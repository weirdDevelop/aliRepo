// pages/ai.js.php
const DAY_LABELS = {6:'شنبه',0:'یکشنبه',1:'دوشنبه',2:'سه‌شنبه',3:'چهارشنبه',4:'پنجشنبه',5:'جمعه'};
const WEEK_ORDER = [6,0,1,2,3,4,5];
let SUP_DAYS = [];
let PROVIDERS = {};   // آخرین وضعیت پروایدرها که از سرور آمده (برای ساخت payload ذخیره)

async function loadAi(){
  const d = await api('ai_settings');
  if(!d.ok){ document.getElementById('aiStatus').textContent='دسترسی این بخش فقط برای مدیر است'; return; }
  const a = d.ai || {}, s = d.support || {};

  document.getElementById('aiEnabled').checked     = a.enabled === '1';
  document.getElementById('aiSystem').value        = a.system || '';
  document.getElementById('aiSignature').value     = a.signature || '';
  document.getElementById('aiAutoOffline').checked = a.auto_offline === '1';
  document.getElementById('aiInsecure').checked    = a.insecure === '1';
  document.getElementById('aiTemp').value          = a.temperature || '0.3';
  document.getElementById('aiMaxTok').value        = a.max_tokens || '400';
  document.getElementById('aiHistory').value       = a.history || '8';
  document.getElementById('aiTimeout').value       = a.timeout || '20';
  document.getElementById('aiRate').value          = (a.rate_hour !== undefined ? a.rate_hour : '10');

  PROVIDERS = a.providers || {};
  renderProviders();

  document.getElementById('supPresence').value = s.presence || 'auto';
  document.getElementById('supFrom').value     = s.from || '09:00';
  document.getElementById('supTo').value       = s.to || '18:00';
  SUP_DAYS = Array.isArray(s.days) ? s.days.map(Number) : [];
  renderDays();

  const tz = document.getElementById('supTz');
  const tzList = (d.timezones||['Asia/Tehran']).slice();
  // اگر منطقه‌ی ذخیره‌شده در فهرست نبود، اضافه‌اش کن وگرنه select بی‌صدا عوض می‌شود
  if(s.tz && !tzList.includes(s.tz)) tzList.unshift(s.tz);
  tz.innerHTML = tzList.map(t=>`<option value="${esc(t)}">${esc(t)}</option>`).join('');
  tz.value = s.tz || 'Asia/Tehran';

  renderStatus(d);
}

function renderDays(){
  document.getElementById('supDays').innerHTML = WEEK_ORDER.map(n=>{
    const on = SUP_DAYS.includes(n);
    return `<button type="button" class="chip-btn${on?' on':''}" data-d="${n}" onclick="toggleDay(${n})"
      style="${on?'background:var(--accent-soft);color:var(--accent-ink);border-color:var(--accent)':''}">${DAY_LABELS[n]}</button>`;
  }).join('');
}
function toggleDay(n){
  const i = SUP_DAYS.indexOf(n);
  if(i>=0) SUP_DAYS.splice(i,1); else SUP_DAYS.push(n);
  renderDays();
}

/** یک جمله‌ی روشن که بگوید الان AI چه اجازه‌ای دارد */
function renderStatus(d){
  const s = d.support||{}, mode = d.mode, ready = (d.ai||{}).ready === 1;
  const online = !!s.online;

  let tag, text;
  if(!ready){
    tag = `<span class="tag tag-mute">خاموش</span>`;
    text = 'اتصال تنظیم یا فعال نشده — هیچ پاسخ خودکاری فرستاده نمی‌شود و دکمه‌ی پیش‌نویس هم کار نمی‌کند.';
  } else if(mode === 'auto'){
    tag = `<span class="tag tag-ok">پاسخ خودکار</span>`;
    text = 'تیم آفلاین است، پس هوش مصنوعی به پیام‌های تازه خودش جواب می‌دهد.';
  } else {
    tag = `<span class="tag tag-accent">فقط پیش‌نویس</span>`;
    text = 'هوش مصنوعی چیزی نمی‌فرستد؛ در گفتگو دکمه‌ی «پیش‌نویس هوش مصنوعی» پاسخ پیشنهادی می‌سازد و ارسال با اپراتور است.';
  }

  document.getElementById('aiStatus').innerHTML = `
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px">
      <span style="font-weight:600">وضعیت الان:</span> ${tag}
      <span class="tag ${online?'tag-ok':'tag-warn'}">تیم ${online?'آنلاین':'آفلاین'}</span>
      ${s.reason?`<span class="muted" style="font-size:12px">(${esc(s.reason)})</span>`:''}
      ${s.now?`<span class="muted" style="font-size:12px">· ساعت ${esc(faNum(s.now))}</span>`:''}
    </div>
    <div class="note" style="margin:0">${text}</div>`;
}

// ---- پروایدرها (چند سرویس هم‌زمان + اولویت/fallback) ----
function renderProviders(){
  const box = document.getElementById('providerList');
  const keys = Object.keys(PROVIDERS);
  if(!keys.length){ box.innerHTML = '<div class="muted">در حال بارگذاری…</div>'; return; }

  // به ترتیب اولویت نشان بده
  keys.sort((a,b)=> (PROVIDERS[a].priority||9) - (PROVIDERS[b].priority||9));

  box.innerHTML = keys.map(k=>{
    const p = PROVIDERS[k];
    const readyTag = p.ready
      ? `<span class="tag tag-ok">کامل تنظیم شده</span>`
      : `<span class="tag tag-mute">ناقص</span>`;
    const enabledTag = p.enabled ? `<span class="tag tag-accent">فعال</span>` : '';
    return `
    <div class="card" style="padding:14px;margin-bottom:10px;border:1px solid var(--line)" data-prov="${k}">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" class="prov-enabled" data-k="${k}" ${p.enabled?'checked':''}>
          <span style="font-weight:600">${esc(p.label)}</span>
        </label>
        <div style="display:flex;gap:6px;align-items:center">${enabledTag}${readyTag}</div>
      </div>
      <div class="note" style="margin:-4px 0 10px">${esc(p.hint||'')}</div>
      <div class="row" style="gap:10px">
        ${p.editable_url ? `
        <div class="field col">
          <label>آدرس سرویس (base URL)</label>
          <input class="mono prov-base" dir="ltr" data-k="${k}" value="${esc(p.base_url||'')}" placeholder="https://.../v1">
        </div>` : `
        <div class="field col">
          <label>آدرس سرویس</label>
          <input class="mono" dir="ltr" value="${esc(p.base_url||'')}" disabled>
        </div>`}
        <div class="field col">
          <label>نام مدل</label>
          <input class="mono prov-model" dir="ltr" data-k="${k}" value="${esc(p.model||'')}" placeholder="مثلاً gpt-4o-mini">
        </div>
      </div>
      <div class="row" style="gap:10px">
        <div class="field col">
          <label>کلید API</label>
          <input class="mono prov-key" dir="ltr" type="password" data-k="${k}" placeholder="${p.api_key_set?'•••••••• (تنظیم شده)':'کلید را وارد کن'}">
        </div>
        <div class="field col" style="max-width:140px">
          <label>اولویت</label>
          <input class="mono prov-priority" dir="ltr" data-k="${k}" value="${p.priority||9}">
        </div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:4px">
        <button class="btn btn-ghost btn-sm" onclick="testProvider('${k}')">تست این پروایدر</button>
        ${p.api_key_set ? `<button class="btn btn-danger-ghost btn-sm" onclick="clearProviderKey('${k}')">پاک‌کردن کلید</button>` : ''}
        <span class="note" id="provTest_${k}" style="margin:0"></span>
      </div>
    </div>`;
  }).join('');
}

function collectProviderPayload(){
  const out = {};
  document.querySelectorAll('#providerList [data-prov]').forEach(card=>{
    const k = card.getAttribute('data-prov');
    const enabled  = card.querySelector('.prov-enabled').checked ? 1 : 0;
    const model    = card.querySelector('.prov-model').value.trim();
    const baseEl   = card.querySelector('.prov-base');
    const base_url = baseEl ? baseEl.value.trim() : '';
    const keyEl    = card.querySelector('.prov-key');
    const api_key  = keyEl ? keyEl.value : '';
    const priority = enNum(card.querySelector('.prov-priority').value.trim()) || 9;
    out[k] = { enabled, model, base_url, api_key, priority };
  });
  return out;
}

async function saveAi(){
  const d = await api('ai_save', {
    enabled:      document.getElementById('aiEnabled').checked ? 1 : 0,
    system:       document.getElementById('aiSystem').value,
    signature:    document.getElementById('aiSignature').value.trim(),
    auto_offline: document.getElementById('aiAutoOffline').checked ? 1 : 0,
    insecure:     document.getElementById('aiInsecure').checked ? 1 : 0,
    temperature:  enNum(document.getElementById('aiTemp').value.trim()),
    max_tokens:   enNum(document.getElementById('aiMaxTok').value.trim()),
    history:      enNum(document.getElementById('aiHistory').value.trim()),
    timeout:      enNum(document.getElementById('aiTimeout').value.trim()),
    rate_hour:    enNum(document.getElementById('aiRate').value.trim()),
    providers:    collectProviderPayload()
  });
  if(!d.ok){ toast(d.error||'ذخیره نشد', false); return; }
  toast('ذخیره شد');
  loadAi();
}

async function clearProviderKey(k){
  if(!confirm('کلید API این پروایدر پاک شود؟')) return;
  const d = await api('ai_key_clear', {provider:k});
  if(!d.ok){ toast(d.error||'پاک نشد', false); return; }
  toast('کلید پاک شد'); loadAi();
}

async function testProvider(k){
  const box = document.getElementById('provTest_'+k);
  if(box) box.innerHTML = '<span class="muted">در حال تست…</span>';
  const d = await api('ai_test_provider', {provider:k});
  if(!box) return;
  box.innerHTML = d.ok
    ? `<span class="tag tag-ok">موفق</span> <span class="muted">${esc(d.text||'')}</span>`
    : `<span class="tag tag-warn">ناموفق</span> ${esc(d.error||'')}${d.code?` <span class="muted">(کد ${faNum(d.code)})</span>`:''}`;
}

async function testAi(){
  const box = document.getElementById('aiTestResult');
  box.innerHTML = '<span class="muted">در حال تست…</span>';
  const d = await api('ai_test');
  box.innerHTML = d.ok
    ? `<span class="tag tag-ok">اتصال برقرار است</span> <span class="muted">${esc(d.text||'')}</span>${d.provider?` <span class="muted">— ${esc(PROVIDERS[d.provider]?PROVIDERS[d.provider].label:d.provider)}</span>`:''}`
    : `<span class="tag tag-warn">ناموفق</span> ${esc(d.error||'')}${d.code?` <span class="muted">(کد ${faNum(d.code)})</span>`:''}`;
}

async function saveSupport(){
  if(!SUP_DAYS.length && document.getElementById('supPresence').value==='auto'){
    toast('حداقل یک روز کاری انتخاب کن، وگرنه تیم همیشه آفلاین حساب می‌شود', false); return;
  }
  const d = await api('support_save', {
    presence: document.getElementById('supPresence').value,
    tz:       document.getElementById('supTz').value,
    days:     SUP_DAYS.join(','),
    from:     enNum(document.getElementById('supFrom').value.trim()),
    to:       enNum(document.getElementById('supTo').value.trim())
  });
  if(!d.ok){ toast(d.error||'ذخیره نشد', false); return; }
  toast('ساعت کاری ذخیره شد');
  loadAi();
}

// ---- پایگاه دانش هوش مصنوعی (مورد ۱۲: منبع دانش پاسخ‌گویی) ----
let KB_ITEMS = [];

async function loadKb(){
  const d = await api('aikb_list');
  if(!d.ok){ return; } // فقط admin؛ اگر دسترسی نبود بی‌صدا رد شو
  const up = document.getElementById('kbNeedUpgrade');
  if(up) up.style.display = (d.ready === 1) ? 'none' : '';
  KB_ITEMS = d.items || [];
  renderKb();
}

function renderKb(){
  const box = document.getElementById('kbList');
  if(!box) return;
  if(!KB_ITEMS.length){
    box.innerHTML = '<div class="note">هنوز مدخلی نداری. اولین دانش را بالا اضافه کن یا یک فایل متنی درون‌ریزی کن.</div>';
    return;
  }
  box.innerHTML = KB_ITEMS.map(it=>{
    const pin = Number(it.pinned)===1 ? ' <span class="tag tag-accent" style="font-size:10px">📌 همیشه</span>' : '';
    const off = Number(it.is_active)===1 ? '' : ' <span class="tag tag-mute" style="font-size:10px">غیرفعال</span>';
    const kw  = it.keywords ? `<div class="muted" style="font-size:11px;margin-top:2px">کلیدواژه: ${esc(it.keywords)}</div>` : '';
    const body = String(it.content||'');
    return `<div class="card" style="padding:10px 12px;margin-bottom:8px">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start">
        <div style="min-width:0">
          <div style="font-weight:600">${esc(it.title)}${pin}${off}</div>
          <div class="muted" style="font-size:12px;margin-top:3px;white-space:pre-wrap;word-break:break-word">${esc(body.slice(0,240))}${body.length>240?'…':''}</div>
          ${kw}
        </div>
        <div style="display:flex;gap:6px;flex-shrink:0">
          <button class="btn btn-ghost btn-sm" onclick="editKb(${it.id})">ویرایش</button>
          <button class="btn btn-danger-ghost btn-sm" onclick="deleteKb(${it.id})">حذف</button>
        </div>
      </div>
    </div>`;
  }).join('');
}

async function saveKb(){
  const title = document.getElementById('kbTitle').value.trim();
  const content = document.getElementById('kbContent').value.trim();
  if(!title){ toast('عنوان لازم است', false); return; }
  if(!content){ toast('محتوا لازم است', false); return; }
  const d = await api('aikb_save', {
    id:        document.getElementById('kbId').value || '',
    title:     title,
    keywords:  document.getElementById('kbKeywords').value.trim(),
    content:   content,
    pinned:    document.getElementById('kbPinned').checked ? 1 : 0,
    is_active: document.getElementById('kbActive').checked ? 1 : 0
  });
  if(!d.ok){ toast(d.error||'ذخیره نشد', false); return; }
  toast('ذخیره شد'); resetKb(); loadKb();
}

function editKb(id){
  const it = KB_ITEMS.find(x=>Number(x.id)===Number(id));
  if(!it) return;
  document.getElementById('kbId').value = it.id;
  document.getElementById('kbTitle').value = it.title||'';
  document.getElementById('kbKeywords').value = it.keywords||'';
  document.getElementById('kbContent').value = it.content||'';
  document.getElementById('kbPinned').checked = Number(it.pinned)===1;
  document.getElementById('kbActive').checked = Number(it.is_active)===1;
  document.getElementById('kbCancelBtn').style.display = '';
}

function resetKb(){
  document.getElementById('kbId').value = '';
  document.getElementById('kbTitle').value = '';
  document.getElementById('kbKeywords').value = '';
  document.getElementById('kbContent').value = '';
  document.getElementById('kbPinned').checked = false;
  document.getElementById('kbActive').checked = true;
  document.getElementById('kbCancelBtn').style.display = 'none';
}

async function deleteKb(id){
  if(!confirm('این مدخل حذف شود؟')) return;
  const d = await api('aikb_delete', {id:id});
  if(!d.ok){ toast(d.error||'حذف نشد', false); return; }
  toast('حذف شد'); loadKb();
}

async function importKb(){
  const inp = document.getElementById('kbFile');
  const f = inp.files[0];
  if(!f){ toast('اول یک فایل متنی انتخاب کن', false); return; }
  const fd = new FormData(); fd.append('file', f);
  const d = await api('aikb_import', fd, true);
  if(!d.ok){ toast(d.error||'درون‌ریزی نشد', false); return; }
  toast(`${faNum(d.imported||0)} مدخل اضافه شد`);
  inp.value = '';
  loadKb();
}

loadAi();
loadKb();

// ---- ۱.۰.۱۴: فهرست مدل‌ها، وضعیت اتصال، مصرف و هزینه ----
let USAGE_DAYS = 30, USAGE = null;
async function fetchModels(k){
  const card = document.querySelector(`#providerList [data-prov="${k}"]`); if(!card) return;
  const box = document.getElementById('provTest_'+k);
  if(box) box.innerHTML = '<span class="muted">در حال دریافت مدل‌ها…</span>';
  const keyEl = card.querySelector('.prov-key'), baseEl = card.querySelector('.prov-base');
  const d = await api('ai_models', {provider:k, api_key:keyEl ? keyEl.value : '', base_url:baseEl ? baseEl.value.trim() : ''});
  if(!d.ok){ if(box) box.innerHTML = `<span class="tag tag-warn">ناموفق</span> ${esc(d.error||'')}`; return; }
  const dl = document.getElementById('provModels_'+k);
  if(dl) dl.innerHTML = d.models.map(m=>`<option value="${esc(m)}">`).join('');
  if(box) box.innerHTML = `<span class="tag tag-ok">${faNum(d.models.length)} مدل</span> <span class="muted">روی «نام مدل» کلیک کنید و از فهرست انتخاب کنید، بعد ذخیره کنید.</span>`;
  const mi = card.querySelector('.prov-model'); if(mi){ mi.focus(); }
}
function provStatusHtml(p){
  const s = p.status; if(!s) return '<span class="muted">هنوز درخواستی ثبت نشده</span>';
  const okNewer = s.ok_at && (!s.err_at || s.ok_at >= s.err_at);
  return okNewer
    ? `<span class="tag tag-ok">اتصال سالم</span> <span class="muted">آخرین پاسخ موفق: ${esc(faNum(s.ok_at))}</span>`
    : `<span class="tag tag-warn">خطا</span> <span class="muted">${esc(faNum(s.err_at||''))}${s.code?' · کد '+faNum(s.code):''}</span> ${esc(s.err||'')}`;
}
// افزودن دکمه‌ی «دریافت مدل‌ها»، datalist و وضعیت به کارت هر پروایدر (بعد از هر بار رندر)
const _renderProviders = renderProviders;
renderProviders = function(){
  _renderProviders();
  document.querySelectorAll('#providerList [data-prov]').forEach(card=>{
    const k = card.getAttribute('data-prov'), p = PROVIDERS[k] || {};
    const mi = card.querySelector('.prov-model');
    if(mi && !document.getElementById('provModels_'+k)){ mi.setAttribute('list', 'provModels_'+k); mi.insertAdjacentHTML('afterend', `<datalist id="provModels_${k}"></datalist>`); }
    const be = card.querySelector('.prov-base');
    if(be && p.default_url && !be.value) be.value = p.default_url;
    const ke = card.querySelector('.prov-key');
    if(ke && p.api_key_set) ke.placeholder = `${p.key_hint || '••••'} (ذخیره شده${p.encrypted ? '، رمزنگاری‌شده' : ''})`;
    const bar = card.querySelector('#provTest_'+k);
    if(bar){
      bar.insertAdjacentHTML('beforebegin', `<button class="btn btn-ghost btn-sm" onclick="fetchModels('${k}')">دریافت فهرست مدل‌ها</button>`);
      bar.parentElement.insertAdjacentHTML('afterend', `<div class="note" style="margin:8px 0 0" id="provStat_${k}">${provStatusHtml(p)}</div>`);
    }
  });
};
// نتیجه‌ی تست، وضعیت کارت را هم به‌روز کند
const _testProvider = testProvider;
testProvider = async function(k){ await _testProvider(k); const d = await api('ai_settings'); if(d.ok && d.ai && d.ai.providers){ PROVIDERS = d.ai.providers; const el = document.getElementById('provStat_'+k); if(el) el.innerHTML = provStatusHtml(PROVIDERS[k]||{}); } loadUsage(USAGE_DAYS); };

const SRC_LABEL = {agent:'دستیار فروش', autoreply:'پاسخ خودکار', draft:'پیش‌نویس', test:'تست', sim:'آزمایش گفتگو', ai:'سایر'};
const nf0 = n => faNum(Math.round(+n||0).toLocaleString('en-US'));
async function loadUsage(days){
  USAGE_DAYS = days || USAGE_DAYS;
  document.querySelectorAll('#aiUsageDays [data-d]').forEach(b=>{ const on = +b.dataset.d === USAGE_DAYS; b.classList.toggle('on', on); b.style.cssText = on ? 'background:var(--accent-soft);color:var(--accent-ink);border-color:var(--accent)' : ''; });
  const d = await api('ai_usage', {days:USAGE_DAYS});
  const box = document.getElementById('aiUsage'); if(!box) return;
  if(!d.ok){ box.innerHTML = `<div class="note">${esc(d.error||'بارگذاری نشد')}</div>`; return; }
  USAGE = d;
  const t = d.totals || {}, req = +t.req||0, ok = +t.ok||0;
  const kpi = (l,v,s='') => `<div class="card" style="padding:10px 12px;flex:1;min-width:130px;border:1px solid var(--line)"><div class="muted" style="font-size:12px">${l}</div><div style="font-size:18px;font-weight:700">${v}</div>${s?`<div class="muted" style="font-size:11px">${s}</div>`:''}</div>`;
  const prices = d.prices || {};
  box.innerHTML = `
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
      ${kpi('درخواست‌ها', nf0(req), req ? 'موفق ' + faNum(Math.round(100*ok/req)) + '٪' : '')}
      ${kpi('توکن ورودی', nf0(t.pt))}${kpi('توکن خروجی', nf0(t.ct))}
      ${kpi('هزینه‌ی تخمینی', nf0(t.cost) + ' تومان', Object.keys(prices).length ? '' : 'قیمت مدل‌ها را پایین وارد کنید')}
      ${kpi('میانگین زمان پاسخ', t.ms ? faNum((t.ms/1000).toFixed(1)) + ' ثانیه' : '—')}
    </div>
    ${(d.by_model||[]).length ? `<div style="overflow:auto"><table class="table" style="width:100%"><thead><tr><th>پروایدر</th><th>مدل</th><th>درخواست</th><th>خطا</th><th>توکن ورودی / خروجی</th><th>هزینه (تومان)</th></tr></thead><tbody>
      ${d.by_model.map(r=>`<tr><td>${esc((PROVIDERS[r.provider]||{}).label||r.provider)}</td><td class="mono" dir="ltr">${esc(r.model)}</td><td>${nf0(r.req)}</td><td>${(+r.req - +r.ok) ? `<span class="tag tag-warn">${nf0(+r.req - +r.ok)}</span>` : '۰'}</td><td>${nf0(r.pt)} / ${nf0(r.ct)}</td><td>${nf0(r.cost)}</td></tr>`).join('')}
    </tbody></table></div>` : '<div class="muted">در این بازه درخواستی ثبت نشده است.</div>'}
    ${(d.by_source||[]).length ? `<div style="display:flex;gap:6px;flex-wrap:wrap;margin:10px 0">${d.by_source.map(r=>`<span class="tag">${esc(SRC_LABEL[r.source]||r.source)}: ${nf0(r.req)} درخواست · ${nf0(r.cost)} تومان</span>`).join('')}</div>` : ''}
    ${(d.errors||[]).length ? `<details style="margin:10px 0"><summary style="cursor:pointer">آخرین خطاها (${faNum(d.errors.length)})</summary><div style="margin-top:8px">${d.errors.map(e=>`<div class="note" style="margin:4px 0">${esc(faNum(e.created_at))} · ${esc((PROVIDERS[e.provider]||{}).label||e.provider)} <span class="mono" dir="ltr">${esc(e.model)}</span>${e.http?' · کد '+faNum(e.http):''} — ${esc(e.error||'')}</div>`).join('')}</div></details>` : ''}
    <details style="margin-top:10px"><summary style="cursor:pointer;font-weight:600">قیمت مدل‌ها (تومان به ازای ۱ میلیون توکن)</summary>
      <div style="overflow:auto;margin-top:8px"><table class="table" style="width:100%" id="aiPriceTbl"><thead><tr><th>مدل</th><th>ورودی</th><th>خروجی</th></tr></thead><tbody>
        ${(d.models||[]).map(m=>`<tr data-m="${esc(m)}"><td class="mono" dir="ltr">${esc(m)}</td><td><input class="mono pr-in" dir="ltr" value="${esc((prices[m]||{}).in||'')}" placeholder="0" style="max-width:140px"></td><td><input class="mono pr-out" dir="ltr" value="${esc((prices[m]||{}).out||'')}" placeholder="0" style="max-width:140px"></td></tr>`).join('')}
        <tr data-new="1"><td><input class="mono pr-model" dir="ltr" placeholder="نام مدل دیگر"></td><td><input class="mono pr-in" dir="ltr" placeholder="0" style="max-width:140px"></td><td><input class="mono pr-out" dir="ltr" placeholder="0" style="max-width:140px"></td></tr>
      </tbody></table></div>
      <button class="btn btn-primary btn-sm" style="margin-top:8px" onclick="savePrices()">ذخیره‌ی قیمت‌ها</button>
      <span class="note" style="margin:0 8px">هزینه‌ی درخواست‌های بعدی با این قیمت‌ها حساب می‌شود.</span>
    </details>`;
}
async function savePrices(){
  const prices = {};
  document.querySelectorAll('#aiPriceTbl tbody tr').forEach(tr=>{
    const m = tr.dataset.new ? (tr.querySelector('.pr-model').value||'').trim() : tr.dataset.m;
    if(!m) return;
    prices[m] = {in: enNum(tr.querySelector('.pr-in').value.trim()), out: enNum(tr.querySelector('.pr-out').value.trim())};
  });
  const d = await api('ai_prices_save', {prices});
  if(!d.ok){ toast(d.error||'ذخیره نشد', false); return; }
  toast('قیمت‌ها ذخیره شد'); loadUsage(USAGE_DAYS);
}
loadUsage(30);
