<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/services/AnyTourUmkLateLedger.php';

function checkUmk(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
}

$db = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE modeled_conversion_profile_versions (id INTEGER PRIMARY KEY,project_key TEXT,platform TEXT)');
$db->exec('CREATE TABLE max2_common_value_accounts_v2 (id INTEGER PRIMARY KEY,counter_id INTEGER,goal_id INTEGER,target TEXT,project_key TEXT,
    platform TEXT,inquiry_key TEXT,inquiry_recorded_at_unix INTEGER,binding_kind TEXT,tracker_record_id TEXT)');
$db->exec('CREATE TABLE max2_common_value_events_v2 (id INTEGER PRIMARY KEY,account_id INTEGER,counter_id INTEGER,goal_id INTEGER,target TEXT,
    profile_id TEXT,event_key TEXT,event_at_unix INTEGER,total_kopeks INTEGER,delta_kopeks INTEGER,delivery_state TEXT)');
$sql = file_get_contents(dirname(__DIR__).'/migrations/114_anytour_umk_late_value.sql');
checkUmk(is_string($sql),'UMK migration required');
$sql = str_replace([' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',' CHARACTER SET ascii COLLATE ascii_bin'],'',$sql);
$sql = preg_replace('/^\s*KEY ix_[^\n]+,\n/m','',$sql);
$sql = preg_replace('/UNIQUE KEY (\w+) \(/','CONSTRAINT $1 UNIQUE (',$sql);
$db->exec($sql);
$ledger = new AnyTourUmkLateLedger($db);
function account(PDO $db, int $id, string $platform, int $clock, ?string $tracker): void
{
    $db->prepare("INSERT INTO max2_common_value_accounts_v2 VALUES (?,98615635,687941321,'anytour_modeled_value_v2_max2','anytour',?,?,?,?,?)")
        ->execute([$id,$platform,hash('sha256','inquiry'.$id),$clock,$tracker===null?'PROVEN_NO_NEW_EARLY_RESERVE':'UNIQUE_OBSERVED_MAX2',$tracker]);
}
function profile(PDO $db,int $id,int $account,int $profile,int $total,int $v2Delta,int $clock): void
{
    $db->prepare("INSERT INTO max2_common_value_events_v2 VALUES (?,?,98615635,687941321,'anytour_modeled_value_v2_max2',?,?,?,?,?,?)")
        ->execute([$id,$account,(string)$profile,hash('sha256','event'.$id),$clock,$total,$v2Delta,$v2Delta>0?'reserved':'not_needed']);
}
function evidence(int $account,?string $tracker,int $clock,int $amount): array
{
    return ['counter_id'=>'98615635','goal_id'=>'696194739','target'=>'anytour_umk_funnel_value','verified'=>true,'inquiry_key'=>hash('sha256','inquiry'.$account),
        'record_id'=>$tracker,'issued_at_unix'=>$clock,'amount_kopeks'=>$amount,'state'=>$amount>0?'issued_unconfirmed':'no_reserve',
        'complete'=>true,'source_sha256'=>hash('sha256','immutable'.$account)];
}

account($db,1,'telegram',1,null);
profile($db,1,1,88,700,700,1);
$db->exec("INSERT INTO modeled_conversion_profile_versions VALUES (100,'anytour','max')");
checkUmk($ledger->reserve(1)['status']==='OFF','default is off');
$floors = $ledger->activate();
$start = (int)$floors['activated_at_unix'];
checkUmk($start>0 && (int)$floors['initial_profile_id']===100 && (int)$floors['initial_source_id']===1,'floor anchored in storage');
checkUmk($ledger->reserve(1)['status']==='BEFORE_ACTIVATION','past source id');
profile($db,2,1,101,800,100,$start+1);
checkUmk($ledger->reserve(2)['status']==='BEFORE_ACTIVATION','old inquiry with new profile');
account($db,2,'max',$start+4,'42');
profile($db,3,2,99,900,900,$start+4);
profile($db,4,2,102,83300,81501,$start+5);
profile($db,5,2,103,85000,1700,$start+6);
profile($db,6,2,104,84000,0,$start+7);
checkUmk($ledger->reserve(3)['status']==='BEFORE_ACTIVATION','past profile id');
checkUmk($ledger->reserve(5)['status']==='EARLIER_PROFILE_PENDING','no out of order reserve');
checkUmk($ledger->reserve(4)['status']==='EARLY_RESERVE_UNPROVED','unknown is never zero');
$proof = evidence(2,'42',$start+2,1799);
$bad = $proof;
$bad['issued_at_unix'] = $start;
checkUmk($ledger->reserve(4,$bad)['status']==='EARLY_RESERVE_UNPROVED','skip old or boundary launch');
$bad = $proof;
$bad['record_id'] = '450';
checkUmk($ledger->reserve(4,$bad)['status']==='EARLY_RESERVE_UNPROVED','same tracker required');
$bad = $proof;
$bad['goal_id'] = '687941321';
checkUmk($ledger->reserve(4,$bad)['status']==='EARLY_RESERVE_UNPROVED','wrong destination');
$event = $ledger->reserve(4,$proof);
checkUmk($event['status']==='RESERVED' && $event['delta_kopeks']===81501,'same starting early and late amounts');
checkUmk($ledger->reserve(4)['status']==='ALREADY_RECORDED','retry never grants another reservation');
$db->prepare("UPDATE anytour_umk_late_events SET delivery_state='unknown',attempt_key=?,csv_sha256=?,attempted_at_unix=? WHERE source_event_id=4")
    ->execute([hash('sha256','attempt'),hash('sha256','csv'),$start+5]);
checkUmk($ledger->reserve(5)['delta_kopeks']===1700,'unknown still reserved; frozen initial reused');
checkUmk($ledger->reserve(6)['status']==='NOT_NEEDED','decline grants no value');
account($db,3,'max',$start+4,'72');
profile($db,7,3,105,1500,0,$start+5);
checkUmk($ledger->reserve(7,evidence(3,'72',$start+2,500))['delta_kopeks']===1000,'V2 not_needed can have a positive independent delta');
account($db,4,'telegram',$start+4,null);
profile($db,8,4,106,8345,8345,$start+5);
checkUmk($ledger->reserve(8)['delta_kopeks']===8345,'telegram without MAX2 starts from zero');
account($db,5,'max',$start+4,null);
profile($db,9,5,107,7300,7300,$start+5);
$zero = evidence(5,null,$start+2,0);
$incomplete = $zero;
$incomplete['complete'] = false;
checkUmk($ledger->reserve(9,$incomplete)['status']==='EARLY_RESERVE_UNPROVED','unlinked incomplete history');
checkUmk($ledger->reserve(9,$zero)['delta_kopeks']===7300,'exact complete negative');
checkUmk($floors === $ledger->activate(),'no floor movement');
checkUmk((int)$db->query('SELECT COUNT(*) FROM anytour_umk_late_events')->fetchColumn()===6,'one row per admitted profile');
// A second independent database exercises the normal worker with only the provider stubbed.
require_once dirname(__DIR__).'/services/AnyTourUmkLateDelivery.php';
$work = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$work->exec('PRAGMA foreign_keys=ON');
foreach (['modeled_conversion_profile_versions','max2_common_value_accounts_v2','max2_common_value_events_v2'] as $table) {
    $ddl = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn();
    $work->exec($ddl);
}
$work->exec("ALTER TABLE max2_common_value_accounts_v2 ADD COLUMN client_id TEXT DEFAULT '123456789'");
$work->exec($sql);
$requests = [];
$comments = [];
$rejectCatalogue = false;
$unknownPost = false;
$badPoll = false;
$transport = static function($method,$url,$body) use (&$requests,&$comments,&$rejectCatalogue,&$unknownPost,&$badPoll) {
    $requests[] = [$method,$url,$body];
    if (str_ends_with($url,'/goals')) {
        $goal = ['id'=>696194739,'type'=>'action','name'=>'УМК','conditions'=>[['type'=>'exact','url'=>'anytour_umk_funnel_value']]];
        $data = ['goals'=>$rejectCatalogue ? [] : [$goal]];
    } elseif ($method === 'POST') {
        if ($unknownPost) return ['http'=>0,'error'=>28,'body'=>''];
        parse_str(parse_url($url,PHP_URL_QUERY), $query);
        $comments[321] = $query['comment'];
        $data = ['uploading'=>['id'=>321,'status'=>'UPLOADED','comment'=>$comments[321]]];
    } else {
        $data = ['uploading'=>['id'=>321,'status'=>'PROCESSED','comment'=>$badPoll ? 'another goal' : $comments[321],
            'source_quantity'=>1,'line_quantity'=>1]];
    }
    return ['http'=>200,'error'=>0,'body'=>json_encode($data,JSON_THROW_ON_ERROR)];
};
$clock = time()+30;
$worker = new AnyTourUmkLateDelivery($work,static fn() => null,
    static fn()=>Max2CommonValueMetrikaClientV2::forUmk('synthetic',$transport),static fn()=>$clock);
checkUmk($worker->tick() === ['status'=>'OFF'] && $requests===[],'OFF performs no provider work');
$epoch = (int)(new AnyTourUmkLateLedger($work))->activate()['activated_at_unix'];
// The original fixture helper uses positional INSERTs, so seed these two rows explicitly.
$insert = $work->prepare("INSERT INTO max2_common_value_accounts_v2(id,counter_id,goal_id,target,project_key,platform,inquiry_key,
    inquiry_recorded_at_unix,binding_kind,tracker_record_id,client_id) VALUES (?,98615635,687941321,'anytour_modeled_value_v2_max2',
    'anytour',?,?,?,?,?,?)");
$insert->execute([10,'max',hash('sha256','inquiry10'),$epoch+1,'UNIQUE_OBSERVED_MAX2','410','123456789']);
$insert->execute([11,'telegram',hash('sha256','inquiry11'),$epoch+1,'PROVEN_NO_NEW_EARLY_RESERVE',null,'123456789']);
profile($work,20,10,101,80000,78201,$epoch+2);
profile($work,21,11,102,83300,83300,$epoch+3);
checkUmk($worker->tick()['collection']==='EARLY_RESERVE_UNPROVED','MAX is held without UMK evidence');
checkUmk($worker->tick()['collection']==='RESERVED' && $requests===[],'other eligible conversation still collected');
$worker->setDelivery($epoch,true);
$rejectCatalogue=true;
checkUmk($worker->tick()['send']==='CATALOGUE_HOLD','misconfigured goal does not consume attempt');
$rejectCatalogue=false;
checkUmk($worker->tick()['send']==='ACCEPTED','durable attempt sent after catalogue admission');
$badPoll=true;
checkUmk($worker->tick()['poll']==='UNCONFIRMED','foreign receipt cannot confirm');
$badPoll=false;
checkUmk($worker->tick()['poll']==='PROCESSED','own exact receipt reconciled');
$events = $work->query('SELECT * FROM anytour_umk_late_events')->fetchAll(PDO::FETCH_ASSOC);
checkUmk(count($events)===1 && $events[0]['delivery_state']==='processed' && $events[0]['delta_kopeks']==83300
    && strlen($events[0]['csv_sha256'])===64,'only own destination received completed result');
$posts = static fn(array $log):array => array_values(array_filter($log,static fn($r)=>$r[0]==='POST'));
checkUmk(count($posts($requests))===1 && str_contains($posts($requests)[0][2],',833.00,RUB'),'one correct upload');
profile($work,22,11,103,83500,200,$epoch+4);
$unknownPost=true;
for ($i=0;$i<4;$i++) $worker->tick();
$frozen = $work->query('SELECT delivery_state FROM anytour_umk_late_events WHERE source_event_id=22')->fetchColumn();
checkUmk($frozen==='unknown' && count($posts($requests))===2,'uncertain outcome never reissued on later ticks');
$worker->setDelivery($epoch,false);
checkUmk($worker->tick()['status']==='COLLECT','pause preserves original activation cohort');
// A long suffix of pre-activation inquiries and evaluated profiles costs one bounded page per tick.
for ($id=23;$id<=102;$id++) profile($work,$id,11,$id+150,83000,0,$epoch);
$work->exec('UPDATE anytour_umk_late_settings SET scan_after_source_id=22');
checkUmk($worker->tick()['collection']==='IDLE' && (int)$worker->settings()['scan_after_source_id']===54,'scan stops after first 32 source rows');
checkUmk($worker->tick()['collection']==='IDLE' && (int)$worker->settings()['scan_after_source_id']===86,'bounded progress on an ineligible suffix');
$worker->tick();
checkUmk((int)$worker->settings()['scan_after_source_id']===102,'last partial page observed');
$worker->tick();
checkUmk((int)$worker->settings()['scan_after_source_id']===(int)$worker->settings()['initial_source_id'],'idle scan wraps to its own prospective floor');
checkUmk($worker->tick()['collection']==='EARLY_RESERVE_UNPROVED','unresolved earlier MAX entry is reconsidered');

require_once dirname(__DIR__).'/services/AnyTourUmkEarlyProofReader.php';
(static function(): void {
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    checkUmk(AnyTourUmkEarlyProofReader::activeSince($db)===null,'missing UMK settings disabled');
    $db->exec('CREATE TABLE anytour_umk_late_settings(id INTEGER PRIMARY KEY,activated_at_unix INTEGER);
        INSERT INTO anytour_umk_late_settings VALUES(1,100);
        CREATE TABLE max2_common_value_events_v2(id INTEGER,account_id INTEGER,counter_id INTEGER,goal_id INTEGER,target TEXT,event_at_unix INTEGER);
        CREATE TABLE max2_common_value_accounts_v2(id INTEGER,inquiry_key TEXT,binding_kind TEXT,tracker_record_id TEXT,
            immutable_tuple_sha256 TEXT,binding_proof_sha256 TEXT,producer_contract_sha256 TEXT,
            counter_id INTEGER,goal_id INTEGER,target TEXT,project_key TEXT,platform TEXT,inquiry_recorded_at_unix INTEGER);');
    $app=dirname(__DIR__);
    $migration=file_get_contents($app.'/migrations/114_anytour_umk_late_value.sql');
    $ddl=substr($migration,strpos($migration,'CREATE TABLE IF NOT EXISTS anytour_umk_point_proofs'));
    $db->exec(str_replace([' CHARACTER SET ascii COLLATE ascii_bin',' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'],['',''],$ddl));
    $key=hash('sha256','inquiry'); $tuple=hash('sha256','tuple'); $source=hash('sha256','raw');
    $db->prepare("INSERT INTO max2_common_value_accounts_v2 VALUES(1,?,'UNIQUE_OBSERVED_MAX2','42',?,?,?,
        98615635,687941321,'anytour_modeled_value_v2_max2','anytour','max',120)")->execute([$key,$tuple,$source,Max2ValueLookupProtocolV2::PRODUCER_CONTRACT_SHA256]);
    $db->exec("INSERT INTO max2_common_value_events_v2 VALUES(5,1,98615635,687941321,'anytour_modeled_value_v2_max2',125)");
    $binding=['inquiry_key'=>$key,'status'=>'UNIQUE_OBSERVED_MAX2','immutable_tuple_sha256'=>$tuple,'inquiry_recorded_at_unix'=>120,'tracker_record_id'=>'42'];
    $proof=['issued_at_unix'=>110,'source_sha256'=>$source,'amount_kopeks'=>1799];
    $projection=['status'=>'READY','binding'=>$binding,'umk_point_proof'=>$proof];
    checkUmk(AnyTourUmkEarlyProofReader::read($db,5)===null,'V2 alone cannot prove positive UMK amount');
    $wrong=$projection; $wrong['binding']['tracker_record_id']='43';
    AnyTourUmkEarlyProofReader::capture($db,$wrong,100);
    checkUmk(AnyTourUmkEarlyProofReader::read($db,5)===null,'sibling row rejected');
    AnyTourUmkEarlyProofReader::capture($db,$projection,100);
    AnyTourUmkEarlyProofReader::capture($db,$projection,100);
    checkUmk(AnyTourUmkEarlyProofReader::read($db,5)['amount_kopeks']===1799,'repeats retain one verified amount');
    $projection['umk_point_proof']['amount_kopeks']=2636;
    AnyTourUmkEarlyProofReader::capture($db,$projection,100);
    checkUmk(AnyTourUmkEarlyProofReader::read($db,5)===null,'conflicting positive evidence holds');
    $db->exec("UPDATE max2_common_value_accounts_v2 SET binding_kind='PROVEN_NO_NEW_EARLY_RESERVE',tracker_record_id=NULL,immutable_tuple_sha256=NULL");
    checkUmk(AnyTourUmkEarlyProofReader::read($db,5)['state']==='no_reserve','complete future MAX absence');
    $db->exec('UPDATE anytour_umk_late_settings SET activated_at_unix=130');
    checkUmk(AnyTourUmkEarlyProofReader::read($db,5)===null,'old inquiries excluded');
})();
echo "UMK late value accounting and delivery PASS\n";
