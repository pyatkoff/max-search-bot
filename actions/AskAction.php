<?php
require_once __DIR__ . '/../services/RulesEngine.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';

class AskAction
{
    public static function plan(array $decision): array
    {
        $field = (string)($decision['next_field'] ?? '');
        return [
            'action'=>RulesEngine::ASK,
            'field'=>$field !== '' ? $field : null,
            'text'=>RulesEngine::questionFor($field),
        ];
    }

    public static function execute($chatId, array $decision): bool
    {
        $plan = self::plan($decision);
        MaxSearchApi::setStatus($chatId, MaxSearchApi::$statusAi);
        return IntegrationRegistry::messenger()->send($chatId, $plan['text']);
    }
}
