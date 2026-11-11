<?php
declare(strict_types=1);

define('APPLESERVICE39_PUBLISHER_FIXTURES_ONLY', true);
require_once dirname(__DIR__).'/run_appleservice39_publisher_regression.php';

function as39NativeConnection(): PDO
{
    $dsn = (string)getenv('APPLESERVICE39_TEST_MYSQL_DSN');
    if ($dsn === '' || !str_contains($dsn, 'dbname=appleservice39_publisher_test')) throw new RuntimeException('Dedicated native test database required');
    $pdo = new PDO($dsn, (string)getenv('APPLESERVICE39_TEST_MYSQL_USER'), (string)getenv('APPLESERVICE39_TEST_MYSQL_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'appleservice39_publisher_test') throw new RuntimeException('Wrong native test database');
    return $pdo;
}

if (($argv[1] ?? '') === '--claim-worker') {
    $directory = $argv[2] ?? '';
    if (!is_dir($directory) || !str_starts_with($directory, sys_get_temp_dir().'/as39-claim-')) throw new RuntimeException('Invalid barrier directory');
    $pdo = as39NativeConnection(); $store = new AppleService39PilotPublicationStore($pdo);
    $operation = $argv[3] ?? '';
    if (!in_array($operation, ['create','landing_upload','landing_edit'], true)) throw new RuntimeException('Invalid native claim operation');
    $row = $store->operation('concurrent', $operation);
    if (!$row) throw new RuntimeException('Missing native claim fixture');
    file_put_contents($directory.'/ready-'.getmypid(), 'ready');
    $deadline = microtime(true)+10;
    while (!is_file($directory.'/go') && microtime(true)<$deadline) usleep(10000);
    if (!is_file($directory.'/go')) throw new RuntimeException('Claim barrier timeout');
    $claimed = $store->claim($row);
    file_put_contents($directory.'/result-'.getmypid(), $claimed ? '1' : '0');
    exit(0);
}

$pdo = as39NativeConnection();
foreach (['appleservice39_pilot_operations','appleservice39_pilot_campaigns','manager_projects','conversation_sources','managers','projects'] as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
as39Schema($pdo);
as39ReviewedLandingRegression($pdo);
foreach (['appleservice39_pilot_operations','appleservice39_pilot_campaigns','manager_projects','conversation_sources','managers','projects'] as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
as39Schema($pdo);
as39FullPublicationRegression($pdo);
$pdo->exec('INSERT INTO manager_projects VALUES (2,1)');
$store = new AppleService39PilotPublicationStore($pdo);
foreach (['create','landing_upload','landing_edit'] as $claimOperation) {
    $row = $store->prepare('concurrent', $claimOperation, ['body'=>['text'=>'Synthetic concurrent one-attempt fixture']]);
    $directory = sys_get_temp_dir().'/as39-claim-'.bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create native barrier');
    register_shutdown_function(static function () use ($directory): void {
        foreach (glob($directory.'/*') ?: [] as $path) @unlink($path);
        @rmdir($directory);
    });
    $workers = [];
    for ($i=0; $i<2; $i++) {
        $pipes = [];
        $worker = proc_open([PHP_BINARY,__FILE__,'--claim-worker',$directory,$claimOperation], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
        if (!is_resource($worker)) throw new RuntimeException('Cannot create native claim worker');
        fclose($pipes[0]); $workers[] = [$worker,$pipes];
    }
    $deadline = microtime(true)+10;
    while (count(glob($directory.'/ready-*') ?: [])<2 && microtime(true)<$deadline) usleep(10000);
    as39Expect(count(glob($directory.'/ready-*') ?: [])===2, 'two independent native connections observed the same prepared operation');
    file_put_contents($directory.'/go', 'go');
    foreach ($workers as [$worker,$pipes]) {
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($worker)!==0) throw new RuntimeException('Native claim worker failed: '.$output.$errors);
    }
    $results = array_map(static fn(string $path): string=>trim((string)file_get_contents($path)), glob($directory.'/result-*') ?: []);
    sort($results);
    as39Expect($results===['0','1'], 'simultaneous native claims admit exactly one provider attempt');
    $fresh = new AppleService39PilotPublicationStore(as39NativeConnection());
    $held = $fresh->operation('concurrent',$claimOperation);
    as39Expect($held !== null && $held['state']==='write_started' && (int)$held['write_attempts']===1 && !$fresh->claim($held), 'unrecorded/crashed native attempt remains held on a new connection');
    as39Refuses(static fn()=>$fresh->prepare('concurrent',$claimOperation,['body'=>['text'=>'Changed payload cannot reset the claim']]), 'pilot_operation_changed');
}
$binding = $fresh->campaign(); $binding['manifest_sha256']=str_repeat('f',64); unset($binding['created_at']);
as39Refuses(static fn()=>$fresh->bind($binding), 'campaign_binding_changed');
as39Expect((int)$pdo->query("SELECT write_attempts FROM appleservice39_pilot_operations WHERE post_key='concurrent'")->fetchColumn()===1, 'source/manifest drift never grants another native attempt');
ProjectMaxConnection::resetForTests();
foreach (['appleservice39_pilot_operations','appleservice39_pilot_campaigns','manager_projects','conversation_sources','managers','projects'] as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
as39Schema($pdo);
define('APPLESERVICE39_SEARCH_FIXTURES_ONLY', true);
require_once dirname(__DIR__).'/run_appleservice39_search_publisher_regression.php';
as39SearchRegression($pdo);
ProjectMaxConnection::resetForTests();
echo "AppleService39 native MySQL publisher regression complete\n";
