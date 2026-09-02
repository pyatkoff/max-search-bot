<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$service=(string)file_get_contents($root.'/services/SalesPipelineService.php');
$leadCard=(string)file_get_contents($root.'/manager/assets/workspace-v2-lead-card.js');
$pipelineJs=(string)file_get_contents($root.'/manager/assets/workspace-v2-pipeline.js');
$passed=0;$failed=0;
function pitCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

$tagsStart=strpos($service,'public static function tagsForConversation');
$tagsEnd=strpos($service,'public static function outcomeForConversation');
$tagsMethod=$tagsStart===false?'':substr($service,$tagsStart,$tagsEnd===false?null:$tagsEnd-$tagsStart);
$setStart=strpos($service,'public static function setTags');
$setEnd=strpos($service,'public static function setOutcome');
$setMethod=$setStart===false?'':substr($service,$setStart,$setEnd===false?null:$setEnd-$setStart);
$validate=strpos($setMethod,'if(count($valid)!==count($tagIds))');
$delete=strpos($setMethod,'DELETE FROM conversation_lead_tags WHERE conversation_id=? AND tag_id IN (SELECT id FROM lead_tags WHERE is_active=1)');

pitCheck('conversation snapshot retains assigned inactive tag facts',strpos($tagsMethod,'t.is_active')!==false&&strpos($tagsMethod,'AND t.is_active=1')===false);
pitCheck('list projection retains assigned inactive tag facts',strpos($service,'SELECT ct.conversation_id,t.id,t.tag_key,t.display_name,t.color,t.sort_order,t.is_active FROM conversation_lead_tags')!==false&&strpos($service,'WHERE ct.conversation_id IN ({$in}) AND t.is_active=1')===false);
pitCheck('tag replacement mutates active assignments only',$delete!==false&&strpos($setMethod,"DELETE FROM conversation_lead_tags WHERE conversation_id=?')->execute")===false);
pitCheck('invalid requested tag set still fails before any mutation',$validate!==false&&$delete!==false&&$validate<$delete);
pitCheck('lead card exposes current inactive tags as checked read-only facts',strpos($leadCard,'function tagChoicesMarkup')!==false&&strpos($leadCard,"(неактивен)")!==false&&strpos($leadCard,'checked disabled')!==false&&strpos($leadCard,'t.is_active')!==false);
pitCheck('browser mutation payload captures only editable tags',strpos($pipelineJs,"document.querySelectorAll('#leadTags input:not(:disabled)')")!==false&&strpos($pipelineJs,'ids=inputs.filter(x=>x.checked)')!==false&&strpos($pipelineJs,'inputs.forEach(x=>x.disabled=true)')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
