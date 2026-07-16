# Implementation Plan

## 1. 建立权威端到端文档

- [x] 新增根目录 `WSL本地验证与腾讯云生产上线工作流.md`。
- [x] 写明环境、术语、状态模型、三道授权门和标准主流程。
- [x] 按变更类型给出 WSL 本地生效矩阵和代表命令。
- [x] 写明人工验收清单、失败回路、Git/生产流程、生产验证与回滚。

## 2. 校正现有入口

- [x] 更新 `README.md`，增加完整工作流入口并避免把 runtime 专项说明误当完整本地上线说明。
- [x] 更新 `deploy/README.md` 日常发布顺序，补入 WSL 本地部署和用户验收门。
- [x] 更新 `docs/ai-development-manual.md`，增加交付状态报告和授权边界。
- [x] 更新 `.trellis/spec/deployment/ops/index.md`，固化 future-agent 强制门禁。

## 3. 一致性检查

- [x] 搜索 `生产部署`、`Git 授权门`、`本地测试`、`wx.oiob.cn`、`wx.gxwqb.cn`，确认无相反顺序。
- [x] 验证新增/修改的 Markdown 相对链接存在。
- [x] 确认没有把生成目录、凭据、本地 DB 或 uploads 纳入 Git/生产同步说明。
- [x] 确认所有生产命令与 `deploy/deploy.py` 当前 CLI 一致。
- [x] 运行 `git diff --check`，仅报告本任务文档差异，不混入现有无关工作树修改。

## 4. 审批与边界

- [x] 在实施前向用户展示规划摘要并取得开始实施批准。
- [x] 本任务不执行 WSL runtime 替换、migration、Git commit/push 或腾讯云部署。
- [x] 文档完成后另行回到当前权限/版权任务，按新门禁先走 WSL 本地验收。
