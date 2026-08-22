<?php

class CallbackHandler
{
public static function handle($query) {
	$chat_id = $query['from']['id'];
	$q       = $query['data'];
	//put_log_out($chat_id."!!".$q);
	//MaxSearchApi::MaxSend($q,$chat_id);
	if($q =="ai_start")
	{
		MaxSearchApi::funnelLog($chat_id,'ai_start');
		// Новый AI-подбор должен начинаться с чистого поискового контекста.
		// Ставим новую границу statusStart, чтобы getSavedData() не подтягивал
		// страну/отель/дату из предыдущего подбора этого же chat_id.
		MaxSearchApi::deletePrevMessage($chat_id);
		MaxSearchApi::setStatus($chat_id,MaxSearchApi::$statusStart);
		MaxSearchApi::showAiStart($chat_id);
	}
	elseif($q =="start_search" || $q =="back_pick_city")
	{
		if($q =="start_search")
		{
			MaxSearchApi::funnelLog($chat_id,'start_search');
			// Поиск «по шагам» тоже является новым подбором, а не продолжением старого.
			MaxSearchApi::deletePrevMessage($chat_id);
			MaxSearchApi::setStatus($chat_id,MaxSearchApi::$statusStart);
		}
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
}