# Compatibility matrix

## Supported application/runtime baseline

| Component | Supported baseline | Preflight behavior |
| --- | --- | --- |
| WordPress | 6.9.x and 7.0.x, single-site | Multisite is rejected. The integration matrix targets WordPress 6.9.4 and 7.0.1. |
| PHP | 7.4 through 8.5 | PHP below 7.4 is rejected. The constant-memory first uploads baseline requires cURL; OpenSSL and zlib are strongly recommended. |
| MySQL | 5.7+; 8.0+ recommended | Older versions are rejected. Source/target family, version, SQL mode, packet and collation data are checked. |
| MariaDB | 10.3+; 10.11+ recommended | Older versions are rejected; unavailable target collations fail preflight. |
| HTTPS | Required for public sources | Plain HTTP is accepted only for explicitly local development hosts. |

The PHP 7.4 and older database floors exist for Newspack environment compatibility, not as recommendations for new deployments. Prefer a currently supported PHP branch and MySQL 8.0+ or MariaDB 10.11+.

## Database objects

Supported tables must use InnoDB and reproduce the source `SHOW CREATE TABLE` structurally on the target (the table name, `AUTO_INCREMENT` counter, and column charset or collation clauses equal to the table default are not compared). Non-empty full-sync tables require a primary key for resumable keyset transfer and row-safe URL rewriting. Empty primary-key-less tables are supported normally because only their schema is transferred. A table explicitly selected as schema-only may contain source rows without a primary key because no rows are requested or copied; its exact schema activates as an empty table with normal rollback coverage. Views, triggers, foreign keys, non-InnoDB engines, unavailable collations, generated primary keys in full-sync tables, and rows above the negotiated statement/packet ceiling fail closed. Generated non-key columns are omitted from full-sync inserts and reproduced by the target schema.

`options`, `users` and `usermeta` cannot be schema-only because sandbox identity preservation requires their complete row data. Excluded tables retain existing sandbox contents and override a duplicate schema-only selection. `newspack_newsletters_subscription_attempts` defaults to schema-only because it is disposable recovery data containing production subscriber email addresses.

One atomic `RENAME TABLE` activates the complete imported set. The configured table-count ceiling must pass before transfer. Prefix-sensitive roles, capabilities, and the initiating administrator identity/session are mapped explicitly.

Incremental row delta is limited to the canonical `posts` and `postmeta` tables. It requires matching source/target schemas, an existing local table, and a delta below the configured row/ratio ceilings. Planning scans the complete `ID`/`post_modified_gmt` indexes and clones the complete local tables on the sandbox before applying changed rows. It therefore reduces network bytes but does not reduce sandbox database copy, binlog, or temporary-capacity requirements. Changes that do not advance normal WordPress post-modified state require a full sync/audit.

### Database capacity

Sandbox Sync does not block on a database free-space estimate. Managed database services such as WP Cloud keep the database filesystem and quota outside the PHP container, so `@@datadir` cannot provide a path whose free space PHP can measure. Treating that unknown value as zero blocks every managed-hosting sync, while treating a guessed value as free capacity provides false assurance.

Database imports are built in job-owned shadow tables and do not replace active sandbox tables until schema and exact per-chunk row-count validation succeeds. If the database service exhausts its capacity, the import fails before activation and cleanup can remove the registered working tables. This protects database integrity, but it can waste transfer time. Operators must therefore monitor and provision database capacity at the hosting-platform layer. Filesystem archive/staging capacity remains independently checked because that filesystem is visible to PHP. The live source is never paused or frozen during any of this.

## Filesystems

The source and target must provide local rename semantics within `WP_CONTENT_DIR`. Staging is placed on the same filesystem as the active target. Hosts that forbid renaming the well-known `plugins/`, `mu-plugins/`, or `themes/` directories themselves (WP Cloud: `EPERM` on the directory inode) are still supported: Sandbox Sync evacuates and promotes top-level children instead of renaming the directory. The first uploads baseline additionally needs long-lived, unbuffered source responses and target PHP/cURL requests; test platform proxy/FPM timeouts with representative year sizes before production use. Symlinks are preserved locally but never traversed, exported, overwritten, or deleted. Unix modes and mtimes are retained where supported; ownership, ACLs and extended attributes are outside the protocol.

Uploads must be a local filesystem tree with stable directory iteration and atomic rename behavior. Remote/object-backed stream wrappers, sparse-file fidelity, ownership, ACLs, and extended attributes are not supported by protocol 16. Path components longer than the 100-byte ustar name field travel as GNU long-name records (protocol 15+); a single path may not exceed 4096 bytes.

## Scheduling and HTTP infrastructure

Background mode requires a functioning WordPress cron runner. If `DISABLE_WP_CRON` is true, configure a real system scheduler to run due WordPress events, keep the Sandbox Sync admin page open so its authenticated ticks drive the job, or use foreground WP-CLI. A cron event that has already launched is an active PHP process; deleting the event does not kill that request.

The source URL must be reachable from the sandbox without suspension, maintenance, password-protection, or proxy rules that return nonstandard errors to the sync endpoints. Long baseline streams and shell dumps require proxies, load balancers, PHP-FPM, and the hosting platform to permit the documented request duration and unbuffered response behavior.
