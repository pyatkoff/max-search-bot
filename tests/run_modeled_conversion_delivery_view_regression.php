<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/visual/fixtures/modeled-delivery-registry.php';
require_once dirname(__DIR__).'/services/ModeledConversionForecastPaymentAggregateView.php';
$checks=0;$failures=[];
$check=static function(bool $ok,string $name)use(&$checks,&$failures):void{$checks++;if(!$ok)$failures[]=$name;};
[$page,$input,$monitor]=modeledDeliveryViewFixture();
$html=ModeledConversionDeliveryRegistryView::render($page,$input,$monitor);
$part=static function(string $html,string $id):string{
    if(preg_match('/<section\b[^>]*\bid="'.preg_quote($id,'/').'"[^>]*>.*?<\/section>/s',$html,$match)!==1)
        throw new RuntimeException('missing_monitor_section');
    return $match[0];
};
$primaryHtml=$part($html,'pipelineMonitor');$externalHtml=$part($html,'externalMonitor');
$check(str_contains($html,'Конверсии')&&str_contains($html,'Закрытый просмотр'),'private owner title');
$check(str_contains($html,'13 333 ₽')&&str_contains($html,'Начислений: 2 · диалогов: 2'),'whole selected-day totals');
$check(str_contains($html,'833 ₽')&&str_contains($html,'2 083 ₽'),'period increase and lifetime total remain different');
$check(str_contains($html,'Новый поток:')&&str_contains($html,'Исторический разбор:'),'live and historical origins are distinct');
$check(str_contains($html,'по дате')||str_contains($html,'По дате начисления'),'assignment-day basis is explicit');
$check(str_contains($html,'По дате начисления')&&str_contains($html,'суммы не складываются'),'overlapping acceptance cohorts are explained');
$check(str_contains($html,'method="get"')&&str_contains($html,'name="platform"')&&str_contains($html,'name="date"'),'filters perform GET only');
$check(str_contains($html,'rel="next"')&&str_contains($html,'cursor=v1.synthetic-fixture.abcdef'),'bounded next-page cursor link');
$check(str_contains($html,'профилем №901')&&str_contains($html,'Конкретный запрос'),'award tied to recorded stage profile');
$check(str_contains($html,'03.11.2026 15:00:00')&&str_contains($html,'МСК'),'UTC times rendered and labelled in Moscow timezone');
$oldCalendar=$page;$oldCalendar['timezone']='Europe/Kaliningrad';
$oldHtml=ModeledConversionDeliveryRegistryView::render($oldCalendar,$input,$monitor);
$check(str_contains($oldHtml,'Реестр сейчас недоступен')&&!str_contains($oldHtml,'Анна'),'old calendar cohort cannot be labelled Moscow');
foreach(['2026-12-31T20:59:59Z'=>'31.12.2026 23:59:59','2026-12-31T21:00:00Z'=>'01.01.2027 00:00:00',
    '2026-12-31T21:00:01Z'=>'01.01.2027 00:00:01']as$utc=>$local){
    $boundary=$page;$boundary['rows'][0]['events'][1]['event_at']=$utc;
    $before=$boundary;
    $check(str_contains(ModeledConversionDeliveryRegistryView::render($boundary,$input,$monitor),$local)
        &&$boundary===$before,'Moscow midnight display preserves the original event instant: '.$utc);
}
$check(str_contains($html,'точное время подтверждения')&&str_contains($html,'Не записано'),'legacy receipt clock is not fabricated');
$check(str_contains($html,'найдено несколько')||str_contains($html,'Найдено несколько'),'held identity has a human reason');
$check(str_contains($primaryHtml,'Контроль обработчиков')&&str_contains($primaryHtml,'Регулярность подтверждена')
    &&str_contains($primaryHtml,'По серверному расписанию'),'three confirmed local scheduled checks displayed');
$check(str_contains($externalHtml,'Внешняя проверка сервера')&&str_contains($externalHtml,'После обновления приложения')
    &&!str_contains($externalHtml,'Регулярность подтверждена'),'post-deploy external check remains separately labelled');
$check(str_contains($primaryHtml,'Последнее уведомление об обработчиках')&&str_contains($primaryHtml,'03.11.2026 13:17:00')
    &&str_contains($externalHtml,'Последнее уведомление о сервере')&&str_contains($externalHtml,'03.11.2026 12:17:00'),'quiet local and external notifications retain distinct timestamps');
$check(str_contains($externalHtml,'GitHub может задерживать или пропускать запуски')
    &&str_contains($externalHtml,'Гарантии внешней проверки каждые пять минут нет'),'external best-effort coverage is explicit');
$check(!str_contains($html,'<script')&&!str_contains($html,'ClientID')&&!str_contains($html,'YCLID'),'no scripts or advertising identifiers');
$check(str_contains($html,'не подтверждает привязку каждой конверсии к визиту'),'processed does not assert per-row visit match');
[$stale,$si,$sm]=modeledDeliveryViewFixture('stale');
$staleHtml=ModeledConversionDeliveryRegistryView::render($stale,$si,$sm);
$check(str_contains($staleHtml,'Проверка устарела')&&!str_contains($staleHtml,'Проверка успешна'),'old OK cannot appear healthy');
$check(str_contains($staleHtml,'0 из 3 проверок')&&!str_contains($staleHtml,'Регулярность подтверждена'),'manual test is not scheduled regularity');
[$bad,$bi,$bm]=modeledDeliveryViewFixture('unsafe');
$unsafe=ModeledConversionDeliveryRegistryView::render($bad,$bi,$bm);
$check(str_contains($unsafe,'&lt;img')&&!str_contains($unsafe,'<img')&&!str_contains($unsafe,'<svg'),'customer name and errors cannot inject HTML');
[$failed,$fi,$fm]=modeledDeliveryViewFixture('error');
$failedHtml=ModeledConversionDeliveryRegistryView::render($failed,$fi,$fm);
$check(str_contains($failedHtml,'Реестр сейчас недоступен')&&!str_contains($failedHtml,'Анна')&&!str_contains($failedHtml,'13 333'),'failed read never renders stale private rows');
$unknown=ModeledConversionDeliveryRegistryView::render($page,$input,[]);
$check(str_contains($unknown,'Нет подтверждённой проверки')&&!str_contains($unknown,'Проверка успешна'),'missing monitor is unknown');
$route=(string)file_get_contents(dirname(__DIR__).'/manager/assigned-value.php');
$pos=static fn(string $s):int=>($p=strpos($route,$s))===false?PHP_INT_MAX:$p;
$check($pos('ManagerHttp::requireAdmin($manager)')<$pos('new ModeledConversionDeliveryRegistry(')
    &&$pos("ProjectAccessService::canAccess(".'$actor'.",'anytour')")<$pos('new ModeledConversionDeliveryRegistry('),'owner and project authorization precede ledger read');
$after=substr($route,$pos("session_start(['read_and_close'=>true])"));
$check(str_contains($after,'ManagerHttp::managerId()!==$actor')&&str_contains($after,'ManagerHttp::requireAdmin($fresh)')
    &&str_contains($after,"ProjectAccessService::canAccess(".'$actor'.",'anytour')"),'current session actor role project rechecked after ledger read');
$check(str_contains($route,"session_id().'|modeled-delivery-registry-v1|'.".'$actor'),'cursor key bound to current owner session');
$check(!str_contains($route,"action='detail'")&&!str_contains($route,'->tick(')&&!str_contains($route,'->resolve('),'ledger viewer cannot mark read or process');
$admin=(string)file_get_contents(dirname(__DIR__).'/manager/admin.php');
$js=(string)file_get_contents(dirname(__DIR__).'/manager/assets/admin.js');
$check(str_contains($admin,'id="adminConversions"')&&str_contains($admin,'view=delivery" hidden'),'admin entry is initially hidden');
$check(str_contains($js,"$('adminConversions').hidden=true")&&str_contains($js,"$('adminConversions').hidden=false"),'entry follows live accepted admin identity and invalidation');

$beforeCutover=$monitor;unset($beforeCutover['pipeline']);
$beforeCutover['scheduled']=['fresh'=>true,'success_streak'=>3,'regular'=>true,'last_checked_at'=>'2026-11-03T12:11:00Z'];
$legacyHtml=ModeledConversionDeliveryRegistryView::render($page,$input,$beforeCutover);
$legacyPrimary=$part($legacyHtml,'pipelineMonitor');
$check(str_contains($legacyPrimary,'Нет подтверждённой проверки')&&str_contains($legacyPrimary,'0 из 3 проверок')
    &&!str_contains($legacyPrimary,'Проверка успешна')&&!str_contains($legacyPrimary,'Регулярность подтверждена')
    &&str_contains($part($legacyHtml,'externalMonitor'),'Проверка успешна'),'external legacy success cannot supply missing local proof');
$inactive=$monitor;$inactive['pipeline']['active']=false;
$inactivePrimary=$part(ModeledConversionDeliveryRegistryView::render($page,$input,$inactive),'pipelineMonitor');
$check(!str_contains($inactivePrimary,'Проверка успешна')&&!str_contains($inactivePrimary,'Регулярность подтверждена')
    &&str_contains($inactivePrimary,'не подключены'),'inactive local checker cannot appear healthy');
$wrongOrigin=$monitor;$wrongOrigin['pipeline']['origin']='workflow_run';
$wrongOriginPrimary=$part(ModeledConversionDeliveryRegistryView::render($page,$input,$wrongOrigin),'pipelineMonitor');
$check(!str_contains($wrongOriginPrimary,'Проверка успешна')&&str_contains($wrongOriginPrimary,'0 из 3 проверок')
    &&!str_contains($wrongOriginPrimary,'Регулярность подтверждена'),'post-deploy origin cannot qualify as local scheduled proof');
$uncertain=$monitor;$uncertain['pipeline']['notification']='unknown';
$uncertainHtml=ModeledConversionDeliveryRegistryView::render($page,$input,$uncertain);
$uncertainPrimary=$part($uncertainHtml,'pipelineMonitor');
$check(!str_contains($uncertainPrimary,'Проверка успешна')&&str_contains($uncertainPrimary,'Отправка уведомления не подтверждена')
    &&str_contains($part($uncertainHtml,'externalMonitor'),'Проверка успешна'),'uncertain local delivery stays separate from healthy external check');
$externalUncertain=$monitor;$externalUncertain['notification']='unknown';
$externalUncertainHtml=ModeledConversionDeliveryRegistryView::render($page,$input,$externalUncertain);
$check(str_contains($part($externalUncertainHtml,'pipelineMonitor'),'Проверка успешна')
    &&!str_contains($part($externalUncertainHtml,'externalMonitor'),'Проверка успешна')
    &&str_contains($part($externalUncertainHtml,'externalMonitor'),'Отправка уведомления о сервере не подтверждена'),'external uncertainty cannot overwrite healthy local status');
$staleLocal=$monitor;$staleLocal['pipeline']['fresh']=false;
$staleLocalPrimary=$part(ModeledConversionDeliveryRegistryView::render($page,$input,$staleLocal),'pipelineMonitor');
$check(str_contains($staleLocalPrimary,'Проверка устарела')&&!str_contains($staleLocalPrimary,'Проверка успешна')
    &&!str_contains($staleLocalPrimary,'Регулярность подтверждена')&&str_contains($staleLocalPrimary,'0 из 3 проверок'),'fresh external check cannot refresh stale local proof');
$staleExternal=$monitor;$staleExternal['fresh']=false;
$staleExternalHtml=ModeledConversionDeliveryRegistryView::render($page,$input,$staleExternal);
$check(str_contains($part($staleExternalHtml,'pipelineMonitor'),'Регулярность подтверждена')
    &&str_contains($part($staleExternalHtml,'externalMonitor'),'Проверка устарела'),'fresh local proof cannot refresh stale external status');
$incomplete=$monitor;$incomplete['pipeline']['scheduled']['regular']=false;$incomplete['pipeline']['scheduled']['success_streak']=2;
$incompletePrimary=$part(ModeledConversionDeliveryRegistryView::render($page,$input,$incomplete),'pipelineMonitor');
$check(str_contains($incompletePrimary,'2 из 3 проверок')&&!str_contains($incompletePrimary,'Регулярность подтверждена'),'two local checks do not become three');
$unavailableLocal=$monitor;$unavailableLocal['pipeline']['available']=false;
$unavailablePrimary=$part(ModeledConversionDeliveryRegistryView::render($page,$input,$unavailableLocal),'pipelineMonitor');
$check(!str_contains($unavailablePrimary,'Проверка успешна')&&!str_contains($unavailablePrimary,'Регулярность подтверждена'),'unavailable local checker never appears healthy');
$failedRuntime=$monitor;$failedRuntime['pipeline']['ok']=false;
$failedRuntimePrimary=$part(ModeledConversionDeliveryRegistryView::render($page,$input,$failedRuntime),'pipelineMonitor');
$check(!str_contains($failedRuntimePrimary,'Проверка успешна')&&!str_contains($failedRuntimePrimary,'Регулярность подтверждена'),'failed local execution cannot become a green saved success');
$check(!str_contains($html,$monitor['pipeline']['cycle_id'])&&!str_contains($html,$monitor['pipeline']['source_sha']),'technical cycle identifiers are not shown');
foreach(['admin'=>200,'admin_previous_day'=>200,'manager'=>403,'inactive'=>401,'no_project'=>403,'foreign'=>404,
    'post'=>405,'parameters'=>422,'session_changed'=>401,'anonymous'=>401]as$case=>$expected){
    $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/modeled_delivery_route.php',$case],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    if(!is_resource($process))throw new RuntimeException('route_fixture_process');
    fclose($pipes[0]);$body=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    $matched=is_string($body)&&preg_match('/\nMDR-RESULT:(\{[^\n]+\})\n/',$body,$match)===1;
    $result=$matched?json_decode($match[1],true):null;
    $check($exit===0&&$matched&&($result['status']??null)===$expected
        &&($result['unchanged']??false)===true&&$errors==='','real read-only endpoint case: '.$case);
    if(in_array($case,['admin','admin_previous_day'],true))$check(str_contains($body,'Синтетический турист: проверка доступа')&&str_contains($body,'1 250 ₽')
        &&!str_contains($body,'Реестр сейчас недоступен'),'actual admin route composes reader, monitor and renderer');
    else $check(!str_contains($body,'Синтетический турист: проверка доступа')&&!str_contains($body,'1 250 ₽'),'denied endpoint returns no private rows: '.$case);
}


$check(str_contains($html,'view=reconciliation')&&str_contains($html,'view=forecast-payments'),'closed ledger links to daily reconciliation and forecast-payment views');
foreach(['reconciliation','forecast-payments','forecast-summary']as$analyticsView){
    $routeCases=['admin'=>200,'admin_previous_day'=>200,'manager'=>403,'inactive'=>401,'no_project'=>403,'foreign'=>404,
        'post'=>405,'parameters'=>422,'session_changed'=>401,'anonymous'=>401];
    if($analyticsView==='forecast-summary')$routeCases+=['cursor'=>422,'invalid_period'=>422];
    foreach($routeCases as$case=>$expected){
        $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/modeled_delivery_route.php',$case,$analyticsView],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
        if(!is_resource($process))throw new RuntimeException('analytics_route_fixture_process');
        fclose($pipes[0]);$body=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        $matched=is_string($body)&&preg_match('/\\nMDR-RESULT:(\\{[^\\n]+\\})\\n/',$body,$match)===1;
        $result=$matched?json_decode($match[1],true):null;
        $check($exit===0&&$matched&&($result['status']??null)===$expected
            &&($result['unchanged']??false)===true&&$errors==='','new private GET route: '.$analyticsView.' / '.$case);
        if(in_array($case,['admin','admin_previous_day'],true)){
            $check(str_contains($body,'Закрытый просмотр')&&str_contains($body,'method="get"'),'new owner view is composed by its real read-only endpoint: '.$analyticsView.' / '.$case);
            if($analyticsView==='forecast-payments')
                $check(str_contains($body,'Синтетический турист: проверка доступа')&&str_contains($body,'оценка v1')
                    &&!str_contains($body,'Сопоставление сейчас недоступно'),'real owner route renders an admitted saved early forecast: '.$case);
            if($analyticsView==='forecast-summary')
                $check(str_contains($body,'Сводка ранних прогнозов')&&str_contains($body,'id="aggregateTotals"')
                    &&str_contains($body,'value="1" selected')&&!str_contains($body,'Сводка сейчас недоступна')
                    &&!str_contains($body,'Синтетический турист: проверка доступа')&&!str_contains($body,'/manager2/#conversation='),
                    'real anonymous aggregate route renders complete one-day cohort without individual records: '.$case);
        }else $check(!str_contains($body,'1 250 ₽')&&!str_contains($body,'Синтетический турист: проверка доступа'),'new denied owner view releases no fixture records: '.$analyticsView.' / '.$case);
    }
}

$aggregateEmpty=['n'=>0,'predicted_probability_mean'=>null,'window_open'=>0,'horizon_elapsed'=>0,'later_evidence_observed'=>0,'unknown'=>0,'waiting'=>0];
$aggregateObserved=['n'=>1,'predicted_probability_mean'=>0.35,'window_open'=>1,'horizon_elapsed'=>0,'later_evidence_observed'=>1,'unknown'=>0,'waiting'=>0];
$aggregateUnknown=['n'=>1,'predicted_probability_mean'=>0.08,'window_open'=>0,'horizon_elapsed'=>1,'later_evidence_observed'=>0,'unknown'=>1,'waiting'=>0];
$aggregateEdges=[0.0,0.05,0.10,0.20,0.35,0.50,1.0];$aggregateBins=[];
for($i=0;$i<6;$i++)$aggregateBins[]=($i===1?$aggregateUnknown:($i===4?$aggregateObserved:$aggregateEmpty))
    +['lower'=>$aggregateEdges[$i],'upper'=>$aggregateEdges[$i+1],'upper_inclusive'=>$i===5];
$aggregateStats=['first_forecasts'=>2,'window_open'=>1,'horizon_elapsed'=>1,'later_evidence_observed'=>1,'followup_unknown'=>1,'followup_waiting'=>0,
    'evidence_cases'=>['self_reported_payment'=>1,'manager_confirmed_payment'=>1],
    'unknown_by_reason'=>['horizon_elapsed_coverage_unknown'=>1],
    'by_platform'=>['max'=>$aggregateObserved,'telegram'=>$aggregateUnknown],
    'by_stage'=>['substantive_dialogue'=>$aggregateEmpty,'concrete_request'=>$aggregateUnknown,'exact_offer_discussion'=>$aggregateEmpty,
        'general_purchase_intent'=>$aggregateEmpty,'agency_purchase_intent'=>$aggregateObserved],'probability_bins'=>$aggregateBins];
$aggregatePage=['version'=>'forecast_payment_aggregate_v1','available'=>true,'reason'=>null,
    'filters'=>['date'=>'2026-11-05','period'=>1,'platform'=>'all'],'as_of'=>'2026-11-05T12:00:00Z',
    'window'=>['from'=>'2026-11-04T21:00:00Z','until'=>'2026-11-05T12:00:01Z','timezone'=>'Europe/Moscow'],
    'unit'=>'canonical_scope_and_request_anchor','cohort_basis'=>'first_admitted_prediction_profile_observed_clock',
    'selection_exhausted'=>true,'cohort_membership_complete'=>true,'totals_available'=>true,'totals'=>$aggregateStats,'inspected'=>$aggregateStats,
    'coverage'=>['scanned_profiles'=>4,'omitted_by_reason'=>['not_global_first'=>2],'invalid_earlier_profiles_by_reason'=>[],
        'stopped_reason'=>null,'max_scan_profiles'=>128,'max_seconds'=>15,'max_bytes'=>4194304,'source_history_complete'=>false,'trip_identity'=>'unverified'],
    'payment_label'=>null,'probability_calibrated'=>false,'verified_payment'=>false,'evaluation_eligible'=>false];
$aggregateOriginal=$aggregatePage;$aggregateHtml=ModeledConversionForecastPaymentAggregateView::render($aggregatePage);
$check(str_contains($aggregateHtml,'id="aggregateTotals"')&&!str_contains($aggregateHtml,'id="aggregateInspected"')
    &&str_contains($aggregateHtml,'Итоги выбранной группы')&&str_contains($aggregateHtml,'Диапазоны ранней оценки')
    &&str_contains($aggregateHtml,'05.11.2026 15:00:00')&&str_contains($aggregateHtml,'value="1" selected'),
    'aggregate view shows validated complete one-day totals and Moscow clock');
$check(str_contains($aggregateHtml,'В общем числе он учитывается один раз')&&str_contains($aggregateHtml,'полнота наблюдения неизвестна')
    &&str_contains($aggregateHtml,'не становится «не купил»')&&str_contains($aggregateHtml,'не откалиброваны')
    &&!str_contains($aggregateHtml,'/manager2/#conversation=')&&!str_contains($aggregateHtml,'name="cursor"')
    &&!str_contains($aggregateHtml,'<script')&&$aggregatePage===$aggregateOriginal,
    'aggregate view preserves evidence overlap unknown outcomes privacy and immutable input');
$check(str_contains($aggregateHtml,'view=forecast-payments')&&str_contains($aggregateHtml,'view=delivery')
    &&str_contains($aggregateHtml,'method="get"'),'aggregate view has owner navigation and read-only form');
$partialAggregate=$aggregatePage;$partialAggregate['totals_available']=false;$partialAggregate['totals']=null;
$partialAggregate['selection_exhausted']=false;$partialAggregate['cohort_membership_complete']=false;
$partialAggregate['coverage']['stopped_reason']='scan_limit';$partialAggregate['coverage']['scanned_profiles']=128;
$partialAggregate['filters']['period']=7;
$partialAggregateHtml=ModeledConversionForecastPaymentAggregateView::render($partialAggregate);
$check(str_contains($partialAggregateHtml,'id="aggregateInspected"')&&!str_contains($partialAggregateHtml,'id="aggregateTotals"')
    &&str_contains($partialAggregateHtml,'Общие итоги недоступны')&&str_contains($partialAggregateHtml,'Выбрать один день')
    &&!str_contains($partialAggregateHtml,'Итоги выбранной группы'),'partial aggregate never presents inspected counts as full totals');
$badAggregate=$partialAggregate;$badAggregate['totals']=$aggregateStats;
$check(str_contains(ModeledConversionForecastPaymentAggregateView::render($badAggregate),'Сводка сейчас недоступна'),
    'partial aggregate with fabricated totals fails closed');
$badAggregate=$aggregatePage;$badAggregate['coverage']['omitted_by_reason']['prediction_admission']=1;
$check(str_contains(ModeledConversionForecastPaymentAggregateView::render($badAggregate),'Сводка сейчас недоступна'),
    'aggregate totals with unresolved membership omissions fail closed');
$badAggregate=$aggregatePage;$badAggregate['totals_available']=false;$badAggregate['totals']=null;
$check(str_contains(ModeledConversionForecastPaymentAggregateView::render($badAggregate),'Сводка сейчас недоступна'),
    'aggregate totals and cohort membership flags must agree');
$badAggregate=$aggregatePage;$badAggregate['inspected']['by_platform']['max']['n']=2;$badAggregate['totals']=$badAggregate['inspected'];
$check(str_contains(ModeledConversionForecastPaymentAggregateView::render($badAggregate),'Сводка сейчас недоступна'),
    'aggregate inconsistent denominators cannot be rendered');
$badAggregate=$aggregatePage;$badAggregate['inspected']['probability_bins'][5]['upper_inclusive']=false;$badAggregate['totals']=$badAggregate['inspected'];
$check(str_contains(ModeledConversionForecastPaymentAggregateView::render($badAggregate),'Сводка сейчас недоступна'),
    'aggregate bin boundary contract is checked before presentation');
$badAggregate=$aggregatePage;$badAggregate['window']['timezone']='Europe/Kaliningrad';
$check(str_contains(ModeledConversionForecastPaymentAggregateView::render($badAggregate),'Сводка сейчас недоступна'),
    'aggregate old calendar cannot be labelled Moscow');
$badAggregate=$aggregatePage;$badAggregate['rows']=[['customer_name'=>'PRIVATE_FIXTURE_NAME','conversation_id'=>'PRIVATE_FIXTURE_ID']];
$badAggregateHtml=ModeledConversionForecastPaymentAggregateView::render($badAggregate);
$check(str_contains($badAggregateHtml,'Сводка сейчас недоступна')&&!str_contains($badAggregateHtml,'PRIVATE_FIXTURE'),
    'aggregate view rejects individual records and does not echo their values');
$failedAggregateHtml=ModeledConversionForecastPaymentAggregateView::render($aggregatePage,[],true);
$check(str_contains($failedAggregateHtml,'Сводка сейчас недоступна')&&!str_contains($failedAggregateHtml,'id="aggregateTotals"')
    &&!str_contains($failedAggregateHtml,'id="aggregateInspected"'),'failed private read cannot render cached aggregate numbers');
$invalidAggregateHtml=ModeledConversionForecastPaymentAggregateView::render(null,['date'=>"2026-11-05\0PRIVATE_FIXTURE",'period'=>1]);
$check(str_contains($invalidAggregateHtml,'Сводка сейчас недоступна')&&!str_contains($invalidAggregateHtml,'PRIVATE_FIXTURE'),
    'malformed input on unavailable aggregate remains a neutral page');

if($failures){fwrite(STDERR,implode(PHP_EOL,$failures).PHP_EOL);exit(1);}
echo 'modeled delivery view: '.$checks.' checks passed'.PHP_EOL;
