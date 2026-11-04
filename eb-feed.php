<?php
/**
 * eb-feed.php  (قبلاً notices.php)
 * نقطه‌ی عمومی «اعلان‌های سایت» (پاپ‌آپ/نوار/اسلاید-این — مورد ۷).
 * اسکریپت نصب‌شده در سایت مشتری (eb-embed.js) با همین فایل حرف می‌زند.
 *
 * ⚠️ چرا این اسم عوض شد: خیلی از افزونه‌های ادبلاک (uBlock/AdBlock) فایل‌هایی
 * با نام notice/popup/banner/ads را صرفاً به‌خاطر اسمشان بلاک می‌کنند — مستقل
 * از این‌که واقعاً تبلیغ باشند یا نه. این یک علت شایع «پاپ‌آپ نمایش داده نمی‌شود»ست.
 *
 * ⚠️ مثل chat.php، بدون‌لاگین است. برخلاف چت، اینجا هیچ داده‌ی شخصی رد و بدل
 * نمی‌شود (فقط محتوای تبلیغاتی/اطلاع‌رسانی که قرار است عمومی دیده شود)، پس
 * محدودیت دامنه لازم نیست — هرکس اسکریپت را نصب کند اعلان‌های فعال را می‌بیند.
 *
 * کنش‌ها: ?n=active&url=...   |   ?n=hit  (POST: id, type=impression|click)
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
    echo json_encode(['ok' => false, 'error' => 'no config'], JSON_UNESCAPED_UNICODE);
    exit;
}
$config = require $cfgFile;

require_once $root . '/core/Database.php';
require_once $root . '/modules/SiteCtx.php';
require_once $root . '/modules/Notices.php';
require_once $root . '/modules/Persons.php';
Database::init($config['db']);
// ۲.۱.۱: اعلان‌های همین سایت (?site=KEY)؛ بدون کلید = سایت اصلی
if (!SiteCtx::fromPublic((string)($_GET['site'] ?? ''))) { echo json_encode(['ok' => true, 'notices' => []]); exit; }

function nz_out(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$in = [];
$raw = file_get_contents('php://input');
if ($raw !== '' && $raw !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $in = $decoded;
}
if (!$in && !empty($_POST)) $in = $_POST;

$action = (string)($_GET['n'] ?? ($in['n'] ?? ''));

// محدودیت نرخ ساده فقط برای دریافت شماره (تا کسی با اسپم شماره پایگاه‌داده را پر نکند)
function nz_rate_limited(string $ip): bool
{
    $dir = sys_get_temp_dir() . '/eb_feed_rl';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $key = $dir . '/' . md5($ip);
    $now = time();
    $count = 0;
    if (is_file($key)) {
        $data = @json_decode((string)@file_get_contents($key), true);
        if (is_array($data) && ($now - ($data['t'] ?? 0)) < 60) {
            $count = (int)($data['c'] ?? 0);
        }
    }
    $count++;
    @file_put_contents($key, json_encode(['t' => $now, 'c' => $count]));
    return $count > 8; // بیش از ۸ تلاش در یک دقیقه از یک IP → مشکوک
}

try {
    switch ($action) {
        case 'active':
            $url = (string)($_GET['url'] ?? ($in['url'] ?? ''));
            nz_out(['ok' => true, 'notices' => Notices::activeFor($url)]);
            break;

        case 'hit':
            $id   = (int)($in['id'] ?? ($_GET['id'] ?? 0));
            $type = ((string)($in['type'] ?? '')) === 'click' ? 'click' : 'impression';
            if ($id > 0) Notices::hit($id, $type);
            nz_out(['ok' => true]);
            break;

        case 'lead_submit':
            $noticeId = (int)($in['notice_id'] ?? 0);
            $phone = (string)($in['phone'] ?? '');
            $pageUrl = (string)($in['page_url'] ?? '');
            if (!$noticeId || $phone === '') nz_out(['ok' => false, 'error' => 'ورودی ناقص']);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            if (nz_rate_limited($ip)) { http_response_code(429); nz_out(['ok' => false, 'error' => 'تعداد تلاش زیاد بود، کمی صبر کن']); }
            nz_out(Notices::submitLead($noticeId, $phone, $pageUrl));
            break;

        default:
            http_response_code(400);
            nz_out(['ok' => false, 'error' => 'کنش نامعتبر']);
    }
} catch (Throwable $e) {
    if (!empty($config['debug'])) error_log('[notices.php] ' . $e->getMessage());
    http_response_code(500);
    nz_out(['ok' => false, 'error' => 'خطای سرور']);
}
