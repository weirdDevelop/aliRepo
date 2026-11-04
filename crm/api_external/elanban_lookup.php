<?php
/**
 * api_external/elanban_lookup.php
 * نقطه‌ی ورود اختصاصی برای اعلان‌بان که بخواهد بر اساس شماره تلفن،
 * وضعیت سرنخ/مشتری/تماس‌های آن شخص را از جاکجاست بگیرد (جهت برعکسِ
 * ElanbanAPI.php - آن‌جا جاکجاست از اعلان‌بان می‌پرسد، این‌جا برعکس).
 *
 * درست مثل api/internal_lookup.php سمت اعلان‌بان: یک endpoint مستقل با
 * توکن ثابت، نه از مسیر api.php معمولی (که کاملاً session-based است و
 * Auth::requireLogin() دارد - برای یک تماس سرور-به-سرور بی‌معنی است).
 *
 * احراز هویت: هدر X-Internal-Token باید برابر مقدار تنظیم
 * 'elanban_incoming_token' باشد (جدا از 'elanban_token' که جاکجاست خودش
 * برای صدا زدن اعلان‌بان استفاده می‌کند - دو توکن مجزا برای دو جهت، تا
 * لو رفتن یکی باعث دسترسی دوطرفه نشود).
 *
 * ورودی:  GET ?phone=<هر فرمتی>
 * خروجی:  {ok:true, found:bool, data:{...}}  یا  {ok:false, error:"..."}
 *
 * منطق اصلی از Call::getFullNumberProfile() که از قبل ساخته شده و
 * دقیقاً همین کار را برای phone_detail.php انجام می‌دهد - اینجا فقط با
 * $visibleExtens=null صدا زده می‌شود (یعنی بدون محدودیت دید کاربر، چون
 * این یک سیستم است نه یک اپراتور خاص).
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

function elanbanLookupRespond(array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- احراز هویت توکن ----
$expectedToken = Report::getSetting('elanban_incoming_token');
$givenToken = $_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '';
// سیستم یکپارچه: کلید مشترک مشتق از app_key اعلان‌بان
if (defined('CRM_BRIDGE_KEY') && CRM_BRIDGE_KEY !== '' && hash_equals(CRM_BRIDGE_KEY, (string)$givenToken)) {
    $expectedToken = CRM_BRIDGE_KEY;
}

if (empty($expectedToken)) {
    elanbanLookupRespond(['ok' => false, 'error' => 'incoming token not configured'], 503);
}
if (!hash_equals((string) $expectedToken, (string) $givenToken)) {
    Logger::error('Elanban lookup: invalid token', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    elanbanLookupRespond(['ok' => false, 'error' => 'unauthorized'], 401);
}

// ---- ورودی ----
$rawPhone = trim($_GET['phone'] ?? '');
if ($rawPhone === '') {
    elanbanLookupRespond(['ok' => false, 'error' => 'phone parameter is required'], 400);
}
$last10 = substr(preg_replace('/\D+/', '', $rawPhone), -10);
if (strlen($last10) < 10) {
    elanbanLookupRespond(['ok' => false, 'error' => 'invalid phone format'], 400);
}

// ---- جستجو (بدون محدودیت دید - سیستم است، نه یک اپراتور خاص) ----
try {
    $profile = Call::getFullNumberProfile($last10, null);
} catch (Exception $e) {
    Logger::error('Elanban lookup: getFullNumberProfile failed: ' . $e->getMessage());
    elanbanLookupRespond(['ok' => false, 'error' => 'internal error'], 500);
}

$hasAnything = !empty($profile['customer']) || !empty($profile['leads']) || !empty($profile['calls']);
if (!$hasAnything) {
    elanbanLookupRespond(['ok' => true, 'found' => false, 'data' => null]);
}

$customer = $profile['customer'];
$leads = $profile['leads'] ?? [];
$tickets = $profile['tickets'] ?? [];
$stats = $profile['stats'] ?? [];

$data = [
    'name'          => $profile['name'] ?? ($customer['name'] ?? null),
    'is_customer'   => !empty($customer),
    'customer'      => $customer ? [
        'id'     => (int) $customer['id'],
        'name'   => $customer['name'],
        'level'  => $customer['level'] ?? null,
        'status' => $customer['status'] ?? null,
        'city'   => $customer['city'] ?? null,
    ] : null,
    'leads' => array_map(function ($l) {
        return [
            'id'       => (int) $l['id'],
            'name'     => $l['name'],
            'stage'    => $l['stage'],
            'assignee' => $l['assignee'],
            'source'   => $l['source'],
        ];
    }, $leads),
    'open_tickets_count' => count(array_filter($tickets, fn($t) => in_array($t['status'], ['open', 'in_review'], true))),
    'call_stats' => [
        'total_calls'   => (int) ($stats['total_calls'] ?? count($profile['calls'] ?? [])),
        'answer_rate'   => $stats['answer_rate'] ?? null,
        'last_call_at'  => $stats['last_call_at'] ?? null,
    ],
];

elanbanLookupRespond(['ok' => true, 'found' => true, 'data' => $data]);
