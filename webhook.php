<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
require_once(__DIR__ . '/maxsearchclass.php');
require_once(__DIR__ . '/ai/AiRouter.php');

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
			MaxSearchApi::deleteAllStatus($chat_id);
			MaxSearchApi::setEditMode($chat_id,'');
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
                $userText = trim((string)$message['text']);
                MaxSearchApi::funnelLog($chat_id,'ai_text',['text'=>function_exists('mb_substr')?mb_substr($userText,0,300,'UTF-8'):substr($userText,0,300)]);
                if($userText === '') {
                    MaxSearchApi::MaxSend("Напишите, какой тур вы ищете — можно обычными словами.", $chat_id);
                    return;
                }

                $current = MaxSearchApi::getAiSearchContext($chat_id);

                // FIX6: СНАЧАЛА классифицируем сообщение.
                // Большой полноценный запрос вообще не трогаем локальным парсером —
                // сразу отдаём его AiRouter целиком.
                $routeText = function_exists('mb_strtolower')
                    ? mb_strtolower($userText, 'UTF-8')
                    : strtolower($userText);
                $routeLen = function_exists('mb_strlen')
                    ? mb_strlen($userText, 'UTF-8')
                    : strlen($userText);

                $richTourRequest =
                    $routeLen > 55 ||
                    substr_count($userText, ',') >= 2 ||
                    preg_match('/\b\d+\s*(?:взросл|реб[её]н|дет)/ui',$userText) ||
                    preg_match('/\b\d+\s*(?:-|–|—)\s*\d+\s*ноч/ui',$userText) ||
                    preg_match('/(?:отел|зв[её]зд|пляж|понтон|бухт|курорт|район|шарм|хургада)/ui',$userText);

                $ai = null;

                if ($richTourRequest) {
                    // Полный запрос -> сразу AI, без local apply и без раннего return.
                    @file_put_contents(
                        __DIR__.'/ai_debug.log',
                        "\n".date('d.m.Y H:i:s')."--- chat=".$chat_id." ---\n".
                        "ROUTE: RICH_AI\n".
                        "AI INPUT: ".$userText."\n".
                        "AI CONTEXT BEFORE: ".json_encode($current,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",
                        FILE_APPEND|LOCK_EX
                    );

                    try {
                        $ai = AiRouter::parseTourRequest($userText, $current);
                        @file_put_contents(
                            __DIR__.'/ai_debug.log',
                            "AI RAW: ".json_encode($ai,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",
                            FILE_APPEND|LOCK_EX
                        );
                    } catch (\Throwable $e) {
                        @file_put_contents(
                            __DIR__.'/ai_errors.log',
                            date('d.m.Y H:i:s').'--- chat='.$chat_id.' --- '.$e->getMessage().PHP_EOL,
                            FILE_APPEND|LOCK_EX
                        );
                        $ai=['_error'=>true];
                    }
                } else {
                    // Только короткие сообщения и простые коррекции идут в local fallback.
                    $localParams = [];
                    $localText = $routeText;

                    if (empty($current['city'])) {
                        $localParams['city'] = 'Москва';
                    }

                    $localCountries = [
                        'турц'=>'Турция',
                        'егип'=>'Египет',
                        'таиланд'=>'Таиланд',
                        'тайланд'=>'Таиланд',
                        'оаэ'=>'ОАЭ',
                        'эмират'=>'ОАЭ',
                        'мальдив'=>'Мальдивы',
                        'шри-ланк'=>'Шри-Ланка',
                        'китай'=>'Китай',
                        'хайнан'=>'Китай'
                    ];
                    foreach ($localCountries as $stem=>$name) {
                        if (strpos($localText,$stem)!==false) {
                            $localParams['country']=$name;
                            break;
                        }
                    }

                    if (
                        strpos($localText,'на двоих')!==false ||
                        strpos($localText,'вдвоем')!==false ||
                        strpos($localText,'вдвоём')!==false
                    ) {
                        $localParams['adults']=2;
                        $localParams['children']=0;
                    }

                    if (
                        strpos($localText,'без детей')!==false ||
                        strpos($localText,'детей нет')!==false
                    ) {
                        $localParams['children']=0;
                    }

                    if (preg_match('/(?:на\s+)?недел(?:ю|ьку)/ui',$userText)) {
                        $localParams['nights']='7';
                    }

                    // Дата для коротких сообщений.
                    $localMonthOnly=false;
                    $localResolvedDate='';
                    $localMonths = [
                        'январ'=>1, 'феврал'=>2, 'март'=>3, 'апрел'=>4,
                        'мае'=>5, 'май'=>5, 'июн'=>6, 'июл'=>7, 'август'=>8,
                        'сентябр'=>9, 'октябр'=>10, 'ноябр'=>11, 'декабр'=>12
                    ];

                    $localMonthNum=0;
                    foreach ($localMonths as $monthStem=>$monthNum) {
                        if (strpos($localText,$monthStem)!==false) {
                            $localMonthOnly=true;
                            $localMonthNum=$monthNum;
                            break;
                        }
                    }

                    if (preg_match('/\bзавтра\b/ui',$userText)) {
                        $localResolvedDate=date('d.m.Y',strtotime('+1 day'));
                    } elseif (preg_match('/\bпослезавтра\b/ui',$userText)) {
                        $localResolvedDate=date('d.m.Y',strtotime('+2 days'));
                    } elseif (preg_match('/\b(?:ближайш(?:ая|ие|ую)|как\s+можно\s+скорее|поскорее)\b/ui',$userText)) {
                        $localResolvedDate=date('d.m.Y',strtotime('+1 day'));
                    } elseif ($localMonthNum>0) {
                        $localYear=(int)date('Y');
                        if($localMonthNum < (int)date('n')) $localYear++;

                        $localDay=0;
                        if (preg_match('/(?:в\s+)?начал(?:е|о)\s+[а-яё]+/ui',$userText)) {
                            $localDay=5;
                        } elseif (preg_match('/(?:в\s+)?середин(?:е|у)\s+[а-яё]+/ui',$userText)) {
                            $localDay=15;
                        } elseif (preg_match('/(?:в\s+)?конц(?:е|а)\s+[а-яё]+/ui',$userText)) {
                            $localDay=25;
                        } elseif (preg_match('/после\s+(\d{1,2})\s+[а-яё]+/ui',$userText,$dm)) {
                            $localDay=min(28,((int)$dm[1])+1);
                        } elseif (preg_match('/\b(\d{1,2})\s+[а-яё]+/ui',$userText,$dm)) {
                            $localDay=(int)$dm[1];
                        }

                        if ($localDay>0 && checkdate($localMonthNum,$localDay,$localYear)) {
                            $localResolvedDate=sprintf('%02d.%02d.%04d',$localDay,$localMonthNum,$localYear);
                        }
                    }

                    if ($localResolvedDate!=='') {
                        $localParams['date']=$localResolvedDate;
                        $localMonthOnly=false;
                    }

                    if (!empty($localParams)) {
                        $tmpCountry=$localParams['country'] ?? ($current['country'] ?? '');
                        $tmpCountryKey=function_exists('mb_strtolower')
                            ? mb_strtolower((string)$tmpCountry,'UTF-8')
                            : strtolower((string)$tmpCountry);
                        if (in_array($tmpCountryKey,['турция','египет'],true)) {
                            if (empty($current['meal'])) $localParams['meal']='all_inclusive';
                            if (empty($current['stars'])) $localParams['stars']=4;
                        }

                        $hadCurrentBeforeLocal = !empty($current);
                        $appliedLocal = MaxSearchApi::applyAiParameters($chat_id,$localParams);
                        $current=MaxSearchApi::getAiSearchContext($chat_id);
                    } else {
                        $hadCurrentBeforeLocal = !empty($current);
                        $appliedLocal = [];
                    }

                    $missingLocal=MaxSearchApi::getAiMissingFields($chat_id);
                    $simpleLocal =
                        $routeLen <= 55 &&
                        !preg_match('/(?:где\s+дешевле|что\s+лучше|сравни|посоветуй|почему)/ui',$userText);

                    if ($simpleLocal && empty($missingLocal) && $hadCurrentBeforeLocal && !empty($appliedLocal)) {
                        MaxSearchApi::showCheckButtons($chat_id);
                        return;
                    }

                    if ($simpleLocal && !empty($missingLocal)) {
                        $localFallback=[
                            'city'=>'Из какого города планируете вылет?',
                            'country'=>'Куда хотите поехать?',
                            'adults'=>'Сколько будет взрослых туристов?',
                            'children'=>'Будут дети? Если да — сколько?',
                            'child_ages'=>'Сколько лет детям?',
                            'stars'=>'Какая минимальная категория отеля нужна — 3, 4 или 5 звёзд?',
                            'meal'=>'Какое питание предпочитаете?',
                            'nights'=>'На сколько ночей планируете поездку?',
                            'date'=>$localMonthOnly
                                ? 'Подскажите ориентировочную дату вылета в этом месяце — например, в начале, середине или конце.'
                                : 'Какая ориентировочная дата вылета?'
                        ];
                        MaxSearchApi::setStatus($chat_id,MaxSearchApi::$statusAi);
                        MaxSearchApi::MaxSend(
                            $localFallback[$missingLocal[0]] ?? 'Уточните, пожалуйста, параметры поездки.',
                            $chat_id
                        );
                        return;
                    }

                    // Сложный, но короткий текст — всё равно отдаём AI.
                    @file_put_contents(
                        __DIR__.'/ai_debug.log',
                        "\n".date('d.m.Y H:i:s')."--- chat=".$chat_id." ---\n".
                        "ROUTE: SHORT_AI\n".
                        "AI INPUT: ".$userText."\n".
                        "AI CONTEXT BEFORE: ".json_encode($current,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",
                        FILE_APPEND|LOCK_EX
                    );

                    try {
                        $ai = AiRouter::parseTourRequest($userText, $current);
                        @file_put_contents(
                            __DIR__.'/ai_debug.log',
                            "AI RAW: ".json_encode($ai,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",
                            FILE_APPEND|LOCK_EX
                        );
                    } catch (\Throwable $e) {
                        @file_put_contents(
                            __DIR__.'/ai_errors.log',
                            date('d.m.Y H:i:s').'--- chat='.$chat_id.' --- '.$e->getMessage().PHP_EOL,
                            FILE_APPEND|LOCK_EX
                        );
                        $ai=['_error'=>true];
                    }
                }

                // FIX7: старый второй routing-проход удалён.
                // $ai уже получен выше либо через RICH_AI, либо через SHORT_AI.
                // Ни local fallback, ни второй AiRouter здесь повторно не запускаются.

                // STEP 3: бизнес-дефолты. Стартовую ветку не трогаем.
                if (is_array($ai) && empty($ai['_error'])) {
                    if (!isset($ai['parameters']) || !is_array($ai['parameters'])) {
                        $ai['parameters'] = [];
                    }

                    $p =& $ai['parameters'];

                    // Если город вылета не указан — Москва.
                    if (empty($p['city']) && empty($current['city'])) {
                        $p['city'] = 'Москва';
                    }

                    // "вдвоём / вдвоем / на двоих" = 2 взрослых, без детей.
                    $lt = function_exists('mb_strtolower')
                        ? mb_strtolower((string)$userText, 'UTF-8')
                        : strtolower((string)$userText);

                    if (
                        strpos($lt, 'вдвоём') !== false ||
                        strpos($lt, 'вдвоем') !== false ||
                        strpos($lt, 'на двоих') !== false
                    ) {
                        // Если человек говорит "на двоих", без упоминания детей,
                        // считаем это двумя взрослыми без детей.
                        if (empty($p['adults']) && empty($current['adults'])) {
                            $p['adults'] = 2;
                        }
                        if (
                            (!isset($p['children']) || $p['children'] === null || $p['children'] === '') &&
                            !array_key_exists('children', $current)
                        ) {
                            $p['children'] = 0;
                        }
                    }

                    $country = trim((string)($p['country'] ?? ($current['country'] ?? '')));
                    $countryKey = function_exists('mb_strtolower')
                        ? mb_strtolower($country, 'UTF-8')
                        : strtolower($country);

                    // Для Турции и Египта — разумные дефолты, если пользователь не указал другое.
                    if (in_array($countryKey, ['турция','египет'], true)) {
                        if (empty($p['meal']) && empty($current['meal'])) {
                            $p['meal'] = 'all_inclusive';
                        }
                        if (empty($p['stars']) && empty($current['stars'])) {
                            $p['stars'] = 4;
                        }
                    }
                }

                if (!is_array($ai) || !empty($ai['_error'])) {
                    put_log_out('AI ERROR: '.json_encode($ai, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

                    $missingAfterError=MaxSearchApi::getAiMissingFields($chat_id);
                    $fallbackAfterError=[
                        'city'=>'Из какого города планируете вылет?',
                        'country'=>'Куда хотите поехать?',
                        'adults'=>'Сколько будет взрослых туристов?',
                        'children'=>'Будут дети? Если да — сколько?',
                        'child_ages'=>'Сколько лет детям?',
                        'stars'=>'Какая минимальная категория отеля нужна — 3, 4 или 5 звёзд?',
                        'meal'=>'Какое питание предпочитаете?',
                        'nights'=>'На сколько ночей планируете поездку?',
                        'date'=>'Какая ориентировочная дата вылета?'
                    ];

                    MaxSearchApi::setStatus($chat_id,MaxSearchApi::$statusAi);
                    MaxSearchApi::MaxSend(
                        $fallbackAfterError[$missingAfterError[0]] ?? 'Уточните, пожалуйста, параметры поездки.',
                        $chat_id
                    );
                    return;
                }

                $params = is_array($ai['parameters'] ?? null) ? $ai['parameters'] : [];

                // FULL FIX1: дата может быть частью первого длинного запроса.
                // Если AI её не выделил — достаём понятные формулировки прямо из userText.
                if (empty($params['date'])) {
                    $dateTextAll = function_exists('mb_strtolower')
                        ? mb_strtolower($userText, 'UTF-8')
                        : strtolower($userText);

                    $resolvedInlineDate = '';
                    $monthsInline = [
                        'январ'=>1, 'феврал'=>2, 'март'=>3, 'апрел'=>4,
                        'ма'=>5, 'июн'=>6, 'июл'=>7, 'август'=>8,
                        'сентябр'=>9, 'октябр'=>10, 'ноябр'=>11, 'декабр'=>12
                    ];
                    $monthInline = 0;
                    foreach ($monthsInline as $stem=>$num) {
                        if (strpos($dateTextAll, $stem) !== false) {
                            $monthInline = $num;
                            break;
                        }
                    }

                    if (preg_match('/\bзавтра\b/ui', $dateTextAll)) {
                        $resolvedInlineDate = date('d.m.Y', strtotime('+1 day'));
                    } elseif (preg_match('/\bпослезавтра\b/ui', $dateTextAll)) {
                        $resolvedInlineDate = date('d.m.Y', strtotime('+2 days'));
                    } elseif (preg_match('/\b(?:ближайш(?:ая|ие|ую)|как\s+можно\s+скорее|поскорее)\b/ui', $dateTextAll)) {
                        $resolvedInlineDate = date('d.m.Y', strtotime('+1 day'));
                    } elseif ($monthInline > 0) {
                        $yearInline=(int)date('Y');
                        if($monthInline < (int)date('n')) $yearInline++;

                        $dayInline=0;
                        if (preg_match('/(?:в\s+)?начал(?:е|о)\s+[а-яё]+/ui', $dateTextAll)) {
                            $dayInline=5;
                        } elseif (preg_match('/(?:в\s+)?середин(?:е|у)\s+[а-яё]+/ui', $dateTextAll)) {
                            $dayInline=15;
                        } elseif (preg_match('/(?:в\s+)?конц(?:е|а)\s+[а-яё]+/ui', $dateTextAll)) {
                            $dayInline=25;
                        } elseif (preg_match('/после\s+(\d{1,2})\s+[а-яё]+/ui', $dateTextAll, $dm)) {
                            $dayInline=min(28,((int)$dm[1])+1);
                        } elseif (preg_match('/\b(\d{1,2})\s+[а-яё]+/ui', $dateTextAll, $dm)) {
                            $dayInline=(int)$dm[1];
                        }

                        if ($dayInline > 0 && checkdate($monthInline,$dayInline,$yearInline)) {
                            $resolvedInlineDate=sprintf('%02d.%02d.%04d',$dayInline,$monthInline,$yearInline);
                        }
                    }

                    if ($resolvedInlineDate !== '') {
                        $params['date']=$resolvedInlineDate;
                    }
                }

                @file_put_contents(
                    __DIR__.'/ai_debug.log',
                    "ROUTE AFTER AI: APPLY_PARAMETERS\n",
                    FILE_APPEND|LOCK_EX
                );
                $appliedResult = MaxSearchApi::applyAiParameters($chat_id, $params);
                $missing = MaxSearchApi::getAiMissingFields($chat_id);

                @file_put_contents(
                    __DIR__.'/ai_debug.log',
                    "AI PARAMS: ".json_encode($params,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n".
                    "AI APPLIED: ".json_encode($appliedResult,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n".
                    "AI CONTEXT AFTER: ".json_encode(MaxSearchApi::getAiSearchContext($chat_id),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n".
                    "AI MISSING: ".json_encode($missing,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",
                    FILE_APPEND|LOCK_EX
                );

                if (empty($missing)) {
                    MaxSearchApi::showCheckButtons($chat_id);
                    return;
                }

                // Возвращаем AI-статус последним, чтобы следующее текстовое сообщение снова разбирал AI.
                MaxSearchApi::setStatus($chat_id, MaxSearchApi::$statusAi);

                // ВАЖНО: после применения бизнес-дефолтов AI-вопрос может быть уже устаревшим.
                // Поэтому следующий вопрос строим только по ФАКТИЧЕСКИ первому missing-полю.
                $fallback = [
                    'city'=>'Из какого города планируете вылет?',
                    'country'=>'В какую страну хотите поехать?',
                    'adults'=>'Сколько будет взрослых туристов?',
                    'children'=>'Будут дети? Если да — сколько?',
                    'child_ages'=>'Сколько лет детям?',
                    'stars'=>'Какая минимальная категория отеля нужна — 3, 4 или 5 звёзд?',
                    'meal'=>'Какое питание предпочитаете?',
                    'nights'=>'На сколько ночей планируете поездку?',
                    'date'=>'Какая ориентировочная дата вылета?'
                ];

                $question = $fallback[$missing[0]] ?? 'Уточните, пожалуйста, параметры поездки.';
                MaxSearchApi::MaxSend($question, $chat_id);
            }
            elseif($status==MaxSearchApi::$statusCityChoose)
			{
				$city = trim($message['text']);
				$cityRes =  MaxSearchApi::getCityByName($city);
				if($cityRes)
				{
					MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusCityChoose,$cityRes["ID"]);
					if(!MaxSearchApi::finishEditIfNeeded($chat_id,'city'))
						MaxSearchApi::showCountryButtons($chat_id);
				}	
				else
					MaxSearchApi::MaxSend("Не нашла такой город вылета. Проверьте название или выберите один из предложенных вариантов.",$chat_id);
				
			}
			elseif($status==MaxSearchApi::$statusContryChoose)
			{
				$country = trim($message['text']);
				$countryRes =  MaxSearchApi::getCountryByName($country);
				if($countryRes)
				{
					MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusContryChoose,$countryRes["ID"]);
					if(!MaxSearchApi::finishEditIfNeeded($chat_id,'country'))
						MaxSearchApi::showAdultsButtons($chat_id);
				}	
				else
					MaxSearchApi::MaxSend("Не нашла это направление в поиске. Проверьте название или выберите одну из популярных стран.",$chat_id);
				
			}
			elseif($status==MaxSearchApi::$statusAge)
			{
				$age = $message['text'];
				$error = false;
				$childCount = MaxSearchApi::getLastValue($chat_id,MaxSearchApi::$statusChild);
				preg_match('/[^\d\s,]{1,}/', $age, $checkArray);
				if(is_array($checkArray) && count($checkArray)>0)
					$error = true;
				else
				{
					$sep = " ";
					if(strpos($age,",")!==false)
						$sep = ",";
					$ageArr = explode($sep,$age);
					$ageOut = [];
					foreach($ageArr as $ageItem)
					{
						$ageItem = intval(trim($ageItem));
						if($ageItem<0 || $ageItem>17)
						{
							$error = true;
							break;
						}	
						else
						$ageOut[] = $ageItem;
					}
					if(!$error && count($ageOut)!=$childCount)
						$error = true;
					if(!$error)
					{
						$ageOut = implode(", ",$ageOut);
						MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusAge,$ageOut);
						if(!MaxSearchApi::finishEditIfNeeded($chat_id,'tourists'))
							MaxSearchApi::showStarsButtons($chat_id);
					}			
				}
				if($error)
				{
					if(intval($childCount)==1)
						MaxSearchApi::MaxSend("К сожалению возраст ребенка указан неверно. Пожалуйста, введите 1 число в диапазоне от 0 до 17.",$chat_id);
					else
						MaxSearchApi::MaxSend("К сожалению возраст детей указан неверно. Пожалуйста, введите ".$childCount." числа через разделитель (пробел или запятая) в диапазоне от 0 до 17.",$chat_id);
				}
				
			}
			elseif($status==MaxSearchApi::$statusNights)
			{
				$nights = $message['text'];
				$error = false;
				preg_match('/[^\d\s\-]{1,}/', $nights, $checkArray);
				if(is_array($checkArray) && count($checkArray)>0)
					$error = true;
				else
				{
					$sep = " ";
					if(strpos($nights,"-")!==false)
						$sep = "-";
					$nightsArr = explode($sep,$nights);	
					$nightsOut = [];
					foreach($nightsArr as $nightItem)
					{
						$nightItem = intval(trim($nightItem));
						if($nightItem<=0 || $nightItem>28)
						{
							$error = true;
							break;
						}	
						else
							$nightsOut[] = $nightItem;
					}
					if(!$error && (count($nightsOut)<=0 ||  count($nightsOut)>2))
						$error = true;
					if(!$error)
					{
						$nightsOut = implode("-",$nightsOut);
						MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusNights,$nightsOut);
						if(!MaxSearchApi::finishEditIfNeeded($chat_id,'nights'))
							MaxSearchApi::showCalendarButtons($chat_id,date("m"),date("Y"));
					}	
				}	
				if($error)
					MaxSearchApi::MaxSend("К сожалению диапазон ночей указан неверно. Пожалуйста, укажите одно число или два числа через разделитель (пробел или дефис) в диапазоне от 1 до 28.",$chat_id);

			}	
			elseif($status==MaxSearchApi::$statusPhone)
			{
				$phone = $message['text'];
				$error = false;
				if(strlen($phone)!=12 || strpos($phone,"+7")!==0)
					$error = true;
				else
				{
					$subPhone = substr($phone,2);
					preg_match('/[^\d]{1,}/', $subPhone, $checkArray);
					if(is_array($checkArray) && count($checkArray)>0)
						$error = true;
				}	
				if($error) 
					MaxSearchApi::MaxSend("Не получилось распознать номер. Напишите его в формате +71234567890.",$chat_id);
				else
				{
					$ok = MaxSearchApi::savePhone($chat_id, $phone);
					MaxSearchApi::deletePrevMessage($chat_id,true);
					MaxSearchApi::deleteAllStatus($chat_id);
					if($ok)
						MaxSearchApi::showChannelOffer($chat_id,true);
					else
						MaxSearchApi::MaxSend("Не получилось сохранить номер. Попробуйте ещё раз.",$chat_id);
				}	
			}	
		}

		//MaxSearchApi::showCalendarButtons($chat_id,date("m"),date("Y"));
		//$res = MaxSearchApi::TelegramRequest("sendMessage", array('chat_id' => $chat_id, "text" => "!", "reply_markup"=>['keyboard' => [["МЕНЮ"]],'resize_keyboard' => true,'one_time_keyboard' => true]));
		//add2log($res);
	} 	
}
function processQuery($query) {
	$chat_id = $query['from']['id'];
	$q       = $query['data'];
	//put_log_out($chat_id."!!".$q);
	//MaxSearchApi::MaxSend($q,$chat_id);
	if($q =="ai_start")
	{
		MaxSearchApi::funnelLog($chat_id,'ai_start');
		MaxSearchApi::showAiStart($chat_id);
	}
	elseif($q =="start_search" || $q =="back_pick_city")
	{
		if($q =="start_search")
			MaxSearchApi::funnelLog($chat_id,'start_search');
		if($q =="back_pick_city")
			MaxSearchApi::deletePrevMessage($chat_id,true);
		MaxSearchApi::showCityButtons($chat_id);
	}
	elseif(strpos($q,"pick_city_")===0 || $q=="back_pick_country")
	{
		if($q=="pick_city_other")
		{
			MaxSearchApi::showCityOtherButtons($chat_id);
		}
		else
		{
			if($q=="back_pick_country")
				MaxSearchApi::deletePrevMessage($chat_id,true);
			else
			{
				$city = str_replace("pick_city_","",$q);
				MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusCityChoose,$city);
			}
			MaxSearchApi::showCountryButtons($chat_id);
		}
	}
	elseif($q=="pick_country_other")
	{
		MaxSearchApi::deletePrevMessage($chat_id);
		$buttons = [[['text'=>'← Назад','callback_data'=>'back_pick_country']]];
		$messID = MaxSearchApi::MaxSendWithButtons("🌍 <b>Введите страну</b>\n\nНапишите название направления, которое хотите рассмотреть.", $chat_id, $buttons);
		MaxSearchApi::setStatus($chat_id,MaxSearchApi::$statusContryChoose,$messID);
	}
	elseif(strpos($q,"pick_country_")===0 || $q=="back_adults")
	{
		if($q=="back_adults")
			MaxSearchApi::deletePrevMessage($chat_id,true);
		else
		{
			MaxSearchApi::funnelLog($chat_id,'country_selected',['payload'=>$q]);
			$country = str_replace("pick_country_","",$q);
			MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusContryChoose,$country);
			if(MaxSearchApi::finishEditIfNeeded($chat_id,'country')) return;
		}
		MaxSearchApi::showAdultsButtons($chat_id);
		
	}
	elseif(strpos($q,"adults_")===0 || $q=="back_child")
	{
		if(strpos($q,"adults_")===0)
			MaxSearchApi::funnelLog($chat_id,'tourists_selected',['stage'=>'adults','payload'=>$q]);
		if($q=="back_child")
			MaxSearchApi::deletePrevMessage($chat_id,true);
		else
		{
			$adults = str_replace("adults_","",$q);
			MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusAdults,$adults);
		}	
		MaxSearchApi::showChildButtons($chat_id);
		
	}
	elseif(strpos($q,"child_")===0 || $q=="back_stars")
	{
		if(strpos($q,"child_")===0)
			MaxSearchApi::funnelLog($chat_id,'tourists_selected',['stage'=>'children','payload'=>$q]);
		if($q=="back_stars")
		{
			MaxSearchApi::deletePrevMessage($chat_id,true);
			MaxSearchApi::showStarsButtons($chat_id);
		}	
		else
		{
			$child = str_replace("child_","",$q);
			MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusChild,$child);
			if($child==0) {
				if(!MaxSearchApi::finishEditIfNeeded($chat_id,'tourists'))
					MaxSearchApi::showStarsButtons($chat_id);
			}
			else
				MaxSearchApi::showAgeButtons($chat_id,intval($child));
		}	
	}
	elseif(strpos($q,"star_")===0 || $q=="back_meal")
	{
		if($q=="back_meal")
			MaxSearchApi::deletePrevMessage($chat_id,true);
		else
		{
			$star = str_replace("star_","",$q);
			MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusStars,$star);
			if(MaxSearchApi::finishEditIfNeeded($chat_id,'stars')) return;
		}	
		MaxSearchApi::showMealButtons($chat_id);
	}
	elseif(strpos($q,"meal_")===0 || $q=="back_nights")
	{
		if($q=="back_nights")
			MaxSearchApi::deletePrevMessage($chat_id,true);
		else
		{
			$meal = str_replace("meal_","",$q);
			MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusMeal,$meal);
			if(MaxSearchApi::finishEditIfNeeded($chat_id,'meal')) return;
		}	
		MaxSearchApi::showNightsButtons($chat_id);
		
	}
	elseif($q=="nights_other")
	{
		MaxSearchApi::deletePrevMessage($chat_id);
		$buttons = [[['text'=>'← Назад','callback_data'=>'back_nights']]];
		$messID = MaxSearchApi::MaxSendWithButtons("🌙 <b>Введите количество ночей</b>\n\nНапример: 7 или диапазон 7-10.", $chat_id, $buttons);
		MaxSearchApi::setStatus($chat_id,MaxSearchApi::$statusNights,$messID);
	}
	elseif(strpos($q,"nights_")===0 || $q=="back_calendar")
	{
		if($q=="back_calendar")
			MaxSearchApi::deletePrevMessage($chat_id,true);
		else
		{
			$nights = str_replace("nights_","",$q);
			$nights = str_replace("_","-",$nights);
			MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusNights,$nights);
			if(MaxSearchApi::finishEditIfNeeded($chat_id,'nights')) return;
		}
		MaxSearchApi::showCalendarButtons($chat_id,date("m"),date("Y"));
		
	}
	elseif(strpos($q,"pick_date_")===0 || $q=="back_check")
	{
		if($q=="back_check")
			MaxSearchApi::deletePrevMessage($chat_id,true);
		else
		{
			$date = str_replace("pick_date_","",$q);
			MaxSearchApi::saveLastValue($chat_id,MaxSearchApi::$statusDate,$date);
			if(MaxSearchApi::finishEditIfNeeded($chat_id,'date')) return;
		}
		MaxSearchApi::showCheckButtons($chat_id);
	}
	elseif(strpos($q,"month_change_")===0)
	{
		
		$monthYear = str_replace("month_change_","",$q);
		if($monthYear!="")
		{
			$arr = explode(".",$monthYear);
			MaxSearchApi::showCalendarButtons($chat_id,$arr[0],$arr[1]);
		}	
		
	}
	elseif($q =="edit_params")
	{
		MaxSearchApi::cancelToursFollowup($chat_id);
		MaxSearchApi::showEditParamsButtons($chat_id);
	}
	elseif($q =="edit_city")
	{
		MaxSearchApi::setEditMode($chat_id,'city');
		MaxSearchApi::showCityButtons($chat_id);
	}
	elseif($q =="edit_country")
	{
		MaxSearchApi::setEditMode($chat_id,'country');
		MaxSearchApi::showCountryButtons($chat_id);
	}
	elseif($q =="edit_tourists")
	{
		MaxSearchApi::setEditMode($chat_id,'tourists');
		MaxSearchApi::showAdultsButtons($chat_id);
	}
	elseif($q =="edit_stars")
	{
		MaxSearchApi::setEditMode($chat_id,'stars');
		MaxSearchApi::showStarsButtons($chat_id);
	}
	elseif($q =="edit_meal")
	{
		MaxSearchApi::setEditMode($chat_id,'meal');
		MaxSearchApi::showMealButtons($chat_id);
	}
	elseif($q =="edit_nights")
	{
		MaxSearchApi::setEditMode($chat_id,'nights');
		MaxSearchApi::showNightsButtons($chat_id);
	}
	elseif($q =="edit_date")
	{
		MaxSearchApi::setEditMode($chat_id,'date');
		MaxSearchApi::showCalendarButtons($chat_id,date("m"),date("Y"));
	}
	elseif($q =="show_tours" || strpos($q,"finish")===0)
	{
		$name = maxQueryUserName($query);
		MaxSearchApi::showToursChoice($chat_id,$name);
	}
	elseif($q =="manager_request")
	{
		MaxSearchApi::funnelLog($chat_id,'manager_request',['source'=>'before_site']);
		MaxSearchApi::queueMetrikaGoal($chat_id,'max_manager_request');
		$name = maxQueryUserName($query);
		MaxSearchApi::showManagerRequest($chat_id,$name,false);
	}
	elseif($q =="manager_after_tours")
	{
		MaxSearchApi::funnelLog($chat_id,'manager_request',['source'=>'followup']);
		MaxSearchApi::cancelToursFollowup($chat_id);
		MaxSearchApi::queueMetrikaGoal($chat_id,'max_manager_request');
		$name = maxQueryUserName($query);
		MaxSearchApi::showManagerRequest($chat_id,$name,true);
	}
	elseif($q =="tours_checked")
	{
		MaxSearchApi::showAfterToursQuestion($chat_id);
	}
	elseif($q =="tours_found")
	{
		MaxSearchApi::funnelLog($chat_id,'tours_found');
		MaxSearchApi::cancelToursFollowup($chat_id);
		MaxSearchApi::showChannelOffer($chat_id,false);
	}
	elseif($q =="phone_manual")
	{
		MaxSearchApi::deletePrevMessage($chat_id);
		$buttons = [[['text'=>'← Назад','callback_data'=>'tours_checked']]];
		$messID = MaxSearchApi::MaxSendWithButtons("📱 <b>Введите номер телефона</b>\n\nНапример: +71234567890", $chat_id, $buttons);
		MaxSearchApi::setStatus($chat_id,MaxSearchApi::$statusPhone,$messID);
	}
	elseif($q =="restart")
	{
		MaxSearchApi::deletePrevMessage($chat_id,true);
		MaxSearchApi::deleteAllStatus($chat_id);
		MaxSearchApi::showStart($chat_id);
	}
	elseif($q =="back_phone")
	{
		MaxSearchApi::deletePrevMessage($chat_id,true);
		MaxSearchApi::deleteAllStatus($chat_id);
	}

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
