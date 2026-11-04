<?php
/** P-09/P-10 end-to-end on the bridged CRM (real Elanban login → shared session → crm/api.php). */
function crm_call(Browser $b, string $method, string $path, ?array $body = null): array {
    $r = $b->request($method, 'crm/api.php/' . $path, $body === null ? null : json_encode($body),
        ['X-Requested-With: XMLHttpRequest', 'Content-Type: application/json']);
    return $r + ['json' => json_decode($r['body'], true)];
}
function crm_secret(Sandbox $sb): void {
    $pdo = $sb->pdo();
    $pdo->exec("DELETE FROM settings WHERE key_name IN ('telefonchy_token','sms_api_key','site_db_pass')");
    foreach (['telefonchy_token' => 'CRM-SECRET-TEL', 'sms_api_key' => 'CRM-SECRET-SMS', 'site_db_pass' => 'CRM-SECRET-DB'] as $k => $v)
        $pdo->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?)")->execute([$k, $v]);
}
function crm_login(Sandbox $sb, string $u, string $role, ?array $perms): Browser {
    $sb->user($u, $role, $perms);
    $b = new Browser($sb->url); ok($b->login($u, 'pw-' . $u), "login $u");
    return $b;
}

it('crm: "all sections" operator cannot read CRM settings (tokens/passwords) — was 200 with secrets before 2.1.6', function (Sandbox $sb) {
    crm_secret($sb);
    $b = crm_login($sb, 'crm_all', 'operator', null);
    $r = crm_call($b, 'GET', 'settings');
    eq(403, $r['code'], substr($r['body'], 0, 150));
    ok(strpos($r['body'], 'CRM-SECRET') === false, 'secret leaked');
});
it('crm: read-only demo viewer cannot read CRM settings', function (Sandbox $sb) {
    crm_secret($sb);
    $b = crm_login($sb, 'crm_viewer', 'viewer', null);
    $r = crm_call($b, 'GET', 'settings');
    eq(403, $r['code']);
    ok(strpos($r['body'], 'CRM-SECRET') === false, 'secret leaked to viewer');
});
it('crm: CRM settings page is not rendered for non-admins (it embeds token values)', function (Sandbox $sb) {
    crm_secret($sb);
    $b = crm_login($sb, 'crm_all', 'operator', null);
    $r = $b->request('GET', 'crm/index.php?page=settings');
    ok(strpos($r['body'], 'CRM-SECRET') === false, 'settings page leaked a secret (HTTP ' . $r['code'] . ')');
});
it('crm: "all sections" operator cannot send arbitrary SMS via /sms/send', function (Sandbox $sb) {
    $b = crm_login($sb, 'crm_all', 'operator', null);
    eq(403, crm_call($b, 'POST', 'sms/send', ['to' => '09120000000', 'message' => 'x'])['code']);
});
it('crm: staff page online-status still works for any logged-in user', function (Sandbox $sb) {
    $b = crm_login($sb, 'crm_inbox', 'operator', ['pages' => ['inbox']]);
    eq(200, crm_call($b, 'GET', 'users/online-status')['code']);
});
it('crm: "all sections" operator keeps normal CRM modules', function (Sandbox $sb) {
    $b = crm_login($sb, 'crm_all', 'operator', null);
    foreach (['leads', 'customers', 'tasks'] as $res) ok(crm_call($b, 'GET', $res)['code'] !== 403, $res);
});
it('crm: creating a lead reference needs the leads module (was open to everyone)', function (Sandbox $sb) {
    $b = crm_login($sb, 'crm_inbox', 'operator', ['pages' => ['inbox']]);
    eq(403, crm_call($b, 'POST', 'references', ['name' => 'x'])['code']);
    ok(crm_call($b, 'GET', 'references')['code'] !== 403, 'listing references stays open (lead form)');
    $b2 = crm_login($sb, 'crm_leads', 'operator', ['pages' => ['crm_leads']]);
    ok(crm_call($b2, 'POST', 'references', ['name' => 'x'])['code'] !== 403, 'leads user may create');
});
it('crm: admin still reads CRM settings', function (Sandbox $sb) {
    crm_secret($sb);
    $b = crm_login($sb, 'admin1', 'admin', null);
    $r = crm_call($b, 'GET', 'settings');
    eq(200, $r['code'], substr($r['body'], 0, 150));
    eq('CRM-SECRET-TEL', $r['json']['data']['telefonchy_token'] ?? null);
});
