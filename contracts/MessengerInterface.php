<?php

interface MessengerInterface
{
    public function send($chatId, string $text): bool;

    public function sendWithButtons($chatId, string $text, array $buttons): bool;
}
