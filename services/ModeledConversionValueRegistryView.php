<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionAssignedValue.php';

/** Pure HTML presentation of one already-authorized registry page; no IO or valuation. */
final class ModeledConversionValueRegistryView
{
    private const LABELS=[
        'subscription'=>'Подписка',
        'retention_30m'=>'Наблюдаемое удержание через 30 минут',
        'dialogue'=>'Первое действительное сообщение клиента',
        'specific_request'=>'Содержательный запрос клиента',
        'offer_reply'=>'Связанный ответ на доставленное предложение',
    ];

    public static function render(string $view,?array $page,string $after='0',bool $unavailable=false):string
    {
        if(!in_array($view,['members','profiles'],true)||!self::cursor($after))throw new InvalidArgumentException('value_view_request');
        if(!$unavailable&&(!is_array($page)||!is_array($page['rows']??null)||!array_is_list($page['rows'])
            ||count($page['rows'])>5||!is_bool($page['has_more']??null)||!is_string($page['next_after']??null)
            ||!self::cursor($page['next_after'])||array_filter($page['rows'],static fn($r):bool=>!is_array($r))))$unavailable=true;
        $title='Наблюдаемые этапы и экспериментальная оценка';
        $html='<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.$title.' · AnyTour</title><style>'.self::css().'</style></head><body><header><a href="/manager2/">AnyTour · к диалогам</a>'
            .'<p class="eyebrow">Приватный просмотр · только администратор</p><h1>'.$title.'</h1>'
            .'<p>Наблюдаемые этапы показаны отдельно от экспериментальной оценки. Веса 50/25/50/50/100 и сумма 275 ₽ были примером и не утверждены как доход или денежная шкала.</p></header><main>'
            .'<nav aria-label="Источники реестра">'.self::nav('members','Подписчики MAX',$view).self::nav('profiles','Диалоги MAX / Telegram',$view).'</nav>'
            .'<section class="notice"><h2>Границы этого просмотра</h2>'
            .'<p>Один запрос показывает не более 5 строк одного источника. Общий размер доступного источника и доля полного охвата здесь неизвестны; эта страница не доказывает проверку всех подписчиков.</p>'
            .'<p>Подписки Telegram недоступны из MAX-моста. Сохранённые профили диалогов Telegram видны отдельно вместе с MAX-профилями. Подписчик MAX может присутствовать без сообщений.</p>'
            .'<p>Строки диалогов могут пересекаться со строками подписчиков. Не суммируйте их как уникальных людей. Повторный просмотр не создаёт начислений.</p></section>'
            .'<section aria-labelledby="source-title"><h2 id="source-title">'.($view==='members'?'Текущие подписчики MAX':'Текущие доступные профили диалогов MAX / Telegram').'</h2>';
        if($unavailable){
            $html.='<div class="error" role="status"><h3>Источник недоступен</h3><p>Текущая страница не получена. Это не отсутствие подписчиков или диалогов и не нулевая ценность.</p>'
                .'<a href="'.self::e(self::url($view,$after)).'">Повторить эту страницу</a></div>';
        }else{
            $rows=$page['rows'];
            $html.=self::pageCoverage($view,$rows);
            $html.='<p class="muted">Показано строк на этой странице: '.count($rows).'. Правила: '.self::e($page['rules_version']??null).'.</p>';
            if(!$rows)$html.='<p class="empty">На этой странице доступных строк нет. Этот результат не устанавливает отсутствие строк в других страницах или источниках.</p>';
            foreach($rows as$row)$html.=self::row($row,$view);
            $omitted=$page[$view==='members'?'omitted_owner_or_test':'omitted']??null;
            if(is_int($omitted)&&$omitted>0)$html.='<p class="muted">Пропущено при обработке этой страницы: '.$omitted.'. Состав пропусков не считается нулевой ценностью.</p>';
            $html.='<details class="coverage"><summary>Точный охват источника этой страницы</summary><pre>'.self::e(self::json($page['coverage']??null)).'</pre></details>';
            if($page['has_more'])$html.='<p><a class="button" rel="next" href="'.self::e(self::url($view,$page['next_after'])).'">Следующие до 5 строк →</a></p>';
            else $html.='<p class="muted">Источник не сообщает следующую страницу для этого курсора. Это не подтверждение полного охвата бизнеса или других источников.</p>';
        }
        return $html.'</section><footer><p>Экспериментальные веса: 50 + 25 + 50 + 50 + 100 = 275 ₽ при наличии всех пяти оснований. Эта шкала не утверждена. Дополнительные 75 ₽ не согласованы и отключены.</p>'
            .'<p>50 ₽ — желаемая цена привлечения подписчика. Допустимые цены дальнейших этапов определяются после расчёта переходов; ДРР 10% пока кандидат. Экспорт выключен; денежных конверсионных отправок: 0. Времена показаны из доступных оснований; неизвестные поля не восстановлены из предположений.</p></footer></main></body></html>';
    }

    private static function pageCoverage(string $view,array $rows):string
    {
        $html='<div class="pageCoverage"><h3 id="page-coverage-title">Охват этой страницы по источникам</h3>'
            .'<table aria-labelledby="page-coverage-title"><thead><tr><th scope="col">Источник</th><th scope="col">Строк на этой странице</th><th scope="col">Всего в источнике</th></tr></thead><tbody>';
        if($view==='members'){
            $html.='<tr><th scope="row">Подписчики MAX</th><td>'.count($rows).'</td><td>неизвестно</td></tr>'
                .'<tr><th scope="row">Подписчики Telegram</th><td>источник недоступен</td><td>неизвестно</td></tr>'
                .'<tr><th scope="row">Профили диалогов MAX / Telegram</th><td>отдельный просмотр</td><td>неизвестно</td></tr>';
        }else{
            $counts=['max'=>0,'telegram'=>0,'other'=>0];
            foreach($rows as$row){
                $platform=is_array($row['value_scope']??null)?($row['value_scope']['platform']??null):null;
                $key=$platform==='max'?'max':($platform==='telegram'?'telegram':'other');
                $counts[$key]++;
            }
            foreach(['max'=>'Профили MAX','telegram'=>'Профили Telegram','other'=>'Другой / неизвестный канал']as$key=>$label){
                if($key==='other'&&$counts[$key]===0)continue;
                $html.='<tr><th scope="row">'.$label.'</th><td>'.$counts[$key].'</td><td>неизвестно</td></tr>';
            }
        }
        return $html.'</tbody></table><p class="muted">Только строки показанной страницы: не накопленный итог и не число уникальных людей. Размеры других страниц и источников не определяются по этим числам.</p></div>';
    }

    private static function row(array $row,string $view):string
    {
        $scope=is_array($row['value_scope']??null)?$row['value_scope']:[];
        $platform=$scope['platform']??null;
        $platformLabel=match($platform){'max'=>'MAX','telegram'=>'Telegram',default=>self::value($platform)};
        $identity=$view==='members'?'пользователь №'.self::value($scope['external_user_id']??null)
            :'диалог №'.self::value($scope['conversation_id']??null).' · субъект №'.self::value($scope['customer_key']??null);
        $subtotal=$row['known_subtotal_rub']??null;
        $amount=is_int($subtotal)&&$subtotal>=0?(string)$subtotal.' ₽':'неизвестно';
        $complete=($row['completeness']??null)==='all_five_awarded'?'Наблюдаются основания всех пяти этапов. Денежная интерпретация экспериментальная.':'Экспериментальная сумма частичная; неподтверждённые этапы остаются неизвестными.';
        $html='<article class="row"><h3>'.self::e($platformLabel.' · '.$identity).'</h3><div class="subtotal"><span>Экспериментальная сумма</span><strong>'.self::e($amount).'</strong></div>'
            .'<p>'.$complete.'</p><p class="muted">Версия правил: '.self::e($row['rules_version']??null).' · Оценка на: '.self::e($row['evaluated_at']??null).'</p>';
        if($view==='profiles')$html.='<p class="warning">Это наблюдаемый префикс переписки, не подтверждённая текущая поездка и не установленный уникальный человек. Связь с подписчиком не предполагается.</p>';
        else $html.='<p>Связанный профиль: '.self::e(self::binding($row['profile_binding']??null)).'.</p>'
            .'<p class="muted">Даже связанный профиль описывает наблюдаемый префикс переписки; границы текущей поездки не подтверждены.</p>';
        $html.=self::attribution($row['attribution']??null);
        $html.='<p>Тип реакции: '.self::e(self::reaction($row['reaction_type']??null)).'. Ответ не доказывает оплату.</p><div class="stages">';
        foreach(self::LABELS as$key=>$label){
            $stage=is_array($row['stages'][$key]??null)?$row['stages'][$key]:[];
            $awarded=($stage['status']??null)==='awarded';
            $html.='<section class="stage"><h4>'.self::e($label).'</h4><p class="'.($awarded?'awarded':'unknown').'">'
                .($awarded?'Основание есть · тестовый вес: '.ModeledConversionAssignedValue::WEIGHTS[$key].' ₽':'Неизвестно · тестовый вес при подтверждении: '.ModeledConversionAssignedValue::WEIGHTS[$key].' ₽')
                .'</p><p>'.self::e($awarded?self::ground($key):self::unknown($key,$row,$view,$platform)).'</p>'
                .'<details><summary>Основания и время</summary>';
            $refs=is_array($stage['source_refs']??null)?$stage['source_refs']:[];
            if(!$refs)$html.='<p class="muted">Проверенные ссылки на основания не предоставлены.</p>';
            else{
                $html.='<ul>';
                foreach($refs as$ref)$html.='<li><code>'.self::e($ref).'</code></li>';
                $html.='</ul>';
            }
            $html.='<dl>';
            foreach(['occurred_at'=>'Событие произошло','recorded_at'=>'Основание записано','available_at'=>'Основание стало доступно']as$field=>$name)
                $html.='<dt>'.$name.'</dt><dd>'.self::e($stage[$field]??null).'</dd>';
            $html.='</dl></details></section>';
        }
        return $html.'</div><details><summary>Стабильная единица оценки и точный охват строки</summary>'
            .'<h4>value_scope</h4><pre>'.self::e(self::json($scope)).'</pre><h4>Охват / ограничения</h4><pre>'.self::e(self::json($row['coverage']??null)).'</pre>'
            .'<p>Ссылки показаны как сохранены. Непредоставленные ревизии и времена остаются неизвестными; они не выводятся из номера снимка.</p></details></article>';
    }

    private static function attribution(mixed $attribution):string
    {
        $a=is_array($attribution)?$attribution:[];
        if(($a['status']??null)==='known')return '<p>Рекламная привязка: текущий ClientID '.self::e($a['client_id']??null)
            .'. Это привязка текущей строки трекера, не проверенный исторический рекламный визит. Основание: <code>'.self::e($a['basis']??null).'</code>.</p>';
        return '<p>Рекламная привязка неизвестна. Причина: <code>'.self::e($a['reason']??null).'</code>.</p>';
    }

    private static function ground(string $key):string
    {
        return match($key){
            'subscription'=>'Проверенная запись подписки в доступных основаниях.',
            'retention_30m'=>'Наблюдаемая проверка точного периода не ранее чем через 1800 секунд после подписки.',
            'dialogue'=>'Действительное входящее сообщение клиента, допущенное установленными правилами.',
            'specific_request'=>'Содержательный запрос распознан установленным правилом в доступном контексте.',
            'offer_reply'=>'Точное связывание запроса, подготовки, подтверждённой доставки и последующего текстового ответа.',
        };
    }

    private static function unknown(string $key,array $row,string $view,mixed $platform):string
    {
        if($key==='subscription')return $platform==='telegram'
            ?'Нет проверенной записи подписки в доступном профиле. MAX-мост не покрывает подписки Telegram.'
            :'Нет проверенной записи подписки в доступных основаниях.';
        if($key==='retention_30m')return 'Нет наблюдаемой проверки точного периода через 1800 секунд. Прошедшее время и отсутствие отписки её не заменяют.';
        if($view==='members'&&($row['profile_binding']??null)!=='exact_private_chat_current_profile')
            return 'Нет доступного однозначно связанного текущего профиля. Наличие или отсутствие сообщений и запроса не устанавливается.';
        return match($key){
            'dialogue'=>'Нет проверенного клиентского сообщения в доступных основаниях. Это не доказательство отсутствия диалога.',
            'specific_request'=>'Нет подтверждённого совпадения установленного правила запроса. Другие формулировки остаются неизвестными.',
            'offer_reply'=>'Нет доступной точной связи доставки предложения и последующего текстового ответа. Другие ответы остаются неизвестными.',
        };
    }

    private static function binding(mixed $binding):string
    {
        return match($binding){
            'exact_private_chat_current_profile'=>'точная связь с доступным текущим профилем личного чата MAX',
            'ambiguous'=>'неоднозначная связь; профили не объединены',
            'no_dialogue'=>'нет доступного связанного текущего профиля; отсутствие переписки не доказано',
            default=>self::value($binding),
        };
    }

    private static function reaction(mixed $reaction):string
    {
        return match($reaction){
            'unknown',null=>'неизвестно',
            default=>self::value($reaction),
        };
    }

    private static function nav(string $view,string $label,string $current):string
    {
        return '<a class="button'.($view===$current?' selected':'').'" href="'.self::e(self::url($view,'0')).'"'
            .($view===$current?' aria-current="page"':'').'>'.$label.'</a>';
    }

    private static function url(string $view,string $after):string
    {
        return '/manager/assigned-value.php?'.http_build_query(['view'=>$view,'after'=>$after],'','&',PHP_QUERY_RFC3986);
    }

    private static function cursor(string $cursor):bool
    {
        return preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$cursor)===1;
    }

    private static function value(mixed $value):string
    {
        return is_string($value)||is_int($value)||is_float($value)?(string)$value:'неизвестно';
    }

    private static function e(mixed $value):string
    {
        return htmlspecialchars(self::value($value),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }

    private static function json(mixed $value):string
    {
        if($value===null)return 'неизвестно';
        $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($json)?$json:'неизвестно';
    }

    private static function css():string
    {
        return 'body{margin:0;background:#f5f6f8;color:#182334;font:16px/1.55 system-ui,sans-serif}header,main{max-width:1080px;margin:auto;padding:24px}header{padding-bottom:8px}h1{font-size:clamp(28px,5vw,40px);line-height:1.15}h2{font-size:22px}h3{font-size:19px;overflow-wrap:anywhere}h4{margin:0 0 8px}a{color:#075ab2}nav{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:22px}.button{display:inline-block;border:1px solid #b7c8dc;border-radius:9px;padding:9px 14px;background:white;text-decoration:none}.selected{background:#123f6c;color:white}.notice,.row,.error{padding:20px;border:1px solid #d9e1ea;border-radius:12px;background:white;margin:18px 0}.notice{background:#edf4fb}.notice h2{margin-top:0}.eyebrow,.muted,footer{color:#536275}.eyebrow{font-size:13px;letter-spacing:.04em}.subtotal{display:flex;align-items:center;gap:18px;flex-wrap:wrap}.subtotal strong{font-size:30px}.stages{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr));gap:14px;margin:20px 0}.stage{padding:14px;background:#f6f8fb;border:1px solid #e0e6ee;border-radius:9px}.stage p{margin:8px 0}.awarded{color:#145d39;font-weight:700}.unknown{color:#6a4c12;font-weight:600}.warning{padding-left:12px;border-left:3px solid #a06a18}.error{border-color:#c98282;background:#fff2f2}.empty{padding:18px;background:white}summary{cursor:pointer;font-weight:600}.pageCoverage{overflow-x:auto}.pageCoverage table{width:100%;border-collapse:collapse;font-size:14px}.pageCoverage th,.pageCoverage td{text-align:left;padding:10px;border-bottom:1px solid #d9e1ea;vertical-align:top}.pageCoverage thead{background:#edf4fb}pre,code,dd{overflow-wrap:anywhere;white-space:pre-wrap}pre{font-size:13px;background:#f2f4f7;padding:12px;border-radius:6px}dl{font-size:13px}dt{color:#536275;margin-top:8px}dd{margin:0}footer{font-size:14px;border-top:1px solid #d9e1ea;margin-top:26px;padding-top:12px}a:focus-visible,summary:focus-visible{outline:3px solid #df8b1f;outline-offset:3px}@media(max-width:600px){header,main{padding:16px}.row,.notice,.error{padding:15px}}';
    }
}
