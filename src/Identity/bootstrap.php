<?php
/** Identity wiring (2.3.0): role and grant changes → eb_audit, via the outbox (one row per event). */
Outbox::listen('identity.*', 'identity.audit', function (array $event): void {
    $d = $event['data'];
    $detail = ['event_id' => $event['id']] + array_intersect_key($d, array_flip(['role_id', 'name', 'keys', 'before', 'after']));
    Database::run("INSERT INTO eb_audit (user_id, target_user_id, action, detail, ip) VALUES (?, ?, ?, ?, NULL)",
        [$d['by_user_id'] ?? null, $d['user_id'] ?? null, mb_substr($event['type'], 0, 40), mb_substr(json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 500)]);
});
