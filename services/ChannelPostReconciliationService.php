<?php
declare(strict_types=1);
require_once __DIR__.'/ChannelPostReconciliationState.php';
require_once __DIR__.'/ChannelPostAutomationService.php';

/** Bounded MAX provider reads recover missing publications without replaying a webhook. */
final class ChannelPostReconciliationService
{
    private ChannelPostRegistryService $registry;
    private ChannelPostReconciliationState $state;
    private ChannelPostButtonTransport $transport;
    private $request;
    private $clock;
    public function __construct(?PDO $pdo=null, ?callable $request=null, ?callable $clock=null)
    {
        $pdo=$pdo??ConversationDb::connection();
        $this->registry=new ChannelPostRegistryService($pdo);
        $this->state=new ChannelPostReconciliationState($pdo);
        $this->request=$request??[ChannelPostApiTransport::class,'buttonRequest'];
        $this->transport=new ChannelPostButtonTransport($this->request);
        $this->clock=$clock??static fn():int=>(int)floor(microtime(true)*1000);
    }

    public function scan(array $identity, bool $apply=false): array
    {
        $claim=null;
        try {
            self::identity($identity);
            $claim=$this->state->claim($identity['project_key'],$identity['bot_key'],($this->clock)(),$apply);
            if(!$claim)return ['ok'=>true,'state'=>'idle','observed'=>0,'registered'=>0];
            $channel=$this->registry->channel($identity['project_key'],'max',$identity['bot_key'],(string)$claim['channel_id']);
            if(!$channel||(int)$channel['config_version']!==(int)$claim['config_version'])throw new RuntimeException('channel_configuration_changed');
            $this->verify($channel);
            $messages=$this->page((string)$channel['channel_id'],(int)$claim['before_ms'],(int)$claim['after_ms']);
            $registered=0;$oldest=(int)$claim['before_ms'];
            foreach($messages as $m) {
                $stamp=(int)$m['timestamp'];$oldest=min($oldest,$stamp);
                if($apply) {
                    $p=$this->registry->registerProviderObservation($identity['project_key'],$identity['bot_key'],$channel,$m,(int)$claim['enabled_since_ms']);
                    if(!$p)throw new RuntimeException('channel_configuration_changed');
                    if(($p['state']??'')==='pending')$registered++;
                }
            }
            $full=count($messages)===100;
            if($full&&$oldest>=(int)$claim['before_ms'])throw new RuntimeException('pagination_boundary_requires_attention');
            $next=$full?$oldest:null;
            if($apply&&!$this->state->finish($claim,$next))throw new RuntimeException('reconciliation_claim_changed');
            return ['ok'=>true,'state'=>$apply?'observed':'preview','observed'=>count($messages),'registered'=>$registered,'more'=>$full];
        } catch(Throwable $e) {
            $reason=self::reason($e);
            if($apply&&$claim)try{$this->state->finish($claim,null,$reason);}catch(Throwable $ignored){}
            return ['ok'=>false,'state'=>'requires_attention','reason'=>$reason];
        }
    }

    /** Exact owner-selected publication; preview makes zero database/provider writes. */
    public function recover(array $identity, string $channelId, int $publishedMs, string $expectedHash='', bool $apply=false): array
    {
        try {
            self::identity($identity);
            if($publishedMs<=0||($apply&&!preg_match('/\A[a-f0-9]{64}\z/',$expectedHash)))throw new RuntimeException('exact_target_required');
            $channel=$this->registry->channel($identity['project_key'],'max',$identity['bot_key'],$channelId);
            if(!$channel)throw new RuntimeException('channel_not_enabled');
            $this->verify($channel);
            $messages=$this->page((string)$channel['channel_id'],$publishedMs,$publishedMs);
            if(count($messages)!==1)throw new RuntimeException('exact_target_not_unique');
            $message=$messages[0];$snapshot=ChannelPostIncomingAdapter::maxSnapshot($message);
            if(!$snapshot||($snapshot['metadata']['capability']??'')!=='supported')throw new RuntimeException('unsupported_post');
            $hash=ChannelPostIncomingAdapter::contentFingerprint($snapshot);
            if($expectedHash!==''&&!hash_equals($expectedHash,$hash))throw new RuntimeException('target_content_changed');
            $safe=['ok'=>true,'state'=>'preview','content_hash'=>$hash,'image_count'=>count($snapshot['attachments']),'published_ms'=>$publishedMs,'database_writes'=>0,'provider_writes'=>0];
            if(!$apply)return $safe;
            $p=$this->registry->registerProviderObservation($identity['project_key'],$identity['bot_key'],$channel,$message,$publishedMs);
            if(!$p||!hash_equals((string)$p['content_hash'],$hash))throw new RuntimeException('target_registration_changed');
            if($p['state']==='sent'&&ChannelPostIncomingAdapter::containsOnlyButton($snapshot,ChannelPostButtonTransport::buttonUrl($p),(string)$channel['button_text']))return array_replace($safe,['state'=>'sent','already_present'=>true]);
            $result=(new ChannelPostAutomationService($this->registry,$this->transport))->processPublication($identity['project_key'],'max',$identity['bot_key'],(int)$p['publication_id'],(int)$p['version_id']);
            return ['ok'=>($result['state']??'')==='sent','state'=>$result['state']??'unknown','reason'=>$result['reason']??'','content_hash'=>$hash,'image_count'=>count($snapshot['attachments']),'version_number'=>(int)$p['version_number'],'receipt'=>$result['receipt']??[]];
        } catch(Throwable $e) {return ['ok'=>false,'state'=>'requires_attention','reason'=>self::reason($e)];}
    }

    private function verify(array $channel): void
    {
        $check=$this->transport->verifyChannel($channel);
        if(($check['state']??'')!=='ready')throw new RuntimeException('provider_channel_verification_failed');
    }

    private function page(string $channelId,int $before,int $after): array
    {
        $r=($this->request)('max','GET','/messages',['chat_id'=>$channelId,'from'=>$before,'to'=>$after,'count'=>100],null);
        if(!empty($r['transport_error'])||(int)($r['http']??0)!==200||!is_array($r['data']['messages']??null)||!array_is_list($r['data']['messages'])||count($r['data']['messages'])>100)throw new RuntimeException('provider_page_unavailable');
        $messages=$r['data']['messages'];$ids=[];
        foreach($messages as $m) {
            $stamp=$m['timestamp']??null;$mid=$m['body']['mid']??null;
            if(!is_array($m)||($m['recipient']['chat_type']??'')!=='channel'||(string)($m['recipient']['chat_id']??'')!==$channelId
                ||(!is_int($stamp)&&!is_string($stamp))||!preg_match('/\A[1-9][0-9]{0,15}\z/',(string)$stamp)
                ||(int)$stamp<$after||(int)$stamp>$before||!is_string($mid)||!preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/',$mid)||isset($ids[$mid])
                ||ChannelPostIncomingAdapter::maxSnapshot($m)===null)throw new RuntimeException('provider_page_identity_mismatch');
            $ids[$mid]=true;
        }
        return $messages;
    }
    private static function identity(array $identity): void
    {
        if(($identity['platform']??'')!=='max'||!is_string($identity['project_key']??null)||!is_string($identity['bot_key']??null))throw new RuntimeException('canonical_identity_required');
    }
    private static function reason(Throwable $e): string
    {
        $known=['channel_configuration_changed','provider_channel_verification_failed','provider_page_unavailable','provider_page_identity_mismatch','pagination_boundary_requires_attention','reconciliation_claim_changed','exact_target_required','channel_not_enabled','exact_target_not_unique','unsupported_post','target_content_changed','target_registration_changed','canonical_identity_required'];
        return in_array($e->getMessage(),$known,true)?$e->getMessage():'reconciliation_unavailable';
    }
}
