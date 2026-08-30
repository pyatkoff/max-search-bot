<?php
require_once __DIR__ . '/../integrations/TelegramIncomingAdapter.php';
require_once __DIR__ . '/../integrations/TelegramMessengerAdapter.php';
require_once __DIR__ . '/../services/DialogueApplication.php';
require_once __DIR__ . '/../services/IncomingUpdateDispatcher.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';
require_once __DIR__ . '/../services/DiagnosticLogger.php';
require_once __DIR__ . '/../services/ProjectConfig.php';
require_once __DIR__ . '/../services/TelegramStartSourceResolver.php';

class TelegramWebhookHandler
{
    public static function secretAccepted(string $provided): bool
    {
        if (!defined('TELEGRAM_WEBHOOK_SECRET') || TELEGRAM_WEBHOOK_SECRET === '') return true;
        return hash_equals((string)TELEGRAM_WEBHOOK_SECRET, $provided);
    }

    public static function dispatchUpdate(
        array $update,
        ?IncomingUpdateDispatcher $dispatcher = null,
        ?TelegramMessengerAdapter $messenger = null
    ): bool {
        $incoming = TelegramIncomingAdapter::fromUpdate($update);
        if (!$incoming) {
            DiagnosticLogger::log('telegram_webhook', 'ignored_update', [
                'update_id'=>$update['update_id'] ?? null,
            ], null, 'warning');
            return false;
        }

        $incoming['source_key'] = TelegramStartSourceResolver::resolve($incoming);

        $messenger = $messenger ?: new TelegramMessengerAdapter();
        IntegrationRegistry::useMessenger($messenger);

        if ($dispatcher === null) {
            $application = new DialogueApplication(
                null,
                null,
                null,
                static function (string $callbackId) use ($messenger): void {
                    if ($callbackId !== '') $messenger->answerCallback($callbackId);
                }
            );
            $dispatcher = new IncomingUpdateDispatcher($application);
        }

        $handled = $dispatcher->dispatch($incoming);
        DiagnosticLogger::log('telegram_webhook', $handled ? 'handled' : 'ignored', [
            'update_id'=>$update['update_id'] ?? null,
            'type'=>$incoming['type'] ?? '',
            'source_key'=>$incoming['source_key'] ?? '',
        ], $incoming['user']['chat_id'] ?? null);
        return $handled;
    }
}
