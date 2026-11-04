/* p-flows.js — سناریوساز: فهرست سناریوها و ویرایشگر گرافی (شبیه n8n) */
(function(){
const {$, $$, E, fa, nf, ic, pill, sw, seg, tabs, empty, field, A, ON, UI, reg, go, toast, menu, modal, drawer, confirm, copy, debounce, rel} = EB;
const L = () => window.EB_LIVE;
const uid = () => 'n' + Math.random().toString(36).slice(2, 8);

/* ======================= تعریف گره‌ها ======================= */
const CH = [['instagram', 'اینستاگرام'], ['telegram', 'تلگرام'], ['bale', 'بله'], ['roubika', 'روبیکا'], ['webchat', 'گفتگوی سایت'], ['sms', 'پیامک']];
const OPS = [['equals', 'برابر'], ['not_equals', 'نابرابر'], ['contains', 'شامل'], ['not_contains', 'شامل نیست'], ['starts', 'شروع با'], ['ends', 'پایان با'], ['regex', 'الگوی regex'],
  ['gt', 'بزرگ‌تر از'], ['gte', 'بزرگ‌تر یا برابر'], ['lt', 'کوچک‌تر از'], ['lte', 'کوچک‌تر یا برابر'], ['empty', 'خالی است'], ['not_empty', 'خالی نیست'], ['in_list', 'یکی از فهرست'], ['is_true', 'درست/بله'], ['is_false', 'نادرست/خیر']];
const VARS = [['text', 'متن آخرین پیام'], ['person.name', 'نام کامل'], ['person.first_name', 'نام کوچک'], ['person.phone', 'موبایل'], ['person.is_member', 'عضو است (۱/۰)'], ['person.tags', 'برچسب‌ها'], ['person.groups', 'گروه‌ها'],
  ['person.messages_in', 'تعداد پیام‌های دریافتی'], ['channel', 'کانال'], ['time.hour', 'ساعت (۰ تا ۲۳)'], ['time.weekday', 'روز هفته (۰=شنبه)'], ['time.date', 'تاریخ امروز'], ['support.online', 'تیم آنلاین است (۱/۰)'], ['http.status', 'کد پاسخ HTTP'], ['http.body', 'بدنه‌ی پاسخ HTTP']];
const GROUPS = [['trigger', 'شروع', '#1D5C4D'], ['logic', 'منطق و زمان', '#3D5A80'], ['send', 'ارسال', '#8A5A1C'], ['person', 'مشتری و تیم', '#6B4F7A'], ['data', 'داده و اتصال', '#2E6670']];

const T = {
  'trigger.message_in': {g:'trigger', t:'پیام دریافتی', i:'chat-circle-dots', d:() => ({channels:[], match:'any', keywords:'', first_only:false, once:false, cooldown:0, hours:'any', consume:true}),
    sum:d => `${d.channels && d.channels.length ? d.channels.map(c => (CH.find(x => x[0] === c) || [0, c])[1]).join('، ') : 'همه‌ی کانال‌ها'}${d.match !== 'any' ? ' · ' + ({contains:'شامل', exact:'دقیقاً', starts:'شروع با', regex:'الگو'}[d.match]) + ': ' + (d.keywords || '').split(/\n|,|،/)[0] : ''}`},
  'trigger.contact_shared': {g:'trigger', t:'اشتراک شماره', i:'phone-incoming', d:() => ({channels:[]}), sum:() => 'وقتی مشتری شماره‌اش را در ربات بفرستد'},
  'trigger.form_submit': {g:'trigger', t:'تکمیل فرم', i:'list-checks', d:() => ({form_id:''}), sum:d => d.form_id ? 'فرم: ' + ((META.forms || []).find(f => +f.id === +d.form_id) || {}).name : 'هر فرمی'},
  'trigger.webhook': {g:'trigger', t:'وب‌هوک ورودی', i:'webhooks-logo', d:() => ({key:Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2), phone_field:'phone'}), sum:() => 'فراخوانی از سیستم دیگر'},
  'trigger.schedule': {g:'trigger', t:'زمان‌بندی', i:'calendar-dots', d:() => ({every:'daily', time:'10:00', minutes:60, minute:0, weekdays:[], audience:'none', group_id:'', tag_id:'', limit:500}),
    sum:d => ({minutes:'هر ' + fa(d.minutes) + ' دقیقه', hourly:'هر ساعت', daily:'هر روز ساعت ' + fa(d.time), weekly:'هفتگی ساعت ' + fa(d.time)}[d.every] || '') + (d.audience !== 'none' ? ' · برای گروه/برچسب' : '')},
  'trigger.manual': {g:'trigger', t:'اجرای دستی', i:'hand-pointing', d:() => ({}), sum:() => 'از پنل برای مشتری‌های انتخابی'},

  'logic.if': {g:'logic', t:'شرط (اگر/وگرنه)', i:'git-fork', d:() => ({join:'and', rules:[{var:'text', op:'contains', value:''}]}), ports:() => [['true', 'بله'], ['false', 'خیر']],
    sum:d => (d.rules || []).map(r => (VARS.find(v => v[0] === r.var) || [0, r.var])[1] + ' ' + (OPS.find(o => o[0] === r.op) || [0, r.op])[1] + (['empty', 'not_empty', 'is_true', 'is_false'].includes(r.op) ? '' : ' «' + r.value + '»')).join(d.join === 'or' ? ' یا ' : ' و ')},
  'logic.switch': {g:'logic', t:'سوییچ (چند حالت)', i:'arrows-split', d:() => ({var:'text', cases:[{op:'contains', value:'قیمت'}, {op:'contains', value:'ارسال'}]}),
    ports:d => (d.cases || []).map((c, i) => ['case_' + i, '«' + (c.value || '…') + '»']).concat([['default', 'هیچ‌کدام']]), sum:d => 'بر اساس ' + ((VARS.find(v => v[0] === d.var) || [0, d.var])[1])},
  'logic.wait': {g:'logic', t:'تأخیر', i:'hourglass-medium', d:() => ({mode:'delay', amount:30, unit:'minute', time:'09:00'}),
    sum:d => d.mode === 'until' ? 'تا ساعت ' + fa(d.time) : fa(d.amount) + ' ' + ({minute:'دقیقه', hour:'ساعت', day:'روز'}[d.unit])},
  'logic.wait_reply': {g:'logic', t:'سؤال و منتظر پاسخ', i:'chat-teardrop-text', d:() => ({question:'', buttons:[], expect:'any', save_as:'reply', timeout:60, retries:2, invalid_text:''}), ports:() => [['reply', 'پاسخ داد'], ['timeout', 'پاسخ نداد']],
    sum:d => (d.question ? '«' + d.question.slice(0, 40) + '» ' : '') + '→ {{vars.' + (d.save_as || 'reply') + '}}'},
  'logic.loop': {g:'logic', t:'حلقه', i:'repeat', d:() => ({mode:'count', count:3, var:'', text:'', sep:',', item_var:'item'}), ports:() => [['item', 'هر مورد'], ['done', 'پایان']],
    sum:d => d.mode === 'count' ? fa(d.count) + ' بار' : d.mode === 'split' ? 'تقسیم متن با «' + d.sep + '»' : 'روی ' + d.var},
  'logic.split': {g:'logic', t:'تقسیم تصادفی (A/B)', i:'shuffle', d:() => ({branches:[{weight:50}, {weight:50}]}), ports:d => (d.branches || []).map((b, i) => ['a_' + i, String.fromCharCode(65 + i) + ' ' + fa(b.weight) + '٪']), sum:d => fa((d.branches || []).length) + ' شاخه'},
  'logic.stop': {g:'logic', t:'پایان', i:'stop-circle', d:() => ({}), ports:() => [], sum:() => 'اجرای سناریو تمام می‌شود'},

  'act.send': {g:'send', t:'ارسال پیام', i:'paper-plane-tilt', d:() => ({text:'', buttons:[], image:'', request_contact:false}), ports:() => [['out', ''], ['error', 'ناموفق']], sum:d => d.text ? d.text.slice(0, 60) : (d.image ? 'عکس' : '(بدون متن)')},
  'act.send_phone': {g:'send', t:'پیامک یا سفیر', i:'device-mobile-speaker', d:() => ({phone:'{{person.phone}}', text:'', mode:'safir_then_sms'}), ports:() => [['out', ''], ['error', 'ناموفق']], sum:d => d.text.slice(0, 50) || 'به شماره‌ی مشتری'},
  'act.ai': {g:'send', t:'هوش مصنوعی', i:'sparkle', d:() => ({prompt:'', history:true, save_as:'ai', send:true}), ports:() => [['ok', 'پاسخ گرفت'], ['error', 'خطا']], sum:d => d.send ? 'پاسخ می‌سازد و می‌فرستد' : 'پاسخ در {{vars.' + (d.save_as || 'ai') + '}}'},

  'act.tag': {g:'person', t:'برچسب', i:'tag', d:() => ({action:'add', tag:''}), sum:d => (d.action === 'remove' ? 'حذف' : 'افزودن') + ' «' + (d.tag || '…') + '»'},
  'act.group': {g:'person', t:'گروه مشتری', i:'users-three', d:() => ({action:'add', group_id:''}), sum:d => (d.action === 'remove' ? 'حذف از ' : 'افزودن به ') + (((META.groups || []).find(g => +g.id === +d.group_id) || {}).name || '…')},
  'act.person': {g:'person', t:'ویرایش مشتری', i:'user-gear', d:() => ({name:'', phone:'', notes:''}), sum:d => [d.name && 'نام', d.phone && 'موبایل', d.notes && 'یادداشت'].filter(Boolean).join('، ') || '…'},
  'act.assign': {g:'person', t:'سپردن گفتگو', i:'user-switch', d:() => ({assignee_id:'', team_id:'', status:'', priority:''}), sum:d => [d.assignee_id && 'به ' + (((META.users || []).find(u => +u.id === +d.assignee_id) || {}).name || ''), d.team_id && 'تیم ' + (((META.teams || []).find(u => +u.id === +d.team_id) || {}).name || ''), d.status && 'وضعیت', d.priority && 'اولویت'].filter(Boolean).join(' · ') || '…'},
  'act.note': {g:'person', t:'یادداشت و اعلان', i:'note-pencil', d:() => ({text:'', to_conversation:true, notify:false}), sum:d => d.text.slice(0, 50) || '…'},
  'act.ai_handoff': {g:'person', t:'سپردن به هوش مصنوعی', i:'robot', d:() => ({action:'on'}), sum:d => d.action === 'off' ? 'برگرداندن به اپراتور' : 'هوش مصنوعی پاسخ بدهد'},

  'data.set': {g:'data', t:'متغیر', i:'brackets-curly', d:() => ({sets:[{name:'', op:'set', value:''}]}), sum:d => (d.sets || []).map(s => s.name).filter(Boolean).join('، ') || '…'},
  'act.http': {g:'data', t:'درخواست HTTP', i:'globe-hemisphere-west', d:() => ({method:'POST', url:'', headers:[], body_type:'json', body:'{\n  "phone": "{{person.phone}}",\n  "name": "{{person.name}}"\n}', save_as:'response'}), ports:() => [['ok', 'موفق'], ['error', 'خطا']],
    sum:d => d.method + ' ' + (d.url || '').replace(/^https?:\/\//, '').slice(0, 40)},
  'act.flow': {g:'data', t:'اجرای سناریوی دیگر', i:'flow-arrow', d:() => ({flow_id:''}), sum:d => (((META.flows || []).find(f => +f.id === +d.flow_id) || {}).name) || '…'},
};
const portsOf = n => n.type.startsWith('trigger.') ? [['out', '']] : (T[n.type] && T[n.type].ports ? T[n.type].ports(n.data) : [['out', '']]);
const grpColor = g => (GROUPS.find(x => x[0] === g) || [0, 0, '#555'])[2];

let META = {};
let F = null;       // سناریوی در حال ویرایش
let V = {x:0, y:0, z:1}, SEL = null, SEL_EDGE = null, HIST = [], HL = null;

/* چیدمان خودکار وقتی گره‌ها مختصات معتبر/متمایز ندارند (مثلاً ساخته‌شده با API) */
function autoLayout(g) {
  if (!g || !g.nodes || g.nodes.length < 2) return;
  const keys = new Set(g.nodes.map(n => (+n.x || 0) + ':' + (+n.y || 0)));
  if (keys.size === g.nodes.length) return;
  const lvl = {}, out = {};
  g.edges.forEach(e => (out[e.from] = out[e.from] || []).push(e.to));
  const roots = g.nodes.filter(n => n.type.startsWith('trigger.') || !g.edges.some(e => e.to === n.id));
  const q = roots.map(n => (lvl[n.id] = 0, n.id));
  while (q.length) { const id = q.shift(); (out[id] || []).forEach(t => { if (lvl[t] === undefined) { lvl[t] = lvl[id] + 1; q.push(t); } }); }
  const rows = {};
  g.nodes.forEach(n => { const l = lvl[n.id] ?? 0; rows[l] = rows[l] || 0; n.x = 700 - l * (NW + 70); n.y = 80 + rows[l]++ * 170; });
}

/* ======================= صفحه ======================= */
reg('flows', {title:'سناریوها', icon:'flow-arrow', full:true, perm:'botbuilder',
  async load(args) {
    const m = await L().api('flow_meta'); if (m.ok) META = m;
    if (args[0] && args[0] !== 'new') {
      const r = await L().api('flow_get', {id:+args[0]});
      if (!r.ok) throw new Error(r.error);
      F = r.flow; F.hook_url = r.hook_url; autoLayout(F.graph); V = {x:0, y:0, z:1}; SEL = null; HIST = []; HL = null; F.dirty = false;
    } else if (args[0] === 'new') {
      if (!F || F.id) newFlow(args[1]);
    } else {
      const r = await L().api('flow_list'); if (!r.ok) throw new Error(r.error);
      META.list = r.flows;
    }
  },
  render(view, args) {
    if (args[0]) editorView(view); else listView(view);
  },
  leave() { F = null; }
});

function listView(view) {
  const rows = META.list || [];
  view.innerHTML = `<div class="page-scroll scroll"><div class="page wide">
    <div class="ph"><div><h2>سناریوها</h2><p>اتوماسیون گرافی برای همه‌ی کانال‌ها و گفتگوی سایت: شرط، سوییچ، حلقه، تأخیر، منتظر پاسخ، هوش مصنوعی و اتصال به سیستم‌های دیگر.</p></div>
      <div class="acts"><button class="btn btn-p" data-act="fl-new">${ic('plus')}سناریوی جدید</button></div></div>
    ${rows.length ? `<div class="card"><div class="tbl-wrap"><table class="tbl resp"><thead><tr><th>سناریو</th><th>شروع</th><th class="num">اجرا</th><th class="num">منتظر</th><th class="num">خطا</th><th>آخرین اجرا</th><th>وضعیت</th><th></th></tr></thead><tbody>
    ${rows.map(f => `<tr class="clk" data-act="go" data-to="flows.${f.id}"><td><b>${E(f.name)}</b>${f.description ? `<div class="xs muted ell" style="max-width:320px">${E(f.description)}</div>` : ''}</td>
      <td class="small"><span class="row" style="gap:6px">${ic((T['trigger.' + f.trigger_type] || {}).i || 'play')}${E((T['trigger.' + f.trigger_type] || {}).t || f.trigger_type)}</span></td>
      <td class="num">${nf(f.runs)}</td><td class="num">${nf(f.waiting)}</td><td class="num">${+f.errors ? `<span style="color:var(--err)">${nf(f.errors)}</span>` : '۰'}</td>
      <td class="small muted">${f.last_run_local ? E(JD.toJ(f.last_run_local)) + ' ' + E(fa(f.last_run_local.slice(11, 16))) : 'هنوز اجرا نشده'}</td>
      <td onclick="event.stopPropagation()">${sw('fls' + f.id, +f.is_active, '', 'fl-toggle').replace('<input ', `<input data-id="${f.id}" `)}</td>
      <td class="w1" onclick="event.stopPropagation()"><button class="btn btn-g btn-ic btn-sm" data-act="fl-row-menu" data-id="${f.id}" aria-label="بیشتر">${ic('dots-three')}</button></td></tr>`).join('')}
    </tbody></table></div></div>` : `<div class="card"><div class="card-b">${empty('flow-arrow', 'هنوز سناریویی نساخته‌اید', 'با یک الگوی آماده شروع کنید یا از صفر بسازید. هر سناریو با یک «شروع» آغاز می‌شود و گره‌ها را به هم وصل می‌کنید.', `<button class="btn btn-p" data-act="fl-new">${ic('plus')}سناریوی جدید</button>`)}</div></div>`}
    <div class="card" style="margin-top:16px"><div class="card-h"><h3>الگوهای آماده</h3><span class="sub">یک کلیک، بعد ویرایش</span></div><div class="card-b"><div class="fl-tpls">${TEMPLATES.map((t, i) => `<button class="fl-tpl" data-act="fl-tpl" data-i="${i}"><span class="chi md" style="background:${t.c}">${ic(t.i)}</span><b>${t.n}</b><span>${t.d}</span></button>`).join('')}</div></div></div>
  </div></div>`;
}
A['fl-new'] = el => menu(el, [{t:'سناریوی خالی', icon:'file', fn:() => { F = null; newFlow(); go('flows.new'); }}, '-', {h:'از الگو'}, ...TEMPLATES.map((t, i) => ({t:t.n, icon:t.i, fn:() => useTpl(i)}))]);
A['fl-tpl'] = el => useTpl(+el.dataset.i);
function useTpl(i) { F = null; newFlow(i); go('flows.new'); }
ON['fl-toggle'] = async el => { const r = await L().api('flow_toggle', {id:+el.dataset.id, on:el.checked}); if (!r.ok) { el.checked = !el.checked; toast(r.error, 'err'); } else toast(el.checked ? 'سناریو روشن شد' : 'سناریو خاموش شد'); };
A['fl-row-menu'] = el => menu(el, [{t:'ویرایش', icon:'pencil-simple', fn:() => go('flows.' + el.dataset.id)}, {t:'کپی', icon:'copy', fn:async () => { const r = await L().api('flow_duplicate', {id:+el.dataset.id}); r.ok ? (toast('کپی ساخته شد'), EB.route(true)) : toast(r.error, 'err'); }},
  {t:'گزارش اجراها', icon:'list-bullets', fn:() => runsDrawer(+el.dataset.id)}, '-', {t:'حذف', icon:'trash', danger:true, fn:() => confirm({title:'حذف سناریو', text:'سناریو و تاریخچه‌ی اجراهایش حذف می‌شود.', ok:'حذف', danger:true, onOk:async () => { await L().api('flow_delete', {id:+el.dataset.id}); toast('حذف شد'); EB.route(true); }})}]);

function newFlow(tplIndex) {
  const t = tplIndex != null && TEMPLATES[tplIndex] ? TEMPLATES[tplIndex] : null;
  const g = t ? t.g() : {nodes:[{id:'start', type:'trigger.message_in', x:600, y:200, data:T['trigger.message_in'].d()}], edges:[]};
  F = {id:0, name:t ? t.n : 'سناریوی جدید', description:t ? t.d : '', is_active:0, graph:g, dirty:true};
  V = {x:0, y:0, z:1}; SEL = null; HIST = []; HL = null;
}

/* ======================= ویرایشگر ======================= */
function editorView(view) {
  view.innerHTML = `<div class="fl">
    <div class="fl-top">
      <button class="btn btn-g btn-ic" data-act="fl-back" aria-label="بازگشت">${ic('arrow-right')}</button>
      <input class="inp fl-name" id="flName" value="${E(F.name)}" aria-label="نام سناریو" data-on-input="fl-name">
      <span class="fl-state" id="flState"></span>
      <span class="ms-auto"></span>
      <label class="row small" title="روشن/خاموش">${sw('flActive', +F.is_active, '', 'fl-active')}<span class="hide-m">روشن</span></label>
      <button class="btn btn-sm" data-act="fl-runs" ${F.id ? '' : 'disabled'}>${ic('list-bullets')}<span class="hide-m">اجراها</span></button>
      <button class="btn btn-sm" data-act="fl-test" ${F.id ? '' : 'disabled'}>${ic('play')}<span class="hide-m">اجرای آزمایشی</span></button>
      <button class="btn btn-p btn-sm" id="flSave" data-act="fl-save">${ic('check')}ذخیره</button>
    </div>
    <div class="fl-body">
      <aside class="fl-pal scroll" id="flPal">${GROUPS.map(g => `<div class="fl-pg"><div class="fl-pgh"><i style="background:${g[2]}"></i>${g[1]}</div>${Object.entries(T).filter(([, d]) => d.g === g[0]).map(([k, d]) => `<button class="fl-pi" data-act="fl-add" data-t="${k}" title="${E(d.t)}">${ic(d.i)}<span>${E(d.t)}</span></button>`).join('')}</div>`).join('')}</aside>
      <div class="fl-canvas" id="flCanvas" tabindex="0" aria-label="بوم سناریو">
        <div class="fl-layer" id="flLayer"><svg class="fl-edges" id="flEdges"></svg><div id="flNodes"></div></div>
        <div class="fl-zoom"><button data-act="fl-zoom" data-z="1.15" aria-label="بزرگ‌نمایی">${ic('plus')}</button><button data-act="fl-zoom" data-z="0.87" aria-label="کوچک‌نمایی">${ic('minus')}</button><button data-act="fl-fit" aria-label="نمایش همه">${ic('corners-out')}</button><button data-act="fl-undo" aria-label="برگرداندن">${ic('arrow-counter-clockwise')}</button></div>
        <div class="fl-mob"><button class="btn btn-sm" data-act="fl-pal-open">${ic('plus')}گره</button></div>
      </div>
      <aside class="fl-insp scroll" id="flInsp"></aside>
    </div></div>`;
  bindCanvas();
  draw(); inspector();
  requestAnimationFrame(fit);
  state();
}
function state() { const s = $('#flState'); if (!s) return; s.innerHTML = F.dirty ? `<span class="pill warn">ذخیره نشده</span>` : (F.id ? `<span class="pill ok">${ic('check')}ذخیره شده</span>` : ''); }
function touch() { F.dirty = true; state(); }
function snapshot() { HIST.push(JSON.stringify(F.graph)); if (HIST.length > 40) HIST.shift(); }
A['fl-undo'] = () => { if (!HIST.length) return toast('چیزی برای برگرداندن نیست', 'info'); F.graph = JSON.parse(HIST.pop()); SEL = null; SEL_EDGE = null; draw(); inspector(); touch(); };
ON['fl-name'] = el => { F.name = el.value; touch(); };
ON['fl-active'] = el => { F.is_active = el.checked ? 1 : 0; touch(); if (el.checked) toast('برای اعمال، ذخیره کنید', 'info'); };
A['fl-back'] = () => { if (F.dirty) return confirm({title:'تغییرات ذخیره نشده', text:'بدون ذخیره خارج شوید؟', ok:'خروج بدون ذخیره', danger:true, onOk:() => { F.dirty = false; go('flows'); }}); go('flows'); };

/* ---------- رسم ---------- */
const NW = 230;
function nodeHtml(n) {
  const d = T[n.type] || {t:n.type, i:'question', g:'data', sum:() => ''};
  const ports = portsOf(n);
  const col = grpColor(d.g);
  const isTrig = n.type.startsWith('trigger.');
  let sum = ''; try { sum = d.sum ? d.sum(n.data || {}) : ''; } catch (e) {}
  return `<div class="fl-n ${SEL === n.id ? 'sel' : ''} ${HL && HL.includes(n.id) ? 'hl' : ''} ${isTrig ? 'trig' : ''}" data-id="${n.id}" style="transform:translate(${n.x}px,${n.y}px);--c:${col}">
    ${isTrig ? '' : '<span class="fl-in" title="ورودی"></span>'}
    <div class="fl-nh" data-drag="${n.id}"><span class="fl-ni">${ic(d.i)}</span><b class="ell">${E((n.data && n.data.title) || d.t)}</b></div>
    <div class="fl-ns">${E(sum || '')}</div>
    ${ports.length ? `<div class="fl-ports">${ports.map(p => `<div class="fl-p" data-port="${p[0]}"><span class="fl-pl">${E(p[1])}</span><span class="fl-out" data-from="${n.id}" data-port="${p[0]}" title="بکشید و روی گره‌ی بعدی رها کنید"></span></div>`).join('')}</div>` : ''}
  </div>`;
}
function draw() {
  const nodes = $('#flNodes'); if (!nodes) return;
  nodes.innerHTML = F.graph.nodes.map(nodeHtml).join('');
  applyView(); edges();
}
function portPos(nodeId, port, input) {
  const el = $(`.fl-n[data-id="${nodeId}"]`); const n = F.graph.nodes.find(x => x.id === nodeId);
  if (!el || !n) return null;
  if (input) return {x:n.x + el.offsetWidth, y:n.y + 22};
  const p = el.querySelector(`.fl-p[data-port="${port}"]`);
  if (!p) return {x:n.x, y:n.y + 22};
  return {x:n.x, y:n.y + p.offsetTop + p.offsetHeight / 2};
}
function curve(a, b) { const dx = Math.max(40, Math.abs(a.x - b.x) * .5); return `M${a.x},${a.y} C${a.x - dx},${a.y} ${b.x + dx},${b.y} ${b.x},${b.y}`; }
function edges(tmp) {
  const svg = $('#flEdges'); if (!svg) return;
  let h = '';
  F.graph.edges.forEach((e, i) => {
    const a = portPos(e.from, e.port), b = portPos(e.to, null, true); if (!a || !b) return;
    const on = SEL_EDGE === i, hl = HL && HL.includes(e.from) && HL.includes(e.to);
    const col = e.port === 'error' || e.port === 'false' || e.port === 'timeout' ? 'var(--err)' : 'var(--accent)';
    h += `<path class="fl-e-hit" d="${curve(a, b)}" data-edge="${i}"/><path class="fl-e ${on ? 'sel' : ''} ${hl ? 'hl' : ''}" d="${curve(a, b)}" style="stroke:${on ? 'var(--warn)' : col}" marker-end="url(#flArrow)"/>`;
  });
  if (tmp) h += `<path class="fl-e tmp" d="${curve(tmp.a, tmp.b)}"/>`;
  svg.innerHTML = `<defs><marker id="flArrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="var(--ink-3)"/></marker></defs>${h}`;
}
function applyView() { const l = $('#flLayer'); if (l) l.style.transform = `translate(${V.x}px,${V.y}px) scale(${V.z})`; }
function fit() {
  const c = $('#flCanvas'); if (!c || !F.graph.nodes.length) return;
  const xs = F.graph.nodes.map(n => n.x), ys = F.graph.nodes.map(n => n.y);
  const minX = Math.min(...xs) - 40, maxX = Math.max(...xs) + NW + 60, minY = Math.min(...ys) - 40, maxY = Math.max(...ys) + 160;
  const z = Math.max(.35, Math.min(1.1, Math.min(c.clientWidth / (maxX - minX), c.clientHeight / (maxY - minY))));
  V.z = z; V.x = (c.clientWidth - (maxX - minX) * z) / 2 - minX * z; V.y = (c.clientHeight - (maxY - minY) * z) / 2 - minY * z;
  if ((maxX - minX) * z > c.clientWidth) V.x = c.clientWidth - maxX * z;   // سناریوی پهن: شروع (سمت راست) دیده شود
  if ((maxY - minY) * z > c.clientHeight) V.y = -minY * z;
  applyView();
}
A['fl-fit'] = fit;
A['fl-zoom'] = el => { const c = $('#flCanvas'); zoomAt(+el.dataset.z, c.clientWidth / 2, c.clientHeight / 2); };
function zoomAt(f, cx, cy) { const nz = Math.max(.3, Math.min(1.8, V.z * f)); V.x = cx - (cx - V.x) * nz / V.z; V.y = cy - (cy - V.y) * nz / V.z; V.z = nz; applyView(); }

/* ---------- تعامل با بوم ---------- */
function toWorld(e) { const r = $('#flCanvas').getBoundingClientRect(); return {x:(e.clientX - r.left - V.x) / V.z, y:(e.clientY - r.top - V.y) / V.z}; }
function bindCanvas() {
  const c = $('#flCanvas');
  let mode = null, start = null, orig = null, nodeId = null, link = null, moved = false;
  c.addEventListener('pointerdown', e => {
    if (e.button !== 0) return;
    const out = e.target.closest('.fl-out'); const head = e.target.closest('[data-drag]'); const nodeEl = e.target.closest('.fl-n'); const edge = e.target.closest('[data-edge]');
    moved = false;
    if (out) { mode = 'link'; link = {from:out.dataset.from, port:out.dataset.port}; c.setPointerCapture(e.pointerId); e.preventDefault(); return; }
    if (edge) { SEL_EDGE = +edge.dataset.edge; SEL = null; draw(); inspector(); return; }
    if (head || nodeEl) {
      nodeId = (head || nodeEl).dataset.drag || nodeEl.dataset.id;
      if (e.target.closest('input,textarea,select,button')) return;
      mode = 'node'; start = {x:e.clientX, y:e.clientY}; const n = F.graph.nodes.find(x => x.id === nodeId); orig = {x:n.x, y:n.y};
      c.setPointerCapture(e.pointerId);
      return;
    }
    mode = 'pan'; start = {x:e.clientX, y:e.clientY}; orig = {x:V.x, y:V.y}; c.setPointerCapture(e.pointerId); c.classList.add('panning');
  });
  c.addEventListener('pointermove', e => {
    if (!mode) return;
    if (mode === 'pan') { V.x = orig.x + e.clientX - start.x; V.y = orig.y + e.clientY - start.y; applyView(); moved = true; }
    else if (mode === 'node') {
      const dx = (e.clientX - start.x) / V.z, dy = (e.clientY - start.y) / V.z;
      if (!moved && Math.abs(dx) + Math.abs(dy) < 3) return;
      if (!moved) snapshot();
      moved = true;
      const n = F.graph.nodes.find(x => x.id === nodeId);
      n.x = Math.round((orig.x + dx) / 10) * 10; n.y = Math.round((orig.y + dy) / 10) * 10;
      const el = $(`.fl-n[data-id="${nodeId}"]`); if (el) el.style.transform = `translate(${n.x}px,${n.y}px)`;
      edges();
    } else if (mode === 'link') {
      const a = portPos(link.from, link.port); edges({a, b:toWorld(e)}); moved = true;
    }
  });
  c.addEventListener('pointerup', e => {
    if (mode === 'link') {
      const target = document.elementFromPoint(e.clientX, e.clientY); const nodeEl = target && target.closest('.fl-n');
      if (nodeEl && nodeEl.dataset.id !== link.from && !nodeEl.classList.contains('trig')) {
        snapshot();
        F.graph.edges = F.graph.edges.filter(x => !(x.from === link.from && x.port === link.port));
        F.graph.edges.push({from:link.from, port:link.port, to:nodeEl.dataset.id}); touch();
      }
      edges();
    } else if (mode === 'node') {
      if (moved) touch(); else { SEL = nodeId; SEL_EDGE = null; $$('.fl-n').forEach(x => x.classList.toggle('sel', x.dataset.id === SEL)); edges(); inspector(); }
    } else if (mode === 'pan' && !moved) { if (SEL || SEL_EDGE != null) { SEL = null; SEL_EDGE = null; draw(); inspector(); } }
    c.classList.remove('panning');
    mode = null; link = null;
  });
  c.addEventListener('wheel', e => { e.preventDefault(); const r = c.getBoundingClientRect(); zoomAt(e.deltaY < 0 ? 1.1 : 0.9, e.clientX - r.left, e.clientY - r.top); }, {passive:false});
  c.addEventListener('keydown', e => {
    if ((e.key === 'Delete' || e.key === 'Backspace') && !e.target.closest('input,textarea')) { if (SEL) A['fl-del-node'](); else if (SEL_EDGE != null) A['fl-del-edge'](); }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); A['fl-undo'](); }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); A['fl-save']($('#flSave')); }
  });
}

/* ---------- افزودن/حذف ---------- */
A['fl-add'] = el => {
  const type = el.dataset.t; const isTrig = type.startsWith('trigger.');
  if (isTrig && F.graph.nodes.some(n => n.type.startsWith('trigger.'))) {
    const t = F.graph.nodes.find(n => n.type.startsWith('trigger.'));
    return confirm({title:'تغییر نوع شروع', text:'هر سناریو یک «شروع» دارد. نوع شروع فعلی با «' + T[type].t + '» جایگزین شود؟', ok:'جایگزین کن', onOk:() => { snapshot(); t.type = type; t.data = T[type].d(); SEL = t.id; draw(); inspector(); touch(); }});
  }
  snapshot();
  const c = $('#flCanvas');
  let pos = {x:(c.clientWidth / 2 - V.x) / V.z - NW / 2, y:(c.clientHeight / 2 - V.y) / V.z - 40};
  const from = SEL ? F.graph.nodes.find(n => n.id === SEL) : null;
  if (from) pos = {x:from.x - NW - 70, y:from.y};
  while (F.graph.nodes.some(n => Math.abs(n.x - pos.x) < 40 && Math.abs(n.y - pos.y) < 40)) pos.y += 110;
  const n = {id:uid(), type, x:Math.round(pos.x / 10) * 10, y:Math.round(pos.y / 10) * 10, data:T[type].d()};
  F.graph.nodes.push(n);
  // اتصال خودکار از گره‌ی انتخاب‌شده (اولین درگاه آزاد)
  if (from && !isTrig) { const free = portsOf(from).map(p => p[0]).find(p => !F.graph.edges.some(e => e.from === from.id && e.port === p)); if (free) F.graph.edges.push({from:from.id, port:free, to:n.id}); }
  SEL = n.id; SEL_EDGE = null; draw(); inspector(); touch();
  $('#flPal').classList.remove('open');
};
A['fl-pal-open'] = () => $('#flPal').classList.toggle('open');
A['fl-del-node'] = () => { if (!SEL) return; snapshot(); F.graph.nodes = F.graph.nodes.filter(n => n.id !== SEL); F.graph.edges = F.graph.edges.filter(e => e.from !== SEL && e.to !== SEL); SEL = null; draw(); inspector(); touch(); };
A['fl-del-edge'] = () => { if (SEL_EDGE == null) return; snapshot(); F.graph.edges.splice(SEL_EDGE, 1); SEL_EDGE = null; draw(); inspector(); touch(); };
A['fl-dup-node'] = () => { const n = F.graph.nodes.find(x => x.id === SEL); if (!n || n.type.startsWith('trigger.')) return; snapshot(); const c = JSON.parse(JSON.stringify(n)); c.id = uid(); c.x += 30; c.y += 120; F.graph.nodes.push(c); SEL = c.id; draw(); inspector(); touch(); };

/* ---------- فرم گره (پنل کناری) ---------- */
const nd = () => F.graph.nodes.find(n => n.id === SEL);
const dget = (path) => path.split('.').reduce((o, k) => (o == null ? o : o[k]), nd().data);
function dset(path, v) { const ks = path.split('.'); let o = nd().data; ks.slice(0, -1).forEach(k => { if (o[k] == null) o[k] = /^\d+$/.test(k) ? [] : {}; o = o[k]; }); o[ks[ks.length - 1]] = v; }
const fi = (path, attrs = '', cls = '') => `<input class="inp ${cls}" value="${E(dget(path) == null ? '' : dget(path))}" data-fb="${path}" data-on-input="fl-fb" ${attrs}>`;
const fta = (path, rows = 3, attrs = '') => `<div class="fl-ta"><textarea class="inp" rows="${rows}" data-fb="${path}" data-on-input="fl-fb" ${attrs}>${E(dget(path) || '')}</textarea><button type="button" class="btn btn-g btn-sm fl-var" data-act="fl-var" data-fb="${path}" title="درج متغیر">${ic('brackets-curly')}متغیر</button></div>`;
const fsel = (path, opts) => `<select class="inp" data-fb="${path}" data-on-change="fl-fb">${opts.map(o => `<option value="${E(o[0])}" ${String(dget(path) == null ? '' : dget(path)) === String(o[0]) ? 'selected' : ''}>${E(o[1])}</option>`).join('')}</select>`;
const fchk = (path, label) => `<label class="row small">${sw('fc_' + path.replace(/\./g, '_'), !!dget(path), '', 'fl-fb').replace('<input ', `<input data-fb="${path}" `)}<span>${label}</span></label>`;
const fnum = (path, min, max) => `<input class="inp ltr" type="number" min="${min}" max="${max}" value="${E(dget(path))}" data-fb="${path}" data-on-input="fl-fb" style="max-width:130px">`;
ON['fl-fb'] = el => {
  if (!nd()) return;
  const v = el.type === 'checkbox' ? el.checked : el.type === 'number' ? +el.value : el.value;
  dset(el.dataset.fb, v); touch();
  const n = nd(); const nodeEl = $(`.fl-n[data-id="${n.id}"]`);
  if (nodeEl) {
    const d = T[n.type]; let sum = ''; try { sum = d.sum(n.data); } catch (e) {}
    nodeEl.querySelector('.fl-ns').textContent = sum;
    nodeEl.querySelector('.fl-nh b').textContent = n.data.title || d.t;
    if (el.dataset.redraw) { draw(); }
  }
  if (el.dataset.reinsp) inspector();
};
A['fl-arr-add'] = el => { const arr = dget(el.dataset.fb) || []; arr.push(JSON.parse(el.dataset.tpl)); dset(el.dataset.fb, arr); touch(); draw(); inspector(); };
A['fl-arr-rm'] = el => { const arr = dget(el.dataset.fb) || []; arr.splice(+el.dataset.i, 1); snapshot(); F.graph.edges = F.graph.edges.filter(e => !(e.from === SEL && (e.port === 'case_' + el.dataset.i || e.port === 'a_' + el.dataset.i))); dset(el.dataset.fb, arr); touch(); draw(); inspector(); };
A['fl-chk-arr'] = el => { const arr = dget(el.dataset.fb) || []; const v = /^\d+$/.test(el.dataset.v) ? +el.dataset.v : el.dataset.v; const i = arr.indexOf(v); if (i > -1) arr.splice(i, 1); else arr.push(v); dset(el.dataset.fb, arr); el.classList.toggle('on'); touch(); const n = nd(); const ne = $(`.fl-n[data-id="${n.id}"] .fl-ns`); if (ne) ne.textContent = T[n.type].sum(n.data); };
A['fl-var'] = el => {
  const known = new Set();
  F.graph.nodes.forEach(n => { const d = n.data || {}; if (d.save_as) known.add(d.save_as); if (d.item_var) known.add(d.item_var); (d.sets || []).forEach(s => s.name && known.add(s.name)); });
  const items = VARS.map(v => ({t:v[1] + ` <span class="mono muted">{{${v[0]}}}</span>`, fn:() => ins('{{' + v[0] + '}}')}));
  [...known].forEach(k => items.push({t:'متغیر ' + E(k) + ` <span class="mono muted">{{vars.${E(k)}}}</span>`, fn:() => ins('{{vars.' + k + '}}')}));
  if (F.graph.nodes.some(n => n.type === 'trigger.webhook' || n.type === 'trigger.form_submit')) items.push({t:'داده‌ی ورودی <span class="mono muted">{{payload.نام_فیلد}}</span>', fn:() => ins('{{payload.')});
  function ins(s) { const ta = el.parentElement.querySelector('textarea,input'); const p = ta.selectionStart || ta.value.length; ta.value = ta.value.slice(0, p) + s + ta.value.slice(ta.selectionEnd || p); ta.focus(); ta.dispatchEvent(new Event('input', {bubbles:true})); }
  menu(el, [{h:'درج متغیر'}, ...items]);
};
ON['fl-upload'] = async el => { const f = el.files && el.files[0]; if (!f) return; const fd = new FormData(); fd.append('image', f); const r = await L().api('upload_image', fd, true); if (!r.ok) return toast(r.error, 'err'); dset(el.dataset.fb, r.rel); touch(); inspector(); };

function inspector() {
  const box = $('#flInsp'); if (!box) return;
  if (SEL_EDGE != null) {
    const e = F.graph.edges[SEL_EDGE]; if (!e) { box.innerHTML = ''; return; }
    box.innerHTML = `<div class="fl-ih"><b>اتصال</b></div><div class="fl-ib stack s12"><p class="small soft">از «${E(lbl(e.from))}» به «${E(lbl(e.to))}»${e.port !== 'out' ? ' (درگاه: ' + E((portsOf(F.graph.nodes.find(n => n.id === e.from)).find(p => p[0] === e.port) || [0, e.port])[1]) + ')' : ''}</p><button class="btn btn-d btn-sm" data-act="fl-del-edge">${ic('trash')}حذف اتصال</button></div>`;
    box.classList.add('open'); return;
  }
  const n = nd();
  if (!n) {
    const trig = F.graph.nodes.find(x => x.type.startsWith('trigger.'));
    box.innerHTML = `<div class="fl-ih"><b>سناریو</b></div><div class="fl-ib stack s12">
      ${field('توضیح', `<textarea class="inp" rows="2" data-on-input="fl-desc">${E(F.description || '')}</textarea>`)}
      <div class="callout">${ic('info')}<span>روی هر گره بزنید تا تنظیماتش اینجا باز شود. از دایره‌ی کوچک لبه‌ی چپ گره بکشید و روی گره‌ی بعدی رها کنید تا وصل شوند.</span></div>
      <div class="small soft stack s4"><span>${ic('mouse')} کشیدن زمینه: جابه‌جایی · چرخ ماوس: بزرگ‌نمایی</span><span>${ic('keyboard')} Delete: حذف · Ctrl+Z: برگرداندن · Ctrl+S: ذخیره</span></div>
      ${trig && trig.type === 'trigger.webhook' && F.id ? `<div class="well small">آدرس وب‌هوک در تنظیمات گره‌ی شروع است.</div>` : ''}</div>`;
    box.classList.remove('open'); return;
  }
  const d = T[n.type] || {t:n.type};
  const isTrig = n.type.startsWith('trigger.');
  box.innerHTML = `<div class="fl-ih"><span class="fl-ni" style="--c:${grpColor(d.g)}">${ic(d.i)}</span><b class="grow">${E(d.t)}</b>
      ${isTrig ? '' : `<button class="btn btn-g btn-ic btn-sm" data-act="fl-dup-node" title="کپی" aria-label="کپی">${ic('copy')}</button>`}<button class="btn btn-g btn-ic btn-sm" data-act="fl-del-node" title="حذف" aria-label="حذف">${ic('trash')}</button>
      <button class="btn btn-g btn-ic btn-sm only-narrow" data-act="fl-insp-close" aria-label="بستن">${ic('x')}</button></div>
    <div class="fl-ib stack s12">${field('عنوان دلخواه', fi('title', 'placeholder="' + E(d.t) + '"'))}${form(n)}</div>`;
  box.classList.add('open');
}
A['fl-insp-close'] = () => { $('#flInsp').classList.remove('open'); SEL = null; draw(); };
ON['fl-desc'] = el => { F.description = el.value; touch(); };
const lbl = id => { const n = F.graph.nodes.find(x => x.id === id); return n ? ((n.data && n.data.title) || (T[n.type] || {}).t || n.type) : id; };

function chChips(path) { const cur = dget(path) || []; return `<div class="row wrap" style="gap:6px">${CH.map(c => `<button type="button" class="fl-chip ${cur.includes(c[0]) ? 'on' : ''}" data-act="fl-chk-arr" data-fb="${path}" data-v="${c[0]}">${E(c[1])}</button>`).join('')}</div><span class="hint">هیچ‌کدام یعنی همه‌ی کانال‌ها</span>`; }
function form(n) {
  const d = n.data, t = n.type;
  const varOpts = VARS.map(v => [v[0], v[1]]).concat(customVars().map(v => ['vars.' + v, 'متغیر ' + v]));
  const tm = () => {
    switch (t) {
      case 'trigger.message_in': return `${field('کانال‌ها', chChips('channels'))}
        ${field('چه پیامی', fsel('match', [['any', 'هر پیامی'], ['contains', 'شامل کلمه‌ها'], ['exact', 'دقیقاً برابر'], ['starts', 'شروع با'], ['regex', 'الگوی regex']]).replace('data-on-change="fl-fb"', 'data-on-change="fl-fb" data-reinsp="1"'))}
        ${d.match !== 'any' ? field('کلمه‌ها', `<textarea class="inp" rows="3" data-fb="keywords" data-on-input="fl-fb" placeholder="قیمت&#10;هزینه&#10;چند">${E(d.keywords || '')}</textarea>`, 'هر خط یک کلمه؛ یکی کافی است') : ''}
        ${(META.ig_pages || []).length ? field('فقط پیج اینستاگرام', fsel('ig_account_id', [['', 'همه‌ی پیج‌ها']].concat(META.ig_pages.map(p => [p.id, p.name])))) : ''}
        ${field('زمان', fsel('hours', [['any', 'همیشه'], ['online', 'فقط در ساعت کاری'], ['offline', 'فقط خارج از ساعت کاری']]))}
        ${fchk('first_only', 'فقط اولین پیام مشتری')}${fchk('once', 'برای هر مشتری فقط یک بار')}
        ${field('فاصله‌ی دوباره اجرا (دقیقه)', fnum('cooldown', 0, 100000), 'صفر یعنی بدون محدودیت')}
        ${fchk('consume', 'بعد از شروع، پاسخ خودکار و هوش مصنوعی جواب ندهند')}`;
      case 'trigger.contact_shared': return field('کانال‌ها', chChips('channels'));
      case 'trigger.form_submit': return field('فرم', fsel('form_id', [['', 'هر فرمی']].concat((META.forms || []).map(f => [f.id, f.name]))), 'پاسخ‌ها در {{payload.a1}}، {{payload.a2}} و … در دسترس‌اند');
      case 'trigger.webhook': return `${F.id ? `<div class="copyrow"><div class="code ltr small">${E(F.hook_url + '&key=' + d.key)}</div><button class="btn btn-ic btn-sm" data-act="copy" data-text="${E(F.hook_url + '&key=' + d.key)}" aria-label="کپی">${ic('copy')}</button></div>` : '<div class="callout warn">' + ic('info') + '<span>بعد از اولین ذخیره، آدرس وب‌هوک اینجا نمایش داده می‌شود.</span></div>'}
        <p class="small soft">با POST (بدنه‌ی JSON یا فرم) فراخوانی کنید. همه‌ی فیلدها در {{payload.نام_فیلد}} در دسترس‌اند.</p>
        ${field('فیلد شماره‌ی موبایل', fi('phone_field', 'placeholder="phone"', 'ltr'), 'اگر شماره ارسال شود، مشتری پیدا و پیام‌ها برای او فرستاده می‌شود')}
        <button class="btn btn-sm" data-act="fl-newkey">${ic('arrows-clockwise')}ساخت کلید تازه</button>`;
      case 'trigger.schedule': return `${field('تکرار', fsel('every', [['minutes', 'هر چند دقیقه'], ['hourly', 'هر ساعت'], ['daily', 'هر روز'], ['weekly', 'روزهای مشخص هفته']]).replace('data-on-change="fl-fb"', 'data-on-change="fl-fb" data-reinsp="1"'))}
        ${d.every === 'minutes' ? field('هر چند دقیقه', fnum('minutes', 5, 1440)) : d.every === 'hourly' ? field('دقیقه‌ی هر ساعت', fnum('minute', 0, 59)) : field('ساعت', fi('time', 'type="time"', 'ltr'))}
        ${d.every === 'weekly' || d.every === 'daily' ? field('روزها', `<div class="row wrap" style="gap:6px">${['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'].map((x, i) => `<button type="button" class="fl-chip ${(d.weekdays || []).includes(i) ? 'on' : ''}" data-act="fl-chk-arr" data-fb="weekdays" data-v="${i}">${x}</button>`).join('')}</div>`, d.every === 'daily' ? 'هیچ‌کدام یعنی همه‌ی روزها' : '') : ''}
        ${field('برای چه کسانی', fsel('audience', [['none', 'یک بار (بدون مشتری)'], ['group', 'هر عضو یک گروه'], ['tag', 'هر مشتری با یک برچسب']]).replace('data-on-change="fl-fb"', 'data-on-change="fl-fb" data-reinsp="1"'))}
        ${d.audience === 'group' ? field('گروه', fsel('group_id', [['', 'انتخاب کنید']].concat((META.groups || []).map(g => [g.id, g.name])))) : ''}
        ${d.audience === 'tag' ? field('برچسب', fsel('tag_id', [['', 'انتخاب کنید']].concat((META.tags || []).map(g => [g.id, g.name])))) : ''}
        ${d.audience !== 'none' ? field('حداکثر نفر در هر اجرا', fnum('limit', 1, 2000)) : ''}
        <p class="small soft">زمان‌ها به وقت ایران. سناریوهای زمان‌بندی‌شده با cron اجرا می‌شوند.</p>`;
      case 'trigger.manual': return `<p class="small soft">این سناریو خودکار شروع نمی‌شود. از دکمه‌ی «اجرای آزمایشی» یا از پروفایل مشتری برای یک یا چند نفر اجرایش کنید.</p>`;

      case 'logic.if': return `${field('ترکیب شرط‌ها', fsel('join', [['and', 'همه‌ی شرط‌ها (و)'], ['or', 'حداقل یکی (یا)']]))}
        ${(d.rules || []).map((r, i) => `<div class="well stack s8"><div class="row"><b class="small grow">شرط ${fa(i + 1)}</b>${(d.rules || []).length > 1 ? `<button class="btn btn-g btn-ic btn-sm" data-act="fl-arr-rm" data-fb="rules" data-i="${i}" aria-label="حذف">${ic('x')}</button>` : ''}</div>
          ${fsel('rules.' + i + '.var', varOpts)}${fsel('rules.' + i + '.op', OPS).replace('data-on-change="fl-fb"', 'data-on-change="fl-fb" data-reinsp="1"')}${['empty', 'not_empty', 'is_true', 'is_false'].includes(r.op) ? '' : fi('rules.' + i + '.value', 'placeholder="مقدار (متغیر هم مجاز است)"')}</div>`).join('')}
        <button class="btn btn-sm" data-act="fl-arr-add" data-fb="rules" data-tpl='{"var":"text","op":"contains","value":""}'>${ic('plus')}شرط دیگر</button>`;
      case 'logic.switch': return `${field('بر اساس', fsel('var', varOpts))}
        ${(d.cases || []).map((c, i) => `<div class="well stack s8"><div class="row"><b class="small grow">حالت ${fa(i + 1)}</b><button class="btn btn-g btn-ic btn-sm" data-act="fl-arr-rm" data-fb="cases" data-i="${i}" aria-label="حذف">${ic('x')}</button></div>${fsel('cases.' + i + '.op', OPS)}${fi('cases.' + i + '.value', 'data-redraw="1" placeholder="مقدار"')}</div>`).join('')}
        <button class="btn btn-sm" data-act="fl-arr-add" data-fb="cases" data-tpl='{"op":"contains","value":""}' ${(d.cases || []).length >= 12 ? 'disabled' : ''}>${ic('plus')}حالت دیگر</button><p class="small soft">اولین حالتی که درست باشد اجرا می‌شود؛ وگرنه «هیچ‌کدام».</p>`;
      case 'logic.wait': return `${field('نوع', fsel('mode', [['delay', 'مدت مشخص'], ['until', 'تا ساعت مشخص']]).replace('data-on-change="fl-fb"', 'data-on-change="fl-fb" data-reinsp="1"'))}
        ${d.mode === 'until' ? field('ساعت', fi('time', 'type="time"', 'ltr'), 'اگر گذشته باشد، فردا همین ساعت') : `<div class="row">${fnum('amount', 1, 100000)}${fsel('unit', [['minute', 'دقیقه'], ['hour', 'ساعت'], ['day', 'روز']])}</div>`}`;
      case 'logic.wait_reply': return `${field('پیام سؤال', fta('question', 3, 'placeholder="مثلاً: شماره‌ی موبایلتان را بفرستید"'))}
        ${field('پاسخ مورد انتظار', fsel('expect', [['any', 'هر متنی'], ['number', 'عدد'], ['phone', 'شماره‌ی موبایل (به پروفایل اضافه می‌شود)'], ['email', 'ایمیل'], ['yesno', 'بله / خیر']]))}
        ${btnList()}
        ${field('ذخیره در متغیر', fi('save_as', 'placeholder="reply"', 'ltr mono'), 'بعداً با {{vars.نام}}')}
        <div class="row">${field('حداکثر انتظار (دقیقه)', fnum('timeout', 1, 43200))}${field('تکرار پرسش', fnum('retries', 0, 5))}</div>
        ${field('پیام پاسخ نامعتبر', fi('invalid_text', 'placeholder="پاسخ معتبر نیست، دوباره بفرستید"'))}`;
      case 'logic.loop': return `${field('روی چه چیزی', fsel('mode', [['count', 'تعداد مشخص'], ['split', 'تقسیم یک متن'], ['var', 'فهرست داخل متغیر (مثلاً پاسخ HTTP)']]).replace('data-on-change="fl-fb"', 'data-on-change="fl-fb" data-reinsp="1"'))}
        ${d.mode === 'count' ? field('چند بار', fnum('count', 1, 200)) : d.mode === 'split' ? field('متن', fta('text', 2)) + field('جداکننده', fi('sep', '', 'ltr')) : field('مسیر متغیر', fi('var', 'placeholder="vars.response.items"', 'ltr mono'))}
        ${field('نام متغیر هر مورد', fi('item_var', '', 'ltr mono'), 'داخل حلقه: {{vars.item}} و شماره: {{vars.index}}')}
        <p class="small soft">مسیر «هر مورد» را بکشید تا کارهای تکراری؛ وقتی شاخه تمام شد به حلقه برمی‌گردد. بعد از آخرین مورد، «پایان» اجرا می‌شود. سقف ۲۰۰ تکرار.</p>`;
      case 'logic.split': return `${(d.branches || []).map((b, i) => `<div class="row"><b class="small" style="width:24px">${String.fromCharCode(65 + i)}</b>${fnum('branches.' + i + '.weight', 0, 100).replace('data-on-input="fl-fb"', 'data-on-input="fl-fb" data-redraw="1"')}<span class="small muted">درصد</span>${(d.branches || []).length > 2 ? `<button class="btn btn-g btn-ic btn-sm" data-act="fl-arr-rm" data-fb="branches" data-i="${i}" aria-label="حذف">${ic('x')}</button>` : ''}</div>`).join('')}
        <button class="btn btn-sm" data-act="fl-arr-add" data-fb="branches" data-tpl='{"weight":50}' ${(d.branches || []).length >= 5 ? 'disabled' : ''}>${ic('plus')}شاخه</button><p class="small soft">برای آزمایش A/B؛ هر مشتری تصادفی و با این نسبت به یکی از شاخه‌ها می‌رود.</p>`;
      case 'logic.stop': return '<p class="small soft">اجرای سناریو همین‌جا تمام می‌شود.</p>';

      case 'act.send': return `${field('متن پیام', fta('text', 5))}${btnList()}
        ${field('عکس', `<div class="row"><label class="btn btn-sm" style="cursor:pointer">${ic('image')}${d.image ? 'تغییر عکس' : 'افزودن عکس'}<input type="file" class="sr" accept="image/*" data-on-change="fl-upload" data-fb="image"></label>${d.image ? `<img src="${E(d.image)}" alt="" style="width:44px;height:44px;border-radius:8px;object-fit:cover"><button class="btn btn-g btn-sm" data-act="fl-clear-img">حذف</button>` : ''}</div>`)}
        ${fchk('request_contact', 'دکمه‌ی «اشتراک شماره» (بله و تلگرام)')}
        <p class="small soft">به همان کانالی فرستاده می‌شود که مشتری از آن پیام داده (یا آخرین کانال فعالش).</p>`;
      case 'act.send_phone': return `${field('شماره', fi('phone', '', 'ltr'), '{{person.phone}} یعنی موبایل همین مشتری')}${field('متن', fta('text', 4))}${field('روش', fsel('mode', [['safir_then_sms', 'سفیر بله، اگر نشد پیامک'], ['safir_only', 'فقط سفیر بله'], ['sms_only', 'فقط پیامک']]))}`;
      case 'act.ai': return `${!META.ai_ready ? `<div class="callout warn">${ic('warning')}<span>هوش مصنوعی هنوز تنظیم نشده است.</span></div>` : ''}${field('دستور اضافه', fta('prompt', 4, 'placeholder="مثلاً: کوتاه و دوستانه جواب بده و در پایان شماره‌ی تماس بخواه"'))}
        ${fchk('history', 'گفتگوهای قبلی مشتری هم در نظر گرفته شود')}${fchk('send', 'پاسخ مستقیم برای مشتری فرستاده شود')}${field('ذخیره در متغیر', fi('save_as', '', 'ltr mono'))}
        <p class="small soft">از پایگاه دانش و اطلاعات مشتری استفاده می‌کند.</p>`;
      case 'act.tag': return `${field('کار', fsel('action', [['add', 'افزودن برچسب'], ['remove', 'حذف برچسب']]))}${field('برچسب', fi('tag', 'list="flTags" placeholder="نام برچسب"'))}<datalist id="flTags">${(META.tags || []).map(t => `<option value="${E(t.name)}">`).join('')}</datalist><span class="hint">اگر وجود نداشته باشد ساخته می‌شود؛ متغیر هم مجاز است.</span>`;
      case 'act.group': return `${field('کار', fsel('action', [['add', 'افزودن به گروه'], ['remove', 'حذف از گروه']]))}${field('گروه', fsel('group_id', [['', 'انتخاب کنید']].concat((META.groups || []).map(g => [g.id, g.name]))))}`;
      case 'act.person': return `${field('نام', fta('name', 1))}${field('موبایل', fi('phone', 'placeholder="{{vars.reply}}"', 'ltr'))}${field('افزودن به یادداشت', fta('notes', 2))}<p class="small soft">فیلدهای خالی تغییری نمی‌کنند.</p>`;
      case 'act.assign': return `${field('اپراتور', fsel('assignee_id', [['', 'بدون تغییر']].concat((META.users || []).map(u => [u.id, u.name]))))}${field('تیم', fsel('team_id', [['', 'بدون تغییر']].concat((META.teams || []).map(u => [u.id, u.name]))))}
        ${field('وضعیت', fsel('status', [['', 'بدون تغییر'], ['open', 'باز'], ['pending', 'منتظر مشتری'], ['closed', 'بسته']]))}${field('اولویت', fsel('priority', [['', 'بدون تغییر'], ['urgent', 'فوری'], ['high', 'زیاد'], ['normal', 'عادی'], ['low', 'کم']]))}`;
      case 'act.note': return `${field('متن', fta('text', 4))}${fchk('to_conversation', 'به‌عنوان یادداشت داخلی در گفتگو')}${fchk('notify', 'اعلان در پنل تیم')}<p class="small soft">برای منشن یک همکار، @نام‌کاربری بنویسید.</p>`;
      case 'act.ai_handoff': return field('کار', fsel('action', [['on', 'هوش مصنوعی جواب بدهد'], ['off', 'برگرداندن گفتگو به اپراتور']]));
      case 'data.set': return `${(d.sets || []).map((s, i) => `<div class="well stack s8"><div class="row"><b class="small grow">متغیر ${fa(i + 1)}</b>${(d.sets || []).length > 1 ? `<button class="btn btn-g btn-ic btn-sm" data-act="fl-arr-rm" data-fb="sets" data-i="${i}" aria-label="حذف">${ic('x')}</button>` : ''}</div>
          ${fi('sets.' + i + '.name', 'placeholder="نام (انگلیسی)"', 'ltr mono')}${fsel('sets.' + i + '.op', [['set', 'برابر با'], ['add', 'افزودن عدد'], ['sub', 'کم کردن عدد'], ['append', 'چسباندن متن'], ['extract', 'استخراج با regex'], ['json', 'JSON']]).replace('data-on-change="fl-fb"', 'data-on-change="fl-fb" data-reinsp="1"')}
          ${s.op === 'extract' ? fi('sets.' + i + '.from', 'placeholder="از: text یا vars.x"', 'ltr mono') : ''}<div class="fl-ta"><textarea class="inp" rows="2" data-fb="sets.${i}.value" data-on-input="fl-fb">${E(s.value || '')}</textarea><button type="button" class="btn btn-g btn-sm fl-var" data-act="fl-var" title="درج متغیر">${ic('brackets-curly')}متغیر</button></div></div>`).join('')}
        <button class="btn btn-sm" data-act="fl-arr-add" data-fb="sets" data-tpl='{"name":"","op":"set","value":""}'>${ic('plus')}متغیر دیگر</button>`;
      case 'act.http': return `<div class="row">${fsel('method', [['GET', 'GET'], ['POST', 'POST'], ['PUT', 'PUT'], ['PATCH', 'PATCH'], ['DELETE', 'DELETE']])}${fi('url', 'placeholder="https://api.example.com/leads"', 'ltr')}</div>
        ${field('هدرها', `<div class="stack s6">${(d.headers || []).map((h, i) => `<div class="row">${fi('headers.' + i + '.key', 'placeholder="Authorization"', 'ltr mono')}${fi('headers.' + i + '.value', 'placeholder="Bearer …"', 'ltr mono')}<button class="btn btn-g btn-ic btn-sm" data-act="fl-arr-rm" data-fb="headers" data-i="${i}" aria-label="حذف">${ic('x')}</button></div>`).join('')}<button class="btn btn-sm" style="align-self:flex-start" data-act="fl-arr-add" data-fb="headers" data-tpl='{"key":"","value":""}'>${ic('plus')}هدر</button></div>`)}
        ${d.method !== 'GET' ? field('بدنه', fsel('body_type', [['json', 'JSON'], ['form', 'فرم (urlencoded)']]) + fta('body', 6, 'dir="ltr" style="font-family:var(--mono)"')) : ''}
        ${field('ذخیره‌ی پاسخ در', fi('save_as', '', 'ltr mono'), 'پاسخ JSON در {{vars.نام}} و کد پاسخ در {{http.status}}')}
        <p class="small soft">درخواست به آدرس‌های داخلی شبکه‌ی سرور مجاز نیست. اگر سرور در ایران است و مقصد خارجی، برای «درخواست‌های HTTP سناریوها» پروکسی تنظیم کنید.</p>`;
      case 'act.flow': return field('سناریو', fsel('flow_id', [['', 'انتخاب کنید']].concat((META.flows || []).filter(f => f.id !== F.id).map(f => [f.id, f.name]))), 'برای همین مشتری و با همین متغیرها اجرا می‌شود');
    }
    return '';
  };
  return tm();
  function btnList() {
    return field('دکمه‌ها', `<div class="stack s6">${(d.buttons || []).map((b, i) => `<div class="row">${fi('buttons.' + i + '.text', 'placeholder="متن دکمه"')}${fi('buttons.' + i + '.url', 'placeholder="لینک (اختیاری)"', 'ltr')}<button class="btn btn-g btn-ic btn-sm" data-act="fl-arr-rm" data-fb="buttons" data-i="${i}" aria-label="حذف">${ic('x')}</button></div>`).join('')}
      <button class="btn btn-sm" style="align-self:flex-start" data-act="fl-arr-add" data-fb="buttons" data-tpl='{"text":"","url":""}' ${(d.buttons || []).length >= 8 ? 'disabled' : ''}>${ic('plus')}دکمه</button></div>`, 'دکمه بدون لینک، متنش را به‌عنوان پاسخ مشتری می‌فرستد (برای «سؤال و منتظر پاسخ» عالی است)');
  }
}
function customVars() { const s = new Set(); F.graph.nodes.forEach(n => { const d = n.data || {}; if (d.save_as) s.add(d.save_as); if (d.item_var) s.add(d.item_var); (d.sets || []).forEach(x => x.name && s.add(x.name)); }); return [...s]; }
A['fl-clear-img'] = () => { dset('image', ''); touch(); inspector(); };
A['fl-newkey'] = () => { dset('key', Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)); touch(); inspector(); toast('کلید تازه ساخته شد؛ ذخیره کنید و آدرس جدید را در سیستم مقصد بگذارید'); };

/* ---------- ذخیره، آزمایش، گزارش ---------- */
A['fl-save'] = async el => {
  if (!el) return;
  el.classList.add('loading'); el.disabled = true;
  const r = await L().api('flow_save', {id:F.id, name:F.name, description:F.description, is_active:F.is_active, graph:F.graph});
  el.classList.remove('loading'); el.disabled = false;
  if (!r.ok) return toast(r.error, 'err');
  const wasNew = !F.id;
  F.id = r.id; F.dirty = false; state();
  if (r.warnings && r.warnings.length) toast('ذخیره شد. ' + r.warnings.join(' · '), 'warn'); else toast('سناریو ذخیره شد' + (F.is_active ? ' و فعال است' : ''));
  if (wasNew) { history.replaceState(null, '', '#flows.' + F.id); const g = await L().api('flow_get', {id:F.id}); if (g.ok) F.hook_url = g.hook_url; $$('[data-act=fl-runs],[data-act=fl-test]').forEach(b => b.disabled = false); inspector(); }
};
A['fl-test'] = () => {
  if (F.dirty) return toast('اول ذخیره کنید', 'warn');
  const m = modal({title:'اجرای آزمایشی', icon:'play', body:`<div class="stack s12"><p class="small soft">سناریو حتی اگر خاموش باشد اجرا می‌شود. پیام‌ها واقعاً برای مشتری ارسال می‌شوند؛ برای آزمایش، خودتان را انتخاب کنید.</p>
    ${field('مشتری', `<input class="inp" id="flTq" placeholder="جستجوی نام یا شماره..." autocomplete="off"><div id="flTr" class="stack s4" style="margin-top:6px;max-height:200px;overflow:auto"></div>`)}
    ${field('یا همه‌ی اعضای گروه', `<select class="inp" id="flTg"><option value="">—</option>${(META.groups || []).map(g => `<option value="${g.id}">${E(g.name)}</option>`).join('')}</select>`)}
    ${field('متغیرهای اولیه (JSON، اختیاری)', '<textarea class="inp ltr mono" id="flTv" rows="2" placeholder=\'{"order":"1234"}\'></textarea>')}</div>`, foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>اجرا</button>'});
  let picked = null;
  const q = m.el.querySelector('#flTq');
  q.oninput = debounce(async () => {
    const r = await L().api('persons_list', {q:q.value.trim(), page:1});
    m.el.querySelector('#flTr').innerHTML = (r.persons || []).slice(0, 8).map(p => `<button type="button" class="li" data-pid="${p.id}" style="text-align:right">${EB.av(p.full_name || p.phone || '؟', {size:'sm'})}<span class="grow">${E(p.full_name || 'بدون نام')}</span><span class="small muted ltr">${E(p.phone || '')}</span></button>`).join('') || '<span class="small muted">پیدا نشد</span>';
  }, 250);
  m.el.addEventListener('click', e => { const b = e.target.closest('[data-pid]'); if (!b) return; picked = +b.dataset.pid; $$('[data-pid]', m.el).forEach(x => x.classList.toggle('on', x === b)); });
  m.el.querySelector('[data-ok]').onclick = async e => {
    let vars = {}; const vt = m.el.querySelector('#flTv').value.trim(); if (vt) { try { vars = JSON.parse(vt); } catch (err) { return toast('JSON متغیرها معتبر نیست', 'err'); } }
    const g = m.el.querySelector('#flTg').value;
    const r = await L().api('flow_test', {id:F.id, person_ids:picked ? [picked] : [], group_id:g ? +g : 0, vars});
    if (!r.ok) return toast(r.error, 'err');
    m.close(); toast('اجرا شد (' + fa(r.runs.length) + ' اجرا)');
    if (r.runs.length === 1) runDetail(r.runs[0], true);
  };
};
A['fl-runs'] = () => runsDrawer(F.id);
let RUNS_DW = null;
async function runsDrawer(flowId) {
  const dw = RUNS_DW = drawer({title:'اجراهای سناریو', sub:'۵۰ اجرای اخیر', size:'lg', body:`<div style="padding:16px">${EB.sk(6)}</div>`});
  const r = await L().api('flow_runs', {id:flowId, limit:50});
  const b = dw.el.querySelector('.m-b');
  if (!r.ok) { b.innerHTML = empty('warning-circle', 'بارگذاری نشد', E(r.error)); return; }
  const st = {done:['تمام شد', 'ok'], failed:['خطا', 'err'], waiting:['منتظر', 'warn'], running:['در حال اجرا', 'info']};
  b.innerHTML = `<div class="stat-row" style="padding:14px">${[['کل', r.stats.total], ['تمام‌شده', r.stats.done], ['منتظر', r.stats.waiting], ['خطا', r.stats.failed]].map(x => `<div class="well"><span class="xs muted">${x[0]}</span><b>${nf(x[1] || 0)}</b></div>`).join('')}</div>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>زمان</th><th>مشتری</th><th>وضعیت</th><th class="num">گام</th><th></th></tr></thead><tbody>${r.runs.map(x => `<tr class="clk" data-run="${x.id}"><td class="small">${E(JD.toJ(x.created_local))} ${E(fa((x.created_local || '').slice(11, 16)))}</td><td>${x.full_name ? E(x.full_name) : '<span class="muted small">بدون مشتری</span>'}<div class="xs muted">${E(x.channel || x.trigger_type || '')}</div></td><td>${pill((st[x.status] || [x.status])[0], (st[x.status] || [0, ''])[1])}${x.status === 'waiting' && x.resume_local ? `<div class="xs muted">تا ${E(fa(x.resume_local.slice(11, 16)))}${x.wait_for === 'reply' ? ' (منتظر پاسخ)' : ''}</div>` : ''}${x.error ? `<div class="xs" style="color:var(--err)">${E(x.error)}</div>` : ''}</td><td class="num">${fa(x.steps)}</td><td class="w1">${ic('caret-left', 'muted')}</td></tr>`).join('') || `<tr><td colspan="5">${empty('play', 'هنوز اجرایی نیست', '')}</td></tr>`}</tbody></table></div>`;
  b.addEventListener('click', e => { const t = e.target.closest('[data-run]'); if (t) runDetail(+t.dataset.run); });
}
async function runDetail(id, fromTest) {
  const r = await L().api('flow_run', {id});
  if (!r.ok) return toast(r.error, 'err');
  const run = r.run;
  const m = modal({title:'جزئیات اجرا #' + fa(run.id), icon:'path', size:'lg', body:`<div class="stack s12">
    <div class="row wrap">${pill({done:'تمام شد', failed:'خطا', waiting:'منتظر', running:'در حال اجرا'}[run.status] || run.status, {done:'ok', failed:'err', waiting:'warn'}[run.status] || '')}${run.error ? `<span class="small" style="color:var(--err)">${E(run.error)}</span>` : ''}</div>
    <div class="tl">${(run.log || []).map(l => `<div class="tl-i ${l.e ? '' : 'a'}"><b>${E(F && F.graph ? lbl(l.n) : ((T[l.t] || {}).t || l.t))}</b>${l.p && l.p !== 'out' ? ` <span class="xs muted">→ ${E(l.p)}</span>` : ''}${l.e ? `<div class="xs" style="color:var(--err)">${E(l.e)}</div>` : ''}<div class="t">${E(fa(l.at || ''))}</div></div>`).join('') || '<span class="muted small">گامی ثبت نشده</span>'}</div>
    <details><summary class="small" style="cursor:pointer">متغیرها و داده‌ها</summary><pre class="code ltr" style="white-space:pre-wrap;max-height:260px;overflow:auto;margin-top:8px">${E(JSON.stringify(run.ctx, null, 2))}</pre></details></div>`,
    foot:`${run.status === 'waiting' ? '<button class="btn btn-d" data-cancel>توقف این اجرا</button>' : ''}${F && F.graph ? '<button class="btn" data-hl>نمایش مسیر روی نقشه</button>' : ''}<span class="ms-auto"></span><button class="btn" data-close>بستن</button>`});
  const c = m.el.querySelector('[data-cancel]'); if (c) c.onclick = async () => { await L().api('flow_run_cancel', {id}); m.close(); toast('اجرا متوقف شد'); };
  const h = m.el.querySelector('[data-hl]'); if (h) h.onclick = () => { HL = (run.log || []).map(l => l.n); const t = F.graph.nodes.find(n => n.type.startsWith('trigger.')); if (t) HL.unshift(t.id); m.close(); if (RUNS_DW) { try { RUNS_DW.close(); } catch (e) {} RUNS_DW = null; } draw(); toast('مسیر اجرا روی نقشه پررنگ شد', 'info'); setTimeout(() => { HL = null; draw(); }, 12000); };
}

/* ======================= الگوها ======================= */
const TEMPLATES = [
  {n:'خوش‌آمد و گرفتن شماره', d:'به اولین پیام هر مشتری خوش‌آمد می‌گوید، شماره‌اش را می‌گیرد و برچسب «سرنخ» می‌زند.', i:'hand-waving', c:'#1D5C4D', g:() => ({nodes:[
    {id:'t', type:'trigger.message_in', x:700, y:120, data:Object.assign(T['trigger.message_in'].d(), {first_only:true})},
    {id:'a', type:'act.send', x:420, y:120, data:{text:'سلام {{person.first_name}}، خوش آمدید 🌿\nچطور می‌توانیم کمکتان کنیم؟', buttons:[], image:'', request_contact:false}},
    {id:'b', type:'logic.wait_reply', x:140, y:120, data:{question:'برای پیگیری سریع‌تر، شماره‌ی موبایلتان را بفرستید.', buttons:[], expect:'phone', save_as:'mobile', timeout:120, retries:2, invalid_text:'شماره را به شکل ۰۹۱۲۱۲۳۴۵۶۷ بفرستید.'}},
    {id:'c', type:'act.tag', x:-140, y:60, data:{action:'add', tag:'سرنخ'}},
    {id:'d', type:'act.send', x:-420, y:60, data:{text:'ممنون! همکارانمان به‌زودی با شما تماس می‌گیرند.', buttons:[], image:'', request_contact:false}},
    {id:'e', type:'act.note', x:-140, y:260, data:{text:'مشتری شماره نداد؛ پیگیری دستی', to_conversation:true, notify:false}},
  ], edges:[{from:'t', port:'out', to:'a'}, {from:'a', port:'out', to:'b'}, {from:'b', port:'reply', to:'c'}, {from:'c', port:'out', to:'d'}, {from:'b', port:'timeout', to:'e'}]})},
  {n:'پاسخ خارج از ساعت کاری', d:'وقتی تیم آفلاین است، پاسخ مؤدبانه می‌دهد و گفتگو را برای صبح علامت می‌زند.', i:'moon', c:'#3D5A80', g:() => ({nodes:[
    {id:'t', type:'trigger.message_in', x:600, y:140, data:Object.assign(T['trigger.message_in'].d(), {hours:'offline', cooldown:240, consume:false})},
    {id:'a', type:'act.send', x:320, y:140, data:{text:'سلام! الان خارج از ساعت کاری هستیم. پیامتان ثبت شد و صبح اول وقت پاسخ می‌دهیم.', buttons:[], image:'', request_contact:false}},
    {id:'b', type:'act.assign', x:40, y:140, data:{assignee_id:'', team_id:'', status:'open', priority:'high'}},
  ], edges:[{from:'t', port:'out', to:'a'}, {from:'a', port:'out', to:'b'}]})},
  {n:'منوی پاسخ سریع با سوییچ', d:'پیام مشتری را بررسی می‌کند و برای قیمت، ارسال یا پشتیبانی پاسخ جدا می‌دهد.', i:'arrows-split', c:'#8A5A1C', g:() => ({nodes:[
    {id:'t', type:'trigger.message_in', x:720, y:200, data:T['trigger.message_in'].d()},
    {id:'s', type:'logic.switch', x:440, y:200, data:{var:'text', cases:[{op:'contains', value:'قیمت'}, {op:'contains', value:'ارسال'}, {op:'contains', value:'پشتیبانی'}]}},
    {id:'a', type:'act.send', x:120, y:20, data:{text:'فهرست قیمت‌ها: https://example.com/prices', buttons:[], image:'', request_contact:false}},
    {id:'b', type:'act.send', x:120, y:170, data:{text:'سفارش‌ها ۱ تا ۳ روز کاری ارسال می‌شوند.', buttons:[], image:'', request_contact:false}},
    {id:'c', type:'act.assign', x:120, y:320, data:{assignee_id:'', team_id:'', status:'open', priority:'high'}},
    {id:'d', type:'act.ai', x:120, y:470, data:{prompt:'کوتاه و دوستانه پاسخ بده.', history:true, save_as:'ai', send:true}},
  ], edges:[{from:'t', port:'out', to:'s'}, {from:'s', port:'case_0', to:'a'}, {from:'s', port:'case_1', to:'b'}, {from:'s', port:'case_2', to:'c'}, {from:'s', port:'default', to:'d'}]})},
  {n:'پیگیری بعد از فرم', d:'یک روز بعد از تکمیل فرم پیام پیگیری می‌فرستد و بر اساس پاسخ، به فروش می‌سپارد.', i:'list-checks', c:'#6B4F7A', g:() => ({nodes:[
    {id:'t', type:'trigger.form_submit', x:760, y:160, data:{form_id:''}},
    {id:'w', type:'logic.wait', x:500, y:160, data:{mode:'delay', amount:1, unit:'day', time:'10:00'}},
    {id:'q', type:'logic.wait_reply', x:240, y:160, data:{question:'سلام {{person.first_name}}، هنوز به راهنمایی نیاز دارید؟', buttons:[{text:'بله', url:''}, {text:'خیر', url:''}], expect:'yesno', save_as:'need', timeout:1440, retries:1, invalid_text:''}},
    {id:'i', type:'logic.if', x:-20, y:100, data:{join:'and', rules:[{var:'vars.need', op:'equals', value:'yes'}]}},
    {id:'a', type:'act.assign', x:-300, y:20, data:{assignee_id:'', team_id:'', status:'open', priority:'high'}},
    {id:'b', type:'act.tag', x:-300, y:200, data:{action:'add', tag:'بی‌نیاز'}},
  ], edges:[{from:'t', port:'out', to:'w'}, {from:'w', port:'out', to:'q'}, {from:'q', port:'reply', to:'i'}, {from:'i', port:'true', to:'a'}, {from:'i', port:'false', to:'b'}]})},
  {n:'ارسال سرنخ به CRM', d:'پیام‌های شامل «سفارش» را به سیستم دیگر (مثلاً CRM یا n8n) می‌فرستد و نتیجه را اعلام می‌کند.', i:'globe-hemisphere-west', c:'#2E6670', g:() => ({nodes:[
    {id:'t', type:'trigger.message_in', x:700, y:160, data:Object.assign(T['trigger.message_in'].d(), {match:'contains', keywords:'سفارش\nخرید'})},
    {id:'h', type:'act.http', x:420, y:160, data:T['act.http'].d()},
    {id:'a', type:'act.send', x:120, y:80, data:{text:'درخواستتان ثبت شد؛ شماره‌ی پیگیری: {{vars.response.id}}', buttons:[], image:'', request_contact:false}},
    {id:'b', type:'act.note', x:120, y:260, data:{text:'ارسال به CRM ناموفق بود: {{http.status}}', to_conversation:true, notify:true}},
  ], edges:[{from:'t', port:'out', to:'h'}, {from:'h', port:'ok', to:'a'}, {from:'h', port:'error', to:'b'}]})},
  {n:'پیام هفتگی به یک گروه', d:'هر شنبه ساعت ۱۰ برای اعضای یک گروه پیام می‌فرستد.', i:'calendar-dots', c:'#9A4E2A', g:() => ({nodes:[
    {id:'t', type:'trigger.schedule', x:600, y:160, data:Object.assign(T['trigger.schedule'].d(), {every:'weekly', time:'10:00', weekdays:[0], audience:'group'})},
    {id:'a', type:'act.send', x:320, y:160, data:{text:'سلام {{person.first_name}}، پیشنهادهای این هفته آماده است.', buttons:[{text:'دیدن پیشنهادها', url:'https://example.com/offers'}], image:'', request_contact:false}},
  ], edges:[{from:'t', port:'out', to:'a'}]})},
];
})();
