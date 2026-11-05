# API Overview (ADR-009)

> Status: Proposed. Conventions deliberately match the YarAPI public API you already integrate with (envelope, scopes, `Idempotency-Key`, HMAC webhooks), so one mental model covers both.

## 1. Two API surfaces

| Surface | Path | Callers | Auth | Status |
|---|---|---|---|---|
| **Panel RPC** (existing) | `index.php?api=<action>` | the panel's own JS | session + CSRF + `ApiPolicy` | Kept. No new actions for new contexts; legacy actions migrate over time. |
| **REST v2** (new) | `api.php/v2/<context>/<resource>[/<id>[/<sub>]]` (PATH_INFO, works without mod_rewrite; pretty `/api/v2/...` when rewrite exists) | new SPA screens, CRM, extensions, later public API | session + CSRF (`X-CSRF-Token`) **or** API key (`Authorization: Bearer ak_…`, scoped) | Built from M2 for Work and every new context |

## 2. Resource conventions (REST v2)

| Operation | Method & path | Success |
|---|---|---|
| List | `GET /v2/work/tasks?board=12&state=open&limit=50&after=<cursor>` | `200 {ok, data:[…], next_cursor, has_more}` |
| Get | `GET /v2/work/tasks/{id}` | `200 {ok, data}` + `ETag: "v<version>"` |
| Create | `POST /v2/work/tasks` + `Idempotency-Key` | `201 {ok, data}` |
| Partial update | `PATCH /v2/work/tasks/{id}` + `If-Match: "v<version>"` | `200`; stale version → `409 conflict` with current |
| Command | `POST /v2/work/tasks/{id}/assign`, `/move`, `/route`, `/approve` | `200` |
| Archive | `POST /v2/work/tasks/{id}/archive` (no hard delete for business records) | `200` |

* JSON UTF-8; timestamps ISO-8601 with offset, stored UTC; ids: integer + public ULID (`pid`).
* Pagination: cursor only (`after`), `limit` ≤ 100, default 50.
* Filtering: explicit query params per resource; no generic query language; segment builder uses a validated JSON condition tree (never SQL).
* Every response carries `X-Request-Id`; logs use it.
* Rate limits per user/key (headers `X-RateLimit-Limit/Remaining/Reset`, `429` + `Retry-After`).
* Versioning: path `v2`; additive changes only within a version; deprecations announced with `Deprecation` header.

## 3. Idempotency

`POST` endpoints with side effects accept `Idempotency-Key` (≤ 64 chars). Stored in `eb_idempotency (key, actor, method, path, body_sha256, status, response, created_at)` for 24 h: same key + same body → stored response replayed; same key + different body → `422 idempotency_mismatch`. Clients derive keys from business data (same rule YarAPI documents).

## 4. Authentication for API keys (public API, later — feature #12)

`ak_live_` keys per tenant, shown once, stored hashed, scopes = permission keys subset, optional restriction to org units, expiry, last-used; management under `identity.api_key.*`. First call for integrators: `GET /v2/ping` (key, scopes, rate limit) — mirrors YarAPI `GET /ping`.

## 5. Implemented so far

2.2.0: `GET /v2/platform/ping` (any user) · `GET /v2/platform/health` (admin) · `GET /v2/platform/jobs?status=` (admin) · `POST /v2/platform/jobs/{id}/retry` (admin, Idempotency-Key, audited). 
2.3.0: `GET /v2/identity/me/permissions` (any user) · `GET /v2/identity/permissions` · `GET|POST /v2/identity/roles` · `GET|PATCH|DELETE /v2/identity/roles/{id}` · `GET /v2/identity/users` · `GET /v2/identity/users/{id}/effective` · `PUT /v2/identity/users/{id}/roles` (all `identity.role.manage`, admin-only). Route access is now `any|admin|<permission key>`.

2.4.0: `GET /v2/org/units?date=&archived=` · `GET /v2/org/units/{id}` · `POST /v2/org/units` · `PATCH /v2/org/units/{id}` · `POST /v2/org/units/{id}/archive|restore` · `POST /v2/org/units/{id}/members` · `POST /v2/org/memberships/{id}/end` · `POST /v2/org/units/{id}/delegations` · `POST /v2/org/delegations/{id}/revoke` · `GET /v2/org/users/{id}/reporting-chain` · `GET /v2/org/me/reporting-chain` · `GET /v2/org/import/preview` · `POST /v2/org/import` · `PUT /v2/identity/users/{id}/scoped-roles`. Reads open to any user; writes authorised per unit with `Auth::canIn` (`org.unit.manage`, `org.member.manage`).

## 6. Related

`error-contract.md` · `webhooks.md` · `connector-contracts.md`
