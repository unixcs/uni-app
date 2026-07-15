# Runtime Ownership Contract

## Scenario: WSL local PHP runtime and cache maintenance

### 1. Scope / Trigger

Use this contract when changing WSL cleanup scripts, local Nginx/PHP-FPM/Timer setup, or any code that writes `yoshop2.0/runtime`. It prevents mixed root/`www-data` ownership from breaking ThinkPHP file-cache writes. Production releases use a separate shared-runtime symlink and must not be changed by local helpers.

### 2. Signatures

```text
scripts/repair-local-runtime.sh [--clear-cache]
scripts/cleanup-local.sh [--apply] [--deep]
scripts/systemd/yoshop2.0-timer-local-override.conf
```

- No flag: preserve runtime content and repair ownership, inherited permissions, cache buckets, and writeability.
- `--clear-cache`: delete only generated `cache`, `temp`, and `schema`, then rebuild them.
- `cleanup-local.sh --apply`: after deleting generated runtime state, must call the repair helper before returning success.
- Local systemd override: `User=www-data`, `Group=www-data`, `UMask=0007`.

### 3. Contracts

- Local runtime root: `/opt/yoshop/yoshop2.0/runtime`.
- Runtime owner: `www-data:www-data`.
- Cache directory mode: `2770`; all 256 first-level hexadecimal buckets must exist.
- PHP-FPM and local Timer must both write runtime state as `www-data`.
- With `acl` installed, root-created descendants must remain writable by `www-data`.
- Workerman private state is excluded from shared ACL inheritance and retains its secure Timer-managed permissions (`workerman/` 0700 and `timer.log` 0600).
- The repair helper must preserve `.env`, uploads, payment material, database data, and logs unless a future explicit signature says otherwise.
- The repair helper must reject a runtime symlink. `/srv/yoshop/current/yoshop2.0/runtime -> /srv/yoshop/shared/runtime` is production state and is managed only by deployment tooling.

### 4. Validation & Error Matrix

| Condition | Required behavior |
|---|---|
| Repository lacks `.git` or `yoshop2.0/think` | Refuse before mutation |
| Runtime root is a symlink | Refuse as production-style layout |
| `www-data` does not exist | Refuse before mutation |
| Caller is non-root and `sudo` exists | Re-exec through `sudo` |
| Caller is non-root and `sudo` is absent | Exit with root-required error |
| `setfacl` is absent | Repair current owner/mode and emit inheritance warning |
| `www-data` cannot create and overwrite a probe | Exit non-zero; never report success |
| Cleanup helper is missing/not executable | `cleanup-local.sh --apply` refuses before deleting anything |

### 5. Good / Base / Bad Cases

- **Good:** Run `repair-local-runtime.sh --clear-cache`; local admin and mini-program API requests return business responses, and runtime logs contain no file-write exception.
- **Base:** Run without flags after a permissions drift; cache content is preserved while ownership and writeability are repaired.
- **Bad:** Delete all runtime contents directly and restart services without rebuilding ownership. This can leave `root:root 0755` parents that PHP-FPM cannot extend.
- **Bad:** Let Timer run as root while PHP-FPM runs as `www-data`; direct `file_put_contents` can fail when either identity creates the file first.

### 6. Tests Required

Run:

```bash
shellcheck scripts/repair-local-runtime.sh scripts/cleanup-local.sh scripts/tests/test-local-runtime-contract.sh
scripts/tests/test-local-runtime-contract.sh
nginx -t
```

Assertions:

1. Production-style runtime symlink is rejected.
2. Applying cleanup in an isolated test working tree recreates runtime as `www-data:www-data` and allows a `www-data` write.
3. No tracked Markdown/shell file documents the raw runtime deletion anti-pattern.
4. On the WSL host, `systemctl show yoshop2.0-timer.service` reports `User=www-data`, `Group=www-data`, `NRestarts=0`.
5. Local and A-domain smoke responses contain none of `file_put_contents`, `Failed to open stream`, or `No such file or directory`.

### 7. Wrong vs Correct

#### Wrong

```bash
rm -rf "$RUNTIME"/*   # 删除后没有重建 owner/mode
systemctl restart php8.3-fpm nginx
```

#### Correct

```bash
/opt/yoshop/scripts/repair-local-runtime.sh --clear-cache
systemctl restart php8.3-fpm
```

Do not run the local helper on Tencent Cloud. Production runtime ownership and symlinks are governed by `deploy/scripts/server-release.sh` and the production release contract.
