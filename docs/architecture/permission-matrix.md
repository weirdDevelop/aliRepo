# Permission Model and Matrix (ADR-006) ⚑

> Master prompt §10.2–10.5. Status: Proposed.

## 1. Today (2.1.6)

* Fixed roles `admin | supervisor | operator | viewer` (`eb_users.role`).
* Per-user page list (`permissions_json.pages`, empty = all non-admin pages), customer-group restriction, `can_delete`, PII masking.
* Org-chart roles (`eb_roles.pages_json`, `is_manager`) add pages.
* Server enforcement: page ⇒ API actions via the generated map (`core/ApiPolicy.map.php`, 2.1.5); CRM module keys (`crm/api.php` gate, 2.1.6); record rules ad hoc (`Desk::canSee`, group restriction).

Pages are too coarse for the requested capabilities ("may view tasks of my department but approve only in my project").

## 2. Target model

```
permission key   = <context>.<resource>.<action>        e.g. work.task.approve
role             = named set of permission keys          (templates shipped, editable per tenant)
grant            = user × role × scope                   scope ∈ {tenant, org_unit(+descendants), project, board, own}
check            = can(user, key, subject?)               → role keys ∩ scope contains subject ∩ policy rules (ABAC)
```

* **Scopes** come from Organization (unit + descendants), Work (project/board membership) and ownership (`created_by`, `assignee`, `owner_id`).
* **ABAC policy rules** live in the owning context's service, declared next to the action: confidentiality level, workflow state ("cannot edit after Done"), separation of duties ("requester ≠ approver"), time-bound delegation.
* **Default deny.** Unknown key ⇒ deny. Admin role = all keys at tenant scope (but SoD rules still apply).
* **Backward compatibility:** each legacy page maps to a key `<page>.page.view` (and the 2.1.5 action map keeps working). Existing users are migrated by generating one role per distinct page set; no one gains or loses access in the migration (verified by a before/after effective-permission diff report).
* **Effective-permission preview** and **change history** (Audit) are part of the role editor.
* **Viewer** stays a special read-only flag (keys with `.view` only; writes blocked by `Database::$readOnly` as today).

## 3. Permission catalogue (initial)

| Context | Resource | Actions |
|---|---|---|
| identity | user, role, api_key, session | view, create, edit, deactivate, grant, revoke, impersonate |
| org | unit, membership, delegation | view, create, edit, archive, manage_members, delegate |
| work | project, board, task, comment, time, view | view, create, edit, archive, restore, assign, reassign, transfer, route, accept_route, approve, export, import, configure |
| workflow | definition, instance, approval, sla_policy | view, configure, transition, approve, reject, escalate |
| customer | person, identity, merge, consent | view, view_pii, edit, merge, unmerge, export |
| crm | lead, customer, pipeline, segment, score, call | view, create, edit, assign, convert, export, configure, listen_recording |
| conversation | conversation, message, note, saved_reply | view, reply, assign, reassign, close, delete_message, view_others |
| notification | template, campaign, send | view, create, edit, approve, send_bulk, cancel |
| automation | rule | view, create, edit, enable, dry_run, kill |
| channel | connector | view, configure, rotate_secret, test |
| analytics | report, dashboard | view, export, schedule |
| audit | log | view, export |
| hr (Jakojast ext.) | staff, payroll, leave, attendance | view, view_own, edit, approve |
| platform | settings, billing, branding | view, configure, manage_billing |

## 4. Role templates (master prompt §10.2) — initial key sets

✓ = granted at the stated scope · ○ = own records only · — = none. Scope column = default grant scope.

| Role template | Scope | work.task | work.task.approve | work.project.configure | crm.lead | customer.person.view_pii | customer.merge | conversation.reply | conversation.view_others | notification.send_bulk | automation.rule | channel.connector.configure | analytics.report | audit.log | hr.payroll | identity.role.grant | platform.settings |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Organization Owner | tenant | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Organization Administrator | tenant | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| Security Administrator | tenant | — | — | — | — | — | — | — | — | — | — | ✓ | — | ✓ | — | ✓ | ✓ |
| Auditor | tenant | view | — | — | view | — | — | — | view | — | view | view | ✓ | ✓ | — | — | view |
| Department Director | unit+desc | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ | — | view | — | ✓ | — | view | — | — |
| Department Manager | unit | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ | — | view | — | ✓ | — | — | — | — |
| Team Leader / Supervisor | team | ✓ | ✓ | — | ✓ | ✓ | — | ✓ | ✓ | — | — | — | ✓ | — | — | — | — |
| Project Manager | project | ✓ | ✓ | ✓ | — | — | — | — | — | — | — | — | view | — | — | — | — |
| Project Member / Employee | project/own | ○ | — | — | — | — | — | — | — | — | — | — | — | — | — | — | — |
| CRM Manager | tenant | view | — | — | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | — | — | — | — |
| CRM Operator / Sales | own+team | ○ | — | — | ○ | ✓ | — | ✓ | — | — | — | — | — | — | — | — | — |
| Support Supervisor | team | view | — | — | view | ✓ | — | ✓ | ✓ | — | — | — | ✓ | — | — | — | — |
| Support Agent | own | ○ | — | — | view | masked | — | ✓ | — | — | — | — | — | — | — | — | — |
| Integration Administrator | tenant | — | — | — | — | — | — | — | — | — | ✓ | ✓ | — | — | — | — | — |
| Read-only Analyst | tenant | view | — | — | view | masked | — | — | view | — | — | — | ✓ | — | — | — | — |
| External Collaborator | project | ○ | — | — | — | — | — | — | — | — | — | — | — | — | — | — | — |

The CTO / Architect / DBA job titles from §10.2 are **not** given data access by title (master prompt §1.2): they receive Security/Integration Administrator or Auditor templates as needed; production DB access stays outside the application.

## 5. Separation-of-duties rules (enforced in services)

1. A user cannot approve their own request (`approval.requested_by ≠ approver`).
2. A user cannot grant themselves a role, nor grant keys they do not hold.
3. Destructive data operations (bulk delete, merge > N records, unmerge) require a second administrator's approval.
4. Impersonation: time-limited (≤ 60 min), banner visible, every action audited with both identities; cannot impersonate an administrator (already enforced in `Auth::impersonate`).

## 6. Migration from pages (M2)

1. Create keys `<page>.page.view` for the 28 panel pages and CRM modules.
2. For each distinct `(role, pages, groups, can_delete)` combination in `eb_users` and each `eb_roles` node, create a role with exactly those keys.
3. Grant at tenant scope (matches current behaviour).
4. Effective-permission diff: for every user, old `Auth::canAccess` per page vs new `can()` must be identical, or the migration aborts.
5. `ApiPolicy` keeps its generated map; the generator emits keys instead of pages.
