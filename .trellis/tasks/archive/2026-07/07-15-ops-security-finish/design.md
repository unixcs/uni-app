# Design

运行证据以机器可读报告+简洁 runbook 保存；备份必须通过恢复证明而非只看文件存在。`/mnt/vps/tencent/AGENTS.md` 只放安全操作规则，README 为索引，runbooks/inventory/architecture 分层。密钥轮换采用双会话、先加后删、管理员与 deployer 分钥、失败可回退流程。
