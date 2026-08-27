<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2.css');

$passed=0;$failed=0;
function urgencyCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

urgencyCheck('wait urgency keeps agreed five minute SLA threshold',strpos($inbox,"seconds>=300")!==false&&strpos($inbox,"'urgent'")!==false&&strpos($inbox,"Срочно · 5+ мин")!==false);
urgencyCheck('wait urgency provides pre-SLA warning without changing routing',strpos($inbox,"seconds>=180")!==false&&strpos($inbox,"Скоро 5 мин")!==false&&strpos($inbox,'waitWarn')!==false&&strpos($inbox,'waitUrgent')!==false);
urgencyCheck('urgency derives only from awaiting first manager reply',strpos($inbox,"if(!waiting)return''")!==false&&strpos($inbox,'c.awaiting_first_reply')!==false&&strpos($inbox,'c.wait_age_seconds')!==false);
urgencyCheck('urgency remains presentation-only',strpos($inbox,"pipe('list'")!==false&&strpos($inbox,"pipe('take'")===false&&strpos($inbox,"pipe('release'")===false&&strpos($inbox,"is_working")===false);
urgencyCheck('urgency states have desktop and mobile-safe visual treatment',strpos($css,'.leadItem.waitWarn')!==false&&strpos($css,'.leadItem.waitUrgent')!==false&&strpos($css,'.waitSignal.warn')!==false&&strpos($css,'.waitSignal.urgent')!==false&&strpos($css,'@media(max-width:520px)')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
