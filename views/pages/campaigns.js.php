// pages/campaigns.js.php
let CMP_TPLS=[], STEPS=[];

async function initCmp(){
  const t=await api('templates_list'); CMP_TPLS=t.templates||[];
  const g=await api('groups_list');
  document.getElementById('cmpGroup').innerHTML='<option value="">همه مشتریان</option>'+(g.groups||[]).map(x=>`<option value="${x.id}">${esc(x.name)} (${x.member_count})</option>`).join('');
  STEPS=[{text:'',template_id:'',delay_hours:0,only_nonresponders:0}]; // مرحله‌ی اول
  renderSteps();
  loadCmp();
}

function tplOptions(sel){
  return '<option value="">— متن دستی —</option>'+CMP_TPLS.map(t=>`<option value="${t.id}" ${sel==t.id?'selected':''}>${esc(t.name)}</option>`).join('');
}
function renderSteps(){
  document.getElementById('cmpSteps').innerHTML=STEPS.map((s,i)=>`
    <div class="step-box">
      <div class="num">مرحله ${i+1} ${i===0?'<span class="muted" style="font-weight:400">(به همه)</span>':''}</div>
      ${i>0?`<button class="btn btn-danger-ghost btn-xs" onclick="STEPS.splice(${i},1);renderSteps()" style="position:absolute;left:8px;top:8px">×</button>`:''}
      <div class="field"><label>الگو</label><select onchange="STEPS[${i}].template_id=this.value;renderSteps()">${tplOptions(s.template_id)}</select></div>
      ${!s.template_id?`<div class="field"><label>متن</label><textarea oninput="STEPS[${i}].text=this.value" placeholder="متن مرحله…">${esc(s.text||'')}</textarea></div>`:''}
      ${i>0?`
        <div class="field"><label>فاصله از مرحله‌ی قبل (ساعت)</label><input type="number" value="${s.delay_hours||72}" oninput="STEPS[${i}].delay_hours=this.value" min="1"></div>
        <label style="display:flex;align-items:center;gap:7px;font-size:12.5px"><input type="checkbox" ${s.only_nonresponders?'checked':''} onchange="STEPS[${i}].only_nonresponders=this.checked?1:0" style="width:auto"> فقط برای کسانی که جواب نداده‌اند</label>
      `:''}
    </div>`).join('');
}
function addStep(){ STEPS.push({text:'',template_id:'',delay_hours:72,only_nonresponders:1}); renderSteps(); }

async function loadCmp(){
  const d=await api('campaigns_list');
  document.getElementById('cmpRows').innerHTML=(d.campaigns||[]).map(c=>{
    const badge=c.status==='sent'?'b-ok':(c.status==='sending'?'b-warn':'b-mut');
    const slbl={sent:'ارسال‌شده',sending:'در حال ارسال',draft:'پیش‌نویس',scheduled:'زمان‌بندی‌شده'}[c.status]||c.status;
    return `<tr style="cursor:pointer" onclick="openCmp(${c.id})"><td>${esc(c.name)}</td><td>${faNum(c.step_count)} مرحله</td>
    <td><span class="badge ${badge}"><span class="b-dot"></span>${slbl}</span></td>
    <td style="text-align:left;white-space:nowrap" onclick="event.stopPropagation()">
      ${c.status==='draft'?`<button class="btn btn-primary btn-sm" onclick="startCmp(${c.id})">شروع</button>`:''}
      <button class="btn btn-danger-ghost btn-sm" onclick="delCmp(${c.id})">حذف</button></td></tr>`;
  }).join('')||'<tr><td colspan="4" class="empty">کمپینی نیست</td></tr>';
}
async function createCmp(){
  const name=document.getElementById('cmpName').value.trim();
  if(!name){toast('نام لازم است',false);return;}
  // اعتبارسنجی: هر مرحله باید متن یا الگو داشته باشد
  for(let i=0;i<STEPS.length;i++){
    if(!STEPS[i].template_id && !(STEPS[i].text||'').trim()){toast(`مرحله ${i+1}: متن یا الگو لازم است`,false);return;}
  }
  const d=await api('campaign_create',{
    name, group_id:document.getElementById('cmpGroup').value,
    channels:document.getElementById('cmpChannels').value.trim(), steps:STEPS
  });
  if(d.ok){toast('کمپین ساخته شد');document.getElementById('cmpName').value='';STEPS=[{text:'',template_id:'',delay_hours:0,only_nonresponders:0}];renderSteps();loadCmp();}
  else toast(d.error||'خطا',false);
}
async function startCmp(id){
  if(!confirm('کمپین شروع شود؟ مرحله‌ی اول همین حالا ارسال می‌شود.'))return;
  toast('در حال ارسال مرحله‌ی اول…');
  const d=await api('campaign_start',{id});
  if(d.ok){toast(`مرحله‌ی اول: موفق ${d.sent}، ناموفق ${d.failed}`);loadCmp();}
  else toast(d.error||'خطا',false);
}
async function delCmp(id){if(!confirm('حذف شود؟'))return;await api('campaign_delete',{id});loadCmp();}

async function openCmp(id){
  document.getElementById('cmpBackdrop').classList.add('show');
  document.getElementById('cmpDrawer').classList.add('open');
  document.getElementById('cmpBody').innerHTML='<div class="empty">…</div>';
  const d=await api('campaign_get',{id});
  if(!d.ok){document.getElementById('cmpBody').innerHTML='<div class="empty">خطا</div>';return;}
  const c=d.campaign;
  const stats=c.step_stats||[];
  document.getElementById('cmpBody').innerHTML=`
    <div style="font-weight:600;margin-bottom:4px">${esc(c.name)}</div>
    <div class="muted" style="font-size:12px;margin-bottom:16px">وضعیت: ${c.status} · ${(c.steps||[]).length} مرحله</div>
    ${(c.steps||[]).map((s,i)=>{
      const st=stats.find(x=>x.order==s.step_order)||{sent:0,responded:0};
      const rate=st.sent>0?Math.round(st.responded/st.sent*100):0;
      return `<div class="step-box">
        <div class="num">مرحله ${s.step_order} ${s.only_nonresponders==1&&s.step_order>1?'<span class="tag tag-mute" style="font-weight:400">پیگیری بی‌پاسخ‌ها</span>':''}</div>
        <div style="font-size:12.5px;margin-bottom:6px">${esc((s.text||'(الگو)').slice(0,80))}</div>
        ${s.step_order>1?`<div class="muted" style="font-size:11px">${s.delay_hours} ساعت بعد از مرحله‌ی قبل</div>`:''}
        <div style="display:flex;gap:10px;margin-top:8px">
          <span class="tag tag-accent">ارسال: ${st.sent}</span>
          <span class="tag tag-ok">پاسخ: ${st.responded} (${rate}%)</span>
        </div>
      </div>`;
    }).join('')}`;
}
function closeCmp(){document.getElementById('cmpBackdrop').classList.remove('show');document.getElementById('cmpDrawer').classList.remove('open');}

initCmp();
