<?php
require_once __DIR__ . '/../services/TripStateService.php';
require_once __DIR__ . '/../services/TripStateRepository.php';
require_once __DIR__ . '/../services/ShadowDialogueService.php';
require_once __DIR__ . '/../services/DiagnosticLogger.php';
require_once __DIR__ . '/../services/RuntimeStorage.php';
require_once __DIR__ . '/../services/NeedApplicationService.php';

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
            if (empty(array_diff_key($legacy, ['budget'=>true,'preferences'=>true,'negative_preferences'=>true]))) {
                TripStateRepository::delete($chatId, dirname(__DIR__));
                $stored = [];
            } else {
                $stored = TripStateRepository::load($chatId, dirname(__DIR__));
            }
            $state = TripStateRepository::overlay($legacyState, $stored);

            // Standalone active metadata has one authoritative owner. Cached shadow
            // values must not undo a budget correction, clear, or accepted wish list.
            $canonicalBudget = RuntimeStorage::usesMysql() ? $legacyState['budget'] : null;
            if ($canonicalBudget !== null) $state['budget'] = $canonicalBudget;
            if (RuntimeStorage::usesMysql()) {
                $state['preferences'] = (array)($legacyState['preferences'] ?? []);
                $state['negative_preferences'] = (array)($legacyState['negative_preferences'] ?? []);
            }

            // Delay the message_evaluated diagnostic until guarded preference
            // application and canonical read-back have finished. Otherwise the log can
            // claim a low-confidence/rejected wish was saved when it was not.
            $result = ShadowDialogueService::run($chatId, $message, $state, false);
            if ($canonicalBudget !== null && is_array($result['new_state'] ?? null)) {
                $result['new_state']['budget'] = $canonicalBudget;
            }

            if (RuntimeStorage::usesMysql()) {
                try {
                    NeedApplicationService::applyExtractedPreferences(
                        $chatId,
                        (array)($result['extracted']['changes'] ?? []),
                        (array)($result['extracted']['confidence'] ?? [])
                    );
                } catch (Throwable $e) {
                    DiagnosticLogger::error('dialogue_v2_shadow', 'preference_persist_failed', [
                        'error_type'=>get_class($e),
                    ], $chatId);
                }
                // Read back canonical metadata after the guarded application. Rejected,
                // stale or low-confidence model values are not allowed to live in shadow.
                $active = (array)MaxSearchApi::getAiSearchContext($chatId);
                if (is_array($result['new_state'] ?? null)) {
                    $result['new_state']['preferences'] = array_values((array)($active['preferences'] ?? []));
                    $result['new_state']['negative_preferences'] = array_values((array)($active['negative_preferences'] ?? []));
                }
            }

            if (!empty($result['new_state']) && is_array($result['new_state'])) {
                TripStateRepository::save($chatId, $result['new_state'], dirname(__DIR__));
            }
            ShadowDialogueService::logResult($chatId, $message, $result);
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
