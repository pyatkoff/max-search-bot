<?php
declare(strict_types=1);
$passed=0;
function mediaPolicyCheck(string $name,bool $ok):void {global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";$passed++;}
function policyProcess(string $upload,string $post):array {
    $source='require '.var_export(dirname(__DIR__).'/services/ManagerQuickReplyMediaPolicy.php',true).'; echo json_encode([ManagerQuickReplyMediaPolicy::ordinaryUpload(),ManagerQuickReplyMediaPolicy::forChannel("max")]);';
    $pipes=[];$process=proc_open([PHP_BINARY,'-c',php_ini_loaded_file()?:'/dev/null','-d','upload_max_filesize='.$upload,'-d','post_max_size='.$post,'-r',$source],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('process unavailable');
    $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0)throw new RuntimeException($error);
    return json_decode($out,true,32,JSON_THROW_ON_ERROR);
}
[$ordinary,$material]=policyProcess('300M','400M');
mediaPolicyCheck('ordinary separate upload retains PHP per-request cap without material50MB cap',$ordinary['server_max_file_bytes']===300*1024*1024&&$ordinary['channels']['max']['max_file_bytes_by_type']['file']===300*1024*1024&&$material['max_file_bytes_by_type']['file']===50000000);
mediaPolicyCheck('ordinary selection has no aggregate material POST cap',!isset($ordinary['channels']['max']['max_total_bytes'])&&isset($material['max_total_bytes']));
mediaPolicyCheck('current Telegram multipart photo and document limits remain distinct',$ordinary['channels']['telegram']['max_file_bytes_by_type']['image']===10000000&&$ordinary['channels']['telegram']['max_file_bytes_by_type']['file']===50000000);
[$small]=policyProcess('8M','2M');mediaPolicyCheck('multipart headroom is applied to each single request',$small['server_max_file_bytes']===2*1024*1024-65536);
[$bytes]=policyProcess('131072','128K');mediaPolicyCheck('byte and K settings are parsed without unit confusion',$bytes['server_max_file_bytes']===65536);
[$tiny]=policyProcess('32K','32K');mediaPolicyCheck('insufficient multipart headroom reports zero usable bytes',$tiny['server_max_file_bytes']===0&&$tiny['channels']['max']['max_file_bytes_by_type']['file']===0);
[$unbounded]=policyProcess('0','0');mediaPolicyCheck('unbounded PHP setting does not invent a server maximum',$unbounded['server_max_file_bytes']===null&&$unbounded['channels']['max']['max_file_bytes_by_type']['file']===4000000000);
mediaPolicyCheck('ordinary projection is identity-free and retains supported transport presentation',array_keys($ordinary)===['schema','server_max_file_bytes','channels']&&$ordinary['channels']['max']['separate_messages']&&$ordinary['channels']['telegram']['caption_first_only']);
echo 'TOTAL '.$passed." | FAIL 0\n";
