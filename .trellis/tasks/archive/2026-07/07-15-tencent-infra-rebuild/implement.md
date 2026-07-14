# Implementation Plan

1. 复制审计/配置/包/服务/账号/cron/证书元数据到本地并校验；记录快照状态。
2. 启用维护页，停止/禁用旧 Timer，确认日志不再增长。
3. 保存最小错误样本，安全轮转/vacuum 巨型日志。
4. 停止应用依赖，删除批准旧目录、DB/用户、备份和开发工具；模拟后卸载无用包。
5. 建立目录/权限/deployer/scoped sudo/shared/log/swap/firewall/fail2ban。
6. 安装/更新 Nginx/systemd/logrotate/watchdog 配置，规范备案页。
7. 验证所有保留服务和安全边界；维护页保持到 cutover 子任务。
