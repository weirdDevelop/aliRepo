<?php
/**
 * جاکجاست - همگام‌سازی دوره‌ای تماس‌ها (Cron Job)
 * Jakojast - Periodic Call Sync (Cron)
 *
 * این فایل توسط Cron Job هر ۵ دقیقه اجرا می‌شود.
 * تماس‌های جدید را از تلفنچی دریافت کرده و در دیتابیس ذخیره می‌کند.
 *
 * تنظیم Cron Job در cPanel یا crontab (هر ۵ دقیقه):
 *   cron: every-5-min → php /path/to/jakojast/cron_sync.php
 *   یا wget با ?key=YOUR_CRON_KEY
 *
 * برای امنیت، یک cron_key در تنظیمات تعریف کنید و در URL ارسال کنید.
 */

require_once __DIR__ . '/config.php';

// بررسی cron_key (برای جلوگیری از دسترسی غیرمجاز)
// 2.1.6 (R-06): کلید CRM یا همان کلید cron اعلان‌بان (تنظیمات ← سیستم) پذیرفته می‌شود؛ بدون هیچ کلیدی
// فقط CLI یا درخواست از خود سرور اجرا می‌شود (قبلاً هر بازدیدکننده‌ای می‌توانست اجرایش کند).
require_once EB_ROOT . '/core/CronGuard.php';
$cronKey = Report::getSetting('cron_key');
$ebCronKey = null;
try { $ebCronKey = Database::getInstance()->fetchColumn("SELECT v FROM eb_settings WHERE k = 'cron.key'"); } catch (\Throwable $e) {}
$cronAuth = CronGuard::check([$cronKey, $ebCronKey], isset($_GET['key']) ? (string)$_GET['key'] : null, $_SERVER, php_sapi_name() === 'cli');
if (!CronGuard::allowed($cronAuth)) {
    Logger::error('cron_sync: denied (' . $cronAuth . ')', ['remote_ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
    http_response_code(403);
    die('Forbidden');
}

// تنظیم هدر JSON فقط در محیط web
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// قفل: اجراهای هم‌پوشان (مثلاً اجرای جبرانیِ طولانی) هم‌زمان اجرا نشوند
$__lockFp = @fopen(LOG_PATH . '/.cron_sync.lock', 'c');
if ($__lockFp && !flock($__lockFp, LOCK_EX | LOCK_NB)) {
    Logger::info('cron_sync: previous run still active, skipping');
    echo "Skipped: previous run still active\n";
    exit(0);
}

try {
    $startTime = microtime(true);

    Logger::info('Cron sync started');

    // اجرای همگام‌سازی افزایشی
    $syncManager = new CallSyncManager();
    $result = $syncManager->syncIncrementalAuto();
    $__catchUp = (($result['mode'] ?? '') === 'catch-up');

    // همگام‌سازی اپراتورها/داخلی‌ها با تلفنچی - هر ۶ ساعت یک‌بار (۱ درخواست)
    if (!$__catchUp) {
        $opsAt = Report::getSetting('telefonchy_extens_synced_at');
        if (!$opsAt || (time() - strtotime($opsAt)) > 6 * 3600) {
            try { $result['operators'] = $syncManager->syncOperators(); }
            catch (\Throwable $e) { Logger::error('cron_sync: syncOperators failed: ' . $e->getMessage()); }
        }
    }

    // ⚠️ مستقل از sync افزایشی بالا (که با اولین cuid تکراری متوقف می‌شود
    // و نمی‌تواند صدای تماس‌های قدیمی‌تر را عقب‌گرد کند): تماس‌هایی که
    // واقعاً مکالمه داشته‌اند ولی هنوز file_id ندارند را صریحاً دوباره
    // چک می‌کند. رفع مشکل «فایل صوتی یافت نشد» - نگاه کنید به
    // CallSyncManager::backfillMissingRecordings برای توضیح کامل ریشه‌ی مشکل.
    $recordingBackfill = ['checked' => 0, 'recovered' => 0];
    if (!$__catchUp) try {
        $recordingBackfill = $syncManager->backfillMissingRecordings(2); // سقف کم به‌خاطر محدودیت نرخ API (۸/دقیقه سراسری)
    } catch (\Throwable $e) {
        Logger::error('cron_sync: backfillMissingRecordings failed: ' . $e->getMessage());
    }
    $result['recording_backfill'] = $recordingBackfill;

    // ⚠️ رفع «تماس‌های گیرکرده روی در حال زنگ خوردن»: اگر وب‌هوک hangup
    // به هر دلیلی هرگز نرسیده باشد، تماس برای همیشه با وضعیت اشتباه
    // می‌ماند. نگاه کنید به CallSyncManager::reconcileStaleRingingCalls
    // برای توضیح کامل.
    $statusReconcile = ['checked' => 0, 'fixed' => 0];
    if (!$__catchUp) try {
        $statusReconcile = $syncManager->reconcileStaleRingingCalls(2);
    } catch (\Throwable $e) {
        Logger::error('cron_sync: reconcileStaleRingingCalls failed: ' . $e->getMessage());
    }
    $result['status_reconcile'] = $statusReconcile;

    // ⚠️ پاکسازی توکن‌های منقضی به اینجا منتقل شد (قبلاً روی *هر* درخواست
    // از *هر* کاربر در Auth::currentUser() اجرا می‌شد - با ۲۰ کاربر
    // هم‌زمان یعنی صدها DELETE غیرضروری در دقیقه). هر ۵ دقیقه از همین‌جا
    // کافی است.
    $purgedTokens = 0;
    try {
        $purgedTokens = Auth::purgeExpiredTokens();
    } catch (\Throwable $e) {
        Logger::error('cron_sync: purgeExpiredTokens failed: ' . $e->getMessage());
    }
    $result['purged_tokens'] = $purgedTokens;

    // ⚠️ نگهداری/پاک‌سازی لاگ‌ها: جدول‌های telefonchy_api_logs و
    // telefonchy_events قبلاً هیچ سقفی نداشتند و با گذر زمان (خصوصاً با
    // ۲۰ کاربر فعال و لاگ کامل هر تماس/وب‌هوک/backfill) می‌توانستند به
    // میلیون‌ها ردیف برسند و کوئری‌های داشبورد را کند کنند. این کار فقط
    // یک‌بار در روز اجرا می‌شود (نه هر ۵ دقیقه) تا فشار اضافه‌ای روی
    // دیتابیس نگذارد.
    $logsPurged = ['api_logs' => 0, 'webhook_events' => 0];
    try {
        $lastPurge = Report::getSetting('logs_last_purged_at');
        if (empty($lastPurge) || strtotime($lastPurge) < strtotime('-1 day')) {
            $logsPurged['api_logs'] = TelefonchyApiLog::purgeOlderThan(30);
            $logsPurged['webhook_events'] = Database::getInstance()->execute(
                'DELETE FROM telefonchy_events WHERE received_at < (NOW() - INTERVAL 14 DAY)'
            );
            Report::setSetting('logs_last_purged_at', date('Y-m-d H:i:s'));
            Logger::info('Log retention purge completed', $logsPurged);
        }
    } catch (\Throwable $e) {
        Logger::error('cron_sync: log retention purge failed: ' . $e->getMessage());
    }
    $result['logs_purged'] = $logsPurged;

    // پیامک خودکار برای تماس‌های بی‌پاسخ (اگر در تنظیمات فعال باشد)
    $missedSms = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
    try {
        $missedSms = (new SmsService())->processMissedCallSms();
    } catch (\Throwable $e) {
        Logger::error('cron_sync: missed SMS failed: ' . $e->getMessage());
    }
    $result['missed_sms'] = $missedSms;

    // r7: مراحل پیگیری شماره‌ها (پاسخ→در حال پیگیری، بی‌پاسخ→عدم پاسخ، بعد از N روز→عدم رزرو)
    try {
        $result['pipeline'] = Pipeline::processNewCalls();
    } catch (\Throwable $e) {
        Logger::error('cron_sync: pipeline failed: ' . $e->getMessage());
    }

    $duration = round(microtime(true) - $startTime, 2);

    Logger::info('Cron sync completed', [
        'duration' => $duration,
        'result'   => $result,
    ]);

    $output = [
        'success'  => $result['success'],
        'duration' => $duration . 's',
        'timestamp'=> date('Y-m-d H:i:s'),
        'result'   => $result,
    ];

    if (php_sapi_name() === 'cli') {
        // خروجی متنی در CLI
        echo "Cron Sync Report\n";
        echo "================\n";
        echo "Time: " . $output['timestamp'] . "\n";
        echo "Duration: " . $output['duration'] . "\n";
        echo "Success: " . ($result['success'] ? 'Yes' : 'No') . "\n";
        if ($result['success']) {
            echo "Total fetched: " . ($result['total_fetched'] ?? 0) . "\n";
            echo "New calls: " . ($result['new_count'] ?? 0) . "\n";
            echo "Updated: " . ($result['updated_count'] ?? 0) . "\n";
            echo "Contacts matched: " . ($result['contacts_matched'] ?? 0) . "\n";
            echo "Recording backfill checked/recovered: " . $recordingBackfill['checked'] . "/" . $recordingBackfill['recovered'] . "\n";
            echo "Stale ringing checked/fixed: " . $statusReconcile['checked'] . "/" . $statusReconcile['fixed'] . "\n";
            echo "Expired tokens purged: " . $purgedTokens . "\n";
            if ($logsPurged['api_logs'] || $logsPurged['webhook_events']) {
                echo "Logs purged: api_logs=" . $logsPurged['api_logs'] . ", webhook_events=" . $logsPurged['webhook_events'] . "\n";
            }
        } else {
            echo "Error: " . ($result['message'] ?? 'unknown') . "\n";
        }
        echo "================\n";
    } else {
        // خروجی JSON در محیط web
        echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

} catch (Exception $e) {
    Logger::error('Cron sync exception: ' . $e->getMessage());

    if (php_sapi_name() === 'cli') {
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode([
            'success'  => false,
            'message'  => $e->getMessage(),
            'timestamp'=> date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
    }
}
