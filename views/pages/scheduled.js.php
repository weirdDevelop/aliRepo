// pages/scheduled.js.php
let SCH_TPLS=[], SCH_IMG='';
const SCH_TYPE_NAME={chat:'پیام‌رسان',phone:'شماره',voice:'تماس',email:'ایمیل'};

async function initSch(){
  // الگوها
  const t=await api('templates_list'); SCH_TPLS=t.templates||[];
  document.getElementById('schTpl').innerHTML='<option value="">— متن دستی —</option>'+SCH_TPLS.map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join('');
  // گروه و برچسب
  const g=await api('groups_list');
  document.getElementById('schGroup').innerHTML='<option value="">همه</option>'+(g.groups||[]).map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join('');
  const tg=await api('tags_list');
  document.getElementById('schTag').innerHTML='<option value="">همه</option>'+(tg.tags||[]).map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join('');
  onSchType();
  loadSch();
}
function onSchType(){
  const t=document.getElementById('schType').value;
  document.getElementById('schPhones').style.display=(t==='phone'||t==='voice')?'block':'none';
  document.getElementById('schChWrap').style.display=(t==='chat')?'block':'none';
  document.getElementById('schPhoneModeWrap').style.display=(t==='phone')?'block':'none';
  // عکس فقط برای chat و phone(صفیر) معنی دارد
  document.getElementById('schImgWrap').style.display=(t==='chat'||t==='phone')?'block':'none';
}
function onSchTpl(){
  const id=document.getElementById('schTpl').value;
  const t=SCH_TPLS.find(x=>x.id==id);
  if(t) document.getElementById('schText').value=t.body||'';
}
async function uploadSchImg(){
  const f=document.getElementById('schImg').files[0]; if(!f)return;
  const st=document.getElementById('schImgStatus'); st.textContent='در حال آپلود…';
  const fd=new FormData(); fd.append('image',f);
  const d=await api('upload_image', fd, true);
  if(d.ok){ SCH_IMG=d.path; st.innerHTML='<span class="tag tag-ok">عکس بارگذاری شد</span>'; }
  else { st.textContent='خطا: '+(d.error||''); }
}
async function loadSch(){
  const d=await api('scheduled_list');
  if(d.ok&&d.scheduled.some(x=>['pending','queued','sending'].includes(x.status))){ clearTimeout(window.SCH_T); window.SCH_T=setTimeout(loadSch,20000); }
  const tb=document.getElementById('schRows');
  if(!d.ok||!d.scheduled.length){ tb.innerHTML=`<tr><td colspan=5>${emptyState('⏰','زمان‌بندی‌ای نیست','یک ارسال خودکار برای زمان دلخواه بساز')}</td></tr>`; return; }
  const stMap={pending:'<span class="tag tag-warn">در انتظار زمان</span>',sending:'<span class="tag tag-accent">در حال ارسال</span>',queued:'<span class="tag tag-accent">در صف ارسال</span>',sent:'<span class="tag tag-ok">ارسال شد</span>',failed:'<span class="tag tag-err">ناموفق</span>',cancelled:'<span class="tag tag-mute">لغو شد</span>'};
  tb.innerHTML=d.scheduled.map(s=>`<tr>
    <td>${esc(s.name)}</td>
    <td><span class="tag tag-mute">${SCH_TYPE_NAME[s.send_type]||'پیام‌رسان'}</span></td>
    <td style="white-space:nowrap">${toFa(s.run_at_local||s.run_at)}</td>
    <td>${stMap[s.status]||esc(s.status)}${s.status==='sent'?`<div class="muted" style="font-size:11px">موفق ${toFa(s.sent_count)}، ناموفق ${toFa(s.failed_count)}</div>`:''}${s.status==='queued'?`<div class="muted" style="font-size:11px">${toFa(s.q_processed||0)} از ${toFa(s.q_total||s.sent_count)} گیرنده</div>`:''}${s.status==='failed'&&s.error?`<div style="font-size:11px;color:var(--err)">${esc(s.error)}</div>`:''}</td>
    <td style="text-align:left;white-space:nowrap">
      ${s.status==='pending'?`<button class="btn btn-ghost btn-sm" onclick="cancelSch(${s.id})">لغو</button>`:''}
      <button class="btn btn-danger-ghost btn-sm" onclick="delSch(${s.id})">حذف</button></td>
  </tr>`).join('');
}
async function createSch(){
  const name=document.getElementById('schName').value.trim();
  const time=document.getElementById('schTime').value;
  if(!name){toast('نام لازم است',false);return;}
  if(!time){toast('زمان ارسال لازم است',false);return;}
  const type=document.getElementById('schType').value;
  // datetime-local → "YYYY-MM-DDTHH:MM" → "YYYY-MM-DD HH:MM:00"
  const runAt=time.replace('T',' ')+':00';
  const payload={
    name, send_type:type, run_at:runAt,
    template_id:document.getElementById('schTpl').value||0,
    text:document.getElementById('schText').value,
    image_path:SCH_IMG||'',
    group_id:document.getElementById('schGroup').value||'',
    tag_id:document.getElementById('schTag').value||'',
    channel_priority:document.getElementById('schChannels').value.trim(),
    phones_text:document.getElementById('schPhonesText').value.trim(),
    phone_mode:document.getElementById('schPhoneMode').value,
  };
  const d=await api('scheduled_create', payload);
  if(d.ok){
    toast('زمان‌بندی شد'); document.getElementById('schResult').innerHTML='<span class="tag tag-ok">ثبت شد</span>';
    document.getElementById('schName').value=''; document.getElementById('schText').value=''; document.getElementById('schPhonesText').value=''; SCH_IMG=''; document.getElementById('schImgStatus').textContent='';
    loadSch();
  } else { document.getElementById('schResult').innerHTML='<span class="tag tag-warn">'+esc(d.error||'خطا')+'</span>'; }
}
async function cancelSch(id){ await api('scheduled_cancel',{id}); loadSch(); }
async function delSch(id){ if(!confirm('حذف شود؟'))return; await api('scheduled_delete',{id}); loadSch(); }
initSch();
