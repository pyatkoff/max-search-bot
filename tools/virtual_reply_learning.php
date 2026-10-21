<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$mode=$argv[1]??'';
$source=$argv[2]??'';
$lane=$argv[3]??'';
$rawLimit=$argv[$mode==='capture'?4:2]??'';
$validLimit=is_string($rawLimit)&&preg_match('/\A(?:[1-9]|1[0-9]|20)\z/D',$rawLimit);
if (!$validLimit||!in_array($mode,['capture','reconcile'],true)
    ||($mode==='capture'&&(count($argv)!==5||!in_array($source,['conversation','telegram_archive'],true)||!in_array($lane,['fresh','history'],true)))
    ||($mode==='reconcile'&&count($argv)!==3)) {
    fwrite(STDERR,"Usage: php tools/virtual_reply_learning.php capture conversation|telegram_archive fresh|history 1..20\n"
        ."       php tools/virtual_reply_learning.php reconcile 1..20\n");
    exit(2);
}

try {
    require_once dirname(__DIR__).'/config.php';
    require_once dirname(__DIR__).'/services/VirtualReplyStore.php';
    if (ProjectConfig::projectId()!=='anytour') throw new RuntimeException();
    $store=new VirtualReplyStore(ConversationDb::connection());
    $result=$mode==='capture'?$store->capture($source,$lane,(int)$rawLimit):$store->reconcile((int)$rawLimit);
    // Cron output is aggregate only. No question, scope, answer, claim or review text.
    $counts=[];
    foreach (['scanned','captured','unchanged','invalidated','checked','recaptured','expired'] as $key) {
        if (is_int($result[$key]??null)) $counts[$key]=$result[$key];
    }
    echo json_encode(['ok'=>true,'operation'=>$mode,'counts'=>$counts,'provider_calls'=>0],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $ignored) {
    fwrite(STDERR,"virtual_reply_learning_failed\n");exit(1);
}
