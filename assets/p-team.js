/* p-team.js — گزارش راندمان اپراتورها: پاسخ‌ها، زمان پاسخ، مهلت، بستن، تخصیص و رضایت مشتری */
(function(){
const {$, $$, E, fa, nf, ic, av, empty, A, ON, reg, toast, store, chartBox} = EB;
const L = () => window.EB_LIVE;
const S = {preset:store.get('teamPreset', '7'), from:'', to:'', sort:'replies', dir:-1, r:null};
const PRESETS = [['0', 'امروز'], ['1', 'دیروز'], ['7', '۷ روز'], ['30', '۳۰ روز'], ['90', '۹۰ روز'], ['c', 'سفارشی']];
const COLORS = ['#1D5C4D', '#8A5A1C', '#3D5A80', '#7A3E48', '#4F6B3A', '#2E6670', '#6B4F7A', '#9A4E2A'];

/** مدت به زبان آدم: ۴ دقیقه، ۱ ساعت و ۱۰ دقیقه */
function dur(sec) {
  if (sec == null) return '<span class="muted">—</span>';
  if (sec < 60) return fa(Math.round(sec)) + ' ثانیه';
  const m = Math.round(sec / 60);
  if (m < 60) return fa(m) + ' دقیقه';
  const h = Math.floor(m / 60), mm = m % 60;
  if (h < 24) return fa(h) + ' ساعت' + (mm ? ' و ' + fa(mm) + ' دقیقه' : '');
  return fa(Math.floor(h / 24)) + ' روز' + (h % 24 ? ' و ' + fa(h % 24) + ' ساعت' : '');
}
function range() {
  if (S.preset === 'c' && S.from && S.to) return {from:S.from, to:S.to};
  const d = S.preset === '1' ? 1 : 0, n = S.preset === '0' || S.preset === '1' ? 1 : +S.preset || 7;
  return {from:JD.isoDaysAgo(d + n - 1), to:JD.isoDaysAgo(d)};
}

reg('team', {title:'گزارش عملکرد تیم', icon:'chart-line-up', perm:'inbox',
  async load() { await fetchR(); },
  render(el) { el.innerHTML = page(); EB.drawCharts && EB.drawCharts(el); }});

async function fetchR() {
  const r = await L().api('desk_agent_report', range());
  if (!r.ok) throw new Error(r.error || 'گزارش ساخته نشد');
  S.r = r;
}
async function reload() {
  const box = $('.page'); if (!box) return;
  box.style.opacity = .55;
  try { await fetchR(); box.innerHTML = page(); EB.drawCharts && EB.drawCharts(box); }
  catch (e) { toast(e.message, 'err'); }
  box.style.opacity = '';
}

const COLS = [
  ['name', 'همکار'], ['replies', 'پیام پاسخ', 'تعداد پیام‌هایی که اپراتور از صندوق ورودی فرستاده (پیام بات و پاسخ خودکار حساب نمی‌شود)'],
  ['chats', 'گفتگو', 'تعداد مشتری‌های متفاوتی که در این بازه جواب گرفته‌اند'],
  ['frt_med', 'اولین پاسخ (میانه)', 'زمان از اولین پیام بی‌جواب مشتری تا اولین جواب اپراتور، در شروع هر دور گفتگو. «میانه» یعنی نصف پاسخ‌ها سریع‌تر از این عدد بوده‌اند'],
  ['rt_med', 'زمان پاسخ (میانه)', 'برای همه‌ی پاسخ‌ها: از اولین پیام بی‌جوابِ مشتری تا پاسخ اپراتور. پاسخ بات انتظار را تمام نمی‌کند'],
  ['sla_pct', 'در مهلت', 'درصد پاسخ‌هایی که زودتر از مهلت (SLA) اولویت همان گفتگو رسیده‌اند'],
  ['closed', 'بسته‌شده', 'گفتگوهایی که همین اپراتور بسته'],
  ['claims', 'بر عهده گرفته', 'گفتگوهایی که مسئولشان شده (با اولین پاسخ یا «بر عهده گرفتن»)'],
  ['transfers_out', 'انتقال', 'به همکار داده / از همکار گرفته'],
  ['csat', 'رضایت مشتری', 'میانگین امتیاز ۱ تا ۵ مشتری‌ها در نظرسنجی بعد از بستن گفتگو'],
];
const sortVal = (u, k) => k === 'csat' ? (u.csat && u.csat.avg != null ? u.csat.avg : -1) : k === 'name' ? u.name : (u[k] ?? (k.endsWith('_med') ? 1e12 : -1));

function page() {
  const r = S.r; const t = r.team || {};
  const users = [...r.users].sort((a, b) => { const x = sortVal(a, S.sort), y = sortVal(b, S.sort); return (x > y ? 1 : x < y ? -1 : 0) * S.dir * (S.sort.endsWith('_med') ? -1 : 1); });
  const maxR = Math.max(1, ...users.map(u => u.replies));
  // نمودار روزانه‌ی پاسخ‌ها
  const days = []; { const a = new Date(r.from.slice(0, 10) + 'T12:00:00'), b = new Date(r.to.slice(0, 10) + 'T12:00:00'); for (let d = a; d <= b && days.length < 93; d = new Date(d.getTime() + 864e5)) days.push(d.toISOString().slice(0, 10)); }
  const top = users.filter(u => u.replies).slice(0, 6);
  const kpi = (label, val, sub, tip) => `<div class="kpi" ${tip ? `data-tip="${E(tip)}"` : ''}><div class="k-l">${label}</div><div class="k-v">${val}</div>${sub ? `<div class="k-s">${sub}</div>` : ''}</div>`;
  return `<div class="ph"><div><h2>گزارش عملکرد تیم</h2><p>${E(JD.toJ(r.from.slice(0, 10)))} تا ${E(JD.toJ(r.to.slice(0, 10)))}${r.mine_only ? ' · فقط آمار خودتان (همه‌ی تیم را مدیر و سرپرست می‌بینند)' : ''}</p></div>
    <div class="acts"><div class="seg">${PRESETS.map(p => `<button type="button" class="${S.preset === p[0] ? 'on' : ''}" data-act="tm-preset" data-v="${p[0]}">${p[1]}</button>`).join('')}</div></div></div>
  ${S.preset === 'c' ? `<div class="row" style="gap:8px;margin-bottom:14px"><input class="inp inp-sm" id="tmFrom" style="max-width:150px" placeholder="از" value="${E(S.from ? JD.toJ(S.from) : '')}" data-act="tm-pick" readonly><span class="muted">تا</span><input class="inp inp-sm" id="tmTo" style="max-width:150px" placeholder="تا" value="${E(S.to ? JD.toJ(S.to) : '')}" data-act="tm-pick" readonly><button class="btn btn-sm btn-p" data-act="tm-apply">نمایش</button></div>` : ''}
  <div class="kpis k6" style="margin-bottom:16px">
    ${kpi('پیام پاسخ', fa(nf(t.replies || 0)), fa(nf(t.chats || 0)) + ' گفتگو', COLS[1][2])}
    ${kpi('اولین پاسخ (میانه)', dur(t.frt_med), t.frt_avg != null ? 'میانگین ' + dur(t.frt_avg) : '', COLS[3][2])}
    ${kpi('زمان پاسخ (میانه)', dur(t.rt_med), '', COLS[4][2])}
    ${kpi('پاسخ در مهلت', t.sla_pct != null ? fa(t.sla_pct) + '٪' : '—', 'مهلت عادی ' + fa((r.sla || {}).normal || 30) + ' دقیقه', COLS[5][2])}
    ${kpi('گفتگوی بسته‌شده', fa(nf(t.closed || 0)), '', COLS[6][2])}
    ${kpi('رضایت مشتری', t.csat != null ? '⭐ ' + fa(t.csat.toFixed(1)) : '—', t.csat_n ? fa(t.csat_n) + ' امتیاز' : 'نظرسنجی در تنظیمات صندوق ورودی', COLS[9][2])}
  </div>
  ${top.length && days.length > 1 ? `<div class="card" style="margin-bottom:16px"><div class="card-h"><h3>پاسخ‌های روزانه</h3><div class="acts row wrap" style="gap:10px">${top.map((u, i) => `<span class="row xs" style="gap:5px"><i class="dot" style="background:${COLORS[i % COLORS.length]}"></i>${E(u.name)}</span>`).join('')}</div></div><div class="card-b">${chartBox({labels:days.map(d => JD.toJ(d).slice(5)), series:top.map((u, i) => ({name:u.name, color:COLORS[i % COLORS.length], data:days.map(d => (u.days || {})[d] || 0)})), area:false, aria:'پاسخ‌های روزانه‌ی هر همکار'}, 220)}</div></div>` : ''}
  <div class="card"><div class="card-h"><h3>همکاران</h3><span class="muted xs">برای مرتب‌سازی روی عنوان ستون بزنید · توضیح هر ستون با نگه داشتن موس</span><div class="acts"><button class="btn btn-sm btn-g" data-act="tm-csv">${ic('download-simple')}خروجی CSV</button></div></div>
    ${users.length ? `<div class="tbl-wrap"><table class="tbl tm-tbl"><thead><tr>${COLS.map(c => `<th class="${c[0] === 'name' ? '' : 'num'} clk ${S.sort === c[0] ? 'sorted' : ''}" data-act="tm-sort" data-k="${c[0]}" ${c[2] ? `data-tip="${E(c[2])}"` : ''}>${c[1]}${S.sort === c[0] ? ' ' + (S.dir < 0 ? '↓' : '↑') : ''}</th>`).join('')}</tr></thead><tbody>
      ${users.map(u => { const o = D.ops.find(x => x.rid === u.user_id); return `<tr>
        <td><div class="row" style="gap:8px">${av(u.name, {color:o && o.color, size:'sm'})}<div><b>${E(u.name)}</b><div class="xs muted">${({admin:'مدیر', supervisor:'سرپرست'})[u.role] || 'پشتیبان'}${u.active ? '' : ' · غیرفعال'}</div></div></div></td>
        <td class="num"><div class="tm-bar"><i style="width:${Math.round(u.replies * 100 / maxR)}%"></i></div><b class="tnum">${fa(nf(u.replies))}</b></td>
        <td class="num tnum">${fa(nf(u.chats))}</td>
        <td class="num">${dur(u.frt_med)}<div class="xs muted">${u.first ? fa(u.first) + ' اولین پاسخ' : ''}</div></td>
        <td class="num">${dur(u.rt_med)}${u.rt_avg != null ? `<div class="xs muted">میانگین ${dur(u.rt_avg)}</div>` : ''}</td>
        <td class="num">${u.sla_pct != null ? `<span class="pill ${u.sla_pct >= 80 ? 'ok' : u.sla_pct >= 50 ? 'warn' : 'err'}">${fa(u.sla_pct)}٪</span><div class="xs muted">${fa(u.in_sla)} از ${fa(u.sla_n)}</div>` : '<span class="muted">—</span>'}</td>
        <td class="num tnum">${fa(u.closed)}</td>
        <td class="num tnum">${fa(u.claims)}</td>
        <td class="num tnum">${fa(u.transfers_out)} / ${fa(u.transfers_in)}</td>
        <td class="num">${u.csat && u.csat.rated ? `<b>⭐ ${fa(u.csat.avg.toFixed(1))}</b><div class="xs muted">${fa(u.csat.rated)} امتیاز${u.csat.bad ? ' · ' + fa(u.csat.bad) + ' ناراضی' : ''}</div>` : u.csat && u.csat.sent ? `<span class="xs muted">${fa(u.csat.sent)} نظرسنجی، بی‌پاسخ</span>` : '<span class="muted">—</span>'}</td>
      </tr>`; }).join('')}</tbody></table></div>` : `<div class="card-b">${empty('chart-bar', 'در این بازه پاسخی ثبت نشده', 'بازه را بزرگ‌تر کنید. فقط پیام‌هایی که اپراتور از صندوق ورودی فرستاده حساب می‌شوند.')}</div>`}
  </div>
  <p class="xs muted" style="margin-top:12px">${ic('info')} زمان‌ها ساعت واقعی‌اند (ساعت‌های خارج از وقت کاری هم شمرده می‌شود). پاسخ‌هایی که فرستنده‌شان ثبت نشده (مثل پیام‌های بات) در این گزارش نیستند.</p>`;
}
A['tm-preset'] = el => { S.preset = el.dataset.v; store.set('teamPreset', S.preset); if (S.preset === 'c') { const box = $('.page'); box.innerHTML = page(); EB.drawCharts && EB.drawCharts(box); return; } reload(); };
A['tm-pick'] = el => JD.picker(el, iso => { if (el.id === 'tmFrom') S.from = iso; else S.to = iso; });
A['tm-apply'] = () => { if (!S.from || !S.to) return toast('هر دو تاریخ را انتخاب کنید', 'err'); reload(); };
A['tm-sort'] = el => { const k = el.dataset.k; if (S.sort === k) S.dir = -S.dir; else { S.sort = k; S.dir = k === 'name' ? 1 : -1; } const box = $('.page'); box.innerHTML = page(); EB.drawCharts && EB.drawCharts(box); };
A['tm-csv'] = () => {
  const rows = [['همکار', 'پیام پاسخ', 'گفتگو', 'اولین پاسخ‌ها', 'میانه اولین پاسخ (ثانیه)', 'میانه زمان پاسخ (ثانیه)', 'میانگین زمان پاسخ (ثانیه)', 'در مهلت ٪', 'بسته‌شده', 'بر عهده گرفته', 'انتقال داده', 'انتقال گرفته', 'میانگین رضایت', 'تعداد امتیاز']];
  S.r.users.forEach(u => rows.push([u.name, u.replies, u.chats, u.first, u.frt_med ?? '', u.rt_med ?? '', u.rt_avg ?? '', u.sla_pct ?? '', u.closed, u.claims, u.transfers_out, u.transfers_in, u.csat && u.csat.avg != null ? u.csat.avg : '', u.csat ? u.csat.rated : '']));
  const csv = '﻿' + rows.map(r => r.map(x => `"${String(x).replace(/"/g, '""')}"`).join(',')).join('\n');
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv'})); a.download = 'team-report-' + range().from + '_' + range().to + '.csv'; a.click();
};
})();
