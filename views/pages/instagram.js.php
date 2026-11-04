// pages/instagram.js.php — بازطراحی کامل صفحه‌ی اینستاگرام
// ساختار: #home (لیست پیج‌ها) ← #page/{id}/{بخش} (فضای کاری جدا برای هر پیج)
//         #shared/{بخش} (تنظیمات مشترک همه‌ی پیج‌ها) · #tools/{بخش} · #connect/{بخش}
// همه‌ی اکشن‌های API همان اکشن‌های قبلی handler.php هستند؛ بک‌اند تغییری نکرده.

const IGX = {
  pages: [], rules: [], stats: {}, ap: null,
  knowledge: {},            // media_id → ردیف دانش
  view: '', pageId: null, tab: 'overview',
  ps: null, psDirty: false, // تنظیمات پیج جاری {id, data, settings, orig}
  comments: {}, cmFilter: '',
  content: {}, contentKind: 'posts',
  loaded: {},
};
let IGX_LAST_HASH = '';
let RC_SC_CARD_COUNT = 0;
let RC_PUB_IMAGE_REL = null;

// ==================== کمکی‌ها ====================
const igxPage = id => IGX.pages.find(p => +p.id === +id) || null;
const igxPC = p => (p && p.color) || '#6366f1';
const igxInitial = s => { const t = String(s || '').trim().replace(/^@/, ''); return esc(t ? Array.from(t)[0].toUpperCase() : '?'); };
const igxLoad = (t) => `<div class="igx-loading">${t || 'در حال بارگذاری…'}</div>`;
const igxEmpty = (ico, txt, extra) => `<div class="igx-empty"><div class="e">${ico}</div>${txt}${extra ? `<div style="margin-top:12px">${extra}</div>` : ''}</div>`;
const igxErr = (d) => `<span class="tag tag-warn">ناموفق</span> ${esc((d && d.error) || '')}`;
const igxOk = (t) => `<span class="tag tag-ok">${t}</span>`;
const igxProvTag = p => p.provider === 'apichi' ? '<span class="tag tag-accent">یار API</span>' : '<span class="tag tag-mute">اتصال مستقیم</span>';
function igxCopyFallback(text){
  const t = document.createElement('textarea');
  t.value = text; t.setAttribute('readonly', '');
  t.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none';
  (document.querySelector('.igx-modal.on .igx-drawer-b') || document.body).appendChild(t);
  t.focus(); t.select(); t.setSelectionRange(0, text.length);
  let ok = false; try { ok = document.execCommand('copy'); } catch(e) {}
  t.remove();
  return ok;
}
function igxCopy(text){
  text = String(text == null ? '' : text).trim();
  if (!text) { toast('شناسه‌ای برای کپی نیست', false); return; }
  const done = () => toast('کپی شد: ' + (text.length > 28 ? text.slice(0, 28) + '…' : text));
  const manual = () => igxModal('کپی دستی', `<p class="igx-box-d">مرورگر اجازه‌ی کپی خودکار نداد؛ متن انتخاب شده، Ctrl+C بزن.</p><input class="mono" dir="ltr" id="igxManualCopy" value="${esc(text)}" readonly onclick="this.select()">`, '<button class="btn btn-ghost btn-sm" onclick="igxCloseModal()">بستن</button>');
  const after = () => setTimeout(() => { const el = document.getElementById('igxManualCopy'); if (el) { el.focus(); el.select(); } }, 30);
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done, () => { if (igxCopyFallback(text)) done(); else { manual(); after(); } });
  } else if (igxCopyFallback(text)) done();
  else { manual(); after(); }
}
function rcCopy(t){ igxCopy(t); }
function igFmtJson(raw){
  if (raw == null || raw === '') return '(خالی)';
  try { return JSON.stringify(JSON.parse(raw), null, 2); } catch(e) { return String(raw); }
}
function igxSetHtml(id, html){ const el = document.getElementById(id); if (el) el.innerHTML = html; }

// ==================== کشو و مودال ====================
function igxOpenDrawer(id){
  document.querySelectorAll('.igx-drawer.on').forEach(d => d.classList.remove('on'));
  document.getElementById('igxOverlay').classList.add('on');
  document.getElementById(id).classList.add('on');
  document.body.style.overflow = 'hidden';
}
function igxCloseAll(){
  document.getElementById('igxOverlay').classList.remove('on');
  document.querySelectorAll('.igx-drawer.on').forEach(d => d.classList.remove('on'));
  igxCloseModal();
  document.body.style.overflow = '';
}
function igxModal(title, body, foot, wide){
  document.getElementById('igxModalTitle').textContent = title;
  document.getElementById('igxModalBody').innerHTML = body;
  const f = document.getElementById('igxModalFoot');
  f.innerHTML = foot || ''; f.style.display = foot ? '' : 'none';
  document.getElementById('igxModalCard').classList.toggle('wide', !!wide);
  document.getElementById('igxModal').classList.add('on');
}
function igxCloseModal(){
  document.getElementById('igxModal').classList.remove('on');
  if (!document.querySelector('.igx-drawer.on')) document.body.style.overflow = '';
}
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  if (document.getElementById('igxModal').classList.contains('on')) igxCloseModal(); else igxCloseAll();
});

// ==================== مسیریابی ====================
function igxGo(h){ if (location.hash.replace(/^#\/?/, '') === h) igxRoute(); else location.hash = h; }
function igxGoTab(tab){ if (IGX.pageId) igxGo('page/' + IGX.pageId + '/' + tab); }
function igxSwitchPage(id){ igxGo('page/' + id + '/' + (IGX.tab || 'overview')); }

function igxRoute(){
  const raw = location.hash.replace(/^#\/?/, '') || 'home';
  let [view, a, b] = raw.split('/');
  if (!['home', 'page', 'shared', 'tools', 'connect'].includes(view)) view = 'home';

  const leavingPage = IGX.view === 'page' && (view !== 'page' || +a !== +IGX.pageId);
  if (leavingPage && IGX.psDirty) {
    if (!confirm('تغییرات ذخیره‌نشده‌ی این پیج از بین برود؟')) { history.replaceState(null, '', '#' + IGX_LAST_HASH); return; }
    IGX.psDirty = false; igxSavebar();
  }
  IGX_LAST_HASH = raw;
  igxStopTimers(raw);

  if (view === 'page') { igxOpenPage(+a, b || 'overview'); return; }
  igxShowView(view);
  if (view === 'home') { igxRenderHome(); return; }
  const def = { shared: 'rules', tools: 'sales', connect: 'yarapi' }[view];
  const sec = a || def;
  igxShowSec('igxV-' + view, sec);
  const fn = IGX_SEC_LOAD[view + '/' + sec];
  if (fn) fn();
}
function igxShowView(v){
  IGX.view = v;
  document.querySelectorAll('.igx-view').forEach(el => el.classList.toggle('on', el.id === 'igxV-' + v));
  document.querySelectorAll('#igxTopnav button').forEach(b => b.classList.toggle('on', b.dataset.v === (v === 'page' ? 'home' : v)));
}
function igxShowSec(viewId, sec){
  const root = document.getElementById(viewId);
  if (!root.querySelector(`.igx-sec[data-sec="${sec}"]`)) sec = root.querySelector('.igx-sec').dataset.sec;
  root.querySelectorAll('.igx-side button[data-s]').forEach(b => b.classList.toggle('on', b.dataset.s === sec));
  root.querySelectorAll('.igx-sec').forEach(s => s.classList.toggle('on', s.dataset.sec === sec));
  const side = root.querySelector('.igx-side'), btn = side && side.querySelector('button.on');
  if (btn && side.scrollWidth > side.clientWidth + 4) side.scrollLeft += btn.getBoundingClientRect().left - side.getBoundingClientRect().left - (side.clientWidth - btn.offsetWidth) / 2;
  return sec;
}
function igxStopTimers(raw){
  if (raw !== 'connect/raw' && IG_RAW_TIMER) { clearInterval(IG_RAW_TIMER); IG_RAW_TIMER = null; const c = document.getElementById('igRawAuto'); if (c) c.checked = false; }
  if (raw !== 'connect/log' && AP_LOG_TIMER) { clearInterval(AP_LOG_TIMER); AP_LOG_TIMER = null; const c = document.getElementById('apLogAuto'); if (c) c.checked = false; }
}
const igxOnce = (key, fn) => () => { if (IGX.loaded[key]) return; IGX.loaded[key] = true; fn(); };
const IGX_SEC_LOAD = {
  'shared/rules':     () => igxRenderSharedRules(),
  'shared/knowledge': () => igLoadKnowledge(),
  'shared/forbidden': igxOnce('forbidden', () => igLoadForbidden()),
  'shared/faq':       igxOnce('faq', () => igLoadFaq()),
  'shared/phone':     igxOnce('phone', () => igLoadPhoneGate()),
  'shared/botmenu':   igxOnce('botmenu', () => igBotLoadNodes()),
  'tools/sales':      igxOnce('sales', () => { igLoadProducts(); igLoadOrders(); }),
  'connect/yarapi':   () => apLoad(),
  'connect/health':   () => apLogHealth(),
  'connect/log':      igxOnce('aplog', () => { apLogHealth(); apLogReload(); }),
  'connect/raw':      () => igLoadRawLog(),
  'connect/legacy':   igxOnce('legacy', () => { rcLoadIntegrationKey(); igLoadStatus(); }),
};

// ==================== بارگذاری داده‌های پایه ====================
async function igxLoadPages(){
  const d = await api('roboclick_accounts_list');
  IGX.pages = d.ok ? (d.accounts || []) : [];
  window.__rcAccounts = IGX.pages;
  return d.ok;
}
async function igxLoadRules(){
  const d = await api('instagram_automation_list');
  IGX.rules = d.ok ? (d.rows || []) : [];
  window.__igRules = {};
  IGX.rules.forEach(r => window.__igRules[r.id] = r);
}
async function igxLoadStats(){
  const d = await api('roboclick_stats_today');
  IGX.stats = {};
  if (d.ok) (d.rows || []).forEach(r => IGX.stats[r.id] = r);
}
async function igxLoadAp(){
  const d = await api('apichi_status');
  IGX.ap = d.ok ? d : null;
  igxRenderChrome();
  return d;
}
async function igxReloadAll(){
  await Promise.all([igxLoadPages(), igxLoadRules(), igxLoadStats(), igxLoadAp()]);
  igxRenderChrome();
}

// ==================== سربرگ و هشدارها ====================
function igxPageRules(id){ return IGX.rules.filter(r => +r.ig_account_id === +id); }
function igxSharedRules(){ return IGX.rules.filter(r => !r.ig_account_id); }
function igxWarnings(){
  const w = [], ap = IGX.ap;
  if (ap && ap.configured) {
    if (ap.missing_scopes && ap.missing_scopes.length) w.push(['warn', 'کلید یار API این دسترسی‌ها را ندارد: ' + ap.missing_scopes.join(', '), 'connect/yarapi']);
    if (ap.mode === 'webhook' && !ap.webhook_https) w.push(['err', 'آدرس سایت HTTPS نیست و وب‌هوک یار API کار نمی‌کند؛ حالت polling را انتخاب کن.', 'connect/yarapi']);
    else if (ap.mode === 'webhook' && !ap.webhook_id) w.push(['warn', 'وب‌هوک یار API هنوز ثبت نشده؛ پیام‌ها لحظه‌ای نمی‌رسند.', 'connect/yarapi']);
    if (ap.last_poll_error) w.push(['warn', 'خطای polling: ' + ap.last_poll_error, 'connect/health']);
  }
  IGX.pages.filter(p => p.provider !== 'apichi' && +p.is_active && !p.webhook_secret_set)
    .forEach(p => w.push(['warn', `Secret وب‌هوک پیج «${p.label}» ثبت نشده؛ پیام‌های این پیج رد می‌شوند.`, 'page/' + p.id + '/settings']));
  return w;
}
function igxRenderChrome(){
  const act = IGX.pages.filter(p => +p.is_active).length;
  const rulesOn = IGX.rules.filter(r => +r.is_active).length;
  igxSetHtml('igxHeadSub', IGX.pages.length
    ? `${faNum(act)} پیج فعال${IGX.pages.length > act ? ` از ${faNum(IGX.pages.length)}` : ''} · ${faNum(rulesOn)} قانون کامنت فعال`
    : 'هنوز پیجی وصل نشده');
  const dot = document.getElementById('igxConnDot'), txt = document.getElementById('igxConnTxt');
  const ap = IGX.ap, warns = igxWarnings();
  if (ap && ap.configured) {
    dot.className = 'igx-dot ' + (warns.length ? 'warn' : 'ok');
    txt.textContent = 'یار API · ' + (ap.mode === 'polling' ? 'polling' : 'وب‌هوک') + (warns.length ? ` · ${faNum(warns.length)} هشدار` : '');
  } else if (IGX.pages.length) {
    dot.className = 'igx-dot ' + (warns.length ? 'warn' : 'ok'); txt.textContent = 'اتصال مستقیم';
  } else { dot.className = 'igx-dot err'; txt.textContent = 'وصل نیست'; }
  const c1 = document.getElementById('igxCntShared'); if (c1) c1.textContent = faNum(igxSharedRules().length);
  if (IGX.pageId) { const c2 = document.getElementById('igxCntRules'); if (c2) c2.textContent = faNum(igxPageRules(IGX.pageId).length); }
}

// ==================== ۱) لیست پیج‌ها ====================
function igxRenderHome(){
  const warns = igxWarnings();
  igxSetHtml('igxHomeWarn', warns.map(w => `<div class="igx-warn ${w[0] === 'err' ? 'err' : ''}"><span>⚠️</span><span class="grow">${esc(w[1])}</span><button class="btn btn-ghost btn-xs" onclick="igxGo('${w[2]}')">رفع</button></div>`).join(''));
  const box = document.getElementById('igxPages');
  if (!IGX.pages.length) {
    box.style.display = 'block';
    box.innerHTML = `<div class="igx-box">${igxEmpty('📸', '<b style="color:var(--ink);font-size:14px">هنوز هیچ پیجی وصل نیست</b><br>کلید یار API را وارد کن تا همه‌ی پیج‌هایت یک‌جا اضافه شوند.',
      `<button class="btn btn-primary btn-sm" onclick="igxGo('connect/yarapi')">اتصال به یار API</button>`)}</div>`;
    return;
  }
  box.style.display = '';
  box.innerHTML = IGX.pages.map(p => {
    const s = IGX.stats[p.id] || {};
    const rules = igxPageRules(p.id), on = rules.filter(r => +r.is_active).length;
    const needSecret = p.provider !== 'apichi' && !p.webhook_secret_set;
    return `<button type="button" class="igx-pcard ${+p.is_active ? '' : 'off'}" style="--pc:${esc(igxPC(p))}" onclick="igxGo('page/${+p.id}/overview')">
      <div class="igx-pc-top">
        <div class="igx-av">${igxInitial(p.label || p.username)}</div>
        <div style="min-width:0;flex:1">
          <div class="igx-pc-name">${esc(p.label || ('پیج #' + p.id))}</div>
          ${p.username ? `<div class="igx-pc-user">@${esc(p.username)}</div>` : ''}
        </div>
      </div>
      <div class="igx-pc-stats">
        <div class="igx-pc-stat"><b>${faNum(s.messages_in ?? '·')}</b><span>دایرکت امروز</span></div>
        <div class="igx-pc-stat"><b>${faNum(s.messages_out ?? '·')}</b><span>ارسالی</span></div>
        <div class="igx-pc-stat"><b>${faNum(s.comments ?? '·')}</b><span>کامنت</span></div>
        <div class="igx-pc-stat"><b>${faNum(on)}</b><span>قانون فعال</span></div>
      </div>
      <div class="igx-pc-foot">
        ${igxProvTag(p)}
        ${+p.is_active ? '' : '<span class="tag tag-warn">غیرفعال</span>'}
        ${needSecret ? '<span class="tag tag-warn">Secret ثبت نشده</span>' : ''}
        <span class="go">مدیریت ←</span>
      </div>
    </button>`;
  }).join('');
}
async function igxRefreshHome(){
  igxSetHtml('igxPages', igxLoad());
  await igxReloadAll();
  igxRenderHome();
}

// ==================== ۲) فضای کاری پیج ====================
function igxResetPageState(){
  IGX.ps = null; IGX.psDirty = false; igxSavebar();
  IGX.cmFilter = '';
  document.querySelectorAll('#igxCmFilter button').forEach(b => b.classList.toggle('on', b.dataset.f === ''));
  RC_PUB_IMAGE_REL = null;
  const prev = document.getElementById('rcPubPreview'); prev.style.display = 'none'; prev.removeAttribute('src');
  document.getElementById('rcPubDropTxt').style.display = '';
  ['rcPubCaption', 'rcPubImage', 'rcStoryResourceId', 'rcShowContact'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
  ['rcPubResult', 'rcPubImageStatus', 'rcStoryResult', 'rcShowResult'].forEach(id => igxSetHtml(id, ''));
  rcPubCaptionCounter();
  igxARReset();
}
function igxOpenPage(id, tab){
  const p = igxPage(id);
  if (!p) { toast('این پیج پیدا نشد', false); igxGo('home'); return; }
  const changed = +IGX.pageId !== +id;
  IGX.pageId = +id;
  if (changed) igxResetPageState();
  igxShowView('page');
  igxRenderPageHead(p);
  IGX.tab = igxShowSec('igxV-page', tab);
  igxLoadTab(IGX.tab);
}
function igxRenderPageHead(p){
  const ph = document.getElementById('igxPH');
  ph.style.setProperty('--pc', igxPC(p));
  const av = document.getElementById('igxPHAv');
  av.style.setProperty('--pc', igxPC(p));
  av.innerHTML = igxInitial(p.label || p.username);
  document.getElementById('igxPHName').textContent = p.label || ('پیج #' + p.id);
  document.getElementById('igxPHMeta').innerHTML = [p.username ? `<span dir="ltr">@${esc(p.username)}</span>` : '', p.provider === 'apichi' ? 'یار API' : 'اتصال مستقیم', +p.is_active ? 'فعال' : '<b style="color:var(--warn)">غیرفعال</b>'].filter(Boolean).join(' · ');
  const sw = document.getElementById('igxPHSwitch');
  sw.style.display = IGX.pages.length > 1 ? '' : 'none';
  sw.innerHTML = IGX.pages.map(x => `<option value="${+x.id}" ${+x.id === +p.id ? 'selected' : ''}>${esc(x.label || ('پیج #' + x.id))}${+x.is_active ? '' : ' (غیرفعال)'}</option>`).join('');
  document.getElementById('igxCntRules').textContent = faNum(igxPageRules(p.id).length);
}
function igxLoadTab(tab){
  switch (tab) {
    case 'overview':  igxRenderOverview(); break;
    case 'comments':  igxLoadComments(false); break;
    case 'rules':     igxRenderPageRules(); break;
    case 'autoreply': case 'ai': case 'flow': igxEnsurePS(); break;
    case 'content':   igxLoadContent(false); break;
    case 'publish':   rcLoadPublishHistory(); break;
    case 'dmtools':   if (!document.querySelector('#rcShowCardsBuilder .ig-sc-card')) rcShowcaseAddCard(); break;
    case 'settings':  igxRenderSettings(); break;
  }
}

// ---------- خلاصه ----------
async function igxRenderOverview(){
  const id = IGX.pageId, s = IGX.stats[id] || {}, rules = igxPageRules(id);
  document.getElementById('igxKIn').textContent = faNum(s.messages_in ?? 0);
  document.getElementById('igxKOut').textContent = faNum(s.messages_out ?? 0);
  document.getElementById('igxKCm').textContent = faNum(s.comments ?? 0);
  document.getElementById('igxKRules').textContent = faNum(rules.filter(r => +r.is_active).length);
  igxRenderFeat();
  igxEnsurePS();
  igxSetHtml('igxOvComments', igxLoad());
  const rows = await igxEnsureComments(false);
  if (id !== IGX.pageId) return;
  igxSetHtml('igxOvComments', rows === null ? igxErr({error: 'دریافت کامنت‌ها ناموفق بود'})
    : (rows.length ? rows.slice(0, 5).map(r => igxCommentHtml(r, true)).join('') : igxEmpty('💬', 'هنوز کامنتی روی این پیج ثبت نشده.')));
}
function igxFeatTag(state, txt){
  const cls = state === 'on' ? 'tag-ok' : (state === 'inherit' ? 'tag-accent' : 'tag-mute');
  return `<span class="tag ${cls}">${txt}</span>`;
}
function igxRenderFeat(){
  const box = document.getElementById('igxFeat');
  if (!IGX.ps || IGX.ps.id !== IGX.pageId) { box.innerHTML = igxLoad(); return; }
  const s = IGX.ps.settings, d = IGX.ps.data, g = d.global || {};
  const aiG = {off: 'خاموش', draft: 'فقط پیش‌نویس', auto: 'روشن'}[g.ai_mode] || g.ai_mode || '—';
  const on = v => String(v) === '1';
  const rules = igxPageRules(IGX.pageId), rOn = rules.filter(r => +r.is_active).length;
  const tri = (v, gOn) => v === 'inherit' ? igxFeatTag('inherit', 'طبق کلی · ' + (gOn === true ? 'روشن' : gOn === false ? 'خاموش' : gOn))
                    : igxFeatTag(v === 'off' ? 'off' : 'on', v === 'off' ? 'خاموش' : 'روشن');
  const rows = [
    ['⚡', 'پاسخ خودکار دایرکت', on(s.autoreply_enabled) ? igxFeatTag('on', `روشن · ${faNum((d.rules || []).length)} قانون مخصوص`) : igxFeatTag('off', 'خاموش'), 'autoreply'],
    ['🤖', 'هوش مصنوعی', tri(s.ai_mode, aiG), 'ai'],
    ['📞', 'گرفتن اجباری شماره', tri(s.phone_gate, !!g.phone_gate), 'flow'],
    ['🧭', 'منوی بات', igxFeatTag(on(s.botmenu_enabled) ? 'on' : 'off', on(s.botmenu_enabled) ? 'روشن' : 'خاموش'), 'flow'],
    ['📝', 'فرم‌ها', igxFeatTag(on(s.forms_enabled) ? 'on' : 'off', on(s.forms_enabled) ? 'روشن' : 'خاموش'), 'flow'],
    ['🔔', 'اعلان پیام تازه', igxFeatTag(on(s.notify_enabled) ? 'on' : 'off', on(s.notify_enabled) ? 'روشن' : 'خاموش'), 'flow'],
    ['🎯', 'اتوماسیون کامنت', igxFeatTag(rOn ? 'on' : 'off', rules.length ? `${faNum(rOn)} از ${faNum(rules.length)} فعال` : 'بدون قانون'), 'rules'],
  ];
  box.innerHTML = rows.map(r => `<div class="igx-feat" onclick="igxGoTab('${r[3]}')"><span class="ic">${r[0]}</span><span class="nm">${r[1]}</span>${r[2]}<span class="ar">‹</span></div>`).join('');
}

// ---------- تنظیمات پیج (پاسخ خودکار / AI / شماره و منو) ----------
async function igxEnsurePS(force){
  const id = IGX.pageId;
  if (!force && IGX.ps && IGX.ps.id === id) { igxFillPS(); return IGX.ps; }
  const d = await api('ig_page_settings_get', {account_id: id});
  if (id !== IGX.pageId) return null;
  if (!d.ok) { toast(d.error || 'بارگذاری تنظیمات پیج ناموفق بود', false); return null; }
  IGX.ps = {id, data: d, settings: Object.assign({}, d.settings), orig: Object.assign({}, d.settings)};
  IGX.psDirty = false; igxSavebar();
  igxFillPS();
  return IGX.ps;
}
function igxFillPS(){
  if (!IGX.ps) return;
  const s = IGX.ps.settings, d = IGX.ps.data, g = d.global || {};
  document.querySelectorAll('#igxV-page [data-ps]').forEach(el => {
    const k = el.dataset.ps;
    if (el.type === 'checkbox') el.checked = String(s[k]) === '1'; else el.value = s[k] ?? '';
  });
  document.querySelectorAll('#igxV-page [data-ps-seg]').forEach(seg => {
    const v = String(s[seg.dataset.psSeg] || 'inherit');
    seg.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.val === v));
  });
  const aiG = {off: 'خاموش', draft: 'فقط پیش‌نویس', auto: 'روشن'}[g.ai_mode] || g.ai_mode || '—';
  document.getElementById('igxAIGlobal').innerHTML = 'تنظیم کلی: ' + esc(aiG) + (g.ai_ready ? '' : ' · <span style="color:var(--err)">پروایدر AI تنظیم نشده</span>');
  document.getElementById('igxPGGlobal').textContent = 'تنظیم کلی: ' + (g.phone_gate ? 'روشن' : 'خاموش');
  document.getElementById('igxPGText').placeholder = g.phone_gate_text || '';
  document.getElementById('igxPGThanks').placeholder = g.phone_gate_thanks || '';
  document.getElementById('igxARGlobalHint').textContent = `${faNum((d.global_rules || []).length)} قانون در صفحه‌ی «پاسخ خودکار» برای همه‌ی پیج‌ها تعریف شده. خاموش باشد، فقط قانون‌های مخصوص همین پیج اجرا می‌شوند.`;
  igxRenderARRules();
  igxRenderFeat();
}
function igxPSMark(){
  if (!IGX.ps) return;
  IGX.psDirty = JSON.stringify(IGX.ps.settings) !== JSON.stringify(IGX.ps.orig);
  igxSavebar();
}
function igxSavebar(){ document.getElementById('igxSavebar').classList.toggle('on', !!IGX.psDirty); }
function igxPSDiscard(){
  if (!IGX.ps) return;
  IGX.ps.settings = Object.assign({}, IGX.ps.orig);
  IGX.psDirty = false; igxSavebar(); igxFillPS();
}
async function igxPSSave(){
  if (!IGX.ps) return;
  const d = await api('ig_page_settings_save', {account_id: IGX.ps.id, settings: IGX.ps.settings});
  if (!d.ok) { toast(d.error || 'ذخیره ناموفق بود', false); return; }
  if (d.settings) IGX.ps.settings = Object.assign({}, d.settings);
  IGX.ps.orig = Object.assign({}, IGX.ps.settings);
  IGX.psDirty = false; igxSavebar(); igxFillPS();
  toast('تنظیمات این پیج ذخیره شد');
}
function igxBindPS(){
  document.querySelectorAll('#igxV-page [data-ps]').forEach(el => {
    el.addEventListener(el.type === 'checkbox' ? 'change' : 'input', () => {
      if (!IGX.ps) return;
      IGX.ps.settings[el.dataset.ps] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
      igxPSMark();
    });
  });
  document.querySelectorAll('#igxV-page [data-ps-seg]').forEach(seg => {
    seg.addEventListener('click', e => {
      const b = e.target.closest('button[data-val]'); if (!b || !IGX.ps) return;
      IGX.ps.settings[seg.dataset.psSeg] = b.dataset.val;
      seg.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
      igxPSMark();
    });
  });
}

// قانون‌های پاسخ خودکار مخصوص پیج (جدول eb_auto_replies با ig_account_id)
const IGX_AR_TYPE = {exact: 'دقیق', contains: 'شامل', starts_with: 'شروع با', regex: 'Regex', on_contact: 'اشتراک شماره'};
function igxARResp(r){ try { return JSON.parse(r.response_json || '{}') || {}; } catch(e) { return {}; } }
function igxRenderARRules(){
  const rules = (IGX.ps && IGX.ps.data.rules) || [];
  igxSetHtml('igxARRules', rules.length ? rules.map(r => {
    const resp = igxARResp(r);
    return `<div class="igx-item ${+r.is_active ? '' : 'off'}">
      <div class="bd">
        <div class="tt">${r.trigger_type === 'on_contact' ? 'اشتراک شماره' : esc(r.trigger_keyword)} <span class="tag tag-mute">${IGX_AR_TYPE[r.trigger_type] || esc(r.trigger_type)}</span>${+r.is_active ? '' : '<span class="tag tag-warn">غیرفعال</span>'}${+r.priority ? `<span class="tag tag-mute">اولویت ${faNum(r.priority)}</span>` : ''}</div>
        <div class="ds">${esc(resp.text || '')}</div>
      </div>
      <div class="ac"><button class="btn btn-ghost btn-xs" onclick="igxAREdit(${+r.id})">ویرایش</button><button class="btn btn-danger-ghost btn-xs" onclick="igxARDelete(${+r.id})">حذف</button></div>
    </div>`;
  }).join('') : igxEmpty('⚡', 'هنوز قانون مخصوصی برای این پیج نیست.'));
}
function igxAREdit(id){
  const r = ((IGX.ps && IGX.ps.data.rules) || []).find(x => +x.id === +id); if (!r) return;
  document.getElementById('igxARId').value = r.id;
  document.getElementById('igxARKw').value = r.trigger_keyword || '';
  document.getElementById('igxARType').value = IGX_AR_TYPE[r.trigger_type] && r.trigger_type !== 'on_contact' ? r.trigger_type : 'contains';
  document.getElementById('igxARText').value = igxARResp(r).text || '';
  document.getElementById('igxARPri').value = r.priority || 0;
  document.getElementById('igxARFormTitle').textContent = 'ویرایش قانون';
  document.getElementById('igxARSave').textContent = 'ذخیره‌ی قانون';
  document.getElementById('igxARCancel').style.display = '';
  document.getElementById('igxARKw').focus();
}
function igxARReset(){
  ['igxARId', 'igxARKw', 'igxARText'].forEach(i => document.getElementById(i).value = '');
  document.getElementById('igxARPri').value = '0';
  document.getElementById('igxARType').value = 'contains';
  document.getElementById('igxARFormTitle').textContent = '+ قانون جدید';
  document.getElementById('igxARSave').textContent = 'افزودن قانون';
  document.getElementById('igxARCancel').style.display = 'none';
}
async function igxARSave(){
  const kw = document.getElementById('igxARKw').value.trim(), text = document.getElementById('igxARText').value.trim();
  if (!kw || !text) { toast('کلمه‌ی محرک و متن پاسخ لازم است', false); return; }
  const id = document.getElementById('igxARId').value;
  const old = id ? ((IGX.ps && IGX.ps.data.rules) || []).find(x => +x.id === +id) : null;
  const resp = old ? igxARResp(old) : {};
  const d = await api('autoreply_save', {
    id, channel: 'instagram', ig_account_id: IGX.pageId, trigger_keyword: kw, trigger_type: document.getElementById('igxARType').value, text,
    buttons_json: resp.buttons ? JSON.stringify(resp.buttons) : '', priority: document.getElementById('igxARPri').value,
    request_contact: resp.request_contact ? 1 : 0, is_active: old ? +old.is_active : 1,
  });
  if (!d.ok) { toast(d.error || 'خطا', false); return; }
  toast('قانون ذخیره شد'); igxARReset();
  await igxRefreshPSRules();
}
async function igxARDelete(id){
  if (!confirm('این قانون حذف شود؟')) return;
  const d = await api('autoreply_delete', {id});
  if (d.ok) { toast('حذف شد'); await igxRefreshPSRules(); } else toast(d.error || 'خطا', false);
}
async function igxRefreshPSRules(){
  const d = await api('ig_page_settings_get', {account_id: IGX.pageId});
  if (d.ok && IGX.ps && IGX.ps.id === IGX.pageId) { IGX.ps.data.rules = d.rules; IGX.ps.data.global_rules = d.global_rules; igxRenderARRules(); igxRenderFeat(); }
}

// ---------- کامنت‌ها ----------
async function igxEnsureComments(force){
  const id = IGX.pageId;
  if (!force && IGX.comments[id]) return IGX.comments[id];
  const d = await api('roboclick_comments_list', {id});
  IGX.comments[id] = d.ok ? (d.rows || []) : null;
  return IGX.comments[id];
}
function igxCommentHtml(r, compact){
  const replied = !!r.replied_at;
  const mid = r.media_id ? String(r.media_id) : '';
  return `<div class="igx-item" style="${compact ? 'padding:10px 12px' : ''}">
    <div class="igx-av sm" style="--pc:var(--ink-faint)">${igxInitial(r.sender_username)}</div>
    <div class="bd">
      <div class="tt"><span dir="ltr">@${esc(r.sender_username || '—')}</span>${replied ? '<span class="tag tag-ok">پاسخ خودکار</span>' : '<span class="tag tag-mute">بی‌پاسخ</span>'}</div>
      <div class="ds">${esc(r.body || '')}</div>
      <div class="mt">${esc(r.created_at || '')}${mid ? ` · پست <span class="mono" style="font-size:10px">${esc(mid)}</span>` : ''}</div>
    </div>
    ${compact || !mid ? '' : `<div class="ac">
      <button class="btn btn-ghost btn-xs" data-copy="${esc(mid)}" onclick="igxCopy(this.dataset.copy)">کپی شناسه پست</button>
      <button class="btn btn-ghost btn-xs" data-mid="${esc(mid)}" onclick="igRuleForm(null,{ig_account_id:IGX.pageId, media_id:this.dataset.mid})">قانون برای این پست</button>
    </div>`}
  </div>`;
}
async function igxLoadComments(force){
  const box = document.getElementById('igxComments');
  box.innerHTML = igxLoad();
  const id = IGX.pageId;
  const rows = await igxEnsureComments(force);
  if (id !== IGX.pageId) return;
  if (rows === null) { box.innerHTML = igxErr({error: 'دریافت کامنت‌ها ناموفق بود'}); return; }
  const f = IGX.cmFilter;
  const list = rows.filter(r => !f || (f === 'replied' ? !!r.replied_at : !r.replied_at));
  box.innerHTML = list.length ? list.map(r => igxCommentHtml(r, false)).join('')
    : igxEmpty('💬', rows.length ? 'کامنتی با این فیلتر نیست.' : 'هنوز کامنتی روی این پیج ثبت نشده. کامنت‌ها با وب‌هوک یا polling اینجا می‌آیند.');
}

// ---------- اتوماسیون کامنت ----------
function igxRuleHtml(r, showPage){
  const p = r.ig_account_id ? igxPage(r.ig_account_id) : null;
  const where = [];
  if (+r.trigger_comment) where.push(r.media_id ? 'کامنتِ یک پست خاص' : 'کامنت همه‌ی پست‌ها');
  if (+r.trigger_story_reply) where.push(r.story_id ? 'ریپلای یک استوری خاص' : 'ریپلای استوری');
  return `<div class="igx-item ${+r.is_active ? '' : 'off'}">
    <div class="bd">
      <div class="tt">${esc(r.name || 'بدون نام')}
        <span class="tag ${+r.is_active ? 'tag-ok' : 'tag-mute'}">${+r.is_active ? 'فعال' : 'غیرفعال'}</span>
        ${showPage ? (p ? `<span class="tag" style="background:${esc(igxPC(p))}22;color:${esc(igxPC(p))}">${esc(p.label)}</span>` : '<span class="tag tag-accent">همه‌ی پیج‌ها</span>') : ''}
        ${+r.require_follow ? '<span class="tag tag-mute">فالو اجباری</span>' : ''}
        ${+r.once_per_user ? '<span class="tag tag-mute">یک‌بار برای هر کاربر</span>' : ''}
        ${r.end_at ? `<span class="tag tag-mute">تا ${esc(String(r.end_at).slice(0, 10))}</span>` : ''}
      </div>
      <div class="ds">کلمه: ${r.keyword ? `<b>${esc(r.keyword)}</b>` : '<i>هر کامنتی</i>'} · ${esc(where.join(' و ') || '—')}</div>
      <div class="mt">${faNum(r.hit_count || 0)} بار اجرا شده</div>
    </div>
    <div class="ac">
      <button class="btn btn-ghost btn-xs" onclick="igRuleForm(window.__igRules[${+r.id}])">ویرایش</button>
      <button class="btn btn-ghost btn-xs" onclick="igToggleRule(${+r.id}, ${+r.is_active ? 0 : 1})">${+r.is_active ? 'توقف' : 'فعال‌سازی'}</button>
      <button class="btn btn-danger-ghost btn-xs" onclick="igDeleteRule(${+r.id})">حذف</button>
    </div>
  </div>`;
}
function igxRenderPageRules(){
  const rows = igxPageRules(IGX.pageId), shared = igxSharedRules();
  igxSetHtml('igxRulesPage', rows.length ? rows.map(r => igxRuleHtml(r, false)).join('')
    : `<div class="igx-box">${igxEmpty('🎯', 'هنوز قانونی برای این پیج نساختی.<br>مثلاً: «هر کی زیر پست نوشت قیمت، قیمت رو دایرکت کن».',
        '<button class="btn btn-primary btn-sm" onclick="igRuleForm(null,{ig_account_id:IGX.pageId})">ساخت اولین قانون</button>')}</div>`);
  const sharedOn = shared.filter(r => +r.is_active);
  igxSetHtml('igxRulesSharedNote', shared.length ? `<div class="igx-strip"><span class="grow">${faNum(sharedOn.length)} قانون مشترک فعال هم روی این پیج اجرا می‌شود${sharedOn.length ? ': ' + sharedOn.slice(0, 3).map(r => '«' + esc(r.name) + '»').join('، ') + (sharedOn.length > 3 ? '…' : '') : ''}.</span><button class="btn btn-ghost btn-xs" onclick="igxGo('shared/rules')">مدیریت قانون‌های مشترک</button></div>` : '');
  document.getElementById('igxCntRules').textContent = faNum(rows.length);
}
function igxRenderSharedRules(){
  const shared = igxSharedRules();
  igxSetHtml('igxRulesShared', shared.length ? shared.map(r => igxRuleHtml(r, false)).join('')
    : `<div class="igx-box">${igxEmpty('🧩', 'قانون مشترکی نیست. قانون‌هایی که اینجا بسازی روی همه‌ی پیج‌ها اجرا می‌شوند.')}</div>`);
  igxSetHtml('igxRulesByPage', IGX.pages.length ? IGX.pages.map(p => {
    const rs = igxPageRules(p.id), on = rs.filter(r => +r.is_active).length;
    return `<div class="igx-item" style="align-items:center"><div class="igx-av sm" style="--pc:${esc(igxPC(p))}">${igxInitial(p.label)}</div>
      <div class="bd"><div class="tt">${esc(p.label)}</div><div class="mt">${faNum(rs.length)} قانون · ${faNum(on)} فعال</div></div>
      <div class="ac"><button class="btn btn-ghost btn-xs" onclick="igxGo('page/${+p.id}/rules')">مدیریت ←</button></div></div>`;
  }).join('') : igxEmpty('📱', 'هنوز پیجی وصل نیست.'));
  igxRenderChrome();
}
function igxRerenderRules(){
  igxRenderChrome();
  if (IGX.view === 'page') {
    if (IGX.tab === 'rules') igxRenderPageRules();
    if (IGX.tab === 'overview') igxRenderOverview();
    if (IGX.tab === 'content') igxRenderContent();
    document.getElementById('igxCntRules').textContent = faNum(igxPageRules(IGX.pageId).length);
  }
  if (IGX.view === 'shared') igxRenderSharedRules();
  if (IGX.view === 'home') igxRenderHome();
}

// فرم قانون (کشو). preset: {ig_account_id, media_id, story_id}
function igRuleForm(rule, preset){
  preset = preset || {};
  const v = (k, def) => rule ? (rule[k] ?? def) : (preset[k] ?? def);
  document.getElementById('igRuleFormTitle').textContent = rule ? 'ویرایش قانون' : 'قانون جدید';
  document.getElementById('igRId').value = rule ? rule.id : '';
  const sel = document.getElementById('igRAccountId');
  sel.innerHTML = '<option value="">همه‌ی پیج‌ها</option>' + IGX.pages.map(a => `<option value="${+a.id}">${esc(a.label)}</option>`).join('');
  sel.value = String(v('ig_account_id', '') || '');
  document.getElementById('igRName').value = v('name', '');
  const story = !!preset.story_id || !!preset.story;
  document.getElementById('igRTriggerComment').checked = rule ? !!(+rule.trigger_comment) : !story;
  document.getElementById('igRTriggerStory').checked = rule ? !!(+rule.trigger_story_reply) : story;
  document.getElementById('igRMediaId').value = v('media_id', '') || '';
  document.getElementById('igRStoryId').value = v('story_id', '') || '';
  document.getElementById('igRStartAt').value = rule ? igDtToLocal(rule.start_at) : '';
  document.getElementById('igREndAt').value = rule ? igDtToLocal(rule.end_at) : '';
  document.getElementById('igRKeyword').value = v('keyword', '') || '';
  document.getElementById('igRMatchMode').value = (rule && rule.match_mode) || 'contains';
  document.getElementById('igRExcludeKeywords').value = v('exclude_keywords', '') || '';
  document.getElementById('igROncePerUser').checked = rule ? !!(+rule.once_per_user) : false;
  document.getElementById('igRPublicReply').value = v('public_reply_text', '') || '';
  document.getElementById('igRRequireFollow').checked = rule ? !!(+rule.require_follow) : false;
  document.getElementById('igRFollowPrompt').value = v('follow_prompt_text', '') || '';
  document.getElementById('igRConfirmPhrase').value = (rule && rule.confirm_phrase) || 'فالو کردم';
  document.getElementById('igRNotYet').value = v('not_yet_text', '') || '';
  document.getElementById('igRDmText').value = v('dm_text', '') || '';
  document.getElementById('igRActive').checked = rule ? !!(+rule.is_active) : true;
  igToggleFollowFields();
  igxOpenDrawer('igRuleFormCard');
  setTimeout(() => document.getElementById('igRName').focus(), 230);
}
function igDtToLocal(dt){ return dt ? String(dt).slice(0, 16).replace(' ', 'T') : ''; }
function igDtFromLocal(v){ return v ? v.replace('T', ' ') + ':00' : ''; }
function igToggleFollowFields(){ document.getElementById('igRFollowFields').style.display = document.getElementById('igRRequireFollow').checked ? '' : 'none'; }
function igLoadRuleExample(){
  const set = (id, val) => document.getElementById(id).value = val;
  set('igRName', 'نمونه: قیمت با فالو اجباری');
  document.getElementById('igRTriggerComment').checked = true;
  document.getElementById('igRTriggerStory').checked = false;
  set('igRKeyword', 'قیمت, هزینه, چند');
  set('igRMatchMode', 'contains');
  set('igRExcludeKeywords', 'اسپم');
  document.getElementById('igROncePerUser').checked = true;
  set('igRPublicReply', 'فرستادم، دایرکتت رو چک کن 💌');
  document.getElementById('igRRequireFollow').checked = true;
  set('igRFollowPrompt', 'برای اینکه قیمت رو برات بفرستم، اول پیجمون رو فالو کن، بعد دکمه‌ی زیر رو بزن 🙏');
  set('igRConfirmPhrase', 'فالو کردم');
  set('igRNotYet', 'هنوز فالو نکردی 🙈 فالو کن و دوباره دکمه رو بزن.');
  set('igRDmText', 'سلام! قیمت این محصول ۴۵۰ هزار تومانه. برای ثبت سفارش کافیه همینجا شماره‌ت رو بفرستی 🌷');
  document.getElementById('igRActive').checked = true;
  igToggleFollowFields();
  toast('نمونه پر شد؛ عوضش کن و ذخیره بزن');
}
async function igSaveRule(){
  const g = id => document.getElementById(id);
  const payload = {
    id: g('igRId').value || null,
    ig_account_id: g('igRAccountId').value || '',
    name: g('igRName').value.trim(),
    trigger_comment: g('igRTriggerComment').checked ? 1 : 0,
    trigger_story_reply: g('igRTriggerStory').checked ? 1 : 0,
    media_id: g('igRMediaId').value.trim(),
    story_id: g('igRStoryId').value.trim(),
    start_at: igDtFromLocal(g('igRStartAt').value),
    end_at: igDtFromLocal(g('igREndAt').value),
    keyword: g('igRKeyword').value.trim(),
    match_mode: g('igRMatchMode').value,
    exclude_keywords: g('igRExcludeKeywords').value.trim(),
    once_per_user: g('igROncePerUser').checked ? 1 : 0,
    public_reply_text: g('igRPublicReply').value.trim(),
    require_follow: g('igRRequireFollow').checked ? 1 : 0,
    follow_prompt_text: g('igRFollowPrompt').value.trim(),
    confirm_phrase: g('igRConfirmPhrase').value.trim(),
    not_yet_text: g('igRNotYet').value.trim(),
    dm_text: g('igRDmText').value.trim(),
    is_active: g('igRActive').checked ? 1 : 0,
  };
  if (!payload.trigger_comment && !payload.trigger_story_reply) { toast('حداقل یکی از «کامنت» یا «ریپلای استوری» را انتخاب کن', false); return; }
  if (!payload.name || !payload.dm_text) { toast('نام قانون و متن دایرکت نهایی لازم است', false); return; }
  const d = await api('instagram_automation_save', payload);
  if (!d.ok) { toast(d.error || 'خطا', false); return; }
  toast('ذخیره شد'); igxCloseAll();
  await igxLoadRules(); igxRerenderRules();
}
async function igToggleRule(id, active){
  const d = await api('instagram_automation_toggle', {id, active});
  if (!d.ok) { toast('خطا', false); return; }
  await igxLoadRules(); igxRerenderRules();
}
async function igDeleteRule(id){
  if (!confirm('این قانون حذف شود؟')) return;
  const d = await api('instagram_automation_delete', {id});
  if (!d.ok) { toast('خطا', false); return; }
  toast('حذف شد'); await igxLoadRules(); igxRerenderRules();
}

// انتخاب پست برای قانون (مودال روی کشو)
async function igxPickPost(){
  const pid = +(document.getElementById('igRAccountId').value || IGX.pageId || (IGX.pages[0] && IGX.pages[0].id) || 0);
  if (!pid) { toast('اول یک پیج وصل کن', false); return; }
  const opts = IGX.pages.map(p => `<option value="${+p.id}" ${+p.id === +pid ? 'selected' : ''}>${esc(p.label)}</option>`).join('');
  igxModal('انتخاب پست', `<div class="field"><label>پست‌های کدام پیج؟</label><select id="igxPickPage" onchange="igxPickLoad(this.value)">${opts}</select></div><div id="igxPickList">${igxLoad()}</div>`, '', true);
  igxPickLoad(pid);
}
async function igxPickLoad(pid){
  const box = document.getElementById('igxPickList'); box.innerHTML = igxLoad();
  const c = await igxEnsurePosts(+pid, false);
  if (!c.ok) { box.innerHTML = igxErr(c); return; }
  if (!c.posts.length) { box.innerHTML = igxEmpty('🗂', 'پستی برنگشت.'); return; }
  const cur = (document.getElementById('igRMediaId').value || '').trim();
  const items = c.posts.map(p => ({id: igxPostId(p), th: igxPostThumb(p), cp: igxPostCaption(p)}));
  const usable = items.filter(x => x.id);
  if (!usable.length) { box.innerHTML = igxErr({error: 'پست‌ها آمدند ولی شناسه‌ای در پاسخ API نبود؛ از «پست‌ها و استوری‌ها ← داده‌ی خام» JSON را برای پشتیبانی بفرست.'}); return; }
  box.innerHTML = `<div class="igx-posts">${usable.map(x => `<button type="button" class="igx-post" style="cursor:pointer;font-family:inherit;text-align:start;padding:0;color:var(--ink);${x.id === cur ? 'outline:2px solid var(--accent);' : ''}" data-mid="${esc(x.id)}" onclick="igxPickDone(this.dataset.mid)">
      <div class="im">${x.th ? `<img src="${esc(x.th)}" alt="" loading="lazy" onerror="this.remove()">` : '🖼'}<div class="bdg">${x.id === cur ? '<span class="tag tag-ok">انتخاب‌شده</span>' : ''}</div></div>
      <div class="bd"><div class="cp">${esc(x.cp || '(بدون کپشن)')}</div><div class="id mono" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(x.id)}</div></div></button>`).join('')}</div>`
    + (c.cursor ? `<div style="text-align:center;margin-top:10px"><button class="btn btn-ghost btn-sm" onclick="igxPickMore(${+pid})">پست‌های بیشتر</button></div>` : '');
}
async function igxPickMore(pid){
  const c = igxContentState(pid);
  if (!c.cursor) return;
  const d = await api('roboclick_posts_list', {id: pid, cursor: c.cursor});
  if (!d.ok) { toast(d.error || 'ناموفق', false); return; }
  c.posts = c.posts.concat(d.posts || []); c.cursor = d.cursor || null;
  igxPickLoad(pid);
}
function igxPickDone(mid){
  if (!mid) { toast('این پست شناسه ندارد', false); return; }
  document.getElementById('igRMediaId').value = mid;
  document.getElementById('igRTriggerComment').checked = true;
  igxCloseModal(); toast('پست انتخاب شد');
}

// ---------- پست‌ها و استوری‌ها ----------
// بک‌اند (roboclick_posts_list / roboclick_stories_list) هر پست را به {id, thumb, caption, permalink, raw} یکسان می‌کند
const igxPostId = p => String((p && (p.id ?? p.postId ?? p.post_id ?? p.media_id)) ?? '').trim();
const igxPostThumb = p => (p && (p.thumb || p.thumbnail_url || p.media_url || p.image_url)) || '';
const igxPostCaption = p => String((p && (p.caption || p.text)) || '');
/** قانون‌هایی که مشخصاً برای همین پست/استوری ساخته شده‌اند (نه قانون‌های «همه‌ی پست‌ها») */
function igxRulesForMedia(id, kind){
  if (!id) return [];
  const pageId = IGX.pageId;
  return IGX.rules.filter(r => {
    if (r.ig_account_id && +r.ig_account_id !== +pageId) return false;
    if (kind === 'story') return +r.trigger_story_reply && String(r.story_id || '').trim() === id;
    return +r.trigger_comment && String(r.media_id || '').trim() === id;
  });
}
function igxRulesBadge(id, kind){
  const rs = igxRulesForMedia(id, kind);
  if (!rs.length) return '';
  const on = rs.filter(r => +r.is_active).length;
  return `<span class="tag ${on ? 'tag-ok' : 'tag-mute'}" title="${esc(rs.map(r => r.name).join('، '))}">🎯 ${faNum(rs.length)} قانون${on < rs.length ? ` (${faNum(on)} فعال)` : ''}</span>`;
}
function igxShowRaw(kind, idx){
  const c = igxContentState(IGX.pageId), list = kind === 'story' ? c.stories : c.posts, it = list && list[idx];
  if (!it) return;
  igxModal('داده‌ی خام از API', `<p class="igx-box-d">شناسه‌ی این مورد در پاسخ API پیدا نشد. این JSON را برای پشتیبانی بفرست تا کلید درستش اضافه شود.</p><pre class="mono" style="white-space:pre-wrap;background:var(--surface-2);padding:10px;border-radius:9px;max-height:50vh;overflow:auto">${esc(JSON.stringify(it.raw ?? it, null, 2))}</pre>`, '<button class="btn btn-ghost btn-sm" onclick="igxCloseModal()">بستن</button>', true);
}
function igxContentState(id){ return IGX.content[id] || (IGX.content[id] = {posts: null, cursor: null, stories: null, err: null, serr: null}); }
async function igxEnsurePosts(id, force){
  const c = igxContentState(id);
  if (!force && c.posts) return {ok: true, posts: c.posts};
  const d = await api('roboclick_posts_list', {id});
  if (!d.ok) { c.err = d.error || 'ناموفق'; return {ok: false, error: c.err}; }
  c.posts = d.posts || []; c.cursor = d.cursor || null; c.err = null;
  return {ok: true, posts: c.posts};
}
async function igxLoadMorePosts(){
  const id = IGX.pageId, c = igxContentState(id);
  if (!c.cursor) return;
  const btn = document.getElementById('igxPostsMore'); btn.disabled = true;
  const d = await api('roboclick_posts_list', {id, cursor: c.cursor});
  btn.disabled = false;
  if (!d.ok) { toast(d.error || 'ناموفق', false); return; }
  c.posts = c.posts.concat(d.posts || []); c.cursor = d.cursor || null;
  igxRenderContent();
}
async function igxLoadContent(force){
  const id = IGX.pageId;
  igxSetHtml('igxContent', igxLoad());
  document.getElementById('igxPostsMore').style.display = 'none';
  if (!IGX.loaded.knowledge) { IGX.loaded.knowledge = true; igLoadKnowledge(); }
  if (IGX.contentKind === 'posts') await igxEnsurePosts(id, force);
  else {
    const c = igxContentState(id);
    if (force || !c.stories) {
      const d = await api('roboclick_stories_list', {id});
      c.stories = d.ok ? (d.stories || []) : null; c.serr = d.ok ? null : (d.error || 'ناموفق');
    }
  }
  if (id === IGX.pageId) igxRenderContent();
}
function igxRenderContent(){
  const c = igxContentState(IGX.pageId), more = document.getElementById('igxPostsMore');
  more.style.display = 'none';
  if (IGX.contentKind === 'posts') {
    if (c.err) { igxSetHtml('igxContent', `<div class="igx-box">${igxErr({error: c.err})} <span class="hint">جزئیات در «اتصال و عیب‌یابی ← لاگ خام».</span></div>`); return; }
    if (!c.posts) { igxSetHtml('igxContent', igxLoad()); return; }
    if (!c.posts.length) { igxSetHtml('igxContent', `<div class="igx-box">${igxEmpty('🗂', 'هیچ پستی برنگشت.')}</div>`); return; }
    const generic = igxPageRules(IGX.pageId).concat(igxSharedRules()).filter(r => +r.is_active && +r.trigger_comment && !String(r.media_id || '').trim()).length;
    igxSetHtml('igxContent', (generic ? `<div class="igx-strip"><span class="grow">${faNum(generic)} قانون فعال روی «همه‌ی پست‌ها» تنظیم شده و روی همه‌ی این پست‌ها هم اجرا می‌شود. عدد روی هر پست فقط قانون‌های مخصوص همان پست است.</span></div>` : '')
      + `<div class="igx-posts">${c.posts.map((p, i) => {
      const id = igxPostId(p), th = igxPostThumb(p), kn = id ? IGX.knowledge[id] : null;
      return `<div class="igx-post">
        <div class="im">${th ? `<img src="${esc(th)}" alt="" loading="lazy" onerror="this.remove()">` : '🖼'}
          <div class="bdg">${igxRulesBadge(id, 'post')}${kn ? '<span class="tag tag-accent">🧠 دانش</span>' : ''}</div></div>
        <div class="bd">
          <div class="cp">${esc(igxPostCaption(p) || '(بدون کپشن)')}</div>
          ${id ? `<div class="id mono" title="${esc(id)}" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(id)}</div>` : `<button class="btn btn-ghost btn-xs" onclick="igxShowRaw('post', ${i})" style="color:var(--warn)">⚠ شناسه پیدا نشد · داده‌ی خام</button>`}
          <div class="ac">
            <button class="btn btn-ghost btn-xs" data-mid="${esc(id)}" ${id ? '' : 'disabled'} onclick="igRuleForm(null,{ig_account_id:IGX.pageId, media_id:this.dataset.mid})">+ قانون</button>
            <button class="btn btn-ghost btn-xs" data-mid="${esc(id)}" ${id ? '' : 'disabled'} onclick="igxKnowledgeModal(this.dataset.mid)">${kn ? 'دانش ✓' : 'دانش AI'}</button>
            <button class="btn btn-ghost btn-xs" data-copy="${esc(id)}" ${id ? '' : 'disabled'} onclick="igxCopy(this.dataset.copy)" title="کپی شناسه">کپی</button>
          </div>
        </div></div>`;
    }).join('')}</div>`);
    more.style.display = c.cursor ? '' : 'none';
  } else {
    if (c.serr) { igxSetHtml('igxContent', `<div class="igx-box">${igxErr({error: c.serr})}</div>`); return; }
    if (!c.stories) { igxSetHtml('igxContent', igxLoad()); return; }
    if (!c.stories.length) { igxSetHtml('igxContent', `<div class="igx-box">${igxEmpty('⏳', 'الان استوری فعالی روی این پیج نیست.')}</div>`); return; }
    const genericS = igxPageRules(IGX.pageId).concat(igxSharedRules()).filter(r => +r.is_active && +r.trigger_story_reply && !String(r.story_id || '').trim()).length;
    igxSetHtml('igxContent', (genericS ? `<div class="igx-strip"><span class="grow">${faNum(genericS)} قانون فعال روی «همه‌ی استوری‌ها» تنظیم شده. عدد روی هر استوری فقط قانون‌های مخصوص همان استوری است.</span></div>` : '')
      + `<div class="igx-posts">${c.stories.map((st, i) => {
      const id = igxPostId(st), th = igxPostThumb(st);
      return `<div class="igx-post story">
        <div class="im">${th ? `<img src="${esc(th)}" alt="" loading="lazy" onerror="this.remove()">` : '⏳'}<div class="bdg">${igxRulesBadge(id, 'story')}</div></div>
        <div class="bd">${id ? `<div class="id mono" title="${esc(id)}" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(id)}</div>` : `<button class="btn btn-ghost btn-xs" onclick="igxShowRaw('story', ${i})" style="color:var(--warn)">⚠ شناسه پیدا نشد</button>`}
          <div class="ac">
            <button class="btn btn-ghost btn-xs" data-sid="${esc(id)}" ${id ? '' : 'disabled'} onclick="igRuleForm(null,{ig_account_id:IGX.pageId, story_id:this.dataset.sid, story:true})">+ قانون ریپلای</button>
            <button class="btn btn-ghost btn-xs" data-copy="${esc(id)}" ${id ? '' : 'disabled'} onclick="igxCopy(this.dataset.copy)" title="کپی شناسه">کپی</button>
          </div></div></div>`;
    }).join('')}</div>`);
  }
}

// ---------- دانش پست‌ها ----------
async function igLoadKnowledge(){
  const box = document.getElementById('igKnowledgeList');
  const d = await api('instagram_knowledge_list');
  if (!d.ok) { box.innerHTML = igxErr(d); return; }
  const rows = d.rows || [];
  IGX.knowledge = {}; window.__igKnowledge = {};
  rows.forEach(k => { IGX.knowledge[String(k.media_id)] = k; window.__igKnowledge[k.id] = k; });
  box.innerHTML = rows.length ? rows.map(k => `<div class="igx-item">
      <div class="bd"><div class="tt">${esc(k.title || 'بدون عنوان')}</div>
        <div class="ds">${esc((k.knowledge_text || '').slice(0, 180))}${(k.knowledge_text || '').length > 180 ? '…' : ''}</div>
        <div class="mt mono">${esc(k.media_id)}</div></div>
      <div class="ac"><button class="btn btn-ghost btn-xs" data-mid="${esc(k.media_id)}" onclick="igxKnowledgeModal(this.dataset.mid)">ویرایش</button>
        <button class="btn btn-danger-ghost btn-xs" onclick="igDeleteKnowledge(${+k.id})">حذف</button></div>
    </div>`).join('') : `<div class="igx-box">${igxEmpty('🧠', 'هنوز دانشی برای هیچ پستی ثبت نشده.')}</div>`;
  if (IGX.view === 'page' && IGX.tab === 'content') igxRenderContent();
}
function igxKnowledgeModal(mediaId){
  const k = mediaId ? IGX.knowledge[String(mediaId)] : null;
  igxModal(k ? 'ویرایش دانش پست' : 'دانش AI برای پست',
    `<p class="igx-box-d">بنویس این پست درباره‌ی چیست و به چه سؤال‌هایی چطور جواب بدهد. اگر کامنتی با هیچ قانونی جور نشد، AI با همین متن جواب می‌دهد.</p>
     <div class="field"><label>شناسه‌ی پست</label><input id="igKMediaId" class="mono" dir="ltr" value="${esc(mediaId || '')}" ${mediaId ? 'readonly' : ''}></div>
     <div class="field"><label>عنوان <span class="muted">(فقط برای خودت)</span></label><input id="igKTitle" value="${esc(k ? k.title || '' : '')}" placeholder="مثلاً: پست اقامتگاه پونک"></div>
     <div class="field" style="margin:0"><label>دانش این پست</label><textarea id="igKText" rows="7" placeholder="مثلاً: ظرفیت ۴ نفر، آشپزخانه مجهز، قیمت هر شب ۱٬۲۰۰٬۰۰۰ تومان…">${esc(k ? k.knowledge_text || '' : '')}</textarea></div>`,
    `<button class="btn btn-primary btn-sm" onclick="igSaveKnowledge()">ذخیره</button>${k ? `<button class="btn btn-danger-ghost btn-sm" onclick="igDeleteKnowledge(${+k.id}, true)">حذف</button>` : ''}<button class="btn btn-ghost btn-sm" onclick="igxCloseModal()">انصراف</button>`);
}
async function igSaveKnowledge(){
  const mediaId = document.getElementById('igKMediaId').value.trim();
  const text = document.getElementById('igKText').value.trim();
  if (!mediaId || !text) { toast('شناسه‌ی پست و متن دانش لازم است', false); return; }
  const d = await api('instagram_knowledge_save', {media_id: mediaId, title: document.getElementById('igKTitle').value.trim(), knowledge_text: text});
  if (!d.ok) { toast(d.error || 'خطا', false); return; }
  toast('ذخیره شد'); igxCloseModal(); igLoadKnowledge();
}
async function igDeleteKnowledge(id, fromModal){
  if (!confirm('این دانش حذف شود؟')) return;
  await api('instagram_knowledge_delete', {id});
  if (fromModal) igxCloseModal();
  igLoadKnowledge();
}

// ---------- انتشار ----------
async function rcUploadForPublish(input){
  const file = input.files && input.files[0];
  if (!file) return;
  const status = document.getElementById('rcPubImageStatus');
  status.textContent = 'در حال آپلود…';
  const fd = new FormData(); fd.append('image', file);
  const d = await api('upload_image', fd, true);
  if (d.ok && (d.rel || d.path)) {
    RC_PUB_IMAGE_REL = d.rel || d.path;
    status.innerHTML = '<span style="color:var(--ok)">✓ آپلود شد، آماده‌ی انتشار</span>';
    const prev = document.getElementById('rcPubPreview');
    prev.src = URL.createObjectURL(file); prev.style.display = 'block';
    document.getElementById('rcPubDropTxt').style.display = 'none';
  } else { RC_PUB_IMAGE_REL = null; status.innerHTML = '<span style="color:var(--err)">آپلود ناموفق: ' + esc(d.error || '') + '</span>'; }
}
function rcPubCaptionCounter(){
  const el = document.getElementById('rcPubCaption'), c = document.getElementById('rcPubCaptionCount');
  if (!el || !c) return;
  c.textContent = faNum(el.value.length) + '/' + faNum(2200);
  c.style.color = el.value.length > 2200 ? 'var(--err)' : '';
}
function rcPubWhenChanged(){
  const later = (document.querySelector('input[name=rcPubWhen]:checked')||{}).value === 'later';
  const at = document.getElementById('rcPubAt');
  at.style.display = later ? '' : 'none';
  document.getElementById('rcPubAtHint').style.display = later ? '' : 'none';
  if (later && !at.value) { const d = new Date(Date.now() + 3600e3); d.setMinutes(0, 0, 0); at.value = new Date(d - d.getTimezoneOffset() * 6e4).toISOString().slice(0, 16); at.oninput && at.oninput(); }
  if (!later) document.getElementById('rcPubAtFa').textContent = '';
  document.getElementById('rcPubBtnPost').textContent = later ? 'زمان‌بندی پست' : 'انتشار پست';
  document.getElementById('rcPubBtnStory').textContent = later ? 'زمان‌بندی استوری' : 'انتشار استوری';
}
async function rcPublish(kind){
  const box = document.getElementById('rcPubResult');
  if (!RC_PUB_IMAGE_REL) { box.innerHTML = '<span class="tag tag-warn">اول عکس را انتخاب کن</span>'; return; }
  const p = igxPage(IGX.pageId);
  const later = (document.querySelector('input[name=rcPubWhen]:checked')||{}).value === 'later';
  const at = document.getElementById('rcPubAt').value;
  if (later && !at) { box.innerHTML = '<span class="tag tag-warn">زمان انتشار را انتخاب کن</span>'; return; }
  if (later && new Date(at) < new Date(Date.now() - 60e3)) { box.innerHTML = '<span class="tag tag-warn">این زمان گذشته است</span>'; return; }
  const what = kind === 'post' ? 'پست' : 'استوری';
  if (!later && !confirm(`${what} همین حالا روی پیج «${p ? p.label : ''}» منتشر شود؟`)) return;
  box.innerHTML = '<span class="muted">' + (later ? 'در حال ثبت در صف…' : 'در حال انتشار…') + '</span>';
  const payload = {ig_account_id: IGX.pageId, kind, media_path: RC_PUB_IMAGE_REL, caption: kind === 'post' ? document.getElementById('rcPubCaption').value.trim() : ''};
  if (later) payload.run_at = at.replace('T', ' ') + ':00'; else payload.publish_now = 1;
  const d = await api('igpost_save', payload);
  if (d.ok) box.innerHTML = igxOk(later ? `${what} برای ${toFa(payload.run_at.slice(0, 16))} در صف انتشار قرار گرفت` : 'منتشر شد');
  else if (d.queued) box.innerHTML = igxErr({error: (d.error || 'انتشار ناموفق بود') + ' — در صف ماند و تا ۳ بار دوباره امتحان می‌شود'});
  else box.innerHTML = igxErr(d);
  rcLoadPublishHistory();
}
const IGP_ST = {pending:['در صف','tag-warn'], publishing:['در حال انتشار','tag-accent'], published:['منتشر شد','tag-ok'], failed:['ناموفق','tag-err'], cancelled:['لغو شد','tag-mute']};
async function rcLoadPublishHistory(){
  const box = document.getElementById('rcPublishHistory');
  const d = await api('igpost_list');
  if (!d.ok) { box.innerHTML = '<span class="muted" style="font-size:12px">' + esc(d.error || 'در دسترس نیست') + '</span>'; return; }
  const rows = d.posts || [];
  box.innerHTML = rows.length ? rows.map(r => {
    const st = IGP_ST[r.status] || [r.status, 'tag-mute'];
    const when = r.status === 'published' ? 'منتشر شد ' + toFa(r.published_at_local || '') : toFa((r.run_at_local || '').slice(0, 16));
    const acts = [];
    if (r.status === 'pending') acts.push(`<button class="btn btn-ghost btn-xs" onclick="igpAct('publish_now',${r.id})">انتشار همین حالا</button>`, `<button class="btn btn-ghost btn-xs" onclick="igpAct('cancel',${r.id})">لغو</button>`);
    if (r.status === 'failed' || r.status === 'cancelled') acts.push(`<button class="btn btn-ghost btn-xs" onclick="igpAct('retry',${r.id})">تلاش دوباره</button>`);
    if (r.status !== 'publishing') acts.push(`<button class="btn btn-danger-ghost btn-xs" onclick="igpAct('delete',${r.id})">حذف</button>`);
    return `<div class="igx-item" style="padding:9px 12px;align-items:center;gap:10px;flex-wrap:wrap">
      <img src="${esc(r.thumb)}" alt="" style="width:42px;height:42px;border-radius:8px;object-fit:cover;flex-shrink:0;background:var(--surface-2)">
      <div class="bd" style="min-width:0;flex:1">
        <div style="font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${r.kind === 'story' ? 'استوری' : 'پست'} · ${esc(r.page_label)} · ${esc(r.caption || '(بدون کپشن)')}</div>
        <div class="muted" style="font-size:11px">${when}${r.error ? ` · <span style="color:var(--err)">${esc(r.error)}</span>` : ''}</div>
      </div>
      <span class="tag ${st[1]}">${st[0]}</span><div style="display:flex;gap:4px">${acts.join('')}</div></div>`;
  }).join('') : igxEmpty('🗓', 'هنوز پستی در صف یا تاریخچه نیست.');
  clearTimeout(window.IGP_T);
  if (rows.some(r => r.status === 'pending' || r.status === 'publishing')) window.IGP_T = setTimeout(rcLoadPublishHistory, 30000);
}
async function igpAct(act, id){
  if (act === 'delete' && !confirm('این مورد از صف و تاریخچه حذف شود؟')) return;
  if (act === 'publish_now' && !confirm('همین حالا منتشر شود؟')) return;
  const d = await api('igpost_' + act, {id});
  if (!d.ok) toast(d.error || 'انجام نشد', false); else toast(act === 'publish_now' ? 'منتشر شد' : 'انجام شد');
  rcLoadPublishHistory();
}

// ---------- ویترین و رسانه‌ی استوری ----------
function rcShowcaseAddCard(){
  const wrap = document.getElementById('rcShowCardsBuilder');
  if (wrap.children.length >= 10) { toast('حداکثر ۱۰ کارت', false); return; }
  const idx = RC_SC_CARD_COUNT++;
  const div = document.createElement('div');
  div.className = 'ig-sc-card'; div.id = 'scCard' + idx;
  div.innerHTML = `
    <div class="ig-sc-card-head"><b>کارت</b><button class="btn btn-ghost btn-xs" onclick="document.getElementById('scCard${idx}').remove()">حذف</button></div>
    <div class="ig-sc-row">
      <div class="field"><label>عنوان</label><input class="sc-title" maxlength="80" placeholder="شومیز طرح گلدار"></div>
      <div class="field"><label>توضیح کوتاه</label><input class="sc-sub" maxlength="80" placeholder="سایز فری، ۴ رنگ"></div>
    </div>
    <div class="ig-sc-row" style="align-items:flex-end">
      <div class="field" style="flex:2"><label>عکس (مربعی)</label><input type="file" accept="image/*" onchange="rcShowcaseUploadImg(this, ${idx})"><input type="hidden" class="sc-photo-rel"><div class="sc-photo-status hint"></div></div>
      <img class="ig-sc-thumb sc-photo-preview" style="display:none" alt="">
    </div>
    <div class="hint" style="margin:6px 0">دکمه‌ها (اختیاری)</div>
    <div class="ig-sc-btn-row"><input class="sc-btn-text" placeholder="متن دکمه (مثلاً: خرید)"><input class="sc-btn-url" dir="ltr" placeholder="https://..."></div>
    <div class="ig-sc-btn-row"><input class="sc-btn-text" placeholder="متن دکمه"><input class="sc-btn-url" dir="ltr" placeholder="https://..."></div>`;
  wrap.appendChild(div);
}
async function rcShowcaseUploadImg(input, idx){
  const file = input.files && input.files[0]; if (!file) return;
  const card = document.getElementById('scCard' + idx), status = card.querySelector('.sc-photo-status');
  status.textContent = 'در حال آپلود…';
  const fd = new FormData(); fd.append('image', file);
  const d = await api('upload_image', fd, true);
  if (d.ok && (d.rel || d.path)) {
    card.querySelector('.sc-photo-rel').value = d.rel || d.path;
    status.textContent = '✓ آپلود شد';
    const prev = card.querySelector('.sc-photo-preview'); prev.src = URL.createObjectURL(file); prev.style.display = 'block';
  } else status.textContent = 'آپلود ناموفق: ' + (d.error || '');
}
function rcShowcaseExample(){
  document.getElementById('rcShowCardsBuilder').innerHTML = '';
  RC_SC_CARD_COUNT = 0; rcShowcaseAddCard(); rcShowcaseAddCard();
  const fill = (i, t, s, u) => { const c = document.getElementById('scCard' + i); c.querySelector('.sc-title').value = t; c.querySelector('.sc-sub').value = s; c.querySelectorAll('.sc-btn-text')[0].value = 'خرید'; c.querySelectorAll('.sc-btn-url')[0].value = u; };
  fill(0, 'شومیز طرح گلدار', 'سایز فری، ۴ رنگ، ۳۹۰ هزار تومان', 'https://example.com/product/1');
  fill(1, 'کیف چرم دست‌دوز', 'قهوه‌ای یا مشکی، ۷۲۰ هزار تومان', 'https://example.com/product/2');
  toast('دو کارت نمونه ساخته شد؛ عکس‌ها را خودت آپلود کن');
}
async function rcSendShowcase(){
  const box = document.getElementById('rcShowResult');
  const contactId = document.getElementById('rcShowContact').value.trim();
  const cardEls = document.querySelectorAll('#rcShowCardsBuilder .ig-sc-card');
  if (!contactId) { box.innerHTML = '<span class="tag tag-warn">شناسه‌ی مخاطب لازم است</span>'; return; }
  if (!cardEls.length) { box.innerHTML = '<span class="tag tag-warn">حداقل یک کارت اضافه کن</span>'; return; }
  const cards = [];
  for (const c of cardEls) {
    const title = c.querySelector('.sc-title').value.trim(), photoRel = c.querySelector('.sc-photo-rel').value;
    if (!title || !photoRel) { box.innerHTML = '<span class="tag tag-warn">هر کارت باید عنوان و عکس داشته باشد</span>'; return; }
    const buttons = [];
    c.querySelectorAll('.ig-sc-btn-row').forEach(row => { const t = row.querySelector('.sc-btn-text').value.trim(), u = row.querySelector('.sc-btn-url').value.trim(); if (t && u) buttons.push({text: t, url: u}); });
    const card = {title, subtitle: c.querySelector('.sc-sub').value.trim(), photo_rel: photoRel};
    if (buttons.length) card.buttons = buttons;
    cards.push(card);
  }
  box.innerHTML = '<span class="muted">در حال ارسال…</span>';
  const d = await api('roboclick_send_showcase', {id: IGX.pageId, contact_id: contactId, cards_json: JSON.stringify(cards)});
  box.innerHTML = d.ok ? igxOk('ارسال شد') : igxErr(d);
}
async function rcLookupStoryMedia(){
  const box = document.getElementById('rcStoryResult');
  const resourceId = document.getElementById('rcStoryResourceId').value.trim();
  if (!resourceId) { box.innerHTML = '<span class="tag tag-warn">شناسه‌ی رسانه لازم است</span>'; return; }
  box.innerHTML = '<span class="muted">در حال دریافت…</span>';
  const d = await api('roboclick_story_media_lookup', {id: IGX.pageId, resource_id: resourceId});
  box.innerHTML = d.ok ? `<pre class="mono" style="max-height:260px;overflow:auto;background:var(--surface-2);padding:10px;border-radius:9px;white-space:pre-wrap">${esc(JSON.stringify(d.raw, null, 2))}</pre>` : igxErr(d);
}

// ---------- تنظیمات پیج (نام، رنگ، فعال، اتصال، حذف) ----------
function igxRenderSettings(){
  const p = igxPage(IGX.pageId); if (!p) return;
  document.getElementById('igxSLabel').value = p.label || '';
  document.getElementById('igxSColor').value = /^#[0-9a-f]{6}$/i.test(p.color || '') ? p.color : '#6366f1';
  document.getElementById('igxSActive').checked = +p.is_active === 1;
  const box = document.getElementById('igxSConn');
  if (p.provider === 'apichi') {
    box.innerHTML = `<div class="igx-box-h"><h3>اتصال</h3>${igxProvTag(p)}</div>
      <p class="igx-box-d">این پیج از طریق یار API وصل است و رویدادهایش از وب‌هوک مشترک یار API می‌رسد؛ کلید یا وب‌هوک جدا لازم ندارد.</p>
      <div class="hint mono" style="margin-bottom:10px">${esc(p.account_key_masked || '')}</div>
      <button class="btn btn-ghost btn-sm" onclick="igxGo('connect/yarapi')">مدیریت اتصال یار API</button>`;
  } else {
    box.innerHTML = `<div class="igx-box-h"><h3>اتصال مستقیم</h3>${p.webhook_secret_set ? '<span class="tag tag-ok">Secret ثبت شده</span>' : '<span class="tag tag-warn">Secret ثبت نشده — پیام‌ها رد می‌شوند</span>'}</div>
      <p class="igx-box-d">۱) این آدرس را در پنل سرویس‌دهنده، «Account API ← Webhook URL» همین پیج وارد کن و Save بزن. ۲) «Rotate Secret» را بزن و Secret را همین‌جا ثبت کن (فقط یک‌بار نشان داده می‌شود).</p>
      <div class="field"><label>آدرس وب‌هوک این پیج</label><div class="igx-inline"><input class="mono" dir="ltr" readonly value="${esc(p.webhook_url || '')}" onclick="this.select()"><button class="btn btn-ghost btn-sm" data-copy="${esc(p.webhook_url || '')}" onclick="igxCopy(this.dataset.copy)">کپی</button></div></div>
      <div class="field"><label>Webhook Secret</label><div class="igx-inline"><input id="igxSSecret" class="mono" dir="ltr" placeholder="${p.webhook_secret_set ? 'ثبت شده؛ برای تغییر، Secret جدید را بچسبان' : 'Secret را اینجا بچسبان'}" autocomplete="off"><button class="btn btn-primary btn-sm" onclick="igxSaveSecret()">ثبت</button></div></div>
      <div class="hint mono">${esc(p.account_key_masked || '')}</div>`;
  }
}
async function igxSaveIdentity(){
  const label = document.getElementById('igxSLabel').value.trim();
  if (!label) { toast('نام پیج لازم است', false); return; }
  const d = await api('roboclick_account_update', {id: IGX.pageId, label, color: document.getElementById('igxSColor').value});
  if (!d.ok) { toast(d.error || 'ذخیره ناموفق بود', false); return; }
  toast('ذخیره شد'); await igxLoadPages(); igxRenderPageHead(igxPage(IGX.pageId)); igxRenderChrome();
}
async function igxToggleActive(on){
  const d = await api('roboclick_account_update', {id: IGX.pageId, is_active: on ? 1 : 0});
  if (!d.ok) { toast(d.error || 'ناموفق', false); document.getElementById('igxSActive').checked = !on; return; }
  toast(on ? 'پیج فعال شد' : 'پیج غیرفعال شد'); await igxLoadPages(); igxRenderPageHead(igxPage(IGX.pageId)); igxRenderChrome();
}
async function igxSaveSecret(){
  const s = document.getElementById('igxSSecret').value.trim();
  if (!s) { toast('Secret را وارد کن', false); return; }
  const d = await api('roboclick_account_update', {id: IGX.pageId, webhook_secret: s});
  if (!d.ok) { toast(d.error || 'ثبت ناموفق بود', false); return; }
  toast('Secret ثبت شد'); await igxLoadPages(); igxRenderSettings(); igxRenderChrome();
}
async function igxDeletePage(){
  const p = igxPage(IGX.pageId); if (!p) return;
  if (!confirm(`پیج «${p.label}» حذف شود؟ گفتگوهای قبلی در صندوق ورودی می‌مانند.`)) return;
  const d = await api('roboclick_account_delete', {id: p.id});
  if (!d.ok) { toast(d.error || 'حذف ناموفق بود', false); return; }
  toast('پیج حذف شد'); IGX.psDirty = false; IGX.pageId = null;
  await igxLoadPages(); igxRenderChrome(); igxGo('home');
}

// ==================== ۳) تنظیمات مشترک ====================
async function igLoadForbidden(){
  const d = await api('instagram_forbidden_get'); if (!d.ok) return;
  document.getElementById('igForbiddenTopics').value = d.topics || '';
  document.getElementById('igForbiddenRedirect').value = d.redirect_text || '';
}
async function igSaveForbidden(){
  const d = await api('instagram_forbidden_save', {topics: document.getElementById('igForbiddenTopics').value.trim(), redirect_text: document.getElementById('igForbiddenRedirect').value.trim()});
  toast(d.ok ? 'ذخیره شد' : (d.error || 'خطا'), !!d.ok);
}
async function igLoadFaq(){
  const box = document.getElementById('igFaqList');
  const d = await api('aikb_list');
  if (!d.ok) { box.innerHTML = igxErr(d); return; }
  const rows = (d.items || []).filter(x => (x.keywords || '').includes('#ig-faq'));
  box.innerHTML = rows.length ? rows.map(r => `<div class="igx-item"><div class="bd"><div class="tt">${esc(r.title)}</div><div class="ds">${esc(r.content)}</div></div>
    <div class="ac"><button class="btn btn-danger-ghost btn-xs" onclick="igFaqDelete(${+r.id})">حذف</button></div></div>`).join('')
    : igxEmpty('❓', 'هنوز سؤالی اضافه نشده.');
}
async function igFaqSave(){
  const q = document.getElementById('igFaqQ').value.trim(), a = document.getElementById('igFaqA').value.trim();
  if (!q || !a) { toast('سؤال و پاسخ هر دو لازم است', false); return; }
  const d = await api('aikb_save', {title: q, content: a, keywords: '#ig-faq', pinned: false, is_active: true});
  if (!d.ok) { toast(d.error || 'ناموفق', false); return; }
  toast('ذخیره شد'); document.getElementById('igFaqQ').value = ''; document.getElementById('igFaqA').value = ''; igLoadFaq();
}
async function igFaqDelete(id){
  if (!confirm('این سؤال متداول حذف شود؟')) return;
  await api('aikb_delete', {id}); igLoadFaq();
}
async function igLoadPhoneGate(){
  const d = await api('instagram_phone_gate_get'); if (!d.ok) return;
  document.getElementById('igPhoneGateEnabled').checked = !!d.enabled;
  document.getElementById('igPhoneGatePrompt').value = d.prompt_text || '';
  document.getElementById('igPhoneGateThanks').value = d.thanks_text || '';
}
async function igSavePhoneGate(){
  const d = await api('instagram_phone_gate_save', {
    enabled: document.getElementById('igPhoneGateEnabled').checked ? 1 : 0,
    prompt_text: document.getElementById('igPhoneGatePrompt').value.trim(),
    thanks_text: document.getElementById('igPhoneGateThanks').value.trim(),
  });
  toast(d.ok ? 'ذخیره شد' : (d.error || 'خطا'), !!d.ok);
  if (d.ok && IGX.ps) IGX.ps = null; // تنظیم کلی عوض شد؛ خلاصه‌ی پیج‌ها دوباره خوانده شود
}

// ---------- منوی بات ----------
let IG_BOT_NODES = [];
async function igBotLoadNodes(){
  const box = document.getElementById('igBotNodesList');
  const d = await api('botnodes_list');
  if (!d.ok) { box.innerHTML = igxErr(d); return; }
  IG_BOT_NODES = (d.nodes || []).filter(n => !n.channel || n.channel === 'instagram');
  if (!IG_BOT_NODES.length) { box.innerHTML = `<div class="igx-box">${igxEmpty('🧭', 'هنوز گره‌ای نساختی. با «منوی نمونه» شروع کن.')}</div>`; return; }
  box.innerHTML = IG_BOT_NODES.map(n => {
    let btns = []; try { btns = JSON.parse(n.buttons_json || '[]'); } catch(e) {}
    return `<div class="igx-item">
      <div class="bd"><div class="tt"><span class="mono">${esc(n.node_key)}</span>${+n.is_root ? '<span class="tag tag-accent">ریشه</span>' : ''}${n.title ? `<span class="muted" style="font-weight:400;font-size:12px">${esc(n.title)}</span>` : ''}</div>
        <div class="ds">${esc((n.text || '').slice(0, 120))}</div>
        <div class="mt">${btns.length ? btns.map(b => '[' + esc(b.text) + ']').join(' ') : 'بدون دکمه'}</div></div>
      <div class="ac"><button class="btn btn-ghost btn-xs" onclick="igBotNodeForm(IG_BOT_NODES.find(x=>+x.id===${+n.id}))">ویرایش</button>
        <button class="btn btn-danger-ghost btn-xs" onclick="igBotDeleteNode(${+n.id})">حذف</button></div></div>`;
  }).join('');
}
function igBotNodeForm(node){
  document.getElementById('igBotNodeFormTitle').textContent = node ? 'ویرایش گره' : 'گره جدید';
  document.getElementById('botNId').value = node ? node.id : '';
  document.getElementById('botNKey').value = node ? node.node_key : '';
  document.getElementById('botNTitle').value = node ? (node.title || '') : '';
  document.getElementById('botNIsRoot').checked = node ? !!(+node.is_root) : false;
  document.getElementById('botNText').value = node ? (node.text || '') : '';
  document.getElementById('botNButtons').innerHTML = '';
  document.getElementById('botNRules').innerHTML = '';
  let btns = [], rules = [];
  try { btns = node ? JSON.parse(node.buttons_json || '[]') : []; } catch(e) {}
  try { rules = node ? JSON.parse(node.rules_json || '[]') : []; } catch(e) {}
  if (btns.length) btns.forEach(igBotAddButtonRow); else igBotAddButtonRow();
  rules.forEach(igBotAddRuleRow);
  igxOpenDrawer('igBotNodeFormCard');
}
function igBotKeyOptionsHtml(selected){
  return IG_BOT_NODES.map(n => `<option value="${esc(n.node_key)}" ${n.node_key === selected ? 'selected' : ''}>${esc(n.node_key)}${n.title ? ' — ' + esc(n.title) : ''}</option>`).join('');
}
function igBotAddButtonRow(btn){
  btn = btn || {};
  const wrap = document.getElementById('botNButtons');
  if (wrap.children.length >= 3) { toast('حداکثر ۳ دکمه', false); return; }
  const type = btn.contact ? 'contact' : (btn.url ? 'url' : 'goto');
  const row = document.createElement('div'); row.className = 'ig-bm-row';
  row.innerHTML = `
    <input class="bm-btn-text" placeholder="متن دکمه" value="${esc(btn.text || '')}">
    <select class="bm-btn-type" onchange="igBotToggleBtnTarget(this)">
      <option value="goto" ${type === 'goto' ? 'selected' : ''}>رفتن به گره</option>
      <option value="url" ${type === 'url' ? 'selected' : ''}>لینک</option>
      <option value="contact" ${type === 'contact' ? 'selected' : ''}>درخواست شماره</option>
    </select>
    <select class="bm-btn-target-goto" style="${type === 'goto' ? '' : 'display:none'}">${igBotKeyOptionsHtml(btn.goto || '')}</select>
    <input class="bm-btn-target-url mono" dir="ltr" placeholder="https://..." value="${esc(btn.url || '')}" style="${type === 'url' ? '' : 'display:none'}">
    <button class="btn btn-ghost btn-xs" onclick="this.closest('.ig-bm-row').remove()">✕</button>`;
  wrap.appendChild(row);
}
function igBotToggleBtnTarget(sel){
  const row = sel.closest('.ig-bm-row');
  row.querySelector('.bm-btn-target-goto').style.display = sel.value === 'goto' ? '' : 'none';
  row.querySelector('.bm-btn-target-url').style.display = sel.value === 'url' ? '' : 'none';
}
function igBotAddRuleRow(rule){
  rule = rule || {};
  const row = document.createElement('div'); row.className = 'ig-bm-row';
  row.innerHTML = `<input class="bm-rule-contains" placeholder="اگر پیام شامل…" value="${esc(rule.contains || '')}">
    <span class="muted" style="flex-shrink:0;font-size:11px">برو به</span>
    <select class="bm-rule-goto">${igBotKeyOptionsHtml(rule.goto || '')}</select>
    <button class="btn btn-ghost btn-xs" onclick="this.closest('.ig-bm-row').remove()">✕</button>`;
  document.getElementById('botNRules').appendChild(row);
}
async function igBotSaveNode(){
  const key = document.getElementById('botNKey').value.trim();
  if (!key) { toast('کلید گره لازم است', false); return; }
  if (!/^[a-z0-9_]+$/i.test(key)) { toast('کلید فقط حروف انگلیسی، عدد و زیرخط', false); return; }
  const buttons = [];
  document.querySelectorAll('#botNButtons .ig-bm-row').forEach(row => {
    const text = row.querySelector('.bm-btn-text').value.trim(); if (!text) return;
    const type = row.querySelector('.bm-btn-type').value;
    if (type === 'goto') { const goto = row.querySelector('.bm-btn-target-goto').value; if (goto) buttons.push({text, goto}); }
    else if (type === 'url') { const url = row.querySelector('.bm-btn-target-url').value.trim(); if (url) buttons.push({text, url}); }
    else buttons.push({text, contact: true});
  });
  const rules = [];
  document.querySelectorAll('#botNRules .ig-bm-row').forEach(row => {
    const contains = row.querySelector('.bm-rule-contains').value.trim(), goto = row.querySelector('.bm-rule-goto').value;
    if (contains && goto) rules.push({contains, goto});
  });
  const d = await api('botnode_save', {
    id: document.getElementById('botNId').value || null, node_key: key, channel: 'instagram',
    title: document.getElementById('botNTitle').value.trim(), text: document.getElementById('botNText').value.trim(),
    is_root: document.getElementById('botNIsRoot').checked ? 1 : 0, buttons, rules,
  });
  if (d.ok) { toast('ذخیره شد'); igxCloseAll(); igBotLoadNodes(); } else toast(d.error || 'خطا', false);
}
async function igBotDeleteNode(id){
  if (!confirm('این گره حذف شود؟ دکمه‌هایی که به آن اشاره می‌کنند دیگر کار نمی‌کنند.')) return;
  await api('botnode_delete', {id}); igBotLoadNodes();
}
async function igBotLoadExample(){
  const nodes = [
    {node_key: 'ig_start', channel: 'instagram', title: 'منوی اصلی', is_root: true, text: 'سلام! چطور می‌تونم کمکتون کنم؟',
      buttons: [{text: 'قیمت‌ها', goto: 'ig_prices'}, {text: 'تماس با ما', url: 'https://instagram.com'}]},
    {node_key: 'ig_prices', channel: 'instagram', title: 'قیمت‌ها', text: 'قیمت‌ها از ۲۵۰ هزار تومان شروع می‌شه. برای سفارش، شماره‌تون رو بفرستید 🌷',
      buttons: [{text: '⬅️ بازگشت به منو', goto: 'ig_start'}]},
  ];
  const d = await api('botnodes_import', {nodes: JSON.stringify(nodes)});
  if (d.ok) { toast('منوی نمونه ساخته شد'); igBotLoadNodes(); } else toast(d.error || 'خطا', false);
}
function igBotSimBubble(text, dir, buttons){
  const chat = document.getElementById('igBotSimChat');
  const div = document.createElement('div'); div.className = 'ig-bm-bubble ' + dir;
  let html = esc(text || '');
  if (buttons && buttons.length) {
    html += '<div class="ig-bm-bubble-btns">' + buttons.map(b => {
      if (b.url) return `<button data-url="${esc(b.url)}" onclick="window.open(this.dataset.url,'_blank')">${esc(b.text)}</button>`;
      if (b.callback) return `<button data-cb="${esc(b.callback)}" onclick="igBotSimSend(null, this.dataset.cb)">${esc(b.text)}</button>`;
      return `<button disabled>${esc(b.text)}</button>`;
    }).join('') + '</div>';
  }
  div.innerHTML = html; chat.appendChild(div); chat.scrollTop = chat.scrollHeight;
}
async function igBotSimSend(evt, callback){
  const input = document.getElementById('igBotSimInput');
  const text = callback ? null : input.value.trim();
  if (!callback && !text) return;
  if (text) { igBotSimBubble(text, 'in'); input.value = ''; }
  const d = await api('bot_simulate', {channel: 'instagram', text, callback});
  if (!d.ok) return;
  if (!d.matched) { igBotSimBubble('(بات گره‌ای برای این پیام پیدا نکرد؛ پیام به فرم، پاسخ خودکار یا AI می‌رسد)', 'out'); return; }
  igBotSimBubble(d.message.text, 'out', d.message.buttons);
}

// ==================== ۴) فروش و ابزارها ====================
function igProductForm(p){
  document.getElementById('igProductFormCard').style.display = '';
  document.getElementById('igPId').value = p ? p.id : '';
  document.getElementById('igPTitle').value = p ? p.title : '';
  document.getElementById('igPPrice').value = p ? (p.price || '') : '';
  document.getElementById('igPStock').value = p ? (p.stock || '') : '';
  document.getElementById('igPTitle').focus();
}
async function igLoadProducts(){
  const box = document.getElementById('igProductsList');
  const d = await api('instagram_products_list');
  if (!d.ok) { box.innerHTML = igxErr(d); return; }
  const rows = d.rows || [];
  window.__igProducts = {}; rows.forEach(p => window.__igProducts[p.id] = p);
  box.innerHTML = rows.length ? rows.map(p => `<div class="igx-item"><div class="bd"><div class="tt">${esc(p.title)}</div>
      <div class="mt">${p.price ? Number(p.price).toLocaleString('fa-IR') + ' تومان' : 'بدون قیمت'} · موجودی: ${faNum(p.stock ?? '—')}</div></div>
      <div class="ac"><button class="btn btn-ghost btn-xs" onclick="igProductForm(window.__igProducts[${+p.id}])">ویرایش</button><button class="btn btn-danger-ghost btn-xs" onclick="igDeleteProduct(${+p.id})">حذف</button></div></div>`).join('')
    : igxEmpty('📦', 'هنوز محصولی ثبت نشده.');
}
async function igSaveProduct(){
  const payload = {id: document.getElementById('igPId').value || null, title: document.getElementById('igPTitle').value.trim(),
    price: document.getElementById('igPPrice').value, stock: document.getElementById('igPStock').value, is_active: 1};
  if (!payload.title) { toast('عنوان لازم است', false); return; }
  const d = await api('instagram_products_save', payload);
  if (d.ok) { toast('ذخیره شد'); document.getElementById('igProductFormCard').style.display = 'none'; igLoadProducts(); } else toast(d.error || 'خطا', false);
}
async function igDeleteProduct(id){ if (!confirm('این محصول حذف شود؟')) return; await api('instagram_products_delete', {id}); igLoadProducts(); }
async function igLoadOrders(){
  const box = document.getElementById('igOrdersList');
  const d = await api('instagram_orders_list');
  if (!d.ok) { box.innerHTML = igxErr(d); return; }
  const rows = d.rows || [];
  const statusFa = {pending: 'در انتظار', confirmed: 'تأییدشده', shipped: 'ارسال‌شده', done: 'انجام‌شده', canceled: 'لغوشده'};
  box.innerHTML = rows.length ? rows.map(o => `<div class="igx-item"><div class="bd"><div class="tt">${esc(o.full_name || 'مشتری بدون‌نام')} <span class="muted" style="font-weight:400;font-size:11px">#${faNum(o.id)}</span></div>
      <div class="ds">جمع: ${Number(o.total || 0).toLocaleString('fa-IR')} تومان${o.notes ? ' · ' + esc(o.notes) : ''}</div><div class="mt">${esc(o.created_at || '')}</div></div>
      <div class="ac"><select style="height:30px;padding:0 8px;font-size:12px;width:auto" onchange="igSetOrderStatus(${+o.id}, this.value)">${Object.keys(statusFa).map(s => `<option value="${s}" ${s === o.status ? 'selected' : ''}>${statusFa[s]}</option>`).join('')}</select>
        <button class="btn btn-danger-ghost btn-xs" onclick="igDeleteOrder(${+o.id})">حذف</button></div></div>`).join('')
    : igxEmpty('🧾', 'هنوز سفارشی ثبت نشده.');
}
async function igCreateOrder(){
  const title = document.getElementById('igOItemTitle').value.trim();
  if (!title) { toast('عنوان کالا لازم است', false); return; }
  const items = [{title, qty: parseInt(document.getElementById('igOQty').value || '1', 10), unit_price: parseFloat(document.getElementById('igOPrice').value || '0')}];
  const d = await api('instagram_orders_create', {items_json: JSON.stringify(items), person_id: document.getElementById('igOPersonId').value, notes: document.getElementById('igONotes').value.trim()});
  if (!d.ok) { toast(d.error || 'خطا', false); return; }
  toast('سفارش ثبت شد');
  ['igOItemTitle', 'igOPrice', 'igONotes', 'igOPersonId'].forEach(i => document.getElementById(i).value = '');
  igLoadOrders();
}
async function igSetOrderStatus(id, status){ const d = await api('instagram_orders_status', {id, status}); toast(d.ok ? 'وضعیت به‌روز شد' : 'خطا', !!d.ok); }
async function igDeleteOrder(id){ if (!confirm('این سفارش حذف شود؟')) return; await api('instagram_orders_delete', {id}); igLoadOrders(); }
async function igAnalyzeMedia(){
  const box = document.getElementById('igMediaResult');
  const url = document.getElementById('igMediaUrl').value.trim();
  if (!url) { toast('آدرس رسانه لازم است', false); return; }
  box.innerHTML = '<span class="muted">در حال تحلیل… (چند ثانیه)</span>';
  const d = await api('instagram_analyze_media', {media_url: url, kind: document.getElementById('igMediaKind').value, prompt: document.getElementById('igMediaPrompt').value.trim()});
  box.innerHTML = d.ok ? `<div class="igx-box" style="margin:0;background:var(--surface-2)">${esc(d.text || '')}</div>` : igxErr(d);
}

// ==================== ۵) اتصال و عیب‌یابی ====================
async function igLoadStatus(){
  const d = await api('instagram_mgmt_status');
  igxSetHtml('igMgmtBanner', d.ok && d.connected ? 'اتصال رسمی مستقیم متا هم فعال است، ولی پیج‌های این صفحه مستقل از آن‌اند.' : '');
}
async function apLoad(){
  const d = await igxLoadAp();
  if (!d.ok) return;
  const tag = document.getElementById('apStateTag');
  document.getElementById('apBase').value = d.base_url || '';
  document.getElementById('apKey').placeholder = d.key_masked ? d.key_masked + ' (ذخیره شده؛ خالی = بدون تغییر)' : 'ak_live_...';
  document.getElementById('apMode').value = d.mode || 'webhook';
  const active = (d.pages || []).filter(p => p.is_active == 1).length;
  if (!d.configured) { tag.className = 'tag tag-warn'; tag.textContent = 'وصل نیست'; }
  else { tag.className = 'tag tag-ok'; tag.textContent = `وصل · ${faNum(active)} پیج فعال`; }
  const row = (t, v, extra) => `<div class="igx-item" style="padding:9px 12px;align-items:center"><div class="bd" style="font-size:12.5px"><span class="muted">${t}</span></div><div style="font-size:12.5px;text-align:end;min-width:0;word-break:break-all">${v}</div>${extra || ''}</div>`;
  const lines = [];
  if (d.configured) {
    lines.push(row('حساب یار API', `<span class="mono">${esc(d.user_email || '—')}</span>`));
    if (d.missing_scopes && d.missing_scopes.length) lines.push(row('scope ناقص', `<span class="tag tag-warn mono">${esc(d.missing_scopes.join(', '))}</span>`));
    lines.push(row('آدرس وب‌هوک', `<span class="mono">${esc(d.webhook_url)}</span> ${d.webhook_id ? '<span class="tag tag-ok">ثبت شده</span>' : (d.mode === 'webhook' ? '<span class="tag tag-warn">ثبت نشده</span>' : '')}`));
    if (!d.webhook_https) lines.push(row('HTTPS', '<span class="tag tag-warn">نیست</span> یار API فقط وب‌هوک HTTPS قبول می‌کند؛ polling را انتخاب کن.'));
    lines.push(row('آخرین وب‌هوک', esc(d.last_webhook_at || '—')));
    lines.push(row('آخرین polling', esc(d.last_poll_at || '—') + (d.last_poll_error ? ` <span class="tag tag-warn">${esc(d.last_poll_error)}</span>` : '')));
    lines.push(row('آخرین همگام‌سازی', esc(d.last_sync_at || '—')));
    if (d.pages && d.pages.length) lines.push(row('پیج‌ها', d.pages.map(p => `<span class="tag ${p.is_active == 1 ? 'tag-ok' : 'tag-warn'}" dir="ltr">${esc(p.username || p.label)}</span>`).join(' ')));
  }
  igxSetHtml('apInfo', lines.join('') || igxEmpty('⚡', 'هنوز اتصالی ذخیره نشده.'));
}
function apMsg(ok, html){ igxSetHtml('apResult', `<span class="tag ${ok ? 'tag-ok' : 'tag-warn'}">${ok ? 'انجام شد' : 'ناموفق'}</span> ${html || ''}`); }
async function igxAfterConnChange(){ await Promise.all([igxLoadPages(), igxLoadStats()]); await apLoad(); igxRenderChrome(); }
async function apSave(){
  const base_url = document.getElementById('apBase').value.trim(), api_key = document.getElementById('apKey').value.trim(), mode = document.getElementById('apMode').value;
  igxSetHtml('apResult', '<span class="muted">در حال اتصال…</span>');
  const d = await api('apichi_save', {base_url, api_key, mode});
  if (!d.ok) { apMsg(false, esc(d.error || '')); return; }
  document.getElementById('apKey').value = '';
  const s = d.sync || {};
  let msg = s.ok ? `${faNum(s.total || 0)} پیج در یار API؛ ${faNum(s.added || 0)} پیج جدید اضافه شد.` : ('همگام‌سازی ناموفق: ' + esc(s.error || ''));
  if (d.missing_scopes && d.missing_scopes.length) msg += ` <span class="tag tag-warn">کلید این scopeها را ندارد: ${esc(d.missing_scopes.join(', '))}</span>`;
  if (mode === 'webhook') msg += ' حالا «ثبت خودکار وب‌هوک» را بزن.';
  apMsg(true, msg); igxAfterConnChange();
}
async function apSync(){
  const d = await api('apichi_sync');
  if (!d.ok) { apMsg(false, esc(d.error || '')); return; }
  apMsg(true, `${faNum(d.total)} پیج · جدید: ${faNum(d.added)} · به‌روز: ${faNum(d.updated)} · غیرفعال‌شده: ${faNum(d.disabled)}`);
  igxAfterConnChange();
}
async function apRegisterWebhook(){
  igxSetHtml('apResult', '<span class="muted">در حال ثبت…</span>');
  const d = await api('apichi_webhook_register');
  if (!d.ok) { apMsg(false, esc(d.error || '')); return; }
  const t = d.test || {};
  apMsg(true, `وب‌هوک #${faNum(d.webhook_id)} ثبت شد. تست: ${t.ok ? '✅ موفق (' + faNum(t.ms || 0) + 'ms)' : '⚠️ ' + esc(t.error || ('HTTP ' + (t.status_code || '?')))}`);
  igxAfterConnChange();
}
async function apTestWebhook(){
  const d = await api('apichi_webhook_test');
  apMsg(!!d.ok, d.ok ? `تست موفق (${faNum(d.ms || 0)}ms)` : esc(d.error || ('HTTP ' + (d.status_code || '?'))));
}
async function apPoll(){
  igxSetHtml('apResult', '<span class="muted">در حال دریافت…</span>');
  const d = await api('apichi_poll');
  apMsg(!d.error, d.error ? esc(d.error) : `${faNum(d.processed || 0)} رویداد تازه پردازش شد.`);
  apLoad();
}
async function apSetMode(){ await api('apichi_mode', {mode: document.getElementById('apMode').value}); apLoad(); }
async function apDisconnect(){
  if (!confirm('اتصال یار API قطع شود؟ پیج‌های یار API غیرفعال می‌شوند (گفتگوهای قبلی می‌مانند).')) return;
  const d = await api('apichi_disconnect');
  apMsg(!!d.ok, ''); igxAfterConnChange();
}

// ---------- اتصال مستقیم (روش قدیمی) ----------
async function rcAddAccount(){
  const box = document.getElementById('rcAddResult');
  const label = document.getElementById('rcNewLabel').value.trim(), username = document.getElementById('rcNewUsername').value.trim();
  const color = document.getElementById('rcNewColor').value || '#6366f1', key = document.getElementById('rcNewKey').value.trim();
  if (!key) { box.innerHTML = '<span class="tag tag-warn">Account Key لازم است</span>'; return; }
  box.innerHTML = '<span class="muted">در حال ثبت…</span>';
  const d = await api('roboclick_account_add', {label, username, color, account_key: key});
  if (!d.ok) { box.innerHTML = igxErr(d); return; }
  ['rcNewLabel', 'rcNewUsername', 'rcNewKey'].forEach(i => document.getElementById(i).value = '');
  await igxLoadPages(); igxRenderChrome();
  box.innerHTML = `${igxOk('پیج اضافه شد')} حالا وب‌هوک و Secret آن را ثبت کن: <a href="#page/${+d.id}/settings">تنظیمات همین پیج ←</a>`;
}
async function rcLoadIntegrationKey(){
  const d = await api('roboclick_integration_get'); if (!d.ok) return;
  document.getElementById('rcIntegrationKey').placeholder = d.key_set ? d.key_masked + ' (ذخیره شده؛ خالی یعنی بدون تغییر)' : 'rk_...';
  document.getElementById('rcReturnUrl').value = d.return_url || '';
}
async function rcSaveIntegrationKey(){
  const box = document.getElementById('rcIntegrationResult'), key = document.getElementById('rcIntegrationKey').value.trim();
  if (!key) { box.innerHTML = '<span class="tag tag-warn">کلید را وارد کن</span>'; return; }
  const d = await api('roboclick_integration_save', {key});
  if (d.ok) { box.innerHTML = igxOk('ذخیره شد'); document.getElementById('rcIntegrationKey').value = ''; rcLoadIntegrationKey(); } else box.innerHTML = igxErr(d);
}
async function rcStartOAuth(){
  const box = document.getElementById('rcOauthResult');
  box.innerHTML = '<span class="muted">در حال ساخت لینک…</span>';
  const d = await api('roboclick_oauth_start', {use_free_trial: document.getElementById('rcUseFreeTrial').checked});
  if (!d.ok) { box.innerHTML = igxErr(d); return; }
  window.open(d.authorize_url, '_blank');
  box.innerHTML = igxOk('تب جدید باز شد') + ' بعد از تأیید در اینستاگرام، خودکار به همین صفحه برمی‌گردی.';
}
function rcCheckOauthReturn(){
  const p = new URLSearchParams(location.search), status = p.get('rc_oauth');
  if (!status) return false;
  toast(p.get('rc_msg') || (status === 'ok' ? 'اتصال با موفقیت انجام شد' : 'اتصال ناموفق بود'), status === 'ok');
  const url = new URL(location.href);
  url.searchParams.delete('rc_oauth'); url.searchParams.delete('rc_msg');
  url.hash = status === 'ok' ? 'home' : 'connect/legacy';
  history.replaceState({}, '', url.toString());
  return true;
}

// ---------- لاگ یار API ----------
let AP_LOG_LAST = null, AP_LOG_TIMER = null, AP_LOG_KINDS = {};
const AP_LOG_TAG = {out: 'tag-mute', in: 'tag-ok', event: 'tag-ok', poll: 'tag-mute', action: 'tag-mute'};
async function apLogHealth(){
  const box = document.getElementById('apLogHealth');
  box.innerHTML = igxLoad('در حال بررسی…');
  const d = await api('apichi_health');
  if (!d.ok) { box.innerHTML = igxErr(d); return; }
  box.innerHTML = (d.checks || []).map(c => `<div class="aplog-check"><span class="aplog-dot ${esc(c[0])}"></span><div><b>${esc(c[1])}</b>${c[2] ? `<div class="muted" style="font-size:12px;margin-top:2px">${esc(c[2])}</div>` : ''}</div></div>`).join('');
  document.getElementById('apLogEnabled').checked = !!d.log_enabled;
  document.getElementById('apLogDays').value = d.log_days || 14;
}
function apLogFilters(extra){
  return Object.assign({kind: document.getElementById('apLogKind').value, ok: document.getElementById('apLogOk').value, q: document.getElementById('apLogQ').value.trim()}, extra || {});
}
function apLogRenderFlow(stats, kinds){
  const sel = document.getElementById('apLogKind');
  if (sel.options.length <= 1) for (const k in kinds) sel.insertAdjacentHTML('beforeend', `<option value="${esc(k)}">${esc(kinds[k])}</option>`);
  document.getElementById('apLogFlow').innerHTML = Object.keys(kinds).map(k => {
    const s = stats[k] || {total: 0, failed: 0};
    return `<div class="aplog-node ${sel.value === k ? 'on' : ''}" data-k="${esc(k)}" onclick="apLogPick(this.dataset.k)">${esc(kinds[k])}<b>${faNum(s.total)}${s.failed ? ` <span class="tag tag-warn" style="font-size:10px">${faNum(s.failed)} خطا</span>` : ''}</b><span class="muted" style="font-size:10.5px">${s.last_at ? 'آخرین: ' + esc(s.last_at) : '۲۴ ساعت: هیچ'}</span></div>`;
  }).join('');
}
function apLogPick(k){ const sel = document.getElementById('apLogKind'); sel.value = sel.value === k ? '' : k; apLogReload(); }
function apLogRow(r){
  const ok = r.ok == 1 ? '<span class="tag tag-ok">ok</span>' : '<span class="tag tag-warn">خطا</span>';
  return `<div class="ig-log-row" id="apLog${+r.id}">
    <div class="ig-log-head" onclick="apLogOpen(${+r.id})">
      <span class="tag ${AP_LOG_TAG[r.kind] || 'tag-mute'}">${esc(AP_LOG_KINDS[r.kind] || r.kind)}</span>
      ${ok} ${r.http_code ? `<span class="muted" style="font-size:10.5px">HTTP ${esc(r.http_code)}</span>` : ''}
      <span style="font-size:12px">${esc(r.summary || '')}</span><span class="grow"></span>
      ${r.duration_ms != null ? `<span class="muted" style="font-size:10.5px">${esc(r.duration_ms)}ms</span>` : ''}
      <span class="muted" style="font-size:10.5px">${esc(r.created_at || '')}</span>
    </div><div class="ig-log-body" id="apLogBody${+r.id}"></div></div>`;
}
async function apLogLoad(more){
  const box = document.getElementById('apLogList');
  const d = await api('apichi_log_list', apLogFilters(more && AP_LOG_LAST ? {before: AP_LOG_LAST} : {}));
  if (!d.ok) { box.innerHTML = igxErr(d); return; }
  AP_LOG_KINDS = d.kinds || {};
  apLogRenderFlow(d.stats || {}, AP_LOG_KINDS);
  const rows = d.rows || [], html = rows.map(apLogRow).join('');
  if (!more) box.innerHTML = html || igxEmpty('🔌', 'لاگی با این فیلتر نیست. یک دایرکت تستی به پیج بده یا «تست وب‌هوک» را بزن.');
  else box.insertAdjacentHTML('beforeend', html);
  if (rows.length) AP_LOG_LAST = rows[rows.length - 1].id;
  document.getElementById('apLogMore').style.display = d.has_more ? '' : 'none';
}
function apLogReload(){ AP_LOG_LAST = null; apLogLoad(false); }
async function apLogOpen(id){
  const row = document.getElementById('apLog' + id), body = document.getElementById('apLogBody' + id);
  if (row.classList.contains('open')) { row.classList.remove('open'); return; }
  const d = await api('apichi_log_get', {id});
  if (!d.ok) { toast(d.error || 'خطا', false); return; }
  const r = d.row;
  const page = r.ig_account_id ? igxPage(r.ig_account_id) : null;
  body.innerHTML = `<div class="muted" style="font-size:11px;margin-top:8px" dir="ltr">${esc((r.method || '') + ' ' + (r.endpoint || ''))}${r.ref ? ' · ' + esc(r.ref) : ''}${r.ig_account_id ? ' · igAccountId ' + esc(r.ig_account_id) : ''}</div>
    ${page ? `<div style="margin-top:6px"><span class="tag" style="background:${esc(igxPC(page))}22;color:${esc(igxPC(page))}">${esc(page.label)}</span></div>` : ''}
    <div class="muted" style="font-size:11px;margin-top:8px">${r.kind === 'in' ? 'بدنه‌ای که یار API فرستاد:' : (r.kind === 'event' ? 'رویداد:' : 'درخواست:')}</div><pre>${esc(igFmtJson(r.request_data))}</pre>
    <div class="muted" style="font-size:11px">${r.kind === 'event' ? 'payload داده‌شده به پایپ‌لاین:' : 'پاسخ:'}</div><pre>${esc(igFmtJson(r.response_data))}</pre>`;
  row.classList.add('open');
}
function apLogAutoToggle(){ if (AP_LOG_TIMER) { clearInterval(AP_LOG_TIMER); AP_LOG_TIMER = null; } if (document.getElementById('apLogAuto').checked) AP_LOG_TIMER = setInterval(apLogReload, 5000); }
async function apLogClear(){
  const k = document.getElementById('apLogKind').value;
  if (!confirm(k ? 'لاگ‌های این نوع پاک شود؟' : 'کل لاگ یار API پاک شود؟')) return;
  const d = await api('apichi_log_clear', {kind: k});
  if (d.ok) { toast('پاک شد'); apLogReload(); } else toast(d.error || 'خطا', false);
}
async function apLogSaveSettings(){
  const d = await api('apichi_log_settings', {enabled: document.getElementById('apLogEnabled').checked, days: document.getElementById('apLogDays').value});
  toast(d.ok ? 'ذخیره شد' : (d.error || 'خطا'), !!d.ok);
}

// ---------- لاگ خام ----------
let IG_RAW_TIMER = null;
function igToggleAutoRefresh(){
  if (IG_RAW_TIMER) { clearInterval(IG_RAW_TIMER); IG_RAW_TIMER = null; }
  if (document.getElementById('igRawAuto').checked) IG_RAW_TIMER = setInterval(igLoadRawLog, 5000);
}
async function igLoadRawLog(){
  const box = document.getElementById('igRawLogList');
  const d = await api('instagram_mgmt_raw_log');
  if (!d.ok) { box.innerHTML = igxErr(d); return; }
  const rows = d.rows || [];
  if (!rows.length) { box.innerHTML = `<div class="igx-box">${igxEmpty('🪵', 'هنوز چیزی لاگ نشده.')}</div>`; return; }
  const open = new Set(Array.from(box.querySelectorAll('.ig-log-row.open')).map(e => e.id));
  box.innerHTML = rows.map(r => {
    const inb = r.action === 'webhook_in';
    const okB = r.ok === null ? '' : (r.ok == 1 ? '<span class="tag tag-ok">ok</span>' : '<span class="tag tag-warn">fail</span>');
    return `<div class="ig-log-row ${open.has('igLog' + r.id) ? 'open' : ''}" id="igLog${+r.id}">
      <div class="ig-log-head" onclick="this.parentNode.classList.toggle('open')">
        ${inb ? '<span class="tag tag-ok">ورودی</span>' : '<span class="tag tag-mute">خروجی</span>'}
        <span class="tag tag-mute">${r.channel === 'instagram_apichi' ? 'یار API' : 'اتصال مستقیم'}</span>
        <span class="mono" style="font-size:11px">${esc(r.action || '')}</span>
        ${okB} ${r.http_code ? `<span class="muted" style="font-size:10.5px">HTTP ${esc(r.http_code)}</span>` : ''}
        <span class="grow"></span><span class="muted" style="font-size:10.5px">${esc(r.created_at || '')}</span>
      </div>
      <div class="ig-log-body">
        ${r.error ? `<div class="tag tag-warn" style="margin-top:8px">${esc(r.error)}</div>` : ''}
        <div class="muted" style="font-size:11px;margin-top:8px">${inb ? 'رویداد ورودی:' : 'درخواست:'}</div><pre>${esc(igFmtJson(r.request_data))}</pre>
        <div class="muted" style="font-size:11px">${inb ? 'رویداد کامل:' : 'پاسخ:'}</div><pre>${esc(igFmtJson(r.response_data))}</pre>
      </div></div>`;
  }).join('');
}
async function igClearRawLog(){
  if (!confirm('لاگ خام پاک شود؟')) return;
  const d = await api('instagram_mgmt_raw_log_clear');
  if (d.ok) { toast('پاک شد'); igLoadRawLog(); } else toast('خطا', false);
}

// ==================== راه‌اندازی ====================
(async function igxBoot(){
  // منوهای کناری
  document.querySelectorAll('#igxPageSide button[data-s]').forEach(b => b.addEventListener('click', () => igxGoTab(b.dataset.s)));
  ['shared', 'tools', 'connect'].forEach(v => document.querySelectorAll(`#igxV-${v} .igx-side button[data-s]`).forEach(b => {
    if (!b.getAttribute('onclick')) b.addEventListener('click', () => igxGo(v + '/' + b.dataset.s));
  }));
  // فیلتر کامنت‌ها
  document.getElementById('igxCmFilter').addEventListener('click', e => {
    const b = e.target.closest('button[data-f]'); if (!b) return;
    IGX.cmFilter = b.dataset.f;
    document.querySelectorAll('#igxCmFilter button').forEach(x => x.classList.toggle('on', x === b));
    igxLoadComments(false);
  });
  // پست / استوری
  document.getElementById('igxContentSeg').addEventListener('click', e => {
    const b = e.target.closest('button[data-k]'); if (!b) return;
    IGX.contentKind = b.dataset.k;
    document.querySelectorAll('#igxContentSeg button').forEach(x => x.classList.toggle('on', x === b));
    igxLoadContent(false);
  });
  // نوع رسانه در ابزار AI
  document.getElementById('igMediaKindSeg').addEventListener('click', e => {
    const b = e.target.closest('button[data-val]'); if (!b) return;
    document.getElementById('igMediaKind').value = b.dataset.val;
    document.querySelectorAll('#igMediaKindSeg button').forEach(x => x.classList.toggle('on', x === b));
    document.getElementById('igMediaPromptField').style.display = b.dataset.val === 'audio' ? 'none' : '';
  });
  igxBindPS();
  window.addEventListener('beforeunload', e => { if (IGX.psDirty) { e.preventDefault(); e.returnValue = ''; } });

  rcCheckOauthReturn();
  await igxReloadAll();
  window.addEventListener('hashchange', igxRoute);
  igxRoute();
})();
