# Implementation Plan

1. 生成并审查 schema/白名单/清空表/上传引用清单。
2. 在临时 DB 恢复，验证外键/计数/页面 JSON/图片/域名/秘密占位。
3. 创建生产 DB/user/env/shared/payment，设置权限。
4. 构建并上传首个完整 release，链接共享数据。
5. 导入初始化基线、记录 migration baseline、执行私有检查。
6. 原子激活，启动服务，执行公网 smoke 和观察。
7. 全绿后撤维护页；失败恢复维护页/current/DB 基线。
