<?php
/**
 * instagram-roboclick-oauth-callback.php
 * مقصدِ ریدایرکت بعد از تأیید اتصال پیج در فلوی OAuth+PKCE سرویس‌دهنده.
 * همین آدرس باید در پنل سرویس‌دهنده به‌عنوان «Allowed Return URL» ثبت شود
 * (تنظیمات → سرویس‌دهنده → OAuth، یا از InstagramRoboclick::oauthReturnUrl()).
 *
 * چون همان مرورگرِ ادمینِ لاگین‌کرده اینجا برمی‌گردد (نه یک وب‌هوک سرور-به-سرور)،
 * از نشست همین پنل (Auth) استفاده می‌کنیم — دقیقاً مثل instagram-oauth-callback.php.
 * طبق نکته‌ی امنیتی مستندات: نتیجه‌ی معتبر فقط از پاسخ CompleteInstagramConnection
 * می‌آید، نه از خودِ query string مرورگر — پس همین‌جا سمت سرور آن را صدا می‌زنیم.
 */

$root = __DIR__;
$cfgFile = $root . '/config.php';
if (!file_exists($cfgFile)) { http_response_code(500); exit('no config'); }
$config = require $cfgFile;

require_once $root . '/core/Database.php';
require_once $root . '/core/Auth.php';
require_once $root . '/core/Url.php';
require_once $root . '/modules/ApiLog.php';
require_once $root . '/modules/InstagramRoboclick.php';

Database::init($config['db']);
Auth::start();

function rc_cb_redirect(string $msg, bool $ok): void
{
    $q = http_build_query(['page' => 'instagram', 'rc_oauth' => $ok ? 'ok' : 'fail', 'rc_msg' => $msg]);
    header('Location: index.php?' . $q);
    exit;
}

if (!Auth::check()) {
    header('Location: index.php?page=login');
    exit;
}

$connectionCode = trim((string)($_GET['connectionCode'] ?? $_GET['code'] ?? ''));
$state = trim((string)($_GET['state'] ?? ''));

if ($connectionCode === '' || $state === '') {
    rc_cb_redirect('پارامترهای بازگشتی از سرویس‌دهنده ناقص بود.', false);
}

$r = InstagramRoboclick::completeOAuth($connectionCode, $state);

if (empty($r['ok'])) {
    rc_cb_redirect($r['error'] ?? 'اتصال ناموفق بود', false);
}

$msg = !empty($r['reconnected']) ? 'پیج دوباره وصل شد.' : 'پیج جدید با موفقیت اضافه شد.';
if (empty($r['account_key_issued'])) {
    $msg .= ' (توجه: Account Key تازه صادر نشد — اگر پیج قبلاً کلید داشت همان معتبر می‌ماند.)';
}
rc_cb_redirect($msg, true);
