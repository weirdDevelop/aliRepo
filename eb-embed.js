/*!
 * eb-embed.js  (قبلاً notice.js) — اعلان‌های سایت اعلان‌بان (پاپ‌آپ/نوار/اسلاید-این — مورد ۷)
 *
 * نصب در سایت مشتری:
 *   <script src="https://panel.example.com/eb-embed.js" async></script>
 *
 * چرا اسم عوض شد: خیلی از افزونه‌های ادبلاک فایل‌هایی به اسم notice/popup/banner/ads
 * را صرفاً به‌خاطر نامشان بلاک می‌کنند — یکی از علت‌های شایعِ «پاپ‌آپ کار نمی‌کند».
 *
 * برای تست بدون درگیرشدن با قانون «هر چند وقت یک‌بار» (که در حالت عادی جلوی
 * نمایش دوباره را می‌گیرد)، آدرس صفحه را با ?eb_preview=1 باز کن — قوانین تکرار
 * نادیده گرفته می‌شوند و خطاهای شبکه/سرور در کنسول مرورگر (F12) چاپ می‌شوند.
 *
 * تصمیم‌های طراحی (هم‌سو با widget.js):
 *   ۱) Shadow DOM: استایل سایت میزبان روی اعلان اثر نمی‌گذارد و برعکس — به‌جز
 *      فونت، که عمداً از سایت میزبان گرفته می‌شود (مورد ۳ درخواست: «از فونت
 *      سایت استفاده شود») مگر ادمین در ادیتور فونت سفارشی گذاشته باشد.
 *   ۲) آدرس سرور از src خودِ اسکریپت درمی‌آید — نصب یک‌خطی، بدون آدرس دستی.
 *   ۳) قانون «هر چند وقت یک‌بار» کاملاً سمت مرورگر با localStorage/sessionStorage
 *      چک می‌شود؛ سرور فقط قوانین صفحه/زمان‌بندی را می‌داند، بازدیدکننده را نمی‌شناسد.
 *   ۴) بازدید (impression) فقط وقتی واقعاً روی صفحه رندر شد ثبت می‌شود.
 *   ۵) دریافت شماره + کد تخفیف: بعد از ارسال موفق شماره، همان کارت به‌جای فرم،
 *      کد تخفیف را نشان می‌دهد — بدون بستن/باز کردن دوباره‌ی پاپ‌آپ.
 */
(function () {
  'use strict';

  if (window.__ebNoticeLoaded) return;
  window.__ebNoticeLoaded = true;

  var PREVIEW = /[?&]eb_preview=1\b/.test(location.search);

  var self = document.currentScript ||
    (function () { var s = document.getElementsByTagName('script'); return s[s.length - 1]; })();
  var BASE = (self && self.src) ? self.src.replace(/\/eb-embed\.js.*$/, '') : '';
  var SITE = (self && self.getAttribute && self.getAttribute('data-site')) || '';   // ۲.۱.۱: کلید سایت (چندسایته)
  var SQ = SITE ? '&site=' + encodeURIComponent(SITE) : '';
  var ENDPOINT = BASE + '/eb-feed.php';

  function store(type, k, v) {
    try { (type === 'local' ? localStorage : sessionStorage).setItem(k, v); } catch (e) {}
  }
  function load(type, k) {
    try { return (type === 'local' ? localStorage : sessionStorage).getItem(k); } catch (e) { return null; }
  }

  function seenKey(id) { return 'ebn_seen_' + id; }

  function alreadyShown(n) {
    if (PREVIEW) return false;
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

  /** فونت خودِ سایت میزبان را می‌خواند — مورد ۳: «از فونت سایت استفاده شود». */
  function detectSiteFont() {
    try {
      var f = getComputedStyle(document.body).fontFamily;
      if (f && f.trim()) return f;
    } catch (e) {}
    return 'Tahoma, system-ui, sans-serif';
  }

  function submitLead(noticeId, phone, cb) {
    try {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', ENDPOINT + '?n=lead_submit' + SQ, true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.onload = function () {
        var res = null;
        try { res = JSON.parse(xhr.responseText); } catch (e) {}
        cb(res || { ok: false, error: 'پاسخ نامعتبر' });
      };
      xhr.onerror = function () { cb({ ok: false, error: 'خطای شبکه' }); };
      xhr.send(JSON.stringify({ notice_id: noticeId, phone: phone, page_url: location.href }));
    } catch (e) { cb({ ok: false, error: 'خطا' }); }
  }

  function render(n) {
    var host = document.createElement('div');
    host.style.all = 'initial';
    document.body.appendChild(host);
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

    var bg = n.bg_color || '#ffffff';
    var fg = n.text_color || '#1b2030';
    var accent = n.accent_color || '#5b6cff';
    var radius = (n.corner_radius != null ? n.corner_radius : 16);
    var font = (n.font_family && n.font_family.trim()) ? n.font_family : detectSiteFont();
    var isBar = (n.type === 'banner_top' || n.type === 'banner_bottom');
    var isSlide = (n.type === 'slide_in');

    var posCss = '';
    if (n.type === 'popup') {
      posCss = 'position:fixed;inset:0;display:flex;align-items:center;justify-content:center;z-index:2147483000;' +
               'background:rgba(15,17,26,.55);backdrop-filter:blur(2px)';
    } else if (n.type === 'banner_top') {
      posCss = 'position:fixed;top:0;left:0;right:0;z-index:2147483000';
    } else if (n.type === 'banner_bottom') {
      posCss = 'position:fixed;bottom:0;left:0;right:0;z-index:2147483000';
    } else { // slide_in
      posCss = 'position:fixed;bottom:20px;left:20px;max-width:340px;z-index:2147483000';
    }

    var cardCss = isBar
      ? 'padding:13px 20px;display:flex;align-items:center;justify-content:center;gap:16px;flex-wrap:wrap;text-align:center'
      : 'padding:26px;max-width:380px;width:calc(100vw - 32px)';

    var style = document.createElement('style');
    style.textContent =
      '*{box-sizing:border-box;font-family:' + font + ';direction:rtl}' +
      '.wrap{' + posCss + '}' +
      '.card{background:' + bg + ';color:' + fg + ';' + cardCss + ';position:relative;' +
        'border-radius:' + (isBar ? 0 : radius) + 'px;' +
        'box-shadow:0 24px 60px -12px rgba(10,12,20,.35), 0 4px 16px rgba(10,12,20,.12);' +
        'animation:ebn-in .32s cubic-bezier(.2,.8,.2,1)}' +
      '@keyframes ebn-in{from{opacity:0;transform:translateY(10px) scale(.97)}to{opacity:1;transform:none}}' +
      '.close{position:absolute;top:10px;left:10px;background:rgba(120,120,140,.12);border:0;color:inherit;' +
        'width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:14px;line-height:1;' +
        'display:flex;align-items:center;justify-content:center;transition:background .15s}' +
      '.close:hover{background:rgba(120,120,140,.22)}' +
      'img.ebn-img{max-width:100%;width:100%;height:auto;max-height:220px;object-fit:cover;' +
        'border-radius:' + Math.max(0, radius - 6) + 'px;margin-bottom:14px;display:block}' +
      '.headline{font-weight:800;font-size:18px;line-height:1.5;margin-bottom:7px;letter-spacing:-.01em}' +
      '.body{font-size:13.5px;line-height:1.85;opacity:.82;white-space:pre-wrap;margin-bottom:4px}' +
      '.btn{display:inline-flex;align-items:center;justify-content:center;margin-top:14px;padding:11px 20px;' +
        'border-radius:' + Math.max(6, radius - 6) + 'px;background:' + accent + ';color:#fff;' +
        'text-decoration:none;font-size:13.5px;font-weight:700;cursor:pointer;border:0;width:100%;' +
        'transition:filter .15s,transform .1s}' +
      '.btn:hover{filter:brightness(1.08)}.btn:active{transform:scale(.98)}' +
      '.eb-form{margin-top:16px;display:flex;flex-direction:column;gap:9px}' +
      '.eb-input{width:100%;padding:11px 14px;border-radius:' + Math.max(6, radius - 6) + 'px;' +
        'border:1.5px solid rgba(120,120,140,.28);font-size:14px;background:rgba(120,120,140,.05);' +
        'color:inherit;direction:ltr;text-align:center;letter-spacing:.02em;outline:none;transition:border-color .15s}' +
      '.eb-input:focus{border-color:' + accent + '}' +
      '.eb-err{color:#e5484d;font-size:11.5px;text-align:center;min-height:14px}' +
      '.eb-code-box{margin-top:14px;padding:16px;border-radius:' + Math.max(6, radius - 6) + 'px;' +
        'background:rgba(120,120,140,.07);border:1.5px dashed ' + accent + ';text-align:center}' +
      '.eb-code{font-size:22px;font-weight:800;letter-spacing:.08em;direction:ltr;color:' + accent + ';margin-bottom:6px}' +
      '.eb-copy{font-size:11px;background:none;border:0;color:inherit;opacity:.65;cursor:pointer;text-decoration:underline;padding:0}' +
      '.eb-note{font-size:12px;opacity:.7;margin-top:8px;line-height:1.7}' +
      (isBar ? '.headline,.body{margin:0} .btn{margin-top:0;margin-inline-start:8px;width:auto}' : '');
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

    // ---- دریافت شماره + کد تخفیف (مورد ۲) ----
    if (n.capture_phone) {
      var formBox = document.createElement('div');
      formBox.className = 'eb-form';
      var input = document.createElement('input');
      input.className = 'eb-input';
      input.type = 'tel';
      input.placeholder = n.phone_label || 'شماره موبایلت رو بگو';
      input.setAttribute('inputmode', 'numeric');
      var errBox = document.createElement('div');
      errBox.className = 'eb-err';
      var submitBtn = document.createElement('button');
      submitBtn.className = 'btn';
      submitBtn.textContent = n.phone_button_text || 'ارسال';
      submitBtn.type = 'button';

      submitBtn.onclick = function () {
        var phone = input.value.trim();
        if (!phone) { errBox.textContent = 'شماره رو وارد کن'; return; }
        errBox.textContent = '';
        submitBtn.disabled = true;
        var prevText = submitBtn.textContent;
        submitBtn.textContent = 'در حال ارسال…';
        submitLead(n.id, phone, function (res) {
          submitBtn.disabled = false;
          submitBtn.textContent = prevText;
          if (!res || !res.ok) {
            errBox.textContent = (res && res.error) || 'شماره معتبر نیست';
            return;
          }
          formBox.remove();
          errBox.remove();
          var successHeadline = card.querySelector('.headline');
          if (successHeadline) successHeadline.textContent = res.headline || successHeadline.textContent;
          if (res.code) {
            var box = document.createElement('div');
            box.className = 'eb-code-box';
            var codeEl = document.createElement('div');
            codeEl.className = 'eb-code';
            codeEl.textContent = res.code;
            box.appendChild(codeEl);
            var copyBtn = document.createElement('button');
            copyBtn.className = 'eb-copy';
            copyBtn.type = 'button';
            copyBtn.textContent = 'کپی کد';
            copyBtn.onclick = function () {
              try { navigator.clipboard.writeText(res.code); copyBtn.textContent = 'کپی شد ✓'; } catch (e) {}
            };
            box.appendChild(copyBtn);
            card.appendChild(box);
          }
          if (res.note) {
            var noteEl = document.createElement('div');
            noteEl.className = 'eb-note';
            noteEl.textContent = res.note;
            card.appendChild(noteEl);
          }
          hit(n.id, 'click'); // ثبت‌نام موفق را هم به‌عنوان تعامل مثبت می‌شماریم
        });
      };

      formBox.appendChild(input);
      formBox.appendChild(submitBtn);
      formBox.appendChild(errBox);
      card.appendChild(formBox);
    } else if (n.button_text && n.button_url) {
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
      if (xhr.status === 0) {
        if (PREVIEW) console.warn('[اعلان‌بان] درخواست به ' + ENDPOINT + ' نرسید — احتمالاً ادبلاک، CORS، یا آدرس اشتباه.');
        return;
      }
      var res = null;
      try { res = JSON.parse(xhr.responseText); } catch (e) {
        if (PREVIEW) console.warn('[اعلان‌بان] پاسخ سرور JSON معتبر نبود:', xhr.responseText);
        return;
      }
      if (!res || !res.ok) {
        if (PREVIEW) console.warn('[اعلان‌بان] سرور خطا داد:', res && res.error);
        return;
      }
      if (!res.notices || !res.notices.length) {
        if (PREVIEW) console.info('[اعلان‌بان] هیچ اعلان فعالی برای این صفحه برنگشت (بررسی کن: فعال است؟ محدودیت آدرس صفحه؟ بازه‌ی زمانی؟).');
        return;
      }

      var candidate = null;
      for (var i = 0; i < res.notices.length; i++) {
        if (!alreadyShown(res.notices[i])) { candidate = res.notices[i]; break; }
      }
      if (!candidate) {
        if (PREVIEW) console.info('[اعلان‌بان] اعلان(ها) پیدا شد ولی قبلاً طبق قانون تکرارشان نمایش داده شده — برای دیدن دوباره از ?eb_preview=1 استفاده کن.');
        return;
      }

      var delay = Math.max(0, parseInt(candidate.delay_seconds, 10) || 0) * 1000;
      setTimeout(function () { render(candidate); }, PREVIEW ? 0 : delay);
    };
    xhr.onerror = function () {
      if (PREVIEW) console.warn('[اعلان‌بان] خطای شبکه در اتصال به ' + ENDPOINT);
    };
    xhr.send();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
