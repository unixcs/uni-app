# Implementation Notes — iOS Apple Refund Inquiry

## 2026-07-11

Implemented backend support for App Store refund flow:

1. `app/api/service/Notify.php`
   - Added `xpay_subscribe_ios_refund_query_notify` dispatch.
   - Returns official `IosRefundQueryResponse` fields: `result_code`, `result_info`, `evidence`.
   - Records inquiry payload into `payment_trade.payload_snapshot` and defaults to suggesting refund when no explicit reject basis exists.

2. `app/common/service/order/Refund.php`
   - Added iOS Apple virtual-trade detection from `query_order.result.order.order_type == 7` or existing iOS refund inquiry evidence.
   - iOS Apple orders no longer call `/xpay/refund_order`; they are marked `waiting_ios_apple_refund` and wait for App Store / Apple / WeChat refund flow.
   - Pending refund sync now skips active `query_order` polling for `waiting_ios_apple_refund`, avoiding false errors and unnecessary upstream queries while Apple owns the refund decision.
   - Android/non-Apple virtual-payment refund behavior remains unchanged.

Validation:

```bash
php -l yoshop2.0/app/api/service/Notify.php
php -l yoshop2.0/app/common/service/order/Refund.php
```

3. Observability
   - Added structured refund-chain logs for three critical stages:
     - local iOS refund request entering `waiting_ios_apple_refund`
     - Apple refund inquiry callback decision
     - WeChat refund notify finalization / pending-binding result
   - Pending refund sync logs now explicitly show `skip_query_order` when Apple owns the refund decision.



## Planned Next Execution (post-design review)

1. Keep the existing backend spike as candidate implementation, subject to design review.
2. Add explicit API projection fields so frontend/admin can distinguish iOS Apple refund mode without parsing snapshots.
3. Update uniapp refund entry / detail copy for iOS Apple orders:
   - stop promising developer-initiated refund
   - guide users to App Store refund
   - keep local request as tracking/support record when enabled
4. Update store/admin order detail actions:
   - hide/disable `服务前退款` for iOS Apple orders
   - replace with status/guidance messaging
5. Run self-check, code review, regression test, acceptance, launch checklist, and online verification in that order.


## 2026-07-11（continued）

Completed product-surface alignment for iOS Apple refund mode:

1. API / projection contract
   - `app/common/model/PaymentTrade.php`
     - Added shared helpers to resolve the most relevant virtual trade for a refund context.
     - Added `buildVirtualRefundProjection()` to expose `ios_apple_refund_required`, `refund_entry_mode`, `refund_guidance`, `refund_display_state`, `refund_display_state_text`.
   - `app/api/model/Order.php`
     - `action_flags` now carries iOS refund-mode guidance.
     - `refund_info` now carries platform-aware display state.
     - When an iOS Apple virtual-pay order has no local refund yet, returns a synthetic `refund_info` projection so the miniapp can still show guidance.
   - `app/api/model/OrderRefund.php`
     - Unified service-refund projection overlays virtual-payment refund projection.
     - `getRefundGoods()` now exposes refund-mode guidance to the refund-apply page.
   - `app/store/model/Order.php`
     - Merchant-side `backend_action_flags` / `virtual_payment_summary` now carry the same iOS refund-mode projection.
     - `checkCanRefundBeforeService()` now blocks iOS Apple orders with an App Store-specific explanation.

2. Miniapp UX alignment
   - `pages/order/detail.vue`
     - Refund state text now prefers `display_state_text`.
     - Shows `refund_guidance` and changes the action copy to `退款指引` in iOS Apple mode.
   - `pages/refund/apply.vue`
     - Adds App Store refund guidance banner.
     - Button copy becomes `提交退款记录` in iOS Apple mode.
     - Validation copy now matches the “local tracking” semantics.
   - `pages/refund/detail.vue`
     - Refund detail header now prefers `display_state_text` and renders `refund_guidance`.

3. Merchant/admin UX alignment
   - `yoshop2.0-store/src/views/order/Detail.vue`
     - Shows iOS refund mode (`App Store 用户申请退款`) and refund guidance in the virtual-payment section.
     - Refund status tag/info card now prefers platform-aware display text.
     - Frontend also blocks `服务前退款` for iOS Apple virtual orders and surfaces guidance instead of firing the action.

4. Diff review conclusion
   - Changes are additive and projection-based, so Android / non-Apple refund flow stays on the original branch.
   - No generic refund API contract was removed; iOS Apple handling is gated behind `wechat_virtual` + Apple-order evidence.

Validation rerun / spot-check summary:

```bash
php -l yoshop2.0/app/api/service/Notify.php
php -l yoshop2.0/app/common/service/order/Refund.php
php -l yoshop2.0/app/common/model/PaymentTrade.php
php -l yoshop2.0/app/api/model/Order.php
php -l yoshop2.0/app/api/model/OrderRefund.php
php -l yoshop2.0/app/store/model/Order.php
./node_modules/.bin/eslint src/views/order/Detail.vue
node - <<'NODE'
const { parse } = require('@vue/compiler-sfc')
;['pages/order/detail.vue','pages/refund/apply.vue','pages/refund/detail.vue'].forEach((file) => {
  parse(require('fs').readFileSync(file, 'utf8'))
  console.log(file + ': PARSE_OK')
})
NODE
npm run build:h5
```

Observed outcome:
- PHP syntax checks pass.
- Merchant targeted lint passes.
- Miniapp changed SFC files parse successfully.
- `build:h5` passes; output still contains existing Sass deprecation warnings and non-blocking environment warnings, but no build failure.
- Merchant repo-wide `npm run lint:nofix` still has historical unrelated failures and is not used as the task-quality gate for this scoped change.

## 2026-07-11（validation / acceptance handoff）

当前执行阶段已从“继续编码”切到“验证闭环”：

1. 已完成定向自测：PHP 语法、商家页 lint、miniapp SFC parse、`build:h5`。
2. 已新增 `qa-acceptance-launch.md`，把后续工作收敛为四件事：
   - 回归测试脚本
   - 缺陷复测闭环
   - 业务验收口径
   - 上线与线上校验清单
3. 后续不建议再扩散实现范围；除非测试发现真实缺口，否则以现有最小正确方案推进验收与发布。

4. 已补充执行模板 `execution-templates.md`：
   - 测试执行记录
   - 缺陷闭环模板
   - 业务验收结论模板
   - 上线与线上校验模板
5. 已新增 `validation-progress.md`：
   - 固化本轮已通过的自动化/静态验证结果
   - 明确真机验证与上游回调验证仍属 pending/blocked
   - 给出最小下一步执行序列，避免继续发散开发


## 2026-07-12（缺陷闭环增量）

- [x] 修复安全模式解密后误走明文签名分支。
- [x] 增加明文/安全模式 6 路签名回归脚本。
- [x] 处理 `xpay_refund_notify` 先于本地售后记录到达：订单锁内自动建档或复用唯一记录。
- [x] 验证重复退款通知幂等、WAIT 记录复用、多候选拒绝猜测、事务回滚恢复。
- [x] 验证 Apple 三次重复问询、未知交易、环境错配、JSON/XML 回包与 3 秒预算。
- [x] 缓存单模型实例的退款状态投影，避免多个 append accessor 重复查询交易。
- [x] 移除问询回包中的原始异常文本，只保留服务端日志。
- [x] 运行最终全量质量门禁并完成独立对抗审查；staged diff 在提交前复核。
- [ ] 使用真实 1 元订单完成 App Store 申请、真实问询、真实退款通知和实际到账验收。
- [ ] 经发布审批后上线，并按 `qa-acceptance-launch.md` 做线上校验。
