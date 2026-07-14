---
name: yoshop-deploy
description: Guarded YoShop release workflow for local builds, Tencent Cloud rsync deployment, production status, rollback, domain-drift checks, and release troubleshooting. Use when preparing, reviewing, deploying, or rolling back wx.gxwqb.cn, or when validating the separate wx.oiob.cn test mini-program build.
---

# YoShop Deploy

Treat WSL Git `main` as code truth and Tencent `shared/` as production-data truth.
Use repository scripts as the execution authority; do not reimplement deployment
with ad-hoc `scp`, `rm`, or SSH commands.

## Guardrails

- Never read secrets into chat or place them in Git/release archives.
- Never copy local `.env`, uploads, runtime, database files, or payment keys to production.
- Keep `wx.oiob.cn` for test/experience and `wx.gxwqb.cn` for production.
- Ask separately before Git commit/push and before production deploy/rollback.
- Require clean, pushed `main`; never deploy an uncommitted working tree.
- Do not run package managers on Tencent. Build PHP vendor and all web assets locally.
- Do not publish the mini-program as part of a server deployment.

## Workflow

1. Read `deploy/config.json`, `deploy/README.md`, and
   `references/release-contract.md`.
2. Inspect `git status` and run `./deploy.sh preflight --fetch`.
3. For mini-program validation, run one explicit environment command:
   - test: `npm --prefix yoshop2.0-uniapp run build:mp-weixin:test`
   - production: `npm --prefix yoshop2.0-uniapp run build:mp-weixin:prod`
   Verify compiled `config.js`; do not upload or release it automatically.
4. If source changes need committing, show logical commit groups and ask for Git
   commit/push approval. Stop if approval is absent.
5. Run `./deploy.sh release --fetch --dry-run`; report build/scan result and the
   planned target/action. Use separate `build` and `deploy --dry-run` only when a
   package needs manual inspection.
6. Ask for production authorization. Only after approval run the routine
   one-command release:
   `./deploy.sh release --fetch --confirm-production DEPLOY-wx.gxwqb.cn`.
7. Run `./deploy.sh status` and the smoke checklist in the reference. If health
   fails, preserve evidence; the remote command automatically restores the
   previous code symlink.
8. Roll back only after separate authorization with:
   `./deploy.sh rollback --confirm-production ROLLBACK-wx.gxwqb.cn`.
   Explain that code rollback does not reverse database migrations.

## Troubleshooting

Read `references/release-contract.md`. Prefer `status`, release reports under
`deploy/reports/`, remote release logs, Nginx/PHP logs, and manifest hashes over
guessing. Never disable path, secret, branch, checksum, or health guardrails to
make a release pass.
