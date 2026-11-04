<?php
/**
 * media.php — پروکسی/کش تصاویر و فایل‌های دریافتی (مورد ۴).
 *
 * چرا لازم است؟ تلگرام/بله برای رسانه‌ی دریافتی فقط یک `file_id` می‌دهند، نه
 * لینک عمومی. برای نمایش واقعی باید با توکن ربات از API آن پلتفرم گرفته شود؛
 * توکن هم نباید در HTML/کلاینت افشا شود. این اسکریپت آن مسیر امن را فراهم می‌کند:
 *
 *   ۱) پیام را از eb_messages می‌خواند (فقط اگر کاربر لاگین باشد).
 *   ۲) با درایور همان کانال، فایل را با file_id دانلود می‌کند (از پروکسیِ
 *      ضدفیلترینگ هم در صورت فعال‌بودن استفاده می‌شود).
 *   ۳) نتیجه را روی دیسک کش می‌کند و media_path پیام را به مسیر محلی آپدیت
 *      می‌کند — دفعه‌ی بعد دیگر نیازی به این اسکریپت نیست، مستقیم از uploads/ می‌آید.
 *
 * استفاده: media.php?mid=123
 */

$cfgFile = __DIR__ . '/config.php';
if (!file_exists($cfgFile)) { http_response_code(500); exit('not configured'); }
$config = require $cfgFile;

error_reporting(E_ALL);
ini_set('display_errors', !empty($config['debug']) ? '1' : '0');

require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/modules/NetProxy.php';
require_once __DIR__ . '/channels/ChannelInterface.php';
require_once __DIR__ . '/channels/BaseBotDriver.php';
require_once __DIR__ . '/channels/BaleBotDriver.php';
require_once __DIR__ . '/channels/TelegramDriver.php';
require_once __DIR__ . '/channels/RoubikaDriver.php';
require_once __DIR__ . '/channels/BaleSafirDriver.php';
require_once __DIR__ . '/channels/SmsDriver.php';
require_once __DIR__ . '/channels/EmailDriver.php';
require_once __DIR__ . '/channels/VoiceDriver.php';
require_once __DIR__ . '/channels/WebchatDriver.php';
require_once __DIR__ . '/channels/ChannelManager.php';

Database::init($config['db']);
Auth::start();

// این رسانه‌ی خصوصیِ مکالمات مشتری‌هاست — بدون لاگین هیچ‌چیز نشان داده نمی‌شود.
if (!Auth::check()) {
    http_response_code(403);
    exit('دسترسی نیاز به ورود دارد');
}

$mid = isset($_GET['mid']) ? (int)$_GET['mid'] : 0;
if ($mid <= 0) { http_response_code(400); exit('bad request'); }

$msg = Database::one(
    "SELECT id, channel, media_type, media_path FROM eb_messages WHERE id = ? AND direction='in'",
    [$mid]
);
if (!$msg || empty($msg['media_path']) || empty($msg['media_type'])) {
    http_response_code(404); exit('یافت نشد');
}

// اگر قبلاً کش شده (مسیر محلی uploads/...)، مستقیم همان را serve کن.
$rel = (string)$msg['media_path'];
if (strpos($rel, 'uploads/') === 0) {
    $abs = rtrim($config['paths']['uploads'] ?? (__DIR__ . '/uploads/'), '/') . '/' . basename($rel);
    if (is_file($abs)) {
        header('Cache-Control: private, max-age=86400');
        header('Content-Type: ' . (mime_content_type($abs) ?: 'application/octet-stream'));
        readfile($abs);
        exit;
    }
    http_response_code(404); exit('فایل کش‌شده یافت نشد');
}

// در غیر این صورت، media_path همان file_id خام کانال است → با درایور دانلود کن.
$driver = ChannelManager::get($msg['channel']);
if (!$driver || !method_exists($driver, 'fetchFile')) {
    http_response_code(404);
    exit('این کانال امکان دریافت مستقیم فایل را ندارد');
}

$file = $driver->fetchFile($rel);
if (!$file || empty($file['bytes'])) {
    http_response_code(502);
    exit('دریافت فایل از سرویس پیام‌رسان ناموفق بود — شاید لینک منقضی شده یا پروکسی لازم است');
}

// کش روی دیسک برای دفعات بعد (و آپدیت media_path در دیتابیس)
$uploadsDir = rtrim($config['paths']['uploads'] ?? (__DIR__ . '/uploads/'), '/') . '/';
$cacheDir   = $uploadsDir . 'media_cache/';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }

$ext = preg_replace('/[^a-zA-Z0-9]/', '', (string)($file['ext'] ?? '')) ?: 'jpg';
$fname = 'in_' . $mid . '_' . substr(sha1($rel), 0, 12) . '.' . $ext;
$fpath = $cacheDir . $fname;

if (is_dir($cacheDir) && @file_put_contents($fpath, $file['bytes']) !== false) {
    try {
        Database::run("UPDATE eb_messages SET media_path = ? WHERE id = ?", ['uploads/media_cache/' . $fname, $mid]);
    } catch (Throwable $e) { /* آپدیت دیتابیس ناموفق شد؛ همچنان می‌توانیم همین بار را serve کنیم */ }
}

header('Cache-Control: private, max-age=86400');
header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
echo $file['bytes'];
