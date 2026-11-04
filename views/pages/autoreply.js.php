<?php require __DIR__ . '/../carousel-editor.php'; ?>
// pages/autoreply.js.php
let RULES = [];
const ARCHN = {instagram:'اینستاگرام', bale:'بله', telegram:'تلگرام', roubika:'روبیکا', webchat:'گفتگوی سایت'};
let AR_PAGES = [];
async function loadARPages(){
  const d = await api('ig_page_list');
  AR_PAGES = (d.ok && d.pages) || [];
  document.getElementById('arPage').innerHTML = '<option value="">همه‌ی پیج‌ها</option>' + AR_PAGES.map(p=>`<option value="${p.id}">${esc(p.label||('پیج #'+p.id))}</option>`).join('');
}
function onARChannelChange(){
  document.getElementById('arPageField').style.display = document.getElementById('arChannel').value === 'instagram' && AR_PAGES.length ? '' : 'none';
  if (document.getElementById('arChannel').value !== 'instagram') document.getElementById('arPage').value = '';
}
const ARTYPE = {exact:'دقیق', contains:'شامل', starts_with:'شروع با', regex:'Regex', on_contact:'📱 اشتراک شماره'};

async function loadAR(){
  const d = await api('autoreply_list');
  RULES = d.rules||[];
  const tb = document.getElementById('arRows');
  if(!RULES.length){ tb.innerHTML='<tr><td colspan=4 class=empty>قانونی نیست</td></tr>'; return; }
  tb.innerHTML = RULES.map(r=>`
    <tr>
      <td>${r.trigger_type==='on_contact'?'<span class="muted">— رویدادی —</span>':esc(r.trigger_keyword)} ${r.is_active==1?'':'<span class="tag tag-mute">غیرفعال</span>'}</td>
      <td>${r.channel?(ARCHN[r.channel]||r.channel):'<span class="muted">همه</span>'}${r.page_label?` <span class="tag" style="font-size:10px;border:1px solid ${esc(r.page_color||'var(--line)')}">پیج: ${esc(r.page_label)}</span>`:''}</td>
      <td><span class="tag tag-accent">${ARTYPE[r.trigger_type]||r.trigger_type}</span></td>
      <td style="text-align:left;white-space:nowrap">
        <button class="btn btn-ghost btn-sm" onclick="editAR(${r.id})">ویرایش</button>
        <button class="btn btn-danger-ghost btn-sm" onclick="delAR(${r.id})">حذف</button>
      </td>
    </tr>`).join('');
}

function onARTypeChange(){
  const isContact = document.getElementById('arType').value === 'on_contact';
  document.getElementById('arKeywordField').style.display = isContact ? 'none' : '';
  document.getElementById('arContactNote').style.display = isContact ? '' : 'none';
}

async function saveAR(){
  const data = {
    id: document.getElementById('arId').value,
    channel: document.getElementById('arChannel').value,
    ig_account_id: document.getElementById('arChannel').value === 'instagram' ? document.getElementById('arPage').value : '',
    trigger_keyword: document.getElementById('arKeyword').value.trim(),
    trigger_type: document.getElementById('arType').value,
    text: document.getElementById('arText').value.trim(),
    buttons_json: document.getElementById('arButtons').value.trim(),
    priority: document.getElementById('arPriority').value,
    request_contact: document.getElementById('arContact').checked ? 1 : 0,
    cards: CarEd.get('arCarousel'),
    site_scope: (document.getElementById('arSite')||{}).value || '',
    is_active: 1,
  };
  const isContact = data.trigger_type === 'on_contact';
  if(isContact){
    if(!data.text && !data.cards.length){ toast('متن یا کارت پاسخ لازم است', false); return; }
  } else {
    if(!data.trigger_keyword || (!data.text && !data.cards.length)){ toast('کلمه‌ی محرک و (متن یا کارت) لازم است', false); return; }
  }
  const d = await api('autoreply_save', data);
  if(d.ok){ toast('ذخیره شد'); resetAR(); loadAR(); }
  else toast(d.error||'خطا', false);
}

function editAR(id){
  const r = RULES.find(x=>x.id==id); if(!r) return;
  document.getElementById('arId').value = r.id;
  document.getElementById('arChannel').value = r.channel||'';
  onARChannelChange();
  document.getElementById('arPage').value = r.ig_account_id||'';
  document.getElementById('arKeyword').value = r.trigger_keyword;
  document.getElementById('arType').value = r.trigger_type;
  onARTypeChange();
  let resp={}; try{ resp=JSON.parse(r.response_json||'{}'); }catch(e){}
  document.getElementById('arText').value = resp.text||'';
  document.getElementById('arButtons').value = resp.buttons?JSON.stringify(resp.buttons):'';
  CarEd.mount('arCarousel', resp.carousel||[]);
  if(document.getElementById('arSite')) document.getElementById('arSite').value = r.site_id ? 'site' : 'all';
  document.getElementById('arPriority').value = r.priority||0;
  document.getElementById('arContact').checked = !!resp.request_contact;
  document.getElementById('arTitle').textContent='ویرایش قانون';
  document.getElementById('arCancel').style.display='inline-flex';
}

function resetAR(){
  ['arId','arKeyword','arText','arButtons'].forEach(i=>document.getElementById(i).value='');
  document.getElementById('arChannel').value='';
  document.getElementById('arPage').value=''; onARChannelChange();
  document.getElementById('arType').value='exact';
  onARTypeChange();
  CarEd.mount('arCarousel', []);
  if(document.getElementById('arSite')) document.getElementById('arSite').value = 'site';
  document.getElementById('arPriority').value='0';
  document.getElementById('arContact').checked=false;
  document.getElementById('arTitle').textContent='قانون جدید';
  document.getElementById('arCancel').style.display='none';
}

async function delAR(id){
  if(!confirm('این قانون حذف شود؟')) return;
  const d = await api('autoreply_delete', {id});
  if(d.ok){ toast('حذف شد'); loadAR(); }
}

CarEd.mount('arCarousel', []);
loadARPages().then(onARChannelChange);
loadAR();
