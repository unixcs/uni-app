# 技术设计：按风险路由的 AI 开发工作流

## 1. Problem Restatement

真正的问题不是 AI “不知道整个项目”，而是每次面对局部问题时，没有一个可靠且足够便宜的机制先确定最小调查边界，因而用全仓扫描来弥补不确定性。

## 2. Design Principles

### 2.1 Progressive Disclosure

上下文分四级按需展开：

1. **系统地图**：确定应用边界，成本最低。
2. **Package Spec index**：确定应读哪些规则。
3. **专题 Spec/架构流**：仅在命中领域时加载。
4. **源码与测试**：以精确搜索验证事实。

任何一级都不能默认把下一级全部载入。

### 2.2 Risk-Proportional Ceremony

流程分三档：

- **Fast Fix**：单 package、边界明确、低风险、预计 1–3 个源码文件；用户显式触发时可不建 Task。
- **Standard Task**：跨层、共享逻辑、契约变化或根因不确定；至少 PRD，复杂时 design/implement。
- **High-Risk Task**：支付、退款、状态机、权限、安全、数据库、生产和部署；完整规划、契约检查和回滚设计。

### 2.3 Evidence-Gated Expansion

Fast Fix 不是固定文件数限制，而是初始搜索预算。只有明确证据才能：

- 从一个 package 扩大到第二个 package；
- 从页面扩大到 API/后端；
- 从精确搜索扩大到目录级搜索；
- 从 Fast Fix 升级到 Trellis Task。

### 2.4 Non-Destructive Spec Migration

现有根级 Spec 已被活跃 Task 引用，因此采用桥接结构而非搬迁：

```text
.trellis/spec/
├── backend/                    # 既是 backend package 根，也是既有共享 backend 契约位置
│   ├── server/index.md         # 新 package/layer 入口
│   └── *.md                    # 原路径保留
├── frontend/                   # 既有共享前端规范库，路径保留
├── miniapp/frontend/index.md   # 链接所需共享规范/契约
├── merchant-console/frontend/index.md
├── admin-console/frontend/index.md
├── deployment/ops/index.md
└── guides/
```

这样同时满足：

- Trellis monorepo package discovery；
- 新任务按 package 路由；
- 旧 JSONL 不断链；
- 不复制大型 Spec；
- 后续可以按热点逐步将共享文档细分。

## 3. Package Model

| Package | Path | Layer | Role |
|---|---|---|---|
| backend | `yoshop2.0` | server | ThinkPHP/PHP API、业务服务、模型、回调和定时任务 |
| miniapp | `yoshop2.0-uniapp` | frontend | 微信小程序/H5、uni-app 页面、API、Vuex |
| merchant-console | `yoshop2.0-store` | frontend | 商家后台 Vue 2 应用 |
| admin-console | `yoshop2.0-admin` | frontend | 平台总后台 Vue 2 应用 |
| deployment | `deploy` | ops | 构建、发布、状态、回滚、环境和迁移工具 |

不设置 `default_package`，避免无 package Task 被静默误路由。配置 `session.spec_scope: active_task`；有 package 的 Task 只标记该 package 为范围，旧的无 package Task 保持全范围兼容。

## 4. Workflow Integration

修改 `.trellis/workflow.md` 的以下位置并保持一致：

1. Core Principles：加入“先路由后加载”和“风险决定流程”。
2. Request Triage：加入 Fast Fix 的必要/禁止条件。
3. `[workflow-state:no_task]`：用户明确 Fast Fix 时允许直接执行，不再反问是否建 Task。
4. Skill Routing：加入 `yoshop-fast-fix` 路由。

Fast Fix 不引入新 Task status，不改 Phase 1/2/3 状态机，因此不需要修改 continue 路由。

## 5. Skill Architecture

```text
.agents/skills/yoshop-fast-fix/
├── SKILL.md
├── agents/openai.yaml
└── references/
    ├── routing.md
    └── validation.md
```

`SKILL.md` 只放不可省略的执行算法和升级规则；目录细节、关键词、命令放 references，按需读取。

执行算法：

```text
工作区保护
→ 风险分级
→ package 路由
→ 精确搜索（预算）
→ 阅读最小依赖闭包
→ 最小修改
→ 最窄验证
→ 检查是否需要知识沉淀
```

## 6. Documentation Architecture

```text
docs/
├── architecture/
│   ├── system-map.md
│   ├── change-routing.md
│   └── verification-matrix.md
└── ai-development-manual.md
```

- `system-map.md`：稳定事实和入口。
- `change-routing.md`：症状到首查范围的操作索引。
- `verification-matrix.md`：验证强度和命令。
- `ai-development-manual.md`：面向用户的完整方法与操作说明。

领域词汇继续留在 `CONTEXT.md`；重大不可逆决策继续留在 `docs/adr/`；执行契约继续留在 `.trellis/spec/`。

## 7. Compatibility and Rollback

### Compatibility

- 不移动旧 Spec，避免 JSONL 失效。
- 不修改业务源码和构建脚本。
- 不设置默认 package，减少错误推断。
- workflow 只改变 no-task 路由，不改变 Trellis task 状态结构。

### Rollback

出现 Trellis package 解析问题时：

1. 删除/注释 `.trellis/config.yaml` 的 `packages` 与 `session.spec_scope`。
2. 删除新增 package index 目录。
3. 恢复 `.trellis/workflow.md` 的 Fast Fix 段落。
4. 项目业务代码和旧 Spec 不受影响。

## 8. Validation Strategy

- YAML：调用 `get_context.py --mode packages` 解析真实配置。
- Spec：逐个检查 package index 被发现；扫描全部 Task JSONL 文件路径是否存在。
- Skill：运行 `skill-creator/scripts/quick_validate.py`。
- Docs：检查 Markdown 相对链接、命令路径、manifest script 名称。
- Workflow：运行 phase context，确认 no-task breadcrumb 包含 Fast Fix，Planning/Execution 状态仍可解析。
- Git：比对修改文件，确保不覆盖任务开始前的用户改动。
