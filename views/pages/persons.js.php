// pages/persons.js.php
const CHN = {bale:'بله', telegram:'تلگرام', roubika:'روبیکا', bale_safir:'صفیر', sms:'پیامک', email:'ایمیل', webchat:'گفتگوی سایت', instagram:'اینستاگرام', voice:'تماس گویا'};
// برچسب پیام‌های خودکار (یکسان با صندوق ورودی)
const PF_SRC = {autoreply:'⚡ پاسخ خودکار', botmenu:'🧭 منوی بات', form:'📝 فرم', ai:'🤖 هوش مصنوعی', comment_dm:'🎯 دایرکت اتوماسیون کامنت', comment_reply:'💬 پاسخ عمومی زیر کامنت', follow_gate:'➕ فالو اجباری', phone_gate:'📞 درخواست شماره', ai_comment:'🤖 AI کامنت', campaign:'📣 کمپین'};
const CHC = {bale:'ch-bale', telegram:'ch-telegram', roubika:'ch-roubika', bale_safir:'ch-safir', sms:'ch-sms', email:'ch-email', voice:'ch-voice', instagram:'ch-instagram', webchat:'ch-landing'};
function chChip(c){ return `<span class="chip" style="margin-left:4px"><span class="cdot ${CHC[c]||''}"></span>${CHN[c]||c}</span>`; }
const FILTER_CHANNELS = [['instagram','اینستاگرام'],['bale','بله'],['telegram','تلگرام'],['roubika','روبیکا'],['bale_safir','صفیر'],['sms','پیامک'],['email','ایمیل'],['webchat','گفتگوی سایت']];
let SEL = new Set();      // شناسه‌های انتخاب‌شده
let PAGE = 1, PAGES = 1, PER = 25;
let CAN_DELETE = false;

function selChannels(){
  return [...document.querySelectorAll('#fChannels input:checked')].map(c=>c.value);
}
function gatherFilters(){
  const f = {
    q: document.getElementById('q').value.trim(),
    status: document.getElementById('fStatus').value,
    group_id: document.getElementById('fGroup')?.value || '',
    tag_id: document.getElementById('fTag')?.value || '',
    channels: selChannels(),
    has_phone: document.getElementById('fHasPhone')?.value || '',
    responded: document.getElementById('fResponded')?.value || '',
    min_resp_rate: document.getElementById('fRespRate')?.value || '',
    delivery: document.getElementById('fDelivery')?.value || '',
    date_field: document.getElementById('fDateField')?.value || 'created',
    date_from: getJDate('fDateFrom'),
    date_to: getJDate('fDateTo'),
    sort: document.getElementById('fSort')?.value || 'recent',
  };
  // فعالیت: active:N یا inactive:N
  const act = document.getElementById('fActivity')?.value || '';
  if(act.startsWith('active:'))   f.active_days = act.split(':')[1];
  if(act.startsWith('inactive:')) f.inactive_days = act.split(':')[1];
  return f;
}
function countActiveAdv(f){
  let n=0;
  ['group_id','tag_id','has_phone','responded','min_resp_rate','delivery','date_from','date_to','active_days','inactive_days'].forEach(k=>{ if(f[k]) n++; });
  if(f.channels && f.channels.length) n++;
  if(f.sort && f.sort!=='recent') n++;
  return n;
}

async function loadPersons(page=1){
  PAGE = page;
  const f = gatherFilters();
  f.page = page;
  // نشانگر تعداد فیلتر فعال
  const badge=document.getElementById('advCount');
  const n=countActiveAdv(f);
  if(badge){ if(n>0){ badge.style.display='inline-block'; badge.textContent=n; } else badge.style.display='none'; }

  const d = await api('persons_list', f);
  const tb = document.getElementById('rows');
  if(!d.ok){ tb.innerHTML='<tr><td colspan=8 class=empty>خطا</td></tr>'; return; }
  if(!d.persons.length){ tb.innerHTML=`<tr><td colspan=8>${emptyState('👥','مشتری‌ای با این فیلترها نیست','فیلترها را تغییر دهید یا پاک کنید','<button class=\"btn btn-ghost btn-sm\" onclick=\"resetAdv()\">پاک‌کردن فیلترها</button>')}</td></tr>`; renderPager(d); return; }
  PAGES = d.pages;
  const startNum = (d.page-1)*PER;
  tb.innerHTML = d.persons.map((p,i)=>{
    const chCount = {}; (p.identities||[]).forEach(x=>{ chCount[x.channel] = (chCount[x.channel]||0) + 1; });
    const ids = Object.keys(chCount).map(c=>chChip(c).replace('</span>'+(CHN[c]||c)+'</span>', '</span>'+(CHN[c]||c)+(chCount[c]>1?` ×${faNum(chCount[c])}`:'')+'</span>')).join('') || '<span class="muted">—</span>';
    const status = p.status==='member'
      ? '<span class="tag tag-ok">عضو</span>' : '<span class="tag tag-warn">ناشناس</span>';
    const checked = SEL.has(String(p.id)) ? 'checked' : '';
    const gt = [
      ...(p.groups||[]).map(g=>`<span class="tag" style="background:${esc(g.color)}22;color:${esc(g.color)};margin-left:3px">${esc(g.name)}</span>`),
      ...(p.tags||[]).map(t=>`<span class="tag tag-mute" style="margin-left:3px">${esc(t.name)}</span>`)
    ].join('') || '<span class="muted">—</span>';
    // فعالیت: ارسالی/دریافتی و نرخ پاسخ
    const sent=+(p.sent_count||0), recv=+(p.recv_count||0);
    let actHtml = `<span class="muted" style="font-size:11px">↑${sent} ↓${recv}</span>`;
    if(p.resp_rate!=null) actHtml += ` <span class="tag ${p.resp_rate>=50?'tag-ok':'tag-mute'}" style="font-size:10px">${p.resp_rate}٪</span>`;
    return `<tr>
      <td><input type="checkbox" data-id="${p.id}" ${checked} onchange="toggleOne('${p.id}',this)" style="width:auto"></td>
      <td class="muted">${startNum+i+1}</td>
      <td style="cursor:pointer" onclick="openProfile(${p.id})">${esc(p.full_name)||'<span class=muted>بدون نام</span>'}</td>
      <td class="mono" style="cursor:pointer" onclick="openProfile(${p.id})">${esc(p.phone)||'—'}</td>
      <td>${status}</td>
      <td>${ids}</td>
      <td>${actHtml}</td>
      <td>${gt}</td>
    </tr>`;
  }).join('');
  renderPager(d);
  syncSelAll();
}

function resetAdv(){
  ['fGroup','fTag','fHasPhone','fResponded','fRespRate','fDelivery','fActivity','fSort','fDateField'].forEach(id=>{ const e=document.getElementById(id); if(e) e.selectedIndex=0; });
  ['fDateFrom','fDateTo'].forEach(id=>{ if(typeof setJDate==='function') setJDate(id,''); });
  ['q'].forEach(id=>{ const e=document.getElementById(id); if(e) e.value=''; });
  document.getElementById('fStatus').selectedIndex=0;
  document.querySelectorAll('#fChannels input').forEach(c=>c.checked=false);
  loadPersons(1);
}
function buildChannelChips(){
  const wrap=document.getElementById('fChannels'); if(!wrap) return;
  wrap.innerHTML=FILTER_CHANNELS.map(([v,l])=>
    `<label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;background:var(--bg);padding:4px 8px;border-radius:6px;cursor:pointer">
      <input type="checkbox" value="${v}" onchange="loadPersons(1)" style="width:auto">${l}</label>`).join('');
}

// خروجی CSV مشتریان با همان فیلترهای فعلی (دانلود مستقیم)
function exportCustomers(){
  const params = new URLSearchParams();
  params.set('export','customers');
  const q = document.getElementById('q').value.trim(); if(q) params.set('q',q);
  const status = document.getElementById('fStatus').value; if(status) params.set('status',status);
  const hp = document.getElementById('fHasPhone')?.value; if(hp) params.set('has_phone',hp);
  const g = document.getElementById('fGroup')?.value; if(g) params.set('group_id',g);
  const t = document.getElementById('fTag')?.value; if(t) params.set('tag_id',t);
  // دانلود از طریق ناوبری مستقیم (GET) تا مرورگر فایل را ذخیره کند
  window.location = 'index.php?' + params.toString();
}

function renderPager(d){
  const pg = document.getElementById('pager');
  if(d.pages<=1){ pg.innerHTML = d.total?`<span class="muted">${d.total} مشتری</span>`:''; return; }
  let h = `<button class="btn btn-ghost btn-sm" ${d.page<=1?'disabled style=opacity:.4':''} onclick="loadPersons(${d.page-1})">قبلی</button>`;
  // صفحه‌بندی هوشمند: اول، آخر، و اطراف صفحه‌ی فعلی
  const pages = new Set([1, d.pages, d.page, d.page-1, d.page+1]);
  const sorted = [...pages].filter(x=>x>=1&&x<=d.pages).sort((a,b)=>a-b);
  let last=0;
  for(const i of sorted){
    if(i-last>1) h+=`<span class="muted">…</span>`;
    h+=`<button class="btn ${i===d.page?'btn-primary':'btn-ghost'} btn-sm" onclick="loadPersons(${i})">${i}</button>`;
    last=i;
  }
  h += `<button class="btn btn-ghost btn-sm" ${d.page>=d.pages?'disabled style=opacity:.4':''} onclick="loadPersons(${d.page+1})">بعدی</button>`;
  h += `<span class="muted" style="margin-right:8px">${d.total} مشتری · صفحه ${d.page}/${d.pages}</span>`;
  pg.innerHTML = h;
}

// ===== انتخاب =====
function toggleOne(id, el){
  if(el.checked) SEL.add(id); else SEL.delete(id);
  updateBulkBar(); syncSelAll();
}
function toggleAll(el){
  document.querySelectorAll('#rows input[type=checkbox]').forEach(cb=>{
    const id = cb.getAttribute('data-id');
    if(!id) return;
    cb.checked = el.checked;
    if(el.checked) SEL.add(id); else SEL.delete(id);
  });
  updateBulkBar();
}
function syncSelAll(){
  const boxes=[...document.querySelectorAll('#rows input[type=checkbox]')];
  const all = boxes.length>0 && boxes.every(cb=>cb.checked);
  const sa=document.getElementById('selAll'); if(sa) sa.checked=all;
}
function clearSel(){ SEL.clear(); updateBulkBar(); loadPersons(PAGE); }
function updateBulkBar(){
  const bar=document.getElementById('bulkBar');
  if(SEL.size>0){ bar.style.display='block'; document.getElementById('bulkCount').textContent=`${SEL.size} مشتری انتخاب شده`; }
  else bar.style.display='none';
}

// ===== عملیات گروهی =====
async function loadBulkGroups(){
  const d=await api('groups_list');
  document.getElementById('bulkGroup').innerHTML='<option value="">افزودن به گروه…</option>'+(d.groups||[]).map(g=>`<option value="${g.id}">${esc(g.name)}</option>`).join('');
  const fg=document.getElementById('fGroup');
  if(fg) fg.innerHTML='<option value="">همه</option>'+(d.groups||[]).map(g=>`<option value="${g.id}">${esc(g.name)}</option>`).join('');
}
async function loadFilterTags(){
  const ft=document.getElementById('fTag'); if(!ft) return;
  const d=await api('tags_list');
  ft.innerHTML='<option value="">همه</option>'+(d.tags||[]).map(t=>`<option value="${t.id}">${esc(t.name)}</option>`).join('');
}
function toggleAdv(){
  const a=document.getElementById('advFilters');
  a.style.display = a.style.display==='none' ? 'block' : 'none';
}

// ===== ورود لیست مشتری =====
async function openImport(){
  // پر کردن گروه‌ها
  const g=await api('groups_list');
  document.getElementById('impGroup').innerHTML='<option value="">— بدون گروه —</option>'+(g.groups||[]).map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join('');
  document.getElementById('impResult').textContent='';
  document.getElementById('impBackdrop').classList.add('show');
  document.getElementById('impDrawer').classList.add('open');
}
function closeImport(){
  document.getElementById('impBackdrop').classList.remove('show');
  document.getElementById('impDrawer').classList.remove('open');
}
async function doImport(){
  const f=document.getElementById('impFile').files[0];
  if(!f){toast('فایل را انتخاب کنید',false);return;}
  const r=document.getElementById('impResult'); r.textContent='در حال پردازش…';
  const fd=new FormData();
  fd.append('file',f);
  fd.append('group_id',document.getElementById('impGroup').value||'');
  fd.append('tag_name',document.getElementById('impTag').value.trim());
  const d=await api('import_customers', fd, true);
  if(d.ok){
    r.innerHTML=`<span class="tag tag-ok">انجام شد</span> ${d.created} مشتری جدید · ${d.added} افزوده/به‌روز`+(d.invalid?` · ${d.invalid} نامعتبر`:'');
    toast('ورود انجام شد'); loadPersons(1);
  } else { r.innerHTML=`<span class="tag tag-warn">خطا</span> ${esc(d.error||'')}`; }
}
async function applyGroup(){
  const gid=document.getElementById('bulkGroup').value;
  if(!gid){ toast('گروه را انتخاب کنید', false); return; }
  if(SEL.size===0) return;
  const d=await api('group_add_persons',{group_id:gid, person_ids:[...SEL]});
  if(d.ok){ toast(`${SEL.size} مشتری به گروه اضافه شد`); clearSel(); }
  else toast(d.error||'خطا', false);
}
async function applyTag(){
  const name=document.getElementById('bulkTag').value.trim();
  if(!name){ toast('برچسب را وارد کنید', false); return; }
  if(SEL.size===0) return;
  // اول تگ را بساز/پیدا کن، بعد به اشخاص بزن
  const t=await api('tag_create',{name});
  if(!t.ok){ toast('خطا در ساخت برچسب', false); return; }
  const d=await api('tag_persons',{tag_id:t.id, person_ids:[...SEL]});
  if(d.ok){ toast(`برچسب به ${SEL.size} مشتری اضافه شد`); document.getElementById('bulkTag').value=''; clearSel(); }
  else toast(d.error||'خطا', false);
}

// ===== افزودن دستی مشتری =====
// کانال‌ها و نوع شناسه‌ای که می‌گیرند
const ADD_CHANNELS = [
  {v:'bale',        label:'بله (ربات)',     hint:'شناسه‌ی چت (chat_id) عددی', kind:'id'},
  {v:'telegram',    label:'تلگرام (ربات)',   hint:'شناسه‌ی چت (chat_id) عددی', kind:'id'},
  {v:'roubika',     label:'روبیکا (ربات)',   hint:'شناسه‌ی چت (chat_id)',       kind:'id'},
  {v:'bale_safir',  label:'بله صفیر (ربات)', hint:'شناسه‌ی چت (chat_id)',       kind:'id'},
  {v:'safir_phone', label:'صفیر (با شماره)', hint:'شماره موبایل',               kind:'phone'},
  {v:'sms',         label:'پیامک',           hint:'شماره موبایل',               kind:'phone'},
  {v:'email',       label:'ایمیل',           hint:'آدرس ایمیل',                 kind:'email'},
];
let ADD_ROW_SEQ = 0;

function channelOptions(sel){
  return ADD_CHANNELS.map(c=>`<option value="${c.v}" ${c.v===sel?'selected':''}>${c.label}</option>`).join('');
}
function addChannelRow(channel='bale', value=''){
  const id = 'achr_'+(ADD_ROW_SEQ++);
  const wrap = document.createElement('div');
  wrap.className='add-ch-row';
  wrap.id=id;
  wrap.style.cssText='display:flex;gap:6px;align-items:flex-start;margin-bottom:8px';
  wrap.innerHTML=`
    <select style="flex:0 0 130px" onchange="updateChHint('${id}')">${channelOptions(channel)}</select>
    <div style="flex:1">
      <input placeholder="شناسه" value="${esc(value)}" style="width:100%">
      <div class="ch-hint" style="font-size:10.5px;color:var(--ink-faint);margin-top:2px"></div>
    </div>
    <button class="btn btn-ghost btn-sm" onclick="this.closest('.add-ch-row').remove()" style="flex:0 0 auto" title="حذف">✕</button>`;
  document.getElementById('addChannels').appendChild(wrap);
  updateChHint(id);
}
function updateChHint(rowId){
  const row=document.getElementById(rowId); if(!row) return;
  const ch=row.querySelector('select').value;
  const meta=ADD_CHANNELS.find(c=>c.v===ch);
  const hintEl=row.querySelector('.ch-hint');
  const inp=row.querySelector('input');
  if(meta){
    hintEl.textContent=meta.hint;
    inp.placeholder=meta.hint;
    inp.className = meta.kind==='email' ? '' : 'mono';
  }
}
function openAdd(){
  document.getElementById('addName').value='';
  document.getElementById('addPhone').value='';
  document.getElementById('addNotes').value='';
  document.getElementById('addResult').textContent='';
  document.getElementById('addChannels').innerHTML='';
  ADD_ROW_SEQ=0;
  addChannelRow();  // یک ردیف پیش‌فرض
  document.getElementById('addBackdrop').classList.add('show');
  document.getElementById('addDrawer').classList.add('open');
}
function closeAdd(){
  document.getElementById('addBackdrop').classList.remove('show');
  document.getElementById('addDrawer').classList.remove('open');
}
async function doAddPerson(){
  const full_name=document.getElementById('addName').value.trim();
  const phone=document.getElementById('addPhone').value.trim();
  const notes=document.getElementById('addNotes').value.trim();
  const channels=[];
  document.querySelectorAll('#addChannels .add-ch-row').forEach(row=>{
    const channel=row.querySelector('select').value;
    const value=row.querySelector('input').value.trim();
    if(value) channels.push({channel, value});
  });
  if(!phone && channels.length===0){
    toast('حداقل یک هویت کانال یا شماره موبایل لازم است', false); return;
  }
  const r=document.getElementById('addResult'); r.textContent='در حال ثبت…';
  const d=await api('person_create',{full_name, phone, notes, channels});
  if(d.ok){
    let msg = d.created ? 'مشتری جدید ثبت شد' : 'هویت‌ها به مشتری موجود افزوده شد';
    if(d.merged) msg += ' · ادغام هویت طلایی انجام شد';
    r.innerHTML=`<span class="tag tag-ok">انجام شد</span> ${msg}`;
    toast('ثبت شد');
    loadPersons(1);
    setTimeout(()=>{ closeAdd(); openProfile(d.person_id); }, 700);
  } else {
    r.innerHTML=`<span class="tag tag-warn">خطا</span> ${esc(d.error||'')}`;
  }
}

// ===== پروفایل =====
let CUR_PID = null;
async function openProfile(id){
  CUR_PID = id;
  document.getElementById('profileBackdrop').classList.add('show');
  document.getElementById('profileDrawer').classList.add('open');
  document.getElementById('profileBody').innerHTML = '<div class="empty">در حال بارگذاری…</div>';
  const d = await api('person_profile', {id});
  if(!d.ok){ document.getElementById('profileBody').innerHTML='<div class="empty">'+esc(d.error||'خطا')+'</div>'; return; }
  CAN_DELETE = !!d.can_delete;
  renderProfile(d.person);
}
function closeProfile(){
  document.getElementById('profileBackdrop').classList.remove('show');
  document.getElementById('profileDrawer').classList.remove('open');
}
function renderProfile(p){
  CUR_PROFILE = p; // برای ساخت تایم‌لاین
  const statusTag = p.status==='member' ? '<span class="tag tag-ok">عضو</span>' : '<span class="tag tag-warn">ناشناس</span>';
  const idsHtml = (p.identities||[]).map(i=>{
    const uname = i.username ? '@'+esc(i.username) : '';
    const fullName = [esc(i.first_name||''), esc(i.last_name||'')].filter(Boolean).join(' ');
    const bio = i.bio ? `<div style="font-size:11.5px;color:var(--ink-faint);margin-top:3px">بیو: ${esc(i.bio)}</div>` : '';
    const lastMsg = i.last_message
      ? `<div style="font-size:11.5px;color:var(--ink-faint);margin-top:3px">${i.last_message_dir==='in'?'دریافت':'ارسال'}: «${esc((i.last_message||'').slice(0,40))}» · ${toFa(i.last_message_at)}</div>`
      : ((i.last_message_at||i.last_msg_at)?`<div style="font-size:11.5px;color:var(--ink-faint);margin-top:3px">آخرین پیام: ${toFa(i.last_message_at||i.last_msg_at)} · ⬇ ${faNum(i.msg_in||0)} ⬆ ${faNum(i.msg_out||0)}</div>`:'');
    return `<div style="padding:8px 0;border-bottom:1px solid var(--line-soft)">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <span><span class="tag tag-accent">${CHN[i.channel]||i.channel}</span> ${i.ig_account_label?`<span class="tag" style="background:${esc(i.ig_account_color||'#6366f1')}22;color:${esc(i.ig_account_color||'#6366f1')}">${esc(i.ig_account_label)}</span>`:''} ${fullName?`<b style="font-size:12.5px">${fullName}</b>`:''} ${uname?(i.profile_url?`<a href="${esc(i.profile_url)}" target="_blank" rel="noopener" style="font-size:11.5px">${uname} ↗</a>`:`<span class="muted" style="font-size:11.5px">${uname}</span>`):''}</span>
        <span class="mono muted" style="font-size:11px">${esc(String(i.channel_uid||'').replace(/^rc\d+_/,''))}</span>
      </div>${bio}${lastMsg}</div>`;
  }).join('') || '<span class="muted">هویتی ثبت نشده</span>';
  const groupsHtml = (p.groups||[]).map(g=>`<span class="tag" style="background:${esc(g.color)}22;color:${esc(g.color)};margin-left:4px">${esc(g.name)} <span onclick="removeGroup(${g.id})" style="cursor:pointer;opacity:.6;margin-right:2px">×</span></span>`).join('') || '<span class="muted">—</span>';
  const tagsHtml = (p.tags||[]).map(t=>`<span class="tag tag-mute" style="margin-left:4px">${esc(t.name)} <span onclick="removeTag(${t.id})" style="cursor:pointer;opacity:.6;margin-right:2px">×</span></span>`).join('') || '<span class="muted">—</span>';
  const msgsHtml = (p.messages||[]).map(m=>{
    const cls = m.direction==='out' ? 'out' : 'in';
    const auto = m.direction==='out' && m.source && PF_SRC[m.source];
    const isCm = m.direction==='in' && m.media_type==='ig_comment';
    const lbl = isCm ? '💬 کامنت زیر پست' : (auto ? PF_SRC[m.source] : '');
    const body = String(m.body||'').replace(/^💬 کامنت زیر پست: /,'').replace(/^💬 پاسخ عمومی زیر کامنت: /,'');
    return `<div class="bubble ${cls}" ${auto?'style="border:1.5px dashed var(--line)"':''}>${lbl?`<div style="font-size:10.5px;font-weight:700;opacity:.8;margin-bottom:2px">${lbl}</div>`:''}${esc(body)||'<span class=muted>(بدون متن)</span>'}<div class="meta">${CHN[m.ch]||m.ch}${m.account_label?' · '+esc(m.account_label):''} · ${toFa(m.created_at)}</div></div>`;
  }).join('') || '<div class="muted" style="text-align:center">پیامی نیست</div>';
  const cmHtml = (p.ig_comments||[]).map(c=>`<div style="padding:6px 0;border-bottom:1px solid var(--line-soft);font-size:12px">
      <div>${c.account_label?`<span class="tag" style="background:${esc(c.account_color||'#e1306c')}22;color:${esc(c.account_color||'#e1306c')}">${esc(c.account_label)}</span>`:''} ${c.post_title?`<b>${esc(c.post_title)}</b>`:`پست <span class="mono" dir="ltr" style="font-size:10.5px">${esc(c.media_id||'')}</span>`}</div>
      <div style="margin-top:2px">💬 ${esc(c.body||'')}</div>
      <div class="muted" style="font-size:10.5px">${toFa(c.created_at)} · ${c.replied_at?'✅ پاسخ خودکار داده شد':'بدون پاسخ خودکار'}</div></div>`).join('');
  const as = p.assign || {};
  const asHtml = as.ready ? `<div class="pf-section"><div class="pf-label">پاسخ‌گویی</div><div style="font-size:12.5px">
      ${as.ai_assigned ? '🤖 سپرده‌شده به هوش مصنوعی' : (as.assigned_name ? `👤 بر عهده‌ی <b>${esc(as.assigned_name)}</b>` : '<span class="muted">بی‌مسئول</span>')}
      ${as.first_responder_name ? ` · شروع‌کننده: ${esc(as.first_responder_name)}` : ''}</div></div>` : '';
  const topCh = p.top_channel ? (CHN[p.top_channel]||p.top_channel) : '—';
  document.getElementById('profileBody').innerHTML = `
    <div class="pf-tabs">
      <button class="pf-tab active" id="pfTabOverview" onclick="pfSwitchTab('overview')">نمای کلی</button>
      <button class="pf-tab" id="pfTabTimeline" onclick="pfSwitchTab('timeline')">تایم‌لاین</button>
      <a class="pf-tab" style="margin-right:auto;text-decoration:none" href="index.php?export=messages&person_id=${p.id}" title="خروجی گفتگو">📤 CSV</a>
    </div>
    <div id="pfOverview">
    <div class="pf-section">
      <div class="field"><label>نام</label><input id="pfName" value="${esc(p.full_name)||''}" placeholder="نام مشتری"></div>
      <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px">${statusTag}<span class="mono muted">${esc(p.phone)||'بدون شماره'}</span></div>
    </div>
    <div class="pf-stat">
      <div><div class="n">${p.stats.sent}</div><div class="pf-label">ارسالی</div></div>
      <div><div class="n">${p.stats.received}</div><div class="pf-label">دریافتی</div></div>
      <div><div class="n">${esc(topCh)}</div><div class="pf-label">فعال‌ترین</div></div>
    </div>
    <div class="pf-section"><div class="pf-label">هویت‌ها (شبکه‌ها و پیج‌ها)</div>${idsHtml}
      <div style="margin-top:8px"><button class="btn btn-ghost btn-sm" onclick="openMerge()">🔗 ادغام با مخاطب دیگر</button>
      <a class="btn btn-ghost btn-sm" href="index.php?page=inbox&person=${p.id}">💬 گفتگو در صندوق ورودی</a></div>
      <div id="pfMerge" style="display:none;margin-top:8px;background:var(--bg);border-radius:10px;padding:10px">
        <div class="muted" style="font-size:11.5px;margin-bottom:6px">مخاطب دوم را پیدا کنید (نام، شماره یا @یوزرنیم). همه‌ی پیام‌ها، سوشال‌ها، گروه‌ها و سوابق دو پروفایل یکی می‌شود؛ پروفایلِ دارای شماره نگه داشته می‌شود.</div>
        <input id="pfMergeQ" placeholder="جستجو…" oninput="searchMerge(this.value)">
        <div id="pfMergeRes" style="margin-top:6px"></div>
      </div>
    </div>
    ${asHtml}
    ${cmHtml?`<div class="pf-section"><div class="pf-label">کامنت‌ها روی پست‌های اینستاگرام (${faNum((p.ig_comments||[]).length)})</div>${cmHtml}</div>`:''}
    <div class="pf-section"><div class="pf-label">گروه‌ها</div>${groupsHtml}</div>
    <div class="pf-section"><div class="pf-label">برچسب‌ها</div>${tagsHtml}</div>
    <div class="pf-section">
      <div class="field"><label>یادداشت</label><textarea id="pfNotes" placeholder="یادداشت داخلی…">${esc(p.notes)||''}</textarea></div>
      <button class="btn btn-primary btn-sm" onclick="saveProfile()">ذخیره تغییرات</button>
      ${CAN_DELETE?`<button class="btn btn-danger-ghost btn-sm" onclick="deletePerson()" style="margin-right:6px">حذف مخاطب</button>`:''}
    </div>
    <div class="pf-section"><div class="pf-label">تاریخچه‌ی گفتگو</div><div class="chat-log">${msgsHtml}</div></div>
    ${renderBehavior(p)}
    ${renderJakojastStatus(p)}
    </div>
    <div id="pfTimeline" style="display:none">${renderTimeline(p)}</div>`;
}

let CUR_PROFILE = null;
function pfSwitchTab(tab){
  const ov=document.getElementById('pfOverview'), tl=document.getElementById('pfTimeline');
  document.getElementById('pfTabOverview').classList.toggle('active', tab==='overview');
  document.getElementById('pfTabTimeline').classList.toggle('active', tab==='timeline');
  ov.style.display = tab==='overview'?'block':'none';
  tl.style.display = tab==='timeline'?'block':'none';
}

// تایم‌لاین یکپارچه: همه‌ی رویدادها (پیام، فرم، کلیک، کمپین) به ترتیب زمانی
function renderTimeline(p){
  const ev = [];
  (p.messages||[]).forEach(m=>{
    const auto = m.direction==='out' && m.source && PF_SRC[m.source];
    const isCm = m.direction==='in' && m.media_type==='ig_comment';
    ev.push({
      t:m.created_at, icon:isCm?'💬':(auto?'🤖':(m.direction==='out'?'📤':'📥')),
      title:isCm?'کامنت زیر پست':(auto?PF_SRC[m.source]:(m.direction==='out'?'پیام ارسالی':'پیام دریافتی')),
      detail:String(m.body||'').replace(/^💬 (کامنت زیر پست|پاسخ عمومی زیر کامنت): /,'').slice(0,80), tag:(CHN[m.ch]||m.ch)+(m.account_label?' · '+m.account_label:'')
    });
  });
  const loggedCm = new Set((p.messages||[]).filter(m=>m.media_type==='ig_comment').map(m=>String(m.body||'').replace(/^💬 کامنت زیر پست: /,'').trim()));
  (p.ig_comments||[]).forEach(c=>{ if(loggedCm.has(String(c.body||'').trim())) return; ev.push({
    t:c.created_at, icon:'💬', title:'کامنت زیر پست'+(c.post_title?` «${c.post_title}»`:''), detail:c.body||'', tag:'اینستاگرام'+(c.account_label?' · '+c.account_label:'')
  }); });
  (p.form_responses||[]).forEach(r=>{
    let n=0; try{n=JSON.parse(r.answers_json||'[]').length;}catch(e){}
    ev.push({t:r.created_at, icon:'📋', title:'پاسخ به فرم', detail:esc(r.form_name||'فرم')+(n?` (${n} پاسخ)`:''), tag:'فرم'});
  });
  (p.link_clicks||[]).forEach(c=>ev.push({
    t:c.clicked_at, icon:'🔗', title:'کلیک روی لینک', detail:esc(c.title||c.target_url||''), tag:'لینک'
  }));
  (p.campaigns||[]).forEach(c=>ev.push({
    t:c.run_at, icon:'📣', title:'کمپین'+(c.step_order?` (مرحله ${c.step_order})`:''),
    detail:esc(c.campaign_name||''), tag:c.responded==1?'پاسخ داد':'ارسال'
  }));
  // مرتب‌سازی نزولی زمانی (جدیدترین بالا). تاریخ‌های خالی ته صف.
  ev.sort((a,b)=>String(b.t||'').localeCompare(String(a.t||'')));
  if(!ev.length) return '<div class="pf-section"><div class="muted" style="text-align:center">رویدادی ثبت نشده</div></div>';
  return `<div class="pf-section"><div class="tl">${ev.map(e=>`
    <div class="tl-item">
      <div class="tl-icon">${e.icon}</div>
      <div class="tl-body">
        <div class="tl-top"><b>${esc(e.title)}</b> <span class="tag tag-mute" style="font-size:9.5px">${esc(e.tag)}</span></div>
        ${e.detail?`<div class="tl-detail">${esc(e.detail)}</div>`:''}
        <div class="tl-time">${e.t?toFa(e.t):''}</div>
      </div>
    </div>`).join('')}</div></div>`;
}

function renderBehavior(p){
  let html='';
  // پاسخ‌های فرم
  if((p.form_responses||[]).length){
    html+=`<div class="pf-section"><div class="pf-label">پاسخ به فرم‌ها</div>`;
    p.form_responses.forEach(r=>{
      let ans=[];try{ans=JSON.parse(r.answers_json||'[]');}catch(e){}
      html+=`<div style="background:var(--bg);border-radius:8px;padding:10px;margin-bottom:8px">
        <div style="font-size:11.5px;color:var(--ink-faint);margin-bottom:4px">${esc(r.form_name||'فرم')} · ${toFa(r.created_at)}</div>
        ${ans.map(a=>`<div style="font-size:12px"><b>${esc(a.q)}:</b> ${esc(a.a)}</div>`).join('')}</div>`;
    });
    html+=`</div>`;
  }
  // کلیک لینک‌ها
  if((p.link_clicks||[]).length){
    html+=`<div class="pf-section"><div class="pf-label">کلیک روی لینک‌ها</div>`;
    p.link_clicks.forEach(c=>{
      html+=`<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--line-soft);font-size:12px">
        <span>🔗 ${esc(c.title||c.target_url)}</span><span class="muted" style="font-size:11px">${toFa(c.clicked_at)}</span></div>`;
    });
    html+=`</div>`;
  }
  // کمپین‌ها
  if((p.campaigns||[]).length){
    html+=`<div class="pf-section"><div class="pf-label">کمپین‌ها</div>`;
    p.campaigns.forEach(c=>{
      html+=`<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--line-soft);font-size:12px">
        <span>${esc(c.campaign_name)} (مرحله ${c.step_order||'?'})</span>
        ${c.responded==1?'<span class="tag tag-ok">پاسخ داد</span>':'<span class="muted" style="font-size:11px">'+toFa(c.run_at)+'</span>'}</div>`;
    });
    html+=`</div>`;
  }
  return html;
}
// وضعیت در جاکجاست (سرنخ/مشتری/تماس) - جهت برعکسِ چیزی که تب چت در
// پروفایل جاکجاست نشان می‌دهد
function renderJakojastStatus(p){
  const j = p.jakojast;
  if(!j) return '';
  if(!j.configured){
    return `<div class="pf-section"><div class="pf-label">وضعیت در جاکجاست</div><span class="muted" style="font-size:12px">اتصال به جاکجاست تنظیم نشده است (تنظیمات ← جاکجاست)</span></div>`;
  }
  if(j.error){
    return `<div class="pf-section"><div class="pf-label">وضعیت در جاکجاست</div><span class="muted" style="font-size:12px">${esc(j.error)}</span></div>`;
  }
  if(!j.found){
    return `<div class="pf-section"><div class="pf-label">وضعیت در جاکجاست</div><span class="muted" style="font-size:12px">سابقه‌ای در جاکجاست پیدا نشد</span></div>`;
  }
  const d = j.data || {};
  let html = `<div class="pf-section"><div class="pf-label">وضعیت در جاکجاست</div>`;
  if(d.is_customer && d.customer){
    html += `<div style="font-size:12px;margin-bottom:6px"><span class="tag tag-ok">مشتری</span> سطح: ${esc(d.customer.level||'-')} · شهر: ${esc(d.customer.city||'-')}</div>`;
  } else {
    html += `<div style="font-size:12px;margin-bottom:6px"><span class="tag tag-mute">هنوز مشتری رسمی نشده</span></div>`;
  }
  if((d.leads||[]).length){
    d.leads.forEach(l=>{
      html += `<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid var(--line-soft);font-size:12px">
        <span>🎯 ${esc(l.name)}</span><span class="muted" style="font-size:11px">${esc(l.stage||'-')} · ${esc(l.assignee||'-')}</span></div>`;
    });
  }
  if(d.call_stats){
    html += `<div style="font-size:11.5px;color:var(--ink-faint);margin-top:6px">📞 ${d.call_stats.total_calls||0} تماس`;
    if(d.call_stats.last_call_at) html += ` · آخرین: ${toFa(d.call_stats.last_call_at)}`;
    html += `</div>`;
  }
  if(d.open_tickets_count){
    html += `<div style="font-size:11.5px;color:var(--ink-faint);margin-top:2px">🎫 ${d.open_tickets_count} تیکت باز</div>`;
  }
  html += `</div>`;
  return html;
}
async function saveProfile(){
  const d = await api('person_update', {id:CUR_PID, full_name:document.getElementById('pfName').value.trim(), notes:document.getElementById('pfNotes').value.trim()});
  if(d.ok){ toast('ذخیره شد'); loadPersons(PAGE); }
  else toast(d.error||'خطا', false);
}
async function removeGroup(gid){
  const d=await api('person_group_remove',{group_id:gid, person_id:CUR_PID});
  if(d.ok){ toast('از گروه حذف شد'); openProfile(CUR_PID); loadPersons(PAGE); }
  else toast(d.error||'خطا',false);
}
async function removeTag(tid){
  const d=await api('person_tag_remove',{tag_id:tid, person_id:CUR_PID});
  if(d.ok){ toast('برچسب حذف شد'); openProfile(CUR_PID); loadPersons(PAGE); }
  else toast(d.error||'خطا',false);
}
// ===== ادغام دستی دو مخاطب (دور ۳، مورد ۴) =====
function openMerge(){ const b=document.getElementById('pfMerge'); b.style.display = b.style.display==='none'?'block':'none'; if(b.style.display==='block') document.getElementById('pfMergeQ').focus(); }
var MERGE_T = null;
function searchMerge(q){
  clearTimeout(MERGE_T);
  MERGE_T = setTimeout(async ()=>{
    const box = document.getElementById('pfMergeRes');
    if(q.trim().length < 2){ box.innerHTML=''; return; }
    const d = await api('person_search_min', {q});
    const rows = (d.rows||[]).filter(r=>+r.id !== +CUR_PID);
    box.innerHTML = rows.length ? rows.map(r=>`<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--line-soft);font-size:12px">
        <span><b>${esc(r.full_name||'بدون نام')}</b> ${r.phone?`<span class="mono muted">${esc(r.phone)}</span>`:''}<div class="muted" style="font-size:10.5px">${esc(r.idents||'')}</div></span>
        <button class="btn btn-ghost btn-xs" onclick="doMerge(${r.id})">ادغام</button></div>`).join('') : '<div class="muted" style="font-size:12px">چیزی پیدا نشد</div>';
  }, 300);
}
async function doMerge(otherId){
  if(!confirm('این دو پروفایل یکی شوند؟ این کار برگشت‌پذیر نیست.')) return;
  const d = await api('person_merge', {id: CUR_PID, other_id: otherId});
  if(!d.ok){ toast(d.error||'ادغام نشد', false); return; }
  toast('دو پروفایل ادغام شدند');
  loadPersons(PAGE); openProfile(d.person_id);
}
async function mergeIgDuplicates(){
  if(!confirm('همه‌ی پروفایل‌هایی که با یک @یوزرنیم به پیج‌های مختلف اینستاگرام پیام داده‌اند یکی شوند؟')) return;
  const d = await api('persons_merge_ig');
  if(!d.ok){ toast(d.error||'خطا', false); return; }
  toast(d.merged ? `${faNum(d.merged)} پروفایل تکراری در ${faNum(d.groups)} گروه ادغام شد` : 'پروفایل تکراری پیدا نشد 👌');
  loadPersons(1);
}

async function deletePerson(){
  if(!confirm('این مخاطب و همه‌ی اطلاعاتش حذف شود؟ این کار قابل بازگشت نیست.'))return;
  const d=await api('person_delete',{id:CUR_PID});
  if(d.ok){ toast('مخاطب حذف شد'); closeProfile(); loadPersons(PAGE); }
  else toast(d.error||'خطا',false);
}

buildChannelChips();
loadPersons(1);
loadBulkGroups();
loadFilterTags();
// اگر با ?person=ID آمده‌ایم (مثلاً از صندوق ورودی)، همان پروفایل را باز کن
(function(){
  var pm = new URLSearchParams(location.search).get('person');
  if(pm && /^\d+$/.test(pm)) openProfile(parseInt(pm,10));
})();
