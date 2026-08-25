#!/bin/bash

# Script to run PHP CodeSniffer for code standards checking
# Usage: bash scripts/lint.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
PLUGIN_DIR="$PROJECT_ROOT/wp-content/plugins/awesome-events"

echo "Running PHP CodeSniffer..."
cd "$PLUGIN_DIR"
composer phpcs || true

echo ""
echo "Running JavaScript linter..."
npm run lint:js || true
