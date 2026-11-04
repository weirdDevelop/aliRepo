# Gap Analysis (summary)

Full requirement-by-requirement mapping: `requirements-traceability.csv` (63 rows). Status vocabulary per master prompt §2.2. **Nothing is classified "verified"**: no runtime, DB or provider access existed in this session.

## 1. By capability area

| Area | What exists (reusable) | Main gaps | Size of gap |
|---|---|---|---|
| **Task & project mgmt (Phase 1)** | CRM `tasks`/`projects` CRUD + kanban view; marketing content kanban; org-role tree; CRM `staff` with supervisor | Real org model (dept/team membership, effective dates); boards; configurable states; history; dependencies; approvals; routing; SLA calendars; workload. Assignees are names not IDs. | **Large — mostly new build**, reusing `eb_users`, `eb_roles`, notification pipeline |
| **CRM & identity (Phase 2)** | Golden identity `eb_persons`/`eb_identities`; CRM leads/pipeline/history; Telefonchy calls→leads; customer profile & timeline | One customer master; consistent phone normalisation; review queue & unmerge; segment builder; scoring; CRM→central task link | **Medium** — consolidation more than new code |
| **Omnichannel inbox** | Desk/inbox, assignment, presence, canned replies, CSAT, 9 channels | SLA/shift backend (front-end only), uniform delivery states, collision prevention (unverified) | **Medium** |
| **Notification (Elanban)** | Mature sender/queue/campaign/scheduler/templates/DLR | Idempotency keys, consent & opt-out, quiet hours, frequency caps, terminal-state tracking for IG | **Small–medium** |
| **Instagram / YarAPI / RoboClick** | Full YarAPI adapter matching the guide; RoboClick adapter; shared pipeline; verified-signature webhooks with dedupe | Stable idempotency; async status tracking; undocumented RoboClick calls; provider strategy | **Small** |
| **Automation** | Six domain-specific engines | One rule model with versioning, dry-run, loop prevention, kill switch, audit | **Large** (consolidation) |
| **Security** | CSRF, prepared statements (spot-checked), hashed passwords/tokens, viewer role, webhook HMAC | Central API authorisation (P-01), Telefonchy webhook auth, cookie flags, throttling, MFA, secrets out of archive | **High priority, moderate effort** |
| **Database** | Idempotent schema code, utf8mb4 | Baseline metrics (blocked), FKs, versioned migrations, string dates, duplicated masters | **Medium** (blocked on data) |
| **Commercial Edition** | Brand module, licence module, installer | Tenancy, entitlements, onboarding, Jakojast extraction (106 files) | **Large** |
| **Quality & ops** | Rich changelogs | Tests, CI, staging, observability, backup/restore evidence | **Large** |

## 2. Recommended order (adjusted from master prompt §20)

0. **M0 Discovery** — this package. ✅ (DB metrics pending B-02)
0.5 **Security hotfix batch** *(needs approval)* — R-01 rotation plan, R-02 central API authorisation, R-03 Telefonchy webhook, R-04 stable idempotency keys, R-05 shared phone utility, R-06 cron fail-closed, R-13 cookies/throttle. Each with PHPUnit tests runnable without a DB. Rationale: these are live-production defects; feature work on top of them multiplies risk.
1. **M1 Target architecture** — bounded contexts, data ownership (choose customer master), tenancy ADR, permission model, migration strategy, test/CI scaffold.
2. **M2–M3 Task management** — new `Work` context built on `eb_users`/org model; migrate CRM `tasks`/`projects` into it with a dry-run.
3. **M4–M7** — CRM consolidation, connector framework, rule engine, notification hardening.
4. **M8** — DB optimisation once a staging dump exists.
5. **M9–M10** — Commercial Edition and release.

## 3. What can proceed without any owner input

Unit-testable code that does not touch production data: the shared phone utility with tests, the API action→permission map (shipped disabled or in log-only mode), stable idempotency-key derivation, the test scaffold, and the M1 architecture documents.
