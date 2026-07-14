# 腾讯云审计清理与基础设施重建

## Goal
在维护模式和可恢复证据下清除旧商城与失控日志，把现有 Ubuntu 主机收敛为最小、安全、可持续发布的生产底座。

## Requirements
- 先导出配置/审计资料，优先快照，再停止失控 Timer；清理前证明日志增长根因。
- 保留 Docker/containerd、Certbot、Komari、腾讯云代理、SSH、Nginx/PHP/MySQL/Redis 和备案页。
- 删除批准的旧商城代码、DB、备份、开发依赖/工具、无用账号/软件和异常日志。
- B 使用 503 维护页；备案页和 ACME 始终可用。
- 建立 releases/current/shared/backups、`deployer` scoped sudo、2 GiB swap、UFW/Fail2ban、日志上限、Timer 熔断。
- Docker保留；备案页由宿主 Nginx 直接服务，唯一源为 `/mnt/vps/tencent/static-site/index.html`。

## Acceptance Criteria
- [x] Timer 停止后日志增长归零，清理后磁盘低于 70% 且保留足够发布/备份空间。
- [x] 仅 22/80/443 公网监听，MySQL/Redis 为 loopback。
- [x] Nginx/PHP/MySQL/Redis/Docker/Certbot/Komari/腾讯云代理正常。
- [x] `deployer` 可完成受限发布但不能任意 sudo。
- [x] 日志轮转、journal 上限、swap、防火墙/Fail2ban 和熔断验证通过。
