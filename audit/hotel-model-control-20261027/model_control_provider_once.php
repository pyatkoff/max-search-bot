<?php
declare(strict_types=1);
// One approved provider attempt only. Isolates runtime constants from SQLite fixtures.
ini_set('display_errors','0');
error_reporting(0);
$attempted=false;$metadata=[];
$reply=['result'=>['_error'=>'provider_unavailable'],'metadata'=>[],'canonical_request_attempted'=>false];
try {
    if (PHP_SAPI!=='cli') throw new RuntimeException('cli_only');
    $raw=stream_get_contents(STDIN,393217);
    if (!is_string($raw)||strlen($raw)>393216) throw new RuntimeException('input_bound');
    $in=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if (!is_array($in)||!is_string($in['instructions']??null)||!is_array($in['payload']??null)
        ||!is_array($in['images']??null)||!is_string($in['expected_request_sha256']??null)) throw new RuntimeException('input_shape');
    $config=__DIR__.'/repo/config.php';
    if (!is_file($config)||!is_readable($config)) throw new RuntimeException('runtime_config_missing');
    $level=ob_get_level();ob_start();
    try {require $config;} finally {while(ob_get_level()>$level)ob_end_clean();}
    // No operational/catalogue DB class or initializer is loaded in this process.
    require __DIR__.'/repo/ai/ManagerDraftClient.php';
    $body=ManagerDraftClient::requestBody($in['instructions'],$in['payload'],$in['images']);
    $encoded=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if (($body['model']??null)!=='gpt-6.1-sol'||($body['reasoning']['effort']??null)!=='low'
        ||($body['service_tier']??null)!=='default'||($body['store']??null)!==false
        ||($body['max_output_tokens']??null)!==2200||strlen($encoded)>196608
        ||!hash_equals($in['expected_request_sha256'],hash('sha256',$encoded))) throw new RuntimeException('profile_or_request_drift');
    if (!defined('OPENAI_API_KEY')||!is_string(OPENAI_API_KEY)||trim(OPENAI_API_KEY)===''
        ||OPENAI_API_KEY==='PASTE_OPENAI_API_KEY_HERE'||strlen(OPENAI_API_KEY)>4096
        ||preg_match('/[\x00-\x20\x7F]/',OPENAI_API_KEY)||!function_exists('curl_init')) throw new RuntimeException('provider_not_configured');
    $attempted=true;
    $result=ManagerDraftClient::requestJson($in['instructions'],$in['payload'],static function(array $m)use(&$metadata):void{$metadata=$m;},$in['images']);
    $reply=['result'=>$result,'metadata'=>$metadata,'canonical_request_attempted'=>true];
} catch (Throwable $ignored) {
    $reply=['result'=>['_error'=>'provider_unavailable'],'metadata'=>$metadata,'canonical_request_attempted'=>$attempted];
}
echo json_encode($reply,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
