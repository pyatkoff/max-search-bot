#!/usr/bin/env python3
"""Native flock regressions using private fixtures; no SSH or production access."""

import fcntl
import os
from pathlib import Path
import shlex
import subprocess
import tempfile
import time
import unittest


ROOT = Path(__file__).resolve().parents[1]
GUARD = (ROOT / "tools/deploy_monitor_lock.sh").read_text()
JOURNAL_BINDING = "deploy_monitor_journal=/var/www/anytoour/data/channel-post-operations/monitor-price-after-collection-v1.jsonl"
WAIT_BINDING = "deploy_monitor_wait_seconds=2100"


class DeployMonitorLockRegression(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="deploy-monitor-lock-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.journal = self.root / "monitor.jsonl"
        self.original = b'{"pending":{"status":"requires_reconciliation"}}\n'
        self.journal.write_bytes(self.original)
        self.sequence = 0

    def guard(self, journal=None, wait=10):
        # Bind only the fixed test fixture; production has no bypass/path override.
        self.assertEqual(GUARD.count(JOURNAL_BINDING), 1)
        self.assertEqual(GUARD.count(WAIT_BINDING), 1)
        return GUARD.replace(
            JOURNAL_BINDING,
            "deploy_monitor_journal=" + shlex.quote(str(journal or self.journal)),
        ).replace(WAIT_BINDING, "deploy_monitor_wait_seconds=" + str(wait))

    def start(self, script, *, cwd=None, env=None):
        self.sequence += 1
        script_file = self.root / ("script-%s.sh" % self.sequence)
        output_file = self.root / ("output-%s.log" % self.sequence)
        script_file.write_text(script)
        with script_file.open("rb") as source, output_file.open("wb") as output:
            process = subprocess.Popen(
                ["bash", "-s"], stdin=source, stdout=output,
                stderr=subprocess.STDOUT, cwd=cwd, env=env,
            )

        def stop():
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=2)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=2)

        self.addCleanup(stop)
        return process, output_file

    def wait_for(self, predicate, output):
        deadline = time.monotonic() + 5
        while not predicate():
            if time.monotonic() >= deadline:
                self.fail("fixture did not reach expected state: " + output.read_text())
            time.sleep(0.01)

    def finish(self, process, output, expected=0):
        self.assertEqual(process.wait(timeout=5), expected, output.read_text())

    def assert_locked(self, journal=None):
        with (journal or self.journal).open("rb") as contender:
            with self.assertRaises(BlockingIOError):
                fcntl.flock(contender, fcntl.LOCK_EX | fcntl.LOCK_NB)

    def assert_unlocked(self):
        with self.journal.open("rb") as contender:
            fcntl.flock(contender, fcntl.LOCK_EX | fcntl.LOCK_NB)
            fcntl.flock(contender, fcntl.LOCK_UN)

    def test_waits_for_monitor_and_preserves_pending_journal(self):
        marker = self.root / "checkout-mutated"
        inode = self.journal.stat().st_ino
        with self.journal.open("rb") as monitor:
            fcntl.flock(monitor, fcntl.LOCK_EX)
            process, output = self.start(self.guard() + "\n: > " + shlex.quote(str(marker)))
            self.wait_for(lambda: "lock=waiting" in output.read_text(), output)
            self.assertFalse(marker.exists())
            self.assertIsNone(process.poll())
            fcntl.flock(monitor, fcntl.LOCK_UN)
            self.finish(process, output)
        self.assertTrue(marker.exists())
        self.assertEqual(self.journal.read_bytes(), self.original)
        self.assertEqual(self.journal.stat().st_ino, inode)
        self.assert_unlocked()

    def test_lock_covers_body_and_nonzero_exit_releases_it(self):
        entered, release = self.root / "entered", self.root / "release"
        body = "\n: > %s\nfor ((n=0; n<500; n++)); do\n  [[ -e %s ]] && exit 23\n  sleep 0.01\ndone\nexit 24\n" % (
            shlex.quote(str(entered)), shlex.quote(str(release)),
        )
        process, output = self.start(self.guard() + body)
        self.wait_for(entered.exists, output)
        self.assert_locked()
        release.touch()
        self.finish(process, output, 23)
        self.assert_unlocked()
        self.assertEqual(self.journal.read_bytes(), self.original)

    def test_timeout_has_no_checkout_write_and_does_not_interrupt_monitor(self):
        marker = self.root / "checkout-mutated"
        with self.journal.open("rb") as monitor:
            fcntl.flock(monitor, fcntl.LOCK_EX)
            process, output = self.start(self.guard(wait=1) + "\n: > " + shlex.quote(str(marker)))
            self.finish(process, output, 75)
            self.assertFalse(marker.exists())
            self.assert_locked()
        self.assertEqual(self.journal.read_bytes(), self.original)
        self.assert_unlocked()

    def test_replaced_inode_while_waiting_refuses_checkout_change(self):
        marker = self.root / "checkout-mutated"
        with self.journal.open("rb") as monitor:
            fcntl.flock(monitor, fcntl.LOCK_EX)
            process, output = self.start(self.guard() + "\n: > " + shlex.quote(str(marker)))
            self.wait_for(lambda: "lock=waiting" in output.read_text(), output)
            old_journal = self.root / "old-journal.jsonl"
            self.journal.rename(old_journal)
            self.journal.write_bytes(b"replacement\n")
            fcntl.flock(monitor, fcntl.LOCK_UN)
            self.finish(process, output, 1)
        self.assertFalse(marker.exists())
        self.assertIn("journal inode changed", output.read_text())
        self.assertEqual(old_journal.read_bytes(), self.original)
        self.assertEqual(self.journal.read_bytes(), b"replacement\n")

    def test_missing_or_unsafe_journal_fails_without_creating_it(self):
        missing = self.root / "missing"
        symlink = self.root / "symlink"
        symlink.symlink_to(self.journal)
        directory = self.root / "directory"
        directory.mkdir()
        fifo = self.root / "fifo"
        os.mkfifo(fifo)
        for candidate in (missing, symlink, directory, fifo):
            with self.subTest(candidate=candidate.name):
                marker = self.root / ("checkout-" + candidate.name)
                process, output = self.start(self.guard(candidate) + "\n: > " + shlex.quote(str(marker)))
                self.finish(process, output, 1)
                self.assertFalse(marker.exists())
        self.assertFalse(missing.exists())
        self.assertEqual(self.journal.read_bytes(), self.original)

    def test_both_real_sync_scripts_hold_lock_through_reset(self):
        # Execute each unchanged workflow run block with local fake transports and
        # Git/PHP/curl commands. This catches acquiring the guard in a child shell,
        # sending it after reset, or breaking the remote script's quoting.
        for workflow, step, next_step, bundle_prefix in (
            ("deploy.yml", "Sync canonical production checkout", "Apply database migrations", "max-search-production"),
            ("deploy-standby.yml", "Sync standby checkout", "Normalize standby runtime configuration", "max-search-bot"),
        ):
            with self.subTest(workflow=workflow):
                work = self.root / workflow
                work.mkdir()
                (work / "tools").mkdir()
                (work / "tools/deploy_monitor_lock.sh").write_text(self.guard())
                binary = work / "bin"
                binary.mkdir()
                entered, release = work / "reset-entered", work / "reset-release"
                stubs = {
                    "ssh": "exec bash -s\n",
                    "scp": "exit 0\n",
                    "php": "exit 0\n",
                    "curl": "printf 403\n",
                    "git": (
                        'case "$1" in\n'
                        '  diff|fetch|cat-file) exit 0 ;;\n'
                        '  rev-parse) printf "%s\\n" "$EXPECTED_SHA" ;;\n'
                        '  reset) : > "$FIXTURE_ENTERED"\n'
                        '    for ((n=0; n<500; n++)); do\n'
                        '      [[ -e "$FIXTURE_RELEASE" ]] && exit 0\n'
                        '      sleep 0.01\n'
                        '    done\n'
                        '    exit 24 ;;\n'
                        '  *) exit 25 ;;\n'
                        'esac\n'
                    ),
                }
                for name, content in stubs.items():
                    target = binary / name
                    target.write_text("#!/usr/bin/env bash\nset -euo pipefail\n" + content)
                    target.chmod(0o700)
                text = (ROOT / ".github/workflows" / workflow).read_text()
                block = text.split("      - name: " + step, 1)[1].split("      - name: " + next_step, 1)[0]
                self.assertIn("timeout-minutes: 45", block)
                self.assertIn("ServerAliveInterval=15", block)
                self.assertIn("ServerAliveCountMax=4", block)
                script = "\n".join(line[10:] for line in block.split("        run: |\n", 1)[1].splitlines())
                script = script.replace("/var/www/anytoour/data/www/app.anytoour.ru", str(work))
                script = script.replace("/tmp/" + bundle_prefix, str(work / bundle_prefix))
                environment = dict(os.environ, PATH=str(binary) + os.pathsep + os.environ["PATH"],
                    RUNNER_TEMP=str(work), EXPECTED_SHA="1" * 40,
                    DEPLOY_USER="fixture", DEPLOY_HOST="fixture",
                    STANDBY_DEPLOY_USER="fixture", STANDBY_DEPLOY_HOST="fixture",
                    FIXTURE_ENTERED=str(entered), FIXTURE_RELEASE=str(release))
                with self.journal.open("rb") as monitor:
                    fcntl.flock(monitor, fcntl.LOCK_EX)
                    process, output = self.start(script, cwd=work, env=environment)
                    self.wait_for(lambda: "lock=waiting" in output.read_text(), output)
                    self.assertFalse(entered.exists())
                    fcntl.flock(monitor, fcntl.LOCK_UN)
                    self.wait_for(entered.exists, output)
                    self.assert_locked()
                    release.touch()
                    self.finish(process, output)
                self.assert_unlocked()
                self.assertEqual(self.journal.read_bytes(), self.original)


if __name__ == "__main__":
    unittest.main(verbosity=2)
