<?php
require_once dirname(__DIR__) . '/services/IntegrationRegistry.php';
require_once dirname(__DIR__) . '/services/DialogueView.php';
require_once dirname(__DIR__) . '/services/MealParser.php';
require_once dirname(__DIR__) . '/services/NightsParser.php';

class AiShortAnswerHandler
{
    public static function handle($message, $chat_id)
    {
        $text = trim((string)($message['text'] ?? ''));
        if ($text === '') return false;

        $missing = MaxSearchApi::getAiMissingFields($chat_id);
        if (empty($missing)) return false;

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
            $partyClarification = self::partyClarificationWhileAskingChildren($lower);
            $ageCountClarification = self::childAgeCountClarificationWhileAskingChildren($lower);
            if ($partyClarification !== null) {
                $params = $partyClarification;
            } elseif ($ageCountClarification !== null) {
                $params = $ageCountClarification;
            } elseif (preg_match('/^(?:нет|не будет|без детей|детей нет|без ребёнка|без ребенка|0)$/ui', $lower)) {
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
            $stars = self::starMinimumFromShortText($lower);
            if ($stars === null) return false;
            $params['stars'] = $stars;
        }
        elseif ($field === 'meal') {
            $meal = MealParser::parse($lower);
            if ($meal === null) return false;
            $params['meal'] = $meal;
        }
        elseif ($field === 'nights') {
            $nights = NightsParser::parse($lower);
            if ($nights === '') return false;
            $params['nights'] = $nights;
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
            DialogueView::check($chat_id);
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
        IntegrationRegistry::messenger()->send(
            $chat_id,
            $questions[$missingAfter[0]] ?? 'Уточните, пожалуйста, параметры поездки.'
        );
        return true;
    }

    public static function partyClarificationWhileAskingChildren(string $text): ?array
    {
        $lower = function_exists('mb_strtolower')
            ? mb_strtolower(trim($text), 'UTF-8')
            : strtolower(trim($text));
        $lower = trim(preg_replace('/[.!?]+$/u', '', $lower));

        if (!preg_match('/^([1-6])\s*(?:взросл(?:ый|ая|ые|ых|ого))$/ui', $lower, $m)) {
            return null;
        }

        return [
            'adults'=>(int)$m[1],
            'children'=>0,
        ];
    }

    public static function childAgeCountClarificationWhileAskingChildren(string $text): ?array
    {
        $lower = function_exists('mb_strtolower')
            ? mb_strtolower(trim($text), 'UTF-8')
            : strtolower(trim($text));
        $lower = trim(preg_replace('/[.!?]+$/u', '', $lower));

        if (!preg_match('/^(\d{1,2})\s*(?:лет|года|год)\s*,?\s*(?:один|1)(?:\s*(?:реб[её]нок|реб[её]нка))?$/ui', $lower, $m)) {
            return null;
        }

        $age = (int)$m[1];
        if ($age < 0 || $age > 17) return null;

        return [
            'children'=>1,
            'child_ages'=>[$age],
        ];
    }

    public static function starMinimumFromShortText(string $text): ?int
    {
        $lower = function_exists('mb_strtolower')
            ? mb_strtolower(trim($text), 'UTF-8')
            : strtolower(trim($text));
        $lower = trim(preg_replace('/[.!?]+$/u', '', $lower));

        if (preg_match('/^(?:не важно|неважно|любая|любые|все|всё)$/ui', $lower)) {
            return 1;
        }
        if (preg_match('/^(?:от\s*)?([1-5])\s*(?:\*|★|зв[её]зд(?:а|ы)?)?$/ui', $lower, $m)) {
            return (int)$m[1];
        }

        $compact = preg_replace('/\s+/u', '', $lower) ?? $lower;
        if (preg_match('/^[1-5](?:[,;\/\-][1-5])+$/u', $compact)) {
            preg_match_all('/[1-5]/u', $compact, $m);
            $values = array_map('intval', $m[0] ?? []);
            if ($values) return min($values);
        }

        return self::numberFromShortText($lower, 1, 5);
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
}
