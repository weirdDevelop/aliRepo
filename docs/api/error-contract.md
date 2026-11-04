# Error Contract

> Status: Proposed. Applies to REST v2; panel RPC keeps `{ok:false, error}` and gains `code` where it is cheap (already done for authz: `code: "forbidden"`).

```json
{ "ok": false,
  "error": { "code": "validation_error", "message": "متن قابل نمایش برای کاربر", "field": "due_at", "details": {} },
  "request_id": "01JC…" }
```

| HTTP | code | Meaning | Client reaction |
|---|---|---|---|
| 400 | `validation_error` | Bad/missing field (`field` set) | Fix input; do not retry |
| 401 | `unauthenticated` | No/expired session or key | Re-login / new key |
| 402 | `subscription_inactive` | Tenant suspended or plan expired (SaaS) | Show billing message |
| 403 | `forbidden` | Permission missing (`details.permission`) | Show "ask admin for …" |
| 403 | `entitlement_exceeded` | Plan limit reached (`details.limit`) | Upgrade prompt |
| 404 | `not_found` | Resource absent **or not visible** to the caller (no existence leak) | — |
| 409 | `conflict` | Version mismatch (`details.current_version`) or duplicate (`details.existing_id`) | Reload / use existing |
| 409 | `invalid_transition` | Workflow guard failed (`details.reasons[]`) | Show reasons |
| 422 | `idempotency_mismatch` | Key reused with different body | Bug in client |
| 422 | `rule_violation` | Business rule (cycle in dependencies, WIP limit, SoD) | Show message |
| 429 | `rate_limited` | Too many requests (`Retry-After`) | Back off |
| 502 | `provider_error` | External provider refused (`details.provider`, `details.provider_code`, `details.retryable`) | Show message; retry only if retryable |
| 503 | `unavailable` | Maintenance / migration in progress | Retry later |
| 500 | `internal_error` | Unexpected; message is generic, details only in logs under `request_id` | Report `request_id` |

Rules: messages are localized, never include SQL, stack traces, secrets or other users' data. `500` responses never echo exception messages (today `api/handler.php` does — fix in M2).
