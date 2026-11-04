# Evidence: .htaccess directory protection (2.2.0)

Executed 2026-11-04 on Apache 2.4 (Ubuntu package), `AllowOverride All`, the same tree served at the web root **and** under `/marketing/`. No PHP module loaded, so `200` = file reachable (on a real host the PHP file would execute). `old` = 2.1.6 `.htaccess`, `new` = 2.2.0.

```text
url                                  old   new  
/core/Cache.php                      200   403  
/marketing/core/Cache.php            403   403  
/modules/Queue.php                   200   403  
/marketing/modules/Queue.php         403   403  
/channels/SmsDriver.php              403   403  
/core/Auth.php                       403   403  
/marketing/core/Auth.php             403   403  
/logs/x.log                          200   403  
/marketing/logs/x.log                200   403  
/cli/migrate.php                     404   403  
/src/Platform/Api.php                404   403  
/migrations/0001_baseline.php        404   403  
/api/handler.php                     200   403  
/marketing/api/handler.php           200   403  
/config.php                          403   403  
/marketing/crm/config.php            403   403  
/docs/discovery/gap-analysis.md      200   403  
/tools/gen_api_policy.php            200   403  
/assets/core.js                      200   200  
/marketing/assets/core.js            200   200  
/index.php                           200   200  
/marketing/api.php                   404   200  
/crm/index.php                       200   200  
/widget.js                           200   200  
```

Notes: `logs/` was also protected before by its own `logs/.htaccess` (not present in this test tree). `/core/Auth.php` was 403 before only because of the filename rule. `docs/`, `tools/`, `src/`, `migrations/` do not exist on servers that install from the patch zips; the rule is defence in depth. Reproduce: `bash tools/htaccess_check.sh <tree> <label>` (needs apache2; tree must be readable by www-data).
