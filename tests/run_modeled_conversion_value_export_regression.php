<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ModeledConversionValueExport.php';

function modeledValueSchema(PDO $p):void
{
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql&&$p->query('SELECT DATABASE()')->fetchColumn()!=='maxbot_modeled_stream_test')throw new RuntimeException('unsafe_fixture_database');
    foreach(['modeled_conversion_price_audit','modeled_conversion_value_export_settings','max_channel_conversion_outbox','max_channel_attribution_settings','modeled_conversion_profile_versions','advertising_visit_observations','conversations','customer_channels']as$t)$p->exec('DROP TABLE IF EXISTS '.$t);
    $auto=$mysql?'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    if($mysql){
        $p->exec(file_get_contents(dirname(__DIR__).'/migrations/037_max_channel_conversion_outbox.sql'));
        $p->exec("ALTER TABLE max_channel_conversion_outbox ADD transport_target VARCHAR(64),ADD platform VARCHAR(16) NOT NULL DEFAULT 'max',ADD identity_source_key VARCHAR(80) NOT NULL DEFAULT '',ADD identity_observation_id BIGINT NULL");
        $p->exec(file_get_contents(dirname(__DIR__).'/migrations/086_modeled_conversion_value_export.sql'));
        $p->exec(file_get_contents(dirname(__DIR__).'/migrations/087_modeled_conversion_value_history.sql'));
        $p->exec(file_get_contents(dirname(__DIR__).'/migrations/088_modeled_conversion_value_fortnight.sql'));
        $p->exec(file_get_contents(dirname(__DIR__).'/migrations/090_modeled_conversion_delivery_observability.sql'));
        $p->exec(file_get_contents(dirname(__DIR__).'/migrations/093_modeled_value_price_continuity.sql'));
    }else{
        $p->exec("CREATE TABLE max_channel_conversion_outbox(id $auto,project_key TEXT,conversation_id INTEGER,external_user_id TEXT,counter_id TEXT,target TEXT,transport_target TEXT,event_unix INTEGER,client_id TEXT,tracker_record_id TEXT,tracker_observed_unix INTEGER,state TEXT DEFAULT 'pending',next_attempt_unix INTEGER DEFAULT 0,claim_token TEXT,claimed_at_unix INTEGER,attempts INTEGER DEFAULT 0,upload_id TEXT,upload_status TEXT,last_error TEXT DEFAULT '',platform TEXT DEFAULT 'max',identity_source_key TEXT DEFAULT '',identity_observation_id INTEGER,value_rub NUMERIC,value_profile_id INTEGER,value_stage TEXT,value_contract TEXT,value_origin TEXT,value_reviewed_at_unix INTEGER,value_ready_at_unix INTEGER,value_api_accepted_at_unix INTEGER,value_processed_at_unix INTEGER,value_receipt_basis TEXT,UNIQUE(project_key,conversation_id,target))");
        $p->exec("CREATE TABLE modeled_conversion_value_export_settings(project_key TEXT PRIMARY KEY,counter_id TEXT,mode TEXT DEFAULT 'off',goal_id TEXT,goal_intent TEXT DEFAULT 'none',activated_at_unix INTEGER,profile_cursor INTEGER DEFAULT 0,base_value_rub INTEGER DEFAULT 25000,contract_version TEXT DEFAULT 'semantic_stage_value_25000_v1',last_tick_unix INTEGER,last_receipt_json TEXT,history_from_unix INTEGER,history_until_unix INTEGER,history_cursor INTEGER DEFAULT 0,history_ceiling INTEGER,history_completed_at_unix INTEGER,history_scanned INTEGER DEFAULT 0,history_collected INTEGER DEFAULT 0,history_stats_json TEXT,history_fortnight_requested_at_unix INTEGER,history_day_receipt_json TEXT,last_report_success_unix INTEGER,last_report_success_json TEXT,report_failure_since_unix INTEGER)");
        $p->exec("INSERT INTO modeled_conversion_value_export_settings(project_key,counter_id) VALUES('anytour','98615635')");
        $p->exec("CREATE TABLE modeled_conversion_price_audit(outbox_id BIGINT PRIMARY KEY,action VARCHAR(24),original_value_rub INTEGER,applied_value_rub INTEGER,prior_reserved_rub INTEGER,assessed_at_unix BIGINT,basis_json TEXT,basis_sha256 VARCHAR(64))");
    }
    // Match migration066: the native profile project is ASCII, while outbox037 is utf8mb4_unicode_ci.
    $profileProject=$mysql?'project_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin':'project_key VARCHAR(64)';
    $p->exec("CREATE TABLE modeled_conversion_profile_versions(id $auto,$profileProject,platform VARCHAR(16),customer_key VARCHAR(20),conversation_id BIGINT,observed_at_unix BIGINT,profile_json ".($mysql?'JSON':'TEXT').")");
    $p->exec("CREATE TABLE customer_channels(id BIGINT PRIMARY KEY,customer_id BIGINT,project_key VARCHAR(64),channel VARCHAR(16),external_user_id VARCHAR(20),external_chat_id VARCHAR(32))");
    $p->exec("CREATE TABLE conversations(id BIGINT PRIMARY KEY,customer_id BIGINT,customer_channel_id BIGINT,project_key VARCHAR(64),channel VARCHAR(16),is_test INT,external_chat_id VARCHAR(32))");
    $p->exec("CREATE TABLE advertising_visit_observations(id $auto,project_key VARCHAR(64),source_key VARCHAR(80),platform VARCHAR(16),counter_id VARCHAR(20),external_user_id VARCHAR(20),identity_status VARCHAR(20),client_id VARCHAR(40),visit_at_unix BIGINT,observed_at_unix BIGINT,received_at_unix BIGINT)");
}
function modeledValueBinding(PDO $p,int $id,string $platform='max',int $test=0):array
{
    $user=(string)(70000+$id);$chat=$platform==='max'?'-'.$user:$user;
    $p->prepare("INSERT INTO customer_channels VALUES(?,?,'anytour',?,?,?)")->execute([$id,$id,$platform,$user,$chat]);
    $p->prepare("INSERT INTO conversations VALUES(?,?,?,'anytour',?,?,?)")->execute([$id,$id,$id,$platform,$test,$chat]);
    return ['project_key'=>'anytour','platform'=>$platform,'customer_key'=>(string)$id,'conversation_id'=>(string)$id];
}
function modeledValueWrapper(array $subject,int $now,string $goal='substantive_interest'):array
{
    $at=gmdate('Y-m-d\TH:i:s\Z',$now-2);$available=gmdate('Y-m-d\TH:i:s\Z',$now-1);$freeze=gmdate('Y-m-d\TH:i:s\Z',$now);
    $role=$goal==='manager_payment_ack'?'manager':'customer';$ref='journal:101';
    $e=['id'=>'message:'.$ref,'kind'=>$role.'_message','subject'=>$subject,'at'=>$at,'available_at'=>$available,
        'references'=>[['source'=>'message','id'=>$ref,'at'=>$at,'available_at'=>$available]]];
    $w=['subject'=>$subject,'access_status'=>'allowed','profile'=>['prediction_at'=>$freeze,'observation_input'=>['subject'=>$subject,'evidence'=>[$e]]],
        'processing'=>['source_sha256'=>hash_file('sha256',dirname(__DIR__).'/services/ModeledConversionProfiles.php')],
        'contextual_signals'=>['version'=>ModeledConversionEarlyForecast::VERSION,
            'extractor_sha256'=>hash_file('sha256',dirname(__DIR__).'/services/ArchiveResearchSemanticExtractor.php'),
            'adapter_sha256'=>hash_file('sha256',dirname(__DIR__).'/services/ModeledConversionEarlyForecast.php'),
            'frozen_at'=>$freeze,'input_cutoff_at'=>$freeze,'events'=>[['goal'=>$goal,'product'=>'tour','source_ref'=>$ref]]]];
    if($goal==='concrete_request'){
        $w['contextual_signals']['events']=[];
        $model=new ModeledConversionSemanticPrediction($subject,$freeze,$freeze,$freeze,'10000','10000');
        $model->observeMessage('customer','Египет в ноябре',$ref,$now-2,$now-1,1,
            ['version'=>ArchiveResearchSemanticExtractor::VERSION,'status'=>'scanned','flags'=>[],'events'=>[]]);
        $w['semantic_prediction']=$model->finish(['early_forecast'=>['status'=>'early_assessment']]);
    }
    return $w;
}
function modeledValueSave(PDO $p,array $w):void
{
    $s=$w['subject'];
    $p->prepare("INSERT INTO modeled_conversion_profile_versions(project_key,platform,customer_key,conversation_id,profile_json) VALUES(?,?,?,?,?)")
        ->execute([$s['project_key'],$s['platform'],$s['customer_key'],$s['conversation_id'],json_encode($w,JSON_THROW_ON_ERROR)]);
}
final class ModeledValueTestWire
{
    public array $goals=[['id'=>'9','type'=>'action','name'=>'unchanged','conditions'=>[['type'=>'exact','url'=>'existing_goal']]]];
    public array $csv=[];public array $receipts=[];public int $creates=0;public int $posts=0;public int $gets=0;public int $visitReads=0;
    public array $reportTotals=[2,1,2083];public bool $reportSampled=false;public int $reportHttp=200;
    public bool $reportWrongScope=false;public array $lastReportQuery=[];
    public ?int $visitAt=null;public string $visitSource='other_or_unknown';public bool $visitAvailable=true;public bool $visitEmpty=false;
    public string $postMode='accepted';public bool $createUnknown=false;public mixed $duringPost=null;
    public function __construct(?int $visitAt=null){$this->visitAt=$visitAt;}
    public function request(string $method,string $url,mixed $body):array
    {
        $path=(string)parse_url($url,PHP_URL_PATH);$data=[];
        if($method==='GET')$this->gets++;
        if($path==='/stat/v1/data'){
            parse_str((string)parse_url($url,PHP_URL_QUERY),$query);$this->lastReportQuery=$query;
            if(isset($query['dimensions'])){
                $this->visitReads++;$dims=explode(',',$query['dimensions']);$source=count($dims)===4;
                if(!$this->visitAvailable)return ['http'=>503,'error'=>0,'body'=>'PRIVATE_VISIT_REPORT_BODY'];
                preg_match("/clientID=='([0-9]+)'/",$query['filters'],$match);$cid=$match[1]??'0';
                $visit=$this->visitAt??(strtotime($query['date1'].'T00:00:00Z')+21*86400);
                $rowDims=[['name'=>$cid],['name'=>gmdate('Y-m-d H:i:s',$visit)]];
                if($source){$rowDims[]=['id'=>$this->visitSource==='yandex_direct'?'ad':'direct'];$rowDims[]=['id'=>$this->visitSource==='yandex_direct'?'ya_direct':null];}
                $data=['query'=>['ids'=>[98615635],'metrics'=>explode(',',$query['metrics']),'dimensions'=>$dims,'filters'=>$query['filters']],
                    'data'=>$this->visitEmpty?[]:[['dimensions'=>$rowDims,'metrics'=>[1]]],'sampled'=>false,'total_rows'=>$this->visitEmpty?0:1];
                return ['http'=>200,'error'=>0,'body'=>json_encode($data,JSON_THROW_ON_ERROR)];
            }
            if($this->reportHttp!==200)return ['http'=>$this->reportHttp,'error'=>0,'body'=>'PRIVATE_REPORT_API_BODY'];
            $data=['query'=>['ids'=>[$this->reportWrongScope?999:98615635],'metrics'=>explode(',',$query['metrics']),
                'date1'=>$query['date1'],'date2'=>'2026-11-03'],'totals'=>$this->reportTotals,'sampled'=>$this->reportSampled];
        }elseif($method==='GET'&&preg_match('~/management/v1/counter/[0-9]+$~D',$path)){
            $data=['counter'=>['id'=>98615635,'time_zone_name'=>'UTC']];
        }elseif(str_ends_with($path,'/goals')){
            if($method==='POST'){
                $this->creates++;$g=json_decode($body,true,32,JSON_THROW_ON_ERROR)['goal'];$g['id']='678187097';$this->goals[]=$g;
                if($this->createUnknown)return ['error'=>28,'http'=>0,'body'=>''];
            }
            $data=['goals'=>$this->goals];
        }elseif($method==='POST'){
            $this->posts++;$this->csv[]=$body;parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
            $id=(string)(5000+$this->posts);$u=['id'=>$id,'status'=>'PROCESSED','source_quantity'=>1,'line_quantity'=>1,'comment'=>$query['comment']];
            $this->receipts[$id]=$u;if(is_callable($this->duringPost)){$hook=$this->duringPost;$this->duringPost=null;$hook();}
            if($this->postMode==='unknown')return ['error'=>28,'http'=>0,'body'=>''];
            if($this->postMode==='rejected')return ['error'=>0,'http'=>403,'body'=>'{}'];
            $data=['uploading'=>$u];
        }elseif(str_contains($path,'/uploadings'))$data=['uploadings'=>array_values($this->receipts)];
        else{$id=basename($path);$data=['uploading'=>$this->receipts[$id]??null];}
        return ['http'=>200,'error'=>0,'body'=>json_encode($data,JSON_THROW_ON_ERROR)];
    }
    public function api():MetrikaOfflineClient{return new MetrikaOfflineClient('98615635','fixture-only',[$this,'request']);}
}
final class ModeledValueTestLookup
{
    public bool $missing=false;public int $calls=0;public ?int $observedAt=1793500000;
    public function lookup(string $counter,array $users):array
    {
        $this->calls++;
        $out=[];if(!$this->missing)foreach($users as$user)$out[$user]=['external_user_id'=>$user,'client_id'=>'880000'.$user,'record_id'=>'77','observed_at_unix'=>$this->observedAt];
        return $out;
    }
}

function modeledValueMaxAttributionWindowAssertions(PDO $p,callable $check):void
{
    $now=1793660000;$gate=static fn(string $chat):bool=>true;
    foreach(['pending','ready']as$lane){
        modeledValueSchema($p);$scope=modeledValueBinding($p,911);
        $wire=new ModeledValueTestWire($now-86400);$api=$wire->api();$control=new ModeledConversionValueExport($p);
        $control->configure('create-goal',$api,$now-20);$control->configure('enable',$api,$now-10);
        modeledValueSave($p,modeledValueWrapper($scope,$now));
        $lookup=new ModeledValueTestLookup();$lookup->observedAt=$now-10*86400+60;
        if($lane==='ready'){
            $control->collect(ModeledConversionValueExport::settings($p),$now,$gate);
            $row=$p->query('SELECT * FROM max_channel_conversion_outbox LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            $resolve=new ReflectionMethod(ModeledConversionValueExport::class,'resolve');
            $resolve->invoke($control,$row,$lookup->lookup('98615635',[$row['external_user_id']]),$now,$gate);
        }
        $profiles=(string)$p->query('SELECT profile_json FROM modeled_conversion_profile_versions LIMIT 1')->fetchColumn();
        ModeledConversionValueExport::tick($p,$lookup,$api,$now+301,$gate);
        $row=$p->query('SELECT * FROM max_channel_conversion_outbox LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $check('MAX '.$lane.' source expires between the signal and dispatch without an upload or claim',
            $wire->posts===0&&$row['state']==='pending'&&$row['last_error']==='identity_not_found'
            &&(int)$row['attempts']===0&&empty($row['upload_id'])&&empty($row['claim_token']));
        $check('dispatch hold retains the original value time and immutable profile: '.$lane,
            (int)$row['event_unix']===$now&&(int)$row['value_rub']>0
            &&(string)$p->query('SELECT profile_json FROM modeled_conversion_profile_versions LIMIT 1')->fetchColumn()===$profiles);
    }
    modeledValueSchema($p);$scope=modeledValueBinding($p,912);
    $wire=new ModeledValueTestWire($now-86400);$api=$wire->api();$control=new ModeledConversionValueExport($p);
    $control->configure('create-goal',$api,$now-20);$control->configure('enable',$api,$now-10);
    modeledValueSave($p,modeledValueWrapper($scope,$now));$lookup=new ModeledValueTestLookup();$lookup->observedAt=$now-60;
    ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate);
    $p->exec("INSERT INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,platform,identity_source_key,value_rub,value_profile_id,value_stage,value_contract,state,client_id,tracker_observed_unix)
        SELECT project_key,conversation_id,external_user_id,counter_id,'anytour_value_2_v1',transport_target,event_unix,platform,identity_source_key,83,value_profile_id,'specific_request',value_contract,'ready',client_id,tracker_observed_unix FROM max_channel_conversion_outbox LIMIT 1");
    $before=(string)$p->query("SELECT upload_id FROM max_channel_conversion_outbox WHERE target='anytour_value_1_v1'")->fetchColumn();
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+301,$gate);
    $held=$p->query("SELECT * FROM max_channel_conversion_outbox WHERE target='anytour_value_2_v1'")->fetch(PDO::FETCH_ASSOC);
    $check('a later positive delta cannot overwrite an existing provider key at the same second',
        $wire->posts===1&&$held['state']==='ready'&&$held['last_error']==='wire_key_collision'
        &&(int)$held['value_rub']===83&&(int)$held['attempts']===0&&empty($held['upload_id'])
        &&(string)$p->query("SELECT upload_id FROM max_channel_conversion_outbox WHERE target='anytour_value_1_v1'")->fetchColumn()===$before);
    foreach(['unknown'=>null,'future'=>$now+1,'expired'=>$now-10*86400-1,'boundary'=>$now-10*86400,'fresh'=>$now-60]as$case=>$observed){
        modeledValueSchema($p);$scope=modeledValueBinding($p,910);
        $wire=new ModeledValueTestWire($now-86400);$api=$wire->api();$control=new ModeledConversionValueExport($p);
        $control->configure('create-goal',$api,$now-20);$control->configure('enable',$api,$now-10);
        modeledValueSave($p,modeledValueWrapper($scope,$now));$lookup=new ModeledValueTestLookup();$lookup->observedAt=$observed;
        $profiles=(string)$p->query('SELECT profile_json FROM modeled_conversion_profile_versions LIMIT 1')->fetchColumn();
        ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate);
        $q=$p->query('SELECT state,client_id,tracker_observed_unix,last_error,value_rub,event_unix FROM max_channel_conversion_outbox LIMIT 1');
        $row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();$allowed=in_array($case,['boundary','fresh'],true);
        $check('MAX source attribution clock: '.$case,$allowed
            ?$wire->posts===1&&$row['state']==='accepted'&&(int)$row['tracker_observed_unix']===$observed
            :$wire->posts===0&&$row['state']==='pending'&&$row['last_error']==='identity_not_found'&&$row['client_id']===null);
        $check('source-window gate retains the immutable forecast and its positive signal: '.$case,
            (string)$p->query('SELECT profile_json FROM modeled_conversion_profile_versions LIMIT 1')->fetchColumn()===$profiles
            &&(int)$row['value_rub']>0&&(int)$row['event_unix']===$now);
        if(!$allowed){
            $lookup->observedAt=$now-5;ModeledConversionValueExport::tick($p,$lookup,$api,$now+301,$gate);
            $check('a genuinely new prior click releases the same held row: '.$case,
                $wire->posts===1&&(int)$p->query('SELECT COUNT(*) FROM max_channel_conversion_outbox')->fetchColumn()===1);
            ModeledConversionValueExport::tick($p,$lookup,$api,$now+302,$gate);
            $check('a recovered row is not uploaded twice: '.$case,$wire->posts===1);
        }
    }
}

function modeledValueAssertions(PDO $p,callable $check):void
{
    modeledValueMaxAttributionWindowAssertions($p,$check);
    modeledValueReportResponseEvidenceAssertions($check);
    modeledValueCurrentVisitLookbackAssertions($check);
    modeledValueReportTimeoutAssertions($check);
    modeledValueAttributionFailureAssertions($check);
    foreach([
        ['direct',10*86400,'yandex_direct',true],['direct_expired',10*86400+1,'yandex_direct',false],
        ['other',3*86400,'other_or_unknown',true],['other_expired',3*86400+1,'other_or_unknown',false],
        ['unknown',4*86400,'unverified',false],['old_twenty_one_day_assumption',17*86400,'yandex_direct',false]
    ]as[$label,$age,$source,$allow]){
        $n=1793660000;$report=['status'=>'ok','visits'=>[['started_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$n-$age),'visits'=>1,'source_class'=>$source]]];
        $w=ModeledConversionAttributionWindow::assess($report,$n,$n);
        $check('temporary actual-visit window '.$label,$w['allowed']===$allow);
    }
    foreach([['yandex_direct',9*86400,true],['other_or_unknown',4*86400,false],['unverified',4*86400,false]]as[$source,$age,$allowed]){
        $now=1793660000;$gate=static fn(string $chat):bool=>true;
        modeledValueSchema($p);$scope=modeledValueBinding($p,913);$wire=new ModeledValueTestWire($now-86400);$wire->visitSource=$source;$wire->visitAt=$now-$age;
        $api=$wire->api();$control=new ModeledConversionValueExport($p);$control->configure('create-goal',$api,$now-20);$control->configure('enable',$api,$now-10);
        modeledValueSave($p,modeledValueWrapper($scope,$now));$lookup=new ModeledValueTestLookup();$lookup->observedAt=$now-$age;
        ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate);
        $row=$p->query('SELECT * FROM max_channel_conversion_outbox LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $check('actual source gates financial dispatch '.$source.':'.$age,$allowed
            ?$wire->posts===1&&$row['state']==='accepted'
            :$wire->posts===0&&$row['state']==='ready'&&$row['last_error']==='metrika_visit_window'&&(int)$row['attempts']===0&&empty($row['claim_token'])&&empty($row['upload_id']));
    }

    modeledValuePredictionAssertions($p,$check);
    modeledValuePolicyTransitionAssertions($p,$check);
    modeledPriceLossAssertions($p,$check);
    modeledValueCatchUpAssertions($p,$check);
    modeledValueFailureRetentionAssertions($p,$check);
    modeledValueIdentityEvidenceAssertions($p,$check);
    modeledValueFiniteVisitAssertions($p,$check);
    modeledValueVisitRecoveryAssertions($p,$check);
    modeledValueVisitGateDecisionAssertions($p,$check);
    modeledValueFixedPairAssertions($p,$check);
    $now=1793650000;$gate=static fn(string $chat):bool=>true;
    modeledValueSchema($p);$wire=new ModeledValueTestWire($now-86400);$api=$wire->api();$control=new ModeledConversionValueExport($p);
    $check('actual forward migration is default OFF',ModeledConversionValueExport::settings($p)['mode']==='off');
    $scope=modeledValueBinding($p,101);modeledValueSave($p,modeledValueWrapper($scope,$now-20));
    $control->configure('create-goal',$api,$now-10);$control->configure('create-goal',$api,$now-9);
    $check('one exact goal creation preserves other goals and is idempotent',$wire->creates===1&&$wire->goals[0]['name']==='unchanged'&&count($wire->goals)===2);
    $control->configure('enable',$api,$now-8);$s=ModeledConversionValueExport::settings($p);
    $check('activation freezes actual profile floor and time',(int)$s['profile_cursor']===1&&(int)$s['activated_at_unix']===$now-8);
    $control->configure('enable',$api,$now-7);
    $check('repeated enable never resets prospective floor',(int)ModeledConversionValueExport::settings($p)['activated_at_unix']===$now-8);
    $lookup=new ModeledValueTestLookup();
    ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate);
    $check('old profiles are not replayed',$wire->posts===0);
    modeledValueSave($p,modeledValueWrapper($scope,$now));
    ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate);
    $check('real shared-outbox claim sends one value row',$wire->posts===1&&(int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE state='accepted'")->fetchColumn()===1);
    $csv=str_getcsv(explode("\n",trim($wire->csv[0]))[1]);
    $check('actual CSV carries exact goal price RUB and freeze',$csv[1]===ModeledConversionValueContract::TARGET&&$csv[2]===(string)$now&&$csv[3]==='1250'&&$csv[4]==='RUB');
    $legacy=new MaxChannelConversionOutbox($p);
    $check('existing legacy ready and receipt lanes exclude valued rows',$legacy->pending($now)===[]&&$legacy->rows('ready',$now,5)===[]&&$legacy->rows('accepted',$now+5000,5)===[]);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+1,$gate);
    $check('repeat processing never resends reserved value',$wire->posts===1);
    modeledValueSave($p,modeledValueWrapper($scope,$now+5,'concrete_request'));
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+5,$gate);
    $csv=str_getcsv(explode("\n",trim($wire->csv[1]??''))[1]??'');
    $check('current numeric request stage sends only833 increment',$wire->posts===2&&($csv[3]??null)==='833'&&(int)$p->query("SELECT SUM(value_rub) FROM max_channel_conversion_outbox")->fetchColumn()===2083);
    modeledValueSave($p,modeledValueWrapper($scope,$now+10));
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+10,$gate);
    $check('lower stage never adds or subtracts value',$wire->posts===2);
    modeledValueSave($p,modeledValueWrapper($scope,$now+15,'agency_purchase_intent'));
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+15,$gate);
    modeledValueSave($p,modeledValueWrapper($scope,$now+20,'payment_self_report'));
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+20,$gate);
    $check('agency then payment reaches exactly25000 total',(int)$p->query("SELECT SUM(value_rub) FROM max_channel_conversion_outbox")->fetchColumn()===25000&&$wire->posts===4);
    modeledValueSave($p,modeledValueWrapper($scope,$now+25,'manager_payment_ack'));
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+25,$gate);
    $check('manager confirmation never duplicates customer payment value',$wire->posts===4);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+400,$gate);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+401,$gate);
    $check('accepted uploads are polled to validated processed rows',(int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE state='processed'")->fetchColumn()===4&&$wire->posts===4);
    $w=modeledValueWrapper($scope,$now+30);$w['contextual_signals']['events'][]=['goal'=>'refund_report','source_ref'=>'journal:101','product'=>'tour'];
    $check('contrary evidence suppresses a new positive value',ModeledConversionValueContract::candidate($w,$scope,$now-8,$now+30)===null);
    foreach(['office_question','payment_terms_question','booking_confirmation','document_issuance']as$goal)
        $check('no payment/value from '.$goal,ModeledConversionValueContract::candidate(modeledValueWrapper($scope,$now+30,$goal),$scope,$now-8,$now+30)===null);
    $w=modeledValueWrapper($scope,$now+30);$w['subject']['project_key']='foreign';
    $check('foreign scope is rejected',ModeledConversionValueContract::candidate($w,$scope,$now-8,$now+30)===null);
    $w=modeledValueWrapper($scope,$now+30);$w['profile']['observation_input']['evidence'][0]['kind']='manager_message';
    $check('manager wording cannot establish customer interest',ModeledConversionValueContract::candidate($w,$scope,$now-8,$now+30)===null);
    $w=modeledValueWrapper($scope,$now+30);$w['contextual_signals']['events'][0]['product']='flight';
    $check('standalone flight does not inherit package value',ModeledConversionValueContract::candidate($w,$scope,$now-8,$now+30)===null);
    $w=modeledValueWrapper($scope,$now+30);$w['profile']['observation_input']['evidence']=[];
    $check('missing source refs do not award value',ModeledConversionValueContract::candidate($w,$scope,$now-8,$now+30)===null);
    $w=modeledValueWrapper($scope,$now-20);
    $check('backdated preactivation evidence stays excluded',ModeledConversionValueContract::candidate($w,$scope,$now-8,$now+30)===null);
    $before=$wire->posts;$s2=modeledValueBinding($p,102);modeledValueSave($p,modeledValueWrapper($s2,$now+40));
    $lookup->missing=true;ModeledConversionValueExport::tick($p,$lookup,$api,$now+40,$gate);
    $check('missing identity is pending with no fabricated POST',$wire->posts===$before&&(int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE conversation_id=102 AND state='pending'")->fetchColumn()===1);
    $lookup->missing=false;ModeledConversionValueExport::tick($p,$lookup,$api,$now+341,$gate);
    $check('late identity resolves the same immutable row',$wire->posts===$before+1&&(int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE conversation_id=102")->fetchColumn()===1);
    foreach([[2,'max',0],[103,'max',1]]as[$id,$platform,$test]){
        $ss=modeledValueBinding($p,$id,$platform,$test);modeledValueSave($p,modeledValueWrapper($ss,$now+350));
    }
    $before=$wire->posts;ModeledConversionValueExport::tick($p,$lookup,$api,$now+350,$gate);
    $check('owner and test conversations are excluded',$wire->posts===$before);
    $s3=modeledValueBinding($p,104);modeledValueSave($p,modeledValueWrapper($s3,$now+360));
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+360,static fn(string $chat):bool=>false);
    $check('existing destination policy holds capture',$wire->posts===$before);
    $tg=modeledValueBinding($p,105,'telegram');modeledValueSave($p,modeledValueWrapper($tg,$now+370));
    $i=$p->prepare("INSERT INTO advertising_visit_observations(project_key,source_key,platform,counter_id,external_user_id,identity_status,client_id,visit_at_unix,observed_at_unix,received_at_unix) VALUES('anytour','tgmini','telegram','98615635','70105','verified',?,?,?,?)");
    $i->execute(['1000001',$now,$now,$now]);$i->execute(['1000002',$now,$now,$now]);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+370,$gate);
    $check('ambiguous Telegram identity stays pending',$wire->posts===$before
        &&$p->query("SELECT last_error FROM max_channel_conversion_outbox WHERE conversation_id=105")->fetchColumn()==='identity_ambiguous');
    $p->exec("DELETE FROM advertising_visit_observations WHERE client_id='1000002'");
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+671,$gate);
    $check('unique verified Telegram identity uses same existing queue',$wire->posts===$before+1);
    $before=$wire->posts;$unknown=modeledValueBinding($p,106);modeledValueSave($p,modeledValueWrapper($unknown,$now+680));
    $wire->postMode='unknown';ModeledConversionValueExport::tick($p,$lookup,$api,$now+680,$gate);
    $check('lost POST acknowledgment is held UNKNOWN',(string)$p->query("SELECT state FROM max_channel_conversion_outbox WHERE conversation_id=106")->fetchColumn()==='unknown'&&$wire->posts===$before+1);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+681,$gate);
    $check('UNKNOWN is never blindly resubmitted',$wire->posts===$before+1);
    $wire->postMode='accepted';ModeledConversionValueExport::tick($p,$lookup,$api,$now+2481,$gate);
    $check('GET-only recovery accepts original receipt without second POST',$wire->posts===$before+1&&(string)$p->query("SELECT state FROM max_channel_conversion_outbox WHERE conversation_id=106")->fetchColumn()==='processed');
    $reentrant=modeledValueBinding($p,107);modeledValueSave($p,modeledValueWrapper($reentrant,$now+2500));$before=$wire->posts;
    $wire->duringPost=static function()use($p,$lookup,$api,$now,$gate){ModeledConversionValueExport::tick($p,$lookup,$api,$now+2500,$gate);};
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+2500,$gate);
    $check('atomic claim excludes a concurrent exporter for the same row',$wire->posts===$before+1);
    $gets=$wire->gets;$control->configure('disable',$api,$now+2501);
    $off=ModeledConversionValueExport::tick($p,$lookup,$api,$now+2502,$gate);
    $check('disable stops future processing without an API dependency',$off['status']==='off'&&$wire->gets===$gets);
    $floor=(string)ModeledConversionValueExport::settings($p)['profile_cursor'];$control->configure('enable',$api,$now+2503);
    $check('resume retains original floor and contract',(string)ModeledConversionValueExport::settings($p)['profile_cursor']===$floor);
    $event=$p->query("SELECT * FROM max_channel_conversion_outbox ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $posts=$wire->posts;$gets=$wire->gets;
    foreach(['25001','99999','100000','100000.00']as$value){
        $event['value_rub']=$value;$csv=MetrikaOfflineClient::modeledValueCsv($event,$now+2503);
        $cells=str_getcsv(explode("\n",trim($csv))[1]);
        $check('wire accepts the authorized whole-ruble amount '.$value,
            $cells[1]===ModeledConversionValueContract::TARGET&&$cells[3]===(string)(int)$value&&$cells[4]==='RUB');
    }
    foreach(['100001','0','-1','100000.01']as$value){
        $event['value_rub']=$value;$blocked=false;
        try{MetrikaOfflineClient::modeledValueCsv($event,$now+2503);}catch(InvalidArgumentException $e){$blocked=true;}
        $check('wire rejects an out-of-policy amount '.$value,$blocked);
    }
    $check('wire boundary validation performs no provider request',$wire->posts===$posts&&$wire->gets===$gets);
    $result=$control->configure('status',$api,$now+2504);$json=json_encode($result,JSON_THROW_ON_ERROR);
    $check('operational readback contains no people refs amounts or receipts',!str_contains($json,'70101')&&!str_contains($json,'source_ref')&&!str_contains($json,'value_rub')&&!str_contains($json,'upload_id'));

    modeledValueSchema($p);$wire=new ModeledValueTestWire($now-86400);$api=$wire->api();$control=new ModeledConversionValueExport($p);
    $launch=$control->configure('launch-v1',$api,$now);
    $check('one-time accepted-release launch creates verifies and enables',$launch['mode']==='send'&&$wire->creates===1);
    $control->configure('disable',$api,$now+1);$gets=$wire->gets;$launch=$control->configure('launch-v1',$api,$now+2);
    $check('a repeated release cannot reenable a deliberately stopped launch',$launch['already_launched']===true&&$launch['mode']==='off'&&$wire->creates===1&&$wire->gets===$gets);

    modeledValueSchema($p);$wire=new ModeledValueTestWire($now-86400);$wire->createUnknown=true;$api=$wire->api();$control=new ModeledConversionValueExport($p);
    $failed=false;try{$control->configure('create-goal',$api,$now);}catch(Throwable $e){$failed=true;}
    $check('ambiguous goal creation is durably held',$failed&&ModeledConversionValueExport::settings($p)['goal_intent']==='unknown'&&$wire->creates===1);
    $created=$wire->goals[1];array_pop($wire->goals);$blocked=false;
    try{$control->configure('create-goal',$api,$now+1);}catch(Throwable $e){$blocked=true;}
    $check('unknown creation with no matching goal cannot repeat POST',$blocked&&$wire->creates===1&&ModeledConversionValueExport::settings($p)['goal_intent']==='unknown');
    $wire->goals[]=$created;
    $control->configure('create-goal',$api,$now+1);
    $check('lost goal ack is recovered by GET without duplicate creation',ModeledConversionValueExport::settings($p)['goal_intent']==='verified'&&$wire->creates===1);
    $wire->goals[1]['name']='operator change';$blocked=false;
    try{$control->configure('enable',$api,$now+2);}catch(Throwable $e){$blocked=true;}
    $check('operator goal changes prevent activation',$blocked&&ModeledConversionValueExport::settings($p)['mode']==='off');
    $blocked=false;try{$control->configure('arbitrary-operation',$api,$now);}catch(Throwable $e){$blocked=true;}
    $check('operational surface rejects arbitrary commands',$blocked);

    $wire=new ModeledValueTestWire($now-86400);$api=$wire->api();$report=$api->modeledValueReport($now-8,$now);
    $check('fixed read-only report verifies reaches visits and explicit RUB revenue',$report['status']==='ok'&&$report['goal_reaches']===2&&$report['goal_visits']===1&&$report['revenue_rub']===2083.0&&$wire->posts===0);
    $check('session report includes the21day preactivation attribution window',$wire->lastReportQuery['date1']===gmdate('Y-m-d',$now-8-21*86400)
        &&$wire->lastReportQuery['metrics']==='ym:s:goal678187097reaches,ym:s:goal678187097visits,ym:s:goal678187097convertedRUBRevenue');
    $wire->reportWrongScope=true;$report=$api->modeledValueReport($now-8,$now);
    $check('wrong counter report never becomes current goal totals',$report['status']==='unavailable'&&$report['goal_reaches']===null);
    $wire->reportWrongScope=false;$wire->reportHttp=403;$report=$api->modeledValueReport($now-8,$now);
    $check('report permission failure is unknown and contains no API body',$report['status']==='forbidden'&&$report['revenue_rub']===null&&!str_contains(json_encode($report),'PRIVATE_REPORT_API_BODY'));
    $wire->reportHttp=200;$wire->reportSampled=true;$report=$api->modeledValueReport($now-8,$now);
    $check('sampling is explicit rather than exact attribution',$report['status']==='sampled'&&$report['sampled']===true);
    $wire->reportSampled=false;$wire->reportTotals=[0,0,0];$report=$api->modeledValueReport($now-8,$now);
    $check('zero totals require a valid actual scoped report',$report['status']==='ok'&&$report['goal_visits']===0&&$report['revenue_rub']===0.0);

    modeledValueSchema($p);$wire=new ModeledValueTestWire($now-86400);$api=$wire->api();$wire->reportTotals=[0,0,0];
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET mode='send',goal_id='678187097',goal_intent='verified',activated_at_unix=? WHERE project_key='anytour'")->execute([$now-10]);
    ModeledConversionValueExport::tick($p,new ModeledValueTestLookup(),$api,$now,$gate);
    $saved=json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true);
    $check('ordinary idle value step records sanitized API readback without creating events',$saved['metrika_report']['status']==='ok'&&$wire->posts===0&&(int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox")->fetchColumn()===0);
    $wire->goals[]=['id'=>'678187097','name'=>ModeledConversionValueContract::NAME,'type'=>'action','conditions'=>[['type'=>'exact','url'=>ModeledConversionValueContract::TARGET]]];
    $wire->reportHttp=403;$scope=modeledValueBinding($p,201);modeledValueSave($p,modeledValueWrapper($scope,$now+5));
    ModeledConversionValueExport::tick($p,new ModeledValueTestLookup(),$api,$now+5,$gate);
    $saved=json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true);
    $check('report API failure never blocks or retries an otherwise valid upload',$wire->posts===1&&$saved['errors']===0&&$saved['metrika_report']['status']==='forbidden');
}

function modeledValueFailureJsonEqual(mixed $actual,mixed $expected):bool
{
    $sort=static function(mixed $value)use(&$sort):mixed{
        if(!is_array($value))return $value;
        foreach($value as$key=>$item)$value[$key]=$sort($item);
        if($value!==[]&&array_keys($value)!==range(0,count($value)-1))ksort($value);
        return $value;
    };
    return $sort($actual)===$sort($expected);
}

function modeledValueFailureRetentionAssertions(PDO $p,callable $check):void
{
    $now=1793660000;$gate=static fn(string $chat):bool=>true;$steady=static fn():float=>0.0;
    $setup=static function(int $count=1)use($p,$now):array{
        modeledValueSchema($p);$wire=new ModeledValueTestWire($now-86400);
        $wire->goals[]=['id'=>'678187097','name'=>ModeledConversionValueContract::NAME,
            'type'=>'action','conditions'=>[['type'=>'exact','url'=>ModeledConversionValueContract::TARGET]]];
        $p->prepare("UPDATE modeled_conversion_value_export_settings SET mode='send',goal_id='678187097',goal_intent='verified',activated_at_unix=?")->execute([$now-10]);
        for($i=1;$i<=$count;$i++)modeledValueSave($p,modeledValueWrapper(modeledValueBinding($p,1800+$i),$now));
        return [$wire,new ModeledValueTestLookup()];
    };
    $receipt=static fn():array=>json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
    $storage=static function(string $chat):bool{throw new PDOException('PRIVATE_TICK_SQL /private/source.php Bearer fixture-secret');};
    $processing=static function(string $chat):bool{throw new RuntimeException('value_api_unavailable');};

    [$wire,$lookup]=$setup();
    ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now,$storage,null,$steady);
    $first=['tick_at_unix'=>$now,'phase'=>'collect_profiles','category'=>'storage_failed'];
    $check('storage failure metadata is bounded and excludes private exception details',
        modeledValueFailureJsonEqual($receipt()['last_recorded_failure']??null,$first)&&$receipt()['errors']===1
        &&!str_contains(json_encode($receipt()),'PRIVATE_TICK_SQL')&&!str_contains(json_encode($receipt()),'fixture-secret'));
    ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now,$processing,null,$steady);
    $check('equal-second failures preserve the first saved failure',modeledValueFailureJsonEqual($receipt()['last_recorded_failure']??null,$first)&&$receipt()['errors']===1);
    ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now+1,$processing,null,$steady);
    $newer=['tick_at_unix'=>$now+1,'phase'=>'collect_profiles','category'=>'processing_failed'];
    $check('a newer failure replaces the older one and config-like errors outside goal checking stay generic',modeledValueFailureJsonEqual($receipt()['last_recorded_failure']??null,$newer)&&$receipt()['errors']===1);
    ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now+2,$gate,null,$steady);
    $check('a healthy tick keeps failure history while recording its own successful work',
        modeledValueFailureJsonEqual($receipt()['last_recorded_failure']??null,$newer)&&$receipt()['errors']===0&&$receipt()['accepted']===1&&$wire->posts===1);

    foreach(['value_api_unavailable'=>'configuration_unavailable','value_api_unavailable PRIVATE_GOAL_BODY'=>'processing_failed']as$message=>$category){
        [$wire,$lookup]=$setup();
        $api=new MetrikaOfflineClient('98615635','fixture-only',static function(string $method,string $url,mixed $body)use($wire,$message):array{
            if(str_ends_with((string)parse_url($url,PHP_URL_PATH),'/goals'))throw new RuntimeException($message);
            return $wire->request($method,$url,$body);
        });
        ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate,null,$steady);
        $check('goal configuration classification requires an exact message: '.$category,
            modeledValueFailureJsonEqual($receipt()['last_recorded_failure']??null,['tick_at_unix'=>$now,'phase'=>'goal_check','category'=>$category])
            &&$receipt()['errors']===1&&$wire->posts===0&&!str_contains(json_encode($receipt()),'PRIVATE_GOAL_BODY'));
    }
    [$wire,$lookup]=$setup();
    $api=new MetrikaOfflineClient('98615635','fixture-only',static function(string $method,string $url,mixed $body)use($wire):array{
        if(str_ends_with((string)parse_url($url,PHP_URL_PATH),'/goals'))return ['http'=>403,'error'=>0,'body'=>'PRIVATE_GOAL_BODY'];
        return $wire->request($method,$url,$body);
    });
    ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate,null,$steady);
    $check('actual goal-read rejection records its fixed phase without storing the provider body',
        modeledValueFailureJsonEqual($receipt()['last_recorded_failure']??null,['tick_at_unix'=>$now,'phase'=>'goal_check','category'=>'goal_read_failed'])
        &&$receipt()['errors']===1&&$wire->posts===0&&!str_contains(json_encode($receipt()),'PRIVATE_GOAL_BODY'));

    [$wire,$lookup]=$setup(3);$responses=[['error'=>6,'http'=>0],['error'=>0,'http'=>429],['error'=>0,'http'=>403]];
    $api=new MetrikaOfflineClient('98615635','fixture-only',static function(string $method,string $url,mixed $body)use($wire,&$responses):array{
        $actual=$wire->request($method,$url,$body);
        return $method==='POST'?array_shift($responses)+['body'=>'PRIVATE_UPLOAD_BODY']:$actual;
    });
    ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate,null,$steady);
    $check('multiple upload failures retain the first reason while counting every existing error',
        modeledValueFailureJsonEqual($receipt()['last_recorded_failure']??null,['tick_at_unix'=>$now,'phase'=>'upload','category'=>'transport_not_sent'])
        &&$receipt()['errors']===3&&$wire->posts===3&&$responses===[]&&!str_contains(json_encode($receipt()),'PRIVATE_UPLOAD_BODY'));

    [$wire,$lookup]=$setup();$wire->reportHttp=403;
    $api=new MetrikaOfflineClient('98615635','fixture-only',static function(string $method,string $url,mixed $body)use($wire):array{
        $actual=$wire->request($method,$url,$body);
        if($method==='POST')throw new PDOException('PRIVATE_UPLOAD_OUTCOME /private/file.php');
        return $actual;
    });
    $result=ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate,null,$steady);
    $check('an unknown upload and forbidden report create no conversion error or retained failure',
        $result['status']==='running'&&$receipt()['errors']===0&&$receipt()['unknown']===1&&($receipt()['last_recorded_failure']??null)===null
        &&$receipt()['metrika_report']['status']==='forbidden'&&$wire->posts===1&&!str_contains(json_encode($receipt()),'PRIVATE_'));

    [$wire,$lookup]=$setup();
    $api=new class($wire->api()){
        public function __construct(private MetrikaOfflineClient $actual){}
        public function modeledValueGoalStatus():?array{return $this->actual->modeledValueGoalStatus();}
        public function modeledAttributionVisits(string $cid,int $event,int $now):array{return $this->actual->modeledAttributionVisits($cid,$event,$now);}
        public function uploadModeledValue(array $row,int $at):array{return ['outcome'=>'rejected','reason'=>'PRIVATE_UPLOAD_REASON /private/file.php'];}
        public function modeledValueReport(int $activated,int $at):array{return $this->actual->modeledValueReport($activated,$at);}
    };
    ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate,null,$steady);
    $check('unrecognized upload reasons retain only the fixed upload failure category',
        modeledValueFailureJsonEqual($receipt()['last_recorded_failure']??null,['tick_at_unix'=>$now,'phase'=>'upload','category'=>'upload_failed'])
        &&$receipt()['errors']===1&&!str_contains(json_encode($receipt()),'PRIVATE_UPLOAD_REASON'));

    foreach([false,true]as$pressure){
        [$wire,$lookup]=$setup();
        if(!$pressure)ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now,$processing,null,$steady);
        $newWire=new ModeledValueTestWire($now-86400);$newWire->goals=$wire->goals;$wire->reportHttp=403;
        $winning=null;$outsideTransaction=true;$entered=false;
        $api=new MetrikaOfflineClient('98615635','fixture-only',static function(string $method,string $url,mixed $body)use($p,$wire,$newWire,$lookup,$now,$gate,$steady,$pressure,&$winning,&$outsideTransaction,&$entered):array{
            $outsideTransaction=$outsideTransaction&&!$p->inTransaction();
            if((string)parse_url($url,PHP_URL_PATH)==='/stat/v1/data'&&!$entered){
                $entered=true;ModeledConversionValueExport::tick($p,$lookup,$newWire->api(),$now+200,$gate,null,$steady);
                if($pressure){
                    $current=json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
                    unset($current['last_recorded_failure'],$current['visit_gate_decisions']);$current['padding']='';
                    $expanded=$current;$expanded['last_recorded_failure']=['tick_at_unix'=>$now+100,'phase'=>'collect_profiles','category'=>'storage_failed'];
                    $limit=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?8190:8193;
                    $current['padding']=str_repeat('x',$limit-strlen(json_encode($expanded,JSON_THROW_ON_ERROR)));
                    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")->execute([json_encode($current,JSON_THROW_ON_ERROR)]);
                }
                $winning=ModeledConversionValueExport::settings($p);
            }
            return $wire->request($method,$url,$body);
        });
        ModeledConversionValueExport::tick($p,$lookup,$api,$now+100,$storage,null,$steady);
        $after=ModeledConversionValueExport::settings($p);
        if($pressure){
            $expanded=json_decode($winning['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
            $expanded['last_recorded_failure']=['tick_at_unix'=>$now+100,'phase'=>'collect_profiles','category'=>'storage_failed'];
            $encoded=json_encode($expanded,JSON_THROW_ON_ERROR);$nativeLength=strlen($encoded);
            if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
                $q=$p->prepare('SELECT OCTET_LENGTH(CAST(? AS JSON))');$q->execute([$encoded]);$nativeLength=(int)$q->fetchColumn();
                $check('the native JSON boundary fixture fits compact JSON but exceeds stored bytes',strlen($encoded)<=8192&&$nativeLength>8192);
            }
            $check('stale failure enrichment never enlarges the existing receipt beyond its read limit',
                $entered&&$outsideTransaction&&strlen($winning['last_receipt_json'])<=8192&&$nativeLength>8192&&$after===$winning);
        }else{
            $expected=json_decode($winning['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
            $expected['last_recorded_failure']=['tick_at_unix'=>$now+100,'phase'=>'collect_profiles','category'=>'storage_failed'];
            $actual=json_decode($after['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
            unset($after['last_receipt_json'],$winning['last_receipt_json']);
            $check('a delayed failed tick enriches a newer healthy receipt without changing its counts or report clocks',
                $entered&&$outsideTransaction&&modeledValueFailureJsonEqual($actual,$expected)&&$after===$winning&&$actual['errors']===0&&$actual['accepted']===1);
        }
    }

    foreach([false,true]as$olderHealthy){
        [$wire,$lookup]=$setup(0);$nestedWire=new ModeledValueTestWire($now-86400);$nestedWire->goals=$wire->goals;$entered=false;$outsideTransaction=true;
        $failedAt=$now+($olderHealthy?200:100);$healthyAt=$now+($olderHealthy?100:200);
        $api=new MetrikaOfflineClient('98615635','fixture-only',static function(string $method,string $url,mixed $body)use($p,$wire,$nestedWire,$lookup,$now,$failedAt,$storage,$steady,&$entered,&$outsideTransaction):array{
            $outsideTransaction=$outsideTransaction&&!$p->inTransaction();
            if((string)parse_url($url,PHP_URL_PATH)==='/stat/v1/data'&&!$entered){
                $entered=true;modeledValueSave($p,modeledValueWrapper(modeledValueBinding($p,1801),$now));
                ModeledConversionValueExport::tick($p,$lookup,$nestedWire->api(),$failedAt,$storage,null,$steady);
            }
            return $wire->request($method,$url,$body);
        });
        ModeledConversionValueExport::tick($p,$lookup,$api,$healthyAt,$gate,null,$steady);
        $check($olderHealthy?'a stale healthy tick preserves the newer failure and its current error count':
            'a healthy tick merges failure committed during its report instead of its stale starting settings',
            $entered&&$outsideTransaction&&modeledValueFailureJsonEqual($receipt()['last_recorded_failure']??null,['tick_at_unix'=>$failedAt,'phase'=>'collect_profiles','category'=>'storage_failed'])
            &&$receipt()['errors']===($olderHealthy?1:0)&&$receipt()['collected']===0&&(int)ModeledConversionValueExport::settings($p)['last_tick_unix']===$now+200);
    }

    [$wire,$lookup]=$setup(0);
    $future=['errors'=>0,'last_recorded_failure'=>['tick_at_unix'=>$now+10,'phase'=>'upload','category'=>'api_rejected']];
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_tick_unix=?,last_receipt_json=? WHERE project_key='anytour'")->execute([$now,json_encode($future,JSON_THROW_ON_ERROR)]);
    ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now+20,$gate,null,$steady);
    $check('a failure ahead of its saved tick never becomes valid just because a later healthy tick runs',
        ($receipt()['last_recorded_failure']??null)===null&&$receipt()['errors']===0&&(int)ModeledConversionValueExport::settings($p)['last_tick_unix']===$now+20);
}

function modeledValueHistoryAssertions(PDO $p,callable $check):void
{
    modeledValueSchema($p);$now=1793659000;$from=1793570400;$until=1793656800;$at=$from+3600;
    $subject=modeledValueBinding($p,301);
    $w=modeledValueWrapper($subject,$at);
    $w['adapter']=['version'=>'admitted_journal_profile_v1_5'];
    $w['processing']['version']='admitted_journal_profiles_v1_2';
    $w['contextual_signals']['extractor_version']=ArchiveResearchSemanticExtractor::VERSION;
    $w['profile']['prediction_at']=gmdate('Y-m-d\TH:i:s\Z',$at);
    $w['contextual_signals']['adapter_sha256']=str_repeat('a',64);
    $original=json_encode($w,JSON_THROW_ON_ERROR);modeledValueSave($p,$w);modeledValueSave($p,$w);
    $storedProfile=$p->query('SELECT profile_json FROM modeled_conversion_profile_versions ORDER BY id LIMIT 1')->fetchColumn();
    $p->prepare('UPDATE modeled_conversion_profile_versions SET observed_at_unix=?')->execute([$at]);
    $candidate=ModeledConversionValueContract::historicalCandidate($w,$subject,$from,$until,$now);
    $check('historical review retains old evidence time and dates value assignment at review',
        $candidate!==null&&$candidate['value']===1250&&$candidate['event_unix']===$now&&$candidate['evidence_at']===$at-2&&json_encode($w,JSON_THROW_ON_ERROR)===$original);
    $check('old source hash remains rejected by the unchanged prospective contract',
        ModeledConversionValueContract::candidate($w,$subject,$from,$now)===null);
    $outside=$w;$outside['contextual_signals']['frozen_at']=gmdate('Y-m-d\TH:i:s\Z',$until);
    $check('historical review excludes a later frozen profile and non-day windows',
        ModeledConversionValueContract::historicalCandidate($outside,$subject,$from,$until,$now)===null
        &&ModeledConversionValueContract::historicalCandidate($w,$subject,$from,$until+1,$now)===null);
    $bad=$w;$bad['subject']['customer_key']='999';
    $check('historical review preserves exact subject and actual customer-role evidence',
        ModeledConversionValueContract::historicalCandidate($bad,$subject,$from,$until,$now)===null);
    $wire=new ModeledValueTestWire($now-86400);$wire->goals[]=['id'=>'678187097','name'=>ModeledConversionValueContract::NAME,
        'type'=>'action','conditions'=>[['type'=>'exact','url'=>ModeledConversionValueContract::TARGET]]];
    $api=$wire->api();$control=new ModeledConversionValueExport($p);$gate=static fn(string $chat):bool=>true;
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET mode='send',goal_id='678187097',goal_intent='verified',activated_at_unix=?,profile_cursor=2")->execute([$until-60]);
    $check('historical collection is inactive until its finite explicit configuration',$control->collectHistory($now,$gate)===0);
    $before=ModeledConversionValueExport::settings($p);
    $control->configure('launch-history-20261102',$api,$now);
    $check('day configuration preserves the original prospective activation and cursor',
        ModeledConversionValueExport::settings($p)['activated_at_unix']===$before['activated_at_unix']
        &&ModeledConversionValueExport::settings($p)['profile_cursor']===$before['profile_cursor']);
    $n=$control->collectHistory($now,$gate);
    $row=$p->query("SELECT * FROM max_channel_conversion_outbox")->fetch(PDO::FETCH_ASSOC);
    $check('historical day writes original profile provenance and one true incremental value',
        $n===1&&(int)$row['value_rub']===1250&&$row['value_origin']==='owner_day_review_v1'
        &&(int)$row['value_profile_id']===1&&(int)$row['value_reviewed_at_unix']===$now);
    $control->configure('launch-history-20261102',$api,$now+1);
    $check('relaunch and later normal ticks cannot replay the completed day',
        $control->collectHistory($now+1,$gate)===0
        &&(int)ModeledConversionValueExport::settings($p)['history_scanned']===2
        &&(int)$p->query('SELECT COUNT(*) FROM max_channel_conversion_outbox')->fetchColumn()===1);
    ModeledConversionValueExport::tick($p,new ModeledValueTestLookup(),$api,$now+2,$gate);
    $row=$p->query("SELECT * FROM max_channel_conversion_outbox")->fetch(PDO::FETCH_ASSOC);
    $check('historical rows use the existing actual CSV identity and receipt path',
        $wire->posts===1&&$row['state']==='accepted'&&str_contains($wire->csv[0],',1250,RUB'));
    $check('historical processing never edits or appends source profile versions',
        (int)$p->query('SELECT COUNT(*) FROM modeled_conversion_profile_versions')->fetchColumn()===2
        &&$p->query('SELECT profile_json FROM modeled_conversion_profile_versions ORDER BY id LIMIT 1')->fetchColumn()===$storedProfile);
}


function modeledValueFortnightAssertions(PDO $p,callable $check):void
{
    modeledValueSchema($p);$now=1793659000;$from=1792447200;$day=1793570400;$until=1793656800;
    $a=modeledValueBinding($p,401);$b=modeledValueBinding($p,402,'telegram');$c=modeledValueBinding($p,403);
    $save=static function(array $subject,int $at,string $goal)use($p):array{
        $w=modeledValueWrapper($subject,$at,$goal);$w['adapter']=['version'=>'admitted_journal_profile_v1_5'];
        $w['processing']['version']='admitted_journal_profiles_v1_2';
        $w['contextual_signals']['extractor_version']=ArchiveResearchSemanticExtractor::VERSION;
        modeledValueSave($p,$w);$p->prepare('UPDATE modeled_conversion_profile_versions SET observed_at_unix=? WHERE id=?')->execute([$at,$p->lastInsertId()]);return $w;
    };
    $old=$save($a,$from+3600,'general_purchase_intent');$save($b,$from+7200,'substantive_interest');
    $save($c,$from+10800,'agency_purchase_intent');$save($a,$day+3600,'substantive_interest');
    $beforeProfiles=json_encode($p->query('SELECT * FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);
    $wire=new ModeledValueTestWire($now-86400);$wire->goals[]=['id'=>'678187097','name'=>ModeledConversionValueContract::NAME,
        'type'=>'action','conditions'=>[['type'=>'exact','url'=>ModeledConversionValueContract::TARGET]]];
    $api=$wire->api();$control=new ModeledConversionValueExport($p);$gate=static fn(string $chat):bool=>true;
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET mode='send',goal_id='678187097',goal_intent='verified',activated_at_unix=?,profile_cursor=4")->execute([$until-60]);
    $control->configure('launch-history-20261102',$api,$now);
    $control->configure('launch-history-fortnight-v1',$api,$now);
    $s=ModeledConversionValueExport::settings($p);
    $check('fortnight request cannot interrupt the first-day cursor or skip its review',
        (int)$s['history_from_unix']===$day&&(int)$s['history_cursor']===0&&$s['history_day_receipt_json']===null);
    $check('first-day review still runs to completion before any expansion',$control->collectHistory($now,$gate)===1
        &&(int)ModeledConversionValueExport::settings($p)['history_scanned']===1);
    $p->exec("UPDATE max_channel_conversion_outbox SET state='unknown',last_error='upload_outcome_unknown'");
    $daySettings=ModeledConversionValueExport::settings($p);
    ModeledConversionValueExport::tick($p,new ModeledValueTestLookup(),$api,$now+1,$gate);
    $s=ModeledConversionValueExport::settings($p);$receipt=$s['history_day_receipt_json'];$first=json_decode($receipt,true,32,JSON_THROW_ON_ERROR);
    $check('finite expansion preserves the completed day receipt activation and live cursor',
        (int)$s['history_from_unix']===$from&&(int)$s['history_until_unix']===$until
        &&(int)$first['history_scanned']===1&&(int)$first['history_collected']===1
        &&$first['history_completed_at_unix']==$daySettings['history_completed_at_unix']
        &&$s['activated_at_unix']===$daySettings['activated_at_unix']&&$s['profile_cursor']===$daySettings['profile_cursor']);
    $rows=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $check('historical increment counts UNKNOWN reservations and never repeats their upload',
        count($rows)===4&&(int)$rows[1]['value_rub']===7083&&$rows[0]['state']==='unknown'
        &&array_sum(array_map(static fn(array $r):int=>(int)$r['value_rub'],$rows))===22083);
    $check('missing Telegram identity preserves its value while other eligible rows upload',
        $rows[2]['state']==='pending'&&$rows[2]['last_error']==='identity_not_found'
        &&$rows[1]['state']==='accepted'&&$rows[3]['state']==='accepted'&&$wire->posts===2&&$wire->creates===0);
    $check('fortnight rows retain their separate provenance and old source clocks',
        $rows[1]['value_origin']==='owner_fortnight_review_v1'&&(int)$rows[1]['value_profile_id']===1
        &&ModeledConversionValueContract::historicalCandidate($old,$a,$from,$until,$now)['evidence_at']===$from+3598);
    $control->configure('launch-history-fortnight-v1',$api,$now+2);
    $check('repeated finite request cannot reset completed review or its immutable first-day receipt',
        $control->collectHistory($now+2,$gate)===0&&ModeledConversionValueExport::settings($p)['history_day_receipt_json']===$receipt
        &&(int)ModeledConversionValueExport::settings($p)['history_scanned']===4);
    $rewind=false;try{$control->configure('launch-history-20261102',$api,$now+2);}catch(RuntimeException $e){$rewind=$e->getMessage()==='value_configuration_changed';}
    $check('old day operation cannot rewind an expanded history',$rewind&&ModeledConversionValueExport::settings($p)['history_day_receipt_json']===$receipt);
    $check('all historical profile bytes remain exactly as stored',$beforeProfiles===json_encode($p->query('SELECT * FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));
    $check('historical review rejects unapproved larger windows and future end',
        ModeledConversionValueContract::historicalCandidate($old,$a,$from-86400,$until,$now)===null
        &&ModeledConversionValueContract::historicalCandidate($old,$a,$from,$until,$until-1)===null);
}


/** Real local transactions under one ordinary exporter tick; every provider is a disposable stub. */
function modeledValueCatchUpAssertions(PDO $p,callable $check):void
{
    $now=1793658000;$gate=static fn(string $chat):bool=>true;$steady=static fn():float=>0.0;
    $setup=static function(int $count,int $empty=0)use($p,$now):array{
        modeledValueSchema($p);$wire=new ModeledValueTestWire($now-86400);
        $wire->goals[]=['id'=>'678187097','name'=>ModeledConversionValueContract::NAME,
            'type'=>'action','conditions'=>[['type'=>'exact','url'=>ModeledConversionValueContract::TARGET]]];
        $lookup=new ModeledValueTestLookup();$lookup->missing=true;
        $p->prepare("UPDATE modeled_conversion_value_export_settings SET mode='send',goal_id='678187097',goal_intent='verified',activated_at_unix=?")->execute([$now-10]);
        for($i=1;$i<=$count;$i++){
            $w=modeledValueWrapper(modeledValueBinding($p,1500+$i),$now);
            if($i<=$empty)$w['contextual_signals']['events']=[];
            modeledValueSave($p,$w);
        }
        return [$wire,$lookup];
    };
    $cursor=static fn():int=>(int)ModeledConversionValueExport::settings($p)['profile_cursor'];
    $rows=static fn():int=>(int)$p->query('SELECT COUNT(*) FROM max_channel_conversion_outbox')->fetchColumn();
    $receipt=static fn():array=>json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
    $profiles=static fn():array=>$p->query('SELECT id,profile_json FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

    [$wire,$lookup]=$setup(40,8);$before=$profiles();
    ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now,$gate,null,$steady);
    $check('local collection advances past a zero-award page and stops at four committed pages',
        $cursor()===32&&$rows()===24&&$receipt()['collected']===24&&$receipt()['errors']===0);
    ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now+300,$gate,null,$steady);
    $check('the next ordinary tick collects the remaining saved profiles exactly once',$cursor()===40&&$rows()===32&&$receipt()['collected']===8);
    ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now+600,$gate,null,$steady);
    $check('empty local catch-up preserves all profile bytes and does not recreate reserved rows',
        $cursor()===40&&$rows()===32&&$receipt()['collected']===0&&$before===$profiles()&&$wire->posts===0);

    [$wire,$lookup]=$setup(9);$appended=false;
    $append=static function(string $chat)use($p,$now,&$appended):bool{
        if(!$appended){$appended=true;modeledValueSave($p,modeledValueWrapper(modeledValueBinding($p,1600),$now));}
        return true;
    };
    ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now,$append,null,$steady);
    $check('all local pages retain one frozen profile ceiling despite an in-flight append',
        $cursor()===9&&$rows()===9&&(int)$p->query('SELECT MAX(id) FROM modeled_conversion_profile_versions')->fetchColumn()===10);
    ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now+300,$gate,null,$steady);
    $check('a profile beyond the original ceiling waits for the next ordinary tick',$cursor()===10&&$rows()===10&&$receipt()['collected']===1);

    [$wire,$lookup]=$setup(24);$attempts=0;
    $failSecond=static function(string $chat)use(&$attempts):bool{
        $attempts++;if($chat==='-71509')throw new RuntimeException('synthetic_second_page_failure');return true;
    };
    $result=ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now,$failSecond,null,$steady);
    $check('a later collection failure preserves committed progress and its exact receipt without retry',
        $result['status']==='degraded'&&$cursor()===8&&$rows()===8&&$receipt()['collected']===8
        &&$receipt()['errors']===1&&$attempts===9&&$lookup->calls===0&&$wire->posts===0&&$wire->gets===1);
    $failedPage=['tick_at_unix'=>$now,'phase'=>'collect_profiles','category'=>'processing_failed'];
    $check('a later-page error retains only its fixed phase category and original tick',
        modeledValueFailureJsonEqual($receipt()['last_recorded_failure']??null,$failedPage)&&!str_contains(json_encode($receipt()),'synthetic_second_page_failure'));
    ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now+300,$gate,null,$steady);
    $check('the next normal tick resumes a failed page without duplicating earlier reservations',
        $cursor()===24&&$rows()===24&&$receipt()['collected']===16&&$receipt()['errors']===0);
    $check('successful page recovery preserves the earlier failure without changing current errors',
        modeledValueFailureJsonEqual($receipt()['last_recorded_failure']??null,$failedPage)&&(int)ModeledConversionValueExport::settings($p)['last_tick_unix']===$now+300);

    [$wire,$lookup]=$setup(24);$clocks=0;
    $deadline=static function()use(&$clocks):float{return $clocks++===0?0.0:5.0;};
    ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now,$gate,null,$deadline);
    $check('the monotonic start deadline admits no second page and does not change business time',
        $cursor()===8&&$rows()===8&&$receipt()['collected']===8&&$receipt()['errors']===0
        &&(int)ModeledConversionValueExport::settings($p)['last_tick_unix']===$now);

    [$wire,$lookup]=$setup(16);
    $disable=static function(string $chat)use($p):bool{
        if($chat==='-71508')$p->exec("UPDATE modeled_conversion_value_export_settings SET mode='off' WHERE project_key='anytour'");
        return true;
    };
    ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now,$disable,null,$steady);
    $check('an operator stop between committed pages prevents further local collection',
        $cursor()===8&&$rows()===8&&ModeledConversionValueExport::settings($p)['mode']==='off'&&$wire->posts===0);

    [$wire,$lookup]=$setup(0);$scope=modeledValueBinding($p,1501);
    for($i=0;$i<8;$i++)modeledValueSave($p,modeledValueWrapper($scope,$now));
    for($i=0;$i<8;$i++)modeledValueSave($p,modeledValueWrapper($scope,$now+1,'concrete_request'));
    modeledValueSave($p,modeledValueWrapper($scope,$now+2));
    $p->prepare("INSERT INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,platform,state,next_attempt_unix,value_rub,value_contract,claim_token) VALUES('anytour',1501,'71501','98615635','prior_unknown',?,?, 'max','unknown',?,1000,?,'preserved_claim')")
        ->execute([ModeledConversionValueContract::TARGET,$now-1,$now+86400,ModeledConversionValueContract::VERSION]);
    $unknown=$p->query("SELECT * FROM max_channel_conversion_outbox WHERE target='prior_unknown'")->fetch(PDO::FETCH_ASSOC);
    ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now+2,$gate,null,$steady);
    $check('cross-page growth lower estimates and repeats include an existing unknown reservation once',
        $cursor()===17&&$rows()===3&&$receipt()['collected']===2
        &&(int)$p->query('SELECT SUM(value_rub) FROM max_channel_conversion_outbox')->fetchColumn()===2083
        &&$unknown===$p->query("SELECT * FROM max_channel_conversion_outbox WHERE target='prior_unknown'")->fetch(PDO::FETCH_ASSOC));

    [$wire,$lookup]=$setup(40);$lookup->missing=false;
    ModeledConversionValueExport::tick($p,$lookup,$wire->api(),$now,$gate,null,$steady);
    $check('four local pages retain one lookup one aggregate report and bounded visit reads within the original three-upload limit',
        $cursor()===32&&$rows()===32&&$receipt()['collected']===32&&$receipt()['resolved']===MaxTrackerLookupProtocol::MAX_USERS
        &&$lookup->calls===1&&$wire->posts===3&&$wire->gets===6&&$wire->visitReads===3&&$receipt()['accepted']===3&&$receipt()['errors']===0);
}

function modeledValueFairProgressAssertions(PDO $p,callable $check):void
{
    modeledValueSchema($p);$now=1793659000;$gate=static fn(string $chat):bool=>true;
    $wire=new ModeledValueTestWire($now-86400);$wire->goals[]=['id'=>'678187097','name'=>ModeledConversionValueContract::NAME,
        'type'=>'action','conditions'=>[['type'=>'exact','url'=>ModeledConversionValueContract::TARGET]]];
    $api=$wire->api();$lookup=new ModeledValueTestLookup();
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET mode='send',goal_id='678187097',goal_intent='verified',activated_at_unix=?")->execute([$now-10]);
    for($i=501;$i<=506;$i++)modeledValueSave($p,modeledValueWrapper(modeledValueBinding($p,$i,$i===506?'max':'telegram'),$now));
    ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate);
    $check('a fresh valid identity behind five held rows resolves and sends within the same bounded tick',
        $wire->posts===1&&(int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE last_error='identity_not_found'")->fetchColumn()===5);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+300,$gate);
    $check('the next tick polls the accepted fresh row while preserving all five missing identities',
        $wire->posts===1&&$p->query("SELECT state FROM max_channel_conversion_outbox WHERE conversation_id=506")->fetchColumn()==='processed'
        &&(int)$p->query("SELECT SUM(value_rub) FROM max_channel_conversion_outbox WHERE state='pending'")->fetchColumn()===6250);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+600,$gate);
    $check('fair progress keeps missing rows and polls the accepted receipt without resending',
        $wire->posts===1&&(int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE state='pending'")->fetchColumn()===5
        &&$p->query("SELECT state FROM max_channel_conversion_outbox WHERE conversation_id=506")->fetchColumn()==='processed');

    // Synthetic legacy profiles can have an audit hold after a model/adapter
    // upgrade. Those rows retain their values but must not monopolize ready.
    modeledValueSchema($p);$now=1793670000;
    $wire=new ModeledValueTestWire($now-86400);$wire->visitAt=$now-60;$api=$wire->api();
    $control=new ModeledConversionValueExport($p);
    $control->configure('create-goal',$api,$now-500);
    $control->configure('enable',$api,$now-500);
    $lookup=new ModeledValueTestLookup();$lookup->observedAt=$now-120;
    for($id=9011;$id<=9013;$id++){
        $scope=modeledValueBinding($p,$id);
        $legacy=modeledExpertWrapper($scope,$now-100,200000);
        $value=$legacy['deal_value_prediction']['value_rub'];
        // A former adapter hash represents the original immutable version.
        $legacy['contextual_signals']['adapter_sha256']=str_repeat('0',64);
        modeledValueSave($p,$legacy);$profile=(string)$p->lastInsertId();
        $p->prepare("INSERT INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,platform,identity_source_key,value_rub,value_profile_id,value_stage,value_contract,state,client_id,tracker_observed_unix)"
            ." VALUES('anytour',?,?,'98615635',?,?,?,'max','max_tracker',?,?,?,?,'ready',?,?)")
            ->execute([$id,(string)(70000+$id),ModeledConversionValueContract::predictionKey($profile),
                ModeledConversionValueContract::TARGET,$now-100,$value,$profile,'specific_request',
                ModeledConversionValueContract::VERSION,'880000'.(70000+$id),$now-120]);
    }
    $heldSnapshot=static fn():array=>$p->query("SELECT id,target,event_unix,value_rub,value_profile_id,state,attempts,claim_token,claimed_at_unix,upload_id,upload_status FROM max_channel_conversion_outbox WHERE conversation_id BETWEEN 9011 AND 9013 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $heldBefore=$heldSnapshot();
    $fresh=modeledExpertWrapper(modeledValueBinding($p,9014),$now,200000);
    $expected=$fresh['deal_value_prediction']['value_rub'];modeledValueSave($p,$fresh);
    $frozen=$p->query('SELECT id,profile_json FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate);
    $check('legacy adapter rows receive genuine price-audit holds without sending or changing their reservations',
        $wire->posts===0&&$heldSnapshot()===$heldBefore
        &&(int)$p->query("SELECT COUNT(*) FROM modeled_conversion_price_audit WHERE action='unverified'")->fetchColumn()===3
        &&$p->query("SELECT state FROM max_channel_conversion_outbox WHERE conversation_id=9014")->fetchColumn()==='ready');
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+300,$gate);
    $check('three price-held ready rows cannot indefinitely block a later eligible conversion',
        $wire->posts===1&&$heldSnapshot()===$heldBefore
        &&$p->query("SELECT state FROM max_channel_conversion_outbox WHERE conversation_id=9014")->fetchColumn()==='accepted'
        &&str_contains($wire->csv[0],'88000079014')&&str_contains($wire->csv[0],','.$expected.',RUB'));
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+600,$gate);
    $check('price-hold fairness preserves source snapshots and reconciles the one accepted receipt without resending',
        $wire->posts===1&&$heldSnapshot()===$heldBefore
        &&$p->query("SELECT state FROM max_channel_conversion_outbox WHERE conversation_id=9014")->fetchColumn()==='processed'
        &&$p->query('SELECT id,profile_json FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$frozen);
}


/** Synthetic admitted prefix used by both SQLite and the isolated native-MySQL contract. */
function modeledExpertWrapper(array $subject,int $now,int $budget,bool $office=false,bool $hold=false,?string $holdText=null,bool $posted=false):array
{
    $freeze=gmdate('Y-m-d\TH:i:s\Z',$now);$messages=[['customer','Подберите тур в Турцию на 7 ночей. Бюджет '.$budget.' рублей на всех.']];
    if($office)$messages[]=['customer','Где находится ваш офис?'];
    if($hold){$messages[]=['customer','Оплатила'];$messages[]=['manager',$holdText??'Денежные средства заморожены, списания еще нет.'];
        if($posted)$messages[]=['manager','Денежные средства успешно списаны.'];}
    $model=new ModeledConversionSemanticPrediction($subject,$freeze,$freeze,$freeze,'1000','1000',null,true);
    $state=ArchiveResearchSemanticExtractor::initialState();$evidence=[];$rows=[];$events=[];
    foreach($messages as$i=>[$role,$text]){
        $id=(string)(101+$i);$ref='journal:'.$id;$at=$now-200+$i*10;$available=$at+1;
        $r=ArchiveResearchSemanticExtractor::step(['source_ref'=>$ref,'created_at'=>$at,'read_status'=>'available','text'=>$text,
            'role'=>$role,'direction'=>$role==='customer'?'inbound':'outbound','role_verified'=>true,'text_minimized'=>true,'forwarded'=>false,
            'media'=>[],'media_contents_read'=>false,'selection_links_unread'=>false,'links_redacted'=>false],$state);
        $state=$r['state'];$model->observeMessage($role,$text,$ref,$at,$available,1,$r);$events=[...$events,...$r['events']];
        $atText=gmdate('Y-m-d\TH:i:s\Z',$at);$availableText=gmdate('Y-m-d\TH:i:s\Z',$available);
        $reference=['source'=>'message','id'=>$ref,'at'=>$atText,'available_at'=>$availableText];
        $evidence[]=['id'=>'message:'.$ref,'subject'=>$subject,'kind'=>$role.'_message','at'=>$atText,'available_at'=>$availableText,'references'=>[$reference]];
        $rows[]=['journal_id'=>$id,'source_kind'=>'message','source_id'=>$id,'source_version_no'=>1,'observed_at_unix'=>$available,
            'snapshot'=>$subject+['source_kind'=>'message','source_id'=>$id,'source_status'=>'present','payload_status'=>'copied',
                'source_created_at_unix'=>$at,'source_timestamp_basis'=>'database_session_timezone_to_unix',
                'payload'=>['text'=>$text,'direction'=>$role==='customer'?'inbound':'outbound','sender_type'=>$role,'metadata_json'=>'{"type":"message"}']]];
    }
    $w=['subject'=>$subject,'access_status'=>'allowed','profile'=>['prediction_at'=>$freeze,
        'observation_input'=>['subject'=>$subject,'evidence'=>$evidence],
        'fields'=>['budget'=>['status'=>'known','usable_at_prediction'=>true,'available_at'=>$freeze,
            'value'=>['minimum'=>$budget,'maximum'=>$budget,'currency'=>'RUB','unit'=>'party_total'],'references'=>$evidence[0]['references']]]],
        'processing'=>['source_sha256'=>hash_file('sha256',dirname(__DIR__).'/services/ModeledConversionProfiles.php')],
        'contextual_signals'=>['version'=>ModeledConversionEarlyForecast::VERSION,'extractor_sha256'=>hash_file('sha256',dirname(__DIR__).'/services/ArchiveResearchSemanticExtractor.php'),
            'adapter_sha256'=>hash_file('sha256',dirname(__DIR__).'/services/ModeledConversionEarlyForecast.php'),
            'frozen_at'=>$freeze,'input_cutoff_at'=>$freeze,'events'=>$events]];
    $w['semantic_prediction']=$model->finish(['early_forecast'=>['status'=>'early_assessment']]);
    $w['deal_value_prediction']=ModeledConversionDealValue::freeze($w,['subject'=>$subject,'rows'=>$rows]);
    return $w;
}
function modeledUnknownPriceWrapper(array $w,bool $fallback=false):array
{
    unset($w['profile']['fields']['budget']);
    if($fallback){$w['semantic_prediction']['status']='insufficient_context';$w['semantic_prediction']['estimated_probability']=null;
        $w['semantic_prediction']['base_probability']=null;$w['semantic_prediction']['hypothesis_odds_multiplier']=null;
        $w['semantic_prediction']['prediction_digest']=ModeledConversionSemanticPrediction::digest($w['semantic_prediction']);}
    $w['deal_value_prediction']=ModeledConversionDealValue::freeze($w,['rows'=>[]]);return$w;
}
function modeledValuePredictionAssertions(PDO $p,callable $check):void
{
    modeledValueSchema($p);$now=1793665000;$scope=modeledValueBinding($p,650);
    $wire=new ModeledValueTestWire($now-86400);$api=$wire->api();$control=new ModeledConversionValueExport($p);
    $control->configure('create-goal',$api,$now-500);$control->configure('enable',$api,$now-500);
    $lookup=new class{public function lookup(string $counter,array $users):array{$out=[];foreach($users as$u)$out[$u]=['external_user_id'=>$u,'client_id'=>'88000001','record_id'=>'1','observed_at_unix'=>1793665000];return$out;}};
    $gate=static fn(string $chat):bool=>true;
    $first=modeledExpertWrapper($scope,$now,200000);modeledValueSave($p,$first);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate);
    $sum=static fn():int=>(int)$p->query("SELECT COALESCE(SUM(value_rub),0) FROM max_channel_conversion_outbox WHERE conversation_id=650")->fetchColumn();
    $diagnostic=['posts'=>$wire->posts,'reserved'=>$sum(),'rows'=>$p->query('SELECT state,last_error,value_rub,value_profile_id FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'audit'=>$p->query('SELECT action,applied_value_rub FROM modeled_conversion_price_audit ORDER BY outbox_id')->fetchAll(PDO::FETCH_ASSOC)];
    $check('expert V1 actual CSV carries seven-percent expected commission rather than fixed stage value '.json_encode($diagnostic,JSON_THROW_ON_ERROR),
        $wire->posts===1&&$sum()===1167&&str_contains($wire->csv[0],',1167,RUB')
        &&str_starts_with((string)$p->query("SELECT target FROM max_channel_conversion_outbox")->fetchColumn(),'anytour_value_pred_'));
    $stronger=modeledExpertWrapper($scope,$now+1,200000,true);modeledValueSave($p,$stronger);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+1,$gate);
    $expected=$stronger['deal_value_prediction']['value_rub'];
    $check('meaning strengthens value within the same stage and exports only the positive increment',
        $wire->posts===2&&$sum()===$expected&&$expected>1167
        &&$stronger['semantic_prediction']['stage']===$first['semantic_prediction']['stage']);
    modeledValueSave($p,$stronger);ModeledConversionValueExport::tick($p,$lookup,$api,$now+2,$gate);
    $check('an identical saved forecast version never duplicates cumulative value',$wire->posts===2&&$sum()===$expected);
    $repriced=modeledExpertWrapper($scope,$now+3,300000,true);modeledValueSave($p,$repriced);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+3,$gate);
    $check('same-stage amount revision exports a new bounded increment',
        $wire->posts===3&&$sum()===$repriced['deal_value_prediction']['value_rub']&&$sum()>$expected
        &&$repriced['semantic_prediction']['estimated_probability']===$stronger['semantic_prediction']['estimated_probability']);
    $before=$sum();$cheaper=modeledExpertWrapper($scope,$now+4,100000,true);modeledValueSave($p,$cheaper);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+4,$gate);
    $check('downward estimate appends no negative event or duplicate refund',$wire->posts===3&&$sum()===$before);
    $expensive=modeledExpertWrapper($scope,$now+5,2000000,true);modeledValueSave($p,$expensive);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+5,$gate);
    $check('a newly frozen commission uses the authorized 100000 cap and still multiplies by probability',
        $expensive['deal_value_prediction']['commission_estimate_rub']===140000
        &&$expensive['deal_value_prediction']['full_value_base_rub']===100000
        &&$sum()===$expensive['deal_value_prediction']['value_rub']&&$sum()>$before&&$sum()<100000);
    $held=modeledExpertWrapper($scope,$now+6,200000,false,true);
    $check('a payment statement followed by explicit frozen funds never awards a payment-stage increment',
        $held['deal_value_prediction']['payment_hold_observed']
        &&ModeledConversionValueContract::candidate($held,$scope,$now-500,$now+6)===null);
    $policy=modeledExpertWrapper($scope,$now+7,200000,false,true,'После оплаты средства будут заморожены.');
    $check('future hold policy is not reclassified as an existing payment hold',
        !$policy['deal_value_prediction']['payment_hold_observed']);
    $posted=modeledExpertWrapper($scope,$now+8,200000,false,true,null,true);
    $check('a later explicit posting can resolve a held-funds state regardless of input row ordering',
        !$posted['deal_value_prediction']['payment_hold_observed']
        &&ModeledConversionValueContract::candidate($posted,$scope,$now-500,$now+8)!==null);
    $bad=$stronger;$bad['deal_value_prediction']['value_rub']=24000;
    $check('modified monetary capsule cannot fall back to a legacy positive award',
        ModeledConversionValueContract::candidate($bad,$scope,$now-500,$now+6)===null);
}


function modeledValuePolicyTransitionAssertions(PDO $p,callable $check):void
{
    modeledValueSchema($p);$now=1793669000;$gate=static fn(string $chat):bool=>true;
    $scope=modeledValueBinding($p,850);$wire=new ModeledValueTestWire($now-86400);$api=$wire->api();$control=new ModeledConversionValueExport($p);
    $control->configure('create-goal',$api,$now-500);$control->configure('enable',$api,$now-500);
    $posts=$wire->posts;$gets=$wire->gets;
    $unknown=modeledUnknownPriceWrapper(modeledExpertWrapper($scope,$now,200000));modeledValueSave($p,$unknown);
    $created=$control->collect(ModeledConversionValueExport::settings($p),$now,$gate);
    $original=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $profileBefore=$p->query('SELECT profile_json FROM modeled_conversion_profile_versions ORDER BY id LIMIT 1')->fetchColumn();
    $check('unknown price reserves probability times the new 10000 provisional commission base',
        $unknown['deal_value_prediction']['version']==='early_deal_commission_7pct_v2'
        &&$unknown['deal_value_prediction']['deal_amount_rub']['point']===null
        &&$unknown['deal_value_prediction']['commission_estimate_rub']===null
        &&$unknown['deal_value_prediction']['full_value_base_rub']===10000
        &&$unknown['deal_value_prediction']['value_rub']===833&&$created===1
        &&count($original)===1&&(int)$original[0]['value_rub']===833);
    $known=modeledExpertWrapper($scope,$now+1,200000);modeledValueSave($p,$known);
    $created=$control->collect(ModeledConversionValueExport::settings($p),$now+1,$gate);
    $rows=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $check('a newly known price adds only 334 to the already reserved 833 at the same probability',
        $known['semantic_prediction']['estimated_probability']===$unknown['semantic_prediction']['estimated_probability']
        &&$known['deal_value_prediction']['value_rub']===1167&&$created===1&&count($rows)===2
        &&$rows[0]===$original[0]&&(int)$rows[1]['value_rub']===334
        &&$p->query('SELECT profile_json FROM modeled_conversion_profile_versions ORDER BY id LIMIT 1')->fetchColumn()===$profileBefore);
    $lost=modeledUnknownPriceWrapper(modeledExpertWrapper($scope,$now+2,200000,true));modeledValueSave($p,$lost);
    $check('losing a 14000 priced base uses the lower 10000 provisional base and cannot refund or inflate the reservation',
        $lost['deal_value_prediction']['full_value_base_rub']===10000&&$lost['deal_value_prediction']['value_rub']<1167
        &&$control->collect(ModeledConversionValueExport::settings($p),$now+2,$gate)===0
        &&$rows===$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
        &&$wire->posts===$posts&&$wire->gets===$gets);

    modeledValueSchema($p);$wire=new ModeledValueTestWire($now-86400);$api=$wire->api();$control=new ModeledConversionValueExport($p);
    $control->configure('create-goal',$api,$now-500);$control->configure('enable',$api,$now-500);
    $posts=$wire->posts;$gets=$wire->gets;
    $states=['pending','ready','uploading','accepted','processed','unknown','failed','expired','unmatched'];$unknownScope=null;
    $seed=$p->prepare("INSERT INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,platform,identity_source_key,value_rub,value_stage,value_contract,state,attempts,claimed_at_unix,upload_id,upload_status) VALUES('anytour',?,?,'98615635','anytour_value_6_v1','anytour_modeled_value_v1',?,'max','max_tracker',25000,'payment_report','semantic_stage_value_25000_v1',?,?,?,?,?)");
    foreach($states as$i=>$state){
        $id=851+$i;$scope=modeledValueBinding($p,$id);$attempted=!in_array($state,['pending','ready'],true);
        $receipt=in_array($state,['accepted','processed','unmatched'],true)?(string)(71000+$id):null;
        $status=$receipt===null?null:($state==='accepted'?'UPLOADED':($state==='unmatched'?'LINKAGE_FAILURE':'PROCESSED'));
        $seed->execute([$id,(string)(70000+$id),$now-300,$state,$attempted?1:0,$attempted?$now-300:null,$receipt,$status]);
        $legacy=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE conversation_id='.$id)->fetch(PDO::FETCH_ASSOC);
        // A posted payment signal exercises the full priced base: 1000000 * 7% = 70000.
        $paid=modeledExpertWrapper($scope,$now+$i,1000000,false,true,null,true);modeledValueSave($p,$paid);$profile=(string)$p->lastInsertId();
        $candidate=ModeledConversionValueContract::candidate($paid,$scope,$now-500,$now+$i);
        $created=$control->collect(ModeledConversionValueExport::settings($p),$now+$i,$gate);
        $rows=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE conversation_id='.$id.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $check('legacy 25000 in '.$state.' remains reserved and a new 70000 target adds exactly 45000',
            $candidate!==null&&$candidate['value']===70000&&$created===1&&count($rows)===2
            &&$rows[0]===$legacy&&(int)$rows[1]['value_rub']===45000
            &&$rows[1]['target']===ModeledConversionValueContract::predictionKey($profile)
            &&$rows[1]['transport_target']==='anytour_modeled_value_v1'&&$rows[1]['value_contract']==='semantic_stage_value_25000_v1');
        modeledValueSave($p,$paid);
        $check('a repeated new forecast and collector replay cannot duplicate the '.$state.' reservation',
            $control->collect(ModeledConversionValueExport::settings($p),$now+$i,$gate)===0
            &&$control->collect(ModeledConversionValueExport::settings($p),$now+$i,$gate)===0
            &&$rows===$p->query('SELECT * FROM max_channel_conversion_outbox WHERE conversation_id='.$id.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        if($state==='unknown')$unknownScope=$scope;
    }
    if($unknownScope===null)throw new RuntimeException('fixture_unknown_scope_missing');
    $id=(int)$unknownScope['conversation_id'];$before=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE conversation_id='.$id.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $capped=modeledExpertWrapper($unknownScope,$now+10,2000000,false,true,null,true);modeledValueSave($p,$capped);
    $created=$control->collect(ModeledConversionValueExport::settings($p),$now+10,$gate);
    $rows=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE conversation_id='.$id.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $check('a 140000 commission caps the total at 100000 and adds only 30000 after the mixed 70000 reservation',
        $capped['deal_value_prediction']['commission_estimate_rub']===140000
        &&$capped['deal_value_prediction']['full_value_base_rub']===100000&&$created===1&&count($rows)===3
        &&array_slice($rows,0,2)===$before&&(int)$rows[2]['value_rub']===30000
        &&array_sum(array_map(static fn(array $r):int=>(int)$r['value_rub'],$rows))===100000);
    $higher=modeledExpertWrapper($unknownScope,$now+11,3000000,false,true,null,true);modeledValueSave($p,$higher);
    $check('a higher tour price cannot add another row after the authorized cap or alter an UNKNOWN claim',
        $control->collect(ModeledConversionValueExport::settings($p),$now+11,$gate)===0
        &&$rows===$p->query('SELECT * FROM max_channel_conversion_outbox WHERE conversation_id='.$id.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
        &&$rows[0]['state']==='unknown'&&$wire->posts===$posts&&$wire->gets===$gets);
}


function modeledPriceLossAssertions(PDO $p,callable $check):void
{
    modeledValueSchema($p);$now=1793675000;$scope=modeledValueBinding($p,751);
    $wire=new ModeledValueTestWire($now-86400);$api=$wire->api();$control=new ModeledConversionValueExport($p);
    $control->configure('create-goal',$api,$now-500);$control->configure('enable',$api,$now-500);
    $lookup=new class{public function lookup(string $counter,array $users):array{$r=[];foreach($users as$u)$r[$u]=['external_user_id'=>$u,'client_id'=>'88000001','record_id'=>'1','observed_at_unix'=>1793675000];return$r;}};
    $gate=static fn(string $chat):bool=>true;
    $first=modeledExpertWrapper($scope,$now,100000);modeledValueSave($p,$first);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate);
    $sum=static fn():int=>(int)$p->query("SELECT COALESCE(SUM(value_rub),0) FROM max_channel_conversion_outbox WHERE conversation_id=751")->fetchColumn();
    $initial=$sum();$posts=$wire->posts;
    $lost=modeledUnknownPriceWrapper(modeledExpertWrapper($scope,$now+1,100000));modeledValueSave($p,$lost);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+1,$gate);
    $check('losing a known price cannot create a same probability uplift or another actual upload',
        $initial===583&&$sum()===$initial&&$wire->posts===$posts&&$lost['deal_value_prediction']['full_value_base_rub']===10000);
    $stronger=modeledUnknownPriceWrapper(modeledExpertWrapper($scope,$now+2,100000,true));modeledValueSave($p,$stronger);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+2,$gate);
    $expected=(int)round($stronger['semantic_prediction']['estimated_probability']*7000);
    if($sum()!==$expected)$control->collect(ModeledConversionValueExport::settings($p),$now+2,$gate);
    $detail=['posts'=>$wire->posts,'initial'=>$initial,'expected'=>$expected,'reserved'=>$sum(),'rows'=>$p->query('SELECT state,last_error,value_rub FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'audit'=>$p->query('SELECT action,applied_value_rub FROM modeled_conversion_price_audit ORDER BY outbox_id')->fetchAll(PDO::FETCH_ASSOC)];
    $check('genuine stronger meaning still exports its delta using the retained priced base '.json_encode($detail,JSON_THROW_ON_ERROR),
        $sum()===$expected&&$expected>$initial&&$wire->posts===$posts+1&&str_contains(end($wire->csv),','.($expected-$initial).',RUB'));
    $audit=$p->query("SELECT * FROM modeled_conversion_price_audit WHERE action='rebased'")->fetch(PDO::FETCH_ASSOC);
    $basis=json_decode($audit['basis_json'],true,32,JSON_THROW_ON_ERROR);
    $check('retained base is an auditable cap and never rewrites the frozen unknown tour estimate',
        $basis['applied_base_rub']===7000&&$basis['reference_deal_rub']===100000
        &&$basis['weight_basis']==='frozen_probability'&&$stronger['deal_value_prediction']['deal_amount_rub']['point']===null);
    $priced=modeledExpertWrapper($scope,$now+3,300000,true);modeledValueSave($p,$priced);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+3,$gate);
    $check('an explicitly known new price can legitimately raise the monetary base',
        $sum()===$priced['deal_value_prediction']['value_rub']&&$wire->posts===$posts+2);

    // Independent synthetic pre-fix pending row, lower-stage fallback after a known price.
    modeledValueSchema($p);$scope=modeledValueBinding($p,752);$control=new ModeledConversionValueExport($p);$wire=new ModeledValueTestWire($now-86400);$api=$wire->api();
    $control->configure('create-goal',$api,$now-500);$control->configure('enable',$api,$now-500);
    $first=modeledExpertWrapper($scope,$now,70000);modeledValueSave($p,$first);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate);
    $reserved=(int)$p->query('SELECT SUM(value_rub) FROM max_channel_conversion_outbox')->fetchColumn();
    $fallback=modeledUnknownPriceWrapper(modeledExpertWrapper($scope,$now+1,70000),true);modeledValueSave($p,$fallback);$profile=(string)$p->lastInsertId();
    $candidate=ModeledConversionValueContract::candidate($fallback,$scope,$now-500,$now+1);
    if(!$candidate)throw new RuntimeException('fixture_fallback_missing');
    $falseDelta=$candidate['value']-$reserved;
    $p->prepare("INSERT INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,platform,identity_source_key,value_rub,value_profile_id,value_stage,value_contract) VALUES('anytour',?,'70752','98615635',?,'anytour_modeled_value_v1',?,'max','max_tracker',?,?,?,'semantic_stage_value_25000_v1')")
        ->execute([752,ModeledConversionValueContract::predictionKey($profile),$now+1,$falseDelta,$profile,$candidate['stage']]);
    $bad=(string)$p->lastInsertId();$frozen=$p->query('SELECT id,profile_json FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$beforePosts=$wire->posts;
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+1,$gate);
    $row=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE id='.(int)$bad)->fetch(PDO::FETCH_ASSOC);
    $entry=$p->query('SELECT * FROM modeled_conversion_price_audit WHERE outbox_id='.(int)$bad)->fetch(PDO::FETCH_ASSOC);
    $repairDetail=['posts'=>$wire->posts,'before_posts'=>$beforePosts,'delta'=>$falseDelta,'reserved'=>$reserved,'row'=>array_intersect_key($row,array_flip(['state','value_rub','last_error','attempts'])),'audit'=>array_intersect_key($entry?:[],array_flip(['action','original_value_rub','applied_value_rub']))];
    $check('an unattempted false fallback reservation is voided before identity resolution and upload '.json_encode($repairDetail,JSON_THROW_ON_ERROR),
        $falseDelta>0&&(int)$row['value_rub']===0&&$row['state']==='failed'&&$row['last_error']==='price_loss_reconciled'
        &&$entry['action']==='voided'&&(int)$entry['original_value_rub']===$falseDelta
        &&$wire->posts===$beforePosts&&(int)$p->query('SELECT SUM(value_rub) FROM max_channel_conversion_outbox')->fetchColumn()===$reserved);
    $snapshot=json_encode($p->query('SELECT * FROM modeled_conversion_price_audit ORDER BY outbox_id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+2,$gate);
    $check('reconciliation is idempotent and preserves original forecasts and the immutable correction receipt',
        $snapshot===json_encode($p->query('SELECT * FROM modeled_conversion_price_audit ORDER BY outbox_id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR)
        &&$frozen===$p->query('SELECT id,profile_json FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
        &&$wire->posts===$beforePosts);
    $later=modeledUnknownPriceWrapper(modeledExpertWrapper($scope,$now+3,70000),true);modeledValueSave($p,$later);$profile=(string)$p->lastInsertId();
    $p->prepare("INSERT INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,platform,identity_source_key,value_rub,value_profile_id,value_stage,value_contract,state,claimed_at_unix,attempts,upload_id,upload_status) VALUES('anytour',?,'70752','98615635',?,'anytour_modeled_value_v1',?,'max','max_tracker',?,?,?,'semantic_stage_value_25000_v1','accepted',?,1,'901234','UPLOADED')")
        ->execute([752,ModeledConversionValueContract::predictionKey($profile),$now+3,$falseDelta,$profile,$candidate['stage'],$now+3]);
    $sent=(string)$p->lastInsertId();$guard=new ModeledConversionPriceContinuity($p);$report=$guard->reconcile($now+3,static fn(array $r):bool=>true);
    $row=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE id='.(int)$sent)->fetch(PDO::FETCH_ASSOC);
    $check('a claimed or API accepted amount is flagged for review without changing or resending its provider record',
        $row['state']==='accepted'&&(int)$row['value_rub']===$falseDelta&&$row['upload_id']==='901234'
        &&($report['actions']['review_required']['events']??0)===1&&$report['complete']&&!$guard->maySend($row));
}


/** The existing natural tick records only its own bounded identity decisions, without becoming a new resolver. */
function modeledValueIdentityEvidenceAssertions(PDO $p,callable $check):void
{
    modeledValueSchema($p);$now=1793679000;$namespace=ModeledConversionProfiles::assessmentNamespace();
    $p->exec("ALTER TABLE modeled_conversion_profile_versions ADD COLUMN assessment_namespace CHAR(64)");
    $p->exec("ALTER TABLE modeled_conversion_profile_versions ADD COLUMN assessment_kind VARCHAR(8)");
    $wire=new ModeledValueTestWire($now-86400);$api=$wire->api();$control=new ModeledConversionValueExport($p);
    $control->configure('create-goal',$api,$now-500);$control->configure('enable',$api,$now-500);
    $gate=static fn(string $chat):bool=>true;
    for($i=1;$i<=11;$i++)modeledValueSave($p,modeledExpertWrapper(modeledValueBinding($p,4600+$i),$now-100,200000));
    $p->prepare("UPDATE modeled_conversion_profile_versions SET assessment_namespace=?,assessment_kind='repair'")->execute([$namespace]);
    while($control->collect(ModeledConversionValueExport::settings($p),$now,$gate)>0){}
    $rows=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $check('identity fixture preserves more than five genuine increments whose frozen signal precedes lookup',
        count($rows)===11&&count(array_filter($rows,static fn(array $row):bool=>(int)$row['event_unix']===$now-100))===11);
    $records=[];$expected=[1,2,6,3,4,5,7,7,7,7,6];
    foreach($rows as$i=>$row){
        $records[$row['external_user_id']]=['external_user_id'=>$row['external_user_id'],
            'client_id'=>'880000'.$row['external_user_id'],'record_id'=>'17','observed_at_unix'=>(int)$row['event_unix']];
        if($i===0)$records[$row['external_user_id']]=null;
        if($i===1)$records[$row['external_user_id']]['observed_at_unix']=null;
        if($i===2)$records[$row['external_user_id']]['observed_at_unix']=$now+1;
        if($i===3)$records[$row['external_user_id']]['observed_at_unix']=(int)$row['event_unix']+1;
        if($i===4)$records[$row['external_user_id']]['observed_at_unix']=(int)$row['event_unix']-ModeledConversionAttributionWindow::MAX_SECONDS-1;
        if($i===5)$records[$row['external_user_id']]['observed_at_unix']=$now-ModeledConversionAttributionWindow::MAX_SECONDS-1;
        if($i===10)unset($records[$row['external_user_id']]); // Missing map entry is invalid, never confirmed absence.
    }
    $lookup=new class($records){
        public int $calls=0;public int $count=0;public bool $throw=false;
        public function __construct(public array $records){}
        public function lookup(string $counter,array $users):array{
            $this->calls++;$this->count=count($users);
            if($this->throw)throw new RuntimeException('tracker_transport_failed');
            return array_intersect_key($this->records,array_fill_keys($users,true));
        }
    };
    $wire->visitAt=(int)$rows[0]['event_unix']-1;
    $frozen=$p->query('SELECT id,profile_json FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $result=ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate);
    $receipt=static fn():array=>json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
    $saved=$receipt();$audit=ModeledConversionValueIdentityEvidence::normalize($saved['identity_resolution_evidence']??null,$namespace,$now);
    $check('one bounded canonical lookup resolves more than five pending rows while uploads remain capped at three',
        $lookup->calls===1&&$lookup->count===11&&$lookup->count<=MaxTrackerLookupProtocol::MAX_USERS
        &&$wire->posts===3&&$saved['resolved']===4
        &&(int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE state='ready'")->fetchColumn()===1);
    $check('actual successful resolver updates save a private identity evidence receipt',$audit!==null);
    $check('the private identity receipt covers all eleven bounded synthetic resolver updates',
        $audit['complete']&&count($audit['rows'])===11);
    $codes=array_column($audit['rows'],'reason_code');
    $check('resolver receipt reasons preserve absent missing future expired and resolved order '.json_encode(['actual'=>$codes,'expected'=>$expected],JSON_THROW_ON_ERROR),
        $codes===$expected);
    $check('the compact saved identity receipt stays within its fixed two KiB cap',
        strlen(json_encode($saved['identity_resolution_evidence']))<=2048);
    $check('the private evidence never changes public diagnostic output or immutable forecasts',
        $result===['status'=>'running','contract'=>ModeledConversionValueContract::VERSION]
        &&$frozen===$p->query('SELECT id,profile_json FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
        &&!str_contains(json_encode($saved['identity_resolution_evidence']),'external_user_id')
        &&!str_contains(json_encode($saved['identity_resolution_evidence']),'880000'));
    $first=$rows[0];$ref=ModeledConversionValueIdentityEvidence::reference($first,$namespace);
    $native=$first;foreach(['id','value_profile_id','conversation_id','event_unix','counter_id']as$key)$native[$key]=(int)$native[$key];
    $native['value_rub']=(float)$native['value_rub'];
    $check('opaque evidence refs are canonical across PDO strings and native MySQL JSON scalar types',
        $ref!==null&&$ref===ModeledConversionValueIdentityEvidence::reference($native,$namespace));
    $bound=true;
    foreach(['id','value_profile_id','conversation_id','external_user_id','event_unix','value_rub']as$key){
        $changed=$first;$changed[$key]=(string)((int)$changed[$key]+1);
        $bound=$bound&&ModeledConversionValueIdentityEvidence::reference($changed,$namespace)!==$ref;
    }
    foreach(['project_key','platform','counter_id','target','transport_target','value_contract']as$key){
        $changed=$first;$changed[$key]='foreign';
        $bound=$bound&&ModeledConversionValueIdentityEvidence::reference($changed,$namespace)===null;
    }
    $changed=$first;$changed['value_rub']=ModeledConversionValueContract::MAX_VALUE_RUB+1;
    $check('refs bind the complete immutable ledger scope and reject foreign keys and values above the existing cap',
        $bound&&ModeledConversionValueIdentityEvidence::reference($changed,$namespace)===null
        &&ModeledConversionValueIdentityEvidence::reference($first,str_repeat('b',64))!==$ref);
    $before=$saved['identity_resolution_evidence'];$posts=$wire->posts;$lookup->throw=true;
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+301,$gate);
    $after=$receipt();
    $check('transport failure never manufactures a fresh not-found observation or a duplicate upload',
        modeledValueFailureJsonEqual($after['identity_resolution_evidence']??null,$before)
        &&($after['last_recorded_failure']['phase']??null)==='resolve_identity'
        &&$after['errors']===1&&$wire->posts===$posts);
    $lookup->throw=false;
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+602,$gate);
    $later=$receipt();
    $check('the same natural lane resumes its remaining upload and retains earlier accepted reservations without duplicates',
        $wire->posts===$posts+1&&$later['errors']===0
        &&count($p->query('SELECT id FROM max_channel_conversion_outbox')->fetchAll(PDO::FETCH_ASSOC))===11
        &&$frozen===$p->query('SELECT id,profile_json FROM modeled_conversion_profile_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));

    $private=new ModeledConversionValueExport($p);
    $set=static function(object $o,string $name,mixed $value):void{(new ReflectionProperty($o,$name))->setValue($o,$value);};
    $set($private,'identityNamespace',$namespace);$refs=[];
    foreach($rows as$row)$refs[ModeledConversionValueIdentityEvidence::reference($row,$namespace)]=true;
    $set($private,'identityRefs',[$ref=>true]);$set($private,'identityCohortAvailable',true);$set($private,'identityComplete',true);
    $set($private,'identityObservations',[$ref=>[$ref,$now,1,null]]);
    $winning=$receipt();$winning['identity_resolution_evidence']['c']=false;
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")
        ->execute([json_encode($winning,JSON_THROW_ON_ERROR)]);
    $settingsBefore=ModeledConversionValueExport::settings($p);$receiptBefore=$receipt();
    (new ReflectionMethod($private,'persistTickReceipt'))->invoke($private,['errors'=>99,'metrika_report'=>['status'=>'unavailable']],null,$now);
    $settingsAfter=ModeledConversionValueExport::settings($p);$receiptAfter=$receipt();
    $check('a delayed smaller complete cohort cannot erase newer refs or upgrade incomplete evidence or overwrite counts and clocks',
        modeledValueFailureJsonEqual($receiptBefore,$receiptAfter)
        &&$settingsBefore['last_tick_unix']===$settingsAfter['last_tick_unix']
        &&$settingsBefore['last_report_success_unix']===$settingsAfter['last_report_success_unix']
        &&modeledValueFailureJsonEqual($settingsBefore['last_report_success_json'],$settingsAfter['last_report_success_json']));
    $near=$receiptAfter;unset($near['visit_gate_decisions']);
    $near['last_recorded_failure']=['tick_at_unix'=>$now+100,'phase'=>'upload','category'=>'api_rejected'];
    $newFailure=['tick_at_unix'=>$now+500,'phase'=>'collect_profiles','category'=>'processing_failed'];
    $measure=static function(array $value)use($p):int{
        $json=json_encode($value,JSON_THROW_ON_ERROR);
        if($p->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')return strlen($json);
        $q=$p->prepare('SELECT OCTET_LENGTH(CAST(? AS JSON))');$q->execute([$json]);return(int)$q->fetchColumn();
    };
    $near['padding']='';
    $expanded=$near;$expanded['last_recorded_failure']=$newFailure;
    // Measure the real stored JSON boundary rather than assuming native JSON whitespace.
    $near['padding']=str_repeat('x',8193-$measure($expanded));
    $expanded=$near;$expanded['last_recorded_failure']=$newFailure;
    $check('winning audit pressure fixture admits the old receipt but needs audit space for a newer delayed failure',
        $measure($near)<=8192&&$measure($expanded)===8193);
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")
        ->execute([json_encode($near,JSON_THROW_ON_ERROR)]);
    $clocksBefore=ModeledConversionValueExport::settings($p);
    (new ReflectionMethod($private,'persistTickReceipt'))->invoke($private,['errors'=>99,'metrika_report'=>['status'=>'unavailable']],$newFailure,$now+500);
    $held=$receipt();$heldAudit=$held['identity_resolution_evidence']??null;
    $nearWithoutAudit=$near;unset($nearWithoutAudit['identity_resolution_evidence']);
    $nearWithoutAudit['last_recorded_failure']=$newFailure;
    $heldWithoutAudit=$held;unset($heldWithoutAudit['identity_resolution_evidence']);
    $clocksAfter=ModeledConversionValueExport::settings($p);
    $check('a delayed real failure trims only the validated winning audit and keeps its membership prefix counts and clocks',
        $measure($held)<=8192&&modeledValueFailureJsonEqual($heldWithoutAudit,$nearWithoutAudit)
        &&is_array($heldAudit)&&$heldAudit['c']===false&&count($heldAudit['r'])<count($near['identity_resolution_evidence']['r'])
        &&$heldAudit['r']===array_slice($near['identity_resolution_evidence']['r'],0,count($heldAudit['r']))
        &&$clocksBefore['last_tick_unix']===$clocksAfter['last_tick_unix']
        &&$clocksBefore['last_report_success_unix']===$clocksAfter['last_report_success_unix']
        &&$clocksBefore['last_report_success_json']===$clocksAfter['last_report_success_json']);
    $base=['errors'=>2,'accepted'=>3,'processed'=>1,'metrika_report'=>['status'=>'unavailable'],
        'last_recorded_failure'=>$receiptAfter['last_recorded_failure'],'padding'=>str_repeat('x',7200)];
    $packed=(new ReflectionMethod($private,'withIdentityEvidence'))->invoke($private,$base,$later['identity_resolution_evidence'], $p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql');
    $check('audit overflow yields before original counts report and historical failure and keeps the whole receipt bounded',
        strlen(json_encode($packed))<=8192&&$packed['errors']===2&&$packed['accepted']===3&&$packed['processed']===1
        &&$packed['metrika_report']===$base['metrika_report']
        &&$packed['last_recorded_failure']===$base['last_recorded_failure']
        &&(($packed['identity_resolution_evidence']['c']??null)===false||($packed['identity_resolution_evidence_incomplete']??false)));

    $p->exec('ALTER TABLE modeled_conversion_profile_versions RENAME COLUMN assessment_namespace TO unavailable_namespace');
    $beforePosts=$wire->posts;
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+903,$gate);
    $check('optional audit metadata SQL failure is fail-soft for the normal financial tick',
        $receipt()['errors']===0&&$wire->posts===$beforePosts
        &&($receipt()['identity_resolution_evidence']['c']??null)===false);
}


/** Exact checkpoint proofs are finite GET observations, never new financial identities or admission rules. */
function modeledValueFiniteVisitAssertions(PDO $p,callable $check):void
{
    $now=ModeledConversionValueVisitEvidence::CHECKPOINT+600;$event=ModeledConversionValueVisitEvidence::CHECKPOINT-100;
    $namespace=ModeledConversionProfiles::assessmentNamespace();$gate=static fn(string $chat):bool=>true;
    $setup=static function(bool $selected=true,int $count=3)use($p,$now,$event,$namespace,$gate):array{
        modeledValueSchema($p);
        $p->exec("ALTER TABLE modeled_conversion_profile_versions ADD COLUMN assessment_namespace CHAR(64)");
        $p->exec("ALTER TABLE modeled_conversion_profile_versions ADD COLUMN assessment_kind VARCHAR(8)");
        $wire=new ModeledValueTestWire($now-86400);$control=new ModeledConversionValueExport($p);
        $control->configure('create-goal',$wire->api(),$now-1000);$control->configure('enable',$wire->api(),$now-1000);
        for($i=1;$i<=$count;$i++)modeledValueSave($p,modeledExpertWrapper(modeledValueBinding($p,5600+$i),$event,200000));
        $p->prepare("UPDATE modeled_conversion_profile_versions SET observed_at_unix=?,assessment_namespace=?,assessment_kind='repair'")
            ->execute([$selected?$event:ModeledConversionValueVisitEvidence::CHECKPOINT+1,$namespace]);
        while($control->collect(ModeledConversionValueExport::settings($p),$now,$gate)>0){}
        $rows=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $records=[];
        foreach($rows as$i=>$row)$records[$row['external_user_id']]=$i===2?null:[
            'external_user_id'=>$row['external_user_id'],'client_id'=>'880000'.$row['external_user_id'],
            'record_id'=>'90000000000000101','observed_at_unix'=>(int)$row['event_unix']-40*86400];
        return [$rows,$records,$wire];
    };
    $lookup=static fn(array $records):object=>new class($records){
        public int $calls=0;public int $users=0;
        public function __construct(public array $records){}
        public function lookup(string $counter,array $users):array{
            $this->calls++;$this->users=count($users);$out=[];foreach($users as$u)$out[$u]=$this->records[$u]??null;return$out;
        }
    };
    $api=static fn(array $reports):object=>new class($reports){
        public int $checks=0;public mixed $observe=null;
        public function __construct(public array $reports){}
        public function modeledValueReport(int $since,int $now):array{return ['status'=>'unavailable','goal_id'=>'678187097','currency'=>'RUB','fetched_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'reason'=>'fixture'];}
        public function modeledAttributionVisits(string $id,int $event,int $now,?callable $admit=null):array{
            $this->checks++;if(is_callable($this->observe))($this->observe)();
            return $this->reports[$id]??['status'=>'ok','visits'=>[]];
        }
    };
    $saved=static fn():array=>json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
    $financial=static fn():array=>$p->query('SELECT id,conversation_id,external_user_id,event_unix,value_rub,value_profile_id,value_stage,state,client_id,tracker_record_id,tracker_observed_unix,next_attempt_unix,attempts,claim_token,upload_id,upload_status,last_error FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

    [$rows,$records]=$setup(false);$ordinary=$lookup($records);$plain=$api([]);
    ModeledConversionValueExport::tick($p,$ordinary,$plain,$now,$gate);$baseline=$financial();
    [$rows,$records]=$setup();$source=$lookup($records);
    $first=$records[$rows[0]['external_user_id']]['client_id'];$second=$records[$rows[1]['external_user_id']]['client_id'];
    $provider=$api([$first=>['status'=>'ok','visits'=>[['started_at'=>gmdate('Y-m-d\TH:i:s\Z',$event-60),'visits'=>1,'source_class'=>'other_or_unknown']]],
        $second=>['status'=>'ok','visits'=>[]]]);
    $intentSeen=true;$outsideLock=true;
    $provider->observe=static function()use($p,$saved,$namespace,$now,&$intentSeen,&$outsideLock):void{
        $frame=ModeledConversionValueVisitEvidence::decode($saved()['finite_visit_evidence']??null,$namespace,$now);
        $intentSeen=$intentSeen&&$frame!==null&&count(array_filter($frame['r'],static fn(array $r):bool=>$r[2]===1))===1;
        $outsideLock=$outsideLock&&!$p->inTransaction();
    };
    $result=ModeledConversionValueExport::tick($p,$source,$provider,$now,$gate);
    $receipt=$saved();$frame=ModeledConversionValueVisitEvidence::decode($receipt['finite_visit_evidence']??null,$namespace,$now);
    $check('finite checkpoint evidence is present for exactly the three original bindings',$frame!==null&&count($frame['r'])===3);
    $check('old source identity with an actual recent visit is observed separately from a client with no prior visit',
        $frame['r'][0][8]<$event-10*86400&&$frame['r'][0][10]===1&&$frame['r'][0][11]===true&&$frame['r'][0][13]===$event-60
        &&$frame['r'][1][10]===1&&$frame['r'][1][11]===false&&$frame['r'][1][12]===3
        &&$frame['r'][2][5]===3&&$frame['r'][2][10]===0);
    $check('finite proof uses one shared MAX lookup at most two logical visit checks and durable intents outside a transaction',
        $source->calls===1&&$source->users===3&&$provider->checks===2&&$intentSeen&&$outsideLock);
    $check('finite GET proof never rewrites financial identity money queue attempts or diagnostic return',
        modeledValueFailureJsonEqual($financial(),$baseline)
        &&$result===['status'=>'running','contract'=>ModeledConversionValueContract::VERSION]);
    $public=ModeledConversionValueVisitEvidence::projection($frame,$namespace,$now);$json=json_encode($public,JSON_THROW_ON_ERROR);
    $check('finite private frame and public projection remain bounded and strip raw identity record and ledger fields',
        strlen(json_encode($frame))<=2048&&$public['complete']&&$public['provider_requests_in_reader']===0
        &&!str_contains($json,$first)&&!str_contains($json,'90000000000000101')&&!str_contains($json,'ledgerid')&&!str_contains($json,'client_id'));
    ModeledConversionValueExport::tick($p,$source,$provider,$now+301,$gate);
    $check('completed finite events never repeat their logical provider checks on later natural ticks',
        $provider->checks===2&&modeledValueFailureJsonEqual($saved()['finite_visit_evidence'],$frame));
    foreach([['other_or_unknown',false],['yandex_direct',true]]as[$kind,$allowed]){
        $window=ModeledConversionAttributionWindow::assess(['status'=>'ok','visits'=>[
            ['started_at'=>gmdate('Y-m-d\TH:i:s\Z',$event-4*86400),'visits'=>1,'source_class'=>$kind]]],$event,$now);
        $check('actual visits keep the existing three or ten day source rule '.$kind,$window['allowed']===$allowed);
    }
    $future=ModeledConversionAttributionWindow::assess(['status'=>'ok','visits'=>[
        ['started_at'=>gmdate('Y-m-d\TH:i:s\Z',$event+1),'visits'=>1,'source_class'=>'yandex_direct']]],$event,$now);
    $check('a future visit cannot create finite eligibility',$future['allowed']===false&&$future['visit_at']===null);

    // One already consumed intent survives a later reader/worker, with no second source/provider attempt for that event.
    [$rows,$records]=$setup();$owner=new ModeledConversionValueExport($p);
    (new ReflectionMethod($owner,'prepareFiniteEvidence'))->invoke($owner,$now,$gate);
    $pending=$saved()['finite_visit_evidence'];$intent=$pending['r'][0];$record=$records[$rows[0]['external_user_id']];
    $intent[2]=1;$intent[3]=$now;$intent[5]=1;$intent[6]=$record['client_id'];$intent[7]=$record['record_id'];$intent[8]=$record['observed_at_unix'];
    $intent[9]=ModeledConversionValueVisitEvidence::candidateReference($intent[0],$intent[6],$intent[7],$intent[8],'canonical_max_lookup');
    $firstClaim=(new ReflectionMethod($owner,'finiteSave'))->invoke($owner,$intent,0,null,$now);
    $duplicate=(new ReflectionMethod($owner,'finiteSave'))->invoke($owner,$intent,0,null,$now);
    $before=$financial();$observer=new ModeledConversionValueExport($p);
    (new ReflectionMethod($observer,'prepareFiniteEvidence'))->invoke($observer,$now,$gate);
    foreach(['finiteIdentities'=>$records,'finiteLookupReturned'=>true,'finiteLookupAttempted'=>true]as$key=>$value)(new ReflectionProperty($observer,$key))->setValue($observer,$value);
    $proof=$api([]);(new ReflectionMethod($observer,'runFiniteEvidence'))->invoke($observer,$proof,$now,$gate,static fn():int=>$now);
    $interrupted=$saved()['finite_visit_evidence'];$projection=ModeledConversionValueVisitEvidence::projection($interrupted,$namespace,$now);
    $check('an interrupted consumed intent is UNKNOWN and cannot be claimed or automatically read again',
        $firstClaim&&!$duplicate&&$interrupted['r'][0][2]===1&&$proof->checks===1
        &&$projection['automatic_attempts_finished']&&!$projection['complete']
        &&modeledValueFailureJsonEqual($before,$financial()));

    // Only trusted already accepted tracker bindings can supply a retained candidate; two clients remain ambiguous.
    [$rows,$records]=$setup();$user=$rows[2]['external_user_id'];
    $retained=$p->prepare("INSERT INTO max_channel_conversion_outbox(project_key,conversation_id,external_user_id,counter_id,target,transport_target,event_unix,platform,identity_source_key,client_id,tracker_record_id,tracker_observed_unix,state,upload_id,upload_status,value_api_accepted_at_unix,value_receipt_basis) VALUES('anytour',? ,?,'98615635',?,?,?,'max','max_tracker',?,? ,?,'accepted',?,'UPLOADED',?,'post_response')");
    for($i=1;$i<=2;$i++)$retained->execute([9900+$i,$user,'retained_'.$i,'retained_'.$i,$event-1000,'99000000'.$i,'90000000'.$i,$event-30*86400,'80000000'.$i,$now-10]);
    $owner=new ModeledConversionValueExport($p);
    [$code,$candidate]=(new ReflectionMethod($owner,'finiteRetainedSource'))->invoke($owner,$rows[2],$now);
    $check('two distinct trusted retained clients never choose an arbitrary source',$code===4&&$candidate===null);
    $p->exec("DELETE FROM max_channel_conversion_outbox WHERE target='retained_2'");
    [$code,$candidate]=(new ReflectionMethod($owner,'finiteRetainedSource'))->invoke($owner,$rows[2],$now);
    $check('a unique old retained tracker receipt is an identity source only and preserves its original timestamp',
        $code===2&&$candidate===['990000001','900000001',$event-30*86400]);
    foreach([
        ['project_key','ANYTOUR','anytour'],['platform','MAX','max'],['counter_id','98615635 ','98615635'],
        ['identity_source_key','MAX_TRACKER','max_tracker'],['state','ACCEPTED','accepted'],
        ['value_receipt_basis','POST_RESPONSE','post_response'],['upload_id',"800000001\n",'800000001']
    ]as[$field,$alias,$original]){
        $change=$p->prepare("UPDATE max_channel_conversion_outbox SET ".$field."=? WHERE target='retained_1'");
        $change->execute([$alias]);
        [$code,$candidate]=(new ReflectionMethod($owner,'finiteRetainedSource'))->invoke($owner,$rows[2],$now);
        $check('retained identity rejects noncanonical scope or provider-receipt bytes '.$field,$code===3&&$candidate===null);
        $change->execute([$original]);
    }
    $p->exec("UPDATE max_channel_conversion_outbox SET identity_source_key='untrusted' WHERE target='retained_1'");
    [$code,$candidate]=(new ReflectionMethod($owner,'finiteRetainedSource'))->invoke($owner,$rows[2],$now);
    $check('an untrusted retained identity is unavailable rather than a fabricated binding',$code===3&&$candidate===null);

    [$rows,$records]=$setup(true,4);$source=$lookup($records);$provider=$api([]);
    ModeledConversionValueExport::tick($p,$source,$provider,$now,$gate);
    $check('four checkpoint candidates fail closed without pinning or making finite provider requests',
        !array_key_exists('finite_visit_evidence',$saved())&&$provider->checks===0);
    [$rows,$records]=$setup();$source=$lookup($records);$provider=$api([]);
    $oversized=['finite_visit_evidence'=>$interrupted,'padding'=>str_repeat('x',8200)];
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")
        ->execute([json_encode($oversized,JSON_THROW_ON_ERROR)]);
    ModeledConversionValueExport::tick($p,$source,$provider,$now,$gate);
    $firstUnreadable=$saved();
    ModeledConversionValueExport::tick($p,$source,$provider,$now+301,$gate);
    $check('an oversized winning consumed receipt becomes a durable no-retry tombstone across two ordinary financial ticks',
        $provider->checks===0&&$firstUnreadable['finite_visit_evidence']===false&&$saved()['finite_visit_evidence']===false
        &&$firstUnreadable['errors']===0&&$saved()['errors']===0&&$source->calls===2
        &&strlen(ModeledConversionValueExport::settings($p)['last_receipt_json'])<=8192);
    [$rows,$records]=$setup();$source=$lookup($records);$provider=$api([]);
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")->execute(['["unreadable"]']);
    ModeledConversionValueExport::tick($p,$source,$provider,$now,$gate);
    ModeledConversionValueExport::tick($p,$source,$provider,$now+301,$gate);
    $check('a non-object saved receipt never manufactures authority to pin a new finite attempt',
        $provider->checks===0&&$saved()['finite_visit_evidence']===false&&$saved()['errors']===0);
    $contradiction=$frame;$contradiction['r'][0][13]=$event-4*86400;
    $check('saved finite window claims must agree with the existing source-specific window arithmetic',
        ModeledConversionValueVisitEvidence::decode($contradiction,$namespace,$now)===null);
    $full=$frame;$base=['errors'=>1,'accepted'=>2,'processed'=>3,'metrika_report'=>['status'=>'unavailable'],
        'last_recorded_failure'=>['tick_at_unix'=>$now,'phase'=>'upload','category'=>'api_rejected'],
        'price_continuity_audit'=>['complete'=>true,'corrections'=>[['fixture'=>str_repeat('x',7600)]]]];
    $packed=(new ReflectionMethod(new ModeledConversionValueExport($p),'withFiniteEvidence'))->invoke(new ModeledConversionValueExport($p),$base,['finite_visit_evidence'=>$full],$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql');
    $check('financial receipt pressure preserves counts failure and a permanent no-retry tombstone',
        strlen(json_encode($packed))<=8192&&array_key_exists('finite_visit_evidence',$packed)&&$packed['finite_visit_evidence']===false
        &&$packed['errors']===1&&$packed['accepted']===2&&$packed['processed']===3&&$packed['last_recorded_failure']===$base['last_recorded_failure']
        &&ModeledConversionValueVisitEvidence::projection(false,$namespace,$now)['reason']==='retained_budget_tombstone_no_automatic_retry');
}


/** The ordinary already-required report response retains only bounded technical scalars. */
function modeledValueReportResponseEvidenceAssertions(callable $check):void
{
    $now=ModeledConversionValueVisitEvidence::CHECKPOINT+600;$secret='PRIVATE_RESPONSE_BODY Bearer SYNTHETIC_REPORT_TOKEN clientID=999999999999999999';
    $valid=['query'=>['ids'=>[98615635],'metrics'=>['ym:s:goal678187097reaches','ym:s:goal678187097visits','ym:s:goal678187097convertedRUBRevenue'],'date2'=>'2026-11-11'],
        'totals'=>[2,1,833.0],'sampled'=>false];
    foreach([
        [429,0,'rate_limited','unavailable','report_api_unavailable',429,0],
        [503,0,'provider_unavailable','unavailable','report_api_unavailable',503,0],
        [400,0,'invalid_request','unavailable','report_api_unavailable',400,0],
        [403,0,'access_denied','forbidden','report_scope_or_counter_access',403,0],
        [302,0,'unexpected_http_status','unavailable','report_api_unavailable',302,0],
        [0,28,'transport_error','unavailable','transport_unavailable',null,28],
        [600,101,'unknown','unavailable','transport_unavailable',null,null],
        ['429 PRIVATE_RESPONSE_BODY',0,'unknown','unavailable','report_api_unavailable',null,0],
        [429.0,0,'unknown','unavailable','report_api_unavailable',null,0],
        [503,'28 SYNTHETIC_REPORT_TOKEN','unknown','unavailable','transport_unavailable',503,null],
        [200,0,'invalid_response','unavailable','invalid_report',200,0],
    ]as$i=>[$http,$error,$category,$status,$reason,$safeHttp,$safeError]){
        $calls=0;$api=new MetrikaOfflineClient('98615635','SYNTHETIC_REPORT_TOKEN',static function(string $method,string $url,mixed $body)use(&$calls,$http,$error,$secret):array{
            $calls++;if($method!=='GET'||!str_starts_with($url,'https://api-metrika.yandex.net/stat/v1/data?'))throw new RuntimeException('unexpected_request');
            return ['http'=>$http,'error'=>$error,'body'=>$secret];
        });
        $r=$api->modeledValueReport($now-600,$now);$json=json_encode($r,JSON_THROW_ON_ERROR);
        $check('ordinary report response stores strict bounded HTTP and transport evidence '.$i,
            $calls===1&&$r['status']===$status&&$r['reason']===$reason&&$r['failure_category']===$category
            &&$r['http_status']===$safeHttp&&$r['transport_error_code']===$safeError
            &&$r['goal_reaches']===null&&$r['goal_visits']===null&&$r['revenue_rub']===null);
        $check('ordinary failure evidence never returns a provider body URL token or customer identifier '.$i,
            !str_contains($json,'PRIVATE_RESPONSE_BODY')&&!str_contains($json,'SYNTHETIC_REPORT_TOKEN')
            &&!str_contains($json,'999999999999999999')&&!str_contains($json,'api-metrika.yandex.net'));
    }
    $calls=0;$api=new MetrikaOfflineClient('98615635','SYNTHETIC_REPORT_TOKEN',static function(string $method,string $url,mixed $body)use(&$calls,$valid):array{
        $calls++;return ['http'=>200,'error'=>0,'body'=>json_encode($valid,JSON_THROW_ON_ERROR)];
    });
    $r=$api->modeledValueReport($now-600,$now);
    $check('successful ordinary report keeps unchanged goal totals with no failure category or extra request',
        $calls===1&&$r['status']==='ok'&&$r['goal_reaches']===2&&$r['goal_visits']===1&&$r['revenue_rub']===833.0
        &&$r['http_status']===200&&$r['transport_error_code']===0&&$r['failure_category']===null);
}


/** A forward finite attempt is gated by this healthy ordinary response and never rewrites the original result. */
function modeledValueVisitRecoveryAssertions(PDO $p,callable $check):void
{
    $now=ModeledConversionValueVisitEvidence::CHECKPOINT+2400;$event=ModeledConversionValueVisitEvidence::CHECKPOINT-100;
    $oldAt=ModeledConversionValueVisitEvidence::CHECKPOINT+600;$ns=ModeledConversionProfiles::assessmentNamespace();$gate=static fn(string $chat):bool=>true;
    $healthy=static fn(int $at):array=>['status'=>'ok','goal_id'=>'678187097','currency'=>'RUB','fetched_at'=>gmdate('Y-m-d\TH:i:s\Z',$at),
        'date1'=>'2026-10-20','date2'=>'2026-11-11','goal_reaches'=>2,'goal_visits'=>1,'revenue_rub'=>833.0,'sampled'=>false,
        'http_status'=>200,'transport_error_code'=>0,'failure_category'=>null];
    $setup=static function(bool $priorHealthy=true)use($p,$now,$event,$oldAt,$ns,$gate,$healthy):array{
        modeledValueSchema($p);$p->exec("ALTER TABLE modeled_conversion_profile_versions ADD COLUMN assessment_namespace CHAR(64)");
        $p->exec("ALTER TABLE modeled_conversion_profile_versions ADD COLUMN assessment_kind VARCHAR(8)");
        $wire=new ModeledValueTestWire($now-86400);$control=new ModeledConversionValueExport($p);
        $control->configure('create-goal',$wire->api(),$now-4000);$control->configure('enable',$wire->api(),$now-4000);
        for($i=1;$i<=3;$i++)modeledValueSave($p,modeledExpertWrapper(modeledValueBinding($p,6600+$i),$event,200000));
        $p->prepare("UPDATE modeled_conversion_profile_versions SET observed_at_unix=?,assessment_namespace=?,assessment_kind='repair'")->execute([$event,$ns]);
        while($control->collect(ModeledConversionValueExport::settings($p),$now,$gate)>0){}
        $rows=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $original=['v'=>ModeledConversionValueVisitEvidence::VERSION,'n'=>$ns,'b'=>ModeledConversionValueVisitEvidence::CHECKPOINT,'r'=>[]];$records=[];
        foreach($rows as$i=>$row){
            $ref=ModeledConversionValueIdentityEvidence::reference($row,$ns);
            if($i<2){
                $record=['external_user_id'=>$row['external_user_id'],'client_id'=>'880000'.$row['external_user_id'],'record_id'=>'90000000000000301','observed_at_unix'=>$event-40*86400];
                $hash=ModeledConversionValueVisitEvidence::candidateReference($ref,$record['client_id'],$record['record_id'],$record['observed_at_unix'],'canonical_max_lookup');
                $original['r'][]=[$ref,(string)$row['id'],2,$oldAt-1,$oldAt,1,$record['client_id'],$record['record_id'],$record['observed_at_unix'],$hash,2,false,4,null,0,$event];
                $records[$row['external_user_id']]=$record;
            }else{$original['r'][]=[$ref,(string)$row['id'],2,null,$oldAt,3,null,null,null,null,0,null,0,null,0,$event];$records[$row['external_user_id']]=null;}
        }
        $receipt=['errors'=>0,'metrika_report'=>$priorHealthy?$healthy($now-300):['status'=>'unavailable','http_status'=>503,'transport_error_code'=>0,'failure_category'=>'provider_unavailable'],
            'finite_visit_evidence'=>$original];
        $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_tick_unix=?,last_receipt_json=? WHERE project_key='anytour'")->execute([$now-300,json_encode($receipt,JSON_THROW_ON_ERROR)]);
        $stored=json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
        return [$rows,$records,$stored['finite_visit_evidence'],$control];
    };
    $lookup=static fn(array $records):object=>new class($records){
        public int $calls=0;public array $queried=[];
        public function __construct(public array $records){}
        public function lookup(string $counter,array $users):array{$this->calls++;$this->queried=$users;return array_intersect_key($this->records,array_fill_keys($users,true));}
    };
    $api=static fn():object=>new class($healthy,$event){
        public int $checks=0;public int $reports=0;public bool $ok=true;public int $offset=0;public array $responses=[];public mixed $observe=null;
        public function __construct(private $healthy,private int $event){}
        public function modeledValueReport(int $since,int $now):array{
            $this->reports++;return $this->ok?($this->healthy)($now+$this->offset):
                ['status'=>'unavailable','goal_id'=>'678187097','currency'=>'RUB','fetched_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'sampled'=>null,'http_status'=>503,'transport_error_code'=>0,'failure_category'=>'provider_unavailable'];
        }
        public function modeledAttributionVisits(string $client,int $event,int $now,?callable $admit=null):array{
            $this->checks++;if(is_callable($this->observe))($this->observe)();
            return $this->responses[$client]??['status'=>'ok','visits'=>[['started_at'=>gmdate('Y-m-d\TH:i:s\Z',$this->event-60),'visits'=>1,'source_class'=>'other_or_unknown']]];
        }
    };
    $saved=static fn():array=>json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
    $financial=static fn():array=>$p->query('SELECT id,conversation_id,external_user_id,event_unix,value_rub,value_profile_id,value_stage,state,client_id,tracker_record_id,tracker_observed_unix,next_attempt_unix,attempts,claim_token,upload_id,upload_status,last_error FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    [$rows,$records,$old]=$setup(false);$source=$lookup($records);$provider=$api();
    ModeledConversionValueExport::tick($p,$source,$provider,$now,$gate);$baseline=$financial();
    $check('a first healthy ordinary response after a failed prior report does not invent reserved recovery authority',$provider->checks===0);
    [$rows,$records,$old]=$setup();$source=$lookup($records);$provider=$api();$provider->ok=false;
    ModeledConversionValueExport::tick($p,$source,$provider,$now,$gate);
    $pending=$saved()['finite_visit_recovery']??null;
    $check('a cached healthy report cannot authorize recovery when this actual ordinary response is 503',
        $provider->checks===0&&is_array($pending)&&array_column($pending['r'],2)===[0,0]
        &&$saved()['finite_visit_evidence']===$old&&$saved()['errors']===0&&$financial()===$baseline);
    $provider->ok=true;ModeledConversionValueExport::tick($p,$source,$provider,$now+301,$gate);
    $check('a previous failed ordinary tick still prevents reservation on the first subsequent healthy tick',$provider->checks===0);
    $intentSeen=true;$outside=true;
    $provider->observe=static function()use($p,$saved,$ns,$now,$old,&$intentSeen,&$outside):void{
        $r=$saved();$journal=ModeledConversionValueVisitRecovery::decode($r['finite_visit_recovery']??null,$old,$ns,$now+602);
        $intentSeen=$intentSeen&&$journal!==null&&count(array_filter($journal['r'],static fn(array $row):bool=>$row[2]===1))===1;
        $outside=$outside&&!$p->inTransaction();
    };
    ModeledConversionValueExport::tick($p,$source,$provider,$now+602,$gate);
    $done=$saved()['finite_visit_recovery'];$projection=ModeledConversionValueVisitRecovery::projection($done,$old,$ns,$now+602);
    $check('two known completed unavailable cases receive one forward attempt only after a fresh healthy ordinary response',
        $provider->checks===2&&$intentSeen&&$outside&&$projection['complete']&&$projection['checks_consumed']===2
        &&array_column($done['r'],2)===[2,2]&&$saved()['finite_visit_evidence']===$old);
    ModeledConversionValueExport::tick($p,$source,$provider,$now+903,$gate);
    $check('observed recovery outcomes are retained and never automatically replayed',$provider->checks===2&&$saved()['finite_visit_recovery']===$done);
    $order=['r'=>$old['r'],'b'=>$old['b'],'n'=>$old['n'],'v'=>$old['v']];
    $check('the original frame digest is independent of native JSON object key order',
        ModeledConversionValueVisitRecovery::originalDigest($order,$ns,$now)===ModeledConversionValueVisitRecovery::originalDigest($old,$ns,$now));
    $missing=ModeledConversionValueVisitRecovery::projection(null,$old,$ns,$now);
    $check('unrecorded eligible recovery is explicit pending with zero consumed checks rather than a saved attempt',
        $missing['available']&&!$missing['recorded']&&$missing['checks_consumed']===0&&count($missing['rows'])===2);
    foreach(['http_status'=>503,'transport_error_code'=>28,'sampled'=>true]as$key=>$value){
        $bad=$healthy($now);$bad[$key]=$value;$check('current ordinary health fails closed for '.$key,!ModeledConversionValueVisitRecovery::healthyOrdinary($bad,$now));
    }
    $check('stale health can be a reservation hint only within its bounded age and never this-tick authority',
        ModeledConversionValueVisitRecovery::healthyOrdinary($healthy($now-300),$now,600)
        &&!ModeledConversionValueVisitRecovery::healthyOrdinary($healthy($now-300),$now)
        &&!ModeledConversionValueVisitRecovery::healthyOrdinary($healthy($now-601),$now,600));

    [$rows,$records,$old]=$setup();$source=$lookup($records);$provider=$api();$first=$records[$rows[0]['external_user_id']]['client_id'];
    $provider->responses[$first]=['status'=>'unavailable','visits'=>[]];
    ModeledConversionValueExport::tick($p,$source,$provider,$now,$gate);
    $failed=$saved()['finite_visit_recovery'];
    $check('the first unavailable forward check consumes itself and leaves the second pending without another provider call',
        $provider->checks===1&&$failed['r'][0][2]===2&&$failed['r'][0][5]===2&&$failed['r'][1][2]===0&&$saved()['finite_visit_evidence']===$old);
    ModeledConversionValueExport::tick($p,$source,$provider,$now+301,$gate);
    $check('the remaining pending case can cooperate with a later healthy natural tick without replaying the consumed failure',
        $provider->checks===2&&array_column($saved()['finite_visit_recovery']['r'],2)===[2,2]&&$saved()['finite_visit_evidence']===$old);

    [$rows,$records,$old]=$setup();unset($records[$rows[1]['external_user_id']]);$source=$lookup($records);$provider=$api();
    ModeledConversionValueExport::tick($p,$source,$provider,$now,$gate);
    $check('a missing canonical response key is not a not-found result or a changed binding',
        $provider->checks===1&&$saved()['finite_visit_recovery']['r'][1][2]===0&&$saved()['finite_visit_recovery']['r'][1][10]===0);
    [$rows,$records,$old]=$setup();$records[$rows[1]['external_user_id']]['record_id']='90000000000000302';$source=$lookup($records);$provider=$api();
    ModeledConversionValueExport::tick($p,$source,$provider,$now,$gate);
    $check('a genuinely different canonical candidate receives no recovery visit request and never rewrites the old candidate',
        $provider->checks===1&&$saved()['finite_visit_recovery']['r'][1][2]===3&&$saved()['finite_visit_recovery']['r'][1][10]===3
        &&$saved()['finite_visit_evidence']===$old);

    [$rows,$records,$old]=$setup();$shared=new ModeledConversionValueExport($p);
    (new ReflectionMethod($shared,'prepareFiniteEvidence'))->invoke($shared,$now,$gate);
    (new ReflectionMethod($shared,'prepareRecoveryEvidence'))->invoke($shared,$now);
    foreach(['finiteIdentities'=>$records,'finiteLookupReturned'=>true,'finiteChecksUsed'=>1]as$key=>$value)(new ReflectionProperty($shared,$key))->setValue($shared,$value);
    $provider=$api();(new ReflectionMethod($shared,'runRecoveryEvidence'))->invoke($shared,$provider,$now,$gate,static fn():int=>$now,$healthy($now));
    $check('one already consumed original logical check leaves at most one forward check within the same shared two-check budget',
        $provider->checks===1&&array_column($saved()['finite_visit_recovery']['r'],2)===[2,0]&&$saved()['finite_visit_evidence']===$old);
    $unknown=$old;$unknown['r'][0][2]=1;$unknown['r'][0][4]=null;$unknown['r'][0][10]=0;$unknown['r'][0][11]=null;$unknown['r'][0][12]=0;
    $check('consumed UNKNOWN original events are never eligible for the forward protocol',
        ModeledConversionValueVisitRecovery::create($unknown,$ns,$now)===null);

    [$rows,$records,$old]=$setup();$owner=new ModeledConversionValueExport($p);
    (new ReflectionMethod($owner,'prepareFiniteEvidence'))->invoke($owner,$now,$gate);
    (new ReflectionMethod($owner,'prepareRecoveryEvidence'))->invoke($owner,$now);
    $journal=$saved()['finite_visit_recovery'];$intent=$journal['r'][0];$intent[2]=1;$intent[3]=$now;$intent[10]=1;
    $firstClaim=(new ReflectionMethod($owner,'recoverySave'))->invoke($owner,$intent,0,$now);
    $duplicate=(new ReflectionMethod($owner,'recoverySave'))->invoke($owner,$intent,0,$now);
    $other=new ModeledConversionValueExport($p);(new ReflectionMethod($other,'prepareFiniteEvidence'))->invoke($other,$now,$gate);
    (new ReflectionMethod($other,'prepareRecoveryEvidence'))->invoke($other,$now);
    foreach(['finiteIdentities'=>$records,'finiteLookupReturned'=>true]as$key=>$value)(new ReflectionProperty($other,$key))->setValue($other,$value);
    $before=$financial();$provider=$api();
    (new ReflectionMethod($other,'runRecoveryEvidence'))->invoke($other,$provider,$now,$gate,static fn():int=>$now,$healthy($now));
    $held=$saved()['finite_visit_recovery'];
    $check('an interrupted new intent is consumed UNKNOWN while another case may finish without financial mutation',
        $firstClaim&&!$duplicate&&$held['r'][0][2]===1&&$provider->checks===1&&$held['r'][1][2]===2
        &&$financial()===$before&&$saved()['finite_visit_evidence']===$old);
    $private=json_encode(ModeledConversionValueVisitRecovery::projection($held,$old,$ns,$now),JSON_THROW_ON_ERROR);
    $check('the forward journal and its projection expose no source identity or private record bytes',
        strlen(json_encode($held))<=2048&&!str_contains($private,'880000'.$rows[0]['external_user_id'])
        &&!str_contains($private,'90000000000000301')&&!str_contains($private,'client_id'));
    $measure=static function(array $value)use($p):int{
        $json=json_encode($value,JSON_THROW_ON_ERROR);if($p->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')return strlen($json);
        $q=$p->prepare('SELECT OCTET_LENGTH(CAST(? AS JSON))');$q->execute([$json]);return(int)$q->fetchColumn();
    };
    foreach([$journal,$held]as$capped){
        $base=['errors'=>1,'accepted'=>2,'processed'=>3,'metrika_report'=>$healthy($now),
            'last_recorded_failure'=>['tick_at_unix'=>$now-10,'phase'=>'upload','category'=>'api_rejected'],'finite_visit_evidence'=>$old,'padding'=>''];
        $base['padding']=str_repeat('x',8000-$measure($base));
        $packed=(new ReflectionMethod($owner,'withRecoveryEvidence'))->invoke($owner,$base,['finite_visit_recovery'=>$capped],$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql');
        $check('pending or consumed recovery overflow yields only its own permanent tombstone and preserves valid original evidence and financial history',
            $measure($packed)<=8192&&$packed['finite_visit_recovery']===false&&$packed['finite_visit_evidence']===$old
            &&$packed['errors']===1&&$packed['accepted']===2&&$packed['processed']===3&&$packed['last_recorded_failure']===$base['last_recorded_failure']);
    }

    // A delayed writer must not replace a newer journal with its old in-memory pending membership.
    $winner=$saved();unset($winner['visit_gate_decisions']);$winner['last_recorded_failure']=['tick_at_unix'=>$now+10,'phase'=>'upload','category'=>'api_rejected'];
    $newFailure=['tick_at_unix'=>$now+300,'phase'=>'collect_profiles','category'=>'processing_failed'];
    $winner['padding']='';$expanded=$winner;$expanded['last_recorded_failure']=$newFailure;
    $winner['padding']=str_repeat('x',8193-$measure($expanded));
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_tick_unix=?,last_receipt_json=? WHERE project_key='anytour'")
        ->execute([$now+900,json_encode($winner,JSON_THROW_ON_ERROR)]);
    $beforeSettings=ModeledConversionValueExport::settings($p);
    (new ReflectionMethod($owner,'persistTickReceipt'))->invoke($owner,['errors'=>99,'metrika_report'=>['status'=>'unavailable']],null,$now+300);
    $check('a delayed unchanged tick preserves the newer original and recovery journal byte-for-byte',
        ModeledConversionValueExport::settings($p)===$beforeSettings);
    (new ReflectionMethod($owner,'persistTickReceipt'))->invoke($owner,['errors'=>99,'metrika_report'=>['status'=>'unavailable']],$newFailure,$now+300);
    $after=$saved();$beforeWithout=$winner;unset($beforeWithout['finite_visit_recovery']);$beforeWithout['last_recorded_failure']=$newFailure;
    $afterWithout=$after;unset($afterWithout['finite_visit_recovery']);
    $check('a delayed new failure yields only the winning new journal while retaining original proof counts and clocks',
        $measure($after)<=8192&&$after['finite_visit_recovery']===false&&$after['finite_visit_evidence']===$old
        &&modeledValueFailureJsonEqual($afterWithout,$beforeWithout)
        &&ModeledConversionValueExport::settings($p)['last_tick_unix']===$beforeSettings['last_tick_unix']);

    [$rows,$records,$old]=$setup();$source=$lookup($records);$provider=$api();
    $oversized=['finite_visit_evidence'=>$old,'finite_visit_recovery'=>$held,'padding'=>str_repeat('x',8200)];
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")->execute([json_encode($oversized,JSON_THROW_ON_ERROR)]);
    ModeledConversionValueExport::tick($p,$source,$provider,$now,$gate);
    ModeledConversionValueExport::tick($p,$source,$provider,$now+301,$gate);
    $check('an unreadable consumed receipt durably tombstones both scopes across ordinary financial overwrites without any replay',
        $provider->checks===0&&$saved()['finite_visit_evidence']===false&&$saved()['finite_visit_recovery']===false&&$saved()['errors']===0);

    // Fixed reserved identities must not consume the ordinary fresh-source portion twice.
    foreach([2,3]as$reserve){
        [$rows,$records,$old,$control]=$setup();
        if($reserve===3){
            foreach($old['r']as$i=>$row)$old['r'][$i]=[$row[0],$row[1],0,null,null,0,null,null,null,null,0,null,0,null,0,$row[15]];
            $r=$saved();$r['finite_visit_evidence']=$old;
            $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")->execute([json_encode($r,JSON_THROW_ON_ERROR)]);
        }
        for($i=1;$i<=24;$i++){
            $scope=modeledValueBinding($p,6700+$i);modeledValueSave($p,modeledExpertWrapper($scope,$now-1,200000));
            $records[(string)(70000+6700+$i)]=null;
        }
        while($control->collect(ModeledConversionValueExport::settings($p),$now,$gate)>0){}
        $p->prepare("UPDATE max_channel_conversion_outbox SET next_attempt_unix=? WHERE id IN (?,?,?)")
            ->execute([$now+900,...array_column($rows,'id')]);
        $reservedBefore=$p->query('SELECT id,state,next_attempt_unix,last_error,attempts,client_id FROM max_channel_conversion_outbox WHERE id<='.($reserve).' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $source=$lookup($records);$provider=$api();$provider->responses[$records[$rows[0]['external_user_id']]['client_id']]=['status'=>'unavailable','visits'=>[]];
        ModeledConversionValueExport::tick($p,$source,$provider,$now,$gate);
        $freshDone=(int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE conversation_id>=6701 AND last_error='identity_not_found'")->fetchColumn();
        $reservedAfter=$p->query('SELECT id,state,next_attempt_unix,last_error,attempts,client_id FROM max_channel_conversion_outbox WHERE id<='.($reserve).' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $check('reserved '.$reserve.' exact identities preserve real fresh source capacity and never financially resolve a non-due reserved row',
            $source->calls===1&&count($source->queried)===25&&$freshDone===25-$reserve&&$reservedBefore===$reservedAfter);
    }
}

/** Same preclaim assessments explain a hold without changing delivery or provider traffic. */
function modeledValueVisitGateDecisionAssertions(PDO $p,callable $check):void
{
    $now=1793660000;$gate=static fn(string $chat):bool=>true;$held=[];
    $saved=static fn():array=>json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
    foreach(['no_prior_visit','visit_report_unavailable','outside_current_window','within_current_window']as$reason){
        modeledValueSchema($p);$wire=new ModeledValueTestWire($now-86400);$api=$wire->api();$control=new ModeledConversionValueExport($p);
        $control->configure('create-goal',$api,$now-20);$control->configure('enable',$api,$now-10);
        $wire->visitEmpty=$reason==='no_prior_visit';$wire->visitAvailable=$reason!=='visit_report_unavailable';
        $wire->visitAt=$now-($reason==='outside_current_window'?4*86400:60);
        modeledValueSave($p,modeledValueWrapper(modeledValueBinding($p,891),$now));
        $lookup=new ModeledValueTestLookup();$lookup->observedAt=$now-60;
        $result=ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate);
        $receipt=$saved();$audit=$receipt['visit_gate_decisions']??null;
        $row=$p->query('SELECT state,last_error,attempts,claim_token,upload_id FROM max_channel_conversion_outbox LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $check('same actual preclaim assessment records its closed reason '.$reason,
            is_array($audit)&&$audit['v']===2&&$audit['checked_at_unix']===$now&&$audit['attempts']===1
            &&array_sum($audit['reason_counts'])===1&&$audit['reason_counts'][$reason]===1
            &&array_sum(array_column($audit['failures'],4))===$audit['reason_counts']['visit_report_unavailable']
            &&strlen(json_encode(['visit_gate_decisions'=>$audit],JSON_THROW_ON_ERROR))<=512
            &&$lookup->calls===1&&array_keys($result)===['status','contract']);
        if(in_array($reason,['no_prior_visit','visit_report_unavailable'],true)){
            $held[$reason]=$row['last_error'];
            $check('unverified '.$reason.' keeps the financial hold unclaimed without a POST',
                $row['state']==='ready'&&$row['last_error']==='metrika_visit_unverified'&&(int)$row['attempts']===0
                &&empty($row['claim_token'])&&empty($row['upload_id'])&&$wire->posts===0);
        }elseif($reason==='within_current_window'){
            $check('one accepted send retains exactly one allowed preclaim decision',$wire->posts===1&&$row['state']==='accepted');
        }else $check('outside-window evidence preserves the existing hold',$wire->posts===0&&$row['last_error']==='metrika_visit_window');
    }
    $check('identical legacy hold errors now retain distinct actual no-prior and unavailable decision counts',
        $held['no_prior_visit']===$held['visit_report_unavailable']);

    modeledValueSchema($p);$wire=new ModeledValueTestWire($now-86400);$api=$wire->api();$owner=new ModeledConversionValueExport($p);
    $owner->configure('create-goal',$api,$now-20);$owner->configure('enable',$api,$now-10);
    ModeledConversionValueExport::tick($p,new ModeledValueTestLookup(),$api,$now,$gate);
    $empty=$saved()['visit_gate_decisions'];
    $check('zero attempts records no assessment rather than an absent visit',
        $empty['attempts']===0&&array_sum($empty['reason_counts'])===0&&$wire->visitReads===0&&$wire->posts===0);
    for($i=1;$i<=4;$i++)modeledValueSave($p,modeledValueWrapper(modeledValueBinding($p,891+$i),$now+1));
    $wire->visitEmpty=true;$lookup=new ModeledValueTestLookup();$lookup->observedAt=$now;
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+1,$gate);
    $three=$saved()['visit_gate_decisions'];
    $check('the existing three-ready budget bounds same-tick decision counters',
        $three['attempts']===3&&$three['reason_counts']['no_prior_visit']===3&&array_sum($three['reason_counts'])===3
        &&$wire->visitReads===3&&$wire->posts===0);

    $wire->visitEmpty=false;$wire->visitAvailable=false;
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+302,$gate);
    $unavailable=$saved()['visit_gate_decisions'];
    $check('three same mandatory unavailable responses aggregate into one bounded sparse tuple',
        $unavailable['attempts']===3&&$unavailable['reason_counts']['visit_report_unavailable']===3
        &&$unavailable['failures']===[[5,503,0,0,3]]&&$wire->visitReads===6&&$wire->posts===0);

    $measure=static function(array $value)use($p):int{
        $json=json_encode($value,JSON_THROW_ON_ERROR);if($p->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')return strlen($json);
        $q=$p->prepare('SELECT OCTET_LENGTH(CAST(? AS JSON))');$q->execute([$json]);return(int)$q->fetchColumn();
    };
    $persist=new ReflectionMethod($owner,'persistTickReceipt');
    $old=['v'=>'immutable_saved_fixture','r'=>[['original','preserved']]];
    $recovery=['v'=>'independent_saved_fixture','r'=>[['forward','preserved']]];
    $failure=['tick_at_unix'=>$now-2,'phase'=>'upload','category'=>'api_rejected'];
    $base=['collected'=>4,'resolved'=>2,'accepted'=>1,'processed'=>3,'unknown'=>0,'errors'=>1,
        'metrika_report'=>['status'=>'unavailable','fetched_at'=>gmdate('Y-m-d\TH:i:s\Z',$now)],
        'last_recorded_failure'=>$failure,'identity_resolution_evidence_incomplete'=>true,
        'finite_visit_evidence'=>$old,'finite_visit_recovery'=>$recovery,'padding'=>''];
    $base['padding']=str_repeat('x',8050-$measure($base));
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_tick_unix=?,last_receipt_json=? WHERE project_key='anytour'")
        ->execute([$now-1,json_encode($base,JSON_THROW_ON_ERROR)]);
    $incoming=$base;$incoming['visit_gate_decisions']=$three;$incoming['visit_gate_decisions']['checked_at_unix']=$now;
    $persist->invoke($owner,$incoming,null,$now);
    $capped=$saved();
    $check('new optional counters yield before existing proof counters failure and report clocks near the native receipt cap',
        $measure($capped)<=8192&&!array_key_exists('visit_gate_decisions',$capped)
        &&modeledValueFailureJsonEqual($capped['finite_visit_evidence'],$old)
        &&modeledValueFailureJsonEqual($capped['finite_visit_recovery'],$recovery)
        &&$capped['accepted']===1&&$capped['processed']===3&&$capped['errors']===1
        &&modeledValueFailureJsonEqual($capped['last_recorded_failure'],$failure));

    $winner=$base;$winner['padding']='';$winner['visit_gate_decisions']=$three;$winner['visit_gate_decisions']['checked_at_unix']=$now+900;
    $winner['padding']=str_repeat('x',8150-$measure($winner));
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_tick_unix=?,last_receipt_json=? WHERE project_key='anytour'")
        ->execute([$now+900,json_encode($winner,JSON_THROW_ON_ERROR)]);
    $before=ModeledConversionValueExport::settings($p);
    $persist->invoke($owner,['errors'=>99,'visit_gate_decisions'=>$empty],null,$now+300);
    $check('a delayed unchanged tick preserves the newer decision audit and receipt bytes',
        ModeledConversionValueExport::settings($p)===$before);
    $newFailure=['tick_at_unix'=>$now+300,'phase'=>'collect_profiles','category'=>'processing_failed'];
    $persist->invoke($owner,['errors'=>99,'visit_gate_decisions'=>$empty],$newFailure,$now+300);
    $after=$saved();
    $check('a delayed actual failure removes only new optional counters before existing evidence can yield',
        !array_key_exists('visit_gate_decisions',$after)&&$measure($after)<=8192
        &&modeledValueFailureJsonEqual($after['finite_visit_evidence'],$old)
        &&modeledValueFailureJsonEqual($after['finite_visit_recovery'],$recovery)
        &&modeledValueFailureJsonEqual($after['last_recorded_failure'],$newFailure)
        &&$after['accepted']===1&&$after['processed']===3&&$after['errors']===1
        &&ModeledConversionValueExport::settings($p)['last_tick_unix']===$before['last_tick_unix']);
}

/** URL-level counter-zone bounds retain every visit that the unchanged current gates can admit. */
function modeledValueCurrentVisitLookbackAssertions(callable $check):void
{
    $fixture=static function(string $zoneName,int $now,int $visitAt,string $source):array{
        $zone=new DateTimeZone($zoneName);$wire=(object)['gets'=>0,'posts'=>0,'queries'=>[]];
        $transport=static function(string $method,string $url,mixed $body)use($zoneName,$zone,$now,$visitAt,$source,$wire):array{
            if($method!=='GET'){$wire->posts++;throw new RuntimeException('fixture_forbids_post');}
            $wire->gets++;$path=(string)parse_url($url,PHP_URL_PATH);
            if($path==='/management/v1/counter/98615635')return ['http'=>200,'error'=>0,'body'=>json_encode([
                'counter'=>['id'=>98615635,'time_zone_name'=>$zoneName]],JSON_THROW_ON_ERROR)];
            if($path!=='/stat/v1/data')throw new RuntimeException('fixture_unexpected_get');
            parse_str((string)parse_url($url,PHP_URL_QUERY),$q);$wire->queries[]=$q;
            $dims=explode(',',$q['dimensions']);$metrics=explode(',',$q['metrics']);$traffic=count($dims)===4;
            $local=(new DateTimeImmutable('@'.$visitAt))->setTimezone($zone);
            $today=(new DateTimeImmutable('@'.$now))->setTimezone($zone)->format('Y-m-d');
            $inRange=$local->format('Y-m-d')>=$q['date1']&&$local->format('Y-m-d')<=$today;
            $row=[['name'=>'88000000901'],['name'=>$local->format('Y-m-d H:i:s')]];
            if($traffic){$row[]=['id'=>$source==='yandex_direct'?'ad':'direct'];$row[]=['id'=>$source==='yandex_direct'?'ya_direct':null];}
            $values=count($metrics)===3?[1,1,1250]:[1];
            return ['http'=>200,'error'=>0,'body'=>json_encode([
                'query'=>['ids'=>[98615635],'metrics'=>$metrics,'dimensions'=>$dims,'filters'=>$q['filters']],
                'data'=>$inRange?[['dimensions'=>$row,'metrics'=>$values]]:[],
                'sampled'=>false,'total_rows'=>$inRange?1:0],JSON_THROW_ON_ERROR)];
        };
        return [new MetrikaOfflineClient('98615635','fixture-only',$transport),$wire];
    };
    foreach(['Pacific/Kiritimati','America/Los_Angeles','Europe/Moscow']as$zoneName){
        foreach(['2026-11-11T00:30:00Z','2026-11-11T23:30:00Z']as$clock){
            $now=strtotime($clock);$zone=new DateTimeZone($zoneName);
            $start=(new DateTimeImmutable('@'.($now-10*86400)))->setTimezone($zone)->format('Y-m-d');
            foreach([10,9]as$days){
                [$api,$wire]=$fixture($zoneName,$now,$now-$days*86400,'yandex_direct');$extra=0;
                $report=$api->modeledAttributionVisits('88000000901',$now,$now,static function()use(&$extra):void{$extra++;});
                $window=ModeledConversionAttributionWindow::assess($report,$now,$now);
                $check('current URL calendar floor retains Direct '.$days.'-day visit in '.$zoneName.' '.$clock,
                    $window['allowed']&&$window['visit_at']===$now-$days*86400&&$window['window_days']===10
                    &&$wire->gets===3&&$extra===1&&$wire->posts===0&&count($wire->queries)===2
                    &&$wire->queries[0]['date1']===$start&&$wire->queries[1]['date1']===$start
                    &&$wire->queries[0]['date2']==='today'&&$wire->queries[1]['date2']==='today'
                    &&$wire->queries[0]['limit']==='101'&&$wire->queries[1]['limit']==='101');
            }
        }
    }
    $now=strtotime('2026-11-11T00:30:00Z');
    foreach([[3*86400,true],[3*86400+1,false],[10*86400+1,false],[12*86400,false]]as[$age,$allowed]){
        [$api,$wire]=$fixture('Europe/Moscow',$now,$now-$age,'other_or_unknown');
        $report=$api->modeledAttributionVisits('88000000901',$now,$now);
        $window=ModeledConversionAttributionWindow::assess($report,$now,$now);
        $check('current query never widens unknown three-day or expired ten-day eligibility '.$age,
            $window['allowed']===$allowed&&$wire->gets<=3&&$wire->posts===0
            &&$wire->queries[0]['date1']===(new DateTimeImmutable('@'.($now-10*86400)))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d'));
    }
    [$api,$wire]=$fixture('Pacific/Kiritimati',$now,$now-21*86400,'other_or_unknown');
    $legacy1=$api->modeledValueVisits('88000000901',$now,$now);
    $legacy2=$api->modeledClientVisits('88000000901',$now,$now);
    $check('historical goal and client reports preserve their original 22-day ranges and request budget',
        $legacy1['status']==='ok'&&count($legacy1['visits'])===1&&$legacy2['status']==='ok'&&count($legacy2['visits'])===1
        &&$wire->gets===3&&$wire->posts===0&&count($wire->queries)===2
        &&$wire->queries[0]['date1']===gmdate('Y-m-d',$now-22*86400)
        &&$wire->queries[1]['date1']===gmdate('Y-m-d',$now-22*86400)
        &&str_contains($wire->queries[0]['filters'],'goal==678187097')
        &&!str_contains($wire->queries[1]['filters'],'goal=='));
}

/** No network: exercise the exact timeout options consumed by the existing transport. */
function modeledValueReportTimeoutAssertions(callable $check):void
{
    $options=new ReflectionMethod(MetrikaOfflineClient::class,'requestTimeouts');
    $plain=new MetrikaOfflineClient('98615635','fixture-only',static fn():array=>[]);
    foreach([['GET',true,true,2,20],['GET',true,false,2,8],['GET',false,true,5,30],['POST',true,true,2,8],['POST',false,false,5,30]]as[$method,$statistics,$ordinary,$connect,$timeout]){
        $actual=$options->invoke($plain,$method,$statistics,$ordinary,1000.0);
        $check('isolated aggregate timeout leaves other request budgets unchanged '.implode(':',[$method,(int)$statistics,(int)$ordinary]),
            $actual===['connect_seconds'=>$connect,'timeout_seconds'=>$timeout]);
    }
    foreach([[0.5,500],[20.0,20000],[30.0,20000]]as[$remaining,$expected]){
        $bounded=new MetrikaOfflineClient('98615635','fixture-only',static fn():array=>[],1000.0+$remaining);
        $actual=$options->invoke($bounded,'GET',true,true,1000.0);
        $check('aggregate timeout always respects a smaller equal or larger existing read deadline '.$remaining,
            $actual['timeout_ms']===$expected&&$actual['connect_ms']===min(2000,$expected));
    }
    $bounded=new MetrikaOfflineClient('98615635','fixture-only',static fn():array=>[],1030.0);
    $other=$options->invoke($bounded,'GET',true,false,1000.0);
    $management=$options->invoke($bounded,'GET',false,false,1000.0);
    $check('other statistics and management GETs retain their existing deadline ceilings',
        $other['timeout_ms']===8000&&$management['timeout_ms']===8000);
    $calls=0;$expired=new MetrikaOfflineClient('98615635','fixture-only',static function()use(&$calls):array{$calls++;return[];},microtime(true)-1.0);
    $blocked=false;
    try{$expired->modeledValueReport(1793650000,1793660000);}catch(RuntimeException $e){$blocked=$e->getMessage()==='verification_deadline';}
    $check('expired existing deadlines forbid the ordinary report before any transport invocation',$blocked&&$calls===0);
}

/** Same HTTP responses, closed failure facts only; the existing validators still refuse every bad fixture. */
function modeledValueAttributionFailureAssertions(callable $check):void
{
    $now=1794398400;$cid='88000000941';
    $cases=[
        'counter_transport'=>[1,null,28,0],'counter_http'=>[2,403,0,0],
        'counter_shape'=>[3,200,0,1],'counter_id'=>[3,200,0,2],
        'zone_missing'=>[3,200,0,4],'zone_invalid'=>[3,200,0,8],
        'report_transport'=>[4,200,28,0],'report_http'=>[5,503,0,0],
        'scope_query'=>[6,200,0,1],'scope_ids'=>[6,200,0,2],'scope_metrics'=>[6,200,0,4],
        'scope_dimensions'=>[6,200,0,8],'scope_filter'=>[6,200,0,16],
        'sampled'=>[7,200,0,1],'rows_type'=>[7,200,0,2],'row_overflow'=>[7,200,0,4],
        'total_type'=>[7,200,0,8],'total_mismatch'=>[7,200,0,16],
        'row_dimensions'=>[8,200,0,1],'row_metrics'=>[8,200,0,2],
        'row_identity'=>[8,200,0,4],'row_identity_id_matches'=>[8,200,0,260],
        'row_metric_value'=>[8,200,0,8],'row_metric_integer'=>[8,200,0,16],
        'clock_iso'=>[8,200,0,148512],'clock_date'=>[8,200,0,165920],
        'clock_invalid'=>[8,200,0,1057312],'clock_future'=>[8,200,0,1057344],
        'numeric_strings'=>[5,null,null,0],'out_of_range_error'=>[4,200,null,0]
    ];
    foreach($cases as$case=>$expected){
        foreach([false,true]as$historical){
            $wire=(object)['gets'=>0,'reports'=>0,'posts'=>0];
            $api=new MetrikaOfflineClient('98615635','fixture-private-token',static function(string $method,string $url,mixed $body)use($case,$now,$cid,$wire):array{
                if($method!=='GET'){$wire->posts++;throw new RuntimeException('fixture_forbids_post');}$wire->gets++;
                if((string)parse_url($url,PHP_URL_PATH)==='/management/v1/counter/98615635'){
                    if($case==='counter_transport')return ['http'=>0,'error'=>28,'body'=>'PRIVATE_COUNTER_BODY'];
                    if($case==='counter_http')return ['http'=>403,'error'=>0,'body'=>'PRIVATE_COUNTER_BODY'];
                    $counter=['id'=>98615635,'time_zone_name'=>'UTC'];
                    if($case==='counter_shape')$counter=null;
                    if($case==='counter_id')$counter['id']=98615636;
                    if($case==='zone_missing')unset($counter['time_zone_name']);
                    if($case==='zone_invalid')$counter['time_zone_name']='PRIVATE_INVALID_ZONE';
                    return ['http'=>200,'error'=>0,'body'=>json_encode(['counter'=>$counter],JSON_THROW_ON_ERROR)];
                }
                $wire->reports++;
                if($case==='report_transport')return ['http'=>200,'error'=>28,'body'=>'PRIVATE_VISIT_BODY'];
                if($case==='report_http')return ['http'=>503,'error'=>0,'body'=>'PRIVATE_VISIT_BODY'];
                if($case==='numeric_strings')return ['http'=>'503','error'=>'0','body'=>'PRIVATE_VISIT_BODY'];
                if($case==='out_of_range_error')return ['http'=>200,'error'=>101,'body'=>'PRIVATE_VISIT_BODY'];
                parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
                $q=['ids'=>[98615635],'metrics'=>explode(',',$query['metrics']),'dimensions'=>explode(',',$query['dimensions']),'filters'=>$query['filters']];
                $clock=gmdate('Y-m-d H:i:s',$now-60);
                $dims=[['name'=>$cid],['name'=>$clock]];$values=[1];
                if($case==='row_dimensions')$dims=[['name'=>$cid]];
                if($case==='row_metrics')$values=[];
                if($case==='row_identity')$dims[0]=['name'=>'PRIVATE_WRONG_CID'];
                if($case==='row_identity_id_matches')$dims[0]=['name'=>'PRIVATE_DISPLAY_NAME','id'=>$cid];
                if($case==='row_metric_value')$values=[-1];
                if($case==='row_metric_integer')$values=[1.5];
                if($case==='clock_iso')$dims[1]=['name'=>gmdate('Y-m-d\TH:i:s\Z',$now-60),'id'=>$clock];
                if($case==='clock_date')$dims[1]=['name'=>gmdate('Y-m-d',$now-60),'id'=>$clock];
                if($case==='clock_invalid')$dims[1]=['name'=>'2026-11-31 12:00:00'];
                if($case==='clock_future')$dims[1]=['name'=>gmdate('Y-m-d H:i:s',$now+60)];
                $data=['query'=>$q,'data'=>[['dimensions'=>$dims,'metrics'=>$values]],'sampled'=>false,'total_rows'=>1];
                if($case==='scope_query')unset($data['query']);
                if($case==='scope_ids')$data['query']['ids']=[98615636];
                if($case==='scope_metrics')$data['query']['metrics']=['ym:s:users'];
                if($case==='scope_dimensions')$data['query']['dimensions']=['ym:s:date'];
                if($case==='scope_filter')$data['query']['filters']='PRIVATE_WRONG_FILTER';
                if($case==='sampled')$data['sampled']=true;
                if($case==='rows_type')$data['data']=null;
                if($case==='row_overflow'){$data['data']=array_fill(0,101,$data['data'][0]);$data['total_rows']=101;}
                if($case==='total_type')$data['total_rows']='PRIVATE_NOT_A_COUNT';
                if($case==='total_mismatch')$data['total_rows']=2;
                return ['http'=>200,'error'=>0,'body'=>json_encode($data,JSON_THROW_ON_ERROR)];
            });
            $report=$historical?$api->modeledClientVisits($cid,$now,$now):$api->modeledAttributionVisits($cid,$now,$now);
            $check('same-response attribution failure '.$case.($historical?' historical DTO unchanged':' current closed tuple'),
                $report['status']==='unavailable'&&$report['visits']===[]
                &&($historical?$report===['status'=>'unavailable','visits'=>[]]:($report['attribution_failure']??null)===$expected)
                &&$wire->gets===($expected[0]<=3?1:2)&&$wire->posts===0);
            $json=json_encode($report,JSON_THROW_ON_ERROR);
            $check('refused report discloses no provider or identity bytes '.$case.($historical?' history':' current'),
                !str_contains($json,'PRIVATE_')&&!str_contains($json,$cid)&&!str_contains($json,'fixture-private-token')
                &&!str_contains($json,'https:')&&!str_contains($json,'2026-'));
        }
    }
    $normalize=new ReflectionMethod(ModeledConversionValueExport::class,'normalizeVisitFailure');
    foreach([[5,'503',0,0],[8,200,0,128],[8,200,0,1|4|128],[8,200,0,32],[8,200,0,32|512|8192|131072|1024],
        [9,200,0,0],[7,200,0,0],null]as$invalid){
        $check('malformed diagnostic becomes explicit unknown without carrying raw scalars',
            $normalize->invoke(null,$invalid)===[9,null,null,0]);
    }
    $append=new ReflectionMethod(ModeledConversionValueExport::class,'withVisitGateDecisions');
    $counts=['within_current_window'=>0,'outside_current_window'=>0,'no_prior_visit'=>0,'visit_report_unavailable'=>1,'unknown'=>0];
    $v1=['v'=>1,'checked_at_unix'=>$now,'attempts'=>1,'reason_counts'=>$counts];
    $v2=$v1;$v2['v']=2;$v2['failures']=[[5,503,0,0,1]];
    $check('saved v1 counters stay backward compatible without inventing failure diagnostics',
        $append->invoke(null,[],$v1,2,$now)===['visit_gate_decisions'=>$v1]);
    $check('valid v2 sparse diagnostics stay below their fixed optional byte cap',
        $append->invoke(null,[],$v2,2,$now)===['visit_gate_decisions'=>$v2]
        &&strlen(json_encode(['visit_gate_decisions'=>$v2],JSON_THROW_ON_ERROR))<=512);
    foreach([[[5,503,0,0,2]],[[5,503,0,0,1],[5,503,0,0,1]],[[8,200,0,32,1]]]as$invalid){
        $bad=$v2;$bad['failures']=$invalid;
        $check('invalid count duplicate or incomplete clock diagnostics cannot become a saved audit',
            $append->invoke(null,[],$bad,2,$now)===[]);
    }
}

/** Only the already consumed canonical pair may pass source age; actual current visits still decide first sends. */
function modeledValueFixedPairAssertions(PDO $p,callable $check):void
{
    $event=ModeledConversionValueVisitEvidence::CHECKPOINT-100;$now=ModeledConversionValueVisitEvidence::CHECKPOINT+20000;
    $ns=ModeledConversionProfiles::assessmentNamespace();$gate=static fn(string $chat):bool=>true;
    $setup=static function()use($p,$event,$now,$ns,$gate):array{
        modeledValueSchema($p);$p->exec("ALTER TABLE modeled_conversion_profile_versions ADD COLUMN assessment_namespace CHAR(64)");
        $p->exec("ALTER TABLE modeled_conversion_profile_versions ADD COLUMN assessment_kind VARCHAR(8)");
        $wire=new ModeledValueTestWire($event-60);$control=new ModeledConversionValueExport($p);
        $control->configure('create-goal',$wire->api(),$now-40000);$control->configure('enable',$wire->api(),$now-40000);
        for($i=1;$i<=3;$i++)modeledValueSave($p,modeledExpertWrapper(modeledValueBinding($p,6800+$i),$event,200000));
        $p->prepare("UPDATE modeled_conversion_profile_versions SET observed_at_unix=?,assessment_namespace=?,assessment_kind='repair'")->execute([$event,$ns]);
        while($control->collect(ModeledConversionValueExport::settings($p),$now,$gate)>0){}
        $rows=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $original=['v'=>ModeledConversionValueVisitEvidence::VERSION,'n'=>$ns,'b'=>ModeledConversionValueVisitEvidence::CHECKPOINT,'r'=>[]];$records=[];
        foreach($rows as$i=>$row){
            $ref=ModeledConversionValueIdentityEvidence::reference($row,$ns);$checked=ModeledConversionValueVisitEvidence::CHECKPOINT+600;
            if($i<2){
                $record=['external_user_id'=>$row['external_user_id'],'client_id'=>'880000'.$row['external_user_id'],
                    'record_id'=>'90000000000000401','observed_at_unix'=>$event-($i===0?13:51)*86400];
                $hash=ModeledConversionValueVisitEvidence::candidateReference($ref,$record['client_id'],$record['record_id'],$record['observed_at_unix'],'canonical_max_lookup');
                $original['r'][]=[$ref,(string)$row['id'],2,$checked-1,$checked,1,$record['client_id'],$record['record_id'],$record['observed_at_unix'],$hash,2,false,4,null,0,$event];
                $records[$row['external_user_id']]=$record;
            }else{$original['r'][]=[$ref,(string)$row['id'],2,null,$checked,3,null,null,null,null,0,null,0,null,0,$event];$records[$row['external_user_id']]=null;}
        }
        $recovery=ModeledConversionValueVisitRecovery::create($original,$ns,$now);
        foreach($recovery['r']as$i=>$entry){
            $entry[2]=2;$entry[3]=ModeledConversionValueVisitEvidence::CHECKPOINT+1200;
            $entry[4]=$entry[3]+1;$entry[5]=2;$entry[6]=false;$entry[7]=4;$entry[10]=1;$recovery['r'][$i]=$entry;
        }
        $receipt=['errors'=>0,'metrika_report'=>['status'=>'unavailable','http_status'=>503,'transport_error_code'=>0],
            'finite_visit_evidence'=>$original,'finite_visit_recovery'=>$recovery];
        $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_tick_unix=?,last_receipt_json=? WHERE project_key='anytour'")
            ->execute([$now-300,json_encode($receipt,JSON_THROW_ON_ERROR)]);
        $wire->reportHttp=503;
        $source=new class($records){
            public int $calls=0;public array $queried=[];
            public function __construct(public array $records){}
            public function lookup(string $counter,array $users):array{$this->calls++;$this->queried=$users;return array_intersect_key($this->records,array_fill_keys($users,true));}
        };
        return [$rows,$source,$wire];
    };
    $saved=static fn():array=>json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
    [$rows,$source,$wire]=$setup();$before=$saved();$wire->visitAvailable=false;$api=$wire->api();
    ModeledConversionValueExport::tick($p,$source,$api,$now,$gate);
    $held=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $check('only the fixed pair resolves old canonical source clocks while unavailable actual visits retain an unclaimed ready hold',
        $held[0]['state']==='ready'&&$held[1]['state']==='ready'&&$held[2]['state']==='pending'
        &&(int)$held[0]['tracker_observed_unix']===$source->records[$rows[0]['external_user_id']]['observed_at_unix']
        &&(int)$held[1]['tracker_observed_unix']===$source->records[$rows[1]['external_user_id']]['observed_at_unix']
        &&$wire->posts===0&&$wire->visitReads===2&&$source->calls===1&&count($source->queried)<=25
        &&(int)$held[0]['attempts']===0&&empty($held[0]['claim_token'])&&empty($held[0]['upload_id']));
    $wire->visitAvailable=true;
    ModeledConversionValueExport::tick($p,$source,$api,$now+301,$gate);
    $sent=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $check('a later natural ready tick refreshes the same pair and sends once only after actual current prior visits despite unavailable aggregate health',
        $sent[0]['state']==='accepted'&&$sent[1]['state']==='accepted'&&$wire->posts===2&&$wire->visitReads===4
        &&$source->calls===2&&(int)$sent[0]['attempts']===1&&(int)$sent[1]['attempts']===1
        &&(int)$sent[0]['event_unix']===$event&&(int)$sent[1]['event_unix']===$event
        &&$sent[0]['value_rub']===$rows[0]['value_rub']&&$sent[1]['value_rub']===$rows[1]['value_rub']
        &&modeledValueFailureJsonEqual($saved()['finite_visit_evidence'],$before['finite_visit_evidence'])
        &&modeledValueFailureJsonEqual($saved()['finite_visit_recovery'],$before['finite_visit_recovery']));
    ModeledConversionValueExport::tick($p,$source,$api,$now+602,$gate);
    $check('accepted pair receipts use only normal receipt polling and never repeat a POST',$wire->posts===2);

    foreach(['client','record','clock','fresh_different_source','fresh_clock','future_source','value','namespace','attempt','claimed','paid','unknown','future_due','unfinished_recovery']as$case){
        [$rows,$source,$wire]=$setup();$id=$rows[0]['id'];$user=$rows[0]['external_user_id'];
        if($case==='client')$source->records[$user]['client_id']='88000000000002';
        if($case==='record')$source->records[$user]['record_id']='90000000000000402';
        if($case==='clock')$source->records[$user]['observed_at_unix']--;
        if($case==='fresh_different_source'){$source->records[$user]['client_id']='88000000000002';$source->records[$user]['observed_at_unix']=$event-60;}
        if($case==='fresh_clock')$source->records[$user]['observed_at_unix']=$event-60;
        if($case==='future_source')$source->records[$user]['observed_at_unix']=$event+1;
        if($case==='value')$p->prepare('UPDATE max_channel_conversion_outbox SET value_rub=value_rub+1 WHERE id=?')->execute([$id]);
        if($case==='namespace')$p->prepare('UPDATE modeled_conversion_profile_versions SET assessment_namespace=? WHERE id=?')->execute([str_repeat('f',64),$rows[0]['value_profile_id']]);
        if($case==='attempt')$p->prepare('UPDATE max_channel_conversion_outbox SET attempts=1 WHERE id=?')->execute([$id]);
        if($case==='claimed')$p->prepare("UPDATE max_channel_conversion_outbox SET claim_token='existing',claimed_at_unix=? WHERE id=?")->execute([$now-1,$id]);
        if($case==='paid')$p->prepare("UPDATE max_channel_conversion_outbox SET state='accepted',attempts=1,upload_id='9999',value_api_accepted_at_unix=?,value_receipt_basis='post_response' WHERE id=?")->execute([$now-1,$id]);
        if($case==='unknown')$p->prepare("UPDATE max_channel_conversion_outbox SET state='unknown' WHERE id=?")->execute([$id]);
        if($case==='future_due')$p->prepare('UPDATE max_channel_conversion_outbox SET next_attempt_unix=? WHERE id=?')->execute([$now+900,$id]);
        if($case==='unfinished_recovery'){
            $r=$saved();$entry=$r['finite_visit_recovery']['r'][0];$entry[2]=1;$entry[4]=null;$entry[5]=0;$entry[6]=null;$entry[7]=0;$r['finite_visit_recovery']['r'][0]=$entry;
            $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")->execute([json_encode($r,JSON_THROW_ON_ERROR)]);
        }
        ModeledConversionValueExport::tick($p,$source,$wire->api(),$now,$gate);
        $q=$p->prepare('SELECT * FROM max_channel_conversion_outbox WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $check('fixed-pair admission refuses changed or previously claimed scope '.$case,
            $row['value_ready_at_unix']===null&&($case==='paid'?$row['upload_id']==='9999':empty($row['upload_id']))
            &&($case==='future_due'?!in_array($user,$source->queried,true):true)&&$source->calls<=1);
    }

    [$rows,$source,$wire]=$setup();$wire->visitAvailable=false;$api=$wire->api();
    ModeledConversionValueExport::tick($p,$source,$api,$now,$gate);
    $user=$rows[0]['external_user_id'];$source->records[$user]['record_id']='90000000000000402';$wire->visitAvailable=true;
    ModeledConversionValueExport::tick($p,$source,$api,$now+301,$gate);
    $held=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $source->records[$user]['record_id']='90000000000000401';
    ModeledConversionValueExport::tick($p,$source,$api,$now+602,$gate);
    $check('a ready pair waits for the exact canonical candidate and resumes after it returns without resetting first-send state',
        $held['state']==='ready'&&(int)$held['attempts']===0&&empty($held['upload_id'])&&$wire->posts===2);

    [$rows,$source,$wire]=$setup();$wire->visitAvailable=false;$control=new ModeledConversionValueExport($p);
    for($i=1;$i<=24;$i++){
        $scope=modeledValueBinding($p,6900+$i);modeledValueSave($p,modeledExpertWrapper($scope,$now-1,200000));
        $source->records[(string)(70000+6900+$i)]=null;
    }
    while($control->collect(ModeledConversionValueExport::settings($p),$now,$gate)>0){}
    ModeledConversionValueExport::tick($p,$source,$wire->api(),$now,$gate);
    $fresh=(int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE conversation_id>=6901 AND last_error='identity_not_found'")->fetchColumn();
    $check('the fixed pair shares the single 25-user lookup and retains at least 22 fresh-source slots',
        $source->calls===1&&count($source->queried)===25&&$fresh===22&&$wire->posts===0);

    foreach(['event_unix','value_api_accepted_at_unix','claim_token']as$field){
        [$rows,$source,$wire]=$setup();$changed=false;$cid=$source->records[$rows[0]['external_user_id']]['client_id'];
        $api=new MetrikaOfflineClient('98615635','fixture-only',static function(string $method,string $url,mixed $body)use($wire,$p,$field,$event,$now,$cid,&$changed):array{
            $response=$wire->request($method,$url,$body);parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
            if(!$changed&&$method==='GET'&&isset($query['dimensions'])&&str_contains($query['filters']??'',$cid)){
                $changed=true;$p->prepare('UPDATE max_channel_conversion_outbox SET '.$field.'=? WHERE id=1')->execute([$field==='claim_token'?'other-owner':($field==='event_unix'?$event+1:$now)]);
            }
            return $response;
        });
        ModeledConversionValueExport::tick($p,$source,$api,$now,$gate);
        $row=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $check('the same financial claim refuses an intervening immutable or receipt change '.$field,
            $changed&&$row['state']==='ready'&&(int)$row['attempts']===0&&empty($row['upload_id'])&&$wire->posts===1);
    }

    // The pair must retain the existing exact current Direct/other windows.
    foreach([['yandex_direct',9*86400,true],['other_or_unknown',4*86400,false],['other_or_unknown',3*86400,true],['other_or_unknown',$now-$event-1,false]]as[$kind,$age,$allow]){
        [$rows,$source,$wire]=$setup();$wire->visitAt=$now-$age;$wire->visitSource=$kind;
        ModeledConversionValueExport::tick($p,$source,$wire->api(),$now,$gate);
        $q=$p->query('SELECT state,attempts FROM max_channel_conversion_outbox WHERE id IN (1,2) ORDER BY id');$states=$q->fetchAll(PDO::FETCH_ASSOC);
        $check('source-age correction keeps actual current visit gate '.$kind.':'.$age,
            $allow?$wire->posts===2&&array_column($states,'state')===['accepted','accepted']
                :$wire->posts===0&&array_column($states,'state')===['ready','ready']&&(int)$states[0]['attempts']===0);
    }
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    $passed=0;$check=static function(string $name,bool $ok)use(&$passed){if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;};
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    modeledValueAssertions($p,$check);modeledValueHistoryAssertions($p,$check);modeledValueFortnightAssertions($p,$check);modeledValueFairProgressAssertions($p,$check);echo 'MODELED VALUE EXPORT PASSED '.$passed.PHP_EOL;
}
