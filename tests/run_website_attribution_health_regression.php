<?php
require_once __DIR__ . '/../services/WebsiteAttributionHealth.php';

function assertTrue($condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$healthy = WebsiteAttributionHealth::evaluate([[
    'conversation_id'=>10,
    'project_key'=>'anytour',
    'source_id'=>2,
    'source_key'=>'website:anytour-main',
    'source_project_key'=>'anytour',
    'source_channel'=>'website',
]]);
assertTrue($healthy['ok'] === true, 'healthy website attribution must pass');
assertTrue(count($healthy['anomalies']) === 0, 'healthy website attribution must have no anomalies');

$missing = WebsiteAttributionHealth::evaluate([[
    'conversation_id'=>11,
    'project_key'=>'anytour',
    'source_id'=>0,
    'source_key'=>'',
    'source_project_key'=>'',
    'source_channel'=>'',
]]);
assertTrue($missing['ok'] === false, 'missing source must fail');
assertTrue(($missing['anomalies'][0]['reason'] ?? '') === 'missing_source', 'missing source reason');

$mismatch = WebsiteAttributionHealth::evaluate([[
    'conversation_id'=>12,
    'project_key'=>'anytour',
    'source_id'=>2,
    'source_key'=>'website:anytour-main',
    'source_project_key'=>'other',
    'source_channel'=>'website',
]]);
assertTrue($mismatch['ok'] === false, 'project mismatch must fail');
assertTrue(($mismatch['anomalies'][0]['reason'] ?? '') === 'source_project_mismatch', 'project mismatch reason');

echo "OK website_attribution_health\n";
