<?php
require_once __DIR__ . '/../integrations/MaxIncomingAdapter.php';
require_once __DIR__ . '/../services/IncomingUpdateDispatcher.php';

class MaxUpdateHandler
{
    public static function handle()
    {
        $content = file_get_contents('php://input');
        put_log_in($content);
        $update = json_decode($content, true);

        $incomingSecret = $_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? '';
        if (defined('MAX_SEARCH_WEBHOOK_SECRET') && MAX_SEARCH_WEBHOOK_SECRET !== '' && !hash_equals(MAX_SEARCH_WEBHOOK_SECRET, (string)$incomingSecret)) {
            http_response_code(403);
            echo 'forbidden';
            exit;
        }

        if (!is_array($update)) {
            http_response_code(200);
            echo 'ok';
            exit;
        }

        $type = (string)($update['update_type'] ?? '');
        $userId = maxExtractUserId($update);
        $internalId = maxInternalUserId($userId);

        if ($type === 'bot_started' && $userId) {
            $payload = trim((string)($update['payload'] ?? $update['start_payload'] ?? ''));
            $yclid = '';
            $region = '';
            $campaign = '';

            if ($payload !== '') {
                $clean = preg_replace('/^ya/i', '', $payload);

                if (preg_match('/^(\d{6,})_region_([^_]*)_campaign_([^_]*)/i', $clean, $m)) {
                    $yclid = $m[1] ?? '';
                    $region = $m[2] ?? '';
                    $campaign = $m[3] ?? '';
                }
                elseif (preg_match('/^(\d{6,})_key_(.*?)_(\d+)_campaign_([^_]+)/i', $clean, $m)) {
                    $yclid = $m[1] ?? '';
                    $region = $m[3] ?? '';
                    $campaign = $m[4] ?? '';
                }
                elseif (preg_match('/^_?(\d{6,})_r_([^_]+)(?:_c_([^_]+))?/i', $clean, $m)) {
                    $yclid = $m[1] ?? '';
                    $region = $m[2] ?? '';
                    $campaign = $m[3] ?? '';
                }
                elseif (preg_match('/^(\d{6,})/', $clean, $m)) {
                    $yclid = $m[1];
                }
            }

            if ($yclid !== '') MaxSearchApi::addYclid($internalId, $yclid);
            MaxSearchApi::saveTrafficMeta($internalId,$yclid,$region,$campaign,$payload);
            MaxSearchApi::funnelLog($internalId,'bot_started',['payload'=>$payload]);

            MaxSearchApi::cancelToursFollowup($internalId);
            MaxSearchApi::deleteAllStatus($internalId);
            MaxSearchApi::setEditMode($internalId,'');
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
