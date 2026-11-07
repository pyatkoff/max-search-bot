<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/deployment_runtime_fingerprint.php';
require_once dirname(__DIR__) . '/modules/tour-selections/deploy.php';

// Every input lives under a newly-created synthetic tree. No production config,
// credentials, database, customer records, HTTP or provider calls are used.
$checks = 0;
function fingerprintCheck(bool $condition, string $label): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
}
function fingerprintWrite(string $path, string $bytes): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    if (file_put_contents($path, $bytes) !== strlen($bytes)) throw new RuntimeException('fixture_write_failed');
    chmod($path, 0600);
}
function fingerprintJson(string $path, array $value): void
{
    // Deployment receipts preserve insertion order; native acceptedPrior uses
    // strict array comparisons for its exact acceptance/operation contracts.
    fingerprintWrite($path, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
}
function fingerprintCommand(array $command, string $root, string $input = ''): array
{
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $root,
        ['PATH' => '/usr/bin:/bin', 'LANG' => 'C', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null']);
    if (!is_resource($process)) throw new RuntimeException('fixture_command_unavailable');
    if ($input !== '') fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $out, $error];
}
function fingerprintGit(string $root, array $args): string
{
    [$code, $out] = fingerprintCommand(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'user.name=Synthetic fixture', '-c', 'user.email=fixture@example.invalid', ...$args], $root);
    if ($code !== 0) throw new RuntimeException('fixture_git_failed');
    return trim($out);
}
function fingerprintRemove(string $path): void
{
    if (is_link($path) || !is_dir($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) fingerprintRemove($entry->getPathname());
    rmdir($path);
}
function fingerprintTree(string $root): array
{
    $rows = [];
    $walk = static function (string $directory) use (&$walk, &$rows, $root): void {
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            $path = $entry->getPathname(); $name = substr($path, strlen($root) + 1);
            if ($entry->isLink()) $rows[$name] = ['link', readlink($path)];
            elseif ($entry->isDir()) { $rows[$name] = ['dir', fileperms($path) & 0777]; $walk($path); }
            else $rows[$name] = ['file', hash_file('sha256', $path), fileperms($path) & 0777];
        }
    };
    $walk($root); ksort($rows, SORT_STRING); return $rows;
}
function fingerprintEditor(array $options, string $kind = 'normal', string $suffix = ''): string
{
    $root = $options['root']; $releases = $options['editor-releases'];
    $files = [];
    foreach (ManagerTourSelectionEditorHost::FILES as $name) {
        $content = 'synthetic editor ' . $name . ($name === 'editor.js' ? $suffix : '') . "\n";
        $files[$name] = ['sha256' => hash('sha256', $content), 'bytes' => strlen($content)];
    }
    $release = ['schema' => 1, 'name' => 'anytour-selection-editor', 'version' => $suffix === '' ? '1.0.0' : '1.0.1', 'contract' => 'tour-selection-editor-host-v1', 'files' => $files];
    $release['package_id'] = hash('sha256', ManagerTourSelectionEditorHost::encoded($release));
    $backend = ManagerTourSelectionEditorHost::fingerprint($root);
    $target = $releases . '/' . $release['package_id'] . '-' . $backend;
    foreach (ManagerTourSelectionEditorHost::FILES as $name) fingerprintWrite($target . '/' . $name, 'synthetic editor ' . $name . ($name === 'editor.js' ? $suffix : '') . "\n");
    fingerprintJson($target . '/release.json', $release);
    $hashes = [];
    foreach ([...ManagerTourSelectionEditorHost::FILES, 'release.json'] as $name) $hashes[$name] = hash_file('sha256', $target . '/' . $name);
    $source = $kind === 'normal' ? $options['application-sha'] : str_repeat('b', 40);
    $seal = ['contract' => ManagerTourSelectionEditorHost::CONTRACT, 'origin' => ManagerTourSelectionEditorHost::ORIGIN,
        'package_id' => $release['package_id'], 'fingerprint' => $backend, 'source' => $source, 'source_tree' => str_repeat('c', 40),
        'archive_sha256' => hash('sha256', 'synthetic archive'), 'payload_hashes' => $hashes];
    if ($kind !== 'normal') {
        $seal += ['release_kind' => $kind, 'backend_source' => $options['application-sha'], 'operation' => 'synthetic_0001'];
        fingerprintJson($releases . '/operations/synthetic_0001.json', ['state' => 'activated', 'package_id' => $release['package_id'], 'source' => $source]);
    }
    fingerprintJson($target . '/deployment.json', $seal);
    fingerprintJson($target . '/accepted.json', ['schema' => 1, 'package_id' => $release['package_id'], 'seal_sha256' => hash_file('sha256', $target . '/deployment.json')]);
    fingerprintJson($releases . '/active.json', $seal);
    if (is_link($releases . '/current')) unlink($releases . '/current');
    symlink($target, $releases . '/current');
    fingerprintWrite($options['public-site'] . '/editor.php', ManagerTourSelectionEditorHost::front($root));
    return $target;
}

// Keep the fixture and its CLI child in the same synthetic environment; do not
// inherit CI/database/credential environment as fingerprint input.
if (($argv[1] ?? null) !== '--fixture-child') {
    [$code, $out, $error] = fingerprintCommand([PHP_BINARY, __FILE__, '--fixture-child'], dirname(__DIR__));
    echo $out;
    if ($error !== '') fwrite(STDERR, $error);
    exit($code);
}

$fixture = sys_get_temp_dir() . '/deployment-fingerprint-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
try {
    $root = $fixture . '/www/application';
    mkdir($root, 0700, true); mkdir($fixture . '/config', 0700);
    fingerprintWrite($root . '/.gitignore', "config.php\n");
    fingerprintWrite($root . '/services/backend-contract.php', "<?php\n// Synthetic, never executed.\n");
    fingerprintWrite($root . '/tests/run_purchase_study_runtime.py', "# synthetic development-only input\n");
    $publicFiles = ['view.php' => 'selection/view.php', 'selection.css' => 'selection/selection.css', 'activity.js' => 'selection/activity.js'];
    foreach ($publicFiles as $name => $path) fingerprintWrite($root . '/' . $path, 'synthetic public ' . $name . "\n");
    fingerprintJson($root . '/modules/tour-selections/module.json', ['schema' => 1, 'name' => 'anytour-selection-public', 'version' => '1.0.0',
        'contract' => 'selection-php-view-v1', 'files' => $publicFiles, 'backend_inputs' => ['services/backend-contract.php']]);
    fingerprintJson($root . '/modules/tour-selections/editor.json', ['schema' => 1, 'name' => 'anytour-selection-editor', 'version' => '1.0.0',
        'contract' => 'tour-selection-editor-host-v1', 'files' => [], 'backend_inputs' => ['services/backend-contract.php']]);
    fingerprintGit($root, ['init', '--quiet', '--template=']);
    fingerprintGit($root, ['add', '--all']); fingerprintGit($root, ['commit', '--quiet', '-m', 'Synthetic source']);
    $sha = fingerprintGit($root, ['rev-parse', 'HEAD']);
    $secret = hash('sha256', 'synthetic-runtime-fingerprint-key');
    $private = "<?php\ndefine('MAX_SEARCH_LEAD_BRIDGE_SECRET', " . var_export($secret, true) . ");\ndefine('MAX_SEARCH_STANDALONE_RUNTIME', true);\ndefine('OPENAI_MODEL', 'synthetic-model');\n";
    fingerprintWrite($fixture . '/config/max-search.php', $private);
    $loader = "<?php\ndeclare(strict_types=1);\nrequire_once " . var_export($fixture . '/config/max-search.php', true) . ";\nif (!defined('MAX_SEARCH_PUBLIC_BASE_URL')) { define('MAX_SEARCH_PUBLIC_BASE_URL', 'https://example.invalid'); }\n";
    fingerprintWrite($root . '/config.php', $loader);
    $cron = "PATH=/usr/bin:/bin\n*/5 * * * * /usr/bin/php $root/cron_metrika.php\n* * * * * /usr/bin/php $root/tools/channel_post_worker.php --platform=max --run --reconcile\n* * * * * /usr/bin/php $root/tools/manager_prepared_replies.php tick\n* * * * * HOTEL_WORK_TRIGGER=cron /usr/bin/php $root/tools/hotel_work.php tick\n* * * * * DEVELOPMENT_WORK_TRIGGER=cron /usr/bin/php $root/tools/development_work.php tick\n";
    fingerprintWrite($fixture . '/cron.txt', $cron);
    $options = ['mode' => 'all', 'root' => $root, 'application-sha' => $sha, 'config' => $root . '/config.php',
        'private-config-root' => $fixture . '/config', 'cron-file' => $fixture . '/cron.txt', 'public-site' => $fixture . '/public-site',
        'public-releases' => $fixture . '/public-releases', 'public-marker' => $fixture . '/markers/public.json', 'editor-releases' => $fixture . '/editor-releases'];
    foreach (['public-site', 'public-releases', 'editor-releases'] as $name) mkdir($options[$name], 0700);
    $files = [];
    foreach ($publicFiles as $name => $path) $files[$name] = ['sha256' => hash_file('sha256', $root . '/' . $path), 'bytes' => filesize($root . '/' . $path)];
    $release = ['schema' => 1, 'name' => 'anytour-selection-public', 'version' => '1.0.0', 'contract' => 'selection-php-view-v1', 'files' => $files];
    $release['package_id'] = hash('sha256', ManagerTourSelectionEditorHost::encoded($release));
    $publicFingerprint = ManagerTourSelectionPublicHost::fingerprint($root);
    $publicDir = $options['public-releases'] . '/' . $release['package_id'] . '-' . $publicFingerprint;
    foreach ($publicFiles as $name => $path) fingerprintWrite($publicDir . '/' . $name, (string)file_get_contents($root . '/' . $path));
    fingerprintJson($publicDir . '/release.json', $release);
    $publicSeal = ['contract' => ManagerTourSelectionPublicHost::CONTRACT, 'origin' => ManagerTourSelectionPublicHost::ORIGIN,
        'fingerprint' => $publicFingerprint, 'package_id' => $release['package_id'], 'source' => $sha];
    fingerprintJson($publicDir . '/deployment.json', $publicSeal); fingerprintJson($options['public-marker'], $publicSeal);
    symlink($publicDir, $options['public-releases'] . '/current');
    $host = new TourSelectionHostDeployment($root, $options['public-site'], $options['public-releases'], $options['public-marker']);
    foreach (['index.php', 'photo.php', 'activity.php'] as $name) fingerprintWrite($options['public-site'] . '/' . $name, $host->front($name));
    foreach (['selection.css', 'activity.js'] as $name) symlink($options['public-releases'] . '/current/' . $name, $options['public-site'] . '/' . $name);
    $editorDir = fingerprintEditor($options);

    $before = fingerprintTree($fixture);
    $all = DeploymentRuntimeFingerprint::collect($options);
    fingerprintCheck($all['status'] === 'known', 'complete synthetic accepted installation is known');
    fingerprintCheck($before === fingerprintTree($fixture), 'collection creates/chmods/writes no fixture file');
    fingerprintCheck($all['application_sha'] === $sha && $all['reason_code'] === null, 'actual installed SHA bound');
    fingerprintCheck($all['components']['editor']['release_kind'] === 'normal' && $all['components']['editor']['acceptance'] === 'installed', 'normal editor acceptance');
    $core = DeploymentRuntimeFingerprint::collect(array_replace($options, ['mode' => 'core']));
    $components = DeploymentRuntimeFingerprint::collect(array_replace($options, ['mode' => 'components']));
    fingerprintCheck($core['status'] === 'known' && $components['status'] === 'known', 'separate evidence modes known');
    fingerprintCheck($core['components'] === ['public' => null, 'editor' => null] && $components['core_digest'] === null && $components['configuration_key_id'] === null, 'mode fields cannot imply absent evidence');
    fingerprintCheck($core['core_digest'] === $all['core_digest'] && $core['configuration_key_id'] === $all['configuration_key_id'] && $components['components'] === $all['components'], 'all equals independently collected core/components');
    $joined = ['schema' => 1, 'application_sha' => $sha, 'core_digest' => $core['core_digest'], 'configuration_key_id' => $core['configuration_key_id'], 'components' => $components['components']];
    fingerprintCheck(hash('sha256', DeploymentRuntimeFingerprint::encoded($joined)) === $all['digest'], 'documented aggregate digest join');
    $publicJson = DeploymentRuntimeFingerprint::encoded($all);
    fingerprintCheck(!str_contains($publicJson, $secret) && !str_contains($publicJson, $fixture) && !str_contains($publicJson, 'synthetic-model'), 'public output excludes values, secrets and private paths');
    $stdinOptions = array_replace($options, ['cron-file' => '-']);
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($stdinOptions, $cron)['digest'] === $all['digest'], 'stdin cron gives identical evidence without a temporary file');
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($stdinOptions, str_repeat('x', 65537))['status'] === 'unknown', 'stdin is bounded');
    $command = [PHP_BINARY, dirname(__DIR__) . '/tools/deployment_runtime_fingerprint.php'];
    foreach ($stdinOptions as $name => $value) $command[] = '--' . $name . '=' . $value;
    [$code, $out, $error] = fingerprintCommand($command, $root, $cron);
    $cli = json_decode($out, true, 32, JSON_THROW_ON_ERROR);
    fingerprintCheck($code === 0 && $error === '' && $cli['digest'] === $all['digest'], 'CLI emits one redacted JSON result and consumes bounded stdin');

    $wrongSha = DeploymentRuntimeFingerprint::collect(array_replace($options, ['application-sha' => str_repeat('0', 40)]));
    fingerprintCheck($wrongSha['status'] === 'blocked' && $wrongSha['digest'] === null, 'wrong installed SHA blocks evidence');
    $backendPath = $root . '/services/backend-contract.php'; $backend = file_get_contents($backendPath);
    fingerprintWrite($backendPath, $backend . '// drift');
    $drift = DeploymentRuntimeFingerprint::collect($options);
    fingerprintCheck($drift['status'] === 'blocked' && $drift['reason_code'] === 'tracked_application_drift' && $drift['core_digest'] === null, 'physical bytes checked instead of trusting HEAD');
    fingerprintWrite($backendPath, $backend); chmod($backendPath, 0700);
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($options)['reason_code'] === 'tracked_application_drift', 'tracked executable bit drift blocked');
    chmod($backendPath, 0600);
    $sentinel = $fixture . '/must-not-be-created';
    fingerprintWrite($root . '/config.php', "<?php\nfile_put_contents(" . var_export($sentinel, true) . ", 'not permitted');\nrequire_once " . var_export($fixture . '/config/max-search.php', true) . ";\n");
    $unsupported = DeploymentRuntimeFingerprint::collect($options);
    fingerprintCheck($unsupported['status'] === 'unknown' && $unsupported['reason_code'] === 'configuration_loader_unsupported' && !file_exists($sentinel), 'arbitrary config is rejected without execution');
    fingerprintWrite($root . '/config.php', $loader);
    $extra = $fixture . '/config/guarded-extra.php';
    fingerprintWrite($extra, "<?php define('GUARDED_RUNTIME_SETTING', 'first');\n");
    fingerprintWrite($root . '/config.php', $loader . "\nif (defined('PHP_VERSION')) { require " . var_export($extra, true) . "; }\n");
    $externalGuard = DeploymentRuntimeFingerprint::collect($options);
    fingerprintCheck($externalGuard['status'] === 'unknown' && $externalGuard['reason_code'] === 'configuration_loader_unsupported', 'predefined guard cannot silently omit an active include');
    fingerprintWrite($extra, "<?php define('GUARDED_RUNTIME_SETTING', 'changed');\n");
    $changedGuard = DeploymentRuntimeFingerprint::collect($options);
    fingerprintCheck($changedGuard['status'] === 'unknown' && $changedGuard['digest'] === null, 'changed externally guarded file never produces a reusable known digest');
    fingerprintWrite($root . '/config.php', $loader);
    fingerprintWrite($fixture . '/outside.php', $private);
    fingerprintWrite($root . '/config.php', "<?php require " . var_export($fixture . '/outside.php', true) . ';');
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($options)['reason_code'] === 'configuration_loader_unsupported', 'static include cannot escape explicit roots');
    fingerprintWrite($root . '/config.php', $loader);
    fingerprintWrite($fixture . '/config/max-search.php', str_replace($secret, 'short', $private));
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($options)['reason_code'] === 'configuration_key_unavailable', 'weak or unavailable existing key is unknown, never minted');
    fingerprintWrite($fixture . '/config/max-search.php', str_replace('synthetic-model', 'synthetic-model-2', $private));
    $configChange = DeploymentRuntimeFingerprint::collect($options);
    fingerprintCheck($configChange['status'] === 'known' && $configChange['core_digest'] !== $all['core_digest'] && $configChange['configuration_key_id'] === $all['configuration_key_id'], 'literal configuration changes core digest without relabeling key');
    fingerprintWrite($fixture . '/config/max-search.php', str_replace($secret, hash('sha256', 'synthetic-rotated-key'), $private));
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($options)['configuration_key_id'] !== $all['configuration_key_id'], 'existing key rotation invalidates baseline');
    fingerprintWrite($fixture . '/config/max-search.php', str_replace("RUNTIME', true", "RUNTIME', false", $private));
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($options)['reason_code'] === 'configuration_runtime_unsupported', 'unbounded legacy bootstrap is not attested');
    fingerprintWrite($fixture . '/config/max-search.php', $private);
    chmod($fixture . '/config/max-search.php', 0644);
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($options)['core_digest'] !== $all['core_digest'], 'private configuration permission drift changes core proof');
    chmod($fixture . '/config/max-search.php', 0600);
    unlink($root . '/config.php'); symlink($fixture . '/config/max-search.php', $root . '/config.php');
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($options)['status'] === 'blocked', 'config symlink is not followed');
    unlink($root . '/config.php'); fingerprintWrite($root . '/config.php', $loader);
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($stdinOptions, str_replace('*/5 ', '*/6 ', $cron))['core_digest'] !== $all['core_digest'], 'owned cron schedule change changes core digest');
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($stdinOptions, $cron . '* * * * * /other/project/worker' . "\n")['core_digest'] === $all['core_digest'], 'unrelated project cron does not change application identity');
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($stdinOptions, str_replace($root . '/tools/hotel_work.php tick', '/different/worker', $cron))['status'] === 'blocked', 'missing required owned cron is blocked');

    fingerprintWrite($options['public-releases'] . '/pending.json', '{}');
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($options)['reason_code'] === 'component_operation_pending', 'pending public installation cannot be reused');
    unlink($options['public-releases'] . '/pending.json');
    $publicCss = file_get_contents($publicDir . '/selection.css');
    fingerprintWrite($publicDir . '/selection.css', $publicCss . 'changed');
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($options)['status'] === 'blocked', 'changed installed public payload cannot hide behind marker');
    fingerprintWrite($publicDir . '/selection.css', $publicCss);
    $route = file_get_contents($options['public-site'] . '/index.php');
    fingerprintWrite($options['public-site'] . '/index.php', $route . '// changed');
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($options)['reason_code'] === 'public_route_changed', 'public front route verified');
    fingerprintWrite($options['public-site'] . '/index.php', $route);
    $accepted = file_get_contents($editorDir . '/accepted.json');
    fingerprintJson($editorDir . '/accepted.json', ['schema' => 1, 'package_id' => str_repeat('f', 64), 'seal_sha256' => str_repeat('e', 64)]);
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($options)['status'] === 'blocked', 'editor accepted seal required');
    fingerprintWrite($editorDir . '/accepted.json', $accepted);

    $independentDir = fingerprintEditor($options, 'independent', ' accepted new independent bytes');
    $independent = DeploymentRuntimeFingerprint::collect($options);
    fingerprintCheck($independent['status'] === 'known' && $independent['core_digest'] === $all['core_digest'] && $independent['components']['public'] === $all['components']['public'], 'accepted independent editor preserves core and public identities');
    fingerprintCheck($independent['components']['editor']['package_id'] !== $all['components']['editor']['package_id']
        && $independent['components']['editor']['source_sha'] !== $sha && $independent['components']['editor']['backend_fingerprint'] === $all['components']['editor']['backend_fingerprint']
        && $independent['components']['editor']['release_kind'] === 'independent' && $independent['components']['editor']['acceptance'] === 'independent_activated', 'native accepted independent package has its own source and acceptance');
    $operation = file_get_contents($options['editor-releases'] . '/operations/synthetic_0001.json');
    fingerprintJson($options['editor-releases'] . '/operations/synthetic_0001.json', ['state' => 'reserved']);
    $reserved = DeploymentRuntimeFingerprint::collect($options);
    fingerprintCheck($reserved['status'] === 'blocked' && $reserved['components'] === ['public' => null, 'editor' => null], 'metadata flags cannot replace terminal independent operation');
    fingerprintWrite($options['editor-releases'] . '/operations/synthetic_0001.json', $operation);
    fingerprintJson($options['editor-releases'] . '/operations/another_0001.json', ['state' => 'reserved']);
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($options)['reason_code'] === 'editor_operation_unconfirmed', 'unrelated unfinished editor operation also blocks shortcut');
    unlink($options['editor-releases'] . '/operations/another_0001.json');
    fingerprintWrite($options['editor-releases'] . '/pending.json', '{}');
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($options)['status'] === 'blocked', 'pending editor installation cannot be reused');
    unlink($options['editor-releases'] . '/pending.json');
    $seal = json_decode((string)file_get_contents($independentDir . '/deployment.json'), true, 32, JSON_THROW_ON_ERROR);
    $seal['source'] = str_repeat('d', 40);
    fingerprintJson($options['editor-releases'] . '/active.json', $seal);
    fingerprintCheck(DeploymentRuntimeFingerprint::collect($options)['status'] === 'blocked', 'changed active metadata without native seal proof rejected');
    $errorJson = DeploymentRuntimeFingerprint::encoded(DeploymentRuntimeFingerprint::collect(array_replace($options, ['mode' => $fixture])));
    fingerprintCheck(!str_contains($errorJson, $fixture) && !str_contains($errorJson, $secret), 'error path and arbitrary mode are redacted');

    echo 'PASS deployment runtime fingerprint: ' . $checks . " checks\n";
} finally { fingerprintRemove($fixture); }
