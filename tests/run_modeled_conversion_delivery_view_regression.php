<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/visual/fixtures/modeled-delivery-registry.php';
$checks=0;$failures=[];
$check=static function(bool $ok,string $name)use(&$checks,&$failures):void{$checks++;if(!$ok)$failures[]=$name;};
[$page,$input,$monitor]=modeledDeliveryViewFixture();
$html=ModeledConversionDeliveryRegistryView::render($page,$input,$monitor);
$check(str_contains($html,'Конверсии')&&str_contains($html,'Закрытый просмотр'),'private owner title');
$check(str_contains($html,'13 333 ₽')&&str_contains($html,'2 начислений · 2 диалогов'),'whole selected-day totals');
$check(str_contains($html,'833 ₽')&&str_contains($html,'2 083 ₽'),'period increase and lifetime total remain different');
$check(str_contains($html,'Новый поток:')&&str_contains($html,'Исторический разбор:'),'live and historical origins are distinct');
$check(str_contains($html,'по дате')||str_contains($html,'По дате начисления'),'assignment-day basis is explicit');
$check(str_contains($html,'По дате начисления')&&str_contains($html,'суммы не складываются'),'overlapping acceptance cohorts are explained');
$check(str_contains($html,'method="get"')&&str_contains($html,'name="platform"')&&str_contains($html,'name="date"'),'filters perform GET only');
$check(str_contains($html,'rel="next"')&&str_contains($html,'cursor=v1.synthetic-fixture.abcdef'),'bounded next-page cursor link');
$check(str_contains($html,'профиле №901')&&str_contains($html,'Конкретный запрос'),'award tied to recorded stage profile');
$check(str_contains($html,'03.11.2026 14:00:00'),'UTC times rendered in Kaliningrad timezone');
$check(str_contains($html,'точное время подтверждения')&&str_contains($html,'Не записано'),'legacy receipt clock is not fabricated');
$check(str_contains($html,'найдено несколько')||str_contains($html,'Найдено несколько'),'held identity has a human reason');
$check(str_contains($html,'Регулярность подтверждена')&&str_contains($html,'По расписанию'),'three confirmed scheduled checks displayed');
$check(str_contains($html,'03.11.2026 12:17:00'),'quiet check retains last notification display');
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

$uncertain=$monitor;$uncertain['notification']='unknown';
$uncertainHtml=ModeledConversionDeliveryRegistryView::render($page,$input,$uncertain);
$check(!str_contains($uncertainHtml,'Проверка успешна')&&str_contains($uncertainHtml,'Отправка уведомления не подтверждена'),'uncertain personal delivery cannot have a green healthy badge');
foreach(['admin'=>200,'manager'=>403,'inactive'=>401,'no_project'=>403,'foreign'=>404,
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
    if($case==='admin')$check(str_contains($body,'Синтетический турист: проверка доступа')&&str_contains($body,'1 250 ₽')
        &&!str_contains($body,'Реестр сейчас недоступен'),'actual admin route composes reader, monitor and renderer');
    else $check(!str_contains($body,'Синтетический турист: проверка доступа')&&!str_contains($body,'1 250 ₽'),'denied endpoint returns no private rows: '.$case);
}

if($failures){fwrite(STDERR,implode(PHP_EOL,$failures).PHP_EOL);exit(1);}
echo 'modeled delivery view: '.$checks.' checks passed'.PHP_EOL;
