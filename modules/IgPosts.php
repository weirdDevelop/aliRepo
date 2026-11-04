<?php
/**
 * modules/IgPosts.php
 * صف انتشار پست و استوری اینستاگرام.
 *
 * سرویس‌دهنده‌های اینستاگرام (یار API و روبوکلیک) زمان‌بندی ندارند؛ فقط «همین حالا منتشر کن».
 * پس زمان‌بندی در خود اعلان‌بان انجام می‌شود: هر پست یک ردیف در eb_ig_posts است و cron در زمانش
 * عکس را آپلود و منتشر می‌کند. اگر انتشار شکست بخورد، تا ۳ بار با فاصله‌ی افزایشی تکرار می‌شود
 * و خطای دقیق کنار همان پست نمایش داده می‌شود تا هیچ پستی بی‌صدا در صف نماند.
 */
class IgPosts
{
    const MAX_ATTEMPTS = 3;

    private static function boot(): void
    {
        if (!class_exists('SchemaV4')) require_once __DIR__ . '/../core/SchemaV4.php';
        if (!class_exists('Tz')) require_once __DIR__ . '/../core/Tz.php';
        SchemaV4::ensure();
    }

    public static function uploadsDir(): string
    {
        global $config;
        return rtrim($config['paths']['uploads'] ?? (__DIR__ . '/../uploads/'), '/') . '/';
    }

    public static function absPath(string $rel): string
    {
        return self::uploadsDir() . basename($rel);
    }

    /**
     * ثبت پست. $runAtLocal خالی = همین حالا (در اولین اجرای cron یا با publishNow).
     * @return array ['ok'=>bool, 'id'=>int, 'error'=>?string]
     */
    public static function create(array $d): array
    {
        self::boot();
        $acc = (int)($d['ig_account_id'] ?? 0);
        if (!$acc || !InstagramRoboclick::get($acc)) return ['ok' => false, 'error' => 'پیج اینستاگرام انتخاب نشده یا حذف شده است'];
        $kind = ($d['kind'] ?? 'post') === 'story' ? 'story' : 'post';
        $rel = trim((string)($d['media_path'] ?? ''));
        if ($rel === '' || !is_file(self::absPath($rel))) return ['ok' => false, 'error' => 'فایل عکس پیدا نشد؛ دوباره آپلود کنید'];
        $conv = self::ensureJpeg($rel);
        if (!$conv['ok']) return $conv;
        $rel = $conv['rel'];
        $caption = (string)($d['caption'] ?? '');
        if (mb_strlen($caption) > 2200) return ['ok' => false, 'error' => 'کپشن بیشتر از ۲۲۰۰ کاراکتر است'];
        $runAt = null;
        if (!empty($d['run_at'])) {
            $runAt = Tz::toDb((string)$d['run_at']);
            if (!$runAt) return ['ok' => false, 'error' => 'زمان انتشار نامعتبر است'];
        }
        $runAt = $runAt ?: Database::one("SELECT NOW() n")['n'];
        $id = (int)($d['id'] ?? 0);
        if ($id) {
            $row = Database::one("SELECT status FROM eb_ig_posts WHERE id=?", [$id]);
            if (!$row) return ['ok' => false, 'error' => 'پست پیدا نشد'];
            if (!in_array($row['status'], ['pending', 'failed', 'cancelled'], true)) return ['ok' => false, 'error' => 'این پست در حال انتشار یا منتشرشده است و قابل ویرایش نیست'];
            Database::run("UPDATE eb_ig_posts SET ig_account_id=?, kind=?, media_path=?, caption=?, run_at=?, status='pending', attempts=0, error=NULL WHERE id=?",
                [$acc, $kind, $rel, $caption, $runAt, $id]);
        } else {
            Database::run("INSERT INTO eb_ig_posts (ig_account_id, kind, media_path, caption, run_at, mkt_content_id, created_by) VALUES (?,?,?,?,?,?,?)",
                [$acc, $kind, $rel, $caption, $runAt, !empty($d['mkt_content_id']) ? (int)$d['mkt_content_id'] : null, class_exists('Auth') ? Auth::id() : null]);
            $id = Database::lastId();
        }
        return ['ok' => true, 'id' => $id];
    }

    public static function list(array $f = []): array
    {
        self::boot();
        $w = []; $p = [];
        if (!empty($f['ig_account_id'])) { $w[] = 'p.ig_account_id = ?'; $p[] = (int)$f['ig_account_id']; }
        if (!empty($f['mkt_content_id'])) { $w[] = 'p.mkt_content_id = ?'; $p[] = (int)$f['mkt_content_id']; }
        if (!empty($f['status'])) { $w[] = 'p.status = ?'; $p[] = (string)$f['status']; }
        $rows = Database::all(
            "SELECT p.* FROM eb_ig_posts p" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') .
            " ORDER BY (p.status IN ('pending','publishing')) DESC, p.run_at DESC LIMIT 200", $p
        );
        $labels = [];
        foreach ($rows as &$r) {
            $a = (int)$r['ig_account_id'];
            if (!isset($labels[$a])) { $acc = InstagramRoboclick::get($a); $labels[$a] = $acc ? ($acc['label'] ?: ('@' . $acc['username'])) : 'پیج حذف‌شده'; }
            $r['page_label'] = $labels[$a];
            $r['run_at_local'] = Tz::toLocal($r['run_at']);
            $r['published_at_local'] = Tz::toLocal($r['published_at']);
            $r['thumb'] = 'uploads/' . basename($r['media_path']);
            unset($r['result_json']);
        }
        unset($r);
        return $rows;
    }

    public static function cancel(int $id): bool
    {
        self::boot();
        $st = Database::run("UPDATE eb_ig_posts SET status='cancelled' WHERE id=? AND status IN ('pending','failed')", [$id]);
        return $st && $st->rowCount() > 0;
    }

    public static function delete(int $id): void
    {
        self::boot();
        Database::run("DELETE FROM eb_ig_posts WHERE id=? AND status <> 'publishing'", [$id]);
    }

    /** دوباره در صف (همین حالا) */
    public static function retry(int $id): bool
    {
        self::boot();
        $st = Database::run("UPDATE eb_ig_posts SET status='pending', attempts=0, error=NULL, run_at=NOW() WHERE id=? AND status IN ('failed','cancelled')", [$id]);
        return $st && $st->rowCount() > 0;
    }

    /** انتشار فوری یک پست (از پنل)؛ همان مسیر cron با claim اتمیک */
    public static function publishNow(int $id): array
    {
        self::boot();
        Database::run("UPDATE eb_ig_posts SET run_at=NOW() WHERE id=? AND status='pending'", [$id]);
        $row = Database::one("SELECT * FROM eb_ig_posts WHERE id=?", [$id]);
        if (!$row) return ['ok' => false, 'error' => 'پست پیدا نشد'];
        if ($row['status'] !== 'pending') return ['ok' => false, 'error' => 'این پست در صف انتظار نیست'];
        return self::publishOne($row);
    }

    /** cron: پست‌های سررسیده */
    public static function processDue(int $limit = 3): array
    {
        self::boot();
        // پستی که بیش از ۱۵ دقیقه در «در حال انتشار» مانده (تایم‌اوت سرور) برای تلاش بعدی آزاد می‌شود
        try {
            Database::run("UPDATE eb_ig_posts SET status='pending', error='تلاش قبلی نیمه‌کاره ماند؛ دوباره امتحان می‌شود'
                           WHERE status='publishing' AND claimed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND attempts < ?", [self::MAX_ATTEMPTS]);
            Database::run("UPDATE eb_ig_posts SET status='failed', error=COALESCE(error,'انتشار نیمه‌کاره ماند')
                           WHERE status='publishing' AND claimed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        } catch (Throwable $e) {}
        $due = Database::all("SELECT * FROM eb_ig_posts WHERE status='pending' AND run_at <= NOW() ORDER BY run_at ASC LIMIT " . max(1, $limit));
        $ok = 0; $fail = 0;
        foreach ($due as $row) {
            $r = self::publishOne($row);
            if (!empty($r['skipped'])) continue;
            $r['ok'] ? $ok++ : $fail++;
        }
        return ['published' => $ok, 'failed' => $fail];
    }

    private static function publishOne(array $row): array
    {
        $claim = Database::run("UPDATE eb_ig_posts SET status='publishing', claimed_at=NOW(), attempts=attempts+1 WHERE id=? AND status='pending'", [(int)$row['id']]);
        if (!$claim || $claim->rowCount() !== 1) return ['ok' => false, 'skipped' => true, 'error' => 'این پست را اجرای دیگری برداشته است'];
        $attempts = (int)$row['attempts'] + 1;
        $error = null; $result = null;
        try {
            $driver = InstagramRoboclick::driverForAccountId((int)$row['ig_account_id']);
            if (!$driver) throw new RuntimeException('پیج اینستاگرام پیدا نشد یا غیرفعال است');
            $abs = self::absPath($row['media_path']);
            if (!is_file($abs)) throw new RuntimeException('فایل عکس روی سرور پیدا نشد');
            $up = $driver->uploadImage($abs);
            if (empty($up['ok']) || empty($up['photo'])) throw new RuntimeException('آپلود عکس ناموفق: ' . ($up['error'] ?? 'پاسخ نامعتبر سرویس'));
            $result = $row['kind'] === 'story' ? $driver->sendStory($up['photo']) : $driver->sendPost((string)$row['caption'], $up['photo']);
            if (empty($result['ok'])) throw new RuntimeException((string)($result['error'] ?? 'سرویس اینستاگرام انتشار را نپذیرفت'));
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 480);
        }
        if ($error === null) {
            Database::run("UPDATE eb_ig_posts SET status='published', published_at=NOW(), error=NULL, result_json=? WHERE id=?",
                [json_encode(['tracking_id' => $result['tracking_id'] ?? null, 'raw' => $result['raw'] ?? null], JSON_UNESCAPED_UNICODE), (int)$row['id']]);
            if (!empty($row['mkt_content_id']) && class_exists('Marketing')) {
                try { Marketing::moveContent((int)$row['mkt_content_id'], 'published'); } catch (Throwable $e) {}
            }
            self::notify($row, true, null);
            return ['ok' => true];
        }
        if ($attempts < self::MAX_ATTEMPTS) {
            // تلاش دوباره با فاصله‌ی ۵ و ۱۵ دقیقه
            Database::run("UPDATE eb_ig_posts SET status='pending', error=?, run_at=DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id=?",
                [$error . ' (تلاش ' . $attempts . ' از ' . self::MAX_ATTEMPTS . ')', $attempts === 1 ? 5 : 15, (int)$row['id']]);
        } else {
            Database::run("UPDATE eb_ig_posts SET status='failed', error=? WHERE id=?", [$error, (int)$row['id']]);
            self::notify($row, false, $error);
        }
        return ['ok' => false, 'error' => $error];
    }

    private static function notify(array $row, bool $ok, ?string $error): void
    {
        if (!class_exists('Notifier')) return;
        try {
            $what = $row['kind'] === 'story' ? 'استوری' : 'پست';
            Notifier::push('system', $ok ? "{$what} زمان‌بندی‌شده منتشر شد" : "انتشار {$what} ناموفق بود", $ok ? mb_substr((string)$row['caption'], 0, 80) : (string)$error, 'index.php#instagram', null);
        } catch (Throwable $e) {}
    }

    /**
     * اینستاگرام برای انتشار فقط JPEG می‌پذیرد. PNG/WebP اگر GD در دسترس باشد به JPEG تبدیل می‌شود.
     * نسبت تصویر بررسی نمی‌شود چون سرویس‌دهنده خودش خطای روشن برمی‌گرداند.
     */
    public static function ensureJpeg(string $rel): array
    {
        $abs = self::absPath($rel);
        $info = @getimagesize($abs);
        if (!$info) return ['ok' => false, 'error' => 'فایل انتخاب‌شده عکس معتبر نیست'];
        if ($info[2] === IMAGETYPE_JPEG) return ['ok' => true, 'rel' => 'uploads/' . basename($rel)];
        if (!function_exists('imagecreatetruecolor')) return ['ok' => false, 'error' => 'فقط عکس JPG پذیرفته می‌شود (روی سرور افزونه‌ی GD برای تبدیل خودکار نصب نیست)'];
        $src = null;
        if ($info[2] === IMAGETYPE_PNG) $src = @imagecreatefrompng($abs);
        elseif ($info[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($abs);
        elseif ($info[2] === IMAGETYPE_GIF) $src = @imagecreatefromgif($abs);
        if (!$src) return ['ok' => false, 'error' => 'این قالب عکس قابل تبدیل به JPG نیست'];
        $w = imagesx($src); $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // پس‌زمینه‌ی سفید برای PNG شفاف
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        $name = 'igp_' . bin2hex(random_bytes(6)) . '.jpg';
        $ok = imagejpeg($dst, self::uploadsDir() . $name, 92);
        imagedestroy($src); imagedestroy($dst);
        return $ok ? ['ok' => true, 'rel' => 'uploads/' . $name] : ['ok' => false, 'error' => 'ذخیره‌ی JPG ناموفق بود'];
    }
}
