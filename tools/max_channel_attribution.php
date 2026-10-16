<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/services/MaxChannelConversionWorker.php';

try{
    $args=array_slice($argv,1);$action=$args[0]??'status';
    if(count($args)>1||!in_array($action,['status','probe','collect','shared','send','off'],true))throw new RuntimeException('invalid_operation');
    $pdo=ConversationDb::connection();$s=MaxChannelConversionOutbox::settings($pdo);if(!$s)throw new RuntimeException('migration_required');
    if(in_array($action,['probe','collect','send'],true)){
        $client=new MaxTrackerLookupClient();$probe=$client->lookup($s['counter_id'],[]);
        if($probe!==[])throw new RuntimeException('tracker_probe_failed');
    }
    if(in_array($action,['shared','send'],true)){
        if(!defined('METRIKA_COUNTER_ID')||(string)METRIKA_COUNTER_ID!==$s['counter_id']||!defined('METRIKA_OAUTH_TOKEN'))throw new RuntimeException('metrika_config_missing');
        $api=new MetrikaOfflineClient($s['counter_id'],(string)METRIKA_OAUTH_TOKEN);
        if(!$api->goalsReady())throw new RuntimeException('existing_goals_not_verified');
        if(empty($s['activated_at_unix']))throw new RuntimeException('collect_mode_required_first');
        if($action==='shared'&&$s['mode']!=='collect')throw new RuntimeException('collect_mode_required_first');
        if($action==='send'&&(($s['goal_set']??'')!=='shared'||empty($s['shared_goals_activated_at_unix'])))throw new RuntimeException('shared_goals_activation_required');
    }
    $transition=[];
    if($action==='shared'){$transition=(new MaxChannelConversionOutbox($pdo))->activateSharedGoals(time());$s=MaxChannelConversionOutbox::settings($pdo);}
    if(in_array($action,['collect','send','off'],true)){
        // Explicit operator action only. A first activation starts after existing
        // messages and does not silently backfill earlier production traffic.
        $pdo->beginTransaction();
        try{
            if($action!=='off'&&empty($s['activated_at_unix'])){
                $cursor=(string)$pdo->query('SELECT COALESCE(MAX(id),0) FROM messages')->fetchColumn();
                $q=$pdo->prepare('UPDATE max_channel_attribution_settings SET mode=?,activated_at_unix=?,message_cursor=? WHERE project_key=? AND activated_at_unix IS NULL');
                $q->execute([$action,time(),$cursor,$s['project_key']]);
            }else{$q=$pdo->prepare('UPDATE max_channel_attribution_settings SET mode=? WHERE project_key=?');$q->execute([$action,$s['project_key']]);}
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        $s=MaxChannelConversionOutbox::settings($pdo);
    }
    $counts=[];$q=$pdo->prepare('SELECT state,COUNT(*) AS c FROM max_channel_conversion_outbox WHERE project_key=? GROUP BY state');$q->execute([$s['project_key']]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$counts[$r['state']]=(int)$r['c'];
    echo json_encode(['ok'=>true,'operation'=>$action,'mode'=>$s['mode'],'goal_set'=>$s['goal_set'],'shared_goals_activated_at_unix'=>$s['shared_goals_activated_at_unix'],'counter_id'=>$s['counter_id'],'activated_at_unix'=>$s['activated_at_unix'],'queue_counts'=>$counts,'transition'=>$transition,'conversion_requests'=>0],JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){
    $allowed=['invalid_operation','migration_required','tracker_probe_failed','metrika_config_missing','existing_goals_not_verified','collect_mode_required_first','shared_goals_activation_required'];
    $reason=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'operation_failed';
    echo json_encode(['ok'=>false,'reason'=>$reason,'conversion_requests'=>0])."\n";exit(1);
}

