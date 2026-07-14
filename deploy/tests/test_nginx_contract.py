from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
PRODUCTION = ROOT / "deploy/nginx/wx.gxwqb.cn.conf"
MAINTENANCE = ROOT / "deploy/nginx/wx.gxwqb.cn.maintenance.conf"


def server_block(text: str, server_names: str, listen: str = "80") -> str:
    for match in re.finditer(r"server\s*\{(?P<body>.*?)\n\}", text, re.DOTALL):
        body = match.group("body")
        if f"listen {listen};" in body and f"server_name {server_names};" in body:
            return body
    raise AssertionError(f"server block not found: listen={listen}, names={server_names}")


class NginxContractTests(unittest.TestCase):
    def test_both_configs_keep_static_site_acme_outside_static_root(self) -> None:
        expected = re.compile(
            r"location\s+\^~\s+/\.well-known/acme-challenge/\s*\{"
            r"(?=[^}]*root\s+/var/www/html;)"
            r"(?=[^}]*try_files\s+\$uri\s+=404;)",
            re.DOTALL,
        )
        for path in (PRODUCTION, MAINTENANCE):
            with self.subTest(path=path.name):
                block = server_block(path.read_text(), "gxwqb.cn www.gxwqb.cn")
                self.assertRegex(block, expected)
                self.assertIn("root /mnt/vps/tencent/static-site;", block)

    def test_both_configs_keep_wx_acme_route(self) -> None:
        for path in (PRODUCTION, MAINTENANCE):
            with self.subTest(path=path.name):
                block = server_block(path.read_text(), "wx.gxwqb.cn")
                self.assertIn("location ^~ /.well-known/acme-challenge/", block)
                self.assertIn("root /var/www/html;", block)

    def test_production_root_prefers_h5_shell_over_php_front_controller(self) -> None:
        block = server_block(
            PRODUCTION.read_text(), "wx.gxwqb.cn", listen="443 ssl http2"
        )
        self.assertIn("index index.html index.php index.htm;", block)

    def test_only_production_exposes_minimal_healthz(self) -> None:
        production = PRODUCTION.read_text()
        maintenance = MAINTENANCE.read_text()
        self.assertIn("location = /healthz", production)
        self.assertIn('return 200 "ok\\n";', production)
        self.assertNotIn("location = /healthz", maintenance)
        self.assertIn("return 503;", maintenance)


if __name__ == "__main__":
    unittest.main()
