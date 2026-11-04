<?php
namespace Eb\Platform;

/** REST v2 handlers for the Platform context (2.2.0). Each returns [status, body]. */
final class Http
{
    public static function ping(array $req, array $p): array
    {
        return \Rest::envelope(200, [
            'pong' => true,
            'user' => ['id' => (int)\Auth::id(), 'role' => \Auth::role(), 'is_admin' => \Auth::isRealAdmin()],
            'version' => \ELB_VERSION,
            'server_time_utc' => gmdate('c'),
        ]);
    }

    public static function health(array $req, array $p): array
    {
        return \Rest::envelope(200, Api::health());
    }

    public static function jobs(array $req, array $p): array
    {
        $status = (string)($req['query']['status'] ?? 'dead');
        if (!in_array($status, ['pending', 'running', 'done', 'dead'], true)) {
            throw new \RestError(400, 'validation_error', 'status نامعتبر است', 'status');
        }
        $limit = max(1, min(100, (int)($req['query']['limit'] ?? 50)));
        $after = (int)($req['query']['after'] ?? 0);
        $rows = \Database::all("SELECT id, type, status, attempts, max_attempts, run_at, last_error, created_at, finished_at
                                FROM eb_jobs WHERE status = ? AND id > ? ORDER BY id LIMIT " . ($limit + 1), [$status, $after]);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return \Rest::envelope(200, $rows, ['next_cursor' => $rows ? (string)end($rows)['id'] : null, 'has_more' => $more]);
    }

    public static function retryJob(array $req, array $p): array
    {
        $id = (int)($p['id'] ?? 0);
        $job = \Database::one("SELECT id, type, status FROM eb_jobs WHERE id = ?", [$id]);
        if (!$job) throw new \RestError(404, 'not_found', 'کار پیدا نشد');
        if ($job['status'] !== 'dead') throw new \RestError(409, 'conflict', 'فقط کار «مرده» دوباره اجرا می‌شود', null, ['status' => $job['status']]);
        $pdo = \Database::pdo();
        $pdo->beginTransaction();
        try {
            \Jobs::retry($id);
            \Outbox::record('platform.job.retried', ['job_id' => $id, 'type' => $job['type'], 'by_user_id' => (int)\Auth::id()], 'job:' . $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return \Rest::envelope(200, ['id' => $id, 'status' => 'pending']);
    }
}
