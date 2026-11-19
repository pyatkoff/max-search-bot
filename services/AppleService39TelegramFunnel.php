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

    public static function recordAttribution(PDO $pdo,array $binding,int $conversationId,string $user,array $entry,int $messageAt): void
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
            $q=$pdo->prepare("SELECT 1 FROM conversation_events WHERE conversation_id=? AND event_type='apple_telegram_ad_entry' LIMIT 1");
            $q->execute([$conversationId]);
            if($q->fetchColumn())return;
            ConversationControlService::event($conversationId,'apple_telegram_ad_entry','system',null,[
                'advertising_scope'=>$scope,'bot_id'=>$binding['bot_id'],'post_id'=>$entry['post_id'],'offer_id'=>$offer,
                'visit_id'=>$visits[0],'post_url'=>$url,'start_unix'=>$messageAt,
            ]);
        }catch(PDOException $unavailable){
            return;
        }
    }
}
