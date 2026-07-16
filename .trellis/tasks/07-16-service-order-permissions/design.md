# Design — 服务订单操作权限

## First-principles model

A protected operation needs exactly four facts:

1. a role owns a capability;
2. the capability maps to the exact API route;
3. the frontend uses the same capability to decide visibility;
4. the backend remains the final authority.

Everything else is legacy compatibility. The smallest complete repair is therefore three action capabilities, three API rows, three bindings, one role-backfill rule, and deletion/defensive filtering of obsolete capabilities.

## Data model and contracts

### Capability menus

Reuse `订单处理(menu_id=10201, path=/order/tools)` as the capability namespace. Add three type=20 children with semantic uniqueness `(parent_id, action_mark)`:

| Name | action_mark | Frontend permission key | Backend API URL |
|---|---|---|---|
| 开始服务 | `startService` | `/order/tools.startService` | `/order.event/startService` |
| 完成服务 | `completeService` | `/order/tools.completeService` | `/order.event/completeService` |
| 服务前退款 | `refundBeforeService` | `/order/tools.refundBeforeService` | `/order.event/refundBeforeService` |

The migration resolves the `/order/tools` parent and order API parent semantically, fails closed when either is missing or ambiguous, and lets each table allocate new IDs through `AUTO_INCREMENT`. API rows are located by exact URL; menu rows are located by resolved parent/action. This avoids hard-coded parent drift and primary-key races with customer-defined rows.

### Role migration

Before retiring old permissions, use the set of `(store_id, role_id)` pairs that own the semantically resolved `/order/tools` menu (10201 in the standard dataset) or one of its migration-before direct children. The role editor persists selected leaf actions such as legacy `订单导出(10202)` without necessarily persisting the parent row, so direct-child ownership is the database representation of owning that capability group. Insert all three new action menu IDs for exactly that set with `NOT EXISTS` guards. This makes `guanxing` work through the existing `运营人员` role without granting sensitive order actions to every role that can merely view order details.

### Legacy cleanup

Keep obsolete menu/API rows dormant during the one-release rollback window. Runtime filters remove them from current role trees and backend access sets, while the previous release can still use its `deliver/cancel` fallbacks after a health-check rollback. A later migration may physically delete them after rollback compatibility is no longer required.

### Frontend

Replace API-URL-shaped `$auth` calls and old `deliver/cancel` fallbacks with the three `/order/tools.<action>` keys. Business-state flags (`can_start_service`, etc.) remain independently required, so authorization cannot make an invalid state actionable.

## Migration and deployment

- Add one numbered idempotent SQL file under `deploy/migrations/`.
- Do not depend on `yoshop2.0/数据库修改记录/` because release packaging does not execute it.
- Validate in a transaction against the local database, assert the permission matrix, then roll back.
- Production release creates the standard pre-migration backup and records the migration checksum through `server-release.sh`.
- Users refresh or re-login after release to reload role permissions.

## Compatibility

- Existing super administrators retain their bypass.
- Existing non-super roles without 10201 retain view-only behavior.
- Legacy controller code remains, reducing rollback risk and preserving historical records.
- If migration rollback is needed, restore the pre-migration DB backup and previous release; do not edit an already-recorded migration checksum.

## Rejected alternatives

- **Whitelist the three APIs:** violates least privilege and gives every logged-in administrator service control.
- **Map APIs directly to the broad order-detail menu:** cannot independently revoke actions and over-grants viewers.
- **Keep legacy `deliver/cancel` fallbacks:** preserves semantic drift and recreates button/backend disagreement.
- **Only run a manual SQL script:** not reproducible, not tracked by deployment, and caused the current drift.
- **Extend runtime bootstrap DDL:** hides release failures in request handling and duplicates the established migration system.
