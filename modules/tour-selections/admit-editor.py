"""Trusted source admission for an editor artifact; never deploy or execute candidate code.

Run this helper from the installed backend, not from an uploaded candidate.
The publisher supplies approved immutable source/backend SHAs, expected current
package and unique operation identity. Those are authority inputs, not inferred
from the artifact's self-reported receipt. Installation must independently CAS
that expected current package and retain the operation durably before writes.
"""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import urllib.request
import sys

ROOT = Path(__file__).resolve().parents[2]
EDITABLE = frozenset({
    "manager/assets/workspace-v2-tour-selection.js",
    "manager/assets/workspace-v2-tour-selection.css",
    "modules/tour-selections/editor.json",
    "modules/tour-selections/editor-preview/index.html",
    "modules/tour-selections/editor-preview/preview.css",
    "modules/tour-selections/editor-preview/preview-host.js",
    "tests/visual/manager-tour-selection.spec.js",
})


def git_command(root, *args):
    return ["git", "-c", "core.fsmonitor=false", "-c", "core.hooksPath=/dev/null", "-C", str(root), *args]


def git_environment():
    env = {key: value for key, value in os.environ.items() if not key.startswith("GIT_")}
    return dict(env, GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL="/dev/null", GIT_OPTIONAL_LOCKS="0")


def git(root, *args):
    return subprocess.check_output(git_command(root, *args), env=git_environment(), stderr=subprocess.PIPE).decode().strip()


def strict_json(data):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("duplicate_editor_json_key")
            result[key] = value
        return result
    return json.loads(data, object_pairs_hook=unique)


def repository(root, expected, *, runtime=False):
    if not re.fullmatch(r"[a-f0-9]{40}", expected):
        raise ValueError("invalid_editor_commit")
    root = Path(root)
    if root.is_symlink() or root.resolve() != root or not root.is_dir():
        raise ValueError("invalid_editor_source_root")
    if Path(git(root, "rev-parse", "--show-toplevel")) != root:
        raise ValueError("invalid_editor_source_root")
    if git(root, "rev-parse", "HEAD") != expected:
        raise ValueError("editor_commit_mismatch")
    if git(root, "status", "--porcelain", "--untracked-files=no" if runtime else "--untracked-files=all", "--ignore-submodules=all"):
        raise ValueError("dirty_editor_source")
    return root


def package_module(backend):
    # Only the installed publisher's trusted implementation is imported.
    loader = importlib.util.spec_from_file_location("trusted_editor_builder", backend / "modules/tour-selections/build-editor.py")
    module = importlib.util.module_from_spec(loader)
    old = sys.dont_write_bytecode
    try:
        sys.dont_write_bytecode = True
        loader.loader.exec_module(module)
    finally:
        sys.dont_write_bytecode = old
    return module


REQUIRED_WORKFLOWS = frozenset({
    '.github/workflows/regression-tests.yml', '.github/workflows/retired-domain-guard.yml',
    '.github/workflows/workspace-v2-visual-qa.yml', '.github/workflows/tour-selection-module.yml',
    '.github/workflows/tour-selection-editor-module.yml',
})


def verify_ci(pr_number, source_sha, backend_sha, changed_paths, read):
    required = REQUIRED_WORKFLOWS | ({'.github/workflows/workspace-sound.yml', '.github/workflows/channel-post-context.yml'} if any(p.startswith('manager/') for p in changed_paths) else set())
    if not isinstance(pr_number, int) or pr_number < 1:
        raise ValueError('invalid_editor_pr')
    pr = read('pulls/' + str(pr_number))
    if (pr.get('state') != 'open' or pr.get('draft') is not False
            or pr.get('head', {}).get('sha') != source_sha or pr.get('base', {}).get('sha') != backend_sha
            or pr.get('head', {}).get('repo', {}).get('full_name') != 'pyatkoff/max-search-bot'
            or pr.get('base', {}).get('ref') != 'main'):
        raise ValueError('editor_pr_not_current')
    if read('commits/main').get('sha') != backend_sha:
        raise ValueError('editor_main_changed')
    runs = read('actions/runs?head_sha=' + source_sha + '&event=pull_request&per_page=100')
    if runs.get('total_count', 101) > 100 or len(runs.get('workflow_runs', [])) > 100:
        raise ValueError('editor_ci_truncated')
    latest = {}
    for run in runs.get('workflow_runs', []):
        path = run.get('path', '').split('@', 1)[0]
        if path not in required:
            continue
        if run.get('id', 0) > latest.get(path, {}).get('id', 0):
            latest[path] = run
    if set(latest) != required:
        raise ValueError('editor_required_ci_missing')
    for run in latest.values():
        binding = [p for p in run.get('pull_requests', []) if p.get('number') == pr_number]
        if (run.get('status') != 'completed' or run.get('conclusion') != 'success'
                or run.get('head_sha') != source_sha or run.get('event') != 'pull_request'
                or run.get('head_repository', {}).get('full_name') != 'pyatkoff/max-search-bot'
                or len(binding) != 1 or binding[0].get('base', {}).get('sha') != backend_sha
                or binding[0].get('head', {}).get('sha') != source_sha):
            raise ValueError('editor_required_ci_not_current_success')
    return {path: latest[path]['id'] for path in sorted(latest)}


def github_read(path):
    token = os.environ.get('GH_TOKEN', '')
    if not token:
        raise ValueError('editor_ci_credential_missing')
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            raise ValueError('editor_ci_redirect_refused')
    url = 'https://api.github.com/repos/pyatkoff/max-search-bot/' + path
    request = urllib.request.Request(url, headers={'Authorization': 'Bearer ' + token, 'Accept': 'application/vnd.github+json', 'X-GitHub-Api-Version': '2022-11-28'})
    with urllib.request.build_opener(NoRedirect).open(request, timeout=20) as response:
        data = response.read(2 * 1024 * 1024 + 1)
    if len(data) > 2 * 1024 * 1024:
        raise ValueError('editor_ci_response_too_large')
    return strict_json(data)


def admit(backend, candidate, output, backend_sha, source_sha, current_package, operation):
    if not re.fullmatch(r"[a-f0-9]{64}", current_package):
        raise ValueError("invalid_editor_current_package")
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_-]{7,95}", operation):
        raise ValueError("invalid_editor_operation")
    backend = repository(backend, backend_sha, runtime=True)
    candidate = repository(candidate, source_sha)
    # Objects must exist in the candidate repository. No network fetch, moving
    # branch lookup, candidate scripts, shell interpolation or credentials here.
    if git(candidate, "rev-parse", backend_sha + "^{tree}") != git(backend, "rev-parse", backend_sha + "^{tree}"):
        raise ValueError("editor_backend_tree_mismatch")
    if subprocess.run(git_command(candidate, "merge-base", "--is-ancestor", backend_sha, source_sha), env=git_environment(), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode:
        raise ValueError("editor_backend_not_ancestor")
    changes = git(candidate, "diff", "--no-ext-diff", "--no-textconv", "--ignore-submodules=all", "--name-status", "--no-renames", backend_sha, source_sha, "--").splitlines()
    if not changes:
        raise ValueError("editor_component_unchanged")
    for row in changes:
        status, path = row.split("\t", 1)
        if status != "M" or path not in EDITABLE:
            raise ValueError("editor_shared_change")
        for commit in (backend_sha, source_sha):
            if not git(candidate, "ls-tree", commit, "--", path).startswith("100644 blob "):
                raise ValueError("editor_component_mode")
    old = strict_json((backend / "modules/tour-selections/editor.json").read_bytes())
    new = strict_json((candidate / "modules/tour-selections/editor.json").read_bytes())
    old_version, new_version = old.pop("version"), new.pop("version")
    if old != new:
        raise ValueError("editor_manifest_contract_changed")
    if not all(isinstance(v, str) and re.fullmatch(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)", v) for v in (old_version, new_version)):
        raise ValueError("invalid_editor_version")
    if tuple(map(int, new_version.split("."))) <= tuple(map(int, old_version.split("."))):
        raise ValueError("editor_version_not_newer")
    pack = package_module(backend)
    # verify() checks exact source bytes, collector ZIP members, preview,
    # compatibility, payload set, archive members and byte sizes/hashes.
    pack.verify(candidate, output)
    receipt = strict_json((output / "receipt.json").read_bytes())
    if receipt.get("release_eligible") is not True or receipt.get("source_commit") != source_sha or receipt.get("source_tree") != git(candidate, "rev-parse", "HEAD^{tree}"):
        raise ValueError("editor_artifact_provenance_mismatch")
    _, _, _, backend_inputs, _ = pack.inputs(backend)
    expected_fingerprint = pack.digest(pack.encoded({"schema": 1, "contract": old["contract"], "backend_inputs": backend_inputs}))
    if receipt["backend_fingerprint"] != expected_fingerprint:
        raise ValueError("editor_installed_backend_changed")
    return {"schema": 1, "kind": "selection-editor-source-admission-v1", "backend_sha": backend_sha,
            "backend_fingerprint": expected_fingerprint, "source_sha": source_sha, "source_tree": receipt["source_tree"],
            "package_id": receipt["package_id"], "archive_sha256": receipt["archive_sha256"],
            "expected_current_package": current_package, "operation": operation,
            "version": new_version, "changed_paths": sorted(row.split("\t", 1)[1] for row in changes)}


if __name__ == "__main__":
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--backend", type=Path, required=True)
    p.add_argument("--candidate", type=Path, required=True)
    p.add_argument("--output", type=Path, required=True)
    for name in ("backend-sha", "source-sha", "current-package", "operation"):
        p.add_argument("--" + name, required=True)
    p.add_argument("--pull-request", type=int)
    a = p.parse_args()
    try:
        result = admit(a.backend, a.candidate, a.output, a.backend_sha, a.source_sha, a.current_package, a.operation)
        if a.pull_request is not None:
            result['required_ci'] = verify_ci(a.pull_request, a.source_sha, a.backend_sha, result['changed_paths'], github_read)
        print(json.dumps(result, sort_keys=True))
    except (ValueError, OSError, KeyError, TypeError, subprocess.CalledProcessError) as error:
        p.exit(1, "editor_admission_error: " + str(error) + "\n")
