# Security policy

## Supported versions

| Version | Supported |
| --- | --- |
| 0.1.x | Yes |
| Unreleased `main` | Yes |
| Older development snapshots | No |

Security fixes are applied to the latest tagged release and the main development branch. This project is pre-1.0; compatibility may change in a minor release and is documented in the changelog.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Report it privately to the Newspack/Automattic security contact responsible for the deployment, including:

- affected version or commit;
- source and sandbox topology;
- reproduction steps and impact;
- whether credentials or production data may have been exposed.

Rotate the source bearer credential immediately if it may have leaked. Disable or remove the mu-plugin on the source if an export endpoint may be actively abused.

Do not include bearer tokens, pairing keys, database credentials, job logs containing private paths, or production data in an issue or public attachment. State whether source emergency stop and credential revocation have already been performed.

## Security boundaries

A paired sandbox is intentionally authorized to read the selected source database and `wp-content` data. Because plugins and themes are executable, the paired source and the transferred code must be trusted. Sandbox Sync provides containment, verified transport, expiring bearer-bound transfer sessions with exact job scope, staged activation, rollback, and WordPress-level outbound guards; it does not replace infrastructure egress controls, private ingress, database backups, or host-level incident response.

Before using production data, verify the sandbox's access control, outbound network policy, data-retention policy, platform backups, database/filesystem capacity, cron runner, and source-to-sandbox HTTPS path. The unresolved production gates are tracked in [BACKLOG.md](BACKLOG.md).

See [docs/THREAT-MODEL.md](docs/THREAT-MODEL.md) and [docs/RECOVERY.md](docs/RECOVERY.md).
