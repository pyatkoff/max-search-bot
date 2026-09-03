<?php

declare(strict_types=1);

$migration011=(string)file_get_contents(dirname(__DIR__).'/migrations/011_sales_pipeline.sql');
$migration012=(string)file_get_contents(dirname(__DIR__).'/migrations/012_repair_sales_pipeline_schema.sql');
$migration015=(string)file_get_contents(dirname(__DIR__).'/migrations/015_lead_stage_history.sql');
$runner=(string)file_get_contents(dirname(__DIR__).'/services/MigrationRunner.php');
$service=(string)file_get_contents(dirname(__DIR__).'/services/SalesPipelineService.php');
$metrikaGoals=(string)file_get_contents(dirname(__DIR__).'/services/MetrikaConversionGoalService.php');
$managerOutbound=(string)file_get_contents(dirname(__DIR__).'/services/ManagerOutboundService.php');
$incoming=(string)file_get_contents(dirname(__DIR__).'/services/IncomingUpdateDispatcher.php');
$maxSearch=(string)file_get_contents(dirname(__DIR__).'/maxsearchclass.php');
$managerAction=(string)file_get_contents(dirname(__DIR__).'/actions/ManagerAction.php');
$managerCallback=(string)file_get_contents(dirname(__DIR__).'/actions/callbacks/ManagerCallbackAction.php');
$catalogAdmin=(string)file_get_contents(dirname(__DIR__).'/services/SalesPipelineCatalogAdminService.php');
$pipelineApi=(string)file_get_contents(dirname(__DIR__).'/manager/pipeline-api.php');
$pipelineAdmin=(string)file_get_contents(dirname(__DIR__).'/manager/pipeline-admin.php');
$pipelineAdminJs=(string)file_get_contents(dirname(__DIR__).'/manager/assets/pipeline-admin.js');
$pipelineAdminCss=(string)file_get_contents(dirname(__DIR__).'/manager/assets/pipeline-admin.css');
$managerHttpClient=(string)file_get_contents(dirname(__DIR__).'/manager/assets/manager-http-client.js');
$passed=0;$failed=0;
function spCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

require_once dirname(__DIR__).'/services/MetrikaConversionGoalService.php';

spCheck('pipeline catalog table exists',strpos($migration011,'CREATE TABLE IF NOT EXISTS lead_stages')!==false);
spCheck('technical status is not replaced by sales stage',strpos($migration011,'ADD COLUMN lead_stage_key')!==false && strpos($migration011,'CHANGE COLUMN status')===false && strpos($migration011,'MODIFY COLUMN status')===false);
spCheck('default new stage is explicit',strpos($migration011,"DEFAULT 'new'")!==false && strpos($migration011,"('new','Новый лид'")!==false);
spCheck('won and lost terminal stages are distinct',strpos($migration011,"('won','Продано'")!==false && strpos($migration011,"('lost','Закрыто без продажи'")!==false);
spCheck('applied migration 011 stays immutable',strpos($migration011,'information_schema.COLUMNS')===false && strpos($migration011,'ALTER TABLE conversations')!==false);
spCheck('pending repair checks existing column',strpos($migration012,'information_schema.COLUMNS')!==false && strpos($migration012,"COLUMN_NAME='lead_stage_key'")!==false && strpos($migration012,'PREPARE lead_stage_column_stmt')!==false);
spCheck('pending repair checks existing index',strpos($migration012,'information_schema.STATISTICS')!==false && strpos($migration012,"INDEX_NAME='idx_conversations_lead_stage'")!==false && strpos($migration012,'PREPARE lead_stage_index_stmt')!==false);
spCheck('pending repair no-op produces no result set',substr_count($migration012,"'DO 0'")===2 && strpos($migration012,"'SELECT 1'")===false);
spCheck('pending repair recreates missing tag storage',strpos($migration012,'CREATE TABLE IF NOT EXISTS lead_tags')!==false && strpos($migration012,'CREATE TABLE IF NOT EXISTS conversation_lead_tags')!==false);
$normalApplyStart=strpos($runner,'$started = microtime(true);');
$normalApply=$normalApplyStart===false?'':substr($runner,$normalApplyStart);
$execPos=strpos($normalApply,'$this->pdo->exec($statement);');
$recordPos=strpos($normalApply,"INSERT INTO schema_migrations (version,checksum,baseline,execution_ms)");
spCheck('migration runner records only after statements succeed',$normalApply!=='' && $execPos!==false && $recordPos!==false && $execPos<$recordPos);
spCheck('tag catalog exists',strpos($migration011,'CREATE TABLE IF NOT EXISTS lead_tags')!==false);
spCheck('many-to-many conversation tags exist',strpos($migration011,'CREATE TABLE IF NOT EXISTS conversation_lead_tags')!==false && strpos($migration011,'PRIMARY KEY (conversation_id,tag_id)')!==false);
spCheck('stage history is forward-only append storage',strpos($migration015,'CREATE TABLE IF NOT EXISTS lead_stage_history')!==false && strpos($migration015,'ALTER TABLE conversations')===false && strpos($migration015,'UPDATE conversations')===false);
spCheck('stage history captures transition actor and time',strpos($migration015,'from_stage_key')!==false && strpos($migration015,'to_stage_key')!==false && strpos($migration015,'changed_by_manager_id')!==false && strpos($migration015,'created_at')!==false);
spCheck('service reads ordered active stages',strpos($service,'public static function stages')!==false && strpos($service,'ORDER BY sort_order,display_name,stage_key')!==false);
spCheck('service updates business stage only',strpos($service,"UPDATE conversations SET lead_stage_key=? WHERE id=?")!==false && strpos($service,'status=')===false);
$setStageStart=strpos($service,'public static function setStage');
$setTagsStart=strpos($service,'public static function setTags');
$setStage=$setStageStart===false?'':substr($service,$setStageStart,($setTagsStart!==false?$setTagsStart:$setStageStart)-$setStageStart);
spCheck('stage transition is transactional with immutable history',$setStage!=='' && strpos($setStage,'beginTransaction()')!==false && strpos($setStage,'INSERT INTO lead_stage_history')!==false && strpos($setStage,'commit()')!==false);
spCheck('same-stage write is idempotent without duplicate history',strpos($setStage,'if($current===$key)return true;')!==false && strpos($setStage,'if($current===$key)return true;')<strpos($setStage,'INSERT INTO lead_stage_history'));
spCheck('pipeline stage API attributes manager actor',strpos($pipelineApi,'SalesPipelineService::setStage($id,(string)($data[\'stage_key\']??\'\'),(int)$m[\'id\'])')!==false);
spCheck('service replaces lead tags transactionally',strpos($service,'beginTransaction()')!==false && strpos($service,'DELETE FROM conversation_lead_tags WHERE conversation_id=?')!==false && strpos($service,'INSERT INTO conversation_lead_tags')!==false);
spCheck('conversation snapshot exposes stage tags and immutable history',strpos($service,"'stage'=>self::stageForConversation")!==false && strpos($service,"'stage_history'=>self::stageHistoryForConversation")!==false && strpos($service,"'tags'=>self::tagsForConversation")!==false);
spCheck('pipeline catalog admin is role gated',substr_count($pipelineApi,'ManagerHttp::requireAdmin($m)')>=3 && strpos($pipelineApi,"$action==='admin_catalog'")!==false && strpos($pipelineApi,"$action==='save_stage'")!==false && strpos($pipelineApi,"$action==='save_tag'")!==false);
spCheck('catalog admin owns stage and tag writes',strpos($catalogAdmin,'UPDATE lead_stages')!==false && strpos($catalogAdmin,'UPDATE lead_tags')!==false && strpos($catalogAdmin,'AuditLogService::record')!==false);
spCheck('won stages are terminal by invariant',strpos($catalogAdmin,'if($won)$terminal=1;')!==false);
spCheck('catalog snapshot exposes stage usage counts',strpos($catalogAdmin,"\$stage['usage_count']")!==false && strpos($catalogAdmin,'SELECT lead_stage_key,COUNT(*) AS usage_count FROM conversations')!==false);
spCheck('active stage with leads cannot be deactivated',strpos($catalogAdmin,"'error'=>'stage_in_use'")!==false && strpos($catalogAdmin,'stageUsageCount($key)')!==false && strpos($catalogAdmin,'if($usage>0)return')!==false);
spCheck('catalog snapshot exposes tag usage counts',strpos($catalogAdmin,"\$tag['usage_count']")!==false && strpos($catalogAdmin,'SELECT tag_id,COUNT(*) AS usage_count FROM conversation_lead_tags')!==false);
spCheck('active assigned tag cannot be deactivated',strpos($catalogAdmin,"'error'=>'tag_in_use'")!==false && strpos($catalogAdmin,'tagUsageCount($id)')!==false && strpos($catalogAdmin,'SELECT COUNT(*) FROM conversation_lead_tags WHERE tag_id=?')!==false);
spCheck('pipeline admin explains stage usage and safe deactivation',strpos($pipelineAdminJs,'leadCountLabel')!==false && strpos($pipelineAdminJs,"r.error==='stage_in_use'")!==false && strpos($pipelineAdminJs,'Сначала перенесите лиды в другой этап')!==false);
spCheck('pipeline admin explains tag usage and safe deactivation',strpos($pipelineAdminJs,"r.error==='tag_in_use'")!==false && strpos($pipelineAdminJs,'Сначала снимите тег с этих лидов')!==false && strpos($pipelineAdminJs,"$('tagForm').dataset.usageCount")!==false);
spCheck('pipeline admin explicitly separates business and technical state',strpos($pipelineAdmin,'Технические состояния диалога здесь не меняются')!==false);
spCheck('pipeline admin uses focused assets',strpos($pipelineAdmin,'pipeline-admin.js')!==false && strpos($pipelineAdmin,'pipeline-admin.css')!==false);
spCheck('pipeline admin reuses shared HTTP client',strpos($pipelineAdminJs,"ManagerHttpClient.request(action,data,S.csrf,'pipeline-api.php')")!==false && strpos($managerHttpClient,"endpoint='api.php'")!==false && strpos($managerHttpClient,'fetch(endpoint')!==false);
spCheck('pipeline admin has mobile responsive rules',strpos($pipelineAdminCss,'@media(max-width:760px)')!==false && strpos($pipelineAdminCss,'@media(max-width:460px)')!==false);
spCheck('existing stage key is immutable in editor',strpos($pipelineAdminJs,"$('stageKey').readOnly=true")!==false);

spCheck('Metrika business milestones use the approved goal names',
    strpos($metrikaGoals,"'working' => 'max_lead_working'")!==false
    && strpos($metrikaGoals,"'offer_sent' => 'max_offer_sent'")!==false
    && strpos($metrikaGoals,"'booking' => 'max_booking'")!==false
    && strpos($metrikaGoals,"'won' => 'max_sale'")!==false
    && strpos($metrikaGoals,"'max_manager_reply'")!==false
    && strpos($metrikaGoals,"'max_customer_reply_after_manager'")!==false
    && strpos($metrikaGoals,"'max_bot_activity_start'")!==false
);
spCheck('bot activity goal counts tourist messages callbacks and contacts on MAX and Telegram only',
    MetrikaConversionGoalService::isCustomerActivity('max','message')
    && MetrikaConversionGoalService::isCustomerActivity('max','callback')
    && MetrikaConversionGoalService::isCustomerActivity('telegram','contact')
    && !MetrikaConversionGoalService::isCustomerActivity('max','bot_started')
    && !MetrikaConversionGoalService::isCustomerActivity('website','message')
);
spCheck('first bot activity is evaluated after attribution sync and before routing branches',
    strpos($incoming,'MetrikaConversionGoalService::customerActivity($platform,$chatId,$type);')!==false
    && strpos($incoming,'ConversationAttributionService::syncByChat($platform,$chatId);') < strpos($incoming,'MetrikaConversionGoalService::customerActivity($platform,$chatId,$type);')
    && strpos($incoming,'MetrikaConversionGoalService::customerActivity($platform,$chatId,$type);') < strpos($incoming,'SourceHandlingService::handle($incoming)')
);
spCheck('Metrika conversion owner requires real handoff evidence and dedupes by conversation event',
    strpos($metrikaGoals,"hasEvent(\$conversationId, 'waiting_manager')")!==false
    && strpos($metrikaGoals,"hasEvent(\$conversationId, 'manager_message')")!==false
    && strpos($metrikaGoals,"'metrika_' . \$target")!==false
    && strpos($metrikaGoals,'SELECT 1 FROM conversation_events WHERE conversation_id=? AND event_type=? LIMIT 1')!==false
);
spCheck('successful manager text and media sends trigger manager-reply goal after delivery',
    substr_count($managerOutbound,'MetrikaConversionGoalService::managerReply($conversationId);')===2
    && strpos($managerOutbound,"ConversationControlService::event(\$conversationId,'manager_message'")!==false
);
spCheck('tourist reply goal is evaluated after attribution sync only for manager-owned non-callback inbound',
    strpos($incoming,'ConversationAttributionService::syncByChat($platform,$chatId);')!==false
    && strpos($incoming,"\$status === 'manager' && \$type !== 'callback'")!==false
    && strpos($incoming,'MetrikaConversionGoalService::customerReplyAfterManager((int)$ownership[\'id\']);')!==false
    && strpos($incoming,'ConversationAttributionService::syncByChat($platform,$chatId);') < strpos($incoming,'MetrikaConversionGoalService::customerReplyAfterManager')
);
spCheck('sales stage and won outcome trigger Metrika milestones without repurposing technical status',
    strpos($service,'MetrikaConversionGoalService::salesStage($id,$key);')!==false
    && strpos($service,"if(\$ok&&\$outcome==='won')MetrikaConversionGoalService::saleOutcome(\$id,\$outcome);")!==false
    && strpos($service,'UPDATE conversations SET status')===false
);
spCheck('existing canonical Metrika transport and manager-request goal remain unchanged',
    strpos($metrikaGoals,'MaxSearchApi::queueMetrikaGoal')!==false
    && strpos($maxSearch,'public static function queueMetrikaGoal')!==false
    && strpos($maxSearch,"hash('sha256',\$yclid.'|'.\$target)")!==false
    && strpos($maxSearch,"['россия','абхазия']")!==false
    && strpos($managerAction,"queueMetrikaGoal(\$chatId, 'max_manager_request')")!==false
    && strpos($managerCallback,"'max_manager_request'")!==false
);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
