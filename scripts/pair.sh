#!/bin/bash
# Pairs a sandbox site with live.local via WP-CLI (same code path as the admin
# UI): generates a one-time pairing key on live, performs the handshake from
# the sandbox, and sets its "this site is a sandbox" guardrail flag.
#
# Usage: scripts/pair.sh [site]
#   site: name or domain of a site under dev/sites/ — "sandbox" (default),
#         "qa", "qa.local", ...

set -euo pipefail
# shellcheck source=scripts/common.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/common.sh"

resolve_site "${1:-sandbox}"

if [ "$SITE_NAME" = "live" ]; then
    echo "Error: cannot pair the live site with itself." >&2
    exit 1
fi

echo "==> Generating a pairing key on live.local ..."
KEY=$(run_wp "$LIVE_DIR" sandbox-sync generate-key | tail -n 1 | tr -d '[:space:]')

if [ -z "$KEY" ]; then
    echo "Error: could not generate a pairing key on live.local" >&2
    exit 1
fi

echo "==> Connecting $SITE_URL to $LIVE_URL ..."
run_wp "$SITE_DIR" sandbox-sync connect "$LIVE_URL" "$KEY"

echo "==> Marking $SITE_URL as a sandbox (direction guardrail) ..."
run_wp "$SITE_DIR" sandbox-sync mark-sandbox
