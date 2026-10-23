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

function collectorSchema(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') $pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/057_manager_tour_selection_collectors.sql'));
    else $pdo->exec('CREATE TABLE manager_tour_selection_collectors(connection_id TEXT PRIMARY KEY,manager_id INTEGER,conversation_id INTEGER,device_id TEXT,session_hash TEXT,binding_hash TEXT,created_at TEXT,expires_at TEXT,revoked_at TEXT)');
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
    $decimal=$observation;$decimal['offer']['amount']='200000.00';$decimal['raw']['price']='200 000.00 RUB';deliveryCheck('displayed decimal amount is retained exactly',ManagerTourSelectionImportDraft::normalize($decimal)['offer']['amount']==='200000.00');
    deliveryCheck('no import path sends a tourist message marks read or creates an inquiry',(int)$pdo->query('SELECT COUNT(*) FROM messages WHERE direction="outbound"')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn()===0&&(int)$pdo->query('SELECT last_read_message_id FROM manager_conversation_reads WHERE manager_id=7 AND conversation_id=1')->fetchColumn()===0);
}
