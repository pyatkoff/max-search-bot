<?php
declare(strict_types=1);

/** One required suite over the real flat app classes and immutable synthetic fixtures. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
function max2SuiteNeed(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException('MAX2_V2_FIXTURE_' . $reason);
}
function max2SuiteRemove(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $item) if (!$item->isDot()) max2SuiteRemove($item->getPathname());
    rmdir($path);
}
function max2SuiteRelative($name): bool {
    return is_string($name) && preg_match('~\A[A-Za-z0-9_./-]+\z~D', $name) === 1
        && !str_starts_with($name, '/') && !in_array('..', explode('/', $name), true);
}
$app = dirname(__DIR__);
$release = json_decode((string)file_get_contents($app . '/resources/max2-common-v2/release-manifest.json'),
    true, 64, JSON_THROW_ON_ERROR);
max2SuiteNeed(($release['schema'] ?? null) === 'max2-common-v2-app-source-v1'
    && ($release['counter_id'] ?? null) === 98615635 && ($release['goal_ids'] ?? null) === [687931224,687941321]
    && ($release['initial_mode'] ?? null) === 'off' && is_array($release['files'] ?? null), 'RELEASE_MANIFEST');
foreach ($release['files'] as $relative => $sha) {
    max2SuiteNeed(max2SuiteRelative($relative) && is_string($sha) && preg_match('/\A[a-f0-9]{64}\z/D', $sha) === 1,
        'RELEASE_PIN');
    max2SuiteNeed(is_file($app . '/' . $relative) && !is_link($app . '/' . $relative)
        && hash_file('sha256', $app . '/' . $relative) === $sha, 'RELEASE_SOURCE_' . $relative);
}
$manifestPath = __DIR__ . '/max2_common_v2/fixtures.json';
$manifest = json_decode((string)file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
max2SuiteNeed(($manifest['schema'] ?? null) === 'max2-common-v2-coherent-fixtures-v1'
    && is_array($manifest['files'] ?? null) && is_array($manifest['assets'] ?? null)
    && is_array($manifest['entrypoints'] ?? null) && count($manifest['entrypoints']) >= 13, 'MANIFEST_REQUIRED');
foreach (['pdo_sqlite', 'openssl'] as $extension) max2SuiteNeed(extension_loaded($extension), 'EXTENSION_' . $extension);
$before = [];
foreach ($manifest['files'] + $manifest['assets'] as $relative => $sha) {
    max2SuiteNeed(max2SuiteRelative($relative) && is_string($sha) && preg_match('/\A[a-f0-9]{64}\z/D', $sha) === 1,
        'PIN_SHAPE');
    $path = $app . '/' . $relative;
    max2SuiteNeed(is_file($path) && !is_link($path) && hash_file('sha256', $path) === $sha, 'EXACT_SOURCE_' . $relative);
    $before[$relative] = $sha;
}
$canonical = $manifest['canonical_fixture'] ?? null;
max2SuiteNeed(is_array($canonical) && ($canonical['path'] ?? null) === 'tests/run_modeled_conversion_value_export_regression.php',
    'CANONICAL_FIXTURE_PATH');
$canonicalRaw = file_get_contents($app . '/' . $canonical['path']);
max2SuiteNeed(is_string($canonicalRaw) && hash('sha1', 'blob ' . strlen($canonicalRaw) . "\0" . $canonicalRaw) === ($canonical['git_blob'] ?? null),
    'CANONICAL_FIXTURE_BYTES');

$temp = sys_get_temp_dir() . '/max2-common-v2-suite-' . bin2hex(random_bytes(12));
max2SuiteNeed(mkdir($temp, 0700) && mkdir($temp . '/tests', 0700) && mkdir($temp . '/tests/max2_common_v2', 0700),
    'PRIVATE_TEMP');
register_shutdown_function(static function() use ($temp): void { max2SuiteRemove($temp); });
foreach (['services', 'migrations', 'resources', 'tools'] as $name) {
    max2SuiteNeed(is_dir($app . '/' . $name) && symlink($app . '/' . $name, $temp . '/' . $name), 'REAL_APP_' . $name);
}
max2SuiteNeed(symlink($app . '/' . $canonical['path'], $temp . '/' . $canonical['path']), 'CANONICAL_FIXTURE_VIEW');
foreach ($manifest['files'] as $relative => $sha) {
    max2SuiteNeed(str_starts_with($relative, 'tests/max2_common_v2/'), 'FIXTURE_SCOPE');
    $path = $temp . '/' . $relative;
    if (!is_dir(dirname($path))) max2SuiteNeed(mkdir(dirname($path), 0700, true), 'FIXTURE_PARENT');
    max2SuiteNeed(copy($app . '/' . $relative, $path) && hash_file('sha256', $path) === $sha, 'FIXTURE_COPY');
}
$base = $temp . '/tests/max2_common_v2';
$groups = [];
foreach (array_keys($manifest['files']) as $relative) $groups[explode('/', $relative)[2]] = true;
foreach (array_keys($groups) as $group) {
    max2SuiteNeed(preg_match('/\A[a-z]+\z/D', $group) === 1, 'GROUP_NAME');
    max2SuiteNeed(symlink($app . '/services', $base . '/' . $group . '/services'), 'FLAT_SERVICE_VIEW');
    if (in_array($group, ['ledger', 'worker'], true)) {
        max2SuiteNeed(symlink($app . '/migrations', $base . '/' . $group . '/migrations'), 'ACTUAL_MIGRATION_VIEW');
    }
}
max2SuiteNeed(symlink($app . '/resources/max2-common-v2', $base . '/map'), 'ACTUAL_MAP_VIEW');

$summaries = [];
$disabled = 'curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail';
foreach ($manifest['entrypoints'] as $entrypoint) {
    max2SuiteNeed(max2SuiteRelative($entrypoint) && isset($manifest['files']['tests/max2_common_v2/' . $entrypoint]),
        'ENTRYPOINT_PIN');
    $output = $temp . '/stdout'; $error = $temp . '/stderr';
    $command = ['timeout', '90s', PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0',
        '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0', '-d', 'disable_functions=' . $disabled,
        $base . '/' . $entrypoint];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $output, 'w'],
        2 => ['file', $error, 'w']], $pipes, $temp, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    max2SuiteNeed(is_resource($process), 'SUBPROCESS');
    $exit = proc_close($process);
    $stdout = (string)file_get_contents($output); $stderr = (string)file_get_contents($error);
    max2SuiteNeed(strlen($stdout) <= 131072 && strlen($stderr) <= 131072, 'OUTPUT_BOUND');
    if ($exit !== 0) {
        fwrite(STDERR, 'MAX2 V2 suite failed: ' . $entrypoint . "\n" . $stdout . $stderr);
        throw new RuntimeException('MAX2_V2_FIXTURE_CHILD_FAILED');
    }
    max2SuiteNeed($stderr === '', 'UNEXPECTED_STDERR_' . $entrypoint);
    $summaries[] = ['fixture' => $entrypoint, 'status' => 'PASS', 'output_sha256' => hash('sha256', $stdout)];
}
foreach ($before as $relative => $sha) max2SuiteNeed(hash_file('sha256', $app . '/' . $relative) === $sha,
    'SOURCE_PRESERVED_' . $relative);
max2SuiteNeed(file_get_contents($app . '/' . $canonical['path']) === $canonicalRaw, 'OLD_CANONICAL_FIXTURE_PRESERVED');
max2SuiteRemove($temp);
echo json_encode(['status' => 'PASS', 'fixture_suites' => count($summaries), 'suites' => $summaries,
    'implementation' => 'actual_flat_app_services', 'fixture_database' => 'synthetic_only',
    'live_database_connections' => 0, 'provider_calls' => 0, 'activation' => false,
    'old_sender_invoked' => false], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
