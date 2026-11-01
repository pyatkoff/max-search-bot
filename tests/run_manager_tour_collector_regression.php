<?php
declare(strict_types=1);
require_once __DIR__.'/support/manager-tour-collector.php';

/** Actual collector/session entry in an isolated app; never a real login or server. */
function collectorAuthenticationHttpScenarios(): void
{
    $root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/collector-auth-http-'.bin2hex(random_bytes(8));$server=null;
    $sources=['manager/tour-selection-collector.php','manager/lib/ManagerHttp.php','services/ManagerRequestContext.php',
        'manager/assets/tour-selection-collector.css','manager/assets/manager-http-client.js','manager/assets/tour-selection-collector.js'];
    $stubs=['ManagerAuthService','ManagerConversationAccessPolicy','ManagerTourSelectionEditorHost'];
    try {
        foreach ([$tmp,$tmp.'/manager',$tmp.'/manager/lib',$tmp.'/manager/assets',$tmp.'/services',$tmp.'/sessions'] as $directory) mkdir($directory,0700);
        foreach ($sources as $path) copy($root.'/'.$path,$tmp.'/'.$path);
        deliveryCheck('collector HTTP exercises exact endpoint and canonical session source bytes',array_reduce($sources,static fn(bool $same,string $path):bool=>$same&&hash_file('sha256',$root.'/'.$path)===hash_file('sha256',$tmp.'/'.$path),true));
        file_put_contents($tmp.'/config.php',<<<'PHP'
<?php
final class ManagerAuthService {
    public static function byId(int $id): ?array {return $id===7?['id'=>7,'role'=>'manager']:null;}
    public static function isAdmin(array $manager): bool {return false;}
}
final class ManagerConversationAccessPolicy {}
final class ManagerTourSelectionEditorHost {
    public static function entry(): ?string {file_put_contents(__DIR__.'/editor-entry-read','read',FILE_APPEND);return null;}
}
PHP);
        foreach ($stubs as $name) file_put_contents($tmp.'/services/'.$name.'.php','<?php');
        file_put_contents($tmp.'/manager/fixture-session.php',<<<'PHP'
<?php
require dirname(__DIR__).'/config.php';require __DIR__.'/lib/ManagerHttp.php';ManagerHttp::startJson();
$_SESSION['manager_id']=(int)($_GET['id']??0);$_SESSION['csrf']='fixture-private-csrf';ManagerHttp::respond(['ok'=>true]);
PHP);
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Collector loopback port unavailable');
        $address=(string)stream_socket_get_name($socket,false);fclose($socket);
        $server=proc_open([PHP_BINARY,'-d','session.save_path='.$tmp.'/sessions','-d','display_errors=0','-S',$address,'-t',$tmp],
            [0=>['pipe','r'],1=>['file',$tmp.'/server.log','a'],2=>['file',$tmp.'/server.log','a']],$pipes);
        if(!is_resource($server))throw new RuntimeException('Cannot start collector HTTP fixture');fclose($pipes[0]);
        $ready=false;for($i=0;$i<100;$i++){if($probe=@stream_socket_client('tcp://'.$address,$e,$s,.05)){fclose($probe);$ready=true;break;}usleep(10000);}
        if(!$ready)throw new RuntimeException('Collector HTTP fixture did not start');
        $request=static function(string $path,string $cookie='')use($address):array {
            $context=stream_context_create(['http'=>['method'=>'GET','header'=>$cookie!==''?'Cookie: '.$cookie:'','ignore_errors'=>true,'follow_location'=>0,'timeout'=>3]]);
            $body=(string)file_get_contents('http://'.$address.$path,false,$context);$headers=$http_response_header??[];
            preg_match('/^HTTP\/\S+\s+([0-9]+)/',$headers[0]??'',$match);$nextCookie='';
            foreach($headers as $header)if(preg_match('/^Set-Cookie:\s*(anytour_manager_panel=[^;]+)/i',$header,$cookieMatch))$nextCookie=$cookieMatch[1];
            return ['status'=>(int)($match[1]??0),'headers'=>implode("\n",$headers),'body'=>$body,'cookie'=>$nextCookie];
        };
        $path='/manager/tour-selection-collector.php?conversation_id=11';
        $expiredCookie=$request('/manager/fixture-session.php?id=99')['cookie'];
        deliveryCheck('collector expired identity uses the canonical isolated session cookie',$expiredCookie!=='');
        foreach (['anonymous'=>'','expired'=>$expiredCookie,'expired repeat'=>$expiredCookie] as $state=>$cookie) {
            $response=$request($path,$cookie);
            deliveryCheck('collector '.$state.' stays on the exact entry without an automatic redirect',$response['status']===401&&!preg_match('/^Location:/im',$response['headers']));
            deliveryCheck('collector '.$state.' auth response cannot be cached or indexed',stripos($response['headers'],'Cache-Control:')!==false&&str_contains(strtolower($response['headers']),'no-store')&&stripos($response['headers'],'Referrer-Policy: no-referrer')!==false&&stripos($response['headers'],'X-Robots-Tag: noindex')!==false);
            deliveryCheck('collector '.$state.' offers explicit same-tab Manager2 login',preg_match('~<a\b[^>]*href=["\']/manager2/["\'][^>]*>~i',$response['body'],$link)===1&&!preg_match('/\btarget\s*=\s*["\']_blank/i',$link[0])&&str_contains($response['body'],'Manager2')&&preg_match('/войти|войдите/ui',$response['body'])&&preg_match('/вернит|открой|обнов/ui',$response['body']));
            deliveryCheck('collector '.$state.' cannot run scripts or expose authenticated state',!preg_match('/<script\b|\bon[a-z]+\s*=|javascript:|http-equiv\s*=\s*["\']?refresh/i',$response['body'])&&!str_contains($response['body'],'collectorConfig')&&!str_contains($response['body'],'fixture-private-csrf')&&!is_file($tmp.'/editor-entry-read'));
        }
        $activeCookie=$request('/manager/fixture-session.php?id=7')['cookie'];$response=$request($path,$activeCookie);
        preg_match('~<script id="collectorConfig" type="application/json">(.*?)</script>~s',$response['body'],$configMatch);$config=json_decode($configMatch[1]??'',true);
        deliveryCheck('collector authenticated control still renders the real protected shell',$response['status']===200&&!preg_match('/^Location:/im',$response['headers'])&&($config['actor']??null)===7&&($config['conversation_id']??null)===11&&($config['csrf']??null)==='fixture-private-csrf'&&str_contains($response['body'],'id="collectorConnect"')&&is_file($tmp.'/editor-entry-read'));
    } finally {
        if(is_resource($server)){proc_terminate($server);proc_close($server);}
        foreach ([...$sources,...array_map(static fn(string $name):string=>'services/'.$name.'.php',$stubs),'config.php','manager/fixture-session.php','server.log','editor-entry-read'] as $path) if(is_file($tmp.'/'.$path)||is_link($tmp.'/'.$path))unlink($tmp.'/'.$path);
        foreach (glob($tmp.'/sessions/*')?:[] as $file) if(is_file($file)||is_link($file))unlink($file);
        foreach (['manager/lib','manager/assets','manager','services','sessions',''] as $directory) if(is_dir($tmp.($directory!==''?'/'.$directory:'')))rmdir($tmp.($directory!==''?'/'.$directory:''));
    }
}
collectorAuthenticationHttpScenarios();
if(PHP_SAPI==='cli'&&($argv[1]??'')==='--auth-http-only'){echo "COLLECTOR AUTH HTTP {$checks} PASS\n";return;}

$pdo=deliveryDb();collectorSchema($pdo);collectorScenarios($pdo);
require_once dirname(__DIR__).'/services/ManagerTourSelectionShareService.php';
$pdo=deliveryDb();collectorSchema($pdo);
$pdo->exec('CREATE TABLE manager_tour_selection_links(conversation_id INTEGER PRIMARY KEY,version INTEGER,token TEXT UNIQUE,publication_id TEXT,is_active INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);CREATE TABLE manager_tour_selection_link_history(conversation_id INTEGER,version INTEGER,publication_id TEXT,is_active INTEGER,manager_id INTEGER,created_at TEXT,PRIMARY KEY(conversation_id,version));');
$session=str_repeat('1',64);$device=str_repeat('2',32);$connection=str_repeat('3',32);
$view=ManagerTourSelectionCollectorSession::view(7,1,$session,$device);
ManagerTourSelectionCollectorSession::connect(7,1,$session,['device_id'=>$device,'connection_id'=>$connection,'binding'=>$view['target']['binding'],'confirm_current_trip'=>true]);
$observation=collectorObservation();$command=['operation_id'=>'collector-publication-001','connection_id'=>$connection,'observation'=>$observation];
ManagerTourSelectionService::import(7,1,$session,$command);
$draft=ManagerTourSelectionService::get(7,1)['selection']['draft'];$draft['offers'][0]['photos']=deliveryDraft()['offers'][0]['photos'];$draft['offers'][0]['amount']='210000';
deliverySave($draft,1);$built=ManagerTourSelectionPublicationDraft::build($draft);$without=$draft;unset($without['offers'][0]['import_source']);$manual=ManagerTourSelectionPublicationDraft::build($without);
deliveryCheck('private provenance does not alter native snapshot presentation or creation fence',$built['ok']&&$built['offers'][0]['snapshot']===$manual['offers'][0]['snapshot']&&$built['offers'][0]['presentation']===$manual['offers'][0]['presentation']&&$built['offers'][0]['import_source']['fields']['amount']==='200000');
$prepared=ManagerTourSelectionDeliveryService::prepare(7,1,2);$publication=$prepared['publication']['id'];
$q=$pdo->prepare('SELECT selection_json FROM manager_tour_selection_publications WHERE publication_id=?');$q->execute([$publication]);$frozen=$q->fetchColumn();
$token=str_repeat('f',64);$pdo->prepare('INSERT INTO manager_tour_selection_links VALUES(?,?,?,?,?,?,?)')->execute([1,1,$token,$publication,1,7,gmdate('Y-m-d H:i:s')]);
$page=ManagerTourSelectionShareService::read($token);$json=json_encode($page,JSON_THROW_ON_ERROR);
deliveryCheck('public projection excludes raw source quote external IDs original price and exact ages',!str_contains($json,'import_source')&&!str_contains($json,'quote_id')&&!str_contains($json,'200000')&&!str_contains($json,'b2b.fstravel.com')&&!str_contains($json,'child_ages')&&$page['offers'][0]['amount']==='210000');
$room=$observation;$room['keys']['room']='21';$room['quote_id']='0x'.str_repeat('b',64);$room['offer']['room']=$room['raw']['room']='Family / 2AD';
ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-publication-002','observation'=>$room]));
$q->execute([$publication]);
deliveryCheck('later imports preserve existing public URL and immutable shown version',ManagerTourSelectionShareService::read($token)===$page&&$q->fetchColumn()===$frozen&&ManagerTourSelectionShareService::get(7,1)['share']['path']==='/selection/?t='.$token);
deliveryCheck('import and preview still make no native create or confirmed tourist choice',(int)$pdo->query("SELECT COUNT(*) FROM messages WHERE direction='outbound'")->fetchColumn()===0&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='tour_offer_liked'")->fetchColumn()===0);

// Automatic gallery material enriches the exact source quote; it is not identity.
$photoBase='https://apigate-tui.fstravel.com/api/geocontent/static/Hotel/00180000-ac11-0242-6c05-08d9934f9e4b/';
$auto=collectorObservation();$auto['photos']=array_map(static fn(string $kind):string=>$photoBase.$kind.'-Source-automatic.jpg',['MainPhoto','Gallery','InsideOfTheRoom']);
$normalized=ManagerTourSelectionImportDraft::normalize($auto);$legacy=ManagerTourSelectionImportDraft::normalize(collectorObservation());
deliveryCheck('automatic source photos enter the editable offer and private provenance',$normalized!==null&&count($normalized['offer']['photos'])===3&&ManagerTourSelectionImportDraft::source($normalized)['photos']===$auto['photos']);
deliveryCheck('photo enrichment does not change exact terms or price fingerprints',ManagerTourSelectionImportDraft::fingerprint(ManagerTourSelectionImportDraft::source($normalized))===ManagerTourSelectionImportDraft::fingerprint(ManagerTourSelectionImportDraft::source($legacy))&&!isset($legacy['photos']));
foreach ([['wrong'=>$auto['photos'][0]],[$auto['photos'][0],$auto['photos'][0]],array_merge($auto['photos'],[$auto['photos'][0]])] as $photos){$bad=$auto;$bad['photos']=$photos;deliveryCheck('malformed duplicate or oversized automatic gallery is refused',ManagerTourSelectionImportDraft::normalize($bad)===null);}
foreach (['?token=secret','?%74oken=secret','#fragment'] as $suffix){$bad=$auto;$bad['photos'][0].=$suffix;deliveryCheck('automatic gallery does not persist credentials query or fragment',ManagerTourSelectionImportDraft::normalize($bad)===null);}
$bad=$auto;$bad['photos'][1]=str_replace('00180000','00280000',$bad['photos'][1]);deliveryCheck('mixed FUNSUN hotel UUID gallery is refused',ManagerTourSelectionImportDraft::normalize($bad)===null);
$missingPhotos=$auto;$missingPhotos['photos']=[];$missingImport=ManagerTourSelectionImportDraft::normalize($missingPhotos);deliveryCheck('unavailable automatic gallery is explicit without fabricated photos',$missingImport!==null&&$missingImport['photos']===[]&&$missingImport['offer']['photos']===[]);
// Same public synthetic FUNSUN observation, with a native English weekday label.
// Source quote, keys, dates, party, price and gallery remain the existing fixture's.
$englishDate=$auto;$englishDate['raw']['date']='05.06.2027, Sat';
$englishNormalized=ManagerTourSelectionImportDraft::normalize($englishDate);
deliveryCheck('FUNSUN English weekday normalizes without rewriting the captured date or terms',
    $englishNormalized!==null&&$englishNormalized['raw']['date']===$englishDate['raw']['date']
    &&$englishNormalized['offer']===$normalized['offer']&&$englishNormalized['keys']===$normalized['keys']
    &&$englishNormalized['quote_id']===$normalized['quote_id']&&$englishNormalized['url']===$normalized['url']
    &&$englishNormalized['photos']===$auto['photos']);
foreach (['2027-06-07'=>'Mon','2027-06-08'=>'Tue','2027-06-09'=>'Wed','2027-06-10'=>'Thu','2027-06-11'=>'Fri','2027-06-12'=>'Sat','2027-06-13'=>'Sun'] as $iso=>$weekday) {
    $withWeekday=$englishDate;$withWeekday['offer']['date_from']=$iso;
    $withWeekday['url']=str_replace('20270605',str_replace('-','',$iso),$withWeekday['url']);
    $withWeekday['raw']['date']=substr($iso,8,2).'.'.substr($iso,5,2).'.'.substr($iso,0,4).', '.$weekday;
    $read=ManagerTourSelectionImportDraft::normalize($withWeekday);
    deliveryCheck('FUNSUN exact English '.$weekday.' label retains its matching numeric date',
        $read!==null&&$read['offer']['date_from']===$iso&&$read['raw']['date']===$withWeekday['raw']['date']);
}
foreach (['05.06.2027, сб','05.06.2027'] as $label) {
    $legacyDate=$englishDate;$legacyDate['raw']['date']=$label;
    $read=ManagerTourSelectionImportDraft::normalize($legacyDate);
    deliveryCheck('FUNSUN existing Russian or absent weekday remains unchanged',
        $read!==null&&$read['raw']['date']===$label&&$read['offer']['date_from']==='2027-06-05');
}
foreach (['05.06.2027, Foo','05.06.2027, Saturday','05.06.2027, SAT','05.06.2027, Sat extra','06.06.2027, Sat'] as $label) {
    $bad=$englishDate;$bad['raw']['date']=$label;
    deliveryCheck('FUNSUN weekday support refuses unknown suffix or a different numeric date',ManagerTourSelectionImportDraft::normalize($bad)===null);
}
$bad=$englishDate;$bad['url']=str_replace('20270605','20270606',$bad['url']);
deliveryCheck('FUNSUN English weekday cannot bypass the source search date range',ManagerTourSelectionImportDraft::normalize($bad)===null);
$bad=$englishDate;$bad['offer']['date_from']='2027-02-30';$bad['raw']['date']='30.02.2027, Tue';
deliveryCheck('FUNSUN English weekday cannot validate an impossible calendar date',ManagerTourSelectionImportDraft::normalize($bad)===null);
$bad=$englishDate;$bad['raw']['price']='200 001 RUB';
deliveryCheck('FUNSUN English weekday preserves exact displayed-price validation',ManagerTourSelectionImportDraft::normalize($bad)===null);
$bad=$englishDate;$bad['raw']['party']='3AD';
deliveryCheck('FUNSUN English weekday preserves exact displayed-party validation',ManagerTourSelectionImportDraft::normalize($bad)===null);
$bad=$englishDate;$bad['photos'][1]=str_replace('00180000','00280000',$bad['photos'][1]);
deliveryCheck('FUNSUN English weekday cannot mix source hotel photo identities',ManagerTourSelectionImportDraft::normalize($bad)===null);
$savedEnglishDate=null;
if ($englishNormalized!==null) {$pdo=deliveryDb();$savedEnglishDate=collectorImportedDraft($pdo,$englishDate);}
deliveryCheck('FUNSUN English weekday survives canonical import and exact private provenance readback',
    $savedEnglishDate!==null&&$savedEnglishDate['version']===1&&count($savedEnglishDate['draft']['offers'])===1
    &&$savedEnglishDate['draft']['offers'][0]['import_source']===ManagerTourSelectionImportDraft::source($englishNormalized));

$anex=anexCollectorObservation();$anex['photos']=['https://files.anextour.ru/hotel/hotels/turkey/synthetic/o1?&hotelCode=100'];
deliveryCheck('ANEX one-photo gallery normalizes with its exact hotel code',count(ManagerTourSelectionImportDraft::normalize($anex)['offer']['photos'])===1);
$bad=$anex;$bad['photos'][0]=str_replace('hotelCode=100','hotelCode=101',$bad['photos'][0]);deliveryCheck('ANEX gallery cannot borrow another hotels image',ManagerTourSelectionImportDraft::normalize($bad)===null);
$tv=tourvisorCollectorObservation();$tv['photos']=['https://static.tourvisor.ru/hotel_pics/verybig/'.$tv['keys']['country'].'/'.$tv['keys']['hotel'].'-1.jpg'];
deliveryCheck('Tourvisor gallery normalizes against its exact source hotel prefix',ManagerTourSelectionImportDraft::normalize($tv)!==null);
$bad=$tv;$bad['photos'][0]=str_replace('/'.$tv['keys']['hotel'].'-','/999999-',$bad['photos'][0]);deliveryCheck('Tourvisor gallery rejects another source hotel prefix',ManagerTourSelectionImportDraft::normalize($bad)===null);
$pdo=deliveryDb();collectorSchema($pdo);$view=ManagerTourSelectionCollectorSession::view(7,1,$session,$device);
ManagerTourSelectionCollectorSession::connect(7,1,$session,['device_id'=>$device,'connection_id'=>$connection,'binding'=>$view['target']['binding'],'confirm_current_trip'=>true]);
$cmd=['operation_id'=>'automatic-photos-original','connection_id'=>$connection,'observation'=>collectorObservation()];ManagerTourSelectionService::import(7,1,$session,$cmd);
$current=ManagerTourSelectionService::get(7,1)['selection'];$originalId=$current['draft']['offers'][0]['id'];$current['draft']['offers'][0]['amount']='215000';$current['draft']['offers'][0]['rationale']='Согласованная правка';deliverySave($current['draft'],$current['version']);
$cmd['operation_id']='automatic-photos-enrich';$cmd['observation']=$auto;$added=ManagerTourSelectionService::import(7,1,$session,$cmd);$current=ManagerTourSelectionService::get(7,1)['selection'];
deliveryCheck('re-adding a photo-less legacy import enriches without a duplicate or lost manager price',$added['ok']&&$added['state']==='already_added'&&count($current['draft']['offers'])===1&&$current['draft']['offers'][0]['id']===$originalId&&$current['draft']['offers'][0]['amount']==='215000'&&$current['draft']['offers'][0]['rationale']==='Согласованная правка'&&count($current['draft']['offers'][0]['photos'])===3);
deliveryCheck('uncertain enrichment confirms its exact receipt without another revision',ManagerTourSelectionService::importOperation(7,1,$session,$cmd)['found']&&ManagerTourSelectionService::import(7,1,$session,$cmd)['saved_version']===$current['version']);
$previous=$current;$cmd['operation_id']='automatic-photos-new-gallery';$cmd['observation']['photos'][0]=str_replace('automatic','updated',$auto['photos'][0]);ManagerTourSelectionService::import(7,1,$session,$cmd);
deliveryCheck('repeat source import preserves already selected photos and all manual fields',ManagerTourSelectionService::get(7,1)['selection']['draft']===$previous['draft']);
$forged=$previous['draft'];$forged['offers'][0]['import_source']['photos'][0]=str_replace('automatic','forged',$auto['photos'][0]);deliveryCheck('ordinary save cannot forge the immutable imported photo provenance',deliverySave($forged,$previous['version'])['error']==='source_changed');
foreach ([1,2,3] as $count) foreach ([1,2] as $conversation){
    $pdo=deliveryDb();$observation=$auto;$observation['photos']=array_slice($auto['photos'],0,$count);$saved=collectorImportedDraft($pdo,$observation,$conversation);$draft=$saved['draft'];
    deliveryCheck('automatic '.$count.'-photo draft imports for channel '.$conversation,$saved['version']===1&&count($draft['offers'][0]['photos'])===$count);
    $built=ManagerTourSelectionPublicationDraft::build($draft);deliveryCheck('automatic gallery prepares without requiring three manual photos',$built['ok']&&count($built['offers'][0]['presentation']['photos'])===$count&&$built['offers'][0]['presentation']['layout']==='gallery-v1');
    $prepared=ManagerTourSelectionDeliveryService::prepare(7,$conversation,1);$publication=$prepared['publication']['id'];deliveryCheck('preview exposes the actual photo count',count($prepared['publication']['cards'][0]['photos'])===$count);
    $q=$pdo->prepare('SELECT callback_data FROM manager_tour_selection_cards WHERE publication_id=?');$q->execute([$publication]);$callback=$q->fetchColumn();$card=TourOfferCardRenderer::render($built['offers'][0]['snapshot'],$built['offers'][0]['presentation'],$callback);
    deliveryCheck('automatic gallery captions do not claim selected-room photos',str_contains($card['plain_text'],'Фотографии отеля.')&&!str_contains($card['plain_text'],'Фото номера —'));
    $state=['calls'=>[],'creates'=>0,'readback'=>true,'card'=>$card];$transport=deliveryTransport($state);$sent=ManagerTourSelectionDeliveryService::send(7,$conversation,$publication,0,$transport,'deliveryImages');
    deliveryCheck('exact actual photo count delivers once through the existing transport',$sent['ok']&&$sent['publication']['cards'][0]['state']==='delivered'&&$state['creates']===1);
    deliveryCheck('gallery send retry preserves the one native creation',ManagerTourSelectionDeliveryService::send(7,$conversation,$publication,0,$transport,'deliveryImages')['ok']&&$state['creates']===1);
    $native=$conversation===1?['message_id'=>7001,'chat'=>['id'=>101,'type'=>'private'],'from'=>['id'=>999,'is_bot'=>true],'rich_message'=>$card['telegram']['rich_message'],'reply_markup'=>$card['telegram']['reply_markup']]:$state['native'];
    if($conversation===1){foreach($native['rich_message']['blocks'][0]['blocks'] as &$block)$block['photo']=[['file_id'=>'synthetic-photo','width'=>800,'height'=>600]];unset($block);array_pop($native['rich_message']['blocks'][0]['blocks']);$identity=['user'=>'101','bot'=>'999'];}else{array_shift($native['body']['attachments']);$identity=['user'=>'202','bot'=>'999','chat'=>'555'];}
    deliveryCheck('native verification rejects a response with a missing gallery image',!TourSelectionNativeTransport::matches($conversation===1?'telegram':'max',$card,$identity,$conversation===1?'7001':'mid.sent.1',$native));
}
$partner=partnerCollectorObservation();$normalized=ManagerTourSelectionImportDraft::normalize($partner);
deliveryCheck('FUNSUN partner snapshot retains signed hotel unknown keys and exact displayed room',$normalized!==null&&$normalized['keys']['hotel']==='-10'&&$normalized['keys']['room']==='-2147483647'&&$normalized['keys']['spo']===''&&$normalized['offer']['room']==='DOUBLE STANDARD / 2ADL'&&count($normalized['offer']['photos'])===3&&str_contains($normalized['offer']['constraints'],'не установлено'));
foreach (['hotel'=>'10','room'=>'20','placement'=>'40','spo'=>'60','partner'=>'OTHER'] as $key=>$value){$bad=$partner;$bad['keys'][$key]=$value;deliveryCheck('partner profile cannot reinterpret unsupported '.$key.' identity',ManagerTourSelectionImportDraft::normalize($bad)===null);}
$bad=$partner;unset($bad['keys']['partner']);deliveryCheck('negative identities need the explicit observed partner profile',ManagerTourSelectionImportDraft::normalize($bad)===null);
$bad=$partner;$bad['keys']['hotel']='-2147483647';$bad['raw']['hotel_url']='https://b2b.fstravel.com/hotel/-2147483647?partner=HBK';deliveryCheck('unknown-room sentinel cannot become a partner hotel identity',ManagerTourSelectionImportDraft::normalize($bad)===null);
foreach (['https://evil.example/hotel/-10?partner=HBK','https://b2b.fstravel.com/hotel/-11?partner=HBK','https://b2b.fstravel.com/hotel/-10','https://b2b.fstravel.com/hotel/-10?partner=HBK&token=secret','https://b2b.fstravel.com/hotel/-10?partner=HBK#x'] as $url){$bad=$partner;$bad['raw']['hotel_url']=$url;deliveryCheck('partner hotel link must bind the exact hotel without secrets or another source',ManagerTourSelectionImportDraft::normalize($bad)===null);}
$bad=$partner;unset($bad['raw']['hotel_url']);deliveryCheck('partner format requires its observed source hotel link',ManagerTourSelectionImportDraft::normalize($bad)===null);
$bad=collectorObservation();$bad['raw']['hotel_url']=$partner['raw']['hotel_url'];deliveryCheck('legacy native grammar cannot acquire partner metadata',ManagerTourSelectionImportDraft::normalize($bad)===null);
foreach (['https://hbpro.expert/resource/hotel/photo_1200/11/23/104.jpg','https://hbpro.expert/resource/hotel/photo_1200/11/22/104.jpg?token=secret','https://s-1001.images.hotelbook.ru/5232181/primary',$auto['photos'][0]] as $url){$bad=$partner;$bad['photos'][1]=$url;deliveryCheck('partner gallery rejects mixed hotels query values and unbound room photos',ManagerTourSelectionImportDraft::normalize($bad)===null);}
$pdo=deliveryDb();$saved=collectorImportedDraft($pdo,$partner);$draft=$saved['draft'];$partnerId=$draft['offers'][0]['id'];
deliveryCheck('partner row uses the canonical collector import and editable photo draft',$saved['version']===1&&count($draft['offers'])===1&&$draft['offers'][0]['import_source']['keys']['hotel']==='-10'&&$draft['offers'][0]['import_source']['raw']['hotel_url']===$partner['raw']['hotel_url']&&count($draft['offers'][0]['photos'])===3);
$draft['offers'][0]['amount']='210000';$draft['offers'][0]['rationale']='Согласованная ручная правка';deliverySave($draft,1);
$cmd=['operation_id'=>'partner-exact-repeat-001','connection_id'=>str_repeat('3',32),'observation'=>$partner];$result=ManagerTourSelectionService::import(7,1,str_repeat('1',64),$cmd);$current=ManagerTourSelectionService::get(7,1)['selection'];
deliveryCheck('partner exact repeat preserves manual price text photos offer id and revision',$result['ok']&&$result['state']==='already_added'&&$current['version']===2&&count($current['draft']['offers'])===1&&$current['draft']['offers'][0]['id']===$partnerId&&$current['draft']['offers'][0]['amount']==='210000'&&$current['draft']['offers'][0]['rationale']==='Согласованная ручная правка');
deliveryCheck('partner receipt resolves a lost response without a second import',ManagerTourSelectionService::importOperation(7,1,str_repeat('1',64),$cmd)['found']&&ManagerTourSelectionService::import(7,1,str_repeat('1',64),$cmd)['saved_version']===2);
$changed=$cmd;$changed['operation_id']='partner-different-room-001';$changed['observation']['offer']['room']=$changed['observation']['raw']['room']='SUPERIOR / 2ADL';
deliveryCheck('same opaque claim and unknown room id do not collapse another displayed room',ManagerTourSelectionService::import(7,1,str_repeat('1',64),$changed)['error']==='ambiguous_offer'&&ManagerTourSelectionService::get(7,1)['selection']['draft']===$current['draft']);
$changed['as_variant']=true;$added=ManagerTourSelectionService::import(7,1,str_repeat('1',64),$changed);$current=ManagerTourSelectionService::get(7,1)['selection'];
deliveryCheck('explicit partner room variant keeps separate conditions and original manual edits',$added['ok']&&count($current['draft']['offers'])===2&&$current['draft']['offers'][0]['amount']==='210000'&&$current['draft']['offers'][1]['room']==='SUPERIOR / 2ADL');
$built=ManagerTourSelectionPublicationDraft::build($current['draft']);deliveryCheck('partner images prepare with the existing neutral gallery without a fabricated match',$built['ok']&&$built['offers'][0]['presentation']['layout']==='gallery-v1'&&count($built['offers'][0]['presentation']['photos'])===3&&!$built['offers'][0]['snapshot']['price_verified']);
foreach (['HRG','SSH'] as $airport) foreach ([false,true] as $light){$egypt=anexEgyptCollectorObservation($light,$airport);$normalized=ManagerTourSelectionImportDraft::normalize($egypt);deliveryCheck('ANEX Egypt '.$airport.' '.($light?'Light':'unlabelled').' retains exact source and gallery',$normalized!==null&&$normalized['keys']['country']==='11'&&$normalized['keys']['spo']===$egypt['keys']['spo']&&$normalized['raw']['tariff']===$egypt['raw']['tariff']&&$normalized['offer']['nights']===11&&count($normalized['offer']['photos'])===3&&str_contains($normalized['offer']['constraints'],'требуют проверки'));}
$egypt=anexEgyptCollectorObservation();
foreach ([['keys','country','3'],['keys','spo','AYT-12345'],['keys','spo','HRG-12345-LED Anex Light'],['keys','spo','HRG-12345-MOW Other'],['raw','country','Турция'],['raw','tariff',''],['raw','nights','10+1'],['offer','destination','Турция, Хургада'],['offer','nights',10]] as [$group,$key,$value]){$bad=$egypt;$bad[$group][$key]=$value;deliveryCheck('ANEX Egypt refuses contradictory '.$group.'.'.$key.' '.(string)$value,ManagerTourSelectionImportDraft::normalize($bad)===null);}
$bad=$egypt;$bad['url']=str_replace('STATEINC=11','STATEINC=3',$bad['url']);deliveryCheck('ANEX Egypt cannot borrow Turkey search context',ManagerTourSelectionImportDraft::normalize($bad)===null);
$bad=anexCollectorObservation();$bad['keys']['spo']='HRG-12345-MOW';deliveryCheck('ANEX Turkey keeps its original SPO grammar',ManagerTourSelectionImportDraft::normalize($bad)===null);
$pdo=deliveryDb();$mixed=collectorEgyptMixedDraft($pdo,$egypt);deliveryCheck('same-direction mixed source draft keeps all three independent source identities',count($mixed['draft']['offers'])===3&&array_column(array_column($mixed['draft']['offers'],'import_source'),'adapter')===['funsun-b2b-v1','anex-agent-v1','tourvisor-snapshot-v1']&&array_unique(array_column($mixed['draft']['offers'],'date_from'))===['2026-10-29']&&array_unique(array_column($mixed['draft']['offers'],'nights'))===[11]);
$saved=$mixed['draft'];$saved['offers'][1]['amount']='150000';$saved['offers'][1]['rationale']='Ручное пояснение';deliverySave($saved,$mixed['version']);$cmd=['operation_id'=>'egypt-mixed-exact-repeat','connection_id'=>str_repeat('3',32),'observation'=>$egypt];$repeat=ManagerTourSelectionService::import(7,1,str_repeat('1',64),$cmd);$after=ManagerTourSelectionService::get(7,1)['selection'];deliveryCheck('ANEX Egypt repeat preserves manual price source photos and neighbouring offers',$repeat['state']==='already_added'&&count($after['draft']['offers'])===3&&$after['draft']['offers'][1]['amount']==='150000'&&$after['draft']['offers'][1]['rationale']==='Ручное пояснение'&&$after['draft']['offers'][1]['import_source']===$mixed['draft']['offers'][1]['import_source']&&$after['draft']['offers'][0]===$mixed['draft']['offers'][0]&&$after['draft']['offers'][2]===$mixed['draft']['offers'][2]);
$built=ManagerTourSelectionPublicationDraft::build($after['draft']);deliveryCheck('same-direction mixed source preview has three neutral photo galleries',$built['ok']&&count($built['offers'])===3&&array_column(array_column($built['offers'],'presentation'),'layout')===['gallery-v1','gallery-v1','gallery-v1']);
echo "COLLECTOR TOTAL {$checks} PASS\n";
