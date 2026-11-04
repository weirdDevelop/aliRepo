<?php
/**
 * modules/InstagramDirectsaz.php
 * تنظیمات و کمک‌متدهای اتصال اینستاگرام از طریق «دایرکت‌ساز» (developers.directsaz.com)
 * — یک واسط آماده که خودش Advanced Access و App Review متا را دارد، به‌جای
 * ساختن اپ Meta مستقیم (مسیری که در modules/InstagramAuth.php پیاده شده بود).
 *
 * چرا این ماژول جدا از InstagramAuth است، نه جایگزینش:
 *   - هر پیج ممکن است از یک روش وصل باشد؛ اگر بعداً برای یک پیج خاص Business
 *     Verification/App Review خودتان جور شد، می‌توانید بدون از دست دادن تاریخچه
 *     برگردید به OAuth مستقیم — فقط با عوض‌کردن یک تنظیم (channel.instagram.provider).
 *   - دو مکانیزم احراز هویت (Bearer API Key ثابت در برابر OAuth دومرحله‌ای با
 *     انقضا/تمدید) آن‌قدر متفاوت‌اند که ادغام‌شان در یک کلاس فقط شرط‌های تودرتو
 *     اضافه می‌کرد.
 *
 * تنظیمات در eb_settings با پیشوند `channel.instagram.directsaz.`:
 *   api_key         — کلید ds_live_... (از پنل دایرکت‌ساز، بخش هر پیج)
 *   webhook_token   — توکنی که پنل دایرکت‌ساز برای تأیید/امضای وب‌هوک استفاده می‌کند
 *   account_id      — (اختیاری، فقط نمایشی) شناسه‌ی عددی پیج، برای راهنمای ادمین
 *
 * سوییچ فعال‌بودن این روش در برابر OAuth مستقیم:
 *   channel.instagram.provider = 'graph_api' (پیش‌فرض، سازگار با نصب‌های قبلی) | 'directsaz'
 */

class InstagramDirectsaz
{
    const DEFAULTS = [
        'api_key'       => '',
        'webhook_token' => '',
        'account_id'    => '',
        'base_api_url'  => 'https://developers.directsaz.com/api/v1',
    ];

    private static $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;
        $out = self::DEFAULTS;
        try {
            $rows = Database::all("SELECT k, v FROM eb_settings WHERE k LIKE 'channel.instagram.directsaz.%'");
            foreach ($rows as $r) {
                $key = substr($r['k'], strlen('channel.instagram.directsaz.'));
                if (array_key_exists($key, $out) && $r['v'] !== null && $r['v'] !== '') {
                    $out[$key] = $r['v'];
                }
            }
        } catch (Throwable $e) { /* پیش‌فرض */ }
        self::$cache = $out;
        return $out;
    }

    public static function get(string $key): string
    {
        $a = self::all();
        return (string)($a[$key] ?? '');
    }

    public static function save(string $apiKey, string $webhookToken, string $accountId = ''): void
    {
        $vals = [
            'api_key'       => trim($apiKey),
            'webhook_token' => trim($webhookToken),
            'account_id'    => trim($accountId),
        ];
        foreach ($vals as $k => $v) {
            if ($v === '' && $k !== 'account_id') continue; // خالی = دست‌نخورده بماند (مگر account_id که اختیاری محض است)
            Database::run(
                "INSERT INTO eb_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
                ["channel.instagram.directsaz.{$k}", $v]
            );
        }
        self::$cache = null;
    }

    public static function isConnected(): bool
    {
        $a = self::all();
        return trim($a['api_key']) !== '' && trim($a['webhook_token']) !== '';
    }

    /** درایور آماده برای فراخوانی، یا null اگر هنوز وصل نشده */
    public static function driver(): ?InstagramDirectsazDriver
    {
        if (!self::isConnected()) return null;
        $a = self::all();
        return new InstagramDirectsazDriver($a['api_key'], $a['base_api_url'] ?: 'https://developers.directsaz.com/api/v1');
    }

    public static function disconnect(): void
    {
        foreach (['api_key', 'webhook_token', 'account_id'] as $k) {
            Database::run("DELETE FROM eb_settings WHERE k = ?", ["channel.instagram.directsaz.{$k}"]);
        }
        self::$cache = null;
    }

    // ==================== انتخاب روش اتصال (provider) ====================

    /** 'graph_api' یا 'directsaz'. پیش‌فرض graph_api تا نصب‌های قبلی که قبلاً با OAuth مستقیم
     *  وصل شده‌اند رفتارشان عوض نشود. */
    public static function activeProvider(): string
    {
        try {
            $row = Database::one("SELECT v FROM eb_settings WHERE k = 'channel.instagram.provider'");
            $v = (string)($row['v'] ?? '');
            return $v === 'directsaz' ? 'directsaz' : 'graph_api';
        } catch (Throwable $e) {
            return 'graph_api';
        }
    }

    public static function setActiveProvider(string $provider): void
    {
        $provider = $provider === 'directsaz' ? 'directsaz' : 'graph_api';
        Database::run(
            "INSERT INTO eb_settings (k,v) VALUES ('channel.instagram.provider', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
            [$provider]
        );
    }
}
