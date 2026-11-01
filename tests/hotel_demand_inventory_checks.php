<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerHotelKnowledgeMcpAdapter.php';
require_once dirname(__DIR__).'/services/ManagerTourSelectionPublicationDraft.php';

/** Isolated evidence store; no production connection, provider or customer traffic. */
function hotelDemandFixture(): array
{
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE conversations(id INTEGER PRIMARY KEY,project_key TEXT,channel TEXT,is_test INTEGER);
CREATE TABLE manager_tour_selection_publications(publication_id TEXT,conversation_id INTEGER,draft_hash TEXT,binding_json TEXT,selection_json TEXT,created_at TEXT);
CREATE TABLE manager_tour_selection_cards(publication_id TEXT,card_index INTEGER,offer_id INTEGER);
CREATE TABLE manager_tour_selection_deliveries(offer_id INTEGER,conversation_id INTEGER,offer_hash TEXT,state TEXT,receipt_json TEXT);
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,direction TEXT,sender_type TEXT,channel TEXT,external_message_id TEXT,metadata_json TEXT,created_at TEXT);
INSERT INTO conversations VALUES(10,'anytour','telegram',0),(11,'anytour','max',0),(12,'other','telegram',0),(13,'anytour','telegram',1),(1,'anytour','telegram',0);");
    $hotel='SUNRISE ARABIAN BEACH RESORT';$destination='Египет, Шарм-эль-Шейх';
    $import=ManagerTourSelectionImportDraft::normalize(['adapter'=>'tourvisor-snapshot-v1',
        'url'=>'https://pro.tourvisor.ru/search?ts_dosearch=1&s_form_mode=0&s_nights_from=11&s_nights_to=11&s_directflight=0&s_regular=1&x_hotel_codes=9374&s_j_date_from=29.10.2026&s_j_date_to=29.10.2026&s_adults=2&s_meal=7&s_flyfrom=1&s_country=1',
        'observed_at'=>'2026-10-16T11:00:00Z','quote_id'=>'tv-snapshot-1234567890',
        'keys'=>['hotel'=>'9374','meal'=>'7','departure'=>'1','country'=>'1','operator'=>'13'],
        'offer'=>['hotel'=>$hotel,'destination'=>$destination,'departure'=>'Москва','date_from'=>'2026-10-29','nights'=>11,'adults'=>2,'child_ages'=>[],
            'room'=>'Family Suite','meal'=>'AI - Все Включено','amount'=>'160000','currency'=>'RUB','stars'=>5],
        'raw'=>['hotel'=>$hotel.' 5*','destination'=>$destination,'date'=>'29 октября','nights'=>'11 ночей','room'=>'Family Suite','meal'=>'AI - Все Включено','party'=>'2 взрослых',
            'price'=>'160 000 РУБ','price_basis'=>'за номер','base_price'=>'144 000 РУБ','fuel_price'=>'16 000 РУБ','tariff'=>'PRIVATE source tariff',
            'flight_alternatives'=>'PRIVATE source flight','party_price_confirmation'=>'one_room_two_adults'],
        'photos'=>array_map(static fn($n)=>'https://static.tourvisor.ru/hotel_pics/verybig/1/9374-'.$n.'.jpg',[1,2,3])]);
    if($import===null)throw new RuntimeException('inventory synthetic import invalid');
    $offer=$import['offer']+['import_source'=>ManagerTourSelectionImportDraft::source($import)];
    $selection=ManagerTourSelectionPublicationDraft::build(['title'=>'PRIVATE title','intro'=>'PRIVATE intro','offers'=>[$offer]]);unset($selection['ok']);
    $binding=['project_key'=>'anytour','channel'=>'telegram','external_chat_id'=>'101','external_user_id'=>'101'];
    $pub=str_repeat('a',32);$entry=$selection['offers'][0];$json=hotelDemandJson($selection);
    $pdo->prepare('INSERT INTO manager_tour_selection_publications VALUES(?,?,?,?,?,?)')->execute([$pub,10,hash('sha256',hotelDemandJson($binding).$json),hotelDemandJson($binding),$json,'2026-11-01 10:00:00']);
    $pdo->prepare('INSERT INTO manager_tour_selection_cards VALUES(?,?,?)')->execute([$pub,0,100]);
    $prepared=['schema'=>1,'snapshot'=>$entry['snapshot'],'binding'=>$binding,'token_hash'=>str_repeat('b',64)];
    $pdo->prepare('INSERT INTO conversation_events VALUES(?,?,?,?,?)')->execute([100,10,'tour_offer_prepared',hotelDemandJson($prepared),'2026-11-01 10:00:00']);
    $pdo->prepare('INSERT INTO conversation_events VALUES(?,?,?,?,?)')->execute([101,10,'tour_offer_delivered',hotelDemandJson(['offer_id'=>100,'native_mid'=>'PRIVATE_mid','native_chat_id'=>'101']),'2026-11-01 10:01:00']);
    $receipt=['actor'=>919191,'identity'=>['user'=>'101','chat'=>'101'],'native'=>['mid'=>'PRIVATE_mid','message'=>['PRIVATE'=>'native bytes']]];
    $hash=hash('sha256',hotelDemandJson(['binding'=>$binding,'snapshot'=>$entry['snapshot'],'presentation'=>$entry['presentation']]));
    $pdo->prepare('INSERT INTO manager_tour_selection_deliveries VALUES(?,?,?,?,?)')->execute([100,10,$hash,'delivered',hotelDemandJson($receipt)]);
    $pdo->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?)')->execute([1000,10,'outbound','manager','telegram','PRIVATE_mid',hotelDemandJson(['project_key'=>'anytour','tour_selection'=>$pub,'tour_offer_id'=>100]),'2026-11-01 10:01:00']);
    $s=$entry['snapshot'];$d=$s['quoted_details'];
    // Canonical ShareService::read uses zero-based public index; metadata.variant is one-based.
    $public=['index'=>0,'hotel'=>$s['hotel'],'departure'=>$s['departure'],'date_from'=>$s['date_from'],'nights'=>$s['nights'],'adults'=>$s['adults'],'children'=>count($s['child_ages']),
        'room'=>$s['room'],'meal'=>$s['meal'],'amount'=>$s['amount'],'currency'=>$s['currency'],'rationale'=>$s['rationale'],'compromise'=>$s['compromise'],
        'destination'=>$d['destination'],'flights'=>$d['flights'],'inclusions'=>$d['inclusions'],'constraints'=>$d['constraints'],'observed_at'=>$d['observed_at'],'price_verified'=>false];
    $inquiry=['schema'=>1,'entry_key'=>str_repeat('c',64),'publication_id'=>$pub,'link_version'=>1,'variant'=>1,'public_offer'=>$public];
    $pdo->prepare('INSERT INTO conversation_events VALUES(?,?,?,?,?)')->execute([200,11,'tour_web_inquiry',hotelDemandJson($inquiry),'2026-11-01 10:03:00']);
    $pdo->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?)')->execute([1001,11,'inbound','customer','max',null,hotelDemandJson(['type'=>'tour_web_inquiry','publication_id'=>$pub,'link_version'=>1,'variant'=>1]),'2026-11-01 10:03:01']);
    return [$pdo,$selection,$binding,$inquiry];
}
function hotelDemandJson(array $a): string {return json_encode($a,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function hotelDemandRewrite(PDO $pdo,array $selection,array $binding): void
{
    $json=hotelDemandJson($selection);$pdo->prepare('UPDATE manager_tour_selection_publications SET selection_json=?,draft_hash=?')->execute([$json,hash('sha256',hotelDemandJson($binding).$json)]);
}
function hotelDemandRead(PDO $pdo,int $limit=20): array {return ManagerHotelDemandInventory::read($pdo,$limit,strtotime('2026-11-01T12:00:00Z'));}

function hotelDemandInventoryChecks(): void
{
    [$pdo]=$fixture=hotelDemandFixture();$pdo->exec('PRAGMA query_only=ON');$r=hotelDemandRead($pdo);$pdo->exec('PRAGMA query_only=OFF');
    preparationMcpCheck('hotel inventory reads exact delivered and forwarded native inquiry proofs with writes forbidden',
        $r['status']==='available'&&$r['counts']['delivered_card_exposures']===1&&$r['counts']['native_inquiries']===1&&!$pdo->inTransaction());
    $h=$r['hotels'][0];
    preparationMcpCheck('hotel inventory uses shared approved typed bridge and small fact coverage',
        $h['source_identity']===['adapter'=>'tourvisor-snapshot-v1','hotel'=>'9374','country'=>'1']&&$h['hotel_ref']===['catalog'=>'anytour','id'=>1554]
        &&$h['facts_coverage']['selected_claim_count']===6&&$h['facts_coverage']['source_count']===3&&!$h['facts_coverage']['complete']);
    $bytes=hotelDemandJson($r);
    preparationMcpCheck('hotel inventory never emits donor identities customer text raw imports publication ids or full facts',
        !str_contains($bytes,'PRIVATE')&&!str_contains($bytes,'919191')&&!str_contains($bytes,str_repeat('a',32))&&!str_contains($bytes,'conversation_id')&&!str_contains($bytes,'manager_id')&&!str_contains($bytes,'Family Suite'));
    preparationMcpCheck('hotel demand window and unknown real/test population remain explicit',
        $r['window']['limit']===20&&$r['window']['publications_read']===1&&!$r['window']['has_more']&&$r['window']['oldest_created_at']==='2026-11-01 10:00:00'
        &&$r['classification']['included_sessions']==='unknown'&&$r['coverage']['unique_people']===null&&$r['coverage']['unique_trips']===null&&!$r['coverage']['real_customer_demand_verified']);
    foreach(['pending','rejected','reserved','unknown','known'] as $state){
        [$pdo]=hotelDemandFixture();$pdo->prepare('UPDATE manager_tour_selection_deliveries SET state=?')->execute([$state]);$r=hotelDemandRead($pdo);
        preparationMcpCheck('hotel inventory never counts '.$state.' as sent', $r['counts']['verified_delivered_lower_bound']===0&&$r['counts']['not_confirmed_sent_cards']===1
            &&($r['counts']['delivered_card_exposures']===(in_array($state,['pending','rejected'],true)?0:null))&&$r['counts']['native_inquiries']===1);
    }
    foreach(['missing delivered receipt'=>"DELETE FROM conversation_events WHERE event_type='tour_offer_delivered'",
        'duplicate delivered receipt'=>"INSERT INTO conversation_events SELECT 102,conversation_id,event_type,payload_json,created_at FROM conversation_events WHERE id=101",
        'missing transcript'=>"DELETE FROM messages WHERE direction='outbound'",
        'foreign delivery owner'=>"UPDATE manager_tour_selection_deliveries SET conversation_id=12",
        'corrupt native receipt'=>"UPDATE manager_tour_selection_deliveries SET receipt_json='{'",
        'changed offer hash'=>"UPDATE manager_tour_selection_deliveries SET offer_hash='bad'",
        'same-id prepared edit'=>"UPDATE conversation_events SET payload_json='{}' WHERE id=100",
        'future prepared proof'=>"UPDATE conversation_events SET created_at='2026-11-02 00:00:00' WHERE id=100",
        'future delivered proof'=>"UPDATE conversation_events SET created_at='2026-11-02 00:00:00' WHERE id=101",
        'future outbound mirror'=>"UPDATE messages SET created_at='2026-11-02 00:00:00' WHERE id=1000",
        'delivery before prepared'=>"UPDATE conversation_events SET created_at='2026-10-31 00:00:00' WHERE id=101",
        'mirror before delivered'=>"UPDATE messages SET created_at='2026-10-31 00:00:00' WHERE id=1000"] as $case=>$sql){
        [$pdo]=hotelDemandFixture();$pdo->exec($sql);$r=hotelDemandRead($pdo);
        preparationMcpCheck('hotel inventory preserves unknown rather than zero for '.$case,$r['status']==='partial'&&$r['counts']['delivered_card_exposures']===null&&$r['counts']['verified_delivered_lower_bound']===0);
    }
    foreach(['missing inquiry mirror'=>"DELETE FROM messages WHERE direction='inbound'",
        'duplicate inquiry'=>"INSERT INTO conversation_events SELECT 201,conversation_id,event_type,payload_json,created_at FROM conversation_events WHERE id=200",
        'corrupt inquiry'=>"UPDATE conversation_events SET payload_json='{' WHERE id=200",
        'duplicate inquiry mirror'=>"INSERT INTO messages SELECT 1002,conversation_id,direction,sender_type,channel,external_message_id,metadata_json,created_at FROM messages WHERE id=1001",
        'future mirror'=>"UPDATE messages SET created_at='2026-11-02 10:00:00' WHERE id=1001"] as $case=>$sql){
        [$pdo]=hotelDemandFixture();$pdo->exec($sql);$r=hotelDemandRead($pdo);
        preparationMcpCheck('hotel inventory keeps ambiguous unavailable native proof unknown for '.$case,$r['counts']['native_inquiries']===null&&$r['counts']['verified_inquiries_lower_bound']===0&&$r['counts']['delivered_card_exposures']===1);
    }
    [$pdo,$selection,$binding,$inquiry]=hotelDemandFixture();$inquiry['public_offer']['amount']='1';$pdo->prepare('UPDATE conversation_events SET payload_json=? WHERE id=200')->execute([hotelDemandJson($inquiry)]);$r=hotelDemandRead($pdo);
    preparationMcpCheck('hotel inventory cannot join inquiry to a different exact tariff',$r['counts']['native_inquiries']===null&&$r['counts']['verified_inquiries_lower_bound']===0);
    foreach([12,13,1] as $cid){
        [$pdo]=hotelDemandFixture();$pdo->prepare('UPDATE manager_tour_selection_publications SET conversation_id=?')->execute([$cid]);$r=hotelDemandRead($pdo);
        preparationMcpCheck('hotel inventory excludes foreign known-test or canonical internal publication '.$cid,$r['window']['publications_read']===0&&$r['hotels']===[]);
        [$pdo]=hotelDemandFixture();$pdo->prepare('UPDATE conversation_events SET conversation_id=? WHERE id=200')->execute([$cid]);$r=hotelDemandRead($pdo);
        preparationMcpCheck('hotel inventory excludes foreign known-test or canonical internal inquiry '.$cid,$r['counts']['native_inquiries']===0&&$r['counts']['delivered_card_exposures']===1);
    }
    foreach(['invalid JSON'=>"UPDATE manager_tour_selection_publications SET selection_json='{'",
        'digest mismatch'=>"UPDATE manager_tour_selection_publications SET draft_hash='bad'",
        'duplicate card index'=>"INSERT INTO manager_tour_selection_cards SELECT * FROM manager_tour_selection_cards",
        'missing card'=>"DELETE FROM manager_tour_selection_cards"] as $case=>$sql){
        [$pdo]=hotelDemandFixture();$pdo->exec($sql);$r=hotelDemandRead($pdo);
        preparationMcpCheck('hotel inventory malformed publication is unknown '.$case,$r['counts']['delivered_card_exposures']===null&&$r['counts']['native_inquiries']===null&&$r['hotels']===[]);
    }
    [$pdo,$selection,$binding]=hotelDemandFixture();unset($selection['offers'][0]['import_source']);hotelDemandRewrite($pdo,$selection,$binding);$r=hotelDemandRead($pdo);
    preparationMcpCheck('hotel inventory does not infer accepted hotel id or distinct hotel count from names',
        $r['hotels'][0]['source_identity']===null&&$r['hotels'][0]['hotel_ref']===null&&$r['hotels'][0]['facts_coverage']['status']==='held'
        &&$r['hotels'][0]['group_kind']==='unidentified_cards'&&$r['coverage']['distinct_hotel_count']===null);
    [$pdo,$selection,$binding]=hotelDemandFixture();$selection['offers'][0]['snapshot']['hotel']='Different hotel';hotelDemandRewrite($pdo,$selection,$binding);$r=hotelDemandRead($pdo);
    preparationMcpCheck('hotel inventory edited hotel cannot retain imported identity',$r['hotels'][0]['source_identity']===null&&$r['hotels'][0]['hotel_ref']===null&&$r['counts']['delivered_card_exposures']===null);
    [$pdo,$selection,$binding]=hotelDemandFixture();$selection['offers']=array_fill(0,21,$selection['offers'][0]);hotelDemandRewrite($pdo,$selection,$binding);$r=hotelDemandRead($pdo);
    preparationMcpCheck('hotel inventory rejects more than twenty cards without silent count truncation',$r['counts']['unreadable_publications']===1&&$r['hotels']===[]);
    [$pdo]=hotelDemandFixture();$pdo->prepare('UPDATE manager_tour_selection_publications SET selection_json=?')->execute([str_repeat('я',524289)]);$r=hotelDemandRead($pdo);
    preparationMcpCheck('hotel inventory publication limit counts UTF8 bytes not characters',$r['counts']['unreadable_publications']===1&&$r['counts']['delivered_card_exposures']===null);
    [$pdo]=hotelDemandFixture();$pdo->exec("INSERT INTO manager_tour_selection_publications SELECT 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',conversation_id,draft_hash,binding_json,selection_json,'2026-11-01 10:02:00' FROM manager_tour_selection_publications;
INSERT INTO manager_tour_selection_cards SELECT 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',card_index,offer_id FROM manager_tour_selection_cards;");$r=hotelDemandRead($pdo);
    preparationMcpCheck('hotel inventory deduplicates delivered offer reused in two immutable publications',$r['counts']['observed_publication_cards']===2&&$r['counts']['delivered_card_exposures']===1&&$r['counts']['repeated_delivery_references']===1);
    $r=hotelDemandRead($pdo,1);
    preparationMcpCheck('hotel inventory latest-N selection discloses omitted publications and exact time span',$r['window']['has_more']&&$r['window']['publications_read']===1&&$r['window']['oldest_created_at']==='2026-11-01 10:02:00'&&$r['counts']['native_inquiries']===0);
    [$pdo]=hotelDemandFixture();$pdo->exec("DELETE FROM conversation_events WHERE id=200");
    $q=$pdo->prepare('INSERT INTO conversation_events VALUES(?,?,?,?,?)');
    for($i=0;$i<401;$i++)$q->execute([200+$i,11,'tour_web_inquiry',hotelDemandJson(['schema'=>1,'entry_key'=>str_pad(dechex($i+1),64,'0',STR_PAD_LEFT),'publication_id'=>str_repeat('d',32),'variant'=>1,'link_version'=>1]),'2026-11-01 10:03:00']);
    $r=hotelDemandRead($pdo);
    preparationMcpCheck('hotel inventory bounded native inquiry tail cannot report a false zero for omitted events',$r['window']['inquiry_has_more']&&$r['window']['inquiry_rows_read']===400&&$r['counts']['native_inquiries']===null);
    [$pdo]=hotelDemandFixture();$pdo->exec('DROP TABLE manager_tour_selection_deliveries');$r=hotelDemandRead($pdo);
    preparationMcpCheck('hotel inventory absent storage is unavailable with null counts',$r['status']==='unavailable'&&$r['counts']===null);
    [$pdo]=hotelDemandFixture();$pdo->beginTransaction();$r=hotelDemandRead($pdo);
    preparationMcpCheck('hotel inventory never commits or borrows caller transaction',$r['reason']==='transaction_active'&&$pdo->inTransaction());$pdo->rollBack();
    $adapter=new ManagerHotelKnowledgeMcpAdapter($pdo,static fn()=>strtotime('2026-11-01T12:00:00Z'));
    foreach([['limit'=>0],['limit'=>21],['limit'=>'1'],['limit'=>null],['cursor'=>'x'],['conversation_id'=>10],[1]] as $args){
        $thrown=false;try{$adapter->call(ManagerHotelKnowledgeMcpAdapter::NAME,$args);}catch(InvalidArgumentException $e){$thrown=true;}
        preparationMcpCheck('hotel inventory MCP rejects additional or invalid arguments '.hotelDemandJson($args),$thrown);
    }
    $tool=ManagerHotelKnowledgeMcpAdapter::tools()[0];
    preparationMcpCheck('hotel inventory MCP publishes narrow read-only legacy capability', $tool['name']===ManagerHotelKnowledgeMcpAdapter::NAME&&$tool['annotations']['readOnlyHint']&& !$tool['annotations']['openWorldHint']
        &&$tool['securitySchemes'][0]['scopes']===ManagerPreparationOAuth::SCOPES&&$tool['inputSchema']['properties']['limit']['maximum']===20&&$adapter->call('unrelated',[])===null);
    hotelDemandRecipientAndGroupingChecks();
    hotelDemandCanonicalDeliveryCheck();
    hotelDemandMcpAuthorizationChecks();
    hotelDemandVersionAndBudgetChecks();
}

/** Only the new aggregate's version, streaming and complete-wire boundaries. */
function hotelDemandVersionAndBudgetChecks(): void
{
    [$pdo,$selection]=hotelDemandFixture();$first=hotelDemandRead($pdo)['hotels'][0];
    $pdo->exec("INSERT INTO manager_tour_selection_publications SELECT 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',conversation_id,draft_hash,binding_json,selection_json,'2026-11-01 10:02:00' FROM manager_tour_selection_publications;
INSERT INTO manager_tour_selection_cards SELECT 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',card_index,offer_id FROM manager_tour_selection_cards;");
    $both=hotelDemandRead($pdo);
    preparationMcpCheck('hotel cohort exact accepted bundle pins do not vary with publication provenance',
        count($both['hotels'])===1&&$both['hotels'][0]['group_revision']===$first['group_revision']
        &&strlen($first['accepted_knowledge']['bundle_revision'])===64
        &&strlen($first['accepted_knowledge']['binding_revision'])===64
        &&count($first['accepted_knowledge']['claim_ids'])===6&&count($first['accepted_knowledge']['source_ids'])===3
        &&$first['accepted_knowledge']['price_authority']==='none');
    $entry=$selection['offers'][0];$provenance=['kind'=>'publication','publication_id'=>str_repeat('a',32),'card_index'=>0,
        'draft_offer_id'=>$entry['draft_offer_id'],'publication_sha256'=>str_repeat('a',64),'source_sha256'=>ManagerHotelOfferReference::hash($entry['import_source'])];
    $selector=ManagerHotelOfferReference::selector($entry,$provenance);
    $facts=ManagerHotelOfferReference::capture($entry,$provenance,strtotime('2026-11-01T12:00:00Z'));
    $group=new ReflectionMethod(ManagerHotelDemandInventory::class,'group');$original=$group->invoke(null,$selector['source_identity'],$facts);
    foreach(['binding_revision','record_hashes','material_limits','coverage'] as $field){
        $changed=$facts;
        if($field==='binding_revision')$changed[$field]=str_repeat('f',64);
        else $changed[$field]['synthetic_revision']='changed';
        $next=$group->invoke(null,$selector['source_identity'],$changed);
        preparationMcpCheck('hotel cohort never merges changed accepted '.$field,
            $next['group_revision']!==$original['group_revision']&&$next['accepted_knowledge']['bundle_revision']!==$original['accepted_knowledge']['bundle_revision']);
    }
    [$pdo]=hotelDemandFixture();$pdo->exec('DELETE FROM manager_tour_selection_publications');
    $insert=$pdo->prepare('INSERT INTO manager_tour_selection_publications VALUES(?,?,?,?,?,?)');
    for($i=1;$i<=6;++$i)$insert->execute([str_pad(dechex($i),32,'0',STR_PAD_LEFT),10,str_repeat('a',64),'{}',str_repeat('x',900000),'2026-11-01 10:00:00']);
    $limited=hotelDemandRead($pdo);
    preparationMcpCheck('hotel inventory aborts cumulative input bytes and rolls back its own read',
        $limited['status']==='unavailable'&&$limited['reason']==='byte_limit'&&$limited['counts']===null&&!$pdo->inTransaction());
    $pdo->exec('DELETE FROM manager_tour_selection_publications');
    preparationMcpCheck('hotel inventory closes streamed cursor after byte refusal and can read again',hotelDemandRead($pdo)['status']==='available');
    $pdo=preparationMcpFixture();$service=new ManagerPreparationMcpService($pdo,null,null,null,static fn()=>true);
    $rpc=preparationMcpRpc('tools/call',['name'=>ManagerHotelKnowledgeMcpAdapter::NAME,'arguments'=>[]]);$rpc['id']=str_repeat('x',262144);
    $response=$service->handle($rpc,1,str_repeat('f',32));
    preparationMcpCheck('hotel inventory complete MCP wire cap rejects oversized RPC id before any result or error echo',
        ($response['error']['code']??0)===-32600&&$response['id']===null&&strlen(hotelDemandJson($response))<1024&&!isset($response['result']));
    foreach([['actor'=>2,'args'=>[]],['actor'=>1,'args'=>['limit'=>0]]] as $bad){
        $rpc['params']['arguments']=$bad['args'];$response=$service->handle($rpc,$bad['actor'],str_repeat('f',32));
        preparationMcpCheck('hotel inventory preflight bounds oversized id on denied or invalid request '.hotelDemandJson($bad),
            ($response['error']['code']??0)===-32600&&$response['id']===null&&strlen(hotelDemandJson($response))<1024&&!isset($response['result']));
    }
    foreach(["UPDATE messages SET channel='email' WHERE direction='inbound'","UPDATE messages SET channel='telegram' WHERE direction='inbound'","UPDATE conversations SET channel='email' WHERE id=11"] as $changedChannel){
        [$data]=hotelDemandFixture();$data->exec($changedChannel);$result=hotelDemandRead($data);
        preparationMcpCheck('hotel inventory mismatched or nonnative inquiry platform remains unknown '.$changedChannel,
            $result['counts']['native_inquiries']===null&&$result['counts']['verified_inquiries_lower_bound']===0&&$result['counts']['unknown_inquiry_proofs']===1);
    }
    foreach(['variant','link_version','publication_id'] as $changedTuple){
        [$data]=hotelDemandFixture();$metadata=json_decode($data->query("SELECT metadata_json FROM messages WHERE direction='inbound'")->fetchColumn(),true);
        $metadata[$changedTuple]=$changedTuple==='publication_id'?strtoupper($metadata[$changedTuple]):(string)$metadata[$changedTuple];
        $data->prepare("UPDATE messages SET metadata_json=? WHERE direction='inbound'")->execute([hotelDemandJson($metadata)]);
        $result=hotelDemandRead($data);
        preparationMcpCheck('hotel inventory requires exact typed inquiry mirror '.$changedTuple,
            $result['counts']['native_inquiries']===null&&$result['counts']['verified_inquiries_lower_bound']===0&&$result['counts']['unknown_inquiry_proofs']===1);
    }
    [$data,$selection,$binding]=hotelDemandFixture();$data->exec('DELETE FROM manager_tour_selection_publications; DELETE FROM manager_tour_selection_cards');
    $publication=$data->prepare('INSERT INTO manager_tour_selection_publications VALUES(?,?,?,?,?,?)');
    $card=$data->prepare('INSERT INTO manager_tour_selection_cards VALUES(?,?,?)');$template=$selection['offers'][0];
    for($i=1;$i<=15;++$i){
        $pub=str_pad(dechex($i),32,'0',STR_PAD_LEFT);$selection['offers']=[];
        for($j=0;$j<20;++$j){
            $entry=$template;$hotelId=(string)(10000+$i*20+$j);$entry['import_source']['keys']['hotel']=$hotelId;
            $entry['import_source']['url']=str_replace('x_hotel_codes=9374','x_hotel_codes='.$hotelId,$entry['import_source']['url']);
            $entry['import_source']['photos']=array_map(static fn($url)=>str_replace('/9374-','/'.$hotelId.'-',$url),$entry['import_source']['photos']);
            $entry['snapshot']['quoted_details']['source_url']=$entry['import_source']['url'];
            $selection['offers'][]=$entry;$card->execute([$pub,$j,100]);
        }
        $json=hotelDemandJson($selection);$publication->execute([$pub,10,hash('sha256',hotelDemandJson($binding).$json),hotelDemandJson($binding),$json,'2026-11-01 10:00:00']);
    }
    $aggregate=hotelDemandRead($data);
    preparationMcpCheck('hotel inventory wire budget fixture remains inside raw aggregate cap '.hotelDemandJson(['status'=>$aggregate['status'],'reason'=>$aggregate['reason']??null,'groups'=>count($aggregate['hotels']),'bytes'=>strlen(hotelDemandJson($aggregate))]),count($aggregate['hotels'])===300&&strlen(hotelDemandJson($aggregate))<ManagerHotelDemandInventory::OUTPUT_BYTES);
    $data->exec("CREATE TABLE managers(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER);CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,is_active INTEGER);CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
INSERT INTO managers VALUES(1,'admin',1);INSERT INTO projects VALUES(1,'anytour',1);INSERT INTO manager_projects VALUES(1,1);");
    $service=new ManagerPreparationMcpService($data,null,null,static fn()=>strtotime('2026-11-01T12:00:00Z'),static fn()=>true);
    $response=$service->handle(preparationMcpRpc('tools/call',['name'=>ManagerHotelKnowledgeMcpAdapter::NAME,'arguments'=>[]]),1,str_repeat('f',32));
    preparationMcpCheck('hotel inventory complete success wire includes both text and structured aggregate without partial leak',
        ($response['error']['code']??0)===-32603&&$response['id']===1&&strlen(hotelDemandJson($response))<1024&&!isset($response['result']));
    foreach([[ManagerPreparationOAuth::HOTEL_SCOPE],[ManagerPreparationOAuth::ARCHIVE_SCOPE],[ManagerPreparationOAuth::DEVELOPMENT_SCOPE],['queue:read']] as $scopes){
        $tools=(new ReflectionMethod(ManagerPreparationMcpService::class,'tools'))->invoke($service,$scopes);
        $denied=false;
        try{(new ReflectionMethod(ManagerPreparationMcpService::class,'callTool'))->invoke($service,
            ['name'=>ManagerHotelKnowledgeMcpAdapter::NAME,'arguments'=>[]],1,str_repeat('f',32),$scopes);}
        catch(DomainException $error){$denied=true;}
        preparationMcpCheck('hotel inventory tool projection and dispatcher reject unrelated or incomplete scopes '.implode(',',$scopes),
            !in_array(ManagerHotelKnowledgeMcpAdapter::NAME,array_column($tools,'name'),true)&&$denied);
    }
}

/** Run the existing canonical prepare/send/finish owners against their fake transport. */
function hotelDemandCanonicalDeliveryCheck(): void
{
    require_once __DIR__.'/support/manager-tour-selection.php';
    $old=(new ReflectionProperty(ConversationDb::class,'pdo'))->getValue();
    try{
        foreach(['telegram'=>[1,10],'max'=>[2,20]] as $channel=>[$oldId,$cid]){
            $pdo=deliveryDb();$pdo->exec('ALTER TABLE conversations ADD COLUMN is_test INTEGER DEFAULT 0');
            $pdo->prepare('UPDATE conversations SET id=? WHERE id=?')->execute([$cid,$oldId]);
            $pdo->prepare('UPDATE messages SET conversation_id=? WHERE conversation_id=?')->execute([$cid,$oldId]);
            $saved=deliverySave(deliveryDraft(),0,$cid);$prepared=ManagerTourSelectionDeliveryService::prepare(7,$cid,1);
            if(empty($saved['ok'])||empty($prepared['ok']))throw new RuntimeException('canonical inventory publication unavailable');
            $selection=json_decode($pdo->query('SELECT selection_json FROM manager_tour_selection_publications')->fetchColumn(),true);
            $callback=$pdo->query('SELECT callback_data FROM manager_tour_selection_cards')->fetchColumn();
            $entry=$selection['offers'][0];$card=TourOfferCardRenderer::render($entry['snapshot'],$entry['presentation'],$callback);
            $state=['calls'=>[],'creates'=>0,'card'=>$card,'readback'=>true];$transport=deliveryTransport($state);
            $sent=ManagerTourSelectionDeliveryService::send(7,$cid,$prepared['publication']['id'],0,$transport,'deliveryImages');
            $pdo->exec('PRAGMA query_only=ON');$r=ManagerHotelDemandInventory::read($pdo,20,time()+1);$pdo->exec('PRAGMA query_only=OFF');
            preparationMcpCheck('hotel inventory accepts actual canonical '.$channel.' prepare transport verify finish event and mirror without fabrication',
                !empty($sent['ok'])&&$state['creates']===1&&$r['status']==='available'&&$r['counts']['delivered_card_exposures']===1&&$r['counts']['native_inquiries']===0);
        }
    }finally{(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$old);}
}
function hotelDemandMcpAuthorizationChecks(): void
{
    if(!function_exists('preparationMcpFixture'))return;
    $name=ManagerHotelKnowledgeMcpAdapter::NAME;$pdo=preparationMcpFixture();$connection=str_repeat('f',32);
    $rpc=['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>(object)[]]];
    $rpc['params']['arguments']=[];
    $checks=0;$service=new ManagerPreparationMcpService($pdo,null,null,null,static function()use(&$checks):bool{++$checks;return $checks<3;});
    $result=$service->handle($rpc,1,$connection,ManagerPreparationOAuth::SCOPES);
    preparationMcpCheck('hotel inventory MCP late grant revocation withholds even unavailable packaged private result',($result['error']['code']??0)===-32001&&!isset($result['result']));
    $service=new ManagerPreparationMcpService($pdo,null,null,null,static fn()=>false);
    preparationMcpCheck('hotel inventory MCP revoked grant fails before inventory read',($service->handle($rpc,1,$connection)['error']['code']??0)===-32001);
    $service=new ManagerPreparationMcpService($pdo,null,null,null,static fn()=>true);
    foreach([2,3] as $actor)preparationMcpCheck('hotel inventory MCP denies nonadmin or foreign-project administrator '.$actor,($service->handle($rpc,$actor,$connection)['error']['code']??0)===-32001);
    $list=$service->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'],1,$connection);
    preparationMcpCheck('hotel inventory MCP tool is advertised on authenticated legacy tools surface',in_array($name,array_column($list['result']['tools']??[],'name'),true));
    foreach(['none','admin','project','connection'] as $revocation){
        [$data]=hotelDemandFixture();
        $data->exec("CREATE TABLE managers(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER);
CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
INSERT INTO managers VALUES(1,'admin',1);INSERT INTO projects VALUES(1,'anytour',1);INSERT INTO manager_projects VALUES(1,1);");
        $calls=0;$active=static function()use(&$calls,$data,$revocation):bool{
            ++$calls;
            if($calls===2){
                if($revocation==='admin')$data->exec('UPDATE managers SET is_active=0');
                if($revocation==='project')$data->exec('DELETE FROM manager_projects');
            }
            return !($revocation==='connection'&&$calls>=3);
        };
        $service=new ManagerPreparationMcpService($data,null,null,static fn()=>strtotime('2026-11-01T12:00:00Z'),$active);
        $result=$service->handle($rpc,1,$connection);
        if($revocation==='none')preparationMcpCheck('hotel inventory actual MCP success returns only accepted bounded aggregate',
            ($result['result']['structuredContent']['counts']['delivered_card_exposures']??null)===1&&!str_contains(hotelDemandJson($result),'PRIVATE'));
        else preparationMcpCheck('hotel inventory final '.$revocation.' revocation withholds actual aggregate',
            ($result['error']['code']??0)===-32001&&!isset($result['result']));
    }
}

function hotelDemandRecipientAndGroupingChecks(): void
{
    foreach(['chat','user','both'] as $changed){
        [$pdo]=hotelDemandFixture();$receipt=json_decode($pdo->query('SELECT receipt_json FROM manager_tour_selection_deliveries')->fetchColumn(),true);
        if($changed!=='user')$receipt['identity']['chat']='202';if($changed!=='chat')$receipt['identity']['user']='202';
        $pdo->prepare('UPDATE manager_tour_selection_deliveries SET receipt_json=?')->execute([hotelDemandJson($receipt)]);
        $pdo->prepare('UPDATE conversation_events SET payload_json=? WHERE id=101')->execute([hotelDemandJson(['offer_id'=>100,'native_mid'=>'PRIVATE_mid','native_chat_id'=>$receipt['identity']['chat']])]);
        $r=hotelDemandRead($pdo);
        preparationMcpCheck('hotel inventory rejects self-consistent receipt for foreign Telegram '.$changed,$r['counts']['delivered_card_exposures']===null&&$r['counts']['verified_delivered_lower_bound']===0);
    }
    [$pdo,$selection,$binding,$inquiry]=hotelDemandFixture();$bad=$selection;$entry=&$bad['offers'][0];
    $entry['snapshot']['hotel']='SAVOY 5★';$entry['import_source']['fields']['hotel']='SAVOY';$entry['import_source']['raw']['hotel']='SAVOY 5*';
    $pub=str_repeat('b',32);$json=hotelDemandJson($bad);
    $pdo->prepare('INSERT INTO manager_tour_selection_publications VALUES(?,?,?,?,?,?)')->execute([$pub,10,hash('sha256',hotelDemandJson($binding).$json),hotelDemandJson($binding),$json,'2026-11-01 10:00:02']);
    $pdo->prepare('INSERT INTO manager_tour_selection_cards VALUES(?,?,?)')->execute([$pub,0,300]);
    $prepared=['schema'=>1,'snapshot'=>$entry['snapshot'],'binding'=>$binding,'token_hash'=>str_repeat('d',64)];
    $pdo->prepare('INSERT INTO conversation_events VALUES(?,?,?,?,?)')->execute([300,10,'tour_offer_prepared',hotelDemandJson($prepared),'2026-11-01 10:00:00']);
    $pdo->prepare('INSERT INTO conversation_events VALUES(?,?,?,?,?)')->execute([301,10,'tour_offer_delivered',hotelDemandJson(['offer_id'=>300,'native_mid'=>'PRIVATE_second','native_chat_id'=>'101']),'2026-11-01 10:01:00']);
    $receipt=['actor'=>919191,'identity'=>['user'=>'101','chat'=>'101'],'native'=>['mid'=>'PRIVATE_second']];
    $hash=hash('sha256',hotelDemandJson(['binding'=>$binding,'snapshot'=>$entry['snapshot'],'presentation'=>$entry['presentation']]));
    $pdo->prepare('INSERT INTO manager_tour_selection_deliveries VALUES(?,?,?,?,?)')->execute([300,10,$hash,'delivered',hotelDemandJson($receipt)]);
    $pdo->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?)')->execute([1300,10,'outbound','manager','telegram','PRIVATE_second',hotelDemandJson(['project_key'=>'anytour','tour_selection'=>$pub,'tour_offer_id'=>300]),'2026-11-01 10:01:00']);
    $inquiry['publication_id']=$pub;$inquiry['entry_key']=str_repeat('d',64);$inquiry['public_offer']['hotel']='SAVOY 5★';
    $pdo->prepare('INSERT INTO conversation_events VALUES(?,?,?,?,?)')->execute([400,11,'tour_web_inquiry',hotelDemandJson($inquiry),'2026-11-01 10:03:00']);
    $pdo->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?)')->execute([1400,11,'inbound','customer','max',null,hotelDemandJson(['type'=>'tour_web_inquiry','publication_id'=>$pub,'link_version'=>1,'variant'=>1]),'2026-11-01 10:03:01']);
    foreach(['held_first','accepted_first'] as $order){
        if($order==='accepted_first')$pdo->exec("UPDATE manager_tour_selection_publications SET created_at='2026-11-01 10:00:03' WHERE publication_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'");
        $r=hotelDemandRead($pdo);$accepted=array_values(array_filter($r['hotels'],static fn($g)=>$g['hotel_ref']!==null));$held=array_values(array_filter($r['hotels'],static fn($g)=>$g['hotel_ref']===null));
        preparationMcpCheck('hotel inventory separates same typed key name-mismatch demand from accepted hotel '.$order,
            $r['counts']['delivered_card_exposures']===2&&$r['counts']['native_inquiries']===2&&count($accepted)===1&&count($held)===1
            &&$accepted[0]['hotel_ref']['id']===1554&&$accepted[0]['delivered_card_exposures']===1&&$accepted[0]['native_inquiries']===1
            &&$held[0]['facts_coverage']['reason']==='source_name_mismatch'&&$held[0]['delivered_card_exposures']===1&&$held[0]['native_inquiries']===1);
    }
}
