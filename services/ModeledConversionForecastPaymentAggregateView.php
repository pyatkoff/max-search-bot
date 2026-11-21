<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionForecastPaymentReader.php';

/** Anonymous owner presentation of an admitted aggregate; never renders individual records. */
final class ModeledConversionForecastPaymentAggregateView
{
    private const STAGES=['substantive_dialogue'=>'Содержательный интерес','concrete_request'=>'Конкретный запрос',
        'exact_offer_discussion'=>'Обсуждение предложения','general_purchase_intent'=>'Готовность купить','agency_purchase_intent'=>'Выбор агентства'];
    private const REASONS=['no_earlier_prediction'=>'Нет раннего сопоставимого прогноза','invalid_prior'=>'Ранние данные не прошли проверку',
        'invalid_current'=>'Поздние данные не прошли проверку','access_denied'=>'Поздние данные недоступны',
        'scope_mismatch'=>'Не совпала связь с диалогом','request_anchor_unmatched'=>'Не совпал запрос','edited_request_anchor'=>'Начальный запрос изменён',
        'contrary_signal_after_prediction'=>'Есть отмена, возврат или другое противоречие','edited_outcome_source'=>'Источник исхода был изменён',
        'outcome_not_strictly_after_freeze'=>'Свидетельство не появилось строго после прогноза',
        'outcome_source_not_after_fence'=>'Источник уже входил в ранний срез','outcome_not_available_at_cutoff'=>'Источник ещё не был доступен',
        'outcome_outside_horizon'=>'Свидетельство за пределами 30 дней','non_payment_outcome_only'=>'Есть иные исходы без свидетельства оплаты',
        'horizon_elapsed_coverage_unknown'=>'30 дней истекли; полнота наблюдения неизвестна','no_eligible_payment_evidence'=>'Пригодное позднее свидетельство пока не найдено'];
    private const EDGES=[0.0,0.05,0.10,0.20,0.35,0.50,1.0];

    public static function render(?array $page,array $input=[],bool $unavailable=false):string
    {
        $f=is_array($page['filters']??null)?$page['filters']:$input;
        $date=self::date($f['date']??null)??'';$period=in_array($f['period']??null,[1,7,30,90],true)?$f['period']:1;
        $platform=in_array($f['platform']??null,['all','max','telegram'],true)?$f['platform']:'all';
        $ok=!$unavailable&&self::valid($page);
        $html='<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Сводка ранних прогнозов · AnyTour</title><style>'.self::css().'</style></head><body>'
            .'<header><a class="brand" href="/manager2/admin.php">AnyTour · администрирование</a><span>Закрытый просмотр</span></header><main>'
            .'<h1>Сводка ранних прогнозов</h1><p class="lead">Первые сохранённые оценки по запросам и последующие свидетельства оплаты. Только общие числа, без сведений о туристах.</p>'
            .'<nav aria-label="Разделы аналитики"><a href="/manager/assigned-value.php?view=forecast-payments">Карточки ранних прогнозов</a> · '
            .'<a href="/manager/assigned-value.php?view=delivery">Начисления и мониторинг</a></nav>'
            .'<form method="get" action="/manager/assigned-value.php"><input type="hidden" name="view" value="forecast-summary">'
            .'<label>По день включительно<input type="date" name="date" value="'.self::e($date).'" required></label><label>Период<select name="period">';
        foreach([1=>'1 день',7=>'7 дней',30=>'30 дней',90=>'90 дней']as$key=>$label)$html.='<option value="'.$key.'"'.($period===$key?' selected':'').'>'.$label.'</option>';
        $html.='</select></label><label>Канал<select name="platform">';
        foreach(['all'=>'MAX и Telegram','max'=>'MAX','telegram'=>'Telegram']as$key=>$label)$html.='<option value="'.$key.'"'.($platform===$key?' selected':'').'>'.$label.'</option>';
        $html.='</select></label><button type="submit">Показать сводку</button></form>';
        if(!$ok)return$html.'<section class="notice error" role="status"><h2>Сводка сейчас недоступна</h2><p>Количество прогнозов и поздних свидетельств по этому результату неизвестно.</p>'
            .'<a href="'.self::e(self::url($date,$period,$platform)).'">Обновить срез</a></section></main></body></html>';
        $complete=$page['totals_available'];$s=$complete?$page['totals']:$page['inspected'];$c=$page['coverage'];
        $html.='<p class="asof">Срез: '.self::time($page['as_of']).' · время по Москве (МСК).</p>';
        if(!$complete){
            $html.='<section class="notice partial" id="aggregatePartial" role="status"><h2>Проверена часть выбранного периода. Общие итоги недоступны.</h2>'
                .'<p>'.self::stopReason($c['stopped_reason'],$page['selection_exhausted']).' Ниже показаны только полностью проверенные запросы.</p>';
            if($period!==1)$html.='<a class="button secondary" href="'.self::e(self::url($date,1,$platform)).'">Выбрать один день</a>';
            else $html.='<p>Выберите другой день или один канал, чтобы уменьшить объём проверки.</p>';
            $html.='</section>';
        }
        $html.='<section id="'.($complete?'aggregateTotals':'aggregateInspected').'"><h2>'.($complete?'Итоги выбранной группы':'Просмотренная часть').'</h2>'
            .'<div class="stats"><div class="stat"><span>Первые прогнозы</span><strong>'.$s['first_forecasts'].'</strong></div>'
            .'<div class="stat"><span>Есть позднее свидетельство</span><strong>'.$s['later_evidence_observed'].'</strong></div>'
            .'<div class="stat"><span>Результат неизвестен</span><strong>'.($s['followup_unknown']+$s['followup_waiting']).'</strong></div></div>'
            .'<p class="window-counts">Окно 30 дней открыто: <strong>'.$s['window_open'].'</strong> · Окно истекло: <strong>'.$s['horizon_elapsed'].'</strong></p>';
        if($s['first_forecasts']===0)$html.='<p class="empty">'.($complete?'В выбранной группе нет пригодных сохранённых первых прогнозов.':'В проверенной части пока нет пригодных первых прогнозов. Это не итог всего периода.').'</p>';
        $html.='<h3>Диапазоны ранней оценки</h3><p class="hint">Средняя оценка взята из сохранённых прогнозов. Позднее свидетельство означает сообщение в переписке; доля фактических покупок здесь не рассчитана.</p>'
            .'<p class="hint">Верхняя граница каждого диапазона не включается; последний диапазон включает 100 %.</p>'.self::table($s['probability_bins'],null,true)
            .'<div class="evidence"><h3>Откуда получено свидетельство</h3><p>Турист сообщил об оплате: <strong>'.$s['evidence_cases']['self_reported_payment'].'</strong></p>'
            .'<p>Менеджер подтвердил оплату в переписке: <strong>'.$s['evidence_cases']['manager_confirmed_payment'].'</strong></p>'
            .'<p class="hint">Один запрос может иметь оба вида свидетельства. В общем числе он учитывается один раз.</p></div>'
            .'<details><summary>По каналам и ранним стадиям</summary><h3>Каналы</h3>'.self::table($s['by_platform'],['max'=>'MAX','telegram'=>'Telegram'])
            .'<h3>Ранняя стадия</h3>'.self::table($s['by_stage'],self::STAGES).'</details>';
        if($s['unknown_by_reason'])$html.='<h3>Почему результат неизвестен</h3>'.self::reasons($s['unknown_by_reason'],true);
        $html.='</section><section class="notice coverage"><h2>Что охвачено</h2><p>Проверено сохранённых версий: '.$c['scanned_profiles'].'. '
            .($page['selection_exhausted']?'Поиск по выбранному срезу завершён.':'Поиск по выбранному срезу не завершён.').'</p>'
            .'<p>Группа задана временем наблюдения первого пригодного прогноза. Повторные оценки одного запроса не создают новый запрос. Обращения в разных диалогах и каналах учитываются отдельно; число туристов и поездок здесь не определено.</p>';
        if($c['omitted_by_reason'])$html.='<details><summary>Пропуски и повторные версии</summary>'.self::reasons($c['omitted_by_reason'],false).'</details>';
        if($c['invalid_earlier_profiles_by_reason'])$html.='<details><summary>Ранее сохранённые данные, не прошедшие проверку</summary>'.self::reasons($c['invalid_earlier_profiles_by_reason'],false).'</details>';
        $html.='<p>Полнота наблюдения исходов неизвестна. Отсутствие свидетельства не становится «не купил» после 30 дней. Отметка CRM, бронь, договор и документы сами по себе не подтверждают оплату.</p>'
            .'<p>Оценки пока не откалиброваны. Банковская сверка, фактическая комиссия и точность вероятности покупки в эту сводку не входят.</p></section>'
            .'<footer>Только чтение сохранённых данных. Прогнозы, переписки, исходы и рекламные начисления не меняются.</footer></main></body></html>';
        return$html;
    }

    private static function table(array $groups,?array $labels,bool $bins=false):string
    {
        $html='<div class="table-wrap"><table><thead><tr><th>'.($bins?'Ранняя оценка':'Группа').'</th><th>Запросов</th><th>Средняя оценка</th><th>30 дней истекли</th><th>Есть свидетельство</th><th>Результат неизвестен</th></tr></thead><tbody>';
        foreach($groups as$key=>$m){
            $label=$bins?(self::percent($m['lower']).' — '.self::percent($m['upper'])):($labels[$key]??'Группа');
            $html.='<tr><th scope="row">'.self::e($label).'</th><td data-label="Запросов">'.$m['n'].'</td><td data-label="Средняя оценка">'
                .($m['predicted_probability_mean']===null?'—':self::percent($m['predicted_probability_mean'],1)).'</td><td data-label="30 дней истекли">'.$m['horizon_elapsed'].'</td>'
                .'<td data-label="Есть свидетельство">'.$m['later_evidence_observed'].'</td><td data-label="Результат неизвестен">'.($m['unknown']+$m['waiting']).'</td></tr>';
        }
        return$html.'</tbody></table></div>';
    }
    private static function reasons(array $counts,bool $followup):string
    {
        $html='<ul class="reasons">';
        foreach($counts as$code=>$n){
            $label=$followup?(self::REASONS[$code]??'Недостаточно сопоставимых данных'):match($code){
                'not_global_first'=>'Повторные версии запроса','current_scope_unavailable'=>'Текущий доступ или связь недоступны',
                'earliest_unavailable'=>'Не удалось подтвердить первый прогноз','unusable_anchor'=>'Начало запроса не определено однозначно',
                'packet_size'=>'Сохранённый блок превышает предел чтения','clock_or_fence'=>'Время или граница раннего среза не подтверждены',
                'namespace_or_scope'=>'Принадлежность сохранённых данных не подтверждена','producer_stamp'=>'Происхождение сохранённых данных не подтверждено',
                default=>'Сохранённые данные не прошли проверку'};
            $html.='<li><span>'.self::e($label).'</span><strong>'.$n.'</strong></li>';
        }
        return$html.'</ul>';
    }
    private static function stopReason(?string $reason,bool $exhausted):string
    {
        return match($reason){'scan_limit'=>'Достигнут предел числа сохранённых версий за один просмотр.',
            'byte_limit'=>'Достигнут предел объёма чтения.','time_limit'=>'Достигнут предел времени проверки.',
            'read_limit'=>'Достигнут предел чтения за один просмотр.',
            default=>$exhausted?'Часть ранних данных или связей не удалось подтвердить.':'Проверка выбранного среза не завершена.'};
    }
    private static function valid(?array $p):bool
    {
        $keys=['version','available','reason','filters','as_of','window','unit','cohort_basis','selection_exhausted','cohort_membership_complete',
            'totals_available','totals','inspected','coverage','payment_label','probability_calibrated','verified_payment','evaluation_eligible'];
        if(!self::keys($p,$keys)||$p['version']!==ModeledConversionForecastPaymentReader::AGGREGATE_VERSION||$p['available']!==true||$p['reason']!==null
            ||!self::keys($p['filters'],['date','period','platform'])||self::date($p['filters']['date'])===null
            ||!in_array($p['filters']['period'],[1,7,30,90],true)||!in_array($p['filters']['platform'],['all','max','telegram'],true)
            ||!self::iso($p['as_of'])||!self::keys($p['window'],['from','until','timezone'])||$p['window']['timezone']!=='Europe/Moscow'
            ||!self::iso($p['window']['from'])||!self::iso($p['window']['until'])||strcmp($p['window']['from'],$p['window']['until'])>=0
            ||$p['unit']!=='canonical_scope_and_request_anchor'||$p['cohort_basis']!=='first_admitted_prediction_profile_observed_clock'
            ||!is_bool($p['selection_exhausted'])||!is_bool($p['cohort_membership_complete'])||!is_bool($p['totals_available'])
            ||$p['totals_available']!==$p['cohort_membership_complete']
            ||$p['payment_label']!==null||$p['probability_calibrated']!==false||$p['verified_payment']!==false||$p['evaluation_eligible']!==false
            ||!self::stats($p['inspected']))return false;
        if($p['totals_available']){
            if(!$p['selection_exhausted']||!$p['cohort_membership_complete']||$p['totals']!==$p['inspected'])return false;
        }elseif($p['totals']!==null)return false;
        $c=$p['coverage'];
        return self::keys($c,['scanned_profiles','omitted_by_reason','invalid_earlier_profiles_by_reason','stopped_reason','max_scan_profiles','max_seconds','max_bytes','source_history_complete','trip_identity'])
            &&self::count($c['scanned_profiles'])&&$p['inspected']['first_forecasts']<=$c['scanned_profiles']
            &&self::counts($c['omitted_by_reason'],128)&&self::counts($c['invalid_earlier_profiles_by_reason'],4096)
            &&($c['stopped_reason']===null||is_string($c['stopped_reason'])&&preg_match('/\A[a-z_]{1,64}\z/D',$c['stopped_reason'])===1)
            &&(!$p['totals_available']||$c['stopped_reason']===null&&!array_diff_key($c['omitted_by_reason'],['not_global_first'=>true])&&!$c['invalid_earlier_profiles_by_reason'])
            &&$c['max_scan_profiles']===128&&$c['max_seconds']===15&&$c['max_bytes']===4194304
            &&$c['source_history_complete']===false&&$c['trip_identity']==='unverified';
    }
    private static function stats($s):bool
    {
        if(!self::keys($s,['first_forecasts','window_open','horizon_elapsed','later_evidence_observed','followup_unknown','followup_waiting',
            'evidence_cases','unknown_by_reason','by_platform','by_stage','probability_bins']))return false;
        foreach(['first_forecasts','window_open','horizon_elapsed','later_evidence_observed','followup_unknown','followup_waiting']as$key)if(!self::count($s[$key]))return false;
        $n=$s['first_forecasts'];
        if($s['window_open']+$s['horizon_elapsed']!==$n||$s['later_evidence_observed']+$s['followup_unknown']+$s['followup_waiting']!==$n
            ||!self::keys($s['evidence_cases'],['self_reported_payment','manager_confirmed_payment'])||!self::counts($s['unknown_by_reason'],128)
            ||array_sum($s['unknown_by_reason'])>$s['followup_unknown']+$s['followup_waiting'])return false;
        foreach($s['evidence_cases']as$v)if(!self::count($v)||$v>$s['later_evidence_observed'])return false;
        if(array_sum($s['evidence_cases'])<$s['later_evidence_observed']||!self::keys($s['by_platform'],['max','telegram'])
            ||!self::keys($s['by_stage'],array_keys(self::STAGES))||!is_array($s['probability_bins'])||!array_is_list($s['probability_bins'])||count($s['probability_bins'])!==6)return false;
        foreach(['by_platform','by_stage','probability_bins']as$collection){
            $sums=['n'=>0,'window_open'=>0,'horizon_elapsed'=>0,'later_evidence_observed'=>0,'unknown'=>0,'waiting'=>0];
            foreach($s[$collection]as$i=>$m){
                $bins=$collection==='probability_bins';if(!self::metrics($m,$bins))return false;
                if($bins&&(!self::number($m['lower'])||!self::number($m['upper'])||abs($m['lower']-self::EDGES[$i])>1e-12
                    ||abs($m['upper']-self::EDGES[$i+1])>1e-12||$m['upper_inclusive']!==($i===5)))return false;
                if($bins&&$m['n']>0&&($m['predicted_probability_mean']<$m['lower']-1e-12||$m['predicted_probability_mean']>$m['upper']+1e-12))return false;
                foreach($sums as$key=>$v)$sums[$key]+=$m[$key];
            }
            if($sums!==['n'=>$n,'window_open'=>$s['window_open'],'horizon_elapsed'=>$s['horizon_elapsed'],'later_evidence_observed'=>$s['later_evidence_observed'],
                'unknown'=>$s['followup_unknown'],'waiting'=>$s['followup_waiting']])return false;
        }
        return true;
    }
    private static function metrics($m,bool $bins):bool
    {
        $keys=['n','predicted_probability_mean','window_open','horizon_elapsed','later_evidence_observed','unknown','waiting'];
        if($bins)$keys=[...$keys,'lower','upper','upper_inclusive'];if(!self::keys($m,$keys))return false;
        foreach(['n','window_open','horizon_elapsed','later_evidence_observed','unknown','waiting']as$key)if(!self::count($m[$key]))return false;
        return $m['window_open']+$m['horizon_elapsed']===$m['n']&&$m['later_evidence_observed']+$m['unknown']+$m['waiting']===$m['n']
            &&($m['n']===0?$m['predicted_probability_mean']===null:self::number($m['predicted_probability_mean']));
    }
    private static function keys($v,array $keys):bool{return is_array($v)&&count($v)===count($keys)&&!array_diff($keys,array_keys($v));}
    private static function count($v):bool{return is_int($v)&&$v>=0&&$v<=128;}
    private static function counts($v,int $max):bool
    {if(!is_array($v)||count($v)>64)return false;foreach($v as$key=>$n)if(!is_string($key)||preg_match('/\A[a-z_]{1,64}\z/D',$key)!==1||!is_int($n)||$n<0||$n>$max)return false;return true;}
    private static function number($v):bool{return(is_float($v)||is_int($v))&&is_finite((float)$v)&&$v>=0&&$v<=1;}
    private static function date($v):?string
    {if(!is_string($v)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$v)!==1)return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('Europe/Moscow'));return$d&&$d->format('Y-m-d')===$v?$v:null;}
    private static function iso($v):bool
    {if(!is_string($v)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$v)!==1)return false;$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$v,new DateTimeZone('UTC'));return$d&&$d->format('Y-m-d\TH:i:s\Z')===$v;}
    private static function time(string $v):string{return(new DateTimeImmutable($v))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i:s');}
    private static function percent(float|int $v,int $decimals=0):string{return number_format($v*100,$decimals,',','').' %';}
    private static function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function url(string $date,int $period,string $platform):string
    {return'/manager/assigned-value.php?'.http_build_query(['view'=>'forecast-summary','date'=>$date,'period'=>$period,'platform'=>$platform],'','&',PHP_QUERY_RFC3986);}
    private static function css():string
    {return'body{margin:0;background:#f4f6fa;color:#20312d;font:15px/1.5 system-ui,sans-serif}*{box-sizing:border-box}header,main{max-width:1280px;margin:auto;padding:24px}header{display:flex;justify-content:space-between;gap:16px;border-bottom:1px solid #d7e2dc}a{color:#17604e}.brand{font-size:22px;font-weight:750;text-decoration:none}header span,.asof,.hint,footer{color:#526b5d;font-size:13px}h1{font-size:clamp(26px,4vw,38px);line-height:1.2;margin:12px 0}h2{font-size:23px;margin:0 0 16px}h3{font-size:18px;margin:24px 0 10px}.lead{max-width:850px;color:#526b5d}nav{margin:20px 0;line-height:2}form{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin:24px 0}label{display:flex;flex-direction:column;gap:6px;font-size:13px}input,select,button,.button{font:inherit;padding:10px 14px;border:1px solid #c7d6ce;border-radius:8px;min-height:44px;background:white}button,.button{background:#185e4c;color:white;text-decoration:none;cursor:pointer}.button{display:inline-block}.secondary{background:white;color:#17604e}.notice{background:#edf3f0;border:1px solid #d7e2dc;border-radius:12px;padding:20px;margin:24px 0}.notice p{margin:10px 0}.partial,.error{background:#fff5ec;border-color:#e4bd9a}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.stat{padding:20px;background:white;border:1px solid #d7e2dc;border-radius:12px}.stat span{display:block;color:#526b5d;font-size:13px}.stat strong{display:block;font-size:34px;line-height:1.25;margin-top:10px}.window-counts{margin:18px 0 28px}.table-wrap{margin:14px 0 22px}table{border-collapse:collapse;width:100%;background:white;border:1px solid #d7e2dc}th,td{text-align:left;vertical-align:top;padding:14px 16px;border-bottom:1px solid #dfe7e2;overflow-wrap:anywhere}thead th{background:#edf3ef;font-size:12px}tbody th{font-weight:600;max-width:240px}td{font-variant-numeric:tabular-nums}.evidence{border-left:3px solid #7dba96;padding:0 0 0 18px;margin:28px 0}.evidence h3{margin-top:0}.evidence p{margin:8px 0}details{margin:20px 0}summary{cursor:pointer;color:#17604e;font-weight:600;padding:8px 0}.reasons{list-style:none;padding:0;margin:12px 0;max-width:900px}.reasons li{display:flex;gap:20px;justify-content:space-between;padding:10px 0;border-bottom:1px solid #d7e2dc}.reasons strong{font-variant-numeric:tabular-nums}.empty{padding:18px;background:white;border:1px solid #d7e2dc;border-radius:8px}footer{padding:20px 0;border-top:1px solid #d7e2dc}a:focus-visible,input:focus-visible,select:focus-visible,button:focus-visible,summary:focus-visible{outline:3px solid #c98b2b;outline-offset:3px}@media(max-width:800px){header,main{padding:18px}thead{display:none}table,tbody{display:block}tbody tr{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));border-bottom:3px solid #d7e2dc}tbody th{grid-column:1/-1;max-width:none;background:#edf3ef}tbody th,td{display:block;border:0;padding:12px 16px}td:before{content:attr(data-label);display:block;font-size:11px;color:#526b5d;margin-bottom:4px}.stats{gap:10px}.stat{padding:16px}.stat strong{font-size:30px}}@media(max-width:430px){header{flex-direction:column;gap:6px}header,main{padding:16px}form{display:block}label{margin-bottom:12px}input,select,button{width:100%}.stats{grid-template-columns:1fr}.stat{display:flex;align-items:center;justify-content:space-between;gap:14px}.stat strong{margin:0}.notice{padding:16px}h2{font-size:21px}.reasons li{gap:12px}}';}
}
