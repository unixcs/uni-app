# 方案评审记录

## 第一轮子代理评审

结论：两位评审均阻塞进入开发；核心方向认可，但要求补齐安全与可执行性。

## 已采纳修订

1. 增加已支付 `wechat_virtual + UNKNOWN` 的有界异步补偿。
2. 分类推进收口到 `PaymentTrade` 加锁快照写路径，定义单向升级和冲突优先级。
3. 渠道筛选增加交易状态、订单归属、商家归属一致性；退款状态仍保留原渠道分类。
4. `channel_class` 只作筛选投影/正向证据，不替代现有退款安全识别；UNKNOWN 禁止开发者退款。
5. 历史回填改为分批 dry-run/可恢复命令；空 platform/坏快照默认 unknown。
6. 修正 fresh-install schema 漂移；索引由最终 SQL 的 EXPLAIN 决定。
7. 本地退款申请加入订单行锁，统一 `order -> order_refund -> payment_trade` 锁顺序。
8. 状态改为单调优先级：最终成功 > 已认证 inquiry > 本地活动申请 > 本地取消/拒绝 > 尚未提交。
9. 排序增加 `order_id DESC`，补齐两笔成功 winner/loser 和稳定分页测试。
10. 上线增加第二次增量回填，关闭首次回填到后端发布之间的数据窗口。
11. sticky 降为实测后的可选增强，首期优先最小宽度、明确列宽和横向滚动。
12. iOS payload 清空/省略 images；非 iOS 凭证持久化既有缺陷明确为任务外。
13. 修正 Linux 构建、scoped lint、专用测试库 E2E 和截图/DOM 几何证据要求。

## 待第二轮评审

- 上述修订是否已解除 P0/P1 阻塞；
- 是否仍存在超出本任务必要范围的复杂度；
- 计划是否可以交由用户批准后进入开发。

## 第二轮状态

已将第一轮全部 P0/P1 建议落实到规划文档，并发起原子代理复审。复审调用因代理服务额度限制返回 403，未获得第二份有效 verdict；主代理随后完成本地逐项对照，确认以下阻塞均已在设计/实施计划中显式闭环：

- 已支付 UNKNOWN 补偿；
- winner 交易一致性与稳定分页；
- channel_class 不替代退款安全证据；
- 分类原子单向推进；
- 分批回填、二次增量回填和 fresh-install schema；
- 本地申请并发锁与乱序状态优先级；
- iOS images 清理、Linux 构建、scoped lint、专用测试库 E2E；
- 100% 缩放截图和 DOM 几何验收。

当前结论：规划材料已收敛，可提交用户评审；仍不得在用户明确授权前执行 `task.py start` 或编码。

## 2026-07-12 开发后评审与缺陷闭环

首轮代码评审发现并已修复：

| 编号 | 级别 | 缺陷 | 修复 | 复测证据 | 状态 |
|---|---|---|---|---|---|
| CR-P0-01 | P0 | 退款服务未把持久化 IOS_APPLE 作为正向证据 | 退款服务统一调用 `PaymentTrade::isIosAppleVirtualTrade()` | `scripts/tests/ios-payment-channel-contract.php` 的空快照 IOS_APPLE 用例 | 已关闭 |
| CR-P1-01 | P1 | inquiry 被本地取消/拒绝覆盖 | 调整为 completed > inquiry > active > cancelled/rejected | 合同脚本状态矩阵通过 | 已关闭 |
| CR-P1-02 | P1 | 重复 record 可能把 IOS_APPLE 降级并覆盖快照 | 复用现有分类并递归合并快照 | 单调分类合同用例通过；PHP lint 通过 | 已关闭 |
| CR-P1-03 | P1 | 查单成功但证据不足不退避 | 分类仍 UNKNOWN 时写 `evidence_pending` 并指数退避 | 代码复核、PHP lint | 已关闭 |
| UI-P1-01 | P1 | 已退款仍展示继续 Apple 申请指南 | 仅 `display_state=local_refund_submitted` 展示提交后指南 | H5 构建、状态投影合同用例 | 已关闭 |
| UI-P2-01 | P2 | last-child 样式误命中 colspan 行 | 操作表头/单元格改用 `.operation-column` | ESLint、生产构建 | 已关闭 |

新增验证：商家 Jest 4 suites / 27 tests 通过；uniapp H5 构建通过；真实订单 `334098380149377916` 分类为 IOS_APPLE；数据库筛选 SQL 命中 order 10513。

### 修复后复评补充

复评发现 2 个 P1：取消/拒绝记录被误作活动申请、`PaymentTrade::record()` 与通知并发时仍可能旧读覆盖。现已分别通过活动状态显式判断，以及把交易锁定、快照合并、单调分类、保存纳入同一数据库事务修复；合同测试新增取消/拒绝用例。

最终定向复测发现行锁重构遗漏既有完成态保护，可能把 SUCCESS/REFUND 重置为 UNPAID。已在行锁事务内恢复完成态不可逆检查。

## 微信小程序目标构建闭环

根因：HBuilderX 5.15 的 `launch mp-weixin --project` 对已导入项目接受项目名，脚本传 Windows 绝对路径时返回“命令不存在或缺少参数”。构建脚本改为使用 `projectName=yoshop2.0-uniapp`，保留 open 的绝对路径。`npm run build:mp-weixin` 已成功，产物位于 Windows 镜像 `unpackage/dist/dev/mp-weixin`；已核验 apply/detail 页面及 `IosAppleRefundGuide` 的 js/wxml/wxss/json 均生成，指南和按钮文案进入 WXML/JS。

## 2026-07-12 线上缺陷：非 iOS 订单 UNKNOWN

- 症状：用户提交退款提示“支付渠道尚在确认中”；后台“非 iOS订单”为空。
- 共同根因：微信查单明确返回 `order_type=0`，分类代码却用 `> 0` 过滤，错误丢弃合法非 iOS 证据。
- 修复：所有明确存在的非 7 整数（含 0）分类为 NON_IOS；缺失值仍保持 UNKNOWN；新增 `order_type=0` 合同测试。
- 数据闭环：回填扫描 139 笔，96 笔更新为 NON_IOS，41 笔保持 UNKNOWN，2 笔 IOS_APPLE。最新订单 10517/交易 10338 已由 UNKNOWN 更新为 NON_IOS。
- 防复发：backend virtual-payment contract 禁止对枚举使用 truthy/`>0` 判断。

## 2026-07-12 商家静态资源未发布复盘

- 分类：Change Propagation Failure + Missing Deployment Contract。
- 根因：只构建 `yoshop2.0-store/dist`，未同步到 Nginx root 下的 `yoshop2.0/public/store`。
- 防复发：frontend quality spec 新增构建→rsync→nginx reload→HTTP bundle marker 的强制发布契约。
