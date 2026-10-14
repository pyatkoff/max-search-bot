<?php
require_once __DIR__ . '/../integrations/TelegramIncomingAdapter.php';
require_once __DIR__ . '/../integrations/TelegramMessengerAdapter.php';
require_once __DIR__ . '/../services/DialogueApplication.php';
require_once __DIR__ . '/../services/IncomingUpdateDispatcher.php';
require_once __DIR__ . '/../services/IntegrationRegistry.php';
require_once __DIR__ . '/../services/DiagnosticLogger.php';
require_once __DIR__ . '/../services/ProjectConfig.php';
require_once __DIR__ . '/../services/TelegramStartSourceResolver.php';
if (is_file(__DIR__.'/../services/ChannelPostEntryService.php')) require_once __DIR__.'/../services/ChannelPostEntryService.php';

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
        if (class_exists('ChannelPostRuntime')&&ChannelPostIncomingAdapter::isChannelUpdate('telegram',$update)) {
            $result=ChannelPostRuntime::receive('telegram',$update);
            return !empty($result['recognized']);
        }
        $incoming = TelegramIncomingAdapter::fromUpdate($update);
        if (!$incoming) {
            DiagnosticLogger::log('telegram_webhook', 'ignored_update', [
                'update_id'=>$update['update_id'] ?? null,
            ], null, 'warning');
            return false;
        }

        $incoming['source_key'] = TelegramStartSourceResolver::resolve($incoming);
        $postPayload=class_exists('ChannelPostEntryService')?ChannelPostEntryService::telegramPayload($incoming):'';
        $post=$postPayload!==''?ChannelPostEntryService::resolve('telegram',$postPayload):null;
        if ($postPayload!=='') {
            if (ChannelPostEntryService::prepareTelegramEntry($incoming,$post)) {
                ChannelPostEntryService::record('telegram',$incoming['user']['chat_id']??0,$post,ChannelPostEntryService::eventKey('telegram',$update));
                return true;
            }
            if ($post) {
                $incoming['source_key']=$post['source_key'];
                $incoming['entry_kind']='channel_post';
            }
        }

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
        if ($postPayload!=='') ChannelPostEntryService::record('telegram',$incoming['user']['chat_id']??0,$post,ChannelPostEntryService::eventKey('telegram',$update));
        DiagnosticLogger::log('telegram_webhook', $handled ? 'handled' : 'ignored', [
            'update_id'=>$update['update_id'] ?? null,
            'type'=>$incoming['type'] ?? '',
            'source_key'=>$incoming['source_key'] ?? '',
        ], $incoming['user']['chat_id'] ?? null);
        return $handled;
    }
}
