/* jdate.js — تبدیل تاریخ شمسی/میلادی و انتخابگر تاریخ شمسی سبک برای پنل */
(function () {
  function div(a, b) { return ~~(a / b); }
  function g2j(gy, gm, gd) {
    const g = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    let jy = gy <= 1600 ? 0 : 979; gy -= gy <= 1600 ? 621 : 1600;
    const gy2 = gm > 2 ? gy + 1 : gy;
    let days = 365 * gy + div(gy2 + 3, 4) - div(gy2 + 99, 100) + div(gy2 + 399, 400) - 80 + gd + g[gm - 1];
    jy += 33 * div(days, 12053); days %= 12053; jy += 4 * div(days, 1461); days %= 1461;
    if (days > 365) { jy += div(days - 1, 365); days = (days - 1) % 365; }
    const jm = days < 186 ? 1 + div(days, 31) : 7 + div(days - 186, 30);
    const jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
    return [jy, jm, jd];
  }
  function j2g(jy, jm, jd) {
    let gy = jy <= 979 ? 621 : 1600; jy -= jy <= 979 ? 0 : 979;
    let days = 365 * jy + div(jy, 33) * 8 + div(jy % 33 + 3, 4) + 78 + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
    gy += 400 * div(days, 146097); days %= 146097;
    if (days > 36524) { gy += 100 * div(--days, 36524); days %= 36524; if (days >= 365) days++; }
    gy += 4 * div(days, 1461); days %= 1461;
    if (days > 365) { gy += div(days - 1, 365); days = (days - 1) % 365; }
    let gd = days + 1; const sal = [0, 31, (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0 ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    let gm = 0; for (gm = 0; gm < 13 && gd > sal[gm]; gm++) gd -= sal[gm];
    return [gy, gm, gd];
  }
  const pad = n => String(n).padStart(2, '0');
  const en = s => String(s || '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
  const faN = s => String(s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
  const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
  /** طول ماه شمسی: اسفند در سال کبیسه ۳۰ روز است (با رفت‌وبرگشت تبدیل بررسی می‌شود) */
  function jLen(y, m) {
    if (m <= 6) return 31;
    if (m <= 11) return 30;
    const g = j2g(y, 12, 30), back = g2j(g[0], g[1], g[2]);
    return back[0] === y && back[1] === 12 && back[2] === 30 ? 30 : 29;
  }

  /** «YYYY-MM-DD» میلادی ← «۱۴۰۵/۰۷/۲۱» */
  function toJ(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); if (!m) return '';
    const j = g2j(+m[1], +m[2], +m[3]); return faN(j[0] + '/' + pad(j[1]) + '/' + pad(j[2]));
  }
  /** «۱۴۰۵/۰۷/۲۱» ← «YYYY-MM-DD» یا null */
  function fromJ(s) {
    const m = /^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/.exec(en(s).trim()); if (!m) return null;
    const y = +m[1], mo = +m[2], d = +m[3]; if (mo < 1 || mo > 12 || d < 1 || d > 31) return null;
    const g = j2g(y, mo, d); return g[0] + '-' + pad(g[1]) + '-' + pad(g[2]);
  }
  function isoDaysAgo(n) { const d = new Date(); d.setDate(d.getDate() - n); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

  /** انتخابگر تاریخ: input متنی شمسی؛ با کلیک، تقویم ماهانه باز می‌شود */
  function picker(input, onPick) {
    EB.closePop();
    const cur = fromJ(input.value) || isoDaysAgo(0);
    let [y, m] = g2j(...cur.split('-').map(Number));
    const sel = g2j(...cur.split('-').map(Number));
    const el = document.createElement('div'); el.className = 'pop jcal'; el.setAttribute('role', 'dialog');
    function draw() {
      const first = j2g(y, m, 1); const wd = (new Date(first[0], first[1] - 1, first[2]).getDay() + 1) % 7; // ۰=شنبه
      const len = jLen(y, m); const today = g2j(...isoDaysAgo(0).split('-').map(Number));
      let cells = '';
      for (let i = 0; i < wd; i++) cells += '<span></span>';
      for (let d = 1; d <= len; d++) {
        const on = sel[0] === y && sel[1] === m && sel[2] === d, t = today[0] === y && today[1] === m && today[2] === d;
        cells += `<button type="button" data-d="${d}" class="${on ? 'on' : ''} ${t ? 'today' : ''}">${faN(d)}</button>`;
      }
      el.innerHTML = `<div class="jc-h"><button type="button" data-n="-1" aria-label="ماه قبل">${EB.ic('caret-right')}</button><b>${MONTHS[m - 1]} ${faN(y)}</b><button type="button" data-n="1" aria-label="ماه بعد">${EB.ic('caret-left')}</button></div>
        <div class="jc-w">${['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'].map(x => `<span>${x}</span>`).join('')}</div><div class="jc-g">${cells}</div>`;
    }
    draw();
    el.addEventListener('click', e => {
      e.stopPropagation();
      const n = e.target.closest('[data-n]'); if (n) { m += +n.dataset.n; if (m < 1) { m = 12; y--; } if (m > 12) { m = 1; y++; } draw(); return; }
      const d = e.target.closest('[data-d]'); if (!d) return;
      const g = j2g(y, m, +d.dataset.d); const iso = g[0] + '-' + pad(g[1]) + '-' + pad(g[2]);
      input.value = toJ(iso); EB.closePop(); onPick && onPick(iso);
    });
    document.body.appendChild(el);
    requestAnimationFrame(() => el.classList.add('in'));
    const r = input.getBoundingClientRect();
    el.style.top = Math.min(window.innerHeight - el.offsetHeight - 8, r.bottom + 6) + 'px';
    el.style.left = Math.max(8, Math.min(window.innerWidth - el.offsetWidth - 8, r.right - el.offsetWidth)) + 'px';
    setTimeout(() => document.addEventListener('click', function off(ev) { if (!el.contains(ev.target) && ev.target !== input) { el.remove(); document.removeEventListener('click', off); } }), 0);
  }

  window.JD = {g2j, j2g, toJ, fromJ, isoDaysAgo, picker, faN, MONTHS};
})();
