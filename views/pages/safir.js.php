// pages/safir.js.php — ارسال با شماره (صفیر + پیامک)
let PH_IMG_PATH = null;
const SM_HINTS = {kavenegar:'(API Key کاوه‌نگار)', smsir:'(API Key پنل sms.ir)'};

function onProviderChange(){
  const p = document.getElementById('smProvider').value;
  const isMeli = (p === 'melipayamak');
  document.getElementById('smKeyField').style.display  = isMeli ? 'none' : 'block';
  document.getElementById('smUserField').style.display = isMeli ? 'block' : 'none';
  document.getElementById('smPassField').style.display = isMeli ? 'block' : 'none';
  document.getElementById('smKeyHint').textContent = SM_HINTS[p] || '';
}

async function loadSafir(){
  const d = await api('safir_get');
  if(d.ok){
    if(document.getElementById('sfBotId')) document.getElementById('sfBotId').value = d.bot_id||'';
    const st=document.getElementById('sfKeyStatus');
    if(st) st.textContent = d.has_key ? ('✅ کلید ذخیره شده: '+(d.access_key_masked||'')) : '⚠️ کلیدی ذخیره نشده';
  }
}
async function loadSms(){
  const d = await api('sms_get');
  if(!d.ok) return;
  if(document.getElementById('smProvider')){
    document.getElementById('smProvider').value = d.provider||'kavenegar';
    document.getElementById('smSender').value = d.sender||'';
    document.getElementById('smKeyStatus').textContent = d.has_key ? '✅ کلید ذخیره شده' : '⚠️ کلیدی ذخیره نشده';
    if(d.meli_user) document.getElementById('smUser').value = d.meli_user;
    onProviderChange();
  }
}

async function saveSafir(){
  const d = await api('safir_save', {access_key:document.getElementById('sfKey').value.trim(), bot_id:document.getElementById('sfBotId').value.trim()});
  if(d.ok){ toast('صفیر ذخیره شد'); document.getElementById('sfKey').value=''; loadSafir(); }
  else toast(d.error||'خطا', false);
}
async function saveSms(){
  const provider = document.getElementById('smProvider').value;
  let apiKey;
  if(provider === 'melipayamak'){
    const u = document.getElementById('smUser').value.trim();
    const p = document.getElementById('smPass').value.trim();
    if(!u || !p){ toast('نام کاربری و رمز ملی‌پیامک لازم است', false); return; }
    apiKey = u + ':' + p;   // ترکیب برای ذخیره
  } else {
    apiKey = document.getElementById('smKey').value.trim();
  }
  const d = await api('sms_save', {provider, api_key:apiKey, sender:document.getElementById('smSender').value.trim()});
  if(d.ok){
    toast('پیامک ذخیره شد');
    document.getElementById('smKey').value='';
    document.getElementById('smPass').value='';
    loadSms();
  } else toast(d.error||'خطا', false);
}

async function uploadPhImage(){
  const f=document.getElementById('phImage').files[0]; if(!f) return;
  const st=document.getElementById('phImgStatus'); st.textContent='در حال آپلود…';
  const fd=new FormData(); fd.append('image',f);
  const d=await api('upload_image', fd, true);
  if(d.ok){ PH_IMG_PATH=d.path; st.textContent='✅ عکس آماده شد'; }
  else { PH_IMG_PATH=null; st.textContent='⚠️ '+(d.error||'خطا'); }
}

async function loadPhonesFile(){
  const f=document.getElementById('phFile').files[0]; if(!f) return;
  const st=document.getElementById('phFileStatus');
  st.textContent='در حال خواندن فایل…';
  const fd=new FormData(); fd.append('file',f);
  const d=await api('phones_from_file', fd, true);
  if(d.ok){
    const ta=document.getElementById('phPhones');
    const existing=ta.value.trim();
    ta.value=(existing? existing+'\n':'')+d.phones.join('\n');
    st.innerHTML=`✅ ${d.valid_count} شماره‌ی معتبر افزوده شد`+(d.invalid_count?` · ${d.invalid_count} نامعتبر رد شد`:'');
  } else {
    st.textContent='⚠️ '+(d.error||'خطا');
  }
}

async function loadPhoneGroups(){
  const g=await api('groups_list');
  if(document.getElementById('phGroup'))
    document.getElementById('phGroup').innerHTML='<option value="">گروه…</option>'+(g.groups||[]).map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join('');
  const t=await api('tags_list');
  if(document.getElementById('phTag'))
    document.getElementById('phTag').innerHTML='<option value="">برچسب…</option>'+(t.tags||[]).map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join('');
}
async function loadPhonesFromGroup(){
  const gid=document.getElementById('phGroup').value;
  const tid=document.getElementById('phTag').value;
  if(!gid && !tid){ toast('گروه یا برچسب را انتخاب کنید', false); return; }
  const st=document.getElementById('phGroupStatus');
  st.textContent='در حال گرفتن شماره‌ها…';
  const d=await api('phones_from_group', {group_id:gid, tag_id:tid});
  if(d.ok){
    const ta=document.getElementById('phPhones');
    const existing=ta.value.trim();
    ta.value=(existing? existing+'\n':'')+d.phones.join('\n');
    st.innerHTML=`✅ ${d.count} شماره از گروه/برچسب افزوده شد`;
  } else st.textContent='⚠️ '+(d.error||'خطا');
}

async function sendPhone(){
  const text=document.getElementById('phText').value.trim();
  const phones=document.getElementById('phPhones').value.trim();
  if(!text && !PH_IMG_PATH){ toast('متن یا عکس لازم است', false); return; }
  if(!phones){ toast('شماره‌ای وارد نشده', false); return; }
  if(!confirm('ارسال انبوه شروع شود؟')) return;

  const btn=document.getElementById('phSendBtn');
  btn.disabled=true; btn.textContent='در حال ارسال…';
  document.getElementById('phResult').textContent='ارسال در جریان است…';

  const d=await api('phone_send', {
    text, phones,
    mode: document.getElementById('phMode').value,
    buttons_json: document.getElementById('phButtons').value.trim(),
    image_path: PH_IMG_PATH || '',
  });

  btn.disabled=false; btn.textContent='ارسال انبوه';
  if(d.ok){
    document.getElementById('phResult').innerHTML =
      `<span class="tag tag-ok">پایان</span> کل: ${d.total} · صفیر: ${d.safir_sent} · پیامک: ${d.sms_sent} · ناموفق: ${d.failed} · نامعتبر: ${d.invalid}`;
    toast('ارسال انجام شد');
  } else {
    document.getElementById('phResult').innerHTML = `<span class="tag tag-warn">خطا</span> ${esc(d.error||'')}`;
    toast(d.error||'خطا', false);
  }
}

loadSafir(); loadSms(); loadPhoneGroups(); checkVoice(); loadTrackLinks('phLinkPick');

async function checkVoice(){
  const d=await api('voice_available');
  if(d.ok && d.available){
    const b=document.getElementById('phVoiceBtn'); if(b) b.style.display='block';
    const n=document.getElementById('phVoiceNote'); if(n) n.style.display='block';
    const w=document.getElementById('phVoiceFileWrap'); if(w) w.style.display='block';
  }
}
let VOICE_FILE_ID=0;
async function uploadVoiceFile(){
  const f=document.getElementById('phVfFile').files[0]; if(!f)return;
  const st=document.getElementById('phVfStatus'); st.textContent='در حال آپلود فایل صوتی…';
  const fd=new FormData(); fd.append('file',f); fd.append('title',document.getElementById('phVfTitle').value.trim());
  const d=await api('voice_upload_file', fd, true);
  if(d.ok && d.voice_file_id){
    VOICE_FILE_ID=d.voice_file_id;
    st.innerHTML=`<span class="tag tag-ok">آپلود شد</span> شناسه: ${d.voice_file_id}`;
    document.getElementById('phVfSendBtn').style.display='block';
  } else { st.innerHTML=`<span class="tag tag-warn">خطا</span> ${esc(d.error||'')}`; }
}
async function sendBulkVoice(){
  if(!VOICE_FILE_ID){ toast('اول فایل را آپلود کنید',false); return; }
  const phones=document.getElementById('phPhones').value.trim();
  if(!phones){ toast('شماره‌ای وارد نشده',false); return; }
  if(!confirm('تماس انبوه با فایل صوتی گرفته شود؟'))return;
  const r=document.getElementById('phResult'); r.textContent='در حال ثبت تماس انبوه…';
  const d=await api('voice_bulk_file', {voice_file_id:VOICE_FILE_ID, phones, title:document.getElementById('phVfTitle').value.trim()});
  if(d.ok){ r.innerHTML='<span class="tag tag-ok">ثبت شد</span> تماس انبوه در سیستم ملی‌پیامک ثبت شد'; toast('ثبت شد'); }
  else { r.innerHTML=`<span class="tag tag-warn">خطا</span> ${esc(d.error||'')}`; toast(d.error||'خطا',false); }
}
async function sendVoice(){
  const text=document.getElementById('phText')?.value.trim() || '';
  const phones=document.getElementById('phPhones').value.trim();
  if(!text){ toast('متن گفتار لازم است', false); return; }
  if(!phones){ toast('شماره‌ای وارد نشده', false); return; }
  if(!confirm('تماس صوتی با این شماره‌ها گرفته شود؟ متن نوشته‌شده خوانده می‌شود.')) return;
  const btn=document.getElementById('phVoiceBtn');
  btn.disabled=true; btn.textContent='در حال تماس…';
  const r=document.getElementById('phResult'); r.textContent='در حال برقراری تماس‌ها…';
  const d=await api('voice_call', {text, phones});
  btn.disabled=false; btn.textContent='📞 تماس گویا (متن بالا خوانده می‌شود)';
  if(d.ok){
    r.innerHTML=`<span class="tag tag-ok">پایان</span> کل: ${d.total} · موفق: ${d.sent} · ناموفق: ${d.failed} · <a href="index.php?page=sendlog" style="color:var(--accent-ink)">جزئیات</a>`;
    toast('تماس‌ها انجام شد');
  } else {
    r.innerHTML=`<span class="tag tag-warn">خطا</span> ${esc(d.error||'')}`;
    toast(d.error||'خطا', false);
  }
}
