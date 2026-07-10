# Homepage popup and privacy singleton content

## Goal

交付首页首登业务弹窗与隐私协议单例内容能力：
- 后台可配置首登弹窗开关与正文；
- 后台可维护隐私协议内容；
- 小程序首页在微信隐私弹窗结束后，再按“账号只触达一次”规则决定是否展示业务弹窗；
- “我的”页提供隐私协议入口并进入专用页面。

## Confirmed facts

- 现有 `components/privacy-popup/index.vue` 已通过 `end` 事件给出隐私弹窗结束时机，可作为业务弹窗展示的串行触发点。
- 现有首页业务内容并不是可配置的业务弹窗；当前只有微信隐私授权弹窗。
- `wxapp_setting.basic` 已是现成的小程序配置单例入口，适合作为这次单例内容的存储位置。
- `pages/user/index.vue` 当前没有 `隐私协议` 入口。
- `pages/article/detail.vue` 带文章元数据，不适合作为隐私协议单例页。
- `pages/user/index.vue` 与 `pages.json` 也是 Child B 的共享文件，需要显式协调。

## Requirements

### R1. 配置能力
- 在 `wxapp_setting.basic` 中扩展：
  - `firstLoginPopupEnabled`
  - `firstLoginPopupBody`
  - `privacyAgreementContent`
- 商家后台在现有小程序设置页中提供对应编辑能力。

### R2. 首页首登弹窗
- 仅在微信小程序端按既有首页链路接入。
- 展示条件必须以“账号”为准，而不是本地缓存或设备维度。
- 同一账号只应消耗一次展示机会。
- 业务弹窗必须在 `PrivacyPopup` 结束后才允许尝试展示。
- 点击弹窗内外任意处均可关闭，不引入额外 ack 流程。

### R3. 隐私协议专页
- 新增独立隐私协议页面，只承载标题与富文本正文。
- 内容由专用 API 返回，不复用文章详情页元数据。
- “我的”页新增 `隐私协议` 入口。

## Dependencies

### 逻辑依赖
- 不依赖 Child B 或 Child C 的业务完成即可独立实现。

### 文件级依赖
- 与 Child B 共享：
  - `yoshop2.0-uniapp/pages/user/index.vue`
  - `yoshop2.0-uniapp/pages.json`
- 协调要求：
  - 推荐 Child A 先启动，先稳定“我的”页入口结构；
  - Child B 必须基于 A 落地后的文件形态继续加反馈入口，不能覆盖回旧结构。

## Acceptance criteria

- [ ] 后台可在小程序设置中保存首登弹窗开关、弹窗正文、隐私协议正文。
- [ ] 同一账号首次命中时可看到业务弹窗，再次进入首页不重复弹出。
- [ ] 未登录、开关关闭、正文为空或已消费过展示机会时，不会误弹业务弹窗。
- [ ] 微信隐私弹窗存在时，业务弹窗只会在其结束后再尝试展示。
- [ ] “我的”页可进入隐私协议专页，页面只展示隐私协议相关内容。
- [ ] 对 Child B 的共享文件协调方式已在 artifact 中写清。

## Rollback boundary

- 后台可通过关闭 `firstLoginPopupEnabled` 快速停用业务弹窗展示。
- `first_login_popup_seen_time` 属于增量字段，停用功能时可保留，不要求同步回收。
- 隐私协议入口与专页可单独回退，不影响订单、反馈等其他主链路。

## Out of scope

- 反馈入口、反馈提交与后台处理。
- 文章系统改造或通用 CMS 设计。
- 服务订单字段契约、搜索改造、历史订单清理。

## Open questions

- None at the moment.
