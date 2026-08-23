<?php

class EditCallbackAction
{
    public static function handles(string $q): bool
    {
        return $q === 'edit_params' || strpos($q, 'edit_') === 0;
    }

    public static function handle(int $chatId, string $q): bool
    {
        if ($q === 'edit_params') {
            MaxSearchApi::cancelToursFollowup($chatId);
            MaxSearchApi::showEditParamsButtons($chatId);
            return true;
        }

        $map = [
            'edit_city'=>['city','showCityButtons'],
            'edit_country'=>['country','showCountryButtons'],
            'edit_tourists'=>['tourists','showAdultsButtons'],
            'edit_stars'=>['stars','showStarsButtons'],
            'edit_meal'=>['meal','showMealButtons'],
            'edit_nights'=>['nights','showNightsButtons'],
        ];
        if (isset($map[$q])) {
            [$mode, $method] = $map[$q];
            MaxSearchApi::setEditMode($chatId, $mode);
            call_user_func(['MaxSearchApi', $method], $chatId);
            return true;
        }

        if ($q === 'edit_date') {
            MaxSearchApi::setEditMode($chatId, 'date');
            MaxSearchApi::showCalendarButtons($chatId, date('m'), date('Y'));
            return true;
        }

        return false;
    }
}
