#!/bin/bash
# Shared helpers for the sandbox-sync scripts.
# Resolves the repo/dev/site paths and provides run_wp <site-dir> <args...>,
# which uses the devbox environment when wp/php are not already available.

# ShellCheck analyzes this shared library as a standalone script, so variables
# intentionally consumed by its callers appear unused here.
# shellcheck disable=SC2034

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"

# The devbox WordPress environment (override with SANDBOX_SYNC_DEV_DIR).
DEV_DIR="${SANDBOX_SYNC_DEV_DIR:-$(cd "$REPO_DIR/../../dev" && pwd -P)}"
SITES_DIR="$DEV_DIR/sites"

LIVE_DIR="$SITES_DIR/live"
SANDBOX_DIR="$SITES_DIR/sandbox"

LIVE_URL="https://live.local"
SANDBOX_URL="https://sandbox.local"

WP_ADMIN_USER="adminnewspack"
WP_ADMIN_PASSWORD="admin"
WP_ADMIN_EMAIL="admin@localhost.local"

run_wp() {
    local site_dir="$1"
    shift
    if command -v wp >/dev/null 2>&1 && php -m 2>/dev/null | grep -qi '^mysqli$'; then
        wp --path="$site_dir" "$@"
    else
        devbox -c "$DEV_DIR" run -- wp --path="$site_dir" "$@"
    fi
}

# resolve_site <name-or-domain>: accepts "qa" or "qa.local" and sets
# SITE_NAME, SITE_DIR and SITE_URL for a site under $DEV_DIR/sites/.
resolve_site() {
	SITE_NAME="${1%.local}"
	case "$SITE_NAME" in
		''|*[!A-Za-z0-9_-]*)
			echo "Error: invalid site name '$SITE_NAME' (letters, numbers, underscore and hyphen only)." >&2
			exit 1
			;;
	esac
	SITE_DIR="$SITES_DIR/$SITE_NAME"
	SITE_URL="https://$SITE_NAME.local"
	if [ ! -d "$SITE_DIR" ]; then
		echo "Error: no site found at $SITE_DIR" >&2
		echo "Available sites:" >&2
		ls "$SITES_DIR" >&2
		exit 1
	fi

	# Canonical containment: destructive callers must operate on one direct,
	# non-symlinked child of sites/, never a traversal alias or external link.
	local sites_real site_real
	sites_real="$(cd "$SITES_DIR" && pwd -P)"
	if [ -L "$SITE_DIR" ]; then
		echo "Error: refusing symlinked site directory $SITE_DIR" >&2
		exit 1
	fi
	site_real="$(cd "$SITE_DIR" && pwd -P)"
	if [ "$(dirname "$site_real")" != "$sites_real" ]; then
		echo "Error: site path escapes the sites directory: $site_real" >&2
		exit 1
	fi
	SITE_DIR="$site_real"
}
