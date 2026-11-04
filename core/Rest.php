<?php
/**
 * core/Rest.php — REST v2 kernel (2.2.0, ADR-009, docs/api/api-overview.md, docs/api/error-contract.md).
 *
 * Routes are declared by contexts in src/<Context>/routes.php:
 *   return [ ['GET', '/v2/platform/ping', [Eb\Platform\Http::class, 'ping'], 'any'], ... ];
 * Access ('perm'): 'any' = any logged-in user · 'admin' = real administrator · '<page>' = Auth::canAccess(page).
 * (2.3.0 replaces these with permission keys, ADR-006.)
 *
 * Gate order: session → route match → read-only viewer may only GET → CSRF (X-CSRF-Token) on writes →
 * access check → Idempotency-Key (POST) → handler → envelope. Unexpected exceptions become a generic
 * 500 `internal_error` with request_id; details go to logs/app-*.log only.
 */
final class Rest
{
    const MSG = [
        'unauthenticated' => 'نشست شما تمام شده؛ دوباره وارد شوید',
        'forbidden' => 'دسترسی به این بخش برای حساب شما فعال نیست',
        'not_found' => 'پیدا نشد',
        'csrf' => 'توکن امنیتی نامعتبر است',
        'read_only' => 'این حساب نمایشی است؛ تغییر غیرفعال است',
        'internal_error' => 'خطای داخلی؛ شناسه‌ی درخواست را برای پشتیبانی بفرستید',
        'idempotency_mismatch' => 'این Idempotency-Key قبلاً با بدنه‌ی دیگری استفاده شده است',
        'in_progress' => 'درخواستی با همین Idempotency-Key در حال اجراست',
    ];

    /** @var array<int, array{0:string,1:string,2:callable,3:string}> */
    private static array $routes = [];

    public static function add(string $method, string $pattern, callable $handler, string $perm): void
    {
        self::$routes[] = [strtoupper($method), $pattern, $handler, $perm];
    }

    public static function loadRoutes(string $srcDir): void
    {
        foreach (glob($srcDir . '/*/routes.php') ?: [] as $f) {
            foreach ((array)require $f as $r) self::add($r[0], $r[1], $r[2], $r[3]);
        }
    }

    /** @return array{0:?array, 1:array<string,string>, 2:bool} [route, params, pathExists] */
    public static function match(string $method, string $path): array
    {
        $pathExists = false;
        foreach (self::$routes as $r) {
            $re = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[A-Za-z0-9_-]+)', $r[1]) . '$#';
            if (!preg_match($re, $path, $m)) continue;
            $pathExists = true;
            if ($r[0] !== $method) continue;
            return [$r, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY), true];
        }
        return [null, [], $pathExists];
    }

    public static function envelope(int $status, $data, array $extra = []): array
    {
        return [$status, ['ok' => $status < 400, 'data' => $data] + $extra + ['request_id' => Log::requestId()]];
    }

    public static function errorBody(RestError $e): array
    {
        $err = ['code' => $e->errCode, 'message' => $e->getMessage()];
        if ($e->field !== null) $err['field'] = $e->field;
        if ($e->details) $err['details'] = $e->details;
        return ['ok' => false, 'error' => $err, 'request_id' => Log::requestId()];
    }

    /**
     * Handle one request. Pure with respect to I/O: input array in, [status, body, headers] out.
     * @param array{method:string, path:string, query:array, body:?array, raw:string, headers:array} $req
     */
    public static function handle(array $req): array
    {
        $headers = ['X-Request-Id' => Log::requestId()];
        try {
            if (!Auth::check()) throw new RestError(401, 'unauthenticated', self::MSG['unauthenticated']);
            [$route, $params, $exists] = self::match($req['method'], $req['path']);
            if (!$route) {
                if ($exists) throw new RestError(405, 'method_not_allowed', 'method not allowed');
                throw new RestError(404, 'not_found', self::MSG['not_found'], null, ['path' => $req['path']]);
            }
            $write = $req['method'] !== 'GET';
            if ($write && Auth::isViewer()) throw new RestError(403, 'forbidden', self::MSG['read_only']);
            if ($write && !Csrf::verify($req['headers']['x-csrf-token'] ?? null)) throw new RestError(403, 'csrf', self::MSG['csrf']);
            $perm = $route[3];
            $allowed = $perm === 'any' ? true : ($perm === 'admin' ? Auth::isRealAdmin() : Auth::canAccess($perm));
            if (!$allowed) throw new RestError(403, 'forbidden', self::MSG['forbidden'], null, ['permission' => $perm]);

            $idemKey = $req['headers']['idempotency-key'] ?? null;
            if ($req['method'] === 'POST' && $idemKey !== null && $idemKey !== '') {
                return self::withIdempotency($req, (string)$idemKey, $headers, fn() => ($route[2])($req, $params));
            }
            [$status, $body] = ($route[2])($req, $params);
            return [$status, $body, $headers];
        } catch (RestError $e) {
            return [$e->status, self::errorBody($e), $headers];
        } catch (Throwable $e) {
            Log::error('rest.unhandled', ['path' => $req['path'], 'exception' => get_class($e), 'error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
            return [500, self::errorBody(new RestError(500, 'internal_error', self::MSG['internal_error'])), $headers];
        }
    }

    private static function withIdempotency(array $req, string $key, array $headers, callable $run): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $key)) throw new RestError(400, 'validation_error', 'Idempotency-Key نامعتبر است', 'Idempotency-Key');
        $actor = 'u' . (int)Auth::id();
        $hash = hash('sha256', $req['method'] . ' ' . $req['path'] . "\n" . $req['raw']);
        Database::run("DELETE FROM eb_idempotency WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) LIMIT 50");
        $existing = Database::one("SELECT * FROM eb_idempotency WHERE actor = ? AND idem_key = ?", [$actor, $key]);
        if ($existing) {
            if (!hash_equals($existing['body_sha256'], $hash)) throw new RestError(422, 'idempotency_mismatch', self::MSG['idempotency_mismatch']);
            if ((int)$existing['status_code'] === 0) throw new RestError(409, 'conflict', self::MSG['in_progress']);
            return [(int)$existing['status_code'], json_decode($existing['response'], true), $headers + ['Idempotency-Replayed' => 'true']];
        }
        try {
            Database::run("INSERT INTO eb_idempotency (idem_key, actor, method, path, body_sha256, status_code, response, created_at)
                           VALUES (?, ?, ?, ?, ?, 0, '', UTC_TIMESTAMP())", [$key, $actor, $req['method'], mb_substr($req['path'], 0, 190), $hash]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') throw new RestError(409, 'conflict', self::MSG['in_progress']);
            throw $e;
        }
        try {
            [$status, $body] = $run();
        } catch (Throwable $e) {
            Database::run("DELETE FROM eb_idempotency WHERE actor = ? AND idem_key = ?", [$actor, $key]);   // allow a retry
            throw $e;
        }
        if ($status >= 500) {
            Database::run("DELETE FROM eb_idempotency WHERE actor = ? AND idem_key = ?", [$actor, $key]);
        } else {
            Database::run("UPDATE eb_idempotency SET status_code = ?, response = ? WHERE actor = ? AND idem_key = ?",
                [$status, json_encode($body, JSON_UNESCAPED_UNICODE), $actor, $key]);
        }
        return [$status, $body, $headers];
    }
}
