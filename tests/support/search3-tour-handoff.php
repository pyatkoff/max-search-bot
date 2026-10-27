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

/** Genuine native starts use the actual entry owner; every send is intercepted. */
function search3PreviewScenarios(PDO $pdo,string $secret): void
{
    foreach (['telegram'=>101,'max'=>202] as $platform=>$user) {
        $intent=search3FixtureIntent();$intent['observation']['hotel']['name']='Синтетический отель & море';
        $created=search3FixtureCommand($intent,$secret);$payload=search3FixturePayload($created['links'][$platform]);
        $incoming=search3FixtureNative($platform,$payload,$user);$sent=[];$dispatch=0;
        $feedback=static function(array $in,string $text)use(&$sent):void {$sent[]=$text;};
        $handoff=static function()use(&$dispatch):void {++$dispatch;};
        TourSelectionBotEntryService::handle($incoming,$handoff,$feedback);
        deliveryCheck($platform.' native entry shows the selected room meal whole-party amount and all infant ages',count($sent)===1&&str_contains($sent[0],'Family sea view')&&str_contains($sent[0],'Всё включено')&&str_contains($sent[0],'231 456,50')&&str_contains($sent[0],'0, 0, 12'));
        $before=$sent;TourSelectionBotEntryService::handle($incoming,$handoff,$feedback);
        deliveryCheck($platform.' repeated native start never resends the preview',$sent===$before&&$dispatch===2);
    }
    $snapshot=['binding'=>search3FixtureIntent()['binding'],'observation'=>search3FixtureIntent()['observation']];
    $text=Search3TourHandoffPreview::text($snapshot);
    deliveryCheck('preview retains both actual flight labels and discloses final-cost uncertainty',str_contains($text,'Outward selected 10:00 synthetic')&&str_contains($text,'Inward selected 19:00 synthetic')&&str_contains($text,'Итоговую стоимость и наличие подтвердит менеджер'));
    $expired=$snapshot;$expired['observation']['price']['expiresAt']=time()-1;
    deliveryCheck('expired displayed amount remains historical rather than zero or verified',str_contains(Search3TourHandoffPreview::text($expired),'231 456,50')&&str_contains(Search3TourHandoffPreview::text($expired),'устарела'));
    $unknown=$snapshot;$unknown['observation']['price']['amount']=null;$unknown['observation']['price']['state']='unknown';
    deliveryCheck('unknown amount is not promoted to a zero price',str_contains(Search3TourHandoffPreview::text($unknown),'Стоимость уточнит менеджер')&&!str_contains(Search3TourHandoffPreview::text($unknown),'0 ₽'));
    $deferred=$snapshot;$deferred['observation']['flights']=['state'=>'manager-deferred','outward'=>[],'inward'=>[]];$deferred['observation']['price']['basis']='shown-offer';
    deliveryCheck('manager-deferred flights never borrow the first returned pair',str_contains(Search3TourHandoffPreview::text($deferred),'Перелёт уточнит менеджер')&&!str_contains(Search3TourHandoffPreview::text($deferred),'Outward'));
    $deferred['observation']['trip']['stayEnd']=null;$deferred['observation']['trip']['stayEndBasis']='unknown';
    deliveryCheck('unknown stay end is not derived from the number of nights',!str_contains(Search3TourHandoffPreview::text($deferred),'19.06.2027'));
    $long=$snapshot;$long['observation']['hotel']['name']=str_repeat('<&',95);$long['observation']['stay']['room']=str_repeat('🧳<&',120);$long['observation']['stay']['meal']=str_repeat('🧳<&',90);$long['observation']['stay']['placement']=str_repeat('<&',240);$long['observation']['stay']['operator']=str_repeat('🧳<&',90);
    $long['observation']['flights']['outward']=$long['observation']['flights']['inward']=array_fill(0,4,['label'=>str_repeat('<&',490)]);
    $longText=Search3TourHandoffPreview::text($long);
    deliveryCheck('long untrusted Unicode fields stay escaped bounded and keep the exact amount',strlen($longText)<3800&&str_contains($longText,'&lt;')&&!str_contains($longText,'🧳<')&&str_contains($longText,'231 456,50'));
    $invalid=$snapshot;$invalid['observation']['price']['finalPriceVerified']=true;$rejected=false;
    try{Search3TourHandoffPreview::text($invalid);}catch(InvalidArgumentException){$rejected=true;}
    deliveryCheck('presenter cannot normalize a forged final-price authority',$rejected);
    $id=$snapshot['observation']['hotel']['uiId'];
    $catalog=['ok'=>true,'source'=>'anytour-local-tv-catalog-v1','catalog'=>'local-tv','requestedIds'=>[$id],'missingIds'=>[],
        'links'=>[['oldLocalId'=>$id,'tourvisorHotelId'=>987]],'items'=>[['id'=>987,'catalog'=>'local-tv','revision'=>1,'primaryImage'=>'https://photos.example.org/hotel.jpg','images'=>[]]]];
    deliveryCheck('photo uses an explicit crosswalk rather than equal integers',Search3TourHandoffPhoto::source($id,$catalog)==='https://photos.example.org/hotel.jpg');
    foreach(['source','requested','link','item','missing','revision','private-photo']as$case){
        $bad=$catalog;
        if($case==='source')$bad['source']='other';if($case==='requested')$bad['requestedIds']=[987];
        if($case==='link')$bad['links'][0]['oldLocalId']=987;if($case==='item')$bad['items'][0]['id']=$id;
        if($case==='missing')$bad['missingIds']=[$id];if($case==='revision')$bad['items'][0]['revision']=0;
        if($case==='private-photo')$bad['items'][0]['primaryImage']='https://app.anytoour.ru/manager/tour-selection-photo.php?conversation_id=1&draft_photo='.str_repeat('a',32);
        deliveryCheck('photo rejects '.$case,Search3TourHandoffPhoto::source($id,$bad)===null);
    }
    $reads=0;$downloads=0;$body=json_encode($catalog,JSON_THROW_ON_ERROR);
    $oversize=static function()use(&$reads):array{++$reads;return ['status'=>200,'type'=>'application/json','body'=>str_repeat('x',262145)];};
    deliveryCheck('oversize LOCAL response fails without image download',Search3TourHandoffPhoto::image($snapshot,$oversize,static function()use(&$downloads):string{++$downloads;return '';})===null&&$reads===1&&$downloads===0);
    $logProperty=new ReflectionProperty(DiagnosticLogger::class,'file');$oldLog=$logProperty->getValue();
    $dir=sys_get_temp_dir().'/search3-preview-photo-'.bin2hex(random_bytes(8));mkdir($dir,0700);DiagnosticLogger::setFile($dir.'/events.log');
    try{
        $photo=Search3TourHandoffPhoto::image($snapshot,static function(string $url)use(&$reads,$body,$id):array{
            ++$reads;parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
            if(parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_HOST)!=='anytoour.ru'||parse_url($url,PHP_URL_PATH)!=='/_preview/search3-next-candidate/data/local-tv-catalog-read-v1.php'||$query!==['oldLocalHotelIds'=>[(string)$id]])throw new LogicException('Unexpected photo reader');
            return ['status'=>200,'type'=>'application/json; charset=utf-8','body'=>$body];
        },static function()use(&$downloads):string{++$downloads;return file_get_contents(dirname(__DIR__,2).'/assets/owner-tour-cards/compact-v1/cover.jpg');});
        deliveryCheck('accepted LOCAL cover delegates to the existing private image-byte owner',$photo!==null&&is_file($photo['path'])&&$photo['mime']==='image/jpeg'&&$downloads===1);
    }finally{
        DiagnosticLogger::setFile((string)$oldLog);
        $remove=static function(string $path)use(&$remove):void{if(is_file($path)||is_link($path)){unlink($path);return;}foreach(scandir($path)?:[]as$name)if($name!=='.'&&$name!=='..')$remove($path.'/'.$name);rmdir($path);};$remove($dir);
    }
    $original=IntegrationRegistry::messenger();
    try{
        foreach(['telegram','max']as$platform)foreach(['photo','photo-unknown','text','long','photo-error']as$mode){
            $messenger=new class($mode) implements MessengerInterface{
                public array $calls=[];public function __construct(private string $mode){}
                public function send($id,string $text):bool{$this->calls[]=['text',$id,$text];return true;}
                public function sendMedia($id,string $type,string $path,string $name,string $mime,string $text=''):bool{$this->calls[]=['image',$id,$text,$name,$mime];if($this->mode==='photo-unknown')throw new RuntimeException('Unknown native send');return true;}
                public function sendWithButtons($id,string $text,array $buttons):bool{return $this->send($id,$text);}
                public function sendContactRequest($id,string $text,string $manual,string $back):bool{return $this->send($id,$text);}
            };
            IntegrationRegistry::useMessenger($messenger);
            $intent=search3FixtureIntent();
            if($mode==='long'){
                $intent['observation']=$long['observation'];$intent['observation']['hotel']['name']=str_repeat('H',190);
                foreach(['room'=>500,'meal'=>300,'placement'=>500,'operator'=>300]as$field=>$length)$intent['observation']['stay'][$field]=str_repeat('X',$length);
                $intent['observation']['flights']['outward']=$intent['observation']['flights']['inward']=array_fill(0,4,['label'=>str_repeat('F',1000)]);
            }
            $created=search3FixtureCommand($intent,$secret);$in=search3FixtureNative($platform,search3FixturePayload($created['links'][$platform]),$platform==='telegram'?101:202);
            $image=static function()use($mode):?array{if($mode==='photo-error')throw new RuntimeException('Image unavailable');return $mode==='text'?null:['path'=>__FILE__,'mime'=>'image/png'];};
            TourSelectionBotEntryService::handle($in,static function(){},null,$image);$calls=$messenger->calls;
            TourSelectionBotEntryService::handle($in,static function(){},null,$image);
            $expected=in_array($mode,['text','photo-error'],true)?['text']:($mode==='long'?['text','image']:['image']);
            deliveryCheck($platform.' '.$mode.' uses the planned native presentation once without uncertain-send fallback',array_column($calls,0)===$expected&&$messenger->calls===$calls&&str_contains($calls[0][2],'231 456,50'));
            if($expected[0]==='image')deliveryCheck($platform.' image MIME and filename stay consistent',$calls[0][3]==='hotel.png'&&$calls[0][4]==='image/png');
        }
    }finally{IntegrationRegistry::useMessenger($original);}
    // A new private sender must enter the same real Recorder path, not a synthetic existing chat.
    $oldPdo=(new ReflectionProperty(ConversationDb::class,'pdo'))->getValue();$fresh=deliveryDb();search3FixtureSchema($fresh);ProjectConfig::resetForTests(search3FixtureConfig());
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$fresh);
    try{
        $fresh->exec('ALTER TABLE conversation_sources ADD COLUMN project_id INTEGER;ALTER TABLE conversation_sources ADD COLUMN source_key TEXT;ALTER TABLE conversation_sources ADD COLUMN channel TEXT;
ALTER TABLE customer_channels ADD COLUMN username TEXT;ALTER TABLE customer_channels ADD COLUMN metadata_json TEXT;ALTER TABLE conversations ADD COLUMN metadata_json TEXT;
UPDATE conversation_sources SET project_id=1,source_key="telegram:synthetic-main",channel="telegram" WHERE id=1;
INSERT INTO conversation_sources(id,display_name,fallback_mode,is_active,project_id,source_key,channel) VALUES(2,"Synthetic MAX default","none",1,1,"max:synthetic-main","max");');
        foreach(['telegram'=>604,'max'=>605]as$platform=>$user){
            $intent=search3FixtureIntent();$created=search3FixtureCommand($intent,$secret);$in=search3FixtureNative($platform,search3FixturePayload($created['links'][$platform]),$user);$sent=[];$targets=[];
            $handoff=static function(array $in,array $result)use(&$targets):void{$targets[]=$result['conversation_id'];};
            $feedback=static function(array $in,string $text)use(&$sent):void{$sent[]=$text;};
            TourSelectionBotEntryService::handle($in,$handoff,$feedback);
            $id=$targets[0]??0;$row=$fresh->query('SELECT c.channel,c.manager_id,s.source_key FROM conversations c JOIN conversation_sources s ON s.id=c.source_id WHERE c.id='.(int)$id)->fetch(PDO::FETCH_ASSOC);
            $inquiries=TourChoiceService::forConversation($fresh,(int)$id)['search_inquiries'];
            deliveryCheck($platform.' first-ever visitor gets their own default-source dialogue and exact preview',$id>2&&$row['channel']===$platform&&$row['source_key']===($platform==='telegram'?'telegram:synthetic-main':'max:synthetic-main')&&$row['manager_id']===null&&count($sent)===1&&str_contains($sent[0],'Family sea view')&&$inquiries[0]['observation']===$intent['observation']);
            TourSelectionBotEntryService::handle($in,$handoff,$feedback);
            deliveryCheck($platform.' first-ever reentry keeps one inquiry and one customer preview',count($sent)===1&&count(TourChoiceService::forConversation($fresh,(int)$id)['search_inquiries'])===1&&$targets===[$id,$id]);
        }
    }finally{(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$oldPdo);}
}
