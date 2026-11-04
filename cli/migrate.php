<?php
/**
 * cli/migrate.php — run versioned migrations from the command line (2.2.0).
 *   php cli/migrate.php status
 *   php cli/migrate.php dry-run
 *   php cli/migrate.php run [--allow-destructive]
 * The panel button "به‌روزرسانی دیتابیس" and cron apply non-destructive migrations automatically.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$root = dirname(__DIR__);
$config = require $root . '/config.php';
require_once $root . '/core/Database.php';
require_once $root . '/core/Autoload.php';
Database::init($config['db']);

$cmd = $argv[1] ?? 'status';
$allowDestructive = in_array('--allow-destructive', $argv, true);
switch ($cmd) {
    case 'status':
        foreach (Migrations::status() as $r) printf("%-10s %-32s %s\n", $r['state'], $r['name'], $r['description']);
        exit(0);
    case 'dry-run':
    case 'run':
        $r = Migrations::run($cmd === 'dry-run', $allowDestructive, 'cli');
        echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
        exit($r['ok'] ? 0 : 1);
    default:
        fwrite(STDERR, "usage: php cli/migrate.php status|dry-run|run [--allow-destructive]\n");
        exit(2);
}
