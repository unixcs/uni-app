# Technical Design — iOS App Store 退款审核与服务冻结（精简版）

## 1. First-Principles Boundary

不可约事实：

1. Apple 退款成功是异步外部事实；开发者建议不能决定最终结果。
2. 没有可信 Apple 拒绝通知，因此不能安全恢复原订单履约。
3. 防资损只需要把“退款风险建立”和“服务状态写入”在同一订单锁上串行化。
4. 多轮问询需要 append-only 历史，但不需要新任务队列或监控平台。
5. 本任务不触碰积分、结算、优惠券、库存和累计消费。

最小机制：订单风险三态、简单问询历史表、统一订单锁、共享状态投影和幂等回填。

## 2. Data Model

### 2.1 Order risk fields

在 `yoshop_order` 增加：

```text
ios_refund_risk_status tinyint unsigned NOT NULL DEFAULT 0
  0 NONE
  10 LOCKED
  20 REFUNDED

ios_refund_risk_source varchar(32) NOT NULL DEFAULT ''
  ''：首次风险来源；实际值为 LOCAL_APPLY、APPLE_INQUIRY、REFUND_NOTIFY_RECOVERY

ios_refund_risk_time int unsigned NOT NULL DEFAULT 0
```

只允许 `NONE -> LOCKED -> REFUNDED`。首次来源不被后续事件覆盖。

### 2.2 Inquiry history

新增简单 append-only 表 `yoshop_payment_ios_refund_inquiry`：

```text
inquiry_id       bigint unsigned AUTO_INCREMENT PK
order_id         int unsigned NOT NULL DEFAULT 0  # 绑定失败时为 0
order_refund_id  int unsigned NOT NULL DEFAULT 0
trade_id         int unsigned NOT NULL DEFAULT 0
store_id         int unsigned NOT NULL DEFAULT 0
user_id          int unsigned NOT NULL DEFAULT 0
pay_order_id     varchar(50) NOT NULL DEFAULT ''
fingerprint      char(64) NOT NULL DEFAULT ''
migration_key    varchar(100) NULL
binding_status   varchar(40) NOT NULL DEFAULT ''
request_reason   varchar(1000) NOT NULL DEFAULT ''
request_payload  text
service_stage    varchar(20) NOT NULL DEFAULT 'UNKNOWN'
order_status     tinyint unsigned NOT NULL DEFAULT 0
delivery_status  tinyint unsigned NOT NULL DEFAULT 0
receipt_status   tinyint unsigned NOT NULL DEFAULT 0
audit_status     tinyint NULL
result_code      tinyint unsigned NOT NULL DEFAULT 1
result_info      varchar(255) NOT NULL DEFAULT ''
evidence         varchar(1000) NOT NULL DEFAULT ''
response_ms      int unsigned NOT NULL DEFAULT 0
received_at      int unsigned NOT NULL DEFAULT 0
create_time      int unsigned NOT NULL DEFAULT 0
update_time      int unsigned NOT NULL DEFAULT 0
```

索引：

- `(order_id, received_at, inquiry_id)`；
- `(trade_id)`；
- `(fingerprint)`，非唯一；
- nullable unique `(migration_key)`，仅保证历史迁移幂等。

fingerprint 使用规范化关键字段的 canonical JSON SHA-256，只用于关联重复请求，不固定响应决定。

不新增 alert 表、convergence 字段、claim token、lease 或 worker 状态。

## 3. Shared Rules

### 3.1 Final trade binding

改变订单前必须验证：

- `trade.trade_id == order.trade_id`；
- order/store/user 绑定一致；
- platform 为 `wechat_virtual`；
- channel_class 为 iOS Apple；
- 交易为有效支付/退款事实。

无法证明时 fail-closed，返回 `result_code=1`，不修改其他订单。现有重复支付输家退款逻辑保持原样，本任务不新建支付角色框架。

### 3.2 Service stage

共享解析器只输出：

```text
NOT_STARTED
IN_PROGRESS
COMPLETED
UNKNOWN
```

基于现有 order/delivery/receipt 字段精确映射；非法组合返回 UNKNOWN 并建议拒绝。

### 3.3 Risk service

实际共享入口保持最小化：

```text
handleInquiry(payOrderId, payload)
lockOrder(order, source)
markRefunded(order)
isLocked(order)
serviceStage(order)
claimProvideGoodsDispatchIfAllowed(orderId, tradeId, source)
```

本地申请和商家服务动作分别在各自事务内调用 `lockOrder` / `isLocked`；没有 `unlock()`。

## 4. Inquiry Transaction

认证完成后（入口签名/解密已由调用方完成）：

```text
BEGIN
  SELECT final order FOR UPDATE
  SELECT relevant order_refund rows FOR UPDATE
  SELECT final payment_trade FOR UPDATE

  validate final-trade binding
  resolve service stage and current audit status

  if valid final iOS order:
      create missing refund tracking when required
      move risk NONE -> LOCKED
      calculate result from current state
  else:
      result = reject

  INSERT one inquiry history row
  update payment snapshot latest inquiry pointer/summary
COMMIT

return official response
```

如果允许建议退款的事务无法持久化，回滚并 fail-closed；不能先返回 `0` 再补写风险。

### Decision rules

- NOT_STARTED：无退款单时创建 REVIEWED 跟踪，返回 0。
- IN_PROGRESS + no refund/WAIT：创建或保留 WAIT，返回 1。
- IN_PROGRESS + REVIEWED：返回 0。
- IN_PROGRESS + REJECTED：返回 1。
- COMPLETED：返回 1。
- 合法绑定但服务状态 UNKNOWN：返回 1，并建立不可逆风险冻结；不能据此恢复履约。
- 交易/订单绑定失败：返回 1，仅写 `order_id=0` 的安全审计，不修改候选业务订单。

每次认证回调新增历史行；重复 payload 不重复创建退款单或重复冻结。

## 5. Local Apply and Cancellation

本地 iOS 申请：

```text
BEGIN
  SELECT order FOR UPDATE
  SELECT existing refunds FOR UPDATE
  SELECT final trade FOR UPDATE
  validate final iOS order and service stage
  reject LOCKED/REFUNDED or duplicate request
  create refund row
  move risk NONE -> LOCKED(source=LOCAL_APPLY)
COMMIT
```

- 未开始：退款记录直接 REVIEWED，随后展示 Apple 教程。
- 已开始未完成：退款记录 WAIT，等待商家审核。
- 已完成：拒绝。
- `can_cancel=false`。
- 不新增取消路由；现有通用取消能力如存在，必须拒绝 iOS。

## 6. Service Actions and Provide Goods

`startService()` 和 `completeService()` 必须在事务中重新加载并锁定订单，再检查：

- 风险必须为 NONE；
- 不存在有效 iOS 退款申请；
- 服务状态仍允许当前动作。

`provide_goods` 使用现有发送/补偿机制，只增加同一个风险守卫：

- 真正发送前重新读取 order risk；
- LOCKED/REFUNDED 跳过发送并记录结构化日志；
- 补偿扫描也执行相同检查。

不新增新 worker、lease、heartbeat 或调度状态机。

## 7. Merchant Audit

审核事务使用相同锁顺序：order -> refund -> trade。

- REVIEWED：iOS 只保存审核结果，不调用开发者退款 API。
- REJECTED：保存原因，不解除 LOCKED。
- 已存在 result_code=0 的问询后，不允许审核倒退为 REJECTED。
- 非 iOS 分支不变。

## 8. Refund Success

认证成功通知在订单锁事务内：

1. 验证最终 iOS trade/order/store 绑定；
2. trade 单调推进 REFUND；
3. order 关闭/取消并禁止服务履约；
4. risk 单调推进 REFUNDED；
5. 唯一退款单完成；无退款单则补建；
6. 保留原审核字段和 inquiry 历史；
7. 重复通知再次执行得到相同结果。

现有重复支付输家 trade-only 逻辑保持，不因输家通知取消正常订单。

不新增积分、结算、累计消费、优惠券、库存或副作用冲正。

## 9. API Projection

后端统一输出：

```text
ios_refund_risk_status
ios_refund_risk_text
ios_refund_inquiry_received
latest_ios_refund_inquiry
merchant_refund_review_status
refund_display_state
refund_display_state_text
refund_guidance
can_cancel
action_flags.can_start_service
action_flags.can_complete_service
```

列表只需批量加载当前页订单的最新 inquiry 摘要；详情按订单一次查询完整时间线。禁止 accessor/render 逐行查库，但不引入通用 query-count 框架。

## 10. UI Design

### Miniapp

复用现有：

- `pages/refund/apply.vue`
- `pages/refund/detail.vue`
- `pages/order/detail.vue`
- `components/refund/IosAppleRefundGuide.vue`

按后端投影渲染，不根据设备、截图或等待时长推断 Apple 状态。

### Merchant

复用现有订单/售后列表和详情：

- 列表状态块增加标签，不增加列；
- 详情增加风险警告和问询时间线；
- 后端 flags 控制按钮；
- 保持 1280/1366px 操作列布局。

## 11. Backfill

命令先 dry-run，`--apply` 时逐批处理：

- 最终 iOS trade + 任意真实本地服务退款记录 -> LOCKED；
- 最终 iOS trade + 认证问询快照 -> LOCKED，并迁移一条 legacy inquiry；
- 最终 iOS trade + 可信成功通知 -> REFUNDED；
- `ios_refund_required` 单独存在 -> 不变更；
- 绑定冲突 -> 不变更并输出安全日志。

重复 apply 由风险单调状态和 migration_key 保证 changed=0。

## 12. Release

1. additive schema；
2. 发布包含 risk guard、事务锁和 callback 决策的全部后端；
3. reload PHP-FPM/opcache，确认所有进程使用新代码；
4. backfill dry-run，人工核对影响清单；
5. apply，再次 dry-run changed=0/errors=0；
6. 发布商家端并同步 `dist -> public/store`；
7. 发布小程序体验版；
8. 核验问询、审核联动、冻结、退款成功和 UI。

回滚时前端可回滚；后端 LOCKED 守卫和 fail-closed 只能前向修复，不清除已建立风险。

## 13. Rejected Alternatives

- 复用 audit_status 表达冻结；
- 相同 payload 永久复用第一次决定；
- 48小时或截图解锁；
- 只在前端禁用服务按钮；
- 只凭 ios_refund_required 回填；
- 新告警表、metrics、heartbeat、systemd watcher；
- 新 convergence worker、claim/lease；
- 积分、结算、优惠券、库存及副作用账本重构。
