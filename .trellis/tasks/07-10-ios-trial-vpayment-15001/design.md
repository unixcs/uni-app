# Design — iOS Apple 虚拟支付退款产品/技术对齐

## 1. Problem Statement

当前问题已经分裂成两个层次：

1. **支付创建层**：iOS `0.02 元` 商品因违反 Apple 最低 `1 元` 规则，导致上游订单未创建，出现 `-15001`。
2. **退款控制层**：iOS `1 元` 商品可成功走到 App Store 支付，但退款仍沿用“开发者主动退款”产品模型，和 Apple 平台事实冲突，导致用户侧与商家侧同时失败。

本设计聚焦第二层：**让 iOS Apple 支付订单的退款入口、状态机、后端回调、运营认知保持一致。**

---

## 2. First Principles

### 2.1 不可改变的事实

- iOS Apple 虚拟支付订单的**退款决策权不在本系统**，而在 Apple / App Store。
- 开发者系统可以：
  - 记录本地退款意图；
  - 接收 Apple 退款问询；
  - 接收微信退款结果通知；
  - 展示退款状态。
- 开发者系统不可以：
  - 对 iOS Apple 订单继续调用开发者主动退款接口，并承诺“按原路退款”。

### 2.2 用户真正要的不是“按钮”，而是“退款可达成 + 可追踪”

用户核心诉求是：
- 知道去哪里退款；
- 知道系统是否已经记录；
- 知道退款进行到哪一步；
- 最终若 Apple 同意，钱能到账。

### 2.3 商家/客服真正要的不是“手工点退款”，而是“能识别、能引导、能跟进”

商家侧真正需要的是：
- 一眼识别这是 iOS Apple 退款单；
- 不再做无效的主动退款操作；
- 能查看当前链路状态；
- 能对用户进行正确引导。

---

## 3. Razor / Scope Rule（剃刀定律）

> 只做被事实直接要求的最小改动，不引入不必要的新流程。

因此本次**不**做：
- 自建复杂人工审核工作流；
- 自建 iOS 退款审批系统替代 Apple；
- 为 iOS 单独设计全新退款表结构；
- 大范围改动 Android / 非 Apple 虚拟支付退款逻辑。

本次**只**做：
1. 保留并审查已有 backend spike；
2. 补足前端/后台入口分流；
3. 给前端暴露必要的平台感知字段；
4. 把 `waiting_ios_apple_refund` 翻译成用户和商家可读状态；
5. 跑通验证、复测、上线与线上校验闭环。

---

## 4. Chosen Product Strategy

### 4.1 选型

采用：

> **保留本地退款登记/跟踪能力，但停止“开发者主动退款”语义；用户走 App Store 退款，系统只做引导、记录、回调收口与状态展示。**

### 4.2 为什么不是“彻底隐藏入口”

彻底隐藏入口虽然简单，但会丢失：
- 用户侧退款指引触点；
- 客服侧本地跟踪痕迹；
- Apple 退款回调对应的业务承接面；
- 后续运营分析与投诉定位信息。

### 4.3 为什么不是“保持现状”

保持现状的问题是：
- 用户会误以为小程序可以直接退；
- 商家会误以为后台可以直接退；
- 平台规则命中会持续被误判成“系统异常”。

---

## 5. Current State Audit

### 5.1 已有实现（保留候选）

当前 backend spike 已经完成：
- `Notify.php`
  - 支持 `xpay_subscribe_ios_refund_query_notify`
  - 返回官方 `IosRefundQueryResponse`
  - 默认在无明确拒退依据时建议退款
- `Refund.php`
  - 识别 iOS Apple 订单
  - 不再调用 `/xpay/refund_order`
  - 进入 `waiting_ios_apple_refund`
  - 停止对该状态做主动 `query_order` 轮询
  - 增加退款链路观测日志

### 5.2 当前缺口

1. **用户侧入口语义错误**
   - 仍展示通用“退款”按钮
   - 仍展示“提交退款申请”页面
2. **商家侧操作语义错误**
   - 仍展示“服务前退款”按钮
   - 仍提示“按原路退款”
3. **API 契约不够显式**
   - 前端拿不到明确的 iOS Apple 退款模式字段
4. **状态展示过于泛化**
   - `退款处理中` / `退款审核中` 不能表达 Apple 实际阶段
5. **流程验证尚未闭环**
   - 缺用户侧指引验证
   - 缺商家侧误操作防呆验证
   - 缺 callback → 页面状态 → 业务验收闭环

---

## 6. Target Design

## 6.1 Domain Model（最小新增概念）

不新增表，优先基于现有 `payment_trade.payload_snapshot` 与订单/退款投影扩展下列语义：

- `ios_apple_refund_required: bool`
- `refund_entry_mode: enum`
  - `developer_refund`
  - `app_store_guided`
- `refund_guidance: string`
- `refund_display_state: string`
- `refund_display_state_text: string`

### 设计原则
- **内部状态继续保留细粒度**，如 `waiting_ios_apple_refund`
- **对前端暴露语义化投影**，避免页面直接猜 snapshot

---

## 6.2 User-Side Flow

### 适用对象
- iOS Apple 已支付虚拟订单

### 目标行为
- 用户仍能看到退款入口，但语义改为：
  - `申请退款协助`
  - `查看退款指引`
  - 或类似弱承诺文案
- 进入页面后明确提示：
  - 需前往 App Store 申请退款；
  - 本系统仅记录诉求并同步处理 Apple 问询/退款结果。

### 页面最小变更
1. 订单详情页：
   - 不再显示强语义“退款”按钮；
   - 改为平台感知按钮/说明。
2. 退款申请页：
   - 改文案；
   - 保留填写原因、凭证上传、登记说明；
   - 页面内展示 App Store 指引。
3. 退款详情页：
   - 展示 Apple 化状态，而不是纯通用状态。

---

## 6.3 Merchant/Admin Flow

### 目标行为
- iOS Apple 订单不再提供“服务前退款”主动退款操作。
- 商家可以：
  - 查看这是 iOS Apple 退款单；
  - 查看当前退款状态；
  - 查看/补充备注；
  - 引导用户去 App Store。

### 页面最小变更
1. 订单详情页：
   - 隐藏或禁用 `服务前退款`；
   - 替换为说明/状态展示。
2. 确认文案：
   - 去掉“按原路退款并关闭当前服务单”这类错误承诺。
3. 退款信息区：
   - 优先显示 `Apple退款流程中` 等平台感知状态。

---

## 6.4 API Contract Changes

### 用户侧订单详情 API
文件：`app/api/model/Order.php`

建议新增或扩充：
- `action_flags.refund_entry_mode`
- `action_flags.ios_apple_refund_required`
- `refund_info.display_state`
- `refund_info.display_state_text`
- `refund_info.guidance`

### 商家侧订单详情 API
文件：`app/store/model/Order.php`

建议新增或扩充：
- `backend_action_flags.ios_apple_refund_required`
- `backend_action_flags.refund_entry_mode`
- `virtual_payment_summary.refund_guidance`
- `virtual_payment_summary.refund_display_state`

### 契约原则
- 前端**不直接解析 payload_snapshot**；
- 一切平台分流都通过后端显式字段完成；
- Android / 非 Apple 保持原契约兼容。

---

## 6.5 Refund State Mapping

### 内部状态 -> 用户显示
| Internal | User display | Notes |
|---|---|---|
| `waiting_ios_apple_refund` + 未见 inquiry | 待前往 App Store 申请退款 | 用户需要先去发起 |
| `waiting_ios_apple_refund` + inquiry arrived | Apple 退款处理中 | 已进入 Apple 链路 |
| `refund_notify completed` | 已退款 | 结果完成 |
| explicit reject evidence / upstream reject | 退款未通过 | 需给出说明 |

### 内部状态 -> 商家显示
| Internal | Merchant display | Notes |
|---|---|---|
| `waiting_ios_apple_refund` + no inquiry | 等待用户在 App Store 申请退款 | 不可主动退款 |
| inquiry received | 等待 Apple 最终结果 | 已响应问询 |
| completed | 已退款 | 可结束跟进 |
| rejected / failed | Apple 未通过退款 | 需要人工解释 |

---

## 7. Keep / Revise / Revert Review of Existing Spike

### 7.1 Keep
- `Notify.php` 对 `xpay_subscribe_ios_refund_query_notify` 的接入
- `Refund.php` 中 iOS Apple 订单不再调用 `/xpay/refund_order`
- `waiting_ios_apple_refund` 的本地等待态
- `skip_query_order` 的补偿逻辑
- 结构化 observability 日志

### 7.2 Revise
- 对外字段与文案需要产品化命名
- `suggest_refund` 默认策略需要通过业务评审确认边界
- 状态投影要从“工程内部可懂”提升为“用户/商家可懂”

### 7.3 Revert
- 当前暂无必须整体回滚的点
- 若后续评审发现某段回调响应策略不符合官方契约，再做局部修正

---

## 8. Execution Plan

### Phase A — 方案规划（当前阶段）
输出：
- `prd.md`：问题边界、业务目标、开放问题
- `design.md`：本文件
- `implement.md`：记录已有 spike 与后续执行项

### Phase B — 方案评审
评审问题：
1. 是否接受“保留登记能力，但不再承诺开发者主动退款”？
2. 用户侧入口文案最终定什么？
3. 商家侧是否完全隐藏按钮，还是禁用并提示原因？
4. 默认 `suggest_refund` 是否满足当前业务风控？

### Phase C — 编码开发
最小开发顺序：
1. 后端补显式投影字段
2. 用户侧 uniapp 页面改造
3. 商家后台页面改造
4. 必要的文案与状态映射统一

### Phase D — 开发自测
- PHP 语法检查
- 前端关键页面静态检查
- 订单详情 / 退款申请 / 退款详情联调检查
- 商家订单详情按钮显示检查
- iOS Apple / Android 分流检查

### Phase E — 代码评审
重点 review：
- 是否有 Android 回归风险
- 是否仍存在任何主动调用 iOS `/xpay/refund_order`
- 页面文案是否仍有“按原路退款”错误承诺
- 字段命名是否足够稳定清晰

### Phase F — 测试（缺陷复测闭环）
测试用例最少应覆盖：
1. Android 普通虚拟支付退款不受影响
2. iOS `0.02 元` 商品前置拦截/提示正确
3. iOS `1 元` 支付成功后，用户入口正确引导 App Store
4. 商家后台不再能主动发起 iOS Apple 退款
5. 收到 Apple 问询回调后状态更新正确
6. 收到退款结果通知后订单/退款状态收口正确
7. 缺陷修复后复测闭环记录完整

### Phase G — 业务验收
验收关注点：
- 用户是否理解“去 App Store 申请退款”
- 商家是否不再误以为后台可直接退款
- 客服是否能追踪退款诉求和状态

### Phase H — 上线
上线前检查：
- 配置、代码、文案版本一致
- 关键日志字段可检索
- 回滚方案明确（仅限新增投影/UI 变化，backend spike 不建议整块回滚）

### Phase I — 线上校验
线上至少校验：
- 新 iOS Apple 订单是否正确显示入口
- Apple 问询日志是否正常入库
- iOS 退款结果通知是否正常收口
- 无 Android / 非 Apple 退款异常回归

---

## 9. Risks

1. **产品语义风险**：页面若继续出现“按原路退款”，会再次制造错误预期。
2. **兼容性风险**：前端若用新字段分流，需保证老订单/非 Apple 订单默认值稳定。
3. **回调稀缺风险**：Apple 问询样本可能少，需依赖日志与模拟证据做阶段性验证。
4. **状态投影风险**：若只改后端而不改文案，用户仍会认为系统故障。

---

## 10. Acceptance Criteria

- iOS Apple 订单不再向用户/商家暴露“开发者主动退款”错误语义。
- 用户能得到明确的 App Store 退款指引。
- 商家后台不能再对 iOS Apple 订单执行“服务前退款”主动退款。
- 已有 backend spike 被纳入正式设计并通过 review，而不是继续无计划扩散。
- Android / 非 Apple 虚拟支付退款路径保持兼容。
- 从本地登记 → Apple 问询 → 微信退款通知 → 本地状态收口，全链路具备可观测性。

---

## 11. Decision for Next Step

下一步不是继续随手编码，而是：

1. 以本设计为基础完成方案评审；
2. 确认保留 backend spike；
3. 再进入最小实现序列：**后端投影字段 → 用户侧入口/文案 → 商家侧入口/文案 → 联调验证**。
