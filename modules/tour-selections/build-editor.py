"""Exact canonical editor + existing MV3 artifact; no deployment or credentials."""
import argparse
import ast
import hashlib
import io
import json
from pathlib import Path
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[2]
MODULE = "modules/tour-selections/editor.json"
SCRIPT = "modules/tour-selections/build-editor.py"
PREVIEW = "modules/tour-selections/editor-preview"
FILES = {"editor.js": "manager/assets/workspace-v2-tour-selection.js",
         "editor.css": "manager/assets/workspace-v2-tour-selection.css",
         "manager2-host.js": "manager/assets/workspace-v2-tour-selection-host.js",
         "collector.zip": "extensions/selection-collector/anytour-selection-collector.zip",
         "private-host.js": "manager/assets/tour-selection-editor-host.js",
         "private-host.css": "manager/assets/tour-selection-editor-host.css",
         "http.js": "manager/assets/manager-http-client.js",
         "time.js": "manager/assets/manager-time.js",
         "app.php": "modules/tour-selections/editor-app.php",
         "tours.php": "modules/tour-selections/editor-tours.php"}
DEMO_FILES = ("index.html", "preview.css", "preview-host.js")


def encoded(value):
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n").encode()


def digest(data):
    return hashlib.sha256(data).hexdigest()


def source(root, name):
    path = Path(name)
    if path.is_absolute() or ".." in path.parts or "\\" in name:
        raise ValueError("invalid_input_path")
    target = root / path
    if target.is_symlink() or not target.is_file() or not target.resolve().is_relative_to(root.resolve()):
        raise ValueError("invalid_input_file")
    return target.read_bytes()


def git(root, *args):
    return subprocess.check_output(["git", "-C", str(root), *args], stderr=subprocess.PIPE).decode().strip()


def inputs(root):
    spec = json.loads(source(root, MODULE))
    if (set(spec) != {"schema", "name", "version", "contract", "files", "backend_inputs"}
            or spec["schema"] != 1 or spec["name"] != "anytour-selection-editor"
            or spec["contract"] != "tour-selection-editor-host-v1"
            or not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", spec["version"])
            or spec["files"] != FILES or not spec["backend_inputs"]
            or len(spec["backend_inputs"]) != len(set(spec["backend_inputs"]))):
        raise ValueError("invalid_editor_manifest")
    payload = {name: source(root, path) for name, path in FILES.items()}
    # Verify the existing collector against its own explicit canonical file list,
    # without importing/executing its top-level packager or rebuilding its ZIP.
    packager = "extensions/selection-collector/build_package.py"
    tree = ast.parse(source(root, packager))
    names = next(ast.literal_eval(n.value) for n in tree.body if isinstance(n, ast.Assign)
                 and any(isinstance(t, ast.Name) and t.id == "FILES" for t in n.targets))
    collector_inputs = [packager]
    with zipfile.ZipFile(io.BytesIO(payload["collector.zip"])) as archive:
        if archive.namelist() != list(names) or archive.testzip() is not None:
            raise ValueError("invalid_collector_archive")
        for name in names:
            rel = "extensions/selection-collector/" + name
            if archive.read(name) != source(root, rel):
                raise ValueError("stale_collector_archive")
            collector_inputs.append(rel)
    backend = {path: digest(source(root, path)) for path in spec["backend_inputs"]}
    release = {"schema": 1, "name": spec["name"], "version": spec["version"],
               "contract": spec["contract"], "files": {
                   name: {"sha256": digest(data), "bytes": len(data)} for name, data in payload.items()}}
    release["package_id"] = digest(encoded(release))
    demo = {name: source(root, PREVIEW + "/" + name) for name in DEMO_FILES}
    tracked = [MODULE, SCRIPT, *FILES.values(), *spec["backend_inputs"],
               *collector_inputs, *(PREVIEW + "/" + name for name in DEMO_FILES)]
    return payload, demo, release, backend, tracked


def build(root, output, working_tree=False):
    payload, demo, release, backend, tracked = inputs(root)
    dirty = bool(git(root, "status", "--porcelain", "--untracked-files=all", "--", *tracked))
    if dirty and not working_tree:
        raise ValueError("uncommitted_editor_inputs")
    if output.exists() and any(output.iterdir()):
        raise ValueError("output_not_empty")
    output.mkdir(parents=True, exist_ok=True)
    target = output / "payload"
    target.mkdir()
    for name, data in {**payload, "release.json": encoded(release)}.items():
        (target / name).write_bytes(data)
    archive = output / "selection-editor.zip"
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_STORED) as z:
        for name in sorted([*payload, "release.json"]):
            info = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            z.writestr(info, (target / name).read_bytes())
    compatibility = {"schema": 1, "contract": release["contract"], "backend_inputs": backend}
    (output / "compatibility.json").write_bytes(encoded(compatibility))
    preview = output / "preview"
    preview.mkdir()
    for name, data in demo.items():
        (preview / name).write_bytes(data)
    receipt = {"schema": 1, "package_id": release["package_id"],
               "archive_sha256": digest(archive.read_bytes()),
               "source_commit": git(root, "rev-parse", "HEAD"),
               "source_tree": git(root, "rev-parse", "HEAD^{tree}"),
               "release_eligible": not dirty, "backend_fingerprint": digest(encoded(compatibility)),
               "preview_inputs": {name: digest(data) for name, data in demo.items()}}
    (output / "receipt.json").write_bytes(encoded(receipt))
    return receipt


def verify(root, output):
    payload, demo, release, backend, _ = inputs(root)
    receipt = json.loads((output / "receipt.json").read_bytes())
    compatibility = {"schema": 1, "contract": release["contract"], "backend_inputs": backend}
    if (json.loads((output / "compatibility.json").read_bytes()) != compatibility
            or receipt["backend_fingerprint"] != digest(encoded(compatibility))):
        raise ValueError("editor_backend_contract_changed")
    archive = (output / "selection-editor.zip").read_bytes()
    if receipt["archive_sha256"] != digest(archive) or receipt["package_id"] != release["package_id"]:
        raise ValueError("editor_package_identity_mismatch")
    expected = {**payload, "release.json": encoded(release)}
    if set(p.name for p in (output / "payload").iterdir()) != set(expected):
        raise ValueError("unexpected_editor_payload")
    with zipfile.ZipFile(io.BytesIO(archive)) as z:
        if sorted(z.namelist()) != sorted(expected) or len(z.namelist()) != len(expected):
            raise ValueError("unexpected_editor_members")
        for info in z.infolist():
            if info.external_attr >> 16 != 0o100644 or info.file_size > 1048576:
                raise ValueError("invalid_editor_member")
        for name, data in expected.items():
            if z.read(name) != data or source(output / "payload", name) != data:
                raise ValueError("editor_package_bytes_mismatch")
    if receipt["preview_inputs"] != {name: digest(data) for name, data in demo.items()}:
        raise ValueError("editor_preview_identity_mismatch")
    if set(p.name for p in (output / "preview").iterdir()) != set(demo):
        raise ValueError("unexpected_editor_preview")
    for name, data in demo.items():
        if source(output / "preview", name) != data:
            raise ValueError("editor_preview_bytes_mismatch")
    return {"ok": True, "package_id": release["package_id"], "backend_compatible": True,
            "release_eligible": receipt["release_eligible"], "source_commit": receipt["source_commit"]}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("build", "verify"))
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--working-tree", action="store_true", help="Development only; not release eligible")
    args = parser.parse_args()
    try:
        result = build(ROOT, args.output, args.working_tree) if args.action == "build" else verify(ROOT, args.output)
        print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    except (ValueError, OSError, KeyError, StopIteration, SyntaxError, zipfile.BadZipFile, subprocess.CalledProcessError) as e:
        parser.exit(1, f"selection_editor_error: {type(e).__name__}: {e}\n")
