#!/usr/bin/env bash
# Flattens the build artifacts into the files a release carries.
#
#   bash packaging/ci/assemble-release.sh <artifacts dir> <dist dir>
#
# - every qbixserver-<platform>-php<ver>-<variant>[.exe|-gui.exe] and
#   php-<platform>-php<ver>-<variant>[.exe], as built;
# - the source kit, the committed phar, and the deb and rpm packages;
# - qbix_fork.dll once (it does not depend on the PHP version or variant);
# - the names releases used before variants existed (qbixserver-linux-x86_64,
#   php-linux-x86_64, qbixserver-windows-x64.exe, ...), as copies of the
#   standard variant on PHP 8.3, so links to releases/latest/download/ keep
#   working;
# - SHA256SUMS over all of it.
set -euo pipefail
src="${1:?artifacts dir}"; dist="${2:?dist dir}"
LEGACY_PHP="${LEGACY_PHP:-8.3}"
LEGACY_VARIANT="${LEGACY_VARIANT:-standard}"
mkdir -p "$dist"

find "$src" -type f \( -name 'qbixserver-*' -o -name 'php-*' -o -name '*.deb' -o -name '*.rpm' \) ! -name '*.dll' -print0 |
  while IFS= read -r -d '' f; do
    base=$(basename "$f")
    if [ -e "$dist/$base" ]; then echo "::error::two artifacts named $base"; exit 1; fi
    cp "$f" "$dist/$base"
  done

dll=$(find "$src" -type f -name qbix_fork.dll | head -1 || true)
[ -n "$dll" ] && cp "$dll" "$dist/qbix_fork.dll"

cp sbin/qbixserver.phar "$dist/qbixserver.phar"

for f in "$dist"/*-php"$LEGACY_PHP"-"$LEGACY_VARIANT"*; do
  [ -e "$f" ] || continue
  base=$(basename "$f")
  legacy=${base/-php$LEGACY_PHP-$LEGACY_VARIANT/}
  cp "$f" "$dist/$legacy"
done

missing=0
for want in qbixserver-linux-x86_64 qbixserver-linux-aarch64 qbixserver-macos-arm64; do
  [ -e "$dist/$want" ] || { echo "::warning::no $want (the $LEGACY_VARIANT PHP $LEGACY_PHP build is missing)"; missing=1; }
done

( cd "$dist" && sha256sum -- * | grep -v ' SHA256SUMS$' > SHA256SUMS )
echo "release files: $(ls "$dist" | wc -l)"
ls -l "$dist" | sed 's/^/  /'
exit 0
