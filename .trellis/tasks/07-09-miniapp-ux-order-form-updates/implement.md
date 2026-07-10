# 实施计划：Miniapp UX and order form updates（父任务）

## 执行原则

- 当前只做 Trellis planning 承接，不进入实现。
- 父任务不执行 `task.py start`，真正的 Phase 2 从子任务启动。
- 子任务之间的依赖必须体现在 artifact 中，而不是默认依赖 parent/child 关系被“自动理解”。
- 推荐先处理共享文件冲突面，再处理高风险订单链路与生产清理动作。

## 阶段 1：父/子结构整理

### 1.1 父任务保留为 planning / integration 任务
- [x] 保留 `07-09-miniapp-ux-order-form-updates` 作为父任务。
- [x] 不把父任务直接推进到 `in_progress`。
- [x] 将父任务 artifact 改写为“总需求 + 子任务映射 + 集成验收 + 推荐顺序”。

### 1.2 创建并挂接子任务
- [x] 创建 Child A：`07-09-homepage-popup-privacy-singleton`
- [x] 创建 Child B：`07-09-feedback-complaint-mvp`
- [x] 创建 Child C：`07-09-service-order-contract-search-soft-delete-hide`
- [x] 确认父任务 `task.json.children` 已正确记录 3 个子任务。

## 阶段 2：补齐子任务 planning artifacts

### 2.1 Child A
- [x] 写明：首页弹窗 / 隐私协议的范围边界。
- [x] 写明：与 Child B 在 `pages/user/index.vue`、`pages.json` 上的共享文件协调。
- [x] 写明：验收标准与回滚边界。
- [x] 补齐 `prd.md` / `design.md` / `implement.md`。

### 2.2 Child B
- [x] 写明：反馈 MVP 只做单向工单，不扩成聊天系统。
- [x] 写明：复用现有上传 API，且与 Child A 的共享文件协调方式。
- [x] 写明：验收标准与回滚边界。
- [x] 补齐 `prd.md` / `design.md` / `implement.md`。

### 2.3 Child C
- [x] 写明：服务订单新字段契约、后台搜索契约、历史 soft delete hide 策略。
- [x] 写明：内部发布顺序（先代码改造/验证，再维护窗口执行清理）。
- [x] 写明：验收标准与回滚边界。
- [x] 补齐 `prd.md` / `design.md` / `implement.md`。

## 阶段 3：建议启动顺序

### 推荐先启动 Child A
理由：
1. **共享文件最少量先落位**：A 会先稳定 `pages/user/index.vue` 与 `pages.json` 的基础入口结构，降低 B 的文件冲突。
2. **单例配置模式先落地**：A 先验证 `wxapp_setting.basic` 扩展 + 小程序单例内容 API 这条模式，后续不会反复试错。
3. **回滚成本最低**：业务弹窗可用后台开关止损，隐私协议专页也不涉及订单主链路。
4. **给高风险子任务留到最后**：C 涉及订单契约、搜索回归、历史数据隐藏，更适合作为最后启动的子任务。

### 推荐整体顺序
- 第 1 个启动：Child A
- 第 2 个启动：Child B
- 第 3 个启动：Child C

## 阶段 4：进入实现前的门槛

在得到明确批准前，不执行以下动作：
- [x] 已按批准顺序分别承接子任务并完成收口
- [x] 已在各子任务内完成必要的上下文读取、实现与验证
- [x] 3 个子任务已全部完成实现、验证与交付

得到批准后，再按如下顺序推进：
1. review 对应子任务的 `prd.md` / `design.md` / `implement.md`
2. 只启动当前要做的那个子任务
3. 读取 `trellis-before-dev` 与相关 spec
4. 再进入实现

## 结论

父/子任务结构已就位；后续的正确入口应是：
- 先选定一个子任务；
- 经用户批准后，只启动那个子任务；
- 不以父任务直接进入实现。


## 最终集成收口（2026-07-10）

- Child A `07-09-homepage-popup-privacy-singleton`：已完成并归档。
- Child B `07-09-feedback-complaint-mvp`：已完成并归档。
- Child C `07-09-service-order-contract-search-soft-delete-hide`：已完成实现、验证与历史服务订单 soft-delete hide，待本轮归档。

### 集成验收结论

- 首页业务弹窗与微信隐私弹窗时序已落地，隐私协议入口与专页已交付。
- 反馈/投诉闭环已交付，商家后台可处理，用户端可提交并查看。
- 服务订单新字段在 checkout / 用户端详情 / 商家端列表详情 / 导出 / 搜索中已统一。
- 历史服务订单仅按 `delivery_type = NOTHING`、`is_delete = 0`、`create_time < 2026-07-10 00:00:00` 执行 soft delete hide，并保留备份恢复路径。

### 父任务收口结论

- 父任务已完成其 planning / integration 职责。
- 3 个子任务均已具备独立 artifact、验证记录与回滚边界。
- 本父任务可在 Child C 归档后一起归档。
