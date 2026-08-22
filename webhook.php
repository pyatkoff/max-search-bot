<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
require_once(__DIR__ . '/maxsearchclass.php');
require_once(__DIR__ . '/ai/AiRouter.php');
require_once(__DIR__ . '/handlers/AiDateHandler.php');
require_once(__DIR__ . '/handlers/AiMessageHandler.php');
require_once(__DIR__ . '/handlers/CallbackHandler.php');
require_once(__DIR__ . '/handlers/StateMessageHandler.php');

$is_log = true;

// MAX user_id сохраняем в существующих HL-блоках как отрицательное число.
// Это изолирует состояние MAX от Telegram, не меняя текущую структуру HL.
function maxInternalUserId($userId) {
    $id = (int)$userId;
    return $id > 0 ? -$id : $id;
}

function maxExtractUser(array $update) {
    if (!empty($update['callback']['user'])) return $update['callback']['user'];
    if (!empty($update['message']['sender'])) return $update['message']['sender'];
    if (!empty($update['user'])) return $update['user'];
    return [];
}

function maxExtractUserId(array $update) {
    $user = maxExtractUser($update);
    return (int)($user['user_id'] ?? $user['id'] ?? 0);
}

function maxExtractText(array $update) {
    return (string)($update['message']['body']['text'] ?? $update['message']['text'] ?? '');
}

function maxExtractContactPhone(array $update) {
    $attachments = $update['message']['body']['attachments'] ?? $update['message']['attachments'] ?? [];
    foreach ((array)$attachments as $attachment) {
        if (($attachment['type'] ?? '') !== 'contact') continue;
        $payload = $attachment['payload'] ?? [];
        $vcf = (string)($payload['vcf_info'] ?? '');
        if ($vcf !== '' && preg_match('/TEL[^:]*:([+0-9]+)/i', $vcf, $m)) {
            return trim($m[1]);
        }
        foreach (['phone','phone_number'] as $key) {
            if (!empty($payload[$key])) return trim((string)$payload[$key]);
        }
    }
    return '';
}

function maxUserAsTelegramLike(array $user) {
    $name = trim((string)($user['name'] ?? ''));
    $parts = $name !== '' ? preg_split('/\s+/', $name, 2) : [];
    return [
        'id' => maxInternalUserId((int)($user['user_id'] ?? $user['id'] ?? 0)),
        'first_name' => (string)($user['first_name'] ?? ($parts[0] ?? '')),
        'last_name' => (string)($user['last_name'] ?? ($parts[1] ?? '')),
        'username' => (string)($user['username'] ?? ''),
    ];
}

function maxQueryUserName($query) {
	$name = "";
	if(!empty($query['from']["first_name"])) {
		$name = $query['from']["first_name"];
		if(!empty($query['from']["last_name"]))
			$name .= " ".$query['from']["last_name"];
	}
	elseif(!empty($query['from']["username"])) {
		$name = $query['from']["username"];
	}
	return trim($name);
}

function processMessage($message) {
	  // process incoming message
	$message_id = $message['message_id'];
	$chat_id = $message['chat']['id'];
	put_log_in($chat_id."!!!!!!!!!!!".$message['text']); 
	if (isset($message['text'])) 
	{
		if(strpos($message['text'],"/start")===0 && $message['text']!="/start")
		{
			$text = trim(str_replace("/start","",$message['text']));
			if(strpos($text,"ya")===0)
			{
				$text = trim(str_replace("ya","",$text));
				MaxSearchApi::addYclid($chat_id,$text);
			}

			MaxSearchApi::cancelToursFollowup($chat_id);
			MaxSearchApi::cancelToursFollowup($chat_id);
			MaxSearchApi::deleteAllStatus($chat_id);
			MaxSearchApi::setEditMode($chat_id,'');
			MaxSearchApi::showStart($chat_id);
		}
		elseif($message['text']=="/start" || $message['text']=="МЕНЮ" )
		{
			// Новый старт полностью завершает предыдущий сценарий,
			// включая отложенный follow-up.
			MaxSearchApi::cancelToursFollowup($chat_id);
			MaxSearchApi::deleteAllStatus($chat_id);
			MaxSearchApi::setEditMode($chat_id,'');
			AiDateHandler::clear($chat_id);
			MaxSearchApi::showStart($chat_id);

		}
		else
		{
			// STEP 4C: возражение "дорого" работает глобально, а не только в AI-статусе.
			$plainText = trim((string)$message['text']);
			if (preg_match('/^(?:дорого|очень дорого|дороговато|слишком дорого)[.!? ]*$/ui', $plainText)) {
				MaxSearchApi::MaxSend(
					"Поняла. Давайте попробуем удешевить подбор.\n\nМожно:\n• немного сдвинуть даты;\n• сократить количество ночей;\n• снизить категорию отеля;\n• посмотреть другое направление.\n\nНапишите, что готовы изменить — я пересоберу поиск.",
					$chat_id
				);
				return;
			}

			$status = MaxSearchApi::getCurentStatus($chat_id);
			//MaxSearchApi::showCheckButtons($chat_id);
            if($status==MaxSearchApi::$statusAi || !$status || $status==MaxSearchApi::$statusStart)
            {
                AiMessageHandler::handle($message, $chat_id);
            }
            else
            {
                StateMessageHandler::handle($message, $chat_id, $status);
            }
		}

		//MaxSearchApi::showCalendarButtons($chat_id,date("m"),date("Y"));
		//$res = MaxSearchApi::TelegramRequest("sendMessage", array('chat_id' => $chat_id, "text" => "!", "reply_markup"=>['keyboard' => [["МЕНЮ"]],'resize_keyboard' => true,'one_time_keyboard' => true]));
		//add2log($res);
	} 	
}
function processQuery($query) {
	CallbackHandler::handle($query);
}


function put_log_in($data){
	global $is_log;
	if($is_log) {file_put_contents("tmp_in.txt", date('d.m.Y H:i:s')."--- ".$data."\r\n", FILE_APPEND);}
}

function put_log_out($data){
	global $is_log;
	if($is_log) {file_put_contents("tmp_out.txt", date('d.m.Y H:i:s')."--- ".$data."\r\n", FILE_APPEND);}
}





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
