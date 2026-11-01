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
$check(str_contains($html,'<strong>'.$member['known_subtotal_rub'].' ₽</strong>'),'renders installed subtotal without another scoring formula');
$check(str_contains($html,'неизвестными')&&str_contains($html,'Неизвестно · вес при подтверждении: 25 ₽'),'unknown retention remains unknown');
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
$firstRead=$position("currentMemberPage('98615635',".'$after)');
$check($position('ManagerHttp::requireAdmin($manager)')<$firstRead
    &&$position("ProjectAccessService::canAccess(".'$actor'.",'anytour')")<$firstRead
    &&$position("ProjectConfig::projectId()!=='anytour'")<$firstRead,'admin and AnyTour project admission precedes source call');
$reopen=$position("session_start(['read_and_close'=>true])");
$check($reopen>$firstRead&&$reopen<PHP_INT_MAX
    &&str_contains(substr($route,$reopen),'ManagerHttp::managerId()!==$actor')
    &&str_contains(substr($route,$reopen),'ManagerHttp::requireAdmin($fresh)')
    &&str_contains(substr($route,$reopen),"ProjectAccessService::canAccess(".'$actor'.",'anytour')"),'session actor and live admin project access rechecked after private read');
$check(str_contains($route,"!=='GET'")&&str_contains($route,"array_diff(array_keys(".'$_GET'."),['view','after'])")
    &&str_contains($route,"['members','profiles']")&&str_contains($route,"{0,19}"),'GET-only strict view and bounded cursor admission');
$check(str_contains($route,'Cache-Control: private, no-store')&&str_contains($route,'X-Content-Type-Options: nosniff')
    &&str_contains($route,'Vary: Cookie')&&str_contains($route,"default-src 'none'")&&str_contains($route,"frame-ancestors 'none'"),'private cache and security headers');
$check(str_contains($route,'$page=null;$unavailable=true;')&&str_contains($route,'http_response_code($unavailable?503:200)'),'source failure has explicit unavailable status');
$check(!str_contains($route,'get_modeled_conversion_status')&&!str_contains($route,'->tick(')
    &&!str_contains($route,'->start(')&&!str_contains($route,'conversion_upload'),'viewer introduces no status tick start or upload operation');

if($failures){fwrite(STDERR,implode(PHP_EOL,$failures).PHP_EOL);exit(1);}
echo 'modeled conversion value view: '.$checks.' checks passed'.PHP_EOL;
