<?php
/**
 * modules/InstagramApichi.php
 * اتصال اعلان‌بان به پیج‌های اینستاگرام از طریق API خودمان «یار API» (به‌جای اتصال مستقیم سرویس‌دهنده).
 *
 * تفاوت با اتصال مستقیم سرویس‌دهنده:
 *   - یک «کلید یار API» (ak_live_…) برای همه‌ی پیج‌ها کافی است؛ Account Key/Integration Key سرویس‌دهنده لازم نیست.
 *   - پیج‌ها در پنل یار API وصل می‌شوند (OAuth)، و اینجا با «همگام‌سازی پیج‌ها» به eb_ig_accounts
 *     (provider='apichi', apichi_account_id = igAccountId یار API) اضافه می‌شوند — مثلاً ۴ پیج همزمان.
 *   - یک وب‌هوک برای همه‌ی پیج‌ها: webhook/apichi.php (ثبت خودکار با یک کلیک، امضای X-Apichi-Signature).
 *   - polling پشتیبان در cron (رویدادی که به وب‌هوک نرسیده جا نمی‌ماند؛ برای سرور بدون HTTPS هم کافی است).
 *   - بقیه‌ی سیستم (اینباکس، اتوماسیون کامنت، فالو اجباری با دکمه، منوی بات، فرم، AI، گیت شماره،
 *     انتشار پست/استوری، ویترین) بدون تغییر کار می‌کند؛ درایور: channels/InstagramApichiDriver.php
 */

class InstagramApichi
{
    const LOG = 'instagram_apichi';
    const COLORS = ['#6366f1', '#ec4899', '#10b981', '#f59e0b', '#0ea5e9', '#8b5cf6', '#ef4444', '#14b8a6'];
    const REQUIRED_SCOPES = ['account:read', 'messages:read', 'messages:send', 'webhooks:manage'];

    // ==================== دیتابیس (خودترمیم) ====================

    private static bool $schemaChecked = false;

    /**
     * ستون apichi_account_id فقط با «به‌روزرسانی دیتابیس» ساخته می‌شد؛ اگر اجرا نشده باشد
     * همگام‌سازی/دریافت رویداد با خطای SQL می‌میرد. این‌جا خودکار (و فقط یک‌بار در هر درخواست) ساخته می‌شود.
     */
    public static function ensureSchema(): void
    {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        try {
            if (!Database::all("SHOW COLUMNS FROM eb_ig_accounts LIKE 'apichi_account_id'")) {
                Database::run("ALTER TABLE eb_ig_accounts ADD COLUMN apichi_account_id VARCHAR(50) DEFAULT NULL");
                try { Database::run("ALTER TABLE eb_ig_accounts ADD INDEX idx_apichi_account (provider, apichi_account_id)"); } catch (Throwable $e) {}
            }
        } catch (Throwable $e) {
            error_log('[apichi] ensureSchema: ' . $e->getMessage());
        }
    }

    // ==================== تنظیمات ====================

    public static function setting(string $k, string $default = ''): string
    {
        try {
            $row = Database::one("SELECT v FROM eb_settings WHERE k = ?", ['apichi.' . $k]);
            return isset($row['v']) && $row['v'] !== null ? (string)$row['v'] : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }

    public static function setSetting(string $k, ?string $v): void
    {
        Database::run("INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", ['apichi.' . $k, $v]);
    }

    public static function baseUrl(): string { return rtrim(self::setting('base_url'), '/'); }
    public static function apiKey(): string { return self::setting('api_key'); }
    public static function mode(): string { return self::setting('mode', 'webhook') === 'polling' ? 'polling' : 'webhook'; }
    public static function isConfigured(): bool { return self::baseUrl() !== '' && self::apiKey() !== ''; }

    public static function webhookUrl(array $config): string
    {
        return rtrim(app_base_url($config), '/') . '/webhook/apichi.php';
    }

    public static function http(string $method, string $path, ?array $json = null, ?string $idem = null): array
    {
        // لاگ کامل (با مدت، درخواست و پاسخ) داخل InstagramApichiHttp::request در ApichiLog ثبت می‌شود
        return InstagramApichiHttp::request(self::baseUrl(), self::apiKey(), $method, $path, $json, $idem);
    }

    private static function errorOf(array $r): string
    {
        return $r['error'] ?: ('HTTP ' . $r['status'] . (isset($r['body']['raw']) ? ': ' . mb_substr((string)$r['body']['raw'], 0, 150) : ''));
    }

    /**
     * ذخیره‌ی آدرس و کلید + تست (ping) + همگام‌سازی پیج‌ها.
     * $apiBase مثل https://yarapi.ir/api/v1 (بدون mod_rewrite: …/api/v1/index.php)
     */
    public static function saveConnection(string $apiBase, string $apiKey, string $mode = 'webhook'): array
    {
        self::ensureSchema();
        @set_time_limit(120);
        $apiBase = rtrim(trim($apiBase), '/');
        if ($apiBase === '' || !preg_match('~^https?://~i', $apiBase)) return ['ok' => false, 'error' => 'آدرس یار API نامعتبر است (مثل https://yarapi.ir/api/v1)'];
        if (!preg_match('~/api/v1(/index\.php)?$~', $apiBase)) $apiBase .= '/api/v1';

        $apiKey = trim($apiKey);
        if ($apiKey === '') $apiKey = self::apiKey();
        if ($apiKey === '') return ['ok' => false, 'error' => 'کلید یار API لازم است'];
        if (!preg_match('/^(ak|rk)_/', $apiKey)) return ['ok' => false, 'error' => 'کلید یار API باید با ak_ یا rk_ شروع شود (از پنل یار API ← API و توسعه‌دهنده ← کلیدهای API)'];

        $ping = InstagramApichiHttp::request($apiBase, $apiKey, 'GET', 'ping');
        // اگر mod_rewrite روی سرور یار API کار نکند (مثلاً نصب در پوشه‌ی فرعی)، مسیر تمیز 404 می‌دهد؛
        // همان API با فرم /api/v1/index.php همیشه در دسترس است → خودکار امتحان و همان ذخیره می‌شود.
        if (!$ping['ok'] && (int)($ping['status'] ?? 0) === 404 && !preg_match('~/index\.php$~', $apiBase)) {
            $alt = InstagramApichiHttp::request($apiBase . '/index.php', $apiKey, 'GET', 'ping');
            if ($alt['ok']) { $apiBase .= '/index.php'; $ping = $alt; }
        }
        if (!$ping['ok']) return ['ok' => false, 'error' => 'اتصال به یار API ناموفق: ' . self::errorOf($ping)];

        self::setSetting('base_url', $apiBase);
        self::setSetting('api_key', $apiKey);
        self::setSetting('mode', $mode === 'polling' ? 'polling' : 'webhook');

        $scopes = (array)($ping['body']['data']['key']['scopes'] ?? []);
        $missing = array_values(array_diff(self::REQUIRED_SCOPES, $scopes));
        self::setSetting('scopes', json_encode($scopes));
        self::setSetting('user_email', (string)($ping['body']['data']['user']['email'] ?? ''));

        // شروع polling از «الان» (نه از ۳۰ روز تاریخچه)
        if (self::setting('poll_cursor') === '') {
            $latest = self::http('GET', 'instagram/events?after=latest');
            if ($latest['ok']) self::setSetting('poll_cursor', (string)(int)($latest['body']['nextCursor'] ?? 0));
        }

        $sync = self::syncAccounts();
        return ['ok' => true, 'missing_scopes' => $missing, 'scopes' => $scopes, 'sync' => $sync];
    }

    public static function status(array $config): array
    {
        $rows = [];
        try { $rows = Database::all("SELECT id, label, username, apichi_account_id, is_active FROM eb_ig_accounts WHERE provider='apichi' ORDER BY id"); } catch (Throwable $e) {}
        $key = self::apiKey();
        return [
            'configured'      => self::isConfigured(),
            'base_url'        => self::baseUrl(),
            'key_masked'      => $key !== '' ? substr($key, 0, 8) . str_repeat('•', 6) . substr($key, -4) : '',
            'mode'            => self::mode(),
            'user_email'      => self::setting('user_email'),
            'scopes'          => json_decode(self::setting('scopes', '[]'), true) ?: [],
            'missing_scopes'  => array_values(array_diff(self::REQUIRED_SCOPES, json_decode(self::setting('scopes', '[]'), true) ?: [])),
            'webhook_id'      => (int)self::setting('webhook_id', '0'),
            'webhook_url'     => self::webhookUrl($config),
            'webhook_https'   => stripos(self::webhookUrl($config), 'https://') === 0,
            'last_webhook_at' => self::setting('last_webhook_at'),
            'last_poll_at'    => self::setting('last_poll_at'),
            'last_poll_error' => self::setting('last_poll_error'),
            'last_sync_at'    => self::setting('last_sync_at'),
            'pages'           => $rows,
        ];
    }

    /**
     * عیب‌یابی خودکار اتصال یار API — هر آیتم: [level ok|warn|error, title, text]
     * چیزهایی که بررسی می‌شود: تنظیم، آخرین تماس موفق/ناموفق، وب‌هوک (رسیده/ردشده/ثبت‌شده)، polling،
     * رویدادهای پردازش‌شده، پیج‌ها، و پیج‌های قدیمی «اتصال مستقیم» که هنوز فعال‌اند.
     */
    public static function health(array $config): array
    {
        $c = [];
        if (!self::isConfigured()) return [['error', 'یار API هنوز تنظیم نشده', 'آدرس API و کلید را در کارت «اتصال به یار API» ذخیره کن.']];
        $ago = fn($t) => $t ? self::ago($t) : 'هرگز';

        $okOut = class_exists('ApichiLog') ? ApichiLog::last('out', 1) : null;
        $badOut = class_exists('ApichiLog') ? ApichiLog::last('out', 0) : null;
        if ($badOut && (!$okOut || $badOut['id'] > $okOut['id'])) {
            $c[] = ['error', 'آخرین درخواست به یار API ناموفق بود (' . $ago($badOut['created_at']) . ')', (string)$badOut['summary']];
        } elseif ($okOut) {
            $c[] = ['ok', 'ارتباط با یار API برقرار است', 'آخرین درخواست موفق: ' . $ago($okOut['created_at'])];
        }

        $missing = array_values(array_diff(self::REQUIRED_SCOPES, json_decode(self::setting('scopes', '[]'), true) ?: []));
        if ($missing) $c[] = ['warn', 'کلید API این دسترسی‌ها را ندارد: ' . implode('، ', $missing), 'در پنل یار API ← API و توسعه‌دهنده ← کلیدها، یک کلید با همه‌ی scopeهای لازم بساز.'];

        $pages = [];
        try { $pages = Database::all("SELECT id, label, username, is_active FROM eb_ig_accounts WHERE provider='apichi'"); } catch (Throwable $e) {}
        $active = array_filter($pages, fn($p) => !empty($p['is_active']));
        $c[] = $active ? ['ok', count($active) . ' پیج از یار API فعال است', implode('، ', array_map(fn($p) => $p['label'], $active))]
                       : ['error', 'هیچ پیج فعالی از یار API همگام نشده', '«همگام‌سازی دوباره‌ی پیج‌ها» را بزن؛ اگر خالی ماند، کلید API به هیچ پیجی دسترسی ندارد یا در یار API پیجی وصل نیست.'];

        // پیج‌های قدیمی «اتصال مستقیم»
        $legacy = [];
        try { $legacy = Database::all("SELECT id, label FROM eb_ig_accounts WHERE provider <> 'apichi' AND is_active = 1"); } catch (Throwable $e) {}
        if ($legacy) {
            $c[] = ['warn', 'پیج «اتصال مستقیم» هنوز فعال است: ' . implode('، ', array_column($legacy, 'label')),
                'سرویس‌دهنده رویدادهای هر پیج را فقط به یک آدرس می‌فرستد. وقتی آدرس وبهوک در پنل سرویس‌دهنده را به یار API تغییر دادی، این پیج‌ها دیگر رویدادی نمی‌گیرند و ارسال از آن‌ها با «مخاطب یافت نشد» رد می‌شود. غیرفعالشان کن و قانون‌های اتوماسیون کامنت‌شان را روی پیج یار API بگذار.'];
            try {
                $ids = array_map('intval', array_column($legacy, 'id'));
                $n = (int)(Database::one("SELECT COUNT(*) c FROM eb_ig_comment_rules WHERE ig_account_id IN (" . implode(',', $ids) . ")")['c'] ?? 0);
                if ($n) $c[] = ['warn', $n . ' قانون اتوماسیون کامنت به پیج قدیمی وصل است', 'این قانون‌ها روی کامنت‌هایی که از یار API می‌رسند اجرا نمی‌شوند. در تب «اتوماسیون کامنت» پیج آن‌ها را عوض کن (یا خالی بگذار = همه‌ی پیج‌ها).'];
            } catch (Throwable $e) {}
        }

        // دریافت رویداد
        $inOk = class_exists('ApichiLog') ? ApichiLog::last('in', 1) : null;
        $inBad = class_exists('ApichiLog') ? ApichiLog::last('in', 0) : null;
        $evOk = class_exists('ApichiLog') ? ApichiLog::last('event', 1) : null;
        if (self::mode() === 'webhook') {
            if (!(int)self::setting('webhook_id', '0')) $c[] = ['error', 'وب‌هوک در یار API ثبت نشده', 'دکمه‌ی «ثبت خودکار وب‌هوک» را بزن (یا حالت را polling کن).'];
            if ($inBad && (!$inOk || $inBad['id'] > $inOk['id'])) $c[] = ['error', 'آخرین وب‌هوک ورودی رد شد (' . $ago($inBad['created_at']) . ')', (string)$inBad['summary']];
            elseif ($inOk) $c[] = ['ok', 'آخرین وب‌هوک از یار API: ' . $ago($inOk['created_at']), (string)$inOk['summary']];
            else $c[] = ['warn', 'هنوز هیچ وب‌هوکی از یار API نرسیده', '«تست وب‌هوک» را بزن. اگر تست موفق است ولی دایرکت‌ها نمی‌رسند، مشکل قبل از یار API است: در پنل یار API ← «لاگ اتصال‌ها» ببین رویداد از سرویس‌دهنده رسیده یا نه.'];
        }
        $perr = self::setting('last_poll_error');
        if ($perr !== '') $c[] = ['warn', 'آخرین polling خطا داد', $perr];
        if ($evOk) $c[] = ['ok', 'آخرین رویداد پردازش‌شده: ' . $ago($evOk['created_at']), (string)$evOk['summary']];
        else $c[] = ['warn', 'هنوز هیچ دایرکت/کامنتی از یار API پردازش نشده', 'اگر به پیج پیام آمده و این‌جا چیزی نیست: (۱) در پنل یار API «لاگ اتصال‌ها» → مرحله‌ی «سرویس‌دهنده ← ورودی» را ببین؛ خالی یعنی آدرس وبهوک در پنل سرویس‌دهنده هنوز به یار API اشاره نمی‌کند. (۲) اگر آن‌جا هست ولی این‌جا نیست، مرحله‌ی «وب‌هوک → سیستم‌های متصل» در یار API خطا را نشان می‌دهد.'];
        return $c;
    }

    private static function ago(string $t): string
    {
        $d = time() - (strtotime($t) ?: time());
        if ($d < 60) return 'همین الان';
        if ($d < 3600) return floor($d / 60) . ' دقیقه پیش';
        if ($d < 86400) return floor($d / 3600) . ' ساعت پیش';
        return floor($d / 86400) . ' روز پیش';
    }

    // ==================== پیج‌ها ====================

    /**
     * همگام‌سازی پیج‌های متصل در یار API با eb_ig_accounts.
     * پیج جدید → ردیف جدید (لیبل/رنگ قابل ویرایش در پنل)؛ پیج قطع/حذف‌شده در یار API → غیرفعال.
     */
    public static function syncAccounts(): array
    {
        self::ensureSchema();
        if (!self::isConfigured()) return ['ok' => false, 'error' => 'یار API تنظیم نشده'];
        $r = self::http('GET', 'instagram/accounts');
        if (!$r['ok']) return ['ok' => false, 'error' => self::errorOf($r)];

        $remote = (array)($r['body']['data'] ?? []);
        $seen = [];
        $added = 0; $updated = 0; $disabled = 0;
        $existingCount = (int)(Database::one("SELECT COUNT(*) c FROM eb_ig_accounts")['c'] ?? 0);

        foreach ($remote as $a) {
            $aid = (string)(int)($a['id'] ?? 0);
            if ($aid === '0') continue;
            $seen[] = $aid;
            $handle = ltrim((string)($a['instagramHandle'] ?? ''), '@');
            $active = ($a['status'] ?? '') === 'connected' ? 1 : 0;
            $row = Database::one("SELECT * FROM eb_ig_accounts WHERE provider='apichi' AND apichi_account_id = ?", [$aid]);
            if ($row) {
                Database::run("UPDATE eb_ig_accounts SET username = ?, is_active = ? WHERE id = ?", [$handle ?: $row['username'], $active, $row['id']]);
                $updated++;
            } else {
                $color = self::COLORS[($existingCount + $added) % count(self::COLORS)];
                Database::run(
                    "INSERT INTO eb_ig_accounts (provider, label, color, username, account_key, webhook_secret, apichi_account_id, is_active)
                     VALUES ('apichi', ?, ?, ?, ?, NULL, ?, ?)",
                    [$handle !== '' ? $handle : ('پیج یار API #' . $aid), $color, $handle ?: null, 'apichi:' . $aid, $aid, $active]
                );
                $added++;
            }
        }
        // پیج‌هایی که دیگر در یار API نیستند (یا کلید به آن‌ها محدود نیست)
        foreach (Database::all("SELECT id, apichi_account_id FROM eb_ig_accounts WHERE provider='apichi' AND is_active = 1") as $row) {
            if (!in_array((string)$row['apichi_account_id'], $seen, true)) {
                Database::run("UPDATE eb_ig_accounts SET is_active = 0 WHERE id = ?", [$row['id']]);
                $disabled++;
            }
        }
        self::setSetting('last_sync_at', date('Y-m-d H:i:s'));
        return ['ok' => true, 'total' => count($remote), 'added' => $added, 'updated' => $updated, 'disabled' => $disabled];
    }

    public static function accountByApichiId(int $igAccountId): ?array
    {
        self::ensureSchema();
        return Database::one("SELECT * FROM eb_ig_accounts WHERE provider='apichi' AND apichi_account_id = ?", [(string)$igAccountId]);
    }

    public static function driverForAccount(array $account): InstagramApichiDriver
    {
        return new InstagramApichiDriver(self::apiKey(), self::baseUrl(), (int)$account['apichi_account_id']);
    }

    // ==================== وب‌هوک ====================

    /** ثبت (یا ثبت مجدد با Secret تازه) وب‌هوک اعلان‌بان در یار API + تست فوری */
    public static function registerWebhook(array $config): array
    {
        if (!self::isConfigured()) return ['ok' => false, 'error' => 'اول اتصال یار API را ذخیره کن'];
        $url = self::webhookUrl($config);
        if (stripos($url, 'https://') !== 0) {
            return ['ok' => false, 'error' => 'آدرس اعلان‌بان HTTPS نیست؛ یار API فقط وب‌هوک HTTPS قبول می‌کند. حالت «polling» را انتخاب کن (cron هر دقیقه رویدادها را می‌خواند).'];
        }
        $events = ['instagram.*'];
        $r = self::http('POST', 'webhooks', ['url' => $url, 'events' => $events, 'source' => 'elanban', 'description' => 'Elanban — ' . parse_url($url, PHP_URL_HOST)]);
        if (!$r['ok'] && (int)$r['status'] === 409 && !empty($r['body']['existingId'])) {
            $id = (int)$r['body']['existingId'];
            self::http('PATCH', 'webhooks/' . $id, ['events' => $events, 'status' => 'active']);
            $r = self::http('POST', 'webhooks/' . $id . '/rotate-secret');
        }
        if (!$r['ok']) return ['ok' => false, 'error' => self::errorOf($r)];
        $w = $r['body']['data'] ?? [];
        if (empty($w['id']) || empty($w['secret'])) return ['ok' => false, 'error' => 'پاسخ یار API شناسه/Secret وب‌هوک نداشت'];
        self::setSetting('webhook_id', (string)(int)$w['id']);
        self::setSetting('webhook_secret', (string)$w['secret']);
        self::setSetting('mode', 'webhook');
        $test = self::testWebhook();
        return ['ok' => true, 'webhook_id' => (int)$w['id'], 'test' => $test];
    }

    public static function testWebhook(): array
    {
        $id = (int)self::setting('webhook_id', '0');
        if (!$id) return ['ok' => false, 'error' => 'وب‌هوکی ثبت نشده'];
        $r = self::http('POST', 'webhooks/' . $id . '/test');
        if (!$r['ok']) return ['ok' => false, 'error' => self::errorOf($r)];
        $d = $r['body']['data']['delivery'] ?? [];
        return ['ok' => !empty($d['ok']), 'status_code' => $d['statusCode'] ?? null, 'ms' => $d['ms'] ?? null, 'error' => $d['error'] ?? null];
    }

    /** دلیل رد شدن امضای وب‌هوک (برای لاگ) — null یعنی امضا درست است */
    public static function signatureProblem(string $raw, string $timestamp, string $signature): ?string
    {
        $secret = self::setting('webhook_secret');
        if ($secret === '') return 'Secret وب‌هوک در اعلان‌بان ذخیره نشده — «ثبت خودکار وب‌هوک» را دوباره بزن';
        if ($signature === '' || $timestamp === '') return 'هدرهای امضای X-YarApi-Signature / X-YarApi-Timestamp نرسیده (درخواست از یار API نیست یا هاست هدرها را حذف کرده)';
        if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300) return 'timestamp قدیمی/نامعتبر (' . $timestamp . ' در برابر ساعت سرور ' . time() . ') — ساعت سرور را چک کن';
        if (!hash_equals('sha256=' . hash_hmac('sha256', $timestamp . '.' . $raw, $secret), $signature)) return 'امضا نامعتبر — Secret ذخیره‌شده در اعلان‌بان با Secret این وب‌هوک در یار API یکی نیست («ثبت خودکار وب‌هوک» را دوباره بزن تا Secret تازه هماهنگ شود)';
        return null;
    }

    public static function verifySignature(string $raw, string $timestamp, string $signature): bool
    {
        $secret = self::setting('webhook_secret');
        if ($secret === '' || $timestamp === '' || $signature === '' || !ctype_digit($timestamp)) return false;
        if (abs(time() - (int)$timestamp) > 300) return false;
        return hash_equals('sha256=' . hash_hmac('sha256', $timestamp . '.' . $raw, $secret), $signature);
    }

    // ==================== پردازش رویداد ====================

    /**
     * یک رویداد یار API (از وب‌هوک یا polling) را به پایپ‌لاین مشترک می‌دهد.
     * dedupe با شناسه‌ی رویداد (یکسان در وب‌هوک و polling).
     */
    /** ثبت نتیجه‌ی هر رویداد در «لاگ خام» تا هیچ رویدادی بی‌صدا گم نشود */
    private static function logOutcome(string $source, array $ev, string $status, bool $ok, ?string $error = null, $payload = null): void
    {
        if (!class_exists('ApichiLog')) return;
        $d = (array)($ev['data'] ?? []);
        $who = !empty($d['username']) ? '@' . ltrim((string)$d['username'], '@') : (!empty($d['contactId']) ? 'مخاطب ' . $d['contactId'] : '');
        $labels = [
            'no_account' => 'رد: رویداد شناسه‌ی پیج ندارد', 'unknown_account' => 'رد: پیج ناشناخته', 'account_inactive' => 'رد: پیج غیرفعال',
            'unsupported' => 'نادیده (نوع پشتیبانی‌نشده)', 'error' => 'خطا در پردازش', 'duplicate' => 'تکراری',
        ];
        $txt = !empty($d['text']) ? ' «' . mb_substr((string)$d['text'], 0, 60) . '»' : '';
        ApichiLog::add('event', [
            'method' => $source, 'endpoint' => (string)($ev['event'] ?? ''), 'ok' => $ok,
            'ig_account_id' => (int)($ev['igAccount']['id'] ?? 0) ?: null,
            'summary' => ($labels[$status] ?? $status) . ' — ' . ($ev['event'] ?? '?') . ($who ? ' از ' . $who : '') . (!empty($ev['igAccount']['handle']) ? ' روی ' . $ev['igAccount']['handle'] : '') . $txt . ($error ? ' — ' . $error : ''),
            'request' => $ev, 'response' => $payload, 'ref' => (string)($ev['id'] ?? ''),
        ]);
    }

    public static function processEnvelope(array $ev, array $config, string $source = 'webhook'): string
    {
        $eventId = (string)($ev['id'] ?? '');
        $name = (string)($ev['event'] ?? '');
        if ($eventId === '' || strpos($name, 'instagram.') !== 0) return 'ignored';

        // dedupe: یک رویداد ممکن است هم با وب‌هوک و هم با polling برسد (یا retry شود)
        try {
            if (Database::one("SELECT id FROM eb_webhook_events WHERE channel = 'instagram_apichi' AND event_key = ?", [$eventId])) return 'duplicate';
            Database::run("INSERT INTO eb_webhook_events (channel, event_key) VALUES ('instagram_apichi', ?)", [$eventId]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') return 'duplicate';
        } catch (Throwable $e) { /* fail-open */ }

        try {
            $igId = (int)($ev['igAccount']['id'] ?? ($ev['igAccountId'] ?? 0));
            if (!$igId) { self::logOutcome($source, $ev, 'no_account', false, 'رویداد شناسه‌ی پیج (igAccount.id) ندارد'); return 'no_account'; }
            $account = self::accountByApichiId($igId);
            if (!$account) {
                // پیج تازه در یار API وصل شده و هنوز همگام نشده
                self::syncAccounts();
                $account = self::accountByApichiId($igId);
            }
            if (!$account) { self::logOutcome($source, $ev, 'unknown_account', false, 'پیج #' . $igId . ' یار API در اعلان‌بان نیست (کلید API به این پیج دسترسی دارد؟)'); return 'unknown_account'; }
            if (empty($account['is_active'])) { self::logOutcome($source, $ev, 'account_inactive', false, 'پیج «' . $account['label'] . '» در اعلان‌بان غیرفعال است'); return 'account_inactive'; }

            $payload = self::payloadFromEnvelope($ev);
            if (!$payload) { self::logOutcome($source, $ev, 'unsupported', true, null); return 'unsupported:' . $name; }

            $res = IgWebhookPipeline::process($account, $payload, self::driverForAccount($account), $config, self::LOG);
            self::logOutcome($source, $ev, 'پردازش شد → ' . (is_string($res) ? $res : json_encode($res)) . ' (پیج «' . $account['label'] . '»)', $res !== 'error', null, $payload);
            return $res;
        } catch (Throwable $e) {
            // علامت «پردازش‌شده» برداشته شود تا retry/polling بعدی دوباره امتحانش کند
            try { Database::run("DELETE FROM eb_webhook_events WHERE channel = 'instagram_apichi' AND event_key = ?", [$eventId]); } catch (Throwable $e2) {}
            self::logOutcome($source, $ev, 'error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            error_log('[apichi] processEnvelope: ' . $e->getMessage());
            return 'error';
        }
    }

    /**
     * envelope یار API → payload با شکل سرویس‌دهنده (همان چیزی که پایپ‌لاین می‌شناسد).
     * یار API payload خام سرویس‌دهنده را در raw می‌فرستد؛ اگر نبود، از data ساخته می‌شود.
     */
    public static function payloadFromEnvelope(array $ev): ?array
    {
        $raw = $ev['raw'] ?? null;
        if (is_array($raw) && !empty($raw['type']) && in_array($raw['type'], ['direct', 'comment', 'button_click'], true)) {
            return $raw;
        }
        $d = (array)($ev['data'] ?? []);
        $type = [
            'instagram.direct.received'  => 'direct',
            'instagram.story.received'   => 'direct',
            'instagram.comment.received' => 'comment',
            'instagram.button.clicked'   => 'button_click',
        ][$ev['event'] ?? ''] ?? null;
        if (!$type) return null;

        $p = [
            'type'    => $type,
            'contact' => ['id' => $d['contactId'] ?? null, 'username' => $d['username'] ?? null, 'name' => $d['fullName'] ?? null],
        ];
        if ($type === 'direct') {
            $p['message'] = [
                'text' => (string)($d['text'] ?? ''),
                'is_story_reply' => !empty($d['isStoryReply']) || ($ev['event'] ?? '') === 'instagram.story.received',
                'story_id' => $d['storyId'] ?? null,
            ];
            if (!empty($d['storyMediaId'])) $p['message']['story_media'] = ['resource_id' => $d['storyMediaId']];
        } elseif ($type === 'comment') {
            $p['comment'] = ['id' => $d['commentId'] ?? null, 'post_id' => $d['postId'] ?? null, 'text' => (string)($d['text'] ?? '')];
        } else {
            $p['button'] = ['payload' => $d['payload'] ?? null, 'title' => $d['buttonTitle'] ?? null];
        }
        return $p;
    }

    /** polling دستی/از cron با بارگذاری همه‌ی وابستگی‌های پایپ‌لاین (از هر نقطه‌ی ورود قابل صدا زدن) */
    public static function pollWithPipeline(array $config, bool $force = true): int
    {
        require_once dirname(__DIR__) . '/webhook/_ig_bootstrap.php';
        return self::poll($config, $force);
    }

    /**
     * polling رویدادهای جدید (cron). در حالت webhook فقط هر ۱۰ دقیقه برای جبران رویدادهای جاافتاده.
     * @return int تعداد رویداد پردازش‌شده
     */
    public static function poll(array $config, bool $force = false): int
    {
        if (!self::isConfigured()) return 0;
        if (!$force && self::mode() === 'webhook') {
            $last = strtotime(self::setting('last_poll_at', '1970-01-01')) ?: 0;
            if (time() - $last < 600) return 0;
        }
        $cursor = self::setting('poll_cursor');
        if ($cursor === '') {
            $latest = self::http('GET', 'instagram/events?after=latest');
            if ($latest['ok']) self::setSetting('poll_cursor', (string)(int)($latest['body']['nextCursor'] ?? 0));
            self::setSetting('last_poll_at', date('Y-m-d H:i:s'));
            return 0;
        }
        $cursor = (int)$cursor;
        $n = 0;
        for ($page = 0; $page < 5; $page++) {
            $r = self::http('GET', 'instagram/events?' . http_build_query(['after' => $cursor, 'limit' => 100]));
            if (!$r['ok']) {
                self::setSetting('last_poll_error', self::errorOf($r));
                break;
            }
            self::setSetting('last_poll_error', '');
            foreach ((array)($r['body']['data'] ?? []) as $ev) {
                $status = self::processEnvelope($ev, $config, 'polling');
                if ($status !== 'duplicate') $n++;
                $cursor = max($cursor, (int)($ev['cursor'] ?? 0));
                self::setSetting('poll_cursor', (string)$cursor);
            }
            if (empty($r['body']['hasMore'])) break;
        }
        self::setSetting('last_poll_at', date('Y-m-d H:i:s'));
        $perr = self::setting('last_poll_error');
        if (class_exists('ApichiLog') && ($n > 0 || $perr !== '' || $force)) {
            ApichiLog::add('poll', [
                'method' => $force ? 'manual' : 'cron', 'ok' => $perr === '',
                'summary' => $perr !== '' ? 'polling ناموفق: ' . $perr : ($n > 0 ? $n . ' رویداد تازه دریافت و پردازش شد' : 'رویداد تازه‌ای نبود'),
                'request' => ['cursor_after' => $cursor],
            ]);
        }
        return $n;
    }
}
