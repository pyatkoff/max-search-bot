<?php
require_once __DIR__ . '/DialogueController.php';

class DialogueApplication
{
    private $messageHandler;
    private $callbackHandler;
    private $contactHandler;
    private $callbackAcknowledger;
    private $customMessageHandler = false;
    private $customCallbackHandler = false;
    private $customContactHandler = false;

    public function __construct(
        ?callable $messageHandler = null,
        ?callable $callbackHandler = null,
        ?callable $contactHandler = null,
        ?callable $callbackAcknowledger = null
    ) {
        $controller = new DialogueController();
        $this->customMessageHandler = $messageHandler !== null;
        $this->customCallbackHandler = $callbackHandler !== null;
        $this->customContactHandler = $contactHandler !== null;

        $this->messageHandler = $messageHandler ?: static function (array $incoming) use ($controller): void {
            $controller->handleIncomingMessage($incoming);
        };
        $this->callbackHandler = $callbackHandler ?: static function (array $incoming) use ($controller): void {
            $controller->handleIncomingCallback($incoming);
        };
        $this->contactHandler = $contactHandler ?: static function (array $incoming) use ($controller): void {
            $controller->handleIncomingContact($incoming);
        };
        $this->callbackAcknowledger = $callbackAcknowledger ?: static function (string $callbackId, array $incoming): void {
            if ($callbackId === '') return;
            // MAX acknowledgement remains on the proven legacy transport for now.
            // Telegram acknowledgement will be added to the messenger contract separately.
            if (($incoming['platform'] ?? '') === 'max' && class_exists('MaxSearchApi')) {
                MaxSearchApi::answerCallback($callbackId);
            }
        };
    }

    public function dispatch(array $incoming): bool
    {
        $chatId = $incoming['user']['chat_id'] ?? 0;
        if (!$chatId) return false;

        $type = (string)($incoming['type'] ?? '');
        if ($type === 'contact') {
            if ($this->customContactHandler) {
                call_user_func($this->contactHandler, $chatId, trim((string)($incoming['contact_phone'] ?? '')), $incoming);
            } else {
                call_user_func($this->contactHandler, $incoming);
            }
            return true;
        }

        if ($type === 'callback') {
            $callbackId = (string)($incoming['callback_id'] ?? '');
            call_user_func($this->callbackAcknowledger, $callbackId, $incoming);
            if ($this->customCallbackHandler) {
                call_user_func($this->callbackHandler, $this->legacyQuery($incoming), $incoming);
            } else {
                call_user_func($this->callbackHandler, $incoming);
            }
            return true;
        }

        if ($type === 'message') {
            if ($this->customMessageHandler) {
                call_user_func($this->messageHandler, $this->legacyMessage($incoming), $incoming);
            } else {
                call_user_func($this->messageHandler, $incoming);
            }
            return true;
        }

        return false;
    }

    // Compatibility helpers used by regression tests and migration tooling.
    public function legacyMessage(array $incoming): array
    {
        return DialogueController::messageEnvelope($incoming);
    }

    public function legacyQuery(array $incoming): array
    {
        return DialogueController::queryEnvelope($incoming);
    }
}
