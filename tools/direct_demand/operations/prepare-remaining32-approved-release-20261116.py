"""Prepare the existing approved sender, preserving native guards and the ten-pair cap."""
from __future__ import annotations
import hashlib, importlib.util, json, pathlib, sys
PREFIX='<?php\ndeclare(strict_types=1);\n'
EXPECTED='372823d98e580e708169bc8ac9367245376a803b'
ASSET='70164f5b41aa7e7719fa212a0742f11390b55c1f'
IDS=[548,2947,4032,43675,48263,82790,24743,66547,60232,78033,43569,78002,2451,63208,2491,2470,64954,2479,2493,76318,53617,12124,76011,65108,123308,65938,80008,2436,2444,2445,218,63191]
def sha(b):return hashlib.sha256(b).hexdigest()
def load(p,digest):
    raw=p.read_bytes()
    if sha(raw)!=digest:raise ValueError('exact_input_changed:'+p.name)
    return json.loads(raw)
def main():
    source,output=map(pathlib.Path,sys.argv[1:]);output.mkdir(parents=True,exist_ok=True)
    prepared=load(source/'prepared33.json','f5ad1989883965879f2d2f5882beaf8988b5a627d46e3c4ecfb720a3e828a934')
    clearance=load(source/'clearance.json','4200a8e56e53dc264ddadf1a6e090e1957c50c6133da75b386568ccf1d513384')
    publication=load(source/'asset-result.json','2376ea3205284921792e01858c3c21e0e656fa4e3150bc347cde57b4a2ea6caa')
    assert prepared['media_hold']==[108165] and len(prepared['rows'])==33
    assert clearance['ok'] and clearance['clear_count']==33 and clearance['blocked_count']==0
    assert clearance['runtime']=={'head':EXPECTED,'tracked_clean':True,'component_mismatches':[]}
    assert clearance['monitor']['pending'] is None and clearance['monitor']['cycle']['state']=='budget_exhausted'
    assert all(r['candidate_clear'] and not r['matches'] and not r['prior_receipts'] for r in clearance['rows'])
    assert publication['ok'] and publication['asset_commit']==ASSET and publication['held_ids']==[108165]
    selected=[r for r in prepared['rows'] if r['hotel_id']!=108165]
    assert [r['hotel_id'] for r in selected]==[r['hotel_id'] for r in publication['blobs']]==IDS
    base=pathlib.Path('tools/direct_demand/operations')
    spec=importlib.util.spec_from_file_location('batch',base/'prepare-commercial-batch.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    template=(base/'commercial-batch-template-20261102.php').read_text();guard=(base/'commercial-channel-hotel-feeds-20261113-update10-nightly-guard.php').read_text();assert guard.startswith(PREFIX)
    rows=[]
    for item,link in zip(selected,publication['blobs']):
        tv=item['hotel_id'];assert not item['media_hold'] and item['cover'] and item['new_price'] is None
        raw=(source/item['cover']['path']).read_bytes();assert sha(raw)==item['cover']['sha256']==link['sha256']
        assert item['text_sha256']==sha(item['text'].encode()) and 'с видом на океана.' not in item['text']
        assert link['url']==f'https://raw.githubusercontent.com/pyatkoff/max-search-bot/{ASSET}/.github/traffic-publish/commercial-expansion33-20261116/{tv}.png'
        sources=item['sources'][:]
        if tv==123308:sources.append('https://www.marriott.com/en-us/hotels/mlejm-jw-marriott-maldives-kaafu-atoll-island-resort/overview/')
        rows.append({'hotel_id':tv,'logical_id':item['logical_id'],'aliases':item['aliases'],'text':item['text'],'text_sha256':item['text_sha256'],'photo_url':link['url'],'photo_sha256':link['sha256'],'media_state':module.READY,'sources':sources,'media_provenance':'Three retained property photographs; exact runner-rendered cover reviewed on 16 November 2026. No room-category entitlement or current physical-condition guarantee is inferred from photographs.'})
    report={'schema':1,'current_source':EXPECTED,'asset_commit':ASSET,'clearance_run':43921478505,'prepared_artifact':14253362790,'visual_review':'All32 actual rendered covers inspected in four contact sheets; Soneva Secret is held, corrupt Finolhu frame is excluded.','new_prices':0,'site_admission_claimed':False,'ai_admission_claimed':False,'batches':[]}
    for number,start in enumerate(range(0,len(rows),10),22):
        selected=rows[start:start+10];op=f'commercial-hotel-batch-20261116-{number}-v1';folder=output/str(number);folder.mkdir()
        manifest={'state':'PREPARED_NOT_SENT','operation':op,'button_text':'✈️ Узнать стоимость тура','expected_new_full_pairs':len(selected),'server_clearance_run':43921478505,'source_asset_commit':ASSET,'owner_scope_issue':1142,'owner_scope_comment':6923781196,'rows':selected}
        raw=(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n').encode();approval={'schema_version':1,'operation':op,'expected_installed_sha':EXPECTED,'hotel_ids':[r['hotel_id'] for r in selected],'manifest_sha256':sha(raw)}
        compiled,proof=module.prepare(template,raw,approval);assert compiled.startswith(PREFIX)
        preflight="if(file_exists('/var/www/anytoour/data/channel-post-operations/"+op+"')){echo json_encode(['ok'=>false,'operation'=>'"+op+"','error'=>'existing_operation_no_replay','post_write_attempts'=>0]),PHP_EOL;exit(2);}\n"
        program=PREFIX+preflight+guard[len(PREFIX):]+'\n'+compiled[len(PREFIX):]
        (folder/'manifest.json').write_bytes(raw);(folder/'approval.json').write_text(json.dumps(approval,ensure_ascii=False,indent=2)+'\n');(folder/'send.php').write_text(program)
        report['batches'].append({**proof,'operation':op,'hotel_ids':approval['hotel_ids'],'manifest_sha256':sha(raw),'compiled_sender_sha256':sha(compiled.encode()),'guard_sha256':sha(guard.encode()),'script_sha256':sha(program.encode()),'directory_must_be_absent':True})
    assert [len(r['hotel_ids']) for r in report['batches']]==[10,10,10,2]
    (output/'release-intent.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({'prepared_hotels':32,'batches':4,'expected_new_posts':64,'held_ids':[108165],'provider_calls':0,'source':EXPECTED}))
if __name__=='__main__':main()
