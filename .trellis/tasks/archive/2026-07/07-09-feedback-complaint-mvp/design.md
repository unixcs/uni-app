# 设计说明：Feedback and complaint MVP

## 1. 设计目标

本子任务只做“单向工单 MVP”：
- 用户提交一次；
- 后台处理状态并填写官方回复；
- 用户查看处理进度与回复；
- 不扩成双向会话系统。

## 2. 边界

### 拥有范围
- 反馈主表
- 小程序 feedback create/list/detail
- 小程序反馈页与详情页
- 后台反馈列表与处理页
- “我的”页反馈入口

### 不拥有范围
- 首页业务弹窗与隐私协议配置
- 服务订单 checkout / 搜索 / 历史清理
- 聊天式工单、推送通知、订单强绑定

## 3. 数据模型设计

建议新增表：`yoshop_user_feedback`

核心字段：
- `feedback_id`
- `user_id`
- `store_id`
- `issue_type`
- `status`
- `content`
- `mobile`
- `image_ids`（JSON array of file ids）
- `reply_content`
- `reply_time`
- `create_time`
- `update_time`
- `is_delete`

### 为什么不拆图片子表
- MVP 不需要独立图片检索；
- 现有上传直接返回 file_id；
- 单条记录图片量有限；
- JSON 数组足够且更快落地。

## 4. 接口与页面设计

### 4.1 小程序接口
- `POST /feedback/create`
- `GET /feedback/list`
- `GET /feedback/detail`

职责：
- `create`：校验后写库
- `list`：仅返回当前登录用户自己的记录
- `detail`：返回当前登录用户自己的单条记录详情

### 4.2 小程序页面
- `pages/feedback/index.vue`
- `pages/feedback/detail.vue`

`index.vue`：
- Tab1：我要反馈
- Tab2：反馈记录

`detail.vue`：
- 展示 issue type、content、image preview、mobile、status、reply、update time

### 4.3 商家后台
- 列表页：筛选 issue type / status / mobile / user
- 详情或抽屉：查看内容、图片、用户信息
- 编辑动作：修改状态、填写官方回复

## 5. 依赖与协同设计

### 5.1 与 Child A 的共享文件协调
共享文件：
- `pages/user/index.vue`
- `pages.json`

推荐策略：
- A 先落地隐私入口与基础结构；
- B 在其基础上增加反馈入口与反馈页面注册；
- 若 A/B 交错修改，B 必须保留 A 已合入的入口与布局结构。

### 5.2 与 Child C 的关系
- 无业务耦合；
- 可独立测试与回滚。

## 6. 回滚与运维边界

- 前台入口、后台菜单、路由可独立关闭；
- 反馈表保留不会影响其他主链路；
- 临时停用时，不需要清理历史反馈数据。

## 7. 结论

这是新增能力但与订单主链路低耦合；适合作为 Child A 之后的第二个启动目标。
