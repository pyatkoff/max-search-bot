<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ChannelPostMaxAlbumRecovery.php';
require_once __DIR__.'/fixtures/channel_post_registry_fixture.php';
$checks=0;
function recoveryCheck(string $name,bool $ok):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$name);$checks++;echo 'PASS '.$name."\n";}
function recoveryFixture():array{
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    createChannelPostRegistryFixture($pdo);
    $pdo->exec("INSERT INTO projects VALUES(1,'anytour','AnyTour',1);
    INSERT INTO conversation_sources VALUES(6,1,'max_anytour_spb','SPB','max',1,'manager');");
    $registry=new ChannelPostRegistryService($pdo);
    $registry->configureChannel(['project_key'=>'anytour','platform'=>'max','bot_key'=>'id9704048781_1_bot','channel_id'=>'-77309177991187','source_key'=>'max_anytour_spb','display_name'=>'SPB','bot_url'=>'https://max.ru/id9704048781_1_bot','is_enabled'=>true]);
    $message=['recipient'=>['chat_id'=>'-77309177991187','chat_type'=>'channel'],'timestamp'=>1791972294000,'body'=>['mid'=>'mid.spb-owner-album','text'=>'Синтетическая цена 100 000 ₽','markup'=>[],'attachments'=>[]]];
    foreach(range(1,6) as $i)$message['body']['attachments'][]=['type'=>'image','payload'=>['photo_id'=>1000+$i,'token'=>'private-token-'.$i,'url'=>'https://cdn.example.test/private-'.$i]];
    $message['body']['attachments'][]=['type'=>'inline_keyboard','payload'=>['buttons'=>[[['type'=>'open_app','text'=>'Комментарии','contact_id'=>111,'payload'=>'private-launch']]]]];
    $first=ChannelPostIncomingAdapter::maxSnapshot($message);$first['text']='Предыдущая редакция';
    $registry->registerPublication('anytour','max','id9704048781_1_bot','-77309177991187',$message['body']['mid'],$first);
    $old=ChannelPostIncomingAdapter::maxSnapshot($message);
    $old['metadata']['capability']='requires_attention';$old['metadata']['capability_reason']='album_not_supported';
    $media=array_map(static fn($i)=>['type'=>$i['type'],'stable_id'=>(string)$i['photo_id']],$old['attachments']);
    $old['content_hash']=hash('sha256',json_encode([$old['text'],$old['markup'],$media,$old['keyboard'],$old['metadata']['link']??null,''],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $post=$registry->registerPublication('anytour','max','id9704048781_1_bot','-77309177991187',$message['body']['mid'],$old);
    $claim=$registry->claimPublication($post['publication_id'],$post['version_id']);
    $registry->finishPublication($post['publication_id'],$post['version_id'],$claim['claim_token'],'requires_attention','album_not_supported');
    return [$pdo,$registry,$registry->publication($post['publication_id']),$message];
}
[$pdo,$registry,$old,$message]=recoveryFixture();$reads=0;$processes=0;
$service=new ChannelPostMaxAlbumRecovery($registry,
    static function($mid)use(&$reads,$message){$reads++;return ['http'=>200,'transport_error'=>false,'data'=>$message];},
    static function($id,$version)use(&$processes,$registry,$old){$processes++;$fresh=$registry->publication($id);recoveryCheck('processor receives exact appended publication version',$id===$old['publication_id']&&$version===$fresh['version_id']&&$fresh['version_number']===3);return ['state'=>'sent','reason'=>'verified','receipt'=>['request_count'=>5,'write_attempts'=>1,'button_verified'=>true,'content_preserved'=>true]];}
);
$preview=$service->run($old['publication_id']);
recoveryCheck('default preview makes no provider or processor call',$preview['state']==='preview'&&$reads===0&&$processes===0);
recoveryCheck('preview retains terminal original and exact version',$registry->publication($old['publication_id'])['version_id']===$old['version_id']);
$result=$service->run($old['publication_id'],true);$fresh=$registry->publication($old['publication_id']);
recoveryCheck('approved apply performs one initial read and one bounded targeted operation',$result['ok']&&$reads===1&&$processes===1&&$result['provider_requests']===6);
recoveryCheck('recovery keeps original token and appends immutable content version',$fresh['start_payload']===$old['start_payload']&&$fresh['version_number']===3&&$fresh['version_id']!==$old['version_id']);
$original=$registry->versionForPublication($old['publication_id'],$old['version_id']);
recoveryCheck('old photo context and original unsupported evidence are not rewritten',$original['snapshot']===$old['snapshot']);
recoveryCheck('new observation explicitly says provider read rather than inventing webhook or edit time',$fresh['snapshot']['metadata']['observation_kind']==='provider_read'&&$fresh['snapshot']['metadata']['source_revision_scope']==='provider_read_observation_time'&&($fresh['snapshot']['edited_at']??'')==='');
recoveryCheck('new snapshot retains all ordered photographs',array_column($fresh['snapshot']['attachments'],'photo_id')===array_column($old['snapshot']['attachments'],'photo_id'));
$again=$service->run($old['publication_id'],true);
recoveryCheck('apply cannot be repeated after version has advanced',!$again['ok']&&$again['reason']==='target_state_changed'&&$reads===1&&$processes===1);
recoveryCheck('safe receipt omits all post content and private tokens',!str_contains(json_encode($result),'private-')&&!str_contains(json_encode($result),'Синтетическая'));
foreach(['wrong_mid','wrong_channel','unsupported','read_failed'] as $case){
    [$pdo,$registry,$old,$message]=recoveryFixture();$calls=0;
    if($case==='wrong_mid')$message['body']['mid']='mid.other';
    if($case==='wrong_channel')$message['recipient']['chat_id']='-999';
    if($case==='unsupported')$message['body']['attachments'][0]=['type'=>'video','payload'=>['token'=>'private']];
    $response=$case==='read_failed'?['http'=>0,'transport_error'=>true,'data'=>null]:['http'=>200,'data'=>$message,'transport_error'=>false];
    $svc=new ChannelPostMaxAlbumRecovery($registry,static fn($mid)=>$response,static function()use(&$calls){$calls++;return [];});
    $before=$registry->publication($old['publication_id']);$r=$svc->run($old['publication_id'],true);
    recoveryCheck($case.' cannot register or edit a different/unsupported post',!$r['ok']&&$calls===0&&$registry->publication($old['publication_id'])['version_id']===$before['version_id']);
}
[$pdo,$registry,$old,$message]=recoveryFixture();$calls=0;
$svc=new ChannelPostMaxAlbumRecovery($registry,static function($mid)use($pdo,$message){$pdo->exec("UPDATE post_channels SET config_version=config_version+1");return ['http'=>200,'data'=>$message,'transport_error'=>false];},static function()use(&$calls){$calls++;return [];});
$r=$svc->run($old['publication_id'],true);
recoveryCheck('concurrent channel configuration change stops before registration',$r['reason']==='target_changed_during_read'&&$calls===0&&$registry->publication($old['publication_id'])['version_id']===$old['version_id']);
echo 'OK '.$checks." fixed SPB album recovery checks\n";
