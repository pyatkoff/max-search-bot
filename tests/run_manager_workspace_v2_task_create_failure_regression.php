<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$js=(string)file_get_contents($root.'/manager/assets/workspace-v2-tasks.js');
$passed=0;$failed=0;
function tcCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

tcCheck('task create catches rejected async mutations',strpos($js,"catch(e){setStatus('Не удалось добавить задачу','error')}")!==false);
tcCheck('task create keeps false-result failure inline',substr_count($js,"setStatus('Не удалось добавить задачу','error')")>=2);
tcCheck('task create always restores submit controls',strpos($js,"finally{creating=false;if(add.isConnected){add.disabled=false;add.textContent='Добавить задачу'}}")!==false);
tcCheck('task create failure does not clear entered title or deadline',strpos($js,"titleEl.value=''")===false&&strpos($js,"dueEl.value=''")===false);
tcCheck('task create remains duplicate-submit guarded',strpos($js,'if(creating)return')!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
