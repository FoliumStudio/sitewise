#!/usr/bin/env bash
#
# Vendor the canonical Folium UI design library into this plugin at
# lib/folium-ui/. Run after the canonical FoliumStudio/folium-ui changes, then
# commit the result with the plugin.
#
# Usage:  scripts/sync-folium-ui.sh [path-to-canonical-folium-ui]
# Default canonical location: ../folium-ui (sibling of this plugin repo).
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CANON="${1:-$PLUGIN_DIR/../folium-ui}"
DEST="$PLUGIN_DIR/lib/folium-ui"

if [ ! -f "$CANON/folium-ui.php" ]; then
	echo "ERROR: canonical folium-ui not found at: $CANON" >&2
	echo "Pass the path explicitly: scripts/sync-folium-ui.sh /path/to/folium-ui" >&2
	exit 1
fi

echo "Syncing Folium UI"
echo "  from: $CANON"
echo "    to: $DEST"

rm -rf "$DEST"
mkdir -p "$DEST"

# Copy the runtime files (not the canonical repo's own README/.git/dev cruft).
cp "$CANON/loader.php"     "$DEST/"
cp "$CANON/folium-ui.php"  "$DEST/"
cp "$CANON/folium-ui.css"  "$DEST/"
cp "$CANON/folium-ui.js"   "$DEST/"
cp "$CANON/folium-app.js"  "$DEST/"
cp "$CANON/folium-overview.js" "$DEST/"
cp "$CANON/fonts.css"      "$DEST/"
cp "$CANON/VERSION"        "$DEST/"
mkdir -p "$DEST/fonts"
cp "$CANON"/fonts/*.woff2  "$DEST/fonts/"

VER="$(tr -d '[:space:]' < "$CANON/VERSION")"
echo "Vendored Folium UI v$VER ($(find "$DEST" -type f | wc -l) files)."
echo "Remember to: git add lib/folium-ui && commit."
