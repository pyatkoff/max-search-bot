<?php

declare(strict_types=1);

$service=(string)file_get_contents(dirname(__DIR__).'/services/SalesPipelineService.php');
$api=(string)file_get_contents(dirname(__DIR__).'/manager/pipeline-api.php');
$passed=0;$failed=0;
function ptaCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$start=strpos($service,'public static function setTags');
$end=strpos($service,'public static function setOutcome');
$method=$start===false?'':substr($service,$start,$end===false?null:$end-$start);
$validate=strpos($method,'if(count($valid)!==count($tagIds))');
$rollback=strpos($method,'$pdo->rollBack();return false;');
$delete=strpos($method,"DELETE FROM conversation_lead_tags WHERE conversation_id=?");
$insert=strpos($method,'INSERT INTO conversation_lead_tags');
$commit=strpos($method,'$pdo->commit();return true;');

ptaCheck('setTags remains the single per-lead tag mutation owner',$method!==''&&$delete!==false&&$insert!==false);
ptaCheck('invalid or inactive requested tags fail closed before deleting current tags',$validate!==false&&$rollback!==false&&$delete!==false&&$validate<$delete&&$rollback<$delete);
ptaCheck('valid complete tag sets still replace atomically',$delete!==false&&$insert!==false&&$commit!==false&&$delete<$insert&&$insert<$commit);
ptaCheck('empty tag selection can still intentionally clear tags',strpos($method,'if($tagIds){')!==false&&$delete!==false);
ptaCheck('pipeline API surfaces rejected tag mutation as conflict without a false snapshot',strpos($api,"\$action==='set_tags'")!==false&&strpos($api,"'pipeline'=>\$ok?SalesPipelineService::conversationSnapshot(\$id):null")!==false&&strpos($api,'$ok?200:409')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
