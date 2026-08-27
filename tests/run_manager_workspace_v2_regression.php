<?php

declare(strict_types=1);

$workspace=(string)file_get_contents(dirname(__DIR__).'/manager/workspace-v2.php');
$api=(string)file_get_contents(dirname(__DIR__).'/manager/pipeline-api.php');
$conversations=(string)file_get_contents(dirname(__DIR__).'/services/ManagerConversationService.php');
$pipeline=(string)file_get_contents(dirname(__DIR__).'/services/SalesPipelineService.php');
$passed=0;$failed=0;
function mw2Check(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

mw2Check('workspace has three-zone desktop structure',strpos($workspace,'inboxZone')!==false&&strpos($workspace,'conversationZone')!==false&&strpos($workspace,'leadZone')!==false&&strpos($workspace,'grid-template-columns:320px minmax(420px,1fr) 340px')!==false);
mw2Check('workspace has mobile adaptation',strpos($workspace,'@media(max-width:900px)')!==false&&strpos($workspace,'.conversationZone.open')!==false&&strpos($workspace,'.leadZone.open')!==false);
mw2Check('transcript visually separates customer AI and manager',strpos($workspace,"'customer':m.sender_type==='manager'?'manager':'ai'")!==false&&strpos($workspace,'.msg.customer')!==false&&strpos($workspace,'.msg.ai')!==false&&strpos($workspace,'.msg.manager')!==false);
mw2Check('original transcript is rendered from manager detail messages',strpos($workspace,"api('detail',{conversation_id:S.current})")!==false&&strpos($workspace,'(d.messages||[]).forEach')!==false);
mw2Check('lead card keeps trip outside transcript',strpos($workspace,'<div class="sectionTitle">Поездка</div>')!==false&&strpos($workspace,"tripField('Вылет',trip.city)")!==false&&strpos($workspace,"tripField('Куда',trip.country)")!==false);
mw2Check('lead card keeps contact source and handoff outside transcript',strpos($workspace,'<div class="sectionTitle">Контакт</div>')!==false&&strpos($workspace,'<div class="sectionTitle">Источник и handoff</div>')!==false);
mw2Check('workspace exposes sales stage and tags independently',strpos($workspace,'id="leadStage"')!==false&&strpos($workspace,'id="leadTags"')!==false&&strpos($workspace,"pipe('set_stage'")!==false&&strpos($workspace,"pipe('set_tags'")!==false);
mw2Check('workspace has stage and tag inbox filters',strpos($workspace,'id="leadStageFilter"')!==false&&strpos($workspace,'id="leadTagFilter"')!==false&&strpos($workspace,'lead_stage_key:S.leadStageFilter')!==false&&strpos($workspace,'lead_tag_id:S.leadTagFilter')!==false);
mw2Check('workspace list shows business stage and tags',strpos($workspace,'c.lead_stage?.display_name')!==false&&strpos($workspace,'c.lead_tags')!==false&&strpos($workspace,'leadStageTag')!==false);
mw2Check('pipeline API uses existing manager session and csrf',strpos($api,"session_name('anytour_manager_panel')")!==false&&strpos($api,'pipelineRequireCsrf')!==false);
mw2Check('pipeline API gates conversation access through manager visibility',strpos($api,'ManagerConversationService::detail($conversationId,$managerId)')!==false);
mw2Check('pipeline mutations require owner or admin',strpos($api,'function pipelineCanEdit')!==false&&substr_count($api,"if(!$canEdit)pipelineOut(['ok'=>false,'error'=>'forbidden'],403);")===2&&strpos($api,"'can_edit_pipeline'=>$canEdit")!==false);
mw2Check('readonly UI reflects pipeline ownership',strpos($workspace,'can_edit_pipeline')!==false&&strpos($workspace,'Изменять этап и теги может ответственный менеджер или администратор.')!==false&&strpos($workspace,"${canEdit?'':'disabled'}")!==false);
mw2Check('pipeline API exposes filtered list action',strpos($api,"$action==='list'")!==false&&strpos($api,"(string)($data['lead_stage_key']??'')")!==false&&strpos($api,"(int)($data['lead_tag_id']??0)")!==false);
mw2Check('conversation service filters by business stage',strpos($conversations,"$where[]='c.lead_stage_key=?'")!==false&&strpos($conversations,'string $leadStageKey')!==false);
mw2Check('conversation service filters by business tag',strpos($conversations,'conversation_lead_tags clt_filter')!==false&&strpos($conversations,'int $leadTagId=0')!==false);
mw2Check('conversation rows batch-decorate pipeline metadata',strpos($pipeline,'public static function decorateConversationRows')!==false&&strpos($conversations,'SalesPipelineService::decorateConversationRows($rows)')!==false);
mw2Check('pipeline API keeps technical status read-only',strpos($api,"'technical_status'=>$conversation['status']")!==false&&strpos($api,'UPDATE conversations SET status')===false);
mw2Check('pipeline mutations delegate only to SalesPipelineService',strpos($api,'SalesPipelineService::setStage')!==false&&strpos($api,'SalesPipelineService::setTags')!==false);
mw2Check('workspace preserves existing manager lifecycle actions',strpos($workspace,"change('take')")!==false&&strpos($workspace,"change('release')")!==false&&strpos($workspace,"change('close')")!==false&&strpos($workspace,"change('reopen')")!==false);
mw2Check('workspace preserves text reply path',strpos($workspace,"api('send',{conversation_id:S.current,text})")!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
