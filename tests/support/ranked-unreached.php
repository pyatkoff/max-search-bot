<?php
declare(strict_types=1);

namespace AnyTour\Tests\RankedUnreached;

use RuntimeException;
use function AnyTour\ExistingPostBatch\{requireRankedUnreachedJournals, selectRankedUnreachedRows, transferJournal, transferProfileOperation};
use const AnyTour\ExistingPostBatch\{RANKED_ORIGINAL_RUN, RANKED_UNREACHED_OPERATION};

function evidence(array $rows, string $digest): array
{
    $check = ['ok'=>true, 'mode'=>'offline_plan_check', 'operation'=>RANKED_UNREACHED_OPERATION, 'parent_run_id'=>'40',
        'plan_sha256'=>$digest, 'production_writes'=>0, 'provider_writes'=>0, 'rows'=>[]];
    $receipt = ['schema'=>1, 'ok'=>false, 'operation'=>RANKED_UNREACHED_OPERATION, 'parent_run_id'=>'40',
        'native_run_id'=>'41', 'application_sha'=>str_repeat('a', 40), 'plan_sha256'=>$digest,
        'bot_starts'=>0, 'supplier_requests'=>0, 'provider_write_attempts'=>3, 'results'=>[]];
    $summary = ['operation'=>RANKED_UNREACHED_OPERATION, 'state'=>'partial_requires_readback',
        'hotel_ids'=>array_column($rows, 'hotel_id'), 'message_ids'=>array_column($rows, 'target_message_id'),
        'confirmed'=>[$rows[1]['hotel_id']], 'held'=>[], 'unreached'=>array_column(array_slice($rows, 3), 'hotel_id')];
    foreach ($rows as $i => $row) {
        $id = $row['hotel_id'];
        $message = $row['target_message_id'];
        $url = 'https://t.me/anytour/'.$message;
        $check['rows'][] = ['hotel_id'=>$id, 'target_url'=>$url, 'caption_sha256'=>$row['next_caption_sha256']];
        if ($i > 2) continue;
        $result = ['hotel_id'=>$id, 'message_id'=>$message, 'provider_write_attempts'=>1];
        if ($i === 1) {
            $result += ['state'=>'native_and_registry_verified', 'hotel_id_verified'=>true,
                'original_message_id_and_date_preserved'=>true, 'photo_caption_button_verified'=>true];
        } else {
            $result += ['state'=>'response_saved_completion_requires_readback', 'reason'=>'hotel_transfer_response_unconfirmed',
                'reserved_publication_id'=>100+$i];
            if ($i === 0) $result['shared_access_rechecked'] = true;
            $summary['held'][] = $result;
        }
        $receipt['results'][] = $result + ['ok'=>$i === 1, 'url'=>$url];
    }
    return ['parent_run_id'=>'41', 'parent_application_sha'=>str_repeat('a',40), 'parent_artifact_sha256'=>str_repeat('b',64),
        'check'=>$check, 'receipt'=>$receipt, 'summary'=>$summary];
}

function run(array $scope, string $digest): void
{
    $rows = array_slice($scope['rows'], -10);
    $parent = evidence($rows, $digest);
    $tail = selectRankedUnreachedRows($scope, $digest, $parent);
    \channelPostCheck('unreached selects only actual unprocessed suffix',
        [$tail['rows'], $tail['operation']], [array_slice($rows,3), RANKED_UNREACHED_OPERATION]);
    $next = evidence($tail['rows'], $digest);
    $next['receipt']['parent_run_id'] = $next['check']['parent_run_id'] = $parent['parent_run_id'];
    $next['parent_run_id'] = $next['receipt']['native_run_id'] = '42';
    \channelPostCheck('a second partial continuation needs no new list',
        selectRankedUnreachedRows($scope, $digest, $next)['rows'], array_slice($rows,6));

    $cases = [
        'foreign origin'=>static function (&$p) { $p['parent_run_id']='123'; },
        'wrong artifact hash shape'=>static function (&$p) { $p['parent_artifact_sha256']='x'; },
        'foreign application'=>static function (&$p) { $p['parent_application_sha']=str_repeat('c',40); },
        'changed plan'=>static function (&$p) { $p['check']['plan_sha256']=str_repeat('c',64); },
        'incorrect previous parent'=>static function (&$p) { $p['receipt']['parent_run_id']='39'; },
        'reorders source ids'=>static function (&$p) { $p['summary']['hotel_ids']=array_reverse($p['summary']['hotel_ids']); },
        'forged checked message'=>static function (&$p) { $p['check']['rows'][2]['target_url']='https://t.me/anytour/99'; },
        'foreign photo caption'=>static function (&$p) { $p['check']['rows'][3]['caption_sha256']=str_repeat('f',64); },
        'adds an attempted row'=>static function (&$p) { array_unshift($p['summary']['unreached'],$p['summary']['hotel_ids'][1]); },
        'unconfirmed success'=>static function (&$p) { $p['receipt']['results'][1]['hotel_id_verified']=false; },
        'false aggregate count'=>static function (&$p) { $p['receipt']['provider_write_attempts']=2; },
        'global refusal'=>static function (&$p) { $p['receipt']['results'][0]['shared_access_error']='transfer_content_editor_busy'; },
        'first loss lacks read health'=>static function (&$p) { unset($p['receipt']['results'][0]['shared_access_rechecked'],$p['summary']['held'][0]['shared_access_rechecked']); },
        'not the second loss stop'=>static function (&$p) { array_pop($p['receipt']['results']); },
        'typed reservation mirror mismatch'=>static function (&$p) { $p['summary']['held'][1]['reserved_publication_id']='102'; },
        'incomplete native result'=>static function (&$p) { $p['summary']['state']='invoked_requires_readback'; },
        'no progress'=>static function (&$p) { $p['receipt']['results']=[]; $p['summary']['confirmed']=[]; },
    ];
    foreach ($cases as $label => $corrupt) {
        $bad = $parent;
        $corrupt($bad);
        try { selectRankedUnreachedRows($scope, $digest, $bad); $rejected=false; }
        catch (RuntimeException) { $rejected=true; }
        \channelPostCheck('refuse malformed unreached admission: '.$label, $rejected, true);
    }
    $empty = evidence(array_slice($rows,-3), $digest);
    try { selectRankedUnreachedRows($scope, $digest, $empty); $reason=null; }
    catch (RuntimeException $e) { $reason=$e->getMessage(); }
    \channelPostCheck('empty native suffix has no admission', $reason, 'transfer_unreached_empty');

    $dir=sys_get_temp_dir().'/ranked-unreached-'.bin2hex(random_bytes(8));
    mkdir($dir,0700);
    $journals=[];
    try {
        foreach ([0,2] as $i) {
            $row=$rows[$i]; $result=$parent['receipt']['results'][$i]; unset($result['shared_access_rechecked']);
            $path=$dir.'/anytour-issue1566-historical-tg-media-'.$row['target_message_id'].'-v1';
            mkdir($path,0700);
            $request=['chat_id'=>'-1001845835645','message_id'=>(int)$row['target_message_id']];
            $events=[
                ['state'=>'intent_saved','operation'=>transferProfileOperation($digest),'row'=>$row,'plan_sha256'=>$digest,
                    'execution'=>['native_run_id'=>'41','application_sha'=>$parent['parent_application_sha'],'continuation_of_ranked_run_id'=>RANKED_ORIGINAL_RUN]],
                ['state'=>'identity_reserved','plan_sha256'=>$digest,'reservation'=>['publication_id'=>$result['reserved_publication_id']]],
                ['state'=>'request_prepared','request'=>$request,'request_sha256'=>hash('sha256',json_encode($request,JSON_THROW_ON_ERROR))],
                ['state'=>'provider_edit_started','provider_write_attempts'=>1],
                ['state'=>'provider_response_saved','response'=>['http'=>0,'data'=>null,'transport_error'=>true]],
                $result,
            ];
            $journals[$i]=$path.'/receipt.jsonl';
            $file=fopen($journals[$i],'xb'); foreach ($events as $event) transferJournal($file,$event); fclose($file);
        }
        $before=array_map(static fn($p)=>hash_file('sha256',$p),$journals);
        requireRankedUnreachedJournals($scope,$parent,$digest,$dir);
        \channelPostCheck('verifying uncertain journals preserves every byte', array_map(static fn($p)=>hash_file('sha256',$p),$journals), $before);
        $started=$dir.'/anytour-issue1566-historical-tg-media-'.$rows[4]['target_message_id'].'-v1';
        mkdir($started,0700);
        try { requireRankedUnreachedJournals($scope,$parent,$digest,$dir); $reason=null; }
        catch (RuntimeException $e) { $reason=$e->getMessage(); }
        \channelPostCheck('replayed predecessor finds already started target before any write',$reason,'transfer_unreached_target_already_started');
        rmdir($started);
        file_put_contents($journals[2],str_replace('"http":0','"http":403',file_get_contents($journals[2])));
        try { requireRankedUnreachedJournals($scope,$parent,$digest,$dir); $reason=null; }
        catch (RuntimeException $e) { $reason=$e->getMessage(); }
        \channelPostCheck('no continuation on provider refusal or absent second transport proof',$reason,'transfer_previous_operation_do_not_replay');
    } finally {
        foreach ($journals as $p) { if(is_file($p))unlink($p); if(is_dir(dirname($p)))rmdir(dirname($p)); }
        if(is_dir($dir))rmdir($dir);
    }
}
