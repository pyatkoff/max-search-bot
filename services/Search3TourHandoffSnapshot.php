<?php
declare(strict_types=1);

/** Exact source observation, not proof of a current full fare or a LOCAL match. */
final class Search3TourHandoffSnapshot
{
    private static function object(mixed $value,array $keys): array
    {
        if (!is_array($value)||array_is_list($value)||array_diff(array_keys($value),$keys)||array_diff($keys,array_keys($value))) throw new InvalidArgumentException('shape');
        return $value;
    }
    private static function text(mixed $value,int $max,bool $required=false): void
    {
        if (!is_string($value)||!preg_match('//u',$value)||preg_match('/[\x00-\x1f\x7f]/u',$value)||mb_strlen($value)>$max||($required&&trim($value)==='')) throw new InvalidArgumentException('text');
    }
    private static function integer(mixed $value,int $min,int $max): void
    {
        if (!is_int($value)||$value<$min||$value>$max) throw new InvalidArgumentException('integer');
    }
    private static function day(mixed $value,bool $optional=false): void
    {
        if ($value===null&&$optional) return;
        if (!is_string($value)||!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D',$value,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1])) throw new InvalidArgumentException('date');
    }
    private static function direction(mixed $value): void
    {
        if (!is_array($value)||!array_is_list($value)||!$value||count($value)>4) throw new InvalidArgumentException('direction');
        foreach ($value as $leg) {
            if (is_array($leg)&&array_keys($leg)===['label']) {self::text($leg['label'],1000,true);continue;}
            self::object($leg,['number','carrier','departure','arrival','baggage','carryOn']);
            self::text($leg['number'],120);self::text($leg['carrier'],180);
            foreach (['departure','arrival'] as $key) {
                $point=self::object($leg[$key],['date','time','town','port','portCode']);
                foreach (['date'=>80,'town'=>160,'port'=>160,'portCode'=>80] as $field=>$max) self::text($point[$field],$max);
                if ($point['time']!==null) self::text($point['time'],80);
            }
            foreach (['baggage','carryOn'] as $key) if ($leg[$key]!==null) self::text($leg[$key],160);
        }
    }
    public static function normalize(array $input): ?array
    {
        try {
            self::object($input,['binding','observation']);$b=self::object($input['binding'],['record_id','revision']);
            if (!is_string($b['record_id'])||!preg_match('/\A[A-Za-z0-9:_-]{1,128}\z/D',$b['record_id'])) return null;
            self::integer($b['revision'],1,2147483647);
            $o=self::object($input['observation'],['schema','kind','capturedAt','source','hotel','trip','stay','flights','price']);
            if ($o['schema']!==1||$o['kind']!=='search3_browser_observation'||!in_array($o['source'],['anex','tourvisor','andromeda'],true)) return null;
            self::integer($o['capturedAt'],1,8640000000000000);
            $h=self::object($o['hotel'],['uiId','namespace','name','resort']);if ($h['namespace']!=='search3-ui') return null;
            self::integer($h['uiId'],1,2147483647);self::text($h['name'],191,true);self::text($h['resort'],300);
            $t=self::object($o['trip'],['origin','countryUiId','departure','nights','stayEnd','stayEndBasis','adults','childAges']);
            self::text($t['origin'],180,true);self::text($t['countryUiId'],80);self::day($t['departure']);self::day($t['stayEnd'],true);
            self::integer($t['nights'],1,365);self::integer($t['adults'],1,6);
            if (!in_array($t['stayEndBasis'],['shown-stay-end','unknown'],true)||(($t['stayEnd']===null)!==($t['stayEndBasis']==='unknown'))||($t['stayEnd']!==null&&$t['stayEnd']<=$t['departure'])) return null;
            if (!is_array($t['childAges'])||!array_is_list($t['childAges'])||count($t['childAges'])>3) return null;
            foreach ($t['childAges'] as $age) self::integer($age,0,17);
            $s=self::object($o['stay'],['room','meal','placement','operator']);foreach (['room'=>500,'meal'=>300,'placement'=>500,'operator'=>300] as $key=>$max) self::text($s[$key],$max,in_array($key,['room','meal'],true));
            $f=self::object($o['flights'],['state','outward','inward']);if (!in_array($f['state'],['selected','manager-deferred','not-selected'],true)) return null;
            if ($f['state']==='selected') {self::direction($f['outward']);self::direction($f['inward']);}
            elseif ($f['outward']!==[]||$f['inward']!==[]) return null;
            $p=self::object($o['price'],['amount','currency','state','description','expiresAt','basis','finalPriceVerified','authority']);
            if ($p['finalPriceVerified']!==false||$p['authority']!=='browser-observation-only'||!in_array($p['currency'],['RUB','EUR','USD'],true)||!in_array($p['state'],['listing','checked','estimated','unknown','expired'],true)) return null;
            if ($p['amount']!==null&&(!is_string($p['amount'])||!preg_match('/\A(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?\z/D',$p['amount'])||(float)$p['amount']<=0)) return null;
            if (($p['amount']===null&&!in_array($p['state'],['unknown','expired'],true))||($p['state']==='unknown'&&$p['amount']!==null)) return null;
            self::text($p['description'],1000);if ($p['expiresAt']!==null) self::integer($p['expiresAt'],1,9007199254740);
            if (!in_array($p['basis'],['shown-offer','selected-pair'],true)||($p['basis']==='selected-pair')!==($f['state']==='selected')) return null;
            return strlen(json_encode($input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<=24576?$input:null;
        } catch (InvalidArgumentException|JsonException $e) {return null;}
    }
}
