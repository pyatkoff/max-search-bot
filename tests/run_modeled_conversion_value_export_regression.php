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
    $p->exec("CREATE TABLE modeled_conversion_profile_versions(id $auto,project_key VARCHAR(64),platform VARCHAR(16),customer_key VARCHAR(20),conversation_id BIGINT,observed_at_unix BIGINT,profile_json ".($mysql?'JSON':'TEXT').")");
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
        $wire=new ModeledValueTestWire();$api=$wire->api();$control=new ModeledConversionValueExport($p);
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
    $wire=new ModeledValueTestWire();$api=$wire->api();$control=new ModeledConversionValueExport($p);
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
        $wire=new ModeledValueTestWire();$api=$wire->api();$control=new ModeledConversionValueExport($p);
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
        modeledValueSchema($p);$scope=modeledValueBinding($p,913);$wire=new ModeledValueTestWire();$wire->visitSource=$source;$wire->visitAt=$now-$age;
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
    $now=1793650000;$gate=static fn(string $chat):bool=>true;
    modeledValueSchema($p);$wire=new ModeledValueTestWire();$api=$wire->api();$control=new ModeledConversionValueExport($p);
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

    modeledValueSchema($p);$wire=new ModeledValueTestWire();$api=$wire->api();$control=new ModeledConversionValueExport($p);
    $launch=$control->configure('launch-v1',$api,$now);
    $check('one-time accepted-release launch creates verifies and enables',$launch['mode']==='send'&&$wire->creates===1);
    $control->configure('disable',$api,$now+1);$gets=$wire->gets;$launch=$control->configure('launch-v1',$api,$now+2);
    $check('a repeated release cannot reenable a deliberately stopped launch',$launch['already_launched']===true&&$launch['mode']==='off'&&$wire->creates===1&&$wire->gets===$gets);

    modeledValueSchema($p);$wire=new ModeledValueTestWire();$wire->createUnknown=true;$api=$wire->api();$control=new ModeledConversionValueExport($p);
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

    $wire=new ModeledValueTestWire();$api=$wire->api();$report=$api->modeledValueReport($now-8,$now);
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

    modeledValueSchema($p);$wire=new ModeledValueTestWire();$api=$wire->api();$wire->reportTotals=[0,0,0];
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
        modeledValueSchema($p);$wire=new ModeledValueTestWire();
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
        $newWire=new ModeledValueTestWire();$newWire->goals=$wire->goals;$wire->reportHttp=403;
        $winning=null;$outsideTransaction=true;$entered=false;
        $api=new MetrikaOfflineClient('98615635','fixture-only',static function(string $method,string $url,mixed $body)use($p,$wire,$newWire,$lookup,$now,$gate,$steady,$pressure,&$winning,&$outsideTransaction,&$entered):array{
            $outsideTransaction=$outsideTransaction&&!$p->inTransaction();
            if((string)parse_url($url,PHP_URL_PATH)==='/stat/v1/data'&&!$entered){
                $entered=true;ModeledConversionValueExport::tick($p,$lookup,$newWire->api(),$now+200,$gate,null,$steady);
                if($pressure){
                    $current=json_decode(ModeledConversionValueExport::settings($p)['last_receipt_json'],true,32,JSON_THROW_ON_ERROR);
                    unset($current['last_recorded_failure']);$current['padding']='';
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
        [$wire,$lookup]=$setup(0);$nestedWire=new ModeledValueTestWire();$nestedWire->goals=$wire->goals;$entered=false;$outsideTransaction=true;
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
    $wire=new ModeledValueTestWire();$wire->goals[]=['id'=>'678187097','name'=>ModeledConversionValueContract::NAME,
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
    $wire=new ModeledValueTestWire();$wire->goals[]=['id'=>'678187097','name'=>ModeledConversionValueContract::NAME,
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
        modeledValueSchema($p);$wire=new ModeledValueTestWire();
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
        $cursor()===32&&$rows()===32&&$receipt()['collected']===32&&$receipt()['resolved']===5
        &&$lookup->calls===1&&$wire->posts===3&&$wire->gets===6&&$wire->visitReads===3&&$receipt()['accepted']===3&&$receipt()['errors']===0);
}

function modeledValueFairProgressAssertions(PDO $p,callable $check):void
{
    modeledValueSchema($p);$now=1793659000;$gate=static fn(string $chat):bool=>true;
    $wire=new ModeledValueTestWire();$wire->goals[]=['id'=>'678187097','name'=>ModeledConversionValueContract::NAME,
        'type'=>'action','conditions'=>[['type'=>'exact','url'=>ModeledConversionValueContract::TARGET]]];
    $api=$wire->api();$lookup=new ModeledValueTestLookup();
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET mode='send',goal_id='678187097',goal_intent='verified',activated_at_unix=?")->execute([$now-10]);
    for($i=501;$i<=506;$i++)modeledValueSave($p,modeledValueWrapper(modeledValueBinding($p,$i,$i===506?'max':'telegram'),$now));
    ModeledConversionValueExport::tick($p,$lookup,$api,$now,$gate);
    $check('one full missing-identity batch preserves all values without premature sends',
        $wire->posts===0&&(int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE last_error='identity_not_found'")->fetchColumn()===5);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+300,$gate);
    $check('later unattempted eligible row advances ahead of a repeatedly due missing batch',
        $wire->posts===1&&$p->query("SELECT state FROM max_channel_conversion_outbox WHERE conversation_id=506")->fetchColumn()==='accepted'
        &&(int)$p->query("SELECT SUM(value_rub) FROM max_channel_conversion_outbox WHERE state='pending'")->fetchColumn()===6250);
    ModeledConversionValueExport::tick($p,$lookup,$api,$now+600,$gate);
    $check('fair progress keeps missing rows and polls the accepted receipt without resending',
        $wire->posts===1&&(int)$p->query("SELECT COUNT(*) FROM max_channel_conversion_outbox WHERE state='pending'")->fetchColumn()===5
        &&$p->query("SELECT state FROM max_channel_conversion_outbox WHERE conversation_id=506")->fetchColumn()==='processed');

    // Synthetic legacy profiles can have an audit hold after a model/adapter
    // upgrade. Those rows retain their values but must not monopolize ready.
    modeledValueSchema($p);$now=1793670000;
    $wire=new ModeledValueTestWire();$wire->visitAt=$now-60;$api=$wire->api();
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
    $wire=new ModeledValueTestWire();$api=$wire->api();$control=new ModeledConversionValueExport($p);
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
    $scope=modeledValueBinding($p,850);$wire=new ModeledValueTestWire();$api=$wire->api();$control=new ModeledConversionValueExport($p);
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

    modeledValueSchema($p);$wire=new ModeledValueTestWire();$api=$wire->api();$control=new ModeledConversionValueExport($p);
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
    $wire=new ModeledValueTestWire();$api=$wire->api();$control=new ModeledConversionValueExport($p);
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
    modeledValueSchema($p);$scope=modeledValueBinding($p,752);$control=new ModeledConversionValueExport($p);$wire=new ModeledValueTestWire();$api=$wire->api();
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

if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    $passed=0;$check=static function(string $name,bool $ok)use(&$passed){if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;};
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    modeledValueAssertions($p,$check);modeledValueHistoryAssertions($p,$check);modeledValueFortnightAssertions($p,$check);modeledValueFairProgressAssertions($p,$check);echo 'MODELED VALUE EXPORT PASSED '.$passed.PHP_EOL;
}
