<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
ini_set('display_errors','0');set_time_limit(60);ob_start();
$out=['ok'=>false,'server_writes'=>false,'advertising_writes'=>false,'database_writes'=>false,'database_opened'=>false,'token_exported'=>false,'raw_identifiers_exported'=>false,'campaigns'=>['714260445'=>[],'714320748'=>[]]];
try {
    $root='/var/www/anytoour/data/www/app.anytoour.ru';
    $expected='112a0ab1803879a67315a165a403e39a9bcdcf43';
    $actual=trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
    if($actual!==$expected)throw new RuntimeException('release_changed');
    $out['production_sha']=$actual;
    $expectedBlobs=['services/DialogueView.php'=>'59d6a08b70c0d544af23c146f9b38c1846cab7d4','cron_followup.php'=>'5a2de2d5fdf2132980547a7aad21525e84e55c9d','maxsearchclass.php'=>'626ea94bc04db4404bdd823b55f529e74342a242','services/FollowupQueueService.php'=>'ac9ade4ba2d51e819e6c736b2307241107f23fe0'];
    foreach($expectedBlobs as $path=>$sha){
        $body=file_get_contents($root.'/'.$path);
        $match=is_string($body)&&hash_equals($sha,sha1('blob '.strlen($body)."\0".$body));
        $out['deployed_files_match'][$path]=$match;
        if(!$match)throw new RuntimeException('file_changed');
    }
    require_once $root.'/config.php';
    require_once $root.'/services/ConversationDb.php';
    require_once $root.'/services/ProjectConfig.php';
    $pdo=ConversationDb::connection();$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();$out['database_opened']=true;
    $project=ProjectConfig::projectId();
    $q=$pdo->prepare("SELECT DISTINCT external_chat_id FROM conversations WHERE project_key=? AND channel='max' AND is_test=1 LIMIT 5001");
    $q->execute([$project]);$tests=$q->fetchAll(PDO::FETCH_COLUMN);if(count($tests)>5000)throw new RuntimeException('test_limit');$tests=array_fill_keys(array_map('strval',$tests),true);
    // Count only activity after the verified completed deployment, not after merge.
    $now=time();$from=strtotime('2026-09-29T14:47:23Z');$tz=new DateTimeZone('Europe/Moscow');
    $out['checked_at_utc']=gmdate('c',$now);$out['from_utc']=gmdate('c',$from);
    $out['from_basis']='successful deployment receipt 36585073091';
    $events=['bot_started','channel_offer_start','subscription_first_offer','search_followup_shown','ai_text','ai_start','start_search','search_ready','show_tours','manager_request'];
    $counts=[];$users=[];$offers=[];$history=[];
    foreach(['714260445','714320748','other'] as $group)foreach($events as $event)$counts[$group][$event]=0;
    $path=$root.'/funnel.csv';if(!is_file($path)||is_link($path)||filesize($path)>134217728)throw new RuntimeException('journal_boundary');
    $f=fopen($path,'rb');$head=fgetcsv($f);$idx=array_flip($head?:[]);
    foreach(['DateTime','ChatID','CampaignID','Event'] as $name)if(!isset($idx[$name]))throw new RuntimeException('header');
    $n=0;
    while(($r=fgetcsv($f))!==false){
        if(++$n>1000000)throw new RuntimeException('journal_limit');
        if(count($r)!==count($head))throw new RuntimeException('partial_row');
        $date=(string)$r[$idx['DateTime']];if(substr($date,0,10)!=='2026-09-29')continue;
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date,$tz);if(!$dt||$dt->format('Y-m-d H:i:s')!==$date)throw new RuntimeException('timestamp');
        $ts=$dt->getTimestamp();if($ts<$from||$ts>=$now)continue;
        $chat=(string)$r[$idx['ChatID']];if(isset($tests[$chat]))continue;
        $cid=(string)$r[$idx['CampaignID']];$group=in_array($cid,['714260445','714320748'],true)?$cid:'other';
        $event=(string)$r[$idx['Event']];if(!in_array($event,$events,true))continue;
        $counts[$group][$event]++;$users[$group][$event][$chat]=true;
        $history[$chat][]=['ts'=>$ts,'event'=>$event];
        if($event==='subscription_first_offer'){
            $offers[$chat][]=['ts'=>$ts,'group'=>$group];
            $out['first_natural_offer_utc']=isset($out['first_natural_offer_utc'])?min($out['first_natural_offer_utc'],gmdate('c',$ts)):gmdate('c',$ts);
        }
        if($event==='search_followup_shown')$out['last_natural_search_intro_utc']=gmdate('c',$ts);
    }
    fclose($f);
    foreach($counts as $group=>$ec)foreach($ec as $event=>$count)$out['campaigns'][$group][$event]=['events'=>$count,'users'=>count($users[$group][$event]??[])];
    $paired=0;$delays=[];$activeBeforeIntro=0;
    $iq=$pdo->prepare("SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.project_key=? AND c.channel='max' AND c.is_test=0 AND c.external_chat_id=? AND m.direction='inbound' AND m.created_at>=? AND m.created_at<?");
    foreach($offers as $chat=>$entries){
        usort($entries,static fn($a,$b)=>$a['ts']<=>$b['ts']);
        foreach($entries as $i=>$entry){
            $end=$entries[$i+1]['ts']??$now;
            foreach($history[$chat]??[] as $ev){
                if($ev['event']!=='search_followup_shown'||$ev['ts']<$entry['ts']||$ev['ts']>=$end)continue;
                $paired++;$delays[]=$ev['ts']-$entry['ts'];
                $iq->execute([$project,(string)$chat,gmdate('Y-m-d H:i:s',$entry['ts']),gmdate('Y-m-d H:i:s',$ev['ts'])]);
                if((int)$iq->fetchColumn()>0)$activeBeforeIntro++;
                break;
            }
        }
    }
    $out['natural_pairs']=['offer_then_search_intro'=>$paired,'minimum_delay_seconds'=>$delays?min($delays):null,'maximum_delay_seconds'=>$delays?max($delays):null,'inbound_before_intro'=>$activeBeforeIntro];
    $pdo->rollBack();
    $cron=(string)shell_exec('crontab -l 2>/dev/null');$out['followup_cron_schedules']=[];
    foreach(preg_split('/\R/',$cron)?:[] as $line){
        if(preg_match('/^\s*#|^\s*$/',$line)||strpos($line,$root.'/cron_followup.php')===false)continue;
        $fields=preg_split('/\s+/',trim($line));$out['followup_cron_schedules'][]=implode(' ',array_slice($fields,0,5));
    }
    $out['followup_log_after_deploy']=['SEND_SEARCH_INTRO'=>0,'SKIP_SEARCH_INTRO_PROGRESS'=>0,'SKIP_PHONE'=>0,'SKIP_MANAGER'=>0,'WAIT'=>0,'SEND_DONE_SEARCH_INTRO'=>0];
    $logPath=$root.'/cron_followup.log';
    if(is_file($logPath)&&!is_link($logPath)&&filesize($logPath)<67108864){
        $lf=fopen($logPath,'rb');$deployLocal=(new DateTimeImmutable('@'.$from))->setTimezone($tz);
        while(($line=fgets($lf))!==false){
            if(!preg_match('/^(\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2})--- (.*)$/',trim($line),$m))continue;
            $dt=DateTimeImmutable::createFromFormat('!d.m.Y H:i:s',$m[1],$tz);if(!$dt||$dt->getTimestamp()<$from)continue;
            $msg=$m[2];
            foreach(['SEND_SEARCH_INTRO','SKIP_SEARCH_INTRO_PROGRESS','SKIP_PHONE','SKIP_MANAGER','WAIT'] as $tag)if(str_starts_with($msg,$tag.' '))$out['followup_log_after_deploy'][$tag]++;
            if(str_starts_with($msg,'SEND_DONE ')&&str_contains($msg,'type=search_intro'))$out['followup_log_after_deploy']['SEND_DONE_SEARCH_INTRO']++;
        }
        fclose($lf);
    }
    $out['pending_search_intro']=['waiting'=>0,'due'=>0,'older_than_5_minutes'=>0];$files=glob($root.'/followup/*.json')?:[];
    if(count($files)>5000)throw new RuntimeException('queue_limit');
    foreach($files as $file){
        if(is_link($file)||filesize($file)>8192)continue;
        $item=json_decode((string)file_get_contents($file),true);if(!is_array($item)||($item['type']??'tours')!=='search_intro')continue;
        $at=(int)($item['send_at']??0);$out['pending_search_intro'][$at>$now?'waiting':'due']++;
        if($at>0&&$now-$at>300)$out['pending_search_intro']['older_than_5_minutes']++;
    }
    $out['limitations']=['Delivered-message events are not evidence of user reading or subscribing.','No real customer messages are sent by this audit.','Only naturally occurring post-deployment events are counted; explicit test conversations are excluded.','Subscription conversion requires a separate MAX2 membership-data join.','This release is a sequential pilot, not a randomized A/B experiment.'];
    $out['ok']=true;
} catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$out['error_class']=get_class($e);}
while(ob_get_level())ob_end_clean();echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
exit($out['ok']?0:1);
