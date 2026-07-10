# Workspace cleanup and bootstrap closure

## Goal

Clean the repository working tree without losing valid work, finish the pending Trellis bootstrap-guidelines task with real project-backed spec content, and push the resulting committed history to GitHub.

## Requirements

- Preserve valid product/tooling changes that are still uncommitted, but do not silently mix obvious noise into commits.
- Review every currently dirty path and classify it into one of three buckets:
  - keep and commit in this round;
  - revert because it is accidental/noise;
  - leave uncommitted only if it is clearly outside this task and should not be touched.
- Complete `.trellis/spec/frontend/` bootstrap guideline files so they describe the actual project instead of template placeholders.
- Re-review `00-bootstrap-guidelines` and archive it only if its acceptance criteria are truly met.
- Keep commit batches logically separated: cleanup/tooling fixes, bootstrap/spec closure, and Trellis bookkeeping.
- Push only after local commits are complete and task-related dirty files are resolved.

## Acceptance Criteria

- [ ] Remaining dirty files are classified and cleaned up without discarding valid work.
- [ ] The line-ending-only diff in `yoshop2.0-uniapp/utils/request/index.js` is resolved appropriately.
- [ ] Any retained functional/tooling changes (for example the mp-weixin static asset sync fix and DB change log updates) are validated and committed in a deliberate batch.
- [ ] `.trellis/spec/frontend/{directory-structure,component-guidelines,hook-guidelines,state-management,type-safety,quality-guidelines}.md` contain real project guidance with concrete file examples and no placeholder sections.
- [ ] `00-bootstrap-guidelines` is either archived with evidence of completion or explicitly left open with a written reason.
- [ ] This cleanup task itself is committed, wrapped up, and the approved local history is pushed to `origin`.

## Constraints

- Work inline; do not dispatch implement/check sub-agents.
- Follow Trellis workflow: plan first, then `task.py start`, then execute/verify/commit/archive/journal.
- Do not push partial or ambiguous work.

## Notes

- This task intentionally spans repository hygiene plus Trellis bootstrap closure because the two scopes overlap in the current dirty state.
- Existing archived business tasks from 2026-07-09 / 2026-07-10 must remain unchanged except for clean Trellis bookkeeping.
