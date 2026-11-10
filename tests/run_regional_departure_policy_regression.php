<?php
declare(strict_types=1);
require_once __DIR__.'/../services/RegionalDeparturePolicy.php';
require_once __DIR__.'/../services/YandexRegionCatalog.php';
require_once __DIR__.'/../services/DepartureSuggestionResolver.php';

$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{$checks++;if(!$ok)throw new RuntimeException($message);};
foreach (['969'=>['Санкт-Петербург','С.Петербург'],'10758'=>['Москва'],'10884'=>['Санкт-Петербург','С.Петербург'],'155820'=>['Брянск','Москва']] as $id=>$expected) {
    $region=YandexRegionCatalog::findByYandexId((string)$id);$before=$region;
    $check($region!==null && RegionalDeparturePolicy::departureNames($region)===$expected,'Actual scoped mapping: '.$id);
    $check($region===$before,'Suggesting a departure does not mutate the region or choose a trip city');
}
$check(RegionalDeparturePolicy::departureNames(YandexRegionCatalog::findByYandexId('4'))===['Белгород','Москва'],'Inactive city keeps its own name first and only an existing fallback');
$check(RegionalDeparturePolicy::departureNames(YandexRegionCatalog::findByYandexId('225'))===[],'Explicit empty country policy does not inherit an airport');
$check(RegionalDeparturePolicy::departureNames(['id'=>'99999999999999999998','name'=>'Химки','hierarchy'=>['Agency','Москва и область','Химки']])===null,'Agency or unanchored roots cannot borrow a city by its leaf name');
$check(RegionalDeparturePolicy::departureNames(['id'=>'99999999999999999998','name'=>'Белгород','hierarchy'=>['Foreign province','Белгород']])===null,'A repeated city name alone is not a geographic policy');
$region=YandexRegionCatalog::findByYandexId('969');$region['departure_names']=RegionalDeparturePolicy::departureNames($region);
$directory=[['id'=>1,'name'=>'Москва'],['id'=>5,'name'=>'С.Петербург']];
$check(DepartureSuggestionResolver::resolve($region,$directory,['969'=>[]])===[],'Explicit empty project override disables the scoped suggestion');
$check(array_column(DepartureSuggestionResolver::resolve($region,$directory,['969'=>['Москва']])['candidates'],'id')===['1'],'Project override remains higher than a shared regional rule');
$check(DepartureSuggestionResolver::resolve($region,[['id'=>1,'name'=>'Москва']])===[],'Inactive or absent departure records are not guessed from the region');

// Use only names from the captured active directory; these fixture IDs are not
// provider IDs and must never be persisted. A proposal alone changes no choice.
$reviewedRules=json_decode(file_get_contents(__DIR__.'/../resources/regional_departure_rules.json'),true,16,JSON_THROW_ON_ERROR);
$activeDirectory=[];
foreach($reviewedRules['active_catalog']['departure_names'] as$index=>$name)$activeDirectory[]=['id'=>1000+$index,'name'=>$name];
$newExamples=[
    '7'=>['Ярославль','Москва'],'10'=>['Москва'],'11'=>['Москва'],'12'=>['Москва'],
    '13'=>['Москва'],'14'=>['Москва'],'15'=>['Москва'],'192'=>['Москва'],
    '24'=>['С.Петербург'],'41'=>['Казань','Чебоксары'],'53'=>['Тюмень','Екатеринбург'],
    '1092'=>['Владикавказ','Грозный'],'1104'=>['Мин.Воды','Ставрополь'],
    '20627'=>['Казань','Чебоксары'],'11157'=>['Тюмень','Екатеринбург'],
    '11016'=>['Мин.Воды','Ставрополь'],'20179'=>['Владикавказ','Грозный'],
    '11005'=>['Краснодар'],'20177'=>['Волгоград'],'11394'=>['Хабаровск'],'10243'=>['Хабаровск'],
    '79'=>['Хабаровск'],'11404'=>['Хабаровск'],'11458'=>['Хабаровск','Владивосток'],
    '10251'=>['Хабаровск','Владивосток'],'10903'=>['Архангельск'],'10176'=>['Архангельск'],
    '11331'=>['Новосибирск'],'11320'=>['Новосибирск'],
];
foreach($newExamples as$id=>$expected){
    $region=YandexRegionCatalog::findByYandexId((string)$id);$before=$region;
    $names=RegionalDeparturePolicy::departureNames($region);
    $proposal=DepartureSuggestionResolver::resolve(array_merge($region,['departure_names'=>$names]),$activeDirectory);
    $check(array_column($proposal['candidates']??[],'name')===$expected,'Reviewed hub candidates: '.$id);
    $check($region===$before,'Hub proposals do not mutate a region or save a departure: '.$id);
}
$check(RegionalDeparturePolicy::departureNames(YandexRegionCatalog::findByYandexId('10'))===['Орел','Орёл','Москва'],'Both retained Orel spellings remain ordered before the new hub');
foreach(['3','17','26','40','52','59','73','300012','400003','207928','26990']as$id){
    $check(RegionalDeparturePolicy::departureNames(YandexRegionCatalog::findByYandexId($id))===null,'Broad, agency, foreign and unreviewed locations keep the ordinary question: '.$id);
}
$check(RegionalDeparturePolicy::departureNames(YandexRegionCatalog::findByYandexId('166243'))===['Калуга'],'Sevastopol in a Kaluga village keeps its actual-source province policy');
$region=YandexRegionCatalog::findByYandexId('11157');$region['departure_names']=RegionalDeparturePolicy::departureNames($region);
$check(DepartureSuggestionResolver::resolve($region,$activeDirectory,['11157'=>[]])===[],'A project empty override also disables new cross-region hubs');
$check(array_column(DepartureSuggestionResolver::resolve($region,$activeDirectory,['11157'=>['Москва']])['candidates'],'name')===['Москва'],'A project choice outranks new regional hubs');
$check(DepartureSuggestionResolver::resolve($region,[['id'=>1,'name'=>'Москва']])===[],'Unavailable hubs never become a guessed unrelated active city');
$check(DepartureSuggestionResolver::resolve($region,[['id'=>1,'name'=>'Тюмень'],['id'=>2,'name'=>'Тюмень']])===[],'Ambiguous active hub names do not choose a directory ID');
$check(RegionalDeparturePolicy::departureNames(['id'=>'99999999999999999998','name'=>'Назрань','hierarchy'=>['Foreign province','Назрань']])===null,'A rootless retained seed policy never leaks to a same-name location');

$directories=[];$counter=0;$source=file_get_contents(__DIR__.'/../services/RegionalDeparturePolicy.php');
$fixture=static function(array $rules,string $manifest,string $padding='')use(&$directories,&$counter,$source):string{
    $dir=sys_get_temp_dir().'/departure-policy-'.bin2hex(random_bytes(8));mkdir($dir.'/services',0700,true);mkdir($dir.'/resources',0700);
    $directories[]=$dir;$class='RegionalDeparturePolicyFixture'.(++$counter);
    file_put_contents($dir.'/services/RegionalDeparturePolicy.php',str_replace('final class RegionalDeparturePolicy','final class '.$class,$source));
    file_put_contents($dir.'/resources/yandex_regions.json',$manifest);
    file_put_contents($dir.'/resources/regional_departure_rules.json',json_encode($rules,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).$padding);
    require $dir.'/services/RegionalDeparturePolicy.php';
    return $class;
};
$manifest="synthetic bounded source manifest\n";
$rules=['version'=>1,'catalog_manifest_sha256'=>hash('sha256',$manifest),
    'exact'=>['7'=>['anchor_hierarchy'=>['Country','Province','City'],'departure_names'=>['Exact city']],
              '8'=>['anchor_hierarchy'=>['Country','Province','City'],'departure_names'=>[]]],
    'scopes'=>[
        ['kind'=>'province','anchor_id'=>'10','anchor_hierarchy'=>['Country','Province'],'prefix'=>['Country','Province'],'departure_names'=>['Province city']],
        ['kind'=>'city','anchor_id'=>'11','anchor_hierarchy'=>['Country','Province','City'],'prefix'=>['Country','Province','City'],'departure_names'=>['Local city']],
    ]];
$probe=['id'=>'99','hierarchy'=>['Country','Province','City','Neighbourhood']];
try {
    $class=$fixture($rules,$manifest);
    $check($class::departureNames($probe)===['Local city'],'The most specific scope wins without reading any anchor bucket files');
    $check($class::departureNames(['id'=>'7','hierarchy'=>$probe['hierarchy']])===['Exact city'],'An exact global ID wins over every scope');
    $check($class::departureNames(['id'=>'8','hierarchy'=>$probe['hierarchy']])===[],'An exact global empty policy blocks every scope');
    $check($class::departureNames(['id'=>'99','hierarchy'=>['Country','Province extra','City']])===null,'Prefix matching is by complete literal components');
    $check($class::departureNames(['id'=>'99','hierarchy'=>['Country']])===null,'A country root alone never chooses a departure');
    $class=$fixture($rules,$manifest.'changed');
    $check($class::departureNames($probe)===null,'Changed source manifest disables stale rules');
    $invalid=$rules;$invalid['scopes'][]=array_merge($rules['scopes'][1],['departure_names'=>['Conflicting city']]);
    $class=$fixture($invalid,$manifest);
    $check($class::departureNames($probe)===null,'Conflicting policies at equal specificity fail closed');
    $invalid=$rules;$invalid['scopes'][0]['prefix']=['Country'];
    $class=$fixture($invalid,$manifest);
    $check($class::departureNames($probe)===null,'A malformed broad scope is rejected');
    $invalid=$rules;$invalid['scopes'][1]['anchor_hierarchy']=['Country','Different province','City'];
    $class=$fixture($invalid,$manifest);
    $check($class::departureNames($probe)===null,'A scope cannot exceed its declared exact anchor path');
    $invalid=$rules;$invalid['exact']['']=['anchor_hierarchy'=>[],'departure_names'=>['Invalid']];
    $class=$fixture($invalid,$manifest);
    $check($class::departureNames($probe)===null,'Empty global IDs cannot enter the policy table');
    $class=$fixture($rules,$manifest,str_repeat(' ',262145));
    $check($class::departureNames($probe)===null,'Policy files are bounded at 256 KiB');
    $largeManifest=str_repeat('x',65537);$invalid=$rules;$invalid['catalog_manifest_sha256']=hash('sha256',$largeManifest);
    $class=$fixture($invalid,$largeManifest);
    $check($class::departureNames($probe)===null,'Manifest hashing is bounded at 64 KiB');
} finally {
    foreach($directories as$dir){$items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($items as$item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($dir);}
}
echo "$checks regional departure policy checks passed\n";
