<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ChannelPostReconciliationService.php';
require_once __DIR__.'/fixtures/channel_post_registry_fixture.php';
$checks=0;
function checkOriginal(string $name,bool $ok):void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);$checks++;}
function originalFixture():array {
 $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);createChannelPostRegistryFixture($db);
 $db->exec("INSERT INTO projects VALUES(1,'anytour','AnyTour',1); INSERT INTO conversation_sources VALUES(11,1,'tg','TG','telegram',1,'manager');");
 $r=new ChannelPostRegistryService($db);$c=$r->configureChannel(['project_key'=>'anytour','platform'=>'telegram','bot_key'=>'fixture_bot','channel_id'=>'-10099887766','source_key'=>'tg','display_name'=>'TG','bot_url'=>'https://t.me/fixture_bot','is_enabled'=>true]);
 $raw=['message_id'=>831,'date'=>1794931200,'chat'=>['id'=>'-10099887766','type'=>'channel','username'=>'fixture_channel'],'caption'=>'Old caption','photo'=>[['file_id'=>'private','file_unique_id'=>'stable','width'=>1000,'height'=>1000]]];
 $snap=ChannelPostIncomingAdapter::telegramSnapshot($raw);$snap['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($snap);
 $old=$r->registerPublication('anytour','telegram','fixture_bot','-10099887766','831',$snap);
 $priorClaim=$r->claimPublication($old['publication_id'],$old['version_id']);
 $r->finishPublication($old['publication_id'],$old['version_id'],$priorClaim['claim_token'],'sent','verified');
 $url=ChannelPostButtonTransport::buttonUrl($old);$raw['caption']='New caption';$raw['edit_date']=1794931201;$raw['reply_markup']=['inline_keyboard'=>[[['text'=>$c['button_text'],'url'=>$url]]]];
 $raw['caption_entities']=[['type'=>'text_link','offset'=>0,'length'=>3,'url'=>$url]];
 $snap=ChannelPostIncomingAdapter::telegramSnapshot($raw);$snap['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($snap);
 $op='commercial-monitor-price-h4294-'.str_repeat('a',64);$snap['metadata']['source_event_id']=$op.':telegram:831';$snap['metadata']['observation_kind']='authorized_admin_content_edit';$snap['metadata']['monitor_price_record_id']=str_repeat('a',64);
 $p=$r->registerPublication('anytour','telegram','fixture_bot','-10099887766','831',$snap);$claim=$r->claimPublication($p['publication_id'],$p['version_id']);
 $r->finishPublication($p['publication_id'],$p['version_id'],$claim['claim_token'],'requires_attention','provider_read_failed',['request_count'=>2,'write_attempts'=>0,'http_results'=>[['operation'=>'bot_identity','http'=>200,'transport_error'=>false],['operation'=>'channel_rights','http'=>0,'transport_error'=>true]]]);$p=$r->publication($p['publication_id']);
 $e=['operation'=>$op,'original_receipt_sha256'=>str_repeat('b',64),'provider_response'=>['http'=>200,'data'=>['ok'=>true,'result'=>$raw]],'plan'=>['platform'=>'telegram','publication_id'=>$p['publication_id'],'old_version_id'=>$old['version_id'],'message_id'=>'831','token'=>$p['start_payload'],'post_url'=>$p['snapshot']['post_url'],'photo_signature'=>'telegram:stable','text'=>'New caption','update_text'=>true,'monitor_price'=>['record_id'=>str_repeat('a',64)],'price_link'=>['url'=>$url,'telegram_entities'=>$raw['caption_entities']]]];
 $calls=0;$service=new ChannelPostReconciliationService($db,static function()use(&$calls){$calls++;throw new RuntimeException('provider_forbidden');});
 return [$db,$r,$service,$p,$e];
}
function originalCall($service,$p,$e,$digest='',bool $apply=false):array{return $service->confirmTelegramOriginalEdit(['project_key'=>'anytour','platform'=>'telegram','bot_key'=>'fixture_bot'],$p['publication_id'],$p['version_id'],$p['channel']['config_version'],$e,$digest,$apply);}
function originalState($db):array{$v=[];foreach(['post_versions','post_publications','post_observations','post_channels'] as $t)$v[$t]=$db->query('SELECT * FROM '.$t)->fetchAll();return $v;}
[$db,$r,$service,$p,$e]=originalFixture();$before=originalState($db);$preview=originalCall($service,$p,$e);checkOriginal('preview',$preview['ok']&&$preview['state']==='preview'&&originalState($db)===$before);
checkOriginal('ordinary retry remains rejected',!ChannelPostRegistryService::telegramReadFailureRecoveryEligible($p));
foreach(['http','ok','transport','message','channel','text','photo','keyboard','entities','event','token','planid','oldversion','record','hash','kind','state','attempts','writes','claimed','mapping','config','digest'] as $case){
 [$d,$rr,$ss,$pp,$ee]=originalFixture();$pv=originalCall($ss,$pp,$ee);$digest=$pv['confirmation_digest'];
 switch($case){
 case 'http':$ee['provider_response']['http']=500;break;
 case 'ok':$ee['provider_response']['data']['ok']=false;break;
 case 'transport':$ee['provider_response']['transport_error']=true;break;
 case 'message':$ee['provider_response']['data']['result']['message_id']=832;break;
 case 'channel':$ee['provider_response']['data']['result']['chat']['id']='-10011111';break;
 case 'text':$ee['provider_response']['data']['result']['caption']='Changed';break;
 case 'photo':$ee['provider_response']['data']['result']['photo'][0]['file_unique_id']='other';break;
 case 'keyboard':$ee['provider_response']['data']['result']['reply_markup']['inline_keyboard'][0][0]['url']='https://other';break;
 case 'entities':$ee['plan']['price_link']['telegram_entities']=[];break;
 case 'event':$ee['operation']='other';break;
 case 'token':$ee['plan']['token']='p_other';break;
 case 'planid':$ee['plan']['publication_id']++;break;
 case 'oldversion':$ee['plan']['old_version_id']=$pp['version_id'];break;
 case 'record':$ee['plan']['monitor_price']['record_id']=str_repeat('c',64);break;
 case 'hash':$ee['original_receipt_sha256']='';break;
 case 'kind':$snap=$pp['snapshot'];$snap['metadata']['observation_kind']='provider_send_response';$d->prepare('UPDATE post_versions SET snapshot_json=? WHERE id=?')->execute([json_encode($snap),$pp['version_id']]);break;
 case 'state':$d->exec("UPDATE post_versions SET state='unknown' WHERE id=".$pp['version_id']);break;
 case 'attempts':$d->exec('UPDATE post_versions SET attempt_count=2 WHERE id='.$pp['version_id']);break;
 case 'writes':$d->exec('UPDATE post_versions SET write_attempts=1 WHERE id='.$pp['version_id']);break;
 case 'claimed':$d->exec("UPDATE post_versions SET claim_token='claimed' WHERE id=".$pp['version_id']);break;
 case 'mapping':$d->exec('DELETE FROM post_observations');break;
 case 'config':$d->exec('UPDATE post_channels SET config_version=config_version+1');break;
 case 'digest':$digest=str_repeat('0',64);break;
 }
 $state=originalState($d);$answer=originalCall($ss,$pp,$ee,$digest,true);checkOriginal('negative apply '.$case,!$answer['ok']&&originalState($d)===$state);
 // A fresh preview must reject corrupted evidence too, not just the digest.
 if(!in_array($case,['config','digest'],true)){ $answer=originalCall($ss,$pp,$ee);checkOriginal('negative preview '.$case,!$answer['ok']&&originalState($d)===$state); }
}
// JSON object key order is not semantic; list order, key sets and types are.
foreach(['reordered_keys','changed_offset','changed_url','coerced_offset','extra_key','entity_order'] as $case){
 [$d,$rr,$ss,$pp,$ee]=originalFixture();$entity=$ee['plan']['price_link']['telegram_entities'][0];
 if($case==='reordered_keys')$ee['plan']['price_link']['telegram_entities'][0]=['offset'=>$entity['offset'],'url'=>$entity['url'],'length'=>$entity['length'],'type'=>$entity['type']];
 if($case==='changed_offset')$ee['plan']['price_link']['telegram_entities'][0]['offset']++;
 if($case==='changed_url')$ee['plan']['price_link']['telegram_entities'][0]['url']='https://foreign.invalid';
 if($case==='coerced_offset')$ee['plan']['price_link']['telegram_entities'][0]['offset']=(string)$entity['offset'];
 if($case==='extra_key')$ee['plan']['price_link']['telegram_entities'][0]['extra']=true;
 if($case==='entity_order'){
  $second=['type'=>'bold','offset'=>4,'length'=>3];
  $ee['plan']['price_link']['telegram_entities']=[$second,$entity];
  $ee['provider_response']['data']['result']['caption_entities']=[$entity,$second];
  $snap=$pp['snapshot'];$snap['raw_body']=$ee['provider_response']['data']['result'];$snap['markup']=[$entity,$second];
  $snap['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($snap);
  $d->prepare('UPDATE post_versions SET snapshot_json=?,content_hash=? WHERE id=?')->execute([json_encode($snap),$snap['content_hash'],$pp['version_id']]);
 }
 $state=originalState($d);$result=originalCall($ss,$pp,$ee);
 checkOriginal('entity comparison '.$case,($result['ok']===($case==='reordered_keys'))&&originalState($d)===$state);
}
foreach(['stale_expected','mapping_version','prior_photo','prior_number','prior_unknown','prior_claimed','raw_coherent_extra_button'] as $case){
 [$d,$rr,$ss,$pp,$ee]=originalFixture();
 if($case==='stale_expected')$d->exec('UPDATE post_versions SET attempt_count=2 WHERE id='.$pp['version_id']);
 if($case==='mapping_version')$d->exec('UPDATE post_observations SET version_id='.$ee['plan']['old_version_id']);
 if($case==='prior_unknown')$d->exec("UPDATE post_versions SET state='unknown' WHERE id=".$ee['plan']['old_version_id']);
 if($case==='prior_claimed')$d->exec("UPDATE post_versions SET claim_token='foreign' WHERE id=".$ee['plan']['old_version_id']);
 if($case==='prior_number')$d->exec('UPDATE post_versions SET version_number=99 WHERE id='.$ee['plan']['old_version_id']);
 if($case==='prior_photo'){$prior=$d->query('SELECT snapshot_json FROM post_versions WHERE id='.$ee['plan']['old_version_id'])->fetchColumn();$snap=json_decode($prior,true);$snap['attachments'][0]['file_unique_id']='foreign';$d->prepare('UPDATE post_versions SET snapshot_json=? WHERE id=?')->execute([json_encode($snap),$ee['plan']['old_version_id']]);}
 if($case==='raw_coherent_extra_button'){
  $ee['provider_response']['data']['result']['reply_markup']['inline_keyboard'][0][0]['callback_data']='hidden';
  $snap=$pp['snapshot'];$snap['raw_body']=$ee['provider_response']['data']['result'];$snap['keyboard']=$snap['raw_body']['reply_markup']['inline_keyboard'];
  $d->prepare('UPDATE post_versions SET snapshot_json=? WHERE id=?')->execute([json_encode($snap),$pp['version_id']]);$pp=$rr->publication($pp['publication_id']);
 }
 $state=originalState($d);$failed=false;try{$rr->confirmTelegramEditReadFailure($pp,$ee,true);}catch(Throwable $ex){$failed=true;}
 checkOriginal('registry locked negative '.$case,$failed&&originalState($d)===$state);
}
$answer=originalCall($service,$p,$e,$preview['confirmation_digest'],true);$after=originalState($db);$now=$r->publication($p['publication_id']);
checkOriginal('exact sent',$answer['ok']&&$now['state']==='sent'&&$answer['provider_calls']===0&&$answer['provider_writes']===0);
foreach(['post_publications','post_observations','post_channels'] as $t)checkOriginal('unchanged '.$t,$before[$t]===$after[$t]);
$old=$before['post_versions'][1];$new=$after['post_versions'][1];foreach(['state','error_reason','receipt_json','updated_at'] as $k)unset($old[$k],$new[$k]);checkOriginal('immutable version preserved',$old===$new);
checkOriginal('failure retained',$now['receipt']['previous_failure']['receipt_json']===$before['post_versions'][1]['receipt_json']&&$now['receipt']['previous_failure']['reason']==='provider_read_failed');
checkOriginal('no replay',!originalCall($service,$p,$e,$preview['confirmation_digest'],true)['ok']&&originalState($db)===$after);
[$d,$rr,$ss,$pp,$ee]=originalFixture();$pv=originalCall($ss,$pp,$ee);$state=originalState($d);$d->exec("CREATE TRIGGER fail_confirm BEFORE UPDATE OF state ON post_versions WHEN NEW.state='sent' BEGIN SELECT RAISE(ABORT,'injected'); END");$answer=originalCall($ss,$pp,$ee,$pv['confirmation_digest'],true);checkOriginal('failed transaction unknown no retry',$answer['state']==='unknown'&&originalState($d)===$state);
echo 'ORIGINAL_EDIT_CONFIRMATION_OK checks='.$checks.PHP_EOL;
