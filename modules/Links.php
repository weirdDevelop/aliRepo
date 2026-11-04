<?php
/**
 * modules/Links.php
 * لینک رهگیری: ساخت لینک کوتاه، ثبت کلیک، آمار.
 *
 * جریان: کاربر روی لینک کوتاه (r.php?c=CODE) کلیک می‌کند →
 * کلیک ثبت می‌شود → به مقصد ریدایرکت می‌شود.
 * اگر در URL پارامتر p=person_id باشد (در پیام شخصی‌سازی‌شده)، کلیک به آن شخص نسبت داده می‌شود.
 *
 * مقصد می‌تواند دو نوع باشد (مورد ۱):
 *   - آدرس وب: http(s)://…
 *   - شماره تماس: tel:+98…  → روی موبایل صفحه‌ی تماس با شماره‌ی آماده باز می‌شود.
 * طرح‌های دیگر عمداً رد می‌شوند (جلوگیری از open redirect خطرناک مثل javascript:/data:).
 *
 * نکته‌ی مهم: این فایل از r.php هم بارگذاری می‌شود که فقط Database را require می‌کند.
 * پس اینجا **نباید** به ماژول دیگری (مثل Persons) وابسته شویم.
 */

class Links
{
    /** طرح‌های مجاز برای مقصد */
    public const SCHEMES = ['http', 'https', 'tel'];

    /** دسته‌های پیشنهادی پیش‌فرض (مورد ۵) — کاربر می‌تواند دسته‌ی آزاد هم بنویسد */
    public const SUGGESTED = ['استوری', 'ریلز', 'پست', 'سایت', 'پیامک', 'بات', 'کمپین'];

    /**
     * فهرست لینک‌ها با فیلتر اختیاری.
     * @param array $f category (دسته، '__none__' = بدون دسته)، q (جستجو در عنوان/مقصد/کد)، kind (url|tel)
     */
    public static function all(array $f = []): array
    {
        $w = [];
        $p = [];

        if (self::hasCategory()) {
            $cat = trim((string)($f['category'] ?? ''));
            if ($cat === '__none__') {
                $w[] = "(category IS NULL OR category = '')";
            } elseif ($cat !== '') {
                $w[] = "category = ?";
                $p[] = $cat;
            }
        }

        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $w[] = "(title LIKE ? OR target_url LIKE ? OR code LIKE ?)";
            $like = '%' . $q . '%';
            $p[] = $like; $p[] = $like; $p[] = $like;
        }

        $kind = (string)($f['kind'] ?? '');
        if ($kind === 'tel')      { $w[] = "target_url LIKE 'tel:%'"; }
        elseif ($kind === 'url')  { $w[] = "target_url NOT LIKE 'tel:%'"; }

        $sql = "SELECT * FROM eb_links";
        if ($w) $sql .= " WHERE " . implode(' AND ', $w);
        $sql .= " ORDER BY created_at DESC, id DESC LIMIT 500";

        $rows = Database::all($sql, $p);
        foreach ($rows as &$r) { $r['kind'] = self::kindOf($r['target_url'] ?? ''); }
        unset($r);
        return $rows;
    }

    /** دسته‌های موجود (برای فیلتر و پیشنهاد) — مورد ۵ */
    public static function categories(): array
    {
        if (!self::hasCategory()) return [];
        $rows = Database::all(
            "SELECT category, COUNT(*) c FROM eb_links
             WHERE category IS NOT NULL AND category <> ''
             GROUP BY category ORDER BY c DESC, category ASC"
        );
        return array_map(fn($r) => ['name' => $r['category'], 'count' => (int)$r['c']], $rows);
    }

    public static function find(int $id): ?array
    {
        return Database::one("SELECT * FROM eb_links WHERE id = ?", [$id]);
    }

    public static function findByCode(string $code): ?array
    {
        return Database::one("SELECT * FROM eb_links WHERE code = ?", [$code]);
    }

    /** ساخت لینک جدید با کد یکتا */
    public static function create(string $targetUrl, ?string $title = null, ?string $category = null): array
    {
        $t = self::normalizeTarget($targetUrl);
        if (!$t['ok']) return $t;

        $code = self::uniqueCode();
        $cols = ['code', 'title', 'target_url'];
        $vals = [$code, self::clean($title, 250), $t['url']];
        if (self::hasCategory()) { $cols[] = 'category'; $vals[] = self::clean($category, 60); }

        Database::run(
            "INSERT INTO eb_links (" . implode(', ', $cols) . ")
             VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")",
            $vals
        );
        return ['ok' => true, 'id' => Database::lastId(), 'code' => $code, 'kind' => self::kindOf($t['url'])];
    }

    /**
     * ویرایش عنوان / مقصد / دسته (مورد ۴).
     * کد کوتاه هرگز عوض نمی‌شود — لینک‌های پخش‌شده باید کار کنند.
     * فیلدی که در ورودی نباشد، دست‌نخورده می‌ماند.
     */
    public static function update(int $id, array $in): array
    {
        $link = self::find($id);
        if (!$link) return ['ok' => false, 'error' => 'لینک یافت نشد'];

        $set = [];
        $p   = [];

        if (array_key_exists('title', $in)) {
            $set[] = "title = ?";
            $p[]   = self::clean($in['title'], 250);
        }
        if (array_key_exists('target_url', $in)) {
            $t = self::normalizeTarget((string)$in['target_url']);
            if (!$t['ok']) return $t;
            $set[] = "target_url = ?";
            $p[]   = $t['url'];
        }
        if (array_key_exists('category', $in) && self::hasCategory()) {
            $set[] = "category = ?";
            $p[]   = self::clean($in['category'], 60);
        }

        if (!$set) return ['ok' => false, 'error' => 'چیزی برای تغییر نبود'];

        $p[] = $id;
        Database::run("UPDATE eb_links SET " . implode(', ', $set) . " WHERE id = ?", $p);

        $fresh = self::find($id);
        return ['ok' => true, 'kind' => self::kindOf($fresh['target_url'] ?? '')];
    }

    public static function delete(int $id): void
    {
        Database::run("DELETE FROM eb_link_clicks WHERE link_id = ?", [$id]);
        Database::run("DELETE FROM eb_links WHERE id = ?", [$id]);
    }

    /**
     * ثبت یک کلیک و برگرداندن URL مقصد (یا null اگر لینک نبود).
     * @param array $meta اطلاعات بازدیدکننده (مورد ۱۱): ip, ua, referer
     */
    public static function recordClick(string $code, ?int $personId, array $meta = []): ?string
    {
        $link = self::findByCode($code);
        if (!$link) return null;

        try {
            $ua   = isset($meta['ua']) ? (string)$meta['ua'] : '';
            $cols = ['link_id', 'person_id', 'ip', 'user_agent'];
            $vals = [$link['id'], $personId, self::clean($meta['ip'] ?? null, 45), self::clean($ua, 250)];

            if (self::hasVisitorCols()) {
                $agent  = self::parseUa($ua);
                $cols[] = 'referer'; $vals[] = self::clean($meta['referer'] ?? null, 500);
                $cols[] = 'device';  $vals[] = $agent['device'];
                $cols[] = 'browser'; $vals[] = $agent['browser'];
                $cols[] = 'os';      $vals[] = $agent['os'];
            }

            Database::run(
                "INSERT INTO eb_link_clicks (" . implode(', ', $cols) . ")
                 VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")",
                $vals
            );
            Database::run("UPDATE eb_links SET clicks = clicks + 1 WHERE id = ?", [$link['id']]);
        } catch (Throwable $e) { /* ثبت کلیک نباید ریدایرکت را خراب کند */ }

        return $link['target_url'];
    }

    /** جزئیات یک لینک: خلاصه + لیست کلیک‌ها (با نام شخص اگر معلوم باشد) + تفکیک دستگاه/مرورگر/مرجع */
    public static function detail(int $id): ?array
    {
        $link = self::find($id);
        if (!$link) return null;

        $link['kind'] = self::kindOf($link['target_url'] ?? '');
        $link['click_list'] = Database::all(
            "SELECT c.*, p.full_name FROM eb_link_clicks c
             LEFT JOIN eb_persons p ON p.id = c.person_id
             WHERE c.link_id = ? ORDER BY c.clicked_at DESC LIMIT 200",
            [$id]
        );

        // آمار: کلیک یکتا بر اساس شخص
        $link['unique_persons'] = (int)(Database::one(
            "SELECT COUNT(DISTINCT person_id) c FROM eb_link_clicks WHERE link_id = ? AND person_id IS NOT NULL",
            [$id]
        )['c'] ?? 0);

        // بازدیدکننده‌ی یکتا بر اساس IP (شامل ناشناس‌ها)
        $link['unique_ips'] = (int)(Database::one(
            "SELECT COUNT(DISTINCT ip) c FROM eb_link_clicks WHERE link_id = ? AND ip IS NOT NULL",
            [$id]
        )['c'] ?? 0);

        // تفکیک‌ها (مورد ۱۱) — فقط اگر ستون‌ها بعد از db_upgrade موجود باشند
        $link['breakdown'] = ['device' => [], 'browser' => [], 'os' => [], 'referer' => []];
        if (self::hasVisitorCols()) {
            foreach (['device', 'browser', 'os'] as $f) {
                $link['breakdown'][$f] = Database::all(
                    "SELECT COALESCE(NULLIF({$f}, ''), 'نامعلوم') k, COUNT(*) c
                     FROM eb_link_clicks WHERE link_id = ?
                     GROUP BY k ORDER BY c DESC LIMIT 12",
                    [$id]
                );
            }
            $link['breakdown']['referer'] = Database::all(
                "SELECT COALESCE(NULLIF(referer, ''), 'مستقیم') k, COUNT(*) c
                 FROM eb_link_clicks WHERE link_id = ?
                 GROUP BY k ORDER BY c DESC LIMIT 12",
                [$id]
            );
        }
        return $link;
    }

    /**
     * کلیک‌های روزبه‌روز یک لینک، برای نمودار (مورد ۶) — لینک‌های رهگیری معمولاً
     * دائمی‌اند (مثلاً دکمه‌ی تماس)، پس عدد جمعیِ کل کمتر از روند روزانه به‌کار می‌آید.
     * @return array{days: string[], counts: int[]} روزهای بدون کلیک هم با صفر پر می‌شوند تا نمودار پیوسته باشد.
     */
    public static function dailyClicks(int $id, int $days = 30): array
    {
        $days = max(1, min(180, $days));
        $rows = Database::all(
            "SELECT DATE(clicked_at) d, COUNT(*) c FROM eb_link_clicks
             WHERE link_id = ? AND clicked_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(clicked_at)",
            [$id, $days - 1]
        );
        $byDay = [];
        foreach ($rows as $r) { $byDay[$r['d']] = (int)$r['c']; }

        $labels = []; $counts = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = $d;
            $counts[] = $byDay[$d] ?? 0;
        }
        return ['days' => $labels, 'counts' => $counts];
    }

    // ==================== مقصد: اعتبارسنجی و نرمال‌سازی ====================

    /**
     * ورودی خام کاربر را به یک مقصد معتبر تبدیل می‌کند (مورد ۱).
     * می‌پذیرد: http(s)://… · tel:… · شماره‌ی تلفن خام (۰۹۱۲…، ‎+۹۸۹۱۲…، ۰۲۱…) ·
     *           دامنه‌ی بدون طرح (example.com/x → https://example.com/x)
     * رد می‌کند: هر طرح دیگر (javascript:, data:, file:, …)
     * @return array{ok:bool, url?:string, error?:string}
     */
    public static function normalizeTarget(string $raw): array
    {
        $s = trim(self::toAsciiDigits($raw));
        // کاراکترهای کنترلی که می‌توانند اعتبارسنجی را دور بزنند.
        // نکته: با /u اگر ورودی UTF-8 نامعتبر باشد preg_replace مقدار null می‌دهد → با ?? '' مهار می‌شود.
        $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? '';
        $s = trim($s);
        if ($s === '') return ['ok' => false, 'error' => 'آدرس مقصد یا شماره تماس لازم است'];

        // ۱) صریحاً tel:
        if (preg_match('~^tel:~i', $s)) {
            return self::telTarget(substr($s, 4));
        }

        // ۲) صریحاً http(s)
        if (preg_match('~^https?://~i', $s)) {
            if (!preg_match('~^https?://[^\s/?#]+~i', $s)) {
                return ['ok' => false, 'error' => 'آدرس نامعتبر است'];
            }
            // فاصله در آدرس یعنی ورودی خراب است (مثلاً بعد از حذف کاراکتر کنترلی).
            // آدرس معتبر فاصله‌ی خام ندارد.
            if (preg_match('/\s/', $s)) {
                return ['ok' => false, 'error' => 'آدرس نباید فاصله داشته باشد'];
            }
            return ['ok' => true, 'url' => $s];
        }

        // ۳) طرح دیگری صریحاً داده شده؟ رد (جلوگیری از javascript:/data:/…)
        if (preg_match('~^([a-z][a-z0-9+.\-]*):~i', $s, $m)) {
            $sch = strtolower($m[1]);
            if (!in_array($sch, self::SCHEMES, true)) {
                return ['ok' => false, 'error' => "طرح «{$sch}:» مجاز نیست. فقط آدرس وب یا شماره تماس."];
            }
        }

        // ۴) فقط رقم و نشانه‌های تلفن → شماره تماس
        if (preg_match('~^[+()\-\s0-9]+$~', $s)) {
            return self::telTarget($s);
        }

        // ۵) دامنه‌ی بدون طرح → https اضافه می‌کنیم (راحتی کاربر)
        if (preg_match('~^[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}([/?#].*)?$~i', $s)) {
            return ['ok' => true, 'url' => 'https://' . $s];
        }

        return ['ok' => false, 'error' => 'آدرس باید با http:// یا https:// شروع شود، یا یک شماره تماس معتبر باشد'];
    }

    /** ساخت مقصد tel: از یک شماره‌ی خام */
    private static function telTarget(string $raw): array
    {
        $d = self::toAsciiDigits($raw);
        $plus = (strpos($d, '+') === 0);
        $d = preg_replace('/\D+/', '', $d);   // فقط رقم
        if ($d === '') return ['ok' => false, 'error' => 'شماره تماس معتبر نیست'];

        // نرمال‌سازی ایران: 09xx→98…، 9xx→98…، 0098→98
        if (strpos($d, '0098') === 0)      $d = substr($d, 2);
        elseif (strpos($d, '098') === 0)   $d = '98' . substr($d, 3);
        elseif (strpos($d, '09') === 0)    $d = '98' . substr($d, 1);
        elseif (preg_match('/^9\d{9}$/', $d)) $d = '98' . $d;

        // شماره‌ی ثابت داخلی با کد شهر (021…) بدون + می‌ماند تا شماره‌گیر محلی درست عمل کند
        $isIntl = $plus || strpos($d, '98') === 0 || strlen($d) > 11;
        if (strlen($d) < 4 || strlen($d) > 15) {
            return ['ok' => false, 'error' => 'شماره تماس معتبر نیست'];
        }
        return ['ok' => true, 'url' => 'tel:' . ($isIntl ? '+' : '') . $d];
    }

    /** نوع مقصد: 'tel' یا 'url' */
    public static function kindOf(string $target): string
    {
        return preg_match('~^tel:~i', trim($target)) ? 'tel' : 'url';
    }

    /** شماره‌ی خوانا برای نمایش (از مقصد tel:) */
    public static function telDisplay(string $target): string
    {
        return preg_match('~^tel:~i', $target) ? substr(trim($target), 4) : '';
    }

    /**
     * آیا ریدایرکت به این مقصد مجاز است؟ (دفاع لایه‌دوم در r.php)
     * ردیف‌هایی که پیش از این اعتبارسنجی ساخته شده‌اند هم از این فیلتر می‌گذرند.
     * بررسی کاراکتر کنترلی لازم است: `\r\n` در مقدار، هدر Location را می‌شکند.
     */
    public static function isAllowedTarget(string $target): bool
    {
        $t = trim($target);
        if (preg_match('/[\x00-\x1F\x7F]/', $t)) return false;
        return (bool)preg_match('~^(https?://|tel:)~i', $t);
    }

    // ==================== اطلاعات بازدیدکننده (مورد ۱۱) ====================

    /**
     * IP واقعی بازدیدکننده. اگر سایت پشت CDN/پروکسی باشد، REMOTE_ADDR آدرس پروکسی است.
     * @param bool $trustProxy فقط وقتی true باشد هدرها خوانده می‌شوند (هدر قابل جعل است).
     */
    public static function clientIp(bool $trustProxy = false): ?string
    {
        if ($trustProxy) {
            $fwd = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            foreach (explode(',', (string)$fwd) as $cand) {
                $cand = trim($cand);
                if ($cand !== '' && filter_var($cand, FILTER_VALIDATE_IP)) return $cand;
            }
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return ($ip !== null && $ip !== '') ? $ip : null;
    }

    /**
     * تجزیه‌ی User-Agent به دستگاه/مرورگر/سیستم‌عامل.
     * بدون کتابخانه (پروژه composer ندارد) — الگوهای رایج، ترتیب مهم است.
     * @return array{device:string, browser:string, os:string}
     */
    public static function parseUa(?string $ua): array
    {
        $ua = trim((string)$ua);
        if ($ua === '') return ['device' => 'نامعلوم', 'browser' => 'نامعلوم', 'os' => 'نامعلوم'];
        $l = strtolower($ua);

        // --- ربات‌ها اول (وگرنه به‌عنوان مرورگر شناسایی می‌شوند) ---
        if (preg_match('/(bot|crawler|spider|preview|fetcher|curl|wget|python-requests|okhttp|headless)/i', $l)) {
            $b = 'ربات/خزنده';
            if (strpos($l, 'telegrambot') !== false) $b = 'پیش‌نمایش تلگرام';
            elseif (strpos($l, 'balebot') !== false || strpos($l, 'bale') !== false) $b = 'پیش‌نمایش بله';
            elseif (strpos($l, 'whatsapp') !== false) $b = 'پیش‌نمایش واتساپ';
            elseif (strpos($l, 'googlebot') !== false) $b = 'گوگل‌بات';
            return ['device' => 'ربات', 'browser' => $b, 'os' => 'نامعلوم'];
        }

        // --- سیستم‌عامل ---
        $os = 'نامعلوم';
        if (preg_match('/android[ \/]?([0-9.]+)?/i', $l, $m))            $os = 'اندروید' . (!empty($m[1]) ? ' ' . $m[1] : '');
        elseif (preg_match('/(iphone|ipad|ipod).*os ([0-9_]+)/i', $l, $m)) $os = 'iOS ' . str_replace('_', '.', $m[2]);
        elseif (strpos($l, 'iphone') !== false || strpos($l, 'ipad') !== false) $os = 'iOS';
        elseif (strpos($l, 'windows nt 10') !== false)                    $os = 'ویندوز ۱۰/۱۱';
        elseif (strpos($l, 'windows') !== false)                          $os = 'ویندوز';
        elseif (strpos($l, 'mac os x') !== false)                         $os = 'مک';
        elseif (strpos($l, 'cros') !== false)                             $os = 'کروم‌بوک';
        elseif (strpos($l, 'linux') !== false)                            $os = 'لینوکس';

        // --- مرورگر (ترتیب حساس: اج و اپرا خود را کروم هم معرفی می‌کنند) ---
        $br = 'نامعلوم';
        if (preg_match('/(edg|edge|edga|edgios)\/?([0-9.]+)?/i', $l, $m))      $br = 'اج';
        elseif (preg_match('/(opr|opera|opt)\//i', $l))                       $br = 'اپرا';
        elseif (strpos($l, 'samsungbrowser') !== false)                       $br = 'سامسونگ';
        elseif (strpos($l, 'ucbrowser') !== false)                            $br = 'UC';
        elseif (strpos($l, 'fxios') !== false || strpos($l, 'firefox') !== false) $br = 'فایرفاکس';
        elseif (strpos($l, 'crios') !== false)                                $br = 'کروم';
        elseif (strpos($l, 'chrome') !== false)                               $br = 'کروم';
        elseif (strpos($l, 'safari') !== false)                               $br = 'سافاری';
        elseif (strpos($l, 'msie') !== false || strpos($l, 'trident') !== false) $br = 'اینترنت اکسپلورر';

        // --- نوع دستگاه ---
        $device = 'رایانه';
        if (strpos($l, 'ipad') !== false || strpos($l, 'tablet') !== false
            || (strpos($l, 'android') !== false && strpos($l, 'mobile') === false)) {
            $device = 'تبلت';
        } elseif (preg_match('/(mobi|iphone|ipod|android|windows phone|blackberry)/i', $l)) {
            $device = 'موبایل';
        }

        return ['device' => $device, 'browser' => $br, 'os' => $os];
    }

    // ==================== کمکی ====================

    /** ارقام فارسی/عربی → انگلیسی (کاربر معمولاً شماره را فارسی می‌چسباند) */
    public static function toAsciiDigits(string $s): string
    {
        return str_replace(
            ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩','،'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9',','],
            $s
        );
    }

    /** trim + کوتاه‌سازی + تبدیل رشته‌ی خالی به null */
    private static function clean($v, int $max): ?string
    {
        if ($v === null) return null;
        $v = trim((string)$v);
        if ($v === '') return null;
        return mb_substr($v, 0, $max);
    }

    private static function uniqueCode(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $code = substr(str_replace(['+','/','='], '', base64_encode(random_bytes(6))), 0, 7);
            if (!self::findByCode($code)) return $code;
        }
        return substr(md5(uniqid('', true)), 0, 8);
    }

    // ---- گارد migration: ستون‌های تازه ممکن است قبل از db_upgrade نباشند ----

    /** @var bool|null */
    private static $hasCategory = null;
    /** @var bool|null */
    private static $hasVisitor = null;

    private static function hasCategory(): bool
    {
        if (self::$hasCategory === null) {
            try {
                self::$hasCategory = Database::hasColumn('eb_links', 'category');
            } catch (Throwable $e) { self::$hasCategory = false; }
        }
        return self::$hasCategory;
    }

    private static function hasVisitorCols(): bool
    {
        if (self::$hasVisitor === null) {
            try {
                self::$hasVisitor = Database::hasColumn('eb_link_clicks', 'device');
            } catch (Throwable $e) { self::$hasVisitor = false; }
        }
        return self::$hasVisitor;
    }
}
