<?php
declare(strict_types=1);

function modeledExpertWrapper(array $subject,int $now,int $budget,bool $office=false,bool $hold=false,?string $holdText=null,bool $posted=false):array
{
    $freeze=gmdate('Y-m-d\TH:i:s\Z',$now);$messages=[['customer','Подберите тур в Турцию на 7 ночей. Бюджет '.$budget.' рублей на всех.']];
    if($office)$messages[]=['customer','Где находится ваш офис?'];
    if($hold){$messages[]=['customer','Оплатила'];$messages[]=['manager',$holdText??'Денежные средства заморожены, списания еще нет.'];
        if($posted)$messages[]=['manager','Денежные средства успешно списаны.'];}
    $model=new ModeledConversionSemanticPrediction($subject,$freeze,$freeze,$freeze,'1000','1000',null,true);
    $state=ArchiveResearchSemanticExtractor::initialState();$evidence=[];$rows=[];$events=[];
    foreach($messages as$i=>[$role,$text]){
        $id=(string)(101+$i);$ref='journal:'.$id;$at=$now-200+$i*10;$available=$at+1;
        $r=ArchiveResearchSemanticExtractor::step(['source_ref'=>$ref,'created_at'=>$at,'read_status'=>'available','text'=>$text,
            'role'=>$role,'direction'=>$role==='customer'?'inbound':'outbound','role_verified'=>true,'text_minimized'=>true,'forwarded'=>false,
            'media'=>[],'media_contents_read'=>false,'selection_links_unread'=>false,'links_redacted'=>false],$state);
        $state=$r['state'];$model->observeMessage($role,$text,$ref,$at,$available,1,$r);$events=[...$events,...$r['events']];
        $atText=gmdate('Y-m-d\TH:i:s\Z',$at);$availableText=gmdate('Y-m-d\TH:i:s\Z',$available);
        $reference=['source'=>'message','id'=>$ref,'at'=>$atText,'available_at'=>$availableText];
        $evidence[]=['id'=>'message:'.$ref,'subject'=>$subject,'kind'=>$role.'_message','at'=>$atText,'available_at'=>$availableText,'references'=>[$reference]];
        $rows[]=['journal_id'=>$id,'source_kind'=>'message','source_id'=>$id,'source_version_no'=>1,'observed_at_unix'=>$available,
            'snapshot'=>$subject+['source_kind'=>'message','source_id'=>$id,'source_status'=>'present','payload_status'=>'copied',
                'source_created_at_unix'=>$at,'source_timestamp_basis'=>'database_session_timezone_to_unix',
                'payload'=>['text'=>$text,'direction'=>$role==='customer'?'inbound':'outbound','sender_type'=>$role,'metadata_json'=>'{"type":"message"}']]];
    }
    $w=['subject'=>$subject,'access_status'=>'allowed','profile'=>['prediction_at'=>$freeze,
        'observation_input'=>['subject'=>$subject,'evidence'=>$evidence],
        'fields'=>['budget'=>['status'=>'known','usable_at_prediction'=>true,'available_at'=>$freeze,
            'value'=>['minimum'=>$budget,'maximum'=>$budget,'currency'=>'RUB','unit'=>'party_total'],'references'=>$evidence[0]['references']]]],
        'processing'=>['source_sha256'=>hash_file('sha256',dirname(__DIR__).'/services/ModeledConversionProfiles.php')],
        'contextual_signals'=>['version'=>ModeledConversionEarlyForecast::VERSION,'extractor_sha256'=>hash_file('sha256',dirname(__DIR__).'/services/ArchiveResearchSemanticExtractor.php'),
            'adapter_sha256'=>hash_file('sha256',dirname(__DIR__).'/services/ModeledConversionEarlyForecast.php'),
            'frozen_at'=>$freeze,'input_cutoff_at'=>$freeze,'events'=>$events]];
    $w['semantic_prediction']=$model->finish(['early_forecast'=>['status'=>'early_assessment']]);
    $w['deal_value_prediction']=ModeledConversionDealValue::freeze($w,['subject'=>$subject,'rows'=>$rows]);
    return $w;
}
function modeledUnknownPriceWrapper(array $w,bool $fallback=false):array
{
    unset($w['profile']['fields']['budget']);
    if($fallback){$w['semantic_prediction']['status']='insufficient_context';$w['semantic_prediction']['estimated_probability']=null;
        $w['semantic_prediction']['base_probability']=null;$w['semantic_prediction']['hypothesis_odds_multiplier']=null;
        $w['semantic_prediction']['prediction_digest']=ModeledConversionSemanticPrediction::digest($w['semantic_prediction']);}
    $w['deal_value_prediction']=ModeledConversionDealValue::freeze($w,['rows'=>[]]);return$w;
}
