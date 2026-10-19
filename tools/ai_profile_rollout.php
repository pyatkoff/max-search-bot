<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__).'/services/AiProfileConfigEditor.php';
require_once dirname(__DIR__).'/ai/AiClient.php';
require_once dirname(__DIR__).'/ai/ManagerDraftClient.php';

/** Only a synthetic request projection; never provider credentials, text or a paid request. */
function aiProfileVerify(string $config): array
{
    $level=ob_get_level();ob_start();
    try {require $config;} finally {while (ob_get_level()>$level) ob_end_clean();}
    $requests=['bot'=>AiClient::requestBody('Profile smoke',[]),'manager'=>ManagerDraftClient::requestBody('Profile smoke',[])];
    $out=[];
    foreach ($requests as $name=>$body) {
        $out[$name]=['model'=>$body['model']??null,'reasoning'=>$body['reasoning']['effort']??null,'service_tier'=>$body['service_tier']??null];
        if ($out[$name]!==['model'=>'gpt-6.1-sol','reasoning'=>'low','service_tier'=>'default']) throw new RuntimeException('profile_mismatch');
    }
    return $out;
}

$root=realpath(dirname(__DIR__));$external='/var/www/anytoour/data/config/max-search.php';
$mode=$argv[1]??'--verify';$lock=null;$plans=[];$installed=[];$status=0;
try {
    if (count($argv)!==2 || !in_array($mode,['--apply','--verify'],true)) throw new RuntimeException('invalid_mode');
    if ($mode==='--apply') {
        if (getenv('MAX_SEARCH_APPLY_AI_PROFILE')!=='1' || $root!=='/var/www/anytoour/data/www/app.anytoour.ru') throw new RuntimeException('write_not_authorized');
        $mask=umask(0077);
        try {
            if (is_link($external.'.ai-profile.lock')) throw new RuntimeException('config_busy');
            $lock=fopen($external.'.ai-profile.lock','c');
            if ($lock===false || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('config_busy');
            foreach (['runtime'=>$root.'/config.php','external'=>$external] as $name=>$path) {
                if (is_link($path) || !is_file($path) || !is_readable($path) || !is_writable($path)) throw new RuntimeException('config_unavailable');
                $source=file_get_contents($path);$stat=stat($path);
                if (!is_string($source) || $source==='' || $stat===false || $stat['dev']!==stat(dirname($external))['dev']) throw new RuntimeException('config_unavailable');
                $plans[$name]=['path'=>$path,'source'=>$source,'mode'=>$stat['mode']&0777,'candidate'=>null,'tmp'=>null];
            }
            // Existing literal owners stay in place; only truly missing settings go
            // into external config. Avoid introducing duplicate define warnings in
            // legacy wrappers which still define OPENAI_MODEL before/after require.
            $runtimeKeys=AiProfileConfigEditor::definedKeys($plans['runtime']['source']);
            $plans['runtime']['candidate']=AiProfileConfigEditor::rewrite($plans['runtime']['source'],[]);
            $plans['external']['candidate']=AiProfileConfigEditor::rewrite($plans['external']['source'],array_values(array_diff(array_keys(AiProfileConfigEditor::TARGETS),$runtimeKeys)));
            foreach ($plans as $name=>$plan) {
                if ($plan['candidate']===$plan['source']) continue;
                $plans[$name]['tmp']=tempnam(dirname($external),'.ai-profile-');
                $plan['tmp']=$plans[$name]['tmp'];
                if ($plan['tmp']===false || !chmod($plan['tmp'],0600) || file_put_contents($plan['tmp'],$plan['candidate'])!==strlen($plan['candidate'])) throw new RuntimeException('candidate_unavailable');
                $lint=[];$code=1;
                exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($plan['tmp']).' 2>&1',$lint,$code);
                if ($code!==0) throw new RuntimeException('candidate_invalid');
                $backup=dirname($external).'/ai-profile-before-sol-61.'.$name.'.php';
                $handle=fopen($backup,'x');
                if ($handle===false) throw new RuntimeException('backup_exists');
                try {if (!chmod($backup,0600) || fwrite($handle,$plan['source'])!==strlen($plan['source']) || !fflush($handle)) throw new RuntimeException('backup_failed');}
                finally {fclose($handle);}
            }
            foreach ($plans as $plan) if (is_link($plan['path']) || file_get_contents($plan['path'])!==$plan['source']) throw new RuntimeException('config_changed');
            foreach ($plans as $name=>$plan) {
                if ($plan['tmp']===null) continue;
                if (!chmod($plan['tmp'],$plan['mode']) || !rename($plan['tmp'],$plan['path'])) throw new RuntimeException('install_failed');
                $plans[$name]['tmp']=null;$installed[]=$name;
                clearstatcache(true,$plan['path']);
                if (function_exists('opcache_invalidate')) opcache_invalidate($plan['path'],true);
            }
        } finally {umask($mask);}
    }
    $profile=aiProfileVerify($root.'/config.php');
    echo 'AI_PROFILE='.json_encode($profile,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    echo 'AI_PROFILE_PROVIDER_CALLS=0'.PHP_EOL;
} catch (Throwable $ignored) {
    // Never print exceptions, config, tokens or lint output. Restore only our own installs.
    foreach (array_reverse($installed) as $name) {
        $plan=$plans[$name];
        if (is_link($plan['path']) || file_get_contents($plan['path'])!==$plan['candidate']) {
            fwrite(STDERR,"AI_PROFILE_ROLLBACK=DRIFT (private backup retained)\n");continue;
        }
        $restore=tempnam(dirname($external),'.ai-restore-');
        if ($restore===false || !chmod($restore,0600) || file_put_contents($restore,$plan['source'])!==strlen($plan['source'])
            || !chmod($restore,$plan['mode']) || !rename($restore,$plan['path'])) {
            if (is_string($restore)) @unlink($restore);
            fwrite(STDERR,"AI_PROFILE_ROLLBACK=FAILED (private backup retained)\n");
        }
        if (function_exists('opcache_invalidate')) opcache_invalidate($plan['path'],true);
    }
    fwrite(STDERR,"AI_PROFILE_CHECK=FAILED (config contents withheld)\n");$status=2;
} finally {
    foreach ($plans as $plan) if (is_string($plan['tmp']) && is_file($plan['tmp'])) @unlink($plan['tmp']);
    if (is_resource($lock)) {flock($lock,LOCK_UN);fclose($lock);}
}
exit($status);
