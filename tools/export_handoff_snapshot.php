<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config.php';
require_once $baseDir . '/services/ConversationDb.php';
require_once $baseDir . '/services/HandoffTimelineService.php';

function redactText(string $text): string {
    $text = preg_replace('/(?<!\d)(?:\+7|8)[\s\-\(\)]*(?:\d[\s\-\(\)]*){10}(?!\d)/u', '[phone-redacted]', $text);
    return preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu', '[email-redacted]', $text);
}

function recentStructuredHandoffEvents(string $path): array {
    $out = ['priority'=>[], 'push'=>[]];
    if (!is_file($path) || !is_readable($path)) return $out;
    $raw = (string)@shell_exec('tail -n 2000 '.escapeshellarg($path).' 2>/dev/null');
    foreach (preg_split('/\R/u', trim($raw)) ?: [] as $line) {
        $row = json_decode($line, true);
        if (!is_array($row)) continue;
        $component = (string)($row['component'] ?? '');
        if ($component === 'manager_priority') $out['priority'][] = $row;
        elseif ($component === 'manager_push') $out['push'][] = $row;
    }
    return $out;
}

$out = [
    'ok' => false,
    'generated_at' => date('c'),
    'channel' => 'max',
    'messages' => [],
    'events' => [],
    'timelines' => [],
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
        "SELECT e.id,e.conversation_id,e.event_type,e.actor_type,e.actor_id,e.created_at " .
        "FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id " .
        "WHERE c.channel='max' ORDER BY e.id DESC LIMIT 200"
    )->fetchAll();
    foreach (array_reverse($events) as $row) {
        $out['events'][] = [
            'id' => (int)$row['id'],
            'conversation_id' => (int)$row['conversation_id'],
            'event_type' => (string)$row['event_type'],
            'actor_type' => (string)$row['actor_type'],
            'actor_id' => isset($row['actor_id']) ? (int)$row['actor_id'] : null,
            'created_at' => (string)$row['created_at'],
        ];
    }

    $structured = recentStructuredHandoffEvents($baseDir . '/structured_events.log');
    $handoffs = $pdo->query(
        "SELECT c.id,c.project_key,c.channel,c.status,c.manager_id," .
        "MAX(e.created_at) AS manager_request_at " .
        "FROM conversations c JOIN conversation_events e ON e.conversation_id=c.id AND e.event_type='waiting_manager' " .
        "WHERE c.channel='max' GROUP BY c.id,c.project_key,c.channel,c.status,c.manager_id " .
        "ORDER BY manager_request_at DESC LIMIT 20"
    )->fetchAll();

    foreach ($handoffs as $conversation) {
        $conversationId = (int)$conversation['id'];
        $q = $pdo->prepare(
            "SELECT id,conversation_id,event_type,actor_type,actor_id,created_at FROM conversation_events " .
            "WHERE conversation_id=? ORDER BY id ASC"
        );
        $q->execute([$conversationId]);
        $dbEvents = $q->fetchAll();

        $q = $pdo->prepare(
            "SELECT id,conversation_id,direction,sender_type,created_at FROM messages " .
            "WHERE conversation_id=? ORDER BY id ASC"
        );
        $q->execute([$conversationId]);
        $conversationMessages = $q->fetchAll();

        $out['timelines'][] = HandoffTimelineService::build(
            $conversation,
            $dbEvents,
            $conversationMessages,
            $structured['priority'],
            $structured['push']
        );
    }

    $out['ok'] = true;
} catch (Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
