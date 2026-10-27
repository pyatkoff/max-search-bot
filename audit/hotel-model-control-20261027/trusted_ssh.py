from __future__ import annotations
import base64,hashlib,json,socket,subprocess
from pathlib import Path
APP_ROOT="/var/www/anytoour/data/www/app.anytoour.ru"
PIN="SHA256:/5mPWmGBQ6UPnNQDEHLOCy7dCpxdz+FxQ4zVOjqE0F0"
MAX_ENVELOPE=1048576

def require(ok: bool, code: str) -> None:
    if not ok:
        raise RuntimeError(code)

def sha(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()

def enc(value: object) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))

def trusted_hosts(host: str, temp: Path) -> Path:
    require({v[4][0] for v in socket.getaddrinfo(host, 22, type=socket.SOCK_STREAM)} == {"5.45.122.54"}, "canonical_target_changed")
    pinned: list[str] = []
    for _ in range(2):
        scan = subprocess.run(["ssh-keyscan", "-T", "10", host], capture_output=True, text=True, timeout=30)
        for line in scan.stdout.splitlines():
            parts = line.split()
            if len(parts) != 3 or parts[0].startswith("#"):
                continue
            try:
                fp = "SHA256:" + base64.b64encode(hashlib.sha256(base64.b64decode(parts[2], validate=True)).digest()).decode().rstrip("=")
            except ValueError:
                continue
            if fp == PIN:
                pinned.append(line)
        if pinned:
            break
    require(bool(pinned), "canonical_host_pin_not_seen")
    path = temp / "hotel_model_known_hosts"
    path.write_text("\n".join(pinned) + "\n")
    path.chmod(0o600)
    return path
