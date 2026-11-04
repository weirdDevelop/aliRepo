/*!
 * eb-pulse.js — ردیاب سبک آنالیتیکس اعلان‌بان (فاز ۱+۲: آنالیتیکس وب + رفتار/هیت‌مپ)
 *
 * نصب در سایت مشتری:
 *   <script src="https://panel.example.com/eb-pulse.js" async></script>
 *
 * چرا این اسم و نه analytics.js/tracker.js: همان درسِ eb-embed.js — افزونه‌های
 * ادبلاک فایل‌هایی با اسم analytics/track/pixel را صرفاً به‌خاطر اسمشان بلاک
 * می‌کنند، مستقل از این‌که واقعاً ردیاب تبلیغاتی باشند یا نه.
 *
 * تصمیم‌های طراحی (طبق analytics-architecture.md):
 *   ۱) batching: رویدادها در حافظه جمع می‌شوند و هر چند ثانیه یک‌جا فرستاده
 *      می‌شوند — نه یک HTTP request به‌ازای هر کلیک/حرکت.
 *   ۲) نمونه‌برداری: حرکت ماوس و حتی کلیک بر اساس درصدهای config (سمت سرور)
 *      فقط برای بخشی از بازدیدها فعال می‌شود.
 *   ۳) بدون فینگرپرینتینگ تهاجمی: فقط یک شناسه‌ی تصادفی در localStorage/کوکی.
 *   ۴) حالت cookieless (اختیاری، از تنظیمات سرور): شناسه‌ی روزانه‌ی چرخشی
 *      به‌جای شناسه‌ی دائمی — برای سایت‌هایی که نمی‌خواهند بنر رضایت نشان دهند.
 *   ۵) پوشاندن خودکار: هرگز مختصات/محتوای input[type=password] یا فیلد با
 *      data-eb-mask ثبت نمی‌شود (غیرقابل‌مذاکره، حتی در این فاز که فقط
 *      مختصات ثبت می‌شود نه محتوا).
 */
(function () {
  'use strict';

  if (window.__ebPulseLoaded) return;
  window.__ebPulseLoaded = true;

  var self = document.currentScript ||
    (function () { var s = document.getElementsByTagName('script'); return s[s.length - 1]; })();
  var BASE = (self && self.src) ? self.src.replace(/\/eb-pulse\.js.*$/, '') : '';
  var SITE = (self && self.getAttribute && self.getAttribute('data-site')) || '';
  var ENDPOINT = BASE + '/eb-pulse.php';
  var SQ = SITE ? '&site=' + encodeURIComponent(SITE) : '';

  var SESSION_TIMEOUT_MS = 30 * 60 * 1000;
  var FLUSH_INTERVAL_MS = 8000;
  var MAX_BATCH_EVENTS = 200;
  var MOVE_THROTTLE_MS = 100;
  var RAGE_CLICK_WINDOW_MS = 1200;
  var RAGE_CLICK_COUNT = 3;
  var RAGE_CLICK_RADIUS_PX = 24;

  function store(type, k, v) {
    try { (type === 'local' ? localStorage : sessionStorage).setItem(k, v); } catch (e) {}
  }
  function load(type, k) {
    try { return (type === 'local' ? localStorage : sessionStorage).getItem(k); } catch (e) { return null; }
  }
  function remove(type, k) {
    try { (type === 'local' ? localStorage : sessionStorage).removeItem(k); } catch (e) {}
  }

  function randomId() {
    if (window.crypto && crypto.getRandomValues) {
      var a = new Uint8Array(16);
      crypto.getRandomValues(a);
      return Array.prototype.map.call(a, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    }
    return 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'.replace(/x/g, function () {
      return (Math.random() * 16 | 0).toString(16);
    });
  }

  var cfg = { enabled: false, sampling_heatmap: 0, sampling_move: 0, cookieless: false, site_key: '' };
  var visitorId = null, sessionId = null, sessionStartedLocal = false;
  var queue = [];
  var currentPage = { url: null, title: null, scrollMax: 0, enteredAt: 0 };
  var moveSampled = false, clickSampled = false;
  var lastMoveAt = 0;
  var recentClicks = []; // برای تشخیص رِیج‌کلیک: [{x,y,t}]

  // ==================== شناسه‌ی بازدیدکننده/نشست ====================

  function getVisitorId() {
    if (cfg.cookieless) {
      // شناسه‌ی روزانه‌ی چرخشی — بازدیدکننده هیچ اثر دائمی نمی‌گذارد
      var day = new Date().toISOString().slice(0, 10);
      return 'cl_' + day + '_' + (self && self.src ? btoa(location.host).slice(0, 8) : 'x');
    }
    var v = load('local', 'eb_vuid');
    if (!v) { v = randomId(); store('local', 'eb_vuid', v); }
    return v;
  }

  function getSessionId() {
    var sid = load('session', 'eb_suid');
    var last = parseInt(load('session', 'eb_suid_at') || '0', 10);
    var fresh = sid && (Date.now() - last) < SESSION_TIMEOUT_MS;
    if (!fresh) {
      sid = randomId();
      sessionStartedLocal = true;
    }
    store('session', 'eb_suid', sid);
    store('session', 'eb_suid_at', String(Date.now()));
    return sid;
  }

  // ==================== UTM/ارجاع‌دهنده (فقط اولین بار در این نشست) ====================

  function firstTouch() {
    var qp = {};
    try {
      var s = new URLSearchParams(location.search);
      ['utm_source', 'utm_medium', 'utm_campaign'].forEach(function (k) { qp[k] = s.get(k) || null; });
      qp.link_code = s.get('eb_lc') || null; // مورد ۷: نشانه‌ی خودکار لینک رهگیری (از r.php)
    } catch (e) {}
    return {
      referrer: document.referrer || null,
      utm_source: qp.utm_source, utm_medium: qp.utm_medium, utm_campaign: qp.utm_campaign,
      link_code: qp.link_code,
    };
  }

  // ==================== ارسال ====================

  function send(payload, useBeacon) {
    try {
      var body = JSON.stringify(payload);
      if (useBeacon && navigator.sendBeacon) {
        var blob = new Blob([body], { type: 'application/json' });
        navigator.sendBeacon(ENDPOINT + '?px=batch' + SQ, blob);
        return;
      }
      var xhr = new XMLHttpRequest();
      xhr.open('POST', ENDPOINT + '?px=batch' + SQ, true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.send(body);
    } catch (e) {}
  }

  function buildPagePayload(extra) {
    var p = {
      url: location.pathname + location.search,
      title: document.title,
      scroll_depth_pct: currentPage.scrollMax,
      time_on_page_sec: currentPage.enteredAt ? Math.round((Date.now() - currentPage.enteredAt) / 1000) : 0,
    };
    if (extra) for (var k in extra) p[k] = extra[k];
    return p;
  }

  function flush(useBeacon) {
    if (!cfg.enabled) { queue = []; return; }
    var pagePart = null;
    if (currentPage.url) {
      pagePart = buildPagePayload(sessionStartedLocal ? firstTouch() : {});
      sessionStartedLocal = false;
    }
    if (!pagePart && queue.length === 0) return;

    var payload = {
      site_key: cfg.site_key,
      visitor_uid: visitorId,
      session_uid: sessionId,
      page: pagePart,
      events: queue.splice(0, MAX_BATCH_EVENTS),
    };
    send(payload, !!useBeacon);
  }

  // ==================== رویدادهای صفحه (اسکرول/زمان) ====================

  function trackScroll() {
    var doc = document.documentElement;
    var scrollable = Math.max(1, (doc.scrollHeight - doc.clientHeight));
    var pct = Math.min(100, Math.round((window.scrollY / scrollable) * 100));
    if (pct > currentPage.scrollMax) currentPage.scrollMax = pct;
  }

  function startPage() {
    currentPage = { url: location.pathname + location.search, title: document.title, scrollMax: 0, enteredAt: Date.now() };
  }

  // ==================== کلیک: نمونه‌برداری + رِیج‌کلیک/کلیک‌مرده ====================

  function isMaskedTarget(el) {
    while (el) {
      if (el.tagName === 'INPUT' && (el.type === 'password')) return true;
      if (el.dataset && el.dataset.ebMask !== undefined) return true;
      el = el.parentElement;
    }
    return false;
  }

  function looksInteractive(el) {
    while (el) {
      var tag = (el.tagName || '').toLowerCase();
      if (['a', 'button', 'input', 'select', 'textarea', 'label'].indexOf(tag) !== -1) return true;
      if (el.onclick || el.getAttribute && el.getAttribute('role') === 'button') return true;
      el = el.parentElement;
    }
    return false;
  }

  function onClick(e) {
    if (!clickSampled || isMaskedTarget(e.target)) return;
    var x = e.clientX, y = e.clientY;
    queue.push({ type: 'click', x: x, y: y, vw: window.innerWidth, vh: window.innerHeight, page_url: location.pathname });

    var now = Date.now();
    recentClicks = recentClicks.filter(function (c) { return now - c.t < RAGE_CLICK_WINDOW_MS; });
    recentClicks.push({ x: x, y: y, t: now });
    var cluster = recentClicks.filter(function (c) {
      return Math.abs(c.x - x) < RAGE_CLICK_RADIUS_PX && Math.abs(c.y - y) < RAGE_CLICK_RADIUS_PX;
    });
    if (cluster.length >= RAGE_CLICK_COUNT) {
      queue.push({ type: 'rage_click', x: x, y: y, vw: window.innerWidth, vh: window.innerHeight, page_url: location.pathname });
      recentClicks = [];
    } else if (!looksInteractive(e.target)) {
      queue.push({ type: 'dead_click', x: x, y: y, vw: window.innerWidth, vh: window.innerHeight, page_url: location.pathname });
    }

    if (queue.length >= MAX_BATCH_EVENTS) flush(false);
  }

  function onMove(e) {
    if (!moveSampled) return;
    var now = Date.now();
    if (now - lastMoveAt < MOVE_THROTTLE_MS) return;
    lastMoveAt = now;
    queue.push({ type: 'move', x: e.clientX, y: e.clientY, vw: window.innerWidth, vh: window.innerHeight, page_url: location.pathname });
    if (queue.length >= MAX_BATCH_EVENTS) flush(false);
  }

  // ==================== بوت‌استرپ ====================

  function boot(serverCfg) {
    cfg = serverCfg;
    if (!cfg.enabled) return;

    visitorId = getVisitorId();
    sessionId = getSessionId();
    startPage();

    // نمونه‌برداری برای کل این نشست ثابت می‌ماند (نه هر بار عوض شود)
    var seed = parseInt(load('session', 'eb_sample_seed') || '', 10);
    if (isNaN(seed)) { seed = Math.random() * 100; store('session', 'eb_sample_seed', String(seed)); }
    clickSampled = seed < Math.max(cfg.sampling_heatmap, 0);
    moveSampled = seed < Math.max(cfg.sampling_move, 0);

    document.addEventListener('click', onClick, true);
    if (moveSampled) document.addEventListener('mousemove', onMove, true);
    window.addEventListener('scroll', trackScroll, { passive: true });

    setInterval(function () { flush(false); }, FLUSH_INTERVAL_MS);

    window.addEventListener('beforeunload', function () { flush(true); });
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') flush(true);
    });

    // پشتیبانی از SPA: تغییر آدرس بدون لود کامل صفحه (history API)
    var origPush = history.pushState;
    history.pushState = function () {
      flush(false);
      var r = origPush.apply(this, arguments);
      startPage();
      return r;
    };
    window.addEventListener('popstate', function () { flush(false); startPage(); });
  }

  // ---- گرفتن تنظیمات از سرور (نمونه‌برداری/فعال‌بودن) ----
  try {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', ENDPOINT + '?px=config' + SQ, true);
    xhr.onload = function () {
      try {
        var res = JSON.parse(xhr.responseText);
        if (res && res.enabled) boot(res);
      } catch (e) {}
    };
    xhr.send();
  } catch (e) {}
})();
