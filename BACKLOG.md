# Production-readiness backlog

This backlog tracks the work remaining after the 0.1.0 code-level review. “Production ready” means safe, supportable use with sensitive production data—not merely a successful local or WP Cloud test sync.

## P0 — release gates

### PR-001 — Enforce the full CI/integration matrix

Owner: repository/platform

Estimate: 2–4 engineer-days

- Run the configured PHP 7.4/8.3/8.5 quality gate and WordPress 6.9/7.0 × MySQL 8/MariaDB 10.11 integration matrix in hosted CI.
- Make both workflows required branch checks; pin/review third-party actions and dependency updates.
- Acceptance: two consecutive clean default-branch runs; artifacts/logs retained; no allowed-to-fail cells.

### PR-002 — Add destructive fault, recovery, and concurrency integration tests

Owner: sync engineering

Estimate: 1–2 engineer-weeks

- Inject disconnect/timeout/429/500, corrupt range/tar/JSON, disk-full, DB error, safety failure, worker death, lease expiry, cancel, and cron+AJAX+CLI overlap at every mutation boundary.
- Verify no partial activation, transfer-session expiry, exact rollback, checkpoint resume, and no unrelated resource deletion.
- Acceptance: deterministic tests cover every state transition and pass 100 repeated concurrency runs under ThreadSanitizer-equivalent orchestration/process stress.

### PR-003 — Define and enforce the infrastructure safety contract

Owner: platform/security

Estimate: 1 engineer-week plus platform rollout

- Deny sandbox egress at the network layer except approved source/control endpoints; block direct SMTP/raw sockets/system binaries.
- Require private ingress or strong access control, environment banner, noindex headers, isolated secrets, encrypted storage/backups, and documented data retention/deletion.
- Provide an attested preflight signal so production-data sync is blocked when the environment lacks the policy.
- Acceptance: automated policy test proves forbidden egress/ingress paths fail independently of WordPress hooks.

### PR-004 — Complete protocol identity, replay, and credential lifecycle

Owner: application security

Estimate: 1–2 engineer-weeks

- Bind pairing to a verified sandbox key/challenge, label credentials, sign requests with timestamp+nonce/body hash, and retain a bounded replay cache.
- Add seamless rotate-before-expiry with overlap/revocation; preserve emergency manual re-pair.
- Commission an external protocol/security review and remediate all critical/high findings.
- Acceptance: stolen pairing codes cannot enroll another identity; replayed requests fail; rotation does not interrupt an active scoped job.

### PR-005 — Ship a reproducible, signed release pipeline

Owner: release engineering

Estimate: 3–5 engineer-days

- Build a minimal versioned artifact without dev fixtures/tools; generate SBOM, checksums, provenance and signature.
- Define protocol/config/job migration policy, upgrade/downgrade compatibility, release notes and supported-version window.
- Acceptance: clean-machine build is byte-reproducible, signature-verifiable, dependency-audited, and install/upgrade/rollback tested.

### PR-006 — Provide operator-controlled rollback and recovery drills

Owner: sync engineering/operations

Estimate: 1 engineer-week

- Add configurable post-success DB/file rollback retention, `rollback --job=<id>`, dry-run, identity verification and expiry cleanup.
- Keep automatic failure rollback; require typed confirmation and an execution lease for manual rollback.
- Acceptance: operators restore the prior site after a completed sync in a timed drill on both database families; recovery point/retention is documented.

### PR-007 — Production observability, alerts, and runbooks

Owner: SRE

Estimate: 3–5 engineer-days

- Emit structured events/metrics for bytes, rows, throughput, retries, phase latency, worker/session/guard age, rollback, error category and source/target IDs without secrets/PII.
- Alert on stale jobs, expiring transfer sessions/credentials, guard expiry, rollback failure, capacity exhaustion and repeated integrity failures.
- Acceptance: dashboards and on-call runbooks diagnose each injected PR-002 failure from telemetry alone.

## P1 — scale and operational robustness

### PR-101 — Pluggable infrastructure snapshot provider

Add hosting/database snapshot APIs for deployments that require one transactionally consistent database-wide cut. Record snapshot identity/age and export only from immutable snapshot storage. Keep the current always-online per-table mode for providers without snapshots. Acceptance: cross-table consistency test under continuous source writes.

### PR-102 — Default resumable streaming database wire format

The optional CLI shell path already streams large tables, but the portable web-worker path still uses gzip+base64 JSON SQL chunks. Add a versioned framed stream carrying schema, row batches, per-frame hashes, and durable resume points without requiring local database binaries. Acceptance: multi-GB BLOB table transfers stay below the documented memory ceiling and resume after worker death.

### PR-103 — Low-write logical database deltas

Replace the protocol-14 full index scan/local table clone with a filesystem-backed logical-object journal populated by WordPress mutation hooks. Coalesce one request into one immutable segment, transfer only dirty post/user/term/option bundles, rewrite URLs before the database write, and apply bounded changes once in a short target transaction. Do not add source database triggers or source journal tables, so ordinary tracking creates no source binlog traffic. Unsupported tables, missing/overflowed journals, schema changes, or large deltas fall back automatically to the current full-table path. Provide an explicit full audit for direct SQL/SFTP-style changes that bypass WordPress hooks. Acceptance: a one-post sync performs no full table/index scan, writes only changed target rows, and completes within the measured incremental SLO without changing first-sync timing.

### PR-104 — Normalize job, step, event, and artifact persistence

Move mutable job state out of one WordPress option into versioned job/step/checkpoint records; store manifests/archive/range artifacts in a job-owned local/object store with TTL and quotas. Acceptance: 1M-file plans do not depend on option/object-cache size and survive process/host replacement.

### PR-105 — Define large-site activation strategy

Measure metadata locks and query limits for hundreds/thousands of tables. Choose and document fail-closed table-count/lock budgets or a snapshot/blue-green database switch; do not silently sacrifice atomicity. Acceptance: bounded cutover SLO under representative load.

### PR-106 — Scale and soak qualification

Test 100k/1M files, multi-GB uploads, postmeta/options, huge rows, 1k tables, low disk/memory, proxy buffering, object cache and slow links for 24-hour soak runs. Publish supported limits and capacity formulas.

### PR-107 — Post-activation health checks

Add extensible HTTP/CLI/database checks behind the activation guard before backups are finalized. Acceptance: any failed check automatically rolls back and records a structured diagnosis.

### PR-108 — Organization safety profiles

Ship versioned policy packs for custom PII fields, webhooks, search/analytics/payment integrations and row exclusions. Require review/approval for production-data profiles. Acceptance: data-classification fixtures prove every declared field/integration is neutralized.

### PR-109 — Extended filesystem fidelity or explicit rejection

Decide support for sparse files, ownership, ACLs, xattrs and remote/object-backed uploads. Preserve and verify supported metadata; detect and reject unsupported required semantics during preflight.

## P2 — product maturity and future features

- PR-201: internationalize all PHP/JavaScript strings; complete accessibility and keyboard/screen-reader review.
- PR-202: job-ID-targeted status/cancel/resume APIs, richer throughput/retry UI, downloadable redacted support bundle.
- PR-203: content-aware compression and optional direct object-storage multipart transfer.
- PR-204: missing-media proxy with signed source URLs, cache controls and privacy safeguards.
- PR-205: multisite/network model (explicitly unsupported until identity, network tables/plugins and safety semantics are designed/tested).
- PR-206: scheduled/recurring sync policy with maintenance windows, approvals, quotas and notification integrations.

## Recommended sequence and budget

1. PR-001 and PR-002 establish trustworthy evidence.
2. PR-003 and PR-004 establish the production security boundary.
3. PR-005, PR-006 and PR-007 make releases and incidents supportable.
4. Qualify the first production cohort with PR-101/105/106 limits; implement PR-102–104 when measured scale requires them.

Expected effort for the remaining production gates is roughly **6–10 engineer-weeks**, plus external security review and platform rollout. Publishing the pre-1.0 source does not waive those gates: a first sensitive-data production cohort should not start until every P0 acceptance criterion is met, there are no unresolved critical/high security findings, restore/rollback drills pass, and supported scale limits are published.
