<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'content100-bot-publication-20261101-v1','mode'=>'read_failed_receipt_only','ok'=>false,'post_write_attempts'=>0];
try{
 if(trim((string)shell_exec('git rev-parse HEAD'))!==EXPECTED_INSTALLED_SHA)throw new RuntimeException('installed_source_changed');
 $path='/var/www/anytoour/data/channel-post-operations/content100-bot-publication-20261101-v1/telegram-at-h1554-child-facilities-scope-619847-bulk.json';
 $s=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
 foreach(['state','provider_http','provider_error_code','retry_after_seconds','publication_id','version_id'] as $k)$out[$k]=$s[$k]??null;
 $out['ok']=true;
}catch(Throwable $e){$out['error']='exact_failed_receipt_unavailable';}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;exit($out['ok']?0:2);
