/**
 * جاکجاست - انتخابگر تاریخ شمسی (بدون وابستگی خارجی)
 * Jakojast - Vanilla Jalali Datepicker
 *
 * چرا این فایل لازم شد: در کل پروژه هیچ تقویم گرافیکی برای انتخاب تاریخ
 * وجود نداشت (نه برای تاریخ تولد، نه برای درخواست مرخصی، نه برای فیلترهای
 * تاریخ) - همه‌جا کاربر باید تاریخ را دستی به‌فرمت "1405/06/01" تایپ
 * می‌کرد. این فایل یک تقویم شمسی سبک و بدون کتابخانه‌ی خارجی اضافه
 * می‌کند که با کلیک روی هر اینپوتی با کلاس "jalali-date-input" باز می‌شود.
 *
 * الگوریتم تبدیل شمسی/میلادی دقیقاً از core/Jalali.php پورت شده تا با
 * محاسبات سمت سرور کاملاً یکی باشد.
 *
 * استفاده:
 *   <input type="text" class="form-control jalali-date-input" id="my-date">
 * همین کافی است؛ این فایل خودش روی همه‌ی اینپوت‌های این کلاس فعال می‌شود.
 */

(function () {
    const MONTH_NAMES = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const WEEKDAY_NAMES = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج']; // شنبه تا جمعه

    function toEnglishDigits(str) {
        return String(str).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
    }
    function toPersianDigits(num) {
        return String(num).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    }

    // ===== پورت دقیق الگوریتم core/Jalali.php =====
    function toJalali(gy, gm, gd) {
        const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        let jy = (gy <= 1600) ? 0 : 979;
        gy -= (gy <= 1600) ? 621 : 1600;
        const gy2 = (gm > 2) ? (gy + 1) : gy;
        let days = (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) - 80 + gd + g_d_m[gm - 1];
        jy += 33 * Math.floor(days / 12053);
        days %= 12053;
        jy += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) {
            jy += Math.floor((days - 1) / 365);
            days = (days - 1) % 365;
        }
        const jm = (days < 186) ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
        const jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));
        return [jy, jm, jd];
    }

    function toGregorian(jy, jm, jd) {
        let gy = (jy <= 979) ? 621 : 1600;
        jy -= (jy <= 979) ? 0 : 979;
        let days = (365 * jy) + (Math.floor(jy / 33) * 8) + Math.floor((jy % 33 + 3) / 4) + 78 + jd + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
        gy += 400 * Math.floor(days / 146097);
        days %= 146097;
        if (days > 36524) {
            days--;
            gy += 100 * Math.floor(days / 36524);
            days %= 36524;
            if (days >= 365) days++;
        }
        gy += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) {
            gy += Math.floor((days - 1) / 365);
            days = (days - 1) % 365;
        }
        let gd = days + 1;
        const isLeap = (gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0);
        const sal_a = [0, 31, isLeap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        let gm = 0;
        for (gm = 0; gm < 13 && gd > sal_a[gm]; gm++) {
            gd -= sal_a[gm];
        }
        return [gy, gm, gd];
    }

    function daysInJalaliMonth(jy, jm) {
        if (jm <= 6) return 31;
        if (jm <= 11) return 30;
        // اسفند - تشخیص کبیسه با تبدیل رفت‌وبرگشت (ساده و قابل‌اعتماد)
        const [gy, gm, gd] = toGregorian(jy, 12, 30);
        const [jy2] = toJalali(gy, gm, gd);
        return jy2 === jy ? 30 : 29;
    }

    function todayJalali() {
        const now = new Date();
        return toJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
    }

    // ===== ساخت و مدیریت تقویم =====
    let activeInput = null;
    let viewYear, viewMonth;
    let popupEl = null;

    function ensurePopup() {
        if (popupEl) return popupEl;
        popupEl = document.createElement('div');
        popupEl.id = 'jj-jalali-calendar';
        popupEl.style.cssText = `
            position:absolute; z-index:9999; background:var(--card-bg,#fff);
            border:1px solid var(--border,#ddd); border-radius:10px;
            box-shadow:0 10px 30px rgba(0,0,0,0.15); padding:12px; width:280px;
            font-family:inherit; display:none; direction:rtl;
        `;
        document.body.appendChild(popupEl);
        return popupEl;
    }

    function render() {
        const el = ensurePopup();
        const monthName = MONTH_NAMES[viewMonth - 1];
        const daysCount = daysInJalaliMonth(viewYear, viewMonth);

        // پیدا کردن روز هفته‌ی اول ماه (۰=شنبه ... ۶=جمعه)
        const [gy, gm, gd] = toGregorian(viewYear, viewMonth, 1);
        const jsWeekday = new Date(gy, gm - 1, gd).getDay(); // 0=یکشنبه در JS
        const firstDayOffset = (jsWeekday + 1) % 7; // تبدیل به شنبه=۰

        const [ty, tm, td] = todayJalali();

        let selJy = null, selJm = null, selJd = null;
        if (activeInput && activeInput.value) {
            const parts = toEnglishDigits(activeInput.value).split(/[\/\-.]/);
            if (parts.length === 3) {
                selJy = parseInt(parts[0]); selJm = parseInt(parts[1]); selJd = parseInt(parts[2]);
            }
        }

        let daysHtml = '';
        for (let i = 0; i < firstDayOffset; i++) daysHtml += '<div></div>';
        for (let d = 1; d <= daysCount; d++) {
            const isToday = (ty === viewYear && tm === viewMonth && td === d);
            const isSelected = (selJy === viewYear && selJm === viewMonth && selJd === d);
            daysHtml += `<div class="jj-jcal-day${isToday ? ' jj-jcal-today' : ''}${isSelected ? ' jj-jcal-selected' : ''}" data-day="${d}">${toPersianDigits(d)}</div>`;
        }

        el.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <button type="button" class="jj-jcal-nav" data-nav="next" style="border:none;background:none;cursor:pointer;font-size:16px;padding:4px 8px">›</button>
                <div style="display:flex;gap:6px;align-items:center;font-weight:600;font-size:14px">
                    <span>${monthName}</span>
                    <input type="number" class="jj-jcal-year" value="${viewYear}" style="width:64px;text-align:center;border:1px solid var(--border,#ddd);border-radius:6px;padding:2px 4px;font-size:13px">
                </div>
                <button type="button" class="jj-jcal-nav" data-nav="prev" style="border:none;background:none;cursor:pointer;font-size:16px;padding:4px 8px">‹</button>
            </div>
            <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-bottom:4px;font-size:11px;color:var(--text-muted,#888);text-align:center">
                ${WEEKDAY_NAMES.map(w => `<div>${w}</div>`).join('')}
            </div>
            <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;font-size:13px">
                ${daysHtml}
            </div>
            <div style="display:flex;justify-content:space-between;margin-top:10px;padding-top:8px;border-top:1px solid var(--border,#eee)">
                <button type="button" class="jj-jcal-today-btn" style="border:none;background:none;color:var(--primary,#e50241);cursor:pointer;font-size:12px">امروز</button>
                <button type="button" class="jj-jcal-close-btn" style="border:none;background:none;color:var(--text-muted,#888);cursor:pointer;font-size:12px">بستن</button>
            </div>
        `;

        // استایل روزها
        el.querySelectorAll('.jj-jcal-day').forEach(dayEl => {
            dayEl.style.cssText = 'padding:6px 0;cursor:pointer;border-radius:6px;';
            dayEl.addEventListener('mouseover', () => { if (!dayEl.classList.contains('jj-jcal-selected')) dayEl.style.background = 'var(--hover-bg,#f0f0f0)'; });
            dayEl.addEventListener('mouseout', () => { if (!dayEl.classList.contains('jj-jcal-selected')) dayEl.style.background = ''; });
            dayEl.addEventListener('click', () => {
                const d = parseInt(dayEl.dataset.day);
                selectDate(viewYear, viewMonth, d);
            });
        });
        el.querySelectorAll('.jj-jcal-today').forEach(e => e.style.color = 'var(--primary,#e50241)');
        el.querySelectorAll('.jj-jcal-selected').forEach(e => {
            e.style.background = 'var(--primary,#e50241)';
            e.style.color = '#fff';
        });

        el.querySelector('[data-nav="next"]').addEventListener('click', () => {
            viewMonth++; if (viewMonth > 12) { viewMonth = 1; viewYear++; }
            render();
        });
        el.querySelector('[data-nav="prev"]').addEventListener('click', () => {
            viewMonth--; if (viewMonth < 1) { viewMonth = 12; viewYear--; }
            render();
        });
        el.querySelector('.jj-jcal-year').addEventListener('change', (e) => {
            viewYear = parseInt(e.target.value) || viewYear;
            render();
        });
        el.querySelector('.jj-jcal-today-btn').addEventListener('click', () => {
            const [ty2, tm2, td2] = todayJalali();
            selectDate(ty2, tm2, td2);
        });
        el.querySelector('.jj-jcal-close-btn').addEventListener('click', closeCalendar);
    }

    function selectDate(jy, jm, jd) {
        if (!activeInput) return;
        const formatted = `${jy}/${String(jm).padStart(2, '0')}/${String(jd).padStart(2, '0')}`;
        activeInput.value = formatted;
        activeInput.dispatchEvent(new Event('change', { bubbles: true }));
        activeInput.dispatchEvent(new Event('input', { bubbles: true }));
        closeCalendar();
    }

    function openCalendar(input) {
        activeInput = input;
        const existing = toEnglishDigits(input.value || '').split(/[\/\-.]/);
        if (existing.length === 3 && !isNaN(parseInt(existing[0]))) {
            viewYear = parseInt(existing[0]);
            viewMonth = parseInt(existing[1]);
        } else {
            [viewYear, viewMonth] = todayJalali();
        }
        render();
        const el = ensurePopup();
        const rect = input.getBoundingClientRect();
        el.style.display = 'block';
        el.style.top = (window.scrollY + rect.bottom + 4) + 'px';
        el.style.left = (window.scrollX + rect.left) + 'px';
    }

    function closeCalendar() {
        if (popupEl) popupEl.style.display = 'none';
        activeInput = null;
    }

    document.addEventListener('click', (e) => {
        if (e.target.classList && e.target.classList.contains('jalali-date-input')) {
            openCalendar(e.target);
        } else if (popupEl && !popupEl.contains(e.target)) {
            closeCalendar();
        }
    });

    document.addEventListener('focusin', (e) => {
        if (e.target.classList && e.target.classList.contains('jalali-date-input')) {
            e.target.readOnly = true; // جلوگیری از تایپ دستی نادرست - فقط از تقویم انتخاب شود
            openCalendar(e.target);
        }
    });

    // برای اینپوت‌هایی که بعداً به DOM اضافه می‌شوند (مثلاً داخل مودال‌های JS)
    window.jjInitJalaliDatepickers = function (container) {
        (container || document).querySelectorAll('.jalali-date-input').forEach(input => {
            input.readOnly = true;
        });
    };
})();
