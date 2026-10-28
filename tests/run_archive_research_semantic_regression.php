<?php
declare(strict_types=1);
require_once __DIR__.'/../services/ArchiveResearchSemanticExtractor.php';

$checks=0;
function check(bool$ok,string$name):void{global$checks;$checks++;if(!$ok)throw new RuntimeException($name);}
function message(string$role,string$text,int$id=1,?int$time=1700000000,array$override=[]):array
{
    return array_replace(['source_ref'=>'archive_message:'.$id,'read_status'=>'available','role'=>$role,
        'role_verified'=>true,'direction'=>$role==='customer'?'inbound':'outbound','created_at'=>$time,
        'forwarded'=>false,'text'=>$text,'text_minimized'=>true,'media'=>[],'media_contents_read'=>false,
        'links_redacted'=>false,'selection_links_unread'=>false],$override);
}
function runMessages(array$messages,?array$state=null):array
{
    $s=$state??ArchiveResearchSemanticExtractor::initialState();$outputs=[];
    foreach($messages as$m){$r=ArchiveResearchSemanticExtractor::step($m,$s);$s=$r['state'];$outputs[]=$r;}
    return ['state'=>$s,'outputs'=>$outputs];
}
function goals(array$result):array
{
    $out=[];foreach($result['outputs']as$r)foreach($r['events']as$e)$out[]=$e['goal'];return$out;
}
function has(array$r,string$goal):bool{return in_array($goal,goals($r),true);}
function single(string$role,string$text,array$override=[]):array{return runMessages([message($role,$text,1,1700000000,$override)]);}

check(ArchiveResearchSemanticExtractor::validState(ArchiveResearchSemanticExtractor::initialState()),'initial_state_valid');
foreach(['v'=>2,'e'=>-1,'last'=>-1,'p'=>'fake','q'=>'fake','qt'=>'1700000000','qr'=>'secret','qi'=>9,'money'=>[],'mt'=>1.5,'booking'=>'native:1','bt'=>-1,'cancelled'=>1,'linked'=>null]as$k=>$v){
    $s=ArchiveResearchSemanticExtractor::initialState();$s[$k]=$v;check(!ArchiveResearchSemanticExtractor::validState($s),'reject_state_'.$k);
}
$s=ArchiveResearchSemanticExtractor::initialState();$s['extra']=1;check(!ArchiveResearchSemanticExtractor::validState($s),'reject_extra_key');
$s=ArchiveResearchSemanticExtractor::initialState();unset($s['q']);check(!ArchiveResearchSemanticExtractor::validState($s),'reject_missing_key');
try{ArchiveResearchSemanticExtractor::step(message('customer','Привет'),[]);check(false,'invalid_state_must_throw');}catch(InvalidArgumentException$e){check(true,'invalid_state_rejected');}

$r=single('customer','Подберите тур для двоих на июнь');
check(has($r,'substantive_interest'),'trip_request_interest');
check($r['state']['e']===1&&$r['outputs'][0]['episode_boundary']==='first_trip_request_candidate','first_request_candidate');
check(!has($r,'general_purchase_intent'),'request_not_purchase_intent');
check(!has($r,'purchase_supported_candidate'),'request_not_purchase');
$r=single('customer','Туры меня не интересуют');
check(!has($r,'substantive_interest')&&$r['state']['e']===0,'explicit_interest_denial');
check(has(single('customer','Интересует тур не дороже 150 тысяч'),'substantive_interest'),'constraint_negation_not_interest_denial');
$r=single('customer','[ссылка на подборку не прочитана]');
check(goals($r)===[]&&$r['state']['e']===0,'privacy_marker_not_interest');
$r=single('customer','Здравствуйте');
check(goals($r)===[]&&$r['state']['e']===0,'greeting_unresolved');

check(has(single('customer','Хочу этот купить'),'general_purchase_intent'),'general_purchase_intent');
check(!has(single('customer','Хочу этот купить'),'agency_purchase_intent'),'general_not_necessarily_seller');
check(has(single('customer','Берём этот тур'),'agency_purchase_intent'),'specific_tour_choice');
check(!has(single('customer','Берём ипотеку'),'general_purchase_intent'),'non_tour_take_not_intent');
check(has(single('customer','Купим у вас'),'agency_purchase_intent'),'explicit_seller_choice');
check(!has(single('customer','Только у вас дорого'),'agency_purchase_intent'),'seller_mention_not_commitment');
check(!has(single('customer','Если купим у вас, будет скидка?'),'agency_purchase_intent'),'conditional_seller_not_commitment');
check(!has(single('customer','Не будем бронировать'),'general_purchase_intent'),'negated_booking');
check(!has(single('customer','В прошлом году забронировали у вас'),'agency_purchase_intent'),'past_trip_not_current_commitment');
check(has(single('customer','Где ваш офис?'),'office_question'),'office_signal');
check(!has(single('customer','Где ваш офис?'),'agency_purchase_intent'),'office_not_commitment');
check(has(single('customer','Можно оплатить картой?'),'payment_terms_question'),'payment_terms_signal');
check(!has(single('customer','Можно оплатить картой?'),'payment_self_report'),'payment_question_not_paid');
check(has(single('customer','С мужем обсудим'),'deferral'),'family_dependency');

$r=runMessages([message('manager','Бронируем?',1),message('customer','Да',2,1700000010)]);
check(has($r,'agency_purchase_intent'),'affirmative_with_booking_prompt');
check($r['outputs'][1]['events'][1]['related_source_refs']===['archive_message:1'],'prompt_evidence_link');
$r=runMessages([message('manager','Оформляем в офисе или дистанционно?',1),message('customer','Дистанционно',2,1700000010)]);
check(has($r,'agency_purchase_intent'),'remote_choice_in_booking_context');
$r=runMessages([message('manager','Бронируем?',1),message('manager','Подборку получили?',2,1700000001),message('customer','Да',3,1700000002)]);
check(!has($r,'agency_purchase_intent'),'unrelated_question_clears_prompt');
$r=runMessages([message('manager','Клиент написал «Бронируем?»',1),message('customer','Да',2,1700000001)]);
check(!has($r,'agency_purchase_intent'),'quoted_booking_question_not_prompt');
$r=runMessages([message('manager','Не будем бронировать?',1),message('customer','Да',2,1700000001)]);
check(!has($r,'agency_purchase_intent'),'negative_question_not_prompt');
$r=runMessages([message('manager','Бронируем?',1),message('customer','Да',2,1700086401)]);
check(!has($r,'agency_purchase_intent'),'expired_prompt');
$r=runMessages([message('manager','Бронируем?',1),message('manager','',2,1700000001,['read_status'=>'deleted']),message('customer','Да',3,1700000002)]);
check(!has($r,'agency_purchase_intent'),'deleted_barrier');
$r=runMessages([message('manager','Бронируем?',1),message('customer','Да',2,null)]);
check(!has($r,'agency_purchase_intent'),'unknown_clock_barrier');
$r=runMessages([message('manager','Сколько взрослых?',1),message('customer','2',2,1700000001)]);
check(has($r,'substantive_interest'),'short_criteria_reply');

check(has(single('customer','Оплатил'),'payment_self_report'),'self_report');
check(!has(single('customer','Если оплатил, то где чек?'),'payment_self_report'),'conditional_question_not_self_report');
check(!has(single('customer','Оплатили?'),'payment_self_report'),'payment_question');
check(!has(single('customer','Не оплатил'),'payment_self_report'),'negated_self_report');
check(!has(single('customer','Оплачу завтра'),'payment_self_report'),'future_not_self_report');
check(!has(single('customer','Оплатил', ['forwarded'=>true]),'payment_self_report'),'forwarded_not_own_payment');
check(!has(single('customer','Оплатил', ['role_verified'=>false]),'payment_self_report'),'unknown_role');
check(!has(single('customer','Оплатил', ['direction'=>'outbound']),'payment_self_report'),'role_direction_mismatch');
check(!has(single('customer','Оплатил', ['text_minimized'=>false]),'payment_self_report'),'unminimized_input_rejected');
check(!has(single('customer','Оплатил', ['forwarded'=>null]),'payment_self_report'),'unknown_forwarding');
check(!has(single('customer','Перевёл'),'payment_self_report'),'bare_transfer_ambiguous');
check(!has(single('customer','Оплатил депозит в отеле'),'payment_self_report'),'hotel_deposit_not_main_payment');
check(!has(single('customer','Оплатил доплату за бизнес-класс'),'payment_self_report'),'addon_payment_not_main_payment');
check(has(single('customer','Купили тур в другом агентстве'),'purchased_elsewhere'),'other_seller_report');
check(!has(single('customer','Купили тур в другом агентстве'),'agency_purchase_intent'),'other_seller_not_ours');

check(has(single('manager','Оплату вижу, бронирую'),'manager_payment_ack'),'explicit_manager_receipt');
check(has(single('manager','Обе оплаты увидела'),'manager_payment_ack'),'two_payments_one_receipt_signal');
check(!has(single('manager','Увидела, бронирую'),'manager_payment_ack'),'bare_saw_not_receipt');
check(!has(single('manager','Увидела день рождения'),'manager_payment_ack'),'saw_birthday_not_receipt');
check(!has(single('manager','Как только оплату увидела бы, забронировала'),'manager_payment_ack'),'conditional_manager_receipt');
check(!has(single('manager','Оплата должна поступить'),'manager_payment_ack'),'future_money_not_receipt');
check(!has(single('manager','Оплата не поступила'),'manager_payment_ack'),'negated_receipt');
check(has(single('manager','Тур подтверждён'),'booking_confirmation'),'booking_confirmation');
check(!has(single('manager','Ждём, когда тур будет подтверждён'),'booking_confirmation'),'pending_booking');
check(!has(single('customer','Тур подтверждён'),'booking_confirmation'),'customer_booking_relay_not_manager_confirmation');
$r=single('manager','Бронь подтверждена, осталось оплатить');
check(has($r,'booking_confirmation')&&!has($r,'manager_payment_ack'),'confirmed_booking_unpaid');
check(has(single('manager','Документы к туру также на почте'),'document_issuance'),'documents_not_payment');
check(!has(single('manager','Документы к туру также на почте'),'payment_self_report'),'documents_no_paid');
check(has(single('manager','Оплату отменила'),'payment_cancellation'),'payment_cancellation');
check(has(single('manager','Отель отказал'),'booking_cancellation'),'supplier_refusal');
check(!has(single('customer','Хочу вернуть деньги'),'refund_report'),'requested_not_completed_refund');
check(has(single('customer','Деньги вернулись'),'refund_report'),'refund_self_report');

$purchase=[
    message('customer','Подберите тур на июнь',1),
    message('manager','Оплату увидела',2,1700000003),
    message('customer','Оплатил',3,1700000006),
    message('manager','Тур подтвержден',4,1700000060),
];
$r=runMessages($purchase);
check(has($r,'purchase_supported_candidate'),'combined_candidate');
check(count(array_filter(goals($r),fn($g)=>$g==='purchase_supported_candidate'))===1,'one_candidate_for_receipt_and_booking');
check($r['state']['linked']===true,'combined_state');
$r=runMessages([...$purchase,message('customer','Внесли остаток',5,1700000070),message('manager','Оплату получила',6,1700000080)]);
check(count(array_filter(goals($r),fn($g)=>$g==='purchase_supported_candidate'))===1,'balance_not_second_purchase');
$r=runMessages([...$purchase,message('manager','Оплату отменила',5,1700000070)]);
check(has($r,'payment_cancellation')&&!$r['state']['linked']&&$r['state']['cancelled'],'cancel_revises_current_status');
$r=runMessages([message('customer','Подберите тур',1),message('manager','Оплату увидела. Отель отказал, оплату отменила',2,1700000001),message('manager','Тур подтвержден',3,1700000002)]);
check(has($r,'manager_payment_ack')&&has($r,'payment_cancellation')&&!has($r,'purchase_supported_candidate'),'same_message_receipt_cancel_retained_no_purchase');
$r=runMessages([message('manager','Оплату увидела',1),message('manager','Тур подтвержден',2,1700000001)]);
check(!has($r,'purchase_supported_candidate'),'no_request_episode_no_combined_purchase');

$r=runMessages([...$purchase,message('manager','Документы готовы',5,1700000000+54*86400)]);
check($r['state']['e']===1&&$r['outputs'][4]['episode_boundary']===null,'gap_alone_no_new_trip');
$r=runMessages([...$purchase,message('customer','Подберите тур на осень',5,1700000000+54*86400)]);
check($r['state']['e']===2&&!$r['state']['linked'],'new_request_after_gap_new_candidate');
$r=runMessages([...$purchase,message('customer','Нужен тур для другой семьи',5,1700000080)]);
check($r['state']['e']===2&&!$r['state']['linked'],'parallel_group_does_not_inherit_payment');
$r=runMessages([message('customer','Подберите тур',1),message('customer','Подберите тур на август',2,1700000000+26*86400)]);
check(($r['outputs'][1]['flags']['unresolved_trip_boundary']??false)===true,'ambiguous_medium_gap_flag');
$r=single('customer','У вас купили в прошлом году, теперь нужен тур на июнь');
check(has($r,'substantive_interest')&&!has($r,'agency_purchase_intent'),'past_purchase_new_request');

$all=[...$purchase,message('manager','Сколько взрослых?',5,1700000070),message('customer','2',6,1700000071),message('manager','Оплату отменила',7,1700000072),message('customer','Подберите новый тур',8,1700000000+40*86400)];
$whole=runMessages($all);
for($split=0;$split<=count($all);$split++){
    $a=runMessages(array_slice($all,0,$split));$b=runMessages(array_slice($all,$split),$a['state']);
    check($whole['state']===$b['state']&&$whole['outputs']===[...$a['outputs'],...$b['outputs']],'split_invariance_'.$split);
}
foreach($whole['outputs']as$o){
    check(ArchiveResearchSemanticExtractor::validState($o['state']),'every_state_valid');
    check(strlen(json_encode($o['state'],JSON_THROW_ON_ERROR))<=1024,'bounded_state');
    foreach($o['events']as$e){
        check(in_array($e['goal'],ArchiveResearchSemanticExtractor::goalIds(),true),'known_goal');
        check($e['certainty']==='rule_candidate'&&!array_key_exists('text',$e),'evidence_only_candidate');
    }
}
$bad=single('customer',str_repeat('x',32769));check($bad['outputs'][0]['status']==='text_too_large','text_cap');
$bad=single('customer',"\xff");check($bad['outputs'][0]['status']==='invalid_utf8','utf8_guard');
$bad=single('customer','Оплатил',['source_ref'=>'secret']);check($bad['outputs'][0]['status']==='invalid_source_ref','ref_guard');
echo "Archive semantic extractor: ".$checks." checks passed\n";
