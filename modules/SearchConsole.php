<?php
/**
 * modules/SearchConsole.php
 * اتصال به Google Search Console و ابزارهای سئو:
 *   - اتصال با OAuth (حساب گوگل) یا حساب سرویس (Service Account، بدون نیاز به ورود)
 *   - عملکرد جستجو با فیلتر، مقایسه‌ی دوره، کلمات برند/غیربرند
 *   - فرصت‌ها: کلمات نزدیک صفحه‌ی اول، CTR کمتر از انتظار، هم‌نوع‌خواری (چند صفحه برای یک کلمه)، افت و رشد
 *   - نقشه‌ی سایت: فهرست، ارسال، حذف، خواندن آدرس‌ها
 *   - بررسی ایندکس آدرس‌ها (URL Inspection) دستی و خودکار با رعایت سهمیه‌ی روزانه
 *   - بازرسی فنی صفحه‌ها (وضعیت، عنوان، توضیحات، H1، canonical، noindex، سرعت)
 *   - ساخت نقشه‌ی سایت با خزش
 *   - هشدارهای روزانه
 *
 * توکن‌ها و کلیدها با app_key در config.php رمزگذاری و در eb_settings (پیشوند gsc.) ذخیره می‌شوند.
 */
class SearchConsole
{
    const SCOPE = 'https://www.googleapis.com/auth/webmasters';
    const API = 'https://www.googleapis.com/webmasters/v3';
    const INSPECT = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';
    const SECRET_KEYS = ['client_secret', 'refresh_token', 'access_token', 'sa_key'];

    /** نرخ کلیک مورد انتظار بر اساس رتبه (میانگین صنعتی، تقریبی) */
    const CTR_CURVE = [1 => 0.28, 2 => 0.155, 3 => 0.11, 4 => 0.08, 5 => 0.06, 6 => 0.047, 7 => 0.037, 8 => 0.03, 9 => 0.025, 10 => 0.021];

    private static $cfg = null;

    private static function boot(): void
    {
        if (!class_exists('SchemaV4')) require_once __DIR__ . '/../core/SchemaV4.php';
        if (!class_exists('Tz')) require_once __DIR__ . '/../core/Tz.php';
        SchemaV4::ensure();
    }

    /* ============================ تنظیمات ============================ */

    public static function cfg(): array
    {
        if (self::$cfg !== null) return self::$cfg;
        $o = ['mode' => '', 'client_id' => '', 'client_secret' => '', 'refresh_token' => '', 'access_token' => '', 'token_expires' => '0', 'sa_key' => '', 'sa_email' => '',
              'site' => '', 'account' => '', 'brand_terms' => '', 'inspect_daily' => '150', 'audit_daily' => '300', 'alerts' => '1', 'last_cron' => '', 'last_snapshot' => ''];
        try {
            foreach (Database::all("SELECT k, v FROM eb_settings WHERE k LIKE 'gsc.%'") as $r) {
                $k = substr($r['k'], 4);
                if (array_key_exists($k, $o)) $o[$k] = in_array($k, self::SECRET_KEYS, true) ? self::dec((string)$r['v']) : (string)$r['v'];
            }
        } catch (Throwable $e) {}
        return self::$cfg = $o;
    }

    public static function set(array $vals): void
    {
        foreach ($vals as $k => $v) {
            $v = (string)$v;
            if (in_array($k, self::SECRET_KEYS, true) && $v !== '') $v = self::enc($v);
            Database::run("INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", ['gsc.' . $k, $v]);
        }
        self::$cfg = null;
    }

    private static function key(): string
    {
        global $config;
        $k = (string)($config['app_key'] ?? '');
        return $k !== '' ? hash('sha256', 'gsc|' . $k, true) : '';
    }

    private static function enc(string $v): string
    {
        $k = self::key();
        if ($k === '' || !function_exists('openssl_encrypt')) return $v;
        $iv = random_bytes(12); $tag = '';
        $c = openssl_encrypt($v, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
        return 'enc1:' . base64_encode($iv . $tag . $c);
    }

    private static function dec(string $v): string
    {
        if (strpos($v, 'enc1:') !== 0) return $v;
        $k = self::key(); $raw = base64_decode(substr($v, 5));
        if ($k === '' || strlen($raw) < 29) return '';
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $p === false ? '' : $p;
    }

    public static function publicState(string $baseUrl): array
    {
        self::boot();
        $c = self::cfg();
        return [
            'connected' => self::connected(), 'mode' => $c['mode'], 'site' => $c['site'], 'account' => $c['account'],
            'client_id' => $c['client_id'], 'has_secret' => $c['client_secret'] !== '', 'sa_email' => $c['sa_email'],
            'brand_terms' => $c['brand_terms'], 'inspect_daily' => (int)$c['inspect_daily'], 'audit_daily' => (int)$c['audit_daily'], 'alerts' => $c['alerts'] === '1',
            'redirect_uri' => rtrim($baseUrl, '/') . '/gsc-oauth.php', 'openssl' => function_exists('openssl_sign'),
            'last_cron' => $c['last_cron'],
        ];
    }

    public static function connected(): bool
    {
        $c = self::cfg();
        return ($c['mode'] === 'oauth' && $c['refresh_token'] !== '') || ($c['mode'] === 'service' && $c['sa_key'] !== '');
    }

    /* ============================ احراز هویت ============================ */

    public static function authUrl(string $baseUrl, string $state): string
    {
        $c = self::cfg();
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $c['client_id'], 'redirect_uri' => rtrim($baseUrl, '/') . '/gsc-oauth.php', 'response_type' => 'code',
            'scope' => self::SCOPE . ' openid email', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state, 'include_granted_scopes' => 'true',
        ]);
    }

    public static function exchangeCode(string $code, string $baseUrl): array
    {
        $c = self::cfg();
        $r = self::http('POST', self::u('https://oauth2.googleapis.com/token'), ['code' => $code, 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
            'redirect_uri' => rtrim($baseUrl, '/') . '/gsc-oauth.php', 'grant_type' => 'authorization_code'], null, true);
        if (empty($r['json']['refresh_token'])) return ['ok' => false, 'error' => self::gErr($r, 'گوگل توکن دسترسی دائمی نداد؛ یک بار دسترسی برنامه را در حساب گوگل حذف و دوباره وصل کنید')];
        $email = '';
        if (!empty($r['json']['id_token'])) { $p = explode('.', $r['json']['id_token']); $j = json_decode(base64_decode(strtr($p[1] ?? '', '-_', '+/')), true); $email = (string)($j['email'] ?? ''); }
        self::set(['mode' => 'oauth', 'refresh_token' => $r['json']['refresh_token'], 'access_token' => $r['json']['access_token'] ?? '',
                   'token_expires' => (string)(time() + (int)($r['json']['expires_in'] ?? 3000) - 60), 'account' => $email]);
        return ['ok' => true];
    }

    /** اتصال با حساب سرویس: فایل JSON کلید */
    public static function connectService(string $json): array
    {
        $j = json_decode($json, true);
        if (!is_array($j) || empty($j['client_email']) || empty($j['private_key'])) return ['ok' => false, 'error' => 'فایل JSON حساب سرویس معتبر نیست'];
        if (!function_exists('openssl_sign')) return ['ok' => false, 'error' => 'افزونه‌ی OpenSSL روی سرور فعال نیست'];
        if (!@openssl_pkey_get_private($j['private_key'])) return ['ok' => false, 'error' => 'کلید خصوصی داخل فایل قابل خواندن نیست'];
        self::set(['mode' => 'service', 'sa_key' => json_encode(['client_email' => $j['client_email'], 'private_key' => $j['private_key'], 'token_uri' => $j['token_uri'] ?? 'https://oauth2.googleapis.com/token']),
                   'sa_email' => $j['client_email'], 'account' => $j['client_email'], 'access_token' => '', 'token_expires' => '0']);
        $t = self::token();
        if (!$t['ok']) { self::disconnect(); return $t; }
        return ['ok' => true, 'email' => $j['client_email']];
    }

    public static function disconnect(): void
    {
        $c = self::cfg();
        if ($c['mode'] === 'oauth' && $c['refresh_token'] !== '') {
            try { self::http('POST', 'https://oauth2.googleapis.com/revoke', ['token' => $c['refresh_token']], null, true); } catch (Throwable $e) {}
        }
        Database::run("DELETE FROM eb_settings WHERE k IN ('gsc.mode','gsc.refresh_token','gsc.access_token','gsc.token_expires','gsc.sa_key','gsc.sa_email','gsc.account')");
        self::$cfg = null;
    }

    /** توکن دسترسی معتبر (با تمدید خودکار) */
    public static function token(): array
    {
        $c = self::cfg();
        if ($c['access_token'] !== '' && (int)$c['token_expires'] > time() + 30) return ['ok' => true, 'token' => $c['access_token']];
        if ($c['mode'] === 'oauth') {
            if ($c['refresh_token'] === '') return ['ok' => false, 'error' => 'سرچ کنسول وصل نیست'];
            $r = self::http('POST', self::u('https://oauth2.googleapis.com/token'), ['client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'refresh_token' => $c['refresh_token'], 'grant_type' => 'refresh_token'], null, true);
        } elseif ($c['mode'] === 'service') {
            $sa = json_decode($c['sa_key'], true) ?: [];
            if (empty($sa['private_key'])) return ['ok' => false, 'error' => 'کلید حساب سرویس پیدا نشد'];
            $now = time();
            $b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claim = $b64(json_encode(['iss' => $sa['client_email'], 'scope' => self::SCOPE, 'aud' => $sa['token_uri'], 'iat' => $now, 'exp' => $now + 3600]));
            $sig = '';
            if (!openssl_sign($head . '.' . $claim, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) return ['ok' => false, 'error' => 'امضای درخواست با کلید حساب سرویس ناموفق بود'];
            $r = self::http('POST', self::u($sa['token_uri']), ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $head . '.' . $claim . '.' . $b64($sig)], null, true);
        } else {
            return ['ok' => false, 'error' => 'سرچ کنسول وصل نیست'];
        }
        if (empty($r['json']['access_token'])) return ['ok' => false, 'error' => self::gErr($r, 'دریافت توکن از گوگل ناموفق بود')];
        self::set(['access_token' => $r['json']['access_token'], 'token_expires' => (string)(time() + (int)($r['json']['expires_in'] ?? 3000) - 60)]);
        return ['ok' => true, 'token' => $r['json']['access_token']];
    }

    /* ============================ HTTP ============================ */

    private static function http(string $method, string $url, $body = null, ?string $token = null, bool $form = false): array
    {
        $headers = ['Accept: application/json'];
        if ($token) $headers[] = 'Authorization: Bearer ' . $token;
        $payload = null;
        if ($body !== null) {
            if ($form) { $payload = http_build_query($body); $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
            else { $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $headers[] = 'Content-Type: application/json'; }
        }
        $build = function (array $proxy) use ($method, $url, $headers, $payload) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 12]
                + ($payload !== null ? [CURLOPT_POSTFIELDS => $payload] : []) + $proxy);
            return $ch;
        };
        if (class_exists('NetProxy')) [$res, $code, $err] = NetProxy::exec('gsc', $build);
        else { $ch = $build([]); $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch); }
        return ['code' => $code, 'json' => is_string($res) ? json_decode($res, true) : null, 'raw' => is_string($res) ? mb_substr($res, 0, 600) : '', 'err' => $err];
    }

    private static function gErr(array $r, string $def): string
    {
        if ($r['code'] === 0) return 'اتصال به گوگل برقرار نشد' . ($r['err'] ? ' (' . $r['err'] . ')' : '') . '. اگر سرور در ایران است، برای «سرچ کنسول گوگل» پروکسی تنظیم کنید.';
        $m = $r['json']['error_description'] ?? ($r['json']['error']['message'] ?? (is_string($r['json']['error'] ?? null) ? $r['json']['error'] : ''));
        if ((int)$r['code'] === 403 && stripos((string)$m, 'permission') !== false) return 'این حساب به این سایت در سرچ کنسول دسترسی ندارد. ایمیل حساب را در سرچ کنسول به‌عنوان کاربر اضافه کنید.';
        return $def . ($m ? ': ' . $m : ' (HTTP ' . $r['code'] . ')');
    }

    /** فراخوانی API با توکن */
    public static function api(string $method, string $url, $body = null): array
    {
        $t = self::token();
        if (!$t['ok']) return $t;
        $r = self::http($method, $url, $body, $t['token']);
        if ($r['code'] === 401) {   // توکن باطل شده؛ یک بار دیگر
            self::set(['access_token' => '', 'token_expires' => '0']);
            $t = self::token(); if (!$t['ok']) return $t;
            $r = self::http($method, $url, $body, $t['token']);
        }
        if ($r['code'] >= 200 && $r['code'] < 300) return ['ok' => true, 'data' => $r['json'] ?? []];
        return ['ok' => false, 'error' => self::gErr($r, 'خطای سرچ کنسول'), 'code' => $r['code']];
    }

    /** آدرس سرویس‌های گوگل (در محیط آزمایش با متغیر محیطی EB_GSC_FAKE به سرور ساختگی می‌رود) */
    private static function u(string $url): string
    {
        $fake = getenv('EB_GSC_FAKE');
        if (!$fake) return $url;
        return str_replace(['https://www.googleapis.com/webmasters/v3', 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', 'https://oauth2.googleapis.com/token'],
                           [$fake . '/wm', $fake . '/inspect', $fake . '/token'], $url);
    }

    private static function siteEnc(string $site): string { return rawurlencode($site); }

    private static function cached(string $key, int $ttl, callable $fn): array
    {
        $k = md5($key);
        try {
            $row = Database::one("SELECT v FROM eb_gsc_cache WHERE k = ? AND expires_at > NOW()", [$k]);
            if ($row) { $v = json_decode((string)$row['v'], true); if (is_array($v)) return $v + ['cached' => true]; }
        } catch (Throwable $e) {}
        $v = $fn();
        if (!empty($v['ok'])) {
            try { Database::run("INSERT INTO eb_gsc_cache (k, v, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE v = VALUES(v), expires_at = VALUES(expires_at)", [$k, json_encode($v, JSON_UNESCAPED_UNICODE), $ttl]); } catch (Throwable $e) {}
        }
        return $v;
    }

    public static function clearCache(): void { try { Database::run("DELETE FROM eb_gsc_cache"); } catch (Throwable $e) {} }

    /* ============================ سایت‌ها ============================ */

    public static function sites(): array
    {
        self::boot();
        $r = self::api('GET', self::u(self::API) . '/sites');
        if (!$r['ok']) return $r;
        $sites = array_map(fn($s) => ['url' => $s['siteUrl'], 'level' => $s['permissionLevel'] ?? ''], $r['data']['siteEntry'] ?? []);
        usort($sites, fn($a, $b) => strcmp($a['url'], $b['url']));
        return ['ok' => true, 'sites' => $sites];
    }

    private static function site(): string
    {
        $s = self::cfg()['site'];
        if ($s === '') throw new RuntimeException('ابتدا سایت را از فهرست سرچ کنسول انتخاب کنید');
        return $s;
    }

    /* ============================ عملکرد ============================ */

    /** بازه با تأخیر ۲ روزه‌ی داده‌های گوگل */
    public static function range(array $in): array
    {
        $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['to'] ?? '')) ? $in['to'] : gmdate('Y-m-d', time() - 2 * 86400);
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['from'] ?? '')) ? $in['from'] : gmdate('Y-m-d', strtotime($end . ' UTC') - (max(1, min(480, (int)($in['days'] ?? 28))) - 1) * 86400);
        if ($start > $end) [$start, $end] = [$end, $start];
        $span = (int)round((strtotime($end . ' UTC') - strtotime($start . ' UTC')) / 86400) + 1;
        $pEnd = gmdate('Y-m-d', strtotime($start . ' UTC') - 86400);
        $pStart = gmdate('Y-m-d', strtotime($pEnd . ' UTC') - ($span - 1) * 86400);
        return ['from' => $start, 'to' => $end, 'days' => $span, 'prev_from' => $pStart, 'prev_to' => $pEnd];
    }

    private static function filterGroups(array $f): array
    {
        $filters = [];
        $map = ['query' => 'query', 'page' => 'page', 'country' => 'country', 'device' => 'device'];
        foreach ($map as $k => $dim) {
            $v = trim((string)($f[$k] ?? ''));
            if ($v === '') continue;
            $op = $f[$k . '_op'] ?? ($dim === 'country' || $dim === 'device' ? 'equals' : 'contains');
            if (!in_array($op, ['contains', 'equals', 'notContains', 'notEquals', 'includingRegex', 'excludingRegex'], true)) $op = 'contains';
            $filters[] = ['dimension' => $dim, 'operator' => $op, 'expression' => $dim === 'device' ? strtoupper($v) : ($dim === 'country' ? strtolower($v) : $v)];
        }
        // برند / غیربرند
        $brand = trim(self::cfg()['brand_terms']);
        if (!empty($f['brand']) && $brand !== '') {
            $re = implode('|', array_map(fn($t) => preg_quote(trim($t), '/'), array_filter(preg_split('/[\r\n,،]+/u', $brand))));
            if ($re !== '') $filters[] = ['dimension' => 'query', 'operator' => $f['brand'] === 'brand' ? 'includingRegex' : 'excludingRegex', 'expression' => '(?i)(' . $re . ')'];
        }
        return $filters ? [['groupType' => 'and', 'filters' => $filters]] : [];
    }

    private static function query(array $body): array
    {
        $site = self::site();
        $key = 'q|' . $site . '|' . json_encode($body);
        return self::cached($key, 3 * 3600, function () use ($site, $body) {
            $r = self::api('POST', self::u(self::API) . '/sites/' . self::siteEnc($site) . '/searchAnalytics/query', $body);
            if (!$r['ok']) return $r;
            return ['ok' => true, 'rows' => $r['data']['rows'] ?? []];
        });
    }

    private static function totals(array $rows): array
    {
        $c = 0; $i = 0; $pw = 0;
        foreach ($rows as $r) { $c += $r['clicks']; $i += $r['impressions']; $pw += $r['position'] * $r['impressions']; }
        return ['clicks' => (int)$c, 'impressions' => (int)$i, 'ctr' => $i ? round($c / $i * 100, 2) : 0, 'position' => $i ? round($pw / $i, 1) : 0];
    }

    /** داشبورد عملکرد */
    public static function performance(array $in): array
    {
        self::boot();
        try { $site = self::site(); } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        $rg = self::range($in);
        $type = (string)($in['type'] ?? 'web');
        if (!in_array($type, ['web', 'image', 'video', 'news', 'discover', 'googleNews'], true)) $type = 'web';
        $fg = self::filterGroups((array)($in['filters'] ?? []));
        $base = ['type' => $type, 'dataState' => 'all'] + ($fg ? ['dimensionFilterGroups' => $fg] : []);

        $daily = self::query($base + ['startDate' => $rg['from'], 'endDate' => $rg['to'], 'dimensions' => ['date'], 'rowLimit' => 500]);
        if (!$daily['ok']) return $daily;
        $prevDaily = self::query($base + ['startDate' => $rg['prev_from'], 'endDate' => $rg['prev_to'], 'dimensions' => ['date'], 'rowLimit' => 500]);
        $dims = [];
        foreach (['query' => 250, 'page' => 250, 'country' => 50, 'device' => 5] as $dim => $lim) {
            if ($type === 'discover' && $dim === 'query') { $dims[$dim] = []; continue; }
            $cur = self::query($base + ['startDate' => $rg['from'], 'endDate' => $rg['to'], 'dimensions' => [$dim], 'rowLimit' => $lim]);
            $prev = self::query($base + ['startDate' => $rg['prev_from'], 'endDate' => $rg['prev_to'], 'dimensions' => [$dim], 'rowLimit' => $lim * 2]);
            $pm = [];
            foreach (($prev['rows'] ?? []) as $r) $pm[$r['keys'][0]] = $r;
            $dims[$dim] = array_map(function ($r) use ($pm) {
                $p = $pm[$r['keys'][0]] ?? null;
                return ['key' => $r['keys'][0], 'clicks' => (int)$r['clicks'], 'impressions' => (int)$r['impressions'], 'ctr' => round($r['ctr'] * 100, 2), 'position' => round($r['position'], 1),
                        'prev_clicks' => $p ? (int)$p['clicks'] : null, 'prev_position' => $p ? round($p['position'], 1) : null];
            }, $cur['rows'] ?? []);
        }
        $appearance = self::query($base + ['startDate' => $rg['from'], 'endDate' => $rg['to'], 'dimensions' => ['searchAppearance'], 'rowLimit' => 30]);
        $byDay = []; foreach ($daily['rows'] as $r) $byDay[$r['keys'][0]] = $r;
        $series = ['labels' => [], 'clicks' => [], 'impressions' => [], 'ctr' => [], 'position' => []];
        for ($t = strtotime($rg['from'] . ' UTC'); $t <= strtotime($rg['to'] . ' UTC'); $t += 86400) {
            $d = gmdate('Y-m-d', $t); $r = $byDay[$d] ?? null;
            $series['labels'][] = $d; $series['clicks'][] = (int)($r['clicks'] ?? 0); $series['impressions'][] = (int)($r['impressions'] ?? 0);
            $series['ctr'][] = $r ? round($r['ctr'] * 100, 2) : 0; $series['position'][] = $r ? round($r['position'], 1) : null;
        }
        return ['ok' => true, 'site' => $site, 'range' => $rg, 'type' => $type, 'totals' => self::totals($daily['rows']), 'prev' => self::totals($prevDaily['rows'] ?? []),
                'series' => $series, 'queries' => $dims['query'], 'pages' => $dims['page'], 'countries' => $dims['country'], 'devices' => $dims['device'],
                'appearance' => array_map(fn($r) => ['key' => $r['keys'][0], 'clicks' => (int)$r['clicks'], 'impressions' => (int)$r['impressions']], $appearance['rows'] ?? [])];
    }

    /** کلمات کلیدی یک صفحه یا صفحه‌های یک کلمه */
    public static function drill(array $in): array
    {
        self::boot();
        try { self::site(); } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        $rg = self::range($in);
        $dim = ($in['dim'] ?? 'query') === 'page' ? 'page' : 'query';
        $other = $dim === 'page' ? 'query' : 'page';
        $r = self::query(['type' => 'web', 'startDate' => $rg['from'], 'endDate' => $rg['to'], 'dimensions' => [$other], 'rowLimit' => 100,
            'dimensionFilterGroups' => [['filters' => [['dimension' => $dim, 'operator' => 'equals', 'expression' => (string)($in['key'] ?? '')]]]]]);
        if (!$r['ok']) return $r;
        $d = self::query(['type' => 'web', 'startDate' => $rg['from'], 'endDate' => $rg['to'], 'dimensions' => ['date'], 'rowLimit' => 500,
            'dimensionFilterGroups' => [['filters' => [['dimension' => $dim, 'operator' => 'equals', 'expression' => (string)($in['key'] ?? '')]]]]]);
        return ['ok' => true, 'rows' => array_map(fn($x) => ['key' => $x['keys'][0], 'clicks' => (int)$x['clicks'], 'impressions' => (int)$x['impressions'], 'ctr' => round($x['ctr'] * 100, 2), 'position' => round($x['position'], 1)], $r['rows']),
                'daily' => array_map(fn($x) => ['d' => $x['keys'][0], 'clicks' => (int)$x['clicks'], 'position' => round($x['position'], 1)], $d['rows'] ?? [])];
    }

    /** فرصت‌های سئو */
    public static function opportunities(array $in): array
    {
        self::boot();
        try { self::site(); } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        $rg = self::range(['days' => (int)($in['days'] ?? 28)]);
        $qp = self::query(['type' => 'web', 'startDate' => $rg['from'], 'endDate' => $rg['to'], 'dimensions' => ['query', 'page'], 'rowLimit' => 5000]);
        if (!$qp['ok']) return $qp;
        $q = self::query(['type' => 'web', 'startDate' => $rg['from'], 'endDate' => $rg['to'], 'dimensions' => ['query'], 'rowLimit' => 2000]);
        $qPrev = self::query(['type' => 'web', 'startDate' => $rg['prev_from'], 'endDate' => $rg['prev_to'], 'dimensions' => ['query'], 'rowLimit' => 2000]);
        $p = self::query(['type' => 'web', 'startDate' => $rg['from'], 'endDate' => $rg['to'], 'dimensions' => ['page'], 'rowLimit' => 1000]);
        $pPrev = self::query(['type' => 'web', 'startDate' => $rg['prev_from'], 'endDate' => $rg['prev_to'], 'dimensions' => ['page'], 'rowLimit' => 1000]);
        $row = fn($r) => ['key' => $r['keys'][0], 'clicks' => (int)$r['clicks'], 'impressions' => (int)$r['impressions'], 'ctr' => round($r['ctr'] * 100, 2), 'position' => round($r['position'], 1)];

        // کلمات نزدیک صفحه‌ی اول (رتبه ۸ تا ۲۰ با نمایش قابل توجه)
        $striking = [];
        foreach ($q['rows'] ?? [] as $r) if ($r['position'] >= 8 && $r['position'] <= 20 && $r['impressions'] >= 30) $striking[] = $row($r) + ['potential' => (int)round($r['impressions'] * 0.1)];
        usort($striking, fn($a, $b) => $b['impressions'] <=> $a['impressions']);

        // CTR کمتر از انتظار برای رتبه
        $lowCtr = [];
        foreach ($p['rows'] ?? [] as $r) {
            $pos = (int)max(1, round($r['position']));
            if ($pos > 10 || $r['impressions'] < 100) continue;
            $exp = self::CTR_CURVE[$pos];
            if ($r['ctr'] < $exp * 0.6) $lowCtr[] = $row($r) + ['expected_ctr' => round($exp * 100, 1), 'lost_clicks' => (int)round(($exp - $r['ctr']) * $r['impressions'])];
        }
        usort($lowCtr, fn($a, $b) => $b['lost_clicks'] <=> $a['lost_clicks']);

        // هم‌نوع‌خواری: یک کلمه، چند صفحه
        $byQ = [];
        foreach ($qp['rows'] as $r) { if ($r['impressions'] < 10) continue; $byQ[$r['keys'][0]][] = ['page' => $r['keys'][1], 'clicks' => (int)$r['clicks'], 'impressions' => (int)$r['impressions'], 'position' => round($r['position'], 1)]; }
        $cannibal = [];
        foreach ($byQ as $kw => $pages) {
            if (count($pages) < 2) continue;
            usort($pages, fn($a, $b) => $b['impressions'] <=> $a['impressions']);
            $share = $pages[1]['impressions'] / max(1, $pages[0]['impressions']);
            if ($share < 0.2) continue;
            $cannibal[] = ['query' => $kw, 'pages' => array_slice($pages, 0, 4), 'impressions' => array_sum(array_column($pages, 'impressions'))];
        }
        usort($cannibal, fn($a, $b) => $b['impressions'] <=> $a['impressions']);

        // افت و رشد
        $delta = function ($cur, $prev) use ($row) {
            $pm = []; foreach ($prev as $r) $pm[$r['keys'][0]] = $r;
            $out = [];
            foreach ($cur as $r) { $pr = $pm[$r['keys'][0]] ?? null; $out[] = $row($r) + ['prev_clicks' => $pr ? (int)$pr['clicks'] : 0, 'change' => (int)$r['clicks'] - ($pr ? (int)$pr['clicks'] : 0)]; unset($pm[$r['keys'][0]]); }
            foreach ($pm as $k => $pr) $out[] = ['key' => $k, 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0, 'prev_clicks' => (int)$pr['clicks'], 'change' => -(int)$pr['clicks']];
            return $out;
        };
        $qd = $delta($q['rows'] ?? [], $qPrev['rows'] ?? []);
        $pd = $delta($p['rows'] ?? [], $pPrev['rows'] ?? []);
        usort($qd, fn($a, $b) => $a['change'] <=> $b['change']); $qDrop = array_values(array_filter(array_slice($qd, 0, 30), fn($x) => $x['change'] < 0));
        usort($pd, fn($a, $b) => $a['change'] <=> $b['change']); $pDrop = array_values(array_filter(array_slice($pd, 0, 30), fn($x) => $x['change'] < 0));
        usort($qd, fn($a, $b) => $b['change'] <=> $a['change']); $qRise = array_values(array_filter(array_slice($qd, 0, 30), fn($x) => $x['change'] > 0));
        $new = array_values(array_filter($qd, fn($x) => $x['prev_clicks'] === 0 && $x['clicks'] > 0));
        usort($new, fn($a, $b) => $b['impressions'] <=> $a['impressions']);

        return ['ok' => true, 'range' => $rg, 'striking' => array_slice($striking, 0, 50), 'low_ctr' => array_slice($lowCtr, 0, 40), 'cannibal' => array_slice($cannibal, 0, 40),
                'query_drops' => $qDrop, 'page_drops' => $pDrop, 'query_rises' => $qRise, 'new_queries' => array_slice($new, 0, 40)];
    }

    /* ============================ نقشه‌ی سایت ============================ */

    public static function sitemaps(): array
    {
        self::boot();
        try { $site = self::site(); } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        $r = self::api('GET', self::u(self::API) . '/sites/' . self::siteEnc($site) . '/sitemaps');
        if (!$r['ok']) return $r;
        $list = array_map(function ($s) {
            $sub = 0; $idx = 0;
            foreach ($s['contents'] ?? [] as $c) { $sub += (int)($c['submitted'] ?? 0); $idx += (int)($c['indexed'] ?? 0); }
            return ['path' => $s['path'], 'last_submitted' => $s['lastSubmitted'] ?? null, 'last_downloaded' => $s['lastDownloaded'] ?? null, 'pending' => !empty($s['isPending']),
                    'is_index' => !empty($s['isSitemapsIndex']), 'type' => $s['type'] ?? '', 'warnings' => (int)($s['warnings'] ?? 0), 'errors' => (int)($s['errors'] ?? 0), 'submitted' => $sub, 'indexed' => $idx];
        }, $r['data']['sitemap'] ?? []);
        $stats = Database::one("SELECT COUNT(*) total, SUM(verdict='PASS') indexed, SUM(verdict IS NOT NULL AND verdict<>'PASS') not_indexed, SUM(verdict IS NULL) unchecked, SUM(http_status >= 400) broken FROM eb_gsc_urls WHERE site = ?", [$site]) ?: [];
        return ['ok' => true, 'sitemaps' => $list, 'urls' => array_map('intval', $stats)];
    }

    public static function submitSitemap(string $url, bool $delete = false): array
    {
        self::boot();
        try { $site = self::site(); } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        if (!preg_match('~^https?://~i', $url)) return ['ok' => false, 'error' => 'آدرس کامل نقشه‌ی سایت را وارد کنید (با https://)'];
        $r = self::api($delete ? 'DELETE' : 'PUT', self::u(self::API) . '/sites/' . self::siteEnc($site) . '/sitemaps/' . rawurlencode($url));
        return $r['ok'] ? ['ok' => true] : $r;
    }

    /** خواندن آدرس‌های نقشه‌ی سایت (با پشتیبانی از sitemap index و gzip) و ذخیره برای بررسی */
    public static function importSitemap(string $url, int $max = 5000): array
    {
        self::boot();
        try { $site = self::site(); } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        $seen = []; $queue = [$url]; $added = 0; $total = 0; $files = 0; $errors = [];
        while ($queue && $files < 60 && $total < $max) {
            $sm = array_shift($queue); if (isset($seen[$sm])) continue; $seen[$sm] = 1; $files++;
            $f = self::fetch($sm, 20, 15 * 1024 * 1024);
            if ($f['code'] !== 200 || $f['body'] === '') { $errors[] = $sm . ' (HTTP ' . $f['code'] . ')'; continue; }
            $xml = $f['body'];
            if (substr($xml, 0, 2) === "\x1f\x8b") $xml = (string)@gzdecode($xml);
            $prev = libxml_use_internal_errors(true);
            $doc = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            libxml_use_internal_errors($prev);
            if (!$doc) { $errors[] = $sm . ' (XML نامعتبر)'; continue; }
            $ns = $doc->getNamespaces(true); $doc->registerXPathNamespace('s', $ns[''] ?? 'http://www.sitemaps.org/schemas/sitemap/0.9');
            foreach ($doc->xpath('//s:sitemap/s:loc') ?: [] as $loc) $queue[] = trim((string)$loc);
            foreach ($doc->xpath('//s:url') ?: [] as $u) {
                if ($total >= $max) break;
                $u->registerXPathNamespace('s', $ns[''] ?? 'http://www.sitemaps.org/schemas/sitemap/0.9');
                $loc = trim((string)($u->xpath('s:loc')[0] ?? '')); $lm = trim((string)($u->xpath('s:lastmod')[0] ?? ''));
                if ($loc === '' || !preg_match('~^https?://~i', $loc)) continue;
                $total++;
                $st = Database::run("INSERT INTO eb_gsc_urls (site, url, url_hash, source, sitemap, lastmod) VALUES (?, ?, ?, 'sitemap', ?, ?) ON DUPLICATE KEY UPDATE sitemap = VALUES(sitemap), lastmod = VALUES(lastmod)",
                    [$site, mb_substr($loc, 0, 700), md5($loc), mb_substr($sm, 0, 500), mb_substr($lm, 0, 40) ?: null]);
                if ($st->rowCount() === 1) $added++;
            }
        }
        return ['ok' => true, 'files' => $files, 'total' => $total, 'added' => $added, 'errors' => array_slice($errors, 0, 10)];
    }

    /* ============================ بررسی ایندکس ============================ */

    public static function inspect(string $url, bool $save = true): array
    {
        self::boot();
        try { $site = self::site(); } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        $r = self::api('POST', self::u(self::INSPECT), ['inspectionUrl' => $url, 'siteUrl' => $site, 'languageCode' => 'fa-IR']);
        if (!$r['ok']) return $r;
        $res = $r['data']['inspectionResult'] ?? [];
        $ix = $res['indexStatusResult'] ?? [];
        $out = [
            'url' => $url, 'link' => $res['inspectionResultLink'] ?? null,
            'verdict' => $ix['verdict'] ?? 'VERDICT_UNSPECIFIED', 'coverage' => $ix['coverageState'] ?? '', 'robots' => $ix['robotsTxtState'] ?? '',
            'indexing' => $ix['indexingState'] ?? '', 'fetch' => $ix['pageFetchState'] ?? '', 'last_crawl' => $ix['lastCrawlTime'] ?? null,
            'google_canonical' => $ix['googleCanonical'] ?? null, 'user_canonical' => $ix['userCanonical'] ?? null, 'crawled_as' => $ix['crawledAs'] ?? null,
            'sitemaps' => $ix['sitemap'] ?? [], 'referrers' => array_slice($ix['referringUrls'] ?? [], 0, 10),
            'mobile' => $res['mobileUsabilityResult']['verdict'] ?? null, 'mobile_issues' => array_map(fn($i) => $i['message'] ?? $i['issueType'] ?? '', $res['mobileUsabilityResult']['issues'] ?? []),
            'rich' => $res['richResultsResult']['verdict'] ?? null,
            'rich_items' => array_map(fn($d) => ['type' => $d['richResultType'] ?? '', 'issues' => count($d['items'][0]['issues'] ?? [])], $res['richResultsResult']['detectedItems'] ?? []),
        ];
        if ($save) {
            $lc = $out['last_crawl'] ? gmdate('Y-m-d H:i:s', strtotime($out['last_crawl'])) : null;
            Database::run("INSERT INTO eb_gsc_urls (site, url, url_hash, source, verdict, coverage, last_crawl, inspected_at) VALUES (?, ?, ?, 'manual', ?, ?, ?, NOW())
                           ON DUPLICATE KEY UPDATE verdict = VALUES(verdict), coverage = VALUES(coverage), last_crawl = VALUES(last_crawl), inspected_at = NOW()",
                [$site, mb_substr($url, 0, 700), md5($url), $out['verdict'], mb_substr((string)$out['coverage'], 0, 190), $lc]);
        }
        return ['ok' => true, 'result' => $out];
    }

    /** آدرس‌های ذخیره‌شده با فیلتر */
    public static function urls(array $in): array
    {
        self::boot();
        try { $site = self::site(); } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
        $w = ['site = ?']; $p = [$site];
        $f = (string)($in['filter'] ?? '');
        if ($f === 'indexed') $w[] = "verdict = 'PASS'";
        elseif ($f === 'not_indexed') $w[] = "verdict IS NOT NULL AND verdict <> 'PASS'";
        elseif ($f === 'unchecked') $w[] = 'verdict IS NULL';
        elseif ($f === 'issues') $w[] = "(issues_json IS NOT NULL AND issues_json <> '[]')";
        elseif ($f === 'broken') $w[] = 'http_status >= 400';
        if (trim((string)($in['q'] ?? '')) !== '') { $w[] = 'url LIKE ?'; $p[] = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($in['q'])) . '%'; }
        $page = max(1, (int)($in['page'] ?? 1)); $per = 50;
        $total = (int)(Database::one("SELECT COUNT(*) c FROM eb_gsc_urls WHERE " . implode(' AND ', $w), $p)['c'] ?? 0);
        $rows = Database::all("SELECT id, url, source, lastmod, http_status, title, meta_desc, h1, canonical, robots, words, load_ms, issues_json, verdict, coverage, last_crawl, inspected_at, crawled_at
                               FROM eb_gsc_urls WHERE " . implode(' AND ', $w) . " ORDER BY id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $p);
        foreach ($rows as &$r) { $r['issues'] = json_decode((string)$r['issues_json'], true) ?: []; unset($r['issues_json']); }
        unset($r);
        $issues = [];
        foreach (Database::all("SELECT issues_json FROM eb_gsc_urls WHERE site = ? AND issues_json IS NOT NULL AND issues_json <> '[]'", [$site]) as $x)
            foreach (json_decode((string)$x['issues_json'], true) ?: [] as $i) $issues[$i] = ($issues[$i] ?? 0) + 1;
        arsort($issues);
        $coverage = Database::all("SELECT COALESCE(coverage, 'بررسی‌نشده') k, COUNT(*) c FROM eb_gsc_urls WHERE site = ? GROUP BY coverage ORDER BY c DESC LIMIT 20", [$site]);
        return ['ok' => true, 'rows' => $rows, 'total' => $total, 'pages' => max(1, (int)ceil($total / $per)), 'page' => $page, 'issue_counts' => $issues, 'coverage' => $coverage];
    }

    public static function deleteUrls(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return;
        Database::run("DELETE FROM eb_gsc_urls WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", $ids);
    }

    /* ============================ بازرسی فنی صفحه ============================ */

    private static function fetch(string $url, int $timeout = 15, int $max = 3 * 1024 * 1024): array
    {
        // فقط آدرس‌های عمومی (جلوگیری از دسترسی به شبکه‌ی داخلی سرور)
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
        if (!preg_match('~^https?://~i', $url) || $host === '' || $host === 'localhost'
            || ($ip && filter_var($ip, FILTER_VALIDATE_IP) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            return ['code' => 0, 'body' => '', 'redirect' => '', 'ms' => 0, 'type' => ''];
        }
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_MAXFILESIZE => $max, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ElanbanSEO/1.0)', CURLOPT_HEADER => false]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return ['code' => $code, 'body' => is_string($body) ? $body : '', 'redirect' => $loc, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'type' => $ctype];
    }

    public static function auditUrl(string $url): array
    {
        $f = self::fetch($url);
        $out = ['http_status' => $f['code'], 'load_ms' => $f['ms'], 'title' => null, 'meta_desc' => null, 'h1' => null, 'canonical' => null, 'robots' => null, 'words' => null, 'issues' => []];
        if ($f['code'] === 0) { $out['issues'][] = 'باز نشد'; return $out; }
        if ($f['code'] >= 300 && $f['code'] < 400) { $out['issues'][] = 'ریدایرکت در نقشه‌ی سایت'; $out['canonical'] = $f['redirect'] ?: null; return $out; }
        if ($f['code'] >= 400) { $out['issues'][] = 'خطای ' . $f['code']; return $out; }
        if (stripos($f['type'], 'html') === false) return $out;
        $html = $f['body'];
        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($dom);
        $t = trim((string)($xp->evaluate('string(//title)')));
        $d = trim((string)$xp->evaluate('string(//meta[translate(@name,"DESCRIPTION","description")="description"]/@content)'));
        $h1s = $xp->query('//h1');
        $can = trim((string)$xp->evaluate('string(//link[translate(@rel,"CANONICAL","canonical")="canonical"]/@href)'));
        $rob = trim((string)$xp->evaluate('string(//meta[translate(@name,"ROBOTS","robots")="robots"]/@content)'));
        $bodyText = trim(preg_replace('/\s+/u', ' ', (string)$xp->evaluate('string(//body)')));
        $out['title'] = mb_substr($t, 0, 300) ?: null; $out['meta_desc'] = mb_substr($d, 0, 500) ?: null;
        $out['h1'] = $h1s->length ? mb_substr(trim($h1s->item(0)->textContent), 0, 300) : null;
        $out['canonical'] = $can ?: null; $out['robots'] = mb_substr($rob, 0, 120) ?: null;
        $out['words'] = $bodyText === '' ? 0 : count(preg_split('/\s+/u', $bodyText));
        $tl = mb_strlen($t); $dl = mb_strlen($d);
        if ($t === '') $out['issues'][] = 'بدون عنوان';
        elseif ($tl > 65) $out['issues'][] = 'عنوان طولانی';
        elseif ($tl < 20) $out['issues'][] = 'عنوان کوتاه';
        if ($d === '') $out['issues'][] = 'بدون توضیحات متا';
        elseif ($dl > 170) $out['issues'][] = 'توضیحات متا طولانی';
        elseif ($dl < 70) $out['issues'][] = 'توضیحات متا کوتاه';
        if (!$h1s->length) $out['issues'][] = 'بدون H1';
        elseif ($h1s->length > 1) $out['issues'][] = 'چند H1';
        if ($rob !== '' && stripos($rob, 'noindex') !== false) $out['issues'][] = 'noindex در نقشه‌ی سایت';
        if ($can !== '' && rtrim($can, '/') !== rtrim($url, '/')) $out['issues'][] = 'canonical به صفحه‌ی دیگر';
        if ($out['words'] !== null && $out['words'] < 250) $out['issues'][] = 'محتوای کم';
        if ($f['ms'] > 2500) $out['issues'][] = 'پاسخ کند سرور';
        if (!preg_match('/<img[^>]+alt=/i', $html) && preg_match('/<img/i', $html)) $out['issues'][] = 'تصویر بدون alt';
        return $out;
    }

    public static function audit(int $id): array
    {
        self::boot();
        $u = Database::one("SELECT id, url FROM eb_gsc_urls WHERE id = ?", [$id]);
        if (!$u) return ['ok' => false, 'error' => 'آدرس پیدا نشد'];
        $a = self::auditUrl($u['url']);
        Database::run("UPDATE eb_gsc_urls SET http_status=?, load_ms=?, title=?, meta_desc=?, h1=?, canonical=?, robots=?, words=?, issues_json=?, crawled_at=NOW() WHERE id=?",
            [$a['http_status'], $a['load_ms'], $a['title'], $a['meta_desc'], $a['h1'], $a['canonical'], $a['robots'], $a['words'], json_encode($a['issues'], JSON_UNESCAPED_UNICODE), $id]);
        // عنوان تکراری
        if ($a['title']) {
            $dup = (int)(Database::one("SELECT COUNT(*) c FROM eb_gsc_urls WHERE site = (SELECT site FROM eb_gsc_urls WHERE id = ?) AND title = ? AND id <> ?", [$id, $a['title'], $id])['c'] ?? 0);
            if ($dup) { $a['issues'][] = 'عنوان تکراری'; Database::run("UPDATE eb_gsc_urls SET issues_json=? WHERE id=?", [json_encode($a['issues'], JSON_UNESCAPED_UNICODE), $id]); }
        }
        return ['ok' => true, 'audit' => $a];
    }

    /* ============================ ساخت نقشه‌ی سایت ============================ */

    public static function generateSitemap(string $start, int $limit = 300): array
    {
        self::boot();
        if (!preg_match('~^https?://~i', $start)) return ['ok' => false, 'error' => 'آدرس کامل سایت را وارد کنید'];
        $host = strtolower((string)parse_url($start, PHP_URL_HOST));
        $limit = max(10, min(1000, $limit));
        $queue = [$start]; $seen = [self::normUrl($start) => 1]; $pages = []; $t0 = time();
        while ($queue && count($pages) < $limit && time() - $t0 < 50) {
            $url = array_shift($queue);
            $f = self::fetch($url, 10, 2 * 1024 * 1024);
            if ($f['code'] !== 200 || stripos($f['type'], 'html') === false) continue;
            if (preg_match('/<meta[^>]+name=["\']robots["\'][^>]+noindex/i', $f['body'])) continue;
            $pages[] = $url;
            if (!preg_match_all('/<a\s[^>]*href=["\']([^"\'#]+)/i', $f['body'], $m)) continue;
            foreach ($m[1] as $href) {
                $abs = self::absUrl(html_entity_decode($href), $url);
                if (!$abs || strtolower((string)parse_url($abs, PHP_URL_HOST)) !== $host) continue;
                if (preg_match('~\.(jpe?g|png|gif|webp|svg|pdf|zip|rar|mp4|mp3|css|js|xml|ico)(\?|$)~i', $abs)) continue;
                if (preg_match('~/(wp-admin|wp-login|cart|checkout|my-account|login|register|feed)(/|$)|[?&](add-to-cart|replytocom|s)=~i', $abs)) continue;
                $n = self::normUrl($abs);
                if (isset($seen[$n])) continue;
                $seen[$n] = 1; $queue[] = $abs;
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($pages as $p) $xml .= '  <url><loc>' . htmlspecialchars($p, ENT_XML1) . '</loc><lastmod>' . gmdate('Y-m-d') . '</lastmod></url>' . "\n";
        $xml .= '</urlset>' . "\n";
        return ['ok' => true, 'count' => count($pages), 'xml' => $xml, 'partial' => (bool)$queue];
    }

    private static function normUrl(string $u): string
    {
        $p = parse_url($u);
        return strtolower(($p['host'] ?? '')) . rtrim($p['path'] ?? '/', '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    private static function absUrl(string $href, string $base): ?string
    {
        $href = trim($href);
        if ($href === '' || preg_match('~^(mailto|tel|javascript|data):~i', $href)) return null;
        if (preg_match('~^https?://~i', $href)) return $href;
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if (strpos($href, '//') === 0) return ($b['scheme'] ?? 'https') . ':' . $href;
        if ($href[0] === '/') return $origin . $href;
        $dir = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/');
        return $origin . $dir . $href;
    }

    /* ============================ cron و هشدار ============================ */

    public static function cronTick(): void
    {
        self::boot();
        if (!self::connected() || self::cfg()['site'] === '') return;
        $c = self::cfg();
        // حداکثر هر ۱۵ دقیقه یک دسته
        if ($c['last_cron'] !== '' && time() - (int)$c['last_cron'] < 900) return;
        self::set(['last_cron' => (string)time()]);
        $site = $c['site'];

        // بررسی ایندکس خودکار با سهمیه‌ی روزانه (گوگل: ۲۰۰۰ در روز برای هر سایت)
        $daily = max(0, min(1900, (int)$c['inspect_daily']));
        $doneToday = (int)(Database::one("SELECT COUNT(*) c FROM eb_gsc_urls WHERE site = ? AND inspected_at >= CURDATE()", [$site])['c'] ?? 0);
        $batch = min(25, $daily - $doneToday);
        if ($batch > 0) {
            foreach (Database::all("SELECT url FROM eb_gsc_urls WHERE site = ? AND (inspected_at IS NULL OR inspected_at < DATE_SUB(NOW(), INTERVAL 14 DAY)) ORDER BY inspected_at IS NOT NULL, inspected_at LIMIT " . $batch, [$site]) as $u) {
                $r = self::inspect($u['url']);
                if (!$r['ok'] && in_array((int)($r['code'] ?? 0), [429, 403], true)) break;
            }
        }
        // بازرسی فنی صفحه‌ها
        $aDaily = max(0, min(3000, (int)$c['audit_daily']));
        $aToday = (int)(Database::one("SELECT COUNT(*) c FROM eb_gsc_urls WHERE site = ? AND crawled_at >= CURDATE()", [$site])['c'] ?? 0);
        $aBatch = min(30, $aDaily - $aToday);
        if ($aBatch > 0) foreach (Database::all("SELECT id FROM eb_gsc_urls WHERE site = ? AND (crawled_at IS NULL OR crawled_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) ORDER BY crawled_at IS NOT NULL, crawled_at LIMIT " . $aBatch, [$site]) as $u) self::audit((int)$u['id']);

        // عکس روزانه و هشدارها، یک بار در روز
        if ($c['last_snapshot'] !== gmdate('Y-m-d')) {
            self::set(['last_snapshot' => gmdate('Y-m-d')]);
            self::snapshot($site);
            if ($c['alerts'] === '1') self::alerts($site);
        }
    }

    private static function snapshot(string $site): void
    {
        $rg = self::range(['days' => 30]);
        $r = self::query(['type' => 'web', 'startDate' => $rg['from'], 'endDate' => $rg['to'], 'dimensions' => ['date'], 'rowLimit' => 100, 'dataState' => 'final']);
        foreach ($r['rows'] ?? [] as $x) {
            Database::run("INSERT INTO eb_gsc_daily (site, day, clicks, impressions, ctr, position) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE clicks = VALUES(clicks), impressions = VALUES(impressions), ctr = VALUES(ctr), position = VALUES(position)",
                [$site, $x['keys'][0], (int)$x['clicks'], (int)$x['impressions'], round($x['ctr'], 4), round($x['position'], 2)]);
        }
    }

    private static function alerts(string $site): void
    {
        $add = function (string $kind, string $title, string $detail = '') use ($site) {
            if (Database::one("SELECT id FROM eb_gsc_alerts WHERE site = ? AND kind = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)", [$site, $kind])) return;
            Database::run("INSERT INTO eb_gsc_alerts (site, kind, title, detail) VALUES (?, ?, ?, ?)", [$site, $kind, $title, $detail]);
            if (class_exists('Notifier')) { try { Notifier::push('seo', $title, $detail ?: null, 'index.php#seo'); } catch (Throwable $e) {} }
        };
        $w = Database::one("SELECT SUM(CASE WHEN day > DATE_SUB(CURDATE(), INTERVAL 10 DAY) THEN clicks END) cur, SUM(CASE WHEN day <= DATE_SUB(CURDATE(), INTERVAL 10 DAY) AND day > DATE_SUB(CURDATE(), INTERVAL 17 DAY) THEN clicks END) prev FROM eb_gsc_daily WHERE site = ?", [$site]);
        $cur = (int)($w['cur'] ?? 0); $prev = (int)($w['prev'] ?? 0);
        if ($prev >= 50 && $cur < $prev * 0.7) $add('clicks_drop', 'کلیک‌های گوگل ' . round((1 - $cur / $prev) * 100) . '٪ کم شده است', 'هفته‌ی اخیر ' . $cur . ' کلیک، هفته‌ی قبل ' . $prev);
        $broken = (int)(Database::one("SELECT COUNT(*) c FROM eb_gsc_urls WHERE site = ? AND http_status >= 400 AND crawled_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)", [$site])['c'] ?? 0);
        if ($broken) $add('broken', $broken . ' صفحه‌ی نقشه‌ی سایت خطا می‌دهد', 'در «سرچ کنسول › آدرس‌ها» فیلتر «خراب» را ببینید');
        $noindex = (int)(Database::one("SELECT COUNT(*) c FROM eb_gsc_urls WHERE site = ? AND verdict IN ('FAIL','NEUTRAL') AND inspected_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)", [$site])['c'] ?? 0);
        if ($noindex >= 5) $add('not_indexed', $noindex . ' صفحه در بررسی امروز ایندکس نبود');
        $sm = self::sitemaps();
        foreach ($sm['sitemaps'] ?? [] as $s) if ($s['errors'] > 0) { $add('sitemap_err', 'نقشه‌ی سایت خطا دارد', $s['path'] . ' : ' . $s['errors'] . ' خطا'); break; }
    }

    public static function alertList(): array
    {
        self::boot();
        $site = self::cfg()['site'];
        return Database::all("SELECT id, kind, title, detail, seen, created_at FROM eb_gsc_alerts WHERE site = ? ORDER BY id DESC LIMIT 30", [$site]);
    }

    public static function history(): array
    {
        self::boot();
        return Database::all("SELECT day, clicks, impressions, ctr, position FROM eb_gsc_daily WHERE site = ? ORDER BY day", [self::cfg()['site']]);
    }
}
