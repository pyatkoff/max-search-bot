<?php
declare(strict_types=1);
require_once __DIR__.'/support/manager-tour-selection.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionShareService.php';
require_once dirname(__DIR__).'/modules/tour-selections/deploy.php';
$pdo=deliveryDb();
$pdo->exec('CREATE TABLE manager_tour_selection_links(conversation_id INTEGER PRIMARY KEY,version INTEGER,token TEXT UNIQUE,publication_id TEXT,is_active INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);
CREATE TABLE manager_tour_selection_link_history(conversation_id INTEGER,version INTEGER,publication_id TEXT,is_active INTEGER,manager_id INTEGER,created_at TEXT,PRIMARY KEY(conversation_id,version));');
$private=sys_get_temp_dir().'/tour-share-private-'.bin2hex(random_bytes(8));mkdir($private,0700);
DiagnosticLogger::setFile($private.'/events.log');
function shareRows(): array {global $pdo;$out=[];foreach (['conversations','managers','messages','manager_conversation_reads','conversation_events'] as $table) {$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();}return $out;}
function shareCommand(int $actor,int $conversation,int $version,string $publication,bool $active): array {
    $before=shareRows();$result=ManagerTourSelectionShareService::change($actor,$conversation,$version,$publication,$active);
    deliveryCheck('share command leaves transcript reads conversation events and shifts unchanged',shareRows()===$before);
    return $result;
}
function cachePublication(string $id): void {
    global $pdo;
    $q=$pdo->prepare('SELECT selection_json FROM manager_tour_selection_publications WHERE publication_id=?');$q->execute([$id]);$selection=json_decode($q->fetchColumn(),true);
    $q=$pdo->prepare('SELECT offer_id FROM manager_tour_selection_cards WHERE publication_id=? ORDER BY card_index');$q->execute([$id]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
    foreach ($selection['offers'] as $i=>$offer) foreach (array_keys($offer['presentation']['photos']) as $photo) TourSelectionPhotoStore::image(TourSelectionPhotoStore::offerKey((int)$ids[$i]),0,$photo,$offer['presentation']['photos'][$photo]['url'],static fn():string=>file_get_contents(dirname(__DIR__).'/assets/owner-tour-cards/compact-v1/cover.jpg'));
}
function groupingChecks(): void {
    global $pdo;
    $original=$pdo;
    try {
        $pdo=deliveryDb();
        $pdo->exec('CREATE TABLE manager_tour_selection_links(conversation_id INTEGER PRIMARY KEY,version INTEGER,token TEXT UNIQUE,publication_id TEXT,is_active INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);CREATE TABLE manager_tour_selection_link_history(conversation_id INTEGER,version INTEGER,publication_id TEXT,is_active INTEGER,manager_id INTEGER,created_at TEXT,PRIMARY KEY(conversation_id,version));');
        $draft=deliveryDraft();$one=$draft['offers'][0];
        $two=$one;$two['id']=str_repeat('b',32);$two['date_from']='2027-06-12';$two['room']='Другой номер';$two['amount']='260000';$two['flights']='Другой перелёт';$two['photos'][2]['url']='https://photos.example.org/other-room.jpg';
        $three=$one;$three['id']=str_repeat('c',32);$three['amount']='270000';
        $draft['offers']=[$one,$two,$three];
        $before=ManagerTourSelectionPublicationDraft::build($draft);
        deliveryCheck('legacy publication metadata remains absent, without hotel-name matching',!isset($before['offers'][0]['hotel_group']));
        deliverySave($draft);$old=ManagerTourSelectionDeliveryService::prepare(7,1,1)['publication'];cachePublication($old['id']);
        $published=shareCommand(7,1,0,$old['id'],true);$token=substr($published['share']['path'],strlen('/selection/?t='));
        deliveryCheck('identical hotel names alone remain three public groups',count(array_unique(array_column(ManagerTourSelectionShareService::read($token)['offers'],'hotel_group')))===3);
        $oldJson=$pdo->query('SELECT selection_json FROM manager_tour_selection_publications')->fetchColumn();
        $draft['offers'][0]['hotel_group']=$one['id'];$draft['offers'][1]['hotel_group']=$one['id'];
        $after=ManagerTourSelectionPublicationDraft::build($draft);
        deliveryCheck('grouping preserves every exact native snapshot and presentation',array_column($before['offers'],'snapshot')===array_column($after['offers'],'snapshot')&&array_column($before['offers'],'presentation')===array_column($after['offers'],'presentation'));
        $bad=$draft;$bad['offers'][0]['hotel_group']='<script>';
        deliveryCheck('forged grouping type and HTML are rejected',ManagerTourSelectionDraft::normalize($bad)===null&&ManagerTourSelectionDraft::normalize(array_replace($draft,['offers'=>[array_replace($one,['hotel_group'=>12])]]))===null);
        deliverySave($draft,1);$next=ManagerTourSelectionDeliveryService::prepare(7,1,2)['publication'];cachePublication($next['id']);
        deliveryCheck('regrouping reuses canonical offers and callbacks, without another native quote',array_column($old['cards'],'offer_id')===array_column($next['cards'],'offer_id')&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='tour_offer_prepared'")->fetchColumn()===3);
        deliveryCheck('preview does not silently regroup an existing shared page',count(array_unique(array_column(ManagerTourSelectionShareService::read($token)['offers'],'hotel_group')))===3);
        $updated=shareCommand(7,1,1,$next['id'],true);$public=ManagerTourSelectionShareService::read($token);
        deliveryCheck('explicit grouping update keeps URL and separate variants',$updated['share']['path']===$published['share']['path']&&array_column($public['offers'],'hotel_group')===[0,0,1]&&array_column($public['offers'],'amount')===['240000','260000','270000']&&$public['offers'][1]['date_from']==='2027-06-12'&&$public['offers'][1]['room']==='Другой номер'&&$public['offers'][1]['flights']==='Другой перелёт');
        $q=$pdo->prepare('SELECT selection_json FROM manager_tour_selection_publications WHERE publication_id=?');$q->execute([$old['id']]);
        deliveryCheck('previous immutable publication and native choice contract stay intact',$q->fetchColumn()===$oldJson&&!str_contains(json_encode($public),$one['id']));
        $selection=$public;$h=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');ob_start();require dirname(__DIR__).'/selection/view.php';$html=ob_get_clean();
        deliveryCheck('real public renderer has two hotel headers and three separately anchored exact variants',substr_count($html,'<article class="offer"')===2&&preg_match_all('/<h2\b[^>]*>/',$html)===2&&substr_count($html,'class="offerVariant"')===3&&str_contains($html,'id="variant-2"')&&str_contains($html,'260 000,00'));
        deliveryCheck('grouped room images stay with their own variants',substr_count($html,'class="variantRoom"')===2&&str_contains($html,'card=1&amp;photo=2'));
        deliveryCheck('grouping creates no send, like, read marker or routing change',(int)$pdo->query("SELECT COUNT(*) FROM messages WHERE direction='outbound'")->fetchColumn()===0&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='tour_offer_liked'")->fetchColumn()===0&&(int)$pdo->query('SELECT last_read_message_id FROM manager_conversation_reads LIMIT 1')->fetchColumn()===0);
    } finally {$pdo=$original;(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);}
}
function automaticGalleryChecks(): void {
    global $pdo;
    require_once __DIR__.'/support/manager-tour-collector.php';
    $original=$pdo;
    try {
        foreach ([1,2,3] as $count) {
            $pdo=deliveryDb();$pdo->exec('CREATE TABLE manager_tour_selection_links(conversation_id INTEGER PRIMARY KEY,version INTEGER,token TEXT UNIQUE,publication_id TEXT,is_active INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);CREATE TABLE manager_tour_selection_link_history(conversation_id INTEGER,version INTEGER,publication_id TEXT,is_active INTEGER,manager_id INTEGER,created_at TEXT,PRIMARY KEY(conversation_id,version));');
            $observation=collectorObservation();$base='https://apigate-tui.fstravel.com/api/geocontent/static/Hotel/00180000-ac11-0242-6c05-08d9934f9e4b/';$observation['photos']=array_slice(array_map(static fn(string $k):string=>$base.$k.'-Source-share.jpg',['MainPhoto','Gallery','InsideOfTheRoom']),0,$count);
            $saved=collectorImportedDraft($pdo,$observation);$draft=$saved['draft'];$pub=ManagerTourSelectionDeliveryService::prepare(7,1,1)['publication']['id'];
            deliveryCheck('automatic gallery cannot publish before every actual image is reviewed',shareCommand(7,1,0,$pub,true)['error']==='preparation_failed');
            cachePublication($pub);$published=shareCommand(7,1,0,$pub,true);deliveryCheck('automatic '.$count.'-photo gallery publishes without extra manual photos',$published['ok']);
            $token=substr($published['share']['path'],strlen('/selection/?t='));$selection=ManagerTourSelectionShareService::read($token);
            deliveryCheck('public gallery projects only the actual cached image count',count($selection['offers'][0]['photos'])===$count&&$selection['offers'][0]['photo_layout']==='gallery-v1'&&!str_contains(json_encode($selection),'apigate-tui'));
            $h=static fn($s):string=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');ob_start();require dirname(__DIR__).'/selection/view.php';$html=ob_get_clean();
            deliveryCheck('public gallery renders without invented beach or room captions',substr_count($html,'alt="Фото отеля ')===$count&&!str_contains($html,'Пример интерьера номера')&&str_contains($html,'photoCount'.$count));
            foreach (range(0,$count-1) as $i) deliveryCheck('public gallery reads only its reviewed immutable bytes',ManagerTourSelectionShareService::photo($token,1,0,$i)!==null);
            if($count<3) deliveryCheck('missing extra gallery slot stays absent',ManagerTourSelectionShareService::photo($token,1,0,$count)===null);
            shareCommand(7,1,1,$pub,false);deliveryCheck('revoking the automatic gallery closes its photos too',ManagerTourSelectionShareService::read($token)===null&&ManagerTourSelectionShareService::photo($token,1,0,0)===null);
        }
    } finally {$pdo=$original;(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$original);}
}

try {
    $draft=deliveryDraft();$draft['intro']='Комментарий для туриста <script>alert(1)</script>';
    deliverySave($draft);$prepared=ManagerTourSelectionDeliveryService::prepare(7,1,1);$id=$prepared['publication']['id'];$before=shareRows();
    deliveryCheck('share starts private and reading status creates nothing',ManagerTourSelectionShareService::get(7,1)['share']['version']===0&&(int)$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_links')->fetchColumn()===0);
    deliveryCheck('anonymous foreign and colleague cannot publish',shareCommand(0,1,0,$id,true)['error']==='unauthorized'&&shareCommand(8,1,0,$id,true)['error']==='forbidden');
    deliveryCheck('public publication requires already reviewed photo bytes',shareCommand(7,1,0,$id,true)['error']==='preparation_failed');
    cachePublication($id);$published=shareCommand(7,1,0,$id,true);$share=$published['share'];$token=substr($share['path'],strlen('/selection/?t='));
    deliveryCheck('explicit publish creates an unpredictable versioned public URL',$published['ok']&&$share['active']&&$share['version']===1&&preg_match('/\A[a-f0-9]{64}\z/D',$token)===1);
    $public=ManagerTourSelectionShareService::read($token);$json=json_encode($public,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    deliveryCheck('same exact terms whole party price and child count reach public view',$public['offers'][0]['amount']==='240000'&&$public['offers'][0]['room']==='Семейный номер'&&$public['offers'][0]['children']===2&&!$public['offers'][0]['price_verified']);
    foreach (['child_ages','external_chat','source_url','source_label','callback_data','offer_id','conversation_id','customer','manager','photos.example.org'] as $secret) deliveryCheck('public allowlist excludes '.$secret,!str_contains($json,$secret));
    deliveryCheck('public photo is the already frozen reviewed image',ManagerTourSelectionShareService::photo($token,1,0,0)!==null&&ManagerTourSelectionShareService::photo($token,1,20,0)===null&&ManagerTourSelectionShareService::photo($token,1,0,3)===null);
    deliveryCheck('guess malformed and absent tokens expose no page',ManagerTourSelectionShareService::read('')===null&&ManagerTourSelectionShareService::read(str_repeat('b',64))===null&&ManagerTourSelectionShareService::read($token.'x')===null);
    deliveryCheck('stale request is not automatically replayed',shareCommand(7,1,0,$id,true)['error']==='share_changed');
    deliveryCheck('explicit repeat of same current publication creates no new version',shareCommand(7,1,1,$id,true)['share']['version']===1);
    $new=$draft;$new['offers'][0]['amount']='250000';deliverySave($new,1);$second=ManagerTourSelectionDeliveryService::prepare(7,1,2);$id2=$second['publication']['id'];cachePublication($id2);
    deliveryCheck('editing and preview never silently update public price',ManagerTourSelectionShareService::read($token)['offers'][0]['amount']==='240000');
    deliveryCheck('old conditions cannot overwrite a newer draft',shareCommand(7,1,1,$id,true)['error']==='draft_changed');
    $updated=shareCommand(7,1,1,$id2,true);
    deliveryCheck('explicit update retains URL and changes only current page',$updated['share']['path']===$share['path']&&$updated['share']['version']===2&&ManagerTourSelectionShareService::read($token)['offers'][0]['amount']==='250000');
    deliveryCheck('photos from a previous open version do not mix with new terms',ManagerTourSelectionShareService::photo($token,1,0,0)===null&&ManagerTourSelectionShareService::photo($token,2,0,0)!==null);
    deliverySave($draft,2);$reused=ManagerTourSelectionDeliveryService::prepare(7,1,3);
    deliveryCheck('return to identical original terms reuses publication safely',$reused['publication']['id']===$id&&shareCommand(7,1,2,$id,true)['ok']);
    $pdo->exec("UPDATE conversations SET status='closed' WHERE id=1");
    deliveryCheck('assigned manager can still revoke a closed conversations public link',ManagerTourSelectionShareService::get(7,1)['share']['can_revoke']);
    $revoked=shareCommand(7,1,3,$id,false);
    deliveryCheck('revocation closes page and every public photo',$revoked['share']['version']===4&&!$revoked['share']['active']&&ManagerTourSelectionShareService::read($token)===null&&ManagerTourSelectionShareService::photo($token,3,0,0)===null);
    $pdo->exec("UPDATE conversations SET status='manager' WHERE id=1");
    $again=shareCommand(7,1,4,$id,true);$newToken=substr($again['share']['path'],strlen('/selection/?t='));
    deliveryCheck('reactivation never revives a revoked URL',$newToken!==$token&&ManagerTourSelectionShareService::read($token)===null&&ManagerTourSelectionShareService::read($newToken)!==null);
    $marker=ManagerTourSelectionPublicHost::markerPath();$originalShare=ManagerTourSelectionShareService::get(7,1)['share'];
    deliveryCheck('uncommissioned public host preserves the existing app URL',!isset($originalShare['public_url']));
    file_put_contents($marker,json_encode(['contract'=>ManagerTourSelectionPublicHost::CONTRACT,'origin'=>ManagerTourSelectionPublicHost::ORIGIN,'fingerprint'=>ManagerTourSelectionPublicHost::fingerprint()],JSON_THROW_ON_ERROR));
    $hostedShare=ManagerTourSelectionShareService::get(7,1)['share'];
    deliveryCheck('accepted host adds exact public and signed preview URLs without changing link identity',$hostedShare['path']===$originalShare['path']&&$hostedShare['version']===$originalShare['version']&&$hostedShare['public_url']==='https://tours.anytoour.ru/?t='.$newToken&&$hostedShare['preview_url']==='https://tours.anytoour.ru/?'.substr($hostedShare['preview_path'],strlen('/selection/?')));
    unlink($marker);
    deliveryCheck('missing installation receipt falls back without revoking or republishing the selection',!isset(ManagerTourSelectionShareService::get(7,1)['share']['public_url'])&&(int)$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_link_history')->fetchColumn()===5);
    $pdo->exec('UPDATE projects SET is_active=0');deliveryCheck('inactive project closes public reads',ManagerTourSelectionShareService::read($newToken)===null);$pdo->exec('UPDATE projects SET is_active=1');
    deliveryCheck('public sharing never sends or invents a tourist choice',(int)$pdo->query("SELECT COUNT(*) FROM messages WHERE direction='outbound'")->fetchColumn()===0&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='tour_offer_liked'")->fetchColumn()===0);
    deliveryCheck('history preserves all five explicit revisions',(int)$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_link_history')->fetchColumn()===5);
    require_once __DIR__.'/support/manager-tour-interest.php';
    $interestPdo=deliveryDb();interestScenarios($interestPdo);
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    groupingChecks();
    automaticGalleryChecks();
    require_once __DIR__.'/fixtures/tour_selection_bot_entry.php';
    tourSelectionBotEntryChecks();
    // Exercise the unmodified public controllers and ManagerHttp over real HTTP.
    $http=sys_get_temp_dir().'/tour-share-http-'.bin2hex(random_bytes(8));mkdir($http,0700);mkdir($http.'/manager',0700);mkdir($http.'/selection',0700);mkdir($http.'/sessions',0700);$process=null;
    try {
        interestSchema($pdo);
        $pdo->exec('VACUUM INTO '.$pdo->quote($http.'/fixture.sqlite'));
        $root=dirname(__DIR__);
        symlink($root.'/services',$http.'/services');symlink($root.'/integrations',$http.'/integrations');symlink($root.'/manager/lib',$http.'/manager/lib');
        foreach (['index.php','photo.php','view.php','selection.css','activity.php','activity.js'] as $file) copy($root.'/selection/'.$file,$http.'/selection/'.$file);
        mkdir($http.'/hosted',0755);mkdir($http.'/host-releases',0755);mkdir($http.'/host-releases/package',0755);
        copy($root.'/selection/view.php',$http.'/host-releases/package/view.php');symlink($http.'/host-releases/package',$http.'/host-releases/current');
        $hostAdapter=new TourSelectionHostDeployment($http,$http.'/hosted',$http.'/host-releases',$private.'/unused-host-marker.json');
        foreach (['index.php','photo.php','activity.php'] as $route) file_put_contents($http.'/hosted/'.$route,$hostAdapter->front($route));
        copy($root.'/manager/tour-selections-api.php',$http.'/manager/tour-selections-api.php');
        $config="<?php\nrequire_once ".var_export($root.'/services/ManagerRequestContext.php',true).";\n".
            '$fixturePdo=new PDO('.var_export('sqlite:'.$http.'/fixture.sqlite',true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);' ."\n".
            '(new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$fixturePdo);' ."\n".
            'foreach ([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) (new ReflectionProperty($class,"schemaReady"))->setValue(null,true);' ."\n".
            'ProjectConfig::resetForTests(["id"=>"anytour"]);' ."\nrequire_once ".var_export($root.'/services/DiagnosticLogger.php',true).';DiagnosticLogger::setFile('.var_export($private.'/events.log',true).');';
        file_put_contents($http.'/config.php',$config);
        file_put_contents($http.'/manager/session.php','<?php require dirname(__DIR__)."/config.php";require __DIR__."/lib/ManagerHttp.php";ManagerHttp::startJson();$_SESSION["manager_id"]=7;$_SESSION["csrf"]="share-fixture-csrf";ManagerHttp::respond(["ok"=>true]);');
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if (!$socket) throw new RuntimeException('Share loopback unavailable');$address=stream_socket_get_name($socket,false);fclose($socket);
        $process=proc_open([PHP_BINARY,'-d','session.save_path='.$http.'/sessions','-S',$address,'-t',$http],[0=>['pipe','r'],1=>['file',$http.'/server.log','a'],2=>['file',$http.'/server.log','a']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot start share HTTP fixture');fclose($pipes[0]);
        $ready=false;for($i=0;$i<200;$i++){$probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05);if($probe){fclose($probe);$ready=true;break;}usleep(10000);}if(!$ready)throw new RuntimeException('Share HTTP fixture not ready');
        $request=static function(string $path,string $method='GET',array $data=[],string $cookie='',array $extra=[])use($address):array {
            $headers=array_merge(['Content-Type: application/json'],$extra);if($cookie!=='')$headers[]='Cookie: '.$cookie;
            $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='POST'?json_encode($data):'','ignore_errors'=>true,'timeout'=>5]]);
            $body=file_get_contents('http://'.$address.$path,false,$ctx);$received=$http_response_header??[];preg_match('/\s(\d{3})\s/',$received[0]??'',$m);
            return ['status'=>(int)($m[1]??0),'body'=>$body,'headers'=>implode("\n",$received)];
        };
        $path='/selection/?t='.$newToken;$page=$request($path);
        deliveryCheck('anonymous actual HTTP public page renders current exact offer',$page['status']===200&&str_contains($page['body'],'Семейный номер')&&str_contains($page['body'],'240 000,00')&&str_contains($page['body'],'детей 2'));
        deliveryCheck('public HTML escapes manager markup and excludes source and ages',!str_contains($page['body'],'<script>alert(1)</script>')&&str_contains($page['body'],'&lt;script&gt;')&&!str_contains($page['body'],'catalog.example.org')&&!str_contains($page['body'],'child_ages'));
        deliveryCheck('page prevents caching indexing referrer and third-party execution',str_contains($page['headers'],'no-store')&&str_contains($page['headers'],'X-Robots-Tag: noindex')&&str_contains($page['headers'],'Referrer-Policy: no-referrer')&&str_contains($page['headers'],"default-src 'none'")&&!str_contains($page['headers'],'Set-Cookie:'));
        deliveryCheck('HEAD is bodyless and invalid public token is unavailable',$request($path,'HEAD')['body']===''&&$request('/selection/?t=bad')['status']===404);
        $photo=$request('/selection/photo.php?t='.$newToken.'&v=5&card=0&photo=0');
        deliveryCheck('public HTTP photo serves original frozen JPEG',$photo['status']===200&&hash('sha256',$photo['body'])===hash_file('sha256',$root.'/assets/owner-tour-cards/compact-v1/cover.jpg'));
        deliveryCheck('public endpoint rejects writes without touching the publication',$request($path,'POST')['status']===405);
        preg_match('/data-selection-interest="([^"]+)"/',$page['body'],$match);
        $cap=json_decode(html_entity_decode($match[1]??'',ENT_QUOTES|ENT_HTML5,'UTF-8'),true);
        $observe=$cap+['kind'=>'page_view','event_id'=>str_repeat('a',32),'visitor_id'=>str_repeat('b',32),'card_index'=>-1,'platform'=>''];
        deliveryCheck('public observation endpoint requires POST and exact same-origin JSON',$request('/selection/activity.php')['status']===405&&$request('/selection/activity.php','POST',$observe)['status']===403&&$request('/selection/activity.php','POST',$observe,'',['Origin: https://other.example'])['status']===403);
        $origin=['Origin: https://app.anytoour.ru'];$saved=$request('/selection/activity.php','POST',$observe,'',$origin);$again=$request('/selection/activity.php','POST',$observe,'',$origin);
        deliveryCheck('actual anonymous HTTP records one signed observation and reconciles exact retry',$saved['status']===200&&$again['status']===200&&!empty(json_decode($again['body'],true)['duplicate'])&&!str_contains($saved['headers'],'Set-Cookie:'));
        deliveryCheck('actual public observation rejects forged signature without becoming native action',$request('/selection/activity.php','POST',array_replace($observe,['mac'=>str_repeat('0',64)]),'',$origin)['status']===410);
        $hostHeaders=['Host: tours.anytoour.ru'];$hostPath='/hosted/?t='.$newToken;$hostPage=$request($hostPath,'GET',[],'',$hostHeaders);
        deliveryCheck('dedicated-host adapter renders the packaged view with the same canonical exact offer',$hostPage['status']===200&&str_contains($hostPage['body'],'Семейный номер')&&str_contains($hostPage['body'],'240 000,00')&&!str_contains($hostPage['headers'],'Set-Cookie:'));
        deliveryCheck('dedicated host retains signed cached photos and GET-only page',$request('/hosted/photo.php?t='.$newToken.'&v=5&card=0&photo=0','GET',[],'',$hostHeaders)['body']===$photo['body']&&$request($hostPath,'POST',[],'',$hostHeaders)['status']===405);
        deliveryCheck('dedicated host rejects an unapproved Host header',$request($hostPath)['status']===404);
        deliveryCheck('original app origin remains isolated from the new public origin',$request('/selection/activity.php','POST',$observe,'',['Origin: https://tours.anytoour.ru'])['status']===403&&$request('/hosted/activity.php','POST',$observe,'',[...$hostHeaders,'Origin: https://app.anytoour.ru'])['status']===403);
        $hostObserve=array_replace($observe,['kind'=>'hotel_view','event_id'=>str_repeat('e',32),'card_index'=>0]);
        $hostSaved=$request('/hosted/activity.php','POST',$hostObserve,'',[...$hostHeaders,'Origin: https://tours.anytoour.ru']);
        deliveryCheck('actual tours-origin observation uses the existing signed canonical interest store',$hostSaved['status']===200&&!empty(json_decode($hostSaved['body'],true)['saved'])&&!str_contains($hostSaved['headers'],'Set-Cookie:'));
        deliveryCheck('manager sharing HTTP requires authentication',$request('/manager/tour-selections-api.php','POST',['action'=>'share','conversation_id'=>1])['status']===401);
        $session=$request('/manager/session.php');preg_match('/Set-Cookie:\s*([^;\n]+)/i',$session['headers'],$cookieMatch);$cookie=$cookieMatch[1]??'';
        $activity=['action'=>'interest','conversation_id'=>1,'publication_id'=>$id];
        deliveryCheck('private activity retains canonical session and CSRF fences',$request('/manager/tour-selections-api.php','POST',$activity)['status']===401&&$request('/manager/tour-selections-api.php','POST',$activity,$cookie)['status']===403);
        $activity['csrf']='share-fixture-csrf';$observed=$request('/manager/tour-selections-api.php','POST',$activity,$cookie);$data=json_decode($observed['body'],true);
        deliveryCheck('actual Manager2 API returns anonymous scoped signals without visitor or sender identities',$observed['status']===200&&($data['interest']['totals']['browser']['page_view']['unique']??null)===1&&!str_contains($observed['body'],'visitor_hash')&&!str_contains($observed['body'],'external_user_id'));
        $command=['action'=>'revoke','conversation_id'=>1,'publication_id'=>$id,'share_version'=>5];
        deliveryCheck('manager sharing HTTP requires canonical CSRF',$request('/manager/tour-selections-api.php','POST',$command,$cookie)['status']===403);
        $command['csrf']='share-fixture-csrf';$rev=$request('/manager/tour-selections-api.php','POST',$command,$cookie);
        deliveryCheck('actual HTTP revoke immediately closes public page and images',$rev['status']===200&&$request($path)['status']===404&&$request('/selection/photo.php?t='.$newToken.'&v=5&card=0&photo=0')['status']===404);
        deliveryCheck('HTTP revoke also rejects the previous observation capability',$request('/selection/activity.php','POST',$observe,'',$origin)['status']===410);
        deliveryCheck('canonical revoke also closes the new host page cached image and observation',$request($hostPath,'GET',[],'',$hostHeaders)['status']===404&&$request('/hosted/photo.php?t='.$newToken.'&v=5&card=0&photo=0','GET',[],'',$hostHeaders)['status']===404&&$request('/hosted/activity.php','POST',$hostObserve,'',[...$hostHeaders,'Origin: https://tours.anytoour.ru'])['status']===410);
        deliveryCheck('stale HTTP revoke returns conflict without repeated history',$request('/manager/tour-selections-api.php','POST',$command,$cookie)['status']===409);
    } finally {
        if(is_resource($process)){proc_terminate($process);proc_close($process);}
        $clear=static function(string $path)use(&$clear):void{if(is_file($path)||is_link($path)){unlink($path);return;}foreach(scandir($path)?:[]as$part)if($part!=='.'&&$part!=='..')$clear($path.'/'.$part);rmdir($path);};$clear($http);
    }
} finally {
    DiagnosticLogger::setFile('');
    $remove=static function(string $path)use(&$remove):void {if(is_file($path)||is_link($path)){unlink($path);return;}foreach(scandir($path)?:[] as $part)if($part!=='.'&&$part!=='..')$remove($path.'/'.$part);rmdir($path);};$remove($private);
}
echo "TOTAL {$checks} | PASS {$checks} | FAIL 0\n";
