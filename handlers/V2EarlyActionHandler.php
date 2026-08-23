<?php

require_once __DIR__ . '/../services/V2FeatureGate.php';
require_once __DIR__ . '/../services/DiagnosticLogger.php';
require_once __DIR__ . '/../services/RulesEngine.php';
require_once __DIR__ . '/../services/V2ActionExecutor.php';
require_once __DIR__ . '/DepartureRouteAdviceHandler.php';

class V2EarlyActionHandler
{
    public static function handle($chatId, array $message, ?array $shadowResult): bool
    {
        $text = trim((string)($message['text'] ?? ''));
        $action = self::interceptAction($shadowResult, $text);
        if ($action === null) return false;

        $handled = V2ActionExecutor::executePromoted($chatId, $text, $message, (array)$shadowResult, $action);
        DiagnosticLogger::log('dialogue_v2_live', $handled ? 'action_intercepted' : 'action_fell_through', [
            'message'=>$text,
            'action'=>$action,
            'shadow_intent'=>$shadowResult['extracted']['intent'] ?? null,
            'reason'=>$shadowResult['decision']['reason'] ?? null,
        ], $chatId);
        return $handled;
    }

    public static function interceptAction(?array $shadowResult, string $text): ?string
    {
        if (!$shadowResult) return null;
        $decision = (array)($shadowResult['decision'] ?? []);
        $action = (string)($decision['action'] ?? '');

        if ($action === RulesEngine::MANAGER && V2FeatureGate::enabled('manager_request')) {
            return self::isExplicitManagerRequest($text) ? RulesEngine::MANAGER : null;
        }
        if ($action === RulesEngine::SHOW_OPTIONS && V2FeatureGate::enabled('destination_advice')) return RulesEngine::SHOW_OPTIONS;
        if ($action === RulesEngine::ASK && V2FeatureGate::enabled('ask')) return RulesEngine::ASK;
        if ($action === RulesEngine::OPEN_SEARCH && V2FeatureGate::enabled('open_search')) return RulesEngine::OPEN_SEARCH;
        if ($action === RulesEngine::CHANNEL && V2FeatureGate::enabled('channel')) return RulesEngine::CHANNEL;
        return null;
    }

    public static function isExplicitManagerRequest(string $text): bool
    {
        $text = trim($text);
        if ($text === '') return false;
        return (bool)preg_match(
            '/(?:менеджер|оператор|жив(?:ой|ого)\s+человек|сотрудник|специалист|свяж(?:ите|итесь|ется)|позвон(?:ите|ить)|хочу\s+(?:с|к)\s+(?:менеджер|оператор))/ui',
            $text
        );
    }
}
