<?php
/**
 * modules/SendLog.php
 * لاگ‌گیری ارسال در سه لایه:
 *   - eb_sends         : هر عملیات ارسال (batch) + خلاصه
 *   - eb_send_targets  : وضعیت تک‌تک گیرنده‌ها در آن batch (با علت شکست)
 *   - eb_messages.error: علت شکست در سطح پیام
 * پاک‌سازی خودکار: رکوردهای قدیمی‌تر از ۶ ماه.
 */

class SendLog
{
    const RETENTION_DAYS = 180; // ۶ ماه

    /** شروع یک عملیات ارسال؛ خروجی: send_id (یا 0 اگر لاگ ممکن نبود) */
    public static function start(string $kind, ?string $title, ?string $body, array $channels = [], ?string $mode = null): int
    {
        try {
            Database::run(
                "INSERT INTO eb_sends (kind, title, body, channels, mode, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $kind,
                    $title !== null ? mb_substr($title, 0, 250) : null,
                    $body,
                    implode(',', $channels) ?: null,
                    $mode,
                    $_SESSION['eb_uid'] ?? null,
                ]
            );
            return Database::lastId();
        } catch (Throwable $e) {
            return 0; // لاگ نشد، ولی ارسال باید ادامه یابد
        }
    }

    /** ثبت وضعیت یک گیرنده در یک send */
    public static function target(int $sendId, ?int $personId, string $channel, string $recipient, bool $ok, ?string $error = null): void
    {
        if ($sendId <= 0) return;
        try {
            Database::run(
                "INSERT INTO eb_send_targets (send_id, person_id, channel, recipient, status, error)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$sendId, $personId, $channel, mb_substr($recipient, 0, 120), $ok ? 'sent' : 'failed', $error ? mb_substr($error, 0, 500) : null]
            );
        } catch (Throwable $e) { /* لاگ بی‌صدا شکست بخورد، ارسال مهم‌تر است */ }
    }

    /** پایان عملیات: ذخیره‌ی خلاصه‌ی آمار */
    public static function finish(int $sendId, int $total, int $sent, int $failed, array $meta = []): void
    {
        if ($sendId <= 0) return;
        try {
            Database::run(
                "UPDATE eb_sends SET total = ?, sent = ?, failed = ?, meta_json = ? WHERE id = ?",
                [$total, $sent, $failed, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, $sendId]
            );
        } catch (Throwable $e) { /* بی‌صدا */ }
    }

    /** فهرست عملیات‌های ارسال (برای صفحه‌ی تاریخچه) */
    public static function all(int $limit = 100): array
    {
        return Database::all("SELECT * FROM eb_sends ORDER BY created_at DESC LIMIT $limit");
    }

    /**
     * فهرست فیلترشده‌ی ارسال‌ها برای تاریخچه.
     * فیلترها: kind, channel, result (success|partial|failed), date_from, date_to, q.
     */
    public static function filtered(array $f, int $limit = 200): array
    {
        $conds = []; $params = [];
        if (!empty($f['kind']))    { $conds[] = "kind = ?";  $params[] = $f['kind']; }
        if (!empty($f['channel'])) { $conds[] = "(channels = ? OR channels LIKE ? OR channels LIKE ? OR channels LIKE ?)";
            $c = $f['channel']; $params[] = $c; $params[] = "$c,%"; $params[] = "%,$c"; $params[] = "%,$c,%"; }
        if (!empty($f['date_from'])) { $conds[] = "created_at >= ?"; $params[] = $f['date_from'].' 00:00:00'; }
        if (!empty($f['date_to']))   { $conds[] = "created_at <= ?"; $params[] = $f['date_to'].' 23:59:59'; }
        if (!empty($f['q']))         { $conds[] = "(title LIKE ? OR body LIKE ?)"; $params[] = '%'.$f['q'].'%'; $params[] = '%'.$f['q'].'%'; }

        $result = $f['result'] ?? '';
        if ($result === 'success')      $conds[] = "failed = 0 AND total > 0";
        elseif ($result === 'failed')   $conds[] = "sent = 0 AND total > 0";
        elseif ($result === 'partial')  $conds[] = "sent > 0 AND failed > 0";

        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $limit = max(1, min($limit, 1000));
        return Database::all("SELECT * FROM eb_sends $where ORDER BY created_at DESC LIMIT $limit", $params);
    }

    /** جزئیات یک عملیات: خلاصه + لیست گیرنده‌ها */
    public static function detail(int $sendId): ?array
    {
        $send = Database::one("SELECT * FROM eb_sends WHERE id = ?", [$sendId]);
        if (!$send) return null;
        $send['targets'] = Database::all(
            "SELECT t.*, p.full_name FROM eb_send_targets t
             LEFT JOIN eb_persons p ON p.id = t.person_id
             WHERE t.send_id = ? ORDER BY t.status ASC, t.id ASC",
            [$sendId]
        );
        return $send;
    }

    /** پاک‌سازی لاگ‌های قدیمی‌تر از ۶ ماه (توسط cron) */
    public static function cleanup(): int
    {
        $days = self::RETENTION_DAYS;
        // گیرنده‌ها و sendهای قدیمی
        Database::run("DELETE FROM eb_send_targets WHERE created_at < DATE_SUB(NOW(), INTERVAL $days DAY)");
        $n = Database::run("DELETE FROM eb_sends WHERE created_at < DATE_SUB(NOW(), INTERVAL $days DAY)")->rowCount();
        // پیام‌های قدیمی
        Database::run("DELETE FROM eb_messages WHERE created_at < DATE_SUB(NOW(), INTERVAL $days DAY)");
        return $n;
    }
}
