#!/usr/bin/env python3
"""Verify one immutable Actions ZIP before extracting bounded visual evidence."""
import hashlib
import os
from pathlib import Path, PurePosixPath
import shutil
import stat
import sys
import tempfile
import zipfile


def extract_verified(archive, destination, expected_digest):
    archive, destination = Path(archive), Path(destination)
    actual = "sha256:" + hashlib.sha256(archive.read_bytes()).hexdigest()
    if actual != expected_digest:
        raise ValueError("Donor archive digest does not match immutable API metadata")
    if destination.exists():
        raise ValueError("Donor namespace must be new")
    if archive.stat().st_size > 160 * 1024 * 1024:
        raise ValueError("Visual archive is too large")
    temporary = Path(tempfile.mkdtemp(prefix="manager2-donor-", dir=destination.parent))
    try:
        with zipfile.ZipFile(archive) as zipped:
            entries = zipped.infolist()
            if not entries or len(entries) > 6000 or sum(e.file_size for e in entries) > 350 * 1024 * 1024:
                raise ValueError("Unbounded visual archive")
            seen = set()
            for entry in entries:
                name = entry.filename.rstrip("/")
                parts = PurePosixPath(name).parts
                mode = entry.external_attr >> 16
                if (not name or name in seen or name.startswith("/") or "\\" in name
                        or any(p in (".", "..") or ":" in p for p in parts)
                        or stat.S_ISLNK(mode) or (stat.S_IFMT(mode) not in (0, stat.S_IFREG, stat.S_IFDIR))
                        or entry.file_size > 32 * 1024 * 1024 or entry.flag_bits & 1):
                    raise ValueError("Unsafe or duplicate visual archive path")
                seen.add(name)
                target = temporary.joinpath(*parts)
                if entry.is_dir():
                    target.mkdir(parents=True, exist_ok=True)
                else:
                    target.parent.mkdir(parents=True, exist_ok=True)
                    with zipped.open(entry) as source, target.open("xb") as output:
                        shutil.copyfileobj(source, output)
        os.rename(temporary, destination)
    finally:
        if temporary.exists():
            shutil.rmtree(temporary)


if __name__ == "__main__":
    try:
        extract_verified(*sys.argv[1:])
    except Exception as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
