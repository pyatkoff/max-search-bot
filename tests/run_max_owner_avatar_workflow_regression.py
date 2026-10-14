#!/usr/bin/env python3
"""Execute the actual manual probe shell gates with offline SSH/git/PHP doubles."""
import os
from pathlib import Path
import re
import subprocess
import tempfile
import textwrap


ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / ".github/workflows/max-owner-avatar-probe.yml").read_text()
OWNER = WORKFLOW.split("\n  owner-probe:\n", 1)[1]
SHA = "a" * 40
REMOTE_DIR = "cd /var/www/anytoour/data/www/app.anytoour.ru"
COMMAND = 'php tools/max_private_avatar_probe.php --expected-sha="$expected_sha" --confirm-owner-test'


def run_blocks(source):
    lines, blocks = source.splitlines(), []
    for i, line in enumerate(lines):
        match = re.fullmatch(r"( +)run: \|", line)
        if not match:
            continue
        body = []
        for next_line in lines[i + 1:]:
            if next_line.strip() and len(next_line) - len(next_line.lstrip()) <= len(match[1]):
                break
            body.append(next_line)
        blocks.append(textwrap.dedent("\n".join(body)))
    return blocks


blocks = run_blocks(OWNER)
validate, = [b for b in blocks if '"$WORKFLOW_SHA"' in b]
probe, = [b for b in blocks if "ssh -o BatchMode=yes" in b]
guard, = re.findall(r"^    if: (.+)$", OWNER, re.M)
assert guard == "github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main' && inputs.confirm_owner_test == true"
assert re.findall(r"^      ([a-z_]+):$", WORKFLOW.split("permissions:", 1)[0], re.M) == ["expected_sha", "confirm_owner_test"]
assert "needs: regression" in OWNER and "cancel-in-progress: false" in WORKFLOW
assert OWNER.index('"$WORKFLOW_SHA"') < OWNER.index("secrets.STANDBY_DEPLOY_SSH_KEY")
assert [line.strip() for line in re.findall(r"^\s*php .+$", probe, re.M)] == [COMMAND]
assert len(re.findall(r"^\s*ssh ", probe, re.M)) == 1 and probe.count(REMOTE_DIR) == 1
assert not re.search(r"\b(?:for|while|until|retry|retries)\b", probe)
assert "${{" not in probe and not re.search(r"https?://|--(?:url|user-id|conversation-id)", probe)

# These three required conjunctions exclude PR/push events, other refs and an
# omitted/false checkbox. The exact guard assertion above rejects any OR bypass.
for event, ref, confirmed, allowed in [
    ("pull_request", "refs/heads/main", True, False),
    ("push", "refs/heads/main", True, False),
    ("workflow_dispatch", "refs/heads/feature", True, False),
    ("workflow_dispatch", "refs/heads/main", False, False),
    ("workflow_dispatch", "refs/heads/main", True, True),
]:
    assert (event == "workflow_dispatch" and ref == "refs/heads/main" and confirmed is True) is allowed


def execute(expected=SHA, workflow=SHA, installed=SHA, unstaged=0, staged=0):
    with tempfile.TemporaryDirectory(prefix="max-owner-avatar-test-") as temp:
        directory = Path(temp)
        doubles = {
            "ssh": 'printf "%s\\n" "$*" >> "$SSH_LOG"\n/bin/bash -s -- "${@: -1}"\n',
            "git": 'case "$*" in\n"rev-parse HEAD") printf "%s\\n" "$INSTALLED_SHA";;\n"diff --quiet") exit "$UNSTAGED";;\n"diff --cached --quiet") exit "$STAGED";;\n*) exit 90;;\nesac\n',
            "php": 'printf "%s\\n" "$*" >> "$PHP_LOG"\n',
        }
        for name, body in doubles.items():
            executable = directory / name
            executable.write_text("#!/bin/bash\nset -euo pipefail\n" + body)
            executable.chmod(0o700)
        env = {**os.environ, "PATH": str(directory) + ":/usr/bin:/bin",
               "EXPECTED_SHA": expected, "WORKFLOW_SHA": workflow,
               "INSTALLED_SHA": installed, "UNSTAGED": str(unstaged), "STAGED": str(staged),
               "DEPLOY_HOST": "offline.invalid", "DEPLOY_USER": "fixture", "RUNNER_TEMP": temp,
               "PROBE_FIXTURE_CHECKOUT": temp, "SSH_LOG": str(directory / "ssh.log"),
               "PHP_LOG": str(directory / "php.log")}
        result = subprocess.run(["/bin/bash", "-c", validate], env=env, capture_output=True, timeout=5)
        if result.returncode == 0:
            # Only the working directory is mapped; all SHA/dirty gates and the
            # quoted SSH heredoc execute exactly as written in the workflow.
            result = subprocess.run(["/bin/bash", "-c", probe.replace(REMOTE_DIR, 'cd "$PROBE_FIXTURE_CHECKOUT"')],
                                    env=env, capture_output=True, timeout=5)
        logs = [(directory / name).read_text().splitlines() if (directory / name).exists() else []
                for name in ["ssh.log", "php.log"]]
        return result.returncode, *logs


for bad in ["", "a" * 39, "A" * 40, SHA + "\n", '$(touch /must-not-run)', "b" * 40]:
    code, ssh, php = execute(expected=bad)
    assert code != 0 and ssh == [] and php == [], repr(bad)
for kwargs in [{"installed": "b" * 40}, {"unstaged": 1}, {"staged": 1}]:
    code, ssh, php = execute(**kwargs)
    assert code != 0 and len(ssh) == 1 and php == [], kwargs
code, ssh, php = execute()
assert code == 0 and len(ssh) == 1
assert php == [f"tools/max_private_avatar_probe.php --expected-sha={SHA} --confirm-owner-test"]
print("MAX OWNER AVATAR WORKFLOW: event/ref/confirmation gates and 10 offline shell scenarios PASSED")
