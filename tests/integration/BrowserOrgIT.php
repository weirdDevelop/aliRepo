<?php
/** 2.4.0 org chart UI in a real browser. Skipped without node+playwright. */
it('browser: admin builds units and sets a manager in Settings; operator sees the read-only chart and own reporting line', function (Sandbox $sb) {
    exec('node -e "require(\'playwright\')" 2>&1', $o, $rc);
    if ($rc !== 0) { echo "        (skipped: playwright not available)\n"; return; }
    $sb->php("Migrations::run(false, false, 'it'); return 1;");
    $sb->pdo()->exec("DELETE FROM eb_org_memberships; DELETE FROM eb_org_delegations; DELETE FROM eb_org_changes; DELETE FROM eb_org_units");
    $sb->user('admin_org', 'admin');
    $sb->user('lead_ui', 'operator', ['pages' => ['inbox']]);
    $sb->user('agent_ui', 'operator', ['pages' => ['inbox']]);
    $cmd = 'node ' . escapeshellarg(__DIR__ . '/browser/org_chart.js') . ' ' . escapeshellarg($sb->url) . ' admin_org pw-admin_org agent_ui pw-agent_ui lead_ui 2>&1';
    $lines = array_values(array_filter(explode("\n", trim((string)shell_exec($cmd)))));
    $r = json_decode((string)end($lines), true);
    ok(is_array($r), 'bad output: ' . implode("\n", $lines));
    ok(empty($r['error']), 'script error: ' . ($r['error'] ?? ''));
    eq([], $r['pageErrors'], 'JS errors');
    eq(['UI Support', 'UI Shift A'], $r['nodes']);
    eq(true, $r['teamShowsManager'], 'manager not shown on the team node');
    ok(!in_array('UI Support', $r['parentOptions'], true) && !in_array('UI Shift A', $r['parentOptions'], true), 'cycle guard: ' . json_encode($r['parentOptions'], JSON_UNESCAPED_UNICODE));
    eq(['roles', 'teams', 'crm'], $r['importSources']);
    eq(true, $r['opSeesUnits']); eq(true, $r['opSeesManager']);
    eq(0, $r['opSeesManageButtons'], 'operator must not get manage buttons');
    eq(true, $r['opChain'], 'operator should see «reports to» their team manager');
});
