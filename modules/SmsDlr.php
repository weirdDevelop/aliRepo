<?php
/**
 * modules/SmsDlr.php
 * گزارش تحویل پیامک (DLR): ثبت شناسه‌ی پیام موقع ارسال،
 * و استعلام دوره‌ای وضعیت تحویل از provider (در cron).
 *
 * نکته: تحویل واقعی به قابلیت provider بستگی دارد. اگر provider
 * شناسه (msg_id) برنگرداند، DLR برای آن پیام ثبت نمی‌شود.
 */

class SmsDlr
{
    /** ثبت یک پیام برای پیگیری تحویل (موقع ارسال موفق) */
    public static function track(string $provider, ?string $msgId, ?string $phone, ?int $personId): void
    {
        if (!$msgId) return; // بدون شناسه، پیگیری ممکن نیست
        try {
            Database::run(
                "INSERT INTO eb_sms_dlr (provider, msg_id, phone, person_id, status) VALUES (?, ?, ?, ?, 'pending')",
                [$provider, $msgId, $phone, $personId]
            );
        } catch (Throwable $e) { /* بی‌صدا */ }
    }

    /**
     * استعلام وضعیت پیام‌های pending (توسط cron).
     * فقط پیام‌های اخیر (تا ۲۴ ساعت) را چک می‌کند تا بار اضافه نیفتد.
     * خروجی: تعداد پیام‌های به‌روزشده.
     */
    public static function poll(int $limit = 50): int
    {
        $rows = Database::all(
            "SELECT * FROM eb_sms_dlr
             WHERE status = 'pending' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY id ASC LIMIT $limit"
        );
        if (!$rows) return 0;

        $driver = ChannelManager::get('sms');
        if (!$driver) return 0;
        $provider = method_exists($driver, 'provider') ? null : null;
        // از خود درایور provider را می‌گیریم (متد عمومی فرض نشده؛ مستقیم استعلام را به provider می‌دهیم)
        $prov = $driver->getProvider();
        if (!$prov || !method_exists($prov, 'deliveryStatus')) return 0;

        $updated = 0;
        foreach ($rows as $r) {
            try {
                $status = $prov->deliveryStatus($r['msg_id']);
                if ($status && $status !== 'pending') {
                    Database::run(
                        "UPDATE eb_sms_dlr SET status=?, checked_at=NOW() WHERE id=?",
                        [$status, $r['id']]
                    );
                    $updated++;
                } else {
                    Database::run("UPDATE eb_sms_dlr SET checked_at=NOW() WHERE id=?", [$r['id']]);
                }
            } catch (Throwable $e) { /* این پیام را رد کن */ }
        }
        return $updated;
    }

    /** آمار خلاصه برای نمایش */
    public static function summary(): array
    {
        $rows = Database::all("SELECT status, COUNT(*) c FROM eb_sms_dlr GROUP BY status");
        $out = ['delivered'=>0, 'failed'=>0, 'pending'=>0];
        foreach ($rows as $r) { $out[$r['status']] = (int)$r['c']; }
        return $out;
    }
}
