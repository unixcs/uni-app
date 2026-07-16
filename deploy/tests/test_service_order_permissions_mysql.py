"""Opt-in MariaDB/MySQL integration test for the service permission migration.

The default test suite skips this module because it creates and drops isolated
copy tables inside the WSL-local application database. Run only after local DB-write approval:

    YOSHOP_LOCAL_DB_INTEGRATION=1 \
      python3 -m unittest deploy.tests.test_service_order_permissions_mysql
"""
from __future__ import annotations

import os
from pathlib import Path
import subprocess
import unittest
import uuid

ROOT = Path(__file__).resolve().parents[2]
ENV_FILE = ROOT / "yoshop2.0/.env"
MIGRATION = ROOT / "deploy/migrations/0001_service_order_permissions.sql"
TABLES = (
    "yoshop_store_menu",
    "yoshop_store_api",
    "yoshop_store_menu_api",
    "yoshop_store_role_menu",
)
ACTIONS = ("startService", "completeService", "refundBeforeService")


def load_database_config() -> dict[str, str]:
    config: dict[str, str] = {}
    section = ""
    for raw in ENV_FILE.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if line.startswith("[") and line.endswith("]"):
            section = line[1:-1].strip().upper()
            continue
        if section != "DATABASE" or "=" not in line or line.startswith(("#", ";")):
            continue
        key, value = (part.strip() for part in line.split("=", 1))
        config[key.upper()] = value.strip("\"'")
    return config


@unittest.skipUnless(
    os.environ.get("YOSHOP_LOCAL_DB_INTEGRATION") == "1",
    "set YOSHOP_LOCAL_DB_INTEGRATION=1 after local DB-write approval",
)
class ServiceOrderPermissionMysqlIntegrationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.config = load_database_config()
        required = {"HOSTNAME", "HOSTPORT", "DATABASE", "USERNAME", "PASSWORD"}
        self.assertFalse(required - self.config.keys(), "local database config is incomplete")
        self.assertIn(
            self.config["HOSTNAME"],
            {"127.0.0.1", "localhost", "::1"},
            "integration test refuses a non-local database host",
        )
        self.source_database = self.config["DATABASE"]
        suffix = uuid.uuid4().hex[:10]
        self.table_map = {
            table: f"yoshop_permtest_{suffix}_{table.removeprefix('yoshop_')}"
            for table in TABLES
        }
        for source, test_table in self.table_map.items():
            self.mysql(self.source_database, f"CREATE TABLE `{test_table}` LIKE `{source}`")
            self.addCleanup(
                self.mysql,
                self.source_database,
                f"DROP TABLE IF EXISTS `{test_table}`",
                check=False,
            )
            self.mysql(self.source_database, f"INSERT INTO `{test_table}` SELECT * FROM `{source}`")

    def mysql(self, database: str | None, sql: str, *, check: bool = True) -> subprocess.CompletedProcess[str]:
        command = [
            "mysql",
            "-h", self.config["HOSTNAME"],
            "-P", self.config["HOSTPORT"],
            "-u", self.config["USERNAME"],
            "--batch",
            "--skip-column-names",
        ]
        if database:
            command.append(database)
        environment = os.environ.copy()
        environment["MYSQL_PWD"] = self.config["PASSWORD"]
        result = subprocess.run(
            command,
            input=sql,
            text=True,
            capture_output=True,
            env=environment,
            check=False,
        )
        if check and result.returncode:
            self.fail(f"mysql failed ({result.returncode}): {result.stderr}")
        return result

    def scalar(self, sql: str) -> int:
        result = self.mysql(self.source_database, sql)
        return int(result.stdout.strip())

    def test_migration_twice_is_idempotent_and_store_scoped(self) -> None:
        menu = self.table_map["yoshop_store_menu"]
        role_menu = self.table_map["yoshop_store_role_menu"]
        order_tools_menu_id = self.scalar(
            f"SELECT menu_id FROM `{menu}` WHERE type=10 AND path='/order/tools'"
        )
        eligible_child_menu_id = self.scalar(
            f"SELECT MIN(menu_id) FROM `{menu}` WHERE parent_id = {order_tools_menu_id}"
        )
        unrelated_menu_id = self.scalar(
            f"SELECT MIN(menu_id) FROM `{menu}` WHERE menu_id <> {order_tools_menu_id} "
            f"AND parent_id <> {order_tools_menu_id}"
        )
        role_seed = self.scalar(f"SELECT COALESCE(MAX(role_id), 0) + 1000 FROM `{role_menu}`")
        store_seed = self.scalar(f"SELECT COALESCE(MAX(store_id), 0) + 1000 FROM `{role_menu}`")

        # The same role ID is eligible in one store and ineligible in another,
        # proving backfill retains store_id instead of granting across stores.
        self.mysql(
            self.source_database,
            f"""
            INSERT INTO `{role_menu}`(role_id, menu_id, store_id, create_time)
            VALUES
              ({role_seed}, {eligible_child_menu_id}, {store_seed}, UNIX_TIMESTAMP()),
              ({role_seed}, {unrelated_menu_id}, {store_seed + 1}, UNIX_TIMESTAMP());
            """,
        )

        migration_sql = MIGRATION.read_text(encoding="utf-8")
        for source, test_table in self.table_map.items():
            migration_sql = migration_sql.replace(f"`{source}`", f"`{test_table}`")
        self.mysql(self.source_database, migration_sql)
        first_counts = self.permission_counts(role_seed, store_seed)
        self.mysql(self.source_database, migration_sql)
        second_counts = self.permission_counts(role_seed, store_seed)

        self.assertEqual((3, 3, 3, 3, 0, 3, 0), first_counts)
        self.assertEqual(first_counts, second_counts)

    def permission_counts(self, role_id: int, store_id: int) -> tuple[int, ...]:
        actions = ",".join(f"'{action}'" for action in ACTIONS)
        urls = ",".join(f"'/order.event/{action}'" for action in ACTIONS)
        menu = self.table_map["yoshop_store_menu"]
        api = self.table_map["yoshop_store_api"]
        menu_api = self.table_map["yoshop_store_menu_api"]
        role_menu = self.table_map["yoshop_store_role_menu"]
        query = f"""
        SELECT COUNT(*) FROM `{menu}` AS action
        JOIN `{menu}` AS parent ON parent.menu_id=action.parent_id
        WHERE parent.type=10 AND parent.path='/order/tools'
          AND action.type=20 AND action.action_mark IN ({actions});
        SELECT COUNT(*) FROM `{api}` WHERE url IN ({urls});
        SELECT COUNT(*) FROM `{menu_api}` AS binding
        JOIN `{menu}` AS action ON action.menu_id=binding.menu_id
        JOIN `{api}` AS api ON api.api_id=binding.api_id
        JOIN `{menu}` AS parent ON parent.menu_id=action.parent_id
        WHERE parent.path='/order/tools' AND action.action_mark IN ({actions})
          AND api.url=CONCAT('/order.event/', action.action_mark);
        SELECT COUNT(*) FROM `{role_menu}` AS grant_row
        JOIN `{menu}` AS action ON action.menu_id=grant_row.menu_id
        JOIN `{menu}` AS parent ON parent.menu_id=action.parent_id
        WHERE grant_row.role_id={role_id} AND grant_row.store_id={store_id}
          AND parent.path='/order/tools' AND action.action_mark IN ({actions});
        SELECT COUNT(*) FROM `{role_menu}` AS grant_row
        JOIN `{menu}` AS action ON action.menu_id=grant_row.menu_id
        JOIN `{menu}` AS parent ON parent.menu_id=action.parent_id
        WHERE grant_row.role_id={role_id} AND grant_row.store_id={store_id + 1}
          AND parent.path='/order/tools' AND action.action_mark IN ({actions});
        SELECT COUNT(DISTINCT api.url) FROM `{role_menu}` AS grant_row
        JOIN `{menu_api}` AS binding ON binding.menu_id=grant_row.menu_id
        JOIN `{api}` AS api ON api.api_id=binding.api_id
        WHERE grant_row.role_id={role_id} AND grant_row.store_id={store_id}
          AND api.url IN ({urls});
        SELECT COUNT(DISTINCT api.url) FROM `{role_menu}` AS grant_row
        JOIN `{menu_api}` AS binding ON binding.menu_id=grant_row.menu_id
        JOIN `{api}` AS api ON api.api_id=binding.api_id
        WHERE grant_row.role_id={role_id} AND grant_row.store_id={store_id + 1}
          AND api.url IN ({urls});
        """
        result = self.mysql(self.source_database, query)
        return tuple(int(line) for line in result.stdout.splitlines() if line.strip())


if __name__ == "__main__":
    unittest.main()
