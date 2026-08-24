<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config.php';
require_once $baseDir . '/services/ConversationDb.php';

function redactText(string $text): string {
    $text = preg_replace('/(?<!\d)(?:\+7|8)[\s\-\(\)]*(?:\d[\s\-\(\)]*){10}(?!\d)/u', '[phone-redacted]', $text);
    return preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu', '[email-redacted]', $text);
}

$out = [
    'ok' => false,
    'generated_at' => date('c'),
    'channel' => 'max',
    'messages' => [],
    'events' => [],
];

try {
    $pdo = ConversationDb::connection();

    $messages = $pdo->query(
        "SELECT m.id,m.conversation_id,m.direction,m.sender_type,m.text,m.created_at " .
        "FROM messages m JOIN conversations c ON c.id=m.conversation_id " .
        "WHERE c.channel='max' ORDER BY m.id DESC LIMIT 200"
    )->fetchAll();
    foreach (array_reverse($messages) as $row) {
        $out['messages'][] = [
            'id' => (int)$row['id'],
            'conversation_id' => (int)$row['conversation_id'],
            'direction' => (string)$row['direction'],
            'sender_type' => (string)$row['sender_type'],
            'text' => redactText((string)$row['text']),
            'created_at' => (string)$row['created_at'],
        ];
    }

    $events = $pdo->query(
        "SELECT e.id,e.conversation_id,e.event_type,e.actor_type,e.created_at " .
        "FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id " .
        "WHERE c.channel='max' ORDER BY e.id DESC LIMIT 100"
    )->fetchAll();
    foreach (array_reverse($events) as $row) {
        $out['events'][] = [
            'id' => (int)$row['id'],
            'conversation_id' => (int)$row['conversation_id'],
            'event_type' => (string)$row['event_type'],
            'actor_type' => (string)$row['actor_type'],
            'created_at' => (string)$row['created_at'],
        ];
    }

    $out['ok'] = true;
} catch (Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
