import pathlib,json,base64,hashlib,re,sys
ROOT=pathlib.Path('tools/direct_demand/operations');OUT=pathlib.Path('caption78929');OUT.mkdir(exist_ok=True)
APP='cc017ee5bb669f4c7c13efc91d5c5812a50e55f2'
READ='commercial-w9-78929-adults-caption-read-20261118-v1'
EDIT='commercial-w9-78929-adults-caption-edit-20261118-v1'
PINS=[('f5975ab95de5f45e087eb7a0f768f566c5bca648','c6386e155d42bc83970edc95f35b3ebeb5140533'),('f0e04e9b4e0ed6859ced96279cbf0619931596e6','1982ff7368763edf8b7238c3142e3cc28579e991'),('db8181426091a685a79ee55991cde22671f0d9dd','94fb1988ac8caf90dc5103aa23c58e7aad6ea691')]
sha=lambda b:hashlib.sha256(b).hexdigest()
def raw(d):return (json.dumps(d,ensure_ascii=False,separators=(',',':'))+'\n').encode()
def bind(template,constants):
 for old,new in PINS:
  assert template.count(old)==1 and template.count(new)==0
  template=template.replace(old,new)
 declarations='\n'.join('define('+json.dumps(k)+','+json.dumps(v,ensure_ascii=False)+');' for k,v in constants.items())
 assert 'declare(strict_types=1);'in template
 return template.replace('declare(strict_types=1);','declare(strict_types=1);\n'+declarations,1)
def read_stage():
 bound=json.loads((ROOT/'commercial-w9-78929-caption-bound-posts-20261118.json').read_text())
 posts=bound['posts'];assert len(posts)==2 and all(p['hotel_id']==78929 and p['body_photo_button_verified'] for p in posts)
 by={p['platform']:p for p in posts};assert set(by)=={'max','telegram'}
 item={'hotel_id':78929,'max_publication_id':by['max']['publication_id'],'max_url':by['max']['post_url'],'telegram_publication_id':by['telegram']['publication_id'],'telegram_message_id':int(by['telegram']['message_id']),'telegram_url':by['telegram']['post_url']}
 scope={'operation':READ,'rows':[item]};b=raw(scope)
 s=(ROOT/'commercial-caption-review73-20261113-read-scope.php').read_text()
 s=s.replace('commercial-caption-review73-20261113-read-v1',READ).replace("count($scope['rows']??[])===73","count($scope['rows']??[])===1").replace("count($out['posts'])===146","count($out['posts'])===2").replace("$out['provider_calls']===73","$out['provider_calls']===1")
 s,n=re.subn(r"array_column\(\$scope\['rows'\],'hotel_id'\)===\[[0-9,]+\]","array_column($scope['rows'],'hotel_id')===[78929]",s);assert n==1
 s=bind(s,{'EXPECTED_INSTALLED_SHA':APP,'APPROVED_MANIFEST_SHA256':sha(b),'MANIFEST_BASE64':base64.b64encode(b).decode()})
 (OUT/'read.php').write_text(s);(OUT/'read-scope.json').write_bytes(b)
def edit_stage():
 b=(OUT/'read-result.json').read_bytes();d=json.loads(b)
 assert d['ok'] and d['operation']==READ and d['installed_sha']==APP and len(d['posts'])==2 and d['post_write_attempts']==0
 bound=json.loads((ROOT/'commercial-w9-78929-caption-bound-posts-20261118.json').read_text());expected={p['platform']:p for p in bound['posts']}
 rows=[]
 for x in d['posts']:
  p=expected[x['platform']];assert x['hotel_id']==78929 and x['publication_id']==p['publication_id'] and x['post_url']==p['post_url']
  assert x['status']=='sent' and x['button_observed_in_snapshot'] and x['button_resolves_to_current_post']
  old=x['caption'];anchor='\n\nГородской Дубай';assert old.count(anchor)==1 and 'Отель только для взрослых.'not in old
  new=old.replace(anchor,'\n\nОтель только для взрослых.'+anchor,1)
  row={k:x[k] for k in ['hotel_id','platform','publication_id','version_id','message_id','post_url','photo_signature','attachments_sha256','button_url_sha256','config_version','source_key','button_observed_in_snapshot','monitor_price_record_id']}
  row.update(old_text=old,new_text=new,old_text_sha256=sha(old.encode()),new_text_sha256=sha(new.encode()));rows.append(row)
 assert {r['platform']for r in rows}=={'max','telegram'}
 pubs=[r['publication_id']for r in rows];cohort=sha(raw(rows))
 m={'schema_version':1,'operation':EDIT,'expected_installed_sha':APP,'update_kind':'approved_existing_caption_quality_revision','button_text':'✈️ Узнать стоимость тура','throttle_microseconds':3200000,'snapshot':{'operation':READ,'installed_sha':APP,'post_count':2,'sha256':sha(b),'observed_at_utc':d['observed_at_utc']},'quality_authorization':'user_20261118_hotel_content_material_conditions','quality_cohort_sha256':cohort,'batch_index':0,'batch_kind':'canary','predecessor':None,'row_count':2,'rows':rows}
 mb=raw(m);(OUT/'manifest.json').write_bytes(mb)
 s=(ROOT/'commercial-caption-review73-20261113-executor.php').read_text()
 start=s.index('function captionQualityScope(');end=s.index('/** Explicitly approved copy',start)
 fixed="""function captionQualityScope(array $payload): void
{
    need(isApprovedCaptionQuality($payload)
        && ($payload['quality_cohort_sha256']??'')===REPAIR_COHORT_SHA256
        && ($payload['quality_authorization']??'')==='user_20261118_hotel_content_material_conditions'
        && ($payload['operation']??'')==='commercial-w9-78929-adults-caption-edit-20261118-v1'
        && ($payload['predecessor']??null)===null && count($payload['rows'])===2,
        'exact_adults_caption_scope_changed');
    $expected=json_decode(base64_decode(REPAIR_PUBLICATION_IDS_BASE64,true),true,16,JSON_THROW_ON_ERROR);
    need(array_column($payload['rows'],'publication_id')===$expected,'exact_adults_publications_changed');
    $platforms=[];
    foreach($payload['rows'] as $row){
        need($row['hotel_id']===78929 && substr_count($row['old_text'],"\\n\\nГородской Дубай")===1
            && !str_contains($row['old_text'],'Отель только для взрослых.')
            && $row['new_text']===str_replace("\\n\\nГородской Дубай","\\n\\nОтель только для взрослых.\\n\\nГородской Дубай",$row['old_text']),
            'only_adults_sentence_insertion_allowed');
        $platforms[]=$row['platform'];
    }
    sort($platforms);need($platforms===['max','telegram'],'exact_pair_required');
}

"""
 s=s[:start]+fixed+s[end:]
 s=s.replace('commercial-caption-review73-20261113-read-v1',READ).replace("($source['post_count'] ?? null) === 146","($source['post_count'] ?? null) === 2")
 s=bind(s,{'EXPECTED_INSTALLED_SHA':APP,'APPROVED_UPDATE_OPERATION':EDIT,'APPROVED_MANIFEST_SHA256':sha(mb),'MANIFEST_BASE64':base64.b64encode(mb).decode(),'REPAIR_COHORT_SHA256':cohort,'REPAIR_PUBLICATION_IDS_BASE64':base64.b64encode(raw(pubs)).decode(),'REPAIR_BATCH_INDEX':0})
 (OUT/'edit.php').write_text(s)
 # Pure guard tests run before any provider mutation: exact insertion accepted;
 # wrong hotel, different publication, changed price/body and incomplete pair rejected.
 functions=s[:s.index('$out = [')]
 test='\n$good=json_decode(base64_decode(MANIFEST_BASE64),true);captionQualityScope($good);\n'
 for mutation in ["$bad['rows'][0]['hotel_id']=78930;","$bad['rows'][0]['publication_id']++;","$bad['rows'][0]['new_text'].=' changed';","array_pop($bad['rows']);"]:
  test+="$bad=$good;"+mutation+"$rejected=false;try{captionQualityScope($bad);}catch(Throwable $e){$rejected=true;}need($rejected,'negative_scope_test_failed');\n"
 test+="echo 'EXACT_ADULTS_INSERTION_5_SCOPE_TESTS_PASS',PHP_EOL;\n"
 (OUT/'scope-tests.php').write_text(functions+test)
 print(json.dumps({'operation':EDIT,'rows':2,'hotel_id':78929,'price_mutations':0,'photo_button_url_changes':0,'manifest_sha256':sha(mb)}))
if __name__=='__main__':
 if sys.argv[1]=='read':read_stage()
 elif sys.argv[1]=='edit':edit_stage()
 else:raise SystemExit('unknown stage')
