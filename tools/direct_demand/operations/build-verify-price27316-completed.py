"""Offline bundle of reviewed frozen helpers + recovery functions, never either main driver."""
import hashlib,json,pathlib,subprocess,sys,tempfile
root=pathlib.Path(__file__).resolve().parent
if len(sys.argv) not in (3,4):raise SystemExit('usage: build-verify-price27316-completed.py FROZEN_HOOK OUTPUT [VERIFIER_SOURCE]')
with tempfile.TemporaryDirectory() as tmp:
    bundle=pathlib.Path(tmp)/'recovery.php'
    subprocess.run([sys.executable,str(root/'build-reconcile-pending-27316.py'),sys.argv[1],str(bundle)],check=True,capture_output=True)
    raw=bundle.read_bytes()
sentinel=b'\nif (PHP_SAPI !== \'cli\') { http_response_code(404); exit; }'
assert raw.count(sentinel)==1,'RECOVERY_DRIVER_BOUNDARY_CHANGED'
helpers=raw.split(sentinel,1)[0]
source=(pathlib.Path(sys.argv[3]) if len(sys.argv)==4 else root/'verify-price27316-completed.php').read_bytes()
prefix=b'<?php\ndeclare(strict_types=1);\n'
assert source.startswith(prefix)
result=helpers+b'\n'+source[len(prefix):]
assert b"$apply = getenv('ANYTOUR_MONITOR_27316_APPLY')" not in result
with pathlib.Path(sys.argv[2]).open('xb') as f:f.write(result)
print(json.dumps({'sha256':hashlib.sha256(result).hexdigest(),'bytes':len(result),'mode':'helper_only_bundle','recovery_driver_included':False,'hook_driver_included':False}))
