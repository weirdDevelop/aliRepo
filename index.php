<?php
/**
 * index.php — نقطه‌ی ورود واحد (front controller)
 * همه‌ی درخواست‌های پنل از اینجا عبور می‌کنند.
 */

$cfgFile = __DIR__ . '/config.php';
if (!file_exists($cfgFile)) {
    // نصب هنوز انجام نشده؛ به‌جای پیام خطا، مستقیم به ویزارد نصب هدایت کن
    if (is_file(__DIR__ . '/install.php')) {
        header('Location: install.php');
        exit;
    }
    exit('config.php یافت نشد و فایل install.php هم در پوشه نیست. بسته‌ی نصب را کامل آپلود کنید.');
}
$config = require $cfgFile;

error_reporting(E_ALL);
ini_set('display_errors', $config['debug'] ? '1' : '0');

// بارگذاری هسته
require_once __DIR__ . '/core/Autoload.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Csrf.php';
require_once __DIR__ . '/core/Url.php';
require_once __DIR__ . '/core/Tz.php';
require_once __DIR__ . '/core/SchemaV4.php';
require_once __DIR__ . '/core/Viewer.php';

Database::init($config['db']);
SchemaV4::ensure();   // جدول‌ها و ستون‌های تازه، بدون نیاز به زدن دستی «به‌روزرسانی دیتابیس»
Auth::start();

require_once __DIR__ . '/modules/Brand.php';
if (!defined('ELB_VERSION')) define('ELB_VERSION', '2.1.4-jk');   // 1.0.1: نقش نمایشی · 1.0.2-jk: دستیار فروش و اتصال API · 1.0.3-jk: برندها، پایگاه دانش، امنیت، پیگیری و نظرسنجی · 1.0.4-jk: گزارش روزانه‌ی بله و پیگیری بعد از رد استعلام · 1.0.5-jk: رفع خطای Provider returned error · 1.0.6-jk: انتخاب کانال‌های دستیار · 1.0.8-jk: پاسخ در پس‌زمینه، در حال نوشتن، نام دستیار، گفتگوهای دارای مسئول · 1.0.9-jk: لحن کوتاه، تأیید یک‌باره، درخواست تماس · 1.0.10-jk: محله‌های همسایه، هاستل، بدون سؤال تکراری · 1.0.11-jk: پیگیری خودکار استعلام، پروفایل دستیار در ویجت، دکمه‌های سریع، پیامک خودکار لینک

$page = $_GET['page'] ?? 'dashboard';

// ---- صفحات عمومی (بدون نیاز به لاگین) ----
if ($page === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::requireValid();
        $ok = Auth::attempt($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($ok) { header('Location: index.php'); exit; }
        $loginError = 'نام کاربری یا رمز اشتباه است';
    }
    require __DIR__ . '/views/login.php';
    exit;
}

if ($page === 'logout') {
    Auth::logout();
    header('Location: index.php?page=login');
    exit;
}

// ---- از اینجا به بعد نیاز به لاگین ----
if (!Auth::check() && isset($_GET['api'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['ok' => false, 'error' => 'نشست شما تمام شده؛ دوباره وارد شوید', 'login' => true], JSON_UNESCAPED_UNICODE));
}
Auth::requireLogin();



// بارگذاری لایه‌ی کانال و ماژول‌های کاری
require_once __DIR__ . '/modules/NetProxy.php';   // باید قبل از ChannelManager باشد تا درایورها آن را ببینند
require_once __DIR__ . '/modules/InstagramAuth.php';
require_once __DIR__ . '/channels/ChannelInterface.php';   // باید قبل از InstagramRoboclickDriver.php بیاید چون implements می‌کند
require_once __DIR__ . '/channels/InstagramRoboclickDriver.php';
require_once __DIR__ . '/channels/InstagramApichiDriver.php';
require_once __DIR__ . '/modules/InstagramRoboclick.php';
require_once __DIR__ . '/modules/InstagramApichi.php';
require_once __DIR__ . '/channels/ChannelManager.php';
// بقیه‌ی ماژول‌ها با core/Autoload.php و فقط هنگام نیاز بارگذاری می‌شوند
// آدرس پایه از همان منطقی گرفته می‌شود که پنل نشان می‌دهد (core/Url.php)،
// وگرنه لینک رهگیریِ داخل پیام مشتری با لینکِ پنل یکی نمی‌شد.
Personalize::setBaseUrl(app_base_url($config));
app_base_url_remember($config);   // تا cron هم (که HTTP_HOST ندارد) آدرس را بداند
InstagramDriver::setBaseUrl(app_base_url($config));

// ---- API (خروجی JSON) ----
// ---- خروجی/دانلود فایل (CSV) — جدا از API چون هدر فایل می‌خواهد، نه JSON ----
$export = $_GET['export'] ?? null;
if ($export !== null) {
    Csrf::requireValid();
    Viewer::gateExport();
    require __DIR__ . '/api/export.php';
    exit;
}

$api = $_GET['api'] ?? null;
if ($api !== null) {
    Csrf::requireValid();
    Viewer::gateApi((string)$api);
    header('Content-Type: application/json; charset=utf-8');
    require __DIR__ . '/api/handler.php';
    exit;
}

// ---- صفحات پنل ----
$validPages = ['dashboard', 'inbox', 'instagram', 'queue', 'persons', 'send', 'safir', 'templates', 'autoreply', 'botbuilder', 'forms', 'groups', 'campaigns', 'scheduled', 'links', 'sendlog', 'apilog', 'stats', 'botsettings', 'channels', 'settings', 'profile', 'support', 'marketing', 'ai', 'webchat', 'notices', 'analytics'];
if (!in_array($page, $validPages, true)) {
    $page = 'dashboard';
}
// کنترل دسترسی: اگر اپراتور به این صفحه مجاز نیست، به داشبورد برگردد
if (!Auth::canAccess($page)) {
    $page = 'dashboard';
}
$currentPage = $page; // برای استفاده در layout
// صفحه‌های قدیمی که هنوز به پوسته‌ی جدید منتقل نشده‌اند، داخل پنل جدید به‌صورت جاسازی باز می‌شوند
if (!empty($_GET['legacy'])) {
    require __DIR__ . '/views/layout.php';
} else {
    require __DIR__ . '/views/app.php';
}

