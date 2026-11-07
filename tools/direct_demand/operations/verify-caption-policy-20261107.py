import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile

root=Path(os.environ.get('CAPTION_POLICY_SOURCE_DIR','tools/direct_demand/operations'))
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

api=load('caption_prep',root/'prepare-commercial-batch.py')
template=(root/'commercial-batch-template-20261102.php').read_text()
legacy_raw=(root/'commercial-batch-20261107-22-manifest.json').read_bytes()
legacy_approval=json.loads((root/'commercial-batch-20261107-22-approval.json').read_text())
legacy,_=api.prepare(template,legacy_raw,legacy_approval)
original=root/'prepare-commercial-batch.original.py'
if original.exists():
    old=load('old_caption_prep',original)
    assert old.prepare(template,legacy_raw,legacy_approval)[0]==legacy

manifest=json.loads(legacy_raw)
base=copy.deepcopy(manifest)
sample=base['rows'][0]
base['rows']=[sample]
base['operation']='commercial-hotel-batch-20261107-23-v1'
approval={**legacy_approval,'operation':base['operation'],'hotel_ids':[sample['hotel_id']]}
def prepare(text):
    sample['text']=text
    sample['text_sha256']=hashlib.sha256(text.encode()).hexdigest()
    raw=json.dumps(base,ensure_ascii=False).encode()
    approval['manifest_sha256']=hashlib.sha256(raw).hexdigest()
    return api.prepare(template,raw,approval)
def must_reject(text,reason):
    try:
        prepare(text)
    except ValueError as error:
        assert str(error)==reason,(str(error),reason)
    else:
        raise AssertionError('invalid caption passed')

must_reject(sample['text'],'caption_requires_tour_heading_and_resort_country')
title='✈️ Туры в Blue Bay Platinum с перелётом'
location='📍 Шарм-эль-Шейх, Египет.'
caption=title+'\n'+location+'\n\nСодержание публикации.'
standard,proof=prepare(caption)
assert proof['provider_calls']==proof['db_writes']==proof['post_write_attempts']==0
assert 'commercialCaptionPunctuationIsValid' in standard and 'commercialCaptionPunctuationIsValid' not in legacy
must_reject(title+'\n📍 Мармарис, Турция\n\nСодержание публикации.','caption_requires_tour_heading_and_resort_country')
must_reject(title+'\n'+location+'\n\nПляж - песчаный.','caption_invalid')
must_reject(title.replace('Blue Bay','Blue - Bay')+'\n'+location,'caption_invalid')
must_reject(title+'\n'+location.replace('Шарм-эль','Шарм—эль'),'caption_invalid')
if os.environ.get('CHECK_PHP')=='1':
    with tempfile.TemporaryDirectory() as td:
        path=Path(td)/'generated-publisher.php'
        path.write_text(standard)
        subprocess.run(['php','-l',str(path)],check=True)
    args=[caption,title+'\n'+location+'\n\nПляж - песчаный.',title.replace('Blue Bay','Blue - Bay')+'\n'+location]
    check=api.PHP_HEADING_HYPHENS+'\nforeach (array_slice($argv,1) as $text) echo commercialCaptionPunctuationIsValid($text) ? "1" : "0";'
    result=subprocess.run(['php','-r',check,*args],check=True,capture_output=True,text=True)
    assert result.stdout=='100',result.stdout
print('Caption cutover, exact historical compatibility and body punctuation checks passed')
