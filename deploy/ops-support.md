# 生产运维支持清单

本文件补充最小化的上线后运维支持，覆盖数据库备份、回滚、监控观察项和 ACME 证书续期。

## 1. 数据库备份

推荐先建立每日备份，再做上线/变更。

### 备份脚本

```bash
DB_NAME=yoshop2 \
DB_USER=root \
DB_PASS='***' \
BACKUP_DIR=/opt/yoshop/backups \
./deploy/scripts/backup-mysql.sh
```

脚本会输出：
- 压缩后的 SQL 备份：`*.sql.gz`
- 校验文件：`*.sha256`

### 备份范围说明

当前脚本仅覆盖数据库。

上线前仍需手工确认：
- `yoshop2.0/public/uploads/` 是否有独立文件备份
- `deploy/nginx/wx.gxwqb.cn.conf` 是否有服务器侧备份
- `.env` 是否已安全保管在仓库外

## 2. 回滚流程

数据库回滚可使用最近一次备份恢复。

```bash
DB_NAME=yoshop2 \
DB_USER=root \
DB_PASS='***' \
DROP_AND_RECREATE=1 \
./deploy/scripts/rollback-mysql.sh /opt/yoshop/backups/yoshop_YYYY-MM-DD_HHMMSS.sql.gz
```

回滚时仍需人工执行：
- 恢复代码版本到上一稳定提交
- 恢复 `public/uploads/` 资源文件
- 恢复 Nginx / PHP / systemd 配置
- 必要时回退数据库之外的业务配置

## 3. 监控与观察清单

建议首发至少观察以下项目：

- `systemctl status nginx mysql redis-server php8.3-fpm yoshop2.0-timer.service`
- Nginx access/error 日志
- PHP-FPM 日志
- 订单创建、支付回调、上传图片是否正常
- 小程序虚拟支付启用时，运行 `php think virtual-payment:sandbox-check --goods-id 10001 --user-mobile 19900000000`，确认配置、测试用户、商品映射和通知入口通过
- 小程序虚拟支付启用时，观察 `wechat_virtual` 交易的支付通知、主动查单、重复通知幂等、退款收敛和履约回推结果
- MySQL 磁盘使用率、连接数、慢查询
- Redis 是否只监听本机且无异常重启
- 定时任务 `php think timer start` 是否持续存活

建议观察窗口：
- 发布后 30 分钟内高频检查
- 首日每 2~4 小时检查一次
- 之后进入日常巡检

## 4. ACME 证书续期

使用 certbot 时，可通过脚本手工验证续期链路：

```bash
sudo ./deploy/scripts/renew-acme-cert.sh
```

脚本完成后会 reload Nginx。

如果系统尚未安装 certbot，或证书由云厂商托管，则这一步需要按实际环境替换。
