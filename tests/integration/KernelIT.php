<?php
/** 2.2.0 kernel end-to-end on a real MariaDB: migrations, outbox, jobs. */

function kit_reset_kernel(Sandbox $sb): void {
    $sb->php("Migrations::run(false, false, 'it'); return true;");
    $sb->pdo()->exec("DELETE FROM eb_outbox; DELETE FROM eb_outbox_consumed; DELETE FROM eb_jobs;");
    $sb->pdo()->exec("CREATE TABLE IF NOT EXISTS it_calls (k VARCHAR(100) NOT NULL, n INT NOT NULL DEFAULT 1, PRIMARY KEY (k))");
    $sb->pdo()->exec("DELETE FROM it_calls");
}
function kit_calls(Sandbox $sb): array {
    return $sb->pdo()->query("SELECT k, n FROM it_calls ORDER BY k")->fetchAll(PDO::FETCH_KEY_PAIR);
}

it('migrations: fresh database → 0001/0002 pending, run applies both, second run is a no-op', function (Sandbox $sb) {
    $pdo = $sb->pdo();
    $pdo->exec("DROP TABLE IF EXISTS eb_migrations, eb_outbox, eb_outbox_consumed, eb_jobs, eb_idempotency");
    exec('cd ' . escapeshellarg($sb->dir) . ' && php cli/migrate.php status', $st);
    ok(strpos(implode("\n", $st), 'pending    0001_baseline') !== false && strpos(implode("\n", $st), 'pending    0002_kernel') !== false, implode("\n", $st));
    exec('cd ' . escapeshellarg($sb->dir) . ' && php cli/migrate.php run', $o, $rc);
    eq(0, $rc, implode("\n", $o));
    $r = json_decode(implode("\n", $o), true);
    eq(['0001_baseline', '0002_kernel'], $r['applied']);
    foreach (['eb_outbox', 'eb_outbox_consumed', 'eb_jobs', 'eb_idempotency'] as $t) ok((bool)$pdo->query("SHOW TABLES LIKE '$t'")->fetchColumn(), $t);
    $o = []; exec('cd ' . escapeshellarg($sb->dir) . ' && php cli/migrate.php run', $o, $rc);
    eq([], json_decode(implode("\n", $o), true)['applied']);
    ok(is_file($sb->dir . '/logs/migrations/' . $r['log']), 'evidence file written');
});
it('migrations: failing verify stops the run and records the failure; destructive needs approval', function (Sandbox $sb) {
    $dir = $sb->dir . '/__mig_it'; @mkdir($dir);
    file_put_contents("$dir/9001_ok.php", "<?php return ['up'=>function(\$p){ \$p->exec('CREATE TABLE IF NOT EXISTS it_m1 (id INT)'); }, 'verify'=>function(\$p){ return []; }, 'destructive'=>false];");
    file_put_contents("$dir/9002_bad.php", "<?php return ['up'=>function(\$p){}, 'verify'=>function(\$p){ return ['table it_missing absent']; }, 'destructive'=>false];");
    file_put_contents("$dir/9003_never.php", "<?php return ['up'=>function(\$p){ \$p->exec('CREATE TABLE it_m3 (id INT)'); }, 'verify'=>function(\$p){ return []; }, 'destructive'=>false];");
    $r = $sb->php("return Migrations::run(false, false, 'it', " . var_export($dir, true) . ");");
    eq(false, $r['ok']); eq(['9001_ok'], $r['applied']); eq('9002_bad', $r['stopped_at']);
    ok(strpos($r['reason'], 'it_missing') !== false);
    eq('failed', $sb->pdo()->query("SELECT status FROM eb_migrations WHERE name = '9002_bad'")->fetchColumn());
    ok(!$sb->pdo()->query("SHOW TABLES LIKE 'it_m3'")->fetchColumn(), 'must stop at first failure');
    $dir2 = $sb->dir . '/__mig_it2'; @mkdir($dir2);
    file_put_contents("$dir2/9101_drop.php", "<?php return ['up'=>function(\$p){ \$p->exec('DROP TABLE IF EXISTS it_m1'); }, 'verify'=>function(\$p){ return []; }, 'destructive'=>true];");
    $r = $sb->php("return Migrations::run(false, false, 'it', " . var_export($dir2, true) . ");");
    eq(false, $r['ok']); ok(strpos($r['reason'], 'destructive') !== false, $r['reason']);
    ok((bool)$sb->pdo()->query("SHOW TABLES LIKE 'it_m1'")->fetchColumn(), 'destructive migration ran without approval');
    $sb->pdo()->exec("DELETE FROM eb_migrations WHERE name LIKE '9%'; DROP TABLE IF EXISTS it_m1");
});
it('migrations: the panel "update database" button applies migrations', function (Sandbox $sb) {
    $sb->pdo()->exec("DELETE FROM eb_migrations WHERE name = '0002_kernel'");
    $sb->user('admin1', 'admin'); $b = new Browser($sb->url); ok($b->login('admin1', 'pw-admin1'));
    $r = $b->api('db_upgrade');
    eq(true, $r['json']['ok'] ?? null, substr($r['raw'], 0, 200));
    eq(['0002_kernel'], $r['json']['migrations']['applied'] ?? null);
});

it('outbox: event recorded inside a rolled-back transaction does not exist', function (Sandbox $sb) {
    kit_reset_kernel($sb);
    $n = $sb->php("\$p = Database::pdo(); \$p->beginTransaction(); Outbox::record('it.thing.happened', ['x' => 1]); \$p->rollBack();
                   return (int)Database::one('SELECT COUNT(*) c FROM eb_outbox')['c'];");
    eq(0, $n);
});
it('outbox: failing listener retried without re-running the successful one; then delivered', function (Sandbox $sb) {
    kit_reset_kernel($sb);
    $listeners = "
        Outbox::listen('it.*', 'it.ok', function (\$e) { Database::run('INSERT INTO it_calls (k) VALUES (?) ON DUPLICATE KEY UPDATE n = n + 1', ['ok:' . \$e['id']]); });
        Outbox::listen('it.thing.happened', 'it.flaky', function (\$e) {
            Database::run('INSERT INTO it_calls (k) VALUES (?) ON DUPLICATE KEY UPDATE n = n + 1', ['flaky:' . \$e['id']]);
            if ((int)Database::one('SELECT n FROM it_calls WHERE k = ?', ['flaky:' . \$e['id']])['n'] < 2) throw new RuntimeException('transient');
        });";
    // record: the end-of-request flush is delivery attempt 1 (flaky fails, ok succeeds)
    $id = $sb->php($listeners . " Outbox::record('it.thing.happened', ['x' => 1]); return Database::one('SELECT event_id FROM eb_outbox')['event_id'];");
    $row = $sb->pdo()->query("SELECT status, attempts, last_error FROM eb_outbox")->fetch();
    eq('pending', $row['status']); eq(1, (int)$row['attempts']); ok(strpos((string)$row['last_error'], 'transient') !== false);
    eq(['flaky:' . $id => 1, 'ok:' . $id => 1], kit_calls($sb));
    $sb->pdo()->exec("UPDATE eb_outbox SET next_attempt_at = UTC_TIMESTAMP()");
    $r2 = $sb->php($listeners . " return Outbox::dispatch();");
    eq(1, $r2['delivered']);
    eq(['flaky:' . $id => 2, 'ok:' . $id => 1], kit_calls($sb), 'ok-listener must run exactly once');
    $sb->pdo()->exec("UPDATE eb_outbox SET status = 'pending', next_attempt_at = UTC_TIMESTAMP()");
    $sb->php($listeners . " return Outbox::dispatch();");
    eq(['flaky:' . $id => 2, 'ok:' . $id => 1], kit_calls($sb), 'consumed listeners must not run again');
});
it('outbox: an event recorded from an entry point without src/bootstrap still reaches bootstrap listeners', function (Sandbox $sb) {
    kit_reset_kernel($sb);
    $sb->pdo()->exec("DELETE FROM eb_audit WHERE action = 'platform.job.retried'");
    // simulate panel/webhook process: no bootstrap loaded before recording
    $f = $sb->dir . '/__it_nobootstrap.php';
    file_put_contents($f, "<?php chdir(" . var_export($sb->dir, true) . "); \$config = require 'config.php'; require 'core/Database.php'; require 'core/Autoload.php'; Database::init(\$config['db']); Outbox::record('platform.job.retried', ['job_id' => 1, 'type' => 't', 'by_user_id' => null]);");
    shell_exec('php ' . escapeshellarg($f)); @unlink($f);
    eq('done', $sb->pdo()->query("SELECT status FROM eb_outbox")->fetchColumn());
    ok((bool)$sb->pdo()->query("SELECT 1 FROM eb_audit WHERE action = 'platform.job.retried'")->fetchColumn(), 'platform.audit listener did not run');
});
it('outbox: permanently failing listener → dead after MAX_ATTEMPTS', function (Sandbox $sb) {
    kit_reset_kernel($sb);
    $max = (int)$sb->php("return Outbox::MAX_ATTEMPTS;");
    $l = "Outbox::listen('it.thing.broken', 'it.always_fails', function () { throw new RuntimeException('nope'); });";
    $sb->php($l . " Outbox::record('it.thing.broken', []); return 1;");
    for ($i = 1; $i < $max; $i++) { $sb->pdo()->exec("UPDATE eb_outbox SET next_attempt_at = UTC_TIMESTAMP()"); $sb->php($l . " return Outbox::dispatch();"); }
    $row = $sb->pdo()->query("SELECT status, attempts, last_error FROM eb_outbox")->fetch();
    eq('dead', $row['status']); eq($max, (int)$row['attempts']); ok(strpos($row['last_error'], 'nope') !== false);
});

it('jobs: unique_key deduplicates; failing handler retries with backoff, then dead; retry() revives', function (Sandbox $sb) {
    kit_reset_kernel($sb);
    $r = $sb->php("return [Jobs::enqueue('it.job.x', ['a' => 1], ['unique_key' => 'u1', 'max_attempts' => 3]), Jobs::enqueue('it.job.x', [], ['unique_key' => 'u1'])];");
    ok(is_int($r[0]) && $r[1] === null, json_encode($r));
    $h = "Jobs::handle('it.job.x', function () { throw new RuntimeException('down'); });";
    $s = $sb->php($h . " return Jobs::work(5);");
    eq(1, $s['retry']);
    $row = $sb->pdo()->query("SELECT status, attempts, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), run_at) wait FROM eb_jobs")->fetch();
    eq('pending', $row['status']); eq(1, (int)$row['attempts']); ok((int)$row['wait'] >= 20, 'backoff not applied: ' . $row['wait']);
    for ($i = 0; $i < 2; $i++) { $sb->pdo()->exec("UPDATE eb_jobs SET run_at = UTC_TIMESTAMP()"); $sb->php($h . " return Jobs::work(5);"); }
    eq('dead', $sb->pdo()->query("SELECT status FROM eb_jobs")->fetchColumn());
    eq(true, $sb->php("return Jobs::retry((int)Database::one('SELECT id FROM eb_jobs')['id']);"));
    eq('done', $sb->php("Jobs::handle('it.job.x', function () {}); Jobs::work(5); return Database::one('SELECT status FROM eb_jobs')['status'];"));
});
it('jobs: a job left running by a crashed worker is picked up after its lock expires', function (Sandbox $sb) {
    kit_reset_kernel($sb);
    $sb->php("Jobs::enqueue('it.job.y', []); return 1;");
    $sb->pdo()->exec("UPDATE eb_jobs SET status = 'running', attempts = 1, locked_by = 'dead-worker', locked_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)");
    $s = $sb->php("Jobs::handle('it.job.y', function () {}); return Jobs::work(5);");
    eq(1, $s['done']);
    eq(2, (int)$sb->pdo()->query("SELECT attempts FROM eb_jobs")->fetchColumn());
});
it('jobs: two concurrent workers never run the same job twice', function (Sandbox $sb) {
    kit_reset_kernel($sb);
    $sb->php("for (\$i = 0; \$i < 40; \$i++) Jobs::enqueue('it.job.z', ['i' => \$i]); return 1;");
    $w = "Jobs::handle('it.job.z', function (\$p, \$job) { usleep(20000); Database::run('INSERT INTO it_calls (k) VALUES (?) ON DUPLICATE KEY UPDATE n = n + 1', ['job:' . \$job['id']]); });
          Jobs::work(20, 100);";
    $a = $sb->phpAsync($w); $b = $sb->phpAsync($w);
    proc_close($a); proc_close($b);
    $calls = kit_calls($sb);
    eq(40, count($calls), 'every job ran');
    eq([1], array_values(array_unique(array_values($calls))), 'a job ran twice');
});
