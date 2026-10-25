<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/AdvertisingVisitTransport.php';
require_once __DIR__.'/run_advertising_visits_regression.php';
require_once dirname(__DIR__).'/modules/tgmini-identity/tgmini_identity_capture.php';

$passed=0;
function transportCheck(string $label,bool $ok): void {global $passed;if(!$ok)throw new RuntimeException('FAIL '.$label);$passed++;echo 'PASS '.$label.PHP_EOL;}
function transportReject(callable $call,string $label): void {try{$call();}catch(Throwable $e){transportCheck($label,true);return;}transportCheck($label,false);}
function transportCommand(array $args): array {
    $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('fixture_process');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return[proc_close($p),$out,$err];
}
function transportHttp(string $url,?array $request=null): array {
    $options=['method'=>$request?'POST':'GET','ignore_errors'=>true,'follow_location'=>0,'timeout'=>3];
    if($request){$options['header']="Content-Type: application/json\r\nX-AnyTour-Signature: ".$request['signature']."\r\n";$options['content']=$request['body'];}
    $body=@file_get_contents($url,false,stream_context_create(['http'=>$options]));
    $headers=$http_response_header??[];$code=isset($headers[0])&&preg_match('/\s([0-9]{3})\s/',$headers[0],$m)?(int)$m[1]:0;
    return[$code,$body===false?'':$body];
}

$root=dirname(__DIR__);$base=sys_get_temp_dir().'/advertising-transport-'.bin2hex(random_bytes(8));
mkdir($base,0700);mkdir($base.'/web',0700);$server=null;
try{
    $t=new AdvertisingVisitTransport($base.'/receiver');
    transportCheck('unconfigured worker is disabled without creating files',!$t->initialized()&&!is_dir($base.'/receiver'));
    $pub=$t->initialize();$again=$t->initialize();
    transportCheck('key provisioning is idempotent and public-only',$pub===$again&&array_keys($pub)===['request_public_key','response_public_key','public_fingerprint']);
    $secret=json_decode(file_get_contents($base.'/receiver/receiver.json'),true);
    transportCheck('separate independent signing and response-encryption secrets',$secret['sign_seed']!==$secret['box_secret']);
    transportCheck('receiver directory and private key modes',(fileperms($base.'/receiver')&0777)===0700&&(fileperms($base.'/receiver/receiver.json')&0777)===0600);
    $keys=['sign'=>sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin($secret['sign_seed']))),
        'box'=>sodium_crypto_box_keypair_from_secretkey_and_publickey(hex2bin($secret['box_secret']),hex2bin($pub['response_public_key']))];
    $r=transportCommand(['python3',$root.'/tools/build_tgmini_feed.py','--request-public-key='.$pub['request_public_key'],'--response-public-key='.$pub['response_public_key'],'--output='.$base.'/web/tgmini']);
    transportCheck('source package builds without network or private key inputs',$r[0]===0&&$r[2]==='');
    $feed=file_get_contents($base.'/web/tgmini/tgmini_identity_feed.php');
    transportCheck('source package does not contain either receiver secret',!str_contains($feed,$secret['sign_seed'])&&!str_contains($feed,$secret['box_secret']));
    $r=transportCommand([PHP_BINARY,'-l',$base.'/web/tgmini/tgmini_identity_feed.php']);transportCheck('generated source endpoint PHP syntax',$r[0]===0);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,'-t',$base.'/web'],[0=>['pipe','r'],1=>['file',$base.'/server.log','ab'],2=>['file',$base.'/server.log','ab']],$pipes);fclose($pipes[0]);
    $url='http://'.$address.'/tgmini/tgmini_identity_feed.php';
    for($i=0;$i<30;$i++){[$code,$body]=transportHttp($url);if($code>0)break;usleep(50000);}
    transportCheck('anonymous GET denied before source storage access',$code===404&&$body===''&&!is_dir($base.'/.tgmini-identity-anytour'));
    $now=time();$checkpoint=['cursor'=>'0','fingerprint'=>null];
    $req=AdvertisingVisitFeedProtocol::request(AdvertisingVisitTransport::SCOPE,$checkpoint,$keys['sign'],$now);
    [$code,$body]=transportHttp($url,['body'=>$req['body'],'signature'=>str_repeat('0',128)]);
    transportCheck('wrong request signature cannot read or create the journal',$code===404&&$body===''&&!is_dir($base.'/.tgmini-identity-anytour'));
    $wrong=$req;$wrong['body'].=' ';[$code,$body]=transportHttp($url,$wrong);transportCheck('body tampering rejected',$code===404&&$body==='');
    foreach([$now-121,$now+61]as$time){$bad=AdvertisingVisitFeedProtocol::request(AdvertisingVisitTransport::SCOPE,$checkpoint,$keys['sign'],$time);[$code,$body]=transportHttp($url,$bad);transportCheck('expired or future signed request rejected',$code===404&&$body==='');}
    $foreign=AdvertisingVisitTransport::SCOPE;$foreign['counter_id']='123';$bad=AdvertisingVisitFeedProtocol::request($foreign,$checkpoint,$keys['sign'],time());[$code,$body]=transportHttp($url,$bad);transportCheck('signed foreign counter cannot select another feed',$code===404&&$body==='');
    $oldRoot=$_SERVER['DOCUMENT_ROOT']??null;$_SERVER['DOCUMENT_ROOT']=$base.'/web';
    $capture=new TgminiIdentityCapture();
    for($i=0;$i<101;$i++){$r=$capture->capture(['clientId'=>'17929400001234567890','yclid'=>'18446744073709551614','campaign_id'=>'12','region'=>'213'],'98615635',['external_user_id'=>'18446744073709551615','identity_status'=>'verified']);if($r['status']!=='saved')throw new RuntimeException('fixture_capture');}
    transportCheck('real capture writer and unchanged private path produce source history',is_file($base.'/.tgmini-identity-anytour/entries-v1.jsonl')&&$capture->health()['last_capture']['identity_status']==='verified');
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);advertisingVisitFixtureSchema($pdo);
    $calls=0;$fetch=static function(array $r)use($url,&$calls):string{$calls++;[$code,$wire]=transportHttp($url,$r);if($code!==200)throw new RuntimeException('fixture_source_http');return $wire;};
    $off=$t->pull($pdo,$fetch);transportCheck('disabled transport makes no HTTP call and no DB write',$off['status']==='off'&&$calls===0&&(int)$pdo->query('SELECT COUNT(*) FROM advertising_visit_observations')->fetchColumn()===0);
    $req=AdvertisingVisitFeedProtocol::request(AdvertisingVisitTransport::SCOPE,$checkpoint,$keys['sign'],time());$wire=$fetch($req);
    transportCheck('HTTP reply is ciphertext without source identity or ClientID',!str_contains($wire,'18446744073709551615')&&!str_contains($wire,'17929400001234567890')&&!str_contains($wire,'observations'));
    $read=AdvertisingVisitFeedProtocol::open($wire,$req['body'],$keys['box'],AdvertisingVisitTransport::SCOPE,$checkpoint,time());
    transportCheck('authenticated source read is bounded to 100 and preserves large decimal IDs',count($read['page']['observations'])===100&&$read['has_more']&&$read['page']['observations'][0]['external_user_id']==='18446744073709551615');
    $other=AdvertisingVisitFeedProtocol::request(AdvertisingVisitTransport::SCOPE,$checkpoint,$keys['sign'],time());
    transportReject(static fn()=>AdvertisingVisitFeedProtocol::open($wire,$other['body'],$keys['box'],AdvertisingVisitTransport::SCOPE,$checkpoint,time()),'old response cannot satisfy a different request nonce');
    $broken=$wire;$broken[10]=$broken[10]==='A'?'B':'A';
    transportReject(static fn()=>AdvertisingVisitFeedProtocol::open($broken,$req['body'],$keys['box'],AdvertisingVisitTransport::SCOPE,$checkpoint,time()),'ciphertext tampering rejected');
    $otherPair=sodium_crypto_box_keypair();transportReject(static fn()=>AdvertisingVisitFeedProtocol::open($wire,$req['body'],$otherPair,AdvertisingVisitTransport::SCOPE,$checkpoint,time()),'another receiver cannot decrypt source data');
    $result=$t->enable($pdo,$fetch);transportCheck('enable commits first page before activating recurring delivery',$result['inserted']===100&&$result['enabled']&&$t->enabled());
    $result=$t->pull($pdo,$fetch);transportCheck('second page resumes at anchored committed checkpoint',$result['inserted']===1&&!$result['has_more']);
    $result=$t->pull($pdo,$fetch);transportCheck('completed-feed retry adds no duplicate observations',$result['inserted']===0&&$result['observations']===0&&(int)$pdo->query('SELECT COUNT(*) FROM advertising_visit_observations')->fetchColumn()===101);
    $repo=new AdvertisingVisitRepository($pdo);$before=$repo->checkpoint(AdvertisingVisitTransport::SCOPE);
    transportReject(static fn()=>$t->pull($pdo,static function(){throw new RuntimeException('fixture_network_loss');}),'network loss propagates without acknowledging a page');
    transportCheck('network loss preserves database cursor',$before===$repo->checkpoint(AdvertisingVisitTransport::SCOPE));
    $capture->capture(['clientId'=>'17929400001234567890','yclid'=>''],'98615635',['external_user_id'=>'18446744073709551615','identity_status'=>'verified']);
    transportReject(static fn()=>$t->pull($pdo,static fn()=>'invalid-body'),'invalid reply cannot acknowledge a newly appended source row');
    transportCheck('invalid reply leaves checkpoint unchanged',$before===$repo->checkpoint(AdvertisingVisitTransport::SCOPE));
    $result=$t->pull($pdo,$fetch);transportCheck('a fresh request recovers the unacknowledged append',$result['inserted']===1&&(int)$pdo->query('SELECT COUNT(*) FROM advertising_visit_observations')->fetchColumn()===102);
    transportCheck('status and worker results contain aggregates only',!str_contains(json_encode($t->status()),'18446744073709551615')&&!str_contains(json_encode($result),'17929400001234567890')&&$result['conversion_uploads']===0);
    $journal=$base.'/.tgmini-identity-anytour/entries-v1.jsonl';$saved=file_get_contents($journal);file_put_contents($journal,substr($saved,0,-20));
    $before=$repo->checkpoint(AdvertisingVisitTransport::SCOPE);transportReject(static fn()=>$t->pull($pdo,$fetch),'truncated source anchor fails closed');transportCheck('truncated anchor preserves committed data',$before===$repo->checkpoint(AdvertisingVisitTransport::SCOPE));file_put_contents($journal,$saved);
    $t->disable();$callsBefore=$calls;$result=$t->pull($pdo,$fetch);transportCheck('disable preserves keys and avoids further source calls',$result['status']==='off'&&$calls===$callsBefore&&$t->publicKeys()===$pub);
    chmod($base.'/receiver/receiver.json',0644);clearstatcache();transportReject(static fn()=>$t->publicKeys(),'world-readable receiver private key is refused');chmod($base.'/receiver/receiver.json',0600);clearstatcache();
    transportReject(static fn()=>new AdvertisingVisitTransport($root.'/public-bad-key-dir'),'private transport storage cannot be in document root');
    symlink($base.'/receiver',$base.'/receiver-link');transportReject(static fn()=>new AdvertisingVisitTransport($base.'/receiver-link'),'symlink private directory refused');
    $cron=$base.'/cron';mkdir($cron,0700);symlink($root.'/services',$cron.'/services');copy($root.'/cron_metrika.php',$cron.'/cron_metrika.php');file_put_contents($cron.'/config.php',"<?php define('METRIKA_COUNTER_ID','1');define('METRIKA_OAUTH_TOKEN','fixture-only');\n");
    $r=transportCommand([PHP_BINARY,$cron.'/cron_metrika.php']);transportCheck('empty legacy queue result stays unchanged with disabled transport',$r[0]===0&&$r[1]==="OK queue empty\n"&&$r[2]==='');
    $cronSource=file_get_contents($root.'/cron_metrika.php');$transportSource=file_get_contents($root.'/services/AdvertisingVisitTransport.php');
    transportCheck('cron reuses shutdown boundary; HTTP TLS and redirect restrictions remain strict',str_contains($cronSource,'register_shutdown_function')&&str_contains($transportSource,'CURLOPT_SSL_VERIFYPEER=>true')&&str_contains($transportSource,'CURLOPT_SSL_VERIFYHOST=>2')&&str_contains($transportSource,'CURLOPT_FOLLOWLOCATION=>false')&&str_contains($transportSource,'CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS'));
    if($oldRoot===null)unset($_SERVER['DOCUMENT_ROOT']);else $_SERVER['DOCUMENT_ROOT']=$oldRoot;
    echo 'ADVERTISING VISIT TRANSPORT REGRESSION PASSED '.$passed.PHP_EOL;
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    $iter=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iter as$item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($base);
}
