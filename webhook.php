<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
require_once(__DIR__ . '/maxsearchclass.php');
require_once(__DIR__ . '/ai/AiRouter.php');
require_once(__DIR__ . '/services/DepartureCityResolver.php');
require_once(__DIR__ . '/services/DestinationAreaResolver.php');
require_once(__DIR__ . '/services/DestinationResolver.php');
require_once(__DIR__ . '/handlers/AiDateHandler.php');
require_once(__DIR__ . '/handlers/AiMessageHandler.php');
require_once(__DIR__ . '/handlers/AiShortAnswerHandler.php');
require_once(__DIR__ . '/handlers/AiShadowObserver.php');
require_once(__DIR__ . '/handlers/DepartureRouteAdviceHandler.php');
require_once(__DIR__ . '/handlers/CallbackHandler.php');
require_once(__DIR__ . '/handlers/StateMessageHandler.php');
require_once(__DIR__ . '/handlers/MaxUpdateHandler.php');

$is_log = true;

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
			MaxSearchApi::deleteAllStatus($chat_id);
			MaxSearchApi::setEditMode($chat_id,'');
            DestinationResolver::clear($chat_id);
			MaxSearchApi::showStart($chat_id);
		}
		elseif($message['text']=="/start" || $message['text']=="МЕНЮ" )
		{
			MaxSearchApi::cancelToursFollowup($chat_id);
			MaxSearchApi::deleteAllStatus($chat_id);
			MaxSearchApi::setEditMode($chat_id,'');
			AiDateHandler::clear($chat_id);
            DestinationResolver::clear($chat_id);
			MaxSearchApi::showStart($chat_id);

		}
		else
		{
			$plainText = trim((string)$message['text']);
			if (preg_match('/^(?:дорого|очень дорого|дороговато|слишком дорого)[.!? ]*$/ui', $plainText)) {
				MaxSearchApi::MaxSend(
					"Поняла. Давайте попробуем удешевить подбор.\n\nМожно:\n• немного сдвинуть даты;\n• сократить количество ночей;\n• снизить категорию отеля;\n• посмотреть другое направление.\n\nНапишите, что готовы изменить — я пересоберу поиск.",
					$chat_id
				);
				return;
			}

			$status = MaxSearchApi::getCurentStatus($chat_id);
            if($status==MaxSearchApi::$statusAi || !$status || $status==MaxSearchApi::$statusStart)
            {
                DepartureCityResolver::resolveAndStore($chat_id, $message['text']);
                DestinationAreaResolver::resolveAndStore($chat_id, $message['text']);
                DestinationResolver::resolveAndStore($chat_id, $message['text']);

                // V2 shadow mode: independently extract structured TripState changes and
                // calculate RulesEngine action. It never writes trip parameters and never
                // changes the user-visible response; results go only to structured_events.log.
                AiShadowObserver::observe($chat_id, (string)$message['text']);

                if (DepartureRouteAdviceHandler::handle($chat_id, $message['text'])) {
                    return;
                }

                if (!AiShortAnswerHandler::handle($message, $chat_id)) {
                    AiMessageHandler::handle($message, $chat_id);
                }
            }
            else
            {
                StateMessageHandler::handle($message, $chat_id, $status);
            }
		}
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

MaxUpdateHandler::handle();