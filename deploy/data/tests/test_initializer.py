from __future__ import annotations

import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest

MODULE_PATH = pathlib.Path(__file__).resolve().parents[1] / "initializer.py"
SPEC = importlib.util.spec_from_file_location("data_initializer", MODULE_PATH)
assert SPEC and SPEC.loader
initializer = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = initializer
SPEC.loader.exec_module(initializer)


class PolicyTests(unittest.TestCase):
    def test_safe_and_required_empty_tables_are_disjoint(self) -> None:
        policy = initializer.read_json(initializer.DEFAULT_POLICY)
        safe = {item["name"] for item in policy["safe_data_tables"]}
        forbidden = set(policy["required_empty_tables"])
        self.assertFalse(safe & forbidden)
        self.assertTrue(set(policy["private_tables"]) <= forbidden)
        self.assertEqual(
            set(policy["allowed_store_setting_keys"]), {"customer", "register"}
        )


class PageExtractionTests(unittest.TestCase):
    def test_decodes_json_before_extracting_escaped_upload_path(self) -> None:
        raw = json.dumps(
            {
                "items": [
                    {"params": {"imgUrl": "/uploads/10001/safe.png"}},
                    {"params": {"content": '<img src="https://prod.invalid/uploads/10001/rich.jpg">'}},
                ]
            }
        )
        self.assertEqual(
            initializer.extract_page_upload_paths(raw),
            {"10001/safe.png", "10001/rich.jpg"},
        )

    def test_supports_literal_escaped_slashes(self) -> None:
        raw = r'{"items":[{"img":"\/uploads\/10001\/escaped.png"}]}'
        self.assertEqual(
            initializer.extract_page_upload_paths(raw), {"10001/escaped.png"}
        )

    def test_extracts_self_closing_html_without_trailing_slash(self) -> None:
        self.assertEqual(
            initializer.extract_upload_paths('<img src="/uploads/10001/image.jpg"/>'),
            {"10001/image.jpg"},
        )
        self.assertEqual(initializer.extract_upload_paths("notuploads/x.jpg"), set())

    def test_rejects_invalid_page_json(self) -> None:
        with self.assertRaises(initializer.InitializerError):
            initializer.extract_page_upload_paths('{"items":')

    def test_rejects_plain_and_percent_encoded_traversal(self) -> None:
        for value in [
            '<img src="/uploads/../private.pem">',
            '<img src="/uploads/%2e%2e/private.pem">',
            '<img src="/uploads/%252e%252e/private.pem">',
        ]:
            with self.subTest(value=value), self.assertRaises(initializer.InitializerError):
                initializer.extract_upload_paths(value)


class UploadSelectionTests(unittest.TestCase):
    def test_combines_safe_direct_and_content_refs_but_excludes_unsafe_only(self) -> None:
        records = [
            initializer.UploadRecord(1, "local", "10001/direct.jpg", 3, "direct.jpg", 0),
            initializer.UploadRecord(2, "local", "10001/content.jpg", 4, "content.jpg", 0),
            initializer.UploadRecord(3, "local", "10001/user-only.jpg", 5, "user.jpg", 0),
        ]
        selected = initializer.select_upload_records(
            records,
            {1: {"direct:yoshop_goods_image.image_id"}},
            {"10001/content.jpg": {"content:yoshop_goods.content[1]"}},
        )
        self.assertEqual([item.record.file_id for item in selected], [1, 2])
        self.assertNotIn(3, [item.record.file_id for item in selected])

    def test_rejects_missing_deleted_or_remote_uploads(self) -> None:
        with self.assertRaises(initializer.InitializerError):
            initializer.select_upload_records([], {9: {"direct:safe"}}, {})
        with self.assertRaises(initializer.InitializerError):
            initializer.select_upload_records(
                [initializer.UploadRecord(1, "local", "x.jpg", 1, "x.jpg", 1)],
                {1: {"direct:safe"}},
                {},
            )
        with self.assertRaises(initializer.InitializerError):
            initializer.select_upload_records(
                [initializer.UploadRecord(1, "qcloud", "x.jpg", 1, "x.jpg", 0)],
                {1: {"direct:safe"}},
                {},
            )


class PackageIntegrityTests(unittest.TestCase):
    def test_checksums_cover_every_file_and_detect_tampering(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            root = pathlib.Path(temp)
            (root / "schema.sql").write_text("CREATE TABLE x (id INT);\n")
            (root / "nested").mkdir()
            (root / "nested/file.bin").write_bytes(b"abc")
            initializer.write_checksums(root)
            self.assertEqual(initializer.verify_checksums(root), 2)
            (root / "nested/file.bin").write_bytes(b"changed")
            with self.assertRaises(initializer.InitializerError):
                initializer.verify_checksums(root)

    def test_scanner_rejects_dev_domain_private_key_and_exact_secret(self) -> None:
        cases = [
            (b"https://wx.oiob.cn/uploads/x.jpg", ["wx.oiob.cn"], []),
            (b"-----BEGIN PRIVATE KEY-----\nabc", [], []),
            (b"prefix-supersecret-suffix", [], [b"supersecret"]),
        ]
        for payload, domains, secrets in cases:
            with self.subTest(payload=payload), tempfile.TemporaryDirectory() as temp:
                root = pathlib.Path(temp)
                (root / "artifact").write_bytes(payload)
                with self.assertRaises(initializer.InitializerError):
                    initializer.scan_public_artifacts(root, domains, secrets)

    def test_forbidden_transactional_insert_is_rejected(self) -> None:
        policy = initializer.read_json(initializer.DEFAULT_POLICY)
        with self.assertRaises(initializer.InitializerError):
            initializer.assert_no_private_inserts(
                "INSERT INTO `yoshop_order` (`order_id`) VALUES (1);",
                "yoshop_",
                policy,
            )

    def test_manifest_rejects_traversal(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            root = pathlib.Path(temp)
            manifest = {
                "format_version": 1,
                "referenced_file_count": 1,
                "files": [
                    {
                        "file_id": 1,
                        "file_path": "../secret",
                        "package_path": "uploads/../secret",
                        "size": 0,
                        "sha256": "0" * 64,
                    }
                ],
            }
            (root / "uploads-manifest.json").write_text(json.dumps(manifest))
            with self.assertRaises(initializer.InitializerError):
                initializer.validate_manifest(root)


if __name__ == "__main__":
    unittest.main()
