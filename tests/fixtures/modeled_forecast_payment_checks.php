<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/services/ArchiveResearchSemanticExtractor.php';
require_once dirname(__DIR__,2).'/services/ModeledConversionSemanticPrediction.php';
require_once dirname(__DIR__,2).'/services/ModeledConversionForecastPaymentReader.php';
require_once dirname(__DIR__,2).'/services/ModeledConversionForecastPaymentView.php';

/** Synthetic-only fixtures for both SQLite memory and the registered disposable MySQL suite. */
function forecastPaymentFixtureSchema(PDO $p):void
{
    $driver=$p->getAttribute(PDO::ATTR_DRIVER_NAME);$mysql=$driver==='mysql';
    if($mysql){
        if($p->query('SELECT DATABASE()')->fetchColumn()!=='maxbot_modeled_stream_test')throw new RuntimeException('unsafe_fixture_database');
    }elseif($driver==='sqlite'){
        foreach($p->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC)as$db)
            if(($db['name']??null)==='main'&&($db['file']??'')!=='')throw new RuntimeException('unsafe_fixture_database');
    }else throw new RuntimeException('unsafe_fixture_driver');
    if($p->inTransaction())throw new RuntimeException('fixture_outer_transaction');
    foreach(['modeled_conversion_profile_versions','conversations','customer_channels','customers']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    $auto=$mysql?'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $p->exec("CREATE TABLE customers(id BIGINT PRIMARY KEY,display_name VARCHAR(1024))");
    $p->exec("CREATE TABLE customer_channels(id BIGINT PRIMARY KEY,customer_id BIGINT,project_key VARCHAR(64),channel VARCHAR(16),external_user_id VARCHAR(20),external_chat_id VARCHAR(32))");
    $p->exec("CREATE TABLE conversations(id BIGINT PRIMARY KEY,customer_id BIGINT,customer_channel_id BIGINT,project_key VARCHAR(64),channel VARCHAR(16),is_test INT,external_chat_id VARCHAR(32))");
    $p->exec("CREATE TABLE modeled_conversion_profile_versions(id $auto,project_key VARCHAR(64),platform VARCHAR(16),customer_key VARCHAR(20),conversation_id VARCHAR(40),journal_cursor VARCHAR(20),observed_at_unix BIGINT,input_sha256 VARCHAR(64),profile_json ".($mysql?'JSON':'TEXT').")");
}
function forecastPaymentFixtureBinding(PDO $p,int $id,string $platform='max',string $name='Турист',int $test=0):array
{
    $user=(string)(900000+$id);$chat=$platform==='max'?'-'.$user:$user;
    $p->prepare("INSERT INTO customers VALUES(?,?)")->execute([$id,$name]);
    $p->prepare("INSERT INTO customer_channels VALUES(?,?,'anytour',?,?,?)")->execute([$id,$id,$platform,$user,$chat]);
    $p->prepare("INSERT INTO conversations VALUES(?,?,?,'anytour',?,?,?)")->execute([$id,$id,$id,$platform,$test,$chat]);
    return['project_key'=>'anytour','platform'=>$platform,'customer_key'=>(string)$id,'conversation_id'=>(string)$id];
}
function forecastPaymentFixtureWrapper(array $s,int $clock,int $anchorAt,string $mode='request',int $fence=100):array
{
    $stamp=gmdate('Y-m-d\TH:i:s\Z',$clock);$cutoff=gmdate('Y-m-d\TH:i:s\Z',$clock-1);
    $model=new ModeledConversionSemanticPrediction($s,$stamp,$cutoff,$stamp,(string)$fence,(string)$fence);
    $state=ArchiveResearchSemanticExtractor::initialState();
    $messages=[['customer','Подберите тур в Турцию на 7 ночей.','journal:10',$anchorAt,1]];
    if($mode==='intent')$messages[]=['customer','Купим тур только у вас.','journal:110',$clock-10,1];
    if($mode==='payment')$messages[]=['customer','Оплатили тур.','journal:150',$clock-10,1];
    if($mode==='edited_payment')$messages[]=['customer','Оплатили тур.','journal:150',$clock-10,2];
    if($mode==='documents')$messages[]=['manager','Документы готовы.','journal:150',$clock-10,1];
    foreach($messages as[$role,$text,$ref,$at,$version]){
        $r=ArchiveResearchSemanticExtractor::step(['source_ref'=>$ref,'created_at'=>$at,'read_status'=>'available',
            'text'=>$text,'role'=>$role,'direction'=>$role==='customer'?'inbound':'outbound','role_verified'=>true,
            'text_minimized'=>true,'forwarded'=>false,'media'=>[],'media_contents_read'=>false,
            'selection_links_unread'=>false,'links_redacted'=>false],$state);
        $state=$r['state'];$model->observeMessage($role,$text,$ref,$at,$at+1,$version,$r);
    }
    if($mode==='crm')$model->knownOperationalOutcome('journal:150',$clock-10,$clock-9,1);
    $block=$model->finish(['early_forecast'=>['status'=>'early_assessment','early_quality'=>[
        'version'=>'request_offer_reaction_v2','specific_option_discussions'=>[]]]]);
    return['subject'=>$s,'access_status'=>'allowed','profile'=>['prediction_at'=>$stamp],
        'processing'=>['version'=>'admitted_journal_profiles_v1_2','source_sha256'=>str_repeat('a',64),
            'journal_cursor'=>(string)$fence,'source_fence_journal_id'=>(string)$fence],
        'early_forecast'=>['version'=>'live_contextual_ordinal_v1','target_version'=>'purchase_readiness_30d_ordinal_v1'],
        'semantic_prediction'=>$block];
}
function forecastPaymentFixtureSave(PDO $p,array $w,?int $observed=null):string
{
    $s=$w['subject'];$json=json_encode($w,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $p->prepare("INSERT INTO modeled_conversion_profile_versions(project_key,platform,customer_key,conversation_id,journal_cursor,observed_at_unix,input_sha256,profile_json) VALUES(?,?,?,?,?,?,?,?)")
        ->execute([$s['project_key'],$s['platform'],$s['customer_key'],$s['conversation_id'],$w['processing']['journal_cursor'],
            $observed??strtotime($w['profile']['prediction_at']),hash('sha256',$json),$json]);
    return(string)$p->lastInsertId();
}
function forecastPaymentFixtureDigest(PDO $p):string
{
    $data=[];
    foreach(['customers','customer_channels','conversations','modeled_conversion_profile_versions']as$table)
        $data[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
}
function forecastPaymentAssertions(PDO $p,callable $check):void
{
    $now=strtotime('2026-11-05T12:00:00Z');$key=str_repeat('fixture-key-',4);$authCalls=0;
    $auth=static function()use(&$authCalls):void{$authCalls++;};
    $reader=new ModeledConversionForecastPaymentReader($p,$auth,$key);
    $filters=['date'=>'2026-11-05','period'=>90,'platform'=>'all'];
    forecastPaymentFixtureSchema($p);
    try{
        $s=forecastPaymentFixtureBinding($p,81001,'max','<script>alert("synthetic")</script>');
        $base=forecastPaymentFixtureWrapper($s,$now-14400,$now-14500);
        $admission=ModeledConversionValueRegistry::predictionFields($base);
        $check('forecast synthetic producer block passes shared admission',($admission['semantic_prediction']['availability']??null)==='available');
        $baselineId=forecastPaymentFixtureSave($p,$base);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($s,$now-10800,$now-14500,'intent',120));
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($s,$now-3600,$now-14500,'payment',200));
        $tg=forecastPaymentFixtureBinding($p,81002,'telegram');
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($tg,$now-3500,$now-3600));
        $mature=forecastPaymentFixtureBinding($p,81003);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($mature,$now-35*86400,$now-35*86400-100));
        $clock=forecastPaymentFixtureBinding($p,81004);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($clock,$now-5000,$now-5100),$now-5001);
        $clockGood=forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($clock,$now-4000,$now-5100,'request',110));
        $tampered=forecastPaymentFixtureBinding($p,81005);
        $bad=forecastPaymentFixtureWrapper($tampered,$now-5000,$now-5100);$bad['semantic_prediction']['estimated_probability']=0.99;
        forecastPaymentFixtureSave($p,$bad);
        $tamperGood=forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($tampered,$now-4000,$now-5100,'request',110));
        $peer=forecastPaymentFixtureBinding($p,81006);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($peer,$now-3500,$now-3600));
        $p->exec("UPDATE customer_channels SET external_chat_id='-99999999' WHERE id=81006");
        $test=forecastPaymentFixtureBinding($p,81007,'max','Synthetic test',1);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($test,$now-3500,$now-3600));
        $crm=forecastPaymentFixtureBinding($p,81008);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($crm,$now-5000,$now-5100));
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($crm,$now-4000,$now-5100,'crm',200));
        $doc=forecastPaymentFixtureBinding($p,81009);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($doc,$now-5000,$now-5100));
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($doc,$now-4000,$now-5100,'documents',200));
        $revoked=forecastPaymentFixtureBinding($p,81010);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($revoked,$now-5000,$now-5100));
        $deny=forecastPaymentFixtureWrapper($revoked,$now-4000,$now-5100);$deny['access_status']='denied';
        forecastPaymentFixtureSave($p,$deny);
        $edited=forecastPaymentFixtureBinding($p,81011);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($edited,$now-5000,$now-5100));
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($edited,$now-4000,$now-5100,'edited_payment',200));
        $old=forecastPaymentFixtureBinding($p,81012);
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($old,$now-45*86400,$now-45*86400-100));
        forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($old,$now-4000,$now-45*86400-100,'request',110));
        $before=forecastPaymentFixtureDigest($p);$all=[];$input=$filters;$pages=0;$syntheticPages=[];
        do{
            $page=$reader->read($input,$now);$pages++;
            $syntheticPages[]=['available'=>$page['available']??null,'reason'=>$page['reason']??null,'coverage'=>$page['coverage']??null,'has_more'=>$page['has_more']??null];
            if(($page['available']??false)!==true)fwrite(STDERR,'SYNTHETIC_ONLY_FORECAST_PAGES '.json_encode($syntheticPages,JSON_THROW_ON_ERROR).PHP_EOL);
            $check('forecast payment page available '.$pages,$page['available']===true);
            if(!$page['available'])break;
            foreach($page['rows']as$row)$all[]=$row;
            $check('forecast payment page shape '.$pages,count($page['rows'])<=8&&$page['coverage']['scanned_profiles']<=128
                &&$page['coverage']['returned_rows']===count($page['rows'])&&$page['coverage']['scanned_prefix_complete']===!$page['has_more']);
            $input=$filters+($page['has_more']?['cursor'=>$page['next_cursor']]:[]);
        }while($page['has_more']&&$pages<10);
        $by=[];foreach($all as$row)$by[$row['conversation_id']]=$row;
        $earliestOnce=!($page['has_more']??true)&&count($all)===count($by)&&count(array_filter($all,static fn(array $r):bool=>$r['conversation_id']==='81001'))===1;
        if(!$earliestOnce)fwrite(STDERR,'SYNTHETIC_ONLY_FORECAST_PAGES '.json_encode($syntheticPages,JSON_THROW_ON_ERROR).PHP_EOL);
        $check('forecast earliest baseline occurs once across all pages',$earliestOnce);
        $first=$by['81001']??[];
        $check('forecast immutable first estimate survives stronger later stage',($first['profile_id']??null)===$baselineId
            &&($first['forecast']['estimated_probability']??null)===$base['semantic_prediction']['estimated_probability']
            &&($first['forecast']['prediction_digest']??null)===$base['semantic_prediction']['prediction_digest']);
        $check('forecast real comparator admits later synthetic self report',($first['followup']['status']??null)==='observed'
            &&($first['followup']['evidence'][0]['label']??null)==='self_reported_payment'
            &&($first['followup']['prior_prediction_digest']??null)===$base['semantic_prediction']['prediction_digest']
            &&($first['verified_payment']??null)===false&&($first['evaluation_eligible']??null)===false);
        $check('forecast native observed clock mismatch cannot set cohort',($by['81004']['profile_id']??null)===$clockGood
            &&($by['81004']['forecast']['invalid_earlier_profiles']['clock_or_fence']??null)===1);
        $check('forecast tampered digest is skipped with visible coverage',($by['81005']['profile_id']??null)===$tamperGood
            &&($by['81005']['forecast']['invalid_earlier_profiles']['prediction_admission']??null)===1);
        $check('forecast mature absence remains unknown',($by['81003']['maturity']['state']??null)==='elapsed_coverage_unknown'
            &&($by['81003']['followup']['status']??null)==='unknown'&&array_key_exists('payment_label',$by['81003']['followup'])
            &&$by['81003']['followup']['payment_label']===null);
        $check('forecast CRM win and documents are not payment',($by['81008']['followup']['status']??null)==='unknown'
            &&($by['81008']['followup']['reason']??null)==='non_payment_outcome_only'
            &&($by['81009']['followup']['status']??null)==='unknown');
        $check('forecast edited payment stays unknown',($by['81011']['followup']['status']??null)==='unknown'
            &&($by['81011']['followup']['reason']??null)==='edited_outcome_source');
        $check('forecast native peer test and revoked access excluded',!isset($by['81006'])&&!isset($by['81007'])&&!isset($by['81010']));
        $tgPage=$reader->read(['date'=>'2026-11-05','period'=>90,'platform'=>'telegram'],$now);
        $check('forecast platform filter isolates Telegram',($tgPage['available']??false)&&count($tgPage['rows'])===1
            &&$tgPage['rows'][0]['conversation_id']==='81002'&&$tgPage['rows'][0]['platform']==='telegram');
        $short=$reader->read(['date'=>'2026-11-05','period'=>7,'platform'=>'all'],$now);$shortIds=array_column($short['rows']??[],'conversation_id');
        $shortPages=1;while(($short['has_more']??false)===true&&$shortPages++<10){
            $short=$reader->read(['date'=>'2026-11-05','period'=>7,'platform'=>'all','cursor'=>$short['next_cursor']],$now);
            $shortIds=[...$shortIds,...array_column($short['rows']??[],'conversation_id')];
        }
        $check('forecast global earliest before date filter prevents cohort reentry',($short['available']??false)&&!($short['has_more']??true)&&!in_array('81012',$shortIds,true));
        $view=ModeledConversionForecastPaymentView::render($tgPage,['date'=>'2026-11-05','period'=>90,'platform'=>'telegram']);
        $check('forecast owner view renders safe useful fields',str_contains($view,'Ранний прогноз')&&str_contains($view,'/manager2/#conversation=81002'));
        $namePage=$reader->read($filters,$now);$html=ModeledConversionForecastPaymentView::render($namePage,$filters);
        $check('forecast customer name is escaped in private view',!str_contains($html,'<script>alert(')
            &&str_contains($html,'&lt;script&gt;')&&str_contains($html,'/manager2/#conversation=81001'));
        $check('forecast read and pure view do not write fixtures',forecastPaymentFixtureDigest($p)===$before&&!$p->inTransaction()&&$authCalls>10);

        forecastPaymentFixtureSchema($p);$baselineIds=[];
        for($n=1;$n<=10;$n++){
            $s=forecastPaymentFixtureBinding($p,82000+$n,$n%2?'max':'telegram');
            $baselineIds[]=forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($s,$now-5000,$now-5100));
            forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($s,$now-4000,$now-5100,'intent',120));
        }
        $firstPage=$reader->read($filters,$now);$cursor=$firstPage['next_cursor']??null;
        $check('forecast stable paging exposes bounded continuation',($firstPage['available']??false)&&count($firstPage['rows'])===8&&is_string($cursor)&&$firstPage['has_more']===true);
        if(is_string($cursor)){
            $late=forecastPaymentFixtureBinding($p,82011);
            forecastPaymentFixtureSave($p,forecastPaymentFixtureWrapper($late,$now-5000,$now-5100));
            $secondPage=$reader->read($filters+['cursor'=>$cursor],$now+10);
            $joined=[...$firstPage['rows'],...($secondPage['rows']??[])];$ids=array_column($joined,'profile_id');
            $check('forecast cursor freezes asof fence and deduplicates late versions',($secondPage['available']??false)
                &&count($ids)===10&&count(array_unique($ids))===10&&array_diff($ids,$baselineIds)===[]
                &&($secondPage['as_of']??null)===$firstPage['as_of']);
            $tampered=substr($cursor,0,-1).(substr($cursor,-1)==='0'?'1':'0');
            $bad=$reader->read($filters+['cursor'=>$tampered],$now+10);
            $check('forecast forged continuation rejected',($bad['available']??true)===false&&($bad['reason']??null)==='invalid_cursor');
            $bad=$reader->read(['date'=>'2026-11-05','period'=>7,'platform'=>'all','cursor'=>$cursor],$now+10);
            $check('forecast cursor cannot change cohort',($bad['available']??true)===false&&($bad['reason']??null)==='invalid_cursor');
            $bad=(new ModeledConversionForecastPaymentReader($p,$auth,str_repeat('another-key-',4)))->read($filters+['cursor'=>$cursor],$now+10);
            $check('forecast cursor is bound to owner secret',($bad['available']??true)===false);
            $bad=$reader->read($filters+['cursor'=>$cursor],$now+3601);
            $check('forecast cursor expires closed',($bad['available']??true)===false&&($bad['reason']??null)==='invalid_cursor');
        }
        $bad=$reader->read($filters+['unknown'=>'x'],$now);
        $check('forecast unexpected input rejected',($bad['available']??true)===false&&($bad['reason']??null)==='invalid_filters');
        $denied=false;try{(new ModeledConversionForecastPaymentReader($p,static function():void{throw new RuntimeException('denied');},$key))->read($filters,$now);}catch(RuntimeException $e){$denied=true;}
        $check('forecast authorization fails before reading data',$denied&&!$p->inTransaction());
        $p->beginTransaction();$bad=$reader->read($filters,$now);$still=$p->inTransaction();$p->rollBack();
        $check('forecast reader rejects and preserves caller transaction',($bad['reason']??null)==='outer_transaction'&&$still);
        $p->exec('DROP TABLE modeled_conversion_profile_versions');$bad=$reader->read($filters,$now);
        $check('forecast missing storage is unavailable rather than empty success',($bad['available']??true)===false&&($bad['reason']??null)==='storage_unavailable'&&!$p->inTransaction());
    }finally{
        if($p->inTransaction())$p->rollBack();
        foreach(['modeled_conversion_profile_versions','conversations','customer_channels','customers']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    }
}
