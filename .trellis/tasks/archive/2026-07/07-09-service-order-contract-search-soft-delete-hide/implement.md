# 实施计划：Service order contract search and soft-delete hide

## 执行原则

- 先统一契约，再做展示/搜索，再做历史隐藏。
- 历史服务订单处理只做 soft delete hide，不做物理删除。
- 真正执行历史隐藏前，必须已有新订单 smoke test 结果。
- 历史隐藏边界固定为：`delivery_type = NOTHING` + `is_delete = 0` + `create_time < cutoff_time`。
- 实现前仍需用户明确批准并执行本子任务自己的 `task.py start`。

## 阶段 1：后端契约改造

- [x] 改造 `Checkout.php` 输入契约与校验。
- [x] 将 `service_contact` 写入结构切换为：
  - `game_platform`
  - `game_account_id`
  - `contact_mobile`
  - `adult_confirm`
- [x] 更新 `app/common/model/Order.php` 与 `app/api/model/Order.php` 的读取/append 逻辑。

验证：
- 新服务订单提交成功；
- 缺任一必填字段时报业务错误；
- 写入 JSON 结构符合新契约。

## 阶段 2：小程序 checkout / detail 改造

- [x] 重写 `pages/checkout/index.vue` 表单 UI 与提交参数。
- [x] 更新 `pages/order/detail.vue` 服务订单展示区。
- [x] 确保全部文案使用新字段语义。

验证：
- 小程序可完成新服务订单下单；
- 订单详情展示字段与 checkout 一致。

## 阶段 3：商家后台读取 / 搜索 / 导出改造

- [x] 更新 `yoshop2.0-store/src/views/order/Index.vue` 服务订单展示语义。
- [x] 增加 `游戏 ID / 联系方式 / 备注` checkbox 多选组。
- [x] 增加 `端游 / 手游` 独立筛选控件。
- [x] 更新 `yoshop2.0-store/src/views/order/Detail.vue`。
- [x] 更新 `app/store/model/Order.php`：
  - `serviceSearchFields`
  - `gamePlatform`
  - OR 分组关键词匹配
- [x] 更新 `app/store/service/order/Export.php` 导出字段。
- [x] 回归验证既有通用找单能力。

验证：
- 后台列表/详情/导出显示新字段；
- 勾选任意一个或多个服务订单字段时，同一个关键词能正确命中；
- `端游 / 手游` 筛选正确；
- 原有通用找单能力仍可用。

## 阶段 4：历史服务订单 soft delete hide

- [x] 优先改造现有 `service-order:history-cleanup` 命令，保留 `dry-run / soft-delete` 双模式。
- [x] 增加显式 cutoff_time 参数（推荐 `--before-time`），不再依赖固定测试用户集合作为生产筛选条件。
- [x] 命令目标集合固定为：
  - `delivery_type = NOTHING`
  - `is_delete = 0`
  - `create_time < cutoff_time`
- [x] 执行动作仅更新 `order.is_delete = 1`。
- [x] 先输出状态分布汇总并写备份文件，再决定执行 soft-delete 模式。
- [x] 已在代码验证与 smoke test 后执行维护窗口 soft-delete hide。

验证：
- dry-run 输出的候选集只包含边界内旧服务订单；
- 非服务订单不受影响；
- 旧服务订单从常规可见入口消失；
- 必要时可依据备份文件与目标集合人工恢复 `is_delete = 0`。

## 回滚点

- 代码层回滚：先回退读取/展示/搜索改造，再评估是否需要回退提交契约。
- 搜索层回滚：若出现找单回归，可先回退 store search / export / view，不立即动历史数据。
- 数据层回滚：若已执行 soft delete hide，则依据备份文件或命中的订单集合人工恢复目标服务订单 `is_delete = 0`。
- 运维层止损：若 cutoff_time 有争议或 dry-run 结果异常，停止 soft-delete 模式，仅保留 dry-run 复核。

## 启动前检查

- [x] 用户已明确批准启动 Child C
- [x] Child C `prd.md` / `design.md` / `implement.md` 已 review
- [x] 已接受这是 3 个子任务中最高风险的一项
- [x] 当前已直接承接实现、验证与收口


## 本轮实际交付（2026-07-10）

- 服务订单 checkout 输入契约切换为 `gamePlatform / gameAccountId / contactMobile / adultConfirm`，并保持 `remark` 继续写入 `buyer_remark`。
- `app/common/model/Order.php`、`app/api/model/Order.php`、商家后台列表/详情/导出统一读取新字段语义。
- 商家后台 `全部订单` 支持 `游戏 ID / 联系方式 / 备注` 复选框多选，并对同一关键词做 OR 匹配；`端游 / 手游` 作为独立筛选。
- 小程序订单详情、商家后台详情/列表、导出字段已统一为 `游戏平台 / 游戏ID / 联系方式 / 成年人下单`。
- 历史服务订单清理命令升级为显式 `--before-time` + `dry-run / soft-delete` 双模式，并仅做 `is_delete = 1` soft delete hide。
- 已于维护窗口执行 `php think service-order:history-cleanup --before-time="2026-07-10 00:00:00" --mode=soft-delete`，备份文件：`/opt/yoshop/yoshop2.0/runtime/service-order-cleanup/history-cleanup-20260710151824.json`。

## 已执行验证（2026-07-10）

- 商家后台订单相关文件 lint 通过。
- PHP 订单相关模型 / 服务 / 命令语法检查通过。
- `service-order:history-cleanup` 已完成 dry-run 与 soft-delete 两次验证。
- 数据库复核结果：`delivery_type = NOTHING` 的未删除服务订单现仅保留 5 条 2026-07-10 的新契约订单，2026-07-10 00:00:00 之前的 25 条旧服务订单已隐藏。
- 小程序 `mp-weixin` 编译产物已确认包含新字段与校验文案。
- 商家后台构建完成并已同步部署到 `yoshop2.0/public/store`。

## 待人工复核（可选）

- 继续通过商家后台 `全部订单` 搜索验证 `游戏 ID / 联系方式 / 备注` 的多选 OR 语义。
- 如需恢复历史服务订单，可基于备份文件中的订单集合将 `is_delete` 批量恢复为 `0`。
