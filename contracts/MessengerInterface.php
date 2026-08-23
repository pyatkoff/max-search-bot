<?php

interface MessengerInterface
{
    public function send($chatId, string $text): bool;

    public function sendWithButtons($chatId, string $text, array $buttons): bool;

    /**
     * Ask the user to share a phone number, while keeping platform-specific UI
     * details (MAX contact button vs Telegram reply keyboard) inside adapter.
     */
    public function sendContactRequest($chatId, string $text, string $manualCallback, string $backCallback): bool;
}
