<?php
declare(strict_types=1);
require_once __DIR__.'/manager-tour-selection.php';
require_once dirname(__DIR__,2).'/services/ManagerTourSelectionCollectorSession.php';
require_once dirname(__DIR__,2).'/services/ManagerTourSelectionImportDraft.php';

function collectorObservation(): array
{
    return ['adapter'=>'funsun-b2b-v1','url'=>'https://b2b.fstravel.com/search_tour?ADULT=2&CHILD=0&CHECKIN_BEG=20270605&CHECKIN_END=20270605&NIGHTS_FROM=7&NIGHTS_TILL=7&STATEINC=1&TOWNFROMINC=2&CURRENCY=1&PROGRAMINC=3','observed_at'=>'2026-10-23T11:00:00Z','quote_id'=>'0x'.str_repeat('a',64),
        'keys'=>['hotel'=>'10','room'=>'20','meal'=>'30','placement'=>'40','tour'=>'50','program'=>'3','spo'=>'60','departure'=>'2','country'=>'1'],
        'offer'=>['hotel'=>'Синтетический отель','destination'=>'Турция, Лара','departure'=>'Москва','date_from'=>'2027-06-05','nights'=>7,'adults'=>2,'child_ages'=>[],'room'=>'Standard / 2AD','meal'=>'Ultra All Inclusive','amount'=>'200000','currency'=>'RUB','stars'=>5],
        'raw'=>['hotel'=>'Синтетический отель 5*','date'=>'05.06.2027, сб','nights'=>'7','room'=>'Standard / 2AD','meal'=>'Ultra All Inclusive','price'=>'200 000 RUB','party'=>'2AD','tour'=>'Synthetic tour','flight_alternatives'=>'вылеты: 01:00, 02:00','tariff'=>'Synthetic tariff']];
}

/** Real canonical import setup; ordinary save must never invent provenance. */
function collectorImportedDraft(PDO $pdo,array $observation,int $conversation=1): array
{
    collectorSchema($pdo);$session=str_repeat('1',64);$device=str_repeat('2',32);$connection=str_repeat('3',32);
    $view=ManagerTourSelectionCollectorSession::view(7,$conversation,$session,$device);
    $connected=ManagerTourSelectionCollectorSession::connect(7,$conversation,$session,['device_id'=>$device,'connection_id'=>$connection,'binding'=>$view['target']['binding'],'confirm_current_trip'=>true]);
    if (empty($connected['ok'])) throw new RuntimeException('Synthetic collector connection failed');
    $result=ManagerTourSelectionService::import(7,$conversation,$session,['operation_id'=>bin2hex(random_bytes(16)),'connection_id'=>$connection,'observation'=>$observation]);
    if (empty($result['ok'])) throw new RuntimeException('Synthetic automatic photo import failed');
    return ManagerTourSelectionService::get(7,$conversation)['selection'];
}

function partnerCollectorObservation(): array
{
    $o=collectorObservation();$o['quote_id']='0x'.str_repeat('d',268);
    $o['keys']=array_replace($o['keys'],['hotel'=>'-10','room'=>'-2147483647','placement'=>'-2147483647','spo'=>'','partner'=>'HBK']);
    $o['offer']['room']=$o['raw']['room']='DOUBLE STANDARD / 2ADL';
    $o['raw']['hotel_url']='https://b2b.fstravel.com/hotel/-10?partner=HBK';
    $o['raw']['tariff']='Информация о тарифе в корзине';
    $o['photos']=array_map(static fn(int $i):string=>'https://hbpro.expert/resource/hotel/photo_1200/11/22/'.$i.'.jpg',[101,102,103]);
    return $o;
}

function anexCollectorObservation(): array
{
    return ['adapter'=>'anex-agent-v1','url'=>'https://agent.anextour.ru/search/tour?ADULT=2&CHILD=0&CHECKIN_BEG=20261025&CHECKIN_END=20261025&NIGHTMIN=7&NIGHTMAX=7&CURRENCY=1&STATEINC=3&TOWNFROM=moskva','observed_at'=>'2026-10-24T09:30:00Z','quote_id'=>'0x'.str_repeat('b',268),
        'keys'=>['hotel'=>'100','country'=>'3','departure'=>'moskva','spo'=>'AYT-12345'],
        'offer'=>['hotel'=>'Синтетический ANEX','destination'=>'Турция, Белек','departure'=>'Москва','date_from'=>'2026-10-25','nights'=>7,'adults'=>2,'child_ages'=>[],'room'=>'Standard Room / DBL','meal'=>'UAI','amount'=>'177323','currency'=>'RUB','stars'=>5],
        'raw'=>['hotel'=>'Синтетический ANEX5 *','resort'=>'Белек','date'=>'25 окт. 2026 вс','nights'=>'7','room'=>'Standard Room','placement'=>'DBL','meal'=>'UAI','price'=>'177 323 ₽','party'=>'2 взрослых','tariff'=>'ANEX Light','departure'=>'Москва','country'=>'Турция']];
}

function anexCollectorScenarios(PDO $pdo,string $session,string $connection): void
{
    $observation=anexCollectorObservation();$normalized=ManagerTourSelectionImportDraft::normalize($observation);
    deliveryCheck('ANEX source-specific row contract retains opaque identity and unknown fare',$normalized!==null&&$normalized['offer']['photos']===[]&&str_contains($normalized['offer']['constraints'],'топливо'));
    foreach (['price','date','room','placement','meal','party','departure','country','hotel'] as $key) {$bad=$observation;$bad['raw'][$key].=' altered';deliveryCheck('ANEX raw '.$key.' cannot disagree with normalized fields',ManagerTourSelectionImportDraft::normalize($bad)===null);}
    foreach (['token=secret','%74oken=secret','CLAIM=opaque','ADULT=2','ADULT[]=2'] as $extra) {$bad=$observation;$bad['url'].='&'.$extra;deliveryCheck('ANEX source URL rejects unknown secret or duplicate query',ManagerTourSelectionImportDraft::normalize($bad)===null);}
    foreach (['CHILD=0'=>'CHILD=1','ADULT=2'=>'ADULT=3','STATEINC=3'=>'STATEINC=4','CHECKIN_BEG=20261025'=>'CHECKIN_BEG=20261026','NIGHTMIN=7'=>'NIGHTMIN=8'] as $from=>$to) {$bad=$observation;$bad['url']=str_replace($from,$to,$bad['url']);deliveryCheck('ANEX unsupported or contradictory form is refused',ManagerTourSelectionImportDraft::normalize($bad)===null);}
    $bad=$observation;$bad['adapter']='funsun-b2b-v1';deliveryCheck('ANEX cannot borrow FUNSUN source grammar',ManagerTourSelectionImportDraft::normalize($bad)===null);
    $bad=$observation;$bad['raw']['price']='177 323 ₽ 12 413 ₽';deliveryCheck('ANEX price never absorbs commission or additional-service amounts',ManagerTourSelectionImportDraft::normalize($bad)===null);
    $base=ManagerTourSelectionService::get(7,1)['selection'];$count=count($base['draft']['offers']);
    $command=['connection_id'=>$connection,'operation_id'=>'anex-mixed-source-0001','observation'=>$observation];
    $added=ManagerTourSelectionService::import(7,1,$session,$command);
    deliveryCheck('ANEX appends through the same canonical mixed-source draft',$added['ok']&&$added['count']===$count+1&&ManagerTourSelectionService::get(7,1)['selection']['draft']['offers'][0]===$base['draft']['offers'][0]);
    deliveryCheck('ANEX lost response resolves exact receipt without another append',ManagerTourSelectionService::importOperation(7,1,$session,$command)['found']&&ManagerTourSelectionService::import(7,1,$session,$command)['saved_version']===$added['saved_version']&&count(ManagerTourSelectionService::get(7,1)['selection']['draft']['offers'])===$count+1);
    $state=ManagerTourSelectionService::get(7,1)['selection'];$state['draft']['offers'][$count]['amount']='180000';$state['draft']['offers'][$count]['rationale']='Сохранённая ручная правка';deliverySave($state['draft'],$state['version']);
    $dup=ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'anex-mixed-source-0002']));
    deliveryCheck('ANEX duplicate preserves manual price and original source amount',$dup['state']==='already_added'&&ManagerTourSelectionService::get(7,1)['selection']['draft']['offers'][$count]['amount']==='180000'&&ManagerTourSelectionService::get(7,1)['selection']['draft']['offers'][$count]['import_source']['fields']['amount']==='177323');
    $price=$observation;$price['quote_id']='0x'.str_repeat('c',268);$price['offer']['amount']='181000';$price['raw']['price']='181 000 ₽';
    deliveryCheck('ANEX changed opaque quote and price requires explicit review',ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'anex-mixed-price-0001','observation'=>$price]))['error']==='source_price_changed');
    $room=$observation;$room['quote_id']='0x'.str_repeat('d',268);$room['raw']['room']='Sea View';$room['offer']['room']='Sea View / DBL';
    deliveryCheck('ANEX equal amount with different room remains separate',ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'anex-mixed-room-0001','observation'=>$room]))['count']===$count+2);
    $tariff=$observation;$tariff['quote_id']='0x'.str_repeat('e',268);$tariff['raw']['tariff']='EARLY BOOKING TURKEY';$tariff['keys']['spo']='AYT-12346';
    deliveryCheck('ANEX separate tariff of same hotel retains its own choice',ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'anex-mixed-tariff-0001','observation'=>$tariff]))['count']===$count+3);
    $foreign=ManagerTourSelectionService::import(7,1,str_repeat('9',64),array_replace($command,['operation_id'=>'anex-foreign-session-0001']));deliveryCheck('ANEX uses the canonical live connection fences',$foreign['error']==='connection_changed');
}

function collectorSchema(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') $pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/057_manager_tour_selection_collectors.sql'));
    else $pdo->exec('CREATE TABLE manager_tour_selection_collectors(connection_id TEXT PRIMARY KEY,manager_id INTEGER,conversation_id INTEGER,device_id TEXT,session_hash TEXT,binding_hash TEXT,created_at TEXT,expires_at TEXT,revoked_at TEXT)');
}

function tourvisorCollectorObservation(): array
{
    return ['adapter'=>'tourvisor-snapshot-v1','url'=>'https://pro.tourvisor.ru/search?ts_dosearch=1&s_form_mode=0&s_nights_from=11&s_nights_to=11&s_directflight=0&s_regular=1&x_hotel_codes=502&s_j_date_from=29.10.2026&s_j_date_to=29.10.2026&s_adults=2&s_meal=7&s_flyfrom=1&s_country=1','observed_at'=>'2026-10-24T11:00:00Z','quote_id'=>'tv-snapshot-1234567890',
        'keys'=>['hotel'=>'502','meal'=>'7','departure'=>'1','country'=>'1','operator'=>'13'],
        'offer'=>['hotel'=>'Синтетический Tourvisor','destination'=>'Египет, Хургада','departure'=>'Москва','date_from'=>'2026-10-29','nights'=>11,'adults'=>2,'child_ages'=>[],'room'=>'standard room','meal'=>'AI - Все Включено','amount'=>'160000','currency'=>'RUB','stars'=>3],
        'raw'=>['hotel'=>'Синтетический Tourvisor 3*','destination'=>'Египет, Хургада','date'=>'29 октября','nights'=>'11 ночей','room'=>'standard room','meal'=>'AI - Все Включено','party'=>'2 взрослых','price'=>'160 000 РУБ','price_basis'=>'за номер','base_price'=>'144 000 РУБ','fuel_price'=>'16 000 РУБ','tariff'=>'Synthetic Egypt PROMO','flight_alternatives'=>'XX999 Москва - Хургада; XX998 Хургада - Москва','party_price_confirmation'=>'one_room_two_adults']];
}

function tourvisorCollectorScenarios(PDO $pdo,string $session,string $connection): void
{
    $observation=tourvisorCollectorObservation();$normalized=ManagerTourSelectionImportDraft::normalize($observation);
    deliveryCheck('TV snapshot retains shown basis included fuel and private handle without claiming verified fare',$normalized!==null&&$normalized['offer']['photos']===[]&&str_contains($normalized['offer']['constraints'],'топливо уже включено')&&$normalized['quote_id']===$observation['quote_id']);
    foreach (['hotel','destination','date','nights','room','meal','party','price','price_basis','base_price','fuel_price','party_price_confirmation'] as $key) {$bad=$observation;$bad['raw'][$key].=' changed';deliveryCheck('TV rejects changed raw '.$key,ManagerTourSelectionImportDraft::normalize($bad)===null);}
    foreach (['token=private','%74oken=private','s_adults=2','s_adults[]=2'] as $extra) {$bad=$observation;$bad['url'].='&'.$extra;deliveryCheck('TV rejects unknown secret duplicate and array URL input',ManagerTourSelectionImportDraft::normalize($bad)===null);}
    foreach (['s_j_date_from=29.10.2026'=>'s_j_date_from=28.10.2026','s_adults=2'=>'s_adults=3','s_country=1'=>'s_country=4','x_hotel_codes=502'=>'x_hotel_codes=502,503'] as $from=>$to) {$bad=$observation;$bad['url']=str_replace($from,$to,$bad['url']);deliveryCheck('TV rejects unbound search or unsupported template',ManagerTourSelectionImportDraft::normalize($bad)===null);}
    $bad=$observation;$bad['raw']['party_price_confirmation']='pending';deliveryCheck('TV cannot import a room price before explicit party confirmation',ManagerTourSelectionImportDraft::normalize($bad)===null);
    $bad=$observation;$bad['quote_id']='invented-hotel-price-key';deliveryCheck('TV refuses a fabricated quote fingerprint',ManagerTourSelectionImportDraft::normalize($bad)===null);
    $count=count(ManagerTourSelectionService::get(7,1)['selection']['draft']['offers']);
    $command=['operation_id'=>'tv-snapshot-mixed-0001','connection_id'=>$connection,'observation'=>$observation];
    $first=ManagerTourSelectionService::import(7,1,$session,$command);deliveryCheck('TV appends to the same mixed-source canonical draft',$first['ok']&&$first['count']===$count+1);
    $selection=ManagerTourSelectionService::get(7,1)['selection'];$i=count($selection['draft']['offers'])-1;$selection['draft']['offers'][$i]['amount']='170000';deliverySave($selection['draft'],$selection['version']);
    $repeat=ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'tv-snapshot-repeat-0001']));
    deliveryCheck('TV same snapshot repeat preserves original source and manual price',$repeat['state']==='already_added'&&$repeat['count']===$count+1&&ManagerTourSelectionService::get(7,1)['selection']['draft']['offers'][$i]['amount']==='170000');
    $again=$observation;$again['quote_id']='tv-snapshot-1234567891';
    deliveryCheck('TV regenerated handle does not silently dedup or append equivalent terms',ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'tv-regenerated-0001','observation'=>$again]))['error']==='ambiguous_offer');
    $price=$again;$price['offer']['amount']='161000';$price['raw']['price']='161 000 РУБ';$price['raw']['base_price']='145 000 РУБ';
    deliveryCheck('TV changed price under new handle requires price review',ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'tv-price-change-0001','observation'=>$price]))['error']==='source_price_changed');
    $parts=$observation;$parts['raw']['base_price']='143 000 РУБ';$parts['raw']['fuel_price']='17 000 РУБ';
    deliveryCheck('TV changed included-fuel split is not mistaken for the identical snapshot',ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'tv-parts-change-0001','observation'=>$parts]))['error']==='source_price_changed');
    $room=$again;$room['offer']['room']=$room['raw']['room']='sea view';
    deliveryCheck('TV distinct room remains a distinct exact variant',ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'tv-room-change-0001','observation'=>$room]))['count']===$count+2);
    deliveryCheck('TV explicit ambiguity decision uses the existing variant path',ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'tv-variant-choice-0001','observation'=>$again,'as_variant'=>true]))['count']===$count+3);
    deliveryCheck('TV retains canonical session fence',ManagerTourSelectionService::import(7,1,str_repeat('9',64),array_replace($command,['operation_id'=>'tv-foreign-session-0001']))['error']==='connection_changed');
}

function collectorPriceEditScenarios(PDO $pdo,string $session,string $connection): void
{
    $observations=[collectorObservation(),anexCollectorObservation(),tourvisorCollectorObservation()];
    foreach ($observations as $index=>$observation) {
        $observation['quote_id']=$index===2?'tv-snapshot-1234567899':'0x'.str_repeat('f',$index===0?64:268);
        if ($index===1) {$observation['raw']['room']='Price edit room';$observation['offer']['room']='Price edit room / DBL';}
        else $observation['offer']['room']=$observation['raw']['room']='Price edit room / 2AD';
        if ($index===0) $observation['keys']['room']='29';
        $before=ManagerTourSelectionService::get(7,1)['selection'];
        $command=['operation_id'=>'collector-price-editor-'.$index,'connection_id'=>$connection,'observation'=>$observation,'manager_amount'=>'195432.10'];
        $source=ManagerTourSelectionImportDraft::source(ManagerTourSelectionImportDraft::normalize($observation));
        $missing=ManagerTourSelectionService::importOperation(7,1,$session,$command);
        deliveryCheck('adjusted command preflight '.$index.' acknowledges the exact amount without an append',$missing['ok']&&!$missing['found']&&$missing['manager_amount']==='195432.10'&&ManagerTourSelectionService::get(7,1)['selection']===$before);
        $added=ManagerTourSelectionService::import(7,1,$session,$command);
        $after=ManagerTourSelectionService::get(7,1)['selection'];$offer=$after['draft']['offers'][count($after['draft']['offers'])-1];
        deliveryCheck('pre-add price '.$index.' appends once and keeps raw observed price distinct',$added['ok']&&$added['state']==='added'&&count($after['draft']['offers'])===count($before['draft']['offers'])+1&&$offer['amount']==='195432.10'&&$offer['import_source']===$source&&$offer['photos']===[]&&$after['updated_by_manager_id']===7);
        $found=ManagerTourSelectionService::importOperation(7,1,$session,$command);$repeat=ManagerTourSelectionService::import(7,1,$session,$command);
        deliveryCheck('pre-add price '.$index.' lost-response receipt preserves the exact amount and version',$found['found']&&$found['manager_amount']==='195432.10'&&$repeat['manager_amount']==='195432.10'&&$repeat['saved_version']===$added['saved_version']&&ManagerTourSelectionService::get(7,1)['selection']['version']===$after['version']);
        deliveryCheck('pre-add price '.$index.' cannot reuse intent for another final amount',ManagerTourSelectionService::importOperation(7,1,$session,array_replace($command,['manager_amount'=>'195432.11']))['error']==='operation_mismatch');
        $without=$command;unset($without['manager_amount']);deliveryCheck('pre-add price '.$index.' receipt cannot be mistaken for an unadjusted import',ManagerTourSelectionService::importOperation(7,1,$session,$without)['error']==='operation_mismatch');
        $same=ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-price-same-'.$index,'manager_amount'=>'195432.1']));
        deliveryCheck('pre-add price '.$index.' equivalent decimal is an exact duplicate without a new version',$same['state']==='already_added'&&$same['saved_version']===$after['version']);
        $conflict=ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-price-conflict-'.$index,'manager_amount'=>'190000','as_variant'=>true]));
        deliveryCheck('pre-add price '.$index.' cannot overwrite a prior manual amount or bypass dedup as a variant',$conflict['error']==='manual_price_conflict'&&ManagerTourSelectionService::get(7,1)['selection']===$after);
        $plain=ManagerTourSelectionService::import(7,1,$session,array_replace($without,['operation_id'=>'collector-price-plain-'.$index]));
        deliveryCheck('ordinary repeat '.$index.' preserves the adjusted amount',$plain['state']==='already_added'&&ManagerTourSelectionService::get(7,1)['selection']['draft']['offers'][count($after['draft']['offers'])-1]['amount']==='195432.10');
        foreach (['',null,0,false,[],'0','-1','1e3','12.345','1000000000','NaN','0012','1,00'] as $bad) {
            $invalid=ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-price-invalid-'.$index,'manager_amount'=>$bad]));
            deliveryCheck('invalid adjusted amount is rejected before any append',$invalid['error']==='invalid_import'&&ManagerTourSelectionService::get(7,1)['selection']['version']===$after['version']);
        }
        deliveryCheck('adjusted import still requires the original live session',ManagerTourSelectionService::import(7,1,str_repeat('9',64),array_replace($command,['operation_id'=>'collector-price-session-'.$index]))['error']==='connection_changed');
    }
}

function collectorScenarios(PDO $pdo): void
{
    $session=str_repeat('1',64);$device=str_repeat('2',32);$conn=str_repeat('3',32);$observation=collectorObservation();
    $view=ManagerTourSelectionCollectorSession::view(7,1,$session,$device);
    deliveryCheck('collector view preserves exact actor client and server count',$view['ok']&&$view['target']['actor']===7&&$view['target']['conversation_id']===1&&$view['target']['count']===0&&$view['connection']===null);
    $connect=['device_id'=>$device,'connection_id'=>$conn,'binding'=>$view['target']['binding'],'confirm_current_trip'=>true];
    deliveryCheck('current trip confirmation is explicit',ManagerTourSelectionCollectorSession::connect(7,1,$session,array_replace($connect,['confirm_current_trip'=>false]))['error']==='invalid_connection');
    deliveryCheck('visible colleague cannot connect another owners draft',ManagerTourSelectionCollectorSession::connect(8,1,$session,$connect)['error']==='forbidden');
    deliveryCheck('changed target cannot be silently confirmed',ManagerTourSelectionCollectorSession::connect(7,1,$session,array_replace($connect,['binding'=>str_repeat('f',64)]))['error']==='trip_changed');
    $connected=ManagerTourSelectionCollectorSession::connect(7,1,$session,$connect);
    deliveryCheck('session connection is revocable metadata with durable idempotent create',$connected['ok']&&$connected['connection']['id']===$conn&&ManagerTourSelectionCollectorSession::connect(7,1,$session,$connect)['connection']===$connected['connection']);
    $command=['operation_id'=>'collector-operation-0001','connection_id'=>$conn,'observation'=>$observation];
    $missing=ManagerTourSelectionService::importOperation(7,1,$session,$command);
    deliveryCheck('import status checks exact intent without creating a receipt or exposing draft',$missing['ok']&&!$missing['found']&&!isset($missing['selection'])&&(int)$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn()===0);
    $a=ManagerTourSelectionService::import(7,1,$session,$command);
    deliveryCheck('canonical import appends one complete observed offer',$a['ok']&&$a['state']==='added'&&$a['count']===1&&$a['saved_version']===1);
    $draft=ManagerTourSelectionService::get(7,1)['selection']['draft'];$offer=$draft['offers'][0];
    deliveryCheck('raw source id amount time and unknowns retained without invented local match',$offer['import_source']['quote_id']===$observation['quote_id']&&$offer['import_source']['fields']['amount']==='200000'&&$offer['observed_at']===$observation['observed_at']&&$offer['photos']===[]&&str_contains($offer['constraints'],'каталогу не установлено'));
    deliveryCheck('lost response retries the same persisted command once',ManagerTourSelectionService::import(7,1,$session,$command)['ok']&&ManagerTourSelectionService::get(7,1)['selection']['version']===1);
    $found=ManagerTourSelectionService::importOperation(7,1,$session,$command);
    deliveryCheck('lost response reads the matching receipt without a second append',$found['ok']&&$found['found']&&$found['saved_version']===1&&!isset($found['selection'])&&ManagerTourSelectionService::get(7,1)['selection']['version']===1);
    deliveryCheck('status cannot mistake another payload for saved',ManagerTourSelectionService::importOperation(7,1,$session,array_replace($command,['as_variant'=>true]))['error']==='operation_mismatch');
    $dup=ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-operation-0002']));
    deliveryCheck('another tab exact duplicate returns existing id without another version',$dup['state']==='already_added'&&$dup['offer_id']===$a['offer_id']&&$dup['saved_version']===1);
    deliveryCheck('operation id cannot retarget payload',ManagerTourSelectionService::import(7,1,$session,array_replace($command,['as_variant'=>true]))['error']==='operation_mismatch');
    $draft['offers'][0]['amount']='210000';$draft['offers'][0]['rationale']='Моя ручная правка';$saved=deliverySave($draft,1);
    deliveryCheck('manager price remains separate from immutable source',$saved['ok']&&$saved['selection']['draft']['offers'][0]['amount']==='210000'&&$saved['selection']['draft']['offers'][0]['import_source']['fields']['amount']==='200000');
    $dup=ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-operation-0003']));
    deliveryCheck('repeat never overwrites manual price or review text',$dup['state']==='already_added'&&ManagerTourSelectionService::get(7,1)['selection']['draft']['offers'][0]['amount']==='210000');
    $price=$observation;$price['offer']['amount']='220000';$price['raw']['price']='220 000 RUB';
    deliveryCheck('changed source price requires explicit separate-variant review',ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-price-changed-01','observation'=>$price]))['error']==='source_price_changed');
    $expanded=$observation;$expanded['quote_id']='0x'.str_repeat('b',64);
    deliveryCheck('expanded summary identity ambiguity never silently merges or duplicates',ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-expanded-row-01','observation'=>$expanded]))['error']==='ambiguous_offer');
    $room=$observation;$room['quote_id']='0x'.str_repeat('c',64);$room['keys']['room']='21';$room['offer']['room']=$room['raw']['room']='Family / 2AD';
    deliveryCheck('different exact room is appended from the fresh server draft',ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-room-changed-01','observation'=>$room]))['count']===2);
    $variant=ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-price-variant-01','observation'=>$price,'as_variant'=>true]));
    deliveryCheck('explicit source price variant preserves previous manual price',$variant['ok']&&$variant['count']===3&&ManagerTourSelectionService::get(7,1)['selection']['draft']['offers'][0]['amount']==='210000');
    $forged=ManagerTourSelectionService::get(7,1)['selection'];$forged['draft']['offers'][0]['import_source']['raw']['tour']='Forged source';
    deliveryCheck('normal save cannot rewrite original imported observation',deliverySave($forged['draft'],$forged['version'])['error']==='source_changed');
    $local=ManagerTourSelectionService::get(7,1)['selection'];$server=$local['draft'];$server['intro']='Правка другой вкладки';deliverySave($server,$local['version']);
    $local['draft']['intro']='Мой старый экран';deliveryCheck('stale editor cannot replace imports or another editors text',deliverySave($local['draft'],$local['version'])['error']==='conflict');
    anexCollectorScenarios($pdo,$session,$conn);
    tourvisorCollectorScenarios($pdo,$session,$conn);
    collectorPriceEditScenarios($pdo,$session,$conn);
    deliveryCheck('another PHP session cannot inherit collector connection',ManagerTourSelectionService::import(7,1,str_repeat('4',64),array_replace($command,['operation_id'=>'collector-session-cross-01']))['error']==='connection_changed');
    deliveryCheck('status rechecks the current session connection before receipt disclosure',ManagerTourSelectionService::importOperation(7,1,str_repeat('4',64),$command)['error']==='connection_changed');
    deliveryCheck('another client cannot inherit collector connection',ManagerTourSelectionService::import(7,2,$session,array_replace($command,['operation_id'=>'collector-client-cross-01']))['error']==='connection_changed');
    deliveryCheck('another account cannot import through the old connection',ManagerTourSelectionService::import(8,1,$session,array_replace($command,['operation_id'=>'collector-actor-cross-01']))['error']==='forbidden');
    $pdo->exec('UPDATE conversations SET manager_id=8 WHERE id=1');deliveryCheck('actual assignment loss blocks pending import',ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-assignment-01']))['error']==='forbidden');$pdo->exec('UPDATE conversations SET manager_id=7 WHERE id=1');
    $pdo->exec('UPDATE conversation_sources SET is_active=0 WHERE id=1');deliveryCheck('revoked source blocks collector access',empty(ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-source-loss-01']))['ok']));$pdo->exec('UPDATE conversation_sources SET is_active=1 WHERE id=1');
    $pdo->exec('DELETE FROM manager_projects WHERE manager_id=7');deliveryCheck('revoked project blocks collector access',empty(ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-project-loss-01']))['ok']));$pdo->exec('INSERT INTO manager_projects VALUES(7,1)');
    $pdo->exec("UPDATE conversations SET started_at='2026-10-23 00:00:00' WHERE id=1");deliveryCheck('new conversation trip invalidates old connection',ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-trip-changed-01']))['error']==='connection_changed');$pdo->exec("UPDATE conversations SET started_at='2026-10-20 00:00:00' WHERE id=1");
    deliveryCheck('manager revocation closes subsequent imports',ManagerTourSelectionCollectorSession::revoke(7,1,$session)['ok']&&ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-revoked-01']))['error']==='connection_changed');
    deliveryCheck('revocation also closes collector status access',ManagerTourSelectionService::importOperation(7,1,$session,$command)['error']==='connection_changed');
    deliveryCheck('revoked connection cannot be replay-created',ManagerTourSelectionCollectorSession::connect(7,1,$session,$connect)['error']==='connection_changed');
    $connect['connection_id']=str_repeat('5',32);$c=ManagerTourSelectionCollectorSession::connect(7,1,$session,$connect);deliveryCheck('explicit new connection restores only the same verified target',$c['ok']);
    $pdo->exec("UPDATE manager_tour_selection_collectors SET expires_at='2020-01-01 00:00:00'");$command['connection_id']=$connect['connection_id'];$command['operation_id']='collector-expired-01';deliveryCheck('expired metadata is not a surviving login',ManagerTourSelectionService::import(7,1,$session,$command)['error']==='connection_changed');
    $pdo->exec('UPDATE managers SET is_active=0 WHERE id=7');deliveryCheck('inactive account fails before connection use',ManagerTourSelectionService::import(7,1,$session,$command)['error']==='unauthorized');$pdo->exec('UPDATE managers SET is_active=1 WHERE id=7');
    foreach (['price_verified'=>true,'local_hotel_id'=>99,'photos'=>[['url'=>'https://evil.example/image.jpg']]] as $field=>$value) {$bad=$observation;$bad['offer'][$field]=$value;deliveryCheck('import rejects forged '.$field,ManagerTourSelectionImportDraft::normalize($bad)===null);}
    foreach (['https://b2b.fstravel.com/search_tour?token=secret','https://user@b2b.fstravel.com/search_tour','https://b2b.fstravel.com.evil.example/search_tour','http://b2b.fstravel.com/search_tour'] as $url) {$bad=$observation;$bad['url']=$url;deliveryCheck('source URL credentials secrets and foreign origins rejected',ManagerTourSelectionImportDraft::normalize($bad)===null);}
    $bad=$observation;$bad['raw']['price']='250 000 RUB 200 000 RUB';deliveryCheck('old plus current price text is never parsed as one current amount',ManagerTourSelectionImportDraft::normalize($bad)===null);
    foreach (['hotel','room','meal','nights','date','party'] as $field) {$bad=$observation;$bad['raw'][$field].=' changed';deliveryCheck('raw '.$field.' must describe the exact normalized offer',ManagerTourSelectionImportDraft::normalize($bad)===null);}
    foreach (['ADULT=2'=>'ADULT=3','CHILD=0'=>'CHILD=1','CHECKIN_BEG=20270605'=>'CHECKIN_BEG=20270606','NIGHTS_FROM=7'=>'NIGHTS_FROM=8','STATEINC=1'=>'STATEINC=9'] as $from=>$to) {$bad=$observation;$bad['url']=str_replace($from,$to,$bad['url']);deliveryCheck('source form cannot contradict imported conditions',ManagerTourSelectionImportDraft::normalize($bad)===null);}
    $unfiltered=$observation;$unfiltered['url']=str_replace('PROGRAMINC=3','PROGRAMINC=0',$unfiltered['url']);deliveryCheck('unfiltered FUN&SUN program retains exact row IDs without rejecting the default search',ManagerTourSelectionImportDraft::normalize($unfiltered)['keys']===ManagerTourSelectionImportDraft::normalize($observation)['keys']);
    $decimal=$observation;$decimal['offer']['amount']='200000.00';$decimal['raw']['price']='200 000.00 RUB';deliveryCheck('displayed decimal amount is retained exactly',ManagerTourSelectionImportDraft::normalize($decimal)['offer']['amount']==='200000.00');
    deliveryCheck('no import path sends a tourist message marks read or creates an inquiry',(int)$pdo->query('SELECT COUNT(*) FROM messages WHERE direction="outbound"')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn()===0&&(int)$pdo->query('SELECT last_read_message_id FROM manager_conversation_reads WHERE manager_id=7 AND conversation_id=1')->fetchColumn()===0);
}
