<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/AdvertisingVisitRepository.php';
require_once dirname(__DIR__,2).'/services/MaxChannelConversionOutbox.php';
function projectFunnelNativeObservation(int $now):array{
    return ['visit_id'=>'max1_'.str_repeat('a',64),'visit_at_unix'=>$now,'observed_at_unix'=>$now,'external_user_id'=>'7001','identity_status'=>'verified','client_id'=>'','yclid'=>'18446744073709551614','campaign_id'=>'123','region_id'=>'213','post_ref'=>'service-display','offer_ref'=>'as39-search-v1-service-display',
        'context_json'=>json_encode(['post_url'=>'https://max.ru/channel_appleservice39ru/AaG9bRUxHcg','post_title'=>'Reviewed display service','profile_sha256'=>str_repeat('b',64)],JSON_UNESCAPED_SLASHES)];
}
function projectFunnelNativeAssertions(PDO $pdo,callable $check):void{
    $scope=['project_key'=>'funnel_fixture','source_key'=>'max-miniapp','platform'=>'max','counter_id'=>''];$now=time();$row=projectFunnelNativeObservation($now);$repo=new AdvertisingVisitRepository($pdo);
    $first=$repo->recordLaunch($scope,$row,$now);$check('unbound native launch keeps a real empty counter',$first['cursor']==='1');
    $again=$repo->recordLaunch($scope,$row,$now+1);$check('exact native replay reuses original cursor',$again['reused']&&$again['cursor']==='1');
    // Simulate the losing writer's page after its earlier empty read: cursor 2
    // must not append the same launch after the shared scope lock is acquired.
    $page=AdvertisingVisitContract::page(['schema_version'=>2]+$scope+['after_cursor'=>'1','next_cursor'=>'2','observations'=>[['source_cursor'=>'2']+$row]],$scope,$now+1);
    $r=$repo->importPage($page,$now+1,true);$check('locked recheck prevents the concurrent next-cursor duplicate',$r['inserted']===0&&$r['cursor']==='1'&&$repo->checkpoint($scope,true)['cursor']==='1');
    $row['client_id']='17929400001234567890';$enriched=$repo->recordLaunch($scope,$row,$now+2);$check('native late CID appends a separate immutable enrichment',$enriched['cursor']==='2');
    $q=$pdo->prepare('SELECT post_ref,offer_ref,client_id,context_json FROM advertising_visit_observations WHERE project_key=? ORDER BY id');$q->execute(['funnel_fixture']);$saved=$q->fetchAll(PDO::FETCH_ASSOC);
    $check('native collation preserves exact post offer and context',count($saved)===2&&$saved[0]['client_id']===''&&$saved[1]['post_ref']===$row['post_ref']&&$saved[1]['offer_ref']===$row['offer_ref']&&$saved[1]['context_json']===$row['context_json']);
    $bad=$row;$bad['post_ref']='menu';$rejected=false;try{$repo->recordLaunch($scope,$bad,$now+3);}catch(RuntimeException $e){$rejected=$e->getMessage()==='advertising_launch_conflict';}$check('native visit cannot be retargeted to another post',$rejected);
    $check('unbound data cannot enter a bound legacy reader',(static function()use($repo,$scope){try{$repo->verifiedHistory($scope,'7001');}catch(InvalidArgumentException $e){return true;}return false;})());
    $policy=['project_key'=>'funnel_fixture','counter_id'=>'99900011','goals'=>['manager_request'=>'fixture_request'],'sha256'=>str_repeat('c',64)];$outbox=new MaxChannelConversionOutbox($pdo);
    $conversation=['id'=>7001,'external_user_id'=>'7001','project_key'=>'funnel_fixture','channel'=>'max'];
    $check('native company capture reuses the existing outbox',$outbox->captureProject($policy,$conversation,'manager_request',$now,['visit_id'=>$row['visit_id']]));
    $check('native queue deduplicates its original conversation stage',!$outbox->captureProject($policy,$conversation,'manager_request',$now+1,['visit_id'=>$row['visit_id']]));
    $queued=$outbox->projectRows($policy,'pending',$now)[0];$outbox->resolveProject($queued,['client_id'=>$row['client_id'],'observed_at_unix'=>$now],$now);$ready=$outbox->projectRows($policy,'ready',$now)[0];$token=$outbox->claimProject($ready,$now);
    $check('native claim admits only one sender',$token!==null&&$outbox->claimProject($ready,$now)===null);
    $outbox->recoverProject($policy,$now+301);$check('lost native upload claim becomes unknown without retry',count($outbox->projectRows($policy,'unknown',$now+301))===1&&!$outbox->projectRows($policy,'ready',$now+301));
}
