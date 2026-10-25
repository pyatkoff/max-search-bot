import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import unittest
import zipfile

SCRIPT = Path(__file__).resolve().parents[1] / "build.py"
REPO = SCRIPT.parents[2]
module = importlib.util.spec_from_file_location("selection_package", SCRIPT)
pack = importlib.util.module_from_spec(module)
module.loader.exec_module(pack)


class PresentationPackageTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.base = Path(self.tmp.name)
        self.root = self.base / "repo"
        self.root.mkdir()
        spec = json.loads((REPO / pack.MODULE).read_bytes())
        for rel in [pack.MODULE, *pack.FILES.values(), *spec["backend_inputs"],
                    "modules/tour-selections/build.py", "modules/tour-selections/preview.php"]:
            target = self.root / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(REPO / rel, target)
        self.git("init", "-q")
        self.git("config", "user.name", "Synthetic fixture")
        self.git("config", "user.email", "fixture@example.invalid")
        self.commit()

    def tearDown(self):
        self.tmp.cleanup()

    def git(self, *args):
        return pack.git(self.root, *args)

    def commit(self):
        self.git("add", ".")
        self.git("commit", "-qm", "Synthetic package inputs")

    def build(self, name="out", **kwargs):
        out = self.base / name
        receipt = pack.build(self.root, out, **kwargs)
        return out, receipt

    def test_deterministic_archive_and_public_only_inventory(self):
        out, receipt = self.build()
        second, _ = self.build("second")
        self.assertTrue(receipt["release_eligible"])
        self.assertEqual((out / "selection-public.zip").read_bytes(), (second / "selection-public.zip").read_bytes())
        self.assertTrue(pack.verify(self.root, out)["ok"])
        with zipfile.ZipFile(out / "selection-public.zip") as z:
            self.assertEqual(set(z.namelist()), {"view.php", "selection.css", "activity.js", "release.json"})
            self.assertEqual(z.read("view.php"), (REPO / "selection/view.php").read_bytes())

    def test_unrelated_commit_preserves_artifact_but_not_source_receipt(self):
        first, before = self.build()
        (self.root / "unrelated.txt").write_text("Other direction, no presentation dependency")
        self.commit()
        _, after = self.build("after")
        self.assertNotEqual(before["source_commit"], after["source_commit"])
        self.assertEqual(before["package_id"], after["package_id"])
        self.assertEqual(before["archive_sha256"], after["archive_sha256"])
        self.assertTrue(pack.verify(self.root, first)["backend_compatible"])

    def test_changed_backend_rejects_old_compatibility(self):
        out, _ = self.build()
        p = self.root / "selection/activity.php"
        p.write_bytes(p.read_bytes() + b"\n// Synthetic changed boundary\n")
        with self.assertRaisesRegex(ValueError, "backend_contract_changed"):
            pack.verify(self.root, out)

    def test_changed_presentation_rejects_old_package(self):
        out, _ = self.build()
        p = self.root / "selection/selection.css"
        p.write_bytes(p.read_bytes() + b"\n/* Synthetic edit */\n")
        with self.assertRaisesRegex(ValueError, "package_identity_mismatch"):
            pack.verify(self.root, out)

    def test_dirty_input_is_development_only(self):
        p = self.root / "selection/activity.js"
        p.write_bytes(p.read_bytes() + b"\n// Synthetic local edit\n")
        with self.assertRaisesRegex(ValueError, "uncommitted_module_inputs"):
            self.build()
        _, receipt = self.build(working_tree=True)
        self.assertFalse(receipt["release_eligible"])

    def test_unknown_secret_file_is_not_packaged(self):
        (self.root / "selection/private.env").write_text("SYNTHETIC_SECRET_MUST_NOT_LEAVE")
        out, _ = self.build()
        self.assertNotIn(b"SYNTHETIC_SECRET_MUST_NOT_LEAVE", (out / "selection-public.zip").read_bytes())

    def test_manifest_cannot_add_bootstrap_or_traversal(self):
        p = self.root / pack.MODULE
        spec = json.loads(p.read_bytes())
        spec["files"]["config.php"] = "config.php"
        p.write_bytes(pack.encoded(spec))
        with self.assertRaisesRegex(ValueError, "invalid_module_manifest"):
            self.build()
        with self.assertRaisesRegex(ValueError, "invalid_input_path"):
            pack.source(self.root, "../secret")

    def test_symlink_input_is_rejected(self):
        p = self.root / "selection/view.php"
        p.unlink()
        p.symlink_to(REPO / "selection/view.php")
        with self.assertRaisesRegex(ValueError, "invalid_input_file"):
            self.build()

    def test_tampered_archive_is_rejected(self):
        out, _ = self.build()
        archive = out / "selection-public.zip"
        archive.write_bytes(archive.read_bytes() + b"tampered")
        with self.assertRaisesRegex(ValueError, "package_identity_mismatch"):
            pack.verify(self.root, out)

    def test_unexpected_member_rejected_even_with_rehashed_receipt(self):
        out, _ = self.build()
        archive = out / "selection-public.zip"
        with zipfile.ZipFile(archive, "a") as z:
            z.writestr("../secret", "Synthetic forbidden member")
        receipt = json.loads((out / "receipt.json").read_bytes())
        receipt["archive_sha256"] = pack.digest(archive.read_bytes())
        (out / "receipt.json").write_bytes(pack.encoded(receipt))
        with self.assertRaisesRegex(ValueError, "unexpected_archive_members"):
            pack.verify(self.root, out)

    def test_preview_renders_packaged_template_without_business_connections(self):
        out, _ = self.build()
        available = (out / "preview/index.html").read_text()
        unavailable = (out / "preview/unavailable.html").read_text()
        self.assertIn("Тестовая подборка AnyTour", available)
        self.assertIn("180 000,00", available)
        self.assertIn("205 000,00", available)
        self.assertIn("Номер с видом на море", available)
        self.assertIn("Сообщения не отправляются", available)
        self.assertNotIn("data-selection-interest", available)
        self.assertNotIn("https://t.me", available)
        self.assertIn("Подборка сейчас недоступна", unavailable)
        self.assertNotIn("Демонстрационный отель", unavailable)


if __name__ == "__main__":
    unittest.main()
