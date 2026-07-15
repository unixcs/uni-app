# 实施计划：优化小改动工作流与项目使用手册

## Phase A — Baseline and Context

- [x] 记录任务开始前的未提交文件，后续不得覆盖。
- [x] 将本任务需要的 workflow、config、现有 Spec 索引加入 context manifests。
- [x] 验证五个 package 的目录、manifest、入口和可用脚本。

## Phase B — Package Routing

- [x] 在 `.trellis/config.yaml` 声明五个 packages，并设置 `session.spec_scope: active_task`。
- [x] 创建 backend/server、miniapp/frontend、merchant-console/frontend、admin-console/frontend、deployment/ops 的短索引。
- [x] 索引引用真实路径、现有 Spec 和最小质量门，不复制整份规范。
- [x] 运行 `get_context.py --mode packages`，确认发现结果。
- [x] 扫描所有 Task JSONL，确认既有 Spec 引用仍存在。

## Phase C — Fast Fix Workflow and Skill

- [x] 同步修改 `.trellis/workflow.md` 的核心原则、Request Triage、no_task breadcrumb 和 Skill Routing。
- [x] 创建 `.agents/skills/yoshop-fast-fix/SKILL.md`。
- [x] 创建按需加载的 `references/routing.md` 和 `references/validation.md`。
- [x] 生成/编写 `agents/openai.yaml`。
- [x] 运行 `quick_validate.py` 并修复所有问题。

## Phase D — Architecture Navigation

- [x] 编写 `docs/architecture/system-map.md`。
- [x] 编写 `docs/architecture/change-routing.md`。
- [x] 编写 `docs/architecture/verification-matrix.md`。
- [x] 对所有入口、命令、排除目录和链接做源文件核验。

## Phase E — First-Principles Manual

- [x] 编写 `docs/ai-development-manual.md`。
- [x] 覆盖第一性原理、三档分流、文档职责、端到端使用流程。
- [x] 覆盖 Grill/Trellis/Spec/Task/CONTEXT/ADR/专项 Skill 的选择矩阵。
- [x] 给出 Fast Fix、普通功能、高风险支付退款、重复 Bug、架构决策和部署的提示词模板。
- [x] 增加维护节奏、反模式、常见故障和量化指标。
- [x] 从根 README 添加单一入口链接，避免文档不可发现。

## Phase F — Quality Gate

- [x] `python3 ./.trellis/scripts/get_context.py --mode packages`
- [x] `python3 ./.trellis/scripts/get_context.py --mode phase`
- [x] 验证全部 `.trellis/tasks/**/*.jsonl` 中 `file` 路径存在。
- [x] 运行 Skill quick validation。
- [x] 扫描新增 Spec/文档中无 TBD、占位符和断链。
- [x] 复查 Git diff，只包含本任务文件和任务开始前已存在的用户改动。
- [x] 按 `trellis-check` 做规格、复用和一致性检查。
