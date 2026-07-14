# 本地仓库备份与 Git 历史治理

## Goal
在不损失任何有效业务修改、不破坏本地 PHP/Nginx/前端/Windows 小程序开发链路的前提下，建立可恢复备份，精准清理垃圾与 Git 追踪，保留有效提交并更新原 GitHub。

## Requirements
- 先备份 Git 全历史、未提交/未追踪业务文件、本地 DB、uploads、env、支付资料并验证恢复。
- 保留 node_modules/vendor 和本地私有运行资料；清除仅可再生的缓存、日志、dist/unpackage/runtime/tmp。
- 生成精准 `.gitignore` 和发布 allowlist；秘密/上传/生成物不得进入历史。
- 现有业务修改按逻辑分类，未经提交授权不得 commit/push。
- 在隔离克隆中保留有效提交并过滤历史垃圾；原 GitHub URL 不变，旧克隆可废弃。
- WSL 为源码事实源，Windows 镜像可重建。

## Acceptance Criteria
- [x] 备份有 SHA-256 且临时恢复验证通过。
- [x] `git status --ignored` 与 `git ls-files` 证明依赖/秘密/生成物/上传未被追踪。
- [x] PHP、测试、admin/store/H5 和小程序 Windows 编译链路在清理后通过。
- [x] 清理后历史仍含有效提交，垃圾路径在所有 refs 中消失，`git fsck` 通过。
- [x] 经用户提交/推送授权后原 GitHub main 更新成功。
