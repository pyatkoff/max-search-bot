<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$smoke=(string)file_get_contents($root.'/tools/manager_http_smoke.sh');
$deploy=(string)file_get_contents($root.'/.github/workflows/deploy.yml');

$passed=0;$failed=0;
function checkManagerHttp(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

checkManagerHttp('smoke checks canonical manager root',strpos($smoke,'request manager_root "$BASE_URL/"')!==false);
checkManagerHttp('smoke checks explicit index without redirects',strpos($smoke,'request manager_index "$BASE_URL/index.php"')!==false&&strpos($smoke,'--max-redirs 0')!==false);
checkManagerHttp('smoke checks legacy workspace alias',strpos($smoke,'request manager_workspace_alias "$BASE_URL/workspace-v2.php"')!==false);
checkManagerHttp('smoke verifies unauthenticated API boundary',strpos($smoke,'request manager_api_me "$BASE_URL/api.php" POST')!==false&&strpos($smoke,'assert_status manager_api_me 401')!==false&&strpos($smoke,'"error":"unauthorized"')!==false);
checkManagerHttp('smoke verifies rendered Workspace marker',substr_count($smoke,"assert_body_contains manager_")>=4&&strpos($smoke,'id="workspaceRoot"')!==false);
checkManagerHttp('production deploy runs Manager HTTP smoke',strpos($deploy,'bash tools/manager_http_smoke.sh')!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
