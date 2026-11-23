<?php
declare(strict_types=1);
const EXPECTED_INSTALLED_SHA='bd8e248f7138ea75cb9fda2dcf44beb631c633a5';
const DIAGNOSTIC_OPERATION='diagnose78929-current-receipts-readonly';
ini_set('display_errors','0');ini_set('log_errors','0');
function insist(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function diagnostic78929_keys(array $value):array{
 insist(count($value)<=100,'json_key_count');$out=[];
 foreach(array_keys($value) as $key){$s=(string)$key;$out[]=preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,79}\z/D',$s)?$s:'sha256:'.hash('sha256',$s);}
 sort($out,SORT_STRING);return $out;
}
function diagnostic78929_project(array $row,array $expected,array $observations):array{
 insist((int)$row['publication_id']===$expected['publication_id']&&(int)$row['version_id']===$expected['version_id'],'exact_current_version_changed');
 insist($row['project_key']==='anytour'&&$row['platform']===$expected['platform']&&(string)$row['channel_id']===$expected['channel_id']&&(string)$row['external_message_id']===$expected['message_id'],'exact_identity_changed');
 insist(strlen((string)$row['receipt_json'])<=262144&&strlen((string)$row['snapshot_json'])<=262144,'json_size_bound');
 $receipt=json_decode($row['receipt_json'],true,32,JSON_THROW_ON_ERROR);$snapshot=json_decode($row['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
 insist(is_array($receipt)&&is_array($snapshot),'json_shape');$metadata=$snapshot['metadata']??[];insist(is_array($metadata),'metadata_shape');
 $event=$metadata['source_event_id']??null;insist($event===null||is_string($event),'event_type');insist($event===null||strlen($event)<=4096,'event_size');
 insist(count($observations)<=1,'observation_ambiguous');
 $flags=[];foreach(['button_verified','content_preserved','verified'] as $key){$v=$receipt[$key]??null;$flags[$key]=['present'=>array_key_exists($key,$receipt),'type'=>gettype($v),'strict_true'=>$v===true,'nonempty'=>!empty($receipt[$key])];}
 $predicate=!empty($receipt['button_verified'])&&(!empty($receipt['content_preserved'])||!empty($receipt['verified']));
 $textHash=hash('sha256',(string)($snapshot['text']??''));$postHash=hash('sha256',(string)($snapshot['post_url']??''));
 return ['hotel_id'=>78929,'platform'=>$expected['platform'],'publication_id'=>$expected['publication_id'],'version_id'=>$expected['version_id'],
 'state_is_sent'=>$row['state']==='sent','state_sha256'=>hash('sha256',(string)$row['state']),'attempt_count'=>(int)$row['attempt_count'],'write_attempts'=>(int)$row['write_attempts'],
 'project_active'=>(int)$row['project_active']===1,'channel_enabled'=>(int)$row['channel_enabled']===1,'source_active'=>(int)$row['source_active']===1,
 'source_channel_matches'=>$row['source_channel']===$expected['platform'],'source_handling_allowed'=>in_array($row['handling_mode'],['manager','ask'],true),
 'receipt_bytes'=>strlen($row['receipt_json']),'receipt_sha256'=>hash('sha256',$row['receipt_json']),'receipt_keys'=>diagnostic78929_keys($receipt),'receipt_flags'=>$flags,
 'snapshot_sha256'=>hash('sha256',$row['snapshot_json']),'metadata_keys'=>diagnostic78929_keys($metadata),'metadata_sha256'=>hash('sha256',json_encode($metadata,JSON_THROW_ON_ERROR)),
 'text_sha256'=>$textHash,'expected_text_matches'=>$textHash===$expected['text_sha256'],'post_url_sha256'=>$postHash,'expected_post_url_matches'=>$postHash===hash('sha256',$expected['post_url']),
 'source_event_present'=>is_string($event)&&$event!=='','source_event_sha256'=>is_string($event)?hash('sha256',$event):null,
 'source_event_observation_count'=>count($observations),'source_event_observation_version'=>count($observations)===1?(int)$observations[0]['version_id']:null,
 'source_event_observation_matches_current'=>count($observations)===1&&(int)$observations[0]['version_id']===$expected['version_id'],
 'observed_original_predicate'=>$predicate,'current_button_verified'=>false,'provider_read_performed'=>false];
}
$out=['operation'=>DIAGNOSTIC_OPERATION,'mode'=>'receipt_diagnostic_only','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0,'feed_writes'=>0,'current_button_verified'=>false,'rows'=>[]];
try {
    if(PHP_SAPI!=='cli'||trim((string)shell_exec('git rev-parse HEAD'))!==EXPECTED_INSTALLED_SHA)throw new RuntimeException('installed_source_changed');
    // Inspect only the unchanged, previously accepted publishing surface.
    $accepted=[
        "integrations/ChannelPostApiTransport.php"=>"300634d77cb9fddf51adf9869974f956cca6e3f9",
        "migrations/033_channel_post_context.sql"=>"0530458030a4bfe6b6cc55d1e5e441745c29b947",
        "migrations/036_channel_post_member_delivery.sql"=>"1bda463e52b33130ad12752ddef4ee5c92699c05",
        "migrations/040_channel_post_reconciliation.sql"=>"0f24b838eacd45333c5ade9a2991d060488c6035",
        "services/ChannelPostAdminService.php"=>"b80eefc801054bfe6fdb58ec345ecdf687b2e89a",
        "services/ChannelPostAutomationService.php"=>"c6386e155d42bc83970edc95f35b3ebeb5140533",
        "services/ChannelPostButtonTransport.php"=>"7b6711f1de63000346d8e5789f2dbb7f725535b8",
        "services/ChannelPostEntryService.php"=>"1982ff7368763edf8b7238c3142e3cc28579e991",
        "services/ChannelPostMaxAlbumRecovery.php"=>"de266cd068587cc3b2ff83f308a966ea66887a04",
        "services/ChannelPostMediaService.php"=>"f794efd0f6f24df916e18909ff6281b0bc928433",
        "services/ChannelPostMemberDeliveryService.php"=>"20c1d7984898b2a5996a5f9e20e4d2d8c20b4778",
        "services/ChannelPostReconciliationService.php"=>"1fa260e9720a8421227ecbe740bfbe33a42e398d",
        "services/ChannelPostReconciliationState.php"=>"d9583ceca80d1424a44ade3561c21282a23a52f0",
        "services/ChannelPostRegistryService.php"=>"cf42a44257d1a58d1ff516678615cf9d01f45b55",
        "services/ChannelPostRuntime.php"=>"2f1dd9b77474fdbaa92f0402f3b0aa170c595474",
        "services/ChannelPostTelegramAlbum.php"=>"faa1a14bc279a4af33936282126bf0d46e343040",
        "services/ChannelPostWebhookConfigService.php"=>"d5fdcb056088f1e3a541b4046325d97eef6417d9",
        "services/ConversationDb.php"=>"bb6281faf55aca7beeae7f8a57389d19751936f7",
        "services/MaxTlsConfig.php"=>"032c0211ee3f39c6f0d27c669abd5b467e321dea",
        "services/MaxTransport.php"=>"2cb4f8786b4704a8e62a3a9c40dc2cc73dad6159",
        "services/ProjectConfig.php"=>"63c17c8103412f8cff8cdcc4bbb062e744106af9",
        "services/ProjectConfiguration.php"=>"07019d473c588e3386ace42977db93092e8112e3",
    ];
    foreach($accepted as $path=>$blob)if(trim((string)shell_exec('git hash-object -- '.escapeshellarg($path)))!==$blob)throw new RuntimeException('publishing_surface_changed');
    ob_start();try {
        require getcwd().'/config.php';
        require_once getcwd().'/services/ChannelPostRuntime.php';
        require_once getcwd().'/services/ChannelPostAdminService.php';
        require_once getcwd().'/services/ChannelPostEntryService.php';
        require_once getcwd().'/services/MaxTransport.php';
        require_once getcwd().'/integrations/ChannelPostApiTransport.php';
        require_once getcwd().'/services/ChannelPostButtonTransport.php';
    } finally {ob_end_clean();}




    $expected=json_decode(base64_decode('W3siYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiNmNmYjg2Y2E0YWEyYjI0MzdlYjIyZDNlOTU3MWNkMmRmYjkxYmI5Y2VlMzRjYWY1Y2IxNmIyMDhlZjA3MGZjNCIsImNoYW5uZWxfaWQiOiItMTAwMjI2MjY2NjYxNiIsImhvdGVsX2lkIjo3ODkyOSwibWVzc2FnZV9pZCI6IjExNzkiLCJwaG90b19zaGEyNTYiOiI0YjE4ZDlkNDUwNjEwNzQyZjlhZDI1NGI1NzM1ZmY3MTkzMDZmNmQ0NjIyMmNhMTc2OWI5Zjk4NGJjNTZjMDI2IiwicGxhdGZvcm0iOiJ0ZWxlZ3JhbSIsInBvc3RfdXJsIjoiaHR0cHM6Ly90Lm1lL2FueV90b291ci8xMTc5IiwicHVibGljYXRpb25faWQiOjI1MTAsInRleHRfc2hhMjU2IjoiMDAzMTdlODQxZDRmMjdhZmRlYWJiNWJkOGNkZTk2ZDhhNTgxYWM4NjlhZjAxOTViMjY1Y2YyMDI0NDk2YjMxZCIsInZlcnNpb25faWQiOjg4MDZ9LHsiYnV0dG9uX3RleHQiOiLinIjvuI8g0KPQt9C90LDRgtGMINGB0YLQvtC40LzQvtGB0YLRjCDRgtGD0YDQsCIsImJ1dHRvbl91cmxfc2hhMjU2IjoiMThmOGIyMTFmMDIyNjU5ZDc1M2RkODM2YmVhYjJkNjRlMGYxNzk5OGJjNThjNGZkMWYzMmE3YWE2OWIyMWIzOSIsImNoYW5uZWxfaWQiOiItODA0OTIyNDQyMDk2ODMiLCJob3RlbF9pZCI6Nzg5MjksIm1lc3NhZ2VfaWQiOiJtaWQuZmZmZmI2Y2FmMDYwNmZlZDAxYTFlY2I1ODQ2NDZiN2IiLCJwaG90b19zaGEyNTYiOiI0YjE4ZDlkNDUwNjEwNzQyZjlhZDI1NGI1NzM1ZmY3MTkzMDZmNmQ0NjIyMmNhMTc2OWI5Zjk4NGJjNTZjMDI2IiwicGxhdGZvcm0iOiJtYXgiLCJwb3N0X3VybCI6Imh0dHBzOi8vbWF4LnJ1L2NoYW5uZWxfYW55dG91ci9BYUhzdFlSa2EzcyIsInB1YmxpY2F0aW9uX2lkIjoyNTIwLCJ0ZXh0X3NoYTI1NiI6IjAwMzE3ZTg0MWQ0ZjI3YWZkZWFiYjViZDhjZGU5NmQ4YTU4MWFjODY5YWYwMTk1YjI2NWNmMjAyNDQ5NmIzMWQiLCJ2ZXJzaW9uX2lkIjo4ODA1fV0=',true),true,32,JSON_THROW_ON_ERROR);
    insist(count($expected)===2&&array_column($expected,'publication_id')===[2510,2520]&&array_column($expected,'version_id')===[8806,8805],'diagnostic_scope');
    $registry=ConversationDb::connection();$registry->exec('SET TRANSACTION READ ONLY');$registry->beginTransaction();
    $sql='SELECT pp.id AS publication_id,pp.external_message_id,pc.platform,pc.channel_id,p.project_key,p.is_active AS project_active,pc.is_enabled AS channel_enabled,s.is_active AS source_active,s.channel AS source_channel,s.handling_mode,v.id AS version_id,v.state,v.attempt_count,v.write_attempts,v.receipt_json,v.snapshot_json FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id JOIN conversation_sources s ON s.id=pp.source_id AND s.id=pc.source_id AND s.project_id=pc.project_id JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.id=? AND v.id=? AND p.project_key=? AND pc.platform=? AND pc.channel_id=? AND OCTET_LENGTH(v.receipt_json)<=262144 AND OCTET_LENGTH(v.snapshot_json)<=262144 LIMIT 2';
    foreach($expected as $e){
      $q=$registry->prepare($sql);$q->execute([$e['publication_id'],$e['version_id'],'anytour',$e['platform'],$e['channel_id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);insist(count($rows)===1,'exact_row_missing_or_ambiguous');$row=$rows[0];
      $s=json_decode($row['snapshot_json'],true,32,JSON_THROW_ON_ERROR);$event=$s['metadata']['source_event_id']??null;insist($event===null||is_string($event),'event_type');insist($event===null||strlen($event)<=4096,'event_size');$observations=[];
      if(is_string($event)&&$event!==''){$q=$registry->prepare('SELECT version_id FROM post_observations WHERE publication_id=? AND source_event_key=? LIMIT 2');$q->execute([$e['publication_id'],hash('sha256',$event)]);$observations=$q->fetchAll(PDO::FETCH_ASSOC);}
      $out['rows'][]=diagnostic78929_project($row,$e,$observations);
    }
    $registry->rollBack();$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['read_at_utc']=gmdate('c');$out['ok']=true;
}catch(Throwable $e){
 if(isset($registry)&&$registry->inTransaction())$registry->rollBack();
 $out['rows']=[];$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'bounded_receipt_diagnostic_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
