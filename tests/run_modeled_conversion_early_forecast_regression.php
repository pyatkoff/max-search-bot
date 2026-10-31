<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/ModeledConversionEarlyForecast.php';

function modeledEarlyFixture(array $messages):array
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
function modeledEarlyRun(array $messages):array{return ModeledConversionEarlyForecast::freeze(...modeledEarlyFixture($messages));}
/** Full synthetic admitted request + canonical producer receipts + later text. */
function modeledOfferFixture(string $reaction,array $between=[],string $query='Подберите тур в Турцию',array $after=[]):array
{
    $parts=modeledEarlyFixture([['text'=>$query],['role'=>'manager','text'=>'prepared'],
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
function modeledEarlyAssertions(callable $check):void
{
    $p=modeledEarlyRun([['text'=>'Подберите тур в Турцию. Бюджет до 200 тысяч.']]);
    $f=$p['early_forecast'];
    $check('early quality is a distinct non-monetary candidate with unresolved trip',
        $f['early_quality']['state']==='substantive_request'
        &&$f['early_quality']['trip_status']==='unknown'
        &&!$f['early_quality']['evaluation_eligible']&&!$f['early_quality']['export_allowed']
        &&$f['early_quality']['specific_offer_preferences']===[]);
    $priceReply='Fixture Beach A дорого, найдите дешевле с таким же пляжем';
    $parts=modeledOfferFixture($priceReply);
    $p=ModeledConversionEarlyForecast::freeze(...$parts);$quality=$p['early_forecast']['early_quality'];
    $check('explicit named-offer price alternative binds request delivery and text without claiming purchase',
        $quality['state']==='specific_options_discussion'
        &&$quality['request_offer_links'][0]['source_refs']===['journal:1','journal:2','journal:3']
        &&$quality['specific_option_discussions'][0]['source_refs']===['journal:1','journal:2','journal:3','journal:4']
        &&$quality['specific_option_discussions'][0]['reaction']==='price_alternative_request'
        &&$quality['specific_option_discussions'][0]['search_continuation']==='explicit_request'
        &&$quality['specific_option_discussions'][0]['available_at']==='2026-10-26T08:07:11Z'
        &&$quality['trip_status']==='unknown'&&!$quality['export_allowed']&&!$quality['evaluation_eligible']
        &&$p['contextual_signals']['goal_counts']['deferral']===0
        &&$p['contextual_signals']['goal_counts']['payment_self_report']===0);
    $check('reaction projection stores grounds but no hotel text native message ID or token',
        !str_contains(json_encode($quality),'Fixture Beach A')
        &&!str_contains(json_encode($quality),'synthetic-native-mid')
        &&!str_contains(json_encode($quality),str_repeat('a',64)));
    foreach(['Да','Fixture Beach A','Fixture Beach A дорого','Дорого, найдите дешевле с таким же пляжем',
        'Fixture Other дорого, найдите дешевле','Fixture Beach A дорога, найдите дешевле',
        'Fixture Beach A дорого, Fixture Other хороший; найдите дешевле',
        'Если Fixture Beach A дорого, найдите дешевле','В прошлом году Fixture Beach A дорого, найдите дешевле',
        'Мне сказали: Fixture Beach A дорого, найдите дешевле']as$text){
        $p=ModeledConversionEarlyForecast::freeze(...modeledOfferFixture($text));
        $check('unqualified or ungrounded reaction stays unlinked '.hash('sha256',$text),
            $p['early_forecast']['early_quality']['specific_option_discussions']===[]
            &&$p['early_forecast']['early_quality']['state']!=='specific_options_discussion');
    }
    foreach(['missing_delivery','wrong_chat','wrong_actor','edited','scope','snapshot','future_receipt','missing_request',
        'invalid_token','wrong_receipt_clock']as$kind){
        $changed=$parts;
        if($kind==='missing_delivery')$changed[1]['offer_journey']['offers'][0]['delivery']=null;
        elseif($kind==='snapshot')$changed[1]['offer_journey']['offers'][0]['snapshot']['amount']='1';
        elseif($kind==='future_receipt')$changed[1]['offer_journey']['offers'][0]['delivery']['available_at']='2099-01-01T00:00:00Z';
        elseif($kind==='missing_request')$changed=modeledOfferFixture($priceReply,[],'Здравствуйте');
        else foreach($changed[0]['rows']as&$row)if($row['journal_id']===($kind==='invalid_token'?'2':'3')){
            if($kind==='wrong_actor')$row['snapshot']['payload']['actor_type']='customer';
            elseif($kind==='edited')$row['source_version_no']=2;
            elseif($kind==='scope')$row['snapshot']['customer_key']='8';
            else{$data=json_decode($row['snapshot']['payload']['payload_json'],true);
                if($kind==='invalid_token')$data['token_hash']='invalid';
                elseif($kind==='wrong_receipt_clock')$data['_recorded_clock']['unix']++;
                else$data['native_chat_id']='8';
                $row['snapshot']['payload']['payload_json']=json_encode($data);}
        }
        unset($row);
        $p=ModeledConversionEarlyForecast::freeze(...$changed);
        $check('incomplete or changed offer chain cannot establish discussion '.$kind,
            $p['early_forecast']['early_quality']['specific_option_discussions']===[]);
    }
    foreach([['text'=>'Новая поездка, теперь хочу поехать в Египет'],
        ['text'=>'unavailable','admitted'=>false],['text'=>'unknown clock','admitted'=>false,'clock'=>false]]as$i=>$middle){
        $p=ModeledConversionEarlyForecast::freeze(...modeledOfferFixture($priceReply,[$middle]));
        $check('new candidate or omitted content prevents crossing delivery reaction linkage '.$i,
            $p['early_forecast']['early_quality']['specific_option_discussions']===[]);
    }
    $p=ModeledConversionEarlyForecast::freeze(...modeledOfferFixture($priceReply,[],
        'Подберите тур в Турцию',[['text'=>'Новая поездка, теперь хочу поехать в Египет']]));
    $check('earlier discussion is retained as history but cannot promote a later request',
        count($p['early_forecast']['early_quality']['specific_option_discussions'])===1
        &&!$p['early_forecast']['early_quality']['specific_option_discussions'][0]['current_candidate']
        &&$p['early_forecast']['early_quality']['state']==='substantive_request');
    $changed=$parts;$changed[0]['access_denied']=true;$changed[1]['access_status']='denied';
    $p=ModeledConversionEarlyForecast::freeze(...$changed);
    $check('denied scope suppresses both request offer links and textual discussion',
        $p['early_forecast']['early_quality']['request_offer_links']===[]
        &&$p['early_forecast']['early_quality']['specific_option_discussions']===[]);
    $changed=$parts;
    foreach($changed[1]['profile']['observation_input']['evidence']as&$e)
        if($e['references'][0]['id']==='journal:4')$e['kind']='manager_message';
    unset($e);$p=ModeledConversionEarlyForecast::freeze(...$changed);
    $check('manager price-alternative statement is not a customer discussion',
        $p['early_forecast']['early_quality']['specific_option_discussions']===[]);
    foreach([15,20]as$offset){
        $changed=$parts;$at=1793002000+$offset;
        foreach($changed[0]['rows']as&$row)if($row['journal_id']==='4'){
            $row['snapshot']['source_created_at_unix']=$at;$row['observed_at_unix']=$at+1;
        }
        unset($row);
        foreach($changed[1]['profile']['observation_input']['evidence']as&$e)if($e['references'][0]['id']==='journal:4'){
            $e['at']=$e['references'][0]['at']=gmdate('Y-m-d\\TH:i:s\\Z',$at);
            $e['available_at']=$e['references'][0]['available_at']=gmdate('Y-m-d\\TH:i:s\\Z',$at+1);
        }
        unset($e);$p=ModeledConversionEarlyForecast::freeze(...$changed);
        $check('reaction before delivery or at an ambiguous same-second order stays unlinked '.$offset,
            $p['early_forecast']['early_quality']['specific_option_discussions']===[]);
    }
    $changed=$parts;
    foreach($changed[0]['rows']as&$row)if($row['journal_id']==='3')$row['observed_at_unix']=1793002100;
    unset($row);
    $changed[1]['offer_journey']['offers'][0]['delivery']['available_at']='2026-10-26T08:08:20Z';
    $p=ModeledConversionEarlyForecast::freeze(...$changed);
    $check('late actual receipt availability is retained instead of backdating to the customer reply',
        $p['early_forecast']['early_quality']['specific_option_discussions'][0]['available_at']==='2026-10-26T08:08:20Z');
    $changed=$parts;$extra=$changed[1]['offer_journey']['offers'][0];
    foreach([2=>6,3=>7]as$old=>$new){
        foreach($parts[0]['rows']as$row)if($row['journal_id']===(string)$old){$copy=$row;break;}
        $copy['journal_id']=$copy['source_id']=$copy['snapshot']['source_id']=(string)$new;
        $copy['snapshot']['source_created_at_unix']++;$copy['observed_at_unix']++;
        $data=json_decode($copy['snapshot']['payload']['payload_json'],true);
        $data['_recorded_clock']['unix']++;
        if($old===2)$data['snapshot']['amount']='180000';else$data['offer_id']=6;
        $copy['snapshot']['payload']['payload_json']=json_encode($data);
        $changed[0]['rows'][]=$copy;
        $which=$old===2?'prepared':'delivery';
        $extra[$which]=['journal_id'=>(string)$new,'source_id'=>(string)$new,'source_version_no'=>1,
            'recorded_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$copy['snapshot']['source_created_at_unix']),
            'available_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$copy['observed_at_unix'])];
    }
    $extra['snapshot']['amount']='180000';$changed[1]['offer_journey']['offers'][]=$extra;
    foreach([1,2]as$i){$changed[$i]['journal_cursor']='7';$changed[$i]['source_fence_journal_id']='7';}
    $p=ModeledConversionEarlyForecast::freeze(...$changed);
    $check('same named hotel across two delivered offers is ambiguous rather than arbitrarily attributed',
        count($p['early_forecast']['early_quality']['request_offer_links'])===2
        &&$p['early_forecast']['early_quality']['specific_option_discussions']===[]
        &&$p['early_forecast']['early_quality']['linking_omissions']['ambiguous_named_offer']===1);
    $check('new prefix freezes an initial ordinal assessment with no invented probability or money',
        $f['snapshot_kind']==='initial_observation'&&$f['status']==='early_assessment'
        &&$f['purchase_anywhere']['readiness_band']==='interest'&&$f['purchase_anywhere']['probability']===null
        &&$f['purchase_with_us']['probability']===null&&$f['expected_value_rub']===null
        &&!$f['export_allowed']&&!$f['evaluation_eligible']&&$f['coverage']['trip_status']==='unknown'
        &&strtotime($f['horizon_end_at'])-strtotime($f['prediction_at'])===2592000);
    $parts=modeledEarlyFixture([['text'=>'Давайте бронировать этот тур']]);
    $update=ModeledConversionEarlyForecast::freeze(...[...$parts,'1']);
    $check('subsequent prefix retains the first immutable cursor and explicit update identity',
        $update['early_forecast']['snapshot_kind']==='updated_observation'
        &&$update['early_forecast']['first_snapshot_journal_cursor']==='1'
        &&$update['early_forecast']['purchase_with_us']['readiness_band']==='intent');
    $p=modeledEarlyRun([['role'=>'manager','text'=>'Бронируем тур?'],['text'=>'Да']]);
    $check('canonical ordinary reply can use the preceding admitted booking question',
        $p['contextual_signals']['goal_counts']['agency_purchase_intent']===1
        &&$p['contextual_signals']['engagement']['median_reply_seconds']===10);
    foreach(['callback','forwarded statement','unknown role','empty turn']as$kind){
        $p=modeledEarlyRun([['role'=>'manager','text'=>'Бронируем тур?'],['text'=>'Давайте бронировать','admitted'=>false],['text'=>'Да']]);
        $check('omitted '.$kind.' is a barrier and cannot be restored by raw text',
            $p['contextual_signals']['goal_counts']['agency_purchase_intent']===0
            &&$p['contextual_signals']['goal_counts']['general_purchase_intent']===0
            &&$p['contextual_signals']['engagement']['observed_customer_replies']===0);
    }
    $p=modeledEarlyRun([['role'=>'manager','text'=>'Бронируем тур?'],['text'=>'unknown','admitted'=>false,'clock'=>false],['text'=>'Да']]);
    $check('an unplaceable source clock prevents all contextual linking but retains direct signals',
        $p['contextual_signals']['coverage']['context_linking_disabled']
        &&$p['contextual_signals']['goal_counts']['agency_purchase_intent']===0);
    $p=modeledEarlyRun([['role'=>'manager','text'=>'Бронируем тур?'],['text'=>str_repeat('😀',9000)],['text'=>'Да']]);
    $check('valid canonical Unicode message above semantic byte limit becomes a barrier without aborting',
        ($p['contextual_signals']['coverage']['semantic_omissions']['text_too_large']??0)===1
        &&$p['contextual_signals']['goal_counts']['agency_purchase_intent']===0
        &&$p['contextual_signals']['flags']['semantic_text_too_large']===true);
    $p=modeledEarlyRun([['role'=>'manager','text'=>'Бронируем тур?'],['text'=>str_repeat('http://a.b ',1000)],['text'=>'Да']]);
    $check('redaction growth above semantic byte limit also becomes an explicit barrier',
        ($p['contextual_signals']['coverage']['semantic_omissions']['text_too_large']??0)===1
        &&$p['contextual_signals']['goal_counts']['agency_purchase_intent']===0);
    $p=modeledEarlyRun([['text'=>'Подберите тур'],['role'=>'manager','text'=>'Ссылка на оплату https://example.test/pay']]);
    $check('a manager payment link alone is not a payment or observed purchase',
        $p['contextual_signals']['goal_counts']['payment_self_report']===0
        &&$p['contextual_signals']['goal_counts']['manager_payment_ack']===0
        &&$p['early_forecast']['status']==='early_assessment');
    foreach([['customer','Оплатил тур'],['manager','Оплату вижу'],['manager','Бронь подтверждена']]as[$role,$text]){
        $p=modeledEarlyRun([['text'=>'Подберите тур'],['role'=>$role,'text'=>$text]]);
        $check('already observed '.$role.' outcome cannot become a successful early forecast',
            $p['early_forecast']['status']==='target_already_observed'
            &&$p['early_forecast']['purchase_anywhere']['readiness_band']==='not_assessed');
    }
    $p=modeledEarlyRun([['text'=>'Давайте бронировать этот тур'],['text'=>'Пока подумаем и обсудим с мужем']]);
    $check('explicit deferral prevents monotonic readiness despite earlier intent',
        $p['early_forecast']['purchase_with_us']['readiness_band']==='deferred'
        &&$p['early_forecast']['current_readiness_status']==='unknown_after_contrary_signal'
        &&$p['early_forecast']['early_quality']['state']==='unknown'
        &&in_array('deferral',$p['early_forecast']['contrary_goal_ids'],true));
    $parts=modeledEarlyFixture([['text'=>'Подберите тур в Турцию']]);
    $subject=$parts[0]['subject'];$at=1793002010;
    foreach(['tour_offer_prepared','tour_offer_delivered','tour_offer_liked']as$i=>$kind){
        $id=(string)($i+2);
        $parts[0]['rows'][]=['journal_id'=>$id,'source_kind'=>'event','source_id'=>$id,'source_version_no'=>1,
            'observed_at_unix'=>$at+$i*10+1,'snapshot'=>$subject+['source_kind'=>'event','source_id'=>$id,
                'source_status'=>'present','payload_status'=>'copied','source_created_at_unix'=>$at+$i*10,
                'source_timestamp_basis'=>'database_session_timezone_to_unix','payload'=>['event_type'=>$kind]]];
    }
    $refs=[];
    foreach(['prepared','delivery','reaction']as$i=>$kind)$refs[$kind]=['journal_id'=>(string)($i+2),
        'available_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$at+$i*10+1)];
    $parts[1]['offer_journey']=['version'=>'admitted_offer_journey_v1','offers'=>[
        $refs+['state'=>'liked','customer_response'=>'explicit_offer_preference','snapshot'=>['is_demo'=>false],
            'available_at'=>$refs['reaction']['available_at']]]];
    foreach([1,2]as$i){$parts[$i]['journal_cursor']='4';$parts[$i]['source_fence_journal_id']='4';}
    $p=ModeledConversionEarlyForecast::freeze(...$parts);
    $check('joined saved offer preference retains exact references without promoting an unresolved trip',
        $p['early_forecast']['early_quality']['specific_offer_preferences']===[[
            'source_refs'=>['journal:2','journal:3','journal:4'],'available_at'=>$refs['reaction']['available_at']]]
        &&$p['early_forecast']['early_quality']['state']==='substantive_request'
        &&$p['early_forecast']['early_quality']['discussion_status']==='offer_preference_observed_episode_unverified'
        &&!$p['early_forecast']['early_quality']['export_allowed']);
    $changed=$parts;$changed[1]['offer_journey']['offers'][0]['available_at']='2099-01-01T00:00:00Z';
    $p=ModeledConversionEarlyForecast::freeze(...$changed);
    $check('a future offer preference cannot leak into an earlier frozen quality candidate',
        $p['early_forecast']['early_quality']['specific_offer_preferences']===[]);
    $changed=$parts;$changed[1]['offer_journey']['offers'][0]['reaction']['journal_id']='5';
    $p=ModeledConversionEarlyForecast::freeze(...$changed);
    $check('a preference outside the admitted journal prefix cannot be projected',
        $p['early_forecast']['early_quality']['specific_offer_preferences']===[]);
    $changed=$parts;$changed[0]['access_denied']=true;$changed[1]['access_status']='denied';
    $p=ModeledConversionEarlyForecast::freeze(...$changed);
    $check('denied scope suppresses both the early quality and retained offer preference',
        $p['early_forecast']['early_quality']['state']==='unknown'
        &&$p['early_forecast']['early_quality']['specific_offer_preferences']===[]);
    $p=modeledEarlyRun([['text'=>'Давайте бронировать этот тур'],['text'=>'Купили в другом агентстве']]);
    $check('another seller purchase excludes an early assessment and remains separate evidence',
        $p['early_forecast']['status']==='target_already_observed'
        &&$p['contextual_signals']['goal_counts']['purchased_elsewhere']===1);
    $parts=modeledEarlyFixture([['text'=>'Подберите тур'],['text'=>'Давайте бронировать этот тур']]);
    $forward=ModeledConversionEarlyForecast::freeze(...[...$parts,null,1793004000]);$parts[0]['rows']=array_reverse($parts[0]['rows']);
    $parts[0]['subject']=array_reverse($parts[0]['subject'],true);
    $check('input collection order cannot change the deterministic chronological packet',
        $forward===ModeledConversionEarlyForecast::freeze(...[...$parts,null,1793004000]));
    $parts=modeledEarlyFixture([['text'=>'Оплатил тур']]);$parts[0]['access_denied']=true;$parts[1]['access_status']='denied';
    $p=ModeledConversionEarlyForecast::freeze(...$parts);
    $check('denied scope yields no events even when a prior wrapper still has evidence',
        $p['early_forecast']['status']==='access_denied'&&$p['contextual_signals']['events']===[]);
    $parts=modeledEarlyFixture([['text'=>'Давайте бронировать этот тур']]);
    $parts[1]['profile']['observation_input']['evidence'][0]['references'][0]['available_at']='2099-01-01T00:00:00Z';
    try{ModeledConversionEarlyForecast::freeze(...$parts);$ok=false;}catch(RuntimeException $e){$ok=str_starts_with($e->getMessage(),'modeled_early_forecast_');}
    $check('future or changed canonical reference is rejected before persistence',$ok);
    $parts=modeledEarlyFixture([['text'=>'Давайте бронировать этот тур']]);$parts[0]['rows'][0]['snapshot']['customer_key']='8';
    try{ModeledConversionEarlyForecast::freeze(...$parts);$ok=false;}catch(RuntimeException $e){$ok=$e->getMessage()==='modeled_early_forecast_admitted_row';}
    $check('scope substitution cannot reuse an admitted message reference',$ok);
    $p=modeledEarlyRun([['text'=>'Подберите тур. Телефон +7 900 123-45-67, test@example.test, @privatehandle']]);
    $json=json_encode($p,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $check('derived signal packet persists references and counters without customer text',
        !str_contains($json,'123-45-67')&&!str_contains($json,'test@example.test')&&!str_contains($json,'privatehandle')
        &&str_contains($json,'journal:1')&&!str_contains($json,'archive_message:'));
    $receipt=ModeledConversionEarlyForecast::accumulate(ModeledConversionEarlyForecast::emptyReceipt(),$p);
    $check('aggregate receipt counts snapshots rather than claiming unique conversions',
        $receipt['snapshots']===1&&$receipt['initial_observations']===1&&$receipt['early_assessments']===1
        &&!str_contains(json_encode($receipt),'journal:')&&!str_contains(json_encode($receipt),'customer_key'));
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $passed=0;$check=static function(string $name,bool $ok)use(&$passed){if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;};
    modeledEarlyAssertions($check);echo 'MODELED EARLY FORECAST PASSED '.$passed.PHP_EOL;
}

