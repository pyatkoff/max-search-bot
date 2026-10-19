<?php

declare(strict_types=1);

/**
 * Private, one-use evaluation transport. This file has no HTTP/bootstrap path.
 * The reviewed Actions workflow concatenates seven canonical draft dependencies and
 * this file, then invokes main() with sealed bytes, all through SSH stdin.
 * Only prepare/cleanup touch their own private /tmp directory. Evaluation
 * loads production config, never a database, session, logger or delivery API.
 */
final class ManagerAiBulkEval
{
    public const INPUT_SCHEMA = 'manager_ai_anex_b36_input_v1';
    public const OUTPUT_SCHEMA = 'manager_ai_anex_b36_output_v1';
    public const CASES = 11;
    public const MAX_PARALLELISM = 1;
    public const TTL = 3600;
    public const MAX_CIPHERTEXT_BYTES = 4194304;
    public const MAX_PLAINTEXT_BYTES = 16777216;
    public const MAX_DURATION_MS = 2400000;
    public const OUTPUT_TOKEN_RESERVATION = 24200;
    private const LAUNCH_MARGIN_SECONDS = 30;
    private const ROOT = '/var/www/anytoour/data/www/app.anytoour.ru';
    private const REPOSITORY = 'pyatkoff/max-search-bot';
    private const DIRECTORY_PREFIX = '/tmp/max-search-bot-anex-presales-b36-';

    /** All error messages are fixed codes; no exception/provider/config text. */
    public static function main(array $argv, string $sealedBase64 = ''): int
    {
        $options = null;
        try {
            $options = self::options($argv);
            if (!defined('MANAGER_AI_ANEX_B36_DEPENDENCIES')
                || !is_array(constant('MANAGER_AI_ANEX_B36_DEPENDENCIES'))
                || count(constant('MANAGER_AI_ANEX_B36_DEPENDENCIES'))!==7) throw new RuntimeException('dependency_bundle_invalid');
            if (!extension_loaded('sodium') || !function_exists('gzdecode')) {
                throw new RuntimeException('runtime_unavailable');
            }
            if ($options['phase'] === 'prepare') {
                self::prepare($options);
            } elseif ($options['phase'] === 'collect') {
                self::collect($options);
            } elseif ($options['phase'] === 'cleanup') {
                if (!self::cleanup($options['task-token'])) throw new RuntimeException('cleanup_busy');
                self::write(['type'=>'cleanup','ok'=>true]);
            } else {
                self::evaluate($options, $sealedBase64);
            }
            return 0;
        } catch (Throwable $ignored) {
            fwrite(STDERR, "manager_ai_bulk_failed\n");
            return 1;
        }
    }

    private static function options(array $argv): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('cli_only');
        $allowed = ['phase','task-token','production-sha','candidate-sha','bundle-sha256','recipient-sha256','case-count','parallelism','stage'];
        $out = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (!is_string($arg) || !preg_match('/\A--([a-z0-9-]+)=(.*)\z/Ds', $arg, $m)
                || !in_array($m[1], $allowed, true) || isset($out[$m[1]])) {
                throw new RuntimeException('invalid_options');
            }
            $out[$m[1]] = $m[2];
        }
        if (count($out) !== 9 || !in_array($out['phase'] ?? '', ['prepare','evaluate','collect','cleanup'], true)
            || !preg_match('/\A[0-9a-f]{48}\z/D', $out['task-token'] ?? '')
            || !preg_match('/\A[0-9a-f]{40}\z/D', $out['production-sha'] ?? '')
            || ($out['production-sha']??'')!==self::sourceManifest()['production_sha']
            || ($out['candidate-sha']??'')!==self::sourceManifest()['candidate_sha']
            || !preg_match('/\A[0-9a-f]{64}\z/D', $out['bundle-sha256'] ?? '')
            || !preg_match('/\A[0-9a-f]{64}\z/D', $out['recipient-sha256'] ?? '')
            || !preg_match('/\A[1-9][0-9]{0,2}\z/D', $out['case-count'] ?? '')
            || (int)$out['case-count']!==self::CASES
            || !in_array($out['parallelism']??'', ['1'],true)
            || ($out['stage']??'') !== 'generate') {
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
        if (!function_exists('exec') || !function_exists('sodium_crypto_box_keypair')
            || !function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
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
                'schema'=>'manager_ai_anex_b36_lease_v1',
                'repository'=>self::REPOSITORY,
                'task_token'=>$options['task-token'],
                'production_sha'=>$options['production-sha'],'candidate_sha'=>$options['candidate-sha'],
                'source_bundle_sha256'=>$options['bundle-sha256'],
                'output_recipient_sha256'=>$options['recipient-sha256'],
                'case_count'=>(int)$options['case-count'],'parallelism'=>(int)$options['parallelism'],'stage'=>$options['stage'],
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
                'maximum_case_count'=>self::CASES,'fork_available'=>true,
                'dependency_sha256'=>constant('MANAGER_AI_ANEX_B36_DEPENDENCIES'),
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
            .'if(is_link($p)){exit;}if(!is_file($p)){foreach(glob($d."/*.json")?:[] as $f){if(is_link($f)||!preg_match("/\\A(?:header|footer|started-[0-9]{1,3}|result-[0-9]{1,3})\\.json\\z/D",basename($f))){exit;}@unlink($f);}if(!is_link($proof)){@unlink($proof);}@unlink($r);@rmdir($d);exit;}'
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
        $evaluationStarted = hrtime(true);
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

            // Pure capture through the canonical service, never the real provider.
            // Every frozen payload passes the canonical byte cap before config.
            $preflights=[];$requestBytes=0;$redactorChanges=0;
            foreach ($cases as $offset=>$case) {
                $capture=self::preflightCase($case,$offset+1,self::timestamp($input['generated_at']));
                $preflights[]=$capture;
                $requestBytes+=$capture['canonical_request_encoded_bytes'];
                $redactorChanges+=$capture['redactor_changed_count'];
            }
            if (!self::canLaunch(self::timestamp($lease['expires_at']),$evaluationStarted+self::MAX_DURATION_MS*1000000)) {
                throw new RuntimeException('launch_deadline_exhausted');
            }

            // Deliberately no service/bootstrap, session, PDO, router or logger.
            set_error_handler(static function():never {throw new RuntimeException('config_load_failed');});
            try {require self::ROOT.'/config.php';} finally {restore_error_handler();}
            if (!class_exists('ManagerAiDraftService',false) || !class_exists('ManagerDraftClient',false)
                || !defined('OPENAI_API_KEY') || !is_string(OPENAI_API_KEY) || trim(OPENAI_API_KEY)===''
                || OPENAI_API_KEY==='PASTE_OPENAI_API_KEY_HERE' || strlen(OPENAI_API_KEY)>4096
                || preg_match('/[\x00-\x20\x7F]/',OPENAI_API_KEY) || !function_exists('curl_init')) {
                throw new RuntimeException('provider_not_configured');
            }
            $profile=self::requestedProfile(ManagerDraftClient::requestBody(ManagerAiDraftService::instructions(),[]));
            $header=['type'=>'header','schema'=>self::OUTPUT_SCHEMA,'repository'=>self::REPOSITORY,
                'task_token'=>$options['task-token'],'production_sha'=>$options['production-sha'],'candidate_sha'=>$options['candidate-sha'],
                'source_bundle_sha256'=>$options['bundle-sha256'],'output_recipient_sha256'=>$options['recipient-sha256'],
                'input_sha256'=>$inputDigest,'ciphertext_sha256'=>hash('sha256',$sealed),
                'generated_at'=>gmdate('Y-m-d\TH:i:s\Z'),'expected_cases'=>count($cases),
                'parallelism'=>(int)$options['parallelism'],'stage'=>$options['stage'],
                'retries'=>0,'max_provider_calls'=>count($cases),'max_provider_calls_per_case'=>1,
                'model'=>$profile['model'],'requested_profile'=>$profile,'duration_clock'=>'server_monotonic_hrtime',
                'company_knowledge'=>'explicit_empty_offline','live_selection_fetches'=>0,'grading_calls'=>0,
                'submitted_attempt_reservation'=>self::CASES,'output_token_reservation'=>self::OUTPUT_TOKEN_RESERVATION,
                'max_output_tokens_per_request'=>2200,'request_timeout_seconds'=>20,
                'canonical_request_byte_cap'=>ManagerDraftClient::MAX_REQUEST_BYTES,
                'canonical_request_encoded_bytes_total'=>$requestBytes,
                'redactor_changed_count_total'=>$redactorChanges,'request_preflight'=>$preflights,
                'preflight_records_private'=>true,'request_byte_total_includes_policy_counterfactuals'=>true,
                'input_token_count'=>'unknown_without_provider_usage',
                'proposal_inputs_sha256'=>$input['proposal_inputs_sha256'],
                'criteria_sha256'=>$input['criteria_sha256'],'selection_freeze_sha256'=>$input['selection_freeze_sha256'],
                'source_manifest'=>self::sourceManifest(),
                'dependency_sha256'=>constant('MANAGER_AI_ANEX_B36_DEPENDENCIES')];
            self::privateRecord($directory.'/header.json',$header);self::write($header);
            $batchStarted=hrtime(true);
            $records=self::parallel($cases,(int)$options['parallelism'],$options['stage'],$directory,
                static function(array $record):void {self::write($record);},null,
                self::timestamp($lease['expires_at']),$evaluationStarted+self::MAX_DURATION_MS*1000000);
            $workerDurationMs=(int)round((hrtime(true)-$batchStarted)/1000000);
            $durationMs=(int)round((hrtime(true)-$evaluationStarted)/1000000);
            self::verifyProduction($options['production-sha']);
            $footer=['type'=>'footer','complete'=>count($records)===count($cases),
                'finished_at'=>gmdate('Y-m-d\TH:i:s\Z'),'expected_cases'=>count($cases),
                'exported_cases'=>count($records),'duration_ms'=>$durationMs,
                'duration_scope'=>'remote_evaluation_total','worker_loop_duration_ms'=>$workerDurationMs,
                'parallelism'=>(int)$options['parallelism'],'stage'=>$options['stage']];
            self::privateRecord($directory.'/footer.json',$footer);self::write($footer);
        } finally {
            if (is_string($keypair) && $keypair !== '') sodium_memzero($keypair);
            if (is_string($plain) && $plain !== '') sodium_memzero($plain);
            try {
                // A non-owner must never unlink the active owner's lease.
                // Explicit cleanup follows collection and durable save; no provider replay.
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
            || ($request['reasoning']['effort']??null)!=='low' || ($request['service_tier']??null)!=='default'
            || ($request['max_output_tokens']??null)!==2200 || ($request['store']??null)!==false) {
            throw new RuntimeException('requested_profile_mismatch');
        }
        // The requested profile is distinct from allowlisted response metadata.
        // A requested Standard tier never proves the tier that served a response.
        return ['model'=>$request['model'],'reasoning_effort'=>$request['reasoning']['effort'],
            'service_tier'=>$request['service_tier']];
    }

    private static function privateRecord(string $path,array $record):void
    {
        if (is_link($path) || file_exists($path)) throw new RuntimeException('record_exists');
        $bytes=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $h=fopen($path,'x');if ($h===false) throw new RuntimeException('record_unavailable');
        try {if (!chmod($path,0600) || fwrite($h,$bytes)!==strlen($bytes) || !fflush($h)) throw new RuntimeException('record_unavailable');}
        finally {fclose($h);}
    }

    /** Children inherit credentials in memory; no key in files, payloads or argv. */
    private static function parallel(array $cases,int $limit,string $stage,string $directory,callable $emit,?callable $transport=null,
        ?int $leaseExpiresAt=null,?int $deadlineNs=null):array
    {
        if ($limit!==1 || count($cases)<1 || count($cases)>self::CASES
            || !function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) throw new RuntimeException('parallel_unavailable');
        $active=[];$records=[];$next=0;$count=count($cases);$halt=false;$errors=0;
        $leaseExpiresAt=$leaseExpiresAt??time()+self::TTL;
        $deadlineNs=$deadlineNs??hrtime(true)+self::MAX_DURATION_MS*1000000;
        while ((!$halt && $next<$count) || $active) {
            if (!self::canLaunch($leaseExpiresAt,$deadlineNs)) $halt=true;
            while (!$halt && $next<$count && count($active)<$limit) {
                if (!self::canLaunch($leaseExpiresAt,$deadlineNs)) {$halt=true;break;}
                $index=$next+1;$case=$cases[$next++];
                self::privateRecord($directory.'/started-'.$index.'.json',['index'=>$index,'case_id'=>$case['case_id'],'status'=>'started']);
                $pid=pcntl_fork();if ($pid===-1) throw new RuntimeException('fork_failed');
                if ($pid===0) {
                    try {self::privateRecord($directory.'/result-'.$index.'.json',self::runCase($case,$index,$stage,$transport));exit(0);}
                    catch (Throwable $ignored) {exit(2);}
                }
                $active[$pid]=['index'=>$index,'case_id'=>$case['case_id']];
            }
            if (!$active) break;
            $status=0;$pid=pcntl_waitpid(-1,$status,WNOHANG);
            if ($pid===0) {usleep(10000);continue;}
            if ($pid<0 || !isset($active[$pid])) throw new RuntimeException('worker_unavailable');
            $item=$active[$pid];unset($active[$pid]);$file=$directory.'/result-'.$item['index'].'.json';
            if (is_file($file) && !is_link($file) && filesize($file)<=262144) $record=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
            else $record=['type'=>'case','index'=>$item['index'],'case_id'=>$item['case_id'],'stage'=>$stage,
                'provider_calls'=>null,'result_origin'=>'unknown','elapsed_ms'=>null,'provider_roundtrip_ms'=>null,
                'provider_metadata'=>null,'result'=>['ok'=>false,'error'=>'worker_unknown']];
            $records[]=$record;$emit($record);
            // Preserve failures for review; do not replay a submitted case.
            if (($record['result']['ok']??false)!==true) ++$errors;
            if ($errors>=3 || $record['result_origin']==='unknown') $halt=true;
        }
        return $records;
    }

    private static function runCase(array $case,int $index,string $stage,?callable $transport=null):array
    {
        if ($stage!=='generate') throw new RuntimeException('invalid_stage');
        $started=hrtime(true);
        self::validateCase($case,time()+60,$index);
        $projectionStarted=hrtime(true);
        $projection=self::caseProjection($case);
        $projectionMs=(int)round((hrtime(true)-$projectionStarted)/1000000);
        $calls=0;$metadata=null;$observations=0;$selectionReads=0;$roundtripMs=null;$outgoingProfile=null;$outgoingHash=null;
        $observe=static function(array $v)use(&$metadata,&$observations):void {++$observations;$metadata=$observations===1?self::providerMetadata($v):null;};
        $transport=$transport??[ManagerDraftClient::class,'requestJson'];
        $reader=null;
        $result=ManagerAiDraftService::generate($projection['context'],static function(string $instructions,array $payload)use(&$calls,$transport,$observe,&$roundtripMs,&$outgoingProfile,&$outgoingHash):array {
            if ($calls>=1) throw new RuntimeException('provider_budget_exceeded');
            $body=ManagerDraftClient::requestBody($instructions,$payload);
            $outgoingProfile=self::requestedProfile($body);
            $wire=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if (strlen($wire)>ManagerDraftClient::MAX_REQUEST_BYTES) throw new RuntimeException('actual_request_too_large');
            $outgoingHash=hash('sha256',$wire);
            ++$calls;$providerStarted=hrtime(true);
            try {return $transport($instructions,$payload,$observe);}
            finally {$roundtripMs=(int)round((hrtime(true)-$providerStarted)/1000000);}
        },null,$reader);
        return ['type'=>'case','index'=>$index,'case_id'=>$case['case_id'],'stage'=>$stage,'provider_calls'=>$calls,
            'result_origin'=>$calls===0?'policy':'model','elapsed_ms'=>(int)round((hrtime(true)-$started)/1000000),
            'provider_metadata'=>$metadata,'outgoing_requested_profile'=>$outgoingProfile,
            'outgoing_canonical_request_sha256'=>$outgoingHash,'result'=>$result,'provenance_kind'=>$case['provenance']['kind'],
            'selection_reads'=>$selectionReads,'live_selection_fetches'=>0,'company_knowledge'=>'explicit_empty_offline',
            'redactor_changed_count'=>$projection['redactor_changed_count'],'provider_roundtrip_ms'=>$roundtripMs,
            'elapsed_scope'=>'service_total','context_projection_ms'=>$projectionMs,
            'context_projection_scope'=>'offline_redactor_only'];
    }

    private static function collect(array $options):void
    {
        $d=self::directory($options['task-token']);$p=$d.'/header.json';
        if (is_link($d) || !is_dir($d) || is_link($p) || !is_file($p)) throw new RuntimeException('receipt_unavailable');
        $header=json_decode((string)file_get_contents($p),true,32,JSON_THROW_ON_ERROR);
        foreach (['task_token'=>'task-token','production_sha'=>'production-sha','candidate_sha'=>'candidate-sha','source_bundle_sha256'=>'bundle-sha256','output_recipient_sha256'=>'recipient-sha256'] as $a=>$b)
            if (($header[$a]??null)!==$options[$b]) throw new RuntimeException('receipt_mismatch');
        self::write($header);$done=0;
        for ($i=1;$i<=(int)$header['expected_cases'];++$i) {
            $p=$d.'/result-'.$i.'.json';if (is_link($p)) throw new RuntimeException('receipt_invalid');
            if (is_file($p) && filesize($p)<=262144) {self::write(json_decode((string)file_get_contents($p),true,32,JSON_THROW_ON_ERROR));++$done;}
        }
        $f=$d.'/footer.json';
        if (is_file($f) && !is_link($f)) self::write(json_decode((string)file_get_contents($f),true,32,JSON_THROW_ON_ERROR));
        else self::write(['type'=>'footer','complete'=>false,'exported_cases'=>$done,'expected_cases'=>$header['expected_cases'],'collected_only'=>true]);
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

    private static function validateLease($lease, array $options): void
    {
        if (!is_array($lease) || ($lease['schema']??'')!=='manager_ai_anex_b36_lease_v1'
            || ($lease['repository']??'')!==self::REPOSITORY
            || ($lease['task_token']??'')!==$options['task-token']
            || ($lease['production_sha']??'')!==$options['production-sha']
            || ($lease['candidate_sha']??'')!==$options['candidate-sha']
            || ($lease['source_bundle_sha256']??'')!==$options['bundle-sha256']
            || ($lease['output_recipient_sha256']??'')!==$options['recipient-sha256']
            || ($lease['case_count']??0)!==(int)$options['case-count']
            || ($lease['parallelism']??0)!==(int)$options['parallelism']
            || ($lease['stage']??'')!==$options['stage']
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
        $manifest=self::sourceManifest();
        $keys=['schema','repository','task_token','production_sha','candidate_sha','source_bundle_sha256',
            'output_recipient_sha256','stage','lease_id','generated_at','expires_at','cases',
            'proposal_inputs_sha256','criteria_sha256','selection_freeze_sha256'];
        if (!is_array($input) || array_is_list($input) || count($input)!==count($keys)
            || array_diff(array_keys($input),$keys)
            || ($input['schema']??'')!==self::INPUT_SCHEMA || ($input['repository']??'')!==self::REPOSITORY
            || ($input['task_token']??'')!==$options['task-token']
            || ($input['production_sha']??'')!==$options['production-sha']
            || ($input['candidate_sha']??'')!==$options['candidate-sha']
            || ($input['source_bundle_sha256']??'')!==$options['bundle-sha256']
            || ($input['output_recipient_sha256']??'')!==$options['recipient-sha256']
            || ($input['stage']??'')!==$options['stage'] || ($input['lease_id']??'')!==$lease['lease_id']
            || self::timestamp($input['generated_at']??null)>time()+60
            || time()-self::timestamp($input['generated_at'])>self::TTL
            || self::timestamp($input['generated_at'])<self::timestamp($lease['created_at'])
            || self::timestamp($input['expires_at']??null)>self::timestamp($lease['expires_at'])
            || self::timestamp($input['expires_at'])<=self::timestamp($input['generated_at'])
            || self::timestamp($input['expires_at'])<=time()
            || !is_array($input['cases']??null) || !array_is_list($input['cases'])
            || count($input['cases'])!==self::CASES || (int)$options['case-count']!==self::CASES
            || (int)$options['parallelism']!==1) throw new RuntimeException('input_invalid');
        foreach (['proposal_inputs_sha256','criteria_sha256','selection_freeze_sha256'] as $key)
            if (($input[$key]??null)!==$manifest[$key]) throw new RuntimeException('freeze_binding_mismatch');
        foreach ($input['cases'] as $offset=>$case) self::validateCase($case,self::timestamp($input['generated_at']),$offset+1);
        if (!hash_equals($manifest['proposal_inputs_sha256'],self::proposalHash($input['cases'])))
            throw new RuntimeException('proposal_membership_mismatch');
        return $input['cases'];
    }

    /** Last launch leaves the canonical 20-second request and cleanup margin. */
    private static function canLaunch(int $leaseExpiresAt,int $deadlineNs):bool
    {
        return time()+self::LAUNCH_MARGIN_SECONDS<$leaseExpiresAt
            && hrtime(true)+self::LAUNCH_MARGIN_SECONDS*1000000000<=$deadlineNs;
    }

    /** Shared pure canonical redaction; no metadata enters the model context. */
    private static function caseProjection(array $case):array
    {
        $context=[];foreach ($case['context'] as $key=>$value) $context[$key]=$value;
        $rows=[];$changed=0;
        $anyUnread=$context['selections_link_unread']??false;
        foreach ($context['messages'] as $message) {
            $row=[];foreach ($message as $key=>$value) $row[$key]=$value;
            $unread=$row['selections_link_unread']??false;$links=false;
            $text=ManagerAiTextRedactor::redact($row['text'],$unread,$links);
            if ($text!==$row['text']) ++$changed;
            $row['text']=$text;
            if ($unread || array_key_exists('selections_link_unread',$row)) $row['selections_link_unread']=$unread;
            $anyUnread=$anyUnread||$unread;$rows[]=$row;
        }
        $context['messages']=$rows;$context['selections_link_unread']=$anyUnread;
        return ['context'=>$context,'redactor_changed_count'=>$changed];
    }

    /** Pure no-provider capture, including a labeled counterfactual for policy. */
    private static function preflightCase(array $case,int $index,int $generated):array
    {
        self::validateCase($case,$generated,$index);
        $projection=self::caseProjection($case);$captured=null;$calls=0;
        $result=ManagerAiDraftService::generate($projection['context'],
            static function(string $instructions,array $payload)use(&$captured,&$calls):array {
                ++$calls;$captured=[$instructions,$payload];return ['_error'=>'provider_unavailable'];
            },null,null);
        if ($calls>1) throw new RuntimeException('preflight_capture_invalid');
        if ($captured===null) {
            if (($result['ok']??false)!==true) throw new RuntimeException('preflight_context_invalid');
            $normalizer=new ReflectionMethod(ManagerAiDraftService::class,'context');
            $captured=[ManagerAiDraftService::instructions(),$normalizer->invoke(null,$projection['context'])];
        }
        [$instructions,$payload]=$captured;
        $body=ManagerDraftClient::requestBody($instructions,$payload);
        $bytes=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (strlen($bytes)>ManagerDraftClient::MAX_REQUEST_BYTES) throw new RuntimeException('preflight_request_too_large');
        return ['index'=>$index,'case_id'=>$case['case_id'],'provider_planned'=>$calls===1,
            'canonical_request_encoded_bytes'=>strlen($bytes),'canonical_request_sha256'=>hash('sha256',$bytes),
            'payload_sha256'=>hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
            'instructions_sha256'=>hash('sha256',$instructions),'requested_profile'=>self::requestedProfile($body),
            'redactor_changed_count'=>$projection['redactor_changed_count'],
            'normalized_context'=>$payload,'normalized_context_scope'=>'canonical_redacted_input'];
    }

    /** Canonical sorted UTF8 JSON, distinct from canonical provider wire ordering. */
    private static function stableValue($value)
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value,SORT_STRING);
        foreach ($value as $key=>$item) $value[$key]=self::stableValue($item);
        return $value;
    }

    private static function stableHash($value):string
    {
        return hash('sha256',json_encode(self::stableValue($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
            |JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR));
    }

    private static function proposalHash(array $cases):string
    {
        return self::stableHash(['cases'=>$cases,'grader_calls'=>0,'provider_calls'=>0,
            'schema'=>'anex_b36_frozen_development_cases_v1','status'=>'unsubmitted']);
    }

    private static function sourceManifest():array
    {
        if (!defined('MANAGER_AI_ANEX_B36_SOURCE') || !is_array(MANAGER_AI_ANEX_B36_SOURCE)
            || (MANAGER_AI_ANEX_B36_SOURCE['schema']??'')!=='manager_ai_anex_b36_source_v1'
            || (MANAGER_AI_ANEX_B36_SOURCE['production_sha']??'')!=='d86dccd229a9d7bb318bcaf59bd6960305e0cb4f'
            || (MANAGER_AI_ANEX_B36_SOURCE['candidate_sha']??'')!=='f687eebceb105628dc06005d01efbb54955eef7b')
            throw new RuntimeException('source_manifest_invalid');
        return MANAGER_AI_ANEX_B36_SOURCE;
    }

    /** Frozen development provenance remains private and never enters model input. */
    private static function validateCase($case,int $generated,?int $index=null):void
    {
        if (!is_array($case) || array_is_list($case) || count($case)!==3
            || array_diff(array_keys($case),['case_id','context','provenance'])
            || !is_string($case['case_id']??null) || preg_match('/\A B36-([0-9]{3})\z/Dx',$case['case_id'],$match)!==1
            || (int)$match[1]<1 || (int)$match[1]>self::CASES
            || ($index!==null && $case['case_id']!=='B36-'.str_pad((string)$index,3,'0',STR_PAD_LEFT))
            || !is_array($case['context']??null) || !is_array($case['provenance']??null)) throw new RuntimeException('input_invalid');
        $context=$case['context'];$p=$case['provenance'];
        $allowed=['conversation_id','as_of_message_id','messages','history_incomplete','media_unread','selections_link_unread','verified_company_facts'];
        if (array_diff(array_keys($context),$allowed) || ($context['verified_company_facts']??null)!==[])
            throw new RuntimeException('input_invalid');
        $normalizer=new ReflectionMethod(ManagerAiDraftService::class,'context');
        $normalized=$normalizer->invoke(null,$context);
        if (!is_array($normalized) || isset($normalized['error'])) throw new RuntimeException('invalid_context');
        foreach ($context['messages'] as $message) {
            if (array_diff(array_keys($message),['id','sender_type','text','event_type','created_at','media_unread','selections_link_unread','text_truncated'])
                || self::timestamp($message['created_at']??null)>$generated) throw new RuntimeException('invalid_context_fields');
        }
        if (($p['kind']??'')==='previously_exposed_anex_development') {
            if (count($p)!==6 || array_diff(array_keys($p),['kind','exact_context_reused','future_messages_added','source_input_id','source_input_sha256','context_sha256'])
                || ($p['exact_context_reused']??null)!==true || ($p['future_messages_added']??null)!==0
                || !is_string($p['source_input_id']??null) || preg_match('/\APX33-(?:0[1-35-9]|1[0-2])\z/D',$p['source_input_id'])!==1
                || !is_string($p['source_input_sha256']??null) || preg_match('/\A[0-9a-f]{64}\z/D',$p['source_input_sha256'])!==1
                || !is_string($p['context_sha256']??null) || !hash_equals(self::stableHash($context),$p['context_sha256'])) throw new RuntimeException('invalid_provenance');
            return;
        }
        if (($p['kind']??'')!=='authored_synthetic_transport_fixture' || count($p)!==2
            || array_diff(array_keys($p),['kind','control']) || !is_string($p['control']??null)
            || preg_match('/\A[a-z][a-z_]{0,79}\z/D',$p['control'])!==1
            || $context['conversation_id']!==2000000+(int)$match[1]) throw new RuntimeException('invalid_provenance');
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
        $manifest=self::sourceManifest();
        if ($expected!==$manifest['production_sha']) throw new RuntimeException('production_sha_mismatch');
        if (getcwd()!==self::ROOT || !is_file(self::ROOT.'/config.php') || is_link(self::ROOT.'/config.php')) {
            throw new RuntimeException('production_root_mismatch');
        }
        $lines = [];
        $status = 1;
        exec('git -C '.escapeshellarg(self::ROOT).' rev-parse HEAD 2>/dev/null',$lines,$status);
        if ($status!==0 || count($lines)!==1 || !hash_equals($expected,trim($lines[0]))) {
            throw new RuntimeException('production_sha_mismatch');
        }
        foreach ($manifest['production_dependency_sha256'] as $relative=>$hash) {
            $path=self::ROOT.'/'.$relative;
            if (is_link($path) || !is_file($path) || !hash_equals($hash,hash_file('sha256',$path)))
                throw new RuntimeException('production_dependency_mismatch');
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
            foreach (glob($directory.'/*.json')?:[] as $file) {
                if (is_link($file) || !preg_match('/\A(?:header|footer|started-[0-9]{1,3}|result-[0-9]{1,3})\.json\z/D',basename($file))
                    || !@unlink($file)) return false;
            }
            return self::removeProof($token) && @rmdir($directory);
        } finally {
            if ($ownLock) { flock($handle,LOCK_UN); fclose($handle); }
        }
    }

    private static function proofPath(string $token): string
    {
        self::directory($token);
        return self::ROOT.'/manager2/assets/anex-b36-proof-'.$token.'.json';
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

if (!defined('MANAGER_AI_BULK_LIBRARY_ONLY')) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
    exit(ManagerAiBulkEval::main($_SERVER['argv']??[]));
}
