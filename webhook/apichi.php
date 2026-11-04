<?php
/**
 * webhook/apichi.php
 * دریافت رویدادهای اینستاگرام از یار API — یک آدرس برای همه‌ی پیج‌ها.
 * ثبت خودکار: تب اینستاگرام ← «اتصال به یار API» ← «ثبت خودکار وب‌هوک».
 *
 * امضا (مستند یار API):
 *   X-YarApi-Signature (یا X-Apichi-Signature قدیمی) = "sha256=" + hex(HMAC_SHA256(secret, X-Apichi-Timestamp + "." + rawBody))
 * بدنه: { id, event, igAccount:{id,handle}, data:{...}, raw:{payload اصلی سرویس‌دهنده} }
 *
 * پاسخ 200 فوری داده می‌شود و پردازش (پایپ‌لاین کامل: اینباکس، اتوماسیون کامنت، فالو اجباری،
 * منو، فرم، پاسخ خودکار، AI) بعد از بستن اتصال ادامه پیدا می‌کند — چون یار API بعد از ۸ ثانیه
 * درخواست را ناموفق حساب می‌کند و پاسخ AI ممکن است طولانی‌تر باشد.
 */

$root = dirname(__DIR__);
$cfgFile = $root . '/config.php';
if (!file_exists($cfgFile)) { http_response_code(500); exit('no config'); }
$config = require $cfgFile;

require $root . '/webhook/_ig_bootstrap.php';

Database::init($config['db']);

if (class_exists('Personalize')) Personalize::setBaseUrl(app_base_url($config));

$raw = file_get_contents('php://input');
$ts  = (string)($_SERVER['HTTP_X_YARAPI_TIMESTAMP'] ?? ($_SERVER['HTTP_X_APICHI_TIMESTAMP'] ?? ''));
$sig = (string)($_SERVER['HTTP_X_YARAPI_SIGNATURE'] ?? ($_SERVER['HTTP_X_APICHI_SIGNATURE'] ?? ''));

header('Content-Type: application/json; charset=utf-8');

$__t0 = microtime(true);
$__evPeek = json_decode($raw, true);
$__evName = (string)($_SERVER['HTTP_X_YARAPI_EVENT'] ?? ($_SERVER['HTTP_X_APICHI_EVENT'] ?? ($__evPeek['event'] ?? '?')));

if ($problem = InstagramApichi::signatureProblem($raw, $ts, $sig)) {
    if (class_exists('ApichiLog')) {
        ApichiLog::add('in', ['method' => 'POST', 'endpoint' => $__evName, 'http_code' => 401, 'ok' => false,
            'duration_ms' => (int)round((microtime(true) - $__t0) * 1000), 'summary' => 'وب‌هوک رد شد: ' . $problem,
            'request' => $raw, 'ref' => (string)($_SERVER['HTTP_X_YARAPI_EVENT_ID'] ?? ($_SERVER['HTTP_X_APICHI_EVENT_ID'] ?? ''))]);
    }
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid signature']);
    exit;
}

$ev = json_decode($raw, true);
if (!is_array($ev)) { http_response_code(400); echo '{"ok":false}'; exit; }

InstagramApichi::setSetting('last_webhook_at', date('Y-m-d H:i:s'));

if (class_exists('ApichiLog')) {
    $d = (array)($ev['data'] ?? []);
    ApichiLog::add('in', ['method' => 'POST', 'endpoint' => $__evName, 'http_code' => 200, 'ok' => true,
        'duration_ms' => (int)round((microtime(true) - $__t0) * 1000), 'ig_account_id' => (int)($ev['igAccount']['id'] ?? 0) ?: null,
        'summary' => (($ev['event'] ?? '') === 'test.ping' ? 'تست وب‌هوک (test.ping) از یار API رسید ✓' : 'رویداد ' . ($ev['event'] ?? '?') . ' رسید'
            . (!empty($d['username']) ? ' از @' . ltrim((string)$d['username'], '@') : '') . (!empty($ev['igAccount']['handle']) ? ' روی ' . $ev['igAccount']['handle'] : '')),
        'request' => $raw, 'ref' => (string)($ev['id'] ?? '')]);
}

if (($ev['event'] ?? '') === 'test.ping') {
    echo json_encode(['ok' => true, 'pong' => true]);
    exit;
}

// پاسخ فوری
$out = json_encode(['ok' => true]);
http_response_code(200);
header('Content-Length: ' . strlen($out));
header('Connection: close');
echo $out;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
} else {
    @ob_flush(); @flush();
}
ignore_user_abort(true);
@set_time_limit(90);

try {
    InstagramApichi::processEnvelope($ev, $config, 'webhook');
} catch (Throwable $e) {
    error_log('[webhook/apichi] ' . $e->getMessage());
    if (class_exists('ApichiLog')) ApichiLog::add('event', ['method' => 'webhook', 'endpoint' => (string)($ev['event'] ?? ''), 'ok' => false, 'summary' => 'خطای پیش‌بینی‌نشده: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), 'request' => $ev, 'ref' => (string)($ev['id'] ?? '')]);
}
