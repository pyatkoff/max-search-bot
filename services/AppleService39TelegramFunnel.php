<?php
declare(strict_types=1);

require_once __DIR__.'/AdvertisingVisitRepository.php';
require_once __DIR__.'/ProjectFunnelLaunch.php';

final class AppleService39TelegramFunnel
{
    private const PROJECT = 'appleservice39';
    private const COUNTER = '42611469';
    private const CHANNEL = 'https://t.me/appleservice39';
    private const AD_SOURCE = 'apple-tg-miniapp';

    public static function client(): array
    {
        return ['channel_url'=>self::CHANNEL,'counter_id'=>self::COUNTER,'platform'=>'telegram'];
    }

    private static function scope(): array
    {
        return ['project_key'=>self::PROJECT,'source_key'=>self::AD_SOURCE,'platform'=>'telegram','counter_id'=>self::COUNTER];
    }

    private static function context(array $binding,array $entry,string $url): string
    {
        $title=trim(implode(' ',[$entry['model'],$entry['service']])) ?: 'AppleService39';
        $profile=[$binding['bot_id'],$binding['source_id'],$entry['post_id'],$entry['start_payload']];
        return json_encode(['post_url'=>$url,'post_title'=>$title,'profile_sha256'=>hash('sha256',json_encode($profile,JSON_THROW_ON_ERROR))],
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }

    public static function capture(PDO $pdo,array $binding,array $input,int $now): array
    {
        if(array_diff(array_keys($input),['init_data','start_param','client_id'])
            || !is_string($input['init_data']??null) || !is_string($input['start_param']??null)
            || !is_string($input['client_id']??null)
            || ($input['client_id']!=='' && !preg_match('/\A[1-9][0-9]{0,39}\z/D',$input['client_id']))) {
            throw new InvalidArgumentException('invalid_request');
        }
        $launch=ProjectFunnelLaunch::verify($input['init_data'],$binding['bot_token'],$now,'telegram');
        $payload=$launch['payload'];
        $entry=AppleService39PilotCatalog::landingEntryForPost($payload['post_id']);
        if($launch['start_param']!==$input['start_param'] || $payload['kind']!=='advertising' || !$entry
            || $payload['offer_id']!=='as39-tg-'.$entry['post_id']) {
            throw new InvalidArgumentException('invalid_publication');
        }
        $url=AppleService39TelegramIngress::publishedPost($pdo,$entry,$binding,$binding['bot_id']);
        if($url==='')throw new InvalidArgumentException('publication_unavailable');
        $receipt=(new AdvertisingVisitRepository($pdo))->recordLaunch(self::scope(),[
            'visit_id'=>$launch['visit_id'],'visit_at_unix'=>$launch['auth_date'],'observed_at_unix'=>$now,
            'external_user_id'=>$launch['external_user_id'],'identity_status'=>'verified','client_id'=>$input['client_id'],
            'yclid'=>$payload['yclid'],'campaign_id'=>$payload['campaign_id']==='0'?'':$payload['campaign_id'],
            'region_id'=>$payload['region_id'],'post_ref'=>$entry['post_id'],'offer_ref'=>$payload['offer_id'],
            'context_json'=>self::context($binding,$entry,$url),
        ],$now);
        return ['ok'=>true,'mode'=>'advertising','saved'=>true,'post_id'=>$entry['post_id'],
            'offer_id'=>$payload['offer_id'],'redirect_url'=>$url,'receipt'=>$receipt];
    }

    public static function feed(PDO $pdo,array $binding): string
    {
        $input=fopen(dirname(__DIR__).'/resources/appleservice39/telegram-offers-v1.csv','rb');
        if(!$input)throw new RuntimeException('feed_unavailable');
        $output=fopen('php://temp','w+');
        if(!$output){fclose($input);throw new RuntimeException('feed_unavailable');}
        try {
            $header=fgetcsv($input,0,',','"','');
            if($header!==['ID','URL','Image','Title','Description','Price','Currency','custom_label_0','custom_label_1','custom_label_2','custom_label_3','custom_label_4']) {
                throw new RuntimeException('feed_invalid');
            }
            fputcsv($output,$header,',','"','');
            $seen=[];$count=0;
            while(($row=fgetcsv($input,0,',','"',''))!==false){
                if(count($row)!==count($header) || !preg_match('/\Aas39-tg-([a-z0-9-]{1,59})\z/D',$row[0],$m) || isset($seen[$row[0]])) {
                    throw new RuntimeException('feed_invalid');
                }
                $seen[$row[0]]=true;
                $entry=AppleService39PilotCatalog::landingEntryForPost($m[1]);
                if(!$entry)throw new RuntimeException('feed_invalid');
                if(AppleService39TelegramIngress::publishedPost($pdo,$entry,$binding,$binding['bot_id'])!==$row[1])continue;
                $row[1]='https://t.me/'.$binding['bot_username'].'?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_'
                    .ProjectFunnelLaunch::context($entry['post_id'],$row[0]);
                fputcsv($output,$row,',','"','');$count++;
            }
            if($count===0)throw new RuntimeException('feed_unavailable');
            rewind($output);
            $csv=stream_get_contents($output);
            if($csv===false)throw new RuntimeException('feed_unavailable');
            return $csv;
        }finally{
            fclose($input);
            fclose($output);
        }
    }

    public static function recordAttribution(PDO $pdo,array $binding,int $conversationId,string $user,array $entry,int $messageAt,string $messageId): void
    {
        $now=time();
        if($messageAt<$now-86400 || $messageAt>$now+60)return;
        $url=AppleService39TelegramIngress::publishedPost($pdo,$entry,$binding,$binding['bot_id']);
        if($url==='')return;
        $scope=self::scope();
        $offer='as39-tg-'.$entry['post_id'];
        try{
            $q=$pdo->prepare("SELECT DISTINCT visit_id FROM advertising_visit_observations
                WHERE project_key=? AND source_key=? AND platform=? AND counter_id=? AND external_user_id=?
                  AND identity_status='verified' AND post_ref=? AND offer_ref=? AND context_json=?
                  AND visit_at_unix BETWEEN ? AND ? AND observed_at_unix<=? LIMIT 2");
            $q->execute([...array_values($scope),$user,$entry['post_id'],$offer,self::context($binding,$entry,$url),$messageAt-86400,$messageAt,$messageAt]);
            $visits=$q->fetchAll(PDO::FETCH_COLUMN);
            if(count($visits)!==1)return;
            // A private start can recur after the original conversation is closed. Its visit belongs to that original entry.
            $q=$pdo->prepare("SELECT 1 FROM conversations c JOIN conversation_events e ON e.conversation_id=c.id
                WHERE c.project_key=? AND c.channel='telegram' AND c.source_id=? AND c.external_chat_id=?
                AND e.event_type='apple_telegram_ad_entry' AND INSTR(e.payload_json,?)>0 LIMIT 1");
            $q->execute([self::PROJECT,$binding['source_id'],$user,'"visit_id":'.json_encode($visits[0],JSON_THROW_ON_ERROR)]);
            if($q->fetchColumn())return;
            $q=$pdo->prepare("SELECT 1 FROM conversation_events WHERE conversation_id=? AND event_type='apple_telegram_ad_entry' LIMIT 1");
            $q->execute([$conversationId]);
            if($q->fetchColumn())return;
            ConversationControlService::event($conversationId,'apple_telegram_ad_entry','system',null,[
                'advertising_scope'=>$scope,'bot_id'=>$binding['bot_id'],'post_id'=>$entry['post_id'],'offer_id'=>$offer,
                'visit_id'=>$visits[0],'post_url'=>$url,'start_unix'=>$messageAt,'message_id'=>$messageId,
            ]);
        }catch(PDOException $unavailable){
            return;
        }
    }

    /** Resolve the persisted private start and its exact, already linked paid visit. */
    public static function forecastEvidence(PDO $pdo,int $conversationId,array $binding,int $now,?callable $read=null): ?array
    {
        if(($binding['project_key']??null)!==self::PROJECT
            ||($binding['source_key']??null)!==AppleService39TelegramConnection::SOURCE)return null;
        $read??=static function(string $sql,array $args)use($pdo):array{
            $q=$pdo->prepare($sql);
            $q->execute($args);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        };
        $owners=$read("SELECT c.metadata_json,cc.external_user_id
            FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id
              AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel
              AND cc.external_chat_id=c.external_chat_id AND cc.external_user_id=c.external_chat_id
            WHERE c.id=? AND c.project_key=? AND c.channel='telegram' AND c.source_id=? AND c.is_test=0 LIMIT 2",
            [$conversationId,self::PROJECT,$binding['source_id']]);
        $owner=count($owners)===1?$owners[0]:null;
        if(!$owner||(json_decode($owner['metadata_json'],true)['telegram_bot_id']??null)!==$binding['bot_id'])return null;
        $user=$owner['external_user_id'];
        $events=$read("SELECT id,payload_json FROM conversation_events WHERE conversation_id=?
            AND event_type='apple_telegram_ad_entry' AND actor_type='system' ORDER BY id LIMIT 2",[$conversationId]);
        if(count($events)!==1)return null;
        $event=$events[0];
        $anchor=json_decode($event['payload_json'],true);
        $at=ConversationRecordClock::read($event['payload_json']);
        $start=$anchor['start_unix']??null;
        $messageId=$anchor['message_id']??null;
        $visit=$anchor['visit_id']??null;
        if($at===null||$at>$now||!is_int($start)||$start>$at
            ||$start<$now-21*86400||$at-$start>86400
            ||!is_string($messageId)||!preg_match('/\A[1-9][0-9]{0,15}\z/D',$messageId)
            ||!is_string($visit)||!str_starts_with($visit,'tg1_')||strlen($visit)>191
            ||($anchor['advertising_scope']??null)!==self::scope()
            ||($anchor['bot_id']??null)!==$binding['bot_id']||!is_string($anchor['post_id']??null))return null;
        $entry=AppleService39PilotCatalog::landingEntryForPost($anchor['post_id']);
        if(!$entry||!in_array($entry['scope']??'repair',['repair','search_repair','model_service'],true)
            ||$entry['model']===''||$entry['service']===''||($anchor['offer_id']??null)!=='as39-tg-'.$entry['post_id'])return null;
        $url=AppleService39TelegramIngress::publishedPost($pdo,$entry,$binding,$binding['bot_id']);
        if($url===''||($anchor['post_url']??null)!==$url)return null;
        $messages=$read("SELECT metadata_json FROM messages WHERE conversation_id=? AND channel='telegram'
            AND direction='inbound' AND sender_type='customer' AND sender_id=? AND external_message_id=? AND text='/start' LIMIT 2",
            [$conversationId,$user,$messageId]);
        if(count($messages)!==1)return null;
        $messageAt=ConversationRecordClock::read($messages[0]['metadata_json']);
        $message=json_decode($messages[0]['metadata_json'],true);
        $key=hash('sha256',implode("\0",[self::PROJECT,'telegram',$binding['bot_id'],$user,$messageId]));
        if($messageAt===null||$messageAt>$at||$messageAt<$start||($message['delivery_key']??null)!==$key)return null;

        $rows=$read("SELECT * FROM advertising_visit_observations WHERE project_key=? AND source_key=?
            AND platform=? AND counter_id=? AND visit_id=? ORDER BY id LIMIT 101",[...array_values(self::scope()),$visit]);
        if(!$rows||count($rows)>=101)return null;
        $identity=null;
        $context=self::context($binding,$entry,$url);
        foreach($rows as $row){
            if($row['identity_status']!=='verified'||$row['external_user_id']!==$user
                ||$row['post_ref']!==$entry['post_id']||$row['offer_ref']!==$anchor['offer_id']
                ||$row['context_json']!==$context||!preg_match('/\A[1-9][0-9]{0,39}\z/D',(string)$row['yclid'])
                ||(int)$row['visit_at_unix']<$start-86400||(int)$row['visit_at_unix']>$start
                ||(int)$row['observed_at_unix']>$now)return null;
            if($row['client_id']==='')continue;
            if(!preg_match('/\A[1-9][0-9]{0,39}\z/D',$row['client_id'])
                ||($identity!==null&&$row['client_id']!==$identity['client_id']))return null;
            if((int)$row['observed_at_unix']<=$start)$identity=$row;
        }
        if($identity===null)return null;
        $priceContext=['model'=>$entry['model'],'service'=>$entry['service'],'entry_code'=>$entry['start_payload'],
            'post_id'=>$entry['post_id'],'entry_key'=>$key,'recorded_at_unix'=>$at,'source_event_id'=>(string)$event['id']];
        return ['anchor'=>$anchor,'price_context'=>$priceContext,'identity'=>$identity,'event_unix'=>$at];
    }
}
