# Evidence: phone normaliser comparison

Executed in session on 2026-11-04 with PHP 8.3.6. Script: `docs/discovery/evidence/phone_cmp.php` (extracts the three functions verbatim from source and runs them; synthetic numbers only).

Command (from repo root): `php docs/discovery/evidence/phone_cmp.php .`

```text
input              | Persons(EB)    | CallSync(CRM)    | Call::lead(CRM)
09121234567        | '09121234567'  | '09121234567'    | '09121234567' 
+989121234567      | '09121234567'  | '989121234567'   | '09121234567' 
00989121234567     | '09121234567'  | '00989121234567' | '09121234567' 
9121234567         | '09121234567'  | '09121234567'    | '09121234567' 
9121234567         | '09121234567'  | '09121234567'    | '09121234567' 
۰۹۱۲۱۲۳۴۵۶۷ | '09121234567'  | NULL             | NULL          
0912-123-4567      | '09121234567'  | '09121234567'    | '09121234567' 
02188776655        | NULL           | '02188776655'    | '02188776655' 
+14155552671       | NULL           | '14155552671'    | NULL          
12345              | NULL           | '12345'          | NULL          
```

**Observed defects**

1. `CallSyncManager::normalizeIranianMobile` (Telefonchy sync) keeps `98…` / `0098…` prefixes and accepts any digit string (`12345`). A call from `+989121234567` is stored as `989121234567` while the Elanban person is `09121234567` → no match, duplicate lead risk.
2. Persian digits are handled only by `Persons::normalizePhone`; both CRM normalisers return NULL.
3. Landlines (`021…`) are rejected by Elanban but accepted by CRM — a valid key in one system and not the other.
4. International numbers: Elanban drops them (NULL), call sync keeps raw digits, lead conversion drops them. Master prompt §5.5 requires not assuming every number is Iranian.

Row 5 is the same number passed as a JSON-number-like float literal (Telefonchy `ringing` sends numbers as JSON numbers, per `crm/webhook.php`).
