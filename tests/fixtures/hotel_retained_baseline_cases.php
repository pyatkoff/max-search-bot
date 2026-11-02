<?php
declare(strict_types=1);

// Included by the existing SQLite/native-MySQL suite. No provider or archive access.
$bundle=hrBundle([[],['authority'=>'ledger','records'=>[hrRecord('ledger')]]],[],true);
$package=hrInputs($bundle)->package();$retained=$package['retained'];
hsCheck($retained['counts']['claims']===2&&$retained['counts']['sources']===2&&count($retained['model_records'])===2,'direct and ledger earlier facts have independent authority');
$fact=$retained['model_records'][0];$literal=json_decode($fact['answer'],true,64,JSON_THROW_ON_ERROR);
hsCheck(isset($literal['id'])&&$literal['valid_at_travel']==='unverified'&&$literal['scope']['season']==='SYNTHETIC','literal original fact keeps validity and scope qualifiers');
hsCheck(!isset($fact['packet_sha256'],$fact['receipt_sha256'])&&$retained['new_acceptance_credit']===0,'early facts get no arbitrary continuous receipt or new credit');
$page=HotelRetainedBaseline::page($retained,$bundle[1],'candidate/claims',0,1);
$next=HotelRetainedBaseline::page($retained,$bundle[1],'candidate/claims',$page['next_offset'],1);
hsCheck(count($page['records'])===1&&!$page['complete']&&count($next['records'])===1&&$next['complete'],'bounded reader paginates without dropping claims');
$location=$page['records'][0]['location'];
hsCheck($location['sha256']===hash('sha256',$bundle[1][$location['file']])
    &&substr($bundle[1][$location['file']],$location['offset'],$location['length'])===$page['records'][0]['original_json'],'readback pins original shard bytes and byte offsets');
$sources=HotelRetainedBaseline::page($retained,$bundle[1],'candidate/sources');
hsCheck(count($sources['records'])===2&&str_contains(HotelServerInputs::json($sources),'unclaimed-source'),'source without claim remains readable');
hsCheck(HotelRetainedBaseline::document($retained,$bundle[1],'candidate/source-files.json')===$bundle[1]['candidate/source-files.json'],'JSON document returned byte for byte');
hsReject(fn()=>HotelRetainedBaseline::page($retained,$bundle[1],'candidate/claims',0,101),'oversized page rejected');
hsReject(fn()=>HotelRetainedBaseline::document($retained,$bundle[1],'../manifest.json'),'private reader cannot escape pinned files');

// Raw numbers, unknown fields and all original variants survive without re-encoding.
$raw=hrJson(hrRecord('variants'));$raw=substr($raw,0,-1).',"unknown":{"big":123456789012345678901234567890,"float":1.0,"null":null,"marker":"SYNTHETIC_PRIVATE_CANARY"}}';
$variantBundle=hrBundle([['records'=>[$raw,hrRecord('variants','other-question')]]]);
$variantInputs=hrInputs($variantBundle)->package();$vr=$variantInputs['retained'];
$row=HotelRetainedBaseline::page($vr,$variantBundle[1],'candidate/claims')['records'][0];
hsCheck(str_contains($row['original_json'],$raw)&&$row['authority']['variants'][0]['original_record_sha256']===hash('sha256',$raw),'exact raw float bigint unknown fields and digest preserved');
hsCheck($vr['counts']['claim_variants']===2&&$vr['model_records']===[],'claim-level acceptance does not select one of multiple variants');
$overlap=hsScope('variants');$overlap['question']='other-question';$overlap['source']='different-source';
hsReject(fn()=>HotelRetainedBaseline::assertScope($vr,$overlap),'source change cannot evade uncertain variant overlap','retained_scope_unresolved');

$derived=hrRecord('derived');unset($derived['question_id']);
$missing=hrRecord('missing-source');$missing['source_ids']=['absent-source'];
$unknown=hrRecord('unknown-fields');$unknown['future_qualifier']=['keep'=>true];
$large=hrRecord('large-projection');$large['text']=str_repeat('x',12000);$large['source_ids']=array_map(static fn(int $i):string=>'source-'.$i,range(1,30));
$heldBundle=hrBundle([
    ['classification'=>'inherited-baseline','records'=>[hrRecord('inherited')]],
    ['classification'=>'acceptance-unresolved','records'=>[hrRecord('unresolved')]],
    ['records'=>[$derived],'derived'=>['question'=>'derived-question']],
    ['records'=>[$missing],'missing_sources'=>['absent-source']],
    ['records'=>[$unknown]],['records'=>[$large]],
]);
$hr=hrInputs($heldBundle)->package()['retained'];
hsCheck($hr['model_records']===[]&&$hr['counts']['claims']===6,'inherited unresolved derived missing-source unknown and oversized facts stay raw');
$derivedScope=hsScope('derived');$derivedScope['question']='another-question';$derivedScope['source']='another-source';
hsReject(fn()=>HotelRetainedBaseline::assertScope($hr,$derivedScope),'missing original question holds partial overlap','retained_scope_unresolved');
HotelRetainedBaseline::assertScope($hr,hsScope('unrelated'));
hsCheck(true,'known hotel mismatch permits unrelated scope');
hsCheck(in_array('model_projection_bound',array_column($hr['holds'],'reason'),true),'expanded model budget enforced before materialization');

$duplicate=hrBundle([['records'=>[hrRecord('same')]],['records'=>[hrRecord('same')]],['records'=>[hrRecord('same')]]]);
$dr=hrInputs($duplicate)->package()['retained'];
hsCheck(count(HotelRetainedBaseline::page($dr,$duplicate[1],'candidate/claims')['records'])===3&&$dr['model_records']===[],'duplicate scope keeps all claims without arbitrary winner or whole-reader failure');
$conflict=hsScope('same');$conflict['question']='old-question';$conflict['source']='new-source';
hsReject(fn()=>HotelRetainedBaseline::assertScope($dr,$conflict),'third claim cannot re-admit ambiguous scope','retained_scope_unresolved');
$conflicting=hrRecord('mixed');$conflicting['text']='Synthetic conflicting answer';$conflicting['future_qualifier']=true;
$mixed=hrBundle([['records'=>[hrRecord('mixed')]],['records'=>[$conflicting]]]);
$mr=hrInputs($mixed)->package()['retained'];
hsCheck($mr['model_records']===[]&&count(HotelRetainedBaseline::page($mr,$mixed[1],'candidate/claims')['records'])===2,'unadmitted duplicate also prevents arbitrary positive variant selection');

$continuous=hrBundle([['classification'=>'continuous','records'=>[hrRecord('continuous'),hrRecord('continuous','unadmitted-variant')]]]);
$cr=hrInputs($continuous)->package()['retained'];$cs=hsScope('continuous');$cs['question']='unadmitted-variant';
hsCheck($cr['counts']['continuous_claims']===1&&$cr['model_records']===[],'continuous identity stays claim-level');
hsReject(fn()=>HotelRetainedBaseline::assertScope($cr,$cs),'unmapped continuous variant cannot be recollected','retained_scope_unresolved');
$continuousRead=HotelRetainedBaseline::page($cr,$continuous[1],'candidate/claims')['records'][0]['authority'];
hsCheck($continuousRead['classification']==='continuous-claim-level'&&$continuousRead['variants_receipt_assignment']===null,'raw continuous read does not invent per-variant receipts');
$cross=hrBundle([['classification'=>'continuous','records'=>[hrRecord('cross-duplicate')]],['records'=>[hrRecord('cross-duplicate')]]]);
$crossRetained=hrInputs($cross)->package()['retained'];
hsCheck($crossRetained['model_records']===[]&&$crossRetained['counts']['claims']===2,'unmapped continuous variant also prevents an early duplicate from entering model view');

$reserveBundle=hrBundle([], [['status'=>'UNKNOWN','provenance'=>['source_ref'=>'synthetic-reserve'],
    'original_value'=>['expires_at'=>'2000-01-01','country_scope'=>'ZZ','exact_question_membership'=>[['hotel_key'=>'reserved','question_id'=>'q']]]]]);
$rr=hrInputs($reserveBundle)->package()['retained'];$rs=hsScope('reserved');$rs['question']='q';
hsReject(fn()=>HotelRetainedBaseline::assertScope($rr,$rs),'expired protected reserve still holds unknown outcome','retained_scope_unresolved');
$global=hrInputs(hrBundle([], [['original_value'=>['unknown_membership'=>true]]]))->package()['retained'];
hsReject(fn()=>HotelRetainedBaseline::assertScope($global,hsScope('anything')),'unknown reserve membership fails closed','retained_scope_unresolved');

// Corrupt metadata is re-pinned synthetically so the semantic checks, not just SHA, must reject.
foreach(['NOT_ACCEPTED','WORKING_PENDING'] as $state)
    hsReject(fn()=>hrInputs(hrBundle([['state'=>$state]])),'unaccepted direct state cannot grant authority','retained_direct_acceptance_binding');
hsReject(fn()=>hrInputs(hrBundle([['null_operation'=>true]])),'three null IDs cannot create an operation binding','retained_direct_acceptance_binding');
hsReject(fn()=>hrInputs(hrBundle([['authority'=>'ledger','state'=>'PENDING']])),'ledger pending cannot grant authority','retained_ledger_acceptance_binding');
$base=hrBundle([[]]);$metaFile='semantic/earlier-explicit-accepted-facts/part-001.jsonl';
$meta=json_decode(trim($base[1][$metaFile]),true,64,JSON_THROW_ON_ERROR);
foreach(['line','shard_hash','record_hash','operation','provenance','limits','receipt'] as $mutation){
    $bad=$meta;
    if($mutation==='line')$bad['candidate_location']['line_1based']=2;
    if($mutation==='shard_hash')$bad['candidate_location']['sha256']=hash('sha256',rtrim($base[1]['candidate/claims/part-001.jsonl'],"\n"));
    if($mutation==='record_hash')$bad['variants'][0]['original_record_sha256']=str_repeat('f',64);
    if($mutation==='operation')$bad['acceptance_evidence']['direct'][0]['operation_id']='UNRELATED-OP';
    if($mutation==='provenance')$bad['variants'][0]['record_pointer']='/unrelated/record';
    if($mutation==='limits')$bad['variants'][0]['original_status_and_limits']['valid_at_travel']='verified';
    if($mutation==='receipt')$bad['assigned_continuous_receipt']=str_repeat('a',64);
    $badBundle=hrRepack($base,$metaFile,hrJson($bad)."\n");
    hsReject(fn()=>hrInputs($badBundle),'exact original metadata rejects '.$mutation);
}
$proofFile='semantic/earlier-exclusion-provenance/part-001.jsonl';$proof=json_decode(trim($base[1][$proofFile]),true,64,JSON_THROW_ON_ERROR);
$proof['claim_provenance'][0]['claim_id']='FOREIGN';
hsReject(fn()=>hrInputs(hrRepack($base,$proofFile,hrJson($proof)."\n")),'exclusion cannot point to foreign claim','retained_early_exclusion_provenance');
hsReject(fn()=>hrInputs(hrRepack($base,'semantic/earlier_explicit_exclusions.json',"[]\n")),'missing early exclusions rejected','retained_early_exclusion_coverage');
$tampered=$base;$tampered[1]['candidate/claims/part-001.jsonl'].=' ';
hsReject(fn()=>hrInputs($tampered),'retained byte mismatch rejected','retained_file_pin');
$tampered=$base;unset($tampered[1]['candidate/claims/part-001.jsonl']);
hsReject(fn()=>hrInputs($tampered),'missing retained shard rejected');
$tampered=$base;$tampered[1]['candidate/unlisted.json']='{}';
hsReject(fn()=>hrInputs($tampered),'unlisted retained shard rejected','retained_complete_parts_required');
$opaque=hsInputs()->package()['bytes'];$opaque['baseline']='opaque former baseline';
hsReject(fn()=>HotelServerInputs::validate(hsLegacy(),hash('sha256',hsLegacy()['receipt_bytes']),$opaque),'opaque baseline can no longer pretend to be a reader');

// Integration: original data is privately readable, qualified explicit facts reach the bounded
// same-hotel projection, and bootstrap cannot schedule an unresolved retained overlap.
$f=hsFixture();try{
    $b=hrBundle([['records'=>[hrRecord('new-0')]]]);
    $f['cycle']->bootstrap('NEW',hrInputs($b),hsPlan(),static fn():bool=>true);
    $rawRead=$f['cycle']->readRetained('NEW','candidate/claims');
    hsCheck($rawRead['records'][0]['original_json']===rtrim($b[1]['candidate/claims/part-001.jsonl'],"\n")
        &&$f['cycle']->readRetainedDocument('NEW','semantic/manifest.json')===$b[1]['semantic/manifest.json'],'cycle readback retains exact originals and documents');
    $view=$f['cycle']->readAccepted('NEW');
    hsCheck(count($view['records'])===20&&count($view)===5,'accepted reader combines nineteen mapped facts with independent earlier fact');
    hsEnable($f);$f['cycle']->tick('NEW');$request=$f['provider']->requests[0];
    hsCheck(count($request['accepted_context']['records'])===1
        &&json_decode($request['accepted_context']['records'][0]['answer'],true)['valid_at_travel']==='unverified'
        &&!isset($request['input_bytes'],$request['retained_parts']),'model receives bounded qualified fact without raw files');
}finally{hsCleanup($f);}
$f=hsFixture();try{
    $r=hrRecord('new-0');unset($r['question_id']);$b=hrBundle([['classification'=>'inherited-baseline','records'=>[$r]]]);
    hsReject(fn()=>$f['cycle']->bootstrap('NEW',hrInputs($b),hsPlan(),static fn():bool=>true),'partial overlap rejects planned recollection','retained_scope_unresolved');
    hsCheck(hsCount($f['p'],'hotel_server_campaigns')===0&&hsCount($f['p'],'hotel_server_attempts')===0&&count($f['provider']->requests)===0,'overlap fails before campaign commit or provider send');
}finally{hsCleanup($f);}
