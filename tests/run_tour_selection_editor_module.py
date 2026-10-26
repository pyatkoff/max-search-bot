"""Independent editor artifact identity and boundary regressions (synthetic)."""
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import zipfile

REPO = Path(__file__).resolve().parents[1]
SCRIPT = REPO / "modules/tour-selections/build-editor.py"
loader = importlib.util.spec_from_file_location("selection_editor_package", SCRIPT)
pack = importlib.util.module_from_spec(loader)
loader.loader.exec_module(pack)


class EditorPackageTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.root = self.base / "repo"
        self.root.mkdir()
        *_, tracked = pack.inputs(REPO)
        for name in tracked:
            target = self.root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(REPO / name, target)
        subprocess.run(["git", "init", "-q", str(self.root)], check=True)
        self.git("config", "user.name", "Synthetic package test")
        self.git("config", "user.email", "package-test@example.invalid")
        self.commit("initial")

    def git(self, *args):
        return pack.git(self.root, *args)

    def commit(self, message):
        self.git("add", ".")
        self.git("commit", "-qm", message)

    def build(self, name="build", working_tree=False):
        output = self.base / name
        return output, pack.build(self.root, output, working_tree)

    def test_exact_assets_and_existing_collector_not_a_copy(self):
        output, receipt = self.build()
        self.assertTrue(pack.verify(self.root, output)["release_eligible"])
        for name, path in pack.FILES.items():
            self.assertEqual((output / "payload" / name).read_bytes(), (self.root / path).read_bytes())
        with zipfile.ZipFile(output / "selection-editor.zip") as z:
            self.assertEqual(set(z.namelist()), set(pack.FILES) | {"release.json"})
        html = (output / "preview/index.html").read_text()
        self.assertIn('../payload/editor.js', html)
        self.assertIn('../payload/editor.css', html)
        self.assertIn("connect-src 'none'", html)
        self.assertNotIn('manager2-host.js', html)
        self.assertEqual(receipt["source_commit"], self.git("rev-parse", "HEAD"))

    def test_unrelated_commit_does_not_rebuild_identity(self):
        first, a = self.build("first")
        (self.root / "unrelated.txt").write_text("Synthetic unrelated change\n")
        self.commit("unrelated")
        second, b = self.build("second")
        self.assertNotEqual(a["source_commit"], b["source_commit"])
        self.assertEqual(a["package_id"], b["package_id"])
        self.assertEqual(a["backend_fingerprint"], b["backend_fingerprint"])
        self.assertEqual((first / "selection-editor.zip").read_bytes(), (second / "selection-editor.zip").read_bytes())

    def test_dirty_input_has_no_release_receipt(self):
        path = self.root / pack.FILES["editor.js"]
        path.write_bytes(path.read_bytes() + b"\n// synthetic change\n")
        with self.assertRaisesRegex(ValueError, "uncommitted_editor_inputs"):
            self.build("refused")
        output, receipt = self.build("dev", True)
        self.assertFalse(receipt["release_eligible"])
        self.assertFalse(pack.verify(self.root, output)["release_eligible"])

    def test_changed_backend_contract_refuses_old_artifact(self):
        output, _ = self.build()
        path = self.root / json.loads((self.root / pack.MODULE).read_bytes())["backend_inputs"][0]
        path.write_bytes(path.read_bytes() + b"\n// changed synthetic API\n")
        with self.assertRaisesRegex(ValueError, "editor_backend_contract_changed"):
            pack.verify(self.root, output)

    def test_payload_and_preview_tampering_are_rejected(self):
        for relative in ["payload/editor.js", "preview/preview-host.js"]:
            output, _ = self.build(relative.split('/')[0])
            (output / relative).write_bytes(b"unexpected bytes")
            with self.assertRaisesRegex(ValueError, "bytes_mismatch"):
                pack.verify(self.root, output)

    def test_foreign_archive_member_even_with_rehashed_receipt_is_rejected(self):
        output, receipt = self.build()
        archive = output / "selection-editor.zip"
        with zipfile.ZipFile(archive, "a") as z:
            z.writestr("config.php", "synthetic forbidden member")
        receipt["archive_sha256"] = pack.digest(archive.read_bytes())
        (output / "receipt.json").write_bytes(pack.encoded(receipt))
        with self.assertRaisesRegex(ValueError, "unexpected_editor_members"):
            pack.verify(self.root, output)

    def test_stale_existing_collector_cannot_be_shipped(self):
        path = self.root / "extensions/selection-collector/background.js"
        path.write_bytes(path.read_bytes() + b"\n// stale synthetic source\n")
        with self.assertRaisesRegex(ValueError, "stale_collector_archive"):
            self.build()

    def test_symlink_cannot_copy_an_outside_file(self):
        path = self.root / pack.FILES["editor.js"]
        path.unlink()
        outside = self.base / "outside.txt"
        outside.write_text("not a module asset")
        path.symlink_to(outside)
        with self.assertRaisesRegex(ValueError, "invalid_input_file"):
            self.build()


if __name__ == "__main__":
    unittest.main(verbosity=2)
