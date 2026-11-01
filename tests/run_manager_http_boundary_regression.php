<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$http=(string)file_get_contents($root.'/manager/lib/ManagerHttp.php');
$api=(string)file_get_contents($root.'/manager/api.php');
$editService=(string)file_get_contents($root.'/services/ManagerMessageEditService.php');
$push=(string)file_get_contents($root.'/manager/push.php');
$status=(string)file_get_contents($root.'/manager/push-status.php');
$pushEnable=(string)file_get_contents($root.'/manager/push-enable.php');
$mediaUpload=(string)file_get_contents($root.'/manager/media-upload.php');
$mediaFile=(string)file_get_contents($root.'/manager/media-file.php');
$pipeline=(string)file_get_contents($root.'/manager/pipeline-api.php');
$admin=(string)file_get_contents($root.'/manager/admin.php');
$routing=(string)file_get_contents($root.'/manager/routing.php');

$passed=0;$failed=0;
function mhCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$unbounded=[];
foreach(glob($root.'/manager/*.php')?:[] as $managerEndpoint){
    $source=(string)file_get_contents($managerEndpoint);
    // Machine-to-machine MCP has its own OAuth bearer boundary, not a manager
    // browser session. Every other manager interface retains ManagerHttp.
    $name=basename($managerEndpoint);
    // Exact fixed wrappers delegate to independently checked shared boundaries.
    // Do not exempt arbitrary files or replace bearer auth with browser sessions.
    $isolated=['replies-mcp.php'=>['replies','SeparatedMcpHttp.php'],
        'hotels-mcp.php'=>['hotels','SeparatedMcpHttp.php'],
        'replies-oauth.php'=>['replies','SeparatedMcpOAuthHttp.php'],
        'hotels-oauth.php'=>['hotels','SeparatedMcpOAuthHttp.php']];
    if(isset($isolated[$name])) {
        [$profile,$helper]=$isolated[$name];
        if(!str_contains($source,"\$surfaceProfile='".$profile."';")
            ||!str_contains($source,"require __DIR__.'/lib/".$helper."';")
            ||str_contains($source,'$_GET')||str_contains($source,'$_POST')||str_contains($source,'$_REQUEST')) $unbounded[]=$name;
        continue;
    }
    if(in_array($name,['ai-mcp.php','development-mcp.php'],true))continue;
    if(strpos($source,"lib/ManagerHttp.php")===false||strpos($source,'ManagerHttp::start')===false)$unbounded[]=basename($managerEndpoint);
}
mhCheck('all session Manager PHP interfaces enter through ManagerHttp',$unbounded===[]);
$isolatedHttp=(string)file_get_contents($root.'/manager/lib/SeparatedMcpHttp.php');
$isolatedOauth=(string)file_get_contents($root.'/manager/lib/SeparatedMcpOAuthHttp.php');
$isolatedService=(string)file_get_contents($root.'/services/SeparatedMcpService.php');
mhCheck('isolated MCP wrappers keep a fixed OAuth audience before dispatch',
    str_contains($isolatedHttp,"in_array(\$surfaceProfile,['replies','hotels'],true)")
    &&str_contains($isolatedHttp,'new ManagerPreparationOAuth(null,null,$surfaceProfile)')
    &&str_contains($isolatedHttp,'->contextForBearer($header)')
    &&str_contains($isolatedHttp,'if ($context===null)')
    &&str_contains($isolatedHttp,'WWW-Authenticate: Bearer resource_metadata=')
    &&strpos($isolatedHttp,'->contextForBearer($header)')<strpos($isolatedHttp,'new SeparatedMcpService($surfaceProfile)')
    &&str_contains($isolatedHttp,"\$context['admin_id'],\$context['connection_id'],\$context['scopes']")
    &&!str_contains($isolatedHttp,'ManagerHttp::start'));
mhCheck('isolated OAuth browser routes retain manager session and CSRF boundary',
    str_contains($isolatedOauth,"in_array(\$surfaceProfile,['replies','hotels'],true)")
    &&str_contains($isolatedOauth,"require_once __DIR__.'/ManagerHttp.php'")
    &&str_contains($isolatedOauth,'ManagerHttp::start();')
    &&str_contains($isolatedOauth,'ManagerHttp::managerId()')
    &&str_contains($isolatedOauth,'ManagerHttp::csrf(true)')
    &&str_contains($isolatedOauth,'$oauth->disconnect($_SESSION,$adminId,')
    &&str_contains($isolatedOauth,'$oauth->completeAuthorization($_SESSION,'));
mhCheck('isolated command and event restrictions are checked before delegated calls',
    str_contains($isolatedService,'public const REPLY_TOOLS=')
    &&str_contains($isolatedService,'private function authorize(')
    &&str_contains($isolatedService,'connectionAuthorized(')
    &&str_contains($isolatedService,'throw new OutOfBoundsException()'));

// Apache/CGI may discard Authorization before PHP sees the request. Check the
// deployed file policy, not merely the PHP fallback that passed loopback tests.
$apache=(string)file_get_contents($root.'/manager/.htaccess');
preg_match_all('/<Files\s+"([^"]+)">([\s\S]*?)<\/Files>/i',$apache,$filePolicies,PREG_SET_ORDER);
$bearerFiles=[];
foreach($filePolicies as $policy) {
    if(preg_match('/^\s*SetEnvIfNoCase\s+Authorization\s+"\^\(\.\+\)\$"\s+HTTP_AUTHORIZATION=\$1\s*$/mi',$policy[2])) $bearerFiles[]=$policy[1];
}
sort($bearerFiles,SORT_STRING);
$expectedBearerFiles=['ai-mcp.php','development-mcp.php','hotels-mcp.php','replies-mcp.php'];
mhCheck('Apache preserves the real Authorization header on every fixed MCP endpoint',$bearerFiles===$expectedBearerFiles);
$outsideFilePolicies=preg_replace('/<Files\s+"[^"]+">[\s\S]*?<\/Files>/i','',$apache);
mhCheck('Authorization forwarding stays file-scoped and never broadens manager browser or OAuth routes',
    is_string($outsideFilePolicies)&&!preg_match('/^\s*(?:SetEnvIfNoCase\s+Authorization|CGIPassAuth\s+On)\b/mi',$outsideFilePolicies));
mhCheck('isolated ingress records the effective header only under its fixed server profile',
    str_contains($isolatedHttp,"\$_SERVER['HTTP_AUTHORIZATION']=\$header;")
    &&strpos($isolatedHttp,"\$_SERVER['HTTP_AUTHORIZATION']=\$header;")<strpos($isolatedHttp,'->contextForBearer($header)')
    &&str_contains($isolatedHttp,"\$body['error']['code']??null,\$surfaceProfile)")
    &&str_contains($isolatedHttp,"202,'complete',null,\$surfaceProfile)")
    &&!str_contains($isolatedHttp,'HTTP_X_FORWARDED_AUTHORIZATION'));

$mcp=(string)file_get_contents($root.'/manager/ai-mcp.php');
mhCheck('legacy MCP interface retains mandatory OAuth bearer and connection binding',
    strpos($mcp,'ManagerPreparationOAuth::bearerContext($header,$preparationMcpForecastBudget)')!==false
    &&strpos($mcp,"\$authorization['connection_id']")!==false
    &&strpos($mcp,"\$authorization['scopes']")!==false
    &&strpos($mcp,'if ($actor===null||$connection===null)')!==false
    &&strpos($mcp,'WWW-Authenticate: Bearer resource_metadata=')!==false
    &&strpos($mcp,'bearerContext($header,$preparationMcpForecastBudget)')<strpos($mcp,"->handle(\$rpc,\$actor,\$connection,\$authorization['scopes'],\$preparationMcpForecastBudget)")
    &&strpos($mcp,'ManagerRequestContext::')===false&&strpos($mcp,'ManagerHttp::start')===false);

$developmentMcp=(string)file_get_contents($root.'/manager/development-mcp.php');
mhCheck('separate development MCP binds the exact audience before service dispatch',
    strpos($developmentMcp,'new ManagerPreparationOAuth(null,null,ManagerPreparationOAuth::PROFILE_DEVELOPMENT)')!==false
    &&strpos($developmentMcp,'->contextForBearer($header)')!==false
    &&strpos($developmentMcp,"\$authorization['connection_id']")!==false
    &&strpos($developmentMcp,"\$authorization['scopes']")!==false
    &&strpos($developmentMcp,'if ($actor===null||$connection===null)')!==false
    &&strpos($developmentMcp,'ManagerPreparationOAuth::DEVELOPMENT_ENDPOINT')!==false
    &&strpos($developmentMcp,'scope="development_work:write"')!==false
    &&strpos($developmentMcp,'->contextForBearer($header)')<strpos($developmentMcp,"->handle(\$rpc,\$actor,\$connection,\$authorization['scopes'])")
    &&strpos($developmentMcp,'new DevelopmentWorkMcpService()')!==false
    &&strpos($developmentMcp,'ManagerPreparationOAuth::bearerContext($header)')===false
    &&strpos($developmentMcp,'ManagerRequestContext::')===false&&strpos($developmentMcp,'ManagerHttp::start')===false);
mhCheck('development ingress diagnostic surface is server-selected for responses and notifications',
    strpos($developmentMcp,"\$body['error']['code']??null,'development'")!==false
    &&strpos($developmentMcp,"202,'complete',null,'development'")!==false
    &&strpos($mcp,"\$body['error']['code']??null,'development'")===false);

mhCheck('HTTP boundary owns session and JSON headers',strpos($http,'function start()')!==false&&strpos($http,'ManagerRequestContext::startSession()')!==false&&strpos($http,'self::start();')!==false&&strpos($http,"Content-Type: application/json")!==false&&strpos($http,"Cache-Control: no-store")!==false);
mhCheck('legacy manager path redirects to canonical app origin before session bootstrap',strpos($http,"legacyPrefix='/max-search/manager'")!==false&&strpos($http,"target='https://app.anytoour.ru'")!==false&&strpos($http,"header('Location: '.\$target,true,308)")!==false&&strpos($http,'self::redirectLegacyPath();')<strpos($http,'ManagerRequestContext::startSession();'));
mhCheck('HTTP boundary owns JSON body lookup',strpos($http,'return ManagerRequestContext::jsonBody();')!==false);
mhCheck('HTTP boundary owns manager authorization',strpos($http,"'error'=>'unauthorized'")!==false&&strpos($http,'self::respond(')!==false);
mhCheck('HTTP boundary exposes csrf token and csrf/admin guards',strpos($http,'function csrf(bool $rotate=false)')!==false&&strpos($http,'return ManagerRequestContext::csrf($rotate);')!==false&&strpos($http,'function requireCsrf')!==false&&strpos($http,'ManagerRequestContext::validCsrf')!==false&&strpos($http,'function isAdmin')!==false&&strpos($http,'return ManagerRequestContext::isAdmin($manager);')!==false&&strpos($http,'function requireAdmin')!==false&&strpos($http,'if(!self::isAdmin($manager))')!==false);
mhCheck('HTTP boundary owns assigned conversation authorization',strpos($http,'function canEditConversation')!==false&&strpos($http,'ManagerRequestContext::canEditAssignedConversation')!==false&&strpos($http,'function requireConversationEdit')!==false&&strpos($http,"'error'=>'forbidden'")!==false);
mhCheck('CSRF guard validates existing session token without rotating it',strpos($http,'ManagerRequestContext::validCsrf($token)')!==false&&strpos($http,'self::csrf(true)')===false);
mhCheck('HTTP boundary owns consistent JSON response flags',strpos($http,'JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES')!==false);
mhCheck('main Manager API delegates HTTP and auth boundary',strpos($api,"require_once __DIR__ . '/lib/ManagerHttp.php'")!==false&&strpos($api,'ManagerHttp::startJson();')!==false&&strpos($api,'ManagerHttp::body();')!==false&&strpos($api,'ManagerHttp::requireManager();')!==false&&strpos($api,'ManagerHttp::csrf(true)')!==false&&strpos($api,'ManagerHttp::requireCsrf($data);')!==false&&strpos($api,'ManagerHttp::requireAdmin($m);')!==false&&strpos($api,'ManagerHttp::isAdmin($m);')!==false&&strpos($api,'ManagerRequestContext::')===false);
mhCheck('main Manager API does not recreate shared auth/csrf wrapper helpers',strpos($api,'function body()')===false&&strpos($api,'function manager()')===false&&strpos($api,'function csrf()')===false&&strpos($api,'function requireCsrf(')===false&&strpos($api,'function requireAdmin(')===false&&strpos($api,"if(!$m) out(['ok'=>false,'error'=>'unauthorized']")===false);
mhCheck('main Manager API keeps business actions outside HTTP helper',strpos($api,'ManagerConversationService::')!==false&&strpos($api,'ManagerOutboundService::')!==false&&strpos($api,'ManagerAvailabilityService::')!==false&&strpos($http,'ManagerConversationService')===false&&strpos($http,'ManagerOutboundService')===false&&strpos($http,'ManagerAvailabilityService')===false);
mhCheck('manager edit candidates stay behind shared auth and csrf boundary',strpos($api,"if(\$action==='edit_candidates')")!==false&&strpos($api,'ManagerMessageEditService::editableForConversation')!==false);
mhCheck('manager edit eligibility is bounded by the common five-minute policy',strpos($editService,'ManagerMessageEditPolicy::WINDOW_SECONDS')!==false&&strpos($editService,'ManagerMessageEditPolicy::inspect')!==false);
mhCheck('manager edit action stays behind shared auth and csrf boundary',strpos($api,"if(\$action==='edit_message')")!==false&&strpos($api,'ManagerMessageEditService::edit')!==false&&strpos($api,'ManagerHttp::requireCsrf($data);')!==false);
mhCheck('manager edit service requires common policy before provider mutation',strpos($editService,'ManagerMessageEditPolicy::inspect')!==false&&strpos($editService,"empty(\$policy['allowed'])")!==false);
mhCheck('provider edits precede local transcript mutation',strpos($editService,'->editText')!==false&&strpos($editService,'->editText')<strpos($editService,'UPDATE messages SET text='));
mhCheck('provider media edits fail closed until caption/media edit is supported',strpos($editService,'media_edit_not_supported')!==false);
mhCheck('push endpoint delegates HTTP lifecycle and protects subscription writes',strpos($push,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($push,'ManagerHttp::startJson();')!==false&&strpos($push,'ManagerHttp::requireManager();')!==false&&strpos($push,"'enable_intent','subscribe','revoke'")!==false&&strpos($push,'ManagerHttp::requireCsrf($data);')!==false&&strpos($push,'ManagerRequestContext::')===false);
mhCheck('push status endpoint delegates HTTP lifecycle',strpos($status,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($status,'ManagerHttp::startJson();')!==false&&strpos($status,'ManagerHttp::requireManager();')!==false&&strpos($status,'ManagerRequestContext::')===false);
mhCheck('push enable page delegates session auth and csrf lifecycle without JSON headers',strpos($pushEnable,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($pushEnable,'ManagerHttp::start();')!==false&&strpos($pushEnable,'ManagerHttp::managerId()')!==false&&strpos($pushEnable,'ManagerHttp::manager()')!==false&&strpos($pushEnable,'ManagerHttp::csrf(true)')!==false&&strpos($pushEnable,'assets/workspace-v2-notifications.js?v=')!==false&&strpos($pushEnable,'csrf:<?=json_encode($csrf,')!==false&&strpos($pushEnable,'data-enable-push')!==false&&strpos($pushEnable,'ManagerRequestContext::')===false&&strpos($pushEnable,'ManagerHttp::startJson();')===false);
mhCheck('media upload delegates auth csrf and JSON response lifecycle',strpos($mediaUpload,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($mediaUpload,'ManagerHttp::startJson();')!==false&&strpos($mediaUpload,'ManagerHttp::requireManager();')!==false&&strpos($mediaUpload,'ManagerHttp::requireCsrf($_POST);')!==false&&strpos($mediaUpload,'ManagerRequestContext::')===false&&strpos($mediaUpload,'function mediaOut')===false);
mhCheck('media preview delegates session and auth lifecycle without JSON headers',strpos($mediaFile,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($mediaFile,'ManagerHttp::start();')!==false&&strpos($mediaFile,'ManagerHttp::requireManager();')!==false&&strpos($mediaFile,'ManagerHttp::managerId();')!==false&&strpos($mediaFile,'ManagerRequestContext::')===false&&strpos($mediaFile,'ManagerHttp::startJson();')===false);
mhCheck('pipeline API delegates auth csrf response and conversation authorization',strpos($pipeline,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($pipeline,'ManagerHttp::startJson();')!==false&&strpos($pipeline,'ManagerHttp::body();')!==false&&strpos($pipeline,'ManagerHttp::requireManager();')!==false&&strpos($pipeline,'ManagerHttp::requireCsrf($data);')!==false&&strpos($pipeline,'ManagerHttp::canEditConversation(')!==false&&strpos($pipeline,'ManagerHttp::requireConversationEdit(')!==false&&strpos($pipeline,'ManagerRequestContext::')===false&&strpos($pipeline,'function pipelineOut')===false);
mhCheck('pipeline list delegates actionable waiting/attention projection to canonical service',strpos($pipeline,"require_once \$baseDir.'/services/ManagerQueueProjectionService.php';")!==false&&strpos($pipeline,'ManagerQueueProjectionService::actionableRows($queue,$rows)')!==false&&strpos($pipeline,"if(in_array($queue,['waiting','attention'],true))")===false);
mhCheck('admin and routing shells delegate session bootstrap to ManagerHttp',strpos($admin,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($admin,'ManagerHttp::start();')!==false&&strpos($admin,'ManagerRequestContext::')===false&&strpos($routing,"require_once __DIR__.'/lib/ManagerHttp.php'")!==false&&strpos($routing,'ManagerHttp::start();')!==false&&strpos($routing,'ManagerRequestContext::')===false);
mhCheck('pipeline business services stay outside HTTP helper',strpos($pipeline,'SalesPipelineService::')!==false&&strpos($pipeline,'LeadTaskService::')!==false&&strpos($http,'SalesPipelineService')===false&&strpos($http,'LeadTaskService')===false);
mhCheck('business services remain outside HTTP helper',strpos($http,'ManagerPushService')===false&&strpos($http,'ManagerPushHealth')===false&&strpos($http,'ManagerOutboundService')===false&&strpos($push,'ManagerPushService::')!==false&&strpos($status,'ManagerPushHealth::')!==false&&strpos($mediaUpload,'ManagerOutboundService::')!==false);
mhCheck('all current Manager write interfaces use shared csrf guard',strpos($api,'ManagerHttp::requireCsrf($data);')!==false&&strpos($pipeline,'ManagerHttp::requireCsrf($data);')!==false&&strpos($mediaUpload,'ManagerHttp::requireCsrf($_POST);')!==false&&strpos($push,'ManagerHttp::requireCsrf($data);')!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
