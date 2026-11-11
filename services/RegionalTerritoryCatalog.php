<?php
declare(strict_types=1);
require_once __DIR__.'/YandexRegionCatalog.php';

/** Configuration-time scope compiler. Runtime authorization never loads 99k rows. */
final class RegionalTerritoryCatalog
{
    // Reviewed aliases of the same source macro, never aliases of city names.
    private const MACROS=[
        'Центр'=>'Центр','Центральный федеральный округ'=>'Центр',
        'Северо-Запад'=>'Северо-Запад','Северо-Западный федеральный округ'=>'Северо-Запад',
        'Северный Кавказ'=>'Северный Кавказ','Северо-Кавказский федеральный округ'=>'Северный Кавказ',
        'Юг'=>'Юг','Южный федеральный округ'=>'Юг',
        'Поволжье'=>'Поволжье','Приволжский федеральный округ'=>'Поволжье',
        'Урал'=>'Урал','Уральский федеральный округ'=>'Урал',
        'Сибирь'=>'Сибирь','Сибирский федеральный округ'=>'Сибирь',
        'Дальний Восток'=>'Дальний Восток','Дальневосточный федеральный округ'=>'Дальний Восток',
    ];

    public static function manifestHash(): string
    {
        $path=dirname(__DIR__).'/resources/yandex_regions.json';
        if(!is_file($path)||is_link($path)||filesize($path)>65536)throw new RuntimeException('regional_catalog_unavailable');
        $hash=hash_file('sha256',$path);if(!is_string($hash))throw new RuntimeException('regional_catalog_unavailable');
        return$hash;
    }

    private static function prefix(array $path): ?array
    {
        if(count($path)<2||!isset(self::MACROS[$path[0]??''])||!is_string($path[1]??null)||$path[1]==='')return null;
        return[self::MACROS[$path[0]],$path[1]];
    }

    /** Strictly stream one verified suffix bucket at a time, only for admin saves. */
    private static function each(callable $visit): void
    {
        $base=dirname(__DIR__).'/resources/';$manifest=json_decode(file_get_contents($base.'yandex_regions.json'),true,16,JSON_THROW_ON_ERROR);
        if(($manifest['version']??null)!==2||($manifest['layout']??null)!=='suffix-2'||count($manifest['buckets']??[])!==100)throw new RuntimeException('regional_catalog_invalid');
        $total=0;
        for($bucket=0;$bucket<100;$bucket++){
            $suffix=str_pad((string)$bucket,2,'0',STR_PAD_LEFT);$receipt=$manifest['buckets'][$suffix]??null;
            $file=$base.'yandex_regions/'.$suffix.'.json';
            if(!is_array($receipt)||!is_file($file)||is_link($file)||filesize($file)>1048576)throw new RuntimeException('regional_bucket_unavailable');
            $raw=file_get_contents($file);if(!is_string($raw)||!hash_equals((string)($receipt['sha256']??''),hash('sha256',$raw)))throw new RuntimeException('regional_bucket_checksum');
            $rows=json_decode($raw,true,16,JSON_THROW_ON_ERROR)['regions']??null;
            if(!is_array($rows)||count($rows)!==($receipt['count']??null))throw new RuntimeException('regional_bucket_count');
            foreach($rows as$id=>$row){
                $id=(string)$id;$path=$row['hierarchy']??[];
                if(YandexRegionCatalog::normalizeId($id)!==$id||substr(str_pad($id,2,'0',STR_PAD_LEFT),-2)!==$suffix
                    ||!is_array($path)||!array_is_list($path)||count($path)>5)throw new RuntimeException('regional_bucket_record');
                foreach($path as$label)if(!is_string($label)||$label===''||trim($label)!==$label||strlen($label)>400||preg_match('/[\x00-\x1f\x7f]/',$label))throw new RuntimeException('regional_bucket_path');
                $visit($id,$row,$path);$total++;
            }
        }
        if($total!==($manifest['region_count']??null))throw new RuntimeException('regional_catalog_count');
    }

    public static function options(): array
    {
        $hash=self::manifestHash();$options=[];
        self::each(static function(string$id,array$row,array$path)use(&$options,$hash):void{
            $prefix=self::prefix($path);if($prefix===null)return;$key=json_encode($prefix,JSON_THROW_ON_ERROR);
            $old=$options[$key]??null;$count=($old['descendant_count']??0)+1;
            if($old===null||count($path)<count($old['hierarchy'])||(count($path)===count($old['hierarchy'])&&(int)$id<(int)$old['region_id'])){
                $options[$key]=['region_id'=>$id,'name'=>$prefix[1],'hierarchy'=>$path,'prefix'=>$prefix,'catalog_sha256'=>$hash];
            }
            $options[$key]['descendant_count']=$count;
        });
        if(self::manifestHash()!==$hash)throw new RuntimeException('regional_catalog_changed');
        $options=array_values($options);usort($options,static fn(array$a,array$b):int=>strcmp(implode(' / ',$a['prefix']),implode(' / ',$b['prefix'])));
        return$options;
    }

    public static function selected(array $ids): array
    {
        if(!array_is_list($ids)||count($ids)>20)throw new InvalidArgumentException('invalid_territory_regions');
        foreach($ids as$id)if(!is_string($id)||$id===''||YandexRegionCatalog::normalizeId($id)!==$id)throw new InvalidArgumentException('invalid_territory_regions');
        if(count(array_unique($ids))!==count($ids))throw new InvalidArgumentException('invalid_territory_regions');
        if(!$ids)return[];
        $options=[];foreach(self::options()as$option)$options[$option['region_id']]=$option;
        $selected=[];$byPrefix=[];
        foreach($ids as$id){if(!isset($options[$id]))throw new InvalidArgumentException('invalid_territory_regions');$selected[$id]=$options[$id]+['region_ids'=>[]];$byPrefix[json_encode($options[$id]['prefix'],JSON_THROW_ON_ERROR)]=$id;}
        self::each(static function(string$id,array$row,array$path)use(&$selected,$byPrefix):void{
            $prefix=self::prefix($path);if($prefix===null)return;$key=json_encode($prefix,JSON_THROW_ON_ERROR);
            if(isset($byPrefix[$key]))$selected[$byPrefix[$key]]['region_ids'][]=$id;
        });
        $hash=self::manifestHash();foreach($selected as$scope)if($scope['catalog_sha256']!==$hash||count($scope['region_ids'])!==$scope['descendant_count'])throw new RuntimeException('regional_catalog_changed');
        return array_values($selected);
    }

    public static function validHeader(array $scope): bool
    {
        try{
            if(!is_string($scope['territory_region_id']??null)||$scope['territory_region_id']===''||YandexRegionCatalog::normalizeId($scope['territory_region_id'])!==$scope['territory_region_id']||!hash_equals(self::manifestHash(),(string)($scope['catalog_sha256']??'')))return false;
            $anchor=YandexRegionCatalog::findByYandexId($scope['territory_region_id']);if(!$anchor)return false;
            $path=json_decode((string)($scope['anchor_hierarchy_json']??''),true,8,JSON_THROW_ON_ERROR);
            $prefix=json_decode((string)($scope['prefix_json']??''),true,8,JSON_THROW_ON_ERROR);
            $expected=is_array($path)?self::prefix($path):null;
            return $expected!==null&&is_array($prefix)&&array_is_list($prefix)&&count($prefix)===2&&$path===($anchor['hierarchy']??[])&&$prefix===$expected;
        }catch(Throwable$e){return false;}
    }
}
