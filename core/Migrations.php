<?php
/**
 * core/Migrations.php — versioned schema migrations (2.2.0, ADR-005, docs/database/migration-plan.md).
 *
 * Files: migrations/NNNN_name.php, each returning
 *   ['description' => string,
 *    'up'          => function (PDO $pdo): void,          // must be idempotent: MySQL DDL auto-commits
 *    'verify'      => function (PDO $pdo): array,         // list of problems; [] = OK
 *    'down'        => function (PDO $pdo): void | null,
 *    'destructive' => bool]                               // true ⇒ never automatic; needs --allow-destructive
 *
 * Applied state in eb_migrations. Runs under GET_LOCK('eb_migrate'). Stops at the first failure.
 * An applied file whose checksum changed is reported as 'modified' and is NOT re-run.
 * Entry points: cli/migrate.php, the panel's "به‌روزرسانی دیتابیس" button (db_upgrade), and cron
 * (autoApply: non-destructive only). The legacy Schema/SchemaV4 ensure() keeps running unchanged
 * in 2.2.0; retiring it from the request path is a later, measured step.
 */
final class Migrations
{
    private static ?array $applied = null;

    public static function dir(): string
    {
        return dirname(__DIR__) . '/migrations';
    }

    public static function ensureTable(): void
    {
        Database::run("CREATE TABLE IF NOT EXISTS eb_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL UNIQUE,
            checksum CHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'applied',
            applied_at DATETIME NOT NULL,
            duration_ms INT NOT NULL DEFAULT 0,
            applied_by VARCHAR(64) DEFAULT NULL,
            error TEXT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** @return array<string,string> name => path, sorted */
    public static function files(?string $dir = null): array
    {
        $out = [];
        foreach (glob(($dir ?? self::dir()) . '/[0-9][0-9][0-9][0-9]_*.php') ?: [] as $f) {
            $out[basename($f, '.php')] = $f;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    public static function load(string $path): array
    {
        $spec = require $path;
        if (!is_array($spec) || !isset($spec['up']) || !is_callable($spec['up'])) {
            throw new RuntimeException('invalid migration file: ' . basename($path));
        }
        return $spec + ['description' => '', 'verify' => null, 'down' => null, 'destructive' => false];
    }

    /** @return array<string,array> name => row */
    public static function applied(bool $refresh = false): array
    {
        if (self::$applied !== null && !$refresh) return self::$applied;
        $rows = [];
        try {
            foreach (Database::all("SELECT * FROM eb_migrations ORDER BY name") as $r) $rows[$r['name']] = $r;
        } catch (PDOException $e) {
            // table not created yet (before the first run) → nothing applied; read paths never create it
        }
        return self::$applied = $rows;
    }

    public static function isApplied(string $name): bool
    {
        try {
            $a = self::applied();
            return isset($a[$name]) && $a[$name]['status'] === 'applied';
        } catch (Throwable $e) {
            return false;
        }
    }

    /** One row per file: name, state (applied|pending|failed|modified), description */
    public static function status(?string $dir = null): array
    {
        $applied = self::applied(true);
        $out = [];
        foreach (self::files($dir) as $name => $path) {
            $a = $applied[$name] ?? null;
            $sum = hash_file('sha256', $path);
            $state = !$a ? 'pending' : ($a['status'] !== 'applied' ? 'failed' : ($a['checksum'] !== $sum ? 'modified' : 'applied'));
            $out[] = ['name' => $name, 'state' => $state, 'applied_at' => $a['applied_at'] ?? null, 'description' => self::load($path)['description']];
        }
        return $out;
    }

    /**
     * Apply pending migrations in order.
     * @return array{ok:bool, applied:string[], pending:string[], stopped_at:?string, reason:?string, log:?string}
     */
    public static function run(bool $dryRun = false, bool $allowDestructive = false, string $by = 'cli', ?string $dir = null): array
    {
        $report = ['ok' => true, 'applied' => [], 'pending' => [], 'stopped_at' => null, 'reason' => null, 'log' => null];
        $lock = Database::one("SELECT GET_LOCK('eb_migrate', 0) g");
        if ((int)($lock['g'] ?? 0) !== 1) return ['ok' => false, 'reason' => 'another migration run is in progress'] + $report;
        try {
            self::ensureTable();
            $applied = self::applied(true);
            foreach (self::files($dir) as $name => $path) {
                if (isset($applied[$name]) && $applied[$name]['status'] === 'applied') continue;
                $spec = self::load($path);
                $report['pending'][] = $name;
                if ($spec['destructive'] && !$allowDestructive) {
                    $report = array_merge($report, ['ok' => false, 'stopped_at' => $name, 'reason' => 'destructive migration needs explicit approval (--allow-destructive) and a fresh backup']);
                    break;
                }
                if ($dryRun) continue;
                $t0 = microtime(true);
                $error = null;
                try {
                    ($spec['up'])(Database::pdo());
                    $problems = is_callable($spec['verify']) ? (array)($spec['verify'])(Database::pdo()) : [];
                    if ($problems) $error = 'verify failed: ' . implode('; ', array_slice($problems, 0, 20));
                } catch (Throwable $e) {
                    $error = get_class($e) . ': ' . $e->getMessage();
                }
                $ms = (int)round((microtime(true) - $t0) * 1000);
                Database::run("INSERT INTO eb_migrations (name, checksum, status, applied_at, duration_ms, applied_by, error)
                               VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?)
                               ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), status = VALUES(status), applied_at = VALUES(applied_at),
                                                       duration_ms = VALUES(duration_ms), applied_by = VALUES(applied_by), error = VALUES(error)",
                    [$name, hash_file('sha256', $path), $error ? 'failed' : 'applied', $ms, mb_substr($by, 0, 64), $error]);
                if ($error) {
                    $report = array_merge($report, ['ok' => false, 'stopped_at' => $name, 'reason' => $error]);
                    break;
                }
                $report['applied'][] = $name;
            }
        } finally {
            Database::one("SELECT RELEASE_LOCK('eb_migrate') r");
            self::$applied = null;
        }
        if (!$dryRun && ($report['applied'] || $report['stopped_at'])) $report['log'] = self::writeEvidence($report, $by);
        return $report;
    }

    /** Cron/db_upgrade: apply non-destructive pending migrations; never throws. */
    public static function autoApply(string $by = 'cron'): array
    {
        try {
            $pending = false;
            $applied = self::applied(true);
            foreach (self::files() as $name => $_) {
                if (!isset($applied[$name]) || $applied[$name]['status'] !== 'applied') { $pending = true; break; }
            }
            if (!$pending) return ['ok' => true, 'applied' => [], 'pending' => []];
            return self::run(false, false, $by);
        } catch (Throwable $e) {
            error_log('[elanban migrate] autoApply: ' . $e->getMessage());
            return ['ok' => false, 'reason' => $e->getMessage(), 'applied' => [], 'pending' => []];
        }
    }

    private static function writeEvidence(array $report, string $by): ?string
    {
        $dir = dirname(__DIR__) . '/logs/migrations';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (!is_dir($dir) || !is_writable($dir)) return null;
        $f = $dir . '/' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.json';
        @file_put_contents($f, json_encode($report + ['by' => $by, 'at_utc' => gmdate('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return basename($f);
    }
}
