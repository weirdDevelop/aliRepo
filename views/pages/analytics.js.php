// pages/analytics.js.php

// ==================== تب‌ها ====================
const AN_TABS = ['web','realtime','pages','audience','behavior','sessions','funnels','legacy','settings'];
let anLoaded = {};
let AN_SITE_URL = ''; // از تنظیمات پر می‌شود (loadAnSettings) — برای لینک واقعی صفحات پربازدید

function anTab(name){
  AN_TABS.forEach(t=>{
    const pane = document.getElementById('pane'+t.charAt(0).toUpperCase()+t.slice(1));
    const btn = document.getElementById('tab'+t.charAt(0).toUpperCase()+t.slice(1));
    if(pane) pane.style.display = (t===name) ? '' : 'none';
    if(btn) btn.classList.toggle('on', t===name);
  });
  if(!anLoaded[name]){
    anLoaded[name] = true;
    if(name==='web') loadAnWeb();
    else if(name==='realtime') loadAnRealtime();
    else if(name==='pages') loadAnPages();
    else if(name==='audience') loadAnAudience();
    else if(name==='behavior') loadAnHeatmapPages();
    else if(name==='sessions') loadAnSessions();
    else if(name==='funnels') loadAnFunnels();
    else if(name==='legacy'){ loadAnLinks(); loadAnEngagement(); }
    else if(name==='settings') loadAnSettings();
  }
  if(name==='realtime') anStartRealtimePoll();
  else anStopRealtimePoll();
}

// ==================== ابزار مشترک ====================

/**
 * لینک کوتاه‌شده و قابل‌کلیک برای آدرس صفحه — مورد ۲: آدرس‌های طولانی ظاهر
 * جدول‌ها را به‌هم می‌ریختند. با CSS کوتاه می‌شود (تیتر کامل روی هاور) و با
 * کلیک صفحه‌ی واقعی باز می‌شود (فرض: سایتِ ردیابی‌شده هم‌دامنه‌ی همین پنل است
 * — رایج‌ترین حالت نصب خوداستقرار؛ اگر دامنه فرق داشت، حداقل کوتاه و مرتب می‌ماند).
 */
function anUrlLink(url){
  if(!url) return '—';
  // اگر آدرس پایه‌ی سایت را در تنظیمات ذخیره کرده باشیم، لینک واقعی همان سایت ساخته می‌شود؛
  // چون پنل ادمین معمولاً روی دامنه‌ی دیگری میزبانی می‌شود، location.origin اینجا اشتباه است
  // (همان چیزی که کاربر گزارش داد: لینک‌ها به‌جای سایتش به پنل ادمین اشاره می‌کردند).
  const base = AN_SITE_URL || location.origin;
  let href = url;
  try { href = new URL(url, base).href; } catch(e) {}
  return `<a class="an-url-link" href="${esc(href)}" target="_blank" rel="noopener" title="${esc(url)}">${esc(url)}</a>`;
}

function anPersonLink(name, personId){
  const label = esc(name || 'ناشناس');
  const initial = (name || '?').trim().charAt(0) || '?';
  return `<a class="an-person-link" href="index.php?page=inbox&person=${personId}" title="مشاهده در صندوق ورودی">
    <span class="an-person-avatar">${esc(initial)}</span>${label}
  </a>`;
}

function anBarList(rows, nameKey, countKey, opts){
  opts = opts || {};
  if(!rows || !rows.length) return '<div class="muted">داده‌ای نیست</div>';
  const max = Math.max(...rows.map(r=>+r[countKey]||0)) || 1;
  return rows.map(r=>{
    const label = opts.label ? opts.label(r) : esc(r[nameKey]||'—');
    return `<div class="bd-row">
      <span class="bd-name" title="${esc(r[nameKey]||'')}">${label}</span>
      <span class="bd-bar"><span class="bd-fill" style="width:${Math.round((+r[countKey]||0)/max*100)}%"></span></span>
      <span class="bd-num">${faNum(r[countKey])}</span>
    </div>`;
  }).join('');
}

function anLineChart(days, series){
  if(!days || !days.length) return '<div class="muted">داده‌ای نیست</div>';
  const n = days.length;
  const W = 900, H = 180, pad = 24;
  const maxV = Math.max(1, ...series.flatMap(s=>s.values));
  const stepX = (W - 2*pad) / Math.max(1, n-1);
  const colors = ['var(--accent)', '#f59e0b', '#10b981', '#ef4444'];
  const lines = series.map((s,si)=>{
    const pts = s.values.map((v,i)=> `${(pad+i*stepX).toFixed(1)},${(H-pad-(v/maxV)*(H-2*pad)).toFixed(1)}`).join(' ');
    return `<polyline points="${pts}" fill="none" stroke="${s.color||colors[si%colors.length]}" stroke-width="2"/>`;
  }).join('');
  const legend = series.map((s,si)=>`<span style="display:inline-flex;align-items:center;gap:4px;margin-inline-end:12px;font-size:11px">
    <span style="width:9px;height:9px;border-radius:2px;background:${s.color||colors[si%colors.length]};display:inline-block"></span>${esc(s.name)}</span>`).join('');
  const step = Math.max(1, Math.floor(n/8));
  const ticks = [];
  for(let i=0;i<n;i+=step){
    ticks.push(`<text x="${(pad+i*stepX).toFixed(1)}" y="${H-4}" font-size="9" fill="var(--ink-faint)" text-anchor="middle">${toFa(days[i]).slice(5)}</text>`);
  }
  return `<div style="margin-bottom:6px">${legend}</div>
    <svg viewBox="0 0 ${W} ${H}" style="width:100%;height:${H}px;direction:ltr">${lines}${ticks.join('')}</svg>`;
}

function anDailyChart(days, counts){
  if(!days || !days.length) return '<div class="muted">داده‌ای نیست</div>';
  const n = days.length;
  const W = 900, H = 160, pad = 20;
  const max = Math.max(1, ...counts);
  const bw = (W - 2*pad) / n;
  const bars = counts.map((c,i)=>{
    const h = (c/max) * (H - 2*pad - 14);
    const x = pad + i*bw;
    const y = H - pad - h;
    return `<rect x="${(x+bw*0.15).toFixed(1)}" y="${y.toFixed(1)}" width="${(bw*0.7).toFixed(1)}" height="${h.toFixed(1)}" rx="2" fill="var(--brand)" fill-opacity="0.85"><title>${toFa(days[i])}: ${faNum(c)} کلیک</title></rect>`;
  }).join('');
  const step = Math.max(1, Math.floor(n/8));
  const ticks = [];
  for(let i=0;i<n;i+=step){
    ticks.push(`<text x="${(pad+i*bw+bw/2).toFixed(1)}" y="${H-4}" font-size="9" fill="var(--ink-faint)" text-anchor="middle">${toFa(days[i]).slice(5)}</text>`);
  }
  return `<svg viewBox="0 0 ${W} ${H}" style="width:100%;height:${H}px;direction:ltr">${bars}${ticks.join('')}</svg>`;
}

// ==================== وب (نمای کلی) ====================

async function loadAnWeb(){
  const days = document.getElementById('anWebDays').value;
  const d = await api('analytics_overview', {days});
  if(!d.ok){
    document.getElementById('anLockedNotice').style.display = '';
    document.getElementById('anWebCards').innerHTML = '';
    document.getElementById('anWebPvChart').innerHTML = '';
    document.getElementById('anWebSesChart').innerHTML = '';
    return;
  }
  document.getElementById('anLockedNotice').style.display = 'none';
  const s = d.summary;
  document.getElementById('anWebCards').innerHTML = `
    <div class="an-card"><div class="num">${faNum(s.pageviews)}</div><div class="lbl">بازدید صفحه</div></div>
    <div class="an-card"><div class="num">${faNum(s.sessions)}</div><div class="lbl">نشست</div></div>
    <div class="an-card"><div class="num">${faNum(s.visitors_new)}</div><div class="lbl">بازدیدکننده‌ی تازه</div></div>
    <div class="an-card"><div class="num">${faNum(s.avg_bounce_rate)}٪</div><div class="lbl">نرخ خروج تک‌صفحه‌ای</div></div>
    <div class="an-card"><div class="num">${faNum(Math.round(s.avg_duration_sec/60*10)/10)}</div><div class="lbl">میانگین دقیقه در نشست</div></div>`;

  const t = d.today || {};
  document.getElementById('anTodayCards').innerHTML = `
    <div class="an-card"><div class="num">${faNum(t.unique_visitors||0)}</div><div class="lbl">بازدیدکننده‌ی یکتای امروز</div></div>
    <div class="an-card"><div class="num">${faNum(t.sessions||0)}</div><div class="lbl">کل نشست‌های امروز</div></div>
    <div class="an-card"><div class="num">${faNum(t.via_link||0)}</div><div class="lbl">ورودی از لینک رهگیری</div></div>
    <div class="an-card"><div class="num">${faNum(t.via_direct_or_other||0)}</div><div class="lbl">ورودی مستقیم/سایر منابع</div></div>`;
  document.getElementById('anTodayLinks').innerHTML = (t.top_links && t.top_links.length)
    ? '<div class="note" style="margin-bottom:6px;font-weight:600">پرترافیک‌ترین لینک‌های رهگیری امروز</div>' +
      anBarList(t.top_links, 'title', 'c', { label: r => esc(r.title || r.link_code) })
    : '';

  document.getElementById('anWebPvChart').innerHTML = anDailyChart(d.days, d.pageviews);
  document.getElementById('anWebSesChart').innerHTML = anLineChart(d.days, [
    {name:'تازه', values:d.visitors_new, color:'#10b981'},
    {name:'برگشتی', values:d.visitors_returning, color:'#f59e0b'},
  ]);
}

// ==================== لحظه‌ای ====================

let anRtTimer = null;
function anStartRealtimePoll(){
  if(anRtTimer) return;
  anRtTimer = setInterval(loadAnRealtime, 15000);
}
function anStopRealtimePoll(){
  if(anRtTimer){ clearInterval(anRtTimer); anRtTimer = null; }
}

async function loadAnRealtime(){
  const d = await api('analytics_realtime', {});
  if(!d.ok) return;
  document.getElementById('anRtCount').textContent = faNum(d.count);
  const pages = Object.entries(d.top_pages||{}).map(([url,c])=>({url, c}));
  document.getElementById('anRtPages').innerHTML = anBarList(pages, 'url', 'c');
  const rows = d.visitors||[];
  document.getElementById('anRtTable').innerHTML = !rows.length ? '<div class="muted">همین الان کسی روی سایت نیست</div>' : `
    <table class="tbl">
      <thead><tr><th>صفحه</th><th>دستگاه</th><th>مرورگر</th><th>کشور</th></tr></thead>
      <tbody>${rows.map(r=>`<tr>
        <td class="an-url-cell" dir="ltr">${anUrlLink(r.current_page)}</td>
        <td>${esc(r.device||'—')}</td>
        <td>${esc(r.browser||'—')}</td>
        <td>${esc(r.city ? (r.city+'، '+r.country) : (r.country||'—'))}</td>
      </tr>`).join('')}</tbody>
    </table>`;
}

// ==================== صفحات و منابع ====================

async function loadAnPages(){
  const days = document.getElementById('anPagesDays').value;
  const d = await api('analytics_pages_sources', {days});
  if(!d.ok) return;
  document.getElementById('anTopPages').innerHTML = anBarList(d.topPages, 'url', 'views', {
    label: r => anUrlLink(r.url)
  });
  document.getElementById('anSources').innerHTML = anBarList(d.sources, 'source', 'c');
  document.getElementById('anEntryPages').innerHTML = anBarList(d.entryPages, 'url', 'c', {
    label: r => anUrlLink(r.url)
  });
  document.getElementById('anExitPages').innerHTML = anBarList(d.exitPages, 'url', 'c', {
    label: r => anUrlLink(r.url)
  });
  document.getElementById('anCampaigns').innerHTML = !d.campaigns.length ? '<div class="muted">هنوز کمپینی با UTM ثبت نشده</div>' : `
    <table class="tbl">
      <thead><tr><th>منبع</th><th>رسانه</th><th>کمپین</th><th>نشست</th></tr></thead>
      <tbody>${d.campaigns.map(c=>`<tr>
        <td>${esc(c.utm_source||'—')}</td><td>${esc(c.utm_medium||'—')}</td>
        <td>${esc(c.utm_campaign||'—')}</td><td>${faNum(c.c)}</td>
      </tr>`).join('')}</tbody>
    </table>`;
}

// ==================== مخاطبان ====================

async function loadAnAudience(){
  const days = document.getElementById('anAudDays').value;
  const d = await api('analytics_audience', {days});
  if(!d.ok) return;
  document.getElementById('anDevices').innerHTML = anBarList(d.devices, 'd', 'c');
  document.getElementById('anBrowsers').innerHTML = anBarList(d.browsers, 'b', 'c');
  document.getElementById('anOs').innerHTML = anBarList(d.os, 'o', 'c');
  document.getElementById('anCountries').innerHTML = anBarList(d.countries, 'country', 'c');
  document.getElementById('anIdentified').innerHTML = !d.identified.length ? '<div class="muted">هنوز بازدیدکننده‌ای شناسایی نشده</div>' : `
    <table class="tbl">
      <thead><tr><th>مشتری</th><th>تعداد بازدید</th><th>آخرین بازدید</th></tr></thead>
      <tbody>${d.identified.map(r=>`<tr>
        <td>${anPersonLink(r.full_name || r.phone, r.person_id)}</td>
        <td>${faNum(r.visits_count)}</td>
        <td class="muted" style="font-size:11.5px">${toFa(r.last_seen)}</td>
      </tr>`).join('')}</tbody>
    </table>`;
}

// ==================== سفر بازدیدکننده‌ها (مورد ۳) ====================

const AN_SOURCE_LABEL = (s) => {
  if (s.link_code) return '🔗 لینک رهگیری';
  if (s.utm_source) return '📣 ' + esc(s.utm_source);
  if (!s.referrer) return 'مستقیم';
  try { return new URL(s.referrer).hostname; } catch(e) { return 'ارجاعی'; }
};

async function loadAnSessions(){
  const days = document.getElementById('anSesDays').value;
  const d = await api('analytics_sessions_list', {days});
  const box = document.getElementById('anSessionsList');
  if(!d.ok || !d.sessions.length){ box.innerHTML = '<div class="muted">نشستی در این بازه نیست</div>'; return; }
  box.innerHTML = d.sessions.map(s=>{
    const who = s.full_name || (s.person_id ? 'مشتری #'+s.person_id : ('بازدیدکننده '+s.visitor_uid.slice(0,6)));
    return `<div class="an-ses-row" id="anSesRow${s.id}" onclick="loadAnSessionDetail(${s.id})">
      <div>${esc(who)} — ${AN_SOURCE_LABEL(s)}</div>
      <div class="an-ses-meta">${toFa(s.started_at)} · ${esc(s.device||'—')} · ${esc(s.country||'نامعلوم')} · ${faNum(s.page_count)} صفحه · ${faNum(s.duration_sec)} ثانیه</div>
    </div>`;
  }).join('');
}

async function loadAnSessionDetail(id){
  document.querySelectorAll('.an-ses-row').forEach(el=>el.classList.remove('on'));
  document.getElementById('anSesRow'+id)?.classList.add('on');
  const box = document.getElementById('anSessionDetail');
  box.innerHTML = '<div class="muted">در حال بارگذاری…</div>';
  const d = await api('analytics_session_detail', {id});
  if(!d.ok){ box.innerHTML = '<div class="muted">'+esc(d.error||'خطا')+'</div>'; return; }
  const s = d.session;
  const who = s.full_name || s.phone || ('بازدیدکننده '+s.visitor_uid.slice(0,8));

  let html = `<div style="font-weight:700;margin-bottom:4px">${esc(who)}</div>
    <div class="an-ses-meta" style="margin-bottom:14px">
      از کجا آمد: <b>${AN_SOURCE_LABEL(s)}</b>${s.link_code ? ' ('+esc(s.link_code)+')' : ''}<br>
      دستگاه: ${esc(s.device||'—')} · مرورگر: ${esc(s.browser||'—')} · سیستم‌عامل: ${esc(s.os||'—')}<br>
      جغرافیا: ${esc(s.city ? (s.city+'، '+s.country) : (s.country||'نامعلوم'))}<br>
      شروع: ${toFa(s.started_at)} · مجموع: ${faNum(s.page_count)} صفحه، ${faNum(s.duration_sec)} ثانیه
    </div>
    <div style="font-weight:600;margin-bottom:8px;font-size:12.5px">مسیر بازدید (به ترتیب)</div>`;

  if(!d.pageviews.length){
    html += '<div class="muted">صفحه‌ای ثبت نشده</div>';
  } else {
    html += d.pageviews.map(pv=>{
      const ev = pv.events || {};
      const badges = [];
      if(ev.click) badges.push(`<span>🖱 ${faNum(ev.click)} کلیک</span>`);
      if(ev.rage_click) badges.push(`<span style="color:var(--warn)">⚠ ${faNum(ev.rage_click)} رِیج‌کلیک</span>`);
      if(ev.dead_click) badges.push(`<span>◌ ${faNum(ev.dead_click)} کلیک بی‌واکنش</span>`);
      return `<div class="an-journey-step">
        <div class="an-journey-dot"></div>
        <div style="flex:1;min-width:0">
          <div>${anUrlLink(pv.url)}</div>
          <div class="an-ses-meta">${toFa(pv.created_at)} · ${faNum(pv.time_on_page_sec||0)} ثانیه · ${faNum(pv.scroll_depth_pct||0)}٪ اسکرول</div>
          ${badges.length ? `<div class="an-journey-badges">${badges.join('')}</div>` : ''}
        </div>
      </div>`;
    }).join('');
  }
  box.innerHTML = html;
}

// ==================== رفتار (هیت‌مپ) ====================

let anHmData = null;
async function loadAnHeatmapPages(){
  const d = await api('analytics_heatmap_pages', {days:30});
  const sel = document.getElementById('anHmPage');
  if(!d.ok || !d.pages || !d.pages.length){
    sel.innerHTML = '<option value="">هنوز داده‌ای نیست</option>';
    loadAnProblemSpots();
    return;
  }
  sel.innerHTML = d.pages.map(p=>`<option value="${esc(p.page_url)}">${esc(p.page_url)} (${faNum(p.total)})</option>`).join('');
  sel.onchange = loadAnHeatmap;
  loadAnHeatmap();
  loadAnProblemSpots();
}

async function loadAnHeatmap(){
  const url = document.getElementById('anHmPage').value;
  if(!url) return;
  const bucket = document.getElementById('anHmBucket').value;
  const days = document.getElementById('anHmDays').value;
  const d = await api('analytics_heatmap', {page_url: url, bucket, days});
  if(!d.ok) return;
  anHmData = d;
  drawAnHeatmap();
}

function drawAnHeatmap(){
  const canvas = document.getElementById('anHmCanvas');
  const ctx = canvas.getContext('2d');
  if(!anHmData){ ctx.clearRect(0,0,canvas.width,canvas.height); return; }
  const kind = document.getElementById('anHmKind').value;
  const cells = (anHmData.cells||[]).filter(c=>c.kind===kind && c.grid_x!=null && c.grid_y!=null);
  const cs = anHmData.cell_size || 40;

  if(!cells.length){
    ctx.clearRect(0,0,canvas.width,canvas.height);
    ctx.save();
    ctx.fillStyle = '#98a0b3';
    ctx.font = '13px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('برای این ترکیب (صفحه/دستگاه/نوع/بازه) هنوز داده‌ای نیست', canvas.width/2, canvas.height/2);
    ctx.restore();
    return;
  }

  // مورد ۴: اندازه‌ی canvas را متناسب با بیشینه‌ی مختصات واقعیِ داده تنظیم می‌کنیم —
  // قبلاً اندازه‌ی ثابت ۱۲۸۰×۸۰۰ فرض می‌شد که با ویوپورت واقعیِ بازدیدکننده‌ها
  // (به‌خصوص موبایل یا مانیتورهای بزرگ‌تر) جور در نمی‌آمد و نقاط بیرون از کادر
  // رسم می‌شدند — یعنی هیت‌مپ با وجود داده، خالی به‌نظر می‌رسید.
  const maxGx = Math.max(...cells.map(c=>c.grid_x));
  const maxGy = Math.max(...cells.map(c=>c.grid_y));
  const w = Math.min(2400, (maxGx + 2) * cs);
  const h = Math.min(2400, (maxGy + 2) * cs);
  canvas.width = w;
  canvas.height = h;

  ctx.clearRect(0, 0, w, h);
  const max = Math.max(...cells.map(c=>+c.hits));
  cells.forEach(c=>{
    const t = Math.min(1, (+c.hits)/max);
    const hue = Math.round(120 - 120*t);
    ctx.fillStyle = `hsla(${hue}, 85%, 50%, ${0.25 + t*0.55})`;
    ctx.fillRect(c.grid_x*cs, c.grid_y*cs, cs, cs);
  });
}

async function loadAnProblemSpots(){
  const d = await api('analytics_problem_spots', {});
  const box = document.getElementById('anProblemSpots');
  if(!d.ok || !d.rows.length){ box.innerHTML = '<div class="muted">فعلاً نقطه‌ی مشکل‌داری ثبت نشده</div>'; return; }
  box.innerHTML = `
    <table class="tbl">
      <thead><tr><th>صفحه</th><th>نوع</th><th>تعداد</th><th>آخرین بار</th></tr></thead>
      <tbody>${d.rows.map(r=>`<tr>
        <td class="an-url-cell" dir="ltr">${anUrlLink(r.page_url)}</td>
        <td>${r.kind==='rage_click' ? '<span class="tag tag-warn">رِیج‌کلیک</span>' : '<span class="tag">کلیک بی‌واکنش</span>'}</td>
        <td>${faNum(r.hits)}</td>
        <td class="muted" style="font-size:11.5px">${toFa(r.last_seen)}</td>
      </tr>`).join('')}</tbody>
    </table>`;
}

// ==================== قیف تبدیل ====================

function anAddStep(){
  const box = document.getElementById('anFSteps');
  const row = document.createElement('div');
  row.className = 'row';
  row.style.gap = '8px';
  row.innerHTML = `
    <div class="col field" style="min-width:0"><input placeholder="عنوان مرحله" class="anFLabel"></div>
    <div class="col field" style="min-width:0"><input placeholder="/pricing" dir="ltr" class="anFUrl"></div>`;
  box.appendChild(row);
}

async function saveAnFunnel(){
  const name = document.getElementById('anFName').value.trim();
  const steps = Array.from(document.getElementById('anFSteps').children).map(row=>({
    label: row.querySelector('.anFLabel').value.trim(),
    url_contains: row.querySelector('.anFUrl').value.trim(),
  })).filter(s=>s.label && s.url_contains);
  if(!name || steps.length < 2){ toast('نام و حداقل دو مرحله لازم است', false); return; }
  const r = await api('analytics_funnel_save', {name, steps: JSON.stringify(steps)});
  if(!r.ok){ toast(r.error||'خطا', false); return; }
  toast('قیف ذخیره شد');
  document.getElementById('anFName').value = '';
  document.getElementById('anFSteps').innerHTML = '';
  anAddStep(); anAddStep();
  loadAnFunnels();
}

async function loadAnFunnels(){
  const d = await api('analytics_funnels', {});
  const box = document.getElementById('anFunnelsList');
  if(!d.ok || !d.funnels.length){ box.innerHTML = '<div class="card card-pad muted">هنوز قیفی نساخته‌ای</div>'; return; }
  box.innerHTML = '';
  for(const f of d.funnels){
    const card = document.createElement('div');
    card.className = 'card card-pad';
    card.style.marginBottom = '14px';
    card.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <div style="font-weight:700">${esc(f.name)}</div>
      <button class="btn btn-ghost btn-xs" onclick="deleteAnFunnel(${f.id})">حذف</button>
    </div><div id="anFunnelStats${f.id}"><div class="muted">در حال بارگذاری…</div></div>`;
    box.appendChild(card);
    api('analytics_funnel_stats', {id:f.id, days:30}).then(stats=>{
      const el = document.getElementById('anFunnelStats'+f.id);
      if(!stats.ok || !stats.steps.length){ el.innerHTML = '<div class="muted">داده‌ای نیست</div>'; return; }
      el.innerHTML = stats.steps.map(s=>`
        <div class="an-funnel-step">
          <div style="flex:0 0 120px;font-size:12.5px">${esc(s.label)}</div>
          <div class="an-funnel-bar"><div class="an-funnel-fill" style="width:${s.pct_of_first}%">${faNum(s.count)} (${faNum(s.pct_of_first)}٪)</div></div>
        </div>`).join('');
    });
  }
}

async function deleteAnFunnel(id){
  if(!confirm('این قیف حذف شود؟')) return;
  await api('analytics_funnel_delete', {id});
  loadAnFunnels();
}

// ==================== تعامل و لینک‌ها (قدیمی) ====================

async function loadAnLinks(){
  const days = document.getElementById('anLinkDays').value;
  const d = await api('analytics_links', {days});
  if(!d.ok) return;
  document.getElementById('anLinkChart').innerHTML = anDailyChart(d.days, d.counts);
  const box = document.getElementById('anTopLinks');
  if(!d.top_links || !d.top_links.length){ box.innerHTML = '<div class="muted">هنوز کلیکی در این بازه نیست</div>'; return; }
  const max = Math.max(...d.top_links.map(l=>+l.c||0)) || 1;
  box.innerHTML = d.top_links.map(l=>`
    <div class="bd-row">
      <span class="bd-name" title="${esc(l.target_url)}">${esc(l.title||l.code)}</span>
      <span class="bd-bar"><span class="bd-fill" style="width:${Math.round((+l.c||0)/max*100)}%"></span></span>
      <span class="bd-num">${faNum(l.c)}</span>
    </div>`).join('');
}

async function loadAnEngagement(){
  const days = document.getElementById('anEngDays').value;
  const box = document.getElementById('anEngTable');
  box.innerHTML = '<div class="muted">در حال بارگذاری…</div>';
  const d = await api('analytics_engagement', {days, limit: 100});
  if(!d.ok){ box.innerHTML = '<div class="muted">خطا</div>'; return; }
  if(!d.rows || !d.rows.length){ box.innerHTML = '<div class="muted">در این بازه پیامی رد و بدل نشده</div>'; return; }
  box.innerHTML = `
    <table class="tbl">
      <thead><tr><th>مشتری</th><th>ما فرستادیم</th><th>او فرستاد</th><th>آخرین پیام او</th><th>آخرین پیام ما</th><th></th></tr></thead>
      <tbody>
        ${d.rows.map(r=>`
          <tr style="${r.unanswered ? 'background:var(--err-soft)' : ''}">
            <td>${anPersonLink(r.full_name || r.phone, r.id)}</td>
            <td>${faNum(r.out_count)}</td>
            <td>${faNum(r.in_count)}</td>
            <td class="muted" style="font-size:11.5px">${r.last_in?toFa(r.last_in):'—'}</td>
            <td class="muted" style="font-size:11.5px">${r.last_out?toFa(r.last_out):'—'}</td>
            <td>${r.unanswered?'<span class="tag tag-warn">بی‌پاسخ</span>':''}</td>
          </tr>`).join('')}
      </tbody>
    </table>`;
}

// ==================== تنظیمات ====================

async function runAnGeoTest(){
  const box = document.getElementById('anGeoTestResult');
  box.textContent = 'در حال تست…';
  const d = await api('analytics_geoip_test', {});
  if(!d.ok){ box.textContent = 'خطا'; return; }
  let html = `IP تشخیص‌داده‌شده: <span class="mono" dir="ltr">${esc(d.detected_ip||'—')}</span><br>`;
  html += `REMOTE_ADDR خام: <span class="mono" dir="ltr">${esc(d.remote_addr||'—')}</span>`;
  if(d.x_forwarded_for) html += ` · X-Forwarded-For: <span class="mono" dir="ltr">${esc(d.x_forwarded_for)}</span>`;
  html += `<br>trust_proxy در config.php: <b>${d.trust_proxy ? 'روشن' : 'خاموش'}</b><br>`;
  html += d.geo
    ? `<span style="color:var(--ok)">✓ کشور شناسایی شد: ${esc(d.geo.country)} (${esc(d.geo.country_code)})${d.geo.city ? ' — '+esc(d.geo.city) : ''}</span>`
    : '<span style="color:var(--err)">✗ کشوری شناسایی نشد</span>';
  if(d.warning) html += `<br><span style="color:var(--warn)">⚠ ${esc(d.warning)}</span>`;
  box.innerHTML = html;
}

async function loadAnSettings(){
  const d = await api('analytics_settings_get', {});
  if(!d.ok) return;
  const s = d.settings;
  AN_SITE_URL = s.site_url || '';
  document.getElementById('anStEnabled').checked = s.enabled === '1';
  document.getElementById('anStSiteUrl').value = s.site_url || '';
  document.getElementById('anStSampHeat').value = s.sampling_heatmap;
  document.getElementById('anStSampMove').value = s.sampling_move;
  document.getElementById('anStRetention').value = s.retention_days_raw;
  document.getElementById('anStRetentionSessions').value = s.retention_days_sessions;
  document.getElementById('anStCookieless').checked = s.cookieless === '1';
  document.getElementById('anEmbed').textContent = d.embed || '…';
  document.getElementById('anGeoStatus').innerHTML = s.geoip_precision === 'city'
    ? '<span style="color:var(--ok)">✓ دقت شهر فعال است (فایل GeoLite2-City نصب شده)</span>'
    : (s.geoip_precision === 'country'
        ? '<span style="color:var(--ok)">✓ کشور ثبت می‌شود (دیتابیس همراهِ پروژه) — برای شهر هم، راهنمای اختیاری پایین را ببین</span>'
        : '<span style="color:var(--warn)">فایل‌های جغرافیا پیدا نشدند — بررسی کن پوشه‌ی data/ کامل آپلود شده باشد</span>');
}

async function saveAnSettings(){
  const payload = {
    enabled: document.getElementById('anStEnabled').checked ? '1' : '0',
    site_url: document.getElementById('anStSiteUrl').value.trim(),
    sampling_heatmap: document.getElementById('anStSampHeat').value,
    sampling_move: document.getElementById('anStSampMove').value,
    retention_days_raw: document.getElementById('anStRetention').value,
    retention_days_sessions: document.getElementById('anStRetentionSessions').value,
    cookieless: document.getElementById('anStCookieless').checked ? '1' : '0',
  };
  const r = await api('analytics_settings_save', payload);
  if(!r.ok){ toast(r.error||'خطا', false); return; }
  toast('ذخیره شد');
  loadAnSettings();
}

async function runAnRollupNow(){
  const box = document.getElementById('anRollupResult');
  box.textContent = 'در حال اجرا…';
  const r = await api('analytics_run_rollup_now', {});
  box.textContent = r.ok ? ('نتیجه: ' + r.result) : (r.error || 'خطا');
  if(r.ok) toast('رول‌آپ اجرا شد — حالا برگرد تب «وب» و دوباره ببین');
}

function anCopyEmbed(){
  const text = document.getElementById('anEmbed').textContent;
  navigator.clipboard?.writeText(text).then(()=>toast('کد نصب کپی شد.')).catch(()=>toast('کپی نشد', false));
}

// ==================== شروع ====================
anAddStep(); anAddStep();
loadAnWeb();
anLoaded['settings'] = true;
loadAnSettings(); // زودتر از هر تب دیگری، تا AN_SITE_URL برای لینک صفحات پربازدید آماده باشد
