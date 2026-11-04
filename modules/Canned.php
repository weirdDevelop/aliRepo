<?php
/**
 * modules/Canned.php
 * پیام‌های آماده‌ی تیم پشتیبانی (مورد ۶-۱) — متن‌های پرتکرار (آدرس دفتر، شماره
 * کارت، متن‌های راهنمای مصاحبه/معرفی و…) که اپراتور به‌جای تایپ دوباره،
 * انتخاب و در کادر پاسخ می‌گذارد یا مستقیم می‌فرستد.
 *
 * از همان توکن‌های Personalize پشتیبانی می‌کند ({name}, {phone}, ...)، پس یک
 * پیام آماده می‌تواند شخصی‌سازی‌شده باشد بدون اینکه اپراتور دستی جایگزین کند.
 *
 * جدول: eb_canned_replies. گاردِ hasTable تا قبل از db_upgrade کرش نکند.
 */

class Canned
{
    public static function all(?string $category = null): array
    {
        if (!self::hasTable()) return [];
        if ($category !== null && trim($category) !== '') {
            return Database::all(
                "SELECT * FROM eb_canned_replies WHERE category = ? ORDER BY sort_order ASC, id DESC",
                [$category]
            );
        }
        return Database::all("SELECT * FROM eb_canned_replies ORDER BY category IS NULL, category ASC, sort_order ASC, id DESC");
    }

    public static function categories(): array
    {
        if (!self::hasTable()) return [];
        $rows = Database::all(
            "SELECT DISTINCT category FROM eb_canned_replies WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC"
        );
        return array_column($rows, 'category');
    }

    public static function find(int $id): ?array
    {
        if (!self::hasTable()) return null;
        return Database::one("SELECT * FROM eb_canned_replies WHERE id = ?", [$id]);
    }

    /** @throws InvalidArgumentException|RuntimeException */
    public static function save(array $d, ?int $id = null, ?int $userId = null): int
    {
        if (!self::hasTable()) {
            throw new RuntimeException('ابتدا «به‌روزرسانی دیتابیس» را در تنظیمات کانال‌ها بزنید.');
        }
        $title = trim((string)($d['title'] ?? ''));
        $body  = trim((string)($d['body'] ?? ''));
        $cat   = trim((string)($d['category'] ?? ''));
        if ($title === '') throw new InvalidArgumentException('عنوان لازم است');
        if ($body === '')  throw new InvalidArgumentException('متن پیام لازم است');

        $title = mb_substr($title, 0, 255);
        $cat   = $cat !== '' ? mb_substr($cat, 0, 80) : null;
        $order = (int)($d['sort_order'] ?? 0);

        if ($id) {
            Database::run(
                "UPDATE eb_canned_replies SET title=?, body=?, category=?, sort_order=? WHERE id=?",
                [$title, $body, $cat, $order, $id]
            );
            return $id;
        }
        Database::run(
            "INSERT INTO eb_canned_replies (title, body, category, sort_order, created_by) VALUES (?, ?, ?, ?, ?)",
            [$title, $body, $cat, $order, $userId]
        );
        return Database::lastId();
    }

    public static function delete(int $id): void
    {
        if (!self::hasTable()) return;
        Database::run("DELETE FROM eb_canned_replies WHERE id = ?", [$id]);
    }

    /**
     * متن یک پیام آماده، شخصی‌سازی‌شده برای یک مشتری مشخص (اختیاری).
     * اگر personId داده نشود یا ماژول Personalize نبود، متن خام برمی‌گردد.
     */
    public static function textFor(int $id, ?int $personId = null): ?string
    {
        $row = self::find($id);
        if (!$row) return null;
        $body = (string)$row['body'];
        if ($personId && class_exists('Personalize')) {
            try { return Personalize::text($body, $personId); }
            catch (Throwable $e) { return $body; }
        }
        return $body;
    }

    private static $hasTable = null;
    public static function hasTable(): bool
    {
        if (self::$hasTable !== null) return self::$hasTable;
        try {
            self::$hasTable = Database::hasTable('eb_canned_replies');
        } catch (Throwable $e) { self::$hasTable = false; }
        return self::$hasTable;
    }
}
