<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ChannelPostReconciliationService.php';
require_once __DIR__.'/fixtures/channel_post_registry_fixture.php';
$passed=0;
function reconcileCheck(string $name,bool $ok):void {global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
createChannelPostRegistryFixture($pdo);
$pdo->exec("INSERT INTO projects VALUES(1,'anytour','AnyTour',1);
INSERT INTO conversation_sources VALUES(11,1,'max_manager','MAX','max',1,'manager');");
$registry=new ChannelPostRegistryService($pdo);
$config=['project_key'=>'anytour','platform'=>'max','bot_key'=>'test_bot','channel_id'=>'-80095447388179','source_key'=>'max_manager','display_name'=>'Test','bot_url'=>'https://max.ru/test_bot','is_enabled'=>true];
$channel=$registry->configureChannel($config);
$since=(int)$pdo->query('SELECT enabled_since_ms FROM post_channel_reconciliation')->fetchColumn();
$now=$since+10000;
$identity=['project_key'=>'anytour','platform'=>'max','bot_key'=>'test_bot','bot_url'=>'https://max.ru/test_bot'];
$fixture=(require __DIR__.'/fixtures/channel_post_automation.php')['max'];
$fixture['timestamp']=$since+1000;$fixture['url']=null;$fixture['body']['mid']='mid.missed';
$original=$fixture;$messages=['mid.missed'=>$fixture];
$old=$fixture;$old['timestamp']=$since-1000;$old['body']['mid']='mid.old';$messages['mid.old']=$old;
$writes=0;$badPage=false;$failPage=false;$changeConfig=false;$onPage=null;
$request=static function(string $platform,string $method,string $path,array $query,?array $body)use(&$messages,&$writes,&$badPage,&$failPage,&$changeConfig,&$onPage,$registry,$config):array{
    $good=static fn(array $data):array=>['http'=>200,'data'=>$data,'transport_error'=>false];
    if($method==='GET'&&$path==='/me')return $good(['is_bot'=>true,'username'=>'test_bot','user_id'=>1]);
    if($method==='GET'&&str_ends_with($path,'/members/me'))return $good(['is_bot'=>true,'username'=>'test_bot','user_id'=>1,'is_admin'=>true,'permissions'=>['read_all_messages','edit']]);
    if($method==='GET'&&str_starts_with($path,'/chats/'))return $good(['type'=>'channel','chat_id'=>-80095447388179]);
    if($method==='GET'&&$path==='/messages'){
        if($failPage)return ['http'=>503,'data'=>null,'transport_error'=>false];
        if($changeConfig){$changeConfig=false;$registry->configureChannel($config+['expected_version'=>1]);}
        $rows=array_values(array_filter($messages,static fn(array $m):bool=>$m['timestamp']<=$query['from']&&$m['timestamp']>=$query['to']));
        usort($rows,static fn(array $a,array $b):int=>$b['timestamp']<=>$a['timestamp']);
        $rows=array_slice($rows,0,$query['count']);
        if($badPage&&$rows)$rows[0]['recipient']['chat_id']=-999;
        if($onPage!==null){$callback=$onPage;$onPage=null;$callback();}
        return $good(['messages'=>$rows]);
    }
    if($method==='GET'&&str_starts_with($path,'/messages/'))return $good($messages[substr($path,10)]);
    if($method==='PUT'&&$path==='/messages'){
        $writes++;$mid=$query['message_id'];$aa=[];
        foreach($body['attachments'] as $a){
            if(in_array($a['type'],['image','video'],true)){
                foreach($messages[$mid]['body']['attachments']as $prior)if($prior['type']===$a['type']&&$prior['payload']['token']===$a['payload']['token'])$aa[]=$prior;
            }else $aa[]=$a;
        }
        $messages[$mid]['body']['attachments']=$aa;return $good(['success'=>true]);
    }
    throw new RuntimeException('Unexpected provider operation');
};
$service=new ChannelPostReconciliationService($pdo,$request,static function()use(&$now):int{return $now;});
$before=$pdo->query('SELECT * FROM post_channel_reconciliation')->fetchAll();
$preview=$service->scan($identity);
reconcileCheck('preview reads the missed provider post without registry, cursor or provider writes',$preview['ok']&&$preview['observed']===1&&$writes===0&&(int)$pdo->query('SELECT COUNT(*) FROM post_publications')->fetchColumn()===0&&$before===$pdo->query('SELECT * FROM post_channel_reconciliation')->fetchAll());
$seen=$service->scan($identity,true);
$p=$registry->pendingPublications('anytour','max','test_bot',5);
reconcileCheck('missing webhook is recovered from real provider MID while pre-activation history is excluded',$seen['ok']&&$seen['registered']===1&&count($p)===1&&$p[0]['external_message_id']==='mid.missed'&&$p[0]['snapshot']['metadata']['observation_kind']==='provider_read');
$sent=(new ChannelPostAutomationService($registry,new ChannelPostButtonTransport($request)))->processPublication('anytour','max','test_bot',$p[0]['publication_id'],$p[0]['version_id']);
reconcileCheck('recovered post receives exactly one owned button through the existing durable edit path',$sent['state']==='sent'&&$writes===1&&$sent['receipt']['button_verified']&&$sent['receipt']['content_preserved']);
reconcileCheck('provider edit preserves exact text markup forward and original image identities',$messages['mid.missed']['body']['text']===$original['body']['text']&&$messages['mid.missed']['body']['markup']===$original['body']['markup']&&$messages['mid.missed']['link']===$original['link']&&$messages['mid.missed']['body']['attachments'][0]===$original['body']['attachments'][0]);
$now+=1000;$service->scan($identity,true);
reconcileCheck('overlapping reads and self-edits never append another version or repeat PUT',$writes===1&&(int)$pdo->query('SELECT COUNT(*) FROM post_versions')->fetchColumn()===1);
$hash=ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::maxSnapshot($messages['mid.missed']));
$check=$service->recover($identity,$config['channel_id'],$since+1000,$hash);
reconcileCheck('exact recovery preview remains read only for an already registered post',$check['ok']&&$check['state']==='preview'&&$check['database_writes']===0&&$writes===1);
reconcileCheck('exact recovery is idempotent after a successful edit',$service->recover($identity,$config['channel_id'],$since+1000,$hash,true)['already_present']&&$writes===1);
reconcileCheck('stale target fingerprint cannot change the post',$service->recover($identity,$config['channel_id'],$since+1000,str_repeat('0',64),true)['reason']==='target_content_changed'&&$writes===1);
$messages['mid.missed']['body']['attachments']=$original['body']['attachments'];
$current=$registry->publication($p[0]['publication_id']);
$repair=$registry->registerProviderObservation('anytour','test_bot',$channel,$messages['mid.missed'],$since);
reconcileCheck('fresh provider observation can repair only a known successful same-content keyboard',$repair['version_number']===2&&$repair['start_payload']===$current['start_payload']&&$repair['state']==='pending');
$claim=$registry->claimPublication($repair['publication_id'],$repair['version_id']);
$registry->markExternalWriteStarted($repair['publication_id'],$repair['version_id'],$claim['claim_token']);
$registry->finishPublication($repair['publication_id'],$repair['version_id'],$claim['claim_token'],'unknown');
$again=$registry->registerProviderObservation('anytour','test_bot',$channel,$messages['mid.missed'],$since);
reconcileCheck('UNKNOWN write keeps its immutable version and cannot be requeued by polling',$again['version_id']===$repair['version_id']&&$again['state']==='unknown'&&$again['write_attempts']===1);
$ambiguous=$service->recover($identity,$config['channel_id'],$since+1000,$hash,true);
reconcileCheck('recovery of UNKNOWN reconciles by reads and never retries PUT',$ambiguous['state']==='unknown'&&$writes===1);
$messages['mid.missed']['body']['text']='New SMM caption';
$still=$registry->registerProviderObservation('anytour','test_bot',$channel,$messages['mid.missed'],$since);
reconcileCheck('unclocked provider reads cannot replace a different registered content version',$still['version_id']===$repair['version_id']&&$still['snapshot']['text']===$original['body']['text']);
$now+=1000;$fresh=$original;$fresh['timestamp']=$now-1;$fresh['body']['mid']='mid.new';$messages['mid.new']=$fresh;
$badPage=true;$cursor=$pdo->query('SELECT completed_through_ms FROM post_channel_reconciliation')->fetchColumn();$bad=$service->scan($identity,true);$badPage=false;
reconcileCheck('foreign provider page does not advance the durable scan window',$bad['reason']==='provider_page_identity_mismatch'&&$cursor===$pdo->query('SELECT completed_through_ms FROM post_channel_reconciliation')->fetchColumn());
$failPage=true;$failed=$service->scan($identity,true);$failPage=false;
reconcileCheck('provider outage retains the unfinished window for the next run',$failed['reason']==='provider_page_unavailable'&&$cursor===$pdo->query('SELECT completed_through_ms FROM post_channel_reconciliation')->fetchColumn());
$state=new ChannelPostReconciliationState($pdo);$lease=$state->claim('anytour','test_bot',$now);
reconcileCheck('a second scanner cannot acquire a live read lease',$lease!==null&&$state->claim('anytour','test_bot',$now)===null);
$registry->configureChannel($config+['is_enabled'=>true,'expected_version'=>1]);
reconcileCheck('configuration revision fences old registration and cursor receipts',$registry->registerProviderObservation('anytour','test_bot',$channel,$original,$since)===null&&!$state->finish($lease,null));
$disabled=array_replace($config,['is_enabled'=>false,'expected_version'=>2]);$registry->configureChannel($disabled);
reconcileCheck('disabled channels are excluded from automatic provider discovery',$state->claim('anytour','test_bot',$now)===null);
$channel=$registry->configureChannel($config+['expected_version'=>3]);$reset=$pdo->query('SELECT * FROM post_channel_reconciliation')->fetch();
reconcileCheck('re-enable starts a fresh activation window and clears incomplete pagination',$reset['scan_until_ms']===null&&$reset['before_ms']===null&&$reset['completed_through_ms']===$reset['enabled_since_ms']);
reconcileCheck('read-only queue preview never expires an ambiguous write claim',$registry->pendingPublications('anytour','max','test_bot',5,false)[0]['state']==='unknown'&&$writes===1);
$messages=[];$now+=1000;
for($i=0;$i<100;$i++){$m=$original;$m['timestamp']=$now-$i;$m['body']['mid']='mid.page.'.$i;$messages[$m['body']['mid']]=$m;}
$page=$service->scan($identity,true);$partial=$pdo->query('SELECT * FROM post_channel_reconciliation')->fetch();
reconcileCheck('full bounded page persists its inclusive boundary instead of losing the remaining window',$page['ok']&&$page['more']&&$partial['before_ms']===$now-99&&$partial['scan_until_ms']===$now);
$page=$service->scan($identity,true);$complete=$pdo->query('SELECT * FROM post_channel_reconciliation')->fetch();
reconcileCheck('next invocation resumes the same page boundary and commits the completed high-water mark',$page['ok']&&!$page['more']&&$complete['completed_through_ms']===$now&&$complete['scan_until_ms']===null);
$messages=[];$now+=1000;
for($i=0;$i<100;$i++){$m=$original;$m['timestamp']=$now;$m['body']['mid']='mid.tie.'.$i;$messages[$m['body']['mid']]=$m;}
$tied=$service->scan($identity,true);
reconcileCheck('an unpageable equal-millisecond boundary is explicit and never silently advances the cursor',$tied['reason']==='pagination_boundary_requires_attention'&&$pdo->query('SELECT completed_through_ms FROM post_channel_reconciliation')->fetchColumn()===$now-1000&&$writes===1);
$messages=[];$service->scan($identity,true);$now+=600000;$service->scan($identity,true);
$late=$original;$late['timestamp']=$now-10000;$late['body']['mid']='mid.delayed.visibility';$messages['mid.delayed.visibility']=$late;$now+=1000;
$lateRead=$service->scan($identity,true);
reconcileCheck('provider visibility delay is covered by the overlap without advancing or replaying webhook clocks',$lateRead['ok']&&$lateRead['registered']===1&&$writes===1);

// Retained pre-video-support rows used a media fingerprint without video identity.
// Recreate that historical fixture, not an authenticated webhook replay.
$seedLegacy=static function(array $message)use($registry,$config):array{
    $snapshot=ChannelPostIncomingAdapter::maxSnapshot($message);
    $snapshot['metadata']['capability']='requires_attention';
    $snapshot['metadata']['capability_reason']='unsupported_media';
    foreach($snapshot['attachments']as &$item)if($item['type']==='video')unset($item['video_id']);
    unset($item);
    $media=[];
    foreach($snapshot['attachments']as $item)$media[]=['type'=>$item['type'],'stable_id'=>(string)($item['photo_id']??'')];
    $snapshot['content_hash']=hash('sha256',(string)json_encode([$snapshot['text'],$snapshot['markup'],$media,[],$snapshot['metadata']['link'],''],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $p=$registry->registerPublication('anytour','max','test_bot',$config['channel_id'],$message['body']['mid'],$snapshot);
    $claim=$registry->claimPublication($p['publication_id'],$p['version_id']);
    $registry->finishPublication($p['publication_id'],$p['version_id'],$claim['claim_token'],'requires_attention','unsupported_media');
    return $registry->publication($p['publication_id']);
};
$video=$original;$video['timestamp']=$now+10000;$video['body']['mid']='mid.legacy.video';
$video['body']['attachments']=[
    $original['body']['attachments'][0],
    ['type'=>'video','payload'=>['id'=>12345678901234,'token'=>'fixture_original_video_1','url'=>'https://cdn.example.test/video.mp4?expires=old'],'thumbnail'=>['url'=>'https://cdn.example.test/cover.jpg'],'width'=>1080,'height'=>1920,'duration'=>30],
];
$messages[$video['body']['mid']]=$video;
$legacy=$seedLegacy($video);$legacyVersion=(int)$legacy['version_id'];
$oldRow=$pdo->query('SELECT * FROM post_versions WHERE id='.$legacyVersion)->fetch();
$oldPublication=$pdo->query('SELECT * FROM post_publications WHERE id='.$legacy['publication_id'])->fetch();
$beforeVersions=(int)$pdo->query('SELECT COUNT(*) FROM post_versions')->fetchColumn();
$beforeObservations=(int)$pdo->query('SELECT COUNT(*) FROM post_observations')->fetchColumn();
$beforeWrites=$writes;
$currentChannel=$registry->channel('anytour','max','test_bot',$config['channel_id']);
$videoHash=ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::maxSnapshot($video));
$ordinary=$service->recover($identity,$config['channel_id'],$video['timestamp'],$videoHash,true);
$polled=$registry->registerProviderObservation('anytour','test_bot',$currentChannel,$video,0);
reconcileCheck('ordinary recovery and polling do not revive a legacy unsupported video',$ordinary['state']==='requires_attention'&&$polled['version_id']===$legacyVersion&&$polled['state']==='requires_attention'&&$writes===$beforeWrites);
$preview=$service->recover($identity,$config['channel_id'],$video['timestamp'],'',false,true);
reconcileCheck('video opt-in preview exposes exact version config and accurate media counts without raw payloads',$preview['ok']&&$preview['legacy_video_recovery_eligible']&&$preview['eligible_version_id']===$legacyVersion&&$preview['config_version']===(int)$currentChannel['config_version']&&$preview['image_count']===1&&$preview['video_count']===1&&$preview['media_count']===2&&!isset($preview['snapshot'],$preview['start_payload'])&&$preview['database_writes']===0&&$preview['provider_writes']===0&&$beforeVersions===(int)$pdo->query('SELECT COUNT(*) FROM post_versions')->fetchColumn()&&$beforeObservations===(int)$pdo->query('SELECT COUNT(*) FROM post_observations')->fetchColumn()&&$oldRow===$pdo->query('SELECT * FROM post_versions WHERE id='.$legacyVersion)->fetch());
$missingPin=$service->recover($identity,$config['channel_id'],$video['timestamp'],$videoHash,true,true);
reconcileCheck('video recovery apply requires explicit old version and configuration pins',$missingPin['reason']==='exact_video_recovery_target_required'&&$writes===$beforeWrites);
$recoverVideo=static function(string $hash)use($service,$identity,$config,$video,$legacyVersion,$currentChannel):array{
    return $service->recover($identity,$config['channel_id'],$video['timestamp'],$hash,true,true,$legacyVersion,(int)$currentChannel['config_version']);
};
foreach(['video_id','text','markup','order','forward']as $changed){
    $different=$video;
    if($changed==='video_id')$different['body']['attachments'][1]['payload']['id']=12345678901235;
    elseif($changed==='text')$different['body']['text'].=' Changed';
    elseif($changed==='markup')$different['body']['markup'][0]['length']++;
    elseif($changed==='order')$different['body']['attachments']=array_reverse($different['body']['attachments']);
    else $different['link']['mid']='mid.other.original';
    $messages[$video['body']['mid']]=$different;
    $changedHash=ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::maxSnapshot($different));
    $rejected=$recoverVideo($changedHash);
    reconcileCheck('legacy video recovery rejects changed source '.$changed,$rejected['reason']==='legacy_video_content_changed'&&$registry->publication($legacy['publication_id'])['version_id']===$legacyVersion&&$writes===$beforeWrites);
}
$messages[$video['body']['mid']]=$video;
foreach([
    ['state'=>'unknown','write_attempts'=>1,'claim_token'=>null,'claimed_at'=>null],
    ['state'=>'claimed','write_attempts'=>0,'claim_token'=>'held','claimed_at'=>gmdate('Y-m-d H:i:s')],
    ['state'=>'write_started','write_attempts'=>1,'claim_token'=>'held','claimed_at'=>gmdate('Y-m-d H:i:s')],
    ['state'=>'sent','write_attempts'=>1,'claim_token'=>null,'claimed_at'=>null],
    ['state'=>'requires_attention','write_attempts'=>1,'claim_token'=>null,'claimed_at'=>null],
    ['state'=>'requires_attention','write_attempts'=>0,'claim_token'=>'held','claimed_at'=>gmdate('Y-m-d H:i:s')],
]as $unsafe){
    $q=$pdo->prepare('UPDATE post_versions SET state=?,write_attempts=?,claim_token=?,claimed_at=? WHERE id=?');
    $q->execute([$unsafe['state'],$unsafe['write_attempts'],$unsafe['claim_token'],$unsafe['claimed_at'],$legacyVersion]);
    $rejected=$recoverVideo($videoHash);
    reconcileCheck('video recovery never revives '.$unsafe['state'].' writes '.$unsafe['write_attempts'].' claim '.($unsafe['claim_token']??'none'),$rejected['reason']==='legacy_video_not_eligible'&&$writes===$beforeWrites&&$beforeVersions===(int)$pdo->query('SELECT COUNT(*) FROM post_versions')->fetchColumn());
}
$pdo->exec("UPDATE post_versions SET state='requires_attention',write_attempts=0,claim_token=NULL,claimed_at=NULL,error_reason='unrelated_failure' WHERE id=".$legacyVersion);
reconcileCheck('an unrelated terminal failure cannot be relabeled as a video repair',$recoverVideo($videoHash)['reason']==='legacy_video_not_eligible');
$pdo->exec("UPDATE post_versions SET error_reason='unsupported_media' WHERE id=".$legacyVersion);
$messages[$video['body']['mid']]['body']['attachments'][1]['payload']['url']='https://cdn.example.test/video.mp4?expires=fresh';
$messages[$video['body']['mid']]['body']['attachments'][1]['payload']['token']='fixture_rotated_video_token';
$renewedImage=$video['body']['attachments'][0];$renewedImage['payload']['token']='renewed-image-token';
$messages[$video['body']['mid']]['body']['attachments'][0]=$renewedImage;
$recovered=$recoverVideo($videoHash);$new=$registry->publication($legacy['publication_id']);
reconcileCheck('explicit video recovery appends one version and edits once after a fresh read',$recovered['ok']&&$recovered['state']==='sent'&&$recovered['recovered_from_version_id']===$legacyVersion&&$new['version_number']===$legacy['version_number']+1&&$new['version_id']!==$legacyVersion&&$new['write_attempts']===1&&$writes===$beforeWrites+1&&$beforeVersions+1===(int)$pdo->query('SELECT COUNT(*) FROM post_versions')->fetchColumn()&&$beforeObservations+1===(int)$pdo->query('SELECT COUNT(*) FROM post_observations')->fetchColumn());
reconcileCheck('recovery retains the rejected row stable p token and webhook clock',$oldRow===$pdo->query('SELECT * FROM post_versions WHERE id='.$legacyVersion)->fetch()&&$new['start_payload']===$legacy['start_payload']&&$oldPublication['observed_revision_ms']===$pdo->query('SELECT observed_revision_ms FROM post_publications WHERE id='.$legacy['publication_id'])->fetchColumn()&&$new['snapshot']['metadata']['observation_kind']==='provider_read'&&$new['snapshot']['metadata']['recovered_version_id']===$legacyVersion);
reconcileCheck('video recovery preserves caption markup forward media order and uses renewed image and video credentials',$recovered['receipt']['content_preserved']&&$recovered['receipt']['button_verified']&&$messages[$video['body']['mid']]['body']['text']===$video['body']['text']&&$messages[$video['body']['mid']]['body']['markup']===$video['body']['markup']&&$messages[$video['body']['mid']]['link']===$video['link']&&$messages[$video['body']['mid']]['body']['attachments'][0]===$renewedImage&&$messages[$video['body']['mid']]['body']['attachments'][1]['payload']['token']==='fixture_rotated_video_token'&&$messages[$video['body']['mid']]['body']['attachments'][1]['duration']===30);
reconcileCheck('repeating an old video recovery selection cannot append or write again',$recoverVideo($videoHash)['reason']==='target_version_changed'&&$writes===$beforeWrites+1&&$beforeVersions+1===(int)$pdo->query('SELECT COUNT(*) FROM post_versions')->fetchColumn());

// Concurrent provider-read boundaries fence both selected version and configuration.
$race=$video;$race['timestamp']++;$race['body']['mid']='mid.legacy.video.race';$messages[$race['body']['mid']]=$race;
$raceOld=$seedLegacy($race);$raceHash=ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::maxSnapshot($race));
$onPage=static function()use($registry,$race,$config):void{
    $changed=$race;$changed['body']['text'].=' New source revision';
    $snapshot=ChannelPostIncomingAdapter::maxSnapshot($changed);$snapshot['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($snapshot);
    $registry->registerPublication('anytour','max','test_bot',$config['channel_id'],$race['body']['mid'],$snapshot);
};
$raced=$service->recover($identity,$config['channel_id'],$race['timestamp'],$raceHash,true,true,$raceOld['version_id'],(int)$currentChannel['config_version']);
reconcileCheck('a successor arriving during the provider read fences the selected legacy version',$raced['reason']==='target_version_changed'&&$writes===$beforeWrites+1);
$configRace=$video;$configRace['timestamp']+=2;$configRace['body']['mid']='mid.legacy.video.config';$messages[$configRace['body']['mid']]=$configRace;
$configOld=$seedLegacy($configRace);$configHash=ChannelPostIncomingAdapter::contentFingerprint(ChannelPostIncomingAdapter::maxSnapshot($configRace));
$onPage=static function()use($registry,$config,$currentChannel):void{$registry->configureChannel(array_replace($config,['expected_version'=>(int)$currentChannel['config_version'],'button_text'=>'Другая кнопка']));};
$changedConfig=$service->recover($identity,$config['channel_id'],$configRace['timestamp'],$configHash,true,true,$configOld['version_id'],(int)$currentChannel['config_version']);
reconcileCheck('configuration change during the provider read prevents appending or editing',$changedConfig['reason']==='channel_configuration_changed'&&$registry->publication($configOld['publication_id'])['version_id']===$configOld['version_id']&&$writes===$beforeWrites+1);
echo "OK $passed MAX publication reconciliation checks\n";

