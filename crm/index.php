<?php
/**
 * جاکجاست - نقطه ورودی و روتینگ ویوها
 * Jakojast - Entry Point & View Router
 *
 * این فایل صفحات HTML را serve می‌کند (front-end).
 * تمام درخواست‌های /api/* توسط api.php هندل می‌شوند.
 *
 * ساختار URL:
 *   /                     → داشبورد (در صورت لاگین) یا لاگین
 *   /?page=login          → صفحه لاگین
 *   /?page=dashboard      → داشبورد
 *   /?page=customers      → مشتریان
 *   /?page=customer_profile&id=123
 *   /?page=hosts, host_profile, staff, staff_profile,
 *          leads, host_leads, tickets, chat, tasks,
 *          reports, calls, call_detail, settings,
 *          leaves, payroll
 */

require_once __DIR__ . '/config.php';

// سیستم یکپارچه: اگر جدول‌های CRM هنوز در دیتابیس اعلان‌بان ساخته/منتقل نشده‌اند
if (defined('CRM_BRIDGED') && CRM_BRIDGED) {
    try { Database::getInstance()->fetchColumn('SELECT 1 FROM `users` LIMIT 1'); $__crmReady = true; }
    catch (Throwable $e) { $__crmReady = false; }
    if (!$__crmReady) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><body style="font-family:Vazirmatn,Tahoma;background:#f6f7f9;margin:0;display:grid;place-items:center;min-height:100vh">'
           . '<div style="background:#fff;border-radius:14px;padding:28px 30px;max-width:520px;box-shadow:0 4px 20px rgba(0,0,0,.06);line-height:2">'
           . '<h3 style="margin:0 0 6px">CRM هنوز راه‌اندازی نشده</h3>'
           . 'جدول‌های CRM در دیتابیس پیدا نشدند. در <b>اعلان‌بان ← تنظیمات ← CRM</b> یا اطلاعات CRM قبلی را منتقل کنید، یا جدول‌های خالی را بسازید.'
           . '</div></body></html>';
        exit;
    }
}

// لیست صفحات مجاز (whitelist)
$allowedPages = [
    'login',
    'dashboard',
    'customers', 'customer_profile',
    'hosts', 'host_profile',
    'staff', 'staff_profile',
    'leads', 'host_leads',
    'tickets', 'chat', 'tasks',
    'reports',
    'calls', 'call_detail', 'phone_detail', 'pipeline',
    'site_inquiries', 'site_sales',
    'settings',
    'leaves', 'payroll',
    'logout',
    'my_profile',
];

// تعیین صفحه فعلی
$requestedPage = $_GET['page'] ?? '';

// اگر page خالی است و کاربر لاگین کرده → داشبورد
// اگر page خالی است و لاگین نکرده → لاگین
if ($requestedPage === '') {
    $requestedPage = Auth::check() ? 'dashboard' : 'login';
}

// سیستم یکپارچه: ورود و خروج فقط از اعلان‌بان (کل پنجره، نه داخل قاب)
if (defined('CRM_BRIDGED') && CRM_BRIDGED && ($requestedPage === 'login' || $requestedPage === 'logout' || !Auth::check())) {
    $to = Auth::elanbanUrl($requestedPage === 'logout' ? 'page=logout' : 'page=login');
    if ($requestedPage === 'login' && Auth::check()) $to = Auth::elanbanUrl();
    header('Content-Type: text/html; charset=utf-8');
    $toJs = json_encode($to, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    echo '<!doctype html><meta charset="utf-8"><title>…</title><script>try{(window.top||window).location.href=' . $toJs . ';}catch(e){location.href=' . $toJs . ';}</script>'
       . '<p style="font-family:Tahoma;text-align:center;margin-top:40px"><a href="' . htmlspecialchars($to) . '" target="_top">ورود به پنل</a></p>';
    exit;
}

// اگر logout درخواست شده
if ($requestedPage === 'logout') {
    Auth::logout();
    header('Location: index.php?page=login');
    exit;
}

// اگر صفحه مجاز نیست → 404
if (!in_array($requestedPage, $allowedPages, true)) {
    http_response_code(404);
    die('صفحه مورد نظر یافت نشد.');
}

// اگر صفحه لاگین نیست و کاربر لاگین نکرده → هدایت به لاگین
if ($requestedPage !== 'login' && !Auth::check()) {
    header('Location: index.php?page=login');
    exit;
}

// اگر صفحه لاگین است و کاربر لاگین کرده → هدایت به داشبورد
if ($requestedPage === 'login' && Auth::check()) {
    header('Location: index.php?page=dashboard');
    exit;
}

// پرونده‌ی پرسنلی خود کاربر (منوی «پرونده‌ی پرسنلی من» در اعلان‌بان)
if ($requestedPage === 'my_profile') {
    $__me = Auth::currentUser();
    $__sid = null;
    try { $__sid = Database::getInstance()->fetchColumn('SELECT id FROM staff WHERE user_id = ? LIMIT 1', [$__me['id']]); } catch (Exception $e) {}
    if ($__sid) { header('Location: index.php?page=staff_profile&id=' . (int)$__sid . (isset($_GET['embed']) ? '&embed=1' : '')); exit; }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><body style="font-family:Vazirmatn,Tahoma;background:#f6f7f9;margin:0;display:grid;place-items:center;min-height:100vh">'
       . '<div style="background:#fff;border-radius:14px;padding:26px 28px;max-width:480px;line-height:2;box-shadow:0 4px 20px rgba(0,0,0,.06)">'
       . '<h3 style="margin:0 0 6px">پرونده‌ی پرسنلی شما وصل نشده</h3>حساب شما هنوز به یک پرونده در «پرسنل» وصل نیست. مدیر می‌تواند از <b>تنظیمات ← CRM ← اتصال همکاران</b> وصل کند.</div></body></html>';
    exit;
}

// ===== بررسی دسترسی (سیستم مجوزهای پرسنل) =====
// نگاشت هر صفحه به کلید دسترسی مربوطه (چند صفحه ممکن است زیرمجموعه‌ی
// یک ماژول باشند، مثلاً customer_profile زیرمجموعه‌ی customers است)
$pagePermissionMap = [
    'customer_profile' => 'customers',
    'host_profile'      => 'hosts',
    'staff_profile'     => 'staff',
    'call_detail'       => 'calls',
    'phone_detail'      => 'calls',
    'pipeline'          => 'calls',
    'site_inquiries'    => 'leads',
    'site_sales'        => 'reports',
];
$requiredPermission = $pagePermissionMap[$requestedPage] ?? $requestedPage;
$permissionExemptPages = ['login', 'logout', 'dashboard']; // همیشه برای همه‌ی کاربران لاگین‌شده آزاد است

// ⚠️ استثنای مهم: هر کاربر باید بدون توجه به دسترسی ماژول «پرسنل»،
// همیشه به پروفایل *خودش* دسترسی داشته باشد (برای درخواست مرخصی، ثبت
// ورود/خروج، و دیدن فیش حقوقی خودش) - نه پروفایل کل پرسنل دیگران.
$isOwnStaffProfile = false;
if ($requestedPage === 'staff_profile' && Auth::check()) {
    $currentUserForCheck = Auth::currentUser();
    $requestedStaffId = (int)($_GET['id'] ?? 0);
    if ($requestedStaffId > 0) {
        try {
            $linkedStaffId = Database::getInstance()->fetchColumn(
                'SELECT id FROM staff WHERE user_id = ? LIMIT 1',
                [$currentUserForCheck['id']]
            );
            if ($linkedStaffId && (int)$linkedStaffId === $requestedStaffId) {
                $isOwnStaffProfile = true;
            }
        } catch (Exception $e) {
            // ستون user_id هنوز migrate نشده - نادیده بگیر، دسترسی عادی اعمال شود
        }
    }
}

if (!$isOwnStaffProfile && !in_array($requestedPage, $permissionExemptPages, true) && !Auth::hasPermission($requiredPermission)) {
    http_response_code(403);
    $currentUser = Auth::currentUser();
    $pageTitle = 'دسترسی غیرمجاز';
    require VIEW_PATH . '/parts/header.php';
    require VIEW_PATH . '/parts/sidebar.php';
    echo '<main id="main-content"><div class="card" style="padding:50px;text-align:center">
            <div style="font-size:48px;margin-bottom:16px">🔒</div>
            <h2>دسترسی به این بخش برای شما فعال نشده</h2>
            <p style="color:var(--text-muted);margin-top:8px">برای دسترسی به این بخش با مدیر سیستم تماس بگیرید.</p>
            <a href="' . BASE_URL . '/index.php?page=dashboard" class="btn btn-primary" style="margin-top:20px">بازگشت به داشبورد</a>
          </div></main>';
    require VIEW_PATH . '/parts/footer.php';
    exit;
}

// مسیر فایل ویو
$viewFile = VIEW_PATH . '/' . $requestedPage . '.php';

if (!file_exists($viewFile)) {
    http_response_code(404);
    die('فایل صفحه یافت نشد: ' . htmlspecialchars($requestedPage));
}

// متغیرهای مشترک برای استفاده در ویوها
$currentUser = Auth::currentUser();
$pageName = $requestedPage;
$pageTitles = [
    'dashboard'         => 'داشبورد اجرایی',
    'customers'         => 'مشتریان',
    'customer_profile'  => 'پروفایل مشتری',
    'hosts'             => 'میزبانان',
    'host_profile'      => 'پروفایل میزبان',
    'staff'             => 'پرسنل',
    'staff_profile'     => 'پروفایل پرسنل',
    'leads'             => 'سرنخ‌های فروش',
    'host_leads'        => 'جذب میزبان',
    'tickets'           => 'پشتیبانی',
    'chat'              => 'چت داخلی',
    'tasks'             => 'تسک‌ها و پروژه‌ها',
    'reports'           => 'گزارشات',
    'calls'             => 'تماس‌ها',
    'call_detail'       => 'جزئیات تماس',
    'phone_detail'      => 'جزئیات شماره',
    'pipeline'          => 'پیگیری شماره‌ها',
    'site_inquiries'    => 'استعلام‌های سایت',
    'site_sales'        => 'فروش سایت',
    'settings'          => 'تنظیمات',
    'leaves'            => 'مدیریت مرخصی‌ها',
    'payroll'           => 'حقوق و دستمزد',
];
$pageTitle = $pageTitles[$requestedPage] ?? 'جاکجاست';

// قفل نشست آزاد شود تا صفحه‌های سنگین CRM درخواست‌های پنل اعلان‌بان (نشست مشترک) را معطل نکنند
Helpers::generateCsrfToken();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

// بارگذاری ویو
require $viewFile;
