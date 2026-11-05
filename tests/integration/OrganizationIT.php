<?php
/** 2.4.0 Organization end-to-end on a real install (synthetic data). */
function oit_ready(Sandbox $sb): Browser {
    $sb->php("Migrations::run(false, false, 'it'); return 1;");
    $sb->user('admin1', 'admin');
    $b = new Browser($sb->url); ok($b->login('admin1', 'pw-admin1'));
    return $b;
}
function oit(Browser $b, string $m, string $path, $body = null): array {
    $h = ['Content-Type: application/json']; if ($m !== 'GET') $h[] = 'X-CSRF-Token: ' . $b->csrf;
    $r = $b->request($m, 'api.php' . $path, $body === null ? null : json_encode($body), $h);
    return $r + ['json' => json_decode($r['body'], true)];
}
function oit_unit(Browser $b, string $name, ?int $parent = null, string $type = 'department'): int {
    $r = oit($b, 'POST', '/v2/org/units', ['name' => $name, 'type' => $type, 'parent_id' => $parent]);
    eq(201, $r['code'], $r['body']);
    return (int)$r['json']['data']['id'];
}
function oit_uid(Sandbox $sb, string $u): int { return (int)$sb->pdo()->query("SELECT id FROM eb_users WHERE username = " . $sb->pdo()->quote($u))->fetchColumn(); }
function oit_clean(Sandbox $sb): void {
    $sb->pdo()->exec("DELETE FROM eb_org_memberships; DELETE FROM eb_org_delegations; DELETE FROM eb_org_changes; DELETE FROM eb_org_units; DELETE FROM eb_perm_grants WHERE scope_type <> 'tenant'");
}

it('org: tables exist; chart readable by any logged-in user, including restricted operators', function (Sandbox $sb) {
    oit_ready($sb);
    foreach (['eb_org_units', 'eb_org_memberships', 'eb_org_delegations', 'eb_org_changes'] as $t) ok((bool)$sb->pdo()->query("SHOW TABLES LIKE '$t'")->fetchColumn(), $t);
    $sb->user('op_org', 'operator', ['pages' => ['inbox']]);
    $op = new Browser($sb->url); ok($op->login('op_org', 'pw-op_org'));
    eq(200, oit($op, 'GET', '/v2/org/units')['code']);
    eq(403, oit($op, 'GET', '/v2/org/import/preview')['code'], 'import is admin-level');
    eq(403, oit($op, 'POST', '/v2/org/units', ['name' => 'x'])['code'], 'creating needs org.unit.manage');
});

it('org: cycles are refused; moves, renames, archive/restore are recorded in unit history', function (Sandbox $sb) {
    $b = oit_ready($sb); oit_clean($sb);
    $a = oit_unit($b, 'IT A'); $a1 = oit_unit($b, 'IT A1', $a, 'team'); $a2 = oit_unit($b, 'IT A2', $a1, 'team'); $bb = oit_unit($b, 'IT B');
    $r = oit($b, 'PATCH', "/v2/org/units/$a", ['parent_id' => $a2]);
    eq(422, $r['code']); eq('rule_violation', $r['json']['error']['code']);
    eq(422, oit($b, 'PATCH', "/v2/org/units/$a", ['parent_id' => $a])['code'], 'under itself');
    eq(200, oit($b, 'PATCH', "/v2/org/units/$a1", ['parent_id' => $bb, 'name' => 'IT A1 renamed'])['code']);
    eq(409, oit($b, 'POST', "/v2/org/units/$bb/archive")['code'], 'has active child');
    eq(200, oit($b, 'POST', "/v2/org/units/$a2/archive")['code']);
    eq(422, oit($b, 'PATCH', "/v2/org/units/$a2", ['name' => 'x'])['code'], 'archived units are read-only');
    eq(200, oit($b, 'POST', "/v2/org/units/$a2/restore")['code']);
    $types = array_column(oit($b, 'GET', "/v2/org/units/$a1")['json']['data']['changes'], 'change_type');
    foreach (['created', 'moved', 'renamed'] as $t) ok(in_array($t, $types, true), "history lacks $t: " . json_encode($types));
});

it('org: memberships are effective-dated, never overwritten; overlaps and second managers refused', function (Sandbox $sb) {
    $b = oit_ready($sb); oit_clean($sb);
    foreach (['m_ali', 'm_sara', 'm_reza'] as $u) $sb->user($u, 'operator', ['pages' => ['inbox']]);
    $d = oit_unit($b, 'IT Dept');
    $ali = oit_uid($sb, 'm_ali'); $sara = oit_uid($sb, 'm_sara'); $reza = oit_uid($sb, 'm_reza');
    $r = oit($b, 'POST', "/v2/org/units/$d/members", ['user_id' => $ali, 'role' => 'member', 'valid_from' => '2026-01-01', 'valid_to' => '2026-03-31']);
    eq(201, $r['code'], $r['body']);
    eq(409, oit($b, 'POST', "/v2/org/units/$d/members", ['user_id' => $ali, 'valid_from' => '2026-03-31'])['code'], 'overlapping period');
    eq(201, oit($b, 'POST', "/v2/org/units/$d/members", ['user_id' => $ali, 'valid_from' => '2026-04-01'])['code'], 'adjacent period is fine');
    eq(201, oit($b, 'POST', "/v2/org/units/$d/members", ['user_id' => $sara, 'role' => 'manager', 'valid_from' => '2026-01-01'])['code']);
    $r = oit($b, 'POST', "/v2/org/units/$d/members", ['user_id' => $reza, 'role' => 'manager', 'valid_from' => '2026-06-01']);
    eq(409, $r['code'], 'second manager'); eq($sara, $r['json']['error']['details']['manager_user_id'] ?? null);
    eq(400, oit($b, 'POST', "/v2/org/units/$d/members", ['user_id' => $reza, 'valid_from' => '2026-02-30'])['code'], 'invalid date');
    // as-of views
    $feb = oit($b, 'GET', '/v2/org/units?date=2026-02-15')['json']['data']['units'];
    $u = array_values(array_filter($feb, fn($x) => $x['id'] === $d))[0];
    eq(2, count($u['members'])); eq($sara, $u['manager']['id']);
    // end the open membership: row kept with an end date
    $mid = (int)$sb->pdo()->query("SELECT id FROM eb_org_memberships WHERE unit_id = $d AND user_id = $ali AND valid_to IS NULL")->fetchColumn();
    eq(200, oit($b, 'POST', "/v2/org/memberships/$mid/end", ['valid_to' => '2026-09-30'])['code']);
    eq(3, (int)$sb->pdo()->query("SELECT COUNT(*) FROM eb_org_memberships WHERE unit_id = $d")->fetchColumn(), 'no rows deleted');
    $oct = array_values(array_filter(oit($b, 'GET', '/v2/org/units?date=2026-10-01')['json']['data']['units'], fn($x) => $x['id'] === $d))[0];
    eq([$sara], array_map(fn($m) => $m['user']['id'], $oct['members']), 'only the manager remains in October');
});

it('org: acting manager and reporting chain', function (Sandbox $sb) {
    $b = oit_ready($sb); oit_clean($sb);
    foreach (['c_head', 'c_lead', 'c_agent', 'c_temp'] as $u) $sb->user($u, 'operator', ['pages' => ['inbox']]);
    [$head, $lead, $agent, $temp] = array_map(fn($u) => oit_uid($sb, $u), ['c_head', 'c_lead', 'c_agent', 'c_temp']);
    $dept = oit_unit($b, 'IT Support'); $team = oit_unit($b, 'IT Shift A', $dept, 'team');
    oit($b, 'POST', "/v2/org/units/$dept/members", ['user_id' => $head, 'role' => 'manager', 'valid_from' => '2026-01-01']);
    oit($b, 'POST', "/v2/org/units/$team/members", ['user_id' => $lead, 'role' => 'manager', 'valid_from' => '2026-01-01']);
    oit($b, 'POST', "/v2/org/units/$team/members", ['user_id' => $agent, 'valid_from' => '2026-01-01', 'is_primary' => true]);
    eq([$lead, $head], array_map(fn($c) => $c['user']['id'], oit($b, 'GET', "/v2/org/users/$agent/reporting-chain?date=2026-05-01")['json']['data']));
    eq([$head], array_map(fn($c) => $c['user']['id'], oit($b, 'GET', "/v2/org/users/$lead/reporting-chain?date=2026-05-01")['json']['data']), 'a manager reports to the next level');
    eq(422, oit($b, 'POST', "/v2/org/units/$team/delegations", ['to_user_id' => $lead, 'valid_from' => '2026-05-01', 'valid_to' => '2026-05-10'])['code'], 'manager cannot be own deputy');
    eq(201, oit($b, 'POST', "/v2/org/units/$team/delegations", ['to_user_id' => $temp, 'valid_from' => '2026-05-01', 'valid_to' => '2026-05-10', 'reason' => 'leave'])['code']);
    eq(409, oit($b, 'POST', "/v2/org/units/$team/delegations", ['to_user_id' => $head, 'valid_from' => '2026-05-05', 'valid_to' => '2026-05-20'])['code'], 'overlapping delegation');
    $ch = oit($b, 'GET', "/v2/org/users/$agent/reporting-chain?date=2026-05-03")['json']['data'];
    eq($temp, $ch[0]['user']['id']); eq(true, $ch[0]['acting']);
    eq($lead, oit($b, 'GET', "/v2/org/users/$agent/reporting-chain?date=2026-05-11")['json']['data'][0]['user']['id'], 'delegation ended');
});

it('org: department-scoped role — manages its subtree only, cannot escape it, opens no pages', function (Sandbox $sb) {
    $b = oit_ready($sb); oit_clean($sb);
    $sb->user('dm', 'operator', ['pages' => ['inbox']]); $sb->user('dm_x', 'operator', ['pages' => ['inbox']]);
    $A = oit_unit($b, 'IT Sales'); $A1 = oit_unit($b, 'IT Sales North', $A, 'team'); $B = oit_unit($b, 'IT Finance');
    $role = oit($b, 'POST', '/v2/identity/roles', ['name' => 'it-dept-manager', 'keys' => ['org.member.manage', 'org.unit.manage']])['json']['data']['id'];
    $g = oit($b, 'PUT', '/v2/identity/users/' . oit_uid($sb, 'dm') . '/scoped-roles', ['grants' => [['role_id' => $role, 'scope_type' => 'org_unit_tree', 'unit_id' => $A]]]);
    eq(200, $g['code'], $g['body']);
    $dm = new Browser($sb->url); ok($dm->login('dm', 'pw-dm'));
    $x = oit_uid($sb, 'dm_x');
    eq(201, oit($dm, 'POST', "/v2/org/units/$A1/members", ['user_id' => $x])['code'], 'inside subtree');
    eq(403, oit($dm, 'POST', "/v2/org/units/$B/members", ['user_id' => $x])['code'], 'other department');
    eq(201, oit($dm, 'POST', '/v2/org/units', ['name' => 'IT Sales South', 'parent_id' => $A])['code'], 'create inside subtree');
    eq(403, oit($dm, 'POST', '/v2/org/units', ['name' => 'IT Rogue', 'parent_id' => null])['code'], 'create at root');
    eq(403, oit($dm, 'PATCH', "/v2/org/units/$A1", ['parent_id' => $B])['code'], 'move out of subtree');
    eq(403, $dm->api('templates_list')['code'], 'unit-scoped roles open no pages');
    eq(422, oit($b, 'PUT', '/v2/identity/users/' . oit_uid($sb, 'admin1') . '/scoped-roles', ['grants' => []])['code'], 'SoD: no self-grants');
});

it('org: import preview reports issues, apply is idempotent and leaves old structures untouched', function (Sandbox $sb) {
    $b = oit_ready($sb); oit_clean($sb);
    $pdo = $sb->pdo();
    $sb->php("OrgRoles::ensure(); Desk::ensure(); return 1;");
    foreach (['i_a', 'i_b', 'i_c'] as $u) $sb->user($u, 'operator', ['pages' => ['inbox']]);
    [$ia, $ib, $ic] = array_map(fn($u) => oit_uid($sb, $u), ['i_a', 'i_b', 'i_c']);
    $pdo->exec("DELETE FROM eb_roles; DELETE FROM eb_teams");
    $pdo->exec("INSERT INTO eb_roles (id, name, parent_id, pages_json, is_manager, sort_order) VALUES (801, 'فروش', NULL, '[]', 1, 1), (802, 'فروش آنلاین', 801, '[]', 0, 2)");
    $pdo->exec("UPDATE eb_users SET role_id = 802 WHERE id = $ia");
    $pdo->exec("INSERT INTO eb_teams (id, name, members, rule) VALUES (701, 'شیفت صبح', '$ib,99999', 'manual')");
    $hasCol = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'eb_user_id'")->fetchColumn();
    if (!$hasCol) $pdo->exec("ALTER TABLE users ADD COLUMN eb_user_id INT DEFAULT NULL");
    $pdo->exec("DELETE FROM staff; DELETE FROM users WHERE username LIKE 'crm_it_%'");
    $pdo->exec("INSERT INTO users (username, password, full_name, role, eb_user_id) VALUES ('crm_it_c', 'x', 'C', 'staff', $ic)");
    $cu = (int)$pdo->lastInsertId();
    // S1 «فروش» + ZWNJ (same as role «فروش» after normalisation), S2 «فروشي» with Arabic yeh (a different word), S3 no unit
    $pdo->prepare("INSERT INTO staff (user_id, code, name, unit, status) VALUES (?, 'S1', 'کارمند یک', ?, 'active'), (NULL, 'S2', 'کارمند دو', 'فروشي', 'active'), (NULL, 'S3', 'کارمند سه', '', 'active')")
        ->execute([$cu, "فروش\u{200c}"]);
    $p = oit($b, 'GET', '/v2/org/import/preview');
    eq(200, $p['code'], $p['body']);
    $d = $p['json']['data'];
    eq(['units' => 2, 'new_units' => 2, 'members' => 1], $d['summary']['roles']);
    eq(['units' => 1, 'new_units' => 1, 'members' => 1], $d['summary']['teams']);
    eq(2, $d['summary']['crm']['units'], 'crm units: ' . json_encode(array_column(array_filter($d['units'], fn($u) => $u['source'] === 'crm'), 'name'), JSON_UNESCAPED_UNICODE));
    ok((bool)array_filter($d['issues'], fn($i) => strpos($i['message'], 'مثل سرپرست') !== false), 'is_manager note missing');
    ok((bool)array_filter($d['issues'], fn($i) => strpos($i['message'], '99999') !== false), 'missing team member not reported');
    eq(['S2', 'S3'], array_column($d['unmatched_staff'], 'staff_code'));
    $dup = array_values(array_filter($d['possible_duplicates'], fn($x) => $x['name'] === 'فروش'));
    ok($dup && in_array('roles:801', $dup[0]['items'], true) && count($dup[0]['items']) === 2, 'role «فروش» vs CRM «فروش‌» must be flagged: ' . json_encode($d['possible_duplicates'], JSON_UNESCAPED_UNICODE));
    $rolesBefore = (int)$pdo->query("SELECT COUNT(*) FROM eb_roles")->fetchColumn();
    $a1 = oit($b, 'POST', '/v2/org/import', ['sources' => ['roles', 'crm']]);
    eq(200, $a1['code'], $a1['body']);
    eq(4, $a1['json']['data']['units_created']); eq(2, $a1['json']['data']['memberships_created']);
    $child = $pdo->query("SELECT u.parent_id, p.name FROM eb_org_units u JOIN eb_org_units p ON p.id = u.parent_id WHERE u.source = 'roles' AND u.source_id = '802'")->fetch();
    eq('فروش', $child['name'], 'hierarchy preserved');
    $a2 = oit($b, 'POST', '/v2/org/import', ['sources' => ['roles', 'crm']]);
    eq(0, $a2['json']['data']['units_created'], 'second run creates nothing'); eq(0, $a2['json']['data']['memberships_created']);
    eq(0, (int)$pdo->query("SELECT COUNT(*) FROM eb_org_units WHERE source = 'teams'")->fetchColumn(), 'unselected source not imported');
    eq($rolesBefore, (int)$pdo->query("SELECT COUNT(*) FROM eb_roles")->fetchColumn(), 'old org chart untouched');
    eq(802, (int)$pdo->query("SELECT role_id FROM eb_users WHERE id = $ia")->fetchColumn(), 'user role_id untouched');
});

it('org: changes are audited through the outbox', function (Sandbox $sb) {
    $b = oit_ready($sb); oit_clean($sb);
    $sb->pdo()->exec("DELETE FROM eb_audit WHERE action LIKE 'org.%'");
    $u = oit_unit($b, 'IT Audit');
    $sb->user('au1', 'operator', ['pages' => ['inbox']]);
    oit($b, 'POST', "/v2/org/units/$u/members", ['user_id' => oit_uid($sb, 'au1')]);
    $sb->php("Outbox::dispatch(); return 1;");
    $rows = $sb->pdo()->query("SELECT action, target_user_id FROM eb_audit WHERE action LIKE 'org.%'")->fetchAll();
    $acts = array_column($rows, 'action');
    ok(in_array('org.unit.created', $acts, true) && in_array('org.member.added', $acts, true), json_encode($acts));
    $m = array_values(array_filter($rows, fn($r) => $r['action'] === 'org.member.added'));
    eq(oit_uid($sb, 'au1'), (int)$m[0]['target_user_id']);
});
