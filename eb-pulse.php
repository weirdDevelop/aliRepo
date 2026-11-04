<?php
/**
 * eb-pulse.php
 * نقطه‌ی عمومیِ دریافتِ رویدادهای آنالیتیکس (بازدید صفحه/کلیک/اسکرول و...).
 * اسکریپت نصب‌شده در سایت مشتری (eb-pulse.js) با همین فایل حرف می‌زند.
 *
 * ⚠️ اسم عمداً خنثی انتخاب شده (نه analytics.php/tracker.php) — درسِ همان
 * ماجرای notice.js/eb-embed.js: خیلی از افزونه‌های ادبلاک فایل‌هایی با اسم
 * analytics/track/pixel را صرفاً به‌خاطر شباهت اسمی بلاک می‌کنند.
 *
 * برخلاف eb-feed.php (که کاملاً عمومی و بدون هیچ کنترلی است)، این‌جا داده‌ی
 * رفتاری بازدیدکننده رد و بدل می‌شود، پس قفلِ فعال‌سازی ماژول را هم چک می‌کنیم:
 * اگر analytics.enabled خاموش باشد، هیچ‌چیز ذخیره نمی‌شود (فقط ok:false
 * ساکت — تا در کنسول بازدیدکننده خطای شلوغ ندهد).
 *
 * کنش‌ها:
 *   GET  ?px=config   → تنظیمات لازم برای کلاینت (نمونه‌برداری، cookieless، فعال بودن)
 *   POST ?px=batch     → batch رویدادها (JSON body)
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$root    = __DIR__;
$cfgFile = $root . '/config.php';
if (!file_exists($cfgFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    exit;
}
$config = require $cfgFile;

require_once $root . '/core/Database.php';
require_once $root . '/core/Url.php';
require_once $root . '/modules/Links.php';
require_once $root . '/modules/GeoIp.php';
require_once $root . '/modules/GeoIpCountry.php';
require_once $root . '/modules/SiteCtx.php';
require_once $root . '/modules/AnalyticsSettings.php';
require_once $root . '/modules/AnalyticsIngest.php';
Database::init($config['db']);

function px_out(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- محدودیت نرخ ساده‌ی per-IP (جلوی سیل جعلی، بدون نیاز به جدول جدا) ----
// از همان تکنیک فایل session-less rate limit استفاده می‌کنیم: یک فایل کوچک
// در پوشه‌ی logs، نه دیتابیس — تا خودِ محدودیتِ نرخ چیزی به دیتابیس فشار نیاورد.
function px_rate_limited(string $ip): bool
{
    $dir = sys_get_temp_dir() . '/eb_pulse_rl';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $key = $dir . '/' . md5($ip);
    $now = time();
    $count = 0;
    if (is_file($key)) {
        $data = @json_decode((string)@file_get_contents($key), true);
        if (is_array($data) && ($now - ($data['t'] ?? 0)) < 10) {
            $count = (int)($data['c'] ?? 0);
        }
    }
    $count++;
    @file_put_contents($key, json_encode(['t' => $now, 'c' => $count]));
    return $count > 60; // بیش از ۶۰ درخواست در ۱۰ ثانیه از یک IP → مشکوک
}

$trustProxy = !empty($config['trust_proxy']);
$ip = Links::clientIp($trustProxy) ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

if (px_rate_limited($ip)) {
    http_response_code(429);
    px_out(['ok' => false]);
}

$action = (string)($_GET['px'] ?? '');
// ۲.۱.۱: سایتِ این درخواست از کلید سایت (?site=KEY)؛ بدون کلید = سایت اصلی
if (!SiteCtx::fromPublic((string)($_GET['site'] ?? ''))) { px_out(['ok' => true, 'enabled' => false]); }

try {
    if (!AnalyticsSettings::isActive()) {
        // ماژول خاموش است — بدون خطای بلند، فقط enabled:false تا اسکریپت کلاینت
        // خودش را خاموش کند و دیگر درخواست نفرستد.
        px_out(['ok' => true, 'enabled' => false]);
    }

    switch ($action) {
        case 'config':
            px_out([
                'ok' => true,
                'enabled' => true,
                'site_key' => AnalyticsSettings::get('site_key'),
                'sampling_heatmap' => (int)AnalyticsSettings::get('sampling_heatmap'),
                'sampling_move' => (int)AnalyticsSettings::get('sampling_move'),
                'cookieless' => AnalyticsSettings::get('cookieless') === '1',
            ]);
            break;

        case 'batch':
            $raw = file_get_contents('php://input');
            $in = json_decode((string)$raw, true);
            if (!is_array($in)) px_out(['ok' => false, 'error' => 'بدنه‌ی نامعتبر']);

            $expectedKey = AnalyticsSettings::get('site_key');
            if ($expectedKey !== '' && ($in['site_key'] ?? '') !== $expectedKey) {
                px_out(['ok' => false, 'error' => 'site_key نامعتبر']);
            }

            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $result = AnalyticsIngest::process($in, $ip, $ua);
            px_out(['ok' => (bool)($result['ok'] ?? false)]);
            break;

        default:
            http_response_code(400);
            px_out(['ok' => false, 'error' => 'کنش نامعتبر']);
    }
} catch (Throwable $e) {
    if (!empty($config['debug'])) error_log('[eb-pulse.php] ' . $e->getMessage());
    http_response_code(500);
    px_out(['ok' => false]);
}
