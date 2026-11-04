/* p-crm.js — ۲.۰.۰: سیستم مدیریت یکپارچه‌ی جاکجاست
   صفحه‌های CRM (پوشه‌ی crm/) داخل پنل، سوابق CRM هر مشتری در صندوق ورودی و بانک مشتری،
   کارت‌های CRM در داشبورد، و بخش «CRM» در تنظیمات (انتقال داده، اتصال کاربران). */
(function(){
const {$, $$, E, fa, nf, ic, pill, empty, field, A, reg, go, toast, drawer} = EB;
const L = () => window.EB_LIVE;
const BOOT = window.EB_BOOT || {};
const CRM = BOOT.crm || {};   // {present, ready}

const src = (page, q) => `crm/index.php?page=${encodeURIComponent(page)}${q ? '&' + q : ''}&embed=1`;
/* [اسلاگ، عنوان، آیکن، صفحه‌ی CRM، کلید دسترسی] */
const PAGES = [
  ['crm_dash', 'داشبورد اجرایی', 'pulse', 'dashboard', 'crm_reports'],
  ['crm_calls', 'تماس‌ها', 'phone-incoming', 'calls', 'crm_calls'],
  ['crm_pipeline', 'پیگیری شماره‌ها', 'list-bullets', 'pipeline', 'crm_calls'],
  ['crm_leads', 'سرنخ‌های فروش', 'funnel', 'leads', 'crm_leads'],
  ['crm_inquiries', 'استعلام‌های سایت', 'envelope-simple', 'site_inquiries', 'crm_leads'],
  ['crm_customers', 'مشتریان CRM', 'users', 'customers', 'crm_customers'],
  ['crm_sales', 'فروش سایت', 'trend-up', 'site_sales', 'crm_reports'],
  ['crm_tickets', 'تیکت‌ها', 'flag', 'tickets', 'crm_tickets'],
  ['crm_hosts', 'میزبانان', 'city', 'hosts', 'crm_hosts'],
  ['crm_host_leads', 'جذب میزبان', 'magnifying-glass-plus', 'host_leads', 'crm_host_leads'],
  ['crm_tasks', 'تسک‌ها و پروژه‌ها', 'check-square', 'tasks', 'crm_tasks'],
  ['crm_chat', 'چت داخلی', 'chats-circle', 'chat', 'crm_chat'],
  ['crm_staff', 'پرسنل', 'user-check', 'staff', 'crm_staff'],
  ['crm_leaves', 'مرخصی‌ها', 'calendar-dots', 'leaves', 'crm_leaves'],
  ['crm_payroll', 'حقوق و دستمزد', 'percent', 'payroll', 'crm_payroll'],
  ['crm_reports', 'گزارش‌های CRM', 'ranking', 'reports', 'crm_reports'],
  ['crm_me', 'پرونده‌ی پرسنلی من', 'user-circle-dashed', 'my_profile', 'crm_me'],
];
EB.CRM_PAGES = PAGES;
if (CRM.present && CRM.ready) PAGES.forEach(([slug, title, icon, page, perm]) => {
  reg(slug, {title, icon, full:true, legacy:true, perm, render(view) { EB.legacyPage(view, slug, false, src(page)); }});
});

/* باز کردن یک صفحه‌ی CRM (پروفایل مشتری، شماره، میزبان) در کشوی کناری */
EB.crmOpen = (page, q, title) => {
  drawer({title:title || 'CRM', size:'xl', body:`<iframe src="${src(page, q)}" title="${E(title || page)}" style="width:100%;height:calc(100vh - 66px);border:0;display:block;background:var(--bg)"></iframe>`});
};
A['crm-open'] = el => EB.crmOpen(el.dataset.page, el.dataset.q || '', el.dataset.title || '');

/* ---------- سوابق CRM یک شماره (صندوق ورودی و بانک مشتری) ---------- */
const CACHE = new Map();
const TYPE = {inbound:['ورودی', 'phone-incoming'], outbound:['خروجی', 'phone-call'], missed:['بی‌پاسخ', 'phone'], internal:['داخلی', 'phone']};
const dur = s => { s = +s || 0; return s < 60 ? fa(s) + ' ثانیه' : fa(Math.floor(s / 60)) + ':' + fa(String(s % 60).padStart(2, '0')); };
const dt = x => x ? (L().faDate ? L().faDate(x, {day:'numeric', month:'short', hour:'2-digit', minute:'2-digit'}) : x) : '';
function boxHtml(r, pid) {
  const all = pid && D.pageAllow.includes('persons') ? `<button class="btn btn-sm" data-act="person-open" data-id="c${pid}" data-tab="tl">${ic('clock-counter-clockwise')}همه‌ی سوابق</button>` : '';
  if (!r) return '<div class="muted small">CRM هنوز راه‌اندازی نشده است.</div>';
  if (!r.found) return '<div class="muted small">سابقه‌ای با این شماره در CRM نیست (مشتری، سرنخ، تماس یا میزبان).</div>' + (all ? `<div class="row wrap" style="margin-top:8px">${all}</div>` : '');
  const can = r.can || {}; const l10 = String(r.phone || '').slice(-10);
  const btn = (page, q, t, label, icon) => `<button class="btn btn-sm" data-act="crm-open" data-page="${page}" data-q="${E(q)}" data-title="${E(t)}">${ic(icon)}${label}</button>`;
  let h = '';
  if (r.customer) { const c = r.customer;
    h += `<div class="ident"><div class="grow" style="min-width:0"><div class="b ell">${E(c.name)} ${pill(E(c.level || 'عادی'), 'gold')}</div><div class="xs muted">${fa(+c.reservations || 0)} رزرو · ${nf(+c.total_spend || 0)} تومان${c.city ? ' · ' + E(c.city) : ''}${c.last_purchase ? ' · آخرین خرید ' + E(c.last_purchase) : ''}</div></div></div>`; }
  if (r.host) h += `<div class="ident"><div class="grow"><div class="b">${pill('میزبان', 'info', 'city')} ${E(r.host.name)}</div><div class="xs muted">${fa(+r.host.properties || 0)} اقامتگاه${r.host.city ? ' · ' + E(r.host.city) : ''} · ${E(r.host.status || '')}</div></div>${can.hosts ? btn('host_profile', 'id=' + r.host.id, 'پروفایل میزبان', '', 'arrow-square-out') : ''}</div>`;
  (r.leads || []).forEach(x => { h += `<div class="ident"><div class="grow" style="min-width:0"><div class="small b ell">${ic('funnel')} ${E(x.name)}</div><div class="xs muted">${E(x.status || '-')}${x.assignee ? ' · ' + E(x.assignee) : ''}${x.next_contact ? ' · پیگیری ' + E(x.next_contact) : ''}</div></div></div>`; });
  (r.host_leads || []).forEach(x => { h += `<div class="ident"><div class="grow"><div class="small b">${ic('magnifying-glass-plus')} جذب میزبان: ${E(x.name)}</div><div class="xs muted">${E(x.status || '-')}${x.assignee ? ' · ' + E(x.assignee) : ''}</div></div></div>`; });
  const cs = r.calls || {};
  if (cs.total) {
    h += `<div class="lab" style="margin-top:8px">تماس‌ها</div><div class="xs muted">${fa(cs.total)} تماس · ${fa(cs.answered)} پاسخ‌داده${cs.last_at ? ' · آخرین ' + dt(cs.last_at) : ''}</div>`;
    h += (cs.recent || []).map(c => { const t = TYPE[c.type] || ['تماس', 'phone']; return `<div class="row xs" style="gap:6px;padding:3px 0">${ic(t[1])}<span>${t[0]}</span><span class="${c.status === 'answered' ? '' : 'muted'}">${c.status === 'answered' ? dur(c.time_talk) : 'بی‌پاسخ'}</span><span class="muted ms-auto">${dt(c.started_at)}</span></div>`; }).join('');
  }
  const tk = r.tickets || {};
  if ((tk.recent || []).length) h += `<div class="lab" style="margin-top:8px">تیکت‌ها${tk.open ? ` ${pill(fa(tk.open) + ' باز', 'warn')}` : ''}</div>` + tk.recent.slice(0, 3).map(t => `<div class="row xs" style="padding:2px 0"><span class="grow ell">${E(t.title)}</span><span class="muted">${E(t.status)}</span></div>`).join('');
  const acts = [all, r.customer && can.customers ? btn('customer_profile', 'id=' + r.customer.id, 'پروفایل مشتری', 'پروفایل CRM', 'user-focus') : '', cs.total && can.calls ? btn('phone_detail', 'number=0' + l10, 'سوابق شماره', 'سوابق شماره', 'phone') : ''].filter(Boolean).join('');
  return h + (acts ? `<div class="row wrap" style="margin-top:8px">${acts}</div>` : '');
}
async function fill(el, phone, pid) {
  const k = phone || ('p' + pid); const c = CACHE.get(k);
  if (c && Date.now() - c.t < 60000) { el.innerHTML = boxHtml(c.r, pid); return; }
  const r = await L().api('crm_person', {phone, person_id:pid});
  if (!el.isConnected) return;
  if (!r.ok) { el.innerHTML = `<div class="muted small">${E(r.error || 'خطا')}</div>`; return; }
  CACHE.set(k, {r:r.crm, t:Date.now()}); el.innerHTML = boxHtml(r.crm, pid);
}
/* HTML جاگیر؛ بعد از قرار گرفتن در صفحه خودش پر می‌شود */
EB.crmBox = (phone, pid) => {
  if (!CRM.present || !CRM.ready) return '';
  const id = 'crmb' + Math.random().toString(36).slice(2, 8);
  setTimeout(() => { const el = document.getElementById(id); if (el) fill(el, phone || '', pid || 0); }, 0);
  return `<div id="${id}" class="stack s6">${EB.sk ? EB.sk(2) : ''}</div>`;
};

/* ---------- کارت‌های CRM در داشبورد ---------- */
EB.crmKpiRow = k => {
  if (!k) return '';
  const cards = [];
  if (k.calls) cards.push(['crm_calls', 'phone-incoming', 'تماس امروز', nf(k.calls.total), `${fa(k.calls.answered)} پاسخ · ${fa(k.calls.missed)} بی‌پاسخ`]);
  if (k.leads) cards.push(['crm_leads', 'funnel', 'سرنخ باز', nf(k.leads.open), `${fa(k.leads.today)} سرنخ تازه‌ی امروز`]);
  if (k.tickets) cards.push(['crm_tickets', 'flag', 'تیکت باز', nf(k.tickets.open), k.tickets.high ? `${fa(k.tickets.high)} با اولویت بالا` : 'بدون تیکت فوری']);
  if (k.tasks) cards.push(['crm_tasks', 'check-square', 'تسک باز', nf(k.tasks.open), `${fa(k.tasks.doing)} در حال انجام`]);
  if (k.customers) cards.push(['crm_customers', 'users', 'مشتری CRM', nf(k.customers.total), `${fa(k.customers.month)} در این ماه`]);
  if (k.leaves && k.leaves.pending) cards.push(['crm_leaves', 'calendar-dots', 'مرخصی در انتظار', nf(k.leaves.pending), 'نیاز به تأیید']);
  if (!cards.length) return '';
  const cls = cards.length >= 6 ? 'k6' : cards.length === 5 ? 'k5' : '';
  const cols = cards.length < 4 ? ` style="margin-bottom:16px;grid-template-columns:repeat(${cards.length},minmax(0,1fr))"` : ' style="margin-bottom:16px"';
  return `<div class="row" style="margin:0 2px 8px;gap:8px"><b class="small">${ic('pulse')} CRM</b><span class="xs muted">تماس، فروش و تیم</span>${D.pageAllow.includes('crm_reports') ? `<button class="btn btn-g btn-sm ms-auto" data-act="go" data-to="crm_dash">داشبورد اجرایی</button>` : ''}</div>
  <div class="kpis ${cls}"${cols}>${cards.map(c => `<div class="kpi" data-act="go" data-to="${c[0]}" style="cursor:pointer"><span class="k-l">${ic(c[1])}${c[2]}</span><span class="k-v">${c[3]}</span><span class="k-d">${c[4]}</span></div>`).join('')}</div>`;
};

/* ---------- تنظیمات ← CRM ---------- */
const ST = {status:null, users:null, src:{host:'localhost', port:'3306', name:'', user:'', pass:''}, tables:null, same:false, replace:false, running:false, log:[]};
async function load() {
  const [s, u] = await Promise.all([L().api('crm_status'), L().api('crm_users')]);
  if (!s.ok) throw new Error(s.error);
  ST.status = s; ST.users = u.ok ? u : null;
}
function paint() { const b = $('#crmSetBox'); if (b) b.innerHTML = html(); }
const sec = (t, d, b) => `<div class="set-sec"><div class="d"><h4>${t}</h4>${d ? `<p>${d}</p>` : ''}</div><div class="stack s16" style="min-width:0">${b}</div></div>`;
const TBL = {users:'کاربران', customers:'مشتریان', leads:'سرنخ‌ها', calls:'تماس‌ها', tickets:'تیکت‌ها', tasks:'تسک‌ها', hosts:'میزبانان', host_leads:'جذب میزبان', staff:'پرسنل', chat_messages:'پیام‌های چت'};
function html() {
  const s = ST.status || {}; const base = location.origin + location.pathname.replace(/index\.php$/, '').replace(/\/$/, '');
  const stat = !s.present ? `<div class="callout" style="border-color:var(--err)">${ic('warning-octagon')}<span>پوشه‌ی <code>crm/</code> کنار فایل‌های اعلان‌بان پیدا نشد. بسته‌ی کامل ۲.۰.۰ را بریزید.</span></div>`
    : s.ready ? `<div class="row wrap" style="gap:6px">${pill('CRM فعال است', 'ok', 'check-circle')}${Object.entries(s.tables || {}).map(([t, n]) => n == null ? '' : `<span class="tag">${TBL[t] || t}: <b class="tnum">${nf(n)}</b></span>`).join('')}</div>${s.last_sync_at ? `<div class="xs muted">آخرین همگام‌سازی تماس‌ها: ${E(s.last_sync_at)}</div>` : '<div class="xs muted">همگام‌سازی تماس‌ها هنوز اجرا نشده (کرون پایین صفحه).</div>'}`
    : `<div class="callout">${ic('info')}<span>جدول‌های CRM هنوز در دیتابیس اعلان‌بان نیستند. اطلاعات CRM قبلی را منتقل کنید (پیشنهادی)، یا اگر CRM تازه است جدول‌های خالی را بسازید.</span></div>
       <div class="row"><button class="btn" data-act="crm-empty">${ic('database')}ساخت جدول‌های خالی</button></div>`;
  const src = ST.src;
  const tl = ST.tables;
  const mig = `<div class="fg">${field('میزبان دیتابیس', `<input class="inp ltr" id="cmHost" value="${E(src.host)}">`)}${field('پورت', `<input class="inp ltr" id="cmPort" value="${E(src.port)}">`)}${field('نام دیتابیس CRM قبلی', `<input class="inp ltr" id="cmName" value="${E(src.name)}" placeholder="مثلاً jakojast_crm">`)}${field('نام کاربری', `<input class="inp ltr" id="cmUser" value="${E(src.user)}" autocomplete="off">`)}${field('رمز', `<input class="inp ltr" id="cmPass" type="password" value="${E(src.pass)}" autocomplete="new-password">`, 'فقط برای همین انتقال استفاده می‌شود و جایی ذخیره نمی‌شود')}</div>
    <div class="row wrap"><button class="btn" data-act="crm-mig-check" ${ST.running ? 'disabled' : ''}>${ic('magnifying-glass')}بررسی دیتابیس</button>
      ${tl && tl.length ? `<label class="row small"><input type="checkbox" class="cb" id="cmReplace" ${ST.replace ? 'checked' : ''} data-on-change="crm-replace">جایگزینی جدول‌های موجود</label><button class="btn btn-p" data-act="crm-mig-run" ${ST.running ? 'disabled' : ''}>${ic('arrows-clockwise')}${ST.running ? 'در حال انتقال...' : 'شروع انتقال'}</button>` : ''}</div>
    ${ST.same ? `<div class="callout">${ic('check-circle')}<span>این همان دیتابیس اعلان‌بان است؛ CRM از قبل در همین دیتابیس است و انتقال لازم نیست.</span></div>` : ''}
    ${tl && tl.length ? `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>جدول</th><th>ردیف در CRM قبلی</th><th>در اعلان‌بان</th><th>وضعیت</th></tr></thead><tbody>${tl.map(t => `<tr><td class="ltr" style="text-align:right">${E(t.table)}</td><td class="tnum">${nf(t.rows)}</td><td class="tnum">${t.target_rows == null ? '<span class="muted">ندارد</span>' : nf(t.target_rows)}</td><td class="small" id="cmSt_${E(t.table)}">${t.st || (t.data ? '' : '<span class="muted">فقط ساختار</span>')}</td></tr>`).join('')}</tbody></table></div>` : ''}
    ${ST.log.length ? `<div class="xs" style="max-height:140px;overflow:auto;background:var(--bg-2,#f4f4f2);border-radius:8px;padding:8px">${ST.log.map(x => `<div>${x}</div>`).join('')}</div>` : ''}`;
  const U = ST.users || {}; const crmU = U.crm || []; const staff = U.staff || [];
  const users = !s.ready ? '<span class="muted small">بعد از انتقال یا ساخت جدول‌ها فعال می‌شود.</span>' : `<div class="tbl-wrap"><table class="tbl resp"><thead><tr><th>حساب اعلان‌بان</th><th>کاربر CRM</th><th>داخلی تلفنچی</th><th>پرسنل</th><th></th></tr></thead><tbody>
    ${(U.eb || []).map(e => { const cur = crmU.find(c => +c.eb_user_id === +e.id); return `<tr data-eb="${e.id}"><td><b>${E(e.name)}</b><div class="xs muted ltr" style="text-align:right">${E(e.username)} · ${{admin:'مدیر', supervisor:'سرپرست'}[e.role] || 'پشتیبان'}</div></td>
      <td><select class="inp inp-sm" data-f="crm"><option value="0">${cur ? 'همین کاربر' : '— در اولین ورود ساخته می‌شود —'}</option>${crmU.map(c => `<option value="${c.id}" ${cur && +cur.id === +c.id ? 'selected' : ''} ${c.eb_user_id && +c.eb_user_id !== +e.id ? 'disabled' : ''}>${E(c.full_name)} (${E(c.username)})${c.eb_user_id && +c.eb_user_id !== +e.id ? ' — متصل به حساب دیگر' : ''}</option>`).join('')}</select></td>
      <td><input class="inp inp-sm ltr" style="max-width:100px" data-f="exten" value="${E(cur ? cur.exten_number || '' : '')}" placeholder="مثلاً 101"></td>
      <td><select class="inp inp-sm" data-f="staff"><option value="0">—</option>${staff.map(p => `<option value="${p.id}" ${cur && +p.user_id === +cur.id ? 'selected' : ''}>${E(p.name)}${p.code ? ' · ' + E(p.code) : ''}</option>`).join('')}</select></td>
      <td class="w1"><button class="btn btn-sm" data-act="crm-link">ذخیره</button></td></tr>`; }).join('')}</tbody></table></div>
    <p class="xs muted">کاربران CRM قبلی با همان نام کاربری خودکار وصل می‌شوند، تا سرنخ‌ها و تسک‌هایی که به نام آن‌ها ثبت شده سر جایشان بمانند. اگر نام کاربری فرق دارد، اینجا دستی وصل کنید.</p>`;
  const cron = `<div class="stack s8 small"><div>همگام‌سازی تماس‌ها با تلفنچی (هر ۵ دقیقه):</div><div class="copyrow"><code class="code ltr">*/5 * * * * php ${E(s.dir || '…/crm')}/cron_sync.php</code><button class="btn btn-sm" data-act="copy">${ic('copy')}</button></div>
    <div>تبدیل صدا به متن مکالمه‌ها (اختیاری):</div><div class="copyrow"><code class="code ltr">*/10 * * * * php ${E(s.dir || '…/crm')}/cron/transcribe_calls.php</code><button class="btn btn-sm" data-act="copy">${ic('copy')}</button></div>
    <div>آدرس وب‌هوک تلفنچی (در پنل تلفنچی جایگزین آدرس قبلی کنید):</div><div class="copyrow"><code class="code ltr">${E(base)}/crm/webhook.php</code><button class="btn btn-sm" data-act="copy">${ic('copy')}</button></div></div>`;
  return `<div class="ph" style="margin-bottom:6px"><div><h2>CRM و یکپارچه‌سازی</h2><p>CRM جاکجاست داخل همین پنل: ورود با همین حساب‌ها، همین دیتابیس، پیامک با سرویس اعلان‌بان.</p></div><div class="acts">${s.ready ? `<button class="btn" data-act="go" data-to="settings.crmset">${ic('phone-call')}تنظیمات تلفنچی و سایت</button>` : ''}</div></div>
    ${sec('وضعیت', '', stat)}
    ${s.present ? sec('انتقال از CRM قبلی', 'همه‌ی جدول‌های دیتابیس CRM قبلی (به‌جز جدول‌های eb_) به دیتابیس اعلان‌بان کپی می‌شوند. CRM قبلی دست نمی‌خورد. نشست‌های ورود قدیمی منتقل نمی‌شوند.', mig) : ''}
    ${s.ready ? sec('پرونده‌ی یکپارچه‌ی مشتریان', 'هر شماره‌ای که در CRM هست (تماس‌گیرنده، مشتری، سرنخ، میزبان) در «مشتریان» می‌آید، با پیام‌های همه‌ی کانال‌ها یک پرونده می‌شود و تایم‌لاین «همه‌ی سوابق» دارد. کرون اعلان‌بان شماره‌های تازه را خودکار اضافه می‌کند.', syncHtml(s.sync || {})) : ''}
    ${s.present ? sec('اتصال همکاران', 'هر حساب اعلان‌بان به کدام کاربر CRM، داخلی تلفنچی و پرونده‌ی پرسنلی وصل است. نقش و دسترسی‌ها همان «اپراتورها و دسترسی» است (بخش CRM).', users) : ''}
    ${s.present ? sec('کرون و وب‌هوک', 'CRM حالا در پوشه‌ی crm اعلان‌بان است؛ مسیر کرون‌ها و وب‌هوک تلفنچی عوض می‌شود.', cron) : ''}`;
}
function syncHtml(y) {
  return `<div class="row wrap" style="gap:6px">${[['linked', 'شماره در پرونده‌ها'], ['callers', 'تماس‌گیرنده'], ['customers', 'مشتری CRM'], ['leads', 'سرنخ'], ['hosts', 'میزبان']].map(([k, t]) => `<span class="tag">${t}: <b class="tnum">${nf(y[k] || 0)}</b></span>`).join('')}</div>
    <div class="xs muted">${y.at ? 'آخرین همگام‌سازی کامل: ' + E(y.at) : 'هنوز همگام‌سازی نشده'}${y.pending ? ' · سوابق تازه در صف است' : ''}</div>
    <div class="row wrap"><button class="btn btn-p" data-act="crm-sync" ${ST.syncing ? 'disabled' : ''}>${ic('arrows-clockwise')}${ST.syncing ? 'در حال همگام‌سازی... ' + nf(ST.syncN || 0) : 'همگام‌سازی الان'}</button><button class="btn btn-g btn-sm" data-act="crm-sync" data-reset="1" ${ST.syncing ? 'disabled' : ''}>از اول</button></div>
    <p class="xs muted">شماره‌های ثابت (غیر موبایل) در بانک مشتری نمی‌آیند ولی در CRM می‌مانند. دقت کنید: «ارسال پیام» به «همه‌ی عضوها» حالا تماس‌گیرنده‌ها را هم شامل می‌شود؛ برای ارسال هدفمند از گروه یا فیلتر استفاده کنید.</p>`;
}
A['crm-sync'] = async el => {
  if (ST.syncing) return;
  ST.syncing = true; ST.syncN = 0; paint();
  let reset = el.dataset.reset === '1', r = null;
  for (let i = 0; i < 200; i++) {
    r = await L().api('crm_sync', reset ? {reset:1} : {}); reset = false;
    if (!r.ok) { toast(r.error, 'err'); break; }
    ST.syncN += r.processed || 0; if (ST.status) ST.status.sync = r.status; paint();
    if (r.done) break;
  }
  ST.syncing = false; paint();
  if (r && r.ok) toast(`همگام‌سازی تمام شد · ${nf((r.status || {}).linked || 0)} شماره در پرونده‌ها`);
};
EB.crmSet = {load, html};
EB.ON['crm-replace'] = el => { ST.replace = el.checked; };
const readSrc = () => { ['host', 'port', 'name', 'user', 'pass'].forEach(k => { const el = $('#cm' + k[0].toUpperCase() + k.slice(1)); if (el) ST.src[k] = el.value.trim(); }); return ST.src; };
A['crm-mig-check'] = async el => {
  const srcv = readSrc(); if (!srcv.name) return toast('نام دیتابیس را وارد کنید', 'err');
  el.classList.add('loading'); const r = await L().api('crm_mig_tables', {src:srcv}); el.classList.remove('loading');
  if (!r.ok) { toast(r.error, 'err'); ST.tables = null; paint(); return; }
  ST.same = !!r.same; ST.tables = r.tables; ST.log = []; paint();
};
A['crm-mig-run'] = () => {
  const tl = ST.tables || []; if (!tl.length) return;
  const exist = tl.filter(t => t.target_rows > 0);
  if (exist.length && !ST.replace) return toast(`${fa(exist.length)} جدول در اعلان‌بان داده دارد؛ «جایگزینی جدول‌های موجود» را بزنید یا آن‌ها را خالی کنید`, 'err');
  EB.confirm({title:'انتقال اطلاعات CRM', text:`${fa(tl.length)} جدول به دیتابیس اعلان‌بان کپی می‌شود${ST.replace ? ' و جدول‌های هم‌نام موجود جایگزین می‌شوند' : ''}. پیش از شروع از دیتابیس اعلان‌بان پشتیبان بگیرید. ادامه می‌دهید؟`, ok:'شروع انتقال', onOk:runMig});
};
async function runMig() {
  ST.running = true; ST.log = []; paint();
  const srcv = ST.src; let failed = 0;
  // اول جدول‌هایی که بقیه به آن‌ها کلید خارجی دارند (کلید خارجی در انتقال موقتاً خاموش است، ترتیب فقط برای خوانایی)
  for (const t of ST.tables) {
    const cell = () => document.getElementById('cmSt_' + t.table);
    let off = 0, done = false, tries = 0;
    while (!done) {
      const r = await L().api('crm_mig_copy', {src:srcv, table:t.table, offset:off, replace:ST.replace ? 1 : 0});
      if (!r.ok) {
        if (++tries < 3 && /timeout|gone away|lost/i.test(r.error || '')) continue;
        failed++; t.st = `<span style="color:var(--err)">${E(r.error)}</span>`; ST.log.push(`✗ ${E(t.table)}: ${E(r.error)}`); if (cell()) cell().innerHTML = t.st; break;
      }
      off = r.next; done = r.done;
      t.st = done ? `<span style="color:var(--ok)">✓ ${nf(r.total)}</span>` : `${nf(off)} / ${nf(r.total)}`;
      if (cell()) cell().innerHTML = t.st;
    }
    if (done) ST.log.push(`✓ ${E(t.table)}`);
  }
  const d = await L().api('crm_mig_done');
  ST.running = false; ST.src.pass = '';
  if (d.ok) ST.status = d.status;
  try { const u = await L().api('crm_users'); if (u.ok) ST.users = u; } catch (e) {}
  paint();
  if (!failed) setTimeout(() => { const b = document.querySelector('[data-act="crm-sync"]'); if (b) A['crm-sync'](b); }, 300);
  toast(failed ? `انتقال با ${fa(failed)} خطا تمام شد` : 'انتقال کامل شد؛ صفحه را یک بار تازه کنید تا منوی CRM بیاید', failed ? 'err' : 'ok');
}
A['crm-empty'] = el => EB.confirm({title:'ساخت جدول‌های خالی CRM', text:'اگر CRM قبلی دارید، به‌جای این کار «انتقال از CRM قبلی» را بزنید. ادامه؟', ok:'ساخت جدول‌ها', onOk:async () => {
  const r = await L().api('crm_install_empty'); if (!r.ok) return toast(r.error || (r.errors || []).join(' / '), 'err');
  ST.status = r.status; paint(); toast('جدول‌ها ساخته شدند؛ صفحه را یک بار تازه کنید');
}});
A['crm-link'] = async el => {
  const tr = el.closest('tr'); const g = f => tr.querySelector(`[data-f="${f}"]`).value;
  el.classList.add('loading');
  const r = await L().api('crm_user_link', {eb_id:+tr.dataset.eb, crm_id:+g('crm'), exten:g('exten'), staff_id:+g('staff')});
  el.classList.remove('loading');
  if (!r.ok) return toast(r.error, 'err');
  if (r.users) ST.users = r.users; paint(); toast('ذخیره شد');
};
})();
