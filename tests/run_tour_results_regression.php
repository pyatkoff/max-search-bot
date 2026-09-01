<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ProjectConfig.php';
require_once __DIR__ . '/../services/TourResultsService.php';

$passed = 0;
$failed = 0;
function trCheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

if (!class_exists('MaxSearchApi')) {
    class MaxSearchApi {
        public static function getSavedData($chatId): array { return ['city'=>1]; }
        public static function saveClaim($chatId, $savedData): string { return 'https://legacy.test/search/abc/?yclid=777'; }
        public static function getLastClaimForChat($chatId): array { return ['UF_CITY'=>1,'UF_COUNTRY'=>4,'UF_CODE'=>'abc']; }
        public static function getLatestYclid($chatId): string { return '777'; }
        public static function buildChannelMiniappUrl($chatId): string { return 'https://max.ru/test?startapp=777'; }
    }
}

ProjectConfig::resetForTests([
    'id'=>'test',
    'messenger'=>[
        'provider'=>'max',
        'open_channel_path'=>'/track/channel.php',
    ],
    'search'=>[
        'base_domain'=>'https://public-search.test',
        'search_path'=>'/poisk-turov/',
        'tracking_base_domain'=>'https://tracker.test',
        'open_tours_path'=>'/track/tours.php',
    ],
]);

$model = TourResultsService::build(-123, 'Pavel');
$canonical = 'https://public-search.test/poisk-turov/?from=1&country=4&yclid=777';
trCheck('claim url canonicalized', $model['claim_url'], $canonical);
trCheck('channel url preserved', $model['channel_url'], 'https://max.ru/test?startapp=777');
trCheck('MAX button label', $model['buttons'][1][0]['text'], '🔥 Горящие туры в MAX');
trCheck('manager callback', $model['buttons'][2][0]['callback_data'], 'manager_after_tours');
trCheck('edit callback', $model['buttons'][3][0]['callback_data'], 'edit_params');
trCheck('tour tracking keeps canonical target', $model['buttons'][0][0]['url'], 'https://tracker.test/track/tours.php?chat=-123&url='.rawurlencode($canonical));
trCheck('channel tracking url uses tracking origin', $model['buttons'][1][0]['url'], 'https://tracker.test/track/channel.php?chat=-123&url='.rawurlencode('https://max.ru/test?startapp=777'));
trCheck('public and tracking origins stay independent', ProjectConfig::baseDomain(), 'https://public-search.test');
trCheck('MAX message wording', strpos($model['text'], 'MAX-канал') !== false, true);

ProjectConfig::resetForTests([
    'messenger'=>['provider'=>'telegram'],
    'search'=>['base_domain'=>'https://other.test'],
]);
trCheck('tracking origin falls back to base domain', ProjectConfig::trackingBaseDomain(), 'https://other.test');
trCheck('Telegram button label', TourResultsService::channelButtonText(), '🔥 Горящие туры в Telegram');
trCheck('Telegram message wording', strpos(TourResultsService::messageText(), 'Telegram-канал') !== false, true);
trCheck('absolute tracking path supported', TourResultsService::trackedUrl('https://tracker.test/open', 5, 'https://target.test/a'), 'https://tracker.test/open?chat=5&url='.rawurlencode('https://target.test/a'));

$total = $passed + $failed;
echo "\n--------------------------\n";
echo "TOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);