<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelCollectionCanonicalService.php';
require_once dirname(__DIR__).'/services/HotelWorkOperationJournalPrototype.php';
require_once dirname(__DIR__).'/services/HotelWorkQueue.php';
require_once dirname(__DIR__).'/services/HotelWorkMcpAdapter.php';
require_once __DIR__.'/fixtures/hotel_work_queue.php';
require_once __DIR__.'/fixtures/hotel_work_operation_journal.php';
require_once __DIR__.'/fixtures/hotel_collection_canonical.php';

$hccChecks=0;$hccFiles=[];
function hccCheck(bool $ok,string $name):void{global$hccChecks;++$hccChecks;if(!$ok)throw new RuntimeException('canonical_service_assertion:'.$name);}
function hccReject(callable $call,string $name,?string $reason=null):void{try{$call();}catch(Throwable$e){hccCheck($reason===null||$e->getMessage()===$reason,$name.':'.$e->getMessage());return;}hccCheck(false,$name);}
function hccJson(array $value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function hccExec(PDO $p,string $sql,array $args=[]):void{$s=$p->prepare($sql);$s->execute($args);}
function hccState(PDO $p):string{$out=[];foreach(['hotel_work_queues','hotel_journal_heads','hotel_journal_operations','hotel_collection_baselines','hotel_collection_policy','hotel_collection_policy_versions','hotel_collection_authorizations','hotel_collection_heads','hotel_collection_assignments','hotel_collection_candidates','hotel_collection_snapshots','hotel_collection_ledger','hotel_collection_requests']as$t)$out[$t]=$p->query('SELECT * FROM '.$t.' ORDER BY 1,2')->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',hccJson($out));}
function hccNoWrite(PDO $p,callable $call,string $name,?string $reason=null):void{$before=hccState($p);hccReject($call,$name,$reason);hccCheck($before===hccState($p),$name.'_no_effects');}
function hccAuthFixture(PDO $p):void
{
    // Only the fixture PDO can reach this helper; native mode is already gated
    // by HotelWorkTestPdo's maxbot_prepared_test database allowlist.
    $p->exec('CREATE TABLE IF NOT EXISTS managers(id INTEGER PRIMARY KEY,login VARCHAR(191) NOT NULL,display_name VARCHAR(191),role VARCHAR(32),is_active INTEGER)');
    $p->exec('CREATE TABLE IF NOT EXISTS projects(id INTEGER PRIMARY KEY,project_key VARCHAR(64) UNIQUE,display_name VARCHAR(191),is_active INTEGER)');
    $p->exec('CREATE TABLE IF NOT EXISTS manager_projects(manager_id INTEGER,project_id INTEGER,PRIMARY KEY(manager_id,project_id))');
    $p->exec('CREATE TABLE IF NOT EXISTS manager_preparation_oauth_clients(client_id VARCHAR(48) PRIMARY KEY,redirect_uri VARCHAR(255),registration_bucket VARCHAR(64),created_at BIGINT)');
    $p->exec('CREATE TABLE IF NOT EXISTS manager_preparation_oauth_grants(grant_id VARCHAR(32) PRIMARY KEY,client_id VARCHAR(48),admin_id INTEGER,project_key VARCHAR(64),resource VARCHAR(255),scopes VARCHAR(100),created_at BIGINT,expires_at BIGINT,revoked_at BIGINT)');
    foreach([901001,901002]as$id){$s=$p->prepare('SELECT id FROM managers WHERE id=?');$s->execute([$id]);if($s->fetchColumn()===false)hccExec($p,'INSERT INTO managers(id,login,display_name,role,is_active) VALUES(?,?,?,?,1)',[$id,'canonical-service-'.$id,'Canonical fixture','admin']);else hccExec($p,"UPDATE managers SET role='admin',is_active=1 WHERE id=?",[$id]);}
    $project=$p->query("SELECT id,display_name FROM projects WHERE project_key='anytour'")->fetch(PDO::FETCH_ASSOC);
    if(!$project){hccExec($p,'INSERT INTO projects(id,project_key,display_name,is_active) VALUES(?,?,?,1)',[901001,'anytour','Canonical fixture']);$project=['id'=>901001,'display_name'=>'Canonical fixture'];}
    hccExec($p,'UPDATE projects SET is_active=1 WHERE id=?',[$project['id']]);
    foreach([901001,901002]as$id){hccExec($p,'DELETE FROM manager_projects WHERE manager_id=? AND project_id=?',[$id,$project['id']]);hccExec($p,'INSERT INTO manager_projects(manager_id,project_id) VALUES(?,?)',[$id,$project['id']]);}
    $client=str_repeat('c',48);
    foreach([str_repeat('a',32),str_repeat('b',32)]as$conn)hccExec($p,'DELETE FROM manager_preparation_oauth_grants WHERE grant_id=?',[$conn]);
    $s=$p->prepare('SELECT client_id FROM manager_preparation_oauth_clients WHERE client_id=?');$s->execute([$client]);
    if($s->fetchColumn()===false)hccExec($p,'INSERT INTO manager_preparation_oauth_clients(client_id,redirect_uri,registration_bucket,created_at) VALUES(?,?,?,?)',[$client,ManagerPreparationOAuth::REDIRECT_URI,str_repeat('0',64),1793500000]);
    foreach([[str_repeat('a',32),901001],[str_repeat('b',32),901002]]as[$conn,$id])hccExec($p,'INSERT INTO manager_preparation_oauth_grants(grant_id,client_id,admin_id,project_key,resource,scopes,created_at,expires_at,revoked_at) VALUES(?,?,?,?,?,?,?,?,NULL)',[$conn,$client,$id,'anytour',ManagerPreparationOAuth::RESOURCE,ManagerPreparationOAuth::HOTEL_SCOPE,1793500000,1893500000]);
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>$project['display_name']]]);
}
function hccFixture(bool $largeIndex=false):array
{
    global$hccFiles;$mysql=getenv('HOTEL_WORK_NATIVE_MYSQL')==='1';
    $file=$mysql?null:tempnam(sys_get_temp_dir(),'hotel-collection-service-');if(!$mysql)$hccFiles[]=$file;
    // Use the existing actual queue fixture, then a plain PDO so its restrictive
    // SQL-observer test double is not silently widened for new/auth tables.
    $initializer=new HotelWorkTestPdo($file);$initializer=null;
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>false];
    if($mysql){$options[PDO::ATTR_EMULATE_PREPARES]=false;$p=new PDO((string)getenv('PREPARED_REPLY_MYSQL_DSN'),getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,$options);}
    else{$p=new PDO('sqlite:'.$file,null,null,$options);$p->exec('PRAGMA busy_timeout=10000');}
    hotelJournalFixtureSchema($p);hotelCollectionCanonicalFixtureSchema($p);hccAuthFixture($p);
    $clock=static fn():int=>1793500000;$conn=str_repeat('a',32);$control=str_repeat('1',64);$bytes="opaque fixture WORKING\n\0baseline";$sha=hash('sha256',$bytes);
    $queue=new HotelWorkQueue($p,$clock);$queue->create(901001,$conn,['queue_id'=>'COLLECTION-SERVICE','mode'=>'continuous','start_country'=>'TH','phase_counter'=>0,'baseline_sha256'=>$sha]);
    $stage=$queue->status(901001,$conn,'COLLECTION-SERVICE')['stage_id'];$journal=new HotelWorkOperationJournalPrototype($p,901001,$conn);$journal->enroll('COLLECTION-SERVICE',$stage,$sha,$control,'fixture-controller');
    $index=['baseline_sha256'=>$sha,'facts'=>[],'sources'=>[],'read_update_ids'=>[],'question_ids'=>[]];
    if($largeIndex)for($i=0;$i<1500;++$i)$index['facts'][]=['id'=>'baseline-scope-fact-'.$i,'hotel_key'=>'courtyard-fixture','country'=>'TH'];
    $inventory=['schema_version'=>1,'entries'=>[]];foreach(['Melissa','Ibis','legacy162']as$label)$inventory['entries'][]=['reserve_id'=>'protected-'.$label,'country'=>'TH','hotel_keys'=>[strtolower($label).'-fixture'],'protected'=>true];
    $service=new HotelCollectionCanonicalService($p,901001,$conn,$clock);
    $service->bootstrap('COLLECTION-SERVICE',$stage,1,$control,$bytes,$sha,$index,'fixture approved opaque baseline index',$inventory,'fixture approved complete protected inventory');
    return compact('p','clock','conn','control','bytes','sha','stage','queue','journal','index','inventory','service');
}
function hccIntent(array $f,string $label='one'):array{return$f['journal']->intent('COLLECTION-SERVICE',$f['stage'],1,$f['control'],'intent-'.$label,'event-'.$label,'existing-native-task-fixture');}
function hccPrepared(array $f,string $label='one'):array
{
    $intent=hccIntent($f,$label);$a=$f['service']->prepare('COLLECTION-SERVICE',$f['stage'],1,$f['control'],$intent['operation_id'],'scope-courtyard','source-packet-'.$label,['courtyard-fixture'],0);
    $f['journal']->dispatchIntent('COLLECTION-SERVICE',$f['stage'],1,$f['control'],$intent['operation_id'],'dispatch-'.$label);
    $f['journal']->admit('COLLECTION-SERVICE',$f['stage'],1,$intent['operation_id'],$intent['worker_capability'],'admit-'.$label,'synthetic-instance-'.$label);
    $args=['queue_id'=>'COLLECTION-SERVICE','stage_id'=>$f['stage'],'generation'=>1,'operation_id'=>$intent['operation_id'],'worker_capability'=>$intent['worker_capability'],'instance_id'=>'synthetic-instance-'.$label];
    return[$intent,$a,$args];
}
function hccPacket(array $assignment,string $label='one'):string
{
    $v=['schema_version'=>1];foreach($assignment['context']as$key=>$value)if($key!=='hotel_keys')$v[$key]=$value;
    $v['sources']=[['id'=>'source-'.$label,'hotel_key'=>'courtyard-fixture','url'=>'https://example.test/rooms','kind'=>'official','publication_date'=>null,'retrieved_at'=>'2026-11-01T00:00:00Z','evidence_sha256'=>hash('sha256','synthetic source '.$label),'read_scope'=>'fixture room paragraph','limitations'=>'No external evidence byte verification asserted']];
    $v['facts']=[['id'=>'fact-'.$label,'hotel_key'=>'courtyard-fixture','criterion_id'=>'room_layout','text'=>'Synthetic stated room layout.','kind'=>'official_declared','scope'=>['category'=>'Family','age'=>null,'season'=>null,'product'=>null],'source_ids'=>['source-'.$label],'material_limits'=>['Party and future operation unknown'],'valid_at_travel'=>'unverified']];
    $v['read_updates']=[];$v['questions']=[];return hccJson($v);
}
function hccCommit(array$f,array$a,array$worker,array$candidate,string$request='commit-one'):array{return$f['service']->commit('COLLECTION-SERVICE',$worker['stage_id'],1,$f['control'],$worker['operation_id'],$request,$candidate['raw_sha256'],$a['base_revision'],$a['base_snapshot_sha256']);}

function hccRun():void
{
    $f=hccFixture();$p=$f['p'];[$intent,$a,$args]=hccPrepared($f);
    $queueBefore=$p->query("SELECT * FROM hotel_work_queues WHERE queue_id='COLLECTION-SERVICE'")->fetch(PDO::FETCH_ASSOC);
    $adapter=new HotelWorkMcpAdapter($p,$f['queue'],$f['clock']);
    $read=$adapter->call('hotel_work_collection_context',$args,901001,$f['conn']);
    hccCheck($read['operation_id']===$intent['operation_id']&&$read['context']['hotel_keys']===['courtyard-fixture'],'cached_raw_queue_adapter_uses_real_collection_service');
    hccCheck($read['complete_legacy_reader']===false&&$read['opaque_baseline_content_included']===false&&!$read['business_accepted']&&!$read['native_run_verified'],'worker_context_honest_limits');
    hccCheck(!str_contains(hccJson($read),$f['control'])&&!str_contains(hccJson($read),$intent['worker_capability']),'read_never_returns_capabilities');
    foreach(['generation','worker_capability','instance_id']as$field){$bad=$args;$bad[$field]=$field==='generation'?2:($field==='worker_capability'?str_repeat('f',64):'other-instance');hccNoWrite($p,fn()=>$f['service']->workerRead($bad),'reject_wrong_'.$field);}
    $bad=$args;$bad['worker_capability']=$f['control'];hccNoWrite($p,fn()=>$f['service']->workerRead($bad),'control_is_not_worker_capability','canonical_service_worker_capability_or_instance_required');
    $bad=$args;$bad['hotel_keys']=['melissa-fixture'];hccNoWrite($p,fn()=>$f['service']->workerRead($bad),'worker_cannot_supply_scope','canonical_service_argument_keys');
    hccNoWrite($p,fn()=>$f['service']->bootstrap('COLLECTION-SERVICE',$f['stage'],1,$intent['worker_capability'],$f['bytes'],$f['sha'],$f['index'],'index',$f['inventory'],'reserves'),'worker_cannot_bootstrap','canonical_service_control_capability_required');
    $other=new HotelCollectionCanonicalService($p,901002,str_repeat('b',32),$f['clock']);hccNoWrite($p,fn()=>$other->workerRead($args),'other_active_admin_wrong_owner','canonical_service_access_denied');
    foreach(['revoked','scope','admin']as$case){
        if($case==='revoked')hccExec($p,'UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?',[1793500001,$f['conn']]);
        elseif($case==='scope')hccExec($p,"UPDATE manager_preparation_oauth_grants SET scopes='queue:read prepared:write' WHERE grant_id=?",[$f['conn']]);
        else hccExec($p,'UPDATE managers SET is_active=0 WHERE id=?',[901001]);
        hccNoWrite($p,fn()=>$f['service']->workerRead($args),'real_oauth_rechecks_'.$case,'canonical_service_access_denied');
        hccExec($p,'UPDATE manager_preparation_oauth_grants SET revoked_at=NULL,scopes=? WHERE grant_id=?',[ManagerPreparationOAuth::HOTEL_SCOPE,$f['conn']]);hccExec($p,'UPDATE managers SET is_active=1 WHERE id=?',[901001]);
    }
    hccExec($p,"UPDATE hotel_work_queues SET oauth_connection_id=? WHERE queue_id='COLLECTION-SERVICE'",[str_repeat('b',32)]);
    hccNoWrite($p,fn()=>$f['service']->workerRead($args),'queue_connection_changed','canonical_service_access_denied');hccExec($p,"UPDATE hotel_work_queues SET oauth_connection_id=? WHERE queue_id='COLLECTION-SERVICE'",[$f['conn']]);
    hccExec($p,"UPDATE hotel_work_queues SET stage_index=1 WHERE queue_id='COLLECTION-SERVICE'");hccNoWrite($p,fn()=>$f['service']->workerRead($args),'current_stage_changed','canonical_service_current_stage_required');hccExec($p,"UPDATE hotel_work_queues SET stage_index=0 WHERE queue_id='COLLECTION-SERVICE'");
    hccExec($p,"UPDATE hotel_journal_operations SET state='snapshot_committed' WHERE operation_id=?",[$intent['operation_id']]);hccNoWrite($p,fn()=>$f['service']->workerRead($args),'completed_operation_cannot_read_as_worker','canonical_service_current_operation_required');hccExec($p,"UPDATE hotel_journal_operations SET state='admitted' WHERE operation_id=?",[$intent['operation_id']]);
    $raw=hccPacket($a);$candidate=$adapter->call('hotel_work_collection_candidate',$args+['request_id'=>'candidate-one','packet_json'=>$raw],901001,$f['conn']);
    hccCheck($candidate['raw_sha256']===hash('sha256',$raw)&&!$candidate['business_accepted'],'adapter_candidate_real_store_roundtrip');
    hccNoWrite($p,fn()=>$f['service']->workerSubmit($args+['request_id'=>'library-replacement','packet_json'=>hccJson(['library_file_id'=>'fake-newer','WORKING'=>[]])]),'raw_library_replacement_rejected','canonical_document_object_keys');
    hccNoWrite($p,fn()=>$f['service']->commit('COLLECTION-SERVICE',$f['stage'],1,$intent['worker_capability'],$intent['operation_id'],'worker-commit',$candidate['raw_sha256'],0,$a['base_snapshot_sha256']),'worker_cannot_control_commit','canonical_service_control_capability_required');
    $faulty=new HotelCollectionCanonicalService($p,901001,$f['conn'],$f['clock'],static function(string$point):void{if($point==='after_commit:commit')throw new RuntimeException('synthetic_lost_ack');});
    hccReject(fn()=>$faulty->commit('COLLECTION-SERVICE',$f['stage'],1,$f['control'],$intent['operation_id'],'commit-one',$candidate['raw_sha256'],0,$a['base_snapshot_sha256']),'real_service_commit_lost_ack','synthetic_lost_ack');
    $receipt=hccCommit($f,$a,$args,$candidate);hccCheck($receipt['revision']===1&&!$receipt['business_accepted']&&!$receipt['native_run_verified'],'derived_commit_not_business_native_proof');
    hccCheck(hccCommit($f,$a,$args,$candidate)===$receipt,'service_exact_lost_ack_replay');
    hccCheck($queueBefore===$p->query("SELECT * FROM hotel_work_queues WHERE queue_id='COLLECTION-SERVICE'")->fetch(PDO::FETCH_ASSOC),'collection_does_not_advance_or_claim_business_queue');
    hccCheck($p->query("SELECT state FROM hotel_journal_operations WHERE operation_id='".$intent['operation_id']."'")->fetchColumn()==='admitted','collection_does_not_invent_journal_business_completion');

    // Actual second enrolled stage is fixture setup only, never a production
    // advancement command. It lets the receipt-link corruption test use two
    // independently valid canonical receipts, not invented native run IDs.
    hccExec($p,"UPDATE hotel_work_queues SET stage_index=1 WHERE queue_id='COLLECTION-SERVICE'");
    $oldStage=$f['stage'];$f['stage']=hash('sha256',hccJson(['COLLECTION-SERVICE',$queueBefore['plan_sha256'],1]));
    $f['journal']->enroll('COLLECTION-SERVICE',$f['stage'],$f['sha'],$f['control'],'fixture-controller');
    [$intent2,$a2,$args2]=hccPrepared($f,'two');$c2=$f['service']->workerSubmit($args2+['request_id'=>'candidate-two','packet_json'=>hccPacket($a2,'two')]);$r2=hccCommit($f,$a2,$args2,$c2,'commit-two');
    hccCheck($r2['revision']===2&&$f['service']->status('COLLECTION-SERVICE')['counts']['facts']===2,'second_stage_cumulative_derived_data');
    $requestRows=$p->query("SELECT operation_id,receipt_bytes,receipt_sha256 FROM hotel_collection_requests WHERE method='commit'")->fetchAll(PDO::FETCH_ASSOC);$rows=[];foreach($requestRows as$row)$rows[$row['operation_id']]=$row;
    hccExec($p,"UPDATE hotel_collection_requests SET receipt_bytes=?,receipt_sha256=? WHERE operation_id=? AND method='commit'",[$rows[$intent2['operation_id']]['receipt_bytes'],$rows[$intent2['operation_id']]['receipt_sha256'],$intent['operation_id']]);
    hccNoWrite($p,fn()=>$f['service']->receipt('COLLECTION-SERVICE',$oldStage,$f['control'],$intent['operation_id'],'commit-one'),'swapped_valid_receipt_rejected','canonical_store_receipt_operation_mismatch');
    hccExec($p,"UPDATE hotel_collection_requests SET receipt_bytes=?,receipt_sha256=? WHERE operation_id=? AND method='commit'",[$rows[$intent['operation_id']]['receipt_bytes'],$rows[$intent['operation_id']]['receipt_sha256'],$intent['operation_id']]);
    $next=$f['inventory'];$next['entries'][]=['reserve_id'=>'new-protected','country'=>'TH','hotel_keys'=>['another-fixture'],'protected'=>true];
    $policy=$f['service']->refreshReserves('COLLECTION-SERVICE',$f['stage'],1,$f['control'],0,$next,'fresh control-attested inventory');
    hccCheck($policy['reserve_revision']===1&&(int)$p->query('SELECT COUNT(*) FROM hotel_collection_policy_versions')->fetchColumn()===2,'immutable_policy_versions_persisted');
    hccNoWrite($p,fn()=>$f['service']->workerRead($args2),'changed_reservations_hold_worker_reads','canonical_service_reservation_snapshot_changed');
    hccCheck($f['service']->receipt('COLLECTION-SERVICE',$oldStage,$f['control'],$intent['operation_id'],'commit-one')===$receipt,'historical_receipt_after_stage_and_reserve_change');
    hccNoWrite($p,fn()=>$f['service']->refreshReserves('COLLECTION-SERVICE',$f['stage'],1,$f['control'],1,$f['inventory'],'attempt remove'),'protected_reserve_cannot_drop_or_expire','canonical_service_protected_reserve_removal_forbidden');

    $g=hccFixture();$gi=hccIntent($g);
    hccNoWrite($g['p'],fn()=>$g['service']->prepare('COLLECTION-SERVICE',$g['stage'],1,$g['control'],$gi['operation_id'],'bad-scope','bad-packet',['melissa-fixture'],0),'explicit_protected_reserve_overlap','canonical_service_protected_reserve_overlap');
    $ga=$g['service']->prepare('COLLECTION-SERVICE',$g['stage'],1,$g['control'],$gi['operation_id'],'scope-courtyard','new-packet',['courtyard-fixture'],0);
    $g['journal']->dispatchIntent('COLLECTION-SERVICE',$g['stage'],1,$g['control'],$gi['operation_id'],'dispatch');$g['journal']->admit('COLLECTION-SERVICE',$g['stage'],1,$gi['operation_id'],$gi['worker_capability'],'admit','fixture-instance');
    $gw=['queue_id'=>'COLLECTION-SERVICE','stage_id'=>$g['stage'],'generation'=>1,'operation_id'=>$gi['operation_id'],'worker_capability'=>$gi['worker_capability'],'instance_id'=>'fixture-instance'];
    $gc=$g['service']->workerSubmit($gw+['request_id'=>'candidate','packet_json'=>hccPacket($ga)]);
    $new=$g['inventory'];$new['entries'][]=['reserve_id'=>'later-overlap','country'=>'TH','hotel_keys'=>['courtyard-fixture'],'protected'=>true];$g['service']->refreshReserves('COLLECTION-SERVICE',$g['stage'],1,$g['control'],0,$new,'later protected reservation');
    hccNoWrite($g['p'],fn()=>hccCommit($g,$ga,$gw,$gc),'reserve_change_serialized_before_new_commit','canonical_service_reservation_snapshot_changed');
    hccCheck((int)$g['p']->query('SELECT revision FROM hotel_collection_heads')->fetchColumn()===0,'reserve_race_no_data_commit');

    $m=hccFixture();[,,$mw]=hccPrepared($m);$m['p']->exec('DELETE FROM hotel_collection_heads');
    hccNoWrite($m['p'],fn()=>$m['service']->workerRead($mw),'missing_canonical_store_holds_without_library_fallback','canonical_store_not_bootstrapped');
    hccCheck($m['p']->query("SELECT write_protocol FROM hotel_work_queues WHERE queue_id='COLLECTION-SERVICE'")->fetchColumn()==='journal_v1','missing_store_never_reopens_legacy_protocol');
    $b=hccFixture(true);$bi=hccIntent($b);
    hccNoWrite($b['p'],fn()=>$b['service']->prepare('COLLECTION-SERVICE',$b['stage'],1,$b['control'],$bi['operation_id'],'large','large-packet',['courtyard-fixture'],0),'bounded_projection_holds_without_truncation','canonical_service_projection_too_large');

    // The service accepts the separate hotels audience only when the actual
    // canonical OAuth client namespace/resource/scopes all match it.
    $h=hccFixture();[,,$hw]=hccPrepared($h);$hotelClient='hot_'.str_repeat('d',44);
    $s=$h['p']->prepare('SELECT client_id FROM manager_preparation_oauth_clients WHERE client_id=?');$s->execute([$hotelClient]);
    if($s->fetchColumn()===false)hccExec($h['p'],'INSERT INTO manager_preparation_oauth_clients(client_id,redirect_uri,registration_bucket,created_at) VALUES(?,?,?,?)',[$hotelClient,ManagerPreparationOAuth::REDIRECT_URI,str_repeat('d',64),1793500000]);
    hccExec($h['p'],'UPDATE manager_preparation_oauth_grants SET client_id=?,resource=? WHERE grant_id=?',[$hotelClient,ManagerPreparationOAuth::ORIGIN.'/manager/hotels-mcp.php',$h['conn']]);
    hccCheck($h['service']->workerRead($hw)['operation_id']===$hw['operation_id'],'actual_hotels_oauth_profile_supported');
    hccExec($h['p'],'UPDATE manager_preparation_oauth_grants SET resource=? WHERE grant_id=?',[ManagerPreparationOAuth::RESOURCE,$h['conn']]);
    hccNoWrite($h['p'],fn()=>$h['service']->workerRead($hw),'mixed_audience_client_namespace_denied','canonical_service_access_denied');
}

try{hccRun();echo 'HOTEL COLLECTION CANONICAL SERVICE: '.$hccChecks.' PASS ('.(getenv('HOTEL_WORK_NATIVE_MYSQL')==='1'?'MySQL actual082/083 migrations':'SQLite')."; real OAuth/journal/adapter path, no native/domain acceptance proof)\n";}
finally{foreach($hccFiles as$file){if(is_file($file))unlink($file);if(is_file($file.'-journal'))unlink($file.'-journal');}}
