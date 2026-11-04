<?php
/**
 * api.php — REST v2 front controller (2.2.0). URL form works without mod_rewrite:
 *   /api.php/v2/platform/ping
 * Contract: docs/api/api-overview.md, docs/api/error-contract.md. Routes: src/<Context>/routes.php.
 */
$root = __DIR__;
if (!is_file($root . '/config.php')) { http_response_code(503); exit; }
$config = require $root . '/config.php';
ini_set('display_errors', '0');
require_once $root . '/core/Database.php';
require_once $root . '/core/Autoload.php';
require_once $root . '/core/Version.php';
Database::init($config['db']);
Auth::start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '' && isset($_SERVER['REQUEST_URI'])) {               // some hosts do not set PATH_INFO
    $u = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
    $p = strpos($u, '/api.php');
    $path = $p === false ? '' : substr($u, $p + 8);
}
$path = '/' . trim($path, '/');

if (!Migrations::isApplied('0002_kernel')) {
    http_response_code(503);
    exit(json_encode(['ok' => false, 'error' => ['code' => 'unavailable', 'message' => 'به‌روزرسانی دیتابیس انجام نشده است (تنظیمات ← سیستم ← به‌روزرسانی دیتابیس)'], 'request_id' => Log::requestId()], JSON_UNESCAPED_UNICODE));
}
require_once $root . '/src/bootstrap.php';
Rest::loadRoutes($root . '/src');

$raw = (string)file_get_contents('php://input');
$hdr = [];
foreach ($_SERVER as $k => $v) if (strpos($k, 'HTTP_') === 0) $hdr[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
$body = $raw !== '' ? json_decode($raw, true) : null;
if ($raw !== '' && !is_array($body)) {
    http_response_code(400);
    header('X-Request-Id: ' . Log::requestId());
    exit(json_encode(Rest::errorBody(new RestError(400, 'validation_error', 'بدنه‌ی JSON نامعتبر است')), JSON_UNESCAPED_UNICODE));
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // REST handlers only read the session

[$status, $out, $headers] = Rest::handle([
    'method' => strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), 'path' => $path,
    'query' => $_GET, 'body' => $body, 'raw' => $raw, 'headers' => $hdr,
]);
http_response_code($status);
foreach ($headers as $k => $v) header($k . ': ' . $v);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
