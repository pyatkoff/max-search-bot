"""Offline fixtures for the MAX photo packet's strict data-only Git checks."""
import json
import subprocess
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from verify_max_photo_packet import editor_preview, inspect_packet, read_registry_zip, sha


class MaxPhotoPacketTests(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.repo = Path(directory.name)
        self.git("init", "-q")
        self.write("editor.php", "<?php echo 1;")
        self.write("config/anytour_ranked_approved_indexes.json", "[]")
        self.application = self.commit("Installed application")
        self.archive = dict(ok=True, history_complete=True, price_content_shared_lease=True,
                            native_run_id="30", production_writes=0, provider_writes=0)
        self.source = dict(ok=True, operation="max_seven_source_rights",
                           application_sha=self.application, repository_sha=self.application,
                           archive_run=30, provider_writes=0, db_writes=0, bot_starts=0,
                           target_editor_rights=True)
        self.scope = dict(version=1, archive_run_id="30",
                          native_source=dict(run_id=31, attempt=1, application_sha=self.application,
                                             repository_sha=self.application),
                          rows=[dict(hotel_id=132, source_id=10, source_mid="mid.small",
                                     source_url="https://max.ru/channel_anytour/source",
                                     target_mid="mid.large", target_url="https://max.ru/anytour/target")])

    @staticmethod
    def encode(value):
        return json.dumps(value).encode()

    def git(self, *args):
        return subprocess.check_output(
            ["git", "-c", "user.name=Fixture", "-c", "user.email=fixture@example.com",
             "-C", str(self.repo), *args], text=True
        ).strip()

    def write(self, name, value):
        path = self.repo / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(value if isinstance(value, bytes) else value.encode())

    def commit(self, message):
        self.git("add", ".")
        self.git("commit", "-qm", message)
        return self.git("rev-parse", "HEAD")

    def packet(self):
        self.archive["rows"] = [dict(mid=row["target_mid"], url=row["target_url"]) for row in self.scope["rows"]]
        self.source["rows"] = list(self.scope["rows"])
        self.archive_bytes = self.encode(self.archive)
        self.source_bytes = self.encode(self.source)
        self.scope["archive_registry_sha256"] = sha(self.archive_bytes)
        self.scope["native_source"]["json_sha256"] = sha(self.source_bytes)
        raw = self.encode(self.scope)
        digest = sha(raw)
        name = f"config/anytour_max_photo_scope_{digest}.json"
        self.write(name, raw)
        return digest, self.commit("MAX scope only")

    def inspect(self, digest, commit, *, source=None):
        return inspect_packet(self.repo, self.application, commit, digest, self.archive_bytes,
                              self.source_bytes if source is None else source)

    def test_packet_is_evidence_only(self):
        self.scope["rows"].append(dict(hotel_id=249, source_id=11, source_mid="mid.small2",
                                       source_url="https://max.ru/channel_anytour/source2",
                                       target_mid="mid.large2", target_url="https://max.ru/anytour/target2"))
        digest, commit = self.packet()
        result = self.inspect(digest, commit)
        self.assertEqual(result["hotel_count"], 2)
        self.assertEqual(result["archive_run_id"], "30")
        self.assertTrue(result["evidence_only"])
        self.assertFalse(result["write_admission"])

    def test_packet_with_changed_editor_is_refused(self):
        self.write("editor.php", "<?php echo 2;")
        digest, commit = self.packet()
        with self.assertRaisesRegex(ValueError, "Git changes outside"):
            self.inspect(digest, commit)

    def test_max_packet_cannot_change_telegram_approval(self):
        self.write("config/anytour_ranked_approved_indexes.json", "[{}]")
        digest, commit = self.packet()
        with self.assertRaisesRegex(ValueError, "Git approval list change outside"):
            self.inspect(digest, commit)

    def test_native_source_changed(self):
        digest, commit = self.packet()
        with self.assertRaisesRegex(ValueError, "native source receipt changed"):
            self.inspect(digest, commit, source=self.source_bytes + b" ")

    def test_duplicate_target_is_refused(self):
        second = self.scope["rows"][0] | {"hotel_id": 249, "source_id": 11, "source_mid": "mid.small2",
                                          "source_url": "https://max.ru/channel_anytour/source2"}
        self.scope["rows"].append(second)
        digest, commit = self.packet()
        with self.assertRaisesRegex(ValueError, "duplicate MAX scope target_mid"):
            self.inspect(digest, commit)


    def test_data_is_based_on_repository_reusing_installed_application(self):
        self.write("README.md", "accepted repository update without reinstalling the application")
        repository = self.commit("Accepted repository update")
        self.scope["native_source"]["repository_sha"] = repository
        self.source["repository_sha"] = repository
        digest, commit = self.packet()
        result = inspect_packet(self.repo, self.application, commit, digest, self.archive_bytes,
                                self.source_bytes, repository_sha=repository)
        self.assertEqual(result["repository_sha"], repository)
        self.assertEqual(result["application_sha"], self.application)

    def test_registry_archive_must_contain_only_original_member(self):
        receipt = self.repo / "registry.zip"
        with zipfile.ZipFile(receipt, "w") as archive:
            archive.writestr("registry.json", b"{}")
        self.assertEqual(read_registry_zip(receipt), b"{}")
        with zipfile.ZipFile(receipt, "a") as archive:
            archive.writestr("another.json", b"{}")
        with self.assertRaisesRegex(ValueError, "unexpected MAX artifact members"):
            read_registry_zip(receipt)


    def test_editor_preview_compiles_ready_row_and_holds_only_occupied_target(self):
        start = self.scope["rows"][0]
        second = start | {"hotel_id": 249, "source_id": 11, "source_mid": "mid.small2",
                          "source_url": "https://max.ru/channel_anytour/source2", "target_mid": "mid.large2",
                          "target_url": "https://max.ru/anytour/target2"}
        self.scope["rows"].append(second)
        self.archive["rows"] = []
        self.source["rows"] = []
        self.source["channel_config_version"] = 5
        for row in self.scope["rows"]:
            row.update(name="Hotel Example", target_ms=123, target_fingerprint="f" * 64,
                       caption_without_price="Hotel Example — рассчитать поездку на ваши даты.")
            self.archive["rows"].append(dict(
                mid=row["target_mid"], url=row["target_url"], published_ms=123, fingerprint="f" * 64,
                registered=row is second, media=["image"], keyboard_present=False, linked_message=False,
            ))
            self.source["rows"].append(row | dict(
                blockers=[], source_catalog_id=row["hotel_id"], native_source_photo_count=1, source_version=2,
                source_content_sha256="a" * 64, source_photo_sha256="b" * 64, source_caption_sha256="c" * 64,
                source_caption_nonempty=True, native_source_photo_reusable=True,
                source_caption_matches_registry=True, source_photo_matches_registry=True,
                source_single_registry_button=True, target_matches_native_archive=True,
                source_hotel_id_matches=True, source_button_resolves_to_hotel=True,
                target_occupied=0, source_held=0, target_or_hotel_registry_conflicts=0,
            ))
        archive, source = self.encode(self.archive), self.encode(self.source)
        self.scope["archive_registry_sha256"] = sha(archive)
        self.scope["native_source"]["json_sha256"] = sha(source)
        raw = self.encode(self.scope)
        proof = editor_preview(raw, sha(raw), archive, source, self.application, self.application)
        self.assertEqual(set(proof["ready_for_editor_check"]), {"132"})
        self.assertRegex(proof["ready_for_editor_check"]["132"], r"^[a-f0-9]{64}$")
        self.assertEqual(proof["held"], {"249": "target_changed_or_occupied"})
        self.assertEqual(proof["provider_writes"], 0)


if __name__ == "__main__":
    unittest.main()
