<?php
declare(strict_types=1);

/** Owner-only HTML projection. Reads no storage and performs no requests. */
final class ModeledConversionDeliveryRegistryView
{
    private const STAGES=[
        'interest'=>'Интерес к поездке',
        'specific_request'=>'Конкретный запрос',
        'offer_discussion'=>'Обсуждение предложения',
        'purchase_intent'=>'Готовность купить',
        'agency_choice'=>'Выбор AnyTour',
        'payment_report'=>'Сообщение об оплате',
    ];
    private const STATES=[
        'pending'=>'Ожидает', 'ready'=>'Готово к отправке', 'uploading'=>'Отправка проверяется',
        'accepted'=>'Принято API Метрики', 'processed'=>'Обработка завершена',
        'unknown'=>'Результат неизвестен', 'failed'=>'Ошибка', 'expired'=>'Срок истёк',
        'unmatched'=>'Нет совпадения',
    ];

    public static function render(?array $page,array $input,array $monitor=[],bool $unavailable=false):string
    {
        $date=is_string($input['date']??null)?$input['date']:'';
        if(is_string($page['date']??null))$date=$page['date'];
        if(preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$date)!==1)$date='';
        $platform=in_array($input['platform']??null,['all','max','telegram'],true)?$input['platform']:'all';
        $ok=!$unavailable&&($page['available']??false)===true&&($page['timezone']??null)==='Europe/Moscow';
        $html='<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Конверсии · AnyTour</title><style>'.self::css().'</style></head><body>'
            .'<a class="skip" href="#registry">К начислениям</a><header class="top"><a class="brand" href="/manager2/admin.php" aria-label="AnyTour — администрирование">Any<span>Tour</span></a>'
            .'<a class="back" href="/manager2/admin.php">← Администрирование</a></header><main>'
            .'<div class="intro"><div><p class="eyebrow">Рекламная аналитика · доступ администратора</p><h1>Конверсии</h1>'
            .'<p class="lead">Кому, сколько и за какой сигнал начислена ценность.</p></div>'
            .'<span class="private">Закрытый просмотр</span></div>'
            .'<nav class="scope-note" aria-label="Разделы аналитики"><a href="/manager/assigned-value.php?view=reconciliation">Дневная сверка</a> · <a href="/manager/assigned-value.php?view=forecast-payments">Ранний прогноз → оплата</a> · <a href="/manager/assigned-value.php?view=acquisition">От вступления до обращения →</a></nav>'
            .self::monitor($monitor)
            .'<section class="ledger" id="registry" aria-labelledby="ledgerTitle"><div class="section-heading"><div><h2 id="ledgerTitle">Начисления по диалогам</h2>'
            .'<p class="muted">По дате начисления. Все даты и время — Москва (МСК).</p></div></div>'
            .self::filters($date,$platform);
        if(!$ok){
            $html.='<div class="notice error" role="status"><h3>Реестр сейчас недоступен</h3><p>Не удалось получить актуальные начисления. Обновите страницу позже.</p></div>';
        }else{
            $automatic=is_array($page['visit_verification']??null)?$page['visit_verification']:[];
            if(($automatic['available']??false)===true){
                $html.='<p class="scope-note">Сверка с визитами: автоматическая проверка раз в 5 минут. Последнее успешное обновление: '
                    .self::moment(($automatic['success_unix']??0)>0?gmdate('c',$automatic['success_unix']):null)
                    .'. Новые начисления и ранее не проверенные строки проходят по очереди; завершённые результаты проверяются повторно раз в сутки.'
                    .(($automatic['fresh']??false)===true?'':' Обновление задерживается.')
                    .' Время проверки каждой строки указано в её карточке.</p>';
            }
            $summary=is_array($page['summary']??null)?$page['summary']:[];
            $html.='<div class="totals" aria-label="Итоги выбранного дня"><div class="total"><span>Начислено за день</span><strong>'.self::money($summary['value_rub']??null).'</strong>'
                .'<small>Начислений: '.self::integer($summary['events']??null).' · диалогов: '.self::integer($summary['conversations']??null).'</small></div>';
            $delivery=is_array($summary['delivery']??null)?$summary['delivery']:[];
            foreach(['accepted_receipts'=>'Из них принято API','processed'=>'Из них обработано']as$key=>$label){
                $v=is_array($delivery[$key]??null)?$delivery[$key]:[];
                $html.='<div class="total"><span>'.$label.'</span><strong>'.self::money($v['value_rub']??null).'</strong><small>Начислений: '.self::integer($v['events']??null).'</small></div>';
            }
            $html.='</div><p class="scope-note">Итоги относятся к начислениям выбранного дня. Приём и обработка могут происходить позже; эти суммы не складываются.</p>';
            $live=$summary['live']??[];$history=$summary['historical_review']??[];
            $html.='<p class="split">Новый поток: <strong>'.self::money($live['value_rub']??null).'</strong>'
                .' <span>·</span> Исторический разбор: <strong>'.self::money($history['value_rub']??null).'</strong></p>';
            $rows=is_array($page['rows']??null)?array_slice($page['rows'],0,25):[];
            $html.='<div class="row-labels" aria-hidden="true"><span>Турист и основание</span><span>За выбранный день</span><span>Накоплено по диалогу</span></div><div class="rows">';
            foreach($rows as$row)if(is_array($row))$html.=self::row($row);
            if(!$rows)$html.='<div class="empty"><h3>В этой выборке начислений нет</h3><p>Выберите другой день или канал.</p></div>';
            $html.='</div>'.self::pagination($page,$date,$platform,is_string($input['cursor']??null));
            $html.='<p class="asof">Граница выборки: '.self::moment($page['as_of']??null).'. Статусы прочитаны: '.self::moment($page['checked_at']??null).'.</p>';
        }
        return $html.'</section><footer><p>Ценность рассчитана по согласованной рекламной шкале. Это не сумма оплаты туриста и не фактическая выручка.</p>'
            .'<p>«Принято API» означает получение загрузки Метрикой. Завершение обработки само по себе не подтверждает привязку каждой конверсии к визиту.</p></footer></main></body></html>';
    }

    private static function filters(string $date,string $platform):string
    {
        $html='<form class="filters" method="get" action="/manager/assigned-value.php"><input type="hidden" name="view" value="delivery">'
            .'<label>День начисления<input name="date" type="date" value="'.self::e($date).'" required></label>'
            .'<label>Канал<select name="platform">';
        foreach(['all'=>'MAX и Telegram','max'=>'MAX','telegram'=>'Telegram']as$value=>$label)
            $html.='<option value="'.$value.'"'.($value===$platform?' selected':'').'>'.$label.'</option>';
        return $html.'</select></label><button type="submit">Показать</button>'
            .'<a class="refresh" href="'.self::e(self::url($date,$platform)).'">Обновить данные</a></form>';
    }

    private static function monitor(array $m):string
    {
        $p=is_array($m['pipeline']??null)?$m['pipeline']:[];
        $active=($p['active']??false)===true;
        $available=$active&&($p['available']??false)===true&&($p['origin']??null)==='server_cron';
        $fresh=$available&&($p['fresh']??false)===true;
        $confirmed=in_array($p['notification']??null,['sent','quiet'],true);
        $healthy=$fresh&&($p['ok']??false)===true&&($p['status']??null)==='ok'&&$confirmed;
        $disabled=$fresh&&($p['ok']??false)===true&&($p['status']??null)==='off'&&$confirmed;
        $label=$disabled?'Отправка конверсий выключена':($healthy?'Проверка успешна':($available&&!$fresh?'Проверка устарела':($available?'Требуется внимание':'Нет подтверждённой проверки')));
        $class=$disabled?'neutral':($healthy?'good':'attention');
        $s=is_array($p['scheduled']??null)?$p['scheduled']:[];
        $scheduledFresh=$fresh&&($s['fresh']??false)===true;
        $regular=($healthy||$disabled)&&$scheduledFresh&&($s['regular']??false)===true;
        $n=$scheduledFresh&&is_int($s['success_streak']??null)?max(0,min(3,$s['success_streak'])):0;
        $source=($p['origin']??null)==='server_cron'?'По серверному расписанию':'Тип запуска не сохранён';
        $html='<section class="monitor" id="pipelineMonitor" aria-labelledby="monitorTitle"><div class="monitor-heading"><h2 id="monitorTitle">Контроль обработчиков</h2><span class="badge '.$class.'">'.$label.'</span></div>'
            .'<div class="monitor-grid"><div><span class="muted">Последняя проверка обработчиков</span><strong>'.self::moment($p['checked_at']??null).'</strong><small>'.$source.'</small></div>'
            .'<div><span class="muted">Последняя серверная проверка по расписанию</span><strong>'.self::moment($s['last_checked_at']??null).'</strong><small>'
            .($regular?'Регулярность подтверждена':'Регулярность пока не подтверждена · '.$n.' из 3 проверок').'</small></div>'
            .'<div><span class="muted">Последнее уведомление об обработчиках</span><strong>'.self::moment($p['last_notification_at']??null).'</strong><small>Одинаковые сбои не дублируются</small></div></div>';
        if(($p['notification']??null)==='unknown')$html.='<p class="monitor-warning">Отправка уведомления не подтверждена. Повторная отправка приостановлена до проверки результата.</p>';
        if(!$fresh)$html.='<p class="monitor-warning">Свежей проверки обработчиков нет. Последний сохранённый результат не подтверждает их работу сейчас.</p>';
        $note=$active?'Серверные проверки обработчиков настроены каждые пять минут. При здоровом состоянии уведомления не отправляются.'
            :(($p['active']??null)===false?'Серверные проверки обработчиков не подключены.':'Настройка серверных проверок не подтверждена.');
        $html.='<p class="monitor-note">'.$note.'</p></section>';
        return $html.self::externalMonitor($m);
    }

    private static function externalMonitor(array $m):string
    {
        $available=($m['available']??false)===true;$fresh=($m['fresh']??false)===true;
        $confirmed=in_array($m['notification']??null,['sent','quiet'],true);
        $healthy=$available&&$fresh&&($m['status']??null)==='ok'&&$confirmed;
        $label=$healthy?'Проверка успешна':($available&&!$fresh?'Проверка устарела':($available?'Требуется внимание':'Нет подтверждённой проверки'));
        $s=is_array($m['scheduled']??null)?$m['scheduled']:[];
        $source=match($m['source_event']??null){
            'schedule'=>'По расписанию GitHub', 'workflow_run'=>'После обновления приложения',
            'workflow_dispatch'=>'Проверочный запуск', default=>'Тип запуска не сохранён',
        };
        $html='<section class="notice external-monitor" id="externalMonitor" aria-labelledby="externalMonitorTitle" style="margin-bottom:32px"><div class="monitor-heading"><h2 id="externalMonitorTitle">Внешняя проверка сервера</h2><span class="badge '.($healthy?'good':'attention').'">'.$label.'</span></div>'
            .'<div class="monitor-grid"><div><span class="muted">Последняя внешняя проверка</span><strong>'.self::moment($m['checked_at']??null).'</strong><small>'.$source.'</small></div>'
            .'<div><span class="muted">Последняя внешняя проверка по расписанию</span><strong>'.self::moment($s['last_checked_at']??null).'</strong><small>Расписание GitHub</small></div>'
            .'<div><span class="muted">Последнее уведомление о сервере</span><strong>'.self::moment($m['last_notification_at']??null).'</strong><small>Доступность сервера проверяется извне</small></div></div>';
        if(($m['notification']??null)==='unknown')$html.='<p class="monitor-warning">Отправка уведомления о сервере не подтверждена. Повторная отправка приостановлена до проверки результата.</p>';
        if(!$available||!$fresh)$html.='<p class="monitor-warning">Свежей внешней проверки нет. Доступность сервера сейчас не подтверждена.</p>';
        return $html.'<p class="monitor-note">GitHub может задерживать или пропускать запуски. Гарантии внешней проверки каждые пять минут нет.</p></section>';
    }

    private static function row(array $r):string
    {
        $id=self::positiveId($r['conversation_id']??null);
        $name=is_string($r['customer_name']??null)&&trim($r['customer_name'])!==''?mb_substr($r['customer_name'],0,120):'Турист';
        $platform=match($r['platform']??null){'max'=>'MAX','telegram'=>'Telegram',default=>'Канал неизвестен'};
        $stage=self::stage($r['stage']??null);
        $html='<article class="dialogue" data-dialogue="'.self::e($id??'unknown').'"><div class="dialogue-head">'
            .'<div class="identity"><h3>'.self::e($name).'</h3><p class="meta">'.$platform.' · диалог №'.self::e($id??'неизвестно').'</p><p class="stage">'.$stage.'</p></div>'
            .'<div class="amount"><span>За день</span><strong>'.self::money($r['period_value_rub']??null).'</strong></div>'
            .'<div class="amount total-amount"><span>Накоплено</span><strong>'.self::money($r['total_value_rub']??null).'</strong></div></div>'
            .'<details class="event-details"><summary>Начисления и результат отправки</summary><p class="muted small" style="padding:0 20px">Все сохранённые начисления по этому диалогу до среза выборки.</p><div class="event-list">';
        if(is_int($r['events_omitted']??null)&&$r['events_omitted']>0)$html.='<p class="muted small">Показаны последние 32 начисления; более ранних: '.self::integer($r['events_omitted']).'. Итоги учитывают все начисления.</p>';
        foreach(array_slice(is_array($r['events']??null)?$r['events']:[],0,32)as$event)if(is_array($event))$html.=self::event($event);
        return $html.'</div></details></article>';
    }

    private static function event(array $e):string
    {
        $state=is_string($e['state']??null)?$e['state']:'';
        $stateText=self::STATES[$state]??'Статус неизвестен';
        $class=in_array($state,['accepted','processed'],true)?'good':(in_array($state,['failed','unknown','expired','unmatched'],true)?'attention':'neutral');
        $origin=in_array($e['origin']??null,['owner_day_review_v1','owner_fortnight_review_v1','historical_review'],true)?'Исторический разбор':'Новый поток';
        $html='<section class="event"><div class="event-heading"><div><h4>'.self::stage($e['stage']??null).'</h4><span class="muted">'.$origin.'</span></div><strong>+'.self::money($e['value_rub']??null).'</strong>'
            .'<span class="badge '.$class.'">'.$stateText.'</span></div>';
        $reason=self::reason($e['error']??null);
        if($reason!==null)$html.='<p class="hold-reason">'.$reason.'</p>';
        $html.='<dl class="clocks">';
        foreach(['event_at'=>'Начислено','profile_saved_at'=>'Профиль сохранён','ready_at'=>'Готово к отправке','upload_claim_at'=>'Последняя попытка загрузки','api_accepted_at'=>'Приём API подтверждён','processed_at'=>'Обработка подтверждена']as$key=>$label)
            $html.='<div><dt>'.$label.'</dt><dd>'.self::moment($e[$key]??null).'</dd></div>';
        $html.='</dl><p class="ground">';
        if(($e['grounds_status']??null)==='saved_stage_profile'&&($profile=self::positiveId($e['profile_id']??null))!==null)
            $html.='Начисление связано с сохранённым профилем №'.self::e($profile).'.';
        else $html.='Подробное сохранённое основание недоступно. Начисленный этап указан выше.';
        $html.='</p><p class="muted small">'.(($e['identity_resolved']??false)===true?'Рекламная связка сохранена.':'Подтверждённая рекламная связка пока отсутствует.').'</p>';
        if(($e['api_accepted_at']??null)===null&&($e['api_receipt_present']??false)===true)
            $html.='<p class="muted small">Приём загрузки известен, но точное время подтверждения для этой старой записи не сохранялось.</p>';
        $v=is_array($e['valuation']??null)?$e['valuation']:[];
        if(($v['basis']??null)==='predictive_v1'){
            $html.='<p class="ground">Предиктив v1 · комиссия для оценки 7%.';
            if(is_numeric($v['probability']??null))$html.=' Оценка покупки: '.self::e(number_format((float)$v['probability']*100,2,',',' ')).'%.';
            if(is_numeric($v['deal_rub']??null))$html.=' Оценка тура: '.self::money($v['deal_rub']).'.';
            if(is_numeric($v['frozen_base_rub']??null))$html.=' База сохранённого прогноза: '.self::money($v['frozen_base_rub']).'.';
            $html.=' Отправляется только положительный прирост; ограничение при потере цены учитывается отдельно.</p>';
        }
        $p=is_array($e['visit_check']??null)?$e['visit_check']:[];
        if(($p['available']??false)===true){
            $linked=($p['linkage']??null)==='unique_visit_reconciled';
            $failed=($p['linkage']??null)==='failed';
            $html.='<div class="ground"><strong>'.($linked?'Однозначно сверено с целевым визитом':($failed?'Метрика не привязала начисление к визиту':'Привязка к визиту ещё не подтверждена')).'</strong>'
                .'<p>Дополнительная проверка Метрики: '.self::moment($p['checked_at']??null).'. Статус загрузки: '.self::e($p['upload_status']??'не получен').'.</p>';
            $why=match($p['reason']??null){
                'no_reported_client_visits'=>'В счётчике не найдены визиты сохранённого ClientID за проверенный период.',
                'only_later_or_expired_client_visits'=>'Найдены только визиты после сигнала или вне окна атрибуции.',
                'no_client_visit_in_attribution_window'=>'Для этого сигнала нет визита в пределах 21 дня.',
                'eligible_visit_but_provider_linkage_failed'=>'Подходящий по времени визит найден, но Метрика вернула отказ привязки.',
                'client_visit_report_unavailable'=>'Отказ привязки получен; отчёт визитов для уточнения причины недоступен.',
                default=>null,
            };
            if($why!==null)$html.='<p>'.self::e($why).'</p>';
            if(($p['fresh']??false)!==true)$html.='<p class="hold-reason">Проверка устарела; это сохранённый результат.</p>';
            if($linked)$html.='<p>Начало визита: '.self::moment($p['visit_started_at']??null).'. Достижений этой цели в визите: '
                .self::integer($p['visit_goal_reaches']??null).'; сумма: '.self::money($p['visit_goal_value_rub']??null).'. Учтены прежние начисления того же посетителя.</p>'
                .'<p class="muted small">Сверка по ClientID и точному времени визита; отдельный VisitID не выгружался.</p>';
            $html.='</div>';
        }else $html.='<p class="muted small">Отдельная сверка этого начисления с визитом пока не сохранена.</p>';
        return $html.'</section>';
    }

    private static function pagination(array $page,string $date,string $platform,bool $hasCursor):string
    {
        $p=is_array($page['pagination']??null)?$page['pagination']:[];
        $html='<nav class="pagination" aria-label="Страницы реестра"><span>Диалогов на странице: '.self::integer($p['shown']??null).'</span>';
        if(($p['has_more']??false)===true&&is_string($p['next_cursor']??null)&&strlen($p['next_cursor'])<=2048)
            $html.='<a class="button" rel="next" href="'.self::e(self::url($date,$platform,$p['next_cursor'])).'">Следующие диалоги →</a>';
        if($hasCursor)$html.='<a href="'.self::e(self::url($date,$platform)).'">В начало</a>';
        return $html.'</nav>';
    }

    private static function stage(mixed $s):string{return is_string($s)?(self::STAGES[$s]??'Этап не определён'):'Этап не определён';}
    private static function reason(mixed $error):?string
    {
        return match($error){
            'identity_not_found'=>'Нет сохранённой рекламной связки для отправки.',
            'identity_ambiguous'=>'Найдено несколько рекламных связок. Однозначная связь пока не подтверждена.',
            'scope_unavailable'=>'Не удалось подтвердить доступ к основанию.',
            'api_rejected','api_row_rejected'=>'Метрика отклонила загрузку или строку начисления.',
            'upload_outcome_unknown'=>'Результат отправки ещё не подтверждён. Повторная загрузка не выполняется вслепую.',
            'event_expired'=>'Срок допустимой передачи события истёк.',
            'transport_not_sent'=>'Отправка не состоялась. Сохранена для следующей допустимой попытки.',
            'rate_limited'=>'Метрика ограничила частоту запросов. Ожидается следующая допустимая попытка.',
            null,''=>null, default=>'Сохранена ошибка обработки. Подробности требуют проверки.',
        };
    }
    private static function url(string $date,string $platform,?string $cursor=null):string
    {
        $q=['view'=>'delivery','date'=>$date,'platform'=>$platform];if($cursor!==null)$q['cursor']=$cursor;
        return '/manager/assigned-value.php?'.http_build_query($q,'','&',PHP_QUERY_RFC3986);
    }
    private static function positiveId(mixed $id):?string
    {
        return (is_int($id)||is_string($id))&&preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$id)===1?(string)$id:null;
    }
    private static function money(mixed $v):string
    {
        if((!is_int($v)&&!is_float($v)&&!is_string($v))||!is_numeric($v)||!is_finite((float)$v)||(float)$v<0)return 'Неизвестно';
        $n=(float)$v;return number_format($n,abs($n-round($n))<0.000001?0:2,',',' ').' ₽';
    }
    private static function integer(mixed $v):string
    {
        return (is_int($v)&&$v>=0)||(is_string($v)&&preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$v)===1)?self::e((string)$v):'—';
    }
    private static function moment(mixed $v):string
    {
        if(!is_string($v)||preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D',$v)!==1)return 'Не записано';
        try{$d=new DateTimeImmutable($v);return self::e($d->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i:s'));}catch(Throwable $ignored){return 'Не записано';}
    }
    private static function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function css():string
    {
        return '*,*:before,*:after{box-sizing:border-box}body{margin:0;background:#f4f6f5;color:#172a28;font:15px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}a{color:#175f51}button,input,select{font:inherit}button,.button{background:#175b4e;color:#fff;border:1px solid #175b4e;border-radius:9px;padding:11px 18px;text-decoration:none;font-weight:650;cursor:pointer}a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,summary:focus-visible{outline:3px solid #c78d23;outline-offset:4px}.skip{position:absolute;left:16px;top:-80px;background:white;padding:10px;z-index:2}.skip:focus{top:10px}.top,main{max-width:1180px;margin:auto;padding:24px 32px}.top{display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #dae4df}.brand{font-size:25px;font-weight:800;letter-spacing:-1px;text-decoration:none;color:#19483d}.brand span{color:#aa762c}.back{font-size:14px;text-decoration:none}.intro{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin:12px 0 28px}.eyebrow{font-size:12px;color:#58716a;letter-spacing:.04em;margin:0 0 8px}h1{font-size:clamp(30px,4vw,42px);line-height:1.15;letter-spacing:-1px;margin:0 0 12px}h2{font-size:20px;line-height:1.3;margin:0}h3{font-size:17px;line-height:1.3;margin:0;overflow-wrap:anywhere}h4{margin:0;font-size:15px}.lead{color:#58716a;margin:0}.private{background:#e7eeea;color:#536a61;padding:7px 10px;border-radius:6px;white-space:nowrap;font-size:12px}.monitor{border:1px solid #d9e3dd;background:white;border-radius:14px;padding:22px;margin-bottom:32px}.monitor-heading,.event-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.monitor-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:22px;margin-top:20px}.monitor-grid>div{display:flex;flex-direction:column;gap:5px;min-width:0}.monitor-grid strong{font-size:14px}.monitor-grid small,.muted{color:#61746d}.badge{display:inline-block;font-size:12px;padding:5px 9px;border-radius:6px;font-weight:650}.good{background:#e7f3eb;color:#22633d}.attention{background:#fff0d6;color:#8a5815}.neutral{background:#edf0f2;color:#52616e}.monitor-warning{padding:11px 13px;background:#fff5e7;color:#835919;border-radius:7px;font-size:13px}.monitor-note,.scope-note,.asof{font-size:12px;color:#697c74;margin-bottom:0}.section-heading{display:flex;justify-content:space-between;gap:16px;margin-bottom:20px}.section-heading p{font-size:13px;margin:5px 0 0}.filters{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin:20px 0}.filters label{display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:600;color:#536a62}.filters input,.filters select{border:1px solid #cddbd2;background:white;border-radius:8px;padding:10px 12px;min-height:45px;min-width:180px;max-width:100%;color:#233f34}.refresh{align-self:center;margin-top:19px;font-size:13px}.totals{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.total{display:flex;flex-direction:column;gap:7px;background:white;border:1px solid #d9e3dd;border-radius:12px;padding:20px;min-width:0}.total>span{font-size:13px;color:#60746b}.total strong{font-size:clamp(25px,3vw,32px);letter-spacing:-.6px;overflow-wrap:anywhere}.total small{font-size:12px;color:#6a7b73}.split{font-size:13px;color:#63786d;display:flex;gap:6px;flex-wrap:wrap;margin:18px 0 24px}.split strong{font-weight:600}.row-labels{display:grid;grid-template-columns:minmax(0,1fr) 180px 180px;gap:16px;padding:0 21px 9px;font-size:11px;color:#718178}.row-labels span:not(:first-child){text-align:right}.rows{display:grid;gap:12px}.dialogue{background:white;border:1px solid #d9e3dd;border-radius:12px;overflow:hidden}.dialogue-head{display:grid;grid-template-columns:minmax(0,1fr) 180px 180px;gap:16px;padding:20px}.identity{min-width:0}.meta{font-size:12px;color:#708078;margin:5px 0}.stage{font-size:13px;color:#345f4c;margin:8px 0 0}.amount{text-align:right;display:flex;flex-direction:column;gap:6px}.amount span{display:none;font-size:11px;color:#738078}.amount strong{font-size:22px;white-space:nowrap;letter-spacing:-.4px}.total-amount strong{font-size:18px;color:#63766b}.event-details{border-top:1px solid #edf1ee}.event-details>summary{padding:12px 20px;cursor:pointer;color:#4b685b;font-size:13px}.event-list{padding:0 20px 18px;display:grid;gap:12px}.event{border:1px solid #e2e9e4;border-radius:9px;padding:15px;background:#fbfcfb}.event-heading .muted{display:block;font-size:11px;margin-top:4px}.event-heading>strong{margin-left:auto;font-size:18px}.clocks{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:18px 0}.clocks>div{min-width:0}.clocks dt{font-size:11px;color:#718178}.clocks dd{margin:3px 0 0;font-size:12px;color:#3d5849;overflow-wrap:anywhere}.hold-reason{font-size:13px;background:#fff3df;padding:10px;border-radius:6px;color:#83571c}.ground{font-size:12px;color:#586e60;margin:14px 0 8px}.small{font-size:11px;margin:6px 0}.pagination{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px;margin:23px 0;font-size:13px;color:#65786b}.pagination a{font-size:13px}.notice,.empty{padding:25px;border:1px solid #d9e3dd;border-radius:10px;background:white;margin-top:16px}.error{border-color:#e4bfa3;background:#fff8ee}.notice p,.empty p{margin-bottom:0;color:#687b70}footer{font-size:12px;color:#78877d;border-top:1px solid #dce5df;margin-top:32px;padding-top:15px}footer p{margin:8px 0}@media(max-width:800px){.top,main{padding:20px}.intro{flex-wrap:wrap}.monitor-grid{grid-template-columns:1fr;gap:16px}.totals{grid-template-columns:1fr}.total{padding:16px}.total strong{font-size:29px}.row-labels{display:none}.dialogue-head{grid-template-columns:1fr 1fr;gap:14px;padding:17px}.identity{grid-column:1/-1}.amount{text-align:left}.amount span{display:block}.amount strong{font-size:23px}.total-amount strong{font-size:20px}.clocks{grid-template-columns:1fr 1fr}.monitor{padding:17px}.event-list{padding:0 12px 12px}.event-details>summary{padding:12px 17px}}@media(max-width:430px){.top,main{padding:16px}.brand{font-size:23px}.back{font-size:12px}.filters{gap:10px}.filters label{width:100%}.filters input,.filters select{min-width:0;width:100%}.filters button{flex:1}.refresh{margin-top:0}.monitor-heading{align-items:flex-start}.monitor-heading h2{font-size:18px}.event-heading{align-items:flex-start}.event-heading>strong{font-size:17px}.clocks{gap:12px}.private{font-size:11px}.pagination{align-items:stretch}.pagination .button{text-align:center;width:100%}}';
    }
}
