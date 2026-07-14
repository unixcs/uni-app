# Implementation Plan

1. 生成磁盘/文件/Git/分支/未提交清单。
2. 在工作树外创建 bundle、diff、untracked、DB/uploads/env/payment 加密或权限受限备份并验证。
3. 建立精准 ignore 与安全清理脚本，先 dry-run，再只清可再生项。
4. 验证全部本地运行/构建/测试链路。
5. 分类当前有效修改，准备逻辑提交清单并请求授权。
6. 在隔离镜像仓库运行历史过滤，扫描秘密/垃圾、验证图和 fsck。
7. 请求 force-with-lease 推送授权后更新原 GitHub。
8. 失败时恢复原 bundle/工作树，不触碰腾讯云。
