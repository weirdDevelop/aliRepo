# Integration Inventory

> Status vocabulary: **Code present** = client code exists and lints; **Live-verified** = only when stated by project changelogs as tested on the production server (not re-tested in this session); **Not tested here** = no credentials/sandbox in this session. No integration was exercised live in this session (master prompt §0.1-5).

## 1. Communication channels (`channels/`, `ChannelManager::available()`)

| # | Channel | Driver | Provider host | Inbound | Outbound | Project-reported status |
|---|---|---|---|---|---|---|
| 1 | Bale bot | `BaleBotDriver` | `tapi.bale.ai` | `webhook/receive.php` | text, buttons, media | live ✅ (ROADMAP §1); bot-builder needs final live test 🧪 |
| 2 | Bale Safir (number) | `BaleSafirDriver` | `safir.bale.ai` | — | send by phone | 🧪 payload structure unconfirmed |
| 3 | Telegram | `TelegramDriver` | `api.telegram.org` | webhook | text, buttons | live ✅ |
| 4 | Rubika | `RoubikaDriver` | `botapi.rubika.ir` | webhook | text, buttons | 🧪 bot-builder |
| 5 | SMS | `SmsDriver` + `channels/sms/{Kavenegar,Melipayamak,Smsir}Provider` | kavenegar / payamak-panel / sms.ir | `SmsInbound::pull` | text; DLR via `SmsDlr::poll` | live ✅ |
| 6 | Email | `EmailDriver` (SMTP) | customer SMTP | — | HTML/text | 🧪 |
| 7 | Voice / IVR | `VoiceDriver` (SOAP) | voice provider | IVR ticks (`ChatPlus::ivrTick`) | voice calls | 🧪 open bug: WAV upload `Value cannot be null` |
| 8 | Webchat | `WebchatDriver`, `chat.php`, `widget.js` | self | public widget | text | ✅ built, 🧪 on a real site |
| 9 | Instagram | see §2 | 4 providers | 3 webhooks + polling | see §2 | see §2 |

The owner mentioned "7–8 communication channels": the 8 rows above (1–8) plus Instagram match that.

## 2. Instagram — four provider adapters

| Provider | Files | Auth model | Selection |
|---|---|---|---|
| **YarAPI** (`https://yarapi.ir/api/v1`, formerly "apichi") | `channels/InstagramApichiDriver.php`, `modules/InstagramApichi.php`, `webhook/apichi.php`, `modules/IgWebhookPipeline.php` | One `ak_live_` key for all pages, scopes `account:read, messages:read, messages:send, webhooks:manage` (+`content:publish`) | `eb_ig_accounts.provider = 'apichi'` |
| **RoboClick** (`https://api.roboclick.co`) | `channels/InstagramRoboclickDriver.php`, `InstagramRoboclickCommentAdapter.php`, `modules/InstagramRoboclick.php`, `webhook/roboclick.php`, `instagram-roboclick-oauth-callback.php` | `rk_` integration key + per-page `ak_` key + per-page `wh_` webhook secret; OAuth+PKCE connect flow | `provider = 'roboclick'` (default) |
| **Directsaz** (`developers.directsaz.com/api/v1`) | `channels/InstagramDirectsazDriver.php`, `modules/InstagramDirectsaz.php`, `webhook/directsaz*.php` | provider token | `eb_settings channel.instagram.provider='directsaz'` |
| **Instagram Graph API** (`graph.instagram.com`) | `channels/InstagramDriver.php`, `modules/InstagramAuth.php`, `instagram-oauth-callback.php` | Meta OAuth (token refresh in cron) | `channel.instagram.provider='graph_api'` |

### 2.1 YarAPI: code vs supplied guide (`YarAPI developer guide`, Oct 5 2026)

| Guide endpoint | Used in code | Note |
|---|---|---|
| `GET /ping`, `GET /instagram/accounts`, `GET /instagram/events` | ✅ | polling with cursor |
| `POST /instagram/send-direct`, `/send-direct-buttons` | ✅ | |
| `POST /instagram/reply-comment` (private) | ✅ `sendPrivateReplyToComment` | **log label says "public reply"** (`InstagramApichiDriver::label`, line 333) — mislabel only, call is correct |
| `POST /instagram/send-comment` (public) | ✅ `sendCommentReply` | no log label |
| `/upload-image`, `/upload-audio`, `/send-showcase`, `/send-voice` | ✅ | |
| `POST /instagram/follow-status` | ✅ | code label map also lists `follower-status` (not in guide) |
| `/posts`, `/stories`, `/story-media`, `/publish-post`, `/publish-story` | ✅ | |
| `/webhooks` (+ rotate/test) | ✅ | auto-registration |
| `instagram/send-image` | label only | **not in the guide**; appears in log label map only |
| `/automations*`, `/wallet`, `/account`, `/direct-requests`, `/direct-status` | ❌ not used | Elanban runs its own comment automations (changelog: "define automations only in Elanban") |
| `Idempotency-Key` | ⚠️ sent, but **random per call** (`'eb-'.bin2hex(random_bytes(10))`) | Guide: "stable value built from business data, **not random**". Defeats duplicate protection → risk R-04 |
| Webhook verification | ✅ `sha256=HMAC(secret, ts + "." + raw)`, `hash_equals`, ±300 s window (`InstagramApichi::verifySignature`, l.326) | ✅ event-id dedupe via `eb_webhook_events` (l.367–370, unique-key fallback, row released on processing failure l.393). Matches all four rules in the guide. |

### 2.2 RoboClick: code vs supplied document

Supplied doc lists 11 endpoints; it is explicitly **incomplete** (tabs "Account keys", "Webhook log", "Guide" were not provided).

| Endpoint | In supplied doc | In code |
|---|---|---|
| `CreateInstagramAuthLinkV2`, `CompleteInstagramConnection` | ✅ | ✅ |
| `SendDirect`, `SendDirectWithButtons`, `SendComment`, `SendPrivateReplyToComment`, `UploadImage`, `GetPosts`, `GetFollowerStatus` | ✅ | ✅ |
| `GET /api/v1/direct-requests/{id}` (async status) | ✅ | ❌ (doc §9 also says not added) |
| `CreateFollowRequiredAutomation` | name only, no schema | ❌ |
| `SendShowcase`, `UploadAudio`, `SendPost`, `SendStory` | ❌ **not in supplied doc** | ✅ used (`InstagramRoboclickDriver.php` lines 151, 164, 210, 217) — must be confirmed against the missing doc tabs before relying on them |
| `Idempotency-Key` | required "stable" | ⚠️ random per call (lines 312, 363) |
| Grace-period forced branding | documented | not handled in code (outgoing text may carry RoboClick branding after expiry) |

### 2.3 "RAPI"

The master prompt lists "RAPI documentation" separately. No document or code named RAPI was found. **Assumption pending owner confirmation:** "RAPI" refers to **YarAPI** ("Yar API"). If it is a different product, its documentation is missing (blocker B-04).

## 3. Telephony — Telefonchy ("Telephonechi")

| Item | Finding |
|---|---|
| Doc | `crm/docs/TELEFONCHY_API.md` (local, verified against raw payload samples per its own history). Public `https://telefonchy.com/help` **not reachable from this session** (network policy). |
| Base URL | `https://panel.telefonchy.com/webservice/v1` (`crm/config.php:88`, settings override) |
| Auth | header `webservice-token` |
| Endpoints used | `GET /services`, `GET /calls` (filters, paging), `GET /call?call_id=`, `GET /calls/record`, server date, locations, `POST /calls/analysis/request`, `GET /calls/analysis/show` |
| Webhook | `crm/webhook.php` — `ringing` and `hangup` events with different shapes. **`webservice_token` mismatch is only logged, not rejected** (documented in-file as a deliberate temporary measure). |
| Sync | `crm/cron_sync.php` incremental + date-range backfill; rate state `crm/logs/.telefonchy_rate.json` |
| Extras | Call→Lead conversion, extension↔staff mapping, Vosk transcription, AI call analysis |
| Phone handling | `CallSyncManager::normalizeIranianMobile` keeps `98…`/`0098…` prefixes → mismatches with Elanban persons (see evidence) |

## 4. Other external services

| Service | Use | Files |
|---|---|---|
| Google Search Console + OAuth | SEO dashboards | `modules/SearchConsole.php`, `gsc-oauth.php`, `api/v4_gsc.php` |
| AI providers (OpenAI, OpenRouter, Groq, AvalAI, GapGPT) | sales agent, KB answers, call analysis | `modules/Ai.php`, `SalesAgent.php` — **customer conversation content is sent to the configured provider** |
| Jakojast main website | lead/customer status lookup both ways | `api/internal_lookup.php` (inbound, `X-Internal-Token`), `modules/JakojastLookup.php`, `PropertyLookup.php` (outbound) |
| Generic API connectors | user-defined HTTP connectors | `modules/ApiConnectors.php`, `webhook/connector.php` (HMAC + timestamp) |
| GeoIP | offline country DB | `data/geoip-country-ipv*.bin` |

## 5. Gaps against master prompt §7–§8

* No connector registry/contract shared across providers (each provider has bespoke settings in `eb_settings`/`eb_ig_accounts`).
* No sandbox/fixture harness; no recorded fixtures.
* No uniform message lifecycle states: YarAPI/RoboClick `202 queued` is not followed to a terminal state (`direct-requests`/`direct.status_changed` unused).
