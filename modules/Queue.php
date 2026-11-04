<?php
/**
 * modules/Queue.php
 * صف پایدار ارسال انبوه. هر گیرنده یک ردیف در eb_queue دارد، پس اگر پردازش وسط کار
 * قطع شود، از همان‌جا ادامه می‌یابد و هیچ‌کس دوبار پیام نمی‌گیرد.
 *
 * جریان:
 *   enqueue() → یک job + N ردیف pending می‌سازد (و یک SendLog با kind='bulk').
 *   process() → یک batch از ردیف‌های pending را می‌فرستد (توسط cron یا «هل» مرورگر).
 *   status()  → پیشرفت یک job یا کل صف.
 *
 * چون رویکرد «صف کامل» است، ارسال انبوه دیگر همگام در مرورگر انجام نمی‌شود؛
 * فقط در صف می‌رود و cron (یا هل مرورگر) پردازشش می‌کند.
 */

class Queue
{
    /**
     * افزودن یک کار ارسال انبوه به صف.
     * $personIds: آرایه‌ای از person_id. $message: آرایه‌ی پیام (text/photo/buttons).
     * خروجی: ['job_id'=>int, 'send_id'=>int, 'total'=>int]
     */
    public static function enqueue(array $personIds, array $message, array $channelPriority, ?string $title = null): array
    {
        $personIds = array_values(array_unique(array_map('intval', $personIds)));
        $personIds = array_filter($personIds, fn($x) => $x > 0);
        if (!$personIds) return ['job_id' => 0, 'send_id' => 0, 'total' => 0];

        $title = $title ?: (mb_substr($message['text'] ?? 'ارسال انبوه', 0, 80));
        $priority = implode(',', $channelPriority);

        // یک SendLog بساز تا در تاریخچه‌ی ارسال هم دیده شود
        $sendId = class_exists('SendLog')
            ? SendLog::start('bulk', $title, $message['text'] ?? null, $channelPriority, 'queue')
            : null;

        Database::run(
            "INSERT INTO eb_queue_jobs (send_id, title, message_json, channel_priority, total, status)
             VALUES (?, ?, ?, ?, ?, 'queued')",
            [$sendId, $title, json_encode($message, JSON_UNESCAPED_UNICODE), $priority, count($personIds)]
        );
        $jobId = Database::lastId();

        // ردیف‌های گیرنده را گروهی درج کن (batch insert برای سرعت)
        $chunks = array_chunk($personIds, 500);
        foreach ($chunks as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), "($jobId, ?, 'pending')"));
            $sql = "INSERT INTO eb_queue (job_id, person_id, status) VALUES $ph";
            Database::run($sql, array_values($chunk));
        }

        return ['job_id' => $jobId, 'send_id' => $sendId, 'total' => count($personIds)];
    }

    /**
     * پردازش یک batch از ردیف‌های pending در کل صف.
     * توسط cron (هر اجرا) یا «هل» مرورگر صدا زده می‌شود.
     * $batch: حداکثر تعداد گیرنده در این اجرا.
     * خروجی: ['processed'=>int, 'sent'=>int, 'failed'=>int, 'remaining'=>int, 'busy'=>bool]
     *
     * ---- مورد ۶ دور بازخورد: باگ ارسال دوباره ----
     * الگوی قبلی «SELECT pending → ارسال → UPDATE» بود، **بدون claim اتمیک**. دو worker
     * همزمان (هل مرورگر + cron، یا دو اجرای cron که روی هم افتاده‌اند چون یک اجرا بیش از
     * یک دقیقه طول کشیده) همان ردیف‌های pending را برمی‌داشتند و هر دو می‌فرستادند.
     * ادعای idempotent بودن فقط برای اجرای **ترتیبی** درست بود، نه همزمان.
     *
     * دو لایه محافظت اضافه شد:
     *   ۱) قفل انحصاری worker (`GET_LOCK`) — همزمان فقط یک پردازنده‌ی صف.
     *   ۲) claim اتمیک — ردیف با یک UPDATE از pending به 'sending' می‌رود و توکن مالکیت
     *      می‌گیرد؛ فقط ردیف‌های دارای توکنِ خودمان فرستاده می‌شوند.
     * لایه‌ی دوم حتی اگر GET_LOCK در دسترس نباشد (یا اتصال‌ها جدا باشند) کار می‌کند.
     */
    public static function process(int $batch = 50, bool $useLock = true): array
    {
        $sent = 0; $failed = 0; $processed = 0;

        // ---- لایه ۱: فقط یک worker همزمان ----
        // $useLock=false برای فراخوانی از cron که خودش قفل سراسری دارد. دلیل: در MySQL ≤5.6
        // گرفتن قفل تازه، قفل قبلیِ همان اتصال را آزاد می‌کند (قفل‌ها تودرتو نمی‌شوند).
        $locked = false;
        if ($useLock) {
            if (!self::acquireLock()) {
                return ['processed'=>0, 'sent'=>0, 'failed'=>0, 'remaining'=>self::openCount(), 'busy'=>true];
            }
            $locked = true;
        }

        try {
            // ردیف‌هایی که worker قبلی وسط کار مرد و 'sending' مانده‌اند را آزاد کن
            self::requeueStaleClaims();

            // job‌های فعال (queued/running) را به ترتیب قدیمی‌ترین بگیر
            $jobs = Database::all(
                "SELECT * FROM eb_queue_jobs WHERE status IN ('queued','running') ORDER BY id ASC"
            );

            foreach ($jobs as $job) {
                if ($processed >= $batch) break;
                $jobId = (int)$job['id'];

                // ---- لایه ۲: claim اتمیک ردیف‌های این job ----
                $rows = self::claimBatch($jobId, $batch - $processed);

                if (!$rows) {
                    // اگر هیچ ردیف بازی (pending/sending) نماند → job تمام است
                    if (self::openCountForJob($jobId) === 0) self::finalizeJob($jobId);
                    continue;
                }

                // اولین‌بار که کاری از این job پردازش می‌شود، وضعیتش را running کن
                if ($job['status'] === 'queued') {
                    Database::run("UPDATE eb_queue_jobs SET status='running' WHERE id=?", [$jobId]);
                }

                $message = json_decode($job['message_json'] ?? '{}', true) ?: [];
                $priority = array_filter(explode(',', $job['channel_priority'] ?? 'bale,telegram,roubika'));
                $sendId = !empty($job['send_id']) ? (int)$job['send_id'] : null;

                foreach ($rows as $idx => $row) {
                    // اگر کاربر وسط کار «لغو» زد، بقیه‌ی ردیف‌های claim‌شده را نفرست
                    if (self::isCancelled($jobId)) {
                        self::releaseClaimed(array_slice($rows, $idx));
                        break;
                    }

                    $res = Sender::sendToPerson((int)$row['person_id'], $message, $priority, $sendId);
                    $okSend = !empty($res['ok']);
                    Database::run(
                        "UPDATE eb_queue SET status = ?, channel = ?, error = ?, processed_at = NOW() WHERE id = ?",
                        [
                            $okSend ? 'sent' : 'failed',
                            $res['channel'] ?? null,
                            $okSend ? null : mb_substr((string)($res['error'] ?? 'failed'), 0, 500),
                            $row['id'],
                        ]
                    );
                    if ($okSend) $sent++; else $failed++;
                    $processed++;
                    usleep(20000); // ملایم با API پلتفرم
                }

                // پیشرفت job را به‌روز کن
                self::refreshJobCounts($jobId);

                // اگر دیگر ردیف بازی نماند، تمامش کن
                if (self::openCountForJob($jobId) === 0) self::finalizeJob($jobId);
            }

            $remaining = self::openCount();
            return ['processed' => $processed, 'sent' => $sent, 'failed' => $failed, 'remaining' => $remaining];
        } finally {
            // قفل در هر حالت (حتی خطا) آزاد شود، وگرنه صف تا پایان اتصال می‌خوابد
            if ($locked) self::releaseLock();
        }
    }

    // ================= کمک‌کننده‌های همزمانی (مورد ۶) =================

    private const LOCK_NAME = 'eb_queue_worker';

    /**
     * قفل انحصاری MySQL. اگر worker دیگری مشغول است false.
     * اگر GET_LOCK پشتیبانی نشد، true برمی‌گردانیم تا جلوی کار گرفته نشود
     * (لایه‌ی claim اتمیک همچنان از ارسال دوباره جلوگیری می‌کند).
     */
    private static function acquireLock(): bool
    {
        try {
            $r = Database::one("SELECT GET_LOCK(?, 0) g", [self::LOCK_NAME]);
            return (int)($r['g'] ?? 0) === 1;
        } catch (Throwable $e) {
            return true;
        }
    }

    private static function releaseLock(): void
    {
        try { Database::run("SELECT RELEASE_LOCK(?)", [self::LOCK_NAME]); } catch (Throwable $e) { /* بی‌صدا */ }
    }

    /**
     * برداشتن اتمیکِ چند ردیف pending: با یک UPDATE به 'sending' می‌روند و توکن می‌گیرند،
     * سپس فقط ردیف‌های همان توکن خوانده می‌شوند. پس دو worker هرگز یک ردیف را برنمی‌دارند.
     */
    private static function claimBatch(int $jobId, int $limit): array
    {
        $limit = (int)$limit;
        if ($limit < 1) return [];

        if (!self::hasClaimSupport()) {
            // نصب قدیمی (db_upgrade اجرا نشده): مسیر قبلی، فقط با محافظت قفل worker
            return Database::all(
                "SELECT id, person_id FROM eb_queue WHERE job_id = ? AND status = 'pending' ORDER BY id ASC LIMIT $limit",
                [$jobId]
            );
        }

        try {
            $token = bin2hex(random_bytes(12));
        } catch (Throwable $e) {
            $token = md5(uniqid('q', true));
        }
        Database::run(
            "UPDATE eb_queue SET status = 'sending', claim_token = ?, claimed_at = NOW()
             WHERE job_id = ? AND status = 'pending'
             ORDER BY id ASC LIMIT $limit",
            [$token, $jobId]
        );
        return Database::all(
            "SELECT id, person_id FROM eb_queue WHERE claim_token = ? AND status = 'sending' ORDER BY id ASC",
            [$token]
        );
    }

    /** برگرداندن ردیف‌های claim‌شده‌ی نفرستاده به pending (مثلاً بعد از لغو). */
    private static function releaseClaimed(array $rows): void
    {
        if (!$rows || !self::hasClaimSupport()) return;
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            Database::run(
                "UPDATE eb_queue SET status='pending', claim_token=NULL, claimed_at=NULL
                 WHERE id IN ($ph) AND status='sending'",
                $ids
            );
        } catch (Throwable $e) { /* بی‌صدا */ }
    }

    /**
     * ردیف‌هایی که بیش از ۱۰ دقیقه در 'sending' مانده‌اند (worker وسط کار مرده،
     * مثلاً timeout یا restart سرور) را به pending برگردان تا گم نشوند.
     */
    private static function requeueStaleClaims(): void
    {
        if (!self::hasClaimSupport()) return;
        try {
            Database::run(
                "UPDATE eb_queue SET status='pending', claim_token=NULL, claimed_at=NULL
                 WHERE status='sending'
                   AND (claimed_at IS NULL OR claimed_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))"
            );
        } catch (Throwable $e) { /* بی‌صدا */ }
    }

    /** آیا این job لغو شده؟ (چک سبک وسط ارسال) */
    private static function isCancelled(int $jobId): bool
    {
        $r = Database::one("SELECT status FROM eb_queue_jobs WHERE id = ?", [$jobId]);
        return $r && in_array($r['status'], ['cancelled', 'done'], true);
    }

    /** تعداد ردیف‌های «باز» (نه فرستاده نه ناموفق) یک job. */
    private static function openCountForJob(int $jobId): int
    {
        $r = Database::one(
            "SELECT COUNT(*) c FROM eb_queue WHERE job_id = ? AND status IN ('pending','sending')",
            [$jobId]
        );
        return (int)($r['c'] ?? 0);
    }

    /** تعداد ردیف‌های باز در کل صف (job‌های فعال). */
    private static function openCount(): int
    {
        $r = Database::one(
            "SELECT COUNT(*) c FROM eb_queue q INNER JOIN eb_queue_jobs j ON j.id = q.job_id
             WHERE q.status IN ('pending','sending') AND j.status IN ('queued','running')"
        );
        return (int)($r['c'] ?? 0);
    }

    /**
     * @var bool|null کش: آیا ستون claim_token و وضعیت 'sending' هر دو موجودند؟
     * تا قبل از «به‌روزرسانی دیتابیس» نباید UPDATE با مقدار sending بزنیم (strict mode خطا می‌دهد).
     */
    private static $hasClaim = null;
    private static function hasClaimSupport(): bool
    {
        if (self::$hasClaim !== null) return self::$hasClaim;
        try {
            $col = Database::one("SHOW COLUMNS FROM eb_queue LIKE 'claim_token'");
            $st  = Database::one("SHOW COLUMNS FROM eb_queue LIKE 'status'");
            $enumOk = $st && stripos((string)($st['Type'] ?? ''), "'sending'") !== false;
            self::$hasClaim = (bool)$col && $enumOk;
        } catch (Throwable $e) {
            self::$hasClaim = false;
        }
        return self::$hasClaim;
    }

    /** به‌روزرسانی شمارنده‌های یک job از روی ردیف‌هایش. */
    private static function refreshJobCounts(int $jobId): void
    {
        // نکته (مورد ۶): «processed» باید فقط sent+failed باشد. قبلاً `status<>'pending'` بود
        // که با آمدن وضعیت واسطِ 'sending' ردیف‌های در حال ارسال را هم پردازش‌شده می‌شمرد
        // و نوار پیشرفت جلوتر از واقعیت می‌رفت.
        $r = Database::one(
            "SELECT
                SUM(status='sent') s, SUM(status='failed') f,
                SUM(status IN ('sent','failed')) p
             FROM eb_queue WHERE job_id = ?",
            [$jobId]
        );
        Database::run(
            "UPDATE eb_queue_jobs SET sent=?, failed=?, processed=? WHERE id=?",
            [(int)($r['s'] ?? 0), (int)($r['f'] ?? 0), (int)($r['p'] ?? 0), $jobId]
        );
    }

    /** بستن یک job (done) + بستن SendLog مربوطه. */
    private static function finalizeJob(int $jobId): void
    {
        self::refreshJobCounts($jobId);
        $job = Database::one("SELECT * FROM eb_queue_jobs WHERE id=?", [$jobId]);
        if (!$job) return;
        if ($job['status'] === 'cancelled') return; // لغو شده، دست نزن
        Database::run("UPDATE eb_queue_jobs SET status='done', finished_at=NOW() WHERE id=?", [$jobId]);
        if (!empty($job['send_id']) && class_exists('SendLog')) {
            SendLog::finish((int)$job['send_id'], (int)$job['total'], (int)$job['sent'], (int)$job['failed']);
        }
    }

    /** لغو یک job (ردیف‌های pending دیگر فرستاده نمی‌شوند). */
    public static function cancel(int $jobId): bool
    {
        $job = Database::one("SELECT status FROM eb_queue_jobs WHERE id=?", [$jobId]);
        if (!$job || in_array($job['status'], ['done', 'cancelled'], true)) return false;
        Database::run("UPDATE eb_queue_jobs SET status='cancelled', finished_at=NOW() WHERE id=?", [$jobId]);
        self::refreshJobCounts($jobId);
        // SendLog را با آمار فعلی ببند
        $j = Database::one("SELECT * FROM eb_queue_jobs WHERE id=?", [$jobId]);
        if ($j && !empty($j['send_id']) && class_exists('SendLog')) {
            SendLog::finish((int)$j['send_id'], (int)$j['total'], (int)$j['sent'], (int)$j['failed']);
        }
        return true;
    }

    /** فهرست job‌های صف (برای صفحه‌ی نمایش). */
    public static function jobs(int $limit = 50): array
    {
        return Database::all("SELECT * FROM eb_queue_jobs ORDER BY id DESC LIMIT $limit");
    }

    /** وضعیت یک job خاص. */
    public static function job(int $jobId): ?array
    {
        return Database::one("SELECT * FROM eb_queue_jobs WHERE id=?", [$jobId]);
    }

    /** خلاصه‌ی وضعیت کل صف (برای نشانگر و «هل» مرورگر). */
    public static function summary(): array
    {
        $row = Database::one(
            "SELECT
                (SELECT COUNT(*) FROM eb_queue_jobs WHERE status IN ('queued','running')) active_jobs,
                (SELECT COUNT(*) FROM eb_queue q INNER JOIN eb_queue_jobs j ON j.id=q.job_id
                   WHERE q.status IN ('pending','sending') AND j.status IN ('queued','running')) pending"
        );
        return [
            'active_jobs' => (int)($row['active_jobs'] ?? 0),
            'pending'     => (int)($row['pending'] ?? 0),
        ];
    }
}
