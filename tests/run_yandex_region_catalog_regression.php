<?php
declare(strict_types=1);
require_once __DIR__.'/../services/YandexRegionCatalog.php';

$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
foreach (['', '0', '000', '-2', '1.0', '2e3', '{region_id}', '213 OR 1=1', str_repeat('1', 21), true, [], 2.5] as $invalid) {
    $check(YandexRegionCatalog::normalizeId($invalid) === '', 'Invalid region must remain unresolved');
}
$check(YandexRegionCatalog::normalizeId(' 000213 ') === '213', 'Canonical advertising ID');
$check(YandexRegionCatalog::normalizeId('18446744073709551615') === '18446744073709551615', 'No lossy integer coercion');
$check(YandexRegionCatalog::normalizeId('999999') === '999999', 'Unknown numeric geography remains recordable');
$check(YandexRegionCatalog::findByYandexId('99999999999999999999') === null, 'Unknown geography never invents a departure');
$spb = YandexRegionCatalog::findByYandexId('2');
$check($spb !== null && $spb['id'] === '2' && $spb['name'] === 'Санкт-Петербург', 'External ID lookup');
$check(in_array('С.Петербург', $spb['departure_names'], true), 'Known catalogue spelling supported');
$check(YandexRegionCatalog::findByYandexId('225')['departure_names'] === [], 'Country must not imply an airport');
$catalog = json_decode(file_get_contents(__DIR__.'/../resources/yandex_regions.json'), true, 16, JSON_THROW_ON_ERROR);
$publishedCount = 0;
$publishedBuckets = ($catalog['version'] ?? null) === 2 ? array_keys($catalog['buckets']) : [null];
foreach ($publishedBuckets as $suffix) {
    $rows = $suffix === null ? $catalog['regions']
        : json_decode(file_get_contents(__DIR__.'/../resources/yandex_regions/'.str_pad((string)$suffix,2,'0',STR_PAD_LEFT).'.json'),true,16,JSON_THROW_ON_ERROR)['regions'];
    foreach ($rows as $id => $row) {
        $resolved = YandexRegionCatalog::findByYandexId((string)$id);
        $check($resolved !== null && $resolved['id'] === (string)$id, 'Every published region resolves by its Yandex ID');
        $check($resolved['name'] === $row['name'], 'Names remain faithful to the published source');
        $check($resolved['departure_names'] === $row['departure_names'], 'Existing departure policy remains exact');
        $check(!array_key_exists('city_id', $resolved), 'Geography must not masquerade as a travel catalogue ID');
        if (array_key_exists('hierarchy',$row)) $check(($resolved['hierarchy'] ?? null) === $row['hierarchy'], 'Hierarchy is preserved without guessing parent IDs');
        $publishedCount++;
    }
}
$check($publishedCount === (($catalog['version'] ?? null) === 2 ? $catalog['region_count'] : count($catalog['regions'])), 'Published coverage matches the manifest');

// Each copied reader has an independent request cache and only isolated synthetic
// resources. Production resources are never overwritten by corruption tests.
$fixtureDirectories = [];
$fixtureCounter = 0;
$reader = file_get_contents(__DIR__.'/../services/YandexRegionCatalog.php');
$fixture = static function (string $manifest, array $files) use (&$fixtureDirectories,&$fixtureCounter,$reader): array {
    $directory = sys_get_temp_dir().'/yandex-catalog-'.bin2hex(random_bytes(8));
    mkdir($directory.'/services',0700,true);mkdir($directory.'/resources/yandex_regions',0700,true);
    $fixtureDirectories[] = $directory;
    file_put_contents($directory.'/resources/yandex_regions.json',$manifest);
    foreach ($files as $suffix=>$raw) file_put_contents($directory.'/resources/yandex_regions/'.str_pad((string)$suffix,2,'0',STR_PAD_LEFT).'.json',$raw);
    $class = 'YandexRegionCatalogFixture'.(++$fixtureCounter);
    file_put_contents($directory.'/services/YandexRegionCatalog.php',str_replace('final class YandexRegionCatalog','final class '.$class,$reader));
    require $directory.'/services/YandexRegionCatalog.php';
    return [$class,$directory];
};
$encode = static fn(array $value): string => json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$row = ['name'=>'Synthetic city','departure_names'=>[],'hierarchy'=>['Country','District','Synthetic city']];
$raw01 = $encode(['regions'=>['1'=>$row]]);
$largeId = '18446744073709551615';
$raw15 = $encode(['regions'=>[$largeId=>$row]]);
$entry = static fn(string $raw,int $count=1): array => ['count'=>$count,'sha256'=>hash('sha256',$raw)];
$manifest = ['version'=>2,'layout'=>'suffix-2','region_count'=>2,
    'source'=>'synthetic local fixture','buckets'=>['01'=>$entry($raw01),'15'=>$entry($raw15)]];
try {
    [$class,$directory] = $fixture($encode($manifest),['01'=>$raw01,'15'=>$raw15]);
    $check($class::findByYandexId('1')['hierarchy'] === $row['hierarchy'], 'Bounded reader returns the raw hierarchy');
    $check($class::findByYandexId($largeId)['id'] === $largeId, 'Suffix selection never casts a large decimal ID to integer');
    $check($class::findByYandexId('3') === null, 'A missing suffix remains unknown');

    $withMissing = $manifest;$withMissing['region_count']=3;$withMissing['buckets']['02']=$entry($raw01);
    [$class] = $fixture($encode($withMissing),['01'=>$raw01,'15'=>$raw15]);
    $check($class::findByYandexId('1') !== null, 'Lookup reads only its requested bucket, not missing neighbours');
    $check($class::findByYandexId('2') === null, 'An absent requested bucket fails closed');

    [$class,$directory] = $fixture($encode($manifest),['01'=>$raw01,'15'=>$raw15]);
    $check($class::findByYandexId('1') !== null, 'First bucket is available');
    $check($class::findByYandexId($largeId) !== null, 'A different bucket is available');
    file_put_contents($directory.'/resources/yandex_regions/01.json','corrupted after the first read');
    $check($class::findByYandexId('1') === null, 'Changing suffix evicts the preceding decoded bucket');

    $legacy = $encode(['version'=>1,'regions'=>['2'=>['name'=>'Legacy city','departure_names'=>['Legacy city']]]]);
    [$class] = $fixture($legacy,[]);
    $check($class::findByYandexId('2')['name'] === 'Legacy city', 'Bounded v1 migration compatibility remains available');
    [$class] = $fixture($legacy.str_repeat(' ',1048577-strlen($legacy)),[]);
    $check($class::findByYandexId('2') === null, 'Oversized flat legacy snapshots are not decoded');

    $invalidManifests = [];
    foreach ([['version',3],['layout','prefix-2'],['region_count',0],['region_count',250001],['region_count','2'],['region_count',3]] as [$key,$value]) {
        $invalid=$manifest;$invalid[$key]=$value;$invalidManifests[]=$invalid;
    }
    foreach ([['../01',$entry($raw01)],['1',$entry($raw01)],['01',$entry($raw01,0)],['01',['count'=>1,'sha256'=>'invalid']],['01',['count'=>'1','sha256'=>hash('sha256',$raw01)]]] as [$suffix,$value]) {
        $invalid=$manifest;$invalid['buckets']=[$suffix=>$value];$invalidManifests[]=$invalid;
    }
    foreach ($invalidManifests as $invalid) {
        [$class] = $fixture($encode($invalid),['01'=>$raw01,'15'=>$raw15]);
        $check($class::findByYandexId('1') === null, 'Invalid manifest metadata never authorizes a bucket read');
    }
    [$class] = $fixture($encode($manifest).str_repeat(' ',65537-strlen($encode($manifest))),['01'=>$raw01,'15'=>$raw15]);
    $check($class::findByYandexId('1') === null, 'V2 manifest is limited to 64 KiB');

    $single = ['version'=>2,'layout'=>'suffix-2','region_count'=>1,'buckets'=>['01'=>$entry($raw01)]];
    [$class] = $fixture($encode($single),['01'=>$raw01.' ']);
    $check($class::findByYandexId('1') === null, 'Requested bucket hash is verified before use');
    $wrongCount=$single;$wrongCount['region_count']=2;$wrongCount['buckets']['01']['count']=2;
    [$class] = $fixture($encode($wrongCount),['01'=>$raw01]);
    $check($class::findByYandexId('1') === null, 'Decoded row count must match the manifest');
    foreach ([$encode(['regions'=>['2'=>$row]]),$encode(['regions'=>['01'=>$row]]),'invalid JSON'] as $badBucket) {
        $invalid=$single;$invalid['buckets']['01']=$entry($badBucket);
        [$class] = $fixture($encode($invalid),['01'=>$badBucket]);
        $check($class::findByYandexId('1') === null, 'Bucket keys and JSON must match the suffix contract');
    }
    $oversized=$raw01.str_repeat(' ',1048577-strlen($raw01));
    $invalid=$single;$invalid['buckets']['01']=$entry($oversized);
    [$class] = $fixture($encode($invalid),['01'=>$oversized]);
    $check($class::findByYandexId('1') === null, 'Requested bucket is limited to 1 MiB even with a matching hash');
    foreach ([['too','many','hierarchy','labels','for','one row'],['Country',null]] as $hierarchy) {
        $invalidRow=$row;$invalidRow['hierarchy']=$hierarchy;
        $invalidRaw=$encode(['regions'=>['1'=>$invalidRow]]);$invalid=$single;$invalid['buckets']['01']=$entry($invalidRaw);
        [$class] = $fixture($encode($invalid),['01'=>$invalidRaw]);
        $check($class::findByYandexId('1') === null, 'Malformed hierarchy never becomes a geographic fact');
    }
} finally {
    foreach ($fixtureDirectories as $directory) {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($directory);
    }
}
echo "Yandex region catalogue regression passed\n";
