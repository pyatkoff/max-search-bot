<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('OWNER_SELECTION_PREVIEW_OFFLINE_TEST')) define('OWNER_SELECTION_PREVIEW_OFFLINE_TEST', true);
require_once __DIR__.'/owner_tour_selection_preview.php';

/** A separate fixed functional trial; historical visual examples are immutable. */
function ownerChoiceTrialArguments(array $args): ?string
{
    if (!in_array('--confirm-owner-choice', $args, true)) return null;
    return ownerPreviewArguments(array_map(static fn($arg)=>$arg === '--confirm-owner-choice' ? '--confirm-owner-send' : $arg, $args));
}

function ownerChoiceTrialSnapshot(): array
{
    return ['hotel'=>'Delphin Palace 5★', 'departure'=>'Москва', 'date_from'=>'2027-06-05', 'date_to'=>'2027-06-12',
        'nights'=>7, 'adults'=>2, 'child_ages'=>[], 'room'=>'Стандартный номер', 'meal'=>'Ультра всё включено',
        'flights'=>['Демонстрационный перелёт; рейсы не проверены'], 'amount'=>'219600', 'currency'=>'RUB',
        'price_basis'=>'party', 'is_demo'=>true, 'price_verified'=>false,
        'rationale'=>'Проверка сохранения конкретного варианта по кнопке «Нравится».',
        'compromise'=>'Даты, цена и условия демонстрационные; актуальность не проверена.'];
}

function ownerChoiceTrialText(): string
{
    return "Проверка кнопки «Нравится»\nDelphin Palace 5★\n219 600 ₽ за двоих\n5–12 июня 2027 · 7 ночей\nИз Москвы · 2 взрослых\nСтандартный номер · Ультра всё включено\n\nДемо: даты, цена, перелёт и условия — пример, актуальность не проверена.\nНажмите «Нравится»: этот вариант с указанными условиями сохранится и появится у менеджера.";
}

function ownerChoiceTrialCallback($value, int $offerId): bool
{
    return is_string($value) && strlen($value) <= 64
        && preg_match('/\Atour_like_'.preg_quote((string)$offerId, '/').'_[a-f0-9]{24}\z/D', $value) === 1;
}

function ownerChoiceTrialBody(string $channel, string $callback): array
{
    if ($channel === 'telegram') return ['rich_message'=>['blocks'=>[['type'=>'paragraph', 'text'=>ownerChoiceTrialText()]], 'skip_entity_detection'=>true],
        'reply_markup'=>['inline_keyboard'=>[[['text'=>'Нравится', 'callback_data'=>$callback]]]]];
    if ($channel !== 'max') throw new InvalidArgumentException('Unknown trial channel');
    return ['text'=>ownerChoiceTrialText(), 'attachments'=>[['type'=>'inline_keyboard',
        'payload'=>['buttons'=>[[['type'=>'callback', 'text'=>'Нравится', 'payload'=>$callback]]]]]]];
}

function ownerChoiceTrialMessage(?array $response): ?array
{
    $data = ownerPreviewData($response);
    return is_array($data['message'] ?? null) ? $data['message'] : $data;
}

function ownerChoiceTrialMatches(string $channel, ?array $message, string $mid, array $identity, string $callback): bool
{
    if (!is_array($message)) return false;
    $body = ownerChoiceTrialBody($channel, $callback);
    if ($channel === 'telegram') {
        $blocks = $message['rich_message']['blocks'] ?? null;
        return MaxPrivateAvatarProbe::decimalId($message['message_id'] ?? null) === $mid
            && MaxPrivateAvatarProbe::decimalId($message['from']['id'] ?? null) === $identity['bot']
            && ($message['from']['is_bot'] ?? null) === true
            && MaxPrivateAvatarProbe::decimalId($message['chat']['id'] ?? null) === $identity['user']
            && ($message['chat']['type'] ?? null) === 'private'
            && is_array($blocks) && count($blocks) === 1 && ($blocks[0]['type'] ?? null) === 'paragraph'
            && telegramOwnerPreviewText($blocks[0]['text'] ?? null) === [[false, ownerChoiceTrialText()]]
            && ($message['reply_markup']['inline_keyboard'] ?? null) == $body['reply_markup']['inline_keyboard'];
    }
    return ownerPreviewMid($message) === $mid
        && MaxPrivateAvatarProbe::decimalId($message['sender']['user_id'] ?? null) === $identity['bot']
        && MaxPrivateAvatarProbe::decimalId($message['recipient']['user_id'] ?? null) === $identity['user']
        && MaxPrivateAvatarProbe::chatId($message['recipient']['chat_id'] ?? null) === $identity['chat']
        && ($message['recipient']['chat_type'] ?? null) === 'dialog'
        && ($message['body']['text'] ?? null) === ownerChoiceTrialText()
        && ($message['body']['attachments'] ?? null) == $body['attachments'];
}

/** Explicit states fence corruption; a reserved creation can never become sendable again. */
function ownerChoiceTrialReceipt(array $record, string $channel, int $conversationId, string $spec, array $identity): bool
{
    if (($record['schema'] ?? null) !== 1 || ($record['trial'] ?? null) !== 'owner-choice-trial-20261021-'.$channel
        || ($record['spec_sha256'] ?? null) !== $spec || ($record['conversation_id'] ?? null) !== $conversationId
        || ($record['identity'] ?? null) !== $identity || !is_string($record['created_at'] ?? null)
        || !is_bool($record['post_reserved'] ?? null)) return false;
    $base = ['schema','trial','spec_sha256','identity','conversation_id','state','post_reserved','created_at'];
    $state = $record['state'] ?? null;
    if ($state === 'unprepared') return $record['post_reserved'] === false && count(array_diff(array_keys($record), $base)) === 0;
    if (!is_int($record['offer_id'] ?? null) || $record['offer_id'] <= 0
        || !ownerChoiceTrialCallback($record['callback_data'] ?? null, $record['offer_id'])
        || ($record['body_sha256'] ?? null) !== ownerSelectionHash(ownerChoiceTrialBody($channel, $record['callback_data']))) return false;
    $prepared = array_merge($base, ['offer_id','callback_data','body_sha256']);
    if (!$record['post_reserved']) return $state === 'prepared' && count(array_diff(array_keys($record), $prepared)) === 0;
    $allowed = array_merge($prepared, ['diagnostic','message_id','native_message']);
    if (count(array_diff(array_keys($record), $allowed)) !== 0 || !in_array($state, ['post_reserved','unknown','sent_unverified','delivered'], true)) return false;
    if (in_array($state, ['post_reserved','unknown'], true)) return !array_key_exists('message_id', $record) && !array_key_exists('native_message', $record);
    if (!is_string($record['message_id'] ?? null)) return false;
    if ($channel === 'telegram') return MaxPrivateAvatarProbe::decimalId($record['message_id']) === $record['message_id'] && is_array($record['native_message'] ?? null);
    return ownerPreviewMid(['mid'=>$record['message_id']]) === $record['message_id'] && !array_key_exists('native_message', $record);
}

/** Dependency callables are offline seams. CLI binds only fixed owner targets and TourChoiceService. */
function ownerChoiceTrialRun(string $expected, string $installed, string $project, array $tokens, array $targets,
    array $requests, string $directory, callable $prepare, callable $delivered): array
{
    $out = ['ok'=>false, 'state'=>'blocked', 'send_attempted'=>false, 'provider_requests'=>0, 'cards'=>[]];
    $files = []; $records = []; $identities = []; $lock = null;
    if (!preg_match('/\A[0-9a-f]{40}\z/D', $expected) || $expected !== $installed || $project !== 'anytour') return $out;
    foreach (['telegram','max'] as $channel) if (!is_string($tokens[$channel] ?? null) || $tokens[$channel] === ''
        || strlen($tokens[$channel]) > 4096 || preg_match('/[\x00-\x20\x7f]/', $tokens[$channel])) return $out;
    try {
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700))
            || (fileperms($directory) & 0777) !== 0700 || !ownerPreviewSyncDirectory(dirname($directory))) return array_replace($out, ['state'=>'receipt_unavailable']);
        $lockPath = $directory.'/batch.lock';
        if (is_link($lockPath)) return array_replace($out, ['state'=>'receipt_unavailable']);
        $old = umask(0077); $lock = @fopen($lockPath, 'c+'); umask($old);
        if ($lock === false || ((fstat($lock)['mode'] ?? 0) & 0777) !== 0600 || !flock($lock, LOCK_EX|LOCK_NB)) return array_replace($out, ['state'=>'busy']);
        // Prove both independent native private identities before any business write or message creation.
        foreach (['telegram','max'] as $channel) {
            $identities[$channel] = ownerSelectionIdentity($channel, $targets[$channel](), $requests[$channel], $tokens[$channel], $out);
            if ($identities[$channel] === null) return array_replace($out, ['state'=>'owner_unverified']);
        }
        // Inspect every once receipt first. An unknown earlier POST blocks the whole trial.
        foreach (['telegram'=>1,'max'=>2] as $channel=>$conversationId) {
            $path = $directory.'/owner-choice-trial-20261021-'.$channel.'.json';
            if (is_link($path)) return array_replace($out, ['state'=>'receipt_unavailable']);
            $exists = file_exists($path); $old = umask(0077); $file = @fopen($path, $exists ? 'r+b' : 'x+b'); umask($old);
            if ($file === false) return array_replace($out, ['state'=>'receipt_unavailable']);
            $files[$channel] = $file;
            if (((fstat($file)['mode'] ?? 0) & 0777) !== 0600 || !flock($file, LOCK_EX|LOCK_NB)) return array_replace($out, ['state'=>'receipt_unavailable']);
            clearstatcache(true, $path); $opened = fstat($file); $current = stat($path);
            if ($opened === false || $current === false || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev']) return array_replace($out, ['state'=>'receipt_unavailable']);
            $spec = ownerSelectionHash(['snapshot'=>ownerChoiceTrialSnapshot(), 'text'=>ownerChoiceTrialText(), 'identity'=>$identities[$channel], 'conversation_id'=>$conversationId]);
            if (!$exists) {
                $record = ['schema'=>1, 'trial'=>'owner-choice-trial-20261021-'.$channel, 'spec_sha256'=>$spec,
                    'identity'=>$identities[$channel], 'conversation_id'=>$conversationId, 'state'=>'unprepared', 'post_reserved'=>false, 'created_at'=>gmdate('c')];
                if (!ownerPreviewWrite($file, $record) || !ownerPreviewSyncDirectory($directory)) return array_replace($out, ['state'=>'receipt_unavailable']);
                $records[$channel] = $record; continue;
            }
            $bytes = stream_get_contents($file, 65537); $record = is_string($bytes) && strlen($bytes) <= 65536 ? json_decode($bytes, true) : null;
            if (!is_array($record) || !ownerChoiceTrialReceipt($record, $channel, $conversationId, $spec, $identities[$channel])) return array_replace($out, ['state'=>'receipt_unavailable']);
            if ($record['post_reserved'] && !is_string($record['message_id'] ?? null)) return array_replace($out, ['state'=>'unknown']);
            $records[$channel] = $record;
        }
        foreach (['telegram'=>1,'max'=>2] as $channel=>$conversationId) {
            $file = $files[$channel]; $record = $records[$channel]; $identity = $identities[$channel]; $request = $requests[$channel]; $token = $tokens[$channel];
            if ($record['state'] === 'unprepared') {
                $saved = $prepare($conversationId, ownerChoiceTrialSnapshot());
                if (($saved['ok'] ?? false) !== true || !is_int($saved['offer_id'] ?? null) || $saved['offer_id'] <= 0
                    || !ownerChoiceTrialCallback($saved['callback_data'] ?? null, $saved['offer_id'])) return array_replace($out, ['state'=>'snapshot_unavailable']);
                $record = ['schema'=>1, 'trial'=>'owner-choice-trial-20261021-'.$channel, 'spec_sha256'=>$record['spec_sha256'],
                    'identity'=>$identity, 'conversation_id'=>$conversationId, 'offer_id'=>$saved['offer_id'], 'callback_data'=>$saved['callback_data'],
                    'state'=>'prepared', 'post_reserved'=>false, 'created_at'=>gmdate('c')];
                $record['body_sha256'] = ownerSelectionHash(ownerChoiceTrialBody($channel, $record['callback_data']));
                if (!ownerPreviewWrite($file, $record) || !ownerPreviewSyncDirectory($directory)) return array_replace($out, ['state'=>'receipt_unavailable']);
            }
            $callback = $record['callback_data']; $body = ownerChoiceTrialBody($channel, $callback);
            if (!$record['post_reserved']) {
                $record['post_reserved'] = true; $record['state'] = 'post_reserved';
                if (!ownerPreviewWrite($file, $record) || !ownerPreviewSyncDirectory($directory)) return array_replace($out, ['state'=>'receipt_unavailable']);
                $out['send_attempted'] = true; $out['provider_requests']++;
                $response = $channel === 'telegram' ? $request('sendRichMessage', ['chat_id'=>$identity['user']] + $body, $token, [])
                    : $request('POST', '/messages', ['user_id'=>$identity['user']], $body, $token);
                $message = $channel === 'telegram' ? ($response['data'] ?? null) : ownerChoiceTrialMessage($response);
                $mid = $channel === 'telegram' ? MaxPrivateAvatarProbe::decimalId($message['message_id'] ?? null) : ownerPreviewMid($message);
                $record['diagnostic'] = $channel === 'telegram' ? ($response['diagnostic'] ?? []) : ownerPreviewDiagnostic($response);
                if ($mid !== null) $record['message_id'] = $mid;
                if ($channel === 'telegram' && is_array($message)) $record['native_message'] = ['message_id'=>$message['message_id'] ?? null,
                    'from'=>['id'=>$message['from']['id'] ?? null, 'is_bot'=>$message['from']['is_bot'] ?? null],
                    'chat'=>['id'=>$message['chat']['id'] ?? null, 'type'=>$message['chat']['type'] ?? null],
                    'rich_message'=>$message['rich_message'] ?? null, 'reply_markup'=>$message['reply_markup'] ?? null];
                $record['state'] = $mid === null ? 'unknown' : 'sent_unverified';
                if (!ownerPreviewWrite($file, $record)) return array_replace($out, ['state'=>'receipt_unavailable']);
                if ($mid === null) return array_replace($out, ['state'=>'unknown']);
            }
            $mid = $record['message_id'];
            if ($channel === 'max') { $out['provider_requests']++; $message = ownerChoiceTrialMessage($request('GET', '/messages/'.rawurlencode($mid), [], null, $token)); }
            else $message = $record['native_message'] ?? null;
            if (!ownerChoiceTrialMatches($channel, $message, $mid, $identity, $callback)) return array_replace($out, ['state'=>'sent_unverified']);
            $saved = $delivered($record['offer_id'], $mid, $identity['chat']);
            if (($saved['ok'] ?? false) !== true) return array_replace($out, ['state'=>'delivery_binding_unavailable']);
            $record['state'] = 'delivered';
            if (!ownerPreviewWrite($file, $record)) return array_replace($out, ['state'=>'receipt_unavailable']);
            $out['cards'][$channel] = ['ok'=>true, 'state'=>'delivered'];
        }
        return array_replace($out, ['ok'=>true, 'state'=>'delivered']);
    } catch (Throwable $e) { return array_replace($out, ['state'=>$out['send_attempted'] ? 'incomplete' : 'unavailable']); }
    finally { foreach ($files as $file) if (is_resource($file)) fclose($file); if (is_resource($lock)) fclose($lock); }
}

if (!defined('OWNER_CHOICE_TRIAL_OFFLINE_TEST')) {
    ini_set('display_errors', '0'); $result = ['ok'=>false, 'state'=>'invalid_arguments', 'send_attempted'=>false, 'provider_requests'=>0];
    $expected = ownerChoiceTrialArguments(array_slice($argv, 1));
    if ($expected !== null) {
        $level = ob_get_level(); ob_start();
        try {
            $root = dirname(__DIR__); $installed = trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
            if ($installed !== $expected) $result['state'] = 'installed_sha_mismatch';
            else {
                require_once $root.'/config.php'; require_once $root.'/services/ConversationDb.php'; require_once $root.'/services/ProjectConfig.php'; require_once $root.'/services/TourChoiceService.php';
                $db = ConversationDb::connection();
                $result = ownerChoiceTrialRun($expected, $installed, ProjectConfig::projectId(),
                    ['telegram'=>defined('TELEGRAM_BOT_TOKEN') ? (string)TELEGRAM_BOT_TOKEN : '', 'max'=>defined('MAX_SEARCH_TOKEN') ? (string)MAX_SEARCH_TOKEN : ''],
                    ['telegram'=>static fn()=>telegramOwnerPreviewTarget($db), 'max'=>static fn()=>ownerPreviewTarget($db)],
                    ['telegram'=>'telegramOwnerPreviewRequest', 'max'=>'ownerPreviewRequest'], '/var/www/anytoour/data/owner-tour-choice-trial',
                    static fn(int $conversationId, array $snapshot)=>TourChoiceService::prepare($db, $conversationId, $snapshot),
                    static fn(int $offerId, string $mid, string $chat)=>TourChoiceService::recordDelivered($db, $offerId, $mid, $chat));
            }
        } catch (Throwable $e) { $result['state'] = 'configuration_unavailable'; }
        finally { while (ob_get_level() > $level) ob_end_clean(); }
    }
    echo json_encode($result, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n"; exit($result['ok'] ? 0 : 1);
}
