<?php
/**
 * modules/AnalyticsSettings.php
 * تنظیمات ماژول آنالیتیکس در eb_settings با پیشوند `analytics.` — دقیقاً
 * هم‌الگو با NetProxy.php (تا کسی که یکی را بلد است، این را هم بلد باشد).
 *
 * دو لایه‌ی روشن/خاموش:
 *   ۱) enabled=0 یعنی نه صفحه‌ی ادمین داده نشان می‌دهد نه ردیاب چیزی تحویل می‌گیرد
 *   ۲) per-feature: heatmap و funnel جدا از خودِ «فعال‌بودن پایه» روشن/خاموش می‌شوند
 */

class AnalyticsSettings
{
    const DEFAULTS = [
        'enabled'            => '0',
        'site_key'           => '',   // تولید خودکار در فعال‌سازی اول — eb-pulse.js با همین خودش را معرفی می‌کند
        'site_url'           => '',   // آدرس پایه‌ی سایتِ ردیابی‌شده (مثلاً https://example.com) — برای
                                        // ساختن لینک واقعی صفحات پربازدید لازم است؛ چون پنل ادمین معمولاً
                                        // روی دامنه‌ی دیگری میزبانی می‌شود، location.origin در جاوااسکریپت
                                        // اشتباه است (مورد ۲ درخواست کاربر).
        'sampling_heatmap'   => '20', // درصد نشست‌هایی که کلیک/اسکرول برایشان جمع می‌شود
        'sampling_move'      => '5',  // درصد نشست‌ها برای حرکت ماوس (سنگین‌تر، پیش‌فرض کمتر)
        'retention_days_raw' => '90', // نگه‌داری eb_events خام (رول‌آپ روزانه برای همیشه می‌ماند)
        'retention_days_sessions' => '180', // مورد ۹: نگه‌داری eb_sessions/eb_pageviews خام (بعدش فقط رول‌آپ روزانه می‌ماند، برای همیشه)
        'cookieless'         => '0',  // ۱ یعنی شناسه‌ی روزانه‌ی چرخشی به‌جای کوکی/localStorage دائمی
        'geoip_enabled'      => '1',
    ];

    private static $cache = null;
    private static $cachePf = '';

    /** ۲.۱.۱: تنظیمات هر سایت جدا (سایت اصلی بدون پیشوند) */
    private static function pf(): string { return class_exists('SiteCtx') ? SiteCtx::pfx() : ''; }

    public static function all(): array
    {
        if (self::$cache !== null && self::$cachePf === self::pf()) return self::$cache;
        $out = self::DEFAULTS;
        $pf = self::pf();
        try {
            $rows = Database::all("SELECT k, v FROM eb_settings WHERE k LIKE ?", [$pf . 'analytics.%']);
            foreach ($rows as $r) {
                $key = substr($r['k'], strlen($pf . 'analytics.'));
                if (array_key_exists($key, $out) && $r['v'] !== null && $r['v'] !== '') {
                    $out[$key] = $r['v'];
                }
            }
        } catch (Throwable $e) { /* جدول نبود → پیش‌فرض (یعنی خاموش) */ }
        self::$cache = $out; self::$cachePf = $pf;
        return $out;
    }

    public static function get(string $key): string
    {
        $a = self::all();
        return (string)($a[$key] ?? (self::DEFAULTS[$key] ?? ''));
    }

    /** آیا ماژول روشن است؟ */
    public static function isActive(): bool
    {
        return self::get('enabled') === '1';
    }

    public static function heatmapEnabled(): bool
    {
        return self::isActive() && (int)self::get('sampling_heatmap') > 0;
    }

    public static function save(array $values): void
    {
        $allowed = array_keys(self::DEFAULTS);
        foreach ($values as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            if ($k === 'sampling_heatmap' || $k === 'sampling_move') {
                $v = (string)max(0, min(100, (int)$v));
            }
            if ($k === 'retention_days_raw') {
                $v = (string)max(7, min(365, (int)$v));
            }
            if ($k === 'retention_days_sessions') {
                $v = (string)max(30, min(730, (int)$v));
            }
            Database::run(
                "INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
                [self::pf() . 'analytics.' . $k, (string)$v]
            );
        }
        self::$cache = null;
        // اگر تازه فعال شد و site_key نداشت، یکی بساز
        if (self::get('enabled') === '1' && self::get('site_key') === '') {
            self::ensureSiteKey();
        }
    }

    /** کلید سایت (برای این‌که چند دامنه هم‌زمان رصد شوند در آینده، فعلاً یکی کافی است) */
    public static function ensureSiteKey(): string
    {
        $key = self::get('site_key');
        if ($key !== '') return $key;
        $key = bin2hex(random_bytes(16));
        Database::run(
            "INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
            [self::pf() . 'analytics.site_key', $key]
        );
        self::$cache = null;
        return $key;
    }

    /** تنظیمات برای UI */
    public static function publicSettings(): array
    {
        $a = self::all();
        $a['current_domain'] = self::currentDomain();
        // دقت شهر فقط اگر فایل .mmdb دستی نصب شده باشد؛ دقت کشور همیشه از
        // دیتابیس همراهِ پروژه در دسترس است (بدون نیاز به هیچ تنظیم دستی).
        $hasCity = class_exists('GeoIp') ? GeoIp::isAvailable() : false;
        $hasCountry = class_exists('GeoIpCountry') ? GeoIpCountry::isAvailable() : false;
        $a['geoip_available'] = $hasCity || $hasCountry;
        $a['geoip_precision'] = $hasCity ? 'city' : ($hasCountry ? 'country' : 'none');
        return $a;
    }

    /** دامنه‌ی نصب (برای نمایش در تنظیمات) */
    public static function currentDomain(): string
    {
        global $config;
        $d = '';
        if (is_array($config ?? null) && function_exists('app_base_url')) $d = app_base_url($config);
        if ($d === '') $d = $_SERVER['HTTP_HOST'] ?? '';
        $d = strtolower(trim($d));
        $d = preg_replace('#^https?://#', '', $d);
        $d = preg_replace('#[:/].*$#', '', $d);
        return preg_replace('#^www\.#', '', $d);
    }
}
