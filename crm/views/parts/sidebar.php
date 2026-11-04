<?php
/**
 * جاکجاست - سایدبار
 * Jakojast - Sidebar (Partial)
 *
 * متغیرهای مورد نیاز:
 *   $currentUser  → اطلاعات کاربر جاری
 *   $pageName     → نام صفحه فعلی (برای فعال‌سازی آیتم)
 */
if (!defined('BASE_URL')) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    define('BASE_URL', rtrim($scriptDir, '/'));
}

// تعریف ساختار منو با شمارش badge از دیتابیس
$db = Database::getInstance();

// شمارش آیتم‌های منو (با مدیریت خطا در صورت نبود جدول)
function safeCount($sql, $params = []) {
    try {
        return (int)Database::getInstance()->fetchColumn($sql, $params);
    } catch (Exception $e) {
        return 0;
    }
}

// بهینه‌سازی: همه‌ی شمارش‌ها در یک رفت‌وبرگشت؛ در صورت خطا (مثلاً نبود جدول) روش قبلی
$__counts = null;
try {
    $__counts = Database::getInstance()->fetchOne(
        'SELECT (SELECT COUNT(*) FROM customers WHERE status != ?) AS c1,
                (SELECT COUNT(*) FROM leads WHERE stage NOT IN (?, ?)) AS c2,
                (SELECT COUNT(*) FROM hosts WHERE status = ?) AS c3,
                (SELECT COUNT(*) FROM host_leads) AS c4,
                (SELECT COUNT(*) FROM staff WHERE status = ?) AS c5,
                (SELECT COUNT(*) FROM leaves WHERE status = ?) AS c6,
                (SELECT COUNT(*) FROM tickets WHERE status != ?) AS c7',
        ['blocked', 'برنده', 'باخته', 'active', 'active', 'pending', 'closed']
    );
} catch (\Throwable $e) { $__counts = null; }
if (is_array($__counts) && isset($__counts['c7'])) {
    $customerCount = (int)$__counts['c1']; $leadCount = (int)$__counts['c2']; $hostCount = (int)$__counts['c3'];
    $hostLeadCount = (int)$__counts['c4']; $staffCount = (int)$__counts['c5']; $leavePending = (int)$__counts['c6'];
    $ticketOpenCount = (int)$__counts['c7'];
} else {
$customerCount   = safeCount('SELECT COUNT(*) FROM customers WHERE status != ?', ['blocked']);
$leadCount       = safeCount('SELECT COUNT(*) FROM leads WHERE stage NOT IN (?, ?)', ['برنده', 'باخته']);
$hostCount       = safeCount('SELECT COUNT(*) FROM hosts WHERE status = ?', ['active']);
$hostLeadCount   = safeCount('SELECT COUNT(*) FROM host_leads');
$staffCount      = safeCount('SELECT COUNT(*) FROM staff WHERE status = ?', ['active']);
$leavePending    = safeCount('SELECT COUNT(*) FROM leaves WHERE status = ?', ['pending']);
$ticketOpenCount = safeCount('SELECT COUNT(*) FROM tickets WHERE status != ?', ['closed']);
}
?>
<!-- ===========================
     سایدبار
     =========================== -->
<div id="sidebar">
    <div class="sidebar-logo">
        <div class="logo-icon">ج</div>
        <div>
            <div class="logo-text">جاکجاست</div>
            <div class="logo-sub">پنل مدیریت یکپارچه</div>
        </div>
    </div>

    <nav>
        <div class="nav-section">کار روزانه</div>
<a href="<?= BASE_URL ?>/index.php?page=dashboard" data-page="dashboard" class="<?= ($pageName ?? '') === 'dashboard' ? 'active' : '' ?>">
            <span class="nav-icon">📊</span> داشبورد اجرایی
        </a>
<?php if (Auth::hasPermission('calls')): ?>
        <a href="<?= BASE_URL ?>/index.php?page=calls" data-page="calls" class="<?= ($pageName ?? '') === 'calls' || ($pageName ?? '') === 'call_detail' ? 'active' : '' ?>">
            <span class="nav-icon">📞</span> تماس‌ها
        </a>
        <a href="<?= BASE_URL ?>/index.php?page=pipeline" data-page="pipeline" class="<?= ($pageName ?? '') === 'pipeline' ? 'active' : '' ?>">
            <span class="nav-icon">📋</span> پیگیری شماره‌ها
            <span class="nav-badge" id="nav-pl-badge" style="display:none"></span>
        </a>
        <?php endif; ?>
        <?php if (Auth::hasPermission('leads')): ?>        <a href="<?= BASE_URL ?>/index.php?page=leads" data-page="leads" class="<?= ($pageName ?? '') === 'leads' ? 'active' : '' ?>">
            <span class="nav-icon">🎯</span> سرنخ‌های فروش
            <?php if ($leadCount > 0): ?><span class="nav-badge"><?= $leadCount ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
<?php if (Auth::hasPermission('customers')): ?>
        <a href="<?= BASE_URL ?>/index.php?page=customers" data-page="customers" class="<?= ($pageName ?? '') === 'customers' || ($pageName ?? '') === 'customer_profile' ? 'active' : '' ?>">
            <span class="nav-icon">👥</span> مشتریان
            <?php if ($customerCount > 0): ?><span class="nav-badge"><?= $customerCount ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
<?php if (Auth::hasPermission('tickets')): ?>
        <a href="<?= BASE_URL ?>/index.php?page=tickets" data-page="tickets" class="<?= ($pageName ?? '') === 'tickets' ? 'active' : '' ?>">
            <span class="nav-icon">🎫</span> تیکت‌ها
            <?php if ($ticketOpenCount > 0): ?><span class="nav-badge"><?= $ticketOpenCount ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
<?php if (Auth::hasPermission('tasks')): ?>
        <a href="<?= BASE_URL ?>/index.php?page=tasks" data-page="tasks" class="<?= ($pageName ?? '') === 'tasks' ? 'active' : '' ?>">
            <span class="nav-icon">✅</span> تسک‌ها و پروژه‌ها
        </a>
        <?php endif; ?>

        <div class="nav-section">میزبانان و سایت</div>
        <?php if (Auth::hasPermission('hosts')): ?>        <a href="<?= BASE_URL ?>/index.php?page=hosts" data-page="hosts" class="<?= ($pageName ?? '') === 'hosts' || ($pageName ?? '') === 'host_profile' ? 'active' : '' ?>">
            <span class="nav-icon">🏠</span> میزبانان
            <?php if ($hostCount > 0): ?><span class="nav-badge"><?= $hostCount ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (Auth::hasPermission('host_leads')): ?>        <a href="<?= BASE_URL ?>/index.php?page=host_leads" data-page="host_leads" class="<?= ($pageName ?? '') === 'host_leads' ? 'active' : '' ?>">
            <span class="nav-icon">🔍</span> جذب میزبان
            <?php if ($hostLeadCount > 0): ?><span class="nav-badge"><?= $hostLeadCount ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (Auth::hasPermission('leads')): ?>
        <a href="<?= BASE_URL ?>/index.php?page=site_inquiries" data-page="site_inquiries" class="<?= ($pageName ?? '') === 'site_inquiries' ? 'active' : '' ?>">
            <span class="nav-icon">📨</span> استعلام‌های سایت
        </a>
        <?php endif; ?>
        <?php if (Auth::hasPermission('reports')): ?>
        <a href="<?= BASE_URL ?>/index.php?page=site_sales" data-page="site_sales" class="<?= ($pageName ?? '') === 'site_sales' ? 'active' : '' ?>">
            <span class="nav-icon">🧾</span> فروش سایت
        </a>
        <?php endif; ?>

        <div class="nav-section">تیم</div>
<?php if (Auth::hasPermission('staff')): ?>
        <a href="<?= BASE_URL ?>/index.php?page=staff" data-page="staff" class="<?= ($pageName ?? '') === 'staff' || ($pageName ?? '') === 'staff_profile' ? 'active' : '' ?>">
            <span class="nav-icon">💼</span> پرسنل
            <?php if ($staffCount > 0): ?><span class="nav-badge"><?= $staffCount ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (Auth::hasPermission('leaves')): ?>        <a href="<?= BASE_URL ?>/index.php?page=leaves" data-page="leaves" class="<?= ($pageName ?? '') === 'leaves' ? 'active' : '' ?>">
            <span class="nav-icon">🌴</span> مرخصی‌ها
            <?php if ($leavePending > 0): ?><span class="nav-badge" style="background:#ffc107;color:#333"><?= $leavePending ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if (Auth::hasPermission('payroll')): ?>        <a href="<?= BASE_URL ?>/index.php?page=payroll" data-page="payroll" class="<?= ($pageName ?? '') === 'payroll' ? 'active' : '' ?>">
            <span class="nav-icon">💰</span> حقوق و دستمزد
        </a>
        <?php endif; ?>
        <?php if (Auth::hasPermission('chat')): ?>        <a href="<?= BASE_URL ?>/index.php?page=chat" data-page="chat" class="<?= ($pageName ?? '') === 'chat' ? 'active' : '' ?>">
            <span class="nav-icon">💬</span> چت داخلی
        </a>
        <?php endif; ?>
        <?php
        // لینک «پروفایل من» - فقط اگر حساب کاربری جاری به یک پرسنل متصل باشد
        // (برای دسترسی سریع به مرخصی/ورود-خروج/فیش حقوقی خودش، مستقل از دسترسی ماژول پرسنل)
        $myStaffId = null;
        try {
            $myStaffId = Database::getInstance()->fetchColumn('SELECT id FROM staff WHERE user_id = ? LIMIT 1', [$currentUser['id'] ?? 0]);
        } catch (Exception $e) { /* ستون user_id هنوز migrate نشده */ }
        ?>
        <?php if (!empty($myStaffId)): ?>
        <a href="<?= BASE_URL ?>/index.php?page=staff_profile&id=<?= (int)$myStaffId ?>" data-page="my_profile" class="<?= (($pageName ?? '') === 'staff_profile' && (int)($_GET['id'] ?? 0) === (int)$myStaffId) ? 'active' : '' ?>">
            <span class="nav-icon">🙋</span> پروفایل من
        </a>
        <?php endif; ?>

        <div class="nav-section">سیستم</div>
<?php if (Auth::hasPermission('reports')): ?>
        <a href="<?= BASE_URL ?>/index.php?page=reports" data-page="reports" class="<?= ($pageName ?? '') === 'reports' ? 'active' : '' ?>">
            <span class="nav-icon">📈</span> گزارش‌ها
        </a>
        <?php endif; ?>
        <?php if (Auth::hasPermission('settings')): ?>        <a href="<?= BASE_URL ?>/index.php?page=settings" data-page="settings" class="<?= ($pageName ?? '') === 'settings' ? 'active' : '' ?>">
            <span class="nav-icon">⚙️</span> تنظیمات
        </a>
        <?php endif; ?>

    </nav>

    <div class="sidebar-footer">
        <div class="user-mini">
            <div class="user-avatar" style="background:<?= Helpers::avatarColor($currentUser['full_name'] ?? 'admin') ?>">
                <?= Helpers::initials($currentUser['full_name'] ?? 'A') ?>
            </div>
            <div>
                <div style="font-size:13px;font-weight:600"><?= Helpers::e($currentUser['full_name'] ?? 'مدیر سیستم') ?></div>
                <div style="font-size:11px;color:rgba(255,255,255,0.5)"><?= Helpers::e(ucfirst($currentUser['role'] ?? 'admin')) ?></div>
            </div>
        </div>
    </div>
</div>
