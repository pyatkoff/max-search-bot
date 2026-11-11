<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerHotelKnowledge.php';
require_once dirname(__DIR__).'/services/ManagerAiDraftService.php';

/** Reviewed TOP500 consumer groups; no SQL, HTTP or approval creation. */
function hotelTop500AcceptedPairChecks(): void
{
    hotelSideStarEleganceChecks();
    hotelBaronAcceptedChecks();
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

/** The inherited Elegance group uses the existing reader and a real manifest admission. */
function hotelSideStarEleganceChecks(): void
{
    $directory = dirname(__DIR__).'/knowledge/hotels';
    $file = 'anytour-7425-side-star-elegance.json';
    $key = 'top500_b73b1521377bb3ea';
    $canonicalMethod = new ReflectionMethod(ManagerHotelKnowledge::class, 'canonical');
    $canonical = static fn($value): string => $canonicalMethod->invoke(null, $value);
    $hash = static fn($value): string => hash('sha256', $canonical($value));
    $select = new ReflectionMethod(ManagerHotelKnowledge::class, 'select');
    $admit = new ReflectionMethod(ManagerAiDraftService::class, 'admitHotelReference');
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
    $matches = array_values(array_filter($manifest['bindings'] ?? [], static fn(array $entry): bool =>
        ($entry['bundle_file'] ?? null) === $file));
    $entry = $matches[0] ?? [];
    $identity = ['adapter'=>'tourvisor-snapshot-v1', 'hotel'=>'1014', 'country'=>'4'];
    $name = 'SIDE STAR ELEGANCE (EX. ASTERIA SIDE)';
    draftContextCheck('Elegance has one admitted exact TV1014/country4 to AnyTour7425 and local-tv1014 binding',
        count($matches) === 1 && ($entry['status'] ?? null) === 'accepted'
        && $canonical($entry['source_identity'] ?? null) === $canonical($identity)
        && ($entry['accepted_source_names'] ?? null) === [$name]
        && $hash($entry['accepted_binding'] ?? null) === 'de0192613b7682ed1bf310bebd76bf23163b5b72c5e5b691c68a0888f56380dc'
        && ($entry['binding_revision'] ?? null) === 'de0192613b7682ed1bf310bebd76bf23163b5b72c5e5b691c68a0888f56380dc');

    $bytes = file_get_contents($directory.'/'.$file);
    $bundle = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
    draftContextCheck('Elegance uses the exact bounded v1 data without promoting its authoring or price authority',
        strlen($bytes) === 12885
        && hash('sha256', $bytes) === '3be614a9daea046286a2e239194b52b95e993f8e6cf725cc0b56f07c9a3936f6'
        && ($entry['bundle_sha256'] ?? null) === hash('sha256', $bytes)
        && ($bundle['status'] ?? null) === 'SEALED_AUTHORING_DATA_ONLY'
        && ($bundle['runtime_admission_verified'] ?? null) === false
        && ($bundle['trusted_selector_required'] ?? null) === true
        && ($bundle['price_authority'] ?? null) === 'none');

    $claimKinds = [
        $key.'_demand16_c01'=>'official_declared',
        $key.'_run05_c01'=>'operator_declared', $key.'_run05_c02'=>'operator_declared',
        $key.'_run05_c03'=>'operator_declared', $key.'_run05_c04'=>'operator_declared',
        $key.'_run05audit_c02'=>'official_declared', $key.'_run05audit_c05'=>'official_declared',
    ];
    $validAtTravel = array_unique(array_column($bundle['facts'], 'valid_at_travel'));
    draftContextCheck('Elegance keeps seven source-scoped declarations with travel applicability unverified',
        array_column($bundle['facts'], 'kind', 'id') === $claimKinds
        && array_values($validAtTravel) === ['unverified']
        && ($bundle['coverage']['selected_claim_count'] ?? null) === 7
        && ($bundle['coverage']['available_accepted_claim_count'] ?? null) === 0
        && ($bundle['coverage']['complete'] ?? null) === false);

    // Independent original-record pins: no private original JSON is copied into this test.
    $expectedLimits = [
        $key.'_demand16_c02'=>'9c1b82ee31ac5938541dcf2a37e4fbf64cabc9b25559c23a4a3518b29b6e433b',
        $key.'_run05_c05'=>'05374ef0ad8d3100de933a4a59a7e85a4af66554db440d8c0ad3af11c11dca77',
        $key.'_run05audit_c06'=>'a6e8b70c35714781daa1c3ea609ee7aebfd7691e3fde4ec196fad9f8bb1803b8',
    ];
    $limits = []; $rolesValid = true;
    foreach ($bundle['material_limits']['retained_records'] ?? [] as $item) {
        $record = $item['record'] ?? [];
        $limits[$record['id'] ?? ''] = $hash($record);
        $rolesValid = $rolesValid && ($item['role'] ?? null) === 'retained_limit_not_service_declaration';
    }
    draftContextCheck('Elegance preserves the exact drink-hours conflict, Swim Up/mobility caution and failed-link history outside facts',
        $rolesValid && $limits === $expectedLimits
        && array_intersect(array_keys($limits), array_keys($claimKinds)) === []);
    $provenance = $bundle['material_limits']['research_provenance'] ?? [];
    draftContextCheck('Elegance remains inherited baseline with no original ledger acceptance or fabricated continuous receipt',
        ($provenance['classification'] ?? null) === 'inherited-baseline'
        && ($provenance['new_acceptance_credit'] ?? null) === 0
        && ($provenance['original_direct_or_ledger_acceptance_retained'] ?? null) === false
        && array_key_exists('assigned_continuous_receipt', $provenance)
        && $provenance['assigned_continuous_receipt'] === null);
    $dates = []; $publicationUnknown = true;
    foreach ($bundle['sources'] as $source) {
        $dates[$source['id']] = $source['retrieved_on'] ?? null;
        $publicationUnknown = $publicationUnknown && array_key_exists('publication_date', $source) && $source['publication_date'] === null;
    }
    draftContextCheck('Elegance retains all five related source observations and does not invent publication dates',
        $publicationUnknown && $dates === [
            $key.'_demand16_s02'=>'2026-10-20', $key.'_run05_s01'=>'2026-10-19',
            $key.'_run05audit_s02'=>'2026-10-19', $key.'_run05audit_s03'=>'2026-10-19',
            $key.'_run05audit_s04'=>'2026-10-19',
        ]);

    $selector = ['project_key'=>'anytour', 'source_identity'=>$identity,
        'provenance'=>['kind'=>'publication', 'publication_id'=>str_repeat('a',32), 'card_index'=>0,
            'draft_offer_id'=>str_repeat('b',32), 'publication_sha256'=>str_repeat('c',64), 'source_sha256'=>str_repeat('d',64)],
        'selected_hotel'=>$name, 'selected_room'=>'Synthetic operator category'];
    $now = strtotime('2026-11-10T14:30:00Z');
    $reference = ManagerHotelKnowledge::capture($selector, $now);
    $authorityUnchanged = ($reference['price_authority'] ?? null) === 'none'
        && ($reference['applicability'] ?? null) === 'conditional_published_information';
    foreach (['current_trip_binding', 'customer_agreement_verified', 'current_price_verified',
        'availability_verified', 'room_tariff_equivalence_verified', 'meal_plan_equivalence_verified',
        'future_operation_verified'] as $flag) $authorityUnchanged = $authorityUnchanged && ($reference[$flag] ?? null) === false;
    draftContextCheck('The installed data path supplies the whole Elegance group without current-trip or tariff authority',
        ($reference['status'] ?? null) === 'available' && $authorityUnchanged
        && ($reference['hotel_ref'] ?? null) === ['catalog'=>'anytour', 'id'=>7425]
        && ($reference['research_hotel_key'] ?? null) === $key
        && ($reference['facts'] ?? null) === $bundle['facts']
        && ($reference['sources'] ?? null) === $bundle['sources']
        && ($reference['material_limits'] ?? null) === $bundle['material_limits']
        && ($reference['coverage'] ?? null) === $bundle['coverage']);

    $isolated = $manifest; $isolated['bindings'] = [$entry];
    foreach ([
        ['ANEX8475 is not TV1014', '8475', '4', $name, 'binding_unresolved'],
        ['AnyTour7425 is not TV1014', '7425', '4', $name, 'binding_unresolved'],
        ['TV1014 requires country4', '1014', '1', $name, 'binding_unresolved'],
        ['Side Star Resort cannot borrow Elegance', '1014', '4', 'SIDE STAR RESORT (EX. VERA CLUB LINDITA)', 'source_name_mismatch'],
    ] as [$description,$hotel,$country,$sourceName,$reason]) {
        $changed = $selector;
        $changed['source_identity']['hotel'] = $hotel; $changed['source_identity']['country'] = $country;
        $changed['selected_hotel'] = $sourceName;
        $held = $select->invoke(null, $isolated, $bytes, $changed, $now);
        draftContextCheck('Elegance: '.$description,
            ($held['status'] ?? null) === 'held' && ($held['reason'] ?? null) === $reason
            && ($held['facts'] ?? null) === [] && ($held['sources'] ?? null) === []);
    }
    $pending = $isolated; $pending['bindings'][0]['status'] = 'pending';
    $held = $select->invoke(null, $pending, $bytes, $selector, $now);
    draftContextCheck('An Elegance candidate without completed manifest admission is rejected',
        ($held['status'] ?? null) === 'held' && ($held['reason'] ?? null) === 'manifest_invalid'
        && ($held['facts'] ?? null) === [] && ($held['sources'] ?? null) === []);

    $baseline = ['conversation_id'=>1, 'as_of_message_id'=>1, 'history_incomplete'=>false,
        'messages'=>[['id'=>1, 'sender_type'=>'customer', 'text'=>'Synthetic hotel budget probe']]];
    $flags = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
    $encode = static fn(array $context): string => json_encode(
        ManagerDraftClient::requestBody(ManagerAiDraftService::instructions($context), $context), $flags);
    $plainBytes = strlen($encode($baseline));
    foreach (['Synthetic operator category', str_repeat('R',300)] as $room) {
        $withRoom = $selector; $withRoom['selected_room'] = $room;
        $candidate = $select->invoke(null, $isolated, $bytes, $withRoom, $now);
        [$context, $admission] = $admit->invoke(null, $baseline, $candidate);
        $wireBytes = strlen($encode($context));
        draftContextCheck('Elegance keeps all seven declarations and every caveat within the real budget for a '.strlen($room).'-byte room label',
            ManagerHotelKnowledge::MAX_REFERENCE_BYTES === 16384
            && ($admission['status'] ?? null) === 'available'
            && $context === $baseline + ['hotel_reference'=>$candidate]
            && ($candidate['facts'] ?? null) === $bundle['facts']
            && ($candidate['material_limits'] ?? null) === $bundle['material_limits']
            && ($admission['admitted_claim_ids'] ?? null) === array_keys($claimKinds)
            && ($admission['wire_increment_bytes'] ?? null) === $wireBytes-$plainBytes
            && $wireBytes-$plainBytes <= 16384 && $wireBytes <= ManagerDraftClient::MAX_REQUEST_BYTES);
    }
    $escaped = $selector; $escaped['selected_room'] = str_repeat('"',300);
    $escapedReference = $select->invoke(null, $isolated, $bytes, $escaped, $now);
    [$escapedContext, $escapedAdmission] = $admit->invoke(null, $baseline, $escapedReference);
    $candidateIncrement = strlen($encode($baseline + ['hotel_reference'=>$escapedReference]))-$plainBytes;
    $unchangedBaseline = $escapedContext; unset($unchangedBaseline['hotel_reference']);
    draftContextCheck('Elegance is omitted whole when double-JSON escaping exceeds 16KiB, without shortening the baseline',
        ($escapedReference['status'] ?? null) === 'available' && $candidateIncrement > 16384
        && ($escapedAdmission['status'] ?? null) === 'omitted' && $unchangedBaseline === $baseline
        && ($escapedContext['hotel_reference']['facts'] ?? null) === []
        && ($escapedContext['hotel_reference']['sources'] ?? null) === []);
}

/** Exact Baron files with saved release admission; no network or approval creation. */
function hotelBaronAcceptedChecks(): void
{
    $directory = dirname(__DIR__).'/knowledge/hotels';
    $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
    $canonicalMethod = new ReflectionMethod(ManagerHotelKnowledge::class, 'canonical');
    $canonical = static fn($value): string => $canonicalMethod->invoke(null, $value);
    $hash = static fn($value): string => hash('sha256', $canonical($value));
    $select = new ReflectionMethod(ManagerHotelKnowledge::class, 'select');
    $admit = new ReflectionMethod(ManagerAiDraftService::class, 'admitHotelReference');
    $prefix = 'HOTEL-EG-MANUAL-20261025-BARON-01_';
    $cases = [
        [
            'id'=>868, 'tv'=>'1963', 'slug'=>'baron-resort', 'name'=>'BARON RESORT',
            'other_name'=>'BARON PALMS RESORT ADULTS ONLY 16+',
            'key'=>'top500_4a932ca9ee9e4703', 'bytes'=>11980,
            'bundle_sha'=>'a3ee178b9a385356eb33b32c148737f36282b4d17f37445a038b483a202f9bfd',
            'core_sha'=>'ed6024194c1240c4cb797a1714e78e1d4a357e53d3900fef45b880f5cb49a4b8',
            'raw_claims'=>[
                'c01'=>'c9a21866cdf0173f0c24757406ab34c560097297d5dfe44b447faf7245bb0474',
                'c03'=>'5f50497c2b6727f036b6946e8713620317fc526563283f0ce8f0521020e158cc',
                'c04'=>'dc1563c26b5d9ed0b04970aad042be0e652830aa5f59e134981c9b04062e65b2',
                'c05'=>'9213253096894370e30e042588283bf7d26be428774572819ecc6c43188d2135',
            ],
            'context_id'=>'c02', 'context_sha'=>'7ae52ac7a2602db014cea3e98e7c36052c8cc151db8a78d6f2a983b14ed78ee9',
            'source_hashes'=>[
                'HOTEL-EG-MANUAL-20261025-BARON-01_s01'=>'12ff301fcabcb7ab5ac607f3874e76c83e5dfe96543c5fb5a2df03c9b2fbe465',
                'top500_4a932ca9ee9e4703_enrich04_20261019_s01'=>'349266c81eef38e5b9fe7615c5b3c1da52bab97d09c34db4bd28face87f24378',
            ],
        ],
        [
            'id'=>867, 'tv'=>'1962', 'slug'=>'baron-palms', 'name'=>'BARON PALMS RESORT ADULTS ONLY 16+',
            'other_name'=>'BARON RESORT',
            'key'=>'top500_6f323c1d8a3cc6fe', 'bytes'=>13349,
            'bundle_sha'=>'8073bf552b4d1d0907b3a960d09d42dbc155df0ada4f6e0a1e123eb8712d5dd2',
            'core_sha'=>'afbe126f11d4320daa02510aad05fe4e507c8c6381138d2406691e12bd9657e4',
            'raw_claims'=>[
                'c06'=>'23603ca75c93c3cedbd65f0bbda5071577ab3ebe6c110c5f06fcab183649316d',
                'c07'=>'3a571fef051b50f02143ab1306b888ebd908d3cd959518f53f0925c638db5c88',
                'c09'=>'e9013405c079331cbdc3b3e5962645186a66263128fa62362d9d9303e1aade4c',
                'c10'=>'782d0464f340a6393bfc75c7aab4aaa073b6713ad69af8c585083af4fa511cca',
                'c11'=>'13427fe9718df46dd703cb49b7ad83a24ff265f5f446fe778df5ec90a4da67cd',
            ],
            'context_id'=>'c08', 'context_sha'=>'755732d5106de79a0a6615c2ed9ebec886f06aa89b01f205624a3934bc65ed5e',
            'source_hashes'=>[
                'HOTEL-EG-MANUAL-20261025-BARON-01_s02'=>'c771462049203df6ef251d03c195abe0238b98088a31d6065814847851485b18',
                'top500_6f323c1d8a3cc6fe_enrich04_20261019_s01'=>'036a6d227fad8f75a42807a3eeaeb0726ca6c7cddfe3f5f512f3b92cd4f621a1',
            ],
        ],
    ];
    foreach ($cases as $case) {
        $file = 'anytour-'.$case['id'].'-'.$case['slug'].'.json';
        $matches = array_values(array_filter($manifest['bindings'], static fn(array $entry): bool =>
            ($entry['bundle_file'] ?? null) === $file));
        $entry = $matches[0] ?? [];
        draftContextCheck($case['name'].' uses its own accepted identity and actual release-admission version',
            count($matches) === 1 && ($entry['status'] ?? null) === 'accepted'
            && $canonical($entry['source_identity'] ?? null) === $canonical(['adapter'=>'tourvisor-snapshot-v1', 'hotel'=>$case['tv'], 'country'=>'1'])
            && ($entry['accepted_source_names'] ?? null) === [$case['name']]
            && $hash($entry['accepted_binding'] ?? null) === $case['core_sha']
            && ($entry['binding_revision'] ?? null) === $case['core_sha']
            && ($entry['approval']['library_file_id'] ?? null) === 'libfile_4b24e1cb1b9881918dfd6802b259134d'
            && ($entry['approval']['common_version'] ?? null) === 4
            && ($entry['approval']['member'] ?? null) === 'baron-publication-20261111/'.$case['slug'].'/accepted-binding.json');
        $bytes = file_get_contents($directory.'/'.$file);
        $bundle = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        draftContextCheck($case['name'].' keeps the exact reviewed bounded bundle and unchanged authoring authority',
            strlen($bytes) === $case['bytes'] && hash('sha256', $bytes) === $case['bundle_sha']
            && ($entry['bundle_sha256'] ?? null) === $case['bundle_sha']
            && ($bundle['status'] ?? null) === 'SEALED_AUTHORING_DATA_ONLY'
            && ($bundle['runtime_admission_verified'] ?? null) === false
            && ($bundle['price_authority'] ?? null) === 'none'
            && ($bundle['coverage']['complete'] ?? null) === false
            && ($bundle['coverage']['selected_claim_count'] ?? null) === count($case['raw_claims']));
        $restored = []; $markers = true;
        foreach ($bundle['facts'] as $fact) {
            $id = $fact['id']; $projection = $bundle['material_limits']['consumer_projection'][$id] ?? [];
            $markers = $markers && ($fact['declared'] ?? null) === true
                && ($fact['kind'] ?? null) === 'official_declared'
                && ($fact['valid_at_travel'] ?? null) === 'unverified'
                && ($fact['pending_not_canonical'] ?? null) === true
                && ($fact['retrieved_on'] ?? null) === '2026-10-25'
                && ($projection['added_metadata'] ?? null) === ['declared'=>true]
                && ($projection['projected_record_sha256'] ?? null) === $hash($fact);
            unset($fact['declared']);
            $restored[substr($id, strlen($prefix))] = $hash($fact);
            $markers = $markers && ($projection['raw_record_sha256'] ?? null) === $hash($fact);
        }
        draftContextCheck($case['name'].' restores every original field by removing only the approved declaration marker',
            $markers && $restored === $case['raw_claims']);
        $sourceHashes = [];
        foreach ($bundle['sources'] as $source) $sourceHashes[$source['id']] = $hash($source);
        ksort($sourceHashes); $expectedSourceHashes = $case['source_hashes']; ksort($expectedSourceHashes);
        $limits = $bundle['material_limits'];
        $interpretation = $limits['context_records'][0] ?? [];
        draftContextCheck($case['name'].' retains original sources, full category conflict and all source-specific limitations',
            $sourceHashes === $expectedSourceHashes
            && count($limits['context_records'] ?? []) === 1
            && ($interpretation['id'] ?? null) === $prefix.$case['context_id']
            && ($interpretation['kind'] ?? null) === 'research_interpretation'
            && $hash($interpretation) === $case['context_sha']
            && !in_array($interpretation['id'], array_column($bundle['facts'], 'id'), true)
            && count($limits['source_read_updates'] ?? []) === 1
            && ($limits['source_read_updates'][0]['read_on'] ?? null) === '2026-10-25'
            && count($limits['prior_context_limits'] ?? []) === 4 && count($limits['unknowns'] ?? []) === 5);
        // Explicit synthetic selector for the original native-inquiry variant.
        $selector = ['project_key'=>'anytour',
            'source_identity'=>['adapter'=>'tourvisor-snapshot-v1','hotel'=>$case['tv'],'country'=>'1'],
            'provenance'=>['message_id'=>1,'event_id'=>1,'publication_sha256'=>str_repeat('a',64),'source_sha256'=>str_repeat('b',64)],
            'selected_hotel'=>$case['name'],'selected_room'=>'Standard'];
        $now = strtotime('2026-11-11T07:30:00Z');
        $reference = ManagerHotelKnowledge::capture($selector, $now);
        draftContextCheck($case['name'].' is available through the unchanged reader with its whole source group',
            ($reference['status'] ?? null) === 'available'
            && ($reference['hotel_ref'] ?? null) === ['catalog'=>'anytour','id'=>$case['id']]
            && ($reference['facts'] ?? null) === $bundle['facts']
            && ($reference['sources'] ?? null) === $bundle['sources']
            && ($reference['material_limits'] ?? null) === $limits
            && ($reference['room_tariff_equivalence_verified'] ?? null) === false
            && ($reference['future_operation_verified'] ?? null) === false);
        $baseline = ['conversation_id'=>1,'as_of_message_id'=>1,'history_incomplete'=>false,
            'messages'=>[['id'=>1,'sender_type'=>'customer','text'=>'Synthetic Baron knowledge budget probe']]];
        $flags = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
        $encode = static fn(array $context): string => json_encode(
            ManagerDraftClient::requestBody(ManagerAiDraftService::instructions($context),$context),$flags);
        [$context,$admission] = $admit->invoke(null,$baseline,$reference);
        $increment = strlen($encode($context))-strlen($encode($baseline));
        draftContextCheck($case['name'].' reaches the actual encoded AI request with all declarations and caveats',
            ($admission['status'] ?? null) === 'available'
            && $context === $baseline + ['hotel_reference'=>$reference]
            && ($admission['admitted_claim_ids'] ?? null) === array_column($bundle['facts'],'id')
            && ($admission['wire_increment_bytes'] ?? null) === $increment
            && $increment <= 16384 && strlen($encode($context)) <= ManagerDraftClient::MAX_REQUEST_BYTES);
        $isolated = $manifest; $isolated['bindings'] = [$entry];
        foreach ([
            ['other country',$case['tv'],'4',$case['name'],'binding_unresolved'],
            ['AnyTour id used as TV',(string)$case['id'],'1',$case['name'],'binding_unresolved'],
            ['other Baron name',$case['tv'],'1',$case['other_name'],'source_name_mismatch'],
        ] as [$label,$tv,$country,$name,$reason]) {
            $bad = $selector; $bad['source_identity']['hotel']=$tv;
            $bad['source_identity']['country']=$country; $bad['selected_hotel']=$name;
            $held = $select->invoke(null,$isolated,$bytes,$bad,$now);
            draftContextCheck($case['name'].' rejects '.$label.' without borrowing hotel facts',
                ($held['status'] ?? null) === 'held' && ($held['reason'] ?? null) === $reason
                && ($held['facts'] ?? null) === [] && ($held['sources'] ?? null) === []);
        }
        $long = $selector; $long['selected_room'] = str_repeat('"',300);
        $longReference = $select->invoke(null,$isolated,$bytes,$long,$now);
        [$longContext,$longAdmission] = $admit->invoke(null,$baseline,$longReference);
        $candidateIncrement = strlen($encode($baseline + ['hotel_reference'=>$longReference]))-strlen($encode($baseline));
        $unchanged = $longContext; unset($unchanged['hotel_reference']);
        draftContextCheck($case['name'].' enforces the real request budget without shortening baseline evidence or caveats',
            ($longReference['status'] ?? null) === 'available' && $unchanged === $baseline
            && ($candidateIncrement <= 16384
                ? (($longAdmission['status'] ?? null) === 'available' && ($longContext['hotel_reference'] ?? null) === $longReference)
                : (($longAdmission['status'] ?? null) === 'omitted'
                    && ($longContext['hotel_reference']['facts'] ?? null) === []
                    && ($longContext['hotel_reference']['sources'] ?? null) === [])));
    }
}
