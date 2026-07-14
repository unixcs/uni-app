import json
import os
from pathlib import Path
import re
import shutil
import ssl
import subprocess
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import unittest
from urllib.parse import urlsplit


REPO_ROOT = Path(__file__).resolve().parents[2]
SCRIPT = REPO_ROOT / "deploy" / "scripts" / "production-smoke.sh"
NGINX_CONFIG = REPO_ROOT / "deploy" / "nginx" / "wx.gxwqb.cn.conf"
UPLOAD_PATH = "/uploads/seed/referenced.jpg"


class SmokeFixtureHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, _format, *args):
        return

    def _record(self, method):
        content_length = int(self.headers.get("Content-Length", "0"))
        self.server.state["requests"].append((method, self.path, content_length))

    def _respond(self, status, body=b"", content_type=None, headers=None):
        self.send_response(status)
        if content_type:
            self.send_header("Content-Type", content_type)
        for name, value in (headers or {}).items():
            self.send_header(name, value)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        if body:
            self.wfile.write(body)

    def do_GET(self):
        self._record("GET")
        if self.server.redirect_only:
            self._respond(
                self.server.state["redirect_status"],
                headers={"Location": self.server.state["https_base"] + "/"},
            )
            return

        parsed = urlsplit(self.path)
        if parsed.path == "/":
            self._respond(
                200,
                b'<!doctype html><div id="app"></div><script src="/assets/app.js"></script>',
                "text/html; charset=utf-8",
            )
        elif parsed.path == "/healthz":
            headers = {}
            if self.server.state["health_cache_control"] is not None:
                headers["Cache-Control"] = self.server.state["health_cache_control"]
            self._respond(200, b"ok\n", "text/plain", headers)
        elif parsed.path == "/index.php" and parsed.query == "s=/api/":
            self._respond(200, b"api entry", "text/html; charset=utf-8")
        elif parsed.path == "/index.php" and parsed.query == "s=/api/page/detail":
            payload = json.dumps(
                {
                    "status": 200,
                    "message": "success",
                    "data": {"pageData": {"page": {"id": 10001}, "items": []}},
                },
                separators=(",", ":"),
            ).encode()
            self._respond(200, payload, "application/json; charset=utf-8")
        elif parsed.path in ("/admin/", "/store/"):
            self._respond(
                200,
                b'<!doctype html><div id="app"></div><script src="app.js"></script>',
                "text/html",
            )
        elif parsed.path == UPLOAD_PATH:
            self._respond(200, b"\xff\xd8\xfffixture-jpeg", "image/jpeg")
        elif parsed.path == "/notify/virtualPayment" and not parsed.query:
            self._respond(
                self.server.state["callback_status"],
                b"callback-body-must-not-appear token=fixture-secret",
                "application/json",
            )
        else:
            self._respond(404, b"not found", "text/plain")

    def do_HEAD(self):
        self._record("HEAD")
        self._respond(500)

    def do_OPTIONS(self):
        self._record("OPTIONS")
        self._respond(405)

    def do_POST(self):
        self._record("POST")
        self._respond(405)


class ProductionSmokeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if shutil.which("curl") is None or shutil.which("openssl") is None:
            raise unittest.SkipTest("curl and openssl are required for fixture TLS tests")

        cls.temp_dir_context = tempfile.TemporaryDirectory()
        cls.temp_dir = Path(cls.temp_dir_context.name)
        cls.ca_cert, server_cert, server_key = cls._make_test_certificates(cls.temp_dir)
        cls.state = {
            "requests": [],
            "redirect_status": 301,
            "health_cache_control": "no-store",
            "callback_status": 200,
        }

        cls.https_server = ThreadingHTTPServer(("127.0.0.1", 0), SmokeFixtureHandler)
        cls.https_server.redirect_only = False
        cls.https_server.state = cls.state
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.minimum_version = ssl.TLSVersion.TLSv1_2
        context.load_cert_chain(server_cert, server_key)
        cls.https_server.socket = context.wrap_socket(cls.https_server.socket, server_side=True)
        https_port = cls.https_server.server_address[1]
        cls.https_base = f"https://127.0.0.1:{https_port}"
        cls.state["https_base"] = cls.https_base

        cls.http_server = ThreadingHTTPServer(("127.0.0.1", 0), SmokeFixtureHandler)
        cls.http_server.redirect_only = True
        cls.http_server.state = cls.state
        http_port = cls.http_server.server_address[1]
        cls.http_base = f"http://127.0.0.1:{http_port}"

        cls.threads = []
        for server in (cls.https_server, cls.http_server):
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            cls.threads.append(thread)

    @classmethod
    def tearDownClass(cls):
        for server in (cls.http_server, cls.https_server):
            server.shutdown()
            server.server_close()
        for thread in cls.threads:
            thread.join(timeout=2)
        cls.temp_dir_context.cleanup()

    @staticmethod
    def _make_test_certificates(directory):
        ca_key = directory / "ca.key"
        ca_cert = directory / "ca.pem"
        server_key = directory / "server.key"
        server_csr = directory / "server.csr"
        server_cert = directory / "server.pem"
        extensions = directory / "server-ext.cnf"
        extensions.write_text(
            "[server]\n"
            "basicConstraints=critical,CA:FALSE\n"
            "keyUsage=critical,digitalSignature,keyEncipherment\n"
            "extendedKeyUsage=serverAuth\n"
            "subjectAltName=IP:127.0.0.1,DNS:localhost\n"
        )
        quiet = {"stdout": subprocess.DEVNULL, "stderr": subprocess.DEVNULL}
        subprocess.run(
            [
                "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
                "-sha256", "-days", "1", "-subj", "/CN=YoShop Smoke Test CA",
                "-addext", "basicConstraints=critical,CA:TRUE",
                "-addext", "keyUsage=critical,keyCertSign,cRLSign",
                "-keyout", str(ca_key), "-out", str(ca_cert),
            ],
            check=True,
            **quiet,
        )
        subprocess.run(
            [
                "openssl", "req", "-newkey", "rsa:2048", "-nodes", "-sha256",
                "-subj", "/CN=127.0.0.1", "-keyout", str(server_key),
                "-out", str(server_csr),
            ],
            check=True,
            **quiet,
        )
        subprocess.run(
            [
                "openssl", "x509", "-req", "-in", str(server_csr),
                "-CA", str(ca_cert), "-CAkey", str(ca_key), "-CAcreateserial",
                "-days", "1", "-sha256", "-extfile", str(extensions),
                "-extensions", "server", "-out", str(server_cert),
            ],
            check=True,
            **quiet,
        )
        return ca_cert, server_cert, server_key

    def setUp(self):
        self.state["requests"].clear()
        self.state["redirect_status"] = 301
        self.state["health_cache_control"] = "no-store"
        self.state["callback_status"] = 200

    def run_smoke(self, *extra_args, include_cacert=True):
        args = [
            str(SCRIPT),
            "--non-production-test",
            "--base-url", self.https_base,
            "--http-url", self.http_base,
        ]
        if include_cacert:
            args.extend(["--cacert", str(self.ca_cert)])
        args.extend(["--upload-path", UPLOAD_PATH])
        args.extend(extra_args)
        env = os.environ.copy()
        for key in ("HTTP_PROXY", "HTTPS_PROXY", "ALL_PROXY", "http_proxy", "https_proxy", "all_proxy"):
            env.pop(key, None)
        env["NO_PROXY"] = "127.0.0.1,localhost"
        env["no_proxy"] = env["NO_PROXY"]
        return subprocess.run(args, text=True, capture_output=True, env=env, timeout=20)

    def test_nginx_healthz_is_minimal_and_non_cacheable(self):
        text = NGINX_CONFIG.read_text()
        match = re.search(r"location\s*=\s*/healthz\s*\{(?P<body>[^}]*)\}", text, re.DOTALL)
        self.assertIsNotNone(match)
        block = match.group("body")
        self.assertIn("access_log off;", block)
        self.assertIn("default_type text/plain;", block)
        self.assertIn('add_header Cache-Control "no-store" always;', block)
        self.assertIn('return 200 "ok\\n";', block)
        self.assertNotIn("fastcgi", block)

    def test_complete_smoke_uses_only_safe_get_requests(self):
        result = self.run_smoke()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        expected_checks = [
            "http_redirect", "tls_certificate", "healthz", "h5_root",
            "api_entry", "page_detail", "admin_shell", "store_shell",
            "referenced_upload", "callback_reachability",
        ]
        lines = result.stdout.splitlines()
        self.assertEqual(len(lines), 11)
        for name, line in zip(expected_checks, lines[:10]):
            self.assertRegex(line, rf"^SMOKE_CHECK name={name} result=pass(?: |$)")
        self.assertEqual(
            lines[-1],
            "SMOKE_SUMMARY result=pass passed=10 expected=10 target=non_production_test",
        )
        self.assertEqual(result.stderr, "")
        self.assertNotIn("fixture-secret", result.stdout)
        self.assertTrue(self.state["requests"])
        self.assertTrue(all(method == "GET" for method, _path, _length in self.state["requests"]))
        callback_requests = [
            request for request in self.state["requests"]
            if urlsplit(request[1]).path == "/notify/virtualPayment"
        ]
        self.assertEqual(callback_requests, [("GET", "/notify/virtualPayment", 0)])

    def test_non_production_target_is_rejected_without_explicit_mode(self):
        result = subprocess.run(
            [
                str(SCRIPT), "--base-url", "https://wx.oiob.cn",
                "--upload-path", UPLOAD_PATH,
            ],
            text=True,
            capture_output=True,
            timeout=3,
        )
        self.assertEqual(result.returncode, 2)
        self.assertIn("reason=non_production_mode_required", result.stdout)
        self.assertIn("passed=0 expected=10 target=production_b", result.stdout)

    def test_certificate_failure_stops_before_https_handler(self):
        result = self.run_smoke(include_cacert=False)
        self.assertEqual(result.returncode, 1)
        self.assertIn("name=http_redirect result=pass", result.stdout)
        self.assertIn("name=tls_certificate result=fail reason=transport_or_certificate_failure", result.stdout)
        self.assertIn("result=fail passed=1 expected=10", result.stdout)
        self.assertFalse(any(path.startswith("/healthz") for _method, path, _length in self.state["requests"]))

    def test_healthz_requires_no_store_and_fails_fast(self):
        self.state["health_cache_control"] = "public, max-age=60"
        result = self.run_smoke()
        self.assertEqual(result.returncode, 1)
        self.assertIn("name=healthz result=fail reason=cache_policy_missing", result.stdout)
        self.assertIn("result=fail passed=2 expected=10", result.stdout)
        self.assertFalse(any(path.startswith("/index.php") for _method, path, _length in self.state["requests"]))

    def test_unsigned_callback_reachability_requires_http_200(self):
        self.state["callback_status"] = 204
        result = self.run_smoke()
        self.assertEqual(result.returncode, 1)
        self.assertIn(
            "name=callback_reachability result=fail reason=unexpected_status http_status=204",
            result.stdout,
        )
        self.assertIn("result=fail passed=9 expected=10", result.stdout)
        self.assertNotIn("callback-body-must-not-appear", result.stdout)
        callback_requests = [
            request for request in self.state["requests"]
            if urlsplit(request[1]).path == "/notify/virtualPayment"
        ]
        self.assertEqual(callback_requests, [("GET", "/notify/virtualPayment", 0)])


if __name__ == "__main__":
    unittest.main()
