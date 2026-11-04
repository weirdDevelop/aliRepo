# Connector Contracts (Channels context)

> Master prompt §7.6, §8. Status: Proposed. Extends the existing `channels/ChannelInterface.php` (`name, capabilities, send, handleInbound, setWebhook`) rather than replacing it.

## 1. Interface

```php
interface Connector                                   // superset of ChannelInterface
{
    public function id(): string;                    // 'instagram.yarapi', 'telephony.telefonchy', 'sms.kavenegar'
    public function version(): string;
    public function capabilities(): array;           // from a fixed vocabulary, see §2
    public function configSchema(): array;           // fields, types, which are secret (write-only)
    public function healthCheck(): HealthResult;     // ok | degraded | down, latency, message
    public function send(Recipient $to, OutboundMessage $m, string $idempotencyKey): SendResult;
    public function verifyInbound(string $raw, array $headers): VerifyResult;   // signature/token/timestamp
    public function normalizeInbound(array $payload): ?InboundEvent;           // provider event → canonical
    public function providerEventId(array $payload, array $headers): ?string;   // for dedupe
    public function mapError(array $providerResponse): ProviderError;          // {code, retryable, message}
}
```

`SendResult` states follow master prompt §6.3 exactly and **only as reported by the provider**: `queued | submitted | accepted | sent | delivered | read | failed | unknown`. YarAPI `202` = `accepted` (queued at provider), never `delivered`.

## 2. Capability vocabulary

`text, buttons(max n, title len), media.image, media.audio, media.video, showcase(max cards), comment.reply_public, comment.reply_private, follow_status, publish.post, publish.story, delivery_status, read_receipts, inbound.message, inbound.comment, inbound.button, inbound.story, typing, voice_call, call_records, recordings`

Each connector declares limits, e.g. YarAPI: buttons ≤ 3, title ≤ 20 chars, no buttons with images, 24-hour window, comment-originated contact needs `reply-comment` first (all from the supplied YarAPI guide §3).

## 3. Registry

`eb_connectors (id, provider, label, config_enc, status active|paused|error, health, health_at, last_error, created_by, created_at)`; one row per configured account/page. Credentials encrypted (`security-architecture.md` §3). Lifecycle: create → validate → test → activate → pause → rotate → disconnect → delete (after dependency check).

## 4. Current providers → connectors (M5)

| Provider | Connector id | Docs status | Notes |
|---|---|---|---|
| YarAPI | `instagram.yarapi` | Supplied guide (Oct 2026) — complete | **Proposed primary for Instagram** ⚑ (ADR-013) |
| RoboClick | `instagram.roboclick` | Supplied doc **incomplete**; `SendShowcase`, `UploadAudio`, `SendPost`, `SendStory` used but undocumented | Keep; mark those 4 operations `unverified` until docs arrive |
| Directsaz | `instagram.directsaz` | none supplied | Freeze (maintenance only) ⚑ |
| Instagram Graph (Meta OAuth) | `instagram.graph` | none supplied | Freeze ⚑ |
| Telefonchy | `telephony.telefonchy` | local `crm/docs/TELEFONCHY_API.md`; public help page blocked from session (B-01) | Move from `crm/api_integration` |
| Bale bot / Safir, Telegram, Rubika | `msg.bale`, `msg.bale_safir`, `msg.telegram`, `msg.rubika` | public provider docs (not supplied) | Wrap existing drivers |
| SMS: Kavenegar, Melipayamak, SMS.ir | `sms.*` | not supplied | Wrap `channels/sms/*` |
| Email (SMTP), Voice/IVR (SOAP), Webchat | `email.smtp`, `voice.soap`, `web.chat` | — | Wrap |

## 5. Test harness

Per connector: recorded **sanitised** fixtures for inbound payloads and provider responses, contract tests for `normalizeInbound` and `mapError`, signature tests, a fake HTTP server in the sandbox. Fixtures are labelled as fixtures; live/sandbox verification is reported separately (master prompt §8.4).
