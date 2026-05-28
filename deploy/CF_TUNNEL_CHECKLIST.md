# Cloudflare Tunnel / 域名回源排查清单

适用场景：
- 前端 API、页面资源、图片曾被写死到 `https://wx.oiob.cn/...`
- 访问后端接口、首页图片、后台页面时明显变卡
- 怀疑普通业务流量被 Cloudflare Tunnel 回源拖慢

## 一、先确认当前目标

目标不是立刻删除公网域名，而是区分两类流量：

1. **必须公网可达的流量**
   - 微信支付/支付宝支付回调
   - 微信小程序必须访问的 HTTPS 接口
   - 外部第三方 webhook

2. **不应优先走 Tunnel 的流量**
   - H5 前台普通 API
   - 后台 admin/store 普通 API
   - 页面装修图片、站内 `/uploads`、`/assets`
   - 同机部署时可直接相对路径访问的静态资源

---

## 二、先做 3 组耗时对比

在服务器上执行，比较本机直连和外网域名回源差异：

### 1) 本机直连 API

```bash
curl -w '\nconnect=%{time_connect} start=%{time_starttransfer} total=%{time_total}\n' "http://127.0.0.1/index.php?s=/api/"
```

### 2) 本机访问公网域名 API

```bash
curl -k -w '\nconnect=%{time_connect} start=%{time_starttransfer} total=%{time_total}\n' "https://wx.oiob.cn/index.php?s=/api/"
```

### 3) 本机访问页面资源

```bash
curl -k -w '\nconnect=%{time_connect} start=%{time_starttransfer} total=%{time_total}\n' "https://wx.oiob.cn/uploads/你的测试图片路径"
```

判断方法：
- 如果 `127.0.0.1` 很快，而 `wx.oiob.cn` 明显慢，说明慢主要在 **Cloudflare Tunnel / 外网回源链路**。
- 如果两者都慢，再查 PHP、MySQL、Redis、磁盘或 Nginx。

---

## 三、核查 Nginx 是否已经支持同域相对路径

确认站点根目录是否指向：

```text
/opt/yoshop/yoshop2.0/public
```

重点确认：

1. `/` 是否能正常返回 H5 首页
2. `/admin` 是否能返回后台前端页面
3. `/store` 是否能返回商户后台前端页面
4. `/index.php?s=/api/`、`/index.php?s=/admin`、`/index.php?s=/store` 是否都能直接由 PHP 处理

建议检查项：
- `root`
- `index`
- `try_files`
- `location /admin`
- `location /store`
- `location ~ \.php$`
- `fastcgi_param HTTP_X_FORWARDED_PROTO`
- `fastcgi_param HTTP_HOST`

如果这些都已打通，前端就应优先用：
- `./index.php?s=/api/`
- `../index.php?s=/admin`
- `../index.php?s=/store`

---

## 四、核查 Cloudflare Tunnel 配置

重点看 `cloudflared` 的 ingress 是否把整站所有资源都套进 Tunnel。

需要确认：

1. `wx.oiob.cn` 当前是不是直接指向 tunnel
2. Tunnel 回源是不是又转发到本机 Nginx
3. 是否所有静态资源 `/uploads`、`/assets` 也统一走 tunnel
4. 是否开启了额外的 WAF、缓存绕过、零信任校验，导致普通请求变慢
5. Tunnel 节点所在服务器与访问用户是否地域很远

如果当前结构是：

```text
用户 -> Cloudflare -> Tunnel -> 服务器 Nginx -> PHP
```

那就不要再让“同机可直出的普通资源”也全部绑定到这个域名。

---

## 五、核查后端生成的绝对地址来源

项目中页面资源会被转成绝对地址，关键代码：

```php
return rtrim(base_url(), '/') . $url;
```

需要确认 `base_url()` 最终为何变成了 `https://wx.oiob.cn`。

重点检查：

1. 当前请求头里的 `Host`
2. `X-Forwarded-Proto`
3. `X-Forwarded-Host`
4. Nginx / Cloudflare 是否把外网域名透传给 PHP
5. 后端是否根据当前请求自动生成 `base_url()`

结论判断：
- 如果后端总是收到 `Host: wx.oiob.cn`，那么它就会倾向把资源继续拼成 `wx.oiob.cn`。

---

## 六、核查数据库里是否残留绝对资源地址

项目里已经提供修复脚本：

```text
deploy/sql/fix-page-data-relative-paths.sql
```

作用：
- 把 `https://wx.oiob.cn/uploads/...` 改成 `/uploads/...`
- 把 `https://wx.oiob.cn/assets/...` 改成 `/assets/...`

建议先在数据库里检查：

```sql
SELECT page_id
FROM yoshop_page
WHERE page_data LIKE '%wx.oiob.cn%';
```

如果还能查到记录，说明页面装修数据仍在传播绝对域名。

---

## 七、优先级最高的修正顺序

### 第一优先级
1. uniapp 改成相对 API：`./index.php?s=/api/`
2. admin 改成相对 API：`../index.php?s=/admin`
3. store 改成相对 API：`../index.php?s=/store`

### 第二优先级
4. 清理数据库里 `wx.oiob.cn/uploads`、`wx.oiob.cn/assets`
5. 确认 `base_url()` 不会继续把站内资源拼回 tunnel 域名

### 第三优先级
6. 保留支付回调等必须公网可达的接口走 HTTPS 公网域名
7. 普通页面/API/站内资源尽量同域或相对路径

---

## 八、建议保留的公网使用场景

以下场景继续使用 HTTPS 公网域名通常没问题：

- 微信支付回调
- 支付宝回调
- 微信小程序业务域名白名单
- APP/H5 从公网访问 API
- 外部第三方系统调用接口

但这不等于：

- 后台前端必须写死公网域名
- 页面装修图必须写死公网域名
- 同站点部署的 H5 必须走 Tunnel 回源

---

## 九、执行后的预期结果

如果配置修正正确，通常会看到：

1. 后台 admin/store 页面打开更快
2. H5 首屏图片加载更快
3. 首页装修图片不再全部绕公网回源
4. 浏览器 Network 中 API/图片请求不再集中指向 `https://wx.oiob.cn/...`
5. 只有支付回调、小程序公网访问等场景仍使用公网域名
