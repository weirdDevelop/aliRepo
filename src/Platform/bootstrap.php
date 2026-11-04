<?php
/**
 * Platform context wiring (2.2.0): security-relevant platform events → eb_audit (via the outbox).
 * Idempotent by design: Outbox guarantees one successful run per (listener, event).
 */
Outbox::listen('platform.*', 'platform.audit', function (array $event): void {
    $d = $event['data'];
    Database::run("INSERT INTO eb_audit (user_id, target_user_id, action, detail, ip) VALUES (?, NULL, ?, ?, NULL)",
        [$d['by_user_id'] ?? null, mb_substr($event['type'], 0, 40), mb_substr(json_encode(['event_id' => $event['id'], 'subject' => $event['subject']] + $d, JSON_UNESCAPED_UNICODE), 0, 500)]);
});
