<?php
require_once __DIR__ . '/ActionRouter.php';
require_once __DIR__ . '/DiagnosticLogger.php';
require_once __DIR__ . '/../actions/AskAction.php';
require_once __DIR__ . '/../actions/SearchAction.php';
require_once __DIR__ . '/../actions/ManagerAction.php';
require_once __DIR__ . '/../actions/DestinationAdviceAction.php';
require_once __DIR__ . '/../actions/ChannelAction.php';

class V2ActionExecutor
{
    public static function plan(array $decision, array $tripState, array $context = []): array
    {
        $route = ActionRouter::route($decision);
        switch ($route['handler']) {
            case 'ask':
                return AskAction::plan($decision);
            case 'search':
                return SearchAction::plan($tripState);
            case 'manager':
                return ManagerAction::plan($tripState, $context);
            case 'destination_advice':
                return DestinationAdviceAction::plan($tripState);
            case 'channel':
                return ChannelAction::plan($context['chat_id'] ?? 0);
            default:
                return ['action'=>$route['action'], 'handler'=>$route['handler']];
        }
    }

    public static function executePromoted($chatId, string $messageText, array $message, array $shadowResult, string $action): bool
    {
        $state = (array)($shadowResult['new_state'] ?? []);
        $decision = (array)($shadowResult['decision'] ?? []);
        $userContext = ['chat_id'=>$chatId];

        if ($action === RulesEngine::MANAGER) {
            $name = self::messageUserName($message);
            return ManagerAction::execute($chatId, $state, $userContext, $name, false);
        }
        if ($action === RulesEngine::SHOW_OPTIONS) {
            return DestinationAdviceAction::execute($chatId, $messageText);
        }
        if ($action === RulesEngine::ASK) {
            return AskAction::execute($chatId, $decision);
        }
        if ($action === RulesEngine::OPEN_SEARCH) {
            return SearchAction::execute($chatId, $state, self::messageUserName($message));
        }
        return false;
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
