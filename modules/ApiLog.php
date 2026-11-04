<?php
/**
 * modules/ApiLog.php
 * لاگ دقیق تماس با APIهای خارجی (صفیر، پیامک، تماس گویا، ایمیل، روبیکا، ...).
 * payload خام و پاسخ خام را ذخیره می‌کند تا اشکال‌یابی دقیق ممکن باشد.
 *
 * استفاده:
 *   ApiLog::record('bale_safir', 'send_message', $payload, $response, $httpCode, $ok, $error);
 *
 * برای امنیت، فیلدهای حساس (token, password, api_key) در ثبت ماسک می‌شوند.
 */

class ApiLog
{
    /** آیا لاگ فعال است؟ (از تنظیمات) — پیش‌فرض روشن */
    public static function enabled(): bool
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            $row = Database::one("SELECT v FROM eb_settings WHERE k='apilog.enabled'");
            $cache = ($row === null) ? true : ($row['v'] === '1');
        } catch (Throwable $e) { $cache = false; }
        return $cache;
    }

    public static function record(string $channel, string $action, $request, $response, ?int $httpCode = null, ?bool $ok = null, ?string $error = null): void
    {
        if (!self::enabled()) return;
        try {
            Database::run(
                "INSERT INTO eb_api_log (channel, action, request_data, response_data, http_code, ok, error)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $channel,
                    $action,
                    self::stringify(self::mask($request)),
                    self::stringify($response),
                    $httpCode,
                    $ok === null ? null : ($ok ? 1 : 0),
                    $error ? mb_substr($error, 0, 500) : null,
                ]
            );
        } catch (Throwable $e) { /* لاگ نباید جریان اصلی را بشکند */ }
    }

    public static function all(int $limit = 100, ?string $channel = null): array
    {
        if ($channel) {
            return Database::all(
                "SELECT * FROM eb_api_log WHERE channel = ? ORDER BY id DESC LIMIT $limit",
                [$channel]
            );
        }
        return Database::all("SELECT * FROM eb_api_log ORDER BY id DESC LIMIT $limit");
    }

    public static function clear(): void
    {
        try { Database::run("DELETE FROM eb_api_log"); } catch (Throwable $e) {}
    }

    /** پاک‌کردن فقط لاگ‌های یک کانال — برای دکمه‌ی «پاک‌کردن» در صفحه‌ای مخصوص یک کانال
     *  (مثلاً صفحه‌ی مدیریت اینستاگرام)، تا لاگ بقیه‌ی سرویس‌ها (پیامک، ایمیل، ...) پاک نشود. */
    public static function clearChannel(string $channel): void
    {
        try { Database::run("DELETE FROM eb_api_log WHERE channel = ?", [$channel]); } catch (Throwable $e) {}
    }

    /** پاک‌سازی لاگ‌های قدیمی‌تر از N روز (در cron) */
    public static function cleanup(int $days = 14): void
    {
        try {
            Database::run("DELETE FROM eb_api_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
        } catch (Throwable $e) {}
    }

    /** ماسک کردن فیلدهای حساس */
    private static function mask($data)
    {
        if (!is_array($data)) return $data;
        $sensitive = ['password', 'token', 'api_key', 'apikey', 'pass', 'api-access-key'];
        foreach ($data as $k => $v) {
            $lk = strtolower((string)$k);
            if (in_array($lk, $sensitive, true)) {
                $data[$k] = '***';
            } elseif (is_array($v)) {
                $data[$k] = self::mask($v);
            }
        }
        return $data;
    }

    private static function stringify($data): string
    {
        if (is_string($data)) return mb_substr($data, 0, 8000);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return mb_substr($json ?: '', 0, 8000);
    }
}
