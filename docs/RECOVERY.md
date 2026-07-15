# Recovery runbook

## Normal controls

- `wp sandbox-sync status` shows the current job and owned resources.
- `wp sandbox-sync cancel` requests cooperative cancellation; the fenced worker rolls back at its next checkpoint.
- `wp sandbox-sync cleanup` is for a finished or stale job. It acquires the job lease, restores active database/file backups when needed, closes the scoped source session, and deletes only resources registered to that job.

Do not manually drop `ssx_*` or `ssxbak_*` tables based on their prefix. The ownership registry in the job record is authoritative.

Cancellation is not a process-kill signal. Deleting the `sandbox_sync_process_job` cron event prevents a future worker but cannot terminate a PHP request that is already hashing, streaming, extracting, or executing a database query. After `cancel`, wait for a new log entry or terminal status showing that the active worker reached a checkpoint. If the source must stop serving an in-flight transfer immediately, run `wp sandbox-sync source-stop` on the live site too; the request still exits cooperatively at its next source-side check.

## Dormant background worker

If log timestamps stop advancing, verify the sandbox's WP-Cron/system-cron runner before changing sync state. Opening the Sandbox Sync admin page can push an idle job forward, but that is a recovery assist rather than a production scheduler. A dormant cron pauses the job; it does not cancel an in-flight source request.

The bearer-bound source transfer grant has a 12-hour idle lease and slides forward on every transfer request. During long local hashing/validation/extraction, durable checkpoints send a keepalive at most every 15 minutes. If a worker returns after the source grant has expired, it first requests renewal; only the stable `transfer_owner` missing/expired response permits recovery. Recovery recreates the same transfer ID and exact directory, constant, table and schema-only scope, then resumes from durable file/database checkpoints. Other authentication or scope errors fail closed.

## Failed activation

Database activation retains the previous tables under job-owned `ssxbak_*` names while the traffic guard covers deferred file activation and safety. Finalization removes the guard only after the complete snapshot is ready. A recoverable error invokes one atomic reverse database rename and restores each activated plugins, mu-plugins or themes directory from its job-owned backup.

Uploads deliberately use a lower-disk recovery model: verified files are atomically installed in place, while validated extraneous files are renamed (not copied) into the job quarantine and restored if the job rolls back. Additions and replacements installed before a failure are not reverted; they remain as verified retry residue and the next incremental run will reuse or replace them. This means uploads never require a complete rollback copy, but cleanup must not delete `.sandbox-sync-work/<job>/uploads-quarantine` while a job is active or recoverable.

A missing or different-source uploads cursor uses a distinct first-baseline recovery model. Selected YYYY/MM partitions are grouped into year requests (with `other` separate), each selected target partition is cleared once, and raw tar bytes are parsed directly into one-file temporary writes plus atomic renames. No complete archive, duplicate year or per-file hash manifest is retained. The worker renews its local fenced lease and checks cancellation throughout the request. A completed group commits its partitions to the per-partition cursor immediately using the plan-time generations. During an interrupted group, the durable resume cursor advances after each installed path; the retry keeps the partial tree and asks the source to resume after that path instead of clearing or retransmitting the whole year. Previously completed groups are never retransmitted. After all groups, the normal generation final delta runs; activation upgrades every selected partition to the delta-validated final generations. Deletion-free adopt/incremental partitions also early-commit once their comparison or last transfer finishes. If a job died after copying files for partitions that never reached a usable checkpoint (for example a much older version, corrupt recovery state, or a deletion-bearing adopt scope), rerun with `--adopt-uploads` (or the "Adopt existing uploads" UI checkbox): every uncommitted selected partition is metadata-diffed in place against the live manifest and only missing or changed files are re-downloaded, instead of clearing and re-streaming the full baseline.

For ordinary uploads batches, streamed hashes are persisted into the job-owned batch before the first file is installed. A replacement worker therefore verifies already-installed entries by SHA-256 and resumes only the unfinished remainder. Cached archives remain untrusted until their signed trailing manifest is checked during extraction. Files above 32 MiB use their existing content-addressed range cache and resume from the last completed range.

Every primary batch JSON also has a checksummed record in `.sandbox-sync-work/<job>/batches/recovery.ndjson`. If a later PHP/cron worker cannot see or parse an individual primary batch on shared storage, it retries filesystem discovery and atomically rebuilds the primary from the newest valid journal record. The journal is job-owned recovery state and must remain until finalization or explicit failed-job cleanup.

Fast-upload generations are stored outside the media tree in `WP_CONTENT_DIR/.sandbox-sync-uploads-state.php`; each sandbox keeps its independent applied cursor in the `sandbox_sync_uploads_cursor` option. Do not copy source cursors between sandboxes or manually advance generations. A missing cursor intentionally triggers a new destructive selected-scope bulk baseline; removing the cursor is therefore an explicit request to re-stream those scopes. Corrupt/unknown generation state fails closed; preserve the file for diagnosis.

During activation the frontend receives 503, but Tools → Sandbox Sync, its AJAX ticks, WP-Cron, and WP-CLI stay reachable so operators can monitor and resume. The guard expires automatically after one hour; inspect the job before removing `.sandbox-sync-activation-guard.php` manually.

## Row-delta retry behavior

Incremental `posts`/`postmeta` row delta scans the complete lightweight posts index and clones the complete current sandbox table into a job-owned shadow before applying changed rows. A worker failure before publication leaves only registered shadow/checkpoint tables; cleanup or the next attempt can remove them safely. The clone itself is one database operation and cannot checkpoint in the middle. If its query is killed, times out, encounters a schema mismatch, or exceeds the configured delta budget, the runner abandons that path and uses the normal full table import.

The delta detector uses `ID` and `post_modified_gmt`. Run a full sync/audit after direct SQL or plugin behavior that changes content without advancing normal post-modified state; the row delta is not a database change-data-capture system.

## Ambiguous database state

If the job reports `rollback_state`, stop all writes and take a database snapshot before intervening. Compare every target/shadow/backup name in `sandbox_sync_job.db_swap.mapping`. Never guess from a prefix scan. Restore all mappings in one `RENAME TABLE` statement or recover from the platform backup.

## Credential incident

Revoke the affected sandbox on the source, rotate/re-pair it, remove stale transfer files after verifying ownership, and review the source-side authentication audit metadata. Treat the bearer as authorization to read production database/content data.
