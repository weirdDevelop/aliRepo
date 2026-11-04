<?php
/**
 * modules/InstagramAuth.php
 * اتصال حساب اینستاگرام با «Instagram API with Instagram Login» — همان روشی
 * که در پلاگین «اینستینو» (نمونه‌ی کارفرما) با app_id/app_secret مخصوصِ بخش
 * «Instagram business login» (نه App ID عمومیِ فیسبوک) پیاده شده بود.
 *
 * جریان: authorizationUrl() → کاربر تأیید می‌کند → کالبک با code → exchangeCode()
 * (کد → توکن کوتاه‌مدت → توکن ۶۰روزه) → ذخیره + اشتراک وب‌هوک پیام‌ها.
 * توکن هر چند روز با refreshIfNeeded() (از cron) تمدید می‌شود، وگرنه بعد از ۶۰ روز باطل می‌شود.
 *
 * تنظیمات در eb_settings با پیشوند `channel.instagram.` (همان الگوی بقیه‌ی کانال‌ها).
 */

class InstagramAuth
{
    const GRAPH_VERSION = 'v21.0';

    const DEFAULTS = [
        'app_id'          => '',
        'app_secret'      => '',
        'verify_token'    => '',
        'access_token'    => '',
        'ig_user_id'      => '',
        'username'        => '',
        'token_expires_at'=> '',
    ];

    private static $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;
        $out = self::DEFAULTS;
        try {
            $rows = Database::all("SELECT k, v FROM eb_settings WHERE k LIKE 'channel.instagram.%'");
            foreach ($rows as $r) {
                $key = substr($r['k'], strlen('channel.instagram.'));
                if (array_key_exists($key, $out) && $r['v'] !== null && $r['v'] !== '') {
                    $out[$key] = $r['v'];
                }
            }
        } catch (Throwable $e) { /* پیش‌فرض */ }
        // verify_token اگر هنوز ساخته نشده، یک‌بار خودکار بساز (برای فرم متا لازم است)
        if ($out['verify_token'] === '') {
            $out['verify_token'] = bin2hex(random_bytes(16));
            try {
                Database::run(
                    "INSERT INTO eb_settings (k, v) VALUES ('channel.instagram.verify_token', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
                    [$out['verify_token']]
                );
            } catch (Throwable $e) { /* بی‌اثر */ }
        }
        self::$cache = $out;
        return $out;
    }

    public static function get(string $key): string
    {
        $a = self::all();
        return (string)($a[$key] ?? '');
    }

    public static function saveApp(string $appId, string $appSecret): void
    {
        Database::run("INSERT INTO eb_settings (k,v) VALUES ('channel.instagram.app_id', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [trim($appId)]);
        if (trim($appSecret) !== '') {
            Database::run("INSERT INTO eb_settings (k,v) VALUES ('channel.instagram.app_secret', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [trim($appSecret)]);
        }
        self::$cache = null;
    }

    public static function isAppConfigured(): bool
    {
        $a = self::all();
        return trim($a['app_id']) !== '' && trim($a['app_secret']) !== '';
    }

    public static function isConnected(): bool
    {
        $a = self::all();
        return trim($a['access_token']) !== '' && trim($a['ig_user_id']) !== '';
    }

    public static function disconnect(): void
    {
        foreach (['access_token', 'ig_user_id', 'username', 'token_expires_at'] as $k) {
            Database::run("DELETE FROM eb_settings WHERE k = ?", ["channel.instagram.{$k}"]);
        }
        self::$cache = null;
    }

    public static function redirectUri(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/instagram-oauth-callback.php';
    }

    public static function authorizationUrl(string $baseUrl, string $state): ?string
    {
        $appId = self::get('app_id');
        if ($appId === '') return null;
        $scopes = ['instagram_business_basic', 'instagram_business_manage_messages'];
        $params = [
            'client_id'     => $appId,
            'redirect_uri'  => self::redirectUri($baseUrl),
            'response_type' => 'code',
            'scope'         => implode(',', $scopes),
            'state'         => $state,
        ];
        return 'https://www.instagram.com/oauth/authorize/?' . http_build_query($params);
    }

    /**
     * تبدیل کد OAuth به توکن ۶۰روزه، ذخیره، و اشتراک وب‌هوک پیام‌ها.
     * @return array{ok:bool, error?:string}
     */
    public static function exchangeCode(string $code, string $baseUrl): array
    {
        $appId = self::get('app_id');
        $secret = self::get('app_secret');
        if ($appId === '' || $secret === '') {
            return ['ok' => false, 'error' => 'App ID یا App Secret اینستاگرام تنظیم نشده است'];
        }

        // ۱) کد → توکن کوتاه‌مدت (فرم‌انکد، نه JSON — طبق مستندات api.instagram.com)
        $short = self::httpForm('POST', 'https://api.instagram.com/oauth/access_token', [
            'client_id'     => $appId,
            'client_secret' => $secret,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => self::redirectUri($baseUrl),
            'code'          => $code,
        ]);
        if (!$short['ok'] || empty($short['body']['access_token'])) {
            $msg = $short['body']['error_message'] ?? ($short['body']['error_type'] ?? 'توکن کوتاه‌مدت دریافت نشد');
            return ['ok' => false, 'error' => "خطای تبادل کد: {$msg}"];
        }
        $shortToken = (string)$short['body']['access_token'];

        // ۲) توکن کوتاه‌مدت → توکن ۶۰روزه
        $long = self::httpForm('GET', 'https://graph.instagram.com/access_token', [
            'grant_type'    => 'ig_exchange_token',
            'client_secret' => $secret,
            'access_token'  => $shortToken,
        ]);
        $token = (!empty($long['body']['access_token'])) ? (string)$long['body']['access_token'] : $shortToken;
        $expiresIn = (!empty($long['body']['expires_in'])) ? (int)$long['body']['expires_in'] : 3600;

        // ۳) پروفایل حساب متصل‌شده
        $profile = self::httpForm('GET', 'https://graph.instagram.com/' . self::GRAPH_VERSION . '/me', [
            'fields'       => 'user_id,username',
            'access_token' => $token,
        ]);
        $igId = (string)($profile['body']['user_id'] ?? $profile['body']['id'] ?? '');
        if ($igId === '') {
            return ['ok' => false, 'error' => 'شناسه‌ی حساب اینستاگرام دریافت نشد'];
        }
        $username = (string)($profile['body']['username'] ?? '');

        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        foreach ([
            'access_token'     => $token,
            'ig_user_id'       => $igId,
            'username'         => $username,
            'token_expires_at' => $expiresAt,
        ] as $k => $v) {
            Database::run(
                "INSERT INTO eb_settings (k,v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
                ["channel.instagram.{$k}", $v]
            );
        }
        self::$cache = null;

        self::subscribeWebhook($igId, $token);

        return ['ok' => true];
    }

    /** اشتراک فیلد messages تا رویدادهای دایرکت به وب‌هوک ما برسند */
    public static function subscribeWebhook(?string $igId = null, ?string $token = null): array
    {
        $igId  = $igId  ?? self::get('ig_user_id');
        $token = $token ?? self::get('access_token');
        if ($igId === '' || $token === '') return ['ok' => false, 'error' => 'متصل نیست'];
        return self::httpForm('POST', 'https://graph.instagram.com/' . self::GRAPH_VERSION . "/{$igId}/subscribed_apps", [
            'subscribed_fields' => 'messages',
            'access_token'      => $token,
        ]);
    }

    /**
     * تمدید توکن اگر کمتر از ۱۰ روز تا انقضا مانده (توکن باید حداقل ۲۴ ساعت از
     * صدورش گذشته باشد تا Meta اجازه‌ی تمدید بدهد؛ خطای احتمالی را بی‌صدا نادیده می‌گیریم).
     * از cron صدا زده می‌شود.
     */
    public static function refreshIfNeeded(): void
    {
        $a = self::all();
        if ($a['access_token'] === '' || $a['token_expires_at'] === '') return;
        $expiresAt = strtotime($a['token_expires_at']);
        if (!$expiresAt || $expiresAt - time() > 10 * 86400) return; // هنوز زوده

        $r = self::httpForm('GET', 'https://graph.instagram.com/refresh_access_token', [
            'grant_type'   => 'ig_refresh_token',
            'access_token' => $a['access_token'],
        ]);
        if (!empty($r['body']['access_token'])) {
            $expiresIn = (int)($r['body']['expires_in'] ?? 5184000); // پیش‌فرض ۶۰ روز
            Database::run("INSERT INTO eb_settings (k,v) VALUES ('channel.instagram.access_token', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$r['body']['access_token']]);
            Database::run("INSERT INTO eb_settings (k,v) VALUES ('channel.instagram.token_expires_at', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [date('Y-m-d H:i:s', time() + $expiresIn)]);
            self::$cache = null;
        }
    }

    /** GET/POST با بدنه‌ی فرم‌انکد (نه JSON) — دقیقاً چیزی که اندپوینت‌های OAuth متا می‌خواهند */
    private static function httpForm(string $method, string $url, array $params): array
    {
        $ch = curl_init();
        if (strtoupper($method) === 'GET') {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cErr = curl_error($ch);
        curl_close($ch);

        $body = json_decode((string)$raw, true);
        if (class_exists('ApiLog')) {
            $masked = $params;
            if (isset($masked['client_secret'])) $masked['client_secret'] = '***';
            if (isset($masked['access_token']))   $masked['access_token']  = '***';
            ApiLog::record('instagram', 'oauth:' . $url, $masked, $body ?? $raw, $code, ($code >= 200 && $code < 300), $cErr ?: null);
        }
        return ['ok' => ($raw !== false && $code >= 200 && $code < 300), 'body' => is_array($body) ? $body : [], 'code' => $code, 'error' => $cErr ?: null];
    }
}
