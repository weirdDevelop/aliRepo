<?php
/**
 * core/Url.php
 * آدرس پایه‌ی واقعی برنامه.
 *
 * چرا این فایل ساخته شد؟
 * قبلاً `app_base_url()` داخل `api/handler.php` بود، پس فقط مسیر API از آن استفاده می‌کرد:
 * پنل یک لینک کوتاهِ **درست** نشان می‌داد، ولی `Personalize::setBaseUrl($config['base_url'])`
 * در index/cron/webhook مقدار خام config را می‌گرفت. اگر نصب‌کننده `base_url` را ویرایش نکرده
 * بود (مقدار نمونه `https://example.com/elanban`)، لینکِ داخل پیامِ ارسالی به مشتری **مرده**
 * بود در حالی که همان لینک در پنل سالم دیده می‌شد — خرابیِ بی‌صدا. حالا هر سه بوت‌استرپ
 * از همین یک تابع استفاده می‌کنند.
 */

/** آدرس پایه (بدون اسلش انتهایی). اولویت: config معتبر → تشخیص از سرور → مقدار ذخیره‌شده. */
function app_base_url(array $config): string
{
    static $cache = null, $ov = null;
    // ۱.۰.۱۳: آدرس پایه از پنل (مثلاً وقتی دامنه به www منتقل می‌شود و وب‌هوک‌ها با ریدایرکت از دست می‌روند)
    if ($ov === null) {
        try {
            if (class_exists('Database', false)) {
                $o = Database::one("SELECT v FROM eb_settings WHERE k = 'app.base_url_override'");
                $o = rtrim(trim((string)($o['v'] ?? '')), '/');
                $ov = ($o !== '' && preg_match('#^https?://#i', $o)) ? $o : '';
            }
        } catch (Throwable $e) {}
    }
    if ($ov) return $ov;
    if ($cache !== null) return $cache;

    $base = rtrim((string)($config['base_url'] ?? ''), '/');
    // config معتبر است اگر پر باشد و مقدار نمونه نباشد
    if ($base !== '' && stripos($base, 'example.com') === false) {
        return $cache = $base;
    }

    // اجرای CLI (cron): هیچ میزبان HTTPی وجود ندارد که تشخیص داده شود.
    // آخرین آدرسی که لایه‌ی وب تشخیص داده و ذخیره کرده را برمی‌داریم.
    if (empty($_SERVER['HTTP_HOST'])) {
        $saved = app_base_url_saved();
        return $cache = ($saved !== '' ? $saved : $base);
    }

    return $cache = app_base_url_detect();
}

/** تشخیص آدرس از متغیرهای سرور (فقط در بستر وب معنی دارد) */
function app_base_url_detect(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

    // مسیر نصب را از **محل خودِ فایل** می‌گیریم، نه از SCRIPT_NAME.
    // دلیل: نقطه‌ی ورود می‌تواند index.php یا webhook/receive.php یا r.php باشد؛
    // با SCRIPT_NAME مسیر اشتباهی مثل «/elanban/webhook» به دست می‌آمد.
    $dir     = '';
    $docRoot = str_replace('\\', '/', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));
    $appDir  = str_replace('\\', '/', dirname(__DIR__));   // ریشه‌ی نصب = والدِ core/

    if ($docRoot !== '' && strpos($appDir, $docRoot) === 0) {
        $dir = rtrim(substr($appDir, strlen($docRoot)), '/');
    } else {
        // پشتیبان: از SCRIPT_NAME، با حذف پوشه‌های فرعیِ شناخته‌شده
        $script = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
        $dir    = rtrim(preg_replace('~/(api|webhook)$~', '', $script), '/');
    }
    if ($dir === '/' || $dir === '.') $dir = '';

    return $scheme . '://' . $host . $dir;
}

/** آدرسی که قبلاً لایه‌ی وب تشخیص داده و ذخیره کرده (برای cron) */
function app_base_url_saved(): string
{
    try {
        $r = Database::one("SELECT v FROM eb_settings WHERE k = 'app.base_url_detected'");
        return $r ? rtrim((string)($r['v'] ?? ''), '/') : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * لایه‌ی وب آدرس تشخیص‌داده‌شده را ذخیره می‌کند تا cron (که HTTP_HOST ندارد) هم بداند.
 * فقط وقتی config تنظیم نشده باشد، و حداکثر یک‌بار در هر نشست (تا هر درخواست یک INSERT نزند).
 */
function app_base_url_remember(array $config): void
{
    $base = rtrim((string)($config['base_url'] ?? ''), '/');
    if ($base !== '' && stripos($base, 'example.com') === false) return;   // config معتبر: نیازی نیست
    if (empty($_SERVER['HTTP_HOST'])) return;                              // CLI: چیزی برای تشخیص نیست

    $now = app_base_url_detect();
    if ($now === '') return;

    $sessionOn = (session_status() === PHP_SESSION_ACTIVE);
    if ($sessionOn && ($_SESSION['_app_bu'] ?? '') === $now) return;       // در این نشست ذخیره شده

    try {
        Database::run(
            "INSERT INTO eb_settings (k, v) VALUES ('app.base_url_detected', ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v)",
            [$now]
        );
        if ($sessionOn) $_SESSION['_app_bu'] = $now;
    } catch (Throwable $e) { /* ذخیره‌نشدن نباید صفحه را بخواباند */ }
}
