# Risks and Blockers

Severity: Critical / High / Medium / Low. "Action" items that change production behaviour need **owner approval** (master prompt §0.1-8, §2.5) and are not applied in Milestone 0.

## 1. Risk register

| ID | Risk | Evidence | Sev. | Proposed action |
|---|---|---|---|---|
| R-01 | **Live production secrets and customer data were distributed in the uploaded archive**: `config.php` (DB password, `app_key`, `jakojast_integration_token`, `jakojast_outgoing_token`, `analytics_license_secret`), `uploads/` (~15 MB customer media), `crm/logs/error.log` (2.4 MB), cache files. | archive contents | **Critical** | Treat as exposed. Rotate DB password, Jakojast tokens and the cron key. Rotating `app_key` re-keys encrypted channel settings, the CRM bridge HMAC key and `APP_SECRET`, so it needs a planned re-encryption step. Ship future archives without `config.php`, logs, uploads and caches (a `.gitignore` is now in place locally). |
| R-02 | Broken access control in panel API: write actions callable by any logged-in operator regardless of page grants (P-01), incl. bulk send to all customers. | `permission-inventory.md` P-01 | **High** | Add a central action→permission map enforced in `index.php` before `api/handler.php`, default-deny for unmapped write actions. |
| R-03 | Telefonchy call webhook accepts unauthenticated events (token mismatch only logged) → forged calls/leads. | `crm/webhook.php` header comment; P-12 | **High** | Find out why real events failed the token check (compare the raw `webservice_token` against the stored value; likely a different token per service), then enforce. Optional interim: allowlist Telefonchy source IPs. |
| R-04 | `Idempotency-Key` is random per request for YarAPI and RoboClick → no protection against duplicate external sends on retry/redelivery. | `InstagramApichiDriver.php` l.227; `InstagramRoboclickDriver.php` l.312, 363 | **High** | Derive keys from business identity (`reply-{eventId}`, `send-{sendId}-{personId}`, `ig-auto-{ruleId}-{commentId}`) and pass them from callers. |
| R-05 | Three inconsistent phone normalisers → mismatched identities, duplicate leads, failed CRM linkage. | `evidence/phone-normalizer-comparison.md` | **High** | Single shared `PhoneNumber` utility (canonical E.164 + display `09…`), used by Elanban, CRM sync, lead conversion, imports; report-only scan of existing data before any back-fill. |
| R-06 | Cron endpoints run unauthenticated when no key is set; non-constant-time comparison. | `cron.php` l.58, `crm/cron_sync.php` l.24 | Medium | Fail closed for HTTP when key empty; `hash_equals`. |
| R-07 | No automated tests, CI, or known staging environment; prior verification was bracket counting and regex. | repo | **High** | Introduce PHPUnit (dev-only, outside deploy package) with DB-less unit tests first, then an integration suite against a disposable MySQL. |
| R-08 | Imperative migrations run on every request (`SchemaV4::ensure()`); no versioning or rollback. | `index.php` l.31 | Medium | Versioned migration table + explicit upgrade step; keep idempotent ensure as fallback until cut-over. |
| R-09 | Customer conversations are sent to external AI providers (OpenAI, OpenRouter, Groq, AvalAI, GapGPT). | `modules/Ai.php`, `SalesAgent.php` | Medium (privacy) | Document the data flow; require explicit tenant-level opt-in per provider (§15, §17.2). |
| R-10 | RoboClick endpoints used in code but absent from supplied doc (`SendShowcase`, `UploadAudio`, `SendPost`, `SendStory`); grace-period forced branding not handled. | `integration-inventory.md` §2.2 | Medium | Obtain the missing doc tabs; until then mark these operations "unverified". |
| R-11 | No tenancy anywhere (132 tables, file cache, uploads, cron) → Commercial multi-tenant edition is a cross-cutting change. | `database-inventory.md` | **High (scope)** | Decide tenancy model early (ADR in Milestone 1); recommended: database-per-tenant for the existing codebase, which keeps the single-company assumption valid inside each DB. |
| R-12 | Jakojast/lodging coupling: 106 files mention Jakojast (439 lines); 66 PHP files contain lodging terms (host/property/reservation); version suffix `-jk`. | grep in session | High (Commercial) | Extract Jakojast extension (`crm/` hosts, site integration, `JakojastLookup`, `PropertyLookup`, seed data) behind an extension boundary. |
| R-13 | Panel session cookies lack `httponly/secure/samesite`; no login throttling. | P-05, P-06 | Medium | Set cookie params in `Auth::start`; add attempt counter per user+IP. |
| R-14 | HR data (salaries, national codes) protected only by a module-level permission in a shared DB/user model. | P-11 | Medium | Record-level + department scoping; audit reads of payroll. |
| R-15 | Duplicated subsystems: 2 customer masters, 2 user tables, 3 org hierarchies, ≥6 rule engines, 4 Instagram providers. | `module-inventory.md` | Medium (architecture) | Consolidate logically in Milestones 1/4/6 with dry-run migrations; no physical merges without approval. |
| R-16 | Jalali dates stored as strings in CRM (`VARCHAR(20)`). | DB-05 | Medium | Add proper `DATE`/`DATETIME` columns alongside, back-fill, switch readers, then retire. |
| R-17 | `-jk` build is the only build; working copy and production may differ (patches 2.0.x–2.1.1 hand-applied). | `PATCH-*.md` | Medium | Confirm the archive equals production before deploying anything derived from it. |

## 2. Blockers

| ID | Blocker | Needed from owner |
|---|---|---|
| B-01 | `https://telefonchy.com/help` unreachable (session network policy denies the host). | Allow `telefonchy.com` (environment → Network access → Allowed domains), or upload the page/PDF. The local `crm/docs/TELEFONCHY_API.md` is being used meanwhile. |
| B-02 | No database access → DB baseline (row counts, sizes, slow queries, EXPLAIN, backups) impossible. | A sanitised staging dump (schema + anonymised data), or read-only staging credentials. Never production. |
| B-03 | No provider credentials or sandboxes (Bale, Telegram, Rubika, SMS, SMTP, voice, YarAPI, RoboClick, Telefonchy). | Test accounts/keys restricted to a **test page / test number** (e.g. YarAPI key restricted to one test `igAccountId`). |
| B-04 | "RAPI" not identified. | Confirm whether RAPI = YarAPI; otherwise provide its documentation. |
| B-05 | RoboClick doc incomplete (tabs "Account keys", "Webhook log", "Guide"; schemas for `CreateFollowRequiredAutomation`, and the 4 endpoints above). | Those pages. |
| B-06 | Delivery target unknown: no git remote; previous practice was zip hand-off. | Confirm: zip deliverables, or a GitHub repository to push to. |
| B-07 | Production environment facts unknown (PHP/MySQL versions, hosting type, shell/cron access, HTTPS, backups). | Hosting details or a staging server. |
| B-08 | Approval decisions (see §3). | Owner decisions. |

## 3. Decisions requiring owner approval

1. **Secret rotation now** (R-01) — which secrets, and the maintenance window for `app_key` re-encryption.
2. **Security hotfix batch** (R-02, R-03, R-04, R-06, R-13) before feature work. Each changes production behaviour; R-03 may reject events until the token issue is understood.
3. **Tenancy model** for the Commercial Edition (R-11).
4. **Canonical customer master**: `eb_persons` (recommended, with CRM `customers`/`leads` becoming CRM-owned profiles that reference it) vs CRM `customers`.
5. **Instagram provider strategy**: keep 4 adapters, or standardise on YarAPI (which the Oct 2026 guide positions as the single public API) and freeze the others.
6. **Language of internal documentation** (current project docs are Persian; master prompt is English).

## 4. Status after patch 2.1.5 (2026-11-04)

| ID | Status | Evidence |
|---|---|---|
| R-02 | **Fixed** — central API/export authorisation (`core/ApiPolicy.php`, generated map) | 9 unit + 6 integration tests; blocking tests fail on the pre-fix commit |
| R-03 | **Fixed** — Telefonchy webhook enforced (`TelefonchyWebhookAuth`), monitor escape hatch | 9 unit + 8 integration tests; forged-event tests fail on the pre-fix commit |
| R-04 | **Fixed for YarAPI/RoboClick** at the 4 retry points (`core/Idempotency.php`); Bale/Telegram/Rubika/SMS have no provider idempotency → still open | 10 unit tests; live provider behaviour on key reuse with a different body is undocumented |
| R-05 | **Fixed** — `core/PhoneNumber.php`; Elanban merge key output identical to legacy (differential test) | 10 unit tests + end-to-end webhook test (fails on baseline with `989121234567`) |
| R-01, R-06, R-13, P-03, P-04 | Open — next batch / owner decision | — |
| R-07 | **Partly addressed** — `tests/run.php` (unit, no DB) and `tests/integration/run.php` (disposable sandbox) exist; no CI yet | — |

## 5. Status after patch 2.1.6 (2026-11-04)

| ID | Status | Evidence |
|---|---|---|
| **R-18 (new, High)** | **Fixed** — `GET /crm/api/settings` returned all CRM secrets (Telefonchy token, SMS key/password, site DB password, AI keys) to non-admins with "all sections" and to the read-only viewer; CRM settings page rendered them; `sms/send` sent arbitrary SMS. CRM `settings` module now admin-only. **Exposure cannot be ruled out → rotate those secrets.** | 8 integration tests; reproduced on the pre-fix commit with live requests |
| R-06 / P-13 | **Fixed** — `core/CronGuard.php`; dashboard "cron running" step | 6 unit + 6 integration tests |
| R-13 / P-05 / P-06 | **Fixed** — cookie flags, strict mode, login throttling | 6 unit + 4 integration tests |
| P-04 | **Fixed** — role/permissions/is_active reloaded per request | 3 integration tests |
| P-03 | **By design** (documented "all sections" option); empty "selected only" trap blocked | Playwright browser test |
| P-09 | Same design as P-03 in the bridged CRM; the dangerous part (admin-only CRM settings) fixed under R-18 | — |
| P-10 | **Fixed** — unmapped CRM resources deny by default | all 32 routes verified mapped/open |
| Still open | R-01 (+ CRM secrets above), R-04 remainder (non-Instagram channels), R-09 (AI data flow consent), R-14 (record-level HR scoping), R-08/R-16 (migrations, string dates) | — |
