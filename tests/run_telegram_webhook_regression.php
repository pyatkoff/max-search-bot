<?php

declare(strict_types=1);

if (!defined('TELEGRAM_WEBHOOK_SECRET')) define('TELEGRAM_WEBHOOK_SECRET', 'test-secret');
if (!defined('TELEGRAM_BOT_TOKEN')) define('TELEGRAM_BOT_TOKEN', 'test-token');

require_once __DIR__ . '/../services/ProjectConfig.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';
require_once __DIR__ . '/../services/DialogueApplication.php';
require_once __DIR__ . '/../services/IncomingUpdateDispatcher.php';
require_once __DIR__ . '/../services/TelegramWebhookHealth.php';
require_once __DIR__ . '/../services/TelegramStartSourceResolver.php';
require_once __DIR__ . '/../integrations/TelegramMessengerAdapter.php';
require_once __DIR__ . '/../handlers/TelegramWebhookHandler.php';

$passed = 0;
$failed = 0;
function twCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

ProjectConfig::resetForTests([
    'id'=>'anytour',
    'messenger'=>['telegram'=>['source_key'=>'telegram:anytour-main']],
]);

twCheck('Webhook accepts correct secret', TelegramWebhookHandler::secretAccepted('test-secret'), true);
twCheck('Webhook rejects wrong secret', TelegramWebhookHandler::secretAccepted('wrong'), false);
twCheck('Deep-link payload builds configured source candidates', TelegramStartSourceResolver::candidateKeys('tg_anytour_msk'), ['tg_anytour_msk','tg:anytour-msk','telegram:anytour-msk']);
$knownSources=['tg:anytour-msk'=>true,'telegram:anytour-main'=>true];
$lookup=static fn(string $key): string => !empty($knownSources[$key]) ? $key : '';
$directKnownSources=['tg_anytour_msk'=>true,'tg_anytour_msk2'=>true,'telegram:anytour-main'=>true];
$directLookup=static fn(string $key): string => !empty($directKnownSources[$key]) ? $key : '';
twCheck('Published tg_anytour_msk link keeps exact canonical source', TelegramStartSourceResolver::resolve(['text'=>'/start tg_anytour_msk'],$directLookup), 'tg_anytour_msk');
twCheck('Published tg_anytour_msk2 link keeps exact canonical source', TelegramStartSourceResolver::resolve(['text'=>'/start tg_anytour_msk2'],$directLookup), 'tg_anytour_msk2');
twCheck('Deep-link tg_anytour_msk resolves to existing Telegram source', TelegramStartSourceResolver::resolve(['text'=>'/start tg_anytour_msk'],$lookup), 'tg:anytour-msk');
twCheck('Unknown deep-link source falls back to configured source', TelegramStartSourceResolver::resolve(['text'=>'/start tg_unknown_place'],$lookup), 'telegram:anytour-main');
twCheck('Non-start message cannot change source', TelegramStartSourceResolver::resolve(['text'=>'tg_anytour_msk'],$lookup), 'telegram:anytour-main');

$messages = [];
$callbacks = [];
$contacts = [];
$acks = [];
$app = new DialogueApplication(
    static function(array $message, array $incoming) use (&$messages): void { $messages[] = [$message,$incoming]; },
    static function(array $query, array $incoming) use (&$callbacks): void { $callbacks[] = [$query,$incoming]; },
    static function($chatId, string $phone, array $incoming) use (&$contacts): void { $contacts[] = [$chatId,$phone,$incoming]; },
    static function(string $callbackId, array $incoming) use (&$acks): void { $acks[] = [$callbackId,$incoming]; }
);
$dispatcher = new IncomingUpdateDispatcher($app);

$outbound = [];
$messenger = new TelegramMessengerAdapter(static function(string $method, array $payload) use (&$outbound): bool {
    $outbound[] = [$method,$payload];
    return true;
});

$textUpdate = [
    'update_id'=>1001,
    'message'=>[
        'message_id'=>50,
        'from'=>['id'=>777,'first_name'=>'Pavel','username'=>'ptest'],
        'chat'=>['id'=>777],
        'text'=>'/start',
    ],
];
twCheck('Text update handled', TelegramWebhookHandler::dispatchUpdate($textUpdate,$dispatcher,$messenger), true);
twCheck('Text update reaches application', $messages[0][0]['text'] ?? null, '/start');
twCheck('Text update keeps Telegram platform', $messages[0][1]['platform'] ?? null, 'telegram');
twCheck('Telegram keeps positive chat id', $messages[0][1]['user']['chat_id'] ?? null, 777);
twCheck('Telegram update gets project-specific source', $messages[0][1]['source_key'] ?? null, 'telegram:anytour-main');
twCheck('Registry overridden to Telegram messenger', IntegrationRegistry::messenger() === $messenger, true);

$callbackUpdate = [
    'update_id'=>1002,
    'callback_query'=>[
        'id'=>'tg-cb-1',
        'from'=>['id'=>777,'first_name'=>'Pavel'],
        'data'=>'pick_country_4',
    ],
];
twCheck('Callback update handled', TelegramWebhookHandler::dispatchUpdate($callbackUpdate,$dispatcher,$messenger), true);
twCheck('Callback payload reaches application', $callbacks[0][0]['data'] ?? null, 'pick_country_4');
twCheck('Callback keeps Telegram source', $callbacks[0][1]['source_key'] ?? null, 'telegram:anytour-main');
twCheck('Callback acknowledgement hook runs', $acks[0][0] ?? null, 'tg-cb-1');

$contactUpdate = [
    'update_id'=>1003,
    'message'=>[
        'message_id'=>51,
        'from'=>['id'=>777,'first_name'=>'Pavel'],
        'contact'=>['phone_number'=>'+79990000000'],
    ],
];
twCheck('Contact update handled', TelegramWebhookHandler::dispatchUpdate($contactUpdate,$dispatcher,$messenger), true);
twCheck('Contact phone reaches application', $contacts[0][1] ?? null, '+79990000000');
twCheck('Contact keeps Telegram source', $contacts[0][2]['source_key'] ?? null, 'telegram:anytour-main');

$messenger->answerCallback('direct-callback');
twCheck('Telegram answerCallback uses API method', $outbound[0][0] ?? null, 'answerCallbackQuery');
twCheck('Telegram answerCallback sends callback id', $outbound[0][1]['callback_query_id'] ?? null, 'direct-callback');

$healthRequest = static function(string $method): array {
    if ($method === 'getMe') return ['transport_ok'=>true,'http'=>200,'json'=>['ok'=>true,'result'=>['id'=>123,'username'=>'Any_tour_bot','first_name'=>'AnyTour']]];
    return ['transport_ok'=>true,'http'=>200,'json'=>['ok'=>true,'result'=>['url'=>'https://example.test/current-webhook','pending_update_count'=>2,'allowed_updates'=>['message','callback_query']]]];
};
$health = TelegramWebhookHealth::collect($healthRequest);
twCheck('Telegram health probe validates API', $health['ok'] ?? null, true);
twCheck('Telegram health probe exposes username without token', $health['bot']['username'] ?? null, 'Any_tour_bot');
twCheck('Telegram health probe exposes current webhook', $health['webhook']['url'] ?? null, 'https://example.test/current-webhook');
twCheck('Telegram health probe never exposes token', array_key_exists('token',$health), false);

$explicitHealth = TelegramWebhookHealth::collectToken('different-test-token',$healthRequest);
twCheck('Telegram health supports explicit token identity inspection', $explicitHealth['bot']['id'] ?? null, 123);
twCheck('Explicit token health still never exposes token', array_key_exists('token',$explicitHealth), false);

// BEGIN bounded read-only smoke transport regression (synthetic, no network).
$probeFailure = static fn(int $errno, int $http = 0): array => [
    'transport_ok'=>false, 'http'=>$http, 'curl_errno'=>$errno,
    'curl_error'=>'https://api.telegram.org/botPRIVATE_TEST_SECRET/getMe', 'json'=>null,
];
foreach ([6,7,28,52,55,56] as $errno) {
    foreach (['getMe','getWebhookInfo'] as $failedMethod) {
        $probeCalls=[];$probeCounts=[];
        $request=static function(string $method, int $budgetMs) use (&$probeCalls,&$probeCounts,$errno,$failedMethod,$probeFailure,$healthRequest): array {
            $probeCalls[]=[$method,$budgetMs];$probeCounts[$method]=($probeCounts[$method]??0)+1;
            return $method===$failedMethod&&$probeCounts[$method]===1 ? $probeFailure($errno) : $healthRequest($method);
        };
        $recovered=TelegramWebhookHealth::collectToken('PRIVATE_TEST_SECRET',$request,true);
        $prefix=$failedMethod==='getMe'?'get_me':'get_webhook_info';
        $label=$failedMethod.' errno '.$errno;
        twCheck($label.' recovers only after a successful read', $recovered['ok'], true);
        twCheck($label.' repeats only the failed read', array_column($probeCalls,0),
            $failedMethod==='getMe'?['getMe','getMe','getWebhookInfo']:['getMe','getWebhookInfo','getWebhookInfo']);
        twCheck($label.' preserves original failure evidence', $recovered['transport'][$prefix.'_prior_failures'][0]['curl_errno'], $errno);
        twCheck($label.' reports bounded recovery', [$recovered['transport'][$prefix.'_attempts'],$recovered['transport'][$prefix.'_recovered']], [2,true]);
        twCheck($label.' budgets never disable or increase timeout', count(array_filter($probeCalls,static fn(array $call):bool=>$call[1]>0&&$call[1]<=15000)), 3);
        twCheck($label.' does not expose token or raw transport text', str_contains(json_encode($recovered),'PRIVATE_TEST_SECRET'), false);
        twCheck($label.' preserves webhook and identity data', [$recovered['bot'],$recovered['webhook']], [$health['bot'],$health['webhook']]);
    }
}
$nonRetryable=[
    'unauthorized'=>$probeFailure(0,401), 'forbidden'=>$probeFailure(0,403),
    'rate_limit'=>$probeFailure(0,429), 'server_error'=>$probeFailure(0,500),
    'unavailable'=>$probeFailure(0,503), 'tls_handshake'=>$probeFailure(35),
    'tls_certificate'=>$probeFailure(60), 'tls_ca'=>$probeFailure(77),
    'unknown_transport'=>$probeFailure(99), 'no_classification'=>$probeFailure(0),
    'invalid_json'=>['transport_ok'=>true,'http'=>200,'json'=>null],
    'api_refusal'=>['transport_ok'=>true,'http'=>200,'json'=>['ok'=>false]],
];
foreach ($nonRetryable as $label=>$failure) {
    $probeCalls=[];
    $request=static function(string $method) use (&$probeCalls,$failure,$healthRequest):array {
        $probeCalls[]=$method;return $method==='getMe'?$failure:$healthRequest($method);
    };
    $refused=TelegramWebhookHealth::collectToken('PRIVATE_TEST_SECRET',$request,true);
    twCheck($label.' stays failed', $refused['ok'], false);
    twCheck($label.' is not retried', $probeCalls, ['getMe','getWebhookInfo']);
    twCheck($label.' has no recovery flag', $refused['transport']['get_me_recovered'], false);
}
$probeCalls=[];
$persistent=static function(string $method)use(&$probeCalls,$probeFailure):array {$probeCalls[]=$method;return $probeFailure(7);};
$refused=TelegramWebhookHealth::collectToken('PRIVATE_TEST_SECRET',$persistent,true);
twCheck('persistent failure has at most two attempts per method', $probeCalls, ['getMe','getMe','getWebhookInfo','getWebhookInfo']);
twCheck('persistent failure never passes smoke health', $refused['ok'], false);
$probeCalls=[];
$legacy=TelegramWebhookHealth::collectToken('PRIVATE_TEST_SECRET',$persistent);
twCheck('existing status readers do not acquire retries', $probeCalls, ['getMe','getWebhookInfo']);
twCheck('existing status readers retain failure', $legacy['ok'], false);
$probeCalls=[];
twCheck('missing token remains unconfigured', TelegramWebhookHealth::collectToken('',$persistent,true), ['ok'=>false,'configured'=>false,'reason'=>'missing_token']);
twCheck('missing token makes no API requests', $probeCalls, []);
$exception=TelegramWebhookHealth::collectToken('PRIVATE_TEST_SECRET',static function():array {throw new RuntimeException('https://example.test/PRIVATE_TEST_SECRET');},true);
twCheck('unknown exception remains an error', [$exception['ok'],$exception['reason']], [false,'exception']);
twCheck('exception detail cannot disclose tokens', str_contains(json_encode($exception),'PRIVATE_TEST_SECRET'), false);

// Exercise the real private retry loop with a deterministic monotonic clock;
// no sleep, credential, external request or replacement retry implementation.
$readProbe=new ReflectionMethod(TelegramWebhookHealth::class,'readProbe');
foreach ([12000,15000,20000] as $elapsed) {
    $probeClock=0.0;$budgets=[];
    $request=static function(string $method,int $budget)use(&$probeClock,&$budgets,$elapsed,$probeFailure,$healthRequest):array {
        $budgets[]=$budget;
        if(count($budgets)===1){$probeClock+=$elapsed;return $probeFailure(28);}
        return $healthRequest($method);
    };
    $clock=static function()use(&$probeClock):float{return $probeClock;};
    $probe=$readProbe->invoke(null,$request,'getMe',$clock);
    twCheck('elapsed '.$elapsed.' shares original 15-second budget', $budgets, $elapsed<15000?[15000,15000-$elapsed]:[15000]);
    twCheck('elapsed '.$elapsed.' requires real successful result', $probe['transport_ok'], $elapsed<15000);
}
$probeCalls=[];
foreach (['sendMessage','setWebhook','deleteWebhook','getUpdates'] as $method) {
    $denied=false;
    try {$readProbe->invoke(null,$persistent,$method);} catch (InvalidArgumentException $e) {$denied=$e->getMessage()==='unsupported_health_read';}
    twCheck('retry boundary refuses '.$method, $denied, true);
}
twCheck('disallowed methods never reach transport', $probeCalls, []);
$probeCalls=[];
$invalidAfterRetry=static function(string $method)use(&$probeCalls,$probeFailure,$healthRequest):array {
    $probeCalls[]=$method;
    if($method!=='getMe')return $healthRequest($method);
    return count($probeCalls)===1?$probeFailure(7):['transport_ok'=>true,'http'=>200,'json'=>null];
};
$refused=TelegramWebhookHealth::collectToken('PRIVATE_TEST_SECRET',$invalidAfterRetry,true);
twCheck('second invalid response cannot turn a retry into success', [$refused['ok'],$refused['transport']['get_me_recovered']], [false,false]);
// END bounded read-only smoke transport regression.

$channelUpdate = ['update_id'=>1004,'edited_channel_post'=>['text'=>'ignore me']];
twCheck('Channel update is consumed before personal dispatcher', TelegramWebhookHandler::dispatchUpdate($channelUpdate,$dispatcher,$messenger), true);
$ignored = ['update_id'=>1005,'poll'=>['question'=>'ignore me']];
twCheck('Unsupported update ignored safely', TelegramWebhookHandler::dispatchUpdate($ignored,$dispatcher,$messenger), false);

IntegrationRegistry::resetForTests();
ProjectConfig::resetForTests(null);

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
