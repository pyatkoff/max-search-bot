<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionValueContract.php';

/** Finite GET-only verification. Only its private receipt is written; outbox and profiles stay immutable. */
final class ModeledConversionVisitProof
{
    public const VERSION='modeled_visit_reconciliation_v1';
    public const MAX_ROWS=32;
    private const MAX_BYTES=49152;
    public static function fingerprint(array $r):string
    {
        return hash('sha256',json_encode(array_map(static fn(string $k):string=>(string)($r[$k]??''),
            ['id','conversation_id','platform','target','value_rub','event_unix','client_id','upload_id']),JSON_THROW_ON_ERROR));
    }
    public static function ledger(PDO $pdo):array
    {
        require_once __DIR__.'/ModeledConversionDeliveryRegistry.php';
        $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $q=$pdo->query('SELECT o.id,o.conversation_id,o.project_key,o.counter_id,o.transport_target,o.value_contract,o.platform,o.target,o.value_profile_id,'
            .'o.value_stage,o.value_rub,o.event_unix,o.client_id,o.upload_id,o.external_user_id,o.state,o.upload_status,o.value_api_accepted_at_unix,o.value_processed_at_unix '
            .ModeledConversionDeliveryRegistry::sourceSql($mysql).' ORDER BY o.id DESC LIMIT 1001');
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        if(count($rows)>=1001)throw new RuntimeException('verification_ledger_bound');
        return $rows;
    }
    public static function verify(array $ledger,object $api,int $activation,int $checkpoint,int $now,?array $selectedIds=null,?float $deadline=null,array $retained=[]):array
    {
        if(count($ledger)>=1001||$activation<946684800||$checkpoint<$activation||$checkpoint>$now)throw new InvalidArgumentException('verification_scope');
        $cohort=[];$other=[];$ids=[];
        foreach($ledger as$r){
            if(($r['project_key']??null)!=='anytour'||(string)($r['counter_id']??'')!=='98615635'
                ||($r['transport_target']??null)!==ModeledConversionValueContract::TARGET
                ||($r['value_contract']??null)!==ModeledConversionValueContract::VERSION
                ||!ModeledConversionValueContract::isKey($r['target']??null)||!in_array($r['platform']??null,['max','telegram'],true)
                ||!is_numeric($r['value_rub']??null)||(float)$r['value_rub']<=0||(float)$r['value_rub']>ModeledConversionValueContract::MAX_VALUE_RUB
                ||!in_array($r['value_stage']??null,ModeledConversionValueContract::STAGES,true)
                ||preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)($r['id']??''))!==1
                ||preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)($r['conversation_id']??''))!==1
                ||!is_numeric($r['event_unix']??null)||(int)$r['event_unix']<946684800||(int)$r['event_unix']>$now
                ||isset($ids[(string)$r['id']]))throw new RuntimeException('verification_invalid_ledger');
            $ids[(string)$r['id']]=true;
            if(!str_starts_with($r['target'],'anytour_value_pred_')){
                $rank=array_search($r['value_stage'],ModeledConversionValueContract::STAGES,true);
                if($rank===false||$r['target']!==ModeledConversionValueContract::key($rank))throw new RuntimeException('verification_stage_binding');
                continue;
            }
            if($r['target']!==ModeledConversionValueContract::predictionKey((string)$r['value_profile_id']))throw new RuntimeException('verification_binding');
            $accepted=(int)($r['value_api_accepted_at_unix']??0);
            $in=$accepted>0&&$accepted<=$checkpoint&&($r['value_processed_at_unix']===null||(int)$r['value_processed_at_unix']>$checkpoint);
            if($in)$cohort[]=$r;else $other[]=$r;
        }
        if(count($cohort)>self::MAX_ROWS)throw new RuntimeException('verification_cohort_bound');
        if($selectedIds!==null){
            $order=array_flip($selectedIds);
            $other=array_values(array_filter($other,static fn(array $r):bool=>isset($order[(string)$r['id']])));
            usort($other,static fn(array $a,array $b):int=>$order[(string)$a['id']]<=>$order[(string)$b['id']]);
        }
        $retainedRecords=[];
        foreach($cohort as$i=>$r)if(isset($retained[(string)$r['id']])){
            $e=$retained[(string)$r['id']];
            if(!hash_equals((string)($e['fingerprint']??''),self::fingerprint($r)))throw new RuntimeException('verification_retained_binding');
            $retainedRecords[(string)$r['id']]=$e;unset($cohort[$i]);
        }
        $selected=array_merge(array_values($cohort),array_slice($other,0,self::MAX_ROWS-count($cohort)-count($retainedRecords)));
        $polls=[];$started=microtime(true);$requests=0;
        $poll=static function(array $r)use($api,&$polls,&$requests,$started,$deadline):?array{
            $id=(string)($r['upload_id']??'');if($id==='')return null;
            if(array_key_exists($id,$polls))return $polls[$id];
            if(++$requests>96||microtime(true)-$started>100||($deadline!==null&&microtime(true)>=$deadline))throw new RuntimeException('verification_request_bound');
            $v=$api->poll($id);
            if(is_array($v)&&isset($v['comment'])&&$v['comment']!==MetrikaOfflineClient::comment($r))$v=null;
            return $polls[$id]=$v;
        };
        $records=$retainedRecords;$clients=[];
        foreach($selected as$r){
            $v=$poll($r);$status=is_array($v)?($v['status']??null):null;
            if(!in_array($status,['PREPARED','UPLOADED','EXPORTED','MATCHED','PROCESSED','LINKAGE_FAILURE'],true))$status=null;
            $cid=(string)($r['client_id']??'');
            $entry=['outbox_id'=>(string)$r['id'],'fingerprint'=>self::fingerprint($r),'conversation_id'=>(string)$r['conversation_id'],
                'platform'=>$r['platform'],'stage'=>$r['value_stage'],'increment_rub'=>(int)$r['value_rub'],
                'signal_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$r['event_unix']),
                'cohort'=>in_array((string)$r['id'],array_map(static fn(array $c):string=>(string)$c['id'],$cohort),true),
                'checked_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$now),'upload_status'=>$status,'linkage'=>'not_checked','reason'=>$status===null?'receipt_unavailable':'provider_processing',
                'visit_started_at'=>null,'visit_goal_reaches'=>null,'visit_goal_value_rub'=>null,'expected_goal_reaches'=>null,'expected_goal_value_rub'=>null];
            if($status==='LINKAGE_FAILURE'){
                $entry['linkage']='failed';$entry['reason']='provider_linkage_failure';
                if(preg_match('/\\A[1-9][0-9]{0,39}\\z/D',$cid)===1){
                    if(++$requests>96||($deadline!==null&&microtime(true)>=$deadline))throw new RuntimeException('verification_request_bound');
                    $entry['reason']=self::failureReason($api->modeledClientVisits($cid,(int)$r['event_unix'],$now),(int)$r['event_unix']);
                }
            }
            if($status==='PROCESSED'){
                if(preg_match('/\A[1-9][0-9]{0,39}\z/D',$cid)!==1||(int)($v['source_quantity']??0)!==1||(int)($v['line_quantity']??0)!==1)
                    $entry['reason']='processed_receipt_not_single_valid_row';
                else $clients[$cid][]=(string)$r['id'];
            }
            $records[(string)$r['id']]=$entry;
        }
        foreach($clients as$cid=>$eventIds){
            $cid=(string)$cid;$expected=[];$complete=true;
            foreach($ledger as$r)if((string)($r['client_id']??'')===$cid&&($r['upload_id']??'')!==''){
                $v=$poll($r);if(!is_array($v)){$complete=false;continue;}
                if(($v['status']??null)==='PROCESSED'){
                    if((int)($v['source_quantity']??0)!==1||(int)($v['line_quantity']??0)!==1)$complete=false;
                    $expected[]=$r;
                }
            }
            if(++$requests>96||microtime(true)-$started>100||($deadline!==null&&microtime(true)>=$deadline))throw new RuntimeException('verification_request_bound');
            $report=$api->modeledValueVisits($cid,$activation,$now);
            $valid=$complete&&($report['status']??null)==='ok';
            $visits=$report['visits']??[];$unique=$valid&&count($visits)===1&&($visits[0]['visits']??null)===1;
            $reaches=count($expected);$value=array_sum(array_map(static fn(array $r):float=>(float)$r['value_rub'],$expected));
            $matches=$unique&&($visits[0]['goal_reaches']??null)===$reaches&&abs((float)($visits[0]['revenue_rub']??-1)-$value)<0.005;
            $within=$matches;
            $visit=$unique?strtotime((string)$visits[0]['started_at']):false;
            if($visit===false)$within=false;
            if($within)foreach($expected as$r){$age=(int)$r['event_unix']-$visit;if($age<0||$age>21*86400)$within=false;}
            foreach($eventIds as$id){
                $records[$id]['expected_goal_reaches']=$reaches;$records[$id]['expected_goal_value_rub']=round($value,2);
                if($unique){$records[$id]['visit_started_at']=$visits[0]['started_at'];$records[$id]['visit_goal_reaches']=$visits[0]['goal_reaches'];$records[$id]['visit_goal_value_rub']=$visits[0]['revenue_rub'];}
                $records[$id]['linkage']=$within?'unique_visit_reconciled':'unverified';
                $records[$id]['reason']=$within?'client_and_visit_start_count_and_value_reconciled':
                    (!$complete?'other_receipts_unavailable':(!$valid?'visit_report_unavailable':(!$unique?'no_unique_goal_visit':(!$matches?'goal_count_or_value_mismatch':'outside_visit_window'))));
            }
        }
        return ['version'=>self::VERSION,'project_key'=>'anytour','counter_id'=>'98615635','goal_id'=>'678187097','currency'=>'RUB',
            'checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'checkpoint_at'=>gmdate('Y-m-d\TH:i:s\Z',$checkpoint),
            'basis'=>'unique_client_and_second_precision_visit_start_reconciliation_not_native_visit_id_or_event_log',
            'ledger_complete'=>true,'selected_rows'=>count($selected),'other_rows_omitted'=>max(0,count($ledger)-count($records)),
            'events'=>array_values($records),'provider_write_requests'=>0,'source_writes'=>0];
    }
    public static function path():string{return dirname(__DIR__,2).'/max-search-bot-modeled-visit-proof/last-check.json';}
    public static function failureReason(array $report,int $event):string
    {
        if(($report['status']??null)!=='ok'||!is_array($report['visits']??null))return 'client_visit_report_unavailable';
        if($report['visits']===[])return 'no_reported_client_visits';
        $later=false;
        foreach($report['visits']as$v){
            $at=strtotime((string)($v['started_at']??''));if($at===false)return 'client_visit_report_unavailable';
            $age=$event-$at;
            if($age>=0&&$age<=21*86400)return 'eligible_visit_but_provider_linkage_failed';
            if($age<0)$later=true;
        }
        return $later?'only_later_or_expired_client_visits':'no_client_visit_in_attribution_window';
    }
    /** Newest half plus oldest unchecked half; stable terminal evidence is rechecked daily. */
    public static function dueIds(array $ledger,int $checkpoint,int $now,callable $prior,int $batch=26):array
    {
        $due=[];$cohort=0;
        foreach($ledger as$r){
            if(!str_starts_with((string)$r['target'],'anytour_value_pred_')||empty($r['upload_id']))continue;
            $accepted=(int)($r['value_api_accepted_at_unix']??0);
            if($accepted>0&&$accepted<=$checkpoint&&($r['value_processed_at_unix']===null||(int)$r['value_processed_at_unix']>$checkpoint)){$cohort++;continue;}
            $old=$prior($r);$at=(int)($old['at']??0);$e=$old['event']??[];
            $terminal=($e['linkage']??null)==='unique_visit_reconciled'
                ||(($e['upload_status']??null)==='LINKAGE_FAILURE'&&!in_array($e['reason']??null,['provider_linkage_failure','client_visit_report_unavailable'],true));
            if($at===0||$now-$at>=($terminal?86400:300))$due[]=['id'=>(string)$r['id'],'at'=>$at];
        }
        $limit=max(0,min($batch,self::MAX_ROWS-$cohort));$ids=array_column(array_slice($due,0,(int)ceil($limit/2)),'id');
        usort($due,static fn(array $a,array $b):int=>$a['at']<=>$b['at']);
        foreach($due as$d)if(count($ids)<$limit&&!in_array($d['id'],$ids,true))$ids[]=$d['id'];
        return $ids;
    }
    private static function eventPath(array $row):string
    {
        return dirname(self::path()).'/events/'.self::fingerprint($row).'.json';
    }
    public static function prior(array $row,int $now):array
    {
        $c=self::readCacheAt(self::eventPath($row),$now);
        if(($c['available']??false)!==true)return [];
        $r=$c['receipt'];$e=$r['events'][0]??[];
        if(count($r['events'])!==1||($e['outbox_id']??null)!==(string)$row['id']||!hash_equals((string)($e['fingerprint']??''),self::fingerprint($row)))return [];
        return ['at'=>strtotime($e['checked_at']??$r['checked_at']),'event'=>$e,'cache'=>$c];
    }
    public static function save(array $receipt):void
    {
        self::directory(dirname(self::path()));
        // Keep per-row evidence after it rotates out of the bounded current report.
        foreach($receipt['events']as$e){
            $one=$receipt;$one['events']=[$e];$one['selected_rows']=1;
            self::storeAt(dirname(self::path()).'/events/'.$e['fingerprint'].'.json',$one);
        }
        self::storeAt(self::path(),$receipt);
    }
    private static function storeAt(string $path,array $receipt):void
    {
        $json=json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $data=json_encode(['sha256'=>hash('sha256',$json),'receipt'=>$receipt],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(strlen($data)>self::MAX_BYTES)throw new RuntimeException('verification_cache_bound');
        $dir=dirname($path);self::directory($dir);
        if(is_link($path)||(file_exists($path)&&((fileperms($path)&0777)!==0600||fileowner($path)!==posix_geteuid())))
            throw new RuntimeException('verification_cache_path');
        $tmp=tempnam($dir,'.proof-');if($tmp===false)throw new RuntimeException('verification_cache_temporary');
        try{if(!chmod($tmp,0600)||file_put_contents($tmp,$data,LOCK_EX)!==strlen($data)||!rename($tmp,$path))throw new RuntimeException('verification_cache_write');}
        finally{if(is_file($tmp))unlink($tmp);}
    }
    private static function directory(string $dir):void
    {
        if(is_link($dir))throw new RuntimeException('verification_cache_path');
        if(!is_dir($dir)&&!mkdir($dir,0700))throw new RuntimeException('verification_cache_directory');
        if((fileperms($dir)&0777)!==0700||fileowner($dir)!==posix_geteuid())throw new RuntimeException('verification_cache_permissions');
    }
    public static function automatic(int $now):array
    {
        $path=dirname(self::path()).'/automatic.json';
        if(is_link(dirname($path))||is_link($path)||!is_file($path))return ['available'=>false];
        $owner=fileowner(__FILE__);$size=filesize($path);
        if((fileperms(dirname($path))&0777)!==0700||(fileperms($path)&0777)!==0600
            ||fileowner(dirname($path))!==$owner||fileowner($path)!==$owner||!is_int($size)||$size>4096)return ['available'=>false];
        try{$d=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);}catch(JsonException $ignored){return ['available'=>false];}
        if(($d['origin']??null)!=='server_cron'||!in_array($d['status']??null,['running','ok','failed'],true)
            ||!is_int($d['attempt_unix']??null)||$d['attempt_unix']<946684800||$d['attempt_unix']>$now
            ||!is_int($d['success_unix']??null)||$d['success_unix']<0||$d['success_unix']>$now
            ||!is_array($d['checks']??null)||count($d['checks'])>3)return ['available'=>false];
        foreach($d['checks']as$at)if(!is_int($at)||$at<946684800||$at>$now)return ['available'=>false];
        return ['available'=>true,...$d,'fresh'=>$d['success_unix']>0&&$now-$d['success_unix']<=900,'interval_seconds'=>300];
    }
    public static function recordAutomatic(string $status,int $now,?array $previous):void
    {
        $checks=$previous['checks']??[];
        if($status==='ok'){$checks[]=$now;$checks=array_slice(array_values(array_unique($checks)),-3);}
        $value=['origin'=>'server_cron','status'=>$status,'attempt_unix'=>$now,
            'success_unix'=>$status==='ok'?$now:(int)($previous['success_unix']??0),'checks'=>$checks];
        $path=dirname(self::path()).'/automatic.json';$dir=dirname($path);
        if(is_link($dir)||is_link($path)||!is_dir($dir)||(fileperms($dir)&0777)!==0700||fileowner($dir)!==posix_geteuid())
            throw new RuntimeException('verification_cache_permissions');
        $data=json_encode($value,JSON_THROW_ON_ERROR);$tmp=tempnam($dir,'.automatic-');
        if($tmp===false)throw new RuntimeException('verification_cache_temporary');
        try{if(!chmod($tmp,0600)||file_put_contents($tmp,$data,LOCK_EX)!==strlen($data)||!rename($tmp,$path))throw new RuntimeException('verification_cache_write');}
        finally{if(is_file($tmp))unlink($tmp);}
    }
    public static function readCache(int $now,?ModeledConversionStatusBudget $budget=null):array
    {
        return self::readCacheAt(self::path(),$now,$budget);
    }
    /** Fixed public reader delegates to an internal path loader; no path is accepted by a tool. */
    private static function readCacheAt(string $path,int $now,?ModeledConversionStatusBudget $budget=null):array
    {
        if(is_link(dirname($path))||is_link($path)||!is_file($path)||!is_readable($path))return ['available'=>false,'reason'=>'not_recorded'];
        $size=filesize($path);$owner=fileowner(__FILE__);
        if(!is_int($owner)||!is_int($size)||$size>self::MAX_BYTES||(fileperms($path)&0777)!==0600||(fileperms(dirname($path))&0777)!==0700
            ||fileowner($path)!==$owner||fileowner(dirname($path))!==$owner)return ['available'=>false,'reason'=>'invalid_cache'];
        if($budget!==null&&$size+8192>$budget->remainingBytes())return ['available'=>false,'reason'=>'budget_headroom'];
        $raw=file_get_contents($path);if(!is_string($raw)||strlen($raw)!==$size)return ['available'=>false,'reason'=>'invalid_cache'];
        if($budget!==null)$budget->consumeBytes(strlen($raw));
        try{$v=json_decode($raw,true,24,JSON_THROW_ON_ERROR);}catch(JsonException $ignored){return ['available'=>false,'reason'=>'invalid_cache'];}
        return self::validateCache($v,$now);
    }
    public static function validateCache(mixed $v,int $now):array
    {
        if(!is_array($v)||!is_array($v['receipt']??null)||!is_string($v['sha256']??null))return ['available'=>false,'reason'=>'invalid_cache'];
        $r=$v['receipt'];$json=json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $at=is_string($r['checked_at']??null)?strtotime($r['checked_at']):false;
        if(!hash_equals(hash('sha256',$json),$v['sha256'])||($r['version']??null)!==self::VERSION
            ||($r['project_key']??null)!=='anytour'||($r['counter_id']??null)!=='98615635'||($r['goal_id']??null)!=='678187097'
            ||($r['currency']??null)!=='RUB'||($r['ledger_complete']??null)!==true||($r['provider_write_requests']??null)!==0
            ||($r['source_writes']??null)!==0||$at===false||$at>$now||!is_array($r['events']??null)||count($r['events'])>self::MAX_ROWS)
            return ['available'=>false,'reason'=>'invalid_cache'];
        foreach($r['events']as$e)if(!is_array($e)||preg_match('/\A[a-f0-9]{64}\z/D',(string)($e['fingerprint']??''))!==1
            ||!is_int($e['increment_rub']??null)||$e['increment_rub']<=0||$e['increment_rub']>ModeledConversionValueContract::MAX_VALUE_RUB||!is_bool($e['cohort']??null)
            ||!in_array($e['platform']??null,['max','telegram'],true)||!in_array($e['stage']??null,ModeledConversionValueContract::STAGES,true)
            ||!in_array($e['linkage']??null,['not_checked','failed','unverified','unique_visit_reconciled'],true)
            ||(isset($e['checked_at'])&&(!is_string($e['checked_at'])||strtotime($e['checked_at'])===false||strtotime($e['checked_at'])>$now)))return ['available'=>false,'reason'=>'invalid_cache'];
        return ['available'=>true,'fresh'=>$now-$at<=900,'receipt'=>$r];
    }
    public static function forEvent(array $cache,array $row,?int $now=null):array
    {
        $now??=time();
        if(($cache['available']??false)!==true){$prior=self::prior($row,$now);if($prior!==[])return self::forEvent($prior['cache'],$row,$now);return ['available'=>false,'reason'=>$cache['reason']??'not_recorded'];}
        foreach($cache['receipt']['events']as$e)if($e['outbox_id']===(string)$row['id']){
            if(!hash_equals($e['fingerprint'],self::fingerprint($row)))return ['available'=>false,'reason'=>'event_changed'];
            $at=$e['checked_at']??$cache['receipt']['checked_at'];
            return ['available'=>true,'fresh'=>$now-strtotime($at)<=900,'checked_at'=>$at,
                'upload_status'=>$e['upload_status'],'linkage'=>$e['linkage'],'reason'=>$e['reason'],'visit_started_at'=>$e['visit_started_at'],
                'visit_goal_reaches'=>$e['visit_goal_reaches'],'visit_goal_value_rub'=>$e['visit_goal_value_rub']];
        }
        $prior=self::prior($row,$now);if($prior!==[])return self::forEvent($prior['cache'],$row,$now);
        return ['available'=>false,'reason'=>'not_in_checked_window'];
    }
    public static function privateSummary(int $now,ModeledConversionStatusBudget $budget):array
    {
        $c=self::readCache($now,$budget);if(($c['available']??false)!==true)return [...$c,'automatic'=>self::automatic($now)];
        $r=$c['receipt'];$out=['available'=>true,'fresh'=>$c['fresh'],'checked_at'=>$r['checked_at'],'checkpoint_at'=>$r['checkpoint_at'],
            'goal_id'=>'678187097','currency'=>'RUB','basis'=>$r['basis'],'automatic'=>self::automatic($now),
            'selected_rows'=>$r['selected_rows'],'other_rows_omitted'=>$r['other_rows_omitted'],'cohort_events'=>0,'cohort_value_rub'=>0,
            'processed_events'=>0,'processed_value_rub'=>0,'reconciled_events'=>0,'reconciled_value_rub'=>0,'examples'=>[]];
        $cases=[];
        foreach($r['events']as$e)if($e['cohort']){
            $out['cohort_events']++;$out['cohort_value_rub']+=$e['increment_rub'];
            if($e['upload_status']==='PROCESSED'){$out['processed_events']++;$out['processed_value_rub']+=$e['increment_rub'];}
            if($e['linkage']==='unique_visit_reconciled'){$out['reconciled_events']++;$out['reconciled_value_rub']+=$e['increment_rub'];}
            $key=$e['platform'].'|'.$e['conversation_id'];$cases[$key]??='dialogue_'.(count($cases)+1);
            $safe=['case'=>$cases[$key]];foreach(['platform','stage','increment_rub','signal_at','upload_status','linkage','reason',
                'visit_started_at','visit_goal_reaches','visit_goal_value_rub','expected_goal_reaches','expected_goal_value_rub']as$k)$safe[$k]=$e[$k];
            $safe['checked_at']=$e['checked_at']??$r['checked_at'];
            $out['examples'][]=$safe;
        }
        return $out;
    }
}
