<?php
declare(strict_types=1);
// Private RPC pipe only. The HTTP client and key remain on the canonical host.
ini_set('display_errors','0');error_reporting(0);
$out=['result'=>['_error'=>'provider_unavailable'],'metadata'=>[],'canonical_request_attempted'=>false];
try {
    if(PHP_SAPI!=='cli'||getenv('HOTEL_CONTROL_PROVIDER_RPC')!=='1')throw new RuntimeException('mode');
    $host=getenv('HOTEL_CONTROL_RPC_HOST');$user=getenv('HOTEL_CONTROL_RPC_USER');$work=getenv('HOTEL_CONTROL_RPC_WORK');
    $key=getenv('HOTEL_CONTROL_RPC_KEY');$known=getenv('HOTEL_CONTROL_RPC_KNOWN_HOSTS');
    if(!is_string($host)||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9.-]{0,252}\z/D',$host)
        ||!is_string($user)||!preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}\z/D',$user)
        ||!is_string($work)||!preg_match('/\A\/tmp\/hotel-model-jaz99-sunrise1554-20261027-v1\z/D',$work)
        ||!is_string($key)||!is_file($key)||!is_string($known)||!is_file($known))throw new RuntimeException('rpc_scope');
    $raw=stream_get_contents(STDIN,393217);if(!is_string($raw)||strlen($raw)>393216)throw new RuntimeException('bound');
    $pipes=[];$p=proc_open(['ssh','-i',$key,'-o','BatchMode=yes','-o','StrictHostKeyChecking=yes',
        '-o','UserKnownHostsFile='.$known,'-o','ConnectTimeout=10','-o','ServerAliveInterval=10','-o','ServerAliveCountMax=2',
        $user.'@'.$host,'php',$work.'/model_control_provider_remote.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('rpc_start');
    $n=0;while($n<strlen($raw)){$x=fwrite($pipes[0],substr($raw,$n));if(!is_int($x)||$x<=0)throw new RuntimeException('rpc_input');$n+=$x;}fclose($pipes[0]);
    $result=stream_get_contents($pipes[1],262145);fclose($pipes[1]);stream_get_contents($pipes[2],4096);fclose($pipes[2]);$code=proc_close($p);
    if($code!==0||!is_string($result)||strlen($result)>262144)throw new RuntimeException('rpc_unknown');
    $decoded=json_decode($result,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($decoded)||!is_array($decoded['result']??null)||!is_array($decoded['metadata']??null)
        ||!is_bool($decoded['canonical_request_attempted']??null))throw new RuntimeException('rpc_result');
    $out=$decoded;
}catch(Throwable $ignored){$out=['result'=>['_error'=>'provider_unavailable'],'metadata'=>[],'canonical_request_attempted'=>false,'rpc_outcome_unknown'=>true];}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
