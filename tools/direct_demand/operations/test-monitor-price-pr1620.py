"""Portable exact two-pin compatibility proof; no network or production writes."""
from pathlib import Path
import base64,hashlib,json,re,subprocess,tempfile
P=Path(__file__).parent
proof=json.loads((P/'monitor-price-pr1620-baseline-proof.json').read_text())
sha=lambda b:hashlib.sha256(b).hexdigest()
def const(s,n):return re.search(rb'const '+n.encode()+rb'="([^"]+)"',s)[1]
with tempfile.TemporaryDirectory() as t:
 target=Path(t)/'hook.php';subprocess.run(['python3',str(P/'build-monitor-price-hook.py'),str(target)],check=True,capture_output=True);s=target.read_bytes()
 assert sha(s)==proof['new_bundle_sha256']
 pins=json.loads(base64.b64decode(const(s,'HOOK_RUNTIME_PINS_B64')));old=proof['old_effective_pins'];assert len(pins)==len(old)==23
 assert {k for k in pins if pins[k]!=old[k]}==set(proof['changes'])
 editor=base64.b64decode(const(s,'HOOK_EDITOR_B64'));restored=editor
 for k,(before,after) in proof['changes'].items():
  assert old[k]==before and pins[k]==after and restored.count(after.encode())==1
  restored=restored.replace(after.encode(),before.encode())
 assert sha(restored)==proof['old_editor_sha256']
 # Full inverse proves legacy pin selection and all editor logic unchanged.
 reconstructed=s.replace(const(s,'HOOK_EDITOR_B64'),base64.b64encode(restored),1)
 reconstructed=reconstructed.replace(const(reconstructed,'HOOK_RUNTIME_PINS_B64'),base64.b64encode(json.dumps(old).encode()),1)
 assert sha(reconstructed)==proof['old_bundle_sha256']
 print('PR1620_TWO_PIN_PROOF_OK all_other_bundle_bytes_and_legacy_identical=1 pins=23 changed=2')
