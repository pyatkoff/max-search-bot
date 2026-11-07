import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile

root=Path(os.environ.get('CAPTION_POLICY_SOURCE_DIR','tools/direct_demand/operations'))
spec=importlib.util.spec_from_file_location('caption_prep',root/'prepare-commercial-batch.py')
api=importlib.util.module_from_spec(spec)
spec.loader.exec_module(api)
source=(root/'commercial-batch-template-20261102.php').read_text()
raw=(root/'commercial-batch-20261107-38-manifest.json').read_bytes()
approval=json.loads((root/'commercial-batch-20261107-38-approval.json').read_text())
prior,prior_proof=api.prepare(source,raw,approval)
assert prior_proof['post_write_attempts']==0
old=json.loads(raw)
row=old['rows'][0]
old['rows']=[row]
date,batch=api.SHORT_HEADING_CUTOVER
old['operation']=f'commercial-hotel-batch-{date}-{batch}-v1'
approval.update(operation=old['operation'],hotel_ids=[row['hotel_id']])
title,body=row['text'].split('\n',1)
assert title.endswith(' с перелётом')

def prepare(text):
    row['text']=text
    row['text_sha256']=hashlib.sha256(text.encode()).hexdigest()
    encoded=json.dumps(old,ensure_ascii=False).encode()
    approval['manifest_sha256']=hashlib.sha256(encoded).hexdigest()
    return api.prepare(source,encoded,approval)

for suffix in ('с перелётом','с перелетом'):
    try:
        prepare(title.removesuffix('с перелётом')+suffix+'\n'+body)
    except ValueError as exc:
        assert str(exc)=='caption_title_must_omit_flight_suffix',str(exc)
    else:
        raise AssertionError('a flight suffix passed the new title rule')
new=title.removesuffix(' с перелётом')+'\n'+body
generated,proof=prepare(new)
assert proof['post_write_attempts']==proof['db_writes']==proof['provider_calls']==0
encoded=re.search(r"const MANIFEST_BASE64='([^']+)';",generated)[1]
import base64
compiled=json.loads(base64.b64decode(encoded))
assert compiled['rows'][0]['text']==new
assert compiled['rows'][0]['text'].split('\n',1)[1]==body
with tempfile.TemporaryDirectory() as td:
    path=Path(td)/'generated.php'
    path.write_text(generated)
    subprocess.run(['php','-l',str(path)],check=True)
print('Short title policy and unchanged historical approval passed without channel writes')
