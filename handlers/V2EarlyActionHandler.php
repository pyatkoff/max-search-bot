<?php

require_once __DIR__ . '/../services/V2FeatureGate.php';
require_once __DIR__ . '/../services/DiagnosticLogger.php';
require_once __DIR__ . '/../services/RulesEngine.php';
require_once __DIR__ . '/DepartureRouteAdviceHandler.php';

class V2EarlyActionHandler
{
    public static function handle($chatId, array $message, ?array $shadowResult): bool
    {
        $text = trim((string)($message['text'] ?? ''));
        $action = self::interceptAction($shadowResult, $text);
        if ($action === null) return false;

        if ($action === RulesEngine::MANAGER) {
            MaxSearchApi::funnelLog($chatId, 'manager_request', ['source'=>'ai_v2_text']);
            MaxSearchApi::queueMetrikaGoal($chatId, 'max_manager_request');
            $name = self::messageUserName($message);
            MaxSearchApi::showManagerRequest($chatId, $name, false);
            DiagnosticLogger::log('dialogue_v2_live', 'manager_intercepted', [
                'message'=>$text,
                'shadow_intent'=>$shadowResult['extracted']['intent'] ?? null,
            ], $chatId);
            return true;
        }

        if ($action === RulesEngine::SHOW_OPTIONS) {
            $handled = DepartureRouteAdviceHandler::handle($chatId, $text);
            DiagnosticLogger::log('dialogue_v2_live', $handled ? 'destination_advice_intercepted' : 'destination_advice_fell_through', [
                'message'=>$text,
                'shadow_intent'=>$shadowResult['extracted']['intent'] ?? null,
            ], $chatId);
            return $handled;
        }

        return false;
    }

    /**
     * Pure promotion gate used both by production and regression tests.
     * Shadow intent alone is deliberately not enough for MANAGER: the text must
     * contain a deterministic explicit request for a human.
     */
    public static function interceptAction(?array $shadowResult, string $text): ?string
    {
        if (!$shadowResult) return null;
        $decision = (array)($shadowResult['decision'] ?? []);
        $action = (string)($decision['action'] ?? '');

        if ($action === RulesEngine::MANAGER && V2FeatureGate::enabled('manager_request')) {
            return self::isExplicitManagerRequest($text) ? RulesEngine::MANAGER : null;
        }

        if ($action === RulesEngine::SHOW_OPTIONS && V2FeatureGate::enabled('destination_advice')) {
            return RulesEngine::SHOW_OPTIONS;
        }

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

    private static function messageUserName(array $message): string
    {
        $from = (array)($message['from'] ?? []);
        $name = trim((string)($from['first_name'] ?? ''));
        $last = trim((string)($from['last_name'] ?? ''));
        if ($last !== '') $name = trim($name . ' ' . $last);
        if ($name === '') $name = trim((string)($from['username'] ?? ''));
        return $name;
    }
}
