<?php
declare(strict_types=1);

/** Documented error types only: https://yandex.ru/dev/metrika/ru/intro/errors */
function as39GoalAuditErrors($data): array
{
    $types = ['backend_error','invalid_parameter','not_found','missing_parameter','filter_limits','access_denied',
        'counter_in_connect','invalid_token','unauthorized','quota_requests_by_uid','quota_delegate_requests',
        'quota_grants_requests','quota_requests_by_ip','quota_parallel_requests','quota_requests_by_counter_id',
        'query_error','too_much_rows','conflict','not_acceptable','timeout','invalid_uploading','invalid_json','limit_exceeded'];
    $codePresent = is_array($data) && array_key_exists('code', $data);
    $code = is_array($data) ? ($data['code'] ?? null) : null;
    $errorsPresent = is_array($data) && array_key_exists('errors', $data);
    $errors = is_array($data) ? ($data['errors'] ?? null) : null;
    $observed = [];
    foreach (is_array($errors) ? array_slice($errors, 0, 8) : [] as $error) {
        $type = is_array($error) ? ($error['error_type'] ?? null) : null;
        $observed[] = in_array($type, $types, true) ? $type : 'other';
    }
    return ['code_present'=>$codePresent, 'code_type'=>$codePresent ? get_debug_type($code) : 'missing',
        'code'=>is_int($code) && $code >= 100 && $code <= 599 ? $code : null,
        'errors_present'=>$errorsPresent, 'errors_type'=>$errorsPresent ? get_debug_type($errors) : 'missing',
        'error_count'=>is_array($errors) ? count($errors) : null,
        'error_types'=>array_values(array_unique($observed)),
        'error_types_truncated'=>is_array($errors) && count($errors) > 8];
}

function as39GoalAccessEnum($object, string $field, array $allowed, string $label): array
{
    $present = $object instanceof stdClass && property_exists($object, $field);
    $value = $present ? $object->$field : null;
    return [$label.'_present'=>$present, $label.'_type'=>$present ? get_debug_type($value) : 'missing',
        $label=>in_array($value, $allowed, true) ? $value
            : (!$present ? 'missing' : (is_string($value) ? 'unrecognized' : 'non_string'))];
}

/** No account identity, raw payload, goal read or inferred owner permission. */
function as39GoalAccessObservation(string $stage, array $response): array
{
    $body = $response['body'];
    $document = json_decode($body, false, 32, JSON_BIGINT_AS_STRING);
    $valid = json_last_error() === JSON_ERROR_NONE;
    $out = ['stage'=>$stage, 'http'=>(int)($response['http'] ?? 0),
        'curl_errno'=>(int)($response['error'] ?? 0),
        'ssl_verify_result'=>isset($response['ssl_verify_result']) ? (int)$response['ssl_verify_result'] : null,
        'response_bytes'=>strlen($body), 'json_valid'=>$valid,
        'provider_error'=>as39GoalAuditErrors(json_decode($body, true, 32, JSON_BIGINT_AS_STRING))];
    if ($stage === 'counter') {
        $counter = $document instanceof stdClass ? ($document->counter ?? null) : null;
        $shape = $counter instanceof stdClass;
        $idPresent = $shape && property_exists($counter, 'id');
        $id = $shape ? ($counter->id ?? null) : null;
        return $out + ['counter_shape_ok'=>$shape, 'counter_id_present'=>$idPresent,
            'counter_id_type'=>$idPresent ? get_debug_type($id) : 'missing',
            'counter_id_matches'=>(is_int($id) || is_string($id)) && (string)$id === '42611469']
            + as39GoalAccessEnum($counter, 'status', ['Active','Deleted'], 'status')
            + as39GoalAccessEnum($counter, 'type', ['simple','partner'], 'counter_type')
            + as39GoalAccessEnum($counter, 'permission', ['own','edit','view','analyst','view_access_filter','analyst_access_filter'], 'permission');
    }
    $grant = $document instanceof stdClass ? ($document->grant ?? null) : null;
    return $out + ['grant_shape_ok'=>$grant instanceof stdClass,
        'user_login_present'=>$grant instanceof stdClass && property_exists($grant, 'user_login')]
        + as39GoalAccessEnum($grant, 'perm', ['public_stat','view','edit','analyst','analyst_access_filter'], 'perm');
}

/** Exactly two fixed GET attempts; my_grant is diagnostic evidence, never an owner proof. */
function as39GoalAuditAccessRead(array $settings, callable $transport): array
{
    $metric = $settings['metrika'] ?? [];
    if (($settings['project_key'] ?? '') !== 'appleservice39' || ($settings['bot_key'] ?? '') !== 'id390521129062_bot'
        || ($metric['counter_id'] ?? '') !== '42611469' || ($metric['mode'] ?? '') !== 'collect'
        || ($metric['credential_source'] ?? '') !== 'platform' || ($metric['goals'] ?? []) !== ['predictive_value'=>'predict']
        || ($metric['goal_ids'] ?? []) !== ['predictive_value'=>'680570232']
        || ($metric['goal_match'] ?? []) !== ['predictive_value'=>'exact']) throw new RuntimeException('policy_changed');
    $observations = []; $attempts = 0;
    foreach (['counter'=>'https://api-metrika.yandex.net/management/v1/counter/42611469',
        'my_grant'=>'https://api-metrika.yandex.net/management/v1/counter/42611469/my_grant'] as $stage=>$url) {
        $attempts++;
        try { $response = $transport('GET', $url, null); }
        catch (Throwable $ignored) {
            $observations[] = ['stage'=>$stage,'read_error'=>'bounded_transport_exception']; continue;
        }
        if (!is_array($response) || !is_string($response['body'] ?? null) || strlen($response['body']) > 1048576) {
            $observations[] = ['stage'=>$stage,'read_error'=>'bounded_response_invalid']; continue;
        }
        $observations[] = as39GoalAccessObservation($stage, $response);
    }
    return ['ok'=>true, 'scope'=>'appleservice39_goal_admission_readonly', 'purpose'=>'access_detail_only',
        'diagnostic_version'=>4, 'mode'=>'collect', 'native_probe_calls'=>0,
        'observations'=>$observations, 'http_attempts'=>$attempts, 'provider_mutations'=>0,
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
        require_once $root.'/services/ProjectFunnelSettings.php';
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
        $result = as39GoalAuditAccessRead($settings, $transport);
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
            'purpose'=>'access_detail_only', 'diagnostic_version'=>4, 'native_probe_calls'=>0,
            'reason'=>in_array($error->getMessage(),$safe,true)?$error->getMessage():'read_unavailable',
            'database_writes'=>0,'provider_mutations'=>0,'conversion_requests'=>0,'customer_messages'=>0], JSON_UNESCAPED_SLASHES).PHP_EOL;
        return 1;
    }
}

if (!defined('AS39_GOAL_AUDIT_LIBRARY_ONLY')) {
    ini_set('display_errors','0'); ini_set('log_errors','0');
    exit(as39GoalAuditMain($argv));
}
