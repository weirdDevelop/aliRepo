<?php
/**
 * core/Jobs.php — generic database job queue processed by cron (2.2.0, ADR-008).
 *
 *   Jobs::handle('work.sla_check', fn(array $payload, array $job) => ...);   // register (bootstrap)
 *   Jobs::enqueue('work.sla_check', ['task_id' => 5], ['run_at_utc' => '…', 'unique_key' => 'sla:5']);
 *
 * Claiming: UPDATE … SET locked_by = token WHERE due AND (unlocked OR lock expired) ORDER BY priority, run_at LIMIT n
 * — atomic in MySQL, so two cron runs never take the same job. A worker that dies leaves locked_until
 * in the past after LOCK_SECONDS and the job is picked up again (attempt counted).
 * Failure ⇒ retry with exponential backoff + jitter; after max_attempts ⇒ 'dead' (visible, manually retriable).
 * The existing send queue (eb_queue / modules/Queue.php) is unchanged.
 */
final class Jobs
{
    const LOCK_SECONDS = 300;
    /** @var array<string, callable> */
    private static array $handlers = [];

    public static function handle(string $type, callable $fn): void
    {
        if (!preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+){1,4}$/', $type)) throw new InvalidArgumentException('bad job type');
        self::$handlers[$type] = $fn;
    }

    public static function resetHandlers(): void { self::$handlers = []; }

    /** Exponential backoff with ±20 % jitter: 30 s, 60 s, 120 s … capped at 1 h. */
    public static function backoffSeconds(int $attempt, ?float $rand = null): int
    {
        $base = min(3600, 30 * (2 ** max(0, $attempt - 1)));
        $j = $rand ?? (mt_rand() / mt_getrandmax());
        return max(1, (int)round($base * (0.8 + 0.4 * $j)));
    }

    /**
     * @param array{run_at_utc?:string, delay_sec?:int, priority?:int, max_attempts?:int, unique_key?:string} $opt
     * @return int|null job id, or null when unique_key already exists (deduplicated)
     */
    public static function enqueue(string $type, array $payload = [], array $opt = []): ?int
    {
        if (!preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+){1,4}$/', $type)) throw new InvalidArgumentException('bad job type');
        $runAt = $opt['run_at_utc'] ?? gmdate('Y-m-d H:i:s', time() + max(0, (int)($opt['delay_sec'] ?? 0)));
        try {
            Database::run("INSERT INTO eb_jobs (type, payload_json, status, priority, run_at, max_attempts, unique_key, created_at)
                           VALUES (?, ?, 'pending', ?, ?, ?, ?, UTC_TIMESTAMP())",
                [$type, json_encode($payload, JSON_UNESCAPED_UNICODE), (int)($opt['priority'] ?? 5), $runAt,
                 max(1, (int)($opt['max_attempts'] ?? 5)), $opt['unique_key'] ?? null]);
            return Database::lastId();
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000' && !empty($opt['unique_key'])) return null;
            throw $e;
        }
    }

    /** Process due jobs until the budget or limit is reached. */
    public static function work(int $budgetSec = 20, int $limit = 100): array
    {
        $stats = ['done' => 0, 'retry' => 0, 'dead' => 0, 'no_handler' => 0];
        $until = microtime(true) + $budgetSec;
        $token = bin2hex(random_bytes(16));
        for ($n = 0; $n < $limit && microtime(true) < $until; $n++) {
            $claimed = Database::run("UPDATE eb_jobs SET locked_by = ?, locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL " . self::LOCK_SECONDS . " SECOND),
                                             status = 'running', attempts = attempts + 1
                                      WHERE status IN ('pending', 'running') AND run_at <= UTC_TIMESTAMP()
                                        AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP())
                                      ORDER BY priority, run_at, id LIMIT 1", [$token])->rowCount();
            if (!$claimed) break;
            $job = Database::one("SELECT * FROM eb_jobs WHERE locked_by = ? AND status = 'running' ORDER BY locked_until DESC LIMIT 1", [$token]);
            if (!$job) break;
            $fn = self::$handlers[$job['type']] ?? null;
            $err = null;
            if (!$fn) {
                $err = 'no handler registered for ' . $job['type'];
                $stats['no_handler']++;
            } else {
                try { $fn(json_decode((string)$job['payload_json'], true) ?: [], $job); }
                catch (Throwable $e) { $err = get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 500); }
            }
            if ($err === null) {
                Database::run("UPDATE eb_jobs SET status = 'done', locked_by = NULL, locked_until = NULL, last_error = NULL, finished_at = UTC_TIMESTAMP() WHERE id = ?", [$job['id']]);
                $stats['done']++;
            } elseif ((int)$job['attempts'] >= (int)$job['max_attempts']) {
                Database::run("UPDATE eb_jobs SET status = 'dead', locked_by = NULL, locked_until = NULL, last_error = ?, finished_at = UTC_TIMESTAMP() WHERE id = ?", [$err, $job['id']]);
                $stats['dead']++;
                if (class_exists('Log')) Log::error('job.dead', ['job_id' => (int)$job['id'], 'type' => $job['type'], 'error' => $err]);
            } else {
                Database::run("UPDATE eb_jobs SET status = 'pending', locked_by = NULL, locked_until = NULL, last_error = ?,
                                      run_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND) WHERE id = ?",
                    [$err, self::backoffSeconds((int)$job['attempts']), $job['id']]);
                $stats['retry']++;
            }
        }
        return $stats;
    }

    /** Manual replay of a dead job (caller must check permission and audit). */
    public static function retry(int $id): bool
    {
        return Database::run("UPDATE eb_jobs SET status = 'pending', attempts = 0, run_at = UTC_TIMESTAMP(), last_error = NULL, finished_at = NULL
                              WHERE id = ? AND status = 'dead'", [$id])->rowCount() === 1;
    }

    public static function backlog(): array
    {
        $r = Database::one("SELECT SUM(status IN ('pending','running')) open, SUM(status = 'dead') dead,
                                   MIN(CASE WHEN status = 'pending' THEN run_at END) oldest_due FROM eb_jobs");
        return ['open' => (int)($r['open'] ?? 0), 'dead' => (int)($r['dead'] ?? 0), 'oldest_due_utc' => $r['oldest_due'] ?? null];
    }
}
