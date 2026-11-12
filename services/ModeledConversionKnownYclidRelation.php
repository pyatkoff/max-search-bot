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
    // Cache freshness is over the database's retrieved textual bytes, not re-encoded JSON objects.
    private const BASIS_COLUMNS=['purpose','outbox_id','case_index','namespace','frame_digest','event_ref','intent_at_unix','intent_sha256','state',
        'source_request_sha256','r1_request_sha256','r2_request_sha256','r1_requested_at_unix','r2_requested_at_unix',
        'enrichment_request_sha256','enrichment_requested_at_unix','plan_json','source_json','r1_json','r2_json','enrichment_json','completed_at_unix'];
    public static function projectionBasisHash(array $row):?string
    {
        $bytes='retained-known-outcome-v1|';
        foreach(self::BASIS_COLUMNS as$key){
            if(!array_key_exists($key,$row))return null;$v=$row[$key];
            if($v===null){$bytes.='N;';continue;}
            if(!is_int($v)&&!is_string($v))return null;$v=(string)$v;$bytes.='S'.strlen($v).':'.$v.';';
        }return hash('sha256',$bytes);
    }
    /** SQLite's identically named fixture UDF hashes the same byte string; no raw capsule is selected by the reader. */
    public static function projectionBasisSql(string $driver,string $alias=''):?string
    {
        if(!in_array($driver,['mysql','sqlite'],true)||($alias!==''&&preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,30}\z/D',$alias)!==1))return null;
        $parts=["'retained-known-outcome-v1|'"];$prefix=$alias===''?'':$alias.'.';
        foreach(self::BASIS_COLUMNS as$key){$c=$prefix.$key;
            $parts[]=$driver==='mysql'
                ?"CASE WHEN ".$c." IS NULL THEN 'N;' ELSE CONCAT('S',OCTET_LENGTH(CAST(".$c." AS CHAR CHARACTER SET utf8mb4)),':',CAST(".$c." AS CHAR CHARACTER SET utf8mb4),';') END"
                :"CASE WHEN ".$c." IS NULL THEN 'N;' ELSE 'S'||LENGTH(CAST(".$c." AS BLOB))||':'||CAST(".$c." AS TEXT)||';' END";
        }
        return $driver==='mysql'?'LOWER(SHA2(CONCAT('.implode(',',$parts).'),256))':'known_relation_sha256('.implode('||',$parts).')';
    }
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
    /** Internal route only; kind2 preferred Yclid never implies that the same exact record lacks a CID. */
    private static function identity(array $p,array $source,?array $r1,int $intent,int $now):array
    {
        $fake=['plan_json'=>self::canonical($p),'source_request_sha256'=>$source['request_sha256']??null,'intent_at_unix'=>$intent];
        $s=self::source($fake,$source,$now);
        if($s===null||$s['result'][0]!==1)return ['status'=>'source_unavailable'];
        $cid=$s['result'][2][2];
        if($cid!==''){
            if(!self::uint64($cid))return ['status'=>'invalid_source_client_id'];
            if($cid===$p['held_client_id'])return ['status'=>'reconfirms_previously_checked_held_cid','reason'=>'source_client_id_matches_held'];
            if($r1!==null)return ['status'=>'unexpected_r1_for_source_cid'];
            return ['status'=>'ready','client_id'=>$cid,'identity_origin'=>'exact_frozen_record_client_id'];
        }
        if($r1===null)return ['status'=>'needs_yclid_r1','reason'=>'r1_not_observed'];
        $link=self::validateR1($p,$s['result'][2][3],$intent,$r1);
        if(($link['status']??null)!=='unique_cid_in_recorded_day')return ['status'=>'r1_identity_unavailable','reason'=>$link['reason']??$link['status']??'unavailable'];
        if($link['held_value_match'])return ['status'=>'reconfirms_previously_checked_held_cid','reason'=>'recorded_day_cid_matches_held'];
        return ['status'=>'ready','client_id'=>$link['client_id'],'identity_origin'=>'yclid_recorded_click_day'];
    }
    public static function sourceIdentityRoute(array $p,array $source,int $intent,int $now):array
    {
        try{$x=self::identity($p,$source,null,$intent,$now);return array_intersect_key($x,array_flip(['status','identity_origin']));}
        catch(Throwable $ignored){return ['status'=>'source_unavailable'];}
    }

    /** Complete unfiltered base, only for a source-proven CID different from the already checked held value. */
    public static function r2Query(array $p,array $source,?array $r1,int $intent,int $requested):?array
    {
        $link=self::identity($p,$source,$r1,$intent,$requested);
        if($link['status']!=='ready'||$requested<($r1['checked_at_unix']??$source['received_at_unix']??PHP_INT_MAX)
            ||$requested<$intent||$requested>$intent+self::TTL||$p['event_unix']<$requested-ModeledConversionAttributionWindow::MAX_SECONDS)return null;
        $cid=$link['client_id'];$lower=$requested-ModeledConversionAttributionWindow::MAX_SECONDS;
        return ['ids'=>'98615635','metrics'=>'ym:s:visits','dimensions'=>'ym:s:clientID,ym:s:dateTime',
            'filters'=>"ym:s:clientID=='".$cid."' AND ym:s:dateTime>='".gmdate('Y-m-d H:i:s',$lower)."' AND ym:s:dateTime<='".gmdate('Y-m-d H:i:s',$p['event_unix'])."'",
            'timezone'=>'+00:00','date1'=>gmdate('Y-m-d',$lower),'date2'=>gmdate('Y-m-d',$p['event_unix']),
            'accuracy'=>'full','limit'=>101,'offset'=>1,'include_undefined'=>true];
    }
    private static function visitRows(array $p,string $cid,array $saved,int $requested,int $dimensions):array
    {
        $rows=$saved['response']['data'];$visits=[];$seen=[];
        foreach($rows as$row){
            $dims=$row['dimensions']??null;$n=self::visits($row['metrics']??null);
            if(!is_array($dims)||!array_is_list($dims)||count($dims)!==$dimensions||self::dimension($dims[0])!==$cid||$n===null)return ['error'=>'invalid_visit_row'];
            $date=self::dimension($dims[1]);if($date===null)return ['error'=>'invalid_visit_clock'];
            $at=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date,new DateTimeZone('UTC'));$e=DateTimeImmutable::getLastErrors();
            if(!$at||($e!==false&&($e['warning_count']||$e['error_count']))||$at->format('Y-m-d H:i:s')!==$date)return ['error'=>'invalid_visit_clock'];
            $unix=$at->getTimestamp();if($unix>$p['event_unix']||$unix<$requested-ModeledConversionAttributionWindow::MAX_SECONDS||$unix>$saved['checked_at_unix'])return ['error'=>'outside_query_window'];
            $kind='other_or_unknown';
            if($dimensions===4){
                foreach([2,3]as$i)if(!is_array($dims[$i])||(isset($dims[$i]['id'])&&(!is_string($dims[$i]['id'])||strlen($dims[$i]['id'])>80)))return ['error'=>'invalid_source_dimension'];
                $kind=($dims[2]['id']??null)==='ad'&&($dims[3]['id']??null)==='ya_direct'?'yandex_direct':'other_or_unknown';
            }
            if(isset($seen[$date]))return ['error'=>$dimensions===4?'ambiguous_source_groups':'duplicate_base_group'];$seen[$date]=true;
            $visits[]=['started_at'=>gmdate('Y-m-d\TH:i:s\Z',$unix),'visits'=>$n,'source_class'=>$kind];
        }
        return ['error'=>null,'visits'=>$visits];
    }
    public static function validateR2(array $p,array $source,?array $r1,int $intent,mixed $saved):array
    {
        try{
            if(!is_array($saved)||!is_int($saved['requested_at_unix']??null))return self::unavailable('invalid_capsule');
            $q=self::r2Query($p,$source,$r1,$intent,$saved['requested_at_unix']);if($q===null)return self::unavailable('r1_relation_or_window_unavailable');
            $proof=self::capsule($q,'derived_cid_visits',$intent,$saved,65536,$p['event_unix']);
            if($proof['error']!==null)return self::unavailable($proof['error']);$d=$proof['data'];
            if($d['total_rows']>100||count($d['data'])>100)return self::unavailable('scoped_row_limit');
            if(count($d['data'])!==$d['total_rows'])return self::unavailable('incomplete_provider_data');
            $link=self::identity($p,$source,$r1,$intent,$saved['checked_at_unix']);$rows=self::visitRows($p,$link['client_id'],$saved,$saved['requested_at_unix'],2);
            if($rows['error']!==null)return self::unavailable($rows['error']);$report=['status'=>'ok','visits'=>$rows['visits']];
            $w=ModeledConversionAttributionWindow::assess($report,$p['event_unix'],$saved['checked_at_unix']);
            return ['status'=>'complete_base_window_assessed','reason'=>$w['reason'],'assessment'=>$w,'base_report'=>$report,
                'coverage_scope'=>'complete_unfiltered_base_query_rows','financial_permission'=>false];
        }catch(Throwable $ignored){return self::unavailable('invalid_capsule');}
    }
    /** Optional source dimensions can omit visits; only a complete base can authorize this one enrichment read. */
    public static function enrichmentQuery(array $p,array $source,?array $r1,int $intent,array $base,int $requested):?array
    {
        $proof=self::validateR2($p,$source,$r1,$intent,$base);$w=$proof['assessment']??null;
        if(!is_array($w)||$w['allowed']||$w['visit_at']===null||$w['reason']!=='outside_current_window'
            ||$base['checked_at_unix']-$w['visit_at']>ModeledConversionAttributionWindow::MAX_SECONDS
            ||$requested<$base['checked_at_unix']||$requested>$intent+self::TTL
            ||$requested-$w['visit_at']>ModeledConversionAttributionWindow::MAX_SECONDS)return null;
        $q=self::r2Query($p,$source,$r1,$intent,$base['requested_at_unix']);if($q===null)return null;
        $q['dimensions']='ym:s:clientID,ym:s:dateTime,ym:s:lastTrafficSource,ym:s:lastAdvEngine';return $q;
    }
    /** Enrichment never removes base rows and cannot introduce a different clock or group count. */
    public static function validateEnrichment(array $p,array $source,?array $r1,int $intent,array $base,mixed $saved):array
    {
        $fallback=self::validateR2($p,$source,$r1,$intent,$base);
        if(($fallback['status']??null)!=='complete_base_window_assessed')return $fallback;
        $fallback['enrichment_status']='unavailable_base_preserved';
        try{
            if(!is_array($saved)||!is_int($saved['requested_at_unix']??null)){$fallback['enrichment_reason']='invalid_capsule';return $fallback;}
            $q=self::enrichmentQuery($p,$source,$r1,$intent,$base,$saved['requested_at_unix']);
            if($q===null){$fallback['enrichment_reason']='enrichment_not_admitted';return $fallback;}
            $proof=self::capsule($q,'derived_cid_sources',$intent,$saved,65536,$p['event_unix']);
            if($proof['error']!==null){$fallback['enrichment_reason']=$proof['error'];return $fallback;}$d=$proof['data'];
            if($d['total_rows']>100||count($d['data'])>100){$fallback['enrichment_reason']='scoped_row_limit';return $fallback;}
            if(count($d['data'])!==$d['total_rows']){$fallback['enrichment_reason']='incomplete_provider_data';return $fallback;}
            $link=self::identity($p,$source,$r1,$intent,$saved['checked_at_unix']);
            $sourceRows=self::visitRows($p,$link['client_id'],$saved,$base['requested_at_unix'],4);
            if($sourceRows['error']!==null){$fallback['enrichment_reason']=$sourceRows['error'];return $fallback;}
            $report=$fallback['base_report'];$index=[];
            foreach($report['visits']as$i=>$visit)$index[$visit['started_at']]=$i;
            foreach($sourceRows['visits']as$visit){
                $i=$index[$visit['started_at']]??null;
                if($i===null||$report['visits'][$i]['visits']!==$visit['visits']){$fallback['enrichment_reason']='non_base_or_nonmatching_source_group';return $fallback;}
            }
            foreach($sourceRows['visits']as$visit)if($visit['source_class']==='yandex_direct')$report['visits'][$index[$visit['started_at']]]['source_class']='yandex_direct';
            $w=ModeledConversionAttributionWindow::assess($report,$p['event_unix'],$saved['checked_at_unix']);
            return ['status'=>'complete_base_window_assessed','reason'=>$w['reason'],'assessment'=>$w,'base_report'=>$report,
                'coverage_scope'=>'complete_unfiltered_base_query_rows','enrichment_status'=>'checked_subset',
                'enrichment_covers_all_visits'=>false,'include_undefined_applies_to'=>'first_client_id_dimension_only','financial_permission'=>false];
        }catch(Throwable $ignored){$fallback['enrichment_reason']='invalid_capsule';return $fallback;}
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
        $q=$this->pdo->prepare('SELECT id,value_profile_id,project_key,platform,conversation_id,external_user_id,counter_id,target,transport_target,value_contract,event_unix,value_rub,identity_source_key,client_id,tracker_record_id,tracker_observed_unix,state,attempts,claimed_at_unix,upload_id,upload_status,value_receipt_basis,last_error,claim_token,value_api_accepted_at_unix,value_processed_at_unix FROM max_channel_conversion_outbox WHERE id=? LIMIT 1'.$lock);
        $q->execute([$plan['outbox_id']]);$current=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        return $current&&$current['identity_source_key']==='max_tracker'&&$current['state']==='ready'
            &&in_array($current['attempts'],[0,'0'],true)&&$current['claimed_at_unix']===null&&in_array($current['upload_id'],[null,''],true)&&in_array($current['upload_status'],[null,''],true)
            &&in_array($current['value_receipt_basis'],[null,''],true)&&$current['last_error']!=='upload_outcome_unknown'&&in_array($current['claim_token'],[null,''],true)
            &&$current['value_api_accepted_at_unix']===null&&$current['value_processed_at_unix']===null
            &&self::canonical(ModeledConversionRetainedTrackerIdentityEvidence::knownRehydratePlan($receipt[$key]??null,$receipt['finite_visit_evidence']??null,$current,$plan['namespace'],$now))===self::canonical($plan);
    }

    /** Successful commit is the only returned admission. An ambiguous commit yields no IO permission. */
    public function begin(mixed $saved,mixed $frame,array $row,string $ns,int $now):?array
    {
        $plan=ModeledConversionRetainedTrackerIdentityEvidence::knownRehydratePlan($saved,$frame,$row,$ns,$now);
        if($plan===null||!self::planValid($plan,$now)||$this->pdo->inTransaction())return null;
        try{
            if($this->pdo->beginTransaction()!==true)throw new RuntimeException('transaction_unconfirmed');if(!$this->liveBinding($plan,$now))throw new RuntimeException('binding_unavailable');
            $token=bin2hex(random_bytes(32));$hash=hash('sha256',self::canonical([self::PURPOSE,$plan,$now,$token]));
            $q=$this->pdo->prepare('INSERT INTO '.self::TABLE.'(purpose,outbox_id,case_index,namespace,frame_digest,event_ref,intent_at_unix,intent_sha256,plan_json,state) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $q->execute([self::PURPOSE,$plan['outbox_id'],$plan['case_index'],$ns,$plan['frame_digest'],$plan['event_ref'],$now,$hash,self::canonical($plan),'intent']);
            if($q->rowCount()!==1)throw new RuntimeException('intent_not_persisted');if($this->pdo->commit()!==true)throw new RuntimeException('commit_unconfirmed');
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
            if($this->pdo->beginTransaction()!==true)throw new RuntimeException('transaction_unconfirmed');
            if($preIO&&!$this->liveBinding($plan,$now))throw new RuntimeException('binding_changed');
            $r=$this->row($intent['outbox_id'],true);
            if($r===null||!self::intentValid($r,$intent,$now)
                ||($preIO&&self::canonical(self::storedPlan($r['plan_json']??null))!==self::canonical($plan))
                ||$work($r)!==true)throw new RuntimeException('intent_changed');
            if($this->pdo->commit()!==true)throw new RuntimeException('commit_unconfirmed');return true;
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
        if($s===null||$s['result'][0]!==1||self::identity($s['plan'],$source,null,(int)$row['intent_at_unix'],$now)['status']!=='needs_yclid_r1'
            ||!is_array($saved)||!is_string($row['r1_request_sha256']??null)||($saved['query_sha256']??null)!==$row['r1_request_sha256']
            ||!is_int($saved['requested_at_unix']??null)||$saved['requested_at_unix']!==(int)($row['r1_requested_at_unix']??0)
            ||$saved['requested_at_unix']<$s['received_at_unix']||!is_int($saved['checked_at_unix']??null)||$saved['checked_at_unix']>$now)return null;
        $proof=self::validateR1($s['plan'],$s['result'][2][3],(int)$row['intent_at_unix'],$saved);
        return ($proof['reason']??null)==='invalid_capsule'?null:['source'=>$s,'proof'=>$proof];
    }
    private static function r2(array $row,mixed $base,int $now):?array
    {
        $source=self::decode($row['source_json']??null,4096);$s=self::source($row,$source,$now);$r1=self::decode($row['r1_json']??null,16384);
        if($s===null||$s['result'][0]!==1||!is_array($base)||($base['query_sha256']??null)!==($row['r2_request_sha256']??null)
            ||!is_int($base['requested_at_unix']??null)||$base['requested_at_unix']!==(int)($row['r2_requested_at_unix']??0)
            ||!is_int($base['checked_at_unix']??null)||$base['checked_at_unix']>$now)return null;
        $identity=self::identity($s['plan'],$source,$r1,(int)$row['intent_at_unix'],$now);
        if($identity['status']!=='ready')return null;
        if($identity['identity_origin']==='yclid_recorded_click_day'){
            if(self::r1($row,$r1,$now)===null||$base['requested_at_unix']<$r1['checked_at_unix'])return null;
        }elseif(($row['r1_request_sha256']??null)!==null||($row['r1_json']??null)!==null||$base['requested_at_unix']<$s['received_at_unix'])return null;
        $proof=self::validateR2($s['plan'],$source,$r1,(int)$row['intent_at_unix'],$base);
        return in_array($proof['reason']??null,['invalid_capsule','r1_relation_or_window_unavailable'],true)?null:
            ['source'=>$s,'source_capsule'=>$source,'r1'=>$r1,'identity'=>$identity,'proof'=>$proof];
    }
    public function admitR1(array $intent,array $source,string $hash,int $requested,int $now):bool
    {
        if(!self::hash($hash)||$requested>$now)return false;
        return $this->change($intent,$now,function(array $r)use($intent,$source,$hash,$requested,$now):bool{
            $s=self::source($r,$source,$now);
            if($s===null||$s['result'][0]!==1||self::identity($s['plan'],$source,null,$intent['intent_at_unix'],$now)['status']!=='needs_yclid_r1'
                ||$r['r1_request_sha256']!==null||$r['source_json']!==null||$requested<$s['received_at_unix']||$requested<$intent['intent_at_unix'])return false;
            $q=self::r1Query($s['plan'],$s['result'][2][3],$intent['intent_at_unix']);
            if($q===null||self::queryHash('click_day_cid',$q)!==$hash)return false;
            $save=$this->pdo->prepare('UPDATE '.self::TABLE.' SET source_json=?,r1_request_sha256=?,r1_requested_at_unix=? WHERE purpose=? AND outbox_id=? AND intent_sha256=? AND r1_request_sha256 IS NULL AND source_json IS NULL');
            $save->execute([self::canonical($source),$hash,$requested,self::PURPOSE,$intent['outbox_id'],$intent['intent_sha256']]);return $save->rowCount()===1;
        },true);
    }
    public function admitR2(array $intent,array $source,?array $r1,string $hash,int $requested,int $now):bool
    {
        if(!self::hash($hash)||$requested>$now)return false;
        return $this->change($intent,$now,function(array $r)use($intent,$source,$r1,$hash,$requested,$now):bool{
            $s=self::source($r,$source,$now);if($s===null||$s['result'][0]!==1||$r['r2_request_sha256']!==null||$r['r1_json']!==null)return false;
            $id=self::identity($s['plan'],$source,$r1,$intent['intent_at_unix'],$now);
            if($id['status']!=='ready')return false;
            if($id['identity_origin']==='yclid_recorded_click_day'){
                if(self::r1($r,$r1,$now)===null||self::canonical(self::decode($r['source_json'],4096))!==self::canonical($source))return false;
            }elseif($r['r1_request_sha256']!==null||$r['source_json']!==null||$r1!==null)return false;
            $q=self::r2Query($s['plan'],$source,$r1,$intent['intent_at_unix'],$requested);
            if($q===null||self::queryHash('derived_cid_visits',$q)!==$hash)return false;
            $save=$this->pdo->prepare('UPDATE '.self::TABLE.' SET source_json=?,r1_json=?,r2_request_sha256=?,r2_requested_at_unix=? WHERE purpose=? AND outbox_id=? AND intent_sha256=? AND r2_request_sha256 IS NULL AND r1_json IS NULL');
            $save->execute([self::canonical($source),$r1===null?null:self::canonical($r1),$hash,$requested,self::PURPOSE,$intent['outbox_id'],$intent['intent_sha256']]);return $save->rowCount()===1;
        },true);
    }
    /** The entire complete base is committed before the one optional source-enrichment GET. */
    public function admitEnrichment(array $intent,array $base,string $hash,int $requested,int $now):bool
    {
        if(!self::hash($hash)||$requested>$now)return false;
        return $this->change($intent,$now,function(array $r)use($intent,$base,$hash,$requested,$now):bool{
            $b=self::r2($r,$base,$now);if($b===null||$r['enrichment_request_sha256']!==null||$r['r2_json']!==null)return false;$p=$b['source']['plan'];
            $q=self::enrichmentQuery($p,$b['source_capsule'],$b['r1'],$intent['intent_at_unix'],$base,$requested);
            if($q===null||self::queryHash('derived_cid_sources',$q)!==$hash)return false;
            $save=$this->pdo->prepare('UPDATE '.self::TABLE.' SET r2_json=?,enrichment_request_sha256=?,enrichment_requested_at_unix=? WHERE purpose=? AND outbox_id=? AND intent_sha256=? AND enrichment_request_sha256 IS NULL AND r2_json IS NULL');
            $save->execute([self::canonical($base),$hash,$requested,self::PURPOSE,$intent['outbox_id'],$intent['intent_sha256']]);return $save->rowCount()===1;
        },true);
    }
    public function completeSource(array $intent,array $source,int $now):bool
    {
        return $this->change($intent,$now,function(array $r)use($intent,$source,$now):bool{
            if(self::source($r,$source,$now)===null||$r['r1_request_sha256']!==null||$r['r2_request_sha256']!==null||$r['source_json']!==null)return false;
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
    /** A failed/unknown enrichment never discards the already performed complete base read. */
    public function completeR2(array $intent,array $base,int $now):bool
    {
        return $this->change($intent,$now,function(array $r)use($intent,$base,$now):bool{
            if(self::r2($r,$base,$now)===null||$r['enrichment_json']!==null)return false;
            if($r['r2_json']!==null&&self::canonical(self::decode($r['r2_json'],65536))!==self::canonical($base))return false;
            $q=$this->pdo->prepare('UPDATE '.self::TABLE." SET r2_json=?,state='complete',completed_at_unix=? WHERE purpose=? AND outbox_id=? AND intent_sha256=? AND state='intent'");
            $q->execute([self::canonical($base),$now,self::PURPOSE,$intent['outbox_id'],$intent['intent_sha256']]);return $q->rowCount()===1;
        });
    }
    public function completeEnrichment(array $intent,array $enrichment,int $now):bool
    {
        return $this->change($intent,$now,function(array $r)use($intent,$enrichment,$now):bool{
            $base=self::decode($r['r2_json']??null,65536);$b=self::r2($r,$base,$now);
            if($b===null||$r['enrichment_json']!==null||!is_string($r['enrichment_request_sha256'])
                ||($enrichment['query_sha256']??null)!==$r['enrichment_request_sha256']
                ||($enrichment['requested_at_unix']??null)!==(int)($r['enrichment_requested_at_unix']??0)||($enrichment['checked_at_unix']??PHP_INT_MAX)>$now)return false;
            $p=$b['source']['plan'];$q=self::enrichmentQuery($p,$b['source_capsule'],$b['r1'],$intent['intent_at_unix'],$base,$enrichment['requested_at_unix']);
            if($q===null||(self::capsule($q,'derived_cid_sources',$intent['intent_at_unix'],$enrichment,65536,$p['event_unix'])['error']??null)==='invalid_capsule')return false;
            $q=$this->pdo->prepare('UPDATE '.self::TABLE." SET enrichment_json=?,state='complete',completed_at_unix=? WHERE purpose=? AND outbox_id=? AND intent_sha256=? AND state='intent' AND enrichment_json IS NULL");
            $q->execute([self::canonical($enrichment),$now,self::PURPOSE,$intent['outbox_id'],$intent['intent_sha256']]);return $q->rowCount()===1;
        });
    }
    /** One case, once only. Caller reserves this one subject instead of its ordinary lookup.
     * Receiver exact-record revision must be installed and verified before wiring this entry.
     * Every IO callback commits its own phase under the same current-row binding; no upload here.
     */
    public function captureOnce(mixed $saved,mixed $frame,array $row,string $ns,object $lookup,object $api,int $now,callable $clock,callable $allowCurrent):void
    {
        try{
            if(!method_exists($lookup,'retainedClickRehydrate')||!method_exists($api,'retainedYclidClickDayClients')
                ||!method_exists($api,'retainedIdentityVisitBase')||!method_exists($api,'retainedIdentityVisitSources'))return;
            $plan=ModeledConversionRetainedTrackerIdentityEvidence::knownRehydratePlan($saved,$frame,$row,$ns,$now);
            if($plan===null)return;$intent=$this->begin($saved,$frame,$row,$ns,$now);if($intent===null)return;
            $at=static function()use($clock,$intent):int{
                $v=$clock();if(!is_int($v)||$v<$intent['intent_at_unix']||$v>$intent['intent_at_unix']+self::TTL)throw new RuntimeException('capture_clock');
                return $v;
            };
            $read=static function(callable $io):?array{try{$v=$io();return is_array($v)?$v:null;}catch(Throwable $ignored){return null;}};
            $source=$read(fn():?array=>$lookup->retainedClickRehydrate($plan['selection'],
                fn(string $h):bool=>$allowCurrent()===true&&$this->admitSource($intent,$h,$at())));
            if($source===null)return;$route=self::sourceIdentityRoute($plan,$source,$now,$at());$r1=null;
            if($route['status']==='needs_yclid_r1'){
                $raw=$source['result'][2][3];
                $r1=$read(fn():?array=>$api->retainedYclidClickDayClients($plan,$raw,$now,
                    fn(string $h,int $requested):bool=>$allowCurrent()===true&&$this->admitR1($intent,$source,$h,$requested,$at()),$at));
                if($r1===null){$this->completeSource($intent,$source,$at());return;}
                if(self::identity($plan,$source,$r1,$now,$at())['status']!=='ready'){$this->completeR1($intent,$r1,$at());return;}
            }elseif($route['status']!=='ready'){$this->completeSource($intent,$source,$at());return;}
            $base=$read(fn():?array=>$api->retainedIdentityVisitBase($plan,$source,$r1,$now,
                fn(string $h,int $requested):bool=>$allowCurrent()===true&&$this->admitR2($intent,$source,$r1,$h,$requested,$at()),$at));
            if($base===null){if($r1===null)$this->completeSource($intent,$source,$at());else $this->completeR1($intent,$r1,$at());return;}
            if(self::enrichmentQuery($plan,$source,$r1,$now,$base,$at())===null){$this->completeR2($intent,$base,$at());return;}
            $enrichment=$read(fn():?array=>$api->retainedIdentityVisitSources($plan,$source,$r1,$base,$now,
                fn(string $h,int $requested):bool=>$allowCurrent()===true&&$this->admitEnrichment($intent,$base,$h,$requested,$at()),$at));
            if($enrichment===null)$this->completeR2($intent,$base,$at());else $this->completeEnrichment($intent,$enrichment,$at());
        }catch(Throwable $ignored){/* Every admitted IO remains consumed, including an unknown response or clock. */}
        finally{
            $observed=$now;try{$v=$clock();if(is_int($v)&&$v>=$now)$observed=$v;}catch(Throwable $ignored){}
            $this->cacheOutcome($saved,$frame,$row,$ns,$observed);
        }
    }
    /** Worker-only saved outcome. Failure/staleness has no effect on once, identity, or financial admission. */
    private function cacheOutcome(mixed $saved,mixed $frame,array $row,string $ns,int $now):void
    {
        try{
            $id=$row['id']??null;
            if($this->pdo->inTransaction()||(!is_int($id)&&!is_string($id))||!MaxTrackerLookupProtocol::id((string)$id))return;
            $stored=$this->read((string)$id);if($stored===null||self::boundPlan($stored,$saved,$frame,$row,$ns,$now)===null)return;
            $basis=self::projectionBasisHash($stored);$sql=self::projectionBasisSql((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            if($basis===null||$sql===null)return;
            $out=self::projection($stored,$saved,$frame,$row,$ns,$now);$out['financial_permission']=false;$out['current_eligibility']='not_assessed';
            $json=self::canonical($out);if(strlen($json)>2048)return;
            $q=$this->pdo->prepare('UPDATE '.self::TABLE.' SET outcome_json=?,outcome_basis_sha256=? WHERE purpose=? AND outbox_id=? AND '.$sql.'=?');
            $q->execute([$json,$basis,self::PURPOSE,$stored['outbox_id'],$basis]);
        }catch(Throwable $ignored){/* A missing cache is explicit UNKNOWN; never repeat any source or Reports phase. */}
    }

    private static function boundPlan(mixed $stored,mixed $saved,mixed $frame,array $row,string $ns,int $now):?array
    {
        $b=ModeledConversionRetainedTrackerIdentityEvidence::knownBinding($frame,$row,$ns,$now);if($b===null||!is_array($stored))return null;
        $old=array_replace($row,['client_id'=>$b['held_client_id'],'tracker_record_id'=>$b['held_record_id'],'tracker_observed_unix'=>$b['held_source_at']]);
        $p=ModeledConversionRetainedTrackerIdentityEvidence::knownRehydratePlan($saved,$frame,$old,$ns,$now);$copy=self::storedPlan($stored['plan_json']??null);
        if($p===null||$copy===null||!is_string($stored['intent_sha256']??null)||!self::hash($stored['intent_sha256'])||($stored['purpose']??null)!==self::PURPOSE||(string)($stored['outbox_id']??'')!==$p['outbox_id']
            ||(int)($stored['case_index']??0)!==$p['case_index']||($stored['namespace']??null)!==$ns
            ||($stored['frame_digest']??null)!==$p['frame_digest']||($stored['event_ref']??null)!==$p['event_ref']
            ||self::canonical($copy)!==self::canonical($p))return null;
        return $p;
    }
    private static function completedProof(array $stored,array $p,int $now):?array
    {
        $intent=(int)($stored['intent_at_unix']??0);$done=(int)($stored['completed_at_unix']??0);
        if(($stored['state']??null)!=='complete'||$intent<946684800||$done<$intent||$done>$intent+self::TTL||$done>$now)return null;
        $source=self::decode($stored['source_json']??null,4096);$s=self::source($stored,$source,$now);
        if($s===null||$s['received_at_unix']>$done)return null;
        if($s['result'][0]!==1)return ['status'=>'source_unavailable','source'=>$s,'observed'=>$s['received_at_unix']];
        $r1=self::decode($stored['r1_json']??null,16384);$route=self::identity($p,$source,$r1,$intent,$now);
        if($r1!==null&&self::r1($stored,$r1,$now)===null)return null;
        if($route['status']==='reconfirms_previously_checked_held_cid')return ['status'=>'relation_reconfirms_previously_checked_held_cid','relation_reason'=>$route['reason'],'source'=>$s,'r1'=>$r1,'observed'=>$r1['checked_at_unix']??$s['received_at_unix']];
        if($route['status']!=='ready')return ['status'=>$route['status'],'relation_reason'=>$route['reason']??null,'source'=>$s,'r1'=>$r1,'observed'=>$r1['checked_at_unix']??$s['received_at_unix']];
        $base=self::decode($stored['r2_json']??null,65536);
        if($base===null)return ['status'=>'identity_observed_no_visit_report','source'=>$s,'observed'=>$r1['checked_at_unix']??$s['received_at_unix']];
        $b=self::r2($stored,$base,$now);if($b===null||$base['checked_at_unix']>$done)return null;$result=$b['proof'];
        $enrichment=self::decode($stored['enrichment_json']??null,65536);
        if($enrichment!==null){
            if(($enrichment['query_sha256']??null)!==($stored['enrichment_request_sha256']??null)
                ||($enrichment['requested_at_unix']??null)!==(int)($stored['enrichment_requested_at_unix']??0)||($enrichment['checked_at_unix']??PHP_INT_MAX)>$done)return null;
            $q=self::enrichmentQuery($p,$source,$r1,$intent,$base,$enrichment['requested_at_unix']);if($q===null)return null;
            if((self::capsule($q,'derived_cid_sources',$intent,$enrichment,65536,$p['event_unix'])['error']??null)==='invalid_capsule')return null;
            $result=self::validateEnrichment($p,$source,$r1,$intent,$base,$enrichment);
        }else$result['enrichment_status']=($stored['enrichment_request_sha256']??null)===null?'not_attempted':'consumed_unknown_base_preserved';
        return ['status'=>$result['status'],'source'=>$s,'identity'=>$route,'result'=>$result,'base'=>$base,'r1'=>$r1,
            'observed'=>$enrichment['checked_at_unix']??$base['checked_at_unix']];
    }
    public static function projection(mixed $stored,mixed $saved,mixed $frame,array $row,string $ns,int $now):array
    {
        $fail=static fn(string $r):array=>['available'=>false,'reason'=>$r,'financial_permission'=>false];
        try{
            $p=self::boundPlan($stored,$saved,$frame,$row,$ns,$now);if($p===null)return $fail('invalid_saved_binding');
            $out=['available'=>true,'case_index'=>$p['case_index'],'event_ref'=>$p['event_ref'],'status'=>'consumed_unknown','historical'=>true,
                'as_of_checked'=>false,'current_eligibility'=>'not_assessed','financial_permission'=>false];
            foreach(['source_request_sha256','r1_request_sha256','r2_request_sha256','enrichment_request_sha256']as$k)
                $out[$k]=is_string($stored[$k]??null)&&self::hash($stored[$k])?$stored[$k]:null;
            if(($stored['state']??null)==='intent')return $out;
            $proof=self::completedProof($stored,$p,$now);if($proof===null)return $fail('invalid_completed_proof');
            $out['status']=$proof['status'];$out['as_of_checked']=true;$out['observed_at_utc']=gmdate('Y-m-d\TH:i:s\Z',$proof['observed']);
            $out['source_checked_at_utc']=gmdate('Y-m-d\TH:i:s\Z',$proof['source']['result'][1]);
            if(isset($proof['relation_reason']))$out['relation_reason']=$proof['relation_reason'];
            if(isset($proof['identity']))$out['identity_origin']=$proof['identity']['identity_origin'];
            if(isset($proof['r1'])&&$proof['r1']!==null){$out['r1_coverage_scope']='recorded_click_utc_day_only';$out['r1_day_utc']=gmdate('Y-m-d',$p['selection']['selected_recorded_at_unix']);}
            $r=$proof['result']??null;if(is_array($r)){
                $out['reason']=$r['reason']??null;$out['enrichment_status']=$r['enrichment_status']??null;
                if(isset($r['assessment'])){$w=$r['assessment'];$out['eligible_as_of_check']=$w['allowed'];$out['window_days']=$w['window_days'];
                    $out['source_class']=$w['source_class'];$out['visit_at_utc']=$w['visit_at']===null?null:gmdate('Y-m-d\TH:i:s\Z',$w['visit_at']);$out['coverage_scope']='complete_unfiltered_base_query_rows';}
            }return $out;
        }catch(Throwable $ignored){return $fail('invalid_saved_capsule');}
    }
    /** PRIVATE worker candidate only. Pure/no IO, no identity write, no export, no claim. */
    public static function dispatchCandidate(mixed $stored,mixed $saved,mixed $frame,array $row,array $settings,string $ns,int $now):?array
    {
        try{
            $p=self::boundPlan($stored,$saved,$frame,$row,$ns,$now);
            if($p===null||($settings['project_key']??null)!=='anytour'||($settings['counter_id']??null)!=='98615635'
                ||($settings['mode']??null)!=='send'||($settings['goal_id']??null)!=='678187097'||($settings['goal_intent']??null)!=='verified'
                ||($settings['contract_version']??null)!==ModeledConversionValueContract::VERSION||($row['state']??null)!=='ready'
                ||($row['identity_source_key']??null)!=='max_tracker')return null;
            foreach(['upload_id','upload_status','claim_token','value_receipt_basis']as$k)if(!array_key_exists($k,$row)||!in_array($row[$k],[null,''],true))return null;
            foreach(['value_api_accepted_at_unix','value_processed_at_unix']as$k)if(!array_key_exists($k,$row)||$row[$k]!==null)return null;
            $proof=self::completedProof($stored,$p,$now);$report=$proof['result']['base_report']??null;$id=$proof['identity']['client_id']??null;
            if(!is_array($report)||!self::uint64($id)||$id===$p['held_client_id']
                ||($row['tracker_record_id']??null)!==$p['held_record_id']||(string)($row['tracker_observed_unix']??'')!==(string)$p['held_source_at'])return null;
            $attempts=$row['attempts']??null;
            if((!is_int($attempts)&&!is_string($attempts))||preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',(string)$attempts)!==1)return null;
            if((int)$attempts===0){
                if(($row['client_id']??null)!==$p['held_client_id']||($row['claimed_at_unix']??null)!==null
                    ||!in_array($row['last_error']??null,[null,'','identity_not_found','metrika_visit_unverified','metrika_visit_window','wire_key_collision'],true))return null;
            }else{
                $claimed=$row['claimed_at_unix']??null;
                if(($row['client_id']??null)!==$id||!in_array($row['last_error']??null,['transport_not_sent','rate_limited'],true)
                    ||(!is_int($claimed)&&!is_string($claimed))||preg_match('/\A[1-9][0-9]{8,10}\z/D',(string)$claimed)!==1
                    ||(int)$claimed<$p['event_unix']||(int)$claimed>$now)return null;
            }
            if(!ModeledConversionAttributionWindow::assess($report,$p['event_unix'],$now)['allowed'])return null;
            $hash=hash('sha256',self::canonical([self::PURPOSE,$p,$stored['intent_sha256'],self::decode($stored['source_json'],4096),self::decode($stored['r1_json'],16384),self::decode($stored['r2_json'],65536),self::decode($stored['enrichment_json'],65536)]));
            return ['client_id'=>$id,'event_ref'=>$p['event_ref'],'outbox_id'=>$p['outbox_id'],'visit_report'=>$report,'proof_sha256'=>$hash];
        }catch(Throwable $ignored){return null;}
    }
}
