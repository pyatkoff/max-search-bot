<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('OWNER_SELECTION_PREVIEW_OFFLINE_TEST')) define('OWNER_SELECTION_PREVIEW_OFFLINE_TEST', true);
require_once __DIR__.'/owner_tour_selection_preview.php';
require_once dirname(__DIR__).'/services/TourOfferCardRenderer.php';

/** A separate fixed photo/choice trial; historical visual examples are immutable. */
function ownerPhotoChoiceArguments(array $args): ?string
{
    if (!in_array('--confirm-owner-photo-choice', $args, true)) return null;
    return ownerPreviewArguments(array_map(static fn($arg)=>$arg === '--confirm-owner-photo-choice' ? '--confirm-owner-send' : $arg, $args));
}

function ownerPhotoChoiceSnapshot(): array
{
    return ['hotel'=>'Delphin Imperial 5★', 'departure'=>'Москва', 'date_from'=>'2027-06-05', 'date_to'=>'2027-06-12',
        'nights'=>7, 'adults'=>2, 'child_ages'=>[], 'room'=>'Улучшенный номер', 'meal'=>'Ультра всё включено',
        'flights'=>[], 'amount'=>'238400', 'currency'=>'RUB', 'price_basis'=>'party',
        'is_demo'=>true, 'price_verified'=>false, 'rationale'=>'', 'compromise'=>''];
}

function ownerPhotoChoicePresentation(): array
{
    return ['destination'=>'Турция, Лара', 'inclusions'=>[], 'room_illustrative'=>true, 'photos'=>[
        ['role'=>'cover','url'=>'https://www.delphinhotel.com/main_pics/pages/medium/2996.png'],
        ['role'=>'beach','url'=>'https://www.delphinhotel.com/main_pics/pages/big/4270.png'],
        ['role'=>'room','url'=>'https://www.delphinhotel.com/main_pics/pages/medium/709.png']]];
}

function ownerPhotoChoiceCard(string $callback): array
{
    return TourOfferCardRenderer::render(ownerPhotoChoiceSnapshot(), ownerPhotoChoicePresentation(), $callback);
}

function ownerPhotoChoiceCallback($value, int $offerId): bool
{
    return is_string($value) && strlen($value) <= 64
        && preg_match('/\Atour_like_'.preg_quote((string)$offerId, '/').'_[a-f0-9]{24}\z/D', $value) === 1;
}

function ownerPhotoChoiceBody(string $channel, string $callback, array $tokens = []): array
{
    $card = ownerPhotoChoiceCard($callback);
    if (!in_array($channel, ['max','telegram'], true)) throw new InvalidArgumentException('Unknown photo trial channel');
    $body = $card[$channel];
    if ($channel === 'max' && $tokens !== []) {
        $images = [];
        foreach ([0,1,2] as $index) {
            if (!ownerPreviewImageToken($tokens[$index] ?? null)) throw new InvalidArgumentException('Missing photo token');
            $images[] = ['type'=>'image','payload'=>['token'=>$tokens[$index]]];
        }
        $body['attachments'] = array_merge($images, $body['attachments']);
    }
    return $body;
}

function ownerPhotoChoiceMessage(?array $response): ?array
{
    $data = ownerPreviewData($response);
    return is_array($data['message'] ?? null) ? $data['message'] : $data;
}

function ownerPhotoChoiceMatches(string $channel, ?array $message, string $mid, array $identity, string $callback): bool
{
    if (!is_array($message) || !in_array($channel, ['max','telegram'], true)) return false;
    $card = ownerPhotoChoiceCard($callback);
    if ($channel === 'telegram') return MaxPrivateAvatarProbe::decimalId($message['message_id'] ?? null) === $mid
        && telegramOwnerPreviewMatches($message, $identity['user'], $identity['bot'], 'slideshow', $card['telegram']);
    $attachments = $message['body']['attachments'] ?? null;
    if (!is_array($attachments) || !array_is_list($attachments) || count($attachments) !== 4) return false;
    foreach ([0,1,2] as $index) if (($attachments[$index]['type'] ?? null) !== 'image'
        || !is_array($attachments[$index]['payload'] ?? null) || $attachments[$index]['payload'] === []) return false;
    $markup = $message['body']['markup'] ?? null;
    $expected = $card['max_markup'];
    if (!is_array($markup) || count($markup) !== 2) return false;
    foreach ($markup as $span) {
        if (!is_array($span) || count($span) !== 3) return false;
        $key = array_search(['type'=>$span['type'] ?? null,'from'=>$span['from'] ?? null,'length'=>$span['length'] ?? null], $expected, true);
        if ($key === false) return false;
        unset($expected[$key]);
    }
    return $expected === [] && $attachments[3] == $card['max']['attachments'][0]
        && ownerPreviewMid($message) === $mid
        && ($message['body']['text'] ?? null) === $card['plain_text']
        && MaxPrivateAvatarProbe::decimalId($message['sender']['user_id'] ?? null) === $identity['bot']
        && MaxPrivateAvatarProbe::decimalId($message['recipient']['user_id'] ?? null) === $identity['user']
        && MaxPrivateAvatarProbe::chatId($message['recipient']['chat_id'] ?? null) === $identity['chat']
        && ($message['recipient']['chat_type'] ?? null) === 'dialog';
}

/** Explicit states fence corruption; a reserved creation can never become sendable again. */
function ownerPhotoChoiceReceipt(array $record, string $channel, int $conversationId, string $spec, array $identity): bool
{
    if (($record['schema'] ?? null) !== 1 || ($record['trial'] ?? null) !== 'owner-photo-choice-20261021-'.$channel
        || ($record['spec_sha256'] ?? null) !== $spec || ($record['conversation_id'] ?? null) !== $conversationId
        || ($record['identity'] ?? null) !== $identity || !is_string($record['created_at'] ?? null)
        || !is_bool($record['post_reserved'] ?? null)) return false;
    $base = ['schema','trial','spec_sha256','identity','conversation_id','state','post_reserved','created_at','photo_sha256','image_tokens'];
    $hashes = $record['photo_sha256'] ?? null; $tokens = $record['image_tokens'] ?? null;
    if (!is_array($hashes) || !array_is_list($hashes) || !in_array(count($hashes), [0,3], true)
        || !is_array($tokens) || !array_is_list($tokens) || count($tokens) > 3 || ($channel === 'telegram' && $tokens !== [])) return false;
    foreach ($hashes as $hash) if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) return false;
    foreach ($tokens as $token) if (!ownerPreviewImageToken($token)) return false;
    if ($tokens !== [] && count($hashes) !== 3) return false;
    $state = $record['state'] ?? null;
    if ($state === 'unprepared') return $record['post_reserved'] === false && $tokens === [] && count(array_diff(array_keys($record), $base)) === 0;
    if (!is_int($record['offer_id'] ?? null) || $record['offer_id'] <= 0
        || !ownerPhotoChoiceCallback($record['callback_data'] ?? null, $record['offer_id'])
        || ($record['body_sha256'] ?? null) !== ownerSelectionHash(ownerPhotoChoiceBody($channel, $record['callback_data']))) return false;
    $prepared = array_merge($base, ['offer_id','callback_data','body_sha256']);
    if (!$record['post_reserved']) return $state === 'prepared' && count(array_diff(array_keys($record), $prepared)) === 0;
    $allowed = array_merge($prepared, ['diagnostic','message_id','native_message','post_sha256']);
    if (count($hashes) !== 3 || ($channel === 'max' && count($tokens) !== 3)
        || ($record['post_sha256'] ?? null) !== ownerSelectionHash(['body'=>ownerPhotoChoiceBody($channel, $record['callback_data'], $tokens), 'photos'=>$hashes])) return false;
    if (count(array_diff(array_keys($record), $allowed)) !== 0 || !in_array($state, ['post_reserved','unknown','sent_unverified','delivered'], true)) return false;
    if (in_array($state, ['post_reserved','unknown'], true)) return !array_key_exists('message_id', $record) && !array_key_exists('native_message', $record);
    if (!is_string($record['message_id'] ?? null)) return false;
    if ($channel === 'telegram') return MaxPrivateAvatarProbe::decimalId($record['message_id']) === $record['message_id'] && is_array($record['native_message'] ?? null);
    return ownerPreviewMid(['mid'=>$record['message_id']]) === $record['message_id'] && !array_key_exists('native_message', $record);
}

/** Dependency callables are offline seams. CLI binds only fixed owner targets and TourChoiceService. */
function ownerPhotoChoiceRun(string $expected, string $installed, string $project, array $tokens, array $targets,
    array $requests, string $directory, callable $prepare, callable $delivered, array $transfers = []): array
{
    $out = ['ok'=>false, 'state'=>'blocked', 'send_attempted'=>false, 'provider_requests'=>0, 'cards'=>[]];
    $files = []; $records = []; $identities = []; $images = []; $lock = null;
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
            $path = $directory.'/owner-photo-choice-20261021-'.$channel.'.json';
            if (is_link($path)) return array_replace($out, ['state'=>'receipt_unavailable']);
            $exists = file_exists($path); $old = umask(0077); $file = @fopen($path, $exists ? 'r+b' : 'x+b'); umask($old);
            if ($file === false) return array_replace($out, ['state'=>'receipt_unavailable']);
            $files[$channel] = $file;
            if (((fstat($file)['mode'] ?? 0) & 0777) !== 0600 || !flock($file, LOCK_EX|LOCK_NB)) return array_replace($out, ['state'=>'receipt_unavailable']);
            clearstatcache(true, $path); $opened = fstat($file); $current = stat($path);
            if ($opened === false || $current === false || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev']) return array_replace($out, ['state'=>'receipt_unavailable']);
            $spec = ownerSelectionHash(['snapshot'=>ownerPhotoChoiceSnapshot(), 'presentation'=>ownerPhotoChoicePresentation(), 'identity'=>$identities[$channel], 'conversation_id'=>$conversationId]);
            if (!$exists) {
                $record = ['schema'=>1, 'trial'=>'owner-photo-choice-20261021-'.$channel, 'spec_sha256'=>$spec,
                    'identity'=>$identities[$channel], 'conversation_id'=>$conversationId, 'state'=>'unprepared', 'post_reserved'=>false, 'created_at'=>gmdate('c'), 'photo_sha256'=>[], 'image_tokens'=>[]];
                if (!ownerPreviewWrite($file, $record) || !ownerPreviewSyncDirectory($directory)) return array_replace($out, ['state'=>'receipt_unavailable']);
                $records[$channel] = $record; continue;
            }
            $bytes = stream_get_contents($file, 65537); $record = is_string($bytes) && strlen($bytes) <= 65536 ? json_decode($bytes, true) : null;
            if (!is_array($record) || !ownerPhotoChoiceReceipt($record, $channel, $conversationId, $spec, $identities[$channel])) return array_replace($out, ['state'=>'receipt_unavailable']);
            if ($record['post_reserved'] && !is_string($record['message_id'] ?? null)) return array_replace($out, ['state'=>'unknown']);
            $records[$channel] = $record;
        }
        if ($records['telegram']['photo_sha256'] !== [] && $records['max']['photo_sha256'] !== []
            && $records['telegram']['photo_sha256'] !== $records['max']['photo_sha256']) return array_replace($out, ['state'=>'source_changed']);
        // Reuse the exact downloaded bytes in both channels; hashes bind cached uploads to the fixed source specification.
        if (!$records['telegram']['post_reserved'] || !$records['max']['post_reserved']) {
            $download = $transfers['download'] ?? static fn(int $index, string $dir)=>ownerPreviewDownloadOfficialImage(ownerPhotoChoicePresentation()['photos'][$index]['url'], $index, $dir);
            foreach ([0,1,2] as $index) {
                $image = $download($index, $directory);
                if (!is_array($image) || !is_string($image['path'] ?? null) || dirname($image['path']) !== $directory
                    || !preg_match('/\A\.owner-preview-image-[012]-[a-f0-9]{16}\.tmp\z/D', basename($image['path']))
                    || is_link($image['path']) || !is_file($image['path']) || (fileperms($image['path']) & 0777) !== 0600
                    || !in_array($image['mime'] ?? null, ['image/jpeg','image/png'], true)) return array_replace($out, ['state'=>'image_unavailable']);
                $images[$index] = $image;
            }
            $hashes = array_map(static fn($image)=>hash_file('sha256', $image['path']), $images);
            foreach (['telegram','max'] as $channel) {
                if ($records[$channel]['photo_sha256'] !== [] && $records[$channel]['photo_sha256'] !== $hashes) return array_replace($out, ['state'=>'source_changed']);
                if (!$records[$channel]['post_reserved']) {
                    $records[$channel]['photo_sha256'] = $hashes;
                    if (!ownerPreviewWrite($files[$channel], $records[$channel])) return array_replace($out, ['state'=>'receipt_unavailable']);
                }
            }
        }
        foreach (['telegram'=>1,'max'=>2] as $channel=>$conversationId) {
            $file = $files[$channel]; $record = $records[$channel]; $identity = $identities[$channel]; $request = $requests[$channel]; $token = $tokens[$channel];
            if ($record['state'] === 'unprepared') {
                $saved = $prepare($conversationId, ownerPhotoChoiceSnapshot());
                if (($saved['ok'] ?? false) !== true || !is_int($saved['offer_id'] ?? null) || $saved['offer_id'] <= 0
                    || !ownerPhotoChoiceCallback($saved['callback_data'] ?? null, $saved['offer_id'])) return array_replace($out, ['state'=>'snapshot_unavailable']);
                $record = ['schema'=>1, 'trial'=>'owner-photo-choice-20261021-'.$channel, 'spec_sha256'=>$record['spec_sha256'],
                    'identity'=>$identity, 'conversation_id'=>$conversationId, 'offer_id'=>$saved['offer_id'], 'callback_data'=>$saved['callback_data'],
                    'state'=>'prepared', 'post_reserved'=>false, 'created_at'=>gmdate('c'), 'photo_sha256'=>$record['photo_sha256'], 'image_tokens'=>$record['image_tokens']];
                $record['body_sha256'] = ownerSelectionHash(ownerPhotoChoiceBody($channel, $record['callback_data']));
                if (!ownerPreviewWrite($file, $record) || !ownerPreviewSyncDirectory($directory)) return array_replace($out, ['state'=>'receipt_unavailable']);
            }
            $callback = $record['callback_data'];
            if (!$record['post_reserved'] && $channel === 'max') foreach ([0,1,2] as $index) {
                if (isset($record['image_tokens'][$index])) continue;
                $out['provider_requests']++; $reservation = $request('POST', '/uploads', ['type'=>'image'], null, $token);
                $data = ownerPreviewData($reservation); $url = $data['url'] ?? $data['upload_url'] ?? null;
                if (!ownerPreviewUploadUrl($url)) return array_replace($out, ['state'=>'image_upload_unavailable']);
                $out['provider_requests']++; $upload = $transfers['upload'] ?? 'ownerPreviewUploadImage';
                $imageToken = ownerPreviewUploadedToken(ownerPreviewData($upload($url, $images[$index])));
                if ($imageToken === null) return array_replace($out, ['state'=>'image_upload_unavailable']);
                $record['image_tokens'][$index] = $imageToken;
                if (!ownerPreviewWrite($file, $record)) return array_replace($out, ['state'=>'receipt_unavailable']);
            }
            $body = ownerPhotoChoiceBody($channel, $callback, $record['image_tokens']);
            if (!$record['post_reserved']) {
                $record['post_reserved'] = true; $record['state'] = 'post_reserved';
                $record['post_sha256'] = ownerSelectionHash(['body'=>$body, 'photos'=>$record['photo_sha256']]);
                if (!ownerPreviewWrite($file, $record) || !ownerPreviewSyncDirectory($directory)) return array_replace($out, ['state'=>'receipt_unavailable']);
                $out['send_attempted'] = true; $out['provider_requests']++;
                $response = $channel === 'telegram' ? $request('sendRichMessage', ['chat_id'=>$identity['user']] + $body, $token, $images)
                    : $request('POST', '/messages', ['user_id'=>$identity['user']], $body, $token);
                $message = $channel === 'telegram' ? ($response['data'] ?? null) : ownerPhotoChoiceMessage($response);
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
            if ($channel === 'max') { $out['provider_requests']++; $message = ownerPhotoChoiceMessage($request('GET', '/messages/'.rawurlencode($mid), [], null, $token)); }
            else $message = $record['native_message'] ?? null;
            if (!ownerPhotoChoiceMatches($channel, $message, $mid, $identity, $callback)) return array_replace($out, ['state'=>'sent_unverified']);
            $saved = $delivered($record['offer_id'], $mid, $identity['chat']);
            if (($saved['ok'] ?? false) !== true) return array_replace($out, ['state'=>'delivery_binding_unavailable']);
            $record['state'] = 'delivered';
            if (!ownerPreviewWrite($file, $record)) return array_replace($out, ['state'=>'receipt_unavailable']);
            $out['cards'][$channel] = ['ok'=>true, 'state'=>'delivered'];
        }
        return array_replace($out, ['ok'=>true, 'state'=>'delivered']);
    } catch (Throwable $e) { return array_replace($out, ['state'=>$out['send_attempted'] ? 'incomplete' : 'unavailable']); }
    finally { foreach ($images as $image) if (is_file($image['path'])) @unlink($image['path']); foreach ($files as $file) if (is_resource($file)) fclose($file); if (is_resource($lock)) fclose($lock); }
}

if (!defined('OWNER_PHOTO_CHOICE_OFFLINE_TEST')) {
    ini_set('display_errors', '0'); $result = ['ok'=>false, 'state'=>'invalid_arguments', 'send_attempted'=>false, 'provider_requests'=>0];
    $expected = ownerPhotoChoiceArguments(array_slice($argv, 1));
    if ($expected !== null) {
        $level = ob_get_level(); ob_start();
        try {
            $root = dirname(__DIR__); $installed = trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
            if ($installed !== $expected) $result['state'] = 'installed_sha_mismatch';
            else {
                require_once $root.'/config.php'; require_once $root.'/services/ConversationDb.php'; require_once $root.'/services/ProjectConfig.php'; require_once $root.'/services/TourChoiceService.php';
                $db = ConversationDb::connection();
                $result = ownerPhotoChoiceRun($expected, $installed, ProjectConfig::projectId(),
                    ['telegram'=>defined('TELEGRAM_BOT_TOKEN') ? (string)TELEGRAM_BOT_TOKEN : '', 'max'=>defined('MAX_SEARCH_TOKEN') ? (string)MAX_SEARCH_TOKEN : ''],
                    ['telegram'=>static fn()=>telegramOwnerPreviewTarget($db), 'max'=>static fn()=>ownerPreviewTarget($db)],
                    ['telegram'=>'telegramOwnerPreviewRequest', 'max'=>'ownerPreviewRequest'], '/var/www/anytoour/data/owner-tour-photo-choice-trial',
                    static fn(int $conversationId, array $snapshot)=>TourChoiceService::prepare($db, $conversationId, $snapshot),
                    static fn(int $offerId, string $mid, string $chat)=>TourChoiceService::recordDelivered($db, $offerId, $mid, $chat));
            }
        } catch (Throwable $e) { $result['state'] = 'configuration_unavailable'; }
        finally { while (ob_get_level() > $level) ob_end_clean(); }
    }
    echo json_encode($result, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n"; exit($result['ok'] ? 0 : 1);
}
