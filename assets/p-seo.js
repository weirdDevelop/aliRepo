/* p-seo.js — سرچ کنسول گوگل و ابزارهای سئو */
(function(){
const {$, $$, E, fa, nf, pct, compact, ic, pill, sw, seg, tabs, empty, field, A, ON, UI, reg, go, toast, menu, modal, drawer, confirm, chartBox, hbars, copy, debounce} = EB;
const L = () => window.EB_LIVE;

const TABS = [['perf', 'عملکرد', 'chart-line-up'], ['opps', 'فرصت‌ها', 'lightbulb'], ['sitemaps', 'نقشه‌ی سایت', 'tree-structure'], ['urls', 'ایندکس و بازرسی صفحه‌ها', 'magnifying-glass'], ['tools', 'ابزارها', 'wrench'], ['settings', 'اتصال و تنظیمات', 'gear-six']];
const PRESETS = [['7', '۷ روز'], ['28', '۲۸ روز'], ['90', '۳ ماه'], ['180', '۶ ماه'], ['480', '۱۶ ماه'], ['c', 'دلخواه']];
const TYPES = [['web', 'وب'], ['image', 'تصویر'], ['video', 'ویدیو'], ['news', 'اخبار'], ['discover', 'دیسکاور']];
const COUNTRY = {irn:'ایران', deu:'آلمان', usa:'آمریکا', gbr:'انگلیس', tur:'ترکیه', are:'امارات', can:'کانادا', nld:'هلند', fra:'فرانسه', swe:'سوئد', aus:'استرالیا', irq:'عراق', afg:'افغانستان', tjk:'تاجیکستان', ita:'ایتالیا', aut:'اتریش', nor:'نروژ', dnk:'دانمارک', che:'سوئیس', bel:'بلژیک', esp:'اسپانیا', rus:'روسیه', ind:'هند', arm:'ارمنستان', geo:'گرجستان', omn:'عمان', qat:'قطر', kwt:'کویت', sau:'عربستان'};
const DEVICE = {MOBILE:'موبایل', DESKTOP:'دسکتاپ', TABLET:'تبلت'};
const COV = {'Submitted and indexed':'ارسال‌شده و ایندکس‌شده', 'Indexed, not submitted in sitemap':'ایندکس‌شده، نه در نقشه‌ی سایت', 'Crawled - currently not indexed':'خزیده‌شده، فعلاً ایندکس نشده', 'Discovered - currently not indexed':'کشف‌شده، فعلاً ایندکس نشده',
  'Page with redirect':'صفحه با ریدایرکت', 'Excluded by ‘noindex’ tag':'با noindex حذف شده', 'Duplicate without user-selected canonical':'تکراری بدون canonical', 'Duplicate, Google chose different canonical than user':'تکراری؛ گوگل canonical دیگری انتخاب کرده',
  'Alternate page with proper canonical tag':'صفحه‌ی جایگزین با canonical درست', 'Not found (404)':'پیدا نشد (۴۰۴)', 'Soft 404':'۴۰۴ نرم', 'Blocked by robots.txt':'مسدود با robots.txt', 'URL is unknown to Google':'گوگل این آدرس را نمی‌شناسد', 'Server error (5xx)':'خطای سرور (5xx)'};
const VERD = {PASS:['ایندکس‌شده', 'ok'], FAIL:['ایندکس نشده', 'err'], NEUTRAL:['ایندکس نشده (مستثنی)', 'warn'], PARTIAL:['بخشی', 'warn']};
let G = {tab:'perf', state:null, alerts:[], preset:'28', from:'', to:'', type:'web', filters:{}, metric:'clicks', dim:'queries', sort:{k:'clicks', d:-1}, perf:null, opps:null, urlFilter:'', urlQ:'', urlPage:1};

reg('seo', {title:'سرچ کنسول و سئو', icon:'magnifying-glass-plus', wide:true, perm:'analytics',
  async load(args) {
    const s = await L().api('gsc_state');
    if (!s.ok) throw new Error(s.error);
    G.state = s.state; G.alerts = s.alerts || [];
    G.tab = TABS.some(t => t[0] === args[0]) ? args[0] : (G.state.connected && G.state.site ? 'perf' : 'settings');
    if (args[0] === 'settings' && args[1]) setTimeout(() => toast(decodeURIComponent(args.slice(2).join('.')) || (args[1] === 'ok' ? 'انجام شد' : 'خطا'), args[1] === 'ok' ? 'ok' : 'err'), 300);
  },
  render(el) { G.el = el; paint(); },
  update(args) { if (TABS.some(t => t[0] === args[0])) { G.tab = args[0]; paint(); } }
});

function paint() {
  const el = G.el, st = G.state, ready = st.connected && st.site;
  const unseen = G.alerts.filter(a => !+a.seen);
  el.innerHTML = `<div class="ph"><div><h2>سرچ کنسول و سئو</h2><p>${ready ? `<span class="ltr" style="unicode-bidi:isolate">${E(st.site)}</span> · ${E(st.account || '')}` : 'اتصال به Google Search Console برای دیدن جستجوها، ایندکس و نقشه‌ی سایت'}</p></div>
    <div class="acts">${unseen.length ? `<button class="btn" data-act="seo-alerts">${ic('bell-ringing')}${fa(unseen.length)} هشدار</button>` : ''}${ready ? `<button class="btn" data-act="seo-refresh">${ic('arrows-clockwise')}به‌روز کردن</button>` : ''}</div></div>
    ${tabs(TABS.map(t => [t[0], t[1], t[2]]), G.tab, 'seo-tab')}
    <div id="seoBody" style="margin-top:16px"></div>`;
  const body = $('#seoBody');
  if (!ready && G.tab !== 'settings' && G.tab !== 'tools') { body.innerHTML = `<div class="card"><div class="card-b">${empty('plugs', 'سرچ کنسول هنوز وصل نشده', 'برای دیدن این بخش، اول از «اتصال و تنظیمات» حساب گوگل را وصل و سایت را انتخاب کنید.', `<button class="btn btn-p" data-act="seo-tab" data-v="settings">${ic('plug')}اتصال</button>`)}</div></div>`; return; }
  ({perf, opps, sitemaps, urls, tools, settings})[G.tab](body);
}
A['seo-tab'] = el => { G.tab = el.dataset.v; history.replaceState(null, '', '#seo.' + G.tab); paint(); };
A['seo-refresh'] = async el => { el.classList.add('loading'); await L().api('gsc_config_save', {}); G.perf = null; G.opps = null; el.classList.remove('loading'); paint(); toast('داده‌ها دوباره از گوگل گرفته شد'); };
A['seo-alerts'] = () => { modal({title:'هشدارهای سئو', icon:'bell-ringing', body:`<div class="stack s8">${G.alerts.map(a => `<div class="li"><span class="chi md" style="background:var(--warn-soft);color:var(--warn)">${ic('warning')}</span><div class="grow"><div class="t">${E(a.title)}</div><div class="s">${E(a.detail || '')}</div></div><span class="xs muted">${E(JD.toJ(a.created_at))}</span></div>`).join('')}</div>`, foot:'<button class="btn" data-close>بستن</button>'}); L().api('gsc_alert_seen'); G.alerts.forEach(a => a.seen = 1); };

/* ---------- عملکرد ---------- */
function rangeParams() { const p = {type:G.type, filters:G.filters}; if (G.preset === 'c') { p.from = G.from; p.to = G.to; } else p.days = +G.preset; return p; }
async function perf(body) {
  body.innerHTML = filterBar() + `<div id="seoPerf">${EB.sk(8)}</div>`;
  const r = await L().api('gsc_performance', rangeParams());
  const box = $('#seoPerf'); if (!box) return;
  if (!r.ok) { box.innerHTML = errBox(r.error); return; }
  G.perf = r; paintPerf();
}
function errBox(msg) { return `<div class="card"><div class="card-b">${empty('warning-circle', 'گوگل پاسخ نداد', E(msg), `<button class="btn" data-act="seo-tab" data-v="${G.tab}">${ic('arrows-clockwise')}تلاش دوباره</button>`)}</div></div>`; }
function filterBar() {
  const f = G.filters;
  const chips = [['query', 'کلمه'], ['page', 'صفحه'], ['country', 'کشور'], ['device', 'دستگاه'], ['brand', 'برند']].filter(x => f[x[0]]).map(x => `<span class="fchip"><b>${x[1]}:</b><span>${E(x[0] === 'brand' ? (f.brand === 'brand' ? 'فقط برند' : 'بدون برند') : x[0] === 'device' ? DEVICE[f.device] || f.device : x[0] === 'country' ? COUNTRY[f.country] || f.country : f[x[0]])}</span><button data-act="seo-unf" data-k="${x[0]}" aria-label="حذف">${ic('x')}</button></span>`).join('');
  return `<div class="card" style="margin-bottom:14px"><div class="card-b stack s10">
    <div class="row wrap" style="gap:10px">${seg('seoPreset', PRESETS, G.preset, 'seo-preset')}
      <span class="dinp ${G.preset === 'c' ? '' : 'hide'}" id="seoCustom"><input class="inp inp-sm" id="seoFrom" placeholder="از" value="${E(G.from ? JD.toJ(G.from) : '')}" data-act="seo-pick" readonly><span class="muted">تا</span><input class="inp inp-sm" id="seoTo" placeholder="تا" value="${E(G.to ? JD.toJ(G.to) : '')}" data-act="seo-pick" readonly><button class="btn btn-sm btn-p" data-act="seo-apply">اعمال</button></span>
      <span class="ms-auto"></span>${seg('seoType', TYPES, G.type, 'seo-type')}</div>
    <div class="fbar" style="margin:0">${chips}<button class="btn btn-sm" data-act="seo-addf">${ic('funnel-simple')}افزودن فیلتر</button>${G.state.brand_terms ? seg('seoBrand', [['', 'همه'], ['brand', 'برند'], ['nonbrand', 'بدون برند']], f.brand || '', 'seo-brand') : ''}</div>
  </div></div>`;
}
function paintPerf() {
  const r = G.perf, t = r.totals, p = r.prev, box = $('#seoPerf'); if (!box) return;
  const ch = (a, b, inv) => b ? EB.delta((a - b) / b * 100, inv) + '<span class="xs muted">نسبت به دوره‌ی قبل</span>' : '';
  const ser = r.series; const lab = ser.labels.map(x => JD.toJ(x).slice(5));
  const mm = {clicks:['کلیک', ser.clicks, nf], impressions:['نمایش', ser.impressions, nf], ctr:['CTR', ser.ctr, v => pct(v, 1)], position:['جایگاه میانگین', ser.position.map(v => v || 0), v => fa(Math.round(v * 10) / 10)]}[G.metric];
  box.innerHTML = `<div class="kpis" style="margin-bottom:14px">
    <div class="kpi"><span class="k-l">${ic('cursor-click')}کلیک</span><span class="k-v">${nf(t.clicks)}</span><span class="k-d">${ch(t.clicks, p.clicks)}</span></div>
    <div class="kpi"><span class="k-l">${ic('eye')}نمایش</span><span class="k-v">${compact(t.impressions)}</span><span class="k-d">${ch(t.impressions, p.impressions)}</span></div>
    <div class="kpi"><span class="k-l">${ic('percent')}نرخ کلیک (CTR)</span><span class="k-v">${pct(t.ctr, 1)}</span><span class="k-d">${ch(t.ctr, p.ctr)}</span></div>
    <div class="kpi"><span class="k-l">${ic('ranking')}جایگاه میانگین</span><span class="k-v">${fa(t.position)}</span><span class="k-d">${ch(t.position, p.position, true)}</span></div></div>
  <div class="card" style="margin-bottom:14px"><div class="card-h"><h3>روند روزانه</h3><span class="sub">${E(JD.toJ(r.range.from))} تا ${E(JD.toJ(r.range.to))}</span><div class="acts">${seg('seoMetric', [['clicks', 'کلیک'], ['impressions', 'نمایش'], ['ctr', 'CTR'], ['position', 'جایگاه']], G.metric, 'seo-metric')}</div></div>
    <div class="card-b">${mm[1].some(Boolean) ? chartBox({labels:lab, series:[{name:mm[0], data:mm[1]}], tipFmt:mm[2], fmt:G.metric === 'ctr' ? v => fa(Math.round(v)) + '٪' : null, aria:'روند سرچ کنسول'}, 240) : empty('chart-line', 'داده‌ای نیست', 'گوگل برای این بازه داده‌ای برنگرداند.')}</div></div>
  <div class="card">${tabs([['queries', 'کلمات جستجو', 'magnifying-glass', r.queries.length], ['pages', 'صفحه‌ها', 'file-text', r.pages.length], ['countries', 'کشورها', 'globe-simple'], ['devices', 'دستگاه‌ها', 'device-mobile'], ['appearance', 'ظاهر در نتایج', 'sparkle']], G.dim, 'seo-dim')}
    <div class="row" style="padding:10px 14px 0"><input class="inp inp-sm" id="seoQ" placeholder="جستجو در جدول..." data-on-input="seo-q" style="max-width:260px"><span class="ms-auto"></span><button class="btn btn-g btn-sm" data-act="seo-csv">${ic('download-simple')}CSV</button></div>
    <div id="seoTbl">${dimTable()}</div></div>`;
  EB.drawCharts(box);
}
function dimRows() {
  const r = G.perf; let rows = (G.dim === 'appearance' ? r.appearance.map(x => Object.assign({ctr:x.impressions ? x.clicks / x.impressions * 100 : 0, position:null}, x)) : r[G.dim]) || [];
  const q = ($('#seoQ') || {}).value || '';
  if (q) rows = rows.filter(x => String(x.key).toLowerCase().includes(q.toLowerCase()));
  const k = G.sort.k, d = G.sort.d;
  return rows.slice().sort((a, b) => ((a[k] || 0) - (b[k] || 0)) * d);
}
function dimTable() {
  const rows = dimRows();
  if (!rows.length) return `<div class="card-b">${empty('magnifying-glass', 'موردی نیست', '')}</div>`;
  const lab = x => G.dim === 'countries' ? (COUNTRY[x] || x.toUpperCase()) : G.dim === 'devices' ? (DEVICE[x] || x) : x;
  const th = (k, t) => `<th class="num clk" data-act="seo-sort" data-k="${k}">${t}${G.sort.k === k ? ic(G.sort.d < 0 ? 'caret-down' : 'caret-up') : ''}</th>`;
  const max = Math.max(...rows.map(x => x.clicks)) || 1;
  return `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>${({queries:'کلمه', pages:'صفحه', countries:'کشور', devices:'دستگاه', appearance:'نوع نمایش'})[G.dim]}</th>${th('clicks', 'کلیک')}${th('impressions', 'نمایش')}${th('ctr', 'CTR')}${G.dim !== 'appearance' ? th('position', 'جایگاه') : ''}</tr></thead><tbody>
    ${rows.slice(0, 300).map(x => `<tr class="${['queries', 'pages'].includes(G.dim) ? 'clk' : ''}" ${['queries', 'pages'].includes(G.dim) ? `data-act="seo-drill" data-k="${E(x.key)}"` : ''}><td><div class="stack s4"><span class="${G.dim === 'pages' ? 'url-cell' : 'ell'}" style="max-width:420px">${E(lab(x.key))}</span><div class="mini-bar" style="max-width:220px"><i style="width:${(x.clicks / max * 100).toFixed(1)}%"></i></div></div></td>
      <td class="num">${nf(x.clicks)}${x.prev_clicks != null && x.prev_clicks !== x.clicks ? `<div class="xs" style="color:var(--${x.clicks > x.prev_clicks ? 'ok' : 'err'})">${x.clicks > x.prev_clicks ? '+' : ''}${nf(x.clicks - x.prev_clicks)}</div>` : ''}</td><td class="num">${nf(x.impressions)}</td><td class="num">${pct(x.ctr, 1)}</td>
      ${G.dim !== 'appearance' ? `<td class="num">${fa(x.position)}${x.prev_position != null && Math.abs(x.prev_position - x.position) >= 0.5 ? `<div class="xs" style="color:var(--${x.position < x.prev_position ? 'ok' : 'err'})">${x.position < x.prev_position ? '▲' : '▼'} ${fa(Math.abs(Math.round((x.prev_position - x.position) * 10) / 10))}</div>` : ''}</td>` : ''}</tr>`).join('')}</tbody></table></div>`;
}
A['seo-dim'] = el => { G.dim = el.dataset.v; $$('.tab', el.parentElement).forEach(b => b.classList.toggle('on', b === el)); $('#seoTbl').innerHTML = dimTable(); };
A['seo-sort'] = el => { const k = el.dataset.k; G.sort = {k, d:G.sort.k === k ? -G.sort.d : (k === 'position' ? 1 : -1)}; $('#seoTbl').innerHTML = dimTable(); };
ON['seo-q'] = debounce(() => { $('#seoTbl').innerHTML = dimTable(); }, 200);
A['seo-metric'] = el => { A['seg'](el); G.metric = el.dataset.v; paintPerf(); };
A['seo-preset'] = el => { A['seg'](el); G.preset = el.dataset.v; $('#seoCustom').classList.toggle('hide', G.preset !== 'c'); if (G.preset !== 'c') reload(); };
A['seo-type'] = el => { A['seg'](el); G.type = el.dataset.v; reload(); };
A['seo-brand'] = el => { A['seg'](el); if (el.dataset.v) G.filters.brand = el.dataset.v; else delete G.filters.brand; reload(); };
A['seo-pick'] = el => JD.picker(el, iso => { if (el.id === 'seoFrom') G.from = iso; else G.to = iso; });
A['seo-apply'] = () => { if (!G.from || !G.to) return toast('هر دو تاریخ را انتخاب کنید', 'err'); reload(); };
A['seo-unf'] = el => { delete G.filters[el.dataset.k]; reload(); };
A['seo-addf'] = el => menu(el, [{h:'فیلتر'}, {t:'کلمه‌ی جستجو', icon:'magnifying-glass', fn:() => textFilter('query', 'کلمه‌ی جستجو', 'کفش')}, {t:'صفحه', icon:'file-text', fn:() => textFilter('page', 'آدرس صفحه شامل', '/blog/')},
  {t:'کشور', icon:'globe-simple', fn:() => pickFilter('country', Object.entries(COUNTRY))}, {t:'دستگاه', icon:'device-mobile', fn:() => pickFilter('device', Object.entries(DEVICE))}]);
function textFilter(k, title, ph) {
  const m = modal({title, icon:'funnel-simple', body:`<div class="stack s10">${field('متن', `<input class="inp" id="seoFv" placeholder="${E(ph)}" value="${E(G.filters[k] || '')}">`)}${field('نوع تطبیق', `<select class="inp" id="seoFo"><option value="contains">شامل</option><option value="equals">دقیقاً برابر</option><option value="notContains">شامل نباشد</option><option value="includingRegex">الگوی regex</option></select>`)}</div>`, foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>اعمال</button>'});
  m.el.querySelector('[data-ok]').onclick = () => { const v = $('#seoFv').value.trim(); if (v) { G.filters[k] = v; G.filters[k + '_op'] = $('#seoFo').value; } else { delete G.filters[k]; delete G.filters[k + '_op']; } m.close(); reload(); };
}
function pickFilter(k, opts) { menu($('[data-act=seo-addf]'), [{h:'انتخاب'}, ...opts.map(o => ({t:o[1], on:G.filters[k] === o[0], fn:() => { G.filters[k] = o[0]; reload(); }}))]); }
function reload() { G.perf = null; perf($('#seoBody')); }
A['seo-csv'] = () => {
  const rows = dimRows(); const csv = '﻿' + [['مورد', 'کلیک', 'نمایش', 'CTR', 'جایگاه']].concat(rows.map(x => [x.key, x.clicks, x.impressions, x.ctr, x.position == null ? '' : x.position])).map(r => r.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\n');
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8'})); a.download = 'search-console-' + G.dim + '.csv'; a.click();
};
A['seo-drill'] = async el => {
  const key = el.dataset.k, dim = G.dim === 'pages' ? 'page' : 'query';
  const dw = drawer({title:dim === 'page' ? 'کلمات این صفحه' : 'صفحه‌های این کلمه', sub:key, size:'lg', body:`<div style="padding:16px">${EB.sk(6)}</div>`});
  const r = await L().api('gsc_drill', Object.assign(rangeParams(), {dim, key}));
  const b = dw.el.querySelector('.m-b');
  if (!r.ok) { b.innerHTML = empty('warning-circle', 'بارگذاری نشد', E(r.error)); return; }
  b.innerHTML = `<div style="padding:14px">${r.daily.length ? chartBox({labels:r.daily.map(x => JD.toJ(x.d).slice(5)), series:[{name:'کلیک', data:r.daily.map(x => x.clicks)}]}, 160) : ''}</div>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>${dim === 'page' ? 'کلمه' : 'صفحه'}</th><th class="num">کلیک</th><th class="num">نمایش</th><th class="num">CTR</th><th class="num">جایگاه</th></tr></thead><tbody>${r.rows.map(x => `<tr><td><span class="${dim === 'query' ? 'url-cell' : ''}">${E(x.key)}</span></td><td class="num">${nf(x.clicks)}</td><td class="num">${nf(x.impressions)}</td><td class="num">${pct(x.ctr, 1)}</td><td class="num">${fa(x.position)}</td></tr>`).join('')}</tbody></table></div>
    ${dim === 'page' ? `<div style="padding:14px"><button class="btn btn-sm" data-act="seo-inspect-url" data-u="${E(key)}">${ic('magnifying-glass')}بررسی ایندکس این صفحه</button></div>` : ''}`;
  EB.drawCharts(b);
};

/* ---------- فرصت‌ها ---------- */
async function opps(body) {
  body.innerHTML = `<div class="row" style="margin-bottom:12px">${seg('seoOd', [['28', '۲۸ روز'], ['90', '۳ ماه']], G.oppDays || '28', 'seo-od')}<span class="small muted">مقایسه با دوره‌ی قبل از آن</span></div><div id="seoOpps">${EB.sk(8)}</div>`;
  const r = await L().api('gsc_opportunities', {days:+(G.oppDays || 28)});
  const box = $('#seoOpps'); if (!box) return;
  if (!r.ok) { box.innerHTML = errBox(r.error); return; }
  const card = (title, desc, icon, color, rows, head, row) => `<div class="card" style="margin-bottom:14px"><div class="card-h"><span class="chi md" style="background:${color}">${ic(icon)}</span><div><h3>${title}</h3><div class="sub">${desc}</div></div><div class="acts">${pill(fa(rows.length))}</div></div>
    ${rows.length ? `<div class="tbl-wrap"><table class="tbl"><thead><tr>${head}</tr></thead><tbody>${rows.slice(0, 25).map(row).join('')}</tbody></table></div>` : `<div class="card-b"><span class="small muted">موردی پیدا نشد.</span></div>`}</div>`;
  box.innerHTML =
    card('کلمات نزدیک صفحه‌ی اول', 'جایگاه ۸ تا ۲۰ با نمایش قابل توجه؛ کمی بهبود محتوا یا لینک داخلی، این‌ها را به صفحه‌ی اول می‌برد.', 'rocket-launch', '#1D5C4D', r.striking, '<th>کلمه</th><th class="num">نمایش</th><th class="num">جایگاه</th><th class="num">کلیک فعلی</th><th class="num">کلیک بالقوه</th>',
      x => `<tr><td>${E(x.key)}</td><td class="num">${nf(x.impressions)}</td><td class="num">${fa(x.position)}</td><td class="num">${nf(x.clicks)}</td><td class="num" style="color:var(--ok)">+${nf(x.potential)}</td></tr>`) +
    card('CTR کمتر از انتظار', 'این صفحه‌ها رتبه‌ی خوبی دارند ولی کم کلیک می‌خورند؛ عنوان و توضیحات متا را جذاب‌تر کنید.', 'cursor-click', '#B9741A', r.low_ctr, '<th>صفحه</th><th class="num">جایگاه</th><th class="num">CTR</th><th class="num">CTR مورد انتظار</th><th class="num">کلیک از دست‌رفته</th>',
      x => `<tr class="clk" data-act="seo-inspect-url" data-u="${E(x.key)}"><td><span class="url-cell">${E(x.key)}</span></td><td class="num">${fa(x.position)}</td><td class="num">${pct(x.ctr, 1)}</td><td class="num">${pct(x.expected_ctr, 1)}</td><td class="num" style="color:var(--err)">${nf(x.lost_clicks)}</td></tr>`) +
    card('رقابت صفحه‌های خودتان (هم‌نوع‌خواری)', 'برای این کلمات چند صفحه از سایت شما با هم رقابت می‌کنند؛ یکی را اصلی کنید و بقیه را به آن لینک یا ادغام کنید.', 'swap', '#9C2F45', r.cannibal, '<th>کلمه</th><th>صفحه‌ها</th><th class="num">نمایش</th>',
      x => `<tr><td>${E(x.query)}</td><td><div class="stack s4">${x.pages.map(p => `<span class="small"><span class="url-cell" style="max-width:340px;display:inline-block;vertical-align:bottom">${E(p.page)}</span> <span class="muted">· جایگاه ${fa(p.position)} · ${nf(p.impressions)} نمایش</span></span>`).join('')}</div></td><td class="num">${nf(x.impressions)}</td></tr>`) +
    `<div class="grid g2">` +
    card('بیشترین افت کلمات', 'در مقایسه با دوره‌ی قبل', 'trend-down', '#B42318', r.query_drops, '<th>کلمه</th><th class="num">قبل</th><th class="num">حالا</th><th class="num">تغییر</th>',
      x => `<tr><td>${E(x.key)}</td><td class="num">${nf(x.prev_clicks)}</td><td class="num">${nf(x.clicks)}</td><td class="num" style="color:var(--err)">${nf(x.change)}</td></tr>`) +
    card('بیشترین افت صفحه‌ها', 'در مقایسه با دوره‌ی قبل', 'trend-down', '#B42318', r.page_drops, '<th>صفحه</th><th class="num">قبل</th><th class="num">حالا</th>',
      x => `<tr class="clk" data-act="seo-inspect-url" data-u="${E(x.key)}"><td><span class="url-cell">${E(x.key)}</span></td><td class="num">${nf(x.prev_clicks)}</td><td class="num">${nf(x.clicks)}</td></tr>`) +
    card('کلمات رو به رشد', '', 'trend-up', '#2F7A5E', r.query_rises, '<th>کلمه</th><th class="num">قبل</th><th class="num">حالا</th>',
      x => `<tr><td>${E(x.key)}</td><td class="num">${nf(x.prev_clicks)}</td><td class="num">${nf(x.clicks)}</td></tr>`) +
    card('کلمات تازه', 'در دوره‌ی قبل کلیکی نداشتند', 'sparkle', '#3D5A80', r.new_queries, '<th>کلمه</th><th class="num">نمایش</th><th class="num">کلیک</th><th class="num">جایگاه</th>',
      x => `<tr><td>${E(x.key)}</td><td class="num">${nf(x.impressions)}</td><td class="num">${nf(x.clicks)}</td><td class="num">${fa(x.position)}</td></tr>`) + `</div>`;
}
A['seo-od'] = el => { A['seg'](el); G.oppDays = el.dataset.v; opps($('#seoBody')); };

/* ---------- نقشه‌ی سایت ---------- */
async function sitemaps(body) {
  body.innerHTML = EB.sk(6);
  const r = await L().api('gsc_sitemaps');
  if (!r.ok) { body.innerHTML = errBox(r.error); return; }
  const u = r.urls;
  body.innerHTML = `<div class="kpis k5" style="margin-bottom:14px">${[['آدرس‌های ثبت‌شده', u.total, 'list'], ['ایندکس‌شده', u.indexed, 'check-circle'], ['ایندکس نشده', u.not_indexed, 'x-circle'], ['هنوز بررسی نشده', u.unchecked, 'hourglass-medium'], ['خراب (۴xx/۵xx)', u.broken, 'link-break']].map(x => `<div class="kpi"><span class="k-l">${ic(x[2])}${x[0]}</span><span class="k-v">${nf(x[1] || 0)}</span></div>`).join('')}</div>
    <div class="card" style="margin-bottom:14px"><div class="card-h"><h3>نقشه‌های ارسال‌شده به گوگل</h3>${D.me.is_admin ? `<div class="acts"><button class="btn btn-sm btn-p" data-act="seo-sm-add">${ic('plus')}ارسال نقشه‌ی سایت</button></div>` : ''}</div>
    ${r.sitemaps.length ? `<div class="tbl-wrap"><table class="tbl resp"><thead><tr><th>آدرس</th><th>آخرین ارسال</th><th class="num">ارسال‌شده</th><th class="num">ایندکس‌شده</th><th>وضعیت</th><th></th></tr></thead><tbody>${r.sitemaps.map(s => `<tr><td><span class="url-cell">${E(s.path)}</span>${s.is_index ? '<span class="xs muted">فهرست نقشه‌ها</span>' : ''}</td><td class="small">${s.last_submitted ? E(JD.toJ(s.last_submitted.slice(0, 10))) : '-'}</td><td class="num">${nf(s.submitted)}</td><td class="num">${nf(s.indexed)}${s.submitted ? `<div class="xs muted">${pct(s.indexed / s.submitted * 100, 0)}</div>` : ''}</td>
      <td>${s.pending ? pill('در صف گوگل', 'info') : s.errors ? pill(fa(s.errors) + ' خطا', 'err') : s.warnings ? pill(fa(s.warnings) + ' هشدار', 'warn') : pill('سالم', 'ok')}</td>
      <td class="w1"><div class="row" style="gap:4px"><button class="btn btn-sm" data-act="seo-sm-import" data-u="${E(s.path)}" title="خواندن آدرس‌ها برای بررسی ایندکس">${ic('download-simple')}خواندن آدرس‌ها</button>${D.me.is_admin ? `<button class="btn btn-g btn-ic btn-sm" data-act="seo-sm-menu" data-u="${E(s.path)}" aria-label="بیشتر">${ic('dots-three')}</button>` : ''}</div></td></tr>`).join('')}</tbody></table></div>` : `<div class="card-b">${empty('tree-structure', 'نقشه‌ای ارسال نشده', 'آدرس sitemap.xml سایت را ارسال کنید تا گوگل صفحه‌ها را سریع‌تر پیدا کند. اگر نقشه ندارید، از «ابزارها» بسازید.')}</div>`}</div>
    <div class="callout">${ic('info')}<span>با «خواندن آدرس‌ها»، صفحه‌های نقشه در بخش «ایندکس و بازرسی» ثبت می‌شوند و به‌مرور (با رعایت سهمیه‌ی روزانه‌ی گوگل) وضعیت ایندکس و مشکلات فنی هرکدام بررسی می‌شود.</span></div>`;
}
A['seo-sm-add'] = () => {
  const base = /^sc-domain:/.test(G.state.site) ? 'https://' + G.state.site.replace('sc-domain:', '') + '/' : G.state.site;
  const m = modal({title:'ارسال نقشه‌ی سایت به گوگل', icon:'tree-structure', body:field('آدرس کامل نقشه', `<input class="inp ltr" id="seoSmU" value="${E(base)}sitemap.xml">`, 'معمولاً sitemap.xml یا sitemap_index.xml (وردپرس با Yoast/RankMath)'), foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>ارسال</button>'});
  m.el.querySelector('[data-ok]').onclick = async e => { const b = e.currentTarget; b.classList.add('loading'); const r = await L().api('gsc_sitemap_submit', {url:$('#seoSmU').value.trim()}); b.classList.remove('loading'); if (!r.ok) return toast(r.error, 'err'); m.close(); toast('نقشه‌ی سایت برای گوگل ارسال شد'); paint(); };
};
A['seo-sm-menu'] = el => menu(el, [{t:'ارسال دوباره', icon:'arrows-clockwise', fn:async () => { const r = await L().api('gsc_sitemap_submit', {url:el.dataset.u}); r.ok ? toast('دوباره ارسال شد') : toast(r.error, 'err'); }}, {t:'باز کردن', icon:'arrow-square-out', fn:() => window.open(el.dataset.u, '_blank', 'noopener')}, '-',
  {t:'حذف از سرچ کنسول', icon:'trash', danger:true, fn:() => confirm({title:'حذف نقشه', text:'این نقشه از فهرست سرچ کنسول حذف می‌شود (خود فایل روی سایت باقی می‌ماند).', ok:'حذف', danger:true, onOk:async () => { const r = await L().api('gsc_sitemap_delete', {url:el.dataset.u}); r.ok ? (toast('حذف شد'), paint()) : toast(r.error, 'err'); }})}]);
A['seo-sm-import'] = async el => { el.classList.add('loading'); const r = await L().api('gsc_sitemap_import', {url:el.dataset.u}); el.classList.remove('loading'); if (!r.ok) return toast(r.error, 'err'); toast(`${fa(r.total)} آدرس از ${fa(r.files)} فایل خوانده شد (${fa(r.added)} آدرس تازه)` + (r.errors.length ? ' · ' + fa(r.errors.length) + ' فایل خوانده نشد' : ''), r.errors.length ? 'warn' : 'ok'); paint(); };

/* ---------- آدرس‌ها ---------- */
const ISSUE_FA = x => x;
async function urls(body) {
  body.innerHTML = `<div class="row wrap" style="gap:10px;margin-bottom:12px">${seg('seoUf', [['', 'همه'], ['indexed', 'ایندکس‌شده'], ['not_indexed', 'ایندکس نشده'], ['unchecked', 'بررسی‌نشده'], ['issues', 'مشکل فنی'], ['broken', 'خراب']], G.urlFilter, 'seo-uf')}
    <input class="inp inp-sm" placeholder="جستجوی آدرس..." value="${E(G.urlQ)}" data-on-input="seo-uq" style="max-width:240px"><span class="ms-auto"></span><button class="btn btn-sm" data-act="seo-inspect-new">${ic('plus')}بررسی یک آدرس</button></div><div id="seoUrls">${EB.sk(8)}</div>`;
  loadUrls();
}
async function loadUrls() {
  const r = await L().api('gsc_urls', {filter:G.urlFilter, q:G.urlQ, page:G.urlPage});
  const box = $('#seoUrls'); if (!box) return;
  if (!r.ok) { box.innerHTML = errBox(r.error); return; }
  const issues = Object.entries(r.issue_counts || {});
  box.innerHTML = `${issues.length || r.coverage.length ? `<div class="grid g2" style="margin-bottom:14px"><div class="card"><div class="card-h"><h3>وضعیت ایندکس (گوگل)</h3></div><div class="card-b">${hbars(r.coverage.map(c => ({l:E(COV[c.k] || c.k), v:+c.c})))}</div></div>
    <div class="card"><div class="card-h"><h3>مشکلات فنی صفحه‌ها</h3></div><div class="card-b">${issues.length ? hbars(issues.slice(0, 12).map(([k, v]) => ({l:E(k), v, c:'var(--warn)'}))) : '<span class="small muted">هنوز صفحه‌ای بازرسی نشده یا مشکلی نیست.</span>'}</div></div></div>` : ''}
  <div class="card">${r.rows.length ? `<div class="tbl-wrap"><table class="tbl resp"><thead><tr><th>آدرس</th><th>ایندکس</th><th>آخرین خزش گوگل</th><th>بازرسی فنی</th><th></th></tr></thead><tbody>${r.rows.map(x => `<tr>
      <td><span class="url-cell" title="${E(x.url)}">${E(x.url)}</span>${x.title ? `<div class="xs muted ell" style="max-width:380px">${E(x.title)}</div>` : ''}</td>
      <td>${x.verdict ? pill((VERD[x.verdict] || [x.verdict])[0], (VERD[x.verdict] || [0, ''])[1]) + `<div class="xs muted">${E(COV[x.coverage] || x.coverage || '')}</div>` : '<span class="small muted">بررسی نشده</span>'}</td>
      <td class="small">${x.last_crawl ? E(JD.toJ(x.last_crawl)) : '-'}</td>
      <td>${x.crawled_at ? (x.http_status && x.http_status >= 400 ? pill('خطای ' + fa(x.http_status), 'err') : x.issues.length ? `<div class="row wrap" style="gap:4px">${x.issues.slice(0, 3).map(i => `<span class="pill warn">${E(i)}</span>`).join('')}${x.issues.length > 3 ? `<span class="xs muted">+${fa(x.issues.length - 3)}</span>` : ''}</div>` : pill('بدون مشکل', 'ok')) : '<span class="small muted">-</span>'}${x.load_ms ? `<div class="xs muted">${fa(x.load_ms)} میلی‌ثانیه</div>` : ''}</td>
      <td class="w1"><div class="row" style="gap:4px"><button class="btn btn-sm" data-act="seo-inspect-url" data-u="${E(x.url)}">${ic('magnifying-glass')}ایندکس</button><button class="btn btn-sm" data-act="seo-audit" data-id="${x.id}">${ic('stethoscope')}بازرسی</button></div></td></tr>`).join('')}</tbody></table></div>
    ${r.pages > 1 ? `<div class="row" style="justify-content:center;padding:12px"><button class="btn btn-sm" data-act="seo-up" data-d="-1" ${r.page <= 1 ? 'disabled' : ''}>قبلی</button><span class="small muted">صفحه‌ی ${fa(r.page)} از ${fa(r.pages)} · ${nf(r.total)} آدرس</span><button class="btn btn-sm" data-act="seo-up" data-d="1" ${r.page >= r.pages ? 'disabled' : ''}>بعدی</button></div>` : ''}` : `<div class="card-b">${empty('magnifying-glass', 'آدرسی ثبت نشده', 'از «نقشه‌ی سایت» آدرس‌ها را بخوانید یا یک آدرس را دستی بررسی کنید.', `<button class="btn" data-act="seo-tab" data-v="sitemaps">${ic('tree-structure')}نقشه‌ی سایت</button>`)}</div>`}</div>
  <p class="small muted" style="margin-top:10px">بررسی خودکار: روزی تا ${fa(G.state.inspect_daily)} آدرس از نظر ایندکس و ${fa(G.state.audit_daily)} آدرس از نظر فنی (با cron).</p>`;
}
A['seo-uf'] = el => { A['seg'](el); G.urlFilter = el.dataset.v; G.urlPage = 1; loadUrls(); };
ON['seo-uq'] = debounce(el => { G.urlQ = el.value.trim(); G.urlPage = 1; loadUrls(); }, 300);
A['seo-up'] = el => { G.urlPage += +el.dataset.d; loadUrls(); };
A['seo-audit'] = async el => {
  el.classList.add('loading'); const r = await L().api('gsc_audit', {id:+el.dataset.id}); el.classList.remove('loading');
  if (!r.ok) return toast(r.error, 'err');
  const a = r.audit;
  modal({title:'بازرسی فنی صفحه', icon:'stethoscope', size:'lg', body:`<dl class="kv" style="grid-template-columns:140px 1fr">${[['کد پاسخ', a.http_status ? fa(a.http_status) : 'باز نشد'], ['زمان پاسخ', a.load_ms ? fa(a.load_ms) + ' میلی‌ثانیه' : '-'], ['عنوان', a.title ? E(a.title) + ` <span class="xs muted">(${fa(a.title.length)} حرف)</span>` : '-'], ['توضیحات متا', a.meta_desc ? E(a.meta_desc) + ` <span class="xs muted">(${fa(a.meta_desc.length)} حرف)</span>` : '-'], ['H1', E(a.h1 || '-')], ['canonical', a.canonical ? `<span class="ltr">${E(a.canonical)}</span>` : '-'], ['robots', E(a.robots || '-')], ['تعداد کلمات', a.words != null ? fa(a.words) : '-']].map(x => `<dt>${x[0]}</dt><dd>${x[1]}</dd>`).join('')}</dl>
    <div style="margin-top:12px">${a.issues.length ? `<div class="stack s6">${a.issues.map(i => `<div class="callout warn">${ic('warning')}<span>${E(i)}</span></div>`).join('')}</div>` : `<div class="callout ok">${ic('check-circle')}<span>مشکل فنی مهمی پیدا نشد.</span></div>`}</div>`, foot:'<button class="btn" data-close>بستن</button>'});
  loadUrls();
};
A['seo-inspect-new'] = () => { const m = modal({title:'بررسی ایندکس یک آدرس', icon:'magnifying-glass', body:field('آدرس کامل صفحه', '<input class="inp ltr" id="seoIu" placeholder="https://example.com/page">', 'باید متعلق به سایت انتخاب‌شده در سرچ کنسول باشد'), foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>بررسی</button>'}); m.el.querySelector('[data-ok]').onclick = () => { const u = $('#seoIu').value.trim(); if (!u) return; m.close(); inspectUrl(u); }; };
A['seo-inspect-url'] = el => inspectUrl(el.dataset.u);
async function inspectUrl(u) {
  const m = modal({title:'وضعیت ایندکس در گوگل', icon:'magnifying-glass', size:'lg', body:`<div class="stack s10"><span class="url-cell">${E(u)}</span>${EB.sk(5)}</div>`, foot:'<button class="btn" data-close>بستن</button>'});
  const r = await L().api('gsc_inspect', {url:u});
  const b = m.el.querySelector('.m-b');
  if (!r.ok) { b.innerHTML = `<div class="callout err">${ic('warning-circle')}<span>${E(r.error)}</span></div>`; return; }
  const x = r.result, v = VERD[x.verdict] || [x.verdict, ''];
  const yes = s => s && /SUCCESS|ALLOWED|INDEXING_ALLOWED|PASS/.test(s);
  b.innerHTML = `<div class="stack s12"><div class="row wrap">${pill(v[0], v[1], v[1] === 'ok' ? 'check-circle' : 'warning')}<span class="small">${E(COV[x.coverage] || x.coverage || '')}</span>${x.link ? `<a class="btn btn-sm ms-auto" href="${E(x.link)}" target="_blank" rel="noopener">${ic('arrow-square-out')}در سرچ کنسول</a>` : ''}</div>
    <dl class="kv" style="grid-template-columns:170px 1fr">
      <dt>آخرین خزش</dt><dd>${x.last_crawl ? E(JD.toJ(x.last_crawl)) + ' ' + E(fa(x.last_crawl.slice(11, 16))) : 'هنوز خزیده نشده'}</dd>
      <dt>خزش با</dt><dd>${E({MOBILE:'ربات موبایل', DESKTOP:'ربات دسکتاپ'}[x.crawled_as] || '-')}</dd>
      <dt>دریافت صفحه</dt><dd>${yes(x.fetch) ? pill('موفق', 'ok') : E(x.fetch || '-')}</dd>
      <dt>robots.txt</dt><dd>${yes(x.robots) ? pill('مجاز', 'ok') : E(x.robots || '-')}</dd>
      <dt>اجازه‌ی ایندکس</dt><dd>${yes(x.indexing) ? pill('مجاز', 'ok') : E(x.indexing || '-')}</dd>
      <dt>canonical شما</dt><dd class="ltr" style="text-align:right">${E(x.user_canonical || '-')}</dd>
      <dt>canonical گوگل</dt><dd class="ltr" style="text-align:right">${E(x.google_canonical || '-')}${x.google_canonical && x.user_canonical && x.google_canonical !== x.user_canonical ? ' ' + pill('متفاوت', 'warn') : ''}</dd>
      <dt>سازگاری با موبایل</dt><dd>${x.mobile ? (x.mobile === 'PASS' ? pill('سازگار', 'ok') : pill('مشکل دارد', 'warn') + (x.mobile_issues.length ? '<div class="xs muted">' + x.mobile_issues.map(E).join('، ') + '</div>' : '')) : '-'}</dd>
      <dt>نتایج غنی</dt><dd>${x.rich_items.length ? x.rich_items.map(i => pill(E(i.type) + (i.issues ? ' · ' + fa(i.issues) + ' مشکل' : ''), i.issues ? 'warn' : 'ok')).join(' ') : '-'}</dd>
      ${x.sitemaps.length ? `<dt>در نقشه‌ی</dt><dd class="ltr" style="text-align:right">${x.sitemaps.map(E).join('<br>')}</dd>` : ''}
      ${x.referrers.length ? `<dt>لینک‌شده از</dt><dd class="ltr small" style="text-align:right">${x.referrers.slice(0, 5).map(E).join('<br>')}</dd>` : ''}
    </dl>${v[1] !== 'ok' ? `<div class="callout">${ic('info')}<span>برای درخواست ایندکس، از دکمه‌ی «در سرچ کنسول» گزینه‌ی Request indexing را بزنید (گوگل این کار را فقط از پنل خودش اجازه می‌دهد).</span></div>` : ''}</div>`;
}

/* ---------- ابزارها ---------- */
function tools(body) {
  body.innerHTML = `<div class="grid g2">
    <div class="card"><div class="card-h"><span class="chi md" style="background:#1D5C4D">${ic('tree-structure')}</span><div><h3>ساخت نقشه‌ی سایت</h3><div class="sub">صفحه‌های سایت را می‌خزد و sitemap.xml می‌سازد</div></div></div><div class="card-b stack s10">
      ${field('آدرس صفحه‌ی اول سایت', '<input class="inp ltr" id="seoGu" placeholder="https://example.com/">')}${field('حداکثر صفحه', '<select class="inp" id="seoGl"><option>100</option><option selected>300</option><option>600</option><option>1000</option></select>', 'صفحه‌های noindex، سبد خرید، ورود و فایل‌ها کنار گذاشته می‌شوند')}
      <button class="btn btn-p" style="align-self:flex-start" data-act="seo-gen">${ic('play')}ساخت</button><div id="seoGr"></div></div></div>
    <div class="card"><div class="card-h"><span class="chi md" style="background:#3D5A80">${ic('magnifying-glass')}</span><div><h3>وضعیت ایندکس یک صفحه</h3><div class="sub">مستقیم از گوگل (URL Inspection)</div></div></div><div class="card-b stack s10">
      ${field('آدرس صفحه', '<input class="inp ltr" id="seoTu" placeholder="https://example.com/page">')}<button class="btn btn-p" style="align-self:flex-start" data-act="seo-tinspect" ${G.state.connected && G.state.site ? '' : 'disabled'}>${ic('magnifying-glass')}بررسی</button>${G.state.connected ? '' : '<span class="small muted">نیاز به اتصال سرچ کنسول</span>'}</div></div>
  </div>`;
}
A['seo-tinspect'] = () => { const u = $('#seoTu').value.trim(); if (u) inspectUrl(u); };
A['seo-gen'] = async el => {
  const u = $('#seoGu').value.trim(); if (!u) return toast('آدرس سایت را وارد کنید', 'err');
  el.classList.add('loading'); el.disabled = true; $('#seoGr').innerHTML = '<span class="small muted">در حال خزیدن صفحه‌ها؛ تا یک دقیقه طول می‌کشد…</span>';
  const r = await L().api('gsc_generate', {url:u, limit:+$('#seoGl').value});
  el.classList.remove('loading'); el.disabled = false;
  if (!r.ok) { $('#seoGr').innerHTML = `<div class="callout err">${ic('warning-circle')}<span>${E(r.error)}</span></div>`; return; }
  if (!r.count) { $('#seoGr').innerHTML = `<div class="callout warn">${ic('warning')}<span>صفحه‌ای پیدا نشد. آدرس را بررسی کنید یا ببینید سرور اجازه‌ی اتصال به سایت را دارد.</span></div>`; return; }
  const url = URL.createObjectURL(new Blob([r.xml], {type:'application/xml'}));
  $('#seoGr').innerHTML = `<div class="callout ok">${ic('check-circle')}<span>${fa(r.count)} صفحه پیدا شد${r.partial ? ' (به سقف رسید؛ سایت صفحه‌های بیشتری دارد)' : ''}.</span></div>
    <div class="row wrap" style="margin-top:8px"><a class="btn btn-sm" href="${url}" download="sitemap.xml">${ic('download-simple')}دانلود sitemap.xml</a><span class="small muted">فایل را در ریشه‌ی سایت بگذارید و از «نقشه‌ی سایت» برای گوگل ارسال کنید.</span></div>`;
};

/* ---------- تنظیمات ---------- */
function settings(body) {
  const s = G.state, admin = D.me.is_admin;
  if (!admin) { body.innerHTML = `<div class="card"><div class="card-b">${empty('lock-key', 'فقط مدیر', 'اتصال سرچ کنسول را مدیر پنل انجام می‌دهد.')}</div></div>`; return; }
  body.innerHTML = `<div class="set-in" style="padding:0">
    ${s.connected ? `<div class="callout ok">${ic('check-circle')}<span>وصل است: <b class="ltr">${E(s.account)}</b> (${s.mode === 'service' ? 'حساب سرویس' : 'حساب گوگل'})</span><div class="acts"><button class="btn btn-sm btn-g" data-act="seo-disc">قطع اتصال</button></div></div>
      <div class="set-sec"><div class="d"><h4>سایت</h4><p>از سایت‌هایی که این حساب در سرچ کنسول به آن‌ها دسترسی دارد</p></div><div class="stack s10" id="seoSites">${EB.sk(2)}</div></div>` : `
      <div class="set-sec"><div class="d"><h4>روش اول: حساب سرویس (پیشنهادی)</h4><p>بدون نیاز به ورود و بدون منقضی شدن؛ مناسب سرورهایی که به صفحه‌ی ورود گوگل دسترسی ندارند.</p></div><div class="stack s10">
        <ol class="small soft stack s6" style="margin:0;padding-inline-start:18px"><li>در <span class="ltr">console.cloud.google.com</span> یک پروژه بسازید و «Google Search Console API» را فعال کنید.</li><li>در بخش IAM › Service Accounts یک حساب سرویس بسازید و از تب Keys یک کلید JSON دانلود کنید.</li><li>ایمیل حساب سرویس را در سرچ کنسول › Settings › Users به‌عنوان کاربر (Full) اضافه کنید.</li><li>فایل JSON را اینجا بارگذاری کنید.</li></ol>
        <label class="btn" style="align-self:flex-start;cursor:pointer">${ic('upload-simple')}بارگذاری فایل JSON<input type="file" class="sr" accept=".json,application/json" data-on-change="seo-sa"></label>${s.openssl ? '' : `<div class="callout warn">${ic('warning')}<span>افزونه‌ی OpenSSL روی سرور فعال نیست؛ از روش دوم استفاده کنید.</span></div>`}</div></div>
      <div class="set-sec"><div class="d"><h4>روش دوم: ورود با حساب گوگل</h4><p>با OAuth؛ حساب گوگلی که مالک سایت در سرچ کنسول است.</p></div><div class="stack s10">
        <ol class="small soft stack s6" style="margin:0;padding-inline-start:18px"><li>در Google Cloud، «Search Console API» را فعال و یک OAuth Client از نوع Web application بسازید.</li><li>این آدرس را در Authorized redirect URIs بگذارید:</li></ol>
        <div class="copyrow"><div class="code ltr">${E(s.redirect_uri)}</div><button class="btn btn-ic" data-act="copy" data-text="${E(s.redirect_uri)}" aria-label="کپی">${ic('copy')}</button></div>
        <div class="fg">${field('Client ID', `<input class="inp ltr mono" id="seoCid" value="${E(s.client_id)}">`)}${field('Client Secret', `<input class="inp ltr mono" id="seoCs" type="password" placeholder="${s.has_secret ? 'ثبت شده؛ خالی بماند تا تغییر نکند' : ''}">`)}</div>
        <div class="row"><button class="btn" data-act="seo-oauth-save">${ic('check')}ذخیره</button><button class="btn btn-p" data-act="seo-oauth" ${s.client_id && s.has_secret ? '' : 'disabled'}>${ic('sign-in')}ورود با گوگل</button></div></div></div>`}
    <div class="set-sec"><div class="d"><h4>کلمات برند</h4><p>برای جدا کردن جستجوهای برند (مثل نام فروشگاه) از بقیه</p></div><div class="stack s10">${field('', `<textarea class="inp" id="seoBt" rows="2" placeholder="آرتان، artan">${E(s.brand_terms)}</textarea>`, 'با ویرگول یا خط جدا کنید')}</div></div>
    <div class="set-sec"><div class="d"><h4>بررسی خودکار</h4><p>سهمیه‌ی روزانه‌ی گوگل برای بررسی ایندکس، ۲۰۰۰ آدرس در روز برای هر سایت است.</p></div><div class="stack s10"><div class="fg">${field('بررسی ایندکس در روز', `<input class="inp ltr" type="number" id="seoId" min="0" max="1900" value="${E(s.inspect_daily)}">`)}${field('بازرسی فنی در روز', `<input class="inp ltr" type="number" id="seoAd" min="0" max="3000" value="${E(s.audit_daily)}">`)}</div>
      <label class="row">${sw('seoAl', s.alerts)}<span>هشدار افت کلیک، صفحه‌های خراب و خطای نقشه‌ی سایت</span></label><button class="btn btn-p" style="align-self:flex-start" data-act="seo-cfg-save">${ic('check')}ذخیره</button></div></div>
    <div class="callout">${ic('info')}<span>اگر سرور در ایران است و به گوگل دسترسی ندارد، در «تنظیمات › پروکسی» یک پروکسی بسازید و برای «سرچ کنسول گوگل» فعالش کنید.</span><div class="acts"><button class="btn btn-sm" data-act="go" data-to="settings.proxy">پروکسی</button></div></div></div>`;
  if (s.connected) loadSites();
}
async function loadSites() {
  const r = await L().api('gsc_sites'); const box = $('#seoSites'); if (!box) return;
  if (!r.ok) { box.innerHTML = `<div class="callout err">${ic('warning-circle')}<span>${E(r.error)}</span></div>`; return; }
  if (!r.sites.length) { box.innerHTML = `<div class="callout warn">${ic('warning')}<span>این حساب به هیچ سایتی در سرچ کنسول دسترسی ندارد. ایمیل <b class="ltr">${E(G.state.account)}</b> را در سرچ کنسول به‌عنوان کاربر اضافه کنید.</span></div>`; return; }
  box.innerHTML = `<div class="stack s6">${r.sites.map(x => `<label class="li" style="cursor:pointer"><input type="radio" name="seoSite" value="${E(x.url)}" ${x.url === G.state.site ? 'checked' : ''} data-on-change="seo-site"><span class="grow ltr" style="text-align:right">${E(x.url)}</span><span class="xs muted">${E({siteOwner:'مالک', siteFullUser:'دسترسی کامل', siteRestrictedUser:'دسترسی محدود'}[x.level] || x.level)}</span></label>`).join('')}</div>`;
}
ON['seo-site'] = async el => { const r = await L().api('gsc_site_set', {site:el.value}); if (!r.ok) return toast(r.error, 'err'); G.state.site = el.value; G.perf = null; toast('سایت انتخاب شد'); paint(); };
ON['seo-sa'] = async el => { const f = el.files && el.files[0]; if (!f) return; const txt = await f.text(); const r = await L().api('gsc_connect_service', {json:txt}); if (!r.ok) return toast(r.error, 'err'); toast('وصل شد: ' + r.email); EB.route(true); };
A['seo-oauth-save'] = async () => { const r = await L().api('gsc_config_save', {client_id:$('#seoCid').value.trim(), client_secret:$('#seoCs').value.trim()}); if (!r.ok) return toast(r.error, 'err'); G.state = r.state; toast('ذخیره شد'); paint(); };
A['seo-oauth'] = async () => { const r = await L().api('gsc_auth_url'); if (!r.ok) return toast(r.error, 'err'); location.href = r.url; };
A['seo-disc'] = () => confirm({title:'قطع اتصال سرچ کنسول', text:'توکن‌ها حذف می‌شوند. داده‌های ذخیره‌شده (آدرس‌ها و تاریخچه) باقی می‌مانند.', ok:'قطع اتصال', danger:true, onOk:async () => { await L().api('gsc_disconnect'); toast('اتصال قطع شد'); EB.route(true); }});
A['seo-cfg-save'] = async () => { const r = await L().api('gsc_config_save', {brand_terms:$('#seoBt').value, inspect_daily:+$('#seoId').value, audit_daily:+$('#seoAd').value, alerts:$('#seoAl').checked}); if (!r.ok) return toast(r.error, 'err'); G.state = r.state; toast('ذخیره شد'); };
})();
