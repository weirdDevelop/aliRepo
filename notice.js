/*!
 * notice.js — اعلان‌های سایت اعلان‌بان (پاپ‌آپ/نوار/اسلاید-این — مورد ۷)
 *
 * نصب در سایت مشتری:
 *   <script src="https://panel.example.com/notice.js" async></script>
 *
 * تصمیم‌های طراحی (هم‌سو با widget.js):
 *   ۱) Shadow DOM: استایل سایت میزبان روی اعلان اثر نمی‌گذارد و برعکس.
 *   ۲) آدرس سرور از src خودِ اسکریپت درمی‌آید — نصب یک‌خطی، بدون آدرس دستی.
 *   ۳) قانون «هر چند وقت یک‌بار» کاملاً سمت مرورگر با localStorage/sessionStorage
 *      چک می‌شود؛ سرور فقط قوانین صفحه/زمان‌بندی را می‌داند، بازدیدکننده را نمی‌شناسد.
 *   ۴) بازدید (impression) فقط وقتی واقعاً روی صفحه رندر شد ثبت می‌شود، نه صرفاً
 *      وقتی از سرور برگشت — تا آمار «دیده شد» واقعی باشد.
 */
(function () {
  'use strict';

  if (window.__ebNoticeLoaded) return;
  window.__ebNoticeLoaded = true;

  var self = document.currentScript ||
    (function () { var s = document.getElementsByTagName('script'); return s[s.length - 1]; })();
  var BASE = (self && self.src) ? self.src.replace(/\/notice\.js.*$/, '') : '';
  var SITE = (self && self.getAttribute && self.getAttribute('data-site')) || '';   // ۲.۱.۱: کلید سایت (چندسایته)
  var SQ = SITE ? '&site=' + encodeURIComponent(SITE) : '';
  var ENDPOINT = BASE + '/notices.php';

  function store(type, k, v) {
    try { (type === 'local' ? localStorage : sessionStorage).setItem(k, v); } catch (e) {}
  }
  function load(type, k) {
    try { return (type === 'local' ? localStorage : sessionStorage).getItem(k); } catch (e) { return null; }
  }

  function seenKey(id) { return 'ebn_seen_' + id; }

  function alreadyShown(n) {
    if (n.frequency === 'always') return false;
    if (n.frequency === 'once_per_session') return !!load('session', seenKey(n.id));
    if (n.frequency === 'once_ever') return !!load('local', seenKey(n.id));
    if (n.frequency === 'once_per_day') {
      var v = load('local', seenKey(n.id));
      if (!v) return false;
      return (Date.now() - parseInt(v, 10)) < 86400000;
    }
    return false;
  }
  function markShown(n) {
    if (n.frequency === 'once_per_session') store('session', seenKey(n.id), '1');
    else if (n.frequency === 'once_ever') store('local', seenKey(n.id), '1');
    else if (n.frequency === 'once_per_day') store('local', seenKey(n.id), String(Date.now()));
  }

  function hit(id, type) {
    try {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', ENDPOINT + '?n=hit' + SQ, true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.send(JSON.stringify({ id: id, type: type }));
    } catch (e) {}
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function render(n) {
    var host = document.createElement('div');
    host.style.all = 'initial';
    document.body.appendChild(host);
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

    var bg = n.bg_color || '#1d2433';
    var fg = n.text_color || '#ffffff';
    var isBar = (n.type === 'banner_top' || n.type === 'banner_bottom');
    var isSlide = (n.type === 'slide_in');

    var posCss = '';
    if (n.type === 'popup') {
      posCss = 'position:fixed;inset:0;display:flex;align-items:center;justify-content:center;z-index:2147483000;background:rgba(10,13,20,.5)';
    } else if (n.type === 'banner_top') {
      posCss = 'position:fixed;top:0;left:0;right:0;z-index:2147483000';
    } else if (n.type === 'banner_bottom') {
      posCss = 'position:fixed;bottom:0;left:0;right:0;z-index:2147483000';
    } else { // slide_in
      posCss = 'position:fixed;bottom:20px;left:20px;max-width:320px;z-index:2147483000';
    }

    var cardCss = isBar
      ? 'padding:12px 18px;display:flex;align-items:center;justify-content:center;gap:14px;flex-wrap:wrap;text-align:center'
      : 'padding:22px;border-radius:14px;max-width:360px;box-shadow:0 20px 50px rgba(0,0,0,.35)';

    var style = document.createElement('style');
    style.textContent =
      '*{box-sizing:border-box;font-family:Vazirmatn,Tahoma,system-ui,sans-serif;direction:rtl}' +
      '.wrap{' + posCss + '}' +
      '.card{background:' + bg + ';color:' + fg + ';' + cardCss + ';position:relative;animation:ebn-in .25s ease}' +
      '@keyframes ebn-in{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}' +
      '.close{position:absolute;top:8px;left:8px;background:rgba(255,255,255,.15);border:0;color:inherit;' +
        'width:26px;height:26px;border-radius:50%;cursor:pointer;font-size:14px;line-height:1}' +
      '.close:hover{background:rgba(255,255,255,.28)}' +
      'img.ebn-img{max-width:100%;border-radius:10px;margin-bottom:10px;display:block}' +
      '.headline{font-weight:700;font-size:16px;margin-bottom:6px}' +
      '.body{font-size:13.5px;line-height:1.8;opacity:.92;white-space:pre-wrap}' +
      '.btn{display:inline-block;margin-top:12px;padding:9px 18px;border-radius:9px;background:#fff;color:#141926;' +
        'text-decoration:none;font-size:13px;font-weight:600;cursor:pointer;border:0}' +
      (isBar ? '.headline,.body{margin:0} .btn{margin-top:0;margin-inline-start:8px}' : '');
    root.appendChild(style);

    var wrap = document.createElement('div');
    wrap.className = 'wrap';
    var card = document.createElement('div');
    card.className = 'card';

    var closeBtn = document.createElement('button');
    closeBtn.className = 'close';
    closeBtn.innerHTML = '✕';
    closeBtn.setAttribute('aria-label', 'بستن');
    closeBtn.onclick = function () { wrap.remove(); };
    card.appendChild(closeBtn);

    if (n.image_url && !isBar) {
      var img = document.createElement('img');
      img.className = 'ebn-img';
      img.src = n.image_url;
      card.appendChild(img);
    }
    if (n.headline) {
      var h = document.createElement('div');
      h.className = 'headline';
      h.textContent = n.headline;
      card.appendChild(h);
    }
    if (n.body) {
      var b = document.createElement('div');
      b.className = 'body';
      b.textContent = n.body;
      card.appendChild(b);
    }
    if (n.button_text && n.button_url) {
      var a = document.createElement('a');
      a.className = 'btn';
      a.href = n.button_url;
      a.target = '_blank';
      a.rel = 'noopener';
      a.textContent = n.button_text;
      a.onclick = function () { hit(n.id, 'click'); };
      card.appendChild(a);
    }

    wrap.appendChild(card);
    root.appendChild(wrap);

    markShown(n);
    hit(n.id, 'impression');
  }

  function boot() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', ENDPOINT + '?n=active&url=' + encodeURIComponent(location.href) + SQ, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      var res = null;
      try { res = JSON.parse(xhr.responseText); } catch (e) { return; }
      if (!res || !res.ok || !res.notices || !res.notices.length) return;

      // فقط اولین اعلانِ نمایش‌نیافته (بر اساس اولویت که سرور مرتب کرده) نشان داده می‌شود؛
      // بمباران بازدیدکننده با چند پاپ‌آپ هم‌زمان تجربه‌ی بدی است.
      var candidate = null;
      for (var i = 0; i < res.notices.length; i++) {
        if (!alreadyShown(res.notices[i])) { candidate = res.notices[i]; break; }
      }
      if (!candidate) return;

      var delay = Math.max(0, parseInt(candidate.delay_seconds, 10) || 0) * 1000;
      setTimeout(function () { render(candidate); }, delay);
    };
    xhr.send();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
