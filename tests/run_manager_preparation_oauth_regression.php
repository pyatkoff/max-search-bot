<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerPreparationOAuth.php';

$passed=0;
function preparationOauthCheck(string $name, bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    ++$passed;
    echo 'PASS '.$name."\n";
}

function preparationOauthReject(string $name, callable $action, string $expected): void
{
    try { $action(); } catch (InvalidArgumentException $error) {
        preparationOauthCheck($name,$error->getMessage()===$expected);
        return;
    }
    throw new RuntimeException('FAIL '.$name.' accepted');
}

function preparationOauthFixture(?string $databasePath = null): array
{
    $pdo=new PDO('sqlite:'.($databasePath??':memory:'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE managers(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER,is_working INTEGER);
        CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
        CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
        CREATE TABLE manager_preparation_oauth_clients(client_id TEXT PRIMARY KEY,redirect_uri TEXT,registration_bucket TEXT,created_at INTEGER);
        CREATE TABLE manager_preparation_oauth_grants(grant_id TEXT PRIMARY KEY,client_id TEXT,admin_id INTEGER,project_key TEXT,resource TEXT,scopes TEXT,created_at INTEGER,expires_at INTEGER,revoked_at INTEGER);
        CREATE TABLE manager_preparation_oauth_codes(code_hash TEXT PRIMARY KEY,grant_id TEXT,redirect_uri TEXT,code_challenge TEXT,expires_at INTEGER,consumed_at INTEGER);
        CREATE TABLE manager_preparation_oauth_tokens(token_hash TEXT PRIMARY KEY,grant_id TEXT,token_kind TEXT,expires_at INTEGER,consumed_at INTEGER);
        INSERT INTO managers VALUES(1,'admin',1,0),(2,'manager',1,1),(3,'admin',0,1),(4,'admin',1,1);
        INSERT INTO projects VALUES(1,'anytour','Synthetic project',1),(2,'other','Other project',1);
        INSERT INTO manager_projects VALUES(1,1),(2,1),(3,1),(4,2);");
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic project']]);
    $clock=(object)['now'=>time()];
    $oauth=new ManagerPreparationOAuth($pdo,static fn():int=>$clock->now);
    $client=$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'synthetic-registration-source');
    return [$pdo,$clock,$oauth,$client['client_id']];
}

function preparationOauthRequest(string $client, string $verifier): array
{
    return ['client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'response_type'=>'code',
        'resource'=>ManagerPreparationOAuth::RESOURCE,'scope'=>'queue:read prepared:write','state'=>'synthetic-state/a?b&c',
        'code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')];
}

function preparationOauthGrant(ManagerPreparationOAuth $oauth, string $client, int $admin = 1): array
{
    $session=['csrf'=>'synthetic-session-csrf'];
    $verifier=str_repeat('a',43);
    $request=preparationOauthRequest($client,$verifier);
    $id=$oauth->beginAuthorization($session,$request);
    $oauth->pendingAuthorization($session,$id,$admin);
    $target=$oauth->completeAuthorization($session,$id,$admin,$session['csrf'],true);
    parse_str((string)parse_url($target,PHP_URL_QUERY),$query);
    return [$query['code'],['grant_type'=>'authorization_code','client_id'=>$client,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,
        'resource'=>ManagerPreparationOAuth::RESOURCE,'code'=>$query['code'],'code_verifier'=>$verifier],$target,$session,$id];
}

/** Read the actual rendered successful controls, not a hand-invented POST body. */
function preparationOauthConsentForm(string $html, string $decision): array
{
    $dom=new DOMDocument();
    $previous=libxml_use_internal_errors(true);
    try {
        if (!$dom->loadHTML($html,LIBXML_NONET)) throw new RuntimeException('Consent HTML unavailable');
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $xpath=new DOMXPath($dom);
    $forms=$xpath->query('//form[@action="/manager/ai-oauth.php?action=authorize"]');
    if ($forms->length!==1) throw new RuntimeException('Expected one canonical consent form');
    $form=$forms->item(0);
    $fields=[];
    foreach ($xpath->query('.//input[@type="hidden"]',$form) as $input) {
        $name=$input->getAttribute('name');
        if ($name==='' || array_key_exists($name,$fields)) throw new RuntimeException('Invalid consent control');
        $fields[$name]=$input->getAttribute('value');
    }
    $buttons=$xpath->query('.//button[@type="submit" and @name="decision"]',$form);
    $choices=[];
    foreach ($buttons as $button) $choices[]=$button->getAttribute('value');
    if (!in_array($decision,$choices,true)) throw new RuntimeException('Consent submitter unavailable');
    return ['action'=>$form->getAttribute('action'),'method'=>strtoupper($form->getAttribute('method')),
        'fields'=>$fields,'choices'=>$choices,'post'=>$fields+['decision'=>$decision]];
}

/** Exact endpoint, real session and real SQLite service; no production bootstrap. */
function preparationOauthHttpChecks(bool $browserFixture = false): void
{
    $tmp=sys_get_temp_dir().'/manager-preparation-oauth-'.bin2hex(random_bytes(6));
    $server=null;
    try {
        foreach ([$tmp,$tmp.'/manager',$tmp.'/manager/lib',$tmp.'/services',$tmp.'/sessions',$tmp.'/.well-known',$tmp.'/.well-known/oauth-authorization-server',$tmp.'/.well-known/oauth-authorization-server/manager'] as $directory) mkdir($directory,0700);
        $database=$tmp.'/fixture.sqlite';
        [$pdo,,,$fixtureClient]=preparationOauthFixture($database);
        chmod($database,0600);
        $root=dirname(__DIR__);
        foreach (['manager/ai-oauth.php','manager/lib/ManagerHttp.php','services/ManagerPreparationOAuth.php','services/ManagerRequestContext.php','.well-known/oauth-authorization-server/index.php','.well-known/oauth-authorization-server/manager/ai-oauth.php'] as $file) copy($root.'/'.$file,$tmp.'/'.$file);
        preparationOauthCheck('HTTP fixture executes exact OAuth endpoint and service bytes',hash_file('sha256',$root.'/manager/ai-oauth.php')===hash_file('sha256',$tmp.'/manager/ai-oauth.php') && hash_file('sha256',$root.'/services/ManagerPreparationOAuth.php')===hash_file('sha256',$tmp.'/services/ManagerPreparationOAuth.php'));
        file_put_contents($tmp.'/services/ConversationDb.php','<?php class ConversationDb { public static function connection():PDO { return new PDO('.var_export('sqlite:'.$database,true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); } }');
        file_put_contents($tmp.'/services/ProjectConfig.php',"<?php class ProjectConfig { public static function projectId():string{return 'anytour';} public static function get(string \$key,\$default=null){return \$key==='brand.name'?'Synthetic project':\$default;} }");
        foreach (['ManagerAuthService','ManagerConversationAccessPolicy'] as $class) file_put_contents($tmp.'/services/'.$class.'.php','<?php class '.$class.' {}');
        file_put_contents($tmp.'/config.php','<?php // isolated fixture bootstrap');
        // PHP's development server rejects hidden directory segments. Map the
        // exact RFC 8414 PHP path; never simulate a working directory index.
        file_put_contents($tmp.'/router.php','<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);if($path==="/.well-known/oauth-authorization-server/manager/ai-oauth.php"){require __DIR__.$path;return true;}return false;');
        file_put_contents($tmp.'/manager/fixture-session.php','<?php require dirname(__DIR__)."/services/ManagerRequestContext.php";ManagerRequestContext::startSession();session_regenerate_id(true);$_SESSION["manager_id"]=(int)($_GET["actor"]??1);$_SESSION["csrf"]="synthetic-http-csrf";header("Content-Type:application/json");echo "{}";');
        file_put_contents($tmp.'/manager/fixture-expire.php','<?php require dirname(__DIR__)."/services/ManagerRequestContext.php";ManagerRequestContext::startSession();foreach($_SESSION["manager_preparation_oauth_pending"]??[]as$id=>$pending)$_SESSION["manager_preparation_oauth_pending"][$id]["expires_at"]=1;header("Content-Type:application/json");echo "{}";');
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
        if (!$socket) throw new RuntimeException('OAuth HTTP fixture socket unavailable');
        $address=(string)stream_socket_get_name($socket,false);fclose($socket);
        $server=proc_open([PHP_BINARY,'-d','session.save_path='.$tmp.'/sessions','-d','display_errors=0','-S',$address,'-t',$tmp,$tmp.'/router.php'],
            [0=>['pipe','r'],1=>['file',$tmp.'/server.log','a'],2=>['file',$tmp.'/server.log','a']],$pipes);
        if (!is_resource($server)) throw new RuntimeException('OAuth HTTP fixture server unavailable');
        fclose($pipes[0]);
        $ready=false;
        for($i=0;$i<100;$i++) {
            if ($probe=@stream_socket_client('tcp://'.$address,$errno,$error,.05)) {fclose($probe);$ready=true;break;}
            usleep(10000);
        }
        if (!$ready) throw new RuntimeException('OAuth HTTP fixture not ready');
        if ($browserFixture) {
            // CLI-only, synthetic SQLite/cookies. The browser harness supplies
            // loopback TLS and intercepts the fixed external callback locally.
            echo 'OAUTH_BROWSER_FIXTURE '.json_encode(['origin'=>'http://'.$address,
                'authorization_path'=>'/manager/ai-oauth.php?'.http_build_query(
                    preparationOauthRequest($fixtureClient,str_repeat('q',43))+['ui_locales'=>'ru-RU','action'=>'authorize'],'','&',PHP_QUERY_RFC3986),
                'client_id'=>$fixtureClient,'verifier'=>str_repeat('q',43),
                'resource'=>ManagerPreparationOAuth::RESOURCE,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,
                'issuer'=>ManagerPreparationOAuth::ISSUER],JSON_THROW_ON_ERROR)."\n";
            flush();
            fgets(STDIN);
            return;
        }
        $request=static function(string $path,string $method='GET',string $body='',string $cookie='',string $type='application/x-www-form-urlencoded')use($address):array {
            $socket=stream_socket_client('tcp://'.$address,$errno,$error,3);
            if (!$socket) throw new RuntimeException('OAuth HTTP fixture client unavailable');
            stream_set_timeout($socket,3);
            $headers=$method.' '.$path." HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n";
            if ($cookie!=='') $headers.='Cookie: '.$cookie."\r\n";
            if ($method==='POST') $headers.='Content-Type: '.$type."\r\nContent-Length: ".strlen($body)."\r\n";
            fwrite($socket,$headers."\r\n".$body);
            $raw=stream_get_contents($socket);fclose($socket);
            [$head,$response]=array_pad(explode("\r\n\r\n",(string)$raw,2),2,'');
            preg_match('/\AHTTP\/1\.\d (\d+)/',$head,$match);
            preg_match('/\r\nLocation: ([^\r\n]+)/i',$head,$location);
            preg_match('/\r\nSet-Cookie: ([^;\r\n]+)/i',$head,$newCookie);
            return ['status'=>(int)($match[1]??0),'body'=>$response,'json'=>json_decode($response,true),'headers'=>$head,'location'=>$location[1]??'','cookie'=>$newCookie[1]??$cookie];
        };
        $issuerParts=parse_url(ManagerPreparationOAuth::ISSUER);
        $discoveryPath='/.well-known/oauth-authorization-server'.($issuerParts['path']??'');
        preparationOauthCheck('RFC8414 issuer resolves to explicit PHP discovery path', $discoveryPath==='/.well-known/oauth-authorization-server/manager/ai-oauth.php');
        $meta=$request($discoveryPath);
        preparationOauthCheck('exact path-bearing issuer discovery returns metadata without redirects or auth',
            $meta['status']===200 && $meta['location']==='' && $meta['cookie']==='' && $meta['json']===ManagerPreparationOAuth::authorizationMetadata()
            && $meta['json']['issuer']===ManagerPreparationOAuth::ISSUER);
        $method=$request($discoveryPath,'POST');
        preparationOauthCheck('explicit discovery PHP wrapper retains GET-only and no-store boundary',
            $method['status']===405 && $method['json']===['error'=>'invalid_request']
            && str_contains(strtolower($meta['headers']),'cache-control: no-store') && $method['cookie']==='');
        $resource=$request('/manager/ai-oauth.php?action=resource');
        preparationOauthCheck('resource discovery points at exact path-bearing authorization issuer', $resource['status']===200
            && $resource['json']===ManagerPreparationOAuth::resourceMetadata()
            && $resource['json']['authorization_servers']===[$meta['json']['issuer']]
            && $resource['json']['resource']==='https://app.anytoour.ru/manager/ai-mcp.php');
        $invalid=$request('/manager/ai-oauth.php?action=metadata&action=authorize');
        preparationOauthCheck('HTTP rejects duplicate query parameters before PHP collapse', $invalid['status']===400 && $invalid['location']==='');
        preparationOauthCheck('HTTP token endpoint refuses GET', $request('/manager/ai-oauth.php?action=token')['status']===405);
        $invalid=$request('/manager/ai-oauth.php?action=register','POST','{','', 'application/json');
        preparationOauthCheck('malformed registration JSON has bounded client error', $invalid['status']===400 && $invalid['json']===['error'=>'invalid_request']);
        $registration=$request('/manager/ai-oauth.php?action=register','POST',json_encode(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]]),'','application/json');
        preparationOauthCheck('HTTP DCR returns reusable public client ID without secret', $registration['status']===201 && isset($registration['json']['client_id']) && !isset($registration['json']['client_secret']));
        $client=$registration['json']['client_id'];
        $verifier=str_repeat('q',43);
        $authorization=preparationOauthRequest($client,$verifier);
        $url='/manager/ai-oauth.php?'.http_build_query(['action'=>'authorize']+$authorization,'','&',PHP_QUERY_RFC3986);
        $page=$request($url);
        preg_match('/href="(\/manager\/ai-oauth.php\?action=authorize&amp;resume=[a-f0-9]{48})"/',$page['body'],$match);
        $resume=html_entity_decode($match[1]??'',ENT_QUOTES,'UTF-8');
        preparationOauthCheck('anonymous authorization retains safe session-scoped resume and fixed login link',
            $page['status']===200 && $resume!=='' && str_contains($page['body'],'href="/manager/"') && !str_contains($page['body'],'name="decision"')
            && (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_grants')->fetchColumn()===0);
        preparationOauthCheck('OAuth browser response has no-store, no-referrer and frame protections',
            str_contains(strtolower($page['headers']),'cache-control: no-store') && str_contains(strtolower($page['headers']),'referrer-policy: no-referrer')
            && str_contains(strtolower($page['headers']),'x-frame-options: deny'));
        preg_match('/\r\nContent-Security-Policy: ([^\r\n]+)/i',$page['headers'],$policy);
        preparationOauthCheck('OAuth navigation policy permits only self and the pinned callback without relaxing other directives',
            ($policy[1]??'')==="default-src 'none'; style-src 'unsafe-inline'; form-action 'self' ".ManagerPreparationOAuth::REDIRECT_URI."; frame-ancestors 'none'; base-uri 'none'");
        $login=$request('/manager/fixture-session.php?actor=1','GET','',$page['cookie']);
        $cookie=$login['cookie'];
        $consent=$request($resume,'GET','',$cookie);
        $form=preparationOauthConsentForm($consent['body'],'allow');
        $pending=$form['fields']['pending_id']??'';
        preparationOauthCheck('active admin sees explicit two-scope consent after real session regeneration',
            $consent['status']===200 && $pending!=='' && str_contains($consent['body'],'value="allow"') && str_contains($consent['body'],'value="deny"')
            && (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_grants')->fetchColumn()===0);
        preparationOauthCheck('rendered consent form has canonical POST, exact hidden controls and both explicit submitters',
            $form['method']==='POST' && array_keys($form['fields'])===['pending_id','csrf']
            && preg_match('/\A[a-f0-9]{48}\z/D',$pending)===1 && $form['fields']['csrf']!=='' && $form['choices']===['allow','deny']);
        $bad=$request('/manager/ai-oauth.php?action=authorize','POST',http_build_query(['pending_id'=>$pending,'csrf'=>'wrong','decision'=>'allow']),'anytour_manager_panel=unknown-session');
        preparationOauthCheck('HTTP consent cannot authorize without the original session and CSRF', $bad['status']===403 && $bad['location']==='');
        $bad=$request($form['action'],'POST',http_build_query($form['fields']),$cookie);
        preparationOauthCheck('missing submitter is distinguishable without assuming consent or issuing a grant',
            $bad['status']===400 && $bad['json']===['error'=>'invalid_request','error_description'=>'consent_decision_missing'] && $bad['location']==='');
        $bad=$request($form['action'],'POST',http_build_query($form['fields']+['decision'=>'unexpected']),$cookie);
        preparationOauthCheck('unsupported submitter is distinguishable and never interpreted as consent',
            $bad['status']===400 && $bad['json']===['error'=>'invalid_request','error_description'=>'consent_decision_invalid']);
        $bad=$request($form['action'],'POST',json_encode($form['post']),$cookie,'application/json');
        preparationOauthCheck('wrong consent encoding has a fixed diagnostic and does not accept JSON fallback',
            $bad['status']===415 && $bad['json']===['error'=>'invalid_request','error_description'=>'consent_form_encoding']);
        $badPost=$form['post'];$badPost['pending_id']='malformed';
        $bad=$request($form['action'],'POST',http_build_query($badPost),$cookie);
        preparationOauthCheck('malformed pending reference has a fixed diagnostic',
            $bad['status']===400 && $bad['json']===['error'=>'invalid_request','error_description'=>'consent_request_invalid']);
        $badPost['pending_id']=str_repeat('f',48);
        $bad=$request($form['action'],'POST',http_build_query($badPost),$cookie);
        preparationOauthCheck('missing session request is distinguishable from expiry without reflecting any values',
            $bad['status']===400 && $bad['json']===['error'=>'invalid_request','error_description'=>'consent_request_missing']);
        $post=http_build_query($form['post']);
        $bad=$request('/manager/ai-oauth.php?action=authorize','POST',$post.'&csrf=wrong',$cookie);
        preparationOauthCheck('HTTP consent rejects duplicated CSRF fields before authorization',
            $bad['status']===400 && $bad['location']==='' && $bad['json']===['error'=>'invalid_request','error_description'=>'consent_form_invalid']
            && (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_grants')->fetchColumn()===0);
        $allowed=$request($form['action'],$form['method'],$post,$cookie);
        parse_str((string)parse_url($allowed['location'],PHP_URL_QUERY),$callback);
        preparationOauthCheck('HTTP explicit consent redirects only to pinned callback with issuer identification',
            $allowed['status']===303 && str_starts_with($allowed['location'],ManagerPreparationOAuth::REDIRECT_URI.'?')
            && ($callback['iss']??null)===ManagerPreparationOAuth::ISSUER && ($callback['state']??null)===$authorization['state'] && isset($callback['code']));
        $replayed=$request($form['action'],$form['method'],$post,$cookie);
        preparationOauthCheck('repeated form submission does not issue a second grant',
            $replayed['status']===400 && $replayed['json']===['error'=>'invalid_request','error_description'=>'consent_request_missing']
            && (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_grants')->fetchColumn()===1);
        $exchange=['grant_type'=>'authorization_code','client_id'=>$client,'resource'=>ManagerPreparationOAuth::RESOURCE,'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'code'=>$callback['code'],'code_verifier'=>$verifier];
        $bad=$request('/manager/ai-oauth.php?action=token','POST',http_build_query($exchange).'&resource%5B%5D=x');
        preparationOauthCheck('HTTP parser rejects bracketed audience parameters', $bad['status']===400);
        $tokens=$request('/manager/ai-oauth.php?action=token','POST',http_build_query($exchange));
        preparationOauthCheck('HTTP PKCE exchange authenticates with real service and persisted token digests',
            $tokens['status']===200 && $tokens['json']['expires_in']===3600 && (new ManagerPreparationOAuth($pdo))->verifyBearer('Bearer '.$tokens['json']['access_token'])===1);
        $active=new ManagerPreparationOAuth($pdo);
        $family=$active->connectionForBearer('Bearer '.$tokens['json']['access_token']);
        $disconnected=$request('/manager/ai-oauth.php?action=connections','POST',http_build_query(['grant_id'=>$family,'csrf'=>'synthetic-http-csrf']),$cookie);
        preparationOauthCheck('HTTP administrator disconnect immediately revokes existing bearer and event family',
            $disconnected['status']===303 && !$active->connectionActive($family,1) && $active->verifyBearer('Bearer '.$tokens['json']['access_token'])===null);
        $hotelUrl='/manager/ai-oauth.php?'.http_build_query(array_replace($authorization,['scope'=>ManagerPreparationOAuth::HOTEL_SCOPE,'action'=>'authorize']));
        $hotelConsent=$request($hotelUrl,'GET','',$cookie);
        $hotelForm=preparationOauthConsentForm($hotelConsent['body'],'deny');
        preparationOauthCheck('HTTP hotel-only consent describes the new queue and grants no reply-context permission',
            $hotelConsent['status']===200 && str_contains($hotelConsent['body'],'Управление очередью гостиничных исследований')
            && !str_contains($hotelConsent['body'],'Чтение очереди и контекста диалогов')
            && !str_contains($hotelConsent['body'],'Сохранение подготовленных ответов для проверки менеджером'));
        $hotelDenied=$request($hotelForm['action'],$hotelForm['method'],http_build_query($hotelForm['post']),$cookie);
        preparationOauthCheck('HTTP explicit hotel refusal creates no new grant',
            $hotelDenied['status']===303 && (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_grants')->fetchColumn()===1);
        $archiveUrl='/manager/ai-oauth.php?'.http_build_query(array_replace($authorization,['scope'=>ManagerPreparationOAuth::ARCHIVE_SCOPE,'action'=>'authorize']));
        $archiveConsent=$request($archiveUrl,'GET','',$cookie);
        $archiveForm=preparationOauthConsentForm($archiveConsent['body'],'deny');
        preparationOauthCheck('HTTP archive-only consent explicitly describes historical message access',
            $archiveConsent['status']===200&&str_contains($archiveConsent['body'],'Чтение сохранённых исторических Telegram-диалогов')
            &&!str_contains($archiveConsent['body'],'Чтение очереди и контекста диалогов')
            &&!str_contains($archiveConsent['body'],'Управление очередью гостиничных исследований'));
        $archiveDenied=$request($archiveForm['action'],$archiveForm['method'],http_build_query($archiveForm['post']),$cookie);
        preparationOauthCheck('HTTP explicit archive refusal creates no new grant',
            $archiveDenied['status']===303&&(int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_grants')->fetchColumn()===1);
        $expiring=$request($url,'GET','',$cookie);
        $expiredForm=preparationOauthConsentForm($expiring['body'],'allow');
        $request('/manager/fixture-expire.php','GET','',$cookie);
        $expired=$request($expiredForm['action'],$expiredForm['method'],http_build_query($expiredForm['post']),$cookie);
        preparationOauthCheck('expired consent form has a distinct fixed diagnostic and retains the ten-minute guard',
            $expired['status']===400 && $expired['json']===['error'=>'invalid_request','error_description'=>'consent_request_expired']
            && (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_grants')->fetchColumn()===1);
        $login=$request('/manager/fixture-session.php?actor=2');
        $denied=$request($url,'GET','',$login['cookie']);
        preparationOauthCheck('HTTP existing ordinary manager session receives no consent rights', $denied['status']===403 && $denied['json']===['error'=>'access_denied']);
        preparationOauthCheck('HTTP diagnostic output contains no raw access or refresh tokens',
            !str_contains((string)file_get_contents($tmp.'/server.log'),$tokens['json']['access_token'])
            && !str_contains((string)file_get_contents($tmp.'/server.log'),$tokens['json']['refresh_token']));
    } finally {
        if (is_resource($server)) {proc_terminate($server);proc_close($server);}
        $remove=static function(string $path)use(&$remove):void {
            if (is_link($path)||is_file($path)) {unlink($path);return;}
            if (is_dir($path)) {foreach(scandir($path)?:[]as $name) if($name!=='.'&&$name!=='..') $remove($path.'/'.$name);rmdir($path);}
        };
        $remove($tmp);
    }
}

try {
    if (PHP_SAPI==='cli' && in_array('--browser-fixture',$argv??[],true)) {
        preparationOauthHttpChecks(true);
        exit;
    }
    [$pdo,$clock,$oauth,$client]=preparationOauthFixture();
    $metadata=ManagerPreparationOAuth::authorizationMetadata();
    preparationOauthCheck('issuer path leaves origin resource and OAuth action endpoints unchanged',
        ManagerPreparationOAuth::ORIGIN==='https://app.anytoour.ru'
        && ManagerPreparationOAuth::ISSUER==='https://app.anytoour.ru/manager/ai-oauth.php'
        && ManagerPreparationOAuth::RESOURCE==='https://app.anytoour.ru/manager/ai-mcp.php'
        && ManagerPreparationOAuth::ENDPOINT==='https://app.anytoour.ru/manager/ai-oauth.php'
        && $metadata['authorization_endpoint']==='https://app.anytoour.ru/manager/ai-oauth.php?action=authorize'
        && $metadata['token_endpoint']==='https://app.anytoour.ru/manager/ai-oauth.php?action=token'
        && $metadata['registration_endpoint']==='https://app.anytoour.ru/manager/ai-oauth.php?action=register'
        && $metadata['revocation_endpoint']==='https://app.anytoour.ru/manager/ai-oauth.php?action=revoke');
    preparationOauthCheck('metadata pins issuer, audience, PKCE S256 and public DCR without machine grants',
        $metadata['issuer']===ManagerPreparationOAuth::ISSUER && $metadata['authorization_response_iss_parameter_supported']===true
        && $metadata['token_endpoint_auth_methods_supported']===['none'] && $metadata['code_challenge_methods_supported']===['S256']
        && !in_array('client_credentials',$metadata['grant_types_supported'],true)
        && ManagerPreparationOAuth::resourceMetadata()['resource']===ManagerPreparationOAuth::RESOURCE
        && ManagerPreparationOAuth::resourceMetadata()['scopes_supported']===['queue:read','prepared:write','hotel_work:write','archive:read','development_work:write']);
    preparationOauthCheck('only current active admin with canonical project membership is eligible',
        $oauth->activeAdmin(1) && !$oauth->activeAdmin(0) && !$oauth->activeAdmin(2) && !$oauth->activeAdmin(3) && !$oauth->activeAdmin(4));
    preparationOauthCheck('authorization never changes the operator working state', (int)$pdo->query('SELECT is_working FROM managers WHERE id=1')->fetchColumn()===0);
    foreach (['https://attacker.test/callback',ManagerPreparationOAuth::REDIRECT_URI.'/extra',ManagerPreparationOAuth::REDIRECT_URI.'?next=https://attacker.test','https://chatgpt.com.attacker.test/connector_platform_oauth_redirect'] as $redirect) {
        preparationOauthReject('DCR rejects non-exact callback '.$redirect,fn()=>$oauth->registerClient(['redirect_uris'=>[$redirect]],'synthetic'),'invalid_client_metadata');
    }
    preparationOauthReject('DCR rejects additional arbitrary redirect',fn()=>$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI,'https://attacker.test']],'synthetic'),'invalid_client_metadata');
    preparationOauthReject('DCR rejects client secret method',fn()=>$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI],'token_endpoint_auth_method'=>'client_secret_basic'],'synthetic'),'invalid_client_metadata');
    preparationOauthReject('DCR rejects machine to machine grant',fn()=>$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI],'grant_types'=>['authorization_code','client_credentials']],'synthetic'),'invalid_client_metadata');
    preparationOauthReject('DCR rejects malformed grant array safely',fn()=>$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI],'grant_types'=>[[]]],'synthetic'),'invalid_client_metadata');
    preparationOauthReject('DCR rejects customer send scope',fn()=>$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI],'scope'=>'queue:read customer:send'],'synthetic'),'invalid_scope');
    preparationOauthCheck('registration stores only generated public identity, fixed callback and bounded hash bucket',
        (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_clients')->fetchColumn()===1
        && preg_match('/\A[a-f0-9]{48}\z/D',$client)===1
        && !str_contains(json_encode($pdo->query('SELECT * FROM manager_preparation_oauth_clients')->fetchAll()),'synthetic-registration-source'));

    $request=preparationOauthRequest($client,str_repeat('a',43));
    foreach ([['resource','https://other.test/mcp','invalid_target'],['redirect_uri','https://attacker.test','invalid_request'],['code_challenge_method','plain','invalid_request'],['code_challenge','bad','invalid_request'],['client_id',str_repeat('b',48),'invalid_client'],['scope','queue:read customer:send','invalid_scope'],['scope','queue:read','invalid_scope'],['state','', 'invalid_request'],['resource',[ManagerPreparationOAuth::RESOURCE],'invalid_request']] as [$field,$value,$error]) {
        $bad=$request; $bad[$field]=$value;
        preparationOauthReject('authorization rejects '.$field.' '.$error,fn()=>$oauth->validateAuthorization($bad),$error);
    }
    $session=['csrf'=>'synthetic-session-csrf'];
    $pending=$oauth->beginAuthorization($session,$request);
    preparationOauthCheck('GET authorization stores bounded request only and issues no grant or code',
        count($session['manager_preparation_oauth_pending'])===1 && (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_grants')->fetchColumn()===0);
    preparationOauthReject('consent CSRF rejects a guessed token',function()use($oauth,&$session,$pending){$oauth->completeAuthorization($session,$pending,1,'wrong',true);},'csrf');
    preparationOauthReject('ordinary manager cannot approve a grant',function()use($oauth,&$session,$pending){$oauth->completeAuthorization($session,$pending,2,$session['csrf'],true);},'access_denied');
    preparationOauthReject('inactive administrator cannot approve a grant',function()use($oauth,&$session,$pending){$oauth->completeAuthorization($session,$pending,3,$session['csrf'],true);},'access_denied');
    preparationOauthReject('outside-project administrator cannot approve a grant',function()use($oauth,&$session,$pending){$oauth->completeAuthorization($session,$pending,4,$session['csrf'],true);},'access_denied');
    preparationOauthCheck('failed authorization has no OAuth writes',(int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_grants')->fetchColumn()===0);
    $deny=$oauth->completeAuthorization($session,$pending,1,$session['csrf'],false);
    parse_str((string)parse_url($deny,PHP_URL_QUERY),$denied);
    preparationOauthCheck('explicit denial returns issuer and original state to exact callback without grant',
        $denied['error']==='access_denied' && $denied['iss']===ManagerPreparationOAuth::ISSUER && $denied['state']===$request['state']
        && str_starts_with($deny,ManagerPreparationOAuth::REDIRECT_URI.'?') && (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_grants')->fetchColumn()===0);
    preparationOauthReject('consent request is single-use',function()use($oauth,&$session,$pending){$oauth->completeAuthorization($session,$pending,1,$session['csrf'],true);},'invalid_request');

    $session=['csrf'=>'synthetic'];
    for($i=0;$i<10;$i++) $oauth->beginAuthorization($session,$request);
    preparationOauthCheck('session authorization requests are capped at five',count($session['manager_preparation_oauth_pending'])===5);
    $pending=$oauth->beginAuthorization($session,$request);
    $clock->now+=600;
    preparationOauthReject('pending consent expires after ten minutes',function()use($oauth,&$session,$pending){$oauth->pendingAuthorization($session,$pending,1);},'invalid_request');

    [$code,$exchange,$target,$consumedSession,$pending]=preparationOauthGrant($oauth,$client);
    parse_str((string)parse_url($target,PHP_URL_QUERY),$success);
    preparationOauthCheck('explicit admin consent returns code plus exact issuer and state',
        $success['iss']===ManagerPreparationOAuth::ISSUER && $success['state']===$request['state']
        && preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$code)===1 && !isset($consumedSession['manager_preparation_oauth_pending'][$pending]));
    preparationOauthCheck('authorization code is stored only by SHA256 digest',
        $pdo->query('SELECT code_hash FROM manager_preparation_oauth_codes')->fetchColumn()===hash('sha256',$code)
        && !str_contains(json_encode($pdo->query('SELECT * FROM manager_preparation_oauth_codes')->fetchAll()),$code));
    $bad=$exchange; $bad['code_verifier']=str_repeat('z',43);
    preparationOauthReject('intercepted code cannot exchange without original verifier',fn()=>$oauth->exchange($bad),'invalid_grant');
    $bad=$exchange; $bad['resource']='https://other.test/mcp';
    preparationOauthReject('token exchange pins the intended resource',fn()=>$oauth->exchange($bad),'invalid_target');
    $bad=$exchange; $bad['redirect_uri']=ManagerPreparationOAuth::REDIRECT_URI.'/other';
    preparationOauthReject('token exchange binds exact authorization callback',fn()=>$oauth->exchange($bad),'invalid_grant');
    $second=$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'second-source');
    $bad=$exchange; $bad['client_id']=$second['client_id'];
    preparationOauthReject('code cannot exchange under another registered public client',fn()=>$oauth->exchange($bad),'invalid_grant');
    $bad=$exchange; $bad['client_secret']='forbidden';
    preparationOauthReject('public client does not accept client-secret authentication',fn()=>$oauth->exchange($bad),'invalid_client');
    $tokens=$oauth->exchange($exchange);
    preparationOauthCheck('valid PKCE issues one-hour bearer and separate rotating refresh token',
        $tokens['expires_in']===3600 && $tokens['token_type']==='Bearer' && $tokens['scope']==='queue:read prepared:write'
        && $tokens['access_token']!==$tokens['refresh_token'] && $oauth->verifyBearer('Bearer '.$tokens['access_token'])===1);
    $family=$oauth->connectionForBearer('Bearer '.$tokens['access_token']);
    preparationOauthCheck('connection identity is a stable opaque grant bound to exact administrator',
        is_string($family) && preg_match('/\A[a-f0-9]{32}\z/D',$family)===1 && $oauth->connectionActive($family,1) && !$oauth->connectionActive($family,4));
    preparationOauthCheck('raw access and refresh tokens never appear in persistent OAuth tables',
        !str_contains(json_encode($pdo->query('SELECT * FROM manager_preparation_oauth_tokens')->fetchAll()),$tokens['access_token'])
        && !str_contains(json_encode($pdo->query('SELECT * FROM manager_preparation_oauth_tokens')->fetchAll()),$tokens['refresh_token']));
    preparationOauthCheck('only access bearer header authenticates, never refresh/basic/query-like strings',
        $oauth->verifyBearer('Bearer '.$tokens['refresh_token'])===null && $oauth->verifyBearer('Basic '.$tokens['access_token'])===null
        && $oauth->verifyBearer($tokens['access_token'])===null && $oauth->verifyBearer('Bearer '.$tokens['access_token']."\n")===null);

    $pdo->exec('DELETE FROM manager_projects WHERE manager_id=1');
    preparationOauthCheck('membership revocation immediately invalidates bearer and persistent connection',
        $oauth->verifyBearer('Bearer '.$tokens['access_token'])===null && !$oauth->connectionActive($family,1));
    $pdo->exec('INSERT INTO manager_projects VALUES(1,1)');
    $pdo->exec("UPDATE managers SET role='manager' WHERE id=1");
    preparationOauthCheck('admin demotion immediately invalidates all scoped access', $oauth->verifyBearer('Bearer '.$tokens['access_token'])===null && !$oauth->connectionActive($family,1));
    $pdo->exec("UPDATE managers SET role='admin',is_active=0 WHERE id=1");
    preparationOauthCheck('account deactivation invalidates bearer and callback connection', $oauth->verifyBearer('Bearer '.$tokens['access_token'])===null && !$oauth->connectionActive($family,1));
    $pdo->exec('UPDATE managers SET is_active=1 WHERE id=1');
    $pdo->exec('UPDATE projects SET is_active=0 WHERE id=1');
    preparationOauthCheck('project deactivation immediately invalidates grant', $oauth->verifyBearer('Bearer '.$tokens['access_token'])===null);
    $pdo->exec('UPDATE projects SET is_active=1 WHERE id=1');
    $pdo->exec("UPDATE projects SET display_name='Not canonical' WHERE id=1");
    preparationOauthCheck('canonical project mismatch fails closed without repairing metadata', $oauth->verifyBearer('Bearer '.$tokens['access_token'])===null && $pdo->query('SELECT display_name FROM projects WHERE id=1')->fetchColumn()==='Not canonical');
    $pdo->exec("UPDATE projects SET display_name='Synthetic project' WHERE id=1");
    foreach (['resource'=> 'https://other.test/mcp','scopes'=>'queue:read customer:send','project_key'=>'other'] as $column=>$value) {
        $original=$pdo->query('SELECT '.$column.' FROM manager_preparation_oauth_grants')->fetchColumn();
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET '.$column.'=?')->execute([$value]);
        preparationOauthCheck('persisted '.$column.' mismatch cannot authenticate', $oauth->verifyBearer('Bearer '.$tokens['access_token'])===null && !$oauth->connectionActive($family,1));
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET '.$column.'=?')->execute([$original]);
    }

    $clock->now+=3600;
    preparationOauthCheck('expired access token does not authenticate but refresh family remains active', $oauth->verifyBearer('Bearer '.$tokens['access_token'])===null && $oauth->connectionActive($family,1));
    $refresh=['grant_type'=>'refresh_token','client_id'=>$client,'resource'=>ManagerPreparationOAuth::RESOURCE,'refresh_token'=>$tokens['refresh_token']];
    $rotated=$oauth->exchange($refresh);
    preparationOauthCheck('refresh rotates tokens while preserving connection identity and scope',
        $rotated['refresh_token']!==$tokens['refresh_token'] && $oauth->verifyBearer('Bearer '.$rotated['access_token'])===1
        && $oauth->connectionForBearer('Bearer '.$rotated['access_token'])===$family);
    preparationOauthReject('refresh-token replay is refused',fn()=>$oauth->exchange($refresh),'invalid_grant');
    preparationOauthCheck('refresh replay revokes whole family and stops persistent subscriptions',
        $oauth->verifyBearer('Bearer '.$rotated['access_token'])===null && !$oauth->connectionActive($family,1));
    $refresh['refresh_token']=$rotated['refresh_token'];
    preparationOauthReject('newest refresh also fails after family replay revocation',fn()=>$oauth->exchange($refresh),'invalid_grant');

    [$code,$exchange]=preparationOauthGrant($oauth,$client);
    $tokens=$oauth->exchange($exchange);
    $family=$oauth->connectionForBearer('Bearer '.$tokens['access_token']);
    preparationOauthReject('authorization-code replay is refused',fn()=>$oauth->exchange($exchange),'invalid_grant');
    preparationOauthCheck('authorization-code replay revokes issued token family', $oauth->verifyBearer('Bearer '.$tokens['access_token'])===null && !$oauth->connectionActive($family,1));

    [$code,$exchange]=preparationOauthGrant($oauth,$client);
    $clock->now+=300;
    preparationOauthReject('authorization code expires after five minutes',fn()=>$oauth->exchange($exchange),'invalid_grant');
    preparationOauthCheck('failed exchange leaves no open transaction',!$pdo->inTransaction());

    [$code,$exchange]=preparationOauthGrant($oauth,$client);
    $tokens=$oauth->exchange($exchange);
    $family=$oauth->connectionForBearer('Bearer '.$tokens['access_token']);
    preparationOauthReject('disconnect requires valid session CSRF',fn()=>$oauth->disconnect(['csrf'=>'expected'],1,$family,'wrong'),'csrf');
    preparationOauthCheck('bad disconnect has no effect', $oauth->connectionActive($family,1));
    $oauth->revoke(['client_id'=>$second['client_id'],'token'=>$tokens['refresh_token']]);
    preparationOauthCheck('another OAuth client cannot revoke a known token family', $oauth->connectionActive($family,1));
    $oauth->revoke(['client_id'=>$client,'token'=>$tokens['refresh_token']]);
    preparationOauthCheck('RFC7009 revocation invalidates bearer and connection immediately', !$oauth->connectionActive($family,1) && $oauth->verifyBearer('Bearer '.$tokens['access_token'])===null);
    $oauth->revoke(['client_id'=>$client,'token'=>str_repeat('x',43)]);
    preparationOauthCheck('unknown revocation is non-disclosing success',true);

    [$code,$exchange]=preparationOauthGrant($oauth,$client);
    $tokens=$oauth->exchange($exchange);
    $family=$oauth->connectionForBearer('Bearer '.$tokens['access_token']);
    $oauth->disconnect(['csrf'=>'expected'],1,$family,'expected');
    preparationOauthCheck('administrator can explicitly disconnect own connection', !$oauth->connectionActive($family,1));

    [$code,$exchange]=preparationOauthGrant($oauth,$client);
    $tokens=$oauth->exchange($exchange);
    $family=$oauth->connectionForBearer('Bearer '.$tokens['access_token']);
    $clock->now+=30*86400;
    preparationOauthCheck('thirty-day absolute grant cap also stops event callbacks', !$oauth->connectionActive($family,1));
    preparationOauthReject('refresh cannot extend consent beyond absolute cap',fn()=>$oauth->exchange(['grant_type'=>'refresh_token','client_id'=>$client,'resource'=>ManagerPreparationOAuth::RESOURCE,'refresh_token'=>$tokens['refresh_token']]),'invalid_grant');

    [$pdo,$clock,$oauth,$client]=preparationOauthFixture();
    [$code,$exchange]=preparationOauthGrant($oauth,$client);
    $tokens=$oauth->exchange($exchange);
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    $family=ManagerPreparationOAuth::connectionIdForBearer('Bearer '.$tokens['access_token']);
    preparationOauthCheck('production static entry points share the same complete authorization checks',
        ManagerPreparationOAuth::authenticateBearer('Bearer '.$tokens['access_token'])===1 && is_string($family)
        && ManagerPreparationOAuth::isConnectionActive($family,1));
    $pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');
    preparationOauthCheck('production static entry points fail closed after deactivation',
        ManagerPreparationOAuth::authenticateBearer('Bearer '.$tokens['access_token'])===null
        && ManagerPreparationOAuth::connectionIdForBearer('Bearer '.$tokens['access_token'])===null
        && !ManagerPreparationOAuth::isConnectionActive($family,1));
    $pdo->exec('DROP TABLE manager_preparation_oauth_tokens');
    preparationOauthCheck('production auth fails closed on unavailable storage without leaking details', ManagerPreparationOAuth::authenticateBearer('Bearer '.$tokens['access_token'])===null);

    [$pdo,$clock,$oauth,$client]=preparationOauthFixture();
    for($i=0;$i<30;$i++) $oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'abuse-source');
    preparationOauthReject('public DCR applies bounded per-source hourly registration budget',fn()=>$oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'abuse-source'),'temporarily_unavailable');
    $clock->now+=3601;
    preparationOauthCheck('registration budget renews without deleting stable client identities',is_string($oauth->registerClient(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]],'abuse-source')['client_id']) && (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_oauth_clients')->fetchColumn()===32);
    preparationOauthHttpChecks();
    echo 'OK '.$passed." manager preparation OAuth checks\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage()."\n");
    exit(1);
}
