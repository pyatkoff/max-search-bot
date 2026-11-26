<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/repair_max_webhook_fixture.php';
require_once dirname(__DIR__).'/services/ProjectFunnelConversation.php';
$passed=0;
function funnelCheck(string $name,bool $ok):void{global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;}
function funnelReject(callable $call):bool{try{$call();}catch(InvalidArgumentException|RuntimeException $e){return true;}return false;}
function funnelSigned(string $start,int $now,string $token='synthetic-apple-token',string $user='7001',array $extra=[]):string{
    $data=['auth_date'=>(string)$now,'user'=>json_encode(['id'=>$user,'first_name'=>'Synthetic'],JSON_THROW_ON_ERROR),'start_param'=>$start]+$extra;ksort($data);$lines=[];foreach($data as$k=>$v)$lines[]=$k.'='.$v;
    $data['hash']=hash_hmac('sha256',implode("\n",$lines),hash_hmac('sha256',$token,'WebAppData',true));return http_build_query($data,'','&',PHP_QUERY_RFC3986);
}
function funnelSchema(PDO $pdo):void{
    $pdo->exec("CREATE TABLE advertising_visit_cursors(project_key TEXT,source_key TEXT,platform TEXT,counter_id TEXT,last_cursor TEXT DEFAULT '0',PRIMARY KEY(project_key,source_key,platform,counter_id));
    CREATE TABLE advertising_visit_observations(id INTEGER PRIMARY KEY AUTOINCREMENT,project_key TEXT,source_key TEXT,platform TEXT,counter_id TEXT,source_cursor TEXT,visit_id TEXT,visit_at_unix INTEGER,observed_at_unix INTEGER,external_user_id TEXT,identity_status TEXT,client_id TEXT,yclid TEXT,campaign_id TEXT,region_id TEXT,payload_sha256 TEXT,received_at_unix INTEGER,post_ref TEXT DEFAULT '',offer_ref TEXT DEFAULT '',context_json TEXT,UNIQUE(project_key,source_key,platform,counter_id,source_cursor));
    CREATE TABLE appleservice39_pilot_campaigns(campaign_key TEXT,project_key TEXT,bot_key TEXT,channel_id TEXT,source_id INTEGER);
    CREATE TABLE appleservice39_pilot_operations(post_key TEXT,campaign_key TEXT,operation TEXT,state TEXT,write_attempts INTEGER,external_message_id TEXT,public_url TEXT);
    INSERT INTO appleservice39_pilot_campaigns VALUES('appleservice39-pilot-v1','appleservice39','id390521129062_bot','-80917954947727',21);");
    $ids=['menu'=>'AaG9bSM5Y9o','model-iphone-17'=>'AaHJ4f--XbI','service-display'=>'AaG9bRUxHcg','repair-iphone-16-display'=>'AaHJ4X2QW-k','service-rear-glass-replacement'=>'AaHJ4ZVPOYk','symptom-not-charging'=>'AaHJ4a4KCcU','service-rear-camera-replacement'=>'AaHJ4cflDo0','service-earpiece-replacement'=>'AaHJ4eNxXTg'];
    $q=$pdo->prepare('INSERT INTO appleservice39_pilot_operations VALUES(?,?,?,?,?,?,?)');foreach($ids as$key=>$mid)$q->execute([$key,'appleservice39-pilot-v1','create','verified',1,$mid,'https://max.ru/channel_appleservice39ru/'.$mid]);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__)return;
$pdo=repairFixture();funnelSchema($pdo);$now=time();$connection=ProjectMaxConnection::resolve('appleservice39');$s=ProjectFunnelSettings::load($connection);
funnelCheck('owner-approved AppleService price assumption enables its own sender',$s['metrika']['counter_id']==='42611469'&&$s['metrika']['mode']==='send'
    &&($s['prediction']['basis']??null)==='owner_assumption'&&$s['prediction']['probability']===0.2&&$s['prediction']['price_basis']==='listed_service_price');
funnelCheck('owner-selected predict goal keeps its exact ID with the required offline condition',$s['metrika']['goals']===['predictive_value'=>'predict']&&$s['metrika']['goal_ids']===['predictive_value'=>'680570232']&&$s['metrika']['goal_match']===['predictive_value'=>'exact']&&$s['metrika']['credential_source']==='platform');
$expandedSettings=$s;
$deviceOffers=[
    'device-ipad'=>['offer_id'=>'as39-device-v1-device-ipad','mid'=>'AaHzLTakRs4','model'=>'iPad','service'=>''],
    'device-macbook'=>['offer_id'=>'as39-device-v1-device-macbook','mid'=>'AaHzLVO3L9k','model'=>'MacBook','service'=>''],
    'device-apple-watch'=>['offer_id'=>'as39-device-v1-device-apple-watch','mid'=>'AaHzLWt0d3o','model'=>'Apple Watch','service'=>'Замена стекла Apple Watch'],
];
funnelCheck('coverage source preserves 67 original offers and adds only the three approved device cards',
    count($s['posts'])===70&&count(array_diff_key($s['posts'],$deviceOffers))===67
    &&isset($s['posts']['service-battery-replacement'],$s['posts']['service-front-glass-replacement'])
    &&count(array_filter(array_keys($s['posts']),static fn($id)=>str_starts_with($id,'model-iphone-')))===33
    &&!isset($s['posts']['models-index'],$s['posts']['services-index']));
$oldOfferIds=['menu','model-iphone-17','service-display','repair-iphone-16-display','service-rear-glass-replacement','symptom-not-charging','service-rear-camera-replacement','service-earpiece-replacement'];
$s['posts']=array_intersect_key($s['posts'],array_flip($oldOfferIds));
$s['feed_presentation']=array_intersect_key($s['feed_presentation']??[],$s['posts']);
$s['metrika']=['counter_id'=>'','mode'=>'off','goals'=>[]];$fixtureRegistry=repairFixtureRegistry();$fixtureRegistry['appleservice39']['project']['sales_funnel']=$s;ProjectMaxConnection::resetForTests($fixtureRegistry);$connection=ProjectMaxConnection::resolve('appleservice39');$entry=new ProjectFunnelEntry($pdo,$connection,$s);
funnelCheck('eight approved offers keep their established feed IDs',count($s['posts'])===8&&$s['posts']['menu']['offer_id']==='as39-search-v1-menu');
funnelCheck('missing company analytics stays explicitly off',$s['metrika']===['counter_id'=>'','mode'=>'off','goals'=>[]]&&ProjectFunnelSettings::publicClient($s)['counter_id']==='');
$post='service-display';$offer=$s['posts'][$post]['offer_id'];$start='18446744073709551614_region_213_campaign_123_ctx1_'.ProjectFunnelLaunch::context($post,$offer);
$raw=funnelSigned($start,$now);$launch=ProjectFunnelLaunch::verify($raw,'synthetic-apple-token',$now);
funnelCheck('signature preserves large click and opaque post IDs',$launch['external_user_id']==='7001'&&$launch['payload']['yclid']==='18446744073709551614'&&$launch['payload']['post_id']===$post);
funnelCheck('reordered signed pairs preserve one visit',$launch['visit_id']===ProjectFunnelLaunch::verify(implode('&',array_reverse(explode('&',$raw))),'synthetic-apple-token',$now)['visit_id']);
foreach(['wrong token'=>fn()=>ProjectFunnelLaunch::verify($raw,'other-token',$now),'tampered identity'=>fn()=>ProjectFunnelLaunch::verify(str_replace('7001','7002',$raw),'synthetic-apple-token',$now),'expired signature'=>fn()=>ProjectFunnelLaunch::verify($raw,'synthetic-apple-token',$now+3601),'future signature'=>fn()=>ProjectFunnelLaunch::verify($raw,'synthetic-apple-token',$now-61),'duplicate key'=>fn()=>ProjectFunnelLaunch::verify($raw.'&auth_date='.$now,'synthetic-apple-token',$now),'conflicting signed starts'=>fn()=>ProjectFunnelLaunch::verify(funnelSigned($start,$now,'synthetic-apple-token','7001',['startapp'=>'post1_menu']),'synthetic-apple-token',$now)]as$name=>$call)funnelCheck($name.' rejected',funnelReject($call));
foreach(['{yclid}_region_213_campaign_123_ctx1_abc','0_region_213_campaign_123_ctx1_abc','post1_https://evil.test','1_region_1_campaign_1_ctx1_eyJwb3N0X2lkIjoiYSJ9',str_repeat('a',513)]as$bad)funnelCheck('invalid or unexpanded start rejected '.substr($bad,0,24),funnelReject(fn()=>ProjectFunnelLaunch::parse($bad)));
$input=['init_data'=>$raw,'start_param'=>$start,'client_id'=>''];
$legacySettings=$s;unset($legacySettings['feed_presentation']);
$capture=(new ProjectFunnelEntry($pdo,$connection,$legacySettings))->capture($input,$now);
funnelCheck('redirect acknowledges a durable exact post observation',$capture['saved']===true&&$capture['mode']==='advertising'&&$capture['redirect_url']==='https://max.ru/channel_appleservice39ru/AaG9bRUxHcg'&&!$capture['analytics_bound']&&(int)$pdo->query('SELECT COUNT(*) FROM advertising_visit_observations')->fetchColumn()===1);
$again=$entry->capture($input,$now+1);funnelCheck('retry and missing CID reuse committed observation',$again['receipt']['reused']===true&&$again['receipt']['cursor']===$capture['receipt']['cursor']);
$savedContext=$pdo->query('SELECT context_json FROM advertising_visit_observations ORDER BY id LIMIT 1')->fetchColumn();
$changedPresentation=$s;$changedPresentation['feed_presentation'][$post]=['title'=>'Короткое название','description'=>'Проверенное рекламное описание.','image_url'=>'https://example.test/reviewed-ad.png'];
$changedPresentation=ProjectFunnelSettings::validate($changedPresentation,'appleservice39','id390521129062_bot');
funnelCheck('advertising copy and image changes keep the original launch receipt and profile',
    (new ProjectFunnelEntry($pdo,$connection,$changedPresentation))->capture($input,$now+1)['receipt']['reused']===true
    &&$pdo->query('SELECT context_json FROM advertising_visit_observations ORDER BY id LIMIT 1')->fetchColumn()===$savedContext
    &&(int)$pdo->query('SELECT COUNT(*) FROM advertising_visit_observations')->fetchColumn()===1);
$changedIdentity=$s;$changedIdentity['posts'][$post]['title']='Changed publication identity';
funnelCheck('publication identity changes still reject an old launch retry',funnelReject(fn()=>(new ProjectFunnelEntry($pdo,$connection,$changedIdentity))->capture($input,$now+1)));
$changedSettings=$s;$changedSettings['metrika']['goals']=['working'=>'future_company_goal'];
funnelCheck('unrelated conversion settings cannot break a saved redirect retry',(new ProjectFunnelEntry($pdo,$connection,$changedSettings))->capture($input,$now+1)['receipt']['reused']===true);
$enriched=$entry->capture(array_replace($input,['client_id'=>'17929400001234567890']),$now+2);
funnelCheck('late CID appends one immutable enrichment',$enriched['receipt']['cursor']==='2'&&(int)$pdo->query('SELECT COUNT(*) FROM advertising_visit_observations')->fetchColumn()===2&&$pdo->query('SELECT client_id FROM advertising_visit_observations ORDER BY id LIMIT 1')->fetchColumn()==='');
funnelCheck('late missing CID never erases enrichment',$entry->capture($input,$now+3)['receipt']['cursor']==='2');
funnelCheck('a changed CID cannot rewrite one launch',funnelReject(fn()=>$entry->capture(array_replace($input,['client_id'=>'999']),$now+3)));
funnelCheck('unsigned start hint cannot select another publication',funnelReject(fn()=>$entry->capture(array_replace($input,['start_param'=>'post1_menu']),$now)));
funnelCheck('request cannot inject a destination or counter',funnelReject(fn()=>$entry->capture($input+['redirect_url'=>'https://evil.test'],$now)));
funnelCheck('unknown offer is rejected before capture',funnelReject(fn()=>$entry->destination($post,'foreign-offer')));
$organic=$entry->capture(['init_data'=>funnelSigned('post1_menu',$now),'start_param'=>'post1_menu','client_id'=>''],$now);
funnelCheck('organic launch opens verified post without an ad observation',$organic['saved']===false&&$organic['mode']==='publication'&&(int)$pdo->query('SELECT COUNT(*) FROM advertising_visit_observations')->fetchColumn()===2);
$default=$entry->capture(['init_data'=>funnelSigned('',$now),'start_param'=>'','client_id'=>''],$now);
funnelCheck('normal miniapp launch resolves configured menu',$default['post_id']==='menu'&&$default['saved']===false);
$csv=$entry->feed();$lines=array_map('str_getcsv',explode("\n",trim($csv)));
funnelCheck('feed has eight stable unique offers and literal Direct macros',count($lines)===9&&count(array_unique(array_column(array_slice($lines,1),0)))===8&&substr_count($csv,'?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_')===8);
funnelCheck('feed keeps approved advertising images and no invented price',$lines[1][0]==='as39-search-v1-menu'&&$lines[1][2]===$s['feed_presentation']['menu']['image_url']&&$lines[1][5]===''&&$lines[1][6]==='');
$pdo->exec("UPDATE appleservice39_pilot_operations SET state='unknown' WHERE post_key='menu'");
funnelCheck('unverified publication prevents feed and redirect',funnelReject(fn()=>$entry->feed())&&funnelReject(fn()=>$entry->destination('menu')));$pdo->exec("UPDATE appleservice39_pilot_operations SET state='verified' WHERE post_key='menu'");
// Pending new cards are withheld; invalid verified evidence and DB errors still fail closed.
$expandedSettings['metrika']=['counter_id'=>'','mode'=>'off','goals'=>[]];
$expandedEntry=new ProjectFunnelEntry($pdo,$connection,$expandedSettings);
$insertPublication=$pdo->prepare('INSERT INTO appleservice39_pilot_operations VALUES(?,?,?,?,?,?,?)');
$added=[];
foreach($expandedSettings['posts'] as$id=>$meta){
    if(in_array($id,$oldOfferIds,true)||($meta['withhold_until_verified']??false))continue;
    $insertPublication->execute([$id,'appleservice39-pilot-v1','create','verified',1,'fixture_'.$id,'https://max.ru/channel_appleservice39ru/fixture_'.$id]);$added[]=$id;
}
$staged=$expandedEntry->feed();
funnelCheck('staged coverage exposes 30 verified originals while 37 coverage and three device cards wait',
    count(array_map('str_getcsv',explode("\n",trim($staged))))===31
    &&str_contains($staged,'as39-search-v1-menu')&&str_contains($staged,'service-battery-replacement')
    &&str_contains($staged,'service-front-glass-replacement')
    &&funnelReject(fn()=>$expandedEntry->destination('model-iphone-8')));
foreach($expandedSettings['posts'] as$id=>$meta){
    if(!($meta['withhold_until_verified']??false)||isset($deviceOffers[$id]))continue;
    $insertPublication->execute([$id,'appleservice39-pilot-v1','create','verified',1,'fixture_'.$id,'https://max.ru/channel_appleservice39ru/fixture_'.$id]);$added[]=$id;
}
$full=$expandedEntry->feed();$fullRows=array_map('str_getcsv',explode("\n",trim($full)));
funnelCheck('all67 own verified offers use unique miniapp contexts and literal Direct macros',
    count($fullRows)===68&&count(array_unique(array_column(array_slice($fullRows,1),0)))===67
    &&substr_count($full,'https://max.ru/id390521129062_bot?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_')===67
    &&count(array_filter(array_slice($fullRows,1),static fn($r)=>$r[5]!==''||$r[6]!==''))===0);
// Accepted tour-max CSV ef25b04a.../Git blob096c5e89, read back on16Nov2026.
// Compare parsed rows so ordinary CSV enclosure choices cannot conceal content drift.
funnelCheck('all67 upstream advertising rows exactly match the accepted public feed',
    hash('sha256',json_encode($fullRows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))
    ==='52c30732dcf4a0f7283c3585c0fe20ba9cb5a1fd967557eebe7895c725065aab');
// Offline receipt fixtures reuse the accepted native addresses; no publication or bot call occurs.
foreach($deviceOffers as$id=>$expected){
    $meta=$expandedSettings['posts'][$id];
    funnelCheck($id.' waits for its own verified create receipt',
        $meta['owner']==='appleservice39_pilot'&&$meta['withhold_until_verified']===true
        &&$meta['offer_id']===$expected['offer_id']&&!str_contains($full,$expected['offer_id'])
        &&funnelReject(fn()=>$expandedEntry->destination($id,$expected['offer_id'])));
    $insertPublication->execute([$id,'appleservice39-pilot-v1','create','unknown',1,$expected['mid'],
        'https://max.ru/channel_appleservice39ru/'.$expected['mid']]);$added[]=$id;
    funnelCheck($id.' unknown publication cannot redirect',funnelReject(fn()=>$expandedEntry->destination($id)));
}
funnelCheck('unknown device receipts leave all 67 historical feed rows unchanged',$expandedEntry->feed()===$full);
$pdo->exec("UPDATE appleservice39_pilot_operations SET state='verified' WHERE post_key='device-ipad'");
$partial=$expandedEntry->feed();
funnelCheck('one verified device is exported without waiting for the other two',
    count(explode("\n",trim($partial)))===69&&str_contains($partial,$deviceOffers['device-ipad']['offer_id'])
    &&!str_contains($partial,$deviceOffers['device-macbook']['offer_id'])
    &&!str_contains($partial,$deviceOffers['device-apple-watch']['offer_id']));
$verifyDevice=$pdo->prepare("UPDATE appleservice39_pilot_operations SET state='verified' WHERE post_key=?");
foreach(array_keys($deviceOffers) as$id)$verifyDevice->execute([$id]);
$withDevices=$expandedEntry->feed();$deviceRows=array_map('str_getcsv',explode("\n",trim($withDevices)));
funnelCheck('three verified device offers extend the unchanged 67 to 70 without prices',
    count($deviceRows)===71&&array_slice($deviceRows,0,68)===$fullRows
    &&count(array_unique(array_column(array_slice($deviceRows,1),0)))===70
    &&substr_count($withDevices,'https://max.ru/id390521129062_bot?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_')===70
    &&count(array_filter(array_slice($deviceRows,1),static fn($r)=>$r[5]!==''||$r[6]!==''))===0);
$deviceRowsByOffer=array_column(array_slice($deviceRows,1),null,0);
$telegramSource=fopen(dirname(__DIR__).'/resources/appleservice39/telegram-offers-v1.csv','r');
$telegramHeader=fgetcsv($telegramSource);$telegramRows=[];
while(($telegramRow=fgetcsv($telegramSource))!==false)$telegramRows[$telegramRow[0]]=array_combine($telegramHeader,$telegramRow);
fclose($telegramSource);
foreach($deviceOffers as$id=>$expected){
    $row=$deviceRowsByOffer[$expected['offer_id']];$telegramRow=$telegramRows['as39-tg-'.$id];
    parse_str((string)parse_url($row[1],PHP_URL_QUERY),$deviceQuery);
    $deviceLaunch=ProjectFunnelLaunch::parse(strtr($deviceQuery['startapp'],[
        '{yclid}'=>'123','{region_id}'=>'39','{campaign_id}'=>'456']));
    $deviceContext=AppleService39PilotCatalog::landingEntryForPost($id);
    funnelCheck($id.' keeps the accepted copy image and exact native destination',
        $row[2]===$telegramRow['Image']&&$row[3]===$telegramRow['Title']&&$row[4]===$telegramRow['Description']
        &&array_slice($row,7)===$expandedSettings['posts'][$id]['labels']
        &&$deviceLaunch['post_id']===$id&&$deviceLaunch['offer_id']===$expected['offer_id']
        &&$expandedEntry->destination($id,$expected['offer_id'])['url']==='https://max.ru/channel_appleservice39ru/'.$expected['mid']);
    funnelCheck($id.' keeps its existing model and service in the bot context',
        $deviceContext['model']===$expected['model']&&$deviceContext['service']===$expected['service']
        &&$deviceContext['scope']==='device'&&$deviceContext['start_payload']==='as39_'.$id);
}
$pdo->exec("UPDATE appleservice39_pilot_operations SET public_url='https://max.ru/foreign/borrowed' WHERE post_key='model-iphone-8'");
funnelCheck('withholding never conceals a corrupt verified publication',funnelReject(fn()=>$expandedEntry->feed()));
$pdo->exec("UPDATE appleservice39_pilot_operations SET public_url='https://max.ru/channel_appleservice39ru/fixture_model-iphone-8' WHERE post_key='model-iphone-8'");
$pdo->exec('ALTER TABLE appleservice39_pilot_operations RENAME TO unavailable_publications');
try{funnelCheck('withholding never hides a publication database failure',funnelReject(fn()=>$expandedEntry->feed()));}
finally{$pdo->exec('ALTER TABLE unavailable_publications RENAME TO appleservice39_pilot_operations');}
$deletePublication=$pdo->prepare('DELETE FROM appleservice39_pilot_operations WHERE post_key=?');
foreach($added as$id)$deletePublication->execute([$id]);
$badWithholding=$expandedSettings;$badWithholding['posts']['model-iphone-8']['withhold_until_verified']='yes';
funnelCheck('withholding requires an explicit typed configuration flag',
    funnelReject(fn()=>ProjectFunnelSettings::validate($badWithholding,'appleservice39','id390521129062_bot')));
$presentationFaults=[
    'non-map'=>static function(array &$v):void{$v['feed_presentation']='invalid';},
    'unregistered post'=>static function(array &$v):void{$v['feed_presentation']['foreign']=$v['feed_presentation']['menu'];},
    'routing override'=>static function(array &$v):void{$v['feed_presentation']['menu']['offer_id']='another-offer';},
    'missing field'=>static function(array &$v):void{unset($v['feed_presentation']['menu']['description']);},
    'long title'=>static function(array &$v):void{$v['feed_presentation']['menu']['title']=str_repeat('Я',57);},
    'long description'=>static function(array &$v):void{$v['feed_presentation']['menu']['description']=str_repeat('Я',82);},
    'markup'=>static function(array &$v):void{$v['feed_presentation']['menu']['description']='<b>Ad</b>';},
    'unsafe image'=>static function(array &$v):void{$v['feed_presentation']['menu']['image_url']='https://user:pass@example.test/image.png';},
];
foreach($presentationFaults as$name=>$mutate){$bad=$expandedSettings;$mutate($bad);funnelCheck('invalid feed presentation rejected: '.$name,funnelReject(fn()=>ProjectFunnelSettings::validate($bad,'appleservice39','id390521129062_bot')));}

$before=$pdo->query("SELECT * FROM conversations WHERE id=100")->fetch();
$sent=[];$factory=static function(ProjectMaxConnection $c)use(&$sent){return new MaxMessengerAdapter(static function($chat,$text)use(&$sent){$sent[]=$text;return 'synthetic-funnel-'.count($sent);},null,'system',null,false);};
$result=RepairMaxWebhookService::handle('appleservice39',str_repeat('a',32),repairStart(7001,'as39_service-display'),$factory,static function(){});
$c=repairConversation($pdo,'appleservice39',7001);
funnelCheck('native bot entry keeps the ordinary immediate manager handoff',$result['status']===200&&$c['status']==='waiting_manager');
$e=$pdo->query("SELECT payload_json FROM conversation_events WHERE conversation_id=".$c['id']." AND event_type='project_funnel_entry'")->fetchColumn();$linked=json_decode($e,true);
funnelCheck('manager context matches exact post user and most complete receipt',$linked['post_id']===$post&&$linked['attribution_state']==='matched'&&$linked['observations'][0]['source_cursor']==='2');
$view=ProjectFunnelConversation::managerContext($pdo,['id'=>(int)$c['id'],'project_key'=>'appleservice39','channel'=>'max']);
funnelCheck('manager sees native source without private click or client IDs',$view['post_url']===$capture['redirect_url']&&!str_contains(json_encode($view),'17929400001234567890')&&!str_contains(json_encode($view),'18446744073709551614'));
$counts=[$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn(),count($sent)];RepairMaxWebhookService::handle('appleservice39',str_repeat('a',32),repairStart(7001,'as39_service-display'),$factory,static function(){});
funnelCheck('provider replay makes no duplicate queue event or greeting',$counts===[$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn(),count($sent)]);
$secondStart='123_region_213_campaign_123_ctx1_'.ProjectFunnelLaunch::context($post,$offer);$entry->capture(['init_data'=>funnelSigned($secondStart,$now),'start_param'=>$secondStart,'client_id'=>'999'],$now);
$pdo->beginTransaction();$ambiguous=ProjectFunnelConversation::entry($pdo,$connection,(int)$c['id'],'7001',$s['source_key'],$post,'second-native-start',$now+5);$pdo->commit();
funnelCheck('several paid visits stay ambiguous instead of guessing latest',$ambiguous['attribution_state']==='ambiguous'&&$ambiguous['observations']===[]);
$pdo->beginTransaction();$unrelated=ProjectFunnelConversation::entry($pdo,$connection,(int)$c['id'],'7001',$s['source_key'],'menu','other-post',$now+5);$pdo->commit();
funnelCheck('a visit to another offer cannot credit the menu',$unrelated['attribution_state']==='unattributed');
funnelCheck('AnyTour same user and assignment remain exactly unchanged',$before===$pdo->query('SELECT * FROM conversations WHERE id=100')->fetch());
// A different business uses the same runtime and the canonical publication registry.
$registry=new ChannelPostRegistryService($pdo);$config=['project_key'=>'repair_two','platform'=>'max','bot_key'=>'id999999999002_bot','channel_id'=>'-12345678','source_key'=>'max:repair_two-main','display_name'=>'Another business','bot_url'=>'https://max.ru/id999999999002_bot','is_enabled'=>true];$registry->configureChannel($config);
$pub=$registry->registerPublication('repair_two','max','id999999999002_bot','-12345678','SyntheticPost',['text'=>'Reviewed service','title'=>'Another service','post_url'=>'https://max.ru/another_business/SyntheticPost']);
$registry->confirmObservedButton($pub['publication_id'],$pub['version_id'],['button_verified'=>true,'content_preserved'=>true]);
$other=array_replace($s,['project_key'=>'repair_two','bot_key'=>'id999999999002_bot','source_key'=>'max:repair_two-main','channel_id'=>'-12345678','channel_url'=>'https://max.ru/another_business','default_post'=>'service','posts'=>['service'=>['owner'=>'channel_post_registry','publication_id'=>$pub['publication_id'],'offer_id'=>'another-offer','title'=>'Another service','description'=>'Reviewed description','image_url'=>'https://example.test/image.png']]]);
unset($other['feed_presentation']);
$other=ProjectFunnelSettings::validate($other,'repair_two','id999999999002_bot');$shared=new ProjectFunnelEntry($pdo,ProjectMaxConnection::resolve('repair_two'),$other);
funnelCheck('second business launches by configuration without copying logic',$shared->destination('service')['url']==='https://max.ru/another_business/SyntheticPost'&&str_contains($shared->feed(),'https://max.ru/id999999999002_bot?startapp='));
$otherPresentation=$other;$otherPresentation['feed_presentation']=['service'=>['title'=>'Another ad','description'=>'Approved ad copy.','image_url'=>'https://example.test/ad.png']];
$otherPresentation=ProjectFunnelSettings::validate($otherPresentation,'repair_two','id999999999002_bot');
$otherFeed=array_map('str_getcsv',explode("\n",trim((new ProjectFunnelEntry($pdo,ProjectMaxConnection::resolve('repair_two'),$otherPresentation))->feed())));
funnelCheck('another business uses the same presentation contract with its own routing',
    $otherFeed[1][0]==='another-offer'&&$otherFeed[1][2]==='https://example.test/ad.png'&&$otherFeed[1][3]==='Another ad'
    &&$otherFeed[1][4]==='Approved ad copy.'&&str_contains($otherFeed[1][1],'https://max.ru/id999999999002_bot?startapp=')
    &&$shared->destination('service')['title']==='Another service');
funnelCheck('second bot cannot validate first company launch',funnelReject(fn()=>$shared->capture($input,$now)));
$other['handoff_mode']='manager_first';$other['brand']='Стоматология — тест';$r=repairFixtureRegistry();$r['repair_two']['project']['business']=['kind'=>'dental_clinic'];$r['repair_two']['project']['brand']['name']=$other['brand'];$r['repair_two']['project']['sales_funnel']=$other;$r['repair_two']['project']['messenger']['max']['webhook_url']='https://app.anytoour.ru/project_max_webhook.php?project=repair_two';ProjectMaxConnection::resetForTests($r);
$genericSent=[];$genericFactory=static function(ProjectMaxConnection $c)use(&$genericSent){return new MaxMessengerAdapter(static function($chat,$text)use(&$genericSent){$genericSent[]=$text;return 'synthetic-clinic-reply';},null,'system',null,false);};
$generic=RepairMaxWebhookService::handle('repair_two',str_repeat('b',32),repairStart(7002,$pub['start_payload']),$genericFactory,static function(){});
$genericConversation=repairConversation($pdo,'repair_two',7002);
funnelCheck('unrelated business reaches the same manager flow by settings',$generic['status']===200&&$genericConversation['status']==='waiting_manager'&&count($genericSent)===1&&str_contains($genericSent[0],'что вас интересует')&&!str_contains($genericSent[0],'устройства'));
funnelCheck('generic business keeps publication context without a repair diagnosis',(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=".$genericConversation['id']." AND event_type='project_funnel_entry'")->fetchColumn()===1&&!isset(json_decode($genericConversation['metadata_json'],true)['repair_context']));
$other['posts']['service']['publication_id']=9999;funnelCheck('configuration cannot invent an unregistered publication',funnelReject(fn()=>(new ProjectFunnelEntry($pdo,ProjectMaxConnection::resolve('repair_two'),$other))->destination('service')));
echo 'PROJECT FUNNEL REGRESSION PASSED '.$passed.PHP_EOL;
