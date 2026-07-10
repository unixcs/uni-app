# Miniapp UX and order form updates

## Goal

将当前任务保留为 **父任务（planning / integration）**，承载这次 miniapp 体验升级的完整源需求、子任务映射、跨子任务依赖说明、最终集成验收与发布顺序约束；父任务本身不应直接作为 Phase 2 的实现目标启动。

本次整体升级仍覆盖 3 个交付面：
1. 首页首登弹窗 + 隐私协议单例内容
2. 反馈 / 投诉 MVP
3. 服务订单契约升级 + 商家后台全部订单搜索升级 + 历史服务订单 soft delete hide

## Confirmed facts

- 当前父任务状态仍为 `planning`，且在得到明确批准前 **不得执行** `task.py start`。
- 已确认本任务应保留为父任务，并在 Phase 2 前拆成 3 个子任务。
- 已确认商家后台“全部订单”搜索方案：
  - `游戏 ID / 联系方式 / 备注` 使用复选框多选；
  - 同一个关键词按所选字段做 OR 匹配；
  - `端游 / 手游` 作为独立筛选。
- 已确认历史服务订单处理策略：
  - 只做 soft delete hide；
  - 不做物理删除。
- `pages/user/index.vue` 与 `pages.json` 会同时被“隐私入口”和“反馈入口”两个子任务触达，属于跨子任务共享文件，需要提前写清协调方式。
- 只有服务订单子任务包含类似“发布后再执行清理命令”的上线后动作，因此它必须自带独立的发布/回滚边界。

## Requirements

### R1. 父任务职责
- 父任务必须保留完整源需求，而不是只留下子任务标题。
- 父任务必须记录子任务映射、共享文件协调、推荐执行顺序、跨子任务验收口径。
- 父任务不直接承接具体代码实现清单；实现清单应下沉到对应子任务 artifact。

### R2. 子任务拆分结果
- Child A: `07-09-homepage-popup-privacy-singleton`
  - 范围：首页首登弹窗、隐私协议单例内容、对应后台配置与 API、隐私入口。
- Child B: `07-09-feedback-complaint-mvp`
  - 范围：反馈/投诉主记录、前台提交与记录页、后台处理页、反馈入口。
- Child C: `07-09-service-order-contract-search-soft-delete-hide`
  - 范围：服务订单 checkout/display/export/search 契约统一，以及历史服务订单 soft delete hide。

### R3. 依赖与协调必须显式写入子任务，而不是隐含在树结构里
- Child A 与 Child B 的共享文件协调必须写清：
  - `yoshop2.0-uniapp/pages/user/index.vue`
  - `yoshop2.0-uniapp/pages.json`
- Child C 不依赖 A/B 才能设计或实现，但它内部必须显式区分：
  1. 契约改造与展示/搜索改造
  2. 维护窗口中的历史订单 soft delete hide
- 各子任务必须写明自己的：
  - 逻辑依赖 / 文件级依赖
  - 可测试验收标准
  - 回滚边界

### R4. 父任务拥有最终集成验收
最终集成验收仍由父任务持有，至少包括：
- 首页业务弹窗与微信隐私弹窗时序正确；
- “我的”页中的隐私协议入口与反馈入口均可进入；
- 反馈流程可提交、后台可处理、前台可查看回复；
- 服务订单新字段在 checkout / 用户端 / 商家端 / 导出 / 搜索中语义一致；
- 历史服务订单隐藏策略仅影响服务订单，并保留人工恢复路径。

### R5. 推荐执行顺序需要在父任务中给出
- 父任务需要给出“建议先启动哪个子任务”的明确结论。
- 该结论必须考虑：共享文件冲突面、回滚成本、上线风险、后续子任务承接成本。

## Acceptance criteria

- [ ] 父任务保持 `planning`，且未执行 `task.py start`。
- [ ] 父任务 `task.json` 已正确挂接 3 个子任务。
- [ ] 3 个子任务各自具备 `prd.md` / `design.md` / `implement.md`。
- [ ] 每个子任务 artifact 都明确写出了依赖、验收、回滚边界。
- [ ] 父任务 artifact 已改写为“父规划 / 集成”角色，而不是继续作为单一实现任务使用。
- [ ] 父任务中记录了推荐执行顺序与原因。

## Child task map

1. **Child A — Homepage popup and privacy singleton content**
   - 目标：完成首页弹窗单次触达与隐私协议单例内容能力。
   - 主要共享面：`pages/user/index.vue`、`pages.json`。

2. **Child B — Feedback and complaint MVP**
   - 目标：完成用户提交反馈、查看处理进度、商家后台处理回复的 MVP 闭环。
   - 主要共享面：`pages/user/index.vue`、`pages.json`。

3. **Child C — Service order contract search and soft-delete hide**
   - 目标：完成服务订单新契约统一、后台搜索升级、历史数据隐藏策略。
   - 主要风险面：订单契约一致性、搜索回归、生产清理命令。

## Recommended execution order

推荐顺序：**A → B → C**。

- 先做 **A**：
  - 范围最小，能先落地 `wxapp_setting.basic` 单例内容模式；
  - 能先处理 `pages/user/index.vue` / `pages.json` 的基础入口改动，降低 B 的共享文件冲突；
  - 回滚成本低，可通过后台开关快速止损首页弹窗展示。
- 再做 **B**：
  - 复用 A 之后稳定下来的“我的”页与页面注册结构；
  - 功能相对独立，新增表和页面即使暂时关闭入口，也不会影响订单主链路。
- 最后做 **C**：
  - 风险最高，且带有发布后维护窗口中的历史数据隐藏动作；
  - 适合作为最后一个启动的子任务，以便在前两个交付面稳定后集中处理订单链路回归与上线窗口。

## Out of scope

- 在父任务里直接开始编码或执行任一子任务的 `task.py start`。
- 以父任务继续堆叠更细的实现 checklist 来替代子任务 planning。
- 变更已确认的搜索交互方案或 soft delete 策略。

## Open questions

- None at the moment.
