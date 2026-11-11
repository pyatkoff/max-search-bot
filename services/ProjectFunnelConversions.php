<?php
declare(strict_types=1);
require_once __DIR__.'/ProjectFunnelConversation.php';
require_once __DIR__.'/MaxChannelConversionOutbox.php';
require_once __DIR__.'/MetrikaOfflineClient.php';

/** Company adapter for the existing outbox and worker. No second queue or schedule. */
final class ProjectFunnelConversions
{
    public static function policy(array $settings): ?array
    {
        $metric=$settings['metrika'];
        if($settings['project_key']==='anytour'||$metric['counter_id']==='98615635'||$metric['mode']==='off'||!$metric['goals'])return null;
        $policy=['project_key'=>$settings['project_key'],'bot_key'=>$settings['bot_key'],'counter_id'=>$metric['counter_id'],
            'mode'=>$metric['mode'],'goals'=>$metric['goals'],'prediction'=>$settings['prediction']??null];
        $hash=$policy;unset($hash['mode']);$policy['sha256']=hash('sha256',json_encode($hash,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));return $policy;
    }

    /** A successful CRM assertion is an observed stage, never a payment receipt. */
    public static function observeStage(int $conversationId,string $stage): void
    {
        if(!in_array($stage,['working','offer_sent','booking','won'],true)||!ConversationDb::isConfigured())return;
        try{
            $pdo=ConversationDb::connection();
            $q=$pdo->prepare("SELECT c.id,c.project_key,c.channel,c.lead_stage_key,c.metadata_json FROM conversations c WHERE c.id=? AND c.channel='max' AND c.is_test=0");$q->execute([$conversationId]);$c=$q->fetch(PDO::FETCH_ASSOC);
            if(!$c||$c['lead_stage_key']!==$stage||ProjectMaxConnection::isLegacyConversation($c))return;
            $connection=ProjectMaxConnection::forConversation($c);if(!$connection||!ProjectFunnelSettings::load($connection))return;
            $q=$pdo->prepare('SELECT id,to_stage_key FROM lead_stage_history WHERE conversation_id=? ORDER BY id DESC LIMIT 1');$q->execute([$conversationId]);$history=$q->fetch(PDO::FETCH_ASSOC);
            if(!$history||$history['to_stage_key']!==$stage)return;
            $key='stage-history:'.$history['id'];
            $q=$pdo->prepare("SELECT id FROM conversation_events WHERE conversation_id=? AND event_type='project_funnel_stage' AND payload_json LIKE ? LIMIT 1");$q->execute([$conversationId,'%"operation_key":"'.$key.'"%']);if($q->fetchColumn())return;
            $data=ConversationRecordClock::stamp(['version'=>1,'project_key'=>$connection->projectKey(),'bot_key'=>$connection->botKey(),'stage'=>$stage,'operation_key'=>$key,'evidence_basis'=>'manager_stage_assertion']);
            $q=$pdo->prepare('INSERT INTO conversation_events(conversation_id,event_type,actor_type,payload_json,created_at) VALUES(?,?,?,?,?)');
            $q->execute([$conversationId,'project_funnel_stage','system',json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s')]);
        }catch(Throwable $ignored){}
    }

    /** Identity resolves only the saved exact visit, never the latest user visit or a different offer. */
    public static function identity(PDO $pdo,array $row,array $settings,int $now): ?array
    {
        $ctx=json_decode((string)($row['funnel_context_json']??''),true);
        if(!is_array($ctx)||($ctx['project_key']??null)!==$settings['project_key']||($ctx['bot_key']??null)!==$settings['bot_key']
            ||($ctx['attribution_state']??null)!=='matched'||count($ctx['observations']??[])!==1||($ctx['external_user_id']??null)!==(string)$row['external_user_id']
            ||($ctx['advertising_scope']??null)!==ProjectFunnelEntry::scope($settings)||!isset($settings['posts'][$ctx['post_id']??''])
            ||$settings['posts'][$ctx['post_id']]['offer_id']!==($ctx['offer_id']??null))return null;
        $scope=ProjectFunnelEntry::scope($settings);$visit=$ctx['observations'][0]['visit_id']??'';
        $q=$pdo->prepare("SELECT * FROM advertising_visit_observations WHERE project_key=? AND source_key=? AND platform=? AND counter_id=? AND visit_id=? AND identity_status='verified' ORDER BY id DESC LIMIT 101");
        $q->execute([...array_values($scope),$visit]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);if(!$rows||count($rows)>=101)return null;
        $cid=null;$latest=null;
        foreach($rows as$observation){
            if($observation['external_user_id']!==$ctx['external_user_id']||$observation['post_ref']!==$ctx['post_id']||$observation['offer_ref']!==$ctx['offer_id']
                ||(int)$observation['visit_at_unix']>(int)$row['event_unix']||$now-(int)$observation['visit_at_unix']>21*86400)return null;
            if($observation['client_id']==='')continue;
            if($cid!==null&&$cid!==$observation['client_id'])return null;
            $cid=$observation['client_id'];$latest=$latest??$observation;
        }
        return $latest;
    }

    public static function collect(PDO $pdo,ProjectMaxConnection $connection,array $settings,array $policy,int $now): int
    {
        $repo=new MaxChannelConversionOutbox($pdo);$insert=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';
        $pdo->prepare($insert.' INTO max_channel_attribution_settings(project_key,mode,counter_id,activated_at_unix,message_cursor) VALUES(?,?,?,?,0)')->execute([$policy['project_key'],$policy['mode'],$policy['counter_id'],$now]);
        $q=$pdo->prepare('SELECT counter_id,message_cursor FROM max_channel_attribution_settings WHERE project_key=?');$q->execute([$policy['project_key']]);$cursor=$q->fetch(PDO::FETCH_ASSOC);
        if(!$cursor||$cursor['counter_id']!==$policy['counter_id'])throw new RuntimeException('project_counter_changed');
        $sql="SELECT c.id,c.project_key,c.channel,c.metadata_json,cc.external_user_id FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel AND cc.external_chat_id=c.external_chat_id WHERE c.project_key=? AND c.channel='max' AND c.is_test=0 AND c.id>? AND EXISTS(SELECT 1 FROM conversation_events e WHERE e.conversation_id=c.id AND e.event_type='project_funnel_entry') ORDER BY c.id LIMIT 25";
        $q=$pdo->prepare($sql);$q->execute([$policy['project_key'],(int)$cursor['message_cursor']]);$conversations=$q->fetchAll(PDO::FETCH_ASSOC);
        if(!$conversations&&(int)$cursor['message_cursor']>0){$q->execute([$policy['project_key'],0]);$conversations=$q->fetchAll(PDO::FETCH_ASSOC);}
        $count=0;
        foreach($conversations as$c){
            $metadata=json_decode((string)$c['metadata_json'],true);if(($metadata['max_bot_key']??null)!==$connection->botKey())continue;
            $q=$pdo->prepare("SELECT id,payload_json FROM conversation_events WHERE conversation_id=? AND event_type='project_funnel_entry' ORDER BY id LIMIT 101");$q->execute([$c['id']]);$entries=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($entries)>=101)continue;
            $anchor=null;$since=null;
            foreach($entries as$e){$data=json_decode($e['payload_json'],true);$at=ConversationRecordClock::read($e['payload_json']);
                if(is_array($data)&&($data['attribution_state']??null)==='matched'&&($data['advertising_scope']??null)===ProjectFunnelEntry::scope($settings)
                    &&($data['external_user_id']??null)===(string)$c['external_user_id']&&($data['bot_key']??null)===$connection->botKey()&&$at!==null&&$at>=$now-21*86400&&$at<=$now){$anchor=$data;$since=$at;break;}}
            if(!$anchor)continue;
            // Explicit recording clocks only; bare historical DATETIMEs never supply a conversion time.
            $q=$pdo->prepare('SELECT id,direction,sender_type,metadata_json FROM messages WHERE conversation_id=? ORDER BY id LIMIT 501');$q->execute([$c['id']]);$messages=$q->fetchAll(PDO::FETCH_ASSOC);if(count($messages)>=501)continue;
            $stages=[];$managerAt=null;
            foreach($messages as$m){$at=ConversationRecordClock::read($m['metadata_json']);if($at===null||$at<$since||$at>$now)continue;
                $meta=json_decode((string)$m['metadata_json'],true);
                if($m['direction']==='outbound'&&$m['sender_type']==='manager')$managerAt=$managerAt??$at;
                if($m['direction']==='inbound'&&$m['sender_type']==='customer'&&in_array($meta['type']??'', ['message','callback','contact'],true)){
                    $stages['customer_activity']=$stages['customer_activity']??$at;
                    if($managerAt!==null&&$at>=$managerAt&&in_array($meta['type'],['message','contact'],true))$stages['customer_reply']=$stages['customer_reply']??$at;
                }
            }
            $q=$pdo->prepare("SELECT event_type,payload_json FROM conversation_events WHERE conversation_id=? AND event_type IN ('waiting_manager','project_funnel_stage') ORDER BY id LIMIT 501");$q->execute([$c['id']]);$events=$q->fetchAll(PDO::FETCH_ASSOC);if(count($events)>=501)continue;
            foreach($events as$e){$at=ConversationRecordClock::read($e['payload_json']);if($at===null||$at<$since||$at>$now)continue;$data=json_decode($e['payload_json'],true);
                if($e['event_type']==='waiting_manager')$stages['manager_request']=$stages['manager_request']??$at;
                elseif(($data['project_key']??null)===$policy['project_key']&&($data['bot_key']??null)===$connection->botKey()&&in_array($data['stage']??'', ['working','offer_sent','booking','won'],true))$stages[$data['stage']]=$stages[$data['stage']]??$at;
            }
            foreach($stages as$stage=>$at)if(isset($policy['goals'][$stage])&&$repo->captureProject($policy,$c,$stage,$at,$anchor))$count++;
            $estimate=self::estimate($policy,$stages,$now);
            if($estimate!==null&&isset($policy['goals']['predictive_value'])&&$repo->captureProject($policy,$c,'predictive_value',$estimate['event_unix'],$anchor+['estimate'=>$estimate],$estimate['value_rub']))$count++;
        }
        $next=$conversations?(int)end($conversations)['id']:0;
        $q=$pdo->prepare('UPDATE max_channel_attribution_settings SET message_cursor=?,mode=? WHERE project_key=? AND counter_id=? AND message_cursor=?');$q->execute([$next,$policy['mode'],$policy['project_key'],$policy['counter_id'],$cursor['message_cursor']]);
        return $count;
    }

    /** Versioned, company-calibrated policy only. No inherited travel priors or assumed revenue. */
    public static function estimate(array $policy,array $stages,int $now): ?array
    {
        $model=$policy['prediction']??null;if(!is_array($model)||$model['valid_until_unix']<$now||$model['validated_at_unix']>$now)return null;
        foreach(['won','booking','offer_sent','working','customer_reply','manager_request','customer_activity']as$stage)if(isset($stages[$stage],$model['stage_probability'][$stage])){
            // A factual CRM sale has its own observed goal; do not also call it a prediction.
            if(isset($stages['won']))return null;
            return ['model_id'=>$model['model_id'],'model_version'=>$model['version'],'evidence_sha256'=>$model['evidence_sha256'],
                'basis'=>'configured_conditional_probability_not_payment','stage'=>$stage,'probability'=>$model['stage_probability'][$stage],
                'event_unix'=>$stages[$stage],'value_rub'=>number_format((float)$model['base_value_rub']*$model['stage_probability'][$stage],2,'.','')];
        }
        return null;
    }

    public static function tick(PDO $pdo,int $now,?callable $apiFactory=null): array
    {
        $summary=['projects'=>0,'collected'=>0,'resolved'=>0,'accepted'=>0,'processed'=>0,'unknown'=>0,'held'=>0,'errors'=>0];
        try{$q=$pdo->prepare('SELECT project_key FROM projects WHERE is_active=1 AND project_key<>? ORDER BY id LIMIT 101');$q->execute([ProjectConfig::projectId()]);$projects=$q->fetchAll(PDO::FETCH_COLUMN);}catch(Throwable $e){return $summary;}
        if(count($projects)>=101){$summary['held']++;return $summary;}
        foreach($projects as$project){
            $connection=ProjectMaxConnection::resolve($project);$settings=$connection?ProjectFunnelSettings::load($connection):null;$policy=$settings?self::policy($settings):null;if(!$policy)continue;
            $summary['projects']++;
            try{
                $repo=new MaxChannelConversionOutbox($pdo);$repo->recoverProject($policy,$now);$summary['collected']+=self::collect($pdo,$connection,$settings,$policy,$now);
                foreach($repo->projectRows($policy,'pending',$now,25)as$row){$identity=self::identity($pdo,$row,$settings,$now);$repo->resolveProject($row,$identity,$now);if($identity)$summary['resolved']++;}
                if($policy['mode']!=='send')continue;
                if($apiFactory)$api=$apiFactory($policy);
                else{
                    $bindings=defined('MAX_SEARCH_PROJECT_METRIKA')&&is_array(MAX_SEARCH_PROJECT_METRIKA)?MAX_SEARCH_PROJECT_METRIKA:[];$binding=$bindings[$project]??null;
                    if(!is_array($binding)||($binding['counter_id']??null)!==$policy['counter_id']||($binding['bot_key']??null)!==$connection->botKey()||!is_string($binding['oauth_token']??null)||$binding['oauth_token']===''){$summary['held']++;continue;}
                    $api=new MetrikaOfflineClient($policy['counter_id'],$binding['oauth_token'],null,null,$policy);
                }
                if(!$api){$summary['held']++;continue;}
                $goalsReady=$api->projectGoalsReady();if(!$goalsReady)$summary['held']++;
                foreach($goalsReady?$repo->projectRows($policy,'ready',$now):[]as$row){
                    $stage=substr($row['target'],7);$identity=self::identity($pdo,$row,$settings,$now);
                    if($row['funnel_policy_sha256']!==$policy['sha256']||!isset($policy['goals'][$stage])||$row['transport_target']!==$policy['goals'][$stage]
                        ||!$identity||$row['client_id']!==$identity['client_id']||(int)$row['event_unix']>$now){$summary['held']++;continue;}
                    $token=$repo->claimProject($row,$now);if($token===null)continue;
                    try{$response=$api->upload($row,$now);}catch(Throwable $e){$response=['outcome'=>'unknown','reason'=>'upload_outcome_unknown'];}
                    $repo->finish((string)$row['id'],$token,$response,$now);$outcome=$response['outcome']??'unknown';if(in_array($outcome,['accepted','unknown'],true))$summary[$outcome]++;else $summary['errors']++;
                }
                foreach(['accepted','unknown']as$state)foreach($repo->projectRows($policy,$state,$now,3)as$row){
                    try{$receipt=$state==='accepted'?$api->poll((string)$row['upload_id']):$api->findUnknown($row);
                        if($receipt){$repo->status($row,$receipt,$now);if(($receipt['status']??'')==='PROCESSED')$summary['processed']++;}else $repo->defer((string)$row['id'],$state,$now+1800);
                    }catch(Throwable $e){$repo->defer((string)$row['id'],$state,$now+1800);$summary['errors']++;}
                }
            }catch(Throwable $e){$summary['errors']++;}
        }
        return $summary;
    }
}
