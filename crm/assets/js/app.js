/**
 * جاکجاست - منطق مشترک فرانت‌اند
 * Jakojast - Frontend Application Logic
 *
 * این فایل در تمام صفحات بارگذاری می‌شود و توابع مشترک را ارائه می‌دهد:
 *   - fetchAPI: مدیریت درخواست‌های AJAX با توکن
 *   - showToast: نمایش اعلان
 *   - formatNumber: فرمت اعداد فارسی
 *   - navigateTo: هدایت بین صفحات
 *   - openModal / closeModal: مدیریت مودال‌ها
 *   - makeAvatar: ساخت آواتار متن
 *   - getBadgeStatus: کلاس badge بر اساس وضعیت
 *   - Long Polling برای چت (در مرحله ۶ تکمیل می‌شود)
 *
 * توابع مشترک برای هر ماژول در همان صفحه بارگذاری می‌شوند.
 */

'use strict';

// ===========================
// تنظیمات سراسری
// ===========================
const JJ = {
    // توکن احراز هویت (از localStorage یا cookie)
    get token() {
        return localStorage.getItem('jakojast_token') || '';
    },
    set token(v) {
        if (v) localStorage.setItem('jakojast_token', v);
        else localStorage.removeItem('jakojast_token');
    },

    // کاربر جاری
    get user() {
        try {
            return JSON.parse(localStorage.getItem('jakojast_user') || 'null');
        } catch (e) {
            return null;
        }
    },
    set user(u) {
        if (u) localStorage.setItem('jakojast_user', JSON.stringify(u));
        else localStorage.removeItem('jakojast_user');
    },

    // BASE URL از meta tag یا window
    get baseUrl() {
        return window.JJ_BASE_URL || '';
    },
};

// تنظیم BASE_URL از meta tag اگر وجود دارد
if (document.querySelector('meta[name="jj-base-url"]')) {
    window.JJ_BASE_URL = document.querySelector('meta[name="jj-base-url"]').content;
}

// ===========================
// داده‌های ثابت
// ===========================
const AVATAR_COLORS = ['#e50241', '#17a2b8', '#28a745', '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#007bff', '#ff6b35', '#6f9e00'];
const MONTH_NAMES = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

// ===========================
// توابع کمکی
// ===========================

/**
 * دریافت رنگ آواتار بر اساس ایندکس یا نام
 */
function getAvatarColor(i) {
    if (typeof i === 'string') {
        let hash = 0;
        for (let j = 0; j < i.length; j++) hash += i.charCodeAt(j);
        return AVATAR_COLORS[hash % AVATAR_COLORS.length];
    }
    return AVATAR_COLORS[i % AVATAR_COLORS.length];
}

/**
 * استخراج حروف اول نام
 */
function initials(name) {
    if (!name) return '؟';
    const p = String(name).trim().split(/\s+/);
    if (p.length >= 2) return p[0][0] + p[1][0];
    return p[0].slice(0, 2);
}

/**
 * فرمت‌بندی عدد با جداکننده هزارگان
 */
function formatNumber(n) {
    if (n === null || n === undefined || n === '' || n === '-') return '-';
    if (isNaN(n)) return String(n);
    return Number(n).toLocaleString('fa-IR');
}

/**
 * تبدیل اعداد انگلیسی به فارسی
 */
function toPersianDigits(str) {
    const persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return String(str).replace(/[0-9]/g, d => persian[d]);
}

/**
 * تبدیل اعداد فارسی به انگلیسی
 */
function toEnglishDigits(str) {
    return String(str)
        .replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
        .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
}

// ===========================
// نمایش Toast (اعلان)
// ===========================
function showToast(msg, type = 'success') {
    const container = document.getElementById('toast-container');
    if (!container) {
        alert(msg);
        return;
    }
    const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `<span class="toast-icon">${icons[type] || 'ℹ️'}</span><span class="toast-msg">${msg}</span><span class="toast-close" onclick="this.parentElement.remove()">×</span>`;
    container.appendChild(toast);
    // انیمیشن ورود
    setTimeout(() => toast.classList.add('show'), 10);
    // حذف خودکار
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(-20px)';
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}

// ===========================
// مودال‌ها
// ===========================
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add('show');
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('show');
}

// بستن مودال با کلیک روی overlay
document.addEventListener('click', function (e) {
    if (e.target.classList && e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('show');
    }
});

// بستن مودال با کلید Escape
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.show').forEach(m => m.classList.remove('show'));
    }
});

// ===========================
// API Fetch با مدیریت توکن
// ===========================

/**
 * ارسال درخواست به API
 *
 * @param {string} endpoint  مسیر بعد از /api.php (مثلاً 'customers' یا 'customers/5')
 * @param {object} options   {method, body, headers, ...}
 * @returns {Promise<object>}  پاسخ JSON
 */
async function fetchAPI(endpoint, options = {}) {
    const url = `${JJ.baseUrl}/api.php/${endpoint.replace(/^\//, '')}`;
    const headers = {
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.headers || {}),
    };

    // اضافه کردن توکن
    if (JJ.token) {
        headers['Authorization'] = `Bearer ${JJ.token}`;
    }

    // اگر بدنه FormData نیست، JSON ست کن
    let body = options.body;
    if (body && typeof body === 'object' && !(body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(body);
    }

    try {
        // تایم‌اوت (پیش‌فرض ۳۰ ثانیه، برای sync بیشتر)
        const ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        const tmo = options.timeout || (/^\/?(site-)?sync/.test(endpoint) ? 120000 : 30000);
        const timer = ctrl ? setTimeout(() => ctrl.abort(), tmo) : null;
        let response;
        try {
            response = await fetch(url, {
                method: options.method || 'GET',
                headers,
                body,
                credentials: 'same-origin',
                signal: ctrl ? ctrl.signal : undefined,
            });
        } finally {
            if (timer) clearTimeout(timer);
        }

        // اگر 401 است، توکن منقضی شده
        if (response.status === 401) {
            JJ.token = null;
            JJ.user = null;
            showToast('نشست شما منقضی شده است. در حال هدایت به صفحه ورود...', 'warning');
            setTimeout(() => {
                window.location.href = `${JJ.baseUrl}/index.php?page=login`;
            }, 1500);
            throw new Error('Unauthorized');
        }

        // تلاش برای parse JSON
        let data;
        const contentType = response.headers.get('Content-Type') || '';
        if (contentType.includes('application/json')) {
            data = await response.json();
        } else if (contentType.includes('text/csv')) {
            // برای خروجی اکسل
            const blob = await response.blob();
            return { success: true, blob, _isBlob: true };
        } else {
            data = await response.text();
            try {
                data = JSON.parse(data);
            } catch (e) {
                // متن خام
            }
        }

        if (!response.ok && (!data || !data.success)) {
            const errMsg = (data && data.message) || `خطای HTTP ${response.status}`;
            // در صفحات HTML نیازی به throw نیست، ولی برای API callerها مفید است
            return {
                success: false,
                message: errMsg,
                data: null,
                meta: (data && data.meta) || {},
                _httpStatus: response.status,
            };
        }

        return data;
    } catch (err) {
        console.error('API Error:', endpoint, err);
        if (err && err.message === 'Unauthorized') throw err;
        // درخواست‌های پس‌زمینه (polling) بی‌صدا شکست می‌خورند تا toast تکراری نشان داده نشود
        if (options.silent || document.hidden) {
            return { success: false, message: 'network', data: null, meta: {}, _network: true };
        }
        const isTimeout = err && err.name === 'AbortError';
        showToast(isTimeout ? 'پاسخ سرور طول کشید؛ دوباره تلاش کنید.' : 'خطا در ارتباط با سرور. لطفاً اتصال اینترنت خود را بررسی کنید.', 'error');
        return { success: false, message: isTimeout ? 'timeout' : 'network', data: null, meta: {}, _network: true };
    }
}

// ===========================
// ناوبری
// ===========================

/**
 * هدایت به صفحه دیگر
 * @param {string} page  نام صفحه
 * @param {object} params  پارامترهای query string
 */
function navigateTo(page, params = {}) {
    const url = new URL(`${JJ.baseUrl}/index.php`, window.location.origin);
    url.searchParams.set('page', page);
    Object.entries(params).forEach(([k, v]) => {
        url.searchParams.set(k, v);
    });
    window.location.href = url.toString();
}

// ===========================
// آواتار و badge
// ===========================

/**
 * ساخت HTML آواتار با حروف اول نام
 */
function makeAvatar(name, idx = 0, size = '') {
    const color = typeof idx === 'number' ? getAvatarColor(idx) : getAvatarColor(name);
    const sz = size ? `style="width:${size};height:${size};font-size:${parseInt(size) * 0.4}px"` : '';
    return `<div class="avatar" ${sz} style="background:${color};${sz ? sz.match(/style="([^"]+)"/)?.[1] : ''}">${initials(name)}</div>`;
}

/**
 * کلاس badge بر اساس وضعیت
 */
function getBadgeStatus(status) {
    const map = {
        active: 'badge-success', inactive: 'badge-secondary', blocked: 'badge-danger',
        pending: 'badge-warning', approved: 'badge-success', rejected: 'badge-danger',
        open: 'badge-danger', in_review: 'badge-warning', answered: 'badge-info', closed: 'badge-secondary',
        done: 'badge-success', in_progress: 'badge-info', review: 'badge-warning',
        new: 'badge-primary', cancelled: 'badge-secondary',
        paid: 'badge-success',
        'جذب اولیه': 'badge-info', 'بی پاسخ': 'badge-warning', 'عدم تمایل': 'badge-danger',
        VIP: 'badge-gold', 'طلایی': 'badge-warning', 'نقره‌ای': 'badge-secondary',
        'برنز': 'badge-secondary', 'عادی': 'badge-light',
    };
    return map[status] || 'badge-secondary';
}

/**
 * برچسب فارسی وضعیت
 */
function getStatusLabel(status) {
    const map = {
        active: 'فعال', inactive: 'غیرفعال', blocked: 'مسدود',
        pending: 'در انتظار', approved: 'تأیید شده', rejected: 'رد شده',
        open: 'باز', in_review: 'در بررسی', answered: 'پاسخ داده شده', closed: 'بسته شده',
        done: 'انجام شده', in_progress: 'در حال انجام', review: 'در انتظار بررسی',
        new: 'جدید', cancelled: 'لغو شده',
        paid: 'پرداخت شده',
        inbound: 'ورودی', outbound: 'خروجی', missed: 'بی‌پاسخ', internal: 'داخلی',
        answered: 'پاسخ داده شد', no_answer: 'بی‌پاسخ', busy: 'مشغول', failed: 'ناموفق',
        present: 'حاضر', late: 'تأخیر', absent: 'غایب', leave: 'مرخصی',
    };
    return map[status] || status;
}

/**
 * ساخت ستاره‌های امتیاز
 */
function makeStars(rating) {
    const r = parseFloat(rating) || 0;
    let html = '<span style="color:#ffc107">';
    for (let i = 1; i <= 5; i++) {
        if (r >= i) html += '★';
        else if (r >= i - 0.5) html += '⯨';
        else html += '☆';
    }
    html += '</span>';
    return html;
}

// ===========================
// صفحه‌بندی
// ===========================

/**
 * رندر کنترل صفحه‌بندی
 * @param {string} containerId  شناسه المنت
 * @param {number} currentPage  صفحه فعلی
 * @param {number} totalPages   تعداد کل صفحات
 * @param {function} onPageChange  callback
 */
function renderPagination(containerId, currentPage, totalPages, onPageChange) {
    const container = document.getElementById(containerId);
    if (!container) return;
    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = '';
    // دکمه قبلی
    html += `<button class="page-btn ${currentPage === 1 ? 'disabled' : ''}" ${currentPage === 1 ? 'disabled' : `onclick="(${onPageChange})(${currentPage - 1})"`}>«</button>`;

    // تعیین محدوده صفحات نمایش داده شده
    let start = Math.max(1, currentPage - 2);
    let end = Math.min(totalPages, start + 4);
    if (end - start < 4) start = Math.max(1, end - 4);

    // صفحه اول
    if (start > 1) {
        html += `<button class="page-btn" onclick="(${onPageChange})(1)">۱</button>`;
        if (start > 2) html += '<span class="page-dots">...</span>';
    }

    // صفحات میانی
    for (let i = start; i <= end; i++) {
        html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="(${onPageChange})(${i})">${toPersianDigits(i)}</button>`;
    }

    // صفحه آخر
    if (end < totalPages) {
        if (end < totalPages - 1) html += '<span class="page-dots">...</span>';
        html += `<button class="page-btn" onclick="(${onPageChange})(${totalPages})">${toPersianDigits(totalPages)}</button>`;
    }

    // دکمه بعدی
    html += `<button class="page-btn ${currentPage === totalPages ? 'disabled' : ''}" ${currentPage === totalPages ? 'disabled' : `onclick="(${onPageChange})(${currentPage + 1})"`}>»</button>`;

    container.innerHTML = html;
}

// ===========================
// تب‌ها
// ===========================

/**
 * تغییر تب فعال
 * @param {string} prefix  پیشوند ID تب‌ها (مثلاً 'cp' برای customer_profile)
 * @param {string} tabName نام تب
 * @param {element} btn    دکمه کلیک شده
 */
function switchTab(prefix, tabName, btn) {
    const container = btn.closest('.card') || btn.closest('.page') || document;
    // مخفی کردن همه تب‌ها
    container.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    container.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    // نمایش تب انتخاب شده
    const target = document.getElementById(prefix + '-' + tabName);
    if (target) target.classList.add('active');
    btn.classList.add('active');
}

// ===========================
// تبدیل متن
// ===========================

/**
 * محاسبه زمان گذشته (relative time)
 */
function timeAgo(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return '-';
    const diff = Date.now() - date.getTime();
    const seconds = Math.floor(diff / 1000);
    if (seconds < 60) return 'چند لحظه پیش';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${toPersianDigits(minutes)} دقیقه پیش`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${toPersianDigits(hours)} ساعت پیش`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${toPersianDigits(days)} روز پیش`;
    const weeks = Math.floor(days / 7);
    if (weeks < 4) return `${toPersianDigits(weeks)} هفته پیش`;
    const months = Math.floor(days / 30);
    if (months < 12) return `${toPersianDigits(months)} ماه پیش`;
    const years = Math.floor(days / 365);
    return `${toPersianDigits(years)} سال پیش`;
}

/**
 * کوتاه کردن متن
 */
function truncate(text, length = 50) {
    text = String(text || '');
    if (text.length <= length) return text;
    return text.slice(0, length) + '...';
}

// ===========================
// تأیید حذف
// ===========================
function confirmDelete(msg) {
    return window.confirm(msg || 'آیا از حذف این مورد اطمینان دارید؟');
}

// ===========================
// دانلود فایل
// ===========================

/**
 * دانلود فایل از Blob
 */
function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

/**
 * دانلود خروجی اکسل
 */
async function exportCSV(endpoint, filename) {
    showToast('در حال آماده‌سازی فایل...', 'info');
    try {
        const result = await fetchAPI(endpoint);
        if (result._isBlob) {
            downloadBlob(result.blob, filename);
            showToast('فایل دانلود شد', 'success');
        } else {
            showToast('خطا در دریافت فایل', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

// ===========================
// خروج کاربر
// ===========================
function logout() {
    if (!confirm('آیا از خروج اطمینان دارید؟')) return;
    var ebUrl = document.querySelector('meta[name="jj-eb-url"]');
    if (ebUrl) { try { window.top.location.href = ebUrl.content + '?page=logout'; } catch (e) { location.href = ebUrl.content + '?page=logout'; } return; }
    fetchAPI('auth/logout', { method: 'POST' })
        .finally(() => {
            JJ.token = null;
            JJ.user = null;
            window.location.href = `${JJ.baseUrl}/index.php?page=login`;
        });
}

// ===========================
// initializing هنگام بارگذاری صفحه
// ===========================
document.addEventListener('DOMContentLoaded', function () {
    // تنظیم BASE_URL اگر meta tag وجود دارد
    const meta = document.querySelector('meta[name="jj-base-url"]');
    if (meta) window.JJ_BASE_URL = meta.content;

    // نمایش نام کاربر در سایدبار اگر وجود دارد
    const userMini = document.querySelector('.sidebar-footer .user-mini');
    if (userMini && JJ.user) {
        const nameEl = userMini.querySelector('div:nth-child(2) > div:first-child');
        if (nameEl) nameEl.textContent = JJ.user.full_name || 'کاربر';
    }
});

// ===========================
// توابع Long Polling چت (در مرحله ۶ تکمیل می‌شود)
// ===========================
let chatPollActive = false;
let chatLastMessageId = 0;

function startChatPolling(channel) {
    // در مرحله ۶ پیاده‌سازی می‌شود
    console.log('Chat polling not yet implemented (phase 6)');
}

function stopChatPolling() {
    chatPollActive = false;
}

// ===========================
// مدیریت خطاهای سراسری
// ===========================
window.addEventListener('unhandledrejection', function (event) {
    console.error('Unhandled promise rejection:', event.reason);
});

// ===========================
// خروجی برای استفاده در صفحات
// ===========================
window.JJ = JJ;
window.fetchAPI = fetchAPI;
window.showToast = showToast;
window.navigateTo = navigateTo;
window.openModal = openModal;
window.closeModal = closeModal;
window.formatNumber = formatNumber;
window.toPersianDigits = toPersianDigits;
window.toEnglishDigits = toEnglishDigits;
window.makeAvatar = makeAvatar;
window.getBadgeStatus = getBadgeStatus;
window.getStatusLabel = getStatusLabel;
window.makeStars = makeStars;
window.renderPagination = renderPagination;
window.switchTab = switchTab;
window.timeAgo = timeAgo;
window.truncate = truncate;
window.confirmDelete = confirmDelete;
window.downloadBlob = downloadBlob;
window.exportCSV = exportCSV;
window.logout = logout;
window.initials = initials;
window.getAvatarColor = getAvatarColor;
window.AVATAR_COLORS = AVATAR_COLORS;
window.MONTH_NAMES = MONTH_NAMES;

// ===========================
// جستجوی سراسری (هدر بالای صفحه)
// ===========================
(function initGlobalSearch() {
    let searchTimeout = null;

    document.addEventListener('DOMContentLoaded', () => {
        const input = document.getElementById('global-search');
        const resultsBox = document.getElementById('global-search-results');
        if (!input || !resultsBox) return;

        input.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            const q = input.value.trim();
            if (q.length < 2) {
                resultsBox.style.display = 'none';
                return;
            }
            searchTimeout = setTimeout(() => runGlobalSearch(q, resultsBox), 350);
        });

        // بستن با کلیک بیرون از باکس
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.header-search')) {
                resultsBox.style.display = 'none';
            }
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') resultsBox.style.display = 'none';
        });
    });

    async function runGlobalSearch(q, resultsBox) {
        resultsBox.style.display = 'block';
        resultsBox.innerHTML = '<div style="padding:14px;text-align:center;color:var(--text-muted);font-size:13px">در حال جستجو...</div>';

        try {
            const result = await fetchAPI('search?q=' + encodeURIComponent(q), { silent: true });
            if (!result.success) {
                resultsBox.innerHTML = `<div style="padding:14px;color:var(--danger);font-size:13px">${result.message || 'خطا در جستجو'}</div>`;
                return;
            }

            const d = result.data;
            const sections = [
                { key: 'customers', label: '👤 مشتریان', link: (r) => `${JJ.baseUrl}/index.php?page=customer_profile&id=${r.id}`, text: (r) => `${r.name} ${r.mobile ? '— ' + r.mobile : ''}` },
                { key: 'leads', label: '🎯 سرنخ‌ها', link: (r) => `${JJ.baseUrl}/index.php?page=leads&id=${r.id}`, text: (r) => `${r.name} ${r.mobile ? '— ' + r.mobile : ''}` },
                { key: 'hosts', label: '🏠 میزبانان', link: (r) => `${JJ.baseUrl}/index.php?page=host_profile&id=${r.id}`, text: (r) => `${r.name} ${r.mobile ? '— ' + r.mobile : ''}` },
                { key: 'tickets', label: '🎫 تیکت‌ها', link: (r) => `${JJ.baseUrl}/index.php?page=tickets&id=${r.id}`, text: (r) => r.subject },
                { key: 'staff', label: '💼 پرسنل', link: (r) => `${JJ.baseUrl}/index.php?page=staff_profile&id=${r.id}`, text: (r) => `${r.name} ${r.code ? '(' + r.code + ')' : ''}` },
            ];

            let html = '';
            let totalFound = 0;
            sections.forEach(sec => {
                const items = d[sec.key] || [];
                if (!items.length) return;
                totalFound += items.length;
                html += `<div style="padding:8px 14px 4px;font-size:11px;color:var(--text-muted);font-weight:600">${sec.label}</div>`;
                items.forEach(r => {
                    html += `<a href="${sec.link(r)}" style="display:block;padding:8px 14px;font-size:13px;color:var(--text);text-decoration:none;border-bottom:1px solid var(--border)" onmouseover="this.style.background='var(--hover-bg,#f5f5f5)'" onmouseout="this.style.background='transparent'">${sec.text(r)}</a>`;
                });
            });

            resultsBox.innerHTML = totalFound
                ? html
                : '<div style="padding:14px;text-align:center;color:var(--text-muted);font-size:13px">نتیجه‌ای یافت نشد</div>';
        } catch (err) {
            console.error('Global search error:', err);
            resultsBox.innerHTML = '<div style="padding:14px;color:var(--danger);font-size:13px">خطا در ارتباط با سرور</div>';
        }
    }
})();
