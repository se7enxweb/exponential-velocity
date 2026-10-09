#!/usr/bin/env bash
#
# Regression test: a REQUEST_* key a script adds to $_SERVER is gone on the
# next request.
#
# Between two requests a persistent worker strips the previous request's
# keys from $_SERVER and keeps the ones the server itself provides. Every
# key starting with REQUEST_ used to be kept, as if all of them were the
# server's. A script that records something under that prefix -- a request
# filter's verdict, say -- therefore found it again on the next request the
# same worker served, and acted on a decision made for someone else.
#
# Only the REQUEST_* keys the server sets survive, and it sets them afresh
# for every request: REQUEST_METHOD, REQUEST_URI, REQUEST_SCHEME,
# REQUEST_TIME and REQUEST_TIME_FLOAT.
#
# One worker, so the second request lands on the worker that ran the first.
#
#   ./tests/request-keys-reset.sh
#
# Exits non-zero on any failure.

set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
PASS=0; FAIL=0
TMP="$(mktemp -d)"
# Pick a port nothing else holds.
PORT=0
for _ in $(seq 1 60); do
    _p=$(( 19000 + RANDOM % 900 ))
    ss -ltn 2>/dev/null | grep -q ":$_p " || { PORT=$_p; break; }
done
[ "$PORT" = "0" ] && { echo "  no free port found"; exit 1; }
trap 'rm -rf "$TMP"; pkill -f "qbixserver.*--port=$PORT" 2>/dev/null' EXIT

ok()  { PASS=$((PASS+1)); printf "  ok   %s\n" "$1"; }
bad() { FAIL=$((FAIL+1)); printf "  FAIL %s\n" "$1"; }

command -v "$PHP" >/dev/null || { echo "no php"; exit 1; }

ROOT="$TMP/public"; mkdir -p "$ROOT"

# set.php adds two keys of its own under the REQUEST_ prefix.
# keys.php lists every REQUEST_* key it finds, one per line, with its value.
cat > "$ROOT/set.php" <<'PHP'
<?php
header('Content-Type: text/plain');
$_SERVER['REQUEST_SHIELD'] = 'x';
$_SERVER['REQUEST_CUSTOM'] = 'y';
echo "pid=", getmypid(), "\n";
PHP

cat > "$ROOT/keys.php" <<'PHP'
<?php
header('Content-Type: text/plain');
echo "pid=", getmypid(), "\n";
echo "now=", sprintf('%.6F', microtime(true)), "\n";
$keys = array();
foreach ($_SERVER as $k => $v) {
    if (strncmp($k, 'REQUEST_', 8) === 0) $keys[] = $k;
}
sort($keys);
echo "keys=", implode(',', $keys), "\n";
foreach ($keys as $k) {
    echo $k, "=", is_scalar($_SERVER[$k]) ? $_SERVER[$k] : gettype($_SERVER[$k]), "\n";
}
PHP

echo "=============================================="
echo " Qbix Server — REQUEST_* keys across requests"
echo "=============================================="
echo

( setsid "$PHP" "$WS/sbin/qbixserver.php" --root="$ROOT" --port=$PORT --workers=1 \
    >"$TMP/server.log" 2>&1 </dev/null & )

up=0
for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/keys.php" 2>/dev/null && { up=1; break; }
done
[ "$up" = "1" ] || { echo "  server never came up"; sed 's/^/    /' "$TMP/server.log" | head -10; exit 1; }

field() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }

r1=$(curl -s --max-time 15 "http://127.0.0.1:$PORT/set.php" 2>/dev/null)
pid1=$(field "$r1" pid)
[ -n "$pid1" ] && ok "first request ran (worker $pid1)" || bad "first request: $r1"

before=$(date +%s)
r2=$(curl -s --max-time 15 -X POST -d 'a=1' "http://127.0.0.1:$PORT/keys.php?q=2" 2>/dev/null)
after=$(date +%s)
pid2=$(field "$r2" pid)

# Without the same worker the test proves nothing either way.
[ -n "$pid2" ] && [ "$pid1" = "$pid2" ] \
    && ok "second request on the same worker ($pid2)" \
    || bad "second request ran on another worker ($pid1 then $pid2)"

keys=$(field "$r2" keys)
case ",$keys," in
    *,REQUEST_SHIELD,*|*,REQUEST_CUSTOM,*)
        bad "keys the first request added reached the second: $keys" ;;
    *)  ok "keys the first request added are gone" ;;
esac

expected="REQUEST_METHOD,REQUEST_SCHEME,REQUEST_TIME,REQUEST_TIME_FLOAT,REQUEST_URI"
[ "$keys" = "$expected" ] \
    && ok "only the server's own REQUEST_* keys are present" \
    || bad "REQUEST_* keys: '$keys', expected '$expected'"

[ "$(field "$r2" REQUEST_METHOD)" = "POST" ] \
    && ok "REQUEST_METHOD is this request's (POST)" \
    || bad "REQUEST_METHOD: $(field "$r2" REQUEST_METHOD)"

[ "$(field "$r2" REQUEST_URI)" = "/keys.php?q=2" ] \
    && ok "REQUEST_URI is this request's (/keys.php?q=2)" \
    || bad "REQUEST_URI: $(field "$r2" REQUEST_URI)"

[ "$(field "$r2" REQUEST_SCHEME)" = "http" ] \
    && ok "REQUEST_SCHEME is http" \
    || bad "REQUEST_SCHEME: $(field "$r2" REQUEST_SCHEME)"

rt=$(field "$r2" REQUEST_TIME)
if [ -n "$rt" ] && [ "$rt" -ge "$before" ] && [ "$rt" -le "$after" ]; then
    ok "REQUEST_TIME is when this request started ($rt)"
else
    bad "REQUEST_TIME '$rt' is outside $before..$after"
fi

rtf=$(field "$r2" REQUEST_TIME_FLOAT)
now=$(field "$r2" now)
if [ -n "$rtf" ] && [ "${rtf%%.*}" = "$rt" ] \
    && awk -v a="$rtf" -v b="$now" 'BEGIN { exit !(a <= b && b - a < 10) }'; then
    ok "REQUEST_TIME_FLOAT matches REQUEST_TIME and precedes the script ($rtf)"
else
    bad "REQUEST_TIME_FLOAT '$rtf' (REQUEST_TIME $rt, script at $now)"
fi

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ] || exit 1
