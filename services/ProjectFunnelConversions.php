<?php
declare(strict_types=1);
require_once __DIR__.'/ProjectFunnelConversation.php';
require_once __DIR__.'/MaxChannelConversionOutbox.php';
require_once __DIR__.'/MetrikaOfflineClient.php';
require_once __DIR__.'/RepairProjectSettings.php';
require_once __DIR__.'/AppleService39TelegramIngress.php';

/** Company adapter for the existing outbox and worker. No second queue or schedule. */
final class ProjectFunnelConversions
{
    public static function policy(array $settings): ?array
    {
        $metric=$settings['metrika'];
        if($settings['project_key']==='anytour'||$metric['counter_id']==='98615635'||$metric['mode']==='off'||!$metric['goals'])return null;
        $policy=['project_key'=>$settings['project_key'],'bot_key'=>$settings['bot_key'],'counter_id'=>$metric['counter_id'],
            'mode'=>$metric['mode'],'goals'=>$metric['goals'],'goal_ids'=>$metric['goal_ids']??[],'goal_match'=>$metric['goal_match']??[],
            'credential_source'=>$metric['credential_source']??'project','prediction'=>$settings['prediction']??null];
        $hash=$policy;unset($hash['mode']);$policy['sha256']=hash('sha256',json_encode($hash,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));return $policy;
    }

    public static function hasActiveProjects(?PDO $pdo=null): bool
    {
        try{
            $pdo=$pdo??ConversationDb::connection();$q=$pdo->prepare('SELECT project_key FROM projects WHERE is_active=1 AND project_key<>? ORDER BY id LIMIT 101');$q->execute([ProjectConfig::projectId()]);$projects=$q->fetchAll(PDO::FETCH_COLUMN);
            if(count($projects)>=101)return false;
            foreach($projects as$project){$c=ProjectMaxConnection::resolve($project);$s=$c?ProjectFunnelSettings::load($c):null;if($s&&self::policy($s)!==null)return true;}
        }catch(Throwable $e){}
        return false;
    }

    /** Explicit credential source, used only inside the normal server runtime. */
    private static function api(array $policy): ?MetrikaOfflineClient
    {
        if(($policy['credential_source']??'project')==='platform'){
            if(!defined('METRIKA_OAUTH_TOKEN')||!is_string(METRIKA_OAUTH_TOKEN)||trim(METRIKA_OAUTH_TOKEN)==='')return null;
            return new MetrikaOfflineClient($policy['counter_id'],METRIKA_OAUTH_TOKEN,null,null,$policy);
        }
        $bindings=defined('MAX_SEARCH_PROJECT_METRIKA')&&is_array(MAX_SEARCH_PROJECT_METRIKA)?MAX_SEARCH_PROJECT_METRIKA:[];$binding=$bindings[$policy['project_key']]??null;
        if(!is_array($binding)||($binding['counter_id']??null)!==$policy['counter_id']||($binding['bot_key']??null)!==$policy['bot_key']||!is_string($binding['oauth_token']??null)||$binding['oauth_token']==='')return null;
        return new MetrikaOfflineClient($policy['counter_id'],$binding['oauth_token'],null,null,$policy);
    }

    /** Read-only API admission for an existing configured company. Never returns a credential. */
    public static function probe(array $settings,?callable $apiFactory=null): array
    {
        $policy=self::policy($settings);$result=['ok'=>false,'status'=>'off','conversion_requests'=>0];if(!$policy)return $result;
        try{$api=$apiFactory?$apiFactory($policy):self::api($policy);if(!$api)return array_replace($result,['status'=>'credential_unavailable']);
            $status=$api->projectGoalAdmission();return array_replace($result,['ok'=>$status==='ready','status'=>$status]);
        }catch(Throwable $e){return array_replace($result,['status'=>'goal_access_unavailable']);}
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
    public static function identity(PDO $pdo,array $row,array $settings,int $now,?array $telegram=null,?callable $read=null): ?array
    {
        $ctx=json_decode((string)($row['funnel_context_json']??''),true);
        if(($row['platform']??null)==='telegram'){
            $policy=self::policy($settings);
            $forecast=$telegram&&$policy?self::telegramForecast($pdo,(int)$row['conversation_id'],$policy,$telegram,$now,$read):null;
            if(!$forecast||$row['target']!=='funnel_predictive_value'||$row['project_key']!==$policy['project_key']
                ||$row['counter_id']!==$policy['counter_id']||$row['funnel_policy_sha256']!==$policy['sha256']
                ||$row['external_user_id']!==$forecast['identity']['external_user_id']
                ||(int)$row['event_unix']!==$forecast['estimate']['event_unix']
                ||$row['value_rub']!==$forecast['estimate']['value_rub']
                ||$ctx!==$forecast['context'])return null;
            return $forecast['identity'];
        }
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

    private static function telegramForecast(PDO $pdo,int $conversationId,array $policy,array $binding,int $now,?callable $read=null): ?array
    {
        if($policy['project_key']!==AppleService39TelegramConnection::PROJECT||$policy['counter_id']!=='42611469'
            ||($policy['prediction']['basis']??null)!=='owner_assumption'||!isset($policy['goals']['predictive_value']))return null;
        $evidence=AppleService39TelegramFunnel::forecastEvidence($pdo,$conversationId,$binding,$now,$read);
        if(!$evidence)return null;
        $estimate=self::estimate($policy,['customer_activity'=>$evidence['event_unix']],$now,$evidence['price_context']);
        if($estimate===null)return null;
        return ['context'=>$evidence['anchor']+['estimate'=>$estimate],'estimate'=>$estimate,'identity'=>$evidence['identity']];
    }

    public static function collect(PDO $pdo,ProjectMaxConnection $connection,array $settings,array $policy,int $now,?array $telegram=null): int
    {
        $repo=new MaxChannelConversionOutbox($pdo);$insert=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';
        $pdo->prepare($insert.' INTO max_channel_attribution_settings(project_key,mode,counter_id,activated_at_unix,message_cursor) VALUES(?,?,?,?,0)')->execute([$policy['project_key'],$policy['mode'],$policy['counter_id'],$now]);
        $q=$pdo->prepare('SELECT counter_id,message_cursor FROM max_channel_attribution_settings WHERE project_key=?');$q->execute([$policy['project_key']]);$cursor=$q->fetch(PDO::FETCH_ASSOC);
        if(!$cursor||$cursor['counter_id']!==$policy['counter_id'])throw new RuntimeException('project_counter_changed');
        $entryType="c.channel='max' AND e.event_type='project_funnel_entry'";
        if($telegram!==null&&$policy['project_key']===AppleService39TelegramConnection::PROJECT)
            $entryType="($entryType) OR (c.channel='telegram' AND c.lead_stage_key<>'won' AND e.event_type='apple_telegram_ad_entry')";
        $sql="SELECT c.id,c.project_key,c.channel,c.customer_id,c.customer_channel_id,c.external_chat_id,c.metadata_json,cc.external_user_id FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel AND cc.external_chat_id=c.external_chat_id WHERE c.project_key=? AND c.is_test=0 AND c.id>? AND EXISTS(SELECT 1 FROM conversation_events e WHERE e.conversation_id=c.id AND ($entryType)) ORDER BY c.id LIMIT 25";
        $q=$pdo->prepare($sql);$q->execute([$policy['project_key'],(int)$cursor['message_cursor']]);$conversations=$q->fetchAll(PDO::FETCH_ASSOC);
        if(!$conversations&&(int)$cursor['message_cursor']>0){$q->execute([$policy['project_key'],0]);$conversations=$q->fetchAll(PDO::FETCH_ASSOC);}
        $count=0;
        foreach($conversations as$c){
            if($c['channel']==='telegram'){
                $forecast=self::telegramForecast($pdo,(int)$c['id'],$policy,$telegram,$now);
                if($forecast&&$repo->captureProject($policy,$c,'predictive_value',$forecast['estimate']['event_unix'],
                    $forecast['context'],$forecast['estimate']['value_rub']))$count++;
                continue;
            }
            $metadata=json_decode((string)$c['metadata_json'],true);if(($metadata['max_bot_key']??null)!==$connection->botKey())continue;
            $q=$pdo->prepare("SELECT id,payload_json FROM conversation_events WHERE conversation_id=? AND event_type='project_funnel_entry' ORDER BY id LIMIT 101");$q->execute([$c['id']]);$entries=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($entries)>=101)continue;
            $anchor=null;$since=null;$anchorId=0;
            foreach($entries as$e){$data=json_decode($e['payload_json'],true);$at=ConversationRecordClock::read($e['payload_json']);
                if(is_array($data)&&($data['attribution_state']??null)==='matched'&&($data['advertising_scope']??null)===ProjectFunnelEntry::scope($settings)
                    &&($data['external_user_id']??null)===(string)$c['external_user_id']&&($data['bot_key']??null)===$connection->botKey()){
                    // An unreadable first acquisition cannot be silently replaced by its later priced entry.
                    if(($policy['prediction']['basis']??null)==='owner_assumption'&&($at===null||$at>$now))break;
                    if($at!==null&&$at>=$now-21*86400&&$at<=$now){$anchor=$data;$since=$at;$anchorId=(int)$e['id'];break;}
                }}
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
            $priceContext=null;
            if($stages&&!isset($stages['won'])&&($policy['prediction']['basis']??null)==='owner_assumption'){
                // Any prior outcome owns this conversation's one forecast, including failed/unknown history.
                $q=$pdo->prepare("SELECT id FROM max_channel_conversion_outbox WHERE project_key=? AND conversation_id=? AND target='funnel_predictive_value' LIMIT 1");
                $q->execute([$policy['project_key'],$c['id']]);
                if(!$q->fetchColumn())$priceContext=self::repairContext($pdo,$connection,$c,$settings,$anchor,$since,$anchorId,$entries,$now);
            }
            $estimate=self::estimate($policy,$stages,$now,$priceContext);
            if($estimate!==null&&isset($policy['goals']['predictive_value'])&&$repo->captureProject($policy,$c,'predictive_value',$estimate['event_unix'],$anchor+['estimate'=>$estimate],$estimate['value_rub']))$count++;
        }
        $next=$conversations?(int)end($conversations)['id']:0;
        $q=$pdo->prepare('UPDATE max_channel_attribution_settings SET message_cursor=?,mode=? WHERE project_key=? AND counter_id=? AND message_cursor=?');$q->execute([$next,$policy['mode'],$policy['project_key'],$policy['counter_id'],$cursor['message_cursor']]);
        return $count;
    }

    /** Refine only a valid incomplete model entry; the original acquisition always remains the anchor. */
    private static function repairContext(PDO $pdo,ProjectMaxConnection $connection,array $conversation,array $settings,array $anchor,int $anchorAt,int $anchorId,array $entries,int $now): ?array
    {
        $keys=[];
        foreach($entries as$row){$data=json_decode($row['payload_json'],true,16);$key=is_array($data)?($data['entry_key']??null):null;
            if(is_string($key))$keys[$key]=($keys[$key]??0)+1;}
        $key=$anchor['entry_key']??null;
        if(!is_string($key)||($keys[$key]??0)!==1)return null;
        $original=self::recordedRepairEntry($pdo,$connection,$conversation,$settings,$anchor,$anchorAt,$anchorId,$now);
        if($original===null)return null;
        $context=$original['context'];
        if($context['model']!==''&&$context['service']!=='')return $context;
        if($connection->projectKey()!==AppleService39PilotCatalog::PROJECT||$original['scope']!=='model'
            ||$context['model']===''||$context['service']!=='')return null;
        $visit=self::singleVisit($anchor);if($visit===null)return null;$seenBots=[];
        foreach($entries as$row){
            $id=(int)$row['id'];if($id<=$anchorId)continue;
            $next=json_decode($row['payload_json'],true,16);if(!is_array($next))return null;
            $key=$next['entry_key']??null;if(!is_string($key)||($keys[$key]??0)!==1)return null;
            $at=ConversationRecordClock::read($row['payload_json']);if($at===null||$at<$anchorAt)return null;
            $selected=self::recordedRepairEntry($pdo,$connection,$conversation,$settings,$next,$at,$id,$now);
            if($selected===null)return null;
            $priced=$selected['context'];$botId=(int)$priced['source_event_id'];
            if($botId<=$anchorId||$priced['recorded_at_unix']<$anchorAt||($seenBots&&$botId<=max(array_keys($seenBots))))return null;
            $seenBots[$botId]=true;
            // An ordinary menu is neutral; another attributed request or visit ends this chain.
            if($selected['scope']==='menu'&&($next['attribution_state']??null)==='unattributed')continue;
            foreach(['project_key','bot_key','source_key','external_user_id','advertising_scope','post_id','offer_id']as$field)
                if(($next[$field]??null)!==($anchor[$field]??null))return null;
            if(self::singleVisit($next)!==$visit||$priced['model']!==$context['model'])return null;
            if($selected['scope']==='model'&&$priced['service']==='')continue;
            if($selected['scope']!=='model_service'||$priced['service']==='')return null;
            // Attribution is optional at write time. An earlier native request without its receipt
            // must hold refinement, never let a later, conveniently priced service replace it.
            $q=$pdo->prepare("SELECT id FROM conversation_events WHERE conversation_id=? AND event_type='bot_started' AND id>? AND id<=? ORDER BY id LIMIT 101");
            $q->execute([$conversation['id'],$anchorId,$botId]);$native=$q->fetchAll(PDO::FETCH_COLUMN);
            if(count($native)>=101||count($native)!==count($seenBots))return null;
            foreach($native as$nativeId)if(!isset($seenBots[(int)$nativeId]))return null;
            // Resolve only the original visit. Its immutable CID enrichment may have a newer cursor.
            if(self::identity($pdo,['funnel_context_json'=>json_encode($anchor,JSON_THROW_ON_ERROR),
                'external_user_id'=>(string)$conversation['external_user_id'],'event_unix'=>$priced['recorded_at_unix']],$settings,$now)===null)return null;
            $priced['refinement']=['version'=>1,'basis'=>'same_original_visit_explicit_model_service',
                'original'=>['funnel_event_id'=>(string)$anchorId,'entry_key'=>$anchor['entry_key'],'funnel_recorded_at_unix'=>$anchorAt,
                    'bot_event_id'=>$context['source_event_id'],'bot_recorded_at_unix'=>$context['recorded_at_unix']],
                'selected'=>['funnel_event_id'=>(string)$id,'entry_key'=>$next['entry_key'],'funnel_recorded_at_unix'=>$at,
                    'bot_event_id'=>$priced['source_event_id'],'bot_recorded_at_unix'=>$priced['recorded_at_unix']]];
            // The first explicit selection owns resolution; an unpriced choice cannot fall through to another repair.
            return $priced;
        }
        return null;
    }

    private static function singleVisit(array $entry): ?string
    {
        $observations=$entry['observations']??null;
        if(($entry['attribution_state']??null)!=='matched'||!is_array($observations)||!array_is_list($observations)||count($observations)!==1||!is_array($observations[0]))return null;
        $visit=$observations[0]['visit_id']??null;
        return is_string($visit)&&$visit!==''&&strlen($visit)<=191?$visit:null;
    }

    /** Shared scope, duplicate and clock checks distinguish invalid records from valid empty labels. */
    private static function recordedRepairEntry(PDO $pdo,ProjectMaxConnection $connection,array $conversation,array $settings,array $anchor,int $anchorAt,int $anchorId,int $now): ?array
    {
        $key=$anchor['entry_key']??null;
        if($anchorId<=0||$anchorAt>$now||$anchorAt<$now-21*86400||($anchor['version']??null)!==1
            ||!is_string($key)||!preg_match('/\A[a-f0-9]{64}\z/D',$key)
            ||($anchor['project_key']??null)!==$connection->projectKey()||($anchor['bot_key']??null)!==$connection->botKey()
            ||($anchor['source_key']??null)!==$settings['source_key']||($anchor['external_user_id']??null)!==(string)$conversation['external_user_id']
            ||$conversation['project_key']!==$connection->projectKey()||$conversation['channel']!=='max'
            ||$conversation['external_chat_id']!=='-'.(string)$conversation['external_user_id'])return null;
        $q=$pdo->prepare("SELECT id,CASE WHEN LENGTH(payload_json)<=65536 THEN payload_json ELSE NULL END AS payload_json FROM conversation_events WHERE conversation_id=? AND event_type='bot_started' AND payload_json LIKE ? ORDER BY id LIMIT 2");
        $q->execute([$conversation['id'],'%"delivery_key":"'.$key.'"%']);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==1||!is_string($rows[0]['payload_json']))return null;
        $row=$rows[0];$at=ConversationRecordClock::read($row['payload_json']);
        if($at===null||$at>$now||$at>$anchorAt||$at<$now-21*86400||(int)$row['id']>=$anchorId)return null;
        $payload=json_decode($row['payload_json'],true,16);$context=is_array($payload)?($payload['repair_context']??null):null;
        $intent=is_array($payload)?($payload['repair_entry_reply']??null):null;
        if(!is_array($context)||!is_array($intent)||($payload['delivery_key']??null)!==$key
            ||($payload['bot_key']??null)!==$connection->botKey()||($payload['source_key']??null)!==$settings['source_key']
            ||($intent['delivery_key']??null)!==$key||($intent['project_key']??null)!==$connection->projectKey()
            ||($intent['bot_key']??null)!==$connection->botKey()||($intent['conversation_id']??null)!==(int)$conversation['id']
            ||($intent['customer_id']??null)!==(int)$conversation['customer_id']||($intent['customer_channel_id']??null)!==(int)$conversation['customer_channel_id']
            ||($intent['external_user_id']??null)!==(string)$conversation['external_user_id']||($intent['post_id']??null)!==($anchor['post_id']??null)
            ||!in_array($intent['state']??null,['pending','attempted','delivered','unknown','suppressed'],true)
            ||!is_string($intent['entry_code']??null)||!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$intent['entry_code'])
            ||($context['entry_code']??null)!==$intent['entry_code'])return null;
        foreach(['model'=>160,'service'=>240]as$field=>$limit)if(!is_string($context[$field]??null)
            ||strlen($context[$field])>$limit||preg_match('/[\p{C}<>&]/u',$context[$field])!==0)return null;
        try{
            $entry=RepairProjectSettings::entry($connection,$intent['entry_code']);
            if(!$entry||$entry['post_id']!==$anchor['post_id'])return null;
            $expected=$connection->projectKey()===AppleService39PilotCatalog::PROJECT
                ?AppleService39PilotCatalog::entryContext($entry,$context,$intent['entry_code'])
                :['model'=>$entry['model'],'service'=>$entry['service'],'entry_code'=>$intent['entry_code']];
            if($expected!==$context)return null;
        }catch(Throwable $ignored){return null;}
        return ['scope'=>$entry['scope']??'repair','context'=>['model'=>$context['model'],'service'=>$context['service'],'entry_code'=>$intent['entry_code'],
            'post_id'=>$anchor['post_id'],'entry_key'=>$key,'recorded_at_unix'=>$at,'source_event_id'=>(string)$row['id']]];
    }

    /** Versioned company values: calibrated policy or an explicitly approved owner assumption. */
    public static function estimate(array $policy,array $stages,int $now,?array $priceContext=null): ?array
    {
        $model=$policy['prediction']??null;if(!is_array($model))return null;
        if(($model['basis']??null)==='owner_assumption')return self::ownerEstimate($model,$stages,$now,$priceContext);
        if($model['valid_until_unix']<$now||$model['validated_at_unix']>$now)return null;
        foreach(['won','booking','offer_sent','working','customer_reply','manager_request','customer_activity']as$stage)if(isset($stages[$stage],$model['stage_probability'][$stage])){
            // A factual CRM sale has its own observed goal; do not also call it a prediction.
            if(isset($stages['won']))return null;
            return ['model_id'=>$model['model_id'],'model_version'=>$model['version'],'evidence_sha256'=>$model['evidence_sha256'],
                'basis'=>'configured_conditional_probability_not_payment','stage'=>$stage,'probability'=>$model['stage_probability'][$stage],
                'event_unix'=>$stages[$stage],'value_rub'=>number_format((float)$model['base_value_rub']*$model['stage_probability'][$stage],2,'.','')];
        }
        return null;
    }

    private static function ownerEstimate(array $model,array $stages,int $now,?array $context): ?array
    {
        if($model['approved_at_unix']>$now||isset($stages['won'])||$context===null
            ||!is_int($context['recorded_at_unix']??null)||$context['recorded_at_unix']>$now
            ||$context['recorded_at_unix']<$now-21*86400)return null;
        $matches=[];
        foreach($model['prices']as$price)if($price['model']===($context['model']??null)&&$price['service']===($context['service']??null))$matches[]=$price;
        if(count($matches)!==1)return null;
        $price=$matches[0];
        foreach(['booking','offer_sent','working','customer_reply','manager_request','customer_activity']as$stage){
            $at=$stages[$stage]??null;
            if(!is_int($at)||$at>$now||$at<$now-21*86400)continue;
            $value=number_format((float)$price['amount_rub']*$model['probability'],2,'.','');
            if((float)$value<=0)return null;
            return ['model_id'=>$model['model_id'],'model_version'=>$model['version'],'evidence_sha256'=>$model['evidence_sha256'],
                'basis'=>'owner_assumed_probability_not_payment','approved_at_unix'=>$model['approved_at_unix'],
                'stage'=>$stage,'probability'=>$model['probability'],'price_basis'=>$model['price_basis'],
                'price'=>['model'=>$price['model'],'service'=>$price['service'],'amount_rub'=>$price['amount_rub'],
                    'currency'=>'RUB','source_id'=>$price['source_id']],
                'price_context'=>$context,'event_unix'=>max($at,$context['recorded_at_unix']),'value_rub'=>$value];
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
                $telegram=$project===AppleService39TelegramConnection::PROJECT?AppleService39TelegramConnection::forecastBinding($pdo):null;
                $repo=new MaxChannelConversionOutbox($pdo);$repo->recoverProject($policy,$now);$summary['collected']+=self::collect($pdo,$connection,$settings,$policy,$now,$telegram);
                foreach($repo->projectRows($policy,'pending',$now,25)as$row){$identity=self::identity($pdo,$row,$settings,$now,$telegram);$repo->resolveProject($row,$identity,$now);if($identity)$summary['resolved']++;}
                if($policy['mode']!=='send')continue;
                if($apiFactory)$api=$apiFactory($policy);
                else $api=self::api($policy);
                if(!$api){$summary['held']++;continue;}
                $goalsReady=$api->projectGoalsReady();if(!$goalsReady)$summary['held']++;
                foreach($goalsReady?$repo->projectRows($policy,'ready',$now):[]as$row){
                    $stage=substr($row['target'],7);$identity=self::identity($pdo,$row,$settings,$now,$telegram);
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
