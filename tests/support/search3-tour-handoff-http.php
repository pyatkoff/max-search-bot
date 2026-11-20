<?php
declare(strict_types=1);

/** Actual endpoint in an isolated synthetic document root; signing key stays in memory. */
function search3HttpScenarios(PDO $seed,string $secret,array $keys): void
{
    $dir=sys_get_temp_dir().'/search3-http-'.bin2hex(random_bytes(8));mkdir($dir,0700);mkdir($dir.'/search3',0700);$process=null;
    try {
        $root=dirname(__DIR__,2);$seed->exec('VACUUM INTO '.$seed->quote($dir.'/fixture.sqlite'));
        $db=new PDO('sqlite:'.$dir.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $db->exec("ALTER TABLE conversation_sources ADD COLUMN project_id INTEGER;
ALTER TABLE conversation_sources ADD COLUMN source_key TEXT;
ALTER TABLE conversation_sources ADD COLUMN channel TEXT;
ALTER TABLE conversation_sources ADD COLUMN handling_mode TEXT DEFAULT 'ai';
ALTER TABLE customer_channels ADD COLUMN username TEXT;
ALTER TABLE customer_channels ADD COLUMN metadata_json TEXT;
ALTER TABLE conversations ADD COLUMN metadata_json TEXT;
INSERT INTO conversation_sources(display_name,fallback_mode,is_active,project_id,source_key,channel)
VALUES('Synthetic MAX','none',1,1,'max:synthetic-main','max');
UPDATE managers SET is_working=0");
        symlink($root.'/services',$dir.'/services');copy($root.'/search3/tour-handoff.php',$dir.'/search3/tour-handoff.php');
        file_put_contents($dir.'/config.php','<?php require_once '.var_export($root.'/tests/support/search3-tour-handoff.php',true).';'.
            '$fixturePdo=new PDO('.var_export('sqlite:'.$dir.'/fixture.sqlite',true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);'.
            '(new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$fixturePdo);'.
            'ProjectConfig::resetForTests('.var_export(search3FixtureConfig(),true).');'.
            '(new ReflectionProperty(Search3TourHandoffTrust::class,"keys"))->setValue(null,is_file('.var_export($dir.'/disabled',true).')?[]:'.var_export($keys,true).');');
        file_put_contents($dir.'/max-fixture.php','<?php
require __DIR__."/config.php";
require_once '.var_export($root.'/handlers/MaxUpdateHandler.php',true).';
define("MAX_SEARCH_WEBHOOK_SECRET","search3-local-fixture");
$fixturePdo->sqliteCreateFunction("NOW",static fn():string=>gmdate("Y-m-d H:i:s"));
foreach ([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) {
    (new ReflectionProperty($class,"schemaReady"))->setValue(null,true);
}
DiagnosticLogger::setFile(__DIR__."/fixture-events.log");
IntegrationRegistry::useMessenger(new TelegramMessengerAdapter(static fn():bool=>true,"ai",false));
MaxUpdateHandler::handle();');
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket)throw new RuntimeException('Search3 loopback unavailable');$address=stream_socket_get_name($socket,false);fclose($socket);
        $process=proc_open([PHP_BINARY,'-d','sys_temp_dir='.$dir,'-d','allow_url_fopen=0',
            '-d','disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client',
            '-S',$address,'-t',$dir],[0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot start Search3 HTTP fixture');fclose($pipes[0]);
        $ready=false;for($i=0;$i<200;$i++){$probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05);if($probe){fclose($probe);$ready=true;break;}usleep(10000);}if(!$ready)throw new RuntimeException('Search3 HTTP fixture not ready');
        $request=static function(string $method,string $raw='',array $headers=[],string $path='/search3/tour-handoff.php')use($address):array {
            $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$raw,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>5]]);
            $body=file_get_contents('http://'.$address.$path,false,$context);$received=$http_response_header??[];preg_match('/\s(\d{3})\s/',$received[0]??'',$m);
            if($body===false)throw new RuntimeException('Search3 loopback HTTP failed');
            return ['status'=>(int)($m[1]??0),'body'=>json_decode($body,true),'raw'=>$body,'headers'=>implode("\n",$received)];
        };
        $headers=static fn(array $auth):array=>['Content-Type: application/json','X-Anytour-Search3-Key: '.$auth['HTTP_X_ANYTOUR_SEARCH3_KEY'],'X-Anytour-Search3-Timestamp: '.$auth['HTTP_X_ANYTOUR_SEARCH3_TIMESTAMP'],'X-Anytour-Search3-Signature: '.$auth['HTTP_X_ANYTOUR_SEARCH3_SIGNATURE']];
        $intent=search3FixtureIntent();[$raw,$auth]=search3FixtureSigned($intent,$secret);$signed=$headers($auth);
        deliveryCheck('actual HTTP rejects GET and browser preflight',$request('GET')['status']===405&&$request('OPTIONS')['status']===405);
        deliveryCheck('even signed browser Origin and cookies are denied',$request('POST',$raw,[...$signed,'Origin: https://app.anytoour.ru'])['status']===403&&$request('POST',$raw,[...$signed,'Cookie: PHPSESSID=synthetic'])['status']===403);
        deliveryCheck('HTTP rejects wrong type unsigned and modified body',$request('POST',$raw,['Content-Type: text/plain'])['status']===415&&$request('POST',$raw,['Content-Type: application/json'])['status']===401&&$request('POST',$raw.' ',$signed)['status']===401);
        deliveryCheck('HTTP body bound runs before JSON decoding',$request('POST',str_repeat('x',32769),$signed)['status']===413);
        $created=$request('POST',$raw,$signed);$repeat=$request('POST',$raw,$signed);
        deliveryCheck('actual HTTP exact signed intent recovers original lost-response links',$created['status']===200&&$created['body']['state']==='ready'&&$repeat['body']['duplicate']&&$created['body']['links']===$repeat['body']['links']);
        search3MaxWebhookScenarios($db,$request,$created['body'],$intent['operation_id']);
        deliveryCheck('HTTP response exposes no cookies CORS private terms or caching',str_contains($created['headers'],'no-store')&&str_contains($created['headers'],'X-Robots-Tag: noindex')&&str_contains($created['headers'],'Referrer-Policy: no-referrer')&&!preg_match('/Set-Cookie|Access-Control-Allow-Origin/i',$created['headers'])&&!preg_match('/Family sea view|childAges|record_id|public_key/',$created['raw']));
        $changed=$intent;$changed['observation']['price']['amount']='999.00';[$changedRaw,$changedAuth]=search3FixtureSigned($changed,$secret);deliveryCheck('HTTP conflict never overwrites original selected conditions',$request('POST',$changedRaw,$headers($changedAuth))['status']===409);
        $command=['action'=>'status','operation_id'=>$intent['operation_id'],'request_hash'=>hash('sha256',$raw)];[$statusRaw,$statusAuth]=search3FixtureSigned($command,$secret);deliveryCheck('HTTP signed status returns exact original capabilities',$request('POST',$statusRaw,$headers($statusAuth))['body']['links']===$created['body']['links']);
        $command['action']='revoke';[$revokeRaw,$revokeAuth]=search3FixtureSigned($command,$secret);$revoked=$request('POST',$revokeRaw,$headers($revokeAuth));deliveryCheck('HTTP revoke is durable and create retry cannot revive links',$revoked['body']['state']==='revoked'&&!isset($revoked['body']['links'])&&$request('POST',$raw,$signed)['body']['state']==='revoked');
        file_put_contents($dir.'/disabled','disabled');$disabled=$request('POST',$raw,$signed);deliveryCheck('unconfigured producer is explicit503 without capabilities',$disabled['status']===503&&$disabled['body']['error']==='producer_not_configured'&&!isset($disabled['body']['links']));unlink($dir.'/disabled');
        $db->exec('DROP TABLE search3_tour_handoffs');$outage=$request('POST',$raw,$signed);deliveryCheck('storage outage cannot leak SQL or falsely acknowledge creation',$outage['status']===503&&$outage['body']===['ok'=>false,'error'=>'handoff_unavailable']);
    } finally {
        if(is_resource($process)){proc_terminate($process);proc_close($process);}
        $clear=static function(string $path)use(&$clear):void{if(is_file($path)||is_link($path)){unlink($path);return;}foreach(scandir($path)?:[]as$part)if($part!=='.'&&$part!=='..')$clear($path.'/'.$part);rmdir($path);};$clear($dir);
    }
}

/** Exercise the installed handler code using fictional provider requests on loopback. */
function search3MaxWebhookScenarios(PDO $db,callable $request,array $created,string $operationId): void
{
    $visitor=981;
    $start=search3FixtureNative('max',search3FixturePayload($created['links']['max']),$visitor)['raw'];
    $post=static function(array $update,?string $secret='search3-local-fixture')use($request):array {
        $headers=['Content-Type: application/json'];
        if($secret!==null)$headers[]='X-Max-Bot-Api-Secret: '.$secret;
        return $request('POST',json_encode($update,JSON_THROW_ON_ERROR),$headers,'/max-fixture.php');
    };
    $missingSecret=$post($start,null);
    $wrongSecret=$post($start,'foreign');
    $conversations=$db->prepare("SELECT id,status FROM conversations WHERE channel='max' AND external_chat_id=?");
    $conversations->execute(['-'.$visitor]);
    deliveryCheck('MAX webhook rejects missing or wrong secret before creating a dialogue',
        $missingSecret['status']===403 && $wrongSecret['status']===403 && !$conversations->fetch());
    $response=$post($start);
    $repeat=$post($start);
    $conversations->execute(['-'.$visitor]);
    $conversation=$conversations->fetch();
    deliveryCheck('MAX webhook accepts start and retry with one local manager queue entry',
        $response['status']===200 && $response['raw']==='ok' && $repeat['status']===200
        && ($conversation['status']??null)==='waiting_manager' && !$conversations->fetch());
    $events=$db->prepare('SELECT event_type FROM conversation_events WHERE conversation_id=? ORDER BY id');
    $events->execute([$conversation['id']]);
    deliveryCheck('MAX replay retains one search inquiry and one waiting event',
        array_count_values($events->fetchAll(PDO::FETCH_COLUMN))===['bot_started'=>1,'tour_search_inquiry'=>1,'waiting_manager'=>1]);
    $messages=$db->prepare('SELECT id,direction,external_message_id,metadata_json,text FROM messages WHERE conversation_id=? ORDER BY id');
    $messages->execute([$conversation['id']]);
    $arrivalRows=$messages->fetchAll();
    $arrival=$arrivalRows[0]??[];
    $meta=json_decode($arrival['metadata_json']??'',true);
    $operation=$db->prepare('SELECT operation_id FROM search3_tour_handoffs WHERE handoff_id=?');
    $operation->execute([$meta['handoff_id']??'']);
    $savedOperation=$operation->fetchColumn();
    $operation->closeCursor();
    deliveryCheck('MAX start stores the exact signed Search3 operation without a customer message ID',
        count($arrivalRows)===1 && $arrival['external_message_id']===null
        && ($meta['type']??null)==='tour_search_inquiry' && $savedOperation===$operationId
        && ConversationRecordClock::read($arrival['metadata_json'])!==null);

    $text='Подберите тур в Турцию на 7 ночей для двух взрослых';
    $update=['update_type'=>'message_created','message'=>['sender'=>['user_id'=>$visitor],
        'body'=>['mid'=>'mid.search3-webhook-fixture','text'=>$text]]];
    $first=$post($update);
    $duplicate=$post($update);
    $messages->execute([$conversation['id']]);
    $rows=$messages->fetchAll();
    $customer=$rows[1]??[];
    $clock=ConversationRecordClock::read($customer['metadata_json']??'');
    deliveryCheck('MAX webhook stores the later native text once, with its own MID and recording clock',
        $first['status']===200 && $first['raw']==='ok' && $duplicate['status']===200
        && count($rows)===2 && $customer['direction']==='inbound' && $customer['text']===$text
        && $customer['external_message_id']===$update['message']['body']['mid']
        && (int)$customer['id']>(int)$arrival['id'] && $clock!==null
        && $clock>=ConversationRecordClock::read($arrival['metadata_json']));
}
