<?php

declare(strict_types=1);

$css=(string)file_get_contents(dirname(__DIR__).'/manager/assets/workspace-v2-inbox.css');
$passed=0;$failed=0;
function overdueContrastCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;}else{echo "FAIL  {$name}\n";$failed++;}}

overdueContrastCheck('overdue queue section uses danger surface and edge signal',strpos($css,'.taskQueueSection.overdue{background:var(--ws-danger-soft,#fdecec);color:var(--ws-danger,#a33a3a);box-shadow:inset 3px 0 0 var(--ws-danger,#a33a3a)}')!==false);
overdueContrastCheck('today queue section uses warning surface and edge signal',strpos($css,'.taskQueueSection.today{background:var(--ws-warning-soft,#fff4d2);color:var(--ws-warning,#8a6400);box-shadow:inset 3px 0 0 var(--ws-warning,#8a6400)}')!==false);
overdueContrastCheck('overdue task chip remains visually dominant',strpos($css,'.leadTaskCompact.overdue{background:var(--ws-danger-soft,#fdecec);color:var(--ws-danger,#a33a3a);font-weight:800;box-shadow:inset 0 0 0 1px rgba(163,58,58,.22)}')!==false);
overdueContrastCheck('today task chip gains matching boundary without changing layout',strpos($css,'.leadTaskCompact.today{background:var(--ws-warning-soft,#fff4d2);color:var(--ws-warning,#8a6400);font-weight:750;box-shadow:inset 0 0 0 1px rgba(138,100,0,.18)}')!==false);
overdueContrastCheck('mobile task queue compact rule is preserved',strpos($css,'@media(max-width:520px)')!==false&&strpos($css,'.taskQueueSection{padding:6px 7px;font-size:9px}')!==false);

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
exit($failed>0?1:0);
