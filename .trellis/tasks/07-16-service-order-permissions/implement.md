# Implementation Plan — 服务订单操作权限

1. Add an idempotent production migration under `deploy/migrations/`.
   - Insert/find three service action menus under 10201.
   - Insert/find three exact service API URLs.
   - Insert one-to-one menu/API mappings.
   - Backfill all three actions only to roles that already own 10201.
   - Keep legacy rows for one-release rollback compatibility; rely on current runtime filters to retire them.
2. Expand backend defensive legacy menu/API filters to cover the exact retired IDs/routes, without changing the authorization bypass or order state machine.
3. Update `yoshop2.0-store/src/views/order/Detail.vue` to use `/order/tools.startService`, `.completeService`, and `.refundBeforeService`; delete legacy fallbacks.
4. Add a focused permission migration/contract regression that proves:
   - semantic uniqueness and idempotency;
   - 10201-based role backfill;
   - no grant to view-only roles;
   - legacy permissions absent from current role trees and access sets;
   - all three endpoint URLs reachable only through their new action menus.
5. Validate before activation/commit:
   - run the migration twice inside a transaction against local MySQL, assert rows and role matrix, then roll back;
   - `php -l` every changed PHP file;
   - `NODE_OPTIONS=--openssl-legacy-provider npx vue-cli-service lint src/views/order/Detail.vue` from `yoshop2.0-store`;
   - run the focused contract test;
   - inspect git diff for unrelated or generated files.
6. Rollback point: before applying production migration, rely on the release tool's migration backup; rollback restores the database backup and previous immutable release.
