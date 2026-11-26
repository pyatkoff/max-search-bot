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
    publisherCheck('saved identity enables only the installed scoped publisher API', $state['configured'] && $state['identity_verified']
        && $state['username'] === 'ANYTOURBOT_bot' && $state['bot_id'] === '123456' && $state['version'] === 1
        && $state['publication_api_ready'] === true && $state['webhook_unchanged'] === true && !$pdo->inTransaction());
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

    // The publisher capability never exports a token and never falls back to
    // the global runtime bot. Every provider response below is synthetic.
    $publisherCalls = [];
    $publisherHook = null;
    $native = static function ($candidate, $method, $path, $query, $body) use ($token, &$publisherCalls, &$publisherHook): array {
        if ($candidate !== $token) throw new LogicException('wrong_synthetic_publisher_credential');
        $publisherCalls[] = [$method, $path, $query, $body];
        $result = match ($path) {
            'getMe'=>['id'=>123456, 'username'=>'ANYTOURBOT_bot', 'is_bot'=>true],
            'getChatMember'=>['user'=>['id'=>123456, 'username'=>'ANYTOURBOT_bot', 'is_bot'=>true], 'status'=>'administrator', 'can_edit_messages'=>true],
            'getChat'=>['id'=>-1001845835645, 'type'=>'channel', 'username'=>'anytour'],
            'editMessageReplyMarkup'=>['message_id'=>1678, 'chat'=>['id'=>-1001845835645, 'type'=>'channel'], 'reply_markup'=>$body['reply_markup']],
            default=>throw new LogicException('unexpected_synthetic_publisher_method'),
        };
        if ($publisherHook) $result = $publisherHook($path, $result);
        return ['http'=>200, 'data'=>['ok'=>true, 'result'=>$result], 'transport_error'=>false];
    };
    $publisher = new AnyTourPublisherBotConnection($tmp.'/vault', $reader, $native);
    $readPublisher = static function (array $binding, callable $request): array {
        $results = [];
        $results[] = $request('telegram', 'POST', 'getMe', [], []);
        $results[] = $request('telegram', 'POST', 'getChatMember', [], ['chat_id'=>'-1001845835645', 'user_id'=>$binding['bot_id']]);
        $results[] = $request('telegram', 'POST', 'getChat', [], ['chat_id'=>'-1001845835645']);
        return ['binding'=>$binding, 'http'=>array_column($results, 'http')];
    };
    $button = ['chat_id'=>'-1001845835645', 'message_id'=>'1678', 'reply_markup'=>['inline_keyboard'=>[[
        ['text'=>'Рассчитать на мои даты', 'url'=>'https://t.me/anyru_bot?start=p_'.str_repeat('S', 24)],
    ]]]];
    $leaseBefore = publisherFiles($tmp.'/vault');
    foreach ([0, 2, 3] as $actor) publisherRefuses('forbidden', fn()=>$publisher->withPublisher($actor, 1, $readPublisher));
    publisherRefuses('context_changed', fn()=>$publisher->withPublisher(1, 0, $readPublisher));
    publisherRefuses('context_changed', fn()=>$publisher->withPublisher(1, 2, $readPublisher));
    publisherRefuses('publisher_not_configured', fn()=>(new AnyTourPublisherBotConnection($tmp.'/not-connected', $reader, $native))->withPublisher(1, 1, $readPublisher));
    $pdo->beginTransaction();
    publisherRefuses('storage_unavailable', fn()=>$publisher->withPublisher(1, 1, $readPublisher));
    publisherCheck('publisher refuses an inherited transaction without ending it', $pdo->inTransaction());
    $pdo->rollBack();
    publisherCheck('publisher admission failures have no provider or storage effect', $publisherCalls === []
        && publisherFiles($tmp.'/vault') === $leaseBefore && !file_exists($tmp.'/not-connected'));
    $inspection = $publisher->withPublisher(1, 1, $readPublisher);
    publisherCheck('publisher reads use the saved identity and exact target without credential changes',
        $inspection === ['binding'=>['username'=>'ANYTOURBOT_bot', 'bot_id'=>'123456', 'credential_version'=>1], 'http'=>[200, 200, 200]]
        && array_column($publisherCalls, 1) === ['getMe', 'getChatMember', 'getChat']
        && publisherFiles($tmp.'/vault') === $leaseBefore && !str_contains(json_encode($inspection), $token));
    $publisherCalls = [];
    publisherRefuses('publisher_operation_forbidden', fn()=>$publisher->withPublisher(1, 1,
        static fn($binding, $request)=>$request('telegram', 'POST', 'editMessageReplyMarkup', [], $button)));
    publisherRefuses('publisher_verification_required', fn()=>$publisher->withPublisher(1, 1,
        static fn($binding, $request)=>$request('telegram', 'POST', 'editMessageReplyMarkup', [], $button), true));
    $invalidRequests = [
        ['telegram', 'POST', 'setWebhook', [], []],
        ['telegram', 'POST', 'getUpdates', [], []],
        ['telegram', 'POST', 'editMessageCaption', [], $button],
        ['telegram', 'POST', 'sendMessage', [], $button],
        ['max', 'POST', 'getMe', [], []],
        ['telegram', 'GET', 'getMe', [], []],
        ['telegram', 'POST', 'getMe', ['extra'=>'parameter'], []],
        ['telegram', 'POST', 'getChat', [], ['chat_id'=>'-1001845835646']],
        ['telegram', 'POST', 'getChatMember', [], ['chat_id'=>'-1001845835645', 'user_id'=>234567]],
        ['telegram', 'POST', 'editMessageReplyMarkup', [], array_replace($button, ['message_id'=>'1207'])],
        ['telegram', 'POST', 'editMessageReplyMarkup', [], $button + ['caption'=>'changed']],
        ['telegram', 'POST', 'editMessageReplyMarkup', [], array_replace_recursive($button, ['reply_markup'=>['inline_keyboard'=>[[['url'=>'https://t.me/ANYTOURBOT_bot?start=p_'.str_repeat('S', 24)]]]]])],
        ['telegram', 'POST', 'editMessageReplyMarkup', [], array_replace_recursive($button, ['reply_markup'=>['inline_keyboard'=>[[['url'=>'https://t.me/anyru_bot?start=p_'.str_repeat('S', 23)]]]]])],
        ['telegram', 'POST', 'editMessageReplyMarkup', [], array_replace_recursive($button, ['reply_markup'=>['inline_keyboard'=>[[1=>['text'=>'extra', 'url'=>'https://example.invalid/']]]]])],
        ['telegram', 'POST', 'editMessageReplyMarkup', [], array_replace_recursive($button, ['reply_markup'=>['inline_keyboard'=>[[['callback_data'=>'extra']]]]])],
    ];
    $publisher->withPublisher(1, 1, static function ($binding, $request) use ($invalidRequests): array {
        foreach ($invalidRequests as $arguments) publisherRefuses('publisher_operation_forbidden', fn()=>$request(...$arguments));
        return [];
    }, true);
    publisherCheck('capability refuses all unrelated targets methods and keyboard shapes before provider access', $publisherCalls === []);
    foreach (['getMe', 'getChatMember', 'getChat'] as $wrongNative) {
        $publisherHook = static function ($path, $result) use ($wrongNative): array {
            if ($path !== $wrongNative) return $result;
            if ($path === 'getMe') $result['id'] = 234567;
            elseif ($path === 'getChatMember') $result['can_edit_messages'] = false;
            else $result['username'] = 'another_channel';
            return $result;
        };
        publisherRefuses('publisher_verification_required', fn()=>$publisher->withPublisher(1, 1,
            static function ($binding, $request) use ($readPublisher, $button): array {
                $readPublisher($binding, $request);
                return $request('telegram', 'POST', 'editMessageReplyMarkup', [], $button);
            }, true));
    }
    publisherCheck('fresh bot rights and channel proofs are each required for an edit', !in_array('editMessageReplyMarkup', array_column($publisherCalls, 1), true));
    $publisherHook = null;
    $publisherCalls = [];
    $edited = $publisher->withPublisher(1, 1, static function ($binding, $request) use ($readPublisher, $button): array {
        $readPublisher($binding, $request);
        $result = $request('telegram', 'POST', 'editMessageReplyMarkup', [], $button);
        publisherRefuses('publisher_operation_forbidden', fn()=>$request('telegram', 'POST', 'editMessageReplyMarkup', [], $button));
        return $result;
    }, true);
    publisherCheck('an explicit publisher lease allows exactly one keyboard-only attempt for1678',
        array_column($publisherCalls, 1) === ['getMe', 'getChatMember', 'getChat', 'editMessageReplyMarkup']
        && $publisherCalls[3][3] === $button && ($edited['data']['result']['message_id'] ?? null) === 1678);
    $publisherHook = static function ($path, $result) use ($token): array {
        if ($path === 'editMessageReplyMarkup') throw new RuntimeException('Synthetic transport secret '.$token);
        return $result;
    };
    $publisherCalls = [];
    $uncertain = $publisher->withPublisher(1, 1, static function ($binding, $request) use ($readPublisher, $button): array {
        $readPublisher($binding, $request);
        $result = $request('telegram', 'POST', 'editMessageReplyMarkup', [], $button);
        publisherRefuses('publisher_operation_forbidden', fn()=>$request('telegram', 'POST', 'editMessageReplyMarkup', [], $button));
        return $result;
    }, true);
    publisherCheck('uncertain provider attempt is sanitized and cannot repeat within the lease',
        $uncertain === ['http'=>0, 'data'=>null, 'transport_error'=>true]
        && count(array_filter(array_column($publisherCalls, 1), static fn($path)=>$path === 'editMessageReplyMarkup')) === 1
        && !str_contains(json_encode($uncertain), $token));
    $publisherHook = static function ($path, $result) use ($pdo): array {
        if ($path === 'getMe') $pdo->exec('DELETE FROM manager_projects WHERE manager_id=1 AND project_id=1');
        return $result;
    };
    $publisherCalls = [];
    publisherRefuses('forbidden', fn()=>$publisher->withPublisher(1, 1, $readPublisher));
    publisherCheck('revoked project access stops before the next provider request', array_column($publisherCalls, 1) === ['getMe']);
    $pdo->exec('INSERT INTO manager_projects VALUES(1,1)');
    $publisherHook = null;
    publisherRefuses('forbidden', fn()=>$publisher->withPublisher(1, 1, static function () use ($pdo): array {
        $pdo->exec('DELETE FROM manager_projects WHERE manager_id=1 AND project_id=1');
        return ['must_not_escape'=>true];
    }));
    $pdo->exec('INSERT INTO manager_projects VALUES(1,1)');
    $registryPreimage = (string)file_get_contents($tmp.'/vault/connection.json');
    $publisherHook = static function ($path, $result) use ($tmp, $registryPreimage): array {
        if ($path === 'getMe') {
            $changed = json_decode($registryPreimage, true, 512, JSON_THROW_ON_ERROR);
            $changed['version'] = 2;
            file_put_contents($tmp.'/vault/connection.json', json_encode($changed, JSON_THROW_ON_ERROR));
        }
        return $result;
    };
    $publisherCalls = [];
    publisherRefuses('context_changed', fn()=>$publisher->withPublisher(1, 1, $readPublisher));
    publisherCheck('credential version is checked again before every provider request', array_column($publisherCalls, 1) === ['getMe']);
    file_put_contents($tmp.'/vault/connection.json', $registryPreimage);
    $publisherHook = null;
    publisherRefuses('context_changed', fn()=>$publisher->withPublisher(1, 1, static function () use ($tmp, $registryPreimage): array {
        $changed = json_decode($registryPreimage, true, 512, JSON_THROW_ON_ERROR);
        $changed['version'] = 2;
        file_put_contents($tmp.'/vault/connection.json', json_encode($changed, JSON_THROW_ON_ERROR));
        return ['must_not_escape'=>true];
    }));
    file_put_contents($tmp.'/vault/connection.json', $registryPreimage);
    $escaped = null;
    $publisher->withPublisher(1, 1, static function ($binding, $request) use (&$escaped): array { $escaped = $request; return []; });
    publisherRefuses('publisher_scope_closed', fn()=>$escaped('telegram', 'POST', 'getMe', [], []));
    publisherRefuses('publisher_invalid_result', fn()=>$publisher->withPublisher(1, 1, static fn($binding, $request)=>['request'=>$request]));
    publisherRefuses('publisher_invalid_result', fn()=>$publisher->withPublisher(1, 1, static fn()=>['secret'=>$token]));
    $publisherHook = static function ($path, $result) use ($token): array { $result['unexpected'] = $token; return $result; };
    $sanitized = $publisher->withPublisher(1, 1, static fn($binding, $request)=>$request('telegram', 'POST', 'getMe', [], []));
    publisherCheck('a secret-bearing provider response cannot cross the callback boundary', $sanitized === ['http'=>0, 'data'=>null, 'transport_error'=>true]);
    $publisherHook = null;
    publisherCheck('publisher use leaves the credential version and encrypted record unchanged', $connection->status(1)['version'] === 1
        && (string)file_get_contents($tmp.'/vault/connection.json') === $registryPreimage && !$pdo->inTransaction());

    // The 1047 pilot has its own transport and an immutable request, not a
    // caller-selectable target for the legacy Aloft capability. No network I/O.
    $pilotCalls = [];
    $pilotHook = null;
    $pilotNative = static function ($candidate, $method, $path, $query, $body) use ($token, &$pilotCalls, &$pilotHook): array {
        if ($candidate !== $token) throw new LogicException('wrong_synthetic_pilot_credential');
        $pilotCalls[] = [$method, $path, $query, $body];
        $result = match ($path) {
            'getMe'=>['id'=>123456, 'username'=>'ANYTOURBOT_bot', 'is_bot'=>true],
            'getChatMember'=>['user'=>['id'=>123456, 'username'=>'ANYTOURBOT_bot', 'is_bot'=>true], 'status'=>'administrator', 'can_edit_messages'=>true],
            'getChat'=>['id'=>-1001845835645, 'type'=>'channel', 'username'=>'anytour'],
            'editMessageReplyMarkup'=>['message_id'=>1047, 'chat'=>['id'=>-1001845835645, 'type'=>'channel'], 'reply_markup'=>$body['reply_markup']],
            default=>throw new LogicException('unexpected_synthetic_pilot_method'),
        };
        if ($pilotHook) $result = $pilotHook($path, $result);
        return ['http'=>200, 'data'=>['ok'=>true, 'result'=>$result], 'transport_error'=>false];
    };
    $pilot = new AnyTourPublisherBotConnection($tmp.'/vault', $reader, $native, $pilotNative);
    $pilotBody = ['chat_id'=>'-1001845835645', 'message_id'=>'1047', 'reply_markup'=>['inline_keyboard'=>[
        [['text'=>'Подробнее об отеле', 'url'=>'https://example.invalid/lusso?hotel=old']],
        [['text'=>'Связаться', 'url'=>'https://t.me/ANYTOURBOT_bot?start=existing_lusso']],
        [['text'=>'Другие даты', 'url'=>'https://example.invalid/lusso/dates#existing']],
    ]]];
    publisherCheck('pilot accepts three existing URL buttons without requiring the transfer CTA',
        ChannelPostApiTransport::publisherKeyboardPilotPayload($pilotBody) === $pilotBody);
    $invalidPilotBodies = [
        array_replace($pilotBody, ['message_id'=>'1678']),
        array_replace($pilotBody, ['message_id'=>'1048']),
        array_replace($pilotBody, ['chat_id'=>'-1001845835646']),
        $pilotBody + ['caption'=>'must not change'],
        $pilotBody + ['media'=>['type'=>'photo', 'media'=>'must_not_send']],
        $pilotBody + ['business_connection_id'=>'must_not_send'],
        array_replace($pilotBody, ['reply_markup'=>['inline_keyboard'=>[]]]),
        array_replace($pilotBody, ['reply_markup'=>['inline_keyboard'=>array_fill(0, 4, $pilotBody['reply_markup']['inline_keyboard'][0])]]),
        array_replace($pilotBody, ['reply_markup'=>['inline_keyboard'=>[array_fill(0, 4, $pilotBody['reply_markup']['inline_keyboard'][0][0])]]]),
        array_replace_recursive($pilotBody, ['reply_markup'=>['inline_keyboard'=>[[['url'=>'http://example.invalid/lusso']]]]]),
        array_replace_recursive($pilotBody, ['reply_markup'=>['inline_keyboard'=>[[['url'=>'https://user:password@example.invalid/lusso']]]]]),
        array_replace_recursive($pilotBody, ['reply_markup'=>['inline_keyboard'=>[[['url'=>"https://example.invalid/\r\nother"]]]]]),
        array_replace_recursive($pilotBody, ['reply_markup'=>['inline_keyboard'=>[[['text'=>"line\nbreak"]]]]]),
        array_replace_recursive($pilotBody, ['reply_markup'=>['inline_keyboard'=>[[['text'=>"\xff"]]]]]),
        array_replace_recursive($pilotBody, ['reply_markup'=>['inline_keyboard'=>[[['callback_data'=>'different_action']]]]]),
        array_replace_recursive($pilotBody, ['reply_markup'=>['inline_keyboard'=>[[['web_app'=>['url'=>'https://example.invalid/']]]]]]),
    ];
    $pilotVaultBefore = publisherFiles($tmp.'/vault');
    $legacyBeforePilot = count($publisherCalls);
    foreach ($invalidPilotBodies as $invalidBody) {
        publisherCheck('pilot transport payload refuses an unrelated or unsupported request',
            ChannelPostApiTransport::publisherKeyboardPilotPayload($invalidBody) === null);
        publisherRefuses('publisher_operation_forbidden', fn()=>$pilot->withPublisherKeyboardPilot(1, 1, $invalidBody, $readPublisher, true));
    }
    foreach ([0, 2, 3] as $actor)
        publisherRefuses('forbidden', fn()=>$pilot->withPublisherKeyboardPilot($actor, 1, $pilotBody, $readPublisher));
    publisherRefuses('context_changed', fn()=>$pilot->withPublisherKeyboardPilot(1, 2, $pilotBody, $readPublisher));
    publisherRefuses('publisher_operation_forbidden', fn()=>$publisher->withPublisher(1, 1,
        static fn($binding, $request)=>$request('telegram', 'POST', 'editMessageReplyMarkup', [], $pilotBody), true));
    publisherCheck('pilot admission leaves both transports and credentials untouched', $pilotCalls === []
        && count($publisherCalls) === $legacyBeforePilot && publisherFiles($tmp.'/vault') === $pilotVaultBefore);

    publisherRefuses('publisher_verification_required', fn()=>$pilot->withPublisherKeyboardPilot(1, 1, $pilotBody,
        static fn($binding, $request)=>$request('telegram', 'POST', 'editMessageReplyMarkup', [], $pilotBody), true));
    publisherRefuses('publisher_operation_forbidden', fn()=>$pilot->withPublisherKeyboardPilot(1, 1, $pilotBody,
        static function ($binding, $request) use ($readPublisher, $pilotBody): array {
            $readPublisher($binding, $request);
            return $request('telegram', 'POST', 'editMessageReplyMarkup', [], $pilotBody);
        }));
    foreach (['getMe', 'getChatMember', 'getChat'] as $wrongNative) {
        $pilotHook = static function ($path, $result) use ($wrongNative): array {
            if ($path === $wrongNative) {
                if ($path === 'getMe') $result['username'] = 'anyru_bot';
                elseif ($path === 'getChatMember') $result['can_edit_messages'] = false;
                else $result['id'] = -1001845835646;
            }
            return $result;
        };
        publisherRefuses('publisher_verification_required', fn()=>$pilot->withPublisherKeyboardPilot(1, 1, $pilotBody,
            static function ($binding, $request) use ($readPublisher, $pilotBody): array {
                $readPublisher($binding, $request);
                return $request('telegram', 'POST', 'editMessageReplyMarkup', [], $pilotBody);
            }, true));
    }
    publisherCheck('pilot requires its own fresh identity rights and channel before writing',
        !in_array('editMessageReplyMarkup', array_column($pilotCalls, 1), true));
    $pilotHook = null;
    $pilotCalls = [];
    $editedPilot = $pilot->withPublisherKeyboardPilot(1, 1, $pilotBody,
        static function ($binding, $request) use ($readPublisher, $pilotBody, $button, $invalidRequests): array {
            $readPublisher($binding, $request);
            foreach ($invalidRequests as $arguments)
                publisherRefuses('publisher_operation_forbidden', fn()=>$request(...$arguments));
            foreach ([$button,
                array_replace_recursive($pilotBody, ['reply_markup'=>['inline_keyboard'=>[[['url'=>'https://example.invalid/another-hotel']]]]]),
                array_replace_recursive($pilotBody, ['reply_markup'=>['inline_keyboard'=>[[['text'=>'unapproved label']]]]]),
            ] as $changedBody)
                publisherRefuses('publisher_operation_forbidden', fn()=>$request('telegram', 'POST', 'editMessageReplyMarkup', [], $changedBody));
            $response = $request('telegram', 'POST', 'editMessageReplyMarkup', [], $pilotBody);
            publisherRefuses('publisher_operation_forbidden', fn()=>$request('telegram', 'POST', 'editMessageReplyMarkup', [], $pilotBody));
            return $response;
        }, true);
    publisherCheck('pilot sends exactly the frozen keyboard once through its separate transport',
        array_column($pilotCalls, 1) === ['getMe', 'getChatMember', 'getChat', 'editMessageReplyMarkup']
        && $pilotCalls[3][3] === $pilotBody && $editedPilot['data']['result']['message_id'] === 1047
        && count($publisherCalls) === $legacyBeforePilot);

    $referencedBody = $pilotBody;
    $mutableUrl = $pilotBody['reply_markup']['inline_keyboard'][0][0]['url'];
    $referencedBody['reply_markup']['inline_keyboard'][0][0]['url'] = &$mutableUrl;
    $pilotCalls = [];
    $pilot->withPublisherKeyboardPilot(1, 1, $referencedBody,
        static function ($binding, $request) use ($readPublisher, $pilotBody, $referencedBody, &$mutableUrl): array {
            $readPublisher($binding, $request);
            $mutableUrl = 'https://example.invalid/substituted-after-admission';
            publisherRefuses('publisher_operation_forbidden', fn()=>$request('telegram', 'POST', 'editMessageReplyMarkup', [], $referencedBody));
            return $request('telegram', 'POST', 'editMessageReplyMarkup', [], $pilotBody);
        }, true);
    publisherCheck('caller references cannot mutate the admitted keyboard during a lease', $pilotCalls[3][3] === $pilotBody);

    $pilotHook = static function ($path, $result) use ($token): array {
        if ($path === 'editMessageReplyMarkup') throw new RuntimeException('Synthetic uncertain pilot '.$token);
        return $result;
    };
    $pilotCalls = [];
    $uncertainPilot = $pilot->withPublisherKeyboardPilot(1, 1, $pilotBody,
        static function ($binding, $request) use ($readPublisher, $pilotBody, $button): array {
            $readPublisher($binding, $request);
            $response = $request('telegram', 'POST', 'editMessageReplyMarkup', [], $pilotBody);
            publisherRefuses('publisher_operation_forbidden', fn()=>$request('telegram', 'POST', 'editMessageReplyMarkup', [], $pilotBody));
            publisherRefuses('publisher_operation_forbidden', fn()=>$request('telegram', 'POST', 'editMessageReplyMarkup', [], $button));
            return $response;
        }, true);
    publisherCheck('unknown pilot outcome cannot retry or fall back to the legacy transport',
        $uncertainPilot === ['http'=>0, 'data'=>null, 'transport_error'=>true]
        && array_column($pilotCalls, 1) === ['getMe', 'getChatMember', 'getChat', 'editMessageReplyMarkup']
        && count($publisherCalls) === $legacyBeforePilot);
    $pilotHook = static function ($path, $result) use ($tmp, $registryPreimage): array {
        if ($path === 'getMe') {
            $changed = json_decode($registryPreimage, true, 512, JSON_THROW_ON_ERROR);
            $changed['version'] = 2;
            file_put_contents($tmp.'/vault/connection.json', json_encode($changed, JSON_THROW_ON_ERROR));
        }
        return $result;
    };
    $pilotCalls = [];
    publisherRefuses('context_changed', fn()=>$pilot->withPublisherKeyboardPilot(1, 1, $pilotBody, $readPublisher, true));
    publisherCheck('pilot credential rotation stops before the next provider request', array_column($pilotCalls, 1) === ['getMe']);
    file_put_contents($tmp.'/vault/connection.json', $registryPreimage);
    $pilotHook = null;
    $escapedPilot = null;
    $pilot->withPublisherKeyboardPilot(1, 1, $pilotBody,
        static function ($binding, $request) use (&$escapedPilot): array { $escapedPilot = $request; return []; });
    publisherRefuses('publisher_scope_closed', fn()=>$escapedPilot('telegram', 'POST', 'editMessageReplyMarkup', [], $pilotBody));
    publisherRefuses('publisher_invalid_result', fn()=>$pilot->withPublisherKeyboardPilot(1, 1, $pilotBody,
        static fn($binding, $request)=>['request'=>$request]));
    publisherCheck('pilot leaves stored credentials and the registry transaction state unchanged',
        (string)file_get_contents($tmp.'/vault/connection.json') === $registryPreimage
        && $connection->status(1)['version'] === 1 && !$pdo->inTransaction());

    // The separately authorized media pilot retains the same credential lease.
    // Both ordinary markup capabilities remain outside this exact media scope.
    $images = require __DIR__.'/fixtures/channel_post_media_images.php';
    $mediaBody = ['chat_id'=>'-1001845835645', 'message_id'=>'1058',
        'media'=>['type'=>'photo', 'media'=>'attach://hotel_photo',
            'caption'=>"Amwaj Rotana Jumeirah Beach\nПроверенная подпись гостиницы.", 'caption_entities'=>[]],
        'reply_markup'=>['inline_keyboard'=>[[['text'=>'✈️ Рассчитать на мои даты',
            'url'=>'https://t.me/anyru_bot?start=p_'.str_repeat('S', 24)]]]],
        'photo'=>['base64'=>$images['image/jpeg'], 'sha256'=>hash('sha256', base64_decode($images['image/jpeg'], true))]];
    $mediaCalls = [];
    $mediaHook = null;
    $mediaNative = static function ($candidate, $method, $path, $query, $body) use ($token, &$mediaCalls, &$mediaHook): array {
        if ($candidate !== $token) throw new LogicException('wrong_synthetic_media_credential');
        $mediaCalls[] = [$method, $path, $query, $body];
        $result = match ($path) {
            'getMe'=>['id'=>123456, 'username'=>'ANYTOURBOT_bot', 'is_bot'=>true],
            'getChatMember'=>['user'=>['id'=>123456, 'username'=>'ANYTOURBOT_bot', 'is_bot'=>true], 'status'=>'administrator', 'can_edit_messages'=>true],
            'getChat'=>['id'=>-1001845835645, 'type'=>'channel', 'username'=>'anytour'],
            'editMessageMedia'=>['message_id'=>1058, 'chat'=>['id'=>-1001845835645, 'type'=>'channel'],
                'caption'=>$body['media']['caption'], 'reply_markup'=>$body['reply_markup']],
            default=>throw new LogicException('unexpected_synthetic_media_method'),
        };
        if ($mediaHook) $result = $mediaHook($path, $result);
        return ['http'=>200, 'data'=>['ok'=>true, 'result'=>$result], 'transport_error'=>false];
    };
    $media = new AnyTourPublisherBotConnection($tmp.'/vault', $reader, $native, $pilotNative, $mediaNative);
    $legacyBeforeMedia = count($publisherCalls);
    $keyboardBeforeMedia = count($pilotCalls);
    $mediaVaultBefore = publisherFiles($tmp.'/vault');
    publisherCheck('media admission accepts exact JPEG bytes and a canonical hotel CTA',
        ChannelPostApiTransport::publisherMediaPilotPayload($mediaBody) === $mediaBody);
    foreach ([
        array_replace($mediaBody, ['message_id'=>'1047']),
        array_replace($mediaBody, ['message_id'=>'1678']),
        array_replace($mediaBody, ['chat_id'=>'-1001845835646']),
        array_replace_recursive($mediaBody, ['media'=>['media'=>'another_bot_file_id']]),
        array_replace_recursive($mediaBody, ['media'=>['media'=>'https://example.invalid/hotel.jpg']]),
        array_replace_recursive($mediaBody, ['photo'=>['sha256'=>str_repeat('0', 64)]]),
        array_replace_recursive($mediaBody, ['photo'=>['base64'=>'not-base64']]),
        array_replace_recursive($mediaBody, ['photo'=>['path'=>'/tmp/unapproved.jpg']]),
        $mediaBody + ['business_connection_id'=>'unapproved'],
    ] as $invalidBody) {
        publisherCheck('media transport rejects another target or unverifiable photo before credentials',
            ChannelPostApiTransport::publisherMediaPilotPayload($invalidBody) === null);
        publisherRefuses('publisher_operation_forbidden', fn()=>$media->withPublisherMediaPilot(1, 1, $invalidBody, $readPublisher, true));
    }
    foreach ([0, 2, 3] as $actor)
        publisherRefuses('forbidden', fn()=>$media->withPublisherMediaPilot($actor, 1, $mediaBody, $readPublisher));
    publisherRefuses('context_changed', fn()=>$media->withPublisherMediaPilot(1, 2, $mediaBody, $readPublisher));
    publisherRefuses('publisher_operation_forbidden', fn()=>$publisher->withPublisher(1, 1,
        static fn($binding, $request)=>$request('telegram', 'POST', 'editMessageMedia', [], $mediaBody), true));
    publisherCheck('invalid media admission cannot touch any transport or credential record', $mediaCalls === []
        && count($publisherCalls) === $legacyBeforeMedia && count($pilotCalls) === $keyboardBeforeMedia
        && publisherFiles($tmp.'/vault') === $mediaVaultBefore);
    publisherRefuses('publisher_verification_required', fn()=>$media->withPublisherMediaPilot(1, 1, $mediaBody,
        static fn($binding, $request)=>$request('telegram', 'POST', 'editMessageMedia', [], $mediaBody), true));
    publisherRefuses('publisher_operation_forbidden', fn()=>$media->withPublisherMediaPilot(1, 1, $mediaBody,
        static function ($binding, $request) use ($readPublisher, $mediaBody): array {
            $readPublisher($binding, $request);
            return $request('telegram', 'POST', 'editMessageMedia', [], $mediaBody);
        }));
    foreach (['getMe', 'getChatMember', 'getChat'] as $wrongNative) {
        $mediaHook = static function ($path, $result) use ($wrongNative): array {
            if ($path === $wrongNative) {
                if ($path === 'getMe') $result['username'] = 'anyru_bot';
                elseif ($path === 'getChatMember') $result['can_edit_messages'] = false;
                else $result['id'] = -1001845835646;
            }
            return $result;
        };
        publisherRefuses('publisher_verification_required', fn()=>$media->withPublisherMediaPilot(1, 1, $mediaBody,
            static function ($binding, $request) use ($readPublisher, $mediaBody): array {
                $readPublisher($binding, $request);
                return $request('telegram', 'POST', 'editMessageMedia', [], $mediaBody);
            }, true));
    }
    publisherCheck('media cannot be written until this lease verifies all three native prerequisites',
        !in_array('editMessageMedia', array_column($mediaCalls, 1), true));
    $mediaHook = null;
    $mediaCalls = [];
    $mediaReply = $media->withPublisherMediaPilot(1, 1, $mediaBody,
        static function ($binding, $request) use ($readPublisher, $mediaBody, $pilotBody, $button): array {
            $readPublisher($binding, $request);
            foreach ([
                ['telegram', 'POST', 'sendPhoto', [], $mediaBody],
                ['telegram', 'POST', 'editMessageCaption', [], $mediaBody],
                ['telegram', 'POST', 'editMessageReplyMarkup', [], $pilotBody],
                ['telegram', 'POST', 'editMessageReplyMarkup', [], $button],
                ['telegram', 'GET', 'editMessageMedia', [], $mediaBody],
                ['telegram', 'POST', 'editMessageMedia', ['extra'=>'query'], $mediaBody],
                ['max', 'POST', 'editMessageMedia', [], $mediaBody],
                ['telegram', 'POST', 'editMessageMedia', [], array_replace_recursive($mediaBody, ['media'=>['caption'=>'unapproved']])],
            ] as $arguments) publisherRefuses('publisher_operation_forbidden', fn()=>$request(...$arguments));
            $response = $request('telegram', 'POST', 'editMessageMedia', [], $mediaBody);
            publisherRefuses('publisher_operation_forbidden', fn()=>$request('telegram', 'POST', 'editMessageMedia', [], $mediaBody));
            return $response;
        }, true);
    publisherCheck('media lease sends only one frozen multipart request through the saved publisher transport',
        array_column($mediaCalls, 1) === ['getMe', 'getChatMember', 'getChat', 'editMessageMedia']
        && $mediaCalls[3][3] === $mediaBody && $mediaReply['data']['result']['message_id'] === 1058
        && count($publisherCalls) === $legacyBeforeMedia && count($pilotCalls) === $keyboardBeforeMedia);

    $referencedMedia = $mediaBody;
    $mutablePhoto = $mediaBody['photo']['base64'];
    $referencedMedia['photo']['base64'] = &$mutablePhoto;
    $mediaCalls = [];
    $media->withPublisherMediaPilot(1, 1, $referencedMedia,
        static function ($binding, $request) use ($readPublisher, $mediaBody, $referencedMedia, &$mutablePhoto): array {
            $readPublisher($binding, $request);
            $mutablePhoto = base64_encode('substituted after admission');
            publisherRefuses('publisher_operation_forbidden', fn()=>$request('telegram', 'POST', 'editMessageMedia', [], $referencedMedia));
            return $request('telegram', 'POST', 'editMessageMedia', [], $mediaBody);
        }, true);
    publisherCheck('photo references cannot substitute bytes during the credential lease', $mediaCalls[3][3] === $mediaBody);
    $mediaHook = static function ($path, $result) use ($token): array {
        if ($path === 'editMessageMedia') throw new RuntimeException('Synthetic unknown media result '.$token);
        return $result;
    };
    $mediaCalls = [];
    $unknownMedia = $media->withPublisherMediaPilot(1, 1, $mediaBody,
        static function ($binding, $request) use ($readPublisher, $mediaBody): array {
            $readPublisher($binding, $request);
            $response = $request('telegram', 'POST', 'editMessageMedia', [], $mediaBody);
            publisherRefuses('publisher_operation_forbidden', fn()=>$request('telegram', 'POST', 'editMessageMedia', [], $mediaBody));
            return $response;
        }, true);
    publisherCheck('an unknown media attempt stays consumed and does not leak provider exceptions',
        $unknownMedia === ['http'=>0, 'data'=>null, 'transport_error'=>true]
        && array_column($mediaCalls, 1) === ['getMe', 'getChatMember', 'getChat', 'editMessageMedia']);
    $mediaHook = static function ($path, $result) use ($tmp, $registryPreimage): array {
        if ($path === 'getMe') {
            $changed = json_decode($registryPreimage, true, 512, JSON_THROW_ON_ERROR);
            $changed['version'] = 2;
            file_put_contents($tmp.'/vault/connection.json', json_encode($changed, JSON_THROW_ON_ERROR));
        }
        return $result;
    };
    $mediaCalls = [];
    publisherRefuses('context_changed', fn()=>$media->withPublisherMediaPilot(1, 1, $mediaBody, $readPublisher, true));
    publisherCheck('media credential-version drift stops before the following provider request', array_column($mediaCalls, 1) === ['getMe']);
    file_put_contents($tmp.'/vault/connection.json', $registryPreimage);
    $mediaHook = static function ($path, $result) use ($pdo): array {
        if ($path === 'getMe') $pdo->exec('DELETE FROM manager_projects WHERE manager_id=1 AND project_id=1');
        return $result;
    };
    $mediaCalls = [];
    publisherRefuses('forbidden', fn()=>$media->withPublisherMediaPilot(1, 1, $mediaBody, $readPublisher, true));
    publisherCheck('revoked project access stops the next request inside an admitted media lease', array_column($mediaCalls, 1) === ['getMe']);
    $pdo->exec('INSERT INTO manager_projects VALUES(1,1)');
    $mediaHook = null;
    $escapedMedia = null;
    $media->withPublisherMediaPilot(1, 1, $mediaBody,
        static function ($binding, $request) use (&$escapedMedia): array { $escapedMedia = $request; return []; });
    publisherRefuses('publisher_scope_closed', fn()=>$escapedMedia('telegram', 'POST', 'editMessageMedia', [], $mediaBody));
    publisherRefuses('publisher_invalid_result', fn()=>$media->withPublisherMediaPilot(1, 1, $mediaBody, static fn()=>['secret'=>$token]));
    publisherCheck('media capability leaves encrypted credentials and transaction state unchanged',
        (string)file_get_contents($tmp.'/vault/connection.json') === $registryPreimage
        && $connection->status(1)['version'] === 1 && !$pdo->inTransaction());

    $connection->save(1, 1, $next);
    publisherCheck('explicit rotation advances exactly one version', $connection->status(1)['version'] === 2);
    $transactionPreimage = hash_file('sha256', $tmp.'/vault/connection.json');
    $pdo->beginTransaction();
    publisherRefuses('storage_unavailable', fn()=>$connection->save(1, 2, $next));
    publisherCheck('an inherited transaction is neither committed nor rolled back', $pdo->inTransaction()
        && $connection->status(1)['version'] === 2 && hash_file('sha256', $tmp.'/vault/connection.json') === $transactionPreimage);
    $pdo->rollBack();

    $connection->withPublisher(1, 2, static function () use ($tmp, $connection, &$workers): array {
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
        // Both saves have passed identity verification and can now attempt the
        // real lock. Their credential CAS must wait until this lease ends.
        usleep(100000);
        publisherCheck('publisher callback serializes with actual concurrent credential rotation', $connection->status(1)['version'] === 2
            && proc_get_status($workers['a'][0])['running'] && proc_get_status($workers['b'][0])['running']);
        return [];
    });
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
    $beforePublisherCalls = count($publisherCalls);
    publisherRefuses('credential_store_unavailable', fn()=>$connection->status(1));
    publisherRefuses('credential_store_unavailable', fn()=>$connection->save(1, 3, $next));
    publisherRefuses('credential_store_unavailable', fn()=>$publisher->withPublisher(1, 3, $readPublisher));
    publisherCheck('lost live key cannot be silently replaced', !file_exists($tmp.'/vault/master.key')
        && (string)file_get_contents($tmp.'/vault/connection.json') === $before && count($calls) === $beforeCalls
        && count($publisherCalls) === $beforePublisherCalls);

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
    symlink($source.'/integrations', $httpRoot.'/integrations');
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
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path='.$httpRoot.'/sessions', '-d', 'disable_functions=curl_exec', '-S', $address, '-t', $httpRoot],
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
    $operationForm = ['actor'=>'1', 'csrf'=>'synthetic-publisher-csrf', 'version'=>'0', 'action'=>'inspect'];
    publisherCheck('native actions retain session actor and CSRF checks', $request(1, array_replace($operationForm, ['csrf'=>'wrong']))[0] === 403
        && $request(1, array_replace($operationForm, ['actor'=>'3']))[0] === 409);
    publisherCheck('native action cannot accept arbitrary methods tokens or targets', $request(1, array_replace($operationForm, ['action'=>['inspect']]))[0] === 422
        && $request(1, $operationForm + ['token'=>$invalid])[0] === 422
        && $request(1, $operationForm + ['chat_id'=>'-1001845835645', 'message_id'=>'1207'])[0] === 422
        && $request(1, array_replace($operationForm, ['action'=>'prepare']) + ['button_url'=>'https://example.invalid/'])[0] === 422);
    $applyForm = array_replace($operationForm, ['action'=>'apply', 'publication_id'=>'1', 'version_id'=>'1', 'plan_digest'=>str_repeat('a', 64)]);
    publisherCheck('apply requires a separate exact confirmation and well-formed plan identity',
        $request(1, $applyForm)[0] === 422
        && $request(1, $applyForm + ['confirm'=>'yes'])[0] === 422
        && $request(1, $applyForm + ['confirm'=>['replace_aloft_1678_keyboard']])[0] === 422
        && $request(1, array_replace($applyForm, ['plan_digest'=>'invalid']) + ['confirm'=>'replace_aloft_1678_keyboard'])[0] === 422
        && $request(1, array_replace($applyForm, ['publication_id'=>'0']) + ['confirm'=>'replace_aloft_1678_keyboard'])[0] === 422);
    publisherCheck('unconfigured native action is refused before provider use or storage creation',
        $request(1, $operationForm)[0] === 422 && $request(1, array_replace($operationForm, ['action'=>'prepare']))[0] === 422
        && !file_exists(dirname($httpVault)));
    $httpConnection = new AnyTourPublisherBotConnection($httpVault, $reader);
    $httpConnection->save(1, 0, $token);
    $before = publisherFiles($httpVault);
    [$http, $html] = $request(1);
    publisherCheck('saved HTTP form exposes explicit inspection and preparation without performing them', $http === 200
        && str_contains($html, 'Версия 1') && str_contains($html, 'МСК')
        && str_contains($html, 'value="inspect"') && str_contains($html, 'value="prepare"')
        && !str_contains($html, $token) && publisherFiles($httpVault) === $before);
    publisherCheck('native actions reject stale credential versions before invoking their callback', $request(1, $operationForm)[0] === 409);
    publisherCheck('credential setup creates no routing or client connections', (int)$pdo->query('SELECT COUNT(*) FROM conversation_sources')->fetchColumn() === 0
        && (int)$pdo->query('SELECT COUNT(*) FROM project_client_connections')->fetchColumn() === 0);

    // Seed only this disposable DB. The real HTTP endpoint remains query-only,
    // with provider execution disabled, while rendering two distinct versions.
    require_once __DIR__.'/fixtures/channel_post_registry_fixture.php';
    require_once $source.'/services/ChannelPostRegistryService.php';
    require_once $source.'/integrations/ChannelPostIncomingAdapter.php';
    createChannelPostRegistryFixture($pdo);
    $pdo->exec("INSERT INTO conversation_sources(id,project_id,source_key,display_name,channel,handling_mode,is_active) VALUES(9,1,'tg_anytour_msk','Synthetic Telegram','telegram','manager',1)");
    $postRegistry = new ChannelPostRegistryService($pdo);
    $postChannel = $postRegistry->configureChannel(['project_key'=>'anytour', 'platform'=>'telegram', 'bot_key'=>'anyru_bot',
        'channel_id'=>'-1001845835645', 'source_key'=>'tg_anytour_msk', 'display_name'=>'Synthetic AnyTour',
        'bot_url'=>'https://t.me/anyru_bot', 'button_text'=>'Рассчитать на мои даты', 'is_enabled'=>true]);
    $caption = 'Aloft Palm Jumeirah <script>synthetic-html-escape</script>';
    $publicSnapshot = ['text'=>$caption, 'title'=>'Aloft Palm Jumeirah', 'format'=>'public_html',
        'post_url'=>'https://t.me/anytour/1678', 'published_at'=>'2026-08-11 11:51:56', 'edited_at'=>'', 'media_group_id'=>'',
        'attachments'=>[['type'=>'video', 'provider'=>'telegram', 'duration'=>28, 'public_media_path'=>'/file/test1678.mp4']],
        'markup'=>[], 'raw_body'=>[], 'keyboard'=>[[['text'=>'Old button', 'url'=>'https://example.invalid/old']]],
        'metadata'=>['capability'=>'requires_attention', 'capability_reason'=>'manual_approval_required', 'observation_kind'=>'public_read',
            'actual_message_id'=>'1678', 'channel_id'=>'-1001845835645', 'hotel_catalog_id'=>61159]];
    $preparedPost = $postRegistry->prepareManualTelegramButton($postChannel, $publicSnapshot,
        ['username'=>'ANYTOURBOT_bot', 'bot_id'=>'123456', 'credential_version'=>1]);
    $postClaim = $postRegistry->claimManualTelegramButton($preparedPost, $preparedPost['plan_digest']);
    $postRegistry->markExternalWriteStarted($postClaim['publication_id'], $postClaim['version_id'], $postClaim['claim_token']);
    $postRegistry->finishPublication($postClaim['publication_id'], $postClaim['version_id'], $postClaim['claim_token'],
        'unknown', 'manual_edit_verification_failed', ['button_verified'=>true, 'public_caption_matched'=>false,
            'body_unchanged_by_method'=>true, 'native_media_identity_before_verified'=>false,
            'native_video_unique_id'=>'PRIVATE_RECEIPT_VIDEO_ID', 'provider_result_sha256'=>str_repeat('a', 64),
            'http_results'=>[['operation'=>'append_button', 'http'=>200, 'transport_error'=>0],
                ['operation'=>'PRIVATE_UNKNOWN_STAGE', 'http'=>200, 'transport_error'=>0]]]);
    $nativeSnapshot = ChannelPostIncomingAdapter::telegramSnapshot(['message_id'=>1678,
        'chat'=>['id'=>-1001845835645, 'type'=>'channel', 'username'=>'anytour'], 'date'=>1786449116,
        'edit_date'=>1786449117, 'caption'=>$caption,
        'video'=>['duration'=>27.75, 'file_id'=>'PRIVATE_NATIVE_FILE_ID', 'file_unique_id'=>'PRIVATE_NATIVE_UNIQUE_ID'],
        'reply_markup'=>['inline_keyboard'=>[[['text'=>'Рассчитать на мои даты', 'url'=>'https://t.me/anyru_bot?start='.$preparedPost['start_payload'],
            'callback_data'=>'PRIVATE_CALLBACK_VALUE', 'PRIVATE_BUTTON_KEY'=>'PRIVATE_BUTTON_VALUE']]]]]);
    $currentPost = $postRegistry->registerPublication('anytour', 'telegram', 'anyru_bot', '-1001845835645', '1678', $nativeSnapshot);
    $currentReceipt = $currentPost['receipt'] + ['button_verified'=>false, 'public_caption_matched'=>true, 'publication_date_matched'=>true];
    $pdo->prepare('UPDATE post_versions SET receipt_json=? WHERE id=?')->execute([json_encode($currentReceipt, JSON_THROW_ON_ERROR), $currentPost['version_id']]);
    $databaseBeforeGet = hash_file('sha256', $httpRoot.'/fixture.sqlite');
    [$http, $html] = $request(1);
    preg_match('~<details><summary>Сохранённая квитанция исходной попытки</summary>(.*?)</details>~s', $html, $originalBlock);
    preg_match('~<details><summary>Диагностика текущей нативной версии</summary>(.*?)</details>~s', $html, $currentBlock);
    $originalHtml = $originalBlock[1] ?? '';
    $currentHtml = $currentBlock[1] ?? '';
    publisherCheck('read-only HTTP diagnostics separate original receipt from the later native version', $http === 200
        && $preparedPost['version_id'] !== $currentPost['version_id']
        && str_contains($originalHtml, 'Версия '.$preparedPost['version_id'].'.')
        && str_contains($originalHtml, 'manual_edit_verification_failed')
        && str_contains($currentHtml, 'Версия '.$currentPost['version_id'].'.')
        && str_contains($originalHtml, 'Сообщение и точная кнопка совпали</dt><dd>Да</dd>')
        && str_contains($originalHtml, 'Подпись совпала с исходным публичным снимком</dt><dd>Нет</dd>')
        && str_contains($originalHtml, 'Исходная дата совпала точно</dt><dd>Нет данных</dd>')
        && str_contains($originalHtml, 'Итоговая нативная сверка пройдена</dt><dd>Нет данных</dd>'));
    publisherCheck('HTTP diagnostics expose bounded native duration and keyboard shape without private values',
        str_contains($currentHtml, 'Длительность видео, секунд</dt><dd>27.75 · тип double')
        && str_contains($currentHtml, 'text, url, callback_data; всего: 4; иных полей: 1')
        && str_contains($originalHtml, 'Этап <code>append_button</code>: HTTP 200')
        && !str_contains($html, 'PRIVATE_') && !str_contains($html, $token));
    publisherCheck('HTTP diagnostics escape stored text and cannot reoffer a completed attempt',
        str_contains($html, '&lt;script&gt;synthetic-html-escape&lt;/script&gt;')
        && !str_contains($html, '<script>synthetic-html-escape</script>')
        && !str_contains($html, 'value="apply"')
        && str_contains($html, 'Гостиничный контекст текущей версии требует повторной сверки.'));
    publisherCheck('diagnostic GET preserves private credentials and exact database bytes',
        hash_file('sha256', $httpRoot.'/fixture.sqlite') === $databaseBeforeGet && publisherFiles($httpVault) === $before);
    $pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');
    publisherCheck('deactivated session cannot read the saved connection', $request(1)[0] === 401);
    publisherCheck('no secret appears in HTTP output or diagnostic logs', !str_contains(implode('', $bodies), $token)
        && !str_contains(implode('', $bodies), $invalid)
        && !str_contains((string)file_get_contents($tmp.'/server.log'), $invalid)
        && !is_file($tmp.'/fixture.log'));
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
