<?php
declare(strict_types=1);

/** Pure owner HTML over a validated aggregate. No storage, API, money assignment or sends. */
final class ModeledConversionDailyReconciliationView
{
    private const STATES=['pending'=>'Ожидает','ready'=>'Готово к отправке','uploading'=>'Загрузка проверяется',
        'accepted'=>'Принято API, ожидает обработки','processed'=>'Обработка завершена','unknown'=>'Результат неизвестен',
        'failed'=>'Ошибка','expired'=>'Срок истёк','unmatched'=>'Нет совпадения'];
    private const ROWS=['accruals'=>'Начислено','upload_claims'=>'Начата попытка загрузки',
        'api_receipts'=>'Приём API подтверждён','processed'=>'Обработка подтверждена'];
    public static function render(?array $page,array $input=[],bool $unavailable=false):string
    {
        $date=is_string($page['date']??null)?$page['date']:($input['date']??'');
        if(!is_string($date)||preg_match('/\A20[0-9]{2}-[0-9]{2}-[0-9]{2}\z/D',$date)!==1)$date='';
        $platform=$page['platform']??$input['platform']??'all';
        if(!in_array($platform,['all','max','telegram'],true))$platform='all';
        $html='<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Дневная сверка · AnyTour</title><style>'.self::css().'</style></head><body>'
            .'<header><a href="/manager2/admin.php">AnyTour · администрирование</a><p class="eyebrow">Закрытый просмотр · администратор</p>'
            .'<h1>Дневная сверка конверсий</h1><p>Начисления, попытки загрузки и подтверждения Метрики — по собственным датам.</p></header><main>'
            .'<form method="get" action="/manager/assigned-value.php"><input type="hidden" name="view" value="reconciliation">'
            .'<label>День<input type="date" name="date" value="'.self::e($date).'" required></label><label>Канал<select name="platform">';
        foreach(['all'=>'MAX и Telegram','max'=>'MAX','telegram'=>'Telegram']as$value=>$label)
            $html.='<option value="'.$value.'"'.($value===$platform?' selected':'').'>'.$label.'</option>';
        $html.='</select></label><button>Показать</button></form>'
            .'<nav><a href="'.self::e(self::delivery($date,$platform)).'">Начисления по диалогам →</a></nav>';
        if($unavailable||!self::valid($page))return$html.'<section class="error" role="status"><h2>Сверка сейчас недоступна</h2>'
            .'<p>Полные суммы не получены. Это не ноль начислений или отправок.</p></section></main></body></html>';
        $html.='<section class="notice"><p>День: <strong>'.self::e($date).'</strong>, часовой пояс — Москва (МСК). Проверено: '.self::moment($page['as_of']).'.</p>'
            .'<p>Один диалог может иметь несколько приростов ценности. Считаем записи начислений и отдельные диалоги, а не покупки или уникальных людей.</p></section>'
            .'<section><h2>Движение по календарному дню</h2><div class="scroll"><table><thead><tr><th>Показатель</th>';
        $columns=$platform==='all'?['all'=>'Всего','max'=>'MAX','telegram'=>'Telegram']:[$platform=>$platform==='max'?'MAX':'Telegram'];
        foreach($columns as$label)$html.='<th>'.self::e($label).'</th>';
        $html.='</tr></thead><tbody>';
        foreach(self::ROWS as$key=>$label){
            $g=$page['calendar'][$key];$html.='<tr><th scope="row">'.$label.'</th>';
            foreach($columns as$channel=>$unused)$html.='<td>'.self::metric($channel==='all'?$g:$g['by_platform'][$channel]).'</td>';
            $html.='</tr>';
        }
        $html.='</tbody></table></div><p class="muted">Эти строки пересекаются и не складываются. Например, вчерашнее начисление может получить подтверждение API сегодня.</p>'
            .'<p class="muted">Дата попытки — последняя сохранённая попытка записи, а не точный счётчик HTTP-запросов. Приём и обработка — первые серверные отметки, не часы события у провайдера.</p>'
            .'<details><summary>Новый поток и исторический разбор</summary><ul>';
        foreach(self::ROWS as$key=>$label){
            $g=$page['calendar'][$key]['origins'];$html.='<li>'.$label.': новый поток — '.self::money($g['live']['value_rub'])
                .', исторический разбор — '.self::money($g['historical_review']['value_rub']).'.</li>';
        }
        $html.='</ul><p>Исторический разбор — происхождение начисления, а не день отправки. Он не становится ранним прогнозом.</p></details></section>'
            .'<section><h2>Что сейчас происходит с начислениями этого дня</h2><p>Состояния прочитаны сейчас. Для прошлого дня это не восстановленная очередь на конец суток.</p><div class="cards">';
        foreach(['waiting'=>'Ожидают','problems'=>'Требуют внимания','waiting_identity'=>'Без сохранённой рекламной связки среди ожидающих']as$key=>$label)
            $html.='<article><h3>'.$label.'</h3>'.self::metric($page['current_statuses'][$key]).'</article>';
        $html.='</div><p class="muted">Отсутствующая связка — часть ожиданий. Карточки не складываются.</p>'
            .'<div class="scroll"><table><thead><tr><th>Текущее состояние</th><th>Записи / диалоги / ценность</th></tr></thead><tbody>';
        foreach(self::STATES as$key=>$label)$html.='<tr><th scope="row">'.$label.'</th><td>'.self::metric($page['current_statuses']['by_state'][$key]).'</td></tr>';
        $html.='</tbody></table></div><p class="muted">События и суммы распределены по состояниям без повторов. Диалоги могут встречаться в нескольких состояниях.</p>'
            .'<p>Неизвестный результат загрузки не является разрешением отправить её повторно. Просмотр не меняет очередь.</p></section>'
            .'<section><h2>Чего данные пока не подтверждают</h2><p><strong>Привязка каждой конверсии к визиту: подтверждение не сохранено.</strong> Это неизвестность, а не ноль совпадений.</p>'
            .'<p>Принято API и обработано не означают привязку каждой строки к визиту. Общий отчёт Метрики по датам визитов — отдельный источник.</p>'
            .'<h3>Старые записи без сохранённого дня подтверждения</h3><p>Это весь текущий допущенный охват выбранного канала, не суммы выбранного дня.</p>'
            .'<p>Приём API известен, день неизвестен: '.self::metric($page['coverage']['api_receipts_without_observed_clock']).'</p>'
            .'<p>Обработка известна, день неизвестен: '.self::metric($page['coverage']['processed_without_observed_clock']).'</p></section>'
            .'<footer><p>Ценность — согласованная рекламная оценка, не оплата туриста и не выручка. Текущие связи диалогов проверены; служебные и тестовые диалоги исключены.</p>'
            .'<p>Изменение текущей связи может изменить доступный исторический охват. Клиентские переписки, идентификаторы рекламы и содержимое загрузок здесь не показываются.</p>'
            .'<a href="'.self::e(self::delivery($date,$platform)).'">Открыть основания начислений с теми же днём и каналом</a></footer>';
        return$html.'</main></body></html>';
    }
    private static function valid(?array $p):bool
    {
        if(!is_array($p)||($p['available']??false)!==true||($p['schema_version']??null)!==1
            ||($p['version']??null)!=='modeled_daily_reconciliation_v1'||($p['timezone']??null)!=='Europe/Moscow'
            ||($p['currency']??null)!=='RUB'||!in_array($p['platform']??null,['all','max','telegram'],true)
            ||!self::iso($p['as_of']??null)||!is_array($p['calendar']??null))return false;
        foreach(self::ROWS as$key=>$unused)if(!self::group($p['calendar'][$key]??null))return false;
        $s=$p['current_statuses']??null;
        if(!is_array($s)||($s['basis']??null)!=='current_state_of_selected_day_accruals'||!is_array($s['by_state']??null))return false;
        $n=0;$v=0;
        foreach(self::STATES as$key=>$unused){$x=$s['by_state'][$key]??null;if(!self::basic($x))return false;$n+=$x['events'];$v+=self::cents($x['value_rub']);}
        if($n!==$p['calendar']['accruals']['events']||$v!==self::cents($p['calendar']['accruals']['value_rub']))return false;
        foreach(['waiting','problems','waiting_identity']as$key)if(!self::basic($s[$key]??null))return false;
        if($s['waiting_identity']['events']>$s['waiting']['events'])return false;
        $coverage=$p['coverage']??null;
        if(!is_array($coverage)||($coverage['scope']??null)!=='current_exact_owner_admitted_native_bindings'
            ||($coverage['per_event_visit_match']??null)!==['status'=>'unavailable','reason'=>'not_recorded_per_conversion'])return false;
        return self::group($coverage['api_receipts_without_observed_clock']??null)
            &&self::group($coverage['processed_without_observed_clock']??null);
    }
    private static function basic($x):bool
    {
        if(!is_array($x)||!is_int($x['events']??null)||!is_int($x['conversations']??null)
            ||$x['events']<0||$x['events']>1000000000||$x['conversations']<0||$x['conversations']>$x['events']
            ||$x['events']>$x['conversations']*6)return false;
        $v=$x['value_rub']??null;
        return(is_int($v)||is_float($v))&&is_finite((float)$v)&&$v>=0&&$v<=1000000000000
            &&($x['events']===0?$v==0:$v>0);
    }
    private static function group($x):bool
    {
        if(!self::basic($x))return false;
        $m=$x['by_platform']['max']??null;$t=$x['by_platform']['telegram']??null;
        $l=$x['origins']['live']??null;$h=$x['origins']['historical_review']??null;
        if(!self::basic($m)||!self::basic($t)||!self::basic($l)||!self::basic($h))return false;
        return$m['events']+$t['events']===$x['events']&&$m['conversations']+$t['conversations']===$x['conversations']
            &&self::cents($m['value_rub'])+self::cents($t['value_rub'])===self::cents($x['value_rub'])
            &&$l['events']+$h['events']===$x['events']
            &&self::cents($l['value_rub'])+self::cents($h['value_rub'])===self::cents($x['value_rub']);
    }
    private static function metric(array $x):string
    {return'<strong>'.self::money($x['value_rub']).'</strong><small>Начислений: '.$x['events'].' · диалогов: '.$x['conversations'].'</small>';}
    private static function cents(int|float $v):int{return(int)round($v*100);}
    private static function money(int|float $v):string{return number_format((float)$v,2,',',' ').' ₽';}
    private static function iso($v):bool
    {return is_string($v)&&preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$v)===1&&strtotime($v)!==false;}
    private static function moment(string $v):string
    {return(new DateTimeImmutable($v))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i:s');}
    private static function delivery(string $date,string $platform):string
    {return'/manager/assigned-value.php?'.http_build_query(['view'=>'delivery','date'=>$date,'platform'=>$platform],'','&',PHP_QUERY_RFC3986);}
    private static function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function css():string
    {return'body{margin:0;background:#f5f7fb;color:#172033;font:16px/1.5 system-ui,sans-serif}header,main{max-width:1120px;margin:auto;padding:24px}h1{font-size:32px}h2{margin-top:0}a{color:#175a94}form,.cards{display:flex;gap:16px;flex-wrap:wrap}label{display:grid;gap:4px}input,select,button{font:inherit;padding:10px;border:1px solid #c4cbd6;border-radius:8px}button{background:#175a94;color:white}section,article{background:white;border:1px solid #dde3ed;border-radius:12px;padding:20px;margin:20px 0}.cards article{margin:0;flex:1;min-width:210px}table{border-collapse:collapse;width:100%;min-width:540px}td,th{text-align:left;padding:14px;border-bottom:1px solid #e3e8ef;vertical-align:top}td strong,small{display:block}.scroll{overflow:auto}.muted,.eyebrow,small{color:#566276}small{font-size:13px}.notice{background:#eef5ff}.error{border-color:#b33;background:#fff4f4}nav,footer{margin:20px 0}footer{color:#566276}details{margin-top:14px}@media(max-width:600px){header,main{padding:16px}h1{font-size:26px}section{padding:14px}.cards{display:block}.cards article{margin-bottom:12px}}';}
}
