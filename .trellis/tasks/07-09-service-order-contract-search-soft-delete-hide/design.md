# 设计说明：Service order contract search and soft-delete hide

## 1. 设计目标

该子任务要解决的核心不是“换表单”，而是让服务订单在提交、展示、导出、检索、历史处理上只存在一套真相源语义。

因此设计原则是：
- 新字段契约唯一化；
- 搜索契约与展示契约同名；
- 历史订单不做物理删除，只做可恢复的 soft delete hide；
- 旧历史订单范围靠**显式时间边界**圈定，而不是靠模糊启发式推断。

## 2. 边界

### 拥有范围
- `service_contact` 新 JSON 契约
- checkout / order detail / store list/detail/export/search 改造
- 商家后台“全部订单”搜索升级
- 历史服务订单隐藏命令与执行顺序

### 不拥有范围
- 首页弹窗、隐私协议、反馈工单
- 非服务订单的数据清理

## 3. 契约设计

### 3.1 新 `service_contact` 结构
- `game_platform`: `pc | mobile`
- `game_account_id`: 游戏 ID 文本
- `contact_mobile`: 联系方式
- `adult_confirm`: 成年人下单确认文本

`remark` 不进入 `service_contact`，继续使用 `buyer_remark`。

### 3.2 为什么仍放在 `order_source_data.service_contact`
- 当前系统已把服务订单扩展信息收口在这里；
- 这次不值得为了 4 个字段额外建独立表；
- 更新 checkout / model / view / export 的路径更直接。

## 4. 搜索契约设计

### 4.1 字段映射
- `游戏 ID` → `order_source_data.service_contact.game_account_id`
- `联系方式` → `order_source_data.service_contact.contact_mobile`
- `备注` → `order.buyer_remark`
- `端游 / 手游` → `order_source_data.service_contact.game_platform`

### 4.2 查询参数形状
- `searchValue: string`
- `serviceSearchFields: string[]`
- `gamePlatform: '' | 'pc' | 'mobile'`

### 4.3 行为约束
- 当 `searchValue` 非空且 `serviceSearchFields` 至少有一个值时，对勾选字段组成同一个 OR 分组 LIKE 匹配；
- `gamePlatform` 是独立过滤，不混入关键词语义；
- 既有通用找单能力不能被回归破坏。

## 5. 历史订单隐藏设计

仓库证据：已有 `service-order:history-cleanup` 命令，但当前只面向固定测试用户集合，说明项目已经接受“默认 dry-run + 汇总 + soft delete”的命令形态；本次应优先在这个方向上改造，而不是重新发明另一套清理机制。

### 5.1 处理策略
- 目标范围必须同时满足：
  - `delivery_type = NOTHING`
  - `is_delete = 0`
  - `create_time < cutoff_time`
- `cutoff_time` 由执行者显式传入；推荐沿用当前命令风格，新增 `--before-time` 一类选项，而不是把边界硬编码进代码。
- 动作：`order.is_delete = 1`
- 默认：`dry-run`
- 保留关联支付/退款等数据，不做物理删除

### 5.2 为什么采用 cutoff_time 边界
- 旧订单和新订单在 `delivery_type = NOTHING` 上可能同属一类，单靠 delivery_type 无法区分语义代际；
- 这次真正需要解决的是“把旧语义历史单隐藏掉”，时间边界是最小且可审计的生产规则；
- 显式时间参数比“猜测哪些单像旧单”更简单、更安全，也更符合维护窗口操作。

### 5.3 现有命令的复用边界
- 保留现有命令的两个优点：
  - `dry-run / soft-delete` 双模式
  - 执行前输出 summary，执行时写备份文件
- 去掉当前仅服务于测试清理的限制：
  - 固定测试用户 ID 列表
  - 仅靠测试用户集合界定目标订单
- 新版命令的真相源应改为“服务订单 + 未删除 + before_time”。

### 5.4 为什么只做 soft delete hide
- 用户目标是让旧语义订单从常规视图中消失；
- 开发侧必须保留最基本恢复路径；
- `is_delete` 恰好满足“可恢复隐藏”。

## 6. 发布顺序与依赖设计

必须遵守：
1. 代码与字段契约先上线；
2. 验证新订单可正常提交、展示、搜索；
3. 在维护窗口执行历史服务订单隐藏命令；
4. 执行后复查前后台常规入口不可见旧服务订单。

这意味着：
- “代码改造完成” ≠ “历史清理可立即执行”；
- soft delete hide 属于独立上线动作，需要单独 review；
- `cutoff_time` 应在维护窗口前明确记录，避免现场临时口头决定。

## 7. 回滚边界

### 7.1 代码回滚
- 可按层次回退：
  - store search/read/export
  - app api/common model
  - miniapp checkout/detail

### 7.2 数据回滚
- 仅当已经执行 soft delete hide 时需要数据回滚；
- 回滚方式是把目标服务订单 `is_delete` 从 `1` 恢复为 `0`；
- 因未物理删除，仍保留人工恢复空间；
- 备份文件与执行时使用的 `cutoff_time` 一起构成恢复依据。

## 8. 结论

这是三个子任务中风险最高、验证面最广、最接近生产变更窗口的一个，因此应作为最后启动的子任务。
