# Frontend Development Guidelines

> Repository-specific conventions for the YoShop miniapp client (`yoshop2.0-uniapp`) and merchant console (`yoshop2.0-store`).

---

## Architecture snapshot

This repo has two frontend applications that share backend contracts but not framework versions:

- **Miniapp / H5 client** — `yoshop2.0-uniapp`
  - Vue 3 app created with `createSSRApp`
  - uni-app runtime, Vuex 4, uView UI
  - Options API style (`data`, `computed`, `methods`, lifecycle hooks)
- **Merchant console** — `yoshop2.0-store`
  - Vue 2 + Ant Design Vue admin console
  - Vue CLI build, Vuex 3, route-driven page modules

Write specs and code to match the existing split rather than forcing one app's patterns onto the other.

## Pre-Development Checklist

Before editing frontend code:

1. Identify which app(s) you are touching: `yoshop2.0-uniapp`, `yoshop2.0-store`, or both.
2. Read the relevant generic guides below.
3. If the change crosses backend/frontend boundaries, also read the matching contract doc (`goods-form-contracts`, `wxapp-singleton-content-contracts`, `service-order-contracts`, `feedback-complaint-contracts`).
4. Search for the field / route / config value you plan to change before editing.
5. Decide validation up front: store lint/build, uniapp build, or focused manual smoke.

## Quality Check

Before marking a frontend task done:

1. Re-run the narrowest meaningful validation command for each touched app.
2. Check for cross-layer drift: API field names, enum values, route paths, menu permissions, and exported columns must all stay aligned.
3. Confirm no placeholder Trellis text or speculative conventions were introduced.
4. If you changed a shared contract, update the corresponding spec doc in the same task.
5. WSL local acceptance may regenerate ignored `yoshop2.0/public/store`, but it remains deployment output: never stage it; production packaging copies the reviewed `yoshop2.0-store/dist` through the guarded release workflow.

## Guidelines Index

| Guide | Description | Status |
|-------|-------------|--------|
| [Directory Structure](./directory-structure.md) | How the miniapp and merchant console are laid out | Active |
| [Component Guidelines](./component-guidelines.md) | Component/page composition patterns in both Vue apps | Active |
| [Hook Guidelines](./hook-guidelines.md) | How this repo handles reusable logic without a hooks-heavy architecture | Active |
| [State Management](./state-management.md) | Vuex usage, local state boundaries, and server-state handling | Active |
| [Quality Guidelines](./quality-guidelines.md) | Build/lint/manual verification rules and forbidden patterns | Active |
| [Type Safety](./type-safety.md) | JavaScript-first shape/validation conventions | Active |
| [Goods Form Contracts](./goods-form-contracts.md) | Merchant goods create/edit cross-layer contracts | Active |
| [Wxapp Singleton Content Contracts](./wxapp-singleton-content-contracts.md) | Wxapp singleton-content config, popup consume, and privacy-agreement contracts | Active |
| [Service Order Contracts](./service-order-contracts.md) | Shared checkout service-order contract, admin search semantics, and historical soft-delete-hide boundaries | Active |
| [Feedback Complaint Contracts](./feedback-complaint-contracts.md) | Miniapp feedback upload path normalization and merchant content-editor placement contracts | Active |

## When to read which docs

- **Touching page layout, file placement, or route modules** → `directory-structure.md`
- **Editing Vue SFCs / modal forms / reusable UI** → `component-guidelines.md`
- **Extracting shared logic / lifecycle behavior** → `hook-guidelines.md`
- **Changing Vuex, route permission state, or login/session state** → `state-management.md`
- **Adding validation/build/review steps** → `quality-guidelines.md`
- **Changing payload shapes or normalization logic** → `type-safety.md` plus the relevant contract doc

**Language rule**: keep frontend spec docs in English even when product copy and code comments remain Chinese.
