#!/usr/bin/env bash
#
# Q.webserver.upstreams: a front server passes each listed host on to the
# server that serves it (a loopback port or a Unix socket), and serves the
# rest itself.
#
#   ./tests/upstreams.sh
#
# Checks: GET and POST reach the upstream with method, path, query, body and
# Host intact; X-Forwarded-For carries the client and X-Forwarded-Proto the
# scheme; the upstream's own response headers come back; a 3 MB answer arrives
# byte for byte; a Unix socket upstream works; an unlisted host is served by
# the front itself; an upstream that is down is 502; an Upgrade is 501.
# Exits non-zero on any failure.

set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
PASS=0; FAIL=0
TMP="$(mktemp -d)"; chmod 0755 "$TMP"
free_port() {
    for _ in $(seq 1 60); do
        _p=$(( 19000 + RANDOM % 900 ))
        ss -ltn 2>/dev/null | grep -q ":$_p " || { echo "$_p"; return; }
    done
    echo 0
}
FRONT=$(free_port); BACK=$(free_port); DEAD=$(free_port)
while [ "$BACK" = "$FRONT" ]; do BACK=$(free_port); done
[ "$FRONT" = 0 ] || [ "$BACK" = 0 ] && { echo "  no free port found"; exit 1; }
SOCK="$TMP/back.sock"
# A Unix socket path holds at most 107 bytes.
[ ${#SOCK} -lt 100 ] || SOCK="/tmp/qbix-upstreams-$$.sock"
trap 'pkill -f "qbixserver.php.*--port=($FRONT|$BACK)( |$)" 2>/dev/null; if [ -n "${KEEP_TMP:-}" ]; then echo "  kept $TMP"; else rm -rf "$TMP"; fi; rm -f "$SOCK"' EXIT
ok()  { PASS=$((PASS+1)); printf "  ok   %s\n" "$1"; }
bad() { FAIL=$((FAIL+1)); printf "  FAIL %s\n" "$1"; }

mkdir -p "$TMP/front" "$TMP/back"
printf '<?php echo "front here\\n";\n' > "$TMP/front/index.php"
cat > "$TMP/back/echo.php" <<'PHP'
<?php
header('X-Back: yes');
echo "back here\n", "method=", $_SERVER['REQUEST_METHOD'], "\n", "uri=", $_SERVER['REQUEST_URI'], "\n",
    "host=", $_SERVER['HTTP_HOST'] ?? '', "\n", "body=", file_get_contents('php://input'), "\n",
    "xff=", $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '', "\n", "proto=", $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '', "\n";
PHP
cat > "$TMP/back/big.php" <<'PHP'
<?php
mt_srand(7); $s = ''; for ($i = 0; $i < 3 * 1048576 / 32; $i++) $s .= md5((string) mt_rand());
header('Content-Type: application/octet-stream'); echo $s;
PHP
"$PHP" "$TMP/back/big.php" | md5sum | cut -d' ' -f1 > "$TMP/big.md5"

cat > "$TMP/front.json" <<JSON
{ "Q": { "webserver": { "upstreams": {
    "back.test": "127.0.0.1:$BACK",
    "sock.test": "unix:$SOCK",
    "dead.test": "127.0.0.1:$DEAD"
}, "upstreamTimeout": 10,
"hosts": { "wf.test": { "root": "$TMP/front", "writeForward": {
    "primaryFile": "$TMP/primary", "nodes": { "node-b": "127.0.0.1:$BACK" } } } }
} } }
JSON

echo "=============================================="
echo " Qbix Server -- upstreams by host"
echo "=============================================="
echo "  front=$FRONT back=$BACK sock=$SOCK"
( setsid "$PHP" "$WS/sbin/qbixserver.php" --root="$TMP/back" --port=$BACK --host=127.0.0.1 --socket="$SOCK" --workers=2 \
    >"$TMP/back.log" 2>&1 </dev/null & )
( setsid "$PHP" "$WS/sbin/qbixserver.php" --root="$TMP/front" --port=$FRONT --host=127.0.0.1 --workers=2 \
    --config="$TMP/front.json" >"$TMP/front.log" 2>&1 </dev/null & )
for p in $FRONT $BACK; do
    for _ in $(seq 1 40); do sleep 0.3; curl -s -o /dev/null --max-time 1 "http://127.0.0.1:$p/" && break; done
done

r=$(curl -s --max-time 5 -H 'Host: back.test' "http://127.0.0.1:$FRONT/echo.php?x=1&y=2")
[[ "$r" == "back here"* ]] && ok "listed host is answered by the upstream" || bad "GET: $r"
[[ "$r" == *"uri=/echo.php?x=1&y=2"* && "$r" == *"host=back.test"* ]] && ok "path, query and Host are passed on" || bad "uri/host: $r"
[[ "$r" == *"xff=127.0.0.1"* ]] && ok "X-Forwarded-For names the client" || bad "xff: $r"
[[ "$r" == *"proto=http"* ]] && ok "X-Forwarded-Proto names the scheme" || bad "proto: $r"
r=$(curl -s --max-time 5 -H 'Host: back.test' -H 'X-Forwarded-For: 203.0.113.9' "http://127.0.0.1:$FRONT/echo.php")
[[ "$r" == *"xff=203.0.113.9, 127.0.0.1"* ]] && ok "an incoming X-Forwarded-For chain is extended" || bad "xff chain: $r"
r=$(curl -s --max-time 5 -H 'Host: back.test' --data 'a=1&b=two' "http://127.0.0.1:$FRONT/echo.php")
[[ "$r" == *"method=POST"* && "$r" == *"body=a=1&b=two"* ]] && ok "POST body is passed on" || bad "POST: $r"
h=$(curl -s -D - -o /dev/null --max-time 5 -H 'Host: back.test' "http://127.0.0.1:$FRONT/echo.php")
grep -qi '^X-Back: yes' <<<"$h" && ok "the upstream's response headers come back" || bad "headers: $h"
m=$(curl -s --max-time 20 -H 'Host: back.test' "http://127.0.0.1:$FRONT/big.php" | md5sum | cut -d' ' -f1)
[ "$m" = "$(cat "$TMP/big.md5")" ] && ok "a 3 MB answer arrives byte for byte" || bad "big body md5 $m"
r=$(curl -s --max-time 5 -H 'Host: sock.test' "http://127.0.0.1:$FRONT/echo.php")
[[ "$r" == "back here"* ]] && ok "a Unix socket upstream works" || bad "unix socket: $r"
r=$(curl -s --max-time 5 -H 'Host: other.test' "http://127.0.0.1:$FRONT/")
[[ "$r" == "front here"* ]] && ok "an unlisted host is served by the front itself" || bad "unlisted: $r"
c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 -H 'Host: dead.test' "http://127.0.0.1:$FRONT/")
[ "$c" = 502 ] && ok "an upstream that is down is 502" || bad "dead upstream: $c"
c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 -H 'Host: back.test' -H 'Connection: Upgrade' -H 'Upgrade: websocket' "http://127.0.0.1:$FRONT/")
[ "$c" = 501 ] && ok "an Upgrade is not passed on (501)" || bad "upgrade: $c"
ok_n=0
for i in $(seq 1 50); do curl -s --max-time 5 -H 'Host: back.test' "http://127.0.0.1:$FRONT/echo.php" | grep -q '^back here' && ok_n=$((ok_n+1)); done
[ $ok_n -eq 50 ] && ok "50 requests in a row all relayed" || bad "only $ok_n of 50 relayed"
r=$(curl -s --max-time 5 -H 'Host: other.test' "http://127.0.0.1:$FRONT/")
[[ "$r" == "front here"* ]] && ok "the front still serves its own hosts after relaying" || bad "front after relays: $r"


# Write forwarding to a primary (LiteFS: a .primary file names it while this node is a replica).
printf '<?php echo "front here ", $_SERVER["REQUEST_METHOD"], "\\n";\n' > "$TMP/front/index.php"
r=$(curl -s --max-time 5 -H 'Host: wf.test' --data 'x=1' "http://127.0.0.1:$FRONT/")
[[ "$r" == "front here POST"* ]] && ok "no primary file: this node is primary, a write is answered here" || bad "write on primary: $r"
echo "node-b" > "$TMP/primary"
r=$(curl -s --max-time 5 -H 'Host: wf.test' --data 'x=1' "http://127.0.0.1:$FRONT/echo.php")
[[ "$r" == "back here"* && "$r" == *"method=POST"* && "$r" == *"body=x=1"* ]] && ok "primary file names node-b: a write goes to node-b" || bad "write forward: $r"
r=$(curl -s --max-time 5 -H 'Host: wf.test' "http://127.0.0.1:$FRONT/")
[[ "$r" == "front here GET"* ]] && ok "a read stays on the replica" || bad "read on replica: $r"
r=$(curl -s --max-time 5 -H 'Host: wf.test' -H 'X-Qbix-Forwarded-Write: 1' --data 'x=1' "http://127.0.0.1:$FRONT/")
[[ "$r" == "front here POST"* ]] && ok "a write already forwarded once is not sent on again" || bad "loop guard: $r"

echo; echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ]
