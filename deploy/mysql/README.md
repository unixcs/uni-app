# Production MySQL durability

Install `60-yoshop-retention.cnf` as root under `/etc/mysql/mysql.conf.d/` and
validate with `mysqld --validate-config` before restarting MySQL. The live host
uses row-based binlogs, flushes each transaction/binlog commit, and retains
binlogs for seven days.

Daily logical backups are independent of binlogs. Both are currently on the
same Tencent system disk; COS/CAM/offsite copying is deliberately deferred and
must not be described as a complete disaster-recovery RPO.
