<?php

declare(strict_types=1);

$workspace=(string)file_get_contents(dirname(__DIR__).'/manager/workspace-v2.php');
$workspaceCss=(string)file_get_contents(dirname(__DIR__).'/manager/assets/workspace-v2.css');
$workspaceJs=(string)file_get_contents(dirname(__DIR__).'/manager/assets/workspace-v2.js');
$ui=$workspace."\n".$workspaceCss."\n".$workspaceJs;
$api=(string)file_get_contents(dirname(__DIR__).'/manager/pipeline-api.php');
$conversations=(string)file_get_contents(dirname(__DIR__).'/services/ManagerConversationService.php');
$pipeline=(string)file_get_contents(dirname(__DIR__).'/services/SalesPipelineService.php');
$passed=0;$failed=0;
function mw2Check(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

mw2Check('workspace loads extracted assets',strpos($workspace,'assets/workspace-v2.css')!==false&&strpos($workspace,'assets/workspace-v2.js')!==false&&strpos($workspace,'<style>')===false&&strpos($workspace,'<script>')===false);
mw2Check('workspace has three-zone desktop structure',strpos($ui,'inboxZone')!==false&&strpos($ui,'conversationZone')!==false&&strpos($ui,'leadZone')!==false&&strpos($ui,'grid-template-columns:320px minmax(420px,1fr) 340px')!==false);
mw2Check('workspace has mobile adaptation',strpos($ui,'@media(max-width:900px)')!==false&&strpos($ui,'.conversationZone.open')!==false&&strpos($ui,'.leadZone.open')!==false);
mw2Check('transcript visually separates customer AI and manager',strpos($ui,"'customer':m.sender_type==='manager'?'manager':'ai'")!==false&&strpos($ui,'.msg.customer')!==false&&strpos($ui,'.msg.ai')!==false&&strpos($ui,'.msg.manager')!==false);
mw2Check('original transcript is rendered from manager detail messages',strpos($ui,"api('detail',{conversation_id:S.current})")!==false&&strpos($ui,'(d.messages||[]).forEach')!==false);
mw2Check('lead card keeps trip outside transcript',strpos($ui,'<div class="sectionTitle">Поездка</div>')!==false&&strpos($ui,"tripField('Вылет',trip.city)")!==false&&strpos($ui,"tripField('Куда',trip.country)")!==false);
mw2Check('lead card keeps contact source and handoff outside transcript',strpos($ui,'<div class="sectionTitle">Контакт</div>')!==false&&strpos($ui,'<div class="sectionTitle">Источник и handoff</div>')!==false);
mw2Check('workspace exposes sales stage and tags independently',strpos($ui,'id="leadStage"')!==false&&strpos($ui,'id="leadTags"')!==false&&strpos($ui,"pipe('set_stage'")!==false&&strpos($ui,"pipe('set_tags'")!==false);
mw2Check('workspace has stage and tag inbox filters',strpos($ui,'id="leadStageFilter"')!==false&&strpos($ui,'id="leadTagFilter"')!==false&&strpos($ui,'lead_stage_key:S.leadStageFilter')!==false&&strpos($ui,'lead_tag_id:S.leadTagFilter')!==false);
mw2Check('workspace list shows business stage and tags',strpos($ui,'c.lead_stage?.display_name')!==false&&strpos($ui,'c.lead_tags')!==false&&strpos($ui,'leadStageTag')!==false);
mw2Check('pipeline API uses existing manager session and csrf',strpos($api,"session_name('anytour_manager_panel')")!==false&&strpos($api,'pipelineRequireCsrf')!==false);
mw2Check('pipeline API gates conversation access through manager visibility',strpos($api,'ManagerConversationService::detail(')!==false&&strpos($api,'pipelineConversation(')!==false);
mw2Check('pipeline mutations require owner or admin',strpos($api,'function pipelineCanEdit')!==false&&substr_count($api,"error'=>'forbidden")>=3&&strpos($api,"'can_edit_pipeline'=>\$can")!==false);
mw2Check('readonly UI reflects pipeline ownership',strpos($ui,'can_edit_pipeline')!==false&&strpos($ui,'ответственный менеджер или администратор')!==false&&strpos($ui,"canEdit?'':'disabled'")!==false);
mw2Check('pipeline API exposes filtered list action',strpos($api,"\$action==='list'")!==false&&strpos($api,"(string)(\$data['lead_stage_key']??'')")!==false&&strpos($api,"(int)(\$data['lead_tag_id']??0)")!==false);
mw2Check('conversation service filters by business stage',strpos($conversations,"\$where[]='c.lead_stage_key=?'")!==false&&strpos($conversations,'string $leadStageKey')!==false);
mw2Check('conversation service filters by business tag',strpos($conversations,'conversation_lead_tags clt_filter')!==false&&strpos($conversations,'int $leadTagId=0')!==false);
mw2Check('conversation rows batch-decorate pipeline metadata',strpos($pipeline,'public static function decorateConversationRows')!==false&&strpos($conversations,'SalesPipelineService::decorateConversationRows($rows)')!==false);
mw2Check('pipeline API keeps technical status read-only',strpos($api,"'technical_status'=>\$c['status']")!==false&&strpos($api,'UPDATE conversations SET status')===false);
mw2Check('pipeline mutations delegate only to SalesPipelineService',strpos($api,'SalesPipelineService::setStage')!==false&&strpos($api,'SalesPipelineService::setTags')!==false&&strpos($api,'SalesPipelineService::setOutcome')!==false);
mw2Check('workspace preserves existing manager lifecycle actions',strpos($ui,"change('take')")!==false&&strpos($ui,"change('release')")!==false&&strpos($ui,"change('close')")!==false&&strpos($ui,"change('reopen')")!==false);
mw2Check('workspace preserves text reply path',strpos($ui,"api('send',{conversation_id:S.current,text})")!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
