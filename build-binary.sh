#!/usr/bin/env bash
#
# Build a self-contained Qbix Server binary.
#
# The binary includes the PHP interpreter + all server code.
# No PHP installation needed on the target machine.
#
# Usage: ./build-binary.sh [--arch=x86_64|aarch64] [--os=linux|macos]
#
# Prerequisites:
#   - Docker (for cross-compilation) or local build tools
#   - ~2GB disk space for the build
#
# Output: sbin/qbixserver (or sbin/qbixserver-$OS-$ARCH), beside the phar it
# is made from, sbin/qbixserver.phar (docs/layout.md, "Programs")
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BIN_DIR="$SCRIPT_DIR/sbin"
SRC_DIR="$SCRIPT_DIR/src"

ARCH="${ARCH:-$(uname -m)}"
OS="${OS:-linux}"
PHP_VERSION="8.3"
VARIANT="standard"

# Parse args
for arg in "$@"; do
    case $arg in
        --arch=*) ARCH="${arg#*=}" ;;
        --os=*)   OS="${arg#*=}" ;;
        --php=*)  PHP_VERSION="${arg#*=}" ;;
        --variant=*) VARIANT="${arg#*=}" ;;
        --help)
            echo "Usage: $0 [--arch=x86_64|aarch64] [--os=linux|macos] [--php=8.2|8.3|8.4|8.5] [--variant=mini|lite|standard|full]"
            echo ""
            echo "Builds a self-contained Qbix Server binary. The extensions come from"
            echo "the baseline for that variant (docs/binaries.md, docs/requirements.md)."
            echo "Requires Docker for cross-compilation."
            exit 0
            ;;
    esac
done

mkdir -p "$BIN_DIR"

# What this variant carries on this platform: the baseline, not a list kept
# here (packaging/bin/qbix-ext is `qbixctl ext:list`).
case "$OS-$ARCH" in
    linux-x86_64|linux-amd64)  PLATFORM=linux-x86_64 ;;
    linux-aarch64|linux-arm64) PLATFORM=linux-aarch64 ;;
    macos-*|darwin-*)          PLATFORM=macos-arm64 ;;
    *) echo "no baseline platform for $OS/$ARCH" >&2; exit 2 ;;
esac
EXTS="$(sh "$SCRIPT_DIR/packaging/bin/qbix-ext" list --variant="$VARIANT" --platform="$PLATFORM" --php="$PHP_VERSION" --format=spc)"
LIBS="$(sh "$SCRIPT_DIR/packaging/bin/qbix-ext" list --variant="$VARIANT" --platform="$PLATFORM" --php="$PHP_VERSION" --format=libs)"
[ -n "$EXTS" ] || { echo "the baseline gave no extensions for $VARIANT on $PLATFORM" >&2; exit 2; }

echo "═══════════════════════════════════════════"
echo "  Building Qbix Server binary"
echo "  PHP: $PHP_VERSION"
echo "  OS:  $OS"
echo "  Arch: $ARCH"
echo "═══════════════════════════════════════════"
echo ""

# ── Method 1: static-php-cli (preferred) ─────────

build_with_static_php_cli() {
    echo "Using static-php-cli..."

    # Check if static-php-cli is available
    if ! command -v spc &>/dev/null; then
        echo "Installing static-php-cli..."
        # Download the latest release
        SPC_URL="https://github.com/crazywhalecc/static-php-cli/releases/latest/download/spc-linux-x86_64.tar.gz"
        if [ "$ARCH" = "aarch64" ] || [ "$ARCH" = "arm64" ]; then
            SPC_URL="https://github.com/crazywhalecc/static-php-cli/releases/latest/download/spc-linux-aarch64.tar.gz"
        fi
        curl -sL "$SPC_URL" | tar xz -C /tmp/
        chmod +x /tmp/spc
        SPC="/tmp/spc"
    else
        SPC="spc"
    fi

    # Build PHP micro SAPI with required extensions
    $SPC doctor --auto-fix 2>/dev/null || true
    $SPC download --with-php=$PHP_VERSION --for-extensions="$EXTS" ${LIBS:+--for-libs="$LIBS"}
    $SPC build "$EXTS" --build-micro ${LIBS:+--with-libs="$LIBS"} --debug

    MICRO_SFXN="buildroot/bin/micro.sfx"

    # First build the PHAR, then cat micro.sfx + phar = binary
    echo "Building PHAR for embedding..."
    php -d phar.readonly=0 "$SCRIPT_DIR/build-phar.php"

    echo "Combining micro.sfx + PHAR..."
    cat "$MICRO_SFXN" "$BIN_DIR/qbixserver.phar" > "$BIN_DIR/qbixserver"
    chmod +x "$BIN_DIR/qbixserver"

    echo ""
    echo "Binary built: $BIN_DIR/qbixserver"
    ls -lh "$BIN_DIR/qbixserver"
}

# ── Method 2: Docker-based build ─────────────────

build_with_docker() {
    echo "Using Docker for isolated build..."

    # Create a temporary build context
    TMPDIR=$(mktemp -d)
    cp -r "$SRC_DIR" "$TMPDIR/src"
    cp "$SCRIPT_DIR/build-phar.php" "$TMPDIR/"
    # build-phar.php requires these too: the programs in sbin/ and bin/ and
    # the forwarders at their former paths (build_phar_programs())
    mkdir -p "$TMPDIR/sbin" "$TMPDIR/bin"
    for f in qbixserver.php qbixctl.php qbixconsole.php qshell.php \
             sbin/qbixserver.php sbin/qbixctl.php sbin/qbixconsole.php bin/qshell.php; do
        cp "$SCRIPT_DIR/$f" "$TMPDIR/$f"
    done
    [ -d "$SCRIPT_DIR/web" ] && cp -r "$SCRIPT_DIR/web" "$TMPDIR/web"

    # EXTS and LIBS: the baseline for this variant, resolved above.

    cat > "$TMPDIR/Dockerfile" << DOCKERFILE
FROM php:$PHP_VERSION-cli-alpine AS builder

RUN apk add --no-cache curl bash tar

# Install static-php-cli
RUN curl -sL https://github.com/crazywhalecc/static-php-cli/releases/latest/download/spc-linux-x86_64.tar.gz \
    | tar xz -C /usr/local/bin/ && chmod +x /usr/local/bin/spc

WORKDIR /build

# Build the PHP micro SAPI BEFORE copying any source. This is the expensive
# step (tens of minutes); keeping it above the COPY lines means editing the
# server's PHP code reuses the cached layer instead of rebuilding PHP.
RUN spc doctor --auto-fix 2>/dev/null || true
RUN spc download --with-php=$PHP_VERSION --for-extensions=$EXTS ${LIBS:+--for-libs=$LIBS}
# gd's libraries have to be named. The download step pulls an extension's
# suggested sources by default, but the build links none of them unless
# asked, so gd came out able to read PNG only and imagejpeg() was an
# undefined function at runtime.
#
# Named rather than --with-suggested-libs, which also drags in libaom for
# AVIF; spc 2.8.5 cannot unpack it -- "Patch file
# [libaom_posix_implict.patch] failed to apply" -- and the build dies.
# AVIF output stays unavailable until that is fixed upstream, which
# Image.php already handles: it guards imageavif and declines.
RUN spc build "$EXTS" --build-micro ${LIBS:+--with-libs=$LIBS}

COPY src/ src/
COPY web/ web/
COPY build-phar.php qbixserver.php qbixctl.php qbixconsole.php qshell.php ./
COPY sbin/ sbin/
COPY bin/ bin/

# Parse every file before packaging it. build-phar.php only copies files
# in, so a syntax error travels into the binary and surfaces as a runtime
# fatal from a phar:// path -- after a full build, and with the build
# itself reporting success.
RUN find src sbin bin qbixserver.php qbixctl.php qbixconsole.php qshell.php -name '*.php' -print0 \
    | xargs -0 -n1 php -l > /dev/null

RUN php -d phar.readonly=0 build-phar.php

# Combine. micro:combine appends the phar as an ELF overlay that phpmicro
# locates by reading its own file at runtime -- never UPX-pack the result.
RUN spc micro:combine sbin/qbixserver.phar -O sbin/qbixserver && \
    chmod +x sbin/qbixserver
DOCKERFILE

    # One builder image per PHP version: a single tag would make every
    # switch throw away the other version's compiled PHP.
    IMAGE="qbixserver-builder:php$PHP_VERSION"
    docker rm -f qbix-extract >/dev/null 2>&1 || true
    docker build -t "$IMAGE" "$TMPDIR"
    docker create --name qbix-extract "$IMAGE"
    docker cp qbix-extract:/build/sbin/qbixserver "$BIN_DIR/qbixserver"
    docker rm qbix-extract
    # The builder image is deliberately kept. Deleting it drops its layers,
    # and with them the cached PHP build -- which is the whole point of
    # building the micro SAPI before the COPY lines above. Remove it by hand
    # (docker rmi qbixserver-builder) when you want the space back.

    rm -rf "$TMPDIR"

    echo ""
    echo "Binary built: $BIN_DIR/qbixserver"
    ls -lh "$BIN_DIR/qbixserver"
}

# ── Method 3: Manual build (fallback) ────────────

build_manual() {
    echo "Building PHAR (binary build requires static-php-cli or Docker)..."
    php -d phar.readonly=0 "$SCRIPT_DIR/build-phar.php"

    echo ""
    echo "PHAR built. For a static binary, install static-php-cli or use Docker:"
    echo "  Method A: curl -sL https://github.com/crazywhalecc/static-php-cli/... | tar xz"
    echo "  Method B: $0 --docker"
    echo ""
    echo "The PHAR works identically: php sbin/qbixserver.phar --port=8080"
}

# ── Choose build method ──────────────────────────

if [ "$1" = "--docker" ] && command -v docker &>/dev/null; then
    build_with_docker
elif command -v spc &>/dev/null || [ -f /tmp/spc ]; then
    build_with_static_php_cli
elif command -v docker &>/dev/null; then
    echo "static-php-cli not found, falling back to Docker build..."
    build_with_docker
else
    build_manual
fi

echo ""
echo "Done."
