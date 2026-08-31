<?php
require_once dirname(__DIR__) . '/actions/callbacks/WizardCallbackAction.php';
require_once dirname(__DIR__) . '/actions/callbacks/EditCallbackAction.php';
require_once dirname(__DIR__) . '/actions/callbacks/ManagerCallbackAction.php';
require_once dirname(__DIR__) . '/actions/callbacks/ToursCallbackAction.php';
require_once dirname(__DIR__) . '/handlers/AiDateHandler.php';
require_once __DIR__ . '/InteractionGuard.php';

/**
 * Shared callback controller for MAX/Telegram normalized callbacks.
 *
 * Payload parsing stays centralized here, while each callback family owns its
 * dialogue behavior in a small action class.
 */
class CallbackController
{
    public function handle(array $query): bool
    {
        $chatId = (int)($query['from']['id'] ?? 0);
        $q = (string)($query['data'] ?? '');
        if (!$chatId || $q === '') return false;

        if (ManagerCallbackAction::handles($q)) {
            return ManagerCallbackAction::handle($chatId, $q, $query);
        }
        if (ToursCallbackAction::handles($q)) {
            return ToursCallbackAction::handle($chatId, $q, $query);
        }
        if (EditCallbackAction::handles($q)) {
            return EditCallbackAction::handle($chatId, $q);
        }
        if (WizardCallbackAction::handles($q)) {
            if (strpos($q, 'back_') === 0
                && InteractionGuard::suppressDuplicateCallback($chatId, $q, 'wizard_back')) {
                return true;
            }
            return WizardCallbackAction::handle($chatId, $q);
        }

        if ($q === 'restart') {
            MaxSearchApi::deletePrevMessage($chatId, true);
            AiDateHandler::clear($chatId);
            MaxSearchApi::deleteAllStatus($chatId);
            MaxSearchApi::showStart($chatId);
            return true;
        }
        if ($q === 'back_phone') {
            MaxSearchApi::deletePrevMessage($chatId, true);
            AiDateHandler::clear($chatId);
            MaxSearchApi::deleteAllStatus($chatId);
            return true;
        }

        InteractionGuard::reportSuppressed($chatId, $q, 'unknown_action', null, null, 'callback_controller');
        return false;
    }

    public static function userName(array $query): string
    {
        $from = (array)($query['from'] ?? []);
        $name = trim((string)($from['first_name'] ?? ''));
        $last = trim((string)($from['last_name'] ?? ''));
        if ($last !== '') $name = trim($name . ' ' . $last);
        if ($name === '') $name = trim((string)($from['username'] ?? ''));
        return $name;
    }

    public static function family(string $payload): string
    {
        if ($payload === 'restart') return 'restart';
        if ($payload === 'back_phone') return 'phone';
        if (ManagerCallbackAction::handles($payload)) return 'manager';
        if (ToursCallbackAction::handles($payload)) return 'tours';
        if (EditCallbackAction::handles($payload)) return 'edit';
        if ($payload === 'ai_start') return 'ai';
        if (WizardCallbackAction::handles($payload)) return 'wizard';
        return 'unknown';
    }
}
