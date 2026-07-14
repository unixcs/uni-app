# Implementation Plan — 腾讯云生产重建与可持续部署

## Phase 0 — Recovery and evidence gate

- [ ] Export local Git/repo/data inventory and checksums outside the working tree.
- [ ] Create `git bundle --all`, tracked diff, staged diff, untracked business archive, local DB dump, uploads/env/payment secure archive.
- [ ] Verify archives by listing/restoring into temporary locations.
- [ ] Export read-only server audit/config/package/service/login/log-root-cause summary to local secure storage.
- [ ] Check Tencent snapshot availability; record fallback if unavailable.
- **Rollback**: no destructive action has occurred.

## Phase 1 — Repository and history child

- [ ] Build precise ignore/clean allowlist.
- [ ] Preserve and logically classify all current business changes.
- [ ] Remove only verified generated/cache/log artifacts while retaining dependencies.
- [ ] Validate local PHP/Nginx/tests/builds and Windows mirror.
- [ ] Rewrite history in an isolated clone, verify commit graph and sensitive-path absence, then update original GitHub with `--force-with-lease` only after explicit commit/push approval.
- **Rollback**: restore original repo from bundle and worktree archive.

## Phase 2 — Deployment tooling and Skill child

- [ ] Implement environment-aware builds and safe Windows mirror sync.
- [ ] Implement artifact builder/manifest/scanner.
- [ ] Implement local Python deploy controller and remote Bash release script.
- [ ] Implement safe migration registry, health checks, rollback and dry-run modes.
- [ ] Create and validate `$yoshop-deploy` Skill.
- [ ] Test against local/fake remote fixtures before touching production.
- **Rollback**: tooling changes are local Git changes only.

## Phase 3 — Tencent infrastructure child

- [ ] Enter maintenance mode while preserving ACME/static site.
- [ ] Stop/disable runaway old Timer and confirm log growth stops.
- [ ] Preserve audit samples, then remove oversized logs with service-aware rotation/vacuum.
- [ ] Clean old application/DB/backup/development assets per approved boundary.
- [ ] Keep Docker/containerd; canonicalize host-Nginx static site.
- [ ] Remove approved unused packages after simulated dependency review.
- [ ] Create directory layout, `deployer`, scoped sudo, swap, firewall/fail2ban, log limits and systemd guards.
- [ ] Validate existing Nginx/PHP/MySQL/Redis/Certbot/Komari/Tencent agents.
- **Rollback**: restore server config archive/snapshot; maintenance page remains.

## Phase 4 — Production data and cutover child

- [ ] Generate sanitized schema/data/upload manifests and validate in temporary DB.
- [ ] Create production DB/user and protected env/payment/shared storage.
- [ ] Upload complete initial release and run internal candidate checks.
- [ ] Import sanitized baseline and validate counts/references.
- [ ] Atomically activate release, start services, validate B endpoints and environment separation.
- [ ] Keep B maintenance page until all mandatory checks pass.
- [ ] Run experience-version-on-B acceptance before formal mini-program release instructions.
- **Rollback**: revert code symlink; restore initial DB backup or rebuild from sanitized baseline while maintenance remains.

## Phase 5 — Operations/security finish child

- [ ] Validate code rollback, backup restore, migration idempotency, log rotation and Timer watchdog.
- [ ] Configure local backup/binlog retention and explicitly mark COS deferred.
- [ ] Refactor `/mnt/vps/tencent` docs and remove plaintext credentials.
- [ ] Write manual SSH key-rotation guide.
- [ ] Run full Trellis check, update specs, request commit and production-publish approvals separately, archive children and parent.

## Mandatory validation commands

Commands will be finalized by child tasks, but gates include:

```bash
git status --short --branch
git fsck --full
php -l <changed-php-files>
php think list
npm test / focused test commands
npm run build (admin/store)
npm run build:h5:sync
npm run build:mp-weixin:test
nginx -t
systemctl is-active nginx php8.3-fpm mysql redis-server yoshop2.0-timer.service
curl -fsS https://wx.gxwqb.cn/healthz
```
