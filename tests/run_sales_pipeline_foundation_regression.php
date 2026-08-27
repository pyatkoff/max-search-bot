<?php

declare(strict_types=1);

$migration=(string)file_get_contents(dirname(__DIR__).'/migrations/011_sales_pipeline.sql');
$service=(string)file_get_contents(dirname(__DIR__).'/services/SalesPipelineService.php');
$passed=0;$failed=0;
function spCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

spCheck('pipeline catalog table exists',strpos($migration,'CREATE TABLE IF NOT EXISTS lead_stages')!==false);
spCheck('technical status is not replaced by sales stage',strpos($migration,'ADD COLUMN lead_stage_key')!==false && strpos($migration,'CHANGE COLUMN status')===false && strpos($migration,'MODIFY COLUMN status')===false);
spCheck('default new stage is explicit',strpos($migration,"DEFAULT ''new''")!==false && strpos($migration,"('new','Новый лид'")!==false);
spCheck('won and lost terminal stages are distinct',strpos($migration,"('won','Продано'")!==false && strpos($migration,"('lost','Закрыто без продажи'")!==false);
spCheck('pipeline column migration is retry safe',strpos($migration,"information_schema.COLUMNS")!==false && strpos($migration,"COLUMN_NAME='lead_stage_key'")!==false && strpos($migration,'PREPARE lead_stage_column_stmt')!==false);
spCheck('pipeline index migration is retry safe',strpos($migration,'information_schema.STATISTICS')!==false && strpos($migration,"INDEX_NAME='idx_conversations_lead_stage'")!==false && strpos($migration,'PREPARE lead_stage_index_stmt')!==false);
spCheck('column and index are applied independently',strpos($migration,'DEALLOCATE PREPARE lead_stage_column_stmt')!==false && strpos($migration,'PREPARE lead_stage_index_stmt')!==false);
spCheck('tag catalog exists',strpos($migration,'CREATE TABLE IF NOT EXISTS lead_tags')!==false);
spCheck('many-to-many conversation tags exist',strpos($migration,'CREATE TABLE IF NOT EXISTS conversation_lead_tags')!==false && strpos($migration,'PRIMARY KEY (conversation_id,tag_id)')!==false);
spCheck('service reads ordered active stages',strpos($service,'public static function stages')!==false && strpos($service,'ORDER BY sort_order,display_name,stage_key')!==false);
spCheck('service updates business stage only',strpos($service,"UPDATE conversations SET lead_stage_key=? WHERE id=?")!==false && strpos($service,'status=')===false);
spCheck('service replaces lead tags transactionally',strpos($service,'beginTransaction()')!==false && strpos($service,'DELETE FROM conversation_lead_tags WHERE conversation_id=?')!==false && strpos($service,'INSERT INTO conversation_lead_tags')!==false);
spCheck('conversation snapshot exposes stage and tags',strpos($service,"'stage'=>self::stageForConversation")!==false && strpos($service,"'tags'=>self::tagsForConversation")!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
