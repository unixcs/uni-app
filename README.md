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

## 标准运行环境要求

> 为了避免“本地可运行、服务器报错”的情况，请尽量保持与生产环境一致的 PHP / 扩展 / 运行方式。

### 已验证的生产环境
- 操作系统：Ubuntu 22.04 LTS
- Nginx：1.18+
- PHP：8.3.x（当前生产验证版本为 8.3.31）
- PHP-FPM：`php8.3-fpm`
- MySQL：5.7+ / 8.0+
- Redis：6+
- Composer：2.x
- 定时任务：`php think timer start`，建议由 systemd 托管

### PHP 必需扩展
- `bcmath`
- `curl`
- `gd`
- `intl`
- `json`
- `mbstring`
- `mysqli`
- `mysqlnd`
- `openssl`
- `pdo`
- `pdo_mysql`
- `redis`
- `simplexml`
- `xml`
- `xmlreader`
- `xmlwriter`
- `zip`
- `fileinfo`
- `dom`
- `libxml`

### 兼容性提醒
- 本仓库已验证在 PHP 8.3 环境下运行。
- 若本地与服务器 PHP 小版本不同，某些 vendor 兼容问题可能只在生产环境触发。
- 本仓库已修复 `myclabs/php-enum` 在 PHP 8.3 下的 `JsonSerializable` 方法签名兼容问题。

### 生产配置建议
- `.env` 中 `APP_DEBUG=false`
- `.env` 中 `APP_URL` 与线上域名保持一致
- 数据库账号使用独立生产账号，不要留占位符
- Redis 默认只监听本机回环地址，除非已做好 ACL / 内网隔离
- 生产环境上线前确认 `yoshop2.0/public/admin` 与 `yoshop2.0/public/store` 已有编译产物

如需后台前端，也可分别安装并编译：

- `yoshop2.0-store`
- `yoshop2.0-admin`

## 补充说明

- `yoshop2.0/public/uploads/` 已包含演示图片，因此导入 SQL 后应可恢复演示商品与图片。
- 仓库不提交真实 `.env`、`vendor/`、`node_modules/`、运行时目录与常见垃圾文件。
- 页面装修（DIY）图片已改为相对路径存储、接口动态补全绝对地址，降低换域名部署时的耦合。
- 当前版本修复了商家后台支付设置、其他设置选项；修复了注册商家后台新注册账户登录时的权限报错、404、白屏等问题。
- 更详细的部署说明见 `deploy/README.md`。
