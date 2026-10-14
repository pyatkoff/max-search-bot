<?php
declare(strict_types=1);

// Execute both real PHP entrypoints in isolated processes, without production data.
$root=dirname(__DIR__);
function renderWorkspace(string $entry):string{
    global $root;
    $p=proc_open([PHP_BINARY,$root.'/'.$entry],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    if(!is_resource($p))throw new RuntimeException('Cannot render workspace');
    $html=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($p)!==0||$errors!=='')throw new RuntimeException('Workspace rendering failed: '.$errors);
    return $html;
}
$legacy=renderWorkspace('manager/index.php');$modern=renderWorkspace('manager2/index.php');
$checks=0;
function checkManager2(string $name,bool $ok):void{global $checks;if(!$ok){fwrite(STDERR,'FAIL '.$name."\n");exit(1);}echo 'PASS '.$name."\n";$checks++;}
checkManager2('canonical entrypoint renders without modern presentation',str_contains($legacy,'<title>AnyTour — кабинет менеджера</title>')&&!str_contains($legacy,'class="manager2"')&&!str_contains($legacy,'<base '));
checkManager2('alternate entrypoint opts into the real canonical shell',str_contains($modern,'<body class="manager2">')&&str_contains($modern,'id="workspaceRoot"')&&str_contains($modern,'<base href="/manager/">'));
preg_match_all('/<script src="([^"]+)"/',$legacy,$oldAssets);preg_match_all('/<script src="([^"]+)"/',$modern,$newAssets);
checkManager2('all canonical behavior modules are retained in their original order',array_values(array_filter($newAssets[1],static fn($s)=>!str_starts_with($s,'/manager2/')&&!str_starts_with($s,'/manager/assets/workspace-v2-post-context.js?')))===$oldAssets[1]);
checkManager2('optional original-post module loads once before presentation startup',substr_count($modern,'/manager/assets/workspace-v2-post-context.js?')===1&&strpos($modern,'/manager/assets/workspace-v2-post-context.js?')<strpos($modern,'/manager2/assets/manager2.js'));
checkManager2('presentation loads before canonical startup',strpos($modern,'/manager2/assets/manager2.js')<strpos($modern,'workspace-v2-bootstrap.js'));
foreach(['workspaceRoot','managerName','managerShiftBtn','adminLink','notificationStatus','listViewBtn','kanbanViewBtn','leadSearch','filtersPanel','inboxList','kanbanBoard','conversationZone','conversationActions','messages','composer','replyText','replyFile','emojiToggle','sendReply','leadZone','leadCard','mobileLeadBtn','mobileLeadClose'] as $id){
    checkManager2('exactly one working control '.$id,substr_count($modern,'id="'.$id.'"')===1&&substr_count($legacy,'id="'.$id.'"')===1);
}
checkManager2('modern assets exist and are cache-busted',is_file($root.'/manager2/assets/manager2.css')&&is_file($root.'/manager2/assets/manager2.js')&&preg_match('~/manager2/assets/manager2\.(?:css|js)\?v=\d+~',$modern)===1);
checkManager2('new entrypoint contains no parallel endpoint or business rules',count(glob($root.'/manager2/*api*.php'))===0&&str_contains((string)file_get_contents($root.'/manager2/index.php'),"require dirname(__DIR__).'/manager/index.php'"));
echo 'MANAGER2 ENTRYPOINT CHECKS: '.$checks." PASSED\n";
