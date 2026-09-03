<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Remove customer/operator identifiers and per-conversation evidence before a
 * diagnostic document is committed to the public diagnostics branch.
 * Detailed snapshots stay ephemeral inside the workflow that validates them.
 */
function publicDiagnosticSensitiveKey(string $key): bool
{
    $key = strtolower(trim($key));
    if ($key === '') return false;
    if (preg_match('/(^|_)(conversation|manager|actor|customer|chat|user|source)_ids?$/', $key)) return true;
    return in_array($key, [
        'text','message','payload','message_tail','messages','recent_messages',
        'sessions','flagged_sessions','requests','events','recent_events',
        'login','display_name','phone','email','username','external_chat_id',
        'external_user_id','working_managers','manager_visibility','manager_usage',
        'website_attribution','recent_manager_priority_events','recent_manager_push_events',
    ], true);
}

function sanitizePublicDiagnosticValue($value)
{
    if (!is_array($value)) return $value;
    $out = [];
    foreach ($value as $key => $item) {
        if (is_string($key) && publicDiagnosticSensitiveKey($key)) continue;
        $out[$key] = sanitizePublicDiagnosticValue($item);
    }
    return $out;
}

try {
    $path = (string)($argv[1] ?? '');
    if ($path === '' || !is_file($path) || !is_readable($path)) throw new RuntimeException('diagnostic_input_missing');
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) throw new RuntimeException('diagnostic_input_invalid_json');
    $sanitized = sanitizePublicDiagnosticValue($decoded);
    $sanitized['public_redacted'] = true;
    $json = json_encode($sanitized, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('public_diagnostic_json_encode_failed');
    echo $json . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}
