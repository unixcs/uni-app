# Validation Progress — iOS Apple Virtual Refund Flow

## Snapshot

- Task: `07-10-ios-trial-vpayment-15001`
- Date: 2026-07-11
- Mode: Trellis inline continuation
- Principle: 先证明最核心的真伪，再决定是否继续扩实现

当前判断：
- **代码层最小方案已落地**
- **自动化/静态验证已通过**
- **剩余风险集中在真机链路与上游回调样本**

---

## Execution Record (current)

| Case ID | Scope | Preconditions | Expected | Actual | Result | Evidence |
|---|---|---|---|---|---|---|
| V1 | Backend PHP syntax | 已完成变更文件 | 6 个 PHP 文件语法通过 | 全部 `No syntax errors detected` | Pass | `php -l` |
| V2 | Store targeted lint | 商家详情页改动完成 | `Detail.vue` lint 通过 | 通过 | Pass | `eslint src/views/order/Detail.vue` |
| V3 | Miniapp SFC parse | 3 个页面改动完成 | 页面可正常解析 | `PARSE_OK` | Pass | `@vue/compiler-sfc` parse |
| V4 | Miniapp H5 build | uniapp 改动完成 | `build:h5` 成功 | 成功，只有既有 Sass/环境 warning | Pass | `npm run build:h5` |
| V5 | Diff whitespace | 变更已整理 | 无格式级阻塞 | 通过 | Pass | `git diff --check` |
| V6 | Trellis task context validate | 任务目录存在 | context 校验通过 | 通过 | Pass | `task.py validate` |
| V7 | iOS 1 元支付链路证据回放 | 已有实单 `order_id=10513` / `out_trade_no=334098380149377916` | 远端订单真实存在且已支付 | DB + runtime 证据确认支付成功，`query_order.status=3` | Pass | `runtime/api/codex-evidence/virtual-payment-attempt-trace/20260710.jsonl` + DB snapshot |
| V8 | iOS 无本地退款单投影校验 | 使用已支付 Apple 虚拟单 `10513` | 用户侧/商家侧都显示“请前往 App Store 申请退款” | API `refund_info.display_state_text` 与商家 `backend_action_flags.refund_display_state_text` 均正确 | Pass | `research/evidence/projection-check-10513.json` |
| V9 | 状态=3 支付态解释校验 | 使用实单 `334098380149377916` 做 sandbox-check probe | `status=3` 被解释为已支付、待下游履约 | 相关命令已改为接受 `[2,3]`，远端探测通过 | Pass | `research/evidence/sandbox-check-status3-334098380149377916.json` |
| V10 | iOS 用户退款指引页面 | 需真机/体验版接口联调 | 文案与入口符合 App Store 模式 | 服务端投影已正确，尚缺真机截图 | Pending | 待截图 |
| V11 | 商家后台防误操作 | 需后台联调 | iOS Apple 订单不可服务前退款 | 服务端投影/拦截已正确，尚缺页面联调样本 | Pending | 待截图/接口记录 |
| V12 | Apple 退款问询回调 | 需上游样本 | 收到 `xpay_subscribe_ios_refund_query_notify` 并正确响应 | 本地代码已接入，待真实样本 | Blocked | 依赖上游 |
| V13 | WeChat 退款结果通知收口 | 需上游样本 | 状态最终收口一致 | 本地代码已接入，待真实样本 | Blocked | 依赖上游 |

---

## Commands Executed

### Backend / frontend checks

```bash
php -l yoshop2.0/app/api/service/Notify.php
php -l yoshop2.0/app/common/service/order/Refund.php
php -l yoshop2.0/app/common/model/PaymentTrade.php
php -l yoshop2.0/app/api/model/Order.php
php -l yoshop2.0/app/api/model/OrderRefund.php
php -l yoshop2.0/app/store/model/Order.php

cd yoshop2.0-store
./node_modules/.bin/eslint src/views/order/Detail.vue

cd /opt/yoshop/yoshop2.0-uniapp
node - <<'NODE'
const fs = require('fs')
const { parse } = require('@vue/compiler-sfc')
for (const file of ['pages/order/detail.vue','pages/refund/apply.vue','pages/refund/detail.vue']) {
  parse(fs.readFileSync(file, 'utf8'))
  console.log(file + ': PARSE_OK')
}
NODE

npm run build:h5
```

### Trellis / workspace checks

```bash
python3 ./.trellis/scripts/task.py validate 07-10-ios-trial-vpayment-15001
git diff --check -- <changed-files>
```

### Runtime / projection checks

```bash
# 1) 校验 iOS Apple 订单在无本地退款单时的展示投影
php /tmp/check_ios_refund_projection.php > .trellis/tasks/07-10-ios-trial-vpayment-15001/research/evidence/projection-check-10513.json

# 2) 用真实 iOS 1 元订单验证 query_order.status=3 的解释
php yoshop2.0/think virtual-payment:sandbox-check \
  --goods-id=10010 \
  --out-trade-no=334098380149377916 \
  --expect-paid \
  --expect-query-evidence \
  --expect-no-duplicate-refund \
  --probe-remote-query
# 然后归档 evidence 到：
# .trellis/tasks/07-10-ios-trial-vpayment-15001/research/evidence/sandbox-check-status3-334098380149377916.json
```

---

## First-Principles Readout

### What is now proven
1. 后端 / 前端改动至少在**语法、页面解析、定向 lint、构建**层面是自洽的。
2. iOS `1 元` Apple 实单在当前环境中确实支付成功，证据为 `order_id=10513 / trade_id=10334 / out_trade_no=334098380149377916`。
3. `query_order.status=3` 在当前业务里必须视为**已支付、待下游履约/消耗**，不能误判为未支付。
4. iOS 退款方案已经从“错误地调用开发者退款”切换为“App Store 引导 + 本地跟踪 + 回调收口”。
5. `PaymentTrade::buildVirtualRefundProjection()` 的误判已修正：**无本地退款单**的 iOS Apple 订单重新显示“请前往 App Store 申请退款”，不会再伪装成“等待 App Store 退款处理”。
6. Android / 非 Apple 路径没有被本轮验证直接打出编译级回归。

### What is not yet proven
1. **真机 UI/交互截图**是否与服务端投影完全一致。
2. **真实 Apple 问询回调**已经打到当前服务并留下样本。
3. **真实 WeChat 退款完成通知**已经把状态最终收口到用户侧和后台侧一致。

### Smallest remaining uncertainty
只剩两类不确定性：
- 真机 UI/交互 是否与设计一致
- 上游回调样本 是否按预期到达并收口

支付主链路与退款投影主语义已经被证据收敛；除此之外，不应继续扩开发散。

---

## Next Actions (strictly minimal)

1. 用真机补齐展示证据：
   - iOS `1 元` 支付成功后的订单详情 `退款指引`
   - 退款申请页 `提交退款记录`
   - 退款详情页 `display_state_text`
2. 用商家后台补齐展示证据：
   - 打开同单订单详情
   - 确认只展示说明，不提供可执行的 `服务前退款`
3. 一旦上游样本到达，立刻补齐：
   - `xpay_subscribe_ios_refund_query_notify`
   - `xpay_refund_notify`
   - 状态收口截图 / 日志 / snapshot

---

## Release Readiness (current)

| Dimension | Status | Comment |
|---|---|---|
| 代码修改完成度 | Ready | 最小方案已落地 |
| 自动化/静态验证 | Ready | 已通过 |
| 真机验证 | Pending | 支付实单已证明，仍缺 UI/交互截图 |
| 上游回调验证 | Blocked | 依赖 Apple/WeChat 样本 |
| 业务验收 | Pending | 依赖真机截图与话术确认 |
| 上线决定 | Not yet | 需至少补齐真机关键路径 |


## 2026-07-12 — 回调认证与退款乱序缺陷闭环

### 新发现并修复

1. **安全模式回调二次误验签（P0）**
   - 根因：外层 `Encrypt` 解密后，内层业务体不再含 `Encrypt`，旧顺序却继续调用明文 `signature` 分支。
   - 影响：仅携带 `msg_signature` 的合法安全模式回调会在成功解密后仍被拒绝。
   - 修复：安全模式由 `Encryptor::decrypt()` 一次完成 `msg_signature`、AppID 与密文校验；仅明文模式再执行 `signature` 校验。
   - 回归：明文/安全模式的合法、缺失、错误签名共 6 个断言全部通过。

2. **退款成功通知先于本地退款记录（P0）**
   - 根因：原逻辑没有本地售后单就停在 `pending_refund_binding`，与 Apple 用户可直接从 App Store 申请退款的事实冲突。
   - 修复：仅对 iOS Apple 虚拟交易，在订单锁中复用唯一活动退款单，或自动创建已审核跟踪单；多候选时拒绝猜测。
   - 回归：无记录自动建档、重复通知幂等、唯一 WAIT 记录复用、多候选拒绝绑定全部通过，且事务回滚后原始订单/交易/快照/退款行完整恢复。

3. **退款列表投影重复查询（P1）**
   - 根因：8 个 append accessor 每次都重建投影并查询 `payment_trade`。
   - 修复：在同一 `OrderRefund` 模型实例、同一原始属性快照内缓存投影；实测 8 个 accessor 只执行 1 次业务 SELECT（另有首次模型元数据查询）。

4. **异常信息泄露（P1）**
   - Apple 问询 fail-open 回包改为通用 evidence；原始异常只进入服务端日志。

### 可重复执行证据

```bash
cd /opt/yoshop/yoshop2.0
php ../.trellis/tasks/07-10-ios-trial-vpayment-15001/research/ios-refund-notify-signature-regression.php
php ../.trellis/tasks/07-10-ios-trial-vpayment-15001/research/ios-refund-query-regression.php --trade-id=10334
php ../.trellis/tasks/07-10-ios-trial-vpayment-15001/research/ios-refund-regression.php --trade-id=10334
```

结果：
- 安全模式合法回调成功解密；错误/缺失 `msg_signature` 被拒绝。
- Apple 问询重复 3 次均建议退款，三次本地处理约 15ms，JSON/XML 契约正确，未知交易 fail-open，环境错配被拒绝。
- 首次成功通知自动创建并完成退款单；第二次返回 `already_completed` 且退款单总数保持 1。
- 唯一 WAIT 退款单原位升级并完成；两条活动候选返回 `ambiguous_refund_binding`。
- 三个业务回归均验证数据库最终回滚。

### 尚不能由代码证明的边界

真实资金到账仍必须由 Apple 审批、真实 `xpay_refund_notify`、用户 Apple/银行卡入账三方证据确认。当前代码回归证明的是“回调可达、响应契约正确、本地状态可幂等收敛”，不等同于已经发生真实到账。

## 2026-07-12 Final Adversarial Review and Full Gate

### Defects closed in the final pass

1. **Completed-refund binding ambiguity**: when snapshot binding is missing and two completed service-refund rows exist, the handler now returns `ambiguous_completed_refund_binding` and does not create a third row.
2. **Idempotent repair**: a unique completed refund row is reused even if snapshot binding is missing; repeated notify repairs order/trade/snapshot convergence without duplicating the refund row.
3. **Deadlock prevention**: refund convergence now uses one lock order: `order -> order_refund -> payment_trade`, matching the Apple-notify auto-record path rather than locking refund and order in reverse order.

The lifecycle regression now covers auto-create, duplicate notify, WAIT-row reuse, unique completed-row reuse, ambiguous active rows, ambiguous completed rows, and complete transaction rollback restoration.

### Final gate result

- 10 changed PHP files: `php -l` passed.
- Plaintext/safe-mode notify signature matrix: passed (6 cases).
- Apple inquiry regression: passed; three repeated calls completed in `21.54ms`, with JSON/XML contracts, unknown-trade fail-open, environment mismatch rejection, persisted decision, and rollback restoration verified.
- Refund lifecycle regression: passed, including `ambiguous_completed_refund_binding`; all writes rolled back.
- Merchant targeted ESLint: passed.
- Merchant production build: passed with existing Browserslist/CSS ordering warnings only.
- Uniapp changed SFC parse: passed.
- Uniapp H5 production build: passed with existing Sass deprecation warnings only.
- `git diff --check`: passed.
- `task.py validate 07-10-ios-trial-vpayment-15001`: passed.
- `build:mp-weixin`: remains an environment-blocked gate because the current WSL/HBuilderX CLI reports `launch mp-weixin` missing/invalid and returns before target artifacts are refreshed. This is not recorded as a source-code pass.

### Honest acceptance boundary

Code-level verification and adversarial review are green. Real completion still requires an App Store refund request against `334098380149377916`, authenticated production inquiry evidence, a real successful `xpay_refund_notify`, four-layer database-state evidence, and user confirmation of actual Apple/bank receipt. No local simulation can prove those upstream facts.

### Production evidence gate added

`virtual-payment:sandbox-check` now supports two read-only acceptance assertions:

```bash
php think virtual-payment:sandbox-check \
  --out-trade-no=334098380149377916 \
  --expect-ios-refund-query \
  --expect-refunded \
  --expect-no-duplicate-refund
```

- `--expect-ios-refund-query` requires a persisted authenticated `xpay_subscribe_ios_refund_query_notify` snapshot and `suggest_refund` decision.
- `--expect-refunded` requires a successful `xpay_refund_notify` plus exactly one refund row and four-layer convergence: refund row completed, order cancelled, trade refunded, snapshot completed.

A negative audit against the current real paid order correctly exits non-zero on all eight missing evidence checks. This is authoritative evidence that the code is ready to observe the flow, but the real Apple inquiry/refund/receipt objective is **not yet complete**.

### P0 release-preflight defect: public callback entry returned HTTP 500

A real HTTPS probe of `https://wx.oiob.cn/notice/virtualPayment.php` exposed a release-blocking defect that local route/file checks had missed:

```text
require(/opt/yoshop/yoshop2.0/public/notice/../vendor/autoload.php): No such file or directory
HTTP 500, empty body
```

Root cause: callback files live under `public/notice/`, so `__DIR__ . '/../vendor/autoload.php'` resolves to the nonexistent `public/vendor/autoload.php`. Fixed both current and legacy virtual-payment entries to load the project-root autoloader with `dirname(__DIR__, 2) . '/vendor/autoload.php'`.

Post-fix evidence:

- unsigned GET returns controlled HTTP 200 failure JSON instead of PHP 500;
- signed URL-verification GET returns the exact random `echostr` over the public HTTPS endpoint;
- `virtual-payment:sandbox-check --probe-notify-endpoint` passes;
- preflight now statically verifies both callback entry autoload paths and can perform the signed public probe;
- real-trade audit mode no longer incorrectly requires sandbox `env=1`; it accepts configured env 0/1, requires the matching app key, and verifies audited trade env equals current configuration.

## 2026-07-12 — 公网安全模式 E2E 与发布前复验（01:30 CST）

### 公网安全模式 POST E2E

新增可重复脚本：

```bash
cd /opt/yoshop/yoshop2.0
php ../.trellis/tasks/07-10-ios-trial-vpayment-15001/research/ios-refund-public-safe-mode-regression.php
```

脚本从运行时读取 AppID/token/EncodingAESKey（不打印任何密钥、签名或密文），构造唯一未知交易的加密 `xpay_subscribe_ios_refund_query_notify` 并 POST 到 `https://wx.oiob.cn/notice/virtualPayment.php`。实测：

- HTTP `200`
- 完整返回 `result_code` / `result_info` / `evidence`
- `result_code=0`
- evidence 包含 `local_trade=not_found_or_not_virtual`
- 端到端耗时 `1096.97ms`，低于 3 秒预算
- 响应未泄露异常、路径或 SQL 信息
- POST 前后该未知 `out_trade_no` 的 `payment_trade` 计数均为 `0`

因此已由公网证据覆盖：nginx/PHP 入口、项目 autoload、安全模式签名解密与 AppID 校验、事件分发、Apple 问询专用回包、3 秒预算。它仍不是 Apple 的真实问询样本。

### 生产验收命令正负向复验

- 生产实单 `334098380149377916` 的只读 preflight：exit `0`；production app key、entry/legacy autoload、route、signed endpoint probe、trade env、无重复退款均通过。
- 不传 `--out-trade-no` 的沙箱门禁在当前 production env 下：exit `1`，明确失败于 `virtual_payment.env`，证明沙箱模式仍强制 env=1。
- 对实单加入 `--expect-ios-refund-query --expect-refunded`：exit `1`，真实问询快照、建议退款决策、成功退款通知、唯一 completed 退款单和四层收敛共 8 项均按预期缺失。
- signed endpoint probe 现同时要求 HTTP 200 与随机 `echostr` 完全一致；报告仅记录 endpoint、状态码、耗时和布尔结果。

### 本轮全量门禁

- 12 个 changed PHP 文件 + 4 个 research PHP 回归脚本：`php -l` 全部通过。
- 3 个后端回归：全部通过；问询三次本地处理 `14.33ms`，退款生命周期所有写入均回滚。
- 商家端定向 ESLint：通过；production build：通过（仅既有 Browserslist/CSS 顺序 warning）。
- Uniapp 三个 changed SFC parse：通过；H5 build：通过（仅既有 Sass warning）。
- `npm run build:mp-weixin`：本轮已通过，HBuilderX 5.15 编译成功并刷新 `unpackage/dist/dev/mp-weixin`；存在与本退款变更无关的鸿蒙 UA 兼容提示。

### 完成边界

代码、自测、公网安全模式探测和本地发布前门禁已达到当前可执行的最小正确闭环；任务仍不能标记完成，因为实单尚无真实 Apple 问询、真实成功退款通知、正式前端发布/灰度记录及用户 Apple 余额或银行卡到账凭证。

### 对抗审查新增闭环：远端查单证据脱敏

最终 diff 审查发现 `--probe-remote-query` 会把微信 `query_order` 原始响应中的订单 `token` 写入 evidence。已改为在写盘前递归屏蔽 key 名含 token/secret/signature/app_key/session_key/aes_key 的值，并将已纳入 task 的历史证据 token 替换为长度标记。生产只读查单复测通过，evidence 中仅保留 `***20`，不再出现原 token；业务状态判断仍使用内存中的未裁剪响应，不受脱敏影响。

## 2026-07-12 — 真实退款动作可执行性补强（01:49 CST）

继续按最终结果反推用户路径时发现，“前往 App Store 申请退款”仍缺少可直接执行的入口与步骤。退款投影和用户申请页现统一明确：

1. 访问 `reportaproblem.apple.com`；
2. 登录购买时使用的 Apple 账户；
3. 选择“请求退款”并提交对应项目；
4. 本地表单只记录退款诉求与售后跟踪，实际决策和到账仍由 Apple/银行负责。

申请页使用可选择的 `<text selectable>` 展示域名，便于用户复制。变更后重新通过 PaymentTrade PHP syntax、Apple 问询/退款生命周期回归、changed SFC parse、H5 build 与 HBuilderX 5.15 mp-weixin build。

同一时点再次执行生产最终证据门禁仍为 exit 1：公网签名探测和 trade env 通过，但真实 Apple 问询、成功退款通知、退款单和四层状态均未出现。这证明当前剩余步骤确实是用户/App Store 外部动作，而非被本地绿灯掩盖。
