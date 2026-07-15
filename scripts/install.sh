#!/bin/bash
# Installs the Sandbox Sync mu-plugin on both live.local and sandbox.local by
# symlinking the loader into each site's wp-content/mu-plugins/.
#
# Symlinking (instead of copying) means:
#  - a single checkout serves both sites, and
#  - the loader is automatically excluded from its own file sync (the tar
#    exporter and the sandbox wipe both skip symlinks).

set -euo pipefail
# shellcheck source=scripts/common.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/common.sh"

LOADER="$REPO_DIR/mu-plugin/sandbox-sync.php"

if [ ! -f "$LOADER" ]; then
    echo "Error: loader not found at $LOADER" >&2
    exit 1
fi

for SITE_DIR in "$LIVE_DIR" "$SANDBOX_DIR"; do
    if [ ! -d "$SITE_DIR/wp-content" ]; then
        echo "Error: $SITE_DIR does not look like a WordPress install." >&2
        exit 1
    fi
    MU_DIR="$SITE_DIR/wp-content/mu-plugins"
    mkdir -p "$MU_DIR"
    ln -sfn "$LOADER" "$MU_DIR/sandbox-sync.php"
    echo "Installed: $MU_DIR/sandbox-sync.php -> $LOADER"
done

echo ""
echo "Done. Open Tools > Sandbox Sync on both sites:"
echo "  $LIVE_URL/wp-admin/tools.php?page=sandbox-sync"
echo "  $SANDBOX_URL/wp-admin/tools.php?page=sandbox-sync"
