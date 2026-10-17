<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/services/DiagnosticLogger.php';
require_once $root . '/services/StructuredLogContainment.php';

$arguments = array_slice($argv ?? [], 1);
if ($arguments !== [] && $arguments !== ['--check-only']) {
    echo json_encode(['ok'=>false,'error'=>'invalid_arguments']) . PHP_EOL;
    exit(2);
}
$active = DiagnosticLogger::filePath();
$legacy = $root . '/structured_events.log';
$checkOnly = $arguments === ['--check-only'];
$migration = $checkOnly ? ['ok'=>true] : StructuredLogContainment::contain($legacy, $active);
$written = $checkOnly || (!empty($migration['ok']) && DiagnosticLogger::log('deployment', 'structured_log_boundary_check', ['schema'=>1]));
$check = StructuredLogContainment::check($legacy, $active);
$ok = !empty($migration['ok']) && $written && !empty($check['ok']);
echo json_encode(['ok'=>$ok,'check_only'=>$checkOnly,'containment_ok'=>!empty($migration['ok']),
    'write_checked'=>!$checkOnly,'write_ok'=>$checkOnly ? null : $written] + $check, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
