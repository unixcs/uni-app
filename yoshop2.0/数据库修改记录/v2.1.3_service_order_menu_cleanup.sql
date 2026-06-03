-- Service order cleanup for existing installs
-- Remove legacy physical-commerce delivery workflow menus and permissions.

DELETE FROM `yoshop_store_menu_api`
WHERE `menu_id` IN ('10238', '10239', '10240', '10241', '10242')
  AND `api_id` IN ('11318', '11319', '11320', '11321', '11322', '11367');

DELETE FROM `yoshop_store_menu`
WHERE `menu_id` IN ('10238', '10239', '10241', '10242');

DELETE FROM `yoshop_store_api`
WHERE `api_id` IN ('11318', '11319', '11320', '11321', '11322', '11367');
