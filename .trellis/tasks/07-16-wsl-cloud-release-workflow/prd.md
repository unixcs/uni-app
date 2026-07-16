# 校正 WSL 本地验收与腾讯云上线工作流

## Goal

把当前分散在根 README、部署 README、AI 开发手册和 Trellis Spec 中的操作规则统一成一条可执行、不会混淆环境的开发运维流程：源码修改完成后必须先部署到 WSL 本地运行环境并由用户手工验收；验收通过后才允许提交和推送 GitHub；生产演练通过并再次取得独立授权后，才允许部署腾讯云。

## Background

- `README.md:80-101` 只说明 WSL runtime 修复和 PHP-FPM 重启，没有说明前端构建产物如何进入本地 Nginx 实际目录，也没有本地数据库迁移和人工验收门。
- `deploy/README.md:16-49` 已定义 Git preflight、生产 dry-run 和腾讯云发布，但当前顺序从“本地开发与测试”直接进入 Git 授权门，缺少“WSL 本地部署 → 用户验收”。
- `docs/ai-development-manual.md` 已定义任务风险、实现和检查流程，但没有明确区分“源码已修改”“WSL 本地已上线”“GitHub 已推送”“腾讯云生产已部署”四种状态。
- `.trellis/spec/deployment/ops/index.md` 要求区分本地和生产范围，但没有把 WSL 人工验收设为 GitHub push 之前的强制门禁。
- 当前环境中，本地 Nginx 的根目录是 `/opt/yoshop/yoshop2.0/public`；商家后台源构建到 `yoshop2.0-store/dist` 后，还必须同步到 `yoshop2.0/public/store` 才会在 WSL 本地站点生效。
- `wx.oiob.cn` 是 WSL 本地开发/体验域名；`wx.gxwqb.cn` 是腾讯云正式业务域名。

## Requirements

- R1：统一术语，明确：
  - “源码修改”发生在 WSL Git 工作区；
  - “WSL 本地部署/本地上线”指把构建产物、PHP 缓存状态和本地数据库变更应用到本地运行环境；
  - “Git 提交”是 WSL 本地 commit；
  - “GitHub 推送”是远端代码同步；
  - “生产部署”只指腾讯云 `wx.gxwqb.cn`，不得用于描述 WSL。
- R2：建立固定主流程：需求/缺陷 → 修改 → 自动检查 → WSL 本地部署 → 用户人工验收 → Git commit/push → production preflight/dry-run → 独立生产授权 → 腾讯云发布 → 生产验证/回滚。
- R3：明确授权边界：用户批准开始修复后，默认允许执行安全的 WSL 本地构建、静态产物替换、缓存修复和必要的本地服务重启；任何本地数据库写入、数据清理、`.env` 或敏感配置修改必须在说明具体影响后另行确认；GitHub 提交推送和腾讯云生产部署始终分别授权，后一道不得替代前一道。
- R4：提供按变更类型划分的 WSL 本地生效矩阵，至少覆盖 PHP、商家后台、总后台、H5/小程序、数据库迁移、Nginx 配置、`.env`/长驻 Timer，以及浏览器/登录权限刷新。
- R5：记录本地静态目录映射和可复制执行的 WSL 命令；说明前端 build 不是本地上线，Nginx reload 也不会自动 build 或执行 SQL。
- R6：本地数据库变更必须先确认目标是 WSL 本地库、按风险备份、执行正式 migration，并验证幂等或预期结果；禁止将本地数据库、uploads、支付材料同步到生产。
- R7：对会改变运行行为的页面、API、权限、数据库、配置、运行时、支付和订单状态改动，GitHub 推送前必须记录用户对 WSL 代表场景的明确验收结果；纯文档、纯测试、纯注释等无可部署运行行为的改动可在自动检查通过后直接进入 Git 授权门，但必须明确说明豁免原因；边界不明时默认要求 WSL 人工验收。未经用户明确回复本地测试通过，不得以沉默、‘收到’、自动测试或 AI review 代替必需的人工验收。
- R8：生产发布继续使用现有 guarded release：clean/pushed `main`、`preflight --fetch`、`release --fetch --dry-run`、独立 confirmation token、不可变 release、自动 migration/service reload/health check。
- R9：明确失败处理：本地验收失败返回源码修改阶段；Git/dry-run 失败不得申请生产授权；生产失败按现有 status/rollback 合同处理，数据库 migration 必须前向兼容。
- R9a：对微信真实支付/退款回调、腾讯云网络/证书/白名单、生产密钥、云端定时任务等 WSL 无法完整复现的行为，执行所有可行的本地/体验版/沙箱验证，并逐项列出未验证范围；只有用户明确接受剩余风险后才能进入 GitHub 和生产授权，生产发布后必须立即完成受控真实场景验证和回滚观察。不得以‘本地无法验证’为由跳过全部本地检查。
- R9b：允许用户为正在造成重大生产影响的事件显式启用紧急修复通道；AI 不得自行判定或因赶时间启用。紧急通道仍要求最小针对性检查、尽可能的 WSL 验证、预先回滚方案、独立 Git/生产授权和上线后立即验证；可缩短完整人工验收等待，但事后必须补测和复盘。
- R10：校正现有文档的入口与顺序，避免多份互相矛盾的流程；新增一份面向日常操作者的根目录完整工作流，并从现有 README/AI 手册/部署 Spec 链接到它。
- R11：只修改文档和 Trellis 任务/规范，不新增本地部署脚本，不执行本地数据库迁移、Git commit/push 或腾讯云操作。

## Acceptance Criteria

- [x] AC1：项目根目录存在一份完整的中文 WSL + 腾讯云开发运维工作流，并能从 `README.md` 找到。
- [x] AC2：文档明确写出 `wx.oiob.cn`/`wx.gxwqb.cn`、源码目录/本地 Nginx 运行目录，以及“生产部署只指腾讯云”。
- [x] AC3：端到端顺序中，WSL 本地部署与用户人工验收位于 Git commit/push 之前；生产 dry-run 和独立授权位于 GitHub push 之后。
- [x] AC4：文档提供 PHP、merchant/admin 前端、H5/小程序、数据库、Nginx、Timer/配置的本地生效动作和“何时不需要重启 Nginx”。
- [x] AC5：`deploy/README.md` 的日常发布章节不再从普通本地测试直接跳到 Git 授权门，并链接完整工作流。
- [x] AC6：`docs/ai-development-manual.md` 要求 AI 分别报告“源码修改完成、WSL 本地上线完成、GitHub 推送完成、腾讯云发布完成”，且不得混用。
- [x] AC7：`.trellis/spec/deployment/ops/index.md` 把 WSL 本地人工验收列为 GitHub push 和生产流程前的强制检查点。
- [x] AC8：所有修改后的 Markdown 链接可解析，关键术语和命令在相关文档间一致，`git diff --check` 通过。
- [x] AC9：文档明确运行行为改动的本地人工验收不能被 lint/build/test/review 替代；纯文档/测试/注释可说明理由后豁免；腾讯云生产授权不能由本地验收授权隐含获得。
- [x] AC10：工作流覆盖 WSL 无法完整复现的云端/第三方功能，要求列出未验证项、用户接受剩余风险和发布后受控真实验证。
- [x] AC11：本任务没有执行或声称执行 GitHub push、WSL 数据库写入或腾讯云部署。

## Out of Scope

- 自动实现一键 WSL 本地部署脚本。
- 修改现有生产发布器、Nginx 配置、systemd 服务或数据库 migration。
- 实际部署当前权限/版权修复到 WSL 或腾讯云。
- 改变 Trellis 的通用任务创建/激活协议；本任务只补充本项目的交付与发布门禁。
