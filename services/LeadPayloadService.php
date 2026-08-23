<?php

class LeadPayloadService
{
    public static function peopleString(array $claim)
    {
        $text = 'Взрослых: ' . (int)($claim['UF_ADULTS'] ?? 0);
        $children = (int)($claim['UF_CHILD'] ?? 0);
        if ($children > 0) {
            $text .= '; Детей: ' . $children . '(' . (string)($claim['UF_AGE'] ?? '') . ')';
        }
        return $text;
    }

    public static function mealString(array $claim, array $mealMap)
    {
        $meal = $claim['UF_MEAL'] ?? '';
        if ((string)$meal === '999') return 'любое';
        $value = (string)($mealMap[(string)$meal] ?? '');
        if ($value === '') return '';
        return function_exists('ToLower') ? ToLower($value) : (function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value));
    }

    public static function comments(array $data)
    {
        return [
            'Имя: ' . (string)($data['name'] ?? ''),
            'Телефон: ' . (string)($data['phone'] ?? ''),
            'Город вылета: ' . (string)($data['from'] ?? ''),
            'Страна: ' . (string)($data['country'] ?? ''),
            'Туристы: ' . (string)($data['people'] ?? ''),
            'Категория отеля: ' . (string)($data['stars'] ?? '') . '*',
            'Питание: ' . (string)($data['meal'] ?? ''),
            'Даты вылета: ' . (string)($data['dates'] ?? ''),
            'Количество ночей: ' . (string)($data['nights'] ?? ''),
        ];
    }

    public static function properties(array $data)
    {
        $comments = self::comments($data);
        $props = [
            'NAME' => (string)($data['name'] ?? ''),
            'DATE' => (string)($data['created_at'] ?? ''),
            'PHONE' => (string)($data['clean_phone'] ?? ''),
            'DEPARTURE' => (string)($data['from'] ?? ''),
            'COUNTRY' => (string)($data['country'] ?? ''),
            'PEOPLE' => (string)($data['people'] ?? ''),
            'MEAL' => (string)($data['meal'] ?? ''),
            'NIGHTS' => (string)($data['nights'] ?? ''),
            'COMMENTS' => implode('; ', $comments),
        ];
        if (!empty($data['status'])) $props['STATUS'] = $data['status'];
        if (!empty($data['source'])) $props['SOURCE'] = $data['source'];
        if (array_key_exists('is_anytour_online', $data)) $props['IS_ANYTOUR_ONLINE'] = $data['is_anytour_online'];
        return $props;
    }

    public static function iblockElement(array $data)
    {
        return [
            'IBLOCK_ID' => (int)($data['iblock_id'] ?? 0),
            'IBLOCK_SECTION_ID' => (int)($data['section_id'] ?? 0),
            'PROPERTY_VALUES' => (array)($data['properties'] ?? []),
            'NAME' => 'Заявка от ' . (string)($data['created_at'] ?? ''),
            'ACTIVE' => 'Y',
        ];
    }
}
