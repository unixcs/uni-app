import contextlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest import mock

SPEC = importlib.util.spec_from_file_location("yoshop_deploy", Path(__file__).parents[1] / "deploy.py")
deploy = importlib.util.module_from_spec(SPEC)
assert SPEC.loader
SPEC.loader.exec_module(deploy)


class DeployUnitTests(unittest.TestCase):
    def make_package(self, directory: str, release_id: str = "20260715000000-0123456789ab") -> Path:
        package = Path(directory) / f"yoshop-{release_id}.tar.gz"
        package.write_bytes(b"package")
        package.with_suffix(package.suffix + ".sha256").write_text(
            f"{deploy.sha256_file(package)}  {package.name}\n", encoding="ascii"
        )
        return package

    def test_forbidden_tracking_policy(self):
        self.assertTrue(deploy.path_is_forbidden("yoshop2.0/public/uploads/a.jpg"))
        self.assertFalse(deploy.path_is_forbidden("yoshop2.0/public/uploads/.gitignore"))
        self.assertTrue(deploy.path_is_forbidden("yoshop2.0/public/index.html"))
        self.assertFalse(deploy.path_is_forbidden("yoshop2.0/public/index.php"))
        self.assertFalse(deploy.path_is_forbidden("yoshop2.0/app/api/controller/Index.php"))

    def test_temporary_api_url_awaited_scope_and_restore(self):
        with tempfile.TemporaryDirectory() as td:
            config = Path(td) / "config.js"
            original = 'export default { apiUrl: "https://wx.oiob.cn/index.php?s=/api/" }\n'
            config.write_text(original)
            with deploy.temporary_api_url(config, "https://wx.gxwqb.cn/index.php?s=/api/"):
                self.assertIn("wx.gxwqb.cn", config.read_text())
            self.assertEqual(original, config.read_text())

    def test_secret_scanner(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            (root / ".env.tpl").write_text("PASSWORD=placeholder")
            self.assertEqual([], deploy.scan_secrets(root))
            (root / "merchant.key").write_text("secret")
            self.assertIn("secret-key extension: merchant.key", deploy.scan_secrets(root))
            (root / "merchant.key").unlink()
            (root / "payload.txt").write_text(
                "-----BEGIN " + "PRIVATE KEY-----\n" + "A" * 80 + "\n-----END PRIVATE KEY-----"
            )
            self.assertIn("private-key content: payload.txt", deploy.scan_secrets(root))

    def test_domain_scanner(self):
        with tempfile.TemporaryDirectory() as td:
            public = Path(td)
            (public / "config.js").write_text("https://wx.gxwqb.cn/index.php?s=/api/")
            self.assertEqual(
                [], deploy.scan_web_domains(public, "https://wx.gxwqb.cn", "https://wx.oiob.cn")
            )
            (public / "bad.js").write_text("https://wx.oiob.cn/index.php?s=/api/")
            findings = deploy.scan_web_domains(
                public, "https://wx.gxwqb.cn", "https://wx.oiob.cn"
            )
            self.assertTrue(any("development domain" in item for item in findings))

    def test_reproducible_tar(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td) / "stage"
            root.mkdir()
            (root / "b.txt").write_text("b")
            (root / "a.txt").write_text("a")
            one, two = Path(td) / "one.tar.gz", Path(td) / "two.tar.gz"
            deploy.reproducible_tar(root, one, 1234567890)
            deploy.reproducible_tar(root, two, 1234567890)
            self.assertEqual(deploy.sha256_file(one), deploy.sha256_file(two))
            with tarfile.open(one) as archive:
                self.assertEqual(["a.txt", "b.txt"], archive.getnames())

    def test_routine_deploy_confirmation_and_install_compatibility(self):
        with tempfile.TemporaryDirectory() as td:
            package = self.make_package(td)
            config = deploy.load_config()
            with mock.patch.object(deploy, "run") as run:
                with self.assertRaisesRegex(deploy.DeployError, "authorization missing"):
                    deploy.deploy_release(config, package, dry_run=False, confirmation="")
                run.assert_not_called()

            dry = deploy.deploy_release(config, package, dry_run=True, confirmation="")
            self.assertTrue(dry["dry_run"])
            self.assertEqual("rsync", dry["rsync"][0])
            self.assertIn("install", dry["remote"])
            self.assertNotIn("prepare", dry["remote"])

    def test_prepare_has_independent_confirmation_and_remote_action(self):
        with tempfile.TemporaryDirectory() as td:
            package = self.make_package(td)
            config = deploy.load_config()
            with mock.patch.object(deploy, "run") as run:
                with self.assertRaisesRegex(deploy.DeployError, deploy.PREPARE_CONFIRMATION):
                    deploy.prepare_candidate(config, package, dry_run=False, confirmation="")
                run.assert_not_called()
            dry = deploy.prepare_candidate(config, package, dry_run=True, confirmation="")
            self.assertIn("prepare", dry["remote"])
            self.assertNotIn("install", dry["remote"])

    def test_activate_confirmation_id_validation_and_machine_json(self):
        config = deploy.load_config()
        release_id = "20260715000000-0123456789ab"
        with mock.patch.object(deploy, "run") as run:
            with self.assertRaisesRegex(deploy.DeployError, deploy.ACTIVATE_CONFIRMATION):
                deploy.activate_candidate(config, release_id, dry_run=False, confirmation="")
            run.assert_not_called()
        with self.assertRaisesRegex(deploy.DeployError, "Invalid release id"):
            deploy.activate_candidate(config, "../bad", dry_run=True, confirmation="")

        stdout = io.StringIO()
        stderr = io.StringIO()
        with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
            return_code = deploy.main(["activate", release_id, "--dry-run"])
        self.assertEqual(0, return_code)
        payload = json.loads(stdout.getvalue())
        self.assertEqual(release_id, payload["release_id"])
        self.assertTrue(payload["dry_run"])
        self.assertIn("activate", payload["remote"])
        self.assertEqual("", stderr.getvalue())

    def test_remote_stdout_must_be_machine_readable_status(self):
        config = deploy.load_config()
        invalid = subprocess.CompletedProcess([], 0, stdout="not-json", stderr="")
        with mock.patch.object(deploy, "run", return_value=invalid):
            with self.assertRaisesRegex(deploy.DeployError, "non-JSON stdout"):
                deploy.run_remote_status(config, "status")

        malformed = subprocess.CompletedProcess([], 0, stdout='{"current":null}', stderr="")
        with mock.patch.object(deploy, "run", return_value=malformed):
            with self.assertRaisesRegex(deploy.DeployError, "invalid JSON status"):
                deploy.run_remote_status(config, "status")

    def test_successful_prepare_parses_remote_status(self):
        with tempfile.TemporaryDirectory() as td:
            package = self.make_package(td)
            config = deploy.load_config()
            transferred = subprocess.CompletedProcess([], 0, stdout="", stderr="")
            remote = subprocess.CompletedProcess(
                [], 0, stdout='{"ok":true,"current":null,"prepared":{"state":"prepared"}}\n',
                stderr="",
            )
            with mock.patch.object(deploy, "run", side_effect=[transferred, remote]) as run, \
                    mock.patch.object(deploy, "write_report", return_value=Path(td) / "report.json"):
                result = deploy.prepare_candidate(
                    config,
                    package,
                    dry_run=False,
                    confirmation=deploy.PREPARE_CONFIRMATION,
                )
            self.assertTrue(result["ok"])
            self.assertEqual("prepared", result["remote_status"]["prepared"]["state"])
            self.assertEqual(2, run.call_count)
            self.assertIn("prepare", run.call_args_list[1].args[0])


if __name__ == "__main__":
    unittest.main()
