<?php
/**
 * modules/Webchat.php
 * منطق «گفتگوی آنلاین سایت» (لایوچت) — مورد ۱۳ دور بازخورد.
 *
 * سه بازیگر:
 *   ۱) ویجت در سایت مشتری  → با `chat.php` حرف می‌زند (بدون لاگین، بدون CSRF).
 *   ۲) این ماژول            → نشست بازدیدکننده، ثبت پیام، اجرای زنجیره‌ی خودکار.
 *   ۳) پنل (صندوق ورودی)   → پاسخ اپراتور، مثل هر کانال دیگر از BotReply/Sender می‌رود.
 *
 * قرارداد یگانه‌ی حقیقت: تاریخچه فقط در `eb_messages` است. ویجت پیام‌های
 * `direction='out'` را با poll می‌گیرد. `eb_webchat_outbox` فقط رویدادهای کنترلی
 * (اعلان سیستمی) را نگه می‌دارد تا پیام‌ها دوبار دیده نشوند.
 *
 * channel_uid = توکن ۳۲ کاراکتری نشست که در localStorage مرورگر بازدیدکننده می‌ماند.
 */

class Webchat
{
    /** ۱.۰.۱۴: ستون دکمه/کارت پیام هست؟ */
    private static $payloadCol = null;
    private static function hasPayloadCol(): bool
    {
        if (self::$payloadCol === null) {
            try { self::$payloadCol = class_exists('ChatPlus') ? ChatPlus::hasPayload() : Database::hasColumn('eb_messages', 'payload_json'); }
            catch (Throwable $e) { self::$payloadCol = false; }
        }
        return self::$payloadCol;
    }

    /** دکمه‌ها/کارت‌های ذخیره‌شده‌ی یک پیام برای ویجت */
    private static function payloadOf(array $r): ?array
    {
        if (empty($r['payload_json'])) return null;
        $p = json_decode((string)$r['payload_json'], true);
        return is_array($p) && $p ? $p : null;
    }

    /** حداکثر پیام از یک IP در یک ساعت (ضدهرزنامه) */
    private const IP_HOURLY_LIMIT = 60;

    /** پیش‌فرض تنظیمات — کلیدها در eb_settings با پیشوند `webchat.` */
    private const DEFAULTS = [
        'enabled'       => '0',
        'title'         => 'گفتگو با پشتیبانی',
        'subtitle'      => 'معمولاً در چند دقیقه پاسخ می‌دهیم',
        'welcome'       => 'سلام! چطور می‌توانم کمکتان کنم؟',
        'offline_text'  => 'الان خارج از ساعت کاری هستیم؛ پیامتان را بگذارید، پاسخ می‌دهیم.',
        // 'open'         → بدون فرم؛ بعد از اولین پاسخ، کارت نام/شماره پیشنهاد می‌شود
        // 'form'         → فرم نام و موبایل پیش از باز شدن پنجره‌ی چت
        // 'require_phone'→ تا شماره ندهد پیام ارسال نمی‌شود
        'start_mode'    => 'open',
        'color'         => '',               // خالی = از رنگ برند پنل ارث می‌برد
        'position'      => 'right',          // right | left
        'ask_name'      => '1',
        'ask_phone'     => '1',
        'allowed_hosts' => '',               // خط‌به‌خط؛ خالی = هر دامنه‌ای
        'poll_seconds'  => '4',
        // مورد ۷: تو گوشی خیلی از قالب‌ها یک نوار منوی ثابت پایین صفحه دارند که
        // دکمه‌ی گفتگو (bottom:20px پیش‌فرض) روش می‌افتد. این فاصله را بالاتر می‌برد.
        'mobile_bottom_offset' => '78',      // پیکسل
        // ==== سفارشی‌سازی ظاهر (۰ تا ۱۰۰) ====
        'font_family'   => '',               // خالی = فونت پیش‌فرض ویجت (Vazirmatn)
        'bg_color'      => '',               // پس‌زمینه‌ی پنل گفتگو؛ خالی = سفید
        'text_color'    => '',               // رنگ متن پیام‌های اپراتور؛ خالی = مشکی پیش‌فرض
        'border_radius' => '16',             // شعاع گوشه‌ی پنل (px) — صفر = کاملاً چهارگوش
        'launcher_icon' => '',               // آدرس عکس دکمه‌ی شناور؛ خالی = آیکون پیش‌فرض
        'launcher_size' => '58',             // px — مورد ۱۱: «کم‌زیاد» اندازه‌ی دکمه‌ی شناور
    ];

    // ---------------------------------------------------------------- تنظیمات

    /** همه‌ی تنظیمات با اعمال پیش‌فرض‌ها */
    public static function settings(): array
    {
        $out = self::DEFAULTS;
        $pf = class_exists('SiteCtx') ? SiteCtx::pfx() : '';   // ۲.۱.۱: تنظیمات هر سایت جدا (سایت اصلی: بدون پیشوند)
        try {
            $rows = Database::all("SELECT k, v FROM eb_settings WHERE k LIKE ?", [$pf . 'webchat.%']);
        } catch (Throwable $e) {
            return $out;
        }
        $cut = strlen($pf) + 8;
        foreach ($rows as $r) {
            $key = substr((string)$r['k'], $cut);   // حذف پیشوند 'webchat.'
            if ($key !== '') $out[$key] = (string)$r['v'];
        }
        return $out;
    }

    /** ذخیره‌ی تنظیمات (فقط کلیدهای شناخته‌شده) */
    public static function save(array $values): void
    {
        foreach ($values as $k => $v) {
            if (!array_key_exists($k, self::DEFAULTS)) continue;
            Database::run(
                "INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
                [(class_exists('SiteCtx') ? SiteCtx::pfx() : '') . 'webchat.' . $k, (string)$v]
            );
        }
    }

    public static function isEnabled(): bool
    {
        return self::settings()['enabled'] === '1';
    }

    /**
     * تنظیماتی که به ویجت (عمومی) داده می‌شود — هیچ چیز حساسی نباید اینجا باشد.
     * رنگ و نام و لوگو از تنظیمات برند پنل ارث می‌رسد تا یک جا تنظیم، همه‌جا هماهنگ.
     */
    public static function publicSettings(): array
    {
        $s = self::settings();
        return [
            'title'       => $s['title'],
            'subtitle'    => $s['subtitle'],
            'welcome'     => $s['welcome'],
            'color'       => self::accent(),
            'brandName'   => self::brand('name'),
            'logo'        => self::brand('logo'),
            'position'    => $s['position'],
            'startMode'   => self::startMode(),
            'askName'     => $s['ask_name'] === '1',
            'askPhone'    => $s['ask_phone'] === '1',
            'pollSeconds' => max(2, (int)$s['poll_seconds']),
            'online'      => self::isOnline(),
            'offlineText' => $s['offline_text'],
            'mobileBottomOffset' => max(0, min(200, (int)$s['mobile_bottom_offset'])),
            'fontFamily'  => trim($s['font_family']),
            'bgColor'     => trim($s['bg_color']),
            'textColor'   => trim($s['text_color']),
            'borderRadius'=> max(0, min(28, (int)$s['border_radius'])),
            'launcherIcon'=> trim($s['launcher_icon']),
            'launcherSize'=> max(40, min(88, (int)$s['launcher_size'])),
            // ۱.۰.۱۴: تماس سریع با پشتیبان و فرم درخواست تماس
            'call'        => class_exists('ChatPlus') ? ChatPlus::publicCall() : null,
        ];
    }

    /** حالت شروع گفتگو (قابل تنظیم در پنل) */
    public static function startMode(): string
    {
        $m = self::settings()['start_mode'];
        return in_array($m, ['open', 'form', 'require_phone'], true) ? $m : 'open';
    }

    /** رنگ ویجت: اگر رنگ اختصاصی خالی باشد، رنگ برند پنل */
    public static function accent(): string
    {
        $c = trim((string)self::settings()['color']);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $c)) return $c;
        $b = self::brand('accent');
        return preg_match('/^#[0-9a-fA-F]{6}$/', $b) ? $b : '#1d4ed8';
    }

    /** خواندن یک کلید برند با fallback بی‌خطر (اگر ماژول برند نبود) */
    private static function brand(string $key): string
    {
        if (class_exists('Brand') && method_exists('Brand', 'get')) {
            try { return (string)Brand::get($key); } catch (Throwable $e) { return ''; }
        }
        return '';
    }

    /**
     * آیا تیم پشتیبانی الان آنلاین است؟ از همان ساعت کاریِ ماژول پشتیبانی
     * استفاده می‌کنیم تا دو جای متفاوت دو جواب ندهند. اگر آن ماژول نبود، آنلاین.
     */
    public static function isOnline(): bool
    {
        // ۱.۰.۱۱: دستیار فروش گفتگوی سایت را ۲۴ ساعته جواب می‌دهد؛ «خارج از ساعت کاری» نشان داده نشود
        if (class_exists('SalesAgent')) { try { if (SalesAgent::enabled() && SalesAgent::channelOn('webchat')) return true; } catch (Throwable $e) {} }
        if (class_exists('Support') && method_exists('Support', 'isOnline')) {
            try { return (bool)Support::isOnline(); } catch (Throwable $e) { return true; }
        }
        return true;
    }

    /**
     * بررسی دامنه‌ی مجاز. خالی بودن فهرست = همه مجاز (برای شروع راحت‌تر است).
     * مقایسه روی host انجام می‌شود، نه کل URL، تا http/https و مسیر مهم نباشد.
     */
    public static function originAllowed(?string $origin): bool
    {
        $list = array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)self::settings()['allowed_hosts'])));
        if (!$list) return true;
        if (!$origin) return false;

        $host = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?: $origin));
        $host = preg_replace('/^www\./', '', $host);
        foreach ($list as $item) {
            $ih = strtolower((string)(parse_url($item, PHP_URL_HOST) ?: $item));
            $ih = preg_replace('/^www\./', '', $ih);
            if ($ih !== '' && $ih === $host) return true;
        }
        return false;
    }

    // ------------------------------------------------------- نشست بازدیدکننده

    /** توکن نشست معتبر است؟ (۳۲ هگز) */
    public static function validToken(?string $token): bool
    {
        return is_string($token) && (bool)preg_match('/^[a-f0-9]{32}$/', $token);
    }

    /**
     * ساخت نشست تازه برای بازدیدکننده و برگرداندن توکن.
     * $meta: ['page_url'=>..., 'referer'=>..., 'ua'=>...]
     */
    public static function startSession(array $meta = []): string
    {
        $token = bin2hex(random_bytes(16));
        $ua    = mb_substr((string)($meta['ua'] ?? ''), 0, 255);
        $ag    = self::parseAgent($ua);

        $siteCol = class_exists('SiteCtx') && Database::hasColumn('eb_webchat_sessions', 'site_id');   // ۲.۱.۱
        Database::run(
            "INSERT INTO eb_webchat_sessions (token, ip, user_agent, page_url, referer, device, browser, os" . ($siteCol ? ', site_id' : '') . ")
             VALUES (?, ?, ?, ?, ?, ?, ?, ?" . ($siteCol ? ', ?' : '') . ")",
            array_merge([
                $token,
                self::clientIp(),
                $ua,
                mb_substr((string)($meta['page_url'] ?? ''), 0, 500),
                mb_substr((string)($meta['referer']  ?? ''), 0, 500),
                $ag['device'], $ag['browser'], $ag['os'],
            ], $siteCol ? [SiteCtx::rowId()] : [])
        );
        return $token;
    }

    /** نشست موجود را برگردان؛ اگر نبود null */
    public static function session(string $token): ?array
    {
        if (!self::validToken($token)) return null;
        try {
            return Database::one("SELECT * FROM eb_webchat_sessions WHERE token = ? LIMIT 1", [$token]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** «هنوز اینجاست» — last_seen و صفحه‌ی جاری را به‌روز کن */
    public static function touch(string $token, ?string $pageUrl = null): void
    {
        if (!self::validToken($token)) return;
        try {
            if ($pageUrl !== null && $pageUrl !== '') {
                Database::run(
                    "UPDATE eb_webchat_sessions SET last_seen = NOW(), page_url = ? WHERE token = ?",
                    [mb_substr($pageUrl, 0, 500), $token]
                );
            } else {
                Database::run("UPDATE eb_webchat_sessions SET last_seen = NOW() WHERE token = ?", [$token]);
            }
        } catch (Throwable $e) { /* بی‌صدا */ }
    }

    /** IP واقعی بازدیدکننده (با احتیاط: هدرهای پروکسی قابل جعل‌اند، فقط برای آمار/سقف) */
    public static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', (string)$_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }

    /** تشخیص ساده‌ی دستگاه/مرورگر/سیستم از User-Agent (بدون کتابخانه) */
    public static function parseAgent(string $ua): array
    {
        $u = strtolower($ua);
        $device = (strpos($u, 'mobi') !== false || strpos($u, 'android') !== false) ? 'mobile'
                : ((strpos($u, 'tablet') !== false || strpos($u, 'ipad') !== false) ? 'tablet' : 'desktop');

        $browser = 'نامشخص';
        foreach ([['edg', 'Edge'], ['opr', 'Opera'], ['chrome', 'Chrome'], ['safari', 'Safari'], ['firefox', 'Firefox']] as $b) {
            if (strpos($u, $b[0]) !== false) { $browser = $b[1]; break; }
        }

        $os = 'نامشخص';
        foreach ([['windows', 'Windows'], ['android', 'Android'], ['iphone', 'iOS'], ['ipad', 'iOS'],
                  ['mac os', 'macOS'], ['linux', 'Linux']] as $o) {
            if (strpos($u, $o[0]) !== false) { $os = $o[1]; break; }
        }
        return ['device' => $device, 'browser' => $browser, 'os' => $os];
    }

    /** سقف پیام برای یک IP در ساعت گذشته (ضدهرزنامه) */
    public static function ipRateExceeded(): bool
    {
        // ۱.۰.۱۴: IP مشتری‌ای که پشتیبان دستی رفع فیلترش کرده، تا ۲۴ ساعت معاف است
        if (class_exists('ChatPlus') && ChatPlus::ipAllowed(self::clientIp())) return false;
        try {
            $row = Database::one(
                "SELECT COALESCE(SUM(msg_count),0) AS c FROM eb_webchat_sessions
                 WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                [self::clientIp()]
            );
            return ((int)($row['c'] ?? 0)) >= self::IP_HOURLY_LIMIT;
        } catch (Throwable $e) {
            return false;   // fail-open: گم‌کردن پیام مشتری بدتر از یک پیام اضافه است
        }
    }

    // ------------------------------------------------------- دریافت پیام (in)

    /**
     * پیام بازدیدکننده را ثبت می‌کند و زنجیره‌ی خودکار را اجرا می‌کند.
     * ترتیب پردازش عیناً همان webhook است: فرم → منو → پاسخ خودکار → AI.
     *
     * $payload: ['token','text','name','phone','email','page_url']
     * خروجی: ['ok'=>bool, 'error'=>?string, 'person_id'=>?int]
     */
    public static function ingest(array $payload): array
    {
        $token = (string)($payload['token'] ?? '');
        if (!self::validToken($token)) {
            return ['ok' => false, 'error' => 'نشست نامعتبر است', 'person_id' => null];
        }
        $sess = self::session($token);
        if (!$sess) {
            return ['ok' => false, 'error' => 'نشست پیدا نشد؛ صفحه را تازه کنید', 'person_id' => null];
        }
        // ۲.۱.۱: بات‌ساز/پاسخ خودکار/دستیار همین گفتگو طبق سایتِ خودش
        if (class_exists('SiteCtx') && array_key_exists('site_id', $sess)) SiteCtx::set($sess['site_id'] ? (int)$sess['site_id'] : null);

        $text = trim((string)($payload['text'] ?? ''));
        if ($text === '') {
            return ['ok' => false, 'error' => 'متن پیام خالی است', 'person_id' => null];
        }
        if (mb_strlen($text) > 2000) $text = mb_substr($text, 0, 2000);

        if (self::ipRateExceeded()) {
            return ['ok' => false, 'error' => 'تعداد پیام‌های شما زیاد است؛ کمی بعد تلاش کنید', 'person_id' => null];
        }

        $phone = trim((string)($payload['phone'] ?? ''));
        if (self::startMode() === 'require_phone' && $phone === '' && empty($sess['identified'])) {
            return ['ok' => false, 'error' => 'برای ادامه‌ی گفتگو شماره‌ی موبایل لازم است', 'person_id' => null];
        }
        // ۱.۰.۱۴: شماره‌ی نامعتبر/ناقص به‌عنوان شماره‌ی معتبر ثبت نمی‌شود؛ قالب ذخیره 09…
        if ($phone !== '') {
            $norm = Persons::normalizePhone($phone);
            if ($norm === null) return ['ok' => false, 'error' => 'شماره‌ی موبایل معتبر نیست؛ مثل 09121234567 وارد کنید', 'person_id' => null];
            $phone = $norm;
        }
        $cb = (string)($payload['cb'] ?? '');
        if ($cb !== '' && !preg_match('/^nav:[A-Za-z0-9_\-]{1,60}$/', $cb)) $cb = '';

        // ---- نرمال‌سازی با درایور (همان قالب مشترک کانال‌ها) ----
        $driver = new WebchatDriver();
        $event  = $driver->handleInbound([
            'token' => $token,
            'text'  => $text,
            'name'  => (string)($payload['name'] ?? ''),
            'phone' => $phone,
        ]);
        if (!$event) {
            return ['ok' => false, 'error' => 'پیام نامعتبر', 'person_id' => null];
        }

        $personId = null;
        try {
            // ثبت/merge در بانک مشتری (شماره کلید merge است — هویت طلایی)
            $personId = Persons::ingestIdentity('webchat', $event);

            // مورد ۴: مشتری بلاک‌شده — نه پیام ثبت شود، نه شمارنده/صفحه‌ی نشست آپدیت شود.
            if ($personId && class_exists('Inbox') && Inbox::isBlocked($personId)) {
                return ['ok' => true, 'error' => null, 'person_id' => $personId];
            }

            $identity = Database::one(
                "SELECT id FROM eb_identities WHERE channel = 'webchat' AND channel_uid = ?",
                [$token]
            );
            if ($identity) {
                Database::run(
                    "INSERT INTO eb_messages (identity_id, channel, direction, body, status" . (self::hasPageUrlCol() ? ", page_url" : "") . ")
                     VALUES (?, 'webchat', 'in', ?, 'received'" . (self::hasPageUrlCol() ? ", ?" : "") . ")",
                    self::hasPageUrlCol()
                        ? [$identity['id'], $text, mb_substr((string)($payload['page_url'] ?? ''), 0, 500)]
                        : [$identity['id'], $text]
                );

                // مورد ۲: نوتیف به پشتیبان — پیام تازه از گفتگوی آنلاین سایت
                if (class_exists('Notifier')) {
                    $who = null;
                    if ($personId) {
                        try { $who = Database::one("SELECT full_name FROM eb_persons WHERE id=?", [$personId])['full_name'] ?? null; }
                        catch (Throwable $e) { /* بی‌اثر */ }
                    }
                    Notifier::push(
                        'message',
                        'پیام تازه از ' . ($who ?: 'بازدیدکننده‌ی سایت') . ' (گفتگوی سایت)',
                        mb_substr($text, 0, 200),
                        $personId ? "index.php?page=inbox&person={$personId}" : "index.php?page=inbox",
                        $personId
                    );
                }
            }

            // شمارنده و پیوند نشست ↔ شخص (برای فهرست نشست‌ها در پنل)
            Database::run(
                "UPDATE eb_webchat_sessions
                    SET msg_count = msg_count + 1, last_seen = NOW(),
                        person_id = ?, identity_id = ?,
                        identified = CASE WHEN ? <> '' THEN 1 ELSE identified END,
                        page_url = COALESCE(NULLIF(?, ''), page_url)
                  WHERE token = ?",
                [
                    $personId ?: null,
                    $identity['id'] ?? null,
                    $phone,
                    mb_substr((string)($payload['page_url'] ?? ''), 0, 500),
                    $token,
                ]
            );

            if (empty($payload['skip_auto'])) self::runAutomation($token, $text, $personId, $cb);
        } catch (Throwable $e) {
            // پیام مشتری از دست نرود: خطا را لاگ کن ولی ok بده
            if (class_exists('ApiLog')) {
                try { ApiLog::record('webchat', 'ingest', ['text' => mb_substr($text, 0, 300)], null, null, false, $e->getMessage()); }
                catch (Throwable $ignored) { }
            }
            return ['ok' => true, 'error' => null, 'person_id' => $personId];
        }

        return ['ok' => true, 'error' => null, 'person_id' => $personId];
    }

    /**
     * زنجیره‌ی پاسخ خودکار — دقیقاً با اولویت webhook.
     * جدا شده تا اگر فردا اولویت عوض شد، یک جا عوض شود.
     */
    private static function runAutomation(string $token, string $text, ?int $personId, string $cb = ''): void
    {
        $handled = false;

        // ۱.۰.۱۴: کلیک روی دکمه‌ی منوی بات در ویجت (همان callback «nav:…» بله/تلگرام)
        if ($cb !== '' && class_exists('BotMenu')) {
            $handled = (bool)BotMenu::handle('webchat', $token, $text, $cb);
            if ($handled) return;
        }

        // لوکاپ خودکار اقامتگاه، حتی قبل از فرم — همان اولویت webhook/receive.php
        if (class_exists('PropertyLookup')) {
            $handled = (bool)PropertyLookup::handleIncoming('webchat', $token, $text);
        }
        if (!$handled && class_exists('Forms')) {
            $handled = (bool)Forms::handle('webchat', $token, $text, $personId);
        }
        if (!$handled && class_exists('BotMenu')) {
            $handled = (bool)BotMenu::handle('webchat', $token, $text, null);
        }
        if (!$handled && class_exists('AutoReply')) {
            $handled = (bool)AutoReply::handle('webchat', $token, $text);
        }
        // AI آخرین حلقه است و فقط پیشنهاد/پاسخ خارج از ساعت کاری می‌دهد
        // (اصل قطعی: در ساعت کاری، دکمه‌ی ارسال دست اپراتور است).
        if (!$handled && class_exists('Ai')) {
            Ai::autoReply('webchat', $token, $personId, $text);
        }
    }

    // -------------------------------------------------------- ارسال پیام (out)

    /** @var bool|null کش: آیا ستون eb_messages.page_url وجود دارد؟ (مورد ۱: صفحه‌ی ارسال هر پیام) */
    private static $hasPageUrlCol = null;
    public static function hasPageUrlCol(): bool
    {
        if (self::$hasPageUrlCol !== null) return self::$hasPageUrlCol;
        try {
            self::$hasPageUrlCol = Database::hasColumn('eb_messages', 'page_url');
        } catch (Throwable $e) { self::$hasPageUrlCol = false; }
        return self::$hasPageUrlCol;
    }

    /** @var bool|null کش: آیا ستون eb_messages.seen_at وجود دارد؟ (مورد ۵: تیک خوانده‌شده) */
    private static $hasSeenAt = null;
    public static function hasSeenAt(): bool
    {
        if (self::$hasSeenAt !== null) return self::$hasSeenAt;
        try {
            self::$hasSeenAt = Database::hasColumn('eb_messages', 'seen_at');
        } catch (Throwable $e) { self::$hasSeenAt = false; }
        return self::$hasSeenAt;
    }

    /**
     * علامت‌زدن پیام‌های خروجی این نشست به‌عنوان «دیده‌شده».
     * فقط وقتی صدا زده می‌شود که ویجت واقعاً باز/فعال است (نه هر poll پس‌زمینه‌ای)،
     * تا تیک آبی معنای واقعی «دیده شد» را داشته باشد، نه فقط «به مرورگر رسید».
     */
    public static function markSeen(string $token): void
    {
        if (!self::hasSeenAt()) return;
        if (!self::validToken($token)) return;
        try {
            $identity = Database::one(
                "SELECT id FROM eb_identities WHERE channel = 'webchat' AND channel_uid = ?",
                [$token]
            );
            if (!$identity) return;
            Database::run(
                "UPDATE eb_messages SET seen_at = NOW()
                 WHERE identity_id = ? AND direction = 'out' AND seen_at IS NULL
                   AND status NOT IN ('failed','error')",
                [$identity['id']]
            );
        } catch (Throwable $e) { /* بی‌صدا — تیک خوانده‌شده حیاتی نیست */ }
    }

    /**
     * پیام‌های تازه‌ی سمت پنل/بات برای این نشست.
     * منبع یگانه: eb_messages با direction='out'. `after` آخرین id دیده‌شده است.
     */
    public static function poll(string $token, int $after = 0): array
    {
        if (!self::validToken($token)) {
            return ['ok' => false, 'error' => 'نشست نامعتبر', 'messages' => [], 'events' => [], 'last_id' => $after];
        }
        self::touch($token);

        $identity = Database::one(
            "SELECT id, person_id FROM eb_identities WHERE channel = 'webchat' AND channel_uid = ?",
            [$token]
        );
        if (!$identity) {
            return ['ok' => true, 'error' => null, 'messages' => [], 'events' => [], 'last_id' => $after];
        }

        $rows = Database::all(
            "SELECT id, body, status, media_type, media_path, created_at, sent_by" . (Database::hasColumn('eb_messages', 'source') ? ", source" : "") . (self::hasPayloadCol() ? ", payload_json" : "") . "
               FROM eb_messages
              WHERE identity_id = ? AND direction = 'out' AND id > ?
              ORDER BY id ASC LIMIT 30",
            [$identity['id'], $after]
        );

        $messages = [];
        $lastId   = $after;
        foreach ($rows as $r) {
            $lastId = max($lastId, (int)$r['id']);
            // پیام‌های ناموفق را به بازدیدکننده نشان نده
            if (in_array((string)$r['status'], ['failed', 'error'], true)) continue;
            $messages[] = [
                'id'    => (int)$r['id'],
                'text'  => (string)$r['body'],
                'media' => $r['media_type'] ? ['type' => $r['media_type'], 'path' => $r['media_path']] : null,
                'at'    => (string)$r['created_at'],
                'ai'    => ($r['source'] ?? '') === 'ai',
                'by'    => self::staff((int)($r['sent_by'] ?? 0)),
                'x'     => self::payloadOf($r),   // ۱.۰.۱۴: کارت‌ها، دکمه‌ها و پاسخ‌های سریع
            ];
        }

        return [
            'ok'       => true,
            'error'    => null,
            'messages' => $messages,
            'events'   => self::takeEvents($token),
            'last_id'  => $lastId,
            'online'   => self::isOnline(),
            'typing'   => !$messages && self::someoneTyping((int)($identity['person_id'] ?? 0)),
        ];
    }

    /** ۱.۰.۱۳: نام نمایشی و تصویر همکاری که پیام را فرستاده (برای ویجت) */
    private static function staff(int $uid): ?array
    {
        static $map = null;
        if ($uid <= 0) return null;
        if ($map === null) {
            $map = [];
            try {
                $av = Database::hasColumn('eb_users', 'avatar') ? 'avatar' : "''";
                foreach (Database::all("SELECT id, COALESCE(NULLIF(TRIM(display_name), ''), username) n, $av a FROM eb_users") as $u)
                    $map[(int)$u['id']] = ['name' => (string)$u['n'], 'avatar' => (string)$u['a'], 'initial' => mb_substr((string)$u['n'], 0, 1)];
            } catch (Throwable $e) {}
        }
        return $map[$uid] ?? null;
    }

    /** ۱.۰.۸: «در حال نوشتن…» — دستیار فروش در حال آماده کردن پاسخ است یا همکاری در پنل تایپ می‌کند */
    private static function someoneTyping(int $pid): bool
    {
        if ($pid <= 0) return false;
        try {
            if (class_exists('SalesAgent') && SalesAgent::isBusy($pid)) return true;
            if (Database::hasTable('eb_chat_presence')) {
                return (bool)Database::one("SELECT person_id FROM eb_chat_presence WHERE person_id = ? AND typing = 1 AND seen_at >= DATE_SUB(NOW(), INTERVAL 8 SECOND) LIMIT 1", [$pid]);
            }
        } catch (Throwable $e) {}
        return false;
    }

    /**
     * رویدادهای کنترلی تحویل‌داده‌نشده را برمی‌دارد و «تحویل‌شده» علامت می‌زند.
     * (مثل «گفتگو به هوش مصنوعی سپرده شد» یا «اپراتور وارد شد».)
     */
    private static function takeEvents(string $token): array
    {
        try {
            $rows = Database::all(
                "SELECT id, payload_json, source FROM eb_webchat_outbox
                  WHERE token = ? AND delivered_at IS NULL ORDER BY id ASC LIMIT 20",
                [$token]
            );
        } catch (Throwable $e) {
            return [];
        }
        if (!$rows) return [];

        $out = [];
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int)$r['id'];
            $data  = json_decode((string)$r['payload_json'], true);
            $out[] = ['type' => (string)($r['source'] ?? 'system'), 'data' => is_array($data) ? $data : []];
        }
        try {
            $in = implode(',', array_map('intval', $ids));
            Database::run("UPDATE eb_webchat_outbox SET delivered_at = NOW() WHERE id IN ($in)");
        } catch (Throwable $e) { /* بی‌صدا */ }

        return $out;
    }

    /** ثبت یک رویداد کنترلی برای ویجت (پیام نیست؛ در تاریخچه ثبت نمی‌شود) */
    public static function pushEvent(string $token, string $type, array $data = []): void
    {
        if (!self::validToken($token)) return;
        try {
            Database::run(
                "INSERT INTO eb_webchat_outbox (token, payload_json, source) VALUES (?, ?, ?)",
                [$token, json_encode($data, JSON_UNESCAPED_UNICODE), mb_substr($type, 0, 20)]
            );
        } catch (Throwable $e) { /* بی‌صدا */ }
    }

    /**
     * تاریخچه‌ی گفتگوی این نشست برای ویجت (وقتی بازدیدکننده صفحه را تازه می‌کند).
     * خروجی به ترتیب زمانی؛ هم in و هم out.
     */
    public static function history(string $token, int $limit = 50): array
    {
        if (!self::validToken($token)) return ['messages' => [], 'last_id' => 0];

        $identity = Database::one(
            "SELECT id FROM eb_identities WHERE channel = 'webchat' AND channel_uid = ?",
            [$token]
        );
        if (!$identity) return ['messages' => [], 'last_id' => 0];

        $limit = max(1, min(200, $limit));
        $rows = Database::all(
            "SELECT id, body, direction, status, media_type, media_path, created_at, sent_by" . (Database::hasColumn('eb_messages', 'source') ? ", source" : "") . (self::hasPayloadCol() ? ", payload_json" : "") . "
               FROM eb_messages WHERE identity_id = ?
              ORDER BY id DESC LIMIT $limit",
            [$identity['id']]
        );
        $rows = array_reverse($rows);

        $msgs   = [];
        $lastOut = 0;
        foreach ($rows as $r) {
            $isOut = ((string)$r['direction'] === 'out');
            if ($isOut) {
                if (in_array((string)$r['status'], ['failed', 'error'], true)) continue;
                $lastOut = max($lastOut, (int)$r['id']);
            }
            $msgs[] = [
                'id'    => (int)$r['id'],
                'from'  => $isOut ? 'agent' : 'visitor',
                'ai'    => $isOut && ($r['source'] ?? '') === 'ai',
                'by'    => $isOut ? self::staff((int)($r['sent_by'] ?? 0)) : null,
                'text'  => (string)$r['body'],
                'media' => $r['media_type'] ? ['type' => $r['media_type'], 'path' => $r['media_path']] : null,
                'at'    => (string)$r['created_at'],
                'x'     => $isOut ? self::payloadOf($r) : null,
            ];
        }
        return ['messages' => $msgs, 'last_id' => $lastOut];
    }

    // ------------------------------------------------------------ پنل مدیریت

    /**
     * فهرست نشست‌های چت سایت برای صفحه‌ی مدیریت.
     * $filter: ['q'=>..., 'only_identified'=>bool, 'days'=>int]
     */
    public static function sessions(array $filter = [], int $limit = 100): array
    {
        $where  = ['1=1'];
        $params = [];

        $days = (int)($filter['days'] ?? 0);
        if ($days > 0) {
            // عدد مستقیم داخل SQL می‌رود (بعد از cast به int) چون MySQL برای
            // مقدار INTERVAL همیشه با placeholder راه نمی‌آید.
            $days = min(3650, $days);
            $where[] = "w.last_seen > DATE_SUB(NOW(), INTERVAL $days DAY)";
        }
        if (!empty($filter['only_identified'])) {
            $where[] = 'w.identified = 1';
        }
        $q = trim((string)($filter['q'] ?? ''));
        if ($q !== '') {
            $where[]  = '(p.full_name LIKE ? OR p.phone LIKE ? OR w.ip LIKE ? OR w.page_url LIKE ?)';
            $like     = '%' . $q . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $limit = max(1, min(500, $limit));
        $sql = "SELECT w.id, w.token, w.person_id, w.ip, w.page_url, w.referer,
                       w.device, w.browser, w.os, w.identified, w.msg_count,
                       w.created_at, w.last_seen,
                       p.full_name, p.phone
                  FROM eb_webchat_sessions w
                  LEFT JOIN eb_persons p ON p.id = w.person_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY w.last_seen DESC LIMIT $limit";
        try {
            return Database::all($sql, $params);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** آمار خلاصه‌ی چت سایت برای داشبورد/صفحه‌ی تنظیمات */
    public static function stats(): array
    {
        $zero = ['today' => 0, 'week' => 0, 'identified' => 0, 'online_now' => 0, 'messages' => 0];
        try {
            $row = Database::one(
                "SELECT
                   SUM(created_at >= CURDATE())                                   AS today,
                   SUM(created_at > DATE_SUB(NOW(), INTERVAL 7 DAY))               AS week,
                   SUM(identified = 1)                                            AS identified,
                   SUM(last_seen  > DATE_SUB(NOW(), INTERVAL 2 MINUTE))           AS online_now,
                   COALESCE(SUM(msg_count), 0)                                    AS messages
                 FROM eb_webchat_sessions"
            );
        } catch (Throwable $e) {
            return $zero;
        }
        if (!$row) return $zero;
        foreach ($zero as $k => $_) $zero[$k] = (int)($row[$k] ?? 0);
        return $zero;
    }

    /** کد نصب ویجت برای کپی در سایت مشتری */
    public static function embedCode(string $baseUrl): string
    {
        $src = rtrim($baseUrl, '/') . '/widget.js';
        return '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" async></script>';
    }
}

