<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/services/DiagnosticLogger.php';
require_once $root . '/services/StructuredLogContainment.php';
$tmp = sys_get_temp_dir() . '/max-search-structured-log-test-' . bin2hex(random_bytes(8));
if (!mkdir($tmp, 0700)) throw new RuntimeException('cannot_create_fixture');
$passed = 0;
$failed = 0;
function structuredCheck(string $name, bool $ok): void
{
    global $passed, $failed;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . PHP_EOL;
    $ok ? $passed++ : $failed++;
}
function structuredStat(string $path): array
{
    clearstatcache(true, $path);
    return lstat($path) ?: [];
}
function structuredFixture(string $base): array
{
    mkdir($base, 0700);
    mkdir($base . '/public', 0700);
    mkdir($base . '/private', 0700);
    return [$base . '/public/structured_events.log', $base . '/private/structured_events.log'];
}
function structuredRemoveFixture(string $path): void
{
    if (is_link($path)) { @unlink($path); return; }
    if (is_file($path)) { @chmod($path, 0600); @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') structuredRemoveFixture($path . '/' . $entry);
    }
    @rmdir($path);
}

try {
    mkdir($tmp . '/logger', 0700);
    $file = $tmp . '/logger/events.log';
    DiagnosticLogger::setFile($file);
    structuredCheck('external getter creates no log file', DiagnosticLogger::filePath() === $file && !file_exists($file));
    $written = DiagnosticLogger::warning('fixture', 'bounded_event', [
        'token'=>'PRIVATE-TOKEN', 'nested'=>['password'=>'PRIVATE-PASSWORD', 'phone'=>'+7 (999) 123-45-67'],
        'long'=>str_repeat('x', 4010),
    ], 77);
    $rows = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    $row = json_decode($rows[0] ?? '', true);
    structuredCheck('event format and recursive sanitization stay compatible', $written && count($rows) === 1
        && isset($row['ts']) && $row['level'] === 'warning' && $row['component'] === 'fixture'
        && $row['event'] === 'bounded_event' && $row['chat_id'] === 77
        && $row['data']['token'] === '[redacted]' && $row['data']['nested']['password'] === '[redacted]'
        && $row['data']['nested']['phone'] === '[phone-redacted]' && strlen($row['data']['long']) === 4003
        && !str_contains(implode('', $rows), 'PRIVATE-'));
    structuredCheck('external log file is private', (structuredStat($file)['mode'] & 0777) === 0600);
    $history = str_repeat('synthetic-history|', 90000);
    file_put_contents($file, $history);
    $append = DiagnosticLogger::error('fixture', 'after_history');
    $retained = file_get_contents($file);
    structuredCheck('append keeps history larger than one MiB without rotation or truncation', $append
        && strlen($history) > 1048576 && str_starts_with($retained, $history)
        && str_contains(substr($retained, strlen($history)), 'after_history'));

    $sharedParent = sys_get_temp_dir();
    $parentMode = structuredStat($sharedParent)['mode'] & 0777;
    $sharedOverride = $sharedParent . '/structured-override-' . bin2hex(random_bytes(8)) . '.log';
    DiagnosticLogger::setFile($sharedOverride);
    structuredCheck('temporary override never chmods its shared parent', DiagnosticLogger::log('fixture', 'override')
        && (structuredStat($sharedParent)['mode'] & 0777) === $parentMode
        && (structuredStat($sharedOverride)['mode'] & 0777) === 0600);
    unlink($sharedOverride);

    foreach (['relative-events.log', $root . '/structured_events.log', $root . '/.runtime/forbidden-structured/events.log',
        $tmp . '/logger/../logger/events.log', $tmp . '/logger'] as $unsafe) {
        DiagnosticLogger::setFile($unsafe);
        structuredCheck('logger rejects unsafe path ' . basename($unsafe), DiagnosticLogger::filePath() === ''
            && DiagnosticLogger::log('fixture', 'must_not_write') === false);
    }
    $victim = $tmp . '/logger/victim.log';
    file_put_contents($victim, 'synthetic-victim');
    symlink($victim, $tmp . '/logger/link.log');
    symlink($tmp . '/logger', $tmp . '/linked-directory');
    foreach ([$tmp . '/logger/link.log', $tmp . '/linked-directory/events.log'] as $unsafe) {
        DiagnosticLogger::setFile($unsafe);
        structuredCheck('logger rejects symlink paths without touching target', DiagnosticLogger::filePath() === ''
            && DiagnosticLogger::log('fixture', 'must_not_write') === false
            && file_get_contents($victim) === 'synthetic-victim');
    }
    DiagnosticLogger::setFile('');
    $default = DiagnosticLogger::filePath();
    $expectedDefault = dirname((string)realpath($root)) . '/max-search-bot-private-logs/structured_events.log';
    structuredCheck('reset restores shared external default and private directory', $default === $expectedDefault
        && !str_starts_with($default, $root . '/') && (structuredStat(dirname($default))['mode'] & 0777) === 0700);

    [$legacy, $active] = structuredFixture($tmp . '/held-writer');
    file_put_contents($legacy, "synthetic-history\n");
    chmod($legacy, 0644);
    $old = fopen($legacy, 'ab');
    $before = structuredStat($legacy);
    $moved = StructuredLogContainment::contain($legacy, $active);
    $archives = glob(dirname($active) . '/structured_events.legacy-*.log') ?: [];
    $archive = $archives[0] ?? '';
    $heldWrite = flock($old, LOCK_EX) && fwrite($old, "held-old-writer\n") !== false;
    fflush($old);flock($old, LOCK_UN);fclose($old);
    $fence = structuredStat($legacy);
    $saved = $archive !== '' ? structuredStat($archive) : [];
    structuredCheck('atomic fence preserves original inode and held old writable descriptor', !empty($moved['ok'])
        && !empty($moved['archive_created']) && !empty($moved['active_seeded']) && count($archives) === 1 && $heldWrite
        && $fence['size'] === 0 && ($fence['mode'] & 0777) === 0000 && $fence['ino'] !== $before['ino']
        && $saved['dev'] === $before['dev'] && $saved['ino'] === $before['ino'] && ($saved['mode'] & 0777) === 0600
        && file_get_contents($archive) === "synthetic-history\nheld-old-writer\n"
        && file_get_contents($active) === "synthetic-history\nheld-old-writer\n");
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        $futureOldWriter = @fopen($legacy, 'ab');
        structuredCheck('future unprivileged old writer cannot open mode-zero fence', $futureOldWriter === false);
        if (is_resource($futureOldWriter)) fclose($futureOldWriter);
    } else {
        echo "SKIP  future old writer permission check requires a non-root effective user\n";
    }
    DiagnosticLogger::setFile($active);
    structuredCheck('new logger appends to preserved private history', DiagnosticLogger::log('fixture', 'after_containment')
        && str_starts_with((string)file_get_contents($active), "synthetic-history\nheld-old-writer\n")
        && StructuredLogContainment::check($legacy, $active)['ok']);
    $activeBefore = file_get_contents($active);
    $again = StructuredLogContainment::contain($legacy, $active);
    structuredCheck('existing empty mode-zero fence is idempotent with no new archive or history change', !empty($again['ok'])
        && !$again['archive_created'] && !$again['active_seeded']
        && count(glob(dirname($active) . '/structured_events.legacy-*.log') ?: []) === 1
        && structuredStat($legacy)['ino'] === $fence['ino'] && file_get_contents($active) === $activeBefore);
    $checkBefore = glob(dirname($active) . '/*') ?: [];
    structuredCheck('check-only leaves active history and archive inventory unchanged', StructuredLogContainment::check($legacy, $active)['ok']
        && file_get_contents($active) === $activeBefore && (glob(dirname($active) . '/*') ?: []) === $checkBefore);

    [$legacy2, $active2] = structuredFixture($tmp . '/existing-private');
    file_put_contents($legacy2, "older-public-history\n");
    file_put_contents($active2, "newer-private-history\n");chmod($active2, 0600);
    $preservedActive = structuredStat($active2);
    $existing = StructuredLogContainment::contain($legacy2, $active2);
    $olderArchives = glob(dirname($active2) . '/structured_events.legacy-*.log') ?: [];
    structuredCheck('existing private active data is never overwritten or merged', !empty($existing['ok'])
        && !$existing['active_seeded'] && count($olderArchives) === 1
        && structuredStat($active2)['ino'] === $preservedActive['ino']
        && file_get_contents($active2) === "newer-private-history\n"
        && file_get_contents($olderArchives[0]) === "older-public-history\n");

    [$interruptedLegacy, $interruptedActive] = structuredFixture($tmp . '/interrupted-migration');
    file_put_contents($interruptedLegacy, "history-before-interruption\n");
    $interruptedStat = structuredStat($interruptedLegacy);
    $previousArchive = dirname($interruptedActive) . '/structured_events.legacy-interrupted.log';
    if (!link($interruptedLegacy, $previousArchive)) throw new RuntimeException('cannot_prepare_interrupted_fixture');
    $resumed = StructuredLogContainment::contain($interruptedLegacy, $interruptedActive);
    $resumedArchives = glob(dirname($interruptedActive) . '/structured_events.legacy-*.log') ?: [];
    $everyArchivePreserved = count($resumedArchives) === 2;
    foreach ($resumedArchives as $retainedArchive) {
        $retainedStat = structuredStat($retainedArchive);
        $everyArchivePreserved = $everyArchivePreserved && $retainedStat['dev'] === $interruptedStat['dev']
            && $retainedStat['ino'] === $interruptedStat['ino'] && ($retainedStat['mode'] & 0777) === 0600
            && file_get_contents($retainedArchive) === "history-before-interruption\n";
    }
    structuredCheck('migration resumes after prior hardlink archive without deleting either history link', !empty($resumed['ok'])
        && $everyArchivePreserved && is_file($previousArchive)
        && file_get_contents($interruptedActive) === "history-before-interruption\n"
        && StructuredLogContainment::check($interruptedLegacy, $interruptedActive)['ok']);

    [$absentLegacy, $absentActive] = structuredFixture($tmp . '/absent-legacy');
    $absent = StructuredLogContainment::contain($absentLegacy, $absentActive);
    structuredCheck('absent legacy name receives empty mode-zero fence without fabricated history', !empty($absent['ok'])
        && !$absent['archive_created'] && structuredStat($absentLegacy)['size'] === 0
        && (structuredStat($absentLegacy)['mode'] & 0777) === 0000 && !file_exists($absentActive));
    DiagnosticLogger::setFile($absentActive);
    structuredCheck('private writer initializes after absent legacy containment', DiagnosticLogger::log('fixture', 'first_private_event')
        && StructuredLogContainment::check($absentLegacy, $absentActive)['ok']);

    [$badLegacy, $badActive] = structuredFixture($tmp . '/unsafe-containment');
    file_put_contents($badLegacy, 'retain-on-failure');
    symlink($victim, $badActive);
    structuredCheck('containment rejects active symlink and keeps legacy and victim unchanged', !StructuredLogContainment::contain($badLegacy, $badActive)['ok']
        && file_get_contents($badLegacy) === 'retain-on-failure' && file_get_contents($victim) === 'synthetic-victim');
    unlink($badActive);unlink($badLegacy);symlink($victim, $badLegacy);
    structuredCheck('containment rejects legacy symlink without unlinking or changing its target', !StructuredLogContainment::contain($badLegacy, $badActive)['ok']
        && is_link($badLegacy) && file_get_contents($victim) === 'synthetic-victim');
    unlink($badLegacy);file_put_contents($badLegacy, 'unexpected-nonempty-fence');chmod($badLegacy, 0000);
    structuredCheck('unexpected nonempty mode-zero fence fails without losing data', !StructuredLogContainment::contain($badLegacy, $badActive)['ok']
        && structuredStat($badLegacy)['size'] === strlen('unexpected-nonempty-fence'));
    chmod($badLegacy, 0600);unlink($badLegacy);mkdir($badLegacy, 0700);
    structuredCheck('nonregular legacy path is rejected', !StructuredLogContainment::contain($badLegacy, $badActive)['ok'] && is_dir($badLegacy));
    rmdir($badLegacy);file_put_contents($badLegacy, 'retain-private-boundary');chmod(dirname($badActive), 0755);
    structuredCheck('unsafe private directory fails before archive or legacy replacement', !StructuredLogContainment::contain($badLegacy, $badActive)['ok']
        && file_get_contents($badLegacy) === 'retain-private-boundary'
        && count(glob(dirname($badActive) . '/structured_events.legacy-*.log') ?: []) === 0);
    chmod(dirname($badActive), 0700);
    structuredCheck('relative and public active destinations are rejected', !StructuredLogContainment::contain($badLegacy, 'relative.log')['ok']
        && !StructuredLogContainment::contain($badLegacy, $root . '/structured_events.log')['ok']);

    $smoke = (string)file_get_contents($root . '/tools/structured_runtime_log_smoke.php');
    structuredCheck('deployment tool is CLI-only and supports immutable later check-only gate', str_contains($smoke, "PHP_SAPI !== 'cli'")
        && str_contains($smoke, "'--check-only'") && str_contains($smoke, 'DiagnosticLogger::filePath()')
        && str_contains($smoke, 'StructuredLogContainment::contain($legacy, $active)')
        && str_contains($smoke, 'exit($ok ? 0 : 1)'));
} finally {
    DiagnosticLogger::setFile('');
    structuredRemoveFixture($tmp);
}

echo 'TOTAL ' . ($passed + $failed) . " | PASS {$passed} | FAIL {$failed}\n";
exit($failed > 0 ? 1 : 0);
