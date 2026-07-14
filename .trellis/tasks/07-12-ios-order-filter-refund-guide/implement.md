# iOS订单筛选退款指引与后台布局优化 — 实施计划

> 当前仅规划。未经用户明确授权，不执行编码、迁移、构建或发布。

## 1. Delivery Gates

- [x] 需求澄清与代码证据检查
- [x] 真实订单 `334098380149377916` 数据核验
- [ ] 子代理方案评审通过
- [ ] 用户批准方案并授权开发
- [ ] 编码开发
- [ ] 开发自测与子代理边界审查
- [ ] 主代理代码评审
- [ ] 功能测试与缺陷 100% 复测
- [ ] 业务验收
- [ ] 灰度上线
- [ ] 线上全链路校验

## 2. Pre-development Safety

1. 运行 `trellis-before-dev`，重新加载 frontend/backend 规范。
2. 记录并保护当前约 40 项未提交改动；只增量编辑，不重置、不覆盖、不格式化无关文件。
3. 对每个目标文件先查看 `git diff`，区分 HEAD 与现有工作树。
4. 数据库迁移必须先在备份/测试环境执行；生产默认 dry-run 统计后再回填。
5. 确认当前任务仍处于 planning；用户授权后才执行 `task.py start`。

## 3. Implementation Sequence

### Phase A — Payment channel projection

- [ ] 新增后端渠道分类枚举：`UNKNOWN/NON_IOS/IOS_APPLE`。
- [ ] 新增 `payment_trade.channel_class` 数据库变更记录；索引在最终 SQL 上执行 `EXPLAIN` 后决定，不预设无效组合索引。
- [ ] 同步修正 fresh-install `install.sql` 的完整 `payment_trade` 结构，验证新装/升级 schema 一致。
- [ ] 在交易模型的加锁写路径集中实现纯分类函数与原子推进：
  - 从合并后的完整快照分类；
  - `UNKNOWN -> NON_IOS/IOS_APPLE`；
  - 强 Apple 证据允许 `NON_IOS -> IOS_APPLE`；
  - `IOS_APPLE` 永不降级；
  - 冲突证据按 iOS 安全路径并记录日志。
- [ ] 普通支付创建时写 `NON_IOS`；虚拟支付初始写 `UNKNOWN`。
- [ ] 主动 `query_order` 成功时持久化分类。
- [ ] 虚拟支付通知携带类型时持久化分类。
- [ ] iOS Apple 退款问询到达时幂等写 `IOS_APPLE`。
- [ ] 主动查单、定时查单、退款查单均通过统一快照合并路径推进分类。
- [ ] 增加“已支付且绑定交易为 UNKNOWN”的有界异步补偿，支持退避、批次上限和可恢复游标。
- [ ] 编写分批回填命令：`--dry-run --from-trade-id --limit --batch-size`；只回填有证据的数据，坏快照/空 platform 默认 unknown。
- [ ] 验证 `334098380149377916` 被分类为 `IOS_APPLE`。

Risky files / rollback points:

- `yoshop2.0/app/common/model/PaymentTrade.php`
- `yoshop2.0/app/api/model/PaymentTrade.php`
- `yoshop2.0/app/api/service/cashier/Payment.php`
- `yoshop2.0/app/api/service/Notify.php`
- `yoshop2.0/app/timer/service/Order.php`
- `yoshop2.0/app/common/service/order/Refund.php`
- 新渠道枚举、分批回填命令、数据库变更记录
- `yoshop2.0/public/install/data/install.sql`

### Phase B — Merchant list filter

- [ ] `Order::normalizeQueryParams()` 增加 allowlisted `paymentChannel`。
- [ ] 在 `paginate()` 前应用渠道过滤。
- [ ] `ios_apple` 只命中已支付、交易归属一致且绑定交易状态为 SUCCESS/REFUND、分类为 iOS Apple 的订单。
- [ ] `non_ios` 只命中一致的已支付余额订单，或交易归属一致且绑定交易状态为 SUCCESS/REFUND、分类为非 iOS 的订单。
- [ ] 未支付/失败/unknown/错绑交易不命中任何渠道值。
- [ ] 非法 `paymentChannel` 统一归一为空值，禁止拼 SQL。
- [ ] 排序改为 `create_time DESC, order_id DESC`，保证分页稳定。
- [ ] `Index.vue` 仅在 `dataType === 'all'` 渲染支付渠道下拉。
- [ ] 搜索、重置、翻页验证参数保持一致。
- [ ] 不增加列表展示列，不调整 `colspan=8`。
- [ ] 订单导出 UI 不增加筛选或列。

Risky files:

- `yoshop2.0/app/store/model/Order.php`
- `yoshop2.0-store/src/views/order/Index.vue`

### Phase C — Refund state evidence correction

- [ ] 修改共享退款投影：
  - 本地记录、无 Apple 问询 → `退款申请已提交，请前往 App Store 申请退款`；
  - Apple 问询已收到 → `等待 App Store 退款处理`；
  - 最终成功 → `已退款`。
- [ ] 本地拒绝/取消文案明确为本地处理结果，不冒充 Apple 决策。
- [ ] 明确单调状态优先级：最终成功 > 已认证 inquiry > 本地活动申请 > 本地取消/拒绝 > 尚未提交。
- [ ] 本地 apply 事务按 `order -> order_refund -> payment_trade` 顺序锁定，避免并发生成重复活动退款单。
- [ ] `channel_class` 仅作筛选投影/正向证据，不替换现有快照、order_type=7、认证 inquiry 和 ios_refund_required 安全识别。
- [ ] 成功虚拟交易仍 UNKNOWN 时禁止开发者退款，先安全失败并进入渠道补偿。
- [ ] 保持 Apple 回调 `suggest_refund`、签名验证、fail-open 响应和最终收口事务不变。
- [ ] 覆盖自动审核、人工审核、乱序通知和并发申请。

Risky files:

- `yoshop2.0/app/common/model/PaymentTrade.php`
- `yoshop2.0/app/api/model/Order.php`
- `yoshop2.0/app/api/model/OrderRefund.php`
- `yoshop2.0/app/store/model/Order.php`
- `yoshop2.0/app/store/model/OrderRefund.php`
- `yoshop2.0/app/common/service/order/Refund.php`
- `yoshop2.0/app/timer/service/Order.php`

### Phase D — Miniapp guide

- [ ] 新增复用的 `IosAppleRefundGuide` 展示组件。
- [ ] `before_submit` 使用用户确认的“先提交本地申请，再去 Apple”教程。
- [ ] `after_submit` 使用适配订单详情的开头，不出现“点击下方”。
- [ ] 订单详情入口按钮保持“退款”。
- [ ] iOS 提交按钮保持“提交退款申请”。
- [ ] iOS 模式隐藏上传凭证，并在提交前显式清空/省略 `images`；非 iOS UI 保持现状，本任务不声称修复其既有持久化缺陷。
- [ ] 提交 payload 和本地退款记录创建链路保持兼容。
- [ ] 订单详情退款反馈卡展示完整 after-submit 指南。

Risky files:

- `yoshop2.0-uniapp/pages/order/detail.vue`
- `yoshop2.0-uniapp/pages/refund/apply.vue`
- 新增 guide component
- `yoshop2.0-uniapp/pages/refund/detail.vue`（兼容审查，保留现有字段消费）

### Phase E — Merchant table layout

- [ ] 固定表宽改为 `width:100% + min-width`，滚动容器改为 `overflow-x:auto`。
- [ ] 为 8 列定义稳定宽度，重点保证操作列。
- [ ] 首期先用明确 min-width、colgroup/列 class、`overflow-x:auto` 修复；仅当 1280/1366px 100% 实测仍不合格时再启用明确 action class 的 sticky right。
- [ ] “详情”链接使用 Ant 默认描边按钮视觉，最小 64×32px。
- [ ] 保持权限和新窗口行为。
- [ ] 验证无权限时操作列不会产生异常空白或错位。

Risky file:

- `yoshop2.0-store/src/views/order/Index.vue`

## 4. Automated Validation

### 4.1 PHP

```bash
php -l yoshop2.0/app/common/model/PaymentTrade.php
php -l yoshop2.0/app/api/model/PaymentTrade.php
php -l yoshop2.0/app/api/service/cashier/Payment.php
php -l yoshop2.0/app/api/service/Notify.php
php -l yoshop2.0/app/store/model/Order.php
php -l yoshop2.0/app/api/model/Order.php
php -l yoshop2.0/app/api/model/OrderRefund.php
# 实际执行时对 git diff 中全部新增/修改 PHP 文件逐一 php -l，包含枚举、timer、Refund service、回填命令。
```

- [ ] `virtual-payment:local-e2e` 仅允许在明确记录库名的专用测试库执行，执行前后 cleanup 并保存 evidence；不得称为只读。
- [ ] 对真实订单只运行 `virtual-payment:sandbox-check` 的只读参数验收：分类为 iOS、已有问询证据、无重复退款记录。
- [ ] 数据库变更前后统计三类数量，核对总量守恒。

### 4.2 Merchant console

新增或扩展 Jest 用例，至少覆盖：

- [ ] 仅 `all` 显示支付渠道控件。
- [ ] iOS/non-iOS 参数正确进入 API。
- [ ] reset 清空渠道。
- [ ] 详情链接仍新窗口打开、按钮 class 和可点击区域存在。
- [ ] 枚举未知值不会使页面渲染崩溃。

命令：

```bash
cd yoshop2.0-store
npm run test:unit -- --runInBand
./node_modules/.bin/eslint --no-fix src/views/order/Index.vue tests/unit/order/Index.spec.js
NODE_OPTIONS=--openssl-legacy-provider ./node_modules/.bin/vue-cli-service build
```

### 4.3 Miniapp

- [ ] iOS before-submit 指南渲染。
- [ ] iOS 上传凭证隐藏、上传 API 未调用、提交 payload 中 `images` 缺失或为空。
- [ ] iOS 入口仍为“退款”，提交仍为“提交退款申请”。
- [ ] 非 iOS 退款页无回归。
- [ ] after-submit 订单详情显示适配指南和正确状态。

命令：

```bash
cd yoshop2.0-uniapp
npm run build:h5
npm run build:mp-weixin
```

## 5. Murphy Test Matrix

### Channel classification

- [ ] 真实 iOS 成功交易 `334098380149377916`。
- [ ] Android/非 Apple 虚拟支付成功交易。
- [ ] 普通微信、支付宝、余额成功订单。
- [ ] 未支付订单。
- [ ] 支付失败订单。
- [ ] 虚拟支付成功但上游类型缺失。
- [ ] 多次支付尝试：失败后成功、不同渠道重试、两笔都成功、winner/loser 迟到回调。
- [ ] `order.trade_id` 缺失或指向不存在交易。
- [ ] malformed/empty `payload_snapshot`。
- [ ] 重复支付通知、重复查单、重复 iOS 问询。
- [ ] 支付通知先收口订单但无 order_type，已支付 UNKNOWN 补偿最终分类。
- [ ] 回填运行期间在线写入强 Apple 证据，回填不得降级。
- [ ] 已确认 IOS_APPLE 后收到无类型旧通知，分类不得降级。

### Filtering

- [ ] iOS/non-iOS 与关键词、时间、订单来源、支付方式组合 AND 查询。
- [ ] 25 条相同 create_time 的记录按 order_id 次序稳定分页，页 1/2/3 无重复遗漏。
- [ ] 非法 `paymentChannel` 参数。
- [ ] 其他订单标签页不带渠道参数。
- [ ] unknown 只出现在全部。
- [ ] 空数据和最后一页。

### Refund guide/state

- [ ] iOS 未提交本地申请。
- [ ] 本地已提交但 Apple 尚未问询。
- [ ] Apple 已问询。
- [ ] Apple 最终退款成功。
- [ ] 本地退款取消/拒绝。
- [ ] Apple inquiry 先于本地申请；完成后收到旧 inquiry；本地拒绝/取消后收到 Apple 成功。
- [ ] 两个并发本地申请；本地申请与 Apple 自动建档/成功通知并发；最终活动退款单最多一条。
- [ ] 服务前自动审核和服务中人工审核。
- [ ] 提交接口失败、重复点击、网络超时。
- [ ] 非 iOS 页面不显示教程且仍可上传凭证。

### Layout

- [ ] Chrome 100%：1280×720、1366×768、1440×900、1920×1080。
- [ ] 浏览器 90%/110% 作为非基准健壮性检查。
- [ ] 长订单号、长昵称、长备注、长联系方式。
- [ ] 有/无详情权限。
- [ ] 触控板横向滚动、鼠标拖动滚动条。
- [ ] 若启用 sticky，表头/内容不透叠、不抖动、不误命中 colspan 行。
- [ ] 每个视口保存截图与 DOM 几何：scrollWidth/clientWidth、操作列边界、按钮宽高、zoom、devicePixelRatio。

## 6. Review Gates

### Plan review

- 子代理检查：分类语义、迁移安全、退款状态证据、表格方案是否过度设计。

### Development self-check

- 子代理只读审查代码逻辑、并发/幂等、边界用例、前后端字段一致性。

### Code review

主代理逐文件检查：

- 是否改动了无关未提交代码；
- 是否存在 JSON/SQL 注入或非法参数；
- 是否有列表 N+1；
- 是否错误把 unknown 当 non-ios；
- 是否改变 Apple 回调协议；
- 是否将“本地登记”误写成“Apple 处理中”；
- 是否引入重复文案或重复组件逻辑。

## 7. Business Acceptance

业务验收脚本：

1. 商家后台“全部订单”选择 iOS，找到真实订单对应商城订单 `334098259006387503`；
2. 选择非 iOS，不出现该订单；
3. 清空渠道，未支付和 unknown 订单仍可见；
4. iOS 用户点击“退款”，按钮和页面文案正确；
5. 提交前看到完整教程且无上传凭证；
6. 提交本地申请后订单详情显示“请前往 App Store”；
7. 真实 Apple 问询到达后显示“等待 App Store 退款处理”；
8. 最终成功通知后显示“已退款”；
9. 后台 100% 缩放操作列完整、详情按钮可点击且新窗口打开。

## 8. Grey Release and Online Validation

### Grey release

- [ ] schema 扩展后先跑第一次回填；后端写路径上线后必须跑第二次增量回填，再开放筛选 UI。
- [ ] 先对内部/测试商家开放新后台资源。
- [ ] 小程序先体验版/灰度版本验证。
- [ ] 观察至少一个真实 iOS 支付和退款申请链路。
- [ ] 检查 `UNKNOWN` 成功虚拟交易数量是否增长。
- [ ] 检查订单列表 P95 查询耗时。

### Online full-chain validation

- [ ] 新 iOS 支付 → 分类 IOS_APPLE → 后台筛选命中。
- [ ] 本地退款申请 → 状态一。
- [ ] Apple 问询 → 状态二且响应成功。
- [ ] Apple 成功通知 → 四层本地状态收口。
- [ ] 用户确认实际 Apple/银行卡退款结果；系统日志不能替代到账证据。

## 9. Defect Closure

每个缺陷记录：

```text
编号 / 严重级别 / 前置条件 / 复现步骤 / 预期 / 实际 / 根因 / 修复提交 / 首测 / 复测 / 回归范围 / 状态
```

发布门槛：P0/P1 缺陷为 0；所有已修复缺陷完成原场景复测和关联回归，闭环率 100%。

## Execution update — 2026-07-12

- [x] 渠道字段、分类写入、快照原子合并、历史回填命令
- [x] 已支付 UNKNOWN 有界补偿与未决证据退避
- [x] 全部订单支付渠道筛选（分页前）及稳定排序
- [x] iOS 退款状态投影、UNKNOWN 安全失败、本地申请锁顺序
- [x] 小程序完整指南、入口/提交按钮文案、iOS 隐藏并清理凭证
- [x] 商家表格宽度、独立操作列、64×32 详情按钮
- [x] PHP lint、商家 ESLint/Jest/build、uniapp H5 build、真实订单 DB smoke
- [ ] 1280/1366 浏览器 100% 视觉几何证据
- [ ] 修复后子代理复评 P0/P1=0
- [x] 可导入微信开发者工具的 mp-weixin 目标产物验证（HBuilderX 5.15 CLI 构建成功）
