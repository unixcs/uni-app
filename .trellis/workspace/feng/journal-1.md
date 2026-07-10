# Journal - feng (Part 1)

> AI development session journal
> Started: 2026-07-08

---



## Session 1: 虚拟支付后台配置改造：复核并部署

**Date**: 2026-07-08
**Task**: 虚拟支付后台配置改造：复核并部署
**Branch**: `main`

### Summary

复核代码、修复前端动态字段联动时序问题，并完成商家后台构建部署与服务刷新。

### Main Changes

## Task
- Active task: `.trellis/tasks/07-08-virtual-payment-goods-config`
- Topic: 商城后台虚拟支付道具配置改造

## What was verified
- Re-reviewed the touched backend and merchant-backend frontend files against the agreed boundary:
  - `goods_price` remains the only price source.
  - backend remains the rule owner.
  - `vp_product_name` stays a mirror-only field and does not enter payment core logic.
  - payment core scope was not expanded.
- Re-ran targeted validation:
  - `php -l yoshop2.0/app/store/model/Goods.php`
  - `node --check` on the touched frontend JS modules
  - targeted `vue-cli-service lint` on the touched merchant-backend files
  - `git diff --check` on the changed files

## Fix made during review
- Found and fixed a concrete frontend timing risk in `yoshop2.0-store/src/views/goods/modules/virtualPaymentFormMixin.js`:
  - when enabling virtual payment, the auto-sync could run before the second forced render completed;
  - added a deferred post-render sync path so dynamically rendered `v-decorator` fields are present before sync/validation;
  - when disabling virtual payment, `goods_price` is revalidated immediately so stale VP price errors are cleared.

## Deployment actions executed
- Because the running merchant backend is served from `yoshop2.0/public/store`, the frontend changes required a rebuild and redeploy.
- Executed:
  - cleared `yoshop2.0/runtime/*`
  - `systemctl restart php8.3-fpm nginx`
  - built `yoshop2.0-store`
  - copied `yoshop2.0-store/dist` to `yoshop2.0/public/store`
  - `systemctl reload nginx`
- Verified:
  - `php8.3-fpm` and `nginx` are active
  - deployed `public/store/index.html` hash matches the newly built `dist/index.html`
  - local `http://127.0.0.1/store/` returns the merchant backend HTML

## Remaining status
- Code changes are deployed locally/running.
- Task is intentionally left `in_progress` for now because no commit/archive step was executed in this session.


### Git Commits

(No commits - planning session)

### Testing

- [OK] (Add test results)

### Status

[OK] **Completed**

### Next Steps

- None - task complete


## Session 2: 虚拟支付后台配置：自动化测试全绿

**Date**: 2026-07-09
**Task**: 虚拟支付后台配置：自动化测试全绿
**Branch**: `main`

### Summary

修复登录阻塞项，补齐本地数据库字段，并完成接口级 add/edit + 前端 helper 自动化验证。

### Main Changes

## Task
- Active task: `.trellis/tasks/07-08-virtual-payment-goods-config`
- Topic: 虚拟支付商品后台配置自动化测试与登录阻塞修复

## Blocker handled first
- User reported merchant-backend login popup error:
  `file_put_contents(.../runtime/cache/4c/...php): Failed to open stream: No such file or directory`
- Repaired runtime directory state by ensuring:
  - `yoshop2.0/runtime/{cache,log,schema,store,temp}` exist
  - `runtime/cache/00` ~ `runtime/cache/ff` first-level buckets exist
  - ownership is writable by `www-data`
- Re-smoked the login API and confirmed it returns normal business JSON instead of filesystem write errors.

## Additional readiness fix found during testing
- The code had already documented the DB change, but the local database had **not yet applied** the new column.
- Applied the local schema change required for this feature:
  - `ALTER TABLE yoshop_goods ADD COLUMN vp_product_name varchar(100) NOT NULL DEFAULT '' ...`
- Confirmed the column now exists before continuing with functional verification.

## Automated tests executed
### 1) Merchant-backend API smoke
- invalid login path returns expected business error
- valid login succeeds and returns a token
- authenticated goods list request succeeds

### 2) Interface-level add/edit verification for the new feature
Using existing service goods fixture `goods_id=10004` plus one disposable added record:
- edit with `goods_price=158` => `vip158 / vip158 / 15800`
- edit with `goods_price=0.01` => `vip001 / vip001 / 1`
- edit with `goods_price=0.11` => `vip011 / vip011 / 11`
- edit with `goods_price=9.9` and VP enabled => rejected by backend integer rule
- edit as non-service goods with VP enabled => rejected by backend service/no-delivery rule
- edit with `vp_enabled=0` => related VP fields cleared by backend
- add with `goods_price=88` => saved as `vip88 / vip88 / 8800`
- add with `goods_price=9.9` => rejected
- disposable added goods cleaned up by soft delete

### 3) Frontend helper checks
- verified `virtualPayment.js` cases for:
  - `158 -> vip158`
  - `0.01 -> vip001`
  - `0.11 -> vip011`
  - `9.9` invalid
  - `isVirtualPaymentConfigMatched`
  - `priceToFen`

## Result
- Automated test result: **green**
- Task remains `in_progress` pending the user's manual acceptance and then commit/archive flow.

## Artifacts
- `.trellis/tasks/07-08-virtual-payment-goods-config/research/virtual_payment_api_test.py`
- `.trellis/tasks/07-08-virtual-payment-goods-config/research/2026-07-09-test-report.md`


### Git Commits

(No commits - planning session)

### Testing

- [OK] (Add test results)

### Status

[OK] **Completed**

### Next Steps

- None - task complete

## 2026-07-09 虚拟支付手测回归修复
- 收到用户手测反馈：商家后台编辑商品时，将价格改为 `0.02` 并开启虚拟支付后，保存报错“仅单规格且无需配送的服务商品可启用虚拟支付”。
- 复现确认：真实商家后台 Create / Update 提交 payload 不带 `delivery_type`，而后端虚拟支付校验直接基于请求体判断服务商品，导致上下文缺失时误判失败。
- 最小修复：在 `yoshop2.0/app/store/model/Goods.php` 为虚拟支付校验补齐后端上下文；缺失 `delivery_type` 时复用当前商品 accessor 默认语义，缺失 `serviceIds` 时在编辑场景回退到现有服务关联。
- 原则保持不变：`goods_price` 仍为唯一价格源；后端继续作为规则 owner；支付核心逻辑未扩 scope；`vp_product_name` 仍仅作镜像字段。
- 已补充自动化回归：
  - edit 场景模拟真实 UI payload 缺失 `delivery_type`，`0.02 -> vip002` 成功
  - add 场景模拟真实 UI payload 缺失 `delivery_type`，`0.02 -> vip002` 成功
- 已更新测试报告：`.trellis/tasks/07-08-virtual-payment-goods-config/research/2026-07-09-test-report.md`
- 当前状态：自动化验证继续全绿，等待用户重新手测验收，再进入 spec update / commit / archive。


## Session 3: 虚拟支付后台配置：手测回归修复并归档

**Date**: 2026-07-09
**Task**: 虚拟支付后台配置：手测回归修复并归档
**Branch**: `main`

### Summary

修复商家后台真实提交流程遗漏 delivery_type 导致的虚拟支付误判，补充回归测试、更新前端契约 spec，并完成任务归档。

### Main Changes

(Add details)

### Git Commits

| Hash | Message |
|------|---------|
| `17e00c2` | (see git log) |
| `b7e91a2` | (see git log) |

### Testing

- [OK] (Add test results)

### Status

[OK] **Completed**

### Next Steps

- None - task complete


## Session 4: Complete feedback MVP and singleton content delivery

**Date**: 2026-07-10
**Task**: Complete feedback MVP and singleton content delivery
**Branch**: `main`

### Summary

Closed the feedback/complaint MVP and homepage popup/privacy singleton content tasks after validation, deployment, and task archival.

### Main Changes

(Add details)

### Git Commits

| Hash | Message |
|------|---------|
| `62662dd` | (see git log) |
| `991a311` | (see git log) |

### Testing

- [OK] (Add test results)

### Status

[OK] **Completed**

### Next Steps

- None - task complete


## Session 5: 收口服务订单契约与下单表单更新任务

**Date**: 2026-07-10
**Task**: 收口服务订单契约与下单表单更新任务
**Branch**: `main`

### Summary

完成服务订单契约升级、商家后台搜索与历史订单软删除隐藏收口，归档父子任务。

### Main Changes

(Add details)

### Git Commits

| Hash | Message |
|------|---------|
| `c23b610` | (see git log) |
| `bcafa2d` | (see git log) |
| `91cdef2` | (see git log) |

### Testing

- [OK] (Add test results)

### Status

[OK] **Completed**

### Next Steps

- None - task complete
