<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerHotelOfferReference.php';

/** Uses the existing real normalized source/publication and read-only SQL fixture. */
function hotelOfferReferenceChecks(): void
{
    [$pdo,$publication] = draftHotelFixture();
    $entry = $publication['offers'][0];
    $source = $entry['import_source'];
    $stored = $pdo->query('SELECT binding_json,selection_json FROM manager_tour_selection_publications')->fetch(PDO::FETCH_ASSOC);
    $native = draftHotelReference(draftContextCapture($pdo));
    $provenance = ['kind'=>'publication', 'publication_id'=>str_repeat('a',32), 'card_index'=>0,
        'draft_offer_id'=>$entry['draft_offer_id'],
        'publication_sha256'=>hash('sha256',$stored['binding_json']."\n".$stored['selection_json']),
        'source_sha256'=>ManagerHotelOfferReference::hash($source)];
    $now = strtotime('2026-11-01T00:00:00Z');
    $published = ManagerHotelOfferReference::capture($entry,$provenance,$now);
    $import = ManagerTourSelectionImportDraft::normalize(['adapter'=>$source['adapter'],'url'=>$source['url'],
        'observed_at'=>$source['observed_at'],'quote_id'=>$source['quote_id'],'keys'=>$source['keys'],
        'offer'=>$source['fields'],'raw'=>$source['raw'],'photos'=>$source['photos']]);
    $working = $import['offer'] + ['import_source'=>$source];
    $working = ManagerTourSelectionDraft::normalize(['title'=>'','intro'=>'','offers'=>[$working]])['offers'][0];
    $draftProvenance = static fn(array $offer,int $version=1): array => ['kind'=>'working_draft','draft_version'=>$version,
        'offer_id'=>$offer['id'],'snapshot_sha256'=>ManagerHotelOfferReference::hash($offer),
        'source_sha256'=>ManagerHotelOfferReference::hash($offer['import_source'])];
    $draft = ManagerHotelOfferReference::capture($working,$draftProvenance($working),$now);
    draftContextCheck('shared draft and publication readers preserve the exact native six-fact group and source limitations',
        ($published['status']??'')==='available' && ($draft['status']??'')==='available'
        && $draft['hotel_ref']===['catalog'=>'anytour','id'=>1554]
        && $published['facts']===$native['facts'] && $draft['facts']===$native['facts']
        && $published['sources']===$native['sources'] && $draft['material_limits']===$native['material_limits']
        && count($draft['facts'])===6 && count($draft['sources'])===3);
    draftContextCheck('explicit constructor provenance never fabricates an inquiry or claims a current trip or supplier terms',
        $published['selector_provenance']===$provenance
        && $draft['selector_provenance']===$draftProvenance($working)
        && !isset($draft['selector_provenance']['message_id'],$published['selector_provenance']['event_id'])
        && $draft['current_trip_binding']===false && $draft['customer_agreement_verified']===false
        && $draft['current_price_verified']===false && $draft['availability_verified']===false
        && $draft['room_tariff_equivalence_verified']===false && $draft['meal_plan_equivalence_verified']===false
        && $draft['future_operation_verified']===false && $draft['price_authority']==='none'
        && !array_key_exists('kind',$native['selector_provenance']) && !array_key_exists('current_trip_binding',$native));
    $changed = $working;
    $changed['selected']=false;$changed['room']='Edited different room';$changed['meal']='Edited meal';$changed['amount']='175000';
    $edited = ManagerHotelOfferReference::capture($changed,$draftProvenance($changed,2),$now);
    draftContextCheck('editable inclusion room meal and price never become property identity or verified tariff facts',
        ($edited['status']??'')==='available' && $edited['facts']===$draft['facts']
        && $edited['selected_room']==='Edited different room' && $edited['current_trip_binding']===false
        && $edited['room_tariff_equivalence_verified']===false && $edited['meal_plan_equivalence_verified']===false
        && $edited['knowledge_revision']!==$draft['knowledge_revision']);
    $nextVersion = ManagerHotelOfferReference::capture($working,$draftProvenance($working,2),$now);
    draftContextCheck('same hotel facts retain distinct source revisions across draft saves and immutable publication context',
        $nextVersion['knowledge_revision']!==$draft['knowledge_revision']
        && $published['knowledge_revision']!==$draft['knowledge_revision'] && $nextVersion['facts']===$draft['facts']);

    $badProvenance = [
        'fabricated inquiry on draft'=>$draftProvenance($working)+['message_id'=>1,'event_id'=>999],
        'unknown provenance kind'=>array_replace($provenance,['kind'=>'native_inquiry']),
        'string card index'=>array_replace($provenance,['card_index'=>'0']),
        'card beyond publication bound'=>array_replace($provenance,['card_index'=>20]),
        'negative card index'=>array_replace($provenance,['card_index'=>-1]),
        'missing publication digest'=>array_diff_key($provenance,['publication_sha256'=>true]),
        'wrong draft offer'=>array_replace($provenance,['draft_offer_id'=>str_repeat('f',32)]),
    ];
    foreach ($badProvenance as $name=>$bad) {
        $out=ManagerHotelOfferReference::capture(($bad['kind']??null)==='working_draft'?$working:$entry,$bad,$now);
        draftContextCheck('shared hotel reader refuses '.$name, ($out['status']??'')==='held' && $out['facts']===[] && $out['sources']===[]);
    }
    $staleDraft=$draftProvenance($working);$staleDraft['snapshot_sha256']=str_repeat('0',64);
    $staleSource=$provenance;$staleSource['source_sha256']=str_repeat('0',64);
    draftContextCheck('source and exact working snapshot fences reject stale caller observations',
        (ManagerHotelOfferReference::capture($working,$staleDraft,$now)['reason']??'')==='selector_snapshot_changed'
        && (ManagerHotelOfferReference::capture($entry,$staleSource,$now)['reason']??'')==='selector_source_changed');

    foreach (['hotel'=>'Different hotel','destination'=>'Different destination','source_url'=>'https://example.test/other',
        'observed_at'=>'2026-10-17T11:00:00Z','stars'=>4] as $field=>$value) {
        $bad=$working;$bad[$field]=$value;
        $out=ManagerHotelOfferReference::capture($bad,$draftProvenance($bad),$now);
        draftContextCheck('edited draft '.$field.' cannot retain another imported identity',
            ($out['reason']??'')==='selector_source_identity_changed' && $out['facts']===[]);
    }
    foreach (['hotel','destination','source_url','observed_at'] as $field) {
        $bad=$entry;
        if ($field==='hotel') $bad['snapshot']['hotel']='Different hotel 5★';
        else $bad['snapshot']['quoted_details'][$field]=$field==='observed_at'?'2026-10-17T11:00:00Z':($field==='source_url'?'https://example.test/other':'Different destination');
        $out=ManagerHotelOfferReference::capture($bad,$provenance,$now);
        draftContextCheck('frozen publication '.$field.' must still identify its exact retained source',
            ($out['reason']??'')==='selector_source_identity_changed' && $out['facts']===[]);
    }
    foreach (['missing','malformed','unsupported','raw_mismatch'] as $case) {
        $bad=$entry;
        if ($case==='missing') unset($bad['import_source']);
        elseif ($case==='malformed') $bad['import_source']['fields']='not an object';
        elseif ($case==='unsupported') $bad['import_source']['adapter']='unknown-adapter';
        else $bad['import_source']['raw']['hotel']='Another source hotel 5*';
        $p=$provenance;if (isset($bad['import_source'])) $p['source_sha256']=ManagerHotelOfferReference::hash($bad['import_source']);
        $out=ManagerHotelOfferReference::capture($bad,$p,$now);
        draftContextCheck('unusable '.$case.' source remains unknown with no inferred hotel',
            ($out['status']??'')==='held' && $out['facts']===[] && $out['sources']===[]);
    }
    $oversize=$entry;$oversize['unused']=str_repeat('x',ManagerHotelOfferReference::MAX_ENTRY_BYTES);
    draftContextCheck('oversized entry is refused before source projection',
        (ManagerHotelOfferReference::capture($oversize,$provenance,$now)['reason']??'')==='selector_entry_too_large');
    foreach ([['99999','SUNRISE ARABIAN BEACH RESORT','1','binding_unresolved'],
        ['9374','Different source hotel','1','source_name_mismatch'],
        ['9374','SUNRISE ARABIAN BEACH RESORT','4','binding_unresolved']] as [$id,$name,$country,$reason]) {
        [, $other]=draftHotelFixture($id,$name,$country);$otherEntry=$other['offers'][0];
        $p=$provenance;$p['draft_offer_id']=$otherEntry['draft_offer_id'];$p['source_sha256']=ManagerHotelOfferReference::hash($otherEntry['import_source']);
        $out=ManagerHotelOfferReference::capture($otherEntry,$p,$now);
        draftContextCheck('typed source '.$country.'/'.$id.' and name require their own accepted bridge '.$reason,
            ($out['reason']??'')===$reason && $out['facts']===[]);
    }

    [, $jaz]=draftHotelFixture('268','JAZ MAKADI SARAYA RESORT (EX. IBEROTEL MAKADI SARAYA RESORT)');
    $jazEntry=$jaz['offers'][0];$p=$provenance;$p['draft_offer_id']=$jazEntry['draft_offer_id'];
    $p['source_sha256']=ManagerHotelOfferReference::hash($jazEntry['import_source']);
    $jazOut=ManagerHotelOfferReference::capture($jazEntry,$p,$now);
    $jazBundle=json_decode(file_get_contents(dirname(__DIR__).'/knowledge/hotels/anytour-99-apartments.json'),true,32,JSON_THROW_ON_ERROR);
    draftContextCheck('shared publication reader retains source conflicts and complete material limits as unknowns',
        ($jazOut['status']??'')==='available' && $jazOut['facts']===$jazBundle['facts']
        && $jazOut['material_limits']===$jazBundle['material_limits']
        && !empty($jazOut['material_limits']['source_conflicts']) && $jazOut['coverage']['complete']===false);

    // Pure integrity/validity probes: never modify an approved repository file.
    $manifest=json_decode(file_get_contents(dirname(__DIR__).'/knowledge/hotels/manifest.json'),true,32,JSON_THROW_ON_ERROR);
    $bundle=json_decode(file_get_contents(dirname(__DIR__).'/knowledge/hotels/'.$manifest['bindings'][0]['bundle_file']),true,32,JSON_THROW_ON_ERROR);
    $select=new ReflectionMethod(ManagerHotelKnowledge::class,'select');
    $canonical=new ReflectionMethod(ManagerHotelKnowledge::class,'canonical');
    $selector=ManagerHotelOfferReference::selector($entry,$provenance);
    $expired=$bundle;$expired['valid_until']='2026-10-31T23:59:59Z';
    $bytes=$canonical->invoke(null,$expired);$m=$manifest;$m['bindings'][0]['bundle_sha256']=hash('sha256',$bytes);
    $stale=$select->invoke(null,$m,$bytes,$selector,$now);
    draftContextCheck('new publication provenance cannot bypass explicit knowledge expiry',
        ($stale['reason']??'')==='outside_explicit_validity' && $stale['facts']===[]);
    $corrupt=$bundle;$corrupt['facts'][0]['text']='Changed unapproved declaration';
    $bytes=$canonical->invoke(null,$corrupt);$m=$manifest;$m['bindings'][0]['bundle_sha256']=hash('sha256',$bytes);
    $invalid=$select->invoke(null,$m,$bytes,$selector,$now);
    draftContextCheck('matching outer bytes never excuse a changed unapproved fact record',
        ($invalid['reason']??'')==='bundle_invalid' && $invalid['facts']===[]);

    [$pdo]=draftHotelFixture();
    $pdo->exec("INSERT INTO messages VALUES(2,1,'inbound','customer','А какой пляж у этого отеля?','2026-10-17 12:02:00','telegram',NULL,'PRIVATE','PRIVATE')");
    $continued=draftHotelReference(draftContextCapture($pdo));
    draftContextCheck('shared fact reader does not revive a native selector after an ordinary same-hotel clarification',
        ($continued['reason']??'')==='current_intent_unbound' && $continued['facts']===[] && $pdo->writeAttempts===0);
}
