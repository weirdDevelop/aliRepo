/* p-chatplus.js — ۱.۰.۱۴: چت و پشتیبانی (تماس سریع، تلفن گویا، رفع فیلتر، کارت و نام واحدها، یکسان‌سازی شماره‌ها) */
(function(){
const {$, $$, E, fa, nf, ic, pill, sw, tabs, empty, field, A, reg, toast, confirm, store} = EB;
const L = () => window.EB_LIVE;
const S = {tab:store.get('cpTab', 'call'), d:null, find:null};
const TABS = [['agent', 'رفتار دستیار', 'robot'], ['call', 'تماس سریع', 'phone-call'], ['ivr', 'تلفن گویا', 'headset'], ['filters', 'رفع فیلتر', 'funnel'], ['units', 'کارت و نام واحدها', 'list-checks'], ['phones', 'شماره‌ها', 'users-three']];
const val = id => { const el = document.getElementById(id); return el ? (el.type === 'checkbox' ? el.checked : el.value.trim()) : ''; };
const toEn = s => String(s || '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
const kpi = (l, v, s) => `<div class="kpi"><div class="k-l">${l}</div><div class="k-v">${v}</div>${s ? `<div class="k-s xs muted">${s}</div>` : ''}</div>`;
const dt = s => s ? E(L().fullDT ? L().fullDT(s) : s) : '—';
async function run(btn, fn) { if (btn) { btn.classList.add('loading'); btn.disabled = true; } try { return await fn(); } finally { if (btn && btn.isConnected) { btn.classList.remove('loading'); btn.disabled = false; } } }

reg('chatplus', {title:'چت و پشتیبانی', icon:'headset', sub:'تماس سریع، تلفن گویا، رفع فیلتر، کارت اقامتگاه‌ها و شماره‌ها', perm:'webchat', admin:true, wide:true,
  async load() { await load(); },
  render(el) { el.innerHTML = `<div class="page" id="cpPage">${page()}</div>`; }});

async function load() { const r = await L().api('cp_get'); if (!r.ok) throw new Error(r.error || 'بارگذاری نشد'); S.d = r; }
function paint() { const b = $('#cpPage'); if (b) b.innerHTML = page(); }
async function refresh() { try { await load(); paint(); } catch (e) { toast(e.message, 'err'); } }

function page() {
  return `<div class="ph"><div><h2>چت و پشتیبانی</h2><p>ابزارهای ارتباط سریع با مشتری و تیم پشتیبانی در گفتگوی سایت و همه‌ی کانال‌ها.</p></div></div>
    <div style="margin-bottom:16px">${tabs(TABS, S.tab, 'cp-tab')}</div><div id="cpBody">${body()}</div>`;
}
function body() { return ({agent, call, ivr, filters, units, phones}[S.tab] || call)(); }

/* ---------------- ۱.۰.۱۶: رفتار دستیار در گفتگو ---------------- */
function agent() {
  const s = S.d.settings;
  return `<div class="card"><div class="card-b stack s20">
    ${field('عنوان زیر نام دستیار در پنجره‌ی گفتگو', `<input class="inp" id="agRole" value="${E(s.role_label)}" maxlength="60" placeholder="پشتیبان هوشمند شما">`, 'مثلاً «پشتیبان هوشمند شما» یا «پشتیبان جاکجاست». نام و تصویر دستیار از «دستیار فروش ← برندها و پیج‌ها» تنظیم می‌شود.')}
    ${field('شیوه‌های پرداخت (اقساط، کارت‌به‌کارت)', `<textarea class="inp" id="agPay" rows="4">${E(s.pay_info)}</textarea>`, 'دستیار وقتی از اقساط، کارت‌به‌کارت یا نحوه‌ی پرداخت پرسیده شد دقیقاً طبق این جواب می‌دهد.')}
    ${field('اطلاعات و لینک‌های رسمی برای دستیار', `<textarea class="inp" id="agFacts" rows="6" placeholder="لینک دانلود اپلیکیشن: https://…&#10;کد تخفیف خرید اول از اپ: …&#10;ساعت پاسخ‌گویی تلفنی: ۹ تا ۲۱">${E(s.agent_facts)}</textarea>`, 'دستیار همیشه این‌ها را می‌داند و دقیقاً همین‌ها را به مشتری می‌دهد (لینک اپ، کد تخفیف، شرایط عمومی…). چیزی خارج از این‌ها نمی‌سازد.')}
    ${field('نام‌های دیگر و زیرمحله‌ها', `<textarea class="inp" id="agAlias" rows="4" placeholder="سعادت آباد = مرجانی، سرو، کاج&#10;تهرانپارس = فلکه اول، فلکه دوم">${E(s.area_alias)}</textarea>`, 'هر خط: «محله = نام‌های دیگر». وقتی جستجوی یک محله خالی برگردد، اول این نام‌ها و املای با/بی‌فاصله‌ی همان محله گشته می‌شود و بعد محله‌های همسایه.')}
    <div class="fg">${field('انعطاف بودجه (درصد)', `<input class="inp ltr" type="number" min="0" max="100" id="agFlex" value="${E(s.budget_flex)}">`, 'گزینه‌های تا این درصد گران‌تر از بودجه‌ی مشتری هم آورده و با «چقدر بیشتر» معرفی می‌شوند. اگر هیچ گزینه‌ی نزدیکی نبود، دستیار پیشنهاد می‌دهد همکاران برایش پیدا کنند.')}</div>
    <label class="row">${sw('agHost', s.host_diverse === '1')}<span>از هر میزبان/ساختمان فقط یک گزینه پیشنهاد شود</span></label>
    <label class="row">${sw('agOrd', s.cb_on_order === '1')}<span>هر استعلام یا رزرو ثبت‌شده در فهرست <b>درخواست‌های تماس</b> هم بیاید تا تیم پیگیری کند (تلفن گویا برای این‌ها زنگ نمی‌زند)</span></label>
    <label class="row">${sw('agAh', s.ah_enabled === '1')}<span><b>خارج از ساعت کاری میزبان‌ها</b> (مثلاً ۲۲ تا ۷)، برای امشب/فردا اول اقامتگاه‌های میزبانِ هماهنگ‌شده معرفی شوند تا استعلام‌های بی‌پاسخ شب کمتر شود</span></label>
    <div class="fg">${field('شروع', `<input class="inp ltr" id="agAhF" value="${E(s.ah_from)}" placeholder="22:00">`)}${field('پایان', `<input class="inp ltr" id="agAhT" value="${E(s.ah_to)}" placeholder="07:00">`)}</div>
    ${field('میزبان‌ها / اقامتگاه‌های هماهنگ‌شده', `<textarea class="inp" id="agAhH" rows="3" placeholder="هر خط یک کد اقامتگاه یا نام میزبان؛ خالی = همه">${E(s.ah_hosts)}</textarea>`, 'فقط میزبان‌هایی که از قبل موافقت کرده‌اند اگر تقویمشان خالی بود، شبانه رزرو بگیرند. توجه: فوری‌بودن واقعی رزرو را خودِ سایت جاکجاست تعیین می‌کند؛ این بخش فقط ترتیب و توضیح دستیار را عوض می‌کند.')}
    <div class="row"><button class="btn btn-p" data-act="cp-save-agent">${ic('check')}ذخیره</button></div>
  </div></div>`;
}
A['cp-save-agent'] = el => save(el, {role_label:val('agRole'), agent_facts:val('agFacts'), pay_info:val('agPay'), area_alias:val('agAlias'), budget_flex:toEn(val('agFlex')), host_diverse:val('agHost'), cb_on_order:val('agOrd'), ah_enabled:val('agAh'), ah_from:val('agAhF'), ah_to:val('agAhT'), ah_hosts:val('agAhH')});
A['cp-tab'] = el => { S.tab = el.dataset.v; store.set('cpTab', S.tab); paint(); };

async function save(el, settings, msg) {
  const r = await run(el, () => L().api('cp_save', {settings}));
  if (!r.ok) return toast(r.error, 'err');
  S.d.settings = r.settings; if (r.staff) S.d.staff = r.staff;
  (r.warnings || []).forEach(w => toast(w, 'warn'));
  toast(msg || 'ذخیره شد'); paint();
}

/* ---------------- ۹) تماس سریع ---------------- */
function call() {
  const s = S.d.settings;
  return `${!S.d.webchat ? `<div class="callout warn" style="margin-bottom:14px">${ic('warning')}<span>گفتگوی آنلاین سایت خاموش است؛ دکمه‌ی تماس داخل پنجره‌ی گفتگوی سایت نمایش داده می‌شود. از <a href="#widgets">سایت ← ویجت‌ها</a> روشنش کنید.</span></div>` : ''}
  <div class="card"><div class="card-b stack s20">
    <label class="row">${sw('cpCall', s.call_enabled === '1')}<span><b>دکمه‌ی «تماس با پشتیبان»</b> در گوشه‌ی پنجره‌ی گفتگوی سایت (موبایل و دسکتاپ)</span></label>
    <div class="fg">
      ${field('شماره‌های تماس شرکت', `<textarea class="inp" id="cpPhone" rows="3" placeholder="شرکت: 02191001234&#10;پشتیبانی: 09121234567">${E(s.call_phone)}</textarea>`, 'هر خط یک شماره (حداکثر ۴)، عنوان اختیاری. مشتری با لمس دکمه، خودش با همین شماره تماس می‌گیرد (Click to Call)؛ در دسکتاپ شماره با دکمه‌ی کپی نمایش داده می‌شود.')}
      ${field('متن دکمه', `<input class="inp" id="cpLabel" value="${E(s.call_label)}" maxlength="40">`)}
      ${field('توضیح کوتاه زیر عنوان', `<input class="inp" id="cpNote" value="${E(s.call_note)}" maxlength="120">`, '', 'full')}
    </div>
    <label class="row">${sw('cpFab', s.call_fab === '1')}<span>دکمه‌ی کوچک تماس، بالای دکمه‌ی گفتگو هم روی سایت ثابت بماند</span></label>
    <label class="row">${sw('cpCb', s.cb_form === '1')}<span>دکمه‌ی <b>«همکاران با من تماس بگیرند»</b>: بازدیدکننده نام، شماره و زمان مناسب را می‌دهد و درخواست تماس با برچسب «${E(S.d.priority_tag)}» در صندوق ورودی و فهرست درخواست‌های تماس می‌نشیند</span></label>
    <div class="callout">${ic('info')}<span>هر درخواست تماس (از دستیار فروش یا همین دکمه) روی گفتگو برچسب قرمز <b>«${E(S.d.priority_tag)}»</b> و اولویت «فوری» می‌گیرد تا در صندوق ورودی سریع دیده شود؛ با «تماس گرفته شد» برچسب خودکار برداشته می‌شود.</span></div>
    <div class="row"><button class="btn btn-p" data-act="cp-save-call">${ic('check')}ذخیره</button></div>
  </div></div>`;
}
A['cp-save-call'] = el => save(el, {call_enabled:val('cpCall'), call_phone:val('cpPhone'), call_label:val('cpLabel'), call_note:val('cpNote'), call_fab:val('cpFab'), cb_form:val('cpCb')});

/* ---------------- ۷) تلفن گویا ---------------- */
function ivr() {
  const s = S.d.settings, staff = S.d.staff || [], log = S.d.ivr_log || [];
  const ST = {pending:['در صف', ''], calling:['در حال تماس', 'warn'], sent:['تماس گرفته شد', 'ok'], failed:['ناموفق', 'err'], expired:['منقضی', '']};
  const RS = {callback:'درخواست تماس', handoff:'سپردن به همکار', wait:'گفتگوی بی‌پاسخ', test:'آزمایشی'};
  return `${!S.d.voice_ready ? `<div class="callout warn" style="margin-bottom:14px">${ic('warning')}<span>سرویس تماس صوتی آماده نیست. تلفن گویا روی حساب <b>ملی‌پیامک</b> کار می‌کند: در «کانال‌ها ← پیامک» سرویس‌دهنده را ملی‌پیامک بگذارید (همان نام کاربری و کلید).</span></div>` : ''}
  <div class="card" style="margin-bottom:16px"><div class="card-b stack s20">
    <label class="row">${sw('ivOn', s.ivr_enabled === '1')}<span><b>تماس خودکار با پشتیبان‌ها</b> وقتی یک گفتگو به رسیدگی تیم نیاز دارد</span></label>
    <div class="fg">
      ${field('شماره‌ی پشتیبان‌ها', `<textarea class="inp" id="ivPhones" rows="4" placeholder="علی رضایی: 09121234567&#10;مریم احمدی: 09351234567">${E(s.ivr_phones)}</textarea>`, 'هر خط یک نفر؛ نام اختیاری است. شماره با هر قالبی وارد شود به 09… استاندارد می‌شود.')}
      ${field('متن پیام صوتی', `<textarea class="inp" id="ivText" rows="4" maxlength="400">${E(s.ivr_text)}</textarea>`, 'این متن با صدای ماشینی (تبدیل متن به گفتار) پخش می‌شود.')}
    </div>
    ${staff.length ? `<div class="row wrap" style="gap:6px">${staff.map(x => x.phone ? pill(E((x.name ? x.name + ' · ' : '') + fa(x.phone)), 'ok', 'phone') : pill('نامعتبر: ' + E(x.raw), 'err')).join('')}</div>` : ''}
    <div class="lab">کِی تماس بگیرد؟</div>
    <label class="row">${sw('ivCb', s.ivr_on_callback === '1')}<span>مشتری درخواست تماس ثبت کرد</span></label>
    <label class="row">${sw('ivHand', s.ivr_on_handoff === '1')}<span>دستیار فروش گفتگو را به همکار سپرد (مشتری انسان خواست، ناراضی بود، دستیار نتوانست جواب بدهد…)</span></label>
    <label class="row"><span>گفتگو</span><input class="inp inp-sm ltr" type="number" min="0" max="240" id="ivWait" value="${E(s.ivr_wait)}" style="max-width:76px"><span>دقیقه بی‌پاسخ ماند (۰ = خاموش)</span></label>
    <details><summary class="small" style="cursor:pointer;color:var(--accent-ink)">${ic('sliders-horizontal')} تنظیمات بیشتر</summary>
      <div class="fg" style="margin-top:12px">
        ${field('با کدام شماره‌ها؟', `<select class="inp" id="ivMode"><option value="all" ${s.ivr_mode === 'all' ? 'selected' : ''}>همه‌ی پشتیبان‌ها هم‌زمان</option><option value="first" ${s.ivr_mode === 'first' ? 'selected' : ''}>فقط اولین شماره</option></select>`)}
        ${field('فاصله‌ی دو تماس برای یک گفتگو (دقیقه)', `<input class="inp ltr" type="number" min="1" max="1440" id="ivCool" value="${E(s.ivr_cooldown)}">`)}
        ${field('سقف کل تماس‌ها در ساعت', `<input class="inp ltr" type="number" min="1" max="200" id="ivMax" value="${E(s.ivr_max_hour)}">`, 'برای جلوگیری از هزینه‌ی ناخواسته')}
      </div>
      <label class="row" style="margin-top:8px">${sw('ivHours', s.ivr_hours_only === '1')}<span>فقط در ساعت کاری پشتیبانی تماس بگیرد</span></label>
    </details>
    <div class="row wrap"><button class="btn btn-p" data-act="cp-save-ivr">${ic('check')}ذخیره</button><button class="btn" data-act="cp-ivr-test">${ic('phone-call')}تماس آزمایشی با پشتیبان‌ها</button>
      <span class="xs muted">تماس‌ها از صف و حداکثر تا یک دقیقه بعد (با زمان‌بندی خودکار) برقرار می‌شوند.</span></div>
  </div></div>
  <div class="card"><div class="card-h"><h3>${ic('list-checks')}آخرین تماس‌ها</h3></div>
    ${log.length ? `<div class="tbl-wrap"><table class="tbl resp"><thead><tr><th>زمان</th><th>دلیل</th><th>گفتگو</th><th>شماره‌ی پشتیبان</th><th>نتیجه</th></tr></thead><tbody>
      ${log.map(r => `<tr><td class="xs">${dt(r.created_at)}</td><td>${E(RS[r.reason] || r.reason)}</td><td>${r.person_id ? `<a href="#inbox.v${+r.person_id}">${E(r.person || ('مشتری ' + r.person_id))}</a>` : '—'}</td><td class="ltr">${E(fa(r.phone))}</td><td>${pill((ST[r.status] || [r.status])[0], (ST[r.status] || [])[1] || '')}${r.error ? `<div class="xs muted">${E(r.error)}</div>` : ''}</td></tr>`).join('')}
    </tbody></table></div>` : `<div class="card-b">${empty('headset', 'هنوز تماسی برقرار نشده', 'بعد از روشن کردن و ثبت شماره‌ها، اینجا سابقه‌ی تماس‌ها را می‌بینید.')}</div>`}</div>`;
}
A['cp-save-ivr'] = el => save(el, {ivr_enabled:val('ivOn'), ivr_phones:val('ivPhones'), ivr_text:val('ivText'), ivr_on_callback:val('ivCb'), ivr_on_handoff:val('ivHand'),
  ivr_wait:toEn(val('ivWait')), ivr_mode:val('ivMode'), ivr_cooldown:toEn(val('ivCool')), ivr_max_hour:toEn(val('ivMax')), ivr_hours_only:val('ivHours')});
A['cp-ivr-test'] = el => confirm({title:'تماس آزمایشی', text:'با حداکثر ۳ شماره‌ی ذخیره‌شده تماس گرفته و پیام صوتی پخش می‌شود (هزینه‌ی تماس از حساب ملی‌پیامک کم می‌شود). اول تغییرات را ذخیره کنید.', ok:'تماس بگیر', onOk:async () => {
  const r = await run(el, () => L().api('cp_ivr_test', {}));
  if (r.log) S.d.ivr_log = r.log;
  if (!r.ok) { toast(r.error || 'تماس برقرار نشد', 'err'); paint(); return; }
  toast('تماس برقرار شد: ' + r.results.filter(x => x.ok).map(x => fa(x.phone)).join('، ')); paint(); }});

/* ---------------- ۱) رفع فیلتر ---------------- */
function filters() {
  const rows = S.d.filters || [];
  return `<div class="callout" style="margin-bottom:14px">${ic('info')}<span>مشتری‌هایی که الان محدودیت دارند: بلاک دستی، توقف دستیار به‌خاطر پیام‌های مشکوک، سقف پیام یا جستجوی ساعتی، یا سقف پیام چت سایت برای IP. «رفع فیلتر» همه را با هم برمی‌دارد، سقف‌ها را از همین لحظه از نو می‌شمارد و دستیار را دوباره فعال می‌کند (اگر همکاری گفتگو را برنداشته باشد). پشتیبان‌ها هم از صندوق ورودی، بالای هر گفتگو، همین دکمه را دارند.</span></div>
  <div class="card"><div class="card-h"><h3>${ic('funnel')}مشتری‌های فیلترشده (${fa(rows.length)})</h3><div class="acts"><button class="btn btn-sm" data-act="cp-filters-reload">${ic('arrows-clockwise')}به‌روزرسانی</button>${rows.length > 1 ? `<button class="btn btn-sm btn-p" data-act="cp-unfilter-all">رفع فیلتر همه (${fa(rows.length)})</button>` : ''}</div></div>
    ${rows.length ? `<div class="tbl-wrap"><table class="tbl resp"><thead><tr><th>مشتری</th><th>دلیل</th><th></th></tr></thead><tbody>
      ${rows.map(r => `<tr><td><b>${E(r.name)}</b>${r.phone ? `<div class="xs muted ltr" style="text-align:right">${E(fa(r.phone))}</div>` : ''}</td><td class="small">${r.reasons.map(E).join('<br>')}</td>
        <td><div class="row" style="justify-content:flex-end"><a class="btn btn-sm" href="#inbox.v${+r.person_id}">${ic('chat-circle')}گفتگو</a><button class="btn btn-sm btn-p" data-act="cp-unfilter" data-id="${+r.person_id}">رفع فیلتر</button></div></td></tr>`).join('')}
    </tbody></table></div>` : `<div class="card-b">${empty('shield-check', 'مشتری فیلترشده‌ای نیست', 'همه‌ی مشتری‌ها بدون محدودیت پاسخ می‌گیرند.')}</div>`}</div>`;
}
A['cp-unfilter-all'] = el => confirm({title:'رفع فیلتر همه', text:'بلاک، توقف دستیار و سقف‌های همه‌ی این مشتری‌ها برداشته می‌شود و دستیار دوباره به آن‌ها جواب می‌دهد.', ok:'رفع فیلتر همه', onOk:async () => {
  const r = await run(el, () => L().api('cp_unfilter_all', {})); if (!r.ok) return toast(r.error, 'err');
  S.d.filters = r.rows; toast(fa(r.count) + ' مشتری رفع فیلتر شد'); paint(); }});
A['cp-filters-reload'] = async el => { const r = await run(el, () => L().api('cp_filters', {})); if (!r.ok) return toast(r.error, 'err'); S.d.filters = r.rows; paint(); };
A['cp-unfilter'] = async el => {
  const r = await run(el, () => L().api('inbox_unfilter', {person_id:+el.dataset.id}));
  if (!r.ok) return toast(r.error, 'err');
  toast('انجام شد: ' + (r.done || []).join('، ')); S.d.filters = (S.d.filters || []).filter(x => x.person_id !== +el.dataset.id); paint();
};

/* ---------------- ۳، ۴، ۵) کارت و نام واحدها ---------------- */
function units() {
  const s = S.d.settings, u = S.d.units || {}, f = S.find;
  return `<div class="card" style="margin-bottom:16px"><div class="card-h"><h3>${ic('list-checks')}کارت اقامتگاه‌ها در گفتگوی سایت</h3></div><div class="card-b stack s20">
    <label class="row">${sw('unCards', s.cards === '1')}<span>نتیجه‌ی جستجوی دستیار فروش به‌صورت <b>کروسل کارت</b> نمایش داده شود: عکس، نام، تاریخ ورود و خروج، قیمت بازه و دکمه‌ی «ثبت رزرو» (در تب تازه). فقط همان اقامتگاه‌هایی که دستیار در متن پیامش معرفی کرده کارت می‌شوند، با اسکرول افقی</span></label>
    <div class="fg">
      ${field('حداکثر کارت در هر پیام', `<input class="inp ltr" type="number" min="2" max="10" id="unMax" value="${E(s.cards_max)}">`)}
      ${field('متن دکمه‌ی هر کارت', `<input class="inp" id="unBtn" value="${E(s.cards_button)}" maxlength="40">`)}
    </div>
    <label class="row">${sw('unMatch', s.unit_match === '1')}<span><b>تشخیص نام اختصاصی واحدها:</b> اگر مشتری نام یک واحد را بنویسد (مثلاً «سوئیت لوتوس»)، دستیار همان واحد را پیدا و درباره‌ی همان جواب می‌دهد</span></label>
    <div class="callout">${ic('info')}<span>«ارزان‌ترین» هم فعال است: وقتی مشتری ارزان‌ترین یا کمترین قیمت بخواهد، دستیار چند صفحه از نتایج را می‌گردد و از ارزان به گران (قیمت کل همان بازه) معرفی می‌کند.</span></div>
    <div class="row"><button class="btn btn-p" data-act="cp-save-units">${ic('check')}ذخیره</button></div>
  </div></div>
  <div class="kpis" style="margin-bottom:16px">
    ${kpi('واحدهای شناخته‌شده', fa(nf(u.count || 0)), u.last ? 'آخرین به‌روزرسانی ' + dt(u.last) : 'از جستجوهای دستیار جمع می‌شود')}
    ${kpi('آخرین همگام‌سازی', u.sync ? fa(nf(u.sync.units || 0)) + ' واحد' : '—', u.sync ? dt(u.sync.at) + (u.sync.error ? ' · ' + E(u.sync.error) : '') : 'هنوز انجام نشده')}
  </div>
  <div class="card"><div class="card-h"><h3>${ic('list-checks')}فهرست نام واحدها</h3><div class="acts"><button class="btn btn-sm" data-act="cp-units-sync" ${S.d.search_tool ? '' : 'disabled title="اتصال API با ابزار search پیدا نشد"'}>${ic('arrows-clockwise')}همگام‌سازی از وب‌سرویس</button></div></div>
    <div class="card-b stack s12">
      <p class="small muted" style="margin:0">نام و کد هر واحدی که دستیار در جستجوها می‌بیند خودکار اینجا ثبت می‌شود. با «همگام‌سازی» همه‌ی صفحه‌های جستجو یک‌جا خوانده می‌شود. برای آزمایش، جمله‌ای مثل پیام مشتری بنویسید:</p>
      <div class="row"><input class="inp" id="unQ" placeholder="مثلاً: سوئیت لوتوس برای آخر هفته خالیه؟"><button class="btn" data-act="cp-units-find">${ic('magnifying-glass')}بررسی</button></div>
      ${f ? `<div class="callout ${f.match ? '' : 'warn'}">${ic(f.match ? 'check-circle' : 'warning')}<span>${f.match ? `تشخیص: <b>${E(f.match.title)}</b> (کد ${E(fa(f.match.space_id))}${f.match.place ? '، ' + E(f.match.place) : ''})` : 'نام واحد مشخصی در این جمله تشخیص داده نشد'}${f.rows.length ? ' · مشابه‌ها: ' + f.rows.map(x => E(x.title) + ' (' + E(fa(x.space_id)) + ')').join('، ') : ''}</span></div>` : ''}
      ${(u.recent || []).length ? `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>کد</th><th>نام</th><th>محل</th><th>قیمت شبانه</th></tr></thead><tbody>${u.recent.map(x => `<tr><td class="ltr">${E(fa(x.space_id))}</td><td>${E(x.title)}</td><td class="small">${E(x.place || '—')}</td><td class="small">${x.price ? fa(nf(x.price)) + ' تومان' : '—'}</td></tr>`).join('')}</tbody></table></div>` : ''}
    </div></div>`;
}
A['cp-save-units'] = el => save(el, {cards:val('unCards'), cards_max:toEn(val('unMax')), cards_button:val('unBtn'), unit_match:val('unMatch')});
A['cp-units-sync'] = async el => {
  const r = await run(el, () => L().api('cp_units_sync', {}));
  if (r.units_report) S.d.units = r.units_report;
  if (!r.ok) { toast(r.error || 'همگام‌سازی نشد', 'err'); paint(); return; }
  toast(fa(r.units) + ' واحد از ' + fa(r.pages) + ' صفحه ثبت شد'); paint();
};
A['cp-units-find'] = async el => {
  const q = val('unQ'); if (!q) return;
  const r = await run(el, () => L().api('cp_units_find', {q}));
  if (!r.ok) return toast(r.error, 'err'); S.find = {match:r.match, rows:r.rows || []}; paint(); const i = $('#unQ'); if (i) i.value = q;
};

/* ---------------- ۱۰) شماره‌ها ---------------- */
function phones() {
  const p = S.d.phones || {}, m = p.migration || {};
  const PH = {persons:'تبدیل شماره‌ی مشتری‌ها', identities:'هویت‌های پیامک/صفیر/تلفن گویا', other:'جدول‌های دیگر', done:'تمام شد'};
  return `<div class="callout" style="margin-bottom:14px">${ic('info')}<span>همه‌ی شماره‌ها قبل از ذخیره به قالب <b class="ltr">09XXXXXXXXX</b> تبدیل می‌شوند: چت سایت و فرم‌ها، همه‌ی کانال‌ها، ثبت و ویرایش دستی، ورود اکسل، API و درخواست تماس. شماره‌ی نامعتبر یا ناقص ذخیره نمی‌شود و شماره‌ی استاندارد مبنای یکی کردن مشتری در همه‌ی کانال‌هاست (مثلاً ‎+98912… در اینستاگرام و 0912… در سایت = یک پروفایل).</span></div>
  <div class="kpis" style="margin-bottom:16px">
    ${kpi('مشتری‌های دارای شماره', fa(nf(p.total || 0)))}
    ${kpi('با قالب استاندارد 09…', fa(nf(p.standard || 0)), p.total ? fa(Math.round(100 * (p.standard || 0) / p.total)) + '٪' : '')}
    ${kpi('پروفایل‌های تکراری یکی‌شده', fa(nf(m.merged || 0)))}
    ${kpi('شماره‌ی نامعتبر کنار گذاشته‌شده', fa(nf(m.invalid || 0)), 'متن اصلی در یادداشت مشتری مانده')}
  </div>
  <div class="card"><div class="card-b stack s12">
    <div class="row wrap" style="gap:8px">${m.done ? pill('یکسان‌سازی شماره‌های قبلی کامل شد', 'ok', 'check-circle') : pill('در حال انجام: ' + (PH[m.phase] || m.phase || 'شروع نشده'), 'warn')}
      ${m.at ? `<span class="xs muted">آخرین اجرا ${dt(m.at)}</span>` : ''}</div>
    <p class="small muted" style="margin:0">شماره‌های قدیمی دیتابیس بعد از نصب پچ خودکار و تکه‌تکه (با زمان‌بندی خودکار هر دقیقه) تبدیل می‌شوند و مشتری‌های تکراری با همه‌ی پیام‌ها و سوابقشان در پروفایل قدیمی‌تر یکی می‌شوند. اجرای دوباره بی‌خطر است.</p>
    <div class="row wrap"><button class="btn btn-p" data-act="cp-phones-run">${ic('arrows-clockwise')}${m.done ? 'بررسی دوباره‌ی همه‌ی شماره‌ها' : 'ادامه‌ی تبدیل همین حالا'}</button></div>
  </div></div>`;
}
A['cp-phones-run'] = async el => {
  const restart = !!(S.d.phones && S.d.phones.migration && S.d.phones.migration.done);
  const r = await run(el, () => L().api('cp_phones_run', {restart:restart ? 1 : 0}));
  if (!r.ok) return toast(r.error, 'err');
  S.d.phones = r.report; toast(r.result.done ? 'تمام شد' : 'بخشی انجام شد؛ ادامه خودکار با زمان‌بندی'); paint();
};
})();
