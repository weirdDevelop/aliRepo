# Tenancy Strategy (ADR-004) ⚑

> Master prompt §12.3. Status: **Proposed — requires owner approval.**

## 1. Facts that decide it

| Fact | Evidence |
|---|---|
| No table has a tenant column; the code assumes one company per database everywhere | 103 `eb_*` + 29 CRM tables (`database-inventory.md`) |
| 1,485 Elanban and 661 CRM database call sites; 1,611 SQL strings naming `eb_*` tables | scan, M1 |
| File cache, uploads, logs and cron are per-installation | `core/Cache.php`, `uploads/`, `cron.php` |
| A discriminator retrofit has already been tried once and stayed incomplete | 2.1.1 multi-site added `site_id` in 9 files; its own notes (`PATCH-2.1.1.md`, "محدودیت‌ها") list exports, heatmaps, funnels, visitors, AI knowledge and IVR still unscoped |
| Target hosting for customers is shared cPanel | `install.php` |
| Existing commercial model is "one install per company" with a licence | `modules/License.php`, `cli/analytics_gen_license.php` |

## 2. Options

| | A. Shared DB, `tenant_id` column | B. Schema per tenant | C. **Database per tenant** + control plane | D. Install per tenant (status quo) |
|---|---|---|---|---|
| Code change | Every table, index, query, cache key, file path, job, export — ~3,000 sites | MySQL schema = database, so same as C | Connection selection at bootstrap; cache/uploads/log paths prefixed; cron iterates tenants | None |
| Isolation strength | Weakest: one missing `WHERE` leaks; the `site_id` retrofit already left areas unscoped | = C | Strong: wrong tenant requires wrong connection; queries cannot cross DBs | Strongest |
| Per-tenant backup/restore/export/delete | Hard (row filtering) | = C | Native `mysqldump` per DB | Native |
| Migrations | One run | = C | Run per tenant DB, tracked in control plane | Per install, manual |
| Ops cost at 10–500 tenants | Lowest | = C | Moderate (N databases, one codebase) | High (N codebases to patch) |
| Noisy neighbour | Shared | = C | Shared server, separate DBs (can move a heavy tenant to its own server) | Separate |
| Works on shared hosting | Yes | — | Operator needs DB-creation rights → VPS/dedicated for SaaS mode | Yes |
| Cross-tenant analytics for operator | Easy | — | Via control-plane usage meters only (by design) | None |

## 3. Decision

* **Jakojast edition:** single tenant, unchanged.
* **Commercial self-hosted:** single tenant per install (D) — keeps today's licence model and shared-hosting compatibility.
* **Commercial hosted SaaS:** **database per tenant (C)** with a small control-plane database. The tenant code path is the same code as self-hosted; the only difference is how the connection is chosen.

Option A is rejected: largest change, weakest isolation, and the `site_id` retrofit showed how hard complete coverage is here (for sites that was an accepted limitation; for tenants it would be a data breach).

## 4. Design of option C

**Control plane DB** (separate credentials, never reachable from tenant code paths):
`cp_tenants (id, slug, status, db_host, db_name, db_user, db_pass_enc, created_at)` · `cp_domains (host, tenant_id, verified)` · `cp_plans`, `cp_entitlements`, `cp_subscriptions`, `cp_usage (tenant, meter, period, value)` · `cp_migrations (tenant_id, migration, applied_at, status)` · `cp_platform_admins`, `cp_audit`.

**Tenant resolution** (in `index.php`, `webhook/*`, `chat.php`, `r.php`, `media.php`, every entry point, via one `core/Tenant.php`):
1. Take the `Host` header → `cp_domains` (cached 60 s) → tenant. Unknown host → 404. Suspended tenant → 402 page.
2. Load that tenant's DB credentials → `Database::init()`.
3. **Never** accept a tenant id from query string, body, cookie or webhook payload (master prompt §8.3).
4. Session cookie scoped to the host; session data includes the tenant id and is rejected if it does not match the resolved tenant.

Webhook URLs are per-host (`https://<tenant-host>/webhook/...`), so provider callbacks resolve the tenant the same way.

**Files and caches:** `uploads/<tenant_slug>/…`, `logs/<tenant_slug>/…`, cache keys prefixed with tenant slug (`core/Cache.php` dir per tenant). `media.php` refuses paths outside the tenant prefix (path traversal test).

**Cron:** one system cron runs `cron.php --all-tenants`; it iterates active tenants with a time budget and a per-tenant `GET_LOCK`, round-robin from where the previous run stopped. Large tenants can get a dedicated cron line.

**Migrations:** runner applies pending migrations tenant by tenant, records status in `cp_migrations`, stops on first failure for that tenant and continues others; a release is "done" when all tenants are at the target version.

**Entitlements:** `Entitlements::allows()/limit()` read the tenant's plan from the control plane (cached per request). In self-hosted mode the same API reads the licence.

**Provisioning:** create DB + user → run migrations from 0001 → create owner admin → write `cp_tenants` → domain verification → activation. Deprovisioning: suspend → export bundle → retention period → drop DB (approval + audit).

## 5. Isolation test plan (master prompt Scenario G)

Tenant A and B with identical usernames and record ids: attempt cross access through UI, panel RPC, REST, exports, search, jobs (cron), cache, files (`media.php?path=../B/...`), webhooks (A's secret on B's host), session cookie replay across hosts. All must fail; run in CI on the sandbox (which already supports throwaway databases).

## 6. Consequences

* Immediate work for M9 is bounded: `core/Tenant.php`, entry-point bootstraps, path prefixes, cron loop, migration runner per tenant, control plane, onboarding.
* Nothing in M2–M8 needs tenant columns. New tables follow one rule: **no global singletons outside the tenant DB** (e.g. no shared uploads path in code).
* SaaS operations need a VPS; shared hosting remains supported for self-hosted customers.
