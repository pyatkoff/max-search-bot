<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerHotelKnowledge.php';

/** New Rubi data only: no SQL fixture, HTTP, provider calls or approval synthesis. */
function hotelRubiKnowledgeChecks(): void
{
    $directory = dirname(__DIR__).'/knowledge/hotels';
    $canonicalMethod = new ReflectionMethod(ManagerHotelKnowledge::class, 'canonical');
    $canonical = static fn($record): string => $canonicalMethod->invoke(null, $record);
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
    $matches = array_values(array_filter($manifest['bindings'] ?? [], static fn(array $entry): bool =>
        ($entry['bundle_file'] ?? null) === 'anytour-5537-rubi-platinum.json'));
    $entry = $matches[0] ?? [];
    $sourceIdentity = ['adapter'=>'tourvisor-snapshot-v1', 'hotel'=>'27426', 'country'=>'4'];
    $hotelRef = ['catalog'=>'anytour', 'id'=>5537];
    $researchKey = 'top500_dca4e4a0c9ae44ca';
    draftContextCheck('Rubi has one explicit Tourvisor27426 Turkey binding through canonical AnyTour5537',
        count($matches) === 1 && ($entry['status'] ?? null) === 'accepted'
        && $canonical($entry['source_identity'] ?? null) === $canonical($sourceIdentity)
        && $canonical($entry['accepted_binding']['hotel_ref'] ?? null) === $canonical($hotelRef)
        && ($entry['accepted_binding']['research_hotel_key'] ?? null) === $researchKey
        && $canonical($entry['accepted_binding']['accepted_chain'] ?? null) === $canonical([
            ['namespace'=>'legacy_catalog/catalog_hotels.id', 'id'=>27426],
            ['namespace'=>'anytour', 'id'=>5537, 'catalog_revision'=>1],
            ['namespace'=>'local-tv', 'id'=>27426, 'catalog_revision'=>3],
        ]));

    $bytes = file_get_contents($directory.'/anytour-5537-rubi-platinum.json');
    $bundle = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
    $hashRecords = static function(array $records) use ($canonical): array {
        $hashes = [];
        foreach ($records as $record) $hashes[$record['id']] = hash('sha256', $canonical($record));
        ksort($hashes, SORT_STRING);
        return $hashes;
    };
    // Independent pins from the retained original records, never from the new
    // bundle's own digest map. ANEX8401 remains in the unchanged S01 provenance.
    $expectedClaims = [
        'top500_dca4e4a0c9ae44ca_c01'=>'8fc96a0ec825c8d45563eaed9deade7d3368d87d2db138ed8143e6e3ff117756',
        'top500_dca4e4a0c9ae44ca_c02'=>'f46d13b730204cd220e1692cb33a4c8379ce0e57ee2aab6b4a9d146f6bda1c34',
        'top500_dca4e4a0c9ae44ca_c03'=>'d1260e48e515507fca7627b1d8f51e2379f64d7f5df694f92ab1746ae9c1eef3',
        'top500_dca4e4a0c9ae44ca_c04'=>'1f3c65d034a3c53405574e67fe96ffda83d64179d2019b599fc1642ba3bb909d',
        'top500_dca4e4a0c9ae44ca_c05'=>'dad3c529f067b9248fdd0c6bf17843c1edc165bbbd8b7b00894754f88b25cfb3',
    ];
    $expectedSources = [
        'top500_dca4e4a0c9ae44ca_s01'=>'0af4a455b35fc3c12651d0815a3bf9ebc1c663165576eb435c1591f35be8dd3d',
    ];
    draftContextCheck('Rubi preserves all five original declarations and the complete ANEX source record',
        count($bundle['facts'] ?? []) === 5 && count($bundle['sources'] ?? []) === 1
        && $hashRecords($bundle['facts']) === $expectedClaims
        && $hashRecords($bundle['sources']) === $expectedSources
        && ($bundle['record_hashes'] ?? null) == ['claims'=>$expectedClaims, 'sources'=>$expectedSources]);

    // Synthetic server provenance exercises this data reader only. It is not a
    // new native inquiry, source observation, current trip or runtime receipt.
    $selector = [
        'project_key'=>'anytour', 'source_identity'=>$sourceIdentity,
        'provenance'=>[
            'kind'=>'publication', 'publication_id'=>str_repeat('a',32), 'card_index'=>0,
            'draft_offer_id'=>str_repeat('b',32), 'publication_sha256'=>str_repeat('c',64),
            'source_sha256'=>str_repeat('d',64),
        ],
        'selected_hotel'=>'RUBI PLATINUM SPA RESORT & SUITES', 'selected_room'=>'Family Suite',
    ];
    $now = strtotime('2026-11-06T00:00:00Z');
    $reference = ManagerHotelKnowledge::capture($selector, $now);
    draftContextCheck('the exact Rubi selector returns the whole conditional group with every material limit',
        ($reference['status'] ?? null) === 'available'
        && $canonical($reference['hotel_ref'] ?? null) === $canonical($hotelRef)
        && ($reference['research_hotel_key'] ?? null) === $researchKey
        && ($reference['facts'] ?? null) === $bundle['facts']
        && ($reference['sources'] ?? null) === $bundle['sources']
        && ($reference['material_limits'] ?? null) === $bundle['material_limits']
        && ($reference['coverage'] ?? null) === $bundle['coverage']
        && ($reference['coverage']['complete'] ?? null) === false
        && ($reference['price_authority'] ?? null) === 'none'
        && ($reference['applicability'] ?? null) === 'conditional_published_information'
        && ($reference['current_trip_binding'] ?? null) === false
        && ($reference['customer_agreement_verified'] ?? null) === false
        && ($reference['current_price_verified'] ?? null) === false
        && ($reference['availability_verified'] ?? null) === false
        && ($reference['room_tariff_equivalence_verified'] ?? null) === false
        && ($reference['meal_plan_equivalence_verified'] ?? null) === false
        && ($reference['future_operation_verified'] ?? null) === false
        && ($bundle['runtime_admission_verified'] ?? null) === false);

    // Isolate this one real binding for collision probes. A future independent
    // TV8401/TV5537 binding for another hotel must remain possible.
    $isolated = $manifest;
    $isolated['bindings'] = [$entry];
    $select = new ReflectionMethod(ManagerHotelKnowledge::class, 'select');
    foreach ([
        ['ANEX8401 cannot act as the Rubi Tourvisor ID', '8401', '4', $selector['selected_hotel'], 'binding_unresolved'],
        ['AnyTour5537 cannot act as the Rubi Tourvisor ID', '5537', '4', $selector['selected_hotel'], 'binding_unresolved'],
        ['Rubi Tourvisor27426 requires its accepted country', '27426', '1', $selector['selected_hotel'], 'binding_unresolved'],
        ['Rubi typed identity still requires source-name consistency', '27426', '4', 'RUBI PLATINUM SIGN', 'source_name_mismatch'],
    ] as [$name,$hotel,$country,$sourceName,$reason]) {
        $changed = $selector;
        $changed['source_identity']['hotel'] = $hotel;
        $changed['source_identity']['country'] = $country;
        $changed['selected_hotel'] = $sourceName;
        $held = $select->invoke(null, $isolated, $bytes, $changed, $now);
        draftContextCheck($name, ($held['status'] ?? null) === 'held' && ($held['reason'] ?? null) === $reason
            && ($held['facts'] ?? null) === [] && ($held['sources'] ?? null) === []);
    }

    draftContextCheck('the complete new Rubi bundle and actual reference fit the existing 16KiB cap',
        ManagerHotelKnowledge::MAX_REFERENCE_BYTES === 16384
        && strlen($bytes) <= 16384 && strlen($canonical($reference)) <= 16384);
}
