<?php
declare(strict_types=1);
require_once __DIR__.'/ProjectFunnelSettings.php';
require_once __DIR__.'/AdvertisingVisitRepository.php';
require_once __DIR__.'/AppleService39PilotPublicationStore.php';
require_once __DIR__.'/ChannelPostRegistryService.php';

/** One server-owned path from a signed MAX launch to a verified native publication. */
final class ProjectFunnelEntry
{
    public function __construct(private PDO $pdo,private ProjectMaxConnection $connection,private array $settings) {}

    public function destination(string $post,string $offer=''): array
    {
        $post=$post===''?$this->settings['default_post']:$post;
        $item=$this->settings['posts'][$post]??null;
        if(!is_array($item)||($offer!==''&&$offer!==$item['offer_id']))throw new InvalidArgumentException('publication_unavailable');
        $q=$this->pdo->prepare("SELECT s.id FROM conversation_sources s JOIN projects p ON p.id=s.project_id WHERE p.project_key=? AND p.is_active=1 AND s.source_key=? AND s.channel='max' AND s.is_active=1");
        $q->execute([$this->connection->projectKey(),$this->settings['source_key']]);$source=(int)$q->fetchColumn();
        if($source<=0)throw new RuntimeException('source_unavailable');
        if($item['owner']==='appleservice39_pilot'){
            $url=(new AppleService39PilotPublicationStore($this->pdo))->verifiedPostUrl($post,$source);
        }else{
            $row=(new ChannelPostRegistryService($this->pdo))->publication($item['publication_id']);
            $url=$row&&$row['project_key']===$this->connection->projectKey()&&$row['platform']==='max'
                &&$row['bot_key']===$this->connection->botKey()&&$row['channel_id']===$this->settings['channel_id']
                &&$row['source_id']===$source&&$row['state']==='sent'
                &&!empty($row['receipt']['button_verified'])&&!empty($row['receipt']['content_preserved'])
                ?($row['snapshot']['post_url']??null):null;
        }
        if(!is_string($url)||!preg_match('~\A'.preg_quote($this->settings['channel_url'],'~').'/[A-Za-z0-9_-]{1,191}\z~D',$url))throw new RuntimeException('publication_unavailable');
        return ['post_id'=>$post,'offer_id'=>$item['offer_id'],'url'=>$url,'title'=>$item['title']];
    }

    public function capture(array $input,int $now): array
    {
        if(array_diff(array_keys($input),['init_data','start_param','client_id'])||!is_string($input['init_data']??null)
            ||!is_string($input['start_param']??null)||!is_string($input['client_id']??null)
            ||($input['client_id']!==''&&!preg_match('/\A[1-9][0-9]{0,39}\z/D',$input['client_id'])))throw new InvalidArgumentException('invalid_request');
        $launch=ProjectFunnelLaunch::verify($input['init_data'],$this->connection->token(),$now);
        if($launch['start_param']!==$input['start_param'])throw new InvalidArgumentException('conflicting_start');
        $payload=$launch['payload'];$destination=$this->destination($payload['post_id'],$payload['offer_id']);
        $result=['ok'=>true,'mode'=>'publication','saved'=>false,'post_id'=>$destination['post_id'],'offer_id'=>$destination['offer_id'],'redirect_url'=>$destination['url']];
        if($payload['kind']!=='advertising')return $result;
        $scope=self::scope($this->settings);
        $publicationProfile=[$this->settings['project_key'],$this->settings['bot_key'],$this->settings['source_key'],$this->settings['channel_id'],
            $destination['post_id'],$this->settings['posts'][$destination['post_id']]];
        $context=json_encode(['post_url'=>$destination['url'],'post_title'=>$destination['title'],
            'profile_sha256'=>hash('sha256',json_encode($publicationProfile,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $receipt=(new AdvertisingVisitRepository($this->pdo))->recordLaunch($scope,[
            'visit_id'=>$launch['visit_id'],'visit_at_unix'=>$launch['auth_date'],'observed_at_unix'=>$now,
            'external_user_id'=>$launch['external_user_id'],'identity_status'=>'verified','client_id'=>$input['client_id'],
            'yclid'=>$payload['yclid'],'campaign_id'=>$payload['campaign_id']==='0'?'':$payload['campaign_id'],'region_id'=>$payload['region_id'],
            'post_ref'=>$destination['post_id'],'offer_ref'=>$destination['offer_id'],'context_json'=>$context],$now);
        return array_replace($result,['mode'=>'advertising','saved'=>true,'receipt'=>$receipt,'analytics_bound'=>$scope['counter_id']!=='']);
    }

    public static function scope(array $settings): array
    {
        return ['project_key'=>$settings['project_key'],'source_key'=>$settings['advertising_source_key'],'platform'=>'max','counter_id'=>$settings['metrika']['counter_id']];
    }

    /** The same publication owner gates every exported row. No placeholder URLs or prices. */
    public function feed(): string
    {
        $stream=fopen('php://temp','w+');
        fputcsv($stream,['ID','URL','Image','Title','Description','Price','Currency','custom_label_0','custom_label_1','custom_label_2','custom_label_3','custom_label_4']);
        foreach($this->settings['posts']as$post=>$item){
            $this->destination((string)$post,$item['offer_id']);
            fputcsv($stream,[$item['offer_id'],ProjectFunnelLaunch::feedUrl($this->connection->botKey(),(string)$post,$item['offer_id']),$item['image_url'],$item['title'],$item['description'],'','',...($item['labels']??array_fill(0,5,''))]);
        }
        rewind($stream);$csv=stream_get_contents($stream);fclose($stream);return $csv;
    }
}
