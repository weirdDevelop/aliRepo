<?php
/**
 * core/Database.php
 * اتصال PDO و کمک‌متدهای کوئری امن (prepared statements).
 */

require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/Autoload.php';   // ماژول‌ها هنگام نیاز (در وب‌هوک‌ها و کران هم)

class Database
{
    private static ?PDO $pdo = null;
    private static bool $profOn = false;

    public static function init(array $cfg): void
    {
        // پورت اختیاری (اگر ویزارد نصب یا کاربر در config مقدار port گذاشته باشد)
        $port = (isset($cfg['port']) && (string)$cfg['port'] !== '') ? ';port=' . preg_replace('/[^0-9]/', '', (string)$cfg['port']) : '';
        $dsn = "mysql:host={$cfg['host']}{$port};dbname={$cfg['name']};charset={$cfg['charset']}";
        self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // پروفایل کوئری‌ها فقط در محیط توسعه (EB_SQL_PROFILE=مسیر فایل لاگ)
        if ($f = getenv('EB_SQL_PROFILE')) {
            self::$profOn = true;
            register_shutdown_function(function () use ($f) {
                $tot = array_sum(array_column(self::$prof, 0));
                $uri = ($_GET['api'] ?? '') ?: ($_GET['page'] ?? ($_SERVER['SCRIPT_NAME'] ?? '-'));
                $lines = [sprintf("== %s  q=%d  db=%.1fms  wall=%.1fms", $uri, count(self::$prof), $tot, (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000)];
                foreach (self::$prof as $p) $lines[] = sprintf("   %6.1f  %s", $p[0], $p[1]);
                @file_put_contents($f, implode("\n", $lines) . "\n", FILE_APPEND);
            });
        }
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('Database not initialized');
        }
        return self::$pdo;
    }

    /** اجرای کوئری با پارامتر و بازگشت statement */
    public static array $prof = [];
    /** در نشست نمایشی true می‌شود (Auth::start): کوئری‌های نوشتنی بی‌صدا اجرا نمی‌شوند */
    public static bool $readOnly = false;
    public static function run(string $sql, array $params = []): PDOStatement
    {
        if (self::$readOnly && preg_match('/^\s*\(?\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|RENAME|GRANT|REVOKE|LOCK|SET\s+GLOBAL)\b/i', $sql)) {
            $stmt = self::pdo()->prepare('SELECT 1 FROM DUAL WHERE 0');
            $stmt->execute();
            return $stmt;
        }
        $t0 = self::$profOn ? microtime(true) : 0;
        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($params);
            if ($t0) self::$prof[] = [round((microtime(true) - $t0) * 1000, 1), preg_replace('/\s+/', ' ', substr($sql, 0, 160))];
        } catch (PDOException $e) {
            if (getenv('EB_SQL_DEBUG')) error_log("[sql] " . $e->getMessage() . "\n" . $sql);
            throw $e;
        }
        return $stmt;
    }

    /** یک ردیف */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** همه‌ی ردیف‌ها */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @var array<string,array<string,bool>> کش ستون‌های هر جدول در همین درخواست */
    private static array $colCache = [];
    /** @var array<string,bool> */
    private static array $tableCache = [];

    /**
     * فهرست ستون‌های یک جدول — یک‌بار در هر درخواست (به‌جای ده‌ها SHOW COLUMNS LIKE جدا).
     * کش فایلی ۱۰ دقیقه‌ای هم دارد تا هر درخواست API حتی همین یک کوئری را هم نزند.
     */
    public static function columns(string $table): array
    {
        if (isset(self::$colCache[$table])) return self::$colCache[$table];
        $cols = class_exists('Cache') ? Cache::get('cols_' . $table) : null;
        if (!is_array($cols)) {
            $cols = [];
            try {
                foreach (self::all("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`") as $r) $cols[$r['Field']] = true;
            } catch (Throwable $e) { $cols = []; }
            if ($cols && class_exists('Cache')) Cache::set('cols_' . $table, $cols, 600);
        }
        return self::$colCache[$table] = $cols;
    }

    public static function hasColumn(string $table, string $col): bool
    {
        return isset(self::columns($table)[$col]);
    }

    public static function hasTable(string $table): bool
    {
        if (isset(self::$tableCache[$table])) return self::$tableCache[$table];
        return self::$tableCache[$table] = (bool)self::columns($table);
    }

    /** بعد از migration (db_upgrade) کش ساختار پاک شود */
    public static function forgetSchemaCache(): void
    {
        self::$colCache = []; self::$tableCache = [];
        if (class_exists('Cache')) Cache::flush('cols_');
    }

    /** آخرین id درج‌شده */
    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }
}
