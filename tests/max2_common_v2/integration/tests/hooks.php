<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

function hookNeed(bool $ok, string $reason): void { if (!$ok) throw new RuntimeException('OPTIONAL_HOOK_' . $reason); }
function hookRemove(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) hookRemove($entry->getPathname());
    rmdir($path);
}
$app = dirname(__DIR__, 4);
$realApp = dirname((string)realpath($app . '/services'));
$temp = sys_get_temp_dir() . '/max2-v2-hook-' . bin2hex(random_bytes(12));
hookNeed(mkdir($temp, 0700), 'PRIVATE_TEMP');
register_shutdown_function(static function() use ($temp): void { hookRemove($temp); });
function hookWrite(string $root, string $relative, string $bytes): void {
    $path = $root . '/' . $relative;
    if (!is_dir(dirname($path))) hookNeed(mkdir(dirname($path), 0700, true), 'TEST_PARENT');
    hookNeed(file_put_contents($path, $bytes) === strlen($bytes), 'TEST_WRITE');
}
function hookRun(string $root, string $program): string {
    hookWrite($root, 'probe.php', "<?php\ndeclare(strict_types=1);\n" . $program);
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'allow_url_fopen=0',
        '-d', 'allow_url_include=0', '-d', 'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail',
        $root . '/probe.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        $root, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    hookNeed(is_resource($process), 'CHILD');
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    hookNeed(proc_close($process) === 0 && $err === '', 'CHILD_FAILED_' . $err);
    return $out;
}
$checks = 0;
$webhook = (string)file_get_contents($realApp . '/webhook.php');
$mark = "\n// MAX2 V2 optional message observation begins.\n";
$parts = explode($mark, $webhook);
hookNeed(count($parts) === 2 && hash('sha256', $parts[0]) === '18beb4bcf8b4703c7cc85139dc6566750ed4a72928f54a8c336fbd020fff2ac3',
    'CANONICAL_WEBHOOK_BYTES_UNCHANGED'); $checks++;
foreach (['absent', 'observed', 'throws'] as $case) {
    $root = $temp . '/webhook-' . $case; hookNeed(mkdir($root, 0700), 'CASE');
    hookWrite($root, 'webhook.php', $webhook);
    hookWrite($root, 'config.php', '<?php');
    hookWrite($root, 'maxsearchclass.php', '<?php');
    hookWrite($root, 'services/RuntimeBootstrap.php', '<?php final class RuntimeBootstrap { public static function boot($root): void {} }');
    hookWrite($root, 'services/WebhookRuntimeLogger.php', '<?php final class WebhookRuntimeLogger { public static function input($x): void {} public static function output($x): void {} }');
    hookWrite($root, 'handlers/MaxUpdateHandler.php', '<?php final class MaxUpdateHandler { public static function handle(): void { $GLOBALS["order"][]="canonical"; echo "CANONICAL_REPLY"; } }');
    if ($case !== 'absent') hookWrite($root, 'services/Max2CommonValueRuntimeV2.php', '<?php final class Max2CommonValueRuntimeV2 { public static function captureMessage(): void { $GLOBALS["order"][]="observer";' .
        ($case === 'throws' ? ' throw new RuntimeException("synthetic observer failure");' : '') . ' } }');
    $out = hookRun($root, '$GLOBALS["order"]=[]; ob_start(); require __DIR__."/webhook.php"; $reply=ob_get_clean(); echo json_encode([$reply,$GLOBALS["order"]]);');
    hookNeed(json_decode($out, true, 16, JSON_THROW_ON_ERROR) === ['CANONICAL_REPLY', $case === 'absent' ? ['canonical'] : ['canonical','observer']],
        'WEBHOOK_RESULT_' . $case); $checks++;
}

$entry = (string)file_get_contents($realApp . '/services/ChannelPostEntryService.php');
foreach (['absent', 'observed', 'throws', 'registry_throws', 'unavailable'] as $case) {
    $root = $temp . '/entry-' . $case; hookNeed(mkdir($root, 0700), 'CASE');
    hookWrite($root, 'services/ChannelPostEntryService.php', $entry);
    hookWrite($root, 'services/ChannelPostRuntime.php', '<?php final class ChannelPostRuntime {}');
    hookWrite($root, 'services/ChannelPostRegistryService.php', '<?php final class ChannelPostRegistryService {' .
        ' public function recordEntry($id,$resolved,$key,$legacy,$preserved): array {' .
        ' $GLOBALS["order"][]="saved";' . ($case === 'registry_throws' ? 'throw new RuntimeException("synthetic save failed");' : 'return ["entry_id"=>"17","version_id"=>"19","duplicate"=>false];') . ' }' .
        ' public function recordUnavailableEntry($id,$project,$platform,$key): array { $GLOBALS["order"][]="unavailable"; return ["entry_id"=>"17","unavailable"=>true]; } }');
    if ($case !== 'absent') hookWrite($root, 'services/Max2CommonValueRuntimeV2.php', '<?php final class Max2CommonValueRuntimeV2 {' .
        ' public static function captureEntry($record,$conversation,$resolved,$key): void { $GLOBALS["order"][]="observer";' .
        ' if ($record!==["entry_id"=>"17","version_id"=>"19","duplicate"=>false] || (string)$conversation["id"]!=="30" || $resolved["publication_id"]!=="18" || $key!=="synthetic-event") throw new LogicException("wrong observer tuple");' .
        ($case === 'throws' ? 'throw new RuntimeException("synthetic observer failed");' : '$GLOBALS["tuple_matched"]=true;') . ' } }');
    $probe = <<<'PHP'
final class ProjectConfig { public static function projectId(): string { return 'anytour'; } }
final class ConversationDb {
    public static PDO $db;
    public static function isConfigured(): bool { return true; }
    public static function connection(): PDO { return self::$db; }
}
ConversationDb::$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
ConversationDb::$db->exec('CREATE TABLE conversations(id INTEGER,project_key TEXT,channel TEXT,status TEXT,manager_id INTEGER,external_chat_id TEXT)');
ConversationDb::$db->exec("INSERT INTO conversations VALUES(30,'anytour','max','manager',7,'-12345')");
$GLOBALS['order']=[]; $GLOBALS['tuple_matched']=false;
require __DIR__.'/services/ChannelPostEntryService.php';
$resolved=['project_key'=>'anytour','platform'=>'max','publication_id'=>'18','version_id'=>'19'];
PHP;
    $probe .= $case === 'unavailable' ? '$resolved=null;' : '';
    $probe .= ' $r=ChannelPostEntryService::record("max","-12345",$resolved,"synthetic-event"); echo json_encode([$r,$GLOBALS["order"],$GLOBALS["tuple_matched"]]);';
    $got = json_decode(hookRun($root, $probe), true, 32, JSON_THROW_ON_ERROR);
    if ($case === 'registry_throws') $expected = [null, ['saved'], false];
    elseif ($case === 'unavailable') $expected = [['entry_id'=>'17','unavailable'=>true], ['unavailable'], false];
    else $expected = [['entry_id'=>'17','version_id'=>'19','duplicate'=>false], $case === 'absent' ? ['saved'] : ['saved','observer'], $case === 'observed'];
    hookNeed($got === $expected, 'EXACT_ENTRY_RETURN_' . $case); $checks++;
}

$cron = (string)file_get_contents($realApp . '/cron_metrika.php');
$begin = strpos($cron, '// MAX2 V2 independent shutdown observation begins.');
$endMarker = "// MAX2 V2 independent shutdown observation ends.\n\n";
$end = strpos($cron, $endMarker);
hookNeed(is_int($begin) && is_int($end) && $end > $begin, 'CRON_HOOK_BOUNDS');
$block = substr($cron, $begin, $end + strlen($endMarker) - $begin);
hookNeed(hash('sha256', str_replace($block, '', $cron)) === 'd60c5878f58700e054291b52d05ea08d359ab80d47c4ec117bafb40c21798f33'
    && substr_count(substr($cron, 0, $begin), 'register_shutdown_function(') === 2, 'BOTH_OLD_CRON_PATHS_UNCHANGED'); $checks++;
foreach (['absent', 'observed', 'throws'] as $case) {
    $root = $temp . '/cron-' . $case; hookNeed(mkdir($root, 0700), 'CASE');
    hookWrite($root, 'shutdown.php', "<?php\n" . $block);
    if ($case !== 'absent') hookWrite($root, 'services/Max2CommonValueRuntimeV2.php', '<?php final class Max2CommonValueRuntimeV2 { public static function tick(): array {' .
        ' $GLOBALS["order"][]="new";' . ($case === 'throws' ? 'throw new RuntimeException("synthetic worker failure");' : 'return ["status"=>"OFF"];') . ' } }');
    $out = hookRun($root, '$GLOBALS["order"]=[]; register_shutdown_function(static function(){ $GLOBALS["order"][]="old1"; }); register_shutdown_function(static function(){ $GLOBALS["order"][]="old2"; }); require __DIR__."/shutdown.php"; register_shutdown_function(static function(){ echo json_encode($GLOBALS["order"]); });');
    hookNeed(json_decode($out, true, 16, JSON_THROW_ON_ERROR) === ($case === 'absent' ? ['old1','old2'] : ['old1','old2','new']),
        'CRON_SEQUENCE_' . $case); $checks++;
}
require_once $realApp . '/services/Max2CommonValueRuntimeV2.php';
hookNeed(Max2CommonValueRuntimeV2::tick() === ['status'=>'OFF','provider_calls'=>0,'old_stream_invoked'=>false],
    'ACTUAL_RUNTIME_WITHOUT_CONFIG_HAS_NO_EFFECT'); $checks++;
$root = $temp . '/runtime-settings-unavailable'; hookNeed(mkdir($root, 0700), 'CASE');
$probe = <<<'PHP'
final class ProjectConfig { public static function projectId(): string { return 'anytour'; } }
final class RuntimeUnavailablePDO extends PDO {
    public function __construct() {}
    public function getAttribute(int $attribute): mixed {
        return $attribute===PDO::ATTR_DRIVER_NAME ? 'mysql' : ($attribute===PDO::ATTR_ERRMODE ? PDO::ERRMODE_EXCEPTION : null);
    }
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        $GLOBALS['settings_reads']++;
        if ($query!=='SELECT * FROM max2_common_settings_v2 WHERE id=1') throw new LogicException('unexpected query');
        throw new PDOException('synthetic unavailable settings table');
    }
}
final class ConversationDb {
    public static function isConfigured(): bool { return true; }
    public static function connection(): PDO { return new RuntimeUnavailablePDO(); }
}
$GLOBALS['settings_reads']=0;
PHP;
$probe .= ' require ' . var_export($realApp . '/services/Max2CommonValueRuntimeV2.php', true)
    . '; echo json_encode([Max2CommonValueRuntimeV2::tick(),$GLOBALS["settings_reads"]]);';
hookNeed(json_decode(hookRun($root, $probe), true, 32, JSON_THROW_ON_ERROR) === [
    ['status'=>'HOLD','reason'=>'V2_SETTINGS_UNAVAILABLE','provider_calls'=>0,'old_stream_invoked'=>false],1],
    'ACTUAL_RUNTIME_UNKNOWN_SETTINGS_IS_HOLD_NOT_OFF'); $checks++;
hookRemove($temp);
echo json_encode(['status'=>'PASS','checks'=>$checks,'hook_sources'=>'actual_app_files','fixture_database'=>'sqlite_memory_only',
    'native_connections'=>0,'provider_calls'=>0,'old_sender_invoked'=>false], JSON_THROW_ON_ERROR) . "\n";
