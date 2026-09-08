#!/usr/bin/env bash
#
# Publish the Awesome Calendar Events plugin to the WordPress.org SVN repository.
#
# This script:
#   1. Reads the "Version:" header from the plugin's main PHP file.
#   2. Copies the plugin source into the SVN working copy's "trunk" directory
#      (excluding development-only files).
#   3. Copies the plugin assets (icons, banners, screenshots) into the SVN
#      working copy's "assets" directory and commits it.
#   4. Commits trunk.
#   5. Creates a "tags/<version>" copy from trunk and commits it
#      (skipped with --no-tag).
#
# Usage:
#   scripts/publish.sh [--dry-run] [--no-tag] [SVN_DIR]
#
# SVN_DIR defaults to <repo-root>/../wp-svn/awesome-calendar-events
#
# With --dry-run (or -n) the script reports what it would do but does not
# modify the working copy or commit anything to the remote repository.
# With --no-tag the script syncs and commits trunk and assets but does not
# create or commit a version tag.
#
# Requirements: rsync, svn (Subversion command-line client).
set -euo pipefail

# Resolve the repository root (the parent of this script's directory).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

PLUGIN_SLUG="awesome-calendar-events"
PLUGIN_DIR="$REPO_ROOT/wp-content/plugins/$PLUGIN_SLUG"
PLUGIN_MAIN_FILE="$PLUGIN_DIR/$PLUGIN_SLUG.php"

# Parse arguments: optional --dry-run/-n and --no-tag flags and an optional
# SVN_DIR.
DRY_RUN=0
NO_TAG=0
SVN_DIR=""
for arg in "$@"; do
    case "$arg" in
        --dry-run|-n) DRY_RUN=1 ;;
        --no-tag) NO_TAG=1 ;;
        *) SVN_DIR="$arg" ;;
    esac
done

# SVN working copy for the plugin (contains trunk/, tags/, assets/).
SVN_DIR="${SVN_DIR:-$REPO_ROOT/../wp-svn/$PLUGIN_SLUG}"

# ---------------------------------------------------------------------------
# Sanity checks
# ---------------------------------------------------------------------------
for cmd in svn rsync; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "Error: required command not found: $cmd" >&2
        exit 1
    fi
done

if [ ! -f "$PLUGIN_MAIN_FILE" ]; then
    echo "Error: plugin main file not found: $PLUGIN_MAIN_FILE" >&2
    exit 1
fi

if [ ! -d "$SVN_DIR" ]; then
    echo "Error: SVN working copy not found: $SVN_DIR" >&2
    echo "Check it out first, e.g.:" >&2
    echo "  svn checkout https://plugins.svn.wordpress.org/$PLUGIN_SLUG $SVN_DIR" >&2
    exit 1
fi

if [ ! -d "$SVN_DIR/.svn" ]; then
    echo "Error: $SVN_DIR is not an SVN working copy (no .svn directory)." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Extract the version from the plugin header (e.g. "Version: 1.4.1")
# ---------------------------------------------------------------------------
VERSION="$(grep -iE '^[[:space:]*]*Version:' "$PLUGIN_MAIN_FILE" \
    | head -n 1 \
    | sed -E 's/.*Version:[[:space:]]*//' \
    | tr -d '[:space:]')"

if [ -z "$VERSION" ]; then
    echo "Error: could not determine plugin version from $PLUGIN_MAIN_FILE" >&2
    exit 1
fi

TRUNK_DIR="$SVN_DIR/trunk"
TAG_DIR="$SVN_DIR/tags/$VERSION"
ASSETS_DIR="$REPO_ROOT/assets"

echo "Plugin slug : $PLUGIN_SLUG"
echo "Version     : $VERSION"
echo "Source      : $PLUGIN_DIR"
echo "Assets      : $ASSETS_DIR"
echo "SVN dir     : $SVN_DIR"
if [ "$DRY_RUN" -eq 1 ]; then
    echo "Mode        : DRY RUN (no changes will be committed)"
fi
if [ "$NO_TAG" -eq 1 ]; then
    echo "Tagging     : skipped (--no-tag)"
fi
echo

# Refuse to overwrite an existing tag.
if [ "$NO_TAG" -eq 0 ]; then
    if [ -e "$TAG_DIR" ]; then
        echo "Error: tag already exists: $TAG_DIR" >&2
        echo "Bump the Version in $PLUGIN_SLUG.php before publishing." >&2
        exit 1
    fi
    if svn info "^/$PLUGIN_SLUG/tags/$VERSION" >/dev/null 2>&1; then
        echo "Error: tag $VERSION already exists in the remote repository." >&2
        echo "Bump the Version in $PLUGIN_SLUG.php before publishing." >&2
        exit 1
    fi
fi

# Make sure the working copy is up to date.
echo "Updating SVN working copy..."
svn update "$SVN_DIR"

mkdir -p "$TRUNK_DIR"

# ---------------------------------------------------------------------------
# Sync plugin source into trunk (excluding development-only files).
# ---------------------------------------------------------------------------
RSYNC_OPTS=(-a --delete
    --exclude='.svn/'
    --exclude='.git/'
    --exclude='.gitignore'
    --exclude='.DS_Store'
    --exclude='node_modules/'
    --exclude='package-lock.json'
    --exclude='package.json'
    --exclude='phpunit.xml'
    --exclude='phpcs.xml.dist'
    --exclude='tests/')
if [ "$DRY_RUN" -eq 1 ]; then
    echo "Changes that would be synced into trunk:"
    rsync "${RSYNC_OPTS[@]}" --dry-run --itemize-changes "$PLUGIN_DIR/" "$TRUNK_DIR/"
    echo
    echo "Changes that would be synced into assets:"
    rsync "${RSYNC_OPTS[@]}" --dry-run --itemize-changes "$ASSETS_DIR/" "$SVN_DIR/assets/"
    echo
    echo "Dry run complete. Would commit trunk and assets$( [ "$NO_TAG" -eq 1 ] || echo ", then create tag $VERSION" )."
    exit 0
fi
echo "Syncing plugin source into trunk..."
rsync "${RSYNC_OPTS[@]}" "$PLUGIN_DIR/" "$TRUNK_DIR/"

# ---------------------------------------------------------------------------
# Sync plugin assets (icons, banners, screenshots) into the SVN assets dir.
# These live at the repository root, NOT inside trunk.
# ---------------------------------------------------------------------------
SVN_ASSETS_DIR="$SVN_DIR/assets"
if [ ! -d "$SVN_ASSETS_DIR" ]; then
    echo "Creating SVN assets directory..."
    mkdir -p "$SVN_ASSETS_DIR"
    svn add "$SVN_ASSETS_DIR" >/dev/null
fi
echo "Syncing plugin assets..."
rsync "${RSYNC_OPTS[@]}" "$ASSETS_DIR/" "$SVN_ASSETS_DIR/"

# ---------------------------------------------------------------------------
# Stage adds/deletes in trunk.
# ---------------------------------------------------------------------------
echo "Staging changes in trunk..."
# Schedule newly-added (unversioned) files for addition.
svn add --force "$TRUNK_DIR" >/dev/null
# Schedule missing (deleted) files for removal.
svn status "$TRUNK_DIR" | awk '/^!/ {print $2}' | while IFS= read -r missing; do
    [ -n "$missing" ] && svn delete "$missing" >/dev/null
done

TRUNK_STATUS="$(svn status "$TRUNK_DIR")"
if [ -n "$TRUNK_STATUS" ]; then
    echo "Committing trunk..."
    svn commit "$TRUNK_DIR" -m "Update trunk to version $VERSION"
else
    echo "No changes to commit in trunk."
fi

# ---------------------------------------------------------------------------
# Stage adds/deletes in assets, then commit.
# ---------------------------------------------------------------------------
echo "Staging changes in assets..."
svn add --force "$SVN_ASSETS_DIR" >/dev/null
svn status "$SVN_ASSETS_DIR" | awk '/^!/ {print $2}' | while IFS= read -r missing; do
    [ -n "$missing" ] && svn delete "$missing" >/dev/null
done

ASSETS_STATUS="$(svn status "$SVN_ASSETS_DIR")"
if [ -n "$ASSETS_STATUS" ]; then
    echo "Committing assets..."
    svn commit "$SVN_ASSETS_DIR" -m "Update plugin assets"
else
    echo "No changes to commit in assets."
fi

# ---------------------------------------------------------------------------
# Create and commit the tag from trunk.
# ---------------------------------------------------------------------------
if [ "$NO_TAG" -eq 1 ]; then
    echo
    echo "Skipping tag creation (--no-tag)."
    echo
    echo "Done. Synced $PLUGIN_SLUG (trunk and assets) without tagging."
    exit 0
fi

echo "Creating tag $VERSION..."
svn copy "$TRUNK_DIR" "$TAG_DIR"
svn commit "$TAG_DIR" -m "Tag version $VERSION"

echo
echo "Done. Published $PLUGIN_SLUG version $VERSION."
