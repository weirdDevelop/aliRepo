# Current Architecture (as found)

> Evidence-based description of the system in archive `14050813.zip`, build `2.1.4-jk`. Not a target design.

## 1. Shape: two co-installed PHP applications, one database

```
                         ┌──────────────────────────── one MySQL database ───────────────────────────┐
                         │  eb_* (103 tables)                         CRM tables (29, unprefixed)     │
                         └───────────────▲────────────────────────────────────────▲──────────────────┘
                                         │ PDO                                    │ PDO (same creds)
 ┌───────────────────────────────────────┴───────────┐        ┌──────────────────┴─────────────────────┐
 │ ELANBAN core  (/)                                 │ bridge │ JAKOJAST CRM  (/crm)                    │
 │ L4 UI     views/layout.php + views/pages/*        │◄──────►│ SPA views/*.php + api.php (/api/{res})  │
 │ L3 logic  modules/*.php (74)                      │ HMAC   │ models/*.php (Lead, Call, Host, Task…) │
 │ L2 chan.  channels/*Driver.php (ChannelInterface) │        │ api_integration/TelefonchyAPI.php       │
 │ L1 core   core/Database, Auth, Schema, Csrf       │        │ core/Auth (Bearer, bridged to eb_users) │
 └───▲──────────────▲───────────────▲────────────────┘        └──────────▲───────────────────────────────┘
     │ webhooks     │ cron.php      │ public widgets                     │ crm/webhook.php (calls)
 Bale/Telegram/  every minute     chat.php, widget.js,             Telefonchy VoIP
 Rubika/SMS/     (queue, flows,   r.php, notices, feed
 Instagram       campaigns, IG
 (YarAPI/        polling, CRM sync)
 RoboClick/
 Directsaz/Graph)                         ┌───────────────────────────┐
                                          │ Jakojast main website     │  api/internal_lookup.php
                                          │ (separate system)         │◄─ X-Internal-Token / HMAC
                                          └───────────────────────────┘
```

* **Elanban core** is a 4-layer modular monolith (layering documented in `_CONTINUE_GUIDE.md` and confirmed in code).
* **CRM** (`crm/`) was a separate app and is now *bridged*: it reads DB credentials and `app_key` from the parent `config.php`, authenticates against `eb_users` (`CRM_BRIDGED = true`), and talks to Elanban through `crm-bridge.php` / `modules/CrmBridge.php` with an HMAC key derived from `app_key`.
* There is **no tenant concept**: "each installation = one company" (`_CONTINUE_GUIDE.md`). Multi-site exists only as `eb_sites` + `SiteCtx` (header `X-EB-Site`) for website widgets.

## 2. Request lifecycle (panel API)

`index.php` → `Database::init` → `SchemaV4::ensure()` (**runs schema checks on every request**) → `Auth::start` → login gate → `Csrf::requireValid()` (POST only) → `Viewer::gateApi()` (read-only demo role allow-list) → `api/handler.php` giant `switch($api)` (442 actions across `api/*.php`) → `out()` JSON (with `Viewer::mask`).

Authorization is **per action, ad-hoc** (`adminOnly()`, `Auth::is*()`, `inbox_assert_access()`), with no central action→permission map. Page permissions (`Auth::canAccess($page)`) are enforced only when rendering pages, not on API calls. See `permission-inventory.md`.

## 3. Core domain concept: "golden identity"

`eb_persons` (one customer, phone unique/null, status member|anonymous) 1→N `eb_identities` (channel, channel_uid; UNIQUE(channel, channel_uid)). Mobile number is the merge key (`Persons::merge`, `Persons::normalizePhone`). This is the closest existing thing to the requested Customer Master + identity mapping (§5.2/§5.4).

The CRM keeps **separate** customer masters: `customers`, `leads`, `host_leads`, `hosts`, linked to Elanban only through `eb_crm_links` and phone matching.

## 4. Messaging pipeline

* Outbound: `Sender::sendToPerson` → `ChannelManager::driver()` → driver `send()`; bulk via `Queue` (`eb_queue_jobs`, chunked, cron + client push), `SendLog::start/target/finish` (`eb_sends`, `eb_send_targets`), personalisation (`Personalize`), campaigns (`Campaigns`), scheduler (`Scheduler`).
* Inbound priority (documented and in `webhook/receive.php`): `Persons::ingestIdentity` → `Forms` → `BotMenu` → welcome → `AutoReply` (+ `Flows`, `SalesAgent` AI).
* Instagram has its own shared pipeline `IgWebhookPipeline` used by RoboClick and YarAPI webhooks and by YarAPI polling.

## 5. Scheduled work (`cron.php`)

Global run lock, then: `Queue::process`, `Scheduler::processDue`, `Campaigns::processDue`, `Flows::tick`, `Followups::tick`, `IgPosts::processDue`, `IgCommentAutomation::cleanupPending`, `InstagramApichi::poll`, `InstagramAuth::refreshIfNeeded`, `SmsDlr::poll`, `SmsInbound::pull`, `CrmBridge::syncStep`, `ConvIndex::cronSweep`, `ContentMetrics::cronTick`, `SearchConsole::cronTick`, `ChatPlus::ivrTick`, log cleanups, `eb_webhook_events` 2-day purge. CRM: `crm/cron_sync.php` (Telefonchy call sync), `crm/cron/transcribe_calls.php`.

## 6. Cross-cutting

| Concern | Current state |
|---|---|
| Migrations | Imperative, idempotent `CREATE TABLE IF NOT EXISTS` + `addColumn` in `core/Schema.php`, `core/SchemaV4.php`, and self-migrating models in CRM (`ensure*Columns`). No versioned migration table, no down-migrations. |
| Caching | File cache `logs/cache/*.cache` (`core/Cache.php`), incl. column-existence caches and per-user desk counters. |
| Logging | `eb_api_log`, `ApichiLog`, `telefonchy_api_logs`, `elanban_api_logs`, PHP `error_log`. No correlation IDs. |
| Secrets | `config.php` (plaintext); channel bot tokens stored **plaintext** in `eb_settings` (`ChannelManager::saveToken`, verified in M1 — the `config.php` comment overstates it); Search Console and AI keys are AES-256-GCM encrypted with a key derived from `app_key`; RoboClick account keys AES-256 per supplied RoboClick doc. |
| Licensing | `modules/License.php`, `AnalyticsLicense.php`, `cli/analytics_gen_license.php` (HMAC licences). |
| AI | `modules/Ai.php`, `SalesAgent.php`, `AiKb.php`, `AgentKb.php` (OpenAI-compatible providers; customer data is sent to the configured provider). |
