# Fast Fix Routing

Use `docs/architecture/change-routing.md` as the maintained human-facing routing table. This reference is the minimal execution copy.

## Start in Exactly One Package

| Symptom | Package | Start paths |
|---|---|---|
| Miniapp/H5 page, copy, local layout | `miniapp` | `yoshop2.0-uniapp/pages/`, then referenced `components/` |
| Miniapp request wrapper or local payload use | `miniapp` | `yoshop2.0-uniapp/api/`, `utils/request/` |
| Merchant page/button/table/modal | `merchant-console` | `yoshop2.0-store/src/views/`, then matching `src/api/` |
| Platform-admin page/button/table/modal | `admin-console` | `yoshop2.0-admin/src/views/`, then matching `src/api/` |
| Clearly isolated PHP syntax/null/format issue with known endpoint | `backend` | owning surface under `yoshop2.0/app/api/`, `app/store/`, `app/admin/`, or `app/timer/` |

Deployment and production issues are never Fast Fix; use `$yoshop-deploy` and Trellis planning.

## Initial Search Exclusions

Never use root-wide recursive search for Fast Fix. Exclude:

```text
.git/
.trellis/tasks/archive/
**/node_modules/
**/vendor/
**/dist/
yoshop2.0/runtime/
yoshop2.0/runtim/
yoshop2.0/public/uploads/
yoshop2.0/public/store/
yoshop2.0/public/admin/
deploy/out/
deploy/reports/
```

Static assets and third-party `uni_modules/` are also excluded initially unless the source hit explicitly points there.

## Evidence That Requires Expansion or Upgrade

Upgrade out of Fast Fix when a hit shows any of:

- API request/response field or enum must change;
- backend authority controls the observed UI behavior;
- route/menu/permission data comes from another layer;
- a database field, migration, data repair, timer, callback, or upstream platform is involved;
- payment, refund, service-order state, idempotency, signature, credential, or production environment is involved;
- the same behavior has conflicting implementations across packages;
- three targeted searches do not produce a plausible owning file;
- the coherent fix exceeds three source files or needs design trade-offs.
