# 修复 WSL 本地 PHP runtime 缓存权限

## 背景

WSL 本地 `https://wx.oiob.cn/admin` 与小程序登录均出现 ThinkPHP 文件缓存写入失败：`runtime/cache/<hash-prefix>/<hash>.php` 的父目录不存在。腾讯云生产环境正常，不在本任务改动范围内。

## 目标

恢复 WSL 本地 PHP-FPM 对 `yoshop2.0/runtime` 的稳定写入能力，使后台和小程序 API 不再因缓存目录缺失而失败。

## 范围

- 诊断 WSL 本地 runtime 目录的属主、权限和实际 PHP-FPM 身份。
- 修复本地 runtime 的现有权限与继承权限。
- 用 `www-data` 身份验证哈希缓存目录和文件可创建、可改写。
- 验证本地 HTTP/API 请求不再返回该文件写入错误。
- 如需持久化修复，只增加最小的本地运维入口，不改变生产配置、数据库、上传文件或域名。

## 非目标

- 不发布或重启腾讯云生产服务。
- 不修改生产 `.env`、数据库、支付密钥和共享数据。
- 不处理其他独立业务任务。

## 验收标准

1. `www-data` 可以在 `yoshop2.0/runtime/cache` 新建两级缓存路径、写入并覆盖文件。
2. `runtime` 下后续由 root 或 www-data 新建的内容仍授予 www-data 所需权限。
3. 本地后台入口和至少一个无需凭据的 API/健康请求返回正常响应，响应与日志不再包含 `file_put_contents` / `No such file or directory`。
4. 生产状态未被改变，未执行部署命令。
