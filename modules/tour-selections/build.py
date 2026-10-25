#!/usr/bin/env python3
"""Build the public presentation only; no bootstrap, database or deployment."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[2]
MODULE = "modules/tour-selections/module.json"
FILES = {"view.php": "selection/view.php", "selection.css": "selection/selection.css",
         "activity.js": "selection/activity.js"}


def encoded(value):
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n").encode()


def digest(data):
    return hashlib.sha256(data).hexdigest()


def source(root, path):
    p = Path(path)
    if p.is_absolute() or ".." in p.parts or "\\" in path:
        raise ValueError("invalid_input_path")
    candidate = root / p
    if candidate.is_symlink() or not candidate.is_file() or not candidate.resolve().is_relative_to(root.resolve()):
        raise ValueError("invalid_input_file")
    return candidate.read_bytes()


def inputs(root):
    spec = json.loads(source(root, MODULE))
    if (set(spec) != {"schema", "name", "version", "contract", "files", "backend_inputs"}
            or spec["schema"] != 1 or spec["name"] != "anytour-selection-public"
            or spec["contract"] != "selection-php-view-v1" or spec["files"] != FILES
            or not isinstance(spec["backend_inputs"], list) or not spec["backend_inputs"]
            or len(spec["backend_inputs"]) != len(set(spec["backend_inputs"]))):
        raise ValueError("invalid_module_manifest")
    payload = {name: source(root, path) for name, path in FILES.items()}
    backend = {p: digest(source(root, p)) for p in spec["backend_inputs"]}
    public = {"schema": 1, "name": spec["name"], "version": spec["version"],
              "contract": spec["contract"], "files": {
                  name: {"sha256": digest(data), "bytes": len(data)} for name, data in payload.items()}}
    public["package_id"] = digest(encoded(public))
    return spec, payload, backend, public


def git(root, *args):
    return subprocess.check_output(["git", "-C", str(root), *args], stderr=subprocess.PIPE).decode().strip()


def build(root, output, working_tree=False, preview=True):
    spec, payload, backend, public = inputs(root)
    tracked = [MODULE, *FILES.values(), *spec["backend_inputs"],
               "modules/tour-selections/build.py", "modules/tour-selections/preview.php"]
    dirty = bool(git(root, "status", "--porcelain", "--untracked-files=all", "--", *tracked))
    if dirty and not working_tree:
        raise ValueError("uncommitted_module_inputs")
    if output.exists() and any(output.iterdir()):
        raise ValueError("output_not_empty")
    output.mkdir(parents=True, exist_ok=True)
    package = output / "payload"
    package.mkdir()
    for name, data in payload.items():
        (package / name).write_bytes(data)
    (package / "release.json").write_bytes(encoded(public))
    archive = output / "selection-public.zip"
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_STORED) as z:
        for name in sorted([*payload, "release.json"]):
            info = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            z.writestr(info, (package / name).read_bytes())
    compatibility = {"schema": 1, "contract": spec["contract"], "backend_inputs": backend}
    (output / "compatibility.json").write_bytes(encoded(compatibility))
    receipt = {"schema": 1, "package_id": public["package_id"], "archive_sha256": digest(archive.read_bytes()),
               "source_commit": git(root, "rev-parse", "HEAD"),
               "source_tree": git(root, "rev-parse", "HEAD^{tree}"),
               "release_eligible": not dirty, "backend_fingerprint": digest(encoded(compatibility))}
    (output / "receipt.json").write_bytes(encoded(receipt))
    if preview:
        target = output / "preview"
        target.mkdir()
        for name in ("selection.css", "activity.js"):
            (target / name).write_bytes(payload[name])
        for i, color in enumerate(("#d7e9f1", "#e8dfc8", "#dce9de"), 1):
            (target / f"demo-{i}.svg").write_text(
                f'<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"><rect width="800" height="600" fill="{color}"/>'
                f'<text x="80" y="300" font-size="32">AnyTour · DEMO {i}</text></svg>', encoding="utf-8")
        for state, name in (("available", "index.html"), ("unavailable", "unavailable.html")):
            data = subprocess.check_output(["php", str(root / "modules/tour-selections/preview.php"),
                                            str(package / "view.php"), state], stderr=subprocess.PIPE)
            (target / name).write_bytes(data)
        (target / "demo-contact.html").write_text(
            '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            '<title>Демонстрация AnyTour</title><h1>Тестовая подборка</h1><p>Сообщения не отправляются. '
            'В рабочей подборке эта кнопка открывает ваш диалог с ботом.</p><a href="index.html">Вернуться</a></html>', encoding="utf-8")
    return receipt


def verify(root, output):
    spec, payload, backend, expected = inputs(root)
    receipt = json.loads((output / "receipt.json").read_bytes())
    compatibility = json.loads((output / "compatibility.json").read_bytes())
    wanted = {"schema": 1, "contract": spec["contract"], "backend_inputs": backend}
    if compatibility != wanted or receipt["backend_fingerprint"] != digest(encoded(wanted)):
        raise ValueError("backend_contract_changed")
    archive = (output / "selection-public.zip").read_bytes()
    if receipt["archive_sha256"] != digest(archive) or receipt["package_id"] != expected["package_id"]:
        raise ValueError("package_identity_mismatch")
    with zipfile.ZipFile(output / "selection-public.zip") as z:
        names = [i.filename for i in z.infolist()]
        if sorted(names) != sorted([*FILES, "release.json"]):
            raise ValueError("unexpected_archive_members")
        for info in z.infolist():
            if info.external_attr >> 16 != 0o100644 or info.file_size > 1048576:
                raise ValueError("invalid_archive_member")
        for name, data in {**payload, "release.json": encoded(expected)}.items():
            if z.read(name) != data or source(output / "payload", name) != data:
                raise ValueError("package_bytes_mismatch")
    return {"ok": True, "package_id": expected["package_id"], "backend_compatible": True,
            "release_eligible": receipt["release_eligible"], "source_commit": receipt["source_commit"]}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("build", "verify"))
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--working-tree", action="store_true", help="Development build; never a release receipt")
    args = parser.parse_args()
    try:
        result = build(ROOT, args.output, args.working_tree) if args.action == "build" else verify(ROOT, args.output)
        print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    except (ValueError, OSError, KeyError, zipfile.BadZipFile, subprocess.CalledProcessError) as e:
        parser.exit(1, f"selection_module_error: {type(e).__name__}: {e}\n")
