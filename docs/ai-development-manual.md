# YoShop AI 开发完整使用说明书

> 目标：让 AI 在这个成熟的微信商城二开项目中，以最小必要上下文完成安全改动。核心不是让 AI 每次“读懂整个项目”，而是让它先准确路由、按风险加载、用证据扩大范围。

## 1. 第一性原理

### 1.1 AI 为什么会全盘扫描

AI 收到“退款按钮有问题”时，缺少以下事实：

- 哪个端：小程序、商家后台还是总后台；
- 哪个层：页面、API、权限还是后端状态；
- 哪些目录是源码，哪些是构建产物；
- 这是样式问题还是支付/退款契约问题；
- 改完应运行什么验证。

在没有低成本路由信息时，全仓搜索是模型降低不确定性的自然策略。解决办法不是命令 AI “不要搜索”，而是提供一条更便宜、更可靠的求证路径。

### 1.2 文档为什么可能增加 Token

文档只有在满足三点时才节省上下文：

1. 有清晰入口；
2. 能按 package/领域选择；
3. 只包含稳定、高信号信息。

如果每次自动加载所有 README、Task、Spec 和历史聊天，文档越多，成本越高。因此本项目采用渐进式披露：

```text
系统地图 → 一个 package Spec index → 一个专题契约 → 精确源码/测试
```

### 1.3 流程为什么必须按风险分级

“一行 CSS”和“退款状态机”都修改文件，但失败后果完全不同。流程强度应由以下变量决定：

- 影响范围；
- 可逆性；
- 是否改变跨层契约；
- 是否触及钱、权限、数据和生产；
- 根因确定程度。

因此本项目使用 Fast Fix、Standard Task、High-Risk Task 三档，而不是所有改动都走同一流程。

### 1.4 四种事实载体

- **源码/测试**：当前实现事实，最终权威。
- **架构地图**：告诉 AI 去哪里找，不复制实现。
- **Trellis Spec**：告诉 AI 找到后必须遵守什么可执行契约。
- **Task/聊天历史**：记录当时怎么工作，不自动代表当前事实。

## 2. 项目知识体系

| 载体 | 解决的问题 | 不应该放什么 |
|---|---|---|
| `.trellis/tasks/<task>/prd.md` | 这次要达到什么结果 | 技术实现清单 |
| `design.md` | 复杂任务如何设计、权衡和回滚 | 产品需求堆叠 |
| `implement.md` | 实施顺序和验证门 | 长期项目规范 |
| `.trellis/spec/` | 当前有效的代码契约、约束、错误矩阵 | 一次性任务细节 |
| `docs/architecture/` | 应用边界、入口、路由和验证导航 | 大段易过期实现复制 |
| `CONTEXT.md` / `CONTEXT-MAP.md` | 领域词汇和有界上下文 | 类名、文件路径等实现细节 |
| `docs/adr/` | 难逆转、反直觉、有真实权衡的决策 | 普通小选择和工作日志 |
| `.trellis/tasks/archive/` | 历史工作证据 | 每次开发默认加载的知识库 |
| `deploy/README.md` / `$yoshop-deploy` | 发布与回滚操作契约 | 普通业务开发说明 |

原则：一个事实只设一个权威来源，其他文档链接过去。

## 3. 三档工作流

### 3.1 Fast Fix：显式低风险快速修复

必须同时满足：

- 用户明确说“快速修复 / 小改动 / 不建任务 / fast fix”；
- 能确定一个 package；
- 修改局部、可逆、不改契约；
- 预计 1–3 个源码文件；
- 不涉及支付、退款、订单状态、权限、安全、数据库、数据修复、生产或部署。

执行：

```text
检查 git 状态
→ 选一个 package
→ 读一个 package Spec index
→ 最多先做三次精确搜索
→ 读最小依赖闭包
→ 最小修改
→ 最窄验证
→ 报告证据和未做验证
```

Fast Fix 不是“无脑快改”。发现 API 契约、后端权限、支付状态或根因不明时，必须停止并升级。

### 3.2 Standard Task：普通 Trellis 任务

适用于：

- 跨前后端或多个文件；
- 共享组件/逻辑；
- API 字段、路由、权限或枚举变化；
- 根因需要调查；
- 修改可验证但不属于高风险资金/生产流程。

轻量任务可以只有 `prd.md`；复杂任务要有 `prd.md + design.md + implement.md`。

新建时应指定 package：

```bash
python3 ./.trellis/scripts/task.py create "任务标题" \
  --slug concise-slug \
  --package miniapp
```

可用 package：`backend`、`miniapp`、`merchant-console`、`admin-console`、`deployment`。真正跨 package 的任务可不指定，但必须在设计和实施计划中列明所有影响边界。

### 3.3 High-Risk Task：完整规划和契约门禁

以下默认属于高风险：

- 微信虚拟支付、普通支付；
- 退款、Apple 问询、回调、幂等；
- 订单/服务状态机；
- 权限、安全、凭证；
- 数据库迁移或生产数据修复；
- 生产配置、发布、回滚。

必须包含：需求验收、技术设计、兼容/回滚、实施清单、相关 Spec、契约测试和完整检查。不得因为改动文件少就降级成 Fast Fix。

## 4. 每次请求怎么说

好的提示词应提供“症状 + 期望 + 已知边界 + 风险授权”，不必先告诉 AI 技术答案。

### 4.1 小按钮/样式

```text
使用 $yoshop-fast-fix，不创建 Trellis 任务。
商家后台订单详情页的“复制订单号”按钮和左侧文字太近，期望增加 8px 间距。
先只在 merchant-console 范围定位；除非有明确证据，不要搜索后端和其他应用。
修改后做最窄 lint/页面验证，并说明未执行的人工验证。
```

### 4.2 明确的小程序局部 Bug

```text
使用 $yoshop-fast-fix，不建任务。
小程序用户页在昵称为空时显示 undefined，期望显示“未设置”。
只从 miniapp 的页面文案和字段消费点开始；如果需要改 API 字段就停止并建议升级任务。
```

### 4.3 普通跨层功能

```text
这是 Standard Trellis Task。请先创建任务并进入规划。
商家订单列表增加“服务中”筛选，需要核对前端筛选值和后端查询语义。
先写 PRD；如果确认跨前后端契约，再补 design 和 implement，评审后实施。
```

### 4.4 支付/退款

```text
这是高风险虚拟支付任务，不使用 Fast Fix。
使用 Trellis 完整规划，并加载 virtual-payment Skill 和现有虚拟支付/服务订单 Specs。
先列出上游微信所有权、本地状态机、幂等、回滚和验证矩阵，再进入实施。
```

### 4.5 重复 Bug

```text
这个问题以前修过。先查当前 Task/Spec；如果可用，再使用 trellis-session-insight 检索历史决策。
修复后运行 trellis-break-loop，把可复用的根因和预防机制更新到 Spec，而不是只留在聊天里。
```

### 4.6 架构/术语讨论

```text
使用 grill-with-docs，只聚焦“服务订单与支付交易”的领域边界。
逐个问题确认术语；实现细节从源码核对，但不要写进 CONTEXT.md。
只有满足难逆转、反直觉、有真实权衡时才提出 ADR。
```

### 4.7 部署

```text
使用 $yoshop-deploy。先做本地状态和 preflight，不执行生产发布。
列出 dry-run、生产确认门和回滚路径，等待我明确授权后再进行远端动作。
```

## 5. 工具和 Skill 选择

| 场景 | 首选能力 | 产物/结果 |
|---|---|---|
| 明确低风险局部改动 | `yoshop-fast-fix` | 最小 patch + 窄验证 |
| 需求不清或多方案 | `grill-me` / `trellis-brainstorm` | 澄清结论 / PRD |
| 领域术语和重大决策 | `grill-with-docs` | CONTEXT、必要 ADR |
| 成熟代码反向提炼规范 | `trellis-spec-bootstrap` | source-backed Spec |
| 开发前加载局部规范 | `trellis-before-dev` | 相关 Spec 上下文 |
| 实施后质量门 | `trellis-check` | 规格、lint、test、跨层检查 |
| 重复缺陷治理 | `trellis-break-loop` | 根因、防复发机制、Spec |
| 把新契约固化 | `trellis-update-spec` | 可执行 Spec 更新 |
| 回忆过去讨论 | `trellis-session-insight` | 历史对话原料；当前 CLI/PATH 需先修复 |
| Web/H5/后台 UI 复现 | `browser` | DOM、点击、截图、控制台证据 |
| 微信虚拟支付 | `virtual-payment` | 上游接入和错误码知识 |
| 生产发布/回滚 | `yoshop-deploy` | 受保护的部署流程 |

`grill-with-docs` 不替代代码地图；`trellis-spec-bootstrap` 不替代需求澄清；浏览器 H5 验证不替代微信开发者工具。

## 6. AI 定位标准操作

### Step 1：保护工作区

```bash
git status --short
```

先记录已有修改。禁止为了“干净”而 reset、checkout、clean 或覆盖不属于当前任务的文件。

### Step 2：确定 package

```bash
python3 ./.trellis/scripts/get_context.py --mode packages
```

只读目标 package 的 index。不要因为列表显示五个 package 就把五个 index 全部读完。

### Step 3：精确搜索

优先级：文案/错误码 → 路由/API → 方法/字段/枚举 → 直接依赖。示例：

```bash
rg -n -F '订单详情' yoshop2.0-store/src/views
rg -n 'service_status' yoshop2.0-uniapp/pages/order yoshop2.0-uniapp/api
```

排除依赖、构建产物、runtime、uploads、deploy/out/reports 和归档 Task。完整边界见 [系统地图](architecture/system-map.md)。

### Step 4：阅读最小依赖闭包

通常只需：

- 命中文件；
- 它直接调用的 API/组件；
- 同字段/常量的局部使用点；
- 最近的相关测试。

只有证据要求时才扩大。

### Step 5：最小一致修改

修改必须完整解决目标，但不要顺手重构无关代码。生成目录不能作为源码修改。

### Step 6：风险匹配验证

使用 [验证矩阵](architecture/verification-matrix.md)。报告：运行了什么、结果是什么、为什么足够、还需要什么人工验证。

## 7. Trellis 生命周期

### 7.1 开始会话

AI 应运行 `trellis-start` 等价上下文：当前任务、Git 状态、Phase、packages、相关 Spec index。

### 7.2 创建任务

只有获得创建授权后创建。复杂任务先规划，创建 Task 不等于允许实施。

### 7.3 规划

- `prd.md`：目标、约束、验收。
- `design.md`：边界、数据流、权衡、兼容、回滚。
- `implement.md`：有序步骤、验证命令、检查门。

事实能从仓库获得就直接调查；只向用户询问产品意图、范围和风险偏好。

### 7.4 激活与实施

计划评审后运行 `task.py start`。实施前加载 `trellis-before-dev`，只读相关 package Spec 和专题契约。

### 7.5 检查与沉淀

完成后运行 `trellis-check`。只有形成长期可复用知识时更新 Spec/架构/CONTEXT/ADR；不要把每个小修复都写成文档。

### 7.6 收尾

确认验证、提交边界、任务状态和归档。活跃任务应代表真实工作队列，已完成任务不要长期留在 `in_progress`。

## 8. Grill 与 Trellis 如何衔接

### `grill-me`

用于压力测试需求或设计，只输出更清晰的共同理解。适合新功能、多方案、边界不明。一个问题一次问。

### `grill-with-docs`

在 Grill 基础上维护领域模型：

- 术语确定后立即写入 CONTEXT；
- 实现细节只用于核对，不进入词汇表；
- ADR 只在“难逆转 + 反直觉 + 有真实权衡”同时成立时创建。

成熟项目适合按有界上下文逐步逆向，不适合一次采访整个商城。

### 交接给 Trellis

领域/需求结论稳定后：

1. 创建/更新 Task PRD；
2. 技术复杂则写 design/implement；
3. 将长期契约链接到 Spec；
4. 激活任务后实施和检查。

## 9. 知识沉淀决策树

```text
这条知识下次还会影响实现吗？
├─ 否 → 留在 Task/提交记录
└─ 是
   ├─ 是业务词义/边界 → CONTEXT
   ├─ 是代码契约/错误矩阵 → .trellis/spec
   ├─ 是稳定导航/入口 → docs/architecture
   ├─ 是难逆转架构决策 → ADR
   └─ 是操作流程 → 对应 Skill/README
```

完成 Task 后，不要机械地把聊天摘要复制进所有位置。

## 10. 维护制度

### 每次小改动

- 不建文档，除非发现路由错误或可复用 gotcha。
- 保持 patch 和验证最小。

### 每个 Standard/High-Risk Task

- 收尾时判断 Spec 是否需要更新；
- 新入口/边界变化才更新架构地图；
- 新领域词义才更新 CONTEXT；
- 关闭并归档真实完成的 Task。

### 每月或连续多次任务后

- 检查活跃 Task 是否陈旧；
- 检查架构链接和验证命令；
- 合并重复 Spec，删除过时入口；
- 抽样观察 Fast Fix 是否经常错误升级或漏升级。

### 大版本/架构变化后

运行范围明确的 `trellis-spec-bootstrap`，先刷新受影响 package，不默认重扫全仓。

## 11. 反模式

禁止或警惕：

- 用户说“小问题”就无条件当 Fast Fix；
- 从仓库根目录 `find .` / `grep -R`；
- 默认读取所有 Task 和所有 Spec；
- 把 `dist`、`public/store`、`public/admin` 当源码；
- 为一个 CSS 改动创建完整 ADR/设计文档；
- 把支付/退款改动因为“只有两行”降级；
- 让 `CONTEXT.md` 充满类名和文件路径；
- 把历史 Task 当当前契约；
- 只跑 build，不验证用户场景；
- 在脏工作区中回滚或覆盖用户已有修改。

## 12. 故障排查

### AI 仍然全盘扫描

检查：

1. 提示词是否明确 package 或使用 Fast Fix；
2. `get_context.py --mode packages` 是否能发现 package；
3. package index 是否过长或链接过多；
4. 搜索是否从精确文案/路由开始；
5. AI 是否把“验证同字段”误解成“搜索整个仓库”。

### AI 对小改动仍要求建 Task

显式说：

```text
使用 $yoshop-fast-fix，不创建 Trellis 任务；若触发升级条件再停下来说明。
```

没有显式 Fast Fix 时，workflow 仍会询问是否创建 Task，这是安全默认值。

### AI 错把高风险任务当 Fast Fix

指出触发项（支付/退款/权限/DB/生产/跨包/根因不明），立即停止修改并创建 Trellis Task。不要只改提示词继续做。

### `trellis-session-insight` 不工作

当前环境曾出现 `trellis: command not found`。先修复 CLI 安装或 PATH；不要假装已经检索历史。代码事实优先用 `git log`、`rg` 和源码验证。

### Package Task 没有收窄

检查 Task 的 `task.json.package`。旧任务没有 package 时，为兼容会看到全部 package；新任务应使用 `task.py create ... --package <name>`。

## 13. 衡量是否真的省 Token

建议连续记录 10–20 次小改动：

| 指标 | 目标方向 |
|---|---|
| 首次命中相关源码前的命令数 | 下降；通常不超过 5 |
| 初始读取的 package Spec index 数 | Fast Fix 为 1 |
| 是否出现根目录无边界扫描 | Fast Fix 为 0 |
| 修改源码文件数 | 与问题边界一致，通常 1–3 |
| Fast Fix 升级率 | 有风险时正常升级；不是越低越好 |
| 回归/返工次数 | 不因减少扫描而上升 |
| 文档默认加载量 | 下降，专题契约按需加载 |

真正的成功标准不是“搜索得少”，而是：**以更少的无关上下文获得同等或更高的正确率。**

## 14. 快速入口

- [系统地图](architecture/system-map.md)
- [变更路由](architecture/change-routing.md)
- [验证矩阵](architecture/verification-matrix.md)
- `$yoshop-fast-fix`
- `.trellis/workflow.md`
- `python3 ./.trellis/scripts/get_context.py --mode packages`
