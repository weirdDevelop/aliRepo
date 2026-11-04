<?php
/** 2.2.0 REST v2 end-to-end through api.php with real sessions. */
function rest(Browser $b, string $method, string $path, $body = null, array $h = []): array {
    $hdr = array_merge(['Content-Type: application/json'], $h);
    if ($b->csrf && $method !== 'GET' && !array_filter($h, fn($x) => stripos($x, 'X-CSRF-Token:') === 0)) $hdr[] = 'X-CSRF-Token: ' . $b->csrf;
    $r = $b->request($method, 'api.php' . $path, $body === null ? null : (is_string($body) ? $body : json_encode($body)), $hdr);
    return $r + ['json' => json_decode($r['body'], true)];
}
function rest_ready(Sandbox $sb): void { $sb->php("Migrations::run(false, false, 'it'); return 1;"); }
function rest_dead_job(Sandbox $sb): int {
    return (int)$sb->php("\$id = Jobs::enqueue('it.job.dead', []); Database::run(\"UPDATE eb_jobs SET status = 'dead', attempts = 5 WHERE id = ?\", [\$id]); return \$id;");
}

it('rest: no session → 401 envelope with request id', function (Sandbox $sb) {
    rest_ready($sb);
    $r = rest(new Browser($sb->url), 'GET', '/v2/platform/ping');
    eq(401, $r['code']); eq('unauthenticated', $r['json']['error']['code'] ?? null); ok(!empty($r['json']['request_id']));
});
it('rest: ping for any user; health admin-only; unknown route 404; wrong method 405', function (Sandbox $sb) {
    rest_ready($sb);
    $sb->user('op_rest', 'operator', ['pages' => ['inbox']]);
    $op = new Browser($sb->url); ok($op->login('op_rest', 'pw-op_rest'));
    $r = rest($op, 'GET', '/v2/platform/ping'); eq(200, $r['code']); eq(true, $r['json']['data']['pong'] ?? null);
    $r = rest($op, 'GET', '/v2/platform/health'); eq(403, $r['code']); eq('forbidden', $r['json']['error']['code'] ?? null);
    eq(404, rest($op, 'GET', '/v2/nothing/here')['code']);
    eq(405, rest($op, 'DELETE', '/v2/platform/ping')['code']);
    $sb->user('admin1', 'admin'); $ad = new Browser($sb->url); ok($ad->login('admin1', 'pw-admin1'));
    $h = rest($ad, 'GET', '/v2/platform/health');
    eq(200, $h['code'], substr($h['body'], 0, 200));
    eq([], $h['json']['data']['migrations']['pending']);
    ok(isset($h['json']['data']['outbox']['pending'], $h['json']['data']['jobs']['open']));
});
it('rest: writes need CSRF; viewer cannot write', function (Sandbox $sb) {
    rest_ready($sb); $id = rest_dead_job($sb);
    $sb->user('admin1', 'admin'); $ad = new Browser($sb->url); ok($ad->login('admin1', 'pw-admin1'));
    $r = rest($ad, 'POST', "/v2/platform/jobs/$id/retry", [], ['X-CSRF-Token: wrong']);
    eq(403, $r['code']); eq('csrf', $r['json']['error']['code'] ?? null);
    $sb->user('viewer1', 'viewer'); $v = new Browser($sb->url); ok($v->login('viewer1', 'pw-viewer1'));
    eq(403, rest($v, 'POST', "/v2/platform/jobs/$id/retry", [])['code']);
});
it('rest: Idempotency-Key replays the same response; different body → 422; action audited via outbox', function (Sandbox $sb) {
    rest_ready($sb); $id = rest_dead_job($sb);
    $sb->pdo()->exec("DELETE FROM eb_audit WHERE action = 'platform.job.retried'");
    $sb->user('admin1', 'admin'); $ad = new Browser($sb->url); ok($ad->login('admin1', 'pw-admin1'));
    $k = 'retry-job-' . $id;
    $r1 = rest($ad, 'POST', "/v2/platform/jobs/$id/retry", ['why' => 'x'], ["Idempotency-Key: $k"]);
    eq(200, $r1['code'], substr($r1['body'], 0, 200));
    $r2 = rest($ad, 'POST', "/v2/platform/jobs/$id/retry", ['why' => 'x'], ["Idempotency-Key: $k"]);
    eq(200, $r2['code'], 'replay must not fail even though the job is no longer dead');
    eq($r1['json']['data'], $r2['json']['data']);
    $r3 = rest($ad, 'POST', "/v2/platform/jobs/$id/retry", ['why' => 'different'], ["Idempotency-Key: $k"]);
    eq(422, $r3['code']); eq('idempotency_mismatch', $r3['json']['error']['code'] ?? null);
    $r4 = rest($ad, 'POST', "/v2/platform/jobs/$id/retry", ['why' => 'x']);
    eq(409, $r4['code'], 'without the key the second retry is a real conflict');
    ok((bool)$sb->pdo()->query("SELECT 1 FROM eb_audit WHERE action = 'platform.job.retried'")->fetchColumn(), 'not audited');
    eq(1, (int)$sb->pdo()->query("SELECT COUNT(*) FROM eb_audit WHERE action = 'platform.job.retried'")->fetchColumn(), 'audited more than once');
});
it('rest: invalid JSON → 400; unhandled exception → generic 500 with request id, details only in log', function (Sandbox $sb) {
    rest_ready($sb);
    $sb->user('admin1', 'admin'); $ad = new Browser($sb->url); ok($ad->login('admin1', 'pw-admin1'));
    eq(400, rest($ad, 'POST', '/v2/platform/jobs/1/retry', '{not json')['code']);
    $r = $sb->php("\$_SESSION = ['eb_uid' => 1, 'eb_role' => 'admin'];
        Rest::add('GET', '/v2/it/boom', function () { throw new RuntimeException('SQLSTATE secret detail /var/www/x.php'); }, 'any');
        return Rest::handle(['method' => 'GET', 'path' => '/v2/it/boom', 'query' => [], 'body' => null, 'raw' => '', 'headers' => []]);");
    eq(500, $r[0]); eq('internal_error', $r[1]['error']['code']);
    ok(strpos(json_encode($r[1]), 'secret detail') === false, 'exception text leaked');
    $logs = implode('', array_map('file_get_contents', glob($sb->dir . '/logs/app-*.log') ?: []));
    ok(strpos($logs, 'secret detail') !== false && strpos($logs, $r[1]['request_id']) !== false, 'not logged with request id');
});
it('rpc: unexpected error hides internals from operators, shows them to admins (was shown to everyone)', function (Sandbox $sb) {
    $sb->user('op_rpc', 'operator', null); $sb->user('admin1', 'admin');
    $op = new Browser($sb->url); ok($op->login('op_rpc', 'pw-op_rpc'));
    $ad = new Browser($sb->url); ok($ad->login('admin1', 'pw-admin1'));
    $sb->pdo()->exec("RENAME TABLE eb_tags TO eb_tags_it_hidden");
    try {
        $r = $op->api('tags_list');
        ok(($r['json']['ok'] ?? true) === false, 'expected a failure: ' . substr($r['raw'], 0, 200));
        ok(strpos($r['raw'], 'eb_tags') === false && strpos($r['raw'], 'SQLSTATE') === false, 'SQL details leaked to operator: ' . substr($r['raw'], 0, 200));
        ok(!empty($r['json']['request_id']), 'request id missing');
        $a = $ad->api('tags_list');
        ok(strpos($a['raw'], 'eb_tags') !== false, 'admin should see the detail: ' . substr($a['raw'], 0, 200));
    } finally {
        $sb->pdo()->exec("RENAME TABLE eb_tags_it_hidden TO eb_tags");
    }
});
