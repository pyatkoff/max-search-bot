<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/regional_access_schema.php';

/** Real canonical transaction/receipts, disposable SQLite memory only. No HTTP. */
require_once __DIR__.'/../services/ManagerTourSelectionService.php';
require_once __DIR__.'/../services/ManagerTourSelectionPublicationDraft.php';
require_once __DIR__.'/../services/ManagerTourSelectionOneTouchIntake.php';
require_once __DIR__.'/fixtures/onetouch-public.php';
$p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);regionalAccessFixtureSchema($p);
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$p);
foreach([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class)(new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
$p->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT);
CREATE TABLE manager_customer_names(customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);
CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,external_chat_id TEXT);
CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,display_name TEXT,primary_group_id INTEGER,fallback_mode TEXT,fallback_group_id INTEGER,fallback_after_minutes INTEGER,is_active INTEGER);
CREATE TABLE manager_group_members(manager_id INTEGER,group_id INTEGER);
CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,text TEXT);
CREATE TABLE manager_conversation_reads(manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER);
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT);
CREATE TABLE manager_tour_selection_drafts(conversation_id INTEGER PRIMARY KEY,version INTEGER,draft_json TEXT,updated_by_manager_id INTEGER,updated_at TEXT);
CREATE TABLE manager_tour_selection_operations(manager_id INTEGER,operation_id TEXT,conversation_id INTEGER,request_hash TEXT,saved_version INTEGER,created_at TEXT,PRIMARY KEY(manager_id,operation_id));
INSERT INTO projects VALUES(1,'notes-fixture','Synthetic project',1);
INSERT INTO manager_projects VALUES(8,1),(7,1);
INSERT INTO managers VALUES(8,'fixture','Synthetic owner','manager','',1,1),(7,'other','Synthetic colleague','manager','',1,1);
INSERT INTO customers VALUES(1,'Synthetic client','','');
INSERT INTO conversation_sources VALUES(1,'Synthetic source',0,'none',0,0,1);
INSERT INTO conversations(id,customer_id,project_key,source_id,channel,status,manager_id,started_at) VALUES(1,1,'notes-fixture',1,'max','manager',8,'2026-10-14 00:00:00');
INSERT INTO messages VALUES(11,1,'Synthetic untouched transcript');INSERT INTO manager_conversation_reads VALUES(8,1,10);INSERT INTO conversation_events VALUES(1,1,'synthetic_unchanged_event');");
$count=0;function storeCheck(bool $v,string $n):void{global $count;if(!$v)throw new RuntimeException('FAIL '.$n);$count++;}
$legacy=ManagerTourSelectionDraft::normalize(['title'=>'Synthetic existing selection','intro'=>'Unchanged','offers'=>[['id'=>str_repeat('b',32),'selected'=>true,'hotel'=>'Synthetic retained operator offer','source_label'=>'Библио-Глобус · synthetic fixture','departure'=>'Synthetic City','date_from'=>'2077-01-07','nights'=>7,'adults'=>2,'child_ages'=>[],'room'=>'Unchanged room','meal'=>'Unchanged meal','amount'=>'180000','currency'=>'RUB','photos'=>[]],['id'=>str_repeat('c',32),'selected'=>false,'hotel'=>'Synthetic retained placeholder','amount'=>'60000','currency'=>'RUB','photos'=>[]]]]);
$saved=ManagerTourSelectionService::save(8,1,['version'=>0,'draft'=>$legacy,'operation_id'=>'fixture-original-save-0001']);storeCheck($saved['ok']===true,'existing draft initialized');
$before=[];foreach(['messages','conversation_events','manager_conversation_reads','conversations','managers'] as $table)$before[$table]=$p->query('SELECT * FROM '.$table)->fetchAll();
$f=syntheticOneTouchSource();$now=time();$bundle=['schema'=>'onetouch-manager-capture-v1','reference'=>'SYNTHETIC_STORE_CAPTURE','captured_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'url'=>$f['url'],'public_controls'=>['TOWNFROMINC'=>['value'=>'2','text'=>'Synthetic City'],'STATEINC'=>['value'=>'9','text'=>'Synthetic Country'],'CURRENCY'=>['value'=>'1','text'=>'RUB']],'prices_gzip_base64'=>base64_encode(gzencode($f['response'])),'prices_sha256'=>hash('sha256',$f['response']),'galleries'=>[['hotel'=>'1001','url'=>'https://onetouch.travel/hotels/synthetic-hotel-1001/','observed_at'=>'2020-01-01T00:00:00Z','html_sha256'=>hash('sha256',$f['hotel']),'images'=>[['url'=>$f['photo'],'sha256'=>hash('sha256','synthetic image byte binding')]]]]];
$state=[];$key=ManagerTourSelectionOneTouchIntake::sessionKey($state,str_repeat('a',64));
$r=ManagerTourSelectionOneTouchIntake::search(8,1,$key,['capture'=>$bundle]);storeCheck($r['ok']===true,'canonical authorized source context');
$r=ManagerTourSelectionOneTouchIntake::review(8,1,$key,['ticket'=>$r['offers'][0]['ticket'],'gallery_gzip_base64'=>base64_encode(gzencode($f['hotel']))]);storeCheck($r['ok']===true,'same canonical ready preview');
$command=['operation_id'=>'fixture-onetouch-add-0001','ticket'=>$r['ticket'],'confirm_price_from'=>true,'manager_amount'=>'130000'];
$notYet=ManagerTourSelectionService::importOneTouch(8,1,$key,$command,true);storeCheck($notYet['ok']&&$notYet['found']===false,'readonly absence creates no operation');
storeCheck((int)$p->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn()===1,'readonly absence leaves only old receipt');
$add=ManagerTourSelectionService::importOneTouch(8,1,$key,$command);storeCheck($add['ok']&&$add['state']==='added'&&$add['count']===3,'one distinct source quote appends once');
$view=ManagerTourSelectionService::get(8,1)['selection'];storeCheck(array_slice($view['draft']['offers'],0,2)===$legacy['offers'],'all prior operator and placeholder bytes unchanged');$offer=$view['draft']['offers'][2];
storeCheck($offer['photos']===[]&&$offer['import_source']['photos']===[$f['photo']],'source CDN never becomes a pending browser image');
storeCheck($offer['amount']==='130000'&&$offer['price_qualifier']==='from'&&$offer['import_source']['fields']['amount']==='123456.78','manual/source amounts distinct with minimum retained');
require_once __DIR__.'/../services/ManagerTourSelectionReferenceContext.php';
$referenceConversation=$p->query('SELECT * FROM conversations WHERE id=1')->fetch();
storeCheck(ManagerTourSelectionReferenceContext::capture($p,$referenceConversation,['conversation_id'=>1])===null,'shared AI evidence preserves project boundary');
$reference=ManagerTourSelectionReferenceContext::capture($p,array_replace($referenceConversation,['project_key'=>'anytour']),['conversation_id'=>1]);
storeCheck($reference!==null&&$reference['offers'][1]['price_qualifier']==='from'&&$reference['offers'][1]['source_observation']['fields']['price_qualifier']==='from'&&$reference['current_price_verified']===false,'shared AI evidence retains minimum without current-price verification');
$recover=ManagerTourSelectionService::importOneTouch(8,1,$key,$command,true);storeCheck($recover['ok']&&$recover['found']===true&&$recover['saved_version']===$add['saved_version'],'exact saved intent reconciles readonly');
$repeat=ManagerTourSelectionService::importOneTouch(8,1,$key,$command);storeCheck($repeat['ok']&&$repeat['state']==='saved'&&$repeat['count']===3,'same intent backend receipt never adds duplicate');
$bad=$command;$bad['manager_amount']='140000';storeCheck((ManagerTourSelectionService::importOneTouch(8,1,$key,$bad)['error']??'')==='operation_mismatch','same operation cannot change payload');
storeCheck((int)$p->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn()===2,'one old and one imported receipt only');
storeCheck((ManagerTourSelectionService::importOneTouch(7,1,$key,$command,true)['error']??'')==='onetouch_expired','foreign actor cannot read signed intent');
foreach($before as $table=>$rows)storeCheck($p->query('SELECT * FROM '.$table)->fetchAll()===$rows,'no collateral change '.$table);
// Real canonical transactions distinguish otherwise identical offers by party.
$beforePartyAdds=ManagerTourSelectionService::get(8,1)['selection']['draft']['offers'];
$partyIds=[$offer['id']];$expectedCount=count($beforePartyAdds);
foreach([1,3,14] as $adults){
    $adultFixture=syntheticOneTouchSource($adults);$adultBundle=$bundle;
    $adultBundle['reference']='SYNTHETIC_STORE_ADULT_'.$adults;$adultBundle['url']=$adultFixture['url'];
    $adultBundle['prices_gzip_base64']=base64_encode(gzencode($adultFixture['response']));$adultBundle['prices_sha256']=hash('sha256',$adultFixture['response']);
    $adultSearch=ManagerTourSelectionOneTouchIntake::search(8,1,$key,['capture'=>$adultBundle]);
    storeCheck($adultSearch['ok']===true,'party capture admitted in current source context '.$adults);
    $adultReady=ManagerTourSelectionOneTouchIntake::review(8,1,$key,['ticket'=>$adultSearch['offers'][0]['ticket'],'gallery_gzip_base64'=>base64_encode(gzencode($adultFixture['hotel']))]);
    storeCheck($adultReady['ok']===true,'party ready ticket '.$adults);
    $adultCommand=['operation_id'=>'fixture-onetouch-party-'.$adults.'-0001','ticket'=>$adultReady['ticket'],'confirm_price_from'=>true,'manager_amount'=>'130000'];
    $priorPartyOffers=ManagerTourSelectionService::get(8,1)['selection']['draft']['offers'];
    $adultAdded=ManagerTourSelectionService::importOneTouch(8,1,$key,$adultCommand);$expectedCount++;
    storeCheck($adultAdded['ok']===true&&$adultAdded['state']==='added'&&$adultAdded['count']===$expectedCount,'different party appends a distinct offer '.$adults);
    $partyView=ManagerTourSelectionService::get(8,1)['selection'];$partyOffer=$partyView['draft']['offers'][array_key_last($partyView['draft']['offers'])];$partyIds[]=$partyOffer['id'];
    storeCheck(array_slice($partyView['draft']['offers'],0,count($priorPartyOffers))===$priorPartyOffers,'old offers including other party remain byte-identical '.$adults);
    storeCheck($partyOffer['adults']===$adults&&$partyOffer['child_ages']===[]&&$partyOffer['import_source']['fields']['adults']===$adults&&$partyOffer['import_source']['raw']['party']===$adults.'AD','saved canonical and original party agree '.$adults);
    storeCheck($partyOffer['amount']==='130000'&&$partyOffer['price_qualifier']==='from'&&$partyOffer['import_source']['fields']['amount']==='123456.78'&&$partyOffer['photos']===[],'manual amount from and deferred private photos preserved '.$adults);
    $adultReceipt=ManagerTourSelectionService::importOneTouch(8,1,$key,$adultCommand,true);
    storeCheck($adultReceipt['ok']===true&&$adultReceipt['found']===true&&$adultReceipt['saved_version']===$adultAdded['saved_version'],'exact party receipt is reconciled read-only '.$adults);
    $adultRepeat=ManagerTourSelectionService::importOneTouch(8,1,$key,$adultCommand);
    storeCheck($adultRepeat['ok']===true&&$adultRepeat['state']==='saved'&&$adultRepeat['count']===$expectedCount,'same confirmed party operation cannot duplicate '.$adults);
}
storeCheck(count(array_unique($partyIds))===4,'two one three and fourteen adult offers have distinct canonical identities');
storeCheck(array_slice(ManagerTourSelectionService::get(8,1)['selection']['draft']['offers'],0,count($beforePartyAdds))===$beforePartyAdds,'all pre-existing source snapshots survive the party range');
storeCheck((int)$p->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn()===5,'one initial and four distinct source receipts only');
foreach($before as $table=>$rows)storeCheck($p->query('SELECT * FROM '.$table)->fetchAll()===$rows,'new party imports cause no collateral change '.$table);


// Direct URL photos are already canonical: no counterfeit file mode or upload.
$beforeDirect=ManagerTourSelectionService::get(8,1)['selection']['draft']['offers'];
$fDirect=syntheticOneTouchSource(3);$qDirect=$fDirect['query'];$qDirect['CHECKIN_BEG']='20770108';$qDirect['CHECKIN_END']='20770108';$qDirect['rev']='3187520523';
$urlDirect='https://b2b.onetouch.travel/search_tour?'.http_build_query($qDirect,'','&',PHP_QUERY_RFC3986);
$responseDirect=str_replace(['20770107','07.01.2077'],['20770108','08.01.2077'],$fDirect['response']);
$searchDirect=ManagerTourSelectionOneTouchIntake::search(8,1,$key,['url'=>$urlDirect],static fn($url):array=>['url'=>$urlDirect,'response'=>$responseDirect,'form'=>$fDirect['form'],'observed_at'=>gmdate('Y-m-d\TH:i:s\Z')]);
storeCheck($searchDirect['ok']===true,'direct source uses actual canonical context');
$reviewDirect=ManagerTourSelectionOneTouchIntake::review(8,1,$key,['ticket'=>$searchDirect['offers'][0]['ticket']],static fn($url):string=>$fDirect['hotel']);
storeCheck($reviewDirect['ok']===true,'direct linked gallery produces normal ready ticket');
$collisionOperations=(int)$p->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn();
$collision=ManagerTourSelectionService::importOneTouch(8,1,$key,['operation_id'=>'fixture-direct-native-collision-0001','ticket'=>$reviewDirect['ticket'],'confirm_price_from'=>true,'manager_amount'=>'135000']);
storeCheck(($collision['error']??'')==='ambiguous_offer','same native quote id with different trip is correctly refused');
storeCheck(ManagerTourSelectionService::get(8,1)['selection']['draft']['offers']===$beforeDirect&&(int)$p->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn()===$collisionOperations,'native identity collision changes neither saved offers nor receipts');
// A distinct trip has its own native claim; do not weaken the production guard.
$responseDirect=str_replace('0x'.hash('sha256','synthetic-adults-3'),'0x'.hash('sha256','synthetic-direct-20770108-adults-3'),$responseDirect);
$searchDirect=ManagerTourSelectionOneTouchIntake::search(8,1,$key,['url'=>$urlDirect],static fn($url):array=>['url'=>$urlDirect,'response'=>$responseDirect,'form'=>$fDirect['form'],'observed_at'=>gmdate('Y-m-d\TH:i:s\Z')]);
storeCheck($searchDirect['ok']===true,'distinct native direct quote admitted without overwriting old identity');
$reviewDirect=ManagerTourSelectionOneTouchIntake::review(8,1,$key,['ticket'=>$searchDirect['offers'][0]['ticket']],static fn($url):string=>$fDirect['hotel']);
storeCheck($reviewDirect['ok']===true,'distinct native direct ready ticket keeps linked photo provenance');
$directCommand=['operation_id'=>'fixture-direct-lost-receipt-0001','ticket'=>$reviewDirect['ticket'],'confirm_price_from'=>true,'manager_amount'=>'135000'];
$beforeDirectOperations=(int)$p->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn();
$directAdd=ManagerTourSelectionService::importOneTouch(8,1,$key,$directCommand);
storeCheck($directAdd['ok']===true&&$directAdd['count']===count($beforeDirect)+1,'direct ready offer appends once');
$directDraft=ManagerTourSelectionService::get(8,1)['selection']['draft']['offers'];$directOffer=$directDraft[array_key_last($directDraft)];
storeCheck(array_slice($directDraft,0,count($beforeDirect))===$beforeDirect,'saved Biblio and prior file offers remain byte-identical');
storeCheck($directOffer['photos']===[['url'=>$fDirect['photo'],'kind'=>'cover','illustrative'=>true]]&&$directOffer['import_source']['photos']===[$fDirect['photo']],'normal direct import retains native canonical URL photo without deferred upload');
storeCheck(!isset($directOffer['import_source']['raw']['capture_mode'])&&$directOffer['import_source']['url']===$urlDirect,'direct original executed URL retained without fake capture mode');
storeCheck($directOffer['amount']==='135000'&&$directOffer['import_source']['fields']['amount']==='123456.78'&&$directOffer['price_qualifier']==='from'&&$directOffer['adults']===3,'manual price source minimum and exact party retained separately');
$directRecovered=ManagerTourSelectionService::importOneTouch(8,1,$key,$directCommand,true);
storeCheck($directRecovered['ok']&&$directRecovered['found']&&$directRecovered['saved_version']===$directAdd['saved_version'],'lost direct receipt reconciles original operation readonly without requiring a new offer id');
storeCheck((int)$p->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn()===$beforeDirectOperations+1&&ManagerTourSelectionService::get(8,1)['selection']['draft']['offers']===$directDraft,'readonly direct recovery performs no second import or photo write');
foreach($before as $table=>$rows)storeCheck($p->query('SELECT * FROM '.$table)->fetchAll()===$rows,'direct import causes no collateral change '.$table);


// Family writes use the same canonical transaction and original-operation receipts.
$familyStart=ManagerTourSelectionService::get(8,1)['selection']['draft']['offers'];$familyCommands=[];
foreach([[8],[8,8],[0,8,17]] as $ages){
    $ff=syntheticOneTouchSource(2,$ages);$fs=ManagerTourSelectionOneTouchIntake::search(8,1,$key,['url'=>$ff['url']],static fn($url):array=>['url'=>$ff['url'],'response'=>$ff['response'],'form'=>$ff['form'],'observed_at'=>gmdate('Y-m-d\TH:i:s\Z')]);
    storeCheck($fs['ok'],'family obtains current canonical context '.implode(',',$ages));$fr=ManagerTourSelectionOneTouchIntake::review(8,1,$key,['ticket'=>$fs['offers'][0]['ticket']],static fn($url):string=>$ff['hotel']);storeCheck($fr['ok'],'family gallery bound to ticket '.implode(',',$ages));
    $cmd=['operation_id'=>'fixture-family-'.implode('-',$ages).'-0001','ticket'=>$fr['ticket'],'confirm_price_from'=>true,'manager_amount'=>'145000'];$prior=ManagerTourSelectionService::get(8,1)['selection']['draft']['offers'];$ops=(int)$p->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn();
    $added=ManagerTourSelectionService::importOneTouch(8,1,$key,$cmd);$view=ManagerTourSelectionService::get(8,1)['selection']['draft']['offers'];$savedFamily=$view[array_key_last($view)];
    storeCheck($added['ok']&&$added['state']==='added'&&count($view)===count($prior)+1&&array_slice($view,0,count($prior))===$prior,'family appends once with all prior Biblio and OneTouch untouched '.implode(',',$ages));
    storeCheck($savedFamily['child_ages']===$ages&&$savedFamily['import_source']['fields']['child_ages']===$ages&&$savedFamily['import_source']['raw']['party']==='2AD+'.count($ages).'CH('.implode(',',$ages).')'&&$savedFamily['import_source']['url']===$ff['url'],'requested vector multiplicity survives canonical database '.implode(',',$ages));
    storeCheck($savedFamily['amount']==='145000'&&$savedFamily['import_source']['fields']['amount']==='123456.78'&&$savedFamily['price_qualifier']==='from'&&$savedFamily['photos']===[['url'=>$ff['photo'],'kind'=>'cover','illustrative'=>true]],'family price override and source photos retained separately '.implode(',',$ages));
    $recovered=ManagerTourSelectionService::importOneTouch(8,1,$key,$cmd,true);storeCheck($recovered['ok']&&$recovered['found']&&$recovered['saved_version']===$added['saved_version']&&(int)$p->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn()===$ops+1&&ManagerTourSelectionService::get(8,1)['selection']['draft']['offers']===$view,'family lost receipt resolves original operation read only '.implode(',',$ages));
    $familyCommands[implode(',',$ages)]=$cmd;
    $publication=ManagerTourSelectionPublicationDraft::build(['title'=>'Synthetic family preview','intro'=>'','offers'=>[$savedFamily]]);
    storeCheck($publication['ok']&&$publication['offers'][0]['snapshot']['child_ages']===$ages&&$publication['offers'][0]['snapshot']['amount']==='145000'&&$publication['offers'][0]['import_source']===$savedFamily['import_source'],'private publication freezes exact ages price and original source '.implode(',',$ages));
}
$ff=syntheticOneTouchSource(2,[9]);$eightClaim='0x'.hash('sha256','synthetic-family-2-8');$nineClaim='0x'.hash('sha256','synthetic-family-2-9');$responseCollision=str_replace($nineClaim,$eightClaim,$ff['response']);
$fs=ManagerTourSelectionOneTouchIntake::search(8,1,$key,['url'=>$ff['url']],static fn($url):array=>['url'=>$ff['url'],'response'=>$responseCollision,'form'=>$ff['form'],'observed_at'=>gmdate('Y-m-d\TH:i:s\Z')]);$fr=ManagerTourSelectionOneTouchIntake::review(8,1,$key,['ticket'=>$fs['offers'][0]['ticket']],static fn($url):string=>$ff['hotel']);
$prior=ManagerTourSelectionService::get(8,1)['selection']['draft']['offers'];$ops=(int)$p->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn();
$collision=ManagerTourSelectionService::importOneTouch(8,1,$key,['operation_id'=>'fixture-family-age-collision-0001','ticket'=>$fr['ticket'],'confirm_price_from'=>true,'manager_amount'=>'145000']);
storeCheck(($collision['error']??'')==='ambiguous_offer'&&ManagerTourSelectionService::get(8,1)['selection']['draft']['offers']===$prior&&(int)$p->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn()===$ops,'same opaque claim and price with another age cannot overwrite or append');
$wrong=$familyCommands['8'];$wrong['ticket']=$fr['ticket'];$mismatch=ManagerTourSelectionService::importOneTouch(8,1,$key,$wrong,true);
storeCheck(($mismatch['error']??'')==='operation_mismatch'&&ManagerTourSelectionService::get(8,1)['selection']['draft']['offers']===$prior,'another age ticket cannot reconcile original age-eight operation');
storeCheck(array_slice($prior,0,count($familyStart))===$familyStart,'entire pre-family selection remains unchanged');
foreach($before as $table=>$rows)storeCheck($p->query('SELECT * FROM '.$table)->fetchAll()===$rows,'family tests leave conversations messages events and reads unchanged '.$table);


// Same opaque claim/fare from another city cannot mutate the old operation or selection.
$city=syntheticOneTouchSource(2,[8],'6059');
$readCity=ManagerTourSelectionOneTouchIntake::search(8,1,$key,['url'=>$city['url']],static fn($url):array=>['url'=>$city['url'],'response'=>$city['response'],'form'=>$city['form'],'observed_at'=>gmdate('Y-m-d\TH:i:s\Z')]);
$readyCity=ManagerTourSelectionOneTouchIntake::review(8,1,$key,['ticket'=>$readCity['offers'][0]['ticket']],static fn($url):string=>$city['hotel']);
storeCheck($readyCity['ok']&&str_contains($readyCity['source_reference']['url'],'TOWNFROMINC=6059'),'ready preview keeps a distinct city on same claim and fare');
$cityPrior=ManagerTourSelectionService::get(8,1)['selection']['draft']['offers'];$cityOps=(int)$p->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn();
$sameClaim=ManagerTourSelectionService::importOneTouch(8,1,$key,['operation_id'=>'fixture-city-same-claim-0001','ticket'=>$readyCity['ticket'],'confirm_price_from'=>true,'manager_amount'=>'145000']);
storeCheck(($sameClaim['error']??'')==='ambiguous_offer'&&ManagerTourSelectionService::get(8,1)['selection']['draft']['offers']===$cityPrior&&(int)$p->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn()===$cityOps,'different city same opaque claim never overwrites appends or writes a receipt');
$wrongCity=$familyCommands['8'];$wrongCity['ticket']=$readyCity['ticket'];$cityMismatch=ManagerTourSelectionService::importOneTouch(8,1,$key,$wrongCity,true);
storeCheck(($cityMismatch['error']??'')==='operation_mismatch'&&ManagerTourSelectionService::get(8,1)['selection']['draft']['offers']===$cityPrior,'different city cannot reconcile original operation');
storeCheck(array_slice($cityPrior,0,count($familyStart))===$familyStart,'BG OneTouch and all prior source conditions preserved across city cases');

echo json_encode(['checks'=>$count,'database'=>'sqlite::memory:','realDatabase'=>false,'network'=>0,'realImports'=>0],JSON_THROW_ON_ERROR)."\n";
