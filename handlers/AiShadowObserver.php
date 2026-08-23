<?php
require_once __DIR__ . '/../services/TripStateService.php';
require_once __DIR__ . '/../services/ShadowDialogueService.php';
require_once __DIR__ . '/../services/DiagnosticLogger.php';

class AiShadowObserver
{
    public static function observe($chatId, string $message): void
    {
        if ($message === '') return;
        if (defined('AI_SHADOW_V2') && !AI_SHADOW_V2) return;

        try {
            $legacy = MaxSearchApi::getAiSearchContext($chatId);
            $state = TripStateService::fromLegacyAiContext(
                (array)$legacy,
                static function ($name) { return MaxSearchApi::getCityByName($name); },
                static function ($name) { return MaxSearchApi::getCountryByName($name); }
            );
            ShadowDialogueService::run($chatId, $message, $state);
        } catch (Throwable $e) {
            DiagnosticLogger::error('dialogue_v2_shadow', 'observer_failed', [
                'message'=>$message,
                'error'=>$e->getMessage(),
            ], $chatId);
        }
    }
}
