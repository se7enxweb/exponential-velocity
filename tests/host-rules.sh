#!/usr/bin/env bash
#
# Per-host access control and URL rules (Q_WebServer_HostRules): what a site's
# .htaccess files do under Apache, enforced by the server for every request.
#
#   ./tests/host-rules.sh
#
# Checks: Basic auth with bcrypt and Apache MD5 ($apr1$) user files (401
# without, 401 wrong, 200 right) on the whole site and on one directory, for
# static files and scripts, and before the cache; deny of a directory, of one
# file name (<Files>) and by FilesMatch; a missing user file is 500, never
# open; RewriteRule [F], [G], an external redirect with $1 and QSA, an
# internal rewrite to a front controller behind !-f/!-d conditions, a
# condition on the user agent with [OR], DirectoryIndex; dot segments and
# encoded dots cannot step past a deny. Exits non-zero on any failure.

set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
PASS=0; FAIL=0
TMP="$(mktemp -d)"; chmod 0755 "$TMP"
PORT=0
for _ in $(seq 1 60); do
    _p=$(( 19000 + RANDOM % 900 ))
    ss -ltn 2>/dev/null | grep -q ":$_p " || { PORT=$_p; break; }
done
[ "$PORT" = "0" ] && { echo "  no free port found"; exit 1; }
trap 'pkill -f "qbixserver.php.*--port=$PORT" 2>/dev/null; if [ -n "${KEEP_TMP:-}" ]; then echo "  kept $TMP"; else rm -rf "$TMP"; fi' EXIT
ok()  { PASS=$((PASS+1)); printf "  ok   %s\n" "$1"; }
bad() { FAIL=$((FAIL+1)); printf "  FAIL %s\n" "$1"; }
code() {  # code <host> <path> [curl args]
    local h=$1 p=$2; shift 2
    curl -s -o /dev/null -w '%{http_code}' --max-time 5 -H "Host: $h" "$@" "http://127.0.0.1:$PORT$p"
}
body() { local h=$1 p=$2; shift 2; curl -s --max-time 5 -H "Host: $h" "$@" "http://127.0.0.1:$PORT$p"; }

R="$TMP/site"; mkdir -p "$R/admin" "$R/attachments" "$R/var/cache" "$R/docs" "$R/old"
printf '<?php echo "front ", $_SERVER["REQUEST_URI"] ?? "", "\\n";\n' > "$R/index.php"
printf 'admin page\n' > "$R/admin/index.html"
printf 'secret\n' > "$R/attachments/file.txt"
printf 'ini\n' > "$R/.user.ini"
printf 'log\n' > "$R/debug.log"
printf '<?php echo "should not run";\n' > "$R/var/cache/x.php"
printf 'docs index\n' > "$R/docs/start.html"
printf 'plain\n' > "$R/plain.txt"
# User files: bcrypt from PHP, Apache MD5 from openssl.
printf '<?php echo "alice:", password_hash("s3cret", PASSWORD_BCRYPT), "\\n";\n' > "$TMP/mk.php"
"$PHP" "$TMP/mk.php" > "$TMP/bcrypt.users"
echo "bob:$(openssl passwd -apr1 -salt Ab3dEf9h 'hunter2')" > "$TMP/apr1.users"
# broken.test names a user file that is not there: a missing file must never mean "no password"

cat > "$TMP/conf.json" <<JSON
{ "Q": { "webserver": { "hosts": {
  "site.test": { "root": "$R", "index": ["start.html", "index.php"],
    "access": {
      "deny": [ { "dir": "attachments/" }, { "dir": "", "files": ".user.ini" }, { "dir": "", "filesMatch": "\\\\.log\$" } ],
      "auth": [ { "dir": "admin/", "realm": "Admins", "userFile": "$TMP/apr1.users" } ]
    },
    "rewrite": [ { "dir": "", "base": "/", "rules": [
      { "pattern": "^var/.*\\\\.php\$", "target": "-", "flags": { "F": true } },
      { "pattern": "^gone\$", "target": "-", "flags": { "G": true } },
      { "pattern": "^old/(.*)\$", "target": "https://new.example/\$1?from=old", "flags": { "R": 301, "L": true, "QSA": true } },
      { "pattern": ".*", "target": "-", "flags": { "F": true, "L": true },
        "conds": [ { "test": "%{HTTP_USER_AGENT}", "pattern": "BadBot", "nocase": true, "or": true },
                   { "test": "%{HTTP_USER_AGENT}", "pattern": "EvilCrawler", "nocase": true } ] },
      { "pattern": "^index\\\\.php\$", "target": "-", "flags": { "L": true } },
      { "pattern": ".", "target": "/index.php", "flags": { "L": true },
        "conds": [ { "test": "%{REQUEST_FILENAME}", "file": "-f", "negate": true },
                   { "test": "%{REQUEST_FILENAME}", "file": "-d", "negate": true } ] }
    ] } ] },
  "locked.test": { "root": "$R", "access": { "auth": [ { "dir": "", "realm": "Site", "userFile": "$TMP/bcrypt.users" } ] } },
  "broken.test": { "root": "$R", "access": { "auth": [ { "dir": "", "realm": "Site", "userFile": "$TMP/missing.users" } ] } }
} } } }
JSON

echo "=============================================="
echo " Qbix Server -- per-host access control and URL rules"
echo "=============================================="
( setsid "$PHP" "$WS/sbin/qbixserver.php" --root="$R" --port=$PORT --host=127.0.0.1 --workers=2 --user=nobody \
    --config="$TMP/conf.json" >"$TMP/server.log" 2>&1 </dev/null & )
for _ in $(seq 1 40); do sleep 0.3; curl -s -o /dev/null --max-time 1 "http://127.0.0.1:$PORT/" && break; done

[ "$(code locked.test /plain.txt)" = 401 ] && ok "whole site: 401 without a password" || bad "locked no auth: $(code locked.test /plain.txt)"
h=$(curl -s -D - -o /dev/null -H 'Host: locked.test' "http://127.0.0.1:$PORT/plain.txt" | tr -d '\r' | grep -i '^www-authenticate')
[ "$h" = 'WWW-Authenticate: Basic realm="Site"' ] && ok "401 names the realm" || bad "challenge: $h"
[ "$(code locked.test /plain.txt -u alice:wrong)" = 401 ] && ok "wrong password: 401" || bad "wrong pw"
[ "$(code locked.test /plain.txt -u alice:s3cret)" = 200 ] && ok "bcrypt user, right password: 200 (static file)" || bad "bcrypt ok: $(code locked.test /plain.txt -u alice:s3cret)"
[ "$(code locked.test /index.php)" = 401 ] && ok "a script needs the password too" || bad "script no auth"
[ "$(code locked.test /index.php -u alice:s3cret)" = 200 ] && ok "script with the password: 200" || bad "script auth"
code locked.test /plain.txt -u alice:s3cret >/dev/null; code locked.test /plain.txt -u alice:s3cret >/dev/null
[ "$(code locked.test /plain.txt)" = 401 ] && ok "after cached answers, still 401 without the password" || bad "cache bypass"
[ "$(code broken.test /plain.txt -u nobody:x)" = 500 ] && ok "a missing user file is 500, never open" || bad "unreadable userfile: $(code broken.test /plain.txt -u nobody:x)"

[ "$(code site.test /admin/)" = 401 ] && ok "directory auth: /admin/ asks" || bad "admin no auth: $(code site.test /admin/)"
[ "$(code site.test /admin/ -u bob:hunter2)" = 200 ] && ok "Apache MD5 (\$apr1\$) user, right password: 200" || bad "apr1: $(code site.test /admin/ -u bob:hunter2)"
[ "$(code site.test /admin/ -u bob:nope)" = 401 ] && ok "apr1 wrong password: 401" || bad "apr1 wrong"
[ "$(code site.test /plain.txt)" = 200 ] && ok "outside /admin/: no password" || bad "outside admin"
[ "$(code site.test /attachments/file.txt)" = 403 ] && ok "denied directory: 403" || bad "deny dir: $(code site.test /attachments/file.txt)"
[ "$(code site.test /docs/../attachments/file.txt)" = 403 ] && ok "dot segments do not step past a deny" || bad "dot segments"
[ "$(code site.test /%61ttachments/file.txt)" = 403 ] && ok "encoded characters do not step past a deny" || bad "encoded"
[ "$(code site.test /.user.ini)" = 403 ] && ok "<Files .user.ini> deny: 403" || bad "files deny"
[ "$(code site.test /debug.log)" = 403 ] && ok "FilesMatch deny: 403" || bad "filesMatch deny"
[ "$(code site.test /var/cache/x.php)" = 403 ] && ok "RewriteRule [F]: a script under var/ is 403" || bad "F rule: $(code site.test /var/cache/x.php)"
[ "$(code site.test /gone)" = 410 ] && ok "RewriteRule [G]: 410" || bad "G rule"
loc=$(curl -s -D - -o /dev/null -H 'Host: site.test' "http://127.0.0.1:$PORT/old/page?a=1" | tr -d '\r' | grep -i '^location:')
[ "$loc" = 'Location: https://new.example/page?from=old&a=1' ] && ok "external redirect with \$1 and QSA" || bad "redirect: $loc"
[ "$(code site.test /x -A 'Mozilla BadBot/1')" = 403 ] && ok "user-agent condition with [OR], first" || bad "UA or 1"
[ "$(code site.test /x -A 'evilcrawler')" = 403 ] && ok "user-agent condition with [OR], second, case-insensitive" || bad "UA or 2"
[ "$(code site.test /x -A 'Mozilla')" = 200 ] && ok "other user agents pass" || bad "UA pass"
b=$(body site.test /some/clean/url)
[ "$b" = "front /some/clean/url" ] && ok "!-f !-d rewrite to the front controller keeps REQUEST_URI" || bad "front controller: $b"
[ "$(body site.test /plain.txt)" = "plain" ] && ok "an existing file is served as it is" || bad "existing file"
[ "$(body site.test /docs/)" = "docs index" ] && ok "DirectoryIndex picks start.html" || bad "dirindex: $(body site.test /docs/)"

echo; echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ]
