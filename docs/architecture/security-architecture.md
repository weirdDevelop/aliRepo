# Security Architecture

> Master prompt §10, §17.2. Status: Proposed. Builds on fixes already shipped in 2.1.5/2.1.6.

## 1. Trust boundaries

| Boundary | Who crosses it | Control |
|---|---|---|
| Browser → panel | staff users | session cookie (HttpOnly, SameSite=Lax, Secure), CSRF token on POST, login throttle, per-request permission refresh, server-side AuthZ gate |
| Browser → public widgets (`chat.php`, `widget.js`, `r.php`, `eb-feed.php`, `notices.php`) | anonymous visitors | no session privileges; per-site key; rate limits (to add); output encoding |
| Provider → webhooks | Telegram, Bale, Rubika, SMS, YarAPI, RoboClick, Directsaz, Telefonchy | signature or token verification, timestamp window, event-id dedupe, prompt 2xx, async processing |
| Server → providers | outbound API calls | credentials from encrypted store, timeouts, stable Idempotency-Key, retries with backoff |
| Server → AI providers | OpenAI-compatible endpoints | **explicit per-tenant opt-in per provider and purpose (R-09)**; data minimisation (no phone numbers, ids instead of names where possible); logged as data-flow record |
| Jakojast site ⇄ Elanban ⇄ CRM | server-to-server | shared HMAC tokens (to move into encrypted store; rotate per R-01) |
| Tenant ⇄ tenant (SaaS) | — | database per tenant; tenant from Host only (`tenancy-strategy.md`) |
| Control plane | platform admins only | separate DB credentials; MFA required; every action audited |

## 2. Threats and mitigations (master prompt §10.6)

| Threat | Current state | Target control |
|---|---|---|
| SQL injection | PDO prepared statements throughout (spot-checked); some interpolation of `(int)` casts | Keep; static check in CI for `"... $var ..."` in SQL strings without cast |
| XSS | Server-side `htmlspecialchars` / `Helpers::e`; SPA builds HTML strings with `E()` escaping | Lint rule: template literals inserting data must use `E()`; CSP header (report-only first) |
| CSRF | Token on panel POST; CRM requires `X-Requested-With` | Keep; SameSite=Lax adds defence |
| Broken access control / IDOR | Fixed at action level (2.1.5) and CRM module level (2.1.6); record-level ad hoc | `can(user, key, subject)` in every service method that loads a record by id |
| Privilege escalation | role grants by admins | SoD rule 2 (`permission-matrix.md` §5) |
| Session fixation / theft | strict mode + regenerate on login (2.1.6) | + session list & revoke (M2) |
| Brute force | login throttle (2.1.6) | + MFA (TOTP) optional, required for admins in SaaS |
| SSRF | `Flows` `act.http` blocks private/reserved IPs (`modules/Flows.php` l.645) | Resolve once and pin the IP for the request (DNS-rebinding), deny redirects to private ranges, same guard in `ApiConnectors` |
| Unsafe upload / path traversal | MIME checks exist in places | `Files` context: server-side sniffing, extension allow-list, random names, tenant prefix, `realpath` containment test |
| Webhook replay | YarAPI/RoboClick/Telefonchy verified (2.1.5) | Uniform `WebhookVerifier` per connector; inbound log without secrets |
| Secret leakage | `config.php` plaintext; CRM `settings` plaintext tokens (R-18 exposure, fixed in 2.1.6); **channel bot tokens plaintext in `eb_settings`** (`ChannelManager::saveToken`); only Search Console and AI keys are encrypted (AES-256-GCM, `SearchConsole.php` l.73, `Ai.php` l.841) | Single encrypted secret store (`eb_connectors.config_enc`, AES-256-GCM, key from `app_key`, key-id for rotation); secrets never returned by any API (write-only fields, show last 4) |
| Sensitive data in logs | mixed | `Log` redaction list; message bodies never logged; phone numbers masked |
| Dependency vulnerabilities | only `crm/vendor` (Composer lock present) | `composer audit` in CI for `crm/` |

## 3. Secrets lifecycle

1. **Storage:** `config.php` keeps only DB credentials and the master key (`app_key`). Everything else moves into the encrypted connector store.
2. **Rotation:** each secret has `key_id`; rotation writes new value, keeps old for verification window (webhook secrets), then deletes.
3. **Display:** write-only in UI; API returns `"••••1234"`.
4. **Distribution:** deploy zips never contain `config.php`, logs, uploads or caches (enforced by build script; already true for 2.1.5/2.1.6).

## 4. Audit events (minimum)

Login success/failure/lockout · session revoked · role/permission change · impersonation start/stop · export (who, what, row count) · merge/unmerge · connector credential change · workflow approval decisions · settings change (key name, not value) · tenant lifecycle (control plane).

## 5. Privacy

* Data minimisation for AI calls; per-tenant AI data-flow toggle defaulting to **off** for new tenants.
* Retention per data category (messages, calls/recordings, logs, audit) configurable; deletion jobs audited.
* Customer export and deletion requests handled by Customer Identity with legal-hold override.
* Test fixtures synthetic only (already the rule in `tests/`).

## 6. Verification

OWASP ASVS L2 as checklist reference; each security control above gets a sandbox integration test (pattern already established in `tests/integration/`).
