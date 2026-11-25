"""Exercise the checked-in sync shell with local Git repositories and a real file lock."""
import fcntl
import os
from pathlib import Path
import select
import shutil
import subprocess
from tempfile import TemporaryDirectory
import textwrap
import unittest


ROOT = Path(__file__).resolve().parents[1]
BASE = "302ba66b5739e369a4df94471c8cfe9c054dd070"
PR1702_MAIN = "32155b9eb2c8606b61554951bfdc988d70bbcb39"
PR1692_MAIN = "cdde6f427f61bace3a5afbd943d2303f463d6531"
PR1692_INSTALLED = "e3e1a9bdf29dd0d7530fe6b3b393f1286834d092"
PR1721_MAIN = "3073195685c8d57808256af6836182262f25fead"
PRODUCTION = "/var/www/anytoour/data/www/app.anytoour.ru"
JOURNAL = "/var/www/anytoour/data/channel-post-operations/monitor-price-after-collection-v1.jsonl"
GIT = shutil.which("git")


def checked_git(repo, *args):
    return subprocess.check_output([GIT, "-C", str(repo), *args], text=True).strip()


class ProductionSync(unittest.TestCase):
    def setUp(self):
        self.temp = TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.tools = self.root / "tools"
        self.checkout = self.root / "checkout"
        self.journal = self.root / "journal"
        self.calls = self.root / "calls"
        self.bin = self.root / "bin"
        for directory in (self.tools, self.checkout, self.bin):
            directory.mkdir()
        self.journal.write_text("one original journal record\n")
        self.calls.touch()
        workflow = (ROOT / ".github/workflows/deploy.yml").read_text()
        step = workflow.split("      - name: Sync canonical production checkout\n", 1)[1].split("\n      - name:", 1)[0]
        self.assertIn("EXPECTED_CHECKOUT_SHA: ${{ steps.release_plan.outputs.expected_checkout_sha }}", step)
        self.assertIn("PREVIOUS_MAIN_SHA: ${{ github.event.before }}", step)
        shell = textwrap.dedent(step.split("        run: |\n", 1)[1])
        # Only absolute filesystem locations are redirected. All commands, predicates,
        # production SHAs and lock ordering are the actual workflow's shell.
        self.shell = shell.replace(PRODUCTION, str(self.checkout)).replace(
            "/tmp/max-search-production-", str(self.root / "uploaded-"))
        lock = (ROOT / "tools/deploy_monitor_lock.sh").read_text()
        self.assertIn(JOURNAL, lock)
        (self.tools / "deploy_monitor_lock.sh").write_text(lock.replace(JOURNAL, str(self.journal)))
        fence = (ROOT / "tools/check_public_runtime_log_fence.sh").read_bytes()
        (self.tools / "check_public_runtime_log_fence.sh").write_bytes(fence)
        self.make_git_checkout()
        self.install_doubles()

    def make_git_checkout(self):
        checked_git(self.checkout, "init", "-q")
        user = ["-c", "user.name=Incident test", "-c", "user.email=incident@example.invalid"]
        (self.checkout / "version").write_text("original\n")
        checked_git(self.checkout, "add", "version")
        checked_git(self.checkout, *user, "commit", "-qm", "original fixture")
        self.original = checked_git(self.checkout, "rev-parse", "HEAD")
        (self.checkout / "version").write_text("successor\n")
        checked_git(self.checkout, "add", "version")
        checked_git(self.checkout, *user, "commit", "-qm", "successor fixture")
        self.successor = checked_git(self.checkout, "rev-parse", "HEAD")
        checked_git(self.checkout, "bundle", "create", str(self.root / "production-deploy.bundle"), "HEAD")
        checked_git(self.checkout, "reset", "--hard", self.original)
        self.pin_checkout(BASE)

    def pin_checkout(self, public_sha):
        # Represent the public predecessor ID using a local fixture object. No public
        # repository, credential, remote host, or actual application's files are used.
        checked_git(self.checkout, "update-ref", "refs/replace/" + public_sha, self.original)
        branch = checked_git(self.checkout, "symbolic-ref", "HEAD")
        (self.checkout / ".git" / branch).write_text(public_sha + "\n")
        self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), public_sha)
        self.assertEqual(checked_git(self.checkout, "status", "--porcelain"), "")

    def install_doubles(self):
        wrappers = {
            "curl": """
                [[ " $* " == *" -I "* && " $* " == *" -o /dev/null "* &&
                   "${@: -1}" == https://app.anytoour.ru/structured_events.log ]]
                count=$(grep -c '^http$' "$CALLS" || true)
                echo http >> "$CALLS"
                read -r -a statuses <<< "$HTTP_STATUS_SEQUENCE"
                code="${statuses[count]:-${statuses[-1]}}"
                [[ "$code" != timeout ]] || exit 28
                printf %s "$code"
            """,
            "scp": """
                echo scp >> "$CALLS"
                dst="${@: -1}"
                cp "${@: -2:1}" "${dst#*:}"
            """,
            "ssh": """
                [[ "${@: -1}" == "bash -s" ]]
                echo ssh >> "$CALLS"
                export LOCAL_REMOTE=1
                exec bash -s
            """,
            "git": """
                if [[ "${LOCAL_REMOTE:-}" == 1 ]]; then
                  # A distinct file descriptor cannot acquire this exclusive lock.
                  if flock --nonblock "$LOCAL_JOURNAL" true; then exit 78; fi
                  echo "locked git $*" >> "$CALLS"
                fi
                exec "$REAL_GIT" "$@"
            """,
            "php": """
                [[ "$*" == tools/structured_runtime_log_smoke.php ]]
                echo smoke >> "$CALLS"
            """,
        }
        for name, body in wrappers.items():
            path = self.bin / name
            path.write_text("#!/usr/bin/env bash\nset -euo pipefail\n" + textwrap.dedent(body))
            path.chmod(0o700)

    def options(self, before=BASE, planned=BASE, first="403", last="403", responses=None):
        env = dict(os.environ, PATH=str(self.bin) + ":" + os.environ["PATH"],
                   RUNNER_TEMP=str(self.root), DEPLOY_USER="local", DEPLOY_HOST="fixture",
                   PREVIOUS_MAIN_SHA=before, EXPECTED_CHECKOUT_SHA=planned, EXPECTED_SHA=self.successor,
                   HTTP_STATUS_SEQUENCE=" ".join(responses or [first, last]), CALLS=str(self.calls),
                   REAL_GIT=GIT, LOCAL_JOURNAL=str(self.journal))
        return dict(args=["bash", "-c", self.shell], cwd=self.root, env=env, text=True,
                    stdout=subprocess.PIPE, stderr=subprocess.PIPE)

    def check_run(self, succeeds, **kwargs):
        p = subprocess.run(**self.options(**kwargs), timeout=8)
        self.assertEqual(p.returncode == 0, succeeds, p.stdout + p.stderr)
        return self.calls.read_text().splitlines()

    def test_both_allowed_statuses_and_complete_git_sync(self):
        calls = self.check_run(True, first="404", last="403")
        self.assertEqual(calls[:3], ["http", "scp", "ssh"])
        self.assertEqual(calls[-1], "http")
        self.assertIn("smoke", calls)
        self.assertLess(calls.index("locked git rev-parse HEAD"),
                        next(i for i, c in enumerate(calls) if c.startswith("locked git fetch ")))
        self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), self.successor)
        self.assertEqual((self.checkout / "version").read_text(), "successor\n")

    def test_wrong_physical_head_stops_before_fetch_and_reset(self):
        checked_git(self.checkout, "reset", "--hard", self.original)
        calls = self.check_run(False)
        self.assertEqual(calls, ["http", "scp", "ssh", "locked git rev-parse HEAD"])
        self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), self.original)

    def test_mismatched_or_missing_plan_and_bad_http_stop_before_upload(self):
        for before, planned, first, last, expected in (
                (BASE, "", "403", "403", []), (BASE, self.original, "403", "403", []),
                (self.original, BASE, "403", "403", []), ("", BASE, "403", "403", []),
                (BASE, BASE, "200", "403", ["http"]), (BASE, BASE, "500", "403", ["http"]),
                (BASE, BASE, "timeout", "timeout", ["http", "http"])):
            with self.subTest(before=before, planned=planned, first=first):
                self.calls.write_text("")
                self.assertEqual(self.check_run(False, before=before, planned=planned, first=first, last=last), expected)
                self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), BASE)

    def test_dirty_worktree_and_index_still_stop_before_fetch(self):
        (self.checkout / "version").write_text("local change\n")
        for staged in (False, True):
            with self.subTest(staged=staged):
                if staged:
                    checked_git(self.checkout, "add", "version")
                self.calls.write_text("")
                calls = self.check_run(False)
                self.assertIn("locked git diff --quiet", calls)
                self.assertFalse(any(c.startswith("locked git fetch ") for c in calls))
                self.assertEqual((self.checkout / "version").read_text(), "local change\n")
                self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), BASE)

    def test_ordinary_full_sync_uses_only_its_existing_post_install_head(self):
        checked_git(self.checkout, "reset", "--hard", self.original)
        calls = self.check_run(True, before=self.original, planned="", first="404")
        self.assertEqual(calls[:2], ["scp", "ssh"])
        self.assertEqual(calls.count("http"), 1)
        self.assertEqual(calls[-1], "http")
        self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), self.successor)

    def test_both_transient_http_timeouts_recover_without_repeating_sync(self):
        self.pin_checkout(PR1702_MAIN)
        calls = self.check_run(True, before=PR1702_MAIN, planned=PR1702_MAIN,
                               responses=["timeout", "403", "timeout", "404"])
        self.assertEqual(calls[:4], ["http", "http", "scp", "ssh"])
        self.assertEqual(calls[-2:], ["http", "http"])
        self.assertEqual(calls.count("scp"), 1)
        self.assertEqual(calls.count("ssh"), 1)
        self.assertEqual(sum(c.startswith("locked git reset --hard ") for c in calls), 1)
        self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), self.successor)

    def test_pr1702_requires_public_containment_then_exact_physical_checkout_under_lock(self):
        self.pin_checkout(PR1702_MAIN)
        for status in ("200", "500", "timeout"):
            self.calls.write_text("")
            expected = ["http", "http"] if status == "timeout" else ["http"]
            self.assertEqual(self.check_run(False, before=PR1702_MAIN, planned=PR1702_MAIN,
                                            first=status, last=status), expected)
        self.calls.write_text("")
        self.assertEqual(self.check_run(False, before=PR1702_MAIN, planned=BASE), [])
        self.calls.write_text("")
        calls = self.check_run(True, before=PR1702_MAIN, planned=PR1702_MAIN)
        self.assertEqual(calls[:4], ["http", "scp", "ssh", "locked git rev-parse HEAD"])
        self.assertEqual(calls[-1], "http")
        self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), self.successor)
        self.calls.write_text("")
        self.assertEqual(self.check_run(False, before=PR1702_MAIN, planned=PR1702_MAIN),
                         ["http", "scp", "ssh", "locked git rev-parse HEAD"])
        self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), self.successor)

    def test_pr1704_requires_prior_checkout_and_never_retries_writes(self):
        current_main = "c3307bb811aefaa964c78f855b4d5e405fd606fb"
        for expected in ("", current_main, BASE):
            with self.subTest(expected=expected):
                self.assertEqual(self.check_run(False, before=current_main, planned=expected), [])
        self.pin_checkout(PR1702_MAIN)
        self.assertEqual(self.check_run(False, before=current_main, planned=PR1702_MAIN,
                                        responses=["timeout", "timeout"]), ["http", "http"])
        self.calls.write_text("")
        calls = self.check_run(True, before=current_main, planned=PR1702_MAIN,
                               responses=["timeout", "403", "404"])
        self.assertEqual(calls[:5], ["http", "http", "scp", "ssh", "locked git rev-parse HEAD"])
        self.assertEqual(calls.count("scp"), 1)
        self.assertEqual(calls.count("ssh"), 1)
        self.assertEqual(sum(c.startswith("locked git reset --hard ") for c in calls), 1)
        self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), self.successor)
        self.calls.write_text("")
        calls = self.check_run(False, before=current_main, planned=PR1702_MAIN)
        self.assertEqual(calls, ["http", "scp", "ssh", "locked git rev-parse HEAD"])
        self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), self.successor)

    def test_pr1721_requires_working_https_and_the_partial_checkout(self):
        self.pin_checkout(PR1721_MAIN)
        self.assertEqual(self.check_run(False, before=PR1721_MAIN, planned=""), [])
        self.assertEqual(self.check_run(False, before=PR1721_MAIN, planned=PR1721_MAIN,
                                        responses=["timeout", "timeout"]), ["http", "http"])
        self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), PR1721_MAIN)

        self.calls.write_text("")
        calls = self.check_run(True, before=PR1721_MAIN, planned=PR1721_MAIN)
        self.assertEqual(calls[:4], ["http", "scp", "ssh", "locked git rev-parse HEAD"])
        self.assertEqual(calls[-1], "http")
        self.assertEqual(sum(c.startswith("locked git reset --hard ") for c in calls), 1)
        self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), self.successor)

        self.calls.write_text("")
        self.assertEqual(self.check_run(False, before=PR1721_MAIN, planned=PR1721_MAIN),
                         ["http", "scp", "ssh", "locked git rev-parse HEAD"])
        self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), self.successor)

    def test_pr1692_sync_from_accepted_checkout(self):
        self.pin_checkout(PR1692_INSTALLED)
        calls = self.check_run(True, before=PR1692_MAIN, planned=PR1692_INSTALLED)
        self.assertEqual(calls[:3], ["scp", "ssh", "locked git rev-parse HEAD"])
        self.assertTrue(any(c.startswith("locked git fetch ") for c in calls))
        self.assertTrue(any(c.startswith("locked git reset --hard ") for c in calls))
        self.assertEqual(calls[-1], "http")
        self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), self.successor)
        self.assertEqual((self.checkout / "version").read_text(), "successor\n")

    def test_pr1692_rejects_changed_physical_checkout_under_lock(self):
        self.pin_checkout(PR1692_MAIN)
        calls = self.check_run(False, before=PR1692_MAIN, planned=PR1692_INSTALLED)
        self.assertEqual(calls, ["scp", "ssh", "locked git rev-parse HEAD"])
        self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), PR1692_MAIN)
        self.assertEqual((self.checkout / "version").read_text(), "original\n")

    def test_pr1692_rejects_mismatched_plan_before_upload(self):
        for before, planned in ((PR1692_MAIN, ""), (PR1692_MAIN, PR1692_MAIN),
                                (PR1692_MAIN, BASE), (BASE, PR1692_INSTALLED),
                                ("", PR1692_INSTALLED), (self.original, PR1692_INSTALLED)):
            with self.subTest(before=before, planned=planned):
                self.calls.write_text("")
                self.assertEqual(self.check_run(False, before=before, planned=planned), [])
                self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), BASE)

    def test_live_lock_blocks_work_until_prior_writer_releases_it(self):
        with self.journal.open("r") as prior_owner:
            fcntl.flock(prior_owner, fcntl.LOCK_EX)
            child = subprocess.Popen(**self.options())
            try:
                readable, _, _ = select.select([child.stdout], [], [], 6)
                self.assertTrue(readable, "runner did not reach the journal lock")
                self.assertTrue(child.stdout.readline().startswith("monitor_journal_lock=waiting"))
                self.assertEqual(self.calls.read_text().splitlines(), ["http", "scp", "ssh"])
                self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), BASE)
                fcntl.flock(prior_owner, fcntl.LOCK_UN)
                output, error = child.communicate(timeout=8)
                self.assertEqual(child.returncode, 0, output + error)
                self.assertEqual(checked_git(self.checkout, "rev-parse", "HEAD"), self.successor)
            finally:
                if child.poll() is None:
                    child.kill()
                    child.communicate()


if __name__ == "__main__":
    unittest.main(verbosity=2)
