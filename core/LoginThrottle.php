<?php
/**
 * core/LoginThrottle.php — brute-force protection for the panel login (2.1.6, risk R-13 / P-05).
 *
 * Before 2.1.6 the login form accepted unlimited password guesses.
 * Limits (15-minute window, 15-minute lockout):
 *   - 5 failures for the same username from the same IP → that pair is locked
 *   - 30 failures from one IP across any usernames     → that IP is locked
 * Deliberately NOT a per-username lock on its own, so an attacker cannot lock a real admin out
 * from elsewhere. While locked, the password is not checked at all (no oracle).
 * Storage: core/Cache.php (file cache) — no schema change. Swappable store for tests.
 */
final class LoginThrottle
{
    const WINDOW = 900;
    const LOCK = 900;
    const MAX_PAIR = 5;
    const MAX_IP = 30;

    /** @var callable(string):mixed */ private static $get;
    /** @var callable(string,mixed,int):void */ private static $set;
    /** @var callable():int */ private static $now;

    public static function useStore(callable $get, callable $set, ?callable $now = null): void
    {
        self::$get = $get; self::$set = $set; self::$now = $now;
    }

    private static function boot(): void
    {
        if (!self::$get) {
            self::$get = fn(string $k) => Cache::get($k);
            self::$set = function (string $k, $v, int $ttl) { Cache::set($k, $v, $ttl); };
        }
    }
    private static function now(): int { return self::$now ? (int)(self::$now)() : time(); }
    private static function pairKey(string $u, string $ip): string { return 'lt_p_' . sha1(mb_strtolower(trim($u)) . '|' . $ip); }
    private static function ipKey(string $ip): string { return 'lt_i_' . sha1($ip); }

    private static function read(string $k): array
    {
        $v = (self::$get)($k);
        $v = is_array($v) ? $v : ['n' => 0, 'first' => 0, 'until' => 0];
        if (self::now() - (int)$v['first'] > self::WINDOW && (int)$v['until'] <= self::now()) $v = ['n' => 0, 'first' => 0, 'until' => 0];
        return $v;
    }

    /** Seconds the caller must still wait (0 = may try). */
    public static function wait(string $username, string $ip): int
    {
        self::boot();
        $now = self::now();
        $u = max((int)self::read(self::pairKey($username, $ip))['until'], (int)self::read(self::ipKey($ip))['until']);
        return max(0, $u - $now);
    }

    public static function failed(string $username, string $ip): void
    {
        self::boot();
        foreach ([[self::pairKey($username, $ip), self::MAX_PAIR], [self::ipKey($ip), self::MAX_IP]] as [$k, $max]) {
            $v = self::read($k);
            if (!$v['n']) $v['first'] = self::now();
            $v['n']++;
            if ($v['n'] >= $max) $v['until'] = self::now() + self::LOCK;
            (self::$set)($k, $v, self::WINDOW + self::LOCK);
        }
    }

    public static function succeeded(string $username, string $ip): void
    {
        self::boot();
        (self::$set)(self::pairKey($username, $ip), ['n' => 0, 'first' => 0, 'until' => 0], 1);
    }
}
