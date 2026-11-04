<?php
/**
 * chat.php
 * نقطه‌ی عمومی «گفتگوی آنلاین سایت». ویجت روی سایت مشتری با همین فایل حرف می‌زند.
 *
 * ⚠️ این فایل تنها مسیر بدون‌لاگین و بدون CSRF پروژه است (جز r.php). پس:
 *   - هیچ داده‌ی مدیریتی برنمی‌گرداند؛ فقط تنظیمات عمومی و گفتگوی همان نشست.
 *   - دسترسی با توکن ۳۲ هگزِ نشست کنترل می‌شود (قابل حدس نیست).
 *   - سقف پیام برای هر IP در Webchat::ipRateExceeded اعمال می‌شود.
 *   - دامنه‌های مجاز در تنظیمات چت سایت قابل محدود کردن است.
 *
 * کنش‌ها:  ?wc=init | send | poll | ping | callback
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$root    = __DIR__;
$cfgFile = $root . '/config.php';
if (!file_exists($cfgFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'no config'], JSON_UNESCAPED_UNICODE);
    exit;
}
$config = require $cfgFile;

require_once $root . '/core/Database.php';
require_once $root . '/core/Url.php';
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
// Brand لازم است: رنگ/نام/لوگوی ویجت از تنظیمات برند ارث می‌رسد. اگر این خط نباشد
// class_exists('Brand') برابر false می‌شود و ویجت به رنگ پیش‌فرض برمی‌گردد (باگ بی‌صدا).
require_once $root . '/modules/Brand.php';
require_once $root . '/modules/SiteCtx.php';
require_once $root . '/modules/Webchat.php';
require_once $root . '/modules/SiteWidgets.php';
require_once $root . '/core/SchemaV4.php';

Database::init($config['db']);
// ۲.۱.۱: سایتِ این درخواست از کلید سایت (?site=KEY)؛ بدون کلید = سایت اصلی
$siteOk = SiteCtx::fromPublic((string)($_GET['site'] ?? ''));


// آدرس پایه (مثل webhook) تا لینک رهگیریِ داخل پاسخ‌های خودکار/فرم
// همان لینکی باشد که در پنل دیده می‌شود.
if (function_exists('app_base_url') && class_exists('Personalize')) {
    Personalize::setBaseUrl(app_base_url($config));
}

/** پاسخ JSON و پایان */
function wc_out(array $data, int $code = 200): void
{
    if (!headers_sent()) http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$siteOk) wc_out(['ok' => false, 'error' => 'سایت نامعتبر یا غیرفعال است', 'disabled' => true]);

// ---- CORS: ویجت از دامنه‌ی سایت مشتری صدا می‌زند، نه از دامنه‌ی پنل ----
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && Webchat::originAllowed($origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- خواندن ورودی برای کنش‌های سبک (boot / fabclick) ----
$earlyAction = (string)($_GET['wc'] ?? '');
if ($earlyAction === 'boot' || $earlyAction === 'fabclick') {
    if ($origin !== '' && !Webchat::originAllowed($origin)) wc_out(['ok' => false, 'error' => 'این دامنه مجاز نیست'], 403);
    try {
        SchemaV4::ensure();
        if ($earlyAction === 'boot') {
            // تنظیمات دکمه‌های شناور + طراحی گفتگو، بدون ساختن نشست گفتگو (نشست فقط با باز شدن پنجره ساخته می‌شود)
            header('Cache-Control: public, max-age=60');
            wc_out(['ok' => true] + SiteWidgets::publicBoot());
        }
        $j = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
        SiteWidgets::logClick((string)($j['key'] ?? ''), (string)($j['kind'] ?? ''), (string)($j['page'] ?? ''), (string)($j['device'] ?? ''));
        wc_out(['ok' => true]);
    } catch (Throwable $e) {
        wc_out(['ok' => false, 'error' => 'خطای سرور'], 500);
    }
}

// ---- کانال باید روشن باشد ----
if (!Webchat::isEnabled()) {
    wc_out(['ok' => false, 'error' => 'گفتگوی آنلاین سایت فعال نیست', 'disabled' => true]);
}
if ($origin !== '' && !Webchat::originAllowed($origin)) {
    wc_out(['ok' => false, 'error' => 'این دامنه مجاز نیست'], 403);
}

// ---- خواندن ورودی (JSON یا فرم) ----
$in = [];
$raw = file_get_contents('php://input');
if ($raw !== '' && $raw !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $in = $decoded;
}
if (!$in && !empty($_POST)) $in = $_POST;

$action = (string)($_GET['wc'] ?? ($in['wc'] ?? ''));
$token  = (string)($in['token'] ?? ($_GET['token'] ?? ''));

try {
    switch ($action) {

        // ------------------------------------------------ شروع/ادامه‌ی گفتگو
        case 'init':
            $settings = Webchat::publicSettings();

            // اگر توکن قبلی معتبر و موجود بود، همان نشست ادامه پیدا می‌کند
            $resume = Webchat::validToken($token) ? Webchat::session($token) : null;
            if ($resume) {
                Webchat::touch($token, (string)($in['page_url'] ?? ''));
                Webchat::markSeen($token); // باز کردن ویجت یعنی پیام‌های قبلی را می‌بیند
                $hist = Webchat::history($token);
                wc_out([
                    'ok'       => true,
                    'token'    => $token,
                    'resumed'  => true,
                    'settings' => $settings,
                    'messages' => $hist['messages'],
                    'last_id'  => $hist['last_id'],
                ]);
            }

            $new = Webchat::startSession([
                'page_url' => (string)($in['page_url'] ?? ''),
                'referer'  => (string)($in['referer'] ?? ($_SERVER['HTTP_REFERER'] ?? '')),
                'ua'       => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ]);
            wc_out([
                'ok'       => true,
                'token'    => $new,
                'resumed'  => false,
                'settings' => $settings,
                'messages' => [],
                'last_id'  => 0,
            ]);
            break;

        // ---------------------------------------------------- پیام بازدیدکننده
        case 'send':
            // ۱.۰.۸: اگر دستیار فروش جواب می‌دهد، ویجت همین حالا «ارسال شد» می‌گیرد و پاسخ در پس‌زمینه آماده می‌شود
            if (class_exists('SalesAgent')) { SalesAgent::$releaseBody = json_encode(['ok' => true, 'error' => null]); SalesAgent::$releaseType = 'application/json'; }
            $res = Webchat::ingest([
                'token'    => $token,
                'text'     => (string)($in['text'] ?? ''),
                'name'     => (string)($in['name'] ?? ''),
                'phone'    => (string)($in['phone'] ?? ''),
                'page_url' => (string)($in['page_url'] ?? ''),
                'cb'       => (string)($in['cb'] ?? ''),   // ۱.۰.۱۴: دکمه‌ی منوی بات
            ]);
            wc_out(['ok' => (bool)$res['ok'], 'error' => $res['error']]);
            break;

        // ------------------------------- ۱.۰.۱۴: «با من تماس بگیرید» از ویجت
        case 'callback':
            if (Webchat::ipRateExceeded()) wc_out(['ok' => false, 'error' => 'تعداد درخواست‌ها زیاد است؛ کمی بعد تلاش کنید']);
            $res = ChatPlus::widgetCallback($token, (string)($in['name'] ?? ''), (string)($in['phone'] ?? ''), (string)($in['when'] ?? ''));
            // پاسخ همین حالا برود؛ تماس تلفن گویا با پشتیبان‌ها بعد از آن (تا ویجت منتظر نماند)
            echo json_encode(['ok' => (bool)$res['ok'], 'error' => $res['error'] ?? null], JSON_UNESCAPED_UNICODE);
            if (!empty($res['ok'])) {
                if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); ChatPlus::ivrTick(); }
                elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); ChatPlus::ivrTick(); }
            }
            exit;

        // -------------------------------------------- گرفتن پاسخ‌های تازه
        case 'poll':
            $after = (int)($in['after'] ?? ($_GET['after'] ?? 0));
            // مورد ۵: تیک خوانده‌شده — فقط وقتی ویجت واقعاً باز/فعال است، پیام‌های
            // خروجی «دیده‌شده» علامت بخورند (نه هر poll پس‌زمینه‌ای بی‌ربط).
            $active = !empty($in['active']) || !empty($_GET['active']);
            if ($active) { Webchat::markSeen($token); }
            wc_out(Webchat::poll($token, $after));
            break;

        // ------------------------------------ «هنوز روی سایت هستم» (حضور زنده)
        case 'ping':
            Webchat::touch($token, (string)($in['page_url'] ?? ''));
            $active = !empty($in['active']) || !empty($_GET['active']);
            if ($active) { Webchat::markSeen($token); }
            wc_out(['ok' => true, 'online' => Webchat::isOnline()]);
            break;

        default:
            wc_out(['ok' => false, 'error' => 'کنش نامعتبر'], 400);
    }
} catch (Throwable $e) {
    if (!empty($config['debug'])) {
        error_log('[chat.php] ' . $e->getMessage());
    }
    wc_out(['ok' => false, 'error' => 'خطای سرور'], 500);
}
