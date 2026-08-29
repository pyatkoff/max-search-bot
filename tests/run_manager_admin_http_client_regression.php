<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$client=(string)file_get_contents($root.'/manager/assets/manager-http-client.js');
$admin=(string)file_get_contents($root.'/manager/admin.php');
$routing=(string)file_get_contents($root.'/manager/routing.php');
$adminJs=(string)file_get_contents($root.'/manager/assets/admin.js');
$routingJs=(string)file_get_contents($root.'/manager/assets/routing.js');
$passed=0;$failed=0;
function httpClientCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  $name\n";$passed++;}else{echo "FAIL  $name\n";$failed++;}}
httpClientCheck('shared client owns admin routing fetch transport',strpos($client,"fetch('api.php'")!==false&&strpos($client,"credentials:'same-origin'")!==false&&strpos($client,"error:'network_error'")!==false&&strpos($client,"error:'invalid_response'")!==false);
httpClientCheck('admin delegates transport to shared client',strpos($adminJs,'ManagerHttpClient.request(action,data,S.csrf)')!==false&&strpos($adminJs,"fetch('api.php'")===false);
httpClientCheck('routing delegates transport to shared client',strpos($routingJs,'ManagerHttpClient.request(action,data,S.csrf)')!==false&&strpos($routingJs,"fetch('api.php'")===false);
$adminClient=strpos($admin,'assets/manager-http-client.js?v=');$adminModule=strpos($admin,'assets/admin.js?v=');
httpClientCheck('admin loads shared client before module',is_int($adminClient)&&is_int($adminModule)&&$adminClient<$adminModule);
$routingClient=strpos($routing,'assets/manager-http-client.js?v=');$routingModule=strpos($routing,'assets/routing.js?v=');
httpClientCheck('routing loads shared client before module',is_int($routingClient)&&is_int($routingModule)&&$routingClient<$routingModule);
httpClientCheck('csrf stays page-state supplied not globally owned',strpos($client,'csrf,...data')!==false&&strpos($client,'S.csrf')===false);
echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS $passed | FAIL $failed\n";
exit($failed?1:0);
