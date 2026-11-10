<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$inbox=(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.js');
$css=(string)file_get_contents($root.'/manager/assets/workspace-v2.css')."\n".(string)file_get_contents($root.'/manager/assets/workspace-v2-inbox.css');

$passed=0;$failed=0;
function urgencyCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

urgencyCheck('wait urgency keeps agreed five minute SLA threshold',strpos($inbox,"seconds>=300")!==false&&strpos($inbox,"return'urgent'")!==false&&strpos($inbox,"urgency==='urgent'?'Срочно · '")!==false);
urgencyCheck('wait urgency provides pre-SLA warning without changing routing',strpos($inbox,"seconds>=180")!==false&&strpos($inbox,"return'warn'")!==false&&strpos($inbox,"urgency==='warn'?'Скоро · '")!==false&&strpos($inbox,'waitWarn')!==false&&strpos($inbox,'waitUrgent')!==false);
urgencyCheck('urgency reads current waiting age and retains first-reply fallback for old rows',strpos($inbox,'function replyWaitMeta(c)')!==false&&strpos($inbox,'reply_wait_age_seconds')!==false&&strpos($inbox,'reply_wait_time_known===true')!==false&&strpos($inbox,'c?.awaiting_first_reply')!==false&&strpos($inbox,'c.wait_age_seconds')!==false);
urgencyCheck('urgency remains presentation-only',strpos($inbox,"pipe('list'")!==false&&strpos($inbox,"pipe('take'")===false&&strpos($inbox,"pipe('release'")===false&&strpos($inbox,"is_working")===false);
urgencyCheck('urgency states have desktop and mobile-safe visual treatment',strpos($css,'.leadItem.waitWarn')!==false&&strpos($css,'.leadItem.waitUrgent')!==false&&strpos($css,'.leadWaitCompact.warn')!==false&&strpos($css,'.leadWaitCompact.urgent')!==false&&strpos($css,'@media(max-width:520px)')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
