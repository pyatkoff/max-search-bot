<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ChannelPostWebhookConfigService.php';
require_once dirname(__DIR__).'/services/ChannelPostRuntime.php';
$passed=0;
function cpwCheck(string $name,bool $ok):void{global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
final class ChannelPostWebhookFixture
{
    public array $calls=[];
    public array $claims=[];
    public array $types=['message_callback','bot_started','message_created','message_removed'];
    public string $mode='success';
    public string $bot='id9704048781_1_bot';
    public string $url='https://app.anytoour.ru/webhook.php';
    public string $platform='max';
    public int $subscriptions=1;
    public int $configReads=0;
    public bool $certificate=false;
    public int $pending=12;
    public int $maxConnections=23;
    public function request(string $platform,string $method,string $path,array $query,?array $body):array
    {
        $this->calls[]=compact('platform','method','path','query','body');
        if($path==='/me'||$path==='getMe'){
            $bot=['is_bot'=>true,'username'=>$this->bot,$platform==='max'?'user_id':'id'=>123456789];
            return ['http'=>200,'data'=>$platform==='max'?$bot:['ok'=>true,'result'=>$bot]];
        }
        if($path==='getChatMember')return ['http'=>200,'data'=>['ok'=>true,'result'=>[
            'user'=>['id'=>123456789,'is_bot'=>true,'username'=>$this->bot],
            'status'=>'administrator','can_edit_messages'=>true,
        ]]];
        if($path==='getChat')return ['http'=>200,'data'=>['ok'=>true,'result'=>['id'=>$body['chat_id'],'type'=>'channel']]];
        $write=($platform==='max'&&$method==='POST')||$path==='setWebhook';
        if($write){
            if(!in_array($this->mode,['timeout_unobserved','rejected'],true))$this->types=$body[$platform==='max'?'update_types':'allowed_updates'];
            if(str_starts_with($this->mode,'timeout'))return ['http'=>0,'transport_error'=>true,'data'=>['description'=>'PRIVATE_PROVIDER_SECRET']];
            if($this->mode==='rejected')return ['http'=>400,'data'=>['success'=>false,'ok'=>false,'description'=>'PRIVATE_PROVIDER_SECRET']];
            return ['http'=>200,'data'=>$platform==='max'?['success'=>true]:['ok'=>true,'result'=>true]];
        }
        $this->configReads++;
        if($this->mode==='concurrent_types'&&$this->configReads===2)$this->types[]='chat_title_changed';
        if($this->mode==='post_write_read_failure'&&$this->configReads===3)return ['http'=>500,'data'=>['description'=>'PRIVATE_PROVIDER_SECRET']];
        if($platform==='max'){
            $rows=array_fill(0,$this->subscriptions,['url'=>$this->url,'update_types'=>$this->types,'secret'=>'PRIVATE_PROVIDER_SECRET']);
            return ['http'=>200,'data'=>['subscriptions'=>$rows]];
        }
        return ['http'=>200,'data'=>['ok'=>true,'result'=>[
            'url'=>$this->url,'allowed_updates'=>$this->types,'max_connections'=>$this->maxConnections,'has_custom_certificate'=>$this->certificate,
            'pending_update_count'=>$this->pending,'ip_address'=>'127.0.0.1','last_error_message'=>'PRIVATE_PROVIDER_SECRET',
        ]]];
    }
    public function claim(string $key,array $summary):bool
    {
        if(isset($this->claims[$key]))return false;$this->claims[$key]=$summary;return true;
    }
    public function service(array $secrets=['max'=>'synthetic_max_secret','telegram'=>'synthetic_tg_secret']):ChannelPostWebhookConfigService{return new ChannelPostWebhookConfigService([$this,'request'],[$this,'claim'],$secrets);}
    public function writes():array{return array_values(array_filter($this->calls,static fn(array $call):bool=>$call['path']==='setWebhook'||($call['path']==='/subscriptions'&&$call['method']==='POST')));}
}
$max=['project_key'=>'anytour','platform'=>'max','bot_key'=>'id9704048781_1_bot','bot_url'=>'https://max.ru/id9704048781_1_bot'];
$tg=['project_key'=>'anytour','platform'=>'telegram','bot_key'=>'Any_tour_bot','bot_url'=>'https://t.me/Any_tour_bot'];

// Exercise the versioned runtime identity, independently of synthetic bot fixtures.
ProjectConfig::resetForTests(null);
$runtimeTg=ChannelPostRuntime::identity('telegram');
$f=new ChannelPostWebhookFixture();$f->bot='anyru_bot';
$transport=new ChannelPostButtonTransport([$f,'request']);
$result=$transport->verifyChannel(($runtimeTg??[])+['channel_id'=>'-1001234567890']);
cpwCheck('runtime publication identity accepts the owner-approved Telegram bot without writes',
    $result['state']==='ready'&&$result['reason']==='capability_verified'
    &&array_column($f->calls,'path')===['getMe','getChatMember','getChat']
    &&$result['receipt']['write_attempts']===0);
cpwCheck('runtime publication links open the approved Telegram bot',
    ChannelPostButtonTransport::buttonUrl(['start_payload'=>'p_abcdefghijklmnopqrstuvwx','channel'=>$runtimeTg??[]])==='https://t.me/anyru_bot?start=p_abcdefghijklmnopqrstuvwx');
$f=new ChannelPostWebhookFixture();$f->bot='Any_tour_bot';
$result=(new ChannelPostButtonTransport([$f,'request']))->verifyChannel(($runtimeTg??[])+['channel_id'=>'-1001234567890']);
cpwCheck('runtime publication identity rejects a different Telegram bot before channel access',
    $result['state']==='requires_attention'&&$result['reason']==='canonical_bot_identity_mismatch'
    &&array_column($f->calls,'path')===['getMe']&&$result['receipt']['write_attempts']===0);
$f=new ChannelPostWebhookFixture();$f->bot='anyru_bot';$f->url=WebhookTargetConfig::telegram();$f->types=['message','callback_query'];
$result=$f->service()->run('telegram',$runtimeTg??[]);
cpwCheck('runtime Telegram identity also permits the read-only channel-event plan',
    $result['state']==='planned'&&$result['receipt']['added_update_types']===['channel_post','edited_channel_post']
    &&$f->writes()===[]&&$f->claims===[]);

$fixture=new ChannelPostWebhookFixture();$dry=$fixture->service()->run('max',$max);
cpwCheck('default run verifies exact bot and plans without provider write',$dry['state']==='planned'&&$dry['receipt']['added_update_types']===['message_edited']&&$dry['receipt']['request_count']===2&&$fixture->writes()===[]&&$fixture->claims===[]);
cpwCheck('plan contains no secret, raw provider response or webhook body',!str_contains(json_encode($dry),'PRIVATE_PROVIDER_SECRET')&&!str_contains(json_encode($dry),'synthetic_max_secret')&&!str_contains(json_encode($dry),'app.anytoour.ru'));
$applied=$fixture->service()->run('max',$max,true);$writes=$fixture->writes();
cpwCheck('MAX updates one exact existing subscription using POST, never DELETE',$applied['state']==='ready'&&count($writes)===1&&$writes[0]['method']==='POST'&&$writes[0]['path']==='/subscriptions'&&!in_array('DELETE',array_column($fixture->calls,'method'),true));
cpwCheck('MAX preserves existing events, canonical URL and configured secret',$writes[0]['body']['url']===WebhookTargetConfig::max()&&$writes[0]['body']['secret']==='synthetic_max_secret'&&$writes[0]['body']['update_types']===['message_callback','bot_started','message_created','message_removed','message_edited']);
cpwCheck('MAX apply bounded to identity, reads, one write and readback',$applied['receipt']['request_count']===5&&$applied['receipt']['write_attempts']===1&&$applied['receipt']['update_types_preserved']&&$applied['receipt']['channel_update_types_verified']);
$again=$fixture->service()->run('max',$max,true);
cpwCheck('duplicate explicit apply reads existing result without another write',$again['state']==='ready'&&$again['reason']==='already_configured'&&count($fixture->writes())===1&&$again['receipt']['write_attempts']===0);
foreach(['missing'=>0,'duplicate'=>2] as $name=>$count){$f=new ChannelPostWebhookFixture();$f->subscriptions=$count;$result=$f->service()->run('max',$max,true);cpwCheck('MAX '.$name.' subscription fails closed without mutation',$result['state']==='blocked'&&$result['reason']==='exact_single_max_subscription_required'&&$f->writes()===[]);}
$f=new ChannelPostWebhookFixture();$f->url='https://other.invalid/webhook';$result=$f->service()->run('max',$max,true);
cpwCheck('different webhook URL is never migrated or overwritten',$result['reason']==='canonical_webhook_url_mismatch'&&$f->writes()===[]);
$f=new ChannelPostWebhookFixture();$f->bot='id9704048781_2_bot';$result=$f->service()->run('max',$max,true);
cpwCheck('MAX primary1 identity rejects miniapp2 credential before subscription access',$result['reason']==='canonical_bot_identity_mismatch'&&count($f->calls)===1&&$f->writes()===[]);
$f=new ChannelPostWebhookFixture();$result=$f->service()->run('max',array_replace($max,['bot_url'=>'https://max.ru/wrong_bot']),true);
cpwCheck('noncanonical public bot URL rejected before API call',$result['reason']==='canonical_bot_url_mismatch'&&$f->calls===[]);
$f=new ChannelPostWebhookFixture();$result=$f->service([])->run('max',$max,true);
cpwCheck('unknown existing secret cannot be cleared by apply',$result['reason']==='configured_webhook_secret_required'&&$f->writes()===[]&&$f->claims===[]);
$f=new ChannelPostWebhookFixture();$f->types=[];$result=$f->service()->run('max',$max,true);
cpwCheck('undocumented empty MAX update list cannot be narrowed',$result['state']==='blocked'&&$f->writes()===[]);
$f=new ChannelPostWebhookFixture();$f->mode='concurrent_types';$result=$f->service()->run('max',$max,true);
cpwCheck('concurrent event configuration change rejected before write',$result['reason']==='webhook_configuration_changed'&&$f->writes()===[]);
$f=new ChannelPostWebhookFixture();$f->mode='timeout_observed';$result=$f->service()->run('max',$max,true);
cpwCheck('lost write response can be confirmed by readback without retry',$result['state']==='ready'&&$result['reason']==='verified_after_unknown_response'&&count($f->writes())===1);
$f=new ChannelPostWebhookFixture();$f->mode='timeout_unobserved';$result=$f->service()->run('max',$max,true);$second=$f->service()->run('max',$max,true);
cpwCheck('unobserved timeout remains unknown and durable claim blocks second write',$result['state']==='unknown'&&$second['state']==='unknown'&&$second['reason']==='previous_apply_requires_readback'&&count($f->writes())===1);
$readonly=$f->service()->run('max',$max,false);
cpwCheck('unknown result supports read-only reconciliation without write',$readonly['state']==='planned'&&count($f->writes())===1&&$readonly['receipt']['write_attempts']===0);
$f=new ChannelPostWebhookFixture();$f->mode='post_write_read_failure';$result=$f->service()->run('max',$max,true);
cpwCheck('unavailable post-write readback cannot report success',$result['state']==='unknown'&&count($f->writes())===1&&!str_contains(json_encode($result),'PRIVATE_PROVIDER_SECRET'));

$f=new ChannelPostWebhookFixture();$f->platform='telegram';$f->bot='Any_tour_bot';$f->url=WebhookTargetConfig::telegram();$f->types=['message','callback_query','chat_member'];
$result=$f->service()->run('telegram',$tg,true);$body=$f->writes()[0]['body'];
cpwCheck('TG addition preserves prior allowed_updates including specialized events',$result['state']==='ready'&&$body['allowed_updates']===['message','callback_query','chat_member','channel_post','edited_channel_post']);
cpwCheck('TG URL, max_connections and configured secret preserved, pending queue not dropped',$body['url']===WebhookTargetConfig::telegram()&&$body['max_connections']===23&&$body['secret_token']==='synthetic_tg_secret'&&$body['drop_pending_updates']===false&&!isset($body['ip_address']));
cpwCheck('TG pending queue counts recorded before and after, no deleteWebhook',$result['receipt']['pending_update_count_before']===12&&$result['receipt']['pending_update_count_after']===12&&!in_array('deleteWebhook',array_column($f->calls,'path'),true));
cpwCheck('TG apply safe receipts hide raw API descriptions and secrets',!str_contains(json_encode($result),'PRIVATE_PROVIDER_SECRET')&&!str_contains(json_encode($result),'synthetic_tg_secret'));
$f=new ChannelPostWebhookFixture();$f->bot='Any_tour_bot';$f->url=WebhookTargetConfig::telegram();$f->types=[];$result=$f->service()->run('telegram',$tg,true);
cpwCheck('empty Telegram allowed_updates is already broad and never restricted',$result['state']==='ready'&&$result['reason']==='already_configured'&&$result['receipt']['receives_all_default_updates']&&$f->writes()===[]);
$f=new ChannelPostWebhookFixture();$f->bot='Any_tour_bot';$f->url=WebhookTargetConfig::telegram();$f->types=['message','callback_query'];$f->certificate=true;$result=$f->service()->run('telegram',$tg,true);
cpwCheck('TG custom certificate is not silently replaced',$result['reason']==='custom_certificate_preservation_requires_review'&&$f->writes()===[]);
$f=new ChannelPostWebhookFixture();$f->bot='Any_tour_bot';$f->url=WebhookTargetConfig::telegram();$f->types=['message','callback_query'];$f->maxConnections=0;$result=$f->service()->run('telegram',$tg,true);
cpwCheck('unknown delivery configuration prevents TG mutation',$result['reason']==='existing_delivery_configuration_unavailable'&&$f->writes()===[]);

$dir=sys_get_temp_dir().'/channel-webhook-claim-fixture-'.bin2hex(random_bytes(6));$prior=getenv('CHANNEL_POST_WEBHOOK_STATE_DIR');putenv('CHANNEL_POST_WEBHOOK_STATE_DIR='.$dir);
try{
    $key=hash('sha256','synthetic-only');$summary=['platform'=>'max','update_types'=>['message_edited'],'secret'=>'NEVER_STORE'];
    cpwCheck('private write marker claims once and blocks subsequent processes',ChannelPostWebhookConfigService::persistWriteClaim($key,$summary)&&!ChannelPostWebhookConfigService::persistWriteClaim($key,$summary));
    $file=$dir.'/'.$key.'.json';$stored=(string)file_get_contents($file);
    cpwCheck('private marker contains no secrets and uses restrictive modes',!str_contains($stored,'NEVER_STORE')&&(fileperms($file)&0777)===0600&&(fileperms($dir)&0777)===0700);
    putenv('CHANNEL_POST_WEBHOOK_STATE_DIR='.dirname(__DIR__));
    cpwCheck('write markers refuse the public repository directory',!ChannelPostWebhookConfigService::persistWriteClaim(hash('sha256','rejected-root'),$summary));
}finally{if(is_file($dir.'/'.$key.'.json'))unlink($dir.'/'.$key.'.json');if(is_dir($dir))rmdir($dir);putenv($prior===false?'CHANNEL_POST_WEBHOOK_STATE_DIR':'CHANNEL_POST_WEBHOOK_STATE_DIR='.$prior);}
$source=(string)file_get_contents(dirname(__DIR__).'/services/ChannelPostWebhookConfigService.php');$cli=(string)file_get_contents(dirname(__DIR__).'/tools/channel_post_webhook_updates.php');
$transport=(string)file_get_contents(dirname(__DIR__).'/integrations/ChannelPostApiTransport.php');
cpwCheck('network transport requires strict MAX TLS, fixed hosts and no redirects',str_contains($transport,'MaxTlsConfig::strictCurlOptions()')&&preg_match('/CURLOPT_FOLLOWLOCATION\s*=>\s*false/',$transport)===1&&preg_match('/CURLOPT_SSL_VERIFYPEER\s*=>\s*true/',$transport)===1&&preg_match('/CURLOPT_SSL_VERIFYPEER\s*=>\s*false/',$transport)===0&&str_contains($source,'ChannelPostApiTransport::webhookRequest(')&&!str_contains($source,'platform-api2.max.ru'));
cpwCheck('CLI defaults to dry mode, guards CLI access and obtains canonical runtime identity',str_contains($cli,"PHP_SAPI!=='cli'")&&str_contains($cli,'$apply=false')&&str_contains($cli,'ChannelPostRuntime::identity($platform)')&&str_contains($cli,'--reconcile'));
echo "TOTAL {$passed} | PASS {$passed}\n";
