#!/usr/bin/env bash
#
# Regression test: --project-root keeps another application's autoloader out.
#
# The server loads the Composer autoloader (and .env) of the project root, by
# default the directory above --root: right for an application whose public/
# or web/ folder is served. An application served from its own root can sit
# inside another one. An Exponential installation in a subfolder of an older
# installation got the older one's vendor/autoload.php, which loaded the older
# kernel's autoload.php through a Composer "files" entry: every class came from
# the wrong installation, the warm-up found no class of its own and each
# uncached page died ("Call to undefined method eZINI::environment()").
# --project-root=<root> names the application's own root instead.
#
#   ./tests/project-root.sh
#
# Exits non-zero on any failure.

set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
PASS=0; FAIL=0
TMP="$(mktemp -d)"
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

# An outer application with a Composer autoloader, and an inner one served from its own root.
mkdir -p "$TMP/outer/vendor" "$TMP/outer/inner"
cat > "$TMP/outer/vendor/autoload.php" <<'PHP'
<?php
define('OUTER_AUTOLOAD', 1);
PHP
cat > "$TMP/outer/inner/index.php" <<'PHP'
<?php
header('Content-Type: text/plain');
echo defined('OUTER_AUTOLOAD') ? 'outer' : 'own';
PHP

echo "=============================================="
echo " Qbix Server — --project-root"
echo "=============================================="
echo

serve() { # serve <extra args...>: start on $PORT, wait until it answers; 1 when it never does
    ( setsid "$PHP" "$WS/sbin/qbixserver.php" --root="$TMP/outer/inner" --port=$PORT --workers=1 "$@" \
        >"$TMP/server.log" 2>&1 </dev/null & )
    for _ in $(seq 1 25); do
        sleep 0.4
        curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/" 2>/dev/null && return 0
    done
    echo "  server on port $PORT never came up — log:"
    sed -e 's/^/    /' "$TMP/server.log" | head -12
    return 1
}
stop() {
    pkill -f "qbixserver.*--port=$PORT" 2>/dev/null
    for _ in $(seq 1 25); do
        ss -ltn 2>/dev/null | grep -q ":$PORT " || return 0
        sleep 0.2
    done
}

if serve; then
    b=$(curl -s --max-time 10 "http://127.0.0.1:$PORT/index.php")
    [ "$b" = "outer" ] && ok "default: the directory above --root is the project root (its autoloader loads)" \
        || bad "default: expected outer, got: $(printf '%s' "$b" | head -c 90)"
fi
stop

if serve --project-root="$TMP/outer/inner"; then
    b=$(curl -s --max-time 10 "http://127.0.0.1:$PORT/index.php")
    [ "$b" = "own" ] && ok "--project-root=<root>: the outer application's autoloader stays out" \
        || bad "--project-root: expected own, got: $(printf '%s' "$b" | head -c 90)"
fi
stop

# none: no project at all; an application with its own vendor/ in the document root loads it itself, the
# server does not (under a persistent worker that would put the application's classes in the server).
mkdir -p "$TMP/outer/inner/vendor"
cat > "$TMP/outer/inner/vendor/autoload.php" <<'PHP'
<?php
define('INNER_AUTOLOAD', 1);
PHP
cat > "$TMP/outer/inner/which.php" <<'PHP'
<?php
header('Content-Type: text/plain');
echo (defined('OUTER_AUTOLOAD') ? 'outer' : '') . (defined('INNER_AUTOLOAD') ? 'inner' : '') . 'none';
PHP
if serve --project-root=none; then
    b=$(curl -s --max-time 10 "http://127.0.0.1:$PORT/which.php")
    [ "$b" = "none" ] && ok "--project-root=none: neither the outer nor the root's own autoloader is loaded by the server" \
        || bad "--project-root=none: expected none, got: $(printf '%s' "$b" | head -c 90)"
fi
stop

out=$(timeout 20 "$PHP" "$WS/sbin/qbixserver.php" --root="$TMP/outer/inner" --port=$PORT --project-root="$TMP/nothere" 2>&1)
rc=$?
if [ "$rc" != "0" ] && printf '%s' "$out" | grep -q 'project-root is not a directory'; then
    ok "--project-root that is not a directory: refused at start"
else
    bad "--project-root that is not a directory: rc $rc, $(printf '%s' "$out" | head -c 120)"
fi

echo
echo "  passed: $PASS  failed: $FAIL"
[ "$FAIL" = "0" ]
