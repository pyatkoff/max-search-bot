<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ProjectConfig.php';
require_once __DIR__ . '/../services/TourResultsService.php';
require_once __DIR__ . '/../services/ChannelOfferService.php';

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
        public static function getLastClaimForChat($chatId): array {
            return ['UF_CITY'=>1,'UF_COUNTRY'=>4,'UF_ADULTS'=>3,'UF_CHILD'=>2,'UF_AGE'=>'5, 8','UF_STARS'=>4,'UF_MEAL'=>7,'UF_NIGHTS'=>'9-11','UF_DATE_DEPART'=>'15.09.2026','UF_CODE'=>'abc'];
        }
        public static function getLatestYclid($chatId): string { return '777'; }
    }
}

ProjectConfig::resetForTests(['id'=>'test','search'=>['base_domain'=>'https://public-search.test','search_path'=>'/poisk-turov/','tracking_base_domain'=>'https://tracker.test','open_tours_path'=>'/track/tours.php']]);
$model = TourResultsService::build(-123, 'Pavel');
$canonical = 'https://public-search.test/poisk-turov/?from=1&country=4&dateFrom=2026-09-15&dateTo=2026-09-15&daysFrom=9&daysTill=11&count_people=3&child_count=2&child_age%5B%5D=5&child_age%5B%5D=8&stars=4&food=7&yclid=777';
trCheck('claim url canonicalized with full collected search fields',$model['claim_url'],$canonical);
trCheck('site button label',$model['buttons'][0][0]['text'],'🔥 Посмотреть на сайте');
trCheck('manager button label',$model['buttons'][1][0]['text'],'👩‍💼 Подобрать тур с менеджером');
trCheck('manager callback',$model['buttons'][1][0]['callback_data'],'manager_after_tours');
trCheck('edit callback',$model['buttons'][2][0]['callback_data'],'edit_params');
trCheck('final results do not duplicate channel offer',count($model['buttons']),3);
trCheck('tour tracking keeps canonical target',$model['buttons'][0][0]['url'],'https://tracker.test/track/tours.php?chat=-123&url='.rawurlencode($canonical));
trCheck('final message wording',$model['text'],"🔥 <b>Подходящие туры готовы</b>\n\nМожно посмотреть варианты самостоятельно или продолжить подбор с менеджером.");

ProjectConfig::resetForTests(['messenger'=>['channel_offer'=>[
    'telegram_url'=>'https://t.me/Any_tour_bot?startapp={yclid}',
    'max_url'=>'https://max.ru/id9704048781_2_bot?startapp={yclid}_region_{region_id}',
]]]);
$unknown=ChannelOfferService::model(['yclid'=>'123456','region_id'=>'7','entry_channel'=>'']);
trCheck('unknown source sees both channels',count($unknown['buttons']),2);
trCheck('MAX offer preserves yclid and region',$unknown['buttons'][0][0]['url'],'https://max.ru/id9704048781_2_bot?startapp=123456_region_7');
trCheck('Telegram offer preserves yclid',$unknown['buttons'][1][0]['url'],'https://t.me/Any_tour_bot?startapp=123456');
$maxTransport=ChannelOfferService::model(['yclid'=>'123456','region_id'=>'7','entry_channel'=>'max_paid_yandex']);
trCheck('MAX transport alone does not suppress promo',count($maxTransport['buttons']),2);
$tgTransport=ChannelOfferService::model(['yclid'=>'123456','region_id'=>'7','entry_channel'=>'telegram_paid_yandex']);
trCheck('Telegram transport alone does not suppress promo',count($tgTransport['buttons']),2);
$suppressed=ChannelOfferService::model(['yclid'=>'123456','region_id'=>'7','entry_channel'=>'max_anytour_msk'],'',true);
trCheck('explicit source policy suppresses all channel buttons',count($suppressed['buttons']),0);
trCheck('suppressed model records source key',$suppressed['source_key'],'max_anytour_msk');
trCheck('suppressed model records policy',$suppressed['suppressed'],true);
trCheck('offer copy',$unknown['text'],'А пока можете подписаться на наш канал — там публикуем горящие туры и интересные снижения цен 🔥');

ProjectConfig::resetForTests(['search'=>['base_domain'=>'https://public-search.test','search_path'=>'/poisk-turov/']]);
$savedUrl = ProjectConfig::searchUrlFromSavedData([10=>2,11=>8,12=>'2026-10-03',13=>'7-9',14=>2,15=>1,16=>'6',17=>5,18=>3],[
    'city'=>10,'country'=>11,'date'=>12,'nights'=>13,'adults'=>14,'children'=>15,'child_ages'=>16,'stars'=>17,'meal'=>18,
], 'yclid-test');
trCheck('saved dialogue data preserves full supported search context',$savedUrl,'https://public-search.test/poisk-turov/?from=2&country=8&dateFrom=2026-10-03&dateTo=2026-10-03&daysFrom=7&daysTill=9&count_people=2&child_count=1&child_age%5B%5D=6&stars=5&food=3&yclid=yclid-test');

$total=$passed+$failed;echo "\n--------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";exit($failed>0?1:0);
