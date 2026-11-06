<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionValueRegistryView.php';
require_once __DIR__.'/MaxMemberHistoryViewCursor.php';

/** Pure owner HTML over an authorized, history-only page. No valuation or source IO. */
final class MaxMemberHistoryView
{
    public static function render(?array $page,bool $unavailable=false):string
    {
        $valid=is_array($page)&&($page['basis']??null)==='retained_membership_history_v1'
            &&($page['coverage']??null)==='bounded_retained_history_not_live_platform_verification'
            &&is_array($page['rows']??null)&&array_is_list($page['rows'])&&count($page['rows'])<=5
            &&is_int($page['cutoff_unix']??null)&&$page['cutoff_unix']>=946684800
            &&is_bool($page['has_more']??null)&&is_int($page['scanned_event_count']??null)
            &&$page['scanned_event_count']>=0&&$page['scanned_event_count']<=64
            &&is_int($page['omitted_unknown_count']??null)&&$page['omitted_unknown_count']>=0&&$page['omitted_unknown_count']<=64
            &&is_int($page['omitted_owner_or_test']??null)&&$page['omitted_owner_or_test']>=0&&$page['omitted_owner_or_test']<=5;
        if($valid)foreach($page['rows']as$row){
            $m=is_array($row)?($row['member']??null):null;
            if(!is_array($m)||!is_string($row['display_name']??null)||strlen($row['display_name'])>480
                ||($m['state']??null)!=='observed_subscribed'||!MaxTrackerLookupProtocol::id($m['external_user_id']??null)
                ||!MaxTrackerLookupProtocol::id($m['source_id']??null)||!MaxTrackerLookupProtocol::id($m['last_event_record_id']??null)
                ||!MaxTrackerLookupProtocol::id($m['episode_join_record_id']??null)
                ||!is_int($m['last_event_recorded_at_unix']??null)||!is_int($m['episode_join_recorded_at_unix']??null)
                ||$m['episode_join_recorded_at_unix']<946684800||$m['episode_join_recorded_at_unix']>$m['last_event_recorded_at_unix']
                ||$m['last_event_recorded_at_unix']>$page['cutoff_unix']
                ||!in_array($m['entry_basis']??null,['after_observed_leave','first_retained_join'],true)){$valid=false;break;}
        }
        if($valid&&(($page['has_more']&&(!is_string($page['next_after']??null)||!MaxMemberHistoryViewCursor::shape($page['next_after'])||$page['next_after']==='0'))
            ||(!$page['has_more']&&($page['next_after']??null)!==null)))$valid=false;
        $unavailable=$unavailable||!$valid;
        $title='Подписчики MAX по сохранённой истории';
        $html='<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.$title.' · AnyTour</title><style>'.ModeledConversionValueRegistryView::historyCss().'</style></head><body>'
            .'<header><a href="/manager2/">AnyTour · к диалогам</a><p class="eyebrow">Приватный просмотр · только администратор</p>'
            .'<h1>'.$title.'</h1><p>Показаны пользователи, у которых последнее сохранённое событие по источнику — вступление.</p></header><main>'
            .'<nav aria-label="Разделы конверсий"><a class="button" href="/manager/assigned-value.php?view=delivery">Реестр конверсий</a>'
            .'<a class="button selected" aria-current="page" href="/manager/assigned-value.php?view=members">Подписчики MAX</a>'
            .'<a class="button" href="/manager/assigned-value.php?view=profiles">Диалоги MAX / Telegram</a>'
            .'<a class="button" href="/manager/assigned-value.php?view=acquisition">От вступления до обращения</a></nav>';
        if($unavailable)return$html.'<section class="error" role="status"><h2>История сейчас недоступна</h2>'
            .'<p>Список не получен. По этому результату нельзя судить о числе подписчиков.</p>'
            .'<a class="button" href="/manager/assigned-value.php?view=members">Обновить историю</a></section></main></body></html>';
        $html.='<section class="notice"><h2>Срез на '.self::time($page['cutoff_unix']).'</h2>'
            .'<p>Время по Москве (МСК). Состояние определяется по записанным вступлениям и выходам, отдельно для каждого источника MAX. Проверка текущего состава через MAX API здесь не выполняется.</p>'
            .'<p>Повторное вступление без записанного выхода не меняет начало периода. Переписка для попадания в список не требуется.</p></section>'
            .'<section aria-labelledby="members-title"><h2 id="members-title">Подписки на этой странице</h2>';
        if(!$page['rows'])$html.='<p class="empty">В просмотренном блоке истории подходящих строк нет.'
            .($page['has_more']?' Продолжите просмотр следующего блока.':' Конец доступной истории этого среза.').'</p>';
        foreach($page['rows']as$row){
            $m=$row['member'];$entry=$m['entry_basis']==='after_observed_leave'?'Начало текущего наблюдаемого периода':'Первое сохранённое вступление';
            $html.='<article class="row"><h3>'.self::e($row['display_name']).'</h3>'
                .'<p class="awarded">Подписан по последнему сохранённому событию</p>'
                .'<p class="muted">MAX-пользователь №'.self::e($m['external_user_id']).' · источник №'.self::e($m['source_id']).'</p>'
                .'<dl><dt>'.$entry.'</dt><dd>'.self::time($m['episode_join_recorded_at_unix']).'</dd>'
                .'<dt>Последнее записанное вступление</dt><dd>'.self::time($m['last_event_recorded_at_unix']).'</dd></dl>'
                .'<details><summary>Основания в истории</summary><p>Начало периода: запись №'.self::e($m['episode_join_record_id'])
                .'. Последнее событие: запись №'.self::e($m['last_event_record_id']).'.</p></details></article>';
        }
        $html.='</section><section class="notice"><h2>Охват данных</h2><p>Показано подписок: '.count($page['rows'])
            .'. Просмотрено записей истории в этом блоке: '.$page['scanned_event_count'].'.</p>'
            .'<p>Общее число подписчиков MAX и полнота истории неизвестны. Один пользователь может иметь подписки в нескольких источниках.</p>';
        if($page['omitted_unknown_count']>0)$html.='<p>Не удалось однозначно определить состояние в этом блоке: '.$page['omitted_unknown_count'].'.</p>';
        if($page['omitted_owner_or_test']>0)$html.='<p>Служебные и тестовые записи исключены: '.$page['omitted_owner_or_test'].'.</p>';
        $html.='<p>Данные Telegram показаны в отдельных разделах. Просмотр истории не создаёт начислений и не отправляет конверсии.</p></section>';
        if($page['has_more'])$html.='<p><a class="button" rel="next" href="'.self::e('/manager/assigned-value.php?'.http_build_query(['view'=>'members','after'=>$page['next_after']],'','&',PHP_QUERY_RFC3986)).'">Продолжить просмотр →</a></p>';
        $html.='<p><a href="/manager/assigned-value.php?view=members">Начать новый срез</a></p>';
        return$html.'</main></body></html>';
    }
    private static function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function time(int $unix):string
    {return(new DateTimeImmutable('@'.$unix))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i:s');}
}
