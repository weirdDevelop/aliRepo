<?php
require_once EB_ROOT_DIR . '/crm/api_integration/TelefonchyWebhookAuth.php';

// Payload shapes as recorded in crm/webhook.php header (root fields) and crm/docs/TELEFONCHY_API.md (data envelope)
function tf_root(?string $tok): array { $p = ['cuid' => '173.1', 'event' => 'ringing', 'src' => 9121234567]; if ($tok !== null) $p['webservice_token'] = $tok; return $p; }
function tf_env(?string $tok): array { $d = ['cuid' => '173.1', 'event' => 'hangup']; if ($tok !== null) $d['webservice_token'] = $tok; return ['uid' => 'x', 'data' => $d]; }

test('telefonchy: correct API token accepted (root and envelope)', function () {
    eq('ok', TelefonchyWebhookAuth::check(tf_root('API-TOKEN'), [null, 'API-TOKEN']));
    eq('ok', TelefonchyWebhookAuth::check(tf_env('API-TOKEN'), ['', 'API-TOKEN']));
});
test('telefonchy: dedicated webhook token accepted when configured', function () {
    eq('ok', TelefonchyWebhookAuth::check(tf_root('HOOK-TOKEN'), ['HOOK-TOKEN', 'API-TOKEN']));
});
test('telefonchy: wrong token rejected', function () {
    eq('mismatch', TelefonchyWebhookAuth::check(tf_root('forged'), ['HOOK-TOKEN', 'API-TOKEN']));
    eq('mismatch', TelefonchyWebhookAuth::check(tf_root('API-TOKEN-x'), [null, 'API-TOKEN']));
});
test('telefonchy: missing token rejected (was accepted before 2.1.5)', function () {
    eq('missing_token', TelefonchyWebhookAuth::check(tf_root(null), [null, 'API-TOKEN']));
    eq('missing_token', TelefonchyWebhookAuth::check(tf_root('   '), [null, 'API-TOKEN']));
});
test('telefonchy: nothing configured cannot be verified (was accepted before 2.1.5)', function () {
    eq('no_token_configured', TelefonchyWebhookAuth::check(tf_root('anything'), [null, '', '  ']));
});
test('telefonchy: surrounding whitespace in stored or received token tolerated', function () {
    eq('ok', TelefonchyWebhookAuth::check(tf_root(' API-TOKEN '), [null, "API-TOKEN\n"]));
});
test('telefonchy: non-string token values do not match', function () {
    eq('missing_token', TelefonchyWebhookAuth::check(['webservice_token' => ['API-TOKEN']], ['API-TOKEN']));
});
test('telefonchy: mode defaults to enforce', function () {
    eq('enforce', TelefonchyWebhookAuth::mode(null));
    eq('enforce', TelefonchyWebhookAuth::mode(''));
    eq('enforce', TelefonchyWebhookAuth::mode('garbage'));
    eq('monitor', TelefonchyWebhookAuth::mode(' Monitor '));
});
test('telefonchy: webhook.php rejects before writing anything when enforcing', function () {
    $src = file_get_contents(EB_ROOT_DIR . '/crm/webhook.php');
    $auth = strpos($src, 'TelefonchyWebhookAuth::check(');
    $insert = strpos($src, "insert('telefonchy_events'");
    ok($auth !== false && $insert !== false && $auth < $insert, 'auth must run before the first DB write');
    ok(strpos($src, 'http_response_code(403)') !== false);
    ok(strpos($src, "'received_len'") !== false && strpos($src, "'received_prefix'") === false, 'log must not contain token text');
});
