<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$workflow=(string)@file_get_contents($root.'/.github/workflows/workspace-v2-visual-qa.yml');
$fixture=(string)@file_get_contents($root.'/tests/visual/workspace-v2-fixture.html');
$kanbanFixture=(string)@file_get_contents($root.'/tests/visual/workspace-v2-kanban-fixture.html');
$passed=0;$failed=0;
function vqCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}
vqCheck('visual workflow exists',$workflow!=='');
vqCheck('visual fixture exists',$fixture!=='');
vqCheck('kanban visual fixture exists',$kanbanFixture!=='');
foreach(['390,844','430,932','768,1024','1440,1000'] as $viewport)vqCheck('captures viewport '.$viewport,strpos($workflow,"--viewport-size='".$viewport."'")!==false);
vqCheck('captures mobile Inbox and secondary zones',strpos($workflow,'390-inbox.png')!==false&&strpos($workflow,'390-conversation.png')!==false&&strpos($workflow,'430-lead.png')!==false&&strpos($workflow,'768-conversation.png')!==false);
vqCheck('captures production-like long chat on mobile and desktop',strpos($workflow,'390-conversation-stress.png')!==false&&strpos($workflow,'1440-conversation-stress.png')!==false&&substr_count($workflow,'stress=chat')>=2);
vqCheck('long chat fixture includes repeated history and media',strpos($fixture,"stress==='chat'")!==false&&strpos($fixture,'round<3')!==false&&strpos($fixture,'class=\"attachments\"')!==false&&strpos($fixture,'hotel photo')!==false);
vqCheck('captures desktop and mobile kanban',strpos($workflow,'390-kanban.png')!==false&&strpos($workflow,'1440-kanban.png')!==false&&strpos($workflow,'workspace-v2-kanban-fixture.html')!==false);
vqCheck('visual evidence uploads as artifact',strpos($workflow,'actions/upload-artifact@v4')!==false&&strpos($workflow,'visual-artifacts/*.png')!==false);
vqCheck('visual QA is isolated from production',stripos($workflow,'deploy_')===false&&strpos($workflow,'anytour.online')===false);
vqCheck('workflow only targets manager visual paths',strpos($workflow,"- 'manager/**'")!==false&&strpos($workflow,"- 'tests/visual/**'")!==false);
vqCheck('fixture uses production Workspace V2 styles',strpos($fixture,'../../manager/assets/workspace-v2.css')!==false&&strpos($fixture,'../../manager/assets/workspace-v2-inbox.css')!==false&&strpos($fixture,'../../manager/assets/workspace-v2-tasks.css')!==false&&strpos($fixture,'../../manager/assets/workspace-v2-notifications.css')!==false);
vqCheck('fixture covers high-risk responsive states',strpos($fixture,'waitUrgent')!==false&&strpos($fixture,'waitWarn')!==false&&strpos($fixture,'leadTaskCompact overdue')!==false&&strpos($fixture,'leadWaitCompact urgent')!==false&&strpos($fixture,'conversationZone')!==false&&strpos($fixture,'leadZone')!==false);
vqCheck('fixture can expose mobile conversation and lead views',strpos($fixture,"view==='conversation'")!==false&&strpos($fixture,"view==='lead'")!==false&&strpos($fixture,"classList.add('open')")!==false);
vqCheck('kanban fixture uses production board styles',strpos($kanbanFixture,'../../manager/assets/workspace-v2-kanban.css')!==false&&strpos($kanbanFixture,'kanbanMode')!==false&&strpos($kanbanFixture,'kanbanColumn')!==false&&strpos($kanbanFixture,'kanbanCard urgent')!==false);
vqCheck('fixtures have no real production endpoints',strpos($fixture,'anytour.online')===false&&strpos($fixture,'api.php')===false&&strpos($fixture,'pipeline-api.php')===false&&strpos($kanbanFixture,'anytour.online')===false&&strpos($kanbanFixture,'api.php')===false&&strpos($kanbanFixture,'pipeline-api.php')===false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";exit($failed?1:0);
