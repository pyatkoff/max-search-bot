<?php
declare(strict_types=1);

/** Bundled on stdin by the single admitted operation; no HTTP/request mode. */
final class Max2HoldPairReadOperation
{
    public const APP = '/var/www/anytoour/data/www/app.anytoour.ru';
    public const VERSION = 'max2_original_hold_pair_operation_v1';
    public const OPERATION = 'max2_hold_pair_20261123';
    // Filled from exact, Git-blob-verified source during local package assembly.
    public const BOOTSTRAP_HASHES = [
        'tools/max2_common_value_state_v2.php'=>'c9fa5aef544d77a8c31e704d20dd5019c7bf0ddeb6ddbe639e0665dc27efa5ce',
        'services/ConversationDb.php'=>'8a3c8ef5625aaa24294ba5b9799613920a51a0355c431d0d629662b887652fe1',
        'services/ProjectConfig.php'=>'06321888bc597ac24bb04308b4b04fe4687a36d3b3143124c4e45c414e1adcf7',
        'services/ProjectConfiguration.php'=>'a8c8d1e76eb9367dbe10cfe47de7883958f6daaa40039c0d1f2f6666c0e6331f',
        'services/TourSearchHandoffService.php'=>'3577818bc91f2127418e387f40b50bfc2f52f635a2b56052d8c3a518b60f2ba4',
        'services/ChildAgeValueContract.php'=>'282899f8865a3cc89be5f18997b803f01460791a86674053c6f632ef7098a5df',
        'services/InternalOwnerDialogueScope.php'=>'cf44c20f9f868d82c35c9e4491e060af4c8232105a0b59f89a377f78cecc57d4',
    ];
    private const FAILURES = ['OPERATION_SCOPE','INSTALLED_SOURCE_CHANGED','BOOTSTRAP_SOURCE_CHANGED',
        'PRIVATE_CONFIG_UNAVAILABLE','PRIVATE_CONFIG_CHANGED','BOOTSTRAP_OUTPUT','PROJECT_UNAVAILABLE',
        'STORAGE_UNAVAILABLE','NATIVE_OUTPUT_BOUND','READ_BUDGET_EXCEEDED','OPERATION_UNAVAILABLE'];

    private static function need(bool $ok, string $code): void
    { if (!$ok) throw new RuntimeException($code); }

    private static function source(): void
    {
        foreach (self::BOOTSTRAP_HASHES as $name => $digest) {
            $path = self::APP . '/' . $name;
            clearstatcache(true, $path);
            self::need(is_file($path) && !is_link($path) && realpath($path) === $path
                && hash_file('sha256', $path) === $digest, 'BOOTSTRAP_SOURCE_CHANGED');
        }
    }

    private static function privateConfig(): array
    {
        $out = [];
        foreach (['config.php','project.php'] as $name) {
            $path = self::APP . '/' . $name;
            clearstatcache(true, $path);
            self::need(is_file($path) && !is_link($path) && realpath($path) === $path
                && filesize($path) > 0 && filesize($path) <= 262144, 'PRIVATE_CONFIG_UNAVAILABLE');
            $digest = hash_file('sha256', $path);
            self::need(is_string($digest), 'PRIVATE_CONFIG_UNAVAILABLE');
            $out[$name] = $digest; // Never returned, logged or used as permission.
        }
        return $out;
    }

    private static function project(PDO $pdo): void
    {
        self::need(ProjectConfig::projectId() === 'anytour' && defined('METRIKA_COUNTER_ID')
            && (string)METRIKA_COUNTER_ID === '98615635', 'PROJECT_UNAVAILABLE');
        $q = $pdo->prepare("SELECT /*+ MAX_EXECUTION_TIME(250) */ id,is_active FROM projects"
            . " WHERE project_key='anytour' AND HEX(project_key)=HEX('anytour') LIMIT 2");
        $q->execute(); $rows = $q->fetchAll(PDO::FETCH_ASSOC); $q->closeCursor();
        self::need(count($rows) === 1 && (int)$rows[0]['id'] > 0 && (int)$rows[0]['is_active'] === 1,
            'PROJECT_UNAVAILABLE');
    }

    /** Only a canonical accepted application SHA, never a profile/time selector. */
    public static function main(string $applicationSha): int
    {
        ini_set('display_errors', '0'); ini_set('display_startup_errors', '0'); ini_set('log_errors', '0');
        error_reporting(0); $buffer = false; $started = hrtime(true);
        try {
            self::need(PHP_SAPI === 'cli' && realpath(self::APP) === self::APP && getcwd() === self::APP
                && preg_match('/\A[a-f0-9]{40}\z/D', $applicationSha) === 1, 'OPERATION_SCOPE');
            self::source();
            require_once self::APP . '/tools/max2_common_value_state_v2.php';
            self::need(Max2CommonValueStateCliV2::installedHead(self::APP) === $applicationSha, 'INSTALLED_SOURCE_CHANGED');
            $configBefore = self::privateConfig();
            ob_start(); $buffer = true;
            require_once self::APP . '/config.php';
            require_once self::APP . '/services/ProjectConfig.php';
            require_once self::APP . '/services/ConversationDb.php';
            require_once self::APP . '/services/InternalOwnerDialogueScope.php';
            Max2HoldCandidateDiagnosis::initialize(self::APP . '/services');
            $pdo = ConversationDb::connection();
            self::need($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && !$pdo->inTransaction(), 'STORAGE_UNAVAILABLE');
            // project.php is loaded lazily by ProjectConfig. Keep the buffer
            // through every authorization/read so no private bootstrap output escapes.
            $authorize = static function() use ($pdo, $applicationSha, $configBefore, $started): void {
                self::need(hrtime(true) - $started <= 8000000000, 'READ_BUDGET_EXCEEDED');
                self::need(Max2CommonValueStateCliV2::installedHead(self::APP) === $applicationSha, 'INSTALLED_SOURCE_CHANGED');
                self::need(self::privateConfig() === $configBefore, 'PRIVATE_CONFIG_CHANGED');
                self::project($pdo);
            };
            $authorize();
            $result = (new Max2OriginalHoldPairReader($pdo, $authorize))->read();
            $authorize(); self::source();
            $bootstrapOutput = ob_get_clean(); $buffer = false;
            self::need($bootstrapOutput === '', 'BOOTSTRAP_OUTPUT'); unset($bootstrapOutput);
            self::need(!is_array($result['cases'] ?? null) || count($result['cases']) === 2, 'NATIVE_OUTPUT_BOUND');
            $out = ['version'=>self::VERSION,'operation'=>self::OPERATION,'application_sha'=>$applicationSha,
                'observed_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'result'=>$result,
                'automatic_retry'=>false,'worker_invoked'=>false,'v1_invoked'=>false,'provider_requests'=>0];
            $json = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            self::need(strlen($json) <= 32768, 'NATIVE_OUTPUT_BOUND');
            echo $json . "\n";
            return ($result['status'] ?? null) === 'OBSERVED' ? 0 : 2;
        } catch (Throwable $e) {
            if ($buffer) ob_end_clean();
            $reason = get_class($e) === RuntimeException::class && in_array($e->getMessage(), self::FAILURES, true)
                ? $e->getMessage() : 'OPERATION_UNAVAILABLE';
            echo json_encode(['version'=>self::VERSION,'operation'=>self::OPERATION,'status'=>'UNAVAILABLE',
                'reason'=>$reason,'automatic_retry'=>false,'worker_invoked'=>false,'v1_invoked'=>false,
                'provider_requests'=>0], JSON_THROW_ON_ERROR) . "\n";
            return 2;
        }
    }
}
