#!/usr/bin/env bash
#
# Push the CURRENT working copy of the Sitewise plugin to the disposable live
# test site (pepslogin.com on the peps-general server) over SSH/rsync. This is
# the fast dev loop — test on a real WordPress server instead of a local stack.
# It is NOT the wp.org release path (that's .github/workflows/deploy.yml, on a
# version tag). Safe to run anytime; if the test site breaks, no harm done.
#
# What ships = the same files wp.org would get (dev cruft excluded, see below).
# The vendored lib/folium-ui/ goes along automatically.
#
# Usage:
#   scripts/deploy-test.sh              # sync + fix ownership + activate
#   scripts/deploy-test.sh --no-activate
#   scripts/deploy-test.sh --dry-run    # show what would transfer, change nothing
#
set -euo pipefail

# ---- target config -------------------------------------------------------- #
SSH_HOST="peps-general"                                   # ~/.ssh/config alias
WP_ROOT="/root/www/pepslogin.com/htdocs"
SLUG="wp-call-me-back"                                    # wp.org slug = plugin dir
DEST="$WP_ROOT/wp-content/plugins/$SLUG"
OWNER="pepslogin68617:pepslogin68617"                     # web user (so WP can manage it)
# --------------------------------------------------------------------------- #

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PLUGIN_DIR"

ACTIVATE=1
DRYRUN=""
for arg in "$@"; do
	case "$arg" in
		--no-activate) ACTIVATE=0 ;;
		--dry-run) DRYRUN="--dry-run" ;;
		*) echo "Unknown option: $arg" >&2; exit 2 ;;
	esac
done

# Exclude dev-only files — keep this in step with .distignore.
EXCLUDES=(
	--exclude '.git' --exclude '.github' --exclude '.gitignore' --exclude '.distignore'
	--exclude '.editorconfig' --exclude 'phpcs.xml' --exclude 'phpcs.xml.dist' --exclude '_config.yml'
	--exclude 'scripts' --exclude 'dist' --exclude 'build' --exclude 'node_modules'
	--exclude 'worker'
	--exclude '_original-wp-call-me-back'
	--exclude '.wordpress-org'
	--exclude 'CONCEPT.md' --exclude 'CONCEPT.html' --exclude 'scrape.md'
	--exclude 'TODO.md' --exclude 'CHANGELOG.md'
	--exclude 'Folium Suite - Standalone.html'
	--exclude 'to-add or fix'
	--exclude '.DS_Store' --exclude '*/.DS_Store' --exclude 'Thumbs.db' --exclude '*/Thumbs.db'
)

echo "Syncing $SLUG → $SSH_HOST:$DEST"
ssh "$SSH_HOST" "mkdir -p '$DEST'"

# --delete so files removed locally are removed on the test site too.
rsync -az --delete $DRYRUN "${EXCLUDES[@]}" ./ "$SSH_HOST:$DEST/"

if [ -n "$DRYRUN" ]; then
	echo "(dry run — nothing changed)"
	exit 0
fi

# Match ownership to the web user so WordPress can read/update the plugin.
ssh "$SSH_HOST" "chown -R '$OWNER' '$DEST'"

if [ "$ACTIVATE" -eq 1 ]; then
	echo "Activating via wp-cli…"
	ssh "$SSH_HOST" "cd '$WP_ROOT' && wp --allow-root plugin activate '$SLUG'" || \
		echo "  (activation skipped/failed — activate manually in wp-admin if needed)"
fi

echo "Done. Live at: https://pepslogin.com/wp-admin/admin.php?page=sitewise"
