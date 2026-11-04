<?php
/**
 * modules/Notifier.php
 * فید اعلان داخل پنل (مورد ۲ و ۵) — پیام تازه‌ی مشتری، و تکمیل فرم/استعلام روی سایت.
 *
 * طراحی عمداً ساده: یک جدول append-only (`eb_notifications`)، بدون وضعیت
 * «خوانده‌شده» سمت سرور. هر مرورگرِ اپراتور آخرین شناسه‌ای که دیده را در
 * localStorage نگه می‌دارد (دقیقاً همان الگویی که ویجت وب‌چت برای وضعیت محلی
 * استفاده می‌کند) — چون این تیم کوچک است و صف/خوانده‌شده‌ی هرکس جدا ارزش
 * پیچیدگی migration دیگری را نداشت.
 *
 * مصرف: هر بار رویداد قابل‌توجه رخ می‌دهد (پیام دریافتی جدید، فرم تکمیل‌شده)،
 * `Notifier::push()` صدا زده می‌شود. فرانت‌اند با `notifications_poll` هر چند
 * ثانیه یک‌بار می‌پرسد «چیزی بعد از فلان id هست؟» و صدا/اعلان مرورگر نشان می‌دهد.
 */

class Notifier
{
    public static function push(string $type, string $title, ?string $body = null, ?string $link = null, ?int $personId = null): void
    {
        if (!self::hasTable()) return;
        try {
            if (self::hasPersonId()) {
                Database::run(
                    "INSERT INTO eb_notifications (type, title, body, link, person_id) VALUES (?, ?, ?, ?, ?)",
                    [$type, mb_substr($title, 0, 255), $body !== null ? mb_substr($body, 0, 500) : null, $link, $personId]
                );
            } else {
                Database::run(
                    "INSERT INTO eb_notifications (type, title, body, link) VALUES (?, ?, ?, ?)",
                    [$type, mb_substr($title, 0, 255), $body !== null ? mb_substr($body, 0, 500) : null, $link]
                );
            }
        } catch (Throwable $e) { /* اعلان هرگز نباید مسیر اصلی (ذخیره‌ی پیام) را بشکند */ }
    }

    /**
     * رویدادهای بعد از $afterId (برای polling)، حداکثر ۳۰ تا در هر بار.
     * اگر ردیف person_id دارد و نوعش «message» است، فقط وقتی برگردانده می‌شود
     * که آن شخص هنوز واقعاً پیام دریافتیِ خوانده‌نشده داشته باشد — یعنی اگر
     * تا لحظه‌ی poll شدن، اپراتورِ دیگری (یا خودِ همین اپراتور در تب دیگر)
     * قبلاً آن گفتگو را باز/خوانده باشد، دیگر پاپ‌آپ نمی‌آید.
     */
    public static function since(int $afterId): array
    {
        if (!self::hasTable()) return [];
        try {
            $hasPid = self::hasPersonId();
            $unreadCond = $hasPid
                ? "AND (n.type != 'message' OR n.person_id IS NULL OR EXISTS (
                        SELECT 1 FROM eb_messages m
                        INNER JOIN eb_identities i ON i.id = m.identity_id
                        WHERE i.person_id = n.person_id AND m.direction='in' AND m.read_at IS NULL
                   ))"
                : "";
            return Database::all(
                "SELECT n.id, n.type, n.title, n.body, n.link, n.created_at
                 FROM eb_notifications n
                 WHERE n.id > ? $unreadCond
                 ORDER BY n.id ASC LIMIT 30",
                [$afterId]
            );
        } catch (Throwable $e) { return []; }
    }

    /** بزرگ‌ترین id فعلی — نقطه‌ی شروع برای کلاینتی که تازه صفحه را باز کرده (تا اعلان‌های قدیمی را نبیند) */
    public static function latestId(): int
    {
        if (!self::hasTable()) return 0;
        try {
            $r = Database::one("SELECT MAX(id) m FROM eb_notifications");
            return (int)($r['m'] ?? 0);
        } catch (Throwable $e) { return 0; }
    }

    private static $hasTable = null;
    public static function hasTable(): bool
    {
        if (self::$hasTable !== null) return self::$hasTable;
        try {
            self::$hasTable = Database::hasTable('eb_notifications');
        } catch (Throwable $e) { self::$hasTable = false; }
        return self::$hasTable;
    }

    /** @var bool|null کش: آیا ستون person_id روی eb_notifications هست؟ (برای فیلتر «فقط خوانده‌نشده») */
    private static $hasPersonId = null;
    private static function hasPersonId(): bool
    {
        if (self::$hasPersonId !== null) return self::$hasPersonId;
        try {
            self::$hasPersonId = Database::hasColumn('eb_notifications', 'person_id');
        } catch (Throwable $e) { self::$hasPersonId = false; }
        return self::$hasPersonId;
    }
}
