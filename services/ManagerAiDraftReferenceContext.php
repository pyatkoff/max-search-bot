<?php

declare(strict_types=1);

require_once __DIR__.'/ChannelPostRegistryService.php';
require_once __DIR__.'/TravelDirectoryRepository.php';
require_once __DIR__.'/ManagerAiTextRedactor.php';
require_once __DIR__.'/ManagerHotelReferenceContext.php';

/** Server-owned, bounded source evidence. No HTTP, provider calls or writes. */
final class ManagerAiDraftReferenceContext
{
    private const MAX_CACHE_BYTES = 8388608;
    private const MAX_DEPARTURES = 3;
    private const MAX_COUNTRIES = 24;
    private const MAX_DATES = 24;

    private function __construct(private string $binding, private array $data) {}

    /** Caller must first authorize the current reply owner in its read snapshot. */
    public static function capture(PDO $pdo, array $conversation, array $context): self
    {
        $posts = ['status'=>'unavailable','current'=>null,'history'=>[]];
        $travel = ['status'=>'unavailable','current_price_verified'=>false,'coverage_complete'=>false,'departures'=>[]];
        if (($conversation['project_key'] ?? '') === 'anytour') {
            try {
                $rows = (new ChannelPostRegistryService($pdo))->managerContext((int)$conversation['id'], 'anytour', 3);
                $current = is_array($rows['current'] ?? null) ? self::post($rows['current']) : null;
                $history = [];
                foreach (array_slice($rows['history'] ?? [],0,1) as $row) $history[] = self::post($row);
                $posts = ['status'=>$current === null ? 'none' : ($current['unavailable'] ? 'unavailable' : 'available'),
                    'current'=>$current,'history'=>$history,'history_truncated'=>count($rows['history'] ?? [])>1];
            } catch (Throwable $ignored) { /* Optional evidence never repairs storage. */ }
            $texts = [];
            foreach (array_reverse($context['messages']) as $row) {
                if (($row['sender_type'] ?? '') === 'customer') $texts[] = (string)$row['text'];
            }
            // Earlier posts must not become current after an unknown new entry.
            if (!empty($posts['current']) && !$posts['current']['unavailable']) {
                $texts[] = $posts['current']['title'].' '.$posts['current']['text'];
            }
            try { $travel = self::travelRoutes($texts, time()); }
            catch (Throwable $ignored) { /* Unknown directory evidence stays unknown. */ }
        }
        $hotel = ManagerHotelReferenceContext::capture($pdo, $conversation, $context);
        return new self(self::binding($context), ['origin_posts'=>$posts,'travel_reference'=>$travel,'hotel_reference'=>$hotel]);
    }

    public function forContext(array $context): ?array
    {
        return hash_equals($this->binding,self::binding($context)) ? $this->data : null;
    }

    public function revision(): string
    {
        return hash('sha256',json_encode($this->data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    /** Legacy-file validation for operations; drafts primarily read the catalogue DB. */
    public static function routeCacheHealth(?string $path = null): array
    {
        return self::readRouteCache($path ?? dirname(__DIR__).'/tourvisor_routes.json',time())['health'];
    }

    /** Same primary reader as draft capture; no supplier calls or refresh. */
    public static function travelReferenceHealth(): array
    {
        $reference = self::travelRoutes([], time());
        unset($reference['departures'], $reference['departures_truncated']);
        return $reference;
    }

    private static function travelRoutes(array $texts, int $now): array
    {
        $catalog = self::catalogRoutes($texts, $now);
        if ($catalog['status'] === 'available') return $catalog;
        $legacy = self::routes(dirname(__DIR__).'/tourvisor_routes.json', $texts, $now);
        $legacy['catalog_status'] = $catalog['status'];
        $legacy['catalog_reason'] = $catalog['reason'];
        return $legacy;
    }

    private static function catalogRoutes(array $texts, int $now): array
    {
        $result = ['status'=>'unavailable', 'source'=>'anytour_catalog_db', 'reason'=>'catalog_unavailable',
            'current_price_verified'=>false, 'coverage_complete'=>false, 'dates_available'=>false,
            'catalog_synced_at'=>null, 'catalog_stale'=>true, 'departures'=>[]];
        try { $raw = TravelDirectoryRepository::routeMatrixRows(); }
        catch (Throwable $ignored) {
            $result['reason'] = HotelDatabase::configured() ? 'catalog_unavailable' : 'catalog_not_configured';
            return $result;
        }
        if (!$raw) { $result['reason']='catalog_empty'; return $result; }
        $truncated = count($raw)>8192;
        $counts = ['all'=>0,'direct'=>0,'charter'=>0,'direct_charter'=>0];
        $rows = []; $forms = []; $flags = []; $oldest = $now; $stale = false;
        foreach (array_slice($raw,0,8192) as $row) {
            $kind = (string)($row['kind'] ?? '');
            $did = self::id($row['departure_id'] ?? null); $cid = self::id($row['country_id'] ?? null);
            $departure = self::label($row['departure'] ?? null); $country = self::label($row['country'] ?? null);
            if (!array_key_exists($kind,$counts) || $did===null || $cid===null || $departure===null || $country===null) {
                $truncated=true; continue;
            }
            ++$counts[$kind];
            $synced = (int)($row['synced_at_unix'] ?? 0);
            $stale = $stale || $synced<=0 || $synced>$now+300 || $now-$synced>86400;
            $oldest = min($oldest,$synced);
            if ($kind==='all') {
                if (!isset($rows[$did])) $rows[$did]=['id'=>$did,'name'=>$departure,'countries'=>[]];
                $rows[$did]['countries'][$cid]=['country_id'=>$cid,'country'=>$country,'dates'=>[]];
                $genitive=self::label($row['name_genitive'] ?? null);
                if ($genitive!==null) $forms[self::lower($departure)]=[$genitive];
            } else $flags[$did][$cid][$kind]=true;
        }
        if (!$counts['all']) { $result['reason']='catalog_empty'; return $result; }
        foreach ($rows as $did=>&$departure) {
            foreach ($departure['countries'] as $cid=>&$country) {
                foreach (['direct','charter','direct_charter'] as $kind) {
                    $country[$kind]=isset($flags[$did][$cid][$kind]) ? true :
                        (!$truncated && $counts[$kind]>0 ? false : null);
                }
            }
            unset($country);
            $departure['countries']=array_values($departure['countries']);
        }
        unset($departure);
        $projected=self::projectRoutes(array_values($rows),$texts,$forms);
        return array_replace($result, ['status'=>'available','reason'=>'catalog_readable',
            'catalog_synced_at'=>$oldest>0 ? gmdate('Y-m-d\TH:i:s\Z',$oldest) : null,
            'catalog_stale'=>$stale, 'catalog_rows_truncated'=>$truncated,
            'matrix_coverage_complete'=>!$truncated && !in_array(0,$counts,true),
            'matrix_pair_counts'=>$counts, 'departure_count'=>count($rows),
            'departures'=>$projected['departures'], 'departures_truncated'=>$projected['truncated']]);
    }

    private static function binding(array $context): string
    {
        $messages = [];
        foreach ($context['messages'] ?? [] as $row) $messages[] = [$row['id'] ?? null,$row['sender_type'] ?? null,$row['text'] ?? null];
        return hash('sha256',json_encode([$context['conversation_id'] ?? null,$context['as_of_message_id'] ?? null,$messages],JSON_THROW_ON_ERROR));
    }

    private static function post(array $row): array
    {
        $unavailable = !empty($row['unavailable']);
        $text = $unavailable ? '' : self::text((string)($row['text'] ?? ''),2400);
        $title = $unavailable ? '' : self::text((string)($row['title'] ?? ''),240);
        $media = !$unavailable && (int)($row['media_count'] ?? 0)>0;
        return ['entry_id'=>(int)$row['entry_id'],'version_id'=>$row['version_id'] ?? null,
            'entered_at'=>self::text((string)($row['entered_at'] ?? ''),64),
            'title'=>$title,'text'=>$text,'unavailable'=>$unavailable,
            'text_truncated'=>!$unavailable && (self::length((string)($row['text'] ?? ''))>2400 || self::length((string)($row['title'] ?? ''))>240),
            'media_unread'=>$media,'media_count'=>(int)($row['media_count']??0),'current_price_verified'=>false];
    }

    private static function routes(string $path, array $texts, int $now): array
    {
        $empty = ['status'=>'unavailable','current_price_verified'=>false,'coverage_complete'=>false,'departures'=>[]];
        $read = self::readRouteCache($path,$now);
        if ($read['cache'] === null) return $empty+$read['health'];
        $cache = $read['cache'];
        $forms = [];
        // Use the existing catalogue's exact canonical/genitive names. These
        // forms only select reference rows; they never change a tourist's needs.
        try {
            if (HotelDatabase::configured()) foreach (TravelDirectoryRepository::activeDepartures() as $row) {
                $name = self::lower((string)($row['name'] ?? ''));
                $genitive = (string)($row['name_genitive'] ?? '');
                if ($name!=='' && $genitive!=='') $forms[$name][] = $genitive;
            }
        } catch (Throwable $ignored) { /* Exact cached names remain usable. */ }
        $data = self::projectRoutes($cache['departures'],$texts,$forms);
        return $read['health']+['current_price_verified'=>false,'coverage_complete'=>false,
            'departures'=>$data['departures'],'departures_truncated'=>$data['truncated']];
    }

    private static function readRouteCache(string $path, int $now): array
    {
        $health = ['status'=>'unavailable','source'=>'local_tourvisor_route_cache',
            'reason'=>'cache_not_found','cache_size_bytes'=>null,'cache_limit_bytes'=>self::MAX_CACHE_BYTES,
            'cache_modified_at'=>null,'cache_stale'=>true];
        clearstatcache(true,$path);
        $stat = @stat($path);
        if (!is_array($stat)) return ['health'=>$health,'cache'=>null];
        $health['cache_size_bytes'] = (int)$stat['size'];
        $modified = (int)$stat['mtime'];
        $health['cache_modified_at'] = $modified>0 ? gmdate('Y-m-d\TH:i:s\Z',$modified) : null;
        $health['cache_stale'] = $modified<=0 || $modified>$now+300 || $now-$modified>86400;
        if (($stat['mode'] & 0170000)!==0100000) return ['health'=>array_replace($health,['reason'=>'cache_not_regular']),'cache'=>null];
        if ($stat['size']>self::MAX_CACHE_BYTES) return ['health'=>array_replace($health,['reason'=>'cache_too_large']),'cache'=>null];
        $stream = @fopen($path,'rb');
        if ($stream === false) return ['health'=>array_replace($health,['reason'=>'cache_unreadable']),'cache'=>null];
        try {
            $stat = fstat($stream);
            $raw = stream_get_contents($stream,self::MAX_CACHE_BYTES+1);
        } finally { fclose($stream); }
        if (!is_string($raw)) return ['health'=>array_replace($health,['reason'=>'cache_read_failed']),'cache'=>null];
        if (strlen($raw)>self::MAX_CACHE_BYTES) return ['health'=>array_replace($health,['reason'=>'cache_too_large']),'cache'=>null];
        try { $cache = json_decode($raw,true,24,JSON_THROW_ON_ERROR); }
        catch (Throwable $ignored) { return ['health'=>array_replace($health,['reason'=>'cache_invalid_json']),'cache'=>null]; }
        if (!is_array($cache) || !is_array($cache['departures'] ?? null)) return ['health'=>array_replace($health,['reason'=>'cache_invalid_shape']),'cache'=>null];
        $modified = (int)($stat['mtime'] ?? 0);
        $health['cache_modified_at'] = $modified>0 ? gmdate('Y-m-d\TH:i:s\Z',$modified) : null;
        $health['cache_stale'] = $modified<=0 || $modified>$now+300 || $now-$modified>86400;
        $health['cache_size_bytes'] = strlen($raw);
        $health['status'] = 'available';
        $health['reason'] = 'cache_readable';
        $health['departure_count'] = count($cache['departures']);
        return ['health'=>$health,'cache'=>$cache];
    }

    private static function projectRoutes(array $rows, array $texts, array $forms): array
    {
        $out = [];
        foreach ($texts as $text) {
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $id = self::id($row['id'] ?? null);
                $name = self::label($row['name'] ?? null);
                if ($id===null || $name===null || isset($out[$id])) continue;
                $matched = false;
                foreach (array_merge([$name],$forms[self::lower($name)] ?? []) as $form) {
                    if (preg_match('/(?<![\pL\pN])'.preg_quote($form,'/').'(?![\pL\pN])/iu',$text)===1) { $matched=true;break; }
                }
                if (!$matched && preg_match('/\AНажата кнопка: pick_city_'.preg_quote((string)$id,'/').'\z/Du',$text)!==1) continue;
                if (count($out)>=self::MAX_DEPARTURES) return ['departures'=>array_values($out),'truncated'=>true];
                $countries = [];$countryRows = $row['countries'] ?? [];
                if (!is_array($countryRows)) $countryRows = [];
                foreach ($countryRows as $country) {
                    if (!is_array($country)) continue;
                    $cid = self::id($country['country_id'] ?? null);
                    $countryName = self::label($country['country'] ?? null);
                    if ($cid===null || $countryName===null) continue;
                    if (count($countries)>=self::MAX_COUNTRIES) break;
                    $dates = [];
                    foreach (is_array($country['dates'] ?? null) ? $country['dates'] : [] as $date) {
                        if (!is_string($date) || !preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D',$date,$m)
                            || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) continue;
                        $dates[$date]=true;
                    }
                    $dates = array_keys($dates);sort($dates,SORT_STRING);
                    $countries[] = ['country_id'=>$cid,'country'=>$countryName,
                        'direct'=>self::flag($country['direct'] ?? null),'charter'=>self::flag($country['charter'] ?? null),
                        'direct_charter'=>self::flag($country['direct_charter'] ?? null),
                        'dates'=>array_slice($dates,0,self::MAX_DATES),'dates_truncated'=>count($dates)>self::MAX_DATES];
                }
                $out[$id] = ['departure_id'=>$id,'departure'=>$name,'countries'=>$countries,
                    'countries_truncated'=>count($countryRows)>self::MAX_COUNTRIES];
            }
        }
        return ['departures'=>array_values($out),'truncated'=>false];
    }

    private static function id($value): ?int
    {
        return (is_int($value) || is_string($value)) && preg_match('/\A[1-9][0-9]{0,8}\z/D',(string)$value) ? (int)$value : null;
    }

    private static function flag($value): ?bool
    {
        return is_bool($value) ? $value : (in_array($value,[0,1],true) ? (bool)$value : null);
    }

    private static function label($value): ?string
    {
        return is_string($value) && preg_match('/\A[\pL\pN .()\-–]{1,100}\z/u',$value) ? $value : null;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower(trim($value),'UTF-8') : strtolower(trim($value));
    }

    private static function text(string $value, int $limit): string
    {
        if (preg_match('//u',$value)!==1) return '';
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$value) ?? '';
        $unread = false;$redacted = false;
        $value = ManagerAiTextRedactor::redact($value,$unread,$redacted);
        return function_exists('mb_substr') ? mb_substr($value,0,$limit,'UTF-8') : implode('',array_slice(preg_split('//u',$value,-1,PREG_SPLIT_NO_EMPTY) ?: [],0,$limit));
    }

    private static function length(string $value): int
    {
        return preg_match_all('/./us',$value) ?: 0;
    }
}
