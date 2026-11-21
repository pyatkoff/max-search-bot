#!/usr/bin/env python3
"""Verify this new sidecar's frozen source; no PHP, network or runtime access."""
import argparse
import hashlib
import json
from pathlib import Path

DEPENDENCIES = {
    "Max2ValueLookupProtocolV2.php": "04100d16541e5d1c961845ad59a5fb9b11840361630c4bf3b83a80c360bc2fe2",
    "Max2ValueLookupRepositoryV2.php": "40ab600c7b8e619da212d0a10942046fb36a37ab13ba661f6019d40b3bd9fac9",
}
CANDIDATES = {"lookup.php", "services/Max2ValueLookupHttpV2.php", "services/Max2ValueLookupRuntimeV2.php",
              "services/Max2ValueLookupProtocolV2.php", "services/Max2ValueLookupRepositoryV2.php"}


def need(value, message):
    if not value:
        raise ValueError(message)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--lookup-root", type=Path, help="Optionally compare exact immutable upstream source copies")
    args = parser.parse_args()
    root = Path(__file__).resolve().parent
    pins = json.loads((root / "source-pins.json").read_bytes())
    need(pins["schema_version"] == 1 and pins["installed"] is False, "source_state_changed")
    expected_pins = {"candidate/" + name for name in CANDIDATES} | {"README.md", "verify.py", "tests/sidecar.php"}
    need(type(pins.get("files")) is dict and set(pins["files"]) == expected_pins, "source_pin_inventory_changed")
    found = {str(p.relative_to(root / "candidate")) for p in (root / "candidate").rglob("*") if p.is_file()}
    need(found == CANDIDATES, "candidate_inventory_changed")
    for name, expected in pins["files"].items():
        need(type(expected) is dict and set(expected) == {"bytes", "sha256"}, "source_pin_shape_changed")
        path = root / name
        need(path.is_file() and not path.is_symlink(), "source_missing_or_symlink")
        raw = path.read_bytes()
        need(len(raw) == expected["bytes"] and hashlib.sha256(raw).hexdigest() == expected["sha256"], "source_pin_changed:" + name)
    for name, digest in DEPENDENCIES.items():
        raw = (root / "candidate/services" / name).read_bytes()
        need(hashlib.sha256(raw).hexdigest() == digest, "reviewed_lookup_dependency_changed")
        if args.lookup_root is not None:
            need((args.lookup_root / "services" / name).read_bytes() == raw, "lookup_copy_not_exact")
    need(pins["endpoint"] == "https://tour-max.ru/new/max2-value-attribution/lookup.php", "endpoint_changed")
    print(json.dumps({"status": "SOURCE_VERIFIED", "candidate_files": 5,
                      "reviewed_dependency_copies": 2, "php_executed": False,
                      "native_access": False, "installed": False}))


if __name__ == "__main__":
    main()
