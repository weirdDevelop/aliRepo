<?php
/**
 * modules/License.php
 * لایسنس اعلان‌بان از طریق OAuth راست‌چین (rtl-theme.com).
 *
 * روند:
 *   ۱) مدیر بعد از نصب، نام کاربری راست‌چین و کد سفارش را وارد می‌کند.
 *   ۲) یک POST به https://www.rtl-theme.com/oauth/ با api، username، order_id،
 *      domain و pid زده می‌شود. پاسخ «1» یعنی خریدار واقعی است.
 *   ۳) نتیجه با امضای HMAC در eb_settings و یک نسخه‌ی پشتیبان در data/license.php
 *      ذخیره می‌شود و به دامنه‌ی فعلی گره می‌خورد.
 *
 * نکته‌ها:
 * - راست‌چین فقط «ثبت» را تأیید می‌کند؛ ارسال دوباره‌ی همان کد سفارش «-4» (قبلاً ثبت
 *   شده) برمی‌گرداند. برای همین فایل پشتیبان نگه داشته می‌شود تا نصب دوباره روی همان
 *   دامنه لایسنس را از دست ندهد، و برای انتقال/بازنصب «کد بازیابی» داریم که فروشنده
 *   با cli/license_recovery.php صادر می‌کند.
 * - روی localhost و دامنه‌های توسعه (.test، .local، .localhost) پنل بدون لایسنس کار
 *   می‌کند تا خریدار بتواند قبل از انتقال به هاست اصلی تست کند.
 * - کد سمت سرور همیشه خواندنی است؛ برای محافظت جدی‌تر، این فایل را هنگام انتشار
 *   رمزگذاری (ionCube/SourceGuardian) کنید.
 */

class License
{
    /** API اختصاصی فروشنده در راست‌چین */
    const RTL_API = 'rtl88962c3869b9495f8cd91c9b497f1633';

    /**
     * شناسه‌ی محصول اعلان‌بان در راست‌چین (pid).
     * بعد از تأیید محصول در راست‌چین، عدد شناسه‌ی صفحه‌ی محصول را اینجا بگذارید.
     * تا خالی است، مقدار پیش‌فرض نمونه‌ی راست‌چین («new Product») ارسال می‌شود.
     */
    const RTL_PRODUCT_ID = '';

    const ENDPOINT = 'https://www.rtl-theme.com/oauth/';
    const MARKET   = 'راست‌چین';
    const SALT     = 'elb.lic.v1|8d3f61c2a7e94b05';

    /** پیام‌های پاسخ راست‌چین */
    const ERRORS = [
        '-1' => 'کلید API فروشنده نامعتبر است. با پشتیبانی اعلان‌بان تماس بگیرید.',
        '-2' => 'نام کاربری راست‌چین اشتباه است.',
        '-3' => 'کد سفارش اشتباه است.',
        '-4' => 'این کد سفارش قبلاً روی یک دامنه فعال شده است. اگر روی همان دامنه دوباره نصب کرده‌اید، کد بازیابی را از پشتیبانی بگیرید.',
        '-5' => 'این کد سفارش متعلق به این نام کاربری نیست.',
        '-6' => 'اطلاعات واردشده در قالب درست نیست.',
        '-7' => 'این کد سفارش مربوط به محصول اعلان‌بان نیست.',
        '-8' => 'این کد سفارش متعلق به این نام کاربری نیست.',
    ];

    private static $cache = null;

    /* ------------------------------------------------------------------ */

    /** دامنه‌ی فعلی نصب: فقط host، بدون scheme، پورت، مسیر و www. */
    public static function currentDomain(): string
    {
        global $config;
        $src = '';
        if (!empty($_SERVER['HTTP_HOST'])) {
            $src = $_SERVER['HTTP_HOST'];
        } elseif (is_array($config ?? null) && function_exists('app_base_url')) {
            $src = app_base_url($config);
        }
        return self::normalize($src);
    }

    public static function normalize(string $d): string
    {
        $d = strtolower(trim($d));
        $d = preg_replace('#^[a-z]+://#', '', $d);
        $d = preg_replace('#[/?\#].*$#', '', $d);
        $d = preg_replace('#:\d+$#', '', $d);
        $d = preg_replace('#^www\.#', '', $d);
        return $d;
    }

    /** دامنه‌های توسعه که بدون لایسنس کار می‌کنند */
    public static function isDevHost(string $d): bool
    {
        if ($d === '' || $d === 'localhost' || $d === '::1' || $d === '[::1]') return true;
        if (preg_match('/^127\.\d+\.\d+\.\d+$/', $d)) return true;
        if (preg_match('/^(10|192\.168)\.\d+\.\d+(\.\d+)?$/', $d)) return true;
        return (bool)preg_match('/\.(test|local|localhost|lan)$/', $d);
    }

    /* ------------------------------------------------------------------ */

    /** وضعیت کامل برای پنل */
    public static function status(): array
    {
        if (self::$cache !== null) return self::$cache;
        $domain = self::currentDomain();
        $rec = self::load();
        $valid = $rec && self::verifySig($rec);
        $match = $valid && $rec['domain'] === $domain;
        $dev = self::isDevHost($domain);
        return self::$cache = [
            'active'       => $match || $dev,
            'licensed'     => $match,
            'dev'          => $dev && !$match,
            'mismatch'     => $valid && !$match && !$dev,
            'domain'       => $domain,
            'licensed_domain' => $valid ? $rec['domain'] : null,
            'username'     => $valid ? $rec['username'] : null,
            'order_id'     => $valid ? self::maskOrder($rec['order_id']) : null,
            'activated_at' => $valid ? (int)$rec['activated_at'] : null,
            'market'       => self::MARKET,
        ];
    }

    public static function isActive(): bool
    {
        return self::status()['active'];
    }

    /**
     * فعال‌سازی با راست‌چین.
     * @return array ['ok'=>bool, 'error'=>?string, 'code'=>?string]
     */
    public static function activate(string $username, string $orderId, string $recovery = ''): array
    {
        $username = trim($username);
        $orderId  = preg_replace('/\s+/', '', self::latinDigits($orderId));
        $domain   = self::currentDomain();

        if ($username === '' || $orderId === '') {
            return ['ok' => false, 'error' => 'نام کاربری و کد سفارش را وارد کنید.'];
        }
        if (!preg_match('/^[A-Za-z0-9_.\-@]{2,80}$/', $username)) {
            return ['ok' => false, 'error' => 'نام کاربری فقط شامل حروف انگلیسی، عدد و . _ - است.'];
        }
        if (!preg_match('/^[A-Za-z0-9\-]{3,40}$/', $orderId)) {
            return ['ok' => false, 'error' => 'کد سفارش فقط شامل عدد و حروف انگلیسی است.'];
        }
        if (self::isDevHost($domain)) {
            return ['ok' => false, 'error' => 'روی localhost و دامنه‌های آزمایشی نیازی به فعال‌سازی نیست. پنل را روی دامنه‌ی اصلی نصب و آنجا فعال کنید.'];
        }

        // بازنصب با کد بازیابی (بدون تماس با راست‌چین)
        $recovery = strtoupper(trim(self::latinDigits($recovery)));
        if ($recovery !== '') {
            if (!hash_equals(self::recoveryCode($domain, $orderId), $recovery)) {
                return ['ok' => false, 'error' => 'کد بازیابی برای این دامنه و کد سفارش معتبر نیست.'];
            }
            self::save($domain, $username, $orderId, 'recovery');
            return ['ok' => true];
        }

        $res = self::callRtl($username, $orderId, $domain);
        if (!$res['ok']) return $res;

        $code = trim((string)$res['body']);
        if ($code === '1') {
            self::save($domain, $username, $orderId, 'rtl');
            return ['ok' => true];
        }
        // اگر همین سفارش قبلاً روی همین دامنه فعال شده و فایل پشتیبان موجود است، بازگردانی
        if ($code === '-4') {
            $bak = self::loadFile();
            if ($bak && self::verifySig($bak) && $bak['domain'] === $domain && $bak['order_id'] === $orderId) {
                self::store($bak);
                return ['ok' => true, 'restored' => true];
            }
        }
        $msg = self::ERRORS[$code] ?? 'پاسخ نامشخص از راست‌چین (' . mb_substr(strip_tags($code), 0, 40) . '). کمی بعد دوباره تلاش کنید.';
        self::log('activate_fail', $code);
        return ['ok' => false, 'error' => $msg, 'code' => $code];
    }

    /** حذف لایسنس از این نصب (برای انتقال به دامنه‌ی دیگر) */
    public static function remove(): void
    {
        foreach (['license.data'] as $k) {
            try { Database::run("DELETE FROM eb_settings WHERE k = ?", [$k]); } catch (Throwable $e) {}
        }
        $f = self::filePath();
        if (is_file($f)) @unlink($f);
        self::$cache = null;
        self::log('removed', self::currentDomain());
    }

    /** کد بازیابی برای بازنصب/انتقال (فروشنده با CLI صادر می‌کند) */
    public static function recoveryCode(string $domain, string $orderId): string
    {
        $h = hash_hmac('sha256', self::normalize($domain) . '|' . trim($orderId), self::SALT . self::RTL_API);
        $b32 = strtoupper(substr(base_convert(substr($h, 0, 12), 16, 36), 0, 8) . substr(base_convert(substr($h, 12, 12), 16, 36), 0, 8));
        $b32 = str_pad($b32, 16, 'X');
        return implode('-', str_split($b32, 4));
    }

    /* ------------------------------------------------------------------ */

    private static function callRtl(string $username, string $orderId, string $domain): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'افزونه‌ی cURL روی سرور فعال نیست؛ برای فعال‌سازی لازم است.'];
        }
        $post = http_build_query([
            'api'      => self::RTL_API,
            'username' => $username,
            'order_id' => $orderId,
            'domain'   => $domain,
            'pid'      => self::RTL_PRODUCT_ID !== '' ? self::RTL_PRODUCT_ID : 'new Product',
        ]);
        $attempt = function (bool $verify) use ($post) {
            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $post,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => $verify,
                CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                CURLOPT_USERAGENT      => 'Elanban-License/1.0',
            ]);
            $body = curl_exec($ch);
            $err  = curl_errno($ch);
            $msg  = curl_error($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$body, $err, $msg, $http];
        };
        [$body, $err, $msg, $http] = $attempt(true);
        // هاست‌های قدیمی گاهی گواهی ریشه‌ی به‌روز ندارند؛ مثل نمونه‌کد راست‌چین بدون بررسی دوباره تلاش می‌کنیم
        if ($err && in_array($err, [35, 51, 58, 60, 77], true)) {
            [$body, $err, $msg, $http] = $attempt(false);
        }
        if ($err || $body === false) {
            self::log('network', $err . ' ' . $msg);
            return ['ok' => false, 'error' => 'اتصال به سرور راست‌چین برقرار نشد (' . $msg . '). اتصال اینترنت سرور یا فایروال را بررسی کنید.'];
        }
        if ($http >= 500) {
            return ['ok' => false, 'error' => 'سرور راست‌چین موقتاً در دسترس نیست (HTTP ' . $http . '). چند دقیقه بعد دوباره تلاش کنید.'];
        }
        return ['ok' => true, 'body' => $body];
    }

    private static function save(string $domain, string $username, string $orderId, string $via): void
    {
        $rec = [
            'domain'       => $domain,
            'username'     => $username,
            'order_id'     => $orderId,
            'activated_at' => time(),
            'via'          => $via,
        ];
        $rec['sig'] = self::sig($rec);
        self::store($rec);
        self::writeFile($rec);
        self::log('activated', $domain . ' via ' . $via);
    }

    private static function store(array $rec): void
    {
        Database::run(
            "INSERT INTO eb_settings (k, v) VALUES ('license.data', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)",
            [json_encode($rec, JSON_UNESCAPED_UNICODE)]
        );
        self::$cache = null;
    }

    private static function load(): ?array
    {
        try {
            $r = Database::one("SELECT v FROM eb_settings WHERE k = 'license.data'");
            if ($r && $r['v']) {
                $d = json_decode($r['v'], true);
                if (is_array($d)) return $d;
            }
        } catch (Throwable $e) {}
        // دیتابیس تازه ولی فایل پشتیبان سالم و هم‌دامنه: خودکار بازگردانی
        $f = self::loadFile();
        if ($f && self::verifySig($f) && $f['domain'] === self::currentDomain()) {
            try { self::store($f); } catch (Throwable $e) {}
            return $f;
        }
        return null;
    }

    private static function sig(array $r): string
    {
        return hash_hmac('sha256', implode('|', [$r['domain'], $r['username'], $r['order_id'], $r['activated_at']]), self::SALT . self::RTL_API);
    }

    private static function verifySig(array $r): bool
    {
        foreach (['domain', 'username', 'order_id', 'activated_at', 'sig'] as $k) if (!isset($r[$k])) return false;
        return hash_equals(self::sig($r), (string)$r['sig']);
    }

    private static function filePath(): string
    {
        return dirname(__DIR__) . '/data/license.php';
    }

    private static function writeFile(array $rec): void
    {
        $dir = dirname(self::filePath());
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        // پیشوند PHP تا روی nginx هم محتوا مستقیم خوانده نشود
        @file_put_contents(self::filePath(), "<?php exit; ?>\n" . json_encode($rec, JSON_UNESCAPED_UNICODE));
    }

    private static function loadFile(): ?array
    {
        $f = self::filePath();
        if (!is_file($f)) return null;
        $raw = (string)@file_get_contents($f);
        $raw = preg_replace('/^<\?php exit; \?>\s*/', '', $raw);
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }

    private static function maskOrder(string $o): string
    {
        $n = strlen($o);
        return $n <= 4 ? $o : str_repeat('•', max(0, $n - 4)) . substr($o, -4);
    }

    private static function latinDigits(string $s): string
    {
        return strtr($s, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    }

    private static function log(string $ev, string $detail): void
    {
        try {
            Database::run(
                "INSERT INTO eb_settings (k, v) VALUES ('license.last_event', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)",
                [json_encode(['ev' => $ev, 'detail' => mb_substr($detail, 0, 200), 'at' => time()], JSON_UNESCAPED_UNICODE)]
            );
        } catch (Throwable $e) {}
    }
}
