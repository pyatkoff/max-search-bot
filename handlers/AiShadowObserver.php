<?php
require_once __DIR__ . '/../services/TripStateService.php';
require_once __DIR__ . '/../services/ShadowDialogueService.php';
require_once __DIR__ . '/../services/DiagnosticLogger.php';
require_once __DIR__ . '/../services/V2FeatureGate.php';

class AiShadowObserver
{
    public static function observe($chatId, string $message): ?array
    {
        if ($message === '') return null;
        if (defined('AI_SHADOW_V2') && !AI_SHADOW_V2) return null;
        if (!V2FeatureGate::enabled('shadow')) return null;

        try {
            $legacy = MaxSearchApi::getAiSearchContext($chatId);
            $state = TripStateService::fromLegacyAiContext(
                (array)$legacy,
                static function ($name) { return MaxSearchApi::getCityByName($name); },
                static function ($name) { return MaxSearchApi::getCountryByName($name); }
            );
            return ShadowDialogueService::run($chatId, $message, $state);
        } catch (Throwable $e) {
            DiagnosticLogger::error('dialogue_v2_shadow', 'observer_failed', [
                'message'=>$message,
                'error'=>$e->getMessage(),
            ], $chatId);
            return null;
        }
    }
}
