/* p-analytics.js — آنالیتیکس سایت با فیلتر، بازه‌ی دلخواه و مقایسه با دوره‌ی قبل */
(function(){
const {$, $$, E, fa, nf, pct, compact, ic, pill, seg, tabs, empty, field, A, ON, UI, reg, go, toast, menu, drawer, modal, chartBox, hbars, store} = EB;
const L = () => window.EB_LIVE;

const DIMS = {
  channel:['کانال ورود', 'signpost'], source:['منبع', 'link-simple'], device:['دستگاه', 'device-mobile'], browser:['مرورگر', 'browser'], os:['سیستم‌عامل', 'desktop'],
  country:['کشور', 'globe-simple'], city:['شهر', 'map-pin'], utm_source:['منبع کمپین (utm_source)', 'megaphone'], utm_medium:['رسانه‌ی کمپین (utm_medium)', 'megaphone'],
  utm_campaign:['نام کمپین (utm_campaign)', 'megaphone'], link_code:['لینک رهگیری', 'link-simple'], page:['صفحه (شامل)', 'file-text'], entry:['صفحه‌ی ورود (شامل)', 'sign-in'], exit:['صفحه‌ی خروج (شامل)', 'sign-out'],
};
const TEXT_DIMS = ['page', 'entry', 'exit'];
const DEV_FA = {desktop:'دسکتاپ', mobile:'موبایل', tablet:'تبلت'};
const PRESETS = [['1', 'امروز'], ['y', 'دیروز'], ['7', '۷ روز'], ['28', '۲۸ روز'], ['90', '۹۰ روز'], ['365', 'یک سال'], ['c', 'دلخواه']];
const METRICS = [['sessions', 'نشست'], ['visitors', 'بازدیدکننده'], ['pageviews', 'بازدید صفحه'], ['bounce', 'نرخ پرش'], ['duration', 'مدت نشست']];

let S = {preset:'28', from:'', to:'', filters:{}, metric:'sessions', tab1:'pages', tab2:'devices', tab3:'countries', data:null, opts:null, views:[], loading:false};
try { const saved = JSON.parse(store.get('an.state', 'null') || 'null'); if (saved) Object.assign(S, {preset:saved.preset || '28', from:saved.from || '', to:saved.to || '', filters:saved.filters || {}, metric:saved.metric || 'sessions'}); } catch (e) {}
const persist = () => store.set('an.state', JSON.stringify({preset:S.preset, from:S.from, to:S.to, filters:S.filters, metric:S.metric}));

function params() {
  const p = {filters:S.filters};
  if (S.preset === 'c') { p.from = S.from; p.to = S.to; }
  else if (S.preset === 'y') { p.from = JD.isoDaysAgo(1); p.to = JD.isoDaysAgo(1); }
  else p.days = +S.preset;
  return p;
}
const dur = s => { s = Math.round(s || 0); return s >= 60 ? fa(Math.floor(s / 60)) + ' دقیقه' + (s % 60 ? ' و ' + fa(s % 60) + ' ثانیه' : '') : fa(s) + ' ثانیه'; };
const durShort = s => { s = Math.round(s || 0); return fa(Math.floor(s / 60)) + ':' + fa(String(s % 60).padStart(2, '0')); };
const chg = (cur, prev, inverse) => { if (!prev) return cur ? `<span class="xs muted">بدون داده‌ی دوره‌ی قبل</span>` : ''; const v = (cur - prev) / prev * 100; return EB.delta(v, inverse) + `<span class="xs muted">نسبت به دوره‌ی قبل</span>`; };
const dimLabel = (k, v) => k === 'device' ? (DEV_FA[v] || v) : v;

reg('analytics', {title:'آنالیتیکس سایت', icon:'chart-line-up', wide:true,
  async load(args) {
    if (args[0] === 'classic') return;
    await fetchAll();
  },
  render(el, args) {
    if (args[0] === 'classic') { el.innerHTML = `<div class="ph"><div><h2>ابزارهای پیشرفته</h2><p>هیت‌مپ، قیف تبدیل، نشست‌ها و تنظیمات ردیاب</p></div><div class="acts"><button class="btn" data-act="go" data-to="analytics">${ic('arrow-right')}بازگشت به گزارش</button></div></div><div class="card" style="overflow:hidden;height:calc(100vh - 210px);min-height:540px"><div id="anClassic" style="height:100%"></div></div>`; EB.legacyFrame($('#anClassic'), 'analytics'); return; }
    paint(el);
  }});

async function fetchAll() {
  S.loading = true;
  const [r, o, v] = await Promise.all([L().api('v4_an_report', params()), S.opts ? Promise.resolve(null) : L().api('v4_an_options', {days:365}), L().api('v4_an_views')]);
  S.loading = false;
  if (!r.ok && r.inactive) { S.data = {inactive:true}; return; }
  if (!r.ok) throw new Error(r.error);
  S.data = r;
  if (o && o.ok) S.opts = o.options;
  if (v && v.ok) S.views = v.views;
}
async function refresh() {
  const el = $('#anRoot'); if (!el) return;
  el.style.opacity = '.55';
  try { await fetchAll(); paint(el.parentElement); } catch (e) { toast(E(e.message || 'خطا'), 'err'); el.style.opacity = ''; }
  persist();
}

function paint(host) {
  const d = S.data;
  if (d && d.inactive) {
    host.innerHTML = `<div class="ph"><div><h2>آنالیتیکس سایت</h2><p>رفتار بازدیدکننده‌های سایت: از کجا آمده‌اند، چه صفحه‌هایی دیده‌اند و چقدر مانده‌اند.</p></div></div>
      <div class="card"><div class="card-b">${empty('chart-line-up', 'آنالیتیکس سایت هنوز روشن نشده', 'اول ماژول را روشن کنید، بعد کد ردیاب را در سایتتان بگذارید. از همان لحظه آمار جمع می‌شود.', D.me.is_admin ? `<div class="row" style="justify-content:center"><button class="btn btn-p" data-act="an-enable">${ic('power')}روشن کردن آنالیتیکس</button><button class="btn" data-act="go" data-to="analytics.classic">${ic('gear-six')}تنظیمات ردیاب</button></div>` : '')}</div></div>`;
    return;
  }
  const k = d.kpi, p = d.prev, r = d.range;
  const days = d.series.labels.length;
  const lbl = d.series.labels.map(x => d.hourly ? fa(x.slice(11, 13)) + ':۰۰' : JD.toJ(x).slice(5));
  const mdata = d.series[S.metric] || [];
  host.innerHTML = `<div id="anRoot">
  <div class="ph"><div><h2>آنالیتیکس سایت</h2><p>${E(JD.toJ(r.from))} تا ${E(JD.toJ(r.to))} · مقایسه با ${E(JD.toJ(r.prev_from))} تا ${E(JD.toJ(r.prev_to))}</p></div>
    <div class="acts"><button class="btn" data-act="an-views">${ic('bookmark-simple')}نماهای ذخیره‌شده</button><button class="btn" data-act="an-sessions">${ic('list-bullets')}نشست‌ها</button><button class="btn" data-act="go" data-to="analytics.classic">${ic('cursor-click')}هیت‌مپ و قیف</button></div></div>
  <div class="card" style="margin-bottom:14px"><div class="card-b stack s10">
    <div class="row wrap" style="gap:10px">${seg('anPreset', PRESETS, S.preset, 'an-preset')}
      <span class="dinp ${S.preset === 'c' ? '' : 'hide'}" id="anCustom"><input class="inp inp-sm" id="anFrom" placeholder="از ۱۴۰۵/۰۷/۰۱" value="${E(S.from ? JD.toJ(S.from) : '')}" data-act="an-pick" readonly><span class="muted">تا</span><input class="inp inp-sm" id="anTo" placeholder="تا" value="${E(S.to ? JD.toJ(S.to) : '')}" data-act="an-pick" readonly><button class="btn btn-sm btn-p" data-act="an-apply">اعمال</button></span>
      <span class="ms-auto"></span>${seg('anVis', [['', 'همه'], ['new', 'جدید'], ['returning', 'بازگشتی']], S.filters.visitor || '', 'an-vis')}
      ${seg('anId', [['', 'همه'], ['1', 'شناسایی‌شده']], S.filters.identified || '', 'an-ident')}</div>
    <div class="fbar" style="margin:0">${Object.keys(S.filters).filter(x => DIMS[x]).map(x => `<span class="fchip"><b>${DIMS[x][0]}:</b><span>${E([].concat(S.filters[x]).map(v => dimLabel(x, v)).join('، '))}</span><button data-act="an-unf" data-k="${x}" aria-label="حذف فیلتر">${ic('x')}</button></span>`).join('')}
      <button class="btn btn-sm" data-act="an-addf">${ic('funnel-simple')}افزودن فیلتر</button>${Object.keys(S.filters).length ? `<button class="btn btn-sm btn-g" data-act="an-clear">پاک کردن فیلترها</button>` : ''}</div>
  </div></div>
  <div class="kpis k6" style="margin-bottom:14px">${[
    ['users', 'بازدیدکننده', nf(k.visitors), chg(k.visitors, p.visitors)],
    ['browsers', 'نشست', nf(k.sessions), chg(k.sessions, p.sessions)],
    ['files', 'بازدید صفحه', nf(k.pageviews), chg(k.pageviews, p.pageviews)],
    ['timer', 'مدت میانگین نشست', durShort(k.avg_duration), chg(k.avg_duration, p.avg_duration)],
    ['arrow-u-up-left', 'نرخ پرش', pct(k.bounce_rate, 1), chg(k.bounce_rate, p.bounce_rate, true)],
    ['user-check', 'شناسایی‌شده', nf(k.identified), `<span class="xs muted">${fa(k.new_visitors)} بازدیدکننده‌ی جدید</span>`],
  ].map(x => `<div class="kpi"><span class="k-l">${ic(x[0])}${x[1]}</span><span class="k-v">${x[2]}</span><span class="k-d">${x[3]}</span></div>`).join('')}</div>
  <div class="card" style="margin-bottom:14px"><div class="card-h"><h3>روند ${d.hourly ? 'ساعتی' : 'روزانه'}</h3><div class="acts">${seg('anMetric', METRICS, S.metric, 'an-metric')}</div></div>
    <div class="card-b">${mdata.some(Boolean) ? chartBox({labels:lbl, series:[{name:(METRICS.find(m => m[0] === S.metric) || [])[1], data:mdata}], fmt:S.metric === 'bounce' ? (v => fa(Math.round(v)) + '٪') : S.metric === 'duration' ? (v => durShort(v)) : null, tipFmt:S.metric === 'bounce' ? (v => pct(v, 1)) : S.metric === 'duration' ? dur : nf, aria:'روند آنالیتیکس'}, 240) : empty('chart-line', 'در این بازه داده‌ای نیست', 'بازه یا فیلترها را تغییر دهید.')}</div></div>
  <div class="grid g-main" style="margin-bottom:14px">
    <div class="card">${tabs([['pages', 'صفحه‌های پربازدید', 'file-text'], ['entry', 'صفحه‌ی ورود', 'sign-in'], ['exit', 'صفحه‌ی خروج', 'sign-out']], S.tab1, 'an-tab1')}<div id="anT1">${tab1()}</div></div>
    <div class="card"><div class="card-h"><h3>کانال‌های ورود</h3>${csvBtn('channels')}</div><div class="card-b">${d.channels.length ? hbars(d.channels.map(x => ({l:`<a href="#" data-act="an-f" data-k="channel" data-v="${E(x.k)}" style="color:inherit">${E(x.k)}</a>`, v:x.sessions}))) : '<span class="muted small">داده‌ای نیست</span>'}</div>
      <div class="card-h" style="border-top:1px solid var(--line-2)"><h3>منبع‌ها</h3>${csvBtn('sources')}</div>${dimTable(d.sources, 'source', 'منبع')}</div>
  </div>
  <div class="grid g2" style="margin-bottom:14px">
    <div class="card">${tabs([['devices', 'دستگاه', 'device-mobile'], ['browsers', 'مرورگر', 'browser'], ['os', 'سیستم‌عامل', 'desktop']], S.tab2, 'an-tab2')}<div id="anT2">${tab2()}</div></div>
    <div class="card">${tabs([['countries', 'کشور', 'globe-simple'], ['cities', 'شهر', 'map-pin'], ['campaigns', 'کمپین‌ها', 'megaphone'], ['links', 'لینک رهگیری', 'link-simple']], S.tab3, 'an-tab3')}<div id="anT3">${tab3()}</div></div>
  </div>
  <div class="card"><div class="card-h"><h3>چه روزی و چه ساعتی؟</h3><span class="sub">تعداد نشست به وقت ایران</span></div><div class="card-b" style="overflow-x:auto">${heat(d.heat)}</div></div>
  </div>`;
  EB.drawCharts(host);
}

function csvBtn(key) { return `<div class="acts"><button class="btn btn-g btn-sm btn-ic" data-act="an-csv" data-k="${key}" title="خروجی CSV" aria-label="خروجی CSV">${ic('download-simple')}</button></div>`; }
function dimTable(rows, dim, title) {
  if (!rows || !rows.length) return `<div class="card-b"><span class="muted small">داده‌ای نیست</span></div>`;
  const max = Math.max(...rows.map(r => r.sessions)) || 1;
  return `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>${title}</th><th class="num">نشست</th><th class="num">بازدیدکننده</th><th class="num">پرش</th><th class="num">مدت</th></tr></thead><tbody>${rows.map(r => `<tr class="clk" data-act="an-f" data-k="${dim}" data-v="${E(r.k)}"><td><div class="stack s4"><span class="ell" style="max-width:260px">${E(dimLabel(dim, r.k))}</span><div class="mini-bar"><i style="width:${(r.sessions / max * 100).toFixed(1)}%"></i></div></div></td><td class="num">${nf(r.sessions)}</td><td class="num">${nf(r.visitors)}</td><td class="num">${pct(r.bounce, 0)}</td><td class="num">${durShort(r.duration)}</td></tr>`).join('')}</tbody></table></div>`;
}
function tab1() {
  const d = S.data;
  if (S.tab1 === 'pages') {
    if (!d.pages.length) return `<div class="card-b">${empty('file-text', 'صفحه‌ای ثبت نشده', 'وقتی ردیاب روی سایت باشد، صفحه‌ها اینجا می‌آیند.')}</div>`;
    return `<div class="row" style="padding:8px 14px 0">${csvBtn('pages')}</div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>صفحه</th><th class="num">بازدید</th><th class="num">نشست</th><th class="num">زمان روی صفحه</th><th class="num">اسکرول</th></tr></thead><tbody>${d.pages.map(r => `<tr class="clk" data-act="an-f" data-k="page" data-v="${E(r.url)}"><td><span class="url-cell" title="${E(r.url)}">${E(r.url)}</span>${r.title ? `<div class="xs muted ell" style="max-width:380px">${E(r.title)}</div>` : ''}</td><td class="num">${nf(r.views)}</td><td class="num">${nf(r.sessions)}</td><td class="num">${durShort(r.avg_time)}</td><td class="num">${pct(r.avg_scroll, 0)}</td></tr>`).join('')}</tbody></table></div>`;
  }
  return dimTable(d[S.tab1], S.tab1, S.tab1 === 'entry' ? 'صفحه‌ی ورود' : 'صفحه‌ی خروج');
}
function tab2() { return dimTable(S.data[S.tab2], {devices:'device', browsers:'browser', os:'os'}[S.tab2], {devices:'دستگاه', browsers:'مرورگر', os:'سیستم‌عامل'}[S.tab2]); }
function tab3() {
  const d = S.data;
  if (S.tab3 === 'campaigns') {
    if (!d.campaigns.length) return `<div class="card-b">${empty('megaphone', 'کمپینی ثبت نشده', 'لینک‌هایی که utm_campaign دارند اینجا جمع می‌شوند.')}</div>`;
    return `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>کمپین</th><th>منبع / رسانه</th><th class="num">نشست</th><th class="num">بازدیدکننده</th></tr></thead><tbody>${d.campaigns.map(r => `<tr class="clk" data-act="an-f" data-k="utm_campaign" data-v="${E(r.campaign)}"><td class="b">${E(r.campaign)}</td><td class="small muted ltr" style="text-align:right">${E(r.source || '-')} / ${E(r.medium || '-')}</td><td class="num">${nf(r.sessions)}</td><td class="num">${nf(r.visitors)}</td></tr>`).join('')}</tbody></table></div>`;
  }
  if (S.tab3 === 'links') return dimTable(d.links.filter(x => x.k !== 'نامعلوم'), 'link_code', 'لینک رهگیری');
  return dimTable(d[S.tab3], S.tab3 === 'countries' ? 'country' : 'city', S.tab3 === 'countries' ? 'کشور' : 'شهر');
}
function heat(grid) {
  const max = Math.max(1, ...grid.flat());
  const days = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
  let h = '<div class="heat2" style="min-width:560px"><span></span>' + Array.from({length:24}, (_, i) => `<span class="hl">${i % 3 === 0 ? fa(i) : ''}</span>`).join('');
  grid.forEach((row, di) => { h += `<span class="xs">${days[di]}</span>` + row.map((v, hi) => `<i title="${days[di]} ساعت ${fa(hi)}: ${fa(v)} نشست" style="opacity:${v ? (0.12 + 0.88 * v / max).toFixed(2) : 0.06}"></i>`).join(''); });
  return h + '</div>';
}

/* ---------- کنش‌ها ---------- */
A['an-preset'] = el => { A['seg'](el); S.preset = el.dataset.v; $('#anCustom').classList.toggle('hide', S.preset !== 'c'); if (S.preset !== 'c') refresh(); };
A['an-pick'] = el => JD.picker(el, iso => { if (el.id === 'anFrom') S.from = iso; else S.to = iso; });
A['an-apply'] = () => { if (!S.from || !S.to) return toast('هر دو تاریخ را انتخاب کنید', 'err'); refresh(); };
A['an-metric'] = el => { A['seg'](el); S.metric = el.dataset.v; persist(); paint($('#anRoot').parentElement); };
A['an-vis'] = el => { A['seg'](el); if (el.dataset.v) S.filters.visitor = el.dataset.v; else delete S.filters.visitor; refresh(); };
A['an-ident'] = el => { A['seg'](el); if (el.dataset.v) S.filters.identified = el.dataset.v; else delete S.filters.identified; refresh(); };
A['an-tab1'] = el => { S.tab1 = el.dataset.v; $$('.tab', el.parentElement).forEach(b => b.classList.toggle('on', b === el)); $('#anT1').innerHTML = tab1(); };
A['an-tab2'] = el => { S.tab2 = el.dataset.v; $$('.tab', el.parentElement).forEach(b => b.classList.toggle('on', b === el)); $('#anT2').innerHTML = tab2(); };
A['an-tab3'] = el => { S.tab3 = el.dataset.v; $$('.tab', el.parentElement).forEach(b => b.classList.toggle('on', b === el)); $('#anT3').innerHTML = tab3(); };
A['an-f'] = (el, e) => { if (e) e.preventDefault(); const k = el.dataset.k, v = el.dataset.v; if (!k || v == null || v === 'نامعلوم') return; S.filters[k] = TEXT_DIMS.includes(k) ? v : [v]; refresh(); };
A['an-unf'] = el => { delete S.filters[el.dataset.k]; refresh(); };
A['an-clear'] = () => { S.filters = {}; refresh(); };
A['an-addf'] = el => menu(el, [{h:'فیلتر بر اساس'}, ...Object.entries(DIMS).map(([k, v]) => ({t:v[0], icon:v[1], fn:() => pickValue(k)}))]);
function pickValue(k) {
  if (TEXT_DIMS.includes(k)) {
    const m = modal({title:DIMS[k][0], icon:DIMS[k][1], body:`${field('بخشی از آدرس', `<input class="inp ltr" id="anTv" placeholder="/product/" value="${E(S.filters[k] || '')}">`, 'هر نشستی که آدرسش این متن را دارد')}`, foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>اعمال</button>'});
    m.el.querySelector('[data-ok]').onclick = () => { const v = $('#anTv').value.trim(); if (v) S.filters[k] = v; else delete S.filters[k]; m.close(); refresh(); };
    setTimeout(() => $('#anTv') && $('#anTv').focus(), 50);
    return;
  }
  const opts = (S.opts && S.opts[k]) || [];
  const cur = [].concat(S.filters[k] || []);
  const m = modal({title:DIMS[k][0], icon:DIMS[k][1], body: opts.length ? `<input class="inp" id="anOq" placeholder="جستجو..." style="margin-bottom:10px"><div class="stack s4" id="anOl" style="max-height:340px;overflow:auto">${opts.map(o => `<label class="row small" style="padding:4px 2px"><input type="checkbox" class="cb" value="${E(o)}" ${cur.includes(o) ? 'checked' : ''}>${E(dimLabel(k, o))}</label>`).join('')}</div>` : empty('magnifying-glass', 'مقداری ثبت نشده', 'در یک سال اخیر داده‌ای برای این فیلتر نیست.'), foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>اعمال</button>'});
  const q = m.el.querySelector('#anOq');
  if (q) q.oninput = () => $$('#anOl label', m.el).forEach(l => { l.style.display = l.textContent.includes(q.value.trim()) ? '' : 'none'; });
  m.el.querySelector('[data-ok]').onclick = () => { const v = $$('#anOl input:checked', m.el).map(c => c.value); if (v.length) S.filters[k] = v; else delete S.filters[k]; m.close(); refresh(); };
}
A['an-csv'] = el => {
  const d = S.data, k = el.dataset.k; let rows = [];
  if (k === 'pages') rows = [['صفحه', 'عنوان', 'بازدید', 'نشست', 'زمان (ثانیه)', 'اسکرول ٪']].concat(d.pages.map(r => [r.url, r.title || '', r.views, r.sessions, r.avg_time, r.avg_scroll]));
  else rows = [['مقدار', 'نشست', 'بازدیدکننده', 'پرش ٪', 'مدت (ثانیه)']].concat((d[k] || []).map(r => [r.k, r.sessions, r.visitors, r.bounce, r.duration]));
  const csv = '﻿' + rows.map(r => r.map(x => '"' + String(x == null ? '' : x).replace(/"/g, '""') + '"').join(',')).join('\n');
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8'})); a.download = 'analytics-' + k + '-' + d.range.from + '_' + d.range.to + '.csv'; a.click();
};
A['an-enable'] = async el => { el.classList.add('loading'); const r = await L().api('analytics_settings_save', {enabled:'1'}); if (!r.ok) { el.classList.remove('loading'); return toast(r.error, 'err'); } toast('آنالیتیکس روشن شد؛ کد ردیاب را در سایت بگذارید'); go('analytics.classic'); };

/* نماهای ذخیره‌شده */
A['an-views'] = el => menu(el, [{h:'نماهای ذخیره‌شده'}, ...(S.views.length ? S.views.map((v, i) => ({t:E(v.name), icon:'bookmark-simple', fn:() => { S.filters = v.filters || {}; if (v.from && v.to) { S.preset = 'c'; S.from = v.from; S.to = v.to; } else S.preset = String(v.days || 28); refresh(); }})) : [{html:'<div class="small muted" style="padding:8px 10px">هنوز نمایی ذخیره نکرده‌اید</div>'}]), '-', {t:'ذخیره‌ی نمای فعلی', icon:'plus', fn:saveView}, ...(S.views.length ? [{t:'مدیریت نماها', icon:'trash', fn:manageViews}] : [])]);
function saveView() {
  const m = modal({title:'ذخیره‌ی نما', icon:'bookmark-simple', body:field('نام نما', '<input class="inp" id="anVn" placeholder="مثلاً: موبایلی‌های کمپین پاییز">', 'بازه و فیلترهای فعلی ذخیره می‌شوند'), foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>ذخیره</button>'});
  m.el.querySelector('[data-ok]').onclick = async () => {
    const name = $('#anVn').value.trim(); if (!name) return toast('نام را بنویسید', 'err');
    const v = {name, filters:S.filters}; if (S.preset === 'c') { v.from = S.from; v.to = S.to; } else v.days = S.preset === 'y' ? 1 : +S.preset;
    const r = await L().api('v4_an_views_save', {views:S.views.concat([v])}); if (!r.ok) return toast(r.error, 'err');
    S.views = r.views; m.close(); toast('نما ذخیره شد');
  };
}
function manageViews() {
  const m = modal({title:'نماهای ذخیره‌شده', icon:'bookmark-simple', body:`<div class="stack s6">${S.views.map((v, i) => `<div class="li"><span class="grow">${E(v.name)}</span><button class="btn btn-sm btn-g" data-del="${i}">${ic('trash')}حذف</button></div>`).join('')}</div>`, foot:'<button class="btn" data-close>بستن</button>'});
  m.el.addEventListener('click', async e => { const b = e.target.closest('[data-del]'); if (!b) return; const r = await L().api('v4_an_views_save', {views:S.views.filter((_, i) => i !== +b.dataset.del)}); if (r.ok) { S.views = r.views; m.close(); toast('حذف شد'); } });
}

/* نشست‌ها */
A['an-sessions'] = async () => {
  const dw = drawer({title:'نشست‌ها', sub:'با همین بازه و فیلترها', size:'lg', body:`<div style="padding:16px">${EB.sk(6)}</div>`});
  let page = 1;
  async function load() {
    const r = await L().api('v4_an_sessions', Object.assign(params(), {page}));
    const b = dw.el.querySelector('.m-b');
    if (!r.ok) { b.innerHTML = empty('warning-circle', 'بارگذاری نشد', E(r.error)); return; }
    b.innerHTML = `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>زمان</th><th>بازدیدکننده</th><th>ورود</th><th>کانال</th><th class="num">صفحه</th><th class="num">مدت</th></tr></thead><tbody>${r.sessions.map(s => `<tr class="clk" data-sid="${s.id}"><td class="small">${E(JD.toJ(s.started_local))} ${E((s.started_local || '').slice(11, 16).replace(/\d/g, x => '۰۱۲۳۴۵۶۷۸۹'[x]))}</td><td>${s.full_name ? `<b>${E(s.full_name)}</b>` : `<span class="muted small">ناشناس</span>`}<div class="xs muted">${E(DEV_FA[s.device] || s.device || '')} · ${E(s.browser || '')} · ${E(s.city || s.country || '')}</div></td><td><span class="url-cell" style="max-width:220px">${E(s.entry_page || '')}</span></td><td class="small">${E(s.channel)}</td><td class="num">${fa(s.page_count)}</td><td class="num">${durShort(s.duration_sec)}</td></tr>`).join('') || `<tr><td colspan="6">${empty('browsers', 'نشستی نیست', '')}</td></tr>`}</tbody></table></div>
      ${r.pages > 1 ? `<div class="row" style="justify-content:center;padding:12px"><button class="btn btn-sm" data-pg="-1" ${page <= 1 ? 'disabled' : ''}>قبلی</button><span class="small muted">صفحه‌ی ${fa(page)} از ${fa(r.pages)} · ${nf(r.total)} نشست</span><button class="btn btn-sm" data-pg="1" ${page >= r.pages ? 'disabled' : ''}>بعدی</button></div>` : ''}`;
  }
  dw.el.addEventListener('click', async e => {
    const pg = e.target.closest('[data-pg]'); if (pg) { page += +pg.dataset.pg; load(); return; }
    const tr = e.target.closest('[data-sid]'); if (tr) sessionDetail(+tr.dataset.sid);
  });
  load();
};
async function sessionDetail(id) {
  const r = await L().api('analytics_session_detail', {id});
  if (!r.ok) return toast(r.error, 'err');
  const s = r.session;
  modal({title:'سفر بازدیدکننده', icon:'path', size:'lg', body:`<div class="stack s12"><div class="row wrap small muted">${E(s.full_name || 'ناشناس')} · ${E(DEV_FA[s.device] || s.device || '')} · ${E(s.browser || '')} · ${E(s.city || s.country || '')} · از ${E(s.referrer || 'ورود مستقیم')}</div>
    <div class="tl">${r.pageviews.map((p, i) => `<div class="tl-i"><b class="ltr" style="display:block;text-align:right">${E(p.url)}</b><span class="xs muted">${p.title ? E(p.title) + ' · ' : ''}${durShort(p.time_on_page_sec || 0)} · اسکرول ${pct(p.scroll_depth_pct || 0, 0)}${p.events && Object.keys(p.events).length ? ' · ' + Object.entries(p.events).map(([k, v]) => fa(v) + ' ' + ({click:'کلیک', rage:'کلیک عصبی', dead:'کلیک بی‌اثر'}[k] || k)).join('، ') : ''}</span></div>`).join('')}</div></div>`, foot:'<button class="btn" data-close>بستن</button>'});
}
})();
