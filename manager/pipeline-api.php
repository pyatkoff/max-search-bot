<?php
session_name('anytour_manager_panel');
session_set_cookie_params(['lifetime'=>60*60*12,'path'=>'/max-search/manager/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();

$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once $baseDir.'/maxsearchclass.php';
require_once $baseDir.'/services/ManagerAuthService.php';
require_once $baseDir.'/services/ManagerConversationService.php';
require_once $baseDir.'/services/SalesPipelineService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pipelineOut(array $data,int $status=200):void{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function pipelineBody():array{$raw=(string)file_get_contents('php://input');$v=json_decode($raw,true);return is_array($v)?$v:[];}
function pipelineManager():?array{$id=(int)($_SESSION['manager_id']??0);return $id?ManagerAuthService::byId($id):null;}
function pipelineCsrf():string{return(string)($_SESSION['csrf']??'');}
function pipelineRequireCsrf(array $data):void{$expected=pipelineCsrf();if($expected===''||!hash_equals($expected,(string)($data['csrf']??'')))pipelineOut(['ok'=>false,'error'=>'csrf'],403);}
function pipelineConversation(int $conversationId,int $managerId):?array{$detail=ManagerConversationService::detail($conversationId,$managerId);return $detail?(array)($detail['conversation']??[]):null;}
function pipelineTrip(array $conversation):array{
    $chatId=$conversation['external_chat_id']??null;
    if($chatId===null||$chatId==='')return[];
    try{return class_exists('MaxSearchApi')?(array)MaxSearchApi::getAiSearchContext($chatId):[];}catch(Throwable $ignored){return[];}
}

$data=pipelineBody();$action=(string)($data['action']??'');
$m=pipelineManager();if(!$m)pipelineOut(['ok'=>false,'error'=>'unauthorized'],401);
pipelineRequireCsrf($data);

if($action==='catalog'){
    pipelineOut(['ok'=>true,'stages'=>SalesPipelineService::stages(true),'tags'=>SalesPipelineService::tags(true)]);
}

$conversationId=(int)($data['conversation_id']??0);
$conversation=pipelineConversation($conversationId,(int)$m['id']);
if(!$conversation)pipelineOut(['ok'=>false,'error'=>'not_found'],404);

if($action==='detail'){
    pipelineOut([
        'ok'=>true,
        'pipeline'=>SalesPipelineService::conversationSnapshot($conversationId),
        'trip'=>pipelineTrip($conversation),
        'contact'=>['phone'=>$conversation['phone']??null,'email'=>$conversation['email']??null],
        'source'=>[
            'project'=>$conversation['project_name']??$conversation['project_key']??null,
            'source'=>$conversation['source_name']??null,
            'channel'=>$conversation['channel']??null,
        ],
        'handoff'=>[
            'technical_status'=>$conversation['status']??null,
            'manager_name'=>$conversation['manager_name']??null,
        ],
    ]);
}
if($action==='set_stage'){
    $ok=SalesPipelineService::setStage($conversationId,(string)($data['stage_key']??''));
    pipelineOut(['ok'=>$ok,'pipeline'=>$ok?SalesPipelineService::conversationSnapshot($conversationId):null],$ok?200:409);
}
if($action==='set_tags'){
    $ok=SalesPipelineService::setTags($conversationId,(array)($data['tag_ids']??[]),(int)$m['id']);
    pipelineOut(['ok'=>$ok,'pipeline'=>$ok?SalesPipelineService::conversationSnapshot($conversationId):null],$ok?200:409);
}

pipelineOut(['ok'=>false,'error'=>'unknown_action'],400);
