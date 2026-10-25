<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerTourSelectionDraft.php';
require_once __DIR__.'/TourOfferCardRenderer.php';

/** Complete customer-visible terms, separate from permissive editable drafts. */
final class ManagerTourSelectionPublicationDraft
{
    public static function build(array $draft): array
    {
        $draft=ManagerTourSelectionDraft::normalize($draft);
        if ($draft===null) return ['ok'=>false,'error'=>'invalid_draft'];
        $offers=[]; $errors=[];
        foreach ($draft['offers'] as $index=>$offer) {
            if (!$offer['selected']) continue;
            $missing=[];
            foreach (['hotel','departure','date_from','nights','adults','room','meal','amount'] as $key) if ($offer[$key]===null || $offer[$key]==='') $missing[]=$key;
            $gallery=isset($offer['import_source']['photos']);
            $kinds=array_column($offer['photos'],'kind');
            if (!$kinds || count(array_unique($kinds))!==count($kinds) || (!$gallery && $kinds!==['cover','beach','room'])) $missing[]='photos';
            if ($missing!==[]) {$errors[]=['offer_id'=>$offer['id'],'position'=>$index+1,'fields'=>$missing];continue;}
            // A manually supplied room image is not verified exact-room evidence.
            foreach ($offer['photos'] as &$photo) if ($photo['kind']==='room') $photo['illustrative']=true;
            unset($photo);
            $until=(new DateTimeImmutable($offer['date_from'],new DateTimeZone('UTC')))->modify('+'.$offer['nights'].' days')->format('Y-m-d');
            // This is a derived lodging boundary, never a confirmed return flight.
            $snapshot=TourChoiceService::normalize([
                'hotel'=>$offer['hotel'].($offer['stars']?' '.$offer['stars'].'★':''),'departure'=>$offer['departure'],
                'date_from'=>$offer['date_from'],'date_to'=>$until,'date_to_basis'=>'lodging_estimate','nights'=>$offer['nights'],'adults'=>$offer['adults'],'child_ages'=>$offer['child_ages'],
                'room'=>$offer['room'],'meal'=>$offer['meal'],'amount'=>$offer['amount'],'currency'=>$offer['currency'],'price_basis'=>'party',
                'is_demo'=>false,'price_verified'=>false,'flights'=>[],
                'rationale'=>str_replace("\n",' ',$offer['rationale']),'compromise'=>str_replace("\n",' ',$offer['compromise']),
                'quoted_details'=>array_intersect_key($offer,array_flip(['destination','source_label','source_url','observed_at','flights','inclusions','constraints','photos'])),
            ]);
            if ($snapshot===null) {$errors[]=['offer_id'=>$offer['id'],'position'=>$index+1,'fields'=>['conditions']];continue;}
            $presentation=['destination'=>$offer['destination'],'inclusions'=>[],'layout'=>$gallery?'gallery-v1':'departure-v1',
                'room_illustrative'=>$gallery?true:$offer['photos'][2]['illustrative'],
                'photos'=>array_map(static fn(array $photo,int $i):array=>['role'=>$gallery?['cover','beach','room'][$i]:$photo['kind'],'url'=>$photo['url']],$offer['photos'],array_keys($offer['photos']))];
            try {$card=TourOfferCardRenderer::render($snapshot,$presentation,'tour_like_1_'.str_repeat('0',24));}
            catch (InvalidArgumentException $e) {$errors[]=['offer_id'=>$offer['id'],'position'=>$index+1,'fields'=>['photos']];continue;}
            if (preg_match_all('/./us',$card['plain_text'])>3500) return ['ok'=>false,'error'=>'card_too_long','position'=>$index+1];
            $entry=['draft_offer_id'=>$offer['id'],'snapshot'=>$snapshot,'presentation'=>$presentation];
            // Private source observation is frozen for staff history. Public pages
            // continue using their explicit snapshot allowlist, never this field.
            if (isset($offer['import_source'])) $entry['import_source']=$offer['import_source'];
            // Page grouping is publication metadata, not different native quote
            // conditions: regrouping cannot create another native delivery.
            if (isset($offer['hotel_group'])) $entry['hotel_group']=$offer['hotel_group'];
            $offers[]=$entry;
        }
        if ($errors!==[]) return ['ok'=>false,'error'=>'incomplete_offers','fields'=>$errors];
        if ($offers===[]) return ['ok'=>false,'error'=>'empty_selection'];
        return ['ok'=>true,'title'=>$draft['title'],'intro'=>$draft['intro'],'offers'=>$offers];
    }
}
