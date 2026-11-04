<?php
/**
 * core/CronGuard.php — who may trigger cron.php / crm/cron_sync.php over HTTP (2.1.6, risk R-06).
 *
 * Before 2.1.6 both endpoints ran for ANY visitor when no key was configured (the installer
 * never sets one, and the CRM key had no setting UI), keys were compared with `!==`, and
 * cron.php refused CLI runs whenever a key was set ($_GET is empty under CLI).
 *
 * Rules:
 *   CLI                       → allowed (shell access is already trusted)
 *   a key is configured       → `?key=` must match one of them (hash_equals)
 *   no key configured         → only requests from this server itself (127.0.0.1, ::1 or
 *                               the server's own address — the usual hosting `wget` to its own
 *                               domain); everything else is denied
 * Pure: no DB, no I/O.
 */
final class CronGuard
{
    const OK_CLI = 'ok_cli';
    const OK_KEY = 'ok_key';
    const OK_LOCAL = 'ok_local_no_key';
    const BAD_KEY = 'bad_key';
    const NO_KEY_REMOTE = 'no_key_remote';

    /** @param array<int,?string> $expectedKeys */
    public static function check(array $expectedKeys, ?string $providedKey, array $server, bool $isCli): string
    {
        if ($isCli) return self::OK_CLI;
        $keys = array_values(array_filter(array_map(fn($k) => trim((string)$k), $expectedKeys), 'strlen'));
        if ($keys) {
            $got = trim((string)$providedKey);
            $match = false;
            foreach ($keys as $k) $match = ($got !== '' && hash_equals($k, $got)) || $match;
            return $match ? self::OK_KEY : self::BAD_KEY;
        }
        return self::isLocal($server) ? self::OK_LOCAL : self::NO_KEY_REMOTE;
    }

    public static function allowed(string $result): bool
    {
        return strpos($result, 'ok_') === 0;
    }

    /** Request from this machine? Uses REMOTE_ADDR only — never X-Forwarded-For (spoofable). */
    public static function isLocal(array $server): bool
    {
        $ip = (string)($server['REMOTE_ADDR'] ?? '');
        if ($ip === '') return false;
        if ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '127.') === 0 || $ip === '::ffff:127.0.0.1') return true;
        $own = (string)($server['SERVER_ADDR'] ?? '');
        return $own !== '' && $ip === $own;
    }
}
