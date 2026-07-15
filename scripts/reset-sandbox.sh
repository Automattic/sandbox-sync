#!/bin/bash
# Resets a sandbox site to a pristine state so the sync test can be repeated:
#  - drops and reinstalls the WordPress database (fresh core install)
#  - wipes non-symlinked plugins, mu-plugins and uploads
#    (symlinked dev-repo plugins and the sandbox-sync loader are kept;
#     themes are left alone so the fresh install still has one)
#
# Usage: scripts/reset-sandbox.sh [site] [--yes]
#   site: name or domain of a site under dev/sites/ — "sandbox" (default),
#         "qa", "qa.local", ...
#
# After a reset the site is unpaired (fresh DB). Re-pair with
# scripts/pair.sh [site] or through the admin UI.

set -euo pipefail
# shellcheck source=scripts/common.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/common.sh"

SITE_ARG="sandbox"
ASSUME_YES=0
for ARG in "$@"; do
	if [ "$ARG" = "--yes" ]; then
		ASSUME_YES=1
	else
		SITE_ARG="$ARG"
	fi
done

resolve_site "$SITE_ARG"

# Safety: this script destroys the site's database and files.
LIVE_REAL="$(cd "$LIVE_DIR" && pwd -P)"
if [ "$SITE_NAME" = "live" ] || [ "$SITE_DIR" = "$LIVE_REAL" ]; then
	echo "Error: refusing to reset the live site." >&2
	exit 1
fi

if [ "$ASSUME_YES" -ne 1 ]; then
	if [ ! -t 0 ]; then
		echo "Error: reset is destructive; pass --yes in non-interactive use." >&2
		exit 1
	fi
	printf "Type '%s' to permanently reset %s: " "$SITE_NAME" "$SITE_DIR"
	read -r CONFIRM
	if [ "$CONFIRM" != "$SITE_NAME" ]; then
		echo "Reset cancelled." >&2
		exit 1
	fi
fi

echo "==> Resetting the $SITE_URL database ..."
run_wp "$SITE_DIR" db reset --yes

echo "==> Reinstalling WordPress on $SITE_URL ..."
run_wp "$SITE_DIR" core install \
    --url="$SITE_URL" \
    --title="$SITE_NAME" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --skip-email

run_wp "$SITE_DIR" rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true

echo "==> Wiping non-symlinked plugins/mu-plugins/uploads on $SITE_URL ..."
for DIR in plugins mu-plugins uploads; do
    TARGET="$SITE_DIR/wp-content/$DIR"
    [ -d "$TARGET" ] || continue
    find "$TARGET" -mindepth 1 -maxdepth 1 ! -type l -exec rm -rf {} +
    echo "  wiped non-symlinked entries in wp-content/$DIR"
done

echo ""
echo "Site reset. Admin: $SITE_URL/wp-admin ($WP_ADMIN_USER / $WP_ADMIN_PASSWORD)"
echo "Re-pair with: scripts/pair.sh $SITE_NAME (or via Tools > Sandbox Sync)."
