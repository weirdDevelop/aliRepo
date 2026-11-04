<?php
require_once EB_ROOT_DIR . '/core/LoginThrottle.php';

function lt_reset(int &$clock): void {
    $GLOBALS['lt_store'] = [];
    LoginThrottle::useStore(
        function (string $k) { return $GLOBALS['lt_store'][$k] ?? null; },
        function (string $k, $v, int $ttl) { $GLOBALS['lt_store'][$k] = $v; },
        function () use (&$clock) { return $clock; }
    );
}
test('throttle: 4 failures allowed, 5th locks the user+IP pair for 15 min', function () {
    $t = 1000000; lt_reset($t);
    for ($i = 0; $i < 4; $i++) { eq(0, LoginThrottle::wait('admin', '1.2.3.4')); LoginThrottle::failed('admin', '1.2.3.4'); }
    eq(0, LoginThrottle::wait('admin', '1.2.3.4'));
    LoginThrottle::failed('admin', '1.2.3.4');
    eq(900, LoginThrottle::wait('admin', '1.2.3.4'));
    $t += 899; ok(LoginThrottle::wait('admin', '1.2.3.4') > 0);
    $t += 2;   eq(0, LoginThrottle::wait('admin', '1.2.3.4'), 'lock must expire');
});
test('throttle: lock is per pair — same user from another IP, and other users, unaffected', function () {
    $t = 1000000; lt_reset($t);
    for ($i = 0; $i < 5; $i++) LoginThrottle::failed('admin', '1.2.3.4');
    eq(0, LoginThrottle::wait('admin', '5.6.7.8'), 'attacker must not lock the real admin out');
    eq(0, LoginThrottle::wait('operator', '1.2.3.4'));
});
test('throttle: username case/whitespace does not bypass the pair limit', function () {
    $t = 1000000; lt_reset($t);
    foreach (['admin', 'Admin', ' ADMIN', 'admin ', 'aDmin'] as $u) LoginThrottle::failed($u, '1.2.3.4');
    ok(LoginThrottle::wait('admin', '1.2.3.4') > 0);
});
test('throttle: 30 failures from one IP over many usernames lock the IP', function () {
    $t = 1000000; lt_reset($t);
    for ($i = 0; $i < 29; $i++) LoginThrottle::failed("u$i", '9.9.9.9');
    eq(0, LoginThrottle::wait('fresh', '9.9.9.9'));
    LoginThrottle::failed('u29', '9.9.9.9');
    ok(LoginThrottle::wait('fresh', '9.9.9.9') > 0);
});
test('throttle: failures older than the window are forgotten', function () {
    $t = 1000000; lt_reset($t);
    for ($i = 0; $i < 4; $i++) LoginThrottle::failed('admin', '1.2.3.4');
    $t += 901;
    LoginThrottle::failed('admin', '1.2.3.4');
    eq(0, LoginThrottle::wait('admin', '1.2.3.4'), '4 old + 1 new must not lock');
});
test('throttle: success clears the pair counter', function () {
    $t = 1000000; lt_reset($t);
    for ($i = 0; $i < 4; $i++) LoginThrottle::failed('admin', '1.2.3.4');
    LoginThrottle::succeeded('admin', '1.2.3.4');
    LoginThrottle::failed('admin', '1.2.3.4');
    eq(0, LoginThrottle::wait('admin', '1.2.3.4'));
});
