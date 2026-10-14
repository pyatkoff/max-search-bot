<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
ini_set('display_errors','0');
require_once dirname(__DIR__).'/services/MaxPrivateAvatarProbe.php';
$input=MaxPrivateAvatarProbe::arguments(array_slice($argv,1));
if ($input===null) {
    echo json_encode(MaxPrivateAvatarProbe::blocked('invalid_arguments'),JSON_THROW_ON_ERROR)."\n";
    exit(2);
}
try {
    // The root is fixed by this installed script; no argument enters a shell command.
    $root=dirname(__DIR__);
    $sha=trim((string)@shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
    if ($sha!==$input['expected_sha']) $result=MaxPrivateAvatarProbe::blocked('installed_sha_mismatch');
    else {
        @require_once $root.'/config.php';
        require_once $root.'/services/ConversationDb.php';
        require_once $root.'/services/ProjectConfig.php';
        $token=defined('MAX_SEARCH_TOKEN')?trim((string)MAX_SEARCH_TOKEN):'';
        $result=(new MaxPrivateAvatarProbe(ConversationDb::connection()))->run($input,$sha,ProjectConfig::projectId(),$token);
    }
} catch (Throwable $error) {$result=MaxPrivateAvatarProbe::blocked('probe_configuration_unavailable');}
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($result['ok']?0:1);
