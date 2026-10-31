<?php
declare(strict_types=1);

/** Pure candidate linkage inside the admitted prefix; never proves trip identity. */
final class ModeledConversionOfferReaction
{
    private array $rows=[];
    private array $offers=[];
    private array $known=[];
    private array $context=[];
    private int $segment=0;
    private ?string $request=null;

    public function __construct(array $case,array $wrapper,private int $cutoff,private bool $disabled)
    {
        if($disabled)return;
        foreach($case['rows']as$row)$this->rows['journal:'.$row['journal_id']]=$row;
        $journey=$wrapper['offer_journey']??null;
        if(!is_array($journey)||($journey['version']??null)!=='admitted_offer_journey_v1'
            ||!is_array($journey['offers']??null)||count($journey['offers'])>50)return;
        foreach($journey['offers']as$offer){
            if(!is_array($offer)||!is_array($offer['snapshot']??null)||($offer['snapshot']['is_demo']??null)!==false
                ||($offer['snapshot']['price_basis']??null)!=='party'||($offer['snapshot']['price_verified']??null)!==false
                ||!is_string($offer['snapshot']['hotel']??null)||trim($offer['snapshot']['hotel'])===''||strlen($offer['snapshot']['hotel'])>1500)continue;
            $p=$this->receipt($offer['prepared']??null,'tour_offer_prepared','system',$case['subject']);
            $d=$this->receipt($offer['delivery']??null,'tour_offer_delivered','system',$case['subject']);
            if($p===null||$d===null||!self::after($d,$p))continue;
            $binding=$p['data']['binding']??null;
            if(!is_array($binding)||($binding['project_key']??null)!==$case['subject']['project_key']
                ||($binding['channel']??null)!==$case['subject']['platform']
                ||!is_string($binding['external_chat_id']??null)||$binding['external_chat_id']===''
                ||!is_string($binding['external_user_id']??null)||$binding['external_user_id']===''
                ||($p['data']['schema']??null)!==1
                ||!self::keys($p['data'],['binding','schema','snapshot','token_hash'])
                ||!is_string($p['data']['token_hash']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$p['data']['token_hash'])!==1
                ||!is_array($p['data']['snapshot']??null)
                ||self::ordered($p['data']['snapshot'])!==self::ordered($offer['snapshot'])
                ||!self::keys($d['data'],['native_chat_id','native_mid','offer_id'])
                ||!is_int($d['data']['offer_id']??null)||(string)$d['data']['offer_id']!==$p['source_id']
                ||($d['data']['native_chat_id']??null)!==$binding['external_chat_id']
                ||!is_string($d['data']['native_mid']??null)||$d['data']['native_mid']===''
                ||($case['subject']['platform']==='telegram'&&$binding['external_chat_id']!==$binding['external_user_id']))continue;
            $this->offers[]=['prepared'=>$p,'delivery'=>$d,'hotel'=>$offer['snapshot']['hotel']];
            $this->known[$p['ref']]=$this->known[$d['ref']]=true;
            $like=$this->receipt($offer['reaction']??null,'tour_offer_liked','customer',$case['subject']);
            if($like!==null&&self::after($like,$d)&&self::keys($like['data'],['offer_id'])&&is_int($like['data']['offer_id']??null)
                &&(string)$like['data']['offer_id']===$p['source_id'])$this->known[$like['ref']]=true;
        }
    }

    public function unavailable(string $ref,bool $longGap):void
    {
        if($this->disabled)return;
        if($longGap||!isset($this->known[$ref])){$this->segment++;$this->request=null;}
        $this->context[$ref]=['segment'=>$this->segment,'request'=>$this->request];
    }

    public function message(string $ref,string $role,int $at,int $available,string $text,array $semantic):void
    {
        if($this->disabled)return;
        if(($this->rows[$ref]['source_version_no']??null)!==1){$this->segment++;$this->request=null;return;}
        $flags=$semantic['flags'];
        if($semantic['episode_boundary']!==null||($flags['unresolved_trip_boundary']??false)
            ||($flags['party_change_unresolved']??false)||($flags['product_changed_or_alternative']??false)
            ||($flags['long_observation_gap']??false)){$this->segment++;$this->request=null;}
        foreach($semantic['events']as$e)if($role==='customer'&&$e['goal']==='substantive_interest'&&$this->request===null)$this->request=$ref;
        $this->context[$ref]=['segment'=>$this->segment,'request'=>$this->request,'role'=>$role,
            'at'=>$at,'available'=>$available,'text'=>$text,'flags'=>$flags];
    }

    public function project():array
    {
        $links=[];$byMessage=[];$omissions=['ambiguous_named_offer'=>0];
        $preparedCounts=array_count_values(array_column(array_column($this->offers,'prepared'),'ref'));
        foreach($this->offers as$offer){
            $p=$offer['prepared'];$d=$offer['delivery'];$pc=$this->context[$p['ref']]??null;$dc=$this->context[$d['ref']]??null;
            if($preparedCounts[$p['ref']]!==1)continue;
            $q=is_array($pc)?($pc['request']??null):null;$qc=$q!==null?($this->context[$q]??null):null;
            if($qc===null||$dc===null||$pc['segment']!==$dc['segment']||$q!==$dc['request']
                ||$qc['segment']!==$pc['segment']||$qc['at']>=$p['at'])continue;
            $refs=[$q,$p['ref'],$d['ref']];$available=max($qc['available'],$p['available'],$d['available']);
            $links[]=['source_refs'=>$refs,'available_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$available),
                'request_binding'=>'same_observed_candidate_segment_not_verified_trip'];
            foreach($this->context as$ref=>$c){
                if(($c['role']??null)!=='customer'||$c['segment']!==$dc['segment']||$c['request']!==$q
                    ||$c['at']<=$d['at']||$ref===$q)continue;
                $flags=$c['flags'];
                if(($flags['past_trip']??false)||($flags['quoted_or_relayed']??false)
                    ||($flags['conditional']??false)||($flags['negation']??false))continue;
                $text=$c['text'];
                // Name alone is not discussion. Require a direct price objection AND
                // an explicit request for cheaper alternatives, tied to a read snapshot.
                $pattern='~(?<![\pL\pN])'.preg_quote($offer['hotel'],'~').'(?![\pL\pN])'
                    .'\s*[,:\-–—]?\s*(?:слишком\s+)?(?:дорого(?:вато)?|дороже|дорогой|дорогая|дорогие|дороговат\pL*)(?!\pL)'
                    .'[,.!?;:\s]+(?:найдите|подберите|поищите|давайте)\s+(?:нам\s+)?(?:(?:тур|отель|вариант\pL*)\s+)?дешевле(?!\pL)~iu';
                if(preg_match($pattern,$text)!==1)continue;
                $byMessage[$ref][]=['source_refs'=>[...$refs,$ref],
                    'available_at'=>gmdate('Y-m-d\\TH:i:s\\Z',max($available,$c['available'])),
                    'reaction'=>'price_alternative_request','search_continuation'=>'explicit_request',
                    'request_binding'=>'same_observed_candidate_segment_not_verified_trip',
                    'current_candidate'=>$c['segment']===$this->segment];
            }
        }
        $discussions=[];
        foreach($byMessage as$candidates){
            if(count($candidates)!==1){$omissions['ambiguous_named_offer']++;continue;}
            $discussions[]=$candidates[0];
        }
        return ['request_offer_links'=>$links,'specific_option_discussions'=>$discussions,'linking_omissions'=>$omissions];
    }

    private function receipt(mixed $ref,string $kind,string $actor,array $subject):?array
    {
        if(!is_array($ref)||!is_string($ref['journal_id']??null)||!is_string($ref['source_id']??null)
            ||preg_match('/\A[1-9][0-9]{0,19}\z/D',$ref['journal_id'])!==1
            ||preg_match('/\A[1-9][0-9]{0,19}\z/D',$ref['source_id'])!==1||($ref['source_version_no']??null)!==1)return null;
        $key='journal:'.$ref['journal_id'];$row=$this->rows[$key]??null;$s=$row['snapshot']??null;
        if(!is_array($row)||!is_array($s)||($row['source_kind']??null)!=='event'
            ||($s['source_kind']??null)!=='event'||($s['source_status']??null)!=='present'||($s['payload_status']??null)!=='copied'
            ||($row['source_version_no']??null)!==1||(string)($row['source_id']??'')!==$ref['source_id']
            ||(string)($s['source_id']??'')!==$ref['source_id'])return null;
        foreach(['project_key','platform','customer_key','conversation_id']as$k)
            if((string)($s[$k]??'')!==$subject[$k])return null;
        $at=$s['source_created_at_unix']??null;$available=$row['observed_at_unix']??null;
        if(!is_int($at)||!is_int($available)||$at<946684800||$at>$available||$available>$this->cutoff
            ||($ref['recorded_at']??null)!==gmdate('Y-m-d\\TH:i:s\\Z',$at)
            ||($ref['available_at']??null)!==gmdate('Y-m-d\\TH:i:s\\Z',$available))return null;
        $payload=$s['payload']??null;
        if(!is_array($payload)||($payload['event_type']??null)!==$kind||($payload['actor_type']??null)!==$actor
            ||!is_string($payload['payload_json']??null))return null;
        try{$data=json_decode($payload['payload_json'],true,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){return null;}
        if(!is_array($data)||array_is_list($data))return null;
        $basis=$s['source_timestamp_basis']??null;
        if($basis==='canonical_recording_unix_v1'){
            if(!is_array($data['_recorded_clock']??null)
                ||self::ordered($data['_recorded_clock'])!==self::ordered(['version'=>1,'basis'=>'server_recording_unix','unix'=>$at]))return null;
            unset($data['_recorded_clock']);
        }elseif($basis!=='database_session_timezone_to_unix'||array_key_exists('_recorded_clock',$data))return null;
        return ['ref'=>$key,'source_id'=>$ref['source_id'],'at'=>$at,'available'=>$available,'data'=>$data];
    }
    private static function after(array $a,array $b):bool
    {
        if($a['at']!==$b['at'])return $a['at']>$b['at'];
        return strlen($a['source_id'])!==strlen($b['source_id'])?strlen($a['source_id'])>strlen($b['source_id']):strcmp($a['source_id'],$b['source_id'])>0;
    }
    private static function ordered(array $a):array
    {
        if(!array_is_list($a))ksort($a,SORT_STRING);
        foreach($a as&$v)if(is_array($v))$v=self::ordered($v);
        return $a;
    }
    private static function keys(array $a,array $expected):bool
    {
        $keys=array_keys($a);sort($keys,SORT_STRING);return $keys===$expected;
    }
}
