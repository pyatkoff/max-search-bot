<?php
declare(strict_types=1);

/** Read-only cohort projection. Raw identifiers and message contents never leave this process. */
function screenProjection(array $entry,array $events,int $now,bool $known):array{
    $t=(int)$entry['ts']; $end=$t+3600;
    $r=['offered_users'=>1,'fresh_excluded'=>0,'observed_60m'=>0,'db_linked'=>0,'db_unlinked'=>0,
        'any_inbound'=>0,'clicked_search'=>0,'chooser_delivered'=>0,'ai_selected'=>0,'wizard_selected'=>0,
        'free_text'=>0,'parameters_ready'=>0,'show_tours'=>0,'no_inbound'=>0,'no_search_activity'=>0,
        'clicked_without_chooser'=>0,'chooser_without_followup'=>0,'chooser_followup'=>0];
    if($now<$end){$r['fresh_excluded']=1;return $r;}
    $r['observed_60m']=1;$r[$known?'db_linked':'db_unlinked']=1;
    $active=array_values(array_filter($events,static fn($e)=>$e['ts'] >= $t && $e['ts']<$end));
    usort($active,static fn($a,$b)=>$a['ts']<=>$b['ts']);
    $click=null;$chooser=null;$steps=[];
    foreach($active as $e){
        $k=$e['kind'];$ts=$e['ts'];
        if(in_array($k,['search_options','ai_start','start_search','free_text','other_inbound','show_tours_callback'],true))$r['any_inbound']=1;
        if($k==='search_options'){$r['clicked_search']=1;if($click===null)$click=$ts;}
        if($k==='chooser'&&$click!==null&&$ts >= $click){$r['chooser_delivered']=1;if($chooser===null)$chooser=$ts;}
        if($k==='ai_start'){$r['ai_selected']=1;$steps[]=$ts;}
        if($k==='start_search'){$r['wizard_selected']=1;$steps[]=$ts;}
        if($k==='free_text'){$r['free_text']=1;$steps[]=$ts;}
        if($k==='search_ready')$r['parameters_ready']=1;
        if($k==='show_tours')$r['show_tours']=1;
    }
    $r['no_inbound']=(int)!$r['any_inbound'];
    $r['no_search_activity']=(int)!($r['clicked_search']||$r['ai_selected']||$r['wizard_selected']||$r['free_text']||$r['parameters_ready']||$r['show_tours']);
    $r['clicked_without_chooser']=(int)($click!==null&&$chooser===null);
    if($chooser!==null){
        foreach($steps as $ts)if($ts >= $chooser){$r['chooser_followup']=1;break;}
        $r['chooser_without_followup']=1-$r['chooser_followup'];
    }
    return $r;
}
function addScreenProjection(array &$bucket,array $row):void{foreach($row as $k=>$v)$bucket[$k]=($bucket[$k]??0)+$v;}
function readStartScreen(string $root,PDO $pdo,string $project,DateTimeImmutable $now):array{
    $cids=['714260445','714320748'];$tz=new DateTimeZone('Europe/Moscow');
    $from=strtotime('2026-09-23T00:00:00+03:00');$fix=strtotime('2026-09-28T19:06:53+03:00');$cap=$now->getTimestamp();
    $path=$root.'/funnel.csv';
    if(!is_file($path)||is_link($path)||filesize($path)>134217728)throw new RuntimeException('journal_unavailable_or_limit');
    $fh=fopen($path,'rb');if(!$fh)throw new RuntimeException('journal_open_failed');$head=fgetcsv($fh);$idx=array_flip($head?:[]);
    foreach(['DateTime','ChatID','YclidText','CampaignID','Event'] as $key)if(!isset($idx[$key]))throw new RuntimeException('journal_header');
    $offers=[];$events=[];$join=[];$joinMissingYclid=0;$malformed=0;$order=0;
    while(($row=fgetcsv($fh))!==false){
        $order++;
        if(count($row)!==count($head)){$malformed++;continue;}
        $cid=trim($row[$idx['CampaignID']]);if(!in_array($cid,$cids,true))continue;
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$row[$idx['DateTime']],$tz);
        if(!$dt||$dt->format('Y-m-d H:i:s')!==$row[$idx['DateTime']])throw new RuntimeException('timestamp_invalid');
        $ts=$dt->getTimestamp();if($ts<$from||$ts>=$cap)continue;
        $chat=(string)$row[$idx['ChatID']];if(!preg_match('/^-?[0-9]+$/D',$chat))throw new RuntimeException('chat_key_invalid');
        $k=$row[$idx['Event']];
        if($k==='channel_offer_start')$offers[]=['chat'=>$chat,'cid'=>$cid,'ts'=>$ts,'order'=>$order];
        if(in_array($k,['ai_start','show_tours','search_ready'],true))$events[$chat][]=['ts'=>$ts,'kind'=>$k,'cid'=>$cid,'origin'=>'journal'];
        if(in_array($k,['bot_started','channel_offer_start','search_ready','show_tours'],true)){
            $yclid=trim((string)$row[$idx['YclidText']]);
            if($yclid==='')$joinMissingYclid++;
            else{
                $hk=hash('sha256','max-funnel-v1|'.$yclid);
                if(!isset($join[$hk]))$join[$hk]=['campaigns'=>[],'bot_started'=>false,'offer'=>false,'parameters_ready'=>false,'show_tours'=>false];
                $join[$hk]['campaigns'][$cid]=true;
                if($k==='bot_started')$join[$hk]['bot_started']=true;
                elseif($k==='channel_offer_start')$join[$hk]['offer']=true;
                elseif($k==='search_ready')$join[$hk]['parameters_ready']=true;
                elseif($k==='show_tours')$join[$hk]['show_tours']=true;
            }
        }
    }
    fclose($fh);if($malformed)throw new RuntimeException('malformed_journal_rows');
    if(count($offers)>10000)throw new RuntimeException('offer_limit');
    usort($offers,static fn($a,$b)=>[$a['ts'],$a['order']]<=>[$b['ts'],$b['order']]);
    $chatKeys=array_values(array_unique(array_column($offers,'chat')));$known=[];$tests=[];$rows=0;
    foreach(array_chunk($chatKeys,300) as $batch){
        $marks=implode(',',array_fill(0,count($batch),'?'));
        $q=$pdo->prepare("SELECT DISTINCT external_chat_id,is_test FROM conversations WHERE project_key=? AND channel='max' AND external_chat_id IN ($marks) LIMIT 2001");
        $q->execute(array_merge([$project],$batch));$found=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($found)>2000)throw new RuntimeException('identity_limit');
        foreach($found as $c){$known[(string)$c['external_chat_id']]=true;if(!empty($c['is_test']))$tests[(string)$c['external_chat_id']]=true;}
        // Only known callback values and categorical flags are selected; customer free text is not exported.
        $sql="SELECT c.external_chat_id,m.created_at,m.direction,
            CASE WHEN m.direction='outbound' THEN 'chooser'
                 WHEN m.text REGEXP '^(g1_[a-f0-9]{8}_)?(search_options|ai_start|start_search|show_tours)$' THEN m.text
                 WHEN JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(m.metadata_json) THEN m.metadata_json ELSE '{}' END,'$.type'))='message' THEN 'free_text'
                 ELSE 'other_inbound' END AS kind
            FROM messages m JOIN conversations c ON c.id=m.conversation_id
            WHERE c.project_key=? AND c.channel='max' AND c.is_test=0 AND c.external_chat_id IN ($marks)
            AND m.created_at>=? AND m.created_at<?
            AND (m.direction='inbound' OR (m.direction='outbound' AND m.text LIKE '%Как будем подбирать тур?%'))
            ORDER BY m.created_at,m.id LIMIT 30001";
        $q=$pdo->prepare($sql);$q->execute(array_merge([$project],$batch,[gmdate('Y-m-d H:i:s',$from),gmdate('Y-m-d H:i:s',$cap)]));
        $part=0;
        while($m=$q->fetch(PDO::FETCH_ASSOC)){
            if(++$part>30000||++$rows>150000)throw new RuntimeException('message_limit');
            $chat=(string)$m['external_chat_id'];$k=(string)$m['kind'];
            $k=preg_replace('/^g1_[a-f0-9]{8}_/','',$k);if($k==='show_tours')$k='show_tours_callback';
            $ts=(new DateTimeImmutable($m['created_at'],new DateTimeZone('UTC')))->getTimestamp();
            $events[$chat][]=['ts'=>$ts,'kind'=>$k,'origin'=>'db'];
        }
    }
    // Independent clock check: matching AI-selection events recorded in both sources.
    $alignment=['pairs'=>0,'within_60s'=>0,'offset_hours_histogram'=>[]];
    foreach($events as $chat=>$evs){
        $jt=[];$mt=[];foreach($evs as $e)if($e['kind']==='ai_start'){if($e['origin']==='journal')$jt[]=$e['ts'];else $mt[]=$e['ts'];}
        foreach($jt as $t){
            $best=null;foreach($mt as $m)if($best===null||abs($m-$t)<abs($best))$best=$m-$t;
            if($best===null||abs($best)>14400)continue;
            $alignment['pairs']++;if(abs($best)<=60)$alignment['within_60s']++;
            $h=(string)(int)round($best/3600);$alignment['offset_hours_histogram'][$h]=($alignment['offset_hours_histogram'][$h]??0)+1;
        }
    }
    $out=['ok'=>true,'generated_at'=>$now->format('c'),'report_type'=>'first_delivered_offer_per_user_per_period_60m',
        'from_moscow'=>(new DateTimeImmutable('@'.$from))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('c'),'fix_at_utc'=>gmdate('c',$fix),'journal_timezone'=>$tz->getName(),'php_cli_timezone'=>date_default_timezone_get(),
        'db_clock'=>$pdo->query('SELECT @@session.time_zone AS session_timezone, TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW()) AS offset_from_utc_seconds')->fetch(PDO::FETCH_ASSOC),
        'clock_alignment'=>$alignment,'clock_join_verified'=>$alignment['pairs']>=10 && $alignment['within_60s']/$alignment['pairs']>=0.9,
        'source_offer_rows'=>count($offers),'message_flag_rows'=>$rows,'explicit_test_users_excluded'=>count($tests),'periods'=>[],
        'cross_campaign_users'=>0,'limitations'=>['No MAX2 membership join: no inbound action does not mean no subscription click.',
        'Campaign is taken from the delivered-offer journal entry; outcomes use this same chat and following hour.',
        'First delivered offer in each period only; one person can be in more than one comparison period.',
        'Free text and mode selection are not mutually exclusive. Database timestamps interpreted as UTC and checked against journal AI selections.',
        'Raw chat identifiers and message text are never returned.']];
    $seen=[];$seenCampaign=[];
    foreach($offers as $entry){
        $chat=$entry['chat'];$cid=$entry['cid'];if(isset($tests[$chat]))continue;
        $seenCampaign[$chat][$cid]=true;
        $day=(new DateTimeImmutable('@'.$entry['ts']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d');
        $periods=['all',$entry['ts']<$fix?'before_fix':'after_fix',$day];
        foreach($periods as $p)foreach(['all_selected',$cid] as $group){
            $key=$p.'|'.$group.'|'.$chat;if(isset($seen[$key]))continue;$seen[$key]=true;
            // Exclude journal outcomes attributed to a different campaign; DB actions remain scoped to the entry's next hour.
            $evs=array_values(array_filter($events[$chat]??[],static fn($e)=>!isset($e['cid'])||$e['cid']===$cid));
            $row=screenProjection($entry,$evs,$cap,isset($known[$chat]));
            if(!isset($out['periods'][$p][$group]))$out['periods'][$p][$group]=[];
            addScreenProjection($out['periods'][$p][$group],$row);
        }
    }
    foreach($seenCampaign as $set)if(count($set)>1)$out['cross_campaign_users']++;
    foreach($join as &$j){$j['campaigns']=array_keys($j['campaigns']);sort($j['campaigns']);}unset($j);ksort($join);
    $out['cross_system_yclid_sha256']=$join;$out['cross_system_missing_yclid_metric_rows']=$joinMissingYclid;
    return $out;
}
if(defined('START_SCREEN_TEST'))return;
if(PHP_SAPI!=='cli'){http_response_code(404);exit(2);}
ini_set('display_errors','0');set_time_limit(90);ob_start();
$out=['ok'=>false,'server_writes'=>false,'advertising_writes'=>false,'database_writes'=>false,'database_opened'=>true,'token_exported'=>false,'campaigns'=>['714260445'=>[],'714320748'=>[]]];
try{
    $root='/var/www/anytoour/data/www/app.anytoour.ru';
    require_once $root.'/config.php';require_once $root.'/services/ConversationDb.php';require_once $root.'/services/ProjectConfig.php';
    $pdo=ConversationDb::connection();$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $out['start_screen']=readStartScreen($root,$pdo,ProjectConfig::projectId(),$now);
    $pdo->rollBack();$out['campaigns']=$out['start_screen']['periods']['all']??[];$out['ok']=true;
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$out['error_class']=get_class($e);$out['error_code']=(string)$e->getCode();}
while(ob_get_level())ob_end_clean();echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
exit($out['ok']?0:1);
