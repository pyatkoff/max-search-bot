<?php
declare(strict_types=1);

/** Pure administrator-only presentation of already-authorized aggregate data. */
final class ModeledConversionAcquisitionStatsView
{
    private const BINS=[
        'under_1h'=>'До часа','from_1h_to_1d'=>'От часа до суток',
        'from_1d_to_7d'=>'От суток до 7 дней','from_7d_to_30d'=>'От 7 до 30 дней',
        'over_30d'=>'Больше 30 дней',
    ];
    private const EXCLUDED=[
        'join_missing'=>'Дата вступления неизвестна',
        'join_after_inquiry'=>'Обращение раньше известного вступления',
        'join_ambiguous'=>'Несколько подходящих вступлений',
        'identity_ambiguous'=>'Неоднозначная связь человека и канала',
        'membership_unavailable'=>'Источник вступлений пока не подключён',
    ];
    private const ENTRY_COUNTS=[
        'prior_observed'=>'Вход не позже обращения',
        'ambiguous'=>'Неоднозначная связь',
        'late_only'=>'Только более поздний вход',
        'no_data'=>'Нет подходящего наблюдения',
    ];

    public static function render(?array $page,array $input=[],bool $unavailable=false):string
    {
        $filters=is_array($page['filters']??null)?$page['filters']:$input;
        $date=is_string($filters['date']??null)&&preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$filters['date'])===1?$filters['date']:'';
        $period=in_array($filters['period']??null,[7,30,90],true)?$filters['period']:30;
        $platform=in_array($filters['platform']??null,['all','max','telegram'],true)?$filters['platform']:'all';
        $ok=!$unavailable&&self::valid($page);
        $html='<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>От вступления до обращения · AnyTour</title><style>'.self::css().'</style></head><body>'
            .'<a class="skip" href="#acquisition">К статистике</a><header class="top"><a class="brand" href="/manager2/admin.php" aria-label="AnyTour — администрирование">Any<span>Tour</span></a>'
            .'<a class="back" href="/manager2/admin.php">← Администрирование</a></header><main>'
            .'<div class="intro"><div><p class="eyebrow">Рекламная аналитика · доступ администратора</p><h1>От вступления до обращения</h1>'
            .'<p class="lead">Первое зафиксированное содержательное обращение в доступной истории.</p></div><span class="private">Закрытый просмотр</span></div>'
            .'<nav aria-label="Разделы аналитики"><a href="/manager/assigned-value.php?view=delivery">← Начисления и мониторинг</a></nav>'
            .'<section id="acquisition" aria-labelledby="cohortTitle"><div class="section-heading"><h2 id="cohortTitle">Обращения за период</h2>'
            .'<p class="muted">Период определяется по времени обращения. Все даты и время — Москва (МСК).</p></div>'
            .self::filters($date,$period,$platform);
        if(!$ok){
            $html.='<div class="notice error" role="status"><h3>Статистика сейчас недоступна</h3><p>Не удалось получить полную выборку в пределах проверки. Это не ноль обращений. Обновите страницу позже.</p></div>';
        }else{
            $html.='<div class="notice"><h3>Что входит в расчёт</h3><p>Считаем людей с первым зафиксированным содержательным обращением за выбранный период. Один человек учитывается один раз внутри своего канала; MAX и Telegram не объединяются.</p>'
                .'<p>Это доступная сохранённая история, а не полный архив и не все подписчики. Запуск бота и рекламный визит не считаются вступлением. Доля всех подписчиков, которые обратились, пока не рассчитана.</p></div>'
                .'<div class="channel-grid">';
            foreach(['max'=>'MAX','telegram'=>'Telegram']as$key=>$label)
                if($platform==='all'||$platform===$key)$html.=self::card($key,$label,$page['platforms'][$key]);
            $html.='</div><p class="asof">Данные проверены: '.self::moment($page['as_of']??null).'.</p>';
            $sources=$page['sources'];
            $html.='<details class="source-details"><summary>Охват сохранённых данных</summary>'
                .'<p>Проверено сохранённых профилей: '.self::integer($sources['profiles_examined']??null).'. Полнота более ранней истории не подтверждена.</p>'
                .'<p>Даты сохранённых наблюдений: '.self::moment($sources['first_profile_at']??null).' — '.self::moment($sources['last_profile_at']??null).'. Это даты сохранения; для длительности используется время самого обращения.</p>'
                .'<p>Время вступления MAX взято из доступных сохранённых событий. Это не полный список вступивших. Повторные вступления и неоднозначные связи не превращаются в нулевую задержку.</p>'
                .'<p>Нет данных о вступлении или выходе — значит соответствующая дата неизвестна. Отсутствие выхода не доказывает непрерывную подписку.</p></details>';
        }
        return $html.'</section><footer><p>Время до обращения рассчитано только для однозначных пар дат, где вступление произошло не позже обращения. Неизвестные даты исключены из длительности и показаны отдельно.</p>'
            .'<p>История подписок Telegram будет подключена из Bitrix. Визиты tgmini и запуск бота не заменяют дату вступления.</p></footer></main></body></html>';
    }

    private static function valid(?array $page):bool
    {
        if(($page['available']??null)!==true||($page['schema_version']??null)!==1
            ||($page['meaning']??null)!=='first_observed_substantive_inquiry_not_first_ever'||($page['window']['timezone']??null)!=='Europe/Moscow'
            ||!is_array($page['platforms']??null)||!is_array($page['sources']??null))return false;
        foreach(['max','telegram']as$key){
            $p=$page['platforms'][$key]??null;
            if(!is_array($p)||!self::count($p['inquiries']??null)||!self::count($p['valid_pairs']??null)
                ||$p['valid_pairs']>$p['inquiries']||!is_array($p['bins']??null)||!is_array($p['excluded']??null)
                ||($p['membership_status']??null)!==($key==='max'?'saved_max_events_only':'unavailable_pending_bitrix'))return false;
            $sum=0;
            foreach(self::BINS as$bin=>$_){if(!self::count($p['bins'][$bin]??null))return false;$sum+=$p['bins'][$bin];}
            if($sum!==$p['valid_pairs'])return false;
            $excluded=0;
            foreach(self::EXCLUDED as$reason=>$_){if(!self::count($p['excluded'][$reason]??null))return false;$excluded+=$p['excluded'][$reason];}
            if($excluded+$p['valid_pairs']!==$p['inquiries'])return false;
            foreach(['median_seconds','p90_seconds']as$field){
                $v=$p[$field]??null;
                if($p['valid_pairs']===0){if($v!==null)return false;}
                elseif(!is_int($v)||$v<0||$v>3155760000)return false;
            }
            if($p['valid_pairs']>0&&$p['p90_seconds']<$p['median_seconds'])return false;
            if($key==='telegram'&&$p['valid_pairs']!==0)return false;
            if(!self::validEntry($p['entry_context']??null,$key,$p['inquiries']))return false;
        }
        return true;
    }

    private static function validEntry(mixed $entry,string $platform,int $inquiries):bool
    {
        if(!is_array($entry)||!self::keys($entry,['status','basis','counts']))return false;
        if($platform==='max')return $entry['status']==='not_supported'
            &&$entry['basis']==='max_identity_mapping_not_visit'&&$entry['counts']===null;
        if($entry['status']!=='available'||$entry['basis']!=='verified_tgmini_identity_observation'
            ||!is_array($entry['counts'])||!self::keys($entry['counts'],array_keys(self::ENTRY_COUNTS)))return false;
        $sum=0;
        foreach(self::ENTRY_COUNTS as$key=>$_){if(!self::count($entry['counts'][$key]))return false;$sum+=$entry['counts'][$key];}
        return $sum===$inquiries;
    }
    private static function keys(array $value,array $expected):bool
    {return count($value)===count($expected)&&array_diff(array_keys($value),$expected)===[];}

    private static function filters(string $date,int $period,string $platform):string
    {
        $html='<form class="filters" method="get" action="/manager/assigned-value.php"><input type="hidden" name="view" value="acquisition">'
            .'<label>По какой день<input name="date" type="date" value="'.self::e($date).'" required></label><label>Период<select name="period">';
        foreach([7=>'7 дней',30=>'30 дней',90=>'90 дней']as$value=>$label)$html.='<option value="'.$value.'"'.($period===$value?' selected':'').'>'.$label.'</option>';
        $html.='</select></label><label>Канал<select name="platform">';
        foreach(['all'=>'MAX и Telegram','max'=>'MAX','telegram'=>'Telegram']as$value=>$label)$html.='<option value="'.$value.'"'.($platform===$value?' selected':'').'>'.$label.'</option>';
        return $html.'</select></label><button type="submit">Показать</button></form>';
    }

    private static function card(string $key,string $label,array $p):string
    {
        $n=$p['inquiries'];$pairs=$p['valid_pairs'];$telegram=$key==='telegram';
        $html='<article class="channel-card" id="channel-'.$key.'" aria-labelledby="title-'.$key.'"><div class="channel-title"><h3 id="title-'.$key.'">'.$label.'</h3>'
            .'<span class="source-badge">'.($telegram?'Ожидается источник дат':'Сохранённые вступления').'</span></div>'
            .'<div class="inquiry-count"><strong>'.self::integer($n).'</strong><span>человек с содержательным обращением</span></div>';
        if($n===0)$html.='<p class="empty">В доступных данных за выбранный период таких обращений нет.</p>';
        if($telegram){
            $html.='<div class="unknown"><h4>Время до обращения пока неизвестно</h4><p>Даты вступления подключим из Bitrix.</p><p>Обращения Telegram учтены. Без даты вступления длительность и доля связанных дат не рассчитываются.</p></div>';
        }else{
            $coverage=$n>0?number_format(100*$pairs/$n,1,',',' ').'%':'не рассчитывается';
            $html.='<div class="coverage"><h4>Есть подходящие даты вступления</h4><p><strong>'.self::integer($pairs).' из '.self::integer($n).'</strong><span>'.$coverage.'</span></p>'
                .'<small>Доля обращений с однозначной парой дат. Это не конверсия всех подписчиков.</small></div>';
            if($pairs>0){
                $html.='<dl class="timing"><div><dt>Медиана</dt><dd>'.self::duration($p['median_seconds']).'</dd><small>Половина обращений — не позже этого времени</small></div>'
                    .'<div><dt>90-й процентиль</dt><dd>'.self::duration($p['p90_seconds']).'</dd><small>90% обращений с известными датами — не позже</small></div></dl>'
                    .'<h4>Сколько времени прошло</h4><table class="distribution"><caption>Только обращения с подходящими датами: '.self::integer($pairs).'</caption><tbody>';
                foreach(self::BINS as$bin=>$binLabel)$html.='<tr><th scope="row">'.$binLabel.'</th><td>'.self::integer($p['bins'][$bin]).'</td></tr>';
                $html.='</tbody></table>';
            }else{
                $html.='<p class="unknown">Нет подходящих пар дат для расчёта времени. Это не нулевая длительность.</p>';
            }
            $reasons='';
            foreach(self::EXCLUDED as$reason=>$reasonLabel)if($p['excluded'][$reason]>0)$reasons.='<li><span>'.$reasonLabel.'</span><strong>'.self::integer($p['excluded'][$reason]).'</strong></li>';
            if($reasons!=='')$html.='<div class="exclusions"><h4>Почему время не рассчитано</h4><ul>'.$reasons.'</ul></div>';
        }
        return $html.self::entryContext($key,$p['entry_context']).'</article>';
    }

    private static function entryContext(string $platform,array $entry):string
    {
        $html='<section class="entry-context" aria-labelledby="entry-'.$platform.'"><h4 id="entry-'.$platform.'">Вход до обращения</h4>';
        if($platform==='max')return $html.'<p>Связь пользователя MAX с ClientID сама по себе не подтверждает предшествующий визит. В этом отчёте ранний вход пока не определён.</p></section>';
        $html.='<p>Наблюдения входа в Mini App сопоставлены по подтверждённому Telegram-пользователю и ClientID. Это не подтверждение визита на внешний сайт или рекламного клика.</p>'
            .'<dl class="entry-counts">';
        foreach(self::ENTRY_COUNTS as$key=>$label)$html.='<div><dt>'.$label.'</dt><dd>'.self::integer($entry['counts'][$key]).'</dd></div>';
        return $html.'</dl><p>«Не позже» включает ту же секунду: порядок событий внутри секунды неизвестен. Один подходящий ClientID не означает один визит.</p>'
            .'<p>Это те же люди с обращением, а не все посетители; конверсия из визитов не рассчитывается. Нет наблюдения — не значит, что человек бот или визита не было.</p></section>';
    }

    private static function count(mixed $v):bool{return is_int($v)&&$v>=0&&$v<=50000;}
    private static function integer(mixed $v):string{return self::count($v)?number_format($v,0,',',' '):'неизвестно';}
    private static function duration(int $seconds):string
    {
        if($seconds<60)return 'меньше минуты';
        if($seconds<3600)return intdiv($seconds,60).' мин.';
        if($seconds<86400)return intdiv($seconds,3600).' ч. '.(intdiv($seconds%3600,60)>0?intdiv($seconds%3600,60).' мин.':'');
        return intdiv($seconds,86400).' дн.'.(intdiv($seconds%86400,3600)>0?' '.intdiv($seconds%86400,3600).' ч.':'');
    }
    private static function moment(mixed $v):string
    {
        if(!is_string($v)||preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D',$v)!==1)return 'неизвестно';
        try{return (new DateTimeImmutable($v))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i:s');}catch(Throwable $ignored){return 'неизвестно';}
    }
    private static function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function css():string
    {
        $entry='.entry-context{margin-top:24px;padding-top:20px;border-top:1px solid #dae1e9}.entry-context p{font-size:13px;color:#586579;margin:8px 0;overflow-wrap:anywhere}.entry-counts{margin:14px 0;font-size:14px}.entry-counts>div{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;padding:9px 0;border-bottom:1px solid #e5eaf0}.entry-counts dt{font-weight:400}.entry-counts dd{margin:0;font-weight:650;white-space:nowrap}';
        return $entry.'body{margin:0;background:#f4f6fa;color:#182334;font:16px/1.55 system-ui,sans-serif}*{box-sizing:border-box}.top,main{max-width:1180px;margin:auto;padding:24px}.top{display:flex;justify-content:space-between;gap:20px;align-items:center}.brand{font-size:23px;font-weight:800;text-decoration:none;color:#182334}.brand span{color:#08786b}.back,a{color:#176255}.back{text-decoration:none;font-size:14px}.intro{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin:12px 0 24px}h1{font-size:clamp(28px,4vw,42px);line-height:1.15;margin:8px 0 14px;letter-spacing:-.02em}h2{font-size:24px;margin:0}h3{font-size:23px;margin:0 0 12px}h4{font-size:16px;margin:0 0 8px}.lead,.muted,.asof{color:#586579}.lead{max-width:720px;margin:0}.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.07em;color:#586579;margin:0}.private,.source-badge{white-space:nowrap;background:#e6eee9;border:1px solid #cbdcd0;border-radius:99px;padding:6px 11px;font-size:12px;color:#355d46}.private{margin-top:8px}nav{margin-bottom:28px;font-size:14px}.section-heading{margin-bottom:18px}.section-heading p{margin:6px 0}.filters{display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px;background:white;padding:20px;border:1px solid #dae1e9;border-radius:14px;margin:18px 0}.filters label{display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:600;color:#485468}.filters input,.filters select,.filters button{font:inherit;min-height:44px;border:1px solid #bdc9d5;border-radius:8px;padding:9px 12px;background:white;color:#182334;max-width:100%}.filters button{background:#12634f;border-color:#12634f;color:white;font-weight:600;padding:10px 24px;cursor:pointer}.notice{background:#edf3f7;border:1px solid #d3e0e9;border-radius:12px;padding:20px;margin:20px 0}.notice h3{font-size:17px;margin:0 0 8px}.notice p{font-size:14px;margin:8px 0}.error{background:#fff1ef;border-color:#e6b5ad}.channel-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-top:24px}.channel-card{padding:24px;background:white;border:1px solid #dae1e9;border-radius:16px;min-width:0}.channel-title{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px}.channel-title h3{margin:0}.source-badge{font-size:11px;padding:4px 8px}.inquiry-count{display:flex;align-items:center;gap:14px;margin:25px 0}.inquiry-count strong{font-size:44px;line-height:1.1;letter-spacing:-.03em}.inquiry-count span{max-width:200px;font-size:14px;color:#586579}.coverage{background:#f3f7f5;border-radius:10px;padding:16px}.coverage p{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;margin:6px 0}.coverage p strong{font-size:22px}.coverage p span{font-size:18px;color:#176255}.coverage small,.timing small{font-size:12px;color:#586579}.timing{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin:24px 0}.timing dt{font-size:13px;color:#586579}.timing dd{font-size:24px;font-weight:700;margin:3px 0 6px;line-height:1.25}.timing small{display:block;line-height:1.4}.distribution{width:100%;border-collapse:collapse;font-size:14px}.distribution caption{text-align:left;color:#586579;font-size:12px;margin-bottom:6px}.distribution th,.distribution td{border-bottom:1px solid #e5eaf0;padding:10px 0;text-align:left}.distribution th{font-weight:400}.distribution td{text-align:right;font-weight:600}.unknown{background:#f7f3e9;border:1px solid #e5d9bd;border-radius:10px;padding:16px;color:#665426;font-size:14px}.unknown h4{font-size:17px}.exclusions{margin-top:24px}.exclusions ul{list-style:none;padding:0;margin:10px 0 0}.exclusions li{display:flex;justify-content:space-between;gap:16px;border-top:1px solid #e5eaf0;padding:10px 0;font-size:13px}.exclusions strong{white-space:nowrap}.empty{font-size:14px;color:#586579}.asof{font-size:12px;margin:18px 0}.source-details{font-size:14px;border-top:1px solid #d8e0e9;margin-top:24px;padding-top:18px}.source-details summary{cursor:pointer;font-weight:600}.source-details p{max-width:900px;color:#586579}footer{border-top:1px solid #d8e0e9;margin-top:30px;padding-top:16px;color:#586579;font-size:12px}footer p{max-width:960px}.skip{position:absolute;left:12px;top:-60px;background:white;padding:8px}.skip:focus{top:8px}a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,summary:focus-visible{outline:3px solid #d38d29;outline-offset:3px}@media(max-width:700px){.top,main{padding:16px}.intro{flex-direction:column;gap:10px}.private{margin:0}.channel-grid{grid-template-columns:1fr}.channel-card{padding:20px}.filters{padding:16px}.filters label{flex:1 1 140px}.filters button{width:100%}.top{align-items:flex-start}.back{max-width:160px;text-align:right}.timing dd{font-size:22px}}';
    }
}
