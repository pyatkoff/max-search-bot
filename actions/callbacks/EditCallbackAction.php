<?php
require_once dirname(__DIR__, 2) . '/services/DialogueView.php';
require_once dirname(__DIR__, 2) . '/services/WizardStepView.php';
require_once dirname(__DIR__, 2) . '/services/EditParamsView.php';

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
            EditParamsView::menu($chatId);
            return true;
        }

        $map = [
            'edit_city'=>['city','showCityButtons'],
            'edit_country'=>['country','showCountryButtons'],
            'edit_tourists'=>['tourists','showAdultsButtons'],
            'edit_stars'=>['stars','showStarsButtons'],
        ];
        if (isset($map[$q])) {
            [$mode, $method] = $map[$q];
            MaxSearchApi::setEditMode($chatId, $mode);
            call_user_func(['MaxSearchApi', $method], $chatId);
            return true;
        }

        if ($q === 'edit_meal') {
            MaxSearchApi::setEditMode($chatId, 'meal');
            WizardStepView::meal($chatId);
            return true;
        }

        if ($q === 'edit_nights') {
            MaxSearchApi::setEditMode($chatId, 'nights');
            WizardStepView::nights($chatId);
            return true;
        }

        if ($q === 'edit_date') {
            MaxSearchApi::setEditMode($chatId, 'date');
            DialogueView::calendar($chatId, date('m'), date('Y'));
            return true;
        }

        return false;
    }
}
