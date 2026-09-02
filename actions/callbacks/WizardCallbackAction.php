<?php
require_once dirname(__DIR__, 2) . '/services/DialogueView.php';
require_once dirname(__DIR__, 2) . '/services/WizardStepView.php';
require_once dirname(__DIR__, 2) . '/services/EditFlowService.php';
require_once dirname(__DIR__, 2) . '/services/InteractionGuard.php';

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

    private static function callbackLockPath(int $chatId, string $suffix = ''): string
    {
        return InteractionGuard::lockPath($chatId, 'date' . ($suffix === '' ? '' : $suffix));
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

    public static function isDuplicateMonthChange(string $previousPayload, float $previousAt, string $payload, float $now, float $windowSeconds = 10.0): bool
    {
        return InteractionGuard::isDuplicate($previousPayload, $previousAt, $payload, $now, $windowSeconds);
    }

    public static function isRapidDifferentMonthChange(string $previousPayload, float $previousAt, string $payload, float $now, float $windowSeconds = 0.75): bool
    {
        return $previousPayload !== ''
            && $previousPayload !== $payload
            && InteractionGuard::isRecent($previousAt, $now, $windowSeconds);
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
        $fp = @fopen(self::callbackLockPath($chatId, '.month'), 'c+');
        if (!$fp || !flock($fp, LOCK_EX)) {
            if ($fp) fclose($fp);
            InteractionGuard::reportSuppressed($chatId, $q, 'concurrent', null, (int)MaxSearchApi::$statusDate, 'month_change');
            return true;
        }

        try {
            $currentStatus = (int)MaxSearchApi::getCurentStatus($chatId);
            if ($currentStatus !== (int)MaxSearchApi::$statusDate) {
                InteractionGuard::reportSuppressed($chatId, $q, 'stale_state', $currentStatus, (int)MaxSearchApi::$statusDate, 'month_change');
                if (function_exists('put_log_in')) put_log_in('STALE_MONTH_CHANGE_CALLBACK_SKIPPED chat=' . $chatId . ' payload=' . $q . ' status=' . $currentStatus);
                return true;
            }

            rewind($fp);
            $state = json_decode((string)stream_get_contents($fp), true);
            $previousPayload = is_array($state) ? (string)($state['payload'] ?? '') : '';
            $previousAt = is_array($state) ? (float)($state['at'] ?? 0) : 0.0;
            $now = microtime(true);
            if (self::isDuplicateMonthChange($previousPayload, $previousAt, $q, $now)) {
                InteractionGuard::reportSuppressed($chatId, $q, 'duplicate', $currentStatus, (int)MaxSearchApi::$statusDate, 'month_change');
                if (function_exists('put_log_in')) put_log_in('DUPLICATE_MONTH_CHANGE_CALLBACK_SKIPPED chat=' . $chatId . ' payload=' . $q);
                return true;
            }
            if (self::isRapidDifferentMonthChange($previousPayload, $previousAt, $q, $now)) {
                InteractionGuard::reportSuppressed($chatId, $q, 'rapid_replacement', $currentStatus, (int)MaxSearchApi::$statusDate, 'month_change');
                if (function_exists('put_log_in')) put_log_in('RAPID_MONTH_CHANGE_CALLBACK_SKIPPED chat=' . $chatId . ' previous=' . $previousPayload . ' payload=' . $q);
                return true;
            }

            $monthYear = str_replace('month_change_', '', $q);
            $arr = explode('.', $monthYear);
            if (count($arr) >= 2 && $arr[0] !== '' && $arr[1] !== '') {
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode(['payload'=>$q, 'at'=>$now], JSON_UNESCAPED_SLASHES));
                fflush($fp);
                DialogueView::calendar($chatId, $arr[0], $arr[1]);
            }
            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
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
            else MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusAdults, str_replace('adults_', '', $q));
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
                MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusNights, $nights);
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
