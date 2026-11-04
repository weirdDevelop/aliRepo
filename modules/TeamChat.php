<?php
/**
 * modules/TeamChat.php
 * چت داخلی بین اعضای تیم (مورد ۶-۲) — کاملاً جدا از مکالمه‌ی مشتری‌ها.
 * پیام مستقیم (DM) بین دو کاربر پنل (eb_users)، به‌علاوه یک کانال «همه»
 * (recipient_id = NULL) برای اطلاع‌رسانی سراسری تیم.
 *
 * ساده و polling-محور، هم‌سو با الگوی webchat (poll_seconds) در همین پروژه —
 * بدون وابستگی به WebSocket/queue که این استک آن‌ها را ندارد.
 *
 * جدول: eb_team_messages. گاردِ hasTable تا قبل از db_upgrade کرش نکند.
 */

class TeamChat
{
    /** کلید کانال «همه‌ی تیم» در recipient_id (NULL در دیتابیس) */
    const BROADCAST = 0;

    public static function users(int $excludeId = 0): array
    {
        $rows = Database::all(
            "SELECT id, username, " . Auth::nameSql('') . " AS name, role FROM eb_users WHERE is_active = 1 AND role <> 'viewer' ORDER BY name ASC"
        );
        if ($excludeId) $rows = array_values(array_filter($rows, fn($r) => (int)$r['id'] !== $excludeId));
        return $rows;
    }

    /**
     * نخِ گفتگو بین دو کاربر (DM)، یا کانال «همه» اگر $otherId === 0.
     */
    public static function thread(int $meId, int $otherId, int $limit = 200): array
    {
        if (!self::hasTable()) return [];
        if ($otherId === self::BROADCAST) {
            // جدیدترین‌ها (قبلاً قدیمی‌ترین $limit پیام برمی‌گشت)
            return array_reverse(Database::all(
                "SELECT tm.*, " . Auth::nameSql('u') . " AS sender_name FROM eb_team_messages tm
                 JOIN eb_users u ON u.id = tm.sender_id
                 WHERE tm.recipient_id IS NULL
                 ORDER BY tm.created_at DESC, tm.id DESC LIMIT $limit"
            ));
        }
        return array_reverse(Database::all(
            "SELECT tm.*, " . Auth::nameSql('u') . " AS sender_name FROM eb_team_messages tm
             JOIN eb_users u ON u.id = tm.sender_id
             WHERE (tm.sender_id = ? AND tm.recipient_id = ?)
                OR (tm.sender_id = ? AND tm.recipient_id = ?)
             ORDER BY tm.created_at DESC, tm.id DESC LIMIT $limit",
            [$meId, $otherId, $otherId, $meId]
        ));
    }

    public static function send(int $senderId, int $recipientId, string $body): int
    {
        if (!self::hasTable()) {
            throw new RuntimeException('ابتدا «به‌روزرسانی دیتابیس» را در تنظیمات کانال‌ها بزنید.');
        }
        $body = trim($body);
        if ($body === '') throw new InvalidArgumentException('پیام خالی است');
        $body = mb_substr($body, 0, 4000);

        Database::run(
            "INSERT INTO eb_team_messages (sender_id, recipient_id, body) VALUES (?, ?, ?)",
            [$senderId, $recipientId === self::BROADCAST ? null : $recipientId, $body]
        );
        return Database::lastId();
    }

    /** علامت‌زدن پیام‌های یک نخ (که برای من است) به‌عنوان خوانده‌شده */
    public static function markRead(int $meId, int $otherId): void
    {
        if (!self::hasTable()) return;
        if ($otherId === self::BROADCAST) {
            // کانال همه: «خوانده‌شده» شخصی معنا ندارد بدون جدول جدا per-user؛
            // برای سادگی، فقط DMها شمارشِ خوانده‌نشده دارند.
            return;
        }
        Database::run(
            "UPDATE eb_team_messages SET read_at = NOW()
             WHERE sender_id = ? AND recipient_id = ? AND read_at IS NULL",
            [$otherId, $meId]
        );
    }

    /** تعداد پیام خوانده‌نشده‌ی هر فرستنده به من (برای نشان‌دادن نقطه‌ی قرمز کنار نام) */
    public static function unreadCounts(int $meId): array
    {
        if (!self::hasTable()) return [];
        $rows = Database::all(
            "SELECT sender_id, COUNT(*) c FROM eb_team_messages
             WHERE recipient_id = ? AND read_at IS NULL
             GROUP BY sender_id",
            [$meId]
        );
        $out = [];
        foreach ($rows as $r) $out[(int)$r['sender_id']] = (int)$r['c'];
        return $out;
    }

    public static function totalUnread(int $meId): int
    {
        if (!self::hasTable()) return 0;
        $r = Database::one(
            "SELECT COUNT(*) c FROM eb_team_messages WHERE recipient_id = ? AND read_at IS NULL",
            [$meId]
        );
        return (int)($r['c'] ?? 0);
    }

    private static $hasTable = null;
    public static function hasTable(): bool
    {
        if (self::$hasTable !== null) return self::$hasTable;
        try {
            self::$hasTable = Database::hasTable('eb_team_messages');
        } catch (Throwable $e) { self::$hasTable = false; }
        return self::$hasTable;
    }
}
