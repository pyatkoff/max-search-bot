"""Local CLOSED interface bridge. Does not manufacture admission or sender summaries."""
import pathlib,hashlib,importlib.util
ADAPTER=pathlib.Path(__file__).resolve().parent/'prepare_feed123.py'
ADAPTER_SHA256='ec72e075065cab74e0569892009188789a1d45b437c3a3f653e7e9bbce280757'
raw=ADAPTER.read_bytes()
if hashlib.sha256(raw).hexdigest()!=ADAPTER_SHA256:raise ValueError('feed123_adapter_changed')
spec=importlib.util.spec_from_file_location('closed_feed123_adapter',ADAPTER);adapter=importlib.util.module_from_spec(spec);spec.loader.exec_module(adapter)
core=adapter.scoped_module()
NAMES=core.NAMES
INVENTORY_SHA=adapter.RECOVERED_INVENTORY_SHA
PUBLIC_LABELS=adapter.LABELS
PUBLIC_LABEL_ORIGINALS=adapter.LABEL_ORIGINALS
fresh=core.fresh
feed_operation=core.feed_operation
inventory_projection=adapter.inventory_projection
def prepare(repo,originals,reads,routes,preimages,admission,out,reviewed_digest):
 raw=pathlib.Path(admission).read_bytes()
 if hashlib.sha256(raw).hexdigest()!=reviewed_digest:raise ValueError('derived_admission_changed')
 # This is same-run compiler admission, not permission to write. Finite config and
 # authenticated artifacts/probe are checked before this call; writer still requires permit.
 adapter.REVIEWED_RECOVERY_METADATA=True
 adapter.REVIEWED_ADMISSION_SHA256=reviewed_digest
 adapter.REVIEWED_INSTALLED_HOOK_SHA256='483f9f1cd853fdf1808523e72d466e2acd0866ec6ae35de049ada38b5cc128f9'
 adapter.REVIEWED_NIGHTLY_GUARD_SHA256='26fd1f24bc79f39cacc8cd112b6bd5e9dcde9f0bfde6061363955272435b4029'
 return adapter.prepare(repo,originals,reads,routes,preimages,admission,out)
