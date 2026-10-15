<?php

declare(strict_types=1);

// Included by the canonical avatar regression; all provider responses and image
// bytes are synthetic, including deliberately invalid boundary responses.
$fullCalls=[];$fullCase=[];$fullSessionReleased=true;
$fullFetch=static function(string $url,?array $post,int $limit,int $budget) use (&$fullCalls,&$fullCase,&$fullSessionReleased,$png): ?string {
    $fullSessionReleased=$fullSessionReleased && session_status()!==PHP_SESSION_ACTIVE;
    $fullCalls[]=['method'=>basename($url),'post'=>$post,'limit'=>$limit,'budget'=>$budget];
    if (str_ends_with($url,'getUserProfilePhotos')) return json_encode(['ok'=>true,'result'=>[
        'total_count'=>$fullCase['count']??3,
        'photos'=>array_key_exists('photos',$fullCase)?$fullCase['photos']:[[
            ['file_id'=>'small','width'=>64,'height'=>64,'file_size'=>100],
            ['file_id'=>'large-'.$post['offset'],'width'=>2048,'height'=>2048,'file_size'=>500],
            ['file_id'=>'too-wide','width'=>4097,'height'=>4097,'file_size'=>500],
            ['file_id'=>'too-heavy','width'=>4096,'height'=>4096,'file_size'=>ManagerCustomerAvatarService::FULL_MAX_BYTES+1],
        ]],
    ]]);
    if (str_ends_with($url,'getFile')) return json_encode(['ok'=>true,'result'=>['file_path'=>$fullCase['path']??'photos/full.png','file_size'=>$fullCase['file_size']??strlen($png)]]);
    return $fullCase['bytes']??$png;
};
$fullService=new ManagerCustomerAvatarService($root.'/full-service',$fullFetch);
$before=count($fullCalls);$small=$fullService->avatar(1,100);
avCheck('normal portrait asks only first photo and smallest permitted size',count($fullCalls)===$before+3 && $fullCalls[$before]['post']===['user_id'=>'123','offset'=>0,'limit'=>1] && $fullCalls[$before+1]['post']===['file_id'=>'small'] && !array_key_exists('photo_count',$small??[]));
session_start();
avCheck('full photo fixture acquired a real session lock',session_status()===PHP_SESSION_ACTIVE);
$before=count($fullCalls);$firstFull=$fullService->avatar(1,100,'full',0);
avCheck('explicit full photo chooses largest valid size and returns gallery metadata',$firstFull!==null && $firstFull['bytes']===$png && $firstFull['photo_count']===3 && $firstFull['limited']===false && count($fullCalls)===$before+3 && $fullCalls[$before+1]['post']===['file_id'=>'large-0'] && $fullCalls[$before+2]['limit']===ManagerCustomerAvatarService::FULL_MAX_BYTES);
avCheck('full photo releases session lock before provider work',$fullSessionReleased && session_status()!==PHP_SESSION_ACTIVE);
$before=count($fullCalls);$secondFull=$fullService->avatar(1,100,'full',1);
avCheck('next photo is lazy one-offset lookup rather than eager gallery download',$secondFull!==null && $secondFull['photo_count']===3 && count($fullCalls)===$before+3 && $fullCalls[$before]['post']===['user_id'=>'123','offset'=>1,'limit'=>1] && $fullCalls[$before+1]['post']===['file_id'=>'large-1']);
$before=count($fullCalls);
avCheck('thumb and both full indices retain separate positive cache entries',$fullService->avatar(1,100)===$small && $fullService->avatar(1,100,'full',0)===$firstFull && $fullService->avatar(1,100,'full',1)===$secondFull && count($fullCalls)===$before);
avCheck('expected channel is bound to canonical identity before warm cache',$fullService->avatar(1,100,'thumb',0,'max')===null && $fullService->avatar(1,100,'full',0,'max')===null && $fullService->avatar(1,100,'full',0,'telegram')===$firstFull && count($fullCalls)===$before);
avCheck('unsupported expected channel fails without provider request',$fullService->avatar(1,100,'full',0,'website')===null && count($fullCalls)===$before);
foreach (glob($root.'/full-service/*.json') as $file) {
    $record=json_decode(file_get_contents($file),true);
    avCheck('thumbnail and full positive cache records last one day',(int)$record['expires']>=time()+86390 && (int)$record['expires']<=time()+86400);
}
avCheck('full cache uses independent fixed slots',count(glob($root.'/full-service/[0-9]*.json'))===1 && count(glob($root.'/full-service/full-*.json'))===2);
foreach ([['full',-1],['full',1000],['other',0],['thumb',1]] as [$size,$index]) avCheck('service rejects invalid photo mode or index '.$size.'/'.$index,$fullService->avatar(1,100,$size,$index)===null && count($fullCalls)===$before);
$before=count($fullCalls);avCheck('photo beyond provider count stops before getFile',$fullService->avatar(1,100,'full',3)===null && count($fullCalls)===$before+1);
$before=count($fullCalls);avCheck('missing full photo is negatively cached',$fullService->avatar(1,100,'full',3)===null && count($fullCalls)===$before);
$pdo->exec('DELETE FROM manager_projects WHERE manager_id=1');avCheck('full cached photo still checks current ACL',$fullService->avatar(1,100,'full',0)===null && count($fullCalls)===$before);$pdo->exec('INSERT INTO manager_projects VALUES(1,1)');
$fullCase=['count'=>1001];$capped=new ManagerCustomerAvatarService($root.'/full-capped',$fullFetch);$before=count($fullCalls);$last=$capped->avatar(1,100,'full',999);
avCheck('Telegram gallery caps reported count and marks truncation at final index',$last!==null && $last['photo_count']===1000 && $last['limited']===true && count($fullCalls)===$before+3 && $fullCalls[$before]['post']['offset']===999);
foreach ([
    'zero-count'=>['count'=>0],
    'noninteger-count'=>['count'=>'3'],
    'empty'=>['photos'=>[]],
    'unsafe-path'=>['path'=>'../../secret'],
    'file-over-limit'=>['file_size'=>ManagerCustomerAvatarService::FULL_MAX_BYTES+1],
    'body-over-limit'=>['bytes'=>str_repeat('x',ManagerCustomerAvatarService::FULL_MAX_BYTES+1)],
    'dimensions-over-limit'=>['bytes'=>substr_replace($png,pack('N',4097),16,4)],
    'svg'=>['bytes'=>'<svg xmlns="http://www.w3.org/2000/svg"><script>bad()</script></svg>'],
] as $name=>$case) {
    $fullCase=$case;$unsafe=new ManagerCustomerAvatarService($root.'/full-invalid-'.$name,$fullFetch);$before=count($fullCalls);
    avCheck('full photo rejects '.$name,$unsafe->avatar(1,100,'full',0)===null && count($fullCalls)>$before);
    $after=count($fullCalls);avCheck('full photo negatively caches '.$name,$unsafe->avatar(1,100,'full',0)===null && count($fullCalls)===$after);
}
$fullCase=['bytes'=>substr_replace($png,pack('N',4096),16,4)];$edge=new ManagerCustomerAvatarService($root.'/full-dimension-edge',$fullFetch);
avCheck('full photo accepts 4096 dimension boundary',$edge->avatar(1,100,'full',0)!==null);
$fullCase=['bytes'=>$png.str_repeat("\0",ManagerCustomerAvatarService::MAX_BYTES)];$larger=new ManagerCustomerAvatarService($root.'/full-byte-envelope',$fullFetch);
avCheck('full image byte envelope can exceed thumbnail limit',$larger->avatar(1,100,'full',0)!==null && $larger->avatar(1,100)===null);
$fullCase=['bytes'=>$png.str_repeat("\xff",ManagerCustomerAvatarService::FULL_MAX_BYTES-strlen($png))];$exactBytes=new ManagerCustomerAvatarService($root.'/full-byte-edge',$fullFetch);
$exactImage=$exactBytes->avatar(1,100,'full',0);$before=count($fullCalls);
avCheck('one MiB full binary remains cacheable despite slash-heavy base64',$exactImage!==null && strlen($exactImage['bytes'])===ManagerCustomerAvatarService::FULL_MAX_BYTES && $exactBytes->avatar(1,100,'full',0)===$exactImage && count($fullCalls)===$before);
foreach (glob($root.'/full-byte-edge/full-*.json') as $file) avCheck('exact ceiling full JSON record fits computed base64 bound',filesize($file)<=4*intdiv(ManagerCustomerAvatarService::FULL_MAX_BYTES+2,3)+512);
$fullCase=['count'=>1000];$boundedFull=new ManagerCustomerAvatarService($root.'/full-bounded',$fullFetch);$boundedFull->avatar(1,100);$thumbRecord=glob($root.'/full-bounded/[0-9]*.json')[0]??'';$thumbBefore=$thumbRecord!==''?file_get_contents($thumbRecord):null;
for ($i=0;$i<ManagerCustomerAvatarService::FULL_CACHE_SLOTS+80;$i++) $boundedFull->avatar(1,100,'full',$i);
avCheck('many gallery indices cannot evict thumbnail slots',$thumbRecord!=='' && file_get_contents($thumbRecord)===$thumbBefore);
avCheck('full gallery lock and image records stay within independent fixed bound',count(glob($root.'/full-bounded/full-*'))<=ManagerCustomerAvatarService::FULL_CACHE_SLOTS*2);
foreach (glob($root.'/full-bounded/full-*.json') as $file) {
    $recordBytes=file_get_contents($file);
    if (strlen($recordBytes)>4*intdiv(ManagerCustomerAvatarService::FULL_MAX_BYTES+2,3)+512 || str_contains($recordBytes,'synthetic_avatar_token') || str_contains($recordBytes,'https://')) throw new RuntimeException('Unsafe full cache record');
}
avCheck('full cache stores bounded binary and count without provider URLs',true);
$fullCase=[];
