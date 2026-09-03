<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$manager=$root.'/manager';
$fetchOwners=[];
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($manager,FilesystemIterator::SKIP_DOTS));
foreach($iterator as $file){
    if(!$file->isFile()||!in_array(strtolower($file->getExtension()),['js','php'],true))continue;
    $source=(string)file_get_contents($file->getPathname());
    if(strpos($source,'fetch(')!==false)$fetchOwners[]=str_replace($root.'/','',$file->getPathname());
}
sort($fetchOwners);
$expected=[
    'manager/assets/manager-http-client.js',
    'manager/assets/workspace-v2-media.js',
    'manager/assets/workspace-v2-notifications.js',
    'manager/assets/workspace-v2.js',
    'manager/push-enable.php',
    'manager/sw.js',
];
sort($expected);

$client=(string)file_get_contents($manager.'/assets/manager-http-client.js');
$core=(string)file_get_contents($manager.'/assets/workspace-v2.js');
$media=(string)file_get_contents($manager.'/assets/workspace-v2-media.js');
$notifications=(string)file_get_contents($manager.'/assets/workspace-v2-notifications.js');
$standalone=(string)file_get_contents($manager.'/push-enable.php');
$worker=(string)file_get_contents($manager.'/sw.js');
$passed=0;$failed=0;
function transportCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){echo "PASS  {$name}\n";$passed++;return;}echo "FAIL  {$name}\n";$failed++;}

transportCheck('every Manager browser fetch owner is explicitly inventoried',$fetchOwners===$expected);
transportCheck('shared admin transport owns ordinary JSON request failures',strpos($client,"endpoint='api.php'")!==false&&strpos($client,"error:'network_error'")!==false&&strpos($client,"error:'invalid_response'")!==false);
transportCheck('Workspace core owns auth recovery and generic JSON transports only',strpos($core,'showAuthRecovery')!==false&&strpos($core,"request('api.php'")!==false&&strpos($core,"request('pipeline-api.php'")!==false&&strpos($core,"fetch('api.php'")!==false&&strpos($core,"fetch('media-upload.php'")===false&&strpos($core,"fetch('push.php")===false&&strpos($core,"fetch('push-status.php")===false);
transportCheck('multipart media upload remains a focused transport owner',strpos($media,'new FormData()')!==false&&strpos($media,"fetch('media-upload.php'")!==false&&strpos($media,"'Content-Type':'application/json'")===false);
transportCheck('notification transport retains push-specific timeouts and auth recovery',strpos($notifications,"fetch('push-status.php'")!==false&&strpos($notifications,"fetch('push.php")!==false&&strpos($notifications,'withTimeout(')!==false&&strpos($notifications,'handleUnauthorized(')!==false);
transportCheck('standalone and worker push callers remain lifecycle-specific',strpos($standalone,'Notification.requestPermission()')!==false&&strpos($standalone,"fetch('push.php")!==false&&strpos($worker,"self.addEventListener('activate'")!==false&&strpos($worker,'syncPushSubscription')!==false&&strpos($worker,"fetch('push.php")!==false);

echo "\n--------------------------\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
