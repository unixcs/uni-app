# YoShop systemd units

本目录保存生产 Timer 进程和每日备份的 systemd 单元。生产固定根目录是
`/srv/yoshop`，不再使用旧 `/opt/yoshop` 布局。

## 应用 Timer

```bash
sudo install -o root -g root -m 0644 \
  deploy/systemd/yoshop2.0-timer.service \
  /etc/systemd/system/yoshop2.0-timer.service
sudo systemctl daemon-reload
sudo systemctl enable --now yoshop2.0-timer.service
```

状态检查：

```bash
systemctl status yoshop2.0-timer.service
journalctl -u yoshop2.0-timer.service -f
```

## 每日生产备份

只能在生产数据库和以下共享路径都已建立后安装/启动：

```text
/srv/yoshop/shared/{.env,mysql-client.cnf,db-name,uploads,payment,backups}
```

先安装受版本控制的脚本。公共 helper 必须与两个入口放在同一目录；脚本本身不含凭据：

```bash
sudo install -o root -g root -m 0644 \
  deploy/scripts/backup-production-common.sh \
  /usr/local/sbin/backup-production-common.sh
sudo install -o root -g root -m 0755 \
  deploy/scripts/backup-mysql.sh \
  /usr/local/sbin/backup-mysql.sh
sudo install -o root -g root -m 0755 \
  deploy/scripts/rollback-mysql.sh \
  /usr/local/sbin/rollback-mysql.sh
sudo install -o root -g root -m 0644 \
  deploy/systemd/yoshop-backup.service \
  deploy/systemd/yoshop-backup.timer \
  /etc/systemd/system/
sudo systemctl daemon-reload
```

先手动运行并执行默认的临时库恢复验证；两步都成功后才启用 Timer：

```bash
sudo /usr/local/sbin/backup-mysql.sh --type daily
sudo /usr/local/sbin/rollback-mysql.sh
sudo systemctl enable --now yoshop-backup.timer
systemctl list-timers yoshop-backup.timer
```

Timer 每天以 `02:15` 为基准，附加最多 2 小时随机延迟并启用 `Persistent=true`。
Service 以 root 运行是为了读取受保护的 `.env`、payment 和 uploads，但仅允许
`/srv/yoshop/shared/backups` 写入；同时设置 50% CPU、768 MiB 内存、64 tasks、
低 I/O 优先级和 2 小时超时。它不访问腾讯 API，也不把备份上传到外部。

检查最近执行：

```bash
systemctl status yoshop-backup.timer yoshop-backup.service
journalctl -u yoshop-backup.service --since '2 days ago'
sudo find /srv/yoshop/shared/backups -mindepth 1 -maxdepth 1 -type d \
  -name '*.complete' -printf '%TY-%Tm-%Td %TH:%TM %m %f\n' | sort
```

完整备份/恢复、保留策略、binlog 和生产覆盖保护契约见 `../ops-support.md`。
