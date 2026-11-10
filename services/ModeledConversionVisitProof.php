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
            .'o.value_stage,o.value_rub,o.event_unix,o.client_id,o.upload_id,o.external_user_id,o.state,o.upload_status,o.value_api_accepted_at_unix,o.value_processed_at_unix,o.claimed_at_unix,o.tracker_observed_unix '
            .ModeledConversionDeliveryRegistry::sourceSql($mysql).' ORDER BY o.id DESC LIMIT 1001');
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        if(count($rows)>=1001)throw new RuntimeException('verification_ledger_bound');
        return $rows;
    }
    public static function verify(array $ledger,object $api,int $activation,int $checkpoint,int $now,?array $selectedIds=null,?float $deadline=null,array $retained=[],?int $followupCheckpoint=null):array
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
                // An explicit bounded goal-cohort read can also verify legacy stage rows.
                if($selectedIds!==null&&in_array((string)$r['id'],$selectedIds,true))$other[]=$r;
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
                    $clientVisits=$api->modeledClientVisits($cid,(int)$r['event_unix'],$now);
                    $entry['reason']=self::failureReason($clientVisits,(int)$r['event_unix']);
                    if(++$requests>96||($deadline!==null&&microtime(true)>=$deadline))throw new RuntimeException('verification_request_bound');
                    $entry['failure_audit']=self::failureAudit($r,$v,$clientVisits,(method_exists($api,'modeledValueVisits')?$api->modeledValueVisits($cid,$activation,$now):['status'=>'unavailable']),$ledger,$now);
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
        $result=['version'=>self::VERSION,'project_key'=>'anytour','counter_id'=>'98615635','goal_id'=>'678187097','currency'=>'RUB',
            'checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'checkpoint_at'=>gmdate('Y-m-d\TH:i:s\Z',$checkpoint),
            'basis'=>'unique_client_and_second_precision_visit_start_reconciliation_not_native_visit_id_or_event_log',
            'ledger_complete'=>true,'selected_rows'=>count($selected),'other_rows_omitted'=>max(0,count($ledger)-count($records)),
            'events'=>array_values($records),'today_sent_cohort'=>self::dailySentCohort($ledger,$records,$now),'provider_write_requests'=>0,'source_writes'=>0];
        if($followupCheckpoint!==null){
            $result['followup_checkpoint_at']=gmdate('Y-m-d\\TH:i:s\\Z',$followupCheckpoint);
            $result['followup_sent_cohort']=self::followupReport($ledger,$records,$followupCheckpoint,$now);
        }
        return $result;
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
    /** Evidence only. A provider failure never becomes delivery credit or permission to resend. */
    public static function failureAudit(array $row,array $receipt,array $visits,array $goals,array $ledger,int $now):array
    {
        $event=(int)$row['event_unix'];$claim=(int)($row['claimed_at_unix']??0);
        $created=is_string($receipt['create_time']??null)?strtotime($receipt['create_time']):false;
        $uploaded=$created!==false&&$created>=946684800&&$created<=$now?$created:
            ($claim>=946684800&&$claim<=$now?$claim:null);
        $a=['signal_at'=>gmdate('Y-m-d\TH:i:s\Z',$event),'uploaded_at'=>$uploaded===null?null:gmdate('Y-m-d\TH:i:s\Z',$uploaded),
            'upload_clock'=>$created!==false&&$created===$uploaded?'provider':'claim',
            'latest_prior_visit_at'=>null,'eligible_at_signal'=>null,'eligible_at_upload'=>null,'wire_collision_rows'=>0,
            'known_processed_events'=>0,'known_processed_rub'=>0,'known_failed_events'=>0,'known_failed_rub'=>0,
            'other_unsettled_rows'=>0,'reported_goal_reaches'=>null,'reported_goal_rub'=>null,'report_comparison'=>'unavailable',
            'diagnosis'=>'unavailable','checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$now)];
        foreach($ledger as$r){
            if((string)($r['client_id']??'')!==(string)$row['client_id']||(string)($r['upload_id']??'')==='')continue;
            if((int)$r['event_unix']===$event&&(string)$r['id']!==(string)$row['id'])$a['wire_collision_rows']++;
            $status=$r['upload_status']??null;
            if($status==='PROCESSED'||($r['state']??null)==='processed'){$a['known_processed_events']++;$a['known_processed_rub']+=(int)$r['value_rub'];}
            elseif($status==='LINKAGE_FAILURE'||($r['state']??null)==='unmatched'){$a['known_failed_events']++;$a['known_failed_rub']+=(int)$r['value_rub'];}
            else $a['other_unsettled_rows']++;
        }
        if(($goals['status']??null)==='ok'&&is_array($goals['visits']??null)){
            $a['reported_goal_reaches']=0;$a['reported_goal_rub']=0;
            foreach($goals['visits']as$v){$a['reported_goal_reaches']+=(int)$v['goal_reaches'];$a['reported_goal_rub']+=(float)$v['revenue_rub'];}
            $a['reported_goal_rub']=round($a['reported_goal_rub'],2);
            $same=static fn(int $count,int $value):bool=>$a['reported_goal_reaches']===$count&&abs($a['reported_goal_rub']-$value)<0.005;
            $a['report_comparison']=$a['other_unsettled_rows']>0?'unsettled':
                ($same($a['known_processed_events']+$a['known_failed_events'],$a['known_processed_rub']+$a['known_failed_rub'])?'all_uploads':
                ($same($a['known_processed_events'],$a['known_processed_rub'])?'processed_only':'mismatch'));
        }
        if(($visits['status']??null)!=='ok'||!is_array($visits['visits']??null))return $a;
        $prior=[];
        foreach($visits['visits']as$v){$at=strtotime((string)($v['started_at']??''));if($at===false)return $a;if($at<=$event)$prior[]=$at;}
        $latest=$prior===[]?null:max($prior);
        $a['latest_prior_visit_at']=$latest===null?null:gmdate('Y-m-d\TH:i:s\Z',$latest);
        $a['eligible_at_signal']=$latest!==null&&$event-$latest<=21*86400;
        $a['eligible_at_upload']=$uploaded===null?null:($latest!==null&&$latest<=$uploaded&&$uploaded-$latest<=21*86400);
        $a['diagnosis']=$visits['visits']===[]?'no_reported_visits':($latest===null?'only_later_visits':
            (!$a['eligible_at_signal']?'outside_signal_window':($a['eligible_at_upload']===false?'outside_upload_window':
            ($a['wire_collision_rows']>0?'wire_collision':($a['report_comparison']==='all_uploads'?'reported_despite_failure':'eligible_unexplained')))));
        return $a;
    }
    /** Newest half plus oldest unchecked half; stable terminal evidence is rechecked daily. */
    public static function dueIds(array $ledger,int $checkpoint,int $now,callable $prior,int $batch=26):array
    {
        $due=[];$urgent=[];$cohort=0;
        foreach($ledger as$r){
            if(!ModeledConversionValueContract::isKey($r['target']??null)||empty($r['upload_id']))continue;
            $accepted=(int)($r['value_api_accepted_at_unix']??0);
            if(str_starts_with((string)$r['target'],'anytour_value_pred_')&&$accepted>0&&$accepted<=$checkpoint
                &&($r['value_processed_at_unix']===null||(int)$r['value_processed_at_unix']>$checkpoint)){$cohort++;continue;}
            $old=$prior($r);$at=(int)($old['at']??0);$e=$old['event']??[];
            $db=$r['upload_status']??null;
            if(in_array($db,['PROCESSED','LINKAGE_FAILURE'],true)&&($e['upload_status']??null)!==$db
                &&($at>0||(int)($r['value_processed_at_unix']??0)>=$now-900)){
                $urgent[]=(string)$r['id'];continue;
            }
            $terminal=($e['linkage']??null)==='unique_visit_reconciled'
                ||(($e['upload_status']??null)==='LINKAGE_FAILURE'&&!in_array($e['reason']??null,['provider_linkage_failure','client_visit_report_unavailable'],true));
            if($at===0||$now-$at>=($terminal?86400:300))$due[]=['id'=>(string)$r['id'],'at'=>$at];
        }
        $limit=max(0,min($batch,self::MAX_ROWS-$cohort));$ids=array_slice($urgent,0,$limit);
        $remaining=$limit-count($ids);
        foreach(array_slice($due,0,(int)ceil($remaining/2))as$d)$ids[]=$d['id'];
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
        $previous=self::readCache(time());
        if(!isset($receipt['followup_checkpoint_at'])&&($previous['available']??false)===true){
            $f=self::followupSummary($previous['receipt']['followup_sent_cohort']??null,time());
            if(($f['available']??false)===true){
                $receipt['followup_checkpoint_at']=$f['checkpoint_at'];
                $receipt['followup_sent_cohort']=$previous['receipt']['followup_sent_cohort'];
            }
        }
        self::directory(dirname(self::path()));
        // Keep per-row evidence after it rotates out of the bounded current report.
        foreach($receipt['events']as$e){
            $one=$receipt;unset($one['today_sent_cohort'],$one['followup_checkpoint_at'],$one['followup_sent_cohort']);$one['events']=[$e];$one['selected_rows']=1;
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

    private const DAILY_GROUPS=['sent','api_accepted','processed','visit_linked','linkage_failed','provider_processing',
        'unverified_visits','proof_missing','stale_proof','accepted_clock_unknown'];
    /** One fixed calendar and one sent-row cohort; all amounts remain private aggregates. */
    public static function dailySentCohort(array $ledger,array $current,int $now,?callable $archive=null,?int $dayReference=null):array
    {
        if(count($ledger)>=1001||$now<946684800)throw new RuntimeException('verification_daily_scope');
        $dayReference??=$now;if($dayReference<946684800||$dayReference>$now)throw new RuntimeException('verification_daily_scope');
        $zone=new DateTimeZone('Europe/Kaliningrad');$day=(new DateTimeImmutable('@'.$dayReference))->setTimezone($zone)->setTime(0,0);
        $from=$day->getTimestamp();$until=$day->modify('+1 day')->getTimestamp();$out=[];$people=[];
        foreach(['max','telegram']as$platform)foreach(self::DAILY_GROUPS as$group){
            $out[$platform][$group]=['events'=>0,'conversations'=>0,'value_rub'=>0];$people[$platform][$group]=[];
        }
        $archive??=static fn(array $r):array=>self::prior($r,$now);
        foreach($ledger as$r){
            $claim=(int)($r['claimed_at_unix']??0);
            if($claim<$from||$claim>=$until||$claim>$now)continue;
            $platform=$r['platform']??null;$value=(int)($r['value_rub']??0);
            if(!in_array($platform,['max','telegram'],true)||$value<=0||$value>ModeledConversionValueContract::MAX_VALUE_RUB
                ||($r['project_key']??null)!=='anytour'||(string)($r['counter_id']??'')!=='98615635'
                ||($r['transport_target']??null)!==ModeledConversionValueContract::TARGET
                ||($r['value_contract']??null)!==ModeledConversionValueContract::VERSION)throw new RuntimeException('verification_daily_binding');
            $old=$current[(string)$r['id']]??null;$at=is_array($old)?strtotime((string)($old['checked_at']??'')):false;
            if(!is_array($old)){$prior=$archive($r);$old=$prior['event']??null;$at=$prior['at']??false;}
            $proof=is_array($old)&&is_int($at)&&$at>=946684800&&$at<=$now
                &&hash_equals((string)($old['fingerprint']??''),self::fingerprint($r));
            $accepted=(string)($r['upload_id']??'')!=='';
            $status=$proof?($old['upload_status']??$r['upload_status']??null):($r['upload_status']??null);
            // A newer durable processing receipt must not be downgraded by an older archived UPLOADED check.
            $processed=$accepted&&($status==='PROCESSED'||($r['upload_status']??null)==='PROCESSED'||($r['state']??null)==='processed');
            $failed=$accepted&&!$processed&&($status==='LINKAGE_FAILURE'||($r['upload_status']??null)==='LINKAGE_FAILURE'||($r['state']??null)==='unmatched');
            $linked=$processed&&$proof&&($old['upload_status']??null)==='PROCESSED'&&($old['linkage']??null)==='unique_visit_reconciled';
            $flags=['sent'=>true,'api_accepted'=>$accepted,'processed'=>$processed,'visit_linked'=>$linked,
                'linkage_failed'=>$failed,'provider_processing'=>$accepted&&!$processed&&!$failed,
                'unverified_visits'=>$processed&&!$linked,'proof_missing'=>!$proof,
                'stale_proof'=>$proof&&$now-$at>900,'accepted_clock_unknown'=>$accepted&&(int)($r['value_api_accepted_at_unix']??0)<=0];
            foreach($flags as$group=>$include)if($include){
                $out[$platform][$group]['events']++;$out[$platform][$group]['value_rub']+=$value;
                $people[$platform][$group][(string)$r['conversation_id']]=true;
            }
        }
        foreach(['max','telegram']as$platform)foreach(self::DAILY_GROUPS as$group)
            $out[$platform][$group]['conversations']=count($people[$platform][$group]);
        return ['version'=>'modeled_today_sent_cohort_v1','available'=>true,'goal_id'=>'678187097','counter_id'=>'98615635','currency'=>'RUB',
            'date'=>$day->format('Y-m-d'),'timezone'=>'Europe/Kaliningrad','as_of'=>gmdate('Y-m-d\\TH:i:s\\Z',$now),
            'window'=>['from'=>gmdate('Y-m-d\\TH:i:s\\Z',$from),'until_exclusive'=>gmdate('Y-m-d\\TH:i:s\\Z',$until)],
            'basis'=>'last_persisted_upload_claim_same_cohort_receipt_and_visit_evidence_not_payment_or_session_day',
            'ledger_complete'=>true,'by_platform'=>$out];
    }
    private static function dailySummary(mixed $d,int $now,?int $dayReference=null):array
    {
        $fail=['available'=>false,'reason'=>'not_recorded'];
        if(!is_array($d))return $fail;
        if(($d['version']??null)!=='modeled_today_sent_cohort_v1'||($d['available']??null)!==true||($d['goal_id']??null)!=='678187097'
            ||($d['counter_id']??null)!=='98615635'||($d['currency']??null)!=='RUB'||($d['timezone']??null)!=='Europe/Kaliningrad'
            ||($d['ledger_complete']??null)!==true||!is_string($d['as_of']??null)||!is_string($d['date']??null)
            ||!is_array($d['window']??null)||array_keys($d['window'])!==['from','until_exclusive']
            ||!is_array($d['by_platform']??null)||array_keys($d['by_platform'])!==['max','telegram'])return ['available'=>false,'reason'=>'invalid_daily_cache'];
        $at=strtotime($d['as_of']);if($at===false||$at>$now||$at<946684800)return ['available'=>false,'reason'=>'invalid_daily_cache'];
        $zone=new DateTimeZone('Europe/Kaliningrad');$day=(new DateTimeImmutable('@'.($dayReference??$at)))->setTimezone($zone)->setTime(0,0);
        if($d['date']!==$day->format('Y-m-d')||($d['window']['from']??null)!==gmdate('Y-m-d\\TH:i:s\\Z',$day->getTimestamp())
            ||($d['window']['until_exclusive']??null)!==gmdate('Y-m-d\\TH:i:s\\Z',$day->modify('+1 day')->getTimestamp()))return ['available'=>false,'reason'=>'invalid_daily_cache'];
        $safe=[];
        foreach(['max','telegram']as$p){
            $groups=$d['by_platform'][$p];if(!is_array($groups)||array_keys($groups)!==self::DAILY_GROUPS)return ['available'=>false,'reason'=>'invalid_daily_cache'];
            foreach($groups as$k=>$v){
                if(!is_array($v)||array_keys($v)!==['events','conversations','value_rub']
                    ||!is_int($v['events'])||!is_int($v['conversations'])||!is_int($v['value_rub'])||$v['events']<0||$v['events']>1000
                    ||$v['conversations']<0||$v['conversations']>$v['events']||$v['value_rub']<0||$v['value_rub']>1000*ModeledConversionValueContract::MAX_VALUE_RUB
                    ||$v['value_rub']<$v['events']||$v['value_rub']>$v['events']*ModeledConversionValueContract::MAX_VALUE_RUB
                    ||($v['events']>0&&$v['conversations']===0)||($v['events']===0&&$v['value_rub']!==0))return ['available'=>false,'reason'=>'invalid_daily_cache'];
                if($k!=='sent'&&($v['events']>$groups['sent']['events']||$v['value_rub']>$groups['sent']['value_rub']||$v['conversations']>$groups['sent']['conversations']))return ['available'=>false,'reason'=>'invalid_daily_cache'];
            }
            if($groups['processed']['events']+$groups['linkage_failed']['events']+$groups['provider_processing']['events']!==$groups['api_accepted']['events']
                ||$groups['visit_linked']['events']+$groups['unverified_visits']['events']!==$groups['processed']['events'])return ['available'=>false,'reason'=>'invalid_daily_cache'];
            if($groups['processed']['value_rub']+$groups['linkage_failed']['value_rub']+$groups['provider_processing']['value_rub']!==$groups['api_accepted']['value_rub']
                ||$groups['visit_linked']['value_rub']+$groups['unverified_visits']['value_rub']!==$groups['processed']['value_rub']
                ||$groups['accepted_clock_unknown']['events']>$groups['api_accepted']['events']
                ||$groups['accepted_clock_unknown']['value_rub']>$groups['api_accepted']['value_rub'])return ['available'=>false,'reason'=>'invalid_daily_cache'];
            $safe[$p]=$groups;
        }
        return ['available'=>true,'fresh'=>$now-$at<=900&&($dayReference!==null||$day->format('Y-m-d')===(new DateTimeImmutable('@'.$now))->setTimezone($zone)->format('Y-m-d')),
            'date'=>$d['date'],'timezone'=>$d['timezone'],'as_of'=>$d['as_of'],'window'=>$d['window'],'goal_id'=>'678187097','currency'=>'RUB',
            'basis'=>'last_persisted_upload_claim_same_cohort_receipt_and_visit_evidence_not_payment_or_session_day',
            'ledger_complete'=>true,'by_platform'=>$safe];
    }


    /** Reconstruct a fixed sent-before snapshot from durable claim/first-processing clocks. */
    public static function followupReport(array $ledger,array $current,int $cut,int $now,?callable $archive=null):array
    {
        if($cut<946684800||$cut>$now)throw new RuntimeException('verification_followup_scope');
        $zone=new DateTimeZone('Europe/Kaliningrad');$day=(new DateTimeImmutable('@'.$cut))->setTimezone($zone)->setTime(0,0);
        $from=$day->getTimestamp();$before=[];$processed=[];$remaining=[];$unknown=[];
        foreach($ledger as$r){
            $claim=(int)($r['claimed_at_unix']??0);if($claim<$from||$claim>$cut)continue;
            $before[]=$r;$at=(int)($r['value_processed_at_unix']??0);
            if($at>0&&$at<=$cut)$processed[]=$r;else $remaining[]=$r;
            if($at<=0&&(($r['state']??null)==='processed'||($r['upload_status']??null)==='PROCESSED'))$unknown[]=$r;
        }
        $empty=static fn(array $r):array=>[];
        $a=self::dailySentCohort($before,[],$now,$empty,$cut);
        $b=self::dailySentCohort($processed,[],$now,$empty,$cut);
        $u=self::dailySentCohort($unknown,[],$now,$empty,$cut);
        $out=self::dailySentCohort($remaining,$current,$now,$archive,$cut);$base=[];$failures=[];
        $archive??=static fn(array $r):array=>self::prior($r,$now);
        foreach(['max','telegram']as$p)$base[$p]=['sent'=>$a['by_platform'][$p]['sent'],
            'processed_at_checkpoint'=>$b['by_platform'][$p]['sent'],'processing_clock_unknown'=>$u['by_platform'][$p]['sent']];
        foreach($remaining as$r){
            if(count($failures)>=16)break;
            $e=$current[(string)$r['id']]??null;if(!is_array($e))$e=$archive($r)['event']??null;
            if(!is_array($e)||!hash_equals((string)($e['fingerprint']??''),self::fingerprint($r))||($e['upload_status']??null)!=='LINKAGE_FAILURE')continue;
            $clock=(int)($r['tracker_observed_unix']??0);$age=(int)$r['event_unix']-$clock;
            $reason=$e['reason']??null;
            if(!in_array($reason,['provider_linkage_failure','no_reported_client_visits','only_later_or_expired_client_visits',
                'no_client_visit_in_attribution_window','eligible_visit_but_provider_linkage_failed','client_visit_report_unavailable'],true))$reason='unavailable';
            $failures[]=['platform'=>$r['platform'],'increment_rub'=>(int)$r['value_rub'],'reason'=>$reason,
                'source_clock'=>($r['platform']!=='max'||$clock<946684800)?'unknown':($age<0?'after_signal':($age>21*86400?'expired':'within_window')),
                'audit'=>self::validatedFailureAudit($e['failure_audit']??null,$now)];
        }
        return ['version'=>'modeled_followup_sent_cohort_v1','checkpoint_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$cut),
            'selection_basis'=>'claim_at_or_before_checkpoint_first_processing_observation_after_checkpoint_or_missing',
            'baseline_by_platform'=>$base,'failure_examples'=>$failures,'outcome'=>$out];
    }
    /** Fixed anonymous scalar projection; source identities and raw provider fields cannot cross MCP. */
    private static function validatedFailureAudit(mixed $v,int $now):?array
    {
        if(!is_array($v))return null;
        $keys=['signal_at','uploaded_at','upload_clock','latest_prior_visit_at','eligible_at_signal','eligible_at_upload','wire_collision_rows',
            'known_processed_events','known_processed_rub','known_failed_events','known_failed_rub','other_unsettled_rows',
            'reported_goal_reaches','reported_goal_rub','report_comparison','diagnosis','checked_at'];
        if(array_keys($v)!==$keys)return null;
        foreach(['signal_at','uploaded_at','latest_prior_visit_at','checked_at']as$k){
            if($v[$k]===null&&in_array($k,['uploaded_at','latest_prior_visit_at'],true))continue;
            if(!is_string($v[$k])||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$v[$k])!==1
                ||($at=strtotime($v[$k]))===false||$at<946684800||$at>$now)return null;
        }
        foreach(['eligible_at_signal','eligible_at_upload']as$k)if($v[$k]!==null&&!is_bool($v[$k]))return null;
        foreach(['wire_collision_rows','known_processed_events','known_failed_events','other_unsettled_rows']as$k)
            if(!is_int($v[$k])||$v[$k]<0||$v[$k]>1000)return null;
        foreach(['known_processed_rub','known_failed_rub','reported_goal_reaches','reported_goal_rub']as$k){
            if($v[$k]===null&&str_starts_with($k,'reported_'))continue;
            if((!is_int($v[$k])&&!is_float($v[$k]))||!is_finite((float)$v[$k])||$v[$k]<0||$v[$k]>1000000000)return null;
        }
        if(!in_array($v['upload_clock'],['provider','claim'],true)
            ||!in_array($v['report_comparison'],['unavailable','unsettled','all_uploads','processed_only','mismatch'],true)
            ||!in_array($v['diagnosis'],['unavailable','no_reported_visits','only_later_visits','outside_signal_window',
                'outside_upload_window','wire_collision','reported_despite_failure','eligible_unexplained'],true))return null;
        return $v;
    }
    private static function followupSummary(mixed $v,int $now):array
    {
        $fail=['available'=>false,'reason'=>'not_recorded'];
        if(!is_array($v))return $fail;
        if(($v['version']??null)!=='modeled_followup_sent_cohort_v1'||!is_string($v['checkpoint_at']??null))return ['available'=>false,'reason'=>'invalid_followup_cache'];
        $cut=strtotime($v['checkpoint_at']);if($cut===false||$cut<946684800||$cut>$now)return ['available'=>false,'reason'=>'invalid_followup_cache'];
        $d=self::dailySummary($v['outcome']??null,$now,$cut);if(($d['available']??false)!==true)return ['available'=>false,'reason'=>'invalid_followup_cache'];
        $base=$v['baseline_by_platform']??null;if(!is_array($base)||array_keys($base)!==['max','telegram'])return ['available'=>false,'reason'=>'invalid_followup_cache'];
        foreach($base as$p=>$groups){
            if(!is_array($groups)||array_keys($groups)!==['sent','processed_at_checkpoint','processing_clock_unknown'])return ['available'=>false,'reason'=>'invalid_followup_cache'];
            foreach($groups as$g){
                if(!is_array($g)||array_keys($g)!==['events','conversations','value_rub']
                    ||!is_int($g['events'])||$g['events']<0||$g['events']>1000||!is_int($g['conversations'])
                    ||$g['conversations']<0||$g['conversations']>$g['events']||!is_int($g['value_rub'])
                    ||$g['value_rub']<$g['events']||$g['value_rub']>$g['events']*ModeledConversionValueContract::MAX_VALUE_RUB)return ['available'=>false,'reason'=>'invalid_followup_cache'];
            }
            if($groups['processed_at_checkpoint']['events']+$d['by_platform'][$p]['sent']['events']!==$groups['sent']['events']
                ||$groups['processed_at_checkpoint']['value_rub']+$d['by_platform'][$p]['sent']['value_rub']!==$groups['sent']['value_rub'])
                return ['available'=>false,'reason'=>'invalid_followup_cache'];
        }
        $failures=$v['failure_examples']??[];
        if(!is_array($failures)||count($failures)>16)return ['available'=>false,'reason'=>'invalid_followup_cache'];
        foreach($failures as$e)if(!is_array($e)||!in_array(array_keys($e),[['platform','increment_rub','reason','source_clock'],['platform','increment_rub','reason','source_clock','audit']],true)
            ||!in_array($e['platform'],['max','telegram'],true)||!is_int($e['increment_rub'])||$e['increment_rub']<=0
            ||$e['increment_rub']>ModeledConversionValueContract::MAX_VALUE_RUB
            ||!in_array($e['reason'],['provider_linkage_failure','no_reported_client_visits','only_later_or_expired_client_visits',
                'no_client_visit_in_attribution_window','eligible_visit_but_provider_linkage_failed','client_visit_report_unavailable','unavailable'],true)
            ||!in_array($e['source_clock'],['unknown','after_signal','expired','within_window'],true)
            ||(isset($e['audit'])&&self::validatedFailureAudit($e['audit'],$now)!==$e['audit']))return ['available'=>false,'reason'=>'invalid_followup_cache'];
        return ['available'=>true,'fresh'=>$d['fresh'],'checkpoint_at'=>$v['checkpoint_at'],'as_of'=>$d['as_of'],
            'date'=>$d['date'],'timezone'=>$d['timezone'],'currency'=>'RUB','goal_id'=>'678187097',
            'selection_basis'=>'claim_at_or_before_checkpoint_first_processing_observation_after_checkpoint_or_missing',
            'baseline_by_platform'=>$base,'by_platform'=>$d['by_platform'],'failure_examples'=>$failures];
    }

    public static function privateSummary(int $now,ModeledConversionStatusBudget $budget):array
    {
        $c=self::readCache($now,$budget);if(($c['available']??false)!==true)return [...$c,'automatic'=>self::automatic($now)];
        $r=$c['receipt'];$out=['available'=>true,'fresh'=>$c['fresh'],'checked_at'=>$r['checked_at'],'checkpoint_at'=>$r['checkpoint_at'],
            'goal_id'=>'678187097','currency'=>'RUB','basis'=>$r['basis'],'automatic'=>self::automatic($now),
            'selected_rows'=>$r['selected_rows'],'other_rows_omitted'=>$r['other_rows_omitted'],
            'today_sent_cohort'=>self::dailySummary($r['today_sent_cohort']??null,$now),
            'followup_sent_cohort'=>self::followupSummary($r['followup_sent_cohort']??null,$now),'cohort_events'=>0,'cohort_value_rub'=>0,
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
