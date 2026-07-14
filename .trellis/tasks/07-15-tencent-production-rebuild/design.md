# Design — 腾讯云生产重建与可持续部署

## 1. System boundary

- **Source of truth**: WSL Git working tree and cleaned GitHub `main` for code; Tencent MySQL/shared storage for post-cutover production data; `/mnt/vps/tencent` for private provider-specific runbooks.
- **Build boundary**: all Node/Composer application work occurs locally in an isolated staging directory; Tencent receives a complete runtime artifact.
- **Deployment boundary**: Python orchestrator performs deterministic local checks and calls a fixed remote Bash script over SSH/rsync. The Skill only orchestrates intent and approval.
- **Data boundary**: releases are immutable; env/uploads/payment/database/logs are external to release directories.

## 2. Target filesystem

```text
/opt/yoshop/
├── releases/<timestamp>-<sha>/
├── current -> releases/<timestamp>-<sha>
├── shared/
│   ├── env/production.env
│   ├── uploads/
│   ├── payment/
│   └── deployment/
└── backups/
/var/log/yoshop/
/mnt/vps/tencent/static-site/index.html
```

Nginx uses `/opt/yoshop/current/yoshop2.0/public`. Each release links `.env`, `public/uploads`, and `data/payment` to protected shared paths. Runtime cache/schema is release-local; session/cache use Redis; logs use `/var/log/yoshop`.

## 3. Release state machine

```text
preflight -> local build/test -> artifact scan -> upload candidate
-> remote DB backup -> safe migrations -> link shared data
-> remote candidate checks -> atomic current switch -> service reload/restart
-> public health checks -> success
                         -> failure: current switch back + service restart
```

The previous code version remains intact. Database migrations are expand/forward-compatible so code rollback does not require automatic destructive DB rollback.

## 4. Environment contract

- `test`: A domain, local DB/uploads/payment test configuration, visible environment marker.
- `production`: B domain, Tencent DB/shared storage/production secrets, production marker.
- Build commands inject environment without persisting edits to tracked source. Final artifacts are scanned for A/B/localhost drift.

## 5. Initial data flow

```text
local DB -> schema export + whitelist table export -> sanitize users/transactions/secrets
-> restore into temporary DB -> referential/count validation -> production import
local uploads -> referenced-file manifest -> checksum copy -> shared/uploads
```

Admin/store credentials and DB password are newly generated or explicitly reset. Payment secrets are migrated separately to protected shared storage.

## 6. Server rebuild boundary

Preserve OS, Docker/containerd, Certbot, Komari, Tencent agents, SSH, Nginx/PHP/MySQL/Redis packages and static filing page. Remove old application trees, DB/backups, development tooling, unused desktop packages after dependency simulation, and oversized logs only after audit evidence export and stopping the runaway Timer.

## 7. Security model

- Current root key remains for this migration; `deployer` temporarily uses the same public key with scoped sudo.
- Production release refuses root as its normal target once `deployer` works.
- UFW/security checks expose only 22/80/443; MySQL/Redis remain loopback-only.
- Secrets are never logged or versioned. SSH rotation is a manual post-project runbook.

## 8. Observability and bounded failure

- Public `/healthz` exposes only liveness.
- Private health command checks DB, Redis, writable shared paths, migration state, timer, disk, certs and environment identity.
- Timer/log watchdog stops Timer on crash storms or abnormal growth while keeping Web/DB available.
- journald/logrotate size limits prevent unbounded growth.

## 9. Simplicity decisions (Occam)

- No Dockerization of the PHP stack; existing host services are retained.
- Docker is retained, but the filing page is served directly by host Nginx because a static file does not justify another runtime dependency.
- No server-side Git/npm/Composer downloads.
- No automatic destructive DB rollback.
- No automatic mini-program upload/release.
- COS and external message channels are deferred rather than partially configured.
