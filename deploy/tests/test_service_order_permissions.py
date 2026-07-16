from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
MIGRATION = ROOT / "deploy/migrations/0001_service_order_permissions.sql"
DETAIL = ROOT / "yoshop2.0-store/src/views/order/Detail.vue"
MENU = ROOT / "yoshop2.0/app/common/model/store/Menu.php"
MENU_API = ROOT / "yoshop2.0/app/common/model/store/MenuApi.php"
HISTORICAL_CLEANUP = ROOT / "yoshop2.0/数据库修改记录/v2.1.3_service_order_menu_cleanup.sql"

ACTIONS = ("startService", "completeService", "refundBeforeService")
API_URLS = tuple(f"/order.event/{action}" for action in ACTIONS)
LEGACY_MENU_IDS = (10052, 10059, 10202, 10203, 10238, 10239, 10240, 10241, 10242)
LEGACY_API_IDS = (11088, 11266, 11267, 11268, 11318, 11319, 11320, 11321, 11322, 11323, 11367)


class ServiceOrderPermissionContractTests(unittest.TestCase):
    def test_migration_is_atomic_and_semantically_idempotent(self) -> None:
        sql = MIGRATION.read_text(encoding="utf-8")
        self.assertRegex(sql, r"(?m)^START TRANSACTION;")
        self.assertRegex(sql, r"(?m)^COMMIT;")
        self.assertIn("`path` = '/order/tools'", sql)
        self.assertIn("`child`.`url` = '/order.event/updateRemark'", sql)
        self.assertIn("SIGNAL SQLSTATE '45000'", sql)
        self.assertIn("@order_tools_menu_id", sql)
        self.assertIn("@order_api_parent_id", sql)
        self.assertNotIn("MAX(`menu_id`)", sql)
        self.assertNotIn("MAX(`api_id`)", sql)
        self.assertNotRegex(sql, r"INSERT INTO `yoshop_store_menu`\s*\n\s*\(`menu_id`,")
        self.assertNotRegex(sql, r"INSERT INTO `yoshop_store_api`\s*\n\s*\(`api_id`,")
        for action, url in zip(ACTIONS, API_URLS):
            with self.subTest(action=action):
                self.assertIn(f"`action_mark` = '{action}'", sql)
                self.assertIn(f"`url` = '{url}'", sql)
                self.assertRegex(
                    sql,
                    rf"(?s)INSERT INTO `yoshop_store_menu_api`.*?@{self._snake(action)}_menu_id.*?WHERE NOT EXISTS",
                )
                self.assertRegex(
                    sql,
                    rf"(?s)INSERT INTO `yoshop_store_role_menu`.*?@{self._snake(action)}_menu_id.*?`owned_menu`.`parent_id` = @order_tools_menu_id.*?NOT EXISTS",
                )

    def test_migration_preserves_legacy_rows_for_code_rollback(self) -> None:
        sql = MIGRATION.read_text(encoding="utf-8")
        self.assertNotIn("DELETE FROM", sql)
        self.assertIn("rollback compatibility", sql)
        self.assertIn("previous release's deliver/cancel fallbacks", sql)

    def test_historical_manual_cleanup_is_non_destructive(self) -> None:
        sql = HISTORICAL_CLEANUP.read_text(encoding="utf-8")
        self.assertNotIn("DELETE FROM", sql)
        self.assertIn("deploy/migrations/0001_service_order_permissions.sql", sql)
        self.assertIn("rollback compatibility", sql)

    def test_frontend_uses_capability_keys_without_legacy_fallbacks(self) -> None:
        detail = DETAIL.read_text(encoding="utf-8")
        for action in ACTIONS:
            self.assertIn(f"this.$auth('/order/tools.{action}')", detail)
            self.assertNotIn(f"this.$auth('/order.event/{action}')", detail)
        self.assertNotIn("/order/list/all.deliver", detail)
        self.assertNotIn("/order/list/all.cancel", detail)

    def test_backend_defense_lists_match_the_migration_cleanup(self) -> None:
        menu = MENU.read_text(encoding="utf-8")
        menu_api = MENU_API.read_text(encoding="utf-8")
        for menu_id in LEGACY_MENU_IDS:
            self.assertRegex(menu, rf"\b{menu_id}\b")
        for api_id in LEGACY_API_IDS:
            self.assertRegex(menu_api, rf"\b{api_id}\b")
        for prefix in ("/order.delivery", "/order.export", "/order.refund/receipt"):
            self.assertIn(prefix, menu_api)

    @staticmethod
    def _snake(action: str) -> str:
        return re.sub(r"(?<!^)(?=[A-Z])", "_", action).lower()


if __name__ == "__main__":
    unittest.main()
