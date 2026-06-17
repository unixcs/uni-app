# 部署说明

## 仓库用途

这是一个单仓库（monorepo），用于保存当前演示环境可恢复所需的核心内容，包含：

- `yoshop2.0/`：PHP 后端
- `yoshop2.0-uniapp/`：uniapp / 微信小程序源码
- `yoshop2.0-store/`：商家后台前端源码
- `yoshop2.0-admin/`：总后台前端源码
- `yoshop2.0/public/uploads/`：当前演示图片
- `deploy/sql/`：基础安装 SQL 与演示增量 SQL
- `deploy/env/`：环境变量模板
- `deploy/scripts/`：基础 bootstrap、数据库备份/回滚、ACME 续期辅助脚本
- `deploy/ops-support.md`：上线后运维支持清单（备份、回滚、监控、证书续期）

## 新服务器恢复步骤

1. `git clone <your-repo-url>`
2. 复制 `deploy/env/yoshop2.0.env.example` 为 `yoshop2.0/.env` 并按实际环境填写
3. 进入 `yoshop2.0/` 执行 `composer install`
4. 导入 `deploy/sql/install.sql`
5. 导入 `deploy/sql/demo-content.sql`
6. 如是旧页面装修数据，可执行 `deploy/sql/fix-page-data-relative-paths.sql`
7. 配置 Nginx / PHP / MySQL / Redis
8. 按需安装前端依赖并重新编译：
   - `yoshop2.0-uniapp/`
   - `yoshop2.0-store/`
   - `yoshop2.0-admin/`
9. 上线前后按 `deploy/ops-support.md` 完成数据库备份、回滚演练、监控观察与 ACME 续期核验

## 说明

- `yoshop2.0/public/uploads/` 已直接包含演示图片。
- 导入 SQL 后，演示商品与图片记录可直接恢复。
- 当前代码已修复页面装修图的存储方式：数据库保存相对路径，接口输出时自动补全当前站点域名。
- 对旧库可执行 `deploy/sql/fix-page-data-relative-paths.sql` 做一次性清理。
- 生产运维支持的最小脚本与检查清单见 `deploy/ops-support.md`。
