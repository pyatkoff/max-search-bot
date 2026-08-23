<?php
/**
 * DepartureRouteResolver
 *
 * Работает ТОЛЬКО с локальными файлами:
 *   - tourvisor_routes.json
 *   - departure_fallbacks.json
 *
 * Никаких запросов к Tourvisor API в момент работы.
 */

declare(strict_types=1);

final class DepartureRouteResolver
{
    private array $routes;
    private array $fallbacks;

    private const COUNTRY_PRIORITY = [
        'турция' => 100,
        'египет' => 95,
        'оаэ' => 90,
        'таиланд' => 85,
        'вьетнам' => 80,
        'шри-ланка' => 75,
        'мальдивы' => 70,
        'тунис' => 65,
        'китай' => 60,
        'абхазия' => 55,
        'россия' => 50,
    ];

    public function __construct(
        string $routesFile = __DIR__ . '/tourvisor_routes.json',
        string $fallbacksFile = __DIR__ . '/departure_fallbacks.json'
    ) {
        $this->routes = $this->loadJson($routesFile);
        $rawFallbacks = is_file($fallbacksFile) ? $this->loadJson($fallbacksFile) : [];
        $this->fallbacks = [];
        foreach ($rawFallbacks as $departure => $fallbackList) {
            $this->fallbacks[$this->normalize((string)$departure)] = is_array($fallbackList)
                ? array_values($fallbackList) : [$fallbackList];
        }
    }

    private function loadJson(string $file): array
    {
        if (!is_file($file)) throw new RuntimeException("Файл не найден: {$file}");
        $json = file_get_contents($file);
        if ($json === false) throw new RuntimeException("Не удалось прочитать файл: {$file}");
        $data = json_decode($json, true);
        if (!is_array($data)) throw new RuntimeException("Некорректный JSON: {$file}");
        return $data;
    }

    public function getDirectDestinations(string $departure, ?string $period = null): array
    {
        $dep = $this->findDeparture($departure);
        if (!$dep) return ['ok'=>false,'reason'=>'departure_not_found','departure'=>$departure,'destinations'=>[]];

        $result = [];
        foreach (($dep['countries'] ?? []) as $country) {
            $direct = !empty($country['direct']);
            $charter = !empty($country['charter']);
            if (!$direct || !$charter) continue;

            $dates = $this->filterDates((array)($country['dates'] ?? []), $period);
            if ($period !== null && !$dates) continue;
            if ((int)($country['dates_count'] ?? 0) <= 0 && $period === null) continue;

            $datesCount = $period !== null ? count($dates) : (int)($country['dates_count'] ?? 0);
            $countryName = (string)($country['country'] ?? '');
            $result[] = [
                'country_id'=>(int)($country['country_id'] ?? 0),
                'country'=>$countryName,
                'direct'=>true,
                'charter'=>true,
                'dates_count'=>$datesCount,
                'first_date'=>$period !== null ? ($dates[0] ?? null) : ($country['first_date'] ?? null),
                'last_date'=>$period !== null ? ($dates ? $dates[count($dates)-1] : null) : ($country['last_date'] ?? null),
                'dates'=>$period !== null ? $dates : [],
                'recommendation_score'=>$this->recommendationScore($countryName, $datesCount),
            ];
        }

        usort($result, static function(array $a, array $b): int {
            $score = ($b['recommendation_score'] ?? 0) <=> ($a['recommendation_score'] ?? 0);
            if ($score !== 0) return $score;
            return ($b['dates_count'] ?? 0) <=> ($a['dates_count'] ?? 0);
        });

        return ['ok'=>true,'departure_id'=>(int)($dep['id'] ?? 0),'departure'=>(string)($dep['name'] ?? $departure),'period'=>$period,'destinations'=>$result];
    }

    private function recommendationScore(string $country, int $datesCount): int
    {
        $priority = self::COUNTRY_PRIORITY[$this->normalize($country)] ?? 40;
        return ($priority * 1000) + min(max($datesCount, 0), 999);
    }

    public function checkRoute(string $departure, string $country, ?string $period = null): array
    {
        $dep = $this->findDeparture($departure);
        if (!$dep) return ['ok'=>false,'reason'=>'departure_not_found','departure'=>$departure,'country'=>$country];
        $route = $this->findCountryInDeparture($dep, $country);
        if (!$route) return ['ok'=>true,'departure'=>(string)$dep['name'],'country'=>$country,'direct'=>false,'charter'=>false,'direct_charter'=>false,'available_in_period'=>false,'dates_count'=>0,'dates'=>[]];
        $direct=!empty($route['direct']); $charter=!empty($route['charter']); $directCharter=$direct&&$charter;
        $dates=$this->filterDates((array)($route['dates'] ?? []),$period);
        $hasDates=$period===null ? ((int)($route['dates_count'] ?? 0)>0) : !empty($dates);
        return ['ok'=>true,'departure'=>(string)$dep['name'],'country'=>(string)($route['country'] ?? $country),'direct'=>$direct,'charter'=>$charter,'direct_charter'=>$directCharter,'available_in_period'=>$directCharter&&$hasDates,'dates_count'=>$period===null?(int)($route['dates_count']??0):count($dates),'dates'=>$period===null?[]:$dates];
    }

    public function getFallback(string $departure,string $country,?string $period=null): array
    {
        $fallbackList=$this->fallbacks[$this->normalize($departure)]??[]; $checked=[];
        foreach($fallbackList as $fallbackDeparture){
            $fallbackDeparture=trim((string)$fallbackDeparture); if($fallbackDeparture==='')continue;
            $check=$this->checkRoute($fallbackDeparture,$country,$period); $checked[]=$check;
            if(($check['ok']??false)&&($check['direct_charter']??false)&&($check['available_in_period']??false)){
                return ['ok'=>true,'found'=>true,'requested_departure'=>$departure,'country'=>$country,'period'=>$period,'fallback_departure'=>$check['departure'],'dates_count'=>$check['dates_count']??0,'dates'=>$check['dates']??[],'checked'=>$checked];
            }
        }
        return ['ok'=>true,'found'=>false,'requested_departure'=>$departure,'country'=>$country,'period'=>$period,'checked'=>$checked];
    }

    public function resolve(string $departure, ?string $country = null, ?string $period = null): array
    {
        if ($country === null || trim($country) === '') {
            return $this->getDirectDestinations($departure, $period);
        }

        $route = $this->checkRoute($departure, $country, $period);
        if (($route['direct_charter'] ?? false) && ($route['available_in_period'] ?? false)) {
            return [
                'status' => 'direct_available',
                'route' => $route,
                'fallback' => null,
            ];
        }

        $fallback = $this->getFallback($departure, $country, $period);
        return [
            'status' => ($fallback['found'] ?? false) ? 'fallback_available' : 'not_found',
            'route' => $route,
            'fallback' => $fallback,
        ];
    }

    private function findDeparture(string $departure): ?array
    {
        $needle=$this->normalize($departure);
        foreach(($this->routes['departures']??[]) as $dep){ if($this->normalize((string)($dep['name']??''))===$needle)return $dep; }
        return null;
    }

    private function findCountryInDeparture(array $dep,string $country): ?array
    {
        $needle=$this->normalize($country);
        foreach(($dep['countries']??[]) as $row){ if($this->normalize((string)($row['country']??''))===$needle)return $row; }
        return null;
    }

    private function filterDates(array $dates,?string $period): array
    {
        $dates=array_values(array_filter(array_map('strval',$dates),static fn($v)=>preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)));
        sort($dates); if($period===null||trim($period)==='')return $dates;
        $period=trim($period);
        if(preg_match('/^\d{4}-\d{2}$/',$period)) return array_values(array_filter($dates,static fn($d)=>str_starts_with($d,$period.'-')));
        if(preg_match('/^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/',$period,$m)){ return array_values(array_filter($dates,static fn($d)=>$d>=$m[1]&&$d<=$m[2])); }
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$period)) return array_values(array_filter($dates,static fn($d)=>$d===$period));
        return [];
    }

    private function normalize(string $value): string
    {
        $value=function_exists('mb_strtolower')?mb_strtolower(trim($value),'UTF-8'):strtolower(trim($value));
        $aliases=['санкт-петербург'=>'с.петербург','петербург'=>'с.петербург','спб'=>'с.петербург','нижний новгород'=>'н.новгород','минеральные воды'=>'мин.воды','набережные челны'=>'наб.челны','петропавловск-камчатский'=>'п.камчатский','южно-сахалинск'=>'ю.сахалинск'];
        return $aliases[$value]??$value;
    }
}
