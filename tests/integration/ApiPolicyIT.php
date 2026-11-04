<?php
/** R-02 end-to-end: real sessions, real index.php routing, sandbox DB. Only harmless inputs (id 0 / empty). */
function authz_forbidden(array $r): bool { return $r['code'] === 403 && (($r['json']['code'] ?? '') === 'forbidden'); }

it('authz: inbox-only operator — inbox flows keep working', function (Sandbox $sb) {
    $sb->user('op_inbox', 'operator', ['pages' => ['inbox']]);
    $b = new Browser($sb->url); ok($b->login('op_inbox', 'pw-op_inbox'), 'login');
    eq(200, $b->request('GET', 'index.php')['code'], 'panel shell');
    foreach (['desk_boot', 'notifications_poll', 'desk_counts', 'inbox_conversations', 'canned_list', 'my_profile', 'persons_list'] as $a) {
        $r = $b->api($a);
        ok(!authz_forbidden($r), "$a must stay allowed, got {$r['code']} " . substr($r['raw'], 0, 120));
    }
});
it('authz: inbox-only operator — other pages\' write actions are blocked (were reachable before 2.1.5)', function (Sandbox $sb) {
    $sb->user('op_inbox', 'operator', ['pages' => ['inbox']]);
    $b = new Browser($sb->url); ok($b->login('op_inbox', 'pw-op_inbox'));
    foreach (['send_enqueue', 'send_bulk', 'template_delete', 'campaign_create', 'autoreply_delete', 'instagram_automation_save',
              'flow_delete', 'group_delete', 'link_delete', 'settings_get', 'settings_save', 'operator_create'] as $a) {
        $r = $b->api($a, ['id' => 0]);
        ok(authz_forbidden($r), "$a should be 403, got {$r['code']} " . substr($r['raw'], 0, 120));
    }
});
it('authz: inbox-only operator — CSV exports outside inbox are blocked', function (Sandbox $sb) {
    $sb->user('op_inbox', 'operator', ['pages' => ['inbox']]);
    $b = new Browser($sb->url); ok($b->login('op_inbox', 'pw-op_inbox'));
    eq(403, $b->request('GET', 'index.php?export=customers')['code']);
    ok($b->request('GET', 'index.php?export=messages')['code'] !== 403, 'messages export belongs to inbox');
});
it('authz: operator without page restrictions (legacy full access) keeps non-admin pages, not admin ones', function (Sandbox $sb) {
    $sb->user('op_all', 'operator', null);
    $b = new Browser($sb->url); ok($b->login('op_all', 'pw-op_all'));
    ok(!authz_forbidden($b->api('templates_list')), 'templates_list');
    ok(!authz_forbidden($b->api('campaigns_list')), 'campaigns_list');
    ok(authz_forbidden($b->api('settings_get')), 'settings is an admin-only page');
    ok(authz_forbidden($b->api('send_bulk')), 'unused endpoint is admin-only');
});
it('authz: admin unaffected', function (Sandbox $sb) {
    $sb->user('admin1', 'admin');
    $b = new Browser($sb->url); ok($b->login('admin1', 'pw-admin1'));
    foreach (['settings_get', 'templates_list', 'send_audience_count', 'operators_list'] as $a) ok(!authz_forbidden($b->api($a)), $a);
});
it('authz: monitor mode logs but allows', function (Sandbox $sb) {
    $sb->pdo()->exec("INSERT INTO eb_settings (k, v) VALUES ('security.api_authz', 'monitor') ON DUPLICATE KEY UPDATE v = VALUES(v)");
    $sb->user('op_inbox', 'operator', ['pages' => ['inbox']]);
    $b = new Browser($sb->url); ok($b->login('op_inbox', 'pw-op_inbox'));
    ok(!authz_forbidden($b->api('templates_list')), 'monitor mode must allow');
    $sb->pdo()->exec("DELETE FROM eb_settings WHERE k = 'security.api_authz'");
    ok(strpos($sb->serverLog(), 'WOULD DENY (monitor) api=templates_list') !== false, 'would-deny not logged');
    ok(authz_forbidden($b->api('templates_list')), 'enforced again after removing the setting');
});
