<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionRetainedTrackerIdentityEvidence.php';
require_once __DIR__.'/ModeledConversionAttributionWindow.php';

/** Inert finite evidence for two original cases. Never resolves identity, sends money, or retries an IO. */
final class ModeledConversionKnownYclidRelation
{
    public const PURPOSE='retained_known_yclid_two_stage_v1';
    public const TABLE='modeled_conversion_known_yclid_relations';
    public const TTL=600;
    private const PLAN_KEYS=['v','case_index','namespace','frame_digest','event_ref','outbox_id','value_rub','event_unix',
        'held_client_id','held_record_id','held_source_at','old_saved_sha256','selection'];
    public function __construct(private PDO $pdo){}

    private static function canonical(mixed $v):string
    {
        $sort=static function(mixed $x)use(&$sort):mixed{
            if(is_array($x)){if(!array_is_list($x))ksort($x);foreach($x as&$item)$item=$sort($item);unset($item);}return $x;
        };
        return json_encode($sort($v),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    private static function storedPlan(mixed $raw):?array
    {
        $p=self::decode($raw,4096);$ctx=$p['selection']['selection_context']??null;
        $keys=['v','user_id','event_ref','frame_sha256','event_at_unix'];
        if($p===null||!is_array($ctx)||count($ctx)!==5||array_diff(array_keys($ctx),$keys))return null;
        $ordered=[];foreach($keys as$k)$ordered[$k]=$ctx[$k];$p['selection']['selection_context']=$ordered;
        return $p;
    }
    public static function planHash(array $plan):string{return hash('sha256',self::canonical($plan));}
    private static function planValid(array $p,int $now):bool
    {
        if(count($p)!==count(self::PLAN_KEYS)||array_diff(array_keys($p),self::PLAN_KEYS)||($p['v']??null)!==1
            ||!in_array($p['case_index']??null,[1,2],true)||!MaxTrackerLookupProtocol::id($p['outbox_id']??null)
            ||!MaxTrackerLookupProtocol::id($p['held_client_id']??null,40)||!MaxTrackerLookupProtocol::id($p['held_record_id']??null)
            ||!is_int($p['event_unix']??null)||$p['event_unix']<946684800||$p['event_unix']>$now
            ||!is_int($p['held_source_at']??null)||$p['held_source_at']<946684800||$p['held_source_at']>$p['event_unix']
            ||!is_int($p['value_rub']??null)||$p['value_rub']<=0||$p['value_rub']>ModeledConversionValueContract::MAX_VALUE_RUB
            ||!is_array($p['selection']??null))return false;
        foreach(['namespace','frame_digest','event_ref','old_saved_sha256']as$k)
            if(!is_string($p[$k]??null)||preg_match('/\A[a-f0-9]{64}\z/D',$p[$k])!==1)return false;
        try{
            MaxTrackerLookupProtocol::retainedClickRehydrateBody($p['selection'],$now,str_repeat('0',32));
            return $p['selection']['selection_context']['event_ref']===$p['event_ref']
                &&$p['selection']['selection_context']['frame_sha256']===$p['frame_digest']
                &&$p['selection']['selection_context']['event_at_unix']===$p['event_unix']&&strlen(self::canonical($p))<=4096;
        }catch(Throwable $ignored){return false;}
    }
    private static function hash(string $s):bool{return preg_match('/\A[a-f0-9]{64}\z/D',$s)===1;}
    private static function rawMatches(array $p,string $raw,int $now):bool
    {
        if(!self::planValid($p,$now)||!MaxTrackerLookupProtocol::id($raw,40))return false;$s=$p['selection'];
        $candidate=hash('sha256',json_encode([MaxTrackerLookupProtocol::RETAINED_CLICK_HEADER,$s['selection_request_sha256'],
            $s['selection_context'],2,$raw,$s['selected_record_id'],$s['selected_record_sha256'],$s['selected_recorded_at_unix']],
            JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return hash_equals($s['selected_candidate_sha256'],$candidate);
    }

    private static function uint64(mixed $v):bool
    {
        return MaxTrackerLookupProtocol::id($v)&&(strlen($v)<20||(strlen($v)===20&&strcmp($v,'18446744073709551615')<=0));
    }
    private static function clickBounds(array $p):array
    {
        $at=$p['selection']['selected_recorded_at_unix'];$start=(int)(floor($at/86400)*86400);
        return [$start,min($start+86399,$p['event_unix'])];
    }
    /** R1 links only the recorded click UTC day. Zero never means global absence of a relation or visit. */
    public static function r1Query(array $p,string $raw,int $intent):?array
    {
        if($intent<946684800||!self::rawMatches($p,$raw,$intent))return null;[$start,$end]=self::clickBounds($p);
        $regex='^https?://[^?#]+[?]([^#]*&)?yclid='.$raw.'([&#]|$)';
        return ['ids'=>'98615635','metrics'=>'ym:s:visits','dimensions'=>'ym:s:clientID',
            'filters'=>"ym:s:startURL=~'".$regex."' AND ym:s:dateTime>='".gmdate('Y-m-d H:i:s',$start)
                ."' AND ym:s:dateTime<='".gmdate('Y-m-d H:i:s',$end)."'",
            'timezone'=>'+00:00','date1'=>gmdate('Y-m-d',$start),'date2'=>gmdate('Y-m-d',$start),
            'accuracy'=>'full','limit'=>2,'offset'=>1,'include_undefined'=>true];
    }
    public static function queryHash(string $stage,array $query):string{return hash('sha256',self::canonical([self::PURPOSE,$stage,$query]));}
    private static function unavailable(string $reason):array{return ['status'=>'unavailable','reason'=>$reason,'financial_permission'=>false];}
    /** Validates the retained complete query response, not completeness of unfiltered visits. */
    private static function capsule(array $query,string $stage,int $intent,mixed $saved,int $cap,int $upper):array
    {
        if(!is_array($saved)||count($saved)!==10||array_diff(array_keys($saved),
            ['v','stage','query_sha256','request_query','requested_at_unix','checked_at_unix','http','curl','response','failure'])
            ||($saved['v']??null)!==2||($saved['stage']??null)!==$stage
            ||($saved['query_sha256']??null)!==self::queryHash($stage,$query)
            ||!is_array($saved['request_query']??null)||self::canonical($saved['request_query'])!==self::canonical($query)
            ||!is_int($saved['requested_at_unix']??null)||$saved['requested_at_unix']<$intent
            ||!is_int($saved['checked_at_unix']??null)||$saved['checked_at_unix']<$saved['requested_at_unix']||$saved['checked_at_unix']>$intent+self::TTL
            ||strlen(self::canonical($saved))>$cap)return ['error'=>'invalid_capsule'];
        if($saved['failure']!==null||$saved['http']!==200||$saved['curl']!==0)return ['error'=>'provider_unavailable'];
        $d=$saved['response'];$echo=is_array($d)?($d['query']??null):null;
        if(!is_array($echo)||!is_array($echo['ids']??null)||array_map('strval',$echo['ids'])!==['98615635']
            ||($echo['metrics']??null)!==explode(',',$query['metrics'])||($echo['dimensions']??null)!==explode(',',$query['dimensions'])
            ||($echo['filters']??null)!==$query['filters']||($echo['timezone']??null)!=='+00:00'
            ||($echo['date1']??null)!==$query['date1']||($echo['date2']??null)!==$query['date2']
            ||($echo['limit']??null)!==$query['limit']||($echo['offset']??null)!==1
            ||(array_key_exists('accuracy',$echo)&&$echo['accuracy']!=='full')
            ||(array_key_exists('include_undefined',$echo)&&$echo['include_undefined']!==true))return ['error'=>'query_echo_unavailable'];
        if(($d['sampled']??null)!==false||($d['total_rows_rounded']??null)!==false||($d['contains_sensitive_data']??null)!==false
            ||!is_int($d['total_rows']??null)||$d['total_rows']<0||!is_array($d['data']??null)||!array_is_list($d['data'])
            ||count($d['data'])>$query['limit']||!is_int($d['data_lag']??null)||$d['data_lag']<0
            ||$saved['checked_at_unix']-$d['data_lag']<$upper)return ['error'=>'incomplete_provider_data'];
        return ['error'=>null,'data'=>$d];
    }
    private static function dimension(mixed $x):?string
    {
        if(!is_array($x)||!is_string($x['name']??null)||(array_key_exists('id',$x)&&$x['id']!==null&&$x['id']!==$x['name']))return null;
        return $x['name'];
    }
    private static function visits(mixed $m):?int
    {
        if(!is_array($m)||!array_is_list($m)||count($m)!==1||(!is_int($m[0])&&!is_float($m[0]))
            ||!is_finite((float)$m[0])||$m[0]<1||$m[0]>1000000000000||(float)$m[0]!==floor((float)$m[0]))return null;
        return (int)$m[0];
    }
    public static function validateR1(array $p,string $raw,int $intent,mixed $saved):array
    {
        try{
            $q=self::r1Query($p,$raw,$intent);if($q===null)return self::unavailable('invalid_r1_binding');
            [$start,$upper]=self::clickBounds($p);$proof=self::capsule($q,'click_day_cid',$intent,$saved,16384,$upper);
            if($proof['error']!==null)return self::unavailable($proof['error']);$d=$proof['data'];
            $base=['coverage_scope'=>'recorded_click_utc_day_only','recorded_day_utc'=>gmdate('Y-m-d',$start),'financial_permission'=>false];
            if($d['total_rows']>2)return self::unavailable('scoped_row_limit');
            if(count($d['data'])!==$d['total_rows'])return self::unavailable('incomplete_provider_data');
            if($d['total_rows']===0)return ['status'=>'no_match_in_recorded_click_utc_day','reason'=>null]+$base;
            foreach($d['data']as$row){$dims=$row['dimensions']??null;
                if(!is_array($dims)||!array_is_list($dims)||count($dims)!==1||!self::uint64(self::dimension($dims[0]))||self::visits($row['metrics']??null)===null)return self::unavailable('invalid_relation_row');}
            if($d['total_rows']!==1)return ['status'=>'ambiguous_recorded_day_relation','reason'=>null]+$base;
            $cid=self::dimension($d['data'][0]['dimensions'][0]);
            return ['status'=>'unique_cid_in_recorded_day','reason'=>null,'client_id'=>$cid,'held_value_match'=>$cid===$p['held_client_id']]+$base;
        }catch(Throwable $ignored){return self::unavailable('invalid_capsule');}
    }
    /** R2 takes its only CID from the complete raw R1 proof; the old held CID is never queried again. */
    public static function r2Query(array $p,string $raw,int $intent,array $r1,int $requested):?array
    {
        $link=self::validateR1($p,$raw,$intent,$r1);
        if(($link['status']??null)!=='unique_cid_in_recorded_day'||$link['held_value_match']||$requested<$r1['checked_at_unix']
            ||$requested<$intent||$requested>$intent+self::TTL||$p['event_unix']<$requested-ModeledConversionAttributionWindow::MAX_SECONDS)return null;
        $cid=$link['client_id'];$lower=$requested-ModeledConversionAttributionWindow::MAX_SECONDS;
        return ['ids'=>'98615635','metrics'=>'ym:s:visits','dimensions'=>'ym:s:clientID,ym:s:dateTime,ym:s:lastTrafficSource,ym:s:lastAdvEngine',
            'filters'=>"ym:s:clientID=='".$cid."' AND ym:s:dateTime>='".gmdate('Y-m-d H:i:s',$lower)."' AND ym:s:dateTime<='".gmdate('Y-m-d H:i:s',$p['event_unix'])."'",
            'timezone'=>'+00:00','date1'=>gmdate('Y-m-d',$lower),'date2'=>gmdate('Y-m-d',$p['event_unix']),
            'accuracy'=>'full','limit'=>101,'offset'=>1,'include_undefined'=>true];
    }
    public static function validateR2(array $p,string $raw,int $intent,array $r1,mixed $saved):array
    {
        try{
            if(!is_array($saved)||!is_int($saved['requested_at_unix']??null))return self::unavailable('invalid_capsule');
            $q=self::r2Query($p,$raw,$intent,$r1,$saved['requested_at_unix']);if($q===null)return self::unavailable('r1_relation_or_window_unavailable');
            $proof=self::capsule($q,'derived_cid_visits',$intent,$saved,65536,$p['event_unix']);
            if($proof['error']!==null)return self::unavailable($proof['error']);$d=$proof['data'];
            if($d['total_rows']>100||count($d['data'])>100)return self::unavailable('scoped_row_limit');
            if(count($d['data'])!==$d['total_rows'])return self::unavailable('incomplete_provider_data');
            $link=self::validateR1($p,$raw,$intent,$r1);$visits=[];$seen=[];
            foreach($d['data']as$row){
                $dims=$row['dimensions']??null;$n=self::visits($row['metrics']??null);
                if(!is_array($dims)||!array_is_list($dims)||count($dims)!==4||self::dimension($dims[0])!==$link['client_id']||$n===null)return self::unavailable('invalid_visit_row');
                $date=self::dimension($dims[1]);if($date===null)return self::unavailable('invalid_visit_clock');
                $at=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date,new DateTimeZone('UTC'));$e=DateTimeImmutable::getLastErrors();
                if(!$at||($e!==false&&($e['warning_count']||$e['error_count']))||$at->format('Y-m-d H:i:s')!==$date)return self::unavailable('invalid_visit_clock');
                $unix=$at->getTimestamp();if($unix>$p['event_unix']||$unix<$saved['requested_at_unix']-ModeledConversionAttributionWindow::MAX_SECONDS||$unix>$saved['checked_at_unix'])return self::unavailable('outside_query_window');
                foreach([2,3]as$i)if(!is_array($dims[$i])||(isset($dims[$i]['id'])&&(!is_string($dims[$i]['id'])||strlen($dims[$i]['id'])>80)))return self::unavailable('invalid_source_dimension');
                $key=self::canonical([$date,$dims[2]['id']??null,$dims[3]['id']??null]);if(isset($seen[$key]))return self::unavailable('duplicate_visit_group');$seen[$key]=true;
                $kind=($dims[2]['id']??null)==='ad'&&($dims[3]['id']??null)==='ya_direct'?'yandex_direct':'other_or_unknown';
                $visits[]=['started_at'=>gmdate('Y-m-d\TH:i:s\Z',$unix),'visits'=>$n,'source_class'=>$kind];
            }
            $window=ModeledConversionAttributionWindow::assess(['status'=>'ok','visits'=>$visits],$p['event_unix'],$saved['checked_at_unix']);
            return ['status'=>$visits===[]?'no_visible_visit_in_source_enriched_query':'visible_rows_window_assessed',
                'reason'=>$window['reason'],'assessment'=>$window,'coverage_scope'=>'complete_visible_source_enriched_query_rows',
                'unfiltered_visit_coverage'=>'not_proven','include_undefined_applies_to'=>'first_client_id_dimension_only','financial_permission'=>false];
        }catch(Throwable $ignored){return self::unavailable('invalid_capsule');}
    }

    private function row(string $id,bool $lock=false):?array
    {
        $q=$this->pdo->prepare('SELECT * FROM '.self::TABLE.' WHERE purpose=? AND outbox_id=? LIMIT 1'
            .($lock&&$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $q->execute([self::PURPOSE,$id]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return is_array($r)?$r:null;
    }
    public function read(string $id):?array{return MaxTrackerLookupProtocol::id($id)?$this->row($id):null;}
    private static function decode(mixed $raw,int $cap):?array
    {
        if(!is_string($raw)||strlen($raw)>$cap)return null;
        try{$x=json_decode($raw,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);return is_array($x)?$x:null;}catch(Throwable $ignored){return null;}
    }
    private static function intentValid(array $row,array $intent,int $now):bool
    {
        return ($row['purpose']??null)===self::PURPOSE&&(string)($row['outbox_id']??'')===$intent['outbox_id']
            &&($row['intent_sha256']??null)===$intent['intent_sha256']&&($row['state']??null)==='intent'
            &&(int)($row['intent_at_unix']??0)===$intent['intent_at_unix']&&$now>=$intent['intent_at_unix']&&$now<=$intent['intent_at_unix']+self::TTL;
    }
    /** Call only inside a transaction. Lock order is settings, original outbox, then derived ledger. */
    private function liveBinding(array $plan,int $now):bool
    {
        $lock=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $bytes=$lock!==''?'OCTET_LENGTH(last_receipt_json)':'LENGTH(CAST(last_receipt_json AS BLOB))';
        $s=$this->pdo->query("SELECT project_key,counter_id,mode,goal_id,goal_intent,contract_version,CASE WHEN ".$bytes."<=8192 THEN last_receipt_json ELSE NULL END AS receipt FROM modeled_conversion_value_export_settings WHERE project_key='anytour'".$lock)->fetch(PDO::FETCH_ASSOC);
        $receipt=self::decode($s['receipt']??null,8192);$key=ModeledConversionRetainedTrackerIdentityEvidence::KNOWN_KEYS[$plan['case_index']-1];
        if(!$s||$s['project_key']!=='anytour'||$s['counter_id']!=='98615635'||$s['mode']!=='send'||$s['goal_id']!=='678187097'
            ||$s['goal_intent']!=='verified'||$s['contract_version']!==ModeledConversionValueContract::VERSION||$receipt===null)return false;
        $q=$this->pdo->prepare('SELECT id,value_profile_id,project_key,platform,conversation_id,external_user_id,counter_id,target,transport_target,value_contract,event_unix,value_rub,client_id,tracker_record_id,tracker_observed_unix,state,upload_id,claim_token,value_api_accepted_at_unix,value_processed_at_unix FROM max_channel_conversion_outbox WHERE id=? LIMIT 1'.$lock);
        $q->execute([$plan['outbox_id']]);$current=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        return $current&&$current['state']==='ready'&&in_array($current['upload_id'],[null,''],true)&&in_array($current['claim_token'],[null,''],true)
            &&$current['value_api_accepted_at_unix']===null&&$current['value_processed_at_unix']===null
            &&self::canonical(ModeledConversionRetainedTrackerIdentityEvidence::knownRehydratePlan($receipt[$key]??null,$receipt['finite_visit_evidence']??null,$current,$plan['namespace'],$now))===self::canonical($plan);
    }

    /** Successful commit is the only returned admission. An ambiguous commit yields no IO permission. */
    public function begin(mixed $saved,mixed $frame,array $row,string $ns,int $now):?array
    {
        $plan=ModeledConversionRetainedTrackerIdentityEvidence::knownRehydratePlan($saved,$frame,$row,$ns,$now);
        if($plan===null||!self::planValid($plan,$now)||$this->pdo->inTransaction())return null;
        try{
            $this->pdo->beginTransaction();if(!$this->liveBinding($plan,$now))throw new RuntimeException('binding_unavailable');
            $token=bin2hex(random_bytes(32));$hash=hash('sha256',self::canonical([self::PURPOSE,$plan,$now,$token]));
            $q=$this->pdo->prepare('INSERT INTO '.self::TABLE.'(purpose,outbox_id,case_index,namespace,frame_digest,event_ref,intent_at_unix,intent_sha256,plan_json,state) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $q->execute([self::PURPOSE,$plan['outbox_id'],$plan['case_index'],$ns,$plan['frame_digest'],$plan['event_ref'],$now,$hash,self::canonical($plan),'intent']);
            if($q->rowCount()!==1)throw new RuntimeException('intent_not_persisted');$this->pdo->commit();
            return ['outbox_id'=>$plan['outbox_id'],'intent_at_unix'=>$now,'intent_sha256'=>$hash];
        }catch(Throwable $ignored){if($this->pdo->inTransaction())$this->pdo->rollBack();return null;}
    }
    private function change(array $intent,int $now,callable $work,bool $preIO=false):bool
    {
        if($this->pdo->inTransaction()||!MaxTrackerLookupProtocol::id($intent['outbox_id']??null)||!is_int($intent['intent_at_unix']??null)||!is_string($intent['intent_sha256']??null)||!self::hash($intent['intent_sha256']))return false;
        try{
            // Preview is non-locking and immutable-plan-only; all granting checks repeat under locks below.
            $plan=null;if($preIO){$preview=$this->row($intent['outbox_id']);$plan=self::storedPlan($preview['plan_json']??null);
                if($preview===null||!self::intentValid($preview,$intent,$now)||$plan===null||!self::planValid($plan,$now))return false;}
            $this->pdo->beginTransaction();
            if($preIO&&!$this->liveBinding($plan,$now))throw new RuntimeException('binding_changed');
            $r=$this->row($intent['outbox_id'],true);
            if($r===null||!self::intentValid($r,$intent,$now)
                ||($preIO&&self::canonical(self::storedPlan($r['plan_json']??null))!==self::canonical($plan))
                ||$work($r)!==true)throw new RuntimeException('intent_changed');
            $this->pdo->commit();return true;
        }catch(Throwable $ignored){if($this->pdo->inTransaction())$this->pdo->rollBack();return false;}
    }
    public function admitSource(array $intent,string $hash,int $now):bool
    {
        if(!self::hash($hash))return false;
        return $this->change($intent,$now,function(array $r)use($intent,$hash):bool{
            if($r['source_request_sha256']!==null)return false;
            $q=$this->pdo->prepare('UPDATE '.self::TABLE.' SET source_request_sha256=? WHERE purpose=? AND outbox_id=? AND intent_sha256=? AND source_request_sha256 IS NULL');
            $q->execute([$hash,self::PURPOSE,$intent['outbox_id'],$intent['intent_sha256']]);return $q->rowCount()===1;
        },true);
    }
    private static function source(array $r,mixed $response,int $now):?array
    {
        $p=self::storedPlan($r['plan_json']??null);$intent=(int)$r['intent_at_unix'];
        if($p===null||!self::planValid($p,$now)||!is_string($r['source_request_sha256']??null)||!self::hash($r['source_request_sha256'])||!is_array($response)||count($response)!==3||array_diff(array_keys($response),['request_sha256','result','received_at_unix'])
            ||($response['request_sha256']??null)!==$r['source_request_sha256']||!is_int($response['received_at_unix']??null)
            ||$response['received_at_unix']<$intent||$response['received_at_unix']>$now||strlen(self::canonical($response))>4096)return null;
        $x=MaxTrackerLookupProtocol::retainedClickRehydrateResult(['v'=>1,'request_sha256'=>$response['request_sha256'],'result'=>$response['result']??null,'financial_permission'=>false],
            $p['selection'],$response['request_sha256'],$response['received_at_unix']);
        return $x===null?null:['plan'=>$p,'result'=>$x,'received_at_unix'=>$response['received_at_unix']];
    }
    private static function r1(array $row,mixed $saved,int $now):?array
    {
        $source=self::decode($row['source_json']??null,4096);$s=self::source($row,$source,$now);
        if($s===null||$s['result'][0]!==1||!is_array($saved)||!is_string($row['r1_request_sha256']??null)
            ||($saved['query_sha256']??null)!==$row['r1_request_sha256']||!is_int($saved['requested_at_unix']??null)
            ||$saved['requested_at_unix']!==(int)($row['r1_requested_at_unix']??0)||$saved['requested_at_unix']<$s['received_at_unix']
            ||!is_int($saved['checked_at_unix']??null)||$saved['checked_at_unix']>$now)return null;
        $proof=self::validateR1($s['plan'],$s['result'][2][3],(int)$row['intent_at_unix'],$saved);
        return ($proof['reason']??null)==='invalid_capsule'?null:['source'=>$s,'proof'=>$proof];
    }
    /** R1 admission commits full source material and its own query hash before the first Reports IO. */
    public function admitR1(array $intent,array $source,string $hash,int $requested,int $now):bool
    {
        if(!self::hash($hash)||$requested>$now)return false;
        return $this->change($intent,$now,function(array $r)use($intent,$source,$hash,$requested,$now):bool{
            $s=self::source($r,$source,$now);
            if($s===null||$s['result'][0]!==1||$r['r1_request_sha256']!==null||$r['source_json']!==null
                ||$requested<$s['received_at_unix']||$requested<$intent['intent_at_unix'])return false;
            $q=self::r1Query($s['plan'],$s['result'][2][3],$intent['intent_at_unix']);
            if($q===null||self::queryHash('click_day_cid',$q)!==$hash)return false;
            $save=$this->pdo->prepare('UPDATE '.self::TABLE.' SET source_json=?,r1_request_sha256=?,r1_requested_at_unix=? WHERE purpose=? AND outbox_id=? AND intent_sha256=? AND r1_request_sha256 IS NULL AND source_json IS NULL');
            $save->execute([self::canonical($source),$hash,$requested,self::PURPOSE,$intent['outbox_id'],$intent['intent_sha256']]);return $save->rowCount()===1;
        },true);
    }
    /** R2 CID comes only from the positive full R1 response, never from the held CID or a hash alone. */
    public function admitR2(array $intent,array $r1,string $hash,int $requested,int $now):bool
    {
        if(!self::hash($hash)||$requested>$now)return false;
        return $this->change($intent,$now,function(array $r)use($intent,$r1,$hash,$requested,$now):bool{
            $bound=self::r1($r,$r1,$now);
            if($bound===null||$r['r2_request_sha256']!==null||$r['r1_json']!==null)return false;$s=$bound['source'];
            $q=self::r2Query($s['plan'],$s['result'][2][3],$intent['intent_at_unix'],$r1,$requested);
            if($q===null||self::queryHash('derived_cid_visits',$q)!==$hash)return false;
            $save=$this->pdo->prepare('UPDATE '.self::TABLE.' SET r1_json=?,r2_request_sha256=?,r2_requested_at_unix=? WHERE purpose=? AND outbox_id=? AND intent_sha256=? AND r2_request_sha256 IS NULL AND r1_json IS NULL');
            $save->execute([self::canonical($r1),$hash,$requested,self::PURPOSE,$intent['outbox_id'],$intent['intent_sha256']]);return $save->rowCount()===1;
        },true);
    }
    public function completeSource(array $intent,array $source,int $now):bool
    {
        return $this->change($intent,$now,function(array $r)use($intent,$source,$now):bool{
            if(self::source($r,$source,$now)===null||$r['r1_request_sha256']!==null||$r['source_json']!==null)return false;
            $q=$this->pdo->prepare('UPDATE '.self::TABLE." SET source_json=?,state='complete',completed_at_unix=? WHERE purpose=? AND outbox_id=? AND intent_sha256=? AND state='intent'");
            $q->execute([self::canonical($source),$now,self::PURPOSE,$intent['outbox_id'],$intent['intent_sha256']]);return $q->rowCount()===1;
        });
    }
    public function completeR1(array $intent,array $r1,int $now):bool
    {
        return $this->change($intent,$now,function(array $r)use($intent,$r1,$now):bool{
            if(self::r1($r,$r1,$now)===null||$r['r2_request_sha256']!==null||$r['r1_json']!==null)return false;
            $q=$this->pdo->prepare('UPDATE '.self::TABLE." SET r1_json=?,state='complete',completed_at_unix=? WHERE purpose=? AND outbox_id=? AND intent_sha256=? AND state='intent'");
            $q->execute([self::canonical($r1),$now,self::PURPOSE,$intent['outbox_id'],$intent['intent_sha256']]);return $q->rowCount()===1;
        });
    }
    public function completeR2(array $intent,array $r2,int $now):bool
    {
        return $this->change($intent,$now,function(array $r)use($intent,$r2,$now):bool{
            $r1=self::decode($r['r1_json']??null,16384);$bound=self::r1($r,$r1,$now);
            if($bound===null||!is_string($r['r2_request_sha256']??null)||($r2['query_sha256']??null)!==$r['r2_request_sha256']
                ||!is_int($r2['requested_at_unix']??null)||$r2['requested_at_unix']!==(int)($r['r2_requested_at_unix']??0)
                ||($r2['checked_at_unix']??PHP_INT_MAX)>$now||$r['r2_json']!==null)return false;$s=$bound['source'];
            if(in_array(self::validateR2($s['plan'],$s['result'][2][3],$intent['intent_at_unix'],$r1,$r2)['reason']??null,['invalid_capsule','r1_relation_or_window_unavailable'],true))return false;
            $q=$this->pdo->prepare('UPDATE '.self::TABLE." SET r2_json=?,state='complete',completed_at_unix=? WHERE purpose=? AND outbox_id=? AND intent_sha256=? AND state='intent' AND r2_json IS NULL");
            $q->execute([self::canonical($r2),$now,self::PURPOSE,$intent['outbox_id'],$intent['intent_sha256']]);return $q->rowCount()===1;
        });
    }
    public static function projection(mixed $stored,mixed $saved,mixed $frame,array $row,string $ns,int $now):array
    {
        try{return self::project($stored,$saved,$frame,$row,$ns,$now);}
        catch(Throwable $ignored){return ['available'=>false,'reason'=>'invalid_saved_capsule','financial_permission'=>false];}
    }
    private static function project(mixed $stored,mixed $saved,mixed $frame,array $row,string $ns,int $now):array
    {
        $fail=static fn(string $r):array=>['available'=>false,'reason'=>$r,'financial_permission'=>false];
        $binding=ModeledConversionRetainedTrackerIdentityEvidence::knownBinding($frame,$row,$ns,$now);
        if($binding===null)return $fail('binding_or_record_unavailable');
        $historical=array_replace($row,['client_id'=>$binding['held_client_id'],'tracker_record_id'=>$binding['held_record_id'],'tracker_observed_unix'=>$binding['held_source_at']]);
        $p=ModeledConversionRetainedTrackerIdentityEvidence::knownRehydratePlan($saved,$frame,$historical,$ns,$now);
        if($p===null||!is_array($stored))return $fail('binding_or_record_unavailable');
        $intent=(int)($stored['intent_at_unix']??0);$plan=self::storedPlan($stored['plan_json']??null);
        if(($stored['purpose']??null)!==self::PURPOSE||(string)($stored['outbox_id']??'')!==$p['outbox_id']
            ||(int)($stored['case_index']??0)!==$p['case_index']||($stored['namespace']??null)!==$ns
            ||($stored['frame_digest']??null)!==$p['frame_digest']||($stored['event_ref']??null)!==$p['event_ref']
            ||$plan===null||self::canonical($plan)!==self::canonical($p)||$intent<946684800||$intent>$now)return $fail('invalid_saved_binding');
        $out=['available'=>true,'case_index'=>$p['case_index'],'event_ref'=>$p['event_ref'],'status'=>'consumed_unknown',
            'source_request_sha256'=>null,'r1_query_sha256'=>null,'r2_query_sha256'=>null,
            'observed_at_utc'=>null,'visit_at_utc'=>null,'source_class'=>null,'historical'=>true,'as_of_checked'=>false,
            'current_eligibility'=>'not_assessed','financial_permission'=>false];
        foreach(['source_request_sha256','r1_request_sha256','r2_request_sha256']as$i=>$k)
            if(is_string($stored[$k]??null)&&self::hash($stored[$k]))$out[['source_request_sha256','r1_query_sha256','r2_query_sha256'][$i]]=$stored[$k];
        if(($stored['state']??null)!=='complete')return ($stored['state']??null)==='intent'?$out:$fail('invalid_saved_state');
        $completed=(int)($stored['completed_at_unix']??0);if($completed<$intent||$completed>$now||$completed>$intent+self::TTL)return $fail('invalid_saved_completion');
        $source=self::decode($stored['source_json']??null,4096);$s=self::source($stored,$source,$now);
        if($s===null||$s['received_at_unix']>$completed)return $fail('invalid_saved_source');
        $out['observed_at_utc']=gmdate('Y-m-d\TH:i:s\Z',$s['received_at_unix']);$out['as_of_checked']=true;
        $out['source_checked_at_utc']=gmdate('Y-m-d\TH:i:s\Z',$s['result'][1]);
        if($s['result'][0]!==1){foreach(['r1_request_sha256','r1_json','r2_request_sha256','r2_json']as$k)if(($stored[$k]??null)!==null)return $fail('invalid_saved_stage_order');$out['status']='source_unavailable';return $out;}
        if(($stored['r1_request_sha256']??null)===null&&($stored['r1_json']??null)===null){if(($stored['r2_request_sha256']??null)!==null||($stored['r2_json']??null)!==null)return $fail('invalid_saved_stage_order');$out['status']='source_observed_no_report';return $out;}
        $r1=self::decode($stored['r1_json']??null,16384);$bound=self::r1($stored,$r1,$now);
        if($bound===null||$r1['checked_at_unix']>$completed)return $fail('invalid_saved_r1');
        $link=$bound['proof'];$out['observed_at_utc']=gmdate('Y-m-d\TH:i:s\Z',$r1['checked_at_unix']);
        $out['r1_coverage_scope']='recorded_click_utc_day_only';$out['r1_day_utc']=gmdate('Y-m-d',$p['selection']['selected_recorded_at_unix']);
        if($link['status']!=='unique_cid_in_recorded_day'){if(($stored['r2_request_sha256']??null)!==null||($stored['r2_json']??null)!==null)return $fail('invalid_saved_stage_order');$out['status']=$link['status'];if(($link['reason']??null)!==null)$out['reason']=$link['reason'];return $out;}
        $out['held_value_match']=$link['held_value_match'];
        if($link['held_value_match']){if(($stored['r2_request_sha256']??null)!==null||($stored['r2_json']??null)!==null)return $fail('held_cid_r2_forbidden');
            $out['status']='relation_reconfirms_previously_checked_held_cid';return $out;}
        if(($stored['r2_request_sha256']??null)===null&&($stored['r2_json']??null)===null){$out['status']='relation_observed_no_visit_report';return $out;}
        $r2=self::decode($stored['r2_json']??null,65536);
        if($r2===null||($r2['query_sha256']??null)!==($stored['r2_request_sha256']??null)
            ||($r2['requested_at_unix']??null)!==(int)($stored['r2_requested_at_unix']??0)
            ||($r2['checked_at_unix']??PHP_INT_MAX)>$completed)return $fail('invalid_saved_r2');
        $result=self::validateR2($p,$s['result'][2][3],$intent,$r1,$r2);
        $out['status']=$result['status'];$out['reason']=$result['reason']??null;$out['observed_at_utc']=gmdate('Y-m-d\TH:i:s\Z',$r2['checked_at_unix']);
        $out['unfiltered_visit_coverage']='not_proven';$out['include_undefined_applies_to']='first_client_id_dimension_only';
        if(isset($result['assessment'])){$w=$result['assessment'];$out['window_reason_over_visible_rows']=$w['reason'];$out['eligible_visible_visit_as_of_check']=$w['allowed'];
            $out['source_class']=$w['source_class'];$out['window_days']=$w['window_days'];$out['visit_at_utc']=$w['visit_at']===null?null:gmdate('Y-m-d\TH:i:s\Z',$w['visit_at']);}
        return $out;
    }
}
