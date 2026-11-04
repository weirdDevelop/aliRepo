(function(){
const {$, $$, E, fa, ic, av, pill, sw, seg, empty, field, A, ON, UI, reg, go, toast, modal, drawer, confirm, P, setPref, ACCENTS, FONTS, DFONTS, RADII, DENS, DEF_PREFS} = EB;
const L = () => window.EB_LIVE;
const reshell = () => { EB.shell(); EB.route(true); };   // پوسته کل #app را می‌سازد، پس صفحه هم دوباره رندر می‌شود
const isAdmin = () => !!(D.me && D.me.is_admin);

const SECS = [
  {h:'حساب و ظاهر'}, ['appearance', 'ظاهر و تم', 'palette'], ['me', 'پروفایل و رمز', 'user-circle'],
  {h:'کسب‌وکار', admin:1}, ['brand', 'برند و شرکت', 'paint-brush', 1], ['channels', 'اتصال کانال‌ها', 'plugs-connected', 1],
  {h:'تیم', admin:1}, ['operators', 'اپراتورها و دسترسی', 'user-gear', 1], ['roles', 'چارت سازمانی و نقش‌ها', 'tree-structure', 1], ['sites', 'سایت‌ها (چندسایته)', 'globe-hemisphere-west', 1], ['routing', 'تیم‌ها، SLA و ساعت کاری', 'arrows-split', 1], ['inbox', 'صندوق ورودی و پیگیری', 'tray', 1],
  ...((window.EB_BOOT || {}).crm && EB_BOOT.crm.present ? [{h:'CRM', admin:1}, ['crm', 'CRM و انتقال داده', 'database', 1], ...(EB_BOOT.crm.ready ? [['crmset', 'تلفنچی، سایت و CRM', 'phone-call', 1]] : [])] : []),
  {h:'سیستم', admin:1}, ['system', 'سیستم و نگهداری', 'hard-drives', 1], ['proxy', 'پروکسی و دسترسی', 'shield-check', 1], 
];
const visible = () => SECS.filter(s => (s.h ? !s.admin : !s[3]) || isAdmin());
const valid = k => visible().some(s => s[0] && s[0] === k);
let cur = 'appearance';
const S = {};          // کش داده‌های هر بخش
let renderSeq = 0;

reg('settings', {title:'تنظیمات', icon:'gear-six', full:true,
  render(view, args) {
    cur = valid(args[0]) ? args[0] : 'appearance';
    view.innerHTML = `<div class="set"><nav class="set-nav" id="setNav"></nav><div class="set-body scroll" id="setBody"></div></div>`;
    nav(); body();
  },
  update(args) { cur = valid(args[0]) ? args[0] : 'appearance'; nav(); body(); }});

function nav() {
  $('#setNav').innerHTML = `<div class="row" style="padding:0 10px 10px"><h3 style="padding:0">تنظیمات</h3><button class="btn btn-g btn-ic btn-sm ms-auto only-narrow" data-act="set-nav-close" aria-label="بستن">${ic('x')}</button></div>`
    + visible().map(s => s.h ? `<div class="grp">${s.h}</div>` : `<a class="ib-i ${cur === s[0] ? 'on' : ''}" href="#settings.${s[0]}" style="text-decoration:none">${ic(s[2])}<span class="grow">${s[1]}</span></a>`).join('');
  $('#setNav').classList.remove('open');
}
A['set-nav-open'] = () => $('#setNav').classList.add('open');
A['set-nav-close'] = () => $('#setNav').classList.remove('open');

const sec = (t, d, b) => `<div class="set-sec"><div class="d"><h4>${t}</h4>${d ? `<p>${d}</p>` : ''}</div><div class="stack s16" style="min-width:0">${b}</div></div>`;
const head = (t, d, acts = '') => `<div class="ph" style="margin-bottom:6px"><div><div class="row"><button class="btn btn-sm only-narrow" data-act="set-nav-open">${ic('list')}بخش‌ها</button></div><h2>${t}</h2><p>${d}</p></div><div class="acts">${acts}</div></div>`;
const saveBtn = (act, label = 'ذخیره') => `<div class="row"><button class="btn btn-p" data-act="${act}">${ic('check')}${label}</button></div>`;
const val = id => { const el = document.getElementById(id); return el ? (el.type === 'checkbox' ? el.checked : el.value.trim()) : ''; };
const toEn = s => String(s || '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
const colorInp = (id, v) => `<input type="color" id="${id}" value="${E(v || '#1D5C4D')}" style="inline-size:56px;block-size:34px;border:0;background:none;padding:0" aria-label="رنگ">`;


/* ---------- صندوق ورودی: دسترسی، اولویت خودکار، نظرسنجی رضایت، پیگیری خودکار ---------- */
const IC_CH = [['instagram', 'اینستاگرام'], ['telegram', 'تلگرام'], ['bale', 'بله'], ['roubika', 'روبیکا'], ['webchat', 'گفتگوی سایت'], ['sms', 'پیامک']];
const IC_PRI = [['urgent', 'فوری'], ['high', 'زیاد'], ['normal', 'عادی'], ['low', 'کم']];
const icPath = (o, path, v) => { const ks = path.split('.'); let x = o; ks.slice(0, -1).forEach(k => { x = x[k]; }); x[ks[ks.length - 1]] = v; };
const icGet = (o, path) => path.split('.').reduce((x, k) => x == null ? x : x[k], o);
const icTags = sel => `<option value="">—</option>${(D.tags || []).map(t => `<option value="${t.rid}" ${+sel === t.rid ? 'selected' : ''}>${E(t.name)}</option>`).join('')}`;
const icChecks = (path, list, opts) => `<div class="row wrap" style="gap:6px 14px">${opts.map(o => `<label class="row small"><input type="checkbox" class="cb" data-on-change="ic-arr" data-k="${path}" value="${o[0]}" ${(list || []).includes(o[0]) ? 'checked' : ''}>${o[1]}</label>`).join('')}</div>`;
function inboxCfg() {
  const c = S.icfg; if (!c) return '';
  const fs = S.icfgStats || {};
  const prio = c.prio_rules.map((r, i) => `<div class="card flat ic-card"><div class="card-b stack s12">
      <div class="row wrap" style="gap:10px"><input class="inp inp-sm" style="max-width:200px" placeholder="نام قانون، مثلاً خرید" value="${E(r.name || '')}" data-on-input="ic-val" data-k="prio_rules.${i}.name">
        <label class="row small ms-auto">${sw('icpa' + i, r.active !== false, '', 'ic-active')}<span>فعال</span></label><button class="btn btn-g btn-ic btn-sm" data-act="ic-del" data-k="prio_rules" data-i="${i}" aria-label="حذف">${ic('trash-simple')}</button></div>
      <div class="fg">${field('کلمه‌ها یا عبارت‌ها (هر خط یکی)', `<textarea class="inp" rows="3" data-on-input="ic-words" data-k="prio_rules.${i}.words" placeholder="قیمت&#10;خرید&#10;موجود">${E((r.words || []).join('\n'))}</textarea>`, 'ی/ک عربی و نیم‌فاصله یکسان در نظر گرفته می‌شوند')}
        <div class="stack s12">${field('اولویت گفتگو', `<select class="inp" data-on-change="ic-val" data-k="prio_rules.${i}.priority">${IC_PRI.map(p => `<option value="${p[0]}" ${r.priority === p[0] ? 'selected' : ''}>${p[1]}</option>`).join('')}</select>`)}
        ${field('برچسب گفتگو (اختیاری)', `<select class="inp" data-on-change="ic-num" data-k="prio_rules.${i}.label">${icTags(r.label)}</select>`)}
        ${field('روش تطبیق', `<select class="inp" data-on-change="ic-val" data-k="prio_rules.${i}.match"><option value="contains" ${r.match !== 'word' ? 'selected' : ''}>هر جای متن (قیمت‌ها هم حساب است)</option><option value="word" ${r.match === 'word' ? 'selected' : ''}>فقط کلمه‌ی کامل</option></select>`)}</div></div>
      ${field('فقط در این کانال‌ها (هیچ‌کدام = همه)', icChecks(`prio_rules.${i}.channels`, r.channels, IC_CH.slice(0, 5)))}
    </div></div>`).join('');
  const fu = c.followups.map((r, i) => { const st = fs[r.id]; return `<div class="card flat ic-card"><div class="card-b stack s12">
      <div class="row wrap" style="gap:10px"><input class="inp inp-sm" style="max-width:220px" placeholder="نام، مثلاً پیگیری ۲۳ ساعته" value="${E(r.name || '')}" data-on-input="ic-val" data-k="followups.${i}.name">
        ${st ? `<span class="pill ok" data-tip="ارسال‌های ۷ روز اخیر">${fa(st.ok)} ارسال${st.fail ? ' · ' + fa(st.fail) + ' ناموفق' : ''}</span>` : ''}
        <label class="row small ms-auto">${sw('icfa' + i, !!r.active, '', 'ic-factive')}<span>فعال</span></label>${r.id && r._saved ? `<button class="btn btn-sm" data-act="ic-fprev" data-id="${E(r.id)}">${ic('eye')}الان به چه کسانی می‌رسد؟</button>` : ''}<button class="btn btn-g btn-ic btn-sm" data-act="ic-del" data-k="followups" data-i="${i}" aria-label="حذف">${ic('trash-simple')}</button></div>
      <div class="fg">
        ${field('چند ساعت بعد از آخرین پیام مشتری', `<div class="row" style="gap:6px"><input class="inp ltr" type="number" min="0.25" max="720" step="0.25" style="max-width:110px" value="${E(r.after_hours)}" data-on-input="ic-float" data-k="followups.${i}.after_hours"><button class="btn btn-sm btn-g" data-act="ic-set" data-k="followups.${i}.after_hours" data-v="23">۲۳ ساعت</button><button class="btn btn-sm btn-g" data-act="ic-set" data-k="followups.${i}.after_hours" data-v="2">۲ ساعت</button></div>`, 'اینستاگرام فقط تا ۲۴ ساعت بعد از پیام مشتری اجازه‌ی پیام می‌دهد؛ برای آن ۲۳ ساعت مناسب است')}
        ${field('شرط', `<select class="inp" data-on-change="ic-val" data-k="followups.${i}.who_last"><option value="us" ${r.who_last === 'us' ? 'selected' : ''}>ما جواب داده‌ایم و مشتری دیگر چیزی نگفته</option><option value="customer" ${r.who_last === 'customer' ? 'selected' : ''}>مشتری پیام داده و هنوز جواب نگرفته</option><option value="any" ${r.who_last === 'any' ? 'selected' : ''}>فرقی نمی‌کند</option></select>`)}
      </div>
      ${field('وضعیت گفتگو', icChecks(`followups.${i}.statuses`, r.statuses, [['open', 'باز'], ['pending', 'منتظر مشتری'], ['snoozed', 'تعویق'], ['closed', 'بسته']]))}
      ${field('کانال‌ها (هیچ‌کدام = همه)', icChecks(`followups.${i}.channels`, r.channels, IC_CH.slice(0, 5)))}
      <div class="fg">${field('فقط اگر مشتری یکی از این‌ها را گفته باشد (اختیاری، هر خط یکی)', `<textarea class="inp" rows="2" data-on-input="ic-words" data-k="followups.${i}.keywords" placeholder="قیمت&#10;ارسال">${E((r.keywords || []).join('\n'))}</textarea>`, 'در ۵ پیام آخر مشتری جست‌وجو می‌شود')}
        <div class="stack s12">${field('فقط گفتگوهای دارای برچسب', `<select class="inp" data-on-change="ic-num" data-k="followups.${i}.label">${icTags(r.label)}</select>`)}${field('نه برای گفتگوهای دارای برچسب', `<select class="inp" data-on-change="ic-num" data-k="followups.${i}.skip_label">${icTags(r.skip_label)}</select>`)}</div></div>
      ${field('متن پیام', `<textarea class="inp" rows="3" data-on-input="ic-val" data-k="followups.${i}.text" placeholder="سلام {name}، خواستیم بپرسیم سؤالی باقی مانده؟">${E(r.text || '')}</textarea>`, 'متغیرها: {name} نام، {firstname} نام کوچک، {phone} شماره')}
    </div></div>`; }).join('');
  const cs = c.csat;
  return `${head('صندوق ورودی و پیگیری', 'دسترسی پشتیبان‌ها، اولویت خودکار با کلمات کلیدی، نظرسنجی رضایت بعد از پایان گفتگو و پیام پیگیری خودکار.', `<button class="btn btn-p" data-act="ic-save">${ic('check')}ذخیره همه</button>`)}
  ${sec('دیدن چت‌های همکاران', 'مدیر و سرپرست همیشه همه‌ی گفتگوها و مسئولشان را می‌بینند.', `${seg('icVis', [['own', 'پشتیبان فقط چت‌های خودش و بی‌مسئول‌ها'], ['all', 'پشتیبان همه‌ی چت‌ها را ببیند']], c.visibility, 'ic-vis')}
    <p class="small muted">در حالت اول، چتی که بر عهده‌ی همکار دیگری است در فهرست پشتیبان دیده نمی‌شود و باز هم نمی‌شود. پشتیبان می‌تواند چت‌های بی‌مسئول را «بر عهده بگیرد» (یا با اولین پاسخ، خودکار مسئولش شود).</p>`)}
  ${sec('اولویت خودکار با کلمات کلیدی', 'وقتی پیام مشتری یکی از این کلمه‌ها را داشته باشد، اولویت گفتگو بالا می‌رود (هرگز پایین نمی‌آید) و در صورت انتخاب، برچسب می‌خورد. با بستن گفتگو، اولویتی که قانون داده بود به «عادی» برمی‌گردد. نتیجه در کمتر از یک دقیقه اعمال می‌شود.', `${prio || '<p class="muted small">هنوز قانونی ندارید.</p>'}
    <div class="row wrap" style="gap:8px"><button class="btn btn-sm" data-act="ic-add-prio">${ic('plus')}قانون تازه</button><button class="btn btn-sm btn-g" data-act="ic-prio-sample">${ic('magic-wand')}قانون‌های نمونه (خرید، شکایت)</button></div>
    <div class="row" style="gap:8px"><input class="inp" id="icTest" placeholder="آزمایش: یک پیام نمونه بنویسید، مثلاً «قیمت این مدل چنده؟»"><button class="btn" data-act="ic-test">آزمایش</button></div><div id="icTestOut" class="small"></div>`)}
  ${sec('نظرسنجی رضایت مشتری', 'بعد از بستن گفتگو، از مشتری خواسته می‌شود به پاسخ‌گویی امتیاز ۱ تا ۵ بدهد. امتیاز به نام اپراتور مسئول ثبت می‌شود و در گزارش عملکرد می‌آید. اگر مشتری به‌جای امتیاز سؤال تازه‌ای بپرسد، گفتگو مثل همیشه ادامه پیدا می‌کند.', `
    <label class="row">${sw('icCsOn', !!cs.enabled, '', 'ic-cs')}<span>نظرسنجی روشن باشد</span></label>
    <label class="row">${sw('icCsAuto', !!cs.auto_close, '', 'ic-cs')}<span>با زدن «بستن» خودکار فرستاده شود (فقط اگر اپراتور در ۳ روز اخیر جواب داده باشد)</span></label>
    ${field('متن سؤال', `<textarea class="inp" rows="3" id="icCsQ">${E(cs.question)}</textarea>`, '{agent} = نام اپراتور. در بله، تلگرام و روبیکا دکمه‌های ۱ تا ۵ و در گفتگوی سایت دکمه‌ی سریع اضافه می‌شود؛ در اینستاگرام مشتری عدد را می‌نویسد.')}
    <div class="fg">${field('تشکر (امتیاز ۳ تا ۵)', `<textarea class="inp" rows="2" id="icCsT">${E(cs.thanks)}</textarea>`)}${field('پاسخ به امتیاز ۱ یا ۲', `<textarea class="inp" rows="2" id="icCsTL">${E(cs.thanks_low)}</textarea>`)}</div>
    <label class="row">${sw('icCsLow', !!cs.notify_low, '', 'ic-cs')}<span>امتیاز ۱ یا ۲ به تیم اعلان بدهد</span></label>
    <div class="fg">${field('مهلت پاسخ مشتری (ساعت)', `<input class="inp ltr" type="number" min="1" max="240" id="icCsExp" value="${E(cs.expire_hours)}">`)}${field('کانال‌ها', icChecks('csat.channels', cs.channels, [['telegram', 'تلگرام'], ['bale', 'بله'], ['roubika', 'روبیکا'], ['webchat', 'گفتگوی سایت'], ['instagram', 'اینستاگرام (مستقیم)'], ['instagram_apichi', 'اینستاگرام (یار API)']]))}</div>`)}
  ${sec('پیام پیگیری خودکار', 'وقتی از آخرین پیام مشتری مدتی گذشت و شرط‌ها برقرار بود، این پیام خودکار فرستاده می‌شود. برای هر پیام مشتری حداکثر یک بار. کران باید هر دقیقه اجرا شود.', `${fu || '<p class="muted small">هنوز پیگیری خودکاری ندارید.</p>'}
    <div class="row wrap" style="gap:8px"><button class="btn btn-sm" data-act="ic-add-fu">${ic('plus')}پیگیری تازه</button><button class="btn btn-sm btn-g" data-act="ic-fu-sample">${ic('magic-wand')}نمونه: یادآوری ۲۳ ساعته‌ی اینستاگرام</button></div>`)}
  ${saveBtn('ic-save', 'ذخیره همه')}`;
}
function icSyncCsat() { if (!$('#icCsQ')) return; Object.assign(S.icfg.csat, {enabled:val('icCsOn'), auto_close:val('icCsAuto'), notify_low:val('icCsLow'), question:val('icCsQ'), thanks:val('icCsT'), thanks_low:val('icCsTL'), expire_hours:+toEn(val('icCsExp')) || 48}); }
function icRe() { icSyncCsat(); const b = $('#setBody'); if (!b) return; const y = b.scrollTop; b.innerHTML = `<div class="set-in">${inboxCfg()}</div>`; b.scrollTop = y; }
ON['ic-val'] = el => { icPath(S.icfg, el.dataset.k, el.value); };
ON['ic-num'] = el => { icPath(S.icfg, el.dataset.k, el.value ? +el.value : null); };
ON['ic-float'] = el => { icPath(S.icfg, el.dataset.k, +toEn(el.value) || 0); };
ON['ic-words'] = el => { icPath(S.icfg, el.dataset.k, el.value.split(/\n|,|،/).map(x => x.trim()).filter(Boolean)); };
ON['ic-arr'] = el => { const a = icGet(S.icfg, el.dataset.k) || []; const v = el.value; const n = el.checked ? [...new Set([...a, v])] : a.filter(x => x !== v); icPath(S.icfg, el.dataset.k, n); };
ON['ic-active'] = el => { S.icfg.prio_rules[+el.id.slice(4)].active = el.checked; };
ON['ic-factive'] = el => { S.icfg.followups[+el.id.slice(4)].active = el.checked; };
ON['ic-cs'] = () => {};
A['ic-vis'] = el => { A['seg'](el); S.icfg.visibility = el.dataset.v; };
A['ic-set'] = el => { icPath(S.icfg, el.dataset.k, +el.dataset.v); icRe(); };
A['ic-del'] = el => { S.icfg[el.dataset.k].splice(+el.dataset.i, 1); icRe(); };
A['ic-add-prio'] = () => { S.icfg.prio_rules.push({name:'', words:[], match:'contains', priority:'high', label:null, channels:[], active:true}); icRe(); };
A['ic-prio-sample'] = () => { S.icfg.prio_rules.push({name:'خرید', words:['قیمت', 'خرید', 'سفارش', 'موجود', 'هزینه', 'تخفیف'], match:'contains', priority:'high', label:null, channels:[], active:true}, {name:'شکایت', words:['شکایت', 'مرجوع', 'خراب', 'پس بدم', 'ناراضی'], match:'contains', priority:'urgent', label:null, channels:[], active:true}); icRe(); };
A['ic-add-fu'] = () => { S.icfg.followups.push({name:'', active:false, after_hours:23, who_last:'us', statuses:['open', 'pending'], channels:[], keywords:[], label:null, skip_label:null, text:''}); icRe(); };
A['ic-fu-sample'] = () => { S.icfg.followups.push({name:'یادآوری ۲۳ ساعته اینستاگرام', active:false, after_hours:23, who_last:'us', statuses:['open', 'pending'], channels:['instagram'], keywords:[], label:null, skip_label:null, text:'سلام {firstname} 🌿 خواستیم بپرسیم سؤالی باقی مانده که بتوانیم کمک کنیم؟'}); icRe(); };
A['ic-test'] = async () => { const r = await L().api('desk_prio_test', {text:val('icTest'), rules:S.icfg.prio_rules}); const o = $('#icTestOut'); if (!r.ok) return toast(r.error, 'err');
  o.innerHTML = r.hit ? `${ic('check-circle')} قانون <b>${E(r.hit.name || '-')}</b> با کلمه‌ی «${E(r.hit.hit)}» جور شد؛ اولویت <b>${(IC_PRI.find(p => p[0] === r.hit.priority) || [])[1]}</b>` : '<span class="muted">هیچ قانونی جور نشد</span>'; };
A['ic-fprev'] = async el => { const r = await L().api('desk_followup_preview', {id:el.dataset.id}); if (!r.ok) return toast(r.error, 'err');
  const L2 = modal({title:'همین الان به چه کسانی می‌رسد', size:'sm', body:r.count ? `<p class="small muted">${fa(r.count)} گفتگو در اجرای بعدی کران پیام می‌گیرند (اگر قانون فعال باشد):</p>${r.items.map(x => `<div class="li"><div class="grow"><b>${E(x.name || 'مشتری ' + x.person_id)}</b><div class="xs muted">${E(x.channel)} · آخرین پیام: ${E(L().fullDT(x.last_in_at))}</div></div></div>`).join('')}` : '<p class="muted">الان هیچ گفتگویی شرایط این قانون را ندارد.</p>', foot:'<button class="btn" data-close>بستن</button>'}); };
A['ic-save'] = async el => {
  const c = S.icfg;
  c.csat = Object.assign(c.csat, {enabled:val('icCsOn'), auto_close:val('icCsAuto'), notify_low:val('icCsLow'), question:val('icCsQ'), thanks:val('icCsT'), thanks_low:val('icCsTL'), expire_hours:+toEn(val('icCsExp')) || 48});
  const bad = c.followups.find(f => f.active && !String(f.text || '').trim()); if (bad) return toast('متن پیام پیگیری «' + (bad.name || 'بی‌نام') + '» خالی است', 'err');
  const r = await run(el, () => L().api('desk_inbox_cfg_save', {visibility:c.visibility, prio_rules:c.prio_rules, csat:c.csat, followups:c.followups}));
  if (!r.ok) return toast(r.error, 'err');
  c.prio_rules = r.prio_rules; c.csat = r.csat; c.followups = r.followups.map(f => Object.assign(f, {_saved:true}));
  D.csat = {enabled:!!r.csat.enabled, auto_close:!!r.csat.auto_close};
  toast('تنظیمات صندوق ورودی ذخیره شد'); icRe();
};

/* دکمه‌ی در حال انجام برای درخواست واقعی */
async function run(btn, fn) {
  if (btn) { btn.classList.add('loading'); btn.disabled = true; }
  try { return await fn(); }
  finally { if (btn && btn.isConnected) { btn.classList.remove('loading'); btn.disabled = false; } }
}

/* هر بخش: داده‌ی لازم + تابع نمایش */
const LOADERS = {
  crm: async () => { if (EB.crmSet) await EB.crmSet.load(); },
  me: async () => { const r = await L().api('my_profile'); S.myProfile = r.ok ? r : null; },
  brand: async () => { const r = await L().api('settings_get'); if (!r.ok) throw new Error(r.error); S.settings = r; },
  operators: async () => {
    const [o, b, rl] = await Promise.all([L().api('operators_list'), L().api('desk_boot'), L().api('roles_list')]);
    if (!o.ok) throw new Error(o.error);
    S.operators = o.operators; S.roles = rl.ok ? rl.roles : [];
    if (b.ok) { D.ops = b.users.map(L().mapUser); D.teams = b.teams.map(L().mapTeam); D.groups = b.groups.map(L().mapGroup); }
  },
  roles: async () => { const r = await L().api('roles_list'); if (!r.ok) throw new Error(r.error); S.roles = r.roles; },
  sites: async () => { const r = await L().api('sites_list'); if (!r.ok) throw new Error(r.error); S.sites = r.sites; S.siteCur = r.current; },
  inbox: async () => { const r = await L().api('desk_inbox_cfg'); if (!r.ok) throw new Error(r.error); S.icfg = JSON.parse(JSON.stringify({visibility:r.visibility, prio_rules:r.prio_rules, csat:r.csat, followups:r.followups.map(f => Object.assign(f, {_saved:true}))})); S.icfgStats = r.followup_stats || {}; },
  routing: async () => { const r = await L().api('support_status'); S.support = r.ok ? r.support : {}; },
  system: async () => { const s = await L().api('settings_get'); if (!s.ok) throw new Error(s.error); S.settings = s; },
  proxy: async () => { const r = await L().api('v4_proxy_get'); if (!r.ok) throw new Error(r.error); S.px = r; S.pxList = JSON.parse(JSON.stringify(r.proxies)); S.pxRoutes = JSON.parse(JSON.stringify(r.routes || {})); },
  
};
const VIEWS = {appearance, me, brand, channels, operators, roles, sites, routing, inbox:inboxCfg, system, proxy,
  crm: () => `<div id="crmSetBox">${EB.crmSet ? EB.crmSet.html() : ''}</div>`,
  crmset: () => `${head('تلفنچی، سایت و CRM', 'اتصال تلفنچی، دیتابیس سایت جاکجاست، تبدیل صدا به متن، داخلی‌ها، مراجع سرنخ و تنظیمات مالی CRM.')}<div class="card" style="overflow:hidden;height:calc(100vh - 210px);min-height:520px;margin-top:12px"><div id="crmFrame" style="height:100%"></div></div>`,
};

async function body() {
  const seq = ++renderSeq; const box = $('#setBody'); if (!box) return;
  if (LOADERS[cur]) {
    box.innerHTML = `<div class="set-in"><div class="sk" style="height:28px;width:240px;margin-bottom:18px"></div><div class="card"><div class="card-b">${EB.sk(5)}</div></div></div>`;
    try { await LOADERS[cur](); }
    catch (e) {
      if (seq !== renderSeq) return;
      box.innerHTML = `<div class="set-in">${empty('warning-circle', 'بارگذاری این بخش ناموفق بود', E(e.message || 'خطای ناشناخته'), `<button class="btn" data-act="set-retry">${ic('arrow-clockwise')}تلاش دوباره</button>`)}</div>`;
      return;
    }
    if (seq !== renderSeq) return;
  }
  box.innerHTML = `<div class="set-in">${VIEWS[cur]()}</div>`; box.scrollTop = 0;
  if (cur === 'channels') EB.legacyFrame($('#chFrame'), 'channels');
  if (cur === 'crmset') EB.legacyFrame($('#crmFrame'), 'crmset', 'crm/index.php?page=settings&embed=1');
}
A['set-retry'] = () => body();
function rerender() { const box = $('#setBody'); if (!box) return; const top = box.scrollTop; box.innerHTML = `<div class="set-in">${VIEWS[cur]()}</div>`; box.scrollTop = top; }

/* ================= ظاهر و تم (شخصی، روی سرور همگام می‌شود) ================= */
function appearance() {
  const isDark = P.theme === 'dark' || (P.theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
  const mini = (bg, side, acc, line) => `<div class="prev" style="grid-template-columns:1fr 26%;background:${bg}"><div style="padding:8px;display:flex;flex-direction:column;gap:5px"><i style="height:7px;width:60%;border-radius:4px;background:${line}"></i><i style="height:7px;width:85%;border-radius:4px;background:${line}"></i><i style="height:14px;width:40%;border-radius:4px;background:${acc};margin-top:auto"></i></div><div style="background:${side};border-right:1px solid ${line}"></div></div>`;
  const half = (bg, side, acc, line) => mini(bg, side, acc, line).replace('class="prev"', `style="display:grid;grid-template-columns:1fr 26%;background:${bg}"`);
  return `${head('ظاهر و تم', 'تغییرات همین الان روی پنل اعمال می‌شوند و برای حساب شما روی سرور ذخیره می‌شوند؛ از هر مرورگری وارد شوید همین ظاهر را می‌بینید.', `<button class="btn" data-act="ap-reset">${ic('arrow-counter-clockwise')}بازگشت به پیش‌فرض</button>`)}
  ${sec('حالت نمایش', 'روشن، تاریک یا هماهنگ با سیستم‌عامل', `<div class="opt-cards">${[['light', 'روشن', mini('#F2F0EA', 'var(--brand-side)', 'var(--a-l)', '#E2DED3')], ['dark', 'تاریک', mini('#0B1311', '#0A1A17', 'var(--a-d)', '#22322D')], ['system', 'خودکار', `<div class="prev" style="grid-template-columns:1fr 1fr">${half('#F2F0EA', 'var(--brand-side)', 'var(--a-l)', '#E2DED3')}${half('#0B1311', '#0A1A17', 'var(--a-d)', '#22322D')}</div>`]].map(o => `<button class="opt ${P.theme === o[0] ? 'on' : ''}" data-act="ap" data-k="theme" data-v="${o[0]}">${o[2]}<b>${o[1]}</b></button>`).join('')}</div>`)}
  ${sec('تم رنگی', 'رنگ اصلی، رنگ نوار کناری و رنگ جزئیات امضایی با هم تنظیم می‌شوند. برای حالت روشن و تاریک جدا طراحی شده‌اند.', `<div class="opt-cards" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr))">${Object.entries(ACCENTS).map(([k, a]) => `<button class="opt theme-opt ${P.accent === k ? 'on' : ''}" data-act="ap" data-k="accent" data-v="${k}"><div class="prev" style="grid-template-columns:1fr 30%;background:${isDark ? '#0E1513' : '#F2F0EA'}"><div style="padding:9px;display:flex;flex-direction:column;gap:6px"><i style="height:6px;width:70%;border-radius:3px;background:${isDark ? '#23312C' : '#E2DED3'}"></i><i style="height:6px;width:45%;border-radius:3px;background:${isDark ? '#23312C' : '#E2DED3'}"></i><i style="height:14px;width:46%;border-radius:4px;background:${isDark ? a.d[0] : a.l[0]};margin-top:auto"></i></div><div style="background:${a.side};position:relative"><i style="position:absolute;top:10px;right:7px;width:14px;height:14px;border-radius:4px;background:${a.g[1]}"></i></div></div><b>${a.name}</b></button>`).join('')}
    <label class="opt ${P.accent === 'custom' ? 'on' : ''}" style="cursor:pointer"><div class="prev" style="place-items:center;background:conic-gradient(from 90deg,#1D5C4D,#22407A,#5A2D5C,#B9741A,#1D5C4D)">${ic('eyedropper')}</div><b>رنگ دلخواه برند</b><input type="color" class="sr" value="${E(P.custom || '#1D5C4D')}" data-on-input="ap-custom"></label></div>
    <div class="row small muted">${ic('info')}<span>تم فعلی: <b style="color:var(--accent-ink)">${P.accent === 'custom' ? 'رنگ دلخواه ' + E(P.custom) : (ACCENTS[P.accent] || {}).name || ''}</b>. کنتراست متن دکمه‌ها خودکار بررسی می‌شود.</span></div>`)}
  ${sec('فونت متن', 'فونت منوها، جدول‌ها و پیام‌ها. وزیرمتن و مرکزی روی سرور خودتان میزبانی می‌شوند (پوشه‌ی assets/fonts).', `<div class="opt-cards" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr))">${Object.entries(FONTS).map(([k, f]) => `<button class="opt ${P.font === k ? 'on' : ''}" data-act="ap" data-k="font" data-v="${k}"><span class="font-prev" style="font-family:${f.fam},Tahoma;font-size:19px">پاسخ به مشتری</span><b>${f.name}</b></button>`).join('')}</div>
    <div class="field" style="max-width:420px"><label>اندازه‌ی متن پایه: <span id="fsV">${fa(P.fs)}</span> پیکسل</label><input type="range" min="13" max="16" step="1" value="${P.fs}" data-on-input="ap-fs" aria-label="اندازه‌ی متن"></div>`)}
  ${sec('فونت تیتر و عدد', 'برای عنوان صفحه‌ها، نام برند و اعداد بزرگ داشبورد', `<div class="opt-cards" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr))">${Object.entries(DFONTS).map(([k, f]) => `<button class="opt ${P.dfont === k ? 'on' : ''}" data-act="ap" data-k="dfont" data-v="${k}"><span class="font-prev" style="font-family:${f.fam || 'var(--font)'},Tahoma;font-size:${Math.round(22 * f.big)}px;font-weight:700">۱۴۰۵ گزارش</span><b>${f.name}</b></button>`).join('')}</div>`)}
  ${sec('گردی گوشه‌ها', 'یک سیستم ثابت برای همه‌ی دکمه‌ها، کارت‌ها و پنجره‌ها', `<div class="opt-cards">${Object.entries(RADII).map(([k, r]) => `<button class="opt ${P.radius === k ? 'on' : ''}" data-act="ap" data-k="radius" data-v="${k}"><div class="prev" style="place-items:center;background:var(--surface-2)"><i style="width:70%;height:34px;border:2px solid var(--accent);border-radius:${r[1]}px;display:block"></i></div><b>${r[2]}</b><span>${fa(r[0])} / ${fa(r[1])} پیکسل</span></button>`).join('')}</div>`)}
  ${sec('تراکم', 'فاصله‌ها و ارتفاع ردیف‌ها؛ «فشرده» برای نمایشگرهای کوچک و صف‌های شلوغ', seg('apDen', Object.entries(DENS).map(([k, d]) => [k, d[4]]), P.density, 'ap-den'))}
  ${sec('منوی کناری', 'رنگ نوار کناری و حالت جمع‌شده', `<div class="opt-cards">${[['brand', 'رنگ برند با نقش گره', 'var(--brand-side)'], ['tint', 'رنگی ملایم', 'var(--accent-soft)'], ['light', 'هم‌رنگ سطح', 'var(--surface)']].map(o => `<button class="opt ${P.side === o[0] ? 'on' : ''}" data-act="ap" data-k="side" data-v="${o[0]}"><div class="prev" style="grid-template-columns:1fr 32%;background:var(--bg)"><div></div><div style="background:${o[2]};border-right:1px solid var(--line)"></div></div><b>${o[1]}</b></button>`).join('')}</div>
    <label class="row">${sw('apCol', P.collapsed, '', 'ap-collapse')}<span>منو جمع‌شده باشد (فقط آیکون)</span></label>`)}
  ${sec('سبک سطوح', 'کارت‌ها با خط مرزی یا با سایه‌ی ملایم', seg('apSurf', [['raised', 'سایه‌ی ملایم'], ['flat', 'مرزدار']], P.surface, 'ap-surf'))}
  ${sec('حرکت', 'انیمیشن‌های باز و بسته شدن پنجره‌ها و منوها', `<label class="row">${sw('apMot', P.motion, '', 'ap-motion')}<span>انیمیشن‌های رابط فعال باشد</span></label><span class="small muted">اگر در سیستم‌عامل «کاهش حرکت» روشن باشد، انیمیشن‌ها خودکار حذف می‌شوند.</span>`)}
  ${isAdmin() ? sec('پیش‌فرض تیم', 'ظاهر فعلی شما برای اپراتورهایی که هنوز ظاهر شخصی انتخاب نکرده‌اند', `<div class="row wrap"><button class="btn" data-act="ap-team">${ic('users-three')}ظاهر من پیش‌فرض تیم شود</button></div>`) : ''}
  ${sec('پیش‌نمایش', 'نمونه‌ای از اجزای پنل با تنظیمات فعلی', `<div class="card"><div class="card-h"><h3>گفتگوی نمونه</h3>${pill('باز', 'info')}<div class="acts"><button class="btn btn-sm" type="button">${ic('user-switch')}سپردن</button><button class="btn btn-p btn-sm" type="button">${ic('check')}بستن</button></div></div><div class="card-b stack"><div class="row">${av('مریم کریمی', {ch:'instagram'})}<div class="grow"><b>مریم کریمی</b><div class="xs muted">سایز ۳۸ مشکی موجوده؟</div></div><span class="sla warn">${ic('timer')}۸ د</span></div><div class="row wrap">${pill('مشتری وفادار', 'acc')}${pill('VIP', 'warn', 'crown-simple')}</div><div class="row"><input class="inp" placeholder="پاسخ..." aria-label="پاسخ نمونه"><button class="btn btn-p" type="button">ارسال</button></div></div></div>`)}`;
}
A['ap'] = el => { setPref(el.dataset.k, el.dataset.v); rerender(); };
ON['ap-custom'] = EB.debounce(el => { P.custom = el.value; setPref('accent', 'custom'); rerender(); }, 120);
ON['ap-fs'] = el => { setPref('fs', +el.value); $('#fsV').textContent = fa(el.value); };
UI.on('seg:apDen', v => setPref('density', v)); UI.on('seg:apSurf', v => setPref('surface', v));
A['ap-den'] = el => A['seg'](el); A['ap-surf'] = el => A['seg'](el);
ON['ap-collapse'] = el => { setPref('collapsed', el.checked); reshell(); };
ON['ap-motion'] = el => setPref('motion', el.checked);
A['ap-reset'] = () => { Object.keys(DEF_PREFS).forEach(k => P[k] = DEF_PREFS[k]); setPref('theme', 'system'); reshell(); toast('ظاهر به پیش‌فرض برگشت'); };
A['ap-team'] = async el => { const r = await run(el, () => L().api('desk_settings_save', {default_prefs:P})); r.ok ? toast('ظاهر فعلی پیش‌فرض تیم شد') : toast(r.error, 'err'); };

/* ================= پروفایل من ================= */
function me() {
  const u = D.ops.find(o => o.id === D.meId) || {name:'', title:'', color:'#1D5C4D'};
  const pr = S.myProfile || {};
  return `${head('پروفایل و رمز', 'نامی که همکاران در صندوق ورودی و یادداشت‌ها می‌بینند، و امنیت حساب شما.')}
  ${sec('اطلاعات نمایشی', 'در فهرست اپراتورها، سپردن گفتگو و منشن‌ها', `<div class="row" style="gap:14px">${av(u.name || 'کاربر', {color:u.color, size:'lg'})}<div class="small muted">رنگ آواتار<br>${colorInp('meColor', u.color)}</div><div class="small muted">تصویر پروفایل<br><label class="btn btn-sm" style="cursor:pointer;margin-top:4px">${ic('upload-simple')}آپلود عکس<input type="file" class="sr" accept="image/png,image/jpeg,image/webp" data-on-change="me-avatar"></label>${u.avatar ? ` <button class="btn btn-sm btn-g" data-act="me-avatar-del">حذف</button>` : ''}</div></div>
    <p class="xs muted" style="margin:6px 0 0">عکس پروفایل در صندوق ورودی و برای مشتری در گفتگوی سایت (کنار پیام‌های شما) نشان داده می‌شود.</p>
    <div class="fg">${field('نام نمایشی', `<input class="inp" id="meName" value="${E(u.name || '')}" maxlength="100">`)}${field('عنوان شغلی', `<input class="inp" id="meTitle" value="${E(u.title || '')}" maxlength="100" placeholder="مثلاً کارشناس فروش">`)}</div>${saveBtn('me-save')}`)}
  ${sec('نام کاربری', 'برای ورود به پنل', `<div class="fg">${field('نام کاربری', `<input class="inp ltr" id="meUser" value="${E(pr.username || u.username || '')}" autocomplete="username">`)}</div>${saveBtn('me-user', 'تغییر نام کاربری')}`)}
  ${sec('تغییر رمز', 'پس از تغییر، نشست فعلی باز می‌ماند', `<div class="fg">${field('رمز فعلی', '<input class="inp ltr" type="password" id="meCur" autocomplete="current-password">')}${field('رمز جدید', '<input class="inp ltr" type="password" id="meNew" autocomplete="new-password">', 'حداقل ۸ کاراکتر؛ ترکیب حرف و عدد پیشنهاد می‌شود')}${field('تکرار رمز جدید', '<input class="inp ltr" type="password" id="meNew2" autocomplete="new-password">')}</div>${saveBtn('me-pass', 'تغییر رمز')}`)}`;
}
ON['me-avatar'] = async el => {
  const f = el.files && el.files[0]; if (!f) return;
  const fd = new FormData(); fd.append('image', f);
  const r = await L().api('desk_avatar_upload', fd, true);
  if (!r.ok) return toast(r.error, 'err');
  const u = D.ops.find(o => o.id === D.meId); if (u) u.avatar = r.avatar;
  reshell(); toast('عکس پروفایل ذخیره شد');
};
A['me-avatar-del'] = async () => { const r = await L().api('desk_user_profile', Object.assign({remove_avatar:1}, (() => { const u = D.ops.find(o => o.id === D.meId) || {}; return {display_name:u.name, title:u.title, color:u.color}; })())); if (!r.ok) return toast(r.error, 'err'); const u = D.ops.find(o => o.id === D.meId); if (u) u.avatar = ''; reshell(); };
A['me-save'] = async el => {
  const r = await run(el, () => L().api('desk_user_profile', {display_name:val('meName'), title:val('meTitle'), color:val('meColor')}));
  if (!r.ok) return toast(r.error, 'err');
  const u = D.ops.find(o => o.id === D.meId); if (u) { u.name = val('meName') || u.username; u.title = val('meTitle'); u.color = val('meColor'); }
  reshell(); toast('پروفایل ذخیره شد');
};
A['me-user'] = async el => { const u = val('meUser'); if (!u) return toast('نام کاربری را وارد کنید', 'err'); const r = await run(el, () => L().api('my_username_change', {username:u})); r.ok ? toast('نام کاربری تغییر کرد') : toast(r.error, 'err'); };
A['me-pass'] = async el => {
  const n = val('meNew'); if (n.length < 8) return toast('رمز جدید حداقل ۸ کاراکتر باشد', 'err');
  if (n !== val('meNew2')) return toast('تکرار رمز با رمز جدید یکی نیست', 'err');
  const r = await run(el, () => L().api('my_password_change', {current:val('meCur'), new:n}));
  if (!r.ok) return toast(r.error, 'err');
  ['meCur', 'meNew', 'meNew2'].forEach(id => { $('#' + id).value = ''; }); toast('رمز عبور تغییر کرد');
};
reg('profile', {title:'پروفایل من', icon:'user-circle', hidden:true, render() { go('settings.me'); }});

/* ================= برند و شرکت ================= */
function brand() {
  const b = (S.settings && S.settings.brand) || D.brand || {};
  return `${head('برند و شرکت', 'نام و لوگوی پنل برای همه‌ی اپراتورها، صفحه‌ی ورود و عنوان مرورگر.')}
  ${sec('هویت پنل', 'در نوار کناری، عنوان مرورگر و صفحه‌ی ورود نمایش داده می‌شود', `<div class="fg">${field('نام نمایشی پنل', `<input class="inp" id="brName" maxlength="60" value="${E(b.name || '')}">`)}${field('زیرعنوان', `<input class="inp" id="brTag" maxlength="120" value="${E(b.tagline || '')}">`)}</div>${saveBtn('br-save')}`)}
  ${sec('لوگو', 'SVG یا PNG مربعی، حداقل ۱۲۸ پیکسل. بدون لوگو، نشان پیش‌فرض نمایش داده می‌شود.', `<div class="row" style="gap:16px">${b.logo ? `<img src="${E(b.logo)}" alt="لوگوی فعلی" style="width:64px;height:64px;border-radius:16px;object-fit:contain;background:var(--surface-2);border:1px solid var(--line)">` : `<span class="logo" style="width:64px;height:64px;border-radius:16px">${ic('broadcast')}</span>`}<div class="stack s6"><label class="btn btn-sm" style="cursor:pointer">${ic('upload-simple')}آپلود لوگو<input type="file" class="sr" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" data-on-change="br-logo"></label>${b.logo ? `<button class="btn btn-sm btn-g" data-act="br-logo-del">حذف لوگو</button>` : ''}</div></div>`)}
  ${sec('نام کسب‌وکار', 'در قالب پیام‌ها و گزارش‌ها استفاده می‌شود', `<div class="fg">${field('نام تجاری', `<input class="inp" id="coName" value="${E((S.settings && S.settings.company) || '')}">`)}</div>${saveBtn('co-save')}`)}`;
}
function applyBrand(b) { D.brand = b; D.company.name = b.name || 'اعلان‌بان'; if (S.settings) S.settings.brand = b; document.title = D.company.name; reshell(); }
A['br-save'] = async el => { const r = await run(el, () => L().api('brand_save', {name:val('brName'), tagline:val('brTag')})); if (!r.ok) return toast(r.error, 'err'); applyBrand(r.brand); toast('برند ذخیره شد'); };
ON['br-logo'] = async el => {
  const f = el.files && el.files[0]; if (!f) return;
  if (f.size > 2 * 1024 * 1024) return toast('حجم لوگو بیشتر از ۲ مگابایت است', 'err');
  const fd = new FormData(); fd.append('image', f);
  const r = await L().api('brand_logo_upload', fd, true);
  if (!r.ok) return toast(r.error, 'err');
  applyBrand(Object.assign({}, D.brand, {logo:r.logo})); rerender(); toast('لوگو به‌روز شد');
};
A['br-logo-del'] = async el => { const r = await run(el, () => L().api('brand_save', {logo:''})); if (!r.ok) return toast(r.error, 'err'); applyBrand(r.brand); rerender(); toast('لوگو حذف شد'); };
A['co-save'] = async el => { const r = await run(el, () => L().api('settings_save', {company:val('coName')})); if (!r.ok) return toast(r.error, 'err'); if (S.settings) S.settings.company = val('coName'); toast('نام کسب‌وکار ذخیره شد'); };

/* ================= کانال‌ها (صفحه‌ی کامل تنظیم کانال، جاسازی‌شده) ================= */
function channels() {
  const on = D.channelsOn || {};
  const names = {instagram:'اینستاگرام', telegram:'تلگرام', bale:'بله', roubika:'روبیکا', safir:'سفیر بله', sms:'پیامک', email:'ایمیل', webchat:'گفتگوی سایت'};
  return `${head('اتصال کانال‌ها', 'توکن ربات‌ها، سرویس پیامک، ایمیل، سفیر و اینستاگرام. هر کانالی که وصل شود، پیام‌هایش در صندوق ورودی جمع می‌شود.')}
  <div class="row wrap" style="gap:6px;margin:10px 0 14px">${Object.keys(names).map(k => pill(names[k], on[k] ? 'ok' : '', on[k] ? 'check-circle' : 'plugs')).join('')}</div>
  <div class="card" style="overflow:hidden;height:calc(100vh - 230px);min-height:520px"><div id="chFrame" style="height:100%"></div></div>`;
}

/* ================= اپراتورها و دسترسی ================= */
const PERMS = [
  ['گفتگو', [['dashboard', 'داشبورد'], ['inbox', 'صندوق ورودی'], ['instagram', 'اینستاگرام'], ['webchat', 'گفتگوی سایت'], ['support', 'پشتیبانی تیمی']]],
  ['ارسال', [['send', 'ارسال پیام'], ['safir', 'ارسال با شماره'], ['campaigns', 'کمپین'], ['scheduled', 'زمان‌بندی'], ['queue', 'صف ارسال'], ['sendlog', 'تاریخچه‌ی ارسال']]],
  ['مشتریان', [['persons', 'بانک مشتری'], ['groups', 'گروه و برچسب']]],
  ['اتوماسیون', [['botbuilder', 'بات‌ساز منو'], ['autoreply', 'پاسخ خودکار'], ['forms', 'فرم و نظرسنجی'], ['callbacks', 'درخواست تماس‌های دستیار فروش'], ['ai', 'هوش مصنوعی']]],
  ...((window.EB_BOOT || {}).crm && EB_BOOT.crm.ready ? [['CRM — فروش و تماس', [['crm_calls', 'تماس‌ها و پیگیری شماره'], ['crm_leads', 'سرنخ‌ها و استعلام‌ها'], ['crm_customers', 'مشتریان CRM'], ['crm_hosts', 'میزبانان'], ['crm_host_leads', 'جذب میزبان']]],
    ['CRM — تیم و گزارش', [['crm_tickets', 'تیکت‌ها'], ['crm_tasks', 'تسک‌ها و پروژه‌ها'], ['crm_chat', 'چت داخلی'], ['crm_staff', 'پرسنل'], ['crm_leaves', 'مرخصی‌ها'], ['crm_payroll', 'حقوق و دستمزد'], ['crm_reports', 'گزارش‌ها، داشبورد اجرایی و فروش سایت']]]] : []),
  ['محتوا و تحلیل', [['templates', 'الگوها'], ['links', 'لینک رهگیری'], ['notices', 'اعلان سایت'], ['stats', 'آمار'], ['analytics', 'آنالیتیکس'], ['marketing', 'مارکتینگ'], ['apilog', 'لاگ سیستم']]],
];
const PRESETS = [
  ['support', 'پشتیبان', ['dashboard', 'inbox', 'instagram', 'webchat', 'support', 'persons', 'templates', 'callbacks', 'crm_calls', 'crm_leads', 'crm_customers', 'crm_tickets', 'crm_tasks', 'crm_chat']],
  ['sales', 'فروش و بازاریابی', ['dashboard', 'inbox', 'instagram', 'send', 'safir', 'campaigns', 'scheduled', 'sendlog', 'persons', 'groups', 'templates', 'links', 'stats', 'marketing', 'crm_calls', 'crm_leads', 'crm_customers', 'crm_tasks', 'crm_chat']],
  ['lead', 'سرپرست', PERMS.flatMap(g => g[1].map(p => p[0])).filter(p => p !== 'apilog')],
];
const permsOf = row => { try { const p = JSON.parse(row.permissions_json || '{}') || {}; return {pages:p.pages || [], groups:(p.groups || []).map(Number), del:!!p.can_delete_customers, mask:p.mask_pii !== false, role:row.role, role_id:+row.role_id || 0}; } catch (e) { return {pages:[], groups:[], del:false, mask:true, role:row.role, role_id:+row.role_id || 0}; } };
function operators() {
  const rows = S.operators || [];
  return `${head('اپراتورها و دسترسی', `${fa(rows.length)} حساب، بدون محدودیت تعداد. هر اپراتور فقط بخش‌ها و گروه‌های مشتری مجاز را می‌بیند.`, `<button class="btn btn-p" data-act="op-new">${ic('user-plus')}اپراتور جدید</button>`)}
  <div class="card" style="margin-top:16px">${rows.length ? `<div class="tbl-wrap"><table class="tbl resp"><thead><tr><th>اپراتور</th><th>نقش</th><th>تیم</th><th>دسترسی</th><th>وضعیت</th><th></th></tr></thead><tbody>
  ${rows.map(r => { const u = D.ops.find(o => o.rid === +r.id) || {name:r.username, color:'#1D5C4D', status:'offline'}; const p = permsOf(r); const team = D.teams.find(t => t.id === u.team);
    return `<tr><td><div class="row">${av(u.name, {color:u.color, st:u.status})}<div style="min-width:0"><b>${E(u.name)}</b><div class="xs muted ltr" style="text-align:right">${E(r.username)}</div></div></div></td>
    <td>${r.role === 'admin' ? pill('مدیر', 'acc', 'crown-simple') : r.role === 'supervisor' ? pill('سرپرست', 'info', 'shield-check') : r.role === 'viewer' ? pill('نمایشی', 'warn', 'eye') : (+r.role_id && roleById(+r.role_id) ? pill(E(roleById(+r.role_id).name), 'info', 'tree-structure') : pill(E(u.title || 'اپراتور'))) }</td>
    <td class="small">${team ? `<span class="row" style="gap:6px"><i class="dot" style="background:${E(team.color)}"></i>${E(team.name)}</span>` : '<span class="muted">بدون تیم</span>'}</td>
    <td class="small">${r.role === 'admin' ? 'همه‌ی بخش‌ها' : r.role === 'viewer' ? 'فقط مشاهده‌ی همه‌ی بخش‌ها' : (p.pages.length ? fa(p.pages.length) + ' بخش' : 'همه‌ی بخش‌ها') + (p.groups.length ? `، ${fa(p.groups.length)} گروه مشتری` : '')}</td>
    <td>${+r.is_active ? pill('فعال', 'ok') : pill('غیرفعال', 'warn')}</td>
    <td class="w1"><button class="btn btn-sm" data-act="op-edit" data-id="${+r.id}">${r.role === 'admin' ? 'پروفایل' : 'ویرایش'}</button></td></tr>`; }).join('')}
  </tbody></table></div>` : empty('user-gear', 'هنوز اپراتوری نساخته‌اید', 'برای هر همکار یک حساب بسازید تا گفتگوها بین تیم پخش شود.', `<button class="btn btn-p" data-act="op-new">${ic('user-plus')}اپراتور جدید</button>`)}</div>`;
}
const roleById = id => (S.roles || []).find(r => +r.id === +id);
/* درخت نقش‌ها به‌صورت فهرست مرتب با عمق */
function roleTree(skip) {
  const by = {}; (S.roles || []).forEach(r => { (by[r.parent_id || 0] = by[r.parent_id || 0] || []).push(r); });
  const out = []; const walk = (pid, d) => (by[pid] || []).forEach(r => { if (skip && +r.id === +skip) return; out.push(Object.assign({depth:d}, r)); walk(r.id, d + 1); });
  walk(0, 0); return out;
}
const roleOpts = (cur, skip) => roleTree(skip).map(r => `<option value="${r.id}" ${+cur === +r.id ? 'selected' : ''}>${'— '.repeat(r.depth)}${E(r.name)}</option>`).join('');
function permForm(p) {
  const restricted = p.pages.length > 0;
  const viewer = p.role === 'viewer';
  const hasRoles = (S.roles || []).length > 0;
  return `${hasRoles ? `<div class="field"><label>نقش در چارت سازمانی</label><select class="inp" id="opRoleId" onchange="if(this.value){const s=document.querySelector('.seg[data-name=opScope] [data-v=some]');s&&s.click();}"><option value="">بدون نقش (فقط دسترسی‌های دستی پایین)</option>${roleOpts(p.role_id)}</select><span class="hint">دسترسی نقش (و همه‌ی زیرمجموعه‌هایش) با بخش‌های انتخابیِ پایین جمع می‌شود. وقتی نقش دارید، «همه‌ی بخش‌ها» معنی ندارد؛ بخش‌های اضافه را پایین تیک بزنید.</span></div>` : ''}<div class="field"><label>نقش</label>${seg('opRole', [['operator', 'پشتیبان'], ['supervisor', 'سرپرست'], ['viewer', 'نمایشی (فقط مشاهده)']], viewer ? 'viewer' : p.role === 'supervisor' ? 'supervisor' : 'operator', 'op-role')}<span class="hint">سرپرست همه‌ی مشتریان را می‌بیند، بدون «بر عهده گرفتن» به هر چتی پاسخ می‌دهد و چت‌ها را بین همکاران جابه‌جا می‌کند. حساب نمایشی برای نشان دادن پنل به مشتری‌های بالقوه است.</span></div>
  <div id="opViewer" ${viewer ? '' : 'hidden'} class="stack s12"><div class="callout">${ic('eye')}<span>این حساب همه‌ی بخش‌ها، گفتگوها، گزارش‌ها و تنظیمات را مثل مدیر <b>می‌بیند</b>، ولی هیچ پیامی نمی‌فرستد و چیزی را ذخیره، حذف یا تغییر نمی‌دهد. کلیدها و توکن‌ها هرگز نمایش داده نمی‌شوند و این حساب در فهرست همکاران و تخصیص چت‌ها نمی‌آید.</span></div>
    <label class="row">${sw('opMask', p.mask !== false)}<span>پوشاندن بخشی از شماره‌ی موبایل و ایمیل مشتریان (پیشنهادی)</span></label></div>
  <div id="opStd" ${viewer ? 'hidden' : ''} class="stack s20"><div class="field"><label>دسترسی به بخش‌ها</label>${seg('opScope', [['all', 'همه‌ی بخش‌ها'], ['some', 'فقط بخش‌های انتخابی']], restricted ? 'some' : 'all', 'op-scope')}</div>
  <div id="opPages" ${restricted ? '' : 'hidden'}><div class="row wrap" style="margin-bottom:10px"><span class="xs muted">الگوی آماده:</span>${PRESETS.map(x => `<button class="btn btn-sm" type="button" data-act="op-preset" data-k="${x[0]}">${x[1]}</button>`).join('')}<button class="btn btn-sm btn-g" type="button" data-act="op-preset" data-k="none">پاک کردن</button></div>
    <div class="card flat"><div class="card-b" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px 20px">${PERMS.map(g => `<div><div class="lab" style="margin-bottom:6px">${g[0]}</div>${g[1].map(x => `<label class="row small" style="padding:3px 0"><input type="checkbox" class="cb" name="opPage" value="${x[0]}" ${p.pages.includes(x[0]) ? 'checked' : ''}>${x[1]}</label>`).join('')}</div>`).join('')}</div></div>
    <p class="xs muted" style="margin-top:6px">تنظیمات و کانال‌ها فقط برای مدیر است.</p></div>
  ${field('گروه‌های مشتری مجاز', D.groups.length ? `<div class="row wrap">${D.groups.map(g => `<label class="tag" style="cursor:pointer"><input type="checkbox" class="cb" name="opGroup" value="${g.rid}" ${p.groups.includes(g.rid) ? 'checked' : ''}>${E(g.name)}</label>`).join('')}</div>` : '<span class="small muted">هنوز گروهی نساخته‌اید.</span>', 'اگر هیچ گروهی انتخاب نشود، اپراتور همه‌ی مشتریان را می‌بیند. وگرنه فقط مشتریان همین گروه‌ها را در بانک مشتری و صندوق ورودی می‌بیند.')}
  <label class="row">${sw('opDel', p.del)}<span>اجازه‌ی حذف مشتری</span></label></div>`;
}
A['op-role'] = el => { A['seg'](el); const v = el.dataset.v === 'viewer'; const a = $('#opViewer'), b = $('#opStd'); if (a) a.hidden = !v; if (b) b.hidden = v; };
A['op-scope'] = el => { A['seg'](el); const box = $('#opPages'); if (box) box.hidden = el.dataset.v !== 'some'; };
A['op-preset'] = el => { const pr = PRESETS.find(x => x[0] === el.dataset.k); $$('input[name=opPage]').forEach(c => { c.checked = !!pr && pr[2].includes(c.value); }); };
function readPerms() {
  const on = $('.seg[data-name=opScope] .on');
  const pages = on && on.dataset.v === 'some' ? $$('input[name=opPage]:checked').map(c => c.value) : [];
  const role = $('.seg[data-name=opRole] .on');
  const rid = $('#opRoleId');
  return {pages, groups:$$('input[name=opGroup]:checked').map(c => +c.value), can_delete_customers:val('opDel'), mask_pii:val('opMask'), role:role ? role.dataset.v : 'operator', role_id:rid && rid.value ? +rid.value : 0};
}
A['op-new'] = () => {
  const dw = drawer({title:'اپراتور جدید', sub:'حساب ورود همکار و دسترسی‌هایش', size:'lg', head:`<span class="chi md" style="background:var(--accent-soft);color:var(--accent-ink)">${ic('user-plus')}</span>`, body:`<div class="stack s20" style="padding:18px">
    <div class="fg">${field('نام نمایشی', '<input class="inp" id="onName" maxlength="100" placeholder="مثلاً سارا محمدی">')}${field('عنوان شغلی', '<input class="inp" id="onTitle" maxlength="100" placeholder="کارشناس پشتیبانی">')}${field('نام کاربری', '<input class="inp ltr" id="onUser" autocomplete="off">')}${field('رمز عبور', '<input class="inp ltr" id="onPass" type="text" autocomplete="off">', 'حداقل ۸ کاراکتر')}</div>
    ${permForm({pages:PRESETS[0][2].slice(), groups:[], del:false})}</div>`, foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>ساخت حساب</button>'});
  dw.el.querySelector('[data-ok]').onclick = async e => {
    const u = val('onUser'), pw = val('onPass');
    if (!u) return toast('نام کاربری را وارد کنید', 'err');
    if (pw.length < 8) return toast('رمز عبور حداقل ۸ کاراکتر باشد', 'err');
    const r = await run(e.currentTarget, () => L().api('operator_create', Object.assign({username:u, password:pw}, readPerms())));
    if (!r.ok) return toast(r.error, 'err');
    if (val('onName') || val('onTitle')) await L().api('desk_user_profile', {id:r.id, display_name:val('onName'), title:val('onTitle')});
    dw.close(); toast('حساب اپراتور ساخته شد'); body();
  };
};
A['op-edit'] = el => {
  const r = (S.operators || []).find(x => +x.id === +el.dataset.id); if (!r) return;
  const u = D.ops.find(o => o.rid === +r.id) || {name:r.username, title:'', color:'#1D5C4D'}; const p = permsOf(r); const admin = r.role === 'admin';
  const dw = drawer({title:u.name, sub:'@' + r.username, size:'lg', head:av(u.name, {color:u.color}), body:`<div class="stack s20" style="padding:18px">
    <div class="fg">${field('نام نمایشی', `<input class="inp" id="oeName" maxlength="100" value="${E(u.name)}">`)}${field('عنوان شغلی', `<input class="inp" id="oeTitle" maxlength="100" value="${E(u.title || '')}">`)}${field('رنگ آواتار', colorInp('oeColor', u.color))}</div>
    ${admin ? `<div class="callout">${ic('crown-simple')}<span>حساب مدیر به همه‌ی بخش‌ها دسترسی دارد و قابل محدودسازی یا حذف نیست. رمز مدیر را از «پروفایل و رمز» تغییر دهید.</span></div>` : `${permForm(p)}
    <label class="row">${sw('oeAct', +r.is_active === 1)}<span>حساب فعال است</span></label>
    ${field('رمز جدید', '<input class="inp ltr" id="oePass" type="text" autocomplete="off" placeholder="خالی بماند تا تغییر نکند">', 'حداقل ۸ کاراکتر')}`}</div>`,
    foot:`${admin ? '' : '<button class="btn btn-d" data-del>حذف اپراتور</button>'}${!admin && +r.is_active === 1 && D.me.is_admin ? `<button class="btn" data-imp title="پنل را دقیقاً از دید این همکار ببینید و به‌جای او کار کنید">${ic('sign-in')}ورود به‌جای ${E(u.name)}</button>` : ''}<span class="ms-auto"></span><button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>ذخیره</button>`});
  const imp = dw.el.querySelector('[data-imp]');
  if (imp) imp.onclick = () => confirm({title:'ورود به‌جای ' + E(u.name), text:`پنل با حساب و دسترسی‌های «${E(u.name)}» باز می‌شود و هر پیامی بفرستید به نام او ثبت می‌شود. این ورود در گزارش امنیتی ثبت می‌شود. هر وقت خواستید از نوار بالای صفحه به حساب مدیر برگردید.`, ok:'ورود', onOk:async () => {
    const res = await L().api('desk_impersonate', {id:+r.id}); if (!res.ok) return toast(res.error, 'err');
    location.href = 'index.php#dashboard'; setTimeout(() => location.reload(), 50);
  }});
  if (!admin) L().api('desk_audit', {user_id:+r.id}).then(a => { if (!a.ok || !a.rows.length) return; const box = document.createElement('div'); box.className = 'card flat'; box.style.margin = '0 18px 18px';
    box.innerHTML = `<div class="card-h">${ic('shield-check')}<h3>گزارش امنیتی</h3></div>${a.rows.slice(0, 8).map(x => `<div class="li"><div class="grow"><div class="small">${E(x.detail || x.action)}</div><div class="xs muted">${E(L().fullDT(x.created_at))}${x.ip ? ' · ' + E(x.ip) : ''}</div></div></div>`).join('')}`;
    const b = dw.el.querySelector('.m-b'); b && b.appendChild(box); });
  dw.el.querySelector('[data-ok]').onclick = async e => {
    const pw = admin ? '' : val('oePass');
    if (pw && pw.length < 8) return toast('رمز جدید حداقل ۸ کاراکتر باشد', 'err');
    const res = await run(e.currentTarget, async () => {
      const a = await L().api('desk_user_profile', {id:+r.id, display_name:val('oeName'), title:val('oeTitle'), color:val('oeColor')});
      if (!a.ok || admin) return a;
      return L().api('operator_update', Object.assign({id:+r.id, is_active:val('oeAct'), password:pw}, readPerms()));
    });
    if (!res.ok) return toast(res.error, 'err');
    dw.close(); toast('اطلاعات اپراتور ذخیره شد'); body();
  };
  const del = dw.el.querySelector('[data-del]');
  if (del) del.onclick = () => confirm({title:'حذف ' + u.name, text:'حساب ورود این اپراتور حذف می‌شود. گفتگوهای سپرده‌شده به او بدون اپراتور می‌مانند. برای قطع موقت، «حساب فعال است» را خاموش کنید.', ok:'حذف حساب', danger:true, onOk:async () => { const x = await L().api('operator_delete', {id:+r.id}); if (!x.ok) return toast(x.error, 'err'); dw.close(); toast('اپراتور حذف شد'); body(); }});
};

/* ================= چارت سازمانی و نقش‌ها (۲.۰.۹) ================= */
function roles() {
  const list = roleTree();
  const row = r => { const mem = r.members || []; const kids = (S.roles || []).filter(x => +x.parent_id === +r.id).length;
    return `<div class="li" style="padding-inline-start:${12 + r.depth * 26}px">
      <i class="ph ph-${r.depth ? 'arrow-elbow-down-left' : 'buildings'}" style="color:var(--muted);opacity:.7"></i>
      <div class="grow" style="min-width:0"><div class="row wrap" style="gap:6px"><b>${E(r.name)}</b>${r.is_manager ? pill('مثل سرپرست', 'info', 'shield-check') : ''}${kids ? pill(fa(kids) + ' زیرگروه', '') : ''}</div>
        <div class="xs muted">${fa(r.effective_count)} بخش (با زیرمجموعه‌ها) · ${mem.length ? mem.map(m => E(m.name)).join('، ') : 'بدون عضو'}</div></div>
      <button class="btn btn-sm btn-g" data-act="rl-new" data-parent="${r.id}">${ic('plus')}زیرگروه</button>
      <button class="btn btn-sm" data-act="rl-edit" data-id="${r.id}">ویرایش</button></div>`; };
  return `${head('چارت سازمانی و نقش‌ها', 'هر نقش یک گره از درخت است: مدیر مالی، مدیر عملیات ← فروش و میزبان، مدیر مارکتینگ ← مدیر سوشال و مدیر سئو ← زیرگروه‌ها. نقش بالاتر همه‌ی بخش‌های زیرمجموعه‌اش را هم دارد. نقش هر همکار را در «اپراتورها و دسترسی» انتخاب کنید.', `<button class="btn btn-p" data-act="rl-new">${ic('plus')}نقش جدید</button>`)}
  <div class="card" style="margin-top:16px">${list.length ? list.map(row).join('') : empty('tree-structure', 'هنوز چارتی نساخته‌اید', 'می‌توانید از یک چارت نمونه شروع کنید (مالی، عملیات، مارکتینگ با سوشال و سئو) و آن را تغییر دهید.', `<button class="btn btn-p" data-act="rl-seed">${ic('magic-wand')}ساخت چارت نمونه</button> <button class="btn" data-act="rl-new">${ic('plus')}نقش جدید</button>`)}</div>
  <p class="xs muted" style="margin-top:10px">«تنظیمات» و «اتصال کانال‌ها» فقط برای مدیر است و به هیچ نقشی داده نمی‌شود. نقشِ «مثل سرپرست» همه‌ی مشتریان را می‌بیند و در CRM هم سطح مدیر بخش دارد.</p>`;
}
A['rl-seed'] = async el => { const r = await run(el, () => L().api('role_seed')); if (!r.ok) return toast(r.error, 'err'); toast('چارت نمونه ساخته شد'); body(); };
A['rl-new'] = el => roleForm(null, el.dataset.parent ? +el.dataset.parent : 0);
A['rl-edit'] = el => roleForm(roleById(+el.dataset.id), 0);
function roleForm(r, parent) {
  const p = r ? r.pages : [];
  const dw = drawer({title:r ? r.name : 'نقش جدید', sub:'بخش‌هایی که این نقش (و بالادست‌هایش) می‌بینند', size:'lg', body:`<div class="stack s20" style="padding:18px">
    <div class="fg">${field('نام نقش', `<input class="inp" id="rlName" maxlength="100" value="${E(r ? r.name : '')}" placeholder="مثلاً مدیر سوشال">`)}
    ${field('زیر مجموعه‌ی', `<select class="inp" id="rlParent"><option value="">— سطح بالا —</option>${roleOpts(r ? r.parent_id : parent, r && r.id)}</select>`)}</div>
    <label class="row">${sw('rlMgr', !!(r && r.is_manager))}<span>مثل سرپرست رفتار کند (همه‌ی مشتریان را ببیند، چت‌ها را جابه‌جا کند)</span></label>
    <div><div class="row wrap" style="margin-bottom:10px"><span class="xs muted">الگوی آماده:</span>${PRESETS.map(x => `<button class="btn btn-sm" type="button" data-act="rl-preset" data-k="${x[0]}">${x[1]}</button>`).join('')}<button class="btn btn-sm btn-g" type="button" data-act="rl-preset" data-k="none">پاک کردن</button></div>
    <div class="card flat"><div class="card-b" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px 20px">${PERMS.map(g => `<div><div class="lab" style="margin-bottom:6px">${g[0]}</div>${g[1].map(x => `<label class="row small" style="padding:3px 0"><input type="checkbox" class="cb" name="rlPage" value="${x[0]}" ${p.includes(x[0]) ? 'checked' : ''}>${x[1]}</label>`).join('')}</div>`).join('')}</div></div></div></div>`,
    foot:`${r ? '<button class="btn btn-d" data-del>حذف نقش</button>' : ''}<span class="ms-auto"></span><button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>ذخیره</button>`});
  dw.el.querySelector('[data-ok]').onclick = async e => {
    if (!val('rlName')) return toast('نام نقش را بنویسید', 'err');
    const res = await run(e.currentTarget, () => L().api('role_save', {id:r ? r.id : 0, name:val('rlName'), parent_id:+val('rlParent') || 0, is_manager:val('rlMgr'), pages:$$('input[name=rlPage]:checked').map(c => c.value)}));
    if (!res.ok) return toast(res.error, 'err');
    dw.close(); toast('نقش ذخیره شد'); body();
  };
  const del = dw.el.querySelector('[data-del]');
  if (del) del.onclick = () => confirm({title:'حذف ' + r.name, text:'زیرگروه‌های این نقش یک پله بالا می‌روند و همکارانِ این نقش بی‌نقش می‌شوند (دسترسی‌های دستی‌شان می‌ماند).', ok:'حذف نقش', danger:true, onOk:async () => { const x = await L().api('role_delete', {id:r.id}); if (!x.ok) return toast(x.error, 'err'); dw.close(); toast('نقش حذف شد'); body(); }});
}
A['rl-preset'] = el => { const pr = PRESETS.find(x => x[0] === el.dataset.k); $$('input[name=rlPage]').forEach(c => { c.checked = !!pr && pr[2].includes(c.value); }); };

/* ================= سایت‌ها — مدیریت چندسایته (۲.۱.۱) ================= */
function sites() {
  const list = S.sites || [], cur = D.siteCur || S.siteCur;
  const card = x => `<div class="card flat" style="margin-bottom:12px;border-inline-start:4px solid ${x.color || 'var(--accent)'}"><div class="card-b stack s12">
    <div class="row wrap" style="gap:8px;align-items:center"><b style="font-size:15px">${E(x.name)}</b>${+x.is_default ? pill('سایت اصلی', 'info') : ''}${+x.id === +cur ? pill('در حال مدیریت', 'ok', 'check-circle') : ''}${+x.is_active ? '' : pill('غیرفعال', 'warn')}
      <span class="xs muted ltr" style="margin-inline-start:6px">${E(x.domain || 'بدون دامنه')}</span><span class="ms-auto"></span>
      ${+x.id === +cur ? '' : `<button class="btn btn-sm btn-p" data-act="st-pick" data-id="${x.id}">مدیریت این سایت</button>`}
      <button class="btn btn-sm" data-act="st-embed" data-id="${x.id}">${ic('code')}کدهای نصب</button>
      <button class="btn btn-sm" data-act="st-edit" data-id="${x.id}">ویرایش</button></div>
    <div class="row wrap xs muted" style="gap:14px"><span>${ic('chat-circle-dots')} چت‌بات: ${x.chat_on ? '<b style="color:var(--ok)">روشن</b>' : 'خاموش'}</span><span>${ic('chart-line-up')} آنالیتیکس: ${x.pulse_on ? '<b style="color:var(--ok)">روشن</b>' : 'خاموش'}</span><span>${fa(x.stats.chats)} گفتگو</span><span>${fa(x.stats.notices)} اعلان اختصاصی</span><span>${fa(x.stats.menus)} گرهٔ منوی اختصاصی</span></div></div></div>`;
  return `${head('سایت‌ها (چندسایته)', 'با یک پنل چند سایت را مدیریت کنید. هر سایت چت‌بات، طراحی و دکمه‌های شناور، اعلان‌ها، آنالیتیکس و منو/پاسخ خودکار جدا دارد؛ سایتِ فعلی از «انتخاب‌گر سایت» بالای پنل عوض می‌شود.')}
  <div class="callout">${ic('info')}<span>هر سایتِ جدید با تنظیمات خالی شروع می‌شود (نه کپیِ سایت اصلی) تا اطلاعات سایت‌ها قاطی نشود. برای شروع سریع، در «ویرایش» می‌توانید تنظیمات را از سایت دیگری کپی کنید. در کد نصب هر سایت، کلید همان سایت (<span class="ltr">data-site</span>) آمده است؛ کد قدیمی بدون کلید همچنان برای «سایت اصلی» کار می‌کند.</span></div>
  <div style="margin-top:16px">${list.map(card).join('')}</div>
  <button class="btn btn-p" data-act="st-new">${ic('plus')}سایت جدید</button>
  <p class="xs muted" style="margin-top:12px">دکمهٔ «مدیریت این سایت» (یا انتخاب‌گر بالای صفحه) باعث می‌شود صفحه‌های «گفتگوی سایت»، «دکمه‌های شناور و طراحی»، «اعلان سایت»، «آنالیتیکس»، «بات‌ساز منو» و «پاسخ خودکار» دادهٔ همان سایت را نشان بدهند و ذخیره کنند.</p>`;
}
A['st-pick'] = el => { try { localStorage.setItem('ebSite', el.dataset.id); } catch (e) {} location.reload(); };
A['st-new'] = () => siteForm(null);
A['st-edit'] = el => siteForm((S.sites || []).find(x => +x.id === +el.dataset.id));
function siteForm(x) {
  const others = (S.sites || []).filter(o => !x || +o.id !== +x.id);
  const dw = drawer({title:x ? x.name : 'سایت جدید', sub:'نام، دامنه و رنگ شناسایی سایت', size:'md', body:`<div class="stack s16" style="padding:18px">
    <div class="fg">${field('نام سایت', `<input class="inp" id="stName" maxlength="120" value="${E(x ? x.name : '')}" placeholder="مثلاً جاکجاست / فروشگاه ...">`)}${field('رنگ شناسایی', colorInp('stColor', x && x.color ? x.color : '#2F7A5E'))}</div>
    ${field('دامنه', `<input class="inp ltr" id="stDomain" value="${E(x ? (x.domain || '') : '')}" placeholder="example.com">`, 'فقط دامنه (بدون https). برای محدود کردن چت همین سایت به همین دامنه استفاده می‌شود.')}
    <label class="row">${sw('stSync', false)}<span>دامنهٔ مجازِ چت این سایت را همین دامنه بگذار (اگر هنوز خالی است)</span></label>
    ${x && !+x.is_default ? `<label class="row">${sw('stActive', !!+x.is_active)}<span>سایت فعال باشد (غیرفعال: ویجت، اعلان و آنالیتیکس‌اش پاسخ نمی‌دهند)</span></label>` : ''}
    ${others.length ? field(x ? 'کپی تنظیمات از سایت دیگر (اختیاری)' : 'شروع از تنظیمات سایت دیگر (اختیاری)', `<select class="inp" id="stCopy"><option value="">— خالی —</option>${others.map(o => `<option value="${o.id}">${E(o.name)}</option>`).join('')}</select>`, 'متن‌ها، رنگ‌ها، طراحی و دکمه‌های شناور کپی می‌شود (روشن‌بودن چت/آنالیتیکس و کلیدها نه). اگر این سایت قبلاً تنظیماتی دارد روی آن‌ها می‌نویسد.') : ''}
    </div>`,
    foot:`${x && !+x.is_default ? '<button class="btn btn-d" data-del>حذف سایت</button>' : ''}<span class="ms-auto"></span><button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>ذخیره</button>`});
  dw.el.querySelector('[data-ok]').onclick = async e => {
    if (!val('stName')) return toast('نام سایت را بنویسید', 'err');
    const r = await run(e.currentTarget, () => L().api('site_save', {id:x ? x.id : 0, name:val('stName'), domain:val('stDomain'), color:val('stColor'), sync_hosts:val('stSync') ? 1 : 0, is_active:x && !+x.is_default ? (val('stActive') ? 1 : 0) : 1}));
    if (!r.ok) return toast(r.error, 'err');
    const from = +val('stCopy') || 0;
    if (from) { const c = await L().api('site_copy', {from, to:r.id}); if (!c.ok) toast(c.error, 'err'); }
    dw.close(); toast('سایت ذخیره شد'); const b = await L().api('desk_boot'); if (b.ok) D.sites = b.sites; body();
  };
  const del = dw.el.querySelector('[data-del]');
  if (del) del.onclick = () => confirm({title:'حذف ' + x.name, text:'تنظیمات این سایت (چت، طراحی، دکمه‌ها، آنالیتیکس) پاک می‌شود. اعلان‌ها، منوها و پاسخ‌های خودکارِ اختصاصی‌اش «برای همه‌ی سایت‌ها» می‌شوند و گفتگوها و نشست‌های ثبت‌شده‌اش به سایت اصلی می‌روند. ادامه می‌دهید؟', danger:true, ok:'حذف سایت', onOk: async () => {
    const r = await L().api('site_delete', {id:x.id}); if (!r.ok) return toast(r.error, 'err');
    try { if (localStorage.getItem('ebSite') == x.id) localStorage.removeItem('ebSite'); } catch (e) {}
    dw.close(); toast('سایت حذف شد'); location.reload(); }});
}
A['st-embed'] = el => {
  const x = (S.sites || []).find(o => +o.id === +el.dataset.id); if (!x) return;
  const blk = (t, code, note) => `<div class="stack s8"><div class="row"><b>${t}</b><span class="ms-auto"></span><button class="btn btn-sm" data-act="copy" data-text="${E(code)}">${ic('copy')}کپی</button></div><pre class="code ltr" style="white-space:pre-wrap;word-break:break-all;margin:0">${E(code)}</pre><div class="xs muted">${note}</div></div>`;
  drawer({title:'کدهای نصب — ' + x.name, sub:'هر کد را فقط روی همین سایت بگذارید (قبل از </body>)', size:'lg', body:`<div class="stack s20" style="padding:18px">
    ${blk('چت‌بات + دکمه‌های شناور', x.embed.widget, 'یک خط برای هر دو: ویجت گفتگو و دکمه‌های شناور همین سایت.')}
    ${blk('اعلان سایت (پاپ‌آپ/نوار)', x.embed.notices, 'فقط اگر روی این سایت اعلان نشان می‌دهید.')}
    ${blk('آنالیتیکس', x.embed.pulse, 'فقط اگر آنالیتیکس همین سایت روشن است (صفحهٔ «آنالیتیکس» ← تنظیمات، پس از انتخاب این سایت).')}
    <div class="row"><button class="btn btn-sm" data-act="st-rotate" data-id="${x.id}">${ic('arrows-clockwise')}تعویض کلید سایت</button><span class="xs muted">با تعویض کلید، کدهای نصب‌شدهٔ قبلی از کار می‌افتند.</span></div></div>`});
};
A['st-rotate'] = el => confirm({title:'تعویض کلید سایت', text:'کدهای نصب‌شدهٔ قبلی روی سایت از کار می‌افتند تا کد تازه را نصب کنید. ادامه؟', danger:true, ok:'تعویض کلید', onOk: async () => {
  const r = await L().api('site_rotate_key', {id:+el.dataset.id}); if (!r.ok) return toast(r.error, 'err'); toast('کلید تازه ساخته شد'); body(); }});

/* ================= تیم‌ها، SLA و ساعت کاری ================= */
const PRI = [['urgent', 'فوری'], ['high', 'زیاد'], ['normal', 'عادی'], ['low', 'کم']];
const DAYS = [[6, 'شنبه'], [0, 'یکشنبه'], [1, 'دوشنبه'], [2, 'سه‌شنبه'], [3, 'چهارشنبه'], [4, 'پنجشنبه'], [5, 'جمعه']];
const RULES = [['manual', 'دستی'], ['round', 'چرخشی'], ['load', 'کمترین بار']];
function routing() {
  const s = S.support || {}; const days = (s.days || []).map(Number);
  return `${head('تیم‌ها، SLA و ساعت کاری', 'گفتگوها چطور بین همکاران پخش شوند، مهلت پاسخ چقدر باشد و چه زمانی تیم آنلاین حساب شود.')}
  ${sec('تیم‌ها', 'هر تیم یک صف جدا در صندوق ورودی دارد. گفتگویی که به عضو تیم سپرده شود، خودکار به صف همان تیم می‌رود.', `${D.teams.length ? `<div class="card flat">${D.teams.map(t => `<div class="li"><i class="dot" style="background:${E(t.color)};width:10px;height:10px"></i><div class="grow" style="min-width:0"><div class="t">${E(t.name)}</div><div class="s ell">${t.members.map(m => (D.ops.find(o => o.id === m) || {}).name).filter(Boolean).map(E).join('، ') || 'بدون عضو'} · ${(RULES.find(x => x[0] === t.rule) || RULES[0])[1]}</div></div><button class="btn btn-sm" data-act="team-edit" data-id="${t.rid}">ویرایش</button></div>`).join('')}</div>` : `<div class="callout">${ic('info')}<span>هنوز تیمی نساخته‌اید. برای کسب‌وکار کوچک لازم نیست؛ همه در صف «همه» کار می‌کنند.</span></div>`}
    <button class="btn btn-sm" style="align-self:flex-start" data-act="team-edit">${ic('plus')}تیم جدید</button>`)}
  ${sec('مهلت پاسخ (SLA)', 'از لحظه‌ی رسیدن پیام مشتری تا پاسخ اپراتور. گفتگوهای نزدیک به مهلت در صندوق ورودی رنگی می‌شوند و در داشبورد هشدار می‌گیرند.', `<div class="card flat"><div class="tbl-wrap"><table class="tbl"><thead><tr><th>اولویت</th><th>مهلت پاسخ</th></tr></thead><tbody>${PRI.map(p => `<tr><td class="b">${p[1]}</td><td><div class="inp-group" style="max-width:200px"><input class="inp inp-sm ltr" id="sla_${p[0]}" inputmode="numeric" value="${+(D.sla || {})[p[0]] || ''}" aria-label="مهلت ${p[1]}"><span class="addon">دقیقه</span></div></td></tr>`).join('')}</tbody></table></div></div>${saveBtn('sla-save')}`)}
  ${sec('ساعت کاری', 'خارج از این بازه، تیم «آفلاین» حساب می‌شود؛ هوش مصنوعی (اگر فعال باشد) پاسخ می‌دهد و ویجت سایت پیام آفلاین نشان می‌دهد.', `<div class="field"><label>حالت</label>${seg('bhMode', [['auto', 'خودکار طبق جدول'], ['online', 'همیشه آنلاین'], ['offline', 'همیشه آفلاین']], s.presence || 'auto')}</div>
    <div class="field"><label>روزهای کاری</label><div class="row wrap">${DAYS.map(d => `<label class="tag" style="cursor:pointer"><input type="checkbox" class="cb" name="bhDay" value="${d[0]}" ${days.includes(d[0]) ? 'checked' : ''}>${d[1]}</label>`).join('')}</div></div>
    <div class="row wrap">${field('از ساعت', `<input class="inp ltr" type="time" id="bhFrom" value="${E(s.from || '09:00')}" style="max-width:140px">`)}${field('تا ساعت', `<input class="inp ltr" type="time" id="bhTo" value="${E(s.to || '18:00')}" style="max-width:140px">`)}${field('منطقه‌ی زمانی', `<select class="inp" id="bhTz">${[['Asia/Tehran', 'تهران'], ['Asia/Dubai', 'دبی'], ['Europe/Istanbul', 'استانبول'], ['Asia/Kabul', 'کابل']].map(z => `<option value="${z[0]}" ${(s.tz || 'Asia/Tehran') === z[0] ? 'selected' : ''}>${z[1]}</option>`).join('')}</select>`)}</div>
    ${s.now ? `<div class="row small muted">${ic(s.online ? 'check-circle' : 'moon')}<span>الان ساعت ${fa(s.now)} است و تیم <b>${s.online ? 'آنلاین' : 'آفلاین'}</b> حساب می‌شود${s.reason ? ' (' + E(s.reason) + ')' : ''}.</span></div>` : ''}
    ${saveBtn('bh-save')}`)}`;
}
A['team-edit'] = el => {
  const t = el.dataset.id ? D.teams.find(x => x.rid === +el.dataset.id) : null;
  const m = modal({title:t ? 'ویرایش تیم' : 'تیم جدید', icon:'users-three', body:`<div class="stack s16">
    <div class="fg">${field('نام تیم', `<input class="inp" id="tmName" value="${E(t ? t.name : '')}" placeholder="مثلاً فروش">`)}${field('رنگ', colorInp('tmColor', t ? t.color : '#2F7A5E'))}</div>
    ${field('روش تخصیص', `<select class="inp" id="tmRule">${RULES.map(x => `<option value="${x[0]}" ${t && t.rule === x[0] ? 'selected' : ''}>${x[1]}</option>`).join('')}</select>`)}
    ${field('اعضا', `<div class="row wrap">${D.ops.map(o => `<label class="tag" style="cursor:pointer"><input type="checkbox" class="cb" name="tmMem" value="${o.rid}" ${t && t.members.includes(o.id) ? 'checked' : ''}>${E(o.name)}</label>`).join('')}</div>`, 'هر اپراتور در یک تیم اصلی قرار می‌گیرد')}</div>`,
    foot:`${t ? '<button class="btn btn-d" data-del>حذف تیم</button><span class="ms-auto"></span>' : ''}<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>ذخیره</button>`});
  m.el.querySelector('[data-ok]').onclick = async e => {
    const r = await run(e.currentTarget, () => L().api('desk_team_save', {id:t ? t.rid : 0, name:val('tmName'), color:val('tmColor'), rule:val('tmRule'), members:$$('input[name=tmMem]:checked', m.el).map(c => +c.value)}));
    if (!r.ok) return toast(r.error, 'err');
    D.teams = r.teams.map(L().mapTeam); m.close(); rerender(); toast('تیم ذخیره شد');
  };
  const del = m.el.querySelector('[data-del]');
  if (del) del.onclick = async () => { const r = await L().api('desk_team_delete', {id:t.rid}); if (!r.ok) return toast(r.error, 'err'); D.teams = r.teams.map(L().mapTeam); m.close(); rerender(); toast('تیم حذف شد'); };
};
A['sla-save'] = async el => {
  const sla = {};
  for (const p of PRI) { const v = parseInt(toEn(val('sla_' + p[0])), 10); if (!v || v < 1) return toast('مهلت «' + p[1] + '» را به دقیقه وارد کنید', 'err'); sla[p[0]] = v; }
  const r = await run(el, () => L().api('desk_settings_save', {sla}));
  if (!r.ok) return toast(r.error, 'err'); D.sla = sla; toast('مهلت پاسخ ذخیره شد');
};
A['bh-save'] = async el => {
  const on = $('.seg[data-name=bhMode] .on'); const mode = on ? on.dataset.v : 'auto';
  const days = $$('input[name=bhDay]:checked').map(c => +c.value);
  if (mode === 'auto' && !days.length) return toast('دست‌کم یک روز کاری انتخاب کنید', 'err');
  const r = await run(el, () => L().api('support_save', {presence:mode, days, from:val('bhFrom'), to:val('bhTo'), tz:val('bhTz')}));
  if (!r.ok) return toast(r.error, 'err');
  S.support = r.support; D.support = {online:!!r.support.online}; rerender(); toast('ساعت کاری ذخیره شد');
};

/* ================= سیستم و نگهداری ================= */
function system() {
  const s = S.settings || {};
  const cronLine = s.cron_url ? `* * * * * wget -q -O /dev/null "${s.cron_url}"` : '';
  let licTile = '';
  
  return `${head('سیستم و نگهداری', 'زمان‌بندی خودکار، به‌روزرسانی دیتابیس، پروکسی و ابزارهای نگهداری.')}
  ${sec('وضعیت', '', `<div class="row wrap" style="gap:10px"><div class="well" style="min-width:170px"><div class="xs muted">نسخه‌ی نصب‌شده</div><b class="tnum">${fa(D.version || '')}</b></div><div class="well" style="min-width:170px"><div class="xs muted">کانال‌های متصل</div><b>${fa((s.channels_configured || []).length)}</b></div>${licTile}</div>`)}
  ${sec('زمان‌بندی خودکار (Cron)', 'برای ارسال زمان‌بندی‌شده، کمپین چندمرحله‌ای، دریافت پیامک و پاک‌سازی لاگ. در کنترل پنل هاست، هر دقیقه اجرا شود.', `${cronLine ? `<div class="copyrow"><div class="code ltr">${E(cronLine)}</div><button class="btn btn-ic" data-act="copy" data-text="${E(cronLine)}" title="کپی" aria-label="کپی">${ic('copy')}</button></div>` : `<div class="callout warn">${ic('warning')}<span>هنوز کلید Cron ساخته نشده. بدون Cron، ارسال زمان‌بندی‌شده و کمپین اجرا نمی‌شود.</span></div>`}
    <div class="row wrap"><button class="btn btn-sm" data-act="cron-gen">${ic('key')}${cronLine ? 'ساخت کلید تازه' : 'ساخت کلید Cron'}</button>${cronLine ? '<span class="xs muted">با ساخت کلید تازه، آدرس قبلی از کار می‌افتد.</span>' : ''}</div>`)}
  ${sec('به‌روزرسانی دیتابیس', 'بعد از نصب نسخه‌ی جدید یک بار بزنید. جدول‌ها و ستون‌های جاافتاده ساخته می‌شوند؛ بی‌خطر و قابل تکرار است.', `<button class="btn" style="align-self:flex-start" data-act="db-up">${ic('database')}به‌روزرسانی دیتابیس</button>`)}
  ${sec('پروکسی', 'برای تلگرام، هوش مصنوعی، سرچ کنسول و درخواست‌های سناریوها وقتی سرور در ایران است', `<button class="btn" style="align-self:flex-start" data-act="go" data-to="settings.proxy">${ic('shield-check')}تنظیم پروکسی‌ها</button>`)}
  ${sec('ابزارها', 'تنظیمات هوش مصنوعی و اتصال‌های جانبی در «تنظیمات تکمیلی» است.', `<div class="row wrap">${D.pageAllow.includes('apilog') ? `<button class="btn" data-act="go" data-to="apilog">${ic('terminal-window')}لاگ سیستم</button>` : ''}<button class="btn" data-act="set-classic">${ic('sliders-horizontal')}تنظیمات تکمیلی</button></div><div id="classicBox"></div>`)}`;
}
A['cron-gen'] = el => {
  const doIt = async () => { const r = await run(el, () => L().api('cron_key_generate')); if (!r.ok) return toast(r.error, 'err'); S.settings.cron_url = r.cron_url; rerender(); toast('کلید Cron ساخته شد؛ دستور را در هاست به‌روز کنید'); };
  if (S.settings && S.settings.cron_url) confirm({title:'ساخت کلید تازه', text:'آدرس فعلی Cron از کار می‌افتد و باید دستور جدید را در کنترل پنل هاست جایگزین کنید.', ok:'ساخت کلید تازه', onOk:doIt}); else doIt();
};
A['db-up'] = async el => { const r = await run(el, () => L().api('db_upgrade')); r.ok ? toast(r.message || 'دیتابیس به‌روز شد') : toast(r.error, 'err'); };
A['set-classic'] = () => { const box = $('#classicBox'); if (!box) return; box.innerHTML = '<div class="card" style="overflow:hidden;height:calc(100vh - 200px);min-height:520px;margin-top:12px"><div id="classicFrame" style="height:100%"></div></div>'; EB.legacyFrame($('#classicFrame'), 'settings'); box.scrollIntoView({behavior:'smooth', block:'start'}); };

/* ================= پروکسی ================= */
function proxy() {
  const P = S.px, list = S.pxList, routes = S.pxRoutes, TY = P.types, TG = P.target_labels;
  const card = (p, i) => `<div class="well stack s10" data-pi="${i}"><div class="row wrap" style="gap:8px"><b class="grow">${E(p.name || 'پروکسی ' + fa(i + 1))}</b>${p.down_until ? pill('خراب در آزمایش اخیر', 'err') : ''}
      <button class="btn btn-g btn-ic btn-sm" data-act="px-mv" data-i="${i}" data-d="-1" ${i === 0 ? 'disabled' : ''} aria-label="بالا">${ic('arrow-up')}</button><button class="btn btn-g btn-ic btn-sm" data-act="px-mv" data-i="${i}" data-d="1" ${i === list.length - 1 ? 'disabled' : ''} aria-label="پایین">${ic('arrow-down')}</button>
      <label class="row small">${sw('pxe' + i, p.enabled, '', 'px-f').replace('<input ', `<input data-i="${i}" data-k="enabled" `)}<span>فعال</span></label>
      <button class="btn btn-sm" data-act="px-test1" data-i="${i}" ${p.id && !p._new ? '' : 'disabled title="اول ذخیره کنید"'}>${ic('flask')}آزمایش</button><button class="btn btn-g btn-ic btn-sm" data-act="px-rm" data-i="${i}" aria-label="حذف">${ic('trash')}</button></div>
    <div class="fg">${field('نام', `<input class="inp" value="${E(p.name || '')}" data-i="${i}" data-k="name" data-on-input="px-f">`)}${field('نوع', `<select class="inp" data-i="${i}" data-k="type" data-on-change="px-f">${Object.entries(TY).map(t => `<option value="${t[0]}" ${p.type === t[0] ? 'selected' : ''}>${t[1]}</option>`).join('')}</select>`)}
      ${field('آدرس', `<input class="inp ltr" value="${E(p.host || '')}" placeholder="1.2.3.4" data-i="${i}" data-k="host" data-on-input="px-f">`)}${field('پورت', `<input class="inp ltr" value="${E(p.port || '')}" placeholder="1080" inputmode="numeric" data-i="${i}" data-k="port" data-on-input="px-f">`)}
      ${field('نام کاربری', `<input class="inp ltr" value="${E(p.username || '')}" placeholder="اختیاری" data-i="${i}" data-k="username" data-on-input="px-f">`)}${field('رمز', `<input class="inp ltr" type="password" autocomplete="new-password" placeholder="${p.password_set ? 'ثبت شده؛ خالی بماند تا تغییر نکند' : 'اختیاری'}" data-i="${i}" data-k="password" data-on-input="px-f">`)}</div></div>`;
  return `${head('پروکسی و دسترسی', 'سرویس‌های خارجی (تلگرام، هوش مصنوعی، گوگل) از سرورهای داخل ایران در دسترس نیستند. اینجا پروکسی تعریف کنید و برای هر سرویس مشخص کنید از کدام استفاده شود؛ اگر اولی وصل نشد، بعدی امتحان می‌شود.', `<button class="btn btn-p" data-act="px-save2">${ic('check')}ذخیره</button>`)}
  ${sec('وارد کردن با لینک', 'لینک SOCKS یا HTTP را بچسبانید تا فرم پر شود', `<div class="row"><input class="inp ltr" id="pxLink" placeholder="socks5://user:pass@1.2.3.4:1080  یا  tg://socks?server=…"><button class="btn" data-act="px-parse">${ic('magic-wand')}افزودن</button></div><div id="pxLinkMsg"></div>`)}
  ${sec('پروکسی‌ها', 'HTTP، HTTPS، SOCKS4، SOCKS4a، SOCKS5 و SOCKS5h. ترتیب این فهرست، اولویت جایگزینی است.', `<div class="stack s10">${list.map(card).join('') || '<div class="well small muted">هنوز پروکسی‌ای تعریف نشده.</div>'}</div><button class="btn btn-sm" style="align-self:flex-start" data-act="px-add">${ic('plus')}پروکسی تازه</button>`)}
  ${sec('کدام سرویس از کدام پروکسی', 'سرویسی که هیچ پروکسی‌ای برایش تیک نخورده، مستقیم وصل می‌شود', list.length ? `<div class="card flat"><div class="tbl-wrap"><table class="tbl"><thead><tr><th>سرویس</th>${list.map((p, i) => `<th style="text-align:center">${E(p.name || 'پروکسی ' + fa(i + 1))}</th>`).join('')}<th></th></tr></thead><tbody>${Object.entries(TG).map(([t, l]) => `<tr><td class="b">${E(l)}</td>${list.map(p => `<td style="text-align:center"><input type="checkbox" class="cb" data-act="px-route" data-t="${t}" data-id="${E(p.id || '')}" ${(routes[t] || []).includes(p.id) ? 'checked' : ''} ${p.id ? '' : 'disabled'} aria-label="${E(l)}"></td>`).join('')}<td class="w1"><button class="btn btn-g btn-sm" data-act="px-test-t" data-t="${t}">${ic('flask')}آزمایش</button></td></tr>`).join('')}</tbody></table></div></div>` : '<span class="small muted">اول یک پروکسی بسازید.</span>')}
  ${sec('آدرس جایگزین API تلگرام', 'راه مطمئن برای ربات تلگرام روی سرور ایران، بدون پروکسی: یک رله‌ی رایگان روی Cloudflare Workers یا سرور Bot API شخصی', `${field('آدرس', `<input class="inp ltr" id="pxTg" value="${E(P.tg_api_base || '')}" placeholder="https://my-relay.example.workers.dev">`, 'خالی یعنی api.telegram.org')}
    <details><summary class="small" style="cursor:pointer">ساخت رله با Cloudflare Workers (۵ دقیقه)</summary><ol class="small soft stack s6" style="margin:10px 0;padding-inline-start:18px"><li>در dash.cloudflare.com وارد Workers &amp; Pages شوید و یک Worker تازه بسازید.</li><li>کد زیر را جای کد پیش‌فرض بگذارید و Deploy کنید.</li><li>آدرس Worker را در کادر بالا بگذارید و ذخیره کنید.</li></ol>
    <div class="copyrow"><pre class="code ltr" style="white-space:pre;overflow:auto;max-height:200px;margin:0">${E(P.worker)}</pre><button class="btn btn-ic" data-act="copy" data-text="${E(P.worker)}" aria-label="کپی">${ic('copy')}</button></div></details>`)}
  ${sec('درباره‌ی پروکسی MTProto', '', `<div class="callout">${ic('info')}<span>پروکسی MTProto (لینک‌های tg://proxy) فقط برای اپلیکیشن تلگرام ساخته شده و ترافیک ربات‌ها را که از Bot API روی HTTPS استفاده می‌کنند عبور نمی‌دهد؛ هیچ سرویس رباتی نمی‌تواند از آن استفاده کند. برای ربات، یکی از پروکسی‌های بالا یا «آدرس جایگزین API تلگرام» را تنظیم کنید.</span></div>`)}`;
}
ON['px-f'] = el => { const p = S.pxList[+el.dataset.i]; const k = el.dataset.k; p[k] = el.type === 'checkbox' ? el.checked : el.value; if (k === 'password') p.password_clear = false; };
A['px-add'] = () => { S.pxList.push({id:'', _new:true, name:'', type:'socks5h', host:'', port:'', username:'', password:'', enabled:true}); rerender(); };
A['px-rm'] = el => { const p = S.pxList.splice(+el.dataset.i, 1)[0]; if (p && p.id) Object.keys(S.pxRoutes).forEach(t => { S.pxRoutes[t] = (S.pxRoutes[t] || []).filter(x => x !== p.id); }); rerender(); };
A['px-mv'] = el => { const i = +el.dataset.i, j = i + +el.dataset.d, l = S.pxList; if (j < 0 || j >= l.length) return; [l[i], l[j]] = [l[j], l[i]]; rerender(); };
A['px-route'] = el => { const t = el.dataset.t, id = el.dataset.id; const cur = new Set(S.pxRoutes[t] || []); el.checked ? cur.add(id) : cur.delete(id); S.pxRoutes[t] = S.pxList.map(p => p.id).filter(x => cur.has(x)); };
A['px-parse'] = async el => {
  const link = val('pxLink'); if (!link) return;
  const r = await run(el, () => L().api('v4_proxy_parse', {link}));
  const box = $('#pxLinkMsg');
  if (!r.ok) { box.innerHTML = `<div class="callout ${r.mtproto ? 'warn' : 'err'}" style="margin-top:8px">${ic(r.mtproto ? 'info' : 'warning-circle')}<span>${E(r.error)}</span></div>`; return; }
  S.pxList.push(Object.assign({id:'', _new:true, name:r.proxy.host, enabled:true}, r.proxy)); rerender(); toast('اضافه شد؛ ذخیره کنید');
};
A['px-save2'] = async el => {
  for (const p of S.pxList) { if (!p.host || !p.port) return toast('آدرس و پورت همه‌ی پروکسی‌ها را کامل کنید', 'err'); p.port = toEn(String(p.port)); }
  const r = await run(el, () => L().api('v4_proxy_save', {proxies:S.pxList.map(p => ({id:p.id, name:p.name, type:p.type, host:p.host, port:p.port, username:p.username, password:p.password || '', enabled:p.enabled})), routes:S.pxRoutes, tg_api_base:val('pxTg')}));
  if (!r.ok) return toast(r.error, 'err');
  S.px = Object.assign(S.px, r.settings); S.pxList = JSON.parse(JSON.stringify(r.settings.proxies)); S.pxRoutes = JSON.parse(JSON.stringify(r.settings.routes || {}));
  rerender(); toast('تنظیمات پروکسی ذخیره شد');
};
A['px-test1'] = async el => { const p = S.pxList[+el.dataset.i]; const r = await run(el, () => L().api('v4_proxy_test', {id:p.id, target:'telegram'})); r.ok ? toast('پروکسی کار می‌کند (' + fa(r.ms) + ' میلی‌ثانیه، تا تلگرام)') : toast('وصل نشد: ' + (r.error || ''), 'err'); };
A['px-test-t'] = async el => { const r = await run(el, () => L().api('v4_proxy_test', {target:el.dataset.t})); r.ok ? toast((r.direct ? 'اتصال مستقیم' : 'از راه پروکسی') + ' برقرار است (' + fa(r.ms) + ' میلی‌ثانیه)') : toast('وصل نشد' + (r.direct ? ' (بدون پروکسی)' : '') + ': ' + (r.error || ''), 'err'); };


})();
