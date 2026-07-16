# Deployment Operations Entry Point

> **Applies to:** `deploy/` and release-facing scripts/configuration.

## Mandatory Route

- Use `$yoshop-deploy` for build, production preflight, release, status, rollback, domain drift, and test mini-program build decisions.
- Read [`WSL本地验证与腾讯云生产上线工作流.md`](../../../../WSL本地验证与腾讯云生产上线工作流.md) for the end-to-end local acceptance/Git/production gates, `deploy/README.md` for the production operator contract, and `deploy/ops-support.md` for support procedures.
- Local WSL runtime/cache ownership work must also read [Runtime Ownership Contract](../../backend/runtime-ownership-contract.md).

Deployment, production configuration, migrations, backups, and rollback are never Fast Fix.

## Pre-Development Checklist

1. Identify source-only, WSL local runtime, test-miniapp, staging-like check, GitHub, or Tencent production scope; never call WSL "production".
2. Preserve the source/data boundary: code release must not overwrite `.env`, uploads, payment material, runtime, or database.
3. For behavior changes, plan how the built/runtime/data change reaches WSL and which representative scenario the user will manually accept before GitHub push.
4. Local database writes, cleanup, `.env`, credentials, and sensitive configuration require impact disclosure and separate confirmation; safe local build/static replacement/cache repair/service restart may follow approval to implement.
5. Design rollback before a production-affecting change.
6. Use dry-run/status commands before any destructive or remote action.

## Mandatory Delivery Gates

```text
modify + automated checks
→ WSL local deployment
→ explicit user acceptance (or recorded no-runtime-behavior exemption)
→ separately authorized Git commit/push
→ production preflight/dry-run
→ separately authorized Tencent production release
→ production verification
```

- Automated tests and AI review do not replace required user acceptance.
- Silence, acknowledgement, or a topic change is not acceptance; require an explicit pass statement.
- Git authorization never implies production authorization.
- If WSL cannot reproduce an external production behavior, exhaust local/sandbox/read-only checks, list the residual risk, and require explicit risk acceptance.
- Only the user may enable the emergency path; it still requires minimum checks, rollback preparation, separate Git/production authorization, immediate post-release verification, and later regression testing.

## Quality Check

1. Run the focused tests under `deploy/tests/` or `deploy/data/tests/` that cover the changed contract.
2. Verify the actual WSL runtime/bundle for behavior changes, not only source or `dist`, then record the user's explicit manual acceptance before GitHub push.
3. Run `./deploy.sh preflight`/dry-run only according to `$yoshop-deploy`; do not improvise production commands.
4. Validate generated manifest/SHA and release-state behavior when packaging logic changes.
5. Report source changed, WSL deployed, GitHub pushed, and Tencent production released as four distinct states.
6. Never claim production success from local tests alone; verify the public release, services, migrations, and representative behavior.
