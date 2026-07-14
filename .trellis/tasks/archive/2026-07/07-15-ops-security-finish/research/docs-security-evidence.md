# Tencent documentation and SSH security evidence

Date: 2026-07-15

Scope owner: `/mnt/vps/tencent/**`, project-root `SSH_KEY_ROTATION.md`, and this evidence file.

No deployment script/configuration, business code, server authentication, public page, Git commit, or Git remote was changed during this work.

## Evidence sources

- Active task `prd.md`, `design.md`, `implement.md`, and curated `implement.jsonl` context.
- Parent audit findings at
  `.trellis/tasks/07-15-tencent-production-rebuild/research/2026-07-15-audit-findings.md`.
- Foundation evidence at
  `.trellis/tasks/07-15-tencent-infra-rebuild/research/2026-07-15-rebuild-evidence.md`.
- Current project deployment documentation and read-only inspection of the old Tencent documentation tree.
- Direct HTTPS GET of the filing site at the known Tencent IP with the production hostname and normal TLS verification.

## Pre-refactor audit

The previous tree mixed old deployment memory with seven one-paragraph directories. Findings:

- `AGENT.md` asserted an old application root, treated production as already live, included stale application-edit history, and contained plaintext admin/store credentials plus a login request with credentials in command data.
- `Nginx/` duplicated the lowercase Nginx documentation directory and contained an out-of-date filing page copy.
- `apps/`, `backup/`, `certbot/`, `docs/`, `nginx/`, and `routes/` repeated small fragments that had already drifted from the current `/srv/yoshop` release model.
- Backup text claimed tasks/directories existed without recovery proof.
- COS/CAM, external notification, maintenance state, no-active-release state, and separate production authorization gates were not represented accurately.

The credential values are intentionally not reproduced in this evidence.

## Direct filing-page synchronization

The local legacy page was 3574 bytes with SHA-256:

```text
40de3142706d63d991c1d11ee1c382168e9c75ff2fa1beb50b9a28588f75ee88
```

A read-only direct request was made with:

```bash
curl --noproxy '*' \
  --resolve www.gxwqb.cn:443:124.222.144.181 \
  --fail --silent --show-error --max-time 20 \
  https://www.gxwqb.cn/
```

Observed response:

| Field | Value |
| --- | --- |
| Remote IP | `124.222.144.181` |
| HTTP | `200` over HTTP/2 |
| Server | `nginx/1.18.0 (Ubuntu)` |
| Content type | `text/html` |
| Size | 2493 bytes |
| Last-Modified | `Tue, 14 Jul 2026 18:40:58 GMT` |
| ETag | `"6a5682ba-9bd"` |
| SHA-256 | `d8e4623842e07152c211f406594b1fb199d31365257c6296d98ec60cee4dea5a` |

The response passed normal hostname/TLS validation. It was copied only to the local
`static-site/index.html`; `cmp` proved the saved copy byte-identical. No PUT, SSH, reload, or other Tencent mutation was performed.

## Rebuilt tree

```text
/mnt/vps/tencent/
├── AGENT.md
├── README.md
├── runbooks/
│   ├── backup-and-recovery.md
│   ├── platform-health.md
│   └── release-and-maintenance.md
└── static-site/
    └── index.html
```

Deleted obsolete directories:

- `Nginx/`
- `apps/`
- `backup/`
- `certbot/`
- `docs/`
- `nginx/`
- `routes/`

`Nginx/index.html` was not blindly renamed: its bytes were obsolete. It was replaced by the direct, currently served response at `static-site/index.html`.

Preserved facts and boundaries:

- canonical application layout is `/srv/yoshop/{incoming,releases,current,state,shared}`;
- B is Tencent production and A is WSL test only;
- B remains HTTPS 503 maintenance with no active release;
- the filing static page remains independent of application releases;
- Docker/containerd, Certbot, Komari, Tencent agents, SSH, Nginx, PHP 8.3, MySQL 8, and Redis are retained;
- MySQL/Redis remain loopback-only; UFW/Fail2ban and public 22/80/443 boundaries are documented;
- COS/CAM and external notifications remain deferred;
- backup existence is not represented as recovery proof.

## SSH guide coverage

`SSH_KEY_ROTATION.md` is manual only and includes:

- separate ED25519 ops-admin and deployer keys;
- add-before-remove sequencing and fingerprint checks;
- one old control session plus fresh admin/deployer validation sessions;
- exact local and remote owner/mode checks;
- per-host `IdentitiesOnly yes` aliases;
- positive release-status sudo test and negative arbitrary-command sudo test;
- old-key removal by public-key blob rather than comment;
- optional root-login disable only after a separately tested non-root admin path;
- rollback from per-account authorization-file backups or Tencent console rescue;
- post-window agent, backup, old-key, and asset-record cleanup.

No key was generated, installed, removed, or rotated.

## Validation evidence

Checks executed after rebuilding:

1. Exact scans found no legacy application-root literal and no legacy uppercase static-root literal in final owned documentation.
2. The final Tencent root contains only `runbooks/` and `static-site/` directories.
3. Credential-shape scanning found no inline curl basic-auth, password-bearing request data, combined username/password text, private-key block, or Tencent-style access-key ID.
4. Additional known-leak-prefix and password-assignment scans found no old credential material.
5. All seven final Markdown files were valid UTF-8, started with an H1, balanced fences, final newlines, no tabs, and no trailing whitespace.
6. All local Markdown links under the Tencent tree resolved.
7. The static file parsed as UTF-8 HTML and matched both direct-fetch bytes and the recorded 2493-byte SHA-256.
8. No Markdown linter executable was installed, so the deterministic structure/whitespace checker was used instead.

## Remaining manual gates

- A user must schedule and execute `SSH_KEY_ROTATION.md`; the old shared access remains until that manual change succeeds.
- Backup retention, temporary-DB restore, binlog continuity, migration idempotency, release cleanup, cert renewal, and watchdog tests need their own live evidence before the parent operations task can be declared complete.
- B remains in maintenance with no active release; initial activation still needs a separately authorized cutover.
- COS/CAM off-host backup and external notifications remain deferred, so complete host-loss recovery objectives are not yet met.
- Commit/push and any Tencent production synchronization remain separately authorized actions and were not performed.
