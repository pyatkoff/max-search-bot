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

function semanticPredictionFreezeFixture(array $messages,string $platform='telegram'):array
{
    $now=1793002000;$subject=['project_key'=>'anytour','platform'=>$platform,'customer_key'=>'7','conversation_id'=>'9'];
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

/** Exact legacy V1 producer codec, retained only to check immutable compatibility. */
function semanticPredictionLegacyDigest(array $value):string
{
    unset($value['prediction_digest']);
    $sort=static function(array $v)use(&$sort):array{
        if(!array_is_list($v))ksort($v,SORT_STRING);
        foreach($v as&$item)if(is_array($item))$item=$sort($item);return$v;
    };
    return hash('sha256',json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
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
$tests['hotel star ranges preserve independent calendar evidence and genuine hotel interest']=static function():void{
    require_once dirname(__DIR__).'/services/ModeledConversionValueContract.php';
    foreach(['И 3/4 звезд?','Нужно 1/2 звезды','Есть варианты 3/4 звезды?','Только 4/5*','Категория 3/4★','Отель 3* или 4/5*','**Категория 3/4** звезды','Подойдёт 4/5⭐️','Категория 3 / 4 звёзды']as$text){
        semanticPredictionAssert(ModeledConversionTextSignals::criteriaFamilies($text)===['requirements'],
            'hotel star range supplied a calendar criterion');
    }
    foreach(['Вылет **4/5**','Вылет *4/5*','**Вылет 4/5**']as$text){
        semanticPredictionAssert(ModeledConversionTextSignals::criteriaFamilies($text)===['dates'],
            'paired emphasis hid a calendar date or introduced a hotel category');
    }
    foreach(['Категория 3/4 звезды, заезд 11/12/2027.','Заезд 3/4, отель 3/4 звезды',
        'На 8 ночей нужен отель 4/5*','Категория 3/4 звезды, вылет с 09.12 по 16.12.']as$text){
        semanticPredictionAssert(ModeledConversionTextSignals::criteriaFamilies($text)===['dates','requirements'],
            'rating masked a separate date or duration in the same turn');
    }
    $freeze=static function(array $messages):array{
        [$case,$w,$doc]=semanticPredictionFreezeFixture($messages,'max');
        $clock=strtotime($doc['requested_at']);$w+=ModeledConversionEarlyForecast::freeze($case,$w,$doc,null,$clock,null,true);
        $w['deal_value_prediction']=ModeledConversionDealValue::freeze($w,$case);
        return [$w,ModeledConversionValueContract::candidate($w,$w['subject'],$clock-3600,$clock)];
    };
    [$w,$candidate]=$freeze([['text'=>'И 3/4 звезд?']]);
    semanticPredictionAssert($w['semantic_prediction']['current_request_anchor']===null&&$candidate===null,
        'bare rating question created a standalone financial candidate');
    [$w,$candidate]=$freeze([['text'=>'Подберите отель 3/4 звезды.']]);
    semanticPredictionAssert($w['semantic_prediction']['stage']==='substantive_dialogue'
        &&$w['semantic_prediction']['features']['criteria_families']===['requirements']
        &&($candidate['stage']??null)==='interest'&&$candidate['value']===500,
        'explicit hotel interest was lost or promoted to a two-criterion financial request');

    [$w]=$freeze([['text'=>'Подберите тур с 10.03 по 19.03.'],['text'=>'Покажете варианты 3/4 звезды?']]);
    $p=$w['semantic_prediction'];$refs=array_column($p['evidence'],'source_ref','feature');
    semanticPredictionAssert($p['current_request_anchor']==='journal:1'&&$p['stage']==='concrete_request'
        &&$p['features']['criteria_families']===['dates','requirements']
        &&($refs['criteria_dates']??null)==='journal:1'&&($refs['criteria_requirements']??null)==='journal:2',
        'later hotel rating replaced the evidence for an independently supplied travel period');
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
$tests['timing signals remain observations without modifying the stage prior']=static function():void{
    $neutral=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей.']]);
    $urgent=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей, срочно, вылет завтра.']]);
    $far=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей на следующий год.']]);
    semanticPredictionAssert($urgent['features']['near_term_urgency']&&$far['features']['long_range_planning'],
        'timing observations were discarded');
    foreach([$neutral,$urgent,$far]as$p)semanticPredictionAssert($p['stage']==='concrete_request'
        &&$p['base_probability']===0.083333&&$p['estimated_probability']===0.083333
        &&$p['hypothesis_odds_multiplier']===1.0&&$p['mode']==='contextual_stage_baseline'
        &&$p['assumptions_version']===ModeledConversionSemanticPrediction::STAGE_BASELINE_ASSUMPTIONS,
        'timing changed stage-only score');
};
$tests['deferral is descriptive and direct refusal retains its explicit guard']=static function():void{
    $request=['text'=>'Подберите тур в Турцию на 7 ночей.'];
    $normal=semanticPredictionRun([$request]);
    $later=semanticPredictionRun([$request,['text'=>'Подумаем, обсудим с мужем позже.']]);
    semanticPredictionAssert($later['features']['deferral']&&$later['estimated_probability']===$normal['estimated_probability']
        &&$later['hypothesis_odds_multiplier']===1.0&&!in_array('hypothesis_deferral',$later['reasons'],true),
        'deferral still penalized the same stage');
    $declined=semanticPredictionRun([$request,['text'=>'Тур нам не нужен.']]);
    semanticPredictionAssert($declined['features']['explicit_decline']
        &&$declined['estimated_probability']<$normal['estimated_probability']&&$declined['hypothesis_odds_multiplier']===0.25
        &&in_array('explicit_decline_guard',$declined['reasons'],true),'direct-refusal guard lost');
    $renewed=semanticPredictionRun([$request,['text'=>'Подумаем позже.'],['text'=>'Купим тур только у вас.']]);
    semanticPredictionAssert(!$renewed['features']['deferral']&&$renewed['stage']==='agency_purchase_intent'
        &&$renewed['estimated_probability']===0.5,'renewed intent stayed deferred');
};
$tests['unweighted signals and their source evidence survive a stage-only freeze']=static function():void{
    $p=semanticPredictionRun([
        ['text'=>'Подберите тур в Турцию на 7 ночей на следующий год.'],
        ['text'=>'Сравните другие варианты.'],['text'=>'Подумаем, обсудим позже.'],
        ['text'=>'Где находится ваш офис?'],['text'=>'Можно оплатить картой?']]);
    semanticPredictionAssert($p['stage']==='concrete_request'&&$p['base_probability']===0.083333
        &&$p['estimated_probability']===0.083333&&$p['hypothesis_odds_multiplier']===1.0,
        'descriptive signals changed score');
    semanticPredictionAssert($p['features']['long_range_planning']&&$p['features']['deferral']
        &&$p['features']['meaningful_customer_turns']>=2&&$p['features']['explicit_comparison']
        &&$p['features']['office_question']&&$p['features']['payment_terms_question'],'research flags lost');
    $kinds=array_column($p['evidence'],'feature');
    foreach(['long_range_planning','deferral','explicit_comparison','office_question','payment_terms_question']as$kind)
        semanticPredictionAssert(in_array($kind,$kinds,true),'missing descriptive evidence '.$kind);
    $wrapper=['subject'=>$p['subject'],'access_status'=>'allowed','profile'=>['prediction_at'=>$p['profile_prediction_at']],
        'semantic_prediction'=>$p];
    $read=ModeledConversionValueRegistry::predictionFields($wrapper);
    semanticPredictionAssert($read['semantic_prediction']['availability']==='available'
        &&$read['semantic_prediction']['value']['features']===$p['features'],'stage policy did not read back');
    foreach([['hypothesis_odds_multiplier'=>0.5],['estimated_probability'=>0.043478],['reasons'=>['hypothesis_deferral']]]as$bad){
        $changed=$wrapper;$changed['semantic_prediction']=array_replace($p,$bad);
        $changed['semantic_prediction']['prediction_digest']=ModeledConversionSemanticPrediction::digest($changed['semantic_prediction']);
        semanticPredictionAssert(ModeledConversionValueRegistry::predictionFields($changed)['semantic_prediction']['availability']==='unavailable',
            'resigned inconsistent stage packet accepted');
    }
};
$tests['retained legacy penalties read unchanged under the new stage policy']=static function():void{
    $current=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей на следующий год.'],
        ['text'=>'Подумаем позже.']]);
    $old=$current;
    $old['mode']='contextual_rules_untrained';
    $old['model_sha256']=str_repeat('a',64);
    $old['assumptions_version']='illustrative_funnel_2026_11_02_v1';
    $old['probability_meaning']='illustrative_semantic_hypothesis_not_observed_payment';
    unset($old['features']['office_question'],$old['features']['payment_terms_question']);
    $old['hypothesis_odds_multiplier']=0.325;
    $old['estimated_probability']=0.028697;
    $old['reasons']=['illustrative_prior_concrete_request','hypothesis_long_range_planning','hypothesis_deferral'];
    $old['prediction_digest']=ModeledConversionSemanticPrediction::digest($old);
    $before=json_encode($old,JSON_THROW_ON_ERROR);
    $wrapper=['subject'=>$old['subject'],'access_status'=>'allowed','profile'=>['prediction_at'=>$old['profile_prediction_at']],
        'semantic_prediction'=>$old];
    $read=ModeledConversionValueRegistry::predictionFields($wrapper);
    semanticPredictionAssert($read['semantic_prediction']['availability']==='available'
        &&$read['semantic_prediction']['value']['estimated_probability']===0.028697
        &&$before===json_encode($old,JSON_THROW_ON_ERROR),'legacy snapshot changed or became unreadable');
    semanticPredictionAssert($current['estimated_probability']===0.083333&&$current['hypothesis_odds_multiplier']===1.0
        &&$current['features']['deferral']&&$current['features']['long_range_planning'],'new policy not separated from retained legacy');
};
$tests['meaningful engagement is independent of reply speed']=static function():void{
    $messages=[['text'=>'Подберите тур в Турцию на 7 ночей.'],
        ['role'=>'manager','text'=>'Здравствуйте!','seconds'=>60],
        ['text'=>'Сравните другие варианты.','seconds'=>61]];
    $fast=semanticPredictionRun($messages);$messages[2]['seconds']=3600;$slow=semanticPredictionRun($messages);
    semanticPredictionAssert($fast['estimated_probability']===$slow['estimated_probability']
        &&$fast['features']['meaningful_customer_turns']===2&&$fast['estimated_probability']===0.083333
        &&$fast['hypothesis_odds_multiplier']===1.0,'engagement or reply speed affected probability');
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


$tests['same-conditions price question after original manager package continues advertising search']=static function():void{
    $request=['text'=>'Ищем тур.'];
    $quote=['role'=>'manager','text'=>"06.03, 9н, Example North 4*, HB, 120 000 RUB\nГород A → Город B\n"
        ."09.03, 9н, Example South 4*, HB, 130 000 RUB\nГород A → Город B"];
    $reply=['text'=>'Найдётся что-нибудь в ту же страну на тот же срок дешевле?'];
    $run=static function(array $messages,bool $advertising=true,?int $edited=null):array{
        [$case,$wrapper,$document]=semanticPredictionFreezeFixture($messages,'max');
        foreach($case['rows']as&$row)if((int)$row['journal_id']===$edited)$row['source_version_no']=2;
        unset($row);
        return ModeledConversionEarlyForecast::freeze($case,$wrapper,$document,null,strtotime($document['requested_at'])+1,null,$advertising);
    };
    $before=$run([$request,$quote])['semantic_prediction'];
    $after=$run([$request,$quote,$reply]);
    $p=$after['semantic_prediction'];
    semanticPredictionAssert(!$before['features']['price_alternative_continued_search']&&$p['features']['price_alternative_continued_search']
        &&$p['stage']===$before['stage']&&$p['current_request_anchor']===$before['current_request_anchor']
        &&$p['stage']==='substantive_dialogue'&&$p['estimated_probability']>$before['estimated_probability'],
        'same-term search or existing stage lost');
    semanticPredictionAssert($after['early_forecast']['early_quality']['specific_option_discussions']===[]
        &&$p['outcome_observations']===[]&&in_array('expert_dialogue_continued_offer_search',$p['reasons'],true)
        &&in_array('journal:3',array_column($p['evidence'],'source_ref'),true),'created an exact offer or outcome');
    $second=['text'=>'Можно подобрать предложение туда же на столько же ночей, только бюджетнее?'];
    semanticPredictionAssert($run([$request,$quote,$second])['semantic_prediction']['features']['price_alternative_continued_search'],'alternative question form');
    foreach([
        [$request,$reply],[$request,$quote,['text'=>'Да.']],[$request,$quote,['text'=>'Есть что-нибудь дешевле?']],
        [$request,$quote,['text'=>'Есть туда же вариант дешевле на другой срок?']],
        [$request,$quote,['text'=>'Можно найти билет туда же на тот же срок дешевле?']],
        [$request,$quote,['text'=>'Есть аренда авто туда же на тот же срок дешевле?']],
        [$request,$quote,['text'=>'Есть полис туда же на тот же срок дешевле?']],
        [$request,$quote,['text'=>'Можно подобрать визу туда же на тот же срок дешевле?']],
        [$request,$quote,['text'=>'Найдётся тур туда же на тот же срок у другого агентства дешевле?']],
        [$request,$quote,['text'=>'Если полетим, есть в ту же страну на тот же срок дешевле?']],
        [$request,$quote,['text'=>'«Найдётся в ту же страну на тот же срок дешевле?»']],
        [$request,$quote,['text'=>'Напишите позже.'],$reply],[$request,$quote,['role'=>'manager','text'=>'Сменим тему.'],$reply],
        [$request,['role'=>'manager','text'=>'Только перелёт 45 000 RUB.'], $reply],
        [$request,['text'=>$quote['text']],$reply],[$request,$quote,['role'=>'manager','text'=>$reply['text']]],
        [$request,$quote,['text'=>'unavailable','admitted'=>false],$reply],[$quote,$reply],
    ]as$messages){
        semanticPredictionAssert(!$run($messages)['semantic_prediction']['features']['price_alternative_continued_search'],'unbound price question');
    }
    foreach(['matching','foreign','unknown']as$receipt){
        [$case,$wrapper,$document]=semanticPredictionFreezeFixture([$request,$quote,['text'=>'receipt','admitted'=>false],$reply],'max');
        foreach($case['rows']as&$row){
            if($row['journal_id']!=='3')continue;
            $row['source_kind']=$row['snapshot']['source_kind']='event';
            $snapshot=&$row['snapshot'];
            $snapshot['source_timestamp_basis']='canonical_recording_unix_v1';
            if($receipt==='foreign')$snapshot['conversation_id']='22';
            $payload=ConversationRecordClock::stamp(['project_key'=>'anytour','channel'=>'max'],$snapshot['source_created_at_unix']);
            $snapshot['payload']=['event_type'=>$receipt==='unknown'?'unrecognized_receipt':'manager_message',
                'actor_type'=>'manager','payload_json'=>json_encode($payload,JSON_THROW_ON_ERROR)];
            unset($snapshot);
        }
        unset($row);
        $result=ModeledConversionEarlyForecast::freeze($case,$wrapper,$document,null,strtotime($document['requested_at'])+1,null,true);
        $signal=$result['semantic_prediction'];
        semanticPredictionAssert($signal['features']['price_alternative_continued_search']===($receipt==='matching'),'package receipt scope: '.$receipt);
        if($receipt==='matching'){
            semanticPredictionAssert($signal['current_request_anchor']==='journal:1'
                &&in_array('journal:4',array_column($signal['evidence'],'source_ref'),true),'service receipt changed customer evidence');
        }
    }
    foreach([2,3]as$linked){
        foreach(['{"type":"message","linked_message":{"type":"reply"}}','invalid']as$raw){
            [$case,$wrapper,$document]=semanticPredictionFreezeFixture([$request,$quote,$reply],'max');
            foreach($case['rows']as&$row)if((int)$row['journal_id']===$linked)$row['snapshot']['payload']['metadata_json']=$raw;
            unset($row);
            $result=ModeledConversionEarlyForecast::freeze($case,$wrapper,$document,null,strtotime($document['requested_at'])+1,null,true);
            semanticPredictionAssert(!$result['semantic_prediction']['features']['price_alternative_continued_search'],'linked or opaque source metadata');
        }
    }
    foreach([-10,86401]as$shift){
        $parts=semanticPredictionFreezeFixture([$request,$quote,$reply],'max');
        $parts[1]['offer_journey']=['offers'=>[]];
        [$case,$wrapper,$document]=semanticPredictionOfferMoveTimes($parts,[3=>$shift]);
        $result=ModeledConversionEarlyForecast::freeze($case,$wrapper,$document,null,strtotime($document['requested_at'])+1,null,true);
        semanticPredictionAssert(!$result['semantic_prediction']['features']['price_alternative_continued_search'],'ambiguous or stale quote time');
    }
    foreach([2,3]as$edited){
        semanticPredictionAssert(!$run([$request,$quote,$reply],true,$edited)['semantic_prediction']['features']['price_alternative_continued_search'],'edited source');
    }
    semanticPredictionAssert(!$run([$request,$quote,$reply],false)['semantic_prediction']['features']['price_alternative_continued_search'],'changed baseline model');
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


$tests['canonical digest survives independent CLI and web float settings']=static function():void{
    $old=(string)ini_get('serialize_precision');
    try{
        $p=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей.']]);
        $before=$p;$given=$p['prediction_digest'];
        ini_set('serialize_precision','14');$legacy14=semanticPredictionLegacyDigest($p);
        ini_set('serialize_precision','17');$legacy17=semanticPredictionLegacyDigest($p);
        semanticPredictionAssert($legacy14!==$legacy17,'legacy precision failure was not reproduced');
        foreach(['-1','14','17']as$writer){
            ini_set('serialize_precision',$writer);
            $encoded=json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $copy=json_decode($encoded,true,32,JSON_THROW_ON_ERROR);
            foreach(['-1','14','17']as$reader){
                ini_set('serialize_precision',$reader);$setting=ini_get('serialize_precision');
                semanticPredictionAssert(ModeledConversionSemanticPrediction::digest($copy)===$given
                    &&ModeledConversionSemanticPrediction::digestMatches($copy),'cross-runtime digest changed '.$writer.' '.$reader);
                semanticPredictionAssert(ini_get('serialize_precision')===$setting,'digest mutated runtime setting');
            }
        }
        semanticPredictionAssert($p===$before,'digest mutated frozen prediction');
    }finally{ini_set('serialize_precision',$old);}
};
$tests['legacy immutable numeric digests remain valid without current-code downgrade']=static function():void{
    $old=(string)ini_get('serialize_precision');
    try{
        $p=semanticPredictionRun([['text'=>'Подберите тур в Турцию на 7 ночей.']]);
        foreach(['-1','14','17']as$mode){
            ini_set('serialize_precision',$mode);
            $legacy=$p;$legacy['model_sha256']=str_repeat('a',64);
            $legacy['prediction_digest']=semanticPredictionLegacyDigest($legacy);$before=$legacy;
            ini_set('serialize_precision',$mode==='17'?'14':'17');
            semanticPredictionAssert(ModeledConversionSemanticPrediction::digestMatches($legacy),'old frozen numeric digest rejected');
            semanticPredictionAssert($legacy===$before,'legacy digest rewritten');
            $legacy['estimated_probability']+=0.00000001;
            semanticPredictionAssert(!ModeledConversionSemanticPrediction::digestMatches($legacy),'small legacy tamper was rounded away');
        }
        ini_set('serialize_precision','14');
        $current=$p;$current['prediction_digest']=semanticPredictionLegacyDigest($current);
        semanticPredictionAssert(!ModeledConversionSemanticPrediction::digestMatches($current),'current model accepted legacy codec downgrade');
        $previous=$p;$previous['model_sha256']='4e4d7c6908bf8246db08917de1b46dbdc904099b0c95afb92273f833b8c3ab42';
        $previous['prediction_digest']=ModeledConversionSemanticPrediction::digest($previous);
        semanticPredictionAssert(ModeledConversionSemanticPrediction::digestMatches($previous),'previous installed canonical profile lost');
        $previous['prediction_digest']=semanticPredictionLegacyDigest($previous);
        semanticPredictionAssert(!ModeledConversionSemanticPrediction::digestMatches($previous),'previous installed producer admitted an unsupported codec');
        $changed=$p;$changed['estimated_probability']+=0.00000001;
        semanticPredictionAssert(!ModeledConversionSemanticPrediction::digestMatches($changed)
            &&ModeledConversionSemanticPrediction::digest($changed)!==$p['prediction_digest'],'small current tamper lost by canonical codec');
    }finally{ini_set('serialize_precision',$old);}
};
$tests['retained contrary observations cannot produce a block rejected by its own outcome contract']=static function():void{
    $base=strtotime('2026-11-01T00:00:00Z');
    foreach(['refund_report','payment_cancellation','booking_cancellation','purchased_elsewhere']as$kind){
        $m=semanticPredictionNew();$state=ArchiveResearchSemanticExtractor::initialState();
        $r=semanticPredictionResult('Подберите тур в Турцию на 7 ночей.','customer','journal:1',$base,$state);
        $m->observeMessage('customer','Подберите тур в Турцию на 7 ночей.','journal:1',$base,$base+1,1,$r);
        $r=['version'=>'ru_archive_contextual_v2','status'=>'scanned','flags'=>[],
            'events'=>[['source_ref'=>'journal:2','goal'=>$kind]]];
        $m->observeMessage($kind==='purchased_elsewhere'?'customer':'manager','Synthetic direct outcome',
            'journal:2',$base+10,$base+11,1,$r);
        $m->barrier();$state=ArchiveResearchSemanticExtractor::initialState();
        $r=semanticPredictionResult('Подберите тур в Египет на 10 ночей.','customer','journal:3',$base+20,$state);
        $m->observeMessage('customer','Подберите тур в Египет на 10 ночей.','journal:3',$base+20,$base+21,1,$r);
        $v=$m->finish(semanticPredictionLegacy());
        semanticPredictionAssert($v['status']==='known_outcome'&&$v['estimated_probability']===null
            &&$v['outcome_observations'][0]['kind']===$kind
            &&ModeledConversionSemanticPrediction::digestMatches($v),'retained outcome manufactured invalid early percentage '.$kind);
    }
};

require_once __DIR__.'/support/archive-predictive-model.php';
$tests['archive-derived atoms private files and frozen relative weights']=static function():void{
    $n=archivePredictiveModelRegression();
    semanticPredictionAssert($n>=30,'archive model support checks omitted');
};
$tests['historical prefix study leakage and model comparison']=static function():void{
    $command=['python3',__DIR__.'/run_archive_predictive_study_regression.py'];
    $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    semanticPredictionAssert(is_resource($process),'study regression process unavailable');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    semanticPredictionAssert($exit===0,'archive study regression failed '.substr($out.$err,-4000));
};

require_once dirname(__DIR__).'/services/ModeledConversionDealValue.php';
$tests['early advertising hypothesis uses meaning without timing or deferral penalties']=static function():void{
    $make=static function(array $messages):array{
        [$case,$w,$doc]=semanticPredictionFreezeFixture($messages);
        $packet=ModeledConversionEarlyForecast::freeze($case,$w,$doc,null,strtotime($doc['requested_at']),null,true);
        return array_replace($w,$packet);
    };
    $base=$make([['text'=>'Подберите тур в Турцию на 7 ночей.']]);
    $office=$make([['text'=>'Подберите тур в Турцию на 7 ночей.'],['text'=>'Где находится ваш офис?']]);
    $later=$make([['text'=>'Подберите тур в Турцию на 7 ночей на следующий год.'],['text'=>'Подумаем, обсудим позже.']]);
    semanticPredictionAssert($base['semantic_prediction']['mode']==='contextual_advertising_v1'
        &&$office['semantic_prediction']['estimated_probability']>$base['semantic_prediction']['estimated_probability']
        &&$later['semantic_prediction']['estimated_probability']===$base['semantic_prediction']['estimated_probability'],'meaning or neutral timing policy');
    foreach([$base,$office,$later]as$w)semanticPredictionAssert(
        ModeledConversionValueRegistry::predictionFields($w)['semantic_prediction']['availability']==='available','expert frozen packet readback');
    $bad=$office;$bad['semantic_prediction']['estimated_probability']=0.9;
    $bad['semantic_prediction']['prediction_digest']=ModeledConversionSemanticPrediction::digest($bad['semantic_prediction']);
    semanticPredictionAssert(ModeledConversionValueRegistry::predictionFields($bad)['semantic_prediction']['availability']==='unavailable','inconsistent signed percentage accepted');
};
$tests['deal value uses seven percent commission and immutable budget evidence']=static function():void{
    [$case,$w,$doc]=semanticPredictionFreezeFixture([['text'=>'Подберите тур в Турцию на 7 ночей. Бюджет 200 тысяч на всех.']]);
    $packet=ModeledConversionEarlyForecast::freeze($case,$w,$doc,null,strtotime($doc['requested_at']),null,true);$w=array_replace($w,$packet);
    $r=$w['profile']['observation_input']['evidence'][0]['references'][0];
    $w['profile']['fields']['budget']=['status'=>'known','usable_at_prediction'=>true,'available_at'=>$w['profile']['prediction_at'],
        'value'=>['minimum'=>200000,'maximum'=>200000,'currency'=>'RUB','unit'=>'party_total'],'references'=>[$r]];
    $w['deal_value_prediction']=ModeledConversionDealValue::freeze($w,$case);
    $v=$w['deal_value_prediction'];
    semanticPredictionAssert($v['version']==='early_deal_commission_7pct_v3'
        &&$v['rules_sha256']===hash_file('sha256',dirname(__DIR__).'/services/ModeledConversionDealValue.php')
        &&$v['deal_amount_rub']['point']===200000&&$v['commission_estimate_rub']===14000
        &&$v['full_value_base_rub']===14000&&$v['value_rub']===1167&&$v['early_window']==='first_hour'
        &&ModeledConversionDealValue::saved($w)!==null,'seven percent budget value or readback');
    $before=json_encode($w,JSON_THROW_ON_ERROR);
    ModeledConversionDealValue::saved($w);
    semanticPredictionAssert(json_encode($w,JSON_THROW_ON_ERROR)===$before,'readback mutates forecast');
    $bad=$w;$bad['deal_value_prediction']['commission_rate']=0.1;
    unset($bad['deal_value_prediction']['deal_digest']);
    $bad['deal_value_prediction']['deal_digest']=ModeledConversionSemanticPrediction::digest($bad['deal_value_prediction']);
    semanticPredictionAssert(ModeledConversionDealValue::saved($bad)===null,'unapproved commission accepted');
    $unknown=$w;unset($unknown['profile']['fields']);$unknown['deal_value_prediction']=ModeledConversionDealValue::freeze($unknown,$case);
    semanticPredictionAssert($unknown['deal_value_prediction']['deal_amount_rub']['point']===null
        &&$unknown['deal_value_prediction']['commission_estimate_rub']===null
        &&$unknown['deal_value_prediction']['full_value_base_rub']===10000
        &&$unknown['deal_value_prediction']['base_basis']==='provisional_commission_10000'
        &&$unknown['deal_value_prediction']['reason']==='deal_price_unknown_provisional_base'
        &&$unknown['deal_value_prediction']['value_rub']===833&&ModeledConversionDealValue::saved($unknown)!==null,
        'unknown price must use probability times the provisional commission base');
    $foreign=$w;$foreign['profile']['fields']['budget']['value']['currency']='USD';
    $foreign['deal_value_prediction']=ModeledConversionDealValue::freeze($foreign,$case);
    semanticPredictionAssert($foreign['deal_value_prediction']['deal_amount_rub']['point']===null,'unverified FX conversion');
};
$tests['frozen monetary V1 keeps its exact rules hash and original arithmetic']=static function():void{
    semanticPredictionAssert(ModeledConversionDealValue::policy('early_deal_commission_7pct_v1')===
        ['cap_rub'=>25000,'unknown_base_rub'=>25000,'unknown_base_basis'=>'existing_25000_fallback']
        &&ModeledConversionDealValue::policy('early_deal_commission_7pct_v2')===
        ['cap_rub'=>100000,'unknown_base_rub'=>10000,'unknown_base_basis'=>'provisional_commission_10000']
        &&ModeledConversionDealValue::policy('early_deal_commission_7pct_v3')===
        ['cap_rub'=>100000,'unknown_base_rub'=>10000,'unknown_base_basis'=>'provisional_commission_10000']
        &&ModeledConversionDealValue::policy('early_deal_commission_7pct_v4')===null,'versioned monetary policies');
    // Synthetic frozen V1 capsules with fixed digests; never rebuild them with a newer producer.
    $legacy=['version'=>'early_deal_commission_7pct_v1',
        'rules_sha256'=>'f4f205793ce51a18f7268292df3e0362b152869d60c71a5bc9c62017ca136593',
        'subject'=>semanticPredictionSubject(),'semantic_prediction_digest'=>str_repeat('a',64),
        'current_request_anchor'=>'journal:1','profile_prediction_at'=>'2026-11-01T00:01:00Z',
        'input_cutoff_at'=>'2026-11-01T00:01:00Z','frozen_at'=>'2026-11-01T00:01:00Z',
        'commission_rate'=>0.07,'currency'=>'RUB','status'=>'estimated','reason'=>'deal_price_unknown_existing_base',
        'early_window'=>'first_hour','age_seconds'=>60,
        'deal_amount_rub'=>['low'=>null,'high'=>null,'point'=>null,'basis'=>'unknown','source_kind'=>'unknown','source_refs'=>[]],
        'commission_estimate_rub'=>null,'full_value_base_rub'=>25000,'base_basis'=>'existing_25000_fallback',
        'estimated_probability'=>0.5,'value_rub'=>12500,'payment_hold_observed'=>false,
        'calibration_status'=>'expert_estimate_not_calibrated','price_verified'=>false,'trip_identity'=>'unverified',
        'export_allowed'=>false,'model_calls'=>0,'conversion_uploads'=>0,
        'deal_digest'=>'673485038df1df4d46f7c644defd3151e1c2c1ef68e6537d4f6f2dd424b6ce88'];
    $known=$legacy;$known['reason']='stage_dialogue_and_estimated_commission';
    $known['deal_amount_rub']=['low'=>1000000,'high'=>1000000,'point'=>1000000,
        'basis'=>'customer_budget_range_proxy','source_kind'=>'customer_budget','source_refs'=>['journal:1']];
    $known['commission_estimate_rub']=70000;$known['base_basis']='estimated_commission_7pct_capped';
    $known['deal_digest']='06742f2e21dc6bb5fa66f5b2e9e90689ac2aee1a7949ef6322a68483c66ca557';
    $wrapper=['access_status'=>'allowed','subject'=>semanticPredictionSubject(),'semantic_prediction'=>[
        'prediction_digest'=>str_repeat('a',64),'current_request_anchor'=>'journal:1',
        'profile_prediction_at'=>'2026-11-01T00:01:00Z','input_cutoff_at'=>'2026-11-01T00:01:00Z',
        'frozen_at'=>'2026-11-01T00:01:00Z','status'=>'predicted','estimated_probability'=>0.5]];
    foreach([$legacy,$known]as$capsule){
        $w=$wrapper+['deal_value_prediction'=>$capsule];$before=json_encode($w,JSON_THROW_ON_ERROR);
        semanticPredictionAssert(ModeledConversionDealValue::saved($w)===$capsule
            &&json_encode($w,JSON_THROW_ON_ERROR)===$before,'frozen V1 changed or became unreadable');
    }
    $reseal=static function(array $v):array{unset($v['deal_digest']);$v['deal_digest']=ModeledConversionSemanticPrediction::digest($v);return$v;};
    $tampered=[];
    foreach([
        ['version'=>'early_deal_commission_7pct_v2'],
        ['rules_sha256'=>hash_file('sha256',dirname(__DIR__).'/services/ModeledConversionDealValue.php')],
        ['rules_sha256'=>str_repeat('b',64)],
        ['version'=>'early_deal_commission_7pct_v3'],
        ['full_value_base_rub'=>10000,'base_basis'=>'provisional_commission_10000','value_rub'=>5000],
    ]as$change)$tampered[]=array_replace($legacy,$change);
    $tampered[]=array_replace($known,['full_value_base_rub'=>70000,'value_rub'=>35000]);
    foreach($tampered as$capsule)semanticPredictionAssert(
        ModeledConversionDealValue::saved($wrapper+['deal_value_prediction'=>$reseal($capsule)])===null,
        'resealed V1 cannot change its version/hash pair or monetary policy');
};
$tests['monetary V3 retains the provisional base and caps known commission without a minimum']=static function():void{
    $make=static function(?int $price):array{
        $text='Подберите тур в Турцию на 7 ночей.'.($price===null?'':' Бюджет '.$price.' рублей на всех.');
        [$case,$w,$doc]=semanticPredictionFreezeFixture([['text'=>$text]]);
        $w=array_replace($w,ModeledConversionEarlyForecast::freeze($case,$w,$doc,null,strtotime($doc['requested_at']),null,true));
        if($price!==null)$w['profile']['fields']['budget']=['status'=>'known','usable_at_prediction'=>true,
            'available_at'=>$w['profile']['prediction_at'],'value'=>[
                'minimum'=>$price,'maximum'=>$price,'currency'=>'RUB','unit'=>'party_total'],
            'references'=>[$w['profile']['observation_input']['evidence'][0]['references'][0]]];
        $w['deal_value_prediction']=ModeledConversionDealValue::freeze($w,$case);return$w;
    };
    $unknown=$make(null);$before=json_encode($unknown,JSON_THROW_ON_ERROR);
    foreach([[100000,7000,7000,583],[200000,14000,14000,1167],
        [1000000,70000,70000,5833],[2000000,140000,100000,8333]]as[$price,$commission,$base,$value]){
        $w=$make($price);$v=$w['deal_value_prediction'];
        semanticPredictionAssert($v['deal_amount_rub']['point']===$price&&$v['commission_estimate_rub']===$commission
            &&$v['full_value_base_rub']===$base&&$v['value_rub']===$value&&ModeledConversionDealValue::saved($w)===$v,
            'known price commission or cap '.$price);
    }
    $refined=$make(200000);
    semanticPredictionAssert($unknown['deal_value_prediction']['value_rub']===833
        &&$refined['deal_value_prediction']['value_rub']-$unknown['deal_value_prediction']['value_rub']===334
        &&json_encode($unknown,JSON_THROW_ON_ERROR)===$before,'refining price rewrote the earlier provisional snapshot');
    foreach([
        ['rules_sha256'=>'f4f205793ce51a18f7268292df3e0362b152869d60c71a5bc9c62017ca136593'],
        ['full_value_base_rub'=>25000,'base_basis'=>'existing_25000_fallback','value_rub'=>2083],
    ]as$change){
        $bad=$unknown;$bad['deal_value_prediction']=array_replace($bad['deal_value_prediction'],$change);
        unset($bad['deal_value_prediction']['deal_digest']);
        $bad['deal_value_prediction']['deal_digest']=ModeledConversionSemanticPrediction::digest($bad['deal_value_prediction']);
        semanticPredictionAssert(ModeledConversionDealValue::saved($bad)===null,'V3 accepted a legacy hash or unknown-price policy');
    }
};
$tests['frozen monetary V2 retains exact source identity and original amounts']=static function():void{
    // Literal synthetic V2 capsules, sealed once by the V2 contract, never re-frozen by V3.
    $unknown=['version'=>'early_deal_commission_7pct_v2',
        'rules_sha256'=>'8a35e248d8237b9042d6aa94eca975c00aae8ae2dc6b3ef42c61b50ee4273669',
        'subject'=>semanticPredictionSubject(),'semantic_prediction_digest'=>str_repeat('a',64),
        'current_request_anchor'=>'journal:1','profile_prediction_at'=>'2026-11-14T00:01:00Z',
        'input_cutoff_at'=>'2026-11-14T00:01:00Z','frozen_at'=>'2026-11-14T00:01:00Z',
        'commission_rate'=>0.07,'currency'=>'RUB','status'=>'estimated','reason'=>'deal_price_unknown_provisional_base',
        'early_window'=>'first_hour','age_seconds'=>60,
        'deal_amount_rub'=>['low'=>null,'high'=>null,'point'=>null,'basis'=>'unknown','source_kind'=>'unknown','source_refs'=>[]],
        'commission_estimate_rub'=>null,'full_value_base_rub'=>10000,'base_basis'=>'provisional_commission_10000',
        'estimated_probability'=>0.083333,'value_rub'=>833,'payment_hold_observed'=>false,
        'calibration_status'=>'expert_estimate_not_calibrated','price_verified'=>false,'trip_identity'=>'unverified',
        'export_allowed'=>false,'model_calls'=>0,'conversion_uploads'=>0,
        'deal_digest'=>'4b23f70f0114e202312b2c18fa6e739724c9c16fb44a54be2dfb15370b87ae70'];
    $known=$unknown;$known['reason']='stage_dialogue_and_estimated_commission';
    $known['deal_amount_rub']=['low'=>200000,'high'=>200000,'point'=>200000,
        'basis'=>'customer_budget_range_proxy','source_kind'=>'customer_budget','source_refs'=>['journal:1']];
    $known['commission_estimate_rub']=14000;$known['full_value_base_rub']=14000;
    $known['base_basis']='estimated_commission_7pct_capped';$known['value_rub']=1167;
    $known['deal_digest']='14d73b5f89b85958f3cdf3eff5ff6d2d491d7b8e4e560b2f76c713396b88a5c9';
    $wrapper=['access_status'=>'allowed','subject'=>semanticPredictionSubject(),'semantic_prediction'=>[
        'prediction_digest'=>str_repeat('a',64),'current_request_anchor'=>'journal:1',
        'profile_prediction_at'=>'2026-11-14T00:01:00Z','input_cutoff_at'=>'2026-11-14T00:01:00Z',
        'frozen_at'=>'2026-11-14T00:01:00Z','status'=>'predicted','estimated_probability'=>0.083333]];
    foreach([$unknown,$known]as$capsule){
        $w=$wrapper+['deal_value_prediction'=>$capsule];$before=json_encode($w,JSON_THROW_ON_ERROR);
        semanticPredictionAssert(ModeledConversionDealValue::saved($w)===$capsule
            &&json_encode($w,JSON_THROW_ON_ERROR)===$before,'frozen V2 was repriced resealed or rejected');
    }
    $reseal=static function(array $v):array{unset($v['deal_digest']);$v['deal_digest']=ModeledConversionSemanticPrediction::digest($v);return$v;};
    foreach([
        ['rules_sha256'=>str_repeat('b',64)],
        ['rules_sha256'=>hash_file('sha256',dirname(__DIR__).'/services/ModeledConversionDealValue.php')],
        ['rules_sha256'=>'f4f205793ce51a18f7268292df3e0362b152869d60c71a5bc9c62017ca136593'],
        ['version'=>'early_deal_commission_7pct_v4'],
        ['full_value_base_rub'=>25000,'value_rub'=>2083],
    ]as$change)semanticPredictionAssert(ModeledConversionDealValue::saved($wrapper+[
        'deal_value_prediction'=>$reseal(array_replace($unknown,$change))])===null,
        'V2 accepted another generation hash an unknown version or changed arithmetic');
};
$tests['monetary V3 selects the latest time eligible publication before the customer anchor']=static function():void{
    [$case,$w,$doc]=semanticPredictionFreezeFixture([['text'=>'earlier publication','admitted'=>false],
        ['text'=>'Подберите тур в Турцию на 7 ночей, 2 взрослых.'],['text'=>'later publication','admitted'=>false]]);
    foreach($case['rows']as&$row){
        if(!in_array($row['journal_id'],['1','3'],true))continue;
        $price=$row['journal_id']==='1'?200000:500000;
        $row['source_kind']=$row['snapshot']['source_kind']='event';
        $row['snapshot']['payload']=['event_type'=>'channel_post_entry','actor_type'=>'customer',
            '_modeled_price_context'=>['version'=>'entry_price_context_v1','captured_at_unix'=>$row['observed_at_unix'],
                'range'=>['low'=>$price,'high'=>$price,'point'=>$price,'unit'=>'party_total',
                    'party_size'=>2,'basis'=>'advertised_range_proxy']]];
    }unset($row);
    // The monetary reader consumes the unchanged admitted human request; entry neutrality has separate adapter tests.
    $human=$case;$human['rows']=array_values(array_filter($human['rows'],static fn(array $r):bool=>$r['source_kind']==='message'));
    $w=array_replace($w,ModeledConversionEarlyForecast::freeze($human,$w,$doc,null,strtotime($doc['requested_at']),null,true));
    $w['profile']['party']['total']=['status'=>'known','value'=>2];
    $beforeCase=$case;$beforeCase['rows']=array_values(array_filter($case['rows'],static fn(array $r):bool=>$r['journal_id']!=='3'));
    $before=ModeledConversionDealValue::freeze($w,$beforeCase);$after=ModeledConversionDealValue::freeze($w,$case);
    semanticPredictionAssert($before['deal_amount_rub']['point']===200000&&$after['deal_amount_rub']===$before['deal_amount_rub']
        &&$after['full_value_base_rub']===14000&&$after['value_rub']===1167
        &&$after['current_request_anchor']==='journal:2'&&$after['value_rub']===$before['value_rub'],
        'a later publication hid the earlier usable price or changed the request value');
    $reversed=$case;$reversed['rows']=array_reverse($case['rows']);
    semanticPredictionAssert(ModeledConversionDealValue::freeze($w,$reversed)===$after,'publication row order changed the selected time eligible price');
    $laterOnly=$case;$laterOnly['rows']=array_values(array_filter($case['rows'],static fn(array $r):bool=>$r['journal_id']!=='1'));
    $late=ModeledConversionDealValue::freeze($w,$laterOnly);
    semanticPredictionAssert($late['deal_amount_rub']['point']===null&&$late['full_value_base_rub']===10000
        &&$late['value_rub']===833,'a post-anchor publication became current request price');
    foreach([86400,86401]as$age){
        $aged=$case;
        foreach($aged['rows']as&$row)if($row['journal_id']==='1'){
            $row['snapshot']['source_created_at_unix']=1793002010-$age;
            $row['observed_at_unix']=$row['snapshot']['source_created_at_unix']+1;
            $row['snapshot']['payload']['_modeled_price_context']['captured_at_unix']=$row['observed_at_unix'];
        }unset($row);
        $v=ModeledConversionDealValue::freeze($w,$aged);
        semanticPredictionAssert($v['deal_amount_rub']['point']===($age===86400?200000:null),
            'publication lookback boundary changed '.$age);
    }
    foreach(['party','unit','range','source','fence']as$guard){
        $bad=$case;
        foreach($bad['rows']as&$row)if($row['journal_id']==='1'){
            if($guard==='party')$row['snapshot']['payload']['_modeled_price_context']['range']['party_size']=3;
            elseif($guard==='unit')$row['snapshot']['payload']['_modeled_price_context']['range']['unit']='night';
            elseif($guard==='range')$row['snapshot']['payload']['_modeled_price_context']['range']['low']=600000;
            elseif($guard==='source')$row['snapshot']['customer_key']='99';
            else$row['journal_id']='9999';
        }unset($row);
        $v=ModeledConversionDealValue::freeze($w,$bad);
        semanticPredictionAssert($v['deal_amount_rub']['point']===null&&$v['value_rub']===833,
            'time filtering loosened publication '.$guard.' guard');
    }
};
$tests['publication estimate distinguishes total party nightly foreign and unknown basis']=static function():void{
    $v=ModeledConversionDealValue::publicationRange('Тур от 150–200 тыс руб за 2 взрослых');
    semanticPredictionAssert($v['point']===175000&&$v['unit']==='party_total'&&$v['party_size']===2,'publication range');
    semanticPredictionAssert(ModeledConversionDealValue::publicationRange('Цена 5000 руб за ночь')===null
        &&ModeledConversionDealValue::publicationRange('Тур 2000 USD')===null,'nightly or foreign price interpreted as tour RUB');
    $v=ModeledConversionDealValue::publicationRange('Тур от 150000 ₽ на двоих');
    semanticPredictionAssert($v['low']===150000&&$v['high']===null&&$v['point']===150000,'advertised floor precision');
};


$tests['canonical recorded questions are strict context and never factual writes']=static function():void{
    $source=file_get_contents(dirname(__DIR__).'/services/MissingFieldQuestionService.php');
    foreach(ModeledConversionQuestionnaireContext::QUESTIONS as$field=>$text)
        semanticPredictionAssert(ModeledConversionQuestionnaireContext::field($text)===$field
            &&str_contains($source,$text),'canonical recorded question mapping drifted');
    foreach(['Сколько взрослых?','Оплачено. На сколько ночей планируете поездку?',
        'Цитата: Будут дети? Если да — сколько?']as$text)
        semanticPredictionAssert(ModeledConversionQuestionnaireContext::field($text)===null,'broad AI text became a pending question');
    foreach([['adults','2'],['children','Нет!'],['children','без детей'],['nights','7-9']]as[$field,$text])
        semanticPredictionAssert(ModeledConversionQuestionnaireContext::accepts($field,$text),'strict literal was rejected');
    foreach([['adults','2 взрослых'],['children','Да'],['children','4'],['nights','29'],
        ['nights','9-7'],['nights','7?'],['nights','7 или 9']]as[$field,$text])
        semanticPredictionAssert(!ModeledConversionQuestionnaireContext::accepts($field,$text),'ambiguous literal became a criterion');
};

$tests['AI question context collects human criteria without receipts or engagement inflation']=static function():void{
    $p=semanticPredictionNew();$state=ArchiveResearchSemanticExtractor::initialState();
    $at=strtotime('2026-11-01T00:00:00Z');
    $answer=static function(string $text,string $ref,int $time)use($p,&$state):void{
        $r=semanticPredictionResult($text,'customer',$ref,$time,$state);
        $p->observeMessage('customer',$text,$ref,$time,$time+1,1,$r);
    };
    $p->observeServiceQuestion('Сколько будет взрослых туристов?','journal:1',$at,$at+1,1);
    semanticPredictionAssert($p->finish(semanticPredictionLegacy())['status']==='insufficient_context','AI question supplied a customer criterion');
    $answer('2','journal:2',$at+10);
    $p->observeServiceQuestion('Будут дети? Если да — сколько?','journal:3',$at+20,$at+21,1);
    $answer('Нет','journal:4',$at+30);
    semanticPredictionAssert($p->finish(semanticPredictionLegacy())['status']==='insufficient_context','party fields became two independent families');
    $p->observeServiceQuestion('Сколько лет детям?','journal:5',$at+40,$at+41,1);
    semanticPredictionAssert($p->canPreserveCustomerContext($at+45),'unsupported known AI question erased an answered party criterion');
    $answer('5','journal:6',$at+50);
    $p->observeServiceQuestion('На сколько ночей планируете поездку?','journal:7',$at+60,$at+61,1);
    $answer('7','journal:8',$at+70);
    $v=$p->finish(semanticPredictionLegacy());
    semanticPredictionAssert($v['status']==='predicted'&&$v['stage']==='concrete_request'
        &&$v['current_request_anchor']==='journal:2'&&$v['base_probability']===0.083333
        &&$v['estimated_probability']===0.083333&&$v['hypothesis_odds_multiplier']===1.0
        &&$v['features']['meaningful_customer_turns']===0&&$v['features']['criteria_families']===['dates','party']
        &&$v['features']['explicit_decline']===false&&$v['outcome_observations']===[],
        'question-conditioned answers changed intent, weights or failed the concrete stage');
    require_once dirname(__DIR__).'/services/ModeledConversionValueRegistry.php';
    $wrapper=['subject'=>semanticPredictionSubject(),'access_status'=>'allowed',
        'profile'=>['prediction_at'=>$v['profile_prediction_at']],'semantic_prediction'=>$v];
    semanticPredictionAssert(ModeledConversionValueRegistry::predictionFields($wrapper)['semantic_prediction']['availability']==='available',
        'new inference is rejected by the existing saved-profile read contract');
};

$tests['pending question is consumed and cannot cross intervening human service or unknown context']=static function():void{
    $at=strtotime('2026-11-01T00:00:00Z');
    foreach(['manager','unrecognized_ai','barrier','second_answer','revised_question']as$kind){
        $p=semanticPredictionNew();$state=ArchiveResearchSemanticExtractor::initialState();
        $observe=static function(string $role,string $text,string $ref,int $time)use($p,&$state):void{
            $r=semanticPredictionResult($text,$role,$ref,$time,$state);
            $p->observeMessage($role,$text,$ref,$time,$time+1,1,$r);
        };
        $p->observeServiceQuestion('Сколько будет взрослых туристов?','journal:1',$at,$at+1,1);
        $observe('customer','2','journal:2',$at+10);
        $p->observeServiceQuestion('На сколько ночей планируете поездку?','journal:3',$at+20,$at+21,$kind==='revised_question'?2:1);
        if($kind==='manager')$observe('manager','Уточняю предложение','journal:4',$at+25);
        elseif($kind==='unrecognized_ai')$p->observeServiceQuestion('Уточняю параметры.','journal:4',$at+25,$at+26,1);
        elseif($kind==='barrier')$p->barrier();
        elseif($kind==='second_answer')$observe('customer','Не знаю','journal:4',$at+25);
        $observe('customer','7','journal:5',$at+30);
        semanticPredictionAssert($p->finish(semanticPredictionLegacy())['status']==='insufficient_context',
            'pending question survived an invalid boundary: '.$kind);
    }
};

$tests['bot questions cannot renew old customer criteria across a day']=static function():void{
    $p=new ModeledConversionSemanticPrediction(semanticPredictionSubject(),'2026-11-04T00:00:00Z',
        '2026-11-03T00:00:00Z','2026-11-04T00:00:00Z','100','100');
    $at=strtotime('2026-11-01T00:00:00Z');$state=ArchiveResearchSemanticExtractor::initialState();
    $p->observeServiceQuestion('Сколько будет взрослых туристов?','journal:1',$at,$at+1,1);
    $r=semanticPredictionResult('2','customer','journal:2',$at+10,$state);
    $p->observeMessage('customer','2','journal:2',$at+10,$at+11,1,$r);
    $p->observeServiceQuestion('На сколько ночей планируете поездку?','journal:3',$at+23*3600,$at+23*3600+1,1);
    // The source reader may see service traffic meanwhile; the human criterion remains old.
    $state=ArchiveResearchSemanticExtractor::initialState();
    $r=semanticPredictionResult('7','customer','journal:4',$at+25*3600,$state);
    $p->observeMessage('customer','7','journal:4',$at+25*3600,$at+25*3600+1,1,$r);
    semanticPredictionAssert($p->finish(semanticPredictionLegacy())['status']==='insufficient_context',
        'a fresh AI question prolonged an old party criterion past 24 hours');
};


$tests['destination only replies retain one family without inventing purchase intent']=static function():void{
    foreach(['ОАЭ','Дубай','Тунис','Кипр','Греция','Испания','Италия','Вьетнам','Индонезия',
        'Бали','Куба','Шри-Ланка','Гоа','Сочи','Абхазия']as$text){
        $p=semanticPredictionRun([['text'=>$text]]);
        semanticPredictionAssert($p['stage']==='substantive_dialogue'&&$p['base_probability']===0.05
            &&$p['current_request_anchor']==='journal:1'
            &&$p['features']['criteria_families']===['destination']
            &&$p['outcome_observations']===[],'country-only promoted beyond interest: '.$text);
    }
};
$tests['destination survives a fresh explicit reply after unread content without bridging old criteria']=static function():void{
    $p=semanticPredictionRun([
        ['text'=>'С 12.02 по 19.02'],['barrier'=>true],['text'=>'ОАЭ'],['text'=>'Отель'],
        ['text'=>'2 взрослых'],['text'=>'На 7 ночей']]);
    semanticPredictionAssert($p['stage']==='concrete_request'&&$p['base_probability']===0.083333
        &&$p['current_request_anchor']==='journal:3'
        &&$p['features']['criteria_families']===['dates','destination','party']
        &&$p['outcome_observations']===[],'fresh country reply lost or became purchase');
    $destination=array_values(array_filter($p['evidence'],static fn(array $e):bool=>$e['feature']==='criteria_destination'));
    semanticPredictionAssert(count($destination)===1&&$destination[0]['source_ref']==='journal:3',
        'destination evidence did not point to the fresh country reply');
    foreach($p['evidence']as$e)semanticPredictionAssert($e['source_ref']!=='journal:1',
        'criteria before unread content were reused');
    $p=semanticPredictionRun([['text'=>'2 взрослых, 7 ночей'],['barrier'=>true],['text'=>'ОАЭ']]);
    semanticPredictionAssert($p['stage']==='substantive_dialogue'&&$p['current_request_anchor']==='journal:3'
        &&$p['features']['criteria_families']===['destination'],'country bridged old party or dates');
    $p=semanticPredictionRun([['text'=>'ОАЭ'],['barrier'=>true],['text'=>'Да']]);
    semanticPredictionAssert($p['estimated_probability']===null&&$p['current_request_anchor']===null,
        'unknown entry barrier preserved old destination through an acknowledgment');
};
$tests['new destination lexicon preserves word clause actor and clock admission guards']=static function():void{
    foreach(['Мы колебались.','Пришлите сочинение.','Это многоаспектный вопрос.',
        'ОАЭ не рассматриваю.','Не ОАЭ.','«ОАЭ»','> Дубай',
        'В прошлом году были в ОАЭ.','Если выберем ОАЭ.']as$text){
        $p=semanticPredictionRun([['text'=>$text]]);
        semanticPredictionAssert($p['estimated_probability']===null&&$p['current_request_anchor']===null,
            'destination guard promoted: '.$text);
    }
    $p=semanticPredictionRun([['role'=>'manager','text'=>'ОАЭ']]);
    semanticPredictionAssert($p['estimated_probability']===null&&$p['current_request_anchor']===null,
        'manager destination promoted');
    $at=strtotime('2026-11-01T00:00:00Z');
    foreach([['forwarded'=>true],['forwarded'=>null],['role_verified'=>false],
        ['direction'=>'outbound'],['created_at'=>null]]as$override){
        $p=semanticPredictionNew();
        $r=ArchiveResearchSemanticExtractor::step(array_replace([
            'source_ref'=>'journal:1','created_at'=>$at,'read_status'=>'available','text'=>'ОАЭ',
            'role'=>'customer','direction'=>'inbound','role_verified'=>true,'text_minimized'=>true,
            'forwarded'=>false,'media'=>[],'media_contents_read'=>false,
            'selection_links_unread'=>false,'links_redacted'=>false],$override),
            ArchiveResearchSemanticExtractor::initialState());
        $p->observeMessage('customer','ОАЭ','journal:1',$at,$at+1,1,$r);
        $v=$p->finish(semanticPredictionLegacy());
        semanticPredictionAssert($v['estimated_probability']===null&&$v['current_request_anchor']===null,
            'destination source/clock guard promoted: '.json_encode($override,JSON_THROW_ON_ERROR));
    }
};


$tests['canonical source date questions add context only and reject surrounding prose']=static function():void{
    require_once dirname(__DIR__).'/services/SourceQualificationPrompt.php';
    foreach([false,true]as$greet)foreach(['','VINPEARL RESORT NHA TRANG']as$hotel){
        $text=SourceQualificationPrompt::text(['date','adults','children'],$hotel,$greet);
        semanticPredictionAssert(ModeledConversionQuestionnaireContext::field($text)==='date','actual source date prompt not recognized');
    }
    semanticPredictionAssert(ModeledConversionQuestionnaireContext::field(MissingFieldQuestionService::question('date'))==='date','ordinary date prompt not recognized');
    foreach(['Цитата: Когда планируете отдых? Можно указать примерные даты.',
        'Оплачено. Когда планируете отдых? Можно указать примерные даты.',
        'Когда планируете отдых? Можно указать примерные даты. Сколько человек?',
        "Помогу подобрать тур в Отель.\nКогда планируете отдых? Можно указать примерные даты."]as$text)
        semanticPredictionAssert(ModeledConversionQuestionnaireContext::field($text)===null,'arbitrary date-question wrapper admitted');
};
$tests['literal date answers validate the calendar using the recorded clock only']=static function():void{
    $at=strtotime('2026-11-01T00:00:00Z');
    foreach(['5 декабря на 8 дней','17.02.27 - 03.03.27','20.11.2026','С 18.04.27 по 02.05.27',
        '20/11/2026','5 ДЕКАБРЯ на 8 ночей','29 февраля']as$text)
        semanticPredictionAssert(ModeledConversionQuestionnaireContext::accepts('date',$text,$at),'literal date rejected: '.$text);
    foreach(['31 февраля','32 декабря','20.13.2026','29.02.2027','20.10.2026','03.03.27 - 17.02.27',
        '20.11 - 25.11.2026','5 декабря на 29 дней','5 декабря на 0 дней','8 дней',
        'Если получится, 5 декабря на 8 дней','В прошлом году 5 декабря на 8 дней',
        'Не 5 декабря','5 декабря?','5 декабря или 7 декабря','20.07..2027','https://example.test/20.11.2026']as$text)
        semanticPredictionAssert(!ModeledConversionQuestionnaireContext::accepts('date',$text,$at),'ambiguous or invalid date admitted: '.$text);
    semanticPredictionAssert(!ModeledConversionQuestionnaireContext::accepts('date','20.11.2026')
        &&!ModeledConversionQuestionnaireContext::accepts('date','20.11.2026',0),'unknown customer clock accepted');
    semanticPredictionAssert(ModeledConversionQuestionnaireContext::accepts('date','20.11.2026',strtotime('2026-11-19T20:59:59Z'))
        &&!ModeledConversionQuestionnaireContext::accepts('date','20.11.2026',strtotime('2026-11-20T21:00:00Z')),
        'explicit past-date comparison did not use the recorded Moscow day');
};
$tests['date then party establishes one concrete request without engagement inflation']=static function():void{
    require_once dirname(__DIR__).'/services/SourceQualificationPrompt.php';
    $at=strtotime('2026-11-01T00:00:00Z');
    foreach(['5 декабря на 8 дней','17.02.27 - 03.03.27','20.11.2026']as$text){
        $p=semanticPredictionNew();$state=ArchiveResearchSemanticExtractor::initialState();
        $answer=static function(string $text,string $ref,int $time)use($p,&$state):void{
            $r=semanticPredictionResult($text,'customer',$ref,$time,$state);
            $p->observeMessage('customer',$text,$ref,$time,$time+1,1,$r);
        };
        $p->observeServiceQuestion(SourceQualificationPrompt::text(['date','adults'],'VINPEARL RESORT NHA TRANG',false),'journal:1',$at,$at+1,1);
        semanticPredictionAssert($p->finish(semanticPredictionLegacy())['features']['criteria_families']===[],'date question invented customer criteria');
        $answer($text,'journal:2',$at+10);
        $one=$p->finish(semanticPredictionLegacy());
        semanticPredictionAssert($one['status']==='insufficient_context'&&$one['current_request_anchor']===null
            &&$one['features']['criteria_families']===['dates'],'one date or duration became two independent families');
        $p->observeServiceQuestion('Из какого города планируете вылет?','journal:3',$at+20,$at+21,1);
        $answer('Москва','journal:4',$at+30);
        $p->observeServiceQuestion('Сколько будет взрослых туристов?','journal:5',$at+40,$at+41,1);
        $answer('2','journal:6',$at+50);
        $p->observeServiceQuestion('Будут дети? Если да — сколько?','journal:7',$at+60,$at+61,1);
        $answer('Нет','journal:8',$at+70);
        $v=$p->finish(semanticPredictionLegacy());
        semanticPredictionAssert($v['stage']==='concrete_request'&&$v['current_request_anchor']==='journal:2'
            &&$v['features']['criteria_families']===['dates','party']
            &&$v['estimated_probability']===0.083333&&$v['hypothesis_odds_multiplier']===1.0
            &&$v['features']['meaningful_customer_turns']===0&&$v['outcome_observations']===[]
            &&$v['features']['explicit_decline']===false,'date-conditioned answer changed intent or weights');
    }
};
$tests['date question cannot bypass actor time or unsafe context guards']=static function():void{
    $at=strtotime('2026-11-01T00:00:00Z');
    foreach(['quoted_or_relayed','past_trip','conditional','context_barrier','unknown_time','reversed_clock','manager','barrier','revised_question']as$kind){
        $p=semanticPredictionNew();$state=ArchiveResearchSemanticExtractor::initialState();
        $p->observeServiceQuestion('Какая ориентировочная дата вылета?','journal:1',$at,$at+1,$kind==='revised_question'?2:1);
        if($kind==='barrier')$p->barrier();
        $role=$kind==='manager'?'manager':'customer';
        $r=semanticPredictionResult('5 декабря на 8 дней',$role,'journal:2',$at+10,$state);
        if(!in_array($kind,['manager','barrier','revised_question'],true))$r['flags'][$kind]=true;
        $p->observeMessage($role,'5 декабря на 8 дней','journal:2',$at+10,$at+11,1,$r);
        $p->observeServiceQuestion('Сколько будет взрослых туристов?','journal:3',$at+20,$at+21,1);
        $r=semanticPredictionResult('2','customer','journal:4',$at+30,$state);
        $p->observeMessage('customer','2','journal:4',$at+30,$at+31,1,$r);
        $v=$p->finish(semanticPredictionLegacy());
        semanticPredictionAssert($v['status']==='insufficient_context'&&$v['current_request_anchor']===null,'unsafe date crossed a guard: '.$kind);
    }
};

$tests['recorded departure alternatives do not turn a prior date into a destination request']=static function():void{
    require_once dirname(__DIR__).'/services/SourceQualificationPrompt.php';
    $date=SourceQualificationPrompt::text(['date','adults'],'',true);
    $city=MissingFieldQuestionService::question('city');
    $adults=MissingFieldQuestionService::question('adults');
    $country=MissingFieldQuestionService::question('country');
    $q=static fn(string $text):array=>['text'=>$text,'role'=>'ai','admitted'=>false];
    $run=static function(array $messages):array{
        [$case,$wrapper,$document]=semanticPredictionFreezeFixture($messages,'max');
        foreach($case['rows']as&$row){
            $message=$messages[(int)$row['journal_id']-1];
            $snap=&$row['snapshot'];
            if(($message['role']??null)==='ai'){
                $snap['source_timestamp_basis']='canonical_recording_unix_v1';
                $snap['payload']['direction']='outbound';$snap['payload']['sender_type']='ai';
                $snap['payload']['metadata_json']=json_encode(ConversationRecordClock::stamp(
                    ['type'=>'message'],$snap['source_created_at_unix']),JSON_THROW_ON_ERROR);
            }elseif(isset($message['metadata']))$snap['payload']['metadata_json']=$message['metadata'];
            if(isset($message['version']))$row['source_version_no']=$message['version'];
            unset($snap);
        }
        unset($row);
        $packet=ModeledConversionEarlyForecast::freeze($case,$wrapper,$document,null,strtotime($document['requested_at'])+1,null,true);
        return $packet['semantic_prediction'];
    };
    foreach(['Казань/Египет','Казань / 15.12','Сочи / ','Сочи/Пермь?',"Сочи\n/Пермь",
        'Сочи / Томск, двое взрослых','Сочи / Турция в июле']as$ambiguous)
        semanticPredictionAssert(!ModeledConversionQuestionnaireContext::departureAlternatives($ambiguous),'an incomplete or mixed answer was interpreted as departure alternatives');
    foreach(['Сочи / СОЧИ','Сочи/Уфа/Пермь/Омск/Братск']as$known){
        $v=$run([$q($city),['text'=>$known]]);
        semanticPredictionAssert($v['features']['criteria_families']===[]&&$v['current_request_anchor']===null,
            'a repeated or longer list of known departure names became a destination request');
    }
    $answer=['text'=>'Казань / Сочи / Пермь'];
    $prefix=[$q($date),['text'=>'04.12 - 14.12'],$q($city),$answer];
    $v=$run($prefix);
    semanticPredictionAssert($v['current_request_anchor']===null&&$v['stage']===null
        &&$v['features']['criteria_families']===['dates'],'departure alternatives created a request or erased the earlier date');
    $v=$run([...$prefix,$q($city),['text'=>'Пермь'],$q($adults),['text'=>'3']]);
    semanticPredictionAssert($v['stage']==='concrete_request'&&$v['current_request_anchor']==='journal:2'
        &&$v['features']['criteria_families']===['dates','party']&&$v['features']['meaningful_customer_turns']===0
        &&$v['outcome_observations']===[],'later real party and date did not retain their own evidence');
    foreach($v['evidence']as$e)semanticPredictionAssert(in_array($e['source_ref'],['journal:2','journal:8'],true),'departure became positive evidence');
    $v=$run([$q($city),$answer,$q($country),['text'=>'Египет'],$q($adults),['text'=>'2']]);
    semanticPredictionAssert($v['current_request_anchor']==='journal:4'
        &&$v['features']['criteria_families']===['destination','party'],'neutral alternatives became the anchor for later independently established criteria');
    $v=$run([$q($country),['text'=>'Сочи']]);
    semanticPredictionAssert($v['features']['criteria_families']===['destination'],'country question lost its ordinary destination');
    $mixed=$run([$q($city),['text'=>'Казань / Сочи, подберите тур в Египет на 9 ночей']]);
    semanticPredictionAssert(in_array('destination',$mixed['features']['criteria_families'],true)
        &&in_array('dates',$mixed['features']['criteria_families'],true),'compound request lost its explicit conditions');
    foreach([[$answer],[$q($city),['text'=>'unavailable','admitted'=>false],$answer],
        [$q($city),$answer+['version'=>2]],
        [$q($city),$answer+['metadata'=>'{"type":"message","linked_message":{"type":"reply"}}']]]as$unbound){
        $v=$run($unbound);
        semanticPredictionAssert(in_array('destination',$v['features']['criteria_families'],true),'an unbound city list was consumed as a reply to the bot');
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
