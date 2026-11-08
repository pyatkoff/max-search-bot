<?php

declare(strict_types=1);

class CSiteParams
{
    public static $isAnytourOnline = 'legacy-marker';
}

require_once dirname(__DIR__) . '/services/ProjectMarkerService.php';

$failures = [];

if (ProjectMarkerService::anytourOnline() !== 'legacy-marker') {
    $failures[] = 'legacy CSiteParams fallback was not preserved';
}

define('MAX_SEARCH_IS_ANYTOUR_ONLINE', 'standalone-marker');
if (ProjectMarkerService::anytourOnline() !== 'standalone-marker') {
    $failures[] = 'standalone config must override legacy global marker';
}

$maxSearchSource = file_get_contents(dirname(__DIR__) . '/maxsearchclass.php');
if (!is_string($maxSearchSource) || strpos($maxSearchSource, 'ProjectMarkerService::anytourOnline()') === false) {
    $failures[] = 'MaxSearchApi must resolve the marker through ProjectMarkerService';
}
if (is_string($maxSearchSource) && strpos($maxSearchSource, 'CSiteParams::$isAnytourOnline') !== false) {
    $failures[] = 'MaxSearchApi must not directly depend on CSiteParams';
}

$receiverSource = file_get_contents(dirname(__DIR__) . '/lead-receiver.php');
if (!is_string($receiverSource) || strpos($receiverSource, "['IS_ANYTOUR_ONLINE']") === false) {
    $failures[] = 'legacy lead receiver must preserve IS_ANYTOUR_ONLINE';
}
if (!is_string($receiverSource) || strpos($receiverSource, 'ProjectMarkerService::anytourOnline()') === false) {
    $failures[] = 'legacy lead receiver must resolve its authoritative project marker through ProjectMarkerService';
}
if (is_string($receiverSource) && strpos($receiverSource, '$currentMarker === null || $currentMarker ===') === false) {
    $failures[] = 'receiver must only enrich a missing/empty project marker';
}

$serviceSource = file_get_contents(dirname(__DIR__) . '/services/ProjectMarkerService.php');
if (!is_string($serviceSource) || strpos($serviceSource, '$configured !== null && $configured !==') === false) {
    $failures[] = 'empty standalone marker config must fall back to the legacy site marker';
}

if ($failures) {
    fwrite(STDERR, "PROJECT MARKER BOUNDARY REGRESSION FAILED\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PROJECT MARKER BOUNDARY REGRESSION PASSED\n";

// Explicit company settings run in this existing required project-boundary suite.
require_once dirname(__DIR__) . '/services/ProjectConfig.php';


$passed = 0;
$failed = 0;
function pcCheck(string $name, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        echo "PASS  {$name}\n";
    } else {
        $failed++;
        echo "FAIL  {$name}\n";
    }
}

function pcReject(string $name, callable $call, string $reason): void
{
    try {
        $call();
        pcCheck($name, 'accepted', $reason);
    } catch (InvalidArgumentException $error) {
        pcCheck($name, $error->getMessage(), $reason);
    } catch (Throwable $error) {
        pcCheck($name, get_class($error), 'InvalidArgumentException');
    }
}

ProjectConfig::resetForTests(null);
$legacy = ProjectConfig::all();
$tour = ProjectConfig::forProject('anytour', $legacy);
$repairValues = require dirname(__DIR__) . '/resources/project-examples/iphone-repair.php';
$repair = ProjectConfig::forProject('iphone_repair', $repairValues);

pcCheck('legacy project remains AnyTour', ProjectConfig::projectId(), 'anytour');
pcCheck('explicit AnyTour snapshot retains every setting', $tour->all(), $legacy);
pcCheck('repair identity is explicit', $repair->projectId(), 'iphone_repair');
pcCheck('repair has its own business profile', $repair->get('business.profile'), 'device_repair');
pcCheck('repair brand stays unconfigured', $repair->get('brand.name', 'fallback'), null);
pcCheck('repair does not inherit tour search', $repair->get('search.base_domain'), null);
pcCheck('repair does not inherit lead destination', $repair->get('leads'), null);
pcCheck('repair does not inherit AnyTour bot', $repair->get('messenger.miniapp_bot_url'), null);
pcCheck('repair does not inherit AnyTour state directory', $repair->get('state.v2_store_dir'), null);
pcCheck('repair does not inherit AnyTour analytics provider', $repair->get('analytics.provider'), null);
pcCheck('sequential project reads retain their own identities',
    [$tour->projectId(), $repair->projectId(), $tour->projectId(), ProjectConfig::projectId()],
    ['anytour', 'iphone_repair', 'anytour', 'anytour']);
pcCheck('creating another snapshot does not change legacy config', ProjectConfig::all(), $legacy);
pcCheck('legacy customer-facing search URL stays exact',
    ProjectConfig::searchUrl(['city'=>1, 'country'=>[4,5], 'empty'=>'', 'zero'=>0, 'missing'=>null]),
    'https://anytoour.ru/poisk-turov/?city=1&country%5B%5D=4&country%5B%5D=5');
pcCheck('legacy claim URL stays customer-facing',
    ProjectConfig::claimUrl('sample', '123'), 'https://anytoour.ru/poisk-turov/?claim=sample&yclid=123');

define('MAX_SEARCH_PUBLIC_BASE_URL', 'https://application.example.invalid/');
define('MAX_SEARCH_TRACKING_BASE_URL', 'https://tracking.example.invalid/');
pcCheck('legacy public-origin override stays supported', ProjectConfig::baseDomain(), 'https://application.example.invalid');
pcCheck('legacy tracking-origin override stays supported', ProjectConfig::trackingBaseDomain(), 'https://tracking.example.invalid');
pcCheck('another project cannot inherit process-wide public override', $repair->get('search.base_domain', 'unset'), 'unset');
pcCheck('customer search destination remains independent of app override',
    ProjectConfig::searchUrl(), 'https://anytoour.ru/poisk-turov/');

$repairValues['brand']['name'] = 'Changed outside';
pcCheck('changing input array cannot change the snapshot', $repair->get('brand.name'), null);
$returned = $repair->all();
$returned['id'] = 'anytour';
$returned['business']['profile'] = 'travel';
pcCheck('changing returned identity cannot change the snapshot', $repair->get('id'), 'iphone_repair');
pcCheck('changing returned nested array cannot change the snapshot', $repair->get('business.profile'), 'device_repair');
$branch = ['label'=>'Original'];
$referenced = ['id'=>'iphone_repair', 'first'=>&$branch, 'second'=>&$branch];
$frozen = ProjectConfig::forProject('iphone_repair', $referenced);
$branch['label'] = 'Changed through reference';
pcCheck('external PHP references are detached', $frozen->get('first.label'), 'Original');
pcCheck('duplicate referenced branches are detached', $frozen->get('second.label'), 'Original');
$nested = $frozen->get('first');
$nested['label'] = 'Changed returned branch';
pcCheck('nested read is isolated too', $frozen->get('first.label'), 'Original');

pcReject('wrong project config is rejected',
    fn()=>ProjectConfig::forProject('iphone_repair', $legacy), 'project_config_scope_mismatch');
pcReject('reverse scope substitution is rejected',
    fn()=>ProjectConfig::forProject('anytour', $repairValues), 'project_config_scope_mismatch');
pcReject('missing project identity is rejected',
    fn()=>ProjectConfig::forProject('iphone_repair', []), 'project_config_scope_mismatch');
pcReject('numeric identity is not coerced',
    fn()=>ProjectConfig::forProject('iphone_repair', ['id'=>123]), 'project_config_scope_mismatch');
foreach (['', '../anytour', 'AnyTour', ' iphone_repair', str_repeat('a', 41)] as $invalid) {
    pcReject('invalid project key is rejected',
        fn()=>ProjectConfig::forProject($invalid, ['id'=>$invalid]), 'project_config_invalid_key');
}
pcCheck('failed resolution cannot change legacy config', ProjectConfig::all(), $legacy);
pcCheck('failed resolution cannot change an existing snapshot', $repair->projectId(), 'iphone_repair');

$values = ['id'=>'iphone_repair', 'data'=>[
    'null'=>null, 'false'=>false, 'zero'=>0, 'decimal'=>1.25, 'empty'=>'',
    'list'=>['first'], 'scalar'=>'text', ''=>['leaf'=>'empty key'],
]];
$typed = ProjectConfig::forProject('iphone_repair', $values);
foreach (['null'=>null, 'false'=>false, 'zero'=>0, 'decimal'=>1.25, 'empty'=>''] as $key=>$value) {
    pcCheck('explicit '.$key.' remains a value', $typed->get('data.'.$key, 'default'), $value);
}
pcCheck('missing path uses the supplied default', $typed->get('data.absent', 'default'), 'default');
pcCheck('scalar cannot become an object path', $typed->get('data.scalar.child', 'default'), 'default');
pcCheck('numeric array path is supported', $typed->get('data.list.0'), 'first');
pcCheck('empty array key retains legacy path semantics', $typed->get('data..leaf'), 'empty key');

ProjectConfig::resetForTests($values);
foreach (['data.null', 'data.false', 'data.zero', 'data.empty', 'data.list.0', 'data..leaf', 'data.scalar.child'] as $path) {
    pcCheck('legacy and explicit getters agree: '.$path,
        ProjectConfig::get($path, 'default'), $typed->get($path, 'default'));
}
ProjectConfig::forProject('anytour', $legacy);
pcCheck('explicit construction never switches the legacy test/runtime scope', ProjectConfig::all(), $values);
ProjectConfig::resetForTests(null);
pcCheck('ordinary legacy reload still uses project.php', ProjectConfig::all(), $legacy);

pcReject('nested objects cannot remain mutable inside settings',
    fn()=>ProjectConfig::forProject('iphone_repair', ['id'=>'iphone_repair', 'value'=>new stdClass()]),
    'project_config_invalid_value');
pcReject('non-finite values are rejected',
    fn()=>ProjectConfig::forProject('iphone_repair', ['id'=>'iphone_repair', 'value'=>INF]),
    'project_config_invalid_value');
$stream = fopen('php://memory', 'r+');
try {
    pcReject('resources cannot be retained inside settings',
        fn()=>ProjectConfig::forProject('iphone_repair', ['id'=>'iphone_repair', 'value'=>$stream]),
        'project_config_invalid_value');
} finally {
    fclose($stream);
}
$cycle = ['id'=>'iphone_repair'];
$cycle['loop'] =& $cycle;
pcReject('cyclic reference fails within the depth bound',
    fn()=>ProjectConfig::forProject('iphone_repair', $cycle), 'project_config_too_deep');

require_once dirname(__DIR__) . '/services/RepairOfferDraft.php';
$appleValues = require dirname(__DIR__) . '/resources/project-examples/appleservice39.php';
$apple = ProjectConfig::forProject('appleservice39', $appleValues);
$offer = $apple->get('offer');
$draft = RepairOfferDraft::prepare($apple, $offer);
pcCheck('preview keeps explicit project and business scope', [$draft['project_key'], $draft['business_key']], ['appleservice39', 'appleservice39']);
pcCheck('pilot has a stable service identity', $draft['offer_id'], 'appleservice39:iphone-x:battery-replacement');
pcCheck('two exact source prices permit the pilot price', $draft['price_rub'], 3500);
pcCheck('draft caption includes model, service, price and business', $draft['publication']['caption'],
    "Замена аккумулятора iPhone X\n3 500 ₽\nAppleService39 · Калининград");
pcCheck('original warranty marks and both conflicting durations are preserved',
    [array_column($draft['source_rows'], 'warranty_raw'), array_column($draft['source_rows'], 'duration_raw')],
    [['✔', '✔'], ['25 мин.', '60 мин.']]);
pcCheck('offline draft has an image and a label with no live button URL',
    [$draft['publication']['image_asset'], $draft['publication']['button_text'], isset($draft['publication']['button_url'])],
    [$offer['image_asset'], 'Уточнить ремонт', false]);
pcCheck('the packaged image matches the draft version',
    hash_file('sha256', dirname(__DIR__) . '/resources/project-examples/assets/' . $draft['publication']['image_asset']),
    $draft['publication']['image_sha256']);

$reversed = $offer;
$reversed['source_rows'] = array_reverse($offer['source_rows']);
pcCheck('source ordering does not change the draft or its version', RepairOfferDraft::prepare($apple, $reversed), $draft);
$changed = $offer;
$changed['source_rows'][1]['price_raw'] = '800 руб.';
$disputed = RepairOfferDraft::prepare($apple, $changed);
pcCheck('price disagreement keeps identity and changes version',
    [$disputed['offer_id'], $disputed['offer_version'] === $draft['offer_version'], $disputed['price_rub']],
    [$draft['offer_id'], false, null]);
pcCheck('price disagreement produces a quote on request', str_contains($disputed['publication']['caption'], 'Стоимость уточняйте'), true);

foreach (['от 3 500 ₽' => ['from', 3500], 'уточняйте' => ['on_request', null], 'Бесплатно' => ['free', 0],
    '0 руб.' => ['free', 0], 'неизвестно' => ['unrecognized', null]] as $raw => [$kind, $amount]) {
    $changed['source_rows'][1]['price_raw'] = $raw;
    $review = RepairOfferDraft::prepare($apple, $changed);
    $row = $review['source_rows'][1];
    pcCheck('raw price stays distinct: ' . $raw, [$row['price_raw'], $row['price_kind'], $row['price_amount_rub'], $review['price_rub']],
        [$raw, $kind, $amount, null]);
}
$changed = $offer;
$changed['price_review_required'] = true;
pcCheck('manual review suppresses even a corroborated price', RepairOfferDraft::prepare($apple, $changed)['price_rub'], null);
$changed = $offer;
$changed['model_scope'] = 'series';
pcCheck('an exact price is not promoted to a series', RepairOfferDraft::prepare($apple, $changed)['price_rub'], null);
$changed = $offer;
$changed['source_rows'] = [$offer['source_rows'][0]];
pcCheck('one price page is insufficient', RepairOfferDraft::prepare($apple, $changed)['price_rub'], null);
$changed['source_rows'][] = $offer['source_rows'][0];
$changed['source_rows'][1]['url'] = rtrim($changed['source_rows'][1]['url'], '/');
pcReject('a repeated source cannot corroborate itself', fn()=>RepairOfferDraft::prepare($apple, $changed), 'repair_offer_duplicate_source');
$changed = $offer;
$changed['source_rows'][0]['url'] = 'https://servise39.ru/example/';
pcReject('a second domain is not silently accepted', fn()=>RepairOfferDraft::prepare($apple, $changed), 'repair_offer_invalid_source_url');
$changed = $offer;
$changed['image_asset'] = '../private.png';
pcReject('a preview asset cannot select an arbitrary path', fn()=>RepairOfferDraft::prepare($apple, $changed), 'repair_offer_invalid_image_asset');
$changed = $offer;
$changed['operation'] = 'front-glass-replacement';
$changed['price_review_required'] = true;
$glass = RepairOfferDraft::prepare($apple, $changed);
$changed['operation'] = 'display-replacement';
$display = RepairOfferDraft::prepare($apple, $changed);
pcCheck('glass and display have distinct identities', [$glass['offer_id'], $display['offer_id']],
    ['appleservice39:iphone-x:front-glass-replacement', 'appleservice39:iphone-x:display-replacement']);
pcReject('AnyTour cannot consume the repair draft', fn()=>RepairOfferDraft::prepare($tour, $offer), 'repair_offer_business_mismatch');
pcReject('the generic repair template does not resolve the AppleService39 key',
    fn()=>RepairOfferDraft::prepare($repair, $offer), 'repair_offer_business_mismatch');
pcCheck('repair preview leaves AnyTour config intact', ProjectConfig::all(), $legacy);

$catalog = ['schema_version'=>1,'business_key'=>'appleservice39','entries'=>[]];
foreach ($offer['source_rows'] as $index => $row) {
    $catalog['entries'][] = [
        'id'=>$offer['catalog_entry_ids'][$index], 'business_key'=>'appleservice39', 'operation_key'=>'battery_replacement',
        'model_scope'=>['kind'=>'model','label'=>'iPhone X','model_keys'=>['iphone_x']], 'source_url'=>$row['url'],
        'source_table'=>1,'source_row'=>$index+1,'source_cells'=>[$row['label_raw']], 'review_flags'=>['duration_conflict'],
        'price'=>['currency'=>'RUB','raw'=>$row['price_raw']], 'warranty'=>['raw'=>$row['warranty_raw']], 'duration_raw'=>$row['duration_raw'],
    ];
}
$fromCatalog = RepairOfferDraft::prepare($apple, RepairOfferDraft::catalogItem($catalog, $offer));
pcCheck('archive adapter keeps the two pinned source row references and exact price',
    [$fromCatalog['price_rub'], array_column(array_column($fromCatalog['source_rows'], 'catalog_ref'), 'entry_id')],
    [3500, $offer['catalog_entry_ids']]);
$changedCatalog = $catalog;
$changedCatalog['entries'][0]['review_flags'][] = 'possible_price_scope_conflict';
pcCheck('archive price-review flag suppresses a matching exact price',
    RepairOfferDraft::prepare($apple, RepairOfferDraft::catalogItem($changedCatalog, $offer))['price_rub'], null);
$changedCatalog['entries'][0]['operation_key'] = 'diagnostics';
pcReject('archive row ID does not bypass exact service identity',
    fn()=>RepairOfferDraft::catalogItem($changedCatalog, $offer), 'repair_offer_catalog_row_mismatch');
$changedCatalog = $catalog;
$changedCatalog['entries'][0]['model_scope']['model_keys'] = ['iphone_xs'];
pcReject('archive row ID does not bypass exact model identity',
    fn()=>RepairOfferDraft::catalogItem($changedCatalog, $offer), 'repair_offer_catalog_row_mismatch');
$changedCatalog = $catalog;
array_pop($changedCatalog['entries']);
pcReject('a missing pinned row cannot silently fall back to another one',
    fn()=>RepairOfferDraft::catalogItem($changedCatalog, $offer), 'repair_offer_catalog_row_missing');

echo "\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
