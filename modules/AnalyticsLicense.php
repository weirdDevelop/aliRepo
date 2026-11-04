<?php
/**
 * modules/AnalyticsLicense.php
 * قفل فعال‌سازی ماژول آنالیتیکس — «هر مشتری نخواست، بهش نمی‌دیم».
 *
 * مدل انتخاب‌شده (طبق تصمیم پروژه): کلید HMAC محلی، بدون تماس با هیچ سرور
 * مرکزی. تو (خودت) کلید را برای هر نصب/دامنه با اسکریپت CLI صادر می‌کنی
 * (`cli/analytics_gen_license.php`) و مشتری همان یک کلید را در تنظیمات
 * ماژول وارد می‌کند.
 *
 * صداقت لازم: این ضدضربه‌ی صددرصد نیست — کد سمت سرور همیشه قابل خواندن است،
 * و کسی با دسترسی به فایل می‌تواند این چک را دور بزند. کاری که واقعاً می‌کند:
 * جلوی «کپی‌کردن ناخواسته‌ی زیپ به یک نصب دیگر و روشن‌شدن خودکار ماژول» را
 * می‌گیرد. اگر بعداً DRM سخت‌گیرانه‌تر (اعتبارسنجی آنلاین) خواستی، آن یک
 * تصمیم جدا و بزرگ‌تر است.
 */

class AnalyticsLicense
{
    /**
     * secret سراسری امضا. این مقدار باید فقط پیش خودت (سازنده‌ی نصب‌ها) باشد،
     * نه در اختیار مشتری. در صورت لو رفتن این فایل برای مشتری، عوضش کن و
     * کلیدهای صادرشده را دوباره بساز.
     *
     * ⚠️ این‌جا یک مقدار پیش‌فرض قرار داده شده تا نصب بدون تنظیم اضافه کار
     * کند؛ در استفاده‌ی واقعی حتماً آن را در config.php با کلید
     * 'analytics_license_secret' override کن (پایین‌تر خوانده می‌شود) تا هر
     * نصب secret خودش را داشته باشد.
     */
    private static function secret(): string
    {
        global $config;
        if (is_array($config ?? null) && !empty($config['analytics_license_secret'])) {
            return (string)$config['analytics_license_secret'];
        }
        // fallback — فقط برای این‌که نصب‌های بدون تنظیم دستی از کار نیفتند.
        // در PRODUCTION حتماً analytics_license_secret را در config.php ست کن.
        return 'elanban-analytics-default-secret-change-me';
    }

    /**
     * صدور کلید برای یک دامنه (از CLI صدا زده می‌شود، نه از پنل).
     * فرمت خروجی: base64url(hmac) — کوتاه و قابل کپی در یک اینپوت.
     */
    public static function issue(string $domain): string
    {
        $domain = self::normalizeDomain($domain);
        $sig = hash_hmac('sha256', $domain, self::secret());
        return rtrim(strtr(base64_encode(hex2bin($sig)), '+/', '-_'), '=');
    }

    /** آیا کلیدِ واردشده برای دامنه‌ی فعلی معتبر است؟ */
    public static function isValid(string $licenseKey, string $domain): bool
    {
        $licenseKey = trim($licenseKey);
        if ($licenseKey === '') return false;
        $expected = self::issue($domain);
        // مقایسه‌ی زمان‌ثابت — جلوگیری از timing attack روی چک رشته‌ای
        return hash_equals($expected, $licenseKey);
    }

    /**
     * دامنه‌ی فعلی نصب. اولویت با `base_url` تنظیم‌شده در config.php (همان
     * چیزی که همه‌جای دیگر برنامه به‌عنوان آدرس واقعی می‌شناسد)، بعد آدرسِ
     * تشخیص‌داده‌شده‌ی ذخیره‌شده (برای CLI/cron که HTTP_HOST ندارند)، در آخر
     * HTTP_HOST خام. اگر این‌جا فقط سراغ تشخیصِ خودکار می‌رفتیم، در نصب‌های
     * پشتِ پروکسی/پورت غیر ۸۰/۴۴۳ دامنه با چیزی که پنل جای دیگر نشان می‌دهد
     * فرق می‌کرد و لایسنس همیشه نامعتبر می‌شد.
     */
    public static function currentDomain(): string
    {
        global $config;
        if (is_array($config ?? null) && function_exists('app_base_url')) {
            $base = app_base_url($config);
            if ($base !== '') return self::normalizeDomain($base);
        }
        if (function_exists('app_base_url_saved')) {
            $saved = app_base_url_saved();
            if ($saved !== '') return self::normalizeDomain($saved);
        }
        return self::normalizeDomain($_SERVER['HTTP_HOST'] ?? '');
    }

    private static function normalizeDomain(string $d): string
    {
        $d = strtolower(trim($d));
        $d = preg_replace('#^https?://#', '', $d);
        $d = rtrim($d, '/');
        $d = preg_replace('#:\d+$#', '', $d); // بدون پورت
        $d = preg_replace('#^www\.#', '', $d);
        return $d;
    }
}
