// pages/settings.js.php
// مورد ۹ (باگ رفع‌شده): این فهرست فقط ۱۰ صفحه داشت در حالی که پنل ۱۷ صفحه‌ی
// قابل‌واگذاری دارد. صفحات غایب: صندوق ورودی، لینک رهگیری، فرم، بات‌ساز، صف،
// مارکتینگ، پشتیبانی. چون «صندوق ورودی» در فرم نبود، برای اپراتورِ پشتیبانی
// راهی جز دادن دسترسی کامل نمی‌ماند.
// گروه‌بندی عیناً مطابق ناوبری کنار پنل است تا پیدا کردنش آسان باشد.
// (صفحات فقط-مدیر — کانال‌ها، تنظیمات، لاگ سیستم، هوش مصنوعی — عمداً اینجا نیستند
//  چون Auth::canAccess هرگز به اپراتور نمی‌دهد؛ آوردنشان وعده‌ی دروغ بود.)
const OP_GROUPED = {
  'ارتباطات':  {inbox:'صندوق ورودی', instagram:'اینستاگرام', send:'ارسال پیام', safir:'ارسال با شماره', queue:'صف ارسال', sendlog:'تاریخچه‌ی ارسال'},
  'مخاطبان':   {persons:'بانک مشتری', groups:'گروه و برچسب'},
  'اتوماسیون': {botbuilder:'بات‌ساز منو', forms:'فرم و نظرسنجی', autoreply:'پاسخ خودکار', ai:'هوش مصنوعی', campaigns:'کمپین', scheduled:'زمان‌بندی'},
  'محتوا':     {templates:'الگوها', links:'لینک رهگیری'},
  'تحلیل':     {stats:'آمار'},
  'تیمی':      {marketing:'مارکتینگ و تیم', support:'پشتیبانی تیمی'},
};
// نگاشت تخت (برای شمارش و برچسب)
const OP_PAGES = Object.assign({}, ...Object.values(OP_GROUPED));
let OP_GROUPS = [];

async function loadOps(){
  const g = await api('groups_list'); OP_GROUPS = g.groups||[];
  const d = await api('operators_list');
  if(!d.ok) return;
  const ops = d.operators.filter(o=>o.role!=='admin');
  const box = document.getElementById('opList');
  if(!ops.length){ box.innerHTML='<div class="muted" style="font-size:13px">هنوز اپراتوری نیست</div>'; return; }
  box.innerHTML = ops.map(o=>{
    let perms={}; try{perms=JSON.parse(o.permissions_json||'{}');}catch(e){}
    const pCount=(perms.pages||[]).length, gCount=(perms.groups||[]).length;
    const scope = (pCount?`${pCount} بخش`:'همه بخش‌ها')+' · '+(gCount?`${gCount} گروه`:'همه مشتریان');
    return `<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line-soft)">
      <div><span style="font-weight:500">${esc(o.name || o.username)}</span>${o.name && o.name !== o.username ? ` <span class="muted" dir="ltr" style="font-size:11px">@${esc(o.username)}</span>` : ''} ${o.role==='supervisor'?'<span class="tag tag-accent">سرپرست</span>':''} ${o.is_active==1?'':'<span class="tag tag-mute">غیرفعال</span>'}<div class="muted" style="font-size:11.5px">${scope}</div></div>
      <div style="display:flex;gap:4px">
        <button class="btn btn-ghost btn-sm" onclick='editOp(${JSON.stringify(o)})'>ویرایش</button>
        <button class="btn btn-danger-ghost btn-sm" onclick="delOp(${o.id})">حذف</button>
      </div>
    </div>`;
  }).join('');
}

function renderOpForm(perms){
  perms=perms||{};
  const pages=perms.pages||[], groups=(perms.groups||[]).map(String);
  // رندر گروه‌بندی‌شده + دکمه‌ی انتخاب/برداشتن همه‌ی هر گروه
  document.getElementById('opPages').innerHTML=Object.entries(OP_GROUPED).map(([title,items])=>{
    const rows=Object.entries(items).map(([k,v])=>
      `<label style="display:flex;align-items:center;gap:7px;font-size:12.5px;padding:1px 0">
         <input type="checkbox" class="opPage" data-grp="${esc(title)}" value="${k}" ${pages.includes(k)?'checked':''} style="width:auto" onchange="syncOpCount()"> ${v}
       </label>`).join('');
    return `<div style="margin-bottom:9px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px">
        <span style="font-size:11px;font-weight:700;color:var(--ink-soft)">${title}</span>
        <button type="button" class="btn btn-ghost btn-xs" onclick="toggleOpGroup('${esc(title)}')">همه</button>
      </div>
      <div style="padding-right:6px;border-right:2px solid var(--line-soft)">${rows}</div>
    </div>`;
  }).join('');
  document.getElementById('opGroups').innerHTML=OP_GROUPS.length?OP_GROUPS.map(g=>
    `<label style="display:flex;align-items:center;gap:7px;font-size:12.5px"><input type="checkbox" class="opGroup" value="${g.id}" ${groups.includes(String(g.id))?'checked':''} style="width:auto"> ${esc(g.name)}</label>`
  ).join(''):'<span class="muted" style="font-size:12px">گروهی تعریف نشده</span>';
  syncOpCount();
}
// تیک/بی‌تیک کردن یک گروه کامل
function toggleOpGroup(title){
  const boxes=[...document.querySelectorAll(`.opPage[data-grp="${title}"]`)];
  if(!boxes.length) return;
  const allOn=boxes.every(b=>b.checked);
  boxes.forEach(b=>b.checked=!allOn);
  syncOpCount();
}
function toggleOpAll(on){
  document.querySelectorAll('.opPage').forEach(b=>b.checked=on);
  syncOpCount();
}
// نمایش شفافِ معنیِ «هیچ تیکی نزده» — منبع سوءتفاهم مورد ۹
function syncOpCount(){
  const n=document.querySelectorAll('.opPage:checked').length;
  const total=Object.keys(OP_PAGES).length;
  const el=document.getElementById('opPagesHint');
  if(!el) return;
  el.innerHTML = n===0
    ? '<b>هیچ بخشی تیک نخورده</b> = دسترسی به <b>همه‌ی</b> بخش‌ها (به‌جز بخش‌های فقط-مدیر). برای محدودکردن، بخش‌های مجاز را تیک بزنید.'
    : `${faNum(n)} بخش از ${faNum(total)} بخش انتخاب شده — اپراتور فقط همین‌ها را می‌بیند.`;
}
function openOpForm(){
  document.getElementById('opId').value='';
  document.getElementById('opUser').value=''; document.getElementById('opUser').disabled=false;
  document.getElementById('opPass').value=''; document.getElementById('opPwHint').textContent='';
  document.getElementById('opActive').checked=true;
  document.getElementById('opCanDelete').checked=false;
  document.getElementById('opRole').value='operator';
  document.getElementById('opTitle').textContent='اپراتور جدید';
  renderOpForm({});
  document.getElementById('opBackdrop').classList.add('show');
  document.getElementById('opDrawer').classList.add('open');
}
function editOp(o){
  let perms={}; try{perms=JSON.parse(o.permissions_json||'{}');}catch(e){}
  document.getElementById('opId').value=o.id;
  document.getElementById('opUser').value=o.username; document.getElementById('opUser').disabled=true;
  document.getElementById('opPass').value=''; document.getElementById('opPwHint').textContent='(خالی = بدون تغییر)';
  document.getElementById('opActive').checked=(o.is_active==1);
  try{document.getElementById('opCanDelete').checked=!!(JSON.parse(o.permissions_json||'{}').can_delete_customers);}catch(e){document.getElementById('opCanDelete').checked=false;}
  document.getElementById('opRole').value=(o.role==='supervisor'?'supervisor':'operator');
  document.getElementById('opTitle').textContent='ویرایش اپراتور';
  renderOpForm(perms);
  document.getElementById('opBackdrop').classList.add('show');
  document.getElementById('opDrawer').classList.add('open');
}
function closeOpForm(){
  document.getElementById('opBackdrop').classList.remove('show');
  document.getElementById('opDrawer').classList.remove('open');
}
async function saveOp(){
  const id=document.getElementById('opId').value;
  const pages=[...document.querySelectorAll('.opPage:checked')].map(c=>c.value);
  const groups=[...document.querySelectorAll('.opGroup:checked')].map(c=>c.value);
  const data={pages, groups, can_delete_customers:document.getElementById('opCanDelete').checked?1:0, is_active:document.getElementById('opActive').checked?1:0, role:document.getElementById('opRole').value, password:document.getElementById('opPass').value};
  let d;
  if(id){ data.id=id; d=await api('operator_update', data); }
  else { data.username=document.getElementById('opUser').value.trim(); d=await api('operator_create', data); }
  if(d.ok){ toast('ذخیره شد'); closeOpForm(); loadOps(); }
  else toast(d.error||'خطا', false);
}
async function delOp(id){
  if(!confirm('این اپراتور حذف شود؟'))return;
  const d=await api('operator_delete',{id});
  if(d.ok){ toast('حذف شد'); loadOps(); } else toast(d.error||'خطا',false);
}

async function loadSettings(){
  const d=await api('settings_get');
  if(!d.ok) return;
  document.getElementById('stCompany').value=d.company||'';
  renderCron(d);
  if(d.brand) fillBrand(d.brand);
}

// ===== برند =====
let BR_LOGO = '';
function fillBrand(b){
  document.getElementById('brName').value = b.name||'';
  document.getElementById('brTagline').value = b.tagline||'';
  const acc = b.accent||'#1d4ed8';
  document.getElementById('brAccent').value = acc;
  document.getElementById('brAccentText').value = acc;
  BR_LOGO = b.logo||'';
  renderLogoPreview();
}
function renderLogoPreview(){
  const box=document.getElementById('brLogoPreview');
  const rm=document.getElementById('brLogoRemove');
  if(BR_LOGO){
    box.innerHTML=`<img src="${esc(BR_LOGO)}?v=${Date.now()}" style="width:100%;height:100%;object-fit:contain">`;
    rm.style.display='';
  } else {
    box.innerHTML='<span class="muted" style="font-size:10px">بدون لوگو</span>';
    rm.style.display='none';
  }
}
function syncAccentText(){ document.getElementById('brAccentText').value=document.getElementById('brAccent').value; }
function syncAccentColor(){
  let v=document.getElementById('brAccentText').value.trim();
  if(/^#[0-9a-fA-F]{6}$/.test(v)) document.getElementById('brAccent').value=v;
}
function pickAccent(hex){
  document.getElementById('brAccent').value=hex;
  document.getElementById('brAccentText').value=hex;
}
async function uploadLogo(){
  const f=document.getElementById('brLogoFile').files[0];
  if(!f) return;
  const fd=new FormData(); fd.append('image',f);
  const d=await api('brand_logo_upload', fd, true);
  if(d.ok){ BR_LOGO=d.logo; renderLogoPreview(); toast('لوگو آپلود شد'); }
  else toast(d.error||'آپلود ناموفق', false);
}
async function removeLogo(){
  const d=await api('brand_save',{logo:''});
  if(d.ok){ BR_LOGO=''; renderLogoPreview(); toast('لوگو حذف شد'); }
}
async function saveBrand(){
  const accent=document.getElementById('brAccentText').value.trim();
  if(!/^#[0-9a-fA-F]{6}$/.test(accent)){ toast('رنگ نامعتبر است (مثلاً #1d4ed8)', false); return; }
  const d=await api('brand_save',{
    name: document.getElementById('brName').value.trim(),
    tagline: document.getElementById('brTagline').value.trim(),
    accent: accent,
  });
  if(d.ok){ toast('ذخیره شد — بارگذاری مجدد…'); setTimeout(()=>location.reload(), 700); }
  else toast(d.error||'خطا', false);
}
function renderCron(d){
  const area=document.getElementById('cronArea');
  if(d.cron_key){
    area.innerHTML=`
      <div class="field">
        <label>آدرس Cron (این را در کران‌جاب سرور بگذارید)</label>
        <div style="display:flex;gap:6px">
          <input class="mono" value="${esc(d.cron_url)}" readonly id="cronUrl" style="flex:1">
          <button class="btn btn-ghost btn-sm" onclick="copyCron()">کپی</button>
        </div>
      </div>
      <div class="note mono" style="background:var(--bg);padding:10px;border-radius:8px;direction:ltr;text-align:left">* * * * * wget -q -O /dev/null "${esc(d.cron_url)}"</div>
      <button class="btn btn-ghost btn-sm" onclick="genCron()" style="margin-top:10px">تولید کلید جدید</button>`;
  } else {
    area.innerHTML=`<p class="note" style="margin-bottom:10px">⚠️ هنوز کلید Cron ساخته نشده. برای فعال‌سازی زمان‌بندی، یک کلید بسازید.</p>
      <button class="btn btn-primary btn-sm" onclick="genCron()">ساخت کلید Cron</button>`;
  }
}
function copyCron(){
  const el=document.getElementById('cronUrl'); el.select();
  try{ document.execCommand('copy'); toast('کپی شد'); }catch(e){}
}
async function genCron(){
  if(!confirm('کلید جدید ساخته شود؟ اگر قبلاً کران تنظیم کرده‌اید، باید آدرس را به‌روز کنید.'))return;
  const d=await api('cron_key_generate');
  if(d.ok){ toast('کلید ساخته شد'); loadSettings(); }
}
async function saveSettings(){
  const d=await api('settings_save',{company:document.getElementById('stCompany').value.trim()});
  if(d.ok)toast('ذخیره شد');
}
loadSettings();
loadOps();
