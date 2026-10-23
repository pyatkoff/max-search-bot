<?php
declare(strict_types=1);
require_once __DIR__.'/manager-tour-selection.php';
require_once dirname(__DIR__,2).'/services/Search3TourHandoffService.php';
require_once dirname(__DIR__,2).'/services/TourSelectionBotEntryService.php';

function search3FixtureSchema(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {$pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/060_search3_tour_handoffs.sql'));return;}
    $pdo->exec('CREATE TABLE search3_tour_handoffs(handoff_id TEXT PRIMARY KEY,project_key TEXT,producer_key TEXT,operation_id TEXT,request_hash TEXT,snapshot_json TEXT,telegram_token TEXT UNIQUE,max_token TEXT UNIQUE,bot_bindings_json TEXT,is_active INTEGER,created_at TEXT,expires_at TEXT,UNIQUE(project_key,operation_id));CREATE INDEX search3_bounds ON search3_tour_handoffs(project_key,created_at)');
}
function search3FixtureKeys(): array
{
    $pair=sodium_crypto_sign_keypair();$keys=['synthetic_search3'=>['producer'=>'search3','project'=>'anytour','not_before'=>time()-60,'not_after'=>time()+172800,'public_key'=>base64_encode(sodium_crypto_sign_publickey($pair))]];
    (new ReflectionProperty(Search3TourHandoffTrust::class,'keys'))->setValue(null,$keys);return [sodium_crypto_sign_secretkey($pair),$keys];
}
function search3FixtureConfig(): array
{
    return ['id'=>'anytour','routing'=>['source_key'=>'max:synthetic-main'],'messenger'=>['telegram'=>['source_key'=>'telegram:synthetic-main']],'channel_posts'=>[
        'telegram'=>['bot_key'=>'synthetic_bot','bot_url'=>'https://t.me/synthetic_bot'],'max'=>['bot_key'=>'synthetic_max','bot_url'=>'https://max.ru/synthetic_max']]];
}
function search3FixtureIntent(): array
{
    return ['action'=>'create','operation_id'=>bin2hex(random_bytes(16)),'binding'=>['record_id'=>'anex_online:'.str_repeat('a',64),'revision'=>3],
        'observation'=>['schema'=>1,'kind'=>'search3_browser_observation','capturedAt'=>time()*1000,'source'=>'anex',
            'hotel'=>['uiId'=>123,'namespace'=>'search3-ui','name'=>'Synthetic selected hotel','resort'=>'Лара'],
            'trip'=>['origin'=>'Москва','countryUiId'=>'4','departure'=>'2027-06-12','nights'=>7,'stayEnd'=>'2027-06-19','stayEndBasis'=>'shown-stay-end','adults'=>2,'childAges'=>[0,0,12]],
            'stay'=>['room'=>'Family sea view','meal'=>'Всё включено','placement'=>'2+3','operator'=>'ANEX'],
            'flights'=>['state'=>'selected','outward'=>[['label'=>'Outward selected 10:00 synthetic']],'inward'=>[['label'=>'Inward selected 19:00 synthetic']]],
            'price'=>['amount'=>'231456.50','currency'=>'RUB','state'=>'checked','description'=>'Source observation only','expiresAt'=>time()+600,'basis'=>'selected-pair','finalPriceVerified'=>false,'authority'=>'browser-observation-only']]];
}
function search3FixtureSigned(array $data,string $secret,?int $time=null): array
{
    $raw=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$stamp=(string)($time??time());$message="anytour-search3-handoff-v1\nPOST\n/search3/tour-handoff.php\n".$stamp."\n".hash('sha256',$raw);
    return [$raw,['HTTP_X_ANYTOUR_SEARCH3_KEY'=>'synthetic_search3','HTTP_X_ANYTOUR_SEARCH3_TIMESTAMP'=>$stamp,'HTTP_X_ANYTOUR_SEARCH3_SIGNATURE'=>rtrim(strtr(base64_encode(sodium_crypto_sign_detached($message,$secret)),'+/','-_'),'=')]];
}
function search3FixtureCommand(array $data,string $secret): array {return Search3TourHandoffService::execute(...search3FixtureSigned($data,$secret));}
function search3FixtureNative(string $platform,string $payload,int $user): array
{
    if ($platform==='telegram') return TelegramIncomingAdapter::fromUpdate(['update_id'=>500,'message'=>['message_id'=>500,'chat'=>['id'=>$user,'type'=>'private'],'from'=>['id'=>$user,'is_bot'=>false,'first_name'=>'Synthetic visitor'],'text'=>'/start '.$payload]])??[];
    return ['platform'=>'max','type'=>'bot_started','start_payload'=>$payload,'user'=>['chat_id'=>-$user,'external_user_id'=>$user],'raw'=>['update_type'=>'bot_started','user'=>['user_id'=>$user],'payload'=>$payload]];
}
function search3FixturePayload(string $url): string {parse_str((string)parse_url($url,PHP_URL_QUERY),$q);return (string)($q['start']??'');}

function search3Scenarios(PDO $pdo): array
{
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);ProjectConfig::resetForTests(search3FixtureConfig());[$secret,$keys]=search3FixtureKeys();
    $business=static function()use($pdo):array{$out=[];foreach(['conversations','managers','messages','manager_conversation_reads','conversation_events']as$table)$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();return $out;};
    $events=static fn():int=>(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='tour_search_inquiry'")->fetchColumn();
    $intent=search3FixtureIntent();$snapshot=['binding'=>$intent['binding'],'observation'=>$intent['observation']];
    deliveryCheck('exact observation retains ordered infants and no final-price or local-ID promotion',Search3TourHandoffSnapshot::normalize($snapshot)===$snapshot);
    foreach (['fixture','recorded'] as $source) {$bad=$snapshot;$bad['observation']['source']=$source;deliveryCheck('production source rejects '.$source,Search3TourHandoffSnapshot::normalize($bad)===null);}
    foreach (['hotel','trip','stay','flights','price'] as $group) {$bad=$snapshot;$bad['observation'][$group]['unexpected']='private';deliveryCheck('nested unknown fields rejected in '.$group,Search3TourHandoffSnapshot::normalize($bad)===null);}
    $bad=$snapshot;$bad['observation']['price']['finalPriceVerified']=true;deliveryCheck('browser verification flag cannot promote a full fare',Search3TourHandoffSnapshot::normalize($bad)===null);
    $bad=$snapshot;$bad['observation']['trip']['childAges']=[0,'0',12];deliveryCheck('child ages are typed ordered integers',Search3TourHandoffSnapshot::normalize($bad)===null);
    $bad=$snapshot;unset($bad['observation']['price']['expiresAt']);deliveryCheck('missing nullable contract fields cannot silently default',Search3TourHandoffSnapshot::normalize($bad)===null);
    $bad=$snapshot;$bad['observation']['flights']['inward']=[];deliveryCheck('selected pair requires explicit return direction',Search3TourHandoffSnapshot::normalize($bad)===null);
    $bad=$snapshot;$bad['observation']['stay']['room']='<script>untrusted()</script>';deliveryCheck('valid source text remains literal untrusted evidence',Search3TourHandoffSnapshot::normalize($bad)===$bad);
    [$raw,$headers]=search3FixtureSigned($intent,$secret);$before=$business();
    deliveryCheck('unsigned input creates no capability or business writes',Search3TourHandoffService::execute($raw,[])['error']==='unauthorized'&&(int)$pdo->query('SELECT COUNT(*) FROM search3_tour_handoffs')->fetchColumn()===0&&$business()===$before);
    $bad=$headers;$bad['HTTP_X_ANYTOUR_SEARCH3_KEY']='unknown';deliveryCheck('unregistered signing key denied',Search3TourHandoffService::execute($raw,$bad)['error']==='unauthorized');
    deliveryCheck('any change of signed raw bytes denied',Search3TourHandoffService::execute($raw.' ',$headers)['error']==='unauthorized');
    deliveryCheck('stale and future signatures fail before SQL',Search3TourHandoffService::execute(...search3FixtureSigned($intent,$secret,time()-301))['error']==='unauthorized'&&Search3TourHandoffService::execute(...search3FixtureSigned($intent,$secret,time()+301))['error']==='unauthorized');
    $created=Search3TourHandoffService::execute($raw,$headers);$tg=search3FixturePayload($created['links']['telegram']);$max=search3FixturePayload($created['links']['max']);
    deliveryCheck('signed intent mints distinct compact configured bot links',$created['ok']&&$created['state']==='ready'&&strlen($tg)===35&&strlen($max)===35&&$tg!==$max&&str_starts_with($created['links']['telegram'],'https://t.me/synthetic_bot?start=')&&str_starts_with($created['links']['max'],'https://max.ru/synthetic_max?start='));
    deliveryCheck('capability creation never creates inquiry messages reads or assignments',$business()===$before);
    $again=Search3TourHandoffService::execute($raw,$headers);deliveryCheck('lost create response recovers exact original links',$again['duplicate']&&$again['links']===$created['links']&&(int)$pdo->query('SELECT COUNT(*) FROM search3_tour_handoffs')->fetchColumn()===1);
    $changed=$intent;$changed['observation']['stay']['room']='Other exact room';deliveryCheck('changed conditions cannot reuse create operation',search3FixtureCommand($changed,$secret)['error']==='operation_mismatch');
    $status=['action'=>'status','operation_id'=>$intent['operation_id'],'request_hash'=>hash('sha256',$raw)];deliveryCheck('signed status returns original receipt',search3FixtureCommand($status,$secret)['links']===$created['links']);
    $wrong=$status;$wrong['request_hash']=str_repeat('0',64);deliveryCheck('wrong hash cannot expose original operation',search3FixtureCommand($wrong,$secret)['error']==='operation_mismatch');
    $missing=$status;$missing['operation_id']=bin2hex(random_bytes(16));deliveryCheck('missing status is read-only and explicit',search3FixtureCommand($missing,$secret)===['ok'=>true,'state'=>'missing']);
    $tgIn=search3FixtureNative('telegram',$tg,101);$mxIn=search3FixtureNative('max',$max,202);$beforeNative=$business();
    $forged=$tgIn;$forged['user']['external_user_id']=303;$forged['user']['chat_id']=303;deliveryCheck('normalized visitor cannot replace actual native sender',empty(TourSelectionBotEntryService::accept($forged)['ok'])&&$business()===$beforeNative);
    $group=$tgIn;$group['raw']['message']['chat']['type']='group';deliveryCheck('group start cannot save private native inquiry',empty(TourSelectionBotEntryService::accept($group)['ok']));
    deliveryCheck('capability is platform-specific',Search3TourHandoffService::botEntry('max',$tg)===null&&Search3TourHandoffService::botEntry('telegram',$max)===null);
    $first=TourSelectionBotEntryService::accept($tgIn);$saved=$business();$repeat=TourSelectionBotEntryService::accept($tgIn);
    deliveryCheck('actual native entry records exactly one event and one labelled message',$first['ok']&&$first['changed']&&$first['conversation_id']===1&&!$repeat['changed']&&$business()===$saved&&$events()===1&&(int)$pdo->query("SELECT COUNT(*) FROM messages WHERE metadata_json LIKE '%tour_search_inquiry%'")->fetchColumn()===1);
    $pdo->exec("INSERT INTO customers VALUES(3,'Synthetic relative','','');INSERT INTO customer_channels VALUES(3,3,'anytour','telegram','303','303');INSERT INTO conversations(id,customer_id,customer_channel_id,project_key,source_id,channel,status,manager_id,started_at,external_chat_id) VALUES(3,3,3,'anytour',1,'telegram','manager',8,'2026-10-23 00:00:00','303')");
    $a=TourSelectionBotEntryService::accept($mxIn);$b=TourSelectionBotEntryService::accept(search3FixtureNative('telegram',$tg,303));deliveryCheck('MAX visitor and forwarded relative retain their own conversations',$a['conversation_id']===2&&$b['conversation_id']===3&&$events()===3);
    $view=TourChoiceService::forConversation($pdo,3);$o=$view['search_inquiries'][0]['observation'];deliveryCheck('Manager2 retains exact selected conditions and repeated infants',$o===$intent['observation']&&$o['trip']['childAges']===[0,0,12]);
    deliveryCheck('projection omits retained record capabilities and signer',!preg_match('/record_id|revision|handoff_id|entry_key|producer_key|s3_/u',json_encode($view['search_inquiries'])));
    deliveryCheck('search entry cannot fabricate native likes deliveries or manager publications',(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type IN ('tour_offer_prepared','tour_offer_delivered','tour_offer_liked')")->fetchColumn()===0);
    $deferred=search3FixtureIntent();$deferred['observation']['flights']=['state'=>'manager-deferred','outward'=>[],'inward'=>[]];$deferred['observation']['price']=array_replace($deferred['observation']['price'],['amount'=>null,'state'=>'unknown','basis'=>'shown-offer']);$d=search3FixtureCommand($deferred,$secret);$dp=search3FixturePayload($d['links']['telegram']);$di=search3FixtureNative('telegram',$dp,101);$feedback=0;$handoffs=0;
    try {TourSelectionBotEntryService::handle($di,static function()use(&$handoffs){$handoffs++;throw new RuntimeException('synthetic after-commit handoff interruption');},static function()use(&$feedback){$feedback++;});}catch(RuntimeException $e){if($e->getMessage()!=='synthetic after-commit handoff interruption')throw $e;}
    $once=$events();TourSelectionBotEntryService::handle($di,static function()use(&$handoffs){$handoffs++;},static function()use(&$feedback){$feedback++;});
    deliveryCheck('after-commit handoff recovery never repeats inquiry or feedback',$events()===$once&&$handoffs===2&&$feedback===0);
    $v=TourChoiceService::forConversation($pdo,1)['search_inquiries'][0]['observation'];deliveryCheck('deferred unknown price never substitutes a first pair',$v['flights']['outward']===[]&&$v['flights']['inward']===[]&&$v['price']['amount']===null);
    $expired=search3FixtureIntent();$expired['observation']['price']['expiresAt']=time()-1;$e=search3FixtureCommand($expired,$secret);$ep=search3FixturePayload($e['links']['telegram']);TourSelectionBotEntryService::accept(search3FixtureNative('telegram',$ep,101));$v=TourChoiceService::forConversation($pdo,1)['search_inquiries'][0];
    deliveryCheck('expired quoted price remains historical and explicitly unverified',$v['price_at_arrival']==='expired'&&$v['price_state_now']==='expired'&&$v['observation']['price']['finalPriceVerified']===false);
    $history=$business();$revoked=search3FixtureCommand(array_replace($status,['action'=>'revoke']),$secret);deliveryCheck('revoke closes both links without rewriting historical inquiries',$revoked['state']==='revoked'&&Search3TourHandoffService::botEntry('telegram',$tg)===null&&Search3TourHandoffService::botEntry('max',$max)===null&&$business()===$history);
    deliveryCheck('retrying create never revives revoked capability',Search3TourHandoffService::execute($raw,$headers)['state']==='revoked');
    $pdo->prepare('UPDATE search3_tour_handoffs SET expires_at=? WHERE handoff_id=?')->execute([gmdate('Y-m-d H:i:s',time()-1),$d['context_id']]);deliveryCheck('capability expiry is separate from price lifetime',Search3TourHandoffService::botEntry('telegram',$dp)===null);
    $stale=search3FixtureIntent();$stale['observation']['capturedAt']=(time()-901)*1000;deliveryCheck('new create rejects stale original capture',search3FixtureCommand($stale,$secret)['error']==='stale_observation');
    (new ReflectionProperty(Search3TourHandoffTrust::class,'keys'))->setValue(null,[]);deliveryCheck('removed producer key closes existing links',Search3TourHandoffService::botEntry('telegram',$ep)===null);(new ReflectionProperty(Search3TourHandoffTrust::class,'keys'))->setValue(null,$keys);
    $config=search3FixtureConfig();$config['channel_posts']['telegram']=['bot_key'=>'other_bot','bot_url'=>'https://t.me/other_bot'];ProjectConfig::resetForTests($config);deliveryCheck('changed canonical bot identity invalidates old link',Search3TourHandoffService::botEntry('telegram',$ep)===null);ProjectConfig::resetForTests(search3FixtureConfig());
    return [$secret,$keys];
}
