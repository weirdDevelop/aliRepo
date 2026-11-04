<?php
/** P-04 end-to-end: changes made by an admin take effect on the user's next request, not next login. */
function pr_forbidden(array $r): bool { return $r['code'] === 403 && (($r['json']['code'] ?? '') === 'forbidden'); }

it('perms: page access revoked/granted while logged in applies on the next request', function (Sandbox $sb) {
    $sb->user('op_live', 'operator', ['pages' => ['templates']]);
    $b = new Browser($sb->url); ok($b->login('op_live', 'pw-op_live'));
    ok(!pr_forbidden($b->api('templates_list')), 'initially allowed');
    $sb->pdo()->prepare("UPDATE eb_users SET permissions_json = ? WHERE username = 'op_live'")->execute([json_encode(['pages' => ['inbox']])]);
    ok(pr_forbidden($b->api('templates_list')), 'revocation must apply without re-login (was ignored before 2.1.6)');
    ok(!pr_forbidden($b->api('canned_list')), 'new grant must apply without re-login');
});
it('perms: role change applies on the next request', function (Sandbox $sb) {
    $sb->user('op_promo', 'operator', ['pages' => ['inbox']]);
    $b = new Browser($sb->url); ok($b->login('op_promo', 'pw-op_promo'));
    ok(pr_forbidden($b->api('settings_get')));
    $sb->pdo()->exec("UPDATE eb_users SET role = 'admin' WHERE username = 'op_promo'");
    ok(!pr_forbidden($b->api('settings_get')), 'promotion should apply');
    $sb->pdo()->exec("UPDATE eb_users SET role = 'operator' WHERE username = 'op_promo'");
    ok(pr_forbidden($b->api('settings_get')), 'demotion must apply immediately');
});
it('perms: deactivated user is logged out on the next request, and it is audited', function (Sandbox $sb) {
    $sb->user('op_leaver', 'operator', ['pages' => ['inbox']]);
    $b = new Browser($sb->url); ok($b->login('op_leaver', 'pw-op_leaver'));
    eq(true, $b->api('my_profile')['json']['ok'] ?? null);
    $sb->pdo()->exec("UPDATE eb_users SET is_active = 0 WHERE username = 'op_leaver'");
    $r = $b->api('my_profile');
    eq(401, $r['code'], 'deactivated session must end (was still valid before 2.1.6): ' . substr($r['raw'], 0, 120));
    eq(401, $b->api('inbox_conversations')['code'], 'stays logged out');
    ok((bool)$sb->pdo()->query("SELECT 1 FROM eb_audit WHERE action = 'session_revoked'")->fetchColumn(), 'not audited');
});
