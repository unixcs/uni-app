import contextlib
import hashlib
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

    def make_frontend_output(
        self, root: Path, css_hash: str, css_content: bytes = b"entry styles"
    ) -> None:
        (root / "css").mkdir(parents=True)
        (root / "js").mkdir()
        (root / "css" / f"app.{css_hash}.css").write_bytes(css_content)
        (root / "css/chunk-vendors.12345678.css").write_bytes(b"vendor styles")
        (root / "js/app.abcdef12.js").write_bytes(b"entry javascript")
        reference = f"css/app.{css_hash}.css"
        (root / "index.html").write_text(
            f'<link href="{reference}" rel="preload" as="style">'
            f'<link href="{reference}" rel="stylesheet">'
            '<script src="js/app.abcdef12.js"></script>',
            encoding="utf-8",
        )

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

    def test_entry_css_hash_variance_normalizes_to_identical_stage_and_manifest(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            stages = []
            hashes = (
                {"admin": "1a179848", "store": "3f6cad2f"},
                {"admin": "0e528e80", "store": "e429e0b9"},
            )
            for number, app_hashes in enumerate(hashes):
                stage = root / f"stage-{number}"
                for app, css_hash in app_hashes.items():
                    app_root = stage / "yoshop2.0/public" / app
                    self.make_frontend_output(
                        app_root, css_hash, css_content=f"{app} styles".encode()
                    )
                    target_name = deploy.normalize_frontend_entry_css(app_root)
                    self.assertRegex(target_name, r"app\.[0-9a-f]{64}\.css")
                    self.assertTrue((app_root / "css" / target_name).is_file())
                    self.assertFalse((app_root / "css" / f"app.{css_hash}.css").exists())
                    self.assertTrue((app_root / "js/app.abcdef12.js").is_file())
                    index = (app_root / "index.html").read_text()
                    self.assertEqual(2, index.count(f"css/{target_name}"))
                    self.assertIn("js/app.abcdef12.js", index)
                stages.append(stage)

            def stage_bytes(stage):
                return {
                    path.relative_to(stage).as_posix(): path.read_bytes()
                    for path in sorted(stage.rglob("*"))
                    if path.is_file()
                }

            self.assertEqual(stage_bytes(stages[0]), stage_bytes(stages[1]))
            self.assertEqual(
                deploy.build_manifest(stages[0], "release", "commit"),
                deploy.build_manifest(stages[1], "release", "commit"),
            )

            changed = root / "changed"
            self.make_frontend_output(changed, "99999999", b"changed entry styles")
            changed_name = deploy.normalize_frontend_entry_css(changed)
            unchanged_name = next(
                path.name for path in (stages[0] / "yoshop2.0/public/admin/css").glob(
                    "app.*.css"
                )
            )
            self.assertNotEqual(unchanged_name, changed_name)
            self.assertEqual(
                2, (changed / "index.html").read_text().count(f"css/{changed_name}")
            )

    def test_entry_css_normalization_rejects_missing_multiple_and_bad_references(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)

            missing = root / "missing"
            self.make_frontend_output(missing, "11111111")
            (missing / "css/app.11111111.css").unlink()
            with self.assertRaisesRegex(deploy.DeployError, "found 0"):
                deploy.normalize_frontend_entry_css(missing)

            multiple = root / "multiple"
            self.make_frontend_output(multiple, "22222222")
            (multiple / "css/app.33333333.css").write_bytes(b"different styles")
            with self.assertRaisesRegex(deploy.DeployError, "found 2"):
                deploy.normalize_frontend_entry_css(multiple)

            bad_references = (
                ("missing-reference", 1),
                ("unexpected-link-structure", 2),
                ("extra-reference", 3),
            )
            for name, reference_count in bad_references:
                app_root = root / name
                self.make_frontend_output(app_root, "44444444")
                reference = "css/app.44444444.css"
                (app_root / "index.html").write_text(reference * reference_count)
                with self.subTest(name=name), self.assertRaisesRegex(
                    deploy.DeployError, f"found {reference_count} references"
                ):
                    deploy.normalize_frontend_entry_css(app_root)
                self.assertTrue((app_root / "css/app.44444444.css").is_file())
                self.assertFalse(any(app_root.glob(".index.html.normalizing-*")))

            mismatch = root / "mismatched-reference"
            self.make_frontend_output(mismatch, "66666666")
            mismatch_index = mismatch / "index.html"
            mismatch_index.write_text(
                mismatch_index.read_text().replace(
                    "css/app.66666666.css", "css/app.77777777.css"
                )
            )
            with self.assertRaisesRegex(deploy.DeployError, "found 2 references"):
                deploy.normalize_frontend_entry_css(mismatch)
            self.assertTrue((mismatch / "css/app.66666666.css").is_file())

    def test_entry_css_normalization_rejects_symlinks_without_following_them(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            app_root = root / "app"
            self.make_frontend_output(app_root, "88888888")
            source = app_root / "css/app.88888888.css"
            source.unlink()
            outside = root / "outside.css"
            outside.write_bytes(b"outside must survive")
            source.symlink_to(outside)

            with self.assertRaisesRegex(deploy.DeployError, "not a regular file"):
                deploy.normalize_frontend_entry_css(app_root)

            self.assertEqual(b"outside must survive", outside.read_bytes())
            self.assertTrue(source.is_symlink())

    def test_entry_css_normalization_rolls_back_when_index_replace_fails(self):
        with tempfile.TemporaryDirectory() as td:
            app_root = Path(td)
            self.make_frontend_output(app_root, "aaaaaaaa", b"entry styles")
            source = app_root / "css/app.aaaaaaaa.css"
            original_index = (app_root / "index.html").read_bytes()
            target = app_root / "css" / (
                f"app.{hashlib.sha256(source.read_bytes()).hexdigest()}.css"
            )

            with mock.patch.object(Path, "replace", side_effect=OSError("simulated")):
                with self.assertRaisesRegex(deploy.DeployError, "Cannot normalize"):
                    deploy.normalize_frontend_entry_css(app_root)

            self.assertTrue(source.is_file())
            self.assertFalse(target.exists())
            self.assertEqual(original_index, (app_root / "index.html").read_bytes())
            self.assertFalse(any(app_root.glob(".index.html.normalizing-*")))

    def test_entry_css_normalization_rejects_conflicting_fixed_target(self):
        with tempfile.TemporaryDirectory() as td:
            app_root = Path(td)
            self.make_frontend_output(app_root, "55555555", b"new entry styles")
            target = app_root / "css/app.css"
            target.write_bytes(b"different existing content")

            with self.assertRaisesRegex(deploy.DeployError, "target already exists"):
                deploy.normalize_frontend_entry_css(app_root)

            self.assertEqual(b"different existing content", target.read_bytes())
            self.assertTrue((app_root / "css/app.55555555.css").is_file())

            target.unlink()
            digest = hashlib.sha256(b"new entry styles").hexdigest()
            content_target = app_root / "css" / f"app.{digest}.css"
            content_target.write_bytes(b"conflicting content")
            with self.assertRaisesRegex(deploy.DeployError, "target already exists"):
                deploy.normalize_frontend_entry_css(app_root)
            self.assertEqual(b"conflicting content", content_target.read_bytes())
            self.assertTrue((app_root / "css/app.55555555.css").is_file())

            content_target.unlink()
            stale_target = app_root / "css" / f"app.{'0' * 64}.css"
            stale_target.write_bytes(b"stale normalized output")
            with self.assertRaisesRegex(deploy.DeployError, "Unexpected additional"):
                deploy.normalize_frontend_entry_css(app_root)
            self.assertEqual(b"stale normalized output", stale_target.read_bytes())
            self.assertTrue((app_root / "css/app.55555555.css").is_file())

    def test_build_release_normalizes_after_composer_before_scans_and_manifest(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            events = []
            commit = "a" * 40
            config = {
                "production_domain": "https://wx.gxwqb.cn",
                "development_domain": "https://wx.oiob.cn",
            }
            original_css_normalize = deploy.normalize_frontend_entry_css
            original_remove = deploy.remove_vcs_metadata
            original_normalize = deploy.normalize_thinkphp_services

            def extract_stage(stage):
                (stage / "yoshop2.0/public").mkdir(parents=True)

            def copy_output(source, destination):
                if destination.name in {"admin", "store"}:
                    css_hash = "11111111" if destination.name == "admin" else "22222222"
                    self.make_frontend_output(destination, css_hash)
                else:
                    destination.mkdir(parents=True)
                    (destination / "asset.txt").write_text("built")

            def copy_file(source, destination):
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_text(config["production_domain"])

            def composer_command(args, **kwargs):
                self.assertEqual("composer", args[0])
                backend = Path(args[args.index("--working-dir") + 1])
                if args[1] == "install":
                    metadata = backend / "vendor/package/.git"
                    metadata.mkdir(parents=True)
                    (metadata / "HEAD").write_text("variable checkout state")
                    services = backend / "vendor/services.php"
                    services.write_bytes(
                        b"<?php \n// This file is automatically generated at:2026-07-15 08:00:01\n"
                        b"return [];\n"
                    )
                    events.append("composer-install")
                elif args[1] == "dump-autoload":
                    self.assertEqual(
                        (
                            "composer", "dump-autoload", "--working-dir", str(backend),
                            "--no-dev", "--optimize", "--no-scripts", "--no-interaction",
                        ),
                        args,
                    )
                    self.assertTrue((backend / "vendor/package/.git").is_dir())
                    services = (backend / "vendor/services.php").read_bytes()
                    self.assertIn(b"automatically generated at:2026-07-15 08:00:01", services)
                    events.append("composer-dump")
                else:
                    self.fail(f"Unexpected Composer command: {args}")
                return subprocess.CompletedProcess(args, 0)

            def normalize_css(app_root):
                events.append(f"{app_root.name}-css")
                return original_css_normalize(app_root)

            def remove_metadata(stage):
                events.append("vcs")
                return original_remove(stage)

            def normalize_services(backend, commit_time):
                events.append("services")
                return original_normalize(backend, commit_time)

            def assert_normalized(stage):
                self.assertFalse((stage / "yoshop2.0/vendor/package/.git").exists())
                for app in ("admin", "store"):
                    app_root = stage / "yoshop2.0/public" / app
                    entry_css = list((app_root / "css").glob("app.*.css"))
                    self.assertEqual(1, len(entry_css))
                    self.assertRegex(entry_css[0].name, r"app\.[0-9a-f]{64}\.css")
                    self.assertEqual(
                        2,
                        (app_root / "index.html").read_text().count(
                            f"css/{entry_css[0].name}"
                        ),
                    )
                services = (stage / "yoshop2.0/vendor/services.php").read_bytes()
                self.assertIn(b"automatically generated at:2023-11-14T22:13:20Z", services)

            def scan_secrets(stage):
                events.append("secrets")
                assert_normalized(stage)
                return []

            def scan_domains(public, production, development):
                events.append("domains")
                return []

            def build_manifest(stage, release_id, git_commit):
                events.append("manifest")
                assert_normalized(stage)
                return {
                    "schema": 1,
                    "release_id": release_id,
                    "git_commit": git_commit,
                    "file_count": 0,
                    "files": [],
                }

            def write_package(source, destination, mtime):
                events.append("package")
                destination.write_bytes(b"deterministic package")

            patches = (
                mock.patch.object(deploy, "OUT_DIR", root / "out"),
                mock.patch.object(deploy, "DEPLOY_DIR", root / "deploy-input"),
                mock.patch.object(deploy, "preflight", return_value={"head": commit}),
                mock.patch.object(deploy, "build_frontends"),
                mock.patch.object(deploy, "git", return_value="1700000000"),
                mock.patch.object(deploy, "extract_git_tree", side_effect=extract_stage),
                mock.patch.object(deploy, "copy_tree", side_effect=copy_output),
                mock.patch.object(deploy.shutil, "copy2", side_effect=copy_file),
                mock.patch.object(
                    deploy, "normalize_frontend_entry_css", side_effect=normalize_css
                ),
                mock.patch.object(deploy, "run", side_effect=composer_command),
                mock.patch.object(deploy, "remove_vcs_metadata", side_effect=remove_metadata),
                mock.patch.object(
                    deploy, "normalize_thinkphp_services", side_effect=normalize_services
                ),
                mock.patch.object(deploy, "scan_secrets", side_effect=scan_secrets),
                mock.patch.object(deploy, "scan_web_domains", side_effect=scan_domains),
                mock.patch.object(deploy, "build_manifest", side_effect=build_manifest),
                mock.patch.object(deploy, "reproducible_tar", side_effect=write_package),
                mock.patch.object(deploy, "write_report", return_value=root / "report.json"),
            )
            with contextlib.ExitStack() as stack:
                for patch in patches:
                    stack.enter_context(patch)
                result = deploy.build_release(config)

            self.assertTrue(result["ok"])
            self.assertEqual(
                [
                    "admin-css", "store-css", "composer-install", "composer-dump",
                    "vcs", "services", "secrets", "domains", "manifest", "package",
                ],
                events,
            )

    def test_nested_vcs_metadata_is_removed_before_manifest_and_package(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            stage = root / "stage"
            package_root = stage / "yoshop2.0/vendor/example/package"
            package_root.mkdir(parents=True)
            (package_root / "runtime.php").write_text("<?php return true;\n")
            (package_root / ".gitignore").write_text("/cache\n")
            for marker in deploy.VCS_METADATA_NAMES:
                metadata = package_root / marker
                metadata.mkdir()
                (metadata / "state").write_text("changes every install")

            removed = deploy.remove_vcs_metadata(stage)

            self.assertEqual(
                [
                    f"yoshop2.0/vendor/example/package/{marker}"
                    for marker in sorted(deploy.VCS_METADATA_NAMES)
                ],
                removed,
            )
            manifest = deploy.build_manifest(stage, "release", "commit")
            manifest_paths = [entry["path"] for entry in manifest["files"]]
            self.assertIn("yoshop2.0/vendor/example/package/.gitignore", manifest_paths)
            self.assertFalse(
                any(
                    set(Path(path).parts) & deploy.VCS_METADATA_NAMES
                    for path in manifest_paths
                )
            )

            package = root / "release.tar.gz"
            deploy.reproducible_tar(stage, package, 1_700_000_000)
            with tarfile.open(package) as archive:
                archived = archive.getnames()
            self.assertFalse(
                any(set(Path(path).parts) & deploy.VCS_METADATA_NAMES for path in archived)
            )

    def test_vcs_metadata_symlink_is_not_followed_or_silently_packaged(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            stage = root / "stage"
            package_root = stage / "vendor/package"
            package_root.mkdir(parents=True)
            outside = root / "outside-repository"
            outside.mkdir()
            (outside / "index").write_text("must survive")
            (package_root / ".git").symlink_to(outside, target_is_directory=True)

            with self.assertRaisesRegex(deploy.DeployError, "non-directory VCS metadata"):
                deploy.remove_vcs_metadata(stage)

            self.assertEqual("must survive", (outside / "index").read_text())
            self.assertTrue((package_root / ".git").is_symlink())

    def test_thinkphp_services_normalization_is_repeatable_and_utc(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            contents = []
            generated_headers = (
                ("one", "2026-07-15 08:00:01"),
                ("two", "2025-01-02 03:04:05"),
            )
            for name, generated_at in generated_headers:
                backend = root / name
                services = backend / "vendor/services.php"
                services.parent.mkdir(parents=True)
                services.write_bytes(
                    b"<?php \n// This file is automatically generated at:"
                    + generated_at.encode("ascii")
                    + b"\ndeclare (strict_types = 1);\nreturn [];\n"
                )
                self.assertTrue(deploy.normalize_thinkphp_services(backend, 1_700_000_000))
                first = services.read_bytes()
                self.assertTrue(deploy.normalize_thinkphp_services(backend, 1_700_000_000))
                self.assertEqual(first, services.read_bytes())
                self.assertIn(b"automatically generated at:2023-11-14T22:13:20Z", first)
                contents.append(first)
            self.assertEqual(contents[0], contents[1])

    def test_thinkphp_services_parent_symlink_cannot_escape_backend(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            backend = root / "backend"
            backend.mkdir()
            outside_vendor = root / "outside-vendor"
            outside_vendor.mkdir()
            services = outside_vendor / "services.php"
            original = (
                b"<?php \n// This file is automatically generated at:2026-07-15 08:00:01\n"
                b"return [];\n"
            )
            services.write_bytes(original)
            (backend / "vendor").symlink_to(outside_vendor, target_is_directory=True)

            with self.assertRaisesRegex(deploy.DeployError, "escapes normalization root"):
                deploy.normalize_thinkphp_services(backend, 1_700_000_000)

            self.assertEqual(original, services.read_bytes())

    def test_unknown_thinkphp_services_header_fails_without_modification(self):
        with tempfile.TemporaryDirectory() as td:
            backend = Path(td)
            services = backend / "vendor/services.php"
            services.parent.mkdir(parents=True)
            original = (
                b"<?php \n// This file is automatically generated at:last Tuesday\n"
                b"return [];\n"
            )
            services.write_bytes(original)

            with self.assertRaisesRegex(deploy.DeployError, "Unknown ThinkPHP"):
                deploy.normalize_thinkphp_services(backend, 1_700_000_000)

            self.assertEqual(original, services.read_bytes())

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
