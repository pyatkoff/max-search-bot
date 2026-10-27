<?php
declare(strict_types=1);
// One canonical provider attempt on the trusted host; no application bootstrap/DB.
ini_set('display_errors','0');error_reporting(0);umask(0077);
$out=['result'=>['_error'=>'provider_unavailable'],'metadata'=>[],'canonical_request_attempted'=>false];$lock=null;$started=false;
function remoteJson(array $x): string{return json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function remoteRequire(bool $x):void{if(!$x)throw new RuntimeException('remote_control_gate');}
function runtimeSha(string $root):string{$p=proc_open(['git','rev-parse','HEAD'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);remoteRequire(is_resource($p));fclose($pipes[0]);$s=trim(stream_get_contents($pipes[1],128));fclose($pipes[1]);stream_get_contents($pipes[2],1024);fclose($pipes[2]);remoteRequire(proc_close($p)===0);return $s;}
function privateReceipt(string $p,array $v):void{remoteRequire(file_put_contents($p,remoteJson($v)."\n",LOCK_EX)!==false);chmod($p,0600);}
try {
    remoteRequire(PHP_SAPI==='cli'&&realpath(__DIR__)==='/tmp/hotel-model-jaz99-sunrise1554-20261027-v1');
    $meta=json_decode(file_get_contents(__DIR__.'/operation.json'),true,32,JSON_THROW_ON_ERROR);
    remoteRequire(($meta['max_paid_calls']??null)===2&&is_int($meta['deadline_unix']??null)&&time()+25<$meta['deadline_unix']);
    $root=realpath(__DIR__.'/repo');remoteRequire($root==='/var/www/anytoour/data/www/app.anytoour.ru'&&runtimeSha($root)===$meta['expected_runtime_sha']);
    foreach($meta['source_pins'] as $path=>$seal)remoteRequire(hash_file('sha256',$root.'/'.$path)===$seal);
    $raw=stream_get_contents(STDIN,393217);remoteRequire(is_string($raw)&&strlen($raw)<=393216);
    $in=json_decode($raw,true,64,JSON_THROW_ON_ERROR);$hash=$in['expected_request_sha256']??null;
    remoteRequire(is_string($hash)&&preg_match('/\A[a-f0-9]{64}\z/D',$hash)===1&&isset($meta['allowed_request_hashes'][$hash]));
    // Use the same runtime configuration wrapper as production profile verification.
    $level=ob_get_level();ob_start();try{require $root.'/config.php';}finally{while(ob_get_level()>$level)ob_end_clean();}
    require $root.'/ai/ManagerDraftClient.php';
    $body=ManagerDraftClient::requestBody($in['instructions'],$in['payload'],$in['images']);$encoded=remoteJson($body);
    remoteRequire(($body['model']??null)==='gpt-6.1-sol'&&($body['reasoning']['effort']??null)==='low'
        &&($body['service_tier']??null)==='default'&&($body['store']??null)===false&&($body['max_output_tokens']??null)===2200
        &&strlen($encoded)<=196608&&hash_equals($hash,hash('sha256',$encoded))&&$in['images']===[]);
    remoteRequire(defined('OPENAI_API_KEY')&&is_string(OPENAI_API_KEY)&&trim(OPENAI_API_KEY)!==''&&OPENAI_API_KEY!=='PASTE_OPENAI_API_KEY_HERE'
        &&strlen(OPENAI_API_KEY)<=4096&&!preg_match('/[\x00-\x20\x7F]/',OPENAI_API_KEY)&&function_exists('curl_init'));
    $dir=__DIR__.'/provider-receipts';if(!is_dir($dir))remoteRequire(mkdir($dir,0700));
    $lock=fopen($dir.'/operation.lock','c');remoteRequire(is_resource($lock)&&flock($lock,LOCK_EX));
    remoteRequire(count(glob($dir.'/*.started.json'))<2&&!file_exists($dir.'/'.$hash.'.started.json'));
    $start=fopen($dir.'/'.$hash.'.started.json','x');remoteRequire(is_resource($start));
    $marker=['schema'=>'hotel_model_remote_attempt_v1','operation_id'=>$meta['operation_id'],'request_sha256'=>$hash,
        'case_id'=>$meta['allowed_request_hashes'][$hash],'status'=>'ISSUED_OUTCOME_UNKNOWN','issued_at_utc'=>gmdate('c'),'expected_runtime_sha'=>$meta['expected_runtime_sha']];
    remoteRequire(fwrite($start,remoteJson($marker)."\n")!==false);fflush($start);if(function_exists('fsync'))fsync($start);fclose($start);$started=true;
    $usage=[];$result=ManagerDraftClient::requestJson($in['instructions'],$in['payload'],static function(array $m)use(&$usage):void{$usage=$m;},$in['images']);
    $out=['result'=>$result,'metadata'=>$usage,'canonical_request_attempted'=>true,'remote_request_sha256'=>$hash];
    $record=$marker+['provider_returned'=>true,'provider_output'=>$out,'runtime_sha_after'=>runtimeSha($root)];
    $record['status']='PROVIDER_RETURNED_REQUIRES_PARENT_VALIDATION';privateReceipt($dir.'/'.$hash.'.result.json',$record);
    remoteRequire($record['runtime_sha_after']===$meta['expected_runtime_sha']);
    foreach($meta['source_pins'] as $path=>$seal)remoteRequire(hash_file('sha256',$root.'/'.$path)===$seal);
}catch(Throwable $ignored){$out=['result'=>['_error'=>'provider_unavailable'],'metadata'=>[],'canonical_request_attempted'=>$started,'remote_outcome_unknown'=>$started];}
finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
echo remoteJson($out)."\n";
