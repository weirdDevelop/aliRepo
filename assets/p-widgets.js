/* p-widgets.js — ابزارهای سایت: دکمه‌های شناور تماس و طراحی گفتگوی آنلاین، با پیش‌نمایش زنده */
(function(){
const {$, $$, E, fa, nf, ic, pill, sw, seg, tabs, empty, field, A, ON, UI, reg, go, toast, menu, modal, confirm, chartBox, hbars, copy, debounce} = EB;
const L = () => window.EB_LIVE;

let W = {tab:'fab', sub:'buttons', fab:null, design:null, base:null, meta:null, dirty:false, device:'desktop', stats:null, previewOpen:null};

reg('widgets', {title:'ابزارهای سایت', icon:'app-window', full:true, perm:'webchat', admin:true,
  async load() {
    const [f, w] = await Promise.all([L().api('fab_get'), L().api('wcx_get')]);
    if (!f.ok) throw new Error(f.error);
    if (!w.ok) throw new Error(w.error);
    W.fab = f.config; W.meta = {types:f.types, icons:f.icons, embed:f.embed, base:f.base_url, online:w.online, brand:w.brand, accent:w.accent, webchat_stats:w.stats};
    W.design = w.design; W.base = w.base; W.dirty = false;
  },
  render(view, args) {
    if (args[0] === 'chat' || args[0] === 'fab' || args[0] === 'stats') W.tab = args[0];
    view.innerHTML = `<div class="wb">
      <div class="wb-ed scroll" id="wbEd"></div>
      <div class="wb-pv"><div class="wb-pvh"><b>پیش‌نمایش زنده</b>${seg('wbDev', [['desktop', 'دسکتاپ', 'desktop'], ['mobile', 'موبایل', 'device-mobile']], W.device, 'wb-dev')}<span class="ms-auto"></span>
        <button class="btn btn-sm" data-act="wb-pv-open">${ic('cursor-click')}${W.tab === 'chat' ? 'باز کردن گفتگو' : 'باز کردن منو'}</button></div>
        <div class="wb-stage"><iframe id="wbFrame" title="پیش‌نمایش ابزارهای سایت" class="${W.device}"></iframe></div></div></div>`;
    editor(); preview(true);
  },
  leave() { if (W.dirty) toast('تغییرات ذخیره‌نشده‌ی ابزارهای سایت کنار گذاشته شد', 'warn'); }
});

/* ---------- کمکی‌های فرم ---------- */
const get = (path) => path.split('.').reduce((o, k) => (o == null ? o : o[k]), W);
function set(path, v) { const ks = path.split('.'); let o = W; ks.slice(0, -1).forEach(k => { if (o[k] == null) o[k] = {}; o = o[k]; }); o[ks[ks.length - 1]] = v; }
const inp = (path, type = 'text', attrs = '') => {
  const v = get(path);
  if (type === 'check') return sw('b_' + path.replace(/\./g, '_'), !!v, '', 'wb-bind').replace('<input ', `<input data-bind="${path}" `);
  if (type === 'color') return `<span class="wb-color"><input type="color" value="${E(v || '#1D5C4D')}" data-bind="${path}" data-on-input="wb-bind" aria-label="رنگ"><input class="inp inp-sm ltr mono" value="${E(v || '')}" data-bind="${path}" data-on-change="wb-bind" placeholder="خودکار" style="width:96px"></span>`;
  if (type === 'range') return `<span class="wb-range"><input type="range" ${attrs} value="${E(v)}" data-bind="${path}" data-on-input="wb-bind"><output>${fa(v)}</output></span>`;
  if (type === 'textarea') return `<textarea class="inp" rows="3" data-bind="${path}" data-on-input="wb-bind" ${attrs}>${E(v || '')}</textarea>`;
  return `<input class="inp ${type === 'ltr' ? 'ltr' : ''}" value="${E(v == null ? '' : v)}" data-bind="${path}" data-on-input="wb-bind" ${attrs}>`;
};
const sel = (path, opts) => `<select class="inp" data-bind="${path}" data-on-change="wb-bind">${opts.map(o => `<option value="${E(o[0])}" ${String(get(path)) === String(o[0]) ? 'selected' : ''}>${o[1]}</option>`).join('')}</select>`;
const segB = (path, opts) => `<div class="seg" role="tablist">${opts.map(o => `<button type="button" class="${String(get(path)) === String(o[0]) ? 'on' : ''}" data-act="wb-seg" data-bind="${path}" data-v="${E(o[0])}">${o[2] ? ic(o[2]) : ''}${o[1]}</button>`).join('')}</div>`;
const sec = (t, d, b) => `<div class="set-sec"><div class="d"><h4>${t}</h4>${d ? `<p>${d}</p>` : ''}</div><div class="stack s12" style="min-width:0">${b}</div></div>`;
const iconPick = (path, list) => `<div class="wb-icons">${list.map(n => `<button type="button" class="${get(path) === n ? 'on' : ''}" data-act="wb-seg" data-bind="${path}" data-v="${n}" title="${n}">${ic(n)}</button>`).join('')}</div>`;

ON['wb-bind'] = (el) => {
  const path = el.dataset.bind; let v;
  if (el.type === 'checkbox') v = el.checked;
  else if (el.type === 'range' || el.type === 'number') { v = +el.value; const o = el.parentElement.querySelector('output'); if (o) o.textContent = fa(v); }
  else v = el.value;
  if (el.type === 'color') { const t = el.parentElement.querySelector('input.inp'); if (t) t.value = v.toUpperCase(); }
  if (el.classList.contains('mono') && el.parentElement.classList.contains('wb-color')) { if (v && !/^#[0-9a-fA-F]{6}$/.test(v)) return; const c = el.parentElement.querySelector('input[type=color]'); if (c && v) c.value = v; }
  set(path, v); dirty();
};
A['wb-seg'] = el => { $$('button', el.parentElement).forEach(b => b.classList.toggle('on', b === el)); const raw = el.dataset.v; set(el.dataset.bind, /^\d+$/.test(raw) ? +raw : raw); dirty(); if (el.dataset.rerender) editor(); };
function dirty() { W.dirty = true; const b = $('#wbSave'); if (b) b.classList.add('pulse-save'); preview(); }

/* ---------- ویرایشگر ---------- */
function editor() {
  const ed = $('#wbEd'); if (!ed) return;
  const top = ed.scrollTop;
  const f = W.fab, b = W.base;
  ed.innerHTML = `<div class="wb-in">
    <div class="ph" style="margin-bottom:8px"><div><h2>ابزارهای سایت</h2><p>دکمه‌های تماس شناور و گفتگوی آنلاین، با یک خط کد روی هر سایتی.</p></div>
      <div class="acts"><button class="btn btn-p" id="wbSave" data-act="wb-save">${ic('check')}ذخیره و انتشار</button></div></div>
    ${tabs([['fab', 'دکمه‌های شناور', 'squares-four'], ['chat', 'گفتگوی آنلاین', 'chat-circle-dots'], ['install', 'نصب روی سایت', 'code'], ['stats', 'آمار کلیک', 'chart-bar']], W.tab, 'wb-tab')}
    <div style="margin-top:14px">${W.tab === 'fab' ? fabEditor() : W.tab === 'chat' ? chatEditor() : W.tab === 'install' ? installTab() : statsTab()}</div></div>`;
  ed.scrollTop = top;
  if (W.tab === 'stats') loadStats();
}
A['wb-tab'] = el => { W.tab = el.dataset.v; history.replaceState(null, '', '#widgets.' + W.tab); editor(); preview(true); const b = $('[data-act=wb-pv-open]'); if (b) b.innerHTML = ic('cursor-click') + (W.tab === 'chat' ? 'باز کردن گفتگو' : 'باز کردن منو'); };

function fabEditor() {
  const f = W.fab, T = W.meta.types;
  return `<div class="callout ${f.enabled ? 'ok' : ''}">${ic(f.enabled ? 'check-circle' : 'info')}<span>${f.enabled ? 'دکمه‌های شناور روی سایت نمایش داده می‌شوند.' : 'دکمه‌های شناور خاموش‌اند. بعد از تنظیم، روشن و ذخیره کنید.'}</span><div class="acts">${inp('fab.enabled', 'check')}</div></div>
  ${sec('چیدمان', 'یک دکمه که با کلیک باز می‌شود، یا همه‌ی دکمه‌ها همیشه دیده شوند، یا نوار ثابت پایین صفحه در موبایل', `<div class="opt-cards">${[['menu', 'منوی بازشونده', 'plus-circle'], ['stack', 'ستونی، همیشه باز', 'rows'], ['bar', 'نوار پایین موبایل', 'square-split-horizontal']].map(o => `<button type="button" class="opt ${f.layout === o[0] ? 'on' : ''}" data-act="wb-seg" data-bind="fab.layout" data-v="${o[0]}" data-rerender="1"><div class="prev" style="place-items:center;background:var(--surface-2)">${ic(o[2])}</div><b>${o[1]}</b></button>`).join('')}</div>${f.layout === 'bar' ? '<span class="small muted">در دسکتاپ، نوار پایین به‌صورت منوی بازشونده نمایش داده می‌شود.</span>' : ''}`)}
  ${sec('دکمه‌ها', 'هر تعداد که بخواهید (تا ۱۲)؛ ترتیب را با فلش‌ها عوض کنید', `<div class="stack s8" id="wbBtns">${f.buttons.map((x, i) => btnRow(x, i)).join('') || '<div class="well small muted">هنوز دکمه‌ای اضافه نکرده‌اید.</div>'}</div>
    <div class="row"><button class="btn" data-act="wb-add-btn" ${f.buttons.length >= 12 ? 'disabled' : ''}>${ic('plus')}افزودن دکمه</button></div>`)}
  ${f.layout === 'menu' ? sec('دکمه‌ی اصلی', 'دکمه‌ای که منو را باز می‌کند', `
    ${field('آیکون', iconPick('fab.main.icon', W.meta.icons))}
    <div class="fg">${field('متن', inp('fab.main.label', 'text', 'maxlength="40"'))}${field('شکل', segB('fab.main.shape', [['circle', 'دایره'], ['rounded', 'گرد'], ['square', 'مربع'], ['pill', 'کپسولی با متن']]))}
    ${field('رنگ', inp('fab.main.color', 'color'))}${field('رنگ آیکون', inp('fab.main.text_color', 'color'))}
    ${field('اندازه', inp('fab.main.size', 'range', 'min="40" max="84"'))}${field('نمایش متن کنار دکمه', segB('fab.main.show_label', [['hover', 'با نگه‌داشتن ماوس'], ['always', 'همیشه'], ['never', 'هرگز']]))}
    ${field('حرکت', sel('fab.main.animation', [['none', 'بدون حرکت'], ['pulse', 'موج'], ['bounce', 'جهش'], ['shake', 'لرزش'], ['glow', 'درخشش'], ['wiggle', 'تپش']]))}${field('تصویر به‌جای آیکون', `<div class="row"><label class="btn btn-sm" style="cursor:pointer">${ic('upload-simple')}آپلود<input type="file" class="sr" accept="image/*" data-on-change="wb-upload" data-bind="fab.main.image"></label>${f.main.image ? `<button class="btn btn-sm btn-g" data-act="wb-clear" data-bind="fab.main.image">حذف تصویر</button>` : ''}</div>`)}</div>
    <label class="row">${inp('fab.main.close_icon', 'check')}<span>وقتی منو باز است، آیکون به ضربدر تبدیل شود</span></label>`) : ''}
  ${sec('ظاهر دکمه‌ها', '', `<div class="fg">${field('اندازه', inp('fab.items.size', 'range', 'min="34" max="72"'))}${field('فاصله', inp('fab.items.gap', 'range', 'min="4" max="24"'))}
    ${field('شکل', segB('fab.items.shape', [['circle', 'دایره'], ['rounded', 'گرد'], ['square', 'مربع']]))}${field('برچسب متنی', segB('fab.items.show_label', [['always', 'همیشه'], ['hover', 'با ماوس'], ['never', 'بدون برچسب']]))}
    ${field('زمینه‌ی برچسب', inp('fab.items.label_bg', 'color'))}${field('رنگ متن برچسب', inp('fab.items.label_text', 'color'))}</div>`)}
  ${sec('جایگاه', 'فاصله از گوشه‌ی صفحه، جدا برای دسکتاپ و موبایل', posFields('fab.position'))}
  ${sec('پیام خوش‌آمد', 'حباب کوچکی کنار دکمه که بعد از چند ثانیه ظاهر می‌شود', `<label class="row">${inp('fab.greeting.enabled', 'check')}<span>نمایش پیام</span></label>
    <div class="fg">${field('متن', inp('fab.greeting.text', 'text', 'maxlength="140"'), '', 'full')}${field('بعد از (ثانیه)', inp('fab.greeting.delay', 'range', 'min="0" max="60"'))}</div>
    <label class="row">${inp('fab.greeting.once', 'check')}<span>در هر بازدید فقط یک بار</span></label>`)}
  ${sec('کجا و کی نمایش داده شود', '', rulesFields('fab.rules'))}
  ${sec('آمار', '', `<label class="row">${inp('fab.tracking', 'check')}<span>ثبت کلیک هر دکمه (در تب «آمار کلیک»)</span></label>`)}`;
}
function btnRow(x, i) {
  const t = W.meta.types[x.type] || ['دکمه', 'link-simple', '#4B5563', ''];
  const needsMsg = ['whatsapp', 'sms', 'email'].includes(x.type);
  return `<div class="wb-btn ${x.enabled ? '' : 'off'}">
    <div class="row" style="gap:8px"><span class="wb-bic" style="background:${E(x.color)}">${ic(x.icon || t[1])}</span><b class="grow">${E(t[0])}</b>
      <button class="btn btn-g btn-ic btn-sm" data-act="wb-mv" data-i="${i}" data-d="-1" ${i === 0 ? 'disabled' : ''} aria-label="بالا">${ic('arrow-up')}</button>
      <button class="btn btn-g btn-ic btn-sm" data-act="wb-mv" data-i="${i}" data-d="1" ${i === W.fab.buttons.length - 1 ? 'disabled' : ''} aria-label="پایین">${ic('arrow-down')}</button>
      ${inp('fab.buttons.' + i + '.enabled', 'check')}
      <button class="btn btn-g btn-ic btn-sm" data-act="wb-rm" data-i="${i}" aria-label="حذف">${ic('trash')}</button></div>
    <div class="fg" style="margin-top:8px">${field('برچسب', inp('fab.buttons.' + i + '.label', 'text', 'maxlength="40"'))}
      ${x.type !== 'webchat' ? field(t[3] ? E(t[3]) : 'مقدار', inp('fab.buttons.' + i + '.value', 'ltr', `placeholder="${E(t[3])}"`)) : `<div class="field"><label>&nbsp;</label><span class="small muted">${W.base.enabled ? 'پنجره‌ی گفتگوی سایت باز می‌شود.' : 'گفتگوی آنلاین خاموش است؛ این دکمه نمایش داده نمی‌شود.'}</span></div>`}
      ${needsMsg ? field(x.type === 'email' ? 'موضوع پیش‌فرض' : 'متن پیش‌فرض پیام', inp('fab.buttons.' + i + '.message', 'text', 'maxlength="300"'), '', 'full') : ''}
      ${field('رنگ', inp('fab.buttons.' + i + '.color', 'color'))}${field('نمایش در', sel('fab.buttons.' + i + '.devices', [['all', 'همه‌ی دستگاه‌ها'], ['mobile', 'فقط موبایل'], ['desktop', 'فقط دسکتاپ']]))}</div>
    <label class="row small" style="margin-top:6px">${inp('fab.buttons.' + i + '.hours_only', 'check')}<span>فقط در ساعت کاری تیم پشتیبانی</span></label></div>`;
}
const posFields = p => `<div class="row wrap" style="gap:12px">${field('سمت', segB(p + '.side', [['right', 'راست'], ['left', 'چپ']]))}</div>
  <div class="fg">${field('فاصله از پایین (دسکتاپ)', inp(p + '.bottom', 'range', 'min="0" max="200"'))}${field('فاصله از کنار (دسکتاپ)', inp(p + '.offset', 'range', 'min="0" max="120"'))}
  ${field('فاصله از پایین (موبایل)', inp(p + '.m_bottom', 'range', 'min="0" max="200"'), 'اگر قالب سایت نوار ثابت پایین دارد، بیشترش کنید')}${field('فاصله از کنار (موبایل)', inp(p + '.m_offset', 'range', 'min="0" max="120"'))}</div>`;
const rulesFields = p => `<div class="fg">${field('دستگاه‌ها', segB(p + '.devices', [['all', 'همه'], ['desktop', 'دسکتاپ'], ['mobile', 'موبایل']]))}${field('تأخیر نمایش (ثانیه)', inp(p + '.delay', 'range', 'min="0" max="60"'))}
  ${field('بعد از اسکرول (درصد صفحه)', inp(p + '.scroll', 'range', 'min="0" max="90"'))}<div class="field"><label>&nbsp;</label><label class="row">${inp(p + '.hours_only', 'check')}<span>فقط در ساعت کاری</span></label></div>
  ${field('فقط در این صفحه‌ها', inp(p + '.include', 'textarea', 'placeholder="/product/*&#10;/contact"'), 'هر خط یک مسیر؛ * یعنی هر چیزی. خالی یعنی همه‌ی صفحه‌ها')}${field('هرگز در این صفحه‌ها', inp(p + '.exclude', 'textarea', 'placeholder="/checkout&#10;/cart"'))}</div>`;

A['wb-add-btn'] = el => menu(el, [{h:'نوع دکمه'}, ...Object.entries(W.meta.types).map(([k, t]) => ({t:t[0], icon:t[1], dot:t[2], fn:() => {
  W.fab.buttons.push({key:'b' + Math.random().toString(36).slice(2, 7), type:k, label:t[0], value:'', message:'', color:t[2], icon:t[1], enabled:true, devices:'all', hours_only:false});
  editor(); dirty(); const l = $$('.wb-btn'); if (l.length) l[l.length - 1].scrollIntoView({behavior:'smooth', block:'center'});
}}))]);
A['wb-mv'] = el => { const i = +el.dataset.i, j = i + +el.dataset.d, b = W.fab.buttons; if (j < 0 || j >= b.length) return; [b[i], b[j]] = [b[j], b[i]]; editor(); dirty(); };
A['wb-rm'] = el => { W.fab.buttons.splice(+el.dataset.i, 1); editor(); dirty(); };
A['wb-clear'] = el => { set(el.dataset.bind, ''); editor(); dirty(); };
ON['wb-upload'] = async el => {
  const f = el.files && el.files[0]; if (!f) return;
  const fd = new FormData(); fd.append('image', f);
  const r = await L().api('wcx_upload', fd, true);
  if (!r.ok) return toast(r.error, 'err');
  set(el.dataset.bind, r.rel); editor(); dirty();
};

function chatEditor() {
  const b = W.base, d = W.design;
  const THEMES = [['کاج', '#1D5C4D', '#F6F5F1'], ['شب', '#1F2A44', '#F3F4F8'], ['زعفران', '#B9741A', '#FBF7F0'], ['انار', '#9C2F45', '#FBF4F5'], ['فیروزه', '#1F7A8C', '#F2F8F9'], ['زغال', '#2B2B2B', '#F5F5F4']];
  return `<div class="callout ${b.enabled ? 'ok' : ''}">${ic(b.enabled ? 'check-circle' : 'info')}<span>${b.enabled ? 'گفتگوی آنلاین روی سایت فعال است. پیام‌ها در صندوق ورودی می‌آیند.' : 'گفتگوی آنلاین خاموش است.'}</span><div class="acts">${inp('base.enabled', 'check')}</div></div>
  ${sec('متن‌ها', '', `<div class="fg">${field('عنوان', inp('base.title', 'text', 'maxlength="80"'))}${field('زیرعنوان', inp('base.subtitle', 'text', 'maxlength="120"'))}
    ${field('پیام خوش‌آمد', inp('base.welcome', 'textarea'), '', 'full')}${field('پیام خارج از ساعت کاری', inp('base.offline_text', 'textarea'), '', 'full')}</div>`)}
  ${sec('شروع گفتگو', 'چه اطلاعاتی قبل یا حین گفتگو از بازدیدکننده گرفته شود', `${field('حالت', segB('base.start_mode', [['open', 'بدون فرم'], ['form', 'فرم کوتاه اول'], ['require_phone', 'شماره‌ی موبایل اجباری']]))}
    <div class="row wrap" style="gap:16px"><label class="row">${inp('base.ask_name', 'check')}<span>پرسیدن نام</span></label><label class="row">${inp('base.ask_phone', 'check')}<span>پرسیدن موبایل</span></label><label class="row">${inp('design.prechat.email', 'check')}<span>پرسیدن ایمیل</span></label><label class="row">${inp('design.prechat.email_required', 'check')}<span>ایمیل اجباری</span></label></div>
    ${field('توضیح زیر فرم', inp('design.prechat.note', 'text', 'maxlength="200" placeholder="مثلاً: اطلاعات شما فقط برای پیگیری استفاده می‌شود"'))}`)}
  ${sec('پاسخ‌های سریع', 'دکمه‌هایی که قبل از اولین پیام نمایش داده می‌شوند', `<div class="row wrap" id="wbQr">${(d.behaviour.quick_replies || []).map((q, i) => `<span class="tag">${E(q)}<button data-act="wb-qr-rm" data-i="${i}" aria-label="حذف">${ic('x')}</button></span>`).join('')}<button class="tag tag-add" data-act="wb-qr-add">${ic('plus')}افزودن</button></div>`)}
  ${sec('دکمه‌ی گفتگو', W.fab.enabled && W.fab.buttons.some(x => x.type === 'webchat' && x.enabled) ? 'دکمه‌های شناور شما دکمه‌ی «گفتگوی آنلاین» دارند؛ پس این دکمه‌ی جدا روی سایت نمایش داده نمی‌شود.' : '', `
    ${field('آیکون', iconPick('design.launcher.icon', W.meta.icons))}
    <div class="fg">${field('شکل', segB('design.launcher.style', [['circle', 'دایره'], ['rounded', 'گرد'], ['square', 'مربع'], ['pill', 'کپسولی با متن']]))}${field('متن دکمه', inp('design.launcher.label', 'text', 'maxlength="40" placeholder="مثلاً گفتگو با کارشناس فروش"'), 'در شکل کپسولی داخل دکمه؛ در بقیه‌ی شکل‌ها کنار دکمه')}${field('نمایش متن کنار دکمه', sel('design.launcher.show_label', [['hover', 'با رفتن ماوس روی دکمه'], ['always', 'همیشه'], ['never', 'هرگز']]))}
    ${field('رنگ', inp('design.launcher.color', 'color'), 'خالی یعنی رنگ برند')}${field('رنگ آیکون', inp('design.launcher.text_color', 'color'))}
    ${field('اندازه', inp('design.launcher.size', 'range', 'min="40" max="84"'))}${field('حرکت', sel('design.launcher.animation', [['none', 'بدون حرکت'], ['pulse', 'موج'], ['bounce', 'جهش'], ['shake', 'لرزش'], ['glow', 'درخشش'], ['wiggle', 'تپش']]))}
    ${field('تصویر به‌جای آیکون', `<div class="row"><label class="btn btn-sm" style="cursor:pointer">${ic('upload-simple')}آپلود<input type="file" class="sr" accept="image/*" data-on-change="wb-upload" data-bind="design.launcher.image"></label>${d.launcher.image ? `<button class="btn btn-sm btn-g" data-act="wb-clear" data-bind="design.launcher.image">حذف تصویر</button>` : ''}</div>`)}<div class="field"><label>&nbsp;</label><label class="row">${inp('design.launcher.badge', 'check')}<span>شمارنده‌ی پیام خوانده‌نشده</span></label></div></div>`)}
  ${sec('رنگ‌ها', 'یک پالت آماده انتخاب کنید یا هر رنگ را جدا تنظیم کنید', `<div class="row wrap" style="gap:8px">${THEMES.map(t => `<button type="button" class="wb-theme" data-act="wb-theme" data-c="${t[1]}" data-bg="${t[2]}"><i style="background:${t[1]}"></i><i style="background:${t[2]}"></i>${t[0]}</button>`).join('')}</div>
    <div class="fg">${field('رنگ اصلی', inp('base.color', 'color'), 'خالی یعنی رنگ برند پنل')}${field('سربرگ', inp('design.colors.header_bg', 'color'))}${field('متن سربرگ', inp('design.colors.header_text', 'color'))}${field('زمینه‌ی گفتگو', inp('design.colors.body_bg', 'color'))}
    ${field('حباب پشتیبان', inp('design.colors.agent_bubble', 'color'))}${field('متن پشتیبان', inp('design.colors.agent_text', 'color'))}${field('حباب بازدیدکننده', inp('design.colors.visitor_bubble', 'color'))}${field('متن بازدیدکننده', inp('design.colors.visitor_text', 'color'))}${field('کادر نوشتن', inp('design.colors.input_bg', 'color'))}</div>`)}
  ${sec('پنجره', '', `<div class="fg">${field('عرض', inp('design.panel.width', 'range', 'min="300" max="480"'))}${field('ارتفاع', inp('design.panel.height', 'range', 'min="400" max="760"'))}
    ${field('گردی گوشه', inp('design.panel.radius', 'range', 'min="0" max="28"'))}${field('سایه', segB('design.panel.shadow', [['soft', 'ملایم'], ['strong', 'پررنگ'], ['none', 'بدون سایه']]))}
    ${field('فونت', inp('design.font.family', 'text', 'placeholder="خالی: وزیرمتن"'), 'نام فونتی که سایت شما بارگذاری کرده')}${field('اندازه‌ی متن', `<span class="wb-range"><input type="range" min="12" max="16" step=".5" value="${E(d.font.size)}" data-bind="design.font.size" data-on-input="wb-bind"><output>${fa(d.font.size)}</output></span>`)}</div>
    <label class="row">${inp('design.panel.mobile_full', 'check')}<span>در موبایل تمام‌صفحه باز شود</span></label>`)}
  ${sec('سربرگ', '', `${field('تصویر', segB('design.header.avatar', [['logo', 'لوگوی برند'], ['team', 'حرف اول همکاران'], ['custom', 'تصویر دلخواه'], ['none', 'بدون تصویر']]))}
    ${d.header.avatar === 'custom' ? field('تصویر دلخواه', `<div class="row"><label class="btn btn-sm" style="cursor:pointer">${ic('upload-simple')}آپلود<input type="file" class="sr" accept="image/*" data-on-change="wb-upload" data-bind="design.header.avatar_url"></label>${d.header.avatar_url ? '<span class="small muted">آپلود شده</span>' : ''}</div>`) : ''}
    <div class="row wrap" style="gap:16px"><label class="row">${inp('design.header.show_status', 'check')}<span>نقطه‌ی آنلاین/آفلاین</span></label><label class="row">${inp('design.header.show_team', 'check')}<span>نمایش متن تیم</span></label></div>
    ${field('متن تیم', inp('design.header.team_text', 'text', 'maxlength="40"'))}`)}
  ${sec('رفتار', '', `<div class="fg">${field('باز شدن خودکار بعد از (ثانیه)', inp('design.behaviour.auto_open', 'range', 'min="0" max="120"'), 'صفر یعنی خاموش؛ برای هر بازدیدکننده یک بار')}
    <div class="field"><label>&nbsp;</label><div class="stack s6"><label class="row">${inp('design.behaviour.sound', 'check')}<span>صدای پیام تازه</span></label><label class="row">${inp('design.behaviour.hide_offline', 'check')}<span>خارج از ساعت کاری پنهان شود</span></label></div></div>
    ${field('دستگاه‌ها', segB('design.behaviour.devices', [['all', 'همه'], ['desktop', 'دسکتاپ'], ['mobile', 'موبایل']]))}<span></span>
    ${field('فقط در این صفحه‌ها', inp('design.behaviour.include', 'textarea', 'placeholder="/product/*"'))}${field('هرگز در این صفحه‌ها', inp('design.behaviour.exclude', 'textarea', 'placeholder="/checkout"'))}</div>`)}
  ${sec('پیام خوش‌آمد کنار دکمه', '', `<label class="row">${inp('design.greeting.enabled', 'check')}<span>نمایش حباب پیام</span></label><div class="fg">${field('متن', inp('design.greeting.text', 'text', 'maxlength="140"'), '', 'full')}${field('بعد از (ثانیه)', inp('design.greeting.delay', 'range', 'min="0" max="120"'))}</div>`)}
  ${sec('پیام‌های هدفمند', 'برای صفحه‌های خاص، بعد از چند ثانیه پیام بفرستید (مثلاً در صفحه‌ی قیمت‌ها)', `<div class="stack s8">${(d.proactive || []).map((p, i) => `<div class="well stack s8"><div class="fg">${field('مسیر صفحه', inp('design.proactive.' + i + '.path', 'ltr', 'placeholder="/pricing"'))}${field('بعد از (ثانیه)', inp('design.proactive.' + i + '.delay', 'range', 'min="0" max="300"'))}${field('پیام', inp('design.proactive.' + i + '.text', 'text', 'maxlength="200"'), '', 'full')}</div><div class="row"><label class="row small">${inp('design.proactive.' + i + '.open', 'check')}<span>پنجره‌ی گفتگو خودکار باز شود</span></label><button class="btn btn-sm btn-g ms-auto" data-act="wb-pro-rm" data-i="${i}">${ic('trash')}حذف</button></div></div>`).join('')}
    <button class="btn btn-sm" style="align-self:flex-start" data-act="wb-pro-add" ${(d.proactive || []).length >= 10 ? 'disabled' : ''}>${ic('plus')}پیام هدفمند</button></div>`)}
  ${sec('جایگاه', '', posFields('design.position'))}
  ${sec('پانویس', '', `<label class="row">${inp('design.footer.branding', 'check')}<span>نمایش متن پانویس</span></label>${field('متن پانویس', inp('design.footer.text', 'text', 'maxlength="60" placeholder="خالی: «گفتگو با» + نام برند"'))}`)}
  ${sec('دامنه‌های مجاز', 'گفتگو فقط روی این دامنه‌ها کار کند (برای جلوگیری از کپی شدن کد روی سایت دیگر)', field('', inp('base.allowed_hosts', 'textarea', 'placeholder="example.com&#10;shop.example.com"'), 'هر خط یک دامنه؛ خالی یعنی همه'))}`;
}
A['wb-theme'] = el => { W.base.color = el.dataset.c; W.design.colors.header_bg = el.dataset.c; W.design.colors.visitor_bubble = el.dataset.c; W.design.colors.body_bg = el.dataset.bg; W.design.launcher.color = el.dataset.c; editor(); dirty(); };
A['wb-qr-add'] = () => { const m = modal({title:'پاسخ سریع', icon:'lightning', body:field('متن دکمه', '<input class="inp" id="wbQrT" maxlength="60" placeholder="مثلاً: پیگیری سفارش">'), foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>افزودن</button>'}); m.el.querySelector('[data-ok]').onclick = () => { const v = $('#wbQrT').value.trim(); if (!v) return; W.design.behaviour.quick_replies = (W.design.behaviour.quick_replies || []).concat(v).slice(0, 8); m.close(); editor(); dirty(); }; };
A['wb-qr-rm'] = el => { W.design.behaviour.quick_replies.splice(+el.dataset.i, 1); editor(); dirty(); };
A['wb-pro-add'] = () => { W.design.proactive = (W.design.proactive || []).concat({path:'', text:'سوالی درباره‌ی این صفحه دارید؟', delay:15, open:false}); editor(); dirty(); };
A['wb-pro-rm'] = el => { W.design.proactive.splice(+el.dataset.i, 1); editor(); dirty(); };

function installTab() {
  return `${sec('کد نصب', 'این یک خط را قبل از &lt;/body&gt; در قالب سایت بگذارید. هر دو ابزار (دکمه‌های شناور و گفتگو) با همین کد نمایش داده می‌شوند و تغییرات بعدی بدون تغییر کد اعمال می‌شود.', `<div class="copyrow"><div class="code ltr">${E(W.meta.embed)}</div><button class="btn btn-ic" data-act="copy" data-text="${E(W.meta.embed)}" aria-label="کپی">${ic('copy')}</button></div>`)}
  ${sec('وردپرس', '', `<ol class="small soft stack s6" style="margin:0;padding-inline-start:18px"><li>افزونه‌ی «Insert Headers and Footers» (یا WPCode) را نصب کنید.</li><li>کد بالا را در بخش «Footer» بگذارید و ذخیره کنید.</li><li>اگر از کش (مثل WP Rocket یا LiteSpeed) استفاده می‌کنید، کش را پاک کنید.</li></ol>`)}
  ${sec('باز کردن گفتگو از دکمه‌های خود سایت', 'مثلاً دکمه‌ی «مشاوره‌ی آنلاین» در منوی سایت', `<div class="copyrow"><div class="code ltr">&lt;a href="#" onclick="window.EBW &amp;&amp; EBW.open(); return false;"&gt;مشاوره‌ی آنلاین&lt;/a&gt;</div><button class="btn btn-ic" data-act="copy" data-text='<a href="#" onclick="window.EBW && EBW.open(); return false;">مشاوره‌ی آنلاین</a>' aria-label="کپی">${ic('copy')}</button></div>`)}`;
}

function statsTab() {
  return `<div id="wbStats">${EB.sk(5)}</div>`;
}
async function loadStats() {
  const r = await L().api('fab_stats', {days:30});
  const box = $('#wbStats'); if (!box) return;
  if (!r.ok) { box.innerHTML = empty('warning-circle', 'آمار بارگذاری نشد', E(r.error)); return; }
  const btn = k => (W.fab.buttons.find(b => b.key === k) || {});
  const labels = [], data = [];
  for (let i = 29; i >= 0; i--) { const d = JD.isoDaysAgo(i); labels.push(JD.toJ(d).slice(5)); data.push(+((r.daily.find(x => x.d === d) || {}).c || 0)); }
  const ws = W.meta.webchat_stats || {};
  box.innerHTML = `<div class="kpis" style="margin-bottom:14px"><div class="kpi"><span class="k-l">${ic('cursor-click')}کلیک دکمه‌ها (۳۰ روز)</span><span class="k-v">${nf(r.total)}</span></div>
    <div class="kpi"><span class="k-l">${ic('chat-circle-dots')}گفتگوهای سایت (۷ روز)</span><span class="k-v">${nf(ws.week || 0)}</span><span class="k-d">${fa(ws.identified || 0)} نفر شماره داده‌اند</span></div>
    <div class="kpi"><span class="k-l">${ic('device-mobile')}سهم موبایل</span><span class="k-v">${r.total ? EB.pct(r.by_button.reduce((a, b) => a + +b.m, 0) / r.total * 100, 0) : '-'}</span></div>
    <div class="kpi"><span class="k-l">${ic('trophy')}پرکلیک‌ترین</span><span class="k-v" style="font-size:17px">${r.by_button[0] ? E(btn(r.by_button[0].k).label || r.by_button[0].kind) : '-'}</span></div></div>
    <div class="card" style="margin-bottom:14px"><div class="card-h"><h3>کلیک روزانه</h3></div><div class="card-b">${data.some(Boolean) ? chartBox({labels, series:[{name:'کلیک', data}], type:'bar'}, 200) : empty('chart-bar', 'هنوز کلیکی ثبت نشده', 'بعد از نصب کد روی سایت، کلیک‌ها اینجا می‌آیند.')}</div></div>
    <div class="grid g2"><div class="card"><div class="card-h"><h3>به تفکیک دکمه</h3></div><div class="card-b">${r.by_button.length ? hbars(r.by_button.map(x => ({l:E(btn(x.k).label || x.kind), v:+x.c, c:btn(x.k).color}))) : '<span class="muted small">داده‌ای نیست</span>'}</div></div>
    <div class="card"><div class="card-h"><h3>صفحه‌هایی که بیشترین کلیک را دارند</h3></div>${r.pages.length ? `<div class="tbl-wrap"><table class="tbl"><tbody>${r.pages.map(p => `<tr><td><span class="url-cell">${E(p.u || '')}</span></td><td class="num">${nf(p.c)}</td></tr>`).join('')}</tbody></table></div>` : '<div class="card-b"><span class="muted small">داده‌ای نیست</span></div>'}</div></div>`;
  EB.drawCharts(box);
}

/* ---------- پیش‌نمایش ---------- */
function href(b) {
  const v = String(b.value || '').trim(), digits = v.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/\D+/g, ''), user = v.replace(/^https?:\/\/[^/]+\//, '').replace(/^[@/]+/, '');
  switch (b.type) {
    case 'whatsapp': return 'https://wa.me/' + (digits[0] === '0' ? '98' + digits.slice(1) : digits);
    case 'telegram': return 'https://t.me/' + user; case 'bale': return 'https://ble.ir/' + user; case 'eitaa': return 'https://eitaa.com/' + user;
    case 'rubika': return 'https://rubika.ir/' + user; case 'instagram': return 'https://ig.me/m/' + user;
    case 'phone': return 'tel:' + v; case 'sms': return 'sms:' + v; case 'email': return 'mailto:' + v; case 'webchat': return '#webchat';
    default: return /^https?:/.test(v) ? v : '';
  }
}
function previewCfg() {
  const f = JSON.parse(JSON.stringify(W.fab)), b = W.base, br = W.meta.brand || {};
  const fab = f.enabled || W.tab === 'fab' ? Object.assign(f, {buttons:f.buttons.filter(x => x.enabled).map(x => Object.assign(x, {href:href(x) || '#'}))}) : null;
  const chat = (b.enabled || W.tab === 'chat') ? {title:b.title, subtitle:b.subtitle, welcome:b.welcome, offlineText:b.offline_text, color:b.color || W.meta.accent, brandName:br.name || 'اعلان‌بان', logo:br.logo || '',
    startMode:b.start_mode, askName:!!b.ask_name, askPhone:!!b.ask_phone, pollSeconds:4, design:JSON.parse(JSON.stringify(W.design)), team:(D.ops || []).slice(0, 3).map(o => ({i:(o.name || '?')[0], c:o.color}))} : null;
  if (W.tab === 'chat' && fab) fab.buttons = fab.buttons.length ? fab.buttons : [];
  return {fab:W.tab === 'chat' && !W.fab.enabled ? null : fab, chat, online:W.meta.online !== false, open:W.previewOpen};
}
const pushPreview = debounce(() => { const fr = $('#wbFrame'); try { fr.contentWindow.EBW.render(previewCfg()); } catch (e) { preview(true); } }, 120);
function preview(full) {
  const fr = $('#wbFrame'); if (!fr) return;
  if (!full && fr.dataset.ready) { pushPreview(); return; }
  const cfg = previewCfg();
  fr.dataset.ready = '';
  fr.srcdoc = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base href="${E(location.href.replace(/[?#].*$/, '').replace(/[^/]*$/, ''))}">
<style>body{margin:0;font-family:Tahoma,sans-serif;background:#F4F2EC;color:#2b2b2b}header{height:58px;background:#fff;border-bottom:1px solid #e6e2d8;display:flex;align-items:center;padding:0 22px;gap:18px;font-size:13px}header b{font-size:15px}
.hero{margin:22px;height:170px;border-radius:14px;background:linear-gradient(135deg,#e4dfd2,#d6d0bf)}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px;margin:0 22px}.grid i{display:block;height:150px;border-radius:12px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.05)}
.l{height:10px;border-radius:5px;background:#e3dfd4;margin:22px 22px 8px;width:40%}.l2{width:70%;margin-top:0}</style></head><body><header><b>${E((W.meta.brand || {}).name || 'سایت شما')}</b><span>محصولات</span><span>درباره ما</span><span>تماس</span></header><div class="hero"></div><div class="l"></div><div class="l l2"></div><div class="grid"><i></i><i></i><i></i><i></i><i></i><i></i></div>
<script>window.EBW_PREVIEW=${JSON.stringify(cfg).replace(/</g, '\\u003c')};<\/script><script src="widget.js?v=${encodeURIComponent((window.EB_BOOT || {}).version || '')}"><\/script></body></html>`;
  fr.onload = () => { fr.dataset.ready = '1'; };
}
A['wb-dev'] = el => { A['seg'](el); W.device = el.dataset.v; const fr = $('#wbFrame'); fr.className = W.device; preview(true); };
A['wb-pv-open'] = () => { W.previewOpen = W.tab === 'chat' ? 'chat' : null; const fr = $('#wbFrame'); try { if (W.tab === 'chat') { fr.contentWindow.EBW.render(previewCfg()); } else { const r = fr.contentDocument.querySelector('[data-elanban-widget]').shadowRoot; const m = r.querySelector('.fab-main'); if (m) m.click(); } } catch (e) {} W.previewOpen = null; };

/* ---------- ذخیره ---------- */
A['wb-save'] = async el => {
  el.classList.add('loading'); el.disabled = true;
  const [f, w] = await Promise.all([L().api('fab_save', {config:W.fab}), L().api('wcx_save', {base:W.base, design:W.design})]);
  el.classList.remove('loading'); el.disabled = false;
  if (!f.ok) return toast(f.error, 'err');
  if (!w.ok) return toast(w.error, 'err');
  W.fab = f.config; W.design = w.design; W.meta.accent = w.accent; W.dirty = false;
  el.classList.remove('pulse-save'); editor(); preview();
  toast('ذخیره شد؛ تغییرات روی سایت حداکثر تا یک دقیقه‌ی دیگر دیده می‌شود');
};
})();
