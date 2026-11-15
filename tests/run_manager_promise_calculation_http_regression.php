<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/support/manager-promise-calculation-fixture.php';
require_once dirname(__DIR__).'/services/ManagerPromiseCalculationService.php';

// Execute the real endpoint, session/CSRF and SQL boundaries. No send endpoint
// or provider is available in this isolated web root.
if (!function_exists('proc_open') || !function_exists('stream_socket_server') || !is_file(PHP_BINARY)) {
    throw new RuntimeException('native_php_http_runtime_required');
}
$directory = sys_get_temp_dir().'/manager-promise-calculation-'.bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) throw new RuntimeException('fixture_directory_failed');
$process = null; $log = null; $passed = 0;

function calculationHttpCheck(string $name, bool $ok, mixed $detail = null): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name.($detail === null ? '' : ' '.json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    ++$passed; echo 'PASS '.$name."\n";
}

function calculationHttpFixture(string $directory): array
{
    $root = dirname(__DIR__); $database = $directory.'/fixture.sqlite';
    $pdo = promiseCalculationFixture($database);
    $pdo->exec("INSERT INTO projects VALUES(2,'appleservice','Other synthetic project',1);
INSERT INTO managers VALUES(91,'foreign-admin','Other project administrator','admin','',1,1),(92,'inactive','Inactive manager','manager','',0,1);
INSERT INTO manager_projects VALUES(91,2),(92,1);");
    $web = $directory.'/web'; mkdir($web.'/manager/lib', 0700, true); mkdir($directory.'/sessions', 0700);
    foreach (['manager/promise-calculations-api.php', 'manager/lib/ManagerHttp.php'] as $file) {
        if (!copy($root.'/'.$file, $web.'/'.$file)) throw new RuntimeException('fixture_copy_failed');
    }
    if (!symlink($root.'/services', $web.'/services')) throw new RuntimeException('fixture_service_link_failed');
    $config = "<?php\nrequire_once ".var_export($root.'/services/ManagerPromiseCalculationService.php', true).";\n";
    $config .= '$pdo=new PDO('.var_export('sqlite:'.$database, true).",null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);\n";
    $config .= '$pdo->exec(\'PRAGMA foreign_keys=ON\');'."\n";
    $config .= '$pdo->sqliteCreateFunction(\'UTC_TIMESTAMP\',static fn():string=>\'2026-11-13 12:00:00\');'."\n";
    $config .= '$pdo->sqliteCreateFunction(\'NOW\',static fn():string=>gmdate(\'Y-m-d H:i:s\'));'."\n";
    $config .= '(new ReflectionProperty(ConversationDb::class,\'pdo\'))->setValue(null,$pdo);'."\n";
    $config .= 'foreach([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class)(new ReflectionProperty($class,\'schemaReady\'))->setValue(null,true);'."\n";
    $config .= "ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'AnyTour']]);\n";
    if (file_put_contents($web.'/config.php', $config) === false) throw new RuntimeException('fixture_config_failed');
    session_save_path($directory.'/sessions'); session_name('anytour_manager_panel'); $sessions = [];
    foreach ([7, 8, 90, 91, 92] as $actor) {
        $sid = bin2hex(random_bytes(16)); session_id($sid); session_start();
        $_SESSION = ['manager_id'=>$actor, 'csrf'=>'fixture-calculation-csrf'];
        session_write_close(); $sessions[(string)$actor] = $sid;
    }
    return ['root'=>$root, 'webroot'=>$web, 'database'=>$database, 'sessions'=>$directory.'/sessions',
        'session_ids'=>$sessions, 'ids'=>promiseCalculationIds($pdo)];
}

function calculationHttpRequest(string $address, array $fixture, ?int $actor, array $input, string $method = 'POST'): array
{
    $headers = ['Content-Type: application/json'];
    if ($actor !== null) $headers[] = 'Cookie: anytour_manager_panel='.$fixture['session_ids'][(string)$actor];
    $options = ['method'=>$method, 'header'=>implode("\r\n", $headers), 'content'=>json_encode($input, JSON_THROW_ON_ERROR), 'ignore_errors'=>true, 'timeout'=>5];
    $body = file_get_contents('http://'.$address.'/manager/promise-calculations-api.php', false, stream_context_create(['http'=>$options]));
    $status = 0;
    foreach ($http_response_header ?? [] as $header) if (preg_match('/^HTTP\/\S+ (\d+)/', $header, $match)) $status = (int)$match[1];
    return [$status, json_decode((string)$body, true), (string)$body];
}

function calculationHttpBusiness(PDO $pdo): array
{
    $result = [];
    foreach (['conversations', 'lead_tasks', 'manager_task_promises', 'manager_task_promise_operations',
        'manager_request_profiles', 'messages', 'manager_conversation_reads', 'conversation_events'] as $table) {
        $result[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
}

function calculationHttpCounts(PDO $pdo): array
{
    return [(int)$pdo->query('SELECT COUNT(*) FROM manager_promise_calculation_intents')->fetchColumn(),
        (int)$pdo->query('SELECT COUNT(*) FROM manager_promise_calculation_operations')->fetchColumn()];
}

try {
    $fixture = calculationHttpFixture($directory);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$listener) throw new RuntimeException('fixture_listener_failed');
    $address = stream_socket_get_name($listener, false); fclose($listener);
    $log = fopen($directory.'/server.log', 'w');
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path='.$fixture['sessions'], '-S', $address, '-t', $fixture['webroot']],
        [0=>['pipe', 'r'], 1=>$log, 2=>$log], $pipes);
    if (!is_resource($process)) throw new RuntimeException('fixture_server_failed');
    fclose($pipes[0]); $ready = false;
    for ($i = 0; $i < 100; ++$i) {
        if ($socket = @stream_socket_client('tcp://'.$address, $e, $s, .05)) { fclose($socket); $ready = true; break; }
        usleep(20000);
    }
    if (!$ready) throw new RuntimeException('fixture_server_not_ready');
    $db = new PDO('sqlite:'.$fixture['database'], null, null,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $before = calculationHttpBusiness($db);
    $common = ['csrf'=>'fixture-calculation-csrf', 'conversation_id'=>1, 'expected_manager_id'=>7];
    $catalog = $common + ['action'=>'catalog'];
    $composition = ['text'=>'Расчёт для синтетической поездки: 179 000 ₽, перелёт и трансфер включены.', 'files'=>[]];
    $preview = $common + ['action'=>'preview', ...$fixture['ids'], 'composition'=>$composition];
    calculationHttpCheck('HTTP fixture serves the byte-exact production endpoint',
        hash_file('sha256', $fixture['root'].'/manager/promise-calculations-api.php') === hash_file('sha256', $fixture['webroot'].'/manager/promise-calculations-api.php'));
    [$status] = calculationHttpRequest($address, $fixture, null, $catalog, 'GET');
    calculationHttpCheck('private calculation endpoint is POST-only', $status === 405);
    [$status, $r] = calculationHttpRequest($address, $fixture, null, $preview);
    calculationHttpCheck('anonymous request cannot read task or promise', $status === 401, $r);
    [$status, $r] = calculationHttpRequest($address, $fixture, 92, array_replace($preview, ['expected_manager_id'=>92]));
    calculationHttpCheck('inactive manager session is refused', $status === 401, $r);
    [$status, $r] = calculationHttpRequest($address, $fixture, 7, array_replace($preview, ['csrf'=>'wrong']));
    calculationHttpCheck('read-only preview still requires current CSRF', $status === 403, $r);
    [$status, $r] = calculationHttpRequest($address, $fixture, 91, array_replace($preview, ['expected_manager_id'=>91]));
    calculationHttpCheck('foreign-project administrator cannot read the calculation', $status === 403, $r);
    foreach (['7', 8, null] as $wrongActor) {
        [$status, $r] = calculationHttpRequest($address, $fixture, 7, array_replace($preview, ['expected_manager_id'=>$wrongActor]));
        calculationHttpCheck('actor pin rejects mismatch or coercion '.json_encode($wrongActor), $status === 409 && empty($r['ok']), $r);
    }
    foreach ([['conversation_id'=>'1'], ['task_id'=>'101'], ['promise_id'=>true], ['extra'=>true],
        ['composition'=>['text'=>'', 'files'=>[]]], ['composition'=>['text'=>'Расчёт', 'files'=>[], 'complete'=>true]]] as $changes) {
        [$status, $r] = calculationHttpRequest($address, $fixture, 7, array_replace($preview, $changes));
        calculationHttpCheck('preview rejects malformed or extended command '.json_encode($changes), $status === 422 && empty($r['ok']), $r);
    }
    [$status, $r] = calculationHttpRequest($address, $fixture, 7, $catalog);
    calculationHttpCheck('catalog carries current actor and conversation', $status === 200 && $r['viewer_id'] === 7 && $r['conversation_id'] === 1, $r);
    [$status, $view] = calculationHttpRequest($address, $fixture, 7, $preview);
    calculationHttpCheck('preview shows source, task revision and complete text composition',
        $status === 200 && $view['can_arm'] === true && $view['task']['id'] === 101
        && $view['task']['business_revision'] === 1 && $view['source']['id'] === 12
        && count($view['parts']) === 1 && $view['composition'] === $composition, $view);
    [$status, $unicode] = calculationHttpRequest($address, $fixture, 7, array_replace($preview,
        ['composition'=>['text'=>"Расчёт\u{2028}179000 ₽", 'files'=>[]]]));
    // Independently calculated by native Node SHA-256(JSON.stringify(composition)).
    // PHP's default Unicode escaping would reject this valid copied calculation.
    calculationHttpCheck('Unicode line separators retain the browser manifest identity', $status === 200
        && $unicode['manifest_sha256'] === '7cfbcf5fecb42742192c99f4ab4caa66b896312ff62334562f6795bcc2195992', $unicode);
    calculationHttpCheck('reads create no intent or canonical business mutation', calculationHttpCounts($db) === [0, 0] && calculationHttpBusiness($db) === $before);
    $arm = array_replace($preview, ['action'=>'arm', 'operation_id'=>bin2hex(random_bytes(16)), 'preview_sha256'=>$view['preview_sha256']]);
    [$status, $r] = calculationHttpRequest($address, $fixture, 8, array_replace($arm, ['expected_manager_id'=>8]));
    calculationHttpCheck('visible colleague cannot arm another managers send', $status === 403, $r);
    [$status, $r] = calculationHttpRequest($address, $fixture, 7, array_replace($arm, ['preview_sha256'=>str_repeat('f', 64)]));
    calculationHttpCheck('stale preview cannot arm the task', $status === 409 && $r['error'] === 'preview_changed', $r);
    [$status, $r] = calculationHttpRequest($address, $fixture, 7, $arm + ['completed'=>true]);
    calculationHttpCheck('browser cannot supply a completion flag', $status === 422 && empty($r['ok']), $r);
    calculationHttpCheck('rejected arm requests preserve every business row', calculationHttpCounts($db) === [0, 0] && calculationHttpBusiness($db) === $before);
    [$status, $armed] = calculationHttpRequest($address, $fixture, 7, $arm);
    $intent = $armed['intent']['intent_id'] ?? '';
    calculationHttpCheck('explicit arm records one immutable intent without sending', $status === 200 && $armed['replayed'] === false
        && $armed['intent']['state'] === 'armed' && $armed['intent']['task_id'] === 101 && calculationHttpCounts($db) === [1, 1], $armed);
    calculationHttpCheck('arming preserves open task, transcript and read markers', calculationHttpBusiness($db) === $before);
    [$status, $r] = calculationHttpRequest($address, $fixture, 7, $arm);
    calculationHttpCheck('lost arm acknowledgement replays the same intent', $status === 200 && $r['replayed'] === true
        && $r['intent']['intent_id'] === $intent && calculationHttpCounts($db) === [1, 1], $r);
    [$status, $r] = calculationHttpRequest($address, $fixture, 7, array_replace($arm, ['composition'=>['text'=>'Изменённый расчёт', 'files'=>[]]]));
    calculationHttpCheck('pending operation cannot be repurposed for different text', $status === 409 && $r['error'] === 'operation_mismatch', $r);
    $operation = $common + ['action'=>'operation', 'operation_id'=>$arm['operation_id']];
    [$status, $r] = calculationHttpRequest($address, $fixture, 7, $operation);
    calculationHttpCheck('operation lookup returns actual saved arm', $status === 200 && $r['found'] === true
        && $r['action'] === 'arm' && $r['intent']['intent_id'] === $intent, $r);
    [$status, $r] = calculationHttpRequest($address, $fixture, 7, array_replace($operation, ['operation_id'=>str_repeat('e', 32)]));
    calculationHttpCheck('unknown operation lookup is explicit read-only absence', $status === 200 && $r['found'] === false, $r);
    $statusRequest = $common + ['action'=>'status', 'intent_id'=>$intent];
    [$status, $r] = calculationHttpRequest($address, $fixture, 7, $statusRequest);
    calculationHttpCheck('status reports zero confirmed parts before dispatch', $status === 200
        && $r['intent']['state'] === 'armed' && $r['intent']['confirmed'] === 0, $r);
    [$status, $r] = calculationHttpRequest($address, $fixture, 7, $statusRequest + ['external_message_id'=>'invented-native-id']);
    calculationHttpCheck('status cannot accept caller-supplied native proof', $status === 422 && empty($r['ok']), $r);
    $recover = array_replace($statusRequest, ['action'=>'recover']);
    [$status, $r] = calculationHttpRequest($address, $fixture, 7, $recover);
    calculationHttpCheck('explicit recovery cannot manufacture missing sending evidence', ($r['intent']['state'] ?? '') !== 'completed'
        && calculationHttpBusiness($db) === $before, $r);
    $cancel = $common + ['action'=>'cancel', 'intent_id'=>$intent, 'operation_id'=>bin2hex(random_bytes(16))];
    [$status, $r] = calculationHttpRequest($address, $fixture, 7, $cancel);
    calculationHttpCheck('cancellation disarms the intent and preserves the task', $status === 200
        && $r['intent']['state'] === 'cancelled' && calculationHttpCounts($db) === [1, 2] && calculationHttpBusiness($db) === $before, $r);
    [$status, $r] = calculationHttpRequest($address, $fixture, 7, $cancel);
    calculationHttpCheck('cancellation replay does not create another receipt', $status === 200 && $r['replayed'] === true && calculationHttpCounts($db) === [1, 2], $r);
    $db->exec('DELETE FROM manager_projects WHERE manager_id=7');
    foreach (['catalog'=>$catalog, 'preview'=>$preview, 'arm replay'=>$arm, 'cancel replay'=>$cancel,
        'operation'=>$operation, 'status'=>$statusRequest, 'recover'=>$recover] as $name=>$request) {
        [$status, $r, $body] = calculationHttpRequest($address, $fixture, 7, $request);
        calculationHttpCheck('fresh revoked access blocks '.$name.' without private evidence', $status === 403
            && !str_contains($body, 'Пришлю') && !str_contains($body, 'Отправить согласован'), $r);
    }
    calculationHttpCheck('revoked reads and writes preserve receipts and canonical rows', calculationHttpCounts($db) === [1, 2]
        && calculationHttpBusiness($db) === $before);
    echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
} catch (Throwable $error) {
    if (is_file($directory.'/server.log')) fwrite(STDERR, substr((string)file_get_contents($directory.'/server.log'), -5000));
    throw $error;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (is_resource($log)) fclose($log);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isLink() || !$file->isDir()) unlink($file->getPathname()); else rmdir($file->getPathname()); }
    rmdir($directory);
}
