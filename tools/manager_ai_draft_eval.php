<?php

declare(strict_types=1);

/**
 * Private, one-use evaluation transport. This file has no HTTP/bootstrap path.
 * The reviewed Actions workflow concatenates the two pure draft classes and
 * this file, then invokes main() with sealed bytes, all through SSH stdin.
 * Only prepare/cleanup touch their own private /tmp directory. Evaluation
 * loads production config, never a database, session, logger or delivery API.
 */
final class ManagerAiDraftEval
{
    public const INPUT_SCHEMA = 'manager_ai_draft_eval_input_v1';
    public const OUTPUT_SCHEMA = 'manager_ai_draft_eval_output_v4';
    public const CASES = 30;
    public const TTL = 3600;
    public const MAX_CIPHERTEXT_BYTES = 65536;
    public const MAX_PLAINTEXT_BYTES = 2097152;
    public const MAX_DURATION_MS = 720000;
    private const ROOT = '/var/www/anytoour/data/www/app.anytoour.ru';
    private const REPOSITORY = 'pyatkoff/max-search-bot';
    private const DIRECTORY_PREFIX = '/tmp/max-search-bot-manager-ai-eval-';

    /** All error messages are fixed codes; no exception/provider/config text. */
    public static function main(array $argv, string $sealedBase64 = ''): int
    {
        $options = null;
        try {
            $options = self::options($argv);
            if (!extension_loaded('sodium') || !function_exists('gzdecode')) {
                throw new RuntimeException('runtime_unavailable');
            }
            if ($options['phase'] === 'prepare') {
                self::prepare($options);
            } elseif ($options['phase'] === 'cleanup') {
                if (!self::cleanup($options['task-token'])) throw new RuntimeException('cleanup_busy');
                self::write(['type'=>'cleanup','ok'=>true]);
            } else {
                self::evaluate($options, $sealedBase64);
            }
            return 0;
        } catch (Throwable $ignored) {
            fwrite(STDERR, "manager_ai_eval_failed\n");
            return 1;
        }
    }

    private static function options(array $argv): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('cli_only');
        $allowed = ['phase','task-token','production-sha','bundle-sha256','recipient-sha256'];
        $out = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (!is_string($arg) || !preg_match('/\A--([a-z0-9-]+)=(.*)\z/Ds', $arg, $m)
                || !in_array($m[1], $allowed, true) || isset($out[$m[1]])) {
                throw new RuntimeException('invalid_options');
            }
            $out[$m[1]] = $m[2];
        }
        if (count($out) !== 5 || !in_array($out['phase'] ?? '', ['prepare','evaluate','cleanup'], true)
            || !preg_match('/\A[0-9a-f]{48}\z/D', $out['task-token'] ?? '')
            || !preg_match('/\A[0-9a-f]{40}\z/D', $out['production-sha'] ?? '')
            || !preg_match('/\A[0-9a-f]{64}\z/D', $out['bundle-sha256'] ?? '')
            || !preg_match('/\A[0-9a-f]{64}\z/D', $out['recipient-sha256'] ?? '')) {
            throw new RuntimeException('invalid_options');
        }
        return $out;
    }

    private static function directory(string $token): string
    {
        if (!preg_match('/\A[0-9a-f]{48}\z/D', $token)) throw new RuntimeException('invalid_task');
        return self::DIRECTORY_PREFIX.$token;
    }

    private static function prepare(array $options): void
    {
        if (!function_exists('exec') || !function_exists('sodium_crypto_box_keypair')) {
            throw new RuntimeException('runtime_unavailable');
        }
        self::verifyProduction($options['production-sha']);
        umask(0077);
        $directory = self::directory($options['task-token']);
        if (!mkdir($directory, 0700)) throw new RuntimeException('task_exists');
        $keypair = '';
        try {
            $keypair = sodium_crypto_box_keypair();
            $created = time();
            $state = [
                'schema'=>'manager_ai_draft_eval_lease_v1',
                'repository'=>self::REPOSITORY,
                'task_token'=>$options['task-token'],
                'production_sha'=>$options['production-sha'],
                'source_bundle_sha256'=>$options['bundle-sha256'],
                'output_recipient_sha256'=>$options['recipient-sha256'],
                'lease_id'=>bin2hex(random_bytes(16)),
                'proof_nonce'=>bin2hex(random_bytes(32)),
                'created_at'=>gmdate('Y-m-d\TH:i:s\Z', $created),
                'expires_at'=>gmdate('Y-m-d\TH:i:s\Z', $created+self::TTL),
                'keypair'=>base64_encode($keypair),
            ];
            $handle = fopen($directory.'/lease.json', 'x');
            if ($handle === false) throw new RuntimeException('lease_failed');
            try {
                if (!chmod($directory.'/lease.json', 0600)) throw new RuntimeException('lease_failed');
                $encoded = json_encode($state, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                    throw new RuntimeException('lease_failed');
                }
            } finally { fclose($handle); }
            self::scheduleCleanup($directory, $state['lease_id']);
            unset($state['keypair']);
            $prepared = ['type'=>'prepared','ok'=>true] + $state + [
                'public_key'=>base64_encode(sodium_crypto_box_publickey($keypair)),
                'encryption'=>'libsodium-sealed-box+gzip',
                'case_count'=>self::CASES,
            ];
            // The bootstrap SSH host key is trusted only after Actions obtains
            // these exact PUBLIC bytes independently from the fixed TLS origin.
            $proof = self::proofPath($options['task-token']);
            $proofBytes = json_encode($prepared,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            $proofHandle = fopen($proof,'x');
            if ($proofHandle===false) throw new RuntimeException('proof_unavailable');
            try {
                if (!chmod($proof,0644) || fwrite($proofHandle,$proofBytes)!==strlen($proofBytes)
                    || !fflush($proofHandle)) throw new RuntimeException('proof_unavailable');
            } finally { fclose($proofHandle); }
            self::write($prepared);
        } catch (Throwable $error) {
            self::cleanup($options['task-token']);
            throw $error;
        } finally {
            if (is_string($keypair) && $keypair !== '') sodium_memzero($keypair);
        }
    }

    /** A detached, bounded cleanup contains only a public path/lease nonce. */
    private static function scheduleCleanup(string $directory, string $leaseId): void
    {
        $token = substr($directory,strlen(self::DIRECTORY_PREFIX));
        $proof = self::proofPath($token);
        $code = 'umask(0077);$d='.var_export($directory,true).';$proof='.var_export($proof,true).';'
            .'if(is_link($d)||!is_dir($d)){exit;}$r=$d."/cleanup.ready";'
            .'if(is_link($r)){exit;}if(@file_put_contents($r,'.var_export($leaseId,true).')!==32){exit;}'
            .'sleep('.(self::TTL+30).');$p=$d."/lease.json";'
            .'if(is_link($r)||@file_get_contents($r)!=='.var_export($leaseId,true).'){exit;}'
            .'if(is_link($p)){exit;}if(!is_file($p)){if(!is_link($proof)){@unlink($proof);}@unlink($r);@rmdir($d);exit;}'
            .'$s=json_decode((string)@file_get_contents($p),true);'
            .'if(!is_array($s)||($s["lease_id"]??"")!=='.var_export($leaseId,true).'){exit;}'
            .'@unlink($p);if(!is_link($proof)){@unlink($proof);}if(!is_link($r)){@unlink($r);}@rmdir($d);';
        $command = 'nohup '.escapeshellarg(PHP_BINARY).' -d display_errors=0 -d log_errors=0 -r '
            .escapeshellarg($code).' </dev/null >/dev/null 2>&1 & echo $!';
        $output = [];
        $status = 1;
        exec($command, $output, $status);
        if ($status !== 0 || count($output) !== 1 || !preg_match('/\A[1-9][0-9]*\z/D', $output[0])) {
            throw new RuntimeException('cleanup_unavailable');
        }
        $ready = $directory.'/cleanup.ready';
        for ($attempt=0; $attempt<20; ++$attempt) {
            if (!is_link($ready) && is_file($ready) && @file_get_contents($ready)===$leaseId) return;
            usleep(100000);
        }
        throw new RuntimeException('cleanup_unavailable');
    }

    private static function evaluate(array $options, string $sealedBase64): void
    {
        self::verifyProduction($options['production-sha']);
        $directory = self::directory($options['task-token']);
        $path = $directory.'/lease.json';
        if (is_link($directory) || !is_dir($directory) || is_link($path) || !is_file($path)
            || (fileperms($directory)&0777) !== 0700 || (fileperms($path)&0777) !== 0600) {
            throw new RuntimeException('lease_unavailable');
        }
        $handle = fopen($path, 'r+');
        if ($handle === false) throw new RuntimeException('lease_unavailable');
        $keypair = '';
        $plain = '';
        $locked = false;
        try {
            if (!flock($handle, LOCK_EX|LOCK_NB)) throw new RuntimeException('lease_in_use');
            $locked = true;
            $bytes = stream_get_contents($handle, 4097);
            if (!is_string($bytes) || strlen($bytes)>4096) throw new RuntimeException('lease_invalid');
            $lease = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
            self::validateLease($lease, $options);
            $keypair = base64_decode($lease['keypair'], true);
            if (!is_string($keypair) || strlen($keypair)!==SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
                throw new RuntimeException('lease_invalid');
            }
            if (strlen($sealedBase64)>4*(int)ceil(self::MAX_CIPHERTEXT_BYTES/3)
                || !preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/D', $sealedBase64)) {
                throw new RuntimeException('ciphertext_invalid');
            }
            $sealed = base64_decode($sealedBase64, true);
            if (!is_string($sealed) || strlen($sealed)>self::MAX_CIPHERTEXT_BYTES
                || strlen($sealed)<=SODIUM_CRYPTO_BOX_SEALBYTES) throw new RuntimeException('ciphertext_invalid');
            $compressed = sodium_crypto_box_seal_open($sealed, $keypair);
            if ($compressed === false) throw new RuntimeException('ciphertext_invalid');
            $plain = gzdecode($compressed, self::MAX_PLAINTEXT_BYTES);
            sodium_memzero($compressed);
            if (!is_string($plain) || strlen($plain)>self::MAX_PLAINTEXT_BYTES) {
                throw new RuntimeException('payload_too_large');
            }
            $input = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
            $cases = self::validateInput($input, $options, $lease);
            $inputDigest = hash('sha256', $plain);
            sodium_memzero($plain);
            // Consume before config or any provider call. A timeout/retry cannot
            // invoke the provider a second time for this prepare lease.
            if (!unlink($path)) throw new RuntimeException('lease_consume_failed');
            sodium_memzero($keypair);
            unset($lease['keypair']);

            // Deliberately no service/bootstrap, session, PDO, router or logger.
            require self::ROOT.'/config.php';
            if (!class_exists('ManagerAiDraftService',false) || !class_exists('ManagerDraftClient',false)
                || !defined('OPENAI_API_KEY') || !is_string(OPENAI_API_KEY) || trim(OPENAI_API_KEY)===''
                || OPENAI_API_KEY==='PASTE_OPENAI_API_KEY_HERE' || strlen(OPENAI_API_KEY)>4096
                || preg_match('/[\x00-\x20\x7F]/',OPENAI_API_KEY) || !function_exists('curl_init')) {
                throw new RuntimeException('provider_not_configured');
            }
            // Validate every case with the actual pure service before the first
            // network call. Reflection does not execute a provider or a mock.
            $normalizer = new ReflectionMethod(ManagerAiDraftService::class, 'context');
            foreach ($cases as $case) {
                $normalized = $normalizer->invoke(null, $case['context']);
                if (!is_array($normalized) || isset($normalized['error'])) {
                    throw new RuntimeException('invalid_context');
                }
            }
            $requestShape = ManagerDraftClient::requestBody(ManagerAiDraftService::instructions(),[]);
            $requestedProfile = self::requestedProfile($requestShape);
            self::write([
                'type'=>'header','schema'=>self::OUTPUT_SCHEMA,'repository'=>self::REPOSITORY,
                'task_token'=>$options['task-token'],'production_sha'=>$options['production-sha'],
                'source_bundle_sha256'=>$options['bundle-sha256'],
                'output_recipient_sha256'=>$options['recipient-sha256'],
                'input_sha256'=>$inputDigest,'ciphertext_sha256'=>hash('sha256',$sealed),
                'generated_at'=>gmdate('Y-m-d\TH:i:s\Z'),'expected_cases'=>self::CASES,
                'provider'=>'ManagerDraftClient::requestJson','retries'=>0,
                'max_provider_calls'=>self::CASES,'max_provider_calls_per_case'=>1,
                'model'=>$requestedProfile['model'],'requested_profile'=>$requestedProfile,
                'duration_clock'=>'server_monotonic_hrtime',
            ]);
            $records = [];
            $batchStarted = hrtime(true);
            foreach ($cases as $index=>$case) {
                $record = self::runCase($case,$index+1,[ManagerDraftClient::class,'requestJson']);
                $records[] = $record;
                self::write($record);
            }
            $durationMs = (int)round((hrtime(true)-$batchStarted)/1000000);
            $summary = self::summarize($records);
            if ($summary['processed_cases']!==self::CASES) throw new RuntimeException('case_count_mismatch');
            if ($durationMs<0 || $durationMs>self::MAX_DURATION_MS
                || $durationMs+self::CASES<$summary['case_elapsed_ms_sum']) {
                throw new RuntimeException('duration_mismatch');
            }
            self::verifyProduction($options['production-sha']);
            self::write(['type'=>'footer','complete'=>true,'finished_at'=>gmdate('Y-m-d\TH:i:s\Z'),
                'expected_cases'=>self::CASES,'exported_cases'=>count($cases),
                'duration_ms'=>$durationMs] + $summary);
        } finally {
            if (is_string($keypair) && $keypair !== '') sodium_memzero($keypair);
            if (is_string($plain) && $plain !== '') sodium_memzero($plain);
            try {
                // A non-owner must never unlink the active owner's lease.
                if ($locked) self::cleanup($options['task-token'], $handle);
            } finally {
                if ($locked) flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /** Verify the actual request projection before spending any provider call. */
    private static function requestedProfile(array $request): array
    {
        if (($request['model']??null)!=='gpt-6.1-sol' || !is_array($request['reasoning']??null)
            || ($request['reasoning']['effort']??null)!=='low' || ($request['service_tier']??null)!=='fast') {
            throw new RuntimeException('requested_profile_mismatch');
        }
        // The requested profile is distinct from allowlisted response metadata.
        // A requested Fast tier never proves the tier that served a response.
        return ['model'=>$request['model'],'reasoning_effort'=>$request['reasoning']['effort'],
            'service_tier'=>$request['service_tier']];
    }

    /** Actual service path shared by evaluation and isolated synthetic tests. */
    private static function runCase(array $case, int $index, callable $transport): array
    {
        $started = hrtime(true);
        $calls = 0;
        $metadata = null; $observations = 0;
        $result = ManagerAiDraftService::generate($case['context'],
            static function(string $instructions, array $payload) use (&$calls,$transport,&$metadata,&$observations): array {
                // Enforce the budget before entering the real transport. A
                // future service retry must not silently spend another call.
                if ($calls>=1) throw new RuntimeException('provider_budget_exceeded');
                ++$calls;
                return $transport($instructions,$payload,
                    static function(array $observed) use (&$metadata,&$observations): void {
                        ++$observations;
                        $metadata = $observations===1 ? self::providerMetadata($observed) : null;
                    });
            });
        return ['type'=>'case','index'=>$index,'case_id'=>$case['case_id'],
            'provider_calls'=>$calls,'result_origin'=>$calls===0?'policy':'model',
            'elapsed_ms'=>(int)round((hrtime(true)-$started)/1000000),
            'provider_metadata'=>$metadata,'result'=>$result];
    }

    /** A second boundary prevents private provider fields entering JSONL. */
    private static function providerMetadata(array $metadata): ?array
    {
        $keys=array_keys($metadata); sort($keys);
        if ($keys!==['model','service_tier','usage']) return null;
        $model=$metadata['model']; $tier=$metadata['service_tier']; $usage=$metadata['usage'];
        if (($model!==null && (!is_string($model)
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$model)!==1))
            || ($tier!==null && (!is_string($tier)
                || !in_array($tier,['default','fast','priority','ultrafast','flex','scale'],true)))
            || !is_array($usage) || ($usage!==[] && array_is_list($usage))) return null;
        $normalized=[];
        foreach ($usage as $key=>$value) {
            if (in_array($key,['input_tokens','output_tokens','total_tokens'],true)) {
                if (!is_int($value) || $value<0 || $value>10000000) return null;
                $normalized[$key]=$value;
            } elseif (in_array($key,['input_tokens_details','output_tokens_details'],true)) {
                $only=$key==='input_tokens_details'?'cached_tokens':'reasoning_tokens';
                if (!is_array($value) || array_keys($value)!==[$only]
                    || !is_int($value[$only]) || $value[$only]<0 || $value[$only]>10000000) return null;
                $normalized[$key]=$value;
            } else return null;
        }
        return ['model'=>$model,'service_tier'=>$tier,'usage'=>$normalized===[]?(object)[]:$normalized];
    }

    /** Never count a zero-call policy answer as a successful model response. */
    private static function summarize(array $records): array
    {
        if (count($records)>self::CASES) throw new RuntimeException('case_count_mismatch');
        $summary = ['processed_cases'=>count($records),'provider_attempts'=>0,
            'policy_cases'=>0,'model_cases'=>0,'successful_cases'=>0,
            'policy_successful_cases'=>0,'provider_successful_cases'=>0,
            'provider_metadata_cases'=>0,'actual_fast_cases'=>0,'usage_observed_cases'=>0,
            'case_elapsed_ms_sum'=>0,'provider_case_elapsed_ms_sum'=>0,'policy_case_elapsed_ms_sum'=>0];
        $errors = [];
        $safeErrors = ['provider_unavailable','provider_not_configured','provider_refused',
            'provider_incomplete','provider_timeout','provider_response_too_large','invalid_ai_response'];
        foreach ($records as $record) {
            $calls = $record['provider_calls']??null;
            if (!is_int($calls) || !in_array($calls,[0,1],true)
                || ($record['result_origin']??'')!==($calls===0?'policy':'model')
                || !is_array($record['result']??null)) throw new RuntimeException('case_count_mismatch');
            $elapsed = $record['elapsed_ms']??null;
            if (!is_int($elapsed) || $elapsed<0 || $elapsed>self::MAX_DURATION_MS) {
                throw new RuntimeException('duration_mismatch');
            }
            $metadata=$record['provider_metadata']??null;
            if ($metadata!==null) {
                if ($calls!==1 || !is_array($metadata)) throw new RuntimeException('invalid_provider_metadata');
                $check=$metadata;
                if (isset($check['usage']) && is_object($check['usage']) && get_object_vars($check['usage'])===[]) $check['usage']=[];
                if (self::providerMetadata($check)===null) throw new RuntimeException('invalid_provider_metadata');
                ++$summary['provider_metadata_cases'];
                // Fast may be reported with its documented priority alias.
                if (in_array($metadata['service_tier'],['fast','priority'],true)) ++$summary['actual_fast_cases'];
                if ((array)$metadata['usage']!==[]) ++$summary['usage_observed_cases'];
            }
            $summary['provider_attempts'] += $calls;
            ++$summary[$calls===0?'policy_cases':'model_cases'];
            $summary['case_elapsed_ms_sum'] += $elapsed;
            $summary[$calls===0?'policy_case_elapsed_ms_sum':'provider_case_elapsed_ms_sum'] += $elapsed;
            if (($record['result']['ok']??null)===true) {
                ++$summary['successful_cases'];
                ++$summary[$calls===0?'policy_successful_cases':'provider_successful_cases'];
            } else {
                $code = $record['result']['error']??null;
                if (($record['result']['ok']??null)!==false || !is_string($code)
                    || !in_array($code,$safeErrors,true)) throw new RuntimeException('invalid_case_result');
                $errors[$code] = ($errors[$code]??0)+1;
            }
        }
        $summary['errors'] = (object)$errors;
        return $summary;
    }

    private static function validateLease($lease, array $options): void
    {
        if (!is_array($lease) || ($lease['schema']??'')!=='manager_ai_draft_eval_lease_v1'
            || ($lease['repository']??'')!==self::REPOSITORY
            || ($lease['task_token']??'')!==$options['task-token']
            || ($lease['production_sha']??'')!==$options['production-sha']
            || ($lease['source_bundle_sha256']??'')!==$options['bundle-sha256']
            || ($lease['output_recipient_sha256']??'')!==$options['recipient-sha256']
            || !is_string($lease['keypair']??null)
            || !is_string($lease['lease_id']??null) || !preg_match('/\A[0-9a-f]{32}\z/D',$lease['lease_id'])
            || self::timestamp($lease['created_at']??null)>time()+60
            || self::timestamp($lease['expires_at']??null)<=time()
            || self::timestamp($lease['expires_at'])-self::timestamp($lease['created_at'])!==self::TTL) {
            throw new RuntimeException('lease_invalid');
        }
    }

    /** Pure validation is also exercised by synthetic local regression. */
    private static function validateInput($input, array $options, array $lease): array
    {
        if (!is_array($input) || array_is_list($input) || ($input['schema']??'')!==self::INPUT_SCHEMA
            || ($input['repository']??'')!==self::REPOSITORY
            || ($input['task_token']??'')!==$options['task-token']
            || ($input['production_sha']??'')!==$options['production-sha']
            || ($input['source_bundle_sha256']??'')!==$options['bundle-sha256']
            || ($input['output_recipient_sha256']??'')!==$options['recipient-sha256']
            || ($input['lease_id']??'')!==$lease['lease_id']
            || self::timestamp($input['generated_at']??null)>time()+60
            || time()-self::timestamp($input['generated_at'])>self::TTL
            || self::timestamp($input['expires_at']??null)>self::timestamp($lease['expires_at'])
            || self::timestamp($input['expires_at'])<=time()
            || !isset($input['cases']) || !is_array($input['cases']) || !array_is_list($input['cases'])
            || count($input['cases'])!==self::CASES) {
            throw new RuntimeException('input_invalid');
        }
        $seen = [];
        foreach ($input['cases'] as $case) {
            if (!is_array($case) || count($case)!==2 || !isset($case['case_id'],$case['context'])
                || !is_string($case['case_id']) || !preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D',$case['case_id'])
                || isset($seen[$case['case_id']]) || !is_array($case['context'])) {
                throw new RuntimeException('input_invalid');
            }
            $seen[$case['case_id']] = true;
        }
        return $input['cases'];
    }

    private static function timestamp($value): int
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D',$value)) {
            throw new RuntimeException('invalid_timestamp');
        }
        $parsed = strtotime($value);
        if ($parsed===false || gmdate('Y-m-d\TH:i:s\Z',$parsed)!==$value) throw new RuntimeException('invalid_timestamp');
        return $parsed;
    }

    private static function verifyProduction(string $expected): void
    {
        if (getcwd()!==self::ROOT || !is_file(self::ROOT.'/config.php') || is_link(self::ROOT.'/config.php')) {
            throw new RuntimeException('production_root_mismatch');
        }
        $lines = [];
        $status = 1;
        exec('git -C '.escapeshellarg(self::ROOT).' rev-parse HEAD 2>/dev/null',$lines,$status);
        if ($status!==0 || count($lines)!==1 || !hash_equals($expected,trim($lines[0]))) {
            throw new RuntimeException('production_sha_mismatch');
        }
    }

    private static function cleanup(string $token, $ownedHandle = null): bool
    {
        $directory = self::directory($token);
        if (is_link($directory)) return false;
        if (!is_dir($directory)) return self::removeProof($token);
        $path = $directory.'/lease.json';
        $handle = $ownedHandle;
        $ownLock = false;
        if (!is_link($path) && is_file($path) && $handle===null) {
            $handle = @fopen($path,'r+');
            if ($handle===false) return false;
            if (!flock($handle,LOCK_EX|LOCK_NB)) { fclose($handle); return false; }
            $ownLock = true;
        }
        try {
            if (!is_link($path) && is_file($path) && !@unlink($path)) return false;
            $ready = $directory.'/cleanup.ready';
            if (!is_link($ready) && is_file($ready) && !@unlink($ready)) return false;
            return self::removeProof($token) && @rmdir($directory);
        } finally {
            if ($ownLock) { flock($handle,LOCK_UN); fclose($handle); }
        }
    }

    private static function proofPath(string $token): string
    {
        self::directory($token);
        return self::ROOT.'/manager2/assets/ai-eval-proof-'.$token.'.json';
    }

    private static function removeProof(string $token): bool
    {
        $proof = self::proofPath($token);
        if (is_link($proof)) return false;
        return !is_file($proof) || @unlink($proof);
    }

    private static function write(array $record): void
    {
        $bytes = json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if (fwrite(STDOUT,$bytes)!==strlen($bytes) || !fflush(STDOUT)) throw new RuntimeException('output_failed');
    }
}

if (!defined('MANAGER_AI_DRAFT_EVAL_LIBRARY_ONLY')) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
    exit(ManagerAiDraftEval::main($_SERVER['argv']??[]));
}
