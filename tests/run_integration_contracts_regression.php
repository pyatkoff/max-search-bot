<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ProjectConfig.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';
require_once __DIR__ . '/../services/SearchRequestBuilder.php';
require_once __DIR__ . '/../services/LeadDeliveryGateway.php';
require_once __DIR__ . '/../services/MaxWebhookHealth.php';

$passed = 0;
$failed = 0;
function icCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

$config = [
    'id'=>'test-agency',
    'messenger'=>['provider'=>'max'],
    'search'=>['provider'=>'tourvisor','base_domain'=>'https://example.test'],
    'leads'=>['provider'=>'bitrix','claim_hl'=>33,'iblock_id'=>4,'section_id'=>26,'status_id'=>9,'uon_source_id'=>36],
];
ProjectConfig::resetForTests($config);
IntegrationRegistry::resetForTests();

$sent = [];
$messenger = new MaxMessengerAdapter(
    static function($chatId, string $text) use (&$sent): bool { $sent[] = [$chatId,$text]; return true; },
    static function($chatId, string $text, array $buttons) use (&$sent): bool { $sent[] = [$chatId,$text,$buttons]; return true; }
);
icCheck('messenger contract send', $messenger->send(123, 'hello'), true);
icCheck('messenger captured chat', $sent[0][0], 123);

$request = [
    'departure_city_id'=>1,'country_id'=>4,'date_from'=>'10.09.2026',
    'nights_min'=>7,'adults'=>2,'children'=>0,
];
$search = IntegrationRegistry::searchProvider()->build($request, ['chat_id'=>123]);
icCheck('search provider selected', $search['provider'], 'tourvisor');
icCheck('search provider project', $search['project_id'], 'test-agency');
icCheck('search provider ready', $search['ready'], true);

$state = [
    'departure'=>['city'=>'Москва'],
    'destination'=>['country'=>'Турция'],
    'tourists'=>['adults'=>2,'children'=>0,'children_ages'=>[]],
    'budget'=>['max'=>180000,'currency'=>'RUB'],
    'preferences'=>['первая линия'],
];
$lead = IntegrationRegistry::leadDestination()->plan($state, ['chat_id'=>123]);
icCheck('lead destination selected', $lead['provider'], 'bitrix');
icCheck('lead destination iblock', $lead['iblock_id'], 4);
icCheck('lead summary contains route', mb_strpos($lead['summary'], 'Москва') !== false, true);

icCheck('lead delivery defaults to protected Bitrix mechanism', LeadDeliveryGateway::driver(), 'bitrix');
$leadGatewaySource = file_get_contents(__DIR__ . '/../services/BitrixLeadDeliveryGateway.php');
$maxSearchSource = file_get_contents(__DIR__ . '/../maxsearchclass.php');
icCheck('Bitrix adapter preserves iblock module load', strpos($leadGatewaySource, "includeModule('iblock')") !== false, true);
icCheck('Bitrix adapter preserves CIBlockElement Add', strpos($leadGatewaySource, 'CIBlockElement') !== false && strpos($leadGatewaySource, '->Add($element)') !== false, true);
icCheck('savePhone delegates lead creation to boundary', strpos($maxSearchSource, 'LeadDeliveryGateway::create($element)') !== false, true);
icCheck('savePhone no longer owns direct CIBlockElement creation', strpos($maxSearchSource, 'new CIblockElement') === false && strpos($maxSearchSource, 'new CIBlockElement') === false, true);

$expectedWebhook = 'https://app.anytoour.ru/webhook.php';
$healthyWebhook = MaxWebhookHealth::evaluate([
    'http'=>200,
    'errno'=>0,
    'body'=>json_encode(['subscriptions'=>[['url'=>$expectedWebhook]]]),
], $expectedWebhook);
icCheck('MAX webhook health accepts exactly one expected owner', $healthyWebhook['ok'] ?? false, true);
icCheck('MAX webhook health reports healthy owner', $healthyWebhook['reason'] ?? '', 'healthy');
$dualWebhook = MaxWebhookHealth::evaluate([
    'http'=>200,
    'errno'=>0,
    'body'=>json_encode(['subscriptions'=>[
        ['url'=>$expectedWebhook],
        ['url'=>'https://anytour.online/max-search/webhook.php'],
    ]]),
], $expectedWebhook);
icCheck('MAX webhook health rejects dual ownership', $dualWebhook['ok'] ?? true, false);
icCheck('MAX webhook health identifies extra subscription', $dualWebhook['reason'] ?? '', 'extra_subscriptions');
$wrongWebhook = MaxWebhookHealth::evaluate([
    'http'=>200,
    'errno'=>0,
    'body'=>json_encode(['subscriptions'=>[['url'=>'https://anytour.online/max-search/webhook.php']]]),
], $expectedWebhook);
icCheck('MAX webhook health rejects wrong owner', $wrongWebhook['ok'] ?? true, false);
icCheck('MAX webhook health fails closed on transport error', MaxWebhookHealth::evaluate(['http'=>0,'errno'=>7,'body'=>''], $expectedWebhook)['reason'] ?? '', 'transport_error');
$maxHealthSource = file_get_contents(__DIR__ . '/../services/MaxWebhookHealth.php');
icCheck('MAX webhook health is read-only', stripos($maxHealthSource, 'CURLOPT_POST') === false && stripos($maxHealthSource, 'CURLOPT_CUSTOMREQUEST') === false, true);

ProjectConfig::resetForTests(['messenger'=>['provider'=>'telegram']]);
IntegrationRegistry::resetForTests();
icCheck('telegram provider is supported', get_class(IntegrationRegistry::messenger()), 'TelegramMessengerAdapter');

ProjectConfig::resetForTests(['messenger'=>['provider'=>'unsupported_test']]);
IntegrationRegistry::resetForTests();
$unsupported = false;
try { IntegrationRegistry::messenger(); } catch (RuntimeException $e) { $unsupported = true; }
icCheck('unknown provider fails explicitly', $unsupported, true);

ProjectConfig::resetForTests(null);
IntegrationRegistry::resetForTests();

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
