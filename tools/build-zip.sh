#!/usr/bin/env bash
#
# Builds dist/missivus-<version>.zip with missivus/ as the top-level folder.
#
# Allowlist, not blocklist: only what the plugin needs at runtime ships. No tests, no tools,
# no docs-internal, no composer files, no .github, no development configs.

set -euo pipefail

cd "$(dirname "$0")/.."

VERSION=$(sed -n 's/^ \* Version: *//p' missivus.php | tr -d '[:space:]')

if [ -z "$VERSION" ]; then
    echo "Could not read the version from missivus.php" >&2
    exit 1
fi

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/missivus"

# The allowlist.
cp missivus.php uninstall.php readme.txt LICENSE "$STAGE/missivus/"
cp -R src "$STAGE/missivus/src"
cp -R languages "$STAGE/missivus/languages"

# Belt and braces: nothing hidden, nothing OS-droppings.
find "$STAGE" -name '.DS_Store' -delete
find "$STAGE" -name '.*' -type f -delete

mkdir -p dist
rm -f "dist/missivus-$VERSION.zip"

( cd "$STAGE" && zip -rq "missivus-$VERSION.zip" missivus )
mv "$STAGE/missivus-$VERSION.zip" "dist/missivus-$VERSION.zip"

echo "Built dist/missivus-$VERSION.zip"
unzip -l "dist/missivus-$VERSION.zip" | tail -3
