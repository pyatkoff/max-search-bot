<?php
declare(strict_types=1);
require_once __DIR__.'/support/manager-tour-collector.php';
$pdo=deliveryDb();collectorSchema($pdo);collectorScenarios($pdo);
require_once dirname(__DIR__).'/services/ManagerTourSelectionShareService.php';
$pdo=deliveryDb();collectorSchema($pdo);
$pdo->exec('CREATE TABLE manager_tour_selection_links(conversation_id INTEGER PRIMARY KEY,version INTEGER,token TEXT UNIQUE,publication_id TEXT,is_active INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);CREATE TABLE manager_tour_selection_link_history(conversation_id INTEGER,version INTEGER,publication_id TEXT,is_active INTEGER,manager_id INTEGER,created_at TEXT,PRIMARY KEY(conversation_id,version));');
$session=str_repeat('1',64);$device=str_repeat('2',32);$connection=str_repeat('3',32);
$view=ManagerTourSelectionCollectorSession::view(7,1,$session,$device);
ManagerTourSelectionCollectorSession::connect(7,1,$session,['device_id'=>$device,'connection_id'=>$connection,'binding'=>$view['target']['binding'],'confirm_current_trip'=>true]);
$observation=collectorObservation();$command=['operation_id'=>'collector-publication-001','connection_id'=>$connection,'observation'=>$observation];
ManagerTourSelectionService::import(7,1,$session,$command);
$draft=ManagerTourSelectionService::get(7,1)['selection']['draft'];$draft['offers'][0]['photos']=deliveryDraft()['offers'][0]['photos'];$draft['offers'][0]['amount']='210000';
deliverySave($draft,1);$built=ManagerTourSelectionPublicationDraft::build($draft);$without=$draft;unset($without['offers'][0]['import_source']);$manual=ManagerTourSelectionPublicationDraft::build($without);
deliveryCheck('private provenance does not alter native snapshot presentation or creation fence',$built['ok']&&$built['offers'][0]['snapshot']===$manual['offers'][0]['snapshot']&&$built['offers'][0]['presentation']===$manual['offers'][0]['presentation']&&$built['offers'][0]['import_source']['fields']['amount']==='200000');
$prepared=ManagerTourSelectionDeliveryService::prepare(7,1,2);$publication=$prepared['publication']['id'];
$q=$pdo->prepare('SELECT selection_json FROM manager_tour_selection_publications WHERE publication_id=?');$q->execute([$publication]);$frozen=$q->fetchColumn();
$token=str_repeat('f',64);$pdo->prepare('INSERT INTO manager_tour_selection_links VALUES(?,?,?,?,?,?,?)')->execute([1,1,$token,$publication,1,7,gmdate('Y-m-d H:i:s')]);
$page=ManagerTourSelectionShareService::read($token);$json=json_encode($page,JSON_THROW_ON_ERROR);
deliveryCheck('public projection excludes raw source quote external IDs original price and exact ages',!str_contains($json,'import_source')&&!str_contains($json,'quote_id')&&!str_contains($json,'200000')&&!str_contains($json,'b2b.fstravel.com')&&!str_contains($json,'child_ages')&&$page['offers'][0]['amount']==='210000');
$room=$observation;$room['keys']['room']='21';$room['quote_id']='0x'.str_repeat('b',64);$room['offer']['room']=$room['raw']['room']='Family / 2AD';
ManagerTourSelectionService::import(7,1,$session,array_replace($command,['operation_id'=>'collector-publication-002','observation'=>$room]));
$q->execute([$publication]);
deliveryCheck('later imports preserve existing public URL and immutable shown version',ManagerTourSelectionShareService::read($token)===$page&&$q->fetchColumn()===$frozen&&ManagerTourSelectionShareService::get(7,1)['share']['path']==='/selection/?t='.$token);
deliveryCheck('import and preview still make no native create or confirmed tourist choice',(int)$pdo->query("SELECT COUNT(*) FROM messages WHERE direction='outbound'")->fetchColumn()===0&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='tour_offer_liked'")->fetchColumn()===0);

// Automatic gallery material enriches the exact source quote; it is not identity.
$photoBase='https://apigate-tui.fstravel.com/api/geocontent/static/Hotel/00180000-ac11-0242-6c05-08d9934f9e4b/';
$auto=collectorObservation();$auto['photos']=array_map(static fn(string $kind):string=>$photoBase.$kind.'-Source-automatic.jpg',['MainPhoto','Gallery','InsideOfTheRoom']);
$normalized=ManagerTourSelectionImportDraft::normalize($auto);$legacy=ManagerTourSelectionImportDraft::normalize(collectorObservation());
deliveryCheck('automatic source photos enter the editable offer and private provenance',$normalized!==null&&count($normalized['offer']['photos'])===3&&ManagerTourSelectionImportDraft::source($normalized)['photos']===$auto['photos']);
deliveryCheck('photo enrichment does not change exact terms or price fingerprints',ManagerTourSelectionImportDraft::fingerprint(ManagerTourSelectionImportDraft::source($normalized))===ManagerTourSelectionImportDraft::fingerprint(ManagerTourSelectionImportDraft::source($legacy))&&!isset($legacy['photos']));
foreach ([['wrong'=>$auto['photos'][0]],[$auto['photos'][0],$auto['photos'][0]],array_merge($auto['photos'],[$auto['photos'][0]])] as $photos){$bad=$auto;$bad['photos']=$photos;deliveryCheck('malformed duplicate or oversized automatic gallery is refused',ManagerTourSelectionImportDraft::normalize($bad)===null);}
foreach (['?token=secret','?%74oken=secret','#fragment'] as $suffix){$bad=$auto;$bad['photos'][0].=$suffix;deliveryCheck('automatic gallery does not persist credentials query or fragment',ManagerTourSelectionImportDraft::normalize($bad)===null);}
$bad=$auto;$bad['photos'][1]=str_replace('00180000','00280000',$bad['photos'][1]);deliveryCheck('mixed FUNSUN hotel UUID gallery is refused',ManagerTourSelectionImportDraft::normalize($bad)===null);
$missingPhotos=$auto;$missingPhotos['photos']=[];$missingImport=ManagerTourSelectionImportDraft::normalize($missingPhotos);deliveryCheck('unavailable automatic gallery is explicit without fabricated photos',$missingImport!==null&&$missingImport['photos']===[]&&$missingImport['offer']['photos']===[]);
$anex=anexCollectorObservation();$anex['photos']=['https://files.anextour.ru/hotel/hotels/turkey/synthetic/o1?&hotelCode=100'];
deliveryCheck('ANEX one-photo gallery normalizes with its exact hotel code',count(ManagerTourSelectionImportDraft::normalize($anex)['offer']['photos'])===1);
$bad=$anex;$bad['photos'][0]=str_replace('hotelCode=100','hotelCode=101',$bad['photos'][0]);deliveryCheck('ANEX gallery cannot borrow another hotels image',ManagerTourSelectionImportDraft::normalize($bad)===null);
$tv=tourvisorCollectorObservation();$tv['photos']=['https://static.tourvisor.ru/hotel_pics/verybig/'.$tv['keys']['country'].'/'.$tv['keys']['hotel'].'-1.jpg'];
deliveryCheck('Tourvisor gallery normalizes against its exact source hotel prefix',ManagerTourSelectionImportDraft::normalize($tv)!==null);
$bad=$tv;$bad['photos'][0]=str_replace('/'.$tv['keys']['hotel'].'-','/999999-',$bad['photos'][0]);deliveryCheck('Tourvisor gallery rejects another source hotel prefix',ManagerTourSelectionImportDraft::normalize($bad)===null);
$pdo=deliveryDb();collectorSchema($pdo);$view=ManagerTourSelectionCollectorSession::view(7,1,$session,$device);
ManagerTourSelectionCollectorSession::connect(7,1,$session,['device_id'=>$device,'connection_id'=>$connection,'binding'=>$view['target']['binding'],'confirm_current_trip'=>true]);
$cmd=['operation_id'=>'automatic-photos-original','connection_id'=>$connection,'observation'=>collectorObservation()];ManagerTourSelectionService::import(7,1,$session,$cmd);
$current=ManagerTourSelectionService::get(7,1)['selection'];$originalId=$current['draft']['offers'][0]['id'];$current['draft']['offers'][0]['amount']='215000';$current['draft']['offers'][0]['rationale']='Согласованная правка';deliverySave($current['draft'],$current['version']);
$cmd['operation_id']='automatic-photos-enrich';$cmd['observation']=$auto;$added=ManagerTourSelectionService::import(7,1,$session,$cmd);$current=ManagerTourSelectionService::get(7,1)['selection'];
deliveryCheck('re-adding a photo-less legacy import enriches without a duplicate or lost manager price',$added['ok']&&$added['state']==='already_added'&&count($current['draft']['offers'])===1&&$current['draft']['offers'][0]['id']===$originalId&&$current['draft']['offers'][0]['amount']==='215000'&&$current['draft']['offers'][0]['rationale']==='Согласованная правка'&&count($current['draft']['offers'][0]['photos'])===3);
deliveryCheck('uncertain enrichment confirms its exact receipt without another revision',ManagerTourSelectionService::importOperation(7,1,$session,$cmd)['found']&&ManagerTourSelectionService::import(7,1,$session,$cmd)['saved_version']===$current['version']);
$previous=$current;$cmd['operation_id']='automatic-photos-new-gallery';$cmd['observation']['photos'][0]=str_replace('automatic','updated',$auto['photos'][0]);ManagerTourSelectionService::import(7,1,$session,$cmd);
deliveryCheck('repeat source import preserves already selected photos and all manual fields',ManagerTourSelectionService::get(7,1)['selection']['draft']===$previous['draft']);
$forged=$previous['draft'];$forged['offers'][0]['import_source']['photos'][0]=str_replace('automatic','forged',$auto['photos'][0]);deliveryCheck('ordinary save cannot forge the immutable imported photo provenance',deliverySave($forged,$previous['version'])['error']==='source_changed');
foreach ([1,2,3] as $count) foreach ([1,2] as $conversation){
    $pdo=deliveryDb();$observation=$auto;$observation['photos']=array_slice($auto['photos'],0,$count);$saved=collectorImportedDraft($pdo,$observation,$conversation);$draft=$saved['draft'];
    deliveryCheck('automatic '.$count.'-photo draft imports for channel '.$conversation,$saved['version']===1&&count($draft['offers'][0]['photos'])===$count);
    $built=ManagerTourSelectionPublicationDraft::build($draft);deliveryCheck('automatic gallery prepares without requiring three manual photos',$built['ok']&&count($built['offers'][0]['presentation']['photos'])===$count&&$built['offers'][0]['presentation']['layout']==='gallery-v1');
    $prepared=ManagerTourSelectionDeliveryService::prepare(7,$conversation,1);$publication=$prepared['publication']['id'];deliveryCheck('preview exposes the actual photo count',count($prepared['publication']['cards'][0]['photos'])===$count);
    $q=$pdo->prepare('SELECT callback_data FROM manager_tour_selection_cards WHERE publication_id=?');$q->execute([$publication]);$callback=$q->fetchColumn();$card=TourOfferCardRenderer::render($built['offers'][0]['snapshot'],$built['offers'][0]['presentation'],$callback);
    deliveryCheck('automatic gallery captions do not claim selected-room photos',str_contains($card['plain_text'],'Фотографии отеля.')&&!str_contains($card['plain_text'],'Фото номера —'));
    $state=['calls'=>[],'creates'=>0,'readback'=>true,'card'=>$card];$transport=deliveryTransport($state);$sent=ManagerTourSelectionDeliveryService::send(7,$conversation,$publication,0,$transport,'deliveryImages');
    deliveryCheck('exact actual photo count delivers once through the existing transport',$sent['ok']&&$sent['publication']['cards'][0]['state']==='delivered'&&$state['creates']===1);
    deliveryCheck('gallery send retry preserves the one native creation',ManagerTourSelectionDeliveryService::send(7,$conversation,$publication,0,$transport,'deliveryImages')['ok']&&$state['creates']===1);
    $native=$conversation===1?['message_id'=>7001,'chat'=>['id'=>101,'type'=>'private'],'from'=>['id'=>999,'is_bot'=>true],'rich_message'=>$card['telegram']['rich_message'],'reply_markup'=>$card['telegram']['reply_markup']]:$state['native'];
    if($conversation===1){foreach($native['rich_message']['blocks'][0]['blocks'] as &$block)$block['photo']=[['file_id'=>'synthetic-photo','width'=>800,'height'=>600]];unset($block);array_pop($native['rich_message']['blocks'][0]['blocks']);$identity=['user'=>'101','bot'=>'999'];}else{array_shift($native['body']['attachments']);$identity=['user'=>'202','bot'=>'999','chat'=>'555'];}
    deliveryCheck('native verification rejects a response with a missing gallery image',!TourSelectionNativeTransport::matches($conversation===1?'telegram':'max',$card,$identity,$conversation===1?'7001':'mid.sent.1',$native));
}
$partner=partnerCollectorObservation();$normalized=ManagerTourSelectionImportDraft::normalize($partner);
deliveryCheck('FUNSUN partner snapshot retains signed hotel unknown keys and exact displayed room',$normalized!==null&&$normalized['keys']['hotel']==='-10'&&$normalized['keys']['room']==='-2147483647'&&$normalized['keys']['spo']===''&&$normalized['offer']['room']==='DOUBLE STANDARD / 2ADL'&&count($normalized['offer']['photos'])===3&&str_contains($normalized['offer']['constraints'],'не установлено'));
foreach (['hotel'=>'10','room'=>'20','placement'=>'40','spo'=>'60','partner'=>'OTHER'] as $key=>$value){$bad=$partner;$bad['keys'][$key]=$value;deliveryCheck('partner profile cannot reinterpret unsupported '.$key.' identity',ManagerTourSelectionImportDraft::normalize($bad)===null);}
$bad=$partner;unset($bad['keys']['partner']);deliveryCheck('negative identities need the explicit observed partner profile',ManagerTourSelectionImportDraft::normalize($bad)===null);
$bad=$partner;$bad['keys']['hotel']='-2147483647';$bad['raw']['hotel_url']='https://b2b.fstravel.com/hotel/-2147483647?partner=HBK';deliveryCheck('unknown-room sentinel cannot become a partner hotel identity',ManagerTourSelectionImportDraft::normalize($bad)===null);
foreach (['https://evil.example/hotel/-10?partner=HBK','https://b2b.fstravel.com/hotel/-11?partner=HBK','https://b2b.fstravel.com/hotel/-10','https://b2b.fstravel.com/hotel/-10?partner=HBK&token=secret','https://b2b.fstravel.com/hotel/-10?partner=HBK#x'] as $url){$bad=$partner;$bad['raw']['hotel_url']=$url;deliveryCheck('partner hotel link must bind the exact hotel without secrets or another source',ManagerTourSelectionImportDraft::normalize($bad)===null);}
$bad=$partner;unset($bad['raw']['hotel_url']);deliveryCheck('partner format requires its observed source hotel link',ManagerTourSelectionImportDraft::normalize($bad)===null);
$bad=collectorObservation();$bad['raw']['hotel_url']=$partner['raw']['hotel_url'];deliveryCheck('legacy native grammar cannot acquire partner metadata',ManagerTourSelectionImportDraft::normalize($bad)===null);
foreach (['https://hbpro.expert/resource/hotel/photo_1200/11/23/104.jpg','https://hbpro.expert/resource/hotel/photo_1200/11/22/104.jpg?token=secret','https://s-1001.images.hotelbook.ru/5232181/primary',$auto['photos'][0]] as $url){$bad=$partner;$bad['photos'][1]=$url;deliveryCheck('partner gallery rejects mixed hotels query values and unbound room photos',ManagerTourSelectionImportDraft::normalize($bad)===null);}
$pdo=deliveryDb();$saved=collectorImportedDraft($pdo,$partner);$draft=$saved['draft'];$partnerId=$draft['offers'][0]['id'];
deliveryCheck('partner row uses the canonical collector import and editable photo draft',$saved['version']===1&&count($draft['offers'])===1&&$draft['offers'][0]['import_source']['keys']['hotel']==='-10'&&$draft['offers'][0]['import_source']['raw']['hotel_url']===$partner['raw']['hotel_url']&&count($draft['offers'][0]['photos'])===3);
$draft['offers'][0]['amount']='210000';$draft['offers'][0]['rationale']='Согласованная ручная правка';deliverySave($draft,1);
$cmd=['operation_id'=>'partner-exact-repeat-001','connection_id'=>str_repeat('3',32),'observation'=>$partner];$result=ManagerTourSelectionService::import(7,1,str_repeat('1',64),$cmd);$current=ManagerTourSelectionService::get(7,1)['selection'];
deliveryCheck('partner exact repeat preserves manual price text photos offer id and revision',$result['ok']&&$result['state']==='already_added'&&$current['version']===2&&count($current['draft']['offers'])===1&&$current['draft']['offers'][0]['id']===$partnerId&&$current['draft']['offers'][0]['amount']==='210000'&&$current['draft']['offers'][0]['rationale']==='Согласованная ручная правка');
deliveryCheck('partner receipt resolves a lost response without a second import',ManagerTourSelectionService::importOperation(7,1,str_repeat('1',64),$cmd)['found']&&ManagerTourSelectionService::import(7,1,str_repeat('1',64),$cmd)['saved_version']===2);
$changed=$cmd;$changed['operation_id']='partner-different-room-001';$changed['observation']['offer']['room']=$changed['observation']['raw']['room']='SUPERIOR / 2ADL';
deliveryCheck('same opaque claim and unknown room id do not collapse another displayed room',ManagerTourSelectionService::import(7,1,str_repeat('1',64),$changed)['error']==='ambiguous_offer'&&ManagerTourSelectionService::get(7,1)['selection']['draft']===$current['draft']);
$changed['as_variant']=true;$added=ManagerTourSelectionService::import(7,1,str_repeat('1',64),$changed);$current=ManagerTourSelectionService::get(7,1)['selection'];
deliveryCheck('explicit partner room variant keeps separate conditions and original manual edits',$added['ok']&&count($current['draft']['offers'])===2&&$current['draft']['offers'][0]['amount']==='210000'&&$current['draft']['offers'][1]['room']==='SUPERIOR / 2ADL');
$built=ManagerTourSelectionPublicationDraft::build($current['draft']);deliveryCheck('partner images prepare with the existing neutral gallery without a fabricated match',$built['ok']&&$built['offers'][0]['presentation']['layout']==='gallery-v1'&&count($built['offers'][0]['presentation']['photos'])===3&&!$built['offers'][0]['snapshot']['price_verified']);
echo "COLLECTOR TOTAL {$checks} PASS\n";
