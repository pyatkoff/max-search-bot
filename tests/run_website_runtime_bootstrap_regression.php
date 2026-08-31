<?php

declare(strict_types=1);

$base=dirname(__DIR__);
$legacy=(string)file_get_contents($base.'/website_consultant_api.php');
$v2=(string)file_get_contents($base.'/web-consultant/api.php');
$passed=0;$failed=0;
function wrbCheck(string $name,bool $ok):void{global $passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

wrbCheck('legacy website consultant uses runtime bootstrap',strpos($legacy,"require_once(__DIR__ . '/services/RuntimeBootstrap.php');")!==false&&strpos($legacy,'RuntimeBootstrap::boot();')!==false);
wrbCheck('legacy website consultant no longer directly requires Bitrix prolog',strpos($legacy,"/bitrix/modules/main/include/prolog_before.php")===false);
wrbCheck('v2 web consultant uses runtime bootstrap',strpos($v2,"require_once $root . '/services/RuntimeBootstrap.php';")!==false&&strpos($v2,'RuntimeBootstrap::boot();')!==false);
wrbCheck('v2 web consultant no longer probes Bitrix prolog itself',strpos($v2,"/bitrix/modules/main/include/prolog_before.php")===false&&strpos($v2,'$prolog')===false);
wrbCheck('standalone decision remains centralized',strpos((string)file_get_contents($base.'/services/RuntimeBootstrap.php'),'MAX_SEARCH_STANDALONE_RUNTIME')!==false);

$total=$passed+$failed;
echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
