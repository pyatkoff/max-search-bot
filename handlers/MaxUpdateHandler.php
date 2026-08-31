<?php
require_once __DIR__ . '/../integrations/MaxIncomingAdapter.php';
require_once __DIR__ . '/../services/IncomingUpdateDispatcher.php';
require_once __DIR__ . '/../services/IncomingUpdateDeduplicator.php';
require_once __DIR__ . '/../services/TrafficAttributionService.php';

class MaxUpdateHandler
{
    public static function handle()
    {
        $content = file_get_contents('php://input');
        if (function_exists('put_log_in')) put_log_in($content);
        $update = json_decode($content, true);

        $incomingSecret = $_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? '';
        if (defined('MAX_SEARCH_WEBHOOK_SECRET') && MAX_SEARCH_WEBHOOK_SECRET !== '' && !hash_equals(MAX_SEARCH_WEBHOOK_SECRET, (string)$incomingSecret)) {
            http_response_code(403);
            echo 'forbidden';
            exit;
        }

        if (defined('MAX_SEARCH_MAX_SHADOW_MODE') && MAX_SEARCH_MAX_SHADOW_MODE === true) {
            if (function_exists('put_log_in')) {
                $type = is_array($update) ? (string)($update['update_type'] ?? '') : '';
                put_log_in('SHADOW_UPDATE_RECEIVED type=' . $type);
            }
            http_response_code(200);
            echo 'ok';
            exit;
        }

        if (!is_array($update)) {
            http_response_code(200);
            echo 'ok';
            exit;
        }

        if (!IncomingUpdateDeduplicator::claim($update)) {
            if (function_exists('put_log_in')) put_log_in('DUPLICATE_UPDATE_SKIPPED ' . IncomingUpdateDeduplicator::key($update));
            http_response_code(200);
            echo 'ok';
            exit;
        }

        $type = (string)($update['update_type'] ?? '');
        $user = MaxIncomingAdapter::user($update);
        $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
        $internalId = $userId > 0 ? -$userId : $userId;

        if ($type === 'bot_started' && $userId) {
            $payload = trim((string)($update['payload'] ?? $update['start_payload'] ?? ''));
            $meta=TrafficAttributionService::parseStartPayload($payload);
            $yclid=(string)($meta['yclid']??'');
            $region=(string)($meta['region_id']??'');
            $campaign=(string)($meta['campaign_id']??'');
            $entry=(string)($meta['entry_channel']??'');

            if ($yclid !== '') MaxSearchApi::addYclid($internalId, $yclid);
            TrafficAttributionService::save(dirname(__DIR__),$internalId,$yclid,$region,$campaign,$payload,$entry);
            MaxSearchApi::funnelLog($internalId, 'bot_started', ['payload'=>$payload,'entry_channel'=>$entry]);

            MaxSearchApi::cancelToursFollowup($internalId);
            MaxSearchApi::deleteAllStatus($internalId);
            MaxSearchApi::setEditMode($internalId, '');
            if (class_exists('AiShadowObserver')) AiShadowObserver::clear($internalId);
            if (class_exists('DestinationResolver')) DestinationResolver::clear($internalId);
            MaxSearchApi::showStart($internalId);
        }
        elseif (in_array($type, ['message_created','message_callback'], true) && $userId) {
            $incoming = MaxIncomingAdapter::fromUpdate($update);
            if ($incoming) {
                $dispatcher = new IncomingUpdateDispatcher();
                $dispatcher->dispatch($incoming);
            }
        }

        http_response_code(200);
        echo 'ok';
    }
}