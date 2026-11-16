"""Runner-only source preparation. No server, supplier, message or knowledge writes."""
from __future__ import annotations
import hashlib,json,os,pathlib,subprocess,sys
BASE='3c76952bd3b6f7e3d04b0f6aae876816188f906f'
PINS={'services/ManagerHotelKnowledge.php':'0e21fe60f25065cbc7519fb55bea46c282d1e070','tests/hotel_catalogue_checks.php':'f180a9dca473aaf6e18808acbd30e581cf217ea8','docs/HOTEL_KNOWLEDGE_CATALOGUE.md':'abd5505cbf9170ec386e0b3f4cd8561caa1ce2fb'}
TESTS=r'''
    // Country expansion is only compatibility for separately admitted data.
    // All identities, approvals, facts and sources below are memory-only fixtures.
    $countryName = new ReflectionMethod(ManagerHotelKnowledge::class, 'retainedCountry');
    $retainedSelectorCheck = new ReflectionMethod(ManagerHotelKnowledge::class, 'validRetainedSelector');
    $supported = [
        '2'=>['Thailand',['THAILAND','ТАИЛАНД','ТАЙЛАНД']],
        '8'=>['Maldives',['MALDIVES','МАЛЬДИВЫ']],
        '9'=>['United Arab Emirates',['UNITED ARAB EMIRATES','UAE','ОАЭ','ОБЪЕДИНЕННЫЕ АРАБСКИЕ ЭМИРАТЫ','ОБЪЕДИНЁННЫЕ АРАБСКИЕ ЭМИРАТЫ']],
        '12'=>['Sri Lanka',['SRI LANKA','ШРИ-ЛАНКА','ШРИ ЛАНКА']],
        '16'=>['Vietnam',['VIETNAM','ВЬЕТНАМ']],
        '64'=>['Oman',['OMAN','ОМАН']],
    ];
    foreach ($supported as $countryId=>[$country,$aliases]) {
        $fixture = $makeEntry(8000+(int)$countryId);
        $fixture['source_identity']['country'] = (string)$countryId;
        $fixture['accepted_binding']['identity_anchor']['country'] = $country;
        $fixture['binding_revision'] = hash('sha256',$canonical($fixture['accepted_binding']));
        $core = $fixture['accepted_binding']; $key = $core['research_hotel_key'];
        $sourceRecord = ['id'=>'country_fixture_source','hotel_key'=>$key,'kind'=>'official',
            'url'=>'https://example.test/synthetic-country','read_scope'=>'Synthetic source record, never admitted.',
            'limitations'=>'','publication_date'=>null,'retrieved_on'=>'2026-11-16'];
        $fact = ['id'=>'country_fixture_fact','hotel_key'=>$key,'text'=>'Synthetic category declaration.',
            'aspect'=>'room','kind'=>'official_declared','valid_at_travel'=>'unverified','declared'=>true,
            'scope'=>['kind'=>'room_category','key'=>'synthetic-suite'],'source_ids'=>[$sourceRecord['id']]];
        $bundle = ['schema_version'=>'hotel_reference_v1','status'=>'SEALED_AUTHORING_DATA_ONLY',
            'project_key'=>'anytour','hotel_ref'=>$core['hotel_ref'],'research_hotel_key'=>$key,
            'binding_revision'=>$fixture['binding_revision'],'price_authority'=>'none',
            'trusted_selector_required'=>true,'runtime_admission_verified'=>false,
            'facts'=>[$fact],'sources'=>[$sourceRecord],'material_limits'=>['Synthetic test only.'],
            'coverage'=>['complete'=>false,'selected_claim_count'=>1],
            'record_hashes'=>['claims'=>[$fact['id']=>hash('sha256',$canonical($fact))],
                'sources'=>[$sourceRecord['id']=>hash('sha256',$canonical($sourceRecord))]]];
        $bytes = $canonical($bundle); $fixture['bundle_sha256'] = hash('sha256',$bytes);
        $native = $selectorFor($fixture); $manifest = $manifestFor([$fixture]);
        $reference = $select->invoke(null,$manifest,$bytes,$native,$now);
        draftContextCheck('expanded country '.$countryId.' uses full existing admission pipeline',
            $reference['status']==='available' && $reference['facts']===[$fact]
            && $reference['sources']===[$sourceRecord] && $reference['price_authority']==='none'
            && $reference['room_tariff_equivalence_verified']===false && $reference['future_operation_verified']===false);
        foreach ($aliases as $alias) {
            draftContextCheck('explicit country name '.$alias.' only', $countryName->invoke(null,'  '.$alias.'  ')===$country);
        }
        $retained = ['project_key'=>'anytour','hotel_ref'=>$core['hotel_ref'],
            'selected_hotel'=>$fixture['accepted_source_names'][0],'selected_room'=>null,
            'provenance'=>['kind'=>'retained_search3_candidate','request_scope_digest'=>str_repeat('a',64),
                'source_scope_digest'=>str_repeat('b',64),'provider'=>'tourvisor','offer_digest'=>str_repeat('c',64),
                'candidate_sha256'=>str_repeat('d',64),'source_generated_at'=>'2026-11-10T23:59:30Z',
                'request_country'=>$aliases[0],'candidate_country'=>end($aliases)]];
        draftContextCheck('expanded retained country '.$countryId.' keeps request and candidate agreement',
            $retainedSelectorCheck->invoke(null,$retained)===true
            && $binding->invoke(null,$manifest,$retained)===[$fixture,'available']);
        $retainedRef = $selectEntry->invoke(null,$fixture,$bytes,$retained,$now);
        draftContextCheck('expanded retained reference '.$countryId.' preserves whole category and tariff limits',
            $retainedRef['facts']===[$fact] && $retainedRef['sources']===[$sourceRecord]
            && $retainedRef['selected_room']===null && $retainedRef['current_trip_binding']===false
            && $retainedRef['customer_agreement_verified']===false && $retainedRef['current_price_verified']===false
            && $retainedRef['availability_verified']===false && $retainedRef['meal_plan_equivalence_verified']===false);
        $mismatch=$retained;$mismatch['provenance']['candidate_country']='Egypt';
        draftContextCheck('expanded country '.$countryId.' rejects contradictory candidate country',
            $retainedSelectorCheck->invoke(null,$mismatch)===false);
        $mismatch=$retained;$mismatch['provenance']['request_country']='Egypt';$mismatch['provenance']['candidate_country']='Egypt';
        draftContextCheck('expanded country '.$countryId.' rejects wrong request country',
            $binding->invoke(null,$manifest,$mismatch)===[null,'source_country_mismatch']);
        foreach (['approval','country','namespace','core','chain'] as $fault) {
            $badEntry=$fixture;
            if($fault==='approval')unset($badEntry['approval']);
            if($fault==='country')$badEntry['source_identity']['country']='1';
            if($fault==='namespace')$badEntry['accepted_binding']['hotel_ref']['catalog']='tourvisor';
            if($fault==='core')$badEntry['binding_revision']=str_repeat('0',64);
            if($fault==='chain')array_pop($badEntry['accepted_binding']['accepted_chain']);
            draftContextCheck('expanded country '.$countryId.' retains '.$fault.' gate',
                $binding->invoke(null,$manifestFor([$badEntry]),$native)===[null,'manifest_invalid']);
        }
        $wrong=$native;$wrong['source_identity']['hotel']=(string)((int)$wrong['source_identity']['hotel']+1);
        draftContextCheck('expanded country '.$countryId.' never resolves by name alone',
            $binding->invoke(null,$manifest,$wrong)===[null,'binding_unresolved']);
        draftContextCheck('expanded country '.$countryId.' never admits modified facts',
            $select->invoke(null,$manifest,$bytes.' ',$native,$now)['reason']==='bundle_integrity_failed');
        $expired=$bundle;$expired['valid_until']='2026-11-10T00:00:00Z';$expiredBytes=$canonical($expired);
        $expiredEntry=$fixture;$expiredEntry['bundle_sha256']=hash('sha256',$expiredBytes);
        draftContextCheck('expanded country '.$countryId.' retains explicit validity',
            $select->invoke(null,$manifestFor([$expiredEntry]),$expiredBytes,$native,$now)['reason']==='outside_explicit_validity');
    }
    foreach (['2','8','9','12','16','64','EG','TR','TH','MV','AE','LK','VN','OM','Atlantis','France','Dubai','Turkey or Oman'] as $unknown) {
        draftContextCheck('country names do not infer numeric IDs, codes or regions '.$unknown,
            $countryName->invoke(null,$unknown)===null);
    }
'''
SNAPSHOT=r'''require 'services/ManagerHotelKnowledge.php';$d='knowledge/hotels/';$c=json_decode(file_get_contents($d.'catalogue.json'),true);$items=[];$available=0;foreach($c['shards'] as $s){$m=json_decode(file_get_contents($d.$s['file']),true);foreach($m['bindings'] as $e){$selector=['project_key'=>'anytour','source_identity'=>$e['source_identity'],'selected_hotel'=>$e['accepted_source_names'][0],'selected_room'=>null,'provenance'=>['message_id'=>1,'event_id'=>1,'publication_sha256'=>str_repeat('a',64),'source_sha256'=>str_repeat('b',64)]];$r=ManagerHotelKnowledge::capture($selector,strtotime('2026-11-16T00:00:00Z'));$items[]=$r;if($r['status']==='available')$available++;}}echo json_encode(['references'=>count($items),'available'=>$available,'sha256'=>hash('sha256',json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))]);'''
HARNESS="$n=0;function draftContextCheck(string $name,bool $ok):void{global $n;$n++;if(!$ok)throw new RuntimeException($name);}require 'tests/hotel_catalogue_checks.php';hotelCatalogueChecks();echo 'COUNTRY_CATALOGUE_CHECKS_OK checks='.$n.PHP_EOL;"
def run(root,args):return subprocess.run(args,cwd=root,text=True,capture_output=True,timeout=180)
def must(ok,reason):
    if not ok:raise RuntimeError(reason)
def sha(raw):return hashlib.sha256(raw).hexdigest()
def blob(raw):return hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()
def main():
    root=pathlib.Path(sys.argv[1]).resolve();out=pathlib.Path(sys.argv[2]).resolve();out.mkdir(parents=True,exist_ok=True)
    must(subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip()==BASE,'BASE_CHANGED')
    originals={p:(root/p).read_bytes() for p in PINS}
    must(all(blob(originals[p])==h for p,h in PINS.items()),'SOURCE_CHANGED')
    knowledge={str(p.relative_to(root)):sha(p.read_bytes()) for p in (root/'knowledge/hotels').rglob('*') if p.is_file()}
    before=run(root,['php','-r',SNAPSHOT]);must(before.returncode==0,'BASELINE_REFERENCE_CAPTURE_FAILED')
    (out/'baseline-references.json').write_text(before.stdout)
    source=root/'services/ManagerHotelKnowledge.php';tests=root/'tests/hotel_catalogue_checks.php';doc=root/'docs/HOTEL_KNOWLEDGE_CATALOGUE.md'
    test=tests.read_text();must(test.rstrip().endswith('}'),'TEST_ANCHOR_CHANGED');tests.write_text(test.rstrip()[:-1]+TESTS+'\n}\n')
    red=run(root,['php','-r',HARNESS]);(out/'red.log').write_text(red.stdout+red.stderr)
    must(red.returncode!=0 and 'expanded country 2 uses full existing admission pipeline' in red.stderr,'RED_NOT_EXPECTED_COUNTRY_GUARD')
    old="private const SOURCE_COUNTRIES = ['1'=>'Egypt', '4'=>'Turkey'];"
    new="private const SOURCE_COUNTRIES = ['1'=>'Egypt', '4'=>'Turkey', '2'=>'Thailand', '8'=>'Maldives',\n        '9'=>'United Arab Emirates', '12'=>'Sri Lanka', '16'=>'Vietnam', '64'=>'Oman'];"
    text=source.read_text();must(text.count(old)==1,'SOURCE_COUNTRY_ANCHOR_CHANGED');text=text.replace(old,new)
    old="return ['EGYPT'=>'Egypt','ЕГИПЕТ'=>'Egypt','TURKEY'=>'Turkey','ТУРЦИЯ'=>'Turkey'][self::foldName($name)] ?? null;"
    new="""return ['EGYPT'=>'Egypt','ЕГИПЕТ'=>'Egypt','TURKEY'=>'Turkey','ТУРЦИЯ'=>'Turkey',
            'THAILAND'=>'Thailand','ТАИЛАНД'=>'Thailand','ТАЙЛАНД'=>'Thailand',
            'MALDIVES'=>'Maldives','МАЛЬДИВЫ'=>'Maldives',
            'UNITED ARAB EMIRATES'=>'United Arab Emirates','UAE'=>'United Arab Emirates','ОАЭ'=>'United Arab Emirates',
            'ОБЪЕДИНЕННЫЕ АРАБСКИЕ ЭМИРАТЫ'=>'United Arab Emirates','ОБЪЕДИНЁННЫЕ АРАБСКИЕ ЭМИРАТЫ'=>'United Arab Emirates',
            'SRI LANKA'=>'Sri Lanka','ШРИ-ЛАНКА'=>'Sri Lanka','ШРИ ЛАНКА'=>'Sri Lanka',
            'VIETNAM'=>'Vietnam','ВЬЕТНАМ'=>'Vietnam','OMAN'=>'Oman','ОМАН'=>'Oman'][self::foldName($name)] ?? null;"""
    must(text.count(old)==1,'RETAINED_COUNTRY_ANCHOR_CHANGED');source.write_text(text.replace(old,new))
    green=run(root,['php','-r',HARNESS]);(out/'green.log').write_text(green.stdout+green.stderr);must(green.returncode==0,'COUNTRY_GREEN_FAILED')
    after=run(root,['php','-r',SNAPSHOT]);must(after.returncode==0 and after.stdout==before.stdout,'EXISTING_REFERENCE_CHANGED')
    (out/'after-references.json').write_text(after.stdout)
    text=doc.read_text()
    old="Existing country scope (Egypt and Turkey), source/record caveats,\nselector authorization, applicability and encoded model-request limits remain\nunchanged. Indexing does not authorize Thailand/Vietnam or any new data."
    new="""Supported country consistency covers Egypt, Turkey, Thailand, Maldives,
United Arab Emirates, Sri Lanka, Vietnam and Oman. This is a finite comparison
allowlist, not permission to import facts. Source/record caveats, selector
authorization, applicability and encoded model-request limits remain unchanged.
Indexing and country support do not admit any new hotel data."""
    must(text.count(old)==1,'DOC_COUNTRY_ANCHOR_CHANGED');text=text.replace(old,new)
    text=text.replace('This consumer does not widen the Egypt/Turkey admission boundary. A missing room',
        'This consumer uses the same finite supported-country checks. A missing room')
    anchor='## One ordinary data release\n'
    extra="""## Country compatibility is not hotel admission

Tourvisor snapshot country keys are explicit: Egypt 1, Turkey 4, Thailand 2,
Maldives 8, United Arab Emirates 9, Sri Lanka 12, Vietnam 16 and Oman 64.
Retained candidates use explicit English/Russian country names, never numeric
country IDs, regions or arbitrary country codes. Candidate and request countries
must agree with the independently admitted identity anchor.

The full accepted legacy-catalog → AnyTour → local-tv chain, real Library
admission reference, accepted source names and every integrity/validity check
remain mandatory. A native Tourvisor ID or a published channel post is not a
substitute for that chain. No new country automatically creates AnyTour IDs,
identity proofs, facts or approvals. Existing hotel bundles and catalogue pins
are unchanged by this compatibility extension. Tests use synthetic memory-only
records and compare every existing captured reference and fingerprint before
and after; they are not production admissions or hotel coverage.

"""
    must(text.count(anchor)==1,'DOC_INSERT_ANCHOR_CHANGED');doc.write_text(text.replace(anchor,extra+anchor))
    results={}
    for name in ['run_manager_ai_draft_context_regression.php','run_manager_ai_draft_service_regression.php','run_manager_preparation_mcp_regression.php']:
        p=run(root,['php','tests/'+name]);(out/(name+'.log')).write_text(p.stdout+p.stderr);results[name]={'exit_code':p.returncode,'last_lines':(p.stdout+p.stderr).splitlines()[-4:]};must(p.returncode==0,'EXISTING_SUITE_FAILED:'+name)
    for name in ['services/ManagerHotelKnowledge.php','tests/hotel_catalogue_checks.php']:
        must(run(root,['php','-l',name]).returncode==0,'PHP_LINT_FAILED')
    must(run(root,['git','diff','--check']).returncode==0,'DIFF_CHECK_FAILED')
    changed=subprocess.check_output(['git','diff','--name-only'],cwd=root,text=True).splitlines();must(set(changed)==set(PINS),'FINAL_SCOPE_CHANGED')
    must(knowledge=={str(p.relative_to(root)):sha(p.read_bytes()) for p in (root/'knowledge/hotels').rglob('*') if p.is_file()},'KNOWLEDGE_BYTES_CHANGED')
    (out/'change.patch').write_bytes(subprocess.check_output(['git','diff','--',*PINS],cwd=root))
    summary={'ok':True,'base':BASE,'country_count_before':2,'country_count_after':8,'real_reference_comparison':json.loads(before.stdout),'knowledge_files_unchanged':len(knowledge),'red_expected':True,'green_log':green.stdout.strip(),'existing_suites':results,'server_calls':0,'supplier_calls':0,'production_writes':0,'hotel_admissions':0,'files':[]}
    for path in PINS:
        raw=(root/path).read_bytes();dest=out/path;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(raw)
        request=out/'blob-request.json';request.write_text(json.dumps({'content':raw.decode(),'encoding':'utf-8'}))
        result=json.loads(subprocess.check_output(['gh','api','repos/pyatkoff/max-search-bot/git/blobs','--method','POST','--input',str(request)],text=True,timeout=30))
        must(result['sha']==blob(raw),'REMOTE_BLOB_MISMATCH')
        summary['files'].append({'path':path,'blob_sha':result['sha'],'sha256':sha(raw),'bytes':len(raw)})
    (out/'blob-request.json').unlink()
    (out/'summary.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n')
    print('KNOWLEDGE_COUNTRY_SOURCE='+json.dumps(summary,ensure_ascii=False,separators=(',',':')))
if __name__=='__main__':main()
