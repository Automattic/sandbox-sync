#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(sed -n "s/.*Version: \([0-9.]*\).*/\1/p" "$ROOT/mu-plugin/sandbox-sync.php")"

if [[ -z "$VERSION" ]]; then
	echo "Could not read the release version from mu-plugin/sandbox-sync.php." >&2
	exit 1
fi

OUTPUT="${1:-$ROOT/sandbox-sync-$VERSION.tar.gz}"
TEMP_ARCHIVE="$(mktemp "${TMPDIR:-/tmp}/sandbox-sync-release.XXXXXX.tar.gz")"
trap 'rm -f "$TEMP_ARCHIVE"' EXIT

# COPYFILE_DISABLE blocks macOS copyfile(3) metadata. The explicit libarchive
# flags cover extended attributes even when the environment variable is
# ignored; feature detection keeps the script usable with GNU tar in CI.
TAR_METADATA_FLAGS=()
if tar --version 2>&1 | grep -qi 'bsdtar'; then
	# macOS bsdtar supports these switches but does not list them in --help.
	TAR_METADATA_FLAGS+=( --no-xattrs --no-mac-metadata --no-fflags )
elif tar --help 2>&1 | grep -q -- '--no-xattrs'; then
	TAR_METADATA_FLAGS+=( --no-xattrs )
fi

COPYFILE_DISABLE=1 tar "${TAR_METADATA_FLAGS[@]}" \
	--exclude='.DS_Store' \
	--exclude='*/.DS_Store' \
	--exclude='._*' \
	--exclude='*/._*' \
	--exclude='__MACOSX' \
	--exclude='*/__MACOSX' \
	-czf "$TEMP_ARCHIVE" \
	-C "$ROOT/mu-plugin" \
	sandbox-sync sandbox-sync.php

mkdir -p "$(dirname "$OUTPUT")"
mv "$TEMP_ARCHIVE" "$OUTPUT"
chmod 0644 "$OUTPUT"
trap - EXIT

echo "$OUTPUT"
