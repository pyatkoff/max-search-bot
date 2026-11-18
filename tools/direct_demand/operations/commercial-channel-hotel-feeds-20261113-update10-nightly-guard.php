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
 $extensionHook='/var/www/anytoour/data/www/anytoour.ru/data/monitor-price-hook.php';
 $extensionMonitorNeed(is_file($extensionHook)&&!is_link($extensionHook)&&hash_file('sha256',$extensionHook)==='1ad41a8a3a8d10632e0e6127289481675f655c7f7f9a5bbe39b698c03e73e592','monitor_hook_changed');
 // The accepted reconciliation reader bounds each complete JSONL row at 2000000 bytes.
 // Read one extra byte to prove the preceding delimiter when the journal is larger.
 $monitorStat=fstat($extensionMonitorLock);
 $extensionMonitorNeed(is_array($monitorStat)&&is_int($monitorStat['size'])&&$monitorStat['size']>0,'monitor_journal_incomplete');
 $monitorReadBytes=min($monitorStat['size'],2000001);
 $extensionMonitorNeed(fseek($extensionMonitorLock,$monitorStat['size']-$monitorReadBytes,SEEK_SET)===0,'monitor_journal_incomplete');
 $monitorRaw=stream_get_contents($extensionMonitorLock,$monitorReadBytes);
 $extensionMonitorNeed(is_string($monitorRaw)&&strlen($monitorRaw)===$monitorReadBytes&&str_ends_with($monitorRaw,"\n"),'monitor_journal_incomplete');
 $monitorTail=rtrim($monitorRaw,"\n");$monitorDelimiter=strrpos($monitorTail,"\n");
 $extensionMonitorNeed($monitorDelimiter!==false||$monitorReadBytes===$monitorStat['size'],'monitor_journal_incomplete');
 $monitorLast=$monitorDelimiter===false?$monitorTail:substr($monitorTail,$monitorDelimiter+1);
 $extensionMonitorNeed($monitorLast!==''&&strlen($monitorLast)+1<=2000000,'monitor_journal_incomplete');
 $monitorState=json_decode($monitorLast,true,64,JSON_THROW_ON_ERROR);
 $extensionMonitorNeed(is_array($monitorState)&&array_key_exists('pending',$monitorState)&&$monitorState['pending']===null,'monitor_pending_operation_stop');
 unset($monitorRaw,$monitorTail,$monitorLast,$monitorDelimiter,$monitorStat,$monitorReadBytes,$monitorState);
 register_shutdown_function(static function()use(&$extensionMonitorLock):void{if(is_resource($extensionMonitorLock)){flock($extensionMonitorLock,LOCK_UN);fclose($extensionMonitorLock);}});
}catch(Throwable $e){
 echo json_encode(['operation'=>'commercial-channel-hotel-feeds-20261113-update10','mode'=>'publish_three_static_public_direct_yml_feeds','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0,'files'=>[],'error'=>preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'monitor_guard_failed'],JSON_THROW_ON_ERROR),PHP_EOL;
 exit(2);
}
