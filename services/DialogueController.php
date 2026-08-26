<?php
require_once __DIR__ . '/IntegrationRegistry.php';
require_once __DIR__ . '/CallbackController.php';
require_once __DIR__ . '/DialogueView.php';
require_once __DIR__ . '/DiagnosticLogger.php';
require_once __DIR__ . '/EditFlowService.php';
require_once __DIR__ . '/../services/DepartureCityResolver.php';
require_once __DIR__ . '/../services/DestinationAreaResolver.php';
require_once __DIR__ . '/../services/DestinationResolver.php';
require_once __DIR__ . '/../handlers/AiDateHandler.php';
require_once __DIR__ . '/../handlers/AiMessageHandler.php';
require_once __DIR__ . '/../handlers/AiShortAnswerHandler.php';
require_once __DIR__ . '/../handlers/AiShadowObserver.php';
require_once __DIR__ . '/../handlers/V2EarlyActionHandler.php';
require_once __DIR__ . '/../handlers/DepartureRouteAdviceHandler.php';
require_once __DIR__ . '/../handlers/StateMessageHandler.php';

/**
 * Shared application controller for normalized messenger input.
 *
 * It intentionally keeps the existing production dialogue behavior while moving
 * it out of webhook.php. Messenger-specific parsing belongs to incoming adapters;
 * this controller receives the common IncomingMessage/UserContext shape.
 */
class DialogueController
{
    public function handleIncomingMessage(array $incoming): bool
    {
        $message = self::messageEnvelope($incoming);
        $chatId = $message['chat']['id'] ?? 0;
        if (!$chatId || !array_key_exists('text', $message)) return false;

        $text = (string)$message['text'];
        $platform = strtolower(trim((string)($incoming['platform'] ?? '')));
        if (function_exists('put_log_in')) put_log_in($chatId . '!!!!!!!!!!!' . $text);

        if (strpos($text, '/start') === 0 && $text !== '/start') {
            $payload = trim(str_replace('/start', '', $text));
            if (strpos($payload, 'ya') === 0) {
                MaxSearchApi::addYclid($chatId, trim(str_replace('ya', '', $payload)));
            }
            $this->resetDialogueSafe($chatId, false, $platform);
            DialogueView::start($chatId);
            return true;
        }

        if ($text === '/start' || $text === 'МЕНЮ') {
            $this->resetDialogueSafe($chatId, true, $platform);
            DialogueView::start($chatId);
            return true;
        }

        $plainText = trim($text);
        $objection = self::objectionReply($plainText);
        if ($objection !== null) {
            IntegrationRegistry::messenger()->send($chatId, $objection);
            return true;
        }

        $status = MaxSearchApi::getCurentStatus($chatId);
        if ($status == MaxSearchApi::$statusAi || !$status || $status == MaxSearchApi::$statusStart) {
            DepartureCityResolver::resolveAndStore($chatId, $text);
            DestinationAreaResolver::resolveAndStore($chatId, $text);
            DestinationResolver::resolveAndStore($chatId, $text);

            $shadowV2 = AiShadowObserver::observe($chatId, $text);
            if (V2EarlyActionHandler::handle($chatId, $message, $shadowV2)) return true;
            if (DepartureRouteAdviceHandler::handle($chatId, $text)) return true;
            if (!AiShortAnswerHandler::handle($message, $chatId)) {
                AiMessageHandler::handle($message, $chatId);
            }
            return true;
        }

        StateMessageHandler::handle($message, $chatId, $status);
        return true;
    }

    public function handleIncomingCallback(array $incoming): bool
    {
        $query = self::queryEnvelope($incoming);
        if (empty($query['from']['id'])) return false;
        $controller = new CallbackController();
        return $controller->handle($query);
    }

    public function handleIncomingContact(array $incoming): bool
    {
        $chatId = $incoming['user']['chat_id'] ?? 0;
        $phone = trim((string)($incoming['contact_phone'] ?? ''));
        if (!$chatId || $phone === '') return false;
        if (MaxSearchApi::getCurentStatus($chatId) != MaxSearchApi::$statusPhone) return false;

        $ok = MaxSearchApi::savePhone($chatId, $phone);
        if ($ok) {
            MaxSearchApi::deleteAllStatus($chatId);
            DialogueView::channelOffer($chatId, true);
            return true;
        }

        IntegrationRegistry::messenger()->send(
            $chatId,
            'Не получилось сохранить номер. Попробуйте отправить контакт ещё раз или введите номер вручную.'
        );
        return true;
    }

    public static function messageEnvelope(array $incoming): array
    {
        $user = (array)($incoming['user'] ?? []);
        return [
            'message_id' => (string)($incoming['message_id'] ?? ''),
            'chat' => ['id' => $user['chat_id'] ?? 0],
            'from' => [
                'id' => $user['chat_id'] ?? 0,
                'first_name' => (string)($user['first_name'] ?? ''),
                'last_name' => (string)($user['last_name'] ?? ''),
                'username' => (string)($user['username'] ?? ''),
            ],
            'text' => (string)($incoming['text'] ?? ''),
            '_platform' => (string)($incoming['platform'] ?? ''),
        ];
    }

    public static function queryEnvelope(array $incoming): array
    {
        $user = (array)($incoming['user'] ?? []);
        return [
            'from' => [
                'id' => $user['chat_id'] ?? 0,
                'first_name' => (string)($user['first_name'] ?? ''),
                'last_name' => (string)($user['last_name'] ?? ''),
                'username' => (string)($user['username'] ?? ''),
            ],
            'data' => (string)($incoming['callback_data'] ?? ''),
            '_platform' => (string)($incoming['platform'] ?? ''),
        ];
    }

    public static function objectionReply(string $text): ?string
    {
        if (!preg_match('/^(?:дорого|очень дорого|дороговато|слишком дорого)[.!? ]*$/ui', trim($text))) {
            return null;
        }
        return "Поняла. Давайте попробуем удешевить подбор.\n\nМожно:\n• немного сдвинуть даты;\n• сократить количество ночей;\n• снизить категорию отеля;\n• посмотреть другое направление.\n\nНапишите, что готовы изменить — я пересоберу поиск.";
    }

    private function resetDialogueSafe($chatId, bool $clearDate, string $platform): void
    {
        try {
            $this->resetDialogue($chatId, $clearDate);
        } catch (\Throwable $e) {
            DiagnosticLogger::error('dialogue', 'reset_failed', [
                'platform'=>$platform,
                'error'=>$e->getMessage(),
            ], $chatId);
            if ($platform !== 'telegram') throw $e;
        }
    }

    private function resetDialogue($chatId, bool $clearDate): void
    {
        MaxSearchApi::cancelToursFollowup($chatId);
        MaxSearchApi::deleteAllStatus($chatId);
        MaxSearchApi::setEditMode($chatId, '');
        EditFlowService::clearSnapshot($chatId);
        if ($clearDate) AiDateHandler::clear($chatId);
        AiShadowObserver::clear($chatId);
        DestinationResolver::clear($chatId);
    }
}
