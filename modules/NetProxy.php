<?php
/**
 * modules/NetProxy.php
 * پروکسی ضدفیلترینگ برای سرویس‌های خارجی (تلگرام، هوش مصنوعی، درخواست‌های HTTP سناریوها).
 *
 * نسخه‌ی ۲:
 *   - چند پروکسی (پروفایل) با همه‌ی نوع‌هایی که curl پشتیبانی می‌کند:
 *     HTTP، HTTPS، SOCKS4، SOCKS4a، SOCKS5 و SOCKS5h (DNS از راه پروکسی)
 *   - مسیر جدا برای هر سرویس، با ترتیب جایگزینی: اگر پروکسی اول وصل نشد، بعدی امتحان می‌شود
 *     و پروکسی خراب ۵ دقیقه کنار گذاشته می‌شود
 *   - وارد کردن با لینک: socks5://user:pass@host:port، tg://socks، t.me/socks، host:port:user:pass
 *   - آدرس جایگزین API تلگرام (سرور Bot API شخصی یا رله‌ی Cloudflare Worker)
 *
 * درباره‌ی MTProto: پروکسی MTProto فقط پروتکل MTProto اپلیکیشن‌های تلگرام را عبور می‌دهد.
 * ربات‌ها با Bot API (HTTPS به api.telegram.org) کار می‌کنند و این ترافیک از MTProto رد نمی‌شود.
 * پس لینک MTProto شناسایی و توضیح داده می‌شود و راه‌حل جایگزین (رله‌ی API) پیشنهاد می‌شود.
 *
 * تنظیمات در eb_settings با پیشوند `netproxy.` ذخیره می‌شود. نسخه‌ی قبلی (یک پروکسی) خودکار
 * به پروفایل اول تبدیل می‌شود و APIهای قبلی (proxy_settings / proxy_save) همچنان کار می‌کنند.
 */
class NetProxy
{
    /** نسخه‌ی قدیمی تک‌پروکسی — برای سازگاری */
    const DEFAULTS = [
        'enabled'  => '0',
        'type'     => 'http',
        'host'     => '',
        'port'     => '',
        'username' => '',
        'password' => '',
        'targets'  => 'telegram',
    ];

    const TYPES = [
        'http'    => 'HTTP',
        'https'   => 'HTTPS (اتصال رمزگذاری‌شده به خود پروکسی)',
        'socks4'  => 'SOCKS4',
        'socks4a' => 'SOCKS4a (DNS از پروکسی)',
        'socks5'  => 'SOCKS5',
        'socks5h' => 'SOCKS5h (DNS از پروکسی، پیشنهادی)',
    ];

    const TARGET_LABELS = [
        'telegram'      => 'تلگرام',
        'ai_openrouter' => 'هوش مصنوعی: OpenRouter',
        'ai_openai'     => 'هوش مصنوعی: OpenAI',
        'ai_custom'     => 'هوش مصنوعی: سرویس سفارشی',
        'flows'         => 'درخواست‌های HTTP سناریوها',
        'gsc'           => 'سرچ کنسول گوگل',
    ];

    const TEST_URLS = [
        'telegram'      => 'https://api.telegram.org',
        'ai_openrouter' => 'https://openrouter.ai/api/v1',
        'ai_openai'     => 'https://api.openai.com/v1',
        'ai_custom'     => 'https://api.openai.com/v1',
        'flows'         => 'https://www.google.com/generate_204',
        'gsc'           => 'https://www.googleapis.com/discovery/v1/apis',
    ];

    const HEALTH_TTL = 300; // پروکسی خراب ۵ دقیقه کنار گذاشته می‌شود

    private static $cache = null;

    /* ---------------- ذخیره‌سازی ---------------- */

    private static function raw(string $k, string $def = ''): string
    {
        $a = self::rawAll();
        return array_key_exists($k, $a) ? (string)$a[$k] : $def;
    }

    private static function rawAll(): array
    {
        if (self::$cache !== null) return self::$cache;
        $out = [];
        try {
            foreach (Database::all("SELECT k, v FROM eb_settings WHERE k LIKE 'netproxy.%'") as $r) $out[substr($r['k'], 9)] = $r['v'];
        } catch (Throwable $e) {}
        return self::$cache = $out;
    }

    private static function put(string $k, string $v): void
    {
        Database::run("INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", ['netproxy.' . $k, $v]);
        self::$cache = null;
    }

    /** فهرست پروکسی‌ها (با رمز) */
    public static function proxies(): array
    {
        $list = json_decode(self::raw('list', ''), true);
        if (is_array($list)) return array_values(array_filter($list, 'is_array'));
        // مهاجرت از نسخه‌ی تک‌پروکسی
        $host = trim(self::raw('host')); $port = trim(self::raw('port'));
        if ($host === '' || $port === '') return [];
        return [[
            'id' => 'p1', 'name' => 'پروکسی اصلی', 'type' => self::raw('type', 'http') ?: 'http',
            'host' => $host, 'port' => $port, 'username' => self::raw('username'), 'password' => self::raw('password'),
            'enabled' => self::raw('enabled', '0') === '1' ? 1 : 0,
        ]];
    }

    /** مسیر هر سرویس: آرایه‌ای از شناسه‌ی پروکسی‌ها به ترتیب اولویت */
    public static function routes(): array
    {
        $r = json_decode(self::raw('routes', ''), true);
        if (is_array($r)) return $r;
        $routes = [];
        $first = self::proxies()[0]['id'] ?? null;
        if ($first) foreach (self::legacyTargets() as $t) $routes[$t] = [$first];
        return $routes;
    }

    private static function legacyTargets(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', self::raw('targets', 'telegram')))));
    }

    /* ---------------- استفاده در درایورها ---------------- */

    /** پروکسی‌های فعال و سالم یک سرویس به ترتیب اولویت */
    public static function chain(string $target): array
    {
        $byId = [];
        foreach (self::proxies() as $p) if (!empty($p['enabled']) && trim($p['host'] ?? '') !== '' && trim($p['port'] ?? '') !== '') $byId[$p['id']] = $p;
        $ids = self::routes()[$target] ?? [];
        $health = json_decode(self::raw('health', '{}'), true) ?: [];
        $ok = []; $bad = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) continue;
            if (($health[$id] ?? 0) > time()) $bad[] = $byId[$id]; else $ok[] = $byId[$id];
        }
        return array_merge($ok, $bad);   // اگر همه خراب‌اند، باز هم به ترتیب امتحان می‌شوند
    }

    public static function shouldApply(string $target): bool
    {
        return (bool)self::chain($target);
    }

    /** گزینه‌های curl برای بهترین پروکسی این سرویس ([] = مستقیم) */
    public static function curlOptionsFor(string $target, int $index = 0): array
    {
        $chain = self::chain($target);
        return isset($chain[$index]) ? self::curlOptions($chain[$index]) : [];
    }

    public static function curlOptions(array $p): array
    {
        $type = strtolower($p['type'] ?? 'http');
        $map = [
            'http'    => CURLPROXY_HTTP,
            'https'   => defined('CURLPROXY_HTTPS') ? CURLPROXY_HTTPS : CURLPROXY_HTTP,
            'socks4'  => CURLPROXY_SOCKS4,
            'socks4a' => defined('CURLPROXY_SOCKS4A') ? CURLPROXY_SOCKS4A : CURLPROXY_SOCKS4,
            'socks5'  => CURLPROXY_SOCKS5,
            'socks5h' => defined('CURLPROXY_SOCKS5_HOSTNAME') ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_SOCKS5,
        ];
        $opts = [
            CURLOPT_PROXY => trim($p['host']) . ':' . (int)$p['port'],
            CURLOPT_PROXYTYPE => $map[$type] ?? CURLPROXY_HTTP,
        ];
        if (trim($p['username'] ?? '') !== '') $opts[CURLOPT_PROXYUSERPWD] = $p['username'] . ':' . ($p['password'] ?? '');
        if ($type === 'https' && defined('CURLOPT_PROXY_SSL_VERIFYPEER')) $opts[CURLOPT_PROXY_SSL_VERIFYPEER] = false;
        return $opts;
    }

    /**
     * اجرای یک درخواست curl با جایگزینی خودکار پروکسی.
     * $build($proxyOpts) باید handle آماده‌ی curl برگرداند. فقط خطاهای «وصل نشدن به پروکسی» دوباره
     * امتحان می‌شوند (درخواست هنوز به مقصد نرسیده)، تا پیام تکراری فرستاده نشود.
     * خروجی: [body|false, http_code, curl_error]
     */
    public static function exec(string $target, callable $build): array
    {
        $chain = self::chain($target);
        $tries = max(1, count($chain));
        $last = [false, 0, ''];
        for ($i = 0; $i < $tries; $i++) {
            $ch = $build(isset($chain[$i]) ? self::curlOptions($chain[$i]) : []);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch); $err = curl_error($ch);
            curl_close($ch);
            $last = [$body, $code, $err];
            if (!isset($chain[$i])) break;
            if ($body !== false || !in_array($errno, [5, 7, 97], true)) { self::markHealthy($chain[$i]['id']); break; }
            self::markFailed($chain[$i]['id']);
        }
        return $last;
    }

    public static function markFailed(string $id): void
    {
        try {
            $h = json_decode(self::raw('health', '{}'), true) ?: [];
            $h[$id] = time() + self::HEALTH_TTL;
            self::put('health', json_encode($h));
        } catch (Throwable $e) {}
    }

    public static function markHealthy(string $id): void
    {
        $h = json_decode(self::raw('health', '{}'), true) ?: [];
        if (!isset($h[$id])) return;
        unset($h[$id]);
        try { self::put('health', json_encode($h)); } catch (Throwable $e) {}
    }

    /** آدرس API تلگرام (پیش‌فرض یا رله/سرور شخصی) */
    public static function telegramApiBase(): string
    {
        $u = rtrim(trim(self::raw('tg_api_base', '')), '/');
        return preg_match('~^https?://[^\s]+$~i', $u) ? $u : 'https://api.telegram.org';
    }

    /* ---------------- پنل ---------------- */

    /** نسخه‌ی امن برای UI (بدون رمزها) + فیلدهای نسخه‌ی قدیمی برای صفحه‌ی کانال‌ها */
    public static function publicSettings(): array
    {
        $list = array_map(function ($p) {
            $p['password_set'] = trim($p['password'] ?? '') !== '' ? 1 : 0;
            unset($p['password']);
            return $p;
        }, self::proxies());
        $health = json_decode(self::raw('health', '{}'), true) ?: [];
        foreach ($list as &$p) $p['down_until'] = max(0, (int)($health[$p['id']] ?? 0) - time());
        unset($p);
        $first = $list[0] ?? null;
        $routes = self::routes();
        $legacyTargets = [];
        foreach ($routes as $t => $ids) if ($first && in_array($first['id'], (array)$ids, true)) $legacyTargets[] = $t;
        return [
            'proxies' => $list,
            'routes' => $routes,
            'tg_api_base' => self::raw('tg_api_base', ''),
            // سازگاری با صفحه‌ی قدیمی کانال‌ها
            'enabled' => $first && !empty($first['enabled']) ? '1' : '0',
            'type' => $first['type'] ?? 'http', 'host' => $first['host'] ?? '', 'port' => $first['port'] ?? '',
            'username' => $first['username'] ?? '', 'password_set' => $first['password_set'] ?? 0,
            'targets' => implode(',', $legacyTargets), 'configured' => (bool)$first,
        ];
    }

    public static function isConfigured(): bool
    {
        return (bool)self::proxies();
    }

    /** ذخیره از فرم جدید: proxies[] + routes{} + tg_api_base */
    public static function saveFromInput(array $in): array
    {
        $old = [];
        foreach (self::proxies() as $p) $old[$p['id']] = $p;
        $list = []; $seen = [];
        foreach ((array)($in['proxies'] ?? []) as $i => $p) {
            if (!is_array($p)) continue;
            $id = preg_match('/^p[a-z0-9]{1,12}$/', (string)($p['id'] ?? '')) ? $p['id'] : 'p' . substr(bin2hex(random_bytes(4)), 0, 7);
            if (isset($seen[$id])) $id = 'p' . substr(bin2hex(random_bytes(4)), 0, 7);
            $seen[$id] = 1;
            $type = array_key_exists($p['type'] ?? '', self::TYPES) ? $p['type'] : 'http';
            $host = trim((string)($p['host'] ?? ''));
            $port = (int)($p['port'] ?? 0);
            if ($host === '' && !$port) continue;
            if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) return ['ok' => false, 'error' => 'آدرس پروکسی ' . ($i + 1) . ' نامعتبر است'];
            if ($port < 1 || $port > 65535) return ['ok' => false, 'error' => 'پورت پروکسی ' . ($i + 1) . ' نامعتبر است'];
            $pass = (string)($p['password'] ?? '');
            if ($pass === '' && empty($p['password_clear']) && isset($old[$id])) $pass = (string)($old[$id]['password'] ?? '');
            $list[] = ['id' => $id, 'name' => mb_substr(trim((string)($p['name'] ?? '')) ?: ('پروکسی ' . ($i + 1)), 0, 60), 'type' => $type,
                'host' => $host, 'port' => (string)$port, 'username' => trim((string)($p['username'] ?? '')), 'password' => $pass, 'enabled' => !empty($p['enabled']) ? 1 : 0];
        }
        $ids = array_column($list, 'id');
        $routes = [];
        foreach ((array)($in['routes'] ?? []) as $t => $r) {
            if (!array_key_exists($t, self::TARGET_LABELS)) continue;
            $routes[$t] = array_values(array_intersect(array_map('strval', (array)$r), $ids));
        }
        $base = rtrim(trim((string)($in['tg_api_base'] ?? '')), '/');
        if ($base !== '' && !preg_match('~^https://[^\s/]+(/[^\s]*)?$~i', $base)) return ['ok' => false, 'error' => 'آدرس API تلگرام باید با https:// شروع شود'];
        self::put('list', json_encode($list, JSON_UNESCAPED_UNICODE));
        self::put('routes', json_encode($routes, JSON_UNESCAPED_UNICODE));
        self::put('tg_api_base', $base === 'https://api.telegram.org' ? '' : $base);
        self::put('health', '{}');
        return ['ok' => true, 'settings' => self::publicSettings()];
    }

    /** ذخیره از فرم قدیمی (یک پروکسی): پروفایل اول و مسیرهایش به‌روز می‌شوند */
    public static function save(array $values): void
    {
        $list = self::proxies();
        $p = $list[0] ?? ['id' => 'p1', 'name' => 'پروکسی اصلی', 'password' => ''];
        foreach (['type', 'host', 'port', 'username'] as $k) if (array_key_exists($k, $values)) $p[$k] = (string)$values[$k];
        if (trim((string)($values['password'] ?? '')) !== '') $p['password'] = (string)$values['password'];
        if (array_key_exists('enabled', $values)) $p['enabled'] = $values['enabled'] === '1' ? 1 : 0;
        if (!array_key_exists($p['type'] ?? '', self::TYPES)) $p['type'] = 'http';
        $list[0] = $p;
        $routes = self::routes();
        if (array_key_exists('targets', $values)) {
            $want = array_values(array_filter(array_map('trim', explode(',', (string)$values['targets']))));
            foreach (self::TARGET_LABELS as $t => $_) {
                $ids = array_values(array_diff((array)($routes[$t] ?? []), [$p['id']]));
                if (in_array($t, $want, true)) array_unshift($ids, $p['id']);
                $routes[$t] = $ids;
            }
        }
        self::put('list', json_encode($list, JSON_UNESCAPED_UNICODE));
        self::put('routes', json_encode($routes, JSON_UNESCAPED_UNICODE));
    }

    public static function clearPassword(): void
    {
        $list = self::proxies();
        if (!$list) return;
        $list[0]['password'] = '';
        self::put('list', json_encode($list, JSON_UNESCAPED_UNICODE));
    }

    /** آزمایش: یک پروکسی مشخص ($proxyId) یا مسیر کامل یک سرویس */
    public static function test(string $target = 'telegram', string $proxyId = ''): array
    {
        $url = $target === 'telegram' ? self::telegramApiBase() : (self::TEST_URLS[$target] ?? 'https://api.telegram.org');
        $opts = [];
        if ($proxyId !== '') {
            $p = null;
            foreach (self::proxies() as $x) if ($x['id'] === $proxyId) $p = $x;
            if (!$p) return ['ok' => false, 'error' => 'این پروکسی هنوز ذخیره نشده است'];
            $opts = self::curlOptions($p);
        } else {
            $opts = self::curlOptionsFor($target);
        }
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_NOBODY => true]);
        $ok = curl_exec($ch) !== false;
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if (!$ok || $code === 0) {
            if ($proxyId !== '') self::markFailed($proxyId);
            return ['ok' => false, 'error' => $err !== '' ? $err : 'اتصال برقرار نشد', 'target' => $target, 'direct' => !$opts];
        }
        if ($proxyId !== '') self::markHealthy($proxyId);
        return ['ok' => true, 'code' => $code, 'ms' => $ms, 'target' => $target, 'direct' => !$opts];
    }

    /**
     * خواندن لینک پروکسی. MTProto شناسایی و با توضیح رد می‌شود.
     * خروجی موفق: ['ok'=>true, 'proxy'=>[type, host, port, username, password]]
     */
    public static function parseLink(string $link): array
    {
        $link = trim($link);
        if ($link === '') return ['ok' => false, 'error' => 'لینک خالی است'];
        $qs = [];
        if (preg_match('~^(?:tg://|https?://(?:www\.)?(?:t|telegram)\.me/)(socks|proxy)\?(.*)$~i', $link, $m)) {
            parse_str($m[2], $qs);
            $host = (string)($qs['server'] ?? ''); $port = (string)($qs['port'] ?? '');
            if (strtolower($m[1]) === 'proxy' || !empty($qs['secret'])) {
                return ['ok' => false, 'mtproto' => true, 'server' => $host, 'port' => $port,
                    'error' => 'این لینک پروکسی MTProto است. MTProto فقط ترافیک اپلیکیشن تلگرام را عبور می‌دهد و ربات‌ها (Bot API روی HTTPS) نمی‌توانند از آن استفاده کنند. به‌جایش یک پروکسی SOCKS5/HTTP یا «آدرس جایگزین API تلگرام» (رله) تنظیم کنید.'];
            }
            return ['ok' => true, 'proxy' => ['type' => 'socks5h', 'host' => $host, 'port' => $port, 'username' => (string)($qs['user'] ?? ''), 'password' => (string)($qs['pass'] ?? '')]];
        }
        if (preg_match('~^(https?|socks4a?|socks5h?)://~i', $link, $m)) {
            $u = parse_url($link);
            if (empty($u['host'])) return ['ok' => false, 'error' => 'لینک نامعتبر است'];
            $type = strtolower($m[1]);
            return ['ok' => true, 'proxy' => ['type' => $type, 'host' => $u['host'], 'port' => (string)($u['port'] ?? ($type === 'https' ? 443 : ($type === 'http' ? 80 : 1080))),
                'username' => isset($u['user']) ? rawurldecode($u['user']) : '', 'password' => isset($u['pass']) ? rawurldecode($u['pass']) : '']];
        }
        $parts = explode(':', $link);
        if (count($parts) >= 2 && preg_match('/^\d{1,5}$/', $parts[1])) {
            return ['ok' => true, 'proxy' => ['type' => 'socks5h', 'host' => $parts[0], 'port' => $parts[1], 'username' => $parts[2] ?? '', 'password' => $parts[3] ?? '']];
        }
        return ['ok' => false, 'error' => 'قالب لینک شناخته نشد. نمونه‌ها: socks5://user:pass@1.2.3.4:1080 ، http://1.2.3.4:8080 ، tg://socks?server=…&port=… ، 1.2.3.4:1080:user:pass'];
    }

    /** اسکریپت رله‌ی Cloudflare Worker برای API تلگرام */
    public static function workerScript(): string
    {
        return <<<'JS'
// رله‌ی API تلگرام برای اعلان‌بان — در Cloudflare Workers بسازید و آدرسش را در اعلان‌بان وارد کنید
export default {
  async fetch(request) {
    const url = new URL(request.url);
    if (!/^\/(file\/)?bot\d+:[\w-]+\//.test(url.pathname)) return new Response('Not found', {status: 404});
    url.protocol = 'https:'; url.hostname = 'api.telegram.org'; url.port = '';
    return fetch(new Request(url.toString(), request));
  }
};
JS;
    }
}
