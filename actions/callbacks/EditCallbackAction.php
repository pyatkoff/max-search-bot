<?php
require_once dirname(__DIR__, 2) . '/services/DialogueView.php';
require_once dirname(__DIR__, 2) . '/services/WizardStepView.php';
require_once dirname(__DIR__, 2) . '/services/EditParamsView.php';
require_once dirname(__DIR__, 2) . '/services/EditFlowService.php';
require_once dirname(__DIR__, 2) . '/services/InteractionGuard.php';

class EditCallbackAction
{
    public static function handles(string $q): bool
    {
        return $q === 'edit_params' || strpos($q, 'edit_') === 0;
    }

    private static function handleEditMenu(int $chatId): bool
    {
        if (InteractionGuard::suppressDuplicateCallback($chatId, 'edit_params', 'edit_menu', 2.0)) {
            if (function_exists('put_log_in')) put_log_in('DUPLICATE_EDIT_MENU_CALLBACK_SKIPPED chat=' . $chatId);
            return true;
        }

        MaxSearchApi::cancelToursFollowup($chatId);
        // Capture before EditParamsView::menu() appends another check-status
        // boundary. Capturing at edit_country/edit_city time can already see
        // an empty saved-data window.
        EditFlowService::captureSnapshot($chatId, true);
        EditParamsView::menu($chatId);
        return true;
    }

    public static function handle(int $chatId, string $q): bool
    {
        if ($q === 'edit_params') {
            return self::handleEditMenu($chatId);
        }

        $map = [
            'edit_city'=>['city','showCityButtons'],
            'edit_country'=>['country','showCountryButtons'],
            'edit_tourists'=>['tourists','showAdultsButtons'],
            'edit_stars'=>['stars','showStarsButtons'],
        ];
        if (isset($map[$q])) {
            [$mode, $method] = $map[$q];
            EditFlowService::begin($chatId, $mode);
            call_user_func(['MaxSearchApi', $method], $chatId);
            return true;
        }

        if ($q === 'edit_meal') {
            EditFlowService::begin($chatId, 'meal');
            WizardStepView::meal($chatId);
            return true;
        }

        if ($q === 'edit_nights') {
            EditFlowService::begin($chatId, 'nights');
            WizardStepView::nights($chatId);
            return true;
        }

        if ($q === 'edit_date') {
            EditFlowService::begin($chatId, 'date');
            DialogueView::calendar($chatId, date('m'), date('Y'));
            return true;
        }

        return false;
    }
}
