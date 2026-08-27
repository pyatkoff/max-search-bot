<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$files=['AGENTS.md','docs/PRODUCT.md','docs/AUTOPILOT.md','docs/OPERATIONS.md','docs/REPO_MAP.md','docs/ARCHITECTURE.md'];
$failed=0;$passed=0;
function contractCheck(string $name,bool $ok):void{global$failed,$passed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
foreach($files as $path)contractCheck("exists {$path}",is_file($root.'/'.$path)&&filesize($root.'/'.$path)>0);
$agents=(string)@file_get_contents($root.'/AGENTS.md');
$product=(string)@file_get_contents($root.'/docs/PRODUCT.md');
$autopilot=(string)@file_get_contents($root.'/docs/AUTOPILOT.md');
$ops=(string)@file_get_contents($root.'/docs/OPERATIONS.md');
$map=(string)@file_get_contents($root.'/docs/REPO_MAP.md');
contractCheck('scope forbids neighbouring project edits',strpos($agents,'Work only inside `pyatkoff/max-search-bot`')!==false&&strpos($map,'Explicitly out of scope')!==false);
contractCheck('metrika and lead mechanism are protected',strpos($agents,'Yandex Metrica counters, goals')!==false&&strpos($agents,'existing lead-sending destination/mechanism')!==false);
contractCheck('live conversations precede roadmap cleanup',strpos($agents,'fresh live conversations')!==false&&strpos($autopilot,'Inspect fresh live conversations first')!==false);
contractCheck('priority keeps UX above roadmap refactor',strpos($agents,'dialogue, search, manager-workspace or responsive UX friction')!==false&&strpos($agents,'roadmap product work')!==false);
contractCheck('handoff keeps 10-20 and five minute fallback',strpos($product,'10:00–20:00')!==false&&strpos($product,'after 5 minutes without a manager reply')!==false);
contractCheck('manager shift state cannot be auto-corrected',strpos($agents,'operator-controlled manager `is_working` state')!==false&&strpos($ops,'Never auto-enable a manager')!==false);
contractCheck('required CI remains merge gate',strpos($agents,'merge only after required CI is green')!==false&&strpos($autopilot,'Required PR CI must be green')!==false);
contractCheck('production completion requires diagnostics',strpos($ops,'production diagnostics transfer')!==false&&strpos($autopilot,'production diagnostics download')!==false);
contractCheck('canonical snapshots are documented',strpos($ops,'tools/production_snapshot.php')!==false&&strpos($ops,'tools/live_session_snapshot.php')!==false);
contractCheck('visual QA baseline is explicit',strpos($agents,'390, 430, 768 and 1440 CSS px')!==false);
contractCheck('roadmap is not permanent knowledge store',strpos($agents,'issue #55 — current roadmap/checkpoints')!==false&&strpos($autopilot,'Durable rules belong in repository docs')!==false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
