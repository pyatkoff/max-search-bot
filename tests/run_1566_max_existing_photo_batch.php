<?php
declare(strict_types=1);
$dir=dirname(__DIR__).'/tools/direct_demand/operations/max-existing-photo';
require $dir.'/editor.php';
require $dir.'/batch_input.php';
require $dir.'/batch_entry.php';

function check(bool $ok, string $case): void {if (!$ok) throw new LogicException($case);}
function refuses(callable $test, string $reason): bool {
    try {$test();return false;} catch (RuntimeException $e) {return $e->getMessage()===$reason;}
}

$raw=file_get_contents($dir.'/scope.json');
$scope=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
check(hash('sha256',$raw)===MAX_BATCH_SCOPE_SHA256,'scope digest');
$ids=[241,15693,293,408,949,1260,24909];
check(array_column($scope['rows'],'hotel_id')===$ids,'exact approved seven');
$repo=str_repeat('a',40);
$app=str_repeat('b',40);
$source=['ok'=>true,'operation'=>'max_seven_source_rights','application_sha'=>$app,'repository_sha'=>$repo,'archive_run'=>(int)$scope['archive_run_id'],
    'provider_writes'=>0,'db_writes'=>0,'bot_starts'=>0,'target_editor_rights'=>true,'channel_config_version'=>2,'rows'=>[]];
$archive=['ok'=>true,'history_complete'=>true,'price_content_shared_lease'=>true,'native_run_id'=>$scope['archive_run_id'],'production_writes'=>0,'provider_writes'=>0,'rows'=>[]];
foreach ($scope['rows'] as $row) {
    $archive['rows'][]=['mid'=>$row['target_mid'],'url'=>$row['target_url'],'published_ms'=>$row['target_ms'],'fingerprint'=>$row['target_fingerprint'],
        'registered'=>false,'media'=>['image'],'keyboard_present'=>false,'linked_message'=>false];
    $obs=array_intersect_key($row,array_flip(['hotel_id','source_id','source_mid','target_mid','target_url']));
    $obs+=['source_catalog_id'=>$row['hotel_id'],'native_source_photo_count'=>1,'source_version'=>1000+$row['source_id'],'blockers'=>[]];
    foreach (['source_content_sha256','source_photo_sha256','source_caption_sha256'] as $key) $obs[$key]=hash('sha256','fixture-'.$row['hotel_id'].$key);
    foreach (['source_caption_nonempty','native_source_photo_reusable','source_caption_matches_registry','source_photo_matches_registry','source_hotel_id_matches',
        'source_single_registry_button','source_button_resolves_to_hotel','target_matches_native_archive'] as $key) $obs[$key]=true;
    foreach (['target_occupied','source_held','target_or_hotel_registry_conflicts'] as $key) $obs[$key]=0;
    $source['rows'][]=$obs;
}
$encode=static fn(array $data): string=>maxEditJson($data);
$fixtureArchive=$encode($archive);
$scope['archive_registry_sha256']=hash('sha256',$fixtureArchive);
$fixtureScope=$encode($scope);
$compile=static fn(array $native): array=>maxBatchInput($fixtureScope,hash('sha256',$fixtureScope),$fixtureArchive,
    $encode($native),hash('sha256',$encode($native)),$repo,$app);
$ready=$compile($source);
check(array_keys($ready['ready'])===$ids && empty($ready['held']),'seven fixture sources');
$albumScope=$scope;
$albumRows=$archive;
$albumSource=$source;
$albumScope['rows'][0]['target_photo_count']=3;
$albumScope['native_source']['operation']='max_commercial_album_sources_read_only';
$albumSource['operation']='max_commercial_album_sources_read_only';
$albumRows['rows'][0]['media']=['image','image','image'];
$compileAlbum=static function (array $s, array $a, array $n) use ($encode,$repo,$app): array {
    $archiveData=$encode($a);$nativeData=$encode($n);
    $s['archive_registry_sha256']=hash('sha256',$archiveData);
    $rawScope=$encode($s);
    return maxBatchInput($rawScope,hash('sha256',$rawScope),$archiveData,$nativeData,hash('sha256',$nativeData),$repo,$app);
};
$album=$compileAlbum($albumScope,$albumRows,$albumSource);
check(($album['ready'][241]['plan']['target_photo_count']??null)===3,'pin exact reviewed historical album');
$albumRows['rows'][0]['media'][1]='video';
check($compileAlbum($albumScope,$albumRows,$albumSource)['held']===[241=>'target_changed_or_occupied'],'reject a mixed-media target');
$albumScope['native_source']['operation']='max_seven_source_rights';
$albumSource['operation']='max_seven_source_rights';
check($compileAlbum($albumScope,$albumRows,$albumSource)['held']===[241=>'unapproved_target_album'],'ordinary approval never enables albums');

$current=$source['rows'][0];
$approval=$ready['ready'][241];
maxBatchVerifyCurrent($approval,$current,2);
foreach (['source_version'=>777,'source_content_sha256'=>str_repeat('0',64),'source_caption_sha256'=>str_repeat('1',64),'source_photo_sha256'=>str_repeat('2',64)] as $key=>$value) {
    $bad=array_replace($current,[$key=>$value]);check(refuses(fn()=>maxBatchVerifyCurrent($approval,$bad,2),'changed_since_native_review'),$key);
}
foreach (['target_occupied','source_held','target_or_hotel_registry_conflicts'] as $key) {
    $bad=$source;$bad['rows'][0][$key]=1;$out=$compile($bad);
    check(count($out['ready'])===6 && $out['held']===[241=>'source_or_target_busy'],$key);
}
foreach (['source_button_resolves_to_hotel','source_single_registry_button','source_photo_matches_registry','target_matches_native_archive'] as $key) {
    $bad=$source;$bad['rows'][0][$key]=false;$out=$compile($bad);
    check(count($out['ready'])===6 && $out['held']===[241=>'missing_native_proof'],$key);
}
$bad=$source;$bad['rows'][0]['source_catalog_id']=15693;
check($compile($bad)['held']===[241=>'different_hotel_or_source'],'wrong hotel');
$bad=$source;$bad['target_editor_rights']=false;check(refuses(fn()=>$compile($bad),'main_channel_edit_not_ready'),'rights');
check(refuses(fn()=>maxBatchVerifyCurrent($approval,$current,3),'changed_since_native_review'),'channel version');
check(!maxBatchOriginalSourceApproved(null,str_repeat('0',64),$repo,$app),'absence of original signed receipt');
check(!maxBatchOriginalSourceApproved(['run_id'=>1,'artifact_id'=>2,'json_sha256'=>str_repeat('0',64),'repository_sha'=>$repo,'application_sha'=>$app],str_repeat('1',64),$repo,$app),'wrong original bytes');
check(refuses(fn()=>maxBatchInput($fixtureScope,hash('sha256',$fixtureScope),$fixtureArchive.' ',$encode($source),hash('sha256',$encode($source)),$repo,$app),'native_archive_digest_changed'),'archive preimage');

// Exercise the genuine fixed source bytes and seven rows selected from the independently authenticated target archive.
$nativeBytes=file_get_contents($dir.'/native-source.json');
$targetBytes=file_get_contents($dir.'/native-targets.json');
$realScope=json_decode($raw,true);
$binding=$realScope['native_source'];
$actual=maxBatchLoad($dir.'/native-targets.json',$dir.'/native-source.json',$binding['json_sha256'],$binding['repository_sha'],$binding['application_sha']);
check(array_keys($actual['ready'])===$ids && !$actual['held'] && $actual['provider_writes']===0,'all genuine legacy sources matched');
$measured=json_decode($nativeBytes,true)['rows'];
foreach ($measured as $n) {
    maxBatchVerifyCurrent($actual['ready'][$n['hotel_id']],$n,2);
    foreach (['source_version'=>$n['source_version']+1,'source_content_sha256'=>str_repeat('5',64),'source_name_in_native_caption'=>false,
        'source_button_count'=>2,'source_button_resolves_publication'=>false,'source_one_button_matches_url'=>false,'blockers'=>[...$n['blockers'],'unexpected_extra_blocker']] as $key=>$value) {
        $other=array_replace($n,[$key=>$value]);
        check(refuses(fn()=>maxBatchVerifyCurrent($actual['ready'][$n['hotel_id']],$other,2),'legacy_publication_identity_changed'),$key);
    }
    $other=array_replace($n,['source_catalog_id'=>999999]);
    check(refuses(fn()=>maxBatchVerifyCurrent($actual['ready'][$n['hotel_id']],$other,2),'different_hotel_or_source'),'legacy cannot replace another catalogue hotel');
}
$temp=tempnam(sys_get_temp_dir(),'max-reviewed-plan');
try {
    foreach ($ready['ready'] as $a) {
        $bytes=maxEditJson($a['plan']);file_put_contents($temp,$bytes);maxEditPlan($temp,$a['sha256'],$a);
        check(refuses(fn()=>maxEditPlan($temp,$a['sha256']),'reviewed_hotel_or_old_target_invalid'),'legacy scope unchanged');
        $a['plan']['caption'].=' 100 ₽';$bytes=maxEditJson($a['plan']);$a['sha256']=hash('sha256',$bytes);file_put_contents($temp,$bytes);
        check(refuses(fn()=>maxEditPlan($temp,$a['sha256'],$a),'reviewed_caption_invalid'),'PRICE cannot enter CONTENT');
    }
    foreach ([
        [['editor.php', '--prepare-check', $temp, $approval['sha256'], $app], null],
        [['editor.php', '--prepare-check', $temp, $approval['sha256'], $app, str_repeat('a',64)], $approval],
    ] as [$arguments, $batchApproval]) {
        ob_start();
        $status=maxEditRun($arguments,$batchApproval);
        $receipt=json_decode((string)ob_get_clean(),true,16,JSON_THROW_ON_ERROR);
        check($status===2 && $receipt['reason']==='exact_mode_plan_and_release_required',
            'prepare-only mode requires its approved row and accepts no write checkpoint');
    }
    $log=fopen($temp,'w+b');
    $calls=0;$reads=0;$p=$approval['plan'];
    $proof=['target_ms'=>$p['target_ms'],'target_url'=>$p['target_url'],'source_photo_id'=>'19'];
    $send=static function() use (&$calls): array { ++$calls;return ['success'=>false,'http'=>0]; };
    $read=static function($mid,$channel) use (&$reads,$p):array {
        ++$reads;check($mid===$p['target_mid'] && $channel===MAIN_MAX,'same historical MID');
        return ['message'=>['timestamp'=>$p['target_ms']],'snapshot'=>['post_url'=>$p['target_url'],'text'=>$p['caption'],'attachments'=>[['provider'=>'max','type'=>'image','photo_id'=>'19']]]];
    };
    maxEditBodyOnce($log,$p,$proof,$send,$read);
    check($calls===1 && $reads===1,'one edit and a matching readback after lost reply');
    $wrong=function($mid,$channel) use ($read) { $snap=$read($mid,$channel);$snap['snapshot']['attachments'][0]['photo_id']='20';return $snap; };
    check(refuses(fn()=>maxEditBodyOnce($log,$p,$proof,$send,$wrong),'updated_text_photo_or_history_unconfirmed'),'ambiguous edit held');
    check($calls===2 && $reads===2,'mismatched readback does not replay');
    fclose($log);
} finally {unlink($temp);}
echo "MAX seven input and no-replay source regression passed\n";
