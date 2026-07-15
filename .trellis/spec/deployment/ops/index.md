# Deployment Operations Entry Point

> **Applies to:** `deploy/` and release-facing scripts/configuration.

## Mandatory Route

- Use `$yoshop-deploy` for build, production preflight, release, status, rollback, domain drift, and test mini-program build decisions.
- Read `deploy/README.md` for the operator contract and `deploy/ops-support.md` for support procedures.
- Local WSL runtime/cache ownership work must also read [Runtime Ownership Contract](../../backend/runtime-ownership-contract.md).

Deployment, production configuration, migrations, backups, and rollback are never Fast Fix.

## Pre-Development Checklist

1. Identify local-only, test-miniapp, staging-like check, or production scope.
2. Preserve the source/data boundary: code release must not overwrite `.env`, uploads, payment material, runtime, or database.
3. Design rollback before a production-affecting change.
4. Use dry-run/status commands before any destructive or remote action.

## Quality Check

1. Run the focused tests under `deploy/tests/` or `deploy/data/tests/` that cover the changed contract.
2. Run `./deploy.sh preflight`/dry-run only according to `$yoshop-deploy`; do not improvise production commands.
3. Validate generated manifest/SHA and release-state behavior when packaging logic changes.
4. Never claim production success from local tests alone.
