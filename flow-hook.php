<?php
/**
 * flow-hook.php — آدرس عمومی وب‌هوک برای سناریوهایی که با «وب‌هوک ورودی» شروع می‌شوند.
 *   POST https://panel.example.com/flow-hook.php?id=12&key=SECRET   (بدنه: JSON یا فرم)
 * فروشگاه، فرم سایت، CRM یا هر سیستم دیگری می‌تواند با این آدرس یک سناریو را اجرا کند.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$root = __DIR__;
if (!file_exists($root . '/config.php')) { http_response_code(500); exit('{"ok":false}'); }
$config = require $root . '/config.php';
@set_time_limit(60);
require_once $root . '/core/Database.php';
require_once $root . '/core/Url.php';
require_once $root . '/core/Tz.php';
require_once $root . '/core/SchemaV4.php';
require_once $root . '/webhook/_ig_bootstrap.php';
require_once $root . '/modules/Sender.php';
require_once $root . '/modules/Groups.php';
require_once $root . '/modules/Flows.php';
Database::init($config['db']);

if (function_exists('app_base_url') && class_exists('Personalize')) Personalize::setBaseUrl(app_base_url($config));

$id = (int)($_GET['id'] ?? 0);
$key = (string)($_GET['key'] ?? ($_SERVER['HTTP_X_FLOW_KEY'] ?? ''));
$raw = (string)file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = $_POST ?: [];
foreach ($_GET as $k => $v) if (!in_array($k, ['id', 'key'], true) && !isset($payload[$k])) $payload[$k] = $v;

try {
    $r = Flows::onWebhook($id, $key, $payload);
    http_response_code($r['code'] ?? ($r['ok'] ? 200 : 400));
    unset($r['code']);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo '{"ok":false,"error":"server_error"}';
}
