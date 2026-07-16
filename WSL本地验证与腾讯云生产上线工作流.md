# YoShop WSL 本地验证与腾讯云生产上线工作流

> 本文是日常缺陷修复和功能交付的端到端操作入口。生产发布器的详细合同仍以 [`deploy/README.md`](deploy/README.md) 为准，WSL runtime 权限以 [Runtime Ownership Contract](.trellis/spec/backend/runtime-ownership-contract.md) 为准。

## 1. 先分清四件事

| 状态 | 含义 | 是否代表用户已能看到 |
|---|---|---|
| 源码修改完成 | `/opt/yoshop` 工作区中的源文件已修改 | 否 |
| WSL 本地上线完成 | 构建产物、本地缓存和获批的本地数据变更已进入 WSL 运行环境 | 仅本地可见 |
| GitHub 推送完成 | 已 commit 并 push 到远端 `main` | 不代表任何运行环境已更新 |
| 腾讯云生产发布完成 | 新 release 已在腾讯云激活并通过生产验证 | 生产用户可见 |

不得把其中任意一种状态简称成另一种。尤其是：

- `build` 成功不等于 WSL 本地上线；
- 重启 Nginx 不会自动编译 Vue，也不会执行 SQL；
- push GitHub 不等于腾讯云已经部署；
- 本文中的“生产部署”只指腾讯云 `wx.gxwqb.cn`，不指 WSL。

## 2. 环境和目录

| 用途 | 地址/目录 |
|---|---|
| WSL 源码工作区 | `/opt/yoshop` |
| WSL 本地开发/体验域名 | `wx.oiob.cn`（也可按本机配置使用 `localhost`） |
| WSL Nginx Web 根目录 | `/opt/yoshop/yoshop2.0/public` |
| 腾讯云正式业务域名 | `wx.gxwqb.cn` |
| 腾讯云当前 release | `/srv/yoshop/current` → `/srv/yoshop/releases/<release-id>` |

主要前端的源码、构建和 WSL 运行目录：

```text
商家后台：yoshop2.0-store/src
       → yoshop2.0-store/dist
       → yoshop2.0/public/store

总后台：  yoshop2.0-admin/src
       → yoshop2.0-admin/dist
       → yoshop2.0/public/admin

H5：      yoshop2.0-uniapp
       → yoshop2.0-uniapp/dist/build/h5
       → yoshop2.0/public/index.html、config.js、assets/
```

`dist/` 和 `yoshop2.0/public/store|admin|assets` 是生成/运行产物，不是业务源码，不得提交 Git。

## 3. 固定主流程

```text
需求或 Bug
  → 保护工作区并定位根因
  → 修改源码和测试
  → 自动检查 / Review
  → 部署到 WSL 本地运行环境
  → 用户手工验收 WSL 代表场景
  → 精确 Git commit / push GitHub
  → production preflight / dry-run
  → 用户独立授权腾讯云生产发布
  → 腾讯云发布
  → 生产验证和观察
  → 必要时回滚
```

失败处理：

- 自动检查失败：继续修改，不能进入 WSL 验收；
- WSL 验收失败：返回源码修改，不得推送 GitHub；
- Git/preflight/dry-run 失败：修复后重新验证，不得申请生产授权；
- 生产验证失败：停止继续操作，检查 `status`，按既有回滚合同处理。

## 4. 授权边界

### 4.1 用户批准开始修复后，默认允许的本地动作

为了形成可测试版本，AI 可以执行安全、可逆的 WSL 本地动作：

- 运行 lint、测试和 build；
- 将新静态产物替换到 WSL 本地 `public/` 对应目录；
- 使用项目 helper 修复/清理本地生成缓存；
- 为使改动生效而重启必要的本地 PHP-FPM/Timer，或在配置检查通过后 reload 本地 Nginx；
- 进行不改变数据的只读检查和本地 HTTP smoke。

这些动作不授权 GitHub push，也不授权腾讯云操作。

### 4.2 必须说明影响并另行确认的动作

以下动作即使只在 WSL，也必须先说明目标、影响和回退方法：

- 写入或删除本地数据库数据、执行 migration；
- 数据清理、批量修复；
- 修改 `.env`、密钥、证书或支付配置；
- 可能破坏本地用户数据、uploads 或运行状态的操作。

### 4.3 Git 和生产始终分别授权

1. 用户明确回复 WSL 手工验收通过后，才进入 Git commit/push 授权门。
2. GitHub 推送完成且 production dry-run 通过后，才进入腾讯云生产授权门。
3. 本地验收授权、Git 授权、生产授权互不包含；不能用“ok”自动推导后续高风险授权。

## 5. 开发和自动检查

### 5.1 开始前保护工作区

```bash
cd /opt/yoshop
git status --short --branch
```

记录已有修改。不得为获得“干净工作区”而 reset、checkout、clean 或覆盖其他任务文件。

### 5.2 按风险执行检查

最低要求以 [`docs/architecture/verification-matrix.md`](docs/architecture/verification-matrix.md) 和相关 Trellis Spec 为准。常用检查包括：

```bash
# PHP：每个变更文件
php -l <changed.php>

# 商家后台
npm --prefix yoshop2.0-store run lint:nofix

# 部署 Python 测试
python3 -m unittest discover -s deploy/tests -p 'test_*.py'

# 工作树空白错误
git diff --check
```

构建、lint、测试和 AI review 是进入人工验收的质量证据，但不能代替用户实际操作验收。

## 6. 部署到 WSL 本地运行环境

只执行本次变更涉及的行，不需要每次重建所有端。

| 变更类型 | WSL 本地生效动作 | 是否需要 Nginx reload |
|---|---|---|
| PHP 请求代码 | 源码已在本地；按需清 ThinkPHP 缓存并 restart PHP-FPM | 否 |
| 商家后台 Vue | build `yoshop2.0-store`，同步到 `public/store` | 否 |
| 总后台 Vue | build `yoshop2.0-admin`，同步到 `public/admin` | 否 |
| H5 | build H5，同步入口、`config.js` 和 `assets/` | 否 |
| 微信小程序 | 构建 test 产物，在微信开发者工具/体验版验证 | 否 |
| 数据库权限/结构/数据 | 获批后对 WSL 本地库执行 migration 并核验 | 否 |
| Nginx 配置/证书 | `nginx -t` 成功后 reload | 是 |
| `.env`/PHP 配置 | 获批后修改；按消费者 restart PHP-FPM/Timer | 通常否 |
| Timer 长驻代码 | restart 本地 `yoshop2.0-timer.service` | 否 |
| 登录权限/前端缓存 | 用户退出重登、浏览器硬刷新 | 否 |

### 6.1 商家后台

WSL Bash 不使用 `package.json` 中的 Windows `SET` 写法，直接调用本地 Vue CLI：

```bash
cd /opt/yoshop/yoshop2.0-store
NODE_OPTIONS=--openssl-legacy-provider \
  ./node_modules/.bin/vue-cli-service build

rsync -a --delete \
  /opt/yoshop/yoshop2.0-store/dist/ \
  /opt/yoshop/yoshop2.0/public/store/
```

然后检查本地入口和实际 bundle，而不只检查 `dist`：

```bash
curl -fsSI http://localhost/store/
```

### 6.2 总后台

```bash
cd /opt/yoshop/yoshop2.0-admin
NODE_OPTIONS=--openssl-legacy-provider \
  ./node_modules/.bin/vue-cli-service build

rsync -a --delete \
  /opt/yoshop/yoshop2.0-admin/dist/ \
  /opt/yoshop/yoshop2.0/public/admin/
```

### 6.3 PHP 后端和 ThinkPHP 缓存

PHP-FPM 直接执行 WSL 工作区内的 PHP 源码。只有缓存、OPcache 或生成 schema 使旧行为残留时，才清本地生成缓存并重启 PHP-FPM：

```bash
/opt/yoshop/scripts/repair-local-runtime.sh --clear-cache
sudo systemctl restart php8.3-fpm
```

不要直接删除整个 `runtime`。如果 Timer 也加载了变更代码，再执行：

```bash
sudo systemctl restart yoshop2.0-timer.service
```

### 6.4 H5 和微信小程序

H5 变更使用仓库已有的 build + sync helper，一次完成构建和 WSL 本地 `public/` 同步：

```bash
cd /opt/yoshop
npm --prefix yoshop2.0-uniapp run build:h5:sync
```

微信小程序 test 构建：

```bash
npm --prefix yoshop2.0-uniapp run build:mp-weixin:test
```

小程序产物需要在微信开发者工具/体验版验证；生成 test 产物不代表腾讯云 Web 或生产已发布。

### 6.5 本地数据库 migration

数据库写入必须先得到单独确认。执行前至少完成：

1. 从 `yoshop2.0/.env` 或批准的本地 client 配置核对 host、database；
2. 明确证明目标不是腾讯云生产数据库；
3. 按风险创建本地备份；
4. 执行 `deploy/migrations/NNNN_description.sql`，不要复制一段临时 SQL 绕过正式 migration；
5. 查询 migration 预期行数和权限链；需要幂等时在临时副本先连续执行两次；
6. 让依赖登录权限的账号退出并重新登录。

示例只使用本地 client 文件占位符，不把凭据写入命令历史或文档：

```bash
mysql --defaults-extra-file=<local-mysql-client.cnf> \
  --batch --skip-column-names <local_database> \
  -e 'SELECT DATABASE(), @@hostname'

mysqldump --defaults-extra-file=<local-mysql-client.cnf> \
  --single-transaction <local_database> \
  > <local-backup.sql>

mysql --defaults-extra-file=<local-mysql-client.cnf> \
  <local_database> \
  < deploy/migrations/NNNN_description.sql
```

不得把 WSL 数据库、uploads、`.env`、支付证书或密钥同步到腾讯云。

### 6.6 Nginx 和配置

静态文件替换、PHP 源码修改和数据库 migration 都不需要 reload Nginx。只有修改 Nginx 配置或证书时执行：

```bash
sudo nginx -t
sudo systemctl reload nginx
```

修改 `.env` 后只重启真正读取该配置的进程。PHP-FPM 和 Timer 可能需要 restart；Nginx 自身配置没变就不用重启。

## 7. 用户 WSL 手工验收门

AI 完成本地部署后必须明确报告：

```text
状态：WSL 本地上线完成，尚未提交/推送 GitHub，尚未部署腾讯云。
本地地址：http://wx.oiob.cn/...
本次运行目录：...
本次本地 migration：未执行 / 已获批并执行 ...
自动检查：...
请验证：...
```

用户应按代表场景检查：

- 修复前的复现步骤现在通过；
- 正常基础路径没有回归；
- 权限类变更同时检查按钮显隐和直接 API 授权；
- 数据库变更检查目标角色/店铺以及未授权角色；
- 前端变更检查浏览器硬刷新后的实际 bundle；
- 支付/订单变更按专题契约执行测试矩阵。

只有用户明确回复“WSL 本地测试通过，可以提交推送”或等价表达，才算通过。沉默、“收到”、切换话题、自动测试通过或 AI review 都不构成人工验收。

### 可豁免场景

纯文档、纯测试、纯注释等没有可部署运行行为的改动，可以在自动检查后记录豁免原因并进入 Git 授权门。边界不明时默认不豁免。

### WSL 无法完整复现的场景

微信真实支付/退款回调、生产域名证书/白名单、生产密钥和云端 Timer 等可能无法在 WSL 完整复现。此时必须：

1. 完成所有可行的 WSL、体验版、沙箱和只读探测；
2. 逐项列出未验证范围和失败后果；
3. 由用户明确接受剩余风险后，才进入 Git/生产授权；
4. 生产发布后立即完成受控真实场景验证并保持回滚观察。

“本地无法验证”不能成为跳过所有本地检查的理由。

## 8. Git commit 和 GitHub push

WSL 验收通过后，先重新检查边界：

```bash
cd /opt/yoshop
git status --short --branch
git diff --stat
git diff --check
```

AI 必须列出准备提交的文件，排除其他任务、runtime、build、uploads、`.env` 和密钥。取得 Git 授权后，才可精确 stage、commit 和 push：

```text
git add <本任务明确文件>
git commit -m '<准确提交说明>'
git push origin main
```

不要使用 `git add .` 混入无关工作；不要把本地验收通过理解为自动获得生产授权。

## 9. 腾讯云生产 dry-run 和授权发布

生产发布只允许从 clean、已 push 且与 `origin/main` 一致的 `main` 开始：

```bash
./deploy.sh preflight --fetch
./deploy.sh release --fetch --dry-run
```

确认 release ID、commit、manifest、SHA-256、迁移清单、静态产物和扫描结果后，向用户单独申请腾讯云生产授权。取得明确授权后才执行：

```bash
./deploy.sh release --fetch \
  --confirm-production DEPLOY-wx.gxwqb.cn
```

该 guarded release 会构建前端、打包已提交源码和 migration、上传腾讯云、备份必要数据库、执行未应用 migration、原子切换 release、reload PHP-FPM/Nginx、restart Timer 并执行健康检查。不要在腾讯云上用手工 rsync、Git pull 或单独重启服务代替 release。

## 10. 生产验证与回滚

发布后至少执行：

```bash
./deploy.sh status
```

并核验：

- `current` 是刚发布的 release/commit，状态为 active；
- 公网 HTML 引用了新 asset hash，实际 HTTP JS bundle 包含预期修复；
- migration 已被记录且结果符合预期；
- PHP-FPM、Nginx、Timer 和健康检查正常；
- 用户使用生产账号重新登录并走一遍代表场景；
- 日志没有新增错误，必要时保持观察窗口。

失败时先保留证据并查看状态。代码回滚使用独立授权：

```bash
./deploy.sh rollback \
  --confirm-production ROLLBACK-wx.gxwqb.cn
```

rollback 只切回上一版代码，不反向撤销数据库 migration，所以 migration 必须幂等、向前兼容并在设计时保留旧代码回滚能力。

## 11. 紧急修复通道

只有用户针对正在造成重大生产影响的事件明确启用，AI 不得因赶时间自行判定。紧急通道可以缩短完整人工验收等待，但不能取消：

- 最小针对性自动检查；
- 尽可能的 WSL 验证；
- 预先准备的回滚方案；
- 独立 Git 授权；
- 独立腾讯云生产授权；
- 上线后立即验证。

事件稳定后必须补齐 WSL 回归测试和复盘。

## 12. 一页检查表

### 修改和 WSL 验收

- [ ] 已保护现有工作树，未覆盖无关修改
- [ ] 根因和影响 package 明确
- [ ] 自动检查、构建和 Review 通过
- [ ] 变更已进入 WSL 实际运行目录/进程/获批的本地数据库
- [ ] AI 已明确报告“尚未 GitHub push、尚未腾讯云部署”
- [ ] 用户已明确回复 WSL 手工验收通过，或记录了无运行行为豁免

### GitHub

- [ ] 提交文件列表精确，不含生成物、数据和密钥
- [ ] 已取得 Git commit/push 授权
- [ ] commit 和 push 成功
- [ ] `HEAD == origin/main`

### 腾讯云

- [ ] `preflight --fetch` 通过
- [ ] `release --fetch --dry-run` 通过
- [ ] 已检查 release、manifest、SHA、migration 和静态产物
- [ ] 已取得独立生产授权
- [ ] guarded release 成功
- [ ] `status`、公网 bundle、migration、服务和业务场景均验证通过
- [ ] 回滚方案仍可执行
