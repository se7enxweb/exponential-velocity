#!/usr/bin/env bash
# The source kit: what it takes to build any variant of the server on a
# machine of your own -- for a platform, architecture or PHP version no
# release job covers, or to add an extension of your own.
#
#   bash packaging/ci/source-kit.sh <version>   -> qbixserver-source-kit-<version>.tar.gz
#
# It carries the tracked sources (git archive, so nothing untracked leaks in),
# the committed phar, the console tools, the baseline, build-binary.sh, and
# one ready-made spc recipe per platform x PHP version x variant, generated
# from the baseline exactly as the release matrix is.
set -euo pipefail
cd "$(dirname "$0")/../.."
version="${1:-dev}"
version="${version#refs/tags/}"
# A branch name can carry slashes (ci/foo); a file name cannot.
version="${version//\//-}"
name="qbixserver-source-kit-$version"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/$name"
git archive --format=tar HEAD | tar -x -C "$work/$name"

# One recipe per build the release matrix would run.
php packaging/ci/release-matrix.php '' '' '' 2>/dev/null \
  | sed -n 's/^matrix=//p' > "$work/matrix.json"
php packaging/ci/write-recipes.php "$work/matrix.json" "$work/$name/recipes"

cat > "$work/$name/BUILDING.md" <<EOF
# Building from the source kit ($version)

Pick a recipe from \`recipes/\` -- one per platform, PHP version and variant --
and run it on a machine of that platform with static-php-cli (spc) on PATH:

    sh recipes/linux-x86_64-php8.3-standard.sh

It downloads the sources, builds PHP with exactly the extensions that variant
carries, checks the result against the baseline, and combines it with
sbin/qbixserver.phar into \`qbixserver-<platform>-php<ver>-<variant>\`.

To see or change what a variant carries, ask the baseline:

    php sbin/qbixctl.php ext:list --variant=standard --platform=linux-x86_64 --php=8.3 --format=spc

build-binary.sh builds the same way with Docker. docs/binaries.md describes
the variants; docs/requirements.md the baseline and its exceptions.
EOF

tar -C "$work" -czf "$name.tar.gz" "$name"
ls -l "$name.tar.gz"
tar -tzf "$name.tar.gz" | grep -c '^' | sed 's/^/files: /'
