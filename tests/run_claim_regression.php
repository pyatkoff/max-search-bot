<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ClaimRepository.php';
require_once __DIR__ . '/../services/ClaimCodeGenerator.php';
require_once __DIR__ . '/../services/LeadPayloadService.php';
require_once __DIR__ . '/../maxsearchbaseclass.php';

class StandaloneYclidRegressionApi extends MaxSearchBase
{
    public static array $trafficMeta = [];

    public static function getTrafficMeta($chatId): array
    {
        return self::$trafficMeta;
    }
}

$passed = 0;
$failed = 0;
function ccheck(string $name, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; $passed++; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    $failed++;
}

echo "MAX Search claim regression suite\n=================================\n\n";

$generated = ClaimCodeGenerator::generate();
ccheck('native claim code keeps legacy length', strlen($generated), 10);
ccheck('native claim code keeps legacy alphabet', preg_match('/^[abcdefghijklnmopqrstuvwxyz0-9]{10}$/', $generated) === 1, true);
$threw = false;
try { ClaimCodeGenerator::generate(0); } catch (InvalidArgumentException $e) { $threw = true; }
ccheck('native claim code rejects non-positive length', $threw, true);
$maxSearchSource = (string)file_get_contents(__DIR__ . '/../maxsearchclass.php');
ccheck('claim creation delegates to native generator', strpos($maxSearchSource, 'ClaimCodeGenerator::generate(10)') !== false, true);
ccheck('claim creation no longer calls Bitrix randString', strpos($maxSearchSource, 'randString(') === false, true);

StandaloneYclidRegressionApi::$trafficMeta = ['yclid'=>' 123456789 '];
ccheck('standalone latest yclid uses persisted traffic attribution without Bitrix', StandaloneYclidRegressionApi::getLatestYclid(-123), '123456789');
StandaloneYclidRegressionApi::$trafficMeta = [];
ccheck('standalone latest yclid fails open when attribution is absent', StandaloneYclidRegressionApi::getLatestYclid(-123), '');

$status = [
    'city'=>65,'country'=>66,'adults'=>67,'children'=>68,'child_ages'=>69,
    'stars'=>70,'meal'=>71,'nights'=>72,'date'=>73,
];
$saved = [
    'NAME'=>'Test User',65=>17,66=>4,67=>2,68=>0,70=>4,71=>7,72=>'9-11',73=>'28.08.2026',
];
$row = ClaimRepository::buildClaimData(-123, $saved, $status, 'abc123');
ccheck('claim keeps chat id', $row['UF_CHAT_ID'] ?? null, -123);
ccheck('claim maps city', $row['UF_CITY'] ?? null, 17);
ccheck('claim maps country', $row['UF_COUNTRY'] ?? null, 4);
ccheck('claim keeps children zero', $row['UF_CHILD'] ?? null, 0);
ccheck('claim maps nights', $row['UF_NIGHTS'] ?? null, '9-11');
ccheck('claim maps code', $row['UF_CODE'] ?? null, 'abc123');

$claim = ['UF_ADULTS'=>2,'UF_CHILD'=>2,'UF_AGE'=>'5, 8','UF_MEAL'=>7];
ccheck('people string with children', LeadPayloadService::peopleString($claim), 'Взрослых: 2; Детей: 2(5, 8)');
ccheck('meal string lowercases mapped meal', LeadPayloadService::mealString($claim, ['7'=>'ВСЕ ВКЛЮЧЕНО']), 'все включено');
ccheck('meal 999 becomes any', LeadPayloadService::mealString(['UF_MEAL'=>999], ['999'=>'ЛЮБОЕ']), 'любое');

$data = [
    'name'=>'Test User','phone'=>'+79990000000','clean_phone'=>'79990000000',
    'created_at'=>'23.08.2026 12:00:00','from'=>'Калининград','country'=>'Турция',
    'people'=>'Взрослых: 2','stars'=>4,'meal'=>'все включено','dates'=>'25.08.2026 - 31.08.2026',
    'nights'=>'9-11','status'=>9,'source'=>36,'is_anytour_online'=>1,
];
$props = LeadPayloadService::properties($data);
ccheck('lead source preserved', $props['SOURCE'] ?? null, 36);
ccheck('lead status preserved', $props['STATUS'] ?? null, 9);
ccheck('lead phone normalized input preserved', $props['PHONE'] ?? null, '79990000000');
ccheck('lead comments contain departure', strpos($props['COMMENTS'] ?? '', 'Город вылета: Калининград') !== false, true);

$element = LeadPayloadService::iblockElement([
    'iblock_id'=>4,'section_id'=>26,'properties'=>$props,'created_at'=>$data['created_at'],
]);
ccheck('iblock id preserved', $element['IBLOCK_ID'] ?? null, 4);
ccheck('section id preserved', $element['IBLOCK_SECTION_ID'] ?? null, 26);
ccheck('element active', $element['ACTIVE'] ?? null, 'Y');

$total = $passed + $failed;
echo "\n---------------------------------\nTOTAL {$total} | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
