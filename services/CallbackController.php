<?php
require_once __DIR__ . '/IntegrationRegistry.php';

/**
 * Shared callback controller for MAX/Telegram normalized callbacks.
 *
 * The callback payload vocabulary remains backward-compatible with the existing
 * buttons. Platform-specific callback parsing/acknowledgement belongs to the
 * incoming adapters/application layer; this controller owns dialogue behavior.
 */
class CallbackController
{
    public function handle(array $query): bool
    {
        $chatId = $query['from']['id'] ?? 0;
        $q = (string)($query['data'] ?? '');
        if (!$chatId || $q === '') return false;

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
            if ($q === 'back_pick_country') {
                MaxSearchApi::deletePrevMessage($chatId, true);
            } else {
                $city = str_replace('pick_city_', '', $q);
                MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusCityChoose, $city);
            }
            MaxSearchApi::showCountryButtons($chatId);
            return true;
        }

        if ($q === 'pick_country_other') {
            MaxSearchApi::deletePrevMessage($chatId);
            $buttons = [[['text'=>'← Назад','callback_data'=>'back_pick_country']]];
            $text = "🌍 <b>Введите страну</b>\n\nНапишите название направления, которое хотите рассмотреть.";
            IntegrationRegistry::messenger()->sendWithButtons($chatId, $text, $buttons);
            MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusContryChoose);
            return true;
        }

        if (strpos($q, 'pick_country_') === 0 || $q === 'back_adults') {
            if ($q === 'back_adults') {
                MaxSearchApi::deletePrevMessage($chatId, true);
            } else {
                MaxSearchApi::funnelLog($chatId, 'country_selected', ['payload'=>$q]);
                $country = str_replace('pick_country_', '', $q);
                MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusContryChoose, $country);
                if (MaxSearchApi::finishEditIfNeeded($chatId, 'country')) return true;
            }
            MaxSearchApi::showAdultsButtons($chatId);
            return true;
        }

        if (strpos($q, 'adults_') === 0 || $q === 'back_child') {
            if (strpos($q, 'adults_') === 0) {
                MaxSearchApi::funnelLog($chatId, 'tourists_selected', ['stage'=>'adults','payload'=>$q]);
            }
            if ($q === 'back_child') {
                MaxSearchApi::deletePrevMessage($chatId, true);
            } else {
                MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusAdults, str_replace('adults_', '', $q));
            }
            MaxSearchApi::showChildButtons($chatId);
            return true;
        }

        if (strpos($q, 'child_') === 0 || $q === 'back_stars') {
            if (strpos($q, 'child_') === 0) {
                MaxSearchApi::funnelLog($chatId, 'tourists_selected', ['stage'=>'children','payload'=>$q]);
            }
            if ($q === 'back_stars') {
                MaxSearchApi::deletePrevMessage($chatId, true);
                MaxSearchApi::showStarsButtons($chatId);
                return true;
            }
            $child = str_replace('child_', '', $q);
            MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusChild, $child);
            if ((int)$child === 0) {
                if (!MaxSearchApi::finishEditIfNeeded($chatId, 'tourists')) MaxSearchApi::showStarsButtons($chatId);
            } else {
                MaxSearchApi::showAgeButtons($chatId, (int)$child);
            }
            return true;
        }

        if (strpos($q, 'star_') === 0 || $q === 'back_meal') {
            if ($q === 'back_meal') {
                MaxSearchApi::deletePrevMessage($chatId, true);
            } else {
                MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusStars, str_replace('star_', '', $q));
                if (MaxSearchApi::finishEditIfNeeded($chatId, 'stars')) return true;
            }
            MaxSearchApi::showMealButtons($chatId);
            return true;
        }

        if (strpos($q, 'meal_') === 0 || $q === 'back_nights') {
            if ($q === 'back_nights') {
                MaxSearchApi::deletePrevMessage($chatId, true);
            } else {
                MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusMeal, str_replace('meal_', '', $q));
                if (MaxSearchApi::finishEditIfNeeded($chatId, 'meal')) return true;
            }
            MaxSearchApi::showNightsButtons($chatId);
            return true;
        }

        if ($q === 'nights_other') {
            MaxSearchApi::deletePrevMessage($chatId);
            $buttons = [[['text'=>'← Назад','callback_data'=>'back_nights']]];
            $text = "🌙 <b>Введите количество ночей</b>\n\nНапример: 7 или диапазон 7-10.";
            IntegrationRegistry::messenger()->sendWithButtons($chatId, $text, $buttons);
            MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusNights);
            return true;
        }

        if (strpos($q, 'nights_') === 0 || $q === 'back_calendar') {
            if ($q === 'back_calendar') {
                MaxSearchApi::deletePrevMessage($chatId, true);
            } else {
                $nights = str_replace('_', '-', str_replace('nights_', '', $q));
                MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusNights, $nights);
                if (MaxSearchApi::finishEditIfNeeded($chatId, 'nights')) return true;
            }
            MaxSearchApi::showCalendarButtons($chatId, date('m'), date('Y'));
            return true;
        }

        if (strpos($q, 'pick_date_') === 0 || $q === 'back_check') {
            if ($q === 'back_check') {
                MaxSearchApi::deletePrevMessage($chatId, true);
            } else {
                MaxSearchApi::saveLastValue($chatId, MaxSearchApi::$statusDate, str_replace('pick_date_', '', $q));
                if (MaxSearchApi::finishEditIfNeeded($chatId, 'date')) return true;
            }
            MaxSearchApi::showCheckButtons($chatId);
            return true;
        }

        if (strpos($q, 'month_change_') === 0) {
            $monthYear = str_replace('month_change_', '', $q);
            if ($monthYear !== '') {
                $arr = explode('.', $monthYear);
                if (count($arr) >= 2) MaxSearchApi::showCalendarButtons($chatId, $arr[0], $arr[1]);
            }
            return true;
        }

        $editMap = [
            'edit_city'=>['city','showCityButtons'],
            'edit_country'=>['country','showCountryButtons'],
            'edit_tourists'=>['tourists','showAdultsButtons'],
            'edit_stars'=>['stars','showStarsButtons'],
            'edit_meal'=>['meal','showMealButtons'],
            'edit_nights'=>['nights','showNightsButtons'],
        ];
        if ($q === 'edit_params') {
            MaxSearchApi::cancelToursFollowup($chatId);
            MaxSearchApi::showEditParamsButtons($chatId);
            return true;
        }
        if (isset($editMap[$q])) {
            [$mode,$method] = $editMap[$q];
            MaxSearchApi::setEditMode($chatId, $mode);
            MaxSearchApi::$method($chatId);
            return true;
        }
        if ($q === 'edit_date') {
            MaxSearchApi::setEditMode($chatId, 'date');
            MaxSearchApi::showCalendarButtons($chatId, date('m'), date('Y'));
            return true;
        }

        if ($q === 'show_tours' || strpos($q, 'finish') === 0) {
            MaxSearchApi::showToursChoice($chatId, self::userName($query));
            return true;
        }

        if ($q === 'manager_request' || $q === 'manager_after_tours') {
            $afterTours = $q === 'manager_after_tours';
            MaxSearchApi::funnelLog($chatId, 'manager_request', ['source'=>$afterTours ? 'followup' : 'before_site']);
            if ($afterTours) MaxSearchApi::cancelToursFollowup($chatId);
            MaxSearchApi::queueMetrikaGoal($chatId, 'max_manager_request');
            MaxSearchApi::showManagerRequest($chatId, self::userName($query), $afterTours);
            return true;
        }

        if ($q === 'tours_checked') {
            MaxSearchApi::showAfterToursQuestion($chatId);
            return true;
        }
        if ($q === 'tours_found') {
            MaxSearchApi::funnelLog($chatId, 'tours_found');
            MaxSearchApi::cancelToursFollowup($chatId);
            MaxSearchApi::showChannelOffer($chatId, false);
            return true;
        }

        if ($q === 'phone_manual') {
            MaxSearchApi::deletePrevMessage($chatId);
            $buttons = [[['text'=>'← Назад','callback_data'=>'tours_checked']]];
            $text = "📱 <b>Введите номер телефона</b>\n\nНапример: +71234567890";
            IntegrationRegistry::messenger()->sendWithButtons($chatId, $text, $buttons);
            MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusPhone);
            return true;
        }

        if ($q === 'restart') {
            MaxSearchApi::deletePrevMessage($chatId, true);
            MaxSearchApi::deleteAllStatus($chatId);
            MaxSearchApi::showStart($chatId);
            return true;
        }
        if ($q === 'back_phone') {
            MaxSearchApi::deletePrevMessage($chatId, true);
            MaxSearchApi::deleteAllStatus($chatId);
            return true;
        }

        return false;
    }

    public static function userName(array $query): string
    {
        $from = (array)($query['from'] ?? []);
        $name = trim((string)($from['first_name'] ?? ''));
        $last = trim((string)($from['last_name'] ?? ''));
        if ($last !== '') $name = trim($name . ' ' . $last);
        if ($name === '') $name = trim((string)($from['username'] ?? ''));
        return $name;
    }

    public static function family(string $payload): string
    {
        if ($payload === 'ai_start') return 'ai';
        if ($payload === 'start_search' || strpos($payload, 'pick_') === 0 || strpos($payload, 'adults_') === 0 || strpos($payload, 'child_') === 0 || strpos($payload, 'star_') === 0 || strpos($payload, 'meal_') === 0 || strpos($payload, 'nights_') === 0 || strpos($payload, 'month_change_') === 0 || strpos($payload, 'back_') === 0) return 'wizard';
        if (strpos($payload, 'edit_') === 0) return 'edit';
        if (in_array($payload, ['manager_request','manager_after_tours','phone_manual'], true)) return 'manager';
        if (in_array($payload, ['show_tours','tours_checked','tours_found'], true) || strpos($payload, 'finish') === 0) return 'tours';
        if ($payload === 'restart') return 'restart';
        return 'unknown';
    }
}
