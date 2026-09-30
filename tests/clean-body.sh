#!/usr/bin/env bash
#
# Regression test: a response body carries what the script wrote, and
# nothing the engine had to say about it.
#
# PHP assigns $context to every stream wrapper instance opened with a
# context. Q_WebServer_CompatFileWrapper never declared the property, so
# PHP 8.2+ reported a dynamic property on each include, and with the
# default display_errors those notices went into the response body ahead
# of the script's own output.
#
# On a text page that was a stray paragraph. On a generated image it was
# fatal: a captcha came back with 1.5KB of notices in front of the PNG
# signature, so the file would not open — served as image/png, with a 200,
# and nothing in the error log.
#
# It only reproduces when the server runs from the phar: including a file
# from inside it passes the wrapper a context, and running from source
# does not. So this test drives ./bin/qbixserver when one is built and
# says so when it has to fall back to the sources, where it proves
# nothing. That is the same gap that let the UPX-corrupted binaries and a
# gd-less build ship: the suite tests a configuration nobody receives.
#
#   ./tests/clean-body.sh          uses bin/qbixserver if present
#   QB=/path/to/binary ./tests/clean-body.sh
#
# Exits non-zero on any failure.

set -uo pipefail

WS="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
PASS=0; FAIL=0
TMP="$(mktemp -d)"
# Pick a port nothing else holds. Fixed ranges collide with whatever the
# machine happens to run -- a container on the same number makes every
# assertion here fail with an empty reply and no hint why.
# ss is iproute2 and exists only on Linux. On macOS it is simply absent, so
# the check silently passed for every candidate and the port was never really
# tested -- which is the same as not checking. Pick whichever listener tool the
# platform has.
if command -v ss >/dev/null 2>&1; then
    _listening() { ss -ltn 2>/dev/null | grep -q ":$1 "; }
elif command -v lsof >/dev/null 2>&1; then
    _listening() { lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1; }
elif command -v netstat >/dev/null 2>&1; then
    _listening() { netstat -an 2>/dev/null | grep -q "[.:]$1 .*LISTEN"; }
else
    _listening() { return 1; }
fi

PORT=0
for _ in $(seq 1 60); do
    _p=$(( 19000 + RANDOM % 900 ))
    _listening "$_p" || { PORT=$_p; break; }
done
[ "$PORT" = "0" ] && { echo "  no free port found"; exit 1; }
trap 'rm -rf "$TMP"; pkill -f "qbixserver.*--port=$PORT" 2>/dev/null' EXIT

ok()  { PASS=$((PASS+1)); printf "  ok   %s\n" "$1"; }
bad() { FAIL=$((FAIL+1)); printf "  FAIL %s\n" "$1"; }

# The macOS and Windows runners carry no system PHP, and the matrix job that
# runs this test does not install one -- but the build it has just finished
# produces a static php beside the server binary. Falling back to that keeps
# the test honest on every platform instead of failing the whole job, and a
# skipped release is what that failure actually costs.
if ! command -v "$PHP" >/dev/null 2>&1; then
    for _candidate in "$WS"/php-* "$WS"/php ./php-* ./php; do
        [ -x "$_candidate" ] || continue
        case "$_candidate" in *.sh|*.md|*.txt) continue;; esac
        PHP="$_candidate"
        break
    done
fi
command -v "$PHP" >/dev/null 2>&1 || {
    echo "no php: set PHP=/path/to/php, or build one alongside the server"
    exit 1
}

# Prefer the built binary: from source the wrapper is never handed a
# context and the notice cannot appear.
QB="${QB:-$WS/bin/qbixserver}"
if [ -x "$QB" ]; then
    RUN=("$QB")
    VIA="binary: $QB"
else
    RUN=("$PHP" "$WS/sbin/qbixserver.php")
    VIA="sources — the phar path is not exercised, this run proves little"
fi

ROOT="$TMP/public"; mkdir -p "$ROOT"
printf '<?php echo "EXACTLY-THIS";\n'                       > "$ROOT/plain.php"
printf '<?php session_start(); echo "SESSION-OK";\n'        > "$ROOT/sess.php"
printf '<?php header("Content-Type: application/json"); echo json_encode(["a"=>1]);\n' \
                                                            > "$ROOT/json.php"
cat > "$ROOT/inc.php" <<'PHP'
<?php
// Including another file is what opens the wrapper a second time.
require __DIR__ . '/lib.php';
echo greet();
PHP
printf '<?php function greet() { return "FROM-INCLUDE"; }\n' > "$ROOT/lib.php"

echo "=============================================="
echo " Qbix Server — no engine diagnostics in bodies"
echo "=============================================="
echo

echo "  via $VIA"
echo

# setsid is util-linux and does not exist on macOS. Without this fallback the
# server was never started there at all, and every assertion failed with an
# empty body -- which read exactly like a binary that returns nothing, and was
# reported as one for several releases. The detach is only so the trap can
# reap it; a subshell background job achieves the same where setsid is absent.
if command -v setsid >/dev/null 2>&1; then
    ( setsid "${RUN[@]}" --root="$ROOT" --port=$PORT --workers=2 \
        >"$TMP/server.log" 2>&1 </dev/null & )
else
    ( "${RUN[@]}" --root="$ROOT" --port=$PORT --workers=2 \
        >"$TMP/server.log" 2>&1 </dev/null & )
fi

for _ in $(seq 1 25); do
    sleep 0.4
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/plain.php" 2>/dev/null && break
done

body() { curl -s --max-time 15 "http://127.0.0.1:$PORT/$1" 2>/dev/null; }

# exact <label> <path> <expected body>
exact() {
    local got; got=$(body "$2")
    if [ "$got" = "$3" ]; then ok "$1"
    else
        bad "$1 — body was ${#got} bytes, expected ${#3}"
        printf '       first 120: %s\n' "$(printf '%s' "$got" | head -c 120 | tr '\n' ' ')"
    fi
}

exact "plain script: body is exactly its output"   plain.php "EXACTLY-THIS"
exact "session_start(): body is exactly its output" sess.php  "SESSION-OK"
exact "json: body is exactly its output"           json.php  '{"a":1}'
exact "include: body is exactly its output"        inc.php   "FROM-INCLUDE"

# Nothing diagnostic anywhere, whatever the wording.
for f in plain.php sess.php json.php inc.php; do
    b=$(body "$f")
    case "$b" in
        *Deprecated:*|*"Warning:"*|*"Notice:"*|*"Fatal error:"*|*"Parse error:"*)
            bad "$f leaks a diagnostic into the body" ;;
        *) ok "$f carries no diagnostic" ;;
    esac
done

# session_start() must not trip "headers already sent", which it does once
# a notice has been printed before it.
b=$(body sess.php)
case "$b" in
    *"headers have already been sent"*) bad "session warns about sent headers" ;;
    *) ok "session starts without a headers warning" ;;
esac

echo
echo "  passed: $PASS  failed: $FAIL"

# On failure the server's own output is the only thing that says why. Without
# it, a body that came back empty is indistinguishable from a body that came
# back wrong, and the macOS build spent several releases in exactly that state:
# failing this test with nothing to go on but the byte count.
if [ "$FAIL" -ne 0 ]; then
    echo
    echo "  --- how the server started ---"
    if [ -s "$TMP/server.log" ]; then
        sed 's/^/    /' "$TMP/server.log" | head -40
    else
        echo "    the server wrote nothing at all"
    fi
    echo
    echo "  --- what it is running on ---"
    printf '    binary : %s\n' "$QB"
    printf '    uname  : %s %s\n' "$(uname -s)" "$(uname -m)"
    if [ -x "$QB" ]; then
        printf '    version: %s\n' "$("$QB" --version 2>&1 | head -1)"
    fi
    echo
    echo "  --- is anything still listening on $PORT? ---"
    if command -v lsof >/dev/null 2>&1; then
        lsof -nP -iTCP:"$PORT" 2>/dev/null | sed 's/^/    /' | head -5
    elif command -v ss >/dev/null 2>&1; then
        ss -ltnp 2>/dev/null | grep ":$PORT " | sed 's/^/    /' | head -5
    fi
fi

[ "$FAIL" -eq 0 ] || exit 1
