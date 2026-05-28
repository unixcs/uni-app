# SQL 说明

- `install.sql`：项目基础安装 SQL（来自 `yoshop2.0/public/install/data/install.sql`）
- `demo-content.sql`：当前演示内容的增量数据

恢复顺序：

1. 先导入 `install.sql`
2. 再导入 `demo-content.sql`

说明：

- `demo-content.sql` 只保留演示商品、商品图片关联、上传文件记录、首页/自定义页面、分类、配送模板、商品服务、店铺基础展示信息等恢复演示所需数据。
- 为避免泄露敏感配置，未导出真实环境密钥、支付配置、数据库配置等内容。
