<?php

declare(strict_types=1);

$migration011=(string)file_get_contents(dirname(__DIR__).'/migrations/011_sales_pipeline.sql');
$migration012=(string)file_get_contents(dirname(__DIR__).'/migrations/012_repair_sales_pipeline_schema.sql');
$migration013=(string)file_get_contents(dirname(__DIR__).'/migrations/013_repair_sales_pipeline_schema_no_result.sql');
$service=(string)file_get_contents(dirname(__DIR__).'/services/SalesPipelineService.php');
$passed=0;$failed=0;
function spCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

spCheck('pipeline catalog table exists',strpos($migration011,'CREATE TABLE IF NOT EXISTS lead_stages')!==false);
spCheck('technical status is not replaced by sales stage',strpos($migration011,'ADD COLUMN lead_stage_key')!==false && strpos($migration011,'CHANGE COLUMN status')===false && strpos($migration011,'MODIFY COLUMN status')===false);
spCheck('default new stage is explicit',strpos($migration011,"DEFAULT 'new'")!==false && strpos($migration011,"('new','Новый лид'")!==false);
spCheck('won and lost terminal stages are distinct',strpos($migration011,"('won','Продано'")!==false && strpos($migration011,"('lost','Закрыто без продажи'")!==false);
spCheck('applied migration 011 stays immutable',strpos($migration011,'information_schema.COLUMNS')===false && strpos($migration011,'ALTER TABLE conversations')!==false);
spCheck('historical repair 012 is preserved',strpos($migration012,'information_schema.COLUMNS')!==false && strpos($migration012,"'SELECT 1'")!==false);
spCheck('final repair checks existing column',strpos($migration013,'information_schema.COLUMNS')!==false && strpos($migration013,"COLUMN_NAME='lead_stage_key'")!==false && strpos($migration013,'PREPARE lead_stage_column_stmt_v2')!==false);
spCheck('final repair checks existing index',strpos($migration013,'information_schema.STATISTICS')!==false && strpos($migration013,"INDEX_NAME='idx_conversations_lead_stage'")!==false && strpos($migration013,'PREPARE lead_stage_index_stmt_v2')!==false);
spCheck('final repair no-op produces no result set',substr_count($migration013,"'DO 0'")===2 && strpos($migration013,"'SELECT 1'")===false);
spCheck('final repair recreates missing tag storage',strpos($migration013,'CREATE TABLE IF NOT EXISTS lead_tags')!==false && strpos($migration013,'CREATE TABLE IF NOT EXISTS conversation_lead_tags')!==false);
spCheck('tag catalog exists',strpos($migration011,'CREATE TABLE IF NOT EXISTS lead_tags')!==false);
spCheck('many-to-many conversation tags exist',strpos($migration011,'CREATE TABLE IF NOT EXISTS conversation_lead_tags')!==false && strpos($migration011,'PRIMARY KEY (conversation_id,tag_id)')!==false);
spCheck('service reads ordered active stages',strpos($service,'public static function stages')!==false && strpos($service,'ORDER BY sort_order,display_name,stage_key')!==false);
spCheck('service updates business stage only',strpos($service,"UPDATE conversations SET lead_stage_key=? WHERE id=?")!==false && strpos($service,'status=')===false);
spCheck('service replaces lead tags transactionally',strpos($service,'beginTransaction()')!==false && strpos($service,'DELETE FROM conversation_lead_tags WHERE conversation_id=?')!==false && strpos($service,'INSERT INTO conversation_lead_tags')!==false);
spCheck('conversation snapshot exposes stage and tags',strpos($service,"'stage'=>self::stageForConversation")!==false && strpos($service,"'tags'=>self::tagsForConversation")!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
