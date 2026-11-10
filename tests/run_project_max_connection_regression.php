<?php

declare(strict_types=1);

define('MAX_SEARCH_TOKEN', 'legacy-test-token-12345');
define('MAX_SEARCH_WEBHOOK_SECRET', str_repeat('l', 32));
require_once dirname(__DIR__) . '/services/ProjectMaxMessengerFactory.php';
require_once dirname(__DIR__) . '/services/RepairProjectSettings.php';

$passed = 0;
function connectionCheck(string $name, bool $condition): void
{
    global $passed;
    if (!$condition) throw new RuntimeException('FAIL '.$name);
    $passed++;
    echo 'PASS '.$name."\n";
}
function repairConnectionEntry(string $key, string $suffix): array
{
    return [
        'project'=>['id'=>$key,'brand'=>['name'=>'Repair '.$suffix],
            'business'=>['kind'=>'device_repair'],'routing'=>['source_key'=>'max:'.$key.'-main']],
        'max'=>['bot_key'=>'repair_'.$suffix.'_bot','token'=>'test-token-'.$suffix.'-12345',
            'webhook_secret'=>str_repeat($suffix, 32)],
    ];
}

$legacySettings = ProjectConfig::all();
$registry = ['appleservice39'=>repairConnectionEntry('appleservice39', 'a'),
    'second_repair'=>repairConnectionEntry('second_repair', 'b')];
ProjectMaxConnection::resetForTests($registry);
$apple = ProjectMaxConnection::resolve('appleservice39');
$other = ProjectMaxConnection::resolve('second_repair');
connectionCheck('both configured connections resolve independently', $apple !== null && $other !== null);
connectionCheck('exact project settings are selected', $apple->settings()->projectId() === 'appleservice39');
connectionCheck('credentials are not inherited', $apple->token() === $registry['appleservice39']['max']['token'] && $apple->token() !== $other->token());
connectionCheck('legacy facade is not switched', ProjectConfig::all() === $legacySettings);
connectionCheck('new project has no implicit tour configuration', $apple->settings()->get('search') === null && $apple->settings()->get('leads') === null);
connectionCheck('provider origin is fixed', $apple->baseUrl() === 'https://platform-api2.max.ru');
connectionCheck('legacy connection retains its token', ProjectMaxConnection::resolve('anytour')->token() === MAX_SEARCH_TOKEN);
connectionCheck('authentic secret selects its project', ProjectMaxConnection::authenticate('appleservice39', str_repeat('a',32)) !== null);
connectionCheck('another project secret cannot authenticate', ProjectMaxConnection::authenticate('appleservice39', str_repeat('b',32)) === null);
connectionCheck('legacy secret cannot authenticate repair', ProjectMaxConnection::authenticate('appleservice39', MAX_SEARCH_WEBHOOK_SECRET) === null);
connectionCheck('empty secret cannot authenticate', ProjectMaxConnection::authenticate('appleservice39', '') === null);
foreach (['unknown','AnyTour','../anytour',' appleservice39',str_repeat('a',41),''] as $key) {
    connectionCheck('unknown or invalid project is closed: '.$key, ProjectMaxConnection::resolve($key) === null);
}

$copy = $registry;
$copy['appleservice39']['project']['id'] = 'second_repair';
ProjectMaxConnection::resetForTests($copy);
connectionCheck('settings scope substitution fails', ProjectMaxConnection::resolve('appleservice39') === null);
foreach (['token','webhook_secret','bot_key'] as $field) {
    $copy = $registry;
    $copy['appleservice39']['max'][$field] = $copy['second_repair']['max'][$field];
    ProjectMaxConnection::resetForTests($copy);
    connectionCheck('duplicate '.$field.' fails for both projects', ProjectMaxConnection::resolve('appleservice39') === null && ProjectMaxConnection::resolve('second_repair') === null);
}
foreach (['token'=>MAX_SEARCH_TOKEN, 'webhook_secret'=>MAX_SEARCH_WEBHOOK_SECRET,
    'bot_key'=>ProjectConfig::get('channel_posts.max.bot_key')] as $field=>$value) {
    $copy = $registry;
    $copy['appleservice39']['max'][$field] = $value;
    ProjectMaxConnection::resetForTests($copy);
    connectionCheck('legacy '.$field.' cannot bind repair', ProjectMaxConnection::resolve('appleservice39') === null);
    connectionCheck('misconfigured repair does not disable legacy', ProjectMaxConnection::resolve('anytour') !== null);
}
foreach ([['token',''],['token',"test-token\r\ninjected"],['token',123],
    ['webhook_secret','short'],['bot_key','https://untrusted.invalid/']] as [$field,$value]) {
    $copy = $registry;
    $copy['appleservice39']['max'][$field] = $value;
    ProjectMaxConnection::resetForTests($copy);
    connectionCheck('invalid '.$field.' is rejected', ProjectMaxConnection::resolve('appleservice39') === null);
}
ProjectMaxConnection::resetForTests($registry);
connectionCheck('resolved object remains immutable after registry replacement', $apple->token() === 'test-token-a-12345');
ob_start();
var_dump($apple);
$debug = ob_get_clean();
connectionCheck('debug view redacts credentials', strpos($debug, $apple->token()) === false && strpos($debug,$apple->webhookSecret()) === false);
connectionCheck('legacy lifecycle requires both stored project and MAX',
    ProjectMaxConnection::isLegacyConversation(['project_key'=>'anytour','channel'=>'max'])
    && !ProjectMaxConnection::isLegacyConversation(['project_key'=>'appleservice39','channel'=>'max'])
    && !ProjectMaxConnection::isLegacyConversation(['project_key'=>'anytour','channel'=>'telegram'])
    && !ProjectMaxConnection::isLegacyConversation(['channel'=>'max']));

$selected = [];
ProjectMaxMessengerFactory::resetForTests(static function (ProjectMaxConnection $connection, string $sender) use (&$selected): MaxMessengerAdapter {
    $selected[] = [$connection->projectKey(),$connection->token(),$sender];
    return new MaxMessengerAdapter(static fn()=> 'test-mid', null, $sender, null, false);
});
connectionCheck('factory consumes an authenticated server connection', ProjectMaxMessengerFactory::forConnection($apple, 'manager') instanceof MaxMessengerAdapter);
connectionCheck('factory passes exact credentials and sender', $selected === [['appleservice39','test-token-a-12345','manager']]);
connectionCheck('unknown conversation is rejected before transport selection', ProjectMaxMessengerFactory::forConversation(['project_key'=>'unknown','channel'=>'max']) === null && count($selected) === 1);
connectionCheck('wrong channel is rejected before transport selection', ProjectMaxMessengerFactory::forConversation(['project_key'=>'appleservice39','channel'=>'telegram']) === null && count($selected) === 1);
connectionCheck('missing project cannot fall back to legacy', ProjectMaxMessengerFactory::forConversation(['channel'=>'max']) === null && count($selected) === 1);
connectionCheck('new project without saved conversation binding cannot dispatch', ProjectMaxMessengerFactory::forConversation(['project_key'=>'appleservice39','channel'=>'max']) === null && count($selected) === 1);
ProjectMaxMessengerFactory::resetForTests();
ProjectMaxConnection::resetForTests();
connectionCheck('unconfigured repair stays disabled', ProjectMaxConnection::resolve('appleservice39') === null);
connectionCheck('all attempts leave legacy settings intact', ProjectConfig::all() === $legacySettings);

// Both public profile spelling and the existing installed kind have one meaning.
foreach ([['kind'=>'device_repair'],['profile'=>'device_repair'],['kind'=>'device_repair','profile'=>'device_repair']] as $business) {
    connectionCheck('repair profile aliases agree', RepairProjectSettings::businessKind(new ProjectConfiguration('repair_two', ['id'=>'repair_two','business'=>$business])) === 'device_repair');
}
foreach ([['kind'=>false],['profile'=>[]],['kind'=>''],['kind'=>'Device_Repair'],['kind'=>null,'profile'=>'device_repair'],['kind'=>'device_repair','profile'=>null],['kind'=>'device_repair','profile'=>'tourism'],['kind'=>'tourism','profile'=>'device_repair']] as $business) {
    connectionCheck('malformed or conflicting repair profile is closed', RepairProjectSettings::businessKind(new ProjectConfiguration('repair_two', ['id'=>'repair_two','business'=>$business])) === null);
}
$configured = repairConnectionEntry('second_repair', 'b');
$configured['project']['business']['repair']['entries'] = ['battery'=>['model'=>'Phone B','service'=>'Замена батареи']];
$configured['project']['messenger']['max']['webhook_url'] = 'https://app.anytoour.ru/project_max_webhook.php?project=second_repair';
ProjectMaxConnection::resetForTests(['second_repair'=>$configured]);
$configuredConnection = ProjectMaxConnection::resolve('second_repair');
$entry = RepairProjectSettings::entry($configuredConnection, 'battery');
connectionCheck('configured entry binds exact project context', $entry['model'] === 'Phone B' && $entry['service'] === 'Замена батареи' && $entry['post_id'] === 'battery');
foreach (['p_foreign','as39_x_battery','ts_foreign','s3_foreign','../battery',str_repeat('x',65),''] as $key) {
    connectionCheck('foreign and malformed entry payload cannot choose catalog content', RepairProjectSettings::entry($configuredConnection, $key) === null);
}
foreach ([['model'=>'Phone B','service'=>'<b>repair</b>'],['model'=>'Phone B','service'=>"repair\nother"],['model'=>'Phone B','service'=>'repair','price'=>1],['model'=>'','service'=>'repair'],['model'=>str_repeat('x',321),'service'=>'repair']] as $bad) {
    $copy = $configured;
    $copy['project']['business']['repair']['entries']['battery'] = $bad;
    ProjectMaxConnection::resetForTests(['second_repair'=>$copy]);
    connectionCheck('unvalidated configured facts are not rendered', RepairProjectSettings::entry(ProjectMaxConnection::resolve('second_repair'), 'battery') === null);
}
$copy = $configured;
$copy['project']['business']['repair']['entries'] = array_fill_keys(array_map(static fn(int $i): string => 'entry'.$i, range(1,129)), ['model'=>'Phone B','service'=>'repair']);
ProjectMaxConnection::resetForTests(['second_repair'=>$copy]);
connectionCheck('entry lookup keeps a finite configuration bound', RepairProjectSettings::entry(ProjectMaxConnection::resolve('second_repair'), 'entry1') === null);

// Exercise the actual HTTP interface with inert unsupported updates only.
// A fixture-owned configuration is installed in a private temporary document root.
$httpRoot = sys_get_temp_dir().'/repair-connection-http-'.bin2hex(random_bytes(8));
$server = null;
mkdir($httpRoot,0700);
try {
    $root = dirname(__DIR__);
    foreach (['services','integrations','contracts','resources'] as $directory) {
        if (!symlink($root.'/'.$directory, $httpRoot.'/'.$directory)) throw new RuntimeException('fixture_link_unavailable');
    }
    copy($root.'/project_max_webhook.php', $httpRoot.'/project_max_webhook.php');
    $httpRegistry = ['second_repair'=>$configured,'disabled_repair'=>repairConnectionEntry('disabled_repair','c')];
    $httpRegistry['appleservice39'] = repairConnectionEntry('appleservice39','a');
    $httpRegistry['appleservice39']['max']['bot_key'] = AppleService39PilotCatalog::BOT;
    $httpRegistry['appleservice39']['project']['routing']['source_key'] = 'max:appleservice39-main';
    $config = '<?php define("MAX_SEARCH_PROJECT_CONNECTIONS",'.var_export($httpRegistry,true).');'
        .'define("MAX_SEARCH_TOKEN","SYNTHETIC_HTTP_LEGACY_TOKEN");define("MAX_SEARCH_WEBHOOK_SECRET",str_repeat("l",32));';
    file_put_contents($httpRoot.'/config.php', $config);
    chmod($httpRoot.'/config.php',0600);
    $listener = stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if (!$listener) throw new RuntimeException('fixture_listener_unavailable');
    $address = stream_socket_get_name($listener,false); fclose($listener);
    $server = proc_open([PHP_BINARY,'-d','display_errors=0','-S',$address,'-t',$httpRoot],
        [0=>['file','/dev/null','r'],1=>['file',$httpRoot.'/server.log','a'],2=>['file',$httpRoot.'/server.log','a']],$pipes);
    if (!is_resource($server)) throw new RuntimeException('fixture_server_unavailable');
    $ready = false;
    for ($attempt=0;$attempt<50;$attempt++) {
        $socket = @stream_socket_client('tcp://'.$address,$errno,$error,0.05);
        if ($socket) { fclose($socket); $ready=true; break; }
        usleep(20000);
    }
    if (!$ready) throw new RuntimeException('fixture_server_not_ready');
    $request = static function (string $query, string $secret, string $body, string $method = 'POST') use ($address): array {
        $context = stream_context_create(['http'=>['method'=>$method,'header'=>"Content-Type: application/json\r\nX-Max-Bot-Api-Secret: ".$secret."\r\n",'content'=>$body,'ignore_errors'=>true,'timeout'=>3]]);
        $result = file_get_contents('http://'.$address.'/project_max_webhook.php?'.$query,false,$context);
        preg_match('/\AHTTP\/\S+ ([0-9]{3})/', $http_response_header[0] ?? '', $match);
        return [(int)($match[1] ?? 0),json_decode((string)$result,true)];
    };
    $probe = '{"update_type":"project_connection_probe"}';
    $httpProbe = $request('project=second_repair',str_repeat('b',32),$probe);
    if ($httpProbe !== [200,['ok'=>true,'result'=>'ignored']]) throw new RuntimeException('fixture HTTP probe failed: '.json_encode($httpProbe).' '.file_get_contents($httpRoot.'/server.log'));
    connectionCheck('generic endpoint accepts its configured authenticated inert probe', $httpProbe === [200,['ok'=>true,'result'=>'ignored']]);
    connectionCheck('HTTP caller cannot substitute another project secret', $request('project=second_repair',str_repeat('a',32),$probe)[0] === 403);
    connectionCheck('unknown and unenabled projects remain closed', $request('project=unknown',str_repeat('b',32),$probe)[0] === 403 && $request('project=disabled_repair',str_repeat('c',32),$probe)[0] === 403);
    connectionCheck('legacy Apple endpoint cannot be silently replaced', $request('project=appleservice39',str_repeat('a',32),$probe)[0] === 403);
    connectionCheck('non-scalar project selector is rejected', $request('project[]=second_repair',str_repeat('b',32),$probe)[0] === 403);
    connectionCheck('HTTP method is checked before configuration', $request('project=second_repair',str_repeat('b',32),'','GET')[0] === 405);
    connectionCheck('authenticated malformed and excessive bodies are rejected', $request('project=second_repair',str_repeat('b',32),'[')[0] === 400 && $request('project=second_repair',str_repeat('b',32),str_repeat('x',262145))[0] === 400);
    connectionCheck('authentication precedes body parsing', $request('project=second_repair','wrong','[')[0] === 403);
    connectionCheck('payload cannot override its server-selected scope', $request('project=second_repair',str_repeat('b',32),'{"update_type":"project_connection_probe","project":"anytour","url":"https://foreign.invalid/","token":"fake"}')[0] === 200);
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach (glob($httpRoot.'/*') ?: [] as $path) unlink($path);
    rmdir($httpRoot);
}
ProjectMaxConnection::resetForTests();
echo "PROJECT MAX CONNECTION REGRESSION PASSED ($passed checks)\n";
