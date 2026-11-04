<?php
/**
 * core/Outbox.php — transactional outbox + idempotent listeners (2.2.0, ADR-008).
 *
 *   Database::pdo()->beginTransaction();
 *   ...state change...
 *   Outbox::record('work.task.created', ['task_id' => 12], 'work.task:12');   // same transaction
 *   Database::pdo()->commit();
 *
 * Delivery is at-least-once: dispatch() runs at the end of the request that recorded events
 * (after the response is flushed where possible) and again from cron for anything left.
 * Each listener runs at most once per event (eb_outbox_consumed); a failing listener does not
 * block others, and the event is retried with backoff until MAX_ATTEMPTS, then marked dead.
 * Payloads must carry ids, never message bodies or PII.
 */
final class Outbox
{
    const MAX_ATTEMPTS = 8;
    /** @var array<string, array<string, callable>> type|'*' => [listenerName => fn(array $event)] */
    private static array $listeners = [];
    private static bool $flushRegistered = false;

    public static function listen(string $type, string $name, callable $fn): void
    {
        if (!preg_match('/^[a-z0-9_.*-]{1,100}$/', $type) || !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $name)) {
            throw new InvalidArgumentException('bad listener registration');
        }
        self::$listeners[$type][$name] = $fn;
    }

    public static function resetListeners(): void { self::$listeners = []; }

    /** @return array<string,callable> listeners for a type: exact, prefix wildcard ('work.*') and '*' */
    public static function listenersFor(string $type): array
    {
        $out = [];
        foreach (self::$listeners as $pat => $ls) {
            $match = $pat === $type || $pat === '*' || (substr($pat, -2) === '.*' && strpos($type, substr($pat, 0, -1)) === 0);
            if ($match) $out += $ls;
        }
        return $out;
    }

    public static function record(string $type, array $payload = [], ?string $subject = null): string
    {
        if (!preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+){1,4}$/', $type)) throw new InvalidArgumentException('bad event type: ' . $type);
        $id = Ulid::generate();
        Database::run("INSERT INTO eb_outbox (event_id, type, subject, payload_json, occurred_at, status, next_attempt_at)
                       VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), 'pending', UTC_TIMESTAMP())",
            [$id, $type, $subject, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        if (!self::$flushRegistered) {
            self::$flushRegistered = true;
            register_shutdown_function(function () {
                if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                try { self::dispatch(50, 5); } catch (Throwable $e) { error_log('[outbox] flush: ' . $e->getMessage()); }
            });
        }
        return $id;
    }

    /** Deliver due events. @return array{events:int, delivered:int, failed:int, dead:int} */
    public static function dispatch(int $limit = 200, int $budgetSec = 20): array
    {
        $stats = ['events' => 0, 'delivered' => 0, 'failed' => 0, 'dead' => 0];
        // Every dispatching process must know every listener, whatever its entry point (panel, webhook,
        // cron, api.php) — otherwise an event with "no listeners here" would be marked done too early.
        $boot = dirname(__DIR__) . '/src/bootstrap.php';
        if (is_file($boot)) require_once $boot;
        $until = microtime(true) + $budgetSec;
        $token = bin2hex(random_bytes(8));
        // Claim a batch by pushing next_attempt_at forward under our token (prevents two workers taking the same events)
        Database::run("UPDATE eb_outbox SET next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), claimed_by = ?
                       WHERE status = 'pending' AND next_attempt_at <= UTC_TIMESTAMP() ORDER BY id LIMIT " . max(1, $limit), [$token]);
        $rows = Database::all("SELECT * FROM eb_outbox WHERE status = 'pending' AND claimed_by = ? ORDER BY id", [$token]);
        foreach ($rows as $ev) {
            if (microtime(true) > $until) {   // give the rest back immediately
                Database::run("UPDATE eb_outbox SET next_attempt_at = UTC_TIMESTAMP(), claimed_by = NULL WHERE id = ? AND claimed_by = ?", [$ev['id'], $token]);
                continue;
            }
            $stats['events']++;
            $event = ['id' => $ev['event_id'], 'type' => $ev['type'], 'subject' => $ev['subject'], 'occurred_at' => $ev['occurred_at'],
                      'data' => json_decode((string)$ev['payload_json'], true) ?: []];
            $errors = [];
            foreach (self::listenersFor($ev['type']) as $name => $fn) {
                if (Database::one("SELECT 1 FROM eb_outbox_consumed WHERE listener = ? AND event_id = ?", [$name, $ev['event_id']])) continue;
                try {
                    $fn($event);
                    Database::run("INSERT IGNORE INTO eb_outbox_consumed (listener, event_id, consumed_at) VALUES (?, ?, UTC_TIMESTAMP())", [$name, $ev['event_id']]);
                } catch (Throwable $e) {
                    $errors[] = $name . ': ' . mb_substr($e->getMessage(), 0, 300);
                }
            }
            $attempts = (int)$ev['attempts'] + 1;
            if (!$errors) {
                Database::run("UPDATE eb_outbox SET status = 'done', attempts = ?, dispatched_at = UTC_TIMESTAMP(), last_error = NULL, claimed_by = NULL WHERE id = ?", [$attempts, $ev['id']]);
                $stats['delivered']++;
            } elseif ($attempts >= self::MAX_ATTEMPTS) {
                Database::run("UPDATE eb_outbox SET status = 'dead', attempts = ?, last_error = ?, claimed_by = NULL WHERE id = ?", [$attempts, implode(' | ', $errors), $ev['id']]);
                $stats['dead']++;
                if (class_exists('Log')) Log::error('outbox.dead', ['event_id' => $ev['event_id'], 'type' => $ev['type'], 'errors' => $errors]);
            } else {
                Database::run("UPDATE eb_outbox SET attempts = ?, last_error = ?, claimed_by = NULL, next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND) WHERE id = ?",
                    [$attempts, implode(' | ', $errors), Jobs::backoffSeconds($attempts), $ev['id']]);
                $stats['failed']++;
            }
        }
        return $stats;
    }

    public static function backlog(): array
    {
        $r = Database::one("SELECT SUM(status = 'pending') pending, SUM(status = 'dead') dead,
                                   MIN(CASE WHEN status = 'pending' THEN occurred_at END) oldest_pending FROM eb_outbox");
        return ['pending' => (int)($r['pending'] ?? 0), 'dead' => (int)($r['dead'] ?? 0), 'oldest_pending_utc' => $r['oldest_pending'] ?? null];
    }
}
