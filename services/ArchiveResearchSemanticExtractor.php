<?php
declare(strict_types=1);

/**
 * Pure V2 contextual rules over minimized archive messages.
 * Labels are evidence-backed candidates, never calibrated probabilities or bank truth.
 * Caller owns authorization, source ordering, chat reset and signed continuation.
 */
final class ArchiveResearchSemanticExtractor
{
    public const VERSION='ru_archive_contextual_v2';
    public const MAX_TEXT_BYTES=32768;
    public const MAX_STATE_BYTES=1024;
    private const GOALS=[
        'substantive_interest','general_purchase_intent','agency_purchase_intent',
        'payment_self_report','manager_payment_ack','booking_confirmation',
        'purchase_supported_candidate','payment_cancellation','booking_cancellation',
        'refund_report','purchased_elsewhere','deferral','office_question',
        'payment_terms_question','documents_for_booking','document_issuance',
    ];

    public static function goalIds():array{return self::GOALS;}
    /** The same temporal fence applies before an unavailable receipt advances last. */
    public static function hasLongObservationGap(mixed $previous,mixed $current):bool
    {
        return self::validTime($previous)&&self::validTime($current)&&$current-$previous>2592000;
    }
    public static function initialState():array
    {
        return ['v'=>2,'e'=>0,'last'=>null,'p'=>'unknown','q'=>'none','qt'=>null,'qr'=>null,'qi'=>0,
            'money'=>null,'mt'=>null,'booking'=>null,'bt'=>null,'cancelled'=>false,'linked'=>false];
    }
    public static function validState(array$s):bool
    {
        $zero=self::initialState();
        if(count($s)!==count($zero)||array_diff(array_keys($s),array_keys($zero))||$s['v']!==2)return false;
        if(!is_int($s['e'])||$s['e']<0||$s['e']>500000||!is_int($s['qi'])||$s['qi']<0||$s['qi']>8)return false;
        if(!in_array($s['p'],['unknown','tour','hotel','flight','addon','mixed'],true)
            ||!in_array($s['q'],['none','booking','data','payment','criteria'],true))return false;
        foreach(['last','qt','mt','bt']as$k)if($s[$k]!==null&&!self::validTime($s[$k]))return false;
        foreach(['qr','money','booking']as$k)if($s[$k]!==null&&!self::validRef($s[$k]))return false;
        foreach(['cancelled','linked']as$k)if(!is_bool($s[$k]))return false;
        return strlen(json_encode($s,JSON_THROW_ON_ERROR))<=self::MAX_STATE_BYTES;
    }

    public static function step(array$m,array$s):array
    {
        if(!self::validState($s))throw new InvalidArgumentException('archive_semantic_invalid_state');
        $events=[];$flags=[];$boundary=null;$ref=$m['source_ref']??null;$time=$m['created_at']??null;
        $role=$m['role']??null;
        $verified=($m['role_verified']??null)===true
            &&(($role==='customer'&&($m['direction']??null)==='inbound')
            ||($role==='manager'&&($m['direction']??null)==='outbound'));
        $status='scanned';
        if(($m['read_status']??null)!=='available')$status='source_unavailable';
        elseif(!$verified)$status='unknown_role';
        elseif(($m['text_minimized']??null)!==true)$status='text_not_minimized';
        elseif(!is_string($m['text']??null))$status='text_unavailable';
        elseif(strlen($m['text'])>self::MAX_TEXT_BYTES)$status='text_too_large';
        elseif(preg_match('//u',$m['text'])!==1)$status='invalid_utf8';
        elseif(!self::validRef($ref))$status='invalid_source_ref';
        elseif(($m['forwarded']??null)!==false)$status=($m['forwarded']??null)===true?'forwarded':'unknown_forwarding';
        if($status!=='scanned'){
            $s=self::barrier($s);$s['last']=self::validTime($time)?$time:null;
            return self::result($s,$status,[],['context_barrier'=>true],null);
        }
        foreach(['qr','money','booking']as$key){
            if($s[$key]!==null&&strtok($s[$key],':')!==strtok($ref,':')){
                $s=self::barrier($s);$flags['source_namespace_barrier']=true;break;
            }
        }
        $clock=self::validTime($time);
        $gap=$clock&&$s['last']!==null?$time-$s['last']:null;
        $longGap=self::hasLongObservationGap($s['last'],$time);
        if(!$clock||($gap!==null&&$gap<0)){
            $s=self::barrier($s);$flags[$clock?'reversed_clock':'unknown_time']=true;
        }
        if($s['q']!=='none'){
            $s['qi']=min(8,$s['qi']+1);
            if(!$clock||$s['qt']===null||$time-$s['qt']>86400||$s['qi']>6)$s=self::clearPrompt($s);
        }
        $markers=['[ссылка на подборку не прочитана]','[ссылка не прочитана]','[email скрыт]','[телефон скрыт]','[контакт скрыт]'];
        $text=trim(str_replace($markers,' ',$m['text']));
        $flags['unread_media']=is_array($m['media']??null)&&count($m['media'])>0&&($m['media_contents_read']??null)!==true;
        $flags['unread_links']=($m['links_redacted']??null)===true||($m['selection_links_unread']??null)===true;
        $flags['episode_identity_unverified']=true;
        $flags['initial_context_unresolved']=$s['e']===0;
        $requestText=self::requestText($text);
        $request=$role==='customer'&&!self::deniedInterest($requestText)&&self::hit('подбер\pL*|подбор\pL*|ищ(?:у|ем)\s+(?:тур|отель)|интересу\pL*|рассматрива\pL*|(?:хочу|хотим|хотел(?:а|и)?)(?:\s+бы)?\s+(?:поехать|отдохнуть|тур)|планиру\pL*\s+(?:поездк|отпуск)|нужен\s+(?:тур|отель)', $requestText);
        if($s['e']===0&&$request){$s=self::initialState();$s['e']=1;$boundary='first_trip_request_candidate';}
        $newTrip=$role==='customer'&&!self::deniedInterest($requestText)&&self::hit('нов(?:ая|ую|ой)\s+поездк|друг(?:ая|ую)\s+поездк|следующ(?:ая|ую)\s+поездк|ещ[её]\s+од(?:ин|ну)\s+тур|для\s+(?:друзей|родителей|другой\s+пары|другой\s+семьи)|(?:ещ[её]\s+одна|другая)\s+(?:пара|семья)\s+(?:хочет|просит|планирует)|теперь\s+(?:хочу|хотим|планиру\pL*)\s+(?:поехать|отдохнуть)', $requestText);
        $afterGap=$request&&$gap!==null&&($longGap||($s['linked']&&$gap>604800));
        if($s['last']!==null&&($newTrip||$afterGap)&&$boundary===null){
            $episode=$s['e']+1;$s=self::initialState();$s['e']=$episode;
            $boundary=$newTrip?'explicit_new_trip_candidate':'new_request_after_gap_candidate';
        }
        if($request&&$boundary===null&&$gap!==null&&$gap>1209600){
            $s=self::barrier($s);$flags['unresolved_trip_boundary']=true;
        }
        $partyChange=$role==='customer'&&self::hit('с\s+нами\s+(?:поедут|едут|будут)\s+ещ[её]|(?:ещ[её]\s+одна|другая)\s+(?:пара|семья)|для\s+(?:друзей|родителей|другой\s+пары|другой\s+семьи)', $requestText);
        if($partyChange&&$boundary===null){$s=self::barrier($s);$flags['party_change_unresolved']=true;}
        if($request&&$boundary===null&&($s['money']!==null||$s['booking']!==null)){
            $s=self::barrier($s);$flags['unresolved_trip_boundary']=true;
        }
        // A gap alone never establishes a second trip.
        if($longGap)$flags['long_observation_gap']=true;
        $product=self::product($text);
        if($product!==null&&$role==='customer'){
            if($s['p']!=='unknown'&&$s['p']!==$product){
                $s=self::barrier($s);$flags['product_changed_or_alternative']=true;
                $s['p']=$product==='addon'?'addon':'mixed';
            }else$s['p']=$product;
        }
        $clauses=preg_split('/(?<=[.!?;])\s+|[\r\n]+|,\s*(?=теперь(?!\pL))/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];
        if(count($clauses)>128){$clauses=array_slice($clauses,0,128);$flags['clause_limit']=true;}
        $goals=[];$relations=[];$rules=[];$newPrompt=null;$managerQuestion=false;
        foreach($clauses as$clause){
            $question=self::hit('[?？]|\A\s*(?:как|где|когда|сколько|почему|можно\s+ли)(?!\pL)',$clause);
            if($role==='manager'&&$question){$managerQuestion=true;$newPrompt=null;}
            $conditional=self::hit('(?<!\pL)(?:если|возможно|пока|бы)(?!\pL)|как\s+только|при\s+услов|зависит',$clause);
            $negated=self::hit('(?<!\pL)(?:не|нет)(?!\pL)|неоплач|неоплат|отказыва|воздерж',$clause);
            $old=self::hit('в\s+прошл|прошл\pL*\s+(?:год|раз|поездк)|раньше|предыдущ\pL*\s+тур|уже\s+отдыхал',$clause);
            $relay=self::hit('[«»“”"]|\A\s*>|мне\s+(?:написали|сказали)|нам\s+(?:написали|сказали)|говорят|цитир|пересылаю',$clause);
            $direct=!$question&&!$conditional&&!$negated&&!$old&&!$relay;
            $excludedProduct=self::hit('депозит|залог|доплат\pL*\s+за|апгрейд|экскурси|страховк|трансфер|бизнес[- ]?класс',$clause);
            $future=self::hit('(?<!\pL)(?:будет|будут|должен|должна|должны|должно|может|могут|ожидаем)(?!\pL)|ожида\pL*\s+подтвержд',$clause);
            foreach(['question'=>$question,'conditional'=>$conditional,'negation'=>$negated,'past_trip'=>$old,'quoted_or_relayed'=>$relay,'other_payment_product'=>$excludedProduct]as$k=>$v)if($v)$flags[$k]=true;
            if($role==='customer'){
                if(!$relay&&!$old&&!self::deniedInterest($clause)&&self::hit('подбер\pL*|подбор\pL*|ищ(?:у|ем)\s+(?:тур|отель)|интересу\pL*|рассматрива\pL*|сравн\pL*|чем\s+отлича\pL*|какой\s+лучше|другие\s+вариант\pL*|ещ[её]\s+(?:вариант|отел)|(?:хочу|хотим|хотел(?:а|и)?)(?:\s+бы)?\s+(?:поехать|отдохнуть|тур)|нужен\s+(?:тур|отель)',$clause)){
                    $goals['substantive_interest']=true;$rules['substantive_interest']='customer_trip_request_or_comparison';
                }
                if(!$relay&&!$old&&self::hit('офис|приеха\pL*\s+к\s+вам|прийти\s+к\s+вам|адрес\s+агентств',$clause)){
                    $goals['office_question']=true;$rules['office_question']='office_logistics_only';
                }
                if(!$relay&&!$old&&self::hit('(?:как|чем)\s+оплат|форм\pL*\s+оплат|наличн\pL*|картой|рассроч\pL*|кредит',$clause)){
                    $goals['payment_terms_question']=true;$rules['payment_terms_question']='payment_terms_only';
                }
                if(!$relay&&!$old&&!self::deniedInterest($clause)&&self::hit('вылет|ночей|ночи|взросл\pL*|реб[её]н\pL*|детей|бюджет|отел[ья]|питани\pL*|пляж|Турци\pL*|Египет|Таиланд|Мальдив\pL*',$clause)){
                    $goals['substantive_interest']=true;$rules['substantive_interest']='customer_trip_conditions';
                }
                if($direct&&$s['q']==='criteria'&&self::hit('\A\s*(?:[0-9][0-9 ,–\-]*|двое|трое|двое\s+взрослых|июнь|июль|август|сентябрь|октябрь|ноябрь|декабрь|январь|февраль|март|апрель|май)\s*[.!]?\s*\z',$clause)){
                    $goals['substantive_interest']=true;$rules['substantive_interest']='answer_to_trip_criteria_question';
                    $relations['substantive_interest']=$s['qr']!==null?[$s['qr']]:[];
                }
                $intent=self::hit('готов\pL*\s+(?:за)?брониров\pL*|давайте\s+(?:за)?брони(?:ру\pL*|ровать)(?!\pL)|давайте\s+(?:нам\s+)?этот|(?<!\pL)(?:за)?бронируем(?!\pL)|хочу\s+(?:этот\s+)?купить|хотим\s+купить|(?:бер[её]м|беру)\s+(?:этот|этот\s+тур|тур|его|вариант)|(?<!\pL)покупаю(?!\pL)|готов\pL*\s+оплат|(?:сейчас|сегодня|завтра)\s+оплачу|(?<!\pL)оплачиваю(?!\pL)',$clause);
                $affirmative=self::hit('\A\s*(?:да|да,\s*беру|давайте|бер[её]м|беру|соглас(?:ен|на|ны)|оформляем|дистанционн(?:о|ое)|удал[её]нно|онлайн|в\s+офисе)\s*[.!]?\s*\z',$clause);
                if($direct&&($intent||($affirmative&&$s['q']==='booking'))){
                    $goals['general_purchase_intent']=true;$rules['general_purchase_intent']='direct_customer_booking_or_payment_intent';
                    if($s['q']==='booking'||(in_array($s['p'],['tour','hotel','flight'],true)&&$s['q']!=='criteria'
                        &&self::hit('давайте\s+(?:нам\s+)?этот\s+(?:тур|отель)(?!\pL)',$clause))||self::hit('давайте\s+(?:за)?брони(?:ру\pL*|ровать)(?!\pL)|(?<!\pL)(?:за)?бронируем(?!\pL)|(?:бер[её]м|беру)\s+(?:этот|тур|его|вариант)',$clause)){
                        $goals['agency_purchase_intent']=true;$rules['agency_purchase_intent']='response_to_current_booking_context';
                        if($s['qr']!==null)$relations['agency_purchase_intent']=[$s['qr']];
                    }
                }
                if($direct&&in_array($s['q'],['booking','data'],true)
                    &&in_array($s['p'],['tour','hotel','flight'],true)
                    &&self::hit('давайте\s+(?:оформим\s+)?(?:дистанционно|удал[её]нно|онлайн|в\s+офисе)(?!\pL)|(?:дистанционно|удал[её]нно|онлайн)(?s:.{0,50})(?:отправлю|вышлю|пришлю|направлю)\s+вам(?s:.{0,40})(?:документ|паспорт|данные)',$clause)){
                    $goals['general_purchase_intent']=true;$goals['agency_purchase_intent']=true;
                    $rules['general_purchase_intent']=$rules['agency_purchase_intent']='customer_accepts_current_booking_mode';
                    if($s['qr']!==null)$relations['general_purchase_intent']=$relations['agency_purchase_intent']=[$s['qr']];
                }
                if($direct&&self::hit('(?:куп|заброниру|брониру|бер[её]м)\pL*(?s:.{0,40})у\s+вас|(?:только|именно)\s+у\s+вас\s+(?:куп|заброниру|брониру)\pL*',$clause)){
                    $goals['agency_purchase_intent']=true;$goals['general_purchase_intent']=true;
                    $rules['agency_purchase_intent']='explicit_seller_choice';$rules['general_purchase_intent']='explicit_seller_choice';
                }
                if($direct&&$s['q']==='data'&&self::hit('мой\s+паспорт|наши\s+паспорт|высылаю\s+(?:данные|паспорт)|отправляю\s+(?:данные|паспорт)|сейчас\s+вышлю\s+(?:все\s+)?данные',$clause)){
                    $goals['documents_for_booking']=true;$rules['documents_for_booking']='customer_data_submission_in_booking_context';
                    $relations['documents_for_booking']=$s['qr']!==null?[$s['qr']]:[];
                }
                $elsewhere=self::hit('(?:купил|купили|забронировал|забронировали|оплатил|оплатили)\pL*(?s:.{0,60})(?:у\s+друг|в\s+другом\s+агент|не\s+у\s+вас)',$clause);
                if(!$question&&!$conditional&&!$old&&!$relay&&$elsewhere){
                    $goals['purchased_elsewhere']=true;$rules['purchased_elsewhere']='customer_reports_other_seller_purchase';
                }
                if($direct&&!$elsewhere&&!$excludedProduct&&self::hit('(?<!\pL)(?:оплатил[аи]?|оплатили|вн[её]с(?:ла|ли)?\s+предоплат\pL*)(?!\pL)',$clause)){
                    $goals['payment_self_report']=true;$rules['payment_self_report']='customer_past_payment_statement';
                }
                if(!$relay&&!$old&&self::hit('подумаем|обсу(?:д|ж)\pL*\s+с\s+(?:муж|жен|семь)|с\s+(?:муж|жен|семь)\pL*\s+обсу(?:д|ж)\pL*|соглас\pL*\s+отпуск|пока\s+(?:воздерж|не)|позже|жду\s+зарплат|если\s+куплю\s+билет',$clause)){
                    $goals['deferral']=true;$rules['deferral']='expressed_delay_or_dependency';
                }
            }else{
                // A request cue starts the actual question after a generic agency introduction.
                $promptClause=preg_replace('~\A(?s:.*)(?<!\pL)(?:подскажите|уточните|скажите)(?!\pL)[,\s:]*~iu','',$clause)??$clause;
                $criteriaQuestion=$question&&!$relay&&!$old&&self::hit('сколько\s+(?:взросл|дет|ноч)|(?:какие|на\s+какие)\s+дат|какой\s+бюджет|откуда\s+вылет|из\s+какого\s+города|тур\s+нужен\s+с\s+вылетом|(?:вы\s+)?летите\s+из|(?:вы\s+)?вылетаете\s+из',$promptClause);
                if($criteriaQuestion)$newPrompt='criteria';
                elseif($question&&!$relay&&!$old&&!$conditional&&!$negated&&self::hit('(?:за)?брониру\pL*|оформля\pL*\s+тур|выбрали\s+(?:тур|вариант)|офис(?s:.{0,60})(?:дистанцион|удал[её]н|онлайн)|(?:дистанцион|удал[её]н|онлайн)(?s:.{0,60})офис',$promptClause))$newPrompt='booking';
                elseif(!$relay&&!$old&&(self::hit('(?:пришлите|отправьте|нужны|жду)\s+(?:ваши\s+)?(?:паспорт|данные)(?s:.{0,60})(?:брон|оформ)|для\s+(?:бронирован|оформлен)\pL*(?s:.{0,60})(?:паспорт|данные)',$clause)
                    ||(self::hit('тур|турист',$clause)&&self::hit('для\s+договора(?s:.{0,120})(?:паспорт|данные)',$clause))))$newPrompt='data';
                elseif(!$relay&&!$old&&self::hit('ссылк\pL*(?s:.{0,60})оплат|оплат\pL*(?s:.{0,60})ссылк',$clause))$newPrompt='payment';
                elseif($question&&!$relay&&!$old&&self::hit('сколько\s+(?:взросл|дет|ноч)|(?:какие|на\s+какие)\s+дат|какой\s+бюджет|откуда\s+вылет',$clause))$newPrompt='criteria';
                if($direct&&!$future&&!$excludedProduct&&self::hit('(?:оплат|плат[её]ж|деньг)\pL*(?s:.{0,30})(?:вижу|увидел(?:а|и)?|поступил(?:а|и|о)?|получил(?:а|и)?|получен(?:а|о|ы)?)(?!\pL)|(?:вижу|увидел(?:а|и)?|получил(?:а|и)?)(?!\pL)(?s:.{0,30})(?:оплат|плат[её]ж|деньг)\pL*',$clause)){
                    $goals['manager_payment_ack']=true;$rules['manager_payment_ack']='manager_explicit_money_receipt';
                }
                if($direct&&!$future&&!$excludedProduct&&self::hit('(?:тур|брон\pL*|заявк\pL*)\s+(?:для\s+вас\s+)?(?:забронирован\pL*\s+и\s+)?(?:уже\s+|успешно\s+)?подтвержд[её]н(?:а|о|ы)?(?!\pL)|подтвержд[её]н(?:а|о|ы)?\s+(?:брон|тур)|(?:наш|ваш)\s+тур\s+(?:уже\s+)?подтвердил\pL*|(?:бронирование|бронирования)\s+(?:уже\s+|успешно\s+)?подтвержден(?:о|ы)(?!\pL)|(?:тур|бронь|заявка|бронирование)\s+подтвердил(?:ся|ась|ось|ись)(?!\pL)',$clause)){
                    $goals['booking_confirmation']=true;$rules['booking_confirmation']='manager_direct_booking_confirmation';
                }
                if($direct&&!$future&&self::hit('(?:документ|ваучер)\pL*(?s:.{0,40})(?:отправлен|выдан|готов|на\s+почте)|(?:направляю|отправил\pL*)(?s:.{0,40})(?:документ|ваучер)',$clause)){
                    $goals['document_issuance']=true;$rules['document_issuance']='manager_documents_statement_not_payment';
                }
            }
            if($direct&&!$future&&self::hit('(?:оплат|плат[её]ж)\pL*\s+отменил(?:а|и)?|отменил(?:а|и)?\s+(?:оплат|плат[её]ж)|(?:оплат|плат[её]ж)\pL*\s+отмен[её]н(?:а|о|ы)?',$clause)){
                $goals['payment_cancellation']=true;$rules['payment_cancellation']='direct_payment_cancel_statement';
            }
            if($direct&&!$future&&self::hit('(?:тур|брон\pL*|заявк\pL*)\s+(?:отмен[её]н|аннулирован)\pL*|отменил(?:а|и)?\s+(?:тур|брон)|отель\s+отказал',$clause)){
                $goals['booking_cancellation']=true;$rules['booking_cancellation']='direct_booking_cancel_or_supplier_refusal';
            }
            if($direct&&!$future&&self::hit('возврат\s+(?:получил\pL*|поступил|приш[её]л)|деньги\s+вернулись|вернул(?:а|и)?\s+(?:деньги|оплату)',$clause)){
                $goals['refund_report']=true;$rules['refund_report']='reported_refund_not_bank_verified';
            }
        }
        if($s['p']==='addon'){
            foreach(['payment_self_report','manager_payment_ack','booking_confirmation']as$g)if(isset($goals[$g])){
                unset($goals[$g]);$flags['addon_context_not_main_purchase']=true;
            }
        }
        // A first useful customer statement establishes a provisional episode, not proven trip identity.
        // Receipt/booking seen before that start must never become the new episode's evidence.
        if($s['e']===0&&$role==='customer'&&(isset($goals['substantive_interest'])
            ||isset($goals['general_purchase_intent'])||isset($goals['agency_purchase_intent']))){
            $s=self::barrier($s);$s['e']=1;$s['cancelled']=false;$s['p']=$product??'unknown';
            $boundary=isset($goals['substantive_interest'])?'first_trip_conditions_candidate':'first_booking_context_candidate';
            $flags['initial_context_unresolved']=true;
        }
        // Emit observed events, retaining history even when a cancellation is in the same message.
        foreach(self::GOALS as$g)if(isset($goals[$g]))$events[]=self::event($g,$s,$ref,$rules[$g]??'context_rule',$relations[$g]??[]);
        $cancel=isset($goals['payment_cancellation'])||isset($goals['booking_cancellation'])||isset($goals['refund_report']);
        if($cancel){$s['cancelled']=true;$s['linked']=false;$s['money']=null;$s['mt']=null;$s['booking']=null;$s['bt']=null;}
        else{
            if($clock&&isset($goals['manager_payment_ack'])){$s['money']=$ref;$s['mt']=$time;$s['cancelled']=false;}
            if($clock&&isset($goals['booking_confirmation'])){$s['booking']=$ref;$s['bt']=$time;}
            if($s['e']>0&&!$s['linked']&&!$s['cancelled']&&$s['money']!==null&&$s['booking']!==null
                &&$s['mt']!==null&&$s['bt']!==null&&abs($s['mt']-$s['bt'])<=1209600
                &&!in_array($s['p'],['addon','mixed'],true)){
                $events[]=self::event('purchase_supported_candidate',$s,$ref,'receipt_and_confirmed_booking_same_candidate_episode',[$s['money'],$s['booking']]);
                $s['linked']=true;
            }
        }
        if(($role==='customer'&&$text!=='')||$managerQuestion)$s=self::clearPrompt($s);
        if($newPrompt!==null&&$clock){$s['q']=$newPrompt;$s['qt']=$time;$s['qr']=$ref;$s['qi']=0;}
        $s['last']=$clock?$time:null;
        return self::result($s,'scanned',$events,$flags,$boundary);
    }

    private static function event(string$goal,array$s,string$ref,string$rule,array$related):array
    {
        return ['goal'=>$goal,'episode'=>$s['e'],'source_ref'=>$ref,
            'related_source_refs'=>array_values(array_unique($related)),'certainty'=>'rule_candidate',
            'product'=>$s['p'],'rule'=>$rule];
    }
    private static function result(array$s,string$status,array$events,array$flags,?string$boundary):array
    {
        return ['version'=>self::VERSION,'status'=>$status,'state'=>$s,'events'=>$events,
            'flags'=>$flags,'episode_boundary'=>$boundary,
            'semantics'=>'contextual_rule_candidates; episode_identity_unverified; not_purchase_probability'];
    }
    private static function clearPrompt(array$s):array{$s['q']='none';$s['qt']=null;$s['qr']=null;$s['qi']=0;return$s;}
    private static function barrier(array$s):array
    {
        $s=self::clearPrompt($s);$s['money']=null;$s['mt']=null;$s['booking']=null;$s['bt']=null;$s['linked']=false;return$s;
    }
    private static function product(string$t):?string
    {
        if(self::hit('доплат\pL*\s+за|депозит|залог|апгрейд',$t))return'addon';
        if(self::hit('только\s+(?:авиа)?билет|только\s+перел[её]т',$t))return'flight';
        if(self::hit('только\s+отел|без\s+перел[её]та',$t))return'hotel';
        if(self::hit('(?<!\pL)тур(?:ы|а|ом|у|ов)?(?!\pL)|пакетный\s+тур',$t))return'tour';
        return null;
    }
    /** Ignore quoted, hypothetical and historical clauses when deciding provisional boundaries. */
    private static function requestText(string$text):string
    {
        $clauses=preg_split('/(?<=[.!?;])\s+|[\r\n]+|,\s*(?=теперь(?!\pL))/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];
        $out=[];
        foreach(array_slice($clauses,0,128)as$clause){
            if(self::hit('[«»“"]|\A\s*>|мне\s+(?:написали|сказали)|нам\s+(?:написали|сказали)|цитир|пересылаю',$clause)
                ||self::hit('(?<!\pL)(?:если)(?!\pL)|при\s+услов|в\s+прошл|прошл\pL*\s+(?:год|раз|поездк)|предыдущ\pL*\s+тур|уже\s+(?:отдыхал|были)', $clause)
                ||self::deniedInterest($clause))continue;
            $out[]=$clause;
        }
        return implode(' ',$out);
    }
    private static function deniedInterest(string$t):bool
    {
        return self::hit('(?<!\pL)не\s+(?:интересу|рассматрива|ищ(?:у|ем)|(?:хот(?:им|ел|ят)|хоч(?:у|ет))|планиру|нуж|подб)', $t);
    }
    private static function hit(string$p,string$t):bool{return preg_match('~'.$p.'~iu',$t)===1;}
    private static function validTime(mixed$v):bool{return is_int($v)&&$v>0&&$v<=253402300799;}
    private static function validRef(mixed$v):bool{return is_string($v)&&preg_match('/\A(?:archive_message|journal):[1-9][0-9]{0,19}\z/D',$v)===1;}
}
