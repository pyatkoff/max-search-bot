<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/AdvertisingVisitImportService.php';

function advertisingVisitScope(array $changes = []): array
{
    return array_replace(['project_key'=>'fixture','source_key'=>'tgmini','platform'=>'telegram','counter_id'=>'98615635'], $changes);
}

function advertisingVisitRow(string $cursor, array $changes = []): array
{
    return array_replace(['source_cursor'=>$cursor,'visit_id'=>'entry-a','visit_at_unix'=>1792940000,
        'observed_at_unix'=>1792940010,'external_user_id'=>'18446744073709551615','identity_status'=>'verified',
        'client_id'=>'17929400001234567890','yclid'=>'18446744073709551614','campaign_id'=>'12','region_id'=>'213'], $changes);
}

function advertisingVisitPage(array $rows, string $after = '0', ?string $next = null, array $scope = []): array
{
    return ['schema_version'=>1] + advertisingVisitScope($scope) + ['after_cursor'=>$after,
        'next_cursor'=>$next ?? ($rows ? $rows[count($rows)-1]['source_cursor'] : $after),'observations'=>$rows];
}

function advertisingVisitFixtureSchema(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $migration = file_get_contents(dirname(__DIR__).'/migrations/063_advertising_visits.sql');
        $pdo->exec($migration);
        $pdo->exec($migration); // Native forward migration must be safe to retry.
        return;
    }
    $pdo->exec('CREATE TABLE advertising_visit_cursors (project_key TEXT,source_key TEXT,platform TEXT,counter_id TEXT,last_cursor TEXT NOT NULL DEFAULT \'0\',PRIMARY KEY(project_key,source_key,platform,counter_id))');
    $pdo->exec('CREATE TABLE advertising_visit_observations (id INTEGER PRIMARY KEY AUTOINCREMENT,project_key TEXT,source_key TEXT,platform TEXT,counter_id TEXT,source_cursor TEXT,visit_id TEXT,visit_at_unix INTEGER,observed_at_unix INTEGER,external_user_id TEXT,identity_status TEXT,client_id TEXT,yclid TEXT,campaign_id TEXT,region_id TEXT,payload_sha256 TEXT,received_at_unix INTEGER,UNIQUE(project_key,source_key,platform,counter_id,source_cursor))');
}

function advertisingVisitAssertions(PDO $pdo, callable $check): void
{
    $now = 1792941000;
    $scope = advertisingVisitScope();
    $repo = new AdvertisingVisitRepository($pdo);
    $service = new AdvertisingVisitImportService($repo, $scope);
    $count = static fn() => (int)$pdo->query('SELECT COUNT(*) FROM advertising_visit_observations')->fetchColumn();
    $reject = static function(callable $call, string $expected, string $name) use ($check): void {
        try { $call(); $check($name, false); }
        catch (Throwable $e) { $check($name, $e->getMessage() === $expected); }
    };
    $check('scope order is canonical', AdvertisingVisitContract::scope(array_reverse($scope, true)) === $scope);
    $check('large decimal comparison avoids integer overflow', AdvertisingVisitContract::compare('18446744073709551615','18446744073709551614') > 0);
    $check('decimal comparison is numeric across lengths', AdvertisingVisitContract::compare('9','10') < 0);
    $r = $service->import(advertisingVisitPage([]), $now);
    $check('empty page does not invent a visitor', $r === ['inserted'=>0,'replayed'=>0,'cursor'=>'0'] && $count() === 0);
    $partial = advertisingVisitRow('1', ['client_id'=>'','external_user_id'=>'','identity_status'=>'missing']);
    $verified = advertisingVisitRow('2');
    $p = advertisingVisitPage([$partial,$verified]);
    $r = $service->import($p, $now);
    $check('entry and later verified identity are separate immutable observations', $r === ['inserted'=>2,'replayed'=>0,'cursor'=>'2'] && $count() === 2);
    $h = $repo->verifiedHistory(array_reverse($scope, true), '18446744073709551615');
    $check('large user and client IDs survive lookup with shuffled scope', count($h) === 1 && $h[0]['client_id'] === $verified['client_id'] && $h[0]['yclid'] === $verified['yclid']);
    $check('timestamps preserve visit and observation instants', (string)$h[0]['visit_at_unix'] === '1792940000' && (string)$h[0]['observed_at_unix'] === '1792940010');
    $reordered = $p;
    $reordered['observations'] = array_map(static fn($row) => array_reverse($row, true), $reordered['observations']);
    $r = $service->import($reordered, $now + 10);
    $check('replayed page and reordered JSON keys produce no duplicates', $r === ['inserted'=>0,'replayed'=>2,'cursor'=>'2'] && $count() === 2);
    $r = $service->import(advertisingVisitPage([advertisingVisitRow('3',['client_id'=>''])],'2'),$now);
    $check('later empty ClientID does not erase known identity', $r['inserted'] === 1 && $count() === 3 && count($repo->verifiedHistory($scope,'18446744073709551615')) === 1);
    $r = $service->import($p, $now);
    $check('old exact replay never moves checkpoint backwards', $r['cursor'] === '3' && $r['replayed'] === 2 && $count() === 3);
    $r = $service->import(advertisingVisitPage([advertisingVisitRow('4',['visit_id'=>'entry-b','client_id'=>'17929400009999999999','observed_at_unix'=>1792940020])],'3'),$now);
    $h = $repo->verifiedHistory($scope, '18446744073709551615');
    $check('repeat advertising visits retain both ClientIDs', count($h) === 2 && $h[0]['visit_id'] === 'entry-b' && $h[1]['visit_id'] === 'entry-a');
    $r = $service->import(advertisingVisitPage([advertisingVisitRow('5',['identity_status'=>'unverified','client_id'=>'17929400008888888888'])],'4'),$now);
    $check('unverified identity remains an observation but is not a match', $r['inserted'] === 1 && count($repo->verifiedHistory($scope,'18446744073709551615')) === 2);
    foreach ([['project_key'=>'another'],['source_key'=>'another'],['platform'=>'max'],['counter_id'=>'98615636']] as $changes) {
        $other = advertisingVisitScope($changes);
        $importer = new AdvertisingVisitImportService($repo, $other);
        $importer->import(advertisingVisitPage([advertisingVisitRow('1')],'0',null,$changes),$now);
        $check('separate identity scope '.key($changes), count($repo->verifiedHistory($other,'18446744073709551615')) === 1 && count($repo->verifiedHistory($scope,'18446744073709551615')) === 2);
        $bad = advertisingVisitPage([advertisingVisitRow('6')],'5',null,$changes);
        $before = $count();
        $reject(static fn()=>$service->import($bad,$now),'advertising_visit_invalid_scope_mismatch','foreign page rejected '.key($changes));
        $check('foreign page leaves data unchanged '.key($changes), $count() === $before);
    }
    $changed = $p;
    $changed['observations'][1]['client_id'] = '17929400007777777777';
    $before = $count();
    $reject(static fn()=>$service->import($changed,$now),'advertising_visit_observation_conflict','changed replay is rejected');
    $check('changed replay preserves data', $count() === $before);
    $reject(static fn()=>$service->import(advertisingVisitPage([advertisingVisitRow('8')],'7'),$now),'advertising_visit_cursor_conflict','missing feed interval is rejected');
    $reject(static fn()=>$service->import(advertisingVisitPage([advertisingVisitRow('6')],'3'),$now),'advertising_visit_cursor_conflict','overlapping stale page cannot append observations');
    $before = $count();
    $bad = advertisingVisitPage([advertisingVisitRow('6'),advertisingVisitRow('7',['client_id'=>'not-an-id'])],'5');
    $reject(static fn()=>$service->import($bad,$now),'advertising_visit_invalid_client_id','whole page validates before any write');
    $check('invalid later row leaves earlier row unwritten', $count() === $before);
    $bad = advertisingVisitPage([advertisingVisitRow('6',['visit_at_unix'=>1792939900])],'5');
    $reject(static fn()=>$service->import($bad,$now),'advertising_visit_entry_conflict','enrichment cannot rewrite the original entry time');
    $bad = advertisingVisitPage([advertisingVisitRow('6',['external_user_id'=>'1234567'])],'5');
    $reject(static fn()=>$service->import($bad,$now),'advertising_visit_identity_conflict','an entry cannot acquire two different verified users');
    $check('entry conflicts preserve all existing observations', $count() === $before);

    $cases = [
        ['source_cursor'=>6,'error'=>'source_cursor'], ['source_cursor'=>'06','error'=>'source_cursor'],
        ['visit_id'=>'../entry','error'=>'visit_id'], ['client_id'=>'1e20','error'=>'client_id'],
        ['external_user_id'=>184467,'error'=>'external_user_id'], ['external_user_id'=>'0','error'=>'external_user_id'],
        ['external_user_id'=>'','error'=>'identity_status'], ['identity_status'=>'missing','error'=>'identity_status'],
        ['visit_at_unix'=>1792940000000,'error'=>'visit_at_unix'], ['visit_at_unix'=>1792941400,'error'=>'visit_at_unix'],
        ['observed_at_unix'=>1792939999,'error'=>'observation_before_visit'],
        ['client_id'=>' 123','error'=>'client_id'], ['yclid'=>'１２３','error'=>'yclid'], ['region_id'=>'-1','error'=>'region_id'],
    ];
    foreach ($cases as $n=>$case) {
        $error = $case['error']; unset($case['error']);
        $bad = advertisingVisitPage([advertisingVisitRow('6',$case)],'5','6');
        $reject(static fn()=>$service->import($bad,$now),'advertising_visit_invalid_'.$error,'invalid field boundary '.$n);
    }
    $bad = advertisingVisitPage([advertisingVisitRow('6',['initData'=>'must-not-be-retained'])],'5');
    $reject(static fn()=>$service->import($bad,$now),'advertising_visit_invalid_fields','raw authentication data cannot enter retained observations');
    $bad = advertisingVisitPage([advertisingVisitRow('6')],'5','7');
    $reject(static fn()=>$service->import($bad,$now),'advertising_visit_invalid_next_cursor','checkpoint cannot skip records outside the page');
    $bad = advertisingVisitPage([],'5','6');
    $reject(static fn()=>$service->import($bad,$now),'advertising_visit_invalid_next_cursor','empty page cannot advance checkpoint');
    $bad = advertisingVisitPage([advertisingVisitRow('6'),advertisingVisitRow('6')],'5');
    $reject(static fn()=>$service->import($bad,$now),'advertising_visit_invalid_cursor_order','duplicate cursors within a page are rejected');
    $bad = advertisingVisitPage(array_fill(0,101,advertisingVisitRow('6')),'5');
    $reject(static fn()=>$service->import($bad,$now),'advertising_visit_invalid_page_bound','page size is bounded');
    $bad = advertisingVisitPage([advertisingVisitRow('6')],'5'); $bad['schema_version'] = 2;
    $reject(static fn()=>$service->import($bad,$now),'advertising_visit_invalid_schema_version','unknown schema cannot silently enter storage');
    $web = new AdvertisingVisitImportService($repo, advertisingVisitScope(['platform'=>'website']));
    $bad = advertisingVisitPage([advertisingVisitRow('1')],'0',null,['platform'=>'website']);
    $reject(static fn()=>$web->import($bad,$now),'advertising_visit_invalid_website_identity','website cannot claim a messenger user ID');
    $web->import(advertisingVisitPage([advertisingVisitRow('1',['external_user_id'=>'','identity_status'=>'missing'])],'0',null,['platform'=>'website']),$now);
    $check('anonymous website visit remains usable without invented messenger identity', $repo->verifiedHistory(advertisingVisitScope(['platform'=>'website']),'18446744073709551615') === []);

    $pdo->beginTransaction();
    $reject(static fn()=>$service->import(advertisingVisitPage([],'5'),$now),'advertising_visit_outer_transaction','import cannot commit an outer business transaction');
    $check('outer transaction remains owned by caller', $pdo->inTransaction());
    $pdo->rollBack();
    $rollbackScope = advertisingVisitScope(['source_key'=>'rollback']);
    $rollback = new AdvertisingVisitImportService($repo,$rollbackScope);
    $rollback->import(advertisingVisitPage([advertisingVisitRow('2')],'0',null,['source_key'=>'rollback']),$now);
    // Deliberately damaged fixture checkpoint: prove a later conflict rolls back an earlier insert.
    $pdo->exec("UPDATE advertising_visit_cursors SET last_cursor='0' WHERE source_key='rollback'");
    $before = $count();
    $bad = advertisingVisitPage([advertisingVisitRow('1'),advertisingVisitRow('2',['client_id'=>'999'])],'0',null,['source_key'=>'rollback']);
    $reject(static fn()=>$rollback->import($bad,$now),'advertising_visit_observation_conflict','mid-page storage conflict rolls back the whole page');
    $check('failed page does not leave a partial insert or advanced checkpoint', $count() === $before && $pdo->query("SELECT last_cursor FROM advertising_visit_cursors WHERE source_key='rollback'")->fetchColumn() === '0');
    $huge = new AdvertisingVisitImportService($repo, advertisingVisitScope(['source_key'=>'large-cursor']));
    $huge->import(advertisingVisitPage([advertisingVisitRow('18446744073709551614')],'0',null,['source_key'=>'large-cursor']),$now);
    $r = $huge->import(advertisingVisitPage([advertisingVisitRow('18446744073709551615')],'18446744073709551614',null,['source_key'=>'large-cursor']),$now);
    $check('source cursor remains exact above signed 64-bit maximum', $r['cursor'] === '18446744073709551615');
    $full = new AdvertisingVisitImportService($repo, advertisingVisitScope(['source_key'=>'full-page']));
    $rows = [];
    for ($i=1;$i<=100;$i++) $rows[] = advertisingVisitRow((string)$i,['visit_id'=>'full-'.$i]);
    $r = $full->import(advertisingVisitPage($rows,'0',null,['source_key'=>'full-page']),$now);
    $check('a full hundred-observation page commits correctly', $r === ['inserted'=>100,'replayed'=>0,'cursor'=>'100']);
    advertisingVisitJournalAssertions($pdo, $check, $now);
}

/** Exact envelope and byte-offset cursors of the delivered Mini App package. */
function advertisingVisitJournalFixture(array $scope, int $count): string
{
    $text = '';
    for ($i=0;$i<$count;$i++) {
        $changes = ['visit_id'=>'journal-'.$i];
        if ($i===0) $changes += ['identity_status'=>'missing','external_user_id'=>'','client_id'=>''];
        if ($i===1) $changes += ['identity_status'=>'unverified'];
        $row = advertisingVisitRow((string)(strlen($text)+1), $changes);
        $text .= json_encode(['schema_version'=>1]+$scope+['observation'=>$row],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    }
    return $text;
}

function advertisingVisitJournalAssertions(PDO $pdo, callable $check, int $now): void
{
    $scope = advertisingVisitScope(['source_key'=>'journal']);
    $repo = new AdvertisingVisitRepository($pdo);
    $service = new AdvertisingVisitImportService($repo,$scope);
    $file = tempnam(sys_get_temp_dir(),'advertising-journal-');
    if ($file === false) throw new RuntimeException('journal_fixture_failed');
    chmod($file,0600);
    $reject = static function(callable $call, string $expected, string $name) use ($check): void {
        try { $call(); $check($name,false); }
        catch (Throwable $e) { $check($name,$e->getMessage()===$expected); }
    };
    $initial = ['cursor'=>'0','fingerprint'=>null];
    try {
        $check('new journal checkpoint is read without creating observations', $repo->checkpoint($scope)===$initial);
        file_put_contents($file,'');
        $read=AdvertisingVisitJournalReader::read($file,$scope,$initial,$now);
        $check('empty journal has an empty page and no invented identity', $read['page']['observations']===[] && !$read['has_more']);
        $text=advertisingVisitJournalFixture($scope,101);
        file_put_contents($file,$text);
        $read=AdvertisingVisitJournalReader::read($file,$scope,$initial,$now);
        $check('journal pagination bounds the first page at one hundred', count($read['page']['observations'])===100 && $read['has_more']);
        $check('journal byte cursors remain strings', is_string($read['page']['next_cursor']) && $read['page']['observations'][0]['source_cursor']==='1');
        $check('journal preserves missing and unverified identities', $read['page']['observations'][0]['identity_status']==='missing' && $read['page']['observations'][1]['identity_status']==='unverified');
        $first=$service->importJournal($file,$now);
        $check('journal imports its page and commits its cursor', $first['inserted']===100 && $first['has_more'] && $first['cursor']===$read['page']['next_cursor']);
        $checkpoint=$repo->checkpoint($scope);
        $check('checkpoint anchors the committed last-row fingerprint', $checkpoint['cursor']===$first['cursor'] && $checkpoint['fingerprint']===AdvertisingVisitContract::fingerprint($read['page']['observations'][99]));
        $second=$service->importJournal($file,$now);
        $check('journal resumes exactly after the committed observation', $second['inserted']===1 && !$second['has_more'] && AdvertisingVisitContract::compare($second['cursor'],$first['cursor'])>0);
        $last=$repo->checkpoint($scope);
        $again=$service->importJournal($file,$now);
        $check('completed journal retry creates no duplicate observations', $again===['inserted'=>0,'replayed'=>0,'cursor'=>$second['cursor'],'has_more'=>false]);
        $history=$repo->verifiedHistory($scope,'18446744073709551615',100);
        $check('journal cannot promote unverified IDs into matching history', count($history)===99 && $history[0]['client_id']==='17929400001234567890');
        $offset=(int)$last['cursor']-1;
        $changed=substr($text,0,$offset).str_replace('17929400001234567890','17929400001234567891',substr($text,$offset));
        file_put_contents($file,$changed);
        $reject(static fn()=>$service->importJournal($file,$now),'advertising_visit_journal_anchor_changed','changed committed anchor is rejected before import');
        $check('anchor failure preserves the receiving checkpoint', $repo->checkpoint($scope)===$last);
        file_put_contents($file,substr($text,0,$offset));
        $reject(static fn()=>$service->importJournal($file,$now),'advertising_visit_journal_checkpoint','truncated journal cannot jump to its checkpoint');
        file_put_contents($file,$text);
        $huge=['cursor'=>'18446744073709551615','fingerprint'=>str_repeat('a',64)];
        $reject(static fn()=>AdvertisingVisitJournalReader::read($file,$scope,$huge,$now),'advertising_visit_journal_checkpoint','journal byte offsets cannot overflow integers');
        $bad=['cursor'=>'2','fingerprint'=>str_repeat('a',64)];
        $reject(static fn()=>AdvertisingVisitJournalReader::read($file,$scope,$bad,$now),'advertising_visit_journal_checkpoint','resume requires a record boundary');
        $lock=fopen($file,'rb');flock($lock,LOCK_EX);
        $reject(static fn()=>AdvertisingVisitJournalReader::read($file,$scope,$initial,$now),'advertising_visit_journal_busy','concurrent writer cannot expose partial rows');
        flock($lock,LOCK_UN);fclose($lock);
        chmod($file,0644);
        $reject(static fn()=>AdvertisingVisitJournalReader::read($file,$scope,$initial,$now),'advertising_visit_journal_file','publicly readable copied journals are rejected');
        chmod($file,0600);
        $reject(static fn()=>AdvertisingVisitJournalReader::read('php://input',$scope,$initial,$now),'advertising_visit_journal_file','journal input cannot be a stream wrapper');
        $foreign=advertisingVisitScope(['source_key'=>'foreign-journal']);
        $reject(static fn()=>AdvertisingVisitJournalReader::read($file,$foreign,$initial,$now),'advertising_visit_journal_scope','journal cannot cross source scope');
        $damaged=advertisingVisitScope(['source_key'=>'damaged-journal']);
        $damagedService=new AdvertisingVisitImportService($repo,$damaged);
        $two=advertisingVisitJournalFixture($damaged,2);
        file_put_contents($file,substr($two,0,-1));
        $reject(static fn()=>$damagedService->importJournal($file,$now),'advertising_visit_journal_incomplete','unterminated later row rejects the complete page');
        $check('invalid journal page writes no rows or checkpoint', $repo->checkpoint($damaged)===$initial && $repo->verifiedHistory($damaged,'18446744073709551615')===[]);
        $one=advertisingVisitJournalFixture($scope,1);
        $record=json_decode($one,true);$record['observation']['source_cursor']='2';
        file_put_contents($file,json_encode($record)."\n");
        $reject(static fn()=>AdvertisingVisitJournalReader::read($file,$scope,$initial,$now),'advertising_visit_journal_offset','stored cursor must equal physical row offset');
        $record=json_decode($one,true);$record['initData']='must-not-enter';
        file_put_contents($file,json_encode($record)."\n");
        $reject(static fn()=>AdvertisingVisitJournalReader::read($file,$scope,$initial,$now),'advertising_visit_journal_record','unknown envelope fields cannot enter storage');
        file_put_contents($file,str_repeat('x',4097)."\n");
        $reject(static fn()=>AdvertisingVisitJournalReader::read($file,$scope,$initial,$now),'advertising_visit_journal_incomplete','journal lines are bounded before JSON decoding');
        file_put_contents($file,"{bad json}\n");
        $reject(static fn()=>AdvertisingVisitJournalReader::read($file,$scope,$initial,$now),'advertising_visit_journal_record','invalid JSON fails without exposing source text');
    } finally { @unlink($file); }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    advertisingVisitFixtureSchema($pdo);
    $passed = 0;
    advertisingVisitAssertions($pdo, static function(string $name, bool $ok) use (&$passed): void {
        if (!$ok) throw new RuntimeException('FAIL '.$name);
        $passed++; echo 'PASS '.$name.PHP_EOL;
    });
    $scope = advertisingVisitScope();
    $args = [PHP_BINARY,dirname(__DIR__).'/tools/advertising_visits.php','validate'];
    foreach ($scope as $key=>$value) $args[] = '--'.$key.'='.$value;
    $p = proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($p)) throw new RuntimeException('cli_fixture_failed');
    fwrite($pipes[0],json_encode(advertisingVisitPage([advertisingVisitRow('1')])));fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $code=proc_close($p);
    $decoded=json_decode($out,true);
    if ($code!==0 || $error!=='' || $decoded!==['status'=>'validated','observations'=>1,'database_writes'=>false] || str_contains($out,'184467')) throw new RuntimeException('cli_validation_boundary_failed');
    echo 'PASS CLI validates without database configuration or private IDs in output'.PHP_EOL;
    $file=tempnam(sys_get_temp_dir(),'advertising-journal-cli-');
    if($file===false)throw new RuntimeException('cli_journal_fixture_failed');
    try {
        chmod($file,0600);file_put_contents($file,advertisingVisitJournalFixture($scope,1));
        $args[2]='journal-validate';$args[]='--journal='.$file;
        $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($p))throw new RuntimeException('cli_journal_fixture_failed');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
        $decoded=json_decode($out,true);
        if($code!==0||$error!==''||$decoded!==['status'=>'validated','observations'=>1,'database_writes'=>false,'cursor'=>'1','has_more'=>false]||str_contains($out,'184467')||str_contains($out,$file))throw new RuntimeException('cli_journal_validation_boundary_failed');
        echo 'PASS CLI journal validation needs no bootstrap and reveals no private data'.PHP_EOL;
        file_put_contents($file,"{bad json}\n");
        $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($p))throw new RuntimeException('cli_journal_fixture_failed');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
        if($code!==1||$out!==''||json_decode($error,true)!==['status'=>'failed','error'=>'advertising_visit_journal_record']||str_contains($error,$file))throw new RuntimeException('cli_journal_error_boundary_failed');
        echo 'PASS CLI journal errors reveal only a fixed enum'.PHP_EOL;
    } finally { @unlink($file); }
    echo 'ADVERTISING VISITS REGRESSION PASSED '.($passed+3).PHP_EOL;
}
