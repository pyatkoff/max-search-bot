<?php

class AiShortAnswerHandler
{
    public static function handle($message, $chat_id)
    {
        $text = trim((string)($message['text'] ?? ''));
        if ($text === '') return false;

        $missing = MaxSearchApi::getAiMissingFields($chat_id);
        if (empty($missing)) return false;

        // Важно: короткий ответ трактуем только как ответ на ТЕКУЩИЙ вопрос.
        // Поэтому смотрим только первое missing-поле, а не пытаемся угадать всё сообщение.
        $field = (string)$missing[0];
        if (!in_array($field, ['adults','children','child_ages','stars','meal','nights'], true)) {
            return false;
        }

        $lower = function_exists('mb_strtolower')
            ? mb_strtolower($text, 'UTF-8')
            : strtolower($text);
        $lower = trim(preg_replace('/[.!?]+$/u', '', $lower));

        $params = [];

        if ($field === 'adults') {
            $n = self::numberFromShortText($lower, 1, 6);
            if ($n === null && preg_match('/^(\d)\s*(?:взросл(?:ый|ых|ого)?|человек(?:а)?)$/ui', $lower, $m)) {
                $n = (int)$m[1];
            }
            if ($n === null || $n < 1 || $n > 6) return false;
            $params['adults'] = $n;
        }
        elseif ($field === 'children') {
            if (preg_match('/^(?:нет|не будет|без детей|детей нет|без ребёнка|без ребенка|0)$/ui', $lower)) {
                $params['children'] = 0;
            } else {
                $n = self::numberFromShortText($lower, 0, 3);
                if ($n === null && preg_match('/^(\d)\s*(?:реб[её]нок|реб[её]нка|реб[её]нков|дет(?:ей|и))$/ui', $lower, $m)) {
                    $n = (int)$m[1];
                }
                if ($n === null || $n < 0 || $n > 3) return false;
                $params['children'] = $n;
            }
        }
        elseif ($field === 'child_ages') {
            $current = MaxSearchApi::getAiSearchContext($chat_id);
            $childrenCount = (int)($current['children'] ?? 0);
            if ($childrenCount <= 0) return false;

            preg_match_all('/\b(\d{1,2})\b/u', $lower, $m);
            $ages = array_map('intval', $m[1] ?? []);
            foreach ($ages as $age) {
                if ($age < 0 || $age > 17) return false;
            }
            if (count($ages) !== $childrenCount) return false;
            $params['child_ages'] = $ages;
        }
        elseif ($field === 'stars') {
            if (preg_match('/^(?:не важно|неважно|любая|любые|все|всё)$/ui', $lower)) {
                $params['stars'] = 1;
            } elseif (preg_match('/^(?:от\s*)?([1-5])\s*(?:\*|★|зв[её]зд(?:а|ы)?)?$/ui', $lower, $m)) {
                $params['stars'] = (int)$m[1];
            } else {
                $n = self::numberFromShortText($lower, 1, 5);
                if ($n === null) return false;
                $params['stars'] = $n;
            }
        }
        elseif ($field === 'meal') {
            $meal = self::mealFromShortText($lower);
            if ($meal === null) return false;
            $params['meal'] = $meal;
        }
        elseif ($field === 'nights') {
            $normalized = str_replace(['–','—',' '], ['-','-',''], $lower);
            if (preg_match('/^(\d{1,2})(?:-(\d{1,2}))?(?:ноч(?:ь|и|ей))?$/ui', $normalized, $m)) {
                $a = (int)$m[1];
                $b = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : $a;
                if ($a < 1 || $a > 28 || $b < 1 || $b > 28 || $a > $b) return false;
                $params['nights'] = $a === $b ? (string)$a : ($a.'-'.$b);
            } else {
                return false;
            }
        }

        if (empty($params)) return false;

        $applied = MaxSearchApi::applyAiParameters($chat_id, $params);
        if (empty($applied[$field])) return false;

        MaxSearchApi::funnelLog($chat_id, 'ai_short_answer', [
            'field' => $field,
            'text' => function_exists('mb_substr') ? mb_substr($text, 0, 100, 'UTF-8') : substr($text, 0, 100),
            'value' => $params[$field]
        ]);

        $missingAfter = MaxSearchApi::getAiMissingFields($chat_id);
        if (empty($missingAfter)) {
            MaxSearchApi::showCheckButtons($chat_id);
            return true;
        }

        $questions = [
            'city'=>'Из какого города планируете вылет?',
            'country'=>'Куда хотите поехать?',
            'adults'=>'Сколько будет взрослых туристов?',
            'children'=>'Будут дети? Если да — сколько?',
            'child_ages'=>'Сколько лет детям?',
            'stars'=>'Какая минимальная категория отеля нужна — 3, 4 или 5 звёзд?',
            'meal'=>'Какое питание предпочитаете?',
            'nights'=>'На сколько ночей планируете поездку?',
            'date'=>'Какая ориентировочная дата вылета?'
        ];

        MaxSearchApi::setStatus($chat_id, MaxSearchApi::$statusAi);
        MaxSearchApi::MaxSend(
            $questions[$missingAfter[0]] ?? 'Уточните, пожалуйста, параметры поездки.',
            $chat_id
        );
        return true;
    }

    private static function numberFromShortText($text, $min, $max)
    {
        if (preg_match('/^\d+$/', $text)) {
            $n = (int)$text;
            return ($n >= $min && $n <= $max) ? $n : null;
        }

        $words = [
            'ноль'=>0,
            'один'=>1, 'одна'=>1, 'одного'=>1,
            'два'=>2, 'двое'=>2, 'двух'=>2,
            'три'=>3, 'трое'=>3, 'трех'=>3, 'трёх'=>3,
            'четыре'=>4, 'четверо'=>4,
            'пять'=>5, 'пятеро'=>5,
            'шесть'=>6, 'шестеро'=>6,
        ];
        if (!array_key_exists($text, $words)) return null;
        $n = $words[$text];
        return ($n >= $min && $n <= $max) ? $n : null;
    }

    private static function mealFromShortText($text)
    {
        if (preg_match('/^(?:не важно|неважно|любое|любая|всё|все)$/ui', $text)) return 'any';
        if (preg_match('/^(?:ai|all\s*inclusive|вс[её]\s*включено|включено всё|включено все)$/ui', $text)) return 'all_inclusive';
        if (preg_match('/^(?:bb|завтрак|завтраки|только завтрак|только завтраки)$/ui', $text)) return 'breakfast';
        if (preg_match('/^(?:hb|полупансион|завтрак\s*(?:\+|и)\s*ужин)$/ui', $text)) return 'half_board';
        if (preg_match('/^(?:fb|полный пансион|завтрак\s*(?:\+|,|и)\s*обед\s*(?:\+|,|и)\s*ужин|завтрак обед ужин)$/ui', $text)) return 'full_board';
        return null;
    }
}
