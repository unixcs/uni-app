# Design

采用“原工作区只备份与分类、隔离克隆负责历史过滤”的边界。备份位于工作树外，分为 Git bundle、diff/untracked、私有数据和清单四类。`.gitignore` 保护开发目录；发布 allowlist 独立于 `.gitignore`，避免忽略规则等同部署规则。历史使用路径过滤保留 commit 拓扑，不推送清理前 tag/ref。
