<?php
/**
 * core/Tz.php
 * هماهنگی ساعت کاربر با ساعت دیتابیس.
 *
 * مشکل: کاربر زمان را به ساعت ایران وارد می‌کند (مثلاً ۲۰:۰۰)، اما NOW() در MySQL
 * روی بسیاری از هاست‌ها UTC است. نتیجه: کاری که برای ۲۰:۰۰ زمان‌بندی شده، سه‌ونیم ساعت
 * دیرتر اجرا می‌شد و از دید کاربر «در صف می‌ماند و اعمال نمی‌شود».
 *
 * راه‌حل: اختلاف ساعت محلی (eb_settings: app.tz، پیش‌فرض Asia/Tehran) با NOW() دیتابیس
 * محاسبه می‌شود و زمان‌ها هنگام ذخیره به ساعت دیتابیس و هنگام نمایش به ساعت محلی برمی‌گردند.
 */
class Tz
{
    private static $diff = null;

    public static function zone(): DateTimeZone
    {
        $name = 'Asia/Tehran';
        try {
            $r = Database::one("SELECT v FROM eb_settings WHERE k='app.tz'");
            if (!empty($r['v'])) $name = $r['v'];
        } catch (Throwable $e) {}
        try { return new DateTimeZone($name); } catch (Throwable $e) { return new DateTimeZone('Asia/Tehran'); }
    }

    /** ثانیه‌هایی که ساعت محلی از ساعت دیتابیس جلوتر است (مضرب ۱۵ دقیقه). */
    public static function diff(): int
    {
        if (self::$diff !== null) return self::$diff;
        $dbOffset = 0;
        try {
            $r = Database::one("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) s");
            $dbOffset = (int)round(((int)($r['s'] ?? 0)) / 900) * 900;
        } catch (Throwable $e) {}
        $local = self::zone()->getOffset(new DateTime('now', new DateTimeZone('UTC')));
        return self::$diff = $local - $dbOffset;
    }

    /** «YYYY-MM-DD HH:MM[:SS]» یا «YYYY-MM-DDTHH:MM» به ساعت محلی ← ساعت دیتابیس. */
    public static function toDb(string $local): ?string
    {
        $t = self::parse($local);
        return $t === null ? null : gmdate('Y-m-d H:i:s', $t - self::diff());
    }

    /** ساعت دیتابیس ← ساعت محلی */
    public static function toLocal(?string $db): ?string
    {
        if (!$db) return $db;
        $t = self::parse($db);
        return $t === null ? $db : gmdate('Y-m-d H:i:s', $t + self::diff());
    }

    /** اکنون به ساعت محلی */
    public static function nowLocal(): string
    {
        return (new DateTime('now', self::zone()))->format('Y-m-d H:i:s');
    }

    private static function parse(string $s): ?int
    {
        $s = strtr(trim($s), ['T' => ' ', '/' => '-']);
        $s = strtr($s, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
        if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $s, $m)) return null;
        // تاریخ و ساعت را بدون منطقه‌ی زمانی (UTC خام) به عدد تبدیل می‌کنیم؛ فقط اختلاف مهم است
        $t = gmmktime((int)($m[4] ?? 0), (int)($m[5] ?? 0), (int)($m[6] ?? 0), (int)$m[2], (int)$m[3], (int)$m[1]);
        return $t === false ? null : $t;
    }
}
