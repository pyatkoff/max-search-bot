<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/integrations/ProjectMaxWebhookSetup.php';

$passed = 0;
function webhookSetupCheck(string $name, bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $passed++; echo 'PASS '.$name.PHP_EOL;
}

ProjectConfig::resetForTests(['id'=>'anytour','channel_posts'=>['max'=>['bot_key'=>'legacy_anytour_bot']]]);
$registry = [
    'appleservice39'=>['project'=>['id'=>'appleservice39','business'=>['kind'=>'device_repair'],'routing'=>['source_key'=>'max:appleservice39-main']],
        'max'=>['bot_key'=>ProjectMaxWebhookSetup::BOT,'token'=>'SYNTHETIC_APPLE_TOKEN','webhook_secret'=>str_repeat('s',32)]],
    'repair_two'=>['project'=>['id'=>'repair_two','business'=>['kind'=>'device_repair'],'routing'=>['source_key'=>'max:repair_two-main']],
        'max'=>['bot_key'=>'another_repair_bot','token'=>'SYNTHETIC_OTHER_TOKEN','webhook_secret'=>str_repeat('t',32)]],
];
ProjectMaxConnection::resetForTests($registry);
$connection = ProjectMaxConnection::resolve('appleservice39');
webhookSetupCheck('fixture obtains trusted immutable Apple connection', $connection instanceof ProjectMaxConnection);

final class ProjectWebhookSetupFixture
{
    public array $calls = [];
    public array $probes = [];
    public array $claims = [];
    public array $rows = [];
    public string $mode = 'success';
    public string $bot = ProjectMaxWebhookSetup::BOT;
    public bool $directoryReady = true;
    public bool $probeReady = true;
    public bool $denyClaim = false;
    public int $reads = 0;
    public int $directoryCalls = 0;
    public ?string $registrationFingerprint = null;

    public function __construct(bool $legacy = true)
    {
        if ($legacy) $this->rows = [['url'=>ProjectMaxWebhookSetup::LEGACY_URL,'update_types'=>ProjectMaxWebhookSetup::UPDATE_TYPES,'secret'=>'PRIVATE_PROVIDER_SECRET']];
    }

    public function provider(ProjectMaxConnection $owner, string $method, string $path, array $query, ?array $body): array
    {
        $this->calls[] = ['project'=>$owner->projectKey(),'token'=>$owner->token(),'method'=>$method,'path'=>$path,'query'=>$query,'body'=>$body];
        if ($path === '/me') return ['http'=>200,'transport_error'=>false,'data'=>['is_bot'=>true,'username'=>$this->bot,'user_id'=>'390521129062','private'=>'PRIVATE_PROVIDER_SECRET']];
        if ($method === 'GET') {
            $this->reads++;
            if ($this->mode === 'read_malformed') return ['http'=>200,'transport_error'=>false,'data'=>null];
            if ($this->mode === 'changed_before_write' && $this->reads === 2) $this->rows = [];
            if ($this->mode === 'unknown_before_write' && $this->reads === 2) $this->rows[] = ['url'=>'https://unknown.invalid/PRIVATE_PROVIDER_SECRET'];
            if ($this->mode === 'readback_malformed' && $this->writes() !== []) return ['http'=>200,'transport_error'=>false,'data'=>null];
            if ($this->mode === 'unknown_after_write' && $this->writes() !== []) $this->rows[] = ['url'=>'https://unknown.invalid/PRIVATE_PROVIDER_SECRET'];
            return ['http'=>200,'transport_error'=>false,'data'=>['subscriptions'=>$this->rows,'private'=>'PRIVATE_PROVIDER_SECRET']];
        }
        if ($method === 'POST') {
            if ($this->mode !== 'register_timeout_unobserved') {
                $this->rows = array_values(array_filter($this->rows,static fn(array $row): bool => $row['url'] !== ProjectMaxWebhookSetup::EXPECTED_URL));
                $this->rows[] = ['url'=>$body['url'],'update_types'=>$this->mode === 'types_unconfirmed' ? null : $body['update_types'],'secret'=>'PRIVATE_PROVIDER_SECRET'];
            }
            if (str_starts_with($this->mode,'register_timeout')) return ['http'=>0,'transport_error'=>true,'data'=>['private'=>'PRIVATE_PROVIDER_SECRET']];
            if ($this->mode === 'write_malformed') return ['http'=>200,'transport_error'=>false,'data'=>null];
            return ['http'=>200,'transport_error'=>false,'data'=>['success'=>true]];
        }
        if ($method === 'DELETE') {
            if ($this->mode !== 'delete_timeout_unobserved') $this->rows = array_values(array_filter($this->rows,static fn(array $row): bool => $row['url'] !== $query['url']));
            if (str_starts_with($this->mode,'delete_timeout')) return ['http'=>0,'transport_error'=>true,'data'=>['private'=>'PRIVATE_PROVIDER_SECRET']];
            return ['http'=>200,'transport_error'=>false,'data'=>['success'=>true]];
        }
        throw new RuntimeException('unexpected fixture operation');
    }

    public function probe(ProjectMaxConnection $owner, string $url, array $update): array
    {
        $this->probes[] = ['project'=>$owner->projectKey(),'url'=>$url,'update'=>$update];
        return ['http'=>200,'transport_error'=>false,'data'=>$this->probeReady ? ['ok'=>true,'result'=>'ignored'] : ['ok'=>true,'result'=>'recorded','private'=>'PRIVATE_PROVIDER_SECRET']];
    }

    public function directory(ProjectMaxConnection $owner): bool
    {
        $this->directoryCalls++;
        return $owner->projectKey() === ProjectMaxWebhookSetup::PROJECT && $this->directoryReady;
    }

    public function claim(string $operation, string $url): void
    {
        $key = $operation.'|'.$url;
        if ($this->denyClaim || isset($this->claims[$key])) throw new RuntimeException('PRIVATE_CLAIM_FAILURE');
        $this->claims[$key] = true;
    }

    public function run(ProjectMaxConnection $owner, bool $activate): array
    {
        $proof = fn(ProjectMaxConnection $connection): bool => $this->registrationFingerprint === self::fingerprint($connection);
        $result = $activate ? ProjectMaxWebhookSetup::activate($owner,[$this,'provider'],[$this,'probe'],[$this,'directory'],[$this,'claim'],$proof)
            : ProjectMaxWebhookSetup::verify($owner,[$this,'provider'],[$this,'probe'],[$this,'directory'],$proof);
        // Model the wrapper's durable receipt only after this exact registration is confirmed.
        if ($activate && ($result['receipt']['registration_confirmed'] ?? false) && ($result['receipt']['register_attempts'] ?? 0) === 1) $this->registrationFingerprint = self::fingerprint($owner);
        return $result;
    }

    public static function fingerprint(ProjectMaxConnection $owner): string
    {
        return hash('sha256',$owner->projectKey()."\0".$owner->botKey()."\0".$owner->token()."\0".$owner->webhookSecret());
    }

    public function writes(): array
    {
        return array_values(array_filter($this->calls,static fn(array $call): bool => $call['method'] !== 'GET'));
    }
}

$f = new ProjectWebhookSetupFixture();
$verified = $f->run($connection,false);
webhookSetupCheck('verify plans migration with only provider reads', $verified['ok'] && $verified['state'] === 'planned' && $verified['reason'] === 'registration_required' && count($f->calls) === 2 && $f->writes() === [] && $f->claims === []);
webhookSetupCheck('preflight uses fixed authenticated ignored update without customer data', $f->probes === [['project'=>'appleservice39','url'=>ProjectMaxWebhookSetup::EXPECTED_URL,'update'=>['update_type'=>'project_connection_probe']]] && $f->directoryCalls === 1);
webhookSetupCheck('safe verification output exposes only expected URL and bounded receipt', !str_contains(json_encode($verified),'PRIVATE_') && !str_contains(json_encode($verified),'SYNTHETIC_') && !str_contains(json_encode($verified),str_repeat('s',32)) && $verified['expected_url'] === ProjectMaxWebhookSetup::EXPECTED_URL);
$startCalls = count($f->calls);
$activated = $f->run($connection,true);
$writes = $f->writes();
webhookSetupCheck('activation registers canonical subscription before removing known standalone URL', $activated['state'] === 'active' && count($writes) === 2 && array_column($writes,'method') === ['POST','DELETE'] && $writes[0]['body'] === ['url'=>ProjectMaxWebhookSetup::EXPECTED_URL,'update_types'=>['bot_started','message_created'],'secret'=>str_repeat('s',32)] && $writes[1]['query'] === ['url'=>ProjectMaxWebhookSetup::LEGACY_URL]);
webhookSetupCheck('activation proves canonical presence before allowed legacy deletion', array_column(array_slice($f->calls,$startCalls),'method') === ['GET','GET','GET','POST','GET','DELETE','GET'] && $activated['receipt']['request_count'] === 7 && $activated['receipt']['write_attempts'] === 2);
webhookSetupCheck('commissioning never uses global AnyTour identity or credentials', array_unique(array_column($f->calls,'project')) === ['appleservice39'] && array_unique(array_column($f->calls,'token')) === ['SYNTHETIC_APPLE_TOKEN']);
$again = $f->run($connection,true);
webhookSetupCheck('already active invocation performs no new mutation or claim', $again['reason'] === 'already_active' && $again['receipt']['write_attempts'] === 0 && count($f->writes()) === 2 && count($f->claims) === 2);
webhookSetupCheck('known POST success and canonical readback produce exact trusted registration receipt', $activated['receipt']['registration_confirmed'] && $f->registrationFingerprint === ProjectWebhookSetupFixture::fingerprint($connection));

$f = new ProjectWebhookSetupFixture(false);
$result = $f->run($connection,true);
webhookSetupCheck('empty subscription list registers only fixed canonical URL', $result['state'] === 'active' && count($f->writes()) === 1 && $f->writes()[0]['method'] === 'POST');
$f = new ProjectWebhookSetupFixture(false);
$f->rows = [['url'=>ProjectMaxWebhookSetup::EXPECTED_URL,'update_types'=>['message_created']]];
$result = $f->run($connection,true);
webhookSetupCheck('existing canonical subscription gets exact required private dialogue events', $result['state'] === 'active' && count($f->writes()) === 1 && $f->rows[0]['update_types'] === ['bot_started','message_created']);
$f = new ProjectWebhookSetupFixture(false);
$f->rows = [['url'=>ProjectMaxWebhookSetup::EXPECTED_URL,'update_types'=>ProjectMaxWebhookSetup::UPDATE_TYPES,'secret'=>'OLD_PRIVATE_PROVIDER_SECRET']];
$result = $f->run($connection,false);
webhookSetupCheck('canonical URL and local probe cannot falsely verify provider secret', $result['state'] === 'planned' && !$result['receipt']['registration_confirmed'] && $f->writes() === []);
$result = $f->run($connection,true);
webhookSetupCheck('existing canonical URL without receipt requires explicit secret registration', $result['state'] === 'active' && $result['receipt']['registration_confirmed'] && count($f->writes()) === 1 && $f->writes()[0]['body']['secret'] === str_repeat('s',32));
$result = $f->run($connection,false);
webhookSetupCheck('trusted confirmed receipt makes subsequent verification read-only ready', $result['state'] === 'ready' && count($f->writes()) === 1);
$rotated = $registry;
$rotated['appleservice39']['max']['webhook_secret'] = str_repeat('r',32);
ProjectMaxConnection::resetForTests($rotated);
$rotatedConnection = ProjectMaxConnection::resolve('appleservice39');
$result = $f->run($rotatedConnection,false);
webhookSetupCheck('secret rotation cannot reuse a prior registration receipt', $result['state'] === 'planned' && !$result['receipt']['registration_confirmed']);
ProjectMaxConnection::resetForTests($registry);

$f = new ProjectWebhookSetupFixture();
$missingAdmission = ProjectMaxWebhookSetup::activate($connection,[$f,'provider'],[$f,'probe'],[$f,'directory']);
webhookSetupCheck('activation without durable external admission is refused before network', $missingAdmission['reason'] === 'mutation_admission_required' && $f->calls === [] && $f->probes === []);
$f = new ProjectWebhookSetupFixture(); $f->denyClaim = true;
$result = $f->run($connection,true);
webhookSetupCheck('denied durable claim causes no provider mutation and hides exception text', $result['reason'] === 'mutation_admission_denied' && $f->writes() === [] && !str_contains(json_encode($result),'PRIVATE_CLAIM_FAILURE'));
$f = new ProjectWebhookSetupFixture();
$result = $f->run(ProjectMaxConnection::resolve('repair_two'),true);
webhookSetupCheck('other project is rejected before any IO', $result['reason'] === 'connection_scope_mismatch' && $f->calls === [] && $f->directoryCalls === 0);
$f = new ProjectWebhookSetupFixture(); $f->bot = 'wrong_bot';
$result = $f->run($connection,true);
webhookSetupCheck('provider identity mismatch stops before subscription access', $result['reason'] === 'bot_identity_mismatch' && count($f->calls) === 1 && $f->writes() === []);
foreach ([false,true] as $includeLegacy) {
    $f = new ProjectWebhookSetupFixture($includeLegacy); $f->rows[] = ['url'=>'https://unknown.invalid/PRIVATE_PROVIDER_SECRET'];
    $result = $f->run($connection,true);
    webhookSetupCheck('unknown existing owner blocks all mutations '.(int)$includeLegacy, $result['reason'] === 'unknown_subscription_url' && $f->writes() === [] && $f->claims === [] && !str_contains(json_encode($result),'unknown.invalid'));
}
foreach (['directory','probe','read_malformed','changed_before_write','unknown_before_write'] as $failure) {
    $f = new ProjectWebhookSetupFixture();
    if ($failure === 'directory') $f->directoryReady = false;
    elseif ($failure === 'probe') $f->probeReady = false;
    else $f->mode = $failure;
    $result = $f->run($connection,true);
    webhookSetupCheck('failed preflight cannot mutate '.$failure, !$result['ok'] && $result['state'] === 'blocked' && $f->writes() === [] && $f->claims === []);
}

foreach (['delete_timeout_observed'] as $mode) {
    $f = new ProjectWebhookSetupFixture(); $f->mode = $mode;
    $result = $f->run($connection,true);
    webhookSetupCheck('one readback can confirm unknown mutation without repeat '.$mode, $result['state'] === 'active' && $result['reason'] === 'verified_after_unknown_response' && count($f->writes()) === 2 && $result['receipt']['request_count'] === 7 && !str_contains(json_encode($result),'PRIVATE_'));
}
foreach (['register_timeout_observed','write_malformed'] as $mode) {
    $f = new ProjectWebhookSetupFixture(); $f->mode = $mode;
    $result = $f->run($connection,true);
    webhookSetupCheck('URL readback after unknown POST cannot prove provider secret '.$mode, $result['state'] === 'unknown' && $result['reason'] === 'registration_secret_unconfirmed' && !$result['receipt']['registration_confirmed'] && count($f->writes()) === 1 && $result['receipt']['request_count'] === 5 && $f->registrationFingerprint === null);
    $second = $f->run($connection,true);
    webhookSetupCheck('unknown POST is never repeated even when canonical URL appears '.$mode, $second['reason'] === 'mutation_admission_denied' && count($f->writes()) === 1);
}
foreach (['register_timeout_unobserved','delete_timeout_unobserved'] as $mode) {
    $f = new ProjectWebhookSetupFixture(); $f->mode = $mode;
    $result = $f->run($connection,true); $writes = count($f->writes());
    $second = $f->run($connection,true);
    webhookSetupCheck('unknown mutation stays unconfirmed and durable admission prevents another write '.$mode, !$result['ok'] && $result['state'] === 'unknown' && $second['reason'] === 'mutation_admission_denied' && count($f->writes()) === $writes && $writes === ($mode === 'register_timeout_unobserved' ? 1 : 2));
    $readonly = $f->run($connection,false);
    webhookSetupCheck('unknown mutation has a separate read-only verification path '.$mode, $readonly['state'] === 'planned' && count($f->writes()) === $writes);
}
foreach (['readback_malformed','types_unconfirmed','unknown_after_write'] as $mode) {
    $f = new ProjectWebhookSetupFixture(); $f->mode = $mode;
    $result = $f->run($connection,true);
    webhookSetupCheck('unverified registration never removes legacy webhook '.$mode, $result['state'] === 'unknown' && !$result['ok'] && count($f->writes()) === 1 && $f->writes()[0]['method'] === 'POST');
}

// Exercise the shared bounded transport's allowlist and JSON/body handling without network.
$wireCalls = [];
$wire = static function (string $url, string $method, string $token, ?string $body) use (&$wireCalls): array {
    $wireCalls[] = compact('url','method','token','body');
    return ['http'=>200,'errno'=>0,'body'=>'{"success":true}'];
};
$body = ['url'=>ProjectMaxWebhookSetup::EXPECTED_URL,'update_types'=>ProjectMaxWebhookSetup::UPDATE_TYPES,'secret'=>str_repeat('s',32)];
foreach ([['GET','/me',[],null],['GET','/subscriptions',[],null],['POST','/subscriptions',[],$body],['DELETE','/subscriptions',['url'=>ProjectMaxWebhookSetup::LEGACY_URL],null]] as [$method,$path,$query,$input]) {
    $response = MaxTransport::webhookRequest('SYNTHETIC_APPLE_TOKEN',$method,$path,$query,$input,$wire);
    webhookSetupCheck('shared transport permits bounded webhook operation '.$method.$path, $response['data'] === ['success'=>true] && !$response['transport_error']);
}
webhookSetupCheck('shared transport fixes API host and encodes exact legacy query', $wireCalls[0]['url'] === 'https://platform-api2.max.ru/me' && $wireCalls[3]['url'] === 'https://platform-api2.max.ru/subscriptions?url='.rawurlencode(ProjectMaxWebhookSetup::LEGACY_URL));
$beforeWire = count($wireCalls);
foreach ([
    ['GET','/messages',[],null],['POST','/messages',[],$body],['DELETE','/me',[],null],
    ['PUT','/subscriptions',[],$body],['GET','/me',[],[]],['GET','https://other.invalid/me',[],null],
    ['POST','/subscriptions',['url'=>ProjectMaxWebhookSetup::EXPECTED_URL],$body],
    ['POST','/subscriptions',[],array_replace($body,['url'=>'http://other.invalid/hook'])],
    ['POST','/subscriptions',[],array_replace($body,['secret'=>"bad\r\nsecret"])],
    ['POST','/subscriptions',[],$body+['unexpected'=>'private']],
    ['DELETE','/subscriptions',['url'=>'https://user@other.invalid/hook'],null],
] as [$method,$path,$query,$input]) {
    webhookSetupCheck('shared transport rejects unrelated or malformed operation '.($passed+1), MaxTransport::webhookRequest('SYNTHETIC_APPLE_TOKEN',$method,$path,$query,$input,$wire)['transport_error']);
}
webhookSetupCheck('rejected transport operations never reach the wire', count($wireCalls) === $beforeWire);
$badJson = MaxTransport::webhookRequest('SYNTHETIC_APPLE_TOKEN','GET','/subscriptions',[],null,static fn(): array => ['http'=>200,'errno'=>0,'body'=>'PRIVATE_NOT_JSON']);
$oversize = MaxTransport::webhookRequest('SYNTHETIC_APPLE_TOKEN','GET','/subscriptions',[],null,static fn(): array => ['http'=>200,'errno'=>0,'body'=>str_repeat('x',262145)]);
webhookSetupCheck('HTTP 200 malformed JSON is not valid provider data', $badJson['data'] === null && !str_contains(json_encode($badJson),'PRIVATE_NOT_JSON'));
webhookSetupCheck('provider response body limit is enforced and discarded', $oversize['transport_error'] && $oversize['data'] === null);

// Real read-only directory and real ignored ingress probe leave the shared business store untouched.
foreach (['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS'] as $name) if (!defined($name)) define($name,'isolated_setup_fixture');
$pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE projects(id INTEGER,project_key TEXT,is_active INTEGER); INSERT INTO projects VALUES(1,'appleservice39',1);
    CREATE TABLE conversation_sources(id INTEGER,project_id INTEGER,source_key TEXT,channel TEXT,is_active INTEGER,handling_mode TEXT); INSERT INTO conversation_sources VALUES(1,1,'max:appleservice39-main','max',1,'manager');
    CREATE TABLE customers(id INTEGER); CREATE TABLE conversations(id INTEGER); CREATE TABLE messages(id INTEGER);");
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
require_once dirname(__DIR__).'/services/RepairMaxWebhookService.php';
$realProbe = static function (ProjectMaxConnection $owner, string $url, array $update): array {
    $result = RepairMaxWebhookService::handle($owner->projectKey(),$owner->webhookSecret(),$update);
    return ['http'=>$result['status'],'transport_error'=>false,'data'=>['ok'=>$result['ok'],'result'=>$result['result']]];
};
$f = new ProjectWebhookSetupFixture();
$result = ProjectMaxWebhookSetup::verify($connection,[$f,'provider'],$realProbe);
webhookSetupCheck('real active project/source preflight and ignored probe are accepted', $result['state'] === 'planned');
webhookSetupCheck('authenticated setup probe creates no customer or conversation rows', (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn() === 0 && (int)$pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn() === 0 && (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 0);
$pdo->exec('UPDATE conversation_sources SET is_active=0');
webhookSetupCheck('inactive source fails real metadata preflight', !ProjectMaxWebhookSetup::activeDirectory($connection));
$pdo->exec('UPDATE conversation_sources SET is_active=1; UPDATE projects SET is_active=0');
webhookSetupCheck('inactive project fails real metadata preflight', !ProjectMaxWebhookSetup::activeDirectory($connection));
webhookSetupCheck('commissioning never switches global AnyTour settings', ProjectConfig::projectId() === 'anytour');

$transport = (string)file_get_contents(dirname(__DIR__).'/services/MaxTransport.php');
$bounded = substr($transport,strpos($transport,'public static function webhookRequest'),strpos($transport,'public static function classifyFailure')-strpos($transport,'public static function webhookRequest'));
webhookSetupCheck('new transport uses strict TLS and bounds without legacy logging', str_contains($bounded,'MaxTlsConfig::strictCurlOptions()') && str_contains($bounded,'CURLOPT_FOLLOWLOCATION=>false') && str_contains($bounded,'CURLOPT_TIMEOUT=>6') && str_contains($bounded,'262144') && !str_contains($bounded,'self::log(') && !str_contains($bounded,'logLegacyOutcome'));
echo 'TOTAL '.$passed.' passed'.PHP_EOL;
