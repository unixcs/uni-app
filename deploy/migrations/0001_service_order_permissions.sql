-- Replace legacy physical-order capabilities with service-order actions.
-- Resolve parent capabilities semantically and let AUTO_INCREMENT allocate IDs.
-- The guarded release serializes migrations; AUTO_INCREMENT also avoids collisions
-- with unrelated permission writes that happen while this transaction is running.

START TRANSACTION;

SET @permission_migration_time := UNIX_TIMESTAMP();

-- Resolve exactly one "订单处理" capability group. A missing or duplicate group
-- makes the prepared SIGNAL fail the migration instead of silently committing no grants.
SET @order_tools_menu_id := (
    SELECT IF(COUNT(*) = 1, MIN(`menu_id`), NULL)
    FROM `yoshop_store_menu`
    WHERE `type` = 10 AND `path` = '/order/tools'
);
SET @permission_guard_sql := IF(
    @order_tools_menu_id IS NULL,
    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'service permissions: expected exactly one /order/tools menu'",
    'DO 0'
);
PREPARE `permission_guard` FROM @permission_guard_sql;
EXECUTE `permission_guard`;
DEALLOCATE PREPARE `permission_guard`;

-- Resolve the API parent semantically and prove it owns the existing order-event APIs.
SET @order_api_parent_id := (
    SELECT IF(COUNT(*) = 1, MIN(`parent`.`api_id`), NULL)
    FROM `yoshop_store_api` AS `parent`
    WHERE `parent`.`name` = '订单管理'
      AND `parent`.`url` = '-'
      AND EXISTS (
          SELECT 1
          FROM `yoshop_store_api` AS `child`
          WHERE `child`.`parent_id` = `parent`.`api_id`
            AND `child`.`url` = '/order.event/updateRemark'
      )
);
SET @permission_guard_sql := IF(
    @order_api_parent_id IS NULL,
    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'service permissions: expected exactly one order API parent'",
    'DO 0'
);
PREPARE `permission_guard` FROM @permission_guard_sql;
EXECUTE `permission_guard`;
DEALLOCATE PREPARE `permission_guard`;

-- Create or reuse the three action menus below the resolved "订单处理" group.
INSERT INTO `yoshop_store_menu`
    (`type`, `name`, `path`, `is_page`, `module_key`, `action_mark`, `parent_id`, `sort`, `create_time`, `update_time`)
SELECT 20, '开始服务', '', 1, '', 'startService', @order_tools_menu_id, 100,
       @permission_migration_time, @permission_migration_time
WHERE NOT EXISTS (
    SELECT 1 FROM `yoshop_store_menu`
    WHERE `parent_id` = @order_tools_menu_id AND `type` = 20 AND `action_mark` = 'startService'
);
INSERT INTO `yoshop_store_menu`
    (`type`, `name`, `path`, `is_page`, `module_key`, `action_mark`, `parent_id`, `sort`, `create_time`, `update_time`)
SELECT 20, '完成服务', '', 1, '', 'completeService', @order_tools_menu_id, 105,
       @permission_migration_time, @permission_migration_time
WHERE NOT EXISTS (
    SELECT 1 FROM `yoshop_store_menu`
    WHERE `parent_id` = @order_tools_menu_id AND `type` = 20 AND `action_mark` = 'completeService'
);
INSERT INTO `yoshop_store_menu`
    (`type`, `name`, `path`, `is_page`, `module_key`, `action_mark`, `parent_id`, `sort`, `create_time`, `update_time`)
SELECT 20, '服务前退款', '', 1, '', 'refundBeforeService', @order_tools_menu_id, 110,
       @permission_migration_time, @permission_migration_time
WHERE NOT EXISTS (
    SELECT 1 FROM `yoshop_store_menu`
    WHERE `parent_id` = @order_tools_menu_id AND `type` = 20 AND `action_mark` = 'refundBeforeService'
);

SET @start_service_menu_id := (
    SELECT IF(COUNT(*) = 1, MIN(`menu_id`), NULL)
    FROM `yoshop_store_menu`
    WHERE `parent_id` = @order_tools_menu_id AND `type` = 20 AND `action_mark` = 'startService'
);
SET @complete_service_menu_id := (
    SELECT IF(COUNT(*) = 1, MIN(`menu_id`), NULL)
    FROM `yoshop_store_menu`
    WHERE `parent_id` = @order_tools_menu_id AND `type` = 20 AND `action_mark` = 'completeService'
);
SET @refund_before_service_menu_id := (
    SELECT IF(COUNT(*) = 1, MIN(`menu_id`), NULL)
    FROM `yoshop_store_menu`
    WHERE `parent_id` = @order_tools_menu_id AND `type` = 20 AND `action_mark` = 'refundBeforeService'
);
SET @permission_guard_sql := IF(
    @start_service_menu_id IS NULL
        OR @complete_service_menu_id IS NULL
        OR @refund_before_service_menu_id IS NULL,
    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'service permissions: action menu resolution failed'",
    'DO 0'
);
PREPARE `permission_guard` FROM @permission_guard_sql;
EXECUTE `permission_guard`;
DEALLOCATE PREPARE `permission_guard`;

-- Create or reuse the exact backend API permissions.
INSERT INTO `yoshop_store_api`
    (`name`, `url`, `parent_id`, `sort`, `create_time`, `update_time`)
SELECT '开始服务', '/order.event/startService', @order_api_parent_id, 155,
       @permission_migration_time, @permission_migration_time
WHERE NOT EXISTS (
    SELECT 1 FROM `yoshop_store_api` WHERE `url` = '/order.event/startService'
);
INSERT INTO `yoshop_store_api`
    (`name`, `url`, `parent_id`, `sort`, `create_time`, `update_time`)
SELECT '完成服务', '/order.event/completeService', @order_api_parent_id, 160,
       @permission_migration_time, @permission_migration_time
WHERE NOT EXISTS (
    SELECT 1 FROM `yoshop_store_api` WHERE `url` = '/order.event/completeService'
);
INSERT INTO `yoshop_store_api`
    (`name`, `url`, `parent_id`, `sort`, `create_time`, `update_time`)
SELECT '服务前退款', '/order.event/refundBeforeService', @order_api_parent_id, 165,
       @permission_migration_time, @permission_migration_time
WHERE NOT EXISTS (
    SELECT 1 FROM `yoshop_store_api` WHERE `url` = '/order.event/refundBeforeService'
);

SET @start_service_api_id := (
    SELECT IF(COUNT(*) = 1, MIN(`api_id`), NULL)
    FROM `yoshop_store_api` WHERE `url` = '/order.event/startService'
);
SET @complete_service_api_id := (
    SELECT IF(COUNT(*) = 1, MIN(`api_id`), NULL)
    FROM `yoshop_store_api` WHERE `url` = '/order.event/completeService'
);
SET @refund_before_service_api_id := (
    SELECT IF(COUNT(*) = 1, MIN(`api_id`), NULL)
    FROM `yoshop_store_api` WHERE `url` = '/order.event/refundBeforeService'
);
SET @permission_guard_sql := IF(
    @start_service_api_id IS NULL
        OR @complete_service_api_id IS NULL
        OR @refund_before_service_api_id IS NULL,
    "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'service permissions: API resolution failed'",
    'DO 0'
);
PREPARE `permission_guard` FROM @permission_guard_sql;
EXECUTE `permission_guard`;
DEALLOCATE PREPARE `permission_guard`;

-- Bind each capability to only its corresponding backend route.
INSERT INTO `yoshop_store_menu_api` (`menu_id`, `api_id`, `create_time`)
SELECT @start_service_menu_id, @start_service_api_id, @permission_migration_time
WHERE NOT EXISTS (
    SELECT 1 FROM `yoshop_store_menu_api`
    WHERE `menu_id` = @start_service_menu_id AND `api_id` = @start_service_api_id
);
INSERT INTO `yoshop_store_menu_api` (`menu_id`, `api_id`, `create_time`)
SELECT @complete_service_menu_id, @complete_service_api_id, @permission_migration_time
WHERE NOT EXISTS (
    SELECT 1 FROM `yoshop_store_menu_api`
    WHERE `menu_id` = @complete_service_menu_id AND `api_id` = @complete_service_api_id
);
INSERT INTO `yoshop_store_menu_api` (`menu_id`, `api_id`, `create_time`)
SELECT @refund_before_service_menu_id, @refund_before_service_api_id, @permission_migration_time
WHERE NOT EXISTS (
    SELECT 1 FROM `yoshop_store_menu_api`
    WHERE `menu_id` = @refund_before_service_menu_id AND `api_id` = @refund_before_service_api_id
);

-- Least-privilege backfill: the role editor stores selected leaf actions, not
-- necessarily their parent group. Treat a role as owning "订单处理" when it owns
-- the resolved group itself or any migration-before direct child (for example
-- legacy 订单导出). store_id remains part of every grant check.
INSERT INTO `yoshop_store_role_menu` (`role_id`, `menu_id`, `store_id`, `create_time`)
SELECT DISTINCT `source`.`role_id`, @start_service_menu_id, `source`.`store_id`, @permission_migration_time
FROM `yoshop_store_role_menu` AS `source`
JOIN `yoshop_store_menu` AS `owned_menu` ON `owned_menu`.`menu_id` = `source`.`menu_id`
WHERE (`owned_menu`.`menu_id` = @order_tools_menu_id OR `owned_menu`.`parent_id` = @order_tools_menu_id)
  AND NOT EXISTS (
      SELECT 1 FROM `yoshop_store_role_menu` AS `existing`
      WHERE `existing`.`role_id` = `source`.`role_id`
        AND `existing`.`store_id` = `source`.`store_id`
        AND `existing`.`menu_id` = @start_service_menu_id
  );
INSERT INTO `yoshop_store_role_menu` (`role_id`, `menu_id`, `store_id`, `create_time`)
SELECT DISTINCT `source`.`role_id`, @complete_service_menu_id, `source`.`store_id`, @permission_migration_time
FROM `yoshop_store_role_menu` AS `source`
JOIN `yoshop_store_menu` AS `owned_menu` ON `owned_menu`.`menu_id` = `source`.`menu_id`
WHERE (`owned_menu`.`menu_id` = @order_tools_menu_id OR `owned_menu`.`parent_id` = @order_tools_menu_id)
  AND NOT EXISTS (
      SELECT 1 FROM `yoshop_store_role_menu` AS `existing`
      WHERE `existing`.`role_id` = `source`.`role_id`
        AND `existing`.`store_id` = `source`.`store_id`
        AND `existing`.`menu_id` = @complete_service_menu_id
  );
INSERT INTO `yoshop_store_role_menu` (`role_id`, `menu_id`, `store_id`, `create_time`)
SELECT DISTINCT `source`.`role_id`, @refund_before_service_menu_id, `source`.`store_id`, @permission_migration_time
FROM `yoshop_store_role_menu` AS `source`
JOIN `yoshop_store_menu` AS `owned_menu` ON `owned_menu`.`menu_id` = `source`.`menu_id`
WHERE (`owned_menu`.`menu_id` = @order_tools_menu_id OR `owned_menu`.`parent_id` = @order_tools_menu_id)
  AND NOT EXISTS (
      SELECT 1 FROM `yoshop_store_role_menu` AS `existing`
      WHERE `existing`.`role_id` = `source`.`role_id`
        AND `existing`.`store_id` = `source`.`store_id`
        AND `existing`.`menu_id` = @refund_before_service_menu_id
  );

-- Keep legacy rows dormant for one-release rollback compatibility. The current
-- code filters these menu IDs and API routes from both role trees and backend
-- access sets. A later cleanup migration may delete them after the rollback
-- window no longer needs the previous release's deliver/cancel fallbacks.

COMMIT;
