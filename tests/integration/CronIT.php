<?php
/** R-06 end-to-end. php -S sees REMOTE_ADDR=127.0.0.1, so "remote" is simulated by setting a key (BAD_KEY path);
 *  the no-key/remote path is covered by unit tests (CronGuardTest) because the sandbox cannot originate a non-local IP. */
function cron_set(Sandbox $sb, ?string $key): void {
    $sb->pdo()->exec("DELETE FROM eb_settings WHERE k IN ('cron.key','cron.last_run','cron.last_denied')");
    if ($key !== null) $sb->pdo()->prepare("INSERT INTO eb_settings (k, v) VALUES ('cron.key', ?)")->execute([$key]);
}
it('cron: wrong key → 403 and denial recorded for the dashboard', function (Sandbox $sb) {
    cron_set($sb, 'REAL-KEY');
    $r = (new Browser($sb->url))->request('GET', 'cron.php?key=guess');
    eq(403, $r['code']);
    $d = json_decode((string)$sb->pdo()->query("SELECT v FROM eb_settings WHERE k='cron.last_denied'")->fetchColumn(), true);
    eq('bad_key', $d['reason'] ?? null);
    ok(strpos(json_encode($d), 'guess') === false, 'provided key must not be stored');
});
it('cron: right key → runs and writes heartbeat', function (Sandbox $sb) {
    cron_set($sb, 'REAL-KEY');
    $r = (new Browser($sb->url))->request('GET', 'cron.php?key=REAL-KEY');
    ok($r['code'] !== 403, 'got ' . $r['code']);
    ok((bool)$sb->pdo()->query("SELECT v FROM eb_settings WHERE k='cron.last_run'")->fetchColumn(), 'heartbeat');
});
it('cron: no key, request from the server itself → runs (keeps existing hosting crons working)', function (Sandbox $sb) {
    cron_set($sb, null);
    ok((new Browser($sb->url))->request('GET', 'cron.php')['code'] !== 403);
});
it('cron: CLI run with a key set now works (was 403 before 2.1.6)', function (Sandbox $sb) {
    cron_set($sb, 'REAL-KEY');
    exec('cd ' . escapeshellarg($sb->dir) . ' && php cron.php 2>&1', $out, $rc);
    ok(stripos(implode("\n", $out), 'forbidden') === false, implode("\n", array_slice($out, 0, 5)));
});
it('cron: dashboard checklist shows cron step and denial reason', function (Sandbox $sb) {
    cron_set($sb, 'REAL-KEY');
    (new Browser($sb->url))->request('GET', 'cron.php?key=guess');
    $sb->user('admin1', 'admin'); $b = new Browser($sb->url); ok($b->login('admin1', 'pw-admin1'));
    $steps = array_column($b->api('setup_status')['json']['steps'] ?? [], null, 'key');
    ok(isset($steps['cron']) && $steps['cron']['done'] === false, 'cron step missing or done');
    ok(strpos($steps['cron']['desc'], 'رد شد') !== false, $steps['cron']['desc']);
});
it('crm cron: Elanban cron key accepted, wrong key rejected', function (Sandbox $sb) {
    cron_set($sb, 'REAL-KEY');
    eq(403, (new Browser($sb->url))->request('GET', 'crm/cron_sync.php?key=guess')['code']);
    ok((new Browser($sb->url))->request('GET', 'crm/cron_sync.php?key=REAL-KEY')['code'] !== 403);
});
