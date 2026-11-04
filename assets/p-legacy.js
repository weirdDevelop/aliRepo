/* p-legacy.js — صفحه‌هایی که هنوز به پوسته‌ی جدید منتقل نشده‌اند، داخل پنل جدید جاسازی می‌شوند.
   هر صفحه‌ای که بازنویسی شد، کافی است از این فهرست حذف و با EB.reg ثبت شود. */
(function(){
const {$, ic, reg, UI, P} = EB;
const LEGACY = {
  instagram:['اینستاگرام', 'instagram-logo'], webchat:['گفتگوی آنلاین سایت', 'browser'],
  send:['ارسال پیام', 'paper-plane-tilt'], safir:['ارسال با شماره', 'device-mobile-speaker'], campaigns:['کمپین', 'megaphone'],
  scheduled:['زمان‌بندی', 'calendar-dots'], queue:['صف ارسال', 'stack'], sendlog:['تاریخچه‌ی ارسال', 'clock-counter-clockwise'],
  botbuilder:['بات‌ساز منو', 'flow-arrow'], autoreply:['پاسخ خودکار', 'lightning'], forms:['فرم و نظرسنجی', 'list-checks'], ai:['هوش مصنوعی', 'sparkle'],
  templates:['الگوها', 'note-blank'], links:['لینک رهگیری', 'link-simple'], notices:['اعلان سایت', 'app-window'],
  support:['پشتیبانی تیمی', 'headset'], marketing:['مارکتینگ و تیم', 'target'], stats:['آمار', 'chart-bar'], analytics:['آنالیتیکس', 'chart-line-up'], apilog:['لاگ سیستم', 'terminal-window'],
  channels:['تنظیمات کانال', 'plugs-connected', true], botsettings:['پیام خوش‌آمد ربات', 'robot', true], profile:['پروفایل من', 'user-circle', true],
};
function themeNow() { const t = document.documentElement.getAttribute('data-theme'); return t || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); }
/* قاب‌ها نگه داشته می‌شوند تا جابه‌جایی بین صفحه‌ها فوری باشد؛ حداکثر ۶ قاب، و اگر بیش از ۵ دقیقه دیده نشده باشد تازه می‌شود */
const FRAMES = new Map(); const MAX = 6, STALE = 5 * 60 * 1000;
const srcOf = (page, src) => src || `index.php?page=${encodeURIComponent(page)}&legacy=1`;
function frame(view, page, force, src) {
  const host = $('#vlegacy');
  if (!host) return frameInline(view, page, src);
  let f = FRAMES.get(page);
  if (f && (force || Date.now() - f.seen > STALE)) { try { f.el.contentWindow.location.reload(); } catch (e) { f.el.src = f.el.src; } f.wrap.classList.add('loading'); }
  if (!f) {
    const wrap = document.createElement('div'); wrap.className = 'lg-frame loading';
    wrap.innerHTML = `<div class="legacy-load"><div class="sk" style="height:26px;width:220px;margin-bottom:20px"></div><div class="card"><div class="card-b">${EB.sk(6)}</div></div></div>
      <iframe title="${LEGACY[page] ? LEGACY[page][0] : page}" src="${srcOf(page, src)}"></iframe>`;
    host.appendChild(wrap);
    const el = $('iframe', wrap);
    el.addEventListener('load', () => { wrap.classList.remove('loading'); try { el.contentDocument.documentElement.setAttribute('data-theme', themeNow()); } catch (e) {} });
    f = {wrap, el, seen:Date.now()}; FRAMES.set(page, f);
    if (FRAMES.size > MAX) { const old = [...FRAMES.entries()].filter(([k]) => k !== page).sort((a, b) => a[1].seen - b[1].seen)[0]; if (old) { old[1].wrap.remove(); FRAMES.delete(old[0]); } }
  }
  f.seen = Date.now();
  FRAMES.forEach((x, k) => x.wrap.hidden = k !== page);
}
function frameInline(view, page, src) {
  view.innerHTML = `<div style="position:relative;height:100%"><iframe title="${page}" src="${srcOf(page, src)}" style="width:100%;height:100%;border:0;display:block;background:var(--bg)"></iframe></div>`;
}
Object.entries(LEGACY).forEach(([slug, [title, icon, hidden]]) => {
  if (EB.PAGES[slug]) return;
  reg(slug, {title, icon, full:true, hidden, legacy:true, render(view) { frame(view, slug); }});
});
UI.on('prefs', () => { FRAMES.forEach(f => { try { f.el.contentDocument.documentElement.setAttribute('data-theme', themeNow()); } catch (e) {} }); });
EB.legacyFrame = frameInline;
EB.legacyPage = frame;   // ۲.۰.۰: صفحه‌های CRM هم در همین قاب‌های نگه‌داشته‌شده (crm/index.php)
EB.legacyReload = page => { const f = FRAMES.get(page); if (f) { f.wrap.classList.add('loading'); f.el.src = f.el.src; } };   // قاب درون یک بخش از صفحه (تنظیمات، آنالیتیکس کلاسیک)
})();
