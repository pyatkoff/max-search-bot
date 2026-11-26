<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$args = array_fill_keys(['expected-sha','channel-id','message-id','publication-id','expected-version-id',
    'expected-config-version','content-hash','recovery-digest'], '');
$apply = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') { $apply = true; continue; }
    if (!preg_match('/\A--([a-z-]+)=(.*)\z/D', $arg, $match) || !array_key_exists($match[1], $args)) {
        fwrite(STDERR, "Unknown option\n"); exit(2);
    }
    $args[$match[1]] = $match[2];
}
$valid = preg_match('/\A[a-f0-9]{40}\z/D', $args['expected-sha'])
    && preg_match('/\A-100[1-9][0-9]{0,16}\z/D', $args['channel-id'])
    && preg_match('/\A[1-9][0-9]{0,19}\z/D', $args['message-id'])
    && preg_match('/\A[a-f0-9]{64}\z/D', $args['content-hash'])
    && (!$apply || preg_match('/\A[a-f0-9]{64}\z/D', $args['recovery-digest']));
foreach (['publication-id','expected-version-id','expected-config-version'] as $key) {
    $args[$key] = filter_var($args[$key], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $valid = $valid && $args[$key] !== false;
}
if (!$valid) { echo json_encode(['ok'=>false,'reason'=>'exact_recovery_target_required']).PHP_EOL; exit(2); }
$sha = trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
$dirty = trim((string)shell_exec('git -C '.escapeshellarg($root).' status --porcelain --untracked-files=no 2>/dev/null'));
if ($sha !== $args['expected-sha'] || $dirty !== '') {
    echo json_encode(['ok'=>false,'reason'=>'installed_checkout_changed']).PHP_EOL; exit(2);
}
if (is_file($root.'/config.php')) require_once $root.'/config.php';
require_once $root.'/services/ChannelPostRuntime.php';
$identity = ChannelPostRuntime::identity('telegram');
if (!$identity || !ConversationDb::isConfigured()) {
    echo json_encode(['ok'=>false,'reason'=>'channel_worker_not_configured']).PHP_EOL; exit(2);
}
$result = (new ChannelPostAutomationService())->recoverTelegramReadFailure($identity, $args['channel-id'],
    $args['message-id'], $args['publication-id'], $args['expected-version-id'], $args['expected-config-version'],
    $args['content-hash'], $args['recovery-digest'], $apply);
$result['installed_sha'] = $sha;
echo json_encode($result, JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
