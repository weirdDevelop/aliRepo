<?php
require_once EB_ROOT_DIR . '/core/Idempotency.php';

/** Simulates the provider calls one webhook event makes; returns the keys sent. */
function idem_simulate_event(string $eventId, string $aiText): array {
    return Idempotency::run('evt-' . $eventId, function () use ($aiText) {
        return [
            Idempotency::key('instagram/reply-comment', ['igAccountId' => 1, 'contactId' => 55, 'commentId' => 'c9', 'text' => $aiText]),
            Idempotency::key('instagram/send-comment',  ['igAccountId' => 1, 'postId' => 'p1', 'commentId' => 'c9', 'text' => 'public']),
            Idempotency::key('instagram/send-direct',   ['igAccountId' => 1, 'contactId' => 55, 'text' => 'part 1']),
            Idempotency::key('instagram/send-direct',   ['igAccountId' => 1, 'contactId' => 55, 'text' => 'part 2']),
        ];
    });
}

test('idem: a retried event reproduces exactly the same keys (even if AI text differs)', function () {
    Idempotency::reset();
    $first = idem_simulate_event('evt_5f0c9a', 'Hello!');
    $retry = idem_simulate_event('evt_5f0c9a', 'Hi there — different AI wording');
    eq($first, $retry);
});
test('idem: different events never share keys', function () {
    Idempotency::reset();
    eq([], array_intersect(idem_simulate_event('evt_A', 'x'), idem_simulate_event('evt_B', 'x')));
});
test('idem: two sends to the same contact in one event get different keys', function () {
    Idempotency::reset();
    $k = idem_simulate_event('evt_1', 'x');
    ok($k[2] !== $k[3], 'part 1 and part 2 must not collide');
    eq(4, count(array_unique($k)));
});
test('idem: different recipients in one scope get different keys', function () {
    Idempotency::reset();
    [$a, $b] = Idempotency::run('q-7', fn() => [
        Idempotency::key('/External/ExternalDirect/SendDirect', ['contactId' => 1]),
        Idempotency::key('/External/ExternalDirect/SendDirect', ['contactId' => 2]),
    ]);
    ok($a !== $b);
});
test('idem: same page vs another page under one YarAPI key differ', function () {
    Idempotency::reset();
    [$a, $b] = Idempotency::run('evt-1', fn() => [
        Idempotency::key('instagram/send-direct', ['igAccountId' => 1, 'contactId' => 5]),
        Idempotency::key('instagram/send-direct', ['igAccountId' => 2, 'contactId' => 5]),
    ]);
    ok($a !== $b);
});
test('idem: outside a scope keys are random (pre-2.1.5 behaviour)', function () {
    Idempotency::reset();
    ok(!Idempotency::active());
    $a = Idempotency::key('instagram/send-direct', ['contactId' => 5]);
    $b = Idempotency::key('instagram/send-direct', ['contactId' => 5]);
    ok($a !== $b);
    ok(strpos($a, 'eb-') === 0);
});
test('idem: keys are short and header-safe', function () {
    Idempotency::reset();
    $k = Idempotency::run(str_repeat('evt_ÿ/ weird:id', 10), fn() => Idempotency::key('/x?y=1', ['contactId' => 1]));
    ok(strlen($k) <= 60, "len " . strlen($k));
    ok((bool)preg_match('/^[A-Za-z0-9_.-]+$/', $k), $k);
});
test('idem: query string does not change the key', function () {
    Idempotency::reset();
    $a = Idempotency::run('s', fn() => Idempotency::key('instagram/posts?limit=5'));
    $b = Idempotency::run('s', fn() => Idempotency::key('instagram/posts?limit=9'));
    eq($a, $b);
});
test('idem: innermost scope wins and scopes close on exceptions', function () {
    Idempotency::reset();
    try {
        Idempotency::run('outer', function () {
            $inner = Idempotency::run('inner', fn() => Idempotency::key('p', []));
            ok(strpos($inner, 'inner-') === 0, $inner);
            ok(strpos(Idempotency::key('p', []), 'outer-') === 0);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException $e) {}
    ok(!Idempotency::active(), 'scope leaked after exception');
});
test('idem: provider drivers no longer generate random keys for send calls', function () {
    $rc = file_get_contents(EB_ROOT_DIR . '/channels/InstagramRoboclickDriver.php');
    $ap = file_get_contents(EB_ROOT_DIR . '/channels/InstagramApichiDriver.php');
    ok(strpos($rc, "Idempotency::key(\$path, \$body)") !== false, 'RoboClick call() must use Idempotency::key');
    ok(strpos($ap, "Idempotency::key(\$path, \$body + ['igAccountId'") !== false, 'YarAPI call() must use Idempotency::key');
    ok(strpos($ap, "\$idempotent ? ('eb-' . bin2hex(random_bytes(10)))") === false, 'old random key path still present');
});
