# Implementation Plan — iOS App Store 退款审核与服务冻结（精简版）

## Phase 0 — Baseline and Scope Guard

- [x] 保存当前工作树清单，禁止 reset/覆盖其他任务改动。
- [x] 记录当前订单风险字段、问询快照、退款成功行为和真实 iOS 样本基线。
- [x] 建立可失败回归：固定建议退款、事务外服务检查、重复问询覆盖历史。
- [x] 增加范围守卫：本任务不得修改积分、结算、累计消费、优惠券、库存、systemd 或新监控基础设施。

## Phase 1 — Schema and Minimal Models

- [x] 新增 risk status/source 枚举。
- [x] 新增 order risk 三字段 migration 和 fresh-install schema。
- [x] 新增简单 `payment_ios_refund_inquiry` 表、模型和索引。
- [x] 增加 nullable unique migration_key，仅用于历史问询迁移幂等。
- [x] 新增 backfill dry-run/apply 命令并注册 `config/console.php`。
- [x] 验证 additive migration 和旧代码兼容。

Validation:

```bash
php -l <new enum/model/command files>
php think list | grep virtual-payment:ios-refund-risk-backfill
php think virtual-payment:ios-refund-risk-backfill
```

## Phase 2 — Inquiry Decision

- [x] 抽取最小 inquiry normalizer/fingerprint 和服务阶段解析器。
- [x] 验证 final trade == order.trade_id 及 order/store/user/channel 绑定。
- [x] 实现订单优先事务锁。
- [x] 实现 NOT_STARTED / IN_PROGRESS / COMPLETED / UNKNOWN 决策矩阵。
- [x] 无退款单时按服务阶段创建 REVIEWED 或 WAIT 跟踪。
- [x] 每次认证问询独立插入历史；相同 payload 重新读取最新审核状态。
- [x] 绑定失败、非法状态和异常统一 fail-closed，不泄露异常。
- [x] 允许建议退款前必须先持久化退款跟踪、LOCKED 和 inquiry。
- [x] `Notify.php` 只保留认证、协议适配和官方响应 shape。

Validation:

```bash
php -l app/api/service/Notify.php
php scripts/tests/ios-refund-review-lock-contract.php
```

## Phase 3 — Local Apply, Audit and Service Locks

- [x] 本地 iOS 申请在同一事务创建 refund + NONE->LOCKED。
- [x] 未开始自动 REVIEWED；已开始 WAIT；已完成拒绝。
- [x] LOCKED/REFUNDED 或已有退款记录时拒绝重复申请。
- [x] iOS `can_cancel=false`；不新增取消路由；现有通用取消入口如存在则拒绝。
- [x] 商家 REVIEWED 不调用开发者退款 API；REJECTED 不解除 LOCKED。
- [x] `startService()` 事务内锁订单并重查风险/退款/服务状态。
- [x] `completeService()` 事务内锁订单并重查风险/退款/服务状态。
- [x] 现有 provide_goods 发送和补偿扫描增加 LOCKED/REFUNDED 守卫。
- [x] 双连接测试覆盖 apply/inquiry vs start/complete/audit。

## Phase 4 — Refund Success

- [x] 认证成功通知验证最终 iOS trade/order/store 绑定。
- [x] 同一订单锁事务推进 order cancelled、service disabled、trade REFUND、risk REFUNDED。
- [x] 唯一退款单完成；无退款单补建；保留审核和 inquiry 历史。
- [x] 重复/乱序通知幂等。
- [x] 保持现有重复支付输家 trade-only 行为，不污染正常订单。
- [x] 明确不新增积分、结算、累计消费、优惠券、库存或副作用账本代码。

## Phase 5 — Shared Projections

- [x] 后端统一输出 risk、latest inquiry、audit、display state、guidance、can_cancel 和 action flags。
- [x] REVIEWED+无历史拒绝 -> 首次 Apple 申请文案。
- [x] REVIEWED+最新拒绝 -> 重新尝试文案。
- [x] 最新 result=0 -> 等待 App Store。
- [x] REJECTED+已有问询 -> 商家驳回不等于 Apple 拒绝。
- [x] 列表批量加载最新摘要；详情一次加载时间线，避免逐行查询。
- [x] 旧客户端忽略新字段仍可运行。

## Phase 6 — Miniapp

Owned files:

- `yoshop2.0-uniapp/pages/refund/apply.vue`
- `yoshop2.0-uniapp/pages/refund/detail.vue`
- `yoshop2.0-uniapp/pages/order/detail.vue`
- `yoshop2.0-uniapp/components/refund/IosAppleRefundGuide.vue`

- [x] WAIT 隐藏 Apple 教程。
- [x] REVIEWED 显示首次/重新尝试。
- [x] REJECTED 显示驳回；已有问询时补充风险说明。
- [x] result=0 显示等待 App Store；REFUNDED 显示已退款。
- [x] 所有 iOS 退款状态不显示取消入口。
- [x] 保持“退款”“提交退款申请”和隐藏上传凭证。
- [x] API 失败不残留 spinner，不根据 truthy 字符串推断状态。

Validation:

```bash
npm run build:h5
npm run build:mp-weixin
```

## Phase 7 — Merchant Console

Owned files:

- `yoshop2.0-store/src/views/order/Index.vue`
- `yoshop2.0-store/src/views/order/Detail.vue`
- `yoshop2.0-store/src/views/order/refund/Index.vue`
- `yoshop2.0-store/src/views/order/refund/Detail.vue`
- focused Jest specs

- [x] 现有状态块增加 Apple 审核/冻结标签，不新增列。
- [x] 订单详情增加风险警告和 inquiry 时间线。
- [x] 售后列表/详情显示 App Store 来源、审核和时间线。
- [x] action flags 控制开始/完成和审核按钮。
- [ ] 1280/1366px、100%缩放下操作列完整。

Validation and deployment:

```bash
npm run lint -- <changed files>
npm run test:unit -- --runInBand
NODE_OPTIONS=--openssl-legacy-provider ./node_modules/.bin/vue-cli-service build
rsync -a --delete dist/ ../yoshop2.0/public/store/
nginx -t
systemctl reload nginx
# verify actual local/public HTTP index and bundle marker
```

## Phase 8 — Backfill and Full Verification

- [x] dry-run 输出本地退款、认证问询、成功通知、冲突清单。
- [x] 人工核对后 apply，再 dry-run changed=0/errors=0。
- [x] 执行 `risk-test-matrix.md` 约45项核心场景，并补充 48 个服务×审核×风险 Mock Apple 状态组合。
- [x] 执行 non-iOS 服务退款 apply/reject/reapply 回归，确认不进入 Apple 风险状态机。
- [x] PHP lint、后端 contract/concurrency tests、商家 Jest/lint/build、小程序 build 全部通过。
- [x] 一次单子代理代码 Review；只修证实的 P0/P1，不再重开 Plan Review。
- [x] 缺陷修复后完整复测相关核心矩阵。
- [x] `git diff --check`、Trellis validate 通过。
- [x] diff 范围检查确认没有积分/结算/告警基础设施代码。

Mandatory commands:

```bash
php_files=(
  app/api/model/Order.php app/api/model/OrderRefund.php app/api/model/PaymentTrade.php app/api/service/Notify.php
  app/common/command/VirtualPaymentIosRefundRiskBackfill.php
  app/common/enum/order/iosRefund/RiskSource.php app/common/enum/order/iosRefund/RiskStatus.php
  app/common/model/OrderRefund.php app/common/model/PaymentIosRefundInquiry.php app/common/model/PaymentTrade.php
  app/common/service/order/IosRefundRisk.php app/common/service/order/Refund.php
  app/store/model/Order.php app/store/model/OrderRefund.php app/timer/service/Order.php
  scripts/tests/ios-payment-channel-contract.php scripts/tests/ios-refund-review-lock-contract.php
  scripts/tests/ios-refund-concurrency-contract.php scripts/tests/ios-refund-state-matrix-contract.php
  scripts/tests/non-ios-service-refund-regression-contract.php
)
for file in "${php_files[@]}"; do php -l "$file"; done
php scripts/tests/ios-payment-channel-contract.php
php scripts/tests/non-ios-service-refund-regression-contract.php
php scripts/tests/ios-refund-review-lock-contract.php
IOS_REFUND_MATRIX_TEST=1 php scripts/tests/ios-refund-state-matrix-contract.php
IOS_REFUND_CONCURRENCY_TEST=1 php scripts/tests/ios-refund-concurrency-contract.php
php think virtual-payment:ios-refund-risk-backfill
php think virtual-payment:ios-refund-risk-backfill --apply
php think virtual-payment:ios-refund-risk-backfill
(cd ../yoshop2.0-store && npm run test:unit -- --runInBand)
(cd ../yoshop2.0-uniapp && node scripts/tests/ios-refund-detail-error-contract.cjs)
(cd ../yoshop2.0-uniapp && npm run build:h5)
(cd ../yoshop2.0-uniapp && npm run build:mp-weixin)
git diff --check
python3 .trellis/scripts/task.py validate .trellis/tasks/07-13-ios-refund-review-service-lock
```

## Phase 9 — Acceptance and Release Preparation

- [x] 未开始服务申请、已开始 WAIT、REVIEWED、REJECTED、重复问询和 REFUNDED 业务验收。
- [x] 冻结后 start/complete/provide_goods 均被后端拒绝。
- [x] 商家 `dist -> temporary staging` checksum 同步、hash 和 bundle marker 验证。
- [ ] 商家实际 `public/store` 和公网 bundle 验证。
- [ ] 小程序体验版真机验收。
- [x] schema/index、backfill dry-run、商家 staging 发布顺序演练。
- [ ] backend guards -> PHP-FPM/opcache reload -> actual frontends 顺序执行。
- [ ] 输出上线结果、回滚点和未解决的真实 Apple 外部验证项。

## Stop Conditions

不得宣称完成，除非：

- 核心 AC 有直接证据；
- 代码 Review P0/P1=0；
- 缺陷已复测；
- 实际服务目录和小程序产物已验证；
- 没有超出 iOS 退款流程的代码改动。
