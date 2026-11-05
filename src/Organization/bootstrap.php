<?php
/** Organization wiring (2.4.0): org changes → eb_audit via the outbox (target user = the member affected, if any). */
Outbox::listen('org.*', 'org.audit', function (array $event): void {
    $d = $event['data'];
    $detail = ['event_id' => $event['id']] + array_diff_key($d, array_flip(['by_user_id']));
    Database::run("INSERT INTO eb_audit (user_id, target_user_id, action, detail, ip) VALUES (?, ?, ?, ?, NULL)",
        [$d['by_user_id'] ?? null, $d['user_id'] ?? null, mb_substr($event['type'], 0, 40), mb_substr(json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 500)]);
});
