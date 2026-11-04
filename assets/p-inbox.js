/* p-inbox.js — صندوق ورودی چندکاناله: صف‌ها، فهرست، گفتگو، پروفایل؛ تخصیص و حضور همکاران؛ چیدمان قابل تنظیم */
(function(){
const {$, $$, E, fa, nf, ic, av, chi, chTxt, pill, tagHtml, miniTag, empty, A, ON, UI, reg, go, op, contact, page, toast, menu, modal, confirm, store, busy} = EB;
const L = () => window.EB_LIVE;
const pid = id => parseInt(String(id).replace(/^\D+/, ''), 10);

const VIEWS = [
  ['all','همه‌ی باز','tray'], ['mine','چت‌های من','user'], ['unassigned','بی‌مسئول','user-circle-dashed'], ['others','دست همکاران','users-three'], ['mentions','منشن‌ها','at'],
  ['sla','عقب‌افتاده (SLA)','timer'], ['ai','در دست هوش مصنوعی','sparkle'], ['starred','ستاره‌دار','star'], ['pending','منتظر مشتری','hourglass-medium'], ['snoozed','به تعویق افتاده','alarm'], ['closed','بسته‌شده','check-circle'],
];
const HOT = ['mine', 'mentions', 'unassigned', 'sla'];
const ST = {open:['باز','info','circle-dashed'], pending:['منتظر مشتری','warn','hourglass-medium'], snoozed:['تعویق','acc','alarm'], closed:['بسته','ok','check-circle']};
const PRI = {low:['کم','arrow-down'], normal:['عادی','minus'], high:['زیاد','arrow-up'], urgent:['فوری','warning-diamond']};
const S = {view:'all', ch:'', pg:'', sort:'', q:'', sel:new Set(), cur:null, mode:'reply', prof:store.get('ibProf', true), unreadOnly:false, team:null, label:null,
  list:[], page:1, pages:1, total:0, loadingList:false, thread:null, showDeleted:false, media:null, ident:0, hasMore:false, older:false, assign:null, editing:false, newPill:0};
let root, poll, tpoll, listReq = 0, thReq = 0;

/* ---------- چیدمان (عرض ستون‌ها و گزینه‌ها) ---------- */
const LAY_DEF = {nav:232, list:360, prof:320, showNav:true, dock:false, compact:false, msgFs:13.5, bubW:560};
const LIM = {nav:[170, 380], list:[260, 620], prof:[250, 520], msgFs:[12, 17], bubW:[380, 900]};
let LAY = Object.assign({}, LAY_DEF, store.get('ibLayout', null) || ((D.me && D.me.prefs && D.me.prefs.ib) || {}));
const clamp = (k, v) => Math.max(LIM[k][0], Math.min(LIM[k][1], Math.round(+v || LAY_DEF[k])));
function applyLayout() {
  if (!root) return;
  ['nav', 'list', 'prof'].forEach(k => root.style.setProperty('--w-' + k, clamp(k, LAY[k]) + 'px'));
  root.style.setProperty('--msg-fs', LAY.msgFs + 'px');
  root.style.setProperty('--bub-w', clamp('bubW', LAY.bubW) + 'px');
  root.classList.toggle('hide-nav', !LAY.showNav);
  root.classList.toggle('dock-prof', !!LAY.dock);
  root.classList.toggle('compact', !!LAY.compact);
  root.classList.toggle('editing', S.editing);
}
const saveLayout = EB.debounce(() => { store.set('ibLayout', LAY); L().api('desk_prefs', {prefs:{ib:LAY}}); }, 600);

/* ---------- داده ---------- */
async function fetchList(silent) {
  const my = ++listReq;
  if (!silent) { S.loadingList = true; renderList(); }
  const r = await L().api('desk_list', {view:S.view, channel:S.ch, ig_account_id:S.pg ? pid(S.pg) : 0, q:S.q, sort:S.sort, team:S.team ? pid(S.team) : 0, label:S.label ? pid(S.label) : 0, unread:S.unreadOnly ? 1 : 0, page:S.page});
  if (my !== listReq) return;
  S.loadingList = false;
  if (!r.ok) { S.listError = r.error; renderList(); return; }
  if (r.now) L().setNow(r.now);
  S.listError = null;
  D.counts = r.counts || D.counts;
  S.list = (r.conversations || []).map(L().mapConv).map(c => c.id);
  S.pages = r.pages || 1; S.total = r.total || 0;
  renderList(); renderNav(); updateBadges();
}

/** باز کردن/تازه کردن گفتگو. keepOlder: پیام‌های قدیمی‌تر بارگذاری‌شده حفظ نشوند مگر لازم باشد */
async function loadThread(id, silent) {
  const my = ++thReq;
  const c = D.convs.find(x => x.id === id) || {id, rid:pid(id), cid:'c' + pid(id)};
  if (!silent) { S.thread = null; S.hasMore = false; S.newPill = 0; renderThread(); renderProf(); }
  const r = await L().api('desk_thread', {person_id:pid(id), include_deleted:S.showDeleted ? 1 : 0, identity_id:S.ident || 0, limit:60});
  if (S.cur !== id || my !== thReq) return;
  if (!r.ok) { $('#ibTh').innerHTML = `<div class="ib-empty">${empty('warning-circle', 'گفتگو باز نشد', E(r.error))}</div>`; return; }
  if (r.now) L().setNow(r.now);
  const ct = L().mapContact(Object.assign({}, r.person, {orders:r.person.orders}));
  ct.aiAssigned = !!r.person.ai_assigned;
  if (!D.convs.find(x => x.id === id)) D.convs.push(Object.assign(c, {ch:L().chKey(r.reply_channel || ct.ch), status:'open', pri:'normal', labels:[], lastM:0, preview:'', unread:0, viewers:[]}));
  const conv = D.convs.find(x => x.id === id);
  const st = r.state || {};
  Object.assign(conv, {cid:ct.id, unread:0, ai:ct.aiAssigned, status:st.effective || st.status || 'open', snooze:st.snooze_label || null, assignee:st.assignee_id ? 'u' + st.assignee_id : null, team:st.team_id ? 'tm' + st.team_id : null, pri:st.priority || 'normal', starred:!!+st.starred, labels:st.labels ? String(st.labels).split(',').map(x => 't' + x) : []});
  conv.replyCh = L().chKey(r.reply_channel || conv.ch); conv.replyIdent = r.reply_identity_id || null;
  conv.aiMode = r.ai_mode; S.aiReady = r.ai_ready;
  conv.raw = {messages:r.messages || [], events:r.events || [], activity:r.activity || []};
  conv.msgs = L().buildThread(conv.raw.messages, conv.raw.events, ct, conv.raw.activity);
  S.hasMore = !!r.has_more; S.assign = r.assign || null; S.thread = conv;
  const ta = $('#cmpTa'); const keep = ta ? ta.value : '';
  const body = $('#thBody'); const nearBottom = !body || body.scrollHeight - body.scrollTop - body.clientHeight < 160;
  renderThread(silent && !nearBottom ? 'keep' : 'bottom'); renderProf(); renderList();
  if (keep && $('#cmpTa')) $('#cmpTa').value = keep;
  if (r.unread_total != null) updateBadges();
}
/** پیام‌های قدیمی‌تر (صفحه‌به‌صفحه)، بدون پرش اسکرول */
async function loadOlder() {
  const c = cur(); if (!c || !c.raw || S.older) return;
  const first = c.raw.messages[0]; if (!first) return;
  S.older = true; renderOlderBtn();
  const r = await L().api('desk_thread', {person_id:pid(c.id), before_id:first.id, include_deleted:S.showDeleted ? 1 : 0, identity_id:S.ident || 0, limit:60});
  S.older = false;
  if (!r.ok || S.cur !== c.id) { renderOlderBtn(); return; }
  const body = $('#thBody'); const h0 = body ? body.scrollHeight : 0, t0 = body ? body.scrollTop : 0;
  c.raw.messages = (r.messages || []).concat(c.raw.messages);
  const seen = new Set(c.raw.events.map(e => e.id)); c.raw.events = (r.events || []).filter(e => !seen.has(e.id)).concat(c.raw.events);
  c.raw.activity = (r.activity || []).concat(c.raw.activity);
  c.msgs = L().buildThread(c.raw.messages, c.raw.events, contact(c.cid), c.raw.activity);
  S.hasMore = !!r.has_more;
  renderThread('keep');
  const b2 = $('#thBody'); if (b2) b2.scrollTop = t0 + (b2.scrollHeight - h0);
}

/* ---------- نوار افقی قابل اسکرول (تب کانال‌ها/پیج‌ها/هویت‌ها) ---------- */
const strip = (key, inner, cls = '') => `<div class="hstrip ${cls}" data-strip="${key}"><button type="button" class="hs-btn hs-prev" data-act="hs-scroll" data-d="1" aria-label="قبلی">${ic('caret-right')}</button><div class="hs-in" role="tablist">${inner}</div><button type="button" class="hs-btn hs-next" data-act="hs-scroll" data-d="-1" aria-label="بعدی">${ic('caret-left')}</button></div>`;
const STRIP_POS = {};
function initStrips(scope) {
  $$('.hstrip', scope).forEach(st => {
    const inn = $('.hs-in', st); const key = st.dataset.strip;
    const upd = () => {
      const max = inn.scrollWidth - inn.clientWidth; const x = Math.abs(inn.scrollLeft);
      st.classList.toggle('can-prev', x > 2); st.classList.toggle('can-next', max - x > 2);
    };
    if (STRIP_POS[key] != null) inn.scrollLeft = STRIP_POS[key];
    const on = $('.on', inn);
    if (on && STRIP_POS[key] == null) on.scrollIntoView({block:'nearest', inline:'center'});
    inn.addEventListener('scroll', () => { STRIP_POS[key] = inn.scrollLeft; upd(); }, {passive:true});
    // چرخ موس عمودی → اسکرول افقی
    inn.addEventListener('wheel', e => { if (Math.abs(e.deltaY) > Math.abs(e.deltaX) && inn.scrollWidth > inn.clientWidth) { e.preventDefault(); inn.scrollBy({left:-e.deltaY, behavior:'auto'}); } }, {passive:false});
    // کشیدن با موس
    let sx = null, sl = 0, moved = false;
    inn.addEventListener('pointerdown', e => { if (e.pointerType !== 'mouse' || e.button) return; sx = e.clientX; sl = inn.scrollLeft; moved = false; });
    inn.addEventListener('pointermove', e => { if (sx == null) return; const dx = e.clientX - sx; if (Math.abs(dx) > 4) { moved = true; inn.scrollLeft = sl - dx; inn.classList.add('dragging'); } });
    const end = () => { sx = null; setTimeout(() => inn.classList.remove('dragging'), 0); };
    inn.addEventListener('pointerup', end); inn.addEventListener('pointerleave', end);
    inn.addEventListener('click', e => { if (moved) { e.stopPropagation(); e.preventDefault(); moved = false; } }, true);
    upd(); new ResizeObserver(upd).observe(inn);
  });
}
A['hs-scroll'] = el => { const inn = $('.hs-in', el.closest('.hstrip')); inn.scrollBy({left:(+el.dataset.d) * inn.clientWidth * .7, behavior:'smooth'}); };

/* ---------- برچسب‌های کمکی ---------- */
const PRI_FA = {urgent:'فوری', high:'زیاد', normal:'عادی', low:'کم'};
/** مهلت پاسخ (SLA): از اولین پیام بی‌جواب مشتری تا الان، در برابر مهلتِ اولویت همین گفتگو */
function slaChip(c) {
  if (c.sla == null || c.status !== 'open') return '';
  const left = c.sla; const target = c.slaTarget || ((D.sla || {})[c.pri] ?? 30); const waited = target - left;
  const cls = left < 0 ? 'err' : left < Math.min(10, target / 3) ? 'warn' : 'ok';
  const dur = m => m >= 1440 ? fa(Math.floor(m / 1440)) + ' روز' + (m % 1440 >= 60 ? ' و ' + fa(Math.floor(m % 1440 / 60)) + ' ساعت' : '') : m >= 60 ? fa(Math.floor(m / 60)) + ' ساعت' + (m % 60 ? ' و ' + fa(m % 60) + ' دقیقه' : '') : fa(m) + ' دقیقه';
  const short = m => m >= 1440 ? fa(Math.floor(m / 1440)) + ' روز' : m >= 60 ? fa(Math.floor(m / 60)) + ' ساعت' : fa(m) + ' دقیقه';
  const tip = `<b>مهلت پاسخ (SLA)</b><br>مشتری از ${c.waitingSince ? 'ساعت ' + E(L().clockOf(c.waitingSince)) + ' ' : ''}منتظر جواب است: <b>${dur(Math.max(0, waited))}</b>.<br>برای اولویت «${PRI_FA[c.pri] || 'عادی'}» باید حداکثر <b>${dur(target)}</b> بعد از پیام مشتری جواب داده شود.<br>${left < 0 ? `مهلت <b>${dur(-left)}</b> است که گذشته.` : `<b>${dur(left)}</b> تا پایان مهلت مانده.`}<span class="tip-note">شمارش از اولین پیامِ بی‌جواب مشتری شروع می‌شود و با اولین پیامی که برایش فرستاده شود (از اپراتور یا پاسخ خودکار) صفر می‌شود. مهلت هر اولویت در تنظیمات ← تیم‌ها، SLA و ساعت کاری.</span>`;
  return `<span class="sla ${cls}" data-tip="${E(tip)}" tabindex="0">${ic('timer')}${left < 0 ? short(-left) + ' دیرکرد' : short(left) + ' مانده'}</span>`;
}
const pageName = id => { const p = id ? page(id) : null; return p ? p.name : ''; };
const pageChip = (id, extra = '') => { const p = id ? page(id) : null; return p ? `<span class="mini-tag pg" title="${p.user ? '⁦@' + E(p.user) + '⁩' : ''}"><i class="dot" style="background:${p.color}"></i>${E(p.name)}${extra}</span>` : ''; };
function assignChip(c) {
  if (c.ai) return `<span class="as-chip ai" title="در دست هوش مصنوعی">${ic('sparkle')}AI</span>`;
  if (c.assignee) { const a = op(c.assignee); const nm = (a && a.name) || c.assigneeName || 'همکار';
    return c.assignee === D.meId ? `<span class="as-chip mine" title="مسئول: شما">${ic('user')}من</span>` : `<span class="as-chip other" title="مسئول: ${E(nm)}">${a ? av(a.name, {color:a.color, size:'xs'}) : ic('user')}<span class="ell">${E(nm)}</span></span>`; }
  return `<span class="as-chip none">بی‌مسئول</span>`;
}
function viewersHtml(vs, full) {
  if (!vs || !vs.length) return '';
  const typing = vs.filter(v => v.typing); const names = vs.map(v => v.name).join('، ');
  if (!full) return `<span class="vw ${typing.length ? 'typing' : ''}" title="${E(typing.length ? typing.map(v => v.name).join('، ') + ' در حال نوشتن' : names + ' این گفتگو را باز کرده')}">${ic(typing.length ? 'pencil-simple-line' : 'eye')}</span>`;
  return typing.length ? `${ic('pencil-simple-line')}<b>${E(typing.map(v => v.name).join('، '))}</b> در حال نوشتن پاسخ است…` : `${ic('eye')}<b>${E(names)}</b> همین الان این گفتگو را باز کرده`;
}

/* ---------- ستون ۱: صف‌ها ---------- */
function renderNav() {
  const C = D.counts || {};
  const it = (on, act, icon, label, n, hot, extra = '') => `<button class="ib-i ${on ? 'on' : ''}" data-act="${act}" ${extra}>${icon}<span class="grow ell">${label}</span>${n != null ? `<span class="n ${hot && n ? 'u' : ''}">${fa(n)}</span>` : ''}</button>`;
  const noSub = !S.team && !S.label;
  const el = $('#ibNav'); if (!el) return;
  el.innerHTML = `<span class="rz" data-rz="nav" title="برای تغییر عرض بکشید"></span><div class="ib-h"><h3>صندوق ورودی</h3><button class="btn btn-g btn-ic btn-sm ms-auto" data-act="ib-refresh" title="به‌روزرسانی">${ic('arrows-clockwise')}</button><button class="btn btn-g btn-ic btn-sm only-narrow" data-act="ib-nav-close" aria-label="بستن">${ic('x')}</button></div>
  <div class="scroll">
    ${C.building != null ? `<div class="callout" style="margin:0 2px 8px;font-size:12px">${ic('hourglass-medium')}<span>در حال آماده‌سازی فهرست گفتگوها (${fa(C.building)}٪). فقط بار اول طول می‌کشد.</span></div>` : ''}
    ${VIEWS.filter(v => v[0] !== 'others' || D.me.sees_others !== false).map(v => it(noSub && S.view === v[0], 'ib-view', ic(v[2]), v[1], C[v[0]] ?? null, HOT.includes(v[0]), `data-v="${v[0]}"`)).join('')}
    ${D.teams.length ? `<div class="ib-sec">تیم‌ها${D.me.is_admin ? `<button data-act="go" data-to="settings.routing" title="مدیریت تیم‌ها">${ic('gear-six')}</button>` : ''}</div>
    ${D.teams.map(t => it(S.team === t.id, 'ib-team', `<i class="dot" style="background:${t.color}"></i>`, E(t.name), (C.teams || {})[t.rid] || 0, false, `data-v="${t.id}"`)).join('')}` : ''}
    <div class="ib-sec">برچسب‌ها${D.pageAllow.includes('groups') ? `<button data-act="go" data-to="groups" title="مدیریت برچسب‌ها">${ic('gear-six')}</button>` : ''}</div>
    ${D.tags.length ? D.tags.map(t => it(S.label === t.id, 'ib-label', `<i class="dot" style="background:${t.color}"></i>`, E(t.name), null, false, `data-v="${t.id}"`)).join('') : '<div class="muted xs" style="padding:4px 9px">برچسبی تعریف نشده</div>'}
    <div class="ib-sec">تیم پشتیبانی</div>
    <button class="ib-i" data-act="ib-transfer-all">${ic('arrows-left-right')}<span class="grow ell">سپردن چت‌های من (پایان شیفت)</span>${C.mine ? `<span class="n">${fa(C.mine)}</span>` : ''}</button>
    <button class="ib-i" data-act="ib-agent-stats">${ic('chart-bar')}<span class="grow ell">آمار پاسخ‌گویی همکاران</span></button>
  </div>`;
}

/* ---------- ستون ۲: فهرست ---------- */
function viewTitle() { if (S.team) return 'تیم ' + E((D.teams.find(t => t.id === S.team) || {}).name || ''); if (S.label) return 'برچسب ' + E((D.tags.find(t => t.id === S.label) || {}).name || ''); return VIEWS.find(v => v[0] === S.view)[1]; }
function igPagesForTabs() {
  const cnt = (D.counts || {}).pages || {};
  return D.pages.filter(p => p.active || cnt[p.rid]);
}
function renderList() {
  const el = $('#ibList'); if (!el) return;
  const C = D.counts || {}; const chc = C.channels || {};
  const chs = ['instagram','webchat','telegram','bale','roubika','safir','sms','email'].filter(ch => chc[ch] || (D.channelsOn || {})[ch]);
  const L2 = S.list.map(id => D.convs.find(c => c.id === id)).filter(Boolean);
  const pages = igPagesForTabs(); const pc = C.pages || {};
  const sel = S.sel.size;
  const cnt = n => n ? `<i>${fa(n.n)}</i>${n.u ? `<b class="u" title="${fa(n.u)} گفتگوی خوانده‌نشده">${fa(n.u)}</b>` : ''}` : '';
  const chTab = (v, label, n) => `<button class="${S.ch === v ? 'on' : ''}" data-act="ib-ch" data-v="${v}">${label}${cnt(n)}</button>`;
  el.innerHTML = `
  <span class="rz" data-rz="list" title="برای تغییر عرض فهرست بکشید (دوبار کلیک: اندازه‌ی پیش‌فرض)"></span>
  <div class="ib-lh">
    <div class="row"><button class="btn btn-g btn-ic btn-sm only-narrow" data-act="ib-nav-open" aria-label="صف‌ها">${ic('sidebar')}</button><h3 class="ell">${viewTitle()}</h3><span class="muted small tnum">${fa(S.total)}</span>
      <div class="ms-auto row" style="gap:2px"><button class="btn btn-g btn-ic btn-sm" data-act="ib-select" title="انتخاب چندتایی">${ic('check-square')}</button><button class="btn btn-g btn-ic btn-sm" data-act="ib-sort" data-pop title="مرتب‌سازی">${ic('sort-descending')}</button><button class="btn btn-g btn-ic btn-sm" data-act="ib-more" data-pop title="نمایش و چیدمان">${ic('sliders-horizontal')}</button></div></div>
    <div class="inp-wrap">${ic('magnifying-glass')}<input class="inp inp-sm" id="ibQ" placeholder="جستجو با نام، شماره یا یوزرنیم" value="${E(S.q)}" data-on-input="ib-q"></div>
    ${strip('ch', chTab('', 'همه', {n:C.all || 0, u:C.unread_convs || 0}) + chs.map(ch => chTab(ch, ic(D.CH[ch].icon) + D.CH[ch].name, chc[ch])).join(''), 'ib-chtabs')}
    ${S.ch === 'instagram' && pages.length > 1 ? strip('pg', `<button class="${!S.pg ? 'on' : ''}" data-act="ib-pg" data-v="">همه‌ی پیج‌ها</button>` + pages.map(p => { const n = pc[p.rid]; return `<button class="${S.pg === p.id ? 'on' : ''}" data-act="ib-pg" data-v="${p.id}" title="${p.user ? '⁦@' + E(p.user) + '⁩' : ''}"><i class="dot" style="background:${p.color}"></i>${E(p.name)}${cnt(n)}</button>`; }).join(''), 'ib-chtabs pg-tabs') : ''}
  </div>
  ${sel ? `<div class="bulkbar"><b>${fa(sel)} انتخاب شده</b><button class="btn btn-sm" data-act="bulk-assign" data-pop>${ic('user-switch')}سپردن</button><button class="btn btn-sm" data-act="bulk-label" data-pop>${ic('tag')}برچسب</button><button class="btn btn-sm" data-act="bulk-close">${ic('check')}بستن</button><button class="btn btn-g btn-sm ms-auto" data-act="bulk-clear">لغو</button></div>` : ''}
  <div class="scroll" style="flex:1" id="ibItems">${S.loadingList && !L2.length ? Array.from({length:6}, () => `<div class="cv"><span class="av sk"></span><div class="body">${EB.sk(2)}</div></div>`).join('') :
    S.listError ? empty('warning-circle', 'فهرست بارگذاری نشد', E(S.listError), '<button class="btn btn-sm" data-act="ib-refresh">تلاش دوباره</button>') :
    L2.length ? L2.map(item).join('') + (S.pages > 1 ? `<div class="pager"><span>صفحه ${fa(S.page)} از ${fa(S.pages)}</span><div class="ms-auto"><button class="btn btn-sm" data-act="ib-page" data-v="${S.page - 1}" ${S.page <= 1 ? 'disabled' : ''}>قبلی</button><button class="btn btn-sm" data-act="ib-page" data-v="${S.page + 1}" ${S.page >= S.pages ? 'disabled' : ''}>بعدی</button></div></div>` : '')
    : empty(S.q ? 'magnifying-glass' : 'tray', S.q ? 'گفتگویی پیدا نشد' : 'این صف خالی است', S.q ? 'عبارت دیگری را امتحان کنید یا فیلتر کانال را بردارید.' : 'گفتگوی تازه‌ای که با این صف جور باشد اینجا می‌نشیند.', S.q || S.ch || S.unreadOnly ? '<button class="btn btn-sm" data-act="ib-clear">پاک کردن فیلترها</button>' : '')}</div>`;
  el.classList.toggle('selecting', sel > 0);
  initStrips(el);
}
function item(c) {
  const p = contact(c.cid) || {name:'مشتری'};
  const who = c.lastDir === 'out' ? 'شما: ' : c.lastDir === 'bot' ? (L().SRC[c.lastSrc] || 'بات') + ': ' : '';
  return `<div class="cv ${c.id === S.cur ? 'on' : ''} ${c.unread ? 'unread' : ''}" data-act="ib-open" data-id="${c.id}" role="button" tabindex="0">
    <input type="checkbox" class="cb" data-act="ib-check" data-id="${c.id}" ${S.sel.has(c.id) ? 'checked' : ''} aria-label="انتخاب">
    <span class="av-w">${av(p.name, {ch:c.ch})}${viewersHtml(c.viewers)}</span>
    <div class="body">
      <div class="l1"><span class="nm ell">${E(p.name)}</span>${c.starred ? `<span style="color:var(--gold)" title="ستاره‌دار">${ic('star')}</span>` : ''}${c.pri === 'urgent' || c.pri === 'high' ? `<span class="pri ${c.pri}" title="اولویت ${PRI[c.pri][0]}">${ic(PRI[c.pri][1])}</span>` : ''}<span class="tm" title="${E(L().fullDT(c.lastAt))}">${E(L().listTime(c.lastAt))}</span></div>
      <div class="pv ell">${c.ai ? `<span style="color:var(--accent-ink)">${ic('sparkle')}</span> ` : ''}${E(who + c.preview)}</div>
      <div class="l3">${pageChip(c.page)}${slaChip(c)}${c.labels.slice(0, 2).map(miniTag).join('')}${c.status === 'snoozed' && c.snooze ? `<span class="mini-tag">${ic('alarm')}${E(c.snooze)}</span>` : ''}${c.status === 'closed' ? `<span class="mini-tag">${ic('check')}بسته</span>` : ''}${c.status === 'pending' ? `<span class="mini-tag">${ic('hourglass-medium')}منتظر</span>` : ''}
        <span class="l3-end">${assignChip(c)}${c.unread ? `<span class="ub">${fa(c.unread)}</span>` : ''}</span></div>
    </div></div>`;
}

/* ---------- نمای کلی (وقتی گفتگویی باز نیست) ---------- */
let OV = null, ovReq = 0;
async function loadOverview() {
  const my = ++ovReq;
  const r = await L().api('desk_overview');
  if (my !== ovReq || S.cur || !r.ok) return;
  if (r.now) L().setNow(r.now);
  OV = r; D.counts = r.counts || D.counts;
  const el = $('#ibOv'); if (el) el.innerHTML = overviewHtml();
}
function overviewHtml() {
  const C = (OV && OV.counts) || D.counts || {};
  if (!OV) return `<div style="padding:24px">${EB.sk(6)}</div>`;
  const tile = (n, label, icon, cls, act) => `<button class="ov-k ${cls || ''}" ${act ? `data-act="ib-view" data-v="${act}"` : ''}>${ic(icon)}<b class="tnum">${fa(n || 0)}</b><span>${label}</span></button>`;
  const chs = Object.entries(C.channels || {}).sort((a, b) => b[1].n - a[1].n);
  const maxN = Math.max(1, ...chs.map(x => x[1].n));
  const csatAll = Object.values(OV.csat_today || {}); const rated = csatAll.reduce((a, x) => a + x.rated, 0); const avg = rated ? csatAll.reduce((a, x) => a + (x.avg || 0) * x.rated, 0) / rated : null;
  return `<div class="ov-in">
    <div class="ov-h"><div><h2>نمای کلی صندوق ورودی</h2><p class="muted small">${E(EB.todayFa())} · برای شروع، یک گفتگو را از فهرست باز کنید</p></div><button class="btn btn-sm" data-act="ib-refresh">${ic('arrows-clockwise')}به‌روزرسانی</button></div>
    <div class="ov-kpis">
      ${tile(C.all, 'گفتگوی باز', 'tray', '', 'all')}
      ${tile(C.unread_convs, 'خوانده‌نشده', 'envelope-simple', C.unread_convs ? 'hot' : '', 'all')}
      ${tile(C.unassigned, 'بی‌مسئول', 'user-circle-dashed', C.unassigned ? 'warn' : '', 'unassigned')}
      ${tile(C.sla, 'عقب از مهلت پاسخ', 'timer', C.sla ? 'err' : '', 'sla')}
      ${tile(C.mine, 'چت‌های من', 'user', '', 'mine')}
      ${tile(OV.convs_today, 'گفتگوی امروز', 'calendar-dots', '')}
    </div>
    <div class="ov-grid">
      <div class="card"><div class="card-h"><h3>${ic('hourglass-medium')}منتظرترین‌ها</h3><span class="muted xs">بیشترین زمان بی‌جواب</span></div>
        ${OV.waiting.length ? OV.waiting.map(w => { const c = L().mapConv(w); const p = contact(c.cid) || {name:'مشتری'}; return `<div class="li clk" data-act="ib-open" data-id="${c.id}" role="button">${av(p.name, {ch:c.ch, size:'sm'})}<div class="grow" style="min-width:0"><div class="b ell">${E(p.name)}</div><div class="xs muted ell">${E(c.preview)}</div></div>${slaChip(c)}</div>`; }).join('') : `<div class="card-b muted small">${ic('check-circle')} همه‌ی پیام‌ها جواب گرفته‌اند</div>`}</div>
      <div class="card"><div class="card-h"><h3>${ic('chats-circle')}کانال‌ها</h3><span class="muted xs">گفتگوی باز / خوانده‌نشده</span></div><div class="card-b">
        ${chs.length ? chs.map(([k, v]) => `<button class="ov-bar" data-act="ib-ch" data-v="${E(k)}"><span class="row" style="gap:6px;min-width:110px">${D.CH[k] ? ic(D.CH[k].icon) + D.CH[k].name : E(k)}</span><span class="bar"><i style="width:${Math.round(v.n * 100 / maxN)}%"></i></span><b class="tnum">${fa(v.n)}</b>${v.u ? `<span class="pill err" style="height:20px">${fa(v.u)} نو</span>` : ''}</button>`).join('') : '<span class="muted small">گفتگوی بازی نیست</span>'}</div></div>
      ${OV.agents && OV.agents.length ? `<div class="card"><div class="card-h"><h3>${ic('users-three')}همکاران</h3><span class="muted xs">چت‌های بر عهده</span></div>${OV.agents.map(a => { const o = D.ops.find(x => x.rid === a.id); return `<div class="li">${av(a.username, {color:o && o.color, size:'sm', st:o ? o.status : (a.online ? 'online' : 'offline')})}<div class="grow"><div class="b">${E(a.username)}</div><div class="xs muted">${({admin:'مدیر', supervisor:'سرپرست'})[a.role] || 'پشتیبان'}</div></div><b class="tnum">${fa(a.open)}</b></div>`; }).join('')}</div>` : ''}
      <div class="card"><div class="card-h"><h3>${ic('chart-bar')}امروزِ شما</h3></div><div class="card-b"><dl class="kv"><dt>پیام پاسخ</dt><dd class="tnum">${fa(OV.me_today.replies)}</dd><dt>گفتگوی پاسخ‌داده</dt><dd class="tnum">${fa(OV.me_today.chats)}</dd>${avg != null ? `<dt>رضایت مشتری امروز</dt><dd>${'⭐'.repeat(Math.round(avg))} <b class="tnum">${fa(avg.toFixed(1))}</b> <span class="muted xs">(${fa(rated)} امتیاز)</span></dd>` : ''}</dl>
        <button class="btn btn-sm btn-g" data-act="go" data-to="team">${ic('chart-line-up')}گزارش کامل عملکرد</button></div></div>
    </div></div>`;
}

/* ---------- ستون ۳: گفتگو ---------- */
function identStrip(c, p) {
  const ids = (p.idents || []).filter(i => i.id && (i.nin || i.nout));
  if (ids.length < 2) return '';
  const lab = i => `${ic(D.CH[i.ch].icon)}${i.page && pageName(i.page) ? E(pageName(i.page)) : D.CH[i.ch].name}<span class="hd ltr">${E(i.handle)}</span>`;
  return strip('id' + c.id, `<button class="${!S.ident ? 'on' : ''}" data-act="th-ident" data-v="0">${ic('stack')}همه‌ی پیام‌ها</button>` + ids.map(i => `<button class="${S.ident === i.id ? 'on' : ''}" data-act="th-ident" data-v="${i.id}" title="${E(D.CH[i.ch].name + (i.lastAt ? ' · آخرین پیام: ' + L().fullDT(i.lastAt) : ''))}">${lab(i)}<i>${fa(i.nin + i.nout)}</i></button>`).join(''), 'ib-chtabs id-tabs');
}
function assignBar(c) {
  const a = S.assign; if (!a || !a.ready) return '';
  if (a.assigned_user_id && !a.mine) {
    return `<div class="assign-bar ${a.can_reply ? 'soft' : ''}">${ic('user-focus')}<span><b>${E(a.assigned_name || 'همکار')}</b> مسئول این گفتگوست${a.assigned_at ? ` <span class="muted xs">از ${E(L().fullDT(a.assigned_at))}</span>` : ''}${a.can_reply ? ' · شما به‌عنوان مدیر می‌توانید پاسخ دهید' : ''}</span><div class="acts">${a.agent_lock ? '' : `<button class="btn btn-sm btn-p" data-act="th-claim">${ic('hand-grabbing')}بر عهده گرفتن</button>`}</div></div>`;
  }
  return '';
}
function renderThread(scroll = 'bottom') {
  const th = $('#ibTh'); if (!th) return;
  const c = D.convs.find(x => x.id === S.cur);
  if (!S.cur) { th.innerHTML = `<span class="rz rz-l" data-rz="prof"></span><div class="ib-ov scroll" id="ibOv">${overviewHtml()}</div>${editBar()}`; loadOverview(); return; }
  if (!S.thread || !c || !c.msgs) { th.innerHTML = `<div class="th-h">${EB.sk(1)}</div><div class="th-body">${Array.from({length:5}, (_, i) => `<div class="msg ${i % 2 ? 'out' : 'in'}"><div class="bub sk" style="width:${[220, 180, 260, 140, 200][i]}px;height:44px"></div></div>`).join('')}</div>`; return; }
  const p = contact(c.cid); const st = ST[c.status] || ST.open;
  const a = c.assignee ? op(c.assignee) : null;
  const replyIdent = (p.idents || []).find(i => i.id === (S.ident || c.replyIdent));
  const pgLabel = replyIdent && replyIdent.page ? pageName(replyIdent.page) : (c.page ? pageName(c.page) : '');
  const prevBody = $('#thBody'); const prevTop = prevBody ? prevBody.scrollTop : 0;
  th.innerHTML = `
  <span class="rz rz-l" data-rz="prof" title="برای تغییر عرض پروفایل بکشید"></span>
  <div class="th-h">
    <button class="btn btn-g btn-ic btn-sm ib-back" data-act="ib-back" aria-label="بازگشت">${ic('arrow-right')}</button>
    <div class="who">${av(p.name, {ch:c.ch})}<div style="min-width:0"><div class="row" style="gap:6px"><b class="ell">${E(p.name)}</b>${p.vip ? pill('VIP', 'gold', 'crown-simple') : ''}${p.member ? '' : pill('ناشناس', '')}</div><div class="xs muted row" style="gap:6px">${chTxt(c.replyCh || c.ch)}${pgLabel ? `<span>· ${E(pgLabel)}</span>` : ''}${p.phone ? `<span class="hide-m">· ${fa(p.phone)}</span>` : ''}</div></div></div>
    <div class="acts">
      <button class="assign" data-act="th-assign" data-pop title="مسئول چت (A)">${c.ai ? ic('sparkle') + '<span class="hide-m">AI</span>' : a ? av(a.name, {color:a.color, size:'xs'}) + `<span class="ell hide-m" style="max-width:110px">${E(a.id === D.meId ? 'من' : a.name)}</span>` : ic('user-circle-dashed') + '<span class="hide-m">بی‌مسئول</span>'}${ic('caret-down')}</button>
      <button class="stbtn pill ${st[1]}" style="height:var(--h-sm);border-radius:var(--r)" data-act="th-status" data-pop>${ic(st[2])}<span class="hide-m">${st[0]}</span>${ic('caret-down')}</button>
      <button class="btn btn-sm hide-m" data-act="th-snooze" data-pop title="تعویق">${ic('alarm')}</button>
      <button class="btn btn-sm ${c.status === 'closed' ? '' : 'btn-p'}" data-act="th-close" title="${c.status === 'closed' ? 'بازگشایی' : 'بستن (E)' + (D.csat && D.csat.enabled && D.csat.auto_close ? ' · نظرسنجی رضایت هم برای مشتری می‌رود' : '')}">${ic(c.status === 'closed' ? 'arrow-counter-clockwise' : 'check')}<span class="hide-m">${c.status === 'closed' ? 'بازگشایی' : 'بستن'}</span></button>
      <button class="btn btn-g btn-ic btn-sm" data-act="th-more" data-pop aria-label="بیشتر">${ic('dots-three-vertical')}</button>
      <button class="btn btn-g btn-ic btn-sm" data-act="ib-prof" title="پروفایل مشتری" aria-label="پروفایل">${ic('sidebar-simple')}</button>
      <button class="btn btn-g btn-ic btn-sm hide-m" data-act="ib-close-th" title="بستن این پنجره و بازگشت به نمای کلی صندوق (Esc)" aria-label="بازگشت به نمای کلی">${ic('x')}</button>
    </div>
  </div>
  ${assignBar(c)}
  ${identStrip(c, p)}
  ${c.ai ? `<div class="callout" style="border-radius:0;background:var(--accent-soft)">${ic('sparkle')}<span>این گفتگو به هوش مصنوعی سپرده شده و خودش پاسخ می‌دهد.</span><div class="acts"><button class="btn btn-sm" data-act="th-ai-off">بازگرداندن به انسان</button></div></div>` : ''}
  ${p.blocked ? `<div class="callout err" style="border-radius:0">${ic('prohibit')}<span>این مشتری بلاک است. پیام‌هایش ثبت می‌شود ولی پاسخ خودکار نمی‌گیرد.</span><div class="acts"><button class="btn btn-sm" data-act="th-unblock">رفع بلاک و فیلترها</button></div></div>`
    : p.filter && p.filter.filtered ? `<div class="callout warn" style="border-radius:0">${ic('funnel')}<span><b>این مشتری فیلتر شده:</b> ${Object.values(p.filter.reasons || {}).map(E).join('، ')}</span><div class="acts"><button class="btn btn-sm" data-act="th-unblock">رفع فیلتر</button></div></div>` : ''}
  <div class="th-body scroll" id="thBody"><div id="olderBox"></div>${c.msgs.length ? msgsHtml(c) : empty('chat-circle', 'پیامی نیست', S.ident ? 'از این حساب پیامی ثبت نشده.' : 'هنوز پیامی در این گفتگو ثبت نشده است.')}</div>
  ${S.newPill ? `<button class="new-pill" data-act="th-jump">${ic('arrow-down')}${fa(S.newPill)} پیام تازه</button>` : ''}
  <div class="viewers" id="thViewers">${S.assign && S.assign.viewers && S.assign.viewers.length ? viewersHtml(S.assign.viewers, true) : ''}</div>
  ${composer(c, replyIdent)}
  ${editBar()}`;
  renderOlderBtn();
  initStrips(th);
  const b = $('#thBody');
  if (scroll === 'keep') b.scrollTop = prevTop; else b.scrollTop = b.scrollHeight;
  b.addEventListener('scroll', () => { if (b.scrollTop < 60 && S.hasMore && !S.older) loadOlder(); if (S.newPill && b.scrollHeight - b.scrollTop - b.clientHeight < 80) { S.newPill = 0; const np = $('.new-pill', th); np && np.remove(); } }, {passive:true});
}
function renderOlderBtn() {
  const box = $('#olderBox'); if (!box) return;
  box.innerHTML = S.hasMore ? `<div class="older"><button class="btn btn-sm btn-g" data-act="th-older" ${S.older ? 'disabled' : ''}>${S.older ? '<span class="spin"></span> در حال بارگذاری…' : ic('arrow-up') + 'پیام‌های قبلی'}</button></div>` : '';
}
function msgsHtml(c) {
  let lastDay = ''; let out = '';
  for (const m of c.msgs) {
    const day = m.ts ? L().dayLabel(m.ts) : '';
    if (day && day !== lastDay) { out += `<div class="daysep">${E(day)}</div>`; lastDay = day; }
    out += msgHtml(m, c);
  }
  return out;
}
// ۱.۰.۱۴: لینک‌های داخل پیام قابل کلیک (در تب تازه)
const linkify = h => String(h).replace(/(https?:\/\/[^\s<>"']+[^\s<>"'.,،؛:!?)\]])/g, u => `<a href="${u}" target="_blank" rel="noopener noreferrer" class="lnk" style="word-break:break-all">${u}</a>`);
function timeEl(m) { return `<time title="${E(L().fullDT(m.ts))}">${E(L().clockOf(m.ts))}</time>`; }
function msgHtml(m, c) {
  if (m.k === 'sys') return `<div class="sys">${ic(m.ic || 'info')}<span>${m.x}</span><span class="muted">· ${timeEl(m)}</span></div>`;
  if (m.k === 'cmt') return `<div class="msg in cmt"><div class="bub"><div class="src">${ic('chat-teardrop-text')}کامنت زیر پست${m.post ? ' · ' + E(String(m.post).slice(0, 40)) : ''}${m.pageName ? ' · ' + E(m.pageName) : ''}</div>${E(m.x).replace(/\n/g, '<br>')}<div class="meta">${timeEl(m)}${m.replied ? ' · ' + ic('check') + 'پاسخ داده شد' : ''}</div></div></div>`;
  const by = m.by && m.k === 'note' ? op(m.by) : null;
  if (m.k === 'note') return `<div class="msg out note">${by ? av(by.name, {color:by.color, size:'xs'}) : ''}<div class="bub"><div class="src">${ic('lock-simple')}یادداشت داخلی${by ? ' · ' + E(by.name) : ''}</div>${E(m.x).replace(/@(\S+)/g, '<b style="color:var(--accent-ink)">@$1</b>').replace(/\n/g, '<br>')}<div class="meta">${timeEl(m)} · مشتری نمی‌بیند</div></div></div>`;
  const src = m.src ? `<div class="src">${ic(m.srcIc || 'robot')}${E(m.src)}</div>` : m.comment ? `<div class="src">${ic('chat-teardrop-text')}کامنت زیر پست</div>` : '';
  const img = m.img ? `<a href="${E(m.img)}" target="_blank" rel="noopener"><img src="${E(m.img)}" alt="تصویر" loading="lazy"></a>` : '';
  const cls = m.k === 'in' ? 'in' : m.k === 'bot' ? 'out bot' : 'out' + (m.fail ? ' failed' : '');
  const who = m.k === 'in' ? av(contact(c.cid).name, {size:'xs'}) : m.k === 'bot' ? `<span class="av xs" style="background:var(--surface-3);color:var(--ink-2)">${ic(m.srcIc || 'robot')}</span>` : '';
  const del = m.deleted ? ' style="opacity:.5"' : '';
  const chLab = (m.ch && m.ch !== (c.replyCh || c.ch)) || (m.page && m.page !== c.page) ? `<span class="via-l">${ic(D.CH[m.ch] ? D.CH[m.ch].icon : 'chat-circle')}${E(m.page && pageName(m.page) ? pageName(m.page) : (D.CH[m.ch] || {}).name || '')}</span>` : '';
  const sender = m.k === 'out' && m.by ? `<span class="by">${E(m.by)}</span>` : '';
  const state = m.k === 'out' ? (m.fail ? ic('warning-circle') : m.seen ? `<span title="دیده شد">${ic('checks')}</span>` : `<span title="ارسال شد">${ic('check')}</span>`) : '';
  const edited = m.edited ? ` <span class="edited" data-tip="${E('<b>ویرایش‌شده</b> · ' + L().fullDT(m.edited) + (m.orig ? '<span class=tip-note>متن اصلی: ' + E(String(m.orig).slice(0, 300)) + '</span>' : ''))}" tabindex="0">ویرایش‌شده</span>` : '';
  const tools = m.deleted ? ` · حذف‌شده <u data-act="msg-restore" data-id="${m.id}" class="lnk">بازگرداندن</u>` : m.id && m.k !== 'bot' ? `${m.k === 'out' && m.canEdit && !m.fail ? ` <u data-act="msg-edit" data-id="${m.id}" class="lnk dim" title="ویرایش همین پیام در ${E((D.CH[m.ch] || {}).name || '')}">ویرایش</u>` : ''} <u data-act="msg-del" data-id="${m.id}" class="lnk dim" title="حذف از دید عادی">حذف</u>` : '';
  return `<div class="msg ${cls}"${del} data-mid="${m.id || ''}">${who}<div class="bub">${src}${img}${linkify(E(m.x)).replace(/\n/g, '<br>')}<div class="meta">${chLab}${sender}${timeEl(m)}${edited}${state}${m.fail ? ' ارسال نشد' + (m.err ? ': ' + E(String(m.err).slice(0, 80)) : '') : ''}${tools}</div></div></div>`;
}
function composer(c, replyIdent) {
  const p = contact(c.cid);
  const ch = replyIdent ? replyIdent.ch : (c.replyCh || c.ch);
  const via = (D.CH[ch] ? D.CH[ch].name : ch) + (replyIdent && replyIdent.page && pageName(replyIdent.page) ? ' · ' + pageName(replyIdent.page) : '');
  const locked = S.mode === 'reply' && S.assign && S.assign.ready && !S.assign.can_reply;
  return `<div class="composer ${S.mode === 'note' ? 'note-mode' : ''} ${locked ? 'locked' : ''}">
    <div class="cmp-tabs"><button class="tab ${S.mode === 'reply' ? 'on' : ''}" data-act="cmp-mode" data-v="reply">${ic('arrow-bend-up-left')}پاسخ</button><button class="tab ${S.mode === 'note' ? 'on' : ''}" data-act="cmp-mode" data-v="note">${ic('lock-simple')}یادداشت داخلی</button>
      ${S.mode === 'reply' ? `<span class="ms-auto via" title="پاسخ از همان حساب/پیجی می‌رود که مشتری آخرین بار پیام داده؛ برای تغییر، یکی از حساب‌های بالای گفتگو را انتخاب کنید">${ic((D.CH[ch] || {}).icon || 'chat-circle')}ارسال از: ${E(via)}</span>` : ''}</div>
    <div style="height:8px"></div>
    ${locked ? (S.assign.agent_lock ? `<div class="cmp-lock">${ic('robot')}<span>${E(S.assign.agent_lock)}</span></div>` : `<div class="cmp-lock">${ic('lock-simple')}<span>این گفتگو بر عهده‌ی <b>${E(S.assign.assigned_name || 'همکار دیگری')}</b> است. برای پاسخ، اول آن را بر عهده بگیرید.</span><button class="btn btn-sm btn-p" data-act="th-claim">بر عهده گرفتن</button></div>`) : `
    ${S.media ? `<div class="row small" style="margin-bottom:6px">${ic('image')}<span class="grow ell">${E(S.media.name)}</span><button class="btn btn-g btn-sm" data-act="cmp-media-x">حذف پیوست</button></div>` : ''}
    <div class="box">
      <textarea id="cmpTa" rows="2" placeholder="${S.mode === 'note' ? 'یادداشت برای تیم. با @ همکار را منشن کنید' : 'پاسخ به ' + E(p.first || p.name) + '... (/ برای پیام آماده، Ctrl+Enter ارسال)'}" data-on-input="cmp-input"></textarea>
      <div class="tools">
        ${S.mode === 'reply' ? `<label class="btn btn-g" title="پیوست عکس">${ic('paperclip')}<input type="file" accept="image/*" class="sr" data-on-change="cmp-file"></label>
        <button class="btn btn-g" data-act="cmp-canned" data-pop title="پیام آماده">${ic('lightning')}</button>
        <button class="btn btn-g" data-act="cmp-var" data-pop title="درج متغیر">${ic('brackets-curly')}</button>
        <button class="btn btn-g hide-m" data-act="cmp-emoji" data-pop title="شکلک">${ic('smiley')}</button>
        ${D.aiReady ? `<button class="ai-chip" data-act="cmp-ai">${ic('sparkle')}پیش‌نویس AI</button>` : ''}` : `<button class="btn btn-g" data-act="cmp-mention" data-pop title="منشن همکار">${ic('at')}</button>`}
        <span class="ms-auto"></span>
        <div class="btn-split"><button class="btn btn-p btn-sm" data-act="cmp-send">${ic(S.mode === 'note' ? 'note' : 'paper-plane-right')}${S.mode === 'note' ? 'ثبت یادداشت' : 'ارسال'}</button>${S.mode === 'reply' ? `<button class="btn btn-p btn-sm" data-act="cmp-send-menu" data-pop aria-label="گزینه‌های ارسال">${ic('caret-down')}</button>` : ''}</div>
      </div>
    </div>`}</div>`;
}

/* ---------- ستون ۴: پروفایل ---------- */
function renderProf() {
  const pr = $('#ibProf'); if (!pr) return;
  const c = D.convs.find(x => x.id === S.cur); if (!c || !S.thread) { pr.innerHTML = S.cur ? `<div style="padding:18px">${EB.sk(8)}</div>` : ''; return; }
  const p = contact(c.cid);
  const sec = (k, title, icon, body, closed) => `<div class="psec ${store.get('psec.' + k, closed) ? 'closed' : ''}" data-k="${k}"><button data-act="psec">${ic(icon)}${title}${ic('caret-down')}</button><div class="pb">${body}</div></div>`;
  const ordSt = {pending:['در انتظار', 'warn'], confirmed:['تأییدشده', 'info'], shipped:['ارسال‌شده', 'acc'], done:['انجام‌شده', 'ok'], canceled:['لغوشده', 'err']};
  const a = S.assign || {};
  const hist = (a.history || []).slice(0, 6);
  const HACT = {auto:'اولین پاسخ', claim:'بر عهده گرفت', takeover:'از همکار گرفت', transfer:'سپرد به', release:'رها کرد', to_ai:'سپرد به AI'};
  pr.innerHTML = `<div class="scroll" style="flex:1">
    <div class="p-top"><button class="btn btn-g btn-ic btn-sm prof-x" style="align-self:flex-start" data-act="ib-prof" aria-label="بستن">${ic('x')}</button>${av(p.name, {size:'lg'})}<h3>${E(p.name)}</h3>
      <div class="row wrap" style="justify-content:center">${p.member ? pill('عضو', 'ok', 'seal-check') : pill('ناشناس', '', 'question')}${p.vip ? pill('VIP', 'gold', 'crown-simple') : ''}${p.blocked ? pill('بلاک', 'err') : ''}</div>
      <div class="muted small">${p.phone ? fa(p.phone) : 'بدون شماره'}</div>
      <div class="p-acts">${D.pageAllow.includes('persons') ? `<button class="btn btn-sm" data-act="person-open" data-id="${p.id}">${ic('user-focus')}پروفایل کامل</button>` : ''}${p.phone ? `<button class="btn btn-sm btn-ic" data-act="copy" data-text="${E(p.phone)}" title="کپی شماره">${ic('copy')}</button>` : ''}</div>
      <div class="grid g3" style="width:100%;gap:6px;margin-top:8px;text-align:center">${[['ارسالی', fa(p.sent)], ['دریافتی', fa(p.recv)], ['سفارش', fa(p.orders)]].map(x => `<div class="well" style="padding:7px 4px"><b class="tnum" style="font-size:13px">${x[1]}</b><div class="xs muted">${x[0]}</div></div>`).join('')}</div>
    </div>
    ${sec('conv', 'این گفتگو', 'chat-circle-text', `<dl class="kv"><dt>وضعیت</dt><dd>${pill((ST[c.status] || ST.open)[0], (ST[c.status] || ST.open)[1])}</dd><dt>مسئول چت</dt><dd>${c.ai ? 'هوش مصنوعی' : a.assigned_name ? E(a.assigned_name) : '<span class="muted">ندارد</span>'}</dd>${a.first_responder_name ? `<dt>شروع‌کننده</dt><dd>${E(a.first_responder_name)}</dd>` : ''}<dt>تیم</dt><dd>${c.team && D.teams.find(t => t.id === c.team) ? E(D.teams.find(t => t.id === c.team).name) : '<span class="muted">-</span>'}</dd><dt>اولویت</dt><dd><button class="tag" data-act="th-pri" data-pop>${ic(PRI[c.pri][1])}${PRI[c.pri][0]}</button></dd><dt>مهلت پاسخ</dt><dd>${slaChip(c) || '<span class="muted">-</span>'}</dd></dl>
      <div class="lab" style="margin-top:6px">برچسب‌های گفتگو</div><div class="row wrap">${c.labels.map(t => tagHtml(t, 'conv-untag')).join('')}${D.tags.length ? `<button class="tag tag-add" data-act="conv-tag" data-pop>${ic('plus')}برچسب</button>` : '<span class="muted xs">ابتدا در «گروه و برچسب» برچسب بسازید</span>'}</div>
      ${hist.length ? `<div class="lab" style="margin-top:10px">سابقه‌ی پاسخ‌گویی</div><div class="hist">${hist.map(h => `<div><span>${E(h.by_name || 'سیستم')} ${HACT[h.action] || h.action}${h.to_name && ['transfer'].includes(h.action) ? ' ' + E(h.to_name) : ''}${h.note ? ` <span class="muted">«${E(h.note)}»</span>` : ''}</span><time title="${E(L().fullDT(h.created_at))}">${E(L().listTime(h.created_at))}</time></div>`).join('')}</div>` : ''}`)}
    ${EB.crmBox && (window.EB_BOOT || {}).crm && EB_BOOT.crm.ready ? sec('crm', 'سوابق CRM (تماس، سرنخ، خرید)', 'phone-call', EB.crmBox(p.phone, p.rid)) : ''}
    ${sec('ids', 'حساب‌ها در کانال‌ها', 'fingerprint', (p.idents.length ? p.idents.map(i => `<div class="ident ${S.ident === i.id ? 'on' : ''}" ${i.id ? `data-act="th-ident" data-v="${S.ident === i.id ? 0 : i.id}" role="button" title="نمایش فقط پیام‌های این حساب"` : ''}>${chi(i.ch)}<div class="grow" style="min-width:0"><div class="ell b ltr" style="text-align:right">${E(i.handle)}</div><div class="xs muted">${D.CH[i.ch].name}${i.page && pageName(i.page) ? ' · ' + E(pageName(i.page)) : ''}${i.nin || i.nout ? ` · ${fa(i.nin)} دریافتی / ${fa(i.nout)} ارسالی` : ''}</div>${i.lastAt ? `<div class="xs muted">آخرین پیام: ${E(L().fullDT(i.lastAt))}</div>` : ''}${i.followers != null ? `<div class="xs muted">${fa(nf(i.followers))} دنبال‌کننده${i.isFollower != null ? ' · ' + (i.isFollower ? 'پیج را دنبال می‌کند' : 'دنبال نمی‌کند') : ''}</div>` : ''}</div>${i.url ? `<a class="btn btn-g btn-ic btn-sm" href="${E(i.url)}" target="_blank" rel="noopener" title="پروفایل در ${D.CH[i.ch].name}">${ic('arrow-square-out')}</a>` : ''}</div>`).join('') : '<span class="muted small">هویتی ثبت نشده</span>') + `<button class="btn btn-sm btn-g" data-act="th-refresh-prof">${ic('arrows-clockwise')}به‌روزرسانی نام و یوزرنیم از شبکه</button>`)}
    ${sec('grp', 'گروه و برچسب مشتری', 'users-three', `<div class="row wrap">${p.groups.map(g => tagHtml(g, 'p-ungroup')).join('')}<button class="tag tag-add" data-act="p-group" data-pop>${ic('plus')}گروه</button></div><div class="row wrap">${p.tags.map(t => tagHtml(t, 'p-untag')).join('')}<button class="tag tag-add" data-act="p-tag" data-pop>${ic('plus')}برچسب</button></div>`)}
    ${p.ordersList && p.ordersList.length ? sec('ord', 'سفارش‌ها', 'package', p.ordersList.map(o => `<div class="ident"><div class="grow"><div class="b">#${fa(o.id)} · ${nf(+o.total)} <span class="xs muted">تومان</span></div><div class="xs muted">${L().faDate(o.created_at)}</div></div>${pill((ordSt[o.status] || ['', ''])[0], (ordSt[o.status] || ['', ''])[1])}</div>`).join('')) : ''}
    ${p.forms && p.forms.length ? sec('frm', 'پاسخ فرم‌ها', 'list-checks', p.forms.map(f => `<div class="ident"><div class="grow"><div class="b small">${E(f.form_name || 'فرم')}</div><div class="xs muted">${L().faDate(f.created_at)}</div></div></div>`).join(''), true) : ''}
    ${sec('note', 'یادداشت داخلی مشتری', 'note', `<textarea class="inp" rows="3" placeholder="فقط تیم می‌بیند؛ هوش مصنوعی هم از آن استفاده می‌کند" data-on-change="p-note" data-id="${p.id}">${E(p.note)}</textarea>`)}
  </div>`;
}

/* ---------- ویرایش زنده‌ی چیدمان ---------- */
function editBar() {
  if (!S.editing) return '';
  const rng = (k, min, max, step, label, unit) => `<label class="eb-f"><span>${label}</span><input type="range" min="${min}" max="${max}" step="${step}" value="${LAY[k]}" data-on-input="lay-range" data-k="${k}"><b>${fa(LAY[k])}${unit}</b></label>`;
  const chk = (k, label) => `<label class="eb-f chk"><input type="checkbox" ${LAY[k] ? 'checked' : ''} data-on-change="lay-check" data-k="${k}"><span>${label}</span></label>`;
  return `<div class="ib-editbar" role="dialog" aria-label="ویرایش چیدمان">
    <div class="eb-h">${ic('layout')}<b>ویرایش چیدمان</b><span class="muted xs">لبه‌ی ستون‌ها را بکشید تا پهن یا باریک شوند</span><button class="btn btn-sm btn-p ms-auto" data-act="lay-done">${ic('check')}تمام</button></div>
    <div class="eb-b">
      ${chk('showNav', 'ستون صف‌ها نمایش داده شود')}
      ${chk('dock', 'پروفایل مشتری همیشه کنار گفتگو باشد')}
      ${chk('compact', 'فهرست گفتگو فشرده')}
      ${rng('msgFs', 12, 17, .5, 'اندازه‌ی متن پیام‌ها', '')}
      ${rng('bubW', 380, 900, 20, 'بیشترین عرض پیام', '')}
      <div class="eb-f"><span>عرض ستون‌ها (پیکسل)</span><span class="tnum xs">صف‌ها ${fa(clamp('nav', LAY.nav))} · فهرست ${fa(clamp('list', LAY.list))} · پروفایل ${fa(clamp('prof', LAY.prof))}</span></div>
      <button class="btn btn-sm btn-g" data-act="lay-reset">${ic('arrow-counter-clockwise')}بازگشت به پیش‌فرض</button>
    </div></div>`;
}
function bindResize() {
  root.addEventListener('pointerdown', e => {
    const h = e.target.closest('.rz'); if (!h || !S.editing && e.pointerType === 'touch') return;
    const k = h.dataset.rz; e.preventDefault();
    const sx = e.clientX, sw = clamp(k, LAY[k]);
    // پنل‌های سمت راست (صف‌ها و فهرست) با کشیدن به چپ پهن می‌شوند؛ پروفایل (سمت چپ) با کشیدن به راست
    const dir = k === 'prof' ? 1 : -1;
    h.setPointerCapture(e.pointerId); root.classList.add('resizing');
    const mv = ev => { LAY[k] = clamp(k, sw + dir * (ev.clientX - sx)); applyLayout(); };
    const up = () => { h.removeEventListener('pointermove', mv); h.removeEventListener('pointerup', up); root.classList.remove('resizing'); saveLayout(); if (S.editing) { const eb = $('.ib-editbar'); if (eb) eb.outerHTML = editBar(); } };
    h.addEventListener('pointermove', mv); h.addEventListener('pointerup', up);
  });
  root.addEventListener('dblclick', e => { const h = e.target.closest('.rz'); if (!h) return; LAY[h.dataset.rz] = LAY_DEF[h.dataset.rz]; applyLayout(); saveLayout(); });
}
A['lay-edit'] = () => { S.editing = !S.editing; applyLayout(); renderThread('keep'); if (S.editing && innerWidth < 1100) toast('تغییر عرض ستون‌ها در صفحه‌های پهن‌تر در دسترس است؛ بقیه‌ی گزینه‌ها اینجا هم کار می‌کنند', 'info'); };
A['lay-done'] = () => { S.editing = false; applyLayout(); renderThread('keep'); toast('چیدمان ذخیره شد'); };
A['lay-reset'] = () => { LAY = Object.assign({}, LAY_DEF); applyLayout(); saveLayout(); renderThread('keep'); full(); };
ON['lay-range'] = el => { LAY[el.dataset.k] = +el.value; el.nextElementSibling.textContent = fa(el.value); applyLayout(); saveLayout(); };
ON['lay-check'] = el => { LAY[el.dataset.k] = el.checked; applyLayout(); saveLayout(); if (el.dataset.k === 'dock') { root.classList.remove('prof-open'); $('#ibProf').classList.toggle('hide', !S.prof && (innerWidth > 1600 || LAY.dock)); } };

/* ---------- رندر کامل ---------- */
function full() {
  if (!root) return;
  root.className = 'inbox' + (S.prof ? '' : ' no-prof') + (S.cur && innerWidth < 768 && S.mobileOpen ? ' th-open' : '');
  applyLayout();
  renderNav(); renderList(); renderThread(); renderProf();
  $('#ibProf').classList.toggle('hide', !S.prof && (innerWidth > 1600 || LAY.dock));
}
function updateBadges() { const n = EB.unreadTotal(); const b = $('#navInboxCnt'); if (b) b.textContent = fa(n); const m = $('.mob-nav .cnt'); if (m) m.textContent = fa(n); }
function leaveConv(id) { if (id) L().api('inbox_leave', {person_id:pid(id)}); }
function openConv(id, mobile = true) {
  if (S.cur && S.cur !== id) leaveConv(S.cur);
  S.cur = id; S.mode = 'reply'; S.media = null; S.ident = 0; S.assign = null; S.newPill = 0;
  history.replaceState(null, '', '#inbox.' + id);
  if (mobile) S.mobileOpen = true;
  root.classList.toggle('th-open', innerWidth < 768);
  renderList(); loadThread(id);
}

/* حضور و پیام تازه در گفتگوی باز (هر ۱۵ ثانیه) */
let lastTyping = 0;
async function pingThread(typing) {
  const c = cur(); if (!c || !c.raw) return;
  const last = c.raw.messages.length ? c.raw.messages[c.raw.messages.length - 1].id : 0;
  const r = await L().api('desk_ping', {person_id:pid(c.id), last_id:last, typing:typing ? 1 : 0});
  if (!r.ok || S.cur !== c.id) return;
  const before = JSON.stringify([S.assign && S.assign.assigned_user_id, S.assign && S.assign.can_reply]);
  S.assign = Object.assign(S.assign || {}, r.assign || {});
  const vw = $('#thViewers'); if (vw) vw.innerHTML = S.assign.viewers && S.assign.viewers.length ? viewersHtml(S.assign.viewers, true) : '';
  if (JSON.stringify([S.assign.assigned_user_id, S.assign.can_reply]) !== before) { loadThread(c.id, true); return; }
  if (r.new_messages > 0) {
    const b = $('#thBody'); const near = b && b.scrollHeight - b.scrollTop - b.clientHeight < 160;
    if (!near) S.newPill = r.new_messages;
    loadThread(c.id, true);
  }
}

reg('inbox', {title:'صندوق ورودی', icon:'tray', full:true, render(view, args) {
  view.innerHTML = `<div class="inbox" id="ib"><aside class="ib-nav" id="ibNav"></aside><section class="ib-list" id="ibList"></section><section class="th" id="ibTh"></section><aside class="prof" id="ibProf"></aside></div>`;
  root = $('#ib');
  applyArgs(args);
  full(); bindResize();
  // ورود به صندوق ورودی هیچ گفتگویی را خودکار باز نمی‌کند؛ نمای کلی نشان داده می‌شود
  fetchList().then(() => { if (S.cur) loadThread(S.cur); });
  clearInterval(poll); poll = setInterval(() => { if (!document.hidden && EB.current().slug === 'inbox') fetchList(true); }, 15000);
  clearInterval(tpoll); tpoll = setInterval(() => { if (!document.hidden && EB.current().slug === 'inbox' && S.cur) pingThread(false); }, 15000);
}, update(args) { const was = S.cur; applyArgs(args); if (S.cur && S.cur !== was) { S.ident = 0; renderList(); loadThread(S.cur); } else { S.page = 1; fetchList(); if (!S.cur) { root && root.classList.remove('th-open'); renderThread(); renderProf(); } } renderNav(); },
leave() { clearInterval(poll); clearInterval(tpoll); leaveConv(S.cur); root = null; }});
function applyArgs(args) {
  const a = args[0];
  if (!a || !/^v\d+$/.test(a)) { if (S.cur) leaveConv(S.cur); S.cur = null; S.thread = null; S.mobileOpen = false; }
  if (!a) return;
  if (/^v\d+$/.test(a)) { S.cur = a; S.mobileOpen = true; }
  else if (VIEWS.some(v => v[0] === a)) { S.view = a; S.team = S.label = null; S.page = 1; }
}
window.addEventListener('beforeunload', () => { if (S.cur && root) navigator.sendBeacon && navigator.sendBeacon('index.php?api=inbox_leave', new Blob([JSON.stringify({person_id:pid(S.cur), _csrf:(window.EB_BOOT || {}).csrf})], {type:'application/json'})); });

/* ---------- اکشن‌ها ---------- */
const reload = () => { S.page = 1; S.sel.clear(); fetchList(); };
A['ib-refresh'] = () => { fetchList(); if (S.cur) loadThread(S.cur, true); };
A['ib-view'] = el => { S.view = el.dataset.v; S.team = S.label = null; closeNav(); history.replaceState(null, '', '#inbox.' + S.view); reload(); renderNav(); };
A['ib-team'] = el => { S.team = el.dataset.v; S.label = null; closeNav(); reload(); renderNav(); };
A['ib-label'] = el => { S.label = el.dataset.v; S.team = null; closeNav(); reload(); renderNav(); };
A['ib-ch'] = el => { S.ch = el.dataset.v; S.pg = ''; reload(); };
A['ib-pg'] = el => { S.pg = el.dataset.v; reload(); };
A['ib-page'] = el => { S.page = Math.max(1, +el.dataset.v); fetchList(); $('#ibItems') && ($('#ibItems').scrollTop = 0); };
A['ib-clear'] = () => { S.q = ''; S.ch = ''; S.pg = ''; S.unreadOnly = false; reload(); };
ON['ib-q'] = EB.debounce(el => { S.q = el.value.trim(); const pos = el.selectionStart; reload(); setTimeout(() => { const i = $('#ibQ'); if (i) { i.focus(); i.setSelectionRange(pos, pos); } }, 0); }, 350);
A['ib-open'] = (el, e) => { if (e.target.closest('.cb')) return; openConv(el.dataset.id); };
A['ib-check'] = (el, e) => { e.stopPropagation(); el.checked ? S.sel.add(el.dataset.id) : S.sel.delete(el.dataset.id); renderList(); };
A['ib-select'] = () => { if (S.sel.size) S.sel.clear(); else S.list.forEach(id => S.sel.add(id)); renderList(); };
A['bulk-clear'] = () => { S.sel.clear(); renderList(); };
async function setState(ids, set, msg) {
  const r = await L().api('desk_set', {person_ids:ids.map(pid), set});
  if (!r.ok) { toast(r.error || 'انجام نشد', 'err'); return false; }
  if (msg) toast(msg);
  return true;
}
A['bulk-close'] = async () => { const ids = [...S.sel]; if (await setState(ids, {status:'closed'}, fa(ids.length) + ' گفتگو بسته شد')) { S.sel.clear(); fetchList(); if (ids.includes(S.cur)) loadThread(S.cur, true); } };
A['bulk-assign'] = el => menu(el, [{h:'سپردن به'}, ...D.ops.filter(o => o.active !== false).map(o => ({t:E(o.name), av:av(o.name, {color:o.color, size:'xs', st:o.status}), fn:async () => { const ids = [...S.sel]; if (await setState(ids, {assignee_id:o.rid}, fa(ids.length) + ' گفتگو به ' + E(o.name) + ' سپرده شد')) { S.sel.clear(); fetchList(); } }}))]);
A['bulk-label'] = el => menu(el, [{h:'افزودن برچسب'}, ...D.tags.map(t => ({t:E(t.name), dot:t.color, fn:async () => { const ids = [...S.sel]; if (await setState(ids, {label_add:t.rid}, 'برچسب ' + E(t.name) + ' اضافه شد')) { S.sel.clear(); fetchList(); } }}))]);
A['ib-sort'] = el => menu(el, [{h:'مرتب‌سازی'}, ...[['', 'جدیدترین پیام'], ['unread', 'خوانده‌نشده‌ها اول'], ['old', 'قدیمی‌ترین (منتظرترین)'], ['sla', 'نزدیک‌ترین مهلت SLA'], ['pri', 'اولویت']].map(s => ({t:s[1], on:S.sort === s[0], fn:() => { S.sort = s[0]; reload(); }}))]);
A['ib-more'] = el => menu(el, [{h:'نمایش'}, {t:'فقط خوانده‌نشده‌ها', icon:'envelope-simple', on:S.unreadOnly, fn:() => { S.unreadOnly = !S.unreadOnly; reload(); }}, '-', {t:S.editing ? 'پایان ویرایش چیدمان' : 'ویرایش چیدمان (اندازه‌ی ستون‌ها و پنجره‌ها)', icon:'layout', fn:() => A['lay-edit']()}]);
A['ib-nav-open'] = () => { $('#ibNav').classList.add('open'); };
A['ib-nav-close'] = () => closeNav();
function closeNav() { $('#ibNav')?.classList.remove('open'); }
A['ib-close-th'] = () => { leaveConv(S.cur); S.cur = null; S.thread = null; S.mobileOpen = false; history.replaceState(null, '', '#inbox'); root.classList.remove('th-open'); renderList(); renderThread(); renderProf(); };
A['ib-back'] = () => { S.mobileOpen = false; root.classList.remove('th-open'); };
A['ib-prof'] = () => { if (innerWidth <= 1600 && !(LAY.dock && innerWidth > 1100)) root.classList.toggle('prof-open'); else { S.prof = !S.prof; store.set('ibProf', S.prof); root.classList.toggle('no-prof', !S.prof); $('#ibProf').classList.toggle('hide', !S.prof); } };
A['psec'] = el => { const s = el.parentElement; s.classList.toggle('closed'); store.set('psec.' + s.dataset.k, s.classList.contains('closed')); };
const cur = () => D.convs.find(x => x.id === S.cur);
async function act(set, msg) { const c = cur(); if (!c) return; if (await setState([c.id], set, msg)) { await loadThread(c.id, true); fetchList(true); } }

/** مودال با دکمه‌ی تأیید؛ اگر onOk مقدار false برگرداند باز می‌ماند */
function dlg({title, body, ok = 'تأیید', onOk, cancel = true, size = '', icon}) {
  const L2 = modal({title, body, size, icon, foot:`${cancel ? '<button class="btn" data-close>انصراف</button>' : ''}<button class="btn btn-p" data-ok>${ok}</button>`});
  L2.el.querySelector('[data-ok]').onclick = async ev => { const b = ev.currentTarget; if (!onOk) return L2.close(); b.classList.add('loading'); const r = await onOk(); b.classList.remove('loading'); if (r !== false) L2.close(); };
  return L2;
}

/* تخصیص: بر عهده گرفتن، انتقال به همکار یا AI، رها کردن */
async function claim(force) {
  const c = cur(); if (!c) return;
  const r = await L().api('inbox_claim', {person_id:pid(c.id), force:force ? 1 : 0});
  if (!r.ok && r.taken) return confirm({title:'بر عهده گرفتن گفتگو', text:`این گفتگو الان بر عهده‌ی «${E(r.by)}» است. اگر بر عهده بگیرید، در سابقه ثبت می‌شود و به ${E(r.by)} در چت داخلی تیم خبر داده می‌شود.`, ok:'بر عهده می‌گیرم', onOk:() => claim(true)});
  if (!r.ok) return toast(r.error || 'انجام نشد', 'err');
  toast('این گفتگو حالا بر عهده‌ی شماست'); S.assign = r.assign || S.assign; loadThread(c.id, true); fetchList(true);
}
A['th-claim'] = () => claim(false);
function transferModal(ids, all) {
  const agents = D.ops.filter(o => o.active !== false && o.id !== D.meId);
  dlg({title:all ? 'سپردن همه‌ی چت‌های من' : 'انتقال گفتگو', icon:'arrows-left-right', body:`
    <div class="field"><label>به چه کسی؟</label><select class="inp" id="trTo">${agents.map(o => `<option value="${o.rid}">${E(o.name)}${o.status !== 'online' ? ' (' + ({busy:'مشغول', away:'غایب'}[o.status] || 'آفلاین') + ')' : ''}</option>`).join('')}${D.aiReady ? `<option value="ai">${D.me && D.me.agent_on ? 'دستیار فروش (هوش مصنوعی)' : 'هوش مصنوعی'}</option>` : ''}</select></div>
    <div class="field"><label>یادداشت برای همکار (اختیاری)</label><textarea class="inp" id="trNote" rows="2" placeholder="مثلاً: منتظر کد پیگیری است"></textarea></div>
    ${all ? `<p class="muted small">${fa((D.counts || {}).mine || 0)} گفتگوی باز به همکار منتقل می‌شود؛ مناسب پایان شیفت یا بیرون از ساعت کاری.</p>` : ''}`,
    ok:all ? 'سپردن همه' : 'انتقال', onOk:async () => {
      const to = $('#trTo').value, note = $('#trNote').value.trim();
      if (all) { const r = await L().api('inbox_transfer_all', {to, note}); if (!r.ok) { toast(r.error || 'انجام نشد', 'err'); return false; } toast(fa(r.moved || 0) + ' گفتگو سپرده شد'); }
      else for (const id of ids) { const r = await L().api('inbox_transfer', {person_id:pid(id), to, note}); if (!r.ok) { toast(r.error || 'انجام نشد', 'err'); return false; } }
      if (!all) toast(to === 'ai' ? 'گفتگو به هوش مصنوعی سپرده شد' : 'گفتگو منتقل شد');
      fetchList(true); if (S.cur) loadThread(S.cur, true);
    }});
}
A['th-assign'] = el => { const c = cur(); const a = S.assign || {}; const mine = c.assignee === D.meId;
  menu(el, [
    ...(!mine ? [{t:'بر عهده گرفتن', icon:'hand-grabbing', fn:() => claim(false)}] : []),
    {t:'انتقال به همکار…', icon:'arrows-left-right', fn:() => transferModal([c.id])},
    ...(D.aiReady && !c.ai ? [{t:D.me && D.me.agent_on ? 'سپردن به دستیار فروش' : 'سپردن به هوش مصنوعی', icon:'sparkle', fn:() => A['th-ai-on']()}] : []),
    ...((mine || (a.unrestricted && c.assignee)) ? [{t:'رها کردن (بی‌مسئول)', icon:'user-minus', fn:async () => { const r = await L().api('inbox_release', {person_id:pid(c.id)}); if (!r.ok) return toast(r.error, 'err'); toast('گفتگو بی‌مسئول شد'); loadThread(c.id, true); fetchList(true); }}] : []),
    ...(a.unrestricted || D.me.is_admin ? ['-', {h:'سپردن مستقیم'}, ...D.ops.filter(o => o.active !== false).map(o => ({t:E(o.name) + (o.status !== 'online' ? ' <span class="muted xs">(' + ({busy:'مشغول', away:'غایب'}[o.status] || 'آفلاین') + ')</span>' : ''), av:av(o.name, {color:o.color, size:'xs', st:o.status}), on:c.assignee === o.id, fn:() => act({assignee_id:o.rid}, 'به ' + E(o.name) + ' سپرده شد')}))] : []),
    ...(D.teams.length ? ['-', {h:'صف تیم'}, ...D.teams.map(t => ({t:'تیم ' + E(t.name), dot:t.color, on:c.team === t.id, fn:() => act({team_id:t.rid}, 'به صف تیم ' + E(t.name) + ' رفت')}))] : []),
    ...((a.history || []).length ? ['-', {t:'سابقه‌ی پاسخ‌گویی', icon:'clock-counter-clockwise', fn:() => histModal(c)}] : []),
  ], {w:270}); };
async function histModal(c) {
  const r = await L().api('inbox_assign_history', {person_id:pid(c.id)}); if (!r.ok) return toast(r.error, 'err');
  const HACT = {auto:'با اولین پاسخ مسئول شد', claim:'بر عهده گرفت', takeover:'از همکار بر عهده گرفت', transfer:'منتقل کرد', release:'رها کرد', to_ai:'به هوش مصنوعی سپرد'};
  dlg({title:'سابقه‌ی پاسخ‌گویی', icon:'clock-counter-clockwise', body:r.rows.length ? `<div class="hist big">${r.rows.map(h => `<div><span><b>${E(h.by_name || 'سیستم')}</b> ${HACT[h.action] || h.action}${h.from_name ? ' · از ' + E(h.from_name) : ''}${h.to_name ? ' · به ' + E(h.to_name) : ''}${h.note ? `<br><span class="muted small">«${E(h.note)}»</span>` : ''}</span><time>${E(L().fullDT(h.created_at))}</time></div>`).join('')}</div>` : '<p class="muted">سابقه‌ای ثبت نشده</p>', ok:'بستن', cancel:false});
}
A['ib-transfer-all'] = () => transferModal([], true);
A['ib-agent-stats'] = async () => {
  const draw = async days => { const r = await L().api('inbox_agent_stats', {days}); if (!r.ok) return toast(r.error, 'err');
    const b = $('#agStats'); if (!b) return;
    b.innerHTML = `<table class="tbl"><thead><tr><th>همکار</th><th>پیام پاسخ</th><th>گفتگوی پاسخ‌داده</th><th>شروع‌کرده</th><th>باز الان</th></tr></thead><tbody>${r.rows.map(x => `<tr><td>${E(x.username)}</td><td class="tnum">${fa(x.replies)}</td><td class="tnum">${fa(x.chats)}</td><td class="tnum">${fa(x.started)}</td><td class="tnum">${fa(x.open)}</td></tr>`).join('')}</tbody></table>`; };
  dlg({title:'آمار پاسخ‌گویی همکاران', icon:'chart-bar', size:'lg', body:`<div class="seg" style="margin-bottom:12px">${[[1, 'امروز'], [7, '۷ روز'], [30, '۳۰ روز']].map((d, i) => `<button type="button" class="${i ? '' : 'on'}" data-act="ag-days" data-v="${d[0]}">${d[1]}</button>`).join('')}</div><div id="agStats" style="overflow:auto">${EB.sk(4)}</div>`, ok:'بستن', cancel:false});
  A['ag-days'] = el => { $$('[data-act="ag-days"]').forEach(x => x.classList.toggle('on', x === el)); draw(+el.dataset.v); };
  draw(1);
};
A['th-ident'] = el => { const v = +el.dataset.v || 0; if (v === S.ident) return; S.ident = v; loadThread(S.cur); };
A['th-older'] = () => loadOlder();
A['th-jump'] = () => { const b = $('#thBody'); if (b) b.scrollTop = b.scrollHeight; S.newPill = 0; const np = $('.new-pill'); np && np.remove(); };
A['th-status'] = el => { const c = cur(); menu(el, Object.entries(ST).filter(([k]) => k !== 'snoozed').map(([k, v]) => ({t:v[0], icon:v[2], on:c.status === k, fn:() => act({status:k})}))); };
A['th-pri'] = el => { const c = cur(); menu(el, Object.entries(PRI).map(([k, v]) => ({t:v[0], icon:v[1], on:c.pri === k, fn:() => act({priority:k})}))); };
A['th-snooze'] = el => { const now = new Date(); const toH = (h, add = 0) => { const d = new Date(); d.setDate(d.getDate() + add); d.setHours(h, 0, 0, 0); return Math.max(5, Math.round((d - now) / 60000)); };
  menu(el, [{h:'تعویق تا'}, ...[['یک ساعت دیگر', 60], ['سه ساعت دیگر', 180], ['امروز ساعت ۱۸', toH(18)], ['فردا ساعت ۱۰', toH(10, 1)], ['هفته‌ی بعد', 7 * 1440]].filter(x => x[1] > 0).map(([t, m]) => ({t, icon:'alarm', fn:() => act({status:'snoozed', snooze_minutes:m, snooze_label:t}, 'به تعویق افتاد: ' + t)}))]); };
let noCsat = false;
A['th-close'] = async () => { const c = cur(); if (!c) return; const reopen = c.status === 'closed';
  const r = await L().api('desk_set', {person_ids:[pid(c.id)], set:{status:reopen ? 'open' : 'closed', no_csat:noCsat ? 1 : 0}});
  noCsat = false;
  if (!r.ok) return toast(r.error || 'انجام نشد', 'err');
  if (!reopen) { toast(r.csat && r.csat.ok ? 'گفتگو بسته شد و نظرسنجی رضایت برای مشتری رفت' : 'گفتگو بسته شد', 'ok', ['بازگرداندن', async () => { await setState([c.id], {status:'open'}); fetchList(true); loadThread(c.id, true); }]);
    // بعد از بستن، به نمای کلی برمی‌گردیم (گفتگوی بعدی خودکار باز نمی‌شود)
    await fetchList(true); if (S.view !== 'closed') { A['ib-close-th'](); return; } }
  loadThread(c.id, true); fetchList(true); };
A['th-more'] = el => { const c = cur(); const p = contact(c.cid); menu(el, [
  {t:c.starred ? 'برداشتن ستاره' : 'ستاره‌دار کردن', icon:'star', fn:() => act({starred:!c.starred}, c.starred ? 'ستاره برداشته شد' : 'ستاره‌دار شد')},
  {t:S.showDeleted ? 'پنهان کردن پیام‌های حذف‌شده' : 'نمایش پیام‌های حذف‌شده', icon:'trash-simple', fn:() => { S.showDeleted = !S.showDeleted; loadThread(c.id, true); }},
  {t:'ارسال نظرسنجی رضایت', icon:'star', fn:async () => { const r = await L().api('desk_csat_send', {person_id:pid(c.id)}); if (!r.ok) return toast(r.error || 'ارسال نشد', 'err'); toast('نظرسنجی برای مشتری فرستاده شد'); loadThread(c.id, true); }},
  ...(D.csat && D.csat.enabled && D.csat.auto_close && c.status !== 'closed' ? [{t:'بستن بدون نظرسنجی', icon:'check', fn:() => { noCsat = true; A['th-close'](); }}] : []),
  {t:'خروجی CSV گفتگو', icon:'download-simple', fn:() => { location.href = 'index.php?export=messages&person_id=' + pid(c.id); }},
  '-',
  {t:p.blocked ? 'رفع بلاک مشتری' : 'بلاک کردن مشتری', icon:'prohibit', danger:!p.blocked, fn:() => confirm({title:p.blocked ? 'رفع بلاک' : 'بلاک کردن ' + E(p.name), text:p.blocked ? 'پاسخ‌های خودکار دوباره برای این مشتری فعال می‌شود.' : 'پیام‌های این شخص ثبت می‌شود ولی هیچ پاسخ خودکاری نمی‌گیرد و از فهرست عادی پنهان می‌شود.', ok:p.blocked ? 'رفع بلاک' : 'بلاک کن', danger:!p.blocked, onOk:async () => { const r = await L().api(p.blocked ? 'inbox_unblock' : 'inbox_block', {person_id:pid(c.id)}); if (!r.ok) return toast(r.error, 'err'); toast(p.blocked ? 'رفع بلاک شد' : 'بلاک شد'); loadThread(c.id, true); fetchList(true); }})},
]); };
A['th-ai-on'] = async () => { const r = await L().api('inbox_transfer', {person_id:pid(S.cur), to:'ai'}); if (!r.ok) { const r2 = await L().api('inbox_ai_assign', {person_id:pid(S.cur)}); if (!r2.ok) return toast(r2.error || r.error, 'err'); } toast('این گفتگو از این لحظه با هوش مصنوعی است'); loadThread(S.cur, true); fetchList(true); };
A['th-ai-off'] = async () => { const r = await L().api('inbox_ai_unassign', {person_id:pid(S.cur)}); if (!r.ok) return toast(r.error, 'err'); toast('گفتگو به انسان برگشت'); loadThread(S.cur, true); fetchList(true); };
A['th-unblock'] = async () => { const r = await L().api('inbox_unfilter', {person_id:pid(S.cur)}); if (!r.ok) return toast(r.error, 'err'); toast(r.done && r.done.length ? 'انجام شد: ' + r.done.join('، ') : 'فیلترها برداشته شد'); loadThread(S.cur, true); fetchList(true); };
A['th-refresh-prof'] = async el => { busy(el, 400); const r = await L().api('person_refresh', {person_id:pid(S.cur)}); if (!r.ok) return toast(r.error, 'err'); toast(r.updated ? 'اطلاعات از شبکه به‌روز شد' : 'تغییری نبود', 'info'); loadThread(S.cur, true); };
A['msg-del'] = el => confirm({title:'حذف پیام', text:'پیام از دید عادی کنار می‌رود ولی متن اصلی برای مدیر می‌ماند و قابل بازگرداندن است.', ok:'حذف', danger:true, onOk:async () => { const r = await L().api('message_delete', {id:+el.dataset.id}); if (!r.ok) return toast(r.error, 'err'); loadThread(S.cur, true); }});
/* ویرایش پیام ارسالی (بله، تلگرام، روبیکا تا ۴۸ ساعت؛ گفتگوی سایت همیشه) */
A['msg-edit'] = el => {
  const c = cur(); const m = c && c.msgs.find(x => x.id === +el.dataset.id); if (!m) return;
  const bub = el.closest('.bub'); if (!bub || bub.querySelector('.msg-edit')) return;
  const keep = bub.innerHTML;
  bub.innerHTML = `<div class="msg-edit"><textarea class="inp" rows="3">${E(m.x)}</textarea><div class="row" style="gap:6px;margin-top:6px"><button class="btn btn-sm btn-p" data-k="save">${ic('check')}ذخیره و ویرایش برای مشتری</button><button class="btn btn-sm" data-k="x">انصراف</button></div><div class="xs" style="opacity:.8;margin-top:4px">متن پیام در ${E((D.CH[m.ch] || {}).name || '')} هم برای مشتری عوض می‌شود.</div></div>`;
  const ta = bub.querySelector('textarea'); ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length);
  bub.querySelector('[data-k="x"]').onclick = () => { bub.innerHTML = keep; };
  bub.querySelector('[data-k="save"]').onclick = async ev => {
    const b = ev.currentTarget; const t = ta.value.trim(); if (!t) return ta.focus();
    b.classList.add('loading'); const r = await L().api('message_edit', {id:m.id, text:t}); b.classList.remove('loading');
    if (!r.ok) return toast(r.error || 'ویرایش نشد', 'err');
    toast(r.unchanged ? 'متن تغییری نکرده بود' : 'پیام ویرایش شد'); loadThread(c.id, true);
  };
  ta.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') bub.querySelector('[data-k="save"]').click(); if (e.key === 'Escape') { e.stopPropagation(); bub.innerHTML = keep; } });
};
A['msg-restore'] = async el => { const r = await L().api('message_restore', {id:+el.dataset.id}); if (!r.ok) return toast(r.error, 'err'); loadThread(S.cur, true); };
A['cmp-mode'] = el => { S.mode = el.dataset.v; const v = $('#cmpTa')?.value; renderThread('keep'); const t = $('#cmpTa'); if (t) { t.value = v || ''; t.focus(); } };
const insert = txt => { const t = $('#cmpTa'); if (!t) return; const s = t.selectionStart || t.value.length; t.value = t.value.slice(0, s) + txt + t.value.slice(t.selectionEnd || s); t.focus(); };
async function canned() { if (!D.canned.length) { const r = await L().api('canned_list'); if (r.ok) D.canned = r.items || []; } return D.canned; }
const fill = txt => { const p = contact(cur().cid); return txt.replace(/\{name\}|\{نام\}/g, p.name).replace(/\{firstname\}|\{نام_کوچک\}/g, p.first || p.name).replace(/\{phone\}|\{موبایل\}/g, p.phone || ''); };
A['cmp-canned'] = async (el, q = '') => { const items = (await canned()).filter(c => !q || (c.title + ' ' + (c.category || '')).includes(q)); menu(el, [{h:'پیام‌های آماده'}, ...(items.length ? items.slice(0, 40).map(c => ({t:`${E(c.title)}${c.category ? ` <span class="muted xs">${E(c.category)}</span>` : ''}`, icon:'lightning', fn:() => { const t = $('#cmpTa'); t.value = t.value.replace(/(^|\s)\/\S*$/, '$1'); insert(fill(c.body || '')); }})) : [{html:'<div class="muted small" style="padding:10px">پیام آماده‌ای ثبت نشده</div>'}]), '-', {t:'مدیریت پیام‌های آماده', icon:'gear-six', fn:() => go('support')}], {w:300}); };
A['cmp-var'] = el => menu(el, [{h:'متغیرهای شخصی‌سازی'}, ...[['{name}', 'نام کامل'], ['{firstname}', 'نام کوچک'], ['{phone}', 'موبایل']].map(v => ({t:`${v[1]} <span class="mono muted">${v[0]}</span>`, fn:() => insert(v[0])}))]);
A['cmp-mention'] = el => menu(el, [{h:'منشن همکار'}, ...D.ops.filter(o => o.id !== D.meId && o.active !== false).map(o => ({t:E(o.name), av:av(o.name, {color:o.color, size:'xs'}), fn:() => insert('@' + (o.username || o.name) + ' ')}))]);
A['cmp-emoji'] = el => menu(el, [{html:`<div style="display:grid;grid-template-columns:repeat(8,1fr);gap:2px;padding:4px">${'🙏🌿✅😊👍❤️🎁📦🚚💳⏰🔥✨🙂👌📞'.match(/./gu).map(x => `<button class="btn btn-g btn-ic btn-sm" data-act="emoji-ins" data-v="${x}">${x}</button>`).join('')}</div>`}], {w:290});
A['emoji-ins'] = el => { EB.closePop(); insert(el.dataset.v); };
A['cmp-ai'] = async el => { el.classList.add('loading'); const r = await L().api('ai_draft', {person_id:pid(S.cur)}); el.classList.remove('loading'); if (!r.ok || !r.text) return toast(r.error || 'پیش‌نویسی ساخته نشد', 'err'); const t = $('#cmpTa'); t.value = r.text; t.focus(); toast('پیش‌نویس آماده شد. قبل از ارسال بررسی کنید', 'info'); };
A['cmp-send-menu'] = el => menu(el, [{t:'ارسال و بستن گفتگو', icon:'check-circle', fn:async () => { if (await send()) act({status:'closed'}, 'ارسال شد و گفتگو بسته شد'); }}, {t:'ارسال و منتظر مشتری', icon:'hourglass-medium', fn:async () => { if (await send()) act({status:'pending'}); }}]);
ON['cmp-input'] = el => {
  el.style.height = 'auto'; el.style.height = Math.min(200, el.scrollHeight) + 'px';
  const m = el.value.match(/(^|\s)\/(\S*)$/); if (m && S.mode === 'reply') A['cmp-canned'](el, m[2]);
  if (S.mode === 'reply' && Date.now() - lastTyping > 6000 && el.value.trim()) { lastTyping = Date.now(); pingThread(true); }
};
ON['cmp-file'] = async el => { const f = el.files[0]; if (!f) return; if (f.size > 8e6) return toast('حجم عکس بیش از ۸ مگابایت است', 'err'); const fd = new FormData(); fd.append('image', f); toast('در حال بارگذاری عکس...', 'info'); const r = await L().api('upload_image', fd, true); if (!r.ok) return toast(r.error, 'err'); S.media = {rel:r.rel, name:f.name}; const v = $('#cmpTa').value; renderThread('keep'); $('#cmpTa').value = v; };
A['cmp-media-x'] = () => { S.media = null; const v = $('#cmpTa').value; renderThread('keep'); $('#cmpTa').value = v; };
A['cmp-send'] = () => send();
let sending = false;
async function send() {
  if (sending) return false;
  const t = $('#cmpTa'); if (!t) return false; const x = t.value.trim();
  if (!x && !(S.mode === 'reply' && S.media)) { t.focus(); t.closest('.box').animate([{transform:'translateX(0)'}, {transform:'translateX(-4px)'}, {transform:'translateX(4px)'}, {transform:'translateX(0)'}], {duration:200}); return false; }
  const c = cur(); sending = true;
  const btn = $('[data-act="cmp-send"]'); btn && btn.classList.add('loading');
  let r;
  if (S.mode === 'note') r = await L().api('desk_note', {person_id:pid(c.id), body:x});
  else r = await L().api('inbox_reply', {person_id:pid(c.id), text:fill(x), media_path:S.media ? S.media.rel : '', identity_id:S.ident || c.replyIdent || 0});
  sending = false; btn && btn.classList.remove('loading');
  if (!r.ok) { if (r.taken_by) { S.assign = r.assign || S.assign; renderThread('keep'); } toast(r.error || 'ارسال نشد', 'err'); return false; }
  t.value = ''; S.media = null; lastTyping = 0;
  if (S.mode === 'note') { toast(r.mentions && r.mentions.length ? 'یادداشت ثبت شد و همکار منشن‌شده اعلان گرفت' : 'یادداشت ثبت شد'); }
  await loadThread(c.id, true); fetchList(true);
  return true;
}
A['conv-tag'] = el => { const c = cur(); menu(el, D.tags.map(t => ({t:E(t.name), dot:t.color, on:c.labels.includes(t.id), fn:() => act(c.labels.includes(t.id) ? {label_remove:t.rid} : {label_add:t.rid})}))); };
A['conv-untag'] = el => act({label_remove:pid(el.dataset.id)});
const personAct = async (name, data, ok) => { const r = await L().api(name, data); if (!r.ok) return toast(r.error || 'انجام نشد', 'err'); if (ok) toast(ok); loadThread(S.cur, true); };
A['p-group'] = el => { const p = contact(cur().cid); menu(el, D.groups.length ? D.groups.map(g => ({t:E(g.name), dot:g.color, on:p.groups.includes(g.id), fn:() => p.groups.includes(g.id) ? personAct('person_group_remove', {group_id:g.rid, person_id:p.rid}) : personAct('group_add_persons', {group_id:g.rid, person_ids:[p.rid]}, 'به گروه ' + E(g.name) + ' اضافه شد')})) : [{html:'<div class="muted small" style="padding:10px">گروهی ساخته نشده</div>'}]); };
A['p-ungroup'] = el => personAct('person_group_remove', {group_id:pid(el.dataset.id), person_id:contact(cur().cid).rid});
A['p-tag'] = el => { const p = contact(cur().cid); menu(el, D.tags.length ? D.tags.map(t => ({t:E(t.name), dot:t.color, on:p.tags.includes(t.id), fn:() => p.tags.includes(t.id) ? personAct('person_tag_remove', {tag_id:t.rid, person_id:p.rid}) : personAct('tag_persons', {tag_id:t.rid, person_ids:[p.rid]})})) : [{html:'<div class="muted small" style="padding:10px">برچسبی ساخته نشده</div>'}]); };
A['p-untag'] = el => personAct('person_tag_remove', {tag_id:pid(el.dataset.id), person_id:contact(cur().cid).rid});
ON['p-note'] = async el => { const p = contact(el.dataset.id); const r = await L().api('person_update', {id:p.rid, full_name:p.raw && p.raw.full_name || '', notes:el.value}); if (!r.ok) return toast(r.error, 'err'); p.note = el.value; toast('یادداشت ذخیره شد'); };

UI.on('key', e => {
  if (EB.current().slug !== 'inbox' || document.querySelector('.modal,.drawer')) return;
  const L2 = S.list; const i = L2.indexOf(S.cur); const k = e.key.toLowerCase();
  if (k === 'j' || k === 'ت') { const n = L2[Math.min(L2.length - 1, i + 1)]; n && n !== S.cur && openConv(n, false); }
  else if (k === 'k' || k === 'ن') { const n = L2[Math.max(0, i - 1)]; n && n !== S.cur && openConv(n, false); }
  else if ((k === 'r' || k === 'ق') && S.thread) { e.preventDefault(); S.mode = 'reply'; renderThread('keep'); $('#cmpTa')?.focus(); }
  else if ((k === 'n' || k === 'د') && S.thread) { e.preventDefault(); S.mode = 'note'; renderThread('keep'); $('#cmpTa')?.focus(); }
  else if ((k === 'e' || k === 'ث') && S.thread) { A['th-close'](); }
  else if ((k === 'a' || k === 'ش') && S.thread) { const b = $('[data-act="th-assign"]'); b && A['th-assign'](b); }
  else if (e.key === 'Escape' && S.cur && !document.querySelector('.pop')) A['ib-close-th']();
});
document.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && e.target.id === 'cmpTa') { e.preventDefault(); send(); } });
UI.on('resize', () => { if (EB.current().slug === 'inbox' && root) { root.classList.toggle('th-open', innerWidth < 768 && !!S.mobileOpen); $('#ibProf').classList.toggle('hide', !S.prof && (innerWidth > 1600 || LAY.dock)); } });
UI.on('counts', () => { if (EB.current().slug === 'inbox') renderNav(); });
})();
