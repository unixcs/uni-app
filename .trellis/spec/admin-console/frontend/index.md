# Admin Console Frontend Entry Point

> **Applies to:** `yoshop2.0-admin/` — platform administrator Vue 2 + Ant Design Vue console.

## Route Before Reading

- Page/button/table/modal → `src/views/`.
- Request/payload → `src/api/`.
- Route/permission → `src/router/`, `src/permission.js`, then `yoshop2.0/app/admin/` only when evidence requires backend work.
- Shared visual component → `src/components/`.

Do not assume merchant-console pages and permissions are interchangeable merely because both applications use Vue 2.

## Pre-Development Checklist

- Use [Component Guidelines](../../frontend/component-guidelines.md) for established Vue 2 composition patterns.
- Use [State Management](../../frontend/state-management.md) for session/store changes.
- Use [Quality Guidelines](../../frontend/quality-guidelines.md) for build and review rules.
- Verify paths against `yoshop2.0-admin/`; merchant-console contract docs apply only when a real shared backend contract is involved.

## Quality Check

1. Run the narrowest script from `yoshop2.0-admin/package.json`, normally `lint:nofix` or `build` as risk requires.
2. Check API names, route exposure, menu authority, and backend admin endpoints when touched.
3. Do not edit `dist/` or `yoshop2.0/public/admin` as source.
