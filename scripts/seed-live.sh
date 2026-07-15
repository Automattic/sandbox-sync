#!/bin/bash
# Seeds live.local with test data for Sandbox Sync development:
#  - 10 lorem ipsum posts + 10 lorem ipsum pages (deterministic titles)
#  - 2 light plugins from wordpress.org, installed as real directories
#  - 1 light custom mu-plugin (zz-test-notice.php), copied as a real file
#
# Re-runnable: seeded posts are replaced, plugins force-reinstalled, the
# mu-plugin overwritten.

set -euo pipefail
# shellcheck source=scripts/common.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/common.sh"

echo "==> Seeding posts and pages on live.local ..."
run_wp "$LIVE_DIR" eval-file "$REPO_DIR/fixtures/seed-posts.php"

echo ""
echo "==> Installing 2 light test plugins on live.local ..."
run_wp "$LIVE_DIR" plugin install classic-widgets disable-emojis --activate --force

echo ""
echo "==> Installing the test mu-plugin on live.local ..."
mkdir -p "$LIVE_DIR/wp-content/mu-plugins"
cp "$REPO_DIR/fixtures/zz-test-notice.php" "$LIVE_DIR/wp-content/mu-plugins/zz-test-notice.php"
echo "Copied zz-test-notice.php to $LIVE_DIR/wp-content/mu-plugins/"

echo ""
echo "Live site seeded. Verify at $LIVE_URL"
