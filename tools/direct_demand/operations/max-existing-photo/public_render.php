<?php
declare(strict_types=1);

function maxRenderPayload(string $text,string $digest): array
{
    maxEditNeed(hash_equals($digest,hash('sha256',$text)) && substr_count($text,'&')===1 && substr_count($text,'Adam & Eve')===1
        && str_starts_with($text,"✈️ Туры в Adam & Eve\n") && !preg_match('~[<>]|(?:https?://)|(?:[₽$€])~u',$text),'render_caption_outside_scope');
    return ['text'=>$text,'format'=>'html'];
}

function maxRenderPublic(string $html,array $p): string
{
    $dom=new DOMDocument(); $previous=libxml_use_internal_errors(true);
    try { maxEditNeed($dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING),'public_card_html_invalid'); }
    finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
    $x=new DOMXPath($dom);
    $class=static fn(string $name): string=>"contains(concat(' ',normalize-space(@class),' '),' ".$name." ')";
    $cards=$x->query('//*['.$class('card').']');
    maxEditNeed($cards->length===1,'public_card_identity_changed');
    $card=$cards->item(0);$a=$x->query('.//*['.$class('footnote').']',$card);$content=$x->query('.//*['.$class('description').']',$card);
    maxEditNeed($a->length===1 && trim($a->item(0)->textContent)==='@anytour' && $content->length===1,'public_card_identity_changed');
    $area=$content->item(0);$images=$x->query('.//img['.$class('attachmentThumb').']',$area);
    maxEditNeed($images->length===1 && hash_equals($p['public_photo_sha256'],hash('sha256',$images->item(0)->getAttribute('src'))),'public_photo_changed');
    foreach (iterator_to_array($x->query('.//br',$area)) as $br) $br->parentNode->replaceChild($dom->createTextNode("\n"),$br);
    return trim($area->textContent);
}

function maxRenderLive(PDO $pdo, ChannelPostRegistryService $reg, array $row, array $proof, string $text): void
{
    $p=$proof['post'];$hotel=$row['hotel_id'];
    $entry=$reg->publication($p['publication_id']);
    maxEditNeed(is_array($entry) && $entry['project_key']===MAX_PROJECT && $entry['platform']==='max' && $entry['bot_key']===MAX_BOT
        && $entry['channel_id']===MAIN_MAX && (int)$entry['version_id']===$p['version_id'] && $entry['state']==='sent'
        && $entry['external_message_id']===$row['target_mid'] && ($entry['snapshot']['post_url']??null)===$p['target']
        && (int)($entry['snapshot']['metadata']['hotel_catalog_id']??0)===$hotel && $entry['snapshot']['text']===$text,'registry_target_changed');
    $busy=$pdo->prepare("SELECT COUNT(*) FROM post_versions WHERE publication_id=? AND (state IN ('claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL)");
    $busy->execute([$p['publication_id']]);$count=(int)$busy->fetchColumn();$busy->closeCursor();
    maxEditNeed($count===0,'target_owned_by_another_editor');
    $channel=$reg->channel(MAX_PROJECT,'max',MAX_BOT,MAIN_MAX);
    maxEditNeed(is_array($channel) && (int)$channel['id']===MAIN_REGISTRY && (int)$channel['config_version']===2,'target_channel_changed');
    \AnyTour\ExistingPostBatch\maxTransferNativeEditor($channel,['channel_id'=>MAIN_MAX,'config_version'=>2]);
    $button=ChannelPostButtonTransport::buttonUrl($entry);
    maxEditNeed(hash_equals($p['button_url_sha256'],hash('sha256',$button)) && hash_equals($p['button_label_sha256'],hash('sha256',$channel['button_text'])),'target_button_changed');
    $seen=maxEditRead($row['target_mid'],MAIN_MAX);
    $resolved=ChannelPostEntryService::resolve('max',$entry['start_payload'],$reg);
    maxRenderVerifyRepresentation($row,$p,$text,$entry,$seen,$resolved,$button,$channel['button_text']);
}

function maxRenderVerifyRepresentation(array $row,array $p,string $text,array $entry,array $seen,?array $resolved,string $button,string $label): void
{
    $snap=$seen['snapshot'];
    maxEditNeed((int)$seen['message']['timestamp']===$p['target_ms'] && $snap['post_url']===$p['target'] && $snap['text']===$text
        && ($snap['markup']??[])===[] && empty($seen['message']['link']),'native_target_or_markup_changed');
    $photos=maxEditPhotos($snap);
    maxEditNeed(count($photos)===1 && hash_equals($p['photo_sha256'],hash('sha256',$photos[0])) && $photos===maxEditPhotos($entry['snapshot'])
        && ChannelPostIncomingAdapter::containsOnlyButton($snap,$button,$label),'native_photo_or_button_changed');
    maxEditNeed(is_array($resolved) && (int)$resolved['publication_id']===$p['publication_id'] && (int)$resolved['version_id']===$p['version_id']
        && (int)($resolved['snapshot']['metadata']['hotel_catalog_id']??0)===$row['hotel_id'] && $resolved['snapshot']['post_url']===$p['target'],'canonical_hotel_context_unconfirmed');
}

function maxRenderMain(array $argv): int
{
    $journal=null;$lease=null;$started=false;
    try {
        maxEditNeed(count($argv)===2 && $argv[1]==='--correct-public-render-949','exact_render_mode_required');
        $scopeBytes=file_get_contents(__DIR__.'/scope.json');
        maxEditNeed(hash_equals(MAX_BATCH_SCOPE_SHA256,hash('sha256',$scopeBytes)),'scope_changed');
        $scope=json_decode($scopeBytes,true,32,JSON_THROW_ON_ERROR);
        $release=$scope['native_source']['application_sha'];maxEditInstalled($release);
        $root=getcwd();
        ob_start();
        try {
            require_once $root.'/config.php';
            require_once $root.'/services/PromotedHotelPriceWriter.php';
            require_once $root.'/services/ChannelPostEntryService.php';
            require_once $root.'/services/ChannelPostButtonTransport.php';
            require_once $root.'/tools/anytour_existing_post_max_transfer.php';
        } finally {ob_end_clean();}
        $proofBytes=file_get_contents(__DIR__.'/public-render-949.json');
        maxEditNeed(hash_equals('9323c35e5e6c8622bc5b12e4318e7d783c7a85e4d56f067582e79a0781ccc8b9',hash('sha256',$proofBytes)),'original_receipt_changed');
        $proof=json_decode($proofBytes,true,32,JSON_THROW_ON_ERROR);$p=$proof['post'];$row=array_column($scope['rows'],null,'hotel_id')[949];
        $text=$row['caption_without_price'];$payload=maxRenderPayload($text,$p['caption_sha256']);
        maxEditNeed($row['target_url']===$p['target'] && maxEditUrl($row['target_mid'],MAIN_MAX)===$p['target'] && $p['hotel']===949,'wrong_hotel');
        $ops='/var/www/anytoour/data/channel-post-operations';
        $leasePath=$ops.'/monitor-price-after-collection-v1.jsonl';
        maxEditNeed(is_file($leasePath) && !is_link($leasePath),'editor_lock_unavailable');
        $lease=fopen($leasePath,'rb');maxEditNeed(is_resource($lease) && flock($lease,LOCK_EX|LOCK_NB),'editor_busy');
        $dir=$ops.'/anytour-1566-main-max-'.substr(hash('sha256',MAIN_MAX.'|'.$row['target_mid']),0,36);
        maxEditNeed(realpath($dir)===$dir && is_file($dir.'/receipt.jsonl') && !is_link($dir.'/receipt.jsonl'),'original_journal_missing');
        $lines=file($dir.'/receipt.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        $original=json_decode(end($lines),true,32,JSON_THROW_ON_ERROR);
        maxEditNeed($original['state']==='existing_max_message_updated_and_verified' && $original['publication_id']===$p['publication_id']
            && $original['version_id']===$p['version_id'] && $original['target_mid']===$row['target_mid'] && $original['button_url_sha256']===$p['button_url_sha256']
            && $original['caption_sha256']===$p['caption_sha256'] && $original['source_photo_sha256']===$p['photo_sha256'],'original_journal_changed');
        $pdo=ConversationDb::connection();$registry=new ChannelPostRegistryService($pdo);
        maxRenderLive($pdo,$registry,$row,$proof,$text);
        $public=static fn(): string=>maxRenderPublic(PromotedHotelPriceWriter::publicBytes($p['target']),$proof);
        $before=$public();
        if ($before===$text) {echo maxEditJson(['state'=>'public_already_exact','provider_writes'=>0]),"\n";return 0;}
        maxEditNeed(hash_equals($proof['public_caption_before_sha256'],hash('sha256',$before))
            && str_replace('Adam &amp; Eve','Adam & Eve',$before)===$text,'public_preimage_changed');
        maxEditInstalled($release);
        $journalPath=$dir.'/public-render-ampersand.jsonl';
        maxEditNeed(!file_exists($journalPath) && !is_link($journalPath),'render_attempt_exists_no_replay');
        $journal=fopen($journalPath,'x+b');maxEditNeed(is_resource($journal),'render_attempt_exists_no_replay');
        maxEditReceipt($journal,['state'=>'text_representation_write_started','hotel'=>949,'mid'=>$row['target_mid'],'previous_public_sha256'=>hash('sha256',$before),'caption_sha256'=>$p['caption_sha256']]);
        $started=true;
        $reply=ChannelPostApiTransport::captionRequest('max',MAIN_MAX,$row['target_mid'],$payload);
        $ack=$reply['http']===200 && !$reply['transport_error'] && ($reply['data']['success']??null)===true;
        maxEditReceipt($journal,['state'=>'provider_response_received','explicit_success'=>$ack,'http'=>$reply['http'],'transport_error'=>$reply['transport_error']]);
        maxRenderLive($pdo,$registry,$row,$proof,$text);
        $after=$public();
        $state=$after===$text?'target_public_and_native_exact':($ack?'native_preserved_public_rendering_persists':'uncertain_no_retry');
        $out=['state'=>$state,'hotel'=>949,'target'=>$p['target'],'publication'=>$p['publication_id'],'version'=>$p['version_id'],'native_caption_sha256'=>$p['caption_sha256'],
            'public_caption_sha256'=>hash('sha256',$after),'physical_photo_sha256'=>$p['photo_sha256'],'button_url_sha256'=>$p['button_url_sha256'],'provider_write_attempts'=>1,'explicit_success'=>$ack,'new_posts'=>0,'ad_writes'=>0];
        maxEditReceipt($journal,$out);echo maxEditJson($out),"\n";return 0;
    } catch(Throwable $e) {
        $reason=preg_match('/\A[a-z_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'operation_failed';
        $out=['state'=>$started?'uncertain_no_retry':'stopped_before_edit','reason'=>$reason,'provider_write_attempts'=>$started?1:0];
        if(is_resource($journal)) maxEditReceipt($journal,$out);
        echo maxEditJson($out),"\n";return 2;
    } finally {if(is_resource($journal)) fclose($journal);if(is_resource($lease)) fclose($lease);}
}
