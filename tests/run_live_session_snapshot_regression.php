<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$source=(string)file_get_contents($base.'/tools/live_session_snapshot.php');
$passed=0;$failed=0;
function lssCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

lssCheck('flagged sessions expose bounded message evidence',strpos($source,"$session['message_tail']=liveDiagnosticMessageTail($messages)")!==false);
lssCheck('message evidence is capped to recent tail',strpos($source,'array_slice($messages,-max(1,$limit))')!==false && strpos($source,'int $limit=24')!==false);
lssCheck('message text is compacted and truncated',strpos($source,"mb_strlen($text)>280")!==false && strpos($source,"mb_substr($text,0,277).'...'")!==false);
lssCheck('unflagged sessions do not receive message evidence',strpos($source,"if(!empty($session['flags']))")!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
