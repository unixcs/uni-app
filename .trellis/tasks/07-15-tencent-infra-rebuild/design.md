# Design

原地保留稳定 OS/运行栈，不做无收益重装或大版本升级。维护 Nginx 独立于应用 current，确保 ACME/备案页可用。服务器清理使用显式 allowlist 与 dry-run 清单；账号/包删除前验证依赖。Timer 使用修复后代码、受限 systemd unit、日志增长 watchdog，异常时停止后台而保全 Web/DB。
