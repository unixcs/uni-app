# Component Guidelines

> How Vue components and route pages are implemented in this repository.

---

## Overview

Both frontend apps use Single File Components, but they do **not** share the same UI stack:

- `yoshop2.0-uniapp`: Vue 3 + uni-app + uView components
- `yoshop2.0-store`: Vue 2 + Ant Design Vue

Match the host app's conventions rather than trying to standardize them during a feature task.

## Component Structure

### Miniapp components/pages

Common structure:
1. `<template>` first
2. `<script>` with `export default { ... }`
3. optional `<style lang="scss" scoped>` or feature stylesheet import

Observed patterns:
- State lives in `data()` and derived values in `computed`.
- Lifecycle hooks are page/runtime specific (`onLoad`, `onShow`, `created`).
- Methods remain in the SFC unless reused across multiple screens.

Examples:
- `yoshop2.0-uniapp/pages/checkout/index.vue`
- `yoshop2.0-uniapp/pages/feedback/index.vue`
- `yoshop2.0-uniapp/components/privacy-popup/index.vue`

### Merchant console pages/modules

Common structure:
1. `<template>` using Ant Design Vue components
2. `<script>` with `export default`
3. optional `<style lang="less" scoped>`

Observed patterns:
- Route pages hold table/filter/detail state.
- Child modal/tool components emit completion events back to the parent.
- Ant Design form instances are created with `this.$form.createForm(this)`.

Examples:
- `yoshop2.0-store/src/views/order/Index.vue`
- `yoshop2.0-store/src/views/order/tools/Export.vue`
- `yoshop2.0-store/src/views/store/address/modules/AddForm.vue`
- `yoshop2.0-store/src/views/content/editor/Index.vue`

## Props and Events Conventions

- Declare props explicitly with type/default when the component is reused.
  - Example: `components/privacy-popup/index.vue` declares `hideTabBar`.
- Declare emitted events when the runtime supports it and the component is reused.
  - Example: `components/privacy-popup/index.vue` emits `end`.
- Merchant child modules usually notify the parent with a descriptive event after successful submit.
  - Example: `AddForm.vue` emits `handleSubmit` after API success.

## Data and API usage

- Import API modules from `@/api/...`; do not inline request URLs inside templates or random helpers.
- Normalize request params in component methods before submit.
  - Example: `pages/checkout/index.vue` builds `getRequestParam()` / `getFormData()`.
- Keep loading/disabled flags near the UI that they control.
  - Example: `disabled` in checkout submit, `confirmLoading` in modal forms, `isLoading` in editor pages.

## Styling Patterns

- Keep page- or component-specific styles inside the SFC when the scope is local.
- Use the app's existing styling technology:
  - miniapp pages/components: `scss` or existing uni/uView class conventions
  - merchant console: `less` with scoped selectors and Ant Design class adjustments when necessary
- Prefer existing utility/class naming already present in the app (`dis-flex`, `flex-box`, `b-f`, `m-top20`) instead of inventing a new utility system mid-task.

## Accessibility and interaction expectations

This codebase is product-first rather than accessibility-framework-heavy, so preserve the existing practical rules:
- Use the platform-native interactive control where required by runtime behavior.
  - Example: WeChat privacy consent uses a real `<button open-type="agreePrivacyAuthorization">` in `components/privacy-popup/index.vue`.
- Keep explicit labels/placeholder text for forms and action buttons.
- Do not replace working platform widgets with purely decorative wrappers that break expected events.

## Common Mistakes

- Mixing miniapp-specific components (`u-popup`, `picker`, `uni.*`) into merchant console pages.
- Introducing direct `request` calls in views instead of reusing/adding `api/*.js` wrappers.
- Hiding parent-child communication in global state when a local event is enough.
- Copy-pasting a large page into a component without extracting the one reusable section.
