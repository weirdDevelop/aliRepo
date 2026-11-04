<?php
/**
 * webhook/directsaz.php
 * نقطه‌ی دریافت مستقل رویدادهای اینستاگرام از طریق دایرکت‌ساز.
 *
 * چرا فایل جداست و داخل webhook/receive.php نرفته: طبق همان اصلی که برای
 * chat.php/eb-feed.php هم رعایت شده — «هرکدام مستقل، تا یک باگ در یکی بقیه را
 * نخواباند». پروتکل دایرکت‌ساز هم واقعاً با بقیه‌ی کانال‌ها فرق دارد:
 *   - تأیید (GET): بدون هدر X-Webhook-Token → 401. با هدر درست → 200. (بدون
 *     hub.challenge؛ برخلاف متای مستقیم)
 *   - رویداد (POST): امضا در هدر X-Directsaz-Signature: t=…,v1=…
 *     (HMAC-SHA256 با webhook_token، نه app secret)
 *   - باید ظرف ۵ ثانیه 2xx برگرداند وگرنه دایرکت‌ساز تحویل را خاموش می‌کند.
 *
 * آدرس: https://example.com/elanban/webhook/directsaz.php
 * (این آدرس دقیقاً همان چیزی‌ست که باید در پنل دایرکت‌ساز، بخش «آدرس وب‌هوک»
 * برای این پیج ثبت شود — و توکن وب‌هوک تولیدشده در تنظیمات کانال اینستاگرام را
 * در «توکن وب‌هوک» همان‌جا وارد کنی.)
 */

$root = dirname(__DIR__);
$cfgFile = $root . '/config.php';
if (!file_exists($cfgFile)) { http_response_code(500); exit('no config'); }
$config = require $cfgFile;

require_once $root . '/core/Database.php';
require_once $root . '/core/Url.php';
require_once $root . '/modules/InstagramDirectsaz.php';
require_once $root . '/channels/ChannelInterface.php';
require_once $root . '/channels/InstagramDirectsazDriver.php';
require_once $root . '/modules/Persons.php';
require_once $root . '/modules/Personalize.php';
require_once $root . '/modules/ApiLog.php';
require_once $root . '/modules/Templates.php';
require_once $root . '/modules/BotReply.php';
require_once $root . '/modules/Support.php';
require_once $root . '/modules/AiKb.php';
require_once $root . '/modules/Ai.php';
require_once $root . '/modules/AutoReply.php';
require_once $root . '/modules/BotMenu.php';
require_once $root . '/modules/Forms.php';
require_once $root . '/modules/Inbox.php';
require_once $root . '/modules/Notifier.php';
require_once $root . '/modules/IgCommentAutomation.php';
require_once $root . '/modules/IgPhoneGate.php';

Database::init($config['db']);

InstagramDirectsazDriver::setBaseUrl(app_base_url($config));
Personalize::setBaseUrl(app_base_url($config));

$webhookToken = InstagramDirectsaz::get('webhook_token');

// ---- تأیید آدرس (GET) — دقیقاً طبق بخش ۳ مستندات: 401 بدون توکن، 200 با توکن درست ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $sentToken = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '';
    if ($webhookToken !== '' && hash_equals($webhookToken, (string)$sentToken)) {
        http_response_code(200); exit('ok');
    }
    http_response_code(401); exit('unauthorized');
}

// ---- دریافت رویداد (POST) ----
$rawBody = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_X_DIRECTSAZ_SIGNATURE'] ?? '';

if ($webhookToken === '' || !InstagramDirectsazDriver::verifySignature($rawBody, $sigHeader, $webhookToken)) {
    http_response_code(403); exit('forbidden');
}

if (!InstagramDirectsaz::isConnected()) {
    http_response_code(200); exit('not configured'); // ۲۰۰ تا دایرکت‌ساز retry نکند
}

$driver = new InstagramDirectsazDriver(InstagramDirectsaz::get('api_key'), InstagramDirectsaz::get('base_api_url') ?: 'https://developers.directsaz.com/api/v1');

$payload = json_decode($rawBody, true);

// لاگ ورودی خام (dm و comment و هر نوع دیگری که بعداً اضافه شود، همه لاگ می‌شوند —
// این لاگ همان جایی‌ست که اگر دایرکت‌ساز روزی نوع رویداد جدیدی اضافه کرد، شکلش دیده می‌شود)
// نشانه‌ی نسخه (code_version) عمداً همین‌جا، تو همون لاگی که همیشه مطمئنیم ثبت می‌شود، گذاشته
// شده — تا اگر باز هم بعد از این هیچ لاگ دیگری نیامد، همین یک خط بگوید دقیقاً کدام نسخه از
// فایل روی سرور در حال اجراست، بدون نیاز به رسیدن به خط‌های بعدی.
if (class_exists('ApiLog')) {
    ApiLog::record('instagram_directsaz', 'webhook_in', ['raw' => mb_substr($rawBody, 0, 2000), 'code_version' => 'v4-2026-09-02-full'], $payload, null, is_array($payload), null);
}

if (!is_array($payload)) {
    http_response_code(200); exit('OK');
}

$events = $driver->allEvents($payload);
if (!$events) {
    http_response_code(200); exit('OK'); // یا رویدادی نبود، یا نوعی بود که هنوز شناخته‌شده نیست
}

$channel = 'instagram';

foreach ($events as $event) {
try {
    $kind = $event['event_kind'] ?? 'dm';

    // ---- جلوگیری از پردازش دوباره (retry دایرکت‌ساز)؛ کلید dm=message id, comment=خودِ comment id ----
    $eventKey = $kind === 'comment' ? ($event['comment_id'] ?? null) : ($event['directsaz_message_id'] ?? null);
    if ($eventKey) {
        $dedupReady = false;
        try { $dedupReady = (bool)Database::one("SHOW TABLES LIKE 'eb_webhook_events'"); } catch (Throwable $e) {}
        if ($dedupReady) {
            try {
                Database::run("INSERT INTO eb_webhook_events (channel, event_key) VALUES ('instagram_directsaz', ?)", [$kind . ':' . $eventKey]);
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') { continue; } // تکراری — همین رویداد را رد کن، بقیه‌ی batch ادامه یابد
                // خطای دیگر: fail-open، پردازش ادامه یابد
            } catch (Throwable $e) { /* fail-open */ }
        }
    }

    // هم dm و هم comment از یک فضای شناسه (sender.id) استفاده می‌کنند، پس هردو از
    // ingestIdentity رد می‌شوند — این باعث می‌شود اگر یک نفر هم کامنت گذاشته هم دایرکت
    // داده، هر دو زیر یک شخص/هویت واحد بمانند، و یوزرنیمِ کامنت (که dm نمی‌دهد) نام را پر کند.
    $personId = Persons::ingestIdentity($channel, $event);

    if ($personId && class_exists('Inbox') && Inbox::isBlocked($personId)) {
        continue;
    }

    if ($kind === 'comment') {
        // اول قوانین خودکار را چک کن (کامنت کن X تا برات بفرستم، با/بدون فالو اجباری).
        // اگر قاعده‌ای اجرا شد، همان لحظه ریپلای/دایرکت رفته؛ فقط برای رکورد و امکان پاسخ
        // دستیِ اضافه، در eb_ig_comments هم ذخیره می‌کنیم (replied_type='auto' اگر اجرا شد).
        $autoHandled = false;
        try {
            // نشانه‌ی تشخیصی صریح: صرف‌نظر از نتیجه، همیشه ثبت می‌شود تا مطمئن شویم این
            // بخش از کد واقعاً اجرا می‌شود (نه یک نسخه‌ی قدیمی/کش‌شده روی سرور).
            $ruleCountForLog = 0;
            try { $ruleCountForLog = (int)(Database::one("SELECT COUNT(*) c FROM eb_ig_comment_rules WHERE is_active = 1")['c'] ?? 0); } catch (Throwable $e) {}
            $autoHandled = IgCommentAutomation::matchAndRun($driver, $event);
            if (class_exists('ApiLog')) {
                ApiLog::record(
                    'instagram_directsaz', 'automation_check',
                    ['comment_id' => $event['comment_id'] ?? null, 'active_rules_found' => $ruleCountForLog],
                    ['matched_and_ran' => $autoHandled], null, true, null
                );
            }
        } catch (Throwable $e) {
            // این خطا قبلاً فقط در error_log سرور ثبت می‌شد که کاربر بهش دسترسی نداشت؛
            // الان در همان تب «لاگ خام» که خودِ کاربر می‌بیند هم ثبت می‌شود.
            if (class_exists('ApiLog')) {
                ApiLog::record('instagram_directsaz', 'automation_error', ['comment_id' => $event['comment_id'] ?? null], $e->getMessage(), null, false, $e->getMessage());
            }
            if (!empty($config['debug'])) error_log('[webhook/directsaz][automation] ' . $e->getMessage());
        }

        // کامنت‌ها عمداً وارد نخِ دایرکت (eb_messages/صندوق‌ورودی) نمی‌شوند — پاسخ عمومی/خصوصی
        // به کامنت اکشن جدایی از دایرکت است و مدیریتش در صفحه‌ی «اینستاگرام» انجام می‌شود.
        try {
            Database::run(
                "INSERT INTO eb_ig_comments (comment_id, media_id, sender_id, sender_username, body, person_id, replied_at, replied_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE body = VALUES(body), sender_username = VALUES(sender_username), person_id = VALUES(person_id)",
                [
                    $event['comment_id'], $event['media_id'] ?? null, $event['channel_uid'], $event['username'] ?? null,
                    $event['text'] ?? '', $personId,
                    $autoHandled ? date('Y-m-d H:i:s') : null, $autoHandled ? 'auto' : null,
                ]
            );
        } catch (Throwable $e) {
            if (class_exists('ApiLog')) {
                ApiLog::record('instagram_directsaz', 'comments_table_error', ['comment_id' => $event['comment_id'] ?? null], $e->getMessage(), null, false, $e->getMessage());
            }
        }

        if (class_exists('Notifier')) {
            $who = $event['username'] ? ('@' . $event['username']) : 'یک کاربر';
            Notifier::push(
                'message',
                $who . ' زیر یک پست اینستاگرام کامنت گذاشت' . ($autoHandled ? ' (خودکار پاسخ داده شد)' : ''),
                mb_substr((string)($event['text'] ?? ''), 0, 200),
                'index.php?page=instagram'
            );
        }
        continue; // کامنت پایپ‌لاین فرم/منو/پاسخ‌خودکار/AI را رد نمی‌کند (آن‌ها برای گفتگوی دایرکت طراحی شده‌اند)
    }

    // ---- از اینجا به بعد فقط dm ----

    // اول چک کن این کاربر منتظر تأیید فالو (بخش اتوماسیون کامنت) نیست — اگر بود و همین
    // پیام تأییدش بود، همین‌جا مصرف می‌شود و به فرم/منو/AI نمی‌رسد.
    $gateHandled = false;
    try {
        $gateHandled = IgCommentAutomation::handleDmForGate($driver, $event['channel_uid'], (string)($event['text'] ?? ''));
    } catch (Throwable $e) {
        if (!empty($config['debug'])) error_log('[webhook/directsaz][gate] ' . $e->getMessage());
    }

    $identity = Database::one(
        "SELECT id FROM eb_identities WHERE channel = ? AND channel_uid = ?",
        [$channel, $event['channel_uid']]
    );
    if ($identity) {
        $hasText = !empty($event['text']);
        if ($hasText) {
            Database::run(
                "INSERT INTO eb_messages (identity_id, channel, direction, body, status)
                 VALUES (?, ?, 'in', ?, 'received')",
                [$identity['id'], $channel, $event['text']]
            );

            if (class_exists('Notifier')) {
                $who = null;
                if ($personId) {
                    try { $who = Database::one("SELECT full_name FROM eb_persons WHERE id=?", [$personId])['full_name'] ?? null; }
                    catch (Throwable $e) {}
                }
                Notifier::push(
                    'message',
                    'پیام تازه از ' . ($who ?: 'مخاطب ناشناس') . ' (اینستاگرام)',
                    mb_substr((string)$event['text'], 0, 200),
                    $personId ? "index.php?page=inbox&person={$personId}" : "index.php?page=inbox",
                    $personId
                );
            }
        }
    }

    // ---- گیت اجباری شماره تلفن (مثل چت آنلاین سایت با mode=require_phone) ----
    // قبل از فرم/منو/AI چک می‌شود؛ تا وقتی شخص شماره نداده، هر پیامی یا با یادآوریِ
    // درخواست شماره جواب می‌گیرد یا (اگر خودِ شماره بود) با تشکر و ثبت/ادغام پروفایل.
    $phoneGateHandled = false;
    if (!$gateHandled && $personId) {
        try {
            $phoneGateHandled = IgPhoneGate::handle($driver, $event['channel_uid'], $personId, (string)($event['text'] ?? ''));
        } catch (Throwable $e) {
            if (class_exists('ApiLog')) {
                ApiLog::record('instagram_directsaz', 'phone_gate_error', ['person_id' => $personId], $e->getMessage(), null, false, $e->getMessage());
            }
        }
    }

    // ---- همان زنجیره‌ی اولویت webhook/receive.php: فرم → منو → پاسخ خودکار → AI ----
    // (اگر gate اتوماسیون کامنت یا گیت شماره تلفن همین پیام را مصرف کردند، این زنجیره اصلاً اجرا نمی‌شود)
    $text = $event['text'] ?? '';
    $formHandled = $gateHandled || $phoneGateHandled || Forms::handle($channel, $event['channel_uid'], $text, $personId);

    $menuHandled = false;
    if (!$formHandled) {
        $menuHandled = BotMenu::handle($channel, $event['channel_uid'], $text, null);
    }

    $autoReplied = false;
    if (!$formHandled && !$menuHandled && $text !== '' && $text !== '/start') {
        $autoReplied = AutoReply::handle($channel, $event['channel_uid'], $text);
    }

    if (!$formHandled && !$menuHandled && !$autoReplied && $text !== '' && $text !== '/start' && class_exists('Ai')) {
        Ai::autoReply($channel, $event['channel_uid'], $personId, $text);
    }
} catch (Throwable $e) {
    if (!empty($config['debug'])) {
        error_log('[webhook/directsaz] ' . $e->getMessage());
    }
}
} // پایان foreach

if (!headers_sent()) http_response_code(200);   // دستیار فروش ممکن است پاسخ را زودتر فرستاده باشد
echo 'OK';
