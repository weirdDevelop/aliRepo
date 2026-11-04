<?php
/**
 * جاکجاست - وب‌هوک تلفنچی (دریافت ایونت تماس)
 * Jakojast - Telefonchy Call Event Webhook
 *
 * ✅ ساختار واقعی (تأیید‌شده با نمونه‌ی خام مستقیم از پنل «دریافتی خام
 * webhook» - نه از مستندات): **هیچ کلید `data` در ریشه‌ی payload وجود
 * ندارد** - همه‌ی فیلدها مستقیم در ریشه هستند. این دقیقاً همان چیزی بود
 * که باعث می‌شد `$payload['data'] ?? []` همیشه آرایه‌ی خالی برگرداند و
 * در نتیجه هیچ رویدادی هیچ‌وقت به‌عنوان ringing/hangup تشخیص داده نشود.
 *
 * دو نوع رویداد، با ساختار **کاملاً متفاوت از هم** (نه فقط یک اسم دیگر):
 *
 * ringing:
 * {
 *   "service_id", "cuid", "unique_id", "src" (عدد JSON!), "dest" (عدد JSON!),
 *   "type": "incoming"|"outgoing", "client_ip", "event": "ringing",
 *   "datetime" (وقت تهران), "trunk_number", "trunk_incoming"|"trunk_outgoing",
 *   "webservice_token", "events_hook", "app_name", "date" (وقت تهران)
 * }
 *
 * hangup:
 * {
 *   "starttime", "endtime" (هر دو UTC!), "src", "dst" (نه dest!),
 *   "type": "incoming"|"outgoing", "disposition": "ANSWERED"|"NO ANSWER"|...,
 *   "duration", "billsec" (مدت مکالمه), "wait" (مدت انتظار),
 *   "record" (نام فایل، فقط اگر ضبط شده), "unique_id", "cuid",
 *   "entry_point"|"outgoing_point", "did", "webservice_token", "service_id",
 *   "events_hook", "trunk_number", "trunk_incoming", "event": "hangup",
 *   "app_name", "date" (وقت تهران، ولی معادل زمان پایان تماس)
 * }
 *
 * نکات مهم دیگر کشف‌شده با نمونه‌ی خام:
 *   - در ringing، شماره‌ها عدد JSON هستند (نه رشته) → صفر ابتدایی شماره‌ی
 *     موبایل ایرانی گم می‌شود (JSON عدد نمی‌تواند صفر ابتدایی داشته باشد).
 *     رفع شد در `CallSyncManager::normalizeIranianMobile()`.
 *   - hangup خودش duration/billsec/wait/disposition/record را می‌دهد،
 *     پس دیگر نیازی نیست بعد از هر hangup یک GET /call جداگانه (blocking)
 *     زده شود - فقط برای file_id یک تلاش best-effort انجام می‌شود.
 *   - بعضی وقت‌ها یک POST خالی (`[]`) به این آدرس می‌رسد - این‌ها ظاهراً
 *     یک نوع ping/heartbeat دوره‌ای از سمت تلفنچی هستند (نه رویداد
 *     واقعی)؛ چون cuid ندارند، بی‌خطر نادیده گرفته می‌شوند.
 *
 * امنیت (2.1.5): webservice_token داخل payload با «توکن وب‌هوک» یا «توکن API» مقایسه
 * می‌شود (hash_equals) و در حالت پیش‌فرض enforce، رویدادِ بدون توکن یا با توکن اشتباه
 * با 403 رد می‌شود. حالت monitor (تنظیمات CRM) رفتار قبلی را نگه می‌دارد.
 *
 * نکته: طبق مستندات، سرور باید در سریع‌ترین حالت ممکن 200 برگرداند. از
 * نسخه‌ی فعلی، پردازش hangup دیگر یک درخواست شبکه‌ی مسدودکننده به تلفنچی
 * نمی‌زند (چون خودِ payload اطلاعات کافی دارد)، پس این نگرانی کمتر شد؛
 * تنها استثنا enrichment غیرمسدودکننده‌ی file_id برای تماس‌های ضبط‌شده است.
 */

require_once __DIR__ . '/config.php';
// webhook نیازی به session ندارد - آزاد کردن قفل session
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$startTime = microtime(true);
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
// ⚠️ وب‌هوک CDR ممکن است به‌صورت form-urlencoded برسد (نه JSON) - قبلاً این حالت
// به `[]` تبدیل و به‌عنوان «skipped (no cuid)» دور ریخته می‌شد.
if (!is_array($payload) || !$payload) {
    $payload = [];
    if (!empty($_POST)) {
        $payload = $_POST;
    } elseif (is_string($rawBody) && $rawBody !== '' && strpos($rawBody, '=') !== false) {
        parse_str($rawBody, $payload);
    }
    if (isset($payload['data']) && is_string($payload['data'])) {
        $decodedData = json_decode($payload['data'], true);
        if (is_array($decodedData)) $payload['data'] = $decodedData;
    }
}

// ⚠️ رفع باگ اصلی: ساختار واقعی هیچ کلید 'data' ندارد - همه‌چیز در ریشه
// است. برای اطمینان و سازگاری با احتمال تغییر آینده‌ی تلفنچی، اگر یک
// روز واقعاً یک کلید 'data' آرایه‌ای اضافه شد، همان اولویت دارد؛ در غیر
// این‌صورت (وضعیت فعلی و واقعی) از خودِ payload استفاده می‌شود.
$eventPayloadData = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : $payload;

// ⚠️ تشخیص مقاوم‌شده‌ی نوع رویداد: بی‌حساسیت به بزرگی/کوچکی حروف و
// فاصله‌ی اضافه‌ی احتمالی (مثلاً " Ringing" یا "RINGING" یا "Hang-up").
// اگر تلفنچی از یک کلید دیگر به‌جای `event` استفاده کند (مثلاً
// `event_type` یا `call_event`)، آن‌ها را هم امتحان می‌کنیم.
$rawEventValue = $eventPayloadData['event']
    ?? $eventPayloadData['event_type']
    ?? $eventPayloadData['call_event']
    ?? null;
$eventTypeNormalized = $rawEventValue !== null
    ? strtolower(trim(str_replace(['-', '_', ' '], '', (string)$rawEventValue)))
    : null;

$isRinging = in_array($eventTypeNormalized, ['ringing', 'ring', 'start'], true);
$isHangup  = in_array($eventTypeNormalized, ['hangup', 'hangout', 'end', 'ended', 'completed'], true);

// وب‌هوک CDR (طبق مستندات تلفنچی): {"route":"cdr","data":{"call_id":..., ...}} - بدون cuid و بدون event
$routeValue = strtolower(trim((string)($payload['route'] ?? $eventPayloadData['route'] ?? '')));
$isCdr = !$isRinging && !$isHangup && (
    $routeValue === 'cdr'
    || (empty($eventPayloadData['cuid']) && !empty($eventPayloadData['call_id']))
);

$cuid = $eventPayloadData['cuid'] ?? $eventPayloadData['unique_id']
     ?? ($isCdr ? ($eventPayloadData['call_id'] ?? null) : null);

// ===== اعتبارسنجی امنیتی (2.1.5 — R-03) =====
// قبلاً عدم تطابق فقط لاگ می‌شد و رویدادِ بدون توکن یا نصبِ بدون توکن هم پذیرفته می‌شد؛
// یعنی هر کسی با داشتن آدرس می‌توانست تماس/سرنخ جعلی بسازد. حالا در حالت پیش‌فرض
// (enforce) رد می‌شود. رویداد واقعیِ ردشده از دست نمی‌رود: cron_sync همان تماس را از
// API تلفنچی (/calls) در اجرای بعدی می‌گیرد. توضیح کامل: TelefonchyWebhookAuth.php
if (TelefonchyWebhookAuth::isEmpty($payload)) {
    // heartbeat / POST خالی تلفنچی (چیزی برای پردازش ندارد)
    http_response_code(200);
    echo json_encode(['success' => true, 'skipped' => 'empty']);
    exit;
}
$authMode = TelefonchyWebhookAuth::mode(Report::getSetting('telefonchy_webhook_auth'));
$authResult = TelefonchyWebhookAuth::check($payload, [
    Report::getSetting('telefonchy_webhook_token'),
    Report::getSetting('telefonchy_token'),
]);
$tokenMismatch = ($authResult !== TelefonchyWebhookAuth::OK);
if ($tokenMismatch) {
    $receivedToken = TelefonchyWebhookAuth::receivedToken($payload);
    Logger::error('Telefonchy webhook: auth ' . $authResult . ($authMode === 'enforce' ? ' — rejected' : ' — accepted (monitor mode)'), [
        'reason'       => $authResult,
        'mode'         => $authMode,
        'received_len' => $receivedToken !== null ? strlen($receivedToken) : 0,   // never the token itself
        'cuid'         => is_scalar($cuid) ? (string)$cuid : null,
        'remote_ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
    if ($authMode === 'enforce') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'forbidden']);
        exit;
    }
}

try {
    // لاگ خام (همیشه، صرف‌نظر از نوع رویداد)
    $db = Database::getInstance();
    $db->insert('telefonchy_events', [
        'event_type' => $rawEventValue !== null ? (string)$rawEventValue : ($isCdr ? 'cdr' : 'unknown'),
        'call_cuid'  => $cuid,
        'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'raw_body'   => $rawBody,
        'received_at'=> date('Y-m-d H:i:s'),
    ]);

    $syncManager = new CallSyncManager();
    $resultInfo = null;
    $processedVia = 'skipped (no cuid)';

    if ($isCdr) {
        $resultInfo = $syncManager->ingestCdrEvent($eventPayloadData);
        $processedVia = 'cdr';
    } elseif ($isRinging) {
        $resultInfo = $syncManager->ingestRingingEvent($eventPayloadData);
        $processedVia = 'ringing';
    } elseif ($isHangup) {
        $resultInfo = $syncManager->ingestHangupEvent($eventPayloadData);
        $processedVia = 'hangup';
    } elseif (!empty($cuid)) {
        // ⚠️ fallback مقاوم: event شناسایی نشد ولی cuid داریم - به‌جای
        // نادیده گرفتن کامل رویداد، از مسیر enrich استفاده می‌کنیم که
        // با یک GET /call مستقل، وضعیت واقعی تماس را می‌گیرد (چه در حال
        // زنگ خوردن باشد چه قطع شده باشد) و آن را ثبت/به‌روزرسانی می‌کند
        // - این مسیر همان جایی است که ساخت خودکار سرنخ هم اتفاق می‌افتد.
        Logger::error('Telefonchy webhook: unrecognized event type, using fallback enrichment', [
            'raw_event' => $rawEventValue, 'data_keys' => array_keys($eventPayloadData), 'cuid' => $cuid,
        ]);
        $resultInfo = $syncManager->ingestHangupEvent($eventPayloadData);
        $processedVia = 'fallback-enrichment (نوع رویداد ناشناخته بود)';
    } else {
        // POSTهای خالی (`[]`) پینگ دوره‌ای تلفنچی هستند - خطا نیستند
        if (empty($payload)) {
            $processedVia = 'skipped (empty ping)';
        } else {
            Logger::info('Telefonchy webhook: no cuid found, cannot process', ['payload_keys' => array_keys($payload)]);
            $processedVia = 'skipped (no cuid)';
        }
    }

    // خلاصه‌ی قابل‌مشاهده در پنل (بدون نیاز به دسترسی سرور): کلیدهای
    // واقعیِ دریافتی + مقدار خام event + نتیجه‌ی پردازش
    $diagnosticPreview = sprintf(
        "event خام: %s | پردازش‌شده به‌عنوان: %s | کلیدهای data: %s | نتیجه: %s",
        $rawEventValue !== null ? json_encode($rawEventValue, JSON_UNESCAPED_UNICODE) : 'وجود نداشت',
        $processedVia,
        implode(', ', array_keys($eventPayloadData)),
        $resultInfo ? json_encode($resultInfo, JSON_UNESCAPED_UNICODE) : '-'
    );

    TelefonchyApiLog::log([
        'direction'        => 'webhook',
        'method'           => $_SERVER['REQUEST_METHOD'] ?? 'POST',
        'endpoint'         => '/webhook.php (' . ($isCdr ? 'cdr' : ($isRinging ? 'ringing' : ($isHangup ? 'hangup' : 'fallback/unknown'))) . ')',
        'request_params'   => ['cuid' => $cuid, 'src' => $eventPayloadData['src'] ?? null, 'dest' => $eventPayloadData['dest'] ?? ($eventPayloadData['dst'] ?? null)],
        'http_code'        => 200,
        'success'          => true,
        'response_preview' => $diagnosticPreview,
        'error_message'    => $tokenMismatch ? '⚠️ احراز هویت وب‌هوک ناموفق (' . $authResult . ') — در حالت monitor پردازش شد' : null,
        'duration_ms'      => (int)round((microtime(true) - $startTime) * 1000),
    ]);

    if ($processedVia !== 'skipped (empty ping)') Logger::info('Telefonchy webhook received', ['raw_event' => $rawEventValue, 'processed_via' => $processedVia, 'cuid' => $cuid]);

} catch (\Throwable $e) {
    Logger::error('Webhook processing failed: ' . $e->getMessage(), [
        'file' => $e->getFile(), 'line' => $e->getLine(), 'raw_body' => substr($rawBody, 0, 500),
    ]);
    if (class_exists('TelefonchyApiLog')) {
        try {
            TelefonchyApiLog::log([
                'direction' => 'webhook', 'method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
                'endpoint' => '/webhook.php (خطای داخلی)', 'success' => false,
                'error_message' => get_class($e) . ': ' . $e->getMessage() . ' (خط ' . $e->getLine() . ')',
            ]);
        } catch (\Throwable $ignore) {}
    }
    // حتی در صورت خطا، 200 OK برگردان تا تلفنچی درخواست را retry نکند
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'message' => 'received']);
exit;
