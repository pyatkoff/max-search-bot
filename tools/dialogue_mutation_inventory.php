<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$inventoryPath = $root . '/docs/dialogue-mutation-inventory.json';
$inventory = json_decode((string)file_get_contents($inventoryPath), true);
if (!is_array($inventory)) {
    fwrite(STDERR, "dialogue_mutation_inventory_invalid_json\n");
    exit(2);
}

$requiredMethods = ['setStatus', 'saveLastValue', 'upsertStatusValue', 'deleteAll', 'applyAiParameters'];
$monitoredMethods = $inventory['monitored_methods'] ?? null;
$classifications = $inventory['classifications'] ?? null;
$configuredCallers = $inventory['callers'] ?? null;
if (($inventory['schema_version'] ?? null) !== 1
    || !is_array($monitoredMethods)
    || !is_array($classifications)
    || !is_array($configuredCallers)
    || array_diff($requiredMethods, $inventory['required_methods'] ?? []) !== []) {
    fwrite(STDERR, "dialogue_mutation_inventory_invalid_schema\n");
    exit(2);
}

$excludedTopLevel = ['.git' => true, 'tests' => true, 'tools' => true, 'vendor' => true];
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $topLevel = explode('/', $relative, 2)[0];
    if (!isset($excludedTopLevel[$topLevel])) {
        $files[$relative] = $file->getPathname();
    }
}
ksort($files);

$actual = [];
foreach ($files as $relative => $path) {
    $tokens = token_get_all((string)file_get_contents($path));
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $receiverToken = $tokens[$index];
        if (!is_array($receiverToken) || !in_array($receiverToken[0], receiverTokenIds(), true)) {
            continue;
        }
        $doubleColonIndex = nextCodeTokenIndex($tokens, $index + 1);
        if ($doubleColonIndex === null || tokenText($tokens[$doubleColonIndex]) !== '::') {
            continue;
        }
        $methodIndex = nextCodeTokenIndex($tokens, $doubleColonIndex + 1);
        if ($methodIndex === null || !is_array($tokens[$methodIndex])) {
            continue;
        }
        $method = tokenText($tokens[$methodIndex]);
        if (!in_array($method, $monitoredMethods, true)) {
            continue;
        }
        $openIndex = nextCodeTokenIndex($tokens, $methodIndex + 1);
        if ($openIndex === null || tokenText($tokens[$openIndex]) !== '(') {
            continue;
        }
        $receiver = tokenText($receiverToken);
        $key = callerKey($relative, $receiver, $method);
        if (!isset($actual[$key])) {
            $actual[$key] = [
                'path' => $relative,
                'receiver' => $receiver,
                'method' => $method,
                'occurrences' => 0,
                'lines' => [],
            ];
        }
        $actual[$key]['occurrences']++;
        $actual[$key]['lines'][] = (int)$receiverToken[2];
    }
}
ksort($actual);

$configured = [];
$errors = [];
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
    $key = callerKey($path, $receiver, $method);
    if ($path === '' || $receiver === '' || !in_array($method, $monitoredMethods, true)
        || !in_array($classification, $classifications, true) || $occurrences < 1 || $rationale === '') {
        $errors[] = 'invalid_configured_caller:' . $key;
        continue;
    }
    if (isset($configured[$key])) {
        $errors[] = 'duplicate_configured_caller:' . $key;
        continue;
    }
    $configured[$key] = $caller;
}
ksort($configured);

foreach ($actual as $key => $caller) {
    if (!isset($configured[$key])) {
        $errors[] = 'unclassified_writer:' . $key;
        continue;
    }
    if ((int)$configured[$key]['occurrences'] !== (int)$caller['occurrences']) {
        $errors[] = sprintf(
            'occurrence_drift:%s:expected=%d:actual=%d',
            $key,
            (int)$configured[$key]['occurrences'],
            (int)$caller['occurrences']
        );
    }
}
foreach ($configured as $key => $_caller) {
    if (!isset($actual[$key])) {
        $errors[] = 'missing_writer:' . $key;
    }
}
foreach ($requiredMethods as $method) {
    if (!array_filter($actual, static fn(array $caller): bool => $caller['method'] === $method)) {
        $errors[] = 'required_method_without_caller:' . $method;
    }
}

$callers = [];
foreach ($actual as $key => $caller) {
    $callers[] = $caller + [
        'classification' => $configured[$key]['classification'] ?? null,
        'rationale' => $configured[$key]['rationale'] ?? null,
    ];
}
$result = [
    'ok' => $errors === [],
    'schema_version' => 1,
    'summary' => [
        'caller_groups' => count($actual),
        'occurrences' => array_sum(array_column($actual, 'occurrences')),
        'unclassified_or_drifted' => count($errors),
    ],
    'errors' => $errors,
    'callers' => $callers,
];

if (in_array('--json', $argv, true)) {
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} elseif ($errors === []) {
    printf("DIALOGUE MUTATION INVENTORY: OK (%d caller groups, %d occurrences)\n", count($actual), $result['summary']['occurrences']);
} else {
    foreach ($errors as $error) {
        fwrite(STDERR, "FAIL: {$error}\n");
    }
    fwrite(STDERR, "DIALOGUE MUTATION INVENTORY: FAIL\n");
}

exit($errors === [] ? 0 : 1);

function receiverTokenIds(): array
{
    $ids = [T_STRING, T_STATIC];
    foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $constant) {
        if (defined($constant)) {
            $ids[] = constant($constant);
        }
    }
    return array_values(array_unique($ids));
}

function nextCodeTokenIndex(array $tokens, int $start): ?int
{
    $ignored = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    for ($index = $start, $count = count($tokens); $index < $count; $index++) {
        if (is_array($tokens[$index]) && in_array($tokens[$index][0], $ignored, true)) {
            continue;
        }
        return $index;
    }
    return null;
}

function tokenText(array|string $token): string
{
    return is_array($token) ? (string)$token[1] : $token;
}

function callerKey(string $path, string $receiver, string $method): string
{
    return $path . '|' . $receiver . '::' . $method;
}
