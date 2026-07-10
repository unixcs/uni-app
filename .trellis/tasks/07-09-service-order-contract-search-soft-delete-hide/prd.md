# Service order contract search and soft-delete hide

## Goal

交付服务订单链路的统一升级：
- checkout 改用新字段契约；
- 用户端、商家端、导出、搜索都按同一字段语义显示与检索；
- 历史服务订单通过 soft delete hide 从常规视图中隐藏；
- 不影响非服务订单，不做物理删除。

## Confirmed facts

- 当前 checkout 仍使用旧字段：`contactName / contactMobile / timePreference / remark`。
- 当前订单读取、商家后台列表/详情/导出/搜索也仍按旧字段语义工作。
- `app/common/model/Order.php` 当前追加字段与服务订单识别仍围绕 `contact_name / contact_mobile / time_preference` 展开，`app/api/model/Order.php` 也仍用这 3 个字段组装 `service_contact` 返回结构。
- 已确认新的服务订单字段为：
  1. `端游 / 手游`
  2. `游戏 ID（请填写游戏账号 ID）`
  3. `联系方式（请填写应急联系方式）`
  4. `确认成年人下单（请填写确认成年人下单）`
  5. `备注（如有其他要求，请再次补充。）`
- 已确认搜索方案：
  - `游戏 ID / 联系方式 / 备注` 采用复选框多选；
  - 同一个关键词按所选字段做 OR 匹配；
  - `端游 / 手游` 是独立筛选。
- 已确认历史服务订单处理策略：只做 soft delete hide，不做物理删除。
- 已确认历史服务订单生产边界采用**显式 cutoff_time**：仅处理满足 `delivery_type = NOTHING`、`is_delete = 0` 且 `create_time < cutoff_time` 的订单，避免把新契约上线后的新单误隐藏。
- 仓库里已存在 `app/common/command/ServiceOrderHistoryCleanup.php`，但当前实现只针对固定 `store_id=10001` 和默认测试用户 ID 列表，适合作为复用/改造起点，不适合作为当前生产级历史隐藏规则直接使用。

## Requirements

### R1. 新服务订单契约
- `service_contact` 结构切换为：
  - `game_platform`
  - `game_account_id`
  - `contact_mobile`
  - `adult_confirm`
- `remark` 继续复用 `buyer_remark`。
- 仅服务订单场景使用该契约校验。

### R2. 前后端显示统一
- 新字段语义必须同步反映到：
  - 小程序 checkout
  - 小程序 order detail/history
  - 商家后台 order list/detail/export/search
- 不允许新旧字段语义在不同页面并存。

### R3. 商家后台搜索契约
- 使用单一 `searchValue`。
- `serviceSearchFields` 支持：
  - `game_account_id`
  - `contact_mobile`
  - `buyer_remark`
- 当 `searchValue` 非空且勾选了至少一个字段时，对已勾选字段做同一个 OR 分组匹配。
- `gamePlatform` 支持：`'' | 'pc' | 'mobile'`，并通过独立筛选控件驱动。
- 改造后不得破坏订单号、第三方单号、会员昵称、会员 ID 等既有找单能力。

### R4. 历史服务订单 soft delete hide
- 只针对服务订单。
- 目标集合必须同时满足：
  - `delivery_type = NOTHING`
  - `is_delete = 0`
  - `create_time < cutoff_time`
- `cutoff_time` 必须在执行时显式传入，不允许自动猜测。
- 执行动作只更新 `order.is_delete = 1`。
- 默认 `dry-run`，先输出数量/状态分布再执行。
- 先完成代码改造并 smoke test 新订单，再在维护窗口执行历史数据隐藏。

## Dependencies

### 与其他子任务关系
- 不依赖 Child A / Child B 即可独立实现。
- 但建议在 A/B 稳定后再启动，以便集中处理订单主链路回归与上线窗口。

### 子任务内部依赖
必须按顺序推进：
1. 契约改造（提交/读取/显示/搜索/导出）
2. 新订单 smoke test
3. 维护窗口执行历史服务订单 soft delete hide
4. 执行后前后台复查

### 关键代码依赖
- 小程序：`yoshop2.0-uniapp/pages/checkout/index.vue`、`yoshop2.0-uniapp/pages/order/detail.vue`
- API / 读取模型：`yoshop2.0/app/api/service/order/Checkout.php`、`yoshop2.0/app/common/model/Order.php`、`yoshop2.0/app/api/model/Order.php`
- 商家后台：`yoshop2.0-store/src/views/order/Index.vue`、`yoshop2.0-store/src/views/order/Detail.vue`
- Store 侧读取 / 导出 / 清理：`yoshop2.0/app/store/model/Order.php`、`yoshop2.0/app/store/service/order/Export.php`、`yoshop2.0/app/common/command/ServiceOrderHistoryCleanup.php`

## Acceptance criteria

- [ ] checkout 可采集并校验新字段，服务订单提交成功。
- [ ] 用户端 order detail/history 与 checkout 使用同一字段语义。
- [ ] 商家后台 list/detail/export 使用同一字段语义。
- [ ] `全部订单` 搜索支持 `游戏 ID / 联系方式 / 备注` 多选 OR 匹配。
- [ ] `全部订单` 搜索支持独立 `端游 / 手游` 筛选。
- [ ] 订单号、第三方单号、会员昵称、会员 ID 等既有通用找单能力未回归失效。
- [ ] 历史隐藏命令默认 `dry-run`，且会先输出候选数量/状态分布。
- [ ] 历史隐藏命令只会匹配 `delivery_type = NOTHING`、`is_delete = 0`、`create_time < cutoff_time` 的服务订单。
- [ ] 历史服务订单隐藏仅影响服务订单，且执行动作仅为 soft delete hide。

## Rollback boundary

- 代码回滚与历史数据回滚必须分开描述与执行。
- 历史数据隐藏只写 `is_delete=1`，必要时可基于命令备份文件或目标订单集合人工恢复为 `0`。
- 若搜索改造出现回归，可优先回退 store list/detail/export/search 读取面，而不必立即动历史数据。
- 若 cutoff_time 选取失误，先停止执行 soft-delete 模式，重新用 `dry-run` 校验目标集合后再处理。

## Out of scope

- 物理删除历史订单。
- 非服务订单的数据清理。
- 首页弹窗、隐私协议、反馈工单能力。

## Open questions

- None at the moment.
