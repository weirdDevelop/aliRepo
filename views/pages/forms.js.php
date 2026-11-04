// pages/forms.js.php
let FRM_QS=[], FRM_IMG='', FRM_IMG_KEEP='';

async function uploadFrmImg(){
  const f=document.getElementById('frmImg').files[0]; if(!f)return;
  const st=document.getElementById('frmImgStatus'); st.textContent='در حال آپلود…';
  const fd=new FormData(); fd.append('image',f);
  const d=await api('upload_image', fd, true);
  if(d.ok){ FRM_IMG=d.path; st.innerHTML='<span class="tag tag-ok">عکس بارگذاری شد</span>'; }
  else st.textContent='خطا: '+(d.error||'');
}

async function loadFrm(){
  const d=await api('forms_list');
  const tb=document.getElementById('frmRows');
  if(!d.ok||!d.forms.length){ tb.innerHTML=`<tr><td colspan=4>${emptyState('📋','هنوز فرمی نیست','یک فرم با چند سؤال بساز و یک کلمه‌ی کلیدی برایش بگذار')}</td></tr>`; return; }
  tb.innerHTML=d.forms.map(f=>`<tr>
    <td style="cursor:pointer" onclick="openResp(${f.id})">${esc(f.name)} ${f.is_active==1?'':'<span class="tag tag-mute">غیرفعال</span>'}</td>
    <td>${f.trigger_keyword?`<span class="mono">${esc(f.trigger_keyword)}</span>`:'—'}</td>
    <td><span class="tag tag-accent">${f.response_count}</span></td>
    <td style="text-align:left;white-space:nowrap">
      <button class="btn btn-ghost btn-sm" onclick='editFrm(${JSON.stringify(f)})'>ویرایش</button>
      <button class="btn btn-danger-ghost btn-sm" onclick="delFrm(${f.id})">حذف</button></td>
  </tr>`).join('');
}

function renderQs(){
  document.getElementById('frmQs').innerHTML=FRM_QS.map((q,i)=>`
    <div style="display:flex;gap:5px;margin-bottom:5px;align-items:center">
      <span class="muted" style="font-size:12px">${i+1}.</span>
      <input value="${esc(q)}" oninput="FRM_QS[${i}]=this.value" placeholder="متن سؤال" style="flex:1">
      <button class="btn btn-danger-ghost btn-xs" onclick="FRM_QS.splice(${i},1);renderQs()">×</button>
    </div>`).join('') || '<span class="muted" style="font-size:12px">سؤالی نیست</span>';
}
function addQ(){ FRM_QS.push(''); renderQs(); }

function resetFrm(){
  document.getElementById('frmId').value='';
  document.getElementById('frmName').value='';
  document.getElementById('frmTrigger').value='';
  document.getElementById('frmFinish').value='با تشکر، پاسخ شما ثبت شد.';
  document.getElementById('frmTitle').textContent='فرم جدید';
  FRM_QS=['']; FRM_IMG=''; FRM_IMG_KEEP='';
  if(document.getElementById('frmImgStatus')) document.getElementById('frmImgStatus').textContent='';
  renderQs();
}
function editFrm(f){
  document.getElementById('frmId').value=f.id;
  document.getElementById('frmName').value=f.name;
  document.getElementById('frmTrigger').value=f.trigger_keyword||'';
  document.getElementById('frmFinish').value=f.finish_text||'';
  document.getElementById('frmTitle').textContent='ویرایش فرم';
  FRM_IMG=''; FRM_IMG_KEEP=f.finish_image||'';
  if(document.getElementById('frmImgStatus')) document.getElementById('frmImgStatus').textContent=f.finish_image?'عکس فعلی حفظ می‌شود (برای تغییر، عکس جدید بدهید)':'';
  try{ FRM_QS=(JSON.parse(f.questions_json||'[]')).map(q=>q.q||''); }catch(e){ FRM_QS=['']; }
  if(!FRM_QS.length) FRM_QS=[''];
  renderQs();
}
async function saveFrm(){
  const name=document.getElementById('frmName').value.trim();
  if(!name){toast('نام فرم لازم است',false);return;}
  const qs=FRM_QS.filter(q=>(q||'').trim()).map(q=>({q}));
  if(!qs.length){toast('حداقل یک سؤال لازم است',false);return;}
  const d=await api('form_save',{
    id:document.getElementById('frmId').value||0,
    name, trigger_keyword:document.getElementById('frmTrigger').value.trim(),
    questions:qs, finish_text:document.getElementById('frmFinish').value,
    finish_image:FRM_IMG||'', finish_image_keep:FRM_IMG_KEEP||''
  });
  if(d.ok){toast('ذخیره شد');resetFrm();loadFrm();}
  else toast(d.error||'خطا',false);
}
async function delFrm(id){if(!confirm('حذف شود؟ پاسخ‌ها هم پاک می‌شوند.'))return;await api('form_delete',{id});loadFrm();}

async function openResp(id){
  document.getElementById('frmBackdrop').classList.add('show');
  document.getElementById('frmDrawer').classList.add('open');
  document.getElementById('frmRespBody').innerHTML='<div class="empty">…</div>';
  const d=await api('form_responses',{id});
  if(!d.ok){document.getElementById('frmRespBody').innerHTML='<div class="empty">خطا</div>';return;}
  const resps=d.responses||[];
  document.getElementById('frmRespBody').innerHTML=`
    <div style="font-weight:600;margin-bottom:4px">${esc(d.form?.name||'')}</div>
    <div class="muted" style="font-size:12px;margin-bottom:14px">${resps.length} پاسخ</div>
    ${resps.length?resps.map(r=>{
      let ans=[];try{ans=JSON.parse(r.answers_json||'[]');}catch(e){}
      return `<div class="resp-card">
        <div style="font-size:11.5px;color:var(--ink-faint);margin-bottom:6px">${esc(r.full_name||'ناشناس')} · ${toFa(r.created_at)}</div>
        ${ans.map(a=>`<div class="qa"><b>${esc(a.q)}</b><br>${esc(a.a)}</div>`).join('')}
      </div>`;
    }).join(''):'<div class="muted">هنوز پاسخی نیست</div>'}`;
}
function closeFrmResp(){document.getElementById('frmBackdrop').classList.remove('show');document.getElementById('frmDrawer').classList.remove('open');}

resetFrm();
loadFrm();
