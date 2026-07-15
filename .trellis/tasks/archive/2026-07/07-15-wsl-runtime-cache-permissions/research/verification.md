# WSL runtime 缓存权限修复证据

日期：2026-07-15（Asia/Shanghai）

## 根因

1. 本地 PHP-FPM 以 `www-data` 运行，但 `yoshop2.0/runtime/cache` 为 `root:root 0755`；以 `www-data` 执行 `mkdir runtime/cache/06` 可稳定复现 `Permission denied`。
2. 本地 `yoshop2.0-timer.service` 未设置 `User` / `Group`，Workerman 实际以 root 运行，与 PHP-FPM 混合写同一文件缓存。
3. `scripts/cleanup-local.sh --apply` 会删除整个 runtime，却没有重建 PHP-FPM 可写目录；多份文档也保留了直接删除 runtime 内容的旧命令。
4. `public/index.php` 已有哈希目录自愈逻辑，但 PHP-FPM 对 runtime 根目录无写权限时，自愈无法生效。

## 实施

- 安装 WSL 本地 `acl` 包（新增约 197 KB）。
- 新增 `scripts/repair-local-runtime.sh`：
  - 仅允许 Git 开发工作区；
  - 拒绝生产式 runtime 软链接；
  - 可选择只清理 cache/temp/schema；
  - 统一属主为 `www-data:www-data`；
  - 预建 256 个 ThinkPHP 缓存桶；
  - 设置可继承 ACL；
  - 用 `www-data` 做真实写入/覆盖探针。
- 本地 Timer 增加 systemd override，统一使用 `www-data:www-data`、`UMask=0007`。
- `cleanup-local.sh --apply` 增加修复脚本前置检查，并在清理后自动重建 runtime。
- 替换所有已追踪文档里的危险 runtime 直接删除命令。
- 清除 5 个由历史错误 shell 命令遗留的零字节未追踪垃圾路径。

## 验证

- `shellcheck`：repair、cleanup、contract test 全部通过。
- `scripts/tests/test-local-runtime-contract.sh`：PASS；覆盖生产软链接拒绝和清理后自动恢复。
- root 创建的缓存文件通过继承 ACL 可由 `www-data` 覆盖：PASS。
- `nginx -t`：PASS。
- `php8.3-fpm`、`nginx`、`yoshop2.0-timer`：active。
- Timer：`User=www-data`、`Group=www-data`、`NRestarts=0`。
- 本机回环后台登录路由返回正常业务级“用户名或密码错误”，不再返回文件写入错误。
- Cloudflare A 域名后台登录路由返回相同正常业务响应。
- Cloudflare A 域名小程序登录路由可进入业务控制器；用空测试载荷得到字段校验错误，但响应和 runtime 日志均无 `file_put_contents`、`Failed to open stream`、`No such file or directory`。

## 边界

- 未连接、修改、重启或发布腾讯云生产环境。
- 未修改本地/生产 `.env`、数据库、上传文件、支付配置或域名。
- 未执行前端构建，因为本次没有修改任何前端或 PHP 业务源码；现有本地 Nginx 静态构建保持不变。
