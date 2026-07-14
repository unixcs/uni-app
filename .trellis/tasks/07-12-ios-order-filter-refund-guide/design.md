# iOS订单筛选退款指引与后台布局优化 — 技术设计

## 1. Problem Restatement

本任务解决三个直接问题：

1. 商家无法从订单列表中稳定筛出实际通过 Apple/App Store 支付、退款路径特殊的订单；
2. iOS 用户只完成小程序本地退款申请还不够，还必须去 Apple 再次申请，但页面没有完整且阶段准确的指引；
3. 商家订单列表固定宽度布局导致 100% 缩放时操作列裁切，“详情”只有文字区域可点击。

不解决的问题：不新增退款引擎、不改变 Apple 审核权、不新增复杂报表或导出能力。

## 2. Fundamental Truths

### 2.1 业务不变量

- Apple/App Store 退款由 Apple 决策；商家或小程序不能对 iOS Apple 订单直接承诺原路退款。
- 用户必须先在小程序提交本地退款申请，系统才有本地退款上下文来响应后续 Apple 退款问询。
- “当前设备是 iPhone”不等于“该历史订单实际由 Apple 支付”。订单分类必须来自成功支付交易。
- 未知不能等价于非 iOS；错误归类会触发错误退款路径。

### 2.2 技术不变量

- `order.trade_id` 在支付成功后指向最终成功交易，是已支付订单分类的首选绑定。
- `payment_trade.platform = wechat_virtual` 只说明微信虚拟支付；还需上游 `order_type=7` 或 Apple 退款问询证据才能确认 iOS Apple。
- 列表筛选必须在分页之前完成，不能先分页再由 PHP/前端过滤。
- 当前订单列表是手写 HTML table，不是 `a-table`；列宽配置不会自动生效。
- 当前工作树已有未提交的 iOS 退款链路改动，设计必须增量兼容，不得回退既有修复。

## 3. Scope and Boundaries

### 3.1 Merchant console

- 仅在路由数据类型 `all` 的“全部订单”显示“支付渠道”筛选。
- 下拉业务选项只有：
  - `ios_apple` → iOS订单
  - `non_ios` → 非iOS订单
- 空值表示全部；内部 `unknown` 不作为运营选项。
- 本次不增加“支付渠道”展示列，避免无需求地增加第九列并进一步挤压表格。

### 3.2 Miniapp

- 保持订单详情入口按钮“退款”。
- 复用现有 `pages/refund/apply`，不新增路由或页面。
- iOS 模式保留退款说明和“提交退款申请”，隐藏上传凭证。
- 完整教程显示于：
  1. 退款申请页提交前；
  2. 本地退款申请提交后的服务订单详情。
- 非 iOS 继续使用原退款申请体验。

### 3.3 Backend

- 增加支付交易的规范化、可索引渠道分类投影。
- 调整 iOS 退款状态投影，严格区分本地登记和 Apple 已问询。
- 不改变 Apple 回调认证、签名、响应协议和最终退款收口事务。

## 4. Payment Channel Classification

### 4.1 Why persist a projection

直接在订单列表 SQL 中反复解析 `payload_snapshot` 多层 JSON 有三个问题：

- JSON 路径容易随上游结构变化而漂移；
- 无法有效索引，分页前筛选会随数据量增长而变慢；
- 同一分类逻辑会在筛选、退款和详情中重复。

因此采用最小持久化投影：在 `payment_trade` 上增加 `channel_class`。

### 4.2 Data contract

建议新增字段：

```text
payment_trade.channel_class TINYINT UNSIGNED NOT NULL DEFAULT 0
```

内部枚举：

| 值 | 常量 | 含义 |
|---:|---|---|
| 0 | `UNKNOWN` | 尚无足够证据 |
| 10 | `NON_IOS` | 已确认非 Apple/App Store 支付 |
| 20 | `IOS_APPLE` | 已确认 Apple/App Store 支付 |

字段位于支付交易而不是订单表，因为分类事实属于实际成功支付尝试；订单通过 `trade_id` 绑定最终成功交易。

索引不预设为固定组合。先落最终 SQL，在生产等量数据上执行 `EXPLAIN`；若订单表按 `trade_id` 对交易表形成 `eq_ref`，主键可能已足够。只有扫描行数和 P95 证明需要时才增加与实际查询匹配的索引。

### 4.3 Classification rules

#### iOS Apple

满足：

```text
trade.platform = wechat_virtual
AND 任一证据成立：
  - query/timer-query/pay-notify 中上游 order_type = 7
  - ios_refund_query_notify 已收到
  - virtual_refund.ios_refund_required = true
```

#### Non-iOS

以下任一为已确认非 iOS：

- 已支付余额订单；
- 成功支付交易 `platform != wechat_virtual`；
- 微信虚拟支付成功交易已取得上游 `order_type` 且不等于 7。

#### Unknown

- 未支付或支付失败；
- 虚拟支付已成功但尚未取得上游类型；
- 历史交易缺失快照或绑定；
- 上游查单暂时失败。

### 4.4 Write points

分类推进统一收口在 `PaymentTrade` 的加锁写方法中，避免调用点先写快照、后写分类造成部分成功：

- 提供纯分类函数 `classify(platform, mergedSnapshot, currentClass)`；
- `mergePayloadSnapshot()` 在交易行锁内，对合并后的完整快照重新分类并与快照原子保存；
- `recordNotify()` 复用同一加锁/分类机制；
- 普通支付交易创建时：只有存在明确普通支付证据才写 `NON_IOS`；
- 虚拟支付交易创建时：写 `UNKNOWN`；
- 主动查单、定时查单、退款查单成功合并快照时自然推进分类；
- iOS Apple 退款问询到达时：幂等提升为 `IOS_APPLE`；
- 状态单向推进：`UNKNOWN -> NON_IOS/IOS_APPLE`，`NON_IOS` 遇到强 Apple 证据可升级为 `IOS_APPLE`，`IOS_APPLE` 永不降级；
- 同一快照出现互相冲突的上游类型时，按退款安全原则归 `IOS_APPLE` 并记录冲突日志。

新增“已支付 UNKNOWN 补偿”：现有未支付定时查单之外，再以有界批次扫描 `wechat_virtual + channel_class=UNKNOWN + 订单已支付且 order.trade_id=trade.trade_id` 的交易。采用退避、批量上限和可恢复游标；查单失败继续保持 UNKNOWN，绝不猜测为 NON_IOS。

### 4.5 Historical backfill

新增独立、可重复执行的分批回填命令：

1. 有明确 Apple 证据的交易回填/提升为 `IOS_APPLE`；
2. 虚拟支付快照中存在合法且明确非 7 上游类型的回填 `NON_IOS`；
3. 普通支付只有在支付方式、交易绑定和版本/实现证据足够时才回填 `NON_IOS`；历史 `platform=''`、坏快照或错绑数据默认保持 `UNKNOWN`；
4. 默认只更新 `UNKNOWN`，但强 Apple 证据允许 `NON_IOS -> IOS_APPLE`；禁止覆盖 `IOS_APPLE`；
5. 命令支持 `--dry-run`、`--from-trade-id`、`--limit`、`--batch-size`，每批独立提交并输出三类计数、坏 JSON 和冲突清单。

真实订单 `out_trade_no=334098380149377916` 必须回填为 `IOS_APPLE`。

为避免直接修改历史版本 SQL，新增当前版本对应的独立数据库变更记录，并同时修正 fresh-install `install.sql` 的 `payment_trade` 完整结构，避免新装与升级 schema 继续漂移。提供：

- 只加列的兼容 schema 变更；
- 分批、dry-run、可恢复回填命令；
- 上线前后及二次增量回填统计；
- 回滚仅回滚应用读取，字段本身保留，避免破坏已写数据。

## 5. Merchant Order Filter

### 5.1 API contract

新增查询参数：

```text
paymentChannel: '' | 'ios_apple' | 'non_ios'
```

后端必须 allowlist；非法值统一规范化为空值（等同未选择渠道），并记录调试日志，不允许直接拼接到 SQL。

### 5.2 Query semantics

仅对已确认分类的已支付订单命中：

```text
ios_apple:
  order.pay_status = SUCCESS
  AND trade.trade_id = order.trade_id
  AND trade.order_id = order.order_id
  AND trade.store_id = order.store_id
  AND trade.trade_state IN (SUCCESS, REFUND)
  AND trade.channel_class = IOS_APPLE

non_ios:
  order.pay_status = SUCCESS
  AND (
    (order.pay_method = balance AND order.trade_id = 0)
    OR (
      trade.trade_id = order.trade_id
      AND trade.order_id = order.order_id
      AND trade.store_id = order.store_id
      AND trade.trade_state IN (SUCCESS, REFUND)
      AND trade.channel_class = NON_IOS
    )
  )
```

`UNKNOWN`、未支付、支付失败不命中任一渠道值。

筛选在 `paginate(10)` 前加入主查询，并由 `applyPaymentChannelFilter()` 同时复用于 `getList()` 和 `getListAll()`。排序固定为 `order.create_time DESC, order.order_id DESC`，避免相同时间戳跨页漂移。首期只有“全部订单”页面传参；订单导出 UI 不新增控件或列。

### 5.3 Frontend behavior

- `dataType === 'all'` 时渲染支付渠道下拉；其他标签页不显示、不发送该参数。
- 默认空值“全部”。
- 搜索、重置、翻页沿用现有 `queryParam` 流程。
- 不新增列表渠道列。

## 6. iOS Refund Guide and State Machine

### 6.1 State evidence

| 状态 | 必需证据 | 用户文案 |
|---|---|---|
| 未提交本地申请 | iOS Apple 已确认，无活动退款记录 | 正常显示“退款”入口 |
| 本地申请已提交 | 存在本地活动退款记录，但无 Apple 问询 | 退款申请已提交，请前往 App Store 申请退款 |
| Apple 已问询 | `ios_refund_query_notify` 或等价已认证证据 | 等待 App Store 退款处理 |
| 完成 | 成功退款通知已完成本地四层收口 | 已退款 |
| 本地取消/拒绝 | 本地状态证据且无更强 Apple 证据 | 使用明确“本地退款申请已取消/未通过”语义，不冒充 Apple 结论 |

状态采用单调证据优先级：

```text
最终成功通知完成
> 已认证 Apple inquiry
> 本地活动退款申请
> 本地取消/拒绝
> iOS 已确认但尚未提交
```

Apple inquiry 或最终成功到达后，本地取消/拒绝不得让状态倒退；最终成功可以覆盖此前任何本地状态。`virtual_refund.ios_refund_required` 或 `waiting_ios_apple_refund` 只证明本地系统进入等待 Apple 路径，不等于已收到 Apple 问询。

关键修正：不能再用“存在本地退款单”直接推导“等待 App Store 退款处理”。

### 6.2 Guide component

为避免申请页和订单详情复制整段文案，新增小程序本地复用组件，例如：

```text
components/refund/IosAppleRefundGuide.vue
```

Props：

```text
stage: 'before_submit' | 'after_submit'
```

- `before_submit` 开头：如需退款，请先点击下方“提交退款申请”按钮……
- `after_submit` 开头：本平台的退款申请已提交，请继续前往 Apple 官方渠道……
- 后续三步流程和两条注意事项共用。

组件只负责展示；是否显示由后端 `ios_apple_refund_required/refund_entry_mode` 决定。

### 6.3 Apply page

- 入口按钮仍为“退款”。
- iOS 模式提交按钮显示“提交退款申请”，不改为“提交退款记录”。
- 指南位于商品摘要之后、退款说明表单之前。
- iOS 模式隐藏上传凭证整行；非 iOS 不变。
- 提交请求仍创建本地 `order_refund`，不得改变 Apple 问询响应所需的本地记录链路。

### 6.4 Order detail

- 有活动 iOS 退款信息时，在“退款反馈”卡中展示 `after_submit` 指南。
- 顶部状态和退款反馈状态都使用后端证据化 `display_state_text`。
- 不在前端根据设备或本地表单状态自行猜测 Apple 是否已经处理。

### 6.5 Local application concurrency

用户本地申请和 Apple 自动建档必须遵循同一锁顺序 `order -> order_refund -> payment_trade`。本地 `apply()` 在事务内先锁订单行，再检查活动服务退款单并创建；外层无锁 count 只能作为快速提示，不能作为唯一性保障。并发两次提交、客户端超时重试、Apple 成功通知自动建档与用户申请并发时，最终活动服务退款单最多一条，状态不得倒退。若发现多个历史活动候选，停止猜测绑定并返回可观测错误。

`channel_class` 首期仅是筛选投影和正向缓存，**不是退款路由的唯一真相源**。退款服务继续保留现有快照、`order_type=7`、认证 inquiry 和 `ios_refund_required` 识别。`IOS_APPLE` 可作为额外正向证据；`UNKNOWN/NON_IOS` 不得覆盖已有 Apple 强证据。对成功 `wechat_virtual + UNKNOWN` 的退款请求安全失败并先触发/等待渠道确认，禁止直接调用开发者退款。

## 7. Merchant Table Layout

### 7.1 Layout strategy

保留现有手写表格，不迁移到 `a-table`，避免无关重构。

- 将固定 `width: 1500px` 改为 `width: 100%; min-width: <tested-min-width>`；
- 横向容器使用 `overflow-x: auto`，避免永久滚动条和裁切；
- 通过 `colgroup` 或明确 class 给操作列稳定宽度；
- 首期通过 `colgroup`/明确 class 固定操作列宽度并提供清晰横向滚动；
- 只有在 1280/1366px、100% 缩放实测仍不能满足可访问性时，才加入 `position: sticky; right: 0`；sticky 必须只作用于明确的操作 th/td，不能使用 last-child 误命中 `colspan=8` 行；
- 如启用 sticky，表头和内容操作列设置不透明背景、独立 z-index 和必要边界，并验证 `border-collapse` 表现；
- 保持 8 列和 `colspan=8`，因为本次不新增展示列。

### 7.2 Detail action

使用语义仍为链接、视觉为 Ant Design 默认描边按钮的实现：

- 最小宽度 `64px`；
- 高度按默认按钮约 `32px`；
- 完整区域可点击；
- `white-space: nowrap`；
- 保留 `$auth('/order/detail')`；
- 保留 `target="_blank"`。

不使用主色实心按钮，避免每行操作过度抢占视觉层级。

## 8. Compatibility

- 未传 `paymentChannel` 的调用保持原列表行为。
- 非 iOS 退款页面、表单和上传凭证保持原行为。
- 旧前端忽略新增投影字段，不受影响。
- 前端以 `refund_entry_mode === 'app_store_guided'` 为首选单一语义字段；旧接口回退只接受严格 boolean/`1`，不得用 `!!"0"`。字段冲突时以后端退款信息中的 Apple 强证据为准并记录异常。
- 新前端在后端字段缺失时不显示 iOS 指引，但不得因此决定退款路由；退款安全仍完全由后端证据决定。
- 独立退款详情页继续消费现有 `refund_guidance/display_state_text`，本任务不删除其已有展示；是否复用完整教程不作为首期范围。
- 数据库字段上线顺序必须先于写入该字段的后端版本。

## 9. Rollout and Rollback

### 9.1 Rollout order

1. 数据库 schema 扩展（旧后端兼容）；
2. 记录迁移前分类基线并执行第一次历史 dry-run/回填；
3. 发布后端分类写入、已支付 UNKNOWN 补偿、筛选与状态投影；
4. 执行第二次增量回填/对账，覆盖首次回填到后端发布之间的新交易；
5. 验证新成功交易持续落分类后，再开放商家筛选 UI；
6. 发布小程序；
7. 灰度期周期 reconciliation，观察查询耗时、unknown 最老时间/增长、退款问询响应与前端错误。

### 9.2 Rollback

- 前端可独立回滚到旧筛选/旧布局，后端新增字段保持兼容；
- 后端可停止消费 `paymentChannel` 并回落原列表；
- 不建议删除 `channel_class`，删除会丢失已积累的分类事实；
- Apple 回调和最终退款收口不得随 UI 回滚而停用。

## 10. Observability

至少记录或可查询：

- 各 `channel_class` 数量；
- 成功虚拟支付中仍为 `UNKNOWN` 的数量和最老时间；
- `334098380149377916` 的分类结果；
- Apple 问询收到时间与状态投影；
- `/order/list` 带渠道筛选的查询耗时；
- 前端订单详情和退款申请接口异常。

## 11. Key Trade-offs

- **选择持久化分类而非运行时 JSON 过滤**：增加一次 schema 变更，但换来正确分页、稳定性能和统一语义。
- **内部保留 unknown、UI 不展示第三选项**：稍增内部状态，但防止把不确定订单误当非 iOS。
- **复用现有退款页而非新建页面**：最小改动，保留原业务链路。
- **保留手写表格而非重写 a-table**：避免任务外重构，先通过自适应宽度、明确列宽和横向滚动定点修复；sticky 仅作为实测后的可选增强。
