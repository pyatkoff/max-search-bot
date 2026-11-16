<?php
declare(strict_types=1);

/** Saved scalar observations only; never admission, source access or delivery authority. */
final class ModeledConversionReadySelectionEvidence
{
    public const KEY='ready_selection';
    public static function stage(string $reason,int $candidates=0,int $examined=0,int $returned=0,?string $blocker=null):array
    {return [$reason,$candidates,$examined,$returned,$blocker];}
    public static function normalize(mixed $v,int $tick):?array
    {
        if(!is_array($v)||count($v)!==6||array_diff(array_keys($v),['v','at','lookup','dispatch','mode','selected'])!==[]
            ||$v['v']!==1||$v['at']!==$tick||$tick<946684800
            ||!self::validStage($v['lookup'],false)||!self::validStage($v['dispatch'],true)
            ||!is_int($v['selected'])||$v['selected']<0||$v['selected']>18
            ||!in_array($v['mode'],['legacy','batch'],true))return null;
        if($v['mode']==='legacy'&&$v['selected']>3)return null;
        if($v['mode']==='batch'&&($v['selected']<4||$v['lookup'][0]!=='expanded'||$v['dispatch'][0]!=='expanded'
            ||$v['selected']>$v['dispatch'][3]))return null;
        if($v['dispatch'][0]!=='not_run'&&$v['lookup'][3]===0)return null;
        return $v;
    }
    private static function validStage(mixed $s,bool $dispatch):bool
    {
        if(!is_array($s)||!array_is_list($s)||count($s)!==5||!is_string($s[0]))return false;
        foreach([1=>19,2=>3,3=>18]as$i=>$cap)if(!is_int($s[$i])||$s[$i]<0||$s[$i]>$cap)return false;
        [$reason,$count,$examined,$returned,$blocker]=$s;
        if(in_array($reason,['unknown_membership','error'],true)||$reason===($dispatch?'not_run':'batch_unavailable'))
            return $count===0&&$examined===0&&$returned===0&&$blocker===null;
        if($reason==='small_prefix')return $count<=3&&$examined===0&&$returned===0&&$blocker===null;
        if($count<4||$examined<1||$returned>$count)return false;
        if($reason==='expanded')return $examined===3&&$blocker===null;
        if($reason==='ineligible_prefix')return $returned===0&&$blocker===null;
        return $reason==='nonordinary_prefix'&&$returned===0&&in_array($blocker,[
            'protected_original','finite_reserved','historical_origin','event_outside_window','nonordinary_shape'],true);
    }
    public static function projection(mixed $v,int $tick,int $activation,int $now):array
    {
        $v=$tick>=$activation&&$tick<=$now?self::normalize($v,$tick):null;
        if($v===null)return ['available'=>false,'reason'=>'not_recorded_or_invalid'];
        $stage=static fn(array $s):array=>['reason'=>$s[0],'candidate_count'=>$s[1],
            'prefix_examined'=>$s[2],'returned_count'=>$s[3],'blocking_class'=>$s[4]];
        return ['available'=>true,'basis'=>'saved_actual_ordinary_selection_not_financial_permission',
            'checked_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$tick),'lookup'=>$stage($v['lookup']),
            'dispatch'=>$stage($v['dispatch']),'selected_mode'=>$v['mode'],'selected_rows'=>$v['selected'],
            'provider_requests_in_reader'=>0,'not_a_count_of_admissible_conversions'=>true];
    }
}
