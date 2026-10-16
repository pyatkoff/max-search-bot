<?php
require_once __DIR__ . '/../ai/TouristExtractorV2.php';
require_once __DIR__ . '/TripStateMerger.php';
require_once __DIR__ . '/RulesEngine.php';
require_once __DIR__ . '/DiagnosticLogger.php';

class ShadowDialogueService
{
    public static function run($chatId, string $message, array $oldState, bool $writeLog = true): array
    {
        $extracted = TouristExtractorV2::extract($message, $oldState);
        return self::evaluate($chatId, $message, $oldState, $extracted, $writeLog);
    }

    public static function evaluate($chatId, string $message, array $oldState, array $extracted, bool $writeLog = false): array
    {
        $changes = isset($extracted['changes']) && is_array($extracted['changes']) ? $extracted['changes'] : [];
        $changes = self::resolveDirectoryIds($changes);
        $newState = TripStateMerger::merge($oldState, $changes);
        $intent = (string)($extracted['intent'] ?? 'general_question');
        $decision = RulesEngine::decide($intent, $newState);

        $result = [
            'old_state'=>$oldState,
            'extracted'=>[
                'intent'=>$intent,
                'changes'=>$changes,
                'confidence'=>is_array($extracted['confidence'] ?? null) ? $extracted['confidence'] : [],
                'note'=>(string)($extracted['note'] ?? ''),
            ],
            'new_state'=>$newState,
            'decision'=>$decision,
        ];

        if ($writeLog) self::logResult($chatId, $message, $result);
        return $result;
    }

    public static function logResult($chatId, string $message, array $result): bool
    {
        $decision = is_array($result['decision'] ?? null) ? $result['decision'] : [];
        return DiagnosticLogger::log('dialogue_v2_shadow', 'message_evaluated', [
            'message'=>$message,
            'old_state'=>is_array($result['old_state'] ?? null) ? $result['old_state'] : [],
            'extracted'=>is_array($result['extracted'] ?? null) ? $result['extracted'] : [],
            'new_state'=>is_array($result['new_state'] ?? null) ? $result['new_state'] : [],
            'rule_action'=>$decision['action'] ?? null,
            'missing'=>$decision['missing'] ?? [],
            'next_field'=>$decision['next_field'] ?? null,
            'reason'=>$decision['reason'] ?? null,
        ], $chatId);
    }

    private static function resolveDirectoryIds(array $changes): array
    {
        if (!empty($changes['departure.city']) && class_exists('MaxSearchApi')) {
            try {
                $row = MaxSearchApi::getCityByName((string)$changes['departure.city']);
                if (is_array($row) && !empty($row['ID'])) $changes['departure.city_id'] = (int)$row['ID'];
            } catch (Throwable $e) {}
        }
        if (!empty($changes['destination.country']) && class_exists('MaxSearchApi')) {
            try {
                $row = MaxSearchApi::getCountryByName((string)$changes['destination.country']);
                if (is_array($row) && !empty($row['ID'])) $changes['destination.country_id'] = (int)$row['ID'];
            } catch (Throwable $e) {}
        }
        return $changes;
    }
}
