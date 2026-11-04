// pages/inbox.js.php — صندوق ورودی (دور ۳)
// تازه‌ها: تخصیص چت به پشتیبان (بر عهده گرفتن/انتقال/AI، حضور هم‌زمان)، سابقه‌ی کامل با «پیام‌های قبلی»،
// خط زمانی یکپارچه (کامنت‌ها + پاسخ‌های خودکار)، پروفایل و چتِ هر سوشال جدا، ریسپانسیو کامل.
const IB_CHN = {bale:'بله', telegram:'تلگرام', roubika:'روبیکا', bale_safir:'صفیر', sms:'پیامک', email:'ایمیل', webchat:'گفتگوی سایت', instagram:'اینستاگرام'};
const IB_CHC = {bale:'ch-bale', telegram:'ch-telegram', roubika:'ch-roubika', bale_safir:'ch-safir', sms:'ch-sms', email:'ch-email', voice:'ch-voice', instagram:'ch-instagram', webchat:'ch-landing'};
const IB_CHI = {instagram:'📸', webchat:'💻', telegram:'✈️', bale:'🟢', roubika:'🟣', bale_safir:'📣', sms:'✉️', email:'📧'};
const IB_TAB_ORDER = ['instagram','webchat','telegram','bale','roubika','bale_safir','sms','email'];
// برچسب منبع پیام خودکار — همه‌ی پاسخ‌های بات (کامنت، دایرکت، ریپلای، منو، فرم، AI) یکسان نمایش داده می‌شوند
const IB_SRC = {autoreply:'پاسخ خودکار', botmenu:'منوی بات', form:'فرم', ai:'هوش مصنوعی', comment_dm:'دایرکت اتوماسیون کامنت', comment_reply:'پاسخ عمومی زیر کامنت', follow_gate:'فالو اجباری', phone_gate:'درخواست شماره', ai_comment:'AI کامنت', campaign:'کمپین', welcome:'خوشامد', flow:'سناریو'};
const IB_SRC_ICO = {autoreply:'⚡', botmenu:'🧭', form:'📝', ai:'🤖', comment_dm:'🎯', comment_reply:'💬', follow_gate:'➕', phone_gate:'📞', ai_comment:'🤖', campaign:'📣', welcome:'👋', flow:'🔀'};
const IB_ROLE = {admin:'مدیر', supervisor:'سرپرست', operator:'پشتیبان'};
let IB_VIEW = (() => { try { return localStorage.getItem('ib_view') === 'list' ? 'list' : 'tabs'; } catch(e) { return 'tabs'; } })();
let IB_ASSIGN = (() => { try { return localStorage.getItem('ib_assign') || ''; } catch(e) { return ''; } })();
let IB_IGACC = 0;
let IB_COUNTS = null;
let IB_PAGE = 1, IB_PAGES = 1;
let CUR_CONVO = null;       // person_id گفتگوی باز
let CUR_PERSON = null;      // داده‌ی شخص گفتگوی باز (هویت‌ها…)
let REPLY_CHANNEL = null;
let REPLY_IDENTITY = null;  // هویت پیش‌فرض پاسخ (آخرین پیام ورودی)
let IB_IDENT_FILTER = 0;    // مورد ۶: فقط پیام‌های یک هویت/سوشال
let IS_BLOCKED = false;
let PENDING_MEDIA = null;
let SHOW_DELETED = false;
let IB_MSGS = [];           // پیام‌های لودشده‌ی گفتگوی باز (برای «پیام‌های قبلی»)
let IB_ACTS = [];           // کامنت‌ها/رویدادهای خط زمانی
let IB_HAS_MORE = false;
let IB_ASSIGN_ST = null;    // وضعیت تخصیص گفتگوی باز
let IB_AGENTS = null;       // همکاران (برای انتقال)
let IB_ME = 0;
let IB_PING_T = null, IB_LIST_T = null, IB_TYPING_AT = 0;

function ibChip(c){ return `<span class="chip"><span class="cdot ${IB_CHC[c]||''}"></span>${IB_CHN[c]||esc(c)}</span>`; }
function pageChip(label, color){
  if(!label) return '';
  const c = color || '#6366f1';
  return `<span class="chip" style="background:${esc(c)}22;color:${esc(c)};border:1px solid ${esc(c)}55">${esc(label)}</span>`;
}
function ibIsMobile(){ return window.matchMedia('(max-width:760px)').matches; }
function ibAssignChip(c){
  if(c.ai_assigned) return '<span class="as-chip ai">🤖 AI</span>';
  if(c.assigned_user_id) return +c.assigned_user_id === IB_ME ? '<span class="as-chip mine">👤 من</span>' : `<span class="as-chip other">👤 ${esc(c.assigned_name||'')}</span>`;
  return '<span class="as-chip none">بی‌مسئول</span>';
}

// ==================== فهرست گفتگوها ====================
async function loadConvos(page=1, silent=false){
  IB_PAGE = page;
  const q = document.getElementById('ibSearch').value.trim();
  const channel = document.getElementById('ibChannel').value;
  const unread = document.getElementById('ibUnread').checked ? 1 : '';
  const blocked = document.getElementById('ibBlocked').checked ? 1 : '';
  const ig_account_id = channel === 'instagram' ? IB_IGACC : 0;
  const box = document.getElementById('convoRows');
  if(!silent && !box.children.length) box.innerHTML = '<div class="empty">در حال بارگذاری…</div>';
  const d = await api('inbox_conversations', {q, channel, unread, blocked, page, ig_account_id, assigned: IB_ASSIGN, with_counts: 1});
  if(!d.ok){ if(!silent) box.innerHTML='<div class="empty">خطا در بارگذاری</div>'; return; }
  if(d.counts){ IB_COUNTS = d.counts; ibRenderTabs(); ibFit(); }
  IB_PAGES = d.pages;
  updateNavBadge(d.unread_total);
  if(!d.conversations.length){
    const msg = unread ? 'پیام خوانده‌نشده‌ای نیست' : (IB_ASSIGN==='me' ? 'چتی بر عهده‌ی شما نیست' : (IB_ASSIGN==='none' ? 'همه‌ی چت‌ها مسئول دارند 👌' : 'گفتگویی پیدا نشد'));
    box.innerHTML = `<div class="empty" style="padding:30px 16px">${msg}</div>`;
    renderIbPager(d); return;
  }
  box.innerHTML = d.conversations.map(c=>{
    const name = esc(c.full_name) || esc(c.phone) || 'ناشناس';
    const initial = (c.full_name||c.phone||'?').trim().replace('@','').charAt(0) || '?';
    const unreadBadge = +c.unread>0 ? `<span class="convo-badge">${c.unread>99?'۹۹+':faNum(c.unread)}</span>` : '';
    const mine = c.last_dir === 'out';
    const srcIco = mine && c.last_source ? (IB_SRC_ICO[c.last_source]||'🤖') + ' ' : '';
    const preview = (mine ? `<span class="me">${srcIco || '↩ '}</span>` : '') + (esc((c.last_body||'').slice(0,60)) || '<span class="muted">(بدون متن)</span>');
    const chTag = c.last_channel ? ibChip(c.last_channel) : '';
    const pgTag = pageChip(c.last_account_label, c.last_account_color);
    const viewers = (c.viewers||[]).filter(v=>v.user_id!==IB_ME);
    const eye = viewers.length ? `<span class="eye" title="${esc(viewers.map(v=>v.name).join('، '))} این چت را باز کرده">${viewers.some(v=>v.typing)?'✍️':'👀'}</span>` : '';
    const active = CUR_CONVO==c.person_id ? ' active' : '';
    return `<div class="convo${active}" data-pid="${c.person_id}" onclick="openConvo(${c.person_id})">
      <div class="convo-avatar">${esc(initial)}${eye}</div>
      <div class="convo-main">
        <div class="convo-top"><span class="convo-name">${name}</span><span class="convo-time">${toFa(c.last_at)}</span></div>
        <div class="convo-top"><span class="convo-preview">${preview}</span>${unreadBadge}</div>
        <div class="convo-tags">${chTag}${pgTag}${ibAssignChip(c)}${c.first_responder_name?`<span class="as-chip" title="اولین پشتیبانی که جواب داد">▶ ${esc(c.first_responder_name)}</span>`:''}</div>
      </div>
    </div>`;
  }).join('');
  renderIbPager(d);
}

// ==================== تب کانال‌ها / لیست یکجا / فیلتر مسئول ====================
function ibSetView(v){
  IB_VIEW = v === 'list' ? 'list' : 'tabs';
  try { localStorage.setItem('ib_view', IB_VIEW); } catch(e) {}
  ibApplyView();
  loadConvos(1);
}
function ibSetAssign(a){
  IB_ASSIGN = a || '';
  try { localStorage.setItem('ib_assign', IB_ASSIGN); } catch(e) {}
  document.querySelectorAll('#ibAssignSeg button').forEach(b => b.classList.toggle('on', b.dataset.a === IB_ASSIGN));
  loadConvos(1);
}
function ibApplyView(){
  document.querySelectorAll('#ibViewSeg button').forEach(b => b.classList.toggle('on', b.dataset.v === IB_VIEW));
  document.querySelectorAll('#ibAssignSeg button').forEach(b => b.classList.toggle('on', b.dataset.a === IB_ASSIGN));
  document.body.classList.toggle('ib-mode-tabs', IB_VIEW === 'tabs');
  const hint = document.getElementById('ibViewHint');
  if (hint) hint.textContent = IB_VIEW === 'tabs' ? 'هر شبکه‌ی اجتماعی در یک تب جدا' : 'همه‌ی گفتگوها با هم، به ترتیب آخرین پیام';
  ibRenderTabs();
}
function ibRenderTabs(){
  const tabs = document.getElementById('ibTabs'), sub = document.getElementById('ibSubTabs');
  if (!tabs) return;
  const on = IB_VIEW === 'tabs';
  tabs.classList.toggle('on', on); sub.classList.toggle('on', false);
  if (!on) { sub.innerHTML = ''; return; }
  const cur = document.getElementById('ibChannel').value;
  const ch = (IB_COUNTS && IB_COUNTS.channels) || {};
  const keys = IB_TAB_ORDER.filter(k => ch[k] || k === cur).concat(Object.keys(ch).filter(k => !IB_TAB_ORDER.includes(k)));
  let allC = 0, allU = 0; Object.values(ch).forEach(x => { allC += x.convos; allU += x.unread; });
  const tab = (k, label, ico, c) => `<button type="button" role="tab" class="ib-tab ${cur === k ? 'on' : ''}" data-ch="${esc(k)}" onclick="ibPickTab(this.dataset.ch)" aria-selected="${cur === k}">
      <span>${ico}</span>${esc(label)}${c ? `<span class="n">${faNum(c.convos)}</span>${c.unread ? `<span class="u">${c.unread > 99 ? '۹۹+' : faNum(c.unread)}</span>` : ''}` : ''}</button>`;
  tabs.innerHTML = tab('', 'همه', '📥', IB_COUNTS ? {convos: allC, unread: allU} : null)
    + keys.map(k => tab(k, IB_CHN[k] || k, IB_CHI[k] || '💬', ch[k] || {convos: 0, unread: 0})).join('');
  const pages = (IB_COUNTS && IB_COUNTS.ig_pages) || [];
  if (cur === 'instagram' && pages.length > 1) {
    sub.classList.add('on');
    sub.innerHTML = `<button type="button" class="ib-sub ${!IB_IGACC ? 'on' : ''}" onclick="ibPickPage(0)">همه‌ی پیج‌ها</button>`
      + pages.map(p => `<button type="button" class="ib-sub ${IB_IGACC === p.id ? 'on' : ''}" style="--pc:${esc(p.color || '#6366f1')}" onclick="ibPickPage(${+p.id})"><span class="d" style="background:${esc(p.color || '#6366f1')}"></span>${esc(p.label)} <span class="muted">${faNum(p.convos)}</span>${p.unread ? `<span class="u">${faNum(p.unread)}</span>` : ''}</button>`).join('');
  } else sub.innerHTML = '';
}
function ibPickTab(ch){
  const sel = document.getElementById('ibChannel');
  if (![...sel.options].some(o => o.value === ch)) sel.insertAdjacentHTML('beforeend', `<option value="${esc(ch)}">${esc(IB_CHN[ch] || ch)}</option>`);
  sel.value = ch; IB_IGACC = 0;
  loadConvos(1);
}
function ibPickPage(id){ IB_IGACC = +id || 0; loadConvos(1); }

function renderIbPager(d){
  const pg = document.getElementById('ibPager');
  if(d.pages<=1){ pg.innerHTML = d.total?`<span class="muted" style="font-size:11px">${faNum(d.total)} گفتگو</span>`:''; return; }
  pg.innerHTML = `
    <button class="btn btn-ghost btn-sm" ${d.page<=1?'disabled style=opacity:.4':''} onclick="loadConvos(${d.page-1})">قبلی</button>
    <span class="muted" style="font-size:11px">${faNum(d.page)}/${faNum(d.pages)} · ${faNum(d.total)} گفتگو</span>
    <button class="btn btn-ghost btn-sm" ${d.page>=d.pages?'disabled style=opacity:.4':''} onclick="loadConvos(${d.page+1})">بعدی</button>`;
}

function updateNavBadge(total){
  const b = document.getElementById('inboxBadge');
  if(total>0){ if(b){ b.textContent = total>99?'۹۹+':faNum(total); b.style.display=''; } }
  else if(b){ b.style.display='none'; }
}

// ==================== نوار تیم (چت‌های من، انتقال همه، آمار) ====================
async function ibLoadTeam(){
  const d = await api('inbox_agents');
  const box = document.getElementById('ibTeam');
  if(!d.ok || !box) return;
  IB_AGENTS = d.agents || []; IB_ME = +d.me || 0;
  if(!d.ready){ box.innerHTML = '<span class="muted" style="font-size:11.5px">برای تخصیص چت به پشتیبان‌ها «به‌روزرسانی دیتابیس» را بزنید</span>'; return; }
  const sup = d.support || {};
  box.innerHTML = `
    <span class="ib-chip-me"><span class="dot ${sup.online===false?'off':''}"></span>${sup.online===false?'بیرون از ساعت کاری':'ساعت کاری'} · چت‌های باز من: <b>${faNum(d.mine_open||0)}</b></span>
    ${d.mine_open ? `<button class="btn btn-ghost btn-sm" onclick="ibTransferAllDlg()" title="آخر شیفت یا بیرون از ساعت کاری">🔁 سپردن چت‌های من</button>` : ''}
    <button class="btn btn-ghost btn-sm" onclick="ibStatsDlg(1)">📊 آمار پشتیبان‌ها</button>`;
}

// ==================== گفتگوی باز ====================
async function openConvo(personId){
  if(CUR_CONVO && CUR_CONVO !== personId) api('inbox_leave', {person_id: CUR_CONVO});
  CUR_CONVO = personId;
  SHOW_DELETED = false; IB_IDENT_FILTER = 0;
  document.querySelectorAll('.convo').forEach(el=>el.classList.toggle('active', +el.dataset.pid === +personId));
  document.getElementById('threadEmpty').style.display='none';
  document.getElementById('threadContent').style.display='flex';
  document.getElementById('inboxWrap').classList.add('ib-thread-open');
  document.body.classList.add('ib-mob-thread'); ibFit();
  document.getElementById('threadBody').innerHTML='<div class="empty">در حال بارگذاری…</div>';
  document.getElementById('ibAssignBar').innerHTML='';
  const ok = await ibFetchThread(false);
  if(!ok) return;
  loadConvos(IB_PAGE, true);
  loadProfilePane(personId);
  ibStartPing();
}
function ibCloseThread(){
  document.getElementById('inboxWrap').classList.remove('ib-thread-open', 'ip-open');
  document.body.classList.remove('ib-mob-thread'); setTimeout(ibFit, 0);
  if(CUR_CONVO) api('inbox_leave', {person_id: CUR_CONVO});
  CUR_CONVO = null; ibStopPing();
  document.querySelectorAll('.convo').forEach(el=>el.classList.remove('active'));
  document.getElementById('threadEmpty').style.display='flex';
  document.getElementById('threadContent').style.display='none';
}

/** گرفتن پیام‌ها. older=true یعنی «پیام‌های قبلی» (اضافه به بالای گفتگو) */
async function ibFetchThread(older){
  const payload = {person_id: CUR_CONVO, include_deleted: SHOW_DELETED ? 1 : 0, identity_id: IB_IDENT_FILTER || 0, limit: 60};
  if(older && IB_MSGS.length) payload.before_id = IB_MSGS[0].id;
  const pid = CUR_CONVO;
  const d = await api('inbox_thread', payload);
  if(pid !== CUR_CONVO) return false;
  if(!d.ok){ document.getElementById('threadBody').innerHTML='<div class="empty">'+esc(d.error||'خطا')+'</div>'; return false; }
  IB_HAS_MORE = !!d.has_more;
  if(older){
    const body = document.getElementById('threadBody'), h0 = body.scrollHeight, t0 = body.scrollTop;
    IB_MSGS = (d.messages||[]).concat(IB_MSGS);
    IB_ACTS = (d.activity||[]).concat(IB_ACTS);
    renderMessages(false);
    body.scrollTop = body.scrollHeight - h0 + t0; // جای اسکرول حفظ شود
    return true;
  }
  IB_MSGS = d.messages || []; IB_ACTS = d.activity || [];
  if(d.person){
    CUR_PERSON = d.person;
    REPLY_CHANNEL = d.reply_channel; REPLY_IDENTITY = d.reply_identity_id || null;
    IB_ASSIGN_ST = d.assign || null;
    renderThreadHead(d.person);
    renderAssignBar();
    const db = document.getElementById('aiDraftBtn');
    if(db) db.style.display = (d.ai_ready === 1) ? '' : 'none';
  }
  renderFilterBar();
  renderMessages(true);
  updateReplyState();
  return true;
}
async function reloadThread(){ if(CUR_CONVO) await ibFetchThread(false); }
function ibLoadOlder(){ ibFetchThread(true); }

function toggleShowDeleted(){
  SHOW_DELETED = !SHOW_DELETED;
  ibCloseMore();
  reloadThread();
}
async function deleteMsg(id){
  if(!confirm('این پیام حذف شود؟ (لاگش برای مدیر می‌ماند، قابل بازگرداندن است)')) return;
  const d = await api('message_delete', {id});
  if(!d.ok){ toast(d.error||'حذف نشد', false); return; }
  toast('حذف شد'); reloadThread();
}
async function restoreMsg(id){
  const d = await api('message_restore', {id});
  if(!d.ok){ toast(d.error||'بازگردانده نشد', false); return; }
  toast('بازگردانده شد'); reloadThread();
}

function ibIdentityLabel(i){
  if(!i) return '';
  return (IB_CHN[i.channel]||i.channel) + (i.ig_account_label ? ' · ' + i.ig_account_label : '') + (i.username ? ' · @' + i.username : '');
}

function renderThreadHead(p){
  const name = esc(p.full_name) || esc(p.phone) || ((p.identities||[]).find(i=>i.username) ? '@'+esc(p.identities.find(i=>i.username).username) : 'ناشناس');
  const seen = new Set();
  const ids = (p.identities||[]).filter(i=>{ const k=i.channel+'|'+(i.ig_account_id||''); if(seen.has(k)) return false; seen.add(k); return true; })
    .map(i=>ibChip(i.channel) + (i.ig_account_label?(' '+pageChip(i.ig_account_label,i.ig_account_color)):'')).join(' ');
  IS_BLOCKED = !!p.blocked;
  const aiAssigned = !!p.ai_assigned;
  document.getElementById('threadHead').innerHTML = `
    <div class="th-main">
      <button class="btn btn-ghost btn-sm ib-back" onclick="ibCloseThread()" aria-label="بازگشت به فهرست">→</button>
      <div style="min-width:0">
        <div class="th-title">${name} ${IS_BLOCKED?'<span class="tag tag-warn" style="font-size:10px">بلاک</span>':''}${aiAssigned?' <span class="tag tag-accent" style="font-size:10px">🤖 با AI</span>':''}</div>
        <div class="th-ids">${ids||'<span class="muted">بدون هویت</span>'}</div>
      </div>
    </div>
    <div class="th-actions">
      <button class="btn btn-ghost btn-sm" onclick="ibToggleProfile(true)" title="اطلاعات مشتری و سوشال‌ها">👤 <span class="ib-hide-sm">مشتری</span></button>
      <div class="ib-more">
        <button class="btn btn-ghost btn-sm" onclick="ibToggleMore(event)" aria-label="بیشتر">⋯</button>
        <div class="ib-more-menu card" id="ibMoreMenu">
          ${aiAssigned
            ? `<button class="btn btn-ghost btn-sm" onclick="unassignAi()">🤖 پس گرفتن از AI</button>`
            : `<button class="btn btn-ghost btn-sm" onclick="assignAi()">🤖 سپردن به AI</button>`}
          <button class="btn btn-ghost btn-sm" onclick="ibAssignHistoryDlg()">🕘 سابقه‌ی پاسخ‌گویی</button>
          <button class="btn btn-ghost btn-sm" onclick="refreshProfile()">↻ به‌روزرسانی از شبکه</button>
          <button class="btn btn-ghost btn-sm" onclick="toggleShowDeleted()">🗑 ${SHOW_DELETED?'مخفی‌کردن':'نمایش'} حذف‌شده‌ها</button>
          ${IS_BLOCKED ? `<button class="btn btn-ghost btn-sm" style="color:var(--ok)" onclick="toggleBlock(false)">رفع بلاک</button>`
                       : `<button class="btn btn-ghost btn-sm" style="color:var(--err)" onclick="toggleBlock(true)">⛔ بلاک</button>`}
          <a href="index.php?page=persons&person=${p.id}" class="btn btn-ghost btn-sm">پروفایل کامل</a>
        </div>
      </div>
    </div>`;
}
function ibToggleMore(e){ e.stopPropagation(); document.getElementById('ibMoreMenu')?.classList.toggle('on'); }
function ibCloseMore(){ document.getElementById('ibMoreMenu')?.classList.remove('on'); }
document.addEventListener('click', e => { if(!e.target.closest('.ib-more')) ibCloseMore(); });

// ---------- نوار تخصیص (مورد ۲ و ۲-۱) ----------
function renderAssignBar(){
  const bar = document.getElementById('ibAssignBar');
  const s = IB_ASSIGN_ST;
  if(!bar) return;
  if(!s || !s.ready){ bar.className='ib-assignbar'; bar.innerHTML=''; updateReplyState(); return; }
  const viewers = (s.viewers||[]);
  const vHtml = viewers.length ? `<span class="ib-viewers"><span class="pulse"></span>${viewers.map(v=>esc(v.name)+(v.typing?' در حال نوشتن…':'')).join('، ')} ${viewers.some(v=>v.typing)?'':'همین الان این چت را باز کرده'}</span>` : '';
  const started = s.first_responder_name ? `<span class="sub">· شروع‌کننده: ${esc(s.first_responder_name)}</span>` : '';
  let cls = 'ib-assignbar', html = '';
  if(s.ai_assigned){
    cls += ' ai';
    html = `<span class="grow">🤖 این چت به <b>هوش مصنوعی</b> سپرده شده و پیام‌های تازه را خودش جواب می‌دهد.</span>
      <button class="btn btn-primary btn-sm" onclick="unassignAi()">پس گرفتن و بر عهده گرفتن</button>`;
  } else if(!s.assigned_user_id){
    cls += ' none';
    html = `<span class="grow">این چت <b>مسئول ندارد</b>. ${started}</span>
      <button class="btn btn-primary btn-sm" onclick="ibClaim(false)">✋ بر عهده می‌گیرم</button>
      <button class="btn btn-ghost btn-sm" onclick="ibTransferDlg()">🔁 سپردن</button>`;
  } else if(s.mine){
    html = `<span class="grow">✅ این چت <b>بر عهده‌ی شماست</b> ${s.assigned_at?`<span class="muted" style="font-size:11px">از ${toFa(s.assigned_at)}</span>`:''} ${started}</span>
      <button class="btn btn-ghost btn-sm" onclick="ibTransferDlg()">🔁 انتقال به همکار / AI</button>
      <button class="btn btn-ghost btn-sm" onclick="ibRelease()">رها کردن</button>`;
  } else {
    cls += ' taken';
    html = `<span class="grow" ${s.unrestricted?'title="شما مدیر/سرپرست هستید؛ بدون بر عهده گرفتن هم می‌توانید پاسخ دهید"':''}>👤 <span class="who">${esc(s.assigned_name)}</span> در حال پاسخ‌گویی است ${s.assigned_at?`<span class="sub">از ${toFa(s.assigned_at)}</span>`:''} ${started}
        ${s.unrestricted ? '<span class="sub">· شما بدون بر عهده گرفتن هم می‌توانید پاسخ دهید</span>' : ''}</span>
      <button class="btn ${s.unrestricted?'btn-ghost':'btn-primary'} btn-sm" onclick="ibClaim(true)">بر عهده گرفتن</button>
      ${s.unrestricted ? `<button class="btn btn-ghost btn-sm" onclick="ibTransferDlg()">🔁 انتقال</button>` : ''}`;
  }
  bar.className = cls;
  bar.innerHTML = html + (vHtml ? `<div style="flex-basis:100%">${vHtml}</div>` : '');
  updateReplyState();
}

async function ibClaim(force){
  if(!CUR_CONVO) return;
  const s = IB_ASSIGN_ST || {};
  if(force && s.assigned_name && !confirm(`این چت الان بر عهده‌ی «${s.assigned_name}» است. بر عهده بگیرید؟\n(در سابقه ثبت می‌شود و به ایشان در چت تیم اطلاع داده می‌شود)`)) return;
  const d = await api('inbox_claim', {person_id: CUR_CONVO, force: force ? 1 : 0});
  if(d.assign){ IB_ASSIGN_ST = d.assign; renderAssignBar(); }
  if(!d.ok){
    if(d.taken){ if(confirm((d.error||'این چت مسئول دارد') + '\nبا این حال بر عهده بگیرید؟')) return ibClaim(true); return; }
    toast(d.error||'خطا', false); return;
  }
  toast('چت بر عهده‌ی شما قرار گرفت');
  loadConvos(IB_PAGE, true); ibLoadTeam();
  document.getElementById('replyText').focus();
}
async function ibRelease(){
  if(!CUR_CONVO || !confirm('این چت رها شود؟ (بی‌مسئول می‌شود و هر همکاری می‌تواند بر عهده بگیرد)')) return;
  const d = await api('inbox_release', {person_id: CUR_CONVO});
  if(d.assign){ IB_ASSIGN_ST = d.assign; renderAssignBar(); }
  if(!d.ok){ toast(d.error||'خطا', false); return; }
  toast('چت رها شد'); loadConvos(IB_PAGE, true); ibLoadTeam();
}

// ---------- پنجره‌ها ----------
function ibModal(title, html){
  document.getElementById('ibModalTitle').textContent = title;
  document.getElementById('ibModalBody').innerHTML = html;
  document.getElementById('ibModalBg').classList.add('on');
}
function ibCloseModal(){ document.getElementById('ibModalBg').classList.remove('on'); }

function ibAgentsHtml(sel){
  const list = (IB_AGENTS||[]).map(a=>`<div class="ib-agent" data-to="${a.id}" onclick="ibPickAgent(this)">
      <div class="av">${esc((a.username||'?').charAt(0))}</div>
      <div style="flex:1;min-width:0"><b>${esc(a.username)}</b> <span class="muted" style="font-size:11px">${IB_ROLE[a.role]||''}</span>
        <div class="muted" style="font-size:11px">${a.online?'🟢 آنلاین':'⚪ آفلاین'} · ${faNum(a.open||0)} چت باز</div></div>
    </div>`).join('');
  return `<div class="ib-agent" data-to="ai" onclick="ibPickAgent(this)"><div class="av">🤖</div>
      <div style="flex:1"><b>هوش مصنوعی</b><div class="muted" style="font-size:11px">پیام‌های تازه را خودکار جواب می‌دهد (مثلاً بیرون از ساعت کاری)</div></div></div>
    ${list || '<div class="muted" style="font-size:12px;padding:8px">همکار فعال دیگری نیست</div>'}
    <div class="field" style="margin-top:10px"><label>یادداشت برای همکار (اختیاری)</label><input id="ibTrNote" placeholder="مثلاً: منتظر واریز است، فردا پیگیری شود"></div>`;
}
let IB_PICK = null;
function ibPickAgent(el){ document.querySelectorAll('.ib-agent').forEach(x=>x.classList.remove('sel')); el.classList.add('sel'); IB_PICK = el.dataset.to; }
async function ibTransferDlg(){
  if(!IB_AGENTS) await ibLoadTeam();
  IB_PICK = null;
  ibModal('انتقال این چت', ibAgentsHtml() + `<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
    <button class="btn btn-ghost btn-sm" onclick="ibCloseModal()">انصراف</button>
    <button class="btn btn-primary btn-sm" onclick="ibDoTransfer()">انتقال</button></div>`);
}
async function ibDoTransfer(){
  if(!IB_PICK){ toast('مقصد را انتخاب کنید', false); return; }
  const d = await api('inbox_transfer', {person_id: CUR_CONVO, to: IB_PICK, note: document.getElementById('ibTrNote')?.value || ''});
  if(d.assign){ IB_ASSIGN_ST = d.assign; renderAssignBar(); }
  if(!d.ok){ toast(d.error||'خطا', false); return; }
  ibCloseModal();
  toast(d.to === 'ai' ? 'چت به هوش مصنوعی سپرده شد' : `چت به ${d.to} منتقل شد`);
  loadConvos(IB_PAGE, true); ibLoadTeam(); reloadThread();
}
async function ibTransferAllDlg(){
  if(!IB_AGENTS) await ibLoadTeam();
  IB_PICK = null;
  ibModal('سپردن همه‌ی چت‌های من', `<p class="muted" style="font-size:12px;margin-top:0">برای پایان شیفت یا بیرون از ساعت کاری: همه‌ی چت‌هایی که الان بر عهده‌ی شماست به یک همکار یا به هوش مصنوعی سپرده می‌شود و در سابقه‌ی هر چت ثبت می‌شود.</p>`
    + ibAgentsHtml() + `<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
    <button class="btn btn-ghost btn-sm" onclick="ibCloseModal()">انصراف</button>
    <button class="btn btn-primary btn-sm" onclick="ibDoTransferAll()">سپردن همه</button></div>`);
}
async function ibDoTransferAll(){
  if(!IB_PICK){ toast('مقصد را انتخاب کنید', false); return; }
  const d = await api('inbox_transfer_all', {to: IB_PICK, note: document.getElementById('ibTrNote')?.value || ''});
  if(!d.ok){ toast(d.error||'خطا', false); return; }
  ibCloseModal(); toast(`${faNum(d.moved||0)} چت سپرده شد`);
  loadConvos(1); ibLoadTeam(); if(CUR_CONVO) reloadThread();
}
async function ibStatsDlg(days){
  ibModal('آمار پاسخ‌گویی پشتیبان‌ها', '<div class="empty">در حال بارگذاری…</div>');
  const d = await api('inbox_agent_stats', {days});
  if(!d.ok){ document.getElementById('ibModalBody').innerHTML = '<div class="empty">خطا</div>'; return; }
  const seg = [1,7,30].map(n=>`<button type="button" data-v="${n}" class="${n===days?'on':''}" onclick="ibStatsDlg(${n})">${n===1?'امروز (۲۴ ساعت)':faNum(n)+' روز'}</button>`).join('');
  const rows = (d.rows||[]).map(r=>`<tr><td><b>${esc(r.username)}</b></td><td>${faNum(r.replies)}</td><td>${faNum(r.chats)}</td><td>${faNum(r.started)}</td><td>${faNum(r.open)}</td></tr>`).join('');
  document.getElementById('ibModalBody').innerHTML = `<div class="ib-seg" style="margin-bottom:12px">${seg}</div>
    <div style="overflow-x:auto"><table class="ib-stat-tbl"><thead><tr><th>پشتیبان</th><th>پیام پاسخ</th><th>دایرکت/چت جواب‌داده</th><th>چت شروع‌کرده</th><th>چت باز الان</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="5" class="muted">داده‌ای نیست</td></tr>'}</tbody></table></div>
    <p class="muted" style="font-size:11px">«دایرکت/چت جواب‌داده» = تعداد مشتری‌های متمایزی که این پشتیبان در این بازه دست‌کم یک پاسخ دستی برایشان فرستاده. پاسخ‌های خودکار بات اینجا شمرده نمی‌شوند.</p>`;
}
const IB_ACT_LBL = {claim:'بر عهده گرفت', auto:'با اولین پاسخ بر عهده گرفت', takeover:'از همکار بر عهده گرفت', transfer:'منتقل کرد', to_ai:'به هوش مصنوعی سپرد', release:'رها کرد'};
async function ibAssignHistoryDlg(){
  ibCloseMore();
  ibModal('سابقه‌ی پاسخ‌گویی این چت', '<div class="empty">در حال بارگذاری…</div>');
  const d = await api('inbox_assign_history', {person_id: CUR_CONVO});
  const rows = (d.rows||[]).map(h=>`<div class="ib-hist"><b>${esc(h.by_name||'سیستم')}</b> ${IB_ACT_LBL[h.action]||esc(h.action)}
      ${h.action==='transfer'||h.action==='takeover' ? ` ${h.from_name?'(از '+esc(h.from_name)+')':''} ${h.to_name?'← '+esc(h.to_name):''}` : ''}
      ${h.note?`<div class="muted" style="font-size:11px">📝 ${esc(h.note)}</div>`:''}
      <div class="muted" style="font-size:10.5px">${toFa(h.created_at)}</div></div>`).join('');
  document.getElementById('ibModalBody').innerHTML = rows || '<div class="empty">هنوز سابقه‌ای ثبت نشده</div>';
}

// ---------- حضور و به‌روزرسانی زنده ----------
function ibStartPing(){
  ibStopPing();
  IB_PING_T = setInterval(ibPing, 15000);
}
function ibStopPing(){ if(IB_PING_T){ clearInterval(IB_PING_T); IB_PING_T = null; } }
async function ibPing(typing){
  if(!CUR_CONVO || document.hidden) return;
  const pid = CUR_CONVO;
  const last = IB_MSGS.length ? IB_MSGS[IB_MSGS.length-1].id : 0;
  const d = await api('inbox_ping', {person_id: pid, typing: typing ? 1 : 0, last_id: last});
  if(!d.ok || pid !== CUR_CONVO) return;
  const was = JSON.stringify(IB_ASSIGN_ST);
  IB_ASSIGN_ST = d.assign;
  if(JSON.stringify(IB_ASSIGN_ST) !== was) renderAssignBar();
  if(d.new_messages > 0) ibAppendNew();
}
async function ibAppendNew(){
  const body = document.getElementById('threadBody');
  const atBottom = body.scrollHeight - body.scrollTop - body.clientHeight < 80;
  const d = await api('inbox_thread', {person_id: CUR_CONVO, identity_id: IB_IDENT_FILTER || 0, limit: 60});
  if(!d.ok) return;
  const known = new Set(IB_MSGS.map(m=>+m.id));
  const fresh = (d.messages||[]).filter(m=>!known.has(+m.id));
  if(!fresh.length) return;
  IB_MSGS = IB_MSGS.concat(fresh);
  REPLY_CHANNEL = d.reply_channel || REPLY_CHANNEL; REPLY_IDENTITY = d.reply_identity_id || REPLY_IDENTITY;
  renderMessages(atBottom);
  loadConvos(IB_PAGE, true);
}
function ibTyping(){
  const now = Date.now();
  if(now - IB_TYPING_AT > 8000){ IB_TYPING_AT = now; ibPing(true); }
}
document.addEventListener('visibilitychange', () => { if(!document.hidden && CUR_CONVO) ibPing(); });
window.addEventListener('beforeunload', () => { if(CUR_CONVO && navigator.sendBeacon){ try { const fd = new FormData(); fd.append('_csrf', CSRF); fd.append('person_id', CUR_CONVO); navigator.sendBeacon('index.php?api=inbox_leave', fd); } catch(e){} } });

// ==================== رندر پیام‌ها (خط زمانی یکپارچه — مورد ۱) ====================
function ibIdentityById(id){ return (CUR_PERSON && CUR_PERSON.identities || []).find(i=>+i.id === +id); }
function ibDay(dt){ return toFa(dt).split(' ').find(x=>x.includes('/')) || ''; }
function renderMessages(scrollBottom){
  const body = document.getElementById('threadBody');
  const items = IB_MSGS.map(m=>({t:m.created_at, k:'m', m})).concat(IB_ACTS.map(a=>({t:a.created_at, k:'a', a})));
  items.sort((x,y)=> x.t < y.t ? -1 : (x.t > y.t ? 1 : (x.k==='a' ? -1 : 1)));
  if(!items.length){ body.innerHTML = '<div class="empty">پیامی نیست</div>'; return; }
  let lastDay = '';
  const html = items.map(it=>{
    const day = ibDay(it.t);
    const sep = day && day !== lastDay ? `<div class="ib-day"><span>${day}</span></div>` : '';
    lastDay = day || lastDay;
    return sep + (it.k === 'a' ? activityHtml(it.a) : bubbleHtml(it.m));
  }).join('');
  body.innerHTML = (IB_HAS_MORE ? `<div class="ib-load-older"><button class="btn btn-ghost btn-sm" onclick="ibLoadOlder()">⬆ پیام‌های قبلی</button></div>` : (IB_MSGS.length > 20 ? '<div class="ib-day"><span>ابتدای گفتگو</span></div>' : '')) + html;
  if(scrollBottom) body.scrollTop = body.scrollHeight;
}
function activityHtml(a){
  if(a.kind === 'ig_comment'){
    const pg = a.account_label ? pageChip(a.account_label, a.account_color) : '';
    const post = a.post_title ? `«${esc(a.post_title)}»` : `<span class="mono" style="font-size:10.5px" dir="ltr">${esc(a.media_id||'')}</span>`;
    return `<div class="t-activity"><span class="ic">💬</span><div><b>کامنت زیر پست</b> ${post} ${pg}
      <div style="color:var(--ink);margin-top:2px">${esc(a.body||'')}</div>
      <div class="muted" style="font-size:10.5px;margin-top:2px">${toFa(a.created_at)} · ${a.replied_at ? '✅ پاسخ خودکار داده شد' : 'بدون پاسخ خودکار'}</div></div></div>`;
  }
  return '';
}
function bubbleHtml(m){
  const dir = m.direction==='out' ? 'out' : 'in';
  const failed = (m.direction==='out' && m.status==='failed') ? ' failed' : '';
  const idn = ibIdentityById(m.identity_id);
  const ch = IB_CHN[m.channel]||esc(m.channel);
  const pg = (m.channel==='instagram' && idn && idn.ig_account_label) ? pageChip(idn.ig_account_label, idn.ig_account_color) : '';
  const isComment = m.direction==='in' && m.media_type==='ig_comment';
  const auto = m.direction==='out' && m.source && m.source !== 'inbox';
  const statusTxt = m.direction==='out'
    ? (m.status==='failed' ? '✕ نرسید' : (m.channel==='webchat' ? (m.seen_at ? '✓✓ دیده شد' : '✓ ارسال شد') : '✓ ارسال شد'))
    : 'دریافتی';
  const statusCls = (m.direction==='out' && m.channel==='webchat' && m.seen_at && m.status!=='failed') ? 'style="color:var(--info)"' : '';
  let mediaHtml = '';
  if(m.media_type==='photo' && m.media_path){
    const isDirect = m.media_path.indexOf('uploads/')===0 || m.channel==='instagram' || m.media_path.indexOf('data:')===0;
    const src = isDirect ? esc(m.media_path) : `media.php?mid=${m.id}`;
    mediaHtml = `<div style="margin-bottom:6px"><img src="${src}" style="max-width:220px;max-height:220px;width:100%;object-fit:cover;border-radius:8px;display:block;cursor:pointer" loading="lazy" onclick="window.open(this.src,'_blank')"
         onerror="this.replaceWith(Object.assign(document.createElement('div'),{className:'muted',textContent:'🖼 تصویر در دسترس نیست'}))"></div>`;
  } else if(m.media_type==='document' && m.media_path){
    const src = m.media_path.indexOf('uploads/')===0 ? esc(m.media_path) : `media.php?mid=${m.id}`;
    mediaHtml = `<div style="margin-bottom:4px">📎 <a href="${src}" target="_blank" rel="noopener">دانلود فایل</a></div>`;
  } else if(m.media_type==='story_reply'){
    mediaHtml = `<div class="muted" style="font-size:11px;margin-bottom:4px">↩️ پاسخ به استوری</div>`;
  }
  // متن: پیشوندهای ثبت‌شده در لاگ (💬 کامنت زیر پست: / 💬 پاسخ عمومی زیر کامنت:) به برچسب تبدیل می‌شوند
  let text = String(m.body||'');
  text = text.replace(/^💬 کامنت زیر پست: /, '').replace(/^💬 پاسخ عمومی زیر کامنت: /, '').replace(/^↩️ ریپلای استوری: /, '');
  const bodyHtml = esc(text) || (mediaHtml ? '' : '<span class="muted">(بدون متن)</span>');
  let kind = '';
  if(isComment) kind = `<div class="t-kind"><span class="k">💬 کامنت زیر پست</span>${m.media_path?`<span class="mono" dir="ltr" style="font-weight:400">${esc(m.media_path)}</span>`:''}${pg}</div>`;
  else if(auto) kind = `<div class="t-kind"><span class="k">${IB_SRC_ICO[m.source]||'🤖'} ${IB_SRC[m.source]||esc(m.source)}</span>${pg}</div>`;
  else if(m.direction==='out' && m.sent_by_name) kind = `<div class="t-kind"><span>👤 ${esc(m.sent_by_name)}</span>${pg}</div>`;
  else if(pg) kind = `<div class="t-kind">${pg}</div>`;
  const pageHtml = (m.channel==='webchat' && m.direction==='in' && m.page_url)
    ? `<div class="t-meta" style="margin-top:2px"><a href="${esc(m.page_url)}" target="_blank" rel="noopener" class="mono" dir="ltr" style="font-size:10.5px">📍 ${esc(m.page_url)}</a></div>` : '';
  const isDeleted = !!m.deleted_at;
  const delBtn = isDeleted
    ? `<button class="btn btn-ghost btn-xs" style="margin-inline-start:6px" onclick="restoreMsg(${m.id})">↺ بازگرداندن</button>`
    : `<button class="msg-del-btn" onclick="deleteMsg(${m.id})" title="حذف پیام (لاگش می‌ماند)">🗑</button>`;
  const cls = `t-bubble ${dir}${failed}${isDeleted?' msg-deleted':''}${auto?' auto':''}${isComment?' comment':''}${m.source==='comment_reply'?' creply':''}`;
  return `<div class="${cls}" data-mid="${m.id}">
    ${isDeleted ? '<div class="note" style="margin-bottom:4px">🗑 این پیام حذف شده</div>' : ''}
    ${kind}${mediaHtml}${bodyHtml}${pageHtml}
    <div class="t-meta"><span>${ch}</span>·<span>${toFa(m.created_at)}</span>·<span ${statusCls}>${statusTxt}</span>${m.status==='failed'&&m.error?`<span title="${esc(m.error)}">ⓘ</span>`:''}${delBtn}</div>
  </div>`;
}

// ---------- وضعیت کادر پاسخ ----------
function ibReplyIdentity(){
  // اگر یک سوشال در پروفایل انتخاب شده، پاسخ از همان می‌رود (مورد ۶)
  if(IB_IDENT_FILTER) return ibIdentityById(IB_IDENT_FILTER);
  return ibIdentityById(REPLY_IDENTITY) || null;
}
function updateReplyState(){
  const hint = document.getElementById('replyHint');
  const replyBtn = document.getElementById('replyBtn');
  const attachBtn = document.getElementById('attachBtn');
  const ta = document.getElementById('replyText');
  const lock = document.getElementById('ibReplyLock');
  if(!hint) return;
  const idn = ibReplyIdentity();
  const ch = idn ? idn.channel : REPLY_CHANNEL;
  const replyable = ch && ['bale','telegram','roubika','webchat','instagram'].includes(ch) && !IS_BLOCKED;
  const s = IB_ASSIGN_ST;
  const locked = s && s.ready && !s.can_reply && !s.ai_assigned;
  if(replyable){
    hint.textContent = `پاسخ از ${idn ? ibIdentityLabel(idn) : (IB_CHN[ch]||ch)}${ibIsMobile()?'':' · Ctrl+Enter'}`;
    replyBtn.disabled = !!locked; ta.disabled = !!locked;
    if(attachBtn) attachBtn.style.display = '';
  } else {
    hint.textContent = IS_BLOCKED ? 'این چت بلاک است؛ برای پاسخ اول رفع بلاک کنید'
      : (ch ? `پاسخ مستقیم برای کانال ${IB_CHN[ch]||ch} پشتیبانی نمی‌شود` : 'کانال قابل پاسخ یافت نشد');
    replyBtn.disabled = true; ta.disabled = true;
    if(attachBtn) attachBtn.style.display = 'none';
  }
  if(lock){
    if(locked && replyable){
      lock.style.display = 'flex';
      lock.innerHTML = `<span>👤 <b>${esc(s.assigned_name||'همکار')}</b> در حال پاسخ‌گویی است.</span><button class="btn btn-primary btn-sm" onclick="ibClaim(true)">بر عهده گرفتن و پاسخ</button>`;
    } else lock.style.display = 'none';
  }
  PENDING_MEDIA = PENDING_MEDIA || null;
  updateAttachLabel();
}

// ==================== پروفایل (ستون سوم) + هر سوشال جدا (مورد ۶) ====================
function ibToggleProfile(on){
  const w = document.getElementById('inboxWrap');
  w.classList.toggle('ip-open', on === undefined ? !w.classList.contains('ip-open') : !!on);
}
async function loadProfilePane(personId){
  const empty = document.getElementById('profileEmpty');
  const box = document.getElementById('profileContent');
  empty.style.display='none'; box.style.display='block';
  box.innerHTML='<div class="ip-section"><span class="muted" style="font-size:12px">در حال بارگذاری…</span></div>';
  const d = await api('inbox_profile', {person_id:personId});
  if(personId !== CUR_CONVO) return;
  if(!d.ok){ box.innerHTML='<div class="ip-section"><span class="muted">'+esc(d.error||'خطا')+'</span></div>'; return; }
  const p = d.profile;
  if(CUR_PERSON && p.identities) CUR_PERSON.identities = p.identities; // هویت‌های کامل‌تر
  const name = esc(p.full_name) || esc(p.phone) || 'ناشناس';
  const initial = (p.full_name||p.phone||'?').trim().charAt(0);
  const st = p.stats || {sent:0,received:0};
  const groups = (p.groups||[]).map(g=>`<span class="tag" style="background:${esc(g.color)}22;color:${esc(g.color)}">${esc(g.name)}</span>`).join('') || '<span class="muted" style="font-size:11px">∙</span>';
  const tags = (p.tags||[]).map(t=>`<span class="tag tag-mute">${esc(t.name)}</span>`).join('') || '<span class="muted" style="font-size:11px">∙</span>';
  const ids = (p.identities||[]).map(i=>identityCardHtml(i)).join('');
  const forms = (p.form_responses||[]).slice(0,3).map(f=>
    `<div style="font-size:11.5px;padding:3px 0;color:var(--ink-soft)">📋 ${esc((f.form_name||f.title||'فرم').slice(0,30))} <span class="muted">${f.created_at?toFa(f.created_at):''}</span></div>`).join('');
  const cms = (p.ig_comments||[]).slice(0,8).map(c=>`<div class="ip-cm">
      <div>${c.account_label?pageChip(c.account_label,c.account_color):''} ${c.post_title?`<b>${esc(c.post_title)}</b>`:`<span class="mono muted" dir="ltr" style="font-size:10px">${esc(c.media_id||'')}</span>`}</div>
      <div style="margin-top:2px">💬 ${esc(c.body||'')}</div>
      <div class="muted" style="font-size:10px">${toFa(c.created_at)} · ${c.replied_at?'✅ پاسخ خودکار':'بی‌پاسخ'}</div></div>`).join('');
  const hist = (p.assign_history||[]).slice(0,4).map(h=>`<div style="font-size:11px;padding:2px 0;color:var(--ink-soft)">• ${esc(h.by_name||'سیستم')} ${IB_ACT_LBL[h.action]||esc(h.action)}${h.to_name&&h.action!=='claim'&&h.action!=='auto'?' ← '+esc(h.to_name):''} <span class="muted">${toFa(h.created_at)}</span></div>`).join('');
  box.innerHTML = `
    <div class="ip-section">
      <div class="ip-avatar">${esc(initial)}</div>
      <div class="ip-name">${name} ${p.blocked?'<span class="tag tag-warn" style="font-size:9px">بلاک</span>':''}</div>
      <div class="ip-sub">${p.phone?`<span dir="ltr">${esc('0'+String(p.phone).replace(/^98/,''))}</span>`:'<span class="muted">بدون شماره</span>'} · ${p.status==='member'?'عضو':'ناشناس'}</div>
      <div class="ip-stat-row">
        <div class="ip-stat"><b>${faNum(st.sent||0)}</b><span>ارسالی</span></div>
        <div class="ip-stat"><b>${faNum(st.received||0)}</b><span>دریافتی</span></div>
        <div class="ip-stat"><b>${faNum((p.identities||[]).length)}</b><span>سوشال</span></div>
      </div>
    </div>
    <div class="ip-section">
      <div class="ip-label"><span>سوشال‌ها و پیج‌ها</span><span class="muted" style="font-weight:400">برای دیدن چت همان سوشال بزنید</span></div>
      <div id="ipIdns">${ids||'<span class="muted" style="font-size:11px">∙</span>'}</div>
    </div>
    ${cms?`<div class="ip-section"><div class="ip-label"><span>کامنت‌ها روی پست‌ها</span><span>${faNum((p.ig_comments||[]).length)}</span></div>${cms}</div>`:''}
    <div class="ip-section">
      <div class="ip-label">گروه‌ها</div><div class="ip-chips">${groups}</div>
      <div class="ip-label" style="margin-top:10px">برچسب‌ها</div><div class="ip-chips">${tags}</div>
    </div>
    ${forms?`<div class="ip-section"><div class="ip-label">فرم‌های اخیر</div>${forms}</div>`:''}
    ${hist?`<div class="ip-section"><div class="ip-label"><span>سابقه‌ی پاسخ‌گویی</span><button class="btn btn-ghost btn-xs" onclick="ibAssignHistoryDlg()">همه</button></div>${hist}</div>`:''}
    <div class="ip-section">
      <a href="index.php?page=persons&person=${p.id}" class="btn btn-ghost btn-sm" style="width:100%;text-align:center">پروفایل کامل و ویرایش</a>
    </div>`;
  ibMarkIdentity();
  updateReplyState();
}
function identityCardHtml(i){
  const uname = i.username ? '@'+esc(i.username) : `<span class="mono muted" dir="ltr" style="font-size:10.5px">${esc(String(i.channel_uid||'').replace(/^rc\d+_/,''))}</span>`;
  const link = i.profile_url ? `<a href="${esc(i.profile_url)}" target="_blank" rel="noopener" onclick="event.stopPropagation()">مشاهده پروفایل در ${IB_CHN[i.channel]||i.channel} ↗</a>` : '';
  const rows = [
    i.full_name ? ['نام در این شبکه', esc(i.full_name)] : null,
    i.bio ? ['بیو', esc(i.bio)] : null,
    i.followers != null ? ['فالوور', faNum(i.followers)] : null,
    i.is_follower != null ? ['فالو کرده؟', i.is_follower ? 'بله ✅' : 'خیر'] : null,
    ['شناسه', `<span class="mono" dir="ltr" style="font-size:10.5px">${esc(i.channel_uid||'')}</span> <button class="btn btn-ghost btn-xs" onclick="event.stopPropagation();ibCopy('${esc(String(i.channel_uid||'').replace(/^rc\d+_/,''))}')">کپی</button>`],
    i.ig_page_username ? ['پیج ما', '@'+esc(i.ig_page_username)] : null,
    ['اولین دیده‌شدن', toFa(i.first_seen||'')],
    ['اولین / آخرین پیام', (i.first_msg_at?toFa(i.first_msg_at):'—') + ' / ' + (i.last_msg_at?toFa(i.last_msg_at):'—')],
  ].filter(Boolean).map(r=>`<div><span class="k">${r[0]}:</span> ${r[1]}</div>`).join('');
  return `<div class="ip-idn" data-iid="${i.id}" onclick="ibPickIdentity(${i.id})">
    <div class="r1"><span>${ibChip(i.channel)} ${i.ig_account_label?pageChip(i.ig_account_label,i.ig_account_color):''}</span><span style="font-size:12px;font-weight:600">${uname}</span></div>
    <div class="r2"><span>⬇ ${faNum(i.msg_in||0)} دریافتی</span><span>⬆ ${faNum(i.msg_out||0)} ارسالی</span>${i.last_msg_at?`<span>${toFa(i.last_msg_at)}</span>`:''}</div>
    <div class="det">${rows}${link?`<div style="margin-top:4px">${link}</div>`:''}</div>
  </div>`;
}
function ibMarkIdentity(){ document.querySelectorAll('.ip-idn').forEach(el=>el.classList.toggle('on', +el.dataset.iid === +IB_IDENT_FILTER)); }
function ibPickIdentity(id){
  IB_IDENT_FILTER = (+IB_IDENT_FILTER === +id) ? 0 : +id;
  ibMarkIdentity();
  ibFetchThread(false);
  if(ibIsMobile()) ibToggleProfile(false);
}
function renderFilterBar(){
  const bar = document.getElementById('ibFilterBar');
  if(!bar) return;
  const i = IB_IDENT_FILTER ? ibIdentityById(IB_IDENT_FILTER) : null;
  if(!i){ bar.style.display='none'; bar.innerHTML=''; return; }
  bar.style.display='flex';
  bar.innerHTML = `<span>🔎 فقط گفتگو در <b>${esc(ibIdentityLabel(i))}</b> — پاسخ هم از همین‌جا می‌رود</span>
    <button class="btn btn-ghost btn-xs" onclick="ibPickIdentity(${i.id})">✕ نمایش همه</button>`;
}
function ibCopy(t){
  const done = () => toast('کپی شد');
  if(navigator.clipboard && window.isSecureContext){ navigator.clipboard.writeText(t).then(done, ()=>prompt('کپی کنید:', t)); return; }
  const ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); done(); } catch(e){ prompt('کپی کنید:', t); }
  ta.remove();
}

// ===== پیام‌های آماده‌ی تیم =====
var CANNED_CACHE = null;
async function toggleCannedMenu(){
  const menu = document.getElementById('cannedMenu');
  if(menu.style.display !== 'none'){ menu.style.display = 'none'; return; }
  if(!CANNED_CACHE){
    const d = await api('canned_list');
    CANNED_CACHE = (d.ok && d.items) ? d.items : [];
  }
  menu.innerHTML = !CANNED_CACHE.length
    ? `<div class="muted" style="padding:8px;font-size:12px">هنوز پیام آماده‌ای ساخته نشده؛ از صفحه‌ی «پشتیبانی تیمی» اضافه کن.</div>`
    : CANNED_CACHE.map(it=>`<button type="button" class="btn btn-ghost btn-sm" style="display:block;width:100%;text-align:right;margin-bottom:2px" onclick="pickCanned(${it.id})">${esc(it.title)}</button>`).join('');
  menu.style.display = '';
}
async function pickCanned(id){
  document.getElementById('cannedMenu').style.display = 'none';
  if(!CUR_CONVO) return;
  const d = await api('canned_get', {id, person_id: CUR_CONVO});
  if(!d.ok){ toast(d.error||'دریافت نشد', false); return; }
  const box = document.getElementById('replyText');
  box.value = box.value.trim() ? (box.value.trim() + '\n' + d.text) : d.text;
  box.focus();
}
document.addEventListener('click', function(e){
  const menu = document.getElementById('cannedMenu');
  if(!menu || menu.style.display === 'none') return;
  if(!e.target.closest('#cannedMenu') && !e.target.closest('[onclick="toggleCannedMenu()"]')) menu.style.display = 'none';
});

// ===== هوش مصنوعی =====
async function aiDraft(){
  if(!CUR_CONVO) return;
  const btn = document.getElementById('aiDraftBtn');
  const box = document.getElementById('replyText');
  const old = btn ? btn.innerHTML : '';
  if(btn){ btn.disabled=true; btn.textContent='✦ …'; }
  try {
    const d = await api('ai_draft', {person_id:CUR_CONVO, hint:(box.value||'').trim()});
    if(!d.ok){ toast(d.error||'پیش‌نویس ساخته نشد', false); return; }
    box.value = d.text||''; box.focus();
    toast('پیش‌نویس آماده است، بازخوانی کن و بفرست');
  } finally { if(btn){ btn.disabled=false; btn.innerHTML=old; } }
}
async function assignAi(){
  ibCloseMore();
  if(!CUR_CONVO) return;
  if(!confirm('این گفتگو به هوش مصنوعی سپرده شود؟ از این لحظه پیام‌های تازه‌ی این مشتری را خودش جواب می‌دهد.')) return;
  const d = await api('inbox_ai_assign', {person_id:CUR_CONVO});
  if(!d.ok){ toast(d.error||'خطا', false); return; }
  toast('گفتگو به هوش مصنوعی سپرده شد');
  reloadThread(); loadConvos(IB_PAGE, true); ibLoadTeam();
}
async function unassignAi(){
  ibCloseMore();
  if(!CUR_CONVO) return;
  const d = await api('inbox_ai_unassign', {person_id:CUR_CONVO});
  if(!d.ok){ toast(d.error||'خطا', false); return; }
  toast('گفتگو به دست شما برگشت');
  reloadThread(); loadConvos(IB_PAGE, true); ibLoadTeam();
}

// ===== بلاک =====
async function toggleBlock(block){
  ibCloseMore();
  if(!CUR_CONVO) return;
  if(block && !confirm('این چت بلاک شود؟ پیام‌هایش دیگر پردازش نمی‌شود و از فهرست مخفی می‌شود.')) return;
  const d = await api(block?'inbox_block':'inbox_unblock', {person_id:CUR_CONVO});
  if(d.ok){
    toast(block?'چت بلاک شد':'رفع بلاک شد');
    if(block) ibCloseThread(); else reloadThread();
    loadConvos(IB_PAGE);
  } else toast(d.error||'خطا', false);
}

async function refreshProfile(){
  ibCloseMore();
  if(!CUR_CONVO) return;
  toast('در حال به‌روزرسانی از شبکه…');
  const d = await api('person_refresh', {person_id:CUR_CONVO});
  if(d.ok){
    toast((d.updated>0 ? `${faNum(d.updated)} هویت به‌روز شد` : 'چیزی برای به‌روزرسانی نبود') + (d.errors&&d.errors.length?` (${faNum(d.errors.length)} خطا)`:''));
    reloadThread(); loadProfilePane(CUR_CONVO);
  } else toast(d.error||'خطا', false);
}

async function sendReply(){
  if(!CUR_CONVO) return;
  const ta = document.getElementById('replyText');
  const text = ta.value.trim();
  if(!text && !PENDING_MEDIA){ toast('پیام خالی است', false); return; }
  const btn = document.getElementById('replyBtn');
  btn.disabled = true; btn.textContent = 'در حال ارسال…';
  const payload = {person_id:CUR_CONVO, text};
  const idn = ibReplyIdentity();
  if(idn) payload.identity_id = idn.id;
  if(PENDING_MEDIA) payload.media_path = PENDING_MEDIA;
  const d = await api('inbox_reply', payload);
  btn.textContent = 'ارسال پاسخ';
  if(d.assign){ IB_ASSIGN_ST = d.assign; renderAssignBar(); }
  if(d.ok){
    ta.value=''; PENDING_MEDIA=null; updateAttachLabel();
    toast(`ارسال شد (${IB_CHN[d.channel]||d.channel})`);
    await ibAppendNew();
    updateReplyState();
    ibLoadTeam();
  } else {
    btn.disabled = false;
    toast(d.error||'ارسال نشد', false);
  }
}

// ===== پیوست عکس =====
async function onAttachPick(input){
  const file = input.files && input.files[0];
  if(!file) return;
  if(!file.type.startsWith('image/')){ toast('فقط عکس مجاز است', false); input.value=''; return; }
  toast('در حال آپلود…');
  const fd = new FormData();
  fd.append('image', file);
  const d = await api('upload_image', fd, true);
  input.value='';
  if(d.ok && (d.rel||d.path)){ PENDING_MEDIA = d.rel || d.path; updateAttachLabel(); toast('عکس پیوست شد'); }
  else toast(d.error||'آپلود نشد', false);
}
function updateAttachLabel(){
  const lbl = document.getElementById('attachLabel');
  if(!lbl) return;
  lbl.textContent = PENDING_MEDIA ? '🖼 عکس پیوست شد، برای حذف کلیک کنید' : '';
  lbl.style.cursor = PENDING_MEDIA ? 'pointer' : 'default';
}
function clearAttach(){ if(PENDING_MEDIA){ PENDING_MEDIA=null; updateAttachLabel(); } }

// ===== ارتفاع صندوق ورودی به اندازه‌ی صفحه (کادر پاسخ همیشه پیدا باشد) =====
function ibFit(){
  const w = document.getElementById('inboxWrap');
  if(!w) return;
  const top = w.getBoundingClientRect().top + window.scrollY;
  const h = Math.max(ibIsMobile() ? 420 : 480, window.innerHeight - top - (ibIsMobile() ? 8 : 16));
  w.style.height = h + 'px';
}
window.addEventListener('resize', ibFit);

// ===== شروع =====
ibApplyView();
setTimeout(ibFit, 0);
ibLoadTeam();
loadConvos(1);
// فهرست هر ۳۰ ثانیه بی‌صدا تازه شود (حضور همکاران، چت‌های تازه، مسئول‌ها)
IB_LIST_T = setInterval(() => { if(!document.hidden) loadConvos(IB_PAGE, true); }, 30000);
(function(){
  var pm = new URLSearchParams(location.search).get('person');
  if(pm && /^\d+$/.test(pm)) openConvo(parseInt(pm,10));
})();
