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

## 新服务器恢复步骤

1. `git clone <your-repo-url>`
2. 复制 `deploy/env/yoshop2.0.env.example` 为 `yoshop2.0/.env` 并按实际环境填写
3. 进入 `yoshop2.0/` 执行 `composer install`
4. 导入 `deploy/sql/install.sql`
5. 导入 `deploy/sql/demo-content.sql`
6. 配置 Nginx / PHP / MySQL / Redis
7. 按需安装前端依赖并重新编译：
   - `yoshop2.0-uniapp/`
   - `yoshop2.0-store/`
   - `yoshop2.0-admin/`

## 说明

- `yoshop2.0/public/uploads/` 已直接包含演示图片。
- 导入 SQL 后，演示商品与图片记录可直接恢复。
- 当前页面装修数据中的部分图片 URL 仍为演示域名绝对地址；如更换域名部署，建议导入后在数据库中统一替换为新域名，或在二开时将装修图 URL 改为由后端动态生成。
