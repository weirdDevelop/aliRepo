<?php
require_once EB_ROOT_DIR . '/core/Ulid.php';
require_once EB_ROOT_DIR . '/core/Log.php';
require_once EB_ROOT_DIR . '/core/Jobs.php';
require_once EB_ROOT_DIR . '/core/Outbox.php';
require_once EB_ROOT_DIR . '/core/RestError.php';
require_once EB_ROOT_DIR . '/core/Rest.php';
require_once EB_ROOT_DIR . '/core/Migrations.php';
require_once EB_ROOT_DIR . '/tools/check_boundaries.php';

test('kernel: ULIDs are 26 Crockford chars, unique, and sort by time', function () {
    $a = Ulid::generate(1700000000000); $b = Ulid::generate(1700000000001);
    ok(Ulid::isValid($a) && Ulid::isValid($b));
    ok(strcmp($a, $b) < 0, 'later ULID must sort after');
    $set = []; for ($i = 0; $i < 2000; $i++) $set[Ulid::generate()] = 1;
    eq(2000, count($set));
    ok(!Ulid::isValid('01ARZ3NDEKTSV4RRFFQ69G5FAI'), 'I is not Crockford');
});
test('kernel: log redaction hides secrets, message bodies, tokens and phones', function () {
    $r = Log::redact(['password' => 'p', 'api_key' => 'k', 'Authorization' => 'Bearer x', 'text' => 'سلام دنیا',
                      'note' => 'call 09121234567 or +989121234567, key ak_live_ABCDEF123456', 'nested' => ['webhook_secret' => 's', 'n' => 5]]);
    eq('[redacted]', $r['password']); eq('[redacted]', $r['api_key']); eq('[redacted]', $r['Authorization']);
    eq('[body 9 chars]', $r['text']);
    ok(strpos($r['note'], '09121234567') === false && strpos($r['note'], '…4567') !== false, $r['note']);
    ok(strpos($r['note'], 'ABCDEF123456') === false, 'API key leaked');
    eq('[redacted]', $r['nested']['webhook_secret']); eq(5, $r['nested']['n']);
});
test('kernel: request id accepts a sane incoming header, else generates one', function () {
    $_SERVER['HTTP_X_REQUEST_ID'] = 'abc-12345678'; Log::setRequestId(null); eq('abc-12345678', Log::requestId());
    $_SERVER['HTTP_X_REQUEST_ID'] = "bad id\n<script>"; Log::setRequestId(null); ok(Ulid::isValid(Log::requestId()));
    unset($_SERVER['HTTP_X_REQUEST_ID']); Log::setRequestId(null);
});
test('kernel: job backoff grows exponentially, is capped at 1 h, jitter ±20 %', function () {
    eq(30, Jobs::backoffSeconds(1, 0.5)); eq(60, Jobs::backoffSeconds(2, 0.5)); eq(120, Jobs::backoffSeconds(3, 0.5));
    eq(3600, Jobs::backoffSeconds(20, 0.5));
    eq(24, Jobs::backoffSeconds(1, 0.0)); eq(36, Jobs::backoffSeconds(1, 1.0));
});
test('kernel: outbox listener matching (exact, prefix.*, *)', function () {
    Outbox::resetListeners();
    Outbox::listen('work.task.created', 'a', fn() => null);
    Outbox::listen('work.*', 'b', fn() => null);
    Outbox::listen('*', 'c', fn() => null);
    Outbox::listen('crm.*', 'd', fn() => null);
    eq(['a', 'b', 'c'], array_keys(Outbox::listenersFor('work.task.created')));
    eq(['b', 'c'], array_keys(Outbox::listenersFor('work.board.created')));
    eq(['c'], array_keys(Outbox::listenersFor('workflow.x.y')), 'work.* must not match workflow.*');
    Outbox::resetListeners();
});
test('kernel: outbox rejects malformed event types and listener names', function () {
    foreach (['Work.Task', 'work', 'work.task;drop', ''] as $bad) {
        try { Outbox::record($bad, []); ok(false, "accepted $bad"); } catch (InvalidArgumentException $e) {}
    }
    try { Outbox::listen('x.y', 'bad name!', fn() => null); ok(false); } catch (InvalidArgumentException $e) {}
});
test('kernel: REST router matches params, distinguishes 404 vs 405', function () {
    Rest::add('GET', '/v2/t/{id}', fn() => [200, []], 'any');
    [$r, $p] = Rest::match('GET', '/v2/t/42'); ok($r !== null); eq('42', $p['id']);
    [$r, , $exists] = Rest::match('POST', '/v2/t/42'); ok($r === null && $exists, '405 expected');
    [$r, , $exists] = Rest::match('GET', '/v2/nope'); ok($r === null && !$exists, '404 expected');
    [$r] = Rest::match('GET', '/v2/t/4;2'); ok($r === null, 'param must not accept ;');
});
test('kernel: REST error body follows the error contract', function () {
    $b = Rest::errorBody(new RestError(409, 'conflict', 'x', 'f', ['current_version' => 3]));
    eq(false, $b['ok']); eq('conflict', $b['error']['code']); eq('f', $b['error']['field']); eq(3, $b['error']['details']['current_version']);
    ok(isset($b['request_id']));
});
test('kernel: migration files are discovered in order and each is well-formed', function () {
    $files = Migrations::files();
    ok(count($files) >= 2);
    eq(array_keys($files), (function ($k) { sort($k, SORT_STRING); return $k; })(array_keys($files)));
    foreach ($files as $name => $path) {
        $s = Migrations::load($path);
        ok(is_callable($s['up']) && is_callable($s['verify']), "$name needs up and verify");
        ok(is_bool($s['destructive']), "$name destructive flag");
        ok(preg_match('/^\d{4}_[a-z0-9_]+$/', $name) === 1, "bad name $name");
    }
});
test('kernel: boundary checker flags cross-context internals and allows Api/Events', function () {
    eq(['Beta/Bad.php → Eb\\Alpha\\InternalRepo'], eb_boundary_violations(EB_ROOT_DIR . '/tests/fixtures/boundary'));
});
test('kernel: src/ has no boundary violations', function () {
    eq([], eb_boundary_violations(EB_ROOT_DIR . '/src'));
});
test('kernel: panel RPC no longer echoes exception text to non-admins', function () {
    $s = file_get_contents(EB_ROOT_DIR . '/api/handler.php');
    ok(strpos($s, "echo json_encode(['ok' => false, 'error' => 'خطای داخلی: ' . \$e->getMessage()]") === false, 'old leaking handler still present');
    ok(strpos($s, 'Auth::isRealAdmin()) ? \'خطای داخلی: \'') !== false, 'admin-only detail missing');
});
