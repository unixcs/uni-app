# 修复服务订单操作权限

## Goal

建立“角色菜单 → 菜单/API绑定 → 后端路由校验 → 前端按钮显示”的闭环，使服务订单动作按最小权限原则可授权、可迁移、可验证。

## Background

- 后端每个商家请求都在 `yoshop2.0/app/store/controller/Controller.php:68-105` 调用权限校验；非超管必须在角色派生的 API URL 列表中命中当前路由。
- API URL 来源链为用户角色 → `store_role_menu` → `store_menu_api` → `store_api`，见 `yoshop2.0/app/store/service/Auth.php:140-155`。
- 三个接口已实现，但数据库没有对应 `store_api` 行或 `store_menu_api` 绑定：
  - `/order.event/startService`
  - `/order.event/completeService`
  - `/order.event/refundBeforeService`
- 前端当前用 API URL 字符串调用 `$auth`，但 `$auth` 的合同是 `菜单路径.动作标识`，见 `yoshop2.0-store/src/utils/helper/permission.js:18-46`；`Detail.vue:272-279` 因此只能依赖旧 `deliver/cancel` 回退显示按钮。
- 现有清理脚本 `yoshop2.0/数据库修改记录/v2.1.3_service_order_menu_cleanup.sql:1-12` 不在正式部署迁移目录、只清理部分发货记录且不新增服务权限。正式迁移合同位于 `deploy/migrations/README.md:1-6`。
- 本机数据库证据（2026-07-16）：`guanxing` 非超管，同时绑定 `运营人员(10001)` 和 `客服人员(10002)`；运营角色在角色树中持有“订单处理”，数据库以其已选直接子项 `订单导出(10202)` 表示（父节点 10201 不单独落库），但三个新 API 均不存在。详细证据见 `research/permission-audit.md`。

## Requirements

- R1：在角色管理权限树中新增三个 type=20 的独立服务动作：开始服务、完成服务、服务前退款。
- R2：三个动作归入现有“订单处理”(menu_id=10201)能力组，action mark 分别为 `startService`、`completeService`、`refundBeforeService`。
- R3：为三个后端路由建立唯一 API 权限记录及一一对应的菜单/API绑定。
- R4：前端仅使用 `/order/tools.<actionMark>` 判断三个按钮权限，移除旧发货/取消权限回退；后端继续以真实 API URL 作最终授权。
- R5：正式变更必须放入 `deploy/migrations/`，迁移可重复执行、不会覆盖 ID 冲突的已有自定义数据，并保持对上一版本代码回滚兼容。
- R6：屏蔽旧实物权限菜单：订单列表下“发货”、售后下“确认收货”、订单处理下“订单导出/下载”、发货管理及其子项。
- R7：屏蔽旧实物权限 API：发货管理、物流跟踪、订单导出、退货确认收货相关 API；不删除对应业务控制器和历史业务表。
- R8：既有角色授权迁移遵循最小权限：只有迁移前已持有“订单处理”(10201)的角色自动获得三个新动作；其他角色由管理员显式勾选。
- R9：更新运行时防御性过滤，使迁移尚未执行或历史脏数据存在时，旧权限仍不会出现在角色树或进入后端授权 URL 集。
- R10：迁移后刷新/重新登录即可获得新权限，不引入长期缓存清理机制。

## Acceptance Criteria

- [ ] AC1：数据库中三个服务 API URL 各恰有一条记录，每条与一个服务动作菜单绑定。
- [ ] AC2：迁移前持有 10201 的每个 `(store_id, role_id)` 均拥有三个新动作且无重复；未持有 10201 的角色不会被自动授权。
- [ ] AC3：`guanxing` 重新登录后，满足订单状态条件时可看到并成功调用三个动作。
- [ ] AC4：移除任一动作菜单授权后，对应按钮消失，并且直接请求对应接口返回无权限。
- [ ] AC5：角色树和普通管理员权限集合不含旧发货、确认收货、订单导出菜单/API；旧记录仅作为上一版本回滚兼容数据保留。
- [ ] AC6：迁移重复执行两次，第二次不新增重复菜单、API、映射或角色授权。
- [ ] AC7：超级管理员行为不变；订单业务状态机仍决定动作是否可执行。
- [ ] AC8：相关 PHP 文件通过 `php -l`，商家后台相关文件通过定向 lint，并有权限矩阵回归检查。

## Out of Scope

- 彻底删除订单导出、物流或退货控制器。
- 将客服角色默认提升为订单处理角色。
- 修改开始服务、完成服务、退款的业务状态机和微信虚拟支付规则。
