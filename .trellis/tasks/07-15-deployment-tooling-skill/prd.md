# 构建发布程序与部署 Skill

## Goal
提供可测试、确定性、人工双授权的一键生产发布工具与 `$yoshop-deploy` Skill，所有构建在本地完成，腾讯云不依赖外网包源。

## Requirements
- Python 标准库控制器 + 固定远端 Bash 脚本 + `deploy.sh` 单入口。
- 完整构建 PHP vendor、H5、admin、store；小程序 test/prod 构建独立。
- 修复小程序异步 URL 恢复竞态；Windows rsync 目标有 sentinel/路径保护。
- 只允许已提交、已推送 main 发布；扫描秘密、A/B/localhost 漂移。
- 支持 dry-run/preflight/build/deploy/status/rollback，输出机器可读报告。
- 原子版本目录、共享链接、备份、迁移、健康检查、自动代码回滚。
- Skill 只编排和请求授权，不保存秘密或替代脚本。

## Acceptance Criteria
- [x] 单元/集成测试覆盖命令失败、危险路径、脏工作树、域名漂移、敏感文件和回滚。
- [x] 干净提交可重复产生含清单/校验值的完整运行包。
- [x] 模拟远端发布/失败回滚通过，不需要 GitHub/npm/Composer 服务器网络。
- [x] test/prod 小程序产物只含对应域名。
- [x] Skill 通过 quick_validate 并遵守双授权。
