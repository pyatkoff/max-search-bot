<?php
declare(strict_types=1);

/** Current published temporary limits. No upload, state write or assumed restoration date. */
final class ModeledConversionAttributionWindow
{
    public const SOURCE='https://yandex.ru/support/metrica/ru/special-mode';
    public const VERSION='metrika_special_mode_checked_20261110';
    public const MAX_SECONDS=10*86400;
    public const UNKNOWN_SECONDS=3*86400;
    public static function metadata():array
    {
        return ['version'=>self::VERSION,'source'=>self::SOURCE,'yandex_direct_days'=>10,'other_or_unknown_days'=>3,
            'basis'=>'current_rules_at_check_not_a_historical_provider_decision'];
    }
    /** Exact current visitor report only. A label/UTM/retained click never grants ten days. */
    public static function assess(array $report,int $event,int $now):array
    {
        $out=['allowed'=>false,'reason'=>'visit_report_unavailable','source_class'=>'other_or_unknown','window_days'=>3,
            'visit_at'=>null,'eligible_at_signal'=>null,'eligible_now'=>null];
        if($event<946684800||$now<$event||($report['status']??null)!=='ok'||!is_array($report['visits']??null)||count($report['visits'])>100)return $out;
        $latest=null;$source='other_or_unknown';
        foreach($report['visits']as$v){
            if(!is_array($v)||!is_string($v['started_at']??null)||!is_int($v['visits']??null)||$v['visits']<1)return $out;
            $at=strtotime($v['started_at']);if($at===false||preg_match('/\\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|\\+00:00)\\z/D',$v['started_at'])!==1||gmdate('Y-m-d\\TH:i:s',$at)!==substr($v['started_at'],0,19))return $out;
            if($at>$event)continue;
            $kind=($v['source_class']??null)==='yandex_direct'?'yandex_direct':'other_or_unknown';
            if($latest===null||$at>$latest){$latest=$at;$source=$kind;}
            elseif($at===$latest&&$source!==$kind)$source='other_or_unknown';
        }
        $days=$source==='yandex_direct'?10:3;$seconds=$days*86400;
        $out['source_class']=$source;$out['window_days']=$days;$out['visit_at']=$latest;
        $out['eligible_at_signal']=$latest!==null&&$event-$latest<=$seconds;
        $out['eligible_now']=$latest!==null&&$now-$latest<=$seconds;
        $out['allowed']=$out['eligible_at_signal']&&$out['eligible_now'];
        $out['reason']=$latest===null?'no_prior_visit':($out['allowed']?'within_current_window':'outside_current_window');
        return $out;
    }
}
