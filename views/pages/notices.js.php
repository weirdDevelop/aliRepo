// pages/notices.js.php
let NZ_ITEMS = [];
const NZ_TYPE_LABELS = {popup:'پاپ‌آپ', banner_top:'نوار بالا', banner_bottom:'نوار پایین', slide_in:'اسلاید-این'};
const NZ_FREQ_LABELS = {always:'هر بار', once_per_session:'یک‌بار در بازدید', once_per_day:'یک‌بار در روز', once_ever:'فقط یک‌بار'};

// ==================== تب‌های اصلی صفحه ====================
function nzSegTab(name){
  document.getElementById('nzPaneEditor').style.display = name==='editor' ? '' : 'none';
  document.getElementById('nzPaneLeads').style.display = name==='leads' ? '' : 'none';
  document.getElementById('nzSegEditor').classList.toggle('on', name==='editor');
  document.getElementById('nzSegLeads').classList.toggle('on', name==='leads');
  if(name==='leads') loadNoticeLeads();
}

// ==================== تب‌های فرم ====================
const NZ_FORM_TABS = ['content','style','phone','rules'];
function nzFormTab(name){
  NZ_FORM_TABS.forEach(t=>{
    document.getElementById('nzPane'+t.charAt(0).toUpperCase()+t.slice(1)).classList.toggle('on', t===name);
    document.getElementById('nzFTab'+t.charAt(0).toUpperCase()+t.slice(1)).classList.toggle('on', t===name);
  });
}

function nzTogglePhone(){
  document.getElementById('nzPhoneFields').style.display = document.getElementById('nzCapturePhone').checked ? '' : 'none';
}
function nzToggleDiscountMode(){
  const mode = document.getElementById('nzDiscountMode').value;
  document.getElementById('nzDiscountStaticWrap').style.display = mode==='static' ? '' : 'none';
  document.getElementById('nzDiscountPrefixWrap').style.display = mode==='generated' ? '' : 'none';
}

// ==================== بارگذاری فهرست ====================
async function loadNotices(){
  const d = await api('notices_list');
  if(!d.ok) return;
  document.getElementById('nzNeedUpgrade').style.display = (d.ready === 1) ? 'none' : '';
  document.getElementById('nzEmbed').textContent = d.embed || '…';
  NZ_ITEMS = d.items || [];
  renderNotices();
  const sel = document.getElementById('nzLeadsFilter');
  sel.innerHTML = '<option value="0">همه‌ی اعلان‌ها</option>' + NZ_ITEMS.filter(it=>it.capture_phone==1).map(it=>`<option value="${it.id}">${esc(it.title)}</option>`).join('');
}

function nzCopyEmbed(){
  const text = document.getElementById('nzEmbed').textContent;
  navigator.clipboard?.writeText(text).then(()=>toast('کپی شد')).catch(()=>toast('کپی نشد', false));
}

function renderNotices(){
  const box = document.getElementById('nzList');
  if(!NZ_ITEMS.length){ box.innerHTML = '<div class="muted">هنوز اعلانی نساخته‌ای</div>'; return; }
  box.innerHTML = NZ_ITEMS.map(it=>{
    const stats = `👁 ${faNum(it.impressions||0)} · 🖱 ${faNum(it.clicks||0)}` + (it.capture_phone==1 ? ` · 📱 ${faNum(it.leads_count||0)} شماره` : '');
    return `
    <div class="card" style="padding:12px;margin-bottom:8px;border:1px solid var(--line)">
      <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">
        <div>
          <div style="font-weight:600;font-size:13px">${esc(it.title)}
            <span class="tag ${it.is_active==1?'tag-ok':'tag-mute'}" style="margin-inline-start:6px">${it.is_active==1?'فعال':'غیرفعال'}</span>
          </div>
          <div class="muted" style="font-size:11.5px;margin-top:3px">
            ${NZ_TYPE_LABELS[it.type]||it.type} · ${NZ_FREQ_LABELS[it.frequency]||it.frequency} · ${stats}
          </div>
        </div>
        <div style="display:flex;gap:4px;flex-shrink:0">
          <button class="btn btn-ghost btn-sm" onclick="editNotice(${it.id})" title="ویرایش">✎</button>
          <button class="btn btn-danger-ghost btn-sm" onclick="deleteNotice(${it.id})" title="حذف">✕</button>
        </div>
      </div>
      ${it.headline ? `<div class="note" style="margin-top:6px;font-weight:600">${esc(it.headline)}</div>` : ''}
      ${it.body ? `<div class="note" style="margin-top:2px;white-space:pre-wrap">${esc(it.body)}</div>` : ''}
    </div>`;
  }).join('');
}

function toDatetimeLocal(v){
  if(!v) return '';
  return String(v).replace(' ', 'T').slice(0,16);
}
function fromDatetimeLocal(v){
  if(!v) return '';
  return v.replace('T', ' ') + ':00';
}

function nzCollectForm(){
  return {
    id: document.getElementById('nzId').value || undefined,
    site_scope: (document.getElementById('nzSite')||{}).value || '',
    title: document.getElementById('nzTitle').value.trim(),
    type: document.getElementById('nzType').value,
    headline: document.getElementById('nzHeadline').value.trim(),
    body: document.getElementById('nzBody').value,
    image_url: document.getElementById('nzImage').value.trim(),
    button_text: document.getElementById('nzBtnText').value.trim(),
    button_url: document.getElementById('nzBtnUrl').value.trim(),
    bg_color: document.getElementById('nzBg').value,
    text_color: document.getElementById('nzFg').value,
    accent_color: document.getElementById('nzAccent').value,
    corner_radius: enNum(document.getElementById('nzRadius').value),
    font_family: document.getElementById('nzFont').value.trim(),
    delay_seconds: enNum(document.getElementById('nzDelay').value.trim()),
    frequency: document.getElementById('nzFreq').value,
    url_contains: document.getElementById('nzUrlInc').value.trim(),
    url_exclude: document.getElementById('nzUrlExc').value.trim(),
    starts_at: fromDatetimeLocal(document.getElementById('nzStart').value),
    ends_at: fromDatetimeLocal(document.getElementById('nzEnd').value),
    priority: enNum(document.getElementById('nzPriority').value.trim()),
    is_active: document.getElementById('nzActive').checked ? 1 : 0,
    capture_phone: document.getElementById('nzCapturePhone').checked ? 1 : 0,
    phone_label: document.getElementById('nzPhoneLabel').value.trim(),
    phone_button_text: document.getElementById('nzPhoneBtnText').value.trim(),
    discount_mode: document.getElementById('nzDiscountMode').value,
    discount_static_code: document.getElementById('nzDiscountStatic').value.trim(),
    discount_prefix: document.getElementById('nzDiscountPrefix').value.trim(),
    success_headline: document.getElementById('nzSuccessHeadline').value.trim(),
    success_note: document.getElementById('nzSuccessNote').value.trim(),
  };
}

async function saveNotice(){
  const d = await api('notices_save', nzCollectForm());
  if(!d.ok){ toast(d.error||'ذخیره نشد', false); return; }
  toast('ذخیره شد');
  resetNotice();
  loadNotices();
}

function editNotice(id){
  const it = NZ_ITEMS.find(x=>x.id==id);
  if(!it) return;
  document.getElementById('nzFormTitle').textContent = 'ویرایش اعلان';
  document.getElementById('nzId').value = it.id;
  if(document.getElementById('nzSite')) document.getElementById('nzSite').value = it.site_id ? 'site' : 'all';
  document.getElementById('nzTitle').value = it.title || '';
  document.getElementById('nzType').value = it.type || 'popup';
  document.getElementById('nzHeadline').value = it.headline || '';
  document.getElementById('nzBody').value = it.body || '';
  document.getElementById('nzImage').value = it.image_url || '';
  document.getElementById('nzBtnText').value = it.button_text || '';
  document.getElementById('nzBtnUrl').value = it.button_url || '';
  document.getElementById('nzBg').value = it.bg_color || '#ffffff';
  document.getElementById('nzFg').value = it.text_color || '#1b2030';
  document.getElementById('nzAccent').value = it.accent_color || '#5b6cff';
  document.getElementById('nzRadius').value = it.corner_radius ?? 16;
  document.getElementById('nzFont').value = it.font_family || '';
  document.getElementById('nzDelay').value = it.delay_seconds ?? 3;
  document.getElementById('nzFreq').value = it.frequency || 'once_per_session';
  document.getElementById('nzUrlInc').value = it.url_contains || '';
  document.getElementById('nzUrlExc').value = it.url_exclude || '';
  document.getElementById('nzStart').value = toDatetimeLocal(it.starts_at);
  document.getElementById('nzEnd').value = toDatetimeLocal(it.ends_at);
  document.getElementById('nzPriority').value = it.priority ?? 0;
  document.getElementById('nzActive').checked = it.is_active == 1;
  document.getElementById('nzCapturePhone').checked = it.capture_phone == 1;
  document.getElementById('nzPhoneLabel').value = it.phone_label || '';
  document.getElementById('nzPhoneBtnText').value = it.phone_button_text || '';
  document.getElementById('nzDiscountMode').value = it.discount_mode || 'none';
  document.getElementById('nzDiscountStatic').value = it.discount_static_code || '';
  document.getElementById('nzDiscountPrefix').value = it.discount_prefix || '';
  document.getElementById('nzSuccessHeadline').value = it.success_headline || '';
  document.getElementById('nzSuccessNote').value = it.success_note || '';
  document.getElementById('nzCancelBtn').style.display = '';
  nzTogglePhone(); nzToggleDiscountMode();
  document.getElementById('nzFormTitle').scrollIntoView({behavior:'smooth', block:'start'});
  nzUpdatePreview();
}

function resetNotice(){
  if(document.getElementById('nzSite')) document.getElementById('nzSite').value = 'site';
  document.getElementById('nzFormTitle').textContent = 'اعلان تازه';
  document.getElementById('nzId').value = '';
  document.getElementById('nzTitle').value = '';
  document.getElementById('nzType').value = 'popup';
  document.getElementById('nzHeadline').value = '';
  document.getElementById('nzBody').value = '';
  document.getElementById('nzImage').value = '';
  document.getElementById('nzBtnText').value = '';
  document.getElementById('nzBtnUrl').value = '';
  document.getElementById('nzBg').value = '#ffffff';
  document.getElementById('nzFg').value = '#1b2030';
  document.getElementById('nzAccent').value = '#5b6cff';
  document.getElementById('nzRadius').value = '16';
  document.getElementById('nzFont').value = '';
  document.getElementById('nzDelay').value = '3';
  document.getElementById('nzFreq').value = 'once_per_session';
  document.getElementById('nzUrlInc').value = '';
  document.getElementById('nzUrlExc').value = '';
  document.getElementById('nzStart').value = '';
  document.getElementById('nzEnd').value = '';
  document.getElementById('nzPriority').value = '0';
  document.getElementById('nzActive').checked = true;
  document.getElementById('nzCapturePhone').checked = false;
  document.getElementById('nzPhoneLabel').value = '';
  document.getElementById('nzPhoneBtnText').value = '';
  document.getElementById('nzDiscountMode').value = 'none';
  document.getElementById('nzDiscountStatic').value = '';
  document.getElementById('nzDiscountPrefix').value = '';
  document.getElementById('nzSuccessHeadline').value = '';
  document.getElementById('nzSuccessNote').value = '';
  document.getElementById('nzCancelBtn').style.display = 'none';
  nzTogglePhone(); nzToggleDiscountMode();
  nzUpdatePreview();
}

async function deleteNotice(id){
  if(!confirm('این اعلان حذف شود؟')) return;
  const d = await api('notices_delete', {id});
  if(d.ok){ toast('حذف شد'); loadNotices(); } else toast(d.error||'خطا', false);
}

// ==================== پیش‌نمایش زنده (همان منطق eb-embed.js، ساده‌شده برای پنل) ====================

function nzUpdatePreview(){
  const n = nzCollectForm();
  const host = document.getElementById('nzPreviewBody');
  const isBar = (n.type === 'banner_top' || n.type === 'banner_bottom');
  const radius = n.corner_radius ?? 16;
  const bg = n.bg_color || '#ffffff';
  const fg = n.text_color || '#1b2030';
  const accent = n.accent_color || '#5b6cff';
  const font = n.font_family || 'inherit'; // تو پنل، فونت خودِ پنل (که فارسی و یکسان با بقیه‌ی سایت‌هاست) به‌عنوان نمونه کافی‌ست

  let posCss = '';
  if(n.type === 'popup'){
    posCss = 'position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(15,17,26,.5)';
  } else if(n.type === 'banner_top'){
    posCss = 'position:absolute;top:0;left:0;right:0';
  } else if(n.type === 'banner_bottom'){
    posCss = 'position:absolute;bottom:0;left:0;right:0';
  } else {
    posCss = 'position:absolute;bottom:14px;left:14px;max-width:230px';
  }
  const cardCss = isBar
    ? 'padding:10px 14px;display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;text-align:center'
    : 'padding:18px;max-width:250px;width:calc(100% - 28px)';

  let inner = '';
  if(n.image_url && !isBar) inner += `<img src="${esc(n.image_url)}" style="max-width:100%;width:100%;max-height:110px;object-fit:cover;border-radius:${Math.max(0,radius-6)}px;margin-bottom:8px;display:block" onerror="this.style.display='none'">`;
  if(n.headline) inner += `<div style="font-weight:800;font-size:14px;margin-bottom:4px">${esc(n.headline)}</div>`;
  if(n.body) inner += `<div style="font-size:11.5px;line-height:1.7;opacity:.8;white-space:pre-wrap">${esc(n.body)}</div>`;

  if(n.capture_phone){
    inner += `<div style="margin-top:10px;display:flex;flex-direction:column;gap:6px">
      <input placeholder="${esc(n.phone_label||'شماره موبایلت رو بگو')}" disabled dir="ltr" style="text-align:center;padding:8px;border-radius:${Math.max(6,radius-6)}px;border:1.5px solid rgba(120,120,140,.28);font-size:11.5px;background:rgba(120,120,140,.05);color:inherit">
      <button style="padding:8px;border-radius:${Math.max(6,radius-6)}px;background:${accent};color:#fff;border:0;font-size:11.5px;font-weight:700">${esc(n.phone_button_text||'ارسال')}</button>
    </div>`;
  } else if(n.button_text){
    inner += `<div style="margin-top:10px;padding:9px;border-radius:${Math.max(6,radius-6)}px;background:${accent};color:#fff;text-align:center;font-size:11.5px;font-weight:700">${esc(n.button_text)}</div>`;
  }

  host.innerHTML = `<div style="${posCss}">
    <div style="background:${bg};color:${fg};${cardCss};border-radius:${isBar?0:radius}px;position:relative;
                box-shadow:0 16px 40px -10px rgba(10,12,20,.3);font-family:${font}">
      <div style="position:absolute;top:6px;left:6px;width:20px;height:20px;border-radius:50%;background:rgba(120,120,140,.15);font-size:10px;display:flex;align-items:center;justify-content:center">✕</div>
      ${inner}
    </div>
  </div>`;
}

// ==================== سرنخ‌ها ====================

async function loadNoticeLeads(){
  const noticeId = document.getElementById('nzLeadsFilter').value;
  document.getElementById('nzLeadsExport').href = 'index.php?export=notice_leads' + (noticeId!=='0' ? ('&notice_id='+noticeId) : '');
  const d = await api('notices_leads', {notice_id: noticeId});
  const box = document.getElementById('nzLeadsTable');
  if(!d.ok || !d.leads.length){ box.innerHTML = '<div class="muted">هنوز شماره‌ای ثبت نشده</div>'; return; }
  box.innerHTML = `
    <table class="tbl nz-leads-table">
      <thead><tr><th>تاریخ</th><th>اعلان</th><th>موبایل</th><th>کد تخفیف</th><th>مشتری شناخته‌شده</th></tr></thead>
      <tbody>${d.leads.map(l=>`<tr>
        <td class="muted" style="font-size:11.5px">${toFa(l.created_at)}</td>
        <td>${esc(l.notice_title||'—')}</td>
        <td class="mono" dir="ltr">${esc(l.phone)}</td>
        <td class="mono" dir="ltr">${esc(l.discount_code||'—')}</td>
        <td>${l.full_name ? esc(l.full_name) : '<span class="muted">ناشناس</span>'}</td>
      </tr>`).join('')}</tbody>
    </table>`;
}

// ==================== شروع ====================
nzTogglePhone();
nzToggleDiscountMode();
nzUpdatePreview();
loadNotices();
