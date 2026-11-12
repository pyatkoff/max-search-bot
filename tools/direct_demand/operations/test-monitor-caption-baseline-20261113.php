<?php
declare(strict_types=1);
require_once ($argv[1]??__DIR__.'/monitor-price-hook-contract.php');

$checks=0;
function check(bool $ok,string $case):void {
    global $checks;
    if(!$ok)throw new RuntimeException('FAIL '.$case);
    $checks++;
}
function receipt_for(array $p,string $platform,string $record):array {
    return ['platform'=>$platform,'state'=>'sent','publication_id'=>(int)$p['publication_id'],
        'version_id'=>(int)$p['version_id'],'message_id'=>$p['external_message_id'],
        'post_url'=>$p['snapshot']['post_url'],'text'=>$p['snapshot']['text'],
        'text_sha256'=>hash('sha256',$p['snapshot']['text']),
        'existing_message_reused'=>true,'body_photo_button_verified'=>true,
        'monitor_price'=>['record_id'=>$record]];
}
function journal_for(array $p,array $row,string $record):array {
    $posts=[];
    foreach($p as $platform=>$publication)$posts[$platform]=['state'=>'confirmed',
        'receipt'=>receipt_for($publication,$platform,$record)];
    return ['complete'=>true,'operation'=>'commercial-monitor-price-h'.$row['tourvisor_hotel_id'].'-'.$record,
        'feed_binding'=>$row['feed_binding'],'posts'=>$posts];
}

$record=str_repeat('a',64);$nextRecord=str_repeat('b',64);
$operation='commercial-caption-repair-fixture-v1';
$old="✈️ Туры в Fixture Hotel\n📍 Тест\n\nСтарое описание\n\nУзнать стоимость тура";
$new="✈️ Туры в Fixture Hotel\n📍 Тест\n\nИсправленное описание\n\nУзнать стоимость тура";
$row=['tourvisor_hotel_id'=>123,'expected_text_sha256'=>array_fill_keys(['max','telegram'],hash('sha256',$old)),
    'feed_binding'=>['tourvisor_hotel_id'=>123,'posts'=>['max'=>'https://max.ru/fixture/1','telegram'=>'https://t.me/fixture/1']]];
$publications=[];$corrected=[];
foreach(['max','telegram'] as $index=>$platform){
    $publications[$platform]=['publication_id'=>501+$index,'version_id'=>10+$index,
        'external_message_id'=>'message-'.$platform,
        'snapshot'=>['text'=>$old,'post_url'=>$row['feed_binding']['posts'][$platform],
            'metadata'=>['monitor_price_record_id'=>$record]]];
    $corrected[$platform]=$publications[$platform];
    $corrected[$platform]['version_id']=20+$index;
    $corrected[$platform]['snapshot']['text']=$new;
    $corrected[$platform]['snapshot']['metadata']['caption_correction_operation']=$operation;
}
$prior=journal_for($publications,$row,$record);
$baselineRow=$row;
$baselineRow['expected_text_sha256']=array_fill_keys(['max','telegram'],hash('sha256',$new));
foreach($corrected as $platform=>$p)$baselineRow['caption_correction_baseline'][$platform]=[
    'publication_id'=>$p['publication_id'],'version_id'=>$p['version_id'],
    'text_sha256'=>hash('sha256',$new),'operation'=>$operation];

foreach(['max','telegram'] as $platform){
    $p=$publications[$platform];$c=$corrected[$platform];
    $legacy=$p;unset($legacy['snapshot']['metadata']['monitor_price_record_id']);
    check(monitor_hook_caption_allowed($legacy,$row,$platform,null),'legacy_exact_'.$platform);
    $bad=$legacy;$bad['snapshot']['text'].=' manual';
    check(!monitor_hook_caption_allowed($bad,$row,$platform,null),'legacy_manual_'.$platform);
    check(monitor_hook_caption_allowed($p,$row,$platform,$prior),'prior_exact_'.$platform);
    check(!monitor_hook_caption_allowed($c,$row,$platform,$prior),'correction_without_baseline_'.$platform);
    check(monitor_hook_caption_allowed($c,$baselineRow,$platform,$prior),'approved_correction_'.$platform);

    $bad=$c;$bad['snapshot']['text'].=' manual';
    check(!monitor_hook_caption_allowed($bad,$baselineRow,$platform,$prior),'wrong_hash_'.$platform);
    $bad=$c;$bad['version_id']++;
    check(!monitor_hook_caption_allowed($bad,$baselineRow,$platform,$prior),'wrong_version_'.$platform);
    $bad=$c;$bad['publication_id']++;
    check(!monitor_hook_caption_allowed($bad,$baselineRow,$platform,$prior),'wrong_publication_'.$platform);
    $bad=$c;$bad['snapshot']['metadata']['caption_correction_operation']='commercial-other-fixture-v1';
    check(!monitor_hook_caption_allowed($bad,$baselineRow,$platform,$prior),'wrong_operation_'.$platform);
    $bad=$c;unset($bad['snapshot']['metadata']['caption_correction_operation']);
    check(!monitor_hook_caption_allowed($bad,$baselineRow,$platform,$prior),'missing_operation_'.$platform);
    $bad=$c;unset($bad['snapshot']['metadata']['monitor_price_record_id']);
    check(!monitor_hook_caption_allowed($bad,$baselineRow,$platform,$prior),'record_cannot_be_cleared_'.$platform);
    $bad=$c;$bad['snapshot']['metadata']['monitor_price_record_id']='UNKNOWN';
    check(!monitor_hook_caption_allowed($bad,$baselineRow,$platform,$prior),'invalid_record_'.$platform);
    check(!monitor_hook_caption_allowed($c,$baselineRow,$platform,null),'missing_journal_'.$platform);
    $bad=$prior;$bad['complete']=false;
    check(!monitor_hook_caption_allowed($c,$baselineRow,$platform,$bad),'unknown_journal_'.$platform);
    $bad=$prior;$bad['operation']='commercial-monitor-price-h124-'.$record;
    check(!monitor_hook_caption_allowed($c,$baselineRow,$platform,$bad),'wrong_journal_operation_'.$platform);
    $bad=$prior;$bad['feed_binding']['tourvisor_hotel_id']=124;
    check(!monitor_hook_caption_allowed($c,$baselineRow,$platform,$bad),'wrong_journal_binding_'.$platform);
    foreach(['message_id'=>'other','post_url'=>'https://t.me/other/1',
        'existing_message_reused'=>false,'body_photo_button_verified'=>false,
        'text_sha256'=>str_repeat('0',64)] as $key=>$value){
        $bad=$prior;$bad['posts'][$platform]['receipt'][$key]=$value;
        check(!monitor_hook_caption_allowed($c,$baselineRow,$platform,$bad),'prior_receipt_'.$key.'_'.$platform);
    }
    $bad=$prior;$bad['posts'][$platform]['receipt']['monitor_price']['record_id']=$nextRecord;
    check(!monitor_hook_caption_allowed($c,$baselineRow,$platform,$bad),'prior_price_record_changed_'.$platform);
    $bad=$baselineRow;unset($bad['caption_correction_baseline'][$platform==='max'?'telegram':'max']);
    check(!monitor_hook_caption_allowed($c,$bad,$platform,$prior),'partial_baseline_'.$platform);
    $bad=$baselineRow;$bad['caption_correction_baseline'][$platform]['text_sha256']=str_repeat('0',64);
    check(!monitor_hook_caption_allowed($c,$bad,$platform,$prior),'baseline_wrong_hash_'.$platform);
    $bad=$baselineRow;$bad['caption_correction_baseline'][$platform]['operation']='commercial-other-fixture-v1';
    check(!monitor_hook_caption_allowed($c,$bad,$platform,$prior),'baseline_operation_mismatch_'.$platform);
}

// The next ordinary monitor update uses the corrected template and its own journal.
$template="✈️ Туры в Fixture Hotel\n📍 Тест\n\nИсправленное описание\n\n"
    ."💰 [Минимальная актуальная цена тура — подтвердить перед выпуском]\n"
    ."[Город вылета · даты · ночи · состав туристов · номер · питание · время проверки]\n\nУзнать стоимость тура";
$price=['record_id'=>$nextRecord,'tourvisor_hotel_id'=>123,'price'=>123456,
    'observed_at'=>'2026-11-12T20:00:00Z','offer'=>['price'=>123456,'nights'=>7,
        'departureDate'=>'2026-12-01','roomType'=>'Standard','mealId'=>7]];
$draft=monitor_hook_draft($template,$price);
check(str_contains($draft,'Исправленное описание')&&!str_contains($draft,'Старое описание'),'new_template_preserves_editorial_copy');
check(str_contains($draft,'123 456 ₽')&&str_contains($draft,'01.12.2026'),'next_price_rendered');
$next=$corrected;
foreach($next as &$p){
    $p['version_id']+=10;$p['snapshot']['text']=$draft;
    $p['snapshot']['metadata']['monitor_price_record_id']=$nextRecord;
}unset($p);
$nextPrior=journal_for($next,$row,$nextRecord);
foreach(['max','telegram'] as $platform){
    check(monitor_hook_caption_allowed($next[$platform],$baselineRow,$platform,$nextPrior),'subsequent_normal_monitor_'.$platform);
    $bad=$next[$platform];$bad['snapshot']['text'].=' manual';
    check(!monitor_hook_caption_allowed($bad,$baselineRow,$platform,$nextPrior),'subsequent_manual_edit_'.$platform);
}
if(isset($argv[2])){
    // Exercise the real Python helper output, not a second copy of its template logic.
    $fixture=json_decode(file_get_contents($argv[2]),true,64,JSON_THROW_ON_ERROR);
    $prepared=$fixture['roster']['rows'][0];$before=[];$after=[];
    foreach(['max','telegram'] as $platform){
        $approval=$fixture['approval']['rows'][0]['channel_texts'][$platform];
        $before[$platform]=$publications[$platform];$before[$platform]['snapshot']['text']=$approval['old_text'];
        $after[$platform]=$corrected[$platform];$after[$platform]['snapshot']['text']=$approval['new_text'];
    }
    $oldJournal=journal_for($before,$prepared,$record);
    foreach(['max','telegram'] as $platform){
        check(monitor_hook_caption_allowed($after[$platform],$prepared,$platform,$oldJournal),'helper_baseline_integrated_'.$platform);
        $rendered=monitor_hook_draft($prepared['templates'][$platform],$price);
        check(str_contains($rendered,'Исправленное описание')&&!str_contains($rendered,'Старое описание'),
            'helper_template_next_monitor_'.$platform);
    }
}
try{
    monitor_hook_parse_journal('{"complete":true}', $prior['operation']);
    check(false,'partial_journal_throws');
}catch(RuntimeException $e){
    check($e->getMessage()==='hook_incomplete_journal_stop','partial_journal_stays_blocked');
}
echo json_encode(['ok'=>true,'checks'=>$checks,'php_version'=>PHP_VERSION,'external_writes'=>0],JSON_THROW_ON_ERROR)."\n";
