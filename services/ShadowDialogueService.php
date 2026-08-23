<?php
require_once __DIR__ . '/../ai/TouristExtractorV2.php';
require_once __DIR__ . '/TripStateMerger.php';
require_once __DIR__ . '/RulesEngine.php';
require_once __DIR__ . '/DiagnosticLogger.php';

class ShadowDialogueService
{
    public static function run($chatId, string $message, array $oldState): array
    {
        $extracted = TouristExtractorV2::extract($message, $oldState);
        return self::evaluate($chatId, $message, $oldState, $extracted, true);
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

        if ($writeLog) {
            DiagnosticLogger::log('dialogue_v2_shadow', 'message_evaluated', [
                'message'=>$message,
                'old_state'=>$oldState,
                'extracted'=>$result['extracted'],
                'new_state'=>$newState,
                'rule_action'=>$decision['action'] ?? null,
                'missing'=>$decision['missing'] ?? [],
                'next_field'=>$decision['next_field'] ?? null,
                'reason'=>$decision['reason'] ?? null,
            ], $chatId);
        }
        return $result;
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
