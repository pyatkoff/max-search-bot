<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$inventoryPath = $root . '/docs/handoff-policy-inventory.json';
$inventory = json_decode((string)file_get_contents($inventoryPath), true);
if (!is_array($inventory)) {
    fwrite(STDERR, "handoff_policy_inventory_invalid_json\n");
    exit(2);
}

$policy = $inventory['policy'] ?? null;
$classifications = $inventory['classifications'] ?? null;
$monitoredCalls = $inventory['monitored_calls'] ?? null;
$configuredCallers = $inventory['callers'] ?? null;
$policySurfaces = $inventory['policy_surfaces'] ?? null;
if (($inventory['schema_version'] ?? null) !== 1
    || !is_array($policy)
    || !is_array($classifications)
    || !is_array($monitoredCalls)
    || !is_array($configuredCallers)
    || !is_array($policySurfaces)) {
    fwrite(STDERR, "handoff_policy_inventory_invalid_schema\n");
    exit(2);
}

$errors = [];
$monitored = [];
foreach ($monitoredCalls as $call) {
    $receiver = trim((string)($call['receiver'] ?? ''));
    $method = trim((string)($call['method'] ?? ''));
    if ($receiver === '' || $method === '') {
        $errors[] = 'invalid_monitored_call';
        continue;
    }
    $key = $receiver . '::' . $method;
    if (isset($monitored[$key])) {
        $errors[] = 'duplicate_monitored_call:' . $key;
    }
    $monitored[$key] = true;
}

$excludedTopLevel = ['.git' => true, 'tests' => true, 'tools' => true, 'vendor' => true];
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $topLevel = explode('/', $relative, 2)[0];
    if (!isset($excludedTopLevel[$topLevel])) $files[$relative] = $file->getPathname();
}
ksort($files);

$actual = [];
foreach ($files as $relative => $path) {
    $tokens = token_get_all((string)file_get_contents($path));
    for ($index = 0, $count = count($tokens); $index < $count; $index++) {
        $receiverToken = $tokens[$index];
        if (!is_array($receiverToken) || !in_array($receiverToken[0], handoffReceiverTokenIds(), true)) continue;
        $doubleColonIndex = handoffNextCodeTokenIndex($tokens, $index + 1);
        if ($doubleColonIndex === null || handoffTokenText($tokens[$doubleColonIndex]) !== '::') continue;
        $methodIndex = handoffNextCodeTokenIndex($tokens, $doubleColonIndex + 1);
        if ($methodIndex === null || !is_array($tokens[$methodIndex])) continue;
        $receiver = handoffTokenText($receiverToken);
        $method = handoffTokenText($tokens[$methodIndex]);
        if (!isset($monitored[$receiver . '::' . $method])) continue;
        $openIndex = handoffNextCodeTokenIndex($tokens, $methodIndex + 1);
        if ($openIndex === null || handoffTokenText($tokens[$openIndex]) !== '(') continue;
        $key = handoffCallerKey($relative, $receiver, $method);
        if (!isset($actual[$key])) {
            $actual[$key] = ['path'=>$relative, 'receiver'=>$receiver, 'method'=>$method, 'occurrences'=>0, 'lines'=>[]];
        }
        $actual[$key]['occurrences']++;
        $actual[$key]['lines'][] = (int)$receiverToken[2];
    }
}
ksort($actual);

$configured = [];
foreach ($configuredCallers as $caller) {
    if (!is_array($caller)) {
        $errors[] = 'invalid_configured_caller';
        continue;
    }
    $path = (string)($caller['path'] ?? '');
    $receiver = (string)($caller['receiver'] ?? '');
    $method = (string)($caller['method'] ?? '');
    $classification = (string)($caller['classification'] ?? '');
    $occurrences = (int)($caller['occurrences'] ?? 0);
    $rationale = trim((string)($caller['rationale'] ?? ''));
    $key = handoffCallerKey($path, $receiver, $method);
    if ($path === '' || !isset($monitored[$receiver . '::' . $method])
        || !in_array($classification, $classifications, true) || $occurrences < 1 || $rationale === '') {
        $errors[] = 'invalid_configured_caller:' . $key;
        continue;
    }
    if (isset($configured[$key])) $errors[] = 'duplicate_configured_caller:' . $key;
    $configured[$key] = $caller;
}
ksort($configured);

foreach ($actual as $key => $caller) {
    if (!isset($configured[$key])) {
        $errors[] = 'unclassified_handoff_caller:' . $key;
        continue;
    }
    if ((int)$configured[$key]['occurrences'] !== (int)$caller['occurrences']) {
        $errors[] = sprintf('handoff_occurrence_drift:%s:expected=%d:actual=%d', $key, $configured[$key]['occurrences'], $caller['occurrences']);
    }
}
foreach ($configured as $key => $_caller) {
    if (!isset($actual[$key])) $errors[] = 'missing_handoff_caller:' . $key;
}

foreach ($policySurfaces as $surface) {
    if (!is_array($surface)) {
        $errors[] = 'invalid_policy_surface';
        continue;
    }
    $path = (string)($surface['path'] ?? '');
    $classification = (string)($surface['classification'] ?? '');
    $responsibility = trim((string)($surface['responsibility'] ?? ''));
    $markers = $surface['required_markers'] ?? null;
    if ($path === '' || !in_array($classification, $classifications, true) || $responsibility === '' || !is_array($markers) || $markers === []) {
        $errors[] = 'invalid_policy_surface:' . $path;
        continue;
    }
    $sourcePath = $root . '/' . $path;
    if (!is_file($sourcePath)) {
        $errors[] = 'missing_policy_surface:' . $path;
        continue;
    }
    $source = (string)file_get_contents($sourcePath);
    foreach ($markers as $marker) {
        if (!is_string($marker) || $marker === '' || !str_contains($source, $marker)) {
            $errors[] = 'policy_surface_marker_missing:' . $path . ':' . (string)$marker;
        }
    }
}

$callers = [];
foreach ($actual as $key => $caller) {
    $callers[] = $caller + [
        'classification'=>$configured[$key]['classification'] ?? null,
        'rationale'=>$configured[$key]['rationale'] ?? null,
    ];
}
$result = [
    'ok'=>$errors === [],
    'schema_version'=>1,
    'policy'=>$policy,
    'summary'=>[
        'caller_groups'=>count($actual),
        'occurrences'=>array_sum(array_column($actual, 'occurrences')),
        'policy_surfaces'=>count($policySurfaces),
        'unclassified_or_drifted'=>count($errors),
    ],
    'errors'=>$errors,
    'callers'=>$callers,
    'policy_surfaces'=>$policySurfaces,
];

if (in_array('--json', $argv, true)) {
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} elseif ($errors === []) {
    printf("HANDOFF POLICY INVENTORY: OK (%d caller groups, %d occurrences, %d surfaces)\n", count($actual), $result['summary']['occurrences'], count($policySurfaces));
} else {
    foreach ($errors as $error) fwrite(STDERR, "FAIL: {$error}\n");
    fwrite(STDERR, "HANDOFF POLICY INVENTORY: FAIL\n");
}

exit($errors === [] ? 0 : 1);

function handoffReceiverTokenIds(): array
{
    $ids = [T_STRING, T_STATIC];
    foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $constant) {
        if (defined($constant)) $ids[] = constant($constant);
    }
    return array_values(array_unique($ids));
}

function handoffNextCodeTokenIndex(array $tokens, int $start): ?int
{
    $ignored = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    for ($index = $start, $count = count($tokens); $index < $count; $index++) {
        if (is_array($tokens[$index]) && in_array($tokens[$index][0], $ignored, true)) continue;
        return $index;
    }
    return null;
}

function handoffTokenText(array|string $token): string
{
    return is_array($token) ? (string)$token[1] : $token;
}

function handoffCallerKey(string $path, string $receiver, string $method): string
{
    return $path . '|' . $receiver . '::' . $method;
}
