<?php
require_once dirname(__DIR__) . '/services/IntegrationRegistry.php';
require_once dirname(__DIR__) . '/services/DialogueView.php';
require_once dirname(__DIR__) . '/services/NeedApplicationService.php';
require_once dirname(__DIR__) . '/services/MissingFieldQuestionService.php';

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
        $appliedField = false;

        if ($field === 'adults' || $field === 'stars' || $field === 'meal' || $field === 'nights') {
            $result = NeedApplicationService::resolveAndApply($chat_id, $field, $lower);
            if (empty($result['recognized']) || empty($result['applied'])) return false;
            $params[$field] = $result['value'];
            $appliedField = true;
        }
        elseif ($field === 'children') {
            $partyClarification = self::partyClarificationWhileAskingChildren($lower);
            $ageCountClarification = self::childAgeCountClarificationWhileAskingChildren($lower);
            if ($partyClarification !== null) {
                $params = $partyClarification;
                $applied = NeedApplicationService::applyParameters($chat_id, $params);
                $appliedField = !empty($applied[$field]);
            } elseif ($ageCountClarification !== null) {
                $params = $ageCountClarification;
                $applied = NeedApplicationService::applyParameters($chat_id, $params);
                $appliedField = !empty($applied[$field]);
            } else {
                $result = NeedApplicationService::resolveAndApply($chat_id, 'children', $lower);
                if (empty($result['recognized']) || empty($result['applied'])) return false;
                $params['children'] = $result['value'];
                $appliedField = true;
            }
        }
        elseif ($field === 'child_ages') {
            $current = MaxSearchApi::getAiSearchContext($chat_id);
            $result = NeedApplicationService::resolveAndApply($chat_id, 'child_ages', $lower, [
                'children'=>(int)($current['children'] ?? 0),
            ]);
            if (empty($result['recognized']) || empty($result['applied'])) return false;
            $params['child_ages'] = $result['value'];
            $appliedField = true;
        }

        if (empty($params) || !$appliedField) return false;

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

        MissingFieldQuestionService::sendForMissing($chat_id, $missingAfter);
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
        return StarsParser::parse($text);
    }
}
