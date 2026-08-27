#!/bin/bash

# Script to automatically fix PHP coding standards issues
# Usage: bash scripts/fix.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
PLUGIN_DIR="$PROJECT_ROOT/wp-content/plugins/awesome-calendar-events"

echo "Running PHP Code Beautifier and Fixer..."
cd "$PLUGIN_DIR"
composer phpcbf || true

echo ""
echo "Running JavaScript formatter..."
npm run format || true
