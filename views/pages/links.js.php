// pages/links.js.php
let LNK_ID = 0;              // لینکی که drawer بازش کرده
let LNK_CATS = [];           // دسته‌های موجود (از سرور)
let LNK_T = null;            // تایمر debounce جستجو

function lnkFilters(){
  return {
    q: (document.getElementById('lnkQ')?.value || '').trim(),
    category: document.getElementById('lnkFilterCat')?.value || '',
    kind: document.getElementById('lnkFilterKind')?.value || ''
  };
}
function lnkDebounced(){ clearTimeout(LNK_T); LNK_T=setTimeout(loadLinks, 300); }

async function loadLinks(){
  const d=await api('links_list', lnkFilters());
  const tb=document.getElementById('lnkRows');
  if(!d.ok){ tb.innerHTML=`<tr><td colspan=6 class="empty">خطا در بارگذاری</td></tr>`; return; }

  LNK_CATS = d.categories||[];
  fillCatOptions(d.suggested||[]);

  if(!d.links.length){
    const f=lnkFilters(), filtered=f.q||f.category||f.kind;
    tb.innerHTML=`<tr><td colspan=6>${filtered
      ? emptyState('🔍','چیزی پیدا نشد','فیلترها را عوض کن')
      : emptyState('🔗','هنوز لینکی نیست','یک لینک کوتاه بساز و در پیام‌هایت استفاده کن')}</td></tr>`;
    return;
  }

  tb.innerHTML=d.links.map(l=>{
    const isTel = l.kind==='tel';
    const dest  = isTel ? ('📞 '+esc(faNum(l.tel||''))) : esc(l.target_url||'');
    return `<tr>
    <td style="cursor:pointer" onclick="openLnk(${l.id})">
      <div style="display:flex;align-items:center;gap:6px">
        <span class="tag ${isTel?'tag-ok':'tag-mute'}" style="font-size:10.5px">${isTel?'تماس':'وب'}</span>
        <span>${esc(l.title||'بدون عنوان')}</span>
      </div>
      <div class="mono" style="font-size:10.5px;opacity:.6;margin-top:3px;max-width:230px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" dir="ltr">${dest}</div>
    </td>
    <td>${l.category?`<span class="chip">${esc(l.category)}</span>`:'<span class="muted">—</span>'}</td>
    <td><span class="mono" style="font-size:11px">${esc(l.short_url)}</span> <button class="btn btn-ghost btn-xs" data-u="${esc(l.short_url)}" onclick="copyLink(this.dataset.u)">کپی</button></td>
    <td><span class="tag tag-accent">${faNum(l.clicks)}</span></td>
    <td class="muted" style="font-size:11.5px;white-space:nowrap">${toFa(l.created_at)}</td>
    <td style="text-align:left;white-space:nowrap">
      <button class="btn btn-ghost btn-sm" onclick="editLnk(${l.id})">ویرایش</button>
      <button class="btn btn-danger-ghost btn-sm" onclick="delLink(${l.id})">حذف</button>
    </td>
  </tr>`}).join('');
}

/** دسته‌ها را در datalist (پیشنهاد) و select فیلتر می‌ریزد */
function fillCatOptions(suggested){
  const dl=document.getElementById('lnkCatList');
  if(dl){
    const names=[...new Set([...LNK_CATS.map(c=>c.name), ...suggested])];
    dl.innerHTML=names.map(n=>`<option value="${esc(n)}">`).join('');
  }
  const sel=document.getElementById('lnkFilterCat');
  if(sel){
    const cur=sel.value;
    sel.innerHTML=`<option value="">همه‌ی دسته‌ها</option><option value="__none__">بدون دسته</option>`
      + LNK_CATS.map(c=>`<option value="${esc(c.name)}">${esc(c.name)} (${faNum(c.count)})</option>`).join('');
    sel.value=cur;   // انتخاب کاربر پس از رفرش حفظ شود
  }
}

/** تغییر نوع مقصد در فرم ساخت: راهنما و placeholder عوض می‌شود */
function onLnkKindChange(){
  const tel=document.getElementById('lnkKind').value==='tel';
  document.getElementById('lnkUrlLabel').textContent = tel?'شماره تماس':'آدرس مقصد';
  const inp=document.getElementById('lnkUrl');
  inp.placeholder = tel?'09121234567':'https://example.com/offer';
  document.getElementById('lnkUrlHint').textContent = tel
    ? 'با کلیک روی لینک، صفحه‌ی تماس گوشی با همین شماره باز می‌شود.'
    : 'آدرس کامل یا فقط دامنه (خودش https اضافه می‌شود).';
}
function onEdKindChange(){
  const tel=document.getElementById('edKind').value==='tel';
  document.getElementById('edUrlLabel').textContent = tel?'شماره تماس':'آدرس مقصد';
  document.getElementById('edUrl').placeholder = tel?'09121234567':'https://example.com/offer';
}

/**
 * مقصد نهایی برای ارسال به سرور.
 * فقط وقتی «تماس» انتخاب شده **و** ورودی شماره‌مانند است پیشوند tel: می‌گذاریم.
 * اگر کاربر نوع را اشتباه انتخاب کرده باشد (مثلاً تماس ولی آدرس وب چسبانده)،
 * مقدار خام می‌رود و سرور خودش نوع درست را تشخیص می‌دهد.
 */
function lnkTarget(isTel, raw){
  const phoneish = /^[+()\-\s0-9۰-۹٠-٩]+$/.test(raw);
  return (isTel && phoneish) ? ('tel:'+raw) : raw;
}

async function createLink(){
  const url=document.getElementById('lnkUrl').value.trim();
  const isTel=document.getElementById('lnkKind').value==='tel';
  if(!url){toast(isTel?'شماره تماس لازم است':'آدرس مقصد لازم است',false);return;}
  const d=await api('link_create',{
    target_url: lnkTarget(isTel, url),
    title: document.getElementById('lnkTitle').value.trim(),
    category: document.getElementById('lnkCat').value.trim()
  });
  if(d.ok){
    document.getElementById('lnkResult').innerHTML=
      `<span class="tag tag-ok">ساخته شد</span> <span class="mono">${esc(d.short_url)}</span>`;
    document.getElementById('lnkUrl').value=''; document.getElementById('lnkTitle').value='';
    toast('لینک ساخته شد'); loadLinks();
  } else {
    document.getElementById('lnkResult').innerHTML=`<span class="tag tag-warn">خطا</span> ${esc(d.error||'')}`;
  }
}

function copyLink(u){
  // پشتیبان: navigator.clipboard فقط در بستر امن (https) وجود دارد و بسیاری از
  // نصب‌ها روی http هستند. با `?.` زنجیره کوتاه می‌شد و .then هرگز اجرا نمی‌شد،
  // یعنی نه کپی می‌شد نه پیامی می‌آمد. پس صریح شرط می‌گذاریم.
  const fallback=()=>{
    const t=document.createElement('input');t.value=u;document.body.appendChild(t);t.select();
    try{document.execCommand('copy');toast('کپی شد');}catch(e){toast('کپی نشد',false);}
    document.body.removeChild(t);
  };
  if(navigator.clipboard && navigator.clipboard.writeText){
    navigator.clipboard.writeText(u).then(()=>toast('کپی شد'), fallback);
  } else { fallback(); }
}
async function delLink(id){
  if(!confirm('حذف شود؟'))return;
  const d=await api('link_delete',{id});
  if(!d.ok){ toast(d.error||'حذف نشد',false); return; }
  if(LNK_ID===id) closeLnk();     // drawer باز روی لینکِ حذف‌شده نماند
  toast('حذف شد'); loadLinks();
}

// ==================== ویرایش (مورد ۴) ====================
async function editLnk(id){
  const d=await api('link_detail',{id});
  if(!d.ok){toast('خطا',false);return;}
  const l=d.link;
  LNK_ID=id;
  openDrawerShell('ویرایش لینک');
  document.getElementById('lnkBody').style.display='none';
  document.getElementById('lnkEdit').style.display='';
  document.getElementById('edResult').innerHTML='';
  document.getElementById('edTitle').value=l.title||'';
  document.getElementById('edCat').value=l.category||'';
  document.getElementById('edKind').value=l.kind==='tel'?'tel':'url';
  document.getElementById('edUrl').value=l.kind==='tel' ? (l.tel||'') : (l.target_url||'');
  onEdKindChange();
}
async function saveLnkEdit(){
  const isTel=document.getElementById('edKind').value==='tel';
  const raw=document.getElementById('edUrl').value.trim();
  if(!raw){toast(isTel?'شماره تماس لازم است':'آدرس مقصد لازم است',false);return;}
  const d=await api('link_update',{
    id: LNK_ID,
    title: document.getElementById('edTitle').value.trim(),
    target_url: lnkTarget(isTel, raw),
    category: document.getElementById('edCat').value.trim()
  });
  if(d.ok){ toast('ذخیره شد'); loadLinks(); openLnk(LNK_ID); }
  else { document.getElementById('edResult').innerHTML=`<span class="tag tag-warn">خطا</span> ${esc(d.error||'')}`; }
}

// ==================== جزئیات ====================
function openDrawerShell(title){
  document.getElementById('lnkBackdrop').classList.add('show');
  document.getElementById('lnkDrawer').classList.add('open');
  document.getElementById('lnkDrawerTitle').textContent=title;
}

/** یک تفکیک را به‌صورت نوار سهم رندر می‌کند (مورد ۱۱) */
function bdBlock(title, rows){
  if(!rows||!rows.length) return '';
  const max=Math.max(...rows.map(r=>+r.c||0))||1;
  return `<div style="margin-bottom:14px">
    <div style="font-weight:600;font-size:12.5px;margin-bottom:5px">${title}</div>
    ${rows.map(r=>`<div class="bd-row">
      <span class="bd-name" title="${esc(r.k)}">${esc(r.k)}</span>
      <span class="bd-bar"><span class="bd-fill" style="width:${Math.round((+r.c||0)/max*100)}%"></span></span>
      <span class="bd-num">${faNum(r.c)}</span>
    </div>`).join('')}
  </div>`;
}

async function openLnk(id){
  LNK_ID=id;
  openDrawerShell('جزئیات لینک');
  document.getElementById('lnkEdit').style.display='none';
  const body=document.getElementById('lnkBody');
  body.style.display=''; body.innerHTML='<div class="empty">…</div>';

  const d=await api('link_detail',{id});
  if(!d.ok){body.innerHTML='<div class="empty">خطا</div>';return;}
  const l=d.link, clicks=l.click_list||[], isTel=l.kind==='tel', bd=l.breakdown||{};

  body.innerHTML=`
    <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">
      <span class="tag ${isTel?'tag-ok':'tag-mute'}" style="font-size:10.5px">${isTel?'📞 تماس':'🔗 وب'}</span>
      <span style="font-weight:600">${esc(l.title||'بدون عنوان')}</span>
      ${l.category?`<span class="chip">${esc(l.category)}</span>`:''}
    </div>
    <div class="mono muted" style="font-size:11px;margin-bottom:6px;word-break:break-all" dir="ltr">${isTel?esc(faNum(l.tel||'')):esc(l.target_url)}</div>
    <div class="mono muted" style="font-size:11px;margin-bottom:10px;word-break:break-all" dir="ltr">${esc(l.short_url||'')}</div>
    <div class="muted" style="font-size:11.5px;margin-bottom:14px">ساخته شده: ${toFa(l.created_at)}</div>

    <div style="display:flex;gap:8px;margin-bottom:16px">
      <div style="flex:1;background:var(--accent-soft);border-radius:8px;padding:12px;text-align:center"><div style="font-size:22px;font-weight:700;color:var(--accent-ink)">${faNum(l.clicks)}</div><div style="font-size:11px">کل کلیک</div></div>
      <div style="flex:1;background:var(--bg);border-radius:8px;padding:12px;text-align:center"><div style="font-size:22px;font-weight:700">${faNum(l.unique_persons)}</div><div style="font-size:11px">مشتری یکتا</div></div>
      <div style="flex:1;background:var(--bg);border-radius:8px;padding:12px;text-align:center"><div style="font-size:22px;font-weight:700">${faNum(l.unique_ips||0)}</div><div style="font-size:11px">بازدیدکننده یکتا</div></div>
    </div>

    <button class="btn btn-ghost btn-sm" onclick="editLnk(${l.id})" style="margin-bottom:16px">✏️ ویرایش عنوان و مقصد</button>

    <div style="font-weight:600;margin-bottom:6px">روند کلیک روزانه (۳۰ روز اخیر)</div>
    <div style="margin-bottom:16px">${linkDailyChart(l.daily)}</div>

    ${bdBlock('نوع دستگاه', bd.device)}
    ${bdBlock('مرورگر', bd.browser)}
    ${bdBlock('سیستم‌عامل', bd.os)}
    ${bdBlock('مرجع (از کجا آمد)', bd.referer)}

    <div style="font-weight:600;margin-bottom:6px">کلیک‌های اخیر</div>
    ${clicks.length?clicks.map(c=>`<div style="padding:7px 0;border-bottom:1px solid var(--line-soft);font-size:12.5px">
      <div style="display:flex;justify-content:space-between;gap:8px">
        <span>${esc(c.full_name||'ناشناس')}</span>
        <span class="muted" style="font-size:11px;white-space:nowrap">${toFa(c.clicked_at)}</span>
      </div>
      <div class="muted" style="font-size:11px;margin-top:2px;display:flex;flex-wrap:wrap;gap:6px">
        ${c.device?`<span>${esc(c.device)}</span>`:''}
        ${c.browser?`<span>· ${esc(c.browser)}</span>`:''}
        ${c.os?`<span>· ${esc(c.os)}</span>`:''}
        ${c.ip?`<span class="mono" dir="ltr">· ${esc(c.ip)}</span>`:''}
      </div>
      ${c.referer?`<div class="mono muted" style="font-size:10.5px;margin-top:2px;word-break:break-all" dir="ltr">↩ ${esc(c.referer)}</div>`:''}
    </div>`).join(''):'<div class="muted">هنوز کلیکی نیست</div>'}

    ${(!bd.device||!bd.device.length)&&clicks.length?'<div class="note" style="margin-top:12px;font-size:11.5px">برای دیدن دستگاه و مرورگر، یک بار «به‌روزرسانی دیتابیس» را در تنظیمات کانال‌ها بزن. کلیک‌های قبلی این اطلاعات را ندارند.</div>':''}`;
}

/** نمودار میله‌ایِ کلیک روزانه (مورد ۶) — لینک‌های رهگیری دائمی‌اند، روند روزانه از عدد جمعی مفیدتر است */
function linkDailyChart(daily){
  if(!daily || !daily.days || !daily.days.length) return '<div class="muted">داده‌ای نیست</div>';
  const days = daily.days, counts = daily.counts;
  const n = days.length;
  const W = 900, H = 150, pad = 20, topPad = 16; // topPad: جای عدد بالای هر ستون
  const max = Math.max(1, ...counts);
  const bw = (W - 2*pad) / n;
  // اگر ستون‌ها خیلی باریک باشند (روزهای زیاد)، نوشتن عدد رو همه‌شون شلوغ و نامفهوم می‌شود؛
  // در آن حالت فقط روی هاور (title) دیده می‌شود، همان‌طور که قبلاً بود.
  const showLabels = bw >= 16;
  const bars = counts.map((c,i)=>{
    const h = (c/max) * (H - pad - topPad - 14);
    const x = pad + i*bw;
    const y = H - pad - h;
    const label = (showLabels && c > 0)
      ? `<text x="${(x+bw/2).toFixed(1)}" y="${(y-4).toFixed(1)}" font-size="10" fill="var(--ink-soft)" text-anchor="middle">${faNum(c)}</text>`
      : '';
    return `${label}<rect x="${(x+bw*0.15).toFixed(1)}" y="${y.toFixed(1)}" width="${(bw*0.7).toFixed(1)}" height="${h.toFixed(1)}" rx="2" fill="var(--brand)" fill-opacity="0.85"><title>${toFa(days[i])}: ${faNum(c)} کلیک</title></rect>`;
  }).join('');
  const step = Math.max(1, Math.floor(n/6));
  const ticks = [];
  for(let i=0;i<n;i+=step){
    ticks.push(`<text x="${(pad+i*bw+bw/2).toFixed(1)}" y="${H-4}" font-size="9" fill="var(--ink-faint)" text-anchor="middle">${toFa(days[i]).slice(5)}</text>`);
  }
  return `<svg viewBox="0 0 ${W} ${H}" style="width:100%;height:${H}px;direction:ltr">${bars}${ticks.join('')}</svg>`;
}

function closeLnk(){
  document.getElementById('lnkBackdrop').classList.remove('show');
  document.getElementById('lnkDrawer').classList.remove('open');
}
onLnkKindChange();
loadLinks();
