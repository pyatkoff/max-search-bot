from pathlib import Path
import json

changed = []
def replace(path, old, new):
    p = Path(path)
    text = p.read_text()
    if text.count(old) != 1:
        raise SystemExit(f'Expected one source marker in {path}, found {text.count(old)}')
    p.write_text(text.replace(old, new))
    if path not in changed:
        changed.append(path)

path = 'services/ManagerPreparationOAuth.php'
s = Path(path).read_text()
start = s.index('    /** Cron only: accept old or isolated resource')
end = s.index('    public static function authenticateBearer', start)
replace(path, s[start:end], r'''    /** Cron only: resolve a known canonical audience before deciding authorization.
     * Missing/inconsistent authority is unavailable, never a durable revocation. */
    public function eventConnectionAuthorizedAcrossProfiles(string $domain,string $familyId,int $adminId): bool
    {
        [$profiles,$required]=match ($domain) {
            'replies'=>[[self::PROFILE_LEGACY,self::PROFILE_REPLIES],self::SCOPES],
            'hotels'=>[[self::PROFILE_LEGACY,self::PROFILE_HOTELS],[self::HOTEL_SCOPE]],
            default=>throw new InvalidArgumentException('invalid_event_domain'),
        };
        try {
            if ($this->pdo->inTransaction()||$adminId<1||preg_match('/\A[a-f0-9]{32}\z/D',$familyId)!==1) throw new RuntimeException();
            $read=function()use($familyId,$adminId):array {
                $q=$this->pdo->prepare('SELECT g.*,c.redirect_uri AS profile_redirect_uri FROM manager_preparation_oauth_grants g LEFT JOIN manager_preparation_oauth_clients c ON c.client_id=g.client_id WHERE g.grant_id=? AND g.admin_id=? LIMIT 1');
                if (!$q||!$q->execute([$familyId,$adminId])) throw new RuntimeException();
                $grant=$q->fetch(PDO::FETCH_ASSOC);
                if (!is_array($grant)||($grant['grant_id']??null)!==$familyId
                    ||($grant['profile_redirect_uri']??null)!==self::REDIRECT_URI
                    ||($grant['project_key']??null)!==ProjectConfig::projectId()) throw new RuntimeException();
                $profile=match ($grant['resource']??null) {
                    self::RESOURCE=>self::PROFILE_LEGACY,
                    self::DEVELOPMENT_RESOURCE=>self::PROFILE_DEVELOPMENT,
                    self::ORIGIN.'/manager/replies-mcp.php'=>self::PROFILE_REPLIES,
                    self::ORIGIN.'/manager/hotels-mcp.php'=>self::PROFILE_HOTELS,
                    default=>throw new RuntimeException(),
                };
                $canonical=new self($this->pdo,$this->clock,$profile);
                if (!$canonical->grantMatchesProfile($grant)) throw new RuntimeException();
                $knownTime=static fn($v):bool=>(is_int($v)&&$v>=0)
                    ||(is_string($v)&&preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/D',$v)===1);
                if (!$knownTime($grant['expires_at']??null)||!array_key_exists('revoked_at',$grant)
                    ||($grant['revoked_at']!==null&&!$knownTime($grant['revoked_at']))) throw new RuntimeException();
                return [$grant,$profile];
            };
            [$grant,$profile]=$read();
            if (!in_array($profile,$profiles,true)) return false;
            $canonical=new self($this->pdo,$this->clock,$profile);
            $authorized=$canonical->connectionAuthorized($familyId,$adminId,$required);
            if (!$authorized) {
                // A failed lookup cannot hide a missing grant/client or changed audience.
                [$current,$currentProfile]=$read();
                if ($currentProfile!==$profile||$current['client_id']!==$grant['client_id']
                    ||$current['project_key']!==$grant['project_key']) throw new RuntimeException();
            }
            return $authorized;
        } catch (Throwable $unavailable) {
            throw new RuntimeException('event_oauth_authority_unavailable',0,$unavailable);
        }
    }

''')

replace('services/ManagerPreparationMcpService.php',
        "return $remove?$this->unsubscribe($params,$admin):$this->subscribe($params,$admin,$connection);",
        "return $remove?$this->unsubscribe($params,$admin,$connection):$this->subscribe($params,$admin,$connection);")
replace('services/ManagerPreparationMcpService.php', r'''    private function unsubscribe(array $params,int $admin): object
    {
        [$id]=$this->subscriptionIdentity($params,$admin,false);
        $this->pdo->prepare('UPDATE manager_preparation_events SET is_active=0,pending_event_id=NULL,pending_body=NULL,pending_jobs_json=NULL,updated_at=? WHERE subscription_id=? AND admin_id=?')
            ->execute([gmdate('Y-m-d H:i:s',$this->now()),$id,$admin]);
        return (object)[];
    }''', r'''    private function unsubscribe(array $params,int $admin,?string $connection): object
    {
        if (!is_string($connection)||!preg_match('/\A[a-f0-9]{32}\z/D',$connection)
            ||!$this->activeConnection($connection,$admin)) throw new DomainException();
        [$id]=$this->subscriptionIdentity($params,$admin,false);
        // Same administrator/callback is not authority over another OAuth binding.
        // In particular, cleanup from an old connection must not stop its replacement.
        $this->pdo->prepare('UPDATE manager_preparation_events SET is_active=0,pending_event_id=NULL,pending_body=NULL,pending_jobs_json=NULL,updated_at=? WHERE subscription_id=? AND admin_id=? AND project_key=? AND oauth_connection_id=?')
            ->execute([gmdate('Y-m-d H:i:s',$this->now()),$id,$admin,self::PROJECT,$connection]);
        return (object)[];
    }''')

extra_tests = r'''
// Reproduce the two independent review findings using real canonical grants.
function unavailableSplit(string $name,callable $call): void
{
    try {$call();} catch (RuntimeException $e) {
        checkSplit($name,$e->getMessage()==='event_oauth_authority_unavailable');return;
    }
    throw new RuntimeException('FAIL '.$name.' converted unknown authority to a boolean');
}
$pdo->exec("CREATE TABLE manager_preparation_events (
 subscription_id TEXT PRIMARY KEY,admin_id INTEGER,oauth_connection_id TEXT,project_key TEXT,event_name TEXT,
 arguments_json TEXT,callback_url TEXT,signing_secret TEXT,previous_secret TEXT,previous_secret_until TEXT,
 verified_at TEXT,expires_at TEXT,is_active INTEGER,observed_jobs_json TEXT,pending_event_id TEXT,pending_body TEXT,
 pending_jobs_json TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,lease_token TEXT,lease_until TEXT,
 last_batch_hash TEXT,last_status INTEGER,created_at TEXT,updated_at TEXT)");
$callback='https://callbacks.example/reply-shared';$arguments=json_encode(['project_key'=>'anytour'],JSON_THROW_ON_ERROR);
$subscription='sub_'.hash('sha256',json_encode([1,$callback,ManagerPreparationMcpService::EVENT,$arguments],JSON_THROW_ON_ERROR));
$seedReply=function(string $connection)use($pdo,$now,$subscription,$arguments,$callback):void {
    $pdo->exec('DELETE FROM manager_preparation_events');
    $date=gmdate('Y-m-d H:i:s',$now);
    $row=['subscription_id'=>$subscription,'admin_id'=>1,'oauth_connection_id'=>$connection,'project_key'=>'anytour',
        'event_name'=>ManagerPreparationMcpService::EVENT,'arguments_json'=>$arguments,'callback_url'=>$callback,
        'signing_secret'=>'whsec_'.base64_encode(str_repeat('k',32)),'verified_at'=>$date,
        'expires_at'=>gmdate('Y-m-d H:i:s',$now+3600),'is_active'=>1,'observed_jobs_json'=>'[]',
        'pending_event_id'=>'synthetic-pending','pending_body'=>'synthetic-body','pending_jobs_json'=>'["synthetic"]',
        'attempts'=>0,'created_at'=>$date,'updated_at'=>$date];
    $pdo->prepare('INSERT INTO manager_preparation_events ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
};
$replyRow=fn():array=>$pdo->query('SELECT * FROM manager_preparation_events')->fetch(PDO::FETCH_ASSOC);
$unsubscribe=rpcSplit('events/unsubscribe',['name'=>ManagerPreparationMcpService::EVENT,
    'arguments'=>['project_key'=>'anytour'],'delivery'=>['mode'=>'webhook','url'=>$callback]]);
$legacyService=new ManagerPreparationMcpService($pdo,$store,clock:$clock);
foreach (['legacy','replies'] as $owner) {
    $seedReply($ctx[$owner]['connection_id']);$before=$replyRow();
    $other=$owner==='legacy'?'replies':'legacy';
    $service=$other==='legacy'?$legacyService:$services['replies'];
    $response=$service->handle($unsubscribe,1,$ctx[$other]['connection_id'],$ctx[$other]['scopes']);
    checkSplit('foreign unsubscribe preserves full row '.$owner,!isset($response['error'])&&$replyRow()===$before);
    $service=$owner==='legacy'?$legacyService:$services['replies'];
    $response=$service->handle($unsubscribe,1,$ctx[$owner]['connection_id'],$ctx[$owner]['scopes']);
    $after=$replyRow();
    checkSplit('owner unsubscribe succeeds '.$owner,!isset($response['error'])&&(int)$after['is_active']===0&&$after['pending_event_id']===null);
    $service->handle($unsubscribe,1,$ctx[$owner]['connection_id'],$ctx[$owner]['scopes']);
    checkSplit('owner unsubscribe remains idempotent '.$owner,$replyRow()===$after);
}
$pdo->exec('DELETE FROM manager_preparation_events');
foreach (['replies','hotels'] as $domain) {
    $grantId=$ctx[$domain]['connection_id'];
    $original=$pdo->query("SELECT * FROM manager_preparation_oauth_grants WHERE grant_id='$grantId'")->fetch(PDO::FETCH_ASSOC);
    foreach (['missing_grant','missing_client','unknown_audience','invalid_scope'] as $failure) {
        $field=match($failure){'missing_grant'=>'grant_id','missing_client'=>'client_id','unknown_audience'=>'resource','invalid_scope'=>'scopes'};
        $bad=$failure==='missing_grant'?str_repeat('e',32):'synthetic-unavailable';
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET '.$field.'=? WHERE grant_id=?')->execute([$bad,$grantId]);
        unavailableSplit('cron unavailable '.$domain.'/'.$failure,fn()=>$background->eventConnectionAuthorizedAcrossProfiles($domain,$grantId,1));
        if ($domain==='replies') {
            $seedReply($grantId);$before=$replyRow();$beforeCalls=count($calls);
            $delivery=new ManagerPreparationMcpService($pdo,$store,clock:$clock,
                connectionActive:fn(string $connection,int $admin):bool=>$background->eventConnectionAuthorizedAcrossProfiles('replies',$connection,$admin));
            $counts=$delivery->deliverPending();$after=$replyRow();
            $mutable=array_flip(['next_attempt_at','lease_token','lease_until','updated_at']);
            checkSplit('reply delivery defers without stopping '.$failure,$counts['stopped']===0&&$counts['retried']===1
                &&array_diff_key($before,$mutable)===array_diff_key($after,$mutable)&&count($calls)===$beforeCalls);
        }
        $where=$field==='grant_id'?$bad:$grantId;
        $pdo->prepare('UPDATE manager_preparation_oauth_grants SET '.$field.'=? WHERE grant_id=?')->execute([$original[$field],$where]);
        checkSplit('authority resumes after repair '.$domain.'/'.$failure,$background->eventConnectionAuthorizedAcrossProfiles($domain,$grantId,1));
    }
    $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([$now,$grantId]);
    checkSplit('known revocation remains denial '.$domain,!$background->eventConnectionAuthorizedAcrossProfiles($domain,$grantId,1));
    $pdo->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=NULL WHERE grant_id=?')->execute([$grantId]);
}
$pdo->exec('DELETE FROM manager_preparation_events');
$pdo->beginTransaction();
unavailableSplit('cron refuses transaction snapshot',fn()=>$background->eventConnectionAuthorizedAcrossProfiles('replies',$r['connection_id'],1));
$pdo->rollBack();
// A missing canonical row must not permanently stop a hotel delivery either.
require_once __DIR__.'/fixtures/hotel_work_events.php';
$f=new HotelEventsFixture();$f->subscribe(false);
$f->pdo->exec('CREATE TABLE manager_preparation_oauth_grants(grant_id TEXT,admin_id INTEGER,client_id TEXT);
 CREATE TABLE manager_preparation_oauth_clients(client_id TEXT,redirect_uri TEXT)');
$hotelBackground=new ManagerPreparationOAuth($f->pdo,fn():int=>$f->at);
$hotelDelivery=new HotelWorkEventService($f->pdo,$f->webhook,fn():int=>$f->at,
    fn(string $connection,int $admin,array $required):bool=>$hotelBackground->eventConnectionAuthorizedAcrossProfiles('hotels',$connection,$admin),$f->queue);
$before=$f->row();$queueBefore=$f->status();$result=$hotelDelivery->deliverPending();$after=$f->row();
$mutable=array_flip(['next_attempt_at','lease_token','lease_until','updated_at']);
checkSplit('hotel delivery defers unknown grant preserving work',$result['stopped']===0
    &&array_diff_key($before,$mutable)===array_diff_key($after,$mutable)&&$f->requests===[]&&$f->status()===$queueBefore);
// Restore the original synthetic service context for the remaining revocation tests.
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
'''
replace('tests/run_separated_mcp_regression.php', "$oauth['replies']->disconnect(['csrf'=>'csrf'],1,$r['connection_id'],'csrf');", extra_tests+"\n$oauth['replies']->disconnect(['csrf'=>'csrf'],1,$r['connection_id'],'csrf');")

# Exercise the actual new OAuth/discovery entrypoints in the existing isolated HTTP fixture.
copy_marker = "        preparationOauthCheck('HTTP fixture executes exact OAuth endpoint and service bytes'"
p = Path('tests/run_manager_preparation_oauth_regression.php')
s = p.read_text()
pos = s.index(copy_marker)
copy_code = r'''        // Use exact production route/helper bytes, but only the existing temporary test DB.
        foreach (['replies','hotels'] as $splitProfile) {
            foreach (['manager/'.$splitProfile.'-oauth.php',
                '.well-known/oauth-authorization-server/manager/'.$splitProfile.'-oauth.php',
                '.well-known/oauth-protected-resource/manager/'.$splitProfile.'-mcp.php'] as $splitFile) {
                if (!is_dir(dirname($tmp.'/'.$splitFile))) mkdir(dirname($tmp.'/'.$splitFile),0700,true);
                if (!copy($root.'/'.$splitFile,$tmp.'/'.$splitFile)) throw new RuntimeException('split_fixture_copy');
            }
        }
        if (!copy($root.'/manager/lib/SeparatedMcpOAuthHttp.php',$tmp.'/manager/lib/SeparatedMcpOAuthHttp.php')) throw new RuntimeException('split_fixture_copy');
'''
s = s[:pos]+copy_code+s[pos:]
http_tests = r'''
        $splitIssued=[];
        foreach (['replies','hotels'] as $splitProfile) {
            $definition=ManagerPreparationOAuth::profileDefinition($splitProfile);
            $route='/manager/'.$splitProfile.'-oauth.php';
            $resourceRoute='/.well-known/oauth-protected-resource/manager/'.$splitProfile.'-mcp.php';
            $metadataRoute='/.well-known/oauth-authorization-server/manager/'.$splitProfile.'-oauth.php';
            $meta=$request($metadataRoute);$resource=$request($resourceRoute);
            preparationOauthCheck('HTTP isolated issuer/resource '.$splitProfile,$meta['status']===200&&$resource['status']===200
                &&$meta['json']===ManagerPreparationOAuth::authorizationMetadata($splitProfile)
                &&$resource['json']===ManagerPreparationOAuth::resourceMetadata($splitProfile));
            $wrongMethod=$request($metadataRoute,'POST');
            preparationOauthCheck('HTTP isolated discovery GET only '.$splitProfile,$wrongMethod['status']===405);
            $registration=$request($route.'?action=register','POST',json_encode(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI]]),'','application/json');
            $splitClient=$registration['json']['client_id']??'';
            preparationOauthCheck('HTTP isolated registration '.$splitProfile,$registration['status']===201
                &&str_starts_with($splitClient,$definition['prefix'])&&strlen($splitClient)===48
                &&($registration['json']['scope']??null)===implode(' ',$definition['defaults']));
            $badRegistration=$request($route.'?action=register','POST',json_encode(['redirect_uris'=>[ManagerPreparationOAuth::REDIRECT_URI],
                'scope'=>'queue:read prepared:write hotel_work:write']),'','application/json');
            preparationOauthCheck('HTTP isolated scope escalation blocked '.$splitProfile,$badRegistration['status']===400&&($badRegistration['json']['error']??null)==='invalid_scope');
            $authorization=array_replace(preparationOauthRequest($splitClient,$verifier),['resource'=>$definition['resource'],'scope'=>implode(' ',$definition['scopes'])]);
            $url=$route.'?'.http_build_query(['action'=>'authorize']+$authorization,'','&',PHP_QUERY_RFC3986);
            $consent=$request($url,'GET','',$devCookie);
            $form=preparationOauthConsentForm($consent['body'],'allow',$route.'?action=authorize');
            preparationOauthCheck('HTTP isolated consent permissions '.$splitProfile,$consent['status']===200
                &&str_contains($consent['body'],$splitProfile==='replies'?'Сохранение подготовленных ответов':'Управление очередью гостиничных исследований')
                &&!str_contains($consent['body'],$splitProfile==='replies'?'<li>Управление очередью гостиничных исследований':'<li>Чтение очереди и контекста диалогов'));
            $badCsrf=$request($form['action'],'POST',http_build_query(array_replace($form['post'],['csrf'=>'invalid-csrf'])),$devCookie);
            preparationOauthCheck('HTTP isolated consent rejects CSRF '.$splitProfile,$badCsrf['status']===403&&($badCsrf['json']['error']??null)==='csrf');
            $opposite=$splitProfile==='replies'?'hotels':'replies';
            $crossPending=$request('/manager/'.$opposite.'-oauth.php?action=authorize','POST',http_build_query($form['post']),$devCookie);
            preparationOauthCheck('HTTP isolated pending context fenced '.$splitProfile,$crossPending['status']===400&&($crossPending['json']['error_description']??null)==='consent_request_missing');
            $allowed=$request($form['action'],$form['method'],http_build_query($form['post']),$devCookie);
            parse_str((string)parse_url($allowed['location'],PHP_URL_QUERY),$callback);
            preparationOauthCheck('HTTP isolated consent callback '.$splitProfile,$allowed['status']===303
                &&str_starts_with($allowed['location'],ManagerPreparationOAuth::REDIRECT_URI.'?')
                &&($callback['iss']??null)===$definition['issuer']&&isset($callback['code']));
            $exchange=['grant_type'=>'authorization_code','client_id'=>$splitClient,'resource'=>$definition['resource'],
                'redirect_uri'=>ManagerPreparationOAuth::REDIRECT_URI,'code'=>$callback['code'],'code_verifier'=>$verifier];
            $crossToken=$request('/manager/'.$opposite.'-oauth.php?action=token','POST',http_build_query($exchange));
            preparationOauthCheck('HTTP isolated foreign issuer rejects code '.$splitProfile,$crossToken['status']===400&&($crossToken['json']['error']??null)==='invalid_client');
            $issued=$request($route.'?action=token','POST',http_build_query($exchange));
            $owner=new ManagerPreparationOAuth($pdo,null,$splitProfile);
            preparationOauthCheck('HTTP isolated code still exchangeable '.$splitProfile,$issued['status']===200
                &&($issued['json']['scope']??null)===implode(' ',$definition['scopes'])
                &&$owner->verifyBearer('Bearer '.$issued['json']['access_token'])===1);
            $family=$owner->connectionForBearer('Bearer '.$issued['json']['access_token']);
            $ownPage=$request($route.'?action=connections','GET','',$devCookie);
            $foreignPage=$request('/manager/'.$opposite.'-oauth.php?action=connections','GET','',$devCookie);
            preparationOauthCheck('HTTP isolated connection administration '.$splitProfile,$ownPage['status']===200&&$foreignPage['status']===200
                &&str_contains($ownPage['body'],$family)&&!str_contains($foreignPage['body'],$family));
            $splitIssued[$splitProfile]=['owner'=>$owner,'client'=>$splitClient,'tokens'=>$issued['json']];
        }
        $replyIssued=$splitIssued['replies'];$hotelIssued=$splitIssued['hotels'];
        $crossRevoke=$request('/manager/replies-oauth.php?action=revoke','POST',http_build_query([
            'token'=>$hotelIssued['tokens']['access_token'],'client_id'=>$hotelIssued['client']]));
        preparationOauthCheck('HTTP replies cannot revoke hotel consent',$crossRevoke['status']===200
            &&$hotelIssued['owner']->verifyBearer('Bearer '.$hotelIssued['tokens']['access_token'])===1);
        $revoke=$request('/manager/replies-oauth.php?action=revoke','POST',http_build_query([
            'token'=>$replyIssued['tokens']['access_token'],'client_id'=>$replyIssued['client']]));
        preparationOauthCheck('HTTP reply revoke is isolated',$revoke['status']===200
            &&$replyIssued['owner']->verifyBearer('Bearer '.$replyIssued['tokens']['access_token'])===null
            &&$hotelIssued['owner']->verifyBearer('Bearer '.$hotelIssued['tokens']['access_token'])===1);
        foreach ($splitIssued as $profile=>$issued) preparationOauthCheck('HTTP split credentials absent from logs '.$profile,
            !str_contains((string)file_get_contents($tmp.'/server.log'),$issued['tokens']['access_token'])
            &&!str_contains((string)file_get_contents($tmp.'/server.log'),$issued['tokens']['refresh_token']));
'''
marker='    } finally {\n        if (is_resource($server))'
if s.count(marker)!=1: raise SystemExit('HTTP finally marker changed')
s=s.replace(marker,http_tests+marker)
p.write_text(s);changed.append(str(p))

p=Path('docs/SEPARATED_REPLIES_HOTELS_MCP.md')
p.write_text(p.read_text()+'''\n## Review repairs\n\nReply unsubscription is bound to the current authenticated OAuth connection as\nwell as administrator, project and subscription identity. Old connection cleanup\ncannot stop a subscription currently owned by its replacement, even if both use\nthe same callback URL. This does not add an automatic transfer or migration.\n\nBackground event authorization resolves a known canonical grant/client/resource\nbefore returning a denial. Missing or inconsistent authority defers delivery and\npreserves the subscription, pending evidence and original hotel work. A known\nrevoked/expired grant still denies access. Neither behavior permits replay.\n\nSynthetic regression coverage includes actual new OAuth/discovery/consent/token\nHTTP routes in a temporary test database. Production consent, HOST bindings and\nactual client delivery remain separate deployment/migration acceptance.\n''');changed.append(str(p))
Path('split_review_paths.json').write_text(json.dumps(changed))
print(json.dumps({'review_repair_files':changed,'runtime_operations':0}))
