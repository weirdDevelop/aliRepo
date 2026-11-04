<?php
/**
 * core/Cache.php
 * کش ساده‌ی فایلی (بدون نیاز به APCu/Redis) برای نتایج کوتاه‌عمر:
 * ساختار جدول‌ها، شمارنده‌های تب صندوق ورودی و…
 * اگر پوشه قابل نوشتن نبود، بی‌صدا غیرفعال می‌شود (هیچ‌وقت نباید پنل را بترکاند).
 */

class Cache
{
    private static ?string $dir = null;
    private static array $mem = [];

    private static function dir(): ?string
    {
        if (self::$dir !== null) return self::$dir ?: null;
        $d = __DIR__ . '/../logs/cache';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        if (!is_dir($d) || !is_writable($d)) {
            $d = rtrim(sys_get_temp_dir(), '/') . '/elanban_cache_' . substr(md5(__DIR__), 0, 8);
            if (!is_dir($d)) @mkdir($d, 0775, true);
        }
        self::$dir = (is_dir($d) && is_writable($d)) ? $d : '';
        return self::$dir ?: null;
    }

    private static function file(string $key): ?string
    {
        $d = self::dir();
        return $d ? $d . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.cache' : null;
    }

    public static function get(string $key)
    {
        if (array_key_exists($key, self::$mem)) return self::$mem[$key];
        $f = self::file($key);
        if (!$f || !is_file($f)) return null;
        $raw = @file_get_contents($f);
        if ($raw === false) return null;
        $d = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($d) || ($d['e'] ?? 0) < time()) return null;
        return self::$mem[$key] = $d['v'];
    }

    public static function set(string $key, $value, int $ttl = 30): void
    {
        self::$mem[$key] = $value;
        $f = self::file($key);
        if (!$f) return;
        $tmp = $f . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, serialize(['e' => time() + $ttl, 'v' => $value])) !== false) @rename($tmp, $f);
    }

    /** مقدار کش‌شده، یا اجرای $fn و ذخیره‌ی نتیجه */
    public static function remember(string $key, int $ttl, callable $fn)
    {
        $v = self::get($key);
        if ($v !== null) return $v;
        $v = $fn();
        self::set($key, $v, $ttl);
        return $v;
    }

    public static function forget(string $key): void
    {
        unset(self::$mem[$key]);
        $f = self::file($key);
        if ($f && is_file($f)) @unlink($f);
    }

    public static function flush(string $prefix = ''): void
    {
        foreach (array_keys(self::$mem) as $k) if ($prefix === '' || strpos($k, $prefix) === 0) unset(self::$mem[$k]);
        $d = self::dir();
        if (!$d) return;
        foreach ((array)glob($d . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $prefix) . '*.cache') as $f) @unlink($f);
    }
}
