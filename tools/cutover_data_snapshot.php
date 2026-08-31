<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/services/ConversationDb.php';

$tables = [
    'customers',
    'customer_channels',
    'conversations',
    'messages',
    'managers',
    'manager_assignments',
    'conversation_events',
];

try {
    $pdo = ConversationDb::connection();
    $snapshot = [
        'database' => defined('CONVERSATION_DB_NAME') ? (string)CONVERSATION_DB_NAME : '',
        'generated_at' => gmdate('c'),
        'tables' => [],
    ];

    foreach ($tables as $table) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $row = $pdo->query("SELECT COUNT(*) AS row_count, COALESCE(MAX(id), 0) AS max_id FROM {$quoted}")->fetch(PDO::FETCH_ASSOC);
        $snapshot['tables'][$table] = [
            'row_count' => (int)($row['row_count'] ?? 0),
            'max_id' => (int)($row['max_id'] ?? 0),
        ];
    }

    fwrite(STDOUT, json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'CUTOVER_DATA_SNAPSHOT_FAILED=' . preg_replace('/\s+/', ' ', $e->getMessage()) . PHP_EOL);
    exit(1);
}
