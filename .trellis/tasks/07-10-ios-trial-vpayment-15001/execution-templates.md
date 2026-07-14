# Execution Templates — Test / Defect Loop / Acceptance / Online Verification

> 目标：把后续执行从“口头推进”变成“可记录、可复盘、可上线”的闭环。

---

## 1. Test Execution Record Template

每跑一条用例，就补一条，不要事后凭印象回忆。

| Case ID | DateTime | Env | Operator | Preconditions | Steps | Expected | Actual | Result | Evidence | Notes |
|---|---|---|---|---|---|---|---|---|---|---|
| R1 |  | Android / prod-like / trial |  |  |  |  |  | Pass / Fail / Blocked | 截图/日志/订单号 |  |
| R2 |  | iOS / trial |  |  |  |  |  | Pass / Fail / Blocked | 截图/日志/交易号 |  |
| R3 |  | iOS / trial |  |  |  |  |  | Pass / Fail / Blocked | 截图/日志/订单号 |  |
| R4 |  | iOS / trial |  |  |  |  |  | Pass / Fail / Blocked | 页面截图 |  |
| R5 |  | store-admin |  |  |  |  |  | Pass / Fail / Blocked | 后台截图/网络记录 |  |
| R6 |  | backend notify |  |  |  |  |  | Pass / Fail / Blocked | payload/response/log |  |
| R7 |  | backend notify |  |  |  |  |  | Pass / Fail / Blocked | payload/response/log |  |
| R8 |  | mixed |  |  |  |  |  | Pass / Fail / Blocked | 缺陷单/复测记录 |  |

### Result meaning
- **Pass**：预期与实际一致，且证据已留存
- **Fail**：已稳定复现偏差
- **Blocked**：受外部依赖阻塞，例如 Apple 问询样本暂未到达

---

## 2. Recommended Filled Example (for this task)

### R1 — Android 虚拟支付退款回归
- 目标：证明本次 iOS 分流改动没有破坏 Android 退款主链路
- 预期：Android 订单仍可正常退款，不出现 iOS 特有文案/拦截

### R2 — iOS 低价商品 `-15001`
- 目标：证明这是测试样本问题，不是退款方案问题
- 预期：低于 Apple 实际可测门槛的商品不能作为有效退款样本；正式测试使用 `>= 1 元`

### R3 — iOS `1 元` 支付成功
- 目标：证明 iOS Apple 支付链路已可用
- 预期：App Store 扣费成功，返回小程序后订单变已支付

### R4 — iOS 用户退款引导
- 目标：证明前端已说真话
- 预期：
  - 按钮文案：`退款指引`
  - 页面说明：App Store 申请退款
  - 提交按钮：`提交退款记录`

### R5 — 商家后台防误操作
- 目标：证明商家不能再误发开发者退款
- 预期：点击 `服务前退款` 后只提示，不继续发起退款流程

### R6 — Apple 退款问询回调
- 目标：证明 `xpay_subscribe_ios_refund_query_notify` 已接通
- 预期：
  - 返回 `result_code / result_info / evidence`
  - `payload_snapshot` 写入问询信息

### R7 — WeChat 退款结果通知收口
- 目标：证明最终状态不是悬空的
- 预期：退款完成后用户侧和后台侧状态一致收口

### R8 — 缺陷复测闭环
- 目标：证明不是“碰巧好了”
- 预期：已发现缺陷均有复测结论

---

## 3. Defect Closure Template

> 第一性原理：每个缺陷都要回答四个问题——怎么复现、为什么发生、修了哪里、怎么证明修好了。

| Defect ID | Found In | Symptom | Stable Repro Steps | Root Cause | Fix Scope | Owner | Evidence | Retest Result | Status |
|---|---|---|---|---|---|---|---|---|---|
| D-001 | iOS trial |  |  |  |  |  |  |  | Open / Fixed / Closed |

### Defect Detail Template

```md
#### Defect ID: D-xxx
- Found in:
- Reporter:
- Time:
- Scenario:
- Symptom:
- Stable repro steps:
  1.
  2.
  3.
- Root cause:
- Why previous logic failed:
- Fix files:
- Fix summary:
- Risk of regression:
- Retest evidence:
- Closure decision:
```

### Suggested initial defect seeds for this task

```md
#### D-001
- Scenario: iOS 低价虚拟商品支付
- Symptom: `-15001` 且微信侧未创建虚拟支付订单
- Root cause candidate: 商品价格不满足 iOS Apple 实测最小支付要求，导致不是有效 Apple 支付样本

#### D-002
- Scenario: iOS 支付成功后从小程序/商家后台发起退款
- Symptom: `IOS订单不支持开发者发起退款`
- Root cause candidate: iOS Apple 订单退款控制权不在开发者侧，旧入口语义错误
```

---

## 4. Business Acceptance Record Template

> 业务验收关注的是“用户是否被正确引导，商家是否不会误操作”。

### 4.1 Acceptance Summary

| Item | Verdict | Evidence | Comment |
|---|---|---|---|
| 用户侧退款语义正确 | Pass / Fail |  |  |
| 商家侧防误操作生效 | Pass / Fail |  |  |
| Apple 问询回调链路可追踪 | Pass / Fail / Blocked |  |  |
| WeChat 退款通知可收口 | Pass / Fail / Blocked |  |  |
| Android 链路无回归 | Pass / Fail |  |  |

### 4.2 Acceptance Conclusion Template

```md
## 业务验收结论

- 验收日期：
- 验收参与人：
- 验收环境：

### 结论
- [ ] 通过
- [ ] 有条件通过
- [ ] 不通过

### 通过依据
1.
2.
3.

### 遗留风险
1.
2.

### 上线前附加条件（如有）
1.
2.
```

---

## 5. Release Decision Template

```md
## 上线决策

- 决策时间：
- 决策人：
- 发布批次：
- 目标环境：

### 是否允许上线
- [ ] 允许
- [ ] 暂缓

### 允许上线的前提
- [ ] Android 退款回归通过
- [ ] iOS 1 元支付验证通过
- [ ] iOS 用户退款引导验证通过
- [ ] 商家后台防误操作验证通过
- [ ] 至少具备一条回调链路证据或明确的线上观测方案

### 回滚口径
1. 若仅页面文案/入口异常：优先回滚前端展示层
2. 若状态投影异常：回查 API 投影与 `PaymentTrade` 平台识别
3. 若回调处理异常：重点检查 `Notify.php` 与 `Refund.php`
```

---

## 6. Online Verification Log Template

### 6.1 First 30-min observation

| Time | Signal | Expected | Actual | Verdict | Action |
|---|---|---|---|---|---|
| T+5m | 新 iOS 订单退款入口文案 | 显示 App Store 指引 |  |  |  |
| T+10m | 商家后台点击服务前退款 | 被拦截 |  |  |  |
| T+15m | Android 退款链路 | 不受影响 |  |  |  |
| T+20m | 结构化日志 | 可检索关键字 |  |  |  |
| T+30m | 状态收口样本 | 至少看到一条有效记录 |  |  |  |

### 6.2 Key log/event checklist

建议至少检查这些关键字是否能被检索到：

```text
ios_refund_query_suggest_refund
ios_refund_query_suggest_refund_without_local_trade
refund_notify_completed
refund_notify_pending_binding
refund_notify_pending_refund_ready
skip_query_order
```

### 6.3 Online conclusion template

```md
## 线上校验结论

- 校验时间窗口：
- 校验执行人：

### 核心观察
1.
2.
3.

### 是否发现异常
- [ ] 否
- [ ] 是

### 异常摘要（如有）
1.
2.

### 结论
- [ ] 继续观察
- [ ] 稳定，可关闭任务
- [ ] 需要回滚/热修
```

---

## 7. Minimal Next Action

按剃刀定律，下一步只做两件事：

1. 先按 `qa-acceptance-launch.md` 跑 `R1 ~ R5`
2. 将结果直接填进本文件模板，避免再次口头化

当 `R6 / R7` 样本到达后，再补齐回调与状态收口证据。
