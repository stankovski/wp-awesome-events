#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
PLUGIN_DIR="$PROJECT_ROOT/wp-content/plugins/awesome-calendar-events"
PLUGIN_CHECK_DIR="${PLUGIN_CHECK_DIR:-$PROJECT_ROOT/wp-content/plugins/plugin-check}"
PHPCS="$PLUGIN_CHECK_DIR/vendor/bin/phpcs"
RULESET="$PLUGIN_CHECK_DIR/phpcs-rulesets/plugin-review.xml"

if ! command -v php >/dev/null 2>&1; then
    echo "Error: PHP is required to validate the plugin." >&2
    exit 1
fi

if [[ ! -f "$PHPCS" || ! -f "$RULESET" ]]; then
    echo "Error: the official WordPress Plugin Check plugin is required." >&2
    echo "Install it at $PLUGIN_CHECK_DIR or set PLUGIN_CHECK_DIR to its location." >&2
    exit 1
fi

echo "Checking PHP syntax..."
while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null
done < <(find "$PLUGIN_DIR" -type f -name '*.php' -print0)

echo "Checking WordPress plugin review rules..."
php "$PHPCS" \
    --standard="$RULESET" \
    --extensions=php \
    "$PLUGIN_DIR"
