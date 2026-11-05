<?php
require_once EB_ROOT_DIR . '/core/Permissions.php';
require_once __DIR__ . '/fixtures/LegacyAccess.php';

const PERM_PAGES = ['dashboard','inbox','instagram','webchat','send','safir','campaigns','scheduled','queue','sendlog','persons','groups',
    'botbuilder','autoreply','forms','ai','templates','links','notices','support','marketing','stats','analytics','apilog','settings',
    'channels','profile','botsettings','callbacks','logout','crm_me','crm_settings','crm_leads','crm_calls','crm_payroll','crm_tasks','unknownpage'];

function perm_new_decision(string $role, ?string $json, ?array $org, string $page): bool {
    return Permissions::allowsPage(Permissions::effective($role, Permissions::decodeLegacy($json), $org === null ? null : $org['pages']), $page);
}
function perm_new_delete(string $role, ?string $json, ?array $org): bool {
    return Permissions::allows(Permissions::effective($role, Permissions::decodeLegacy($json), $org === null ? null : $org['pages']), 'customer.person.delete');
}

test('permissions: differential — identical page and delete decisions to pre-2.3.0 for 30,000 seeded grant combinations', function () {
    mt_srand(23000);
    $roles = ['admin', 'viewer', 'supervisor', 'operator'];
    $diff = []; $n = 0;
    for ($i = 0; $i < 30000; $i++) {
        $role = $roles[mt_rand(0, 3)];
        $pick = function () { $s = []; foreach (PERM_PAGES as $p) if (mt_rand(0, 6) === 0) $s[] = $p; return $s; };
        switch (mt_rand(0, 9)) {                         // permissions_json variants
            case 0: $json = null; break;
            case 1: $json = ''; break;
            case 2: $json = '{bad json'; break;
            case 3: $json = json_encode(['pages' => []]); break;
            case 4: $json = json_encode(['groups' => [1, 2]]); break;
            case 5: $json = json_encode(['pages' => [123]]); break;   // junk entry only
            case 6: $json = json_encode(['pages' => array_merge($pick(), [null])]); break;
            default: $json = json_encode(['pages' => $pick(), 'can_delete_customers' => (bool)mt_rand(0, 1), 'mask_pii' => (bool)mt_rand(0, 1)]);
        }
        $org = null;
        if ($role === 'operator' || $role === 'supervisor') {
            $o = mt_rand(0, 3);
            if ($o === 1) $org = ['pages' => []];
            elseif ($o >= 2) $org = ['pages' => $pick()];
        }
        LegacyAccess::$role = $role; LegacyAccess::$permsJson = $json; LegacyAccess::$orgRole = $org;
        foreach (PERM_PAGES as $page) {
            $n++;
            if (LegacyAccess::canAccess($page) !== perm_new_decision($role, $json, $org, $page)) $diff[] = compact('role', 'json', 'org', 'page');
        }
        $n++;
        if (LegacyAccess::canManageCustomers() !== perm_new_delete($role, $json, $org)) $diff[] = ['delete', $role, $json];
    }
    eq(0, count($diff), 'first difference: ' . json_encode($diff[0] ?? null));
    ok($n > 1000000, "only $n decisions compared");
});
test('permissions: new roles only add access, and never open admin-only pages', function () {
    $k = Permissions::effective('operator', ['pages' => ['inbox']], null, ['templates.page.view', 'settings.page.view', '*.page.view']);
    ok(Permissions::allowsPage($k, 'templates'));
    ok(Permissions::allowsPage($k, 'campaigns'), 'wildcard role grants non-admin pages');
    ok(!Permissions::allowsPage($k, 'settings'), 'admin-only page must stay closed');
    ok(!Permissions::allowsPage($k, 'crm_settings'));
    ok(Permissions::allowsPage($k, 'dashboard'));
});
test('permissions: wildcard matching is per segment', function () {
    ok(Permissions::allows(['work.*.view'], 'work.task.view'));
    ok(!Permissions::allows(['work.*.view'], 'work.task.edit'));
    ok(!Permissions::allows(['work.*'], 'work.task.view'), 'segment count must match');
    ok(Permissions::allows(['*'], 'anything.at.all'));
    ok(!Permissions::allows([], 'inbox.page.view'));
});
test('permissions: key validation', function () {
    ok(Permissions::validKey('work.task.approve'));
    ok(!Permissions::validKey('Work.Task.Approve'));
    ok(!Permissions::validKey('work.task'));
    ok(!Permissions::validKey('work.*.view'));
    ok(Permissions::validKey('work.*.view', true));
    eq(['inbox.page.view'], Permissions::effective('operator', ['pages' => ['inbox']], null, ['bad key', 'x.y']));
});
