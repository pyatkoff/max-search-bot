<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors', '0');

const AS39_LINK_ROOT = '/var/www/anytoour/data/www/app.anytoour.ru';
const AS39_LINK_APPLICATION = 'eb7ab9472e6b556812dc3d209a155d291c2077b6';
const AS39_LINK_CHANNEL = '-80917954947727';
const AS39_LINK_PIN = 'mid.ffffb667d20a157101a1bd6d233963da';
const AS39_LINK_POST_KEY = 'pl3-services-index';
const AS39_LINK_MID = 'mid.ffffb667d20a157101a1d01fb10d287b';
const AS39_LINK_PUBLIC_URL = 'https://max.ru/channel_appleservice39ru/AaHQH7ENKHs';
const AS39_LINK_PHOTO = '47389833809';
const AS39_LINK_BEFORE_TEXT = '0a883871d85189b1490ce619c29c8db576b72307031cdb5256e89d3609d96ec3';
const AS39_LINK_BEFORE_MARKUP = 'a384657107a758835381ec09ad0a071743f12676dbec3c6fe9ac61265f96a817';
const AS39_LINK_BUTTON = [[['type'=>'link','text'=>'Узнать стоимость ремонта','url'=>'https://max.ru/id390521129062_bot?start=as39_services-index']]];
const AS39_LINK_DESTINATIONS = [
    'https://max.ru/channel_appleservice39ru/AaG9bRUxHcg',
    'https://max.ru/channel_appleservice39ru/AaG9bRnSVwU',
    'https://max.ru/channel_appleservice39ru/AaG9bR5vbus',
    'https://max.ru/channel_appleservice39ru/AaHJ4ZVPOYk',
    'https://max.ru/channel_appleservice39ru/AaHJ4cflDo0',
    'https://max.ru/channel_appleservice39ru/AaHJ4eNxXTg',
    'https://max.ru/channel_appleservice39ru/AaHQHEeGWP4',
    'https://max.ru/channel_appleservice39ru/AaHQHJl-VYk',
    'https://max.ru/channel_appleservice39ru/AaHQHOyFaZs',
    'https://max.ru/channel_appleservice39ru/AaHQHT0rWDo',
    'https://max.ru/channel_appleservice39ru/AaHQHZFiJM4',
    'https://max.ru/channel_appleservice39ru/AaHQHegTfM8',
    'https://max.ru/channel_appleservice39ru/AaHQHj-Oa2o',
    'https://max.ru/channel_appleservice39ru/AaHQHpg4KMw',
    'https://max.ru/channel_appleservice39ru/AaHQHvI3c4s',
    'https://max.ru/channel_appleservice39ru/AaHQH0vYVVs',
    'https://max.ru/channel_appleservice39ru/AaHJ4a4KCcU',
    'https://max.ru/channel_appleservice39ru/AaHQG_mTD5w',
];

function as39Check(bool $valid, string $reason): void
{
    if (!$valid) throw new RuntimeException($reason);
}

function as39Canonical($value) {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    return array_map('as39Canonical', $value);
}

function as39MarkupDigest(array $markup): string
{
    return hash('sha256', json_encode(as39Canonical($markup), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function as39CallPublisher(object $publisher, string $method, ...$arguments)
{
    return (new ReflectionMethod($publisher, $method))->invoke($publisher, ...$arguments);
}

function as39GitRead(array $arguments): string
{
    $process = proc_open(['git', '-C', AS39_LINK_ROOT, ...$arguments], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    as39Check(is_resource($process), 'checkout_read_failed');
    fclose($pipes[0]);
    $result = stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    as39Check(proc_close($process) === 0 && is_string($result), 'checkout_read_failed');
    return trim($result);
}

function as39MakePlan(string $text, array $destinations): array
{
    $source = explode("\n", $text);
    $plain = [];
    $html = [];
    $links = [];
    $offset = 0;
    $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    for ($i = 0, $count = count($source); $i < $count; $i++) {
        $line = $source[$i];
        as39Check(!str_starts_with($line, 'https://'), 'unexpected_visible_url');
        $label = $escape($line);
        $length = mb_strlen($line, 'UTF-8');
        $nextLine = $source[$i + 1] ?? '';
        if (str_starts_with($nextLine, 'https://')) {
            as39Check($length > 0 && ($destinations[count($links)] ?? null) === $nextLine, 'destination_changed');
            $links[] = ['type'=>'link','from'=>$offset,'length'=>$length,'url'=>$nextLine];
            $label = '<a href="'.$escape($nextLine).'">'.$label.'</a>';
            $i++;
        }
        $plain[] = $line;
        $html[] = $label;
        $offset += $length + 1;
    }
    as39Check(count($links) === count($destinations) && mb_strlen(implode("\n", $html), 'UTF-8') <= 4000, 'caption_shape_changed');
    return ['body'=>['text'=>implode("\n", $html),'format'=>'html'], 'native_text'=>implode("\n", $plain),'native_markup'=>$links];
}

function as39Report(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
}

$store = null;
$row = null;
$attempted = false;
try {
    as39Check($argc === 2 && in_array($argv[1], ['--self-test', '--apply'], true), 'invalid_operation');
    if ($argv[1] === '--self-test') {
        $plan = as39MakePlan("Раздел\nКамера & стекло\n".AS39_LINK_DESTINATIONS[0]."\n\nДругие услуги\n".AS39_LINK_DESTINATIONS[1], array_slice(AS39_LINK_DESTINATIONS, 0, 2));
        as39Check($plan['native_text'] === "Раздел\nКамера & стекло\n\nДругие услуги" && $plan['native_markup'][0]['from'] === 7
            && str_contains($plan['body']['text'], 'Камера &amp; стекло') && !isset($plan['body']['attachments']),
            'fixture_failed');
        try {
            as39MakePlan("Камера\n".AS39_LINK_DESTINATIONS[1], [AS39_LINK_DESTINATIONS[0]]);
            throw new RuntimeException('misdirected_link_allowed');
        } catch (RuntimeException $error) { as39Check($error->getMessage() === 'destination_changed', 'fixture_failed'); }
        as39Report(['ok'=>true,'state'=>'links_source_checked','destination_count'=>count(AS39_LINK_DESTINATIONS)]);
        exit;
    }

    $private = '/var/www/anytoour/data/config/max-search.php';
    as39Check(is_file($private) && !is_link($private), 'protected_connection_unavailable');
    $configHash = hash_file('sha256', $private);
    $safe = static fn(): bool => as39GitRead(['rev-parse','HEAD']) === AS39_LINK_APPLICATION
        && as39GitRead(['status','--porcelain','--untracked-files=no']) === ''
        && is_file($private) && !is_link($private) && hash_file('sha256', $private) === $configHash;
    as39Check($safe(), 'installed_checkout_changed');
    require_once AS39_LINK_ROOT.'/config.php';
    require_once AS39_LINK_ROOT.'/services/ConversationDb.php';
    require_once AS39_LINK_ROOT.'/services/AppleService39PilotPublisher.php';
    $pdo = ConversationDb::connection();
    $store = new AppleService39PilotPublicationStore($pdo);
    $manifest = AppleService39PilotManifest::installed();
    $publisher = new AppleService39PilotPublisher($pdo, $manifest, AS39_LINK_CHANNEL, $safe, null, null,
        new AppleService39CatalogueRevision($manifest), new AppleService39SearchIntentRevision($manifest));
    $store->assertBinding(as39CallPublisher($publisher, 'preflight'));
    $connection = ProjectMaxConnection::resolve('appleservice39');
    as39Check($connection !== null, 'connection_unavailable');
    $transport = new AppleService39ChannelTransport($connection, AS39_LINK_CHANNEL);
    $checkPin = static function () use ($publisher): void {
        as39Check(as39CallPublisher($publisher, 'pinnedMid') === AS39_LINK_PIN, 'pin_changed');
    };
    $observe = static function () use ($publisher, $transport): array {
        $response = $transport->message(AS39_LINK_MID);
        as39Check(AppleService39ChannelTransport::accepted($response), 'native_read_failed');
        $message = $response['data']['message'] ?? $response['data'];
        $body = $message['body'] ?? [];
        $text = $body['text'] ?? null;
        $markup = $body['markup'] ?? [];
        $attachments = $body['attachments'] ?? [];
        as39Check(is_string($text) && is_array($markup) && is_array($attachments) && count($attachments) === 2, 'native_body_changed');
        $keyboard = array_values(array_filter($attachments, static fn(array $a): bool => ($a['type'] ?? null) === 'inline_keyboard'));
        as39Check(count($keyboard) === 1 && as39CallPublisher($publisher, 'sameButtons', $keyboard[0]['payload']['buttons'] ?? null, AS39_LINK_BUTTON), 'cta_changed');
        as39Check((string)(as39CallPublisher($publisher, 'imagePayload', $message)['photo_id'] ?? '') === AS39_LINK_PHOTO, 'photo_changed');
        as39Check(as39CallPublisher($publisher, 'verifyMessageContent', $message, AS39_LINK_MID, ['text'=>$text,'attachments'=>$attachments],
            ['native_text'=>$text,'native_markup'=>$markup]) === AS39_LINK_PUBLIC_URL, 'native_identity_changed');
        return [$text, $markup];
    };
    $checkPin();
    [$text, $markup] = $observe();
    as39Check(hash('sha256', $text) === AS39_LINK_BEFORE_TEXT && as39MarkupDigest($markup) === AS39_LINK_BEFORE_MARKUP, 'original_caption_changed');
    $plan = as39MakePlan($text, AS39_LINK_DESTINATIONS);
    as39Check($store->operation(AS39_LINK_POST_KEY, 'links') === null, 'edit_already_prepared_or_attempted');
    $request = ['revision'=>'labelled-services-links-20261119','target_post'=>'services-index',
        'message_id'=>AS39_LINK_MID,'url'=>AS39_LINK_PUBLIC_URL,'before_text_sha256'=>AS39_LINK_BEFORE_TEXT,'before_markup_sha256'=>AS39_LINK_BEFORE_MARKUP,
        'preserved_photo_id'=>AS39_LINK_PHOTO,'preserved_buttons'=>AS39_LINK_BUTTON] + $plan;
    $row = $store->prepare(AS39_LINK_POST_KEY, 'links', $request);
    as39CallPublisher($publisher, 'beforeWrite', $row, static function () use ($safe, $checkPin, $observe): void {
        as39Check($safe(), 'installed_checkout_changed');
        $checkPin();
        [$text, $markup] = $observe();
        as39Check(hash('sha256', $text) === AS39_LINK_BEFORE_TEXT && as39MarkupDigest($markup) === AS39_LINK_BEFORE_MARKUP, 'original_caption_changed');
    });
    $store->rememberMessage($row, AS39_LINK_MID, AS39_LINK_PUBLIC_URL);
    $attempted = true;
    $ack = $transport->edit(AS39_LINK_MID, $plan['body']);
    [$afterText, $afterMarkup] = $observe();
    as39Check($afterText === $plan['native_text'] && as39MarkupDigest($afterMarkup) === as39MarkupDigest($plan['native_markup']), 'native_labelled_links_unconfirmed');
    $checkPin();
    $store->finish($row, 'verified', ['readback'=>true,'native_text_sha256'=>hash('sha256',$afterText),
        'native_markup_sha256'=>as39MarkupDigest($afterMarkup),'native_labelled_links'=>count(AS39_LINK_DESTINATIONS),
        'photo_preserved'=>true,'single_cta_preserved'=>true,'provider_acknowledged'=>AppleService39ChannelTransport::accepted($ack)]);
    as39Report(['ok'=>true,'url'=>AS39_LINK_PUBLIC_URL,'links'=>count(AS39_LINK_DESTINATIONS),'provider_edits'=>1,'photo_preserved'=>true,'cta_preserved'=>true]);
} catch (Throwable $error) {
    if ($store instanceof AppleService39PilotPublicationStore && is_array($row)) {
        $fresh = $store->operation(AS39_LINK_POST_KEY, 'links');
        if (is_array($fresh) && $fresh['state'] === 'write_started') {
            try { $store->finish($fresh, 'unknown', ['reason'=>'native_labelled_links_unconfirmed']); } catch (Throwable $ignored) {}
        }
    }
    $reason = $error->getMessage();
    as39Report(['ok'=>false,'reason'=>preg_match('/\A[a-z_]{1,60}\z/D', $reason) ? $reason : 'operation_failed',
        'provider_edit_attempted'=>$attempted]);
    exit(1);
}
