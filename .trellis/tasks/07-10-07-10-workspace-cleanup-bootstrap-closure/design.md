# Design: workspace cleanup and bootstrap closure

## Scope boundary

This task owns repository hygiene for the current dirty tree, bootstrap guideline completion, and the final remote push. It does **not** reopen already archived product tasks except to preserve missing supporting artifacts that were clearly part of shipped work.

## Dirty-state classification model

### A. Keep-and-commit
Changes that are functional, documentation-backed, or Trellis-bootstrap scaffolding:
- `yoshop2.0-uniapp/scripts/build-mp-weixin.cjs` if validation confirms it is the static-asset sync fix for compiled mp-weixin output.
- `yoshop2.0/数据库修改记录/dev.txt` if it captures real schema/menu changes that correspond to shipped work.
- Trellis bootstrap files that should live in the repo (`.trellis/`, `.agents/`, `AGENTS.md`, root Trellis package files, relevant project docs/specs).
- The new cleanup task artifacts and any updates made while closing `00-bootstrap-guidelines`.

### B. Revert/remove
Changes that do not encode real behavior:
- Pure line-ending churn in `yoshop2.0-uniapp/utils/request/index.js`.
- Local caches or install artifacts that should never be versioned.

### C. Leave outside scope only if clearly local/runtime
Examples:
- `.trellis/.developer`, `.trellis/.runtime/`, `.opencode/node_modules`, root `node_modules/`.
These should be ignored, not committed.

## Bootstrap completion model

`00-bootstrap-guidelines` is done only when all six generic frontend guideline files describe this repository as it exists now:
- Vue 3 uni-app client + Vue 2 Ant Design store console
- JavaScript-first codebase, no project TypeScript layer
- Vuex modules, mixins, request wrappers, route-driven page layout
- Validation/build expectations based on actual commands already used in this repo

The task remains open if any guideline file still contains placeholders like `To be filled` or fake examples.

## Commit and rollback shape

### Commit grouping
1. Cleanup / retained functional leftovers
2. Bootstrap specs and Trellis scaffolding
3. Archive + journal bookkeeping (auto/manual via Trellis)

### Rollback boundaries
- If a retained leftover change cannot be validated, revert it instead of carrying uncertainty forward.
- If bootstrap docs become too broad or speculative, trim them back to observed patterns only.
- If remote push fails, keep local history intact and report the exact failure.

## Push rule

Push happens only after:
- task-related changes are committed;
- Trellis tasks are archived or intentionally left active with explanation;
- remaining dirty files are confirmed to be outside this task and safe to leave behind.
