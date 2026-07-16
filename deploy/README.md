# YoShop 可持续部署

## 核心原则

代码和线上数据不是一类东西，必须分开：

- WSL Git `main` 只管理业务源码、锁文件、构建/部署脚本。
- 本地完成 PHP vendor、H5、超管后台、商家后台的全部构建。
- 腾讯云只接收有清单和 SHA-256 的发布包，不依赖 GitHub、npm、Composer。
- 每版代码进入 `/srv/yoshop/releases/<release-id>`，`current` 原子切换。
- 线上 `.env`、uploads、payment、runtime、数据库在 `shared/`/MySQL 中，发布永不覆盖。
- `wx.oiob.cn` 永远用于本地开发/体验；`wx.gxwqb.cn` 永远用于正式业务。

## 日常发布

> 日常交付的完整端到端流程见 [`WSL本地验证与腾讯云生产上线工作流.md`](../WSL本地验证与腾讯云生产上线工作流.md)。本文件从生产发布器角度补充权威命令；“生产部署”只指腾讯云 `wx.gxwqb.cn`。

### 1. 开发与自动检查

在 WSL Git 工作区修改源码，按变更风险运行 lint、测试、build 和 Review。小程序测试构建是独立动作：

```bash
npm --prefix yoshop2.0-uniapp run build:mp-weixin:test
```

它只生成微信开发者工具/HBuilderX 测试产物，不部署 WSL Web，也不部署腾讯云。

### 2. WSL 本地部署与用户验收门

对有运行行为的改动，必须把相关产物、缓存状态和获批的本地 migration 应用到 WSL 实际运行环境，再由用户通过 `wx.oiob.cn` 或本地入口手工验收。典型边界：

- `yoshop2.0-store/dist` 必须同步到 `yoshop2.0/public/store`，单独 build 不算本地上线；
- `yoshop2.0-admin/dist` 必须同步到 `yoshop2.0/public/admin`；
- PHP 按需使用 `scripts/repair-local-runtime.sh --clear-cache` 并 restart PHP-FPM；
- 数据库 migration 必须先说明影响并取得本地数据库写入确认；
- 静态产物替换和 SQL 不需要 reload Nginx，只有 Nginx 配置/证书变化才 `nginx -t` 后 reload；
- 权限类改动需要账号退出并重新登录。

AI 必须明确报告“WSL 本地上线完成，尚未 GitHub push，尚未部署腾讯云”，列出本地地址和人工验收清单。只有用户明确回复本地测试通过，才进入 Git 授权门；自动测试、build 和 AI review 不能替代人工验收。

纯文档、纯测试、纯注释等没有可部署运行行为的改动，可记录豁免原因后直接进入 Git 授权门。微信真实回调、生产证书/白名单、生产密钥等 WSL 无法完整复现的场景，必须完成所有可行的本地/体验版/沙箱验证并列出剩余风险，由用户明确接受后才能继续。

### 3. Git 授权门

WSL 验收通过后，审查并精确提交业务代码，再推送原 GitHub `main`。不得使用宽泛 stage 混入其他任务、生成物、runtime、uploads、`.env` 或密钥。

部署程序拒绝脏工作区、未推送提交和被 Git 追踪的构建/上传文件：

```bash
./deploy.sh preflight --fetch
```

本地验收授权不包含 Git push；Git push 授权也不包含腾讯云生产发布。

### 4. 生产演练、独立授权与一键发布

GitHub 推送完成后，先做无破坏演练：

```bash
./deploy.sh release --fetch --dry-run
```

检查 release ID、commit、manifest、SHA-256、migration 和静态产物。演练通过后必须再次取得腾讯云生产授权，才可执行：

```bash
./deploy.sh release --fetch \
  --confirm-production DEPLOY-wx.gxwqb.cn
```

该命令完成本地构建、扫描、打包、rsync、数据库安全点/未应用 migration、原子切换、服务 reload/restart 和健康检查。不得用手工 Git pull、rsync 或单独重启服务旁路发布器。

也可将 build/deploy 拆开审查包的 ID、大小与 SHA-256：

```bash
./deploy.sh build --fetch
./deploy.sh deploy deploy/out/yoshop-<release-id>.tar.gz --dry-run
./deploy.sh deploy deploy/out/yoshop-<release-id>.tar.gz \
  --confirm-production DEPLOY-wx.gxwqb.cn
```

### 5. 首次切换：候选准备与显式激活

首次切换时，维护 Nginx 会让公开健康检查返回 503，因此不要使用日常 `deploy`/`release`
直接安装。先只上传、解包、校验并封存候选；此动作不切换 `current`、不执行迁移、
不重启或 reload 任何服务：

```bash
./deploy.sh build --fetch
./deploy.sh prepare deploy/out/yoshop-<release-id>.tar.gz \
  --confirm-production PREPARE-wx.gxwqb.cn
./deploy.sh status
```

`status` 必须显示 `prepared.state=prepared`、正确的 release ID、`verified=true`，同时
`current` 仍保持原值（第一次上线为 `null`）。随后完成人工数据导入、共享 `.env`/uploads/
payment/runtime 检查和候选内部检查。候选目录为 root 所有、不可由服务用户写入；激活前
会再次核对状态、清单、文件 SHA-256、权限和共享链接。

当前维护 vhost 没有候选私有健康路由，而激活绝不跳过公开健康检查。因而首次切换必须
使用已审批的人工变更单：先准备好恢复维护 vhost 的回退步骤并执行 `nginx -t`，再人工
切到业务 vhost 并 reload Nginx，然后立即显式激活：

```bash
./deploy.sh activate <release-id> \
  --confirm-production ACTIVATE-wx.gxwqb.cn
```

脚本不会修改 Nginx 配置。激活会执行迁移、原子切换、重启以及本机 Host-header 和公网
HTTPS 健康检查；任何失败都会恢复旧 `current`。首次激活没有旧版本时会删除 `current`
并停止 Timer，保持 fail-closed；值班人员仍须立即人工恢复维护 vhost。维护配置返回 503
时调用 `activate` 必然失败，不得为通过而关闭健康检查。

### 6. 状态、生产验证和回滚

```bash
./deploy.sh status
./deploy.sh rollback \
  --confirm-production ROLLBACK-wx.gxwqb.cn
```

发布成功不能只看命令退出码：还要核对 `current` release/commit、实际公网 HTML/JS bundle、migration 记录、PHP-FPM/Nginx/Timer、健康检查和生产账号代表场景。生产账号权限变更后应退出并重新登录。

回滚需要独立授权，只切回上一版代码，不自动反向修改数据库。增量 SQL 必须向前兼容。
回滚会交换 `current` 与 `previous`；若确认要回到刚才的新版本，应在再次授权后再执行一次
`rollback`，不要重新构建/安装同一提交。相同提交使用相同 release ID，已存在的不可变
release 会拒绝重复安装；只有新提交才走新的日常 `release`。

## 服务器固定目录

```text
/srv/yoshop/
├── current -> releases/<release-id>
├── releases/
├── incoming/
├── state/
│   └── prepared.json  # 仅存在一个已校验、待激活候选时存在
└── shared/
    ├── .env
    ├── uploads/
    ├── payment/
    ├── runtime/
    ├── backups/
    ├── logs/
    ├── mysql-client.cnf
    └── db-name
```

远端固定执行器是 `/usr/local/sbin/yoshop-release`。`deployer` 只能通过受限
sudo 调用这个执行器，不能靠脚本把本地配置覆盖到线上。

## 首次上线与后续上线的区别

首次上线由 Trellis 基础设施/数据切换任务创建用户、目录、数据库、生产 `.env`、
Nginx/systemd，并初始化筛选后的商品/装修/图片。以后只运行上述一键发布；不得再次
导入本地数据库，也不得同步本地 uploads 或支付证书。

更完整的安全边界和检查项见 `$yoshop-deploy` Skill 与 `deploy/ops-support.md`。
