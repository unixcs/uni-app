# 商城 uni-app 二次开发编译全过程总结

> 适用目录：`/opt/yoshop/yoshop2.0-uniapp`
>
> 目标：下次修改代码后，能够**快速完成 H5 预览编译**、**生成微信小程序产物**，并尽量避免再次踩到这次遇到的工具链坑。

---

## 1. 这次做了什么

本次在商城 `uni-app` 项目里做了“服务订单化”的二次开发，主要包括：

- 用户端从实物电商流程改成服务订单流程
- 商品详情页改成服务详情页
- 下单页改成服务下单页
- 订单列表/订单详情页改成服务单语义
- 去掉购物车、地址、物流、售后等入口
- 后端订单状态文案改成服务状态：
  - 待支付
  - 待联系
  - 服务中
  - 已完成
  - 已关闭
  - 已退款

这些业务改动已经在代码里生效，并且最终已经能通过浏览器预览和微信小程序编译结果看到。

---

## 2. 这次编译为什么一开始一直失败

一开始失败，不是因为业务代码本身有明显致命错误，而是因为这个项目的 **uni-app 构建工具链版本混搭** 很严重。

### 核心问题

项目原本同时存在下面几类版本混用：

- 一部分 `@dcloudio/*` 是旧版 `2.x`
- 一部分 `@dcloudio/*` 是 `3.0.0-alpha` 老 alpha 版
- `vite` 还是旧版 `2.9.18`
- `@vitejs/plugin-vue` 还是旧版 `1.2.5`
- 但 `@dcloudio/vite-plugin-uni` 已经是新一代，需要 `vite 5.x`

结果就是：

- `npm run build:h5` 报各种 API 不兼容
- HBuilderX CLI 编译也报错
- 同一个项目在 WSL 和 HBuilderX 的 Windows 镜像目录里还各有一套 `node_modules`

---

## 3. 本次实际踩过的坑

下面是这次真实遇到过的错误类型，方便下次快速识别。

### 3.1 DCloud 包版本不一致

一开始遇到过类似错误：

- `normalizePagesJson is not a function`
- `preHtml is not a function`
- `parseManifestJsonOnce is not a function`
- `parseCompatConfigOnce is not a function`

这类报错基本都指向：

> `@dcloudio/*` 包版本不在同一代。

---

### 3.2 Vite 主版本和 DCloud 插件不兼容

后来又遇到：

- `vite.createFilter is not a function`

这个报错的本质是：

> 当前 `@dcloudio/vite-plugin-uni` 需要的是 **Vite 5**，但项目还在用 **Vite 2**。

---

### 3.3 缺少运行时依赖

例如遇到过：

- `Cannot find module '@dcloudio/uni-app/dist/uni-app.es.js'`
- `Preprocessor dependency "sass" not found`

这类通常是少装了必要包：

- `@dcloudio/uni-app`
- `sass`

---

### 3.4 HBuilderX CLI 实际使用的是 Windows 镜像项目目录

最容易忽略的坑：

HBuilderX CLI 编译时实际吃的项目目录不是纯 WSL 路径，而是类似：

```text
D:\Program\0\home\0\yoshop1\yoshop2.0-uniapp
```

也就是说：

- 你在 WSL 里改了 `node_modules`
- HBuilderX 不一定直接用那一份
- 它可能使用自己映射出来的 Windows 镜像目录

所以 HBuilderX CLI 能不能成功，不光看 WSL 目录，还要看镜像目录是否同步。

---

## 4. 这次最终是怎么修好的

本次最终成功，不是靠单点补丁硬顶到底，而是做了几步关键修正。

### 4.1 统一 DCloud 核心版本

最终统一到了这组可工作的版本：

```json
"@dcloudio/uni-app": "3.0.0-5000720260410001",
"@dcloudio/uni-cli-shared": "3.0.0-5000720260410001",
"@dcloudio/uni-components": "3.0.0-5000720260410001",
"@dcloudio/uni-h5": "3.0.0-5000720260410001",
"@dcloudio/uni-h5-vue": "3.0.0-5000720260410001",
"@dcloudio/uni-i18n": "3.0.0-5000720260410001",
"@dcloudio/uni-shared": "3.0.0-5000720260410001",
"@dcloudio/vite-plugin-uni": "3.0.0-5000720260410001"
```

特殊说明：

```json
"@dcloudio/uni-cli-i18n": "2.0.2-5000720260410001"
```

因为这个包没有对应的 `3.0.0-5000720260410001` 版本，所以保留了同日期族的 `2.0.2`。

---

### 4.2 升级 Vite 主链到兼容版本

把这些版本同步升级到与 DCloud 3.0.0 工具链兼容：

```json
"vite": "5.2.8",
"@vitejs/plugin-vue": "5.2.4"
```

旧版：

- `vite 2.9.18`
- `@vitejs/plugin-vue 1.2.5`

必须放弃，否则会继续报 Vite API 不兼容。

---

### 4.3 安装缺失依赖

本次额外补装了：

- `sass`
- `vuex`
- `@dcloudio/uni-app`

---

### 4.4 修正一个 npm 包名别名问题

项目里原来写的是：

```json
"vite-plugin-multiple-entries2": "^0.0.1"
```

这会导致重新装依赖时报 404。

最终改成：

```json
"vite-plugin-multiple-entries2": "npm:vite-plugin-multiple-entries@0.0.1"
```

这是可正常安装的别名写法。

---

### 4.5 清掉旧 shim 对新版本的污染

之前为了硬顶旧版本构建链，临时做过一些 shim。后来统一版本以后，这些 shim 反而会污染新依赖。

最终保留的 postinstall 只有：

```json
"postinstall": "node ./scripts/create-vue-cli-preprocess-shim.cjs"
```

去掉了旧的 `uni-cli-shared` shim 自动覆盖逻辑。

---

## 5. 这次最终成功的结果

### 5.1 H5 预览成功

H5 编译已经成功，构建产物实际生成到：

```text
/opt/yoshop/yoshop2.0-uniapp/dist/build/h5
```

然后再同步到：

```text
/opt/yoshop/yoshop2.0/public
```

同步后，浏览器访问：

```text
http://localhost/#
```

即可看到最新版本。

我已经验证过：

- `public/index.html` 已更新
- `localhost` 返回 200
- 已经命中新产物入口，例如：
  - `index-OFMmv8lD.js`
- 新资源里有服务订单相关代码和文案

---

### 5.2 微信小程序编译成功

HBuilderX CLI 编译微信小程序成功，产物生成在：

```text
D:\Program\0\home\0\yoshop1\yoshop2.0-uniapp\unpackage\dist\dev\mp-weixin
```

我已经验证过里面有：

- `我的服务订单`
- `服务订单中心`
- `待联系`
- `服务中`

所以微信开发者工具现在可以直接导入这份产物。

---

## 6. 这次新增的一键脚本

为了下次不再手工重复处理，已经新增两个脚本。

### 6.1 H5 一键编译并同步到 localhost 站点

文件：

```text
scripts/build-h5-sync.cjs
```

命令：

```bash
npm run build:h5:sync
```

它会自动：

1. 执行 `npm run build:h5`
2. 读取 `dist/build/h5`
3. 同步 `assets/`、`index.html`、`config.js` 到 `/opt/yoshop/yoshop2.0/public`

---

### 6.2 微信小程序一键编译

文件：

```text
scripts/build-mp-weixin.cjs
```

命令：

```bash
npm run build:mp-weixin
```

它会自动调用：

```text
/mnt/d/Program/tools/HBuilderX/cli.exe
```

去做微信小程序 compile。

---

## 7. 下次正确的使用方式

### 7.1 修改代码后，想看浏览器效果

执行：

```bash
cd /opt/yoshop/yoshop2.0-uniapp
npm run build:h5:sync
```

然后浏览器打开：

```text
http://localhost/#
```

如果没立即变化，按：

```text
Ctrl + F5
```

强制刷新缓存。

---

### 7.2 修改代码后，想重新生成微信小程序产物

执行：

```bash
cd /opt/yoshop/yoshop2.0-uniapp
npm run build:mp-weixin
```

然后在微信开发者工具导入目录：

```text
D:\Program\0\home\0\yoshop1\yoshop2.0-uniapp\unpackage\dist\dev\mp-weixin
```

---

## 8. 下次不要再踩的坑

### 坑 1：不要随便把 DCloud 包混着升/降级

最危险的情况就是：

- `@dcloudio/vite-plugin-uni` 是 3.x
- `@dcloudio/uni-h5` 是 2.x
- `vite` 还是 2.x

这种组合几乎一定炸。

### 建议

如果要升级，**核心 DCloud 包和 Vite 主链一起动**。

---

### 坑 2：HBuilderX CLI 不一定直接使用 WSL 那份 node_modules

HBuilderX CLI 实际用的是镜像项目目录：

```text
D:\Program\0\home\0\yoshop1\yoshop2.0-uniapp
```

所以如果以后再遇到：

- WSL 里没问题
- HBuilderX CLI 还在报旧错

优先怀疑：

> 它是不是还在吃镜像目录里的旧依赖。

---

### 坑 3：不要再恢复旧的 uni-cli-shared shim 覆盖逻辑

旧 shim 是为了解决早期混搭版本问题。

现在版本已经统一，继续用旧 shim 会污染新版本包行为。

---

### 坑 4：`localhost` 看不到变化，不一定是没编译成功

还可能是：

- H5 构建输出到了 `dist/build/h5`
- 但你没同步到 `public`

所以以后不要只跑：

```bash
npm run build:h5
```

而是优先跑：

```bash
npm run build:h5:sync
```

---

## 9. 当前仍然未自动化的部分

### 微信代码上传还没有自动化

原因：当前项目目录里**没有微信代码上传所需的私钥文件**，例如：

```text
private.wxxxxxxxx.key
```

所以现在状态是：

- 浏览器预览：已自动化
- 微信编译：已自动化
- 微信上传：**还缺 upload 私钥**

如果以后你补齐了私钥和 upload 配置，可以再继续加：

```bash
cli publish mp-weixin --project xxx --appid xxx --upload true --version x.x.x --privatekey xxx --description xxx
```

---

## 10. 推荐的后续日常流程

### 看 H5

```bash
cd /opt/yoshop/yoshop2.0-uniapp
npm run build:h5:sync
```

浏览器：

```text
http://localhost/#
```

---

### 看微信小程序

```bash
cd /opt/yoshop/yoshop2.0-uniapp
npm run build:mp-weixin
```

微信开发者工具导入：

```text
D:\Program\0\home\0\yoshop1\yoshop2.0-uniapp\unpackage\dist\dev\mp-weixin
```

---

## 11. 一句话总结

这次真正把编译修通的关键，不是单点修代码，而是：

> **统一 DCloud 版本组 + 升级 Vite 到 5.x + 补齐缺失运行时依赖 + 用脚本固定编译/同步流程。**

以后你只要记住两条命令：

```bash
npm run build:h5:sync
npm run build:mp-weixin
```

基本就够了。
