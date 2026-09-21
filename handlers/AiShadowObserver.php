<?php
require_once __DIR__ . '/../services/TripStateService.php';
require_once __DIR__ . '/../services/TripStateRepository.php';
require_once __DIR__ . '/../services/ShadowDialogueService.php';
require_once __DIR__ . '/../services/DiagnosticLogger.php';
require_once __DIR__ . '/../services/RuntimeStorage.php';

class AiShadowObserver
{
    public static function observe($chatId, string $message): ?array
    {
        if ($message === '') return null;
        if (defined('AI_SHADOW_V2') && !AI_SHADOW_V2) return null;
        try {
            $legacy = MaxSearchApi::getAiSearchContext($chatId);
            $legacyState = TripStateService::fromLegacyAiContext((array)$legacy,
                static function($name){return MaxSearchApi::getCityByName($name);},
                static function($name){return MaxSearchApi::getCountryByName($name);}
            );

            // A fresh legacy context means a new selection was started (including ai_start).
            // Do not carry budget/preferences from the previous trip across that boundary.
            if (empty(array_diff_key($legacy, ['budget'=>true]))) {
                TripStateRepository::delete($chatId, dirname(__DIR__));
                $stored = [];
            } else {
                $stored = TripStateRepository::load($chatId, dirname(__DIR__));
            }
            $state = TripStateRepository::overlay($legacyState, $stored);
            // Standalone budget has one authoritative owner: the current start row.
            // A cached model value must not undo an amount correction or clear.
            $canonicalBudget = RuntimeStorage::usesMysql() ? $legacyState['budget'] : null;
            if ($canonicalBudget !== null) $state['budget'] = $canonicalBudget;
            $result = ShadowDialogueService::run($chatId, $message, $state);
            if ($canonicalBudget !== null && is_array($result['new_state'] ?? null)) {
                $result['new_state']['budget'] = $canonicalBudget;
            }
            if (!empty($result['new_state']) && is_array($result['new_state'])) {
                TripStateRepository::save($chatId, $result['new_state'], dirname(__DIR__));
            }
            return $result;
        } catch (Throwable $e) {
            DiagnosticLogger::error('dialogue_v2_shadow','observer_failed',['message'=>$message,'error'=>$e->getMessage()],$chatId);
            return null;
        }
    }

    public static function clear($chatId): void
    {
        TripStateRepository::delete($chatId, dirname(__DIR__));
    }
}
