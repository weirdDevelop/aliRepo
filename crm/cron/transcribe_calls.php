<?php
/**
 * جاکجاست - پردازش پس‌زمینه‌ی تبدیل صدا به متن تماس‌ها
 * Jakojast - Background Call Transcription Worker
 *
 * دو حالت اجرا:
 *
 *   ۱) دسته‌ای (پیش‌فرض، از طریق کرون سرور):
 *      (every 5 min) /usr/local/bin/php /home/jakojast/domains/jakojast.com/public_html/crm/cron/transcribe_calls.php >> .../crm/logs/transcribe.log 2>&1
 *      چند تماس (پیش‌فرض ۳ تا) که هنوز متن پیاده نشده را پردازش می‌کند.
 *
 *   ۲) تکی و فوری (--call_id=N):
 *      php cron/transcribe_calls.php --call_id=123
 *      فقط همان یک تماس را بلافاصله پردازش می‌کند. این حالت از داخل
 *      `CallSyncManager::ingestHangupEvent()` به‌صورت پس‌زمینه (exec با &)
 *      صدا زده می‌شود تا بلافاصله بعد از قطع هر تماسِ ضبط‌شده (اگر روش
 *      فعلی 'vosk' باشد که سبک/سریع است)، متن مکالمه ظرف چند ثانیه آماده
 *      شود - بدون این‌که خودِ وب‌هوک را کند/مسدود کند.
 *
 * چرا برای Whisper هنوز از دسته‌ای/کرون استفاده می‌شود؟ چون مدل Whisper
 * (به‌خصوص medium) چند ده ثانیه طول می‌کشد و اجرای هم‌زمانِ آن برای هر
 * تماس، فشار CPU را یک‌جا بالا می‌برد. Vosk سبک‌تر است و برای حالت
 * «تکی و فوری» مناسب‌تر است.
 *
 * پیش‌نیاز: نصب b7s/whisper-php (docs/TRANSCRIPTION_SETUP.md) یا راه‌اندازی
 * سرویس Vosk worker (docs/VOSK_TRANSCRIPTION_SETUP.md) - بسته به روش انتخابی.
 */

// جلوگیری از اجرای این فایل از طریق مرورگر (فقط CLI مجاز است)
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('این اسکریپت فقط از طریق CLI/cron قابل اجراست.');
}

require_once __DIR__ . '/../config.php';

// ===== حالت تکی و فوری: php transcribe_calls.php --call_id=123 =====
$singleCallId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--call_id=(\d+)$/', $arg, $m)) {
        $singleCallId = (int)$m[1];
    }
}

if ($singleCallId) {
    echo "[" . date('Y-m-d H:i:s') . "] پردازش فوری تماس #{$singleCallId}...\n";
    if (!TranscriptionService::isAvailable()) {
        echo "[" . date('Y-m-d H:i:s') . "] سرویس تبدیل صدا به متن در دسترس نیست.\n";
        exit(1);
    }
    $result = Call::transcribe($singleCallId);
    if ($result['success']) {
        echo "[" . date('Y-m-d H:i:s') . "] ✅ تماس #{$singleCallId} پردازش شد (" . mb_strlen($result['text']) . " کاراکتر).\n";
        exit(0);
    }
    echo "[" . date('Y-m-d H:i:s') . "] ❌ تماس #{$singleCallId} شکست خورد: {$result['message']}\n";
    exit(1);
}

// ===== حالت دسته‌ای پیش‌فرض (کرون) =====
// حداکثر تعداد تماس در هر اجرای cron (برای جلوگیری از فشار زیاد روی CPU)
$batchSize = 3;

// قفل: جلوگیری از اجرای هم‌زمان دو کرون (Whisper ممکن است طول بکشد)
$__lock = @fopen(__DIR__ . '/../logs/.transcribe.lock', 'c');
if ($__lock && !flock($__lock, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] اجرای قبلی هنوز فعال است؛ رد شد.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] شروع پردازش صف تبدیل صدا به متن...\n";

if (!TranscriptionService::isAvailable()) {
    echo "[" . date('Y-m-d H:i:s') . "] کتابخانه‌ی Whisper نصب نشده. راهنما: docs/TRANSCRIPTION_SETUP.md\n";
    exit(1);
}

$pending = Call::getPendingTranscriptions($batchSize);

if (empty($pending)) {
    echo "[" . date('Y-m-d H:i:s') . "] هیچ تماس در انتظاری برای پردازش نبود.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] " . count($pending) . " تماس در صف پیدا شد.\n";

foreach ($pending as $call) {
    $start = microtime(true);
    echo "[" . date('Y-m-d H:i:s') . "] در حال پردازش تماس #{$call['id']} (cuid: {$call['cuid']})...\n";

    $result = Call::transcribe($call['id']);

    $elapsed = round(microtime(true) - $start, 1);
    if ($result['success']) {
        echo "[" . date('Y-m-d H:i:s') . "] ✅ تماس #{$call['id']} در {$elapsed} ثانیه پردازش شد (" . mb_strlen($result['text']) . " کاراکتر).\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ تماس #{$call['id']} شکست خورد: {$result['message']}\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] پایان این اجرا.\n";
