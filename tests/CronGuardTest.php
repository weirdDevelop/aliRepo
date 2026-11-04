<?php
require_once EB_ROOT_DIR . '/core/CronGuard.php';
$GLOBALS['remote'] = ['REMOTE_ADDR' => '203.0.113.9', 'SERVER_ADDR' => '198.51.100.5'];

test('cron: CLI always allowed, with or without a key (cron.php used to refuse CLI when a key was set)', function () {
    eq('ok_cli', CronGuard::check(['K'], null, [], true));
    eq('ok_cli', CronGuard::check([], null, [], true));
});
test('cron: configured key must match (constant-time), any of several keys', function () {
    eq('ok_key', CronGuard::check(['K1'], 'K1', $GLOBALS['remote'], false));
    eq('ok_key', CronGuard::check([null, 'EB-KEY'], 'EB-KEY', $GLOBALS['remote'], false));
    eq('bad_key', CronGuard::check(['K1'], 'K2', $GLOBALS['remote'], false));
    eq('bad_key', CronGuard::check(['K1'], null, $GLOBALS['remote'], false));
    eq('bad_key', CronGuard::check(['K1'], '', $GLOBALS['remote'], false));
});
test('cron: no key configured → remote visitors denied (was allowed before 2.1.6)', function () {
    eq('no_key_remote', CronGuard::check([null, ''], null, $GLOBALS['remote'], false));
});
test('cron: no key configured → the server itself still allowed (hosting wget to own domain)', function () {
    foreach (['127.0.0.1', '::1', '127.0.1.1', '::ffff:127.0.0.1'] as $ip)
        eq('ok_local_no_key', CronGuard::check([], null, ['REMOTE_ADDR' => $ip], false), $ip);
    eq('ok_local_no_key', CronGuard::check([], null, ['REMOTE_ADDR' => '198.51.100.5', 'SERVER_ADDR' => '198.51.100.5'], false));
});
test('cron: X-Forwarded-For is ignored (cannot spoof localhost)', function () {
    eq('no_key_remote', CronGuard::check([], null, $GLOBALS['remote'] + ['HTTP_X_FORWARDED_FOR' => '127.0.0.1'], false));
});
test('cron: whitespace around stored key tolerated', function () {
    eq('ok_key', CronGuard::check(["K1\n"], ' K1', $GLOBALS['remote'], false));
});
