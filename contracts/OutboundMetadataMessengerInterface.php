<?php
require_once __DIR__.'/MessengerInterface.php';

/** Optional capability: metadata belongs to the local mirror, never provider wire data. */
interface OutboundMetadataMessengerInterface extends MessengerInterface
{
    public function sendWithButtonsAndMetadata($chatId, string $text, array $buttons, array $metadata): bool;
}
