# QA / Acceptance / Launch Checklist — iOS Apple Virtual Refund Flow

## 1. Current Phase Snapshot

按照第一性原理拆解，这个任务已经从“是否还能继续调退款 API”收敛为“是否把产品入口、状态机、回调收口和验收闭环做对”。

当前阶段结论：

- **已完成**：方案规划、方案评审、编码开发、开发自测、代码级 review
- **当前重点**：测试（缺陷复测闭环）→ 业务验收 → 上线准备 → 线上校验
- **不再继续深挖**：iOS Apple 订单的“开发者主动退款”能力，因为官方规则已明确不支持

---

## 2. Self-check Result (already completed)

### 2.1 Backend syntax

```bash
php -l yoshop2.0/app/api/service/Notify.php
php -l yoshop2.0/app/common/service/order/Refund.php
php -l yoshop2.0/app/common/model/PaymentTrade.php
php -l yoshop2.0/app/api/model/Order.php
php -l yoshop2.0/app/api/model/OrderRefund.php
php -l yoshop2.0/app/store/model/Order.php
```

Expected result:
- All PHP files return `No syntax errors detected`

### 2.2 Store targeted lint

```bash
cd yoshop2.0-store
./node_modules/.bin/eslint src/views/order/Detail.vue
```

Expected result:
- No lint error in the changed merchant page

### 2.3 Miniapp SFC parse + build

```bash
cd /opt/yoshop/yoshop2.0-uniapp
node - <<'NODE'
const fs = require('fs')
const { parse } = require('@vue/compiler-sfc')
for (const file of ['pages/order/detail.vue', 'pages/refund/apply.vue', 'pages/refund/detail.vue']) {
  parse(fs.readFileSync(file, 'utf8'))
  console.log(file + ': PARSE_OK')
}
NODE

npm run build:h5
```

Expected result:
- Changed SFC files parse successfully
- `build:h5` succeeds
- Existing Sass deprecation warnings may remain, but must not be a new blocker caused by this task

---

## 3. Regression Test Matrix

> 剃刀定律：只测最能证明“方案对/方案错”的关键路径，不做花哨扩展。

| Case ID | Scenario | Why it matters | Expected result | Evidence |
|---|---|---|---|---|
| R1 | Android 虚拟支付退款 | 证明本次改动没有破坏原主链路 | Android 仍可正常提交退款并完成 | 用户侧截图 + 后台状态 |
| R2 | iOS 低价商品（如 0.02 元）支付 | 证明 `-15001` 属于金额/上游单未创建问题 | 不能作为有效 Apple 退款测试样本；需改用 `>= 1 元` | 下单日志 + 交易号记录 |
| R3 | iOS 1 元支付成功 | 证明 Apple 支付链路本身可走通 | 用户可跳转 App Store 完成支付，回到小程序后订单为已支付 | 订单详情截图 + 支付成功日志 |
| R4 | iOS 用户侧退款入口 | 证明用户入口已改成“引导 + 登记”，不是误导性“开发者原路退款” | 订单详情显示 `退款指引`，退款页展示 App Store 指引 | 小程序截图 |
| R5 | iOS 商家侧服务前退款 | 证明后台已做防误操作拦截 | 商家点击后只提示，不应发起退款 | 后台截图 + 网络/日志 |
| R6 | Apple 退款问询回调 | 证明开发者响应问询链路已接通 | 回调入库，返回 `result_code/result_info/evidence` | 回调日志 + `payload_snapshot` |
| R7 | WeChat 退款结果通知收口 | 证明最终状态能闭环，而不是永远停在等待态 | 用户端/后台状态一致收口 | 日志 + 状态截图 |
| R8 | 缺陷复测 | 证明修复不是一次性幸运命中 | 同一缺陷按原路径不再复现 | 复测记录 |

---

## 4. Real-device Test Script

## 4.1 Script A — iOS 1 元支付成功路径

前提：
- 使用 **iPhone 真机**
- 小程序为 **体验版**
- 测试商品价格设置为 **1 元或以上**
- Apple 测试账号 / 支付环境准备完成

步骤：
1. 打开小程序体验版
2. 进入虚拟商品下单页
3. 下单金额使用 `1 元`
4. 发起支付
5. 跳转 App Store 并完成支付
6. 返回小程序订单详情页

预期：
- 不再出现 `-15001`
- 订单状态变为已支付
- 对应 `payment_trade` 能查到成功交易
- 若有虚拟支付摘要，应能看到支付成功后的基础状态

建议留证：
- 小程序下单截图
- App Store 支付成功截图
- 返回后订单详情截图
- 对应交易号 / `out_trade_no`

## 4.2 Script B — iOS 用户退款入口验证

步骤：
1. 在已支付 iOS Apple 虚拟订单详情页查看退款入口
2. 点击退款入口
3. 进入退款申请页
4. 提交一条退款说明
5. 查看退款详情页

预期：
- 订单详情按钮文案应为 **`退款指引`**，不是误导性“直接退款”
- 退款申请页展示 App Store 指引文案
- 提交按钮文案为 **`提交退款记录`**
- 退款详情页状态优先展示平台感知文案：
  - `请前往 App Store 申请退款`
  - 或 `等待 App Store 退款处理`
- 系统语义应是“登记诉求 + 售后跟踪”，不是“开发者已向 Apple 发起退款”

建议留证：
- 订单详情页截图
- 退款申请页截图
- 提交后退款详情页截图

## 4.3 Script C — 商家后台防误操作验证

步骤：
1. 商家后台打开同一笔 iOS Apple 虚拟支付订单详情
2. 尝试点击 `服务前退款`

预期：
- 出现明确提示：
  - `iOS App Store 虚拟支付订单需由用户在 App Store 申请退款，商家不可直接发起服务前退款`
- 后台不应继续向退款接口推进
- 不应出现新的主动退款请求日志

建议留证：
- 后台订单详情截图
- 点击提示截图
- 同时段日志 / 接口监控记录

## 4.4 Script D — Apple 退款问询回调验证

步骤：
1. 用户在 App Store 发起退款
2. 等待微信 / Apple 发起 `xpay_subscribe_ios_refund_query_notify`
3. 检查服务端 notify 处理结果

预期：
- 事件命中：`xpay_subscribe_ios_refund_query_notify`
- 响应体为官方字段：
  - `result_code`
  - `result_info`
  - `evidence`
- `payment_trade.payload_snapshot` 中写入：
  - `ios_refund_query_notify`
  - `virtual_refund.ios_refund_required = true`
  - `virtual_refund.ios_refund_query_decision`

建议留证：
- notify 请求 payload
- notify 响应体
- 数据库存档 / 调试输出
- 结构化日志关键字：
  - `ios_refund_query_suggest_refund`
  - `ios_refund_query_suggest_refund_without_local_trade`

## 4.5 Script E — WeChat 退款结果通知收口验证

步骤：
1. 在 Apple / WeChat 上游退款结果产生后
2. 等待 `xpay_refund_notify`
3. 核对本地退款单、订单状态、后台展示状态

预期：
- 若退款完成：状态能收口到 `已退款`
- 若仍未绑定本地退款单：能看到中间态日志，而不是无声失败
- 用户侧与后台侧展示一致

建议留证：
- 结构化日志关键字：
  - `refund_notify_completed`
  - `refund_notify_pending_binding`
  - `refund_notify_pending_refund_ready`
  - `skip_query_order`
- 退款详情页截图
- 后台订单详情截图

---

## 5. Defect Closure Loop

每发现一个缺陷，都按下面格式补齐，避免“修了但没闭环”：

| Defect | Repro path | Root cause | Fix owner | Fix commit / file | Retest result | Closed? |
|---|---|---|---|---|---|---|
| 示例：iOS 后台仍可点服务前退款 | 后台订单详情 -> 服务前退款 | 未做 iOS 平台分流拦截 | 研发 | `app/store/model/Order.php` | 已拦截 | Yes |

原则：
- 缺陷必须能 **复现**、**解释**、**验证修复**
- 如果只是“现在看起来好了”，不算闭环

---

## 6. Business Acceptance Checklist

业务验收不是看代码，而是看“产品语义是否终于说真话”。

### 6.1 用户侧验收
- [ ] iOS 用户不会再被误导为“开发者可直接原路退款”
- [ ] 退款入口文案明确区分 App Store 退款模式
- [ ] 退款申请页能解释“本页仅登记诉求，最终退款由 Apple 决策”
- [ ] 退款详情页状态表达符合真实进展

### 6.2 商家侧验收
- [ ] 商家后台不能再直接发起 iOS Apple 订单服务前退款
- [ ] 后台能看到明确退款模式与退款说明
- [ ] 客服 / 商家能基于本地记录跟踪用户诉求

### 6.3 业务规则验收
- [ ] 团队内部统一接受：**iOS Apple 退款不由开发者主动发起**
- [ ] 团队内部统一接受：**不能承诺 100% 退款到账，Apple 拥有最终决定权**
- [ ] Android / 非 Apple 虚拟支付退款规则保持不变

---

## 7. Release Checklist

## 7.1 Pre-release
- [ ] 合并前再次执行本任务涉及文件的 PHP 语法检查
- [ ] 再次执行 miniapp SFC parse / h5 构建
- [ ] 再次确认商家侧详情页 lint 通过
- [ ] 研发 / 测试 / 业务对“iOS 退款语义变更”达成一致
- [ ] 准备客服话术：引导用户去 App Store 申请退款
- [ ] 准备回滚说明：若有问题，优先回滚前端展示/入口变更；不要轻易回滚已接入的官方回调支持

## 7.2 Release note 建议
- iOS Apple 虚拟支付订单退款入口改为 App Store 引导模式
- 商家后台新增 iOS Apple 退款防误操作拦截
- 接入 Apple 退款问询回调与状态收口能力
- Android / 非 Apple 虚拟支付退款链路保持原有行为

---

## 8. Online Verification Checklist

上线后优先盯住最少但最关键的信号。

### 8.1 User-facing
- [ ] 新 iOS 订单支付成功后，详情页入口文案正确
- [ ] 新 iOS 订单退款页能看到 App Store 指引
- [ ] Android 订单退款入口与旧逻辑一致

### 8.2 Backend logs
- [ ] 可检索 `ios_refund_query_suggest_refund`
- [ ] 可检索 `refund_notify_completed`
- [ ] 可检索 `refund_notify_pending_binding`
- [ ] 可检索 `refund_notify_pending_refund_ready`
- [ ] 可检索 `skip_query_order`

### 8.3 Data convergence
- [ ] `payment_trade.payload_snapshot` 存在 iOS 问询 / 退款跟踪字段
- [ ] 本地退款单状态与小程序展示一致
- [ ] 本地退款单状态与商家后台展示一致

### 8.4 Rollback observation
若出现异常，先判断是哪一层错：
1. **入口文案错**：优先修前端/投影字段
2. **状态没收口**：查 notify / refund service / payload snapshot
3. **Android 回归**：优先按平台分流点回查 `PaymentTrade::isIosAppleVirtualTrade()` 与 refund 分支

---

## 9. Final Acceptance Gate

只有同时满足下面四条，才建议进入 `/trellis:finish-work`：

- [ ] 关键回归项（Android、iOS 1 元支付、iOS 退款引导、后台防误操作）至少各过一遍
- [ ] 至少一条 Apple 退款问询 / 退款结果样本被记录并可追踪
- [ ] 业务方确认文案、入口、客服语义可接受
- [ ] 上线与线上观测人、观测窗口、回滚口径已明确


## 2026-07-12 Gate Update

### 已解除的代码级阻塞

- [x] 明文 `signature` 与安全模式 `msg_signature` 入口均有可重复回归。
- [x] Apple 问询三次重复调用、未知交易 fail-open、环境错配、JSON/XML 响应已验证。
- [x] 退款成功通知早于本地记录时自动建档并完成。
- [x] 重复退款通知不重复建档；WAIT 记录复用；多候选不猜测。
- [x] 回归测试不留业务脏数据，已验证事务回滚。

### 发布前硬门槛（仍未满足，不得声称已上线或真实到账）

1. 完成最终全量 PHP lint、商家端定向 ESLint、uniapp SFC parse/build、`git diff --check`、Trellis validate。
2. 由有发布权限的人员确认配置：消息推送 Token、EncodingAESKey、AppID、回调 URL 和生产 `env=0`。
3. 使用真实订单 `out_trade_no=334098380149377916` 在 App Store 发起退款。
4. 留存真实问询的接收时间、响应时间及 `result_code/result_info/evidence`（敏感字段脱敏）。
5. 留存 Apple 审批结果与真实 `xpay_refund_notify`，核对四层状态：退款单、订单、交易、快照。
6. 用户确认 Apple 余额/银行卡实际到账；没有该证据时只能验收“系统已标记退款”，不能验收“真实到账”。
7. 灰度观察无异常后再全量；若签名失败率、回调 5xx、`ambiguous_refund_binding` 或状态不一致升高，立即回滚代码并保留回调日志。

## 2026-07-12 Release Readiness Update

The code-level release gate is green after a main-agent independent adversarial review (inline Trellis mode; no implementation/check subagent was dispatched). The review added and passed regression coverage for missing snapshot binding with one or multiple completed refund rows and unified transaction lock order to `order -> order_refund -> payment_trade`.

Release remains **not approved / not deployed** until all external gates below have evidence:

1. Run the mp-weixin build in a functioning HBuilderX/CI environment; the current WSL CLI cannot refresh target artifacts.
2. Submit the real App Store refund for `334098380149377916`.
3. Capture authenticated `xpay_subscribe_ios_refund_query_notify` and prove the complete response is returned within 3 seconds.
4. Capture successful `xpay_refund_notify` and verify refund/order/trade/snapshot convergence.
5. Obtain user confirmation of actual Apple balance/card receipt.
6. Complete release approval, deployment, gray observation, and rollback readiness checks.

## Machine-checkable production acceptance command

After the real Apple/WeChat callbacks arrive, run:

```bash
cd /opt/yoshop/yoshop2.0
php think virtual-payment:sandbox-check \
  --store-id=10001 \
  --goods-id=10010 \
  --out-trade-no=334098380149377916 \
  --expect-ios-refund-query \
  --expect-refunded \
  --expect-no-duplicate-refund \
  --require-safe-mode \
  --probe-notify-endpoint
```

Exit code `0` is required before claiming callback/state convergence. This command still cannot prove the user's actual Apple balance/card receipt; retain the user receipt evidence as a separate mandatory gate.

## 2026-07-12 Public Safe-Mode Callback Gate

A production-origin, encrypted unknown-trade inquiry was sent through the public callback entry using the configured safe-mode credentials. The reusable regression is:

```bash
cd /opt/yoshop/yoshop2.0
php ../.trellis/tasks/07-10-ios-trial-vpayment-15001/research/ios-refund-public-safe-mode-regression.php
```

Acceptance result: HTTP 200; full Apple inquiry response shape; suggest-refund decision; non-empty unknown-trade evidence; no business trade row created; no exception leakage; 1096.97ms end-to-end latency. This proves public routing and safe-mode mechanics but is deliberately not labeled as a real Apple callback.

The production readiness probe now requires both HTTP 200 and an exact signed `echostr` match. The final real-order command remains red until Apple sends the inquiry and refund callbacks; this is the expected release-evidence boundary, not a test waiver.

The miniapp build gate was rerun successfully with HBuilderX 5.15 (`npm run build:mp-weixin`). This generated a development mp-weixin artifact for verification only; a formal release upload, approval, gray rollout, and post-release observation remain required.
