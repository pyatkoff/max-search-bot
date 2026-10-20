<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerPreparationMcpService.php';
require_once dirname(__DIR__).'/services/ManagerPreparationMcpDiagnostics.php';

$passed=0;
function preparationMcpCheck(string $name,bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    ++$passed;echo 'PASS '.$name."\n";
}

final class PreparationMcpPdo extends PDO
{
    public $beforeClaim=null;
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        if ($this->beforeClaim!==null && str_contains($query,'SET lease_token=?,lease_until=?')) {
            $hook=$this->beforeClaim;$this->beforeClaim=null;$hook($this);
        }
        return parent::prepare($query,$options);
    }
}

function preparationMcpFixture(): PreparationMcpPdo
{
    $pdo=new PreparationMcpPdo('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    (new ReflectionProperty(ProjectAccessService::class,'schemaReady'))->setValue(null,false);
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic project']]);
    $pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER);
INSERT INTO projects VALUES(1,'anytour','Synthetic project',1),(2,'other','Other',1);
INSERT INTO managers VALUES(1,'admin',1),(2,'manager',1),(3,'admin',1),(4,'admin',1);
INSERT INTO manager_projects VALUES(1,1),(2,1),(3,2),(4,1);
CREATE TABLE manager_preparation_events(subscription_id TEXT PRIMARY KEY,admin_id INTEGER,oauth_connection_id TEXT,
project_key TEXT,event_name TEXT,arguments_json TEXT,callback_url TEXT,signing_secret TEXT,previous_secret TEXT,
previous_secret_until TEXT,verified_at TEXT,expires_at TEXT,is_active INTEGER,observed_jobs_json TEXT,
pending_event_id TEXT,pending_body TEXT,pending_jobs_json TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,
lease_token TEXT,lease_until TEXT,last_batch_hash TEXT,last_status INTEGER,created_at TEXT,updated_at TEXT);");
    return $pdo;
}

function preparationMcpRpc(string $method,array $params=[]): array { return ['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params]; }
function preparationMcpHeaders(array $headers): array
{
    $out=[];foreach ($headers as $header) { [$key,$value]=explode(': ',$header,2);$out[strtolower($key)]=$value; }return $out;
}

function preparationMcpDiagnosticChecks(): void
{
    $dir=sys_get_temp_dir().'/mcp-diagnostics-'.bin2hex(random_bytes(6));mkdir($dir,0700);
    $file=$dir.'/events.log';DiagnosticLogger::setFile($file);
    try {
        $server=['REQUEST_METHOD'=>'POST','HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('S',43),
            'HTTP_MCP_PROTOCOL_VERSION'=>'2025-11-25','HTTP_COOKIE'=>'PRIVATE COOKIE','HTTP_ORIGIN'=>'PRIVATE ORIGIN'];
        $rpc=preparationMcpRpc('initialize',['protocolVersion'=>'2025-11-25','clientInfo'=>['name'=>'PRIVATE CLIENT']]);
        ManagerPreparationMcpDiagnostics::record($server,$rpc,200,'complete',null);
        $snapshot=ManagerPreparationMcpDiagnostics::snapshot();$last=$snapshot['recent_attempts'][0]??[];
        preparationMcpCheck('HTTP discovery diagnostics retain only useful protocol categories',
            $snapshot['sampled_count']===1&&$last['credential_header']==='bearer'&&$last['rpc_method']==='initialize'
            &&$last['requested_version']==='2025-11-25'&&$last['http_status']===200&&$last['rpc_error']===null);
        $raw=(string)file_get_contents($file);
        preparationMcpCheck('HTTP discovery log contains no credentials or client-supplied strings',
            !str_contains($raw,str_repeat('S',43))&&!str_contains($raw,'PRIVATE')&&!str_contains($raw,'clientInfo'));
        preparationMcpCheck('HTTP discovery records use private file permissions',(fileperms($file)&0777)===0600);
        foreach (['missing'=>[], 'redirect_only'=>['REDIRECT_HTTP_AUTHORIZATION'=>'PRIVATE TOKEN'],
            'noncanonical_bearer'=>['HTTP_AUTHORIZATION'=>'bearer '.str_repeat('S',43)]] as $expected=>$headers) {
            $meta=ManagerPreparationMcpDiagnostics::metadata($headers,null,401,'credentials',-32001);
            preparationMcpCheck('credential delivery category '.$expected,$meta['credential_header']===$expected
                &&$meta['stage']==='credentials'&&$meta['rpc_method']==='absent'&&$meta['rpc_error']===-32001);
        }
        $hostile=ManagerPreparationMcpDiagnostics::metadata(['REQUEST_METHOD'=>'PRIVATE METHOD'],
            ['method'=>['PRIVATE METHOD'],'params'=>['protocolVersion'=>'PRIVATE VERSION']],999,'PRIVATE STAGE',999);
        preparationMcpCheck('unknown and malformed diagnostic values cannot become published text',
            !str_contains(json_encode($hostile),'PRIVATE')&&$hostile['http_status']===0&&$hostile['rpc_error']===null);
        // A corrupt or old writer cannot smuggle new fields through the public reader.
        $row=['ts'=>gmdate('c'),'component'=>'manager_preparation_mcp','event'=>'http_result',
            'data'=>['http_method'=>'POST','rpc_method'=>'PRIVATE RPC','stage'=>'complete','http_status'=>200,
                'access_token'=>'PRIVATE TOKEN','text'=>'PRIVATE TRANSCRIPT','manager_id'=>7]];
        file_put_contents($file,json_encode($row)."\n",FILE_APPEND);
        $published=json_encode(ManagerPreparationMcpDiagnostics::snapshot());
        preparationMcpCheck('snapshot re-allowlists stored records and excludes injected private fields',
            !str_contains($published,'PRIVATE')&&!str_contains($published,'access_token')&&!str_contains($published,'manager_id'));
        for ($i=0;$i<24;++$i) ManagerPreparationMcpDiagnostics::record($server,preparationMcpRpc('tools/list'),200,'complete',null);
        preparationMcpCheck('discovery history is capped at twenty most recent attempts',ManagerPreparationMcpDiagnostics::snapshot()['sampled_count']===20);
        file_put_contents($file,str_repeat('x',524289)."\n",FILE_APPEND);
        ManagerPreparationMcpDiagnostics::record($server,preparationMcpRpc('tools/list'),200,'complete',null);
        preparationMcpCheck('discovery reader never searches beyond its bounded tail',ManagerPreparationMcpDiagnostics::snapshot()['sampled_count']===1);
        DiagnosticLogger::setFile($dir.'/missing/events.log');
        ManagerPreparationMcpDiagnostics::record($server,$rpc,200,'complete',null);
        preparationMcpCheck('unavailable diagnostic storage is nonfatal and reports no sample',ManagerPreparationMcpDiagnostics::snapshot()['sampled_count']===0);
    } finally {DiagnosticLogger::setFile('');@unlink($file);@rmdir($dir);}
}

try {
    preparationMcpDiagnosticChecks();
    foreach (['admin','project','connection'] as $revocation) {
        $racePdo=preparationMcpFixture();$stillConnected=true;
        $raceStore=static function() use ($racePdo,$revocation,&$stillConnected): array {
            if ($revocation==='admin') $racePdo->exec('UPDATE managers SET is_active=0 WHERE id=1');
            elseif ($revocation==='project') $racePdo->exec('DELETE FROM manager_projects WHERE manager_id=1');
            else $stillConnected=false;
            return ['ok'=>true,'context'=>['private'=>'PRIVATE delayed context']];
        };
        $raceService=new ManagerPreparationMcpService($racePdo,$raceStore,null,null,static function() use (&$stillConnected): bool {return $stillConnected;});
        $raceResult=$raceService->handle(preparationMcpRpc('tools/call',['name'=>'get_reply_context','arguments'=>['job_id'=>str_repeat('1',64)]]),1,str_repeat('a',32));
        preparationMcpCheck($revocation.' revoked during context read blocks private response', ($raceResult['error']['code']??0)===-32001 && !str_contains(json_encode($raceResult),'PRIVATE'));
    }
    $pdo=preparationMcpFixture();$now=1792406400;$connection=str_repeat('a',32);$connectionValid=true;
    $secret='whsec_'.base64_encode(str_repeat('k',32));$nextSecret='whsec_'.base64_encode(str_repeat('n',32));
    $job=['job_id'=>str_repeat('1',64),'project_key'=>'anytour','context_revision'=>str_repeat('2',64),
        'conversation_id'=>10,'manager_id'=>2,'latest_message_id'=>99,'created_at'=>'2026-10-19 08:00:00',
        'private_text'=>'PRIVATE TRANSCRIPT','private_name'=>'PRIVATE CUSTOMER'];
    $jobs=[$job];$calls=[];$requests=[];$statuses=[];$challengeGood=true;$resolveIps=['8.8.8.8'];$pendingAdvance=0;
    $resolver=static function(string $host) use (&$resolveIps): array { return $resolveIps; };
    $clock=static function() use (&$now): int { return $now; };
    $transport=static function(string $url,array $headers,string $body,array $destination) use (&$requests,&$statuses,&$challengeGood): array {
        $decoded=json_decode($body,true);$requests[]=['url'=>$url,'headers'=>preparationMcpHeaders($headers),'body'=>$body,'decoded'=>$decoded,'destination'=>$destination];
        if (($decoded['type']??'')==='verification') return ['status'=>200,'body'=>json_encode(['challenge'=>$challengeGood?$decoded['challenge']:'wrong'])];
        return ['status'=>array_shift($statuses)??204,'body'=>''];
    };
    $webhook=new ManagerPreparationWebhook($transport,$resolver,$clock);
    $imageInputs=[];
    $store=static function(string $op,array $args) use (&$calls,&$jobs,&$pendingAdvance,&$now,&$imageInputs): array {
        $calls[]=[$op,$args];
        if ($op==='pending' && $pendingAdvance>0) { $now+=$pendingAdvance;$pendingAdvance=0; }
        return match ($op) {
            'pending'=>['ok'=>true,'jobs'=>array_slice($jobs,0,$args[0])],
            'context'=>['ok'=>true,'revision'=>str_repeat('2',64),'context'=>['messages'=>[['id'=>99,'text'=>'Synthetic customer']]],'image_inputs'=>$imageInputs],
            'complete'=>['ok'=>true,'job_id'=>$args[0],'idempotent'=>true],
        };
    };
    $active=static function(string $family,int $actor) use (&$connectionValid,$connection): bool { return $connectionValid && $family===$connection && in_array($actor,[1,4],true); };
    $service=new ManagerPreparationMcpService($pdo,$store,$webhook,$clock,$active);
    $subscribe=['name'=>ManagerPreparationMcpService::EVENT,'arguments'=>['project_key'=>'anytour'],
        'delivery'=>['mode'=>'webhook','url'=>'https://callback.example.com/events/abc','secret'=>$secret]];

    preparationMcpCheck('MCP2 discovery advertises tools and events',isset($service->handle(preparationMcpRpc('server/discover'),1)['result']['capabilities']['events']));
    preparationMcpCheck('only protocol 2026-07-28 is advertised',$service->handle(preparationMcpRpc('server/discover'),1)['result']['supportedVersions']===['2026-07-28']);
    foreach (['2025-11-25','2025-06-18','2025-03-26','2024-11-05'] as $version) {
        $params=['protocolVersion'=>$version,'capabilities'=>[],
            'clientInfo'=>['name'=>'synthetic-discovery-client','version'=>'1.0']];
        $initialized=$service->handle(preparationMcpRpc('initialize',$params),1);
        preparationMcpCheck('handshake preserves supported client version '.$version,
            ($initialized['result']['protocolVersion']??null)===$version);
        $wire=json_decode(json_encode($initialized,JSON_THROW_ON_ERROR),false,32,JSON_THROW_ON_ERROR);
        preparationMcpCheck('handshake capabilities encode as objects without Events '.$version,
            $wire->result->capabilities->tools instanceof stdClass && !isset($wire->result->capabilities->events));
        $available=$service->handle(preparationMcpRpc('tools/list'),1);
        preparationMcpCheck('handshake client can discover the preparation tools '.$version,
            array_column($available['result']['tools']??[],'name')===['list_pending_replies','get_reply_context','save_prepared_reply']);
    }
    foreach (['2099-01-01','2026-07-28'] as $version) {
        $initialized=$service->handle(preparationMcpRpc('initialize',['protocolVersion'=>$version]),1);
        preparationMcpCheck('unknown handshake version offers a supported fallback '.$version,
            ($initialized['result']['protocolVersion']??null)==='2025-11-25');
    }
    foreach ([null,[],42,'','unsupported'] as $version) {
        preparationMcpCheck('malformed handshake version is rejected '.get_debug_type($version),
            ($service->handle(preparationMcpRpc('initialize',['protocolVersion'=>$version]),1)['error']['code']??0)===-32602);
    }
    foreach ([0,2,3] as $actor) preparationMcpCheck('handshake retains active project admin boundary '.$actor,
        ($service->handle(preparationMcpRpc('initialize',['protocolVersion'=>'2025-06-18']),$actor)['error']['code']??0)===-32001);
    preparationMcpCheck('handshake and discovery do not read or write queued jobs',$calls===[] && $requests===[]
        && (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_events')->fetchColumn()===0);
    $discovery=$service->handle(preparationMcpRpc('server/discover'),1)['result'];
    preparationMcpCheck('MCP2 Events discovery remains available after legacy handshakes',
        $discovery['supportedVersions']===['2026-07-28'] && isset($discovery['capabilities']['events']));
    foreach ([0,2,3] as $actor) preparationMcpCheck('current active project admin required '.$actor,($service->handle(preparationMcpRpc('tools/list'),$actor)['error']['code']??0)===-32001);
    preparationMcpCheck('invalid JSON-RPC envelope is rejected',($service->handle(['method'=>'tools/list'],1)['error']['code']??0)===-32600);
    preparationMcpCheck('unknown method is rejected',($service->handle(preparationMcpRpc('customers/send'),1)['error']['code']??0)===-32601);
    $tools=$service->handle(preparationMcpRpc('tools/list'),1)['result']['tools'];
    preparationMcpCheck('exactly the three preparation tools are exposed',array_column($tools,'name')===['list_pending_replies','get_reply_context','save_prepared_reply']);
    preparationMcpCheck('tools advertise matching OAuth scope metadata',$tools[0]['securitySchemes']===[['type'=>'oauth2','scopes'=>['queue:read','prepared:write']]] && $tools[0]['_meta']['securitySchemes']===$tools[0]['securitySchemes']);
    $protocolMeta=['io.modelcontextprotocol/protocolVersion'=>'2026-07-28',
        'io.modelcontextprotocol/clientInfo'=>['name'=>'Synthetic client','version'=>'1'],
        'io.modelcontextprotocol/clientCapabilities'=>[], 'example.com/private'=>'PRIVATE METADATA'];
    foreach ([[],['progressToken'=>'synthetic-progress'],$protocolMeta] as $meta) {
        foreach ([[],['limit'=>1]] as $args) {
            $reply=$service->handle(preparationMcpRpc('tools/call',[
                'name'=>'list_pending_replies','arguments'=>$args,'_meta'=>$meta]),1);
            preparationMcpCheck('standard request metadata permits bounded queue reads '.count($meta).'/'.count($args),
                ($reply['result']['structuredContent']['ok']??false)===true
                && end($calls)===['pending',[$args['limit']??20]]
                && !str_contains(json_encode($reply),'PRIVATE'));
        }
    }
    $before=count($calls);
    foreach ([null,'metadata',true,17,['list-item']] as $meta) {
        preparationMcpCheck('nonobject request metadata is rejected '.get_debug_type($meta),
            ($service->handle(preparationMcpRpc('tools/call',[
                'name'=>'list_pending_replies','_meta'=>$meta]),1)['error']['code']??0)===-32602);
    }
    preparationMcpCheck('invalid metadata does not reach the queue',count($calls)===$before);
    foreach ([0,2,3] as $actor) preparationMcpCheck('metadata cannot replace active project admin authorization '.$actor,
        ($service->handle(preparationMcpRpc('tools/call',[
            'name'=>'list_pending_replies','_meta'=>$protocolMeta+['admin_id'=>1,'project_key'=>'anytour']]),$actor)['error']['code']??0)===-32001);
    foreach ([['name'=>'list_pending_replies','project_key'=>'other','_meta'=>$protocolMeta],
        ['name'=>'list_pending_replies','arguments'=>['limit'=>21],'_meta'=>$protocolMeta],
        ['name'=>'list_pending_replies','arguments'=>['limit'=>'1'],'_meta'=>$protocolMeta],
        ['name'=>'list_pending_replies','arguments'=>['_meta'=>$protocolMeta],'_meta'=>$protocolMeta]] as $params) {
        preparationMcpCheck('request metadata does not relax command validation '.count($params).'/'.count($params['arguments']??[]),
            ($service->handle(preparationMcpRpc('tools/call',$params),1)['error']['code']??0)===-32602);
    }
    preparationMcpCheck('denied metadata calls have no queue side effects',count($calls)===$before);
    $listed=$service->handle(preparationMcpRpc('tools/call',['name'=>'list_pending_replies','arguments'=>['limit'=>20]]),1)['result']['structuredContent'];
    preparationMcpCheck('metadata strips customer identity and transcript',!str_contains(json_encode($listed),'PRIVATE') && !isset($listed['jobs'][0]['conversation_id']));
    preparationMcpCheck('canonical context revision is exposed',$listed['jobs'][0]['revision']===$job['context_revision']);
    preparationMcpCheck('queue inventory exposes heartbeat availability separately from empty connection inventory',
        is_bool($listed['preparation_health']['tick']['available']??null)
        && ($listed['preparation_health']['connections']['active']??null)===0);
    preparationMcpCheck('authorized queue inventory can diagnose the exact draft route source without a pending job',
        is_string($listed['preparation_health']['travel_reference']['reason']??null)
        && isset($listed['preparation_health']['travel_reference']['status'])
        && !str_contains(json_encode($listed['preparation_health']['travel_reference']),dirname(__DIR__)));
    $jobs[]=$job+[];$jobs[1]['project_key']='other';
    preparationMcpCheck('metadata cannot leak another project',count($service->handle(preparationMcpRpc('tools/call',['name'=>'list_pending_replies']),1)['result']['structuredContent']['jobs'])===1);$jobs=[$job];
    preparationMcpCheck('tool batch limit cannot exceed twenty',($service->handle(preparationMcpRpc('tools/call',['name'=>'list_pending_replies','arguments'=>['limit'=>21]]),1)['error']['code']??0)===-32602);
    $context=$service->handle(preparationMcpRpc('tools/call',['name'=>'get_reply_context','arguments'=>['job_id'=>$job['job_id']]]),1);
    preparationMcpCheck('canonical store receives the opaque context job',$context['result']['structuredContent']['ok'] && end($calls)===['context',[$job['job_id']]]);
    $imageInputs=[['type'=>'input_image','image_url'=>'data:image/png;base64,U1lOVEhFVElDX1BIT1RP','detail'=>'high']];
    $photoContext=$service->handle(preparationMcpRpc('tools/call',['name'=>'get_reply_context','arguments'=>['job_id'=>$job['job_id']]]),1)['result'];
    preparationMcpCheck('private preparation returns an actual MCP image block beside the text context',
        $photoContext['content'][1]===['type'=>'image','mimeType'=>'image/png','data'=>'U1lOVEhFVElDX1BIT1RP']);
    preparationMcpCheck('image bytes are absent from structured context and its duplicate text representation',
        !isset($photoContext['structuredContent']['image_inputs'])&&!str_contains($photoContext['content'][0]['text'],'U1lOVEhFVElDX1BIT1RP'));
    $imageInputs=[];
    $proposal=['ok'=>true,'should_draft'=>false,'draft'=>'','reason'=>'Synthetic','checks'=>[]];
    $save=$service->handle(preparationMcpRpc('tools/call',['name'=>'save_prepared_reply','arguments'=>['job_id'=>$job['job_id'],'revision'=>$job['context_revision'],'proposal'=>$proposal]]),1);
    preparationMcpCheck('save uses canonical idempotent store without a provider',$save['result']['structuredContent']['idempotent'] && end($calls)===['complete',[$job['job_id'],$job['context_revision'],$proposal]]);
    foreach ([['get_reply_context',['job_id'=>$job['job_id']],'context',[$job['job_id']]],
        ['save_prepared_reply',['job_id'=>$job['job_id'],'revision'=>$job['context_revision'],'proposal'=>$proposal],
            'complete',[$job['job_id'],$job['context_revision'],$proposal]]] as [$name,$args,$operation,$expected]) {
        $reply=$service->handle(preparationMcpRpc('tools/call',['name'=>$name,'arguments'=>$args,'_meta'=>$protocolMeta]),1);
        preparationMcpCheck('protocol metadata is not forwarded to the canonical store '.$name,
            ($reply['result']['structuredContent']['ok']??false)===true && end($calls)===[$operation,$expected]);
    }
    preparationMcpCheck('unknown tool arguments fail closed',($service->handle(preparationMcpRpc('tools/call',['name'=>'get_reply_context','arguments'=>['job_id'=>$job['job_id'],'project'=>'other']]),1)['error']['code']??0)===-32602);
    preparationMcpCheck('event definition has strict project filter',$service->handle(preparationMcpRpc('events/list'),1)['result']['events'][0]['inputSchema']['properties']['project_key']['const']==='anytour');
    preparationMcpCheck('subscription requires a live OAuth connection',($service->handle(preparationMcpRpc('events/subscribe',$subscribe),1)['error']['code']??0)===-32001);

    $sub=$service->handle(preparationMcpRpc('events/subscribe',$subscribe+['_meta'=>$protocolMeta]),1,$connection);
    preparationMcpCheck('signed callback challenge activates a persistent subscription',isset($sub['result']['id']) && count($requests)===1 && (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_events')->fetchColumn()===1);
    $beforeHealth=$pdo->query('SELECT * FROM manager_preparation_events')->fetchAll();$beforeRequests=count($requests);
    $health=$service->preparationHealth();
    preparationMcpCheck('health distinguishes active subscriptions without writing or delivering',
        ($health['connections']['active']??null)===1 && $beforeHealth===$pdo->query('SELECT * FROM manager_preparation_events')->fetchAll() && count($requests)===$beforeRequests);
    $connectionValid=false;
    preparationMcpCheck('health distinguishes revoked authorization from a stopped cron',($service->preparationHealth()['connections']['unauthorized']??null)===1);
    $connectionValid=true;
    $pdo->prepare('UPDATE manager_preparation_events SET expires_at=?')->execute([gmdate('Y-m-d H:i:s',$now-1)]);
    preparationMcpCheck('health reports subscription expiry independently of OAuth',($service->preparationHealth()['connections']['expired']??null)===1);
    $pdo->prepare('UPDATE manager_preparation_events SET expires_at=?')->execute([$beforeHealth[0]['expires_at']]);
    preparationMcpCheck('health excludes callback secrets and identities',!str_contains(json_encode($health),'callback.example.com') && !str_contains(json_encode($health),'oauth_connection_id'));
    $subId=$sub['result']['id'];$challenge=$requests[0];$headers=$challenge['headers'];
    $expected='v1,'.base64_encode(hash_hmac('sha256',$headers['webhook-id'].'.'.$headers['webhook-timestamp'].'.'.$challenge['body'],str_repeat('k',32),true));
    preparationMcpCheck('challenge signature uses exact Standard Webhooks bytes',hash_equals($expected,$headers['webhook-signature']) && $headers['x-mcp-subscription-id']===$subId);
    preparationMcpCheck('callback uses the validated pinned public destination',$challenge['destination']===['host'=>'callback.example.com','ip'=>'8.8.8.8']);
    $same=$service->handle(preparationMcpRpc('events/subscribe',$subscribe),1,$connection);
    preparationMcpCheck('refresh is idempotent with bounded verification cache',$same['result']['id']===$subId && count($requests)===1);
    $short=$subscribe;$short['ttlMs']=5000;$shortResult=$service->handle(preparationMcpRpc('events/subscribe',$short),1,$connection);
    preparationMcpCheck('granted expiration never exceeds requested TTL',strtotime($shortResult['result']['refreshBefore'])===$now+5);
    $long=$subscribe;$long['ttlMs']=172800000;$longResult=$service->handle(preparationMcpRpc('events/subscribe',$long),1,$connection);
    preparationMcpCheck('subscription TTL is capped at twenty four hours',strtotime($longResult['result']['refreshBefore'])===$now+86400);
    $bad=$subscribe;$bad['arguments']['project_key']='other';
    preparationMcpCheck('other project subscription is rejected',($service->handle(preparationMcpRpc('events/subscribe',$bad),1,$connection)['error']['code']??0)===-32602);
    $bad=$subscribe;$bad['delivery']['secret']='whsec_'.base64_encode('short');
    preparationMcpCheck('short signing secret is rejected',($service->handle(preparationMcpRpc('events/subscribe',$bad),1,$connection)['error']['code']??0)===-32602);
    foreach (['http://callback.example.com/a','https://127.0.0.1/a','https://169.254.169.254/a','https://user:pass@callback.example.com/a','https://callback.example.com:444/a','https://callback.example.com/a#fragment','https://localhost/a'] as $url) preparationMcpCheck('unsafe callback blocked '.parse_url($url,PHP_URL_HOST),$webhook->destination($url)===null);
    $resolveIps=['8.8.8.8','10.0.0.1'];preparationMcpCheck('mixed public and private DNS answer is rejected',$webhook->destination('https://callback.example.com/a')===null);$resolveIps=['8.8.8.8'];
    $challengeGood=false;$bad=$subscribe;$bad['delivery']['url']='https://callback.example.com/different';$failed=$service->handle(preparationMcpRpc('events/subscribe',$bad),1,$connection);
    preparationMcpCheck('wrong verification echo returns categorized callback error',($failed['error']['code']??0)===-32015 && ($failed['error']['data']['reason']??'')==='challenge_failed');$challengeGood=true;
    preparationMcpCheck('failed callback is never persisted',(int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_events')->fetchColumn()===1);

    preparationMcpCheck('active subscription gate checks current principal',$service->hasActiveSubscriptions());
    $before=count($requests);$first=$service->deliverPending();$event=$requests[count($requests)-1];
    preparationMcpCheck('pending batch is delivered exactly once',$first['accepted']===1 && count($requests)===$before+1);
    preparationMcpCheck('event contains only count and opaque batch hash',array_keys($event['decoded']['data'])===['project_key','pending_count','batch_hash'] && !str_contains($event['body'],'PRIVATE') && !str_contains($event['body'],$job['job_id']));
    $again=$service->deliverPending();preparationMcpCheck('acknowledged unchanged queue causes no repeated wakeup',$again['unchanged']===1 && count($requests)===$before+1);
    $jobs=[];$again=$service->deliverPending();preparationMcpCheck('saving a draft and shrinking queue does not create a feedback event',$again['unchanged']===1 && count($requests)===$before+1);
    $jobs=[$job];$jobs[0]['context_revision']=str_repeat('3',64);$statuses=[500];$retry=$service->deliverPending();$failedEvent=end($requests);
    preparationMcpCheck('changed source revision creates new bounded retry work',$retry['retried']===1 && $failedEvent['decoded']['eventId']!==$event['decoded']['eventId']);
    $count=count($requests);$service->deliverPending();preparationMcpCheck('backoff prevents premature retries',count($requests)===$count);
    $now+=60;$service=new ManagerPreparationMcpService($pdo,$store,$webhook,$clock,$active);$retried=$service->deliverPending();$resent=end($requests);
    preparationMcpCheck('retry survives restart with stable event ID and body',$retried['accepted']===1 && $resent['body']===$failedEvent['body'] && $resent['headers']['webhook-id']===$failedEvent['headers']['webhook-id']);
    preparationMcpCheck('retry signs a fresh timestamp',$resent['headers']['webhook-timestamp']!==$failedEvent['headers']['webhook-timestamp'] && $resent['headers']['webhook-signature']!==$failedEvent['headers']['webhook-signature']);
    $rotate=$subscribe;$rotate['delivery']['secret']=$nextSecret;$service->handle(preparationMcpRpc('events/subscribe',$rotate),1,$connection);
    $jobs[0]['context_revision']=str_repeat('4',64);$service->deliverPending();$rotated=end($requests);
    preparationMcpCheck('secret rotation signs both keys for a bounded window',count(explode(' ',$rotated['headers']['webhook-signature']))===2);
    $jobs[0]['context_revision']=str_repeat('7',64);$signature=hash('sha256',$jobs[0]['job_id'].':'.$jobs[0]['context_revision']);$count=count($requests);
    $pdo->beforeClaim=static function(PDO $db) use ($signature): void {
        $db->prepare('UPDATE manager_preparation_events SET observed_jobs_json=?')->execute([json_encode([$signature])]);
    };
    $race=$service->deliverPending();
    preparationMcpCheck('concurrent acknowledgement is reread after acquiring the lease',$race['unchanged']===1 && count($requests)===$count);
    $jobs[0]['context_revision']=str_repeat('8',64);
    $pdo->beforeClaim=static function(PDO $db) use (&$now): void {
        $db->prepare('UPDATE manager_preparation_events SET next_attempt_at=?')->execute([gmdate('Y-m-d H:i:s',$now+60)]);
    };
    $service->deliverPending();
    preparationMcpCheck('concurrent retry backoff cannot be bypassed by a stale inventory',count($requests)===$count);
    $pdo->exec('UPDATE manager_preparation_events SET next_attempt_at=NULL');
    $second=$rotate;$second['delivery']['url']='https://callback.example.com/second';
    $secondResult=$service->handle(preparationMcpRpc('events/subscribe',$second),4,$connection);
    $count=count($requests);$pendingAdvance=31;$slow=$service->deliverPending();
    preparationMcpCheck('expired delivery lease retries without revoking an authorized subscription',$slow['retried']===1 && (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_events WHERE is_active=1')->fetchColumn()===2);
    preparationMcpCheck('later subscriptions receive leases based on a fresh clock',$slow['accepted']===1 && count($requests)===$count+1);
    $pdo->prepare('DELETE FROM manager_preparation_events WHERE subscription_id=?')->execute([$secondResult['result']['id']]);
    $now+=60;
    $jobs[0]['context_revision']=str_repeat('9',64);
    $pdo->exec("UPDATE manager_preparation_events SET pending_event_id=NULL,pending_body=NULL,pending_jobs_json=NULL,attempts=0,next_attempt_at=NULL");
    $statuses=[500,500,500,500,500];$stopped=null;
    foreach ([0,60,120,240,480] as $advance) { $now+=$advance;$stopped=$service->deliverPending(); }
    preparationMcpCheck('transient delivery attempts are bounded at five',$stopped['stopped']===1 && (int)$pdo->query('SELECT attempts FROM manager_preparation_events')->fetchColumn()===5);
    $service->handle(preparationMcpRpc('events/subscribe',$rotate),1,$connection);
    $statuses=[410];$stopped=$service->deliverPending();
    preparationMcpCheck('HTTP 410 stops retries and collection gate',$stopped['stopped']===1 && !$service->hasActiveSubscriptions());
    $count=count($requests);$now+=1000;$service->deliverPending();preparationMcpCheck('stopped subscription is not retried',count($requests)===$count);
    $service->handle(preparationMcpRpc('events/subscribe',$rotate),1,$connection);$jobs[0]['context_revision']=str_repeat('6',64);
    $connectionValid=false;$count=count($requests);$callCount=count($calls);
    preparationMcpCheck('OAuth revocation closes active collection gate',!$service->hasActiveSubscriptions());$revoked=$service->deliverPending();
    preparationMcpCheck('OAuth revocation stops delivery before context or queue reads',$revoked['stopped']===1 && count($requests)===$count && count($calls)===$callCount);$connectionValid=true;
    $service->handle(preparationMcpRpc('events/subscribe',$rotate),1,$connection);$pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');$count=count($requests);$revoked=$service->deliverPending();
    preparationMcpCheck('admin deactivation stops ongoing subscriptions',$revoked['stopped']===1 && count($requests)===$count);$pdo->exec('UPDATE managers SET is_active=1 WHERE id=1');
    $service->handle(preparationMcpRpc('events/subscribe',$rotate),1,$connection);
    $unsubscribe=$rotate;unset($unsubscribe['delivery']['secret']);$resolveIps=['10.0.0.1'];
    $unsubscribed=$service->handle(preparationMcpRpc('events/unsubscribe',$unsubscribe),1,$connection);
    preparationMcpCheck('unsubscribe works after destination becomes unavailable',isset($unsubscribed['result']) && !$service->hasActiveSubscriptions());
    preparationMcpCheck('unsubscribe is idempotent',isset($service->handle(preparationMcpRpc('events/unsubscribe',$unsubscribe),1,$connection)['result']));$resolveIps=['8.8.8.8'];
    $service->handle(preparationMcpRpc('events/subscribe',$rotate),1,$connection);$count=count($requests);$now+=86401;
    preparationMcpCheck('finite subscription expiry stops collection and delivery',!$service->hasActiveSubscriptions() && $service->deliverPending()['subscriptions']===0 && count($requests)===$count);
    for ($i=0;$i<20;++$i) {
        $bounded=$rotate;$bounded['delivery']['url']='https://callback.example.com/bounded/'.$i;
        $created=$service->handle(preparationMcpRpc('events/subscribe',$bounded),1,$connection);
        if (!isset($created['result']['id'])) throw new RuntimeException('FAIL bounded subscription fixture');
    }
    $bounded['delivery']['url']='https://callback.example.com/bounded/overflow';
    preparationMcpCheck('active subscription cap prevents starvation beyond one bounded delivery pass',($service->handle(preparationMcpRpc('events/subscribe',$bounded),1,$connection)['error']['code']??0)===-32602);
    echo 'Manager preparation MCP regression: '.$passed." checks passed\n";
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n");exit(1); }
