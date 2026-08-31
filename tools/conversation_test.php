<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$base=dirname(__DIR__);
require_once $base.'/config.php';
require_once $base.'/services/TestConversationProvenance.php';

$action=strtolower(trim((string)($argv[1]??'')));
$id=(int)($argv[2]??0);
if(!in_array($action,['mark','clear'],true)||$id<=0){
    fwrite(STDERR,"Usage: php tools/conversation_test.php mark <conversation_id> <source> [reason]\n");
    fwrite(STDERR,"   or: php tools/conversation_test.php clear <conversation_id>\n");
    exit(2);
}

if($action==='mark'){
    $source=trim((string)($argv[3]??''));
    $reason=trim((string)($argv[4]??''));
    if($source===''){fwrite(STDERR,"source is required\n");exit(2);}
    $ok=TestConversationProvenance::mark($id,$source,$reason);
}else{
    $ok=TestConversationProvenance::clear($id);
}

echo ($ok?'OK':'NOT_CHANGED')." conversation_id={$id} action={$action}\n";
exit($ok?0:1);
