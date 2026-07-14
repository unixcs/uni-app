# Implementation Plan

1. 配置并执行 DB/binlog/uploads/配置备份与保留策略。
2. 在临时位置恢复并验证；演练代码回滚/迁移幂等/版本清理。
3. 验证日志轮转、熔断、证书续期和监控事件。
4. 重构 `/mnt/vps/tencent` 并扫描明文秘密。
5. 写项目内 SSH 换钥手册和实际操作索引。
6. 执行全量 Trellis check、更新规格、形成最终报告并请求提交/推送授权。
