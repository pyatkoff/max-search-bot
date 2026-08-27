<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$workflow=(string)@file_get_contents($root.'/.github/workflows/workspace-v2-visual-qa.yml');
$fixture=(string)@file_get_contents($root.'/tests/visual/workspace-v2-fixture.html');
$passed=0;$failed=0;
function vqCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
vqCheck('visual workflow exists',$workflow!=='');
vqCheck('visual fixture exists',$fixture!=='');
foreach(['390,844','430,932','768,1024','1440,1000'] as $viewport)vqCheck('captures viewport '.$viewport,strpos($workflow,"--viewport-size='".$viewport."'")!==false);
vqCheck('visual evidence uploads as artifact',strpos($workflow,'actions/upload-artifact@v4')!==false&&strpos($workflow,'visual-artifacts/*.png')!==false);
vqCheck('visual QA is isolated from production',strpos($workflow,'deploy')===false&&strpos($workflow,'DEPLOY_')===false&&strpos($workflow,'anytour.online')===false);
vqCheck('workflow only targets manager visual paths',strpos($workflow,"- 'manager/**'")!==false&&strpos($workflow,"- 'tests/visual/**'")!==false);
vqCheck('fixture uses production Workspace V2 styles',strpos($fixture,'../../manager/assets/workspace-v2.css')!==false&&strpos($fixture,'../../manager/assets/workspace-v2-tasks.css')!==false&&strpos($fixture,'../../manager/assets/workspace-v2-notifications.css')!==false);
vqCheck('fixture covers high-risk responsive states',strpos($fixture,'waitUrgent')!==false&&strpos($fixture,'waitWarn')!==false&&strpos($fixture,'nextTask overdue')!==false&&strpos($fixture,'conversationZone open')!==false&&strpos($fixture,'leadZone')!==false);
vqCheck('fixture has no real production endpoints',strpos($fixture,'anytour.online')===false&&strpos($fixture,'api.php')===false&&strpos($fixture,'pipeline-api.php')===false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
