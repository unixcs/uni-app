# 优化小改动工作流与项目使用手册

## Goal

从第一性原理重构本仓库的 AI 开发上下文入口，使 AI 能先把请求路由到最小代码边界，再按风险决定走 Fast Fix、轻量 Trellis Task 或完整设计流程；同时提供一套可长期维护、可直接照做的中文使用说明书，减少小改动时的全仓扫描、流程开销和 Token 浪费。

## User Value

- 小按钮、文案、局部样式和明确单点 Bug 可以低成本完成，不再默认全仓扫描或创建复杂任务。
- 支付、退款、权限、数据库和跨端契约仍保留完整规划与验证门禁，不以速度换安全。
- 新会话和新 AI 能从稳定入口快速理解仓库边界、搜索范围、文档职责和验证命令。
- 项目知识从一次性聊天/Task 记录提升为按需加载的架构地图、代码契约和领域文档。

## First-Principles Invariants

1. AI 无法凭空知道修改边界；必须先有一个成本很低的路由入口。
2. 文档只有在按需加载时才节省 Token；默认加载更多文档会放大问题。
3. 源代码和可执行验证是事实来源；架构地图负责导航，不复制全部实现。
4. 流程强度应由变更风险决定，而不是由“是否修改文件”决定。
5. 历史 Task 是工作记录，不自动等于当前有效契约；可复用结论必须提升到 Spec、架构文档、领域词汇或 ADR。
6. Fast Fix 只能缩小初始调查范围，不能禁止在证据要求时升级范围和流程。

## Confirmed Repository Facts

- 仓库包含 PHP 后端 `yoshop2.0/`、uni-app 小程序/H5 `yoshop2.0-uniapp/`、商家后台 `yoshop2.0-store/`、平台总后台 `yoshop2.0-admin/` 和部署工具 `deploy/`。
- `.trellis/config.yaml` 当前未配置 packages，Trellis 处于 single-repo mode。
- 现有 `.trellis/spec/backend/` 与 `.trellis/spec/frontend/` 已被多个活跃 Task 的 JSONL 引用，不能破坏性移动。
- 根 `CONTEXT.md` 只覆盖微信虚拟支付服务订单领域，不是代码架构地图。
- `trellis-spec-bootstrap`、`trellis-meta`、`skill-creator`、`browser`、`virtual-payment` 和 `yoshop-deploy` 已具备对应能力。
- 当前 shell 中 `trellis` 可执行命令不在 PATH，`trellis-session-insight` 暂不能直接运行，但 Python Trellis 脚本可用。

## Requirements

### R1. Package Routing

- 在 `.trellis/config.yaml` 中声明真实应用边界：backend、miniapp、merchant-console、admin-console、deployment。
- 配置按 active task 收窄 Spec 范围；未指定 package 时不得静默假定某个业务应用。
- 为每个 package 提供短小 Spec 入口，明确适用目录、应读契约和质量门。
- 保留现有根级 backend/frontend Spec 路径，避免破坏活跃 Task 和历史引用。

### R2. Fast Fix Workflow

- 在本地 Trellis workflow 中定义可执行的 Fast Fix 判定、禁止条件、搜索预算、扩大范围证据和退出条件。
- Fast Fix 可在用户明确触发时跳过 Trellis Task 创建，但仍必须检查工作区、定位 package、做最窄验证。
- 支付、退款、订单状态机、权限、安全、数据库、生产配置、部署和不明确根因不得伪装成 Fast Fix。
- 对标准和高风险变更继续使用 Trellis 的 PRD/design/implement/check 门禁。

### R3. Project-Local Skill

- 创建 `.agents/skills/yoshop-fast-fix/`，用于“小改动、快速修复、不建任务、只查某个应用”等请求。
- Skill 必须采用渐进式披露：主文件简短，详细路由和验证表放 references。
- Skill 必须显式排除 vendor、node_modules、dist、runtime、uploads、归档 Task 等高噪声路径。
- Skill 必须包含风险升级规则，并通过 Skill 校验脚本。

### R4. Architecture Navigation

- 新增稳定、薄型的系统地图，说明应用职责、入口、代码边界、生成目录和关键跨端关系。
- 新增变更路由表，能将常见症状/关键词映射到首选 package、首查位置和升级条件。
- 新增验证矩阵，列出各 package 的最窄可靠验证方式及不能替代的人工验证。
- 文档只记录稳定导航，不伪造未验证调用链，不复制现有大型 Spec。

### R5. Complete Chinese Usage Manual

- 从第一性原理解释为什么要分层、为什么更多文档不一定更好、Trellis/Grill/Spec/Task/CONTEXT/ADR 各自解决什么问题。
- 提供从接收请求、分级、提示词写法、定位、实施、验证、知识沉淀到收尾的完整操作手册。
- 提供小改动、普通功能、高风险支付/退款、重复 Bug、架构决策、部署等场景的可复制示例提示词。
- 说明何时使用 grill-me、grill-with-docs、trellis-spec-bootstrap、trellis-session-insight、trellis-break-loop、browser、virtual-payment、yoshop-deploy。
- 包含维护制度、文档归属、反模式、故障排查和效果衡量指标。

## Constraints

- 不修改现有业务源码。
- 不改写或回滚用户当前未提交的虚拟支付 Spec、生产配置 Task 和中文说明文件。
- 不破坏活跃/归档 Task 中现有 Spec 路径。
- 不引入新的外部运行时依赖或假设当前不存在的 MCP 服务。
- 不把全仓逆向扫描固化成每次任务的前置步骤。
- 不将领域词汇、代码导航、实施契约和历史 Task 混写为一份万能文档。

## Out of Scope

- 本轮不完整逆向所有业务 Controller/Service/Model 调用链。
- 本轮不重写现有 2400+ 行 Spec。
- 本轮不安装 GitNexus、ABCoder 或 Trellis CLI。
- 本轮不清理现有 8 个活跃 Task；只在手册中给出治理规则。
- 本轮不替代微信开发者工具完成原生小程序自动化测试。

## Acceptance Criteria

- [x] `get_context.py --mode packages` 能列出五个真实 package 及各自 Spec 入口。
- [x] 现有 `.trellis/spec/backend/*.md`、`.trellis/spec/frontend/*.md` 路径保持可用，现有 Task JSONL 无断链。
- [x] workflow 的 no-task/triage 规则明确识别用户显式 Fast Fix，并保留高风险升级门禁。
- [x] `yoshop-fast-fix` Skill 具备合法 frontmatter、路由规则、搜索预算、排除目录、升级规则和验证矩阵引用，并通过 `quick_validate.py`。
- [x] 架构地图、变更路由表、验证矩阵中的路径和命令已由仓库文件验证。
- [x] 中文使用手册覆盖原理、分级、完整生命周期、工具选择、示例提示词、知识沉淀、维护和指标。
- [x] Markdown 链接、配置解析、Task context 路径和关键关键词检查通过。
- [x] 未触碰用户已有未提交业务文件，且最终 diff 可清楚区分本任务改动。
