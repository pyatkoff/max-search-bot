<?php
declare(strict_types=1);
// Existing journal and lock, read only; held until this existing writer exits.
$extensionMonitorLock=null;
$extensionMonitorNeed=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
try{
 $monitorJournal='/var/www/anytoour/data/channel-post-operations/monitor-price-after-collection-v1.jsonl';
 $extensionMonitorNeed(is_file($monitorJournal)&&!is_link($monitorJournal),'monitor_journal_unavailable');
 $extensionMonitorLock=fopen($monitorJournal,'rb');
 $extensionMonitorNeed($extensionMonitorLock!==false&&flock($extensionMonitorLock,LOCK_EX|LOCK_NB),'monitor_already_running');
 $monitorRaw=stream_get_contents($extensionMonitorLock);
 $extensionMonitorNeed(is_string($monitorRaw)&&$monitorRaw!==''&&str_ends_with($monitorRaw,"\n")&&strlen($monitorRaw)<=100000000,'monitor_journal_incomplete');
 $monitorLines=explode("\n",rtrim($monitorRaw,"\n"));
 $monitorState=json_decode(end($monitorLines),true,64,JSON_THROW_ON_ERROR);
 $extensionMonitorNeed(is_array($monitorState)&&array_key_exists('pending',$monitorState)&&$monitorState['pending']===null,'monitor_pending_operation_stop');
 unset($monitorRaw,$monitorLines,$monitorState);
 register_shutdown_function(static function()use(&$extensionMonitorLock):void{if(is_resource($extensionMonitorLock)){flock($extensionMonitorLock,LOCK_UN);fclose($extensionMonitorLock);}});
}catch(Throwable $e){
 echo json_encode(['operation'=>'commercial-channel-hotel-feeds-20261113-update10','mode'=>'publish_three_static_public_direct_yml_feeds','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0,'files'=>[],'error'=>preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'monitor_guard_failed'],JSON_THROW_ON_ERROR),PHP_EOL;
 exit(2);
}
