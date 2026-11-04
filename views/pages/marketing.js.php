// pages/marketing.js.php
const PERIOD_FA={month:'ماهانه',week:'هفتگی',day:'روزانه',campaign:'کمپینی'};
const LEVEL_FA={strategic:'استراتژیک',specialist:'تخصصی',executive:'اجرایی'};
const LEVEL_CLS={strategic:'b-info',specialist:'b-ai',executive:'b-mut'};
let MKT_MEMBERS=[], MKT_ROLES=[];

async function mktBoot(){
  const r=await api('mkt_ready');
  if(!r.ok || !r.ready){ document.getElementById('mktNeedsDb').style.display='block'; return; }
  document.getElementById('mktApp').style.display='block';
  await Promise.all([loadGoals(), loadTeam()]);
  // وضعیت داده‌ی نمونه
  const ss=await api('mkt_seed_status');
  if(ss.ok && !ss.has_seed){ const bar=document.getElementById('mktSeedBar'); if(bar) bar.style.display='flex'; }
}
async function importSeed(){
  const d=await api('mkt_seed_import');
  if(d.ok){ toast((d.inserted||0)+' محتوای واقعی وارد شد'); const bar=document.getElementById('mktSeedBar'); if(bar) bar.style.display='none'; refreshContentViews(); }
  else toast(d.error||'خطا',false);
}
async function mktUpgradeDb(){
  const btn=event&&event.target; if(btn){ btn.disabled=true; btn.textContent='در حال به‌روزرسانی…'; }
  const d=await api('db_upgrade');
  if(btn){ btn.disabled=false; btn.textContent='به‌روزرسانی دیتابیس'; }
  if(d.ok){
    // دوباره آماده‌بودن را بررسی کن؛ اگر جدول‌ها ساخته شده باشند بنر محو می‌شود
    const r=await api('mkt_ready');
    if(r.ok && r.ready){ toast('دیتابیس به‌روزرسانی شد'); document.getElementById('mktNeedsDb').style.display='none'; mktBoot(); }
    else { toast('جداول ساخته شدند ولی هنوز در دسترس نیستند؛ صفحه را تازه کنید',false); }
  } else {
    toast(d.error||'خطا در به‌روزرسانی',false);
  }
}
function mktTab(t){
  ['goals','mine','cal','tasks','reports','finance','team'].forEach(x=>{
    const pane=document.getElementById('pane'+x.charAt(0).toUpperCase()+x.slice(1));
    if(pane) pane.style.display = (x===t)?'block':'none';
  });
  ['Goals','Mine','Cal','Tasks','Reports','Finance','Team'].forEach(x=>{
    const b=document.getElementById('tab'+x); if(b) b.classList.toggle('on', x.toLowerCase()===t);
  });
  if(t==='team') setTimeout(()=>jdpInit(),0);
  if(t==='mine') loadMine();
  if(t==='cal') loadCalendar();
  if(t==='tasks') loadKanban();
  if(t==='reports') loadReports();
  if(t==='finance') loadFinance();
}

/* ---------- اهداف ---------- */
async function loadGoals(){
  const d=await api('mkt_goals_list');
  if(!d.ok){ document.getElementById('goalsList').innerHTML='<div class="empty">خطا</div>'; return; }
  const goals=d.goals||[];
  if(!goals.length){ document.getElementById('goalsList').innerHTML=emptyState('🎯','هنوز هدفی ثبت نشده','اولین هدف ماهانه را از فرم کنار اضافه کنید'); return; }
  const order=['month','week','day','campaign'];
  const byP={}; goals.forEach(g=>{ (byP[g.period]=byP[g.period]||[]).push(g); });
  let html='';
  order.forEach(p=>{
    if(!byP[p]) return;
    html+=`<div class="card" style="margin-bottom:14px"><div class="card-h"><span class="t">${PERIOD_FA[p]}</span><span class="sub">${faNum(byP[p].length)} هدف</span></div><div class="card-pad" style="padding-top:8px">`;
    byP[p].forEach(g=>{
      const tgt=+g.target_value||0, cur=+g.current_value||0;
      const pct=tgt>0?Math.min(100,Math.round(cur/tgt*100)):0;
      html+=`<div style="padding:10px 0;border-bottom:1px solid var(--line-soft)">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
          <span style="font-weight:700">${esc(g.title)}</span>
          ${g.metric?`<span class="chip">${esc(g.metric)}</span>`:''}
          ${g.owner_name?`<span class="chip"><span class="cdot"></span>${esc(g.owner_name)}</span>`:''}
          ${g.due_date?`<span class="muted" style="font-size:11px">مهلت: ${toFa(g.due_date)}</span>`:''}
          <button class="btn btn-danger-ghost btn-xs" style="margin-right:auto" onclick="delGoal(${g.id})">حذف</button>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
          <div style="flex:1;height:8px;background:var(--line-soft);border-radius:6px;overflow:hidden"><div style="height:100%;width:${pct}%;background:linear-gradient(90deg,var(--brand),var(--brand-2));border-radius:6px"></div></div>
          <span style="font-size:12px;font-weight:700;min-width:120px;text-align:left">${faNum(cur)} / ${faNum(tgt)} <span class="muted">(${faNum(pct)}٪)</span></span>
        </div>
      </div>`;
    });
    html+='</div></div>';
  });
  document.getElementById('goalsList').innerHTML=html;
}
async function saveGoal(){
  const goal={
    period:document.getElementById('gPeriod').value,
    title:document.getElementById('gTitle').value.trim(),
    metric:document.getElementById('gMetric').value.trim(),
    target_value:document.getElementById('gTarget').value||0,
    current_value:document.getElementById('gCurrent').value||0,
    owner_id:document.getElementById('gOwner').value||'',
    due_date:getJDate('gDue')||''
  };
  if(!goal.title){ toast('عنوان لازم است',false); return; }
  const d=await api('mkt_goal_save',{goal});
  if(d.ok){ toast('هدف افزوده شد'); document.getElementById('gTitle').value=''; document.getElementById('gMetric').value=''; document.getElementById('gTarget').value=0; document.getElementById('gCurrent').value=0; setJDate('gDue',''); loadGoals(); }
  else toast(d.error||'خطا',false);
}
async function delGoal(id){ if(!confirm('این هدف حذف شود؟'))return; await api('mkt_goal_delete',{id}); loadGoals(); }

/* ---------- تیم و چارت ---------- */
async function loadTeam(){
  const [rr,mm,uu]=await Promise.all([api('mkt_roles_list'),api('mkt_members_list'),api('mkt_users_linkable')]);
  MKT_ROLES=(rr.roles)||[]; MKT_MEMBERS=(mm.members)||[];
  const ownerOpts='<option value="">—</option>'+MKT_MEMBERS.map(m=>`<option value="${m.id}">${esc(m.full_name)}</option>`).join('');
  document.getElementById('gOwner').innerHTML=ownerOpts;
  document.getElementById('mRole').innerHTML='<option value="">—</option>'+MKT_ROLES.map(r=>`<option value="${r.id}">${esc(r.title)}</option>`).join('');
  document.getElementById('rParent').innerHTML='<option value="">— بدون والد (ریشه)</option>'+MKT_ROLES.map(r=>`<option value="${r.id}">${esc(r.title)}</option>`).join('');
  const uSel=document.getElementById('mUser');
  if(uSel) uSel.innerHTML='<option value="">— بدون دسترسی</option>'+((uu.users)||[]).map(u=>`<option value="${u.id}">${esc(u.username)} (${u.role==='admin'?'مدیر':'اپراتور'})</option>`).join('');
  renderOrgTree();
}
const DEPT_FA={marketing:'مارکتینگ',sales:'فروش',content:'محتوا',design:'طراحی',other:'سایر'};
function renderOrgTree(){
  const box=document.getElementById('orgTree');
  if(!MKT_ROLES.length){ box.innerHTML=emptyState('🌳','چارتی ساخته نشده','اولین نقش (مثلاً مدیر مارکتینگ) را از فرم کنار اضافه کنید'); return; }
  const childrenOf={}; MKT_ROLES.forEach(r=>{ const p=r.parent_id||0; (childrenOf[p]=childrenOf[p]||[]).push(r); });
  const parentOpts=(selfId,curParent)=>'<option value="">ریشه</option>'+MKT_ROLES.filter(r=>r.id!=selfId).map(r=>`<option value="${r.id}" ${r.id==curParent?'selected':''}>${esc(r.title)}</option>`).join('');
  function node(r,depth){
    const mem=(r.members||[]).map(m=>{
      const linked=m.link_username?`<span title="کاربر اعلان‌بان: ${esc(m.link_username)}" style="color:var(--ok)">●</span>`:'';
      const dep=m.department&&m.department!=='marketing'?` · ${DEPT_FA[m.department]||m.department}`:'';
      return `<span class="chip"><span class="av-sm" style="width:18px;height:18px;font-size:9px;border-radius:6px">${esc((m.full_name||'?').slice(0,1))}</span>${esc(m.full_name)}${dep} ${linked}</span>`;
    }).join(' ')||'<span class="muted" style="font-size:11px">بدون عضو</span>';
    const kids=(childrenOf[r.id]||[]);
    return `<div style="margin-right:${depth*18}px;margin-bottom:8px">
      <div class="card" style="border-right:3px solid var(--brand);padding:10px 12px">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span style="font-weight:700">${esc(r.title)}</span>
          <span class="badge ${LEVEL_CLS[r.level]||'b-mut'}">${LEVEL_FA[r.level]||r.level}</span>
          <div style="margin-right:auto;display:flex;gap:6px;align-items:center">
            <select class="chip-select" onchange="reparentRole(${r.id},this.value)" title="تغییر والد">${parentOpts(r.id,r.parent_id||'')}</select>
            <button class="btn btn-danger-ghost btn-xs" onclick="delRole(${r.id})">حذف</button>
          </div>
        </div>
        ${r.duties?`<div class="note" style="margin-top:4px">${esc(r.duties)}</div>`:''}
        <div style="margin-top:8px;display:flex;gap:5px;flex-wrap:wrap">${mem}</div>
      </div>
      ${kids.map(k=>node(k,depth+1)).join('')}
    </div>`;
  }
  box.innerHTML=(childrenOf[0]||[]).map(r=>node(r,0)).join('') || '<div class="empty">همه‌ی نقش‌ها والد دارند ولی ریشه‌ای نیست — یک نقش را به «ریشه» تغییر دهید</div>';
}
async function saveRole(){
  const role={ title:document.getElementById('rTitle').value.trim(), level:document.getElementById('rLevel').value, parent_id:document.getElementById('rParent').value||'', duties:document.getElementById('rDuties').value.trim() };
  if(!role.title){ toast('عنوان نقش لازم است',false); return; }
  const d=await api('mkt_role_save',{role});
  if(d.ok){ toast('نقش افزوده شد'); document.getElementById('rTitle').value=''; document.getElementById('rDuties').value=''; loadTeam(); }
  else toast(d.error||'خطا',false);
}
async function reparentRole(id,parent_id){ const d=await api('mkt_role_save',{role:{id, title:(MKT_ROLES.find(r=>r.id==id)||{}).title, level:(MKT_ROLES.find(r=>r.id==id)||{}).level, parent_id, duties:(MKT_ROLES.find(r=>r.id==id)||{}).duties||''}}); if(d.ok){ toast('والد تغییر کرد'); loadTeam(); } else toast(d.error||'خطا',false); }
async function delRole(id){ if(!confirm('این نقش حذف شود؟ فرزندانش به والدِ آن منتقل می‌شوند.'))return; await api('mkt_role_delete',{id}); loadTeam(); }
async function saveMember(){
  const member={ full_name:document.getElementById('mName').value.trim(), role_id:document.getElementById('mRole').value||'', email:document.getElementById('mEmail').value.trim(), phone:document.getElementById('mPhone').value.trim(), department:document.getElementById('mDept').value, user_id:document.getElementById('mUser').value||'' };
  if(!member.full_name){ toast('نام عضو لازم است',false); return; }
  const d=await api('mkt_member_save',{member});
  if(d.ok){ toast('عضو افزوده شد'); document.getElementById('mName').value=''; document.getElementById('mEmail').value=''; document.getElementById('mPhone').value=''; document.getElementById('mUser').value=''; loadTeam(); }
  else toast(d.error||'خطا',false);
}

/* ---------- تقویم محتوا (شمسی) ---------- */
const CH_FA={instagram:'اینستاگرام',telegram:'تلگرام',bale:'بله',roubika:'روبیکا',whatsapp:'واتساپ',linkedin:'لینکدین',twitter:'توییتر/ایکس',youtube:'یوتیوب',aparat:'آپارات',article:'مقاله',landing:'لندینگ',seo:'سئو',google_ads:'گوگل ادز',divar:'دیوار',sheypoor:'شیپور',reportage:'رپورتاژ',sms:'پیامک',email:'ایمیل'};
const CTYPES={
  instagram:['ریلز','تریال','استوری','کاروسل','اسلایدی','پست','لایو','تردز','ریلز کولَب','گایدلاین'],
  telegram:['پست','نظرسنجی','ویدیو','فایل'],
  bale:['پست','نظرسنجی'],
  roubika:['پست','استوری','ویدیو'],
  rubino:['ریلز','استوری','پست'],
  whatsapp:['قالب','استاتوس'],
  linkedin:['پست','مقاله','ویدیو'],
  twitter:['توییت','رشته'],
  youtube:['ویدیو','شورت'],
  aparat:['ویدیو'],
  namasha:['ویدیو'],
  article:['مقاله'],
  landing:['لندینگ'],
  seo:['تسک سئو'],
  google_ads:['تبلیغ'],
  divar:['آگهی'],
  sheypoor:['آگهی'],
  reportage:['رپورتاژ'],
  billboard:['بیلبورد'],
  influencer:['تبلیغ اینفلوئنسر'],
  sms:['کمپین'],
  email:['کمپین','خبرنامه'],
  push:['نوتیفیکیشن']
};
const JMON=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
let CAL_Y=0, CAL_M=0;
function jlen(y,m){ return m<=6?31:(m<=11?30:(((y%33%4)===1)?30:29)); }
/* ---------- پیج‌ها (تفکیک تقویم بین چند پیج شرکت) ---------- */
let MKT_PAGES=[];
const PAGE_PLATFORM_FA={instagram:'اینستاگرام',telegram:'تلگرام',bale:'بله',roubika:'روبیکا',whatsapp:'واتساپ',linkedin:'لینکدین',twitter:'توییتر',youtube:'یوتیوب',aparat:'آپارات',article:'سایت'};
async function loadPages(){
  const d=await api('mkt_pages');
  MKT_PAGES=(d.ok&&d.pages)||[];
  const sel=document.getElementById('calPage');
  if(sel){
    const cur=sel.value;
    sel.innerHTML='<option value="">همه‌ی پیج‌ها</option>'+MKT_PAGES.map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join('')+'<option value="0">— بدون پیج —</option>';
    sel.value=[...sel.options].some(o=>o.value===cur)?cur:'';
  }
  fillPageSelect(document.getElementById('cPage'));
  return MKT_PAGES;
}
function pageById(id){ return MKT_PAGES.find(p=>String(p.id)===String(id)); }
function calPageFilter(){ const v=(document.getElementById('calPage')||{}).value||''; return (v&&v!=='0')?v:''; }
function fillPageSelect(sel, value){
  if(!sel) return;
  const v=value!==undefined?String(value||''):sel.value;
  sel.innerHTML='<option value="">— بدون پیج —</option>'+MKT_PAGES.map(p=>`<option value="${p.id}">${esc(p.name)}${p.platform&&PAGE_PLATFORM_FA[p.platform]?' · '+PAGE_PLATFORM_FA[p.platform]:''}</option>`).join('')+'<option value="__new">+ پیج جدید…</option>';
  sel.value=[...sel.options].some(o=>o.value===v)?v:'';
}
async function cPageChanged(sel){
  if(sel.value!=='__new') return;
  const name=prompt('نام پیج جدید (مثلاً «پیج فروشگاه»):','');
  if(!name||!name.trim()){ sel.value=''; return; }
  const d=await api('mkt_page_save',{page:{name:name.trim(),platform:document.getElementById('cChannel').value||'instagram'}});
  if(!d.ok){ toast(d.error||'خطا',false); sel.value=''; return; }
  await loadPages(); fillPageSelect(sel, d.id); toast('پیج «'+name.trim()+'» ساخته شد');
}

/* ---------- تقویم ---------- */
let CAL_BY_DAY={}, DAY_OPEN=0;
async function loadCalendar(){
  if(!CAL_Y){ const t=jToday(); CAL_Y=t[0]; CAL_M=t[1]; }
  if(!MKT_PAGES.length) await loadPages();
  const g1=j2g(CAL_Y,CAL_M,1), g2=j2g(CAL_Y,CAL_M,jlen(CAL_Y,CAL_M));
  const pad=n=>String(n).padStart(2,'0');
  const from=g1[0]+'-'+pad(g1[1])+'-'+pad(g1[2]), to=g2[0]+'-'+pad(g2[1])+'-'+pad(g2[2]);
  const pf=(document.getElementById('calPage')||{}).value||'';
  const d=await api('mkt_content_list',pf!==''?{from,to,page_id:pf}:{from,to});
  const items=(d.content)||[]; window._mktContent=items;
  const byDay={};
  items.forEach(it=>{ if(!it.plan_date)return; const m=it.plan_date.match(/(\d{4})-(\d{2})-(\d{2})/); if(!m)return; const j=g2j(+m[1],+m[2],+m[3]); if(j[0]===CAL_Y&&j[1]===CAL_M)(byDay[j[2]]=byDay[j[2]]||[]).push(it); });
  CAL_BY_DAY=byDay;
  document.getElementById('calLabel').textContent=JMON[CAL_M-1]+' '+faNum(CAL_Y);
  // راهنمای رنگ پیج‌ها (کلیک = فیلتر)
  const leg=document.getElementById('calLegend');
  if(leg) leg.innerHTML=MKT_PAGES.length?MKT_PAGES.map(p=>`<span class="lg${pf===String(p.id)?' on':''}" onclick="calPickPage('${p.id}')"><span class="dot" style="background:${esc(p.color)}"></span>${esc(p.name)}</span>`).join(''):'';
  const len=jlen(CAL_Y,CAL_M);
  const gg=j2g(CAL_Y,CAL_M,1); const startCol=(new Date(gg[0],gg[1]-1,gg[2]).getDay()+1)%7;
  const tj=jToday(); const isThisMonth=(tj[0]===CAL_Y&&tj[1]===CAL_M);
  const dow=['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'];
  const showPage=(pf==='');
  let html='<div class="mcal">';
  dow.forEach(x=>html+=`<div class="mcal-dow">${x}</div>`);
  for(let i=0;i<startCol;i++) html+='<div class="mcal-cell empty-cell"></div>';
  for(let day=1;day<=len;day++){
    const its=byDay[day]||[];
    const isToday=isThisMonth&&tj[2]===day;
    const chips=its.slice(0,3).map(it=>{
      const prog=(+it.steps_total>0)?` · پیشرفت ${Math.round(it.steps_done/it.steps_total*100)}٪`:'';
      const pc=it.page_color?`--pc:${esc(it.page_color)}`:'';
      return `<div class="mcal-chip" style="${pc}" onclick="editContent(${it.id});event.stopPropagation()" title="${esc((it.page_name?it.page_name+' — ':'')+it.title)}${prog}"><span class="cdot ch-${it.channel||''}"></span>${showPage&&it.page_name?`<span class="pg">${esc(it.page_name)}</span>`:''}<span class="mcal-chip-txt">${esc(it.title)}</span></div>`;
    }).join('');
    const more=its.length>3?`<div class="mcal-more" onclick="openDay(${day});event.stopPropagation()">+${faNum(its.length-3)} مورد دیگر — مشاهده همه</div>`:'';
    html+=`<div class="mcal-cell${isToday?' today':''}" onclick="openDay(${day})" title="مشاهده‌ی همه‌ی موارد این روز">
      <div class="mcal-head"><div class="mcal-num">${faNum(day)}</div>${its.length?`<span class="mcal-count">${faNum(its.length)} مورد</span>`:''}<button type="button" class="mcal-add" title="افزودن محتوا به این روز" onclick="newContentOn(${day});event.stopPropagation()">＋</button></div>${chips}${more}</div>`;
  }
  html+='</div>';
  document.getElementById('calGrid').innerHTML=html;
  if(DAY_OPEN && document.getElementById('dayModal').style.display!=='none') renderDay(DAY_OPEN);
}
function calPickPage(id){ const sel=document.getElementById('calPage'); sel.value=(sel.value===String(id))?'':String(id); loadCalendar(); }
function calNav(dir){ if(dir===0){ const t=jToday(); CAL_Y=t[0]; CAL_M=t[1]; } else { CAL_M+=dir; if(CAL_M<1){CAL_M=12;CAL_Y--;} if(CAL_M>12){CAL_M=1;CAL_Y++;} } loadCalendar(); }
function dayGreg(day){ const g=j2g(CAL_Y,CAL_M,day); const pad=n=>String(n).padStart(2,'0'); return g[0]+'-'+pad(g[1])+'-'+pad(g[2]); }
function newContentOn(day, pageId){ newContent(dayGreg(day), pageId!==undefined?pageId:calPageFilter()); }

/* ---------- مشاهده‌ی روز (جدا از افزودن) ---------- */
function openDay(day){ DAY_OPEN=day; renderDay(day); document.getElementById('dayModal').style.display='flex'; }
function closeDay(){ document.getElementById('dayModal').style.display='none'; DAY_OPEN=0; }
function dayAdd(pageId){ const d=DAY_OPEN; if(!d) return; newContentOn(d, pageId); }
function renderDay(day){
  const its=(CAL_BY_DAY[day]||[]).slice().sort((a,b)=>(a.plan_time||'99').localeCompare(b.plan_time||'99'));
  const g=j2g(CAL_Y,CAL_M,day); const dw=['یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه','شنبه'][new Date(g[0],g[1]-1,g[2]).getDay()];
  const pf=(document.getElementById('calPage')||{}).value||'';
  const fp=pf&&pf!=='0'?pageById(pf):null;
  document.getElementById('dayTitle').textContent=`📅 ${dw} ${faNum(day)} ${JMON[CAL_M-1]} ${faNum(CAL_Y)} — ${faNum(its.length)} مورد${fp?' · فقط '+fp.name:''}`;
  const box=document.getElementById('dayBody');
  if(!its.length){ box.innerHTML=emptyState('🗓','برای این روز چیزی برنامه‌ریزی نشده','با «افزودن به این روز» محتوا اضافه کنید'); return; }
  // گروه‌بندی بر اساس پیج
  const groups={}; const order=[];
  its.forEach(it=>{ const k=it.page_id?String(it.page_id):'_'; if(!groups[k]){ groups[k]={name:it.page_name||'بدون پیج',color:it.page_color||'',id:it.page_id||'',items:[]}; order.push(k);} groups[k].items.push(it); });
  order.sort((a,b)=>a==='_'?1:(b==='_'?-1:0));
  box.innerHTML=order.map(k=>{
    const gr=groups[k];
    return `<div class="day-group" style="--pc:${esc(gr.color||'var(--line)')}">
      <div class="day-group-h"><span class="dot" style="width:10px;height:10px;border-radius:50%;background:${esc(gr.color||'var(--ink-faint)')}"></span>${esc(gr.name)} <span class="muted" style="font-weight:400;font-size:11.5px">${faNum(gr.items.length)} مورد</span>
        <button class="btn btn-ghost btn-xs" style="margin-inline-start:auto" onclick="dayAdd('${gr.id}')">+ برای این پیج</button></div>
      ${gr.items.map(it=>`<div class="day-item" onclick="editContent(${it.id})">
        <span class="tm">${it.plan_time?faNum(it.plan_time):'—'}</span>
        <span class="cdot ch-${it.channel||''}"></span>
        <span class="ttl" title="${esc(it.title)}">${esc(it.title)}</span>
        ${it.channel?`<span class="chip">${esc(CH_FA[it.channel]||it.channel)}${it.content_type?' · '+esc(it.content_type):''}</span>`:''}
        <span class="chip" style="color:${STATUS_COL[it.status]||'inherit'}">${esc(STATUS_FA[it.status]||it.status)}</span>
        ${it.assignee_name?`<span class="chip">${esc(it.assignee_name)}</span>`:''}
        <button class="btn btn-ghost btn-xs" onclick="event.stopPropagation();editContent(${it.id})">ویرایش</button>
        <button class="btn btn-danger-ghost btn-xs" onclick="event.stopPropagation();delContent(${it.id})">حذف</button>
      </div>`).join('')}
    </div>`;
  }).join('');
}

/* ---------- مدیریت پیج‌ها ---------- */
async function openPagesModal(){ await renderPagesList(); document.getElementById('pagesModal').style.display='flex'; }
function closePagesModal(){ document.getElementById('pagesModal').style.display='none'; loadPages().then(()=>{ if(document.getElementById('paneCal').style.display!=='none') loadCalendar(); }); }
async function renderPagesList(){
  const d=await api('mkt_pages',{all:1});
  const list=(d.ok&&d.pages)||[];
  const box=document.getElementById('pagesList');
  if(!list.length){ box.innerHTML='<div class="muted" style="font-size:12.5px">هنوز پیجی تعریف نشده</div>'; return; }
  box.innerHTML=list.map(p=>`<div class="pg-row" id="pgRow${p.id}">
    <input type="color" value="${esc(p.color||'#6366f1')}" id="pgColor${p.id}">
    <input id="pgName${p.id}" value="${esc(p.name)}" style="flex:1;min-width:0">
    <span class="chip">${esc(PAGE_PLATFORM_FA[p.platform]||p.platform||'سایر')}${p.ig_account_id?' · متصل':''}</span>
    <span class="muted" style="font-size:11px;white-space:nowrap">${faNum(p.content_count||0)} محتوا</span>
    <label style="display:flex;align-items:center;gap:4px;font-size:11.5px;white-space:nowrap"><input type="checkbox" id="pgAct${p.id}" style="width:auto" ${+p.is_active?'checked':''}> فعال</label>
    <button class="btn btn-ghost btn-xs" onclick="savePageRow(${p.id},'${esc(p.platform||'')}')">ذخیره</button>
    <button class="btn btn-danger-ghost btn-xs" onclick="delPage(${p.id})">حذف</button>
  </div>`).join('');
}
async function savePageRow(id, platform){
  const d=await api('mkt_page_save',{page:{id,name:document.getElementById('pgName'+id).value.trim(),color:document.getElementById('pgColor'+id).value,platform,is_active:document.getElementById('pgAct'+id).checked?1:0}});
  toast(d.ok?'ذخیره شد':(d.error||'خطا'),!!d.ok);
}
async function addPage(){
  const name=document.getElementById('npName').value.trim();
  if(!name){ toast('نام پیج لازم است',false); return; }
  const d=await api('mkt_page_save',{page:{name,platform:document.getElementById('npPlatform').value,color:document.getElementById('npColor').value}});
  if(!d.ok){ toast(d.error||'خطا',false); return; }
  document.getElementById('npName').value=''; toast('پیج اضافه شد'); renderPagesList();
}
async function delPage(id){
  if(!confirm('این پیج حذف شود؟ محتواهایش حذف نمی‌شوند و «بدون پیج» می‌شوند.')) return;
  const d=await api('mkt_page_delete',{id}); if(d.ok){ toast('حذف شد'); renderPagesList(); } else toast(d.error||'خطا',false);
}

/* ---------- کانبان ---------- */
const KCOL=[['idea','ایده / شروع نشده'],['producing','در حال تولید'],['review','بازبینی/تأیید'],['ready','آماده انتشار'],['published','منتشر شده']];
async function loadKanban(){
  const d=await api('mkt_content_list');
  const items=(d.content)||[]; window._mktContent=items;
  const byS={}; KCOL.forEach(c=>byS[c[0]]=[]); items.forEach(it=>{ (byS[it.status]=byS[it.status]||[]).push(it); });
  let html='';
  KCOL.forEach((c,ci)=>{
    const list=byS[c[0]]||[];
    html+=`<div style="background:var(--surface-2);border:1px solid var(--line);border-radius:14px;overflow:hidden">
      <div style="padding:10px 12px;font-size:12px;font-weight:700;border-bottom:1px solid var(--line);display:flex;justify-content:space-between"><span>${c[1]}</span><span class="muted">${faNum(list.length)}</span></div>
      <div style="padding:8px;display:flex;flex-direction:column;gap:8px;min-height:60px">`;
    if(!list.length) html+='<div class="muted" style="text-align:center;font-size:11px;padding:14px">—</div>';
    list.forEach(it=>{
      html+=`<div class="card" style="padding:10px;box-shadow:var(--shadow-xs)">
        <div style="font-weight:600;font-size:12.5px;margin-bottom:5px">${esc(it.title)}</div>
        <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:6px">
          ${it.page_name?`<span class="chip" style="border-color:${esc(it.page_color||'')}"><span class="cdot" style="background:${esc(it.page_color||'')};border-radius:50%"></span>${esc(it.page_name)}</span>`:''}
          ${it.channel?`<span class="chip"><span class="cdot ch-${it.channel}"></span>${CH_FA[it.channel]||it.channel}</span>`:''}
          ${it.content_type?`<span class="chip">${esc(it.content_type)}</span>`:''}
          ${it.assignee_name?`<span class="chip"><span class="av-sm" style="width:16px;height:16px;font-size:8px;border-radius:5px">${esc((it.assignee_name||'?').slice(0,1))}</span>${esc(it.assignee_name)}</span>`:''}
        </div>
        ${it.plan_date?`<div class="muted" style="font-size:10.5px;margin-bottom:6px">📅 ${toFa(it.plan_date)}${it.plan_time?' '+faNum(it.plan_time):''}</div>`:''}
        ${(+it.steps_total>0)?`<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px"><div style="flex:1;height:5px;background:var(--line-soft);border-radius:4px;overflow:hidden"><div style="height:100%;width:${Math.round(it.steps_done/it.steps_total*100)}%;background:linear-gradient(90deg,var(--brand),var(--brand-2))"></div></div><span style="font-size:10px;color:var(--ink-faint)">${faNum(it.steps_done)}/${faNum(it.steps_total)}</span></div>`:''}
        <div style="display:flex;gap:4px;align-items:center">
          <button class="btn btn-ghost btn-xs" ${ci===0?'disabled':''} onclick="moveCard(${it.id},'${KCOL[Math.max(0,ci-1)][0]}')" title="عقب">▶</button>
          <button class="btn btn-ghost btn-xs" ${ci===KCOL.length-1?'disabled':''} onclick="moveCard(${it.id},'${KCOL[Math.min(KCOL.length-1,ci+1)][0]}')" title="جلو">◀</button>
          <button class="btn btn-ghost btn-xs" onclick="editContent(${it.id})">ویرایش</button>
          <button class="btn btn-danger-ghost btn-xs" style="margin-right:auto" onclick="delContent(${it.id})">حذف</button>
        </div>
      </div>`;
    });
    html+='</div></div>';
  });
  document.getElementById('kanban').innerHTML=html;
}
async function moveCard(id,status){ await api('mkt_content_move',{id,status}); loadKanban(); }

/* ---------- مودال محتوا ---------- */
function cTypeOptions(){
  const ch=document.getElementById('cChannel').value;
  const types=CTYPES[ch]||[];
  document.getElementById('cType').innerHTML='<option value="">—</option>'+types.map(t=>`<option value="${t}">${t}</option>`).join('');
  renderDynFields();
}
function fillAssignee(sel){ sel.innerHTML='<option value="">—</option>'+MKT_MEMBERS.map(m=>`<option value="${m.id}">${esc(m.full_name)}</option>`).join(''); }
function openContentModal(){ document.getElementById('cModal').style.display='flex'; fillAssignee(document.getElementById('cAssignee')); setTimeout(()=>jdpInit(),0); }
function closeContent(){ document.getElementById('cModal').style.display='none'; }
function resetContentForm(){
  ['cId','cTitle','cTime','cScenario','cBudget','cSpent'].forEach(i=>document.getElementById(i).value='');
  document.getElementById('cChannel').value=''; cTypeOptions();
  document.getElementById('cStatus').value='idea'; document.getElementById('cAssignee').value='';
  setJDate('cDate','');
  document.getElementById('cDynFields').innerHTML='';
  const ex=document.getElementById('cExtra'); if(ex) ex.style.display='none';
  document.getElementById('cSteps').innerHTML=''; document.getElementById('cCrew').innerHTML='';
}
function newContent(date, pageId){
  resetContentForm(); document.getElementById('cModalTitle').textContent='محتوای جدید'; openContentModal();
  if(date) setJDate('cDate',date);
  const ps=document.getElementById('cPage');
  const apply=()=>{ fillPageSelect(ps, pageId||''); const pg=pageById(pageId); if(pg&&pg.platform&&!document.getElementById('cChannel').value){ document.getElementById('cChannel').value=pg.platform; cTypeOptions(); } };
  MKT_PAGES.length?apply():loadPages().then(apply);
}
function editContent(id){
  const it=(window._mktContent||[]).find(x=>x.id==id);
  if(!it){ api('mkt_content_list').then(d=>{ window._mktContent=d.content||[]; editContent(id); }); return; }
  resetContentForm();
  document.getElementById('cModalTitle').textContent='ویرایش محتوا';
  document.getElementById('cId').value=it.id;
  document.getElementById('cTitle').value=it.title||'';
  document.getElementById('cChannel').value=it.channel||''; cTypeOptions();
  document.getElementById('cType').value=it.content_type||'';
  document.getElementById('cStatus').value=it.status||'idea';
  openContentModal();
  { const ps=document.getElementById('cPage'); MKT_PAGES.length?fillPageSelect(ps, it.page_id||''):loadPages().then(()=>fillPageSelect(ps, it.page_id||'')); }
  document.getElementById('cAssignee').value=it.assignee_id||'';
  document.getElementById('cTime').value=it.plan_time||'';
  document.getElementById('cScenario').value=it.scenario||'';
  document.getElementById('cBudget').value=(it.budget&&+it.budget)?(+it.budget):'';
  document.getElementById('cSpent').value=(it.spent&&+it.spent)?(+it.spent):'';
  // فیلدهای اختصاصی را با مقادیر ذخیره‌شده بساز
  let saved={}; try{ saved=it.fields_json?JSON.parse(it.fields_json):{}; }catch(e){}
  renderDynFields(saved);
  if(it.plan_date) setJDate('cDate',it.plan_date.slice(0,10));
  // نمایش رودمپ و عوامل (فقط در ویرایش)
  document.getElementById('cExtra').style.display='block';
  loadContentDetail(it.id);
}
let CUR_CONTENT_ID=0, CUR_CONTENT_TYPE='';
async function loadContentDetail(id){
  CUR_CONTENT_ID=id;
  const d=await api('mkt_content_detail',{id});
  if(!d.ok){ return; }
  CUR_CONTENT_TYPE=(d.content&&d.content.content_type)||'';
  renderSteps(d.steps||[], d.progress||0);
  renderCrew(d.crew||[]);
  renderSendBridge(d.content||{});
  renderPerf(id);
  // پر کردن سلکت اعضا برای crew
  const sel=document.getElementById('cCrewMember');
  if(sel) sel.innerHTML='<option value="">شخص…</option>'+MKT_MEMBERS.map(m=>`<option value="${m.id}">${esc(m.full_name)}</option>`).join('');
}
function renderSteps(steps, progress){
  const box=document.getElementById('cSteps');
  document.getElementById('cProgLbl').textContent='('+faNum(progress)+'٪ کامل)';
  document.querySelector('#cProgBar>div').style.width=progress+'%';
  if(!steps.length){ box.innerHTML='<div class="muted" style="font-size:12px;padding:6px 0">مرحله‌ای نیست. می‌توانید رودمپ پیش‌فرض این نوع را تزریق کنید.</div>'+
    `<button class="btn btn-ghost btn-xs" onclick="seedStepsNow()">تزریق رودمپ پیش‌فرض</button>`; return; }
  box.innerHTML=steps.map(s=>{
    const done=!!s.done_at;
    return `<div style="display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--line-soft)">
      <input type="checkbox" ${done?'checked':''} onchange="toggleStep(${s.id},this.checked)" style="width:17px;height:17px;flex-shrink:0;cursor:pointer">
      <span style="flex:1;font-size:13px;${done?'text-decoration:line-through;color:var(--ink-faint)':''}">${esc(s.step_name)}</span>
      ${s.assignee_name?`<span class="chip"><span class="av-sm" style="width:16px;height:16px;font-size:8px;border-radius:5px">${esc(s.assignee_name.slice(0,1))}</span>${esc(s.assignee_name)}</span>`:''}
      <select class="chip-select" style="max-width:120px" onchange="assignStep(${s.id},'${esc(s.step_name).replace(/'/g,"")}',this.value)"><option value="">مسئول…</option>${MKT_MEMBERS.map(m=>`<option value="${m.id}" ${m.id==s.assignee_id?'selected':''}>${esc(m.full_name)}</option>`).join('')}</select>
      <button class="btn btn-danger-ghost btn-xs" onclick="delStep(${s.id})">✕</button>
    </div>`;
  }).join('');
}
function renderCrew(crew){
  const box=document.getElementById('cCrew');
  if(!crew.length){ box.innerHTML='<div class="muted" style="font-size:12px">هنوز عاملی اضافه نشده</div>'; return; }
  box.innerHTML='<div style="display:flex;flex-wrap:wrap;gap:6px">'+crew.map(c=>
    `<span class="chip" style="padding:5px 9px">${esc(c.prod_role)}${c.full_name?': '+esc(c.full_name):' (تعیین‌نشده)'}<button onclick="delCrew(${c.id})" style="border:none;background:none;cursor:pointer;color:var(--err);margin-right:4px">✕</button></span>`
  ).join('')+'</div>';
}
async function addStep(){
  const name=document.getElementById('cNewStep').value.trim();
  if(!name||!CUR_CONTENT_ID){ toast('نام مرحله لازم است',false); return; }
  const d=await api('mkt_step_save',{step:{content_id:CUR_CONTENT_ID, step_name:name}});
  if(d.ok){ document.getElementById('cNewStep').value=''; loadContentDetail(CUR_CONTENT_ID); }
  else toast(d.error||'خطا',false);
}
async function toggleStep(id,done){ await api('mkt_step_toggle',{id,done:done?1:0}); loadContentDetail(CUR_CONTENT_ID); }
async function assignStep(id,name,assignee_id){ await api('mkt_step_save',{step:{id,content_id:CUR_CONTENT_ID,step_name:name,assignee_id}}); loadContentDetail(CUR_CONTENT_ID); }
async function delStep(id){ if(!confirm('این مرحله حذف شود؟'))return; await api('mkt_step_delete',{id}); loadContentDetail(CUR_CONTENT_ID); }
async function seedStepsNow(){ await api('mkt_steps_seed',{content_id:CUR_CONTENT_ID, content_type:CUR_CONTENT_TYPE}); loadContentDetail(CUR_CONTENT_ID); }
async function addCrew(){
  const role=document.getElementById('cCrewRole').value, member=document.getElementById('cCrewMember').value;
  if(!role){ toast('نقش تولید را انتخاب کنید',false); return; }
  const d=await api('mkt_crew_add',{content_id:CUR_CONTENT_ID, member_id:member||'', prod_role:role});
  if(d.ok){ document.getElementById('cCrewRole').value=''; document.getElementById('cCrewMember').value=''; loadContentDetail(CUR_CONTENT_ID); }
  else toast(d.error||'خطا',false);
}
async function delCrew(id){ await api('mkt_crew_delete',{id}); loadContentDetail(CUR_CONTENT_ID); }
const SENDABLE_CH={telegram:'تلگرام',bale:'بله',roubika:'روبیکا',sms:'پیامک',email:'ایمیل'};
function renderSendBridge(c){
  const box=document.getElementById('cSendBridge'); if(!box) return;
  const ch=c.channel||'';
  if(ch==='instagram'){ renderIgBridge(c,box); return; }
  const sendable=!!SENDABLE_CH[ch];
  const ready=(c.status==='ready'||c.status==='published');
  if(!sendable){ box.style.display='none'; return; }
  box.style.display='block';
  if(c.status==='published'){
    box.innerHTML=`<div class="demo-banner" style="background:var(--ok-soft);color:var(--ok)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 6L9 17l-5-5"/></svg> این محتوا منتشر شده است.</div>`;
    return;
  }
  box.innerHTML=`<div style="background:var(--brand-soft);border:1px solid var(--line);border-radius:12px;padding:14px">
    <div style="font-weight:700;font-size:13px;margin-bottom:4px">📤 ارسال از اعلان‌بان</div>
    <div class="note" style="margin-bottom:10px">این محتوا روی کانال «${SENDABLE_CH[ch]}» قابل ارسال است. متن و دکمه‌ها به صفحه‌ی ارسال منتقل می‌شوند؛ ${ready?'':'<b>هنوز در وضعیت آماده نیست.</b> '}دکمه‌ی نهایی ارسال همیشه با شماست.</div>
    <button class="btn ${ready?'btn-primary':'btn-ghost'} btn-sm" onclick="goSend(${c.id})">${ready?'انتقال به صفحه‌ی ارسال':'ارسال (با وجود آماده‌نبودن)'}</button>
  </div>`;
}
/* انتشار خودکار در اینستاگرام از تقویم محتوا (صف انتشار اعلان‌بان) */
let MKT_IG_IMG='';
async function renderIgBridge(c,box){
  const page=(MKT_PAGES||[]).find(p=>String(p.id)===String(c.page_id||''));
  box.style.display='block';
  if(!page||!page.ig_account_id){
    box.innerHTML=`<div class="note" style="background:var(--surface-2);border:1px solid var(--line);border-radius:12px;padding:12px">برای انتشار خودکار، پیجی را برای این محتوا انتخاب کنید که در بخش اینستاگرام اعلان‌بان وصل شده است.</div>`;
    return;
  }
  MKT_IG_IMG='';
  const isStory=/استوری/.test(c.content_type||'');
  const runAt=(c.plan_date?String(c.plan_date).slice(0,10):'')+(c.plan_date?'T'+((c.plan_time||'10:00').slice(0,5)):'');
  const caption=String(c.scenario||'').replace(/\s*\[seed:[^\]]+\]\s*$/,'');
  box.innerHTML=`<div style="background:var(--brand-soft);border:1px solid var(--line);border-radius:12px;padding:14px">
    <div style="font-weight:700;font-size:13px;margin-bottom:4px">انتشار خودکار در اینستاگرام · ${esc(page.name)}</div>
    <div class="note" style="margin-bottom:10px">عکس را بگذارید و زمان را تأیید کنید؛ اعلان‌بان در همان زمان منتشر می‌کند و وضعیت این محتوا خودکار «منتشر شده» می‌شود.</div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <div class="field" style="margin:0"><label>نوع</label><select id="mkIgKind"><option value="post" ${isStory?'':'selected'}>پست</option><option value="story" ${isStory?'selected':''}>استوری</option></select></div>
      <div class="field" style="margin:0"><label>عکس</label><input type="file" id="mkIgFile" accept="image/jpeg,image/png,image/webp" onchange="mkIgUpload(this)"><div class="hint" id="mkIgSt" style="margin:2px 0 0"></div></div>
      <div class="field" style="margin:0"><label>زمان انتشار</label><input type="datetime-local" id="mkIgAt" dir="ltr" value="${esc(runAt)}"></div>
    </div>
    <div class="field" style="margin-top:8px"><label>کپشن (فقط پست)</label><textarea id="mkIgCap" rows="3">${esc(caption)}</textarea></div>
    <button class="btn btn-primary btn-sm" onclick="mkIgSchedule(${c.id},${+page.ig_account_id})">زمان‌بندی انتشار</button>
    <div id="mkIgList" style="margin-top:10px"></div></div>`;
  mkIgList(c.id);
}
async function mkIgUpload(inp){
  const f=inp.files&&inp.files[0]; if(!f) return;
  const st=document.getElementById('mkIgSt'); st.textContent='در حال آپلود…';
  const fd=new FormData(); fd.append('image',f);
  const d=await api('upload_image',fd,true);
  if(d.ok&&(d.rel||d.path)){ MKT_IG_IMG=d.rel||d.path; st.innerHTML='<span class="tag tag-ok">آماده</span>'; } else { MKT_IG_IMG=''; st.textContent='آپلود ناموفق: '+(d.error||''); }
}
async function mkIgSchedule(cid, acc){
  if(!MKT_IG_IMG){ toast('اول عکس را آپلود کنید',false); return; }
  const at=document.getElementById('mkIgAt').value;
  if(!at){ toast('زمان انتشار را انتخاب کنید',false); return; }
  const d=await api('igpost_save',{ig_account_id:acc, mkt_content_id:cid, kind:document.getElementById('mkIgKind').value, media_path:MKT_IG_IMG, caption:document.getElementById('mkIgCap').value.trim(), run_at:at.replace('T',' ')+':00'});
  if(d.ok){ toast('در صف انتشار قرار گرفت'); MKT_IG_IMG=''; document.getElementById('mkIgFile').value=''; document.getElementById('mkIgSt').textContent=''; mkIgList(cid); }
  else toast(d.error||'خطا',false);
}
async function mkIgList(cid){
  const box=document.getElementById('mkIgList'); if(!box) return;
  const d=await api('igpost_list',{mkt_content_id:cid});
  const st={pending:['در صف','tag-warn'],publishing:['در حال انتشار','tag-accent'],published:['منتشر شد','tag-ok'],failed:['ناموفق','tag-err'],cancelled:['لغو شد','tag-mute']};
  const rows=(d.posts||[]);
  box.innerHTML=rows.map(r=>`<div style="display:flex;gap:8px;align-items:center;padding:6px 0;border-top:1px solid var(--line);font-size:12px"><img src="${esc(r.thumb)}" alt="" style="width:34px;height:34px;border-radius:6px;object-fit:cover"><span style="flex:1">${r.kind==='story'?'استوری':'پست'} · ${toFa((r.status==='published'?r.published_at_local:r.run_at_local)||'')}${r.error?` · <span style="color:var(--err)">${esc(r.error)}</span>`:''}</span><span class="tag ${(st[r.status]||['','tag-mute'])[1]}">${(st[r.status]||[r.status])[0]}</span>${r.status==='pending'?`<button class="btn btn-ghost btn-xs" onclick="api('igpost_cancel',{id:${r.id}}).then(()=>mkIgList(${cid}))">لغو</button>`:''}${r.status==='failed'?`<button class="btn btn-ghost btn-xs" onclick="api('igpost_retry',{id:${r.id}}).then(()=>mkIgList(${cid}))">تلاش دوباره</button>`:''}</div>`).join('');
}
function goSend(id){ window.location.href='index.php?page=send&from_content='+id; }
async function saveContent2(){
  const content={
    id:document.getElementById('cId').value||'',
    title:document.getElementById('cTitle').value.trim(),
    channel:document.getElementById('cChannel').value,
    content_type:document.getElementById('cType').value,
    status:document.getElementById('cStatus').value,
    assignee_id:document.getElementById('cAssignee').value||'',
    plan_date:getJDate('cDate')||'',
    plan_time:enNum(document.getElementById('cTime').value.trim()),
    scenario:document.getElementById('cScenario').value.trim(),
    budget:enNum(document.getElementById('cBudget').value.trim())||0,
    spent:enNum(document.getElementById('cSpent').value.trim())||0,
    fields:collectDynFields(),
    page_id:(v=>(v&&v!=='__new')?v:'')(document.getElementById('cPage').value)
  };
  if(!content.title){ toast('عنوان لازم است',false); return; }
  const d=await api('mkt_content_save',{content});
  if(d.ok){ toast('ذخیره شد'); closeContent(); refreshContentViews(); }
  else toast(d.error||'خطا',false);
}
async function delContent(id){ if(!confirm('این مورد حذف شود؟'))return; await api('mkt_content_delete',{id}); toast('حذف شد'); refreshContentViews(); }
function refreshContentViews(){
  if(document.getElementById('paneCal').style.display!=='none') loadCalendar();
  if(document.getElementById('paneTasks').style.display!=='none') loadKanban();
}


/* ---------- گزارش عملکرد محتوا (دستی + خودکار) ---------- */
const PERF_F=[['views','👁 بازدید/پخش'],['reach','👥 ریچ'],['impressions','📈 ایمپرشن'],['likes','❤️ لایک'],['comments','💬 کامنت'],['shares','↗️ اشتراک'],['saves','🔖 ذخیره'],['clicks','🔗 کلیک لینک'],['follows','➕ فالوور جدید'],['dms','✉️ دایرکت'],['leads','🎯 سرنخ'],['sales','🛒 فروش (تعداد)'],['revenue','💰 درآمد (تومان)'],['watch_sec','⏱ زمان تماشا (ثانیه)']];
let PERF=null;
function perfNum(v){ return v==null?'—':faNum(Number(v).toLocaleString('en-US')); }
async function renderPerf(id){
  const box=document.getElementById('cPerf'); if(!box) return;
  box.innerHTML='<div class="muted" style="font-size:12px">در حال بارگذاری آمار…</div>';
  const d=await api('mktm_get',{content_id:id});
  if(!d.ok){ box.innerHTML=`<div class="muted" style="font-size:12px">${esc(d.error||'آمار در دسترس نیست')}</div>`; return; }
  PERF=d; const L=d.latest||{}; const c=d.content||{};
  const eng=(L.likes||0)+(L.comments||0)+(L.shares||0)+(L.saves||0); const base=L.reach||L.views||0;
  const shown=PERF_F.filter(f=>L[f[0]]!=null);
  box.innerHTML=`<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px">
      <div style="font-weight:700;font-size:13px">📊 گزارش عملکرد</div>
      ${L.captured_at?`<span class="muted" style="font-size:11px">آخرین ثبت: ${faNum(toFa(d.history[0]?.captured_local||''))}</span>`:''}
      <span style="flex:1"></span>
      <button class="btn btn-ghost btn-xs" onclick="perfCollect()" title="از اینستاگرام، کامنت‌های ثبت‌شده و لینک رهگیری">⚡ به‌روزرسانی خودکار</button>
      <button class="btn btn-primary btn-xs" onclick="perfForm()">+ ثبت آمار</button>
    </div>
    ${shown.length?`<div class="perf-grid">${shown.map(f=>`<div class="perf-k"><span>${f[1]}</span><b>${perfNum(L[f[0]])}</b></div>`).join('')}${base?`<div class="perf-k hl"><span>نرخ تعامل</span><b>${faNum((eng*100/base).toFixed(2))}٪</b><small class="muted">(لایک+کامنت+اشتراک+ذخیره) ÷ ${L.reach?'ریچ':'بازدید'}</small></div>`:''}</div>`
      :`<div class="muted" style="font-size:12px;margin-bottom:8px">هنوز آماری ثبت نشده. بعد از انتشار، آمار را دستی وارد کنید یا پست را وصل کنید تا خودکار جمع شود.</div>`}
    <details style="margin-top:10px" ${c.ext_media_id||c.ext_url||c.link_code?'':'open'}><summary style="cursor:pointer;font-size:12.5px;font-weight:600">🔌 اتصال برای آمار خودکار</summary>
      <div class="row" style="gap:8px;margin-top:8px;flex-wrap:wrap">
        <div class="col field" style="min-width:200px;margin:0"><label>لینک یا شناسه‌ی پست اینستاگرام</label><input id="pfUrl" dir="ltr" placeholder="https://instagram.com/p/..." value="${esc(c.ext_url||c.ext_media_id||'')}"></div>
        <div class="col field" style="min-width:140px;margin:0"><label>کد لینک رهگیری</label><input id="pfCode" dir="ltr" placeholder="مثلاً sale24" value="${esc(c.link_code||'')}"></div>
      </div>
      <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap"><button class="btn btn-ghost btn-xs" onclick="perfLink()">ذخیره اتصال</button><button class="btn btn-ghost btn-xs" onclick="perfPick()">انتخاب از پست‌های پیج</button></div>
      <div class="muted" style="font-size:11px;margin-top:6px;line-height:1.8">کامنت‌ها و دایرکت‌های اتوماسیون کامنت همین پنل و کلیک لینک رهگیری همیشه خودکار شمرده می‌شوند. لایک و بازدید فقط اگر سرویس اینستاگرام این اعداد را بدهد. بازدید پست تلگرام، ریچ و فروش را دستی وارد کنید. محتوای منتشرشده روزی یک بار خودکار به‌روز می‌شود.</div>
      <div id="pfPosts"></div>
    </details>
    <div id="pfForm"></div>
    ${d.history.length?`<details style="margin-top:10px"><summary style="cursor:pointer;font-size:12.5px;font-weight:600">🕘 سابقه‌ی ثبت‌ها (${faNum(d.history.length)})</summary><div style="overflow-x:auto"><table class="perf-tbl"><thead><tr><th>زمان</th><th>منبع</th>${PERF_F.filter(f=>d.history.some(h=>h[f[0]]!=null)).map(f=>`<th>${f[1].split(' ').slice(1).join(' ')}</th>`).join('')}<th></th></tr></thead><tbody>${d.history.map(h=>`<tr><td style="white-space:nowrap">${toFa(h.captured_local||'').slice(0,16)}</td><td>${h.source==='auto'?'<span class="tag tag-info">خودکار</span>':`<span class="tag">${esc(h.by_name||'دستی')}</span>`}</td>${PERF_F.filter(f=>d.history.some(x=>x[f[0]]!=null)).map(f=>`<td>${perfNum(h[f[0]])}</td>`).join('')}<td><button class="btn btn-danger-ghost btn-xs" onclick="perfDel(${h.id})">✕</button></td></tr>${h.note?`<tr><td colspan="99" class="muted" style="font-size:11px">📝 ${esc(h.note)}</td></tr>`:''}`).join('')}</tbody></table></div></details>`:''}`;
}
function perfForm(){
  const f=document.getElementById('pfForm'); if(!f) return;
  if(f.innerHTML){ f.innerHTML=''; return; }
  const L=(PERF&&PERF.latest)||{};
  f.innerHTML=`<div class="perf-form"><div style="font-weight:600;font-size:12.5px;margin-bottom:8px">ثبت آمار <span class="muted" style="font-weight:400">(فقط هر عددی که دارید؛ عدد کل تا این لحظه، نه افزایش)</span></div>
    <div class="row" style="gap:8px;flex-wrap:wrap"><div class="field" style="margin:0;min-width:150px"><label>تاریخ</label><input id="pfDate" data-jdate placeholder="امروز"></div></div>
    <div class="perf-inputs">${PERF_F.map(x=>`<div class="field" style="margin:0"><label>${x[1]}</label><input class="mono" inputmode="numeric" data-pf="${x[0]}" placeholder="${L[x[0]]!=null?perfNum(L[x[0]]):''}"></div>`).join('')}</div>
    <div class="field" style="margin:8px 0 0"><label>یادداشت</label><input id="pfNote" placeholder="مثلاً بعد از ۴۸ ساعت · تبلیغ شده"></div>
    <div style="display:flex;gap:6px;margin-top:10px"><button class="btn btn-primary btn-sm" onclick="perfSave()">ثبت</button><button class="btn btn-ghost btn-sm" onclick="document.getElementById('pfForm').innerHTML=''">انصراف</button></div></div>`;
  setTimeout(()=>{ try{ jdpInit(); }catch(e){} },0);
}
async function perfSave(){
  const m={}; document.querySelectorAll('[data-pf]').forEach(i=>{ if(i.value.trim()!=='') m[i.dataset.pf]=i.value.trim(); });
  m.captured_at=getJDate('pfDate')||''; m.note=document.getElementById('pfNote').value;
  const d=await api('mktm_save',{content_id:CUR_CONTENT_ID, m});
  if(!d.ok){ toast(d.error||'ثبت نشد',false); return; }
  toast('آمار ثبت شد'); renderPerf(CUR_CONTENT_ID);
}
async function perfDel(id){ if(!confirm('این ثبت حذف شود؟')) return; const d=await api('mktm_delete',{id}); if(d.ok) renderPerf(CUR_CONTENT_ID); }
async function perfLink(){
  const v=document.getElementById('pfUrl').value.trim(); const code=document.getElementById('pfCode').value.trim();
  const isUrl=/^https?:/i.test(v);
  const d=await api('mktm_link',{content_id:CUR_CONTENT_ID, ext_url:isUrl?v:'', ext_media_id:isUrl?'':v, link_code:code});
  if(!d.ok){ toast(d.error||'ذخیره نشد',false); return; }
  toast('اتصال ذخیره شد'); perfCollect();
}
async function perfCollect(){
  const d=await api('mktm_collect',{content_id:CUR_CONTENT_ID});
  if(!d.ok){ toast(d.error||'آمار خودکاری پیدا نشد',false); renderPerf(CUR_CONTENT_ID); return; }
  toast(d.unchanged?'آمار تغییری نکرده بود':'آمار خودکار به‌روز شد'+(d.notes&&d.notes.length?' ('+d.notes[0]+')':'')); renderPerf(CUR_CONTENT_ID);
}
async function perfPick(){
  const box=document.getElementById('pfPosts'); box.innerHTML='<div class="muted" style="font-size:12px;margin-top:8px">در حال دریافت پست‌ها…</div>';
  const d=await api('mktm_posts',{content_id:CUR_CONTENT_ID});
  if(!d.ok){ box.innerHTML=`<div class="muted" style="font-size:12px;margin-top:8px">${esc(d.error)}</div>`; return; }
  box.innerHTML=`<div class="perf-posts">${d.posts.map(p=>`<button type="button" class="perf-post" onclick="perfUse('${esc(p.id)}','${esc(p.permalink||'')}')">${p.thumb?`<img src="${esc(p.thumb)}" alt="" loading="lazy">`:'<div class="ph">🖼</div>'}<span>${esc((p.caption||'').slice(0,40))||'بدون کپشن'}</span>${p.metrics&&p.metrics.likes!=null?`<small>❤️ ${perfNum(p.metrics.likes)} · 💬 ${perfNum(p.metrics.comments)}</small>`:''}</button>`).join('')||'<div class="muted">پستی نیست</div>'}</div>`;
}
async function perfUse(id, url){ document.getElementById('pfUrl').value=url||id; const d=await api('mktm_link',{content_id:CUR_CONTENT_ID, ext_media_id:id, ext_url:url, link_code:document.getElementById('pfCode').value.trim()}); if(d.ok) perfCollect(); }

/* گزارش عملکرد محتوا در تب گزارش‌ها */
let PERF_RANGE=30;
async function loadPerfReport(){
  const w=document.getElementById('perfReport'); if(!w) return;
  const to=new Date(); const from=new Date(Date.now()-(PERF_RANGE-1)*864e5); const iso=d=>d.toISOString().slice(0,10);
  const d=await api('mktm_report',{from:iso(from), to:iso(to)});
  if(!d.ok){ w.innerHTML=''; return; }
  const T=d.total;
  const card=(l,v,n)=>`<div class="stat"><div class="lab">${l}</div><div class="num">${v}</div>${n?`<div class="note">${n}</div>`:''}</div>`;
  const engT=T.likes+T.comments+T.shares+T.saves; const baseT=T.reach||T.views;
  w.innerHTML=`<div class="card" style="margin-bottom:16px"><div class="card-h"><span class="t">📊 عملکرد محتوای منتشرشده</span><span class="sub">بر اساس آخرین آمار ثبت‌شده‌ی هر محتوا</span>
      <span style="flex:1"></span><select class="chip-select" onchange="PERF_RANGE=+this.value;loadPerfReport()">${[[7,'۷ روز'],[30,'۳۰ روز'],[90,'۹۰ روز'],[365,'یک سال']].map(x=>`<option value="${x[0]}" ${x[0]===PERF_RANGE?'selected':''}>${x[1]}</option>`).join('')}</select></div>
    <div class="card-pad">
    ${!T.contents?'<div class="muted">در این بازه محتوای منتشرشده‌ای نیست.</div>':`
    <div class="grid g-4" style="margin-bottom:14px">${card('👁 بازدید',perfNum(T.views),faNum(T.measured)+' از '+faNum(T.contents)+' محتوا آمار دارند')}${card('❤️ لایک',perfNum(T.likes))}${card('💬 کامنت',perfNum(T.comments))}${card('📈 نرخ تعامل',baseT?faNum((engT*100/baseT).toFixed(2))+'٪':'—','(لایک+کامنت+اشتراک+ذخیره) ÷ '+(T.reach?'ریچ':'بازدید'))}
      ${card('↗️ اشتراک / 🔖 ذخیره',perfNum(T.shares)+' / '+perfNum(T.saves))}${card('🔗 کلیک لینک',perfNum(T.clicks))}${card('🎯 سرنخ / 🛒 فروش',perfNum(T.leads)+' / '+perfNum(T.sales))}${card('💰 درآمد',perfNum(T.revenue)+' <small class="muted">تومان</small>')}</div>
    <div style="font-weight:700;font-size:13px;margin:6px 0">به تفکیک پیج و کانال</div>
    <div style="overflow-x:auto"><table class="perf-tbl"><thead><tr><th>پیج / کانال</th><th>محتوا</th><th>بازدید</th><th>ریچ</th><th>لایک</th><th>کامنت</th><th>اشتراک</th><th>ذخیره</th><th>کلیک</th><th>سرنخ</th><th>نرخ تعامل</th></tr></thead><tbody>${d.groups.map(g=>`<tr><td><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${esc(g.color||'#999')};margin-left:6px"></span>${esc(g.key[0]==='c'?(CH_FA[g.channel]||g.name):g.name)}</td><td>${faNum(g.contents)}</td><td>${perfNum(g.views)}</td><td>${perfNum(g.reach)}</td><td>${perfNum(g.likes)}</td><td>${perfNum(g.comments)}</td><td>${perfNum(g.shares)}</td><td>${perfNum(g.saves)}</td><td>${perfNum(g.clicks)}</td><td>${perfNum(g.leads)}</td><td>${g.er!=null?faNum(g.er)+'٪':'—'}</td></tr>`).join('')}</tbody></table></div>
    <div style="font-weight:700;font-size:13px;margin:14px 0 6px">برترین محتواها</div>
    <div style="overflow-x:auto"><table class="perf-tbl"><thead><tr><th>محتوا</th><th>تاریخ</th><th>بازدید</th><th>لایک</th><th>کامنت</th><th>کلیک</th><th>نرخ تعامل</th><th></th></tr></thead><tbody>${d.items.slice(0,15).map(i=>`<tr><td><b>${esc(i.title)}</b><div class="muted" style="font-size:11px">${esc(i.page||CH_FA[i.channel]||i.channel||'')}${i.type?' · '+esc(i.type):''}</div></td><td style="white-space:nowrap">${faNum(toFa(String(i.date||'').slice(0,10)))}</td><td>${perfNum(i.metrics?.views)}</td><td>${perfNum(i.metrics?.likes)}</td><td>${perfNum(i.metrics?.comments)}</td><td>${perfNum(i.metrics?.clicks)}</td><td>${i.er!=null?faNum(i.er)+'٪':'—'}</td><td><button class="btn btn-ghost btn-xs" onclick="editContent(${i.id})">${i.metrics?'جزئیات':'ثبت آمار'}</button></td></tr>`).join('')}</tbody></table></div>`}
    </div></div>`;
}

/* ---------- گزارش‌ها ---------- */
const STATUS_FA={idea:'ایده',producing:'در تولید',review:'بازبینی',ready:'آماده',published:'منتشر شده'};
const STATUS_COL={idea:'var(--ink-faint)',producing:'var(--info)',review:'var(--warn)',ready:'var(--brand-2)',published:'var(--ok)'};
const DOW_FA={1:'یکشنبه',2:'دوشنبه',3:'سه‌شنبه',4:'چهارشنبه',5:'پنجشنبه',6:'جمعه',7:'شنبه'};
async function loadReports(){
  const d=await api('mkt_reports');
  if(!d.ok){ document.getElementById('reportsWrap').innerHTML='<div class="empty">خطا</div>'; return; }
  const r=d.r;
  if(!r.total){ document.getElementById('reportsWrap').innerHTML=emptyState('📈','هنوز داده‌ای برای گزارش نیست','محتوا اضافه کنید یا داده‌ی نمونه را وارد کنید'); return; }
  let h='';
  // کارت‌های خلاصه
  const pub=(r.by_status.find(x=>x.status==='published')||{}).c||0;
  const ready=(r.by_status.find(x=>x.status==='ready')||{}).c||0;
  h+=`<div class="grid g-4" style="margin-bottom:16px">
    <div class="stat"><div class="lab">📦 کل محتوا</div><div class="num">${faNum(r.total)}</div></div>
    <div class="stat"><div class="lab">✅ منتشر شده</div><div class="num">${faNum(pub)}</div><div class="note">${faNum(Math.round(pub/r.total*100))}٪ از کل</div></div>
    <div class="stat"><div class="lab">🗓️ آماده انتشار</div><div class="num">${faNum(ready)}</div></div>
    <div class="stat"><div class="lab">📡 کانال فعال</div><div class="num">${faNum(r.by_channel.length)}</div></div>
  </div>`;

  // روند انتشار (area)
  h+=`<div class="card" style="margin-bottom:16px"><div class="card-h"><span class="t">روند انتشار محتوا</span><span class="sub">تعداد در هر روز</span></div><div class="card-pad">${trendChart(r.trend)}</div></div>`;

  // دو ستون: کانال + وضعیت
  h+=`<div class="row" style="margin-bottom:16px">
    <div class="col card"><div class="card-h"><span class="t">عملکرد هر کانال</span><span class="sub">حجم محتوا</span></div><div class="card-pad">${channelBars(r.by_channel)}</div></div>
    <div class="col card" style="max-width:340px"><div class="card-h"><span class="t">وضعیت محتوا</span></div><div class="card-pad">${statusDonut(r.by_status)}</div></div>
  </div>`;

  // هیت‌مپ بهترین زمان
  h+=`<div class="card" style="margin-bottom:16px"><div class="card-h"><span class="t">بهترین زمان انتشار</span><span class="sub">روز × ساعت</span></div><div class="card-pad">${heatmap(r.heatmap, r.best_slot)}</div></div>`;

  // بار کاری تیم
  if(r.team && r.team.length){
    h+=`<div class="card" style="margin-bottom:16px"><div class="card-h"><span class="t">بار کاری و عملکرد تیم</span></div><div class="card-pad" style="padding-top:8px">${teamTable(r.team)}</div></div>`;
  }
  // پیشرفت اهداف
  if(r.goals && r.goals.length){
    h+=`<div class="card" style="margin-bottom:16px"><div class="card-h"><span class="t">پیشرفت اهداف</span></div><div class="card-pad">${goalBars(r.goals)}</div></div>`;
  }
  // کارت‌های بینش
  h+=insightCards(r);

  document.getElementById('reportsWrap').innerHTML='<div id="perfReport"></div>'+h;
  loadPerfReport();
}

function trendChart(trend){
  if(!trend.length) return '<div class="muted">داده‌ای نیست</div>';
  const W=900,H=180,pad=24;
  const max=Math.max(1,...trend.map(t=>+t.c));
  const n=trend.length;
  const x=i=> pad + (n<=1?0:(i*(W-2*pad)/(n-1)));
  const y=v=> H-pad - (v/max)*(H-2*pad);
  const pts=trend.map((t,i)=>`${x(i).toFixed(1)},${y(+t.c).toFixed(1)}`);
  const area=`M ${pad},${H-pad} L `+pts.join(' L ')+` L ${x(n-1)},${H-pad} Z`;
  const line='M '+pts.join(' L ');
  // برچسب چند تاریخ
  const ticks=[]; const step=Math.max(1,Math.floor(n/6));
  for(let i=0;i<n;i+=step){ ticks.push(`<text x="${x(i)}" y="${H-6}" font-size="9" fill="var(--ink-faint)" text-anchor="middle">${toFa(trend[i].d).slice(5)}</text>`); }
  return `<svg viewBox="0 0 ${W} ${H}" style="width:100%;height:${H}px;direction:ltr">
    <defs><linearGradient id="trendG" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--brand)" stop-opacity="0.28"/><stop offset="1" stop-color="var(--brand)" stop-opacity="0"/></linearGradient></defs>
    <path d="${area}" fill="url(#trendG)"/>
    <path d="${line}" fill="none" stroke="var(--brand)" stroke-width="2" stroke-linejoin="round"/>
    ${trend.map((t,i)=>`<circle cx="${x(i).toFixed(1)}" cy="${y(+t.c).toFixed(1)}" r="2.5" fill="var(--brand)"><title>${toFa(t.d)}: ${faNum(t.c)}</title></circle>`).join('')}
    ${ticks.join('')}
  </svg>`;
}

function channelBars(rows){
  if(!rows.length) return '<div class="muted">داده‌ای نیست</div>';
  const max=Math.max(1,...rows.map(r=>+r.c));
  return rows.map(r=>{
    const name=CH_FA[r.channel]||r.channel;
    return `<div style="display:flex;align-items:center;gap:10px;margin-bottom:9px">
      <div style="width:90px;font-size:12px;display:flex;align-items:center;gap:5px"><span class="cdot ch-${r.channel}"></span>${esc(name)}</div>
      <div style="flex:1;height:9px;background:var(--line-soft);border-radius:6px;overflow:hidden"><div style="height:100%;width:${Math.round(r.c/max*100)}%;background:linear-gradient(90deg,var(--brand),var(--brand-2));border-radius:6px"></div></div>
      <div style="width:36px;text-align:left;font-weight:700;font-size:12px">${faNum(r.c)}</div>
    </div>`;
  }).join('');
}

function statusDonut(rows){
  const total=rows.reduce((a,b)=>a+(+b.c),0)||1;
  const order=['idea','producing','review','ready','published'];
  const data=order.map(s=>({s,c:(rows.find(x=>x.status===s)||{}).c||0})).filter(x=>x.c>0);
  const R=58,C=2*Math.PI*R; let off=0;
  const segs=data.map(x=>{ const frac=x.c/total; const len=frac*C; const seg=`<circle cx="80" cy="80" r="${R}" fill="none" stroke="${STATUS_COL[x.s]}" stroke-width="20" stroke-dasharray="${len.toFixed(2)} ${(C-len).toFixed(2)}" stroke-dashoffset="${(-off).toFixed(2)}" transform="rotate(-90 80 80)"/>`; off+=len; return seg; }).join('');
  const legend=data.map(x=>`<div style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:5px"><span style="width:10px;height:10px;border-radius:3px;background:${STATUS_COL[x.s]}"></span>${STATUS_FA[x.s]} <span class="muted" style="margin-right:auto">${faNum(x.c)}</span></div>`).join('');
  return `<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
    <svg viewBox="0 0 160 160" style="width:140px;height:140px">${segs}<text x="80" y="76" text-anchor="middle" font-size="22" font-weight="800" fill="var(--ink)">${faNum(total)}</text><text x="80" y="96" text-anchor="middle" font-size="10" fill="var(--ink-faint)">محتوا</text></svg>
    <div style="flex:1;min-width:120px">${legend}</div>
  </div>`;
}

function heatmap(cells, best){
  // روزها: شنبه..جمعه (MySQL DAYOFWEEK: 7=شنبه,1=یکشنبه..6=جمعه)
  const dows=[7,1,2,3,4,5,6]; const dlabel={7:'ش',1:'ی',2:'د',3:'س',4:'چ',5:'پ',6:'ج'};
  const hours=[]; for(let hh=6;hh<=23;hh++) hours.push(hh);
  const map={}; let max=1;
  cells.forEach(c=>{ if(+c.hr<0)return; map[c.dow+'_'+c.hr]=+c.c; if(+c.c>max)max=+c.c; });
  let h='<div style="overflow-x:auto"><table style="border-collapse:separate;border-spacing:3px"><tr><td></td>';
  hours.forEach(hh=>h+=`<td style="font-size:9px;color:var(--ink-faint);text-align:center">${faNum(hh)}</td>`);
  h+='</tr>';
  dows.forEach(dw=>{
    h+=`<tr><td style="font-size:10px;color:var(--ink-soft);font-weight:700;padding-left:4px">${dlabel[dw]}</td>`;
    hours.forEach(hh=>{
      const v=map[dw+'_'+hh]||0; const op=v?(.18+.82*v/max):0;
      const isBest=best&&+best.dow===dw&&+best.hr===hh;
      h+=`<td title="${DOW_FA[dw]} ${faNum(hh)}:۰۰ — ${faNum(v)}" style="width:20px;height:20px;border-radius:5px;background:${v?`color-mix(in srgb,var(--brand) ${Math.round(op*100)}%,transparent)`:'var(--line-soft)'};${isBest?'outline:2px solid var(--gold);outline-offset:-1px':''}"></td>`;
    });
    h+='</tr>';
  });
  h+='</table></div>';
  if(best&&+best.hr>=0) h+=`<div class="note" style="margin-top:10px">⭐ بیشترین تمرکز انتشار: <b>${DOW_FA[+best.dow]||''} ساعت ${faNum(best.hr)}:۰۰</b> (${faNum(best.c)} محتوا)</div>`;
  else h+=`<div class="note" style="margin-top:10px">برای هیت‌مپ دقیق‌تر، ساعت انتشار محتواها را تکمیل کنید.</div>`;
  return h;
}

function teamTable(team){
  return `<table><thead><tr><th>عضو</th><th>کل</th><th>منتشر شده</th><th>باز</th><th style="width:40%">بار کاری</th></tr></thead><tbody>${
    team.map(m=>{
      const tot=+m.total||0, done=+m.done||0, open=+m.open_cnt||0;
      const over=open>8;
      return `<tr><td style="font-weight:600">${esc(m.full_name)}</td><td>${faNum(tot)}</td><td><span class="badge b-ok">${faNum(done)}</span></td><td><span class="badge ${over?'b-err':'b-mut'}">${faNum(open)}</span></td>
        <td><div style="display:flex;align-items:center;gap:8px"><div style="flex:1;height:7px;background:var(--line-soft);border-radius:6px;overflow:hidden;display:flex">
          <div style="height:100%;width:${tot?done/tot*100:0}%;background:var(--ok)"></div><div style="height:100%;width:${tot?open/tot*100:0}%;background:${over?'var(--err)':'var(--warn)'}"></div></div>
          ${over?'<span class="badge b-err" style="font-size:10px">بیش از ظرفیت</span>':''}</div></td></tr>`;
    }).join('') || '<tr><td colspan="5" class="empty">عضوی ندارد</td></tr>'
  }</tbody></table>`;
}

function goalBars(goals){
  return goals.map(g=>{
    const t=+g.target_value||0,c=+g.current_value||0,pct=t>0?Math.min(100,Math.round(c/t*100)):0;
    return `<div style="margin-bottom:12px"><div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:5px"><span style="font-weight:600">${esc(g.title)} <span class="chip">${PERIOD_FA[g.period]||g.period}</span></span><span class="muted">${faNum(c)} / ${faNum(t)} (${faNum(pct)}٪)</span></div>
      <div style="height:8px;background:var(--line-soft);border-radius:6px;overflow:hidden"><div style="height:100%;width:${pct}%;background:linear-gradient(90deg,var(--brand),var(--brand-2));border-radius:6px"></div></div></div>`;
  }).join('');
}

function insightCards(r){
  const ins=[];
  if(r.by_channel.length){ const top=r.by_channel[0]; ins.push(`بیشترین تمرکز محتوا روی <b>${CH_FA[top.channel]||top.channel}</b> است (${faNum(top.c)} مورد).`); }
  if(r.best_slot&&+r.best_slot.hr>=0){ ins.push(`بهترین زمان انتشار: <b>${DOW_FA[+r.best_slot.dow]||''} ${faNum(r.best_slot.hr)}:۰۰</b>.`); }
  const pub=(r.by_status.find(x=>x.status==='published')||{}).c||0; if(r.total){ ins.push(`<b>${faNum(Math.round(pub/r.total*100))}٪</b> از محتوا منتشر شده است.`); }
  const over=(r.team||[]).filter(m=>+m.open_cnt>8); if(over.length){ ins.push(`⚠️ بار کاری بالا: <b>${over.map(m=>esc(m.full_name)).join('، ')}</b>.`); }
  const noTime=(r.heatmap||[]).filter(h=>+h.hr<0).reduce((a,b)=>a+(+b.c),0); if(noTime>0){ ins.push(`${faNum(noTime)} محتوا بدون ساعت انتشار است — برای گزارش زمان دقیق‌تر تکمیل کنید.`); }
  if(!ins.length) return '';
  return `<div class="grid g-2">${ins.map(t=>`<div class="card card-pad" style="border-right:3px solid var(--brand)"><div style="font-size:13px;line-height:1.8">💡 ${t}</div></div>`).join('')}</div>`;
}

/* ---------- فیلدهای اختصاصی هر نوع محتوا ---------- */
// انواع فیلد: text, num, textarea, select(options), tags, list(تا n مورد)
const FIELDSPEC={
  'ریلز':[
    {k:'hook',l:'قلاب / هوک (۳ ثانیه اول)',t:'textarea'},
    {k:'scenario',l:'سناریو',t:'textarea'},
    {k:'duration',l:'مدت (ثانیه)',t:'num'},
    {k:'audio',l:'موسیقی / صدا',t:'text'},
    {k:'cover',l:'کاور',t:'text'},
    {k:'caption',l:'کپشن',t:'textarea'},
    {k:'hashtags',l:'هشتگ‌ها',t:'tags'},
    {k:'cta',l:'CTA',t:'text'},
    {k:'link',l:'لینک',t:'text'}
  ],
  'تریال':[
    {k:'hook',l:'قلاب / هوک تست',t:'textarea'},
    {k:'scenario',l:'سناریو',t:'textarea'},
    {k:'duration',l:'مدت (ثانیه)',t:'num'},
    {k:'trial_account',l:'اکانت تریال (تست)',t:'text'},
    {k:'origin_ref',l:'مرجع/ریلز اصلی مرتبط',t:'text'},
    {k:'audio',l:'موسیقی / صدا',t:'text'},
    {k:'caption',l:'کپشن',t:'textarea'},
    {k:'hashtags',l:'هشتگ‌ها',t:'tags'},
    {k:'target_views',l:'بازدید هدف برای تأیید',t:'num'},
    {k:'result_views',l:'بازدید واقعی',t:'num'},
    {k:'verdict',l:'نتیجه',t:'select',o:['—','در حال تست','موفق (به اصلی تبدیل شود)','ناموفق']}
  ],
  'استوری':[
    {k:'frames',l:'متن فریم‌ها (تا ۸)',t:'list',n:8},
    {k:'interaction',l:'نوع تعامل',t:'select',o:['—','نظرسنجی','سؤال','اسلایدر','کوییز']},
    {k:'sticker',l:'استیکر',t:'text'},
    {k:'link',l:'لینک / سوایپ',t:'text'},
    {k:'duration',l:'مدت',t:'text'}
  ],
  'اسلایدی':[
    {k:'slides',l:'تعداد اسلاید',t:'num'},
    {k:'slide_texts',l:'متن اسلایدها',t:'list',n:10},
    {k:'design',l:'طراحی',t:'text'},
    {k:'caption',l:'کپشن',t:'textarea'},
    {k:'hashtags',l:'هشتگ‌ها',t:'tags'}
  ],
  'پست':[
    {k:'image',l:'تصویر',t:'text'},
    {k:'caption',l:'کپشن',t:'textarea'},
    {k:'hashtags',l:'هشتگ‌ها',t:'tags'},
    {k:'bio_link',l:'لینک بیو',t:'text'}
  ],
  'نظرسنجی':[
    {k:'question',l:'سؤال',t:'text'},
    {k:'options',l:'گزینه‌ها',t:'list',n:4}
  ],
  'مقاله':[
    {k:'slug',l:'اسلاگ (URL)',t:'text'},
    {k:'main_kw',l:'کلمه‌ی کلیدی اصلی',t:'text'},
    {k:'sub_kw',l:'کلمات کلیدی فرعی',t:'tags'},
    {k:'meta',l:'متا دیسکریپشن',t:'textarea'},
    {k:'words',l:'تعداد کلمات هدف',t:'num'},
    {k:'author',l:'نویسنده',t:'text'},
    {k:'seo_status',l:'وضعیت سئو',t:'select',o:['—','پیش‌نویس','بهینه','منتشر']},
    {k:'featured_img',l:'تصویر شاخص',t:'text'},
    {k:'internal_links',l:'لینک‌سازی داخلی',t:'tags'}
  ],
  'لندینگ':[
    {k:'cta_goal',l:'هدف تبدیل (CTA)',t:'text'},
    {k:'hero',l:'هیرو',t:'textarea'},
    {k:'sections',l:'بخش‌ها',t:'list',n:8},
    {k:'form',l:'فرم',t:'text'},
    {k:'ab',l:'نسخه‌ی A/B',t:'text'},
    {k:'campaign_link',l:'لینک کمپین',t:'text'}
  ],
  'تسک سئو':[
    {k:'keyword',l:'کلمه‌ی کلیدی',t:'text'},
    {k:'volume',l:'حجم جستجو',t:'num'},
    {k:'competition',l:'رقابت',t:'select',o:['—','کم','متوسط','زیاد']},
    {k:'target_page',l:'صفحه‌ی هدف',t:'text'},
    {k:'action',l:'اقدام',t:'select',o:['—','محتوای جدید','بهینه‌سازی','بک‌لینک']}
  ],
  'ویدیو':[
    {k:'description',l:'توضیحات',t:'textarea'},
    {k:'tags',l:'تگ‌ها',t:'tags'},
    {k:'thumb',l:'تامبنیل',t:'text'},
    {k:'duration',l:'مدت',t:'text'},
    {k:'subtitle',l:'زیرنویس',t:'select',o:['—','دارد','ندارد']},
    {k:'end_cta',l:'کارت پایانی / CTA',t:'text'}
  ],
  'شورت':[
    {k:'hook',l:'قلاب',t:'textarea'},
    {k:'duration',l:'مدت (ثانیه)',t:'num'},
    {k:'cta',l:'CTA',t:'text'}
  ],
  'توییت':[
    {k:'text',l:'متن (≤۲۸۰)',t:'textarea'},
    {k:'media',l:'رسانه',t:'text'},
    {k:'hashtags',l:'هشتگ‌ها',t:'tags'}
  ],
  'رشته':[
    {k:'tweets',l:'توییت‌های رشته',t:'list',n:10},
    {k:'media',l:'رسانه',t:'text'}
  ],
  'قالب':[
    {k:'template',l:'قالب تأییدشده',t:'text'},
    {k:'vars',l:'متغیرها',t:'tags'},
    {k:'cta',l:'CTA',t:'text'}
  ],
  'تبلیغ':[
    {k:'campaign_type',l:'نوع کمپین',t:'text'},
    {k:'audience',l:'مخاطب / کلمه‌ی کلیدی',t:'text'},
    {k:'budget',l:'بودجه‌ی روزانه',t:'num'},
    {k:'ad_text',l:'متن آگهی',t:'textarea'},
    {k:'cta',l:'CTA',t:'text'},
    {k:'landing',l:'صفحه‌ی فرود',t:'text'},
    {k:'period',l:'بازه',t:'text'}
  ],
  'آگهی':[
    {k:'ad_text',l:'متن آگهی',t:'textarea'},
    {k:'budget',l:'بودجه',t:'num'},
    {k:'category',l:'دسته',t:'text'},
    {k:'period',l:'بازه',t:'text'}
  ],
  'رپورتاژ':[
    {k:'media',l:'رسانه / مجری',t:'text'},
    {k:'cost',l:'هزینه',t:'num'},
    {k:'period',l:'بازه',t:'text'},
    {k:'message',l:'پیام',t:'textarea'},
    {k:'metric',l:'معیار سنجش',t:'text'}
  ],
  'کمپین':[
    {k:'text',l:'متن',t:'textarea'},
    {k:'line',l:'خط ارسال',t:'text'},
    {k:'segment',l:'لیست / سگمنت',t:'text'},
    {k:'short_link',l:'لینک کوتاه',t:'text'}
  ],
  'خبرنامه':[
    {k:'subject',l:'موضوع',t:'text'},
    {k:'preview',l:'پیش‌نمایش',t:'text'},
    {k:'template',l:'قالب',t:'text'},
    {k:'segment',l:'سگمنت',t:'text'},
    {k:'ab_subject',l:'A/B موضوع',t:'text'}
  ],
  'تردز':[
    {k:'text',l:'متن تردز',t:'textarea'},
    {k:'thread',l:'ادامه‌ی رشته',t:'list',n:8},
    {k:'cta',l:'CTA',t:'text'}
  ],
  'لایو':[
    {k:'topic',l:'موضوع لایو',t:'text'},
    {k:'guests',l:'مهمان‌ها',t:'tags'},
    {k:'agenda',l:'سرفصل‌ها',t:'list',n:10},
    {k:'duration',l:'مدت (دقیقه)',t:'num'},
    {k:'promo',l:'تیزر/اعلام قبلی',t:'text'}
  ],
  'ریلز کولَب':[
    {k:'hook',l:'قلاب',t:'textarea'},
    {k:'scenario',l:'سناریو',t:'textarea'},
    {k:'collab_partner',l:'پیج همکار (Collab)',t:'text'},
    {k:'duration',l:'مدت (ثانیه)',t:'num'},
    {k:'caption',l:'کپشن',t:'textarea'},
    {k:'hashtags',l:'هشتگ‌ها',t:'tags'}
  ],
  'گایدلاین':[
    {k:'items',l:'موارد گاید',t:'list',n:12},
    {k:'cover',l:'کاور',t:'text'},
    {k:'desc',l:'توضیح',t:'textarea'}
  ],
  'استاتوس':[
    {k:'text',l:'متن',t:'textarea'},
    {k:'media',l:'رسانه',t:'text'},
    {k:'duration',l:'مدت نمایش',t:'text'}
  ],
  'بیلبورد':[
    {k:'location',l:'موقعیت/بزرگراه',t:'text'},
    {k:'size',l:'ابعاد',t:'text'},
    {k:'design',l:'طرح',t:'text'},
    {k:'cost',l:'هزینه',t:'num'},
    {k:'period',l:'بازه نمایش',t:'text'},
    {k:'message',l:'پیام اصلی',t:'textarea'}
  ],
  'تبلیغ اینفلوئنسر':[
    {k:'influencer',l:'اینفلوئنسر / پیج',t:'text'},
    {k:'followers',l:'تعداد فالوور',t:'num'},
    {k:'format',l:'نوع (استوری/ریلز/پست)',t:'select',o:['—','استوری','ریلز','پست','کپشن']},
    {k:'cost',l:'هزینه',t:'num'},
    {k:'discount_code',l:'کد تخفیف اختصاصی',t:'text'},
    {k:'message',l:'پیام/بریف',t:'textarea'},
    {k:'metric',l:'معیار سنجش',t:'text'}
  ],
  'نوتیفیکیشن':[
    {k:'title',l:'عنوان',t:'text'},
    {k:'body',l:'متن',t:'textarea'},
    {k:'icon',l:'آیکن',t:'text'},
    {k:'link',l:'لینک',t:'text'},
    {k:'segment',l:'سگمنت',t:'text'}
  ]
};
function renderDynFields(values){
  values=values||{};
  const type=document.getElementById('cType').value;
  const spec=FIELDSPEC[type];
  const box=document.getElementById('cDynFields');
  if(!spec){ box.innerHTML=''; return; }
  let h=`<div style="background:var(--surface-2);border:1px solid var(--line);border-radius:11px;padding:12px;margin-bottom:14px">
    <div style="font-size:11px;font-weight:700;color:var(--ink-faint);margin-bottom:10px;letter-spacing:.3px">فیلدهای اختصاصی «${esc(type)}»</div>`;
  spec.forEach(f=>{
    const v=values[f.k];
    h+=`<div class="field" style="margin-bottom:10px"><label>${esc(f.l)}</label>`;
    if(f.t==='textarea'){ h+=`<textarea data-dk="${f.k}" style="min-height:60px">${esc(v||'')}</textarea>`; }
    else if(f.t==='num'){ h+=`<input data-dk="${f.k}" type="number" value="${v!=null?esc(String(v)):''}">`; }
    else if(f.t==='select'){ h+=`<select data-dk="${f.k}">${(f.o||[]).map(o=>`<option ${String(v)===o?'selected':''}>${esc(o)}</option>`).join('')}</select>`; }
    else if(f.t==='tags'){ const tv=Array.isArray(v)?v.join('، '):(v||''); h+=`<input data-dk="${f.k}" data-tags="1" value="${esc(tv)}" placeholder="با ویرگول جدا کنید">`; }
    else if(f.t==='list'){ const arr=Array.isArray(v)?v:(v?[v]:['']); const n=f.n||6;
      h+=`<div data-list="${f.k}" data-n="${n}">`;
      (arr.length?arr:['']).forEach((iv,idx)=>{ h+=`<input data-dlk="${f.k}" value="${esc(iv||'')}" placeholder="مورد ${faNum(idx+1)}" style="margin-bottom:5px">`; });
      h+=`</div><button type="button" class="btn btn-ghost btn-xs" onclick="addListItem('${f.k}')">+ افزودن مورد</button>`;
    }
    else { h+=`<input data-dk="${f.k}" value="${v!=null?esc(String(v)):''}">`; }
    h+=`</div>`;
  });
  h+=`</div>`;
  box.innerHTML=h;
}
function addListItem(k){
  const wrap=document.querySelector(`[data-list="${k}"]`); if(!wrap)return;
  const n=+wrap.dataset.n||6; const cur=wrap.querySelectorAll('input').length;
  if(cur>=n){ toast('به حداکثر تعداد رسید',false); return; }
  const inp=document.createElement('input'); inp.setAttribute('data-dlk',k); inp.placeholder='مورد '+faNum(cur+1); inp.style.marginBottom='5px';
  wrap.appendChild(inp);
}
function collectDynFields(){
  const out={};
  document.querySelectorAll('#cDynFields [data-dk]').forEach(el=>{
    let val=el.value.trim();
    if(el.dataset.tags){ val=val.split(/[،,]/).map(x=>x.trim()).filter(Boolean); }
    if(el.type==='number' && val!=='') val=enNum(val);
    if(val!=='' && !(Array.isArray(val)&&!val.length)) out[el.dataset.dk]=val;
  });
  document.querySelectorAll('#cDynFields [data-list]').forEach(wrap=>{
    const k=wrap.dataset.list;
    const arr=[...wrap.querySelectorAll('[data-dlk]')].map(i=>i.value.trim()).filter(Boolean);
    if(arr.length) out[k]=arr;
  });
  return out;
}

/* ---------- مالی و بودجه ---------- */
let FIN_Y=0, FIN_M=0;
function toman(n){ n=Math.round(+n||0); return faNum(n.toLocaleString('en-US'))+' ت'; }
async function loadFinance(){
  if(!FIN_Y){ const t=jToday(); FIN_Y=t[0]; FIN_M=t[1]; }
  const period=FIN_Y+'-'+String(FIN_M).padStart(2,'0');
  const g1=j2g(FIN_Y,FIN_M,1), g2=j2g(FIN_Y,FIN_M,jlen(FIN_Y,FIN_M));
  const pad=n=>String(n).padStart(2,'0');
  const gf=g1[0]+'-'+pad(g1[1])+'-'+pad(g1[2]), gt=g2[0]+'-'+pad(g2[1])+'-'+pad(g2[2]);
  document.getElementById('finLabel').textContent=JMON[FIN_M-1]+' '+faNum(FIN_Y);
  const d=await api('mkt_finance',{period,greg_from:gf,greg_to:gt});
  if(!d.ok){ document.getElementById('finWrap').innerHTML='<div class="empty">خطا</div>'; return; }
  const f=d.f;
  const tb=f.total_budget||0, ts=f.total_spent||0, tp=f.total_planned||0;
  const remain=tb-ts, pct=tb>0?Math.round(ts/tb*100):0;
  let h='';
  h+=`<div class="grid g-4" style="margin-bottom:16px">
    <div class="stat"><div class="lab">💼 کل بودجه</div><div class="num">${toman(tb)}</div></div>
    <div class="stat"><div class="lab">💸 خرج‌کرد واقعی</div><div class="num">${toman(ts)}</div><div class="note">${faNum(pct)}٪ از بودجه</div></div>
    <div class="stat"><div class="lab">${remain<0?'🔴 کسری':'🟢 باقی‌مانده'}</div><div class="num" style="color:${remain<0?'var(--err)':'var(--ok)'}">${toman(Math.abs(remain))}</div></div>
    <div class="stat"><div class="lab">📋 بودجه‌ی برنامه‌ریزی‌شده</div><div class="num">${toman(tp)}</div><div class="note">جمع بودجه‌ی محتواها</div></div>
  </div>`;
  if(tb>0){
    const barPct=Math.min(100,pct);
    h+=`<div class="card card-pad" style="margin-bottom:16px">
      <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px"><span style="font-weight:700">مصرف کل بودجه</span><span class="${ts>tb?'':''}" style="color:${ts>tb?'var(--err)':'var(--ink-soft)'}">${toman(ts)} از ${toman(tb)}</span></div>
      <div style="height:12px;background:var(--line-soft);border-radius:7px;overflow:hidden"><div style="height:100%;width:${barPct}%;background:${ts>tb?'var(--err)':'linear-gradient(90deg,var(--brand),var(--brand-2))'};border-radius:7px"></div></div>
      ${ts>tb?`<div class="note" style="color:var(--err);margin-top:6px">⚠️ هزینه از بودجه عبور کرده است (${faNum(pct)}٪)</div>`:''}
    </div>`;
  }
  // جدول هر کانال
  if(f.rows && f.rows.length){
    h+=`<div class="card"><div class="card-h"><span class="t">بودجه در برابر هزینه — هر کانال</span></div><div class="card-pad" style="padding-top:8px">
      <table><thead><tr><th>کانال</th><th>بودجه</th><th>هزینه</th><th>محتوا</th><th style="width:32%">مصرف</th><th></th></tr></thead><tbody>`;
    f.rows.forEach(r=>{
      const name=CH_FA[r.channel]||r.channel;
      const pp=r.pct;
      h+=`<tr>
        <td><span class="chip"><span class="cdot ch-${r.channel}"></span>${esc(name)}</span></td>
        <td class="mono">${r.budget?toman(r.budget):'—'}</td>
        <td class="mono" style="${r.over?'color:var(--err);font-weight:700':''}">${toman(r.spent)}</td>
        <td>${faNum(r.count)}</td>
        <td>${r.budget?`<div style="display:flex;align-items:center;gap:8px"><div style="flex:1;height:8px;background:var(--line-soft);border-radius:6px;overflow:hidden"><div style="height:100%;width:${Math.min(100,pp||0)}%;background:${r.over?'var(--err)':'linear-gradient(90deg,var(--brand),var(--brand-2))'};border-radius:6px"></div></div><span style="font-size:11px;min-width:34px">${pp!=null?faNum(pp)+'٪':'—'}</span></div>`:'<span class="muted">بدون بودجه</span>'}</td>
        <td style="text-align:left;white-space:nowrap"><button class="btn btn-ghost btn-xs" onclick="editBudgetFor('${r.channel}')">بودجه</button></td>
      </tr>`;
    });
    h+='</tbody></table></div></div>';
  } else {
    h+=emptyState('💰','برای این دوره بودجه یا هزینه‌ای ثبت نشده','با دکمه‌ی «تعیین بودجه» شروع کنید یا روی محتواها هزینه ثبت کنید');
  }
  document.getElementById('finWrap').innerHTML=h;
}
function finNav(dir){ if(dir===0){ const t=jToday(); FIN_Y=t[0]; FIN_M=t[1]; } else { FIN_M+=dir; if(FIN_M<1){FIN_M=12;FIN_Y--;} if(FIN_M>12){FIN_M=1;FIN_Y++;} } loadFinance(); }
function curPeriod(){ if(!FIN_Y){ const t=jToday(); FIN_Y=t[0]; FIN_M=t[1]; } return FIN_Y+'-'+String(FIN_M).padStart(2,'0'); }
function newBudget(){ document.getElementById('bId').value=''; document.getElementById('bPeriod').value=curPeriod(); document.getElementById('bChannel').value=''; document.getElementById('bAmount').value=''; document.getElementById('bNote').value=''; document.getElementById('bModal').style.display='flex'; }
function editBudgetFor(ch){ newBudget(); document.getElementById('bChannel').value=ch; }
function closeBudget(){ document.getElementById('bModal').style.display='none'; }
async function saveBudget2(){
  const budget={ id:document.getElementById('bId').value||'', period:enNum(document.getElementById('bPeriod').value.trim()), channel:document.getElementById('bChannel').value, amount:enNum(document.getElementById('bAmount').value.trim())||0, note:document.getElementById('bNote').value.trim() };
  if(!/^\d{4}-\d{2}$/.test(budget.period)){ toast('دوره نامعتبر است (مثل 1405-04)',false); return; }
  const d=await api('mkt_budget_save',{budget});
  if(d.ok){ toast('بودجه ذخیره شد'); closeBudget(); loadFinance(); }
  else toast(d.error||'خطا',false);
}

/* ---------- تسک‌های من ---------- */
function gToday(){ const t=jToday(); const g=j2g(t[0],t[1],t[2]); const pad=n=>String(n).padStart(2,'0'); return g[0]+'-'+pad(g[1])+'-'+pad(g[2]); }
async function loadMine(){
  const today=gToday();
  const d=await api('mkt_my_tasks',{today});
  const box=document.getElementById('mineWrap');
  if(!d.ok){ box.innerHTML='<div class="empty">خطا</div>'; return; }
  if(!d.linked){
    box.innerHTML=emptyState('🔗','حساب شما به عضوی از تیم لینک نشده',
      'برای دیدن تسک‌های شخصی، در تب «تیم و چارت» یک عضو را به کاربر اعلان‌بان شما لینک کنید (فیلد «کاربر اعلان‌بان»).');
    return;
  }
  const steps=d.steps||[], content=d.content||[], st=d.stats||{open:0,done:0,overdue:0};
  // دسته‌بندی زمانی مراحل
  const buckets={overdue:[],today:[],week:[],month:[],later:[],nodate:[],done:[]};
  const now=new Date(today+'T00:00:00');
  const weekEnd=new Date(now); weekEnd.setDate(weekEnd.getDate()+7);
  const monthEnd=new Date(now); monthEnd.setDate(monthEnd.getDate()+30);
  steps.forEach(s=>{
    if(s.done_at){ buckets.done.push(s); return; }
    if(!s.due_date){ buckets.nodate.push(s); return; }
    const dd=new Date(s.due_date+'T00:00:00');
    if(dd<now) buckets.overdue.push(s);
    else if(dd.getTime()===now.getTime()) buckets.today.push(s);
    else if(dd<=weekEnd) buckets.week.push(s);
    else if(dd<=monthEnd) buckets.month.push(s);
    else buckets.later.push(s);
  });
  let h='';
  h+=`<div class="grid g-3" style="margin-bottom:16px">
    <div class="stat"><div class="lab">📋 باز</div><div class="num">${faNum(st.open)}</div></div>
    <div class="stat"><div class="lab">✅ انجام‌شده</div><div class="num">${faNum(st.done)}</div></div>
    <div class="stat"><div class="lab" style="color:${st.overdue?'var(--err)':''}">⏰ معوق</div><div class="num" style="color:${st.overdue?'var(--err)':''}">${faNum(st.overdue)}</div></div>
  </div>`;
  const sec=(title,arr,cls)=>{
    if(!arr.length) return '';
    return `<div class="card" style="margin-bottom:12px"><div class="card-h"><span class="t">${title}</span><span class="sub">${faNum(arr.length)} مورد</span></div><div class="card-pad" style="padding-top:6px">${arr.map(stepRow).join('')}</div></div>`;
  };
  function stepRow(s){
    const done=!!s.done_at;
    return `<div style="display:flex;align-items:center;gap:9px;padding:8px 0;border-bottom:1px solid var(--line-soft)">
      <input type="checkbox" ${done?'checked':''} onchange="mineToggle(${s.id},this.checked)" style="width:18px;height:18px;flex-shrink:0;cursor:pointer">
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;${done?'text-decoration:line-through;color:var(--ink-faint)':''}">${esc(s.step_name)}</div>
        <div class="muted" style="font-size:11px">${s.channel?`<span class="cdot ch-${s.channel}"></span> `:''}${esc(s.content_title||'')}${s.content_type?' · '+esc(s.content_type):''}</div>
      </div>
      ${s.due_date?`<span class="badge ${(!done&&s.due_date<today)?'b-err':'b-mut'}" style="font-size:11px">${toFa(s.due_date)}</span>`:''}
      <button class="btn btn-ghost btn-xs" onclick="openContentById(${s.content_id})">باز کردن</button>
    </div>`;
  }
  h+=sec('⏰ معوق', buckets.overdue);
  h+=sec('📅 امروز', buckets.today);
  h+=sec('🗓️ این هفته', buckets.week);
  h+=sec('📆 این ماه', buckets.month);
  h+=sec('⏳ بعداً', buckets.later);
  h+=sec('📭 بدون تاریخ', buckets.nodate);
  // محتوای من (مسئول اصلی)
  if(content.length){
    h+=`<div class="card" style="margin-bottom:12px"><div class="card-h"><span class="t">محتوای من (مسئول اصلی)</span><span class="sub">${faNum(content.length)} مورد</span></div><div class="card-pad" style="padding-top:6px">${
      content.map(c=>`<div style="display:flex;align-items:center;gap:9px;padding:8px 0;border-bottom:1px solid var(--line-soft)">
        ${c.channel?`<span class="cdot ch-${c.channel}"></span>`:''}
        <div style="flex:1;min-width:0"><div style="font-size:13px">${esc(c.title)}</div><div class="muted" style="font-size:11px">${c.content_type?esc(c.content_type):''}${c.plan_date?' · '+toFa(c.plan_date):''}</div></div>
        <span class="badge ${{published:'b-ok',ready:'b-info',review:'b-warn',producing:'b-info',idea:'b-mut'}[c.status]||'b-mut'}" style="font-size:11px">${({idea:'ایده',producing:'تولید',review:'بازبینی',ready:'آماده',published:'منتشر'})[c.status]||c.status}</span>
        <button class="btn btn-ghost btn-xs" onclick="openContentById(${c.id})">باز کردن</button>
      </div>`).join('')
    }</div></div>`;
  }
  if(!steps.length && !content.length){ h+=emptyState('🎉','تسکی برای شما ثبت نشده','وقتی در مراحل یا محتوایی مسئول شوید، اینجا دیده می‌شود'); }
  box.innerHTML=h;
}
async function mineToggle(id,done){ await api('mkt_step_toggle',{id,done:done?1:0}); loadMine(); }
function openContentById(id){
  if((window._mktContent||[]).find(x=>x.id==id)){ editContent(id); }
  else { api('mkt_content_list').then(d=>{ window._mktContent=d.content||[]; editContent(id); }); }
}

mktBoot();
