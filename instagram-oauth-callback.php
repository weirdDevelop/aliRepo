<?php
/**
 * instagram-oauth-callback.php
 * مقصدِ ریدایرکت بعد از تأیید کاربر در صفحه‌ی اتصال اینستاگرام.
 * این آدرس دقیقاً همان چیزی است که باید در Meta App Dashboard → Instagram →
 * API setup with Instagram login → «Valid OAuth Redirect URIs» ثبت شود.
 *
 * چون همان مرورگرِ ادمینِ لاگین‌کرده اینجا برمی‌گردد (نه یک وب‌هوک سرور-به-سرور)،
 * از نشست همین پنل (Auth) برای احراز هویت استفاده می‌کنیم — نه secret جدا.
 */

$root = __DIR__;
$cfgFile = $root . '/config.php';
if (!file_exists($cfgFile)) { http_response_code(500); exit('no config'); }
$config = require $cfgFile;

require_once $root . '/core/Database.php';
require_once $root . '/core/Auth.php';
require_once $root . '/core/Url.php';
require_once $root . '/modules/InstagramAuth.php';

Database::init($config['db']);
Auth::start();

if (!Auth::check()) {
    header('Location: index.php?page=login');
    exit;
}

function ig_back(string $status, string $msg = ''): void
{
    $q = 'page=channels&ig=' . urlencode($status);
    if ($msg !== '') $q .= '&ig_msg=' . urlencode($msg);
    header('Location: index.php?' . $q);
    exit;
}

if (!empty($_GET['error'])) {
    $desc = (string)($_GET['error_description'] ?? $_GET['error'] ?? 'خطای ناشناخته');
    ig_back('error', $desc);
}

$state = (string)($_GET['state'] ?? '');
$code  = (string)($_GET['code'] ?? '');
if ($state === '' || $code === '') {
    ig_back('error', 'پارامترهای بازگشت از اینستاگرام ناقص بود');
}

$expected = $_SESSION['ig_oauth_state'] ?? '';
unset($_SESSION['ig_oauth_state']);
if ($expected === '' || !hash_equals((string)$expected, $state)) {
    ig_back('error', 'حالت امنیتی (state) اتصال منطبق نبود — دوباره تلاش کنید');
}

$result = InstagramAuth::exchangeCode($code, app_base_url($config));
if (!$result['ok']) {
    ig_back('error', $result['error'] ?? 'اتصال ناموفق بود');
}

ig_back('ok');
