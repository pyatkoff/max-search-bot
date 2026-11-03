<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/run_modeled_conversion_value_export_regression.php';
require_once dirname(__DIR__).'/services/ModeledConversionDeliveryRegistry.php';

function deliveryRegistrySchema(PDO $p):void
{
    modeledValueSchema($p);
    $p->exec('DROP TABLE IF EXISTS customers');
    $p->exec('CREATE TABLE customers(id BIGINT PRIMARY KEY,display_name VARCHAR(1024))');
}
function deliveryRegistryFixtureEvent(PDO $p,int $conversation,int $rank,int $at,array $options=[]):string
{
    $q=$p->prepare('SELECT channel FROM conversations WHERE id=?');$q->execute([$conversation]);$platform=$q->fetchColumn();
    if($platform===false){
        $platform=$options['platform']??($conversation%2===0?'max':'telegram');
        modeledValueBinding($p,$conversation,$platform,$options['test']??0);
        $p->prepare('INSERT INTO customers VALUES(?,?)')->execute([$conversation,$options['name']??'Турист '.$conversation]);
    }
    $subject=['project_key'=>'anytour','platform'=>$platform,'customer_key'=>(string)$conversation,'conversation_id'=>(string)$conversation];
    $profileAt=$options['profile_at']??$at;
    $wrapper=['access_status'=>'allowed','subject'=>$subject,'profile'=>['prediction_at'=>gmdate('Y-m-d\TH:i:s\Z',$profileAt)]];
    if(!empty($options['bad_profile']))$wrapper['subject']['customer_key']='999999';
    $p->prepare('INSERT INTO modeled_conversion_profile_versions(project_key,platform,customer_key,conversation_id,observed_at_unix,profile_json) VALUES(?,?,?,?,?,?)')
        ->execute(['anytour',$platform,(string)$conversation,$conversation,$profileAt,json_encode($wrapper,JSON_THROW_ON_ERROR)]);
    $profile=(string)$p->lastInsertId();$state=$options['state']??'pending';
    $receipt=in_array($state,['accepted','processed'],true);
    $columns=['project_key','conversation_id','external_user_id','counter_id','target','transport_target','event_unix','platform',
        'value_rub','value_profile_id','value_stage','value_contract','value_origin','state','client_id','upload_id','upload_status',
        'last_error','value_ready_at_unix','claimed_at_unix','value_api_accepted_at_unix','value_processed_at_unix','value_receipt_basis'];
    $values=['anytour',$conversation,(string)(70000+$conversation),'98615635',ModeledConversionValueContract::key($rank),
        ModeledConversionValueContract::TARGET,$at,$platform,$options['value']??ModeledConversionValueContract::VALUES[$rank],
        $profile,ModeledConversionValueContract::STAGES[$rank],ModeledConversionValueContract::VERSION,$options['origin']??null,$state,
        $receipt?'fixturePrivateClientIdentifier':null,$receipt?'fixtureUploadId':null,$state==='processed'?'PROCESSED':($receipt?'UPLOADED':null),
        $options['error']??'', $receipt?$at+1:null,$receipt?$at+2:null,
        !empty($options['legacy_clock'])?null:($receipt?$at+3:null),$state==='processed'?$at+4:null,
        !empty($options['legacy_clock'])?null:($receipt?'post_response':null)];
    $p->prepare('INSERT INTO max_channel_conversion_outbox('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')')->execute($values);
    return(string)$p->lastInsertId();
}
function deliveryRegistryDataDigest(PDO $p):string
{
    $data=[];foreach(['customers','customer_channels','conversations','modeled_conversion_profile_versions','max_channel_conversion_outbox']as$table)
        $data[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
}
function deliveryRegistryAssertions(PDO $p,callable $check):void
{
    deliveryRegistrySchema($p);
    $date='2026-11-02';$day=new DateTimeImmutable($date.' 00:00:00',new DateTimeZone('Europe/Kaliningrad'));
    $from=$day->getTimestamp();$until=$day->modify('+1 day')->getTimestamp();$now=$until+3600;
    $key=str_repeat('fixture-session-key-only-',3);$auth=static function():void{};
    for($id=10;$id<=66;$id++){
        $options=[];
        if($id===10)$options=['state'=>'accepted','legacy_clock'=>true,'name'=>'Турист <img src=x onerror=alert(1)>'];
        if($id===11)$options=['state'=>'processed'];
        if($id===12)$options=['state'=>'unknown','error'=>'upload_outcome_unknown'];
        if($id===13)$options=['origin'=>'owner_fortnight_review_v1','profile_at'=>$from-86400];
        if($id===14)$options=['bad_profile'=>true];
        deliveryRegistryFixtureEvent($p,$id,1,$from+$id,$options);
    }
    deliveryRegistryFixtureEvent($p,100,1,$from-1,['platform'=>'telegram']);
    deliveryRegistryFixtureEvent($p,100,2,$from+100,['value'=>833]);
    deliveryRegistryFixtureEvent($p,101,1,$from+101,['platform'=>'max']);
    deliveryRegistryFixtureEvent($p,101,2,$from+102,['value'=>833]);
    deliveryRegistryFixtureEvent($p,1,1,$from+1,['platform'=>'telegram']);
    deliveryRegistryFixtureEvent($p,2,1,$from+2,['platform'=>'max']);
    deliveryRegistryFixtureEvent($p,200,1,$from+200);
    $p->exec("UPDATE max_channel_conversion_outbox SET external_user_id='fixtureWrongPeer' WHERE conversation_id=200");
    deliveryRegistryFixtureEvent($p,201,1,$from+201);
    $p->exec("UPDATE max_channel_conversion_outbox SET counter_id='other' WHERE conversation_id=201");
    deliveryRegistryFixtureEvent($p,202,1,$from+202);
    $p->exec("UPDATE max_channel_conversion_outbox SET value_contract='foreign_contract' WHERE conversation_id=202");
    deliveryRegistryFixtureEvent($p,203,1,$from+203);
    $p->exec("UPDATE max_channel_conversion_outbox SET transport_target='foreign_target' WHERE conversation_id=203");
    deliveryRegistryFixtureEvent($p,204,1,$from+204);
    $p->exec("UPDATE max_channel_conversion_outbox SET project_key='foreign' WHERE conversation_id=204");
    deliveryRegistryFixtureEvent($p,205,1,$from+205,['test'=>1]);
    deliveryRegistryFixtureEvent($p,206,1,$from-1);
    deliveryRegistryFixtureEvent($p,207,1,$until);
    deliveryRegistryFixtureEvent($p,208,1,$from+208);
    $p->exec("UPDATE customer_channels SET external_chat_id='wrong-private-peer' WHERE id=208");
    $read=static fn(array $input=[]):array=>(new ModeledConversionDeliveryRegistry($p,$auth,$key))->read(['date'=>$date]+$input,$now);
    $before=deliveryRegistryDataDigest($p);$first=$read();
    if(($first['available']??false)!==true){
        $reason=in_array($first['reason']??null,['invalid_request','invalid_cursor','storage_unavailable','invalid_ledger','read_limit'],true)?$first['reason']:'unknown';
        echo 'REGISTRY SYNTHETIC FIRST READ UNAVAILABLE reason='.$reason.PHP_EOL;
    }
    $check('registry is an actual recorded-value read with an explicit event-day basis',
        $first['available']&&$first['basis']==='saved_value_event_unix'&&$first['timezone']==='Europe/Kaliningrad');
    $check('same-scope exact day summary counts sixty deltas and fifty-nine conversations beyond one page',
        $first['summary']['events']===60&&$first['summary']['conversations']===59&&$first['summary']['value_rub']===74166);
    $check('Kaliningrad half-open midnight window preserves the correct UTC boundaries',
        $first['window']['from']===gmdate('Y-m-d\TH:i:s\Z',$from)&&$first['window']['until_exclusive']===gmdate('Y-m-d\TH:i:s\Z',$until));
    $check('history is a direct origin subset and not a different sending day',
        $first['summary']['historical_review']===['events'=>1,'conversations'=>1,'value_rub'=>1250]
        &&$first['summary']['live']['value_rub']===72916);
    $check('delivery cards are global summary values rather than the twenty-five shown rows',
        $first['summary']['delivery']['accepted_receipts']['events']===2
        &&$first['summary']['delivery']['accepted_receipts']['value_rub']===2500
        &&$first['summary']['delivery']['processed']['events']===1
        &&$first['summary']['delivery']['problem']['events']===1
        &&$first['summary']['delivery']['unresolved_identity']['events']===58);
    $check('first page is twenty-five distinct conversations with a signed continuation',
        count($first['rows'])===25&&$first['pagination']['has_more']&&is_string($first['pagination']['next_cursor']));
    $all=[];$sum=0;$page=$first;$pages=0;
    do{
        ++$pages;foreach($page['rows']as$row){$all[$row['conversation_id']]=$row;$sum+=$row['period_value_rub'];}
        $cursor=$page['pagination']['next_cursor'];
        if($cursor!==null)$page=$read(['cursor'=>$cursor]);
    }while($cursor!==null&&$pages<10);
    $check('all pages reconcile exactly without duplicates or a four-dialogue ceiling',
        $pages===3&&count($all)===59&&$sum===$first['summary']['value_rub']);
    $check('period delta is separate from same-contract lifetime reserved value',
        $all['100']['period_value_rub']===833&&$all['100']['total_value_rub']===2083
        &&$all['100']['stage']==='specific_request'&&count($all['100']['events'])===2
        &&$all['100']['events'][0]['in_selected_day']===false);
    $check('several increments in one conversation remain one row with their actual deltas',
        $all['101']['period_value_rub']===2083&&count($all['101']['events'])===2
        &&array_column($all['101']['events'],'value_rub')===[1250,833]);
    $check('old API receipt keeps an explicitly unknown acknowledgement clock',
        $all['10']['events'][0]['api_receipt_present']===true&&$all['10']['events'][0]['api_accepted_at']===null);
    $check('processed row preserves its independently recorded lifecycle moments',
        $all['11']['events'][0]['processed_at']!==null&&$all['11']['events'][0]['api_accepted_at']!==null
        &&$all['11']['events'][0]['upload_status']==='PROCESSED');
    $check('grounds reference the exact bound saved profile rather than the latest profile',
        $all['100']['events'][0]['profile_id']!==$all['100']['events'][1]['profile_id']
        &&$all['100']['events'][0]['profile_saved_at']===gmdate('Y-m-d\TH:i:s\Z',$from-1)
        &&$all['100']['events'][1]['grounds_status']==='saved_stage_profile');
    $check('invalid frozen subject withholds grounds without fabricating another explanation',
        $all['14']['events'][0]['grounds_status']==='profile_unavailable'&&$all['14']['events'][0]['profile_saved_at']===null);
    $serialized=json_encode($all,JSON_THROW_ON_ERROR);
    $check('no advertising identifiers upload identifiers native peers or raw JSON enter the projection',
        !str_contains($serialized,'fixturePrivateClientIdentifier')&&!str_contains($serialized,'fixtureUploadId')
        &&!str_contains($serialized,'external_user_id')&&!str_contains($serialized,'profile_json'));
    $check('reader returns names as bounded data for the HTML boundary to escape',
        $all['10']['customer_name']==='Турист <img src=x onerror=alert(1)>');
    $max=$read(['platform'=>'max']);$tg=$read(['platform'=>'telegram']);
    $check('platform filters have independent exact denominators',
        $max['summary']['conversations']===30&&$tg['summary']['conversations']===29
        &&$max['summary']['value_rub']+$tg['summary']['value_rub']===$first['summary']['value_rub']);
    $check('owner test foreign counter contract target project and mismatched peers are excluded consistently',
        !array_intersect(array_keys($all),['1','2','200','201','202','203','204','205','206','207','208']));
    $empty=(new ModeledConversionDeliveryRegistry($p,$auth,$key))->read(['date'=>'2026-10-01'],$now);
    $check('empty successful date is distinct from unavailable storage',
        $empty['available']&&$empty['summary']['events']===0&&$empty['rows']===[]&&!$empty['pagination']['has_more']);
    foreach([
        ['date'=>'2026-02-30'],['date'=>'2027-01-01'],['platform'=>'website'],['date'=>['2026-11-02']],['unexpected'=>'x']
    ]as$bad){
        $r=(new ModeledConversionDeliveryRegistry($p,$auth,$key))->read($bad,$now);
        $check('strict date channel and parameter admission rejects malformed or unsupported requests',$r===['available'=>false,'reason'=>'invalid_request']);
    }
    $cursor=$first['pagination']['next_cursor'];
    $check('edited signed cursor is rejected before releasing any result',
        ($read(['cursor'=>substr($cursor,0,-1).(str_ends_with($cursor,'0')?'1':'0')])['reason']??null)==='invalid_cursor');
    $check('cursor is bound to the selected platform',($read(['cursor'=>$cursor,'platform'=>'telegram'])['reason']??null)==='invalid_cursor');
    $check('cursor is bound to the selected day',
        ((new ModeledConversionDeliveryRegistry($p,$auth,$key))->read(['date'=>'2026-11-01','cursor'=>$cursor],$now)['reason']??null)==='invalid_cursor');
    $check('cursor is bound to the current actor session key',
        ((new ModeledConversionDeliveryRegistry($p,$auth,str_repeat('other-session-',4)))->read(['date'=>$date,'cursor'=>$cursor],$now)['reason']??null)==='invalid_cursor');
    $check('old continuation expires rather than silently refreshing the snapshot',
        ((new ModeledConversionDeliveryRegistry($p,$auth,$key))->read(['date'=>$date,'cursor'=>$cursor],$now+3601)['reason']??null)==='invalid_cursor');
    $check('all successful private reads leave canonical rows byte-identical',deliveryRegistryDataDigest($p)===$before&&!$p->inTransaction());
    deliveryRegistryFixtureEvent($p,300,1,$from+600);
    deliveryRegistryFixtureEvent($p,10,3,$from+601,['value'=>2917]);
    $continued=$read(['cursor'=>$cursor]);$fresh=$read();
    $check('continuation pins its original membership and financial summary when new rows arrive',
        $continued['summary']===$first['summary']&&$fresh['summary']['events']===62
        &&$fresh['summary']['value_rub']===$first['summary']['value_rub']+4167);
    $p->exec("UPDATE max_channel_conversion_outbox SET last_error='fixturePrivateUnclassifiedError' WHERE conversation_id=12");
    $page=$read();$rows=$page['rows'];while($page['pagination']['next_cursor']!==null){
        $page=$read(['cursor'=>$page['pagination']['next_cursor']]);$rows=[...$rows,...$page['rows']];
    }
    $found=null;foreach($rows as$row)if($row['conversation_id']==='12')$found=$row['events'][0];
    $check('unrecognized transport details never become a public error label',
        $found['error']==='unclassified_error'&&!str_contains(json_encode($rows,JSON_THROW_ON_ERROR),'fixturePrivateUnclassifiedError'));
    $revoked=false;$outside=0;
    try{(new ModeledConversionDeliveryRegistry($p,static function()use($p,&$outside):void{
        if(!$p->inTransaction()&&++$outside>1)throw new DomainException('fixture_revoked');
    },$key))->read(['date'=>$date],$now);}catch(DomainException $ignored){$revoked=true;}
    $check('fresh authorization after the read transaction can revoke the entire private result',$revoked&&!$p->inTransaction());
    $time=0.0;$limited=(new ModeledConversionDeliveryRegistry($p,$auth,$key,static function()use(&$time):float{return $time+=2.0;}))->read(['date'=>$date],$now);
    $check('bounded execution fails closed and releases its read transaction',
        $limited===['available'=>false,'reason'=>'read_limit']&&!$p->inTransaction());
    // Native case-insensitive column collations must not admit enum variants on an unseen page.
    $p->exec("UPDATE max_channel_conversion_outbox SET value_stage='Interest' WHERE conversation_id=10 AND target='anytour_value_1_v1'");
    $caseVariant=$read();
    $check('capitalized stage on a later page invalidates first-page totals on every SQL collation',
        $caseVariant===['available'=>false,'reason'=>'invalid_ledger']&&!$p->inTransaction());
    $p->exec("UPDATE max_channel_conversion_outbox SET value_stage='interest' WHERE conversation_id=10 AND target='anytour_value_1_v1'");
    // Conversation 10 is below the first page; a bad row must invalidate GLOBAL totals before any page is released.
    $p->exec("UPDATE max_channel_conversion_outbox SET value_stage='unknown_stage' WHERE conversation_id=10 AND target='anytour_value_1_v1'");
    $offPageInvalid=$read();
    $check('invalid stage on a later page withholds the first page and its complete-day totals',
        $offPageInvalid===['available'=>false,'reason'=>'invalid_ledger']&&!$p->inTransaction());
    $p->exec("UPDATE max_channel_conversion_outbox SET value_stage='interest' WHERE conversation_id=10 AND target='anytour_value_1_v1'");
    $p->exec("UPDATE max_channel_conversion_outbox SET value_stage='unknown_stage' WHERE conversation_id=300");
    $invalid=$read();
    $check('an invalid recorded stage does not get silently converted into a valid award',
        $invalid===['available'=>false,'reason'=>'invalid_ledger']&&!$p->inTransaction());
    $p->exec('DROP TABLE max_channel_conversion_outbox');$missing=$read();
    $check('storage failure is unavailable rather than an empty successful day',$missing===['available'=>false,'reason'=>'storage_unavailable']);
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $passed=0;$check=static function(string $name,bool $ok)use(&$passed):void{
        if(!$ok)throw new RuntimeException('FAIL '.$name);++$passed;echo 'PASS '.$name.PHP_EOL;
    };
    deliveryRegistryAssertions($p,$check);echo 'MODELED DELIVERY REGISTRY PASSED '.$passed.PHP_EOL;
}
