<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../../services/HotelTransferPriceLineage.php';
function lineJson(array $v):string {return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function lineAssert(bool $ok,string $case):void {if(!$ok)throw new RuntimeException($case);echo "PASS $case\n";}
function lineReject(callable $f,string $case):void {try{$f();}catch(RuntimeException|JsonException $e){lineAssert(true,$case);return;}throw new RuntimeException('unexpected admission: '.$case);}
(static function():void {
$token='p_'.str_repeat('x',24);$url='https://t.me/anyru_bot?start='.$token;
$template="✈️ Туры в Fixture Hotel\n\nСемейный отдых у моря.\n\n💰 [Минимальная актуальная цена тура — подтвердить перед выпуском]\n[Город вылета · даты · ночи · состав туристов · номер · питание · время проверки]\n\nУзнать стоимость тура";
$baseText="✈️ Туры в Fixture Hotel\n\nСемейный отдых у моря.\n\nУзнать стоимость тура";
$binding=['tourvisor_hotel_id'=>42,'posts'=>['max'=>'https://max.ru/channel_anytour/fixture','telegram'=>'https://t.me/any_toour/51']];
$native=function(string $text,array $entities=[])use($url):array{
    return ['chat'=>['id'=>-1002262666616,'type'=>'channel','username'=>'any_toour'],'message_id'=>51,
        'date'=>1700000000,'caption'=>$text,'caption_entities'=>$entities,
        'photo'=>[['file_id'=>'FILE_ID_FIXTURE','file_unique_id'=>'PHOTO_UNIQUE_FIXTURE','width'=>800,'height'=>600]],
        'reply_markup'=>['inline_keyboard'=>[[['text'=>'✈️ Узнать стоимость тура','url'=>$url]]]]];
};
$version=function(int $id,array $message,?array $price=null)use($token):array{
    $s=ChannelPostIncomingAdapter::telegramSnapshot($message);
    $s['metadata']['hotel_catalog_id']=42;$s['metadata']['logical_id']='commercial-hotel-42-v1';
    if($price!==null){$s['metadata']['monitor_price_record_id']=$price['record_id'];
        $s['metadata']['monitor_price_basis']=$price['price_basis'];$s['metadata']['monitor_price_observed_at_relative']=$price['observed_at'];}
    return ['project_key'=>'anytour','platform'=>'telegram','bot_key'=>'anyru_bot','channel_id'=>'-1002262666616',
        'message_id'=>'51','publication_id'=>101,'version_id'=>$id,'version_number'=>[1001=>1,1003=>2,1008=>3][$id],'state'=>'sent','claim_token'=>null,'claimed_at'=>null,
        'start_payload'=>$token,'content_hash'=>ChannelPostIncomingAdapter::contentFingerprint($s),'snapshot'=>$s];
};
$first=$version(1001,$native($baseText));
$row=['hotel_id'=>42,'source_message_id'=>'51','source_publication_id'=>101,'source_version_id'=>1001,
    'source_content_hash'=>$first['content_hash'],'source_caption_sha256'=>hash('sha256',$baseText),
    'source_file_id_sha256'=>hash('sha256','FILE_ID_FIXTURE'),'source_file_unique_id_sha256'=>hash('sha256','PHOTO_UNIQUE_FIXTURE'),
    'target_message_id'=>'77','target_published_unixtime'=>1690000000,'target_caption_sha256'=>str_repeat('1',64),
    'target_photo_marker_sha256'=>str_repeat('2',64),'target_config_version'=>2,'next_caption'=>'Fixture Hotel — отдых у моря',
    'next_caption_sha256'=>hash('sha256','Fixture Hotel — отдых у моря'),'button_text'=>'✈️ Рассчитать на мои даты',
    'bot_url'=>'https://t.me/anyru_bot','price_template'=>'','price_record_id'=>null,'source_photo_marker_sha256'=>str_repeat('3',64)];
$intent=['schema'=>'telegram_hotel_transfer_intent_v2','price_policy'=>'omit_previous_price_keep_hotel_calculation_button',
    'row'=>$row,'source_price_template'=>$template,'source_start_payload'=>$token,'source_published_at'=>$first['snapshot']['published_at'],
    'source_logical_id'=>'commercial-hotel-42-v1','source_link'=>null,'feed_binding'=>$binding];
$bytes=lineJson($intent);$digest=hash('sha256',$bytes);
$run=fn(array $v,array $j)=>HotelTransferPriceLineage::candidate($bytes,$digest,$v,$j);
$build=function(int $previous,int $next,string $record,int $amount,int $generated)use($template,$binding,$native,$version,$url,$token):array{
    $stamp=fn(int $t)=>gmdate('Y-m-d\TH:i:s\Z',$t);
    $o=['price'=>$amount,'recordId'=>$record,'tourvisorHotelId'=>42,'departureId'=>1,'adults'=>2,'childrenCount'=>0,
        'currency'=>'RUB','nights'=>7,'tourId'=>'fixture','roomType'=>'Standard','mealId'=>7,'departureDate'=>'2026-12-01',
        'currentPriceVerified'=>false,'fullPackagePriceVerified'=>false,'availabilityVerified'=>false,'fuelIncluded'=>null,
        'source'=>'scheduled_monitor','priceKind'=>'listing_observation','ageSecondsAtExport'=>60,'expiresAt'=>$stamp($generated+900)];
    $snapshot=['format'=>'anytour-hotel-monitor-prices-v1','identityNamespace'=>'tourvisor','source'=>'scheduled_monitor',
        'observationWindowHours'=>24,'supplierRequests'=>0,'writes'=>0,
        'timeBasis'=>'database-relative; stored observation timezone is not asserted',
        'sourceUrl'=>'https://anytoour.ru/data/hotel-monitor-prices-read-v1.php','generatedAt'=>$stamp($generated),
        'scope'=>['tourvisorHotelIds'=>[42],'departureId'=>1,'adults'=>2,'childrenCount'=>0,'currency'=>'RUB',
            'nightsFrom'=>5,'nightsTo'=>14,'dateFrom'=>'2026-11-20','dateTo'=>'2026-12-31'],
        'item'=>['tourvisorHotelId'=>42,'state'=>'observed','offers'=>[$o]]];
    $at=$generated+120;$price=monitor_price_observation($snapshot,42,$at,true);
    $text=monitor_hook_draft($template,$price);
    preg_match('/^💰 (От [^\\n]+ ₽) за двоих$/mu',$text,$match,PREG_OFFSET_CAPTURE);
    [$label,$offset]=$match[1];
    $entities=[['type'=>'text_link','offset'=>(int)(strlen(mb_convert_encoding(substr($text,0,$offset),'UTF-16LE','UTF-8'))/2),
        'length'=>(int)(strlen(mb_convert_encoding($label,'UTF-16LE','UTF-8'))/2),'url'=>$url]];
    $message=$native($text,$entities);$v=$version($next,$message,$price);
    $receipt=['platform'=>'telegram','channel_id'=>'-1002262666616','publication_id'=>101,'previous_version_id'=>$previous,
        'version_id'=>$next,'message_id'=>'51','post_url'=>$binding['posts']['telegram'],'logical_id'=>'commercial-hotel-42-v1',
        'state'=>'sent','existing_message_reused'=>true,'body_photo_button_verified'=>true,'monitor_price'=>$price,'text'=>$text,
        'text_sha256'=>hash('sha256',$text),'photo_signature'=>'telegram:PHOTO_UNIQUE_FIXTURE','button_url'=>$url,
        'button_text'=>'✈️ Узнать стоимость тура','price_link_verified'=>true,'price_link_url'=>$url,'content_updated'=>true];
    $max=$receipt;$max['platform']='max';$max['post_url']=$binding['posts']['max'];
    $plan=['platform'=>'telegram','publication_id'=>101,'old_version_id'=>$previous,'message_id'=>'51',
        'logical_id'=>'commercial-hotel-42-v1','post_url'=>$receipt['post_url'],'token'=>$token,'text'=>$text,
        'monitor_price'=>$price,'update_text'=>true,'price_link'=>['url'=>$url,'telegram_entities'=>$entities],
        'photo_signature'=>$receipt['photo_signature']];
    $journal=['operation'=>monitor_price_operation($price),'manifest_hash'=>str_repeat('a',64),'monitor_snapshot'=>$snapshot,
        'feed_binding'=>$binding,'posts'=>['max:fixture'=>['state'=>'confirmed','receipt'=>$max],
            'telegram:fixture'=>['state'=>'confirmed','receipt'=>$receipt,'plan'=>$plan,'edit_request_start_ms'=>$at*1000,
                'provider_response'=>['http'=>200,'data'=>['ok'=>true,'result'=>$message]]]],'complete'=>true];
    return [$v,$journal];
};
[$second,$j2]=$build(1001,1003,str_repeat('b',64),123456,strtotime('2026-11-20T00:00:00Z'));
[$third,$j3]=$build(1003,1008,str_repeat('c',64),124000,strtotime('2026-11-21T00:00:00Z'));
// Synthetic append-only stage sequence with the actual existing TG child shape.
$journalLines=static function(array $j):array {
    $initial=$j;unset($initial['complete']);$initial['posts']=[];
    $max=$initial;$max['posts']['max:fixture']=$j['posts']['max:fixture'];
    $started=$max;$tg=$j['posts']['telegram:fixture'];
    $started['posts']['telegram:fixture']=['state'=>'edit_started','plan'=>$tg['plan'],
        'edit_request_start_ms'=>$tg['edit_request_start_ms']];
    $responded=$started;$responded['posts']['telegram:fixture']['state']='provider_response_recorded';
    $responded['posts']['telegram:fixture']['provider_response']=$tg['provider_response'];
    $confirmed=$j;unset($confirmed['complete']);
    return [$initial,$max,$started,$responded,$confirmed,$j];
};
$encodeJournal=static fn(array $lines):string=>implode("\n",array_map('lineJson',$lines))."\n";
$raw2=$encodeJournal($journalLines($j2));$raw3=$encodeJournal($journalLines($j3));
$zero=$run([$first],[]);lineAssert($zero['row']===$row&&$zero['intent_digest']===$digest,'unchanged full baseline');
$one=$run([$first,$second],[$raw2]);lineAssert($one['row']['source_version_id']===1003&&count($one['price_lineage'])===1,'verified original PRICE transition');
$two=$run([$first,$second,$third],[$raw2,$raw3]);$expected=$row;$expected['source_version_id']=1008;
$expected['source_content_hash']=$third['content_hash'];$expected['source_caption_sha256']=hash('sha256',$third['snapshot']['text']);
lineAssert($two['row']===$expected&&$two['intent_digest']===$digest&&count($two['price_lineage'])===2,'two contiguous historical PRICE transitions; only three source fields change');
lineAssert($run([$first,$second,$third],[$raw2,$raw3])===$two&&$one['execution_digest']!==$two['execution_digest'],'deterministic distinct execution digests');
lineAssert($two['row']['next_caption']===$row['next_caption']&&$two['row']['price_record_id']===null,'approved target unchanged; historical price not copied');
lineReject(fn()=>$run([$first,$third],[$raw3]),'skipped historical version');
lineReject(fn()=>$run([$first,$second],[]),'missing original journal');
lineReject(fn()=>$run([$first,$second],[rtrim($raw2)]),'partial journal');
lineReject(fn()=>$run([$first,$second],["broken\n".$raw2]),'corrupt earlier journal line');
foreach(['complete','previous_version','photo','button','native_http','native_caption','record','link','request_time','manifest','feed','price_template'] as $case){
    $j=$j2;$v=$second;
    switch($case){
        case 'complete':$j['complete']=false;break;
        case 'previous_version':$j['posts']['telegram:fixture']['receipt']['previous_version_id']=999;break;
        case 'photo':$v['snapshot']['attachments'][0]['telegram_file_id']='OTHER_FILE';break;
        case 'button':$v['snapshot']['keyboard'][0][0]['url']='https://t.me/other';break;
        case 'native_http':$j['posts']['telegram:fixture']['provider_response']['http']=0;break;
        case 'native_caption':$j['posts']['telegram:fixture']['provider_response']['data']['result']['caption'].=' Edited';break;
        case 'record':$v['snapshot']['metadata']['monitor_price_record_id']=str_repeat('d',64);break;
        case 'link':$j['posts']['telegram:fixture']['plan']['price_link']['telegram_entities'][0]['offset']++;break;
        case 'request_time':$j['posts']['telegram:fixture']['edit_request_start_ms']+=900000;break;
        case 'manifest':$j['manifest_hash']='bad';break;
        case 'feed':$j['feed_binding']['posts']['telegram']='https://t.me/any_toour/52';break;
        case 'price_template':$v['snapshot']['text'].=' Extra editorial';$v['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($v['snapshot']);break;
    }
    lineReject(fn()=>$run([$first,$v],[$encodeJournal($journalLines($j))]),$case);
}
foreach(['claim_token','claimed_at','state','hotel','token','fingerprint','date','version_number','hidden_button'] as $case){
    $v=$second;
    switch($case){
        case 'claim_token':$v['claim_token']='held';break;
        case 'claimed_at':$v['claimed_at']='2026-11-20';break;
        case 'state':$v['state']='unknown';break;
        case 'hotel':$v['snapshot']['metadata']['hotel_catalog_id']=43;break;
        case 'token':$v['start_payload']='p_other';break;
        case 'fingerprint':$v['content_hash']=str_repeat('0',64);break;
        case 'date':$v['snapshot']['published_at']='other';break;
        case 'version_number':$v['version_number']=4;break;
        case 'hidden_button':$v['snapshot']['keyboard'][0][0]['callback_data']='hidden';break;
    }
    lineReject(fn()=>$run([$first,$v],[$raw2]),$case);
}
lineReject(fn()=>HotelTransferPriceLineage::candidate($bytes.' ',$digest,[$first],[]),'immutable approved bytes');
foreach(['extra_row_field','target_date','caption_length','binding_operation','binding_manifest'] as $case){
    $i=$intent;
    switch($case){
        case 'extra_row_field':$i['row']['unapproved']='x';break;
        case 'target_date':$i['row']['target_published_unixtime']=null;break;
        case 'caption_length':$i['row']['next_caption']=str_repeat('x',1025);$i['row']['next_caption_sha256']=hash('sha256',$i['row']['next_caption']);break;
        case 'binding_operation':$i['feed_binding']['operation']='x';break;
        case 'binding_manifest':$i['feed_binding']['manifest_hash']=str_repeat('a',64);break;
    }
    $raw=lineJson($i);
    lineReject(fn()=>HotelTransferPriceLineage::candidate($raw,hash('sha256',$raw),[$first],[]),$case);
}
lineReject(fn()=>$run([$first,$second],[lineJson($j2)."\n"]),'terminal line alone cannot prove original stages');
foreach(['missing_start','missing_response','duplicate_start','changed_saved_response','changed_plan','early_complete','stage_regression'] as $case){
    $lines=$journalLines($j2);
    switch($case){
        case 'missing_start':array_splice($lines,2,1);break;
        case 'missing_response':array_splice($lines,3,1);break;
        case 'duplicate_start':array_splice($lines,3,0,[$lines[2]]);break;
        case 'changed_saved_response':$lines[3]['posts']['telegram:fixture']['provider_response']['http']=0;break;
        case 'changed_plan':$lines[2]['posts']['telegram:fixture']['plan']['old_version_id']=999;break;
        case 'early_complete':$lines[4]['complete']=true;break;
        case 'stage_regression':array_splice($lines,4,0,[$lines[1]]);break;
    }
    lineReject(fn()=>$run([$first,$second],[$encodeJournal($lines)]),$case);
}
// Real SQLite registry layout (the existing MySQL migration translated by
// the canonical fixture), actual locked files and the same immutable intent.
require_once __DIR__.'/../../services/HotelTransferPriceEvidenceReader.php';
$root=sys_get_temp_dir().'/anytour-price-lineage-'.bin2hex(random_bytes(8));
if(!mkdir($root,0700))throw new RuntimeException('fixture_root');
$root=realpath($root);
$leasePath=$root.'/monitor-price-after-collection-v1.jsonl';
$lease=fopen($leasePath,'x+b');$other=null;$journalLock=null;
if(!is_resource($lease))throw new RuntimeException('fixture_lease');
$dirs=[];$tmpFiles=[$leasePath];
try{
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    createChannelPostRegistryFixture($pdo);
    $pdo->exec("INSERT INTO projects VALUES(1,'anytour','AnyTour',1); INSERT INTO conversation_sources VALUES(11,1,'tg_fixture','TG','telegram',1,'manager');");
    $pdo->exec("INSERT INTO post_channels (id,project_id,source_id,platform,bot_key,canonical_bot_username,channel_id,bot_url,button_text,is_enabled,config_version)
        VALUES(10,1,11,'telegram','anyru_bot','anyru_bot','-1002262666616','https://t.me/anyru_bot','✈️ Узнать стоимость тура',1,2),
        (6,1,11,'telegram','anyru_bot','anyru_bot','-1001845835645','https://t.me/anyru_bot','✈️ Рассчитать на мои даты',1,2)");
    $pdo->prepare('INSERT INTO post_publications (id,post_channel_id,source_id,external_message_id,start_payload,current_version_id) VALUES(101,10,11,?,?,1008)')
        ->execute(['51',$token]);
    $withSource=static function(array $v,array $journal):array{
        $event=$journal['operation'].':telegram:51';
        $v['snapshot']['metadata']['source_event_id']=$event;
        $v['snapshot']['metadata']['observation_kind']='authorized_admin_content_edit';
        return $v;
    };
    $v2=$withSource($second,$j2);$v3=$withSource($third,$j3);
    foreach([$first,$v2,$v3] as $v){
        $pdo->prepare("INSERT INTO post_versions (id,publication_id,version_number,content_hash,snapshot_json,state) VALUES(?,101,?,?,?,'sent')")
            ->execute([$v['version_id'],$v['version_number'],$v['content_hash'],lineJson($v['snapshot'])]);
    }
    $saved=[];
    foreach([[$v2,$j2],[$v3,$j3]] as [$v,$j]){
        $pdo->prepare('INSERT INTO post_observations (publication_id,source_event_key,version_id) VALUES(101,?,?)')
            ->execute([hash('sha256',$v['snapshot']['metadata']['source_event_id']),$v['version_id']]);
        $j['posts']['telegram:fixture']['receipt']['source_key']='tg_fixture';
        $dir=$root.'/'.$j['operation'];if(!mkdir($dir,0700))throw new RuntimeException('fixture_journal_dir');
        $dirs[]=$dir;$path=$dir.'/receipt.jsonl';$tmpFiles[]=$path;
        $saved[$path]=$encodeJournal($journalLines($j));file_put_contents($path,$saved[$path]);
    }
    $read=static fn()=>HotelTransferPriceEvidenceReader::candidate($pdo,$bytes,$digest,$root,$lease);
    $dbState=static function()use($pdo):array{
        $rows=[];
        foreach(['post_channels','post_publications','post_versions','post_observations'] as $table)
            $rows[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    };
    $before=$dbState();$result=$read();
    lineAssert($result['row']===$expected&&count($result['price_lineage'])===2,'reader real registry history and original locked journal files');
    lineAssert($dbState()===$before&&array_map('file_get_contents',array_keys($saved))===array_values($saved)
        &&!file_exists($root.'/anytour-issue1566-historical-tg-media-77-v1'),'reader creates no operation and changes no DB/journal bytes');
    $other=fopen($leasePath,'rb');
    lineAssert(is_resource($other)&&!flock($other,LOCK_EX|LOCK_NB),'reader retains caller-owned global lease');
    lineReject(fn()=>HotelTransferPriceEvidenceReader::candidate($pdo,$bytes,$digest,$root,$other),'competing global lease');
    fclose($other);$other=null;
    $firstPath=array_key_first($saved);
    $journalLock=fopen($firstPath,'rb');flock($journalLock,LOCK_EX|LOCK_NB);
    lineReject($read,'original PRICE journal currently held');
    fclose($journalLock);$journalLock=null;
    $pdo->exec("UPDATE post_versions SET state='unknown' WHERE id=1001");lineReject($read,'historical UNKNOWN source fence');$pdo->exec("UPDATE post_versions SET state='sent' WHERE id=1001");
    $pdo->exec("UPDATE post_versions SET claim_token='foreign' WHERE id=1001");lineReject($read,'historical source claim fence');$pdo->exec("UPDATE post_versions SET claim_token=NULL WHERE id=1001");
    $pdo->exec('UPDATE post_publications SET current_version_id=1003 WHERE id=101');lineReject($read,'registry current pointer does not end history');$pdo->exec('UPDATE post_publications SET current_version_id=1008 WHERE id=101');
    $pdo->exec('UPDATE post_observations SET version_id=1003 WHERE version_id=1008');lineReject($read,'original event points to wrong version');
    // Restore through a bound predicate; no changes outside this in-memory fixture.
    $pdo->prepare('UPDATE post_observations SET version_id=1008 WHERE source_event_key=?')->execute([hash('sha256',$v3['snapshot']['metadata']['source_event_id'])]);
    $pdo->exec("INSERT INTO post_publications (id,post_channel_id,source_id,external_message_id,start_payload) VALUES(102,6,11,'77','p_reserved_fixture')");
    lineReject($read,'target orphan reservation is not reusable');$pdo->exec('DELETE FROM post_publications WHERE id=102');
    $prior=$root.'/anytour-issue1566-historical-tg-media-77-v1';mkdir($prior,0700);
    try{lineReject($read,'existing old v1 target operation fence');}finally{rmdir($prior);}
    $sourceJournal=$root.'/issue-1566-edit-existing-101.jsonl';file_put_contents($sourceJournal,'fixture');
    try{lineReject($read,'source manual editor journal fence');}finally{unlink($sourceJournal);}
    unlink($firstPath);lineReject($read,'missing original receipt file');
    symlink($leasePath,$firstPath);try{lineReject($read,'symlink receipt rejected');}finally{unlink($firstPath);}
    file_put_contents($firstPath,$saved[$firstPath]);
    lineAssert($read()===$result&&$dbState()===$before,'same candidate after isolated negative fixtures');
}finally{
    if(is_resource($other))fclose($other);
    if(is_resource($journalLock))fclose($journalLock);
    flock($lease,LOCK_UN);fclose($lease);
    foreach($tmpFiles as $path)if(is_file($path)||is_link($path))unlink($path);
    foreach($dirs as $dir)if(is_dir($dir))rmdir($dir);
    rmdir($root);
}
echo "HOTEL TRANSFER PRICE LINEAGE REGRESSION PASSED\n";
})();
