// pages/profile.js.php
const PG_NAMES={persons:'بانک مشتری',send:'ارسال',safir:'ارسال با شماره',templates:'الگوها',autoreply:'پاسخ خودکار',botbuilder:'بات‌ساز',forms:'فرم',groups:'گروه/برچسب',campaigns:'کمپین',scheduled:'زمان‌بندی',links:'لینک',sendlog:'تاریخچه',stats:'آمار'};

async function loadProfile(){
  const d=await api('my_profile');
  if(!d.ok)return;
  document.getElementById('pfUsername').value=d.username;
  let access;
  if(d.unrestricted){ access='<span class="tag tag-ok">دسترسی کامل (مدیر)</span>'; }
  else {
    const pages=(d.pages||[]).length?d.pages.map(p=>PG_NAMES[p]||p).join('، '):'همه بخش‌ها';
    const groups=(d.groups||[]).length?`${d.groups.length} گروه محدود`:'همه مشتریان';
    access=`<div style="font-size:12.5px"><b>بخش‌ها:</b> ${esc(pages)}</div><div style="font-size:12.5px"><b>مشتریان:</b> ${esc(groups)}</div>`;
  }
  document.getElementById('profInfo').innerHTML=`
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
      <span style="width:48px;height:48px;border-radius:50%;background:var(--accent-soft);color:var(--accent-ink);display:grid;place-items:center;font-size:20px;font-weight:700">${esc(d.username[0]||'?')}</span>
      <div><div style="font-weight:600;font-size:15px">${esc(d.username)}</div><div class="muted" style="font-size:12px">${d.role==='admin'?'مدیر':'اپراتور'}</div></div>
    </div>
    <div class="pf-stat" style="display:flex;gap:12px;margin-bottom:16px">
      <div style="flex:1;background:var(--bg);border-radius:8px;padding:12px;text-align:center"><div style="font-size:20px;font-weight:700">${d.send_count}</div><div style="font-size:11px">ارسال انجام‌شده</div></div>
    </div>
    <div class="pf-label" style="font-size:11.5px;color:var(--ink-faint);margin-bottom:6px">سطح دسترسی</div>
    ${access}
    <div class="muted" style="font-size:11.5px;margin-top:12px">عضو از: ${toFa(d.created_at)}</div>`;
}
async function changePassword(){
  const cur=document.getElementById('pfCur').value, nw=document.getElementById('pfNew').value;
  const m=document.getElementById('pfMsg');
  if(!cur||!nw){m.innerHTML='<span class="tag tag-warn">هر دو فیلد لازم است</span>';return;}
  const d=await api('my_password_change',{current:cur,new:nw});
  if(d.ok){m.innerHTML='<span class="tag tag-ok">رمز تغییر کرد</span>';document.getElementById('pfCur').value='';document.getElementById('pfNew').value='';toast('رمز تغییر کرد');}
  else m.innerHTML='<span class="tag tag-warn">'+esc(d.error||'خطا')+'</span>';
}
async function changeUsername(){
  const u=document.getElementById('pfUsername').value.trim();
  if(!u){toast('نام کاربری لازم است',false);return;}
  const d=await api('my_username_change',{username:u});
  if(d.ok){toast('نام کاربری تغییر کرد. صفحه را تازه کنید.');}
  else toast(d.error||'خطا',false);
}
loadProfile();
