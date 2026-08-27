#!/usr/bin/env bash
#
# Run the awesome-calendar-events unit tests.
#
# The tests live outside the plugin directory (./tests) so they are never
# included in the release package. They run against a minimal in-memory
# WordPress shim, so no database is required.
#
# Usage: scripts/run-tests.sh [extra phpunit args...]

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

PHPUNIT="${PHPUNIT:-phpunit}"

if ! command -v "$PHPUNIT" >/dev/null 2>&1; then
    if [ -x "$PROJECT_ROOT/vendor/bin/phpunit" ]; then
        PHPUNIT="$PROJECT_ROOT/vendor/bin/phpunit"
    else
        echo "Error: phpunit not found." >&2
        echo "Install it globally or run: composer require --dev phpunit/phpunit" >&2
        exit 1
    fi
fi

cd "$PROJECT_ROOT"
exec "$PHPUNIT" --configuration phpunit.xml.dist "$@"
