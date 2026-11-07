<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/visual/fixtures/modeled-acquisition-stats.php';
$checks=0;$failures=[];
$check=static function(bool $ok,string $name)use(&$checks,&$failures):void{$checks++;if(!$ok)$failures[]=$name;};
[$page,$input]=modeledAcquisitionViewFixture();
$html=ModeledConversionAcquisitionStatsView::render($page,$input);
$card=static function(string $html,string $id):string{
    if(preg_match('/<article\b[^>]*\bid="'.preg_quote($id,'/').'"[^>]*>.*?<\/article>/s',$html,$m)!==1)throw new RuntimeException('missing_acquisition_card');
    return $m[0];
};
$max=$card($html,'channel-max');$tg=$card($html,'channel-telegram');
$check(str_contains($html,'От вступления до обращения')&&str_contains($html,'Закрытый просмотр'),'closed purpose and title');
$check(str_contains($html,'Первое зафиксированное содержательное обращение в доступной истории'),'first observed is not first ever');
$check(str_contains($max,'>12</strong>')&&str_contains($tg,'>9</strong>'),'real inquiry counts separated by platform');
$check(str_contains($max,'5 из 12')&&str_contains($max,'41,7%'),'coverage uses all observed inquiries denominator');
$check(str_contains($max,'1 дн. 1 ч.')&&str_contains($max,'34 дн. 17 ч.'),'valid pairs duration percentiles shown');
$check(str_contains($max,'Только обращения с подходящими датами: 5'),'latency denominator explicit');
$check(str_contains($max,'До часа')&&str_contains($max,'От 7 до 30 дней')&&str_contains($max,'Больше 30 дней'),'all time bins rendered');
$check(str_contains($max,'Дата вступления неизвестна')&&str_contains($max,'Обращение раньше известного вступления')
    &&str_contains($max,'Несколько подходящих вступлений')&&str_contains($max,'Неоднозначная связь человека и канала'),'unpaired reasons visible');
$check(str_contains($tg,'Даты вступления подключим из Bitrix')&&str_contains($tg,'Время до обращения пока неизвестно'),'Telegram source gap explicit');
$check(!str_contains($tg,'Медиана')&&!str_contains($tg,'90-й процентиль')&&!str_contains($tg,'0%')
    &&!str_contains($tg,'0 из 9'),'missing Telegram dates are not zero latency or coverage');
$check(str_contains($html,'не полный архив и не все подписчики')&&str_contains($html,'Доля всех подписчиков, которые обратились, пока не рассчитана'),'incomplete source does not claim subscriber conversion');
$check(str_contains($html,'Отсутствие выхода не доказывает непрерывную подписку'),'unknown leave is not continuous membership');
$check(str_contains($html,'04.11.2026 13:20:30')&&str_contains($html,'Все даты и время — Москва (МСК).'),'as-of clock shown in Moscow');
$rollover=$page;$rollover['as_of']='2026-12-31T21:00:00Z';
$rollover['sources']['first_profile_at']='2026-12-31T20:59:59Z';$rollover['sources']['last_profile_at']='2026-12-31T21:00:01Z';
$savedRollover=$rollover;$rolloverHtml=ModeledConversionAcquisitionStatsView::render($rollover,$input);
$check(str_contains($rolloverHtml,'31.12.2026 23:59:59')&&str_contains($rolloverHtml,'01.01.2027 00:00:00')
    &&str_contains($rolloverHtml,'01.01.2027 00:00:01')&&$rollover===$savedRollover,'Moscow New Year presentation preserves UTC source instants');
$check(str_contains($html,'method="get"')&&str_contains($html,'name="period"')&&str_contains($html,'value="30" selected')
    &&str_contains($html,'name="date"')&&str_contains($html,'name="platform"'),'bounded read-only filters');
$check(!str_contains($html,'<script')&&!str_contains($html,'<img')&&!str_contains($html,'YCLID'),'no scripts or raw attribution data');
$check(str_contains($max,'Связь пользователя MAX с ClientID')&&str_contains($max,'сама по себе не подтверждает предшествующий визит')
    &&!str_contains($max,'class="entry-counts"'),'MAX identity mapping is not presented as preceding website entry');
$check(str_contains($tg,'подтверждённому Telegram-пользователю и ClientID')
    &&str_contains($tg,'не подтверждение визита на внешний сайт или рекламного клика'),'Telegram entry observation has its bounded identity basis');
$check(str_contains($tg,'<dt>Вход не позже обращения</dt><dd>4</dd>')
    &&str_contains($tg,'<dt>Неоднозначная связь</dt><dd>2</dd>')
    &&str_contains($tg,'<dt>Только более поздний вход</dt><dd>1</dd>')
    &&str_contains($tg,'<dt>Нет подходящего наблюдения</dt><dd>2</dd>'),'four entry categories show the same observed inquiry cohort');
$check(str_contains($tg,'включает ту же секунду: порядок событий внутри секунды неизвестен')
    &&str_contains($tg,'Один подходящий ClientID не означает один визит'),'second precision and ClientID uniqueness do not invent sequence or unique visit');
$check(str_contains($tg,'конверсия из визитов не рассчитывается')
    &&str_contains($tg,'не значит, что человек бот или визита не было'),'missing observation is not a bot label or conversion denominator');
[$empty,$ei]=modeledAcquisitionViewFixture('empty');$eh=ModeledConversionAcquisitionStatsView::render($empty,$ei);
$check(!str_contains($eh,'Статистика сейчас недоступна')&&str_contains($eh,'В доступных данных за выбранный период таких обращений нет.'),'empty successful read distinct from failure');
$check(!str_contains($eh,'class="timing"')&&!str_contains($eh,'0,0%'),'empty cohort has no fabricated percentiles or ratio');
$check(str_contains($eh,'<dt>Вход не позже обращения</dt><dd>0</dd>')
    &&str_contains($eh,'<dt>Нет подходящего наблюдения</dt><dd>0</dd>'),'successful empty entry cohort shows zero counts without a conversion ratio');
[$unknown,$ui]=modeledAcquisitionViewFixture('unknown');$uh=ModeledConversionAcquisitionStatsView::render($unknown,$ui);
$check(str_contains($uh,'0 из 12')&&str_contains($uh,'Нет подходящих пар дат')&&!str_contains($uh,'<dd>0'),'MAX unknown joins retain denominator without zero duration');
[$failed,$fi]=modeledAcquisitionViewFixture('error');$fh=ModeledConversionAcquisitionStatsView::render($failed,$fi);
$check(str_contains($fh,'Статистика сейчас недоступна')&&!str_contains($fh,'id="channel-max"')
    &&!str_contains($fh,'5 из 12'),'failed read cannot expose cached stats');
$forced=ModeledConversionAcquisitionStatsView::render($page,$input,true);
$check(str_contains($forced,'Статистика сейчас недоступна')&&!str_contains($forced,'id="channel-max"'),'explicit failure overrides available page');
$bad=$page;$bad['platforms']['max']['inquiries']=13;
$check(str_contains(ModeledConversionAcquisitionStatsView::render($bad,$input),'Статистика сейчас недоступна'),'unreconciled cohort cannot imply coverage');
$bad=$page;$bad['platforms']['max']['bins']['under_1h']=2;
$check(str_contains(ModeledConversionAcquisitionStatsView::render($bad,$input),'Статистика сейчас недоступна'),'unreconciled duration bins rejected');
$bad=$page;$bad['platforms']['max']['median_seconds']=null;
$check(str_contains(ModeledConversionAcquisitionStatsView::render($bad,$input),'Статистика сейчас недоступна'),'missing valid-pair percentile is not zero');
$bad=$page;$bad['platforms']['telegram']=$page['platforms']['max'];$bad['platforms']['telegram']['membership_status']='unavailable_pending_bitrix';
$check(str_contains(ModeledConversionAcquisitionStatsView::render($bad,$input),'Статистика сейчас недоступна'),'Telegram cannot invent membership timings');
$bad=$page;$bad['meaning']='first_ever';
$check(str_contains(ModeledConversionAcquisitionStatsView::render($bad,$input),'Статистика сейчас недоступна'),'unsupported first-ever claim rejected');
$bad=$page;$bad['window']['timezone']='Europe/Kaliningrad';
$check(str_contains(ModeledConversionAcquisitionStatsView::render($bad,$input),'Статистика сейчас недоступна'),'old calendar cohort cannot be presented as Moscow');
$invalidEntries=[];
$bad=$page;unset($bad['platforms']['telegram']['entry_context']);$invalidEntries['missing entry context']=$bad;
$bad=$page;$bad['platforms']['telegram']['entry_context']['counts']['prior_observed']=5;$invalidEntries['entry counts do not reconcile to inquiries']=$bad;
$bad=$page;$bad['platforms']['telegram']['entry_context']['counts']['late_only']='1';$invalidEntries['entry count cannot be a numeric string']=$bad;
$bad=$page;$bad['platforms']['telegram']['entry_context']['counts']['late_only']=-1;$invalidEntries['entry count cannot be negative']=$bad;
$bad=$page;$bad['platforms']['telegram']['entry_context']['counts']['late_only']=1.0;$invalidEntries['entry count cannot be floating point']=$bad;
$bad=$page;$bad['platforms']['telegram']['entry_context']['counts']['no_data']=null;$invalidEntries['entry count cannot be null']=$bad;
$bad=$page;unset($bad['platforms']['telegram']['entry_context']['counts']['ambiguous']);$invalidEntries['entry categories must be complete']=$bad;
$bad=$page;$bad['platforms']['telegram']['entry_context']['status']='not_supported';$invalidEntries['Telegram entry status must match contract']=$bad;
$bad=$page;$bad['platforms']['telegram']['entry_context']['basis']='max_identity_mapping_not_visit';$invalidEntries['Telegram entry basis cannot claim another source']=$bad;
$bad=$page;$bad['platforms']['max']['entry_context']['counts']=[];$invalidEntries['unsupported MAX counts must be null']=$bad;
$bad=$page;$bad['platforms']['max']['entry_context']['status']='available';$invalidEntries['MAX cannot invent supported visit evidence']=$bad;
$bad=$page;$bad['platforms']['max']['entry_context']['basis']='verified_tgmini_identity_observation';$invalidEntries['MAX entry basis cannot claim Telegram identity']=$bad;
$privateId='99887766554433221100';
$bad=$page;$bad['platforms']['telegram']['entry_context']['client_id']=$privateId;$invalidEntries['extra private entry fields are rejected']=$bad;
$bad=$page;$bad['platforms']['telegram']['entry_context']['counts']['external_user_id']=$privateId;$invalidEntries['extra private count fields are rejected']=$bad;
foreach($invalidEntries as$name=>$bad){
    $rejected=ModeledConversionAcquisitionStatsView::render($bad,$input);
    $check(str_contains($rejected,'Статистика сейчас недоступна')&&!str_contains($rejected,'id="channel-')
        &&!str_contains($rejected,$privateId),$name);
}
$reordered=$page;
$reordered['platforms']['telegram']['entry_context']=array_reverse($reordered['platforms']['telegram']['entry_context'],true);
$reordered['platforms']['telegram']['entry_context']['counts']=array_reverse($reordered['platforms']['telegram']['entry_context']['counts'],true);
$check(!str_contains(ModeledConversionAcquisitionStatsView::render($reordered,$input),'Статистика сейчас недоступна'),'entry shape validation does not depend on object key order');
$private=$page;$private['client_id']=$privateId;$private['platforms']['telegram']['external_user_id']=$privateId;
$private['sources']['visit_id']=$privateId;
$privateHtml=ModeledConversionAcquisitionStatsView::render($private,$input);
$check(!str_contains($privateHtml,$privateId)&&!str_contains($privateHtml,'Статистика сейчас недоступна'),'unexpected private fields outside entry contract are never rendered');
$bad=$page;$bad['filters']['date']='<img src=x onerror=alert(1)>';$bad['as_of']='<svg onload=alert(1)>';
$bad['sources']['profiles_examined']='<script>secret</script>';$bad['customer_name']='private customer';
$safe=ModeledConversionAcquisitionStatsView::render($bad,$input);
$check(!str_contains($safe,'<img')&&!str_contains($safe,'<svg')&&!str_contains($safe,'<script')
    &&!str_contains($safe,'private customer'),'malformed labels and extra private fields not emitted');
$single=$page;$single['filters']['platform']='telegram';$sh=ModeledConversionAcquisitionStatsView::render($single,$input);
$check(!str_contains($sh,'id="channel-max"')&&str_contains($sh,'id="channel-telegram"'),'platform selection limits rendered cards');
$zero=$empty;$zero['platforms']['max']['inquiries']=1;$zero['platforms']['max']['valid_pairs']=1;
$zero['platforms']['max']['median_seconds']=0;$zero['platforms']['max']['p90_seconds']=0;$zero['platforms']['max']['bins']['under_1h']=1;
$zh=ModeledConversionAcquisitionStatsView::render($zero,$input);
$check(str_contains($zh,'меньше минуты'),'actual zero-second paired interval remains legitimate');
$route=(string)file_get_contents(dirname(__DIR__).'/manager/assigned-value.php');
$pos=static fn(string $needle):int=>($p=strpos($route,$needle))===false?PHP_INT_MAX:$p;
$check($pos('ProjectAccessService::initializeReadOnly()')<$pos('ManagerHttp::requireManager()')
    &&$pos('ManagerHttp::requireAdmin($manager)')<$pos('new ModeledConversionAcquisitionStatsReader('),'read-only initialization and administrator auth precede acquisition read');
$check(str_contains($route,"'acquisition'=>['view','date','period','platform']")
    &&str_contains($route,"in_array(".'$acquisitionInput'."['period'],['7','30','90'],true)"),'acquisition has isolated parameter allowlist and periods');
$after=substr($route,$pos("session_start(['read_and_close'=>true])"));
$check(str_contains($after,'ManagerHttp::managerId()!==$actor')&&str_contains($after,'ManagerHttp::requireAdmin($fresh)')
    &&str_contains($after,"ProjectAccessService::canAccess(".'$actor'.",'anytour')"),'fresh current actor and project rechecked after private read');
$check(str_contains($route,'Cache-Control: private, no-store')&&str_contains($route,"form-action 'self'")
    &&!str_contains($route,'->tick(')&&!str_contains($route,"action='detail'"),'private read-only response preserved');
foreach(['ModeledConversionDeliveryRegistryView.php','ModeledConversionValueRegistryView.php']as$file)
    $check(str_contains((string)file_get_contents(dirname(__DIR__).'/services/'.$file),'view=acquisition'),'existing private view links to acquisition '.$file);
echo json_encode(['ok'=>!$failures,'checks'=>$checks,'failures'=>$failures],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
exit($failures?1:0);
