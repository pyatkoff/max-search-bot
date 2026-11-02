<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/HotelServerCycle.php';
require_once dirname(__DIR__,2).'/services/HotelServerCatalogProjector.php';

function hsScope(string $hotel): array { return ['country'=>'ZZ','hotel'=>$hotel,'question'=>'synthetic-question','source'=>'synthetic-source']; }
function hsLegacy(): array
{
    $rows=[];
    for($i=0;$i<38;$i++) {
        $r=['outcome'=>'accepted_readback','packet_sha256'=>hash('sha256','packet-'.intdiv($i,2))]; $bytes=HotelServerInputs::json($r);
        $rows[]=['queue_id'=>HotelServerInputs::OLD_QUEUE,'stage_index'=>$i,'stage_id'=>hash('sha256','stage-'.$i),'receipt_sha256'=>hash('sha256',$bytes),'receipt_json'=>$bytes];
    }
    $bytes=HotelServerInputs::json(['outcome'=>'accepted_readback','packet_sha256'=>hash('sha256','unaccepted-TR40')]);
    $rows[]=['queue_id'=>HotelServerInputs::OLD_QUEUE,'stage_index'=>40,'stage_id'=>hash('sha256','TR40'),'receipt_sha256'=>hash('sha256',$bytes),'receipt_json'=>$bytes];
    $snapshot=HotelServerInputs::json(['hotel_work_receipts'=>$rows,'hotel_work_resolutions'=>[]]);
    $receipt=HotelServerInputs::json(['queue_id'=>HotelServerInputs::OLD_QUEUE,'state'=>'retired','accepted_batches'=>19,'acceptance_credit'=>0,'original_operation_outcome'=>'UNKNOWN','snapshot_sha256'=>hash('sha256',$snapshot)]);
    return ['snapshot_bytes'=>$snapshot,'original_state_sha256'=>hash('sha256',$snapshot),'receipt_bytes'=>$receipt];
}
function hsInputs(?array $mapping=null,?array $contexts=null): HotelServerInputs
{
    $ret=hsLegacy(); $pin=hash('sha256',$ret['receipt_bytes']); $registry=HotelServerInputs::registry($ret,$pin);
    $mapping??=array_map(static fn(array $r): array=>$r+['scopes'=>[hsScope('old-'.$r['stage_index'])]],$registry);
    $bytes=array_fill_keys(HotelServerInputs::REQUIRED,'synthetic immutable input');
    $bytes['baseline']='synthetic original baseline bytes; never modified';
    $bytes['accepted_mapping']=HotelServerInputs::json($mapping); $bytes['reserves']=HotelServerInputs::json([hsScope('reserve')]);
    $held=HotelServerInputs::quarantine($ret,$pin);
    $bytes['quarantine_mapping']=HotelServerInputs::json(array_map(static fn(array $r):array=>$r+['scopes'=>[hsScope('TR40-held')]],$held));
    $bytes['accepted_context']=HotelServerInputs::json(array_map(static fn(array $r):array=>['scope'=>hsScope('old-'.$r['stage_index']),'answer'=>'Previously accepted synthetic history','packet_sha256'=>$r['packet_sha256'],'receipt_sha256'=>$r['receipt_sha256']],$registry));
    if($contexts===null){
        $contexts=[];
        foreach(['new-0','new-1','reserve','old-1','TR40-held'] as $hotel)$contexts[]=['scope'=>hsScope($hotel),'dependencies'=>array_fill_keys(['archives','corpus','criteria','instructions','map'],'Assigned synthetic projection')];
        $follow=hsScope('new-0');$follow['question']='followup-question';$contexts[]=['scope'=>$follow,'dependencies'=>array_fill_keys(['archives','corpus','criteria','instructions','map'],'Assigned followup projection')];
    }
    $bytes['stage_contexts']=HotelServerInputs::json($contexts);
    return HotelServerInputs::validate($ret,$pin,$bytes);
}
function hsPlan(int $stages=2): array
{
    return ['stages'=>array_map(static fn(int $i): array=>[hsScope('new-'.$i)],range(0,$stages-1)),
        'models'=>['producer'=>'synthetic-research','reviewer'=>'synthetic-independent-review'],
        'limits'=>['deadline'=>2000000000,'max_calls'=>20,'max_tokens'=>20000,'max_micros'=>200000,'tokens_per_call'=>1000,'micros_per_call'=>10000]];
}
final class HotelServerFixtureProvider implements HotelServerProvider
{
    public array $requests=[]; public array $results=[]; public bool $loseAck=false; public bool $ready=true; public bool $samePrincipal=false;
    public $onSend=null;
    public function __construct(private PDO $p) {}
    public function ready(string $role,string $model): bool { return $this->ready; }
    public function principal(string $role): string { return $this->samePrincipal?'same':$role.'-fixture-principal'; }
    public function send(array $r): array
    {
        if($this->p->inTransaction()) throw new RuntimeException('network_under_SQL_lock');
        $s=$this->p->prepare('SELECT state FROM hotel_server_attempts WHERE attempt_id=?');$s->execute([$r['attempt_id']]);
        $state=$s->fetchColumn();$s->closeCursor();
        if($state!=='unknown') throw new RuntimeException('unknown_not_durable_before_send');
        $this->requests[]=$r;
        $result=['attempt_id'=>$r['attempt_id'],'principal'=>$r['principal'],'baseline_sha256'=>$r['baseline_sha256'],'inputs_sha256'=>$r['inputs_sha256'],'usage'=>['tokens'=>17,'micros'=>9]];
        if($r['role']==='producer') $result['candidate']=['schema'=>'hotel-server-candidate-v1','records'=>array_map(static fn(array $scope): array=>['scope'=>$scope,'answer'=>'Synthetic fixture answer','evidence'=>[['url'=>'https://example.invalid/fixture','quote'=>'Synthetic evidence only']]],$r['scopes'])];
        else $result+=['candidate_sha256'=>$r['candidate_sha256'],'criteria_sha256'=>$r['criteria_sha256'],'approved'=>true,'evidence'=>'Independent synthetic criteria review'];
        $this->results[]=$result;
        if($this->onSend!==null)($this->onSend)();
        if($this->loseAck) throw new RuntimeException('synthetic_lost_ACK');
        return $result;
    }
}

function hsConnect(string $dsn): PDO
{
    $native=str_starts_with($dsn,'mysql:');
    if($native && !preg_match('/(?:\Amysql:|;)dbname=(maxbot_prepared_test_hs_[a-f0-9]+)(?:;|\z)/D',$dsn)) throw new RuntimeException('unsafe_server_fixture_DSN');
    $p=new PDO($dsn,$native?(getenv('PREPARED_REPLY_MYSQL_USER')?:null):null,$native?(getenv('PREPARED_REPLY_MYSQL_PASS')?:null):null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if($native) $p->setAttribute(PDO::ATTR_EMULATE_PREPARES,false); else $p->exec('PRAGMA busy_timeout=10000');
    return $p;
}
function hsSchema(PDO $p): void
{
    $native=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $p->exec('CREATE TABLE hotel_work_lane(lane_key VARCHAR(16) PRIMARY KEY,queue_id VARBINARY(80) NULL)'.($native?' ENGINE=InnoDB':''));
    $p->exec("INSERT INTO hotel_work_lane VALUES('HOTEL',NULL)");
    foreach(['migrations/084_hotel_server_cycle.sql','migrations/hotel/084_hotel_server_projection.sql'] as $file) {
        $sql=(string)file_get_contents(dirname(__DIR__,2).'/'.$file);
        foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[] as $statement) {
            $statement=trim($statement); if($statement==='')continue;
            if(!$native) {
                $statement=str_replace(' ENGINE=InnoDB','',$statement);
                $statement=preg_replace("/FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable hotel server evidence'/","FOR EACH ROW BEGIN SELECT RAISE(ABORT,'immutable hotel server evidence'); END",$statement);
            }
            $p->exec($statement);
        }
    }
}
function hsFixture(): array
{
    $native=getenv('HOTEL_WORK_NATIVE_MYSQL')==='1'; $dir=sys_get_temp_dir().'/hotel-server-'.bin2hex(random_bytes(8));mkdir($dir,0700);
    $admin=null;$db=null;
    if($native) {
        $base=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
        if(!preg_match('/(?:\Amysql:|;)dbname=maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?(?:;|\z)/D',$base))throw new RuntimeException('unsafe_fixture_base');
        $db='maxbot_prepared_test_hs_'.bin2hex(random_bytes(8));
        $admin=new PDO($base,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $admin->exec('CREATE DATABASE `'.$db.'`'); $dsn=preg_replace('/dbname=[^;]+/','dbname='.$db,$base);
    } else $dsn='sqlite:'.$dir.'/operations.sqlite';
    $p=hsConnect($dsn);hsSchema($p);$lane=new HotelServerLane($p);$provider=new HotelServerFixtureProvider($p);$clock=(object)['now'=>1800000000];
    $cycle=new HotelServerCycle($p,$lane,$provider,static fn(): int=>$clock->now);
    return compact('p','lane','provider','cycle','clock','dsn','dir','db','admin');
}
function hsCleanup(array &$f): void
{
    $f['cycle']=null;$f['provider']=null;$f['lane']=null;$f['p']=null;
    if($f['db']!==null) { if(!preg_match('/\Amaxbot_prepared_test_hs_[a-f0-9]+\z/D',$f['db']))throw new RuntimeException('unsafe_cleanup'); $f['admin']->exec('DROP DATABASE `'.$f['db'].'`'); }
    $f['admin']=null;
    foreach(glob($f['dir'].'/*')?:[] as $file) unlink($file);rmdir($f['dir']);
}
