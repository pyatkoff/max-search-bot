<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ArchiveResearchSemanticExtractor.php';
require_once dirname(__DIR__).'/services/ModeledConversionSemanticPrediction.php';
require_once dirname(__DIR__).'/services/ModeledConversionEarlyForecast.php';
require_once dirname(__DIR__).'/services/ModeledConversionPredictionFollowup.php';

function semanticPredictionSubject():array
{return ['project_key'=>'anytour','platform'=>'telegram','customer_key'=>'7','conversation_id'=>'9'];}
function semanticPredictionNew():ModeledConversionSemanticPrediction
{return new ModeledConversionSemanticPrediction(semanticPredictionSubject(),'2026-11-02T01:00:00Z',
    '2026-11-02T00:00:00Z','2026-11-03T12:00:00Z','100','100');}
function semanticPredictionLegacy(array $discussions=[]):array
{return ['early_forecast'=>['status'=>'early_assessment','early_quality'=>[
    'version'=>'request_offer_reaction_v2','specific_option_discussions'=>$discussions]]];}
function semanticPredictionResult(string $text,string $role,string $ref,int $at,array &$state):array
{
    $r=ArchiveResearchSemanticExtractor::step(['source_ref'=>$ref,'created_at'=>$at,
        'read_status'=>'available','text'=>$text,'role'=>$role,'direction'=>$role==='customer'?'inbound':'outbound',
        'role_verified'=>true,'text_minimized'=>true,'forwarded'=>false,'media'=>[],
        'media_contents_read'=>false,'selection_links_unread'=>false,'links_redacted'=>false],$state);
    $state=$r['state'];return$r;
}
function semanticPredictionRun(array $messages,?array $legacy=null):array
{
    $predictor=semanticPredictionNew();$state=ArchiveResearchSemanticExtractor::initialState();
    $base=strtotime('2026-11-01T00:00:00Z');
    foreach($messages as$i=>$m){
        $ref='journal:'.($m['id']??($i+1));$at=$base+($m['seconds']??($i*60));$version=$m['version']??1;
        if(($m['operational']??false)===true){$predictor->knownOperationalOutcome($ref,$at,$at+1,$version);continue;}
        if(($m['barrier']??false)===true){
            $predictor->barrier();
            $state=ArchiveResearchSemanticExtractor::step(['read_status'=>'source_unavailable',
                'source_ref'=>$ref,'created_at'=>$at],$state)['state'];continue;
        }
        $role=$m['role']??'customer';$text=$m['text'];
        $r=semanticPredictionResult($text,$role,$ref,$at,$state);
        if(isset($m['flags']))$r['flags']=array_replace($r['flags'],$m['flags']);
        if(isset($m['boundary']))$r['episode_boundary']=$m['boundary'];
        $predictor->observeMessage($role,$text,$ref,$at,$at+1,$version,$r);
    }
    return$predictor->finish($legacy??semanticPredictionLegacy());
}
function semanticPredictionAssert(bool $condition,string $message):void
{if(!$condition)throw new RuntimeException($message);}
function semanticPredictionThrows(callable $operation):bool
{try{$operation();}catch(InvalidArgumentException $e){return true;}return false;}
function semanticPredictionDiscussion(bool $current=true):array
{
    return ['source_refs'=>['journal:1','journal:2','journal:3','journal:4'],
        'available_at'=>'2026-11-01T00:03:01Z','reaction'=>'price_alternative_request',
        'search_continuation'=>'explicit_request','request_binding'=>'same_observed_candidate_segment_not_verified_trip',
        'current_candidate'=>$current];
}
function semanticPredictionReverseObjects(array $value):array
{
    if(!array_is_list($value))$value=array_reverse($value,true);
    foreach($value as&$item)if(is_array($item))$item=semanticPredictionReverseObjects($item);
    return$value;
}

function semanticPredictionFreezeFixture(array $messages):array
{
    $now=1793002000;$subject=['project_key'=>'anytour','platform'=>'telegram','customer_key'=>'7','conversation_id'=>'9'];
    $scope=$subject+['episode_key'=>'unresolved-prefix:9'];$rows=[];$evidence=[];
    foreach($messages as$i=>$message){
        $id=(string)($i+1);$role=$message['role']??'customer';$at=$now+$i*10;
        $snap=['source_kind'=>'message','source_id'=>$id,'source_status'=>'present','payload_status'=>'copied',
            'source_created_at_unix'=>$at,'source_timestamp_basis'=>'database_session_timezone_to_unix',
            'payload'=>['text'=>$message['text'],'metadata_json'=>'{"type":"message"}']]+$subject;
        if(($message['clock']??true)===false)unset($snap['source_created_at_unix']);
        $rows[]=['journal_id'=>$id,'source_kind'=>'message','source_id'=>$id,'source_version_no'=>1,
            'observed_at_unix'=>$at+1,'snapshot'=>$snap];
        if(($message['admitted']??true)===true){
            $ref=['source'=>'message','id'=>'journal:'.$id,'at'=>gmdate('Y-m-d\\TH:i:s\\Z',$at),'available_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$at+1)];
            $evidence[]=['id'=>'message:journal:'.$id,'kind'=>$role.'_message','subject'=>$scope,
                'at'=>$ref['at'],'available_at'=>$ref['available_at'],'references'=>[$ref]];
        }
    }
    $at=gmdate('Y-m-d\\TH:i:s\\Z',$now+1000);$cursor=(string)max(1,count($rows));
    $case=['subject'=>$subject,'rows'=>array_reverse($rows),'window_limited'=>false,'access_denied'=>false];
    $wrapper=['subject'=>$subject,'journal_cursor'=>$cursor,'source_fence_journal_id'=>$cursor,
        'access_status'=>'allowed','coverage'=>['errors'=>[]],
        'profile'=>['prediction_at'=>$at,'observation_input'=>['subject'=>$scope,'prediction_at'=>$at,'evidence'=>$evidence]]];
    $document=['requested_at'=>$at,'journal_cursor'=>$cursor,'source_fence_journal_id'=>$cursor];
    return [$case,$wrapper,$document];
}
function semanticPredictionFreezeRun(array $messages):array{return ModeledConversionEarlyForecast::freeze(...semanticPredictionFreezeFixture($messages));}
/** Full synthetic admitted request + canonical producer receipts + later text. */
function semanticPredictionOfferFixture(string $reaction,array $between=[],string $query='Подберите тур в Турцию',array $after=[]):array
{
    $parts=semanticPredictionFreezeFixture([['text'=>$query],['role'=>'manager','text'=>'prepared'],
        ['role'=>'manager','text'=>'delivered'],...$between,['text'=>$reaction],...$after]);
    $snapshot=['hotel'=>'Fixture Beach A','departure'=>'Fixture city','room'=>'Standard','meal'=>'AI',
        'date_from'=>'2026-11-10','date_to'=>'2026-11-17','amount'=>'200000','currency'=>'RUB',
        'price_basis'=>'party','is_demo'=>false,'price_verified'=>false];
    $data=[2=>['schema'=>1,'binding'=>['project_key'=>'anytour','channel'=>'telegram',
        'external_chat_id'=>'7','external_user_id'=>'7'],'snapshot'=>$snapshot,'token_hash'=>str_repeat('a',64)],
        3=>['offer_id'=>2,'native_mid'=>'synthetic-native-mid','native_chat_id'=>'7']];
    $refs=[];
    foreach($parts[0]['rows']as&$row){
        $id=(int)$row['journal_id'];if(!isset($data[$id]))continue;
        $at=$row['snapshot']['source_created_at_unix'];
        $clock=['version'=>1,'basis'=>'server_recording_unix','unix'=>$at];
        $row['source_kind']='event';$row['snapshot']['source_kind']='event';
        $row['snapshot']['source_timestamp_basis']='canonical_recording_unix_v1';
        $row['snapshot']['payload']=['event_type'=>$id===2?'tour_offer_prepared':'tour_offer_delivered',
            'actor_type'=>'system','payload_json'=>json_encode($data[$id]+['_recorded_clock'=>$clock],JSON_THROW_ON_ERROR)];
        $refs[$id]=['journal_id'=>(string)$id,'source_id'=>(string)$id,'source_version_no'=>1,
            'recorded_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$at),
            'available_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$row['observed_at_unix'])];
    }
    unset($row);
    $parts[1]['profile']['observation_input']['evidence']=array_values(array_filter(
        $parts[1]['profile']['observation_input']['evidence'],
        static fn(array $e):bool=>!in_array($e['references'][0]['id'],['journal:2','journal:3'],true)));
    $parts[1]['offer_journey']=['version'=>'admitted_offer_journey_v1','offers'=>[
        ['snapshot'=>$snapshot,'prepared'=>$refs[2],'delivery'=>$refs[3],'reaction'=>null,
            'state'=>'delivered','customer_response'=>'unknown','available_at'=>$refs[3]['available_at']]]];
    return $parts;
}
/** Move synthetic dependencies together; recording, availability and cutoff stay coherent. */
function semanticPredictionOfferMoveTimes(array $parts,array $deltas):array
{
    $clocks=[];
    foreach($parts[0]['rows']as&$row){
        $id=$row['journal_id'];$delta=$deltas[$id]??0;
        $row['snapshot']['source_created_at_unix']+=$delta;$row['observed_at_unix']+=$delta;
        if($row['source_kind']==='event'){
            $data=json_decode($row['snapshot']['payload']['payload_json'],true,512,JSON_THROW_ON_ERROR);
            $data['_recorded_clock']['unix']+=$delta;
            $row['snapshot']['payload']['payload_json']=json_encode($data,JSON_THROW_ON_ERROR);
        }
        $clocks['journal:'.$id]=['at'=>gmdate('Y-m-d\\TH:i:s\\Z',$row['snapshot']['source_created_at_unix']),
            'available_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$row['observed_at_unix'])];
    }
    unset($row);
    foreach($parts[1]['profile']['observation_input']['evidence']as&$e){
        $clock=$clocks[$e['references'][0]['id']];
        foreach($clock as$key=>$value)$e[$key]=$e['references'][0][$key]=$value;
    }
    unset($e);
    foreach($parts[1]['offer_journey']['offers']as&$offer){
        foreach(['prepared','delivery']as$key){
            $clock=$clocks['journal:'.$offer[$key]['journal_id']];
            $offer[$key]['recorded_at']=$clock['at'];$offer[$key]['available_at']=$clock['available_at'];
        }
        $offer['available_at']=$offer['delivery']['available_at'];
    }
    unset($offer);
    $cutoff=gmdate('Y-m-d\\TH:i:s\\Z',1793003000+max($deltas));
    $parts[1]['profile']['prediction_at']=$parts[1]['profile']['observation_input']['prediction_at']=$cutoff;
    $parts[2]['requested_at']=$cutoff;
    return $parts;
}

$tests=[];
$tests['greeting and logistics alone provide no probability']=static function():void{
    foreach(['Здравствуйте!','Где ваш офис? Как оплатить картой?']as$text){
        $p=semanticPredictionRun([['text'=>$text]]);
        semanticPredictionAssert($p['status']==='insufficient_context'&&$p['estimated_probability']===null
            &&$p['base_probability']===null&&$p['current_request_anchor']===null,'empty/logistics promoted');
    }
};
$tests['illustrative priors distinguish request meaning and seller intent']=static function():void{
    $sub=semanticPredictionRun([['text'=>'Подберите тур','version'=>3]]);
    $con=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей, 2 взрослых, бюджет 150 тысяч.']]);
    $general=semanticPredictionRun([['text'=>'Готовы оплатить тур.']]);
    $agency=semanticPredictionRun([['text'=>'Купим тур только у вас.']]);
    semanticPredictionAssert($sub['stage']==='substantive_dialogue'&&$sub['base_probability']===0.05,'substantive prior');
    semanticPredictionAssert($sub['current_request_anchor']==='journal:1'&&$sub['anchor_source_version_no']===3,'anchor/version');
    semanticPredictionAssert($con['stage']==='concrete_request'&&$con['base_probability']===0.083333,'concrete prior');
    semanticPredictionAssert($general['stage']==='general_purchase_intent'&&$general['base_probability']===0.333333,'general prior');
    semanticPredictionAssert($agency['stage']==='agency_purchase_intent'&&$agency['base_probability']===0.5,'agency prior');
    $logistics=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей.'],['text'=>'Где офис? Как оплатить картой?']]);
    semanticPredictionAssert($logistics['stage']==='concrete_request','logistics became seller intent');
};
$tests['bounded criteria handle countries outside canonical narrow vocabulary']=static function():void{
    $p=semanticPredictionRun([['text'=>'Испания в июле, 2 взрослых, бюджет 200 тысяч.']]);
    semanticPredictionAssert($p['stage']==='concrete_request'
        &&in_array('destination',$p['features']['criteria_families'],true)
        &&in_array('dates',$p['features']['criteria_families'],true),'criteria not combined');
};
$tests['manager text and guarded customer clauses never promote']=static function():void{
    $manager=semanticPredictionRun([['role'=>'manager','text'=>'Давайте бронируем тур в Турцию на 7 ночей, 2 взрослых.']]);
    semanticPredictionAssert($manager['estimated_probability']===null,'manager promoted');
    foreach(['Не хотим купить тур в Турцию на 7 ночей.',
        '«Давайте бронируем тур в Турцию на 7 ночей».',
        'Если решим, давайте бронировать тур в Турцию на 7 ночей.',
        'В прошлом году хотели купить тур в Турцию на 7 ночей.']as$text){
        $p=semanticPredictionRun([['text'=>$text]]);
        semanticPredictionAssert($p['estimated_probability']===null&&$p['current_request_anchor']===null,'guard failed: '.$text);
    }
};
$tests['canonical intent in a direct clause survives unrelated message flags']=static function():void{
    foreach(['В прошлом году срочно отдыхали в Турции. Купим тур только у вас.',
        'Не нужен трансфер. Давайте бронировать этот тур.']as$text){
        $p=semanticPredictionRun([['text'=>$text]]);
        semanticPredictionAssert($p['stage']==='agency_purchase_intent'&&$p['estimated_probability']===0.5
            &&!$p['features']['near_term_urgency'],'unrelated clause hid canonical seller choice or promoted old urgency');
    }
};
$tests['conditional and interrogative refusals do not become actual declines']=static function():void{
    foreach(['Если дорого, тур нам не нужен.','Тур нам не нужен?','Если не будем бронировать, я сообщу.']as$text){
        $p=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей.'],['text'=>$text]]);
        semanticPredictionAssert(!$p['features']['explicit_decline']&&$p['estimated_probability']===0.083333,
            'conditional or questioned refusal became actual decline');
    }
};
$tests['contrary outcome evidence survives the bounded observation cap']=static function():void{
    $messages=[['text'=>'Подберите тур в Турцию на 7 ночей.'],['text'=>'Возврат получили.']];
    for($i=0;$i<12;$i++)$messages[]=['role'=>'manager','text'=>'Оплату вижу.'];
    $p=semanticPredictionRun($messages);
    semanticPredictionAssert(count($p['outcome_observations'])<=8
        &&in_array('refund_report',array_column($p['outcome_observations'],'kind'),true),
        'later payment reports discarded the contrary outcome');
};
$tests['unavailable and canonical barriers clear stale positives and anchor']=static function():void{
    $legacy=semanticPredictionLegacy([semanticPredictionDiscussion()]);
    $legacy['contextual_signals']['current_episode_goal_counts']=['agency_purchase_intent'=>999,'substantive_interest'=>999];
    $p=semanticPredictionRun([['text'=>'Купим тур только у вас.'],['barrier'=>true],['text'=>'Спасибо!']],$legacy);
    semanticPredictionAssert($p['estimated_probability']===null&&$p['current_request_anchor']===null,'unavailable survived');
    foreach(['long_observation_gap','unresolved_trip_boundary','party_change_unresolved',
        'product_changed_or_alternative','source_namespace_barrier']as$flag){
        $p=semanticPredictionRun([['text'=>'Купим тур только у вас.'],
            ['role'=>'manager','text'=>'Здравствуйте!','flags'=>[$flag=>true]]],$legacy);
        semanticPredictionAssert($p['estimated_probability']===null&&$p['current_customer_turn_count']===0,'barrier '.$flag);
    }
};
$tests['new trip and first meaningful message define stable journal anchor']=static function():void{
    $p=semanticPredictionRun([['text'=>'Здравствуйте!'],['text'=>'Купим тур только у вас.'],
        ['text'=>'Теперь хочу поехать в Турцию на новую поездку.']]);
    semanticPredictionAssert($p['current_request_anchor']==='journal:3'&&$p['stage']==='substantive_dialogue'
        &&$p['current_customer_turn_count']===1,'new trip reused old agency choice');
    $state=ArchiveResearchSemanticExtractor::initialState();$at=strtotime('2026-11-01T00:00:00Z');
    $r=semanticPredictionResult('Подберите тур','customer','journal:1',$at,$state);
    $a=semanticPredictionNew();$b=semanticPredictionNew();
    $a->observeMessage('customer','Подберите тур','journal:1',$at,$at+1,1,$r);
    $r['state']['e']=87;foreach($r['events']as&$event)$event['episode']=87;unset($event);
    $b->observeMessage('customer','Подберите тур','journal:1',$at,$at+1,1,$r);
    semanticPredictionAssert($a->finish(semanticPredictionLegacy())['prediction_digest']===$b->finish(semanticPredictionLegacy())['prediction_digest'],
        'window-dependent episode ordinal affected prediction');
};
$tests['exact current offer discussion promotes continued cheaper search']=static function():void{
    $messages=[['id'=>1,'text'=>'Подберите тур в Турцию на 7 ночей.'],
        ['id'=>4,'seconds'=>180,'text'=>'Fixture Beach A дорого, найдите дешевле.']];
    $p=semanticPredictionRun($messages,semanticPredictionLegacy([semanticPredictionDiscussion()]));
    semanticPredictionAssert($p['stage']==='exact_offer_discussion'&&$p['base_probability']===0.166667
        &&$p['estimated_probability']>=$p['base_probability']&&$p['features']['price_alternative_continued_search'],
        'price alternative treated as automatic negative');
    $old=semanticPredictionRun($messages,semanticPredictionLegacy([semanticPredictionDiscussion(false)]));
    semanticPredictionAssert($old['stage']==='concrete_request','old offer promoted');
    $barrier=semanticPredictionRun([['id'=>1,'text'=>'Подберите тур в Турцию на 7 ночей.'],
        ['id'=>3,'barrier'=>true],['id'=>4,'seconds'=>180,'text'=>'Fixture Beach A дорого, найдите дешевле.']],
        semanticPredictionLegacy([semanticPredictionDiscussion()]));
    semanticPredictionAssert($barrier['estimated_probability']===null,'stale offer bypassed own barrier');
    $other=semanticPredictionDiscussion();$other['source_refs'][0]='journal:9';
    semanticPredictionAssert(semanticPredictionRun($messages,semanticPredictionLegacy([$other]))['stage']==='concrete_request',
        'wrong request anchor linked');
    $messages[1]['text']='«Fixture Beach A дорого, найдите дешевле».';
    semanticPredictionAssert(semanticPredictionRun($messages,semanticPredictionLegacy([semanticPredictionDiscussion()]))['stage']==='concrete_request',
        'quoted offer reaction linked');
};
$tests['urgency and long range adjust the same prior as bounded hypotheses']=static function():void{
    $neutral=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей.']]);
    $urgent=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей, срочно, вылет завтра.']]);
    $far=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей на следующий год.']]);
    semanticPredictionAssert($neutral['base_probability']===$urgent['base_probability']
        &&$neutral['base_probability']===$far['base_probability'],'timing changed stage prior');
    semanticPredictionAssert($urgent['estimated_probability']>$neutral['estimated_probability']
        &&$far['estimated_probability']<$neutral['estimated_probability'],'timing hypotheses absent');
    semanticPredictionAssert($urgent['hypothesis_odds_multiplier']<=1.3&&$far['hypothesis_odds_multiplier']>=0.25,'odds uncapped');
};
$tests['deferral and direct decline reduce a current hypothesis']=static function():void{
    $request=['text'=>'Подберите тур в Турцию на 7 ночей.'];
    $normal=semanticPredictionRun([$request]);
    foreach(['Подумаем, обсудим с мужем позже.','Тур нам не нужен.']as$text){
        $p=semanticPredictionRun([$request,['text'=>$text]]);
        semanticPredictionAssert($p['base_probability']===$normal['base_probability']
            &&$p['estimated_probability']<$normal['estimated_probability'],'deferral/decline not reflected');
    }
    $renewed=semanticPredictionRun([$request,['text'=>'Подумаем позже.'],['text'=>'Купим тур только у вас.']]);
    semanticPredictionAssert(!$renewed['features']['deferral']&&$renewed['stage']==='agency_purchase_intent','renewed intent stayed deferred');
};
$tests['meaningful engagement is independent of reply speed']=static function():void{
    $messages=[['text'=>'Подберите тур в Турцию на 7 ночей.'],
        ['role'=>'manager','text'=>'Здравствуйте!','seconds'=>60],
        ['text'=>'Сравните другие варианты.','seconds'=>61]];
    $fast=semanticPredictionRun($messages);$messages[2]['seconds']=3600;$slow=semanticPredictionRun($messages);
    semanticPredictionAssert($fast['estimated_probability']===$slow['estimated_probability']
        &&$fast['features']['meaningful_customer_turns']===2,'reply speed affected probability');
    $thanks=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей.'],['text'=>'Спасибо!']]);
    semanticPredictionAssert($thanks['features']['meaningful_customer_turns']===1,'greeting promoted engagement');
};
$tests['known CRM and observed operational evidence suppress probabilities']=static function():void{
    $p=semanticPredictionRun([['operational'=>true],['barrier'=>true],['text'=>'Подберите тур в Турцию на 7 ночей.']]);
    semanticPredictionAssert($p['status']==='known_outcome'&&$p['estimated_probability']===null&&$p['base_probability']===null
        &&$p['outcome_observations'][0]['kind']==='known_operational_outcome','global CRM exclusion lost');
    foreach([['text'=>'Оплатили тур.'],['role'=>'manager','text'=>'Оплату вижу.'],
        ['role'=>'manager','text'=>'Бронирование подтверждено.'],['role'=>'manager','text'=>'Документы готовы.']]as$outcome){
        $p=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей.'],$outcome]);
        semanticPredictionAssert($p['status']==='known_outcome'&&$p['estimated_probability']===null
            &&count($p['outcome_observations'])>=1,'observed outcome not excluded');
        semanticPredictionAssert($p['outcome_observations'][0]['kind']!=='verified_payment','invented verified payment');
    }
    $legacy=semanticPredictionLegacy();$legacy['early_forecast']['status']='target_already_observed';
    semanticPredictionAssert(semanticPredictionRun([['text'=>'Подберите тур']],$legacy)['estimated_probability']===null,'legacy exclusion ignored');
    $legacy['early_forecast']['status']='no_usable_customer_context';
    semanticPredictionAssert(semanticPredictionRun([['text'=>'Подберите тур']],$legacy)['base_probability']===null,'non-early legacy prior leaked');
};
$tests['access denied does not emit message provenance']=static function():void{
    $legacy=semanticPredictionLegacy();$legacy['early_forecast']['status']='access_denied';
    $p=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей.']],$legacy);
    semanticPredictionAssert($p['status']==='access_denied'&&$p['estimated_probability']===null&&$p['base_probability']===null
        &&$p['current_request_anchor']===null&&$p['evidence']===[]&&$p['outcome_observations']===[],'denied detail leaked');
};
$tests['actual freeze clock horizon digest and private output bounds']=static function():void{
    $p=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей.','version'=>2]]);
    semanticPredictionAssert($p['profile_prediction_at']==='2026-11-02T01:00:00Z'
        &&$p['prediction_at']==='2026-11-03T12:00:00Z'&&$p['prediction_at']===$p['frozen_at']
        &&strtotime($p['horizon_end_at'])-strtotime($p['frozen_at'])===2592000,'freeze horizon backdated');
    semanticPredictionAssert($p['prediction_digest']===ModeledConversionSemanticPrediction::digest($p),'digest mismatch');
    semanticPredictionAssert(preg_match('/\A[a-f0-9]{64}\z/D',$p['model_sha256'])===1,'model source hash absent');
    semanticPredictionAssert($p['prediction_digest']===ModeledConversionSemanticPrediction::digest(semanticPredictionReverseObjects($p)),
        'JSON object order changed digest');
    $changed=$p;$changed['estimated_probability']=0.9;
    semanticPredictionAssert($p['prediction_digest']!==ModeledConversionSemanticPrediction::digest($changed),'digest omitted estimate');
    foreach($p['evidence']as$e)semanticPredictionAssert(strtotime($e['occurred_at'])<=strtotime($e['available_at'])
        &&strtotime($e['available_at'])<=strtotime($p['input_cutoff_at'])&&is_int($e['source_version_no']),'evidence clock/version');
    semanticPredictionAssert($p['evaluation_eligible']===false&&$p['export_allowed']===false&&$p['expected_value_rub']===null
        &&$p['provider_calls']===0&&$p['model_calls']===0&&$p['conversion_uploads']===0,'side effect contract');
    $many=[];for($i=0;$i<40;$i++)$many[]=['text'=>'Подберите тур в Турцию на 7 ночей, 2 взрослых, бюджет 150 тысяч. fixture_unique_do_not_store_821'];
    for($i=0;$i<10;$i++)$many[]=['operational'=>true];
    $legacy=semanticPredictionLegacy();$legacy['arbitrary_private_data']='never-copy-this';
    $bounded=semanticPredictionRun($many,$legacy);$json=json_encode($bounded,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    semanticPredictionAssert(strlen($json)<=16384&&count($bounded['evidence'])<=16
        &&count($bounded['outcome_observations'])<=8,'output not bounded');
    semanticPredictionAssert(!str_contains($json,'fixture_unique_do_not_store_821')&&!str_contains($json,'never-copy-this')
        &&!str_contains($json,'Подберите'),'raw text retained');
};
$tests['invalid source clocks and cursor cannot enter a prediction']=static function():void{
    $p=semanticPredictionNew();$state=ArchiveResearchSemanticExtractor::initialState();
    $at=strtotime('2026-11-01T00:00:00Z');$r=semanticPredictionResult('Подберите тур','customer','journal:1',$at,$state);
    semanticPredictionAssert(semanticPredictionThrows(static fn()=>$p->observeMessage('customer','Подберите тур','journal:1',
        $at,strtotime('2026-11-02T00:00:01Z'),1,$r)),'future availability accepted');
    semanticPredictionAssert(semanticPredictionThrows(static fn()=>$p->observeMessage('customer','Подберите тур','journal:101',
        $at,$at+1,1,$r)),'future journal reference accepted');
    $wide=new ModeledConversionSemanticPrediction(semanticPredictionSubject(),'2026-11-02T01:00:00Z',
        '2026-11-02T00:00:00Z','2026-11-03T12:00:00Z','100','200');
    $wideState=ArchiveResearchSemanticExtractor::initialState();
    $wideResult=semanticPredictionResult('Подберите тур','customer','journal:150',$at,$wideState);
    $wide->observeMessage('customer','Подберите тур','journal:150',$at,$at+1,1,$wideResult);
    semanticPredictionAssert($wide->finish(semanticPredictionLegacy())['current_request_anchor']==='journal:150',
        'work cursor incorrectly treated as source information fence');
    semanticPredictionAssert(semanticPredictionThrows(static fn()=>$wide->observeMessage('customer','Подберите тур','journal:201',
        $at,$at+1,1,$wideResult)),'reference beyond information fence accepted');
    semanticPredictionAssert(semanticPredictionThrows(static fn()=>new ModeledConversionSemanticPrediction(semanticPredictionSubject(),
        '2026-11-02T01:00:00Z','2026-11-02T00:00:00Z','2026-11-01T12:00:00Z','100','100')),'backdated freeze accepted');
};


$tests['real freeze connects admitted offer events without losing the current request']=static function():void{
    $parts=semanticPredictionOfferFixture('Fixture Beach A дорого, найдите дешевле с таким же пляжем',[],
        'Подберите тур в Турцию на 7 ночей.');
    $freeze=strtotime($parts[2]['requested_at'])+7;
    $p=ModeledConversionEarlyForecast::freeze(...[...$parts,null,$freeze]);
    $s=$p['semantic_prediction'];
    semanticPredictionAssert($s['stage']==='exact_offer_discussion'&&$s['base_probability']===0.166667
        &&$s['current_request_anchor']==='journal:1','validated offer receipts broke predictor context');
    semanticPredictionAssert($s['prediction_at']===gmdate('Y-m-d\TH:i:s\Z',$freeze)
        &&$s['input_cutoff_at']===$parts[2]['requested_at']&&$s['prediction_at']>$s['input_cutoff_at'],
        'actual freeze lost');
    semanticPredictionAssert($p['early_forecast']['purchase_anywhere']['probability']===null
        &&$p['early_forecast']['purchase_with_us']['probability']===null
        &&$p['early_forecast']['expected_value_rub']===null&&!$p['early_forecast']['export_allowed'],
        'legacy frozen fields changed');
    foreach(['Да','Если выберем, давайте бронировать.','Где офис? Как можно оплатить?']as$reaction){
        $parts=semanticPredictionOfferFixture($reaction,[],'Подберите тур в Турцию на 7 ночей.');
        $p=ModeledConversionEarlyForecast::freeze(...[...$parts,null,strtotime($parts[2]['requested_at'])+7]);
        semanticPredictionAssert($p['semantic_prediction']['stage']==='concrete_request',
            'short acknowledgment, hypothetical or logistics promoted');
    }
};
$tests['real freeze does not bridge unknown events or a receipt long gap']=static function():void{
    $parts=semanticPredictionOfferFixture('Fixture Beach A дорого, найдите дешевле',[
        ['text'=>'source unavailable','admitted'=>false]],'Подберите тур в Турцию на 7 ночей.');
    $p=ModeledConversionEarlyForecast::freeze(...[...$parts,null,strtotime($parts[2]['requested_at'])+7]);
    semanticPredictionAssert($p['semantic_prediction']['estimated_probability']===null
        &&$p['semantic_prediction']['current_request_anchor']===null,'unknown content bridged request');
    $parts=semanticPredictionOfferMoveTimes(semanticPredictionOfferFixture(
        'Fixture Beach A дорого, найдите дешевле',[],'Подберите тур в Турцию на 7 ночей.'),
        [2=>2678400,3=>2678400,4=>2678400]);
    $p=ModeledConversionEarlyForecast::freeze(...[...$parts,null,strtotime($parts[2]['requested_at'])+7]);
    semanticPredictionAssert($p['semantic_prediction']['estimated_probability']===null,'receipt gap kept stale request');
};
$tests['real freeze excludes only strictly scoped canonical closed CRM evidence']=static function():void{
    $parts=semanticPredictionFreezeFixture([['text'=>'Подберите тур в Турцию на 7 ночей.'],
        ['role'=>'manager','text'=>'event receipt','admitted'=>false]]);
    $subject=$parts[0]['subject'];$event=null;
    foreach($parts[0]['rows']as&$row)if($row['journal_id']==='2'){
        $at=$row['snapshot']['source_created_at_unix'];
        $payload=['version'=>1,'origin'=>'existing_sales_pipeline','producer'=>'sales_pipeline_manager_assertion',
            'evidence_basis'=>'manager_operational_assertion','scope'=>$subject,'outcome'=>'won','actor_role'=>'manager',
            'operation_id'=>str_repeat('a',32),'inquiry_binding'=>null,'profile_id'=>null,'episode_key'=>null,
            'previous_outcome'=>null,'_recorded_clock'=>['version'=>1,'basis'=>'server_recording_unix','unix'=>$at]];
        $row['source_kind']=$row['snapshot']['source_kind']='event';
        $row['snapshot']['source_timestamp_basis']='canonical_recording_unix_v1';
        $row['snapshot']['payload']=['event_type'=>'sales_pipeline_outcome_observed',
            'actor_type'=>'manager','payload_json'=>json_encode($payload,JSON_THROW_ON_ERROR)];
        $event=$row;
    }
    unset($row);
    $p=ModeledConversionEarlyForecast::freeze(...[...$parts,null,strtotime($parts[2]['requested_at'])+7]);
    semanticPredictionAssert($p['semantic_prediction']['status']==='known_outcome'
        &&$p['semantic_prediction']['estimated_probability']===null
        &&$p['semantic_prediction']['current_request_anchor']==='journal:1'
        &&$p['semantic_prediction']['outcome_observations'][0]['kind']==='known_operational_outcome',
        'known CRM did not suppress new probability or destroyed followup anchor');
    foreach(['edited','wrong_scope','not_closed']as$kind){
        $changed=$parts;
        foreach($changed[0]['rows']as&$row)if($row['journal_id']==='2'){
            if($kind==='edited')$row['source_version_no']=2;
            else{$data=json_decode($row['snapshot']['payload']['payload_json'],true,32,JSON_THROW_ON_ERROR);
                if($kind==='wrong_scope')$data['scope']['customer_key']='88';
                else$data['outcome']='open';
                $row['snapshot']['payload']['payload_json']=json_encode($data,JSON_THROW_ON_ERROR);}
        }
        unset($row);
        $p=ModeledConversionEarlyForecast::freeze(...[...$changed,null,strtotime($changed[2]['requested_at'])+7]);
        semanticPredictionAssert($p['semantic_prediction']['status']==='insufficient_context'
            &&$p['semantic_prediction']['outcome_observations']===[],'untrusted CRM receipt treated as known');
    }
};


$tests['parallel payment comparison uses only unchanged strictly future evidence']=static function():void{
require_once dirname(__DIR__).'/services/ModeledConversionPredictionFollowup.php';
$followupCheck=static function(bool $ok,string $name):void{
    if(!$ok)throw new RuntimeException('prediction_followup_'.$name);
};
$followupCanonical=function(array $v)use(&$followupCanonical):array{
    if(!array_is_list($v))ksort($v,SORT_STRING);
    foreach($v as$k=>$item)if(is_array($item))$v[$k]=$followupCanonical($item);
    return $v;
};
$followupSign=static function(array $p)use($followupCanonical):array{
    unset($p['prediction_digest']);
    $p['prediction_digest']=hash('sha256',json_encode($followupCanonical($p),
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    return $p;
};
$followupPrior=$followupSign([
    'version'=>'semantic_purchase_heuristic_v1',
    'subject'=>['project_key'=>'anytour','platform'=>'max','customer_key'=>'101','conversation_id'=>'201'],
    'current_request_anchor'=>'journal:10','anchor_source_version_no'=>1,
    'status'=>'predicted','input_cutoff_at'=>'2026-10-01T10:00:00Z',
    'prediction_at'=>'2026-10-01T10:00:10Z','frozen_at'=>'2026-10-01T10:00:10Z',
    'source_fence_journal_id'=>'20','journal_cursor'=>'20',
    'estimated_probability'=>0.42,'outcome_observations'=>[],
]);
$followupCurrent=$followupPrior;
$followupCurrent['status']='known_outcome';$followupCurrent['estimated_probability']=null;
$followupCurrent['input_cutoff_at']='2026-10-01T11:00:00Z';
$followupCurrent['prediction_at']=$followupCurrent['frozen_at']='2026-10-01T11:00:10Z';
$followupCurrent['source_fence_journal_id']=$followupCurrent['journal_cursor']='30';
$followupCurrent['outcome_observations']=[
    ['kind'=>'payment_self_report','source_ref'=>'journal:21',
        'occurred_at'=>'2026-10-01T10:10:00Z','available_at'=>'2026-10-01T10:10:01Z','source_version_no'=>1],
    ['kind'=>'manager_payment_ack','source_ref'=>'journal:22',
        'occurred_at'=>'2026-10-01T10:20:00Z','available_at'=>'2026-10-01T10:20:01Z','source_version_no'=>1],
];
$followupCurrent=$followupSign($followupCurrent);
$followupBefore=serialize($followupPrior);
$followupResult=ModeledConversionPredictionFollowup::compare($followupCurrent,$followupPrior);
$followupCheck($followupResult['status']==='observed'
    &&array_column($followupResult['evidence'],'label')===['self_reported_payment','manager_confirmed_payment']
    &&$followupResult['prior_prediction_digest']===$followupPrior['prediction_digest']
    &&$followupResult['prior_prediction_at']===$followupPrior['prediction_at']
    &&$followupResult['prior_estimated_probability']===0.42
    &&$followupResult['observed_at']===$followupCurrent['frozen_at']
    &&$followupResult['payment_label']===null
    &&$followupResult['evaluation_eligible']===false&&$followupResult['export_allowed']===false
    &&serialize($followupPrior)===$followupBefore,'future_pair_and_immutable_prior');
$followupUnknown=static function(array $current,string $reason)use(
    $followupSign,$followupPrior,$followupCheck
):void{
    $r=ModeledConversionPredictionFollowup::compare($followupSign($current),$followupPrior);
    $followupCheck($r['status']==='unknown'&&$r['reason']===$reason
        &&$r['payment_label']===null&&$r['evidence']===[]
        &&$r['calibrated_purchase_probability']===null
        &&$r['evaluation_eligible']===false&&$r['export_allowed']===false,$reason);
};
$c=$followupCurrent;$c['subject']['customer_key']='102';$followupUnknown($c,'scope_mismatch');
$c=$followupCurrent;$c['current_request_anchor']='journal:11';$followupUnknown($c,'request_anchor_unmatched');
$c=$followupCurrent;$c['current_request_anchor']=null;$c['anchor_source_version_no']=null;
$followupUnknown($c,'request_anchor_unmatched');
$c=$followupCurrent;$c['anchor_source_version_no']=2;$followupUnknown($c,'edited_request_anchor');
foreach([
    ['2026-10-01T09:59:59Z','2026-10-01T10:20:00Z'], // Late-ingested old message.
    ['2026-10-01T10:00:10Z','2026-10-01T10:00:10Z'], // Same second as actual freeze.
    ['2026-10-01T10:00:05Z','2026-10-01T10:00:06Z'], // After input cutoff, before actual freeze.
]as[$occurred,$available]){
    $c=$followupCurrent;$c['outcome_observations']=[$c['outcome_observations'][0]];
    $c['outcome_observations'][0]['occurred_at']=$occurred;
    $c['outcome_observations'][0]['available_at']=$available;
    $followupUnknown($c,'outcome_not_strictly_after_freeze');
}
$c=$followupCurrent;$c['outcome_observations'][0]['source_version_no']=2;
$followupUnknown($c,'edited_outcome_source');
$c=$followupCurrent;$c['outcome_observations'][0]['source_ref']='journal:20';
$followupUnknown($c,'outcome_source_not_after_fence');
$c=$followupCurrent;$c['outcome_observations'][0]['available_at']='2026-10-01T11:00:05Z';
$followupUnknown($c,'outcome_not_available_at_cutoff');
foreach(['booking_confirmation','known_operational_outcome','document_issuance']as$kind){
    $c=$followupCurrent;$c['outcome_observations']=[$c['outcome_observations'][0]];
    $c['outcome_observations'][0]['kind']=$kind;$followupUnknown($c,'non_payment_outcome_only');
}
$c=$followupCurrent;$docs=$c['outcome_observations'][1];$docs['kind']='document_issuance';
$c['outcome_observations'][]=$docs;
$r=ModeledConversionPredictionFollowup::compare($followupSign($c),$followupPrior);
$followupCheck($r['status']==='observed'&&count($r['evidence'])===2,'docs_do_not_invalidate_payment');
$c=$followupCurrent;$c['outcome_observations'][1]['kind']='refund_report';
$followupUnknown($c,'contrary_signal_after_prediction');
$c=$followupCurrent;$c['outcome_observations']=[];$followupUnknown($c,'no_eligible_payment_evidence');
$c['input_cutoff_at']='2026-11-01T11:00:00Z';
$c['prediction_at']=$c['frozen_at']='2026-11-01T11:00:10Z';
$followupUnknown($c,'horizon_elapsed_coverage_unknown');
$c['outcome_observations']=[$followupCurrent['outcome_observations'][0]];
$c['outcome_observations'][0]['occurred_at']='2026-10-31T10:00:10Z';
$c['outcome_observations'][0]['available_at']='2026-10-31T10:00:10Z';
$followupUnknown($c,'outcome_outside_horizon');
$badPrior=$followupPrior;$badPrior['estimated_probability']=0.99;
$r=ModeledConversionPredictionFollowup::compare($followupCurrent,$badPrior);
$followupCheck($r['status']==='unknown'&&$r['reason']==='invalid_prior'
    &&$r['prior_prediction_digest']===null&&$r['payment_label']===null,'digest_mismatch');
$reordered=array_reverse($followupPrior,true);$reordered['subject']=array_reverse($reordered['subject'],true);
$r=ModeledConversionPredictionFollowup::compare($followupCurrent,$reordered);
$followupCheck($r['status']==='observed','mysql_object_key_reordering');
$r=ModeledConversionPredictionFollowup::compare($followupCurrent,null);
$followupCheck($r['status']==='waiting'&&$r['reason']==='no_earlier_prediction'
    &&$r['payment_label']===null&&$r['evidence']===[],'no_invented_negative');

$documentPrior=$followupPrior;
$documentPrior['outcome_observations']=[[
    'kind'=>'document_issuance','source_ref'=>'journal:19',
    'occurred_at'=>'2026-10-01T09:50:00Z','available_at'=>'2026-10-01T09:50:01Z',
    'source_version_no'=>1,
]];
$documentPrior=$followupSign($documentPrior);$documentPriorBefore=serialize($documentPrior);
$r=ModeledConversionPredictionFollowup::compare($followupCurrent,$documentPrior);
$followupCheck($r['status']==='observed'
    &&$r['prior_prediction_digest']===$documentPrior['prediction_digest']
    &&$r['prior_estimated_probability']===0.42
    &&serialize($documentPrior)===$documentPriorBefore,'document_only_prior_eligible');
foreach(['payment_self_report','manager_payment_ack','booking_confirmation',
    'purchase_supported_candidate','payment_cancellation','booking_cancellation',
    'refund_report','purchased_elsewhere','known_operational_outcome']as$kind){
    $outcomePrior=$documentPrior;$outcomePrior['outcome_observations'][0]['kind']=$kind;
    $outcomePrior=$followupSign($outcomePrior);
    $r=ModeledConversionPredictionFollowup::compare($followupCurrent,$outcomePrior);
    $followupCheck($r['status']==='unknown'&&$r['reason']==='invalid_prior'
        &&$r['payment_label']===null&&$r['evidence']===[],'known_outcome_prior_'.$kind);
}

};

$failures=0;
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{
    throw new ErrorException($message,0,$severity,$file,$line);
});
foreach($tests as$name=>$test){
    try{$test();echo "PASS ".$name.PHP_EOL;}catch(Throwable $e){$failures++;fwrite(STDERR,"FAIL ".$name.": ".$e->getMessage().PHP_EOL);}
}
restore_error_handler();
if($failures>0)exit(1);
echo 'Modeled semantic prediction regression: '.count($tests).' passed'.PHP_EOL;
