#!/bin/bash
# Fixtures and checks for the plugins and themes a sync keeps because only the
# sandbox has them. The WordPress integration workflow runs the phases around
# its syncs; they work against any source/sandbox pair on one machine.
#
# Usage: fixtures/kept-entries.sh <phase> <source-path> <sandbox-path>
#   seed          before the first sync (the sandbox has no record yet)
#   verify-first  after the first sync
#   change        before a later sync: local installs, removals on the source
#   verify-kept   after any later sync

set -euo pipefail

PHASE="${1:?Usage: kept-entries.sh <phase> <source-path> <sandbox-path>}"
SOURCE="${2:?Missing source path}"
SANDBOX="${3:?Missing sandbox path}"

SOURCE_CONTENT="$SOURCE/wp-content"
SANDBOX_CONTENT="$SANDBOX/wp-content"
# Symlink targets live outside both sites, like a platform's managed packages.
PACKAGES="$(dirname "$SOURCE")/ss-packages"

fail() {
	echo "kept-entries [$PHASE]: $1" >&2
	exit 1
}

write_plugin() {
	local dir="$1" slug="$2" name="$3"
	mkdir -p "$dir"
	printf '<?php\n/**\n * Plugin Name: %s\n */\n' "$name" >"$dir/$slug.php"
}

write_theme() {
	local dir="$1" name="$2"
	mkdir -p "$dir"
	printf '/*\nTheme Name: %s\n*/\n' "$name" >"$dir/style.css"
	printf '<?php\n' >"$dir/index.php"
}

# A build installed over a package the source runs as a symlink: a real
# directory, with the symlink it displaced kept beside it.
assert_local_builds() {
	local kind
	for kind in plugins/ss-managed-plugin themes/ss-managed-theme; do
		if [ ! -d "$SANDBOX_CONTENT/$kind" ] || [ -L "$SANDBOX_CONTENT/$kind" ]; then
			fail "$kind is no longer a real directory"
		fi
		[ "$(cat "$SANDBOX_CONTENT/$kind/BUILD" 2>/dev/null)" = 'sandbox build' ] || fail "$kind lost its own files"
		[ -L "$SANDBOX_CONTENT/$kind--symlink" ] || fail "$kind--symlink is no longer a symlink"
	done
}

# A kept entry is left where it is. The directory found after a sync must be
# the one that was there before it, not a copy swapped into its place: work
# done in it while a long sync runs would be lost with the copy.
KEPT_IN_PLACE='plugins/ss-sandbox-plugin plugins/ss-managed-plugin themes/ss-sandbox-theme themes/ss-managed-theme'

inode_of() {
	php -r 'clearstatcache(); echo fileinode( $argv[1] );' "$1"
}

remember_inodes() {
	local entry
	mkdir -p "$PACKAGES/inodes"
	for entry in $KEPT_IN_PLACE; do
		inode_of "$SANDBOX_CONTENT/$entry" >"$PACKAGES/inodes/${entry//\//-}"
	done
}

assert_left_in_place() {
	local entry
	for entry in $KEPT_IN_PLACE; do
		[ "$(inode_of "$SANDBOX_CONTENT/$entry")" = "$(cat "$PACKAGES/inodes/${entry//\//-}")" ] || fail "$entry was replaced by a copy instead of being left in place"
	done
}

# record_has <option> <name>: whether the record of synced entries lists <name>.
record_has() {
	wp option get "$1" --format=json --path="$SANDBOX" | NAME="$2" php -r '$names = json_decode( stream_get_contents( STDIN ), true ); exit( is_array( $names ) && in_array( getenv( "NAME" ), $names, true ) ? 0 : 1 );'
}

case "$PHASE" in
	seed)
		write_plugin "$SOURCE_CONTENT/plugins/ss-live-plugin" ss-live-plugin 'SS Live Plugin'
		write_theme "$SOURCE_CONTENT/themes/ss-live-theme" 'SS Live Theme'
		wp plugin activate ss-live-plugin --path="$SOURCE" --quiet

		write_plugin "$PACKAGES/ss-managed-plugin" ss-managed-plugin 'SS Managed Plugin'
		write_theme "$PACKAGES/ss-managed-theme" 'SS Managed Theme'
		ln -s "$PACKAGES/ss-managed-plugin" "$SOURCE_CONTENT/plugins/ss-managed-plugin"
		ln -s "$PACKAGES/ss-managed-theme" "$SOURCE_CONTENT/themes/ss-managed-theme"

		write_plugin "$SANDBOX_CONTENT/plugins/ss-managed-plugin" ss-managed-plugin 'SS Managed Plugin'
		write_theme "$SANDBOX_CONTENT/themes/ss-managed-theme" 'SS Managed Theme'
		printf 'sandbox build' >"$SANDBOX_CONTENT/plugins/ss-managed-plugin/BUILD"
		printf 'sandbox build' >"$SANDBOX_CONTENT/themes/ss-managed-theme/BUILD"
		ln -s "$PACKAGES/ss-managed-plugin" "$SANDBOX_CONTENT/plugins/ss-managed-plugin--symlink"
		ln -s "$PACKAGES/ss-managed-theme" "$SANDBOX_CONTENT/themes/ss-managed-theme--symlink"

		# Present before any record exists: indistinguishable from a plugin the
		# source removed, so the first sync still removes it.
		write_plugin "$SANDBOX_CONTENT/plugins/ss-first-sync-only" ss-first-sync-only 'SS First Sync Only'
		;;

	verify-first)
		[ -f "$SANDBOX_CONTENT/plugins/ss-live-plugin/ss-live-plugin.php" ] || fail 'the source plugin did not arrive'
		[ -f "$SANDBOX_CONTENT/themes/ss-live-theme/style.css" ] || fail 'the source theme did not arrive'
		wp plugin is-active ss-live-plugin --path="$SANDBOX" || fail 'the source plugin is not active on the sandbox'
		[ ! -e "$SANDBOX_CONTENT/plugins/ss-first-sync-only" ] || fail 'a sandbox-only plugin survived a sync that had no record'
		assert_local_builds
		record_has sandbox_sync_synced_plugins ss-live-plugin || fail 'the plugins record does not list what the sync wrote'
		record_has sandbox_sync_synced_themes ss-live-theme || fail 'the themes record does not list what the sync wrote'
		! record_has sandbox_sync_synced_plugins ss-managed-plugin || fail 'the plugins record lists a build the sync never wrote'
		! record_has sandbox_sync_synced_themes ss-managed-theme || fail 'the themes record lists a build the sync never wrote'
		;;

	change)
		write_plugin "$SANDBOX_CONTENT/plugins/ss-sandbox-plugin" ss-sandbox-plugin 'SS Sandbox Plugin'
		printf '<?php\n/**\n * Plugin Name: SS Sandbox Single\n */\n' >"$SANDBOX_CONTENT/plugins/ss-sandbox-single.php"
		write_theme "$SANDBOX_CONTENT/themes/ss-sandbox-theme" 'SS Sandbox Theme'
		wp plugin activate ss-sandbox-plugin ss-sandbox-single ss-managed-plugin --path="$SANDBOX" --quiet
		remember_inodes

		wp plugin deactivate ss-live-plugin --path="$SOURCE" --quiet
		rm -rf "$SOURCE_CONTENT/plugins/ss-live-plugin" "$SOURCE_CONTENT/themes/ss-live-theme"
		;;

	verify-kept)
		[ -f "$SANDBOX_CONTENT/plugins/ss-sandbox-plugin/ss-sandbox-plugin.php" ] || fail 'the sandbox-only plugin directory is gone'
		[ -f "$SANDBOX_CONTENT/plugins/ss-sandbox-single.php" ] || fail 'the sandbox-only single-file plugin is gone'
		[ -f "$SANDBOX_CONTENT/themes/ss-sandbox-theme/style.css" ] || fail 'the sandbox-only theme is gone'
		assert_local_builds
		assert_left_in_place
		for plugin in ss-sandbox-plugin ss-sandbox-single ss-managed-plugin; do
			wp plugin is-active "$plugin" --path="$SANDBOX" || fail "$plugin is no longer active"
		done
		[ ! -e "$SANDBOX_CONTENT/plugins/ss-live-plugin" ] || fail 'a plugin removed on the source is still on the sandbox'
		[ ! -e "$SANDBOX_CONTENT/themes/ss-live-theme" ] || fail 'a theme removed on the source is still on the sandbox'
		! record_has sandbox_sync_synced_plugins ss-live-plugin || fail 'the plugins record still lists a removed plugin'
		! record_has sandbox_sync_synced_plugins ss-sandbox-plugin || fail 'the plugins record lists a sandbox-only plugin'
		! record_has sandbox_sync_synced_themes ss-sandbox-theme || fail 'the themes record lists a sandbox-only theme'
		wp sandbox-sync status --format=json --path="$SANDBOX" | php -r '
			$status = json_decode( stream_get_contents( STDIN ), true );
			$report = is_array( $status ) && isset( $status["last_report"] ) && is_array( $status["last_report"] ) ? $status["last_report"] : array();
			$ok     = array( "ss-managed-plugin", "ss-sandbox-plugin", "ss-sandbox-single.php" ) === ( $report["kept_plugins"] ?? null )
				&& array( "ss-managed-theme", "ss-sandbox-theme" ) === ( $report["kept_themes"] ?? null );
			exit( $ok ? 0 : 1 );
		' || fail 'the last report does not list the kept plugins and themes'
		;;

	*)
		fail "unknown phase '$PHASE'"
		;;
esac

echo "kept-entries [$PHASE]: ok"
