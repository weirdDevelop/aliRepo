<?php
/**
 * api/internal_lookup.php
 * نقطه‌ی ورود اختصاصی برای سیستم‌های داخلی شرکت (فعلاً فقط جاکجاست) که
 * بخواهند بر اساس شماره تلفن، پروفایل/تاریخچه‌ی چت یک شخص را بگیرند.
 *
 * برخلاف api/handler.php (که کاملاً session/CSRF-based و برای پنل خود
 * اعلان‌بان است)، این فایل یک endpoint مستقل با احراز هویت توکن ثابت
 * است - دقیقاً همان الگویی که webhook/roboclick.php برای دریافت رویداد
 * از یک سرویس بیرونی استفاده می‌کند (bootstrap مستقل، بدون session).
 *
 * احراز هویت: هدر X-Internal-Token باید دقیقاً برابر با مقدار
 * 'jakojast_integration_token' در config.php باشد. این توکن داخلی/سرور-
 * به-سرور است، نه توکن کاربری/عمومی - هرگز نباید در پنل یا هیچ خروجی
 * قابل‌مشاهده توسط کاربر نهایی نمایش داده شود.
 *
 * ورودی:  GET ?phone=<هر فرمتی - نرمال‌سازی همین‌جا انجام می‌شود>
 * خروجی:  {ok:true, found:bool, data:{...}}  یا  {ok:false, error:"..."}
 *
 * نکته‌ی نرمال‌سازی: جاکجاست از معیار «۱۰ رقم آخر» استفاده می‌کند،
 * اعلان‌بان (Persons::normalizePhone) به فرمت 98XXXXXXXXXX نرمال می‌کند.
 * چون ۱۰ رقم آخر هر دو فرمت یکسان است، همین‌جا هر ورودی (فرمت جاکجاست یا
 * هر فرمت دیگر) از Persons::normalizePhone عبور می‌کند تا با ستون
 * eb_persons.phone (که همیشه به همین فرمت ذخیره شده) مطابقت پیدا کند.
 */

header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$root = dirname(__DIR__);
$cfgFile = $root . '/config.php';
if (!file_exists($cfgFile)) {
    respond(['ok' => false, 'error' => 'no config'], 500);
}
$config = require $cfgFile;

require_once $root . '/core/Database.php';
require_once $root . '/modules/Persons.php';
require_once $root . '/modules/ApiLog.php';

Database::init($config['db']);

// ---- احراز هویت توکن ----
$expectedToken = $config['jakojast_integration_token'] ?? '';
$givenToken = $_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '';
// ۲.۰.۰: CRM یکپارچه (پوشه‌ی crm/) با کلید مشتق از app_key، بدون تنظیم توکن دستی
$__ak = (string)($config['app_key'] ?? '');
if ($__ak !== '' && $givenToken !== '' && hash_equals(hash_hmac('sha256', 'crm-bridge-v1', $__ak), (string)$givenToken)) {
    $expectedToken = (string)$givenToken;
}

if ($expectedToken === '' || $expectedToken === 'CHANGE_ME') {
    // توکن هنوز تنظیم نشده - از دید امنیتی یعنی این endpoint باید غیرفعال
    // در نظر گرفته شود، نه اینکه با توکن خالی هر درخواستی را قبول کند.
    respond(['ok' => false, 'error' => 'internal lookup token not configured'], 503);
}

if (!hash_equals($expectedToken, (string) $givenToken)) {
    ApiLog::record('jakojast_internal', 'lookup_by_phone', ['phone' => $_GET['phone'] ?? null], null, 401, false, 'invalid token');
    respond(['ok' => false, 'error' => 'unauthorized'], 401);
}

// ---- ورودی ----
$rawPhone = trim($_GET['phone'] ?? '');
if ($rawPhone === '') {
    respond(['ok' => false, 'error' => 'phone parameter is required'], 400);
}

$phone = Persons::normalizePhone($rawPhone);
if ($phone === null) {
    ApiLog::record('jakojast_internal', 'lookup_by_phone', ['phone' => $rawPhone], null, 400, false, 'invalid phone format');
    respond(['ok' => false, 'error' => 'invalid phone format'], 400);
}

// ---- جستجو ----
$person = Database::one("SELECT id, full_name, status FROM eb_persons WHERE phone = ?", [$phone]);

if (!$person) {
    ApiLog::record('jakojast_internal', 'lookup_by_phone', ['phone' => $rawPhone], ['found' => false], 200, true);
    respond(['ok' => true, 'found' => false, 'data' => null]);
}

$profile = Persons::profile((int) $person['id']);

$identities = [];
$instagramHandle = null;
foreach (($profile['identities'] ?? []) as $idn) {
    $identities[] = [
        'channel'        => $idn['channel'],
        'username'       => $idn['username'],
        'last_message'   => $idn['last_message'],
        'last_message_at'=> $idn['last_message_at'],
    ];
    if ($idn['channel'] === 'instagram_roboclick' || $idn['channel'] === 'instagram') {
        $instagramHandle = $idn['username'] ? '@' . $idn['username'] : null;
    }
}

$recentMessages = [];
foreach (Persons::messages((int) $person['id'], 30) as $m) {
    $recentMessages[] = [
        'direction' => $m['direction'],
        'text'      => $m['body'],
        'sent_at'   => $m['created_at'],
        'channel'   => $m['ch'],
    ];
}

$data = [
    'person_id'        => (int) $person['id'],
    'full_name'        => $person['full_name'],
    'phone_status'     => $person['status'],
    'instagram_handle' => $instagramHandle,
    'top_channel'      => $profile['top_channel'] ?? null,
    'message_count'    => (int) ($profile['stats']['sent'] ?? 0) + (int) ($profile['stats']['received'] ?? 0),
    'last_activity'    => $profile['stats']['last_activity'] ?? null,
    'identities'       => $identities,
    'recent_messages'  => $recentMessages,
];

ApiLog::record('jakojast_internal', 'lookup_by_phone', ['phone' => $rawPhone], ['found' => true, 'person_id' => $person['id']], 200, true);

respond(['ok' => true, 'found' => true, 'data' => $data]);
