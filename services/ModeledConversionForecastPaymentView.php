<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionForecastPaymentReader.php';

/** Pure closed owner HTML. The reader supplies already-admitted immutable facts. */
final class ModeledConversionForecastPaymentView
{
    private const STAGES=['substantive_dialogue'=>'Содержательный интерес','concrete_request'=>'Конкретный запрос',
        'exact_offer_discussion'=>'Обсуждение предложения','general_purchase_intent'=>'Готовность купить','agency_purchase_intent'=>'Выбор агентства'];
    private const REASONS=['no_earlier_prediction'=>'Нет раннего сопоставимого прогноза','invalid_prior'=>'Ранние данные не прошли проверку',
        'invalid_current'=>'Поздние данные не прошли проверку','access_denied'=>'Поздние данные недоступны',
        'scope_mismatch'=>'Не совпала связь с диалогом','request_anchor_unmatched'=>'Не совпал запрос','edited_request_anchor'=>'Начальный запрос изменён',
        'contrary_signal_after_prediction'=>'Есть отмена, возврат или другое противоречие','edited_outcome_source'=>'Источник исхода был изменён',
        'outcome_not_strictly_after_freeze'=>'Сигнал нельзя считать позднее созданного прогноза',
        'outcome_source_not_after_fence'=>'Источник уже входил в исходный срез','outcome_not_available_at_cutoff'=>'Источник ещё не был доступен',
        'outcome_outside_horizon'=>'Сигнал за пределами окна 30 дней','non_payment_outcome_only'=>'Есть иные исходы, но нет пригодного сообщения об оплате',
        'horizon_elapsed_coverage_unknown'=>'Окно истекло; полнота наблюдения неизвестна','no_eligible_payment_evidence'=>'Пригодное позднее свидетельство пока не найдено',
        'future_conversation_payment_evidence'=>'Позднее свидетельство в переписке'];
    public static function render(?array $page,array $input=[],bool $unavailable=false):string
    {
        $filters=is_array($page['filters']??null)?$page['filters']:$input;$date=self::date($filters['date']??null)??'';
        $period=in_array($filters['period']??null,[7,30,90],true)?$filters['period']:30;
        $platform=in_array($filters['platform']??null,['all','max','telegram'],true)?$filters['platform']:'all';
        $ok=!$unavailable&&self::valid($page);
        $html='<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Ранний прогноз → свидетельства оплаты · AnyTour</title><style>'.self::css().'</style></head><body>'
            .'<header><a class="brand" href="/manager2/admin.php">AnyTour · администрирование</a><span>Закрытый просмотр</span></header><main>'
            .'<h1>Ранний прогноз → свидетельства оплаты</h1><p class="lead">Первая сохранённая оценка по запросу и последующие сведения об оплате.</p>'
            .'<nav><a href="/manager/assigned-value.php?view=delivery">Начисления и мониторинг</a> · '
            .'<a href="/manager/assigned-value.php?view=acquisition">От вступления до обращения</a></nav>'
            .'<form method="get" action="/manager/assigned-value.php"><input type="hidden" name="view" value="forecast-payments">'
            .'<label>По день включительно<input type="date" name="date" value="'.self::e($date).'" required></label><label>Период<select name="period">';
        foreach([7=>'7 дней',30=>'30 дней',90=>'90 дней']as$key=>$label)$html.='<option value="'.$key.'"'.($period===$key?' selected':'').'>'.$label.'</option>';
        $html.='</select></label><label>Канал<select name="platform">';
        foreach(['all'=>'MAX и Telegram','max'=>'MAX','telegram'=>'Telegram']as$key=>$label)$html.='<option value="'.$key.'"'.($platform===$key?' selected':'').'>'.$label.'</option>';
        $html.='</select></label><button type="submit">Показать</button></form>'
            .'<section class="notice"><p>Оценка v1 — некалиброванная гипотеза. Сообщение туриста или менеджера об оплате не является банковской сверкой. Запросы разных диалогов пока учитываются отдельно.</p>'
            .'<p>Отсутствие свидетельства означает «неизвестно». Оно не становится «не купил» после 30 дней. Отметка CRM, договор, платёжная ссылка и выдача документов сами по себе не подтверждают оплату.</p></section>';
        if(!$ok)return$html.'<section class="error" role="status"><h2>Сопоставление сейчас недоступно</h2><p>По этому результату нельзя судить о количестве прогнозов или оплат.</p><a href="'.self::e(self::url($date,$period,$platform)).'">Обновить срез</a></section></main></body></html>';
        $html.='<p class="asof">Срез: '.self::time($page['as_of']).' · время по Калининграду.</p>'
            .'<div class="table-wrap"><table><thead><tr><th>Диалог и запрос</th><th>Ранний прогноз</th><th>Позднее свидетельство</th><th>Окно и полнота</th></tr></thead><tbody>';
        foreach($page['rows']as$row)$html.=self::row($row);
        if(!$page['rows'])$html.='<tr><td colspan="4" class="empty">В просмотренной части нет подходящих ранних прогнозов.'
            .($page['has_more']?' Откройте следующую часть.':' Конец доступного среза.').'</td></tr>';
        $c=$page['coverage'];$html.='</tbody></table></div><section class="coverage"><h2>Что охвачено</h2>'
            .'<p>Проверено сохранённых версий прогноза в этой части: '.(int)$c['scanned_profiles'].'. Строк в таблице: '.count($page['rows']).'.</p>'
            .'<p>Это ограниченный проход по сохранённым прогнозам, а не численность всех туристов, поездок или оплат. Период задан по времени профильного наблюдения первого допущенного прогноза; исходы не определяют попадание в группу.</p>'
            .'<p>Повторные оценки одного запроса не создают новую строку. Разделение разных поездок ещё уточняется; обращения в разных каналах учитываются отдельно.</p>';
        if($c['omitted']){$html.='<details><summary>Пропуски и повторные версии</summary><ul>';foreach($c['omitted']as$key=>$n)$html.='<li>'.self::omission($key).': '.(int)$n.'</li>';$html.='</ul></details>';}
        $html.='<p>Полнота истории неизвестна. Подтверждённая вероятность покупки, точность прогноза и отрицательные платёжные исходы здесь не рассчитаны.</p></section><div class="pagination">';
        if($page['has_more'])$html.='<a class="button" rel="next" href="'.self::e(self::url($date,$period,$platform,$page['next_cursor'])).'">Следующая часть →</a>';
        $html.='<a href="'.self::e(self::url($date,$period,$platform)).'">Начать новый срез</a></div>'
            .'<footer>Только чтение. Просмотр не меняет переписки, исходы, рекламные начисления или сохранённые прогнозы.</footer></main></body></html>';
        return$html;
    }
    private static function row(array $r):string
    {
        $f=$r['forecast'];$u=$r['followup'];$observed=$u['status']==='observed';$platform=$r['platform']==='max'?'MAX':'Telegram';
        $html='<tr><td data-label="Диалог и запрос"><strong>'.self::e($r['customer_name']).'</strong><p><a href="/manager2/#conversation='.self::e($r['conversation_id']).'">'.$platform.' · диалог №'.self::e($r['conversation_id']).'</a></p>'
            .'<small>Оценка относится к отдельному запросу в этом диалоге.</small></td>'
            .'<td data-label="Ранний прогноз"><strong>'.self::e(number_format((float)$f['estimated_probability']*100,1,',','')).'% · оценка v1</strong>'
            .'<p>'.self::e(self::STAGES[$f['stage']]??'Сохранённая оценка').'</p><p>Создан: '.self::time($f['frozen_at']).'</p>'
            .'<details><summary>Сохранённое основание</summary><dl><dt>Профиль</dt><dd>№'.self::e($r['profile_id']).'</dd>'
            .'<dt>Доступный ввод до</dt><dd>'.self::time($f['input_cutoff_at']).'</dd><dt>Наблюдение профиля</dt><dd>'.self::time($f['profile_observed_at']).'</dd>'
            .'</dl>';
        if($f['invalid_earlier_profiles'])$html.='<p>Более ранние кандидаты не прошли проверку: '.array_sum($f['invalid_earlier_profiles']).'. Выбран первый допущенный прогноз; полнота ранней истории ограничена.</p>';
        $html.='</details></td><td data-label="Позднее свидетельство"><strong class="'.($observed?'observed':'unknown').'">'
            .($observed?'Есть позднее сообщение об оплате':'Свидетельство оплаты не установлено').'</strong><p>'
            .self::e(self::REASONS[$u['reason']]??'Недостаточно сопоставимых данных').'</p>';
        foreach($u['evidence']as$e)$html.='<div class="evidence"><p>'.($e['label']==='self_reported_payment'?'Турист сообщил об оплате':'Менеджер подтвердил оплату в переписке')
            .'</p><small>Событие: '.self::time($e['occurred_at']).'<br>Доступно: '.self::time($e['available_at'])
            .'</small></div>';
        $html.='</td><td data-label="Окно и полнота"><p>30 дней до '.self::time($r['maturity']['horizon_end_at']).'</p>'
            .'<p>'.($r['maturity']['state']==='window_open'?'Окно наблюдения открыто.':'Окно истекло; полнота наблюдения неизвестна.').'</p>'
            .'<small>Поздний сопоставимый срез: '.self::time($r['later']['frozen_at']).'.</small>';
        if($r['observations']){$html.='<details><summary>Иные сохранённые наблюдения</summary><ul>';foreach($r['observations']as$e)$html.='<li>'.self::observation($e['kind']).' · '.self::time($e['available_at']).'</li>';$html.='</ul><p>Эти наблюдения не заменяют результат проверки свидетельства.</p></details>';}
        return$html.'</td></tr>';
    }
    private static function valid(?array $p):bool
    {
        if(!is_array($p)||($p['version']??null)!==ModeledConversionForecastPaymentReader::VERSION||($p['available']??null)!==true
            ||!is_array($p['rows']??null)||!array_is_list($p['rows'])||count($p['rows'])>8||!is_bool($p['has_more']??null)
            ||!is_array($p['coverage']??null)||!is_array($p['coverage']['omitted']??null)||!is_int($p['coverage']['scanned_profiles']??null)
            ||$p['coverage']['scanned_profiles']<0||$p['coverage']['scanned_profiles']>128||!self::iso($p['as_of']??null))return false;
        if($p['has_more']&&(!is_string($p['next_cursor']??null)||strlen($p['next_cursor'])>2048||preg_match('/\Afpr1\.[A-Za-z0-9_-]+\.[a-f0-9]{64}\z/D',$p['next_cursor'])!==1)
            ||!$p['has_more']&&($p['next_cursor']??null)!==null)return false;
        foreach($p['coverage']['omitted']as$key=>$n)if(!is_string($key)||!is_int($n)||$n<0||$n>128)return false;
        foreach($p['rows']as$r){
            if(!is_array($r)||!self::id($r['profile_id']??null)||!self::id($r['customer_key']??null)||!self::id($r['conversation_id']??null)
                ||!is_string($r['customer_name']??null)||strlen($r['customer_name'])>800||!in_array($r['platform']??null,['max','telegram'],true)||!self::ref($r['request_anchor']??null)
                ||($r['trip_status']??null)!=='unverified'||($r['evaluation_eligible']??null)!==false||($r['verified_payment']??null)!==false)return false;
            $f=$r['forecast']??null;$u=$r['followup']??null;
            if(!is_array($f)||!is_array($u)||!is_numeric($f['estimated_probability']??null)||!is_finite((float)$f['estimated_probability'])
                ||$f['estimated_probability']<0||$f['estimated_probability']>1||!is_array($f['invalid_earlier_profiles']??null)
                ||!array_key_exists($f['stage']??'',self::STAGES)||!self::iso($f['frozen_at']??null)||!self::iso($f['input_cutoff_at']??null)
                ||!self::iso($f['profile_observed_at']??null)||!self::sha($f['prediction_digest']??null)||!self::sha($f['model_sha256']??null)||!self::sha($f['producer_sha256']??null))return false;
            if(!in_array($u['status']??null,['waiting','unknown','observed'],true)||!is_string($u['reason']??null)||!is_array($u['evidence']??null)||count($u['evidence'])>8
                ||($u['payment_label']??null)!==null||($u['independent_validation']??null)!==false||($u['evaluation_eligible']??null)!==false)return false;
            if($u['status']==='observed'&&!$u['evidence'])return false;
            foreach($u['evidence']as$e)if(!is_array($e)||!in_array($e['label']??null,['self_reported_payment','manager_confirmed_payment'],true)
                ||!self::ref($e['source_ref']??null)||!self::iso($e['occurred_at']??null)||!self::iso($e['available_at']??null)||($e['source_version_no']??null)!==1)return false;
            if(!is_array($r['maturity']??null)||!in_array($r['maturity']['state']??null,['window_open','elapsed_coverage_unknown'],true)
                ||!self::iso($r['maturity']['horizon_end_at']??null)||!is_array($r['later']??null)||!is_array($r['observations']??null)||count($r['observations'])>8)return false;
            foreach($r['observations']as$e)if(!is_array($e)||!is_string($e['kind']??null)||!self::ref($e['source_ref']??null)||!self::iso($e['available_at']??null)
                ||!is_int($e['source_version_no']??null)||$e['source_version_no']<1)return false;
        }return true;
    }
    private static function omission(string $code):string{return match($code){'not_global_first'=>'Повторные версии запроса','current_scope_unavailable'=>'Текущий доступ или связь недоступны','earliest_unavailable'=>'Не удалось подтвердить первый прогноз','unusable_anchor'=>'Начало запроса не удалось определить однозначно',default=>'Данные не прошли проверку'};}
    private static function observation(string $code):string{return match($code){'payment_self_report'=>'Сообщение туриста об оплате','manager_payment_ack'=>'Ответ менеджера об оплате','booking_confirmation'=>'Бронирование','purchase_supported_candidate'=>'Косвенный признак покупки','payment_cancellation'=>'Отмена оплаты','booking_cancellation'=>'Отмена брони','refund_report'=>'Сообщение о возврате','purchased_elsewhere'=>'Покупка в другом месте','document_issuance'=>'Выдача документов','known_operational_outcome'=>'Операционная отметка CRM',default=>'Наблюдение с неопределённым смыслом'};}
    private static function url(string $date,int $period,string $platform,?string $cursor=null):string{$q=['view'=>'forecast-payments','date'=>$date,'period'=>$period,'platform'=>$platform];if($cursor!==null)$q['cursor']=$cursor;return'/manager/assigned-value.php?'.http_build_query($q,'','&',PHP_QUERY_RFC3986);}
    private static function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function id($v):bool{return is_string($v)&&preg_match('/\A[1-9][0-9]{0,19}\z/D',$v)===1;}
    private static function sha($v):bool{return is_string($v)&&preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;}
    private static function ref($v):bool{return is_string($v)&&preg_match('/\Ajournal:[1-9][0-9]{0,19}\z/D',$v)===1;}
    private static function date($v):?string{return is_string($v)&&preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$v)===1?$v:null;}
    private static function iso($v):bool{return is_string($v)&&preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$v)===1&&strtotime($v)!==false;}
    private static function time($v):string{return self::iso($v)?(new DateTimeImmutable('@'.strtotime($v)))->setTimezone(new DateTimeZone('Europe/Kaliningrad'))->format('d.m.Y H:i:s'):'неизвестно';}
    private static function css():string{return'body{margin:0;background:#f4f6fa;color:#20312d;font:15px/1.5 system-ui,sans-serif}*{box-sizing:border-box}header,main{max-width:1440px;margin:auto;padding:24px}header{display:flex;justify-content:space-between;border-bottom:1px solid #d7e2dc;gap:16px}a{color:#17604e}.brand{font-size:22px;font-weight:750;text-decoration:none}header span,.asof,small,footer{color:#63776b;font-size:12px}h1{font-size:clamp(26px,4vw,38px);line-height:1.2;margin:12px 0}.lead{max-width:900px;color:#526b5d}nav{margin:20px 0}form{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin:24px 0}label{display:flex;flex-direction:column;gap:6px;font-size:13px}input,select,button,.button{font:inherit;padding:10px 14px;border:1px solid #c7d6ce;border-radius:8px;min-height:44px;background:white}button,.button{background:#185e4c;color:white;text-decoration:none;cursor:pointer}.notice,.coverage,.error{background:#fff;border:1px solid #d7e2dc;border-radius:12px;padding:18px;margin:20px 0}.notice p,.coverage p{margin:8px 0}.notice{background:#edf3f0}.error{background:#fff5ec;border-color:#e4bd9a}table{border-collapse:collapse;width:100%;background:white;table-layout:fixed;border:1px solid #d7e2dc}th,td{padding:16px;text-align:left;vertical-align:top;border-bottom:1px solid #dfe7e2;overflow-wrap:anywhere}th{background:#edf3ef;font-size:13px}td p{margin:8px 0}td small{display:block}.observed{color:#226b43}.unknown{color:#765c2b}details{margin-top:12px;font-size:12px}summary{cursor:pointer;color:#3e6552}dl{margin:10px 0}dt{font-weight:650}dd{margin:3px 0 10px}.digest{font:11px/1.45 ui-monospace,monospace;word-break:break-all}.evidence{border-left:3px solid #7dba96;padding-left:10px;margin-top:12px}.pagination{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin:24px 0}footer{padding-top:20px;border-top:1px solid #d7e2dc}.empty{padding:25px}a:focus-visible,input:focus-visible,select:focus-visible,button:focus-visible,summary:focus-visible{outline:3px solid #c98b2b;outline-offset:3px}@media(max-width:900px){thead{display:none}table,tbody,tr,td{display:block}tr{border-bottom:3px solid #d7e2dc}td{border:0;padding:15px 18px}td:before{content:attr(data-label);display:block;color:#60766a;font-size:11px;margin-bottom:7px}td:first-child{background:#edf3ef}header,main{padding:18px}}@media(max-width:430px){header{flex-direction:column;gap:6px}form{display:block}label{margin-bottom:12px}input,select,button{width:100%}header,main{padding:16px}.pagination .button{width:100%;text-align:center}}';}
}
