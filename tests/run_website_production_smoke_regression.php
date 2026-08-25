<?php
require_once __DIR__ . '/../services/WebsiteProductionSmoke.php';

$tests = 0; $failed = 0;
function check_wps($ok, $name) { global $tests, $failed; $tests++; if ($ok) echo "PASS {$name}\n"; else { $failed++; echo "FAIL {$name}\n"; } }

$ok = WebsiteProductionSmoke::evaluate([
    'schema_ok'=>true,
    'source_ok'=>true,
    'adapter_ok'=>true,
    'handoff_evidence_ok'=>true,
]);
check_wps(!empty($ok['ok']), 'all production website checks pass');

foreach (['schema_ok','source_ok','adapter_ok','handoff_evidence_ok'] as $key) {
    $facts = [
        'schema_ok'=>true,
        'source_ok'=>true,
        'adapter_ok'=>true,
        'handoff_evidence_ok'=>true,
    ];
    $facts[$key] = false;
    $result = WebsiteProductionSmoke::evaluate($facts);
    check_wps(empty($result['ok']) && empty($result['checks'][$key]), "failure in {$key} fails smoke");
}

printf("TOTAL %d | PASS %d | FAIL %d\n", $tests, $tests-$failed, $failed);
exit($failed ? 1 : 0);
