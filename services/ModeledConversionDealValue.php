<?php
declare(strict_types=1);

/** Pure monetary projection of admitted evidence; estimates are not a quote, income or bank receipt. */
final class ModeledConversionDealValue
{
    public const VERSION='early_deal_commission_7pct_v2';
    public const COMMISSION_RATE=0.07;
    public const CAP=100000;
    public const UNKNOWN_BASE=10000;
    private const LEGACY_VERSION='early_deal_commission_7pct_v1';
    private const LEGACY_RULES_SHA256='f4f205793ce51a18f7268292df3e0362b152869d60c71a5bc9c62017ca136593';

    /** Monetary policy belongs to the frozen version; existing capsules are never re-priced. */
    public static function policy(string $version):?array
    {
        return match($version){
            self::LEGACY_VERSION=>['cap_rub'=>25000,'unknown_base_rub'=>25000,'unknown_base_basis'=>'existing_25000_fallback'],
            self::VERSION=>['cap_rub'=>self::CAP,'unknown_base_rub'=>self::UNKNOWN_BASE,'unknown_base_basis'=>'provisional_commission_10000'],
            default=>null,
        };
    }

    /** Explicit RUB publication amounts only; no FX, bare-number or nightly-price inference. */
    public static function publicationRange(string $text):?array
    {
        if(strlen($text)>8192||preg_match('//u',$text)!==1)return null;
        if(preg_match('~за\s+(?:ночь|сутки)|в\s+сутки|за\s+номер|только\s+проживан~iu',$text)===1)return null;
        $n='(?:\d{1,3}(?:[ \x{00a0}\x{202f}]\d{3})+|\d{2,8})(?:[.,]\d{1,2})?';
        preg_match_all('~(?<![\pL\pN])('.$n.')(?:\s*[-–—]\s*('.$n.'))?\s*(тыс(?:яч\pL*)?\.?)?\s*(?:₽|руб\pL*|RUB)(?!\pL)~iu',$text,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
        if(!$matches||count($matches)>8)return null;
        $lows=[];$highs=[];$floorOnly=false;
        foreach($matches as$m){
            $scale=isset($m[3][0])&&$m[3][0]!==''?1000:1;
            $parse=static fn(string $v):int=>(int)round((float)str_replace(',','.',preg_replace('/[ \x{00a0}\x{202f}]/u','',$v))*$scale);
            $lo=$parse($m[1][0]);$hasHigh=isset($m[2][0])&&$m[2][0]!=='';$hi=$hasHigh?$parse($m[2][0]):$lo;
            if($lo<10000||$hi<$lo||$hi>10000000)return null;
            $lows[]=$lo;$highs[]=$hi;
            $before=substr($text,max(0,$m[0][1]-16),min(16,$m[0][1]));
            if(!$hasHigh&&preg_match('~(?:^|[^\pL])от\s*$~iu',$before)===1)$floorOnly=true;
        }
        $perPerson=preg_match('~за\s+(?:одного\s+)?(?:человека|туриста)|на\s+(?:одного|1)\s+(?:человека|туриста)~iu',$text)===1;
        $party=null;
        if(preg_match('~(?:за|на)\s*(\d{1,2})\s*(?:взросл\pL*|чел\pL*|турист\pL*)~iu',$text,$m)===1)$party=(int)$m[1];
        elseif(preg_match('~(?:за|на)\s+двоих~iu',$text)===1)$party=2;
        $low=min($lows);$high=$floorOnly?null:max($highs);
        return ['low'=>$low,'high'=>$high,'point'=>$high===null?$low:(int)round(($low+$high)/2),
            'unit'=>$perPerson?'per_person':($party!==null?'party_total':'unknown'),'party_size'=>$party,
            'basis'=>$floorOnly?'advertised_floor_proxy':'advertised_range_proxy'];
    }

    public static function freeze(array $wrapper,array $case):array
    {
        $p=$wrapper['semantic_prediction']??[];$subject=$wrapper['subject']??[];
        $cutoff=self::moment($p['input_cutoff_at']??null);$freeze=self::moment($p['frozen_at']??null);
        $policy=self::policy(self::VERSION);
        $out=['version'=>self::VERSION,'rules_sha256'=>hash_file('sha256',__FILE__),'subject'=>$subject,
            'semantic_prediction_digest'=>$p['prediction_digest']??null,'current_request_anchor'=>$p['current_request_anchor']??null,
            'profile_prediction_at'=>$p['profile_prediction_at']??null,'input_cutoff_at'=>$p['input_cutoff_at']??null,
            'frozen_at'=>$p['frozen_at']??null,'commission_rate'=>self::COMMISSION_RATE,'currency'=>'RUB',
            'status'=>'unavailable','reason'=>'insufficient_context','early_window'=>'unknown','age_seconds'=>null,
            'deal_amount_rub'=>['low'=>null,'high'=>null,'point'=>null,'basis'=>'unknown','source_kind'=>'unknown','source_refs'=>[]],
            'commission_estimate_rub'=>null,'full_value_base_rub'=>$policy['unknown_base_rub'],'base_basis'=>$policy['unknown_base_basis'],
            'estimated_probability'=>null,'value_rub'=>null,'payment_hold_observed'=>false,
            'calibration_status'=>'expert_estimate_not_calibrated','price_verified'=>false,'trip_identity'=>'unverified',
            'export_allowed'=>false,'model_calls'=>0,'conversion_uploads'=>0];
        if(($wrapper['access_status']??null)!=='allowed'||$cutoff===null||$freeze===null)return self::seal($out);
        $anchor=null;
        foreach($p['evidence']??[]as$e)if(($e['feature']??null)==='current_request_anchor')$anchor=self::moment($e['occurred_at']??null);
        if($anchor!==null&&$anchor<=$freeze){
            $out['age_seconds']=$freeze-$anchor;
            $out['early_window']=$freeze-$anchor<=3600?'first_hour':($freeze-$anchor<=10800?'first_three_hours':($freeze-$anchor<=86400?'first_day':'later_update'));
        }
        $admitted=[];
        foreach($wrapper['profile']['observation_input']['evidence']??[]as$e)foreach($e['references']??[]as$r)
            if(in_array($e['kind']??null,['customer_message','manager_message'],true)&&is_string($r['id']??null))
                $admitted[$r['id']]=['at'=>self::moment($r['at']??null),'available'=>self::moment($r['available_at']??null),'role'=>$e['kind']];
        $rows=[];$entry=null;$budget=null;$quote=null;$budgetAt=0;$holdAt=null;$postedAt=null;
        foreach($case['rows']??[]as$row){
            $jid=(string)($row['journal_id']??'');$fence=(string)($p['source_fence_journal_id']??'0');
            if(preg_match('/\A[1-9][0-9]{0,19}\z/D',$jid)!==1||strlen($jid)>strlen($fence)||(strlen($jid)===strlen($fence)&&strcmp($jid,$fence)>0))continue;
            $s=$row['snapshot']??[];$at=$s['source_created_at_unix']??null;$available=$row['observed_at_unix']??null;
            if(!is_int($at)||!is_int($available)||$at>$available||$available>$cutoff
                ||($s['source_status']??null)!=='present'||($s['payload_status']??null)!=='copied')continue;
            foreach($subject as$k=>$v)if((string)($s[$k]??'')!==$v)continue 2;
            $ref='journal:'.($row['journal_id']??'');$payload=$s['payload']??[];
            $rows[$ref]=$row;
            if(($row['source_kind']??null)==='event'&&($payload['event_type']??null)==='channel_post_entry'
                &&($payload['actor_type']??null)==='customer'){
                $context=$payload['_modeled_price_context']??null;
                if(is_array($context)&&($context['version']??null)==='entry_price_context_v1'
                    &&($context['captured_at_unix']??null)===$available
                    &&is_array($context['range']??null)&&($entry===null||$at>$entry['at']))
                    $entry=['at'=>$at,'ref'=>$ref,'range'=>$context['range']];
            }
            if(($row['source_kind']??null)==='event'&&($row['source_version_no']??null)===1
                &&($payload['event_type']??null)==='tour_web_inquiry'&&($payload['actor_type']??null)==='customer'){
                try{$inquiry=json_decode($payload['payload_json']??'',true,24,JSON_THROW_ON_ERROR);}catch(Throwable $ignored){$inquiry=null;}
                $o=is_array($inquiry)?($inquiry['public_offer']??null):null;
                if(is_array($o)&&($inquiry['schema']??null)===1&&is_string($inquiry['publication_id']??null)
                    &&preg_match('/\A[a-f0-9]{32}\z/D',$inquiry['publication_id'])===1
                    &&is_int($inquiry['variant']??null)&&$inquiry['variant']>=1&&$inquiry['variant']<=20
                    &&($o['currency']??null)==='RUB'&&is_string($o['amount']??null)
                    &&preg_match('/\A[1-9][0-9]{3,7}(?:\.00)?\z/D',$o['amount'])===1
                    &&(int)$o['amount']>=10000&&(int)$o['amount']<=10000000
                    &&($anchor===null||$at>=$anchor-86400)&&($quote===null||$at>$quote['at']))
                    $quote=['at'=>$at,'value'=>['low'=>(int)$o['amount'],'high'=>(int)$o['amount'],'point'=>(int)$o['amount'],
                        'basis'=>'native_hotel_inquiry_observation','source_kind'=>'native_hotel_inquiry','source_refs'=>[$ref]]];
            }
            if(isset($admitted[$ref])&&$admitted[$ref]['at']===$at&&$admitted[$ref]['available']===$available
                &&is_string($payload['text']??null)&&strlen($payload['text'])<=32768){
                $t=$payload['text'];
                $heldNoun=preg_match('~замороженн\pL*\s+(?:денежн\pL*\s+)?средств\pL*~iu',$t)===1;
                $heldState=preg_match('~(?:деньг\pL*|средств\pL*|сумм\pL*).{0,30}(?:заморожены|заморожена|заблокированы)|(?:установлен|действует)\s+холд~iu',$t)===1;
                $futureHold=preg_match('~(?:деньг\pL*|средств\pL*|сумм\pL*).{0,20}(?:будут|будет|могут).{0,20}(?:заморож|заблокир)~iu',$t)===1;
                if($heldNoun||($heldState&&!$futureHold))$holdAt=max($holdAt??0,$at);
                $posting=preg_match('~(?:деньг\pL*|средств\pL*|оплат\pL*).{0,30}(?:успешно\s+списаны|окончательно\s+списаны|поступили|зачислены|зачислена)~iu',$t)===1;
                $uncertainPosting=preg_match('~\b(?:если|когда|будут|будет)\b|не\s+(?:поступ|спис|зачис)~iu',$t)===1;
                if($posting&&!$uncertainPosting)$postedAt=max($postedAt??0,$at);
            }
        }
        $out['payment_hold_observed']=$holdAt!==null&&($postedAt===null||$postedAt<=$holdAt);
        $field=$wrapper['profile']['fields']['budget']??null;
        if(is_array($field)&&($field['status']??null)==='known'&&($field['usable_at_prediction']??null)===true
            &&self::moment($field['available_at']??null)!==null&&self::moment($field['available_at'])<=$freeze){
            $v=$field['value']??[];$refs=[];$valid=true;
            foreach($field['references']??[]as$r){
                $ref=$r['id']??null;
                if(!is_string($ref)||!isset($admitted[$ref])||$admitted[$ref]['role']!=='customer_message'
                    ||($anchor!==null&&$admitted[$ref]['at']<$anchor)){$valid=false;break;}$refs[]=$ref;$budgetAt=max($budgetAt,$admitted[$ref]['at']??0);
            }
            if($valid&&$refs&&($v['currency']??null)==='RUB'&&($v['unit']??null)==='party_total'){
                $lo=$v['minimum']??null;$hi=$v['maximum']??null;
                if(self::range($lo,$hi))$budget=['low'=>$lo,'high'=>$hi,'point'=>$lo!==null&&$hi!==null?(int)round(($lo+$hi)/2):($hi??$lo),
                    'basis'=>$lo===null?'customer_budget_ceiling_proxy':($hi===null?'customer_budget_floor_proxy':'customer_budget_range_proxy'),
                    'source_kind'=>'customer_budget','source_refs'=>array_values(array_unique($refs))];
            }
        }
        // Native delivered offer receipts remain observational prices, never an accepted contract.
        foreach($wrapper['offer_journey']['offers']??[]as$offer){
            if(!in_array($offer['state']??null,['delivered','liked'],true)||($offer['snapshot']['currency']??null)!=='RUB'
                ||($offer['snapshot']['price_basis']??null)!=='party')continue;
            $prepared='journal:'.($offer['prepared']['journal_id']??'');$delivered='journal:'.($offer['delivery']['journal_id']??'');
            $a=$rows[$prepared]['snapshot']['source_created_at_unix']??null;$d=$rows[$delivered]['snapshot']['source_created_at_unix']??null;
            if($anchor===null||!is_int($a)||!is_int($d)||$a<$anchor||$d<$a||$d>$cutoff)continue;
            $raw=$offer['snapshot']['amount']??null;
            if(!is_string($raw)||preg_match('/\A[1-9][0-9]{3,7}(?:\.00)?\z/D',$raw)!==1)continue;
            $amount=(int)$raw;if($amount<10000||$amount>10000000)continue;
            if($quote===null||$d>$quote['at'])$quote=['at'=>$d,'value'=>['low'=>$amount,'high'=>$amount,'point'=>$amount,
                'basis'=>'delivered_offer_observation','source_kind'=>'delivered_offer','source_refs'=>[$prepared,$delivered]]];
        }
        $deal=$quote!==null&&($budget===null||$quote['at']>=$budgetAt)?$quote['value']:$budget;
        if($deal===null&&$entry!==null&&$anchor!==null&&$entry['at']<=$anchor&&$anchor-$entry['at']<=86400){
            $r=$entry['range'];
            $party=$wrapper['profile']['party']['total']??[];
            $knownTotal=($party['status']??null)==='known'&&is_int($party['value']??null)?$party['value']:null;
            $partyCompatible=$knownTotal===null||($r['party_size']??null)===$knownTotal;
            if($partyCompatible&&self::range($r['low']??null,$r['high']??null)&&($r['unit']??null)==='party_total'
                &&is_int($r['point']??null)&&$r['point']>=10000&&$r['point']<=10000000)
                $deal=['low'=>$r['low'],'high'=>$r['high'],'point'=>$r['point'],'basis'=>$r['basis'],
                    'source_kind'=>'channel_post_range','source_refs'=>[$entry['ref']]];
        }
        if($deal!==null){
            $out['deal_amount_rub']=$deal;$out['commission_estimate_rub']=(int)round($deal['point']*self::COMMISSION_RATE);
            $out['full_value_base_rub']=min(self::CAP,$out['commission_estimate_rub']);$out['base_basis']='estimated_commission_7pct_capped';
        }
        $prob=$p['estimated_probability']??null;
        if(($p['status']??null)==='predicted'&&(is_float($prob)||is_int($prob))&&$prob>0&&$prob<=1){
            $out['status']='estimated';$out['reason']=$deal===null?'deal_price_unknown_provisional_base':'stage_dialogue_and_estimated_commission';
            $out['estimated_probability']=$prob;$out['value_rub']=(int)round($prob*$out['full_value_base_rub']);
        }
        return self::seal($out);
    }

    /** Recheck immutable numeric contract before a new export or private aggregate display. */
    public static function saved(array $wrapper):?array
    {
        $v=$wrapper['deal_value_prediction']??null;$p=$wrapper['semantic_prediction']??null;
        if(!is_array($v)||!is_array($p)||($wrapper['access_status']??null)!=='allowed'||strlen(json_encode($v))>8192
            ||!is_string($v['version']??null))return null;
        $policy=self::policy($v['version']);
        $rulesSha256=$v['version']===self::VERSION?hash_file('sha256',__FILE__):self::LEGACY_RULES_SHA256;
        if($policy===null||($v['rules_sha256']??null)!==$rulesSha256
            ||!self::sameSubject($v['subject']??null,$wrapper['subject']??null)||($v['semantic_prediction_digest']??null)!==($p['prediction_digest']??null)
            ||($v['commission_rate']??null)!==self::COMMISSION_RATE||($v['currency']??null)!=='RUB'
            ||($v['frozen_at']??null)!==($p['frozen_at']??null)||($v['input_cutoff_at']??null)!==($p['input_cutoff_at']??null)
            ||($v['current_request_anchor']??null)!==($p['current_request_anchor']??null)||!is_bool($v['payment_hold_observed']??null)
            ||!is_string($v['deal_digest']??null))return null;
        $allowed=['version','rules_sha256','subject','semantic_prediction_digest','current_request_anchor','profile_prediction_at','input_cutoff_at','frozen_at',
            'commission_rate','currency','status','reason','early_window','age_seconds','deal_amount_rub','commission_estimate_rub','full_value_base_rub','base_basis',
            'estimated_probability','value_rub','payment_hold_observed','calibration_status','price_verified','trip_identity','export_allowed','model_calls','conversion_uploads','deal_digest'];
        if(count($v)!==count($allowed)||array_diff(array_keys($v),$allowed)
            ||($v['calibration_status']??null)!=='expert_estimate_not_calibrated'||($v['price_verified']??null)!==false
            ||($v['trip_identity']??null)!=='unverified'||($v['export_allowed']??null)!==false
            ||($v['model_calls']??null)!==0||($v['conversion_uploads']??null)!==0
            ||!in_array($v['early_window']??null,['unknown','first_hour','first_three_hours','first_day','later_update'],true))return null;
        $d=$v['deal_amount_rub']??null;
        if(!is_array($d)||count($d)!==6||array_diff(array_keys($d),['low','high','point','basis','source_kind','source_refs'])
            ||!in_array($d['source_kind']??null,['unknown','customer_budget','delivered_offer','native_hotel_inquiry','channel_post_range'],true)
            ||!is_array($d['source_refs']??null)||count($d['source_refs'])>50)return null;
        foreach($d['source_refs']as$ref)if(!is_string($ref)||preg_match('/\Ajournal:[1-9][0-9]{0,19}\z/D',$ref)!==1)return null;
        $given=$v['deal_digest'];$copy=$v;unset($copy['deal_digest']);
        if(!hash_equals($given,ModeledConversionSemanticPrediction::digest($copy)))return null;
        $d=$v['deal_amount_rub']??[];$point=$d['point']??null;
        if($point===null){
            if(($v['commission_estimate_rub']??null)!==null||($v['full_value_base_rub']??null)!==$policy['unknown_base_rub']
                ||($v['base_basis']??null)!==$policy['unknown_base_basis'])return null;
        }else{
            if(!is_int($point)||!self::range($d['low']??null,$d['high']??null)
                ||($d['low']!==null&&$point<$d['low'])||($d['high']!==null&&$point>$d['high'])
                ||($v['commission_estimate_rub']??null)!==(int)round($point*self::COMMISSION_RATE)
                ||($v['full_value_base_rub']??null)!==min($policy['cap_rub'],(int)round($point*self::COMMISSION_RATE)))return null;
        }
        if(($v['status']??null)==='estimated'){
            if(($p['status']??null)!=='predicted'||($v['estimated_probability']??null)!==($p['estimated_probability']??null)
                ||($v['value_rub']??null)!==(int)round($p['estimated_probability']*$v['full_value_base_rub']))return null;
        }elseif(($v['value_rub']??null)!==null||($v['estimated_probability']??null)!==null)return null;
        return $v;
    }
    private static function sameSubject(mixed $a,mixed $b):bool
    {if(!is_array($a)||!is_array($b)||count($a)!==4||count($b)!==4)return false;
        foreach(['project_key','platform','customer_key','conversation_id']as$k)if(($a[$k]??null)!==($b[$k]??null))return false;return true;}
    private static function range(mixed $lo,mixed $hi):bool
    {return ($lo!==null||$hi!==null)&&($lo===null||is_int($lo)&&$lo>=10000&&$lo<=10000000)
        &&($hi===null||is_int($hi)&&$hi>=10000&&$hi<=10000000)&&($lo===null||$hi===null||$lo<=$hi);}
    private static function seal(array $v):array{$v['deal_digest']=ModeledConversionSemanticPrediction::digest($v);return $v;}
    private static function moment(mixed $v):?int
    {if(!is_string($v)||preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/D',$v)!==1)return null;$t=strtotime($v);return $t===false?null:$t;}
}
