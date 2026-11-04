<?php require __DIR__ . '/../carousel-editor.php'; ?>
// pages/templates.js.php
let TPLS = [];
let TPL_IMG_PATH = null;

async function uploadTplImage(){
  const f = document.getElementById('tplImage').files[0];
  if(!f) return;
  const st = document.getElementById('tplImgStatus');
  st.textContent = 'در حال آپلود…';
  const fd = new FormData(); fd.append('image', f);
  const d = await api('upload_image', fd, true);
  if(d.ok){ TPL_IMG_PATH = d.path; st.textContent = '✅ عکس آماده شد'; }
  else { TPL_IMG_PATH = null; st.textContent = '⚠️ '+(d.error||'خطا'); }
}

async function loadTpls(){
  const d = await api('templates_list');
  TPLS = d.templates||[];
  const tb = document.getElementById('tplRows');
  if(!TPLS.length){ tb.innerHTML='<tr><td colspan=3 class=empty>الگویی نیست</td></tr>'; return; }
  tb.innerHTML = TPLS.map(t=>`
    <tr>
      <td>${esc(t.name)}</td>
      <td class="muted">${esc((t.body||'').slice(0,40))}${(t.body||'').length>40?'…':''}</td>
      <td style="text-align:left;white-space:nowrap">
        <button class="btn btn-ghost btn-sm" onclick="editTpl(${t.id})">ویرایش</button>
        <button class="btn btn-danger-ghost btn-sm" onclick="delTpl(${t.id})">حذف</button>
      </td>
    </tr>`).join('');
}

async function saveTpl(){
  const name = document.getElementById('tplName').value.trim();
  const body = document.getElementById('tplBody').value.trim();
  const buttons_json = document.getElementById('tplButtons').value.trim();
  const id = document.getElementById('tplId').value;
  const cards = CarEd.get('tplCarousel');
  if(!name || (!body && !cards.length)){ toast('نام و (متن یا کارت) لازم است', false); return; }
  const d = await api('template_save', {id, name, body, buttons_json, cards, media_path: TPL_IMG_PATH || ''});
  if(d.ok){ toast('ذخیره شد'); resetTpl(); loadTpls(); }
  else toast(d.error||'خطا', false);
}

function editTpl(id){
  const t = TPLS.find(x=>x.id==id); if(!t) return;
  document.getElementById('tplId').value = t.id;
  document.getElementById('tplName').value = t.name;
  document.getElementById('tplBody').value = t.body||'';
  document.getElementById('tplButtons').value = t.buttons_json||'';
  { let cc=[]; try{ cc=JSON.parse(t.cards_json||'[]'); }catch(e){} CarEd.mount('tplCarousel', cc); }
  document.getElementById('tplFormTitle').textContent = 'ویرایش الگو';
  document.getElementById('tplCancel').style.display = 'inline-flex';
}

function resetTpl(){
  document.getElementById('tplId').value='';
  document.getElementById('tplName').value='';
  document.getElementById('tplBody').value='';
  document.getElementById('tplButtons').value='';
  CarEd.mount('tplCarousel', []);
  document.getElementById('tplFormTitle').textContent='الگوی جدید';
  document.getElementById('tplCancel').style.display='none';
}

async function delTpl(id){
  if(!confirm('این الگو حذف شود؟')) return;
  const d = await api('template_delete', {id});
  if(d.ok){ toast('حذف شد'); loadTpls(); }
}

CarEd.mount('tplCarousel', []);
loadTpls();
