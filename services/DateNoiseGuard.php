<?php

/**
 * Guards destination inference from ordinary Russian date/month words.
 * These words can appear in hotel names and must never become a tourist area.
 */
class DateNoiseGuard
{
    public static function isMonthWord($token)
    {
        $token = function_exists('mb_strtolower')
            ? mb_strtolower(trim((string)$token), 'UTF-8')
            : strtolower(trim((string)$token));
        $token = str_replace('ё', 'е', $token);

        static $months = [
            'январь','января','январе',
            'февраль','февраля','феврале',
            'март','марта','марте',
            'апрель','апреля','апреле',
            'май','мая','мае',
            'июнь','июня','июне',
            'июль','июля','июле',
            'август','августа','августе',
            'сентябрь','сентября','сентябре',
            'октябрь','октября','октябре',
            'ноябрь','ноября','ноябре',
            'декабрь','декабря','декабре',
        ];

        return in_array($token, $months, true);
    }
}
