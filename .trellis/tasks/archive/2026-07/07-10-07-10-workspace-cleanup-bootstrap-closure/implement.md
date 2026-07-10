# Implementation Plan

## Phase 1 — Planning
- [x] Create cleanup/bootstrap-closure task.
- [x] Inspect dirty tree, existing bootstrap task, and remote state.
- [x] Record classification/closure plan in PRD + design + implement artifacts.

## Phase 2 — Execute

### 2.1 Dirty-state cleanup
- [x] Validate and either keep-or-revert the mp-weixin build helper change.
- [x] Revert line-ending-only churn in `yoshop2.0-uniapp/utils/request/index.js`.
- [x] Decide how to handle `yoshop2.0/数据库修改记录/dev.txt` based on shipped schema/menu work.
- [x] Add/adjust ignore rules for local-only artifacts if needed.

### 2.2 Bootstrap guideline completion
- [x] Analyze real frontend structure/patterns from `yoshop2.0-uniapp` and `yoshop2.0-store`.
- [x] Replace placeholder guideline docs with project-backed content and examples.
- [x] Update `00-bootstrap-guidelines` artifacts so closure evidence exists in-task.

### 2.3 Verification
- [x] Re-run focused validation for any retained functional cleanup changes.
- [x] Confirm no placeholder text remains in bootstrap guideline files.
- [x] Confirm `git status` is explainable before Phase 3.4 commit planning.

## Phase 3 — Finish
- [x] Prepare commit plan for all AI-edited files from this task.
- [x] Commit approved work in logical batches.
- [x] Archive `00-bootstrap-guidelines` if complete.
- [x] Archive this cleanup task.
- [ ] Record journal and push committed history to `origin`.

## Validation commands
- `git diff -- <file>`
- `npm run build:mp-weixin` (only if needed to validate the helper change)
- `grep -R "To be filled\|TBD" .trellis/spec/frontend`
- `git status --short`
- `git log --oneline -N`
- `git push origin main`

## Rollback plan
- Revert uncertain leftover diffs instead of carrying them forward.
- If a Trellis scaffold path proves tool-local rather than repo-level, ignore it instead of committing it.
- If push fails, keep local commits and report the failure plus the exact remote error.


## Execution Notes
- Validated `yoshop2.0-uniapp/scripts/build-mp-weixin.cjs` by running `npm run build:mp-weixin`; the script now syncs `static/` into compiled `mp-weixin` output and the compiled `static/tabbar/*.png` files exist afterward.
- Reverted `yoshop2.0-uniapp/utils/request/index.js` because the diff was line-ending churn only.
- Kept `yoshop2.0/数据库修改记录/dev.txt` as a real project change-log artifact for shipped schema/menu work.
- Added root ignore rules for local-only `node_modules/` and `.opencode/`.
- Replaced Trellis bootstrap placeholder docs with repo-backed frontend guidance and updated `00-bootstrap-guidelines/prd.md` to record completion evidence.

- `git status --short` became fully explainable after grouping the remaining edits into Trellis/bootstrap/spec commits and project context/docs commits.
- Work commits created in this closure round: `3004481` (mp-weixin static asset sync + cleanup), `9611eaf` (Trellis scaffold + frontend spec), `8c0e8c3` (project context/docs).
- Archived bootstrap task manually with Chinese commit `50d82df` after validating the filled guideline set.
- Recorded workspace journal as Session 6 via `34ff2a8` using the work commits above.
- Final remaining external step after task archive is pushing the complete local history to `origin/main`.
