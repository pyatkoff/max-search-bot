<?php
declare(strict_types=1);

const ARCHIVE_CHAT = '-68765999669267';
const ARCHIVE_APP = '/var/www/anytoour/data/www/app.anytoour.ru';

function archiveRow(array $message, array $registered, array $previous): array
{
    $mid = $message['body']['mid'];
    $slug = rtrim(strtr(base64_encode(hex2bin(substr($mid, -16))), '+/', '-_'), '=');
    $url = 'https://max.ru/anytour/' . $slug;
    $row = [
        'mid' => $mid, 'url' => $url, 'published_ms' => (int)$message['timestamp'],
        'registered' => isset($registered[$mid]), 'previously_screened' => isset($previous[$slug]),
    ];
    $snapshot = ChannelPostIncomingAdapter::maxSnapshot($message);
    if (!$snapshot) {
        return $row + ['status' => 'unparsed'];
    }
    $media = array_column($snapshot['attachments'], 'type');
    $row['media'] = $media;
    $row['keyboard_present'] = in_array('inline_keyboard', array_column($message['body']['attachments'] ?? [], 'type'), true);
    $row['linked_message'] = !empty($message['link']);
    $row['fingerprint'] = ChannelPostIncomingAdapter::contentFingerprint($snapshot);
    $providerUrl = $message['url'] ?? '';
    $channelUrl = 'https://max.ru/c/' . ARCHIVE_CHAT . '/' . $slug;
    if ($providerUrl !== '' && $providerUrl !== $url && $providerUrl !== $channelUrl) {
        return $row + ['status' => 'provider_url_mismatch'];
    }
    if ($row['registered'] || $row['previously_screened']) {
        return $row + ['status' => 'already_known'];
    }
    if ($media !== ['image'] || $row['keyboard_present'] || $row['linked_message']
        || ($snapshot['metadata']['capability'] ?? '') !== 'supported') {
        return $row + ['status' => 'other_format'];
    }
    $text = $snapshot['text'];
    $markup = json_encode($snapshot['markup'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (preg_match('~(?:[?&](?:start|startapp)=|(?:^|[^a-z0-9])(?:ctx1_|yclid\b)|max\.ru/channel_anytour/|t\.me/any_toour/)~i', $text . "\n" . $markup)) {
        return $row + ['status' => 'attributed_link_present'];
    }
    // Full public text retains hotel matches beyond the first excerpt. It remains operator evidence.
    return $row + ['status' => 'needs_content_and_route_review', 'text' => $text];
}

function readArchive(callable $pageRead, array $registered, array $previous, int $before): array
{
    $rows = [];
    for ($page = 1; $page <= 50; ++$page) {
        $messages = $pageRead($before);
        if (!is_array($messages) || !array_is_list($messages) || count($messages) > 100) {
            throw new RuntimeException('PAGE_INVALID');
        }
        $oldest = $before;
        $pageIds = [];
        foreach ($messages as $message) {
            $mid = $message['body']['mid'] ?? null;
            $time = $message['timestamp'] ?? null;
            if (!is_string($mid) || !preg_match('/\Amid\.ffffc1752b045fed[0-9a-f]{16}\z/D', $mid)
                || (string)($message['recipient']['chat_id'] ?? '') !== ARCHIVE_CHAT
                || ($message['recipient']['chat_type'] ?? '') !== 'channel'
                || (!is_int($time) && !is_string($time))
                || !preg_match('/\A[1-9][0-9]{0,15}\z/D', (string)$time) || (int)$time > $before
                || isset($pageIds[$mid])) {
                throw new RuntimeException('PAGE_IDENTITY_INVALID');
            }
            $pageIds[$mid] = true;
            $oldest = min($oldest, (int)$time);
            $rows[$mid] ??= archiveRow($message, $registered, $previous);
        }
        if (count($messages) < 100) {
            $rows = array_values($rows);
            usort($rows, static fn(array $a, array $b): int =>
                [$a['published_ms'], $a['mid']] <=> [$b['published_ms'], $b['mid']]);
            $counts = array_count_values(array_column($rows, 'status'));
            return [
                'ok' => true, 'history_complete' => true, 'read_utc' => gmdate(DATE_ATOM),
                'pages' => $page, 'messages' => count($rows), 'categories' => $counts, 'rows' => $rows,
            ];
        }
        if ($oldest >= $before) {
            throw new RuntimeException('HISTORY_CURSOR_STALLED');
        }
        $before = $oldest;
    }
    throw new RuntimeException('HISTORY_LIMIT_INCOMPLETE');
}

function runArchive(): array
{
    if (PHP_SAPI !== 'cli' || getcwd() !== ARCHIVE_APP) {
        throw new RuntimeException('APPLICATION_CLI_REQUIRED');
    }
    require_once ARCHIVE_APP . '/config.php';
    require_once ARCHIVE_APP . '/services/ChannelPostEntryService.php';
    require_once ARCHIVE_APP . '/services/ChannelPostButtonTransport.php';
    if (ProjectConfig::projectId() !== 'anytour') {
        throw new RuntimeException('WRONG_PROJECT');
    }
    $pdo = ConversationDb::connection();
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    try {
        $identity = ChannelPostRuntime::identity('max');
        $registry = new ChannelPostRegistryService($pdo);
        $channel = $identity ? $registry->channel('anytour', 'max', $identity['bot_key'], ARCHIVE_CHAT) : null;
        if ((int)($channel['id'] ?? 0) !== 4) {
            throw new RuntimeException('CHANNEL_MISMATCH');
        }
        $statement = $pdo->prepare('SELECT external_message_id FROM post_publications WHERE post_channel_id = ?');
        $statement->execute([$channel['id']]);
        $registered = array_fill_keys($statement->fetchAll(PDO::FETCH_COLUMN), true);
    } finally {
        $pdo->rollBack();
    }
    // The separately reviewed 19 November material stays in the coverage counts.
    $previous = array_fill_keys(explode(' ', 'AaBXt6whKKM AaBM5lS5bUc AaBI6Jfvad8 AaAuJeZbQAc AaAkdvyEBXQ AaAZbG-VQzQ AaAQRP74HIo AZ_1bEjVO3Q AZ_rrRmlYz0 AZ_bVraKfqs AZ_RB3FVXFc AZ_MDV0gO_I AZ_BkAiveNQ AZ-3aA0tG-E AZ-yESbCL_Q AZ-tLRx7Jzw AZ-OUsaPJjg AZ-DqmRQHF8 AZ9k6MvNF94 AZ87_EUJEiI AZ8r4R9vT_Q AZ8nLpmpFcM AZ8DSRZpHNw AZ7pU2e2Znw AZ7exN6EPIU AZ7Z4K9JJNQ AZ7UVbXteP8 AZ7PcvbibFA AZ7KT8ynMm8 AZ61r6_QcAI AZ6wa60cTlw AZ6sX9HGLTk AZ6rVgdvPUA AZ5qgiujRlo AZ3ZIwgTd3g AZwjaZt4Kz8 AZunpJAQdFw AZnkPdKofYQ'), true);
    $pageRead = static function (int $before): array {
        $response = ChannelPostApiTransport::buttonRequest('max', 'GET', '/messages',
            ['chat_id' => ARCHIVE_CHAT, 'from' => $before, 'to' => 1, 'count' => 100], null);
        if (($response['http'] ?? 0) !== 200 || !empty($response['transport_error'])
            || !is_array($response['data']['messages'] ?? null)) {
            throw new RuntimeException('HISTORY_UNAVAILABLE');
        }
        return $response['data']['messages'];
    };
    return readArchive($pageRead, $registered, $previous, (int)ceil(microtime(true) * 1000));
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit(2);
    }
    ini_set('display_errors', '0');
    ob_start();
    try {
        $output = runArchive();
    } catch (Throwable $error) {
        $reason = $error->getMessage();
        $output = ['ok' => false, 'history_complete' => false,
            'error' => preg_match('/\A[A-Z_]{3,50}\z/D', $reason) ? $reason : 'READ_FAILED'];
    }
    ob_end_clean();
    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    exit($output['ok'] ? 0 : 2);
}
