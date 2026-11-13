<?php
declare(strict_types=1);
require_once __DIR__.'/../app/integrations/anex-search-mapping-registry.php';
require_once __DIR__.'/../v2/data/top-hotels-v1.php';
function top500_namespace_check(bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException($why);
}
function top500_fixture_row(int $anex, int $tv, array $extra=[]): array {
    return array_replace(['anex_hotel_id'=>$anex,'catalog_hotel_id'=>$tv,
        'existing_catalog_hotel_id'=>$tv,'enabled'=>1,'scope'=>'preview',
        'approval_policy'=>'owner_exact_and_strong_20260908','match_class'=>'exact'],$extra);
}
$source=v2_top500_anex_ids();
top500_namespace_check(count($source)===492 && count(array_unique($source))===492,'owner ANEX roster remains intact');
top500_namespace_check(array_slice($source,0,3)===[22879,8520,8208],'owner order remains intact');
$unavailable=v2_top500_resolution();
top500_namespace_check(v2_priority_hotel_ids()===[] && $unavailable['unresolved_anex_ids']===$source
    && $unavailable['state']==='registry_unavailable','no implicit ANEX-to-TV fallback');
// Synthetic identities only: these numbers are not a production mapping receipt.
$rows=[
    top500_fixture_row(22879,900001), top500_fixture_row(8520,900002),
    top500_fixture_row(8208,900001), top500_fixture_row(12575,900004),
    top500_fixture_row(39872,900005), top500_fixture_row(35319,900006),
    top500_fixture_row(27611,900007,['approval_policy'=>'unapproved']),
    top500_fixture_row(8554,900008,['match_class'=>'review']),
    top500_fixture_row(8602,900009,['enabled'=>0]),
    top500_fixture_row(39873,39873),
];
$decisions=[
    ['anex_hotel_id'=>8520,'decision_status'=>'accepted','catalog_hotel_id'=>900003,'existing_catalog_hotel_id'=>900003],
    ['anex_hotel_id'=>12575,'decision_status'=>'rejected','catalog_hotel_id'=>900004,'existing_catalog_hotel_id'=>900004],
    ['anex_hotel_id'=>39872,'decision_status'=>'accepted','catalog_hotel_id'=>900005,'existing_catalog_hotel_id'=>null],
];
$exclusions=[['anex_hotel_id'=>35319,'catalog_hotel_id'=>900006]];
$registry=AnyTourAnexSearchMappingRegistry::fromRows($rows,$decisions,$exclusions);
$r=v2_top500_resolution($registry);
top500_namespace_check($r['tourvisor_ids']===[900001,900003,39873],'accepted targets and first owner rank; many-to-one deduplicates');
top500_namespace_check($r['mapped_source_count']===4 && $r['target_count']===3 && count($r['unresolved_anex_ids'])===488,
    'source coverage and unique TV count are distinct');
top500_namespace_check(!in_array(8208,$r['tourvisor_ids'],true) && !in_array(22879,$r['tourvisor_ids'],true),
    'same-number Tourvisor hotel is not evidence');
top500_namespace_check(in_array(39873,$r['tourvisor_ids'],true),'equal numbers require an explicit accepted pair');
top500_namespace_check(in_array(12575,$r['unresolved_anex_ids'],true) && in_array(35319,$r['unresolved_anex_ids'],true),
    'manual rejection and pair exclusion remain authoritative');
top500_namespace_check($registry->resolve('anex_online',22879,'production')===null,'production resolver scope not enabled');
top500_namespace_check(v2_priority_hotel_ids($registry)===$r['tourvisor_ids'],'collector consumes resolved TV IDs');

// Exercise the real PDO reader, including existing target checks and closed failure.
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE catalog_hotels (id INTEGER PRIMARY KEY)');
$db->exec('CREATE TABLE anex_hotel_search_mappings (anex_hotel_id INTEGER,catalog_hotel_id INTEGER,match_class TEXT,approval_policy TEXT,enabled INTEGER,scope TEXT)');
$db->exec('CREATE TABLE anex_hotel_decisions (anex_hotel_id INTEGER,decision_status TEXT,catalog_hotel_id INTEGER)');
$db->exec('CREATE TABLE anex_review_pair_exclusions (anex_hotel_id INTEGER,catalog_hotel_id INTEGER)');
foreach([8208,22879,900001,900002,900003,900004,900006,900007,900008,900009,39873] as $id) {
    $db->prepare('INSERT INTO catalog_hotels VALUES (?)')->execute([$id]);
}
foreach($rows as $row) $db->prepare('INSERT INTO anex_hotel_search_mappings VALUES (?,?,?,?,?,?)')->execute([
    $row['anex_hotel_id'],$row['catalog_hotel_id'],$row['match_class'],$row['approval_policy'],$row['enabled'],$row['scope']]);
foreach($decisions as $d) $db->prepare('INSERT INTO anex_hotel_decisions VALUES (?,?,?)')->execute([
    $d['anex_hotel_id'],$d['decision_status'],$d['catalog_hotel_id']]);
$db->exec('INSERT INTO anex_review_pair_exclusions VALUES (35319,900006)');
$read=v2_top500_registry($db);
top500_namespace_check($read instanceof AnyTourAnexSearchMappingRegistry && v2_top500_resolution($read)===$r,
    'actual accepted/manual/exclusion PDO projection agrees');
$db->exec('DROP TABLE anex_hotel_decisions');
top500_namespace_check(v2_top500_registry($db)===null,'required registry read failure stays closed');

if(is_file(__DIR__.'/../v2/data/published-hotels-monitor-v1.php')) {
    require_once __DIR__.'/../v2/data/published-hotels-monitor-v1.php';
    require_once __DIR__.'/../v2/data/top500-monthly-plan-v1.php';
    $native=v2_collection_priority_hotel_ids();
    top500_namespace_check(count($native)===182 && !in_array(8208,$native,true),'typed published monitor survives unavailable ANEX mapping');
    $hotels=array_map(static fn(int $id):array=>['id'=>$id,'country_id'=>4,'country_name'=>'Fixture','is_active'=>1],$native);
    $routes=[['departure_id'=>1,'country_id'=>4,'is_active'=>1]];
    $empty=v2_top500_monthly_plan([],$hotels,$routes,'2026-11-06',2,1,true);
    $published=v2_append_published_hotel_monthly_plan($empty,$hotels,$routes,'2026-11-06',2,1);
    top500_namespace_check($published['hotel_count']===182 && $empty['targets']===[],'unresolved TOP500 does not block published cohorts');
    $overlap=AnyTourAnexSearchMappingRegistry::fromRows([top500_fixture_row(22879,$native[0])]);
    $topOnly=array_values(array_diff(v2_priority_hotel_ids($overlap),$native));
    $again=v2_append_published_hotel_monthly_plan(v2_top500_monthly_plan($topOnly,$hotels,$routes,'2026-11-06',2,1,true),
        $hotels,$routes,'2026-11-06',2,1);
    top500_namespace_check($again['targets']===$published['targets'],'new mapping cannot regroup existing published targets');
}
echo "TOP500_ANEX_NAMESPACE_OK source=492 synthetic_mapping_checks=passed\n";
