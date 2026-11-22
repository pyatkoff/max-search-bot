<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require_once dirname(__DIR__).'/services/AnyTourPublisherBotConnection.php';
function check(bool $ok,string $label):void { if(!$ok)throw new RuntimeException($label);echo "PASS $label\n"; }
function refuses(callable $fn,string $label):void {try{$fn();}catch(RuntimeException $e){check(true,$label);return;}throw new RuntimeException($label);}
$root=sys_get_temp_dir().'/anytour-publisher-'.bin2hex(random_bytes(8));
$token='123456:'.str_repeat('A',35);
$calls=[];
$reply=static function(string $candidate,string $method,array $body)use(&$calls):array{
    $calls[]=$method;
    return ['http'=>200,'ok'=>true,'result'=>['id'=>123456,'username'=>'ANYTOURBOT_bot','is_bot'=>true]];
};
try {
    $conn=new AnyTourPublisherBotConnection($root,$reply);
    check($conn->status()['configured']===false,'initially disconnected');
    refuses(fn()=>$conn->save(0,'invalid'),'reject invalid token');
    check(count($calls)===0,'invalid token never reaches Telegram');
    $wrong=new AnyTourPublisherBotConnection($root,static fn()=>['http'=>200,'ok'=>true,'result'=>['id'=>123456,'username'=>'another_bot','is_bot'=>true]]);
    refuses(fn()=>$wrong->save(0,$token),'reject wrong bot');
    check($conn->status()['configured']===false,'wrong identity not saved');
    $conn->save(0,$token);
    $state=$conn->status();
    check($state['configured']===true && $state['version']===1,'encrypted token saved');
    check(!str_contains((string)file_get_contents($root.'/connection.json'),$token),'token absent from registry');
    check((fileperms($root.'/connection.json')&0077)===0,'private registry permissions');
    refuses(fn()=>$conn->save(0,$token),'stale version rejected');
    check($conn->status()['version']===1,'stale write has no effect');
    $conn->save(1,$token);
    check($conn->status()['version']===2,'explicit rotation');
    check($calls===['getMe','getMe','getMe'],'only getMe called');
    echo "PASS AnyTour publisher connection regression\n";
}finally{
    foreach(['connection.json','connection.lock','master.key'] as $f)if(is_file($root.'/'.$f))unlink($root.'/'.$f);
    if(is_dir($root))rmdir($root);
}
