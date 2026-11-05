<?php
/** 2.3.0 permissions end-to-end: parity with pre-2.3.0 on a live install, roles, SoD, audit, CRM. */
require_once dirname(__DIR__) . '/fixtures/LegacyAccess.php';

function pit_ready(Sandbox $sb): void {
    $sb->php("Migrations::run(false, false, 'it'); OrgRoles::ensure(); return 1;");
    $sb->user('admin1', 'admin');
}
function pit_admin(Sandbox $sb): Browser { $b = new Browser($sb->url); ok($b->login('admin1', 'pw-admin1')); return $b; }
function pit_v2(Browser $b, string $m, string $path, $body = null): array {
    $h = ['Content-Type: application/json']; if ($m !== 'GET') $h[] = 'X-CSRF-Token: ' . $b->csrf;
    $r = $b->request($m, 'api.php' . $path, $body === null ? null : json_encode($body), $h);
    return $r + ['json' => json_decode($r['body'], true)];
}
function pit_uid(Sandbox $sb, string $u): int { return (int)$sb->pdo()->query("SELECT id FROM eb_users WHERE username = " . $sb->pdo()->quote($u))->fetchColumn(); }

it('perm: migration 0003 creates role and grant tables', function (Sandbox $sb) {
    pit_ready($sb);
    foreach (['eb_perm_roles', 'eb_perm_role_keys', 'eb_perm_grants'] as $t) ok((bool)$sb->pdo()->query("SHOW TABLES LIKE '$t'")->fetchColumn(), $t);
});

it('perm: live parity — panel page list for 40 synthetic users equals the pre-2.3.0 rules (no roles granted)', function (Sandbox $sb) {
    pit_ready($sb);
    $pdo = $sb->pdo();
    $pdo->exec("DELETE FROM eb_perm_grants");
    // org chart: parent with child (effective pages = own + descendants)
    $pdo->exec("DELETE FROM eb_roles");
    $pdo->exec("INSERT INTO eb_roles (id, name, parent_id, pages_json, is_manager, sort_order) VALUES (901, 'it-parent', NULL, '[\"campaigns\"]', 0, 1), (902, 'it-child', 901, '[\"links\",\"stats\"]', 0, 2), (903, 'it-empty', NULL, '[]', 0, 3)");
    $org = [null => null, 901 => ['campaigns', 'links', 'stats'], 902 => ['links', 'stats'], 903 => []];
    $pages = ['dashboard','inbox','instagram','webchat','send','safir','campaigns','scheduled','queue','sendlog','persons','groups','botbuilder','autoreply','forms','ai','templates','links','notices','support','marketing','stats','analytics','apilog','settings','channels','profile','botsettings','callbacks'];
    mt_srand(4242);
    $mismatch = [];
    for ($i = 0; $i < 40; $i++) {
        $u = "par$i"; $role = ['operator', 'supervisor', 'viewer'][mt_rand(0, 2)];
        $sel = array_values(array_filter($pages, fn() => mt_rand(0, 4) === 0));
        $json = mt_rand(0, 3) === 0 ? null : json_encode(['pages' => mt_rand(0, 2) ? $sel : [], 'can_delete_customers' => (bool)mt_rand(0, 1)]);
        $rid = $role === 'viewer' ? null : [null, 901, 902, 903][mt_rand(0, 3)];
        $pdo->prepare("DELETE FROM eb_users WHERE username = ?")->execute([$u]);
        $sb->user($u, $role, $json === null ? null : json_decode($json, true));
        if ($rid) $pdo->prepare("UPDATE eb_users SET role_id = ? WHERE username = ?")->execute([$rid, $u]);
        $b = new Browser($sb->url); ok($b->login($u, 'pw-' . $u), "login $u");
        $boot = $b->api('desk_boot')['json'];
        LegacyAccess::$role = $role; LegacyAccess::$permsJson = $json; LegacyAccess::$orgRole = $rid ? ['pages' => $org[$rid]] : null;
        $expected = array_values(array_filter($pages, fn($p) => LegacyAccess::canAccess($p)));
        $got = array_values(array_filter($boot['pages'] ?? [], fn($p) => in_array($p, $pages, true)));
        if ($expected !== $got) $mismatch[] = ['user' => $u, 'role' => $role, 'json' => $json, 'org' => $rid, 'missing' => array_values(array_diff($expected, $got)), 'extra' => array_values(array_diff($got, $expected))];
        if ((bool)($boot['me']['can_delete'] ?? null) !== LegacyAccess::canManageCustomers()) $mismatch[] = ['user' => $u, 'can_delete' => 'differs'];
    }
    eq([], $mismatch);
});

it('perm: a role adds access immediately; revoking removes it on the next request', function (Sandbox $sb) {
    pit_ready($sb);
    $sb->user('op_r', 'operator', ['pages' => ['inbox']]);
    $op = new Browser($sb->url); ok($op->login('op_r', 'pw-op_r'));
    eq(403, $op->api('templates_list')['code'], 'baseline: templates closed');
    $ad = pit_admin($sb);
    $role = pit_v2($ad, 'POST', '/v2/identity/roles', ['name' => 'it-templates', 'description' => 'x', 'keys' => ['templates.page.view', 'customer.person.delete']]);
    eq(201, $role['code'], $role['body']);
    $rid = $role['json']['data']['id'];
    $g = pit_v2($ad, 'PUT', '/v2/identity/users/' . pit_uid($sb, 'op_r') . '/roles', ['role_ids' => [$rid]]);
    eq(200, $g['code'], $g['body']);
    ok(in_array('role:it-templates', $g['json']['data']['sources']['templates.page.view'] ?? [], true), 'source not reported');
    ok($op->api('templates_list')['code'] !== 403, 'role grant must open templates');
    $boot = $op->api('desk_boot')['json'];
    ok(in_array('templates', $boot['pages'], true), 'SPA page list must include templates');
    eq(true, $boot['me']['can_delete'] ?? null, 'customer.person.delete via role');
    pit_v2($ad, 'PUT', '/v2/identity/users/' . pit_uid($sb, 'op_r') . '/roles', ['role_ids' => []]);
    eq(403, $op->api('templates_list')['code'], 'revocation must apply on the next request');
});

it('perm: admin-only and unknown keys cannot go into roles; duplicate names rejected', function (Sandbox $sb) {
    pit_ready($sb); $ad = pit_admin($sb);
    $r = pit_v2($ad, 'POST', '/v2/identity/roles', ['name' => 'it-bad', 'keys' => ['settings.page.view']]);
    eq(422, $r['code']); eq('rule_violation', $r['json']['error']['code'] ?? null);
    $r = pit_v2($ad, 'POST', '/v2/identity/roles', ['name' => 'it-bad', 'keys' => ['identity.role.manage']]);
    eq(422, $r['code']);
    $r = pit_v2($ad, 'POST', '/v2/identity/roles', ['name' => 'it-bad', 'keys' => ['made.up.key']]);
    eq(400, $r['code']); eq('keys', $r['json']['error']['field'] ?? null);
    pit_v2($ad, 'POST', '/v2/identity/roles', ['name' => 'it-dup', 'keys' => []]);
    eq(409, pit_v2($ad, 'POST', '/v2/identity/roles', ['name' => 'it-dup', 'keys' => []])['code']);
});

it('perm: separation of duties — nobody changes their own roles; non-admins cannot use the editor', function (Sandbox $sb) {
    pit_ready($sb); $ad = pit_admin($sb);
    $r = pit_v2($ad, 'PUT', '/v2/identity/users/' . pit_uid($sb, 'admin1') . '/roles', ['role_ids' => []]);
    eq(422, $r['code']); eq('rule_violation', $r['json']['error']['code'] ?? null);
    $sb->user('op_all2', 'operator', null);
    $op = new Browser($sb->url); ok($op->login('op_all2', 'pw-op_all2'));
    eq(403, pit_v2($op, 'GET', '/v2/identity/roles')['code']);
    eq(403, pit_v2($op, 'POST', '/v2/identity/roles', ['name' => 'x', 'keys' => []])['code']);
    eq(200, pit_v2($op, 'GET', '/v2/identity/me/permissions')['code'], 'own permissions readable');
});

it('perm: a role in use cannot be deleted; changes are audited with the target user', function (Sandbox $sb) {
    pit_ready($sb); $ad = pit_admin($sb);
    $sb->pdo()->exec("DELETE FROM eb_audit WHERE action LIKE 'identity.%'");
    $sb->user('op_aud', 'operator', ['pages' => ['inbox']]);
    $rid = pit_v2($ad, 'POST', '/v2/identity/roles', ['name' => 'it-aud', 'keys' => ['links.page.view']])['json']['data']['id'];
    pit_v2($ad, 'PUT', '/v2/identity/users/' . pit_uid($sb, 'op_aud') . '/roles', ['role_ids' => [$rid]]);
    eq(409, pit_v2($ad, 'DELETE', "/v2/identity/roles/$rid")['code']);
    pit_v2($ad, 'PUT', '/v2/identity/users/' . pit_uid($sb, 'op_aud') . '/roles', ['role_ids' => []]);
    eq(200, pit_v2($ad, 'DELETE', "/v2/identity/roles/$rid")['code']);
    $sb->php("Outbox::dispatch(); return 1;");
    $acts = $sb->pdo()->query("SELECT action, target_user_id FROM eb_audit WHERE action LIKE 'identity.%' ORDER BY id")->fetchAll();
    $names = array_column($acts, 'action');
    foreach (['identity.role.created', 'identity.grants.changed', 'identity.role.deleted'] as $a) ok(in_array($a, $names, true), "missing audit $a: " . json_encode($names));
    $g = array_values(array_filter($acts, fn($a) => $a['action'] === 'identity.grants.changed'));
    eq(pit_uid($sb, 'op_aud'), (int)$g[0]['target_user_id']);
});

it('perm: CRM honours role grants (crm_leads via role; *.page.view = all CRM except settings)', function (Sandbox $sb) {
    pit_ready($sb); $ad = pit_admin($sb);
    $sb->user('op_crm', 'operator', ['pages' => ['inbox']]);
    $crm = fn(Browser $b, string $path) => $b->request('GET', 'crm/api.php/' . $path, null, ['X-Requested-With: XMLHttpRequest'])['code'];
    $op = new Browser($sb->url); ok($op->login('op_crm', 'pw-op_crm'));
    eq(403, $crm($op, 'leads'), 'baseline: leads closed');
    $r1 = pit_v2($ad, 'POST', '/v2/identity/roles', ['name' => 'it-crm-leads', 'keys' => ['crm_leads.page.view']])['json']['data']['id'];
    pit_v2($ad, 'PUT', '/v2/identity/users/' . pit_uid($sb, 'op_crm') . '/roles', ['role_ids' => [$r1]]);
    ok($crm($op, 'leads') !== 403, 'leads must open via role');
    eq(403, $crm($op, 'payroll'), 'other modules stay closed');
    $r2 = pit_v2($ad, 'POST', '/v2/identity/roles', ['name' => 'it-crm-all', 'keys' => ['*.page.view']])['json']['data']['id'];
    pit_v2($ad, 'PUT', '/v2/identity/users/' . pit_uid($sb, 'op_crm') . '/roles', ['role_ids' => [$r2]]);
    ok($crm($op, 'payroll') !== 403, 'wildcard role opens CRM modules');
    eq(403, $crm($op, 'settings'), 'CRM settings stay admin-only');
});
