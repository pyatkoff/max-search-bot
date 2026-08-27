<?php

declare(strict_types=1);

$migration011=(string)file_get_contents(dirname(__DIR__).'/migrations/011_sales_pipeline.sql');
$migration012=(string)file_get_contents(dirname(__DIR__).'/migrations/012_repair_sales_pipeline_schema.sql');
$runner=(string)file_get_contents(dirname(__DIR__).'/services/MigrationRunner.php');
$service=(string)file_get_contents(dirname(__DIR__).'/services/SalesPipelineService.php');
$passed=0;$failed=0;
function spCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

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
spCheck('service reads ordered active stages',strpos($service,'public static function stages')!==false && strpos($service,'ORDER BY sort_order,display_name,stage_key')!==false);
spCheck('service updates business stage only',strpos($service,"UPDATE conversations SET lead_stage_key=? WHERE id=?")!==false && strpos($service,'status=')===false);
spCheck('service replaces lead tags transactionally',strpos($service,'beginTransaction()')!==false && strpos($service,'DELETE FROM conversation_lead_tags WHERE conversation_id=?')!==false && strpos($service,'INSERT INTO conversation_lead_tags')!==false);
spCheck('conversation snapshot exposes stage and tags',strpos($service,"'stage'=>self::stageForConversation")!==false && strpos($service,"'tags'=>self::tagsForConversation")!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
