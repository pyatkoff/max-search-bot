<?php

declare(strict_types=1);

/**
 * One-time maintenance helper.
 * Removes methods from MaxSearchBase only when MaxSearchApi already owns the
 * public compatibility implementation. The parser works on PHP tokens and
 * deletes complete method bodies, so nested braces/closures are handled safely.
 */

$root = dirname(__DIR__);
$file = $root . '/maxsearchbaseclass.php';

$remove = [
    // Conversation state.
    'getCurentStatus', 'setStatus', 'deletePrevMessage', 'deleteAllStatus',
    'saveLastValue', 'getLastValue', 'getSavedData', 'upsertStatusValue',

    // Claims / leads.
    'saveClaim', 'getLatestClaimLink', 'getLastClaimForChat', 'getClaimByCode', 'savePhone',

    // Post-tour compatibility.
    'showChannelOffer',

    // AI context.
    'getAiSearchContext', 'getAiMissingFields', 'applyAiParameters',

    // Traffic / analytics.
    'trafficFile', 'saveTrafficMeta', 'getTrafficMeta', 'buildChannelMiniappUrl',
    'funnelLog', 'queueMetrikaGoal',

    // MAX transport.
    'MaxRequest', 'MaxRequestJson', 'MaxSend', 'MaxSendWithButtons',
    'MaxSendWithMenuButtons', 'answerCallback', 'maxLog',

    // Follow-up queue.
    'followupDir', 'scheduleToursFollowup', 'cancelToursFollowup',

    // Travel directories.
    'getCityByID', 'getCityFromByID', 'getCityByName',
    'getCountryByID', 'getCountryByName', 'getMealArr',
];

$source = file_get_contents($file);
if ($source === false) {
    fwrite(STDERR, "Cannot read {$file}\n");
    exit(2);
}

$tokens = token_get_all($source);
$offsets = [];
$pos = 0;
foreach ($tokens as $i => $token) {
    $text = is_array($token) ? $token[1] : $token;
    $offsets[$i] = $pos;
    $pos += strlen($text);
}
$offsets[count($tokens)] = $pos;

$ranges = [];
$count = count($tokens);
for ($i = 0; $i < $count; $i++) {
    $token = $tokens[$i];
    if (!is_array($token) || $token[0] !== T_FUNCTION) continue;

    // Find the named function token (skip whitespace and optional &).
    $j = $i + 1;
    while ($j < $count) {
        $t = $tokens[$j];
        if (is_array($t) && $t[0] === T_WHITESPACE) { $j++; continue; }
        if ($t === '&') { $j++; continue; }
        break;
    }
    if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) continue;

    $name = $tokens[$j][1];
    if (!in_array($name, $remove, true)) continue;

    // Walk backwards to include visibility/static/final/abstract and indentation.
    $start = $i;
    for ($k = $i - 1; $k >= 0; $k--) {
        $t = $tokens[$k];
        if (is_array($t) && in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT, T_WHITESPACE], true)) {
            $start = $k;
            // Stop at a newline that belongs to previous statement, keeping just one blank separator.
            if ($t[0] === T_WHITESPACE && substr_count($t[1], "\n") >= 2) break;
            continue;
        }
        break;
    }

    // Find opening body brace and matching closing brace.
    $body = $j;
    while ($body < $count && $tokens[$body] !== '{' && $tokens[$body] !== ';') $body++;
    if ($body >= $count) continue;

    if ($tokens[$body] === ';') {
        $end = $body + 1;
    } else {
        $depth = 0;
        $end = null;
        for ($k = $body; $k < $count; $k++) {
            if ($tokens[$k] === '{') $depth++;
            if ($tokens[$k] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $k + 1;
                    break;
                }
            }
        }
        if ($end === null) {
            fwrite(STDERR, "Cannot find end of {$name}\n");
            exit(3);
        }
    }

    $ranges[] = [
        'name' => $name,
        'start' => $offsets[$start],
        'end' => $offsets[$end],
    ];
}

if (!$ranges) {
    echo "No legacy methods to prune.\n";
    exit(0);
}

// Remove from the end so byte offsets remain valid.
usort($ranges, static fn(array $a, array $b): int => $b['start'] <=> $a['start']);
foreach ($ranges as $range) {
    $source = substr($source, 0, $range['start']) . "\n" . substr($source, $range['end']);
}

file_put_contents($file, $source);

// Validate syntax before reporting success.
$cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1';
exec($cmd, $out, $code);
if ($code !== 0) {
    fwrite(STDERR, implode("\n", $out) . "\n");
    exit(4);
}

echo 'Pruned ' . count($ranges) . " methods:\n";
foreach (array_reverse($ranges) as $range) echo ' - ' . $range['name'] . "\n";
