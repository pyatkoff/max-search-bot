<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
ob_start();
require_once __DIR__.'/fixtures/client_onboarding_fixture.php';
require_once dirname(__DIR__).'/services/AnyTourPublisherBotConnection.php';

function publisherCheck(string $label, bool $ok): void
{
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
}
function publisherRefuses(string $reason, callable $operation): void
{
    try { $operation(); }
    catch (RuntimeException $error) {
        publisherCheck($reason, $error->getMessage() === $reason);
        return;
    }
    throw new RuntimeException('Missing refusal '.$reason);
}
function publisherFiles(string $directory): array
{
    if (!is_dir($directory)) return [];
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $file->getPathname();
        $files[substr($path, strlen($directory))] = [hash_file('sha256', $path), fileperms($path), filemtime($path)];
    }
    ksort($files);
    return $files;
}

// Independent processes read the same version before either finishes getMe.
// Actual file locks and CAS, rather than a mock lock, must select one winner.
if (($argv[1] ?? '') === '--race') {
    $directory = $argv[2];
    $worker = $argv[3];
    $pdo = new ClientFixturePdo('sqlite:'.$directory.'/http/fixture.sqlite');
    clientFixtureBind($pdo, $directory.'/unused-vault');
    $reader = static function ($candidate, $method, $body) use ($directory, $worker): array {
        if ($method !== 'getMe' || $body !== []) throw new RuntimeException('unexpected_provider_method');
        touch($directory.'/ready-'.$worker);
        for ($attempt = 0; $attempt < 500 && !is_file($directory.'/release-workers'); $attempt++) usleep(10000);
        if (!is_file($directory.'/release-workers')) throw new RuntimeException('race_barrier_timeout');
        return ['http'=>200, 'ok'=>true, 'result'=>['id'=>123456, 'username'=>'ANYTOURBOT_bot', 'is_bot'=>true]];
    };
    $connection = new AnyTourPublisherBotConnection($directory.'/vault', $reader);
    try {
        $connection->save(1, 2, '123456:'.str_repeat($worker === 'a' ? 'C' : 'D', 35));
        echo 'saved';
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'context_changed') throw $error;
        echo 'context_changed';
    }
    exit;
}

$source = dirname(__DIR__);
$tmp = realpath(sys_get_temp_dir()).'/anytour-publisher-'.bin2hex(random_bytes(8));
mkdir($tmp, 0700);
$server = null;
$workers = [];
try {
    $httpRoot = $tmp.'/http';
    mkdir($httpRoot, 0700);
    $pdo = new ClientFixturePdo('sqlite:'.$httpRoot.'/fixture.sqlite');
    clientFixtureBind($pdo, $tmp.'/unused-vault');
    clientFixtureSchema($pdo);
    $pdo->prepare('UPDATE projects SET display_name=? WHERE id=1')->execute([ProjectConfig::get('brand.name', ProjectConfig::projectId())]);
    $pdo->exec("INSERT INTO projects(id,project_key,display_name) VALUES(2,'appleservice39','AppleService39')");
    $pdo->exec('DELETE FROM manager_projects WHERE manager_id=3');
    $pdo->exec('INSERT INTO manager_projects VALUES(3,2)');
    DiagnosticLogger::setFile($tmp.'/fixture.log');
    $token = '123456:'.str_repeat('A', 35);
    $next = '123456:'.str_repeat('B', 35);
    $username = 'ANYTOURBOT_bot';
    $calls = [];
    $hook = null;
    $reader = static function ($candidate, $method, $body) use (&$calls, &$username, &$hook): array {
        publisherCheck('provider operation is getMe only', $method === 'getMe' && $body === []);
        $calls[] = $method;
        if ($hook) $hook();
        return ['http'=>200, 'ok'=>true, 'result'=>['id'=>(int)explode(':', $candidate)[0], 'username'=>$username, 'is_bot'=>true]];
    };
    $connection = new AnyTourPublisherBotConnection($tmp.'/vault', $reader);
    $initial = $connection->status(1);
    publisherCheck('initial status is truthful and creates no files', $initial['configured'] === false
        && $initial['version'] === 0 && !$initial['identity_verified'] && !$initial['publication_api_ready']
        && !file_exists($tmp.'/vault') && $calls === []);
    foreach ([0, 2, 3] as $actor) {
        publisherRefuses('forbidden', fn()=>$connection->status($actor));
        publisherRefuses('forbidden', fn()=>$connection->save($actor, 0, $token));
    }
    publisherCheck('unauthorized calls have no provider or storage effect', $calls === [] && !file_exists($tmp.'/vault'));
    publisherRefuses('invalid_token', fn()=>$connection->save(1, 0, $token."\n"));
    publisherRefuses('invalid_token', fn()=>$connection->save(1, 0, 'invalid'));
    publisherCheck('invalid token is rejected before provider reads', $calls === []);
    $username = 'another_bot';
    publisherRefuses('identity_mismatch', fn()=>$connection->save(1, 0, $token));
    publisherCheck('wrong identity leaves no credentials or route', !file_exists($tmp.'/vault')
        && (int)$pdo->query('SELECT COUNT(*) FROM conversation_sources')->fetchColumn() === 0);
    $username = 'ANYTOURBOT_bot';
    $hook = static fn()=>$pdo->exec('DELETE FROM manager_projects WHERE manager_id=1 AND project_id=1');
    publisherRefuses('forbidden', fn()=>$connection->save(1, 0, $token));
    publisherCheck('access revoked during provider read cannot save', !file_exists($tmp.'/vault') && !$pdo->inTransaction());
    $hook = null;
    $pdo->exec('INSERT INTO manager_projects VALUES(1,1)');
    $unavailable = new AnyTourPublisherBotConnection($tmp.'/vault', static function () use ($token): array {
        throw new RuntimeException('Synthetic provider failure containing '.$token);
    });
    try { $unavailable->save(1, 0, $token); throw new LogicException('Expected unavailable provider'); }
    catch (RuntimeException $error) {
        publisherCheck('provider exception is sanitized without a secret-bearing cause', $error->getMessage() === 'identity_unavailable'
            && $error->getPrevious() === null && !str_contains((string)$error, $token));
    }
    $connection->save(1, 0, $token);
    $state = $connection->status(1);
    publisherCheck('saved identity is explicit but publication remains unavailable', $state['configured'] && $state['identity_verified']
        && $state['username'] === 'ANYTOURBOT_bot' && $state['bot_id'] === '123456' && $state['version'] === 1
        && $state['publication_api_ready'] === false && $state['webhook_unchanged'] === true && !$pdo->inTransaction());
    $row = json_decode((string)file_get_contents($tmp.'/vault/connection.json'), true, 512, JSON_THROW_ON_ERROR);
    publisherCheck('saved token is encrypted and bound to this bot', !str_contains(json_encode($row), $token)
        && (new ProjectClientCredentialStore($tmp.'/vault'))->open('anytour', 'telegram:123456', $row['envelope'])['token'] === $token);
    publisherCheck('key registry and lock are private', (fileperms($tmp.'/vault') & 0777) === 0700
        && (fileperms($tmp.'/vault/master.key') & 0777) === 0600
        && (fileperms($tmp.'/vault/connection.json') & 0777) === 0600
        && (fileperms($tmp.'/vault/connection.lock') & 0777) === 0600);
    $before = publisherFiles($tmp.'/vault');
    $beforeCalls = count($calls);
    $connection->status(1);
    publisherRefuses('context_changed', fn()=>$connection->save(1, 0, $next));
    publisherRefuses('identity_mismatch', fn()=>$connection->save(1, 1, '234567:'.str_repeat('B', 35)));
    publisherCheck('status replay and identity substitution cannot write or call provider', publisherFiles($tmp.'/vault') === $before && count($calls) === $beforeCalls);
    $connection->save(1, 1, $next);
    publisherCheck('explicit rotation advances exactly one version', $connection->status(1)['version'] === 2);
    $transactionPreimage = hash_file('sha256', $tmp.'/vault/connection.json');
    $pdo->beginTransaction();
    publisherRefuses('storage_unavailable', fn()=>$connection->save(1, 2, $next));
    publisherCheck('an inherited transaction is neither committed nor rolled back', $pdo->inTransaction()
        && $connection->status(1)['version'] === 2 && hash_file('sha256', $tmp.'/vault/connection.json') === $transactionPreimage);
    $pdo->rollBack();

    foreach (['a', 'b'] as $worker) {
        $process = proc_open([PHP_BINARY, __FILE__, '--race', $tmp, $worker],
            [0=>['pipe', 'r'], 1=>['pipe', 'w'], 2=>['file', $tmp.'/worker-errors.log', 'a']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('cannot_start_race_worker');
        fclose($pipes[0]);
        $workers[$worker] = [$process, $pipes[1]];
    }
    for ($attempt = 0; $attempt < 500 && (!is_file($tmp.'/ready-a') || !is_file($tmp.'/ready-b')); $attempt++) usleep(10000);
    publisherCheck('both concurrent writers reached identity verification for the same version', is_file($tmp.'/ready-a') && is_file($tmp.'/ready-b'));
    touch($tmp.'/release-workers');
    $results = [];
    foreach ($workers as $worker=>[$process, $output]) {
        $results[$worker] = stream_get_contents($output);
        fclose($output);
        publisherCheck('concurrent writer completed without errors', proc_close($process) === 0);
    }
    $workers = [];
    $outcomes = array_values($results);
    sort($outcomes);
    $raceRow = json_decode((string)file_get_contents($tmp.'/vault/connection.json'), true, 512, JSON_THROW_ON_ERROR);
    $winner = $results['a'] === 'saved' ? 'C' : 'D';
    publisherCheck('exactly one concurrent credential save wins', $outcomes === ['context_changed', 'saved']
        && $connection->status(1)['version'] === 3
        && (new ProjectClientCredentialStore($tmp.'/vault'))->open('anytour', 'telegram:123456', $raceRow['envelope'])['token'] === '123456:'.str_repeat($winner, 35));
    publisherCheck('atomic saves leave no temporary records', glob($tmp.'/vault/connection.*.tmp') === []);

    chmod($tmp.'/vault/connection.json', 0644);
    publisherRefuses('storage_unavailable', fn()=>$connection->status(1));
    chmod($tmp.'/vault/connection.json', 0600);
    rename($tmp.'/vault/connection.json', $tmp.'/vault/original.json');
    symlink($tmp.'/vault/original.json', $tmp.'/vault/connection.json');
    publisherRefuses('storage_unavailable', fn()=>$connection->status(1));
    unlink($tmp.'/vault/connection.json');
    rename($tmp.'/vault/original.json', $tmp.'/vault/connection.json');
    $before = (string)file_get_contents($tmp.'/vault/connection.json');
    $invalidDate = json_decode($before, true, 512, JSON_THROW_ON_ERROR);
    $invalidDate['updated_at'] = '2026-13-01T00:00:00+00:00';
    file_put_contents($tmp.'/vault/connection.json', json_encode($invalidDate, JSON_THROW_ON_ERROR));
    publisherRefuses('storage_unavailable', fn()=>$connection->status(1));
    file_put_contents($tmp.'/vault/connection.json', $before);
    unlink($tmp.'/vault/master.key');
    $beforeCalls = count($calls);
    publisherRefuses('credential_store_unavailable', fn()=>$connection->status(1));
    publisherRefuses('credential_store_unavailable', fn()=>$connection->save(1, 3, $next));
    publisherCheck('lost live key cannot be silently replaced', !file_exists($tmp.'/vault/master.key')
        && (string)file_get_contents($tmp.'/vault/connection.json') === $before && count($calls) === $beforeCalls);

    // The exact endpoint uses real session/auth/CSRF owners and a query-only DB.
    // Copy only this service so its default private sibling is also disposable.
    mkdir($httpRoot.'/manager', 0700);
    mkdir($httpRoot.'/sessions', 0700);
    mkdir($httpRoot.'/services', 0700);
    touch($httpRoot.'/synthetic-client-fixture');
    touch($httpRoot.'/read-only');
    foreach (glob($source.'/services/*.php') as $file) {
        $target = $httpRoot.'/services/'.basename($file);
        if (basename($file) === 'AnyTourPublisherBotConnection.php') copy($file, $target);
        else symlink($file, $target);
    }
    symlink($source.'/manager/lib', $httpRoot.'/manager/lib');
    copy($source.'/manager/anytour-publisher-telegram.php', $httpRoot.'/manager/anytour-publisher-telegram.php');
    file_put_contents($httpRoot.'/config.php', '<?php require_once '.var_export(__DIR__.'/fixtures/client_onboarding_http.php', true).';clientHttpBootstrap(__DIR__);');
    publisherCheck('HTTP fixture uses byte-exact endpoint and credential service', hash_file('sha256', $source.'/manager/anytour-publisher-telegram.php') === hash_file('sha256', $httpRoot.'/manager/anytour-publisher-telegram.php')
        && hash_file('sha256', $source.'/services/AnyTourPublisherBotConnection.php') === hash_file('sha256', $httpRoot.'/services/AnyTourPublisherBotConnection.php'));
    session_name('anytour_manager_panel');
    session_save_path($httpRoot.'/sessions');
    $sessions = [];
    foreach ([1, 2, 3] as $actor) {
        $sid = bin2hex(random_bytes(16));
        session_id($sid); session_start();
        $_SESSION = ['manager_id'=>$actor, 'csrf'=>'synthetic-publisher-csrf'];
        session_write_close(); $sessions[$actor] = $sid;
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('loopback_unavailable');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path='.$httpRoot.'/sessions', '-S', $address, '-t', $httpRoot],
        [0=>['pipe', 'r'], 1=>['file', $tmp.'/server.log', 'a'], 2=>['file', $tmp.'/server.log', 'a']], $pipes);
    if (!is_resource($server)) throw new RuntimeException('cannot_start_loopback');
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $probe = @stream_socket_client('tcp://'.$address, $errno, $error, .05);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(10000);
    }
    if (!$ready) throw new RuntimeException('loopback_not_ready');
    $bodies = [];
    $request = static function (int $actor, ?array $form = null, ?string $method = null) use ($address, $sessions, &$bodies): array {
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        if ($actor > 0) $headers[] = 'Cookie: anytour_manager_panel='.$sessions[$actor];
        $options = ['method'=>$method ?? ($form === null ? 'GET' : 'POST'), 'header'=>implode("\r\n", $headers), 'ignore_errors'=>true, 'timeout'=>5, 'follow_location'=>0];
        if ($form !== null) $options['content'] = http_build_query($form);
        $body = (string)file_get_contents('http://'.$address.'/manager/anytour-publisher-telegram.php', false, stream_context_create(['http'=>$options]));
        preg_match('/^HTTP\/\S+\s+(\d+)/', $http_response_header[0] ?? '', $status);
        $bodies[] = $body;
        return [(int)($status[1] ?? 0), $body, $http_response_header];
    };
    publisherCheck('HTTP anonymous employee and foreign administrator are denied', $request(0)[0] === 401 && $request(2)[0] === 403 && $request(3)[0] === 403);
    [$http, $html, $headers] = $request(1);
    $httpVault = $tmp.'/max-search-bot-private-logs/anytour-publisher-credentials';
    publisherCheck('HTTP owner can view disconnected form without storage or database writes', $http === 200
        && str_contains($html, 'type="password"') && str_contains($html, 'synthetic-publisher-csrf')
        && !file_exists(dirname($httpVault)));
    publisherCheck('credential form prevents caching and referrer leakage', in_array('Cache-Control: no-store', $headers, true)
        && in_array('Referrer-Policy: no-referrer', $headers, true));
    $invalid = 'synthetic-invalid-secret-http'; // Invalid shape: no live Telegram request can result.
    $form = ['actor'=>'1', 'csrf'=>'synthetic-publisher-csrf', 'version'=>'0', 'token'=>$invalid];
    publisherCheck('unsupported method is denied', $request(1, null, 'PUT')[0] === 405);
    publisherCheck('missing wrong and array CSRF are denied', $request(1, array_diff_key($form, ['csrf'=>true]))[0] === 403
        && $request(1, array_replace($form, ['csrf'=>'wrong']))[0] === 403
        && $request(1, array_replace($form, ['csrf'=>['unexpected']]))[0] === 403);
    publisherCheck('POST actor is bound to the authenticated session', $request(1, array_replace($form, ['actor'=>'3']))[0] === 409);
    publisherCheck('malformed version token and extra operations are denied', $request(1, array_replace($form, ['version'=>'-1']))[0] === 422
        && $request(1, array_replace($form, ['token'=>['unexpected']]))[0] === 422
        && $request(1, $form + ['action'=>'activate'])[0] === 422);
    publisherCheck('oversized credential request is bounded', $request(1, array_replace($form, ['token'=>str_repeat('A', 5000)]))[0] === 413);
    publisherCheck('invalid credential failure has safe response and no files', $request(1, $form)[0] === 422 && !file_exists(dirname($httpVault)));
    $httpConnection = new AnyTourPublisherBotConnection($httpVault, $reader);
    $httpConnection->save(1, 0, $token);
    $before = publisherFiles($httpVault);
    [$http, $html] = $request(1);
    publisherCheck('saved HTTP form reports version without exposing credentials or enabling publication', $http === 200
        && str_contains($html, 'Версия 1') && str_contains($html, 'МСК') && str_contains($html, 'Редактирование публикаций ещё не подключено.')
        && !str_contains($html, $token) && publisherFiles($httpVault) === $before);
    $pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');
    publisherCheck('deactivated session cannot read the saved connection', $request(1)[0] === 401);
    publisherCheck('no secret appears in HTTP output or diagnostic logs', !str_contains(implode('', $bodies), $token)
        && !str_contains(implode('', $bodies), $invalid)
        && !str_contains((string)file_get_contents($tmp.'/server.log'), $invalid)
        && !is_file($tmp.'/fixture.log'));
    publisherCheck('credential setup creates no routing or client connections', (int)$pdo->query('SELECT COUNT(*) FROM conversation_sources')->fetchColumn() === 0
        && (int)$pdo->query('SELECT COUNT(*) FROM project_client_connections')->fetchColumn() === 0);
    publisherCheck('all provider operations were identity reads', $calls !== [] && array_unique($calls) === ['getMe']);
    echo "AnyTour publisher connection regressions passed\n";
} finally {
    foreach ($workers as [$process, $output]) {
        if (is_resource($output)) fclose($output);
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    }
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isLink() || !$file->isDir()) unlink($file->getPathname());
        else rmdir($file->getPathname());
    }
    rmdir($tmp);
}
