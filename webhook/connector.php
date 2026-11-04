<?php
/**
 * webhook/connector.php?c=<شناسه‌ی اتصال>
 * دریافت رویدادهای وب‌سرویس‌های متصل به دستیار فروش (مثلاً جاکجاست: تأیید/رد استعلام، پرداخت).
 *
 * امضا: "sha256=" + hex(HMAC_SHA256(secret, timestamp + "." + rawBody))
 * (اگر سرویس timestamp نفرستد: HMAC روی خود بدنه). بدون «راز وب‌هوک» در تنظیمات اتصال، هیچ رویدادی
 * پذیرفته نمی‌شود تا کسی نتواند به نام سرویس به مشتری‌ها پیام بفرستد.
 *
 * پاسخ 200 فوری داده می‌شود و ارسال پیام به مشتری بعد از بستن اتصال انجام می‌شود.
 */
$root = dirname(__DIR__);
$cfgFile = $root . '/config.php';
if (!file_exists($cfgFile)) { http_response_code(500); exit('no config'); }
$config = require $cfgFile;
require $root . '/webhook/_ig_bootstrap.php';
require_once $root . '/core/Tz.php';
Database::init($config['db']);
if (class_exists('Personalize')) Personalize::setBaseUrl(app_base_url($config));

header('Content-Type: application/json; charset=utf-8');
$slug = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['c'] ?? '')));
$raw = (string)file_get_contents('php://input');
$fail = function (int $code, string $why) use ($raw, &$c) {
    ApiConnectors::log(null, isset($c['id']) ? (int)$c['id'] : null, 'webhook', null, null, false, $code, 'وب‌هوک رد شد: ' . $why, $raw, null);
    http_response_code($code);
    exit(json_encode(['ok' => false, 'error' => $why]));
};

$c = $slug !== '' ? ApiConnectors::bySlug($slug) : null;
if (!$c) $fail(404, 'unknown connector');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => true, 'connector' => $c['slug'], 'ready' => (string)$c['webhook_secret'] !== '']); exit; }
if (empty($c['is_active'])) $fail(403, 'connector inactive');
$secret = (string)($c['webhook_secret'] ?? '');
if ($secret === '') $fail(403, 'webhook secret not configured');

// هدرهای امضا و زمان: تنظیم اتصال، وگرنه نام‌های رایج
$hdr = function (array $names) {
    foreach ($names as $n) {
        if ($n === '') continue;
        $k = 'HTTP_' . strtoupper(str_replace('-', '_', $n));
        if (!empty($_SERVER[$k])) return (string)$_SERVER[$k];
    }
    return '';
};
$wh = (array)$c['webhook'];
$sig = $hdr([(string)($wh['sig_header'] ?? ''), 'X-Jakojast-Signature', 'X-Webhook-Signature', 'X-Signature', 'X-Hub-Signature-256', 'Signature']);
$ts = $hdr([(string)($wh['ts_header'] ?? ''), 'X-Jakojast-Timestamp', 'X-Webhook-Timestamp', 'X-Timestamp', 'Timestamp']);
if ($sig === '') $fail(401, 'missing signature');
$given = strtolower(preg_replace('/^sha256=/i', '', trim($sig)));
$cands = [hash_hmac('sha256', $raw, $secret)];
if ($ts !== '') array_unshift($cands, hash_hmac('sha256', $ts . '.' . $raw, $secret));
$okSig = false;
foreach ($cands as $h) if (hash_equals($h, $given)) { $okSig = true; break; }
if (!$okSig) $fail(401, 'invalid signature');
if ($ts !== '' && ctype_digit($ts)) {
    $t = (int)$ts; if ($t > 9999999999) $t = intdiv($t, 1000);
    if (abs(time() - $t) > 900) $fail(401, 'stale timestamp');
}
$payload = json_decode($raw, true);
if (!is_array($payload)) $fail(400, 'invalid json');

// تکراری (ارسال دوباره‌ی همان رویداد)
$dupKey = 'cwh_' . md5($raw);
if (class_exists('Cache') && Cache::get($dupKey)) { echo json_encode(['ok' => true, 'duplicate' => true]); exit; }
if (class_exists('Cache')) Cache::set($dupKey, 1, 86400);

$out = json_encode(['ok' => true]);
http_response_code(200);
header('Content-Length: ' . strlen($out));
header('Connection: close');
echo $out;
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
else { @ob_flush(); @flush(); }
ignore_user_abort(true);
@set_time_limit(150);

try {
    $r = SalesAgent::onWebhook($c, $payload);
    ApiConnectors::log($r['person_id'] ?? null, (int)$c['id'], 'webhook', (string)($r['event'] ?? ''), null, true, 200,
        'رویداد ' . ($r['event'] ?? '?') . (!empty($r['sent']) ? ' — پیام برای مشتری رفت' : (isset($r['note']) ? ' — ' . $r['note'] : '')), $payload, null);
} catch (Throwable $e) {
    ApiConnectors::log(null, (int)$c['id'], 'webhook', null, null, false, 500, 'خطا: ' . $e->getMessage(), $payload, null);
}
