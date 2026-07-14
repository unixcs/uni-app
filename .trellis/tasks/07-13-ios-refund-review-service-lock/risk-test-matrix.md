# Murphy Risk and Test Matrix — Minimal iOS Refund Scope

| ID | 场景 | 预期 | 级别 |
|---|---|---|---|
| R01 | 未开始 iOS 订单提交本地退款 | 创建 REVIEWED refund、同事务 LOCKED、显示 Apple 教程 | P0 |
| R02 | 已开始未完成提交本地退款 | 创建 WAIT、同事务 LOCKED、隐藏 Apple 教程 | P0 |
| R03 | 已完成服务提交本地退款 | 后端拒绝且无新退款记录 | P0 |
| R04 | 本地申请写 refund 后 risk 更新失败 | 整个事务回滚 | P0 |
| R05 | 同一订单重复提交本地退款 | 不新增第二条退款记录 | P1 |
| R06 | LOCKED/REFUNDED 再次提交 | 后端拒绝，历史不覆盖 | P0 |
| R07 | iOS 页面取消入口 | 不显示，can_cancel=false | P1 |
| R08 | 直接访问取消 URL/通用取消入口 | 404/405或业务拒绝，数据不变 | P0 |
| R09 | Android 服务退款 | 原流程和文案不变 | P0 |
| R10 | 非 Apple 支付退款 | 原开发者退款逻辑不变 | P0 |
| R11 | 未开始订单首次 Apple 问询 | result=0、自动退款跟踪、LOCKED | P0 |
| R12 | 已开始无退款单首次问询 | 创建 WAIT、result=1、LOCKED | P0 |
| R13 | 已开始 WAIT 问询 | result=1、保持 LOCKED | P0 |
| R14 | 已开始 REVIEWED 问询 | result=0、保持 LOCKED | P0 |
| R15 | 已开始 REJECTED 问询 | result=1、保持 LOCKED | P0 |
| R16 | 已完成服务问询 | result=1、记录历史和风险，不倒退服务状态 | P0 |
| R17 | 交易不存在 | result=1，不修改任何订单 | P0 |
| R18 | 非最终交易/跨店/用户绑定冲突 | result=1，不修改其他订单 | P0 |
| R19 | 回调签名无效 | 不写业务历史、不写 risk | P0 |
| R20 | 认证后业务异常 | fail-closed，不泄露内部异常 | P0 |
| R21 | 相同 payload 重复、审核不变 | 决策一致，一条 refund、多条 inquiry | P1 |
| R22 | 第一次 WAIT 后商家改 REVIEWED | 后续相同 payload 重算为 result=0 | P0 |
| R23 | 连续三次问询 | 三条历史，不重复冻结/建单 | P1 |
| R24 | result=0 前 inquiry/risk 持久化失败 | 回滚并返回安全拒绝 | P0 |
| R25 | 本地申请与 startService 并发 | 订单锁线性化：申请先提交则 start 失败；start 先提交则申请按已开始 WAIT 并冻结，冻结后不再推进服务 | P0 |
| R26 | 本地申请与 completeService 并发 | 订单锁线性化，不能双方成功 | P0 |
| R27 | Apple 问询与 startService 并发 | 先服务则按新阶段决策；先问询则 start 被拒绝 | P0 |
| R28 | Apple 问询与 completeService 并发 | 订单锁线性化，LOCKED 后不能完成 | P0 |
| R29 | 商家审核与问询并发 | 问询读取线性化后的最新 audit | P0 |
| R30 | risk=LOCKED 调 startService | 后端拒绝，状态不变 | P0 |
| R31 | risk=LOCKED 调 completeService | 后端拒绝，状态不变 | P0 |
| R32 | complete 后准备发送 provide_goods 时被 LOCKED | 发送前守卫阻止新发送 | P0 |
| R33 | provide_goods 补偿扫描 LOCKED/REFUNDED | 跳过发送并记录结构化日志 | P0 |
| R34 | 服务端曾建议拒绝但 Apple 最终退款 | order/trade/risk/refund 正确收口，历史保留 | P0 |
| R35 | 退款成功早于本地退款单 | 补建并完成，risk=REFUNDED | P0 |
| R36 | 重复退款成功通知 | 状态幂等，不重复建单 | P0 |
| R37 | 退款成功时已有唯一退款单 | 完成该记录，保留审核历史 | P1 |
| R38 | 退款成功交易不是订单最终交易 | 保持现有 trade-only 行为，不取消正常订单 | P0 |
| R39 | 历史最终 iOS 订单有本地退款记录 | backfill LOCKED | P0 |
| R40 | 历史最终 iOS 订单有认证问询 | backfill LOCKED 并迁移一条 inquiry | P0 |
| R41 | 历史可信成功通知 | backfill REFUNDED | P0 |
| R42 | 仅 ios_refund_required 标识 | 不作为问询证据，不变更 | P0 |
| R43 | backfill 重复 apply | 第二次 changed=0/errors=0 | P1 |
| R44 | 小程序/商家投影字段缺失或 API 失败 | 不误放行、不永久 spinner、显示安全错误 | P1 |
| R45 | 商家构建未同步实际 Nginx 目录 | 发布门禁通过 HTTP bundle marker 发现 | P1 |

## Scope Regression

以下任一出现即为范围缺陷：

- 新增或修改积分、结算、累计消费、优惠券、库存逻辑；
- 新增 alert/metrics/heartbeat/systemd watcher；
- 新增 convergence worker、claim token、lease 或通用任务队列；
- 新增退款取消路由、独立菜单或消息中心。
