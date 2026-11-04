# Decisions for the Owner (Milestone 1 exit)

Milestone 1 is complete when these are approved, changed, or rejected. Each has a recommendation; nothing below is implemented yet.

| # | Decision | Recommendation | If you choose otherwise | ADR |
|---|---|---|---|---|
| D1 | **Customer master record** | `eb_persons` (Elanban golden identity); CRM leads/customers/calls reference it by `person_id` | CRM `customers` as master means re-keying every channel identity and message — much larger change | 003 |
| D2 | **Tenancy for the Commercial Edition** | Self-hosted: one install per company (as today). Hosted SaaS: database per tenant + control plane, on a VPS | Shared DB + `tenant_id` touches ~3,000 query sites and is the weakest isolation | 004 |
| D3 | **Do you want a hosted SaaS at all**, or only self-hosted licences? | Plan for both; build SaaS (4.1.0) last | Self-hosted only removes the control plane and tenant routing work from M9 | 004 |
| D4 | **Permission model** | Permission keys + editable role templates + scopes; current users migrated with zero access change | Staying with page lists blocks per-project/per-department task rules | 006 |
| D5 | **Instagram provider strategy** | YarAPI primary; RoboClick supported (undocumented calls marked unverified); Directsaz and Meta Graph frozen | Keeping all four equal keeps four credential types and two undocumented APIs in scope | 013 |
| D6 | **What is "Jakojast-specific"** and leaves the Commercial core | Hosts, host properties/images/settlements, host leads, site inquiries/sales/site sync, property lookup, lodging presets/seed/prompts. **Stays core:** leads, customers, calls/telephony, tickets, tasks, HR basics | Tell me anything in the "stays core" list you consider Jakojast-only (or vice versa) | 011 |
| D7 | **HR data (payroll, national codes)** | Treat as a Jakojast extension until a generic HR scope is defined; record-level scoping in 2.3.0 | Making HR a Commercial feature adds a module to M9 | 011 |
| D8 | **Order of work after M1** | M2 kernel → permissions → organization → tasks (your Phase 1), then CRM | If CRM consolidation is more urgent than tasks, swap M2.4–M3 with M4 | roadmap |
| D9 | **Staging data** | Provide a sanitised dump (or let me write the sanitising script for you to run on the server) | Without it, every data migration is verified on synthetic data only (stated in each patch) | migration-plan |
| D10 | **Secret rotation (R-01, R-18)** | Rotate now: DB password, Jakojast tokens, cron key, CRM-stored Telefonchy/SMS/site-DB/AI keys; `app_key` with planned re-encryption | Every month of delay keeps exposed values valid | security-architecture |

Reply with the numbers you approve as-is, and changes for the rest.
