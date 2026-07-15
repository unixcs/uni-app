# Change Routing Guide

> Start narrow. The table identifies the first credible search boundary; it does not authorize ignoring evidence from another layer.

## Symptom-to-Package Matrix

| User symptom or keyword | Start package | First search | Upgrade evidence |
|---|---|---|---|
| 小程序页面、文案、按钮、样式 | `miniapp` | exact copy/class in `pages/`; then referenced `components/` | API/state/payment/platform conditional involved |
| 小程序跳转错误、页面不存在 | `miniapp` | route in `pages.json`, then page caller | backend-generated route or permission involved |
| 小程序请求参数/显示字段 | `miniapp` | `api/` and consuming page | request/response contract must change |
| 收银台、支付拉起、支付后状态 | High-risk task | `core/payment/`, cashier/order API, payment Specs | Always backend/upstream contract review; never Fast Fix |
| 商家后台按钮、表格、弹窗 | `merchant-console` | exact copy in `src/views/`; then matching `src/api/` | menu authority, backend field or shared contract involved |
| 商家后台菜单/权限/404 | Standard/high-risk task | `src/router/`, `src/permission.js`, backend `app/store/` | permission authority or database menu data involved |
| 总后台页面/按钮 | `admin-console` | `src/views/`; then `src/api/` | backend admin authority involved |
| API 报错/明确 PHP 文件报错 | `backend` | exact error/method in owning `app/api|store|admin|timer` surface | shared model, DB, callback or another consumer involved |
| 虚拟支付、退款、Apple 问询 | High-risk task | backend virtual-payment Spec + relevant active Task | Always full planning/contract validation |
| Runtime 权限、缓存、PHP-FPM/Timer | Standard/high-risk task | runtime ownership Spec and `scripts/` | production/shared runtime boundary involved |
| 发布、回滚、线上配置、域名 | `deployment` high-risk task | `$yoshop-deploy`, `deploy/README.md` | Always guarded workflow; never Fast Fix |
| 同一个 Bug 再次出现 | Existing/new Trellis task | task/spec first; session history if CLI available | use `trellis-break-loop` after fix |

## Search Ladder

Use the first identifier that exists:

1. Exact UI copy or exact error code.
2. Route/page/API URL.
3. Method/component/field/enum name.
4. Direct imports and callers from the first hit.
5. Nearest focused test.
6. Only then broaden within the package.

Example:

```bash
rg -n -F '申请退款' yoshop2.0-store/src/views
rg -n 'refund|Refund' yoshop2.0-store/src/views/order yoshop2.0-store/src/api/order
```

Do not start with `find .` or unbounded `grep -R` from the repository root.

## Fast Fix Boundary

Fast Fix requires all of:

- explicit user opt-in;
- one owning package;
- local and reversible behavior;
- no contract/state/security/data/production risk;
- expected coherent patch of no more than 1–3 source files.

Upgrade when targeted search fails after three attempts, another package becomes authoritative, the fix changes a contract, or risk classification changes.

## Package Spec Entry Points

```text
backend            .trellis/spec/backend/server/index.md
miniapp            .trellis/spec/miniapp/frontend/index.md
merchant-console   .trellis/spec/merchant-console/frontend/index.md
admin-console      .trellis/spec/admin-console/frontend/index.md
deployment         .trellis/spec/deployment/ops/index.md
```
