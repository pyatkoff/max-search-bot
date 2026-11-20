"""Assemble an offline read-only installed-cycle verifier from the existing close builder."""
import hashlib,importlib.util,json,pathlib,sys
root=pathlib.Path(__file__).resolve().parent
if len(sys.argv)!=4:raise SystemExit('usage: build-verify-price27316-cycle.py FROZEN_HOOK CONTRACT OUTPUT')
spec=importlib.util.spec_from_file_location('closebuilder',root/'build-close-price27316-and-install-hook.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
raw,proof=m.build(pathlib.Path(sys.argv[1]).read_bytes(),pathlib.Path(sys.argv[2]).read_bytes())
sentinel=b'// Standalone only after offline assembly. No callback loop or provider edit calls.\n'
assert raw.count(sentinel)==1,'INSTALL_DRIVER_BOUNDARY_CHANGED'
helpers=raw.split(sentinel,1)[0]
assert b'function close27316_write_file' not in helpers and b'ANYTOUR_PRICE27316_CLOSE_APPLY' not in helpers
prefix=b'<?php\ndeclare(strict_types=1);\n'
source=(root/'verify-price27316-cycle.php').read_bytes();assert source.startswith(prefix)
result=helpers+source[len(prefix):]
with pathlib.Path(sys.argv[3]).open('xb') as f:f.write(result)
print(json.dumps({'sha256':hashlib.sha256(result).hexdigest(),'bytes':len(result),'mode':'read_only_cycle_verification','installed_hook_sha256':proof['upgraded_hook_sha256'],'install_driver_included':False}))
