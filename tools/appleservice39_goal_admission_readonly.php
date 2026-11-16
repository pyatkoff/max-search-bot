<?php
declare(strict_types=1);

/** Finite GET-only observation. No configuration, goal, outbox or message write. */
function as39GoalAuditObservation(string $stage, array $response): array
{
    $body = (string)($response['body'] ?? '');
    $data = json_decode($body, true, 32, JSON_BIGINT_AS_STRING);
    $out = ['stage'=>$stage, 'http'=>(int)($response['http'] ?? 0),
        'curl_errno'=>(int)($response['error'] ?? 0),
        'ssl_verify_result'=>isset($response['ssl_verify_result']) ? (int)$response['ssl_verify_result'] : null,
        'response_bytes'=>strlen($body), 'json_valid'=>json_last_error() === JSON_ERROR_NONE];
    if ($stage === 'counter') {
        $counter = is_array($data) ? ($data['counter'] ?? null) : null;
        $permission = is_array($counter) ? ($counter['permission'] ?? null) : null;
        return $out + ['counter_shape_ok'=>is_array($counter),
            'counter_id_matches'=>is_array($counter) && (string)($counter['id'] ?? '') === '42611469',
            'permission'=>in_array($permission, ['own','edit','view'], true) ? $permission : 'missing_or_other'];
    }
    $goals = is_array($data) ? ($data['goals'] ?? null) : null;
    if (!is_array($goals)) return $out + ['goals_shape_ok'=>false];
    $selected = array_values(array_filter($goals, static fn($g)=>is_array($g) && (string)($g['id'] ?? '') === '680570232'));
    $goal = count($selected) === 1 ? $selected[0] : null;
    $conditions = is_array($goal) ? ($goal['conditions'] ?? null) : null;
    $first = is_array($conditions) && count($conditions) === 1 ? ($conditions[0] ?? null) : null;
    $type = is_array($first) ? ($first['type'] ?? null) : null;
    $overlap = ['empty_identifier'=>0,'same_exact_identifier'=>0,'contains_identifier'=>0,
        'prefix_identifier'=>0,'regexp_not_proven_disjoint'=>0];
    foreach ($goals as $other) {
        if (!is_array($other) || ($other['type'] ?? '') !== 'action' || (string)($other['id'] ?? '') === '680570232') continue;
        if (!is_array($other['conditions'] ?? null)) continue;
        foreach ($other['conditions'] as $condition) {
            $value = is_array($condition) ? ($condition['url'] ?? null) : null;
            $kind = is_array($condition) ? ($condition['type'] ?? '') : '';
            if (!is_string($value) || $value === '') { $overlap['empty_identifier']++; continue; }
            if (in_array($kind, ['exact','action'], true) && $value === 'predict') $overlap['same_exact_identifier']++;
            if ($kind === 'contain' && str_contains('predict', $value)) $overlap['contains_identifier']++;
            if ($kind === 'start' && str_starts_with('predict', $value)) $overlap['prefix_identifier']++;
            if ($kind === 'regexp') $overlap['regexp_not_proven_disjoint']++;
        }
    }
    return $out + ['goals_shape_ok'=>true, 'goal_count'=>count($goals),
        'selected_goal_count'=>count($selected), 'selected_type_is_action'=>is_array($goal) && ($goal['type'] ?? '') === 'action',
        'selected_condition_count'=>is_array($conditions) ? count($conditions) : null,
        'selected_identifier_matches'=>is_array($first) && ($first['url'] ?? '') === 'predict',
        'selected_condition_type'=>in_array($type, ['exact','contain','start','regexp','action'], true) ? $type : 'missing_or_other',
        'other_goal_overlap_counts'=>$overlap];
}

function as39GoalAuditReason(string $nativeStatus, array $observations, ?string $transportStop): string
{
    if ($nativeStatus === 'ready') return 'ready';
    if ($transportStop !== null) return $transportStop;
    $counter = $observations[0] ?? null;
    if (!$counter || $counter['stage'] !== 'counter') return 'counter_not_observed';
    if ($counter['curl_errno'] !== 0) return 'counter_transport_failed';
    if ($counter['http'] !== 200) return 'counter_http_not_200';
    if (!$counter['json_valid'] || !$counter['counter_shape_ok']) return 'counter_payload_invalid';
    if (!$counter['counter_id_matches']) return 'counter_identity_mismatch';
    if (!in_array($counter['permission'], ['own','edit'], true)) return 'counter_permission_not_own_or_edit';
    $goals = $observations[1] ?? null;
    if (!$goals || $goals['stage'] !== 'goals') return 'goals_not_observed';
    if ($goals['curl_errno'] !== 0) return 'goals_transport_failed';
    if ($goals['http'] !== 200) return 'goals_http_not_200';
    if (!$goals['json_valid'] || !$goals['goals_shape_ok']) return 'goals_payload_invalid';
    if ($goals['selected_goal_count'] !== 1) return 'selected_goal_missing_or_duplicated';
    if (!$goals['selected_type_is_action']) return 'selected_goal_not_action';
    if ($goals['selected_condition_count'] !== 1) return 'selected_goal_not_one_condition';
    if (!$goals['selected_identifier_matches']) return 'selected_goal_identifier_mismatch';
    if ($goals['selected_condition_type'] !== 'exact') return 'selected_goal_condition_not_exact';
    foreach ($goals['other_goal_overlap_counts'] as $reason=>$count) if ($count > 0) return 'other_goal_'.$reason;
    return 'native_admission_unclassified';
}

/** Calls the installed canonical admission owner once with its transport seam. */
function as39GoalAuditProbe(array $settings, string $token, callable $transport): array
{
    $metric = $settings['metrika'] ?? [];
    if (($settings['project_key'] ?? '') !== 'appleservice39' || ($settings['bot_key'] ?? '') !== 'id390521129062_bot'
        || ($metric['counter_id'] ?? '') !== '42611469' || ($metric['mode'] ?? '') !== 'collect'
        || ($metric['credential_source'] ?? '') !== 'platform' || ($metric['goals'] ?? []) !== ['predictive_value'=>'predict']
        || ($metric['goal_ids'] ?? []) !== ['predictive_value'=>'680570232']
        || ($metric['goal_match'] ?? []) !== ['predictive_value'=>'exact']) throw new RuntimeException('policy_changed');
    $urls = ['https://api-metrika.yandex.net/management/v1/counter/42611469'=>'counter',
        'https://api-metrika.yandex.net/management/v1/counter/42611469/goals'=>'goals'];
    $observations = []; $seen = []; $transportStop = null;
    $guarded = static function(string $method, string $url, $payload) use ($transport,$urls,&$observations,&$seen,&$transportStop): array {
        if ($method !== 'GET' || $payload !== null || !isset($urls[$url]) || isset($seen[$url]) || count($seen) >= 2) {
            $transportStop = 'unexpected_native_request'; throw new RuntimeException($transportStop);
        }
        $stage = $urls[$url];
        if (($stage === 'counter') !== (count($seen) === 0)) {
            $transportStop = 'unexpected_native_request_order'; throw new RuntimeException($transportStop);
        }
        $seen[$url] = true;
        try { $response = $transport($method, $url, $payload); }
        catch (Throwable $ignored) { $transportStop = 'bounded_transport_exception'; throw new RuntimeException($transportStop); }
        if (!is_array($response) || strlen((string)($response['body'] ?? '')) > 1048576) {
            $transportStop = 'bounded_response_invalid'; throw new RuntimeException($transportStop);
        }
        $observations[] = as39GoalAuditObservation($stage, $response);
        return $response;
    };
    $result = ProjectFunnelConversions::probe($settings,
        static fn(array $policy)=>new MetrikaOfflineClient('42611469', $token, $guarded, null, $policy));
    return ['ok'=>true, 'scope'=>'appleservice39_goal_admission_readonly', 'mode'=>'collect',
        'native_admission'=>$result, 'reason'=>as39GoalAuditReason((string)$result['status'], $observations, $transportStop),
        'observations'=>$observations, 'http_attempts'=>count($seen), 'provider_mutations'=>0,
        'database_writes'=>0, 'conversion_requests'=>0, 'customer_messages'=>0];
}

function as39GoalAuditMain(array $argv): int
{
    $root = '/var/www/anytoour/data/www/app.anytoour.ru';
    $expected = $argv[1] ?? '';
    $pdo = null;
    try {
        if (PHP_SAPI !== 'cli' || count($argv) !== 2 || !preg_match('/\A[a-f0-9]{40}\z/D', $expected)) throw new RuntimeException('invalid_operation');
        $readGit = static function(array $arguments) use ($root): string {
            $process = proc_open(['git','-C',$root,...$arguments], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('checkout_unavailable');
            fclose($pipes[0]); $out = stream_get_contents($pipes[1], 16385); fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0 || !is_string($out) || strlen($out) > 16384) throw new RuntimeException('checkout_unavailable');
            return trim($out);
        };
        if ($readGit(['rev-parse','HEAD']) !== $expected || $readGit(['status','--porcelain','--untracked-files=no']) !== '') throw new RuntimeException('checkout_changed');
        require_once $root.'/config.php';
        require_once $root.'/services/ConversationDb.php';
        $pdo = ConversationDb::connection();
        if ($pdo->inTransaction()) throw new RuntimeException('storage_busy');
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        require_once $root.'/services/ProjectFunnelConversions.php';
        $connection = ProjectMaxConnection::resolve('appleservice39');
        $settings = $connection ? ProjectFunnelSettings::load($connection) : null;
        if (!$settings) throw new RuntimeException('project_unavailable');
        if (!defined('METRIKA_OAUTH_TOKEN') || !is_string(METRIKA_OAUTH_TOKEN) || trim(METRIKA_OAUTH_TOKEN) === '') throw new RuntimeException('credential_unavailable');
        $token = METRIKA_OAUTH_TOKEN;
        $deadline = microtime(true) + 25;
        $transport = static function(string $method, string $url, $payload) use ($token,$deadline): array {
            $remaining = (int)floor(($deadline-microtime(true))*1000);
            if ($remaining < 1) throw new RuntimeException('read_deadline');
            $body = ''; $tooLarge = false; $ch = curl_init($url);
            if ($ch === false) throw new RuntimeException('transport_unavailable');
            try {
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>false, CURLOPT_FOLLOWLOCATION=>false,
                    CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT_MS=>min(5000,$remaining),
                    CURLOPT_TIMEOUT_MS=>$remaining, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                    CURLOPT_HTTPHEADER=>['Authorization: OAuth '.$token,'Accept: application/json'],
                    CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body,&$tooLarge): int {
                        if (strlen($body)+strlen($chunk)>1048576) { $tooLarge=true; return 0; }
                        $body.=$chunk; return strlen($chunk);
                    }]);
                curl_exec($ch);
                return ['http'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE), 'error'=>$tooLarge?23:curl_errno($ch),
                    'ssl_verify_result'=>(int)curl_getinfo($ch,CURLINFO_SSL_VERIFYRESULT), 'body'=>$body];
            } finally { curl_close($ch); }
        };
        $result = as39GoalAuditProbe($settings, $token, $transport);
        $pdo->rollBack();
        if ($readGit(['rev-parse','HEAD']) !== $expected || $readGit(['status','--porcelain','--untracked-files=no']) !== '') throw new RuntimeException('checkout_changed_after_read');
        $result['application_sha']=$expected; $result['observed_at']=gmdate('c');
        echo json_encode($result, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
        return 0;
    } catch (Throwable $error) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        $safe = ['invalid_operation','checkout_unavailable','checkout_changed','checkout_changed_after_read',
            'storage_busy','project_unavailable','credential_unavailable','policy_changed'];
        echo json_encode(['ok'=>false, 'scope'=>'appleservice39_goal_admission_readonly',
            'reason'=>in_array($error->getMessage(),$safe,true)?$error->getMessage():'read_unavailable',
            'database_writes'=>0,'provider_mutations'=>0,'conversion_requests'=>0,'customer_messages'=>0], JSON_UNESCAPED_SLASHES).PHP_EOL;
        return 1;
    }
}

if (!defined('AS39_GOAL_AUDIT_LIBRARY_ONLY')) {
    ini_set('display_errors','0'); ini_set('log_errors','0');
    exit(as39GoalAuditMain($argv));
}
