# Permission Inventory

## 1. Elanban core (`core/Auth.php`, `core/Viewer.php`, `modules/OrgRoles.php`)

| Mechanism | Detail | Evidence |
|---|---|---|
| Authentication | Session; `password_verify` on `eb_users.password_hash`; `session_regenerate_id(true)` on login | `Auth::attempt` l.22–38 |
| Fixed roles | `admin`, `supervisor`, `operator`, `viewer` (ENUM) | `Schema.php` l.805, `Auth::roleLabel` |
| Per-user grants | `eb_users.permissions_json` → `{pages:[], groups:[], can_delete}` | `Auth::permissions`, `canAccess`, `allowedGroups`, `canManageCustomers` |
| Org-chart roles | `eb_roles` tree (`OrgRoles`), each role grants pages; user page list = role pages ∪ own pages | `Auth::permissions` l.217–229 |
| Viewer (demo) | `isAdmin()` returns **true** for `viewer`, but writes are blocked by an API allow-list (`Viewer::READ`), a DB read-only flag (`Database::$readOnly`), export block, and PII masking | `core/Viewer.php` |
| Impersonation | Admin can act as a non-admin; audited in `eb_audit`; cannot impersonate another admin | `Auth::impersonate` l.66–100 |
| Conversation scoping | `Desk::canSee` (assigned-agent ownership) + group restriction | `inbox_assert_access` in `api/handler.php` |
| CSRF | Token in session, `hash_equals`; enforced for **POST** only on `?api=`, `?export=`, login | `core/Csrf.php`, `index.php` l.43/88/96 |

### Findings

| ID | Finding | Evidence | Severity |
|---|---|---|---|
| P-01 | **No central action→permission mapping for the 442 API actions.** Page access (`canAccess`) is checked only when rendering pages. 205 actions contain an explicit role/ownership check; at least 85 write-type actions contain none (heuristic scan), e.g. `send_bulk`, `send_enqueue`, `campaign_create`, `template_delete`, `autoreply_save`, `instagram_automation_save`, `flow_save`, `link_delete`, `group_delete`. Any logged-in operator can call them directly, regardless of page restrictions. | scan in session; `api/v4_gsc.php` is the only file with an action allow-list | **High** |
| P-02 | `settings_get` returns `cron_key` and the full cron URL to any logged-in user | `api/handler.php` `case 'settings_get'` | Medium |
| P-03 | Operator with **no** `pages` list gets access to every non-admin page (fail-open, "legacy compatibility") | `Auth::canAccess` l.248 | Medium |
| P-04 | Permissions are cached in session at login (`$_SESSION['eb_perms']`); revocation takes effect only at next login | `Auth::attempt` l.34, `permissions()` | Medium |
| P-05 | No login throttling / lockout on panel login | repo search | Medium |
| P-06 | Core panel sets no session cookie flags (`httponly`, `secure`, `samesite`); only `crm/config.php` does | repo search | Medium |
| P-07 | No MFA, no session listing/revocation, no API keys for the panel | repo search | Gap (§10.1) |
| P-08 | Roles are hard-coded ENUM; no resource-level/ABAC model; no permission-change history beyond `eb_audit` user actions | DDL | Gap (§10.2–10.5) |

## 2. Jakojast CRM (`crm/core/Auth.php`, `crm/api.php`)

| Mechanism | Detail | Evidence |
|---|---|---|
| Authentication | Bearer token, 64 chars, **stored hashed** in `auth_tokens`, 8 h sliding expiry; bridged to `eb_users` | `crm/core/Auth.php` l.92–124, 242–252 |
| Roles | `admin`, `manager`, `staff` + `users.permissions` JSON of module keys | `crm/db.sql` |
| Module keys | `customers, hosts, host_leads, leads, tickets, tasks, payroll, chat, calls, reports, settings, staff, leaves` | `crm/api.php` l.122–128 |
| Central gate | resource → module map, checked for every non-admin request | `crm/api.php` l.118–135 |
| Extension visibility | operators see calls of permitted extensions (`canViewExten`) | `crm/core/Auth.php` l.652 |

### Findings

| ID | Finding | Evidence | Severity |
|---|---|---|---|
| P-09 | Gate is skipped when `permissions` is `null`; `hasPermission` also returns **true** when permissions are null ("migration not yet run") — fail-open | `crm/api.php` l.121, `crm/core/Auth.php` l.511 | Medium |
| P-10 | Resources absent from the map (`$__need === null`) are allowed for every logged-in user (currently: `search` and `references` have explicit rules; any future resource is open by default) | `crm/api.php` l.130 | Medium (needs per-route review) |
| P-11 | Module-level only: holder of `payroll` sees every salary; holder of `leads` sees all leads. No record ownership or department scoping. | gate design | Gap (§10.3–10.4) |
| P-12 | Webhook `crm/webhook.php` accepts unauthenticated events (token mismatch only logged) | file header + code | **High** |
| P-13 | `crm/cron_sync.php` and `cron.php` run without a key if none is configured; comparison is `!==` not constant-time | `crm/cron_sync.php` l.24, `cron.php` l.58 | Medium |

Previously reported items now **resolved** in this build (verified): plaintext login tokens (now hashed), `crm/debug.php` (removed), 4-char minimum password (now `MIN_PASSWORD_LENGTH = 6`).

## 3. Server-to-server trust

| Link | Mechanism |
|---|---|
| Jakojast site → Elanban `api/internal_lookup.php` | `X-Internal-Token` = `jakojast_integration_token`, `hash_equals` |
| Elanban → Jakojast site | `jakojast_outgoing_token` |
| Elanban ⇄ CRM `crm-bridge.php` | HMAC key derived from `app_key` |
| Provider webhooks | see `integration-inventory.md` |

All four shared secrets live in plaintext `config.php`, which **was included in the uploaded archive** (R-01).
