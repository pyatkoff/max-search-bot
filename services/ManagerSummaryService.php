<?php

require_once __DIR__ . '/TripBudgetPolicy.php';

class ManagerSummaryService
{
    public static function build(array $state, array $userContext = []): string
    {
        $lines = [];
        $unknown = [];

        $from = trim((string)($state['departure']['city'] ?? ''));
        $to = trim((string)($state['destination']['country'] ?? ''));
        if ($from !== '' || $to !== '') $lines[] = 'Маршрут: ' . ($from !== '' ? $from : 'не указан') . ' → ' . ($to !== '' ? $to : 'не указано');
        if ($from === '') $unknown[] = 'город вылета';
        if ($to === '') $unknown[] = 'направление';

        $dateFrom = trim((string)($state['dates']['from'] ?? ''));
        $dateTo = trim((string)($state['dates']['to'] ?? ''));
        $month = trim((string)($state['dates']['month'] ?? ''));
        if ($dateFrom !== '') $lines[] = 'Вылет: ' . $dateFrom . ($dateTo !== '' && $dateTo !== $dateFrom ? ' — ' . $dateTo : '');
        elseif ($month !== '') $lines[] = 'Период: ' . $month;
        if ($dateFrom === '') $unknown[] = 'дата вылета';

        $nMin = $state['nights']['min'] ?? null;
        $nMax = $state['nights']['max'] ?? null;
        if ($nMin !== null) $lines[] = 'Ночей: ' . ($nMax !== null && $nMax != $nMin ? $nMin . '–' . $nMax : $nMin);
        else $unknown[] = 'количество ночей';

        $adults = $state['tourists']['adults'] ?? null;
        $children = $state['tourists']['children'] ?? null;
        $ages = (array)($state['tourists']['children_ages'] ?? []);
        if ($adults !== null || $children !== null) {
            $people = [];
            if ($adults !== null) $people[] = (int)$adults . ' взр.';
            if ($children !== null) $people[] = (int)$children . ' реб.';
            if ($ages) $people[] = 'возраст: ' . implode(', ', $ages);
            $lines[] = 'Туристы: ' . implode(' + ', $people);
        }
        if ($adults === null && $children === null) {
            $unknown[] = 'состав туристов';
        } elseif ($adults === null) {
            $unknown[] = 'количество взрослых';
        } elseif ($children === null) {
            $unknown[] = 'количество детей';
        } elseif ((int)$children > 0 && count($ages) !== (int)$children) {
            $unknown[] = 'возраст детей';
        }

        if ($unknown !== []) $lines[] = 'Не указано для поиска: ' . implode(', ', $unknown);

        $budget = is_array($state['budget'] ?? null) ? $state['budget'] : [];
        $amount = TripBudgetPolicy::amount($budget['max'] ?? null);
        if ($amount !== null) {
            $currency = TripBudgetPolicy::currency($budget['currency'] ?? 'RUB');
            $basis = TripBudgetPolicy::basis($budget);
            $basisLabel = $basis === 'total' ? 'на всех' : ($basis === 'per_person' ? 'на человека' : '(основание требует уточнения)');
            $precision = floor((float)$amount) == $amount ? 0 : 2;
            $lines[] = 'Бюджет: до ' . number_format((float)$amount, $precision, '.', ' ') . ' '
                . ($currency ?? '(валюта требует уточнения)') . ' ' . $basisLabel;
        } else {
            $lines[] = 'Не указано: бюджет (необязательно; уточнять только если нужен до первого предложения)';
        }
        if (!empty($state['hotel']['stars_min'])) $lines[] = 'Отель: от ' . (int)$state['hotel']['stars_min'] . '★';
        if (!empty($state['hotel']['meal'])) $lines[] = 'Питание: ' . self::mealLabel((string)$state['hotel']['meal']);
        if (!empty($state['preferences'])) $lines[] = 'Пожелания: ' . implode(', ', (array)$state['preferences']);
        if (!empty($state['negative_preferences'])) $lines[] = 'Не подходит: ' . implode(', ', (array)$state['negative_preferences']);

        $tracking = [];
        foreach (['region_id'=>'region','campaign_id'=>'campaign','source'=>'source'] as $key=>$label) {
            if (isset($userContext[$key]) && $userContext[$key] !== '') $tracking[] = $label . '=' . $userContext[$key];
        }
        if ($tracking) $lines[] = 'Источник: ' . implode(', ', $tracking);

        return implode("\n", $lines);
    }

    private static function mealLabel(string $value): string
    {
        $value = trim($value);
        $labels = [
            'any'=>'Любое',
            'all_inclusive'=>'Всё включено',
            'breakfast'=>'Завтрак',
            'half_board'=>'Полупансион',
            'full_board'=>'Полный пансион',
        ];
        return $labels[$value] ?? $value;
    }
}
