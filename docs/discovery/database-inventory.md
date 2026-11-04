# Database Inventory

> **Static inventory only.** No database connection was available in this session, so row counts, sizes, index usage, slow-query samples, lock data and backup/restore status (master prompt §11.1) are **not captured** — blocker B-02. Table lists below were extracted from DDL in `core/Schema.php`, `core/SchemaV4.php`, `modules/*.php`, `install.php`, `crm/db.sql`, `crm/db_extra.sql`, and CRM self-migrations.

## 1. Summary

| Item | Value |
|---|---|
| Engine | MySQL/MariaDB, InnoDB, utf8mb4 (from DDL) — version unknown |
| Databases | **One** shared database for Elanban core and CRM (`crm/config.php` reuses parent `db` config) |
| Elanban tables (`eb_` prefix) | 103 |
| CRM tables (no prefix) | 29 (+ tables created at runtime by models) |
| Foreign keys | Elanban: **1** (`core/Schema.php`); CRM: 16 (`crm/db.sql`) |
| Migration mechanism | Idempotent `CREATE TABLE IF NOT EXISTS` + 85 `addColumn` calls; `SchemaV4::ensure()` executes on **every** panel request; CRM models self-migrate (`ensure*Columns`). No migration version table, no rollback scripts. |
| Tenant discriminator | **None** on any table |
| Seed data | `seed_marketing.sql` (61 KB, 104 Jakojast references — Jakojast content calendar) |

## 2. Elanban tables by domain

**Identity & customers** (9): `eb_crm_links`, `eb_groups`, `eb_identities`, `eb_person_groups`, `eb_person_tags`, `eb_persons`, `eb_properties`, `eb_tags`, `eb_visitors`

**Users, roles, org, audit** (12): `eb_audit`, `eb_bot_settings`, `eb_form_sessions`, `eb_ig_page_settings`, `eb_mkt_roles`, `eb_roles`, `eb_sessions`, `eb_settings`, `eb_sites`, `eb_teams`, `eb_users`, `eb_webchat_sessions`

**Conversations & inbox** (12): `eb_assign_log`, `eb_canned_replies`, `eb_chat_presence`, `eb_conv_events`, `eb_conv_index`, `eb_conv_state`, `eb_csat`, `eb_followup_log`, `eb_messages`, `eb_notifications`, `eb_team_messages`, `eb_webchat_outbox`

**Sending, queue, campaigns, templates** (14): `eb_campaign_runs`, `eb_campaign_steps`, `eb_campaigns`, `eb_link_clicks`, `eb_links`, `eb_notice_leads`, `eb_notices`, `eb_queue`, `eb_queue_jobs`, `eb_scheduled`, `eb_send_targets`, `eb_sends`, `eb_sms_dlr`, `eb_templates`

**Automation & bots** (8): `eb_auto_replies`, `eb_bot_nodes`, `eb_events`, `eb_flow_runs`, `eb_flows`, `eb_form_responses`, `eb_forms`, `eb_webhook_events`

**Instagram** (11): `eb_ig_accounts`, `eb_ig_comment_rules`, `eb_ig_comments`, `eb_ig_gate_pending`, `eb_ig_oauth_pending`, `eb_ig_order_items`, `eb_ig_orders`, `eb_ig_post_knowledge`, `eb_ig_posts`, `eb_ig_products`, `eb_ig_rule_fires`

**AI / sales agent / KB** (11): `eb_agent_callbacks`, `eb_agent_kb_chunks`, `eb_agent_kb_docs`, `eb_agent_log`, `eb_agent_orders`, `eb_agent_profiles`, `eb_agent_security`, `eb_agent_state`, `eb_agent_surveys`, `eb_ai_knowledge`, `eb_ai_usage`

**Marketing & content** (8): `eb_mkt_budgets`, `eb_mkt_content`, `eb_mkt_content_crew`, `eb_mkt_content_steps`, `eb_mkt_goals`, `eb_mkt_members`, `eb_mkt_metrics`, `eb_mkt_pages`

**Website analytics & SEO** (11): `eb_fab_clicks`, `eb_funnel_hits`, `eb_funnels`, `eb_gsc_alerts`, `eb_gsc_cache`, `eb_gsc_daily`, `eb_gsc_urls`, `eb_heatmap_grid`, `eb_pageviews`, `eb_problem_spots`, `eb_stats_daily`

**Integrations, ChatPlus (cards/units/IVR) & property lookup** — `eb_property_lookup_pending` is Jakojast-specific; others generic (7): `eb_api_connectors`, `eb_api_log`, `eb_cp_cards`, `eb_cp_ip_allow`, `eb_cp_ivr_calls`, `eb_cp_units`, `eb_property_lookup_pending`

## 3. CRM tables (`crm/db.sql`, `db_extra.sql`)

| Domain | Tables | Generic or Jakojast-specific |
|---|---|---|
| Auth | `users`, `auth_tokens` (token stored as hash) | generic — now bridged to `eb_users` |
| Customers & leads | `customers` (has `reservations`, `total_spend`, Persian `level` values), `customer_notes`, `leads` (Persian stage strings), `lead_status_history`, `lead_references` | mixed: structure generic, values Jakojast/lodging |
| Hosts / properties (lodging) | `hosts`, `host_properties`, `host_property_images`, `host_settlements`, `host_leads` | **Jakojast-specific** |
| Site integration | `site_inquiries`, `site_sales` | Jakojast-specific (main website) |
| Calls | `calls`, `call_numbers`, `call_number_stage_log`, `telefonchy_events`, `telefonchy_api_logs` | telephony-provider-specific |
| Work | `tasks`, `projects`, `tickets` | generic but minimal (see §4) |
| HR | `staff`, `staff_reports`, `staff_evaluations`, `attendance`, `leaves`, `salaries` | generic HR (sensitive: salary, national code) |
| Misc | `chat_messages`, `settings`, `elanban_api_logs` | generic |

## 4. Structural findings

| ID | Finding | Evidence | Impact |
|---|---|---|---|
| DB-01 | Two customer masters: `eb_persons` vs `customers`/`leads`/`host_leads`, linked via `eb_crm_links` and phone | DDL | duplicate mutable master data (violates §3.3) |
| DB-02 | Two user tables: `eb_users` and CRM `users` (CRM now authenticates via `eb_users`; CRM `users` retained) | `crm/config.php` `CRM_BRIDGED` | stale identity data risk |
| DB-03 | `tasks.assignee`, `leads.assignee`, `projects.members` store **names as free text/JSON**, not user IDs | `crm/db.sql` | renames break ownership; no FK; prior review §2.2 |
| DB-04 | `tasks.subtasks`, `tasks.comments` stored as JSON columns | `crm/db.sql` | no history, no concurrency control, not queryable |
| DB-05 | Dates stored as Jalali strings in `VARCHAR(20)` (`start_date`, `end_date`, `deadline`, `next_contact`, `join_date`, `birthday`, `last_purchase`) | `crm/db.sql` | non-sargable, unsortable across year boundaries, locale baked into data |
| DB-06 | Phone stored un-normalised in CRM (`leads.mobile`, `customers.mobile`) by three different normalisers | `evidence/phone-normalizer-comparison.md` | duplicates / failed matching |
| DB-07 | Elanban schema has only one FK | `core/Schema.php` | orphan rows possible; integrity in code only |
| DB-08 | Schema ensure on every request | `index.php:31` | latency on every request (unmeasured) |
| DB-09 | Sensitive HR data (`salaries`, `staff.national_code`, `base_salary`) in the same DB and same user model as marketing/support data | `crm/db.sql` | needs strict permission scoping (§10) |

## 5. Pending (blocked) baseline items

Row counts, table/index sizes, slow query log, `EXPLAIN` of key paths, lock waits, backup schedule, last successful restore test — all require read-only access to a staging copy of the production database (B-02).
