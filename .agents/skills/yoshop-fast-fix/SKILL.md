---
name: yoshop-fast-fix
description: "YoShop project-local low-risk fast-fix workflow. Use when the user explicitly says 快速修复、小改动、不建任务、fast fix, or asks to constrain a clearly local reversible change to one of backend/miniapp/merchant-console/admin-console without a Trellis task. Route to one package, perform evidence-bounded search and minimal validation, and escalate instead of continuing when payment, refund, order state, auth/permission/security, database/data repair, production/deployment, cross-package contracts, or unclear root cause appears."
---

# YoShop Fast Fix

Optimize for the smallest **safe evidence set**, not the fewest commands.

## 1. Protect Existing Work

Run `git status --short`. Record pre-existing changed/untracked paths and never overwrite, revert, stage, or clean them unless the user explicitly includes them.

## 2. Enforce the Risk Gate

Continue only when all are true:

- the user explicitly opted into Fast Fix;
- one owning package can be named;
- the expected change is local, reversible, and contract-neutral;
- no payment/refund/order-state, auth/permission/security, database/data repair, production/deployment, or secret/config ownership is involved;
- the initial expectation is at most 1–3 source files.

If any condition fails or later evidence contradicts it, stop Fast Fix. Explain the evidence and ask to create/continue the appropriate Trellis task. Do not quietly widen into a high-risk change.

## 3. Route Before Searching

Read [references/routing.md](references/routing.md), select exactly one starting package, then read only that package's Spec index from `python3 ./.trellis/scripts/get_context.py --mode packages`.

Do not read all package indexes. Do not scan from the repository root.

## 4. Search With a Budget

Start with up to three targeted searches inside the selected package, using the strongest identifiers available in this order:

1. exact visible copy or error code;
2. route/page/API path;
3. component, method, field, enum, or CSS class.

Use `rg -n` scoped to the package. Exclude generated, dependency, runtime, upload, and archive paths listed in [references/routing.md](references/routing.md).

Read the hit, its direct import/caller, and the nearest relevant test/config only. Expand within the package only when a concrete reference requires it. Expand to another package only by upgrading out of Fast Fix.

## 5. Make the Minimum Coherent Change

- Preserve existing framework and local patterns.
- Do not refactor adjacent code merely because it looks improvable.
- Do not edit generated output as source.
- Search the exact value/constant before changing it to catch local duplicates.
- Keep unrelated dirty files untouched.

## 6. Validate Narrowly

Read [references/validation.md](references/validation.md). Run the narrowest check that can fail for this change, then perform a focused smoke check when possible.

A successful broad build does not replace checking the changed behavior. A browser H5 check does not prove WeChat-native behavior.

## 7. Close or Escalate

Report:

- selected package and why;
- files changed;
- evidence used to keep scope local;
- validation run and any manual check still needed.

Do not create or update project documentation for a one-off cosmetic fix. If the fix reveals a reusable contract, recurring gotcha, or incorrect routing map, propose/update the relevant Spec or architecture document; that knowledge work is no longer part of the minimal Fast Fix unless trivial.
