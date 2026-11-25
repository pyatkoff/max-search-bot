<?php
declare(strict_types=1);
use function AnyTour\ExistingPostBatch\{maxTransferRead,maxTransferNativeEditor};

function maxBatchNeedNative(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException($reason);
}
function maxBatchObservePair(PDO $pdo, ChannelPostRegistryService $registry, array $channel, array $r): array {
    $id = $r['hotel_id'];
    $publication = $registry->publication($r['source_id']);
    maxBatchNeedNative(is_array($publication) && $publication['project_key'] === 'anytour' && $publication['platform'] === 'max'
        && $publication['bot_key'] === $channel['bot_key'] && $publication['channel_id'] === '-80492244209683'
        && $publication['state'] === 'sent' && $publication['message_id'] === $r['source_mid'], 'source_registry_identity');
    $mid = $publication['message_id'];
    [$source, $snap] = maxTransferRead(['message_id'=>$mid,'url'=>$r['source_url'],'channel_id'=>'-80492244209683']);
    $photoCount = $r['target_photo_count'] ?? 1;
    if ($photoCount === 1) {
        [$target, $before] = maxTransferRead(['message_id'=>$r['target_mid'],'url'=>$r['target_url'],'channel_id'=>'-68765999669267']);
    } else {
        $read = maxEditRead($r['target_mid'], MAIN_MAX);
        $target = $read['message'];
        $before = $read['snapshot'];
        maxBatchNeedNative(count(maxEditPhotos($before)) === $photoCount
            && $before['post_url'] === $r['target_url'], 'reviewed_target_photo_count_changed');
    }
    $photo=$snap['attachments'][0];
    $binding = $publication['snapshot']['metadata']['hotel_catalog_id'] ?? null;
    $button = ChannelPostButtonTransport::buttonUrl($publication);
    $resolved = ChannelPostEntryService::resolve('max',$publication['start_payload'],$registry);
    $q=$pdo->prepare('SELECT COUNT(*) FROM post_publications WHERE post_channel_id=4 AND external_message_id=?');
    $q->execute([$r['target_mid']]); $occupied=(int)$q->fetchColumn();$q->closeCursor();
    $q=$pdo->prepare("SELECT COUNT(*) FROM post_versions WHERE publication_id=? AND (state IN ('claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL)");
    $q->execute([$r['source_id']]);$claimed=(int)$q->fetchColumn();$q->closeCursor();
    $q=$pdo->prepare("SELECT COUNT(*) FROM post_publications p JOIN post_versions v ON v.id=p.current_version_id AND v.publication_id=p.id WHERE p.post_channel_id=4 AND (JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id'))=? OR p.external_message_id=?)");
    $q->execute([(string)$id,$r['target_mid']]);$conflicts=(int)$q->fetchColumn();$q->closeCursor();
    $nativeText=$snap['text'];$reg=$publication['snapshot'];
    $buttons=[];foreach ($snap['keyboard']??[] as $buttonRow) foreach ($buttonRow as $key) $buttons[]=$key;
    $oneRegistryUrl=count($buttons)===1 && $button!=='' && ($buttons[0]['url']??'')===$button
        && (!isset($buttons[0]['type'])||$buttons[0]['type']==='link') && trim((string)($buttons[0]['text']??''))!=='';
    $samePublication=is_array($resolved)&&(int)($resolved['publication_id']??0)===$r['source_id']&&($resolved['message_id']??null)===$mid;
    $hotelNameInNative=mb_stripos(mb_substr($nativeText,0,300),$r['name'])!==false;
    $targetMatches=(int)$target['timestamp']===$r['target_ms'] && !$before['keyboard'] && empty($target['link'])
        && hash_equals($r['target_fingerprint'],ChannelPostIncomingAdapter::contentFingerprint($before));
    $result=['hotel_id'=>$id,'source_id'=>$r['source_id'],'source_mid'=>$mid,'source_version'=>$publication['version_id'],
        'source_content_sha256'=>$publication['content_hash'],'source_photo_sha256'=>hash('sha256',(string)($photo['photo_id']??'')),
        'source_caption_sha256'=>hash('sha256',$nativeText),'source_caption_nonempty'=>trim($nativeText)!=='',
        'native_source_date_ms'=>$source['timestamp'],'native_source_photo_count'=>count($snap['attachments']),
        'native_source_photo_reusable'=>!empty($photo['photo_id'])&&is_string($photo['token']??null)&&preg_match('/\A[\x21-\x7e]{1,2048}\z/D',$photo['token'])===1,
        'source_caption_matches_registry'=>($reg['text']??null)===$nativeText,
        'source_photo_matches_registry'=>(string)($reg['attachments'][0]['photo_id']??'')===(string)($photo['photo_id']??''),
        'source_hotel_id_matches'=>(int)$binding===$id,
        'source_catalog_id'=>(int)$binding,'source_name_in_native_caption'=>$hotelNameInNative,
        'source_button_count'=>count($buttons),'source_one_button_matches_url'=>$oneRegistryUrl,
        'source_button_resolves_publication'=>$samePublication,
        'readable_source_hotel_identity'=>(int)$binding===$id||((int)$binding===0&&$hotelNameInNative),
        'source_single_registry_button'=>$button!=='' && ChannelPostIncomingAdapter::containsOnlyButton($snap,$button,(string)$publication['channel']['button_text']),
        'source_button_resolves_to_hotel'=>is_array($resolved)&&(int)($resolved['publication_id']??0)===$r['source_id']
          && ($resolved['message_id']??null)===$mid && (int)($resolved['snapshot']['metadata']['hotel_catalog_id']??0)===$id,
        'target_mid'=>$r['target_mid'],'target_url'=>$r['target_url'],'target_matches_native_archive'=>$targetMatches,
        'target_occupied'=>$occupied,'source_held'=>$claimed,'target_or_hotel_registry_conflicts'=>$conflicts];
    $checks=['source_caption_nonempty','native_source_photo_reusable','source_caption_matches_registry','source_photo_matches_registry','source_hotel_id_matches','source_single_registry_button','source_button_resolves_to_hotel','target_matches_native_archive'];
    $missing=array_values(array_filter($checks,static fn($k)=>!$result[$k]));
    foreach (['target_occupied','source_held','target_or_hotel_registry_conflicts'] as $k) if($result[$k])$missing[]=$k;
    $result['blockers']=$missing;
    return $result;
}
