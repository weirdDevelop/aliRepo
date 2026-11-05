<?php
/**
 * جاکجاست CRM — پیکربندی در «سیستم مدیریت یکپارچه‌ی جاکجاست»
 *
 * این پوشه (crm/) داخل پوشه‌ی اعلان‌بان نصب می‌شود و دیتابیس، کلید و ورود
 * کاربران را از اعلان‌بان می‌گیرد:
 *   - دیتابیس: همان دیتابیس اعلان‌بان (config.php اعلان‌بان، کلید db)
 *   - کاربران: eb_users اعلان‌بان؛ صفحه‌ی ورود جدای CRM دیگر استفاده نمی‌شود
 *   - راز برنامه: از app_key اعلان‌بان مشتق می‌شود
 * هیچ رمز یا اطلاعات دیتابیسی در این فایل نوشته نمی‌شود.
 */

// ===========================
// خطایابی
// ===========================
define('DEBUG_MODE', false);
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// ===========================
// پیکربندی اعلان‌بان (پوشه‌ی والد)
// ===========================
define('EB_ROOT', dirname(__DIR__));
$__ebCfgFile = EB_ROOT . '/config.php';
if (!is_file($__ebCfgFile)) {
    http_response_code(500);
    exit('CRM باید داخل پوشه‌ی اعلان‌بان (مثلاً marketing/crm) نصب شود؛ config.php اعلان‌بان پیدا نشد.');
}
$__ebCfg = require $__ebCfgFile;
$__db = $__ebCfg['db'] ?? [];

define('DB_HOST', (string)($__db['host'] ?? 'localhost'));
define('DB_PORT', (string)(($__db['port'] ?? '') !== '' ? $__db['port'] : '3306'));
define('DB_NAME', (string)($__db['name'] ?? ''));
define('DB_USER', (string)($__db['user'] ?? ''));
define('DB_PASS', (string)($__db['pass'] ?? ''));
define('DB_CHARSET', 'utf8mb4');

// ===========================
// امنیت
// ===========================
$__appKey = (string)($__ebCfg['app_key'] ?? '');
define('APP_SECRET', hash_hmac('sha256', 'crm-app-secret', $__appKey !== '' ? $__appKey : __DIR__));
// کلید ارتباط سرور-به-سرور CRM ⇄ اعلان‌بان (پیامک، جستجوی گفتگو). سمت اعلان‌بان هم همین را می‌سازد.
define('CRM_BRIDGE_KEY', $__appKey !== '' ? hash_hmac('sha256', 'crm-bridge-v1', $__appKey) : '');
// آدرس پایه‌ی اعلان‌بان برای درخواست‌های سرور-به-سرور
define('EB_BASE_URL', rtrim((string)($__ebCfg['base_url'] ?? ''), '/'));
// CRM داخل اعلان‌بان: ورود با حساب اعلان‌بان
define('CRM_BRIDGED', true);

define('TOKEN_EXPIRY', 8 * 3600);
define('TOKEN_HEADER', 'Authorization');
define('MIN_PASSWORD_LENGTH', 6);
unset($__ebCfg, $__db, $__appKey, $__ebCfgFile);

// ===========================
// جلسه (همان نشست اعلان‌بان)
// ===========================
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
}

date_default_timezone_set('Asia/Tehran');

// ===========================
// مسیرها
// ===========================
define('ROOT_PATH', dirname(__FILE__));
define('CORE_PATH', ROOT_PATH . '/core');
define('MODEL_PATH', ROOT_PATH . '/models');
define('VIEW_PATH', ROOT_PATH . '/views');
define('API_INTEGRATION_PATH', ROOT_PATH . '/api_integration');
define('LOG_PATH', ROOT_PATH . '/logs');
define('ASSET_PATH', ROOT_PATH . '/assets');

// ===========================
// تلفنچی (توکن و شناسه‌ی سرویس در «تنظیمات CRM» ذخیره می‌شوند)
// ===========================
define('TELEFONCHY_BASE_URL', 'https://panel.telefonchy.com/webservice/v1');
define('TELEFONCHY_SERVICE_ID', '');
define('TELEFONCHY_TOKEN', '');

define('SYNC_INTERVAL', 300);
define('CHAT_POLL_TIMEOUT', 25);
define('CHAT_POLL_INTERVAL', 2);
define('ALLOW_USER_UPLOAD', false);

// ===========================
// بارگذاری خودکار کلاس‌ها
// ===========================
spl_autoload_register(function ($className) {
    // کلاس مشترک با اعلان‌بان (نرمال‌سازی یکسان شماره در هر دو سیستم — 2.1.5)
    if ($className === 'PhoneNumber') { require_once EB_ROOT . '/core/PhoneNumber.php'; return; }
    if ($className === 'Permissions') { require_once EB_ROOT . '/core/Permissions.php'; return; }   // 2.3.0
    $paths = [
        CORE_PATH . '/' . $className . '.php',
        MODEL_PATH . '/' . $className . '.php',
        API_INTEGRATION_PATH . '/' . $className . '.php',
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
});

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

if (session_status() === PHP_SESSION_NONE && !headers_sent() && PHP_SAPI !== 'cli') {
    session_start();
}
