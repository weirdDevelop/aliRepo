<?php
/**
 * gsc-oauth.php — بازگشت از صفحه‌ی اجازه‌ی گوگل (اتصال سرچ کنسول).
 * این آدرس باید در Google Cloud Console به‌عنوان Authorized redirect URI ثبت شود.
 */
$root = __DIR__;
$config = require $root . '/config.php';
require_once $root . '/core/Database.php';
require_once $root . '/core/Url.php';
require_once $root . '/core/Auth.php';
require_once $root . '/core/Tz.php';
require_once $root . '/core/SchemaV4.php';
require_once $root . '/modules/NetProxy.php';
require_once $root . '/modules/SearchConsole.php';
Database::init($config['db']);
Auth::start();
$back = function (string $msg, bool $ok) {
    header('Location: index.php?' . http_build_query(['page' => 'seo']) . '#seo.settings.' . ($ok ? 'ok' : 'err') . '.' . rawurlencode($msg));
    exit;
};
if (!Auth::check() || !Auth::isAdmin()) { http_response_code(403); exit('ابتدا به‌عنوان مدیر وارد پنل شوید.'); }
$state = (string)($_GET['state'] ?? '');
$saved = (string)(Database::one("SELECT v FROM eb_settings WHERE k = 'gsc.oauth_state'")['v'] ?? '');
[$sv, $st] = array_pad(explode('|', $saved), 2, '0');
Database::run("DELETE FROM eb_settings WHERE k = 'gsc.oauth_state'");
if ($state === '' || !hash_equals($sv, $state) || time() - (int)$st > 900) $back('درخواست اتصال منقضی یا نامعتبر بود؛ دوباره امتحان کنید', false);
if (!empty($_GET['error'])) $back('اجازه‌ی دسترسی داده نشد', false);
$r = SearchConsole::exchangeCode((string)($_GET['code'] ?? ''), app_base_url($config));
$back($r['ok'] ? 'سرچ کنسول وصل شد' : $r['error'], $r['ok']);
