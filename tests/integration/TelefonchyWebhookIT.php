<?php
/** Telefonchy webhook end-to-end against the sandbox (R-03 + R-05). Payloads follow crm/webhook.php's recorded shapes; values synthetic. */
function tf_setting(Sandbox $sb, string $k, ?string $v): void {
    $pdo = $sb->pdo();
    $pdo->prepare("DELETE FROM settings WHERE key_name = ?")->execute([$k]);
    if ($v !== null) $pdo->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?)")->execute([$k, $v]);
}
function tf_count(Sandbox $sb, string $table): int { return (int)$sb->pdo()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn(); }
function tf_post(Sandbox $sb, array $payload): array {
    return (new Browser($sb->url))->request('POST', 'crm/webhook.php', json_encode($payload), ['Content-Type: application/json']);
}
function tf_ringing(string $cuid, ?string $token, $src = 9121234567): array {
    $p = ['service_id' => 'svc', 'cuid' => $cuid, 'unique_id' => $cuid, 'src' => $src, 'dest' => 2188776655,
          'type' => 'incoming', 'event' => 'ringing', 'datetime' => '2026-11-04 10:00:00', 'date' => '2026-11-04 10:00:00'];
    if ($token !== null) $p['webservice_token'] = $token;
    return $p;
}

it('telefonchy: forged event without token → 403, nothing written', function (Sandbox $sb) {
    tf_setting($sb, 'telefonchy_token', 'TEST-API-TOKEN'); tf_setting($sb, 'telefonchy_webhook_auth', null);
    $ev = tf_count($sb, 'telefonchy_events'); $calls = tf_count($sb, 'calls');
    $r = tf_post($sb, tf_ringing('900.1', null));
    eq(403, $r['code'], $r['body']);
    eq($ev, tf_count($sb, 'telefonchy_events')); eq($calls, tf_count($sb, 'calls'));
});
it('telefonchy: wrong token → 403', function (Sandbox $sb) {
    eq(403, tf_post($sb, tf_ringing('900.2', 'guess'))['code']);
});
it('telefonchy: no token configured → 403 (was accepted before 2.1.5)', function (Sandbox $sb) {
    tf_setting($sb, 'telefonchy_token', null);
    eq(403, tf_post($sb, tf_ringing('900.3', 'anything'))['code']);
    tf_setting($sb, 'telefonchy_token', 'TEST-API-TOKEN');
});
it('telefonchy: genuine event accepted, stored, and +98 caller normalised to 09…', function (Sandbox $sb) {
    $ev = tf_count($sb, 'telefonchy_events');
    $r = tf_post($sb, tf_ringing('900.4', 'TEST-API-TOKEN', '+989121234567'));
    eq(200, $r['code'], $r['body']);
    eq($ev + 1, tf_count($sb, 'telefonchy_events'));
    $row = $sb->pdo()->query("SELECT call_source, call_dest FROM calls WHERE cuid = '900.4'")->fetch();
    ok($row, 'call row not created');
    eq('09121234567', $row['call_source'], 'source');   // pre-2.1.5 stored "989121234567"
    eq('02188776655', $row['call_dest'], 'dest');       // JSON number lost its leading zero before
});
it('telefonchy: dedicated webhook token also accepted', function (Sandbox $sb) {
    tf_setting($sb, 'telefonchy_webhook_token', 'TEST-HOOK-TOKEN');
    eq(200, tf_post($sb, tf_ringing('900.5', 'TEST-HOOK-TOKEN'))['code']);
});
it('telefonchy: monitor mode keeps legacy behaviour (processed + logged)', function (Sandbox $sb) {
    tf_setting($sb, 'telefonchy_webhook_auth', 'monitor');
    $r = tf_post($sb, tf_ringing('900.6', null));
    eq(200, $r['code'], $r['body']);
    ok((bool)$sb->pdo()->query("SELECT 1 FROM calls WHERE cuid = '900.6'")->fetchColumn(), 'not processed in monitor mode');
    tf_setting($sb, 'telefonchy_webhook_auth', null);
});
it('telefonchy: empty heartbeat POST → 200 without processing', function (Sandbox $sb) {
    $ev = tf_count($sb, 'telefonchy_events');
    eq(200, (new Browser($sb->url))->request('POST', 'crm/webhook.php', '[]', ['Content-Type: application/json'])['code']);
    eq($ev, tf_count($sb, 'telefonchy_events'));
});
it('telefonchy: rejection log never contains the token', function (Sandbox $sb) {
    tf_post($sb, tf_ringing('900.7', 'SECRET-LOOKING-VALUE-123'));
    $log = (string)@file_get_contents($sb->dir . '/crm/logs/error.log');
    ok(strpos($log, 'Telefonchy webhook: auth mismatch') !== false, 'rejection not logged');
    ok(strpos($log, 'SECRET-LOOKING-VALUE') === false, 'token leaked into log');
});
