<?php

declare(strict_types=1);
require dirname(__DIR__).'/services/AiProfileConfigEditor.php';
function profileCheck(bool $ok,string $label): void {if (!$ok) throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
function profileFixture(?string $path=null): array {
    $command=[PHP_BINARY,'-n',__DIR__.'/fixtures/ai_profile_transport.php'];if ($path!==null) $command[]=$path;
    $p=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);
    profileCheck(proc_close($p)===0 && $error==='','synthetic transport child completed without errors');
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
function checkProfiles(array $r): void {
    profileCheck(count($r['calls'])===2,'both real bot caller paths reach the one transport');
    foreach (array_merge($r['calls'],[$r['manager']]) as $body) {
        profileCheck($body['model']==='gpt-6.1-sol' && $body['reasoning']===['effort'=>'low'] && $body['service_tier']==='default','outgoing Sol low Standard profile');
        profileCheck(!isset($body['temperature']) && !isset($body['top_p']),'no incompatible sampling parameters');
    }
    profileCheck($r['timeout']===12 && $r['router_reply']==='synthetic' && $r['extractor_intent']==='general_question','existing deadline and result parsing preserved');
}
checkProfiles(profileFixture());
$untouched="define('UNRELATED_SECRET', 'SECRET_CANARY'); // retain me exactly\n";
$old="<?php\n".$untouched."define('OPENAI_MODEL', 'gpt-5.6-luna');\nconst MANAGER_AI_DRAFT_MODEL = 'old-model';\n"
    ."if (!defined('MANAGER_AI_DRAFT_REASONING')) define(\"MANAGER_AI_DRAFT_REASONING\",\n 'high');\n"
    ."// define('MANAGER_AI_DRAFT_SERVICE_TIER', 'fake');\n?>\n";
$new=AiProfileConfigEditor::rewrite($old);
profileCheck(str_contains($new,$untouched) && str_contains($new,"// define('MANAGER_AI_DRAFT_SERVICE_TIER', 'fake');"),'unrelated secret and commented definitions preserved byte for byte');
profileCheck(AiProfileConfigEditor::rewrite($new)===$new,'rewrite is idempotent');
$file=tempnam(sys_get_temp_dir(),'ai-profile-test-');
try {file_put_contents($file,$new);checkProfiles(profileFixture($file));}finally{unlink($file);}
// Cover both legacy include orders without adding a second OPENAI_MODEL definition.
foreach (['before','after'] as $order) {
    $external=tempnam(sys_get_temp_dir(),'ai-profile-ext-');$runtime=tempnam(sys_get_temp_dir(),'ai-profile-root-');
    try {
        $definition="define('OPENAI_MODEL','old-runtime-model');\n";
        $include='require '.var_export($external,true).";\n";
        $wrapper="<?php\n".($order==='before'?$definition.$include:$include.$definition);
        $keys=AiProfileConfigEditor::definedKeys($wrapper);
        $ext=AiProfileConfigEditor::rewrite("<?php\n".$untouched,array_values(array_diff(array_keys(AiProfileConfigEditor::TARGETS),$keys)));
        profileCheck(!in_array('OPENAI_MODEL',AiProfileConfigEditor::definedKeys($ext),true),'existing wrapper owner not duplicated in external config');
        file_put_contents($external,$ext);file_put_contents($runtime,AiProfileConfigEditor::rewrite($wrapper,[]));
        checkProfiles(profileFixture($runtime));
    } finally {unlink($external);unlink($runtime);}
}
foreach (["<?php define('OPENAI_MODEL', getenv('X'));", "<?php define('OPENAI_MODEL','one');define('OPENAI_MODEL','two');",
    "<?php function hidden(){define('OPENAI_MODEL','hidden');}","<?php const OPENAI_MODEL = 'one', OTHER = 'two';",
    "<?php define('OPENAI_MODEL','one',true);", "<?php ?>not config", "<?php invalid syntax"] as $bad) {
    $rejected=false;try {AiProfileConfigEditor::rewrite($bad);}catch(Throwable $ignored){$rejected=true;}
    profileCheck($rejected,'unsupported or ambiguous config fails closed');
}
$template=dirname(__DIR__).'/config.example.php';
// Template has an API key definition: check its profile in a separate pure child instead of cURL stubs.
$code='require '.var_export($template,true).';require '.var_export(dirname(__DIR__).'/ai/AiClient.php',true).';echo json_encode(AiClient::requestBody("Synthetic",[]));';
$p=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$v=stream_get_contents($pipes[1]);fclose($pipes[1]);$e=stream_get_contents($pipes[2]);fclose($pipes[2]);
profileCheck(proc_close($p)===0 && $e==='' && json_decode($v,true)['model']==='gpt-6.1-sol','new-environment config selects Sol');
$deploy=file_get_contents(dirname(__DIR__).'/.github/workflows/deploy.yml');
profileCheck(str_contains($deploy,'MAX_SEARCH_APPLY_AI_PROFILE=1 php tools/ai_profile_rollout.php --apply; php tools/ai_profile_rollout.php --verify;'),'canonical deploy applies then verifies runtime overrides');
$p=proc_open([PHP_BINARY,dirname(__DIR__).'/tools/ai_profile_rollout.php','--apply'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);
profileCheck(proc_close($p)===2 && $out==='' && $error==="AI_PROFILE_CHECK=FAILED (config contents withheld)\n",'apply refuses a noncanonical checkout without exposing config');
echo "AI PROFILE ROLLOUT REGRESSION PASSED\n";
