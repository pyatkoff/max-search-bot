#!/usr/bin/env bash
# Run only the #1566 publisher's existing SSH transport and exact staged editor.
set -euo pipefail
umask 077

pin_host() {
  python3 - <<'PY'
import os,re
assert re.fullmatch(r'[A-Za-z0-9_.:-]+',os.environ['HOST_VALUE'] or '')
assert re.fullmatch(r'[A-Za-z0-9_-]+',os.environ['USER_VALUE'] or '')
PY
if [[ "$KEY_VALUE" == *"PRIVATE KEY"* ]]; then
  if [[ "$KEY_VALUE" == *'\n'* ]]; then printf '%b\n' "$KEY_VALUE" | tr -d '\r' > "$RUNNER_TEMP/channel_key"; else printf '%s\n' "$KEY_VALUE" | tr -d '\r' > "$RUNNER_TEMP/channel_key"; fi
else printf '%s' "$KEY_VALUE" | base64 --decode > "$RUNNER_TEMP/channel_key"; fi
chmod 600 "$RUNNER_TEMP/channel_key"
ssh-keygen -y -f "$RUNNER_TEMP/channel_key" >/dev/null
python3 - <<'PY'
import base64,hashlib,os,pathlib,subprocess
scan=subprocess.run(['ssh-keyscan','-T','10','-t','ed25519,ecdsa,rsa',os.environ['HOST_VALUE']],capture_output=True,text=True,timeout=35);found=[]
for s in scan.stdout.splitlines():
    q=s.split()
    if len(q)<3 or q[0].startswith('#'):continue
    try:fp='SHA256:'+base64.b64encode(hashlib.sha256(base64.b64decode(q[2])).digest()).decode().rstrip('=')
    except Exception:continue
    if fp=='SHA256:/5mPWmGBQ6UPnNQDEHLOCy7dCpxdz+FxQ4zVOjqE0F0':found.append(s)
assert found,'HOST_PIN_MISMATCH_STOP'
pathlib.Path(os.environ['RUNNER_TEMP']+'/channel_known_hosts').write_text('\n'.join(found)+'\n')
print('ESTABLISHED_HOST_PIN_VERIFIED')
PY
}

if [[ "$#" == 1 && "$1" == pin-host ]]; then
  pin_host
  exit 0
fi

test "$#" = 2
phase="$1"
seal="$2"
if [[ "$phase" == prepare ]]; then
  test "$seal" = -
elif [[ "$phase" == apply ]]; then
  [[ "$seal" =~ ^[a-f0-9]{64}$ ]]
else
  exit 2
fi

options=(-i "$RUNNER_TEMP/channel_key" -o BatchMode=yes -o ConnectTimeout=10
         -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o GlobalKnownHostsFile=/dev/null
         -o UserKnownHostsFile="$RUNNER_TEMP/channel_known_hosts")
ssh "${options[@]}" "$USER_VALUE@$HOST_VALUE" 'bash -c '"'"'
  set -euo pipefail
  umask 077
  stage=$(mktemp -d /tmp/anytour-1566-old-max.XXXXXXXX)
  trap "rm -f -- \"$stage\"/*; rmdir -- \"$stage\"" EXIT
  tar --no-same-owner --no-same-permissions -C "$stage" -xf -
  bash "$stage/execute.sh" "$stage" "$1" "$2"
'"'"' -- '"$phase $seal"
