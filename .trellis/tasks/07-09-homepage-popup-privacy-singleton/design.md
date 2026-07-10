# 设计说明：Homepage popup and privacy singleton content

## 1. 设计目标

该子任务只解决两件事：
1. 首页业务弹窗的“账号级单次触达”；
2. 隐私协议的“单例内容展示”。

设计上坚持轻量方案：
- 单例内容走 `wxapp_setting.basic`，不扩成文章/CMS 工作流；
- 展示状态走账号字段，不接受仅前端本地缓存；
- 首页业务弹窗依赖现有微信隐私弹窗的 `end` 时机，避免双弹窗冲突。

## 2. 边界

### 拥有范围
- `wxapp_setting.basic` 的 3 个新增字段
- 小程序设置页中的两个新配置区块
- 首页首登弹窗 API 与前端组件
- `user.first_login_popup_seen_time`
- 隐私协议专页与“我的”页隐私入口

### 不拥有范围
- 反馈/投诉表单、记录、后台处理
- 服务订单 checkout / search / soft delete hide

## 3. 数据与接口设计

### 3.1 设置存储
扩展 `wxapp_setting.basic`：
- `firstLoginPopupEnabled: bool`
- `firstLoginPopupBody: string`
- `privacyAgreementContent: string`

### 3.2 账号级单次触达状态
在 `user` 表新增：
- `first_login_popup_seen_time int unsigned not null default 0`

语义：
- `0` = 该账号尚未消费展示机会
- `>0` = 该账号已消费展示机会

### 3.3 API 设计
建议最少新增两个接口：
- 隐私协议内容读取接口
- 首页弹窗判断接口（需要登录）

首页弹窗接口行为：
1. 读取 `wxapp_setting.basic`
2. 若开关关闭、正文为空、未登录或已展示，则返回 `show=false`
3. 若满足展示条件，在同一请求中写入 `first_login_popup_seen_time`，再返回 `show=true` 与正文

这样可用最小逻辑保证幂等，不再额外设计 ack 接口。

## 4. 前端交互设计

### 4.1 首页时序
1. 首页加载
2. 先渲染现有 `PrivacyPopup`
3. 等待 `PrivacyPopup` 发出 `end`
4. 再请求业务弹窗接口
5. 决定是否展示业务弹窗

### 4.2 业务弹窗组件
- 独立组件承载展示与关闭逻辑
- 正文使用后台配置内容
- 点击正文容器外或遮罩层都关闭

### 4.3 隐私协议页面
- 独立 `pages/user/privacy.vue`
- 只展示标题与富文本正文
- 不承接文章浏览量/发布时间等无关字段

## 5. 依赖与协同设计

### 5.1 与 Child B 的共享文件协调
共享文件：
- `pages/user/index.vue`
- `pages.json`

协同策略：
- A 先落地隐私入口与页面注册；
- B 后续在同一结构上继续增加反馈入口与反馈页面注册；
- 若并行推进，B 必须合并 A 的结构性修改，不得回退入口布局。

### 5.2 与 Child C 的关系
- 无运行时依赖；
- 配置、页面、账号触达状态都不影响服务订单链路。

## 6. 回滚与运维边界

- 首先可通过后台开关关闭业务弹窗，作为最快止损路径；
- 若页面实现有问题，可单独回退首页业务弹窗组件与调用；
- 账号级 `seen_time` 字段保留不会影响其他功能；
- 隐私协议页面可独立回退，不影响首页弹窗逻辑。

## 7. 结论

这是一个低耦合、低回滚成本的子任务；它最适合作为父任务拆分后的第一个 Phase 2 启动目标。
