<?php
require_once __DIR__ . '/../services/TripStateService.php';
require_once __DIR__ . '/../services/TripStateRepository.php';
require_once __DIR__ . '/../services/ShadowDialogueService.php';
require_once __DIR__ . '/../services/DiagnosticLogger.php';

class AiShadowObserver
{
    public static function observe($chatId, string $message): ?array
    {
        if ($message === '') return null;
        if (defined('AI_SHADOW_V2') && !AI_SHADOW_V2) return null;

        try {
            $legacy = MaxSearchApi::getAiSearchContext($chatId);
            $legacyState = TripStateService::fromLegacyAiContext(
                (array)$legacy,
                static function ($name) { return MaxSearchApi::getCityByName($name); },
                static function ($name) { return MaxSearchApi::getCountryByName($name); }
            );

            // V2 state is independent from the legacy HL state. The overlay lets us
            // retain fields that legacy storage cannot represent yet (budget,
            // preferences, ranges) without affecting current production behavior.
            $stored = TripStateRepository::load($chatId, dirname(__DIR__));
            $state = TripStateRepository::overlay($legacyState, $stored);
            $result = ShadowDialogueService::run($chatId, $message, $state);

            if (!empty($result['new_state']) && is_array($result['new_state'])) {
                TripStateRepository::save($chatId, $result['new_state'], dirname(__DIR__));
            }
            return $result;
        } catch (Throwable $e) {
            DiagnosticLogger::error('dialogue_v2_shadow', 'observer_failed', [
                'message'=>$message,
                'error'=>$e->getMessage(),
            ], $chatId);
            return null;
        }
    }

    public static function clear($chatId): void
    {
        TripStateRepository::delete($chatId, dirname(__DIR__));
    }
}
