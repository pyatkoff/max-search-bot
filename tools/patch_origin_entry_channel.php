<?php
$root=dirname(__DIR__);
function replaceExact(string $path,string $from,string $to):void{
    $src=file_get_contents($path);if($src===false)throw new RuntimeException("read {$path}");
    if(strpos($src,$from)===false)throw new RuntimeException("missing pattern in {$path}");
    $src=str_replace($from,$to,$src,$count);if($count<1)throw new RuntimeException("replace failed {$path}");
    file_put_contents($path,$src);
}
replaceExact($root.'/services/ManagerConversationService.php',
    'SELECT c.id,c.project_key,c.source_id,c.channel,c.status,c.lead_stage_key',
    'SELECT c.id,c.project_key,c.source_id,c.channel,c.entry_channel,c.attribution_region,c.attribution_campaign,c.status,c.lead_stage_key'
);
$from=<<<'TXT'
        $channel=strtoupper(trim((string)($row['channel']??'')));
        $hasSourceId=array_key_exists('source_id',$row);
TXT;
$to=<<<'TXT'
        $channelRaw=strtolower(trim((string)($row['channel']??'')));
        $channel=$channelRaw==='telegram'?'TG':strtoupper($channelRaw);
        $entry=trim((string)($row['entry_channel']??''));
        if($entry!=='')return trim($channel.($channel!==''?' · ':'').$entry);
        $hasSourceId=array_key_exists('source_id',$row);
TXT;
replaceExact($root.'/services/ManagerLeadInboxService.php',$from,$to);
replaceExact($root.'/manager/pipeline-api.php',
    <<<'TXT'
'channel'=>$c['channel']??null]
TXT,
    <<<'TXT'
'channel'=>$c['channel']??null,'entry_channel'=>$c['entry_channel']??null,'attribution_region'=>$c['attribution_region']??null,'attribution_campaign'=>$c['attribution_campaign']??null]
TXT
);
$test=$root.'/tests/run_manager_workspace_v2_origin_regression.php';
replaceExact($test,
    <<<'TXT'
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
TXT,
    <<<'TXT'
$api=(string)file_get_contents($root.'/manager/pipeline-api.php');
$conversationService=(string)file_get_contents($root.'/services/ManagerConversationService.php');
TXT
);
replaceExact($test,
    <<<'TXT'
originCheck('origin label combines channel and source suffix',ManagerLeadInboxService::originLabel(['channel'=>'max','source_name'=>'project:max_2','project_name'=>'Duplicate project'])==='MAX · max_2');
originCheck('origin label falls back to project when source missing',ManagerLeadInboxService::originLabel(['channel'=>'telegram','source_name'=>'','project_name'=>'tg_1'])==='TELEGRAM · tg_1');
TXT,
    <<<'TXT'
originCheck('origin label prefers explicit entry channel over source',ManagerLeadInboxService::originLabel(['channel'=>'max','entry_channel'=>'max_2','source_name'=>'project:legacy','project_name'=>'Duplicate project'])==='MAX · max_2');
originCheck('origin label uses short TG platform label for entry attribution',ManagerLeadInboxService::originLabel(['channel'=>'telegram','entry_channel'=>'tg_1','source_name'=>'project:legacy'])==='TG · tg_1');
originCheck('origin label still combines channel and source suffix when entry is absent',ManagerLeadInboxService::originLabel(['channel'=>'max','source_name'=>'project:max_2','project_name'=>'Duplicate project'])==='MAX · max_2');
originCheck('origin label falls back to project when source missing',ManagerLeadInboxService::originLabel(['channel'=>'telegram','source_name'=>'','project_name'=>'tg_1'])==='TG · tg_1');
originCheck('conversation list and detail select entry attribution fields',substr_count($conversationService,'c.entry_channel,c.attribution_region,c.attribution_campaign')>=2);
TXT
);
replaceExact($test,
    <<<'TXT'
originCheck('raw detail metadata remains available',strpos($api,"'project'=>\$c['project_name']")!==false&&strpos($api,"'source'=>\$c['source_name']")!==false&&strpos($api,"'channel'=>\$c['channel']")!==false);
TXT,
    <<<'TXT'
originCheck('raw detail metadata remains available',strpos($api,"'project'=>\$c['project_name']")!==false&&strpos($api,"'source'=>\$c['source_name']")!==false&&strpos($api,"'channel'=>\$c['channel']")!==false&&strpos($api,"'entry_channel'=>\$c['entry_channel']")!==false&&strpos($api,"'attribution_region'=>\$c['attribution_region']")!==false&&strpos($api,"'attribution_campaign'=>\$c['attribution_campaign']")!==false);
TXT
);
foreach(['services/ManagerConversationService.php','services/ManagerLeadInboxService.php','manager/pipeline-api.php','tests/run_manager_workspace_v2_origin_regression.php'] as $rel){passthru(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($root.'/'.$rel),$code);if($code!==0)exit($code);}
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($test),$code);exit($code);
