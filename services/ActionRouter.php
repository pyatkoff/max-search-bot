<?php
require_once __DIR__ . '/RulesEngine.php';

class ActionRouter
{
    public static function route(array $decision): array
    {
        $action = (string)($decision['action'] ?? RulesEngine::ANSWER);
        $map = [
            RulesEngine::ASK => 'ask',
            RulesEngine::OPEN_SEARCH => 'search',
            RulesEngine::SHOW_OPTIONS => 'destination_advice',
            RulesEngine::MANAGER => 'manager',
            RulesEngine::CHANNEL => 'channel',
            RulesEngine::ANSWER => 'answer',
            RulesEngine::STOP => 'stop',
        ];
        if (!isset($map[$action])) $action = RulesEngine::ANSWER;
        return [
            'action' => $action,
            'handler' => $map[$action] ?? 'answer',
            'next_field' => $decision['next_field'] ?? null,
            'missing' => array_values((array)($decision['missing'] ?? [])),
            'reason' => $decision['reason'] ?? null,
        ];
    }
}
