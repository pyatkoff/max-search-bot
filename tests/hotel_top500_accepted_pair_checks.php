<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerHotelKnowledge.php';
require_once dirname(__DIR__).'/services/ManagerAiDraftService.php';

/** Two accepted TOP500 projections only; no SQL, HTTP or approval creation. */
function hotelTop500AcceptedPairChecks(): void
{
    $directory = dirname(__DIR__).'/knowledge/hotels';
    $canonicalMethod = new ReflectionMethod(ManagerHotelKnowledge::class, 'canonical');
    $canonical = static fn($record): string => $canonicalMethod->invoke(null, $record);
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
    $select = new ReflectionMethod(ManagerHotelKnowledge::class, 'select');
    $admit = new ReflectionMethod(ManagerAiDraftService::class, 'admitHotelReference');
    $jsonFlags = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
    $hashRecords = static function(array $records) use ($canonical): array {
        $hashes = [];
        foreach ($records as $record) $hashes[$record['id']] = hash('sha256', $canonical($record));
        ksort($hashes, SORT_STRING);
        return $hashes;
    };
    $restoreProjectionAndHash = static function(array $records, array $originalKinds, array $omittedFields) use ($hashRecords): array {
        foreach ($records as &$record) {
            $id = $record['id'] ?? '';
            if (!isset($originalKinds[$id])) return [];
            // Restore only the reviewed kind label and omitted research keys.
            // Independent literals below must recover the whole original record.
            foreach ($omittedFields as $field => $value) {
                if (array_key_exists($field, $record)) return [];
                $record[$field] = $value;
            }
            $record['kind'] = $originalKinds[$id];
        }
        unset($record);
        return $hashRecords($records);
    };

    // Independent pins from working165 original records, checked against each
    // frozen packet pointer. These are not the new bundle's projected hashes.
    $cases = [
        [
            'label'=>'Rox Resort',
            'file'=>'anytour-548-rox-resort.json',
            'research_key'=>'top500_f1261c425e8ee6c5',
            'tv'=>'1216', 'anex'=>'8595', 'anytour'=>548,
            'name'=>'ROX RESORT (EX. ROX ROYAL)',
            'other_name'=>'SIDE STAR RESORT (EX. VERA CLUB LINDITA)',
            'original_run_id'=>'HOTEL-TR-MANUAL-20261025T191157Z-BASE08',
            'original_question_id'=>'HOTEL-TR-MANUAL-20261025T191157Z-BASE08_Q06',
            'claim_hashes'=>[
                'HOTEL-TR-MANUAL-20261025T191157Z-BASE08_c38'=>'3255acb4479277981ea0ea8aba60662c7b7ac1a43b04ab43b4c120546e176d33',
                'HOTEL-TR-MANUAL-20261025T191157Z-BASE08_c39'=>'1b84385f0962db5ebd3b74834e425b6b62e6275b48c5bf85afa57031d38122da',
                'HOTEL-TR-MANUAL-20261025T191157Z-BASE08_c40'=>'b1c2cf8a0358f770fa130f01d0593bc449b4714da6879fce1703b73102371c78',
                'HOTEL-TR-MANUAL-20261025T191157Z-BASE08_c42'=>'10c6295b944e43638ab8c64339d0a2bacf826e4fd85483c1daa2d63b7381a6d9',
                'HOTEL-TR-MANUAL-20261025T191157Z-BASE08_c43'=>'df51cf397067b79ee237630694c968db365378843e81393425f649cb78503af8',
            ],
            'source_hashes'=>[
                'HOTEL-TR-MANUAL-20261025T191157Z-BASE08_s11'=>'f2dc6e7f0feca99f2b4c7e95b0ec07bf66f8667746f748113a1f65b34aff3c80',
                'HOTEL-TR-MANUAL-20261025T191157Z-BASE08_s12'=>'4e6b8c503ced5c5b7b82ab508428a7b47761c9b0a7e43ace07b7ed8f82d0acf1',
            ],
            'source_kinds'=>[
                'HOTEL-TR-MANUAL-20261025T191157Z-BASE08_s11'=>'official',
                'HOTEL-TR-MANUAL-20261025T191157Z-BASE08_s12'=>'official',
            ],
        ],
        [
            'label'=>'Side Star Resort',
            'file'=>'anytour-7434-side-star-resort.json',
            'research_key'=>'top500_fdca49b82e8446ad',
            'tv'=>'1095', 'anex'=>'8476', 'anytour'=>7434,
            'name'=>'SIDE STAR RESORT (EX. VERA CLUB LINDITA)',
            'other_name'=>'SIDE STAR ELEGANCE (EX. ASTERIA SIDE)',
            'original_run_id'=>'HOTEL-TR-MANUAL-20261025T194938Z-BASE06',
            'original_question_id'=>'HOTEL-TR-MANUAL-20261025T194938Z-BASE06_Q02',
            'claim_hashes'=>[
                'HOTEL-TR-MANUAL-20261025T194938Z-BASE06_c03'=>'e256051e0d5b3aa0ee31e6dde292ddf5df6711d6a256eebfd149ab2cfa4c00fb',
                'HOTEL-TR-MANUAL-20261025T194938Z-BASE06_c04'=>'75128325d8b700fa63b408f17315d3e6ea5ef172723de6cd5bb3a6f4e65c12e1',
                'HOTEL-TR-MANUAL-20261025T194938Z-BASE06_c05'=>'6b864f37af1918fb20512f9e4f553559873451637418dcb66279600b44dec9ae',
                'HOTEL-TR-MANUAL-20261025T194938Z-BASE06_c06'=>'38912531c46c5d1c219ac578e1934582f6e68637974f44c3d54a32007a3c31a9',
                'HOTEL-TR-MANUAL-20261025T194938Z-BASE06_c07'=>'719f20b0e58b3d1da27a8014c9b798c6c20210c4bedeba3071fcb4a984917fc1',
                'HOTEL-TR-MANUAL-20261025T194938Z-BASE06_c08'=>'634f009ce9ab236b54ba9b542c5d39f6147a8a86e44f193de2fa4b3d8571cc5a',
                'HOTEL-TR-MANUAL-20261025T194938Z-BASE06_c09'=>'8c356cf44342c09bc887192295aa2f4118c4e1e258150c8398554ddbf0139676',
                'HOTEL-TR-MANUAL-20261025T194938Z-BASE06_c10'=>'d6638f2063f859753c3b6d6ca147343cf53040a9ca53a7e16b4d4abb0b189ba4',
            ],
            'source_hashes'=>[
                'HOTEL-TR-MANUAL-20261025T194938Z-BASE06_s03'=>'7dc6f7378dcaa489250c97f00141b6ab740489c2a41d0931cbd1b0bf9dfa4daa',
                'HOTEL-TR-MANUAL-20261025T194938Z-BASE06_s04'=>'1d436a681993c6edd4c58643947f9401f1b1b900bfe9be850e60a55b662c45a8',
            ],
            'source_kinds'=>[
                'HOTEL-TR-MANUAL-20261025T194938Z-BASE06_s03'=>'official',
                'HOTEL-TR-MANUAL-20261025T194938Z-BASE06_s04'=>'official_document',
            ],
        ],
    ];

    foreach ($cases as $case) {
        $label = $case['label'];
        $matches = array_values(array_filter($manifest['bindings'] ?? [], static fn(array $entry): bool =>
            ($entry['bundle_file'] ?? null) === $case['file']));
        $entry = $matches[0] ?? [];
        $sourceIdentity = ['adapter'=>'tourvisor-snapshot-v1', 'hotel'=>$case['tv'], 'country'=>'4'];
        $hotelRef = ['catalog'=>'anytour', 'id'=>$case['anytour']];
        draftContextCheck($label.' has one exact typed Turkey binding through the returned AnyTour ID',
            count($matches) === 1 && ($entry['status'] ?? null) === 'accepted'
            && $canonical($entry['source_identity'] ?? null) === $canonical($sourceIdentity)
            && $canonical($entry['accepted_binding']['hotel_ref'] ?? null) === $canonical($hotelRef)
            && ($entry['accepted_binding']['research_hotel_key'] ?? null) === $case['research_key']
            && $canonical($entry['accepted_binding']['accepted_chain'] ?? null) === $canonical([
                ['namespace'=>'legacy_catalog/catalog_hotels.id', 'id'=>(int)$case['tv']],
                ['namespace'=>'anytour', 'id'=>$case['anytour'], 'catalog_revision'=>1],
                ['namespace'=>'local-tv', 'id'=>(int)$case['tv'], 'catalog_revision'=>3],
            ]));

        $bytes = file_get_contents($directory.'/'.$case['file']);
        $bundle = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        $originalClaimKinds = array_fill_keys(array_keys($case['claim_hashes']), 'source_declared');
        $omittedSourceFields = ['run_id'=>$case['original_run_id'], 'question_id'=>$case['original_question_id']];
        $omittedClaimFields = $omittedSourceFields + ['criterion_id'=>'owner_authorized_broad_base_profile_comparison'];
        $projectedKindsValid = true;
        foreach ($bundle['facts'] ?? [] as $fact) $projectedKindsValid = $projectedKindsValid && ($fact['kind'] ?? null) === 'official_declared';
        foreach ($bundle['sources'] ?? [] as $source) $projectedKindsValid = $projectedKindsValid && ($source['kind'] ?? null) === 'official';
        draftContextCheck($label.' changes only reviewed kind labels and research keys, recovering exact original records',
            $projectedKindsValid
            && count($bundle['facts'] ?? []) === count($case['claim_hashes'])
            && count($bundle['sources'] ?? []) === count($case['source_hashes'])
            && $restoreProjectionAndHash($bundle['facts'], $originalClaimKinds, $omittedClaimFields) === $case['claim_hashes']
            && $restoreProjectionAndHash($bundle['sources'], $case['source_kinds'], $omittedSourceFields) === $case['source_hashes']
            && $canonical($bundle['material_limits']['research_provenance']['omitted_context_fields'] ?? null) === $canonical([
                'claims'=>array_keys($omittedClaimFields), 'sources'=>array_keys($omittedSourceFields),
            ]));
        draftContextCheck($label.' distinguishes projected integrity hashes from original research hashes',
            ($bundle['record_hashes'] ?? null) == [
                'claims'=>$hashRecords($bundle['facts']),
                'sources'=>$hashRecords($bundle['sources']),
            ]);

        // Synthetic provenance exercises the real reader, without pretending
        // that a native inquiry, customer trip or model request took place.
        $selector = [
            'project_key'=>'anytour', 'source_identity'=>$sourceIdentity,
            'provenance'=>[
                'kind'=>'publication', 'publication_id'=>str_repeat('a',32), 'card_index'=>0,
                'draft_offer_id'=>str_repeat('b',32), 'publication_sha256'=>str_repeat('c',64),
                'source_sha256'=>str_repeat('d',64),
            ],
            'selected_hotel'=>$case['name'], 'selected_room'=>'Selected operator category',
        ];
        $now = strtotime('2026-11-06T00:00:00Z');
        $reference = ManagerHotelKnowledge::capture($selector, $now);
        draftContextCheck($label.' returns the whole conditional group with original limits and no new collection credit',
            ($reference['status'] ?? null) === 'available'
            && $canonical($reference['hotel_ref'] ?? null) === $canonical($hotelRef)
            && ($reference['research_hotel_key'] ?? null) === $case['research_key']
            && ($reference['facts'] ?? null) === $bundle['facts']
            && ($reference['sources'] ?? null) === $bundle['sources']
            && ($reference['material_limits'] ?? null) === $bundle['material_limits']
            && ($reference['coverage'] ?? null) === $bundle['coverage']
            && ($reference['coverage']['complete'] ?? null) === false
            && ($reference['material_limits']['research_provenance']['classification'] ?? null) === 'explicit-accepted'
            && ($reference['material_limits']['research_provenance']['new_acceptance_credit'] ?? null) === 0
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

        // Isolate the real binding: future independent hotels with the same
        // numeric ID in a different namespace must not be globally forbidden.
        $isolated = $manifest;
        $isolated['bindings'] = [$entry];
        foreach ([
            ['ANEX ID is not its Tourvisor selector', $case['anex'], '4', $case['name'], 'binding_unresolved'],
            ['AnyTour ID is not its Tourvisor selector', (string)$case['anytour'], '4', $case['name'], 'binding_unresolved'],
            ['Tourvisor selector requires the accepted country', $case['tv'], '1', $case['name'], 'binding_unresolved'],
            ['another hotel name cannot borrow this typed identity', $case['tv'], '4', $case['other_name'], 'source_name_mismatch'],
        ] as [$description,$hotel,$country,$sourceName,$reason]) {
            $changed = $selector;
            $changed['source_identity']['hotel'] = $hotel;
            $changed['source_identity']['country'] = $country;
            $changed['selected_hotel'] = $sourceName;
            $held = $select->invoke(null, $isolated, $bytes, $changed, $now);
            draftContextCheck($label.': '.$description,
                ($held['status'] ?? null) === 'held' && ($held['reason'] ?? null) === $reason
                && ($held['facts'] ?? null) === [] && ($held['sources'] ?? null) === []);
        }

        draftContextCheck($label.' complete bundle and encoded reader projection fit the unchanged 16KiB cap',
            ManagerHotelKnowledge::MAX_REFERENCE_BYTES === 16384
            && strlen($bytes) <= 16384 && strlen($canonical($reference)) <= 16384);

        // Exercise the production admission and request encoder with a small,
        // nonempty synthetic baseline. No request is sent and no trip is proved.
        $baseline = ['conversation_id'=>1, 'as_of_message_id'=>1, 'history_incomplete'=>false,
            'messages'=>[['id'=>1, 'sender_type'=>'customer', 'text'=>'Synthetic hotel budget probe']]];
        [$context, $admission] = $admit->invoke(null, $baseline, $reference);
        $wire = ManagerDraftClient::requestBody(ManagerAiDraftService::instructions($context), $context);
        $plainWire = ManagerDraftClient::requestBody(ManagerAiDraftService::instructions($baseline), $baseline);
        $wireBytes = strlen(json_encode($wire, $jsonFlags));
        $wireIncrement = $wireBytes - strlen(json_encode($plainWire, $jsonFlags));
        $payload = json_decode($wire['input'][1]['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
        draftContextCheck($label.' admits the whole group within the real 16KiB wire increment including hotel policy',
            $context === $baseline + ['hotel_reference'=>$reference]
            && ($payload['hotel_reference'] ?? null) === $reference
            && ($admission['status'] ?? null) === 'available'
            && ($admission['admitted_claim_ids'] ?? null) === array_column($bundle['facts'], 'id')
            && ($admission['wire_increment_bytes'] ?? null) === $wireIncrement
            && ($admission['text_request_bytes'] ?? null) === $wireBytes
            && str_contains($wire['input'][0]['content'][0]['text'], ManagerHotelKnowledge::instructions())
            && $wireIncrement > 0 && $wireIncrement <= 16384
            && $wireBytes <= ManagerDraftClient::MAX_REQUEST_BYTES);

        if ($case['tv'] === '1216') {
            $comparison = $reference['material_limits']['source_comparison']['record'] ?? null;
            draftContextCheck('Rox coastline conflict remains the unchanged non-declaration material limit, never a service fact',
                is_array($comparison)
                && ($comparison['id'] ?? null) === 'HOTEL-TR-MANUAL-20261025T191157Z-BASE08_c41'
                && ($comparison['kind'] ?? null) === 'source_comparison'
                && ($comparison['declared'] ?? null) === false
                && hash('sha256', $canonical($comparison)) === '6c7a61de88b931770d3ae64dbc1a4b353391badf2e56dca71b09c3919e681b42'
                && !in_array($comparison['id'], array_column($reference['facts'], 'id'), true));
        }
    }
}

