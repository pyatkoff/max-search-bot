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
function modeledEarlyAssertions(callable $check):void
{
    $p=modeledEarlyRun([['text'=>'Подберите тур в Турцию. Бюджет до 200 тысяч.']]);
    $f=$p['early_forecast'];
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
        &&in_array('deferral',$p['early_forecast']['contrary_goal_ids'],true));
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
