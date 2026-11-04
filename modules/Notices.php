<?php
/**
 * modules/Notices.php
 * ساخت انواع اعلان روی سایت (مورد ۷): پاپ‌آپ، نوار بالا/پایین، اسلاید-این.
 * کاملاً جدا از گفتگوی آنلاین سایت (Webchat) — این یک‌طرفه است (اعلام)، نه گفتگو.
 *
 * نصب مثل ویجت چت: یک خط اسکریپت (`eb-embed.js`، قبلاً notice.js) در سایت مشتری.
 * آن اسکریپت با `eb-feed.php` (بدون‌لاگین، فقط خواندنی + شمارش بازدید/کلیک +
 * دریافت شماره برای کد تخفیف) حرف می‌زند.
 * اسم‌ها عمداً چیزی شبیه notice/popup نیستند — افزونه‌های ادبلاک این کلمات را بلاک می‌کنند.
 *
 * جدول: eb_notices, eb_notice_leads. گاردِ hasTable تا قبل از db_upgrade کرش نکند.
 */

class Notices
{
    const TYPES = ['popup', 'banner_top', 'banner_bottom', 'slide_in'];
    const FREQUENCIES = ['always', 'once_per_session', 'once_per_day', 'once_ever'];
    const DISCOUNT_MODES = ['none', 'static', 'generated'];

    public static function all(): array
    {
        if (!self::hasTable()) return [];
        // ۲.۱.۱: با چند سایت فقط اعلان‌های سایتِ انتخاب‌شده + «همه‌ی سایت‌ها» (site_id خالی)
        if (class_exists('SiteCtx') && SiteCtx::multi() && Database::hasColumn('eb_notices', 'site_id')) {
            return Database::all("SELECT * FROM eb_notices WHERE site_id IS NULL OR site_id = ? ORDER BY priority DESC, id DESC", [SiteCtx::id()]);
        }
        return Database::all("SELECT * FROM eb_notices ORDER BY priority DESC, id DESC");
    }

    public static function find(int $id): ?array
    {
        if (!self::hasTable()) return null;
        return Database::one("SELECT * FROM eb_notices WHERE id = ?", [$id]);
    }

    /** @throws InvalidArgumentException|RuntimeException */
    public static function save(array $d, ?int $id = null): int
    {
        if (!self::hasTable()) {
            throw new RuntimeException('ابتدا «به‌روزرسانی دیتابیس» را در تنظیمات کانال‌ها بزنید.');
        }
        $title = trim((string)($d['title'] ?? ''));
        if ($title === '') throw new InvalidArgumentException('عنوان داخلی لازم است (فقط برای خودت، به بازدیدکننده نشان داده نمی‌شود)');

        $type = in_array(($d['type'] ?? ''), self::TYPES, true) ? $d['type'] : 'popup';
        $freq = in_array(($d['frequency'] ?? ''), self::FREQUENCIES, true) ? $d['frequency'] : 'once_per_session';

        $body = trim((string)($d['body'] ?? ''));
        if ($body === '' && trim((string)($d['headline'] ?? '')) === '') {
            throw new InvalidArgumentException('حداقل تیتر یا متن اعلان لازم است');
        }

        $hex = fn($v) => preg_match('/^#[0-9a-fA-F]{6}$/', (string)$v) ? (string)$v : null;

        $capturePhone = !empty($d['capture_phone']);
        $discountMode = in_array(($d['discount_mode'] ?? ''), self::DISCOUNT_MODES, true) ? $d['discount_mode'] : 'none';
        if ($capturePhone && $discountMode === 'static' && trim((string)($d['discount_static_code'] ?? '')) === '') {
            throw new InvalidArgumentException('برای حالت «کد ثابت»، خودِ کد تخفیف را وارد کن');
        }

        $fields = [
            'title'         => mb_substr($title, 0, 255),
            'type'          => $type,
            'headline'      => mb_substr(trim((string)($d['headline'] ?? '')), 0, 255) ?: null,
            'body'          => $body ?: null,
            'image_url'     => trim((string)($d['image_url'] ?? '')) ?: null,
            'button_text'   => mb_substr(trim((string)($d['button_text'] ?? '')), 0, 100) ?: null,
            'button_url'    => trim((string)($d['button_url'] ?? '')) ?: null,
            'bg_color'      => $hex($d['bg_color'] ?? ''),
            'text_color'    => $hex($d['text_color'] ?? ''),
            'accent_color'  => $hex($d['accent_color'] ?? ''),
            'corner_radius' => max(0, min(32, (int)($d['corner_radius'] ?? 16))),
            'font_family'   => mb_substr(trim((string)($d['font_family'] ?? '')), 0, 150) ?: null,
            'delay_seconds' => max(0, min(120, (int)($d['delay_seconds'] ?? 3))),
            'frequency'     => $freq,
            'url_contains'  => mb_substr(trim((string)($d['url_contains'] ?? '')), 0, 255) ?: null,
            'url_exclude'   => mb_substr(trim((string)($d['url_exclude'] ?? '')), 0, 255) ?: null,
            'starts_at'     => trim((string)($d['starts_at'] ?? '')) ?: null,
            'ends_at'       => trim((string)($d['ends_at'] ?? '')) ?: null,
            'priority'      => (int)($d['priority'] ?? 0),
            'is_active'     => !empty($d['is_active']) ? 1 : 0,
            'capture_phone' => $capturePhone ? 1 : 0,
            'phone_label'   => mb_substr(trim((string)($d['phone_label'] ?? '')), 0, 150) ?: null,
            'phone_button_text' => mb_substr(trim((string)($d['phone_button_text'] ?? '')), 0, 100) ?: null,
            'discount_mode' => $discountMode,
            'discount_static_code' => mb_substr(trim((string)($d['discount_static_code'] ?? '')), 0, 60) ?: null,
            'discount_prefix' => mb_substr(trim((string)($d['discount_prefix'] ?? '')), 0, 20) ?: null,
            'success_headline' => mb_substr(trim((string)($d['success_headline'] ?? '')), 0, 255) ?: null,
            'success_note'  => mb_substr(trim((string)($d['success_note'] ?? '')), 0, 255) ?: null,
        ];

        // ۲.۱.۱: متعلق به کدام سایت؟ site_id خالی = همه‌ی سایت‌ها (پیش‌فرض اعلان جدید = سایتِ جاری)
        if (class_exists('SiteCtx') && Database::hasColumn('eb_notices', 'site_id')) {
            $scope = (string)($d['site_scope'] ?? '');
            if ($scope === 'all') $fields['site_id'] = null;
            elseif ($scope === 'site' || !$id) $fields['site_id'] = SiteCtx::id();
        }
        if ($id) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            Database::run("UPDATE eb_notices SET $sets WHERE id = ?", [...array_values($fields), $id]);
            return $id;
        }
        $cols = implode(', ', array_keys($fields));
        $ph   = implode(', ', array_fill(0, count($fields), '?'));
        Database::run("INSERT INTO eb_notices ($cols) VALUES ($ph)", array_values($fields));
        return Database::lastId();
    }

    public static function delete(int $id): void
    {
        if (!self::hasTable()) return;
        Database::run("DELETE FROM eb_notices WHERE id = ?", [$id]);
        Database::run("DELETE FROM eb_notice_leads WHERE notice_id = ?", [$id]);
    }

    /**
     * اعلان‌های فعالی که برای این URL باید نمایش داده شوند (قوانین زمان‌بندی/صفحه
     * اینجا بررسی می‌شود؛ قانون «هر چند وقت یک‌بار» در مرورگر با localStorage
     * چک می‌شود چون سرور بازدیدکننده را نمی‌شناسد).
     */
    public static function activeFor(string $url, int $limit = 5): array
    {
        if (!self::hasTable()) return [];
        try {
            $rows = Database::all(
                "SELECT id, type, headline, body, image_url, button_text, button_url,
                        bg_color, text_color, accent_color, corner_radius, font_family,
                        delay_seconds, frequency, url_contains, url_exclude, priority,
                        capture_phone, phone_label, phone_button_text, success_headline, success_note
                 FROM eb_notices
                 WHERE is_active = 1" . (class_exists('SiteCtx') && Database::hasColumn('eb_notices', 'site_id') ? ' AND (site_id IS NULL OR site_id = ' . (int)SiteCtx::id() . ')' : '') . "
                   AND (starts_at IS NULL OR starts_at <= NOW())
                   AND (ends_at   IS NULL OR ends_at   >= NOW())
                 ORDER BY priority DESC, id DESC"
            );
        } catch (Throwable $e) { return []; }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $out = [];
        foreach ($rows as $r) {
            $inc = trim((string)($r['url_contains'] ?? ''));
            $exc = trim((string)($r['url_exclude'] ?? ''));
            if ($inc !== '' && stripos($url, $inc) === false && stripos((string)$path, $inc) === false) continue;
            if ($exc !== '' && (stripos($url, $exc) !== false || stripos((string)$path, $exc) !== false)) continue;
            unset($r['url_contains'], $r['url_exclude'], $r['priority']);
            $out[] = $r;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    public static function hit(int $id, string $type): void
    {
        if (!self::hasTable()) return;
        $col = ($type === 'click') ? 'clicks' : 'impressions';
        try {
            Database::run("UPDATE eb_notices SET {$col} = {$col} + 1 WHERE id = ?", [$id]);
        } catch (Throwable $e) { /* آمار حیاتی نیست */ }
    }

    /**
     * ثبت شماره‌ی وارد‌شده در پاپ‌آپ و برگرداندن کد تخفیف مناسب.
     * اگر شماره از قبل برای همین اعلان ثبت شده باشد، همان کدِ قبلی برگردانده
     * می‌شود (نه یک کد تازه) — یک نفر نباید با رفرش کردن چند کد بگیرد.
     *
     * @return array{ok:bool, code?:string, headline?:string, note?:string, error?:string}
     */
    public static function submitLead(int $noticeId, string $rawPhone, string $pageUrl): array
    {
        if (!self::hasTable()) return ['ok' => false, 'error' => 'ماژول آماده نیست'];

        $notice = Database::one("SELECT * FROM eb_notices WHERE id = ? AND is_active = 1", [$noticeId]);
        if (!$notice) return ['ok' => false, 'error' => 'اعلان پیدا نشد'];
        if ((int)$notice['capture_phone'] !== 1) return ['ok' => false, 'error' => 'دریافت شماره برای این اعلان فعال نیست'];

        $phone = class_exists('Persons') ? Persons::normalizePhone($rawPhone) : null;
        if (!$phone) return ['ok' => false, 'error' => 'شماره موبایل معتبر نیست'];

        // اگر قبلاً همین شماره برای همین اعلان کد گرفته، همان کدِ قبلی را بده
        $existing = Database::one(
            "SELECT discount_code FROM eb_notice_leads WHERE notice_id = ? AND phone = ? ORDER BY id DESC LIMIT 1",
            [$noticeId, $phone]
        );

        $code = null;
        if ($notice['discount_mode'] === 'static') {
            $code = $notice['discount_static_code'];
        } elseif ($notice['discount_mode'] === 'generated') {
            $code = $existing['discount_code'] ?? self::generateUniqueCode($notice['discount_prefix'] ?: 'EB');
        }

        if (!$existing) {
            // تلاش برای وصل‌کردن به یک مشتری موجود (بر اساس شماره) — بدون ساختن هویت کانال جدید،
            // فقط برای این‌که بعداً در پروفایل مشتری این سرنخ دیده شود.
            $personId = null;
            try {
                $p = Database::one("SELECT id FROM eb_persons WHERE phone = ?", [$phone]);
                $personId = $p ? (int)$p['id'] : null;
            } catch (Throwable $e) { /* بی‌ضرر */ }

            Database::run(
                "INSERT INTO eb_notice_leads (notice_id, phone, discount_code, page_url, person_id, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [$noticeId, $phone, $code, mb_substr($pageUrl, 0, 500), $personId]
            );
            Database::run("UPDATE eb_notices SET leads_count = leads_count + 1 WHERE id = ?", [$noticeId]);
        }

        return [
            'ok' => true,
            'code' => $code,
            'headline' => $notice['success_headline'] ?: 'ممنون! کد تخفیفت اینه:',
            'note' => $notice['success_note'] ?: null,
        ];
    }

    /** یک کد یکتا با پیشوند دلخواه می‌سازد (تا ۱۰ بار تلاش برای جلوگیری از برخورد) */
    private static function generateUniqueCode(string $prefix): string
    {
        $prefix = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $prefix)) ?: 'EB';
        for ($i = 0; $i < 10; $i++) {
            $code = $prefix . '-' . strtoupper(bin2hex(random_bytes(3))); // مثل EB-9F3A2C
            $exists = Database::one("SELECT id FROM eb_notice_leads WHERE discount_code = ?", [$code]);
            if (!$exists) return $code;
        }
        return $prefix . '-' . strtoupper(bin2hex(random_bytes(4))); // تلاش آخر، عملاً برخورد غیرممکن است
    }

    /** لیست سرنخ‌های یک اعلان (یا همه، اگر $noticeId صفر باشد) برای نمایش در پنل */
    public static function leads(int $noticeId = 0, int $limit = 300): array
    {
        if (!self::hasTable()) return [];
        if ($noticeId > 0) {
            return Database::all(
                "SELECT l.*, n.title notice_title, p.full_name
                 FROM eb_notice_leads l
                 LEFT JOIN eb_notices n ON n.id = l.notice_id
                 LEFT JOIN eb_persons p ON p.id = l.person_id
                 WHERE l.notice_id = ? ORDER BY l.id DESC LIMIT ?",
                [$noticeId, $limit]
            );
        }
        return Database::all(
            "SELECT l.*, n.title notice_title, p.full_name
             FROM eb_notice_leads l
             LEFT JOIN eb_notices n ON n.id = l.notice_id
             LEFT JOIN eb_persons p ON p.id = l.person_id
             ORDER BY l.id DESC LIMIT ?",
            [$limit]
        );
    }

    /** برای api/export.php — بدون سقف تعداد */
    public static function leadsForExport(int $noticeId = 0): array
    {
        if (!self::hasTable()) return [];
        $sql = "SELECT l.created_at, n.title notice_title, l.phone, l.discount_code, l.page_url, p.full_name
                FROM eb_notice_leads l
                LEFT JOIN eb_notices n ON n.id = l.notice_id
                LEFT JOIN eb_persons p ON p.id = l.person_id";
        if ($noticeId > 0) {
            return Database::all($sql . " WHERE l.notice_id = ? ORDER BY l.id DESC", [$noticeId]);
        }
        return Database::all($sql . " ORDER BY l.id DESC");
    }

    private static $hasTable = null;
    public static function hasTable(): bool
    {
        if (self::$hasTable !== null) return self::$hasTable;
        try {
            self::$hasTable = Database::hasTable('eb_notices');
        } catch (Throwable $e) { self::$hasTable = false; }
        return self::$hasTable;
    }
}
