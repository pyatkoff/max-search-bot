<?php
declare(strict_types=1);

/** Exercises the same saved-summary projections as the private status reader without touching its cache. */
function modeledVisitSummaryGrowthAssertions(callable $check):void
{
    $now=strtotime('2026-11-24T12:00:00Z');
    $cut=$now-120;
    $cap=ModeledConversionValueContract::MAX_VALUE_RUB;
    $archive=static fn(array $row):array=>[];
    $dailyReader=new ReflectionMethod(ModeledConversionVisitProof::class,'dailySummary');
    $followupReader=new ReflectionMethod(ModeledConversionVisitProof::class,'followupSummary');
    $rows=[];
    for($id=1;$id<=1001;$id++){
        $rows[]=['id'=>(string)$id,'conversation_id'=>(string)(1+$id%2),'platform'=>'max',
            'project_key'=>'anytour','counter_id'=>'98615635','transport_target'=>ModeledConversionValueContract::TARGET,
            'value_contract'=>ModeledConversionValueContract::VERSION,'value_rub'=>$cap,'event_unix'=>$cut-60,
            'claimed_at_unix'=>$cut-30,'upload_id'=>(string)$id,'value_api_accepted_at_unix'=>$cut-25,
            'upload_status'=>'PROCESSED','state'=>'processed','value_processed_at_unix'=>$cut+10];
        if($id<1000)continue;
        $expected=['events'=>$id,'conversations'=>2,'value_rub'=>$id*$cap];
        $daily=ModeledConversionVisitProof::dailySentCohort($rows,[],$now,$archive);
        $report=$dailyReader->invoke(null,$daily,$now);
        $check("saved Moscow day accepts all $id claims and their total at the per-event monetary cap",
            ($report['by_platform']['max']['processed']??null)===$expected
            &&$report['by_platform']['max']['visit_linked']['events']===0);
        $followup=ModeledConversionVisitProof::followupReport($rows,[],$cut,$now,$archive);
        $report=$followupReader->invoke(null,$followup,$now);
        $check("saved historical baseline and outcome both retain all $id claims",
            ($report['baseline_by_platform']['max']['sent']??null)===$expected
            &&$report['by_platform']['max']['unverified_visits']===$expected);
    }
    $overCap=$daily;
    $overCap['by_platform']['max']['sent']['value_rub']++;
    $badBaseline=$followup;
    $badBaseline['baseline_by_platform']['max']['sent']['value_rub']--;
    $check('growth preserves both per-event value bounds and the historical partition identity',
        !$dailyReader->invoke(null,$overCap,$now)['available']
        &&!$followupReader->invoke(null,$badBaseline,$now)['available']);

    // Each individual group is representable, but adding these two parts would overflow a PHP integer.
    $overflow=ModeledConversionVisitProof::dailySentCohort([],[],$now,$archive);
    $huge=['events'=>PHP_INT_MAX,'conversations'=>1,'value_rub'=>PHP_INT_MAX];
    foreach(['sent','api_accepted','processed','unverified_visits']as$group)$overflow['by_platform']['max'][$group]=$huge;
    $overflow['by_platform']['max']['provider_processing']=['events'=>1,'conversations'=>1,'value_rub'=>1];
    $check('overflowing group partitions cannot validate as complete',
        !$dailyReader->invoke(null,$overflow,$now)['available']);

    $automatic=new ReflectionMethod(ModeledConversionVisitProof::class,'automaticSummary');
    $legacy=['origin'=>'server_cron','status'=>'failed','attempt_unix'=>$now,'success_unix'=>$now-901,'checks'=>[$now-901]];
    $old=$automatic->invoke(null,$legacy,$now);
    $work=['phase'=>'verification','ledger_rows'=>4100,'elapsed_ms'=>6095,'budget_ms'=>6000];
    $observed=$automatic->invoke(null,$legacy+['work'=>$work],$now);
    $check('a failed refresh exposes only measured attempt work and preserves the genuine previous success',
        $old['available']&&!array_key_exists('work',$old)&&$observed['work']===$work
        &&!$observed['fresh']&&$observed['success_unix']===$legacy['success_unix']);
    $unsafe=$automatic->invoke(null,$legacy+['unexpected'=>'private','work'=>$work+['exception'=>'private']],$now);
    $incomplete=$automatic->invoke(null,$legacy+['work'=>array_replace($work,['phase'=>'snapshot'])],$now);
    $check('malformed diagnostics and partial snapshot counts cannot escape the private projection',
        $unsafe===$old&&$incomplete===$old);
}
