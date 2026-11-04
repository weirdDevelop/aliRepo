<?php
/**
 * جاکجاست - فوتر صفحه
 * Jakojast - Footer (Partial)
 *
 * شامل: نوار ناوبری موبایل، اسکریپت‌های مشترک، بستن تگ‌های HTML
 */
if (!defined('BASE_URL')) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    define('BASE_URL', rtrim($scriptDir, '/'));
}
?>

<!-- ===========================
     ناوبری موبایل پایین
     =========================== -->
<nav id="mobile-nav">
    <div class="mnav-items">
        <div class="mnav-item <?= ($pageName ?? '') === 'dashboard' ? 'active' : '' ?>" onclick="location.href='<?= BASE_URL ?>/index.php?page=dashboard'">
            <span class="mn-icon">📊</span>خانه
        </div>
        <div class="mnav-item <?= ($pageName ?? '') === 'customers' ? 'active' : '' ?>" onclick="location.href='<?= BASE_URL ?>/index.php?page=customers'">
            <span class="mn-icon">👥</span>مشتریان
        </div>
        <div class="mnav-item <?= ($pageName ?? '') === 'hosts' ? 'active' : '' ?>" onclick="location.href='<?= BASE_URL ?>/index.php?page=hosts'">
            <span class="mn-icon">🏠</span>میزبانان
        </div>
        <div class="mnav-item <?= ($pageName ?? '') === 'staff' ? 'active' : '' ?>" onclick="location.href='<?= BASE_URL ?>/index.php?page=staff'">
            <span class="mn-icon">💼</span>پرسنل
        </div>
        <div class="mnav-item <?= ($pageName ?? '') === 'tickets' ? 'active' : '' ?>" onclick="location.href='<?= BASE_URL ?>/index.php?page=tickets'">
            <span class="mn-icon">🎫</span>تیکت‌ها
        </div>
        <div class="mnav-item <?= ($pageName ?? '') === 'settings' ? 'active' : '' ?>" onclick="location.href='<?= BASE_URL ?>/index.php?page=settings'">
            <span class="mn-icon">⚙️</span>تنظیمات
        </div>
    </div>
</nav>

<!-- ===========================
     اسکریپت اصلی مشترک
     =========================== -->
<script src="https://cdn.jsdelivr.net/npm/plyr@3.7.8/dist/plyr.polyfilled.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/jalali-datepicker.js"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= @filemtime(__DIR__ . "/../../assets/js/app.js") ?>"></script>

<script>
// ===========================
// توابع UI مشترک
// ===========================

// تغییر وضعیت سایدبار موبایل
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('show');
    document.getElementById('sidebar-overlay').classList.toggle('show');
}

function closeSidebar() {
    document.getElementById('sidebar').classList.remove('show');
    document.getElementById('sidebar-overlay').classList.remove('show');
}

// تغییر وضعیت dropdown اعلان‌ها
function toggleNotifications() {
    const dropdown = document.getElementById('notif-dropdown');
    const userMenu = document.getElementById('user-menu');
    if (userMenu) userMenu.classList.remove('show');
    dropdown.classList.toggle('show');
}

// تغییر وضعیت منوی کاربر
function toggleUserMenu() {
    const menu = document.getElementById('user-menu');
    const notif = document.getElementById('notif-dropdown');
    if (notif) notif.classList.remove('show');
    menu.classList.toggle('show');
}

// بستن dropdown ها با کلیک خارج
document.addEventListener('click', function(e) {
    if (!e.target.closest('#notif-btn') && !e.target.closest('#notif-dropdown')) {
        const d = document.getElementById('notif-dropdown');
        if (d) d.classList.remove('show');
    }
    if (!e.target.closest('#user-menu') && !e.target.closest('.header-btn')) {
        // user menu
    }
});

// علامت‌گذاری همه اعلان‌ها به‌عنوان خوانده شده
function markAllNotificationsRead() {
    showToast('اعلان‌ها به‌عنوان خوانده شده علامت‌گذاری شدند', 'info');
    const badges = document.querySelectorAll('.badge-dot');
    badges.forEach(b => b.style.display = 'none');
}

// جستجوی جهانی
document.addEventListener('DOMContentLoaded', function() {
    const search = document.getElementById('global-search');
    if (search) {
        search.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const q = this.value.trim();
                if (q.length > 2) {
                    showToast('جستجو برای: ' + q, 'info');
                    // هدایت به صفحه جستجو (در مراحل بعد پیاده‌سازی می‌شود)
                }
            }
        });
    }
});


// ===== زنگوله: تماس‌های بی‌پاسخِ دیرشده + شماره‌های نزدیک به مهلت (r7) =====
<?php if (Auth::hasPermission('calls')): ?>
(function () {
    const fa = s => String(s ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const mins = s => fa(Math.round((s|0) / 60));
    async function loadAlerts() {
        if (typeof fetchAPI !== 'function') return;
        const r = await fetchAPI('pipeline/alerts', { silent: true, timeout: 45000 });
        if (!r || !r.success || !r.data) return;
        const d = r.data, total = (d.missed_overdue_count || 0) + (d.stage_warn || []).length;
        document.querySelectorAll('#notif-btn,#notif-btn-mob').forEach(b => {
            let x = b.querySelector('.pl-badge');
            if (!x) { x = document.createElement('span'); x.className = 'pl-badge'; b.style.position = 'relative'; b.appendChild(x); }
            x.textContent = fa(total > 99 ? '99+' : total); x.style.display = total ? '' : 'none';
            const dot = b.querySelector('.badge-dot'); if (dot) dot.style.display = 'none';
        });
        const nb = document.getElementById('nav-pl-badge');
        if (nb) { nb.textContent = fa(total); nb.style.display = total ? '' : 'none'; }
        const list = document.getElementById('notif-list'); if (!list) return;
        const base = '<?= BASE_URL ?>';
        let html = '';
        (d.missed_overdue || []).forEach(m => {
            html += `<a class="notif-item" style="display:block;text-decoration:none;color:inherit" href="${base}/index.php?page=phone_detail&number=${encodeURIComponent(m.phone || '')}">
              <div class="ni-text">⏰ <b>${esc(m.name || m.phone)}</b> — بی‌پاسخ، ${mins(m.elapsed_seconds)} دقیقه بدون پیگیری</div>
              <div class="ni-time" dir="ltr">${esc(m.phone || '')}</div></a>`;
        });
        (d.stage_warn || []).forEach(w => {
            html += `<a class="notif-item" style="display:block;text-decoration:none;color:inherit" href="${base}/index.php?page=pipeline&stage=${w.stage}">
              <div class="ni-text">⚠ <b>${esc(w.name || w.phone)}</b> در «${esc(w.stage_label)}» — ${w.remaining_seconds > 0 ? fa(Math.ceil(w.remaining_seconds / 3600)) + ' ساعت تا بسته‌شدن خودکار' : 'مهلت گذشته'}</div></a>`;
        });
        list.innerHTML = html || '<div class="notif-item"><div class="ni-text" style="text-align:center;color:var(--text-muted);padding:20px">مورد دیرشده‌ای نیست 👌</div></div>';
        if (d.missed_overdue_count > (d.missed_overdue || []).length) list.insertAdjacentHTML('beforeend', `<a class="notif-item" style="display:block" href="${base}/index.php?page=calls&type=missed">و ${fa(d.missed_overdue_count - d.missed_overdue.length)} بی‌پاسخ دیگر ←</a>`);
    }
    document.addEventListener('DOMContentLoaded', () => { setTimeout(loadAlerts, 1500); setInterval(loadAlerts, 90000); });
})();
<?php endif; ?>

// خروج با Confirm
function confirmLogout() {
    if (confirm('آیا از خروج اطمینان دارید؟')) {
        location.href = '<?= BASE_URL ?>/index.php?page=logout';
    }
}
</script>

</body>
</html>
