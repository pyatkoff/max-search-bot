<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$smoke=(string)file_get_contents($root.'/tools/manager_http_smoke.sh');
$deploy=(string)file_get_contents($root.'/.github/workflows/deploy.yml');
$workspaceAlias=$root.'/manager/workspace-v2.php';

$passed=0;$failed=0;
function checkManagerHttp(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

checkManagerHttp('smoke defaults to canonical Manager root',strpos($smoke,'BASE_URL="${MANAGER_BASE_URL:-https://app.anytoour.ru/manager}"')!==false&&strpos($smoke,'request manager_root "$BASE_URL/"')!==false&&strpos($smoke,'assert_status manager_root 200')!==false);
checkManagerHttp('obsolete legacy Manager smoke is removed',strpos($smoke,'LEGACY_MANAGER_BASE_URL')===false&&strpos($smoke,'legacy_manager_')===false&&strpos($smoke,'assert_location')===false);
checkManagerHttp('smoke checks explicit index without redirects',strpos($smoke,'request manager_index "$BASE_URL/index.php"')!==false&&strpos($smoke,'--max-redirs 0')!==false);
checkManagerHttp('legacy workspace PHP entrypoint is removed',!is_file($workspaceAlias)&&strpos($smoke,'workspace-v2.php')===false);
checkManagerHttp('smoke verifies unauthenticated main API boundary',strpos($smoke,'request manager_api_me "$BASE_URL/api.php" POST')!==false&&strpos($smoke,'assert_status manager_api_me 401')!==false&&strpos($smoke,'assert_body_contains manager_api_me \'"error":"unauthorized"\'')!==false);
checkManagerHttp('smoke verifies unauthenticated Sales Pipeline API boundary',strpos($smoke,'request manager_pipeline_api "$BASE_URL/pipeline-api.php" POST')!==false&&strpos($smoke,'assert_status manager_pipeline_api 401')!==false&&strpos($smoke,'assert_body_contains manager_pipeline_api \'"error":"unauthorized"\'')!==false);
checkManagerHttp('smoke verifies rendered Workspace marker',substr_count($smoke,"assert_body_contains manager_")>=4&&strpos($smoke,'id="workspaceRoot"')!==false);
checkManagerHttp('smoke verifies canonical consultant root and widget',strpos($smoke,'CONSULTANT_BASE_URL="${CONSULTANT_BASE_URL:-https://app.anytoour.ru/web-consultant}"')!==false&&strpos($smoke,'request consultant_root "$CONSULTANT_BASE_URL/"')!==false&&strpos($smoke,'request consultant_widget "$CONSULTANT_BASE_URL/widget.js"')!==false);
checkManagerHttp('smoke verifies consultant accessibility enhancer',strpos($smoke,'request consultant_a11y "$CONSULTANT_BASE_URL/widget-a11y.js"')!==false&&strpos($smoke,'assert_status consultant_a11y 200')!==false&&strpos($smoke,'prefers-reduced-motion:reduce')!==false);
checkManagerHttp('production deploy runs Manager HTTP smoke',strpos($deploy,'bash tools/manager_http_smoke.sh')!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
