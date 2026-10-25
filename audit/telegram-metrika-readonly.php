<?php
/** Fixed-project aggregate audit. No provider calls, event creation or data writes. */
declare(strict_types=1);
error_reporting(0);
ini_set('display_errors','0');
ini_set('log_errors','0');
set_time_limit(150);
const AUDIT_ROOT='/var/www/anytoour/data/www/app.anytoour.ru';
const EXPECTED_SHA='0616a964f89e3742f381a0d7e3ef5c4e30ff2ccc';
const FROM_UTC='2026-10-17 21:00:00';
const TO_UTC='2026-10-24 21:00:00';
$r=['audit_kind'=>'telegram_metrika_mechanics','date1'=>'2026-10-18','date2'=>'2026-10-24',
    'period_timezone'=>'Europe/Moscow','from_utc'=>FROM_UTC,'to_utc_exclusive'=>TO_UTC,
    'server_writes'=>false,'advertising_writes'=>false,'business_data_writes'=>false,'token_exported'=>false,
    'database_opened'=>false,'transaction_read_only'=>false,'errors'=>[],'query_names'=>[]];
$pdo=null;$stage='runtime_identity';$bufferLevel=ob_get_level();
try{
    if(PHP_SAPI!=='cli'||realpath(AUDIT_ROOT)!==AUDIT_ROOT)throw new RuntimeException('runtime_root_mismatch');
    chdir(AUDIT_ROOT);
    $process=proc_open(['git','--no-optional-locks','-c','core.fsmonitor=false','-c','core.hooksPath=/dev/null','rev-parse','HEAD'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,AUDIT_ROOT);
    if(!is_resource($process))throw new RuntimeException('revision_check_failed');
    $installed=trim((string)stream_get_contents($pipes[1]));fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    if($exit!==0||$installed!==EXPECTED_SHA)throw new RuntimeException('installed_revision_changed');
    $r['installed_sha']=$installed;
    ob_start();require AUDIT_ROOT.'/config.php';require_once AUDIT_ROOT.'/services/ConversationDb.php';require_once AUDIT_ROOT.'/services/ProjectConfig.php';ob_end_clean();
    if(ProjectConfig::projectId()!=='anytour')throw new RuntimeException('project_mismatch');
    $r['runtime']=['project_key'=>'anytour','php_timezone'=>date_default_timezone_get(),
        'bitrix_loader_present'=>class_exists('\\Bitrix\\Main\\Loader'),
        'metrika_counter_matches'=>defined('METRIKA_COUNTER_ID')&&(string)METRIKA_COUNTER_ID==='98615635'];
    $stage='readonly_connection';$pdo=ConversationDb::connection();$r['database_opened']=true;
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    try{$pdo->exec('SET SESSION MAX_EXECUTION_TIME=5000');}catch(Throwable $ignored){try{$pdo->exec('SET SESSION max_statement_time=5');}catch(Throwable $ignored2){}}
    $pdo->beginTransaction();
    try{$readonly=$pdo->query('SELECT @@session.transaction_read_only')->fetchColumn();}catch(Throwable $ignored){$readonly=$pdo->query('SELECT @@session.tx_read_only')->fetchColumn();}
    if((string)$readonly!=='1')throw new RuntimeException('readonly_not_confirmed');
    $r['transaction_read_only']=true;
    $query=function(string $name,string $sql,array $args=[])use($pdo,&$r,&$stage):array{
        $stage=$name;if(!preg_match('/\ASELECT\b/i',$sql))throw new RuntimeException('select_required');
        if(count($r['query_names'])>=20)throw new RuntimeException('query_budget');
        $r['query_names'][]=$name;$q=$pdo->prepare($sql);$q->execute($args);return$q->fetchAll(PDO::FETCH_ASSOC);
    };
    $scope="c.project_key='anytour' AND c.channel IN ('max','telegram') AND c.is_test=0";
    $r['message_activity']=$query('message_activity',"SELECT c.channel,m.direction,m.sender_type,COUNT(*) AS messages,COUNT(DISTINCT c.id) AS conversations FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE ".$scope." AND m.created_at>=? AND m.created_at<? GROUP BY c.channel,m.direction,m.sender_type LIMIT 20",[FROM_UTC,TO_UTC]);
    $r['events']=$query('event_counts',"SELECT c.channel,e.event_type,COUNT(*) AS events,COUNT(DISTINCT c.id) AS conversations FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id WHERE ".$scope." AND e.created_at>=? AND e.created_at<? AND (e.event_type IN ('waiting_manager','manager_message','bot_started','source_handling_choice','source_handling_prompted') OR e.event_type LIKE 'metrika_%') GROUP BY c.channel,e.event_type LIMIT 100",[FROM_UTC,TO_UTC]);
    $r['start_shapes']=$query('start_shapes',"SELECT c.channel,COUNT(*) AS start_messages,COUNT(DISTINCT c.id) AS conversations,SUM(m.text REGEXP '^/start(@[A-Za-z0-9_]+)?[[:space:]]+ya[0-9]{6,}') AS ya_payloads,SUM(m.text REGEXP '^/start(@[A-Za-z0-9_]+)?[[:space:]]+[0-9]{6,}') AS numeric_payloads,SUM(m.text REGEXP '^/start(@[A-Za-z0-9_]+)?[[:space:]]+p_') AS post_payloads,SUM(m.text REGEXP '^/start(@[A-Za-z0-9_]+)?[[:space:]]+(ts_|s3_)') AS selection_payloads FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE ".$scope." AND m.direction='inbound' AND m.sender_type='customer' AND m.created_at>=? AND m.created_at<? AND m.text LIKE '/start%' GROUP BY c.channel LIMIT 2",[FROM_UTC,TO_UTC]);
    $r['customer_after_manager']=$query('customer_after_manager',"SELECT c.channel,COUNT(DISTINCT c.id) AS conversations FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE ".$scope." AND m.direction='inbound' AND m.sender_type='customer' AND m.created_at>=? AND m.created_at<? AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(m.metadata_json,'$.type')),'message') IN ('message','contact') AND EXISTS(SELECT 1 FROM messages prior WHERE prior.conversation_id=c.id AND prior.direction='outbound' AND prior.sender_type='manager' AND (prior.created_at<m.created_at OR (prior.created_at=m.created_at AND prior.id<m.id))) AND EXISTS(SELECT 1 FROM conversation_events e WHERE e.conversation_id=c.id AND e.event_type='waiting_manager' AND e.created_at<=m.created_at) GROUP BY c.channel LIMIT 2",[FROM_UTC,TO_UTC]);
    $r['active_sources']=$query('active_sources',"SELECT c.channel,COALESCE(s.source_key,'') AS source_key,COALESCE(s.handling_mode,'') AS handling_mode,COUNT(*) AS conversations FROM conversations c LEFT JOIN conversation_sources s ON s.id=c.source_id WHERE ".$scope." AND EXISTS(SELECT 1 FROM messages m WHERE m.conversation_id=c.id AND m.direction='inbound' AND m.created_at>=? AND m.created_at<?) GROUP BY c.channel,s.source_key,s.handling_mode LIMIT 100",[FROM_UTC,TO_UTC]);
    $chats=$query('active_chat_metadata',"SELECT c.channel,c.external_chat_id FROM conversations c WHERE ".$scope." AND EXISTS(SELECT 1 FROM messages m WHERE m.conversation_id=c.id AND m.direction='inbound' AND m.created_at>=? AND m.created_at<?) GROUP BY c.channel,c.external_chat_id ORDER BY c.channel,c.external_chat_id LIMIT 2001",[FROM_UTC,TO_UTC]);
    if(count($chats)>2000)throw new RuntimeException('active_chat_bound');
    $r['traffic_metadata']=[];$chatPlatforms=[];
    foreach($chats as $chat){
        $platform=(string)$chat['channel'];$id=(string)$chat['external_chat_id'];
        if(!preg_match('/\A-?[0-9]{1,30}\z/D',$id))continue;
        $chatPlatforms[$id]=$platform;
        if(!isset($r['traffic_metadata'][$platform]))$r['traffic_metadata'][$platform]=['active_chats'=>0,'traffic_files'=>0,'numeric_yclid'=>0,'invalid_nonempty_yclid'=>0,'campaign_present'=>0];
        $sum=&$r['traffic_metadata'][$platform];$sum['active_chats']++;
        $path=AUDIT_ROOT.'/traffic/'.$id.'.json';
        if(!is_link($path)&&is_file($path)&&filesize($path)<=65536){
            $data=json_decode((string)file_get_contents($path),true);
            if(is_array($data)){$sum['traffic_files']++;$yclid=trim((string)($data['yclid']??''));
                if(preg_match('/\A[0-9]{6,}\z/D',$yclid))$sum['numeric_yclid']++;
                elseif($yclid!=='')$sum['invalid_nonempty_yclid']++;
                if(trim((string)($data['campaign_id']??''))!=='')$sum['campaign_present']++;
            }
        }
        unset($sum);
    }
    $r['channel_outbox']=$query('channel_outbox',"SELECT c.channel,o.target,o.transport_target,o.state,COUNT(*) AS events,COUNT(DISTINCT o.conversation_id) AS conversations FROM max_channel_conversion_outbox o JOIN conversations c ON c.id=o.conversation_id WHERE ".$scope." AND o.event_unix>=? AND o.event_unix<? GROUP BY c.channel,o.target,o.transport_target,o.state LIMIT 80",[strtotime(FROM_UTC.' UTC'),strtotime(TO_UTC.' UTC')]);
    $r['channel_settings']=$query('channel_settings',"SELECT project_key,mode,counter_id,goal_set FROM max_channel_attribution_settings WHERE project_key='anytour' LIMIT 1");
    $r['legacy_queue']=['queued_log'=>[],'log_complete'=>true,'pending'=>[]];
    $log=AUDIT_ROOT.'/metrika_events.log';
    if(!is_link($log)&&is_file($log)){
        $fp=fopen($log,'rb');$bytes=filesize($log);$r['legacy_queue']['log_exists']=true;
        if($bytes>8*1024*1024){fseek($fp,-8*1024*1024,SEEK_END);fgets($fp);$r['legacy_queue']['log_complete']=false;}
        $lines=0;$counts=[];
        while(($line=fgets($fp,65537))!==false){
            if(++$lines>100000){$r['legacy_queue']['log_complete']=false;break;}
            $pos=strpos($line,'--- ');if($pos===false)continue;
            $stamp=DateTimeImmutable::createFromFormat('!d.m.Y H:i:s',substr($line,0,$pos),new DateTimeZone(date_default_timezone_get()));
            if(!$stamp)continue;$unix=$stamp->getTimestamp();
            if($unix<strtotime(FROM_UTC.' UTC')||$unix>=strtotime(TO_UTC.' UTC'))continue;
            $item=json_decode(substr($line,$pos+4),true);if(!is_array($item))continue;
            $platform=$chatPlatforms[(string)($item['chat_id']??'')]??null;
            $target=(string)($item['target']??'');if(!$platform||!preg_match('/\A[A-Za-z0-9_.:-]{1,80}\z/D',$target))continue;
            $key=$platform.'|'.$target;if(!isset($counts[$key]))$counts[$key]=['channel'=>$platform,'target'=>$target,'events'=>0];$counts[$key]['events']++;
        }fclose($fp);$r['legacy_queue']['queued_log']=array_values($counts);
    }
    foreach(['metrika_offline_queue.csv','metrika_offline_processing.csv'] as $name){
        $path=AUDIT_ROOT.'/'.$name;$info=['file'=>$name,'exists'=>is_file($path),'rows'=>0,'targets'=>[],'complete'=>true];
        if(!is_link($path)&&is_file($path)&&filesize($path)<=4*1024*1024){
            $fp=fopen($path,'rb');while(($line=fgetcsv($fp))!==false){$target=(string)($line[1]??'');if($target==='Target'||$target==='')continue;if(++$info['rows']>10000){$info['complete']=false;break;}if(preg_match('/\A[A-Za-z0-9_.:-]{1,80}\z/D',$target))$info['targets'][$target]=($info['targets'][$target]??0)+1;}fclose($fp);
        }elseif(is_file($path))$info['complete']=false;
        $r['legacy_queue']['pending'][]=$info;
    }
    $pdo->rollBack();$r['status']='COLLECTED';
}catch(Throwable $e){
    while(ob_get_level()>$bufferLevel)ob_end_clean();
    if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    $safe=['runtime_root_mismatch','revision_check_failed','installed_revision_changed','project_mismatch','readonly_not_confirmed','select_required','query_budget','active_chat_bound'];
    $r['errors'][]=['stage'=>$stage,'code'=>in_array($e->getMessage(),$safe,true)?$e->getMessage():'audit_stage_failed','sqlstate'=>$e instanceof PDOException?$e->getCode():null];
    $r['status']='READ_FAILED';
}
$r['finished_at_utc']=gmdate('c');
echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
