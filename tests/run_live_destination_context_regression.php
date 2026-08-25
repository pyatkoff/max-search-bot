<?php

declare(strict_types=1);

$source=(string)file_get_contents(dirname(__DIR__).'/handlers/AiMessageHandler.php');
$passed=0;$failed=0;
function ldcCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

ldcCheck('unresolved country is detected before repeating missing-field prompt',strpos($source,'$unresolvedDestination = in_array(\'country\',$missingNow,true)')!==false && strpos($source,'in_array(\'country\',$missingLocal,true)')!==false);
ldcCheck('simple unresolved destination does not return through repeated prompt branch',strpos($source,'if ($simpleLocal && !empty($missingLocal) && !$unresolvedDestination)')!==false);
$guardPos=strpos($source,'$unresolvedDestination =');
$shortAiPos=strpos($source,'ROUTE: SHORT_AI',$guardPos===false?0:$guardPos);
ldcCheck('unresolved destination falls through to SHORT_AI',$guardPos!==false && $shortAiPos!==false && $shortAiPos>$guardPos);
ldcCheck('ordinary resolved missing fields keep deterministic prompt path',strpos($source,'$missingLocal,')!==false && strpos($source,"['month_only'=>\$localMonthOnly]")!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
