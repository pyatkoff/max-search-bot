<?php

declare(strict_types=1);

// Included by the canonical avatar regression so its synthetic DB, image and
// cleanup apply here too. No provider/network or production data is used.
require_once dirname(__DIR__,2).'/integrations/MaxCustomerAvatarAdapter.php';

$maxFixture=static function(array $changes=[]) use($png): object {
    $f=(object)['calls'=>[],'dns'=>[],'time'=>1000.0,'changes'=>$changes];
    $exchange=static function(string $url,array $options) use($f,$png):array {
        $f->calls[]=['url'=>$url,'options'=>$options];
        $path=(string)parse_url($url,PHP_URL_PATH);$api=str_starts_with($url,'https://platform-api2.max.ru/');
        $data=null;$body=$png;
        if (str_starts_with($path,'/messages/')) {
            $data=['body'=>['mid'=>'mid.synthetic_max_customer'],'sender'=>['user_id'=>'123'],'recipient'=>['chat_id'=>'-456','chat_type'=>'dialog','user_id'=>'999']];
            $data=array_replace_recursive($data,$f->changes['message']??[]);
            $f->time+=(float)($f->changes['identity_seconds']??0);
        } elseif ($api) {
            $data=['chat_id'=>'-456','type'=>'dialog','dialog_with_user'=>['user_id'=>'123','avatar_url'=>'https://i.oneme.ru/i?signature=SYNTHETIC_SIGNED_IMAGE','full_avatar_url'=>'https://i.oneme.ru/full?never-download']];
            $data=array_replace_recursive($data,$f->changes['chat']??[]);
        } else {$body=$f->changes['image']??$png;}
        if ($data!==null) $body=json_encode($data,JSON_THROW_ON_ERROR);
        if (!empty($f->changes['invalid_json']) && $api) $body='{invalid';
        ($options[CURLOPT_WRITEFUNCTION])(null,$body);
        return ['http_status'=>$f->changes[$api?'api_status':'image_status']??200,'ssl_verify_result'=>$f->changes['ssl_result']??0,'transport_error'=>$f->changes['transport_error']??false,'primary_ip'=>$f->changes['primary_ip']??'93.184.216.34'];
    };
    $resolver=static function(string $host,int $budget) use($f):array {$f->dns[]=['host'=>$host,'budget'=>$budget];$f->time+=(float)($f->changes['dns_seconds']??0);return $f->changes['ips']??['93.184.216.34'];};
    $f->adapter=new MaxCustomerAvatarAdapter($exchange,$resolver,static fn():float=>$f->time);
    return $f;
};
$photo=static fn(object $f):?string=>$f->adapter->photo('123','mid.synthetic_max_customer',ManagerCustomerAvatarService::MAX_BYTES);
$f=$maxFixture();
avCheck('MAX exact MID to private recipient to matching user returns one small image',$photo($f)===$png && count($f->calls)===3);
avCheck('MAX uses real signed chat ID rather than customer alias',array_column($f->calls,'url')===['https://platform-api2.max.ru/messages/mid.synthetic_max_customer','https://platform-api2.max.ru/chats/-456','https://i.oneme.ru/i?signature=SYNTHETIC_SIGNED_IMAGE']);
$headers=static fn(array $call):array=>$call['options'][CURLOPT_HTTPHEADER]??[];
avCheck('MAX API has exact token but image CDN receives no authorization',in_array('Authorization: synthetic_max_avatar_token',$headers($f->calls[0]),true) && in_array('Authorization: synthetic_max_avatar_token',$headers($f->calls[1]),true) && !array_filter($headers($f->calls[2]),static fn($h):bool=>stripos($h,'authorization:')===0));
$cdn=$f->calls[2]['options'];
avCheck('MAX image pins approved public IPv4 and never redirects',($cdn[CURLOPT_FOLLOWLOCATION]??null)===false && ($cdn[CURLOPT_PROXY]??null)==='' && ($cdn[CURLOPT_RESOLVE]??null)===['i.oneme.ru:443:93.184.216.34'] && ($cdn[CURLOPT_SSL_VERIFYPEER]??null)===true && ($cdn[CURLOPT_SSL_VERIFYHOST]??null)===2);
avCheck('MAX resolves only approved CDN within remaining total budget',count($f->dns)===1 && $f->dns[0]['host']==='i.oneme.ru' && $f->dns[0]['budget']>0 && $f->dns[0]['budget']<=5000);
foreach ($f->calls as $i=>$call) avCheck('MAX stage '.$i.' uses short millisecond timeout',($call['options'][CURLOPT_TIMEOUT_MS]??0)>0 && $call['options'][CURLOPT_TIMEOUT_MS]<=5000);

foreach ([
    ['message'=>['body'=>['mid'=>'mid.foreign']]],
    ['message'=>['sender'=>['user_id'=>'124']]],
    ['message'=>['recipient'=>['chat_type'=>'channel']]],
    ['message'=>['recipient'=>['chat_id'=>'0']]],
    ['message'=>['recipient'=>['chat_id'=>'9223372036854775808']]],
    ['message'=>['recipient'=>['chat_id'=>'-9223372036854775809']]],
] as $i=>$case) {$f=$maxFixture($case);avCheck('MAX bad message identity stops before chat or image '.$i,$photo($f)===null && count($f->calls)===1 && !$f->dns);}
foreach ([['type'=>'channel'],['chat_id'=>'-457'],['dialog_with_user'=>['user_id'=>'124']]] as $i=>$chat) {$f=$maxFixture(['chat'=>$chat]);avCheck('MAX bad chat identity stops before DNS/image '.$i,$photo($f)===null && count($f->calls)===2 && !$f->dns);}
foreach (['-9223372036854775808','9223372036854775807'] as $chat) {$f=$maxFixture(['message'=>['recipient'=>['chat_id'=>$chat]],'chat'=>['chat_id'=>$chat]]);avCheck('MAX signed int64 boundary preserved '.$chat,$photo($f)===$png && $f->calls[1]['url']==='https://platform-api2.max.ru/chats/'.$chat);}
foreach (['','0','01','-1','9223372036854775808',1.0] as $id) avCheck('MAX invalid user decimal fails closed '.json_encode($id),MaxCustomerAvatarAdapter::decimalId($id)===null);
foreach (['https://evil.example/i','https://i.oneme.ru.evil.example/i','http://i.oneme.ru/i','https://i.oneme.ru:8443/i','https://user:pass@i.oneme.ru/i','https://i.oneme.ru/i#fragment','https://127.0.0.1/i','file:///etc/passwd','https://i.oneme.ru/i' . "\n",'https://i.oneme.ru\\@evil.example/i'] as $i=>$url) {$f=$maxFixture(['chat'=>['dialog_with_user'=>['avatar_url'=>$url]]]);avCheck('MAX rejects unsafe image origin before DNS '.$i,$photo($f)===null && count($f->calls)===2 && !$f->dns);}
foreach ([['127.0.0.1'],['10.0.0.1'],['169.254.169.254'],['100.64.0.1'],['192.0.2.1'],['198.18.0.1'],['224.0.0.1'],['::1'],['93.184.216.34','192.168.1.1'],[]] as $i=>$ips) {$f=$maxFixture(['ips'=>$ips]);avCheck('MAX rejects nonpublic or mixed DNS without CDN call '.$i,$photo($f)===null && count($f->calls)===2);}
foreach ([['api_status'=>403],['api_status'=>404],['api_status'=>429],['transport_error'=>true],['ssl_result'=>20],['invalid_json'=>true]] as $i=>$case) {$f=$maxFixture($case);avCheck('MAX provider failure ends once without retry '.$i,$photo($f)===null && count($f->calls)===1);}
foreach ([['image_status'=>302],['image_status'=>403],['image_status'=>404],['image_status'=>429],['primary_ip'=>'127.0.0.1'],['image'=>str_repeat('x',ManagerCustomerAvatarService::MAX_BYTES+1)]] as $i=>$case) {$f=$maxFixture($case);avCheck('MAX image failure has no retry/fallback '.$i,$photo($f)===null && count($f->calls)===3);}
$f=$maxFixture(['identity_seconds'=>5.1]);avCheck('MAX shared deadline stops after slow identity stage',$photo($f)===null && count($f->calls)===1);
$f=$maxFixture(['dns_seconds'=>5.1]);avCheck('MAX shared deadline stops before CDN after slow DNS',$photo($f)===null && count($f->calls)===2);
$f=$maxFixture(['chat'=>['dialog_with_user'=>['avatar_url'=>null]]]);avCheck('MAX absent miniature never downloads full photo or group icon',$photo($f)===null && count($f->calls)===2);

// Bounded DNS parser fixtures validate resolver responses without live DNS.
$wire=static function(string $name):string {$out='';foreach(explode('.',$name) as $label)$out.=chr(strlen($label)).$label;return $out."\0";};
$question=$wire('i.oneme.ru').pack('nn',1,1);
$dnsHeader=static fn(int $answers,int $flags=0x8180,int $id=417):string=>pack('nnnnnn',$id,$flags,1,$answers,0,0).$question;
$dnsA=static fn(string $owner,string $ip):string=>$owner.pack('nnNn',1,1,60,4).inet_pton($ip);
$dnsParser=new ReflectionMethod(MaxCustomerAvatarAdapter::class,'dnsAddresses');
$parse=static fn(string $bytes):array=>$dnsParser->invoke(null,$bytes,417,$question);
$valid=$dnsHeader(1).$dnsA("\xc0\x0c",'93.184.216.34');
avCheck('MAX DNS accepts exact matching compressed public IPv4 answer',$parse($valid)===['93.184.216.34']);
$alias=$wire('edge.example.net');$cname="\xc0\x0c".pack('nnNn',5,1,60,strlen($alias)).$alias;
avCheck('MAX DNS follows matching in-packet CNAME chain only',$parse($dnsHeader(2).$cname.$dnsA($alias,'93.184.216.34'))===['93.184.216.34']);
foreach ([
    $dnsHeader(1,0x8380).$dnsA("\xc0\x0c",'93.184.216.34'),
    $dnsHeader(1,0x8183).$dnsA("\xc0\x0c",'93.184.216.34'),
    $dnsHeader(1,0x8180,418).$dnsA("\xc0\x0c",'93.184.216.34'),
    $dnsHeader(1).$dnsA($wire('foreign.example.net'),'93.184.216.34'),
    $dnsHeader(1).$dnsA("\xc0\x0c",'127.0.0.1'),
    $dnsHeader(2).$dnsA("\xc0\x0c",'93.184.216.34').$dnsA("\xc0\x0c",'169.254.169.254'),
    substr($valid,0,-1),
    $dnsHeader(1)."\xff\xff",
    $dnsHeader(1).chr(0xc0).chr(12+strlen($question)),
] as $i=>$packet) avCheck('MAX DNS malformed foreign truncated or nonpublic response rejected '.$i,$parse($packet)===[]);

// Exercise the actual service/cache with noisy saved message metadata. Eligible
// messages are filtered before the final latest choice, within a bounded window.
$pdo->exec("INSERT INTO messages VALUES(1,300,'max','inbound','customer','123','mid.synthetic_max_customer','{\"type\":\"message\"}'),(2,300,'max','inbound','customer','123','mid.callback','{\"type\":\"callback\"}'),(3,300,'max','outbound','manager','123','mid.outgoing','{\"type\":\"message\"}'),(4,300,'telegram','inbound','customer','123','mid.foreign_channel','{\"type\":\"message\"}'),(5,300,'max','inbound','customer','124','mid.foreign_sender','{\"type\":\"message\"}'),(6,300,'max','inbound','customer','123','mid.invalid_json','not-json')");
$f=$maxFixture();$maxService=new ManagerCustomerAvatarService($root.'/max-service',$fetch,$f->adapter);$resolved=$maxService->avatar(1,300);
avCheck('MAX service chooses eligible customer MID behind callbacks and foreign rows',$resolved!==null && $resolved['bytes']===$png && count($f->calls)===3 && $f->calls[0]['url']==='https://platform-api2.max.ru/messages/mid.synthetic_max_customer');
$before=count($f->calls);avCheck('MAX warm cache makes zero provider or DNS calls',$maxService->avatar(1,300)===$resolved && count($f->calls)===$before && count($f->dns)===1);
$pdo->exec('DELETE FROM manager_projects WHERE manager_id=1');avCheck('MAX fresh ACL prevents prewarmed portrait disclosure',$maxService->avatar(1,300)===null && count($f->calls)===$before);$pdo->exec('INSERT INTO manager_projects VALUES(1,1)');
$pdo->exec("UPDATE customer_channels SET external_user_id='124' WHERE id=30");avCheck('MAX changed external identity cannot read prior cache or foreign MID',$maxService->avatar(1,300)===null && count($f->calls)===$before+1 && str_ends_with($f->calls[$before]['url'],'/messages/mid.foreign_sender'));$pdo->exec("UPDATE customer_channels SET external_user_id='123' WHERE id=30");
// Candidate exists outside the latest 256 metadata rows. A bounded lookup must
// prefer initials rather than scanning all original transcript history.
for ($i=10;$i<266;$i++) $pdo->exec("INSERT INTO messages VALUES(".$i.",300,'max','inbound','customer','123','mid.callback_".$i."','{\"type\":\"callback\"}')");
$f=$maxFixture();$bounded=new ManagerCustomerAvatarService($root.'/max-bounded',$fetch,$f->adapter);avCheck('MAX long callback tail bounds candidate read and avoids provider work',$bounded->avatar(1,300)===null && !$f->calls);
$pdo->exec('DELETE FROM messages WHERE id>=10');
$f=$maxFixture(['api_status'=>429]);$negative=new ManagerCustomerAvatarService($root.'/max-negative',$fetch,$f->adapter);avCheck('MAX provider failure keeps initials',$negative->avatar(1,300)===null && count($f->calls)===1);avCheck('MAX negative cache prevents API request storm',$negative->avatar(1,300)===null && count($f->calls)===1);
$f=$maxFixture(['image'=>'<svg xmlns="http://www.w3.org/2000/svg"><script>bad()</script></svg>']);$unsafe=new ManagerCustomerAvatarService($root.'/max-invalid-bytes',$fetch,$f->adapter);avCheck('MAX binary endpoint retains canonical image MIME validation',$unsafe->avatar(1,300)===null && count($f->calls)===3);
foreach (glob($root.'/max-service/*.json') as $file) {$cacheRecord=(string)file_get_contents($file);avCheck('MAX cache never stores token provider URL or message body',!str_contains($cacheRecord,'synthetic_max_avatar_token') && !str_contains($cacheRecord,'SYNTHETIC_SIGNED_IMAGE') && !str_contains($cacheRecord,'https://') && !str_contains($cacheRecord,'synthetic_max_customer'));}
