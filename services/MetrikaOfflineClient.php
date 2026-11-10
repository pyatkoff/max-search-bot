<?php
declare(strict_types=1);
require_once __DIR__.'/MaxTrackerLookupProtocol.php';
require_once __DIR__.'/ModeledConversionAttributionWindow.php';
require_once __DIR__.'/MaxTrackerLookupConfig.php';
require_once __DIR__.'/MaxChannelConversionOutbox.php';

/** Existing counter/token, new ClientId CSV transport. No raw API logging. */
final class MetrikaOfflineClient
{
    // Historical wire identifiers remain valid for receipt recovery only.
    public const CHANNEL_GOALS=[
        'max_bot_activity_start'=>['target'=>'max_channel_activity_start','name'=>'MAX канал: начало общения'],
        'max_manager_reply'=>['target'=>'max_channel_manager_reply','name'=>'MAX канал: ответ менеджера'],
        'max_customer_reply_after_manager'=>['target'=>'max_channel_customer_reply_after_manager','name'=>'MAX канал: клиент ответил менеджеру'],
    ];
    public const SHARED_GOALS=[
        'max_bot_activity_start'=>['id'=>'611089509','target'=>'max_bot_activity_start','name'=>'max_bot_activity_start'],
        'max_manager_request'=>['id'=>'600725752','target'=>'max_manager_request','name'=>'max_manager_request'],
        'max_customer_reply_after_manager'=>['id'=>'605075686','target'=>'max_customer_reply_after_manager','name'=>'max_customer_reply_after_manager'],
    ];
    private string $counter;
    private string $token;
    private $transport;
    private ?float $readDeadline;
    private ?DateTimeZone $reportZone=null;
    public function __construct(string $counter,string $token,?callable $transport=null,?float $readDeadline=null)
    {
        if(!MaxTrackerLookupProtocol::id($counter)||$token==='')throw new RuntimeException('metrika_config_missing');
        $this->counter=$counter;$this->token=$token;$this->transport=$transport;$this->readDeadline=$readDeadline;
    }

    public static function comment(array $event): string
    {
        return 'MaxChannel'.hash('sha256',implode('|',[(string)$event['project_key'],(string)$event['conversation_id'],(string)$event['target'],(string)$event['event_unix']]));
    }

    public static function csv(array $event,int $now): string
    {
        if(!MaxTrackerLookupProtocol::id($event['client_id']??null,40)
            ||!in_array($event['target']??'',MaxChannelConversionOutbox::HISTORICAL_TARGETS,true)
            ||!is_numeric($event['event_unix']??null)||(int)$event['event_unix']<=0||(int)$event['event_unix']>$now
            ||(int)$event['event_unix']<$now-21*86400)throw new InvalidArgumentException('invalid_conversion_event');
        $allowed=[];
        if(isset(self::CHANNEL_GOALS[$event['target']]))$allowed[]=self::CHANNEL_GOALS[$event['target']]['target'];
        if(isset(self::SHARED_GOALS[$event['target']]))$allowed[]=self::SHARED_GOALS[$event['target']]['target'];
        if(!in_array($event['transport_target']??null,$allowed,true))throw new InvalidArgumentException('invalid_conversion_target');
        $f=fopen('php://temp','w+');fputcsv($f,['ClientId','Target','DateTime']);
        fputcsv($f,[$event['client_id'],$event['transport_target'],(string)$event['event_unix']]);rewind($f);$csv=stream_get_contents($f);fclose($f);return $csv;
    }

    public function upload(array $event,int $now): array
    {
        if(($event['counter_id']??null)!==$this->counter)throw new InvalidArgumentException('wrong_conversion_counter');
        if(!isset(self::SHARED_GOALS[$event['target']??''])||($event['transport_target']??null)!==self::SHARED_GOALS[$event['target']]['target'])throw new InvalidArgumentException('retired_conversion_target');
        $csv=self::csv($event,$now);$result=$this->request('POST','offline_conversions/upload?type=BASIC&comment='.rawurlencode(self::comment($event)),$csv);
        $error=(int)($result['error']??0);$http=(int)($result['http']??0);
        if($error!==0)return ['outcome'=>in_array($error,[5,6,7],true)?'retry':'unknown','reason'=>in_array($error,[5,6,7],true)?'transport_not_sent':'upload_outcome_unknown'];
        if(in_array($http,[420,429],true))return ['outcome'=>'retry','reason'=>'rate_limited'];
        if($http>=400&&$http<500)return ['outcome'=>'rejected','reason'=>'api_rejected'];
        $d=$this->decode($result);$u=$d['uploading']??null;
        if($http>=200&&$http<300&&$this->validReceipt($u)
            &&(!array_key_exists('comment',$u)||$u['comment']===self::comment($event)))return ['outcome'=>'accepted','upload_id'=>(string)$u['id'],'status'=>(string)($u['status']??'UPLOADED')];
        return ['outcome'=>'unknown','reason'=>'upload_outcome_unknown'];
    }

    public function poll(string $uploadId): ?array
    {
        if(!MaxTrackerLookupProtocol::id($uploadId))return null;
        $r=$this->request('GET','offline_conversions/uploading/'.$uploadId);
        if((int)($r['error']??0)!==0||(int)($r['http']??0)!==200)return null;
        $d=$this->decode($r);$u=$d['uploading']??null;
        return $this->validReceipt($u)&&(string)$u['id']===$uploadId?$u:null;
    }

    /** Read-only recovery of a lost POST acknowledgement; no automatic resend. */
    public function findUnknown(array $event): ?array
    {
        $comment=self::comment($event);$matches=[];
        for($page=0;$page<3;$page++){
            $r=$this->request('GET','offline_conversions/uploadings?limit=100&offset='.($page*100));
            if((int)($r['error']??0)!==0||(int)($r['http']??0)!==200)return null;
            $d=$this->decode($r);$list=$d['uploadings']??null;if(!is_array($list))return null;
            foreach($list as $u)if($this->validReceipt($u)&&($u['comment']??'')===$comment)$matches[(string)$u['id']]=$u;
            if(count($list)<100)break;
        }
        return count($matches)===1?array_values($matches)[0]:null;
    }

    /** The live BASIC API omits client_id_type; explicit conflicting values fail closed. */
    private function validReceipt($receipt): bool
    {
        return is_array($receipt)
            &&(is_int($receipt['id']??null)||is_string($receipt['id']??null))
            &&MaxTrackerLookupProtocol::id((string)$receipt['id'])
            &&in_array($receipt['status']??null,['PREPARED','UPLOADED','EXPORTED','MATCHED','PROCESSED','LINKAGE_FAILURE'],true)
            &&(!array_key_exists('type',$receipt)||$receipt['type']==='BASIC')
            &&(!array_key_exists('client_id_type',$receipt)||$receipt['client_id_type']==='CLIENT_ID');
    }

    public function goalsReady(): bool
    {
        try{
            $goals=$this->readGoals();
            $selected=$this->selectedGoals($goals);
            foreach(self::SHARED_GOALS as $target=>$definition){
                $matches=$this->exactGoals($goals,$target);
                if(count($matches)!==1||(string)$matches[0]['id']!==$definition['id'])return false;
                if(($selected[$target]['conditions'][0]['type']??null)!=='exact')return false;
            }
            return true;
        }catch(Throwable $ignored){return false;}
    }

    /** Explicit owner-selected existing IDs only: no creation, deletion or renaming. */
    public function configureSharedGoals(bool $apply=false): array
    {
        if($this->counter!=='98615635')throw new RuntimeException('wrong_goal_counter');
        $before=$this->readGoals();$selected=$this->selectedGoals($before);$receipts=[];$changed=[];
        foreach(self::SHARED_GOALS as $target=>$definition){
            $goal=$selected[$target];$needsUpdate=$goal['conditions'][0]['type']!=='exact';
            $receipts[$target]=['id'=>$definition['id'],'ready'=>!$needsUpdate,'updated'=>false];
        }
        foreach($apply?self::SHARED_GOALS:[] as $target=>$definition){
            $goal=$selected[$target];if($goal['conditions'][0]['type']==='exact')continue;
            // Check the exact ID again before editing; a concurrent operator change
            // must never be overwritten with this earlier snapshot.
            $fresh=$this->selectedGoals($this->readGoals())[$target];
            if($this->goalSemantics($fresh)!==$this->goalSemantics($goal))throw new RuntimeException('existing_goal_changed');
            $replacement=$goal;$replacement['conditions']=[['type'=>'exact','url'=>$target]];
            $payload=[];foreach(['id','name','type','default_price','is_favorite','status','flag','conditions'] as $key)if(array_key_exists($key,$replacement))$payload[$key]=$replacement[$key];
            $r=$this->request('PUT','goal/'.$definition['id'],null,['goal'=>$payload]);
            if((int)($r['error']??0)!==0||(int)($r['http']??0)!==200)throw new RuntimeException('goal_update_requires_readback');
            $data=$this->decode($r);$actual=$data['goal']??null;
            if(!is_array($actual)||$this->goalSemantics($actual)!==$this->goalSemantics($replacement))throw new RuntimeException('goal_update_requires_readback');
            $changed[$definition['id']]=$replacement;
            $receipts[$target]=['id'=>$definition['id'],'ready'=>true,'updated'=>true];
        }
        if($apply){
            $after=$this->readGoals();$byId=[];foreach($after as $goal)$byId[(string)($goal['id']??'')]=$goal;
            if(count($after)!==count($before))throw new RuntimeException('existing_goal_changed');
            foreach($before as $goal){
                $id=(string)($goal['id']??'');$expected=$changed[$id]??$goal;
                if(!isset($byId[$id])||$this->goalSemantics($expected)!==$this->goalSemantics($byId[$id]))throw new RuntimeException('existing_goal_changed');
            }
            foreach(self::SHARED_GOALS as $target=>$definition){
                $matches=$this->exactGoals($after,$target);
                if(count($matches)!==1||(string)$matches[0]['id']!==$definition['id'])throw new RuntimeException('goal_update_requires_readback');
            }
        }
        return ['goals'=>$receipts,'existing_goals_checked'=>count($before),'only_selected_conditions_changed'=>$apply,'goal_writes'=>count($changed),'conversion_requests'=>0];
    }


    /** One explicitly owner-authorized experimental goal; never modifies an existing goal. */
    public function modeledValueGoalStatus():?array
    {
        require_once __DIR__.'/ModeledConversionValueContract.php';
        if($this->counter!=='98615635')throw new RuntimeException('wrong_goal_counter');
        $matches=[];
        foreach($this->readGoals()as$goal)foreach((array)($goal['conditions']??[])as$condition)
            if(($condition['url']??null)===ModeledConversionValueContract::TARGET)$matches[(string)($goal['id']??'')]=$goal;
        if(!$matches)return null;
        if(count($matches)!==1)throw new RuntimeException('modeled_goal_conflict');
        $goal=array_values($matches)[0];
        if(($goal['type']??null)!=='action'||($goal['name']??null)!==ModeledConversionValueContract::NAME
            ||!is_array($goal['conditions']??null)||count($goal['conditions'])!==1
            ||($goal['conditions'][0]['type']??null)!=='exact'||($goal['conditions'][0]['url']??null)!==ModeledConversionValueContract::TARGET
            ||!MaxTrackerLookupProtocol::id((string)($goal['id']??'')))throw new RuntimeException('modeled_goal_conflict');
        return ['id'=>(string)$goal['id'],'target'=>ModeledConversionValueContract::TARGET];
    }

    /** Caller must durably reserve a single creation intent before calling this POST. */
    public function createModeledValueGoal():array
    {
        $existing=$this->modeledValueGoalStatus();if($existing)return $existing+['created'=>false];
        $before=$this->readGoals();
        $goal=['name'=>ModeledConversionValueContract::NAME,'type'=>'action','default_price'=>0,
            'conditions'=>[['type'=>'exact','url'=>ModeledConversionValueContract::TARGET]]];
        $r=$this->request('POST','goals',null,['goal'=>$goal]);
        if((int)($r['error']??0)!==0||(int)($r['http']??0)!==200)throw new RuntimeException('modeled_goal_create_unknown');
        $actual=$this->modeledValueGoalStatus();if(!$actual)throw new RuntimeException('modeled_goal_create_unknown');
        $after=$this->readGoals();$byId=[];foreach($after as$g)$byId[(string)($g['id']??'')]=$g;
        if(count($after)!==count($before)+1)throw new RuntimeException('modeled_goal_readback_conflict');
        foreach($before as$g){$id=(string)$g['id'];if(!isset($byId[$id])||$this->goalSemantics($g)!==$this->goalSemantics($byId[$id]))throw new RuntimeException('modeled_goal_readback_conflict');}
        return $actual+['created'=>true];
    }

    public static function modeledValueCsv(array $event,int $now):string
    {
        require_once __DIR__.'/ModeledConversionValueContract.php';
        $value=(string)($event['value_rub']??'');
        if(($event['counter_id']??null)!=='98615635'||($event['project_key']??null)!=='anytour'
            ||!ModeledConversionValueContract::isKey($event['target']??null)
            ||($event['transport_target']??null)!==ModeledConversionValueContract::TARGET
            ||($event['value_contract']??null)!==ModeledConversionValueContract::VERSION
            ||!MaxTrackerLookupProtocol::id($event['client_id']??null,40)
            ||preg_match('/\A[1-9][0-9]{0,5}(?:\.0{1,2})?\z/D',$value)!==1||(float)$value>ModeledConversionValueContract::MAX_VALUE_RUB
            ||!is_numeric($event['event_unix']??null)||(int)$event['event_unix']>$now||(int)$event['event_unix']<$now-21*86400)
            throw new InvalidArgumentException('invalid_modeled_value_event');
        $f=fopen('php://temp','w+');fputcsv($f,['ClientId','Target','DateTime','Price','Currency']);
        fputcsv($f,[$event['client_id'],ModeledConversionValueContract::TARGET,(string)$event['event_unix'],(string)(int)$value,'RUB']);
        rewind($f);$csv=stream_get_contents($f);fclose($f);return $csv;
    }

    public function uploadModeledValue(array $event,int $now):array
    {
        if($this->counter!=='98615635')throw new RuntimeException('wrong_goal_counter');
        $csv=self::modeledValueCsv($event,$now);
        $r=$this->request('POST','offline_conversions/upload?type=BASIC&comment='.rawurlencode(self::comment($event)),$csv);
        $error=(int)($r['error']??0);$http=(int)($r['http']??0);
        if($error!==0)return ['outcome'=>in_array($error,[5,6,7],true)?'retry':'unknown','reason'=>in_array($error,[5,6,7],true)?'transport_not_sent':'upload_outcome_unknown'];
        if(in_array($http,[420,429],true))return ['outcome'=>'retry','reason'=>'rate_limited'];
        if($http>=400&&$http<500)return ['outcome'=>'rejected','reason'=>'api_rejected'];
        $d=$this->decode($r);$u=$d['uploading']??null;
        if($http>=200&&$http<300&&$this->validReceipt($u)&&(!isset($u['comment'])||$u['comment']===self::comment($event)))
            return ['outcome'=>'accepted','upload_id'=>(string)$u['id'],'status'=>$u['status']];
        return ['outcome'=>'unknown','reason'=>'upload_outcome_unknown'];
    }


    /** Explicit terminal-collision correction only. Never called by the uploader cron or MCP. */
    public function uploadModeledWireCorrection(array $members,int $now):array
    {
        require_once __DIR__.'/ModeledConversionWireValue.php';
        $plan=ModeledConversionWireValue::plan($members,$now);
        if($this->counter!=='98615635')throw new RuntimeException('wrong_goal_counter');
        $csv=self::modeledValueCsv($plan['event'],$now);
        $observed=$this->modeledAttributionVisits((string)$plan['event']['client_id'],(int)$plan['event']['event_unix'],$now);
        $dispatchAt=$this->transport!==null?$now:time();
        if(!ModeledConversionAttributionWindow::assess($observed,(int)$plan['event']['event_unix'],$dispatchAt)['allowed'])
            return ['outcome'=>'rejected','reason'=>'metrika_visit_window'];

        $r=$this->request('POST','offline_conversions/upload?type=BASIC&comment='.rawurlencode($plan['comment']),$csv);
        $error=(int)($r['error']??0);$http=(int)($r['http']??0);
        if($error!==0)return ['outcome'=>'unknown','reason'=>'upload_outcome_unknown'];
        if($http>=400&&$http<500)return ['outcome'=>'rejected','reason'=>'api_rejected'];
        $u=$this->decode($r)['uploading']??null;
        if($http>=200&&$http<300&&$this->validReceipt($u)&&($u['comment']??null)===$plan['comment']
            &&(int)($u['source_quantity']??0)===1&&(int)($u['line_quantity']??0)===1)
            return ['outcome'=>'accepted','upload_id'=>(string)$u['id'],'status'=>$u['status']];
        return ['outcome'=>'unknown','reason'=>'upload_outcome_unknown'];
    }
    public function findModeledWireCorrection(array $members,int $now):?array
    {
        require_once __DIR__.'/ModeledConversionWireValue.php';
        $comment=ModeledConversionWireValue::plan($members,$now)['comment'];$matches=[];
        for($page=0;$page<3;$page++){
            $r=$this->request('GET','offline_conversions/uploadings?limit=100&offset='.($page*100));
            if((int)($r['error']??0)!==0||(int)($r['http']??0)!==200)return null;
            $list=$this->decode($r)['uploadings']??null;if(!is_array($list))return null;
            foreach($list as$u)if($this->validReceipt($u)&&($u['comment']??null)===$comment)$matches[(string)$u['id']]=$u;
            if(count($list)<100)break;
        }
        return count($matches)===1?array_values($matches)[0]:null;
    }

    /** Fixed aggregate session report for the explicitly authorized value goal. GET only, no dimensions/identities. */
    public function modeledValueReport(int $activated,int $now):array
    {
        $base=['status'=>'unavailable','goal_id'=>'678187097','currency'=>'RUB',
            'fetched_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$now),'date1'=>gmdate('Y-m-d',max(946684800,$activated-21*86400)),'date2'=>'today',
            'goal_reaches'=>null,'goal_visits'=>null,'revenue_rub'=>null,'sampled'=>null];
        if($this->counter!=='98615635'||$activated<946684800||$activated>$now)return $base+['reason'=>'invalid_scope'];
        $metrics=['ym:s:goal678187097reaches','ym:s:goal678187097visits','ym:s:goal678187097convertedRUBRevenue'];
        $query=['ids'=>'98615635','metrics'=>implode(',',$metrics),'date1'=>$base['date1'],'date2'=>'today','accuracy'=>'full','limit'=>1];
        $r=$this->request('GET','data?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),null,null,true);
        if((int)($r['error']??0)!==0)return $base+['reason'=>'transport_unavailable'];
        $http=(int)($r['http']??0);
        if($http===403||$http===401){$base['status']='forbidden';return $base+['reason'=>'report_scope_or_counter_access'];}
        if($http!==200)return $base+['reason'=>'report_api_unavailable'];
        $d=$this->decode($r);$q=$d['query']??[];$totals=$d['totals']??null;
        $ids=is_array($q['ids']??null)?array_map('strval',$q['ids']):[];
        if($ids!==['98615635']||($q['metrics']??null)!==$metrics||!is_array($totals)||count($totals)!==3
            ||!is_bool($d['sampled']??null))return $base+['reason'=>'invalid_report'];
        foreach($totals as$v)if((!is_int($v)&&!is_float($v))||!is_finite((float)$v)||$v<0||$v>1000000000000)return $base+['reason'=>'invalid_totals'];
        if(((float)$totals[0])!==floor((float)$totals[0]))return $base+['reason'=>'invalid_counts'];
        if(((float)$totals[1])!==floor((float)$totals[1])||$totals[1]>$totals[0])return $base+['reason'=>'invalid_counts'];
        $base['status']=$d['sampled']?'sampled':'ok';$base['sampled']=$d['sampled'];
        $base['goal_reaches']=(int)$totals[0];$base['goal_visits']=(int)$totals[1];$base['revenue_rub']=round((float)$totals[2],2);
        $base['date2']=is_string($q['date2']??null)?$q['date2']:'today';
        return $base;
    }

    /** Exact ClientID + second precision goal-visit report. GET only; no claim of native VisitID/event-log evidence. */
    public function modeledValueVisits(string $clientId,int $activated,int $now):array
    {
        return $this->visitReport($clientId,$activated,$now,true);
    }
    /** Exact ClientID visits without a goal filter: diagnose rejected linkage, never credit it. */
    public function modeledClientVisits(string $clientId,int $activated,int $now):array
    {
        return $this->visitReport($clientId,$activated,$now,false);
    }
    /** Exact last-transition source, unaffected by historical attribution models. GET only. */
    public function modeledAttributionVisits(string $clientId,int $activated,int $now):array
    {
        // Optional advertising dimensions can omit visits with undefined source labels.
        // Keep the complete unfiltered visit list; source evidence may only enrich it.
        $base=$this->visitReport($clientId,$activated,$now,false);
        if(($base['status']??null)!=='ok'||($base['visits']??[])===[])return $base;
        foreach($base['visits']as&$visit)$visit['source_class']='other_or_unknown';unset($visit);
        $window=ModeledConversionAttributionWindow::assess($base,$activated,$now);
        if($window['visit_at']===null||$window['allowed']||$now-$window['visit_at']>ModeledConversionAttributionWindow::MAX_SECONDS)return $base;
        $sources=$this->visitReport($clientId,$activated,$now,false,true);
        if(($sources['status']??null)!=='ok')return $base;
        foreach($base['visits']as&$visit){
            $matches=array_values(array_filter($sources['visits'],static fn(array $source):bool=>$source['started_at']===$visit['started_at']));
            if(count($matches)===1&&$matches[0]['visits']===$visit['visits']&&$matches[0]['source_class']==='yandex_direct')$visit['source_class']='yandex_direct';
        }
        unset($visit);return $base;
    }
    private function visitReport(string $clientId,int $activated,int $now,bool $goal,bool $traffic=false):array
    {
        $fail=['status'=>'unavailable','visits'=>[]];
        if($this->counter!=='98615635'||!MaxTrackerLookupProtocol::id($clientId,40)||$activated<946684800||$activated>$now)return $fail;
        if($this->reportZone===null){
            $counterResponse=$this->request('GET','');
            $counter=$this->decode($counterResponse)['counter']??null;
            if((int)($counterResponse['error']??0)!==0||(int)($counterResponse['http']??0)!==200||!is_array($counter)
                ||(string)($counter['id']??'')!==$this->counter||!is_string($counter['time_zone_name']??null)
                ||!in_array($counter['time_zone_name'],DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true))return $fail;
            $this->reportZone=new DateTimeZone($counter['time_zone_name']);
        }
        $zone=$this->reportZone;
        $metrics=$goal?['ym:s:visits','ym:s:goal678187097reaches','ym:s:goal678187097convertedRUBRevenue']:['ym:s:visits'];
        $dimensions=['ym:s:clientID','ym:s:dateTime'];if($traffic)$dimensions=array_merge($dimensions,['ym:s:lastTrafficSource','ym:s:lastAdvEngine']);$filter="ym:s:clientID=='".$clientId."'".($goal?' AND ym:s:goal==678187097':'');
        $query=['ids'=>'98615635','metrics'=>implode(',',$metrics),'dimensions'=>implode(',',$dimensions),'filters'=>$filter,
            'date1'=>gmdate('Y-m-d',$activated-22*86400),'date2'=>'today','accuracy'=>'full','limit'=>101];
        $response=$this->request('GET','data?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),null,null,true);
        if((int)($response['error']??0)!==0||(int)($response['http']??0)!==200)return $fail;
        $d=$this->decode($response);$q=$d['query']??null;$rows=$d['data']??null;
        if(!is_array($q)||array_map('strval',$q['ids']??[])!==['98615635']||($q['metrics']??null)!==$metrics
            ||($q['dimensions']??null)!==$dimensions||($q['filters']??null)!==$filter
            ||($d['sampled']??null)!==false||!is_array($rows)||count($rows)>=101
            ||!is_numeric($d['total_rows']??null)||(int)$d['total_rows']!==count($rows))return $fail;
        $visits=[];
        foreach($rows as$row){
            $dims=$row['dimensions']??null;$m=$row['metrics']??null;
            if(!is_array($dims)||count($dims)!==($traffic?4:2)||!is_array($m)||count($m)!==count($metrics)
                ||(string)($dims[0]['name']??$dims[0]['id']??'')!==$clientId)return $fail;
            foreach($m as$v)if((!is_int($v)&&!is_float($v))||!is_finite((float)$v)||$v<0||$v>1000000000000)return $fail;
            if((float)$m[0]!==floor((float)$m[0])||($goal&&(float)$m[1]!==floor((float)$m[1])))return $fail;
            $date=(string)($dims[1]['name']??$dims[1]['id']??'');$at=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date,$zone);
            if(!$at||$at->format('Y-m-d H:i:s')!==$date||$at->getTimestamp()>$now)return $fail;
            if(($goal?$m[1]:$m[0])>0){
                $v=['started_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$at->getTimestamp()),'visits'=>(int)$m[0]];
                if($goal){$v['goal_reaches']=(int)$m[1];$v['revenue_rub']=round((float)$m[2],2);}
                if($traffic){
                    $trafficId=$dims[2]['id']??null;$engineId=$dims[3]['id']??null;
                    $v['source_class']=$trafficId==='ad'&&$engineId==='ya_direct'?'yandex_direct':'other_or_unknown';
                }
                $visits[]=$v;
            }
        }
        return ['status'=>'ok','visits'=>$visits];
    }

    private function selectedGoals(array $goals): array
    {
        $selected=[];
        foreach(self::SHARED_GOALS as $target=>$definition){
            $matches=array_values(array_filter($goals,static fn($goal)=>is_array($goal)&&(string)($goal['id']??'')===$definition['id']));
            if(count($matches)!==1)throw new RuntimeException('selected_goal_missing');
            $goal=$matches[0];$conditions=$goal['conditions']??null;
            if(($goal['type']??'')!=='action'||!is_string($goal['name']??null)||!is_array($conditions)||count($conditions)!==1
                ||!in_array($conditions[0]['type']??null,['contain','exact'],true)||($conditions[0]['url']??null)!==$target)throw new RuntimeException('selected_goal_conflict');
            foreach($goals as $other)if((string)($other['id']??'')!==$definition['id']){
                foreach((array)($other['conditions']??[]) as $condition)if(($condition['url']??null)===$target)throw new RuntimeException('ambiguous_shared_goal');
            }
            $selected[$target]=$goal;
        }
        return $selected;
    }

    private function readGoals(): array
    {
        $r=$this->request('GET','goals');$d=$this->decode($r);
        if((int)($r['error']??0)!==0||(int)($r['http']??0)!==200||!is_array($d['goals']??null))throw new RuntimeException('goals_read_failed');
        return $d['goals'];
    }

    private function exactGoals(array $goals,string $target): array
    {
        $matches=[];
        foreach($goals as $goal){
            if(!is_array($goal)||($goal['type']??'')!=='action'||!MaxTrackerLookupProtocol::id((string)($goal['id']??'')))continue;
            $conditions=$goal['conditions']??null;
            // One exact condition excludes additional matches in the same goal.
            if(is_array($conditions)&&count($conditions)===1&&($conditions[0]['type']??'')==='exact'&&($conditions[0]['url']??'')===$target)$matches[]=$goal;
        }
        return $matches;
    }

    private function goalSemantics(array $goal): string
    {
        $normalize=static function($value)use(&$normalize){if(is_array($value)){if(!array_is_list($value))ksort($value);foreach($value as &$v)$v=$normalize($v);unset($v);}return $value;};
        return json_encode($normalize(array_intersect_key($goal,array_fill_keys(['id','name','type','conditions','default_price','is_favorite','status','flag','goal_source','steps'],true))),JSON_THROW_ON_ERROR);
    }

    private function decode(array $response): array
    {
        $data=json_decode((string)($response['body']??''),true,32,JSON_BIGINT_AS_STRING);return is_array($data)?$data:[];
    }

    private function request(string $method,string $path,?string $csv=null,?array $json=null,bool $statistics=false): array
    {
        if($method==='GET'&&$this->readDeadline!==null&&microtime(true)>=$this->readDeadline)throw new RuntimeException('verification_deadline');
        $url='https://api-metrika.yandex.net/'.($statistics?'stat/v1/':'management/v1/counter/'.$this->counter.($path===''?'':'/')).$path;
        if($this->transport)return ($this->transport)($method,$url,$json!==null?json_encode($json,JSON_THROW_ON_ERROR):$csv);
        $temp=null;$ch=null;
        try{
            $body='';$tooLarge=false;$maxResponseBytes=$statistics?65536:1048576;$ch=curl_init($url);if($ch===false)throw new RuntimeException('metrika_transport_failed');
            $options=[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT=>$statistics?2:5,CURLOPT_TIMEOUT=>$statistics?8:30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_HTTPHEADER=>['Authorization: OAuth '.$this->token,'Accept: application/json'],
                CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$body,&$tooLarge,$maxResponseBytes):int{
                    if(strlen($body)+strlen($chunk)>$maxResponseBytes){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);
                }];
            if($method==='GET'&&$this->readDeadline!==null){
                $remaining=(int)floor(($this->readDeadline-microtime(true))*1000);
                if($remaining<1)throw new RuntimeException('verification_deadline');
                $options[CURLOPT_CONNECTTIMEOUT_MS]=min(2000,$remaining);$options[CURLOPT_TIMEOUT_MS]=min(8000,$remaining);
            }
            if(in_array($method,['POST','PUT'],true)&&$json!==null){
                $options[CURLOPT_CUSTOMREQUEST]=$method;$options[CURLOPT_POSTFIELDS]=json_encode($json,JSON_THROW_ON_ERROR);
                $options[CURLOPT_HTTPHEADER][]='Content-Type: application/json';
            }elseif($method==='POST'){
                $temp=tempnam(MaxTrackerLookupConfig::csvDirectory(),'conversion-');if($temp===false)throw new RuntimeException('private_csv_unavailable');
                chmod($temp,0600);if(file_put_contents($temp,$csv)!==strlen((string)$csv))throw new RuntimeException('private_csv_unavailable');
                $options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=['file'=>new CURLFile($temp,'text/csv','offline_conversions.csv')];
            }
            curl_setopt_array($ch,$options);$ok=curl_exec($ch);
            return ['http'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'error'=>$tooLarge?23:curl_errno($ch),'body'=>$body];
        }finally{if($ch!==null&&$ch!==false)curl_close($ch);if(is_string($temp)&&is_file($temp))unlink($temp);}
    }
}

