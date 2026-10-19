<?php
declare(strict_types=1);
// Isolated CLI evaluation: exactly 20 reserved client invocations, no retry or send path.
// Source and task metadata are public; all customer inputs/results travel encrypted.
function hotel20_fail(): never {throw new RuntimeException('hotel20_failed');}
function hotel20_write(string $path,string $data):void {
 $f=fopen($path,'x');if($f===false)hotel20_fail();try{if(!chmod($path,0600)||fwrite($f,$data)!==strlen($data)||!fflush($f))hotel20_fail();if(function_exists('fsync')&&!fsync($f))hotel20_fail();}finally{fclose($f);}
}
function hotel20_json(mixed $v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function hotel20_main(array $t):void {
 if(PHP_SAPI!=='cli'||!extension_loaded('sodium'))hotel20_fail();
 if(($t['schema']??'')!=='anytour_hotel_model20_task_v1'||!in_array($t['phase']??'', ['prepare','evaluate','collect','cleanup'],true)||($t['case_count']??null)!==20||($t['max_output_tokens']??null)!==2200||($t['model']??'')!=='gpt-6.1-sol'||($t['reasoning_effort']??'')!=='low'||($t['service_tier']??'')!=='default'||($t['retries']??null)!==0)hotel20_fail();
 foreach(['token'=>48,'production_sha'=>40,'client_sha256'=>64,'input_sha256'=>64] as $k=>$n){if(!is_string($t[$k]??null)||!preg_match('/\A[0-9a-f]{'.$n.'}\z/D',$t[$k]))hotel20_fail();}
 $pub=base64_decode($t['output_public_base64']??'',true);if($pub===false||strlen($pub)!==32)hotel20_fail();
 $root='/var/www/anytoour/data/www/app.anytoour.ru';$dir='/tmp/anytour-hotel-model20-'.$t['token'];$phase=$t['phase'];umask(0077);
 if($phase==='prepare'||$phase==='evaluate'){
  $head=trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));if(!hash_equals($t['production_sha'],$head))hotel20_fail();
  if(!is_file($root.'/ai/ManagerDraftClient.php')||!hash_equals($t['client_sha256'],hash_file('sha256',$root.'/ai/ManagerDraftClient.php')))hotel20_fail();
 }
 if($phase==='prepare'){
  if(is_link($dir)||file_exists($dir)||!mkdir($dir,0700))hotel20_fail();
  if(!mkdir($dir.'/attempts',0700)||!mkdir($dir.'/results',0700))hotel20_fail();
  $kp=sodium_crypto_box_keypair();$state=['schema'=>'hotel20_lease_v1','task_token'=>$t['token'],'production_sha'=>$t['production_sha'],'client_sha256'=>$t['client_sha256'],'input_sha256'=>$t['input_sha256'],'output_public_sha256'=>hash('sha256',$pub),'created_at'=>time(),'expires_at'=>time()+2400,'keypair'=>base64_encode($kp),'case_count'=>20];
  hotel20_write($dir.'/lease.json',hotel20_json($state));
  echo hotel20_json(['schema'=>'hotel20_prepared_v1','token'=>$t['token'],'production_sha'=>$t['production_sha'],'client_sha256'=>$t['client_sha256'],'input_sha256'=>$t['input_sha256'],'input_public_base64'=>base64_encode(sodium_crypto_box_publickey($kp)),'output_public_sha256'=>hash('sha256',$pub),'case_count'=>20,'provider_calls'=>0]);return;
 }
 if(!is_dir($dir)||is_link($dir)||!is_file($dir.'/lease.json')||is_link($dir.'/lease.json'))hotel20_fail();
 $state=json_decode(file_get_contents($dir.'/lease.json'),true,16,JSON_THROW_ON_ERROR);
 foreach(['token'=>'task_token','production_sha'=>'production_sha','client_sha256'=>'client_sha256','input_sha256'=>'input_sha256'] as $a=>$b){if(($t[$a]??null)!==($state[$b]??null))hotel20_fail();}
 if(($state['output_public_sha256']??null)!==hash('sha256',$pub)||($state['case_count']??null)!==20)hotel20_fail();
 if($phase==='cleanup'){
  // Strictly confined to the fresh one-use namespace and fixed file patterns.
  foreach(['attempts','results'] as $sub){foreach(scandir($dir.'/'.$sub) as $n){if($n==='.'||$n==='..')continue;if(!preg_match('/\A(?:[0-9]{2}|header)\.json\z/D',$n)||is_link($dir.'/'.$sub.'/'.$n)||!is_file($dir.'/'.$sub.'/'.$n))hotel20_fail();if(!unlink($dir.'/'.$sub.'/'.$n))hotel20_fail();}if(!rmdir($dir.'/'.$sub))hotel20_fail();}
  foreach(['started.json','lease.json'] as $n){if(file_exists($dir.'/'.$n)){if(is_link($dir.'/'.$n)||!unlink($dir.'/'.$n))hotel20_fail();}}
  if(count(scandir($dir))!==2||!rmdir($dir))hotel20_fail();echo hotel20_json(['cleanup_ok'=>true,'token'=>$t['token']]);return;
 }
 if($phase==='collect'){
  $records=[];foreach(glob($dir.'/results/*.json') as $p){if(is_link($p)||filesize($p)>300000)hotel20_fail();$records[]=json_decode(file_get_contents($p),true,8,JSON_THROW_ON_ERROR);}
  echo hotel20_json(['schema'=>'hotel20_collected_v1','token'=>$t['token'],'input_sha256'=>$t['input_sha256'],'recipient_sha256'=>hash('sha256',$pub),'records'=>$records]);return;
 }
 if(time()>$state['expires_at']||file_exists($dir.'/started.json'))hotel20_fail();
 $enc=base64_decode($t['ciphertext_base64']??'',true);if($enc===false||strlen($enc)>4000000)hotel20_fail();
 $kp=base64_decode($state['keypair'],true);$compressed=sodium_crypto_box_seal_open($enc,$kp);if($compressed===false)hotel20_fail();
 $raw=gzdecode($compressed,8000000);if($raw===false||!hash_equals($t['input_sha256'],hash('sha256',$raw)))hotel20_fail();
 $input=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
 if(($input['schema']??'')!=='anytour_hotel_model20_input_v1'||!is_string($input['instructions']??null)||strlen($input['instructions'])>24000||!is_array($input['cases']??null)||count($input['cases'])!==20||($input['primary_attempt_ceiling']??null)!==20||($input['automatic_retries']??null)!==0)hotel20_fail();
 require_once $root.'/ai/ManagerDraftClient.php';
 // Same config-only credential path used by the earlier isolated runner; no app bootstrap, PDO, session or logger.
 set_error_handler(static function():never {throw new RuntimeException('config_failed');});try{require $root.'/config.php';}finally{restore_error_handler();}
 if(!defined('OPENAI_API_KEY')||!is_string(OPENAI_API_KEY)||trim(OPENAI_API_KEY)===''||!function_exists('curl_init'))hotel20_fail();
 $ids=[];
 foreach($input['cases'] as $i=>$case){
  $cid=sprintf('HS20-%02d',$i+1);if(($case['case_id']??null)!==$cid||!is_array($case['payload']??null))hotel20_fail();$ids[]=$cid;
  $messages=$case['payload']['conversation']['messages']??null;if(!is_array($messages)||count($messages)>120)hotel20_fail();$chars=0;$mids=[];
  foreach($messages as $m){if(!is_int($m['id']??null)||!in_array($m['role']??'',['customer','manager'],true)||!is_string($m['text']??null))hotel20_fail();$chars+=mb_strlen($m['text'],'UTF-8');$mids[]=$m['id'];}if($chars>40000||count($mids)!==count(array_unique($mids)))hotel20_fail();
  $body=ManagerDraftClient::requestBody($input['instructions'],$case['payload']);
  if(($body['model']??'')!=='gpt-6.1-sol'||($body['reasoning']['effort']??'')!=='low'||($body['service_tier']??'')!=='default'||($body['store']??null)!==false||($body['max_output_tokens']??null)!==2200||isset($body['tools'])||strlen(hotel20_json($body))>ManagerDraftClient::MAX_REQUEST_BYTES)hotel20_fail();
 }
 if(count(array_unique($ids))!==20)hotel20_fail();
 hotel20_write($dir.'/started.json',hotel20_json(['input_sha256'=>$t['input_sha256'],'cases'=>$ids,'attempt_ceiling'=>20,'retries'=>0,'started_at'=>gmdate('c')]));
 $header=['schema'=>'hotel20_header_v1','model'=>'gpt-6.1-sol','reasoning_effort'=>'low','service_tier'=>'default','case_count'=>20,'input_sha256'=>$t['input_sha256'],'production_sha'=>$t['production_sha'],'client_sha256'=>$t['client_sha256'],'request_timeout_seconds'=>20,'max_output_tokens'=>2200,'automatic_retries'=>0];
 hotel20_write($dir.'/results/header.json',hotel20_json(['case_id'=>'header','sealed_base64'=>base64_encode(sodium_crypto_box_seal(hotel20_json($header),$pub))]));
 foreach($input['cases'] as $i=>$case){
  $n=sprintf('%02d',$i+1);$cid=$case['case_id'];
  hotel20_write($dir.'/attempts/'.$n.'.json',hotel20_json(['case_id'=>$cid,'reserved_at'=>gmdate('c'),'input_sha256'=>$t['input_sha256']]));
  $meta=null;$start=hrtime(true);
  try{$result=ManagerDraftClient::requestJson($input['instructions'],$case['payload'],static function(array $m)use(&$meta):void{$meta=$m;});}catch(Throwable $ignored){$result=['_error'=>'client_exception'];}
  $elapsed=(int)round((hrtime(true)-$start)/1000000);
  $record=['case_id'=>$cid,'provider_client_invocations'=>1,'retry_count'=>0,'elapsed_ms'=>$elapsed,'provider_metadata'=>$meta,'result'=>$result,'provider_outcome_unknown'=>isset($result['_error'])&&in_array($result['_error'],['provider_timeout','provider_unavailable','client_exception'],true),'payload_sha256'=>hash('sha256',hotel20_json($case['payload']))];
  hotel20_write($dir.'/results/'.$n.'.json',hotel20_json(['case_id'=>$cid,'sealed_base64'=>base64_encode(sodium_crypto_box_seal(hotel20_json($record),$pub))]));
 }
 echo hotel20_json(['evaluate_completed'=>true,'case_count'=>20,'provider_client_invocations'=>20,'token'=>$t['token']]);
}
try {hotel20_main(HOTEL20_TASK);}catch(Throwable $ignored){fwrite(STDERR,"hotel20_failed\n");exit(1);}
