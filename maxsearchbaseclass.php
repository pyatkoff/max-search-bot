<?php
//require_once($_SERVER["DOCUMENT_ROOT"].'/bitrix/modules/main/include/prolog_before.php');

class MaxSearchBase
{
	// Общие параметры заявок. Совпадают с текущим Telegram-проектом,
	// но сам MAX-бот работает отдельными файлами.
	static $claimIB = 4;
	static $claimStatusIDQueue = 9;
	static $botSearchSection = 26;
	
	static $TV_API_URL  = '';
	static $HL = "";
	static $claimHL = "";
	static $yclidHL = "";
	static $chanelSendHL = "";
	static $statusStart = "";
	static $statusCityChoose = "";
	static $statusContryChoose = "";
	static $statusAdults = "";
	static $statusChild = "";
	static $statusAge = "";
	static $statusStars = "";
	static $statusMeal = "";
	static $statusNights = "";
	static $statusDate = "";
	static $statusCheck = "";
	static $statusPhone = "";
	static $statusAi = "";
	
	static $baseDomain = "";
	static $chanelUrl = "";
	static $isAnyOnline = false;
	static $uonSourceId = 0;

	static $depHL = 1;
	static $contryHL = 2;
	static $regHL 	 = 3;
	static $mealHL 	 = 4;

	static $adultEmojy = "\xF0\x9F\x91\xA4";
	static $childEmojy = "\xF0\x9F\x91\xB6";
	static $starEmojy  = "\xE2\xAD\x90";
	static $planeEmojy = "\u{2708}\u{FE0F}";
	static $hotelEmojy = "\u{1f3e8}";
	static $mealEmojy  = "\u{1F37D}\u{FE0F}";
	static $nightsEmojy = "\u{1F319}";
	static $dateEmojy  = "\u{1F5D3}\u{FE0F}";







	public static function formatSavedData($savedData)
	{
		$outArr = [];

		if($savedData[static::$statusCityChoose] && $savedData[static::$statusContryChoose]) {
			$city = static::getCityByID($savedData[static::$statusCityChoose]);
			$country = static::getCountryByID($savedData[static::$statusContryChoose]);
			$outArr[] = "✈️ ".$city." → ".$country;
		}

		if($savedData[static::$statusAdults]) {
			$t = (int)$savedData[static::$statusAdults]." взр.";
			$children = (int)($savedData[static::$statusChild] ?? 0);
			if($children>0) {
				$t .= " + ".$children." реб.";
				if(!empty($savedData[static::$statusAge])) $t .= " (".$savedData[static::$statusAge]." лет)";
			} else {
				$t .= ", без детей";
			}
			$outArr[] = "👥 ".$t;
		}

		if($savedData[static::$statusStars]) {
			$stars=(int)$savedData[static::$statusStars];
			$outArr[] = "🏨 ".($stars<=1 ? "Категория не важна" : ($stars<5 ? "от ".$stars."★" : "5★"));
		}

		if($savedData[static::$statusMeal]) {
			$mealArr=static::getMealArr();
			$meal=$mealArr[$savedData[static::$statusMeal]] ?? "Не важно";
			$outArr[] = "🍽 ".mb_convert_case($meal,MB_CASE_TITLE,"UTF-8");
		}

		if($savedData[static::$statusNights])
			$outArr[] = "🌙 ".$savedData[static::$statusNights]." ночей";

		if($savedData[static::$statusDate]) {
			$dateObjPlus = new \Bitrix\Main\Type\DateTime($savedData[static::$statusDate]);
			$dateObjPlus->add("3 day");
			$dateObjMinus = new \Bitrix\Main\Type\DateTime($savedData[static::$statusDate]);
			$dateObjMinus->add("-3 day");
			$dateNow = new \Bitrix\Main\Type\Date();
			if($dateNow->getTimestamp()>$dateObjMinus->getTimestamp()) $dateObjMinus=$dateNow;
			$outArr[] = "📅 ".$dateObjMinus->format("d.m.Y")." — ".$dateObjPlus->format("d.m.Y");
		}

		return $outArr;
	}





	public static function showStart($chatID)
	{
		$arrInlineKey = [
			[
				['text'=>'✨ Подобрать с AI','callback_data'=>'ai_start'],
			],
			[
				['text'=>'🧭 Подобрать по шагам','callback_data'=>'start_search'],
			]
		];

		$text = "🌴 <b>Давайте найдём ваш отдых</b>\n\n";
		$text .= "Можно описать пожелания своими словами — или пройти короткий подбор по шагам.";

		$messID = static::MaxSendWithButtons($text, $chatID, $arrInlineKey, true);
		static::setStatus($chatID,static::$statusStart,$messID);
	}


	public static function showAiStart($chatID)
	{
		static::deletePrevMessage($chatID);
		$arrInlineKey = [[['text'=>'ПО ШАГАМ','callback_data'=>'start_search']]];
		$messID = static::MaxSendWithButtons("Опишите поездку одним сообщением или несколькими.\n\nНапример: «Хотим из Москвы в Турцию в конце сентября, 2 взрослых и ребёнок 6 лет, 9–11 ночей, отель от 4★, всё включено».\n\nЯ уточню только то, чего не хватает.", $chatID, $arrInlineKey);
		static::setStatus($chatID, static::$statusAi, $messID);
	}





	public static function showCityButtons($chatID)
	{
		static::deletePrevMessage($chatID);
		$arrInlineKey = [
			[
				['text'=>'Москва','callback_data'=>'pick_city_1'],
				['text'=>'Санкт-Петербург','callback_data'=>'pick_city_5'],
			],
			[
				['text'=>'Казань','callback_data'=>'pick_city_10'],
				['text'=>'Красноярск','callback_data'=>'pick_city_12'],
			],
			[
				['text'=>'🔎 Другой город','callback_data'=>'pick_city_other'],
			],
			[
				['text'=>'🚗 Без перелёта','callback_data'=>'pick_city_99'],
			]
		];
		$messID = static::MaxSendWithButtons("✈️ <b>Откуда вылетаете?</b>\n\nШаг 1 из 7 · Выберите город или введите его вручную.", $chatID, $arrInlineKey);
		static::setStatus($chatID,static::$statusCityChoose,$messID);
	}


	public static function showCityOtherButtons($chatID)
	{
		static::deletePrevMessage($chatID);
		$arrInlineKey = [
			[
				['text'=>'← Назад','callback_data'=>'back_pick_city'],
			]
		];
		$messID = static::MaxSendWithButtons("✈️ <b>Введите город вылета</b>\n\nНапример: Самара, Уфа или Новосибирск.", $chatID, $arrInlineKey);
		static::setStatus($chatID,static::$statusCityChoose,$messID);
	}


	public static function showCountryButtons($chatID)
	{
		static::deletePrevMessage($chatID);
		$arrInlineKey = [
			[
				['text'=>'🇹🇷 Турция','callback_data'=>'pick_country_4'],
				['text'=>'🇪🇬 Египет','callback_data'=>'pick_country_1'],
			],
			[
				['text'=>'🇹🇭 Таиланд','callback_data'=>'pick_country_2'],
				['text'=>'🇦🇪 ОАЭ','callback_data'=>'pick_country_9'],
			],
			[
				['text'=>'🏝 Мальдивы','callback_data'=>'pick_country_8'],
				['text'=>'🇱🇰 Шри-Ланка','callback_data'=>'pick_country_12'],
			],
			[
				['text'=>'🔎 Другая страна','callback_data'=>'pick_country_other'],
			],
			[
				['text'=>'← Назад','callback_data'=>'back_pick_city'],
			],
		];
		$messID = static::MaxSendWithButtons("🌍 <b>Куда хотите поехать?</b>\n\nШаг 2 из 7 · Выберите популярное направление или введите своё.", $chatID, $arrInlineKey);
		static::setStatus($chatID,static::$statusContryChoose,$messID);
	}


	public static function showAdultsButtons($chatID)
	{
		static::deletePrevMessage($chatID);
		$arrInlineKey = [
			[
				['text'=>'1 взрослый','callback_data'=>'adults_1'],
				['text'=>'2 взрослых','callback_data'=>'adults_2'],
			],
			[
				['text'=>'3 взрослых','callback_data'=>'adults_3'],
				['text'=>'4 взрослых','callback_data'=>'adults_4'],
			],
			[
				['text'=>'5 взрослых','callback_data'=>'adults_5'],
				['text'=>'6 взрослых','callback_data'=>'adults_6'],
			],
			[
				['text'=>'← Назад','callback_data'=>'back_pick_country'],
			],
		];
		$messID = static::MaxSendWithButtons("👥 <b>Кто едет?</b>\n\nШаг 3 из 7 · Сколько будет взрослых туристов?", $chatID, $arrInlineKey);
		static::setStatus($chatID,static::$statusAdults,$messID);
	}


	public static function showChildButtons($chatID)
	{
		static::deletePrevMessage($chatID);
		$arrInlineKey = [
			[
				['text'=>'Без детей','callback_data'=>'child_0'],
			],
			[
				['text'=>'1 ребёнок','callback_data'=>'child_1'],
				['text'=>'2 ребёнка','callback_data'=>'child_2'],
				['text'=>'3 ребёнка','callback_data'=>'child_3'],
			],
			[
				['text'=>'← Назад','callback_data'=>'back_adults'],
			],
		];
		$messID = static::MaxSendWithButtons("👨‍👩‍👧 <b>Будут дети?</b>\n\nЕсли да — укажите количество. Возраст спросим следующим сообщением.", $chatID, $arrInlineKey);
		static::setStatus($chatID,static::$statusChild,$messID);
	}


	public static function showAgeButtons($chatID,$child=1)
	{
		static::deletePrevMessage($chatID);
		$arrInlineKey = [
			[
				['text'=>'← Назад','callback_data'=>'back_child'],
			],
		];
		if($child==1)
			$text = "🧒 <b>Сколько лет ребёнку?</b>\n\nВведите возраст числом, например: 6";
		else
			$text = "🧒 <b>Сколько лет детям?</b>\n\nВведите ".$child." возраста через пробел или запятую, например: 3, 7";
		$messID = static::MaxSendWithButtons($text, $chatID, $arrInlineKey);
		static::setStatus($chatID,static::$statusAge,$messID);
	}


	public static function showStarsButtons($chatID)
	{
		static::deletePrevMessage($chatID);
		$arrInlineKey = [
			[
				['text'=>'5★','callback_data'=>'star_5'],
				['text'=>'от 4★','callback_data'=>'star_4'],
			],
			[
				['text'=>'от 3★','callback_data'=>'star_3'],
				['text'=>'от 2★','callback_data'=>'star_2'],
			],
			[
				['text'=>'Не важно','callback_data'=>'star_1'],
			],
			[
				['text'=>'← Назад','callback_data'=>'back_child'],
			],
		];
		$messID = static::MaxSendWithButtons("🏨 <b>Какой уровень отеля рассматриваете?</b>\n\nШаг 4 из 7 · Укажите минимальную категорию.", $chatID, $arrInlineKey);
		static::setStatus($chatID,static::$statusStars,$messID);
	}


	public static function showMealButtons($chatID)
	{
		static::deletePrevMessage($chatID);
		$arrInlineKey = [
			[
				['text'=>'🍽 Всё включено','callback_data'=>'meal_7'],
			],
			[
				['text'=>'🥐 Только завтраки','callback_data'=>'meal_3'],
				['text'=>'🍴 Завтрак + ужин','callback_data'=>'meal_4'],
			],
			[
				['text'=>'🍽 Полный пансион','callback_data'=>'meal_5'],
			],
			[
				['text'=>'Не важно','callback_data'=>'meal_999'],
			],
			[
				['text'=>'← Назад','callback_data'=>'back_stars'],
			]
		];
		$messID = static::MaxSendWithButtons("🍽 <b>Какое питание предпочитаете?</b>\n\nШаг 5 из 7", $chatID, $arrInlineKey);
		static::setStatus($chatID,static::$statusMeal,$messID);
	}


	public static function showNightsButtons($chatID)
	{
		static::deletePrevMessage($chatID);
		$arrInlineKey = [
			[
				['text'=>'6–8 ночей','callback_data'=>'nights_6_8'],
				['text'=>'9–11 ночей','callback_data'=>'nights_9_11'],
			],
			[
				['text'=>'12–14 ночей','callback_data'=>'nights_12_14'],
			],
			[
				['text'=>'✏️ Свой вариант','callback_data'=>'nights_other'],
			],
			[
				['text'=>'← Назад','callback_data'=>'back_meal'],
			]
		];
		$messID = static::MaxSendWithButtons("🌙 <b>На сколько ночей хотите поехать?</b>\n\nШаг 6 из 7 · Можно выбрать диапазон или указать свой.", $chatID, $arrInlineKey);
		static::setStatus($chatID,static::$statusNights,$messID);
	}


	public static function showCalendarButtons($chatID,$month,$year)
	{
		static::deletePrevMessage($chatID);
		$monthArr = [
			1=>"Январь",2=>"Февраль",3=>"Март",4=>"Апрель",5=>"Май",6=>"Июнь",
			7=>"Июль",8=>"Август",9=>"Сентябрь",10=>"Октябрь",11=>"Ноябрь",12=>"Декабрь"
		];

		$arrInlineKey = [
			[
				['text'=>'Месяц','callback_data'=>'month_click'],
			],
			[
				['text'=>'Пн','callback_data'=>'day_click'],
				['text'=>'Вт','callback_data'=>'day_click'],
				['text'=>'Ср','callback_data'=>'day_click'],
				['text'=>'Чт','callback_data'=>'day_click'],
				['text'=>'Пт','callback_data'=>'day_click'],
				['text'=>'Сб','callback_data'=>'day_click'],
				['text'=>'Вс','callback_data'=>'day_click'],
			],
		];

		$day = "01";
		$month = intval($month);
		if($month<10) $month = "0".$month;
		$date = new \Bitrix\Main\Type\Date($day.".".$month.".".$year, "d.m.Y");
		$dateNow = new \Bitrix\Main\Type\Date();
		if($date<$dateNow) $date = $dateNow;

		$dateStart = new \Bitrix\Main\Type\Date("01.".$date->format("m").".".$date->format("Y"), "d.m.Y");
		$monthBeforeDate = new \Bitrix\Main\Type\Date("01.".$date->format("m").".".$date->format("Y"), "d.m.Y");
		$monthBeforeDate->add("-1 month");
		$monthBefore = $monthBeforeDate->format("m.Y");
		$monthAfterDate = new \Bitrix\Main\Type\Date("01.".$date->format("m").".".$date->format("Y"), "d.m.Y");
		$monthAfterDate->add("1 month");
		$monthAfter = $monthAfterDate->format("m.Y");

		$j = $date->format("j");
		$i=0; $week=0; $weekDays=[];
		do {
			$w = $date->format("w");
			if($w==0) $w=7;
			$weekDays[$week][$w] = ["date"=>$date->format("d.m.Y"),"day"=>$date->format("j")];
			$date->add("1 day");
			$j = $date->format("j");
			if($w==7) $week++;
			$i++;
		} while($j>1 && $i<=31);

		foreach($weekDays as $weekArr) {
			$printDays=[];
			for($i=1;$i<=7;$i++) {
				if(!empty($weekArr[$i]))
					$printDays[]=['text'=>$weekArr[$i]["day"],'callback_data'=>'pick_date_'.$weekArr[$i]["date"]];
				else
					$printDays[]=['text'=>'·','callback_data'=>'empty'];
			}
			$arrInlineKey[]=$printDays;
		}

		$arrInlineKey[0][0]["text"]=$monthArr[intval($dateStart->format("m"))]." ".$dateStart->format("Y");
		$arrInlineKey[]=[
			['text'=>'‹','callback_data'=>'month_change_'.$monthBefore],
			['text'=>'›','callback_data'=>'month_change_'.$monthAfter],
		];
		$arrInlineKey[]=[
			['text'=>'← Назад','callback_data'=>'back_nights'],
		];

		$messID = static::MaxSendWithButtons("📅 <b>Когда хотите вылететь?</b>\n\nШаг 7 из 7 · Выберите ориентировочную дату. В поиске посмотрим даты рядом с ней.", $chatID, $arrInlineKey);
		static::setStatus($chatID,static::$statusDate,$messID);
	}
	public static function showCheckButtons($chatID)
	{
		static::funnelLog($chatID,'search_ready');
		static::deletePrevMessage($chatID);
		$savedData = static::getSavedData($chatID);
		$outArr = static::formatSavedData($savedData);

		$buttons = [
			[
				['text'=>'🔥 Показать туры','callback_data'=>'show_tours'],
			],
			[
				['text'=>'👩‍💼 Подобрать с менеджером','callback_data'=>'manager_request'],
			],
			[
				['text'=>'✏️ Изменить параметры','callback_data'=>'edit_params'],
			]
		];

		$text = "✅ <b>Готово! Проверьте параметры</b>\n\n";
		$text .= implode("\n",$outArr);
		$text .= "\n\nЧто удобнее дальше?";

		$messID = static::MaxSendWithButtons($text,$chatID,$buttons);
		static::setStatus($chatID,static::$statusCheck,$messID);
	}


	public static function createSearchClaim($chatID,$name="")
	{
		$savedData = static::getSavedData($chatID);
		$savedData["NAME"] = $name;
		return static::saveClaim($chatID,$savedData);
	}



	public static function editModeFile($chatID)
	{
		$dir=__DIR__.'/edit_mode';
		if(!is_dir($dir)) @mkdir($dir,0755,true);
		return $dir.'/'.preg_replace('/[^0-9\-]/','',(string)$chatID).'.txt';
	}

	public static function setEditMode($chatID,$field="")
	{
		$file=static::editModeFile($chatID);
		if($field==="") { if(is_file($file)) @unlink($file); return; }
		@file_put_contents($file,$field,LOCK_EX);
	}

	public static function getEditMode($chatID)
	{
		$file=static::editModeFile($chatID);
		return is_file($file) ? trim((string)@file_get_contents($file)) : "";
	}

	public static function finishEditIfNeeded($chatID,$field)
	{
		$mode=static::getEditMode($chatID);
		if($mode===$field) {
			static::setEditMode($chatID,"");
			static::showCheckButtons($chatID);
			return true;
		}
		return false;
	}

	public static function showEditParamsButtons($chatID)
	{
		static::deletePrevMessage($chatID);
		$buttons = [
			[
				['text'=>'✈️ Город вылета','callback_data'=>'edit_city'],
				['text'=>'🌍 Страна','callback_data'=>'edit_country'],
			],
			[
				['text'=>'👥 Туристы','callback_data'=>'edit_tourists'],
				['text'=>'🏨 Отель','callback_data'=>'edit_stars'],
			],
			[
				['text'=>'🍽 Питание','callback_data'=>'edit_meal'],
				['text'=>'🌙 Ночи','callback_data'=>'edit_nights'],
			],
			[
				['text'=>'📅 Дата','callback_data'=>'edit_date'],
			],
			[
				['text'=>'← К параметрам','callback_data'=>'back_check'],
			],
		];
		$messID = static::MaxSendWithButtons(
			"✏️ <b>Что хотите изменить?</b>\n\nНе нужно начинать подбор заново — выберите только нужный параметр.",
			$chatID,
			$buttons
		);
		static::setStatus($chatID,static::$statusCheck,$messID);
	}
	public static function showToursChoice($chatID,$name="")
	{
		static::funnelLog($chatID,'show_tours');
		$savedData = static::getSavedData($chatID);
		$savedData["NAME"] = $name;
		$link = static::saveClaim($chatID,$savedData);

		static::deletePrevMessage($chatID);

		$buttons = [
			[
				['text'=>'🔥 Открыть туры на сайте','url'=>'https://app.anytoour.ru/open_tours.php?chat='.rawurlencode($chatID).'&url='.rawurlencode($link)],
			],
		];

		// FIX8: показываем MAX-канал сразу в момент максимального интереса,
		// а не только после follow-up/лида.
		$channelLink = static::buildChannelMiniappUrl($chatID);
		if($channelLink!="") {
			$trackedChannelLink = static::$baseDomain.'/max-search/open_channel.php?chat='.
				rawurlencode($chatID).'&url='.rawurlencode($channelLink);
			$buttons[] = [
				['text'=>'🔥 Горящие туры в MAX','url'=>$trackedChannelLink],
			];
		}

		$buttons[] = [
			['text'=>'👩‍💼 Нужна помощь менеджера','callback_data'=>'manager_after_tours'],
		];
		$buttons[] = [
			['text'=>'✏️ Изменить параметры','callback_data'=>'edit_params'],
		];

		$text = "🔥 <b>Подходящие туры готовы</b>\n\n";
		$text .= "Откройте результаты на сайте — там будут актуальные варианты по выбранным параметрам.\n\n";
		$text .= "Хотите следить за снижением цен и горящими предложениями — загляните в наш MAX-канал.";

		$messID = static::MaxSendWithButtons($text,$chatID,$buttons);
		static::linkSentYclid($chatID);
		return $link;
	}




	public static function showManagerRequest($chatID,$name="",$fromTours=false)
	{
		// Если поисковая заявка ещё не создана — создаём её один раз.
		$claim = static::getLastClaimForChat($chatID);
		if(!$claim) {
			$savedData = static::getSavedData($chatID);
			$savedData["NAME"] = $name;
			static::saveClaim($chatID,$savedData);
		}

		static::deletePrevMessage($chatID);
		$buttons = [
			[
				['text'=>'📱 Отправить мой номер','request_contact'=>true],
			],
			[
				['text'=>'⌨️ Ввести номер вручную','callback_data'=>'phone_manual'],
			],
			[
				['text'=>'← Назад','callback_data'=>($fromTours ? 'tours_checked' : 'back_check')],
			]
		];
		$text = "👩‍💼 <b>Передам запрос менеджеру</b>\n\n";
		$text .= "Параметры поездки уже сохранены — повторно заполнять ничего не нужно.\n";
		$text .= "Осталось поделиться номером телефона, чтобы менеджер мог связаться с вами.";
		$messID = static::MaxSendWithButtons($text,$chatID,$buttons);
		static::setStatus($chatID,static::$statusPhone,$messID);
	}

	public static function showFinishButtons($chatID,$name="")
	{
		return static::showToursChoice($chatID,$name);
	}

	public static function showPickedCity($chatID,$city)
	{
		$cityName = static::getCityByID($city);
		if($cityName)
		{
			static::MaxSend("Город вылета: <b>".$cityName."</b>",$chatID);
		}
	}

	public static function showPickedCountry($chatID,$country)
	{
		$countryName = static::getCountryByID($country);
		if($countryName)
		{
			static::MaxSend("Страна: <b>".$countryName."</b>",$chatID);
		}
	}

	public static function showAdults($chatID,$adults)
	{
		static::MaxSend("Взрослых туристов: <b>".$adults."</b>",$chatID);
	}

	public static function showChild($chatID,$child)
	{
		if($child==0)
			$child ="БЕЗ ДЕТЕЙ";
		static::MaxSend("Детей: <b>".$child."</b>",$chatID);
	}

	public static function showAge($chatID,$age)
	{
		static::MaxSend("Возраст детей: <b>".$age."</b>",$chatID);
	}

	public static function showStars($chatID,$star)
	{
		static::MaxSend("Категория отеля: <b>".$star."*"."</b>",$chatID);
	}

	public static function showMeal($chatID,$meal)
	{
		$mealArr = static::getMealArr();

		static::MaxSend("Питание: <b>".$mealArr[$meal]."</b>",$chatID);
	}

	public static function showNights($chatID,$nights)
	{
		static::MaxSend("Количество ночей: <b>".$nights."</b>",$chatID);
	}

	public static function showDates($chatID,$date)
	{
		$dateObjPlus = new  \Bitrix\Main\Type\DateTime($date);
		$dateObjPlus->add("3 day");
		$dateObjMinus = new  \Bitrix\Main\Type\DateTime($date);
		$dateObjMinus->add("-3 day");
		$dateNow = new \Bitrix\Main\Type\Date();
		if($dateNow->getTimestamp() > $dateObjMinus->getTimestamp())
			$dateObjMinus = $dateNow;

		static::MaxSend("Даты: <b>c ".$dateObjMinus->format("d.m.Y")." по ".$dateObjPlus->format("d.m.Y")."</b>",$chatID);
	}





	

	private static function maxApiRequest($httpMethod, $path, $query = [], $body = null)
	{
		$url = rtrim(static::$TV_API_URL, '/') . '/' . ltrim($path, '/');
		if (!empty($query)) {
			$url .= '?' . http_build_query($query);
		}

		$ch = curl_init($url);
		
// MAX platform-api2 certificate workaround
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($httpMethod));
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Authorization: ' . MAX_SEARCH_TOKEN,
			'Content-Type: application/json',
		]);
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		}

		$response = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response === false || $errno) {
			static::maxLog('API CURL ERROR ' . $errno . ': ' . $error);
			return false;
		}

		$data = json_decode($response, true);
		if ($httpCode < 200 || $httpCode >= 300) {
			static::maxLog('API HTTP ' . $httpCode . ': ' . $response);
			return false;
		}
		return is_array($data) ? $data : true;
	}



	private static function maxUserId($internalId)
	{
		// В HL-блоках MAX-пользователь хранится с отрицательным ID,
		// чтобы не пересечься с Telegram chat_id. В API MAX уходит исходный положительный user_id.
		return abs((int)$internalId);
	}

	private static function extractMessageId($res)
	{
		if (!is_array($res)) return false;
		if (!empty($res['message']['body']['mid'])) return $res['message']['body']['mid'];
		if (!empty($res['body']['mid'])) return $res['body']['mid'];
		if (!empty($res['message']['mid'])) return $res['message']['mid'];
		return false;
	}

	private static function convertButtonsForMax($buttons)
	{
		$out = [];
		foreach ((array)$buttons as $row) {
			$newRow = [];
			foreach ((array)$row as $button) {
				if (!is_array($button) || empty($button['text'])) continue;
				if (!empty($button['request_contact'])) {
					$newRow[] = [
						'type' => 'request_contact',
						'text' => (string)$button['text'],
					];
				} elseif (array_key_exists('callback_data', $button)) {
					$newRow[] = [
						'type' => 'callback',
						'text' => (string)$button['text'],
						'payload' => (string)$button['callback_data'],
					];
				} elseif (!empty($button['url'])) {
					$newRow[] = [
						'type' => 'link',
						'text' => (string)$button['text'],
						'url' => (string)$button['url'],
					];
				}
			}
			if ($newRow) $out[] = $newRow;
		}
		return $out;
	}





	public static function cleanPhone($val)
	{
		$val = preg_replace('/[^0-9+]/', '', (string)$val);
		if (strpos($val, '+7') === 0) return '7' . substr($val, 2);
		if (strpos($val, '8') === 0 && strlen($val) === 11) return '7' . substr($val, 1);
		return ltrim($val, '+');
	}





	public static function sendToursFollowup($chatID)
	{
		static::funnelLog($chatID,'followup_sent');
		$buttons = [
			[
				['text'=>'💬 Получить 3–5 лучших вариантов','callback_data'=>'manager_after_tours'],
			],
			[
				['text'=>'👍 Я уже нашёл подходящий тур','callback_data'=>'tours_found'],
			],
			[
				['text'=>'✏️ Изменить параметры','callback_data'=>'edit_params'],
			]
		];

		$text = "🙂 <b>Могу помочь с выбором</b>\n\n";
		$text .= "Могу передать ваш подбор менеджеру — он проверит актуальные цены и пришлёт <b>3–5 лучших вариантов</b>.\n\n";
		$text .= "Параметры поездки уже сохранены, повторно ничего заполнять не придётся.";

		static::MaxSendWithButtons($text,$chatID,$buttons);
	}

	public static function showAfterToursQuestion($chatID)
	{
		static::deletePrevMessage($chatID);
		$buttons = [
			[
				['text'=>'👍 Да, нашёл варианты','callback_data'=>'tours_found'],
			],
			[
				['text'=>'👩‍💼 Нужна помощь с подбором','callback_data'=>'manager_after_tours'],
			],
			[
				['text'=>'✏️ Изменить параметры','callback_data'=>'edit_params'],
			]
		];
		$text = "🙂 <b>Удалось найти подходящий тур?</b>\n\n";
		$text .= "Если вариантов слишком много или сложно определиться — менеджер может посмотреть ваш запрос и помочь с выбором.";
		static::MaxSendWithButtons($text,$chatID,$buttons);
	}



	public static function phoneAskAgent()
	{
		\Bitrix\Main\Loader::includeModule('highloadblock');
		$hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById(static::$claimHL)->fetch();
		$entity  = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
		$eclass = $entity->getDataClass();
		$checked = [];
		$arrInlineKey =  [
			[
				['text'=>'НАЗАД','callback_data'=>'back_phone'],
			]
		];
		$data = new \Bitrix\Main\Type\DateTime();
		$data->add("-10 minutes");
		$dbData = $eclass::getList(["filter"=>["<=UF_DATE"=>$data,"UF_PHONE" => false,"UF_PHONE_ASKED"=>false],"order"=>["ID"=>"desc"],"select"=>["ID","UF_CHAT_ID"]]);
		while($claim = $dbData->fetch())	
		{
			if(!in_array($claim["UF_CHAT_ID"],$checked))
			{
				$hlblock2 = \Bitrix\Highloadblock\HighloadBlockTable::getById(static::$HL)->fetch();
				$entity2  = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock2);
				$eclass2 = $entity2->getDataClass();
				$query = $eclass2::getList(["order"=>["ID"=>"desc"],"filter"=>["UF_CHAT_ID"=>$claim["UF_CHAT_ID"]]])->fetch();
				if(!$query)
				{
					$checked[]=$claim["UF_CHAT_ID"];
					$messID = static::MaxSendWithButtons( "Вам требуется помощь менеджера?\n\nУкажите номер своего телефона в формате +71234567890 и менеджер свяжется с вами в ближайшее время", $claim["UF_CHAT_ID"], $arrInlineKey);
					static::setStatus($claim["UF_CHAT_ID"],static::$statusPhone,$messID);
					$eclass::update($claim["ID"],["UF_PHONE_ASKED"=>true]);
				}
			}
		}	
	}

	public static function addYclid($chatID, $yclid)
	{
		if($chatID!="" && $yclid!="")
		{
			\Bitrix\Main\Loader::includeModule('highloadblock');
			$hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById(static::$yclidHL)->fetch();
			$entity  = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
			$eclass = $entity->getDataClass();
			$eclass::add([ 
				"UF_DATE"	=> new  \Bitrix\Main\Type\DateTime(),
				"UF_CHATID"=> $chatID,
				"UF_YCLID" => $yclid,
				"UF_UPLOADED" => false
			]);
		}
	}







	public static function buildClaimSummary($chatID)
	{
		try {
			$claim=static::getLastClaimForChat($chatID);
			if(!$claim) return '';
			$from=static::getCityByID($claim['UF_CITY'] ?? '');
			$country=static::getCountryByID($claim['UF_COUNTRY'] ?? '');
			$people=(int)($claim['UF_ADULTS'] ?? 0).' взр.';
			$children=(int)($claim['UF_CHILD'] ?? 0);
			if($children>0) {
				$people.=' + '.$children.' реб.';
				$ages=trim((string)($claim['UF_AGE'] ?? ''));
				if($ages!=='') $people.=' ('.$ages.' лет)';
			} else $people.=', без детей';
			$nights=trim((string)($claim['UF_NIGHTS'] ?? ''));
			$dateStr='';
			if(!empty($claim['UF_DATE_DEPART'])) {
				try {
					$dateObj=new \Bitrix\Main\Type\DateTime($claim['UF_DATE_DEPART']);
					$dateStr=$dateObj->format('d.m.Y');
				} catch(\Throwable $e) {}
			}
			$parts=[];
			if($from!=='' || $country!=='') $parts[]='✈️ '.trim($from.' → '.$country,' →');
			if($people!=='') $parts[]='👥 '.$people;
			if($nights!=='') $parts[]='🌙 '.$nights.' ночей';
			if($dateStr!=='') $parts[]='📅 около '.$dateStr;
			return implode("\n",$parts);
		} catch(\Throwable $e) { return ''; }
	}

	public static function getLatestYclid($chatID)
	{
		if($chatID=="") return "";
		// The standalone runtime keeps attribution in the transport-neutral
		// traffic store. Bitrix is intentionally absent there, so reaching the
		// legacy HL lookup must fail open instead of aborting the user action
		// (manager handoff and tour links both consume this optional value).
		if(!class_exists('\\Bitrix\\Main\\Loader'))
		{
			try {
				$meta=is_callable([static::class,'getTrafficMeta']) ? static::getTrafficMeta($chatID) : [];
				return is_array($meta) ? trim((string)($meta['yclid'] ?? '')) : '';
			} catch(\Throwable $e) { return ''; }
		}
		\Bitrix\Main\Loader::includeModule('highloadblock');
		$hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById(static::$yclidHL)->fetch();
		$entity  = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
		$eclass = $entity->getDataClass();
		$query = $eclass::getList([
			"order"=>["ID"=>"desc"],
			"limit"=>1,
			"select"=>["UF_YCLID"],
			"filter"=>["UF_CHATID"=>$chatID]
		])->fetch();
		return ($query && !empty($query["UF_YCLID"])) ? trim((string)$query["UF_YCLID"]) : "";
	}

	public static function linkSentYclid($chatID)
	{
		if($chatID!="")
		{
			\Bitrix\Main\Loader::includeModule('highloadblock');
			$hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById(static::$yclidHL)->fetch();
			$entity  = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
			$eclass = $entity->getDataClass();
			$query = $eclass::getList(["order"=>["ID"=>"desc"],"filter"=>["UF_CHATID"=>$chatID]])->fetch();
			if($query)
			{
				$eclass::update($query["ID"],[ "UF_LINK_SENT" => true]);
			}
		}
	}

	public static function phoneSentYclid($chatID)
	{
		if($chatID!="")
		{
			\Bitrix\Main\Loader::includeModule('highloadblock');
			$hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById(static::$yclidHL)->fetch();
			$entity  = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
			$eclass = $entity->getDataClass();
			$query = $eclass::getList(["order"=>["ID"=>"desc"],"filter"=>["UF_CHATID"=>$chatID]])->fetch();
			if($query)
			{
				$eclass::update($query["ID"],["UF_PHONE_SENT" => true]);
			}
		}
	}

	public static function checkChanelSub($chatID)
	{
		if($chatID!="")
		{
			\Bitrix\Main\Loader::includeModule('highloadblock');
			$hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById(static::$chanelSendHL)->fetch();
			$entity  = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
			$eclass = $entity->getDataClass();
			$query = $eclass::getList(["order"=>["ID"=>"desc"],"filter"=>["UF_CHAT_ID"=>$chatID]])->fetch();
			if(!$query)
			{
				$eclass::add([ 
					"UF_DATE"	=> new  \Bitrix\Main\Type\DateTime(),
					"UF_CHAT_ID"=> $chatID,
					"UF_ACTIVE" => true
				]);
			}
		}
	}

	public static function checkChanelSubAgent()
	{
		// Для MAX отключено: Telegram-проверка подписки к MAX не относится.
		return false;
	}


}
