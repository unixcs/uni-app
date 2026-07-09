# 实施计划：商城后台虚拟支付道具配置改造

## 执行原则
- 严格按 grill 最终计划执行，不自行扩 scope。
- 以后端为规则 owner；`goods_price` 为唯一价格源。
- `vp_product_name` 仅做镜像字段，不进入支付核心。
- 先最小落库、再后端收口、再前端接入、最后做兼容验证。

## Step 1. 数据库最小变更
- [ ] 为商品表新增 `vp_product_name` 字段。
- [ ] 将变更记录到项目既有数据库修改记录文件中，字段默认空字符串。
- [ ] 不做历史数据批量修复。

回滚点：
- 删除新增字段 SQL 记录或提供对应回滚 SQL（仅文档级）。

## Step 2. 后端规则收口
- [ ] 在 `app/store/model/Goods.php` 接收 `vp_product_name`。
- [ ] 提炼统一价格推导函数：
  - 输入：`goods_price`
  - 输出：`vp_product_id` / `vp_product_name` / `vp_price_snapshot`
- [ ] 在保存校验中实现：
  - 单规格限制
  - 服务商品限制
  - 价格 > 0
  - `<1` 元三位分规则
  - `>=1` 元整数规则
- [ ] `vp_enabled=0` 时清空三个字段。
- [ ] `vp_enabled=1` 时按后端规则重算并收口保存值。
- [ ] 错误信息保持业务可理解，不把规则只留在前端。

验证：
- 运行 PHP 语法检查。
- 必要时构造最小示例进行本地保存逻辑验证。

回滚点：
- 保持规则逻辑集中在单一函数，若行为不符可局部回退而不影响支付链路。

## Step 3. 后台商品表单接入
- [ ] `Index.js` 增加 `vp_product_name` 回显字段。
- [ ] Create/Update 接入统一 helper / mixin，不在页面散写规则。
- [ ] 增加 `vp_product_name` 表单项。
- [ ] 将 `vp_price_snapshot` 改为只读展示。
- [ ] 商品价格变化时联动生成三个字段。
- [ ] 关闭虚拟支付时清空展示值。
- [ ] 编辑页初始化时识别旧值是否符合自动规则。
- [ ] 错误定位补上 `vp_product_name`。

验证：
- 前端静态检查 / 构建（若项目命令可用）。
- 手工检查 Create/Update 主要表单逻辑。

回滚点：
- helper/mixin 与页面接入分离，页面异常时可先局部移除接入而保留规则模块。

## Step 4. 支付链路兼容确认
- [ ] 检查 `Payment.php` 保持只依赖 `vp_product_id` / `vp_price_snapshot`。
- [ ] 若无需改动则保持不改；若需说明则补最小注释，不改协议。
- [ ] 检查其他虚拟支付诊断/命令文件是否因新字段必须同步；非必要不动。

验证：
- 搜索确认 `vp_product_name` 未进入支付核心调用链。

## Step 5. 质量检查
- [ ] 对修改过的 PHP 文件执行 `php -l`。
- [ ] 对修改过的前端模块执行可用的 lint / build / 单文件语法检查。
- [ ] 自查是否违反四条硬约束：
  - `goods_price` 唯一价格源
  - 后端规则 owner
  - 支付核心不扩 scope
  - `vp_product_name` 仅镜像

## 本次预计改动文件
- `yoshop2.0/数据库修改记录/v2.1.0.sql`
- `yoshop2.0/app/store/model/Goods.php`
- `yoshop2.0-store/src/common/model/goods/Index.js`
- `yoshop2.0-store/src/views/goods/Create.vue`
- `yoshop2.0-store/src/views/goods/Update.vue`
- `yoshop2.0-store/src/views/goods/modules/virtualPayment.js`
- `yoshop2.0-store/src/views/goods/modules/virtualPaymentFormMixin.js`
- （按需）极少量校验/说明文件

## 计划后的立即动作
1. 复核 artifacts 与 grill 最终计划是否一致。
2. `task.py start` 进入 in_progress。
3. 读取 `trellis-before-dev` 并开始实现。
