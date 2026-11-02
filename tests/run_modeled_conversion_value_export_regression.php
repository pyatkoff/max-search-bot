<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ModeledConversionValueExport.php';

function modeledValueSchema(PDO $p):void
{
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql&&$p->query('SELECT DATABASE()')->fetchColumn()!=='maxbot_modeled_stream_test')throw new RuntimeException('unsafe_fixture_database');
    foreach(['modeled_conversion_value_export_settings','max_channel_conversion_outbox','max_channel_attribution_settings','modeled_conversion_profile_versions','advertising_visit_observations','conversations','customer_channels']as$t)$p->exec('DROP TABLE IF EXISTS '.$t);
    $auto=$mysql?'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    if($mysql){
        $p->exec(file_get_contents(dirname(__DIR__).'/migrations/037_max_channel_conversion_outbox.sql'));
        $p->exec("ALTER TABLE max_channel_conversion_outbox ADD transport_target VARCHAR(64),ADD platform VARCHAR(16) NOT NULL DEFAULT 'max',ADD identity_source_key VARCHAR(80) NOT NULL DEFAULT '',ADD identity_observation_id BIGINT NULL");
        $p->exec(file_get_contents(dirname(__DIR__).'/migrations/086_modeled_conversion_value_export.sql'));
    }else{
        $p->exec("CREATE TABLE max_channel_conversion_outbox(id $auto,project_key TEXT,conversation_id INTEGER,external_user_id TEXT,counter_id TEXT,target TEXT,transport_target TEXT,event_unix INTEGER,client_id TEXT,tracker_record_id TEXT,tracker_observed_unix INTEGER,state TEXT DEFAULT 'pending',next_attempt_unix INTEGER DEFAULT 0,claim_token TEXT,claimed_at_unix INTEGER,attempts INTEGER DEFAULT 0,upload_id TEXT,upload_status TEXT,last_error TEXT DEFAULT '',platform TEXT DEFAULT 'max',identity_source_key TEXT DEFAULT '',identity_observation_id INTEGER,value_rub NUMERIC,value_profile_id INTEGER,value_stage TEXT,value_contract TEXT,UNIQUE(project_key,conversation_id,target))");
        $p->exec("CREATE TABLE modeled_conversion_value_export_settings(project_key TEXT PRIMARY KEY,counter_id TEXT,mode TEXT DEFAULT 'off',goal_id TEXT,goal_intent TEXT DEFAULT 'none',activated_at_unix INTEGER,profile_cursor INTEGER DEFAULT 0,base_value_rub INTEGER DEFAULT 25000,contract_version TEXT DEFAULT 'semantic_stage_value_25000_v1',last_tick_unix INTEGER,last_receipt_json TEXT)");
        $p->exec("INSERT INTO modeled_conversion_value_export_settings(project_key,counter_id) VALUES('anytour','98615635')");
    }
    $p->exec("CREATE TABLE modeled_conversion_profile_versions(id $auto,project_key VARCHAR(64),platform VARCHAR(16),customer_key VARCHAR(20),conversation_id BIGINT,profile_json ".($mysql?'JSON':'TEXT').")");
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
    public array $csv=[];public array $receipts=[];public int $creates=0;public int $posts=0;public int $gets=0;
    public string $postMode='accepted';public bool $createUnknown=false;public mixed $duringPost=null;
    public function request(string $method,string $url,mixed $body):array
    {
        $path=(string)parse_url($url,PHP_URL_PATH);$data=[];
        if($method==='GET')$this->gets++;
        if(str_ends_with($path,'/goals')){
            if($method==='POST'){
                $this->creates++;$g=json_decode($body,true,32,JSON_THROW_ON_ERROR)['goal'];$g['id']='99001';$this->goals[]=$g;
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
    public bool $missing=false;
    public function lookup(string $counter,array $users):array
    {
        $out=[];if(!$this->missing)foreach($users as$user)$out[$user]=['external_user_id'=>$user,'client_id'=>'880000'.$user,'record_id'=>'77'];
        return $out;
    }
}
function modeledValueAssertions(PDO $p,callable $check):void
{
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
    $check('ambiguous Telegram identity stays pending',$wire->posts===$before);
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
    $invalid=$p->query("SELECT * FROM max_channel_conversion_outbox ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);$invalid['value_rub']='25001';$blocked=false;
    try{MetrikaOfflineClient::modeledValueCsv($invalid,$now+2503);}catch(InvalidArgumentException $e){$blocked=true;}
    $check('wire rejects amounts over agreed cap',$blocked);
    $result=$control->configure('status',$api,$now+2504);$json=json_encode($result,JSON_THROW_ON_ERROR);
    $check('operational readback contains no people refs amounts or receipts',!str_contains($json,'70101')&&!str_contains($json,'source_ref')&&!str_contains($json,'value_rub')&&!str_contains($json,'upload_id'));

    modeledValueSchema($p);$wire=new ModeledValueTestWire();$wire->createUnknown=true;$api=$wire->api();$control=new ModeledConversionValueExport($p);
    $failed=false;try{$control->configure('create-goal',$api,$now);}catch(Throwable $e){$failed=true;}
    $check('ambiguous goal creation is durably held',$failed&&ModeledConversionValueExport::settings($p)['goal_intent']==='unknown'&&$wire->creates===1);
    $control->configure('create-goal',$api,$now+1);
    $check('lost goal ack is recovered by GET without duplicate creation',ModeledConversionValueExport::settings($p)['goal_intent']==='verified'&&$wire->creates===1);
    $wire->goals[1]['name']='operator change';$blocked=false;
    try{$control->configure('enable',$api,$now+2);}catch(Throwable $e){$blocked=true;}
    $check('operator goal changes prevent activation',$blocked&&ModeledConversionValueExport::settings($p)['mode']==='off');
    $blocked=false;try{$control->configure('arbitrary-operation',$api,$now);}catch(Throwable $e){$blocked=true;}
    $check('operational surface rejects arbitrary commands',$blocked);
}
if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    $passed=0;$check=static function(string $name,bool $ok)use(&$passed){if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;};
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    modeledValueAssertions($p,$check);echo 'MODELED VALUE EXPORT PASSED '.$passed.PHP_EOL;
}
