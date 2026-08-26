<?php

/**
 * Canonical dialogue state definitions and transition rules.
 *
 * This class is intentionally side-effect free. It describes the wizard states
 * and allowed transitions so handlers/guards can converge on one source of
 * truth without a risky rewrite of the existing conversation flow.
 */
class DialogueStateMachine
{
    public static function statusMap(): array
    {
        return [
            'start' => (int)MaxSearchApi::$statusStart,
            'ai' => (int)MaxSearchApi::$statusAi,
            'city' => (int)MaxSearchApi::$statusCityChoose,
            'country' => (int)MaxSearchApi::$statusContryChoose,
            'adults' => (int)MaxSearchApi::$statusAdults,
            'children' => (int)MaxSearchApi::$statusChild,
            'child_ages' => (int)MaxSearchApi::$statusAge,
            'stars' => (int)MaxSearchApi::$statusStars,
            'meal' => (int)MaxSearchApi::$statusMeal,
            'nights' => (int)MaxSearchApi::$statusNights,
            'date' => (int)MaxSearchApi::$statusDate,
            'check' => (int)MaxSearchApi::$statusCheck,
            'phone' => (int)MaxSearchApi::$statusPhone,
        ];
    }

    public static function stateForStatus(int $status): ?string
    {
        $state = array_search($status, self::statusMap(), true);
        return $state === false ? null : (string)$state;
    }

    public static function statusForState(string $state): ?int
    {
        switch ($state) {
            case 'start': return (int)MaxSearchApi::$statusStart;
            case 'ai': return (int)MaxSearchApi::$statusAi;
            case 'city': return (int)MaxSearchApi::$statusCityChoose;
            case 'country': return (int)MaxSearchApi::$statusContryChoose;
            case 'adults': return (int)MaxSearchApi::$statusAdults;
            case 'children': return (int)MaxSearchApi::$statusChild;
            case 'child_ages': return (int)MaxSearchApi::$statusAge;
            case 'stars': return (int)MaxSearchApi::$statusStars;
            case 'meal': return (int)MaxSearchApi::$statusMeal;
            case 'nights': return (int)MaxSearchApi::$statusNights;
            case 'date': return (int)MaxSearchApi::$statusDate;
            case 'check': return (int)MaxSearchApi::$statusCheck;
            case 'phone': return (int)MaxSearchApi::$statusPhone;
            default: return null;
        }
    }

    /**
     * Canonical forward wizard path. Child ages are conditional and may be
     * skipped when the party has no children.
     */
    public static function forwardTransitions(): array
    {
        return [
            'start' => ['city', 'ai'],
            'city' => ['country'],
            'country' => ['adults'],
            'adults' => ['children'],
            'children' => ['child_ages', 'stars'],
            'child_ages' => ['stars'],
            'stars' => ['meal'],
            'meal' => ['nights'],
            'nights' => ['date'],
            'date' => ['check'],
        ];
    }

    public static function backTransitions(): array
    {
        return [
            'country' => ['city'],
            'adults' => ['country'],
            'children' => ['adults'],
            'child_ages' => ['children'],
            'stars' => ['children', 'child_ages'],
            'meal' => ['stars'],
            'nights' => ['meal'],
            'date' => ['nights'],
            'check' => ['date'],
        ];
    }

    /**
     * Explicit edit states may be opened from the ready/check screen and then
     * return directly to check after the edited value is accepted.
     */
    public static function editableStates(): array
    {
        return ['city', 'country', 'adults', 'children', 'child_ages', 'stars', 'meal', 'nights', 'date'];
    }

    public static function canTransition(string $from, string $to, string $mode = 'forward'): bool
    {
        if ($from === $to) return true;

        if ($mode === 'edit') {
            if ($from === 'check' && in_array($to, self::editableStates(), true)) return true;
            if (in_array($from, self::editableStates(), true) && $to === 'check') return true;
            return false;
        }

        if ($mode === 'reset') {
            return $to === 'start';
        }

        if ($mode === 'back') {
            return in_array($to, self::backTransitions()[$from] ?? [], true);
        }

        if ($from === 'ai') {
            // AI can ask for any still-missing deterministic wizard field or
            // complete the search directly. This preserves current behavior.
            return in_array($to, array_merge(self::editableStates(), ['check']), true);
        }

        return in_array($to, self::forwardTransitions()[$from] ?? [], true);
    }

    /**
     * Map forward callback families to the canonical state in which they are
     * valid. Non-forward/back/edit actions intentionally return null.
     */
    public static function expectedStateForForwardCallback(string $payload): ?string
    {
        if (strpos($payload, 'pick_city_') === 0) return 'city';
        if (strpos($payload, 'pick_country_') === 0) return 'country';
        if (strpos($payload, 'adults_') === 0) return 'adults';
        if (strpos($payload, 'child_') === 0) return 'children';
        if (strpos($payload, 'star_') === 0) return 'stars';
        if (strpos($payload, 'meal_') === 0) return 'meal';
        if (strpos($payload, 'nights_') === 0) return 'nights';
        return null;
    }

    public static function expectedStatusForForwardCallback(string $payload): ?int
    {
        $state = self::expectedStateForForwardCallback($payload);
        return $state === null ? null : self::statusForState($state);
    }
}
