<?php

declare(strict_types=1);

$failed = 0;
function pseCheck(string $name, $actual, $expected): void {
    global $failed;
    if ($actual === $expected) { echo "PASS  {$name}\n"; return; }
    echo "FAIL  {$name}\n";
    echo '      expected: '.json_encode($expected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo '      actual:   '.json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    $failed++;
}

// Production diagnostics can contain legacy/free-form message bytes. A single
// invalid UTF-8 sequence must not make the whole operational snapshot empty.
$sample = ['ok'=>true, 'text'=>"valid \xB1 byte"];
$json = json_encode($sample, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE);
pseCheck('invalid UTF-8 is substituted instead of failing snapshot JSON', is_string($json), true);
$decoded = is_string($json) ? json_decode($json, true) : null;
pseCheck('substituted snapshot remains valid JSON', is_array($decoded) && ($decoded['ok'] ?? false) === true, true);

$source = (string)file_get_contents(__DIR__ . '/../tools/production_snapshot.php');
pseCheck('production snapshot enables invalid UTF-8 substitution', strpos($source, 'JSON_INVALID_UTF8_SUBSTITUTE') !== false, true);
pseCheck('production snapshot checks json_encode failure explicitly', strpos($source, 'production_snapshot_json_encode_failed') !== false, true);
pseCheck('production snapshot probes manager lead detail without publishing lead data', strpos($source, 'function managerLeadDetailHealth(PDO $pdo):array') !== false && strpos($source, "'manager_lead_detail_health'=>[]") !== false && strpos($source, "'manager_lead_detail_ok'=>true") !== false, true);
pseCheck('lead detail probe checks schema and components independently', strpos($source, "'lead_tasks'=>['id','conversation_id','title','due_at_utc','status','is_pinned'") !== false && strpos($source, "'pipeline'=>static fn(int\$id)=>SalesPipelineService::conversationSnapshot(\$id)") !== false && strpos($source, "'tasks'=>static fn(int\$id)=>LeadTaskService::listForConversation(\$id)") !== false && strpos($source, "'delivery_failure'=>static fn(int\$id)=>ManagerDeliveryStateService::activeFailure(\$id)") !== false, true);
pseCheck('lead detail failures expose only bounded technical identity', strpos($source, "['exception'=>get_class(\$e),'code'=>(string)\$e->getCode()]") !== false && strpos($source, "\$failure['sqlstate']") !== false && strpos($source, "\$failure['driver_code']") !== false, true);

exit($failed > 0 ? 1 : 0);
