# Sandbox Sync

Pull a live WordPress site into a sandbox over authenticated HTTPS: selected database tables and code are staged before activation, uploads are updated in place with bounded recovery, and post-sync safety rules protect the cloned environment.

One must-use plugin is installed on **both** the live site and each sandbox. The sandbox initiates every sync; the live site is never paused. Multiple sandboxes can pair to one source.

**Status:** 0.1.0 initial public release. The protocol and configuration remain pre-1.0 and may change between minor releases. Review [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md) and the remaining [production-readiness backlog](BACKLOG.md) before using sensitive production data.

| Doc | Contents |
|-----|----------|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Structure, design decisions, job/code flow |
| [docs/THREAT-MODEL.md](docs/THREAT-MODEL.md) | Trust boundaries and controls |
| [docs/RECOVERY.md](docs/RECOVERY.md) | Failed activation, guards, cleanup |
| [SECURITY.md](SECURITY.md) | Reporting and security notes |
| [CHANGELOG.md](CHANGELOG.md) | Release history |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Checks, protocol bumps, release gate |
| [BACKLOG.md](BACKLOG.md) | Production-readiness work after 0.1.0 |

---

## Features

### Pairing and guardrails

- One-time pairing codes (expire, single-use); source stores bearer **hashes** only; sandbox seals its token locally.
- Per-sandbox revoke; token expiry and failure rate limits.
- Explicit **sandbox flag** required before overwrite; sites that still have paired sandboxes refuse to be synced into.
- UI confirmation summarizing what will be replaced; refuses syncing a site onto itself.

### Sync engine

- Selective DB + `plugins/` / `mu-plugins/` / `themes/` / `uploads/`.
- Incremental mode (file manifests + table `CHECKSUM TABLE` skips).
- Background jobs (WP-Cron) or foreground UI/CLI slices with a fenced lease.
- Cancel from sandbox; emergency `source-stop` on live.
- Durable job log files + sync history.

### Database

- Shadow-table import, serialized-aware URL search-replace, atomic `RENAME TABLE` activation.
- Incremental table checksums; when `posts`/`postmeta` checksums miss, a **row delta** can clone the local table and transfer only changed posts (and their scoped metadata) via `(ID, post_modified_gmt)` — protocol 14+.
- Excluded tables (keep sandbox data) and schema-only tables (empty recreate; default includes newsletter subscription attempts).
- Opt-in shell fast path (`mysqldump` → `mysql`) when both sites enable it.

### Files

- Code dirs: staged, hashed, then activated (content-swap on hosts that cannot rename `plugins/` etc.).
- Uploads: in-place + quarantine; year-streamed first baseline; per-partition cursor; adopt and full-audit modes.
- Symlinks never followed, synced, overwritten, or deleted.
- Plugins and themes that only the sandbox has are kept. An entry of `plugins/` or `themes/` that is neither on live nor written by an earlier sync is left where it is, not copied or moved, so work done in it while a sync runs is not lost. A kept plugin that was active stays active. An entry live removes after a sync is still removed from the sandbox.
- The first sync after upgrading has no record of earlier syncs yet, so it still removes what live does not have. The start confirmation names what that is.
- A Newspack Manager branch build is kept too, from the first sync, with its `<slug>--symlink` backup beside it: live runs that package as a symlink, so the real directory on the sandbox can only be a local build. For a theme build this needs live to run this release as well.

### Sandbox safety (post-activation)

Default-on rules scrub secrets, newsletter/Jetpack credentials, risky plugins, mail/HTTP, Woo automations, and search indexing; optional cron disable; safety and selected live wp-config constants written to the real site wp-config.php. Users and editorial content are left as synced from live. See Architecture / admin UI / `wp sandbox-sync safety-rules` for what each rule changes.

---

## UI (Tools → Sandbox Sync)

**On the live (source) site**

- Generate a pairing key.
- List paired sandboxes (paired-at, token expiry).
- Revoke one sandbox or all.

**On the sandbox**

- Connect with live URL + pairing key.
- Toggle **This site is a sandbox**.
- Disconnect from source.
- Sync options:
  - Database on/off; excluded tables; schema-only tables.
  - Directories: plugins, mu-plugins, themes, uploads.
  - Incremental; run in background.
  - Adopt existing uploads / rescan all uploads (advanced).
  - Plugin picker, uploads year/month scopes, wp-config constants to replicate.
  - Safety rule checkboxes.
- Start sync (with confirmation), live progress + step log, Cancel.
- Sync history with per-job log **View**.

While the activation guard is up, the frontend returns 503; this admin screen and its AJAX ticks stay available.

---

## CLI (`wp sandbox-sync`)

Run on the appropriate site (`--path=`). Destructive `run` asks for confirmation unless `--yes`.

| Command | Role | Purpose |
|---------|------|---------|
| `generate-key` | Source | Print a one-time pairing key |
| `connect <url> <key>` | Sandbox | Pair with live |
| `mark-sandbox` / `mark-sandbox --off` | Sandbox | Direction flag |
| `run` | Sandbox | Run a sync (foreground slices) |
| `status` | Either | Connection, flag, job, paired sandboxes |
| `cancel` | Sandbox | Cooperative cancel |
| `cleanup` | Sandbox | Clear a finished/failed job and work dirs |
| `history` | Sandbox | Recent reports |
| `log <job_id>` | Sandbox | Dump durable job log file |
| `disconnect` | Sandbox | Drop source connection |
| `revoke` / `revoke <hash-prefix>` | Source | Revoke all or one sandbox |
| `source-stop` | Source | Emergency abort of active transfers |
| `safety-rules` | Either | List safety rules, defaults, and what each changes |
| `source-plugins` | Sandbox | List live plugins for `--plugins=` |
| `source-constants` | Sandbox | List replicable wp-config names |

**`run` flags:** `--db-only`, `--files-only`, `--skip-uploads`, `--full`, `--audit-uploads`, `--adopt-uploads`, `--exclude-tables=`, `--schema-only-tables=`, `--plugins=`, `--uploads=`, `--constants=`, `--safety=`, `--no-safety`, `--dry-run`, `--yes`, `--user=<login>` (preserve that admin across DB swap).

---

## Development environment

Local Newspack **devbox** layout (override root with `SANDBOX_SYNC_DEV_DIR`):

```
<Newspack>/dev/sites/live      → https://live.local
<Newspack>/dev/sites/sandbox  → https://sandbox.local
```

This repo lives at `<Newspack>/tools/sandbox-sync`. Helper scripts resolve `wp` via the host or `devbox -c "$DEV_DIR" run -- wp …`.

Default admin (seed/pair scripts): `adminnewspack` / `admin`.

### Quick start

```bash
scripts/install.sh      # symlink loader into live + sandbox mu-plugins/
scripts/seed-live.sh    # fixture posts/plugins on live
scripts/pair.sh         # pair sandbox + set sandbox flag
# Then sync from UI, or:
wp --path=../../dev/sites/sandbox sandbox-sync run --user=adminnewspack --yes
```

### Repeatable loop

```bash
scripts/seed-live.sh
scripts/reset-sandbox.sh   # confirmation required; --yes for automation
scripts/pair.sh
# sync again
```

`reset-sandbox.sh` / `pair.sh` accept another site name under `dev/sites/` (e.g. `qa`). Resetting `live` is refused.

### Tests and release

```bash
php tests/run-tests.php          # dependency-free suite
composer install && composer check
scripts/build-release.sh         # sandbox-sync-<version>.tar.gz
```

Generated archives are intentionally ignored by Git and should not be committed.

---

## Deploy

The same mu-plugin must be installed on **both** the live (source) site and each sandbox. Do not deploy while a sync job or source transfer is active.

### 1. Build the release archive

```bash
scripts/build-release.sh
# → sandbox-sync-<version>.tar.gz
```

Use this script (not a bare `tar`): it strips macOS metadata and excludes `.DS_Store` / `__MACOSX` junk. Version comes from the plugin header in `mu-plugin/sandbox-sync.php`.

### 2. Install on each site

Extract into `wp-content/mu-plugins/` so you get the loader plus package directory:

```bash
tar -xzf sandbox-sync-<version>.tar.gz -C /path/to/wp-content/mu-plugins
```

That writes:

```
wp-content/mu-plugins/sandbox-sync.php
wp-content/mu-plugins/sandbox-sync/
```

Confirm in wp-admin (**Tools → Sandbox Sync**) or:

```bash
wp eval 'echo SANDBOX_SYNC_VERSION;'
```

Then pair and sync as usual (UI or CLI above). Local dev can keep using `scripts/install.sh` (symlinks) instead of the tarball.

---

## Layout

```
mu-plugin/          Plugin loader + classes (see docs/ARCHITECTURE.md)
scripts/            install, seed, pair, reset, build-release
fixtures/           Seed content and headless job helpers
tests/              PHP unit suite (php tests/run-tests.php)
docs/               Architecture, compatibility, threat model, recovery
```

---

## Limitations (short)

- Single-site only (multisite rejected).
- Best-effort consistency while live keeps writing.
- Fast uploads trusts WordPress media hooks; use upload rescan/audit after raw SFTP writes.
- Posts row-delta planning scans complete lightweight post indexes and clones local `posts`/`postmeta` tables; it reduces network transfer, not destination database copy/binlog work. Changes that bypass normal post-modified semantics can require a full sync/audit.
- Platform egress/snapshots are out of band; WordPress hooks cannot replace them.
- A kept sandbox-only plugin or theme keeps its files, and a plugin its activation. Its settings come from live with the database and its activation routine does not run again, so a plugin that sets up roles, schedules or rewrite rules when activated may need to be deactivated and activated once. The active theme is always live's. `mu-plugins/` keeps nothing.
- A plugin the sandbox holds as a symlink keeps its files, but takes live's activation state.
- The record of what a sync wrote lives in the sandbox database. If that database is replaced outside a sync, the next sync behaves like a first one.

Details and recovery procedures: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/RECOVERY.md](docs/RECOVERY.md).
