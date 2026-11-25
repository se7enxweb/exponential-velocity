#!/usr/bin/env bash
#
# Pools with a user of their own (Q.webserver.pools): one server, one port,
# each host's PHP run by workers of that host's user.
#
#   sudo ./tests/multiuser-pools.sh        (needs root: workers switch users)
#
# Checks: each host's PHP runs as its pool's user and group; no worker of a
# pool runs as root; a host outside every pool uses the server's own workers;
# a pool's ini (open_basedir) holds in its workers; static files of a pooled
# host go out only when that user owns them (a root-owned file and a link to
# another user's file are refused); a pool naming an unknown user, or a host
# naming an unknown pool, stops the start. Exits non-zero on any failure.

set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
PASS=0; FAIL=0
[ "$(id -u)" = 0 ] || { echo "  skipped: needs root (workers switch users)"; exit 0; }
UA=nobody; UB=daemon
id "$UA" >/dev/null 2>&1 && id "$UB" >/dev/null 2>&1 || { echo "  skipped: needs users $UA and $UB"; exit 0; }
GA=$(id -gn "$UA"); GB=$(id -gn "$UB")
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
get() { curl -s --max-time 5 -H "Host: $1" "http://127.0.0.1:$PORT$2"; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 5 -H "Host: $1" "http://127.0.0.1:$PORT$2"; }

mkdir -p "$TMP/a" "$TMP/b" "$TMP/default"
for d in a b default; do
    cat > "$TMP/$d/who.php" <<'PHP'
<?php
$u = posix_getpwuid(posix_geteuid()); $g = posix_getgrgid(posix_getegid());
echo $u['name'], ':', $g['name'], ' basedir=', ini_get('open_basedir'), "\n";
if (isset($_GET['read'])) echo @file_get_contents($_GET['read']) === false ? "read refused\n" : "read ok\n";
PHP
done
echo "b secret" > "$TMP/b/secret.txt"
echo "a own" > "$TMP/a/own.txt"; chown "$UA:$GA" "$TMP/a/own.txt"
echo "root's" > "$TMP/a/rootfile.txt"
ln -s "$TMP/b/secret.txt" "$TMP/a/link.txt"
chown "$UB:$GB" "$TMP/b/secret.txt"
chmod -R a+rX "$TMP"

cat > "$TMP/pools.json" <<JSON
{ "Q": { "webserver": {
    "user": "$UA",
    "hosts": {
        "a.test": { "root": "$TMP/a" },
        "b.test": { "root": "$TMP/b", "pool": "pb" }
    },
    "pools": {
        "pa": { "user": "$UA", "group": "$GA", "workers": 2, "hosts": ["a.test"],
                "ini": { "open_basedir": "$TMP/a/" } },
        "pb": { "user": "$UB", "workers": 2 }
    }
} } }
JSON

echo "=============================================="
echo " Qbix Server -- pools with a user of their own"
echo "=============================================="
echo "  port=$PORT pa=$UA:$GA pb=$UB:$GB"

( setsid "$PHP" "$WS/sbin/qbixserver.php" --root="$TMP/default" --port=$PORT --host=127.0.0.1 --workers=2 \
    --config="$TMP/pools.json" >"$TMP/server.log" 2>&1 </dev/null & )
for _ in $(seq 1 40); do sleep 0.3; curl -s -o /dev/null --max-time 1 "http://127.0.0.1:$PORT/" && break; done

grep -q "Pool pa: 2 workers as $UA:$GA" "$TMP/server.log" && ok "start-up names pool pa and its user" || bad "pool pa not announced: $(grep -i pool "$TMP/server.log" | head -3)"
r=$(get a.test /who.php); [[ "$r" == "$UA:$GA "* ]] && ok "a.test runs as $UA:$GA" || bad "a.test ran as: $r"
r=$(get b.test /who.php); [[ "$r" == "$UB:$GB "* ]] && ok "b.test runs as $UB:$GB (pool by hosts.<host>.pool)" || bad "b.test ran as: $r"
r=$(get other.test /who.php); [[ "$r" == "$UA:"* ]] && ok "a host outside every pool uses the server's workers" || bad "other.test ran as: $r"
r=$(get a.test /who.php); [[ "$r" == *"basedir=$TMP/a/"* ]] && ok "pool pa's open_basedir is set in its workers" || bad "open_basedir: $r"
r=$(get a.test "/who.php?read=$TMP/b/secret.txt"); [[ "$r" == *"read refused"* ]] && ok "pool pa cannot read b's file (open_basedir)" || bad "a read b's file: $r"
for i in 1 2 3 4 5 6; do get b.test /who.php >/dev/null; get a.test /who.php >/dev/null; done
r=$(get b.test /who.php); [[ "$r" == "$UB:"* ]] && ok "still $UB after workers were reused" || bad "b.test after reuse: $r"

c=$(code a.test /own.txt); [ "$c" = 200 ] && ok "a.test sends a file $UA owns" || bad "own file: $c"
c=$(code a.test /rootfile.txt); [ "$c" = 403 ] && ok "a.test refuses a file root owns (owner match)" || bad "root-owned file: $c"
c=$(code a.test /link.txt); [ "$c" = 403 ] && ok "a.test refuses a link to $UB's file" || bad "link to other user's file: $c"

srv=$(pgrep -f "qbixserver.php.*--port=$PORT" | head -1)
kids=$(pgrep -P "$srv" | tr '\n' ' ')
users=$(for k in $kids; do ps -o user= -p "$k"; done | sort | uniq -c | tr -s ' ' | tr '\n' ';')
echo "  worker users: $users"
[[ "$users" != *root* ]] && ok "no worker runs as root" || bad "a worker runs as root: $users"
[[ "$users" == *"$UB"* ]] && ok "pool pb's workers exist as $UB" || bad "no $UB worker: $users"
pkill -f "qbixserver.php.*--port=$PORT"; sleep 1

# Fork per request with pools: every request in a fresh worker of its pool's user, nothing kept from the one before
# (what PHP-FPM gives an application that is not written for persistent workers).
cat > "$TMP/a/count.php" <<'PHP'
<?php
$GLOBALS['n'] = ($GLOBALS['n'] ?? 0) + 1;
if (!function_exists('once_only')) { function once_only() {} echo "defined "; } else { echo "kept "; }
echo $GLOBALS['n'], ' ', posix_getpwuid(posix_geteuid())['name'], "\n";
PHP
cp "$TMP/a/count.php" "$TMP/b/count.php"; chmod a+r "$TMP/a/count.php" "$TMP/b/count.php"
sed 's/"user": "'"$UA"'",/"user": "'"$UA"'", "forkPerRequest": true,/' "$TMP/pools.json" > "$TMP/pools-fpr.json"
( setsid "$PHP" "$WS/sbin/qbixserver.php" --root="$TMP/default" --port=$PORT --host=127.0.0.1 --workers=2 \
    --config="$TMP/pools-fpr.json" >"$TMP/server-fpr.log" 2>&1 </dev/null & )
for _ in $(seq 1 40); do sleep 0.3; curl -s -o /dev/null --max-time 1 "http://127.0.0.1:$PORT/" && break; done
r1=$(get a.test /count.php); r2=$(get a.test /count.php); r3=$(get a.test /count.php)
[ "$r3" = "defined 1 $UA" ] && ok "fork per request: a.test starts fresh every time, as $UA" || bad "fork per request a.test: $r1 | $r2 | $r3"
r=$(get b.test /count.php); r=$(get b.test /count.php)
[ "$r" = "defined 1 $UB" ] && ok "fork per request: b.test starts fresh every time, as $UB" || bad "fork per request b.test: $r"
pkill -f "qbixserver.php.*--port=$PORT"; sleep 1

# Refusals at start.
bad_cfg() {  # bad_cfg <label> <json> <expected message part>
    printf '%s' "$2" > "$TMP/bad.json"
    timeout 20 "$PHP" "$WS/sbin/qbixserver.php" --root="$TMP/default" --port=$PORT --host=127.0.0.1 --workers=1 \
        --config="$TMP/bad.json" >"$TMP/bad.log" 2>&1 </dev/null
    rc=$?
    [ $rc -ne 0 ] && grep -q "$3" "$TMP/bad.log" && ok "$1" || bad "$1 (rc $rc: $(tail -2 "$TMP/bad.log"))"
    pkill -f "qbixserver.php.*--port=$PORT" 2>/dev/null
}
bad_cfg "an unknown pool user stops the start" \
    '{"Q":{"webserver":{"user":"nobody","pools":{"x":{"user":"no-such-user-qbix","hosts":["x.test"]}}}}}' 'does not exist'
bad_cfg "a host naming an unknown pool stops the start" \
    '{"Q":{"webserver":{"user":"nobody","hosts":{"y.test":{"root":"/tmp","pool":"nope"}}}}}' 'not in Q.webserver.pools'
bad_cfg "a root pool without allowRoot stops the start" \
    '{"Q":{"webserver":{"user":"nobody","pools":{"r":{"user":"root","hosts":["r.test"]}}}}}' 'only with allowRoot'

echo; echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ]
