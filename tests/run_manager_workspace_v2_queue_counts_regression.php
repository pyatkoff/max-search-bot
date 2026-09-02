<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.css');
$api=(string)file_get_contents($root.'/manager/api.php');

$failed=0;
function qcCheck(string $name,bool $ok):void{global $failed;echo ($ok?'PASS  ':'FAIL  ').$name."\n";if(!$ok)$failed++;}

qcCheck('backend exposes canonical queue counts',strpos($api,"if(\$action==='counts')")!==false&&strpos($api,'ManagerConversationService::queueCounts')!==false);
qcCheck('inbox refreshes queue counts through existing manager API',strpos($inbox,"api('counts'")!==false&&strpos($inbox,'function refreshQueueCounts')!==false);
qcCheck('counts refresh is non-blocking for successful inbox load',strpos($inbox,'refreshQueueCounts();return true')!==false);
qcCheck('waiting and mine tabs render total counts',strpos($inbox,"renderQueueCount('waiting'")!==false&&strpos($inbox,"renderQueueCount('mine'")!==false&&strpos($inbox,"className='queueCount'")!==false);
qcCheck('unread queue badge stays separate from total lead count',strpos($inbox,"className='queueUnread'")!==false&&strpos($inbox,'непрочитанных сообщений')!==false);
qcCheck('queue badges have dedicated compact styling',strpos($css,'.queueCount')!==false&&strpos($css,'.queueUnread')!==false);

echo 'TOTAL '.(6-$failed)." PASS / {$failed} FAIL\n";
exit($failed?1:0);
