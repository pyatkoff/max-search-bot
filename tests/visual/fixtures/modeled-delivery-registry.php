<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,3).'/services/ModeledConversionDeliveryRegistryView.php';

/** Synthetic values only. Uses the real renderer; never opens a database. */
function modeledDeliveryViewFixture(string $scenario='ready'):array
{
    $event=['id'=>'501','stage'=>'specific_request','value_rub'=>833,'state'=>'accepted','error'=>null,
        'upload_status'=>'UPLOADED','event_at'=>'2026-11-03T12:00:00Z','ready_at'=>'2026-11-03T12:00:01Z',
        'upload_claim_at'=>'2026-11-03T12:05:02Z','api_accepted_at'=>null,'processed_at'=>null,
        'receipt_basis'=>null,'identity_resolved'=>true,'api_receipt_present'=>true,
        'origin'=>'live','profile_id'=>'901','profile_saved_at'=>'2026-11-03T12:00:00Z',
        'grounds_status'=>'saved_stage_profile','in_selected_day'=>true];
    $prior=array_replace($event,['id'=>'490','stage'=>'interest','value_rub'=>1250,'state'=>'processed',
        'event_at'=>'2026-11-02T12:00:00Z','profile_saved_at'=>'2026-11-02T12:00:00Z',
        'ready_at'=>'2026-11-02T12:00:01Z','upload_claim_at'=>'2026-11-02T12:05:02Z',
        'api_accepted_at'=>'2026-11-02T12:05:03Z','processed_at'=>'2026-11-02T12:10:03Z',
        'receipt_basis'=>'post_response','in_selected_day'=>false]);
    $history=array_replace($event,['id'=>'502','stage'=>'agency_choice','value_rub'=>12500,'state'=>'pending',
        'error'=>'identity_ambiguous','identity_resolved'=>false,'api_receipt_present'=>false,
        'ready_at'=>null,'upload_claim_at'=>null,'profile_id'=>'902',
        'origin'=>'owner_fortnight_review_v1','profile_saved_at'=>'2026-11-02T10:00:00Z']);
    $page=['available'=>true,'date'=>'2026-11-03','timezone'=>'Europe/Kaliningrad',
        'basis'=>'saved_value_event_unix','as_of'=>'2026-11-03T12:11:00Z','checked_at'=>'2026-11-03T12:11:01Z',
        'summary'=>['events'=>2,'conversations'=>2,'value_rub'=>13333,
            'live'=>['events'=>1,'conversations'=>1,'value_rub'=>833],
            'historical_review'=>['events'=>1,'conversations'=>1,'value_rub'=>12500],
            'delivery'=>['accepted_receipts'=>['events'=>1,'conversations'=>1,'value_rub'=>833],
                'processed'=>['events'=>0,'conversations'=>0,'value_rub'=>0]]],
        'rows'=>[
            ['conversation_id'=>'101','platform'=>'max','customer_name'=>'Анна · синтетический пример',
                'period_value_rub'=>833,'total_value_rub'=>2083,'stage'=>'specific_request','events'=>[$prior,$event]],
            ['conversation_id'=>'102','platform'=>'telegram','customer_name'=>'Михаил · синтетический пример',
                'period_value_rub'=>12500,'total_value_rub'=>12500,'stage'=>'agency_choice','events'=>[$history]],
        ],
        'pagination'=>['shown'=>2,'page_size'=>25,'has_more'=>true,'next_cursor'=>'v1.synthetic-fixture.abcdef']];
    $checks=[];
    foreach(['12:01:00','12:06:00','12:11:00']as$i=>$time)$checks[]=[
        'checked_at'=>'2026-11-03T'.$time.'Z','status'=>'ok','ok'=>true,
        'cycle_id'=>str_pad((string)($i+1),32,'0',STR_PAD_LEFT),'origin'=>'server_cron','source_sha'=>str_repeat('a',40)];
    $pipeline=['active'=>true,'available'=>true,'checked_at'=>'2026-11-03T12:11:00Z','fresh'=>true,
        'status'=>'ok','notification'=>'quiet','ok'=>true,'last_notification_at'=>'2026-11-03T10:17:00Z',
        'origin'=>'server_cron','cycle_id'=>str_pad('3',32,'0',STR_PAD_LEFT),'source_sha'=>str_repeat('a',40),
        'scheduled'=>['last_checked_at'=>'2026-11-03T12:11:00Z','fresh'=>true,
            'success_streak'=>3,'regular'=>true,'checks'=>$checks]];
    $monitor=['available'=>true,'checked_at'=>'2026-11-03T12:10:00Z','fresh'=>true,'status'=>'ok',
        'notification'=>'quiet','last_notification_at'=>'2026-11-03T09:17:00Z','source_event'=>'workflow_run',
        'scheduled'=>['available'=>false,'last_checked_at'=>null,'fresh'=>null,'success_streak'=>0,'regular'=>false,'checks'=>[]],
        'pipeline'=>$pipeline];
    if($scenario==='stale'){
        $monitor=array_replace($monitor,['fresh'=>false,'source_event'=>'workflow_dispatch','checked_at'=>'2026-11-03T09:17:00Z']);
        $monitor['pipeline']['fresh']=false;
        $monitor['pipeline']['checked_at']='2026-11-03T10:17:00Z';
        $monitor['pipeline']['scheduled']=['last_checked_at'=>null,'fresh'=>false,'success_streak'=>0,'regular'=>false,'checks'=>[]];
    }
    if($scenario==='error')$page['available']=false;
    if($scenario==='unsafe'){
        $page['rows'][0]['customer_name']='<img src=x onerror=alert(1)>';
        $page['rows'][0]['events'][1]['error']='<svg onload=alert(2)>';
    }
    return [$page,['date'=>'2026-11-03','platform'=>'all'],$monitor];
}
if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    [$page,$input,$monitor]=modeledDeliveryViewFixture($argv[1]??'ready');
    echo ModeledConversionDeliveryRegistryView::render($page,$input,$monitor);
}
