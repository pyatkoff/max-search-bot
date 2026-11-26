<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
function v2TestPackage(string $name): string {
    $base=dirname(__DIR__,2); $path=$base.'/'.$name;
    if (!is_dir($path)) $path=$base.'/max2-common-v2-'.$name.'-20261121';
    if (!is_dir($path)) throw new RuntimeException('SYNTHETIC_DEPENDENCY_MISSING');
    return $path;
}
require_once v2TestPackage('policy').'/services/Max2CommonValueAccountingV2.php';
require_once v2TestPackage('ledger').'/services/Max2CommonValueRepositoryV2.php';
require_once v2TestPackage('lookup').'/services/Max2ValueLookupClientV2.php';
require_once v2TestPackage('delivery').'/services/Max2CommonValueMetrikaClientV2.php';
require_once dirname(__DIR__).'/services/Max2CommonValueStateV2.php';
require_once dirname(__DIR__).'/services/Max2CommonValueWorkerV2.php';

final class V2WorkerMemoryPdo extends PDO {
    public int $commits=0;
    public ?int $loseCommit=null;
    public function commit(): bool {
        $result=parent::commit(); $this->commits++;
        if ($this->loseCommit===$this->commits) { $this->loseCommit=null; throw new PDOException('synthetic lost commit acknowledgement'); }
        return $result;
    }
}
function v2TestDb(): V2WorkerMemoryPdo {
    $db=new V2WorkerMemoryPdo('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->sqliteCreateFunction('OCTET_LENGTH',static fn($value):int=>strlen((string)$value),1);
    $db->exec('PRAGMA foreign_keys=ON');
    foreach ([v2TestPackage('ledger').'/migrations/112_max2_common_value_v2.sql',dirname(__DIR__).'/migrations/113_max2_common_runtime_v2.sql'] as $path) {
        $sql=file_get_contents($path);
        $sql=str_replace('BIGINT UNSIGNED NOT NULL AUTO_INCREMENT','INTEGER PRIMARY KEY AUTOINCREMENT',$sql);
        $sql=str_replace(' CHARACTER SET ascii COLLATE ascii_bin','',$sql);
        $sql=preg_replace('/^\s*PRIMARY KEY \(id\),\n/m','',$sql);
        // Settings id is not an auto-increment: retain its one explicit primary key.
        if (str_contains($path,'113_')) $sql=str_replace('id TINYINT UNSIGNED NOT NULL,','id INTEGER PRIMARY KEY,',$sql);
        $sql=preg_replace('/UNIQUE KEY ([a-z0-9_]+) \(/','CONSTRAINT $1 UNIQUE (',$sql);
        $sql=preg_replace('/^\s*KEY [a-z0-9_]+ \([^\n]+\),\n/m','',$sql);
        $sql=preg_replace('/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/',' );',$sql);
        $db->exec($sql);
    }
    $db->exec('CREATE TABLE modeled_conversion_profile_versions (id INTEGER PRIMARY KEY,project_key TEXT NOT NULL)');
    $db->exec("INSERT INTO modeled_conversion_profile_versions VALUES (10,'anytour'),(15,'other')");
    $db->exec('CREATE TABLE max_channel_conversion_outbox (id INTEGER PRIMARY KEY,value_rub INTEGER,target TEXT)');
    $db->exec("INSERT INTO max_channel_conversion_outbox VALUES(1,300,'anytour_modeled_value_v1')");
    return $db;
}
function v2TestState(V2WorkerMemoryPdo $db,int &$clock,string $mode='collect'): Max2CommonValueStateV2 {
    $clock=1795263000; $state=new Max2CommonValueStateV2($db,static function() use(&$clock):int{return $clock;},true);
    $made=$state->activate(sha1('synthetic source'),hash('sha256','synthetic activation request, not a live release'));
    if (($made['status']??null)!=='ACTIVATED') throw new RuntimeException('SYNTHETIC_ACTIVATION_FAILED');
    $clock=1795263660;
    if ($mode!=='collect') {
        $changed=$state->setMode($mode,hash('sha256','synthetic mode '.$mode));
        if (($changed['status']??null)!=='MODE_SET') throw new RuntimeException('SYNTHETIC_MODE_FAILED');
    }
    return $state;
}
function v2TestProjection(string $profile='107',$value='100.00'): array {
    $s=['project_key'=>'anytour','platform'=>'max','customer_key'=>'17','conversation_id'=>'29'];
    $inquiry=['subject'=>$s,'journal_id'=>'103','native_message_id'=>'97','kind'=>'substantive_interest',
        'source_version'=>1,'verified_customer_message'=>true,'recorded_at_unix'=>1795263600];
    $key=Max2ValueBindingPolicyV2::inquiryKey($s,'103');
    $proof=['version'=>2,'counter_id'=>98615635,'subject'=>$s,'inquiry_key'=>$key,'source_receipt_sha256'=>str_repeat('a',64),
        'complete'=>true,'cutoff_unix'=>1795263600,'observed_at_unix'=>1795263650,'blank_row_count'=>4,'own_reserve_only'=>true,
        'producer_contract_sha256'=>Max2ValueBindingPolicyV2::PRODUCER_CONTRACT_SHA256,'status'=>'complete_empty',
        'coverage'=>'all_exact_actor_own_early_reserves_at_observation','possible_max2_unresolved'=>false,'candidates'=>[],
        'actor_binding'=>['authenticated_actor'=>true,'subject'=>$s,'external_user_id'=>'91','external_chat_id'=>'-91',
            'customer_channel_id'=>'25','subject_actor_binding_sha256'=>str_repeat('e',64)]];
    $binding=Max2ValueBindingPolicyV2::resolve($s,$inquiry,$proof);
    return ['status'=>'READY','binding'=>$binding,
        'estimate'=>['admitted_saved_profile'=>true,'subject'=>$s,'inquiry_journal_id'=>'103','profile_id'=>$profile,
            'profile_sha256'=>hash('sha256','synthetic profile '.$profile),'model_version'=>'synthetic_saved_model_v1','currency'=>'RUB',
            'value_rub'=>$value,'input_cutoff_unix'=>1795263650,'event_unix'=>1795263655,'profile_observed_at_unix'=>1795263660],
        'identity'=>['admitted'=>true,'subject'=>$s,'inquiry_key'=>$key,'client_id'=>'1234567890123456789',
            'source_sha256'=>hash('sha256','synthetic identity'),'basis'=>'saved_inquiry_identity'],'recheck'=>[]];
}
function v2TestLookup(callable $transport,callable $clock): Max2ValueLookupClientV2 {
    static $private=null;
    if ($private===null) {
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
        if ($key===false||!openssl_pkey_export($key,$private)) throw new RuntimeException('SYNTHETIC_KEY_FAILED');
    }
    return new Max2ValueLookupClientV2($transport,static fn():string=>$private,$clock);
}
function v2TestLookupReply(array $wire,int $now): array {
    $p=Max2ValueLookupProtocolV2::class; $r=$p::decodeBody($wire['body'],$now);
    $reply=['version'=>2,'purpose'=>$p::PURPOSE,'action'=>'list','counter_id'=>$p::COUNTER,'metric_project_id'=>$p::PROJECT,
        'external_user_id'=>$r['external_user_id'],'context'=>$r['context'],'request_sha256'=>hash('sha256',$wire['body']),
        'producer_contract_sha256'=>$p::PRODUCER_CONTRACT_SHA256,'checked_at_unix'=>$now,'tracker_timezone'=>'Europe/London',
        'coverage'=>$p::COVERAGE,'own_reserve_only'=>true,'status'=>'complete_empty','complete_within_bound'=>true,
        'scanned_rows'=>4,'scoped_rows'=>4,'blank_row_count'=>4,'candidates'=>[],'set_sha256'=>$p::setDigest([]),
        'possible_max2_unresolved'=>false,'reason'=>null,'financial_permission'=>false];
    return ['status_code'=>200,'body'=>$p::json($reply),'request_count'=>1,'redirects_followed'=>0,'tls_verified'=>true];
}
function v2TestPage(PDO $db): Closure {
    return static function(string $cursor,int $limit,int $floor) use($db):array {
        $q=$db->prepare("SELECT id FROM modeled_conversion_profile_versions WHERE project_key='anytour' AND id>? ORDER BY id LIMIT ".(int)$limit);
        $q->execute([$cursor]); return array_map(static fn($id):array=>['profile_id'=>(string)$id],$q->fetchAll(PDO::FETCH_COLUMN));
    };
}
