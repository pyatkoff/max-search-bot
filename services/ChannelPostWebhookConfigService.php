<?php
declare(strict_types=1);
require_once __DIR__.'/WebhookTargetConfig.php';
require_once __DIR__.'/../integrations/ChannelPostApiTransport.php';

/** Explicit operator setup, addition-only event types. Never delete a webhook. */
final class ChannelPostWebhookConfigService
{
    private $request;
    private $claimWrite;
    private array $secrets;
    private array $receipt=[];

    public function __construct(?callable $request=null,?callable $claimWrite=null,?array $secrets=null)
    {
        $this->request=$request??[self::class,'networkRequest'];
        $this->claimWrite=$claimWrite??[self::class,'persistWriteClaim'];
        $this->secrets=$secrets??[
            'max'=>defined('MAX_SEARCH_WEBHOOK_SECRET')?(string)MAX_SEARCH_WEBHOOK_SECRET:'',
            'telegram'=>defined('TELEGRAM_WEBHOOK_SECRET')?(string)TELEGRAM_WEBHOOK_SECRET:'',
        ];
    }

    /** Dry-run is the default. A previous durable write claim permits reads only. */
    public function run(string $platform,array $identity,bool $apply=false): array
    {
        $this->receipt=['request_count'=>0,'write_attempts'=>0,'http_results'=>[]];
        try {
            self::check(in_array($platform,['max','telegram'],true),'invalid_platform');
            $bot=(string)($identity['bot_key']??'');$project=(string)($identity['project_key']??'');
            self::check(($identity['platform']??'')===$platform&&preg_match('/\A[A-Za-z0-9_]{3,96}\z/',$bot)===1&&preg_match('/\A[A-Za-z0-9_:-]{1,64}\z/',$project)===1,'canonical_identity_not_configured');
            self::check(($identity['bot_url']??'')===($platform==='max'?'https://max.ru/':'https://t.me/').$bot,'canonical_bot_url_mismatch');
            $url=$platform==='max'?WebhookTargetConfig::max():WebhookTargetConfig::telegram();
            $me=$this->read($platform,$platform==='max'?'/me':'getMe','bot_identity');
            self::check(($me['is_bot']??false)===true&&($me['username']??'')===$bot&&isset($me[$platform==='max'?'user_id':'id']),'canonical_bot_identity_mismatch');
            $before=$this->configuration($platform,$url,'current_configuration');
            $required=$platform==='max'?['message_created','message_edited']:['channel_post','edited_channel_post'];
            $types=$before['types'];
            $missing=$types===null?[]:array_values(array_diff($required,$types));
            $desired=$types===null?null:array_values(array_unique(array_merge($types,$required)));
            $this->receipt['added_update_types']=$missing;
            $this->receipt['previous_update_types']=$types??[];
            $this->receipt['receives_all_default_updates']=$types===null;
            if(isset($before['pending_update_count']))$this->receipt['pending_update_count_before']=$before['pending_update_count'];
            if($missing===[])return $this->result('ready','already_configured');
            if(!$apply)return $this->result('planned','addition_only_dry_run');
            $secret=(string)($this->secrets[$platform]??'');
            self::check(preg_match($platform==='max'?'/\A[A-Za-z0-9_-]{5,256}\z/':'/\A[A-Za-z0-9_-]{1,256}\z/',$secret)===1,'configured_webhook_secret_required');
            if($platform==='telegram')self::check(($before['has_custom_certificate']??false)===false,'custom_certificate_preservation_requires_review');
            // Re-read before committing a provider write. No new webhook URL,
            // extra MAX owner or concurrently changed event list is overwritten.
            $fresh=$this->configuration($platform,$url,'configuration_before_write');
            self::check(self::fingerprint($before)===self::fingerprint($fresh),'webhook_configuration_changed');
            $key=hash('sha256',$project.'|'.$platform.'|'.$bot.'|'.$url);
            self::check(($this->claimWrite)($key,['platform'=>$platform,'update_types'=>$desired])===true,'previous_apply_requires_readback');
            $body=['url'=>$url,$platform==='max'?'update_types':'allowed_updates'=>$desired,$platform==='max'?'secret':'secret_token'=>$secret];
            if($platform==='telegram'){
                $body['drop_pending_updates']=false;
                $body['max_connections']=$before['max_connections'];
                // getWebhookInfo.ip_address does not identify DNS vs fixed IP.
                // Do not introduce an unsolicited fixed IP setting.
            }
            $written=$this->call($platform,'POST',$platform==='max'?'/subscriptions':'setWebhook',$body,'add_channel_update_types');
            try {
                $after=$this->configuration($platform,$url,'verify_configuration');
                if(isset($after['pending_update_count']))$this->receipt['pending_update_count_after']=$after['pending_update_count'];
                $this->receipt['update_types_preserved']=$after['types']!==null&&array_diff($desired,$after['types'])===[];
                $this->receipt['channel_update_types_verified']=$after['types']===null||array_diff($required,$after['types'])===[];
                if($platform==='telegram')$this->receipt['max_connections_preserved']=$after['max_connections']===$before['max_connections'];
                if($this->receipt['update_types_preserved']&&$this->receipt['channel_update_types_verified']&&($platform==='max'||$this->receipt['max_connections_preserved']))return $this->result('ready',self::accepted($written,$platform)?'verified':'verified_after_unknown_response');
            } catch(Throwable $e) {$this->receipt['readback_unavailable']=true;}
            return $this->result('unknown','apply_requires_readback');
        } catch(ChannelPostWebhookConfigFailure $e) {
            $state=$this->receipt['write_attempts']>0||$e->getMessage()==='previous_apply_requires_readback'?'unknown':'blocked';
            return $this->result($state,$e->getMessage());
        } catch(Throwable $e) {return $this->result($this->receipt['write_attempts']>0?'unknown':'blocked','configuration_unavailable');}
    }

    private function configuration(string $platform,string $url,string $purpose): array
    {
        $data=$this->read($platform,$platform==='max'?'/subscriptions':'getWebhookInfo',$purpose);
        if($platform==='max'){
            self::check(is_array($data['subscriptions']??null)&&count($data['subscriptions'])===1,'exact_single_max_subscription_required');
            $subscription=array_values($data['subscriptions'])[0];
            self::check(is_array($subscription)&&($subscription['url']??'')===$url,'canonical_webhook_url_mismatch');
            self::check(array_key_exists('update_types',$subscription),'existing_update_types_unavailable');
            $types=self::types($subscription['update_types']);
            // An empty MAX subscription's meaning is insufficiently documented;
            // do not turn a possibly broad subscription into a restricted one.
            self::check($types!==[],'existing_update_types_unavailable');
            return ['types'=>$types];
        }
        self::check(($data['url']??'')===$url,'canonical_webhook_url_mismatch');
        $types=array_key_exists('allowed_updates',$data)?self::types($data['allowed_updates']):[];
        self::check(is_bool($data['has_custom_certificate']??null),'existing_delivery_configuration_unavailable');
        self::check(isset($data['max_connections'])&&is_int($data['max_connections'])&&$data['max_connections']>=1&&$data['max_connections']<=100,'existing_delivery_configuration_unavailable');
        self::check(isset($data['pending_update_count'])&&is_int($data['pending_update_count'])&&$data['pending_update_count']>=0,'existing_delivery_configuration_unavailable');
        return ['types'=>$types===[]?null:$types,'max_connections'=>$data['max_connections'],'has_custom_certificate'=>$data['has_custom_certificate'],'pending_update_count'=>$data['pending_update_count']];
    }

    private function read(string $platform,string $path,string $purpose): array
    {
        $response=$this->call($platform,$platform==='max'?'GET':'POST',$path,$platform==='max'?null:[],$purpose);
        self::check(!$response['transport_error']&&$response['http']>=200&&$response['http']<300&&is_array($response['data']),'provider_read_failed');
        if($platform==='telegram'){
            self::check(($response['data']['ok']??false)===true&&is_array($response['data']['result']??null),'provider_read_failed');
            return $response['data']['result'];
        }
        return $response['data'];
    }

    private function call(string $platform,string $method,string $path,?array $body,string $purpose): array
    {
        self::check($this->receipt['request_count']<5,'request_budget_exhausted');
        if($purpose==='add_channel_update_types'){
            self::check($this->receipt['write_attempts']===0,'write_budget_exhausted');
            $this->receipt['write_attempts']++;
        }
        $this->receipt['request_count']++;
        try{$response=($this->request)($platform,$method,$path,[],$body);}catch(Throwable $e){$response=['http'=>0,'transport_error'=>true];}
        $safe=['http'=>(int)($response['http']??0),'transport_error'=>($response['transport_error']??false)===true,'data'=>is_array($response['data']??null)?$response['data']:null];
        $this->receipt['http_results'][]=['operation'=>$purpose,'http'=>$safe['http'],'transport_error'=>$safe['transport_error']];
        return $safe;
    }

    private static function types($value): array
    {
        self::check(is_array($value)&&array_is_list($value)&&count($value)<=128,'existing_update_types_unavailable');
        foreach($value as $type)self::check(is_string($type)&&preg_match('/\A[a-z_]{1,64}\z/',$type)===1,'existing_update_types_unavailable');
        return array_values(array_unique($value));
    }
    private static function fingerprint(array $value): string {unset($value['pending_update_count']);return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR));}
    private static function accepted(array $response,string $platform): bool {return !$response['transport_error']&&$response['http']>=200&&$response['http']<300&&($response['data'][$platform==='max'?'success':'ok']??false)===true;}
    private function result(string $state,string $reason): array {return ['ok'=>$state==='ready'||$state==='planned','state'=>$state,'reason'=>$reason,'receipt'=>$this->receipt];}
    private static function check(bool $ok,string $reason): void {if(!$ok)throw new ChannelPostWebhookConfigFailure($reason);}

    /** Store the write claim before sending. A lost response never permits retry. */
    public static function persistWriteClaim(string $key,array $summary): bool
    {
        if(preg_match('/\A[a-f0-9]{64}\z/',$key)!==1)return false;
        $dir=rtrim((string)(getenv('CHANNEL_POST_WEBHOOK_STATE_DIR')?:rtrim(sys_get_temp_dir(),'/').'/max-search-bot-channel-post-webhook-operations'),'/');
        if($dir===''||$dir[0]!=='/'||is_link($dir))return false;
        $root=realpath(dirname(__DIR__));if($root===false)return false;
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))return false;
        $resolved=realpath($dir);$documentRoot=realpath((string)($_SERVER['DOCUMENT_ROOT']??''));
        if($resolved===false||$resolved===$root||str_starts_with($resolved,$root.'/')||($documentRoot!==false&&($resolved===$documentRoot||str_starts_with($resolved,$documentRoot.'/'))))return false;
        if(!@chmod($resolved,0700))return false;
        $file=$resolved.'/'.$key.'.json';if(is_link($file))return false;
        $handle=@fopen($file,'x');if(!$handle)return false;
        $record=json_encode(['claimed_at'=>gmdate('c'),'platform'=>$summary['platform']??'','update_types'=>$summary['update_types']??[]],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $ok=@chmod($file,0600)&&@fwrite($handle,$record)===strlen($record)&&@fflush($handle);
        if($ok&&function_exists('fsync'))$ok=@fsync($handle);
        @fclose($handle);
        return $ok;
    }

    /** Provider network ownership stays in the bounded integration transport. */
    public static function networkRequest(string $platform,string $method,string $path,array $query,?array $body): array
    {
        return ChannelPostApiTransport::webhookRequest($platform,$method,$path,$query,$body);
    }
}
final class ChannelPostWebhookConfigFailure extends RuntimeException {}
