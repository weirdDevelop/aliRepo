# Migration Plan (ADR-005)

> Status: Proposed. Master prompt §11.2, §0.1-8: no destructive production migration without verified backup, plan, approval and recovery path.

## 1. Mechanism

* Table `eb_migrations (id, name, checksum, applied_at, duration_ms, applied_by, status)`.
* Files `migrations/NNNN_<name>.php` returning `['up' => fn(PDO), 'verify' => fn(PDO): array, 'down' => fn(PDO)|null, 'destructive' => bool]`.
* Runner: `php tools/migrate.php [--dry-run] [--to=NNNN]` and an admin button (replaces "به‌روزرسانی دیتابیس"); takes `GET_LOCK('eb_migrate')`; stops at the first failure; writes evidence (row counts before/after, verify output) to `logs/migrations/`.
* `destructive => true` migrations refuse to run unless a backup marker newer than 24 h exists **and** an admin confirms; never run from cron.
* **Baseline `0001_baseline`**: marks the current schema (all `CREATE TABLE IF NOT EXISTS` + `addColumn` in `core/Schema*.php` and CRM self-migrations) as applied; `verify` checks every expected table/column exists. After baseline adoption, `SchemaV4::ensure()` stops running on every request (perf item DB-08) and becomes a fallback only when `eb_migrations` is missing.
* SaaS: same files, applied per tenant DB, status in control plane (`tenancy-strategy.md` §4).

## 2. Patterns

| Change | Pattern |
|---|---|
| New table / nullable column | Single additive migration |
| Rename / type change (e.g. Jalali string → DATE) | **Expand–contract:** add new column → back-fill in batches (job, resumable) → dual-write → switch readers → (separate, approved, destructive) drop old |
| Move data between contexts (CRM tasks → Work) | Copy with mapping table `(old_id, new_id)`, verify counts/checksums, keep source read-only for one release, then archive |
| Merge duplicates | Never in a migration. Report → review queue → per-case action with lineage |

## 3. Planned migrations (by milestone)

| # | Milestone | Migration | Destructive | Verification |
|---|---|---|---|---|
| 0001 | M2 | baseline adoption | no | all expected tables/columns present |
| 0002 | M2 | kernel tables: `eb_outbox`, `eb_outbox_consumed`, `eb_jobs`, `eb_idempotency`, `eb_files` | no | tables exist |
| 0003 | M2 | permissions: `eb_perm_roles`, `eb_perm_role_keys`, `eb_user_roles`; generate roles from current grants | no | effective-permission diff = 0 for every user |
| 0004 | M2 | org: `eb_org_units`, `eb_org_memberships`; import `eb_roles` tree, `eb_teams`, CRM `staff.unit` (report of unmatched strings) | no | node counts; every user's units listed in report |
| 0005 | M2 | work: `eb_work_projects`, `_boards`, `_states`, `_tasks`, `_task_users`, `_comments`, `_checklist`, `_dependencies`, `_links`, `_history`, `_time` | no | — |
| 0006 | M2 | copy CRM `projects`/`tasks` → work (assignee name → user id by exact `full_name`/`display_name` match; ambiguous/no match → unassigned + flagged); JSON subtasks/comments → rows | no (source kept) | counts equal; every source row has mapping; flagged list for owner |
| 0007 | M3 | workflow: definitions, versions, instances, approvals, SLA policies, calendars | no | — |
| 0008 | M4 | `person_id` on CRM `leads`, `customers`, `calls`, `host_leads` + back-fill | no | match-rate report; unmatched listed |
| 0009 | M4 | `eb_person_merges`, `eb_match_candidates`, `eb_consents` | no | — |
| 0010 | M4 | CRM date strings → `DATE/DATETIME` (expand only) | no | parse-failure report |
| 0011 | M5 | `eb_connectors`; move secrets from `eb_settings`/CRM `settings` (encrypted) | no (old keys blanked only after verify, separate step) | every connector health-checks |
| later | M8 | contract steps (drop old columns/tables) | **yes** — each needs approval | backup + restore test first |

## 4. Rollback

* Additive migrations: application rollback = previous code ignores new tables/columns; `down` optional.
* Copy migrations: source untouched → rollback = stop reading the new tables.
* Destructive: restore from the pre-migration backup (restore test required beforehand, master prompt §18.2).

## 5. Blocker

Dry-runs need a sanitised staging copy of production (B-02). Until then migrations are verified on the sandbox with synthetic data only, and that is stated in every patch note.
