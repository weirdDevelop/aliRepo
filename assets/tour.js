/* =====================================================================
   tour.js — راهنمای مرحله‌ای شروع به کار (Onboarding Wizard) اعلان‌بان
   ---------------------------------------------------------------------
   چه می‌کند؟
   - اولین بار که کاربر وارد پنل می‌شود، یک راهنمای ۸ گامی باز می‌شود که
     هر بخش اعلان‌بان را با توضیح کامل معرفی می‌کند و دکمه‌ی «رفتن به بخش»
     دارد؛ تا کاربر تازه بداند از کجا شروع کند.
   - کاربر هر لحظه می‌تواند راهنما را ببندد (×، دکمه‌ی «بستن راهنما»، Esc).
     انتخاب او در localStorage ذخیره می‌شود و دوباره خودکار باز نمی‌شود.
   - از دکمه‌ی شناور «راهنما» (پایین صفحه) و دکمه‌ی «راهنمای شروع» داخل
     کارت راه‌اندازی داشبورد، هر وقت بخواهد دوباره باز می‌شود.
   - مستقل از بقیه‌ی کدهای پنل است؛ فقط به EB_BOOT و آدرس‌های #page
     نگاه می‌کند و با همین زبان طراحی (کاج و زعفران) رندر می‌شود.
   ===================================================================== */
(function () {
  'use strict';

  var TOUR_KEY = 'eb.tour.v1';
  var CSRF = (window.EB_BOOT && EB_BOOT.csrf) || '';

  function storeGet(k, d) { try { var v = localStorage.getItem(k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } }
  function storeSet(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }

  /* ---------- محتوای گام‌ها (غنی و با جزئیات) ---------- */
  var STEPS = [
    {
      icon: 'rocket-launch', title: 'به اعلان‌بان خوش آمدید!',
      intro: true,
      body: '<p>این راهنمای کوتاه، در <b>۸ گام</b> نشان می‌دهد هر بخش پنل چه می‌کند و از کجا باید شروع کنید. <b>حدود ۳ دقیقه</b> وقت می‌گیرد و هر وقت بخواهید می‌توانید آن را ببندید و بعداً از دکمه‌ی «راهنما» دوباره باز کنید.</p>'
         + '<ul><li>همه‌ی گفتگوهای مشتری در یک صندوق ورودی جمع می‌شود.</li>'
         + '<li>ربات‌ها و سناریوها به‌صورت خودکار جواب می‌دهند و ارسال می‌کنند.</li>'
         + '<li>گزارش‌ها نشان می‌دهد چه چیزی واقعاً جواب می‌دهد.</li></ul>',
      tip: 'پیشنهاد: اول «کانال‌ها» را وصل کنید؛ بقیه‌ی بخش‌ها بدون کانال خالی می‌مانند.'
    },
    {
      icon: 'squares-four', title: 'داشبورد؛ ست فرمان شما',
      body: '<p>داشبورد نمای کلی سیستم است: آمار مشتریان و پیام‌ها، روند ۷ روز اخیر و کارت «<b>نیاز به توجه</b>» که گفتگوهای عقب‌افتاده و پیام‌های ناموفق را مشخص می‌کند.</p>'
         + '<ul><li><b>چک‌لیست راه‌اندازی</b>: قدم‌های باقی‌مانده را نشان می‌دهد و با انجام هر قدم، تیک می‌خورد.</li>'
         + '<li><b>آمار سریع</b>: مشتریان، نرخ عضویت، نرخ تحویل و پاسخ‌دهی.</li>'
         + '<li><b>آخرین فعالیت‌ها</b>: هر چه در سیستم رخ داده، به‌صورت زنده.</li></ul>',
      tip: 'اگر چک‌لیست را بستید و پشیمان شدید، از دکمه‌ی «راهنمای شروع» در همین کارت برمی‌گردد.',
      cta: null
    },
    {
      icon: 'plug', title: 'کانال‌ها؛ اولین قدم واقعی',
      body: '<p>اعلان‌بان فقط وقتی زنده می‌شود که یک کانال وصل کنید. از صفحه‌ی <b>کانال‌ها</b> می‌توانید این‌ها را به سیستم متصل کنید:</p>'
         + '<ul><li><b>دایرکت اینستاگرام</b> — چند پیج همزمان، با اتوماسیون کامنت و پاسخ دایرکت.</li>'
         + '<li><b>تلگرام، بله و روبیکا</b> — فقط توکن بات کافی است؛ وب‌هوک خودکار ثبت می‌شود.</li>'
         + '<li><b>پیامک</b> — کاوه‌نگار، sms.ir و ملی‌پیامک؛ حتی پاسخ به پیام‌های ورودی.</li>'
         + '<li><b>گفتگوی آنلاین سایت</b> — یک خط کد ویجت، چت زنده روی سایت خودتان.</li></ul>',
      tip: 'برای هر کانال، کارت راهنمای خودش داخل همان صفحه هست؛ کافیست روی «اتصال» بزنید.',
      cta: { label: 'رفتن به کانال‌ها', hash: 'channels' }
    },
    {
      icon: 'tray', title: 'صندوق ورودی تیمی',
      body: '<p>قلب اعلان‌بان همین‌جاست: همه‌ی پیام‌های همه‌ی کانال‌ها در یک جا، با امکاناتی برای کار تیمی:</p>'
         + '<ul><li><b>تخصیص گفتگو</b> — هر چت به یک اپراتور می‌رسد و «مسئول چت» مشخص است.</li>'
         + '<li><b>مهلت پاسخ (SLA)</b> — اگر گفتگویی بیش از ۲۰ دقیقه بی‌جواب بماند، در داشبورد هشدار می‌آید.</li>'
         + '<li><b>اولویت خودکار</b> — کلمات کلیدی مثل «شکایت» یا «مرجوعی» گفتگو را فوری می‌کند.</li>'
         + '<li><b>یادداشت داخلی</b> — همکار می‌توانید زیر گفتگو نکته بنویسید؛ مشتری نمی‌بیند (کلید N).</li>'
         + '<li><b>پاسخ آماده</b> — جواب‌های پرتکرار با چند کلیک (کلید Ctrl Enter برای ارسال).</li></ul>',
      tip: 'از فیلترهای بالای صندوق، گفتگوهای «سپرده به شما» و «بدون اپراتور» را جدا ببینید.',
      cta: { label: 'رفتن به صندوق ورودی', hash: 'inbox' }
    },
    {
      icon: 'users-three', title: 'مشتریان؛ پرونده‌ی هر مخاطب',
      body: '<p>هر کسی که در هر کانالی پیام دهد، یک <b>پرونده</b> پیدا می‌کند: شماره، هویت‌های مختلف (تلگرام، اینستاگرام، پیامک…)، تاریخچه‌ی کامل گفتگوها و یادداشت‌ها.</p>'
         + '<ul><li><b>عضو در برابر ناشناس</b> — اگر شماره داشته باشد «عضو» است و برای ارسال گروهی پیامک قابل استفاده است.</li>'
         + '<li><b>یادداشت و برچسب</b> — سلیقه‌ی مشتری یا توافق‌ها را ثبت کنید تا هر همکاری ببیند.</li>'
         + '<li><b>گروه‌بندی</b> — مشتریان را با فیلتر بسازید و برای کمپین نگه دارید.</li></ul>',
      tip: 'با ورود اولین پیام بعد از اتصال کانال، اولین مشتری خودکار ساخته می‌شود.',
      cta: { label: 'رفتن به مشتریان', hash: 'persons' }
    },
    {
      icon: 'paper-plane-tilt', title: 'ارسال؛ تک، گروهی و زمان‌بندی‌شده',
      body: '<p>از بخش <b>ارسال</b> می‌توانید به مشتریان پیام بدهید؛ تک‌تک، گروهی یا زمان‌بندی‌شده:</p>'
         + '<ul><li><b>الگو بسازید</b> — قالب پیام با متغیرهای شخصی‌سازی مثل <span class="k">{نام}</span>؛ هر مشتری پیام با نام خودش می‌گیرد.</li>'
         + '<li><b>ارسال گروهی</b> — به اعضا، یک گروه یا نتیجه‌ی فیلتر؛ صف ارسال خودکار مدیریت می‌شود.</li>'
         + '<li><b>لینک رهگیری</b> — لینک کوتاه بدهید تا ببینید چه کسی چه زمانی کلیک کرد.</li>'
         + '<li><b>گزارش ارسال</b> — تحویل‌شده، خوانده‌شده و ناموفق‌ها با دلیل، در «گزارش ارسال».</li></ul>',
      tip: 'قبل از ارسال گروهی، یک تست به خودتان بفرستید — در همان صفحه دکمه‌ی تست دارد.',
      cta: { label: 'رفتن به الگوها', hash: 'templates' }
    },
    {
      icon: 'robot', title: 'اتوماسیون؛ پنل که خودش کار می‌کند',
      body: '<p>اینجا جادوی اعلان‌بان است؛ چند ابزار اصلی:</p>'
         + '<ul><li><b>پاسخ خودکار</b> — به کلمات کلیدی جواب آماده بدهید (مثل «قیمت» → لیست قیمت‌ها).</li>'
         + '<li><b>منوی بات</b> — منوی دکمه‌دار تلگرام/بله بسازید؛ مشتری با دکمه مسیرش را انتخاب می‌کند.</li>'
         + '<li><b>سناریوساز (گراف)</b> — فلوی چندمرحله‌ای با شرط و انتظار برای پاسخ؛ بدون کدنویسی.</li>'
         + '<li><b>کمپین چندمرحله‌ای</b> — دنباله‌ی پیام‌های فروش یا یادآوری با فاصله‌ی زمانی.</li>'
         + '<li><b>هوش مصنوعی</b> — پیش‌نویس پاسخ برای اپراتور یا پاسخ کامل به سؤالات متداول.</li></ul>',
      tip: 'شروع ساده: اول «پیام خوش‌آمد» ربات را از «تنظیمات بات» بنویسید، بعد سراغ پاسخ خودکار بروید.',
      cta: { label: 'رفتن به پاسخ خودکار', hash: 'autoreply' }
    },
    {
      icon: 'chart-bar', title: 'گزارش‌ها، سایت و تنظیمات',
      body: '<p>چند بخش دیگری که بعداً به کارتان می‌آید:</p>'
         + '<ul><li><b>آنالیتیکس</b> — رفتار بازدیدکننده‌های سایت با نقشه‌ی حرارتی (هیت‌مپ) و سرچ کنسول.</li>'
         + '<li><b>اعلان سایت و دکمه‌های شناور</b> — پیام به بازدیدکننده‌های سایت با یک خط کد امبد.</li>'
         + '<li><b>بازاریابی</b> — تقویم محتوا، اهداف فروش و گزارش عملکرد کمپین‌ها.</li>'
         + '<li><b>تنظیمات</b> — برند و لوگو، اپراتورها و سطح دسترسی، <b>لایسنس</b>، و «زمان‌بندی خودکار (Cron)» که دستور آماده‌اش را همان‌جا می‌گیرید.</li></ul>',
      tip: 'کران را حتماً تنظیم کنید (هر دقیقه)؛ وگرنه ارسال‌های زمان‌بندی‌شده و کپچراها اجرا نمی‌شوند.',
      cta: { label: 'رفتن به تنظیمات', hash: 'settings' },
      last: true
    }
  ];

  /* ---------- استایل ---------- */
  var css = ''
    + '.ebt-backdrop{position:fixed;inset:0;background:var(--overlay);z-index:1000;display:grid;place-items:center;padding:20px;animation:ebtFade .25s var(--ease)}'
    + '@keyframes ebtFade{from{opacity:0}to{opacity:1}}'
    + '@keyframes ebtPop{from{opacity:0;transform:translateY(14px) scale(.985)}to{opacity:1;transform:none}}'
    + '.ebt{width:min(880px,100%);max-height:min(660px,94dvh);background:var(--surface);border-radius:var(--r-lg);box-shadow:var(--sh-3);display:grid;grid-template-columns:260px minmax(0,1fr);overflow:hidden;animation:ebtPop .3s var(--ease)}'
    + '.ebt-side{background:linear-gradient(165deg,var(--side) 0%,#123B33 70%,#0C231E 100%);color:var(--side-ink);padding:22px 18px;display:flex;flex-direction:column;gap:14px;position:relative;isolation:isolate;overflow:auto}'
    + '.ebt-side::before{content:"";position:absolute;inset:0;background:var(--girih);background-size:72px;opacity:.08;z-index:-1}'
    + '.ebt-brand{display:flex;align-items:center;gap:9px}'
    + '.ebt-brand .lg{width:36px;height:36px;border-radius:11px;background:linear-gradient(145deg,#1D5C4D,#2E7D64);color:#fff;display:grid;place-items:center;flex:none}'
    + '.ebt-brand b{font-family:var(--display);font-size:20px;color:#FAF7EE;line-height:1.2}'
    + '.ebt-list{display:flex;flex-direction:column;gap:1px}'
    + '.ebt-it{display:flex;align-items:center;gap:9px;padding:7px 9px;border-radius:var(--r);font-size:12.3px;line-height:1.6;transition:background .2s}'
    + '.ebt-it .n{width:22px;height:22px;border-radius:7px;display:grid;place-items:center;flex:none;background:rgba(255,255,255,.07);border:1px solid var(--side-line);font-size:10.5px;font-weight:700;counter-increment:ebtst}'
    + '.ebt-list{counter-reset:ebtst}'
    + '.ebt-it .n::before{content:counter(ebtst)}'
    + '.ebt-it.cur{background:rgba(255,255,255,.07)}'
    + '.ebt-it.cur .n{background:var(--gold);border-color:var(--gold);color:#1F1807}'
    + '.ebt-it.cur .t{color:#fff;font-weight:600}'
    + '.ebt-it.done .n{background:rgba(108,199,126,.16);border-color:rgba(108,199,126,.4);color:#8FD9A2}'
    + '.ebt-it.done .n::before{content:"✓"}'
    + '.ebt-it.todo{opacity:.5}'
    + '.ebt-it .t{color:#E9EFEC}'
    + '.ebt-sidef{margin-top:auto;padding-top:12px;border-top:1px solid var(--side-line);font-size:10.8px;opacity:.7;display:flex;justify-content:space-between;align-items:center;gap:8px}'
    + '.ebt-main{display:flex;flex-direction:column;min-height:0}'
    + '.ebt-head{display:flex;align-items:center;gap:10px;padding:16px 22px 0}'
    + '.ebt-stepnum{font-size:11.5px;color:var(--ink-3);background:var(--surface-3);border-radius:99px;padding:3px 11px;font-weight:600;flex:none}'
    + '.ebt-close{margin-inline-start:auto;background:none;border:none;cursor:pointer;color:var(--ink-3);width:34px;height:34px;border-radius:9px;display:grid;place-items:center;flex:none}'
    + '.ebt-close:hover{background:var(--err-soft);color:var(--err)}'
    + '.ebt-close svg{width:18px;height:18px}'
    + '.ebt-body{padding:14px 22px 20px;overflow:auto;min-height:0}'
    + '.ebt-body h2{display:flex;align-items:center;gap:10px;font-family:var(--display);font-size:27px;font-weight:600;color:var(--ink);margin:4px 0 12px;line-height:1.3}'
    + '.ebt-body h2 .icw{width:42px;height:42px;border-radius:12px;background:var(--accent-soft);color:var(--accent-ink);display:grid;place-items:center;flex:none}'
    + '.ebt-body h2 .icw svg{width:22px;height:22px}'
    + '.ebt-body p{margin:0 0 10px;font-size:13.3px;line-height:2.1;color:var(--ink-2)}'
    + '.ebt-body p b{color:var(--ink)}'
    + '.ebt-body ul{margin:0 0 10px;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px}'
    + '.ebt-body li{display:flex;gap:9px;font-size:12.8px;line-height:2;color:var(--ink-2)}'
    + '.ebt-body li::before{content:"";width:6px;height:6px;border-radius:2px;background:var(--gold);flex:none;margin-top:11px;transform:rotate(45deg)}'
    + '.ebt-body li b{color:var(--ink)}'
    + '.ebt-body .k{display:inline-block;background:var(--surface-3);border:1px solid var(--line);border-radius:6px;padding:0 7px;font-size:11px;color:var(--ink);font-weight:600}'
    + '.ebt-tip{display:flex;gap:9px;align-items:flex-start;background:var(--gold-soft);border:1px solid color-mix(in srgb,var(--gold) 30%,transparent);color:var(--note);border-radius:var(--r);padding:10px 13px;font-size:12.3px;line-height:1.95;margin-top:12px}'
    + '.ebt-tip svg{width:16px;height:16px;flex:none;margin-top:4px;color:var(--gold)}'
    + '.ebt-foot{display:flex;align-items:center;gap:12px;padding:13px 22px;border-top:1px solid var(--line-2);background:var(--surface-2)}'
    + '.ebt-dots{display:flex;gap:5px;flex:1;justify-content:center}'
    + '.ebt-dots i{width:7px;height:7px;border-radius:99px;background:var(--line-strong);transition:all .25s}'
    + '.ebt-dots i.on{background:var(--accent);width:20px}'
    + '.ebt-nav{display:flex;gap:8px}'
    + '.ebt-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;height:36px;padding:0 16px;border-radius:var(--r);border:1.5px solid var(--line-strong);background:var(--surface);color:var(--ink);font:inherit;font-size:12.8px;font-weight:600;cursor:pointer;transition:all .15s;white-space:nowrap}'
    + '.ebt-btn:hover{border-color:var(--accent);color:var(--accent-ink)}'
    + '.ebt-btn svg{width:15px;height:15px}'
    + '.ebt-btn.p{background:var(--accent);border-color:var(--accent);color:var(--on-accent)}'
    + '.ebt-btn.p:hover{background:var(--accent-ink);border-color:var(--accent-ink);color:var(--on-accent)}'
    + '.ebt-btn.ghost{border-color:transparent;background:none;color:var(--ink-3)}'
    + '.ebt-btn.ghost:hover{color:var(--err)}'
    + '@media (max-width:720px){.ebt{grid-template-columns:1fr;max-height:94dvh}.ebt-side{display:none}}'
    /* دکمه‌ی شناور راهنما */
    + '.ebt-fab{position:fixed;left:16px;bottom:16px;z-index:900;display:inline-flex;align-items:center;gap:8px;height:40px;padding:0 16px;border-radius:99px;border:1px solid var(--line);background:var(--surface);color:var(--ink-2);font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;box-shadow:var(--sh-2);transition:all .2s var(--ease)}'
    + '.ebt-fab:hover{color:var(--accent-ink);border-color:var(--accent);box-shadow:var(--sh-3);transform:translateY(-2px)}'
    + '.ebt-fab svg{width:17px;height:17px;color:var(--accent)}'
    + '.ebt-fab .dot{position:absolute;top:-3px;right:-3px;width:11px;height:11px;border-radius:99px;background:var(--gold);border:2px solid var(--surface);display:none}'
    + '.ebt-fab .dot.on{display:block;animation:ebtPulse 1.8s infinite}'
    + '@keyframes ebtPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.25)}}'
    + '@media (max-width:720px){.ebt-fab{left:12px;bottom:12px;padding:0 13px;height:38px}}';

  /* ---------- رندر ---------- */
  var idx = 0, root = null;

  function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

  function build() {
    var st = STEPS[idx];
    var lastBtn = st.last
      ? '<button class="ebt-btn p" data-close><svg><use href="#i-check"/></svg> تمام شد، شروع می‌کنم</button>'
      : '<button class="ebt-btn p" data-next>بعدی <svg><use href="#i-arrow-left"/></svg></button>';
    var items = STEPS.map(function (s, i) {
      var cls = i === idx ? 'cur' : (i < idx ? 'done' : 'todo');
      return '<div class="ebt-it ' + cls + '"><span class="n"></span><span class="t">' + esc(s.title) + '</span></div>';
    }).join('');

    root.innerHTML =
      '<div class="ebt" role="dialog" aria-modal="true" aria-label="راهنمای شروع اعلان‌بان">'
      + '<aside class="ebt-side">'
      +   '<div class="ebt-brand"><span class="lg"><svg style="width:20px;height:20px"><use href="#i-broadcast"/></svg></span><b>راهنمای شروع<br><span style="font-size:12px;font-family:var(--font);color:var(--side-ink);font-weight:400">اعلان‌بان</span></b></div>'
      +   '<div class="ebt-list">' + items + '</div>'
      +   '<div class="ebt-sidef"><span>' + fa(idx + 1) + ' از ' + fa(STEPS.length) + '</span><span>هر وقت خواستید ببندید</span></div>'
      + '</aside>'
      + '<section class="ebt-main">'
      +   '<div class="ebt-head">'
      +     '<span class="ebt-stepnum">گام ' + fa(idx + 1) + ' از ' + fa(STEPS.length) + '</span>'
      +     '<button class="ebt-close" type="button" data-close title="بستن راهنما (Esc)"><svg><use href="#i-x"/></svg></button>'
      +   '</div>'
      +   '<div class="ebt-body">'
      +     '<h2><span class="icw"><svg><use href="#i-' + st.icon + '"/></svg></span>' + esc(st.title) + '</h2>'
      +     st.body
      +     (st.cta ? '<div style="margin-top:14px"><button class="ebt-btn" data-cta="' + st.cta.hash + '"><svg><use href="#i-arrow-left"/></svg>' + esc(st.cta.label) + '</button></div>' : '')
      +     (st.tip ? '<div class="ebt-tip"><svg><use href="#i-lightbulb"/></svg><span><b>نکته:</b> ' + st.tip + '</span></div>' : '')
      +   '</div>'
      +   '<div class="ebt-foot">'
      +     '<div class="ebt-nav">'
      +       (idx > 0 ? '<button class="ebt-btn" data-prev><svg><use href="#i-arrow-right"/></svg> قبلی</button>' : '')
      +     '</div>'
      +     '<div class="ebt-dots">' + STEPS.map(function (_, i) { return '<i class="' + (i === idx ? 'on' : (i < idx ? 'done' : '')) + '"></i>'; }).join('') + '</div>'
      +     '<div class="ebt-nav">'
      +       '<button class="ebt-btn ghost" data-close>بستن راهنما</button>'
      +       lastBtn
      +     '</div>'
      +   '</div>'
      + '</section>'
      + '</div>';

    root.querySelector('[data-close]').addEventListener('click', close);
    Array.prototype.forEach.call(root.querySelectorAll('[data-close]'), function (b) { b.addEventListener('click', close); });
    var nx = root.querySelector('[data-next]'); if (nx) nx.addEventListener('click', function () { idx = Math.min(idx + 1, STEPS.length - 1); build(); });
    var pv = root.querySelector('[data-prev]'); if (pv) pv.addEventListener('click', function () { idx = Math.max(idx - 1, 0); build(); });
    Array.prototype.forEach.call(root.querySelectorAll('[data-cta]'), function (b) {
      b.addEventListener('click', function () {
        close();   // انتخاب کاربر ذخیره می‌شود
        location.hash = b.getAttribute('data-cta');
      });
    });
  }

  function fa(n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }

  function open(auto) {
    if (root) return;
    root = document.createElement('div');
    root.className = 'ebt-backdrop';
    var style = document.createElement('style'); style.textContent = css; document.head.appendChild(style);
    document.body.appendChild(root);
    build();
    document.addEventListener('keydown', onKey);
    if (auto) {
      var fab = document.querySelector('.ebt-fab .dot'); if (fab) fab.classList.add('on');
    }
  }

  function close() {
    storeSet(TOUR_KEY, { done: true, at: Date.now() });
    if (root) { root.remove(); root = null; }
    document.removeEventListener('keydown', onKey);
    var fab = document.querySelector('.ebt-fab .dot'); if (fab) fab.classList.remove('on');
  }

  function onKey(e) {
    if (e.key === 'Escape') { e.stopPropagation(); close(); }
    else if (e.key === 'ArrowLeft' && root && root.querySelector('[data-next]')) { root.querySelector('[data-next]').click(); }
    else if (e.key === 'ArrowRight' && root && root.querySelector('[data-prev]')) { root.querySelector('[data-prev]').click(); }
  }

  /* ---------- دکمه‌ی شناور ---------- */
  function ensureFab() {
    if (document.querySelector('.ebt-fab')) return;
    var b = document.createElement('button');
    b.className = 'ebt-fab';
    b.type = 'button';
    b.title = 'راهنمای شروع اعلان‌بان';
    b.innerHTML = '<svg><use href="#i-question"/></svg> راهنما<span class="dot"></span>';
    b.addEventListener('click', function () { idx = 0; open(false); });
    document.body.appendChild(b);
  }

  /* ---------- دکمه‌ی داخل کارت راه‌اندازی داشبورد ----------
     پنل تک‌صفحه‌ای است و کارت راه‌اندازی با هر مسیردهی دوباره رندر می‌شود؛
     پس با MutationObserver دائم مراقب هستیم و هر بار دکمه را تازه می‌کنیم. */
  function injectDashboardLaunch() {
    var acts = document.querySelector('#setupCard .card-h .acts');
    if (acts && !acts.querySelector('.ebt-launch')) {
      var b = document.createElement('button');
      b.className = 'btn btn-sm ebt-launch';
      b.type = 'button';
      b.innerHTML = '<svg><use href="#i-question"/></svg>راهنمای شروع';
      b.addEventListener('click', function () { idx = 0; open(false); });
      acts.insertBefore(b, acts.firstChild);
    }
  }
  var mo = new MutationObserver(function () { injectDashboardLaunch(); });
  function startMo() { mo.observe(document.body, { childList: true, subtree: true }); }
  if (document.body) { startMo(); injectDashboardLaunch(); }
  else document.addEventListener('DOMContentLoaded', function () { startMo(); injectDashboardLaunch(); });

  /* ---------- شروع ---------- */
  function boot() {
    ensureFab();
    var seen = storeGet(TOUR_KEY, null);
    // فقط بار اول: خودکار باز شود؛ اگر کاربر بسته باشد دیگر مزاحم نیست
    if (!seen || !seen.done) {
      setTimeout(function () { open(true); }, 700);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
