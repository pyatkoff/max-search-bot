<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/TourBookingService.php';
$checks=0;
function bookingCheck(string $label,bool $ok):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;}
function bookingDb():PDO{
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $p->exec('CREATE TABLE managers(id INTEGER PRIMARY KEY,is_active INTEGER);CREATE TABLE conversations(id INTEGER PRIMARY KEY,project_key TEXT,customer_id INTEGER,channel TEXT,external_chat_id TEXT,status TEXT,customer_channel_id INTEGER);CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,project_key TEXT,channel TEXT,external_user_id TEXT);CREATE TABLE conversation_events(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,event_type TEXT,actor_type TEXT,actor_id TEXT,payload_json TEXT,created_at TEXT)');
    $p->exec("INSERT INTO managers VALUES(7,1),(8,1),(9,0);INSERT INTO conversations VALUES(11,'anytour',21,'telegram','21','manager',1),(12,'anytour',22,'max','native-22','manager',2);INSERT INTO customer_channels VALUES(1,'anytour','telegram','21'),(2,'anytour','max','22')");
    $offer=['hotel'=>'Synthetic sea hotel','departure'=>'Москва','date_from'=>'2027-06-12','nights'=>7,'adults'=>2,'children'=>2,'room'=>'Family','meal'=>'AI','amount'=>'180000.50','currency'=>'RUB'];
    $q=$p->prepare('INSERT INTO conversation_events(conversation_id,event_type,actor_type,actor_id,payload_json,created_at)VALUES(?,?,?,?,?,?)');
    $q->execute([11,'tour_web_inquiry','customer','21',json_encode(['schema'=>1,'publication_id'=>'immutable-publication','link_version'=>3,'variant'=>2,'public_offer'=>$offer]),'2026-10-23 10:00:00']);
    return $p;
}
function bookingFields(string $state='checking'):array{return ['state'=>$state,'amount'=>'','currency'=>'','checked_at'=>'','verification_source'=>'','terms'=>'','agreement_evidence'=>'','operator'=>'','booking_reference'=>'','confirmed_at'=>'','cancellation_reason'=>'','available'=>false,'customer_agreed'=>false];}
function bookingInput(array $fields,int $revision=0,string $op=''):array{return ['source_kind'=>'web','source_id'=>1,'revision'=>$revision,'operation_id'=>$op!==''?$op:bin2hex(random_bytes(16)),'fields'=>$fields];}
$p=bookingDb();$allow=static fn():bool=>true;
$r=TourBookingService::read($p,11,'web',1);
bookingCheck('existing inquiry preserves exact public version and full party amount',$r['ok']&&$r['record']===null&&$r['source']['choice']['link_version']===3&&$r['source']['choice']['variant']===2&&$r['source']['choice']['public_offer']['amount']==='180000.50');
bookingCheck('foreign inquiry cannot be selected',!TourBookingService::read($p,12,'web',1)['ok']);
bookingCheck('fabricated inquiry cannot be selected',!TourBookingService::read($p,11,'web',999)['ok']);
$input=bookingInput(bookingFields());
$r=TourBookingService::save($p,7,11,$input,function()use($p):bool{bookingCheck('write authorization runs inside transaction',$p->inTransaction());return true;});
bookingCheck('checking record does not copy the quote into a verified price',$r['ok']&&$r['record']['revision']===1&&$r['record']['fields']['amount']===''&&!$r['record']['fields']['available']);
$before=(int)$p->query("SELECT COUNT(*) FROM conversation_events")->fetchColumn();
$again=TourBookingService::save($p,7,11,$input,$allow);
bookingCheck('same exact command is reconciled without another event',$again['ok']&&$again['saved_revision']===1&&(int)$p->query("SELECT COUNT(*) FROM conversation_events")->fetchColumn()===$before);
$changed=$input;$changed['fields']['terms']='other conditions';
bookingCheck('operation cannot be reused for changed fields',TourBookingService::save($p,7,11,$changed,$allow)['error']==='operation_mismatch');
bookingCheck('another account cannot reconcile original operation',TourBookingService::read($p,11,'web',1,$input['operation_id'],8)['error']==='operation_mismatch');
bookingCheck('original account can reconcile exact operation',TourBookingService::read($p,11,'web',1,$input['operation_id'],7)['operation_found']);
bookingCheck('different command on stale revision cannot overwrite',TourBookingService::save($p,7,11,bookingInput(bookingFields()),$allow)['error']==='conflict');
bookingCheck('revoked authorization rejects before mutation',TourBookingService::save($p,7,11,bookingInput(bookingFields(),1),static fn():bool=>false)['error']==='forbidden');
bookingCheck('inactive account rejects before mutation',TourBookingService::save($p,9,11,bookingInput(bookingFields(),1),$allow)['error']==='forbidden');
$f=bookingFields('ready');
bookingCheck('choice alone cannot become ready',TourBookingService::save($p,7,11,bookingInput($f,1),$allow)['error']==='verification_required');
$f=array_merge($f,['amount'=>'181500.50','currency'=>'RUB','available'=>true,'checked_at'=>gmdate('Y-m-d\TH:i:s\Z'),'verification_source'=>'Operator quote #example','terms'=>'12–19 June 2027; 2 adults, children 5 and 5; Family; AI; flights and baggage included; all mandatory fees included.','customer_agreed'=>true,'agreement_evidence'=>'Customer message #synthetic-42']);
$expired=$f;$expired['checked_at']=gmdate('Y-m-d\TH:i:s\Z',time()-90000);
bookingCheck('stale verification cannot be presented as ready',TourBookingService::save($p,7,11,bookingInput($expired,1),$allow)['error']==='verification_expired');
$r=TourBookingService::save($p,7,11,bookingInput($f,1),$allow);
bookingCheck('explicit verified terms and customer agreement become ready',$r['ok']&&$r['record']['fields']['state']==='ready'&&$r['source']['choice']['public_offer']['amount']==='180000.50'&&$r['record']['fields']['amount']==='181500.50');
$f['state']='confirmed';
bookingCheck('ready does not invent operator confirmation',TourBookingService::save($p,7,11,bookingInput($f,2),$allow)['error']==='booking_evidence_required');
$f['operator']='Synthetic operator';$f['booking_reference']='TEST-ONLY-123';$f['confirmed_at']=gmdate('Y-m-d\TH:i:s\Z');
$r=TourBookingService::save($p,7,11,bookingInput($f,2),$allow);
bookingCheck('confirmation retains original choice and separate actual operator reference',$r['ok']&&$r['record']['revision']===3&&$r['record']['fields']['booking_reference']==='TEST-ONLY-123'&&$r['source']['id']===1);
$checking=bookingFields();
bookingCheck('confirmed booking cannot silently regress to checking',TourBookingService::save($p,7,11,bookingInput($checking,3),$allow)['error']==='invalid_transition');
$cancel=$f;$cancel['state']='cancelled';
bookingCheck('cancellation needs an explicit reason',TourBookingService::save($p,7,11,bookingInput($cancel,3),$allow)['error']==='cancellation_reason_required');
$cancel['cancellation_reason']='Synthetic cancellation';
$r=TourBookingService::save($p,7,11,bookingInput($cancel,3),$allow);
bookingCheck('cancellation is an immutable next revision',$r['ok']&&$r['record']['revision']===4);
bookingCheck('cancelled record cannot be silently reopened',TourBookingService::save($p,7,11,bookingInput($f,4),$allow)['error']==='invalid_transition');
$p->exec("UPDATE conversations SET customer_id=99 WHERE id=11");
bookingCheck('customer rebinding never adopts another customer booking',TourBookingService::read($p,11,'web',1)['error']==='context_changed');
$p->exec("UPDATE conversations SET customer_id=21,status='closed' WHERE id=11");
bookingCheck('closed conversation remains readable',TourBookingService::read($p,11,'web',1)['ok']);
bookingCheck('closed conversation refuses new booking writes',TourBookingService::save($p,7,11,bookingInput($f,4),$allow)['error']==='forbidden');
foreach(['0','-2','10,5','NaN','1e5'] as $amount){$bad=$f;$bad['amount']=$amount;bookingCheck('invalid amount '.$amount,TourBookingService::normalize($bad)===null);}
$bad=$f;$bad['currency']='XYZ';bookingCheck('unknown currency is not invented',TourBookingService::normalize($bad)===null);
$bad=$f;$bad['checked_at']='2026-02-30T12:00:00Z';bookingCheck('impossible instant rejected',TourBookingService::normalize($bad)===null);
$bad=$f;$bad['checked_at']=gmdate('Y-m-d\TH:i:s\Z',time()+7200);bookingCheck('future verification rejected',TourBookingService::normalize($bad)===null);
bookingCheck('booking records never write messages, routing or sale state',(int)$p->query("SELECT COUNT(*) FROM conversation_events WHERE event_type NOT IN ('tour_web_inquiry','tour_booking_record')")->fetchColumn()===0&&$p->query('SELECT status FROM conversations WHERE id=12')->fetchColumn()==='manager');
// Native demo is selectable for checking, but never for a confirmed live booking.
$p=bookingDb();
$native=['hotel'=>'Demo hotel','departure'=>'Москва','date_from'=>'2027-06-12','date_to'=>'2027-06-19','nights'=>7,'adults'=>2,'child_ages'=>[5,5],'room'=>'Family','meal'=>'AI','flights'=>[],'amount'=>'100','currency'=>'RUB','price_basis'=>'party','is_demo'=>true,'rationale'=>'','compromise'=>''];
$prepared=TourChoiceService::prepare($p,11,$native);
bookingCheck('demo prepared through canonical owner',$prepared['ok']);
$delivered=TourChoiceService::recordDelivered($p,$prepared['offer_id'],'77','21');
bookingCheck('demo fixture has a valid private native delivery',$delivered['ok']);
$method=new ReflectionMethod(TourChoiceService::class,'append');$method->setAccessible(true);
$method->invoke(null,$p,11,'tour_offer_liked','customer','21',['schema'=>1,'offer_id'=>$prepared['offer_id']]);
$demoInput=bookingInput($f);$demoInput['source_kind']='native';$demoInput['source_id']=$prepared['offer_id'];
bookingCheck('demo preference cannot be confirmed as real',TourBookingService::save($p,7,11,$demoInput,$allow)['error']==='verification_required');
echo 'PASS tour booking '.$checks.' checks'.PHP_EOL;
