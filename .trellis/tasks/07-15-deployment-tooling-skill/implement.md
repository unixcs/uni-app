# Implementation Plan

1. 读取项目规范和现有构建脚本，定义 CLI/manifest/schema。
2. 修复跨平台构建脚本和环境注入，补聚焦测试。
3. 实现发布包 builder、allowlist、secret/domain scanner。
4. 实现本地 Python preflight/build/rsync/SSH/report。
5. 实现远端 Bash prepare/migrate/activate/health/rollback/cleanup。
6. 实现 migration registry 与公开/私有健康检查。
7. 创建 Skill/agents metadata/references 并验证。
8. 在本地 fixture/fake remote 上演练成功与失败路径。
