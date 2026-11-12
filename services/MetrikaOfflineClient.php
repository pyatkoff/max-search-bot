<?php
declare(strict_types=1);
require_once __DIR__.'/MaxTrackerLookupProtocol.php';
require_once __DIR__.'/ModeledConversionAttributionWindow.php';
require_once __DIR__.'/MaxTrackerLookupConfig.php';
require_once __DIR__.'/MaxChannelConversionOutbox.php';
require_once __DIR__.'/ModeledConversionKnownYclidRelation.php';

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
    private ?array $lastPollEvidence=null;
    private ?array $projectPolicy=null;
    public function __construct(string $counter,string $token,?callable $transport=null,?float $readDeadline=null,?array $projectPolicy=null)
    {
        if(!MaxTrackerLookupProtocol::id($counter)||$token==='')throw new RuntimeException('metrika_config_missing');
        $this->counter=$counter;$this->token=$token;$this->transport=$transport;$this->readDeadline=$readDeadline;
        if($projectPolicy!==null){
            if(($projectPolicy['counter_id']??null)!==$counter||$counter==='98615635'||($projectPolicy['project_key']??'anytour')==='anytour'
                ||!preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D',$projectPolicy['project_key'])||!is_array($projectPolicy['goals']??null)||!$projectPolicy['goals'])throw new InvalidArgumentException('invalid_project_conversion_policy');
            foreach($projectPolicy['goals']as$event=>$target)if(!in_array($event,['customer_activity','manager_request','customer_reply','working','offer_sent','booking','won','predictive_value'],true)
                ||!is_string($target)||!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$target))throw new InvalidArgumentException('invalid_project_conversion_policy');
            if(count(array_unique($projectPolicy['goals']))!==count($projectPolicy['goals']))throw new InvalidArgumentException('invalid_project_conversion_policy');
            $this->projectPolicy=$projectPolicy;
        }
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
        if($this->projectPolicy!==null)$csv=$this->projectCsv($event,$now);
        else{
            if(!isset(self::SHARED_GOALS[$event['target']??''])||($event['transport_target']??null)!==self::SHARED_GOALS[$event['target']]['target'])throw new InvalidArgumentException('retired_conversion_target');
            $csv=self::csv($event,$now);
        }
        $result=$this->request('POST','offline_conversions/upload?type=BASIC&comment='.rawurlencode(self::comment($event)),$csv);
        $error=(int)($result['error']??0);$http=(int)($result['http']??0);
        if($error!==0)return ['outcome'=>in_array($error,[5,6,7],true)?'retry':'unknown','reason'=>in_array($error,[5,6,7],true)?'transport_not_sent':'upload_outcome_unknown'];
        if(in_array($http,[420,429],true))return ['outcome'=>'retry','reason'=>'rate_limited'];
        if($http>=400&&$http<500)return ['outcome'=>'rejected','reason'=>'api_rejected'];
        $d=$this->decode($result);$u=$d['uploading']??null;
        if($http>=200&&$http<300&&$this->validReceipt($u)
            &&(!array_key_exists('comment',$u)||$u['comment']===self::comment($event)))return ['outcome'=>'accepted','upload_id'=>(string)$u['id'],'status'=>(string)($u['status']??'UPLOADED')];
        return ['outcome'=>'unknown','reason'=>'upload_outcome_unknown'];
    }

    private function projectCsv(array $event,int $now): string
    {
        $stage=str_starts_with($event['target']??'','funnel_')?substr($event['target'],7):'';
        if(($this->projectPolicy['goal_match'][$stage]??'exact')!=='exact')throw new InvalidArgumentException('unsupported_offline_goal_condition');
        if(($event['project_key']??null)!==$this->projectPolicy['project_key']||!isset($this->projectPolicy['goals'][$stage])
            ||($event['transport_target']??null)!==$this->projectPolicy['goals'][$stage]
            ||!MaxTrackerLookupProtocol::id($event['client_id']??null,40)||!is_numeric($event['event_unix']??null)
            ||(int)$event['event_unix']>$now||(int)$event['event_unix']<$now-21*86400)throw new InvalidArgumentException('invalid_project_conversion_event');
        $f=fopen('php://temp','w+');$header=['ClientId','Target','DateTime'];$row=[$event['client_id'],$event['transport_target'],(string)$event['event_unix']];
        if($stage==='predictive_value'){
            if(!is_string($event['value_rub']??null)||!preg_match('/\A(?:0|[1-9][0-9]{0,8})\.[0-9]{2}\z/D',$event['value_rub']))throw new InvalidArgumentException('invalid_project_prediction');
            array_push($header,'Price','Currency');array_push($row,$event['value_rub'],'RUB');
        }
        fputcsv($f,$header);fputcsv($f,$row);rewind($f);$csv=stream_get_contents($f);fclose($f);return $csv;
    }

    /** Existing owner-selected goals only; no goal creation or condition changes. */
    public function projectGoalsReady(): bool
    {
        return $this->projectGoalAdmission()==='ready';
    }

    /** Fixed public-safe result; offline uploads require an exact action condition. */
    public function projectGoalAdmission(): string
    {
        if($this->projectPolicy===null)return 'goal_access_unavailable';
        try{
            $access=$this->request('GET','');$counter=$this->decode($access)['counter']??null;
            if((int)($access['error']??0)!==0||(int)($access['http']??0)!==200||!is_array($counter)
                ||(string)($counter['id']??'')!==$this->counter||!in_array($counter['permission']??null,['own','edit'],true))return 'goal_access_unavailable';
            $goals=$this->readGoals();
            foreach($this->projectPolicy['goals']as$event=>$target){
                $id=$this->projectPolicy['goal_ids'][$event]??null;$match=$this->projectPolicy['goal_match'][$event]??'exact';
                if($match!=='exact')return 'goal_condition_unsupported';
                if($id===null){if(count($this->exactGoals($goals,$target))!==1)return 'goal_access_unavailable';continue;}
                $selected=array_values(array_filter($goals,static fn($g)=>is_array($g)&&(string)($g['id']??'')===$id));
                if(count($selected)!==1||($selected[0]['type']??'')!=='action'||count($selected[0]['conditions']??[])!==1
                    ||($selected[0]['conditions'][0]['url']??'')!==$target)return 'goal_access_unavailable';
                if(($selected[0]['conditions'][0]['type']??'')!=='exact')return 'goal_condition_unsupported';
                foreach($goals as$other)if(($other['type']??'')==='action'&&(string)($other['id']??'')!==$id)foreach(($other['conditions']??[])as$condition){
                    $value=$condition['url']??null;$type=$condition['type']??'';
                    if(!is_string($value)||$value==='')return 'goal_access_unavailable';
                    if(($type==='exact'||$type==='action')&&$value===$target)return 'goal_access_unavailable';
                    if($type==='contain'&&str_contains($target,$value))return 'goal_access_unavailable';
                    if($type==='start'&&str_starts_with($target,$value))return 'goal_access_unavailable';
                    if($type==='regexp')return 'goal_access_unavailable'; // No guessed regexp semantics or goal mutation.
                }
            }
            return 'ready';
        }catch(Throwable $e){return 'goal_access_unavailable';}
    }

    public function poll(string $uploadId): ?array
    {
        $this->lastPollEvidence=null;
        if(!MaxTrackerLookupProtocol::id($uploadId))return null;
        try{$r=$this->request('GET','offline_conversions/uploading/'.$uploadId);}
        catch(Throwable $error){$this->recordPollEvidence($uploadId,1,null,null);throw $error;}
        $http=is_int($r['http']??null)&&$r['http']>=100&&$r['http']<=599?$r['http']:null;
        $curl=is_int($r['error']??null)&&$r['error']>=0&&$r['error']<=100?$r['error']:null;
        if((int)($r['error']??0)!==0){$this->recordPollEvidence($uploadId,1,$http,$curl);return null;}
        if((int)($r['http']??0)!==200){$this->recordPollEvidence($uploadId,2,$http,$curl);return null;}
        $d=$this->decode($r);$jsonError=json_last_error();$u=$d['uploading']??null;
        if($jsonError!==JSON_ERROR_NONE){$this->recordPollEvidence($uploadId,3,$http,$curl);return null;}
        if(!is_array($u)){$this->recordPollEvidence($uploadId,4,$http,$curl);return null;}
        if(!$this->validReceipt($u)){$this->recordPollEvidence($uploadId,5,$http,$curl);return null;}
        if((string)$u['id']!==$uploadId){$this->recordPollEvidence($uploadId,6,$http,$curl);return null;}
        $this->recordPollEvidence($uploadId,7,$http,$curl,$u['status']);return $u;
    }

    /** Closed evidence from the most recent poll only, bound to the requested upload without exposing its ID. */
    public function pollEvidence(string $uploadId):?array
    {
        if(!MaxTrackerLookupProtocol::id($uploadId)||$this->lastPollEvidence===null
            ||!hash_equals($this->lastPollEvidence['receipt_ref'],hash('sha256','modeled_value_upload_receipt_v1|'.$uploadId)))return null;
        return $this->lastPollEvidence;
    }
    private function recordPollEvidence(string $uploadId,int $stage,?int $http,?int $curl,?string $status=null):void
    {
        $this->lastPollEvidence=['v'=>1,'counter_id'=>$this->counter,'receipt_ref'=>hash('sha256','modeled_value_upload_receipt_v1|'.$uploadId),
            'checked_at_unix'=>time(),'stage'=>$stage,'http_status'=>$http,'transport_error_code'=>$curl,'provider_status'=>$status];
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
            'goal_reaches'=>null,'goal_visits'=>null,'revenue_rub'=>null,'sampled'=>null,
            'http_status'=>null,'transport_error_code'=>null,'failure_category'=>null];
        if($this->counter!=='98615635'||$activated<946684800||$activated>$now){$base['failure_category']='invalid_request';return $base+['reason'=>'invalid_scope'];}
        $metrics=['ym:s:goal678187097reaches','ym:s:goal678187097visits','ym:s:goal678187097convertedRUBRevenue'];
        $query=['ids'=>'98615635','metrics'=>implode(',',$metrics),'date1'=>$base['date1'],'date2'=>'today','accuracy'=>'full','limit'=>1];
        $r=$this->request('GET','data?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),null,null,true,true);
        // Observe only this response. No raw body, message, URL or credential is retained.
        $httpScalar=$r['http']??null;$transportScalar=$r['error']??null;
        $base['http_status']=is_int($httpScalar)&&$httpScalar>=100&&$httpScalar<=599?$httpScalar:null;
        $base['transport_error_code']=is_int($transportScalar)&&$transportScalar>=0&&$transportScalar<=100?$transportScalar:null;
        if((int)($r['error']??0)!==0){
            $base['failure_category']=$base['transport_error_code']!==null?'transport_error':'unknown';
            return $base+['reason'=>'transport_unavailable'];
        }
        $http=(int)($r['http']??0);
        $base['failure_category']=$base['http_status']===null?'unknown':
            ($http===429?'rate_limited':($http>=500?'provider_unavailable':
            (in_array($http,[401,403],true)?'access_denied':($http>=400?'invalid_request':'unexpected_http_status'))));
        if($http===403||$http===401){$base['status']='forbidden';return $base+['reason'=>'report_scope_or_counter_access'];}
        if($http!==200)return $base+['reason'=>'report_api_unavailable'];
        $base['failure_category']='invalid_response';
        $d=$this->decode($r);$q=$d['query']??[];$totals=$d['totals']??null;
        $ids=is_array($q['ids']??null)?array_map('strval',$q['ids']):[];
        if($ids!==['98615635']||($q['metrics']??null)!==$metrics||!is_array($totals)||count($totals)!==3
            ||!is_bool($d['sampled']??null))return $base+['reason'=>'invalid_report'];
        foreach($totals as$v)if((!is_int($v)&&!is_float($v))||!is_finite((float)$v)||$v<0||$v>1000000000000)return $base+['reason'=>'invalid_totals'];
        if(((float)$totals[0])!==floor((float)$totals[0]))return $base+['reason'=>'invalid_counts'];
        if(((float)$totals[1])!==floor((float)$totals[1])||$totals[1]>$totals[0])return $base+['reason'=>'invalid_counts'];
        $base['status']=$d['sampled']?'sampled':'ok';$base['sampled']=$d['sampled'];$base['failure_category']=null;
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
    /** R1: one GET links only the recorded click UTC day to one visible CID group. */
    public function retainedYclidClickDayClients(array $plan,string $rawYclid,int $intentAt,callable $beforeRequest,?callable $observationClock=null):?array
    {
        $clock=$observationClock??static fn():int=>time();try{$requested=$clock();}catch(Throwable $ignored){return null;}
        if(!is_int($requested))return null;$query=ModeledConversionKnownYclidRelation::r1Query($plan,$rawYclid,$intentAt);
        return $query===null?null:$this->retainedRelationRequest('click_day_cid',$query,$intentAt,$requested,$beforeRequest,$clock);
    }
    /** R2: its CID must come from the full positive R1 proof and differ from the already checked held CID. */
    public function retainedYclidPriorVisits(array $plan,string $rawYclid,array $r1,int $intentAt,callable $beforeRequest,?callable $observationClock=null):?array
    {
        $clock=$observationClock??static fn():int=>time();try{$requested=$clock();}catch(Throwable $ignored){return null;}
        if(!is_int($requested))return null;$query=ModeledConversionKnownYclidRelation::r2Query($plan,$rawYclid,$intentAt,$r1,$requested);
        return $query===null?null:$this->retainedRelationRequest('derived_cid_visits',$query,$intentAt,$requested,$beforeRequest,$clock);
    }
    private function retainedRelationRequest(string $stage,array $query,int $intent,int $requested,callable $beforeRequest,callable $clock):?array
    {
        if($this->counter!=='98615635'||$this->projectPolicy!==null||$requested<$intent||$requested>$intent+ModeledConversionKnownYclidRelation::TTL)return null;
        $hash=ModeledConversionKnownYclidRelation::queryHash($stage,$query);
        try{if($beforeRequest($hash,$requested)!==true)return null;}catch(Throwable $ignored){return null;}
        $out=['v'=>2,'stage'=>$stage,'query_sha256'=>$hash,'request_query'=>$query,'requested_at_unix'=>$requested,
            'checked_at_unix'=>$requested,'http'=>null,'curl'=>null,'response'=>null,'failure'=>'transport'];
        try{
            $r=$this->request('GET','data?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),null,null,true);
            $checked=$clock();if(!is_int($checked)||$checked<$requested)return null;$out['checked_at_unix']=$checked;
            $out['http']=is_int($r['http']??null)?$r['http']:null;$out['curl']=is_int($r['error']??null)?$r['error']:null;
            if($out['http']!==200||$out['curl']!==0){$out['failure']='http';return $out;}
            $cap=$stage==='click_day_cid'?16384:65536;$bodyCap=$stage==='click_day_cid'?12288:57344;
            if(!is_string($r['body']??null)||strlen($r['body'])>$bodyCap){$out['failure']='response_budget';return $out;}
            $out['failure']='json';$body=json_decode($r['body'],true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
            if(!is_array($body))return $out;$out['response']=$body;$out['failure']=null;
            if(strlen(json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>$cap){$out['response']=null;$out['failure']='response_budget';}
            return $out;
        }catch(Throwable $ignored){return $out;}
    }

    /** Exact last-transition source, unaffected by historical attribution models. GET only. */
    public function modeledAttributionVisits(string $clientId,int $activated,int $now,?callable $admitExtraRead=null):array
    {
        // Optional advertising dimensions can omit visits with undefined source labels.
        // Keep the complete unfiltered visit list; source evidence may only enrich it.
        $base=$this->visitReport($clientId,$activated,$now,false,false,true);
        if(($base['status']??null)!=='ok'||($base['visits']??[])===[])return $base;
        foreach($base['visits']as&$visit)$visit['source_class']='other_or_unknown';unset($visit);
        $window=ModeledConversionAttributionWindow::assess($base,$activated,$now);
        if($window['visit_at']===null||$window['allowed']||$now-$window['visit_at']>ModeledConversionAttributionWindow::MAX_SECONDS)return $base;
        if($admitExtraRead!==null)$admitExtraRead();
        $sources=$this->visitReport($clientId,$activated,$now,false,true,true);
        if(($sources['status']??null)!=='ok')return $base;
        $baseTimes=array_fill_keys(array_column($base['visits'],'started_at'),true);
        foreach($sources['visits']as$source)if(!isset($baseTimes[$source['started_at']]))return $base;
        foreach($base['visits']as&$visit){
            $matches=array_values(array_filter($sources['visits'],static fn(array $source):bool=>$source['started_at']===$visit['started_at']));
            if(count($matches)===1&&$matches[0]['visits']===$visit['visits']&&$matches[0]['source_class']==='yandex_direct')$visit['source_class']='yandex_direct';
        }
        unset($visit);return $base;
    }
    private function visitReport(string $clientId,int $activated,int $now,bool $goal,bool $traffic=false,bool $currentWindow=false):array
    {
        $fail=['status'=>'unavailable','visits'=>[]];
        $failed=static function(int $stage,?array $response=null,int $mask=0)use($fail,$currentWindow):array{
            if(!$currentWindow)return $fail;
            $http=$response['http']??null;$curl=$response['error']??null;
            return $fail+['attribution_failure'=>[$stage,
                is_int($http)&&$http>=100&&$http<=599?$http:null,
                is_int($curl)&&$curl>=0&&$curl<=100?$curl:null,$mask]];
        };
        if($this->counter!=='98615635'||!MaxTrackerLookupProtocol::id($clientId,40)||$activated<946684800||$activated>$now)return $failed(9);
        if($this->reportZone===null){
            $counterResponse=$this->request('GET','');
            $counter=$this->decode($counterResponse)['counter']??null;
            if((int)($counterResponse['error']??0)!==0)return $failed(1,$counterResponse);
            if((int)($counterResponse['http']??0)!==200)return $failed(2,$counterResponse);
            $mask=!is_array($counter)?1:0;
            if(is_array($counter)){
                if((string)($counter['id']??'')!==$this->counter)$mask|=2;
                if(!is_string($counter['time_zone_name']??null))$mask|=4;
                elseif(!in_array($counter['time_zone_name'],DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true))$mask|=8;
            }
            if($mask)return $failed(3,$counterResponse,$mask);
            $this->reportZone=new DateTimeZone($counter['time_zone_name']);
        }
        $zone=$this->reportZone;
        // Any currently allowed visit is at most MAX_SECONDS old at dispatch.
        // Counter-local calendar flooring includes the exact boundary without scanning obsolete weeks.
        $date1=$currentWindow?(new DateTimeImmutable('@'.($now-ModeledConversionAttributionWindow::MAX_SECONDS)))
            ->setTimezone($zone)->format('Y-m-d'):gmdate('Y-m-d',$activated-22*86400);
        $metrics=$goal?['ym:s:visits','ym:s:goal678187097reaches','ym:s:goal678187097convertedRUBRevenue']:['ym:s:visits'];
        $dimensions=['ym:s:clientID','ym:s:dateTime'];if($traffic)$dimensions=array_merge($dimensions,['ym:s:lastTrafficSource','ym:s:lastAdvEngine']);$filter="ym:s:clientID=='".$clientId."'".($goal?' AND ym:s:goal==678187097':'');
        $query=['ids'=>'98615635','metrics'=>implode(',',$metrics),'dimensions'=>implode(',',$dimensions),'filters'=>$filter,
            'date1'=>$date1,'date2'=>'today','accuracy'=>'full','limit'=>101];
        $response=$this->request('GET','data?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),null,null,true);
        if((int)($response['error']??0)!==0)return $failed(4,$response);
        if((int)($response['http']??0)!==200)return $failed(5,$response);
        $d=$this->decode($response);$q=$d['query']??null;$rows=$d['data']??null;
        if($currentWindow){
            $mask=!is_array($q)?1:0;
            if(is_array($q)){
                if(!is_array($q['ids']??null)||array_map('strval',$q['ids'])!==['98615635'])$mask|=2;
                if(($q['metrics']??null)!==$metrics)$mask|=4;
                if(($q['dimensions']??null)!==$dimensions)$mask|=8;
                if(($q['filters']??null)!==$filter)$mask|=16;
            }
            if($mask)return $failed(6,$response,$mask);
            $mask=($d['sampled']??null)!==false?1:0;
            if(!is_array($rows))$mask|=2;
            elseif(count($rows)>=101)$mask|=4;
            if(!is_numeric($d['total_rows']??null))$mask|=8;
            elseif(is_array($rows)&&(int)$d['total_rows']!==count($rows))$mask|=16;
            if($mask)return $failed(7,$response,$mask);
        }elseif(!is_array($q)||array_map('strval',$q['ids']??[])!==['98615635']||($q['metrics']??null)!==$metrics
            ||($q['dimensions']??null)!==$dimensions||($q['filters']??null)!==$filter
            ||($d['sampled']??null)!==false||!is_array($rows)||count($rows)>=101
            ||!is_numeric($d['total_rows']??null)||(int)$d['total_rows']!==count($rows))return $fail;
        $visits=[];
        foreach($rows as$row){
            $dims=$row['dimensions']??null;$m=$row['metrics']??null;
            if(!is_array($dims)||count($dims)!==($traffic?4:2)||!is_array($m)||count($m)!==count($metrics)
                ||(string)($dims[0]['name']??$dims[0]['id']??'')!==$clientId){
                $mask=(!is_array($dims)||count($dims)!==($traffic?4:2)?1:0)|(!is_array($m)||count($m)!==count($metrics)?2:0);
                if($mask===0){
                    $mask=4;$first=is_array($dims[0]??null)?$dims[0]:[];
                    foreach(['name'=>128,'id'=>256]as$key=>$bit){
                        $value=$first[$key]??null;
                        if((is_string($value)||is_int($value))&&MaxTrackerLookupProtocol::id((string)$value,40)&&(string)$value===$clientId)$mask|=$bit;
                    }
                }
                return $failed(8,$response,$mask);
            }
            foreach($m as$v)if((!is_int($v)&&!is_float($v))||!is_finite((float)$v)||$v<0||$v>1000000000000)return $failed(8,$response,8);
            if((float)$m[0]!==floor((float)$m[0])||($goal&&(float)$m[1]!==floor((float)$m[1])))return $failed(8,$response,16);
            $date=(string)($dims[1]['name']??$dims[1]['id']??'');
            try{$at=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date,$zone);}
            catch(Throwable $error){if(!$currentWindow)throw $error;return $failed(8,$response,32|self::visitClockFormatMask($date,$dims[1]??null));}
            if(!$at||$at->format('Y-m-d H:i:s')!==$date)return $failed(8,$response,32|self::visitClockFormatMask($date,$dims[1]??null));
            if($at->getTimestamp()>$now)return $failed(8,$response,64|self::visitClockFormatMask($date,$dims[1]??null));
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

    /** Format classes only; no timestamp or identifier bytes leave this diagnostic. */
    private static function visitClockFormatMask(string $chosen,mixed $dimension):int
    {
        $dimension=is_array($dimension)?$dimension:[];
        $class=static function(mixed $value):int{
            if(!is_string($value))return 3;
            if(preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D',$value)===1)return 0;
            if(preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})?\z/D',$value)===1)return 1;
            if(preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$value)===1)return 2;
            return 3;
        };
        return (1<<(9+$class($chosen)))|(1<<(13+$class($dimension['name']??null)))|(1<<(17+$class($dimension['id']??null)));
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

    /** Fixed aggregate GET has a larger per-request ceiling; existing caller deadlines remain authoritative. */
    private function requestTimeouts(string $method,bool $statistics,bool $ordinaryValueReport,float $at):array
    {
        $seconds=$statistics?($method==='GET'&&$ordinaryValueReport?20:8):30;
        $out=['connect_seconds'=>$statistics?2:5,'timeout_seconds'=>$seconds];
        if($method==='GET'&&$this->readDeadline!==null){
            $remaining=(int)floor(($this->readDeadline-$at)*1000);
            if($remaining<1)throw new RuntimeException('verification_deadline');
            $out['connect_ms']=min(2000,$remaining);
            $out['timeout_ms']=min(($statistics?$seconds:8)*1000,$remaining);
        }
        return $out;
    }

    private function request(string $method,string $path,?string $csv=null,?array $json=null,bool $statistics=false,bool $ordinaryValueReport=false): array
    {
        if($method==='GET'&&$this->readDeadline!==null&&microtime(true)>=$this->readDeadline)throw new RuntimeException('verification_deadline');
        $url='https://api-metrika.yandex.net/'.($statistics?'stat/v1/':'management/v1/counter/'.$this->counter.($path===''?'':'/')).$path;
        if($this->transport)return ($this->transport)($method,$url,$json!==null?json_encode($json,JSON_THROW_ON_ERROR):$csv);
        $temp=null;$ch=null;
        try{
            $body='';$tooLarge=false;$maxResponseBytes=$statistics?65536:1048576;$ch=curl_init($url);if($ch===false)throw new RuntimeException('metrika_transport_failed');
            $timeouts=$this->requestTimeouts($method,$statistics,$ordinaryValueReport,microtime(true));
            $options=[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT=>$timeouts['connect_seconds'],CURLOPT_TIMEOUT=>$timeouts['timeout_seconds'],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_HTTPHEADER=>['Authorization: OAuth '.$this->token,'Accept: application/json'],
                CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$body,&$tooLarge,$maxResponseBytes):int{
                    if(strlen($body)+strlen($chunk)>$maxResponseBytes){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);
                }];
            if(isset($timeouts['connect_ms'])){
                $options[CURLOPT_CONNECTTIMEOUT_MS]=$timeouts['connect_ms'];$options[CURLOPT_TIMEOUT_MS]=$timeouts['timeout_ms'];
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

