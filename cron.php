<?php
/**
 * cron.php — نقطه‌ی ورود cron برای پردازش پیام‌های زمان‌بندی‌شده.
 *
 * تنظیم در سرور (هر دقیقه):
 *   * * * * * wget -q -O /dev/null "https://YOURDOMAIN/elanban/cron.php?key=CRON_KEY"
 *
 * CRON_KEY در eb_settings با کلید 'cron.key' ذخیره می‌شود.
 */

$cfgFile = __DIR__ . '/config.php';
if (!file_exists($cfgFile)) { exit('no config'); }
$config = require $cfgFile;

// cron از راه wget/curl صدا زده می‌شود؛ اگر اتصال زودتر قطع شد یا کار طول کشید، اجرا نیمه‌کاره نماند
@ignore_user_abort(true);
@set_time_limit(0);

require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Url.php';
require_once __DIR__ . '/core/Tz.php';
require_once __DIR__ . '/core/SchemaV4.php';
require_once __DIR__ . '/modules/NetProxy.php';
require_once __DIR__ . '/modules/InstagramAuth.php';
require_once __DIR__ . '/channels/ChannelManager.php';
require_once __DIR__ . '/modules/IgCommentAutomation.php';
require_once __DIR__ . '/modules/Persons.php';
require_once __DIR__ . '/modules/Templates.php';
require_once __DIR__ . '/modules/Sender.php';
require_once __DIR__ . '/modules/Personalize.php';
require_once __DIR__ . '/modules/BotReply.php';
require_once __DIR__ . '/modules/AutoReply.php';
require_once __DIR__ . '/modules/Scheduler.php';
require_once __DIR__ . '/modules/SmsInbound.php';
require_once __DIR__ . '/modules/SendLog.php';
require_once __DIR__ . '/modules/Queue.php';
require_once __DIR__ . '/modules/Campaigns.php';
require_once __DIR__ . '/modules/SmsDlr.php';
require_once __DIR__ . '/modules/ApiLog.php';
require_once __DIR__ . '/modules/Links.php';
require_once __DIR__ . '/modules/GeoIp.php';
require_once __DIR__ . '/modules/GeoIpCountry.php';
require_once __DIR__ . '/modules/AnalyticsSettings.php';
require_once __DIR__ . '/modules/AnalyticsRollup.php';

Database::init($config['db']);



// بعد از init، چون در حالت CLI آدرس پایه از eb_settings خوانده می‌شود (core/Url.php)
Personalize::setBaseUrl(app_base_url($config));
// رفع یک خلأ قبلی: کمپین/زمان‌بندیِ عکس روی اینستاگرام از cron بدون این، آدرس عمومی نداشت
InstagramDriver::setBaseUrl(app_base_url($config));

// بررسی کلید امنیتی (2.1.6 — R-06): بدون کلید فقط CLI یا درخواست از خود سرور؛ مقایسه‌ی constant-time
require_once __DIR__ . '/core/CronGuard.php';
$keyRow = Database::one("SELECT v FROM eb_settings WHERE k='cron.key'");
$cronAuth = CronGuard::check([$keyRow['v'] ?? null], isset($_GET['key']) ? (string)$_GET['key'] : null, $_SERVER, PHP_SAPI === 'cli');
if (!CronGuard::allowed($cronAuth)) {
    // ثبت برای هشدار داشبورد (چک‌لیست راه‌اندازی ← «اجرای خودکار») — بدون ذخیره‌ی کلید ارسالی
    try {
        Database::run("INSERT INTO eb_settings (k, v) VALUES ('cron.last_denied', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)",
            [json_encode(['at' => date('Y-m-d H:i:s'), 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'reason' => $cronAuth])]);
    } catch (Throwable $e) {}
    error_log('[elanban cron] denied: ' . $cronAuth . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    http_response_code(403);
    exit('forbidden');
}
try { Database::run("INSERT INTO eb_settings (k, v) VALUES ('cron.last_run', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [date('Y-m-d H:i:s')]); } catch (Throwable $e) {}
// 2.2.0: migrationهای غیرمخربِ معلق خودکار اعمال می‌شوند (هرگز خطا به بیرون نمی‌دهد)
require_once __DIR__ . '/core/Migrations.php';   // explicit: the autoloader is loaded later in this file
try { Migrations::autoApply('cron'); } catch (Throwable $e) { error_log('[cron migrate] ' . $e->getMessage()); }

// ---- قفل سراسری اجرا (مورد ۶: ارسال دوباره) ----
// cron هر دقیقه اجرا می‌شود ولی یک اجرا می‌تواند بیش از یک دقیقه طول بکشد
// (۸۰ ارسال × زمان پاسخ API). آن‌وقت دو اجرا روی هم می‌افتند و مسیرهایی که
// «اول SELECT بعد UPDATE» می‌کردند — زمان‌بندی و مراحل کمپین — دو بار می‌فرستادند.
// این قفل تضمین می‌کند همیشه فقط یک cron در حال اجراست. اگر اجرای قبلی تمام نشده،
// این اجرا بی‌صدا رد می‌شود (اجرای دقیقه‌ی بعد کار را ادامه می‌دهد).
$cronLock = 'eb_cron_run';
$gotLock = true;
try {
    $lockRow = Database::one("SELECT GET_LOCK(?, 0) g", [$cronLock]);
    $gotLock = (int)($lockRow['g'] ?? 0) === 1;
} catch (Throwable $e) {
    $gotLock = true; // اگر GET_LOCK پشتیبانی نشد، جلوی cron را نگیر
}
if (!$gotLock) {
    echo 'Cron busy - اجرای قبلی هنوز تمام نشده - ' . date('Y-m-d H:i:s');
    exit;
}

try {
$n = Scheduler::processDue(5);
$queue = Queue::process(80, false);   // صف ارسال انبوه؛ قفل داخلی لازم نیست چون cron قفل سراسری دارد
$sms = SmsInbound::pull();   // دریافت پاسخ‌های پیامک (خط اختصاصی)
$camp = Campaigns::processDue();  // مراحل بعدی کمپین‌های چندمرحله‌ای
$dlr = SmsDlr::poll();            // استعلام وضعیت تحویل پیامک

// توکن ۶۰روزه‌ی اینستاگرام: اگر کمتر از ۱۰ روز مانده، تمدید کن (وگرنه اتصال قطع می‌شود)
try { InstagramAuth::refreshIfNeeded(); } catch (Throwable $e) { /* بی‌صدا؛ اتصال بعداً از پنل قابل بررسی است */ }

// پاک‌سازی لاگ‌های قدیمی‌تر از ۶ ماه (یک‌بار در روز کافی است، ولی بی‌ضرر است هر بار)
$cleaned = SendLog::cleanup();
ApiLog::cleanup();
if (class_exists('ApichiLog') && class_exists('InstagramApichi')) ApichiLog::cleanup(max(1, (int)InstagramApichi::setting('log_days', '14')));
try { IgCommentAutomation::cleanupPending(); } catch (Throwable $e) {}
// رویدادهای dedup وب‌هوک: بیش از ۲ روز دیگر لازم نیستند (retry پلتفرم کوتاه‌مدت است)
try {
    Database::run("DELETE FROM eb_webhook_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
} catch (Throwable $e) { /* جدول ممکن است هنوز ساخته نشده باشد */ }

// اینستاگرام از طریق یار API: در حالت polling هر دقیقه، در حالت وب‌هوک هر ۱۰ دقیقه برای جبران رویدادهای جاافتاده
$apichiPolled = 0;
try {
    require_once __DIR__ . '/webhook/_ig_bootstrap.php';
    if (InstagramApichi::isConfigured()) {
        $apichiPolled = InstagramApichi::poll($config, false);
    }
} catch (Throwable $e) { /* بی‌صدا؛ خطا در apichi.last_poll_error یا لاگ خام دیده می‌شود */ }

// صف انتشار اینستاگرام (پست و استوری زمان‌بندی‌شده)
$igPub = ['published' => 0, 'failed' => 0];
try {
    require_once __DIR__ . '/webhook/_ig_bootstrap.php';
    require_once __DIR__ . '/modules/Marketing.php';
    require_once __DIR__ . '/modules/IgPosts.php';
    $igPub = IgPosts::processDue(3);
} catch (Throwable $e) { error_log('[cron igposts] ' . $e->getMessage()); }

// سناریوها: اجراهای منتظر (تأخیر/انتظار) و سناریوهای زمان‌بندی‌شده
$flowsRun = 0;
try {
    require_once __DIR__ . '/modules/Flows.php';
    $flowsRun = Flows::tick();
} catch (Throwable $e) { error_log('[cron flows] ' . $e->getMessage()); }

// سرچ کنسول: عکس روزانه و هشدارها (خودش گیت ساعتی دارد)
try {
    require_once __DIR__ . '/modules/SearchConsole.php';
    SearchConsole::cronTick();
} catch (Throwable $e) { error_log('[cron gsc] ' . $e->getMessage()); }

// فهرست خلاصه‌ی گفتگوها: ساخت اولیه/تور چرخشی تا شمارنده‌ها همیشه درست بمانند
$convIdx = 0;
try {
    require_once __DIR__ . '/core/Autoload.php';
    require_once __DIR__ . '/modules/Inbox.php';
    require_once __DIR__ . '/modules/ConvIndex.php';
    if (ConvIndex::ready()) $convIdx = ConvIndex::cronSweep(1500);
} catch (Throwable $e) { error_log('[cron convidx] ' . $e->getMessage()); }

// آمار خودکار محتوای منتشرشده‌ی تقویم (روزی یک بار برای هر محتوا)
$mktm = 0;
try { if (class_exists('ContentMetrics')) $mktm = ContentMetrics::cronTick(6); } catch (Throwable $e) { error_log('[cron mktm] ' . $e->getMessage()); }

// پیام‌های پیگیری خودکار (مثلاً ۲۳ ساعت بعد از آخرین پیام مشتری)
$followups = 0;
try { if (class_exists('Followups')) $followups = Followups::tick(); } catch (Throwable $e) { error_log('[cron followups] ' . $e->getMessage()); }
// دستیار فروش: پیگیری بعد از پیشنهاد، یادآوری پرداخت، پیام روز ورود، نظرسنجی بعد از اقامت
$agentLc = null;
try { if (class_exists('AgentLifecycle')) $agentLc = AgentLifecycle::tick(); } catch (Throwable $e) { error_log('[cron agent] ' . $e->getMessage()); }
// ۱.۰.۱۴: تلفن گویای پشتیبان‌ها + ادامه‌ی یکسان‌سازی شماره‌های قدیمی (اگر هنوز تمام نشده)
$ivr = null;
try {
    require_once __DIR__ . '/core/Autoload.php';
    if (class_exists('ChatPlus')) {
        $ivr = ChatPlus::ivrTick();
        $mig = json_decode((string)(Database::one("SELECT v FROM eb_settings WHERE k = 'chatplus.phone_mig'")['v'] ?? ''), true);
        if (empty($mig['done'])) ChatPlus::migratePhones(20);
    }
} catch (Throwable $e) { error_log('[cron chatplus] ' . $e->getMessage()); }

// ۲.۰.۱: پرونده‌ی یکپارچه — شماره‌های تازه‌ی CRM (تماس، مشتری، سرنخ، میزبان) در «مشتریان»
$crmSync = null;
try { if (class_exists('CrmBridge') && CrmBridge::present() && CrmBridge::ready()) $crmSync = CrmBridge::syncStep(12); } catch (Throwable $e) { error_log('[cron crm] ' . $e->getMessage()); }

// رول‌آپ روزانه‌ی آنالیتیکس (خودش گیت ساعتی دارد؛ اگر ماژول خاموش باشد فوری برمی‌گردد)
$anRollup = 'skip';
try { $anRollup = AnalyticsRollup::run(); } catch (Throwable $e) { $anRollup = 'error: ' . $e->getMessage(); }

// 2.2.0: هسته‌ی جدید — کارهای پس‌زمینه (eb_jobs) و تحویل رویدادها (eb_outbox)، با سقف زمانی
$kernel = '-';
try {
    require_once __DIR__ . '/core/Autoload.php';
    if (Migrations::isApplied('0002_kernel')) {
        if (is_file(__DIR__ . '/src/bootstrap.php')) require_once __DIR__ . '/src/bootstrap.php';
        $jw = Jobs::work(15, 100);
        $od = Outbox::dispatch(200, 10);
        $kernel = "jobs {$jw['done']}/{$jw['retry']}/{$jw['dead']} events {$od['delivered']}/{$od['failed']}/{$od['dead']}";
    }
} catch (Throwable $e) { error_log('[cron kernel] ' . $e->getMessage()); $kernel = 'error'; }

echo "Cron OK - kernel: {$kernel} - scheduled: {$n} - queue: {$queue['processed']}(↑{$queue['sent']} ✗{$queue['failed']} ⏳{$queue['remaining']}) - sms_inbound: {$sms} - campaign_steps: {$camp} - dlr: {$dlr} - cleaned: {$cleaned} - apichi: {$apichiPolled} - ig_posts: {$igPub['published']}/{$igPub['failed']} - flows: {$flowsRun} - followups: {$followups} - agent: " . ($agentLc ? json_encode($agentLc, JSON_UNESCAPED_UNICODE) : '-') . " - ivr: " . ($ivr ? $ivr['sent'] . '/' . $ivr['failed'] : '-') . " - crm: " . ($crmSync ? $crmSync['processed'] . ($crmSync['done'] ? '' : '+') : '-') . " - {$anRollup} - " . date('Y-m-d H:i:s');
} finally {
    // قفل در هر حالت آزاد شود، وگرنه cron تا بسته‌شدن اتصال می‌خوابد
    try { Database::run("SELECT RELEASE_LOCK(?)", [$cronLock]); } catch (Throwable $e) { /* بی‌صدا */ }
}
