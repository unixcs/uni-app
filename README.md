# yoshop monorepo

## 仓库说明

本仓库用于保存一个可重新部署、可继续二开的 yoshop 

演示项目单仓库版本，包含：

- `yoshop2.0/`：PHP 后端
- `yoshop2.0-uniapp/`：uniapp / 微信小程序源码
- `yoshop2.0-store/`：商家后台前端源码
- `yoshop2.0-admin/`：总后台前端源码
- `yoshop2.0/public/uploads/`：演示商品图 / 首页图
- `deploy/env/`：环境变量模板
- `deploy/sql/`：基础安装 SQL 与演示内容 SQL

## 部署恢复步骤

1. `git clone <your-repo-url>`
2. 配置 `yoshop2.0/.env`
3. 在 `yoshop2.0/` 执行 `composer install`
4. 导入 `deploy/sql/install.sql`
5. 导入 `deploy/sql/demo-content.sql`
6. 如是旧页面装修数据，可执行 `deploy/sql/fix-page-data-relative-paths.sql`
7. 配置 Nginx / PHP / MySQL / Redis
8. 本地重新编译 `yoshop2.0-uniapp`

如需后台前端，也可分别安装并编译：

- `yoshop2.0-store`
- `yoshop2.0-admin`

## 补充说明

- `yoshop2.0/public/uploads/` 已包含演示图片，因此导入 SQL 后应可恢复演示商品与图片。
- 仓库不提交真实 `.env`、`vendor/`、`node_modules/`、运行时目录与常见垃圾文件。
- 页面装修（DIY）图片已改为相对路径存储、接口动态补全绝对地址，降低换域名部署时的耦合。
- 更详细的部署说明见 `deploy/README.md`。
