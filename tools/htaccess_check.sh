#!/bin/bash
# Serve a tree with Apache (AllowOverride All) at / and /marketing/, then print HTTP status for URLs.
# usage: htaccess_check.sh <tree_dir> <label>
set -u
TREE="$1"; LABEL="$2"
BASE=${HT_BASE:-/tmp/htverify}/apache_$LABEL   # must be readable by www-data
mkdir -p "$BASE/docroot" "$BASE/logs"
rm -rf "$BASE/docroot/marketing"
cp -r "$TREE" "$BASE/docroot/marketing"
# root install = same tree at docroot root (copy files, keep marketing subdir alongside)
( cd "$TREE" && tar cf - . ) | ( cd "$BASE/docroot" && tar xf - )
cat > "$BASE/httpd.conf" <<EOF
ServerRoot "/etc/apache2"
Listen 127.0.0.1:8097
PidFile $BASE/httpd.pid
ErrorLog $BASE/logs/error.log
LoadModule mpm_prefork_module /usr/lib/apache2/modules/mod_mpm_prefork.so
LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so
LoadModule alias_module /usr/lib/apache2/modules/mod_alias.so
LoadModule dir_module /usr/lib/apache2/modules/mod_dir.so
LoadModule mime_module /usr/lib/apache2/modules/mod_mime.so
LoadModule rewrite_module /usr/lib/apache2/modules/mod_rewrite.so
LoadModule access_compat_module /usr/lib/apache2/modules/mod_access_compat.so
User www-data
Group www-data
TypesConfig /etc/mime.types
DocumentRoot "$BASE/docroot"
<Directory "$BASE/docroot">
  AllowOverride All
  Require all granted
</Directory>
EOF
apache2 -f "$BASE/httpd.conf" -k start -DFOREGROUND >/dev/null 2>&1 &
sleep 1.5
for u in /core/Cache.php /marketing/core/Cache.php /modules/Queue.php /marketing/modules/Queue.php /channels/SmsDriver.php /core/Auth.php /marketing/core/Auth.php /logs/x.log /marketing/logs/x.log /cli/migrate.php /src/Platform/Api.php /migrations/0001_baseline.php /api/handler.php /marketing/api/handler.php /config.php /marketing/crm/config.php /docs/discovery/gap-analysis.md /tools/gen_api_policy.php /assets/core.js /marketing/assets/core.js /index.php /marketing/api.php /crm/index.php /widget.js; do
  printf "%-36s %s\n" "$u" "$(curl -s -o /dev/null -w '%{http_code}' --noproxy '*' "http://127.0.0.1:8097$u")"
done
kill "$(cat "$BASE/httpd.pid")" 2>/dev/null; sleep 0.5
