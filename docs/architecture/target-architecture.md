# Target Architecture

> Milestone 1. Status: **Proposed — awaiting owner review.** Decisions marked ⚑ need explicit approval (see `decisions-for-owner.md`).
> Baseline: build 2.1.6-jk. Evidence references point to `docs/discovery/` and code paths.

## 1. Constraints that shape every decision

| Constraint | Evidence | Consequence |
|---|---|---|
| Runs on **shared cPanel/DirectAdmin hosting**: MySQL ≥ 5.7, PHP ≥ 7.4 (8.1 recommended), one cron per minute, no daemons, no Composer | `install.php` l.27, 180, 1009, 1128–1210; `_CONTINUE_GUIDE.md` | No Redis, no broker, no long-running workers. Async work = database tables processed by the minute cron (plus end-of-request flush). |
| Plain PHP, no framework; ~93k lines; 1,485 Elanban + 661 CRM DB call sites | discovery; scan in M1 | Rewrite is not viable. **Strangler approach**: new capabilities in a new structured layer; legacy modules wrapped, then migrated piece by piece. |
| One company per install today; Jakojast business logic mixed into core | `_CONTINUE_GUIDE.md`; R-12 (106 files) | Jakojast moves to an **extension**; Commercial is the core without it. |
| No automated tests until 2.1.5 | R-07 | Every increment ships with unit + sandbox integration tests (`tests/`), already in place. |
| Persian-first UI, Jalali dates, `Asia/Tehran` | discovery | Locale is configuration, not code: storage in UTC/ISO, presentation localized. |

## 2. Style: modular monolith, strangler migration (ADR-001)

One deployable PHP application, one database per tenant, divided into **bounded contexts** with explicit public APIs and events. No microservices: no measurable benefit at this scale, and the hosting target cannot run them.

```
                    ┌──────────────────────── Presentation ────────────────────────┐
                    │ SPA (assets/p-*.js) · legacy pages (views/pages) · CRM views  │
                    └──────────────┬───────────────────────────────┬────────────────┘
                                   │ panel RPC  index.php?api=      │ REST  api.php/v2/…   (ADR-009)
                    ┌──────────────▼───────────────────────────────▼────────────────┐
                    │ Application gate: session/API key → CSRF → tenant → AuthZ      │
                    │ (Auth, ApiPolicy → PermissionService, Viewer)                  │
                    ├────────────────────────── Bounded contexts ────────────────────┤
                    │ Identity&Access · Organization · Work(Tasks/Projects) ·        │
                    │ Workflow&Approvals · CRM · Customer Identity · Conversations · │
                    │ Channels · Notifications · Automation · Files · Analytics ·    │
                    │ Audit · Tenant&Subscription · Platform Admin                   │
                    │   each: Api facade · services · repositories · events          │
                    ├────────────────────────── Platform kernel ─────────────────────┤
                    │ core/: Database, Migrations, Outbox/Events, Jobs, Idempotency, │
                    │ PhoneNumber, CronGuard, LoginThrottle, Cache, Log, Crypto      │
                    └───────────────────────────────┬────────────────────────────────┘
                                                    │
          ┌──────────────── tenant database ────────┴───────┐     ┌──── control plane DB (SaaS only) ─┐
          │ eb_* tables (+ CRM tables until merged)         │     │ tenants, domains, plans,         │
          └─────────────────────────────────────────────────┘     │ entitlements, migrations status  │
                                                                  └───────────────────────────────────┘
   Inbound: webhook/* → verify → tenant → dedupe → event        Outbound: Jobs/Queue → Channels connectors
   Scheduler: cron.php → for each tenant: jobs, outbox, timers, SLA, campaigns, polling
```

## 3. Code organisation (ADR-002)

| Location | Content | Rule |
|---|---|---|
| `core/` | Platform kernel (unchanged names; grows: `Migrations`, `Outbox`, `Jobs`, `Permissions`, `Log`) | No business logic |
| `src/<Context>/` **new** | Namespaced classes `Eb\<Context>\…`, autoloaded by a small hand-written PSR-4 loader in `core/Autoload.php` (no Composer) | Other contexts may use only `Eb\<Context>\Api` (facade) and `Eb\<Context>\Events\*`. Enforced by a static test that scans `use`/class references. |
| `modules/`, `channels/`, `api/`, `views/` | Legacy, kept running | New code does not grow them; when a legacy module is migrated, it becomes a thin adapter over the context API. |
| `extensions/<name>/` **new** | Optional packages, e.g. `extensions/jakojast/` | Manifest-declared hooks only (pages, API actions, migrations, event listeners, settings, seed). Commercial build excludes them (ADR-011). |
| `migrations/` **new** | Versioned migration files | ADR-005 |
| `tests/`, `tools/`, `docs/` | Dev only, never deployed | Already true |

## 4. Cross-cutting mechanisms

| Mechanism | Design | ADR |
|---|---|---|
| **Domain events** | Context writes its state change **and** an `eb_outbox` row in one transaction. Dispatcher delivers to in-process listeners at end of request (after `fastcgi_finish_request` where available) and again from cron for anything left. Each listener records `(listener, event_id)` in `eb_outbox_consumed`, so redelivery is harmless. | ADR-008 |
| **Background jobs** | Generic `eb_jobs` table (type, payload, run_at, attempts, max_attempts, backoff with jitter, locked_by/until, last_error, dead). Claimed with `UPDATE … WHERE locked_until < NOW()`. Existing send queue (`eb_queue`) stays as-is and is wrapped later. | ADR-008 |
| **Idempotency** | Outbound provider calls: `core/Idempotency.php` scopes (done in 2.1.5). Inbound API writes: `Idempotency-Key` header stored in `eb_idempotency` (key, request hash, response, 24 h). | ADR-009 |
| **Authorization** | Permission keys `context.resource.action`, roles as key sets, scopes (org unit / project / board / own). Page grants map to keys for backward compatibility. Checked server-side in the gate (coarse) and in services (record level). | ADR-006 |
| **Tenancy** | Jakojast and self-hosted Commercial: single tenant, as today. Hosted SaaS: **database per tenant** + control plane; tenant resolved from Host header only. ⚑ | ADR-004 |
| **Migrations** | `eb_migrations` table + versioned files with `up()`, `verify()`, optional `down()`; current schema adopted as baseline `0001`; `SchemaV4::ensure()` per request retired after baseline. | ADR-005 |
| **Time & locale** | Store `DATETIME` in UTC (or `DATE`), never Jalali strings; tenant setting for timezone, calendar, locale; Jalali rendering in presentation. Existing string-date columns migrated expand–contract. | ADR-012 |
| **Logging** | One `Log` helper: JSON lines with `request_id`, tenant, user id, context, event; redaction of tokens, passwords, message bodies, phone numbers (last 4 only). `X-Request-Id` on every response. | in `security-architecture.md` |
| **Files** | Storage abstraction `Files` context: tenant-prefixed paths `uploads/<tenant>/…`, metadata table, MIME sniffing, size limits, served through `media.php` with permission check. | `domain-boundaries.md` |

## 5. Editions (ADR-011)

| Edition | Contains | Delivery |
|---|---|---|
| **Jakojast** | Core + `extensions/jakojast` (hosts, properties, settlements, site sync, property lookup, Jakojast prompts/seed, lodging presets) | Current server, single tenant |
| **Commercial — self-hosted** | Core only, generic presets and demo data | Licensed zip per company (today's model, `modules/License.php`) |
| **Commercial — hosted SaaS** | Core + control plane | Operator VPS (not shared hosting); DB per tenant ⚑ |

Purity is enforced by a build script that assembles the Commercial zip without `extensions/jakojast` and **fails** if a term scan (Jakojast/lodging vocabulary list) finds matches in code, schema, seeds, UI strings or docs.

## 6. What changes for users now

Nothing in Milestone 1: it is design only. The first code that follows (M2) is additive: new `src/` layer, migration runner, outbox, permission service, and the Work context, without touching existing flows.

## 7. Related documents

`domain-boundaries.md` · `data-ownership.md` · `tenancy-strategy.md` · `security-architecture.md` · `permission-matrix.md` · `../api/api-overview.md` · `../api/error-contract.md` · `../api/webhooks.md` · `../api/connector-contracts.md` · `../database/migration-plan.md` · `adr/` · `../product/roadmap.md` · `decisions-for-owner.md`
