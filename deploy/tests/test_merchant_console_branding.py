from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
STORE_SOURCE = ROOT / "yoshop2.0-store/src"
BASIC_LAYOUT = STORE_SOURCE / "layouts/BasicLayout.vue"
LEGACY_FOOTER = STORE_SOURCE / "components/GlobalFooter/index.vue"
FORBIDDEN_MARKERS = (
    "萤火商城V2.0",
    "YIOVO.COM",
    "https://www.yiovo.com",
    "%5C%B2%DF%E9%EB%DB%D0%CF%DC%94%C9%C9RbbfT%u8444%uF48F%uC5B1%uAD14%u5824%88%60%5E",
    "b%A2%98%A5%A5%7Dq%92%9C",
    "%7D%DC%E8%E4%E3%ADi%5E%A6%EE%EE%A5%A7%E2%D8%E5%E5%9D%91%D2%DC",
)


class MerchantConsoleBrandingContractTests(unittest.TestCase):
    def test_authenticated_layout_does_not_mount_legacy_footer(self) -> None:
        layout = BASIC_LAYOUT.read_text(encoding="utf-8")
        self.assertNotIn('v-slot:footerRender', layout)
        self.assertNotIn("@/components/GlobalFooter", layout)
        self.assertFalse(LEGACY_FOOTER.exists())

    def test_legacy_branding_and_obfuscated_source_are_absent(self) -> None:
        source = "\n".join(
            path.read_text(encoding="utf-8", errors="ignore")
            for path in STORE_SOURCE.rglob("*")
            if path.is_file()
        )
        for marker in FORBIDDEN_MARKERS:
            with self.subTest(marker=marker):
                self.assertNotIn(marker, source)


if __name__ == "__main__":
    unittest.main()
