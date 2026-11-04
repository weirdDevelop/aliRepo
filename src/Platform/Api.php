<?php
namespace Eb\Platform;

/**
 * Platform Administration context — public facade (2.2.0).
 * Other contexts may call only this class (and Eb\Platform\Events\*), never Platform internals.
 */
final class Api
{
    /** Health snapshot used by /v2/platform/health and the dashboard. Admin-only data. */
    public static function health(): array
    {
        $cronLast = \Database::one("SELECT v FROM eb_settings WHERE k = 'cron.last_run'")['v'] ?? null;
        $migrations = \Migrations::status();
        return [
            'version' => \ELB_VERSION,
            'php' => PHP_VERSION,
            'db' => (string)(\Database::one("SELECT VERSION() v")['v'] ?? ''),
            'server_time_utc' => gmdate('c'),
            'cron_last_run_local' => $cronLast,
            'cron_ok' => $cronLast !== null && (time() - strtotime($cronLast)) < 15 * 60,
            'migrations' => [
                'applied' => count(array_filter($migrations, fn($m) => $m['state'] === 'applied')),
                'pending' => array_values(array_map(fn($m) => $m['name'], array_filter($migrations, fn($m) => $m['state'] === 'pending'))),
                'problems' => array_values(array_filter($migrations, fn($m) => in_array($m['state'], ['failed', 'modified'], true))),
            ],
            'outbox' => \Outbox::backlog(),
            'jobs' => \Jobs::backlog(),
        ];
    }
}
