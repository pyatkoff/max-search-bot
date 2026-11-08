<?php

declare(strict_types=1);

define('MAX_SEARCH_TOKEN', 'legacy-test-token-12345');
define('MAX_SEARCH_WEBHOOK_SECRET', str_repeat('l', 32));
require_once dirname(__DIR__) . '/services/ProjectMaxMessengerFactory.php';

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
echo "PROJECT MAX CONNECTION REGRESSION PASSED ($passed checks)\n";
