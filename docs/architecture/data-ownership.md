# Data Ownership

> Master prompt §3.3 / §11.2. One authoritative owner per entity. Status: Proposed. ⚑ = owner approval needed.

## 1. Ownership table

| Entity | Owner context | Authoritative store (target) | Today | Duplicates to resolve |
|---|---|---|---|---|
| User account, credentials | Identity & Access | `eb_users` | `eb_users` + CRM `users` (bridged via `users.eb_user_id`) | CRM `users` becomes a read model synced from `eb_users` (already mostly true, `crm/core/Auth.php fromElanban`); retire writes to it |
| Roles / permissions | Identity & Access | new `eb_perm_roles`, `eb_user_roles` | `eb_users.role` ENUM + `permissions_json` + `eb_roles.pages_json` | Migrate pages → permission keys (ADR-006) |
| Org units, membership, managers | Organization | new `eb_org_units`, `eb_org_memberships` | `eb_roles` (tree), `eb_teams`, CRM `staff.unit/supervisor_id`, `eb_mkt_roles` | Four hierarchies → one (§3) |
| Staff HR record (salary, national code, attendance, leaves) | CRM/HR sub-area (Jakojast extension until generic HR is scoped) | CRM `staff`, `salaries`, `attendance`, `leaves` | same | Links to `eb_users` via `staff.user_id`; record-level permissions (R-14) |
| **Person (customer identity)** | Customer Identity | `eb_persons` ⚑ | `eb_persons` and CRM `customers`/`leads`/`host_leads`, joined by last-10-digits phone (`eb_crm_links.phone10`) | CRM tables gain `person_id`; phone10 join retired (§2) |
| Channel identity | Customer Identity | `eb_identities` (UNIQUE channel+uid) | same | — |
| Lead & pipeline | CRM | CRM `leads` (+`person_id`), configurable stages table | `leads.stage` free Persian text | Stage table; values migrated |
| Customer business profile | CRM | CRM `customers` (+`person_id`) | `customers` with `reservations`, `total_spend` (lodging-flavoured) | Generic columns in core; lodging columns move to Jakojast extension table |
| Conversation / message | Conversations | `eb_conv_state`, `eb_messages`, `eb_conv_events` | same | — |
| Call record | CRM (telephony) | CRM `calls` (+`person_id`) | `calls.call_source/dest` phone strings | Add `person_id` resolved via PhoneNumber at ingest |
| Task / project | Work | new `eb_work_*` | CRM `tasks`/`projects` (JSON subtasks/comments, name-string assignees), `eb_mkt_content` kanban | ADR-007 migration |
| Template, campaign, send, delivery | Notifications | `eb_templates`, `eb_campaigns*`, `eb_sends`, `eb_send_targets`, `eb_queue*` | same | — |
| Automation rule | Automation | `eb_flows` (+ versions) | `eb_flows`, `eb_auto_replies`, `eb_bot_nodes`, `eb_ig_comment_rules`, followups, campaign steps | ADR-010 consolidation |
| Connector credentials | Channels | new `eb_connectors` (encrypted) | `eb_settings` keys, `eb_ig_accounts`, CRM `settings` (plaintext tokens — R-18) | Move secrets into one encrypted store |
| File metadata | Files | new `eb_files` | implicit paths in `eb_messages.media_path` etc. | Back-fill metadata |
| Audit record | Audit | `eb_audit` (extended) | `eb_audit`, `lead_status_history`, `eb_assign_log` (domain histories stay; Audit gets security events) | — |
| Tenant, plan, entitlement | Tenant & Subscription | control plane DB (SaaS) / licence (self-hosted) | `License.php` | — |
| Settings | owning context | per-context settings tables or namespaced `eb_settings` keys (`ctx.key`) | `eb_settings` (mixed), CRM `settings` | Namespace keys; secrets out (above) |

## 2. Customer master decision (ADR-003) ⚑

**Decision:** `eb_persons` is the master person record. CRM keeps its own *profiles* (lead, customer business data, calls) that **reference** `person_id`.

**Why not the CRM `customers` table:** `eb_persons` already is the cross-channel golden identity, with `eb_identities` for every channel and a merge procedure (`Persons::merge`). CRM customers exist only for people who called or bought, and lodging-specific columns are mixed in. Everything that receives messages already keys on `eb_persons.id`.

**Steps (expand–contract, no data loss):**
1. Add nullable `person_id` to `leads`, `customers`, `calls`, `host_leads`.
2. Back-fill: canonicalise phone with `PhoneNumber::iranMobile()` → match `eb_persons.phone` → set `person_id`. Unmatched rows stay NULL and appear in a report. **No person is auto-created or merged in this step.**
3. New writes set `person_id` at ingest (call sync, lead creation, conversion).
4. Readers switch from `eb_crm_links.phone10` to `person_id`.
5. When the report shows no readers left, `eb_crm_links` becomes a derived cache (or is dropped — separate approval).
Dry-run first on a staging copy; counts and samples go into `docs/database/migration-plan.md` evidence.

## 3. Organization hierarchy decision

Today there are four trees with different meanings:

| Structure | Meaning today | Target |
|---|---|---|
| `eb_roles` (OrgRoles) | Org chart **and** page permissions mixed (`pages_json`, `is_manager`) | Split: hierarchy → `eb_org_units`; permission sets → roles (ADR-006). Migration maps each node to a unit + a role. |
| `eb_teams` (Desk) | Inbox routing groups with members and a rule | Keep as **routing groups**; each may reference an org unit. |
| CRM `staff.unit` + `supervisor_id` | HR unit as free text; manager chain | Map distinct `unit` strings to units (report for owner review); supervisor → unit manager. |
| `eb_mkt_roles` | Marketing team tree | Becomes units of the marketing department, or remains marketing-local roles (decide during M2 with the marketing owner). |

## 4. Identifier rules

* Every entity: integer surrogate key; new contexts also get a stable public id (ULID string) for APIs and cross-context references.
* Cross-context references are typed: `{ "type": "crm.lead", "id": 123 }`, never a phone number or a name.
* Names are never keys (fixes CRM `assignee` free text, DB-03).

## 5. Data-quality items carried forward (need staging data, B-02)

Duplicate persons by canonical phone · CRM rows with unparseable phones · orphaned `eb_identities` (only one FK exists) · CRM `assignee` names with no matching user · Jalali date strings that fail to parse. Each gets a report-only script before any fix.

## 6. Status — 2.4.0 (2026-11-05)

Organization tables exist (`eb_org_units`, `eb_org_memberships`, `eb_org_delegations`, `eb_org_changes`). The four legacy hierarchies are **not** retired: `eb_roles` still drives page permissions, `eb_teams` still drives inbox routing, CRM `staff.unit` still drives CRM screens. The import copies them into org units on demand (preview first, idempotent, nothing merged or deleted). Retiring them is a later, approved step once routing and permissions read org units.
