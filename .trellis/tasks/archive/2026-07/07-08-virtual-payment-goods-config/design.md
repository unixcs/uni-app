# 设计说明：商城后台虚拟支付道具配置改造

## 1. 设计目标

以最小改造完成商品后台的虚拟支付配置收口：
- 商品价格是唯一真相源。
- 后端统一推导、校验并最终写库。
- 前端只负责回显、联动、预校验与操作提示。
- 支付核心链路不引入 `vp_product_name` 依赖。

## 2. 现状分析

### 后端现状
- `app/store/model/Goods.php`
  - 当前只接收 `vp_enabled`、`vp_product_id`、`vp_price_snapshot`。
  - 现有校验仅验证单规格、服务商品、价格快照与商品售价一致。
  - 还没有按价格生成 `vp_product_id` / `vp_product_name` 的统一规则函数。
- `app/api/service/cashier/Payment.php`
  - 现有支付流程只消费 `vp_product_id` 和 `vp_price_snapshot`。
  - 订单回补逻辑也只关心 `vp_enabled`、`vp_product_id`、`vp_price_snapshot`。
  - 这条链路不应引入 `vp_product_name`。

### 前端现状
- `yoshop2.0-store/src/views/goods/Create.vue` / `Update.vue`
  - 当前只展示 `vp_product_id`、`vp_price_snapshot`。
  - 无 `vp_product_name` 表单项。
  - 没有价格联动、手动修改识别、规则性预校验。
- `yoshop2.0-store/src/views/goods/modules/virtualPayment.js`
  - 已存在价格转分、自动生成配置、匹配判断 helper 草稿。
- `yoshop2.0-store/src/views/goods/modules/virtualPaymentFormMixin.js`
  - 已存在联动、初始化、前端校验 mixin 草稿，但尚未接入 Create/Update。
- `yoshop2.0-store/src/common/model/goods/Index.js`
  - 回显字段尚未包含 `vp_product_name`。

### 数据现状
- 商品表已存在 `vp_enabled`、`vp_product_id`、`vp_price_snapshot`。
- 需补充 `vp_product_name`。

## 3. 设计决策

### 3.1 数据结构
在商品表新增：
- `vp_product_name varchar(...) not null default ''`

用途：
- 后台运营识别。
- 回显与编辑。
- 保存时参与一致性校验。

不用于：
- 支付协议。
- 支付下单核心参数。

### 3.2 规则 owner 放在后端
在 `app/store/model/Goods.php` 中新增统一规则推导逻辑：
- 输入：`goods_price`
- 输出：
  - `vp_product_id`
  - `vp_product_name`
  - `vp_price_snapshot`

后端保存流程：
1. 先判断 `vp_enabled`。
2. 若关闭：直接清空三个字段。
3. 若开启：
   - 校验单规格。
   - 校验服务商品。
   - 校验价格大于 0。
   - 校验 `>= 1` 元时必须整数。
   - 按规则推导期望值。
   - 以后端期望值为准，对提交值做一致性校验并收口。

实现策略：
- 采用“后端自行推导 + 覆盖保存值”的方式收口，而不是依赖前端提交结果。
- 如需保留手动编辑入口，仅把它作为 UI 层体验；最终落库仍由后端规则值决定。
- 若价格不满足规则，后端直接报错。

### 3.3 前端只做展示与预校验
前端行为：
- 新增 `vp_product_name` 表单项并回显。
- `vp_price_snapshot` 改为只读展示，不允许用户自由填写。
- 价格变化时自动联动三个字段。
- 关闭虚拟支付时清空展示值。
- 编辑页初始化时根据当前值是否匹配自动规则，决定提示“自动联动”还是“已切到手动检查”。
- 即便保留手动输入入口，也只做提示和预校验；不能假设提交值一定会落库。

实现复用：
- 优先接入并修正现有 `virtualPayment.js`、`virtualPaymentFormMixin.js`。
- 避免把同一套规则散落在 `Create.vue` 与 `Update.vue`。

### 3.4 支付链路边界
`Payment.php` 只做兼容确认，不扩 scope：
- 保持继续使用 `vp_product_id`、`vp_price_snapshot`。
- 不将 `vp_product_name` 引入 unified order、trade record 或订单回补核心逻辑。
- 如有需要，仅补注释或极小兼容性确认，不做协议字段扩展。

## 4. 影响文件

### 必改
- `yoshop2.0/数据库修改记录/v2.1.0.sql` 或项目约定的数据库变更记录文件
- `yoshop2.0/app/store/model/Goods.php`
- `yoshop2.0-store/src/common/model/goods/Index.js`
- `yoshop2.0-store/src/views/goods/Create.vue`
- `yoshop2.0-store/src/views/goods/Update.vue`
- `yoshop2.0-store/src/views/goods/modules/virtualPayment.js`
- `yoshop2.0-store/src/views/goods/modules/virtualPaymentFormMixin.js`

### 只读确认 / 尽量不改
- `yoshop2.0/app/api/service/cashier/Payment.php`

### 可选补充校验
- `yoshop2.0/app/common/command/VirtualPaymentSandboxCheck.php`
  - 如果该工具需要展示或校验 `vp_product_name`，可最小补充；否则不必扩动。

## 5. 风险与控制

### 风险 1：前后端规则不一致
控制：
- 后端统一收口并覆盖写库。
- 前端 helper 尽量复刻同一算法，仅作体验增强。

### 风险 2：旧数据编辑后被拦截
控制：
- 视为预期行为。
- 错误信息明确指出价格与规则不符。

### 风险 3：微信侧未创建道具导致支付失败
控制：
- 不在本次范围解决。
- 在交付说明中明确这是外部前置条件。

### 风险 4：误把 `vp_product_name` 带入支付核心
控制：
- 明确支付核心不读取该字段。
- 支付链路只做兼容确认。

## 6. 验证思路

### 后端验证
- 新增商品：`158` 自动生成 `vip158 / vip158 / 15800`。
- 新增商品：`0.01` 自动生成 `vip001 / vip001 / 1`。
- 新增商品：`0.11` 自动生成 `vip011 / vip011 / 11`。
- 新增商品：`9.9` 开启虚拟支付时报错。
- 多规格商品开启虚拟支付时报错。
- 非服务商品开启虚拟支付时报错。
- 关闭虚拟支付后保存，三个字段被清空。
- 旧数据编辑时若不符合新规则，保存时报错或被规则收口。

### 前端验证
- Create/Update 页切换虚拟支付开关时字段联动正确。
- 修改商品价格后自动更新三个字段。
- `vp_price_snapshot` 只读。
- 错误定位可覆盖 `vp_product_name`。
- 编辑页加载旧数据时自动识别是否符合自动规则。
