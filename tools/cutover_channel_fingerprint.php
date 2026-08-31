<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/services/ConversationDb.php';

$channel = strtolower(trim((string)($argv[1] ?? 'max')));
$manifestMode = in_array('--manifest', $argv, true);
if ($channel === '' || !preg_match('/^[a-z0-9_-]{1,32}$/', $channel)) {
    fwrite(STDERR, "INVALID_CHANNEL\n"); exit(2);
}

function fingerprintRows(PDO $pdo, string $sql, array $params, string $timeColumn, bool $manifestMode): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ctx = hash_init('sha256');
    $count = 0;
    $maxId = 0;
    $latest = '';
    $manifest = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $count++;
        $id = (int)($row['id'] ?? 0);
        $maxId = max($maxId, $id);
        if ($timeColumn !== '' && isset($row[$timeColumn]) && (string)$row[$timeColumn] > $latest) {
            $latest = (string)$row[$timeColumn];
        }
        ksort($row);
        $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        hash_update($ctx, $encoded . "\n");
        if ($manifestMode && $id > 0) {
            $manifest[(string)$id] = hash('sha256', $encoded);
        }
    }
    $result = [
        'row_count' => $count,
        'max_id' => $maxId,
        'latest_at' => $latest,
        'sha256' => hash_final($ctx),
    ];
    if ($manifestMode) {
        // Intended only for ephemeral cutover comparison. Workflow output must never print this map.
        $result['row_manifest'] = $manifest;
    }
    return $result;
}

try {
    $pdo = ConversationDb::connection();
    $specs = [
        'customer_channels' => [
            'sql' => 'SELECT * FROM customer_channels WHERE channel = :channel ORDER BY id',
            'time' => 'updated_at',
        ],
        'customers' => [
            'sql' => 'SELECT c.* FROM customers c WHERE EXISTS (SELECT 1 FROM customer_channels cc WHERE cc.customer_id = c.id AND cc.channel = :channel) ORDER BY c.id',
            'time' => 'updated_at',
        ],
        'conversations' => [
            'sql' => 'SELECT * FROM conversations WHERE channel = :channel ORDER BY id',
            'time' => 'updated_at',
        ],
        'messages' => [
            'sql' => 'SELECT m.* FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.channel = :channel ORDER BY m.id',
            'time' => 'created_at',
        ],
        'manager_assignments' => [
            'sql' => 'SELECT ma.* FROM manager_assignments ma JOIN conversations c ON c.id = ma.conversation_id WHERE c.channel = :channel ORDER BY ma.id',
            'time' => 'assigned_at',
        ],
        'conversation_events' => [
            'sql' => 'SELECT ce.* FROM conversation_events ce JOIN conversations c ON c.id = ce.conversation_id WHERE c.channel = :channel ORDER BY ce.id',
            'time' => 'created_at',
        ],
    ];

    $result = ['channel' => $channel, 'tables' => []];
    foreach ($specs as $name => $spec) {
        $result['tables'][$name] = fingerprintRows($pdo, $spec['sql'], [':channel' => $channel], $spec['time'], $manifestMode);
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'CHANNEL_FINGERPRINT_FAILED=' . preg_replace('/\s+/', ' ', $e->getMessage()) . PHP_EOL);
    exit(1);
}
