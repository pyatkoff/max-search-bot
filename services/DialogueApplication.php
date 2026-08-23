<?php

class DialogueApplication
{
    private $messageHandler;
    private $callbackHandler;
    private $contactHandler;
    private $callbackAcknowledger;

    public function __construct(
        ?callable $messageHandler = null,
        ?callable $callbackHandler = null,
        ?callable $contactHandler = null,
        ?callable $callbackAcknowledger = null
    ) {
        $this->messageHandler = $messageHandler ?: static function (array $message): void {
            processMessage($message);
        };
        $this->callbackHandler = $callbackHandler ?: static function (array $query): void {
            processQuery($query);
        };
        $this->contactHandler = $contactHandler ?: static function ($chatId, string $phone): void {
            if ($phone === '' || !class_exists('MaxSearchApi')) return;
            if (MaxSearchApi::getCurentStatus($chatId) != MaxSearchApi::$statusPhone) return;

            $ok = MaxSearchApi::savePhone($chatId, $phone);
            if ($ok) {
                MaxSearchApi::deleteAllStatus($chatId);
                MaxSearchApi::showChannelOffer($chatId, true);
                return;
            }
            MaxSearchApi::MaxSend(
                'Не получилось сохранить номер. Попробуйте отправить контакт ещё раз или введите номер вручную.',
                $chatId
            );
        };
        $this->callbackAcknowledger = $callbackAcknowledger ?: static function (string $callbackId): void {
            if ($callbackId !== '' && class_exists('MaxSearchApi')) MaxSearchApi::answerCallback($callbackId);
        };
    }

    public function dispatch(array $incoming): bool
    {
        $chatId = $incoming['user']['chat_id'] ?? 0;
        if (!$chatId) return false;

        $type = (string)($incoming['type'] ?? '');
        if ($type === 'contact') {
            call_user_func($this->contactHandler, $chatId, trim((string)($incoming['contact_phone'] ?? '')), $incoming);
            return true;
        }

        if ($type === 'callback') {
            $callbackId = (string)($incoming['callback_id'] ?? '');
            call_user_func($this->callbackAcknowledger, $callbackId, $incoming);
            call_user_func($this->callbackHandler, $this->legacyQuery($incoming), $incoming);
            return true;
        }

        if ($type === 'message') {
            call_user_func($this->messageHandler, $this->legacyMessage($incoming), $incoming);
            return true;
        }

        return false;
    }

    public function legacyMessage(array $incoming): array
    {
        return [
            'message_id' => (string)($incoming['message_id'] ?? ''),
            'chat' => ['id' => $incoming['user']['chat_id'] ?? 0],
            'text' => (string)($incoming['text'] ?? ''),
        ];
    }

    public function legacyQuery(array $incoming): array
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
        ];
    }
}
