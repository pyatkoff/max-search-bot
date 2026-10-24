"""Reproducible, explicit-source MV3 installation package; no runtime data."""
from pathlib import Path
import sys
import zipfile

ROOT = Path(__file__).resolve().parent
FILES = ("manifest.json", "background.js", "bridge.js", "funsun.js", "content.js", "anex.js", "anex-content.js",
         "content.css", "popup.html", "popup.js", "popup.css", "README.md")
TARGET = ROOT / "anytour-selection-collector.zip"

if sys.argv[1:] == ["--check"]:
    with zipfile.ZipFile(TARGET) as archive:
        assert archive.namelist() == list(FILES), "Unexpected package entry"
        assert archive.testzip() is None, "Package CRC failure"
        for name in FILES:
            assert archive.read(name) == (ROOT / name).read_bytes(), f"Stale package: {name}"
    print("PASS collector package matches exact source files")
elif not sys.argv[1:]:
    with zipfile.ZipFile(TARGET, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name in FILES:
            info = zipfile.ZipInfo(name, (2026, 10, 23, 0, 0, 0))
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, (ROOT / name).read_bytes(), compresslevel=9)
    print("Built collector installation package")
else:
    raise SystemExit("Usage: build_package.py [--check]")
