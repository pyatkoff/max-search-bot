<?php
require_once dirname(__DIR__, 2) . '/services/DialogueView.php';
require_once dirname(__DIR__, 2) . '/services/WizardStepView.php';
require_once dirname(__DIR__, 2) . '/services/EditFlowService.php';
require_once dirname(__DIR__, 2) . '/services/InteractionGuard.php';
require_once dirname(__DIR__, 2) . '/services/ExistingWizardStepApplicationService.php';

class WizardCallbackAction
{
    public static function handles(string $q): bool
    {
        return $q === 'ai_start'
            || $q === 'start_search'
            || strpos($q, 'pick_') === 0
            || strpos($q, 'adults_') === 0
            || strpos($q, 'child_') === 0
            || strpos($q, 'star_') === 0
            || strpos($q, 'meal_') === 0
            || strpos($q, 'nights_') === 0
            || strpos($q, 'month_change_') === 0
            || (strpos($q, 'back_') === 0 && $q !== 'back_phone');
    }

    private static function handleDateSelection(int $chatId, string $q): bool
    {
        return InteractionGuard::runExpectedStatusCallback(
            $chatId,
            $q,
            'date_selection',
            (int)MaxSearchApi::$statusDate,
            static function () use ($chatId, $q): bool {
                MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusDate, str_replace('pick_date_', '', $q));
                if (EditFlowService::finishIfNeeded($chatId, 'date')) return true;
                DialogueView::check($chatId);
                return true;
            },
            static function (int $currentStatus) use ($chatId, $q): void {
                if (function_exists('put_log_in')) put_log_in('STALE_DATE_CALLBACK_SKIPPED chat=' . $chatId . ' payload=' . $q . ' status=' . $currentStatus);
            }
        );
    }

    public static function expectedStatusForForwardCallback(string $q): ?int
    {
        return InteractionGuard::expectedWizardStatus($q);
    }

    private static function staleForwardCallback(int $chatId, string $q): bool
    {
        return InteractionGuard::isStaleWizardForward($chatId, $q);
    }

    private static function handleMonthChange(int $chatId, string $q): bool
    {
        return InteractionGuard::runExpectedStatusReplacementCallback(
            $chatId,
            $q,
            'month_change',
            (int)MaxSearchApi::$statusDate,
            10.0,
            0.75,
            static function (callable $accept) use ($chatId, $q): bool {
                $monthYear = str_replace('month_change_', '', $q);
                $arr = explode('.', $monthYear);
                if (count($arr) >= 2 && $arr[0] !== '' && $arr[1] !== '') {
                    $accept();
                    DialogueView::calendar($chatId, $arr[0], $arr[1]);
                }
                return true;
            },
            static function (string $reason, string $previousPayload, int $currentStatus, int $expectedStatus) use ($chatId, $q): void {
                if (!function_exists('put_log_in')) return;
                if ($reason === 'stale_state') {
                    put_log_in('STALE_MONTH_CHANGE_CALLBACK_SKIPPED chat=' . $chatId . ' payload=' . $q . ' status=' . $currentStatus);
                } elseif ($reason === 'duplicate') {
                    put_log_in('DUPLICATE_MONTH_CHANGE_CALLBACK_SKIPPED chat=' . $chatId . ' payload=' . $q);
                } elseif ($reason === 'rapid_replacement') {
                    put_log_in('RAPID_MONTH_CHANGE_CALLBACK_SKIPPED chat=' . $chatId . ' previous=' . $previousPayload . ' payload=' . $q);
                }
            }
        );
    }

    public static function handle(int $chatId, string $q): bool
    {
        if (self::expectedStatusForForwardCallback($q) !== null) {
            return InteractionGuard::synchronized($chatId, 'wizard.forward', static function() use ($chatId, $q): bool {
                if (self::staleForwardCallback($chatId, $q)) return true;
                return self::handleUnlocked($chatId, $q);
            });
        }
        return self::handleUnlocked($chatId, $q);
    }

    private static function handleUnlocked(int $chatId, string $q): bool
    {
        if ($q === 'ai_start') {
            MaxSearchApi::funnelLog($chatId, 'ai_start');
            MaxSearchApi::deletePrevMessage($chatId);
            MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusStart);
            MaxSearchApi::showAiStart($chatId);
            return true;
        }

        if ($q === 'start_search' || $q === 'back_pick_city') {
            if ($q === 'start_search') {
                MaxSearchApi::funnelLog($chatId, 'start_search');
                MaxSearchApi::deletePrevMessage($chatId);
                MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusStart);
            } else {
                MaxSearchApi::deletePrevMessage($chatId, true);
            }
            MaxSearchApi::showCityButtons($chatId);
            return true;
        }

        if (strpos($q, 'pick_city_') === 0 || $q === 'back_pick_country') {
            if ($q === 'pick_city_other') {
                MaxSearchApi::showCityOtherButtons($chatId);
                return true;
            }
            if ($q === 'back_pick_country') MaxSearchApi::deletePrevMessage($chatId, true);
            else {
                MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusCityChoose, str_replace('pick_city_', '', $q));
                if (EditFlowService::finishIfNeeded($chatId, 'city')) return true;
            }
            MaxSearchApi::showCountryButtons($chatId);
            return true;
        }

        if ($q === 'pick_country_other') return DialogueView::manualCountry($chatId);

        if (strpos($q, 'pick_country_') === 0 || $q === 'back_adults') {
            if ($q === 'back_adults') MaxSearchApi::deletePrevMessage($chatId, true);
            else {
                MaxSearchApi::funnelLog($chatId, 'country_selected', ['payload'=>$q]);
                MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusContryChoose, str_replace('pick_country_', '', $q));
                if (EditFlowService::finishIfNeeded($chatId, 'country')) return true;
            }
            MaxSearchApi::showAdultsButtons($chatId);
            return true;
        }

        if (strpos($q, 'adults_') === 0 || $q === 'back_child') {
            if (strpos($q, 'adults_') === 0) MaxSearchApi::funnelLog($chatId, 'tourists_selected', ['stage'=>'adults','payload'=>$q]);
            if ($q === 'back_child') MaxSearchApi::deletePrevMessage($chatId, true);
            else {
                $adults = str_replace('adults_', '', $q);
                if (!ExistingWizardStepApplicationService::apply(
                    $chatId,
                    MaxSearchApi::$statusAdults,
                    $adults
                )) return true;
            }
            MaxSearchApi::showChildButtons($chatId);
            return true;
        }

        if (strpos($q, 'child_') === 0 || $q === 'back_stars') {
            if (strpos($q, 'child_') === 0) MaxSearchApi::funnelLog($chatId, 'tourists_selected', ['stage'=>'children','payload'=>$q]);
            if ($q === 'back_stars') {
                MaxSearchApi::deletePrevMessage($chatId, true);
                MaxSearchApi::showStarsButtons($chatId);
                return true;
            }
            $child = str_replace('child_', '', $q);
            MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusChild, $child);
            if ((int)$child === 0) {
                if (!EditFlowService::finishIfNeeded($chatId, 'tourists')) MaxSearchApi::showStarsButtons($chatId);
            } else MaxSearchApi::showAgeButtons($chatId, (int)$child);
            return true;
        }

        if (strpos($q, 'star_') === 0 || $q === 'back_meal') {
            if ($q === 'back_meal') MaxSearchApi::deletePrevMessage($chatId, true);
            else {
                MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusStars, str_replace('star_', '', $q));
                if (EditFlowService::finishIfNeeded($chatId, 'stars')) return true;
            }
            WizardStepView::meal($chatId);
            return true;
        }

        if (strpos($q, 'meal_') === 0 || $q === 'back_nights') {
            if ($q === 'back_nights') MaxSearchApi::deletePrevMessage($chatId, true);
            else {
                MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusMeal, str_replace('meal_', '', $q));
                if (EditFlowService::finishIfNeeded($chatId, 'meal')) return true;
            }
            WizardStepView::nights($chatId);
            return true;
        }

        if ($q === 'nights_other') return DialogueView::manualNights($chatId);

        if (strpos($q, 'nights_') === 0 || $q === 'back_calendar') {
            if ($q === 'back_calendar') MaxSearchApi::deletePrevMessage($chatId, true);
            else {
                $nights = str_replace('_', '-', str_replace('nights_', '', $q));
                if (!ExistingWizardStepApplicationService::apply(
                    $chatId,
                    MaxSearchApi::$statusNights,
                    $nights
                )) return true;
                if (EditFlowService::finishIfNeeded($chatId, 'nights')) return true;
            }
            DialogueView::calendar($chatId, date('m'), date('Y'));
            return true;
        }

        if (strpos($q, 'pick_date_') === 0) return self::handleDateSelection($chatId, $q);

        if ($q === 'back_check') {
            MaxSearchApi::deletePrevMessage($chatId, true);
            DialogueView::check($chatId);
            return true;
        }

        if (strpos($q, 'month_change_') === 0) return self::handleMonthChange($chatId, $q);

        return false;
    }
}
