<?php
/**
 * crm-bridge.php — ۲.۰.۰: درخواست‌های سرور-به-سرور CRM یکپارچه (پوشه‌ی crm/) به اعلان‌بان
 *
 * احراز هویت: هدر X-Bridge-Key = HMAC-SHA256('crm-bridge-v1', app_key) — همان که crm/config.php می‌سازد.
 * بدون نشست و CSRF (مثل وب‌هوک‌ها). هیچ‌وقت از مرورگر صدا زده نمی‌شود.
 *
 *  POST ?action=sms_send  {to, text}  ← پیامک با سرویس پیامک اعلان‌بان (و ثبت در تاریخچه‌ی همان مشتری)
 *  GET  ?action=ping
 */

header('Content-Type: application/json; charset=utf-8');
function bridge_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) bridge_out(['ok' => false, 'error' => 'no config'], 500);
$config = require $cfgFile;
$ak = (string)($config['app_key'] ?? '');
$given = (string)($_SERVER['HTTP_X_BRIDGE_KEY'] ?? '');
if ($ak === '' || $given === '' || !hash_equals(hash_hmac('sha256', 'crm-bridge-v1', $ak), $given)) bridge_out(['ok' => false, 'error' => 'unauthorized'], 401);

require_once __DIR__ . '/core/Autoload.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Url.php';
require_once __DIR__ . '/core/Tz.php';
Database::init($config['db']);
require_once __DIR__ . '/modules/NetProxy.php';
require_once __DIR__ . '/modules/InstagramAuth.php';
require_once __DIR__ . '/channels/ChannelInterface.php';
require_once __DIR__ . '/channels/InstagramRoboclickDriver.php';
require_once __DIR__ . '/channels/InstagramApichiDriver.php';
require_once __DIR__ . '/modules/InstagramRoboclick.php';
require_once __DIR__ . '/modules/InstagramApichi.php';
require_once __DIR__ . '/channels/ChannelManager.php';
if (class_exists('Personalize') && function_exists('app_base_url')) Personalize::setBaseUrl(app_base_url($config));

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$action = (string)($_GET['action'] ?? '');

if ($action === 'ping') bridge_out(['ok' => true, 'sms' => ChannelManager::isConfigured('sms')]);

if ($action === 'sms_send') {
    $to = Persons::normalizePhone((string)($in['to'] ?? ''));
    $text = trim((string)($in['text'] ?? ''));
    if ($to === null) bridge_out(['ok' => false, 'error' => 'شماره‌ی موبایل نامعتبر است']);
    if ($text === '') bridge_out(['ok' => false, 'error' => 'متن پیامک خالی است']);
    if (!ChannelManager::isConfigured('sms')) bridge_out(['ok' => false, 'error' => 'سرویس پیامک در اعلان‌بان (تنظیمات ← اتصال کانال‌ها) وصل نشده است']);
    try {
        $st = Sender::sendByPhone([$to], ['text' => mb_substr($text, 0, 1000)], 'sms', 0);
    } catch (Throwable $e) {
        if (class_exists('ApiLog')) ApiLog::record('crm_bridge', 'sms_send', ['to' => $to], null, 500, false, $e->getMessage());
        bridge_out(['ok' => false, 'error' => 'ارسال نشد: ' . $e->getMessage()]);
    }
    $ok = (int)($st['sms_sent'] ?? 0) > 0;
    if (class_exists('ApiLog')) ApiLog::record('crm_bridge', 'sms_send', ['to' => $to], $st, 200, $ok, $ok ? null : 'failed');
    bridge_out(['ok' => $ok, 'stats' => $st, 'error' => $ok ? null : 'ارسال پیامک ناموفق بود (لاگ سیستم ← پیامک)']);
}

bridge_out(['ok' => false, 'error' => 'unknown action'], 400);
