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

echo "\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
