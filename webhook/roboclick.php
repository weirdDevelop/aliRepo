<?php
/**
 * webhook/roboclick.php
 * نقطه‌ی دریافت رویدادهای اینستاگرام از سرویس‌دهنده — چندپیجی، با پایپ‌لاین کامل.
 * (روش قدیمی/مستقیم. روش پیشنهادی جدید: اتصال از طریق یار API — webhook/apichi.php)
 * منطق پردازش به modules/IgWebhookPipeline.php منتقل شده و بین هر دو مسیر مشترک است.
 *
 * برخلاف webhook/directsaz.php (یک secret مشترک چون فقط یک پیج وصل می‌شد)،
 * اینجا هر پیج یک ردیف در eb_ig_accounts و یک webhook_secret مجزا دارد. آدرسی
 * که باید در پنل سرویس‌دهنده برای هر پیج ثبت شود:
 *   https://example.com/.../webhook/roboclick.php?account={شناسه‌ی داخلی پیج}
 * و امضا با secret همان پیج تأیید می‌شود (نه با account.id داخل payload —
 * چون payload تا قبل از تأیید امضا قابل‌اعتماد نیست).
 *
 * پایپ‌لاین مشترک با دایرکت‌ساز (فرم→منو→پاسخ‌خودکار→AI) از طریق
 * ChannelManager::setInstagramOverride() به همین پیج/اکانت سرویس‌دهنده وصل
 * می‌شود، بدون اینکه آن ماژول‌ها تغییری کنند.
 *
 * مزیت واقعی سرویس‌دهنده نسبت به دایرکت‌ساز که اینجا استفاده شده:
 *   - رویداد button_click واقعی و مستند → هم تأیید فالو با یک کلیک واقعی
 *     (نه حدس متنی)، هم پیمایش منوی بات (nav:) با کلیک واقعی دکمه.
 *   - رویداد کامنت و ریپلای استوری رسمی و مستند.
 */

$root = dirname(__DIR__);
$cfgFile = $root . '/config.php';
if (!file_exists($cfgFile)) { http_response_code(500); exit('no config'); }
$config = require $cfgFile;

require $root . '/webhook/_ig_bootstrap.php';

Database::init($config['db']);


$accountId = (int)($_GET['account'] ?? 0);
if (!$accountId) { http_response_code(400); exit('missing account'); }

$account = InstagramRoboclick::get($accountId);
if (!$account || !$account['is_active'] || empty($account['webhook_secret'])) {
    http_response_code(200); exit('not configured'); // ۲۰۰ تا سرویس‌دهنده retry نکند
}

$rawBody = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_ROBOCLICK_TIMESTAMP'] ?? '';
$sigHeader = $_SERVER['HTTP_X_ROBOCLICK_SIGNATURE'] ?? '';

if (!InstagramRoboclickDriver::verifySignature($rawBody, $timestamp, $sigHeader, $account['webhook_secret'])) {
    http_response_code(403); exit('forbidden');
}

$payload = json_decode($rawBody, true);

if (class_exists('ApiLog')) {
    ApiLog::record('instagram_roboclick', 'webhook_in', ['account_id' => $accountId, 'raw' => mb_substr($rawBody, 0, 2000)], $payload, null, is_array($payload), null);
}

if (!is_array($payload)) { http_response_code(200); exit('OK'); }

// ---- جلوگیری از پردازش دوباره (retry سرویس‌دهنده؛ event_id از هدر یا payload) ----
$eventId = $_SERVER['HTTP_X_ROBOCLICK_EVENT_ID'] ?? ($payload['event_id'] ?? null);
if ($eventId) {
    try {
        Database::run("INSERT INTO eb_webhook_events (channel, event_key) VALUES ('instagram_roboclick', ?)", [(string)$eventId]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') { http_response_code(200); exit('duplicate'); }
    } catch (Throwable $e) { /* fail-open */ }
}

$driver = InstagramRoboclick::driverForAccount($account);

// پایپ‌لاین مشترک (دایرکت/کامنت/کلیک دکمه) — modules/IgWebhookPipeline.php
IgWebhookPipeline::process($account, $payload, $driver, $config, 'instagram_roboclick');

if (!headers_sent()) http_response_code(200);   // دستیار فروش ممکن است پاسخ را زودتر فرستاده باشد
echo 'OK';
