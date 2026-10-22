<?php
declare(strict_types=1);
require_once __DIR__.'/support/manager-tour-selection.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionShareService.php';
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
    foreach ($selection['offers'] as $i=>$offer) foreach ([0,1,2] as $photo) TourSelectionPhotoStore::image(TourSelectionPhotoStore::offerKey((int)$ids[$i]),0,$photo,$offer['presentation']['photos'][$photo]['url'],static fn():string=>file_get_contents(dirname(__DIR__).'/assets/owner-tour-cards/compact-v1/cover.jpg'));
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
    $pdo->exec('UPDATE projects SET is_active=0');deliveryCheck('inactive project closes public reads',ManagerTourSelectionShareService::read($newToken)===null);$pdo->exec('UPDATE projects SET is_active=1');
    deliveryCheck('public sharing never sends or invents a tourist choice',(int)$pdo->query("SELECT COUNT(*) FROM messages WHERE direction='outbound'")->fetchColumn()===0&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='tour_offer_liked'")->fetchColumn()===0);
    deliveryCheck('history preserves all five explicit revisions',(int)$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_link_history')->fetchColumn()===5);
    // Exercise the unmodified public controllers and ManagerHttp over real HTTP.
    $http=sys_get_temp_dir().'/tour-share-http-'.bin2hex(random_bytes(8));mkdir($http,0700);mkdir($http.'/manager',0700);mkdir($http.'/selection',0700);mkdir($http.'/sessions',0700);$process=null;
    try {
        $pdo->exec('VACUUM INTO '.$pdo->quote($http.'/fixture.sqlite'));
        $root=dirname(__DIR__);
        symlink($root.'/services',$http.'/services');symlink($root.'/integrations',$http.'/integrations');symlink($root.'/manager/lib',$http.'/manager/lib');
        foreach (['index.php','photo.php','view.php','selection.css'] as $file) copy($root.'/selection/'.$file,$http.'/selection/'.$file);
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
        $request=static function(string $path,string $method='GET',array $data=[],string $cookie='')use($address):array {
            $headers=['Content-Type: application/json'];if($cookie!=='')$headers[]='Cookie: '.$cookie;
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
        deliveryCheck('manager sharing HTTP requires authentication',$request('/manager/tour-selections-api.php','POST',['action'=>'share','conversation_id'=>1])['status']===401);
        $session=$request('/manager/session.php');preg_match('/Set-Cookie:\s*([^;\n]+)/i',$session['headers'],$cookieMatch);$cookie=$cookieMatch[1]??'';
        $command=['action'=>'revoke','conversation_id'=>1,'publication_id'=>$id,'share_version'=>5];
        deliveryCheck('manager sharing HTTP requires canonical CSRF',$request('/manager/tour-selections-api.php','POST',$command,$cookie)['status']===403);
        $command['csrf']='share-fixture-csrf';$rev=$request('/manager/tour-selections-api.php','POST',$command,$cookie);
        deliveryCheck('actual HTTP revoke immediately closes public page and images',$rev['status']===200&&$request($path)['status']===404&&$request('/selection/photo.php?t='.$newToken.'&v=5&card=0&photo=0')['status']===404);
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
