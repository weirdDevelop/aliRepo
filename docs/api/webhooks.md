# Webhooks

> Status: Proposed for outbound; inbound reflects what 2.1.5/2.1.6 already enforce.

## 1. Inbound (providers → us)

| Provider | Endpoint | Verification (current) |
|---|---|---|
| YarAPI | `webhook/apichi.php` | `sha256=HMAC(secret, ts.raw)`, `hash_equals`, ±300 s, event-id dedupe (`eb_webhook_events`) |
| RoboClick | `webhook/roboclick.php?account=` | same scheme, per-account secret, event-id dedupe |
| Telefonchy | `crm/webhook.php` | `webservice_token` match (enforce, 2.1.5), heartbeat ignored |
| Bale/Telegram/Rubika/SMS | `webhook/receive.php` | per-channel secret in URL/header (to be reviewed per connector in M5) |
| Directsaz | `webhook/directsaz*.php` | token, dedupe |
| Generic connectors | `webhook/connector.php` | HMAC + timestamp |

Target processing contract (all connectors, M5): verify → resolve tenant from Host (never payload) → dedupe on provider event id → store raw (bounded, redacted) → `202/200` within 2 s → process via job → normalized `channel.inbound.received` event. Retries from providers are expected and harmless.

## 2. Outbound (us → customer systems) — new

Same scheme as YarAPI so integrators reuse code:

```
POST <subscriber url>
X-Eb-Event: work.task.completed
X-Eb-Event-Id: evt_01JC…
X-Eb-Timestamp: 1791204517
X-Eb-Signature: sha256=hex(HMAC_SHA256(secret, timestamp + "." + rawBody))
{ "id": "evt_01JC…", "event": "work.task.completed", "created_at": "…", "data": { "task": {"pid": "…"} } }
```

* Subscriptions: `eb_webhook_subscriptions (url, events[], secret_enc, status, created_by)`; HTTPS only; SSRF guard (no private IPs, pinned resolution); no redirects followed.
* Delivery from `eb_outbox` via jobs; timeout 8 s; retries 1 m, 5 m, 15 m, 1 h, 6 h then dead; delivery log visible in panel; manual redeliver (audited).
* Payloads carry ids and minimal fields; never message bodies or PII unless the event type is explicitly a message event and the subscription has that permission.
