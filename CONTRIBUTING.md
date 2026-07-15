# Contributing

## Local checks

The dependency-free checks require PHP only:

```sh
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
php tests/run-tests.php
bash -n scripts/*.sh
node --check mu-plugin/sandbox-sync/assets/admin.js
```

Install the locked development tools with Composer, then run the full gate:

```sh
composer install
composer validate --strict
composer audit --locked
composer check
shellcheck scripts/*.sh
```

`composer check` runs the dependency-free suite, WordPress coding standards, and PHPStan. Changes to locking, transfer, archive, activation, authentication, or safety behavior must include a failure-path test. Database and WordPress lifecycle changes also require the destructive two-site workflow in `.github/workflows/integration.yml`; do not infer end-to-end safety from the shimmed unit suite. For system shape and chosen design constraints, see [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Compatibility

Do not add syntax newer than PHP 7.4 without intentionally raising the runtime floor and updating CI, preflight, and [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md). Protocol-breaking changes must increment `SANDBOX_SYNC_PROTOCOL`. A release must keep the plugin header and `SANDBOX_SYNC_VERSION` identical and update [CHANGELOG.md](CHANGELOG.md).

## Release checklist

1. Confirm the release version is identical in the plugin header, `SANDBOX_SYNC_VERSION`, changelog, and current-release documentation.
2. Run all local checks above, parse both workflow YAML files, and obtain clean hosted CI plus WordPress 6.9/7.0 × MySQL 8/MariaDB 10.11 integration runs.
3. Exercise cancellation and process-death recovery during file extraction, table import, activation, and safety.
4. Review locked dependency advisories, protocol compatibility, threat-model changes, and every release note.
5. Build the archive, inspect its complete file list, extract it into a clean `wp-content/mu-plugins/`, and verify its version and absence of development/Mac metadata.
6. Verify installation and automatic failure rollback on a disposable environment; do not deploy over an active sync.
7. Commit source only, tag an immutable release, and retain its migration/recovery notes and checksums. Generated `.tar.gz` archives remain ignored and must not be committed.

Build distributable archives with `scripts/build-release.sh`. It disables
macOS extended attributes and excludes `.DS_Store`, AppleDouble `._*`, and
`__MACOSX` entries; do not create release archives with a bare `tar` command.
