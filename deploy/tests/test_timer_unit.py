from collections import defaultdict
from pathlib import Path
import unittest


REPO_ROOT = Path(__file__).resolve().parents[2]
UNIT_PATH = REPO_ROOT / "deploy" / "systemd" / "yoshop2.0-timer.service"


def parse_unit(path):
    """Return all directive values, retaining empty and duplicate assignments."""
    sections = defaultdict(lambda: defaultdict(list))
    section = None

    for line_number, raw_line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        line = raw_line.strip()
        if not line or line.startswith(("#", ";")):
            continue
        if line.startswith("[") and line.endswith("]"):
            section = line[1:-1]
            continue
        if section is None or "=" not in line:
            raise AssertionError(f"Malformed unit line {line_number}: {raw_line!r}")
        key, value = line.split("=", 1)
        sections[section][key].append(value)

    return sections


class TimerUnitTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.unit = parse_unit(UNIT_PATH)

    def value(self, section, directive):
        values = self.unit[section].get(directive, [])
        self.assertEqual(
            1,
            len(values),
            f"{section}.{directive} must have exactly one unambiguous assignment",
        )
        return values[0]

    def words(self, section, directive):
        return set(self.value(section, directive).split())

    def assert_enabled(self, *directives):
        for directive in directives:
            with self.subTest(directive=directive):
                self.assertEqual("true", self.value("Service", directive).lower())

    def test_supervision_and_resource_limits_are_preserved(self):
        self.assertEqual("simple", self.value("Service", "Type"))
        self.assertEqual("www-data", self.value("Service", "User"))
        self.assertEqual("www-data", self.value("Service", "Group"))
        self.assertEqual(
            "/srv/yoshop/current/yoshop2.0",
            self.value("Service", "WorkingDirectory"),
        )
        self.assertEqual("/usr/bin/php think timer start", self.value("Service", "ExecStart"))
        self.assertEqual("on-failure", self.value("Service", "Restart"))
        self.assertEqual("15s", self.value("Service", "RestartSec"))
        self.assertEqual("300", self.value("Unit", "StartLimitIntervalSec"))
        self.assertEqual("5", self.value("Unit", "StartLimitBurst"))
        self.assertEqual("768M", self.value("Service", "MemoryMax"))
        self.assertEqual("128", self.value("Service", "TasksMax"))
        self.assertEqual("journal", self.value("Service", "StandardOutput"))
        self.assertEqual("journal", self.value("Service", "StandardError"))
        self.assertEqual("0027", self.value("Service", "UMask"))

    def test_dependencies_wait_for_network_database_and_redis(self):
        self.assertEqual(
            {"network-online.target", "mysql.service", "redis-server.service"},
            self.words("Unit", "After"),
        )
        self.assertEqual({"network-online.target"}, self.words("Unit", "Wants"))

    def test_filesystem_and_privilege_sandbox(self):
        self.assertEqual("", self.value("Service", "CapabilityBoundingSet"))
        self.assertEqual("", self.value("Service", "AmbientCapabilities"))
        self.assert_enabled(
            "NoNewPrivileges",
            "PrivateDevices",
            "PrivateTmp",
            "ProtectClock",
            "ProtectControlGroups",
            "ProtectHome",
            "ProtectHostname",
            "ProtectKernelLogs",
            "ProtectKernelModules",
            "ProtectKernelTunables",
            "RestrictNamespaces",
            "RestrictRealtime",
            "RestrictSUIDSGID",
            "LockPersonality",
        )
        self.assertEqual("invisible", self.value("Service", "ProtectProc"))
        # Timer.php redirects Workerman PID/log state through the shared runtime
        # symlink, so the immutable release can remain read-only.
        self.assertEqual("strict", self.value("Service", "ProtectSystem"))
        self.assertIn(
            "/srv/yoshop/shared",
            self.words("Service", "ReadWritePaths"),
        )
        self.assertNotIn(
            "/srv/yoshop/shared",
            self.words("Service", "ReadOnlyPaths")
            if "ReadOnlyPaths" in self.unit["Service"]
            else set(),
        )

    def test_network_access_is_limited_but_remains_available(self):
        self.assertEqual(
            {"AF_UNIX", "AF_INET", "AF_INET6"},
            self.words("Service", "RestrictAddressFamilies"),
        )
        self.assertNotEqual(
            "true",
            self.unit["Service"].get("PrivateNetwork", [""])[-1].lower(),
        )
        address_denies = self.unit["Service"].get("IPAddressDeny", [])
        self.assertNotIn("any", {value.lower() for value in address_denies})

    def test_native_abi_and_dangerous_syscalls_are_restricted(self):
        self.assertEqual("native", self.value("Service", "SystemCallArchitectures"))
        syscall_filter = self.value("Service", "SystemCallFilter").split()
        self.assertTrue(syscall_filter[0].startswith("~"), "filter must be a deny list")
        denied_groups = {syscall_filter[0][1:], *syscall_filter[1:]}
        self.assertTrue(
            {
                "@clock",
                "@cpu-emulation",
                "@debug",
                "@module",
                "@mount",
                "@obsolete",
                "@privileged",
                "@raw-io",
                "@reboot",
                "@swap",
            }.issubset(denied_groups)
        )
        self.assertEqual("EPERM", self.value("Service", "SystemCallErrorNumber"))
        # PHP extensions and optional JITs can need executable mappings.
        self.assertNotEqual(
            "true",
            self.unit["Service"].get("MemoryDenyWriteExecute", [""])[-1].lower(),
        )


if __name__ == "__main__":
    unittest.main()
