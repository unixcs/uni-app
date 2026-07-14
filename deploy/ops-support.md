# 生产运维最小手册

## 发布前

1. `./deploy.sh preflight --fetch` 必须通过。
2. 数据库和 `/srv/yoshop/shared/uploads` 有最近的 `.complete` 备份，且默认临时库恢复验证通过。
3. 确认发布包不含 `.env`、uploads、runtime、payment/private key。
4. 确认 H5 产物只引用 `wx.gxwqb.cn`。

## 发布后

1. `./deploy.sh status` 显示新 release ID。
2. 检查 H5、`/admin/`、`/store/`、只读 API。
3. 检查 Nginx、PHP-FPM、MySQL、Redis、Timer。
4. 观察 30 分钟日志与磁盘；Timer 不得出现循环崩溃或高速日志增长。
5. 检查 `.env`、uploads、payment、runtime 的 realpath 都在 `/srv/yoshop/shared/`。

## 备份契约

### 固定输入和权限

生产脚本不接受 `DB_PASS`/`MYSQL_PWD`，也不会把连接配置或 secret 输出到日志。它只读取：

```text
/srv/yoshop/shared/mysql-client.cnf  # root:root，0600，必须含 [client]
/srv/yoshop/shared/db-name          # root:root，0600，仅一行 [A-Za-z0-9_]{1,64}
/srv/yoshop/shared/.env
/srv/yoshop/shared/uploads/
/srv/yoshop/shared/payment/
/srv/yoshop/shared/backups/         # root:root，0700
```

首次生产数据库创建完成后，按实际本机 socket/受限备份用户填写 defaults file。下面只有占位符，
不要把真实值粘贴到命令历史、仓库或工单：

```ini
[client]
user=BACKUP_USER_PLACEHOLDER
password=SECRET_PLACEHOLDER
host=localhost
```

```bash
sudo chown root:root /srv/yoshop/shared/mysql-client.cnf /srv/yoshop/shared/db-name
sudo chmod 0600 /srv/yoshop/shared/mysql-client.cnf /srv/yoshop/shared/db-name
sudo install -d -o root -g root -m 0700 /srv/yoshop/shared/backups
```

该 defaults file 应使用仅限 localhost 的专用维护账号，不复用应用账号。它必须同时满足
`mysqldump` 读取目标库/view/trigger/event/routine，以及在 `yoshop_verify_*` 命名空间
create/import/drop 临时库的权限；若需要生产覆盖，还必须显式具备生产库 drop/create/import
权限。具体最小 GRANT 要在生产 DB 建立后用 MySQL 8 实测，不能只验证“能连接”，也不要把
应用账号升级为 root。

### 原子备份内容

```bash
sudo /usr/local/sbin/backup-mysql.sh --type daily
```

脚本使用 `mysqldump --single-transaction --quick` 备份数据库（含 routines/triggers/events），
并备份 uploads，以及受保护的 `.env`、`mysql-client.cnf`、`db-name`、payment 数据。每次先写：

```text
/srv/yoshop/shared/backups/daily-YYYYmmddTHHMMSSZ.incomplete/
```

全部成功、源文件稳定性检查和权限收紧后，才原子改名为 `.complete/`。失败会删除本次
`.incomplete`；完整目录为 `0700`，文件为 `0600`。目录内契约：

- `database.sql.gz`：一致性 SQL dump；
- `uploads.tar.gz` + `uploads-manifest.json`：每个上传文件的相对路径、大小、SHA-256；
- `protected.tar.gz`：`.env`、MySQL defaults、DB 名和 payment；
- `SHA256SUMS`：所有数据 artifact 的 SHA-256；
- `manifest.json` + `manifest.json.sha256`：schema、类型、时间、表数量、上传统计、binlog 状态、保留期。

任何 symlink/special file、危险 DB 名、宽松 defaults 权限、非 InnoDB 表，或备份期间
schema/uploads/protected 数据变化都会 fail closed。日常备份只保留最新 7 份。显式 `--type migration` 和
`--type pre-restore` 按 30 天保留，不进入每日 7 份计数。当前发布执行器在增量迁移前产生的
旧格式 `<db>-YYYYmmdd-HHMMSS.sql.gz` 也被明确视为 migration backup，保留 30 天；未知文件
不会被日常清理误删。

### binlog 7 天

备份脚本记录 `@@GLOBAL.log_bin` 和 `@@GLOBAL.binlog_expire_logs_seconds`，生产环境若未开启
binlog 或小于 604800 秒会拒绝完成。MySQL 创建后由管理员在 MySQL 配置中设置并重启验证；
脚本不擅自修改数据库配置：

```ini
[mysqld]
log_bin=mysql-bin
binlog_expire_logs_seconds=604800
```

```bash
sudo mysql --defaults-extra-file=/srv/yoshop/shared/mysql-client.cnf \
  --batch --skip-column-names \
  -e 'SELECT @@GLOBAL.log_bin, @@GLOBAL.binlog_expire_logs_seconds'
```

## 恢复与验证

### 默认：随机临时库验证（安全路径）

不传目录时选最新 daily；也可以显式指定任意完整备份：

```bash
sudo /usr/local/sbin/rollback-mysql.sh
sudo /usr/local/sbin/rollback-mysql.sh \
  /srv/yoshop/shared/backups/daily-YYYYmmddTHHMMSSZ.complete
```

默认流程先校验 manifest/SHA-256、逐文件核对 uploads archive 和上传清单、检查 protected
archive，再恢复到随机 `yoshop_verify_*` 临时数据库，比较表数量，最后删除临时库。stdout
只输出一份 JSON 结果；`ok=true`、`temporary_database_removed=true`、
`checksums_verified=true`、`uploads_verified=true` 才算恢复证明。它不会覆盖生产库，也不会
自动解包覆盖当前 uploads/config/payment。

需要检查文件恢复时，只解到新建的 root-only 临时目录，人工比对后走变更单；禁止直接在
`shared/` 上 `tar -x`：

```bash
sudo install -d -o root -g root -m 0700 /root/yoshop-restore-inspect
sudo tar -xzf /srv/yoshop/shared/backups/BACKUP.complete/uploads.tar.gz \
  -C /root/yoshop-restore-inspect
sudo tar -xzf /srv/yoshop/shared/backups/BACKUP.complete/protected.tar.gz \
  -C /root/yoshop-restore-inspect
```

### 生产覆盖（破坏性、双保险）

代码回滚不自动回退数据库。确需覆盖生产库时，先进入维护模式并停止所有写入，记录变更单，
再使用固定 typed token：

```bash
sudo /usr/local/sbin/rollback-mysql.sh \
  /srv/yoshop/shared/backups/daily-YYYYmmddTHHMMSSZ.complete \
  --production-overwrite OVERWRITE-PRODUCTION-DATABASE
```

没有完全匹配的 token，生产覆盖路径不可达。即使有 token，脚本仍会先完成随机临时库验证，
再创建独立 `pre-restore-*.complete`（30 天保留）；只有该备份完成后才 drop/create 生产库并
导入。导入失败时不得反复尝试，应保持维护模式并用刚创建的 pre-restore 备份恢复/分析。
业务恢复后再执行深度 smoke、订单只读核对和上传清单抽查。

## 本地磁盘限制与 deferred 项

当前所有 `.complete` 目录仍在同一台主机的 `/srv` 本地磁盘。它能处理误发布/逻辑损坏，
**不能**处理整盘、主机、账号或机房级故障，因此尚未达到异地 RPO。COS bucket、CAM 最小权限、
服务端加密/客户端加密、跨地域保留、恢复演练和外部消息通知本轮全部明确 deferred；在这些
项目通过独立安全评审并真实部署前，不得在文档或验收中声称“已有异地备份”。

## 回滚

```bash
./deploy.sh rollback --confirm-production ROLLBACK-wx.gxwqb.cn
```

代码原子回退，数据库不自动回退。若必须恢复数据库，使用上一节保护流程；先停写、创建当前
生产状态的 pre-restore 备份，再覆盖，避免把新订单一起无提示地丢弃。

## 日志膨胀

先查服务重启次数和同一异常是否重复，而不是直接删日志：

```bash
systemctl status yoshop2.0-timer.service
journalctl -u yoshop2.0-timer.service --since '30 min ago'
du -xhd1 /var/log /srv/yoshop | sort -h
df -h /
```

确认根因已停止后再执行 journald/logrotate 的受控清理。日志目标为 14 天、journal 上限
1 GiB；实际 systemd/journald 配置仍需在生产安装后核对，不能只看仓库文件。
