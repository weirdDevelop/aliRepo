<?php
/**
 * webhook/receive.php
 * نقطه‌ی دریافت یکپارچه‌ی رویداد همه‌ی کانال‌ها.
 *
 * آدرس هر کانال:
 *   https://example.com/elanban/webhook/receive.php?channel=bale&secret=...
 *   https://example.com/elanban/webhook/receive.php?channel=telegram&secret=...
 *   https://example.com/elanban/webhook/receive.php?channel=roubika&secret=...
 *
 * پارامتر secret یک رمز ساده برای جلوگیری از فراخوانی جعلی است
 * (از eb_settings کلید webhook.secret خوانده می‌شود).
 */

$root = dirname(__DIR__);
$cfgFile = $root . '/config.php';
if (!file_exists($cfgFile)) { http_response_code(500); exit('no config'); }
$config = require $cfgFile;

require_once $root . '/core/Database.php';
require_once $root . '/core/Url.php';
require_once $root . '/modules/NetProxy.php';
require_once $root . '/modules/InstagramAuth.php';
require_once $root . '/channels/ChannelManager.php';
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
require_once $root . '/modules/PropertyLookup.php';
require_once $root . '/modules/Forms.php';
require_once $root . '/modules/Inbox.php';
require_once $root . '/modules/Notifier.php';

Database::init($config['db']);

InstagramDriver::setBaseUrl(app_base_url($config));

// آدرس پایه از همان منطق پنل (core/Url.php) — تا لینک رهگیریِ داخل پاسخ‌های
// خودکار/فرم همان لینکی باشد که در پنل دیده می‌شود.
Personalize::setBaseUrl(app_base_url($config));

// ---- اعتبارسنجی کانال و secret ----
$channel = $_GET['channel'] ?? '';
if (!in_array($channel, ChannelManager::available(), true)) {
    http_response_code(400); exit('bad channel');
}

// گفتگوی آنلاین سایت وب‌هوک ندارد؛ نقطه‌ی ورودش `chat.php` است (با نشست و سقف IP).
// اگر اینجا هم پذیرفته می‌شد، می‌شد با یک POST ساده هویت جعلی با channel_uid دلخواه ساخت.
if ($channel === 'webchat') {
    http_response_code(400); exit('webchat has no webhook');
}

// ---- اینستاگرام: پروتکل وب‌هوک متا با بقیه‌ی کانال‌ها فرق دارد ----
// تأیید (GET) با hub.mode/hub.verify_token/hub.challenge، و دریافت (POST) با
// امضای X-Hub-Signature-256 (HMAC با app secret) — نه پارامتر ساده‌ی ?secret=،
// چون متا اجازه‌ی سفارشی‌کردن پارامترهای Callback URL را نمی‌دهد.
if ($channel === 'instagram' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $mode      = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
    $expectedToken = InstagramAuth::get('verify_token');
    if ($mode === 'subscribe' && $expectedToken !== '' && hash_equals($expectedToken, (string)$token)) {
        header('Content-Type: text/plain');
        echo (string)$challenge;
        exit;
    }
    http_response_code(403); exit('Forbidden');
}

$rawBody = file_get_contents('php://input');

if ($channel === 'instagram') {
    $appSecret = InstagramAuth::get('app_secret');
    $sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    $expectedSig = $appSecret !== '' ? 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret) : '';
    if ($appSecret === '' || $sigHeader === '' || !hash_equals($expectedSig, $sigHeader)) {
        http_response_code(403); exit('forbidden');
    }
} else {
    $secretRow = Database::one("SELECT v FROM eb_settings WHERE k = 'webhook.secret'");
    $expected  = $secretRow['v'] ?? null;
    if ($expected && ($_GET['secret'] ?? '') !== $expected) {
        http_response_code(403); exit('forbidden');
    }
}

$driver = ChannelManager::get($channel);
if (!$driver) {
    http_response_code(200); exit('channel not configured'); // 200 تا پلتفرم retry نکند
}

// ---- خواندن payload ----
$raw = $rawBody;
$payload = json_decode($raw, true);

// لاگ ورودی خام (برای اشکال‌یابی بات‌ساز و وب‌هوک)
if (class_exists('ApiLog')) {
    ApiLog::record($channel, 'webhook_in', ['raw' => mb_substr($raw, 0, 2000)], $payload, null, is_array($payload), null);
}

if (!is_array($payload)) {
    http_response_code(200); exit('OK'); // payload نامعتبر را بی‌صدا رد کن
}

// ---- جلوگیری از پردازش دوباره‌ی یک رویداد (مورد ۶، ریشه‌ی دوم) ----
// بله/تلگرام اگر وب‌هوک دیر ۲۰۰ برگرداند همان update را دوباره می‌فرستند. چون این فایل
// کار سنگین می‌کند (ثبت، فرم، منو، پاسخ خودکار)، retry باعث می‌شد پاسخ خودکار دو بار برود.
// `update_id` در خانواده‌ی تلگرام یکتاست؛ با UNIQUE(channel,event_key) دومی رد می‌شود.
//
// طراحی fail-open (مهم): این بلوک فقط وقتی رویداد را دور می‌ریزد که **مطمئن** باشد
// تکراری است (خطای کلید یکتا، SQLSTATE 23000). اگر جدول نبود، اگر update_id نداشتیم،
// یا هر خطای دیگری رخ داد، پردازش عادی ادامه می‌یابد — چون گم‌کردن پیام مشتری
// بدتر از یک پاسخ تکراری است.
$eventKey = null;
if (isset($payload['update_id']) && ctype_digit((string)$payload['update_id'])) {
    $eventKey = (string)$payload['update_id'];
}
if ($eventKey !== null) {
    $dedupReady = false;
    try {
        $dedupReady = (bool)Database::one("SHOW TABLES LIKE 'eb_webhook_events'");
    } catch (Throwable $e) { $dedupReady = false; }

    if ($dedupReady) {
        try {
            Database::run(
                "INSERT INTO eb_webhook_events (channel, event_key) VALUES (?, ?)",
                [$channel, $eventKey]
            );
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {   // کلید تکراری = همین رویداد قبلاً آمده
                http_response_code(200);
                exit('OK duplicate');
            }
            // هر خطای دیگر: نادیده بگیر و پردازش را ادامه بده (fail-open)
        } catch (Throwable $e) { /* fail-open */ }
    }
}

// ---- نرمال‌سازی رویداد(های) توسط درایور ----
// معمولاً هر وب‌هوک دقیقاً یک رویداد دارد (بله/تلگرام/روبیکا)؛ متا می‌تواند چند
// entry/messaging را در یک POST دسته کند، پس برای اینستاگرام از allEvents استفاده می‌شود.
if ($channel === 'instagram' && method_exists($driver, 'allEvents')) {
    $events = $driver->allEvents($payload);
} else {
    $one = $driver->handleInbound($payload);
    $events = $one ? [$one] : [];
}
if (!$events) {
    http_response_code(200); exit('OK');
}

foreach ($events as $event) {
// ---- ثبت در بانک مشتری (با merge خودکار اگر شماره بیاید) ----
try {
    $personId = Persons::ingestIdentity($channel, $event);

    // ---- مورد ۴: مشتری بلاک‌شده — هیچ‌چیز از این پیام در دیتابیس ذخیره نشود ----
    // قبلاً پیام ثبت می‌شد و فقط پردازش خودکار (فرم/منو/AI) رد می‌شد؛ برای
    // فرستنده‌ی مزاحم/اسپم‌کنِ بلاک‌شده این یعنی جدول eb_messages مدام پر می‌شود
    // بدون این‌که کسی هرگز آن پیام‌ها را ببیند. حالا کلاً رد می‌شوند.
    if ($personId && class_exists('Inbox') && Inbox::isBlocked($personId)) {
        continue; // فقط همین رویداد را رد کن؛ بقیه‌ی رویدادهای دسته (اگر بود) پردازش شوند
    }

    // وضعیت رویداد (مستقل از وجود identity تا در کل این scope در دسترس باشد)
    $isCallback = (($event['type'] ?? '') === 'callback');
    $isContact  = (($event['type'] ?? '') === 'contact') && !empty($event['phone']);

    // ثبت پیام دریافتی (inbound) برای تاریخچه
    $identity = Database::one(
        "SELECT id FROM eb_identities WHERE channel = ? AND channel_uid = ?",
        [$channel, $event['channel_uid']]
    );
    if ($identity) {
        $hasText  = !empty($event['text']);
        $hasMedia = !empty($event['media_type']);

        // ---- باگ ۱: رویداد «اشتراک شماره» (contact) ----
        // پیام contact فقط شماره دارد (نه text نه media)، پس با شرط عمومیِ پایین ثبت نمی‌شد
        // و در صندوق ورودی/تاریخچه دیده نمی‌شد. اینجا یک پیام «in» با متن خوانا ثبت می‌کنیم.
        // برای همه‌ی کانال‌های رباتی (بله/تلگرام/روبیکا) کار می‌کند؛ merge جدا در ingestIdentity انجام شده.
        if ($isContact && !$isCallback) {
            $phoneShown = Persons::normalizePhone($event['phone']) ?: $event['phone'];
            // فرمت نمایشی +98… (اگر نرمال‌سازی به 98… داد، + را جلویش بگذار)
            if (preg_match('/^98\d{10}$/', (string)$phoneShown)) {
                $phoneShown = '+' . $phoneShown;
            }
            $contactBody = '📱 شماره به اشتراک گذاشته شد: ' . $phoneShown;
            // اگر همراهِ contact متنی هم آمده باشد (نادر)، آن را پایین‌تر با شرط عمومی ثبت نکن تا دوباره نشود؛
            // پس متن را هم به همین پیام ضمیمه می‌کنیم و $hasText را خاموش می‌کنیم.
            if ($hasText) {
                $contactBody .= "\n" . $event['text'];
                $hasText = false;
            }
            Database::run(
                "INSERT INTO eb_messages (identity_id, channel, direction, body, status)
                 VALUES (?, ?, 'in', ?, 'received')",
                [$identity['id'], $channel, $contactBody]
            );
        }

        // ثبت کن اگر متن یا رسانه‌ای هست (پیام فقط-عکس هم باید ثبت شود)؛ کلیک دکمه را ثبت نکن
        if (($hasText || $hasMedia) && !$isCallback) {
            try {
                Database::run(
                    "INSERT INTO eb_messages (identity_id, channel, direction, body, status, media_type, media_path)
                     VALUES (?, ?, 'in', ?, 'received', ?, ?)",
                    [$identity['id'], $channel, $event['text'] ?? '', $event['media_type'] ?? null, $event['media_id'] ?? null]
                );
            } catch (Throwable $e) {
                // اگر ستون‌های رسانه نبودند (migration اجرا نشده)، حداقل متن را ثبت کن
                if ($hasText) {
                    Database::run(
                        "INSERT INTO eb_messages (identity_id, channel, direction, body, status)
                         VALUES (?, ?, 'in', ?, 'received')",
                        [$identity['id'], $channel, $event['text']]
                    );
                }
            }

            // مورد ۲: نوتیف به پشتیبان — پیام تازه از مشتری رسید
            if (class_exists('Notifier')) {
                $who = null;
                if ($personId) {
                    try { $who = Database::one("SELECT full_name FROM eb_persons WHERE id=?", [$personId])['full_name'] ?? null; }
                    catch (Throwable $e) { /* بی‌اثر */ }
                }
                $chLabel = ['bale'=>'بله','telegram'=>'تلگرام','roubika'=>'روبیکا','instagram'=>'اینستاگرام','sms'=>'پیامک'][$channel] ?? $channel;
                Notifier::push(
                    'message',
                    'پیام تازه از ' . ($who ?: 'مخاطب ناشناس') . " ({$chLabel})",
                    $hasText ? mb_substr((string)$event['text'], 0, 200) : '🖼 رسانه',
                    $personId ? "index.php?page=inbox&person={$personId}" : "index.php?page=inbox",
                    $personId
                );
            }
        }
    }

    // ---- لوکاپ خودکار اقامتگاه (بالاترین اولویت مطلق — قبل حتی از فرم) ----
    // اگر کاربر منتظر تحویل «اسم اقامتگاه» است (بعد از زدن دکمه‌ی قیمت/ساعت/...)، همین‌جا
    // مصرف می‌شود؛ وگرنه نه فرم نه بات‌ساز اصلاً معنایی از این متن نمی‌فهمند.
    // ---- پاسخ نظرسنجی رضایت (دکمه‌ی ۱ تا ۵ یا عدد) ----
    if (class_exists('Csat') && ($isCallback || trim((string)($event['text'] ?? '')) !== '')) {
        try { if (Csat::onMessage($channel, (string)$event['channel_uid'], $event['text'] ?? null, $event['callback_data'] ?? null)) continue; } catch (Throwable $e) {}
    }

    $propertyLookupHandled = false;
    if (($event['type'] ?? '') !== 'callback' && trim((string)($event['text'] ?? '')) !== '') {
        $propertyLookupHandled = PropertyLookup::handleIncoming($channel, $event['channel_uid'], (string)$event['text']);
    }

    // ---- فرم/نظرسنجی (بالاترین اولویت) ----
    // اگر کاربر وسط یک فرم است، پیامش جواب سؤال است؛ یا با کلمه‌ی کلیدی فرم را شروع می‌کند.
    $text = $event['text'] ?? '';
    $callbackData = $event['callback_data'] ?? null;
    $formHandled = $propertyLookupHandled;
    if (!$formHandled && ($event['type'] ?? '') !== 'callback') {
        $formHandled = Forms::handle($channel, $event['channel_uid'], $text, $personId);
    }

    // ---- بات‌ساز (منوی چندمرحله‌ای) ----
    $menuHandled = false;
    if (!$formHandled) {
        $menuHandled = BotMenu::handle($channel, $event['channel_uid'], $text, $callbackData);
    }

    // ---- پاسخ خودکار ----
    $autoReplied = false;
    if (!$formHandled && !$menuHandled && ($event['type'] ?? '') !== 'callback') {
        if ($isContact) {
            // پاسخ خودکارِ رویدادیِ «اشتراک شماره» (قواعد نوع on_contact).
            // فقط وقتی اجرا می‌شود که فرم/منوی فعالی رویداد را نگرفته باشد (تابع اولویت webhook).
            AutoReply::handleContact($channel, $event['channel_uid']);
            $autoReplied = true;
        } elseif (!empty($event['text']) && $text !== '/start') {
            // پاسخ خودکار متنی (exact/contains/starts_with/regex)
            $autoReplied = AutoReply::handle($channel, $event['channel_uid'], $event['text']);
        }
    }

    // ---- هوش مصنوعی (مورد ۱۲): آخرین حلقه‌ی زنجیره ----
    // فقط وقتی هیچ‌کدام از موتورهای قبلی (فرم/منو/پاسخ خودکار) جواب نداده باشند،
    // و فقط اگر تیم پشتیبانی آفلاین باشد یا گفتگو دستی به AI ارجاع شده باشد.
    // در ساعت کاری عمداً هیچ چیزی خودکار فرستاده نمی‌شود — اپراتور پیش‌نویس می‌گیرد.
    if (!$formHandled && !$menuHandled && !$autoReplied
        && ($event['type'] ?? '') !== 'callback' && !$isContact
        && !empty($event['text']) && $text !== '/start'
        && class_exists('Ai')) {
        Ai::autoReply($channel, $event['channel_uid'], $personId, $event['text']);
    }
} catch (Throwable $e) {
    // در محیط production لاگ می‌کنیم ولی همیشه 200 می‌دهیم تا پلتفرم retry نکند
    if (!empty($config['debug'])) {
        error_log('[webhook] ' . $e->getMessage());
    }
}
} // پایان foreach ($events as $event)

if (!headers_sent()) http_response_code(200);   // دستیار فروش ممکن است پاسخ را زودتر فرستاده باشد
echo 'OK';
