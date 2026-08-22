<?php

class MaxUpdateHandler
{
    public static function handle()
    {
        $content = file_get_contents('php://input');
        put_log_in($content);
        $update = json_decode($content, true);

        // Если при подписке задан secret, принимаем webhook только с правильным заголовком MAX.
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
        $user = maxExtractUser($update);

        if ($type === 'bot_started' && $userId) {
            $payload = trim((string)($update['payload'] ?? $update['start_payload'] ?? ''));
            $yclid = '';
            $region = '';
            $campaign = '';

            if ($payload !== '') {
                $clean = preg_replace('/^ya/i', '', $payload);

                // Новый основной формат: {yclid}_region_{region_id}_campaign_{campaign_id}
                if (preg_match('/^(\d{6,})_region_([^_]*)_campaign_([^_]*)/i', $clean, $m)) {
                    $yclid = $m[1] ?? '';
                    $region = $m[2] ?? '';
                    $campaign = $m[3] ?? '';
                }
                // Старый рекламный формат:
                // {yclid}_key_{matched_keyword}_{region_id}_campaign_{campaign_id}
                elseif (preg_match('/^(\d{6,})_key_(.*?)_(\d+)_campaign_([^_]+)/i', $clean, $m)) {
                    $yclid = $m[1] ?? '';
                    $region = $m[3] ?? '';
                    $campaign = $m[4] ?? '';
                }
                // Короткий формат: ya_{yclid}_r_{region}_c_{campaign}
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
            MaxSearchApi::showStart($internalId);
        }
        elseif ($type === 'message_created' && $userId) {
            $contactPhone = maxExtractContactPhone($update);
            if ($contactPhone !== '' && MaxSearchApi::getCurentStatus($internalId)==MaxSearchApi::$statusPhone) {
                $ok = MaxSearchApi::savePhone($internalId,$contactPhone);
                if($ok) {
                    MaxSearchApi::deleteAllStatus($internalId);
                    MaxSearchApi::showChannelOffer($internalId,true);
                } else {
                    MaxSearchApi::MaxSend("Не получилось сохранить номер. Попробуйте отправить контакт ещё раз или введите номер вручную.",$internalId);
                }
            } else {
                $message = [
                    'message_id' => (string)($update['message']['body']['mid'] ?? ''),
                    'chat' => ['id' => $internalId],
                    'text' => maxExtractText($update),
                ];
                processMessage($message);
            }
        }
        elseif ($type === 'message_callback' && $userId) {
            $callbackId = (string)($update['callback']['callback_id'] ?? '');
            $payload = (string)($update['callback']['payload'] ?? '');
            $query = [
                'from' => maxUserAsTelegramLike($user),
                'data' => $payload,
            ];
            // Снимаем индикатор нажатия callback у пользователя.
            if ($callbackId !== '') MaxSearchApi::answerCallback($callbackId);
            processQuery($query);
        }

        http_response_code(200);
        echo 'ok';
    }
}
