# Permission Audit — 2026-07-16

## Confirmed request path

1. `Controller::initialize()` derives `/order.event/<action>` and calls `Auth::checkPrivilege`.
2. Non-super access URL is the union of all role menu IDs, their menu/API mappings, and API URL rows.
3. The reported error is emitted before the order action controller runs; this is authorization metadata failure, not order-state failure.

## Database evidence (local yoshop2)

- User `guanxing` (`store_user_id=10002`, `store_id=10001`, `is_super=0`).
- Roles: `运营人员(10001)` and `客服人员(10002)`.
- `运营人员` owns `订单处理(10201)`; `客服人员` does not.
- No `store_api` row exists for any of:
  - `/order.event/startService`
  - `/order.event/completeService`
  - `/order.event/refundBeforeService`
- Legacy menu rows still exist for `10052 发货`, `10059 确认收货`, `10202 订单导出`, and `10238/10239/10241/10242` delivery management.
- Legacy API rows still exist for export `11266-11268`, delivery `11318-11322/11367`, and return receipt `11088`.
- Existing menu/API data already contains historical duplicates, so the new migration must use semantic `NOT EXISTS` guards rather than assuming a pristine database.

## Root cause

The service endpoints were added at controller/frontend level, but the authorization metadata and production migration were not added. The frontend then used an incompatible `$auth` key and retained legacy fallback actions, allowing buttons to appear while the backend correctly denied the route.
