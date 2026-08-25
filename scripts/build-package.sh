#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
PLUGIN_SLUG="awesome-events"
PLUGIN_DIR="$PROJECT_ROOT/wp-content/plugins/$PLUGIN_SLUG"
PACKAGE_PATH="$PROJECT_ROOT/package.zip"
STAGING_DIR="$(mktemp -d)"

cleanup() {
    rm -rf "$STAGING_DIR"
}
trap cleanup EXIT

for command_name in rsync zip; do
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "Error: $command_name is required to build the package." >&2
        exit 1
    fi
done

echo "Staging $PLUGIN_SLUG..."
mkdir -p "$STAGING_DIR/$PLUGIN_SLUG"
rsync -a \
    --exclude='.DS_Store' \
    --exclude='node_modules/' \
    --exclude='vendor/' \
    --exclude='composer.json' \
    --exclude='composer.lock' \
    --exclude='package.json' \
    --exclude='package-lock.json' \
    "$PLUGIN_DIR/" "$STAGING_DIR/$PLUGIN_SLUG/"
cp "$PROJECT_ROOT/LICENSE" "$STAGING_DIR/$PLUGIN_SLUG/LICENSE"

rm -f "$PACKAGE_PATH"
(
    cd "$STAGING_DIR"
    zip -qr "$PACKAGE_PATH" "$PLUGIN_SLUG"
)

echo "Built $PACKAGE_PATH"
