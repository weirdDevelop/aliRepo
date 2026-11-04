<?php
/**
 * install.php — ویزارد نصب اعلان‌بان
 * =====================================
 * نصب کامل در چند مرحله‌ی ساده: بررسی محیط ← دیتابیس ← تنظیمات ← حساب مدیر ← نصب.
 * این ویزارد همه‌ی مراحل راهنمای نصب را خودکار می‌کند:
 *   ۱) ساخت config.php (با کلید برنامه‌ی تصادفی — بدون کپی دستی config.sample.php)
 *   ۲) اتصال به دیتابیس (با امکان ساخت خودکار دیتابیس، اگر کاربر اجازه داشته باشد)
 *   ۳) ساخت کامل جداول (Schema + SchemaV4 — همان منبع حقیقتِ «به‌روزرسانی دیتابیس»)
 *   ۴) ساخت حساب مدیر
 *   ۵) تنظیمات پایه (منطقه‌ی زمانی، کلید cron، نام پنل)
 * پس از نصب موفق، از همین صفحه می‌توانید فایل نصب را حذف کنید.
 *
 * اجرای چندباره بی‌خطر است (idempotent): config موجود حفظ می‌شود (فقط app_key هرگز
 * بازتولید نمی‌شود تا رمزنگاری تنظیمات کانال‌ها نشکند)، جداول IF NOT EXISTS هستند و
 * حساب مدیر فقط در نبودِ مدیر ساخته می‌شود.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
@set_time_limit(300);
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

define('EB_WIZARD_VERSION', '1.0.0');
define('EB_MIN_PHP', '7.4');
define('EB_PASS_KEEP', "\x00keep\x00");   // رمز «دست نزن» — از config موجود برداشته می‌شود

$ROOT = __DIR__;
$CFG_PATH = $ROOT . '/config.php';

/* ---------- نشست و CSRF ---------- */
if (session_status() === PHP_SESSION_NONE) {
    @session_name('eb_install');
    @session_start();
}
if (empty($_SESSION['eb_install_csrf'])) {
    $_SESSION['eb_install_csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['eb_install_csrf'];

/* ---------- کمک‌تابع‌ها ---------- */

function jout(array $data): void {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function jok(array $data = []): void { jout(['ok' => true] + $data); }
function jfail(string $msg, array $data = []): void { jout(['ok' => false, 'error' => $msg] + $data); }
function need_csrf(): void {
    global $CSRF;
    if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) {
        jfail('نشست نصب منقضی شده است. صفحه را یک‌بار رفرش کنید و دوباره تلاش کنید.');
    }
}
function pget(string $k, string $default = ''): string {
    return is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : $default;
}

/** پیکربندی config.php موجود (در صورت سالم بودن) */
function existing_config(): ?array {
    global $CFG_PATH;
    if (!is_file($CFG_PATH)) return null;
    $cfg = @include $CFG_PATH;
    return is_array($cfg) ? $cfg : null;
}

/** آیا حساب مدیر وجود دارد؟ (نیازمند اتصال دیتابیس) */
function admin_exists(): ?bool {
    try {
        $r = Database::one("SELECT id FROM eb_users WHERE role='admin' LIMIT 1");
        return $r !== null;
    } catch (Throwable $e) { return null; }
}

/** تشخیص آدرس پایه از سرور (همان منطق core/Url.php) */
function detect_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $dir = '';
    $docRoot = str_replace('\\', '/', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));
    $appDir = str_replace('\\', '/', __DIR__);
    if ($docRoot !== '' && strpos($appDir, $docRoot) === 0) {
        $dir = rtrim(substr($appDir, strlen($docRoot)), '/');
    } else {
        $script = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
        $dir = rtrim(preg_replace('~/(webhook)$~', '', $script), '/');
    }
    if ($dir === '/' || $dir === '.') $dir = '';
    return $scheme . '://' . $host . $dir;
}

/** پیام فارسی برای خطاهای رایج دیتابیس */
function db_error_persian(Throwable $e, bool $during_create = false): string {
    $m = $e->getMessage();
    $code = $e instanceof PDOException ? (int)$e->getCode() : 0;
    $driverCode = 0;
    if ($e instanceof PDOException && property_exists($e, 'errorInfo') && is_array($e->errorInfo)) {
        $driverCode = (int)($e->errorInfo[1] ?? 0);
    }
    $c = $driverCode ?: $code;
    if ($c === 1045 || $c === 1698 || strpos($m, 'Access denied for user') !== false) {
        return 'دسترسی رد شد: نام کاربری یا رمز دیتابیس اشتباه است.';
    }
    if ($c === 2002 || $c === 2003 || strpos($m, 'Connection refused') !== false || strpos($m, 'getaddrinfo') !== false) {
        return 'به سرور دیتابیس وصل نشد. هاست و پورت را بررسی کنید.';
    }
    if ($c === 1049 || strpos($m, 'Unknown database') !== false) {
        return $during_create
            ? 'ساخت دیتابیس ممکن نشد؛ کاربر دیتابیس اجازه‌ی CREATE ندارد. دیتابیس را از پنل هاست بسازید.'
            : 'این دیتابیس وجود ندارد. گزینه‌ی «ساخت خودکار دیتابیس» را فعال کنید یا از پنل هاست بسازید.';
    }
    if ($c === 1044) return 'کاربر دیتابیس اجازه‌ی دسترسی به این دیتابیس را ندارد.';
    if ($c === 1203) return 'تعداد اتصال‌های همزمان دیتابیس پر است؛ کمی بعد تلاش کنید.';
    return 'خطای دیتابیس: ' . $m;
}

/** ورودی دیتابیس مشترک بین testdb و مراحل نصب */
function read_db_input(): array {
    global $CFG_PATH;
    $host = pget('db_host', 'localhost'); if ($host === '') $host = 'localhost';
    $port = preg_replace('/[^0-9]/', '', pget('db_port', ''));
    $name = trim(pget('db_name'));
    $user = trim(pget('db_user'));
    $pass = (string)($_POST['db_pass'] ?? '');

    // اگر رمز از قبل در config سالم موجود است و کاربر دست نزده، همان استفاده می‌شود
    if ($pass === EB_PASS_KEEP || $pass === '<<keep>>') {
        $cfg = existing_config();
        $pass = is_array($cfg) ? (string)($cfg['db']['pass'] ?? '') : '';
        if ($user === '' && $cfg) $user = (string)($cfg['db']['user'] ?? '');
        if (pget('db_host') === '' && $cfg) $host = (string)($cfg['db']['host'] ?? $host);
        if (pget('db_name') === '' && $cfg) $name = (string)($cfg['db']['name'] ?? '');
    }
    return ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'pass' => $pass];
}

function dsn_of(array $db, bool $with_name): string {
    $dsn = 'mysql:host=' . $db['host'];
    if ($db['port'] !== '') $dsn .= ';port=' . $db['port'];
    if ($with_name && $db['name'] !== '') $dsn .= ';dbname=' . $db['name'];
    $dsn .= ';charset=utf8mb4';
    return $dsn;
}

function pdo_connect(array $db, bool $with_name, int $timeout = 8): PDO {
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => $timeout,
    ];
    return new PDO(dsn_of($db, $with_name), $db['user'], $db['pass'], $opts);
}

/* =====================================================================
 *  مسیرهای JSON (AJAX)
 * ===================================================================== */

$isPost = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
$action = (string)($_POST['action'] ?? '');

if ($isPost && $action !== '') {

    /* ---------- بارگذاری هسته (برای همه‌ی اکشن‌ها لازم است) ---------- */
    require_once $ROOT . '/core/Database.php';

    if ($action === 'check') {
        need_csrf();
        $rows = [];

        // PHP
        $ver = PHP_VERSION;
        $okMin = version_compare($ver, EB_MIN_PHP, '>=');
        $okRec = version_compare($ver, '8.1', '>=');
        $rows[] = ['id' => 'php', 'label' => 'نسخه‌ی PHP', 'value' => $ver,
            'ok' => $okMin ? true : false, 'required' => true,
            'hint' => !$okMin ? 'PHP ' . EB_MIN_PHP . ' یا بالاتر لازم است (۸.۱ تا ۸.۳ پیشنهاد می‌شود).' :
                (!$okRec ? 'کار می‌کند؛ ولی ۸.۱ تا ۸.۳ سریع‌تر و امن‌تر است.' : '')];

        // اکستنشن‌های الزامی
        $reqExt = [
            'pdo_mysql' => 'اتصال به دیتابیس MySQL/MariaDB',
            'curl'      => 'ارتباط با درگاه‌های تلگرام، بله، پیامک و…',
            'mbstring'  => 'پردازش درست متن‌های فارسی',
            'json'      => 'تبادل داده با پلتفرم‌ها',
        ];
        foreach ($reqExt as $ext => $why) {
            $on = extension_loaded($ext);
            $rows[] = ['id' => 'ext_' . $ext, 'label' => 'اکستنشن ' . $ext, 'value' => $on ? 'فعال' : 'غیرفعال',
                'ok' => $on, 'required' => true,
                'hint' => !$on ? 'برای ' . $why . ' لازم است. از پشتیبانی هاست فعالش کنید.' : ''];
        }

        // اکستنشن‌های پیشنهادی
        $optExt = [
            'openssl' => 'رمزنگاری توکن کانال‌ها (تلگرام/اینستاگرام)',
            'gd'      => 'کار با تصاویر (کارت‌های گرافیکی)',
            'zip'     => 'گرفتن خروجی/پشتیبان فشرده',
            'soap'    => 'اتصال به برخی سرویس‌های پیامک',
            'intl'    => 'بهبود نمایش تاریخ و اعداد',
        ];
        foreach ($optExt as $ext => $why) {
            $on = extension_loaded($ext);
            $rows[] = ['id' => 'oext_' . $ext, 'label' => 'اکستنشن ' . $ext, 'value' => $on ? 'فعال' : 'غیرفعال',
                'ok' => $on ? true : null, 'required' => false,
                'hint' => !$on ? 'پیشنهاد می‌شود: ' . $why . ' (الان مانع نصب نیست).' : ''];
        }

        // دسترسی نوشتن
        $mk = function (string $d): bool {
            if (!is_dir($d)) @mkdir($d, 0775, true);
            return is_dir($d) && is_writable($d);
        };
        $rows[] = ['id' => 'w_root', 'label' => 'قابل‌نوشتن بودن پوشه‌ی نصب (برای ساخت config.php)',
            'value' => is_writable($ROOT) ? 'بله' : 'خیر',
            'ok' => is_writable($ROOT), 'required' => true,
            'hint' => !is_writable($ROOT) ? 'مجوز پوشه را روی ۷۵۵ بگذارید یا مالکیت را به کاربر PHP بدهید.' : ''];
        foreach (['uploads' => 'فایل‌های رسانه‌ای', 'logs' => 'گزارش‌ها و کش', 'data' => 'داده‌های داخلی'] as $d => $why) {
            $path = $ROOT . '/' . $d;
            $okw = $mk($path);
            $rows[] = ['id' => 'w_' . $d, 'label' => 'قابل‌نوشتن بودن پوشه‌ی ' . $d . ' (' . $why . ')',
                'value' => $okw ? 'بله' : 'خیر', 'ok' => $okw, 'required' => $d !== 'data',
                'hint' => !$okw ? 'پوشه باید برای PHP قابل‌نوشتن باشد (مثلاً chmod 755).' : ''];
        }

        // توابع حساس در disable_functions
        $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        $blocked = array_intersect(['curl_exec', 'curl_init', 'file_put_contents', 'password_hash', 'random_bytes'], array_filter($dis));
        $rows[] = ['id' => 'funcs', 'label' => 'در دسترس بودن توابع ضروری PHP',
            'value' => $blocked ? implode('، ', $blocked) . ' غیرفعال' : 'سالم',
            'ok' => $blocked ? false : true, 'required' => true,
            'hint' => $blocked ? 'این توابع در disable_functions فایل php.ini قفل شده‌اند؛ از پشتیبانی هاست بخواهید آزاد کنند.' : ''];

        // session
        $rows[] = ['id' => 'session', 'label' => 'نشست‌های PHP (Session)',
            'value' => session_status() === PHP_SESSION_ACTIVE ? 'فعال' : 'غیرفعال',
            'ok' => session_status() === PHP_SESSION_ACTIVE, 'required' => true,
            'hint' => session_status() !== PHP_SESSION_ACTIVE ? 'برای ورود به پنل، Session باید کار کند.' : ''];

        $allOk = true;
        foreach ($rows as $r) { if ($r['required'] && $r['ok'] !== true) { $allOk = false; break; } }
        jok(['rows' => $rows, 'all_ok' => $allOk]);
    }

    /* ---------- تست اتصال دیتابیس ---------- */
    if ($action === 'testdb') {
        need_csrf();
        $db = read_db_input();
        $create = !empty($_POST['create_db']);
        if ($db['user'] === '') jfail('نام کاربری دیتابیس را وارد کنید.', ['field' => 'db_user']);

        $server = null; $isMaria = false; $version = ''; $utf8 = false;
        try {
            $server = pdo_connect($db, false);
        } catch (Throwable $e) {
            jfail(db_error_persian($e), ['stage' => 'server']);
        }

        try {
            $version = (string)$server->query('SELECT version()')->fetchColumn();
            $isMaria = stripos($version, 'mariadb') !== false;
            $utf8 = (bool)$server->query("SHOW CHARSET LIKE 'utf8mb4'")->fetch();
        } catch (Throwable $e) { /* غیربحرانی */ }

        $minOk = $isMaria
            ? version_compare(preg_replace('/[^0-9.]/', '', explode('MariaDB', $version)[0]), '10.2', '>=')
            : version_compare(preg_replace('/[^0-9.]/', '', $version), '5.7', '>=');
        if ($version !== '' && !$minOk) {
            jfail('نسخه‌ی دیتابیس (' . htmlspecialchars($version) . ') قدیمی است. MySQL ۵.۷+ یا MariaDB ۱۰.۲+ لازم است.', ['stage' => 'version']);
        }

        $created = false; $dbExists = false;
        if ($db['name'] !== '') {
            try {
                pdo_connect($db, true, 8);
                $dbExists = true;
            } catch (Throwable $e) {
                $c = $e instanceof PDOException && property_exists($e, 'errorInfo') && is_array($e->errorInfo) ? (int)($e->errorInfo[1] ?? 0) : 0;
                if ((int)$e->getCode() === 1049 || $c === 1049 || strpos($e->getMessage(), 'Unknown database') !== false) {
                    if ($create) {
                        try {
                            $qname = str_replace('`', '', $db['name']);
                            $server->exec("CREATE DATABASE IF NOT EXISTS `{$qname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                            $created = true;
                            pdo_connect($db, true, 8);
                            $dbExists = true;
                        } catch (Throwable $e2) {
                            jfail(db_error_persian($e2, true), ['stage' => 'create']);
                        }
                    } else {
                        jfail(db_error_persian($e), ['stage' => 'dbname', 'suggest_create' => true]);
                    }
                } else {
                    jfail(db_error_persian($e), ['stage' => 'dbname']);
                }
            }
        } else {
            jfail('نام دیتابیس را وارد کنید.', ['field' => 'db_name']);
        }

        jok([
            'version' => $version,
            'engine'  => $isMaria ? 'MariaDB' : 'MySQL',
            'utf8mb4' => $utf8,
            'created' => $created,
            'db_exists' => $dbExists,
        ]);
    }

    /* ---------- اجرای مرحله‌به‌مرحله‌ی نصب ---------- */
    if ($action === 'install') {
        need_csrf();
        $step = pget('step');

        // همه‌ی مراحل به‌جز «config» به اتصال دیتابیس نیاز دارند (هر درخواست، اتصال تازه)
        if ($step !== 'config') {
            $cfgInst = existing_config();
            if (!is_array($cfgInst)) jfail('config.php پیدا نشد؛ مرحله‌ی قبل کامل نشده است.');
            try {
                Database::init($cfgInst['db']);
            } catch (Throwable $e) {
                jfail(db_error_persian($e));
            }
        }

        /* --- ۱) config.php --- */
        if ($step === 'config') {
            $site = [
                'base_url'    => rtrim(pget('base_url'), '/'),
                'brand'       => trim(pget('brand_name')),
                'tz'          => pget('tz', 'Asia/Tehran'),
                'trust_proxy' => !empty($_POST['trust_proxy']),
            ];
            if ($site['base_url'] === '' || !preg_match('#^https?://#i', $site['base_url'])) {
                jfail('آدرس پایه‌ی پنل معتبر نیست (باید با http:// یا https:// شروع شود).');
            }
            if (stripos($site['base_url'], 'example.com') !== false) {
                jfail('آدرس پایه هنوز همان مقدار نمونه است؛ آدرس واقعی پنل را وارد کنید.');
            }
            try { (new DateTimeZone($site['tz'])); } catch (Throwable $e) { $site['tz'] = 'Asia/Tehran'; }

            $db = read_db_input();
            if ($db['name'] === '' || $db['user'] === '') {
                jfail('اطلاعات دیتابیس کامل نیست؛ به مرحله‌ی دیتابیس برگردید.');
            }

            $cfgOld = existing_config();
            $appKey = (is_array($cfgOld) && !empty($cfgOld['app_key']) && is_string($cfgOld['app_key'])
                && strlen($cfgOld['app_key']) >= 40) ? $cfgOld['app_key'] : base64_encode(random_bytes(32));
            $preserved = is_array($cfgOld) && $appKey === $cfgOld['app_key'];

            $conf = [
                'db' => [
                    'host'    => $db['host'],
                    'name'    => $db['name'],
                    'user'    => $db['user'],
                    'pass'    => $db['pass'],
                    'charset' => 'utf8mb4',
                ],
                'app_key' => $appKey,
                'base_url' => $site['base_url'],
                'trust_proxy' => $site['trust_proxy'],
                'paths' => [
                    'uploads' => $ROOT . '/uploads/',
                    'logs'    => $ROOT . '/logs/',
                ],
                'debug' => false,
            ];
            // پورت غیرپیش‌فرض فقط در صورت نیاز ذخیره می‌شود
            if ($db['port'] !== '' && $db['port'] !== '3306') {
                $conf['db']['port'] = (int)$db['port'];
            }

            $php = "<?php\n"
                . "/**\n * config.php — ساخته‌شده توسط ویزارد نصب اعلان‌بان در " . date('Y-m-d H:i') . "\n"
                . " * این فایل حاوی اطلاعات حساس است؛ آن را عمومی نکنید و در به‌روزرسانی‌ها نگه دارید.\n */\n\n"
                . "return " . var_export($conf, true) . ";\n";
            $tmp = $CFG_PATH . '.tmp' . bin2hex(random_bytes(3));
            if (@file_put_contents($tmp, $php) === false) {
                jfail('نوشتن config.php ممکن نشد. پوشه‌ی نصب باید برای PHP قابل‌نوشتن باشد.');
            }
            @chmod($tmp, 0644);
            if (!@rename($tmp, $CFG_PATH)) {
                $okw = @file_put_contents($CFG_PATH, $php) !== false;
                @unlink($tmp);
                if (!$okw) jfail('ساخت config.php ناموفق بود؛ دسترسی نوشتن را بررسی کنید.');
            }

            jok(['preserved_key' => $preserved, 'base_url' => $site['base_url']]);
        }

        /* --- ۲) اتصال دیتابیس --- */
        if ($step === 'db') {
            try {
                $v = (string)Database::pdo()->query('SELECT version()')->fetchColumn();
                $isMaria = stripos($v, 'mariadb') !== false;
                $minOk = $isMaria
                    ? version_compare(preg_replace('/[^0-9.]/', '', explode('MariaDB', $v)[0]), '10.2', '>=')
                    : version_compare(preg_replace('/[^0-9.]/', '', $v), '5.7', '>=');
                if (!$minOk) jfail('نسخه‌ی دیتابیس قدیمی است: ' . htmlspecialchars($v));
                jok(['version' => $v]);
            } catch (Throwable $e) {
                jfail(db_error_persian($e));
            }
        }

        /* --- ۳) جداول هسته --- */
        if ($step === 'schema') {
            try {
                require_once $ROOT . '/core/Schema.php';
                @set_time_limit(300);
                Schema::ensure();
                $n = (int)Database::pdo()->query(
                    "SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'eb\\_%'"
                )->fetchColumn();
                jok(['tables' => $n]);
            } catch (Throwable $e) {
                jfail('ساخت جداول ناموفق بود: ' . htmlspecialchars(mb_substr($e->getMessage(), 0, 300)));
            }
        }

        /* --- ۴) جداول ماژول‌های تکمیلی --- */
        if ($step === 'schema_v4') {
            try {
                require_once $ROOT . '/core/SchemaV4.php';
                @set_time_limit(300);
                SchemaV4::ensure(true);
                jok();
            } catch (Throwable $e) {
                jfail('ساخت جداول تکمیلی ناموفق بود: ' . htmlspecialchars(mb_substr($e->getMessage(), 0, 300)));
            }
        }

        /* --- ۵) حساب مدیر --- */
        if ($step === 'admin') {
            $u = trim(pget('admin_user'));
            $p = (string)($_POST['admin_pass'] ?? '');
            $dn = trim(pget('admin_display'));
            if (mb_strlen($u) < 3) jfail('نام کاربری باید حداقل ۳ کاراکتر باشد.', ['field' => 'admin_user']);
            if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $u)) {
                jfail('نام کاربری فقط می‌تواند شامل حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط باشد.', ['field' => 'admin_user']);
            }
            if (strlen($p) < 6) jfail('رمز عبور باید حداقل ۶ کاراکتر باشد.', ['field' => 'admin_pass']);
            try {
                $has = Database::one("SELECT id FROM eb_users WHERE role='admin' LIMIT 1");
                if ($has) jok(['skipped' => true, 'username' => '']);
                $cols = Database::columns('eb_users');
                if (isset($cols['display_name']) && $dn !== '') {
                    Database::run(
                        "INSERT INTO eb_users (username, password_hash, role, display_name) VALUES (?, ?, 'admin', ?)",
                        [$u, password_hash($p, PASSWORD_BCRYPT), mb_substr($dn, 0, 100)]
                    );
                } else {
                    Database::run(
                        "INSERT INTO eb_users (username, password_hash, role) VALUES (?, ?, 'admin')",
                        [$u, password_hash($p, PASSWORD_BCRYPT)]
                    );
                }
                jok(['skipped' => false, 'username' => $u]);
            } catch (Throwable $e) {
                jfail('ساخت حساب مدیر ناموفق بود: ' . htmlspecialchars(mb_substr($e->getMessage(), 0, 300)));
            }
        }

        /* --- ۶) تنظیمات پایه --- */
        if ($step === 'settings') {
            try {
                $tz = pget('tz', 'Asia/Tehran');
                try { (new DateTimeZone($tz)); } catch (Throwable $e) { $tz = 'Asia/Tehran'; }
                $brand = trim(pget('brand_name'));
                $cronKey = null;
                $cur = Database::one("SELECT v FROM eb_settings WHERE k='cron.key'");
                $cronKey = ($cur && trim((string)$cur['v']) !== '') ? $cur['v'] : bin2hex(random_bytes(16));
                $put = function (string $k, string $v): void {
                    Database::run(
                        "INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)",
                        [$k, $v]
                    );
                };
                $put('app.tz', $tz);
                $put('cron.key', $cronKey);
                $put('installed.at', date('Y-m-d H:i'));
                $put('installed.by', 'wizard/' . EB_WIZARD_VERSION);
                if ($brand !== '' && $brand !== 'اعلان‌بان') $put('brand.name', mb_substr($brand, 0, 60));

                $cfg = existing_config();
                $baseUrl = rtrim((string)($cfg['base_url'] ?? detect_base_url()), '/');
                jok([
                    'cron_key' => $cronKey,
                    'base_url' => $baseUrl,
                    'abs_path' => $ROOT,
                ]);
            } catch (Throwable $e) {
                jfail('ثبت تنظیمات پایه ناموفق بود: ' . htmlspecialchars(mb_substr($e->getMessage(), 0, 300)));
            }
        }

        jfail('مرحله‌ی نصب نامشخص است.');
    }

    /* ---------- حذف فایل نصب ---------- */
    if ($action === 'selfdestruct') {
        need_csrf();
        if (!is_file($CFG_PATH)) jfail('config.php وجود ندارد؛ حذف فایل نصب هنوز امن نیست.');
        if (@unlink(__FILE__)) {
            jok(['deleted' => true]);
        }
        jfail('حذف خودکار ممکن نشد (مجوز فایل). لطفاً install.php را از فایل‌منیجر هاست دستی حذف کنید.', ['deleted' => false]);
    }

    jfail('اکشن ناشناخته.');
}

/* =====================================================================
 *  وضعیت برای رندر صفحه
 * ===================================================================== */

require_once $ROOT . '/core/Database.php';

$cfg = existing_config();
$adminExists = null;
if (is_array($cfg) && !empty($cfg['db'])) {
    try {
        Database::init($cfg['db']);
        $adminExists = admin_exists();
    } catch (Throwable $e) { $adminExists = null; }
}
$LOCKED = (is_array($cfg) && $adminExists === true);

$state = [
    'csrf'        => $CSRF,
    'locked'      => $LOCKED,
    'php_version' => PHP_VERSION,
    'wizard_version' => EB_WIZARD_VERSION,
    'has_config'  => is_array($cfg),
    'detected_base_url' => detect_base_url(),
    'abs_path'    => $ROOT,
    'prefill'     => null,
    'cron_key'    => null,
];
if (is_array($cfg) && !$LOCKED) {
    $state['prefill'] = [
        'db_host' => (string)($cfg['db']['host'] ?? ''),
        'db_name' => (string)($cfg['db']['name'] ?? ''),
        'db_user' => (string)($cfg['db']['user'] ?? ''),
        'base_url' => (string)($cfg['base_url'] ?? ''),
    ];
}
if ($LOCKED && is_array($cfg)) {
    try {
        $r = Database::one("SELECT v FROM eb_settings WHERE k='cron.key'");
        $state['cron_key'] = ($r && trim((string)$r['v']) !== '') ? (string)$r['v'] : null;
    } catch (Throwable $e) {}
}

/* =====================================================================
 *  رندر صفحه‌ی ویزارد
 * ===================================================================== */

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$stateJson = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$brandTitle = 'اعلان‌بان';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>نصب <?= $brandTitle ?> · ویزارد راه‌اندازی</title>
<link rel="stylesheet" href="assets/fonts.css">
<style>

/* ================= هویت بصری «کاج و زعفران» (هماهنگ با پنل) ================= */
:root{
  --a-l:#1D5C4D; --a-l-ink:#134236; --a-l-soft:#E3EEE9; --a-l-on:#FFFFFF;
  --g-l:#A97A22; --g-d:#E2B45C;
  --brand-side:#0F2A25;
  --accent:var(--a-l); --accent-ink:var(--a-l-ink); --accent-soft:var(--a-l-soft); --on-accent:#FFFFFF;
  --gold:var(--g-l); --gold-soft:#F6ECD8;
  --bg:#F2F0EA; --surface:#FFFFFF; --surface-2:#F8F7F3; --surface-3:#EEEBE3;
  --line:#E2DED3; --line-2:#ECE9E1; --line-strong:#CEC8BA;
  --ink:#16201D; --ink-2:#47534E; --ink-3:#7A847F;
  --ok:#2F7A3E; --ok-soft:#E5F1E2; --warn:#9A6206; --warn-soft:#F8EDD6;
  --err:#B23A2E; --err-soft:#F8E6E2; --info:#2B5C9E; --info-soft:#E5ECF6;
  --r:9px; --r-lg:14px;
  --sh-1:0 1px 2px rgba(40,35,20,.06),0 0 0 1px rgba(40,35,20,.02);
  --sh-2:0 6px 18px -6px rgba(40,35,20,.14),0 2px 4px rgba(40,35,20,.05);
  --sh-3:0 24px 56px -12px rgba(20,25,20,.28),0 4px 12px rgba(20,25,20,.08);
  --font:'Vazirmatn',Tahoma,system-ui,sans-serif;
  --display:'Markazi Text','Vazirmatn',serif;
  --mono:'IBM Plex Mono',ui-monospace,SFMono-Regular,Menlo,monospace;
  --girih:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='56' height='56' viewBox='0 0 56 56'%3E%3Cg fill='none' stroke='%23D2AD5E' stroke-opacity='.14' stroke-width='.9'%3E%3Crect x='14' y='14' width='28' height='28'/%3E%3Cpath d='M28 8L48 28L28 48L8 28Z'/%3E%3Cpath d='M0 0L14 14M56 0L42 14M0 56L14 42M56 56L42 42M28 0V8M28 48V56M0 28H8M48 28H56'/%3E%3Ccircle cx='28' cy='28' r='5'/%3E%3C/g%3E%3C/svg%3E");
  --ease:cubic-bezier(.2,.7,.2,1);
}
@media (prefers-color-scheme: dark){
  :root:not([data-theme="light"]){
    --accent:#5CC2A2; --accent-ink:#9BDCC6; --accent-soft:#16302A; --on-accent:#06140F;
    --gold:#E2B45C; --gold-soft:#2C2414;
    --bg:#0B1311; --surface:#111B18; --surface-2:#15211E; --surface-3:#1B2925;
    --line:#22322D; --line-2:#1B2925; --line-strong:#33463F;
    --ink:#E8EEEA; --ink-2:#B3C0BA; --ink-3:#84928C;
    --ok:#6CC77E; --ok-soft:#15281A; --warn:#E3A43F; --warn-soft:#2B2213;
    --err:#EF7A6C; --err-soft:#2E1714; --info:#82A9E4; --info-soft:#16223A;
  }
}
*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{font-family:var(--font);font-size:14px;line-height:1.7;background:var(--bg);color:var(--ink);min-height:100dvh}
svg.ic{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none}

/* ================= چیدمان دوستونی ================= */
.wiz{min-height:100dvh;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.05fr)}
.wiz-side{position:sticky;top:0;align-self:start;max-height:100dvh;overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.18) transparent;background-color:var(--brand-side);background-image:radial-gradient(560px 560px at -90px calc(100% + 140px),rgba(226,180,92,.16),transparent 62%),var(--girih);background-size:auto,72px auto;background-repeat:no-repeat,repeat;color:#E9EFEC;padding:42px 42px 26px;display:flex;flex-direction:column;gap:24px;isolation:isolate}
.wiz-brand{display:flex;align-items:center;gap:12px}
.wiz-brand b{font-family:var(--display);font-size:30px;color:#FAF7EE;font-weight:600}
.logo{display:grid;place-items:center;width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#1D5C4D,#2E7A65);color:#FAF7EE;box-shadow:inset 0 0 0 1px rgba(255,255,255,.14)}
.logo .ic{width:22px;height:22px}
.wiz-ver{font-size:12px;opacity:.55;border-top:1px solid rgba(255,255,255,.09);padding-top:14px}

/* --- بخش پویا: با هر مرحله تازه می‌شود --- */
.side-in{animation:sideIn .45s var(--ease)}
@keyframes sideIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.side-tag{display:inline-flex;align-items:center;gap:7px;padding:4px 12px;border-radius:999px;background:rgba(226,180,92,.13);border:1px solid rgba(226,180,92,.35);color:#E2B45C;font-size:11.5px;font-weight:700}
.side-dyn h2{font-family:var(--display);font-size:31px;line-height:1.3;color:#FAF7EE;font-weight:600;margin-top:13px}
.side-dyn .lead{opacity:.82;font-size:13.5px;margin-top:10px;line-height:2;max-width:48ch}
.side-pts{display:flex;flex-direction:column;gap:10px;margin-top:18px}
.side-pt{display:flex;gap:11px;align-items:flex-start;padding:12px 13px;border-radius:12px;background:rgba(255,255,255,.045);border:1px solid rgba(255,255,255,.07)}
.side-pt .ic{color:#E2B45C;width:19px;height:19px;margin-top:2px}
.side-pt b{display:block;font-size:13px;color:#F2F5F0;font-weight:700}
.side-pt small{display:block;font-size:11.8px;opacity:.74;line-height:1.85;margin-top:3px}
.side-chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:16px}
.side-chip{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;padding:4px 11px;border-radius:999px;background:rgba(92,194,162,.1);border:1px solid rgba(92,194,162,.28);color:#9BDCC6}
.side-chip .ic{width:13px;height:13px;stroke-width:2.4}
.side-chip.gold{background:rgba(226,180,92,.1);border-color:rgba(226,180,92,.3);color:#E9C98A}
.side-chip.err{background:rgba(239,122,108,.1);border-color:rgba(239,122,108,.3);color:#F2A79D}

/* --- بخش‌های ثابت معرفی محصول --- */
.side-block{border-top:1px solid rgba(255,255,255,.09);padding-top:19px}
.side-block-h{display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:700;color:#F2F5F0;margin-bottom:6px}
.side-block-h .ic{color:#E2B45C;width:17px;height:17px}
.side-block p{font-size:12.3px;opacity:.74;line-height:1.95}
.side-feats{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:13px}
.side-feat{padding:11px 12px;border-radius:11px;background:rgba(255,255,255,.045);border:1px solid rgba(255,255,255,.07)}
.side-feat .ic{width:17px;height:17px;color:#8FD4B9}
.side-feat b{display:block;font-size:12px;margin-top:7px;color:#F2F5F0}
.side-feat small{display:block;font-size:10.8px;opacity:.66;line-height:1.8;margin-top:3px}
.side-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:9px;margin-top:14px}
.side-stat{border-radius:11px;padding:11px 8px 9px;text-align:center;background:rgba(226,180,92,.08);border:1px solid rgba(226,180,92,.22)}
.side-stat b{display:block;font-family:var(--display);font-size:23px;color:#E2B45C;line-height:1.2}
.side-stat small{font-size:10.5px;opacity:.75;display:block;margin-top:2px}
.side-note{display:flex;gap:10px;align-items:flex-start;margin-top:14px;padding:12px 13px;border-radius:12px;background:rgba(92,194,162,.09);border:1px solid rgba(92,194,162,.25)}
.side-note .ic{color:#8FD4B9;width:18px;height:18px;margin-top:2px}
.side-note b{font-size:12.5px;color:#DFF2E9;display:block}
.side-note small{font-size:11.5px;opacity:.78;line-height:1.85;display:block;margin-top:2px}
.side-doc{font-size:12.3px;opacity:.78;line-height:2}
.side-doc .kbd{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.15);color:#DDE7E1}

/* ================= راهنمای مرحله‌ای (کشوی کمک) ================= */
.help-fab{position:fixed;left:22px;bottom:22px;z-index:60;display:inline-flex;align-items:center;gap:8px;height:46px;padding:0 19px;border-radius:999px;border:1px solid rgba(226,180,92,.5);background:var(--brand-side);color:#F2E7CE;font:inherit;font-size:13px;font-weight:700;cursor:pointer;box-shadow:var(--sh-3);transition:all .25s var(--ease)}
.help-fab:hover{transform:translateY(-2px);border-color:#E2B45C;color:#FAF3DD}
.help-fab .ic{color:#E2B45C}
.help-drawer{position:fixed;left:22px;bottom:80px;z-index:60;width:min(372px,calc(100vw - 28px));max-height:min(600px,calc(100dvh - 122px));display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--line);border-radius:16px;box-shadow:var(--sh-3);opacity:0;transform:translateY(14px) scale(.98);pointer-events:none;transition:all .28s var(--ease)}
.help-drawer.on{opacity:1;transform:none;pointer-events:auto}
.hd-head{display:flex;align-items:center;gap:11px;padding:14px 16px;border-bottom:1px solid var(--line-2)}
.hd-ic{width:38px;height:38px;border-radius:11px;background:var(--accent-soft);color:var(--accent);display:grid;place-items:center;flex:none}
.hd-head b{font-size:13.5px;display:block}
.hd-head small{font-size:11.5px;color:var(--ink-3);display:block;margin-top:1px}
.hd-close{margin-inline-start:auto;width:30px;height:30px;border-radius:8px;border:1px solid var(--line);background:var(--surface-2);color:var(--ink-3);cursor:pointer;display:grid;place-items:center;flex:none}
.hd-close:hover{color:var(--err);border-color:var(--err);background:var(--err-soft)}
.hd-close .ic{width:14px;height:14px}
.hd-body{overflow-y:auto;padding:11px 13px;display:flex;flex-direction:column;gap:8px}
.hd-item{border:1px solid var(--line);border-radius:11px;background:var(--surface-2)}
.hd-item summary{display:flex;align-items:center;gap:9px;padding:11px 12px;cursor:pointer;font-size:12.8px;font-weight:700;list-style:none}
.hd-item summary::-webkit-details-marker{display:none}
.hd-item summary:hover{color:var(--accent)}
.hd-item summary .n{flex:none;width:21px;height:21px;border-radius:50%;background:var(--accent-soft);color:var(--accent);display:grid;place-items:center;font-size:11px;font-weight:800}
.hd-item summary .chev{margin-inline-start:auto;width:14px;height:14px;color:var(--ink-3);transition:transform .2s var(--ease);flex:none}
.hd-item[open] summary .chev{transform:rotate(-90deg)}
.hd-a{padding:0 42px 12px 14px;font-size:12.2px;color:var(--ink-2);line-height:2}
.hd-show{margin-top:9px;display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;color:var(--accent);background:var(--accent-soft);border:1px solid transparent;border-radius:8px;padding:5px 10px;cursor:pointer;font-family:inherit}
.hd-show:hover{border-color:var(--accent)}
.hd-show .ic{width:13px;height:13px}
.hd-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 15px;border-top:1px solid var(--line-2);background:var(--surface-2);font-size:11.5px;color:var(--ink-3)}
.hd-foot .btn{height:34px;font-size:12.5px;padding:0 14px}
.pulse-target{animation:targetPulse 1.4s ease-in-out 2}
@keyframes targetPulse{0%,100%{box-shadow:none}45%{box-shadow:0 0 0 4px var(--gold-soft),0 0 0 2px var(--gold)}}
.rtl-flip{transform:scaleX(-1)}
.wiz-main{display:grid;place-items:center;padding:36px 20px;background:var(--bg)}
.wiz-card{width:min(620px,100%)}

/* ================= نوار مراحل ================= */
.steps{display:flex;align-items:flex-start;gap:2px;margin-bottom:26px}
.steps .st{flex:1;display:flex;flex-direction:column;align-items:center;gap:7px;position:relative;cursor:default}
.steps .st::before{content:"";position:absolute;top:13px;right:calc(50% + 16px);left:calc(-50% + 16px);height:2px;background:var(--line);border-radius:2px;transition:background .3s var(--ease)}
.steps .st:first-child::before{display:none}
.steps .st i{width:27px;height:27px;border-radius:50%;display:grid;place-items:center;font-style:normal;font-size:12.5px;font-weight:700;background:var(--surface);color:var(--ink-3);border:1.5px solid var(--line-strong);transition:all .3s var(--ease);z-index:1}
.steps .st small{font-size:11px;color:var(--ink-3);white-space:nowrap;transition:color .3s}
.steps .st.active i{background:var(--accent);border-color:var(--accent);color:var(--on-accent);box-shadow:0 0 0 4px var(--accent-soft)}
.steps .st.active small{color:var(--ink);font-weight:600}
.steps .st.done i{background:var(--ok-soft);border-color:var(--ok);color:var(--ok)}
.steps .st.done::before{background:var(--ok)}
.steps .st.clickable{cursor:pointer}

/* ================= کارت و گام‌ها ================= */
.panel{background:var(--surface);border:1px solid var(--line);border-radius:var(--r-lg);box-shadow:var(--sh-2);overflow:hidden}
.p-head{padding:22px 26px 0}
.p-head h1{font-family:var(--display);font-size:28px;font-weight:600;line-height:1.25}
.p-head .sub{color:var(--ink-3);font-size:13px;margin-top:5px;max-width:52ch}
.p-body{padding:20px 26px}
.p-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 26px;border-top:1px solid var(--line-2);background:var(--surface-2)}
.p-foot .spacer{flex:1}
.step-pane{display:none;animation:fadeUp .35s var(--ease)}
.step-pane.on{display:block}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

/* ================= فرم‌ها ================= */
.field{margin-bottom:15px}
.field label{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;margin-bottom:6px}
.field label .opt{font-weight:400;color:var(--ink-3);font-size:11.5px}
.inp{width:100%;height:42px;padding:0 13px;border:1px solid var(--line-strong);border-radius:var(--r);background:var(--surface);color:var(--ink);font:inherit;transition:border-color .2s,box-shadow .2s}
.inp:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.inp.ltr{direction:ltr;text-align:left;font-family:var(--mono);font-size:13px}
textarea.inp{height:auto;min-height:64px;padding:10px 13px;resize:vertical}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}
.hint{font-size:12px;color:var(--ink-3);margin-top:5px}
.check{display:flex;align-items:flex-start;gap:9px;padding:11px 13px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface-2);cursor:pointer;transition:all .2s}
.check:hover{border-color:var(--line-strong)}
.check input{margin-top:3px;accent-color:var(--accent);width:15px;height:15px;flex:none}
.check div b{font-size:13px;font-weight:600;display:block}
.check div small{color:var(--ink-3);font-size:11.5px;line-height:1.6;display:block;margin-top:2px}

/* ================= دکمه‌ها ================= */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:40px;padding:0 18px;border-radius:var(--r);border:1px solid var(--line-strong);background:var(--surface);color:var(--ink);font:inherit;font-weight:600;font-size:13.5px;cursor:pointer;transition:all .2s var(--ease);white-space:nowrap}
.btn:hover{border-color:var(--accent);color:var(--accent)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn-p{background:var(--accent);border-color:var(--accent);color:var(--on-accent);padding:0 24px}
.btn-p:hover{filter:brightness(1.08);color:var(--on-accent)}
.btn-ghost{border-color:transparent;background:transparent;color:var(--ink-2)}
.btn-ghost:hover{color:var(--accent);background:var(--accent-soft)}
.btn-danger{border-color:var(--err);color:var(--err);background:var(--err-soft)}
.btn-danger:hover{background:var(--err);color:#fff}
.btn-lg{height:46px;font-size:14.5px;width:100%}

/* ================= پیام‌ها و نشان‌ها ================= */
.callout{display:flex;gap:9px;align-items:flex-start;padding:11px 13px;border-radius:var(--r);font-size:13px;line-height:1.7;margin-bottom:14px;border:1px solid transparent}
.callout .ic{margin-top:3px}
.callout.ok{background:var(--ok-soft);color:var(--ok);border-color:color-mix(in srgb,var(--ok) 25%,transparent)}
.callout.err{background:var(--err-soft);color:var(--err);border-color:color-mix(in srgb,var(--err) 25%,transparent)}
.callout.warn{background:var(--warn-soft);color:var(--warn);border-color:color-mix(in srgb,var(--warn) 25%,transparent)}
.callout.info{background:var(--info-soft);color:var(--info);border-color:color-mix(in srgb,var(--info) 25%,transparent)}
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;font-size:11.5px;font-weight:600}
.badge.ok{background:var(--ok-soft);color:var(--ok)}
.badge.warn{background:var(--warn-soft);color:var(--warn)}
.badge.err{background:var(--err-soft);color:var(--err)}

/* ================= چک‌لیست محیط ================= */
.req{display:flex;align-items:flex-start;gap:11px;padding:11px 4px;border-bottom:1px dashed var(--line)}
.req:last-child{border-bottom:none}
.req .st-ic{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;flex:none;margin-top:1px}
.req .st-ic .ic{width:13px;height:13px;stroke-width:2.6}
.req.pass .st-ic{background:var(--ok-soft);color:var(--ok)}
.req.soft .st-ic{background:var(--warn-soft);color:var(--warn)}
.req.fail .st-ic{background:var(--err-soft);color:var(--err)}
.req.pending .st-ic{background:var(--surface-3);color:var(--ink-3)}
.req .t{flex:1;min-width:0}
.req .t b{font-size:13px;font-weight:600;display:block}
.req .t small{color:var(--ink-3);font-size:12px;display:block;line-height:1.6;margin-top:1px}
.req .val{font-family:var(--mono);font-size:11.5px;color:var(--ink-3);direction:ltr;white-space:nowrap;margin-top:2px}
.summary{display:flex;align-items:center;gap:8px;margin-top:14px;padding:12px 14px;border-radius:var(--r);font-size:13px;font-weight:600}

/* ================= کنسول نصب ================= */
.console{background:var(--brand-side);border-radius:var(--r);padding:14px 16px;font-size:12.5px;max-height:300px;overflow:auto;direction:ltr}
.console .ln{display:flex;align-items:flex-start;gap:9px;padding:5px 0;color:#B9C9C2;direction:rtl;font-size:12.5px}
.console .ln .n{direction:ltr;font-family:var(--mono);flex:none;width:16px;color:#5E7A70}
.console .ln .tx{flex:1}
.console .ln.ok .tx{color:#8FD4B9}
.console .ln.err .tx{color:#F2A79D}
.console .ln .dot{flex:none;width:16px;height:16px;border-radius:50%;margin-top:2px}
.console .ln.wait .dot{border:2px solid #E2B45C;border-top-color:transparent;animation:spin .7s linear infinite}
.console .ln.ok .dot{background:#2F7A3E;display:grid;place-items:center}
.console .ln.ok .dot::after{content:"";width:6px;height:3.5px;border-left:1.8px solid #fff;border-bottom:1.8px solid #fff;transform:rotate(-45deg) translateY(-1px)}
.console .ln.err .dot{background:#B23A2E}
.console .ln.err .dot::before,.console .ln.err .dot::after{content:"";position:absolute;width:9px;height:1.8px;background:#fff;margin-top:7px}
.console .ln.err .dot::before{transform:rotate(45deg)}
.console .ln.err .dot::after{transform:rotate(-45deg)}
.console .ln{position:relative}
@keyframes spin{to{transform:rotate(360deg)}}
.inst-title{display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:700;margin-bottom:12px}
.inst-title .pulse{width:9px;height:9px;border-radius:50%;background:var(--gold);animation:pulse 1.2s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.35;transform:scale(.8)}}

/* ================= صفحه‌ی پایان ================= */
.done-hero{text-align:center;padding:8px 0 4px}
.done-hero .ring{width:66px;height:66px;border-radius:50%;background:var(--ok-soft);color:var(--ok);display:grid;place-items:center;margin:0 auto 14px}
.done-hero .ring .ic{width:30px;height:30px;stroke-width:2.4}
.done-hero h2{font-family:var(--display);font-size:30px;font-weight:600}
.done-hero p{color:var(--ink-3);font-size:13.5px;margin-top:5px}
.cron-box{background:var(--surface-2);border:1px solid var(--line);border-radius:var(--r);padding:13px 15px;margin-top:10px}
.cron-box .cb-h{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:7px}
.cron-box .cb-h b{font-size:12.5px}
.cron-box pre{direction:ltr;text-align:left;font-family:var(--mono);font-size:11.5px;line-height:1.8;color:var(--accent-ink);background:var(--surface);border:1px solid var(--line);border-radius:7px;padding:9px 12px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;margin:0}
.copied{opacity:1;color:var(--ok)!important}
.sec-list{display:flex;flex-direction:column;gap:9px;margin-top:4px}
.sec-item{display:flex;gap:10px;align-items:flex-start;padding:11px 13px;border:1px solid var(--line);border-radius:var(--r);background:var(--surface-2)}
.sec-item .ic{color:var(--gold);margin-top:2px}
.sec-item b{font-size:12.5px;display:block}
.sec-item small{color:var(--ink-3);font-size:11.5px;line-height:1.7;display:block}
.linkish{color:var(--accent);text-decoration:none;font-weight:600}
.linkish:hover{text-decoration:underline}

/* ================= قفل (نصب‌شده) ================= */
.lock-wrap{text-align:center;padding:16px 6px}
.lock-wrap .ring{width:70px;height:70px;border-radius:50%;background:var(--accent-soft);color:var(--accent);display:grid;place-items:center;margin:0 auto 16px}
.lock-wrap .ring .ic{width:32px;height:32px}
.lock-wrap h1{font-family:var(--display);font-size:30px;font-weight:600}
.lock-wrap p{color:var(--ink-3);font-size:13.5px;max-width:44ch;margin:7px auto 0}

/* ================= ابزارهای کوچک ================= */
.spin{display:inline-block;width:15px;height:15px;border:2px solid color-mix(in srgb,currentColor 30%,transparent);border-top-color:currentColor;border-radius:50%;animation:spin .7s linear infinite;flex:none}
.mono{font-family:var(--mono);direction:ltr;unicode-bidi:embed}
.kbd{font-family:var(--mono);font-size:11px;background:var(--surface-3);border:1px solid var(--line);border-radius:5px;padding:1px 6px;direction:ltr;display:inline-block}
.pw-meter{height:5px;border-radius:5px;background:var(--surface-3);overflow:hidden;margin-top:7px}
.pw-meter i{display:block;height:100%;width:0;border-radius:5px;transition:all .3s var(--ease)}
.pw-label{font-size:11px;color:var(--ink-3);margin-top:4px}
.hide{display:none!important}
@media (max-width:900px){
  .wiz{grid-template-columns:1fr}
  .wiz-side{display:none}
  .wiz-main{padding:20px 14px}
  .p-head h1{font-size:24px}
  .steps small{display:none}
  .grid2{grid-template-columns:1fr}
  .help-fab{left:14px;bottom:14px;height:42px;padding:0 15px}
  .help-drawer{left:12px;right:12px;width:auto;bottom:68px}
}

</style>
</head>
<body>
<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
<symbol id="i-broadcast" viewBox="0 0 24 24"><circle cx="12" cy="12" r="2.2"/><path d="M7.8 16.2a6 6 0 0 1 0-8.4M16.2 7.8a6 6 0 0 1 0 8.4M4.9 19.1a10 10 0 0 1 0-14.2M19.1 4.9a10 10 0 0 1 0 14.2"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><path d="M4.5 12.5l5 5 10-11"/></symbol>
<symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>
<symbol id="i-warn" viewBox="0 0 24 24"><path d="M12 3L2.5 20h19L12 3z"/><path d="M12 10v4.5M12 17.4v.1"/></symbol>
<symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.6v.1"/></symbol>
<symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3l7.5 3v5.5c0 4.8-3.2 8-7.5 9.5-4.3-1.5-7.5-4.7-7.5-9.5V6L12 3z"/><path d="M8.8 12l2.2 2.2 4.2-4.4"/></symbol>
<symbol id="i-db" viewBox="0 0 24 24"><ellipse cx="12" cy="5.5" rx="7.5" ry="2.8"/><path d="M4.5 5.5v13c0 1.5 3.4 2.8 7.5 2.8s7.5-1.3 7.5-2.8v-13M4.5 12c0 1.5 3.4 2.8 7.5 2.8s7.5-1.3 7.5-2.8"/></symbol>
<symbol id="i-server" viewBox="0 0 24 24"><rect x="3.5" y="4" width="17" height="7" rx="2"/><rect x="3.5" y="13" width="17" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/></symbol>
<symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c.8-3.6 3.6-5.4 7-5.4s6.2 1.8 7 5.4"/></symbol>
<symbol id="i-rocket" viewBox="0 0 24 24"><path d="M12 15.5c5.5-4 7-9 7-12.5-3.5 0-8.5 1.5-12.5 7L4 13.5 10.5 20 12 15.5z"/><path d="M9 15.5L4.5 20M14.5 9.5v.01"/></symbol>
<symbol id="i-gear" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.14-1.4l2-1.55-2-3.46-2.35.95A7 7 0 0 0 14 5.1L13.6 2.6h-4L9.2 5.1a7 7 0 0 0-2.5 1.44l-2.36-.95-2 3.46 2 1.55a7 7 0 0 0 0 2.8l-2 1.55 2 3.46 2.35-.95a7 7 0 0 0 2.51 1.44l.4 2.5h4l.4-2.5a7 7 0 0 0 2.5-1.44l2.36.95 2-3.46-2-1.55c.09-.46.14-.93.14-1.4z"/></symbol>
<symbol id="i-copy" viewBox="0 0 24 24"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/></symbol>
<symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16M9.5 7V5a1.5 1.5 0 0 1 1.5-1.5h2A1.5 1.5 0 0 1 14.5 5v2M6 7l1 13h10l1-13M10 11v5M14 11v5"/></symbol>
<symbol id="i-arrow-r" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></symbol>
<symbol id="i-arrow-l" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></symbol>
<symbol id="i-eye" viewBox="0 0 24 24"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="i-login" viewBox="0 0 24 24"><path d="M14 4h4.5A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5H14M10 8l4 4-4 4M14 12H3"/></symbol>
<symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></symbol>
<symbol id="i-spark" viewBox="0 0 24 24"><path d="M12 3l1.9 5.6L19.5 10l-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.4L12 3z"/></symbol>
</defs></svg>

<div class="wiz">
  <section class="wiz-main">
    <div class="wiz-card" id="app">

      <?php if ($LOCKED): /* ======== قفل: نصب قبلاً انجام شده ======== */ ?>
      <div class="panel">
        <div class="p-body">
          <div class="lock-wrap">
            <div class="ring"><svg class="ic"><use href="#i-shield"/></svg></div>
            <h1>اعلان‌بان از قبل نصب شده است</h1>
            <p>جداول دیتابیس آماده‌اند و حساب مدیر وجود دارد. برای امنیت، پیشنهاد می‌کنیم فایل نصب را حذف کنید. اگر می‌خواهید نصب را دوباره اجرا کنید، ابتدا config.php را حذف کنید.</p>
          </div>
          <div id="lockMsg"></div>
          <div class="cron-box" style="margin-top:18px">
            <div class="cb-h"><b>زمان‌بندی خودکار (Cron) — هر دقیقه</b><button class="btn btn-ghost" style="height:30px;padding:0 10px" onclick="copyCron(this)"><svg class="ic" style="width:14px;height:14px"><use href="#i-copy"/></svg></button></div>
            <pre id="lockCron">* * * * * wget -q -O /dev/null "BASE_URL/cron.php<?= $state['cron_key'] ? '?key=' . h($state['cron_key']) : '' ?>"</pre>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <a class="btn btn-p" style="flex:1" href="index.php"><svg class="ic rtl-flip"><use href="#i-login"/></svg> ورود به پنل</a>
            <button class="btn btn-danger" id="lockDel" onclick="selfdestruct(this)"><svg class="ic"><use href="#i-trash"/></svg> حذف فایل نصب</button>
          </div>
        </div>
      </div>

      <?php else: /* ======== ویزارد کامل ======== */ ?>

      <nav class="steps" id="steps" aria-label="مراحل نصب">
        <div class="st" data-step="1"><i>۱</i><small>خوش‌آمدید</small></div>
        <div class="st" data-step="2"><i>۲</i><small>بررسی محیط</small></div>
        <div class="st" data-step="3"><i>۳</i><small>دیتابیس</small></div>
        <div class="st" data-step="4"><i>۴</i><small>تنظیمات</small></div>
        <div class="st" data-step="5"><i>۵</i><small>حساب مدیر</small></div>
        <div class="st" data-step="6"><i>۶</i><small>نصب</small></div>
      </nav>

      <div class="panel">
        <div class="p-head" id="pHead"><h1></h1><div class="sub"></div></div>
        <div class="p-body">

          <!-- ---------- گام ۱: خوش‌آمدید ---------- -->
          <section class="step-pane" data-pane="1">
            <div class="callout info"><svg class="ic"><use href="#i-info"/></svg><div>قبل از شروع، اطلاعات دیتابیس (هاست، نام دیتابیس، نام کاربری و رمز) را از پنل هاست آماده کنید. همه‌ی مراحل دیگر به‌صورت خودکار انجام می‌شود.</div></div>
            <div style="display:flex;flex-direction:column;gap:9px;margin:6px 0 4px">
              <div class="sec-item"><svg class="ic"><use href="#i-db"/></svg><div><b>۱. بررسی محیط و دیتابیس</b><small>نسخه‌ی PHP، اکستنشن‌ها و دسترسی‌های نوشتن خودکار بررسی و اصلاح می‌شود.</small></div></div>
              <div class="sec-item"><svg class="ic"><use href="#i-gear"/></svg><div><b>۲. ساخت config.php و جداول</b><small>فایل پیکربندی با کلید امن تصادفی ساخته و حدود ۸۰ جدول دیتابیس کامل ایجاد می‌شود.</small></div></div>
              <div class="sec-item"><svg class="ic"><use href="#i-user"/></svg><div><b>۳. حساب مدیر و تنظیمات پایه</b><small>حساب مدیر ساخته، منطقه‌ی زمانی تنظیم و کلید cron به‌صورت خودکار تولید می‌شود.</small></div></div>
            </div>
            <div class="hint" style="margin-top:12px;display:flex;gap:14px;flex-wrap:wrap">
              <span>نسخه‌ی PHP: <b class="mono"><?= h(PHP_VERSION) ?></b></span>
              <span>نسخه‌ی نصب: <b>۱.۰.۰</b></span>
              <span>مسیر نصب: <b class="mono" style="font-size:11px"><?= h($ROOT) ?></b></span>
            </div>
          </section>

          <!-- ---------- گام ۲: بررسی محیط ---------- -->
          <section class="step-pane" data-pane="2">
            <div id="reqList"><div class="req pending"><div class="st-ic"><svg class="ic"><use href="#i-clock"/></svg></div><div class="t"><b>در حال بررسی محیط…</b></div></div></div>
            <div id="reqSummary"></div>
            <div id="reqErr"></div>
          </section>

          <!-- ---------- گام ۳: دیتابیس ---------- -->
          <section class="step-pane" data-pane="3">
            <div id="dbErr"></div>
            <div class="grid2">
              <div class="field"><label>هاست دیتابیس <span class="opt">معمولاً localhost</span></label><input class="inp ltr" id="db_host" autocomplete="off" spellcheck="false"></div>
              <div class="field"><label>پورت <span class="opt">اختیاری — پیش‌فرض ۳۳۰۶</span></label><input class="inp ltr" id="db_port" inputmode="numeric" autocomplete="off" placeholder="3306"></div>
            </div>
            <div class="field"><label>نام دیتابیس</label><input class="inp ltr" id="db_name" autocomplete="off" spellcheck="false" placeholder="elanban"></div>
            <div class="grid2">
              <div class="field"><label>نام کاربری دیتابیس</label><input class="inp ltr" id="db_user" autocomplete="off" spellcheck="false"></div>
              <div class="field"><label>رمز دیتابیس</label><input class="inp ltr" id="db_pass" type="password" autocomplete="new-password"></div>
            </div>
            <label class="check"><input type="checkbox" id="create_db"><div><b>ساخت خودکار دیتابیس (اگر وجود نداشت)</b><small>اگر کاربر دیتابیس اجازه‌ی CREATE داشته باشد، خودکار با پشتیبانی کامل utf8mb4 ساخته می‌شود. روی اکثر هاست‌های اشتراکی باید دیتابیس را از پنل هاست بسازید.</small></div></label>
            <div id="dbResult" style="margin-top:14px"></div>
            <button class="btn" id="btnTestDb" style="margin-top:14px" onclick="testDb(this)"><svg class="ic"><use href="#i-db"/></svg> تست اتصال و ادامه</button>
            <div class="hint" style="margin-top:10px">پس از تست موفق، دکمه‌ی «بعدی» فعال می‌شود.</div>
          </section>

          <!-- ---------- گام ۴: تنظیمات ---------- -->
          <section class="step-pane" data-pane="4">
            <div class="field">
              <label>آدرس پایه‌ی پنل (Base URL)</label>
              <input class="inp ltr" id="base_url" spellcheck="false">
              <div class="hint">آدرس کامل پنل بدون اسلش آخر — برای ساخت لینک‌های پیام و وب‌هوک کانال‌ها استفاده می‌شود. به‌صورت خودکار تشخیص داده شده؛ اگر دامنه اختصاصی دارید، همین را ویرایش کنید.</div>
            </div>
            <div class="grid2">
              <div class="field"><label>نام پنل <span class="opt">اختیاری</span></label><input class="inp" id="brand_name" value="اعلان‌بان" maxlength="60"><div class="hint">در صفحه‌ی ورود و عنوان پنل نمایش داده می‌شود.</div></div>
              <div class="field"><label>منطقه‌ی زمانی</label><select class="inp" id="tz" style="height:42px">
                <?php foreach (['Asia/Tehran' => 'تهران (ایران)', 'Asia/Dubai' => 'دبی', 'Europe/Istanbul' => 'استانبول', 'Europe/Berlin' => 'برلین', 'Europe/London' => 'لندن', 'UTC' => 'UTC'] as $tzk => $tzl): ?>
                <option value="<?= h($tzk) ?>" <?= $tzk === 'Asia/Tehran' ? 'selected' : '' ?>><?= h($tzl) ?> — <?= h($tzk) ?></option>
                <?php endforeach; ?>
              </select><div class="hint">زمان ارسال‌های زمان‌بندی‌شده بر این مبنا است.</div></div>
            </div>
            <label class="check"><input type="checkbox" id="trust_proxy"><div><b>سایت پشت CDN یا پروکسی است (مثل کلادفلر)</b><small>با فعال‌کردن، IP واقعی بازدیدکننده در آمار لینک رهگیری ثبت می‌شود. اگر پروکسی ندارید خاموش بماند.</small></div></label>
          </section>

          <!-- ---------- گام ۵: حساب مدیر ---------- -->
          <section class="step-pane" data-pane="5">
            <div class="callout info" style="margin-top:0"><svg class="ic"><use href="#i-user"/></svg><div>با این حساب وارد پنل می‌شوید. نام کاربری با حروف انگلیسی؛ رمز حداقل ۶ کاراکتر (۱۰ کاراکتر ترکیبی پیشنهاد می‌شود).</div></div>
            <div class="grid2">
              <div class="field"><label>نام کاربری مدیر</label><input class="inp ltr" id="admin_user" autocomplete="username" spellcheck="false" dir="ltr"></div>
              <div class="field"><label>نام نمایشی <span class="opt">اختیاری — مثلاً «مدیر سایت»</span></label><input class="inp" id="admin_display" maxlength="100"></div>
            </div>
            <div class="field">
              <label>رمز عبور</label>
              <div style="position:relative">
                <input class="inp ltr" id="admin_pass" type="password" autocomplete="new-password" style="padding-left:42px" oninput="pwMeter()">
                <button type="button" onclick="togglePw()" style="position:absolute;left:6px;top:50%;transform:translateY(-50%);border:none;background:none;color:var(--ink-3);cursor:pointer;padding:6px" title="نمایش رمز"><svg class="ic"><use href="#i-eye"/></svg></button>
              </div>
              <div class="pw-meter"><i id="pwBar"></i></div>
              <div class="pw-label" id="pwLabel">رمز حداقل ۶ کاراکتر</div>
            </div>
            <div class="field"><label>تکرار رمز عبور</label><input class="inp ltr" id="admin_pass2" type="password" autocomplete="new-password"></div>
            <div id="adminErr"></div>
          </section>

          <!-- ---------- گام ۶: نصب ---------- -->
          <section class="step-pane" data-pane="6">
            <div id="installBox">
              <div class="inst-title"><span class="pulse"></span> نصب در حال اجرا…</div>
              <div class="console" id="console"></div>
              <div class="hint" style="margin-top:10px">این صفحه را نبندید؛ معمولاً کمتر از یک دقیقه طول می‌کشد.</div>
            </div>

            <div id="doneBox" class="hide">
              <div class="done-hero">
                <div class="ring"><svg class="ic"><use href="#i-check"/></svg></div>
                <h2>اعلان‌بان با موفقیت نصب شد</h2>
                <p id="doneSub">همه‌ی جداول ساخته شد و حساب مدیر آماده است.</p>
              </div>
              <div style="display:flex;gap:10px;margin:18px 0 6px">
                <a class="btn btn-p btn-lg" id="goLogin" href="index.php"><svg class="ic rtl-flip"><use href="#i-login"/></svg> ورود به پنل</a>
              </div>
              <div class="callout warn" style="margin-top:16px"><svg class="ic"><use href="#i-shield"/></svg><div><b>امنیت:</b> برای جلوگیری از اجرای مجدد نصب، فایل install.php را حذف کنید.
                <button class="btn btn-danger" style="height:32px;margin-top:8px" id="btnDel" onclick="selfdestruct(this)"><svg class="ic" style="width:14px;height:14px"><use href="#i-trash"/></svg> حذف خودکار فایل نصب</button>
              </div></div>
              <div class="cron-box">
                <div class="cb-h"><b>گام آخر: زمان‌بندی خودکار (Cron) را در هاست تنظیم کنید</b><button class="btn btn-ghost" style="height:30px;padding:0 10px" onclick="copyCron(this)"><svg class="ic" style="width:14px;height:14px"><use href="#i-copy"/></svg></button></div>
                <pre id="cronCmd"></pre>
                <div class="hint" style="margin-top:8px">این کار هوش مصنوعی، ارسال‌های زمان‌بندی‌شده، صف ارسال و دریافت خودکار پیامک را هر دقیقه اجرا می‌کند. جایگزین: در cPanel بخش Cron Jobs یا در DirectAdmin بخش Cronjobs.</div>
              </div>
              <div class="sec-list" style="margin-top:14px">
                <div class="sec-item"><svg class="ic"><use href="#i-spark"/></svg><div><b>قدم بعدی: وصل کردن کانال‌ها</b><small>وارد پنل شوید و از چک‌لیست داشبورد، بله، تلگرام، اینستاگرام، پیامک و بقیه‌ی کانال‌ها را یکی‌یکی متصل کنید. راهنمای کامل هر بخش در فایل FEATURES.md کنار اسکریپت است.</small></div></div>
                <div class="sec-item"><svg class="ic"><use href="#i-server"/></svg><div><b>نصب ویجت روی سایت</b><small>برای گفتگوی آنلاین و اعلان سایت، کد ویجت را از «تنظیمات ← سایت و ویجت‌ها» در قالب سایتتان قرار دهید (به HTTPS نیاز دارد).</small></div></div>
              </div>
            </div>
          </section>

        </div>
        <div class="p-foot" id="pFoot">
          <button class="btn btn-ghost" id="btnPrev" onclick="go(-1)"><svg class="ic"><use href="#i-arrow-r"/></svg> قبلی</button>
          <span class="spacer"></span>
          <button class="btn btn-p" id="btnNext" onclick="go(1)">شروع نصب <svg class="ic"><use href="#i-arrow-l"/></svg></button>
        </div>
      </div>
      <div style="text-align:center;font-size:11.5px;color:var(--ink-3);margin-top:14px">ویزارد نصب اعلان‌بان · نسخه‌ی <?= h(EB_WIZARD_VERSION) ?></div>

      <!-- راهنمای مرحله‌ای: دکمه‌ی شناور + کشوی کمک (قابل بستن) -->
      <button class="help-fab" id="helpFab" onclick="toggleHelp()" type="button"><svg class="ic"><use href="#i-info"/></svg> راهنمای این مرحله</button>
      <div class="help-drawer" id="helpDrawer" role="dialog" aria-label="راهنمای مرحله">
        <div class="hd-head">
          <span class="hd-ic"><svg class="ic"><use href="#i-broadcast"/></svg></span>
          <div><b>راهنمای این مرحله</b><small id="hdStep"></small></div>
          <button class="hd-close" onclick="toggleHelp(false)" type="button" title="بستن راهنما"><svg class="ic"><use href="#i-x"/></svg></button>
        </div>
        <div class="hd-body" id="hdBody"></div>
        <div class="hd-foot"><span id="hdCount"></span><button class="btn btn-p" onclick="toggleHelp(false)" type="button">متوجه شدم</button></div>
      </div>

      <?php endif; ?>

    </div>
  </section>

<aside class="wiz-side">
    <div class="wiz-brand"><span class="logo"><svg class="ic"><use href="#i-broadcast"></use></svg></span><b>اعلان‌بان</b></div>

    <!-- بخش پویا: با هر مرحله از ویزارد، محتوای تازه و مرتبط با همان گام را نشان می‌دهد -->
    <div class="side-dyn side-in" id="sideDyn" style="display: none;"></div>

    <div class="side-block">
      <div class="side-block-h"><svg class="ic"><use href="#i-broadcast"></use></svg> همه‌ی کانال‌ها، یک صندوق ورودی</div>
      <p>دایرکت اینستاگرام، تلگرام، بله، روبیکا، پیامک و گفتگوی زنده‌ی سایت همه داخل یک inbox یکپارچه می‌رسند. هر گفتگو به پروفایل مشتری وصل است و هیچ پیامی بین کانال‌ها گم نمی‌شود.</p>
      <div class="side-feats">
        <div class="side-feat"><svg class="ic"><use href="#i-spark"></use></svg><b>سناریوساز گرافیکی</b><small>کمپین چندمرحله‌ای با شرط، تاخیر و پیام شخصی‌سازی‌شده — بدون یک خط کد</small></div>
        <div class="side-feat"><svg class="ic"><use href="#i-user"></use></svg><b>پروفایل مشتری</b><small>تاریخچه‌ی گفتگو، تگ و یادداشت برای هر شخص در همه‌ی کانال‌ها</small></div>
        <div class="side-feat"><svg class="ic"><use href="#i-clock"></use></svg><b>ارسال زمان‌بندی و صف</b><small>صف هوشمند با محدودیت نرخ، پخش ساعتی و جلوگیری از اسپم</small></div>
        <div class="side-feat"><svg class="ic"><use href="#i-server"></use></svg><b>ویجت سایت و چت زنده</b><small>اعلان پوش، چت آنلاین و پاسخ خودکار برای بازدیدکنندگان سایت</small></div>
      </div>
    </div>

    <div class="side-block">
      <div class="side-block-h"><svg class="ic"><use href="#i-shield"></use></svg> مالکیت کامل داده‌ها، روی سرور خودتان</div>
      <p>برخلاف سرویس‌های ابری، همه‌چیز — از پیام‌ها تا توکن کانال‌ها — در دیتابیس و فایل‌های همین سرور می‌ماند. هیچ داده‌ای از سرور شما خارج نمی‌شود.</p>
      <div class="side-stats">
        <div class="side-stat"><b>۶</b><small>کانال اتصالی</small></div>
        <div class="side-stat"><b>۸۰+</b><small>جدول دیتابیس</small></div>
        <div class="side-stat"><b>۱۰۰٪</b><small>داده روی سرور شما</small></div>
      </div>
      <div class="side-note"><svg class="ic"><use href="#i-shield"></use></svg><div><b>کلید امن اختصاصی (app_key)</b><small>ویزارد برای پنل شما یک کلید ۲۵۶ بیتی تصادفی می‌سازد و توکن کانال‌ها با همین کلید رمزنگاری می‌شود؛ هیچ‌وقت بازتولید نمی‌شود تا چیزی نشکند.</small></div></div>
    </div>

    <div class="side-block">
      <div class="side-block-h"><svg class="ic"><use href="#i-info"></use></svg> مستندات و پشتیبانی</div>
      <p class="side-doc">راهنمای کامل همه‌ی بخش‌ها (کانال‌ها، سناریوساز، کمپین، هوش مصنوعی و…) در فایل <span class="kbd">FEATURES.md</span> کنار اسکریپت است. پس از نصب، از منوی «پشتیبانی» داخل پنل هم می‌توانید راهنمای هر بخش را همین‌جا ببینید.</p>
    </div>

    <div class="wiz-ver">نسخه‌ی ۱.۰.۰ · ویزارد <?= h(EB_WIZARD_VERSION) ?></div>
  </aside>
</div>

<script type="application/json" id="ebState"><?= $stateJson ?></script>
<script>

/* ================= وضعیت ================= */
var S = JSON.parse(document.getElementById('ebState').textContent);
var step = 1, LAST = 6;
var envOk = false, dbOk = false, dbTested = false, installing = false, installed = false;
var el = function (id) { return document.getElementById(id); };

var TITLES = {
  1: ['به ویزارد نصب اعلان‌بان خوش آمدید', 'نصب کامل در ۶ گام ساده — بدون ویرایش دستی هیچ فایلی.'],
  2: ['بررسی محیط میزبان', 'پیش‌نیازهای اسکریپت به‌صورت خودکار بررسی می‌شود.'],
  3: ['اتصال به دیتابیس', 'اطلاعات MySQL/MariaDB را از پنل هاست وارد کنید.'],
  4: ['تنظیمات پنل', 'آدرس پنل به‌صورت خودکار تشخیص داده شده؛ در صورت نیاز ویرایش کنید.'],
  5: ['ساخت حساب مدیر', 'این حساب، حساب اصلی مدیر پنل خواهد بود.'],
  6: ['اجرای نصب', 'همه‌چیز آماده است؛ نصب را اجرا کنید.']
};

/* ===== محتوای پویای ستون راهنما (سمت چپ) — با پیشرفت هر مرحله تازه می‌شود ===== */
var SIDE = {
  1: {
    tag: 'مرحله ۱ از ۶ · خوش‌آمدید',
    title: 'در چند دقیقه، اعلان‌بان روی سرور خودتان راه می‌افتد.',
    lead: 'این ویزارد همه‌ی کارهای فنی نصب را خودکار انجام می‌دهد؛ از ساخت فایل پیکربندی و بیش از ۸۰ جدول دیتابیس تا حساب مدیر و کلید زمان‌بندی. تنها چیزی که باید از قبل آماده داشته باشید، اطلاعات دیتابیس است.',
    points: [
      {ic: 'i-db', t: 'تنها پیش‌نیاز: اطلاعات دیتابیس', d: 'هاست، نام دیتابیس، نام کاربری و رمز را از پنل هاست (بخش MySQL Databases) بردارید و دم دست بگذارید.'},
      {ic: 'i-shield', t: 'بدون ویرایش دستی هیچ فایلی', d: 'config.php با کلید امن تصادفی ساخته می‌شود؛ نیازی به کپی‌کردن config.sample.php یا دست‌کاری کد نیست.'},
      {ic: 'i-clock', t: 'اگر وسط راه قطع شد؟', d: 'نصب idempotent است؛ دوباره اجرایش کنید — از همان‌جا ادامه می‌دهد و هیچ داده‌ای خراب نمی‌شود.'},
      {ic: 'i-spark', t: 'راهنمای هر مرحله همراه شماست', d: 'دکمه‌ی «راهنمای این مرحله» پایین صفحه، توضیح کامل هر فیلد را باز می‌کند؛ هر وقت خواستید ببندیدش.'}
    ]
  },
  2: {
    tag: 'مرحله ۲ از ۶ · بررسی محیط',
    title: 'میزبان شما زیر ذره‌بین — بدون سورپرایز در ادامه‌ی راه.',
    lead: '۱۶ سنجه‌ی فنی بررسی می‌شود: نسخه‌ی PHP، اکستنشن‌های لازم، دسترسی نوشتن پوشه‌ها، توابع قفل‌شده و نشست‌ها. اگر چیزی کم باشد، دقیقاً می‌گوییم چه چیزی است و از کجا باید فعالش کنید.',
    points: [
      {ic: 'i-server', t: 'چه چیزهایی سنجیده می‌شود', d: 'PHP ۷.۴+ (پیشنهاد ۸.۱+)، pdo_mysql، curl، mbstring و json؛ به‌همراه قابل‌نوشتن بودن پوشه‌های uploads و logs.'},
      {ic: 'i-warn', t: 'اگر موردی قرمز شد', d: 'تا وقتی الزامی‌ها سبز نشده‌اند، دکمه‌ی بعدی فعال نمی‌شود؛ معمولاً یک پیام به پشتیبانی هاست کافی است.'},
      {ic: 'i-info', t: 'زرد‌ها اختیاری‌اند', d: 'موارد زرد (مثل gd و zip و intl) فقط کیفیت کار را بالا می‌برند؛ نبودشان مانع نصب نیست.'},
      {ic: 'i-check', t: 'نتیجه همین‌جا ثبت می‌شود', d: 'خلاصه‌ی بررسی به‌صورت نشان‌های سبز در همین ستون اضافه می‌شود تا همیشه در دیدتان باشد.'}
    ]
  },
  3: {
    tag: 'مرحله ۳ از ۶ · دیتابیس',
    title: 'اتصال به دیتابیس — قلب داده‌های پنل.',
    lead: 'اعلان‌بان داده‌ها را کامل در MySQL/MariaDB خودتان نگه می‌دارد. اطلاعات را از پنل هاست وارد کنید؛ دکمه‌ی «تست اتصال» قبل از ادامه، همه‌چیز را واقعاً امتحان می‌کند تا در مراحل بعد غافلگیر نشوید.',
    points: [
      {ic: 'i-server', t: 'هاست معمولاً localhost است', d: 'روی هاست‌های اشتراکی cPanel/DirectAdmin همان localhost بماند؛ پورت را فقط اگر هاست عدد دیگری گفته وارد کنید.'},
      {ic: 'i-db', t: 'کاربر باید به دیتابیس دسترسی داشته باشد', d: 'در cPanel بعد از ساخت دیتابیس، کاربر را با ALL PRIVILEGES به همان دیتابیس اضافه کنید.'},
      {ic: 'i-spark', t: 'ساخت خودکار دیتابیس', d: 'اگر کاربرِ دیتابیس اجازه‌ی CREATE دارد، تیکش را بزنید تا خودش بسازد — با پشتیبانی کامل utf8mb4 برای فارسی.'},
      {ic: 'i-shield', t: 'جداول با پیشوند eb_', d: 'همه‌ی جداول با پیشوند eb_ ساخته می‌شوند؛ با بقیه‌ی اسکریپت‌های همان دیتابیس تداخلی پیدا نمی‌کنند.'}
    ]
  },
  4: {
    tag: 'مرحله ۴ از ۶ · تنظیمات',
    title: 'هویت پنل: آدرس، نام و منطقه‌ی زمانی.',
    lead: 'این مقادیر در قلب پیام‌ها و لینک‌ها می‌نشینند: آدرس پایه در لینک رهگیری و وب‌هوک کانال‌ها، نام پنل در صفحه‌ی ورود، و منطقه‌ی زمانی در زمان‌بندی ارسال‌ها. الان درست تنظیمشان کنید تا بعداً لازم به دست‌کاری نباشد.',
    points: [
      {ic: 'i-info', t: 'آدرس پایه (Base URL)', d: 'همان آدرسی که پنل با آن باز می‌شود؛ اگر بعداً https یا دامنه‌ی جدید آوردید، از تنظیمات پنل به‌روزش کنید.'},
      {ic: 'i-clock', t: 'منطقه‌ی زمانی', d: 'مبنای ارسال‌های زمان‌بندی‌شده و گزارش‌های روزانه است؛ برای ایران همان تهران بماند.'},
      {ic: 'i-server', t: 'پشت کلادفلر یا پروکسی هستید؟', d: 'تیک CDN را بزنید تا IP واقعی بازدیدکنندگان در آمار لینک‌های رهگیری ثبت شود.'},
      {ic: 'i-spark', t: 'نام پنل', d: 'هر برندی می‌تواند باشد؛ در صفحه‌ی ورود و عنوان پنل دیده می‌شود و بعداً هم قابل تغییر است.'}
    ]
  },
  5: {
    tag: 'مرحله ۵ از ۶ · حساب مدیر',
    title: 'حساب اصلی مدیر — درِ ورود شما به پنل.',
    lead: 'با همین حساب وارد پنل می‌شوید و بعداً اپراتورهای دیگر را می‌سازید. رمز به‌صورت هش‌شده‌ی bcrypt ذخیره می‌شود و هیچ‌جایی به‌صورت متن ساده نمی‌ماند — پس اگر گمش کردید قابل بازیابی نیست؛ جایی یادداشت کنید.',
    points: [
      {ic: 'i-user', t: 'نام کاربری انگلیسی', d: 'حروف انگلیسی، عدد و نقطه/خط تیره/زیرخط؛ حداقل ۳ کاراکتر. مثلاً admin یا sar_kar.'},
      {ic: 'i-shield', t: 'رمز قوی واقعاً مهم است', d: 'حداقل ۶ کاراکتر (ترجیحاً ۱۰+ با حرف و عدد) — این حساب به همه‌ی گفتگوهای مشتری‌ها دسترسی دارد.'},
      {ic: 'i-spark', t: 'نام نمایشی', d: 'اختیاری؛ همان اسمی که در پاسخ‌ها و پروفایل دیده می‌شود، مثلاً «مدیر پشتیبانی».'},
      {ic: 'i-info', t: 'اگر مدیر از قبل وجود داشته باشد', d: 'ویزارد حساب تکراری نمی‌سازد؛ حساب موجود دست‌نخورده می‌ماند و این مرحله رد می‌شود.'}
    ]
  },
  6: {
    tag: 'مرحله ۶ از ۶ · نصب',
    title: 'همه‌چیز آماده است — یک کلیک تا راه‌اندازی.',
    lead: 'شش قدم پشت‌سرهم اجرا می‌شود و لاگ زنده‌اش را همین‌جا می‌بینید. هر قدم اگر خطا بدهد، نصب همان‌جا می‌ایستد، دلیلش را فارسی می‌گوید و بعد از رفع مشکل، از همان قدم ادامه می‌دهد — بدون خرابکاری.',
    points: [
      {ic: 'i-gear', t: 'چه چیزی اجرا می‌شود', d: 'ساخت config.php ← اتصال دیتابیس ← جداول اصلی ← جداول تکمیلی ← حساب مدیر ← کلید Cron.'},
      {ic: 'i-clock', t: 'معمولاً کمتر از یک دقیقه', d: 'صفحه را نبندید؛ در پایان، دستور آماده‌ی Cron و دکمه‌ی ورود به پنل را می‌بینید.'},
      {ic: 'i-shield', t: 'آخرین قدم با شماست', d: 'پس از پایان، فایل install.php را با دکمه‌ی «حذف خودکار» بردارید تا نصب امن بماند.'},
      {ic: 'i-rocket', t: 'بعدش چه؟', d: 'از چک‌لیست داشبورد، کانال‌ها را یکی‌یکی وصل کنید و ویجت سایت را در قالب قرار دهید.'}
    ]
  }
};

/* حقایق زنده‌ای که در جریان نصب به ستون راهنما اضافه می‌شوند */
var FACTS = { env: [], db: [], inst: [] };
function sideChips() { return [].concat(FACTS.env, FACTS.db, FACTS.inst); }
function refreshSide() { renderSide(step); }

/* ===== محتوای راهنمای مرحله‌ای (کشوی کمک — قابل بستن) ===== */
var HELP = {
  1: [
    {t: 'این ویزارد چه کارهایی را خودکار می‌کند؟', d: 'ساخت فایل config.php با کلید امن ۲۵۶ بیتی، اتصال و تست دیتابیس، ساخت کامل بیش از ۸۰ جدول، ایجاد حساب مدیر، ثبت منطقه‌ی زمانی و تولید کلید زمان‌بندی (Cron). یعنی دقیقاً همان کارهایی که در نصب دستی باید با ویرایش فایل و اجرای SQL انجام می‌شد.'},
    {t: 'قبل از شروع چه چیزی لازم دارم؟', d: 'فقط اطلاعات دیتابیس: هاست (معمولاً localhost)، نام دیتابیس، نام کاربری و رمز آن. اگر هنوز دیتابیس نساخته‌اید، از پنل هاست (cPanel ← MySQL® Databases یا DirectAdmin ← MySQL Management) بسازید و کاربر را به آن متصل کنید.'},
    {t: 'اگر قبلاً نصب نیمه‌کاره داشته‌ام چه می‌شود؟', d: 'نگران نباشید؛ ویزارد idempotent است. config موجود حفظ می‌شود، کلید امن هرگز عوض نمی‌شود، جداول IF NOT EXISTS هستند و حساب مدیر تکراری ساخته نمی‌شود. فقط از همان جایی که قطع شده ادامه می‌دهد.'},
    {t: 'نصب دستی جایگزین چیست؟', d: 'می‌توانید config.sample.php را به config.php تغییر نام دهید و مشخصات را دستی ویرایش کنید؛ ولی نیازی به این کار نیست — همین ویزارد همه‌ی آن مراحل را با کنترل خطای فارسی انجام می‌دهد.'}
  ],
  2: [
    {t: 'نسخه‌ی PHP و اکستنشن‌ها یعنی چه؟', d: 'اعلان‌بان به PHP ۷.۴ یا بالاتر نیاز دارد (۸.۱ تا ۸.۳ سریع‌تر و امن‌تر است). اکستنشن pdo_mysql برای دیتابیس، curl برای ارتباط با درگاه‌های تلگرام/بله/پیامک، mbstring برای پردازش درست فارسی و json برای تبادل داده لازم‌اند. هر کدام خاموش باشد، از پشتیبانی هاست بخواهید فعالش کند.', sel: '#reqList'},
    {t: '«قابل‌نوشتن بودن پوشه‌ها» برای چیست؟', d: 'ویزارد باید config.php را در پوشه‌ی نصب بسازد و پنل باید در uploads فایل رسانه‌ای و در logs گزارش بنویسد. اگر پوشه‌ای قابل‌نوشتن نباشد، مجوز آن را روی ۷۵۵ بگذارید یا مالکیت را به کاربر PHP بدهید.', sel: '#reqList'},
    {t: 'disable_functions و توابع قفل‌شده؟', d: 'برخی هاست‌ها توابع حساس PHP را در php.ini قفل می‌کنند. اگر curl_exec یا password_hash یا random_bytes قفل باشند، پنل درست کار نمی‌کند. رفعش فقط از سمت پشتیبانی هاست ممکن است.', sel: '#reqList'},
    {t: 'موارد زرد (پیشنهادی) چقدر مهم‌اند؟', d: 'openssl برای رمزنگاری توکن کانال‌ها، gd برای کارت‌های گرافیکی، zip برای پشتیبان‌گیری فشرده و intl برای نمایش بهتر تاریخ. نبودشان نصب را متوقف نمی‌کند؛ ولی اگر فعالشان کنید تجربه‌ی کامل‌تری دارید.', sel: '#reqList'}
  ],
  3: [
    {t: 'این چهار مقدار را از کجا بیاورم؟', d: 'در cPanel: بخش MySQL® Databases — اول «Create a New Database»، بعد «MySQL Users »Add New User»، و در انتها «Add User To Database» با تیک ALL PRIVILEGES. در DirectAdmin: بخش MySQL Management. چهار مقدار همین‌جا به شما داده می‌شود.', sel: '#db_host'},
    {t: 'پورت را کی باید عوض کنم؟', d: 'تقریباً هیچ‌وقت! روی هاست‌های اشتراکی همیشه ۳۳۰۶ (پیش‌فرض) درست است. فقط اگر هاست شما دیتابیس را روی پورت خاصی ارائه داده (مثل سرورهای اختصاصی یا برخی VPSها) همان عدد را وارد کنید.', sel: '#db_port'},
    {t: 'تیک «ساخت خودکار دیتابیس» چه می‌کند؟', d: 'اگر نام دیتابیس هنوز وجود ندارد و کاربرِ دیتابیس اجازه‌ی CREATE داشته باشد، خودش با پشتیبانی کامل utf8mb4 (برای فارسی و ایموجی) می‌سازدش. روی اکثر هاست‌های اشتراکی کاربر اجازه‌ی CREATE ندارد و باید از پنل هاست بسازید — به همین دلیل اگر تست شکست بخورد، این تیک خودکار پیشنهاد می‌شود.', sel: '#create_db'},
    {t: 'خطاهای رایج و معنایشان', d: '«Access denied»: نام کاربری/رمز اشتباه یا کاربر به دیتابیس وصل نشده. «Unknown database»: دیتابیس ساخته نشده. «Connection refused»: هاست/پورت اشتباه یا سرویس MySQL خاموش. همه‌ی این‌ها به فارسی و با راه‌حل، زیر فرم نمایش داده می‌شوند.', sel: '#dbResult'}
  ],
  4: [
    {t: 'آدرس پایه (Base URL) چرا مهم است؟', d: 'این آدرس داخل پیام‌هایی که برای مشتری می‌رود (لینک رهگیری، صفحات فرم، دعوت به گفتگو) و در آدرس وب‌هوک کانال‌ها استفاده می‌شود. اگر اشتباه باشد، لینک‌های پیام به جای درست نمی‌روند. ویزارد همان آدرسی را که الان با آن ویزارد را باز کرده‌اید پیشنهاد می‌دهد؛ معمولاً درست است.', sel: '#base_url'},
    {t: 'نام پنل کجا دیده می‌شود؟', d: 'در صفحه‌ی ورود، عنوان مرورگر و هدر پنل. می‌توانید برند خودتان را بگذارید؛ هر وقت خواستید از «تنظیمات پنل» قابل تغییر است.', sel: '#brand_name'},
    {t: 'منطقه‌ی زمانی روی چه چیزهایی اثر دارد؟', d: 'زمان دقیق ارسال‌های زمان‌بندی‌شده، صف ارسال، گزارش‌های روزانه و تاریخ لاگ‌ها. اگر پنل در ایران استفاده می‌شود، «تهران (ایران)» را نگه دارید.', sel: '#tz'},
    {t: 'پشت CDN هستم؛ تیک پروکسی را بزنم؟', d: 'اگر دامنه‌تان از Cloudflare یا پروکسی مشابه رد می‌شود، بله — تا IP واقعی بازدیدکنندگان در آمار لینک‌های رهگیری ثبت شود، نه IP پروکسی. اگر پروکسی ندارید خاموش بماند تا آمار خراب نشود.', sel: '#trust_proxy'}
  ],
  5: [
    {t: 'قواعد نام کاربری مدیر', d: 'فقط حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط؛ بین ۳ تا ۳۲ کاراکتر. این نام فقط برای ورود است و در پیام‌ها دیده نمی‌شود.', sel: '#admin_user'},
    {t: 'یک رمز امن چه شکلی است؟', d: 'حداقل ۶ کاراکتر، ولی ۱۰ کاراکتر ترکیبی (حرف بزرگ/کوچک + عدد + نماد) خیلی بهتر است. سنجه‌ی قدرت زیر فیلد، همین الان به شما نشان می‌دهد رمز در چه سطحی است. رمز با bcrypt هش می‌شود و قابل بازیابی نیست — فقط می‌شود عوضش کرد.', sel: '#admin_pass'},
    {t: 'دکمه‌ی چشمک چیست؟', d: 'برای اینکه مطمئن شوید رمز را درست تایپ کرده‌اید، رمز را موقتاً نمایان می‌کند. قبل از ادامه، یک بار با چشم ببینید و تکرارش را هم چک کنید.', sel: '#admin_pass'},
    {t: 'نام نمایشی به چه دردی می‌خورد؟', d: 'همان اسمی است که برای اپراتورها و در بخش‌های پنل دیده می‌شود (مثلاً «مدیر پشتیبانی»). اختیاری است و بعداً از پروفایل قابل تغییر است.', sel: '#admin_display'},
    {t: 'بعداً اپراتور دیگر چطور بسازم؟', d: 'پس از ورود به پنل، از بخش «تنظیمات ← کاربران» می‌توانید اپراتور بسازید و برای هر کدام تعیین کنید به کدام صفحات دسترسی داشته باشند.'}
  ],
  6: [
    {t: 'هر خط کنسول چه کاری انجام می‌دهد؟', d: '۱) ساخت config.php با کلید امن ۲) اتصال و بررسی نسخه‌ی دیتابیس ۳) ساخت جداول اصلی پنل ۴) جداول ماژول‌های تکمیلی (سناریوساز، ویجت، آنالیتیکس) ۵) ساخت حساب مدیر ۶) ثبت تنظیمات پایه و کلید Cron. هر خط پس از انجام، تیک سبز می‌گیرد.', sel: '#console'},
    {t: 'اگر وسط نصب خطا داد چه کنم؟', d: 'نصب همان‌جا می‌ایستد و دلیل فارسی را می‌بینید. مشکل را رفع کنید (مثلاً مجوز پوشه) و دوباره «شروع نصب» را بزنید؛ از همان قدمِ شکست‌خورده ادامه می‌دهد و قدم‌های قبلی تکرار نمی‌شوند. هیچ داده‌ای پاک نمی‌شود.', sel: '#console'},
    {t: 'دستور Cron را کجا بگذارم؟', d: 'در cPanel: بخش Cron Jobs ← Add New Cron Job ← زمان‌بندی «هر دقیقه (* * * * *)» ← دستور را در قسمت Command بچسبانید. در DirectAdmin: بخش Cronjobs. این کار صف ارسال، دریافت پیام و هوش مصنوعی را هر دقیقه بیدار می‌کند — بدون آن، ارسال‌های زمان‌بندی‌شده اجرا نمی‌شوند.', sel: '#cronCmd'},
    {t: 'چرا حذف install.php مهم است؟', d: 'فایل نصب می‌تواند ساختار دیتابیس را دوباره اجرا کند؛ اگر به دست فرد ناشناس بیفتد ریسک امنیتی دارد. دکمه‌ی «حذف خودکار» همین فایل را از سرور پاک می‌کند — یک کلیک و خیالتان راحت است.', sel: '#btnDel'},
    {t: 'بعد از ورود، اولین کارها چیست؟', d: '۱) از چک‌لیست داشبورد، کانال‌ها (تلگرام/بله/اینستاگرام/پیامک) را وصل کنید ۲) در «تنظیمات» برند و امضای پیام را بچینید ۳) کد ویجت سایت را در قالب قرار دهید ۴) اولین سناریوی خوش‌آمدگویی را در سناریوساز بسازید. جزئیات هر کدام در FEATURES.md است.'}
  ]
};

/* ================= ناوبری گام‌ها ================= */
function render() {
  document.querySelectorAll('.st').forEach(function (s) {
    var n = +s.dataset.step;
    s.classList.toggle('active', n === step);
    s.classList.toggle('done', n < step || (installed && n === LAST));
  });
  document.querySelectorAll('.step-pane').forEach(function (p) {
    p.classList.toggle('on', +p.dataset.pane === step);
  });
  var t = TITLES[step];
  el('pHead').querySelector('h1').textContent = t[0];
  el('pHead').querySelector('.sub').textContent = t[1];
  renderSide(step);            // ستون راهنما با هر جابه‌جایی، محتوای تازه می‌گیرد
  if (helpOpen) renderHelp();  // اگر کشوی راهنما باز است، متنش به همین مرحله سینک شود
  var prev = el('btnPrev'), next = el('btnNext');
  prev.style.visibility = (step === 1 || installing) ? 'hidden' : 'visible';
  next.style.display = (step === LAST && installed) ? 'none' : 'inline-flex';
  if (installing) { next.disabled = true; return; } else { next.disabled = false; }
  if (step === 1) { next.innerHTML = 'شروع نصب <svg class="ic"><use href="#i-arrow-l"/></svg>'; next.disabled = false; }
  if (step === 2) { next.innerHTML = 'بعدی <svg class="ic"><use href="#i-arrow-l"/></svg>'; next.disabled = !envOk; }
  if (step === 3) { next.disabled = !dbOk; next.innerHTML = 'بعدی <svg class="ic"><use href="#i-arrow-l"/></svg>'; }
  if (step === 4) { next.innerHTML = 'بعدی <svg class="ic"><use href="#i-arrow-l"/></svg>'; }
  if (step === 5) { next.innerHTML = 'شروع نصب <svg class="ic"><use href="#i-rocket"/></svg>'; }
  if (step === LAST && !installed) next.innerHTML = 'شروع نصب <svg class="ic"><use href="#i-rocket"/></svg>';
}
function go(dir) {
  var target = step + dir;
  if (target < 1 || target > LAST) return;
  if (dir > 0 && target === 2 && step === 1) { step = 2; runCheck(); render(); return; }
  if (dir > 0 && target === LAST && step === LAST - 1) { step = LAST; render(); runInstall(); return; }
  step = target; render();
}
document.querySelectorAll('.st.done').forEach(function(){});
document.querySelectorAll('.st').forEach(function (s) {
  s.addEventListener('click', function () {
    var n = +s.dataset.step;
    if (installing || n >= step) return;
    if (n === 3 && !envOk) return;
    if (n >= 4 && !dbOk) return;
    step = n; render();
  });
});

/* ================= ستون راهنما (سمت چپ) ================= */
function renderSide(st) {
  var box = el('sideDyn'); if (!box) return;
  var d = SIDE[st]; if (!d) return;
  var chips = sideChips();
  var h = '<span class="side-tag">' + d.tag + '</span>'
    + '<h2>' + d.title + '</h2>'
    + '<p class="lead">' + d.lead + '</p>'
    + '<div class="side-pts">' + d.points.map(function (p) {
        return '<div class="side-pt"><svg class="ic"><use href="#' + p.ic + '"/></svg><div><b>' + p.t + '</b><small>' + p.d + '</small></div></div>';
      }).join('') + '</div>'
    + (chips.length ? '<div class="side-chips">' + chips.map(function (c) {
        var isObj = (typeof c === 'object' && c !== null);
        var txt = isObj ? c.t : c;
        var cls = isObj ? (c.err ? ' err' : (c.gold ? ' gold' : '')) : '';
        var icn = (isObj && c.err) ? 'i-warn' : 'i-check';
        return '<span class="side-chip' + cls + '"><svg class="ic"><use href="#' + icn + '"/></svg>' + txt + '</span>';
      }).join('') + '</div>' : '');
  box.innerHTML = h;
  box.classList.remove('side-in'); void box.offsetWidth; box.classList.add('side-in');
}

/* ================= راهنمای مرحله‌ای (کشوی کمک — قابل بستن) ================= */
var helpOpen = false;
function renderHelp() {
  if (!el('helpDrawer')) return;
  var items = HELP[step] || [];
  el('hdStep').textContent = TITLES[step][0];
  el('hdBody').innerHTML = items.map(function (it, i) {
    return '<details class="hd-item"' + (i === 0 ? ' open' : '') + '>'
      + '<summary><span class="n">' + (i + 1) + '</span><span class="hd-t">' + it.t + '</span><svg class="ic chev"><use href="#i-arrow-l"/></svg></summary>'
      + '<div class="hd-a">' + it.d
      + (it.sel ? '<button class="hd-show" type="button" onclick="showTarget(\'' + it.sel + '\')"><svg class="ic"><use href="#i-info"/></svg> همین‌جا را نشانم بزن</button>' : '')
      + '</div></details>';
  }).join('');
  el('hdCount').textContent = items.length + ' نکته برای این مرحله';
}
function toggleHelp(force) {
  var dr = el('helpDrawer'); if (!dr) return;
  helpOpen = (force === undefined) ? !helpOpen : !!force;
  dr.classList.toggle('on', helpOpen);
  if (helpOpen) renderHelp();
  else { try { localStorage.setItem('ebHelpSeen', '1'); } catch (e) {} }
}
function showTarget(sel) {
  var t = document.querySelector(sel); if (!t) return;
  var box = t.closest('.field') || t.closest('.check') || t.closest('.cron-box') || t;
  box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  box.classList.remove('pulse-target'); void box.offsetWidth; box.classList.add('pulse-target');
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && helpOpen) toggleHelp(false); });

/* ================= درخواست‌ها ================= */
function post(action, data) {
  var f = new FormData();
  f.append('action', action); f.append('csrf', S.csrf);
  if (data) Object.keys(data).forEach(function (k) { f.append(k, data[k]); });
  return fetch(location.pathname, { method: 'POST', body: f, credentials: 'same-origin' })
    .then(function (r) { return r.json(); });
}
function dbPayload() {
  return {
    db_host: el('db_host').value.trim(),
    db_port: el('db_port').value.trim(),
    db_name: el('db_name').value.trim(),
    db_user: el('db_user').value.trim(),
    db_pass: el('db_pass').value === '' && S.has_config ? '\u0000keep\u0000' : el('db_pass').value
  };
}
function sitePayload() {
  return {
    base_url: el('base_url').value.trim(),
    brand_name: el('brand_name').value.trim(),
    tz: el('tz').value,
    trust_proxy: el('trust_proxy').checked ? '1' : ''
  };
}
function adminPayload() {
  return { admin_user: el('admin_user').value.trim(), admin_display: el('admin_display').value.trim(), admin_pass: el('admin_pass').value };
}
function calloutHtml(type, msg) {
  var ic = type === 'err' ? 'i-warn' : (type === 'warn' ? 'i-warn' : 'i-check');
  return '<div class="callout ' + type + '"><svg class="ic"><use href="#' + ic + '"/></svg><div>' + msg + '</div></div>';
}

/* ================= گام ۲: بررسی محیط ================= */
var checking = false, lastCheck = null;
function renderCheck(d) {
  lastCheck = d;
  var list = el('reqList');
  list.innerHTML = d.rows.map(function (r) {
    var cls = r.ok === true ? 'pass' : (r.ok === null ? 'soft' : 'fail');
    var ic = r.ok === true ? 'i-check' : (r.ok === null ? 'i-warn' : 'i-x');
    var val = r.value ? '<div class="val">' + String(r.value).replace(/</g, '&lt;') + '</div>' : '';
    return '<div class="req ' + cls + '"><div class="st-ic"><svg class="ic"><use href="#' + ic + '"/></svg></div>'
      + '<div class="t"><b>' + r.label + '</b>' + (r.hint ? '<small>' + r.hint + '</small>' : '') + '</div>' + val + '</div>';
  }).join('');
  var warnCount = d.rows.filter(function (r) { return r.ok === null; }).length;
  FACTS.env = ['PHP ' + S.php_version, d.rows.length + ' سنجه بررسی شد'];
  FACTS.env.push(d.all_ok ? 'همه‌ی الزامی‌ها سبز است' : { t: 'موارد قرمز مانع ادامه است', err: true });
  if (warnCount) FACTS.env.push({ t: warnCount + ' مورد پیشنهادی خاموش', gold: true });
  if (d.all_ok) {
    envOk = true;
    el('reqSummary').innerHTML = '<div class="summary" style="background:var(--ok-soft);color:var(--ok)"><svg class="ic"><use href="#i-check"/></svg> محیط آماده است!' + (warnCount ? ' <span style="font-weight:400">' + warnCount + ' مورد پیشنهادی فعال نیست ولی مانع نصب نیست.</span>' : '') + '</div>';
  } else {
    envOk = false;
    el('reqSummary').innerHTML = '<div class="summary" style="background:var(--err-soft);color:var(--err)"><svg class="ic"><use href="#i-warn"/></svg> موارد الزامی هنوز برطرف نشده‌اند. پس از اصلاح، دوباره بررسی کنید.</div>'
      + '<button class="btn" style="margin-top:10px" onclick="runCheck(true)"><svg class="ic"><use href="#i-clock"/></svg> بررسی مجدد</button>';
  }
  render();
}
function runCheck(force) {
  if (checking) return;
  if (lastCheck && !force) { renderCheck(lastCheck); return; }
  checking = true;
  envOk = false; render();
  var list = el('reqList');
  list.innerHTML = '<div class="req pending"><div class="st-ic"><span class="spin" style="width:13px;height:13px;border-width:2px"></span></div><div class="t"><b>در حال بررسی محیط…</b></div></div>';
  el('reqSummary').innerHTML = ''; el('reqErr').innerHTML = '';
  post('check', {}).then(function (d) {
    checking = false;
    if (!d.ok) { list.innerHTML = ''; el('reqErr').innerHTML = calloutHtml('err', d.error || 'بررسی ناموفق بود.'); render(); return; }
    renderCheck(d);
  }).catch(function () { checking = false; list.innerHTML = ''; el('reqErr').innerHTML = calloutHtml('err', 'ارتباط با سرور برقرار نشد؛ اتصال را بررسی و دوباره تلاش کنید.'); render(); });
}

/* ================= گام ۳: تست دیتابیس ================= */
function testDb(btn) {
  var errBox = el('dbErr'), resBox = el('dbResult');
  errBox.innerHTML = ''; resBox.innerHTML = '';
  var p = dbPayload();
  if (!p.db_name) { errBox.innerHTML = calloutHtml('err', 'نام دیتابیس را وارد کنید.'); return; }
  if (!p.db_user) { errBox.innerHTML = calloutHtml('err', 'نام کاربری دیتابیس را وارد کنید.'); return; }
  btn.disabled = true; btn.innerHTML = '<span class="spin"></span> در حال تست اتصال…';
  var data = p; data.create_db = el('create_db').checked ? '1' : '';
  post('testdb', data).then(function (d) {
    btn.disabled = false; btn.innerHTML = '<svg class="ic"><use href="#i-db"/></svg> تست اتصال و ادامه';
    if (!d.ok) {
      dbOk = false; dbTested = false;
      errBox.innerHTML = calloutHtml('err', d.error);
      if (d.suggest_create && !el('create_db').checked) {
        el('create_db').checked = true;
        errBox.innerHTML += calloutHtml('info', 'گزینه‌ی «ساخت خودکار دیتابیس» فعال شد؛ دوباره «تست اتصال» را بزنید.');
      }
      render(); return;
    }
    dbOk = true; dbTested = true;
    FACTS.db = [d.engine + ' ' + d.version];
    if (d.utf8mb4) FACTS.db.push('پشتیبانی کامل فارسی (utf8mb4)');
    if (d.created) FACTS.db.push({ t: 'دیتابیس «' + p.db_name.replace(/</g, '&lt;') + '» ساخته شد', gold: true });
    var created = d.created ? '<div class="callout ok" style="margin-top:10px"><svg class="ic"><use href="#i-check"/></svg><div>دیتابیس «' + p.db_name.replace(/</g, '&lt;') + '» با موفقیت ساخته شد.</div></div>' : '';
    resBox.innerHTML = '<div class="summary" style="background:var(--ok-soft);color:var(--ok);margin:0"><svg class="ic"><use href="#i-check"/></svg> اتصال برقرار شد — ' + d.engine + ' <span class="mono">' + d.version + '</span>'
      + (d.utf8mb4 ? ' · پشتیبانی کامل فارسی (utf8mb4)' : '')
      + '</div>' + created;
    render();
  }).catch(function () {
    btn.disabled = false; btn.innerHTML = '<svg class="ic"><use href="#i-db"/></svg> تست اتصال و ادامه';
    errBox.innerHTML = calloutHtml('err', 'ارتباط با سرور برقرار نشد؛ دوباره تلاش کنید.');
  });
}

/* ================= گام ۵: رمز ================= */
function togglePw() {
  var i = el('admin_pass'); i.type = i.type === 'password' ? 'text' : 'password';
}
function pwMeter() {
  var v = el('admin_pass').value, bar = el('pwBar'), lab = el('pwLabel');
  var score = 0;
  if (v.length >= 6) score++;
  if (v.length >= 10) score++;
  if (/[A-Za-z]/.test(v) && /[0-9]/.test(v)) score++;
  if (/[^A-Za-z0-9]/.test(v)) score++;
  var cfg = [
    [0, 'رمز حداقل ۶ کاراکتر', '#CEC8BA', 4],
    [1, 'ضعیف', 'var(--err)', 30],
    [2, 'متوسط', 'var(--warn)', 55],
    [3, 'خوب', '#8FBF3F', 78],
    [4, 'قوی', 'var(--ok)', 100]
  ][score];
  bar.style.width = cfg[3] + '%'; bar.style.background = cfg[2]; lab.textContent = cfg[1];
}

/* ================= گام ۶: نصب ================= */
function logLine(n, txt, cls) {
  var c = el('console');
  var ln = document.createElement('div');
  ln.className = 'ln ' + (cls || 'wait');
  ln.innerHTML = '<span class="n">' + n + '.</span><span class="dot"></span><span class="tx">' + txt + '</span>';
  c.appendChild(ln); c.scrollTop = c.scrollHeight;
  return ln;
}
function setLine(ln, cls, txt) {
  ln.classList.remove('wait', 'ok', 'err'); ln.classList.add(cls);
  if (txt !== undefined) ln.querySelector('.tx').textContent = txt;
}
var INSTALL_STEPS = [
  ['config', 'ساخت فایل پیکربندی (config.php) با کلید امن تصادفی'],
  ['db', 'اتصال به دیتابیس و بررسی نسخه'],
  ['schema', 'ساخت جداول اصلی پنل'],
  ['schema_v4', 'ساخت جداول ماژول‌های تکمیلی (سناریوساز، ویجت‌ها، آنالیتیکس)'],
  ['admin', 'ساخت حساب مدیر'],
  ['settings', 'ثبت تنظیمات پایه و کلید زمان‌بندی (Cron)']
];
function runInstall() {
  if (installing || installed) return;
  var a = adminPayload();
  if (a.admin_user.length < 3) { el('adminErr').innerHTML = calloutHtml('err', 'نام کاربری مدیر باید حداقل ۳ کاراکتر باشد.'); return; }
  if (a.admin_pass.length < 6) { el('adminErr').innerHTML = calloutHtml('err', 'رمز عبور باید حداقل ۶ کاراکتر باشد.'); return; }
  if (a.admin_pass !== el('admin_pass2').value) { el('adminErr').innerHTML = calloutHtml('err', 'تکرار رمز عبور با رمز یکسان نیست.'); return; }
  if (!el('base_url').value.trim()) { step = 4; render(); return; }
  el('adminErr').innerHTML = '';
  installing = true; render();
  el('console').innerHTML = '';
  el('doneBox').classList.add('hide'); el('installBox').classList.remove('hide');

  var i = 0, results = [];
  function nextStep() {
    if (i >= INSTALL_STEPS.length) { finish(); return; }
    var st = INSTALL_STEPS[i];
    var ln = logLine(i + 1, st[1] + '…', 'wait');
    var data = { step: st[0] };
    if (st[0] === 'config' || st[0] === 'settings') { Object.keys(sitePayload()).forEach(function (k) { data[k] = sitePayload()[k]; }); }
    if (st[0] === 'config' || st[0] === 'db') { Object.keys(dbPayload()).forEach(function (k) { data[k] = dbPayload()[k]; }); }
    if (st[0] === 'admin') { Object.keys(a).forEach(function (k) { data[k] = a[k]; }); }
    post('install', data).then(function (d) {
      if (!d.ok) {
        setLine(ln, 'err', d.error);
        installing = false;
        logLine('!', 'نصب در مرحله‌ی «' + st[1] + '» متوقف شد — پس از رفع مشکل، دوباره «شروع نصب» را بزنید (ادامه از همین‌جا و بدون خرابکاری).', 'err');
        render();
        return;
      }
      var extra = '';
      if (st[0] === 'db' && d.version) extra = ' (' + d.version + ')';
      if (st[0] === 'schema' && d.tables) extra = ' — ' + d.tables + ' جدول';
      if (st[0] === 'config' && d.preserved_key) extra = ' — کلید امن موجود حفظ شد';
      if (st[0] === 'admin' && d.skipped) extra = ' — مدیر از قبل وجود داشت، دست نخورد';
      setLine(ln, 'ok', st[1] + ' ✓' + extra);
      i++; nextStep();
    }).catch(function () {
      setLine(ln, 'err', 'ارتباط با سرور قطع شد.');
      installing = false; render();
    });
  }
  nextStep();

  function finish() {
    installing = false; installed = true;
    FACTS.inst = ['۶ از ۶ قدم کامل شد', 'کلید Cron تولید شد', 'پنل آماده است'];
    post('install', { step: 'settings' }).then(function (d) {
      var key = d.ok ? d.cron_key : '';
      var base = d.ok && d.base_url ? d.base_url.replace(/\/+$/, '') : el('base_url').value.trim().replace(/\/+$/, '');
      el('cronCmd').textContent = '* * * * * wget -q -O /dev/null "' + base + '/cron.php?key=' + key + '"';
      el('goLogin').href = 'index.php';
      el('doneSub').textContent = 'حساب مدیر «' + (a.admin_user || 'admin') + '» ساخته شد. رمز را در جای امنی یادداشت کنید.';
      el('installBox').classList.add('hide');
      el('doneBox').classList.remove('hide');
      document.querySelectorAll('.st').forEach(function (s) { s.classList.add('done'); });
      render();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }).catch(function () {
      el('cronCmd').textContent = '* * * * * wget -q -O /dev/null "' + el('base_url').value.trim().replace(/\/+$/, '') + '/cron.php"';
      el('installBox').classList.add('hide');
      el('doneBox').classList.remove('hide');
      installed = true; render();
    });
  }
}

/* ================= حذف فایل نصب ================= */
function selfdestruct(btn) {
  btn.disabled = true; btn.innerHTML = '<span class="spin"></span> در حال حذف…';
  post('selfdestruct', {}).then(function (d) {
    if (d.ok) {
      btn.outerHTML = '<span class="badge ok" style="height:32px;padding:0 14px"><svg class="ic" style="width:14px;height:14px"><use href="#i-check"/></svg> فایل نصب حذف شد</span>';
      var msg = el('lockMsg'); if (msg) msg.innerHTML = calloutHtml('ok', 'فایل install.php با موفقیت حذف شد. پنل کاملاً امن است.');
    } else {
      btn.disabled = false; btn.innerHTML = '<svg class="ic"><use href="#i-trash"/></svg> تلاش مجدد';
      var msg2 = el('lockMsg');
      if (msg2) msg2.innerHTML = calloutHtml('err', d.error);
      else alert(d.error);
    }
  }).catch(function () { btn.disabled = false; btn.innerHTML = '<svg class="ic"><use href="#i-trash"/></svg> تلاش مجدد'; });
}

/* ================= کپی cron ================= */
function copyCron(btn) {
  var box = btn.closest('.cron-box').querySelector('pre');
  var txt = box.textContent;
  var done = function () {
    var old = btn.innerHTML;
    btn.innerHTML = '<svg class="ic" style="width:14px;height:14px;color:var(--ok)"><use href="#i-check"/></svg>';
    setTimeout(function () { btn.innerHTML = old; }, 1600);
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(txt).then(done).catch(function () { fallbackCopy(txt); done(); });
  } else { fallbackCopy(txt); done(); }
}
function fallbackCopy(txt) {
  var ta = document.createElement('textarea');
  ta.value = txt; document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); } catch (e) {}
  document.body.removeChild(ta);
}

/* ================= پیش‌فرض‌ها و شروع ================= */
(function init() {
  if (S.locked) { var sd = el('sideDyn'); if (sd) sd.style.display = 'none'; return; }
  var pf = S.prefill || {};
  el('db_host').value = pf.db_host || 'localhost';
  el('db_name').value = pf.db_name || '';
  el('db_user').value = pf.db_user || '';
  el('base_url').value = (pf.base_url && pf.base_url.indexOf('example.com') === -1) ? pf.base_url.replace(/\/+$/, '') : S.detected_base_url;
  render();
  // راهنمای مرحله‌ی اول: فقط بار اول به‌صورت خودکار باز می‌شود؛ کاربر هر وقت ببندد، دیگر خودکار باز نمی‌شود
  var seen = false;
  try { seen = !!localStorage.getItem('ebHelpSeen'); } catch (e) {}
  if (!seen) setTimeout(function () { toggleHelp(true); }, 700);
  // پیش‌بارگذاری بررسی محیط در پس‌زمینه
  post('check', {}).then(function (d) { if (d && d.ok) { envOk = d.all_ok; lastCheck = d; if (step === 2) renderCheck(d); } }).catch(function () {});
})();

</script>
</body>
</html>
