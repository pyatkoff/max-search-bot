<?php
declare(strict_types=1);

/** Actual endpoint in an isolated synthetic document root; signing key stays in memory. */
function search3HttpScenarios(PDO $seed,string $secret,array $keys): void
{
    $dir=sys_get_temp_dir().'/search3-http-'.bin2hex(random_bytes(8));mkdir($dir,0700);mkdir($dir.'/search3',0700);$process=null;
    try {
        $root=dirname(__DIR__,2);$seed->exec('VACUUM INTO '.$seed->quote($dir.'/fixture.sqlite'));
        symlink($root.'/services',$dir.'/services');copy($root.'/search3/tour-handoff.php',$dir.'/search3/tour-handoff.php');
        file_put_contents($dir.'/config.php','<?php require_once '.var_export($root.'/tests/support/search3-tour-handoff.php',true).';'.
            '$fixturePdo=new PDO('.var_export('sqlite:'.$dir.'/fixture.sqlite',true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);'.
            '(new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$fixturePdo);'.
            'ProjectConfig::resetForTests('.var_export(search3FixtureConfig(),true).');'.
            '(new ReflectionProperty(Search3TourHandoffTrust::class,"keys"))->setValue(null,is_file('.var_export($dir.'/disabled',true).')?[]:'.var_export($keys,true).');');
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket)throw new RuntimeException('Search3 loopback unavailable');$address=stream_socket_get_name($socket,false);fclose($socket);
        $process=proc_open([PHP_BINARY,'-S',$address,'-t',$dir],[0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot start Search3 HTTP fixture');fclose($pipes[0]);
        $ready=false;for($i=0;$i<200;$i++){$probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05);if($probe){fclose($probe);$ready=true;break;}usleep(10000);}if(!$ready)throw new RuntimeException('Search3 HTTP fixture not ready');
        $request=static function(string $method,string $raw='',array $headers=[])use($address):array {
            $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$raw,'ignore_errors'=>true,'timeout'=>5]]);
            $body=file_get_contents('http://'.$address.'/search3/tour-handoff.php',false,$context);$received=$http_response_header??[];preg_match('/\s(\d{3})\s/',$received[0]??'',$m);
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
        deliveryCheck('HTTP response exposes no cookies CORS private terms or caching',str_contains($created['headers'],'no-store')&&str_contains($created['headers'],'X-Robots-Tag: noindex')&&str_contains($created['headers'],'Referrer-Policy: no-referrer')&&!preg_match('/Set-Cookie|Access-Control-Allow-Origin/i',$created['headers'])&&!preg_match('/Family sea view|childAges|record_id|public_key/',$created['raw']));
        $changed=$intent;$changed['observation']['price']['amount']='999.00';[$changedRaw,$changedAuth]=search3FixtureSigned($changed,$secret);deliveryCheck('HTTP conflict never overwrites original selected conditions',$request('POST',$changedRaw,$headers($changedAuth))['status']===409);
        $command=['action'=>'status','operation_id'=>$intent['operation_id'],'request_hash'=>hash('sha256',$raw)];[$statusRaw,$statusAuth]=search3FixtureSigned($command,$secret);deliveryCheck('HTTP signed status returns exact original capabilities',$request('POST',$statusRaw,$headers($statusAuth))['body']['links']===$created['body']['links']);
        $command['action']='revoke';[$revokeRaw,$revokeAuth]=search3FixtureSigned($command,$secret);$revoked=$request('POST',$revokeRaw,$headers($revokeAuth));deliveryCheck('HTTP revoke is durable and create retry cannot revive links',$revoked['body']['state']==='revoked'&&!isset($revoked['body']['links'])&&$request('POST',$raw,$signed)['body']['state']==='revoked');
        file_put_contents($dir.'/disabled','disabled');$disabled=$request('POST',$raw,$signed);deliveryCheck('unconfigured producer is explicit503 without capabilities',$disabled['status']===503&&$disabled['body']['error']==='producer_not_configured'&&!isset($disabled['body']['links']));unlink($dir.'/disabled');
        $broken=new PDO('sqlite:'.$dir.'/fixture.sqlite');$broken->exec('DROP TABLE search3_tour_handoffs');$outage=$request('POST',$raw,$signed);deliveryCheck('storage outage cannot leak SQL or falsely acknowledge creation',$outage['status']===503&&$outage['body']===['ok'=>false,'error'=>'handoff_unavailable']);
    } finally {
        if(is_resource($process)){proc_terminate($process);proc_close($process);}
        $clear=static function(string $path)use(&$clear):void{if(is_file($path)||is_link($path)){unlink($path);return;}foreach(scandir($path)?:[]as$part)if($part!=='.'&&$part!=='..')$clear($path.'/'.$part);rmdir($path);};$clear($dir);
    }
}
