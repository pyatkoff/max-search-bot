<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ModeledConversionValueRegistryView.php';

$checks=0;$failures=[];
$check=static function(bool $ok,string $name)use(&$checks,&$failures):void{$checks++;if(!$ok)$failures[]=$name;};
$now=1793613600;
// Only synthetic, already-admitted value inputs. No DB, tracker, status or source read.
$member=ModeledConversionAssignedValue::member([
    'external_user_id'=>'101','source_id'=>'9','record_id'=>'7','state'=>'subscribed',
    'subscribed_at_unix'=>$now-3600,'retention_checked_at_unix'=>null,'retention_record_id'=>null,
],$now);
$member['profile_binding']='no_dialogue';
$member['attribution']=['status'=>'unknown','reason'=>'missing_valid_current_client_id'];
$page=['rules_version'=>ModeledConversionAssignedValue::VERSION,'rows'=>[$member],
    'next_after'=>'7','has_more'=>true,'omitted_owner_or_test'=>0,
    'coverage'=>'bounded_current_max_members_not_complete_cohort'];
$html=ModeledConversionValueRegistryView::render('members',$page);
$check(str_contains($html,'<strong>'.$member['known_subtotal_rub'].' ₽</strong>'),'renders existing experimental subtotal without another scoring formula');
$check(str_contains($html,'неизвестными')&&str_contains($html,'Неизвестно · тестовый вес при подтверждении: 25 ₽'),'unknown retention remains unknown');
$check(str_contains($html,'Прошедшее время и отсутствие отписки её не заменяют'),'retention gap explained');
$check(str_contains($html,'отсутствие переписки не доказано'),'missing usable profile does not prove no dialogue');
$check(str_contains($html,'membership:7')&&str_contains($html,'Событие произошло')&&str_contains($html,'Основание записано')
    &&str_contains($html,'Основание стало доступно')&&str_contains($html,'Оценка на:'),'ground references and all four clocks visible');
$check(str_contains($html,'view=members&amp;after=7')&&str_contains($html,'rel="next"'),'bounded next page link preserves selected source');
$check(str_contains($html,'Подписки Telegram недоступны из MAX-моста')&&str_contains($html,'диалогов Telegram видны отдельно'),'separate MAX membership and Telegram profile coverage');
$check(str_contains($html,'Общий размер доступного источника')&&str_contains($html,'неизвестны'),'unknown denominator is explicit');
$check(str_contains($html,'<th scope="row">Подписчики MAX</th><td>1</td><td>неизвестно</td>')
    &&str_contains($html,'<th scope="row">Подписчики Telegram</th><td>источник недоступен</td>')
    &&str_contains($html,'<th scope="row">Профили диалогов MAX / Telegram</th><td>отдельный просмотр</td>'),'member coverage table counts only shown members and keeps other sources separate');
$check(str_contains($html,'Дополнительные 75 ₽ не согласованы и отключены')&&str_contains($html,'отправок: 0'),'unapproved extra and export remain disabled');
foreach(['Подписка','Наблюдаемое удержание через 30 минут','Первое действительное сообщение клиента','Содержательный запрос клиента','Связанный ответ на доставленное предложение']as$label)
    $check(str_contains($html,$label),'Russian stage label: '.$label);

$check(str_contains($html,'Экспериментальная сумма')&&str_contains($html,'были примером')&&str_contains($html,'Эта шкала не утверждена.'),'experimental amounts are not presented as approved monetary value');
$check(str_contains($html,'50 ₽ — желаемая цена привлечения подписчика')&&str_contains($html,'ДРР 10% пока кандидат'),'acquisition price and tentative DRR remain distinct');
$check(!str_contains($html,'назначенных бизнесом')&&!str_contains($html,'Начислено:'),'observed stages do not assert approved accrual');
$profile=ModeledConversionAssignedValue::profile([
    'access_status'=>'allowed','subject'=>['project_key'=>'anytour','platform'=>'telegram','conversation_id'=>'42','customer_key'=>'17'],
    'profile'=>['prediction_at'=>'2026-11-02T10:00:00Z'],
]);
$profile['attribution']=['status'=>'unknown','reason'=>'profile_does_not_prove_advertising_visit'];
$profile['coverage']['unit']='unresolved_conversation_prefix_not_unique_person';
$profilePage=['rules_version'=>ModeledConversionAssignedValue::VERSION,'rows'=>[$profile],'next_after'=>'42','has_more'=>false,'omitted'=>0,
    'coverage'=>'bounded_current_admitted_profiles_not_complete_cohort'];
$profileHtml=ModeledConversionValueRegistryView::render('profiles',$profilePage);
$check(str_contains($profileHtml,'Telegram · диалог №42 · субъект №17'),'profile platform and private canonical identifiers visible');
$check(str_contains($profileHtml,'не установленный уникальный человек')&&str_contains($profileHtml,'Связь с подписчиком не предполагается'),'unresolved profile unit explicit');
$check(str_contains($profileHtml,'Не суммируйте их как уникальных людей')&&!str_contains($profileHtml,'Общая сумма'),'no combined member/profile total');
$check(str_contains($profileHtml,'Нет проверенной записи подписки в доступном профиле. MAX-мост не покрывает подписки Telegram.'),'Telegram membership gap explained');

$mixedMax=$profile;$mixedMax['value_scope']['platform']='max';$mixedMax['value_scope']['conversation_id']='43';
$mixedPage=$profilePage;$mixedPage['rows']=[$mixedMax,$profile];
$mixedHtml=ModeledConversionValueRegistryView::render('profiles',$mixedPage);
$check(str_contains($mixedHtml,'<th scope="row">Профили MAX</th><td>1</td><td>неизвестно</td>')
    &&str_contains($mixedHtml,'<th scope="row">Профили Telegram</th><td>1</td><td>неизвестно</td>'),'mixed profile page reports one MAX and one Telegram with unknown denominators');
$check(!str_contains($mixedHtml,'<th scope="row">Другой / неизвестный канал</th>')
    &&str_contains($mixedHtml,'не накопленный итог и не число уникальных людей'),'profile counts describe this page only and omit absent unknown channel');
$unresolved=$profile;$unresolved['value_scope']['platform']='unresolved_fixture';
$mixedPage['rows'][]=$unresolved;
$check(str_contains(ModeledConversionValueRegistryView::render('profiles',$mixedPage),'<th scope="row">Другой / неизвестный канал</th><td>1</td><td>неизвестно</td>'),'unresolved platform retains its separate shown-page count');

$attack='<img src=x onerror="alert(1)"><script>alert(2)</script>&';
$unsafe=$member;
$unsafe['value_scope']['external_user_id']=$attack;
$unsafe['value_scope']['membership_source_id']=$attack;
$unsafe['rules_version']=$attack;
$unsafe['evaluated_at']=$attack;
$unsafe['attribution']=['status'=>'known','client_id'=>$attack,'basis'=>$attack];
$unsafe['reaction_type']=$attack;
$unsafe['coverage']=['danger'=>$attack];
$unsafe['stages']['subscription']['source_refs']=[$attack];
$unsafe['stages']['subscription']['occurred_at']=$attack;
$unsafe['stages']['subscription']['recorded_at']=$attack;
$unsafe['stages']['subscription']['available_at']=$attack;
$unsafePage=$page;$unsafePage['rows']=[$unsafe];$unsafePage['coverage']=$attack;
$escaped=ModeledConversionValueRegistryView::render('members',$unsafePage);
$check(!str_contains($escaped,'<script>')&&!str_contains($escaped,'<img src=')&&!str_contains($escaped,'onerror="'),'untrusted identities evidence clocks attribution and coverage cannot create markup');
$check(str_contains($escaped,'&lt;img')&&str_contains($escaped,'&quot;alert(1)&quot;'),'untrusted strings escaped in HTML');
$unsafe['attribution']=['status'=>'unknown','reason'=>$attack];$unsafePage['rows']=[$unsafe];
$check(!str_contains(ModeledConversionValueRegistryView::render('members',$unsafePage),'<script>'),'unknown attribution reason escaped');

$empty=$page;$empty['rows']=[];$empty['next_after']='0';$empty['has_more']=false;
$emptyHtml=ModeledConversionValueRegistryView::render('members',$empty);
$check(str_contains($emptyHtml,'На этой странице доступных строк нет')&&!str_contains($emptyHtml,'Источник недоступен'),'empty successful page differs from unavailable');
$unavailable=ModeledConversionValueRegistryView::render('members',$page,'7',true);
$check(str_contains($unavailable,'Источник недоступен')&&str_contains($unavailable,'не нулевая ценность')
    &&!str_contains($unavailable,'membership:7')&&!str_contains($unavailable,'<article'),'failed page never renders stale rows or an empty success');
$check(str_contains($unavailable,'view=members&amp;after=7'),'explicit retry keeps bounded failed cursor');
$oversize=$page;$oversize['rows']=array_fill(0,6,$member);
$check(str_contains(ModeledConversionValueRegistryView::render('members',$oversize),'Источник недоступен'),'renderer refuses more than five rows');
$badCursor=$page;$badCursor['next_after']=$attack;
$check(str_contains(ModeledConversionValueRegistryView::render('members',$badCursor),'Источник недоступен'),'untrusted invalid cursor cannot enter link');

$route=(string)file_get_contents(dirname(__DIR__).'/manager/assigned-value.php');
$position=static fn(string $needle):int=>($p=strpos($route,$needle))===false?PHP_INT_MAX:$p;
$check($position('ProjectAccessService::initializeReadOnly()')<$position('$manager=ManagerHttp::requireManager()'),'read-only project initialization precedes auth lookup');
$firstRead=$position("historyMemberPage('98615635',".'$sourceAfter)');
$check($position('ManagerHttp::requireAdmin($manager)')<$firstRead
    &&$position("ProjectAccessService::canAccess(".'$actor'.",'anytour')")<$firstRead
    &&$position("ProjectConfig::projectId()!=='anytour'")<$firstRead,'admin and AnyTour project admission precedes source call');
$reopen=$position("session_start(['read_and_close'=>true])");
$check($reopen>$firstRead&&$reopen<PHP_INT_MAX
    &&str_contains(substr($route,$reopen),'ManagerHttp::managerId()!==$actor')
    &&str_contains(substr($route,$reopen),'ManagerHttp::requireAdmin($fresh)')
    &&str_contains(substr($route,$reopen),"ProjectAccessService::canAccess(".'$actor'.",'anytour')"),'session actor and live admin project access rechecked after private read');
$check(str_contains($route,"!=='GET'")&&str_contains($route,"array_diff(array_keys(".'$_GET'."),".'$allowed'.")")
    &&str_contains($route,"['delivery','members','profiles','acquisition']")&&str_contains($route,"{0,19}"),'GET-only strict view and bounded cursor admission');
$check(str_contains($route,'Cache-Control: private, no-store')&&str_contains($route,'X-Content-Type-Options: nosniff')
    &&str_contains($route,'Vary: Cookie')&&str_contains($route,"default-src 'none'")&&str_contains($route,"frame-ancestors 'none'"),'private cache and security headers');
$check(str_contains($route,'$page=null;$unavailable=true;')&&str_contains($route,'http_response_code($unavailable?503:200)'),'source failure has explicit unavailable status');
$check(!str_contains($route,'get_modeled_conversion_status')&&!str_contains($route,'->tick(')
    &&!str_contains($route,'->start(')&&!str_contains($route,'conversion_upload'),'viewer introduces no status tick start or upload operation');


// First useful semantic prediction stays separate from the experimental money formula.
$predictionSubject=['project_key'=>'anytour','platform'=>'telegram','conversation_id'=>'42','customer_key'=>'17'];
$predictionBuilder=new ModeledConversionSemanticPrediction($predictionSubject,'2026-11-02T10:00:00Z',
    '2026-11-02T09:00:00Z','2026-11-02T12:00:00Z','100','200');
$predictionMessageAt=strtotime('2026-11-02T08:00:00Z');
$predictionBuilder->observeMessage('customer','Подберите тур в Турцию на 7 ночей.','journal:150',
    $predictionMessageAt,$predictionMessageAt+1,2,[
        'version'=>'ru_archive_contextual_v2','status'=>'scanned','flags'=>[],
        'episode_boundary'=>'first_trip_request_candidate',
        'events'=>[['goal'=>'substantive_interest','source_ref'=>'journal:150','episode'=>1]],
    ]);
$predictionBlock=$predictionBuilder->finish(['early_forecast'=>['status'=>'early_assessment']]);
$predictionWrapper=['access_status'=>'allowed','subject'=>$predictionSubject,
    'profile'=>['prediction_at'=>'2026-11-02T10:00:00Z'],'semantic_prediction'=>$predictionBlock];
$predictionFields=ModeledConversionValueRegistry::predictionFields($predictionWrapper);
$check($predictionFields['semantic_prediction']['availability']==='available'
    &&$predictionFields['semantic_prediction']['value']['prediction_digest']===$predictionBlock['prediction_digest'],
    'private projection admits matching subject bounded digest-checked saved prediction');
$predictionRow=array_replace($profile,$predictionFields);
$predictionPage=$profilePage;$predictionPage['rows']=[$predictionRow];
$predictionHtml=ModeledConversionValueRegistryView::render('profiles',$predictionPage);
$check(str_contains($predictionHtml,'8,33%')&&str_contains($predictionHtml,'Предварительный прогноз оплаты за 30 дней')
    &&str_contains($predictionHtml,'Экспериментальная сумма'),'provisional payment probability and experimental money rendered separately');
$check(str_contains($predictionHtml,'2026-11-02T12:00:00Z')&&str_contains($predictionHtml,'2026-11-02T09:00:00Z')
    &&str_contains($predictionHtml,'journal:150')&&str_contains($predictionHtml,'ревизия 2')
    &&str_contains($predictionHtml,'Конкретные условия поездки'),'forecast actual clock cutoff anchor revision and readable reasons visible');
$check(str_contains($predictionHtml,'правила не обучены и не откалиброваны')
    &&str_contains($predictionHtml,'текущая поездка не подтверждены'),'training and current trip limitations explicit');
$check(str_contains($profileHtml,'Прогноз ещё не сохранён')&&!str_contains($profileHtml,'class="predictionNumber"'),
    'older profiles explicitly have no saved prediction rather than a zero probability');
$check($predictionRow['known_subtotal_rub']===$profile['known_subtotal_rub']
    &&$predictionRow['stages']===$profile['stages'],'projection never changes experimental weights or awards');
foreach(['digest','subject','raw_reason','raw_field','oversize','future_evidence','model_hash','fence','known_outcome_probability']as$invalid){
    $bad=$predictionWrapper;
    if($invalid==='digest')$bad['semantic_prediction']['estimated_probability']=0.9;
    if($invalid==='subject')$bad['semantic_prediction']['subject']['customer_key']='18';
    if($invalid==='raw_reason')$bad['semantic_prediction']['reasons']=[$attack];
    if($invalid==='raw_field')$bad['semantic_prediction']['private_text']=$attack;
    if($invalid==='oversize')$bad['semantic_prediction']['private_text']=str_repeat('x',16385);
    if($invalid==='future_evidence')$bad['semantic_prediction']['evidence'][0]['available_at']='2026-11-02T09:00:01Z';
    if($invalid==='model_hash')$bad['semantic_prediction']['model_sha256']='unknown';
    if($invalid==='fence')$bad['semantic_prediction']['source_fence_journal_id']='100';
    if($invalid==='known_outcome_probability')$bad['semantic_prediction']['outcome_observations']=[
        ['kind'=>'document_issuance','source_ref'=>'journal:151','occurred_at'=>'2026-11-02T08:01:00Z',
            'available_at'=>'2026-11-02T08:01:01Z','source_version_no'=>1],
    ];
    if($invalid!=='digest')$bad['semantic_prediction']['prediction_digest']=ModeledConversionSemanticPrediction::digest($bad['semantic_prediction']);
    $badFields=ModeledConversionValueRegistry::predictionFields($bad);
    $check($badFields['semantic_prediction']['availability']==='unavailable'
        &&$badFields['semantic_prediction']['value']===null,'malformed forecast explicitly unavailable: '.$invalid);
    $badPage=$predictionPage;$badPage['rows']=[array_replace($profile,$badFields)];
    $badHtml=ModeledConversionValueRegistryView::render('profiles',$badPage);
    $check(str_contains($badHtml,'Прогноз недоступен')&&!str_contains($badHtml,'class="predictionNumber"')
        &&!str_contains($badHtml,'<img src=')&&!str_contains($badHtml,'<script>'),
        'malformed forecast does not render probability or raw payload: '.$invalid);
}
$ordered=$predictionWrapper;
$ordered['semantic_prediction']=array_reverse($predictionBlock,true);
$ordered['semantic_prediction']['subject']=array_reverse($predictionBlock['subject'],true);
$check(ModeledConversionValueRegistry::predictionFields($ordered)['semantic_prediction']['availability']==='available',
    'projection tolerates native JSON object key ordering');
$maxWrapper=$predictionWrapper;$maxWrapper['subject']['platform']='max';
$maxWrapper['semantic_prediction']['subject']['platform']='max';
$maxWrapper['semantic_prediction']['prediction_digest']=ModeledConversionSemanticPrediction::digest($maxWrapper['semantic_prediction']);
$joined=ModeledConversionAssignedValue::merge($member,ModeledConversionAssignedValue::profile($maxWrapper));
$joined=array_replace($joined,ModeledConversionValueRegistry::predictionFields($maxWrapper));
$joined['profile_binding']='exact_private_chat_current_profile';
$joinedPage=$page;$joinedPage['rows']=[$joined];
$joinedHtml=ModeledConversionValueRegistryView::render('members',$joinedPage);
$check(str_contains($joinedHtml,'8,33%')&&str_contains($joinedHtml,'journal:150')
    &&$joined['known_subtotal_rub']===$member['known_subtotal_rub'],'exact merged member profiles expose prediction without changing money');
$badCapsule=$predictionRow;$badCapsule['semantic_prediction']['value']['reasons']=[$attack];
$badCapsule['semantic_prediction']['value']['prediction_digest']=ModeledConversionSemanticPrediction::digest($badCapsule['semantic_prediction']['value']);
$badPage=$predictionPage;$badPage['rows']=[$badCapsule];
$check(str_contains(ModeledConversionValueRegistryView::render('profiles',$badPage),'Прогноз недоступен'),
    'pure renderer revalidates capsules and refuses arbitrary text codes');


$waitingFollowup=[
    'version'=>'semantic_purchase_followup_v1','target'=>'conversation_payment_evidence_30d_v1',
    'meaning'=>'diagnostic_conversation_evidence_not_bank_settlement','status'=>'waiting','reason'=>'no_earlier_prediction',
    'observed_at'=>$predictionBlock['frozen_at'],'current_request_anchor'=>$predictionBlock['current_request_anchor'],
    'prior_prediction_digest'=>null,'prior_prediction_at'=>null,'prior_estimated_probability'=>null,
    'horizon_seconds'=>2592000,'horizon_end_at'=>null,'evidence'=>[],'payment_label'=>null,
    'calibrated_purchase_probability'=>null,'independent_validation'=>false,'source_history_complete'=>false,
    'evaluation_eligible'=>false,'evaluation_reason'=>'verified_outcome_and_complete_coverage_unavailable',
    'export_allowed'=>false,'conversion_uploads'=>0,'model_calls'=>0,
];
$waitingWrapper=$predictionWrapper;$waitingWrapper['prediction_followup']=$waitingFollowup;
$waitingFields=ModeledConversionValueRegistry::predictionFields($waitingWrapper);
$waitingPage=$predictionPage;$waitingPage['rows']=[array_replace($profile,$waitingFields)];
$waitingHtml=ModeledConversionValueRegistryView::render('profiles',$waitingPage);
$check($waitingFields['prediction_followup']['availability']==='available'
    &&str_contains($waitingHtml,'Сравнение с последующими сигналами')
    &&str_contains($waitingHtml,'Нет более раннего сохранённого прогноза'),'waiting followup is visible separately from the saved prediction');
$check(str_contains($profileHtml,'Сравнение ещё не сохранено.'),'older profiles have explicit unsaved followup');
$currentBuilder=new ModeledConversionSemanticPrediction($predictionSubject,'2026-11-03T11:00:00Z',
    '2026-11-03T11:00:00Z','2026-11-03T12:00:00Z','200','300');
$currentBuilder->observeMessage('customer','Подберите тур в Турцию на 7 ночей.','journal:150',
    $predictionMessageAt,$predictionMessageAt+1,1,[
        'version'=>'ru_archive_contextual_v2','status'=>'scanned','flags'=>[],
        'episode_boundary'=>'first_trip_request_candidate',
        'events'=>[['goal'=>'substantive_interest','source_ref'=>'journal:150','episode'=>1]],
    ]);
$paymentAt=strtotime('2026-11-03T10:00:00Z');
$currentBuilder->observeMessage('customer','Оплатили тур.','journal:201',$paymentAt,$paymentAt+1,1,[
    'version'=>'ru_archive_contextual_v2','status'=>'scanned','flags'=>[],'episode_boundary'=>null,
    'events'=>[['goal'=>'payment_self_report','source_ref'=>'journal:201','episode'=>1]],
]);
$currentBlock=$currentBuilder->finish(['early_forecast'=>['status'=>'target_already_observed']]);
$priorBlock=$predictionBlock;$priorBlock['anchor_source_version_no']=1;
foreach($priorBlock['evidence']as&$priorEvidence)$priorEvidence['source_version_no']=1;unset($priorEvidence);
$priorBlock['prediction_digest']=ModeledConversionSemanticPrediction::digest($priorBlock);
$observedFollowup=$waitingFollowup;
$observedFollowup['status']='observed';$observedFollowup['reason']='future_conversation_payment_evidence';
$observedFollowup['observed_at']=$currentBlock['frozen_at'];
$observedFollowup['prior_prediction_digest']=$priorBlock['prediction_digest'];
$observedFollowup['prior_prediction_at']=$priorBlock['prediction_at'];
$observedFollowup['prior_estimated_probability']=$priorBlock['estimated_probability'];
$observedFollowup['horizon_end_at']=$priorBlock['horizon_end_at'];
$observedFollowup['evidence']=[['label'=>'self_reported_payment','source_ref'=>'journal:201',
    'occurred_at'=>'2026-11-03T10:00:00Z','available_at'=>'2026-11-03T10:00:01Z','source_version_no'=>1]];
$currentWrapper=['access_status'=>'allowed','subject'=>$predictionSubject,
    'profile'=>['prediction_at'=>'2026-11-03T11:00:00Z'],'semantic_prediction'=>$currentBlock,
    'prediction_followup'=>$observedFollowup];
$currentFields=ModeledConversionValueRegistry::predictionFields($currentWrapper);
$currentPage=$predictionPage;$currentPage['rows']=[array_replace($profile,$currentFields)];
$currentHtml=ModeledConversionValueRegistryView::render('profiles',$currentPage);
$check($currentFields['prediction_followup']['availability']==='available'
    &&str_contains($currentHtml,'Наблюдение получено')&&str_contains($currentHtml,'Сохранённая ранее вероятность')
    &&str_contains($currentHtml,'Клиент сообщил об оплате')&&str_contains($currentHtml,'journal:201'),
    'eligible later self report is displayed with the immutable earlier prediction');
$check(str_contains($currentHtml,'не рассчитывается')&&str_contains($currentHtml,'без банковской проверки')
    &&str_contains($currentHtml,'не считается неоплатой'),'known outcome suppresses current P and comparison remains diagnostic');
foreach(['reason','clock','reference','probability','edited_anchor']as$invalid){
    $bad=$currentWrapper;
    if($invalid==='reason')$bad['prediction_followup']['reason']=$attack;
    if($invalid==='clock')$bad['prediction_followup']['evidence'][0]['available_at']='2026-11-03T11:00:01Z';
    if($invalid==='reference')$bad['prediction_followup']['evidence'][0]['source_ref']='journal:202';
    if($invalid==='probability')$bad['prediction_followup']['prior_estimated_probability']=$attack;
    if($invalid==='edited_anchor'){
        $bad['semantic_prediction']['anchor_source_version_no']=2;
        $bad['semantic_prediction']['prediction_digest']=ModeledConversionSemanticPrediction::digest($bad['semantic_prediction']);
    }
    $badFields=ModeledConversionValueRegistry::predictionFields($bad);
    $badPage=$currentPage;$badPage['rows']=[array_replace($profile,$badFields)];
    $check($badFields['prediction_followup']['availability']==='unavailable'
        &&str_contains(ModeledConversionValueRegistryView::render('profiles',$badPage),'Сравнение недоступно'),
        'malformed followup explicitly unavailable: '.$invalid);
}


// Retained membership history has a separate evidence DTO, session-bound cursor,
// and pure renderer. No native tracker, network, transcript or monetary writes.
require_once dirname(__DIR__).'/services/MaxTrackerLookupClient.php';
require_once dirname(__DIR__).'/services/MaxMemberHistoryViewCursor.php';
require_once dirname(__DIR__).'/services/MaxMemberHistoryView.php';
$historyNow=strtotime('2026-11-05T12:00:00Z');
$historyHash=str_repeat('a',64);$historyKey=str_repeat('b',64);
$historyRow=static fn(string $user,string $record,string $source='10'):array=>[
    'external_user_id'=>$user,'source_id'=>$source,'state'=>'observed_subscribed',
    'last_event_record_id'=>$record,'last_event_recorded_at_unix'=>$historyNow-1800,
    'episode_join_record_id'=>'10','episode_join_recorded_at_unix'=>$historyNow-3600,
    'entry_basis'=>'first_retained_join'];
$historySourceCursor=MaxTrackerLookupProtocol::encodeHistoryCursor('98615635',$historyNow,'900','700',$historyHash);
$historySourcePage=['basis'=>'retained_membership_history_v1','rows'=>[$historyRow('501','900')],
    'cutoff_unix'=>$historyNow,'history_fence_id'=>'900','next_cursor'=>$historySourceCursor,'has_more'=>true,
    'scanned_event_count'=>64,'omitted_unknown_count'=>1,'coverage'=>'bounded_retained_history_not_live_platform_verification'];
$historyEnvelope=static fn(array $p):array=>['ok'=>true,'version'=>1,'counter_id'=>'98615635',
    'tracker_timezone'=>'Europe/London','history_member_page'=>$p];
$historyRejects=static function(callable $f):bool{
    try{$f();}catch(RuntimeException|InvalidArgumentException $ignored){return true;}return false;
};
$check(MaxTrackerLookupClient::validateHistoryMemberPage($historyEnvelope($historySourcePage),'98615635','0',$historyNow)===$historySourcePage,
    'history client accepts the exact observed, nonfinancial page contract');
$historyEmpty=$historySourcePage;$historyEmpty['rows']=[];
$check(MaxTrackerLookupClient::validateHistoryMemberPage($historyEnvelope($historyEmpty),'98615635','0',$historyNow)===$historyEmpty,
    'history client permits an empty intermediate scan that advances to older history');
$historyTerminal=$historySourcePage;$historyTerminal['rows']=[$historyRow('504','650')];
$historyTerminal['next_cursor']=null;$historyTerminal['has_more']=false;$historyTerminal['scanned_event_count']=4;$historyTerminal['omitted_unknown_count']=0;
$check(MaxTrackerLookupClient::validateHistoryMemberPage($historyEnvelope($historyTerminal),'98615635',$historySourceCursor,$historyNow)===$historyTerminal,
    'history client accepts an older final page in the same fixed snapshot');
foreach(['legacy_state','money_field','cid_field','duplicate_pair','ascending_ids','future_clock','reversed_clocks','past_fence','six_rows','bad_coverage','extra_envelope']as$historyInvalid){
    $bad=$historySourcePage;
    if($historyInvalid==='legacy_state')$bad['rows'][0]['state']='subscribed';
    if($historyInvalid==='money_field')$bad['rows'][0]['known_subtotal_rub']=50;
    if($historyInvalid==='cid_field')$bad['rows'][0]['client_id']='123456789';
    if($historyInvalid==='duplicate_pair'){$bad['rows'][]=$historyRow('501','850');}
    if($historyInvalid==='ascending_ids')$bad['rows']=[$historyRow('501','850'),$historyRow('502','900')];
    if($historyInvalid==='future_clock')$bad['rows'][0]['last_event_recorded_at_unix']=$historyNow+1;
    if($historyInvalid==='reversed_clocks')$bad['rows'][0]['episode_join_recorded_at_unix']=$historyNow;
    if($historyInvalid==='past_fence')$bad['rows'][0]['episode_join_record_id']='901';
    if($historyInvalid==='six_rows')$bad['rows']=array_fill(0,6,$historyRow('501','900'));
    if($historyInvalid==='bad_coverage')$bad['coverage']='live_platform_membership';
    $wire=$historyEnvelope($bad);if($historyInvalid==='extra_envelope')$wire['users']=[];
    $check($historyRejects(static fn()=>MaxTrackerLookupClient::validateHistoryMemberPage($wire,'98615635','0',$historyNow)),
        'history client rejects mismatched evidence shape: '.$historyInvalid);
}
foreach(['changed_cutoff','changed_fence','same_cursor','changed_source_map']as$historyInvalid){
    $bad=$historyEmpty;
    if($historyInvalid==='changed_cutoff')$bad['cutoff_unix']=$historyNow-1;
    if($historyInvalid==='changed_fence')$bad['history_fence_id']='901';
    if($historyInvalid==='same_cursor')$bad['next_cursor']=$historySourceCursor;
    if($historyInvalid==='changed_source_map')$bad['next_cursor']=MaxTrackerLookupProtocol::encodeHistoryCursor('98615635',$historyNow,'900','600',str_repeat('c',64));
    $check($historyRejects(static fn()=>MaxTrackerLookupClient::validateHistoryMemberPage($historyEnvelope($bad),'98615635',$historySourceCursor,$historyNow)),
        'history continuation rejects snapshot or cursor drift: '.$historyInvalid);
}
$historyWrapped=MaxMemberHistoryViewCursor::wrap($historySourceCursor,$historyKey,$historyNow);
$check(MaxMemberHistoryViewCursor::shape($historyWrapped)&&!MaxMemberHistoryViewCursor::shape($historySourceCursor)
    &&MaxMemberHistoryViewCursor::unwrap($historyWrapped,$historyKey,$historyNow)===$historySourceCursor,
    'owner URL receives a session MAC wrapper and never accepts the raw database cursor');
$check(MaxMemberHistoryViewCursor::unwrap('0',$historyKey,$historyNow)==='0',
    'the owner cursor explicitly starts a fresh snapshot');
$historyTampered=$historyWrapped;$historyTampered[strlen($historyTampered)-1]=$historyTampered[strlen($historyTampered)-1]==='0'?'1':'0';
foreach([
    [$historyTampered,$historyKey,$historyNow],
    [$historyWrapped,str_repeat('c',64),$historyNow],
    [$historySourceCursor,$historyKey,$historyNow],
    [$historyWrapped,$historyKey,$historyNow+901],
]as[$value,$key,$at])
    $check($historyRejects(static fn()=>MaxMemberHistoryViewCursor::unwrap($value,$key,$at)),
        'tampered, cross-session, raw and expired history cursors cannot be opened');
$check($historyRejects(static fn()=>MaxMemberHistoryViewCursor::wrap('0',$historyKey,$historyNow)),
    'fresh-start marker cannot be disguised as a continuation');

$historyDb=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$historyDb->exec('CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT)');
$historyDb->exec('CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT)');
$historyDb->exec('CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,channel TEXT,external_chat_id TEXT,is_test INTEGER)');
$historyDb->exec('CREATE TABLE max_channel_conversion_outbox(id INTEGER PRIMARY KEY,status TEXT,value_rub INTEGER)');
$historyDb->exec("INSERT INTO max_channel_conversion_outbox VALUES(1,'synthetic_unmodified',1250)");
$historyName='<script>history_name_attack()</script> & "name"';
$historyCustomer=$historyDb->prepare('INSERT INTO customers VALUES(?,?)');
foreach([[1,$historyName],[2,'OWNER_MUST_NOT_RENDER'],[3,'TEST_MUST_NOT_RENDER'],[5,'AMBIGUOUS_NAME_MUST_NOT_RENDER']]as$row)$historyCustomer->execute($row);
$historyChannel=$historyDb->prepare('INSERT INTO customer_channels VALUES(?,?,?,?,?,?)');
foreach([[1,1,'anytour','max','501','-501'],[2,2,'anytour','max','502','-502'],[3,3,'anytour','max','503','-503'],
    [5,5,'anytour','max','505','-505'],[6,1,'anytour','telegram','501','-501']]as$row)$historyChannel->execute($row);
$historyConversation=$historyDb->prepare('INSERT INTO conversations VALUES(?,?,?,?,?,?,?)');
foreach([[10,1,1,'anytour','max','-501',0],[2,2,2,'anytour','max','-502',0],[3,3,3,'anytour','max','-503',1],
    [30,3,3,'anytour','max','-503',0],[31,3,3,'anytour','max','-503',0],[32,3,3,'anytour','max','-503',0],
    [20,5,5,'anytour','max','-505',0],[21,5,5,'anytour','max','-505',0],[1,1,6,'anytour','telegram','-501',0]]as$row)$historyConversation->execute($row);
$historyDigest=static function(PDO $p):string{
    $data=[];foreach(['customers','customer_channels','conversations','max_channel_conversion_outbox']as$table)
        $data[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
};
$historyBefore=$historyDigest($historyDb);
$historyProjectionInput=$historySourcePage;
$historyProjectionInput['rows']=[$historyRow('501','900'),$historyRow('502','890'),$historyRow('503','880'),
    $historyRow('504','870'),$historyRow('505','860')];
$historyProjectionInput['omitted_unknown_count']=0;
$historyRegistry=new ModeledConversionValueRegistry($historyDb);
$historyProjected=$historyRegistry->historyMembers($historyProjectionInput,'0',$historyKey,$historyNow);
$check(array_column(array_column($historyProjected['rows'],'member'),'external_user_id')===['501','504','505']
    &&$historyProjected['omitted_owner_or_test']===2,
    'history registry excludes fixed owner and any matching test conversation, even behind newer ordinary bindings');
$check($historyProjected['rows'][0]['display_name']===$historyName
    &&$historyProjected['rows'][1]['display_name']==='Пользователь MAX 504'
    &&$historyProjected['rows'][2]['display_name']==='Пользователь MAX 505',
    'history needs no HL9 or conversation and uses names only for one exact native binding');
$check(MaxMemberHistoryViewCursor::unwrap($historyProjected['next_after'],$historyKey,$historyNow)===$historySourceCursor
    &&$historyProjected['scanned_event_count']===64,
    'filtering private rows preserves the source scan cursor instead of paging from visible rows');
$historyProjectedKeys=array_keys($historyProjected);sort($historyProjectedKeys);
$historyExpectedKeys=['basis','rows','cutoff_unix','history_fence_id','next_after','has_more','scanned_event_count','omitted_unknown_count','omitted_owner_or_test','coverage'];sort($historyExpectedKeys);
$check($historyProjectedKeys===$historyExpectedKeys
    &&array_keys($historyProjected['rows'][0])===['member','display_name']
    &&!str_contains(json_encode($historyProjected,JSON_THROW_ON_ERROR),'known_subtotal_rub')
    &&!str_contains(json_encode($historyProjected,JSON_THROW_ON_ERROR),'client_id'),
    'history projection contains membership evidence and labels without values or advertising identities');
$check($historyDigest($historyDb)===$historyBefore&&!$historyDb->inTransaction(),
    'history registry closes its read transaction and preserves conversations, identities and conversion outbox');
$historyDb->beginTransaction();
$check($historyRejects(static fn()=>$historyRegistry->historyMembers($historyProjectionInput,'0',$historyKey,$historyNow))
    &&$historyDb->inTransaction(),'history registry refuses and preserves a caller-owned transaction');
$historyDb->rollBack();
$historyProjectedHtml=MaxMemberHistoryView::render($historyProjected);
$check(str_contains($historyProjectedHtml,'Подписчики MAX по сохранённой истории')
    &&str_contains($historyProjectedHtml,'Общее число подписчиков MAX и полнота истории неизвестны')
    &&str_contains($historyProjectedHtml,'Проверка текущего состава через MAX API здесь не выполняется'),
    'history renderer explains retained-state meaning and unknown denominator');
$check(str_contains($historyProjectedHtml,'&lt;script&gt;history_name_attack()&lt;/script&gt;')
    &&!str_contains($historyProjectedHtml,'<script>')&&!str_contains($historyProjectedHtml,'OWNER_MUST_NOT_RENDER')
    &&!str_contains($historyProjectedHtml,'TEST_MUST_NOT_RENDER')&&!str_contains($historyProjectedHtml,'AMBIGUOUS_NAME_MUST_NOT_RENDER'),
    'history names are escaped and excluded or ambiguous labels never reach HTML');
$check(!str_contains($historyProjectedHtml,' ₽')&&!str_contains($historyProjectedHtml,'Экспериментальная сумма')
    &&!str_contains($historyProjectedHtml,'ClientID')&&!str_contains($historyProjectedHtml,'membership:'),
    'restoring the subscriber view does not revive old HL9 credits, weights or CIDs');
$check(str_contains($historyProjectedHtml,'rel="next"')&&str_contains($historyProjectedHtml,'after=hmv1.')
    &&!str_contains($historyProjectedHtml,$historySourceCursor),
    'history links carry only the owner wrapper, not a bare tracker cursor');
$historyEmptyProjection=$historyRegistry->historyMembers($historyEmpty,'0',$historyKey,$historyNow);
$historyEmptyHtml=MaxMemberHistoryView::render($historyEmptyProjection);
$check(str_contains($historyEmptyHtml,'Продолжите просмотр следующего блока')
    &&str_contains($historyEmptyHtml,'rel="next"')&&!str_contains($historyEmptyHtml,'История сейчас недоступна'),
    'empty intermediate history is a successful bounded scan with an actionable continuation');
foreach(['explicit_failure','bad_state','bad_link']as$historyInvalid){
    $bad=$historyProjected;
    if($historyInvalid==='bad_state')$bad['rows'][0]['member']['state']='subscribed';
    if($historyInvalid==='bad_link')$bad['next_after']='<script>';
    $closed=MaxMemberHistoryView::render($bad,$historyInvalid==='explicit_failure');
    $check(str_contains($closed,'История сейчас недоступна')&&!str_contains($closed,'<article')
        &&!str_contains($closed,'history_name_attack'),'failed or malformed history hides every private row: '.$historyInvalid);
}
$check($position("historyMemberPage('98615635',".'$sourceAfter)')<PHP_INT_MAX
    &&str_contains($route,'MaxMemberHistoryViewCursor::unwrap($after,$memberCursorKey,time())')
    &&str_contains($route,'MaxMemberHistoryView::render($page,$unavailable)')
    &&str_contains($route,'->historyMembers(')&&!str_contains($route,'->currentMemberPage('),
    'actual owner route composes the history client, evidence registry and separate renderer');
$check(str_contains($route,"\$memberCursorBinding=session_id().'|max-history-owner-view-v1|'.\$actor;")
    &&str_contains($route,"\$memberCursorKey=hash_hmac('sha256',\$memberCursorBinding,MaxTrackerLookupConfig::privateKey());")
    &&$position("ManagerHttp::requireAdmin(\$manager)")<$position('MaxTrackerLookupConfig::privateKey()')
    &&$position('MaxTrackerLookupConfig::privateKey()')<$position('MaxMemberHistoryViewCursor::unwrap'),
    'route binds cursor MAC to both the owner session and an existing server-only signing secret after authorization');

if($failures){fwrite(STDERR,implode(PHP_EOL,$failures).PHP_EOL);exit(1);}
echo 'modeled conversion value view: '.$checks.' checks passed'.PHP_EOL;
