<?php
$baseDir=dirname(__DIR__);
require_once $baseDir.'/config.php';
require_once $baseDir.'/maxsearchclass.php';
require_once __DIR__.'/lib/ManagerHttp.php';
require_once $baseDir.'/services/ManagerConversationService.php';
require_once $baseDir.'/services/ManagerDeliveryStateService.php';
require_once $baseDir.'/services/ManagerLeadInboxService.php';
require_once $baseDir.'/services/ManagerWorkspaceFilterService.php';
require_once $baseDir.'/services/SalesPipelineService.php';
require_once $baseDir.'/services/SalesPipelineCatalogAdminService.php';
require_once $baseDir.'/services/LeadTaskService.php';

ManagerHttp::startJson();

function pipelineConversation(int $id,int $mid):?array{$d=ManagerConversationService::detail($id,$mid);return$d?(array)($d['conversation']??[]):null;}
function pipelineTrip(array $c):array{$chat=$c['external_chat_id']??null;if($chat===null||$chat==='')return[];try{return class_exists('MaxSearchApi')?(array)MaxSearchApi::getAiSearchContext($chat):[];}catch(Throwable $ignored){return[];}}

$data=ManagerHttp::body();
$action=(string)($data['action']??'');
$m=ManagerHttp::requireManager();
ManagerHttp::requireCsrf($data);
$isAdmin=ManagerHttp::isAdmin($m);

if($action==='catalog')ManagerHttp::respond(['ok'=>true,'stages'=>SalesPipelineService::stages(true),'tags'=>SalesPipelineService::tags(true),'outcomes'=>SalesPipelineService::outcomeOptions(),'close_reasons'=>SalesPipelineService::closeReasonOptions()]);
if($action==='filter_options')ManagerHttp::respond(['ok'=>true,'filters'=>ManagerWorkspaceFilterService::snapshot((int)$m['id'])]);
if($action==='admin_catalog'){ManagerHttp::requireAdmin($m);ManagerHttp::respond(['ok'=>true,'catalog'=>SalesPipelineCatalogAdminService::snapshot()]);}
if($action==='save_stage'){ManagerHttp::requireAdmin($m);$r=SalesPipelineCatalogAdminService::saveStage($data,(int)$m['id']);ManagerHttp::respond($r,!empty($r['ok'])?200:422);}
if($action==='save_tag'){ManagerHttp::requireAdmin($m);$r=SalesPipelineCatalogAdminService::saveTag($data,(int)$m['id']);ManagerHttp::respond($r,!empty($r['ok'])?200:422);}
if($action==='list'){
    $queue=(string)($data['queue']??'waiting');
    $managerFilter=$isAdmin?(string)($data['manager_filter']??''):'';
    $rows=ManagerConversationService::list((int)$m['id'],$queue,200,(string)($data['project_key']??'*'),$managerFilter,(string)($data['lead_stage_key']??''),(int)($data['lead_tag_id']??0));
    $sourceId=(int)($data['source_id']??0);
    if($sourceId===-1)$rows=array_values(array_filter($rows,static fn($r):bool=>(int)($r['source_id']??0)<=0));
    elseif($sourceId>0)$rows=array_values(array_filter($rows,static fn($r):bool=>(int)($r['source_id']??0)===$sourceId));
    if(in_array($queue,['waiting','attention'],true))$rows=array_values(array_filter($rows,fn($r)=>empty($r['delivery_failure_category'])));
    $rows=ManagerLeadInboxService::decorate($rows);
    $rows=ManagerLeadInboxService::filter($rows,(string)($data['lead_outcome']??''),(string)($data['search']??''),(string)($data['lead_task_filter']??''));
    foreach($rows as &$row)$row['can_edit_pipeline']=ManagerHttp::canEditConversation($row,$m);
    unset($row);
    ManagerHttp::respond(['ok'=>true,'conversations'=>array_slice($rows,0,100)]);
}

$id=(int)($data['conversation_id']??0);
$c=pipelineConversation($id,(int)$m['id']);
if(!$c)ManagerHttp::respond(['ok'=>false,'error'=>'not_found'],404);
$can=ManagerHttp::canEditConversation($c,$m);

if($action==='detail')ManagerHttp::respond(['ok'=>true,'can_edit_pipeline'=>$can,'pipeline'=>SalesPipelineService::conversationSnapshot($id),'tasks'=>LeadTaskService::listForConversation($id),'trip'=>pipelineTrip($c),'contact'=>['phone'=>$c['phone']??null,'email'=>$c['email']??null],'source'=>['origin_label'=>ManagerLeadInboxService::originLabel($c),'project'=>$c['project_name']??$c['project_key']??null,'source'=>$c['source_name']??null,'channel'=>$c['channel']??null],'handoff'=>['technical_status'=>$c['status']??null,'manager_name'=>$c['manager_name']??null,'delivery_failure'=>ManagerDeliveryStateService::activeFailure($id)]]);
if($action==='set_stage'){
    ManagerHttp::requireConversationEdit($c,$m);
    $ok=SalesPipelineService::setStage($id,(string)($data['stage_key']??''),(int)$m['id']);
    ManagerHttp::respond(['ok'=>$ok,'pipeline'=>$ok?SalesPipelineService::conversationSnapshot($id):null],$ok?200:409);
}
if($action==='set_tags'){
    ManagerHttp::requireConversationEdit($c,$m);
    $ok=SalesPipelineService::setTags($id,(array)($data['tag_ids']??[]),(int)$m['id']);
    ManagerHttp::respond(['ok'=>$ok,'pipeline'=>$ok?SalesPipelineService::conversationSnapshot($id):null],$ok?200:409);
}
if($action==='set_outcome'){
    ManagerHttp::requireConversationEdit($c,$m);
    $ok=SalesPipelineService::setOutcome($id,(string)($data['outcome']??''),isset($data['close_reason'])?(string)$data['close_reason']:null,isset($data['note'])?(string)$data['note']:null,(int)$m['id'],isset($data['sale_amount'])?(string)$data['sale_amount']:null,isset($data['sale_date'])?(string)$data['sale_date']:null);
    ManagerHttp::respond(['ok'=>$ok,'pipeline'=>$ok?SalesPipelineService::conversationSnapshot($id):null],$ok?200:422);
}
if($action==='create_task'){
    ManagerHttp::requireConversationEdit($c,$m);
    $assigned=(int)($c['manager_id']??0);
    $result=LeadTaskService::create($id,(string)($data['title']??''),isset($data['due_at'])?(string)$data['due_at']:null,(int)$m['id'],$assigned>0?$assigned:(int)$m['id']);
    ManagerHttp::respond($result+['tasks'=>!empty($result['ok'])?LeadTaskService::listForConversation($id):null],!empty($result['ok'])?200:422);
}
if($action==='update_task'){
    ManagerHttp::requireConversationEdit($c,$m);
    $result=LeadTaskService::update($id,(int)($data['task_id']??0),(string)($data['title']??''),isset($data['due_at'])?(string)$data['due_at']:null);
    $status=!empty($result['ok'])?200:(($result['error']??'')==='not_found'?404:422);
    ManagerHttp::respond($result+['tasks'=>!empty($result['ok'])?LeadTaskService::listForConversation($id):null],$status);
}
if($action==='set_task_completed'){
    ManagerHttp::requireConversationEdit($c,$m);
    $ok=LeadTaskService::setCompleted($id,(int)($data['task_id']??0),!empty($data['completed']));
    ManagerHttp::respond(['ok'=>$ok,'tasks'=>$ok?LeadTaskService::listForConversation($id):null],$ok?200:404);
}
if($action==='set_task_pinned'){
    ManagerHttp::requireConversationEdit($c,$m);
    $ok=LeadTaskService::setPinned($id,(int)($data['task_id']??0),!empty($data['pinned']));
    ManagerHttp::respond(['ok'=>$ok,'tasks'=>$ok?LeadTaskService::listForConversation($id):null],$ok?200:404);
}
ManagerHttp::respond(['ok'=>false,'error'=>'unknown_action'],400);
