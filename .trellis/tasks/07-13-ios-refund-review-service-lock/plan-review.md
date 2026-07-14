# Plan Review — iOS App Store 退款审核与服务冻结（校准版）

评审状态：主代理最小范围复核完成，等待用户最终编码授权。

## Review Baseline

以用户提供的最初方案为基线，只检查 iOS 退款决策、不可取消、服务冻结、并发、展示和历史回填，不扩展到其他系统。

## Confirmed Core

- 服务状态、商家审核、Apple inquiry 历史和风险状态正交。
- 风险状态单调 `NONE -> LOCKED -> REFUNDED`，无解锁。
- 每次认证问询按最新审核事实重新决策；相同 payload 不固定第一次结果。
- start/complete 与退款申请/问询使用订单锁互斥。
- iOS 不可取消，Android/非 Apple 保持现状。
- 商家后台不新增列或菜单，小程序保留现有按钮。
- backfill 不使用泛化 `ios_refund_required` 作为问询证据。

## Obvious Errors Corrected

### P0-1 — 本地申请到真实问询之间存在履约窗口

旧摘要把 LOCKED 主要描述为“收到真实 Apple 问询”。但本地申请后会立即引导用户前往 Apple，如果等待问询才冻结，商家仍可能先开始服务。

修正：本地 iOS refund 创建与 `NONE -> LOCKED(source=LOCAL_APPLY)` 同一事务。

### P0-2 — 历史回填遗漏本地退款记录

采用本地申请立即 LOCKED 后，历史本地 iOS 服务退款也必须纳入 dry-run/backfill；否则旧申请仍能履约。

修正：仅在最终交易确认为 iOS Apple 时，真实本地服务退款记录可作为 LOCKED 证据；仍禁止仅凭 `ios_refund_required` 回填。

### P1-1 — 不应为了“拒绝取消”新增取消路由

修正：`can_cancel=false`；现有通用取消入口如存在则拒绝；没有路由时保持 404/405，不新增 API。

### P1-2 — provide_goods 需要最小风险守卫，但不需要新调度系统

修正：现有发送和补偿任务在真正发送前检查 LOCKED/REFUNDED；不新增 convergence worker、claim/lease、heartbeat 或 systemd。

## Scope Drift Removed

已从活动规划中删除：

- 积分、结算、累计消费、优惠券、库存及副作用账本；
- 独立 alert 表、metrics、heartbeat、systemd watcher；
- 新 convergence worker、claim token/lease、dispatch_unknown；
- 通用支付角色框架和扩展运营队列；
- 1/10/20 query-count 专项框架；
- 反复 Plan Review 子代理循环。

## Verdict

- 核心方案 P0：0（上述两个必要修正已写入）
- 核心方案 P1：0（取消路由和最小 provide_goods 边界已明确）
- 风险矩阵：45 项
- 状态：planning
- 代码：未开始

用户确认后直接进入编码；开发完成后只做一次代码 Review 和缺陷复测，不再重开方案评审循环。

## Execute-phase clarification (2026-07-14)

双连接测试发现原 PRD/R25 的“start 与本地申请不能双方成功”表述过强：已开始、未完成的订单本来就允许提交 WAIT 退款。因此正确不变量是订单锁线性化和“冻结提交后不得再推进服务”：申请先提交则 start 失败；start 先提交则申请读取已开始状态、创建 WAIT 并冻结。该修正不增加功能，仅消除验收标准与既有业务矩阵的矛盾。
