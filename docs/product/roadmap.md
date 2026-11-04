# Implementation Plan (Milestones 2–10)

> Status: Proposed. Each increment ships as a patch zip (`PATCH-x.y.z.md` + zip, same format as 2.1.5/2.1.6) with unit + sandbox integration tests, a before/after test run where it fixes behaviour, and rollback notes. Sizes are relative (S < M < L), not calendar estimates.
> Ordering principle: kernel first, then the context the owner needs most (tasks), then consolidation of what already exists.

## M2 — Kernel + Work foundation

| Increment | Content | Size | Exit test |
|---|---|---|---|
| 2.2.0 kernel ✅ delivered 2026-11-04 (`PATCH-2.2.0.md`) | `src/` PSR-4 loader + boundary test; migration runner + baseline 0001; `eb_outbox` + dispatcher; `eb_jobs` worker in cron; `eb_idempotency`; `Log` helper with request id; REST v2 front controller (`api.php/v2`) with envelope/errors | L | runner applies/verifies on sandbox; outbox at-least-once + idempotent listener test; boundary test fails on a planted violation |
| 2.3.0 permissions | permission keys, roles, scoped grants, generated roles from current grants, `can()`; ApiPolicy generator emits keys | M | effective-permission diff = 0 for seeded users; role editor UI |
| 2.4.0 organization | org units, memberships (effective dates), managers, delegation; import `eb_roles` tree + `eb_teams` + CRM `staff.unit` (report) | M | cycle prevention; import report reviewed by owner |
| 2.5.0 work core | projects, boards, states, tasks, assignees/watchers, comments (public/internal), checklists, attachments (Files), history, optimistic locking; Kanban + table + My Work views (SPA) | L | Scenario A minus approvals; concurrent edit → 409 |
| 2.6.0 CRM task migration | copy CRM `tasks`/`projects` → Work; CRM task page reads Work; create-task-from lead/customer/conversation | M | counts equal; flagged assignee list delivered |

## M3 — Enterprise workflow

| Increment | Content | Size | Exit test |
|---|---|---|---|
| 2.7.0 workflow engine | versioned definitions, guards (declarative, reusing Flows operators), required fields, transition permissions, history | L | unauthorized transition rejected server-side |
| 2.8.0 approvals | single/sequential/parallel/any/all/quorum, delegation, expiry, SoD | M | Scenario A complete; self-approval blocked |
| 2.9.0 routing | cross-department routing request → accept/reject/clarify → ownership | M | Scenario B |
| 2.10.0 dependencies, SLA, workload | FS/SS/FF/SF with cycle detection; SLA policies on business calendars; capacity/workload view | L | SLA math tests across holidays; cycle rejected |
| 2.11.0 task dashboards & reports | org/department/employee dashboards with documented metric definitions | M | aggregates reconcile with source rows |

## M4 — CRM foundation

| Increment | Content | Size | Exit test |
|---|---|---|---|
| 3.0.0 person link | `person_id` on CRM tables + back-fill + readers switched (ADR-003) | M | match report; unmatched never auto-created |
| 3.1.0 identity review | match candidates queue, merge with lineage, controlled unmerge, consent records | M | Scenario D |
| 3.2.0 lead lifecycle | configurable pipelines/stages, lost reasons, conversion preserving history, ownership history | M | conversion keeps lead row and links customer |
| 3.3.0 timeline | unified customer timeline from events (messages, calls, tasks, stage changes, merges) | S | each item has source, actor, timestamp, ref |

## M5 — Connectors & inbox

| Increment | Content | Size | Exit test |
|---|---|---|---|
| 3.4.0 connector registry | `Connector` interface, registry, encrypted credentials (moves secrets out of `eb_settings`/CRM `settings`), health page | L | every existing channel health-checks in sandbox fake server |
| 3.5.0 YarAPI first | YarAPI connector on the new contract; delivery-state tracking via `direct.status_changed`/`direct-requests`; 24 h window rule surfaced in UI | M | fixture contract tests; **live test only with owner-provided test page key** |
| 3.6.0 inbox upgrades | SLA clocks backend, collision prevention, handover notes, delivery states shown only as reported | M | collision test; no inferred "delivered" |

## M6 — CRM intelligence & automation

| Increment | Content | Size | Exit test |
|---|---|---|---|
| 3.7.0 classification & custom fields | categories, controlled vocabularies, field types, validation | M | — |
| 3.8.0 segment builder | nested AND/OR/NOT JSON tree → validated query builder, preview counts, static snapshots | L | Scenario E; injection tests |
| 3.9.0 rule engine consolidation | Flows: versions, dry-run, kill switch, quotas, outbox triggers; migrate Followups and AutoReply | L | parity tests on recorded events |
| 3.10.0 explainable scoring | weighted, versioned, overridable scores with explanation | M | score explanation equals computed sum |

## M7 — Notifications consolidation

| 3.11.0 | consent/opt-out/quiet hours/frequency caps enforced before queueing; template provider validation; dead-letter UI; idempotency for non-Instagram channels where provider supports it, local dedupe otherwise | L | Scenario F |

## M8 — Database optimisation (needs staging dump, B-02)

| 3.12.0 | baseline metrics, slow-query capture, index proposals with EXPLAIN evidence, contract migrations (approved one by one), backup/restore test | M–L | before/after measurements; restore test record |

## M9 — Commercial Edition

| Increment | Content | Size | Exit test |
|---|---|---|---|
| 4.0.0 extension split | move Jakojast-specific code to `extensions/jakojast`; generic presets/demo data; purity scan in build | L | Scenario J (scan clean, clean install works) |
| 4.1.0 tenancy | `core/Tenant.php`, control plane, per-tenant paths/cache/cron/migrations, provisioning & onboarding | L | Scenario G (isolation) |
| 4.2.0 entitlements & white-label | plans, limits, usage meters, payment-provider adapter interface (no gateway assumed), branding per tenant | M | limits enforced; adapter contract tests |

## M10 — Final QA & release

Full regression, security review against ASVS L2 list, performance report from M8 data, documentation set (§21), deployment & rollback runbooks, release approval record.

## Dependencies

M2 kernel → everything. Permissions (2.3) → Work (2.5) and all later UI. Organization (2.4) → routing/approvals (M3). Person link (3.0) → identity review, timeline, segments. Connector registry (3.4) → notification consolidation (M7). Staging data (B-02) → M8 and any production data migration dry-run.

## Running items independent of milestones

R-01 secret rotation (owner) · remaining medium items (R-09 AI consent → M7; R-14 HR record scoping → with permissions 2.3) · CI pipeline (lint, unit, sandbox integration, policy-map check, purity scan) once a Git remote exists.
