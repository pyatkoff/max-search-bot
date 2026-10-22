<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('OWNER_LIVE_QUOTE_OFFLINE_TEST')) define('OWNER_LIVE_QUOTE_OFFLINE_TEST',true);
require_once __DIR__.'/owner_tour_live_quote_trial.php';

/** A visual revision of the historical example; never a fresh or verified price. */
function ownerCompactChoiceSpec(): array
{
    $spec=ownerLiveQuoteSpec();
    $spec['id']='owner-card-compact-20261022-grand-park';
    $spec['snapshot']['is_demo']=true;
    $spec['snapshot']['rationale']='';
    $spec['snapshot']['compromise']='';
    $spec['presentation']['layout']='compact-v1';
    return $spec;
}

function ownerCompactChoiceArguments(array $args): ?string
{
    if (count(array_keys($args,'--revision=compact-v1',true)) !== 1) return null;
    return ownerPhotoChoiceArguments(array_values(array_filter($args,static fn($arg)=>$arg !== '--revision=compact-v1')));
}

/** Fixed built assets preserve the genuine photographs with only 4:3 framing. */
function ownerCompactChoiceDownload(int $index,string $directory): ?array
{
    if (!in_array($index,[0,1,2],true)) return null;
    $root=dirname(__DIR__).'/assets/owner-tour-cards/compact-v1';
    $file=$root.'/'.['cover.jpg','beach.jpg','room.jpg'][$index];
    $manifest=json_decode((string)@file_get_contents($root.'/sources.json'),true);
    $entry=$manifest['images'][['cover','beach','room'][$index]] ?? null;
    $source=ownerCompactChoiceSpec()['presentation']['photos'][$index]['url'];
    if (!is_array($entry) || ($entry['source_url'] ?? null) !== $source || ($entry['file'] ?? null) !== basename($file)
        || !is_string($entry['output_sha256'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D',$entry['output_sha256'])
        || is_link($file) || !is_file($file) || filesize($file)>5242880 || !hash_equals($entry['output_sha256'],hash_file('sha256',$file))) return null;
    $size=@getimagesize($file);
    if (!is_array($size) || ($size['mime'] ?? null) !== 'image/jpeg' || $size[0]<1 || $size[1]<1
        || $size[0]>7680 || $size[1]>7680 || $size[0]*3 !== $size[1]*4) return null;
    $path=$directory.'/.owner-preview-image-'.$index.'-'.bin2hex(random_bytes(8)).'.tmp';
    $old=umask(0077);$handle=@fopen($path,'x+b');umask($old);
    if ($handle === false) return null;
    $bytes=(string)file_get_contents($file);
    $ok=fwrite($handle,$bytes)===strlen($bytes) && fflush($handle);fclose($handle);
    if (!$ok || (fileperms($path)&0777)!==0600 || !hash_equals($entry['output_sha256'],hash_file('sha256',$path))) { @unlink($path);return null; }
    return ['path'=>$path,'mime'=>'image/jpeg'];
}

if (!defined('OWNER_COMPACT_CHOICE_OFFLINE_TEST')) {
    ini_set('display_errors','0');
    $result=['ok'=>false,'state'=>'invalid_arguments','send_attempted'=>false,'provider_requests'=>0];
    $expected=ownerCompactChoiceArguments(array_slice($argv,1));
    if ($expected !== null) {
        $level=ob_get_level();ob_start();
        try {
            $root=dirname(__DIR__);
            $installed=trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
            if ($installed !== $expected) $result['state']='installed_sha_mismatch';
            else {
                require_once $root.'/config.php';require_once $root.'/services/ConversationDb.php';require_once $root.'/services/ProjectConfig.php';
                $db=ConversationDb::connection();
                $result=ownerPhotoChoiceRun($expected,$installed,ProjectConfig::projectId(),
                    ['telegram'=>defined('TELEGRAM_BOT_TOKEN')?(string)TELEGRAM_BOT_TOKEN:'','max'=>defined('MAX_SEARCH_TOKEN')?(string)MAX_SEARCH_TOKEN:''],
                    ['telegram'=>static fn()=>telegramOwnerPreviewTarget($db),'max'=>static fn()=>ownerPreviewTarget($db)],
                    ['telegram'=>'telegramOwnerPreviewRequest','max'=>'ownerPreviewRequest'],
                    '/var/www/anytoour/data/owner-tour-compact-choice-trial',
                    static fn(int $conversationId,array $snapshot)=>TourChoiceService::prepare($db,$conversationId,$snapshot),
                    static fn(int $offerId,string $mid,string $chat)=>TourChoiceService::recordDelivered($db,$offerId,$mid,$chat),
                    ['download'=>'ownerCompactChoiceDownload'],ownerCompactChoiceSpec());
                $result['revision']='compact-v1';
            }
        } catch (Throwable $ignored) { $result['state']='configuration_unavailable'; }
        finally { while (ob_get_level()>$level) ob_end_clean(); }
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit($result['ok']?0:1);
}
