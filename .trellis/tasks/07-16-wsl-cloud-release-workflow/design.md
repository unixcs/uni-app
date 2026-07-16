# Design: WSL 本地验收与腾讯云上线工作流文档校正

## 1. 文档边界

采用“一份操作主文档 + 多个短入口/强制契约”的结构，避免把完整流程重复复制到所有文件：

1. 根目录新增 `WSL本地验证与腾讯云生产上线工作流.md`，作为日常开发者/操作者的端到端权威说明。
2. `README.md` 增加醒目入口，并把现有 WSL runtime 小节定位为专项说明而非完整上线流程。
3. `deploy/README.md` 保留生产发布器的权威合同，但在“日常发布”前半段加入 WSL 本地部署和人工验收门，并链接根文档。
4. `docs/ai-development-manual.md` 增加 AI 交付状态和授权门约束，防止以后再次把源码修改、Git push 和生产发布混为一谈。
5. `.trellis/spec/deployment/ops/index.md` 固化对未来编码代理的强制规则；详细命令仍由根工作流和 `deploy/README.md` 承担。

## 2. 状态模型

每个缺陷/功能按以下单向状态推进：

```text
source_changed
  → automated_checks_passed
  → wsl_runtime_updated
  → user_local_acceptance_passed
  → git_committed_and_pushed
  → production_dry_run_passed
  → production_authorized
  → production_released
  → production_verified
```

任何失败都回到拥有修复能力的前序状态。状态名不是新工具实现，只用于文档和沟通，防止“已改代码”被误报成“已上线”。

## 3. 三道授权门

| 门 | 允许的动作 | 不允许隐含的后续动作 |
|---|---|---|
| 开始修复/WSL 本地上线 | 默认允许本地构建、复制产物、修复缓存和必要的本地服务重启；本地数据库写入、清数据、`.env`/敏感配置修改需单独确认 | GitHub push、腾讯云操作 |
| Git 授权 | 精确 stage、commit、push GitHub | 腾讯云部署 |
| 生产授权 | 使用指定 confirmation token 发布/激活/回滚腾讯云 | 不替代前两门及 dry-run |

人工验收属于会改变运行行为的 WSL 门完成条件，且必须由用户明确回复通过；沉默、‘收到’或转入其他话题都不构成验收。AI review 和自动测试是必要质量证据，但不是用户验收本身。纯文档、纯测试、纯注释等无可部署运行行为的改动可记录豁免原因后跳过 WSL 部署/人工验收；边界不明时默认不豁免。

## 4. 环境与产物边界

- WSL 源码：`/opt/yoshop`。
- WSL Nginx root：`/opt/yoshop/yoshop2.0/public`。
- Merchant 源码/构建/运行：`yoshop2.0-store/src` → `yoshop2.0-store/dist` → `yoshop2.0/public/store`。
- Admin 源码/构建/运行：`yoshop2.0-admin/src` → `yoshop2.0-admin/dist` → `yoshop2.0/public/admin`。
- H5 构建产物进入 `yoshop2.0/public/index.html`、`config.js`、`assets/`；微信小程序 test build 只供 DevTools/体验，不等于 Web 或生产部署。
- PHP 源码由本地 PHP-FPM直接执行，但缓存、OPcache、Timer 长驻进程可能需要清理/重启。
- 数据库 migration 是独立状态变更；重启 Nginx/PHP-FPM不会执行 SQL。
- 生产只接收 release package；绝不直接 rsync WSL 工作区、数据库、uploads、`.env` 或支付材料。

## 5. 命令策略

- 只记录已由仓库或当前 WSL 环境证明的命令和路径。
- WSL Vue 2 构建优先使用 `NODE_OPTIONS=--openssl-legacy-provider ./node_modules/.bin/vue-cli-service build`，避免 package.json 中 Windows `SET` 语法在 Bash 下产生歧义。
- 静态发布使用 `rsync -a --delete <dist>/ <public-target>/`；提醒生成目录不进入 Git。
- ThinkPHP 缓存统一用 `scripts/repair-local-runtime.sh --clear-cache`，不记录裸删 runtime 的反模式。
- Nginx 仅在配置/证书变化时 `nginx -t` 后 reload；静态文件替换无需 reload。
- 数据库命令使用明确占位符并要求核对本地目标，不把真实凭据写入文档。
- 生产命令原样引用 `deploy/README.md` 的 guarded commands，不发明旁路。

## 6. WSL 无法完整复现的场景

对真实微信回调、生产域名/证书/白名单、生产密钥和云端 Timer 等场景，不把“必须本地完整通过”设计成不可满足条件。流程要求穷尽本地、体验版、沙箱和只读探测，形成明确的未验证清单；用户接受剩余风险后才可继续，生产发布后立即执行最小真实交易/回调/定时任务验证，并保持可回滚。

## 7. 紧急修复通道

紧急通道不是自动风险分类结果，而是用户针对正在造成重大生产影响的事件给出的显式授权。它只允许缩短完整人工验收等待，不取消最小自动检查、可行的 WSL 验证、回滚准备、Git 授权、生产授权和发布后立即验证；完成后必须补测和复盘。

## 8. 兼容与回滚

纯文档变更不改变现有程序行为。文档必须保持以下既有生产合同：

- 发布器只接受 clean、已 push 的 `main`；
- release 自动构建、打包、迁移、切换、reload 和健康检查；
- 代码 rollback 不反向撤销数据库 migration；
- migration 必须幂等且向前兼容；
- 生产状态以 `./deploy.sh status` 和公网 smoke 证据为准。

## 9. 取舍

不在本任务新增一键本地部署脚本。原因：不同变更面需要不同构建/迁移动作，先把人工合同校正清楚，后续再基于稳定合同设计脚本，避免把错误流程自动化。
