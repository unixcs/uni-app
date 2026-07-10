# 实施计划：Homepage popup and privacy singleton content

## 执行原则

- 保持范围只在“首页弹窗 + 隐私协议单例内容”。
- 不顺手把反馈入口或服务订单改造带进本子任务。
- 进入实现前仍需要用户明确批准并执行本子任务自己的 `task.py start`。

## 阶段 1：设置与数据基础

- [x] 扩展 `app/common/model/wxapp/Setting.php` 默认 `basic` 配置：
  - `firstLoginPopupEnabled`
  - `firstLoginPopupBody`
  - `privacyAgreementContent`
- [x] 扩展 `app/store/model/wxapp/Setting.php` 保存白名单。
- [x] 在 `user` 表增加 `first_login_popup_seen_time` 字段。
- [x] 更新必要的 model / migration / install 记录。

验证：
- 后台能加载/保存新字段；
- 用户字段默认值正确。

## 阶段 2：后台设置页与 API

- [x] 调整后台编辑入口：移出 `yoshop2.0-store/src/views/client/wxapp/Setting.vue`，改由 `店铺管理 -> 内容编辑 -> 首页首登弹窗与隐私协议` 承载：
  - 首页首登弹窗设置区块
  - 隐私协议设置区块
- [x] 新增隐私协议内容读取 API。
- [x] 新增首页弹窗判断 API，并在首次命中时写入 `seen_time`。

验证：
- 设置项保存后重新进入页面可回显；
- 同一账号首次命中 `show=true`，再次命中 `show=false`。

## 阶段 3：小程序接入

- [x] 新增首页业务弹窗组件。
- [x] 在首页通过 `PrivacyPopup @end` 串行触发业务弹窗判断。
- [x] 新增 `pages/user/privacy.vue`。
- [x] 在 `pages/user/index.vue` 增加 `隐私协议` 入口。
- [x] 在 `pages.json` 注册隐私协议页面。

验证：
- 微信隐私弹窗与业务弹窗不会同屏竞争；
- 业务弹窗可关闭；
- 隐私协议页面可正常进入与加载内容。

## 阶段 4：共享文件协调门槛

- [x] 已在共享文件最新结构上承接并保留隐私协议入口。
- [x] 已与反馈入口/页面注册改动合并，避免共享文件互相覆盖。

## 回滚点

- 最快回滚：后台关闭 `firstLoginPopupEnabled`。
- 次级回滚：移除首页业务弹窗调用与组件。
- 独立回滚：移除隐私协议入口与专页。
- 数据层回滚：`first_login_popup_seen_time` 可保留，不必强制回收。

## 启动前检查

- [x] 用户已明确批准启动 Child A
- [x] Child A `prd.md` / `design.md` / `implement.md` 已 review
- [x] 当前已直接实施并交付，不再沿用 planning 约束


## 本轮实际交付（2026-07-10）

- 扩展 `wxapp_setting.basic` 的首页首登弹窗与隐私协议单例内容字段。
- 新增账号级首登弹窗命中与消费逻辑，并在首页通过 `PrivacyPopup` 结束事件串行触发。
- 新增 `我的 -> 隐私协议` 页面与前端读取接口。
- 商家后台将对应编辑入口调整为 `店铺管理 -> 内容编辑 -> 首页首登弹窗与隐私协议`，不再放在 `微信小程序设置` 主表单中。
- 已与反馈/投诉子任务共享文件完成合并，不存在入口互相覆盖。

## 已执行验证（2026-07-10）

- 商家后台目标文件 lint 通过。
- PHP 相关模型 / 控制器语法检查通过。
- 商家后台新构建产物已部署到 `yoshop2.0/public/store`。
- 用户已手动测过当前阶段功能，暂未发现问题。
