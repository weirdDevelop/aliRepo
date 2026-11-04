<?php
it('smoke: clean install boots, admin can log in and load the panel', function (Sandbox $sb) {
    $sb->user('admin1', 'admin');
    $b = new Browser($sb->url);
    ok($b->login('admin1', 'pw-admin1'), 'admin login failed');
    $r = $b->api('settings_get');
    eq(true, $r['json']['ok'] ?? null, 'settings_get as admin: ' . substr($r['raw'], 0, 200));
});
it('smoke: wrong password is rejected', function (Sandbox $sb) {
    ok(!(new Browser($sb->url))->login('admin1', 'nope'));
});
