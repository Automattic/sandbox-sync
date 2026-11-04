# Changelog

All notable public changes are documented here. Sandbox Sync follows semantic versioning; before 1.0, minor releases may include protocol or configuration changes.

## 0.1.1 - 2026-10-27

Protocol 15. Both sites must run this release; a mismatch fails closed at the manifest handshake.

### Fixed

- Upload and code file names longer than the 100-byte ustar name field (common for long headline-based media names) no longer abort the transfer with `tar_long_path`. The exporter now emits GNU long-name records and both extractors restore the full path; a single path is bounded at 4096 bytes.
- Staged code directories no longer fail SHA-256 validation when the source directory ships no `index.php` of its own (typically `mu-plugins/`): the staging placeholder is discarded before validation instead of being counted as an unexpected file.

## 0.1.0 - 2026-07-15

Initial public release.

### Transfer and recovery

- Authenticated pull-based synchronization for selected WordPress database tables, plugins, must-use plugins, themes, uploads, and approved `wp-config.php` constants.
- One-time pairing keys, per-sandbox revocable bearer credentials, scoped transfer sessions, verified public HTTPS, bounded requests, and cooperative cancellation.
- Fenced background/AJAX/CLI workers with durable jobs, checkpoints, logs, recovery journals, exact job-owned cleanup, and structured errors.
- Symlink-safe filesystem containment, verified tar/range transfers, atomic file installation, staged code activation, and guarded database activation with automatic failure rollback.

### Large-site paths

- Knife-like first uploads baseline: selected partitions stream directly by year with bounded parallelism, constant target memory, atomic per-file visibility, and early per-partition cursor commits.
- Incremental uploads generations avoid traversing clean historical partitions; dirty partitions metadata-diff in place and hash files while they stream. Manual audit and adopt-existing modes cover out-of-band or interrupted copies.
- Chunked, resumable database import into shadow tables with adaptive batches, keyset paging, schema/chunk/row validation, serialized-aware URL replacement, and one atomic table swap.
- Incremental `posts`/`postmeta` row-delta path that reduces network transfer for small content changes, with automatic fallback to full table import when schemas differ or the delta exceeds its budget.
- Optional CLI-only `mysqldump` to `mysql` streaming path with gzip framing, end-to-end hashing, statement validation, fenced attempt tables, and automatic fallback.

### Policy and operations

- Schema-only and excluded-table policies, including an empty-schema default for `newspack_newsletters_subscription_attempts`.
- Sandbox safety rules for outbound email/HTTP, secrets, risky integrations, search visibility, cron, and selected configuration constants without anonymizing editorial/user data.
- WordPress admin UI and WP-CLI commands for pairing, sync, status, cancellation, cleanup, history, logs, source emergency stop, and safety inspection.
- WordPress 6.9/7.0, PHP 7.4–8.5, MySQL, MariaDB, WP Cloud filesystem behavior, and failure recovery documented in the repository runbooks.
