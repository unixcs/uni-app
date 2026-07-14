# Design

本地 `deploy/deploy.py` 管理状态机和 JSON 报告，根 `deploy.sh` 只转发参数；`deploy/scripts/server-release.sh` 在受控参数下管理候选版本、共享链接、迁移、切换和回滚。构建在临时 Git archive/worktree 中完成，依赖缓存与产物目录分离。Skill 位于 `.agents/skills/yoshop-deploy`，引用契约/排障文档并调用脚本。危险操作默认拒绝，所有路径以 realpath+sentinel 校验。
