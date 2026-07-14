# iOS App Store 退款审核与服务冻结

## Goal

在不改造 Android、非 Apple 退款、积分、结算、优惠券和库存系统的前提下，优化 iOS 微信虚拟支付服务订单退款流程，消除“用户已申请 Apple 退款，但原订单仍可开始或完成服务”的 P0 资损风险。

本任务只解决四件事：

1. 商家审核结果正确参与每次 Apple 退款问询决策；
2. 本地 iOS 退款申请或真实 Apple 问询建立不可逆服务冻结；
3. 开始/完成服务与退款写入通过订单锁互斥；
4. 小程序和商家后台准确展示审核、Apple 问询、冻结和最终退款结果。

## Confirmed Facts

1. Apple 最终决定是否退款，开发者返回的 `result_code` 只是建议。
2. 只有经过认证的 `xpay_refund_notify` 成功通知能证明 Apple 已退款；没有可靠的 Apple 拒绝通知或单笔拒绝查询接口。
3. 同一问询 payload 可能重试；商家审核状态可能在两次问询之间变化。
4. 当前 `startService()`、`completeService()` 存在事务外检查后事务内更新的 TOCTOU 窗口。
5. 当前支付快照只保存最新问询，不能完整展示多轮问询历史。
6. iOS 服务退款不支持开发者主动退款，商家审核同意后仍由用户前往 Apple 官方渠道申请。

## Product Decisions

### PD-1 不可取消

- iOS 服务订单提交退款申请后不允许取消。
- 小程序不显示取消入口，API 投影 `can_cancel=false`。
- 不增加“Apple 尚未问询时可以取消”等分支。
- 如果当前不存在取消路由，不为本任务新增路由；已知兼容 URL 保持 404/405 且数据不变。
- Android 和非 Apple 流程保持现状。

### PD-2 不可逆服务冻结

订单风险状态：

```text
NONE -> LOCKED -> REFUNDED
```

- `NONE`：没有本地 iOS 退款申请，也没有绑定到该订单的 Apple 问询。
- `LOCKED`：本地 iOS 退款申请已创建，或收到可绑定最终交易/订单的认证 Apple 问询；原订单永久停止履约。
- `REFUNDED`：收到可信退款成功通知。

不提供人工解锁、超时解锁、截图解锁或反向迁移。

### PD-3 商家审核与 Apple 结果独立

- 商家审核 `WAIT / REVIEWED / REJECTED` 只影响下一次给 Apple 的建议。
- 商家驳回不等于 Apple 已拒绝，也不解除 `LOCKED`。
- Apple 最终仍可能覆盖开发者的拒绝建议并退款。

### PD-4 已完成服务

- 小程序不允许已完成服务订单提交退款申请。
- 用户直接向 Apple 申请时，服务端建议拒绝并记录问询。
- Apple 最终仍退款时，只完成本任务范围内的订单/trade/risk/refund 状态收口，不倒退服务历史。

## Functional Requirements

### FR-1 Apple 问询决策矩阵

只有最终支付交易与订单、门店、用户绑定一致时，才允许改变订单风险和退款记录。

| 服务状态 | 商家审核 | 返回 Apple | 本地处理 |
|---|---|---:|---|
| 未开始 | 无记录 | `0` | 创建已同意的退款跟踪并 LOCKED |
| 未开始 | 已有记录 | 按审核状态 | 保持 LOCKED |
| 已开始、未完成 | 无记录/WAIT | `1` | 创建/保留 WAIT 并 LOCKED |
| 已开始、未完成 | REVIEWED | `0` | 保持 LOCKED |
| 已开始、未完成 | REJECTED | `1` | 保持 LOCKED |
| 已完成 | 任意 | `1` | 记录问询并 LOCKED，不倒退服务状态 |
| 交易不存在、非最终交易或绑定冲突 | 未知 | `1` | 不修改其他订单，记录安全日志 |

每次响应必须包含非空 `result_info` 和 `evidence`。

### FR-2 多轮问询

- 每次认证问询都在订单锁内读取最新服务和商家审核状态。
- 相同 payload 不锁死第一次决定。
- WAIT 时第一次返回 `1`，商家改为 REVIEWED 后，后续相同 payload 可返回 `0`。
- 每次问询独立留痕。
- 幂等仅约束业务副作用：不重复创建退款单、不重复冻结、不倒退状态。

### FR-3 本地申请

- 未开始服务：创建退款记录、同事务设置 LOCKED、自动同意并展示 Apple 教程。
- 已开始未完成：创建 WAIT 退款记录、同事务设置 LOCKED、等待商家审核且不展示 Apple 教程。
- 已完成：拒绝提交。
- LOCKED/REFUNDED：不得再次创建本地退款记录。

### FR-4 服务互斥

以下写操作统一使用订单优先锁顺序：

```text
order -> relevant order_refund -> final payment_trade -> inquiry event
```

覆盖：

- 本地退款申请；
- 开始服务；
- 完成服务；
- 商家审核；
- Apple 问询和退款成功通知。

所有授权判断必须在事务和订单锁内重新执行。前端置灰不是安全边界。

### FR-5 最小 provide_goods 防护

- 微信履约通知真正发送前重新读取订单风险。
- `LOCKED/REFUNDED` 不允许新发送 `provide_goods`。
- 现有 provide-goods 补偿任务执行同一风险检查。
- 不新增 convergence worker、claim lease、heartbeat 或独立调度系统。

### FR-6 商家审核

- WAIT -> REVIEWED：iOS 不调用开发者主动退款 API，只更新审核状态和用户引导。
- WAIT -> REJECTED：保存拒绝原因，不解除 LOCKED。
- 已存在建议退款的 Apple 问询后，不允许审核结果倒退为拒绝。
- 非 iOS 路径保持现状。

### FR-7 退款成功

可信 `xpay_refund_notify` 成功后：

- 最终交易推进为 REFUND；
- 订单关闭/取消，服务履约入口失效；
- 风险状态推进为 REFUNDED；
- 唯一退款记录完成；没有记录时补建；
- 重复通知幂等；
- 保留原商家审核和问询历史。

不新增或修改积分、订单结算、累计消费、优惠券、库存或副作用账本。

### FR-8 用户端状态

优先级：

```text
refunded
merchant_review_waiting
merchant_rejected
merchant_approved_apply_required
merchant_approved_reapply_required
waiting_app_store_refund
local_refund_submitted
auto_app_store_guided
```

关键文案：

- WAIT：`退款申请已提交，等待商家审核中，请耐心等候`
- REVIEWED 且无历史拒绝问询：`商家已同意退款，请前往 App Store 申请退款`
- REVIEWED 且此前问询建议拒绝：`商家已同意退款，请重新前往 App Store 尝试提交申请`
- REJECTED：`商家已驳回您的退款申请，如有疑问请联系客服`
- 已有问询且 REJECTED：补充“商家驳回不代表 Apple 已拒绝，原订单已停止履约”
- 建议退款问询已到达：`等待 App Store 退款处理`
- 退款成功：`已退款`

按钮继续使用“退款”“提交退款申请”；iOS 隐藏上传凭证。

### FR-9 商家后台

- 不新增菜单、消息中心或订单列表列。
- 订单列表在现有状态区域显示 Apple 退款/审核/冻结标签。
- 订单详情显示风险警告、首次/最近问询、原因、审核结果和每次建议。
- 售后列表/详情显示 App Store 来源和问询时间线。
- 后端 action flags 和接口检查控制开始/完成服务。

## Historical Backfill

新增：

```bash
php think virtual-payment:ios-refund-risk-backfill
php think virtual-payment:ios-refund-risk-backfill --apply
```

只处理最终交易确认是 iOS Apple 的订单，并认可：

1. 真实本地服务退款记录；
2. 认证 handler 写入的 Apple 问询快照；
3. 可信退款成功通知。

不得仅凭 `ios_refund_required` 判断 Apple 已问询。

- 历史本地退款或认证问询：LOCKED；
- 可信退款成功：REFUNDED；
- 已完成服务只记录风险，不倒退服务历史；
- 命令必须 dry-run、apply、再次 dry-run changed=0。

## Acceptance Criteria

- [x] 决策矩阵全部自动化覆盖（48 个服务×审核×风险组合，Mock Apple 回调矩阵测试）。
- [x] 本地申请与 LOCKED 同事务，失败整体回滚。
- [x] start/complete 与本地申请/问询必须按订单锁线性化：风险冻结先提交则服务动作失败；start 先提交时后续申请/问询按“已开始”处理，冻结提交后不得再推进服务。
- [x] 相同 payload 在审核变化后重新计算，但不重复创建退款单。
- [x] iOS 不可取消、不可重复申请；Android/非 Apple apply/reject/reapply 回归通过。
- [x] LOCKED/REFUNDED 时新 provide_goods 和现有补偿发送均被阻止。
- [x] 退款成功推进订单/trade/risk/refund，重复通知幂等。
- [x] 小程序和商家后台状态、文案、按钮准确。
- [x] 历史回填 dry-run/apply/dry-run 幂等。
- [x] PHP、商家端、小程序构建和核心回归通过。
- [ ] 商家 dist 同步到 `yoshop2.0/public/store` 并验证实际 HTTP bundle。
- [x] 按 `task.json.relatedFiles` 白名单审计，本任务 diff 不包含积分、结算、累计消费、优惠券、库存和新监控基础设施代码。

## Out of Scope

- 积分、订单结算、累计消费、优惠券、库存及其冲正；
- 新告警表、metrics、heartbeat、systemd watcher；
- 新 convergence worker、claim token/lease、任务队列重构；
- 人工或超时解除冻结；
- Apple 主动退款 API、线下补偿、发券；
- Android/非 Apple 退款重构；
- 新菜单、消息中心或退款取消路由；
- 保证 Apple 允许再次申请或一定批准退款。

## Open Questions

无。精简方案已确认并完成开发验证；仅商家实际目录同步、HTTP 产物切换和外部真实 Apple/微信验收保留为发布门禁。
