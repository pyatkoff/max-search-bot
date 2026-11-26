<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerHotelKnowledge.php';
require_once __DIR__.'/hotel_resort_island_reader_checks.php';

/** Synthetic capacity checks; no research, approvals, provider or database writes. */
function hotelCatalogueChecks(): void
{
    hotelResortIslandReaderChecks();
    $canonicalMethod = new ReflectionMethod(ManagerHotelKnowledge::class, 'canonical');
    $canonical = static fn($v): string => $canonicalMethod->invoke(null, $v);
    $lookup = new ReflectionMethod(ManagerHotelKnowledge::class, 'catalogueBinding');
    $binding = new ReflectionMethod(ManagerHotelKnowledge::class, 'binding');
    $select = new ReflectionMethod(ManagerHotelKnowledge::class, 'select');
    $selectEntry = new ReflectionMethod(ManagerHotelKnowledge::class, 'selectEntry');
    $read = new ReflectionMethod(ManagerHotelKnowledge::class, 'read');
    $blob = static fn(string $bytes): string => hash('sha1', 'blob '.strlen($bytes)."\0".$bytes);
    $directory = dirname(__DIR__).'/knowledge/hotels';
    $originalBytes = file_get_contents($directory.'/manifest.json');
    $original = json_decode($originalBytes, true, 24, JSON_THROW_ON_ERROR);
    $index = json_decode(file_get_contents($directory.'/catalogue.json'), true, 16, JSON_THROW_ON_ERROR);
    $selectorFor = static fn(array $entry): array => [
        'project_key'=>'anytour', 'source_identity'=>$entry['source_identity'],
        'selected_hotel'=>$entry['accepted_source_names'][0], 'selected_room'=>'Synthetic operator category',
        'provenance'=>['message_id'=>1,'event_id'=>1,'publication_sha256'=>str_repeat('a',64),'source_sha256'=>str_repeat('b',64)],
    ];
    $selector = $selectorFor($original['bindings'][0]);
    [$found,$reason] = $lookup->invoke(null,$index,$selector,
        static fn(string $file): ?string => $read->invoke(null,$directory,$file,65536));
    draftContextCheck('catalogue pins and preserves the exact existing first manifest entry',
        $reason === 'available' && $found === $original['bindings'][0]
        && $index['shards'][0] === ['file'=>'manifest.json','git_blob_sha'=>$blob($originalBytes)]);
    $now = strtotime('2026-11-11T00:00:00Z');
    $bundleBytes = file_get_contents($directory.'/'.$found['bundle_file']);
    $legacy = $select->invoke(null,$original,$bundleBytes,$selector,$now);
    draftContextCheck('catalogue capture preserves the complete previous reference and fingerprint',
        ManagerHotelKnowledge::capture($selector,$now) === $legacy && $legacy['status'] === 'available');

    // The first canonical lookup validates all shard pins and identities.
    // Read every actual bundle too, including entries the fixtures do not use.
    foreach ($index['shards'] as $shard) {
        $shardBytes = $read->invoke(null,$directory,$shard['file'],65536);
        $manifest = json_decode($shardBytes,true,24,JSON_THROW_ON_ERROR);
        foreach ($manifest['bindings'] as $entry) {
            $bytes = $read->invoke(null,$directory,$entry['bundle_file'],16384);
            $reference = $selectEntry->invoke(null,$entry,$bytes,$selectorFor($entry),time());
            draftContextCheck('exact catalogue bundle passes its current reader: '.$entry['bundle_file'],
                $reference['status']==='available' || ($reference['reason']??null)==='outside_explicit_validity');
        }
    }

    // These identities and approval strings exist in memory only. They never
    // enter the repository manifest, a real admission or the hotel counters.
    $makeEntry = static function(int $id) use($canonical): array {
        $core = ['project_key'=>'anytour','research_hotel_key'=>'synthetic_'.$id,
            'hotel_ref'=>['catalog'=>'anytour','id'=>$id],
            'accepted_chain'=>[
                ['namespace'=>'legacy_catalog/catalog_hotels.id','id'=>10000+$id],
                ['namespace'=>'anytour','id'=>$id,'catalog_revision'=>1],
                ['namespace'=>'local-tv','id'=>10000+$id,'catalog_revision'=>1]],
            'identity_anchor'=>['research_source_id'=>'synthetic_source_'.$id,
                'research_source_record_sha256'=>str_repeat('1',64),
                'official_url'=>'https://example.test/hotel/'.$id,'address'=>'Synthetic address',
                'country'=>'Egypt','city'=>'Synthetic city'],
            'proofs'=>['identity.json'=>str_repeat('2',64),'legacy.raw'=>str_repeat('3',64),'local.raw'=>str_repeat('4',64)]];
        return ['status'=>'accepted','source_identity'=>['adapter'=>'tourvisor-snapshot-v1','hotel'=>(string)(10000+$id),'country'=>'1'],
            'accepted_source_names'=>['SYNTHETIC HOTEL '.$id], 'accepted_binding'=>$core,
            'binding_revision'=>hash('sha256',$canonical($core)),'bundle_file'=>'anytour-'.$id.'-synthetic.json',
            'bundle_sha256'=>str_repeat('5',64),
            'approval'=>['library_file_id'=>'libfile_'.str_repeat('0',32),'common_version'=>1,
                'member'=>'synthetic/accepted-binding.json','scope'=>'Synthetic test only; not runtime admission.']];
    };
    $manifestFor = static fn(array $entries): array => ['schema_version'=>1,'version'=>'synthetic-test-only','project_key'=>'anytour','bindings'=>$entries];
    $build = static function(array $groups) use($canonical,$blob,$manifestFor): array {
        $catalogue = ['schema_version'=>'hotel_catalogue_v1','project_key'=>'anytour','shards'=>[]]; $files = [];
        foreach ($groups as $i=>$group) {
            $file = $i === 0 ? 'manifest.json' : sprintf('manifest-%04d.json',$i);
            $files[$file] = $canonical($manifestFor($group));
            $catalogue['shards'][] = ['file'=>$file,'git_blob_sha'=>$blob($files[$file])];
        }
        return [$catalogue,$files];
    };
    $run = static function(array $catalogue,array $files,array $selector) use($lookup): array {
        return $lookup->invoke(null,$catalogue,$selector,static fn(string $file): ?string => $files[$file] ?? null);
    };

    // Both opaque Library identifiers are syntax only. These synthetic values
    // do not attest content review, file ownership, member bytes or installation.
    $nativeId = 'libfile_fde3_f-'.str_repeat('a',32).'_'.str_repeat('b',32);
    $nativeEntry = $original['bindings'][0];
    $nativeEntry['approval'] = ['library_file_id'=>$nativeId,'common_version'=>1,
        'member'=>'synthetic-native/accepted-binding.json',
        'scope'=>'Synthetic native-ID compatibility fixture only; not runtime admission.'];
    $nativeManifest = $manifestFor([$nativeEntry]);
    $nativeReference = $select->invoke(null,$nativeManifest,$bundleBytes,$selector,$now);
    draftContextCheck('qualified native Library ID preserves the complete legacy fact and source group',
        $binding->invoke(null,$nativeManifest,$selector) === [$nativeEntry,'available']
        && $nativeReference['status'] === 'available'
        && $nativeReference['facts'] === $legacy['facts']
        && $nativeReference['sources'] === $legacy['sources']
        && $nativeReference['binding_revision'] === $legacy['binding_revision']);
    $invalidNativeIds = [
        'libfile_'.str_repeat('a',31), 'libfile_'.str_repeat('a',33),
        'libfile_fde3_f-'.str_repeat('a',32),
        'libfile_fde3_f-'.str_repeat('a',32).'_'.str_repeat('b',31),
        'libfile_fde3_f-'.str_repeat('a',32).'_'.str_repeat('b',33),
        'libfile_fde3_f-'.str_repeat('a',32).'_'.str_repeat('b',32).'_extra',
        str_replace('fde3_f-','fde3_', $nativeId),
        str_replace('fde3_f-','fde1_f-', $nativeId),
        str_replace(str_repeat('a',32),str_repeat('A',32),$nativeId),
        ' '.$nativeId, $nativeId."\n", $nativeId."\0",
        'https://example.test/'.$nativeId, '../'.$nativeId,
    ];
    foreach ($invalidNativeIds as $i=>$invalidId) {
        $badEntry = $nativeEntry; $badEntry['approval']['library_file_id'] = $invalidId;
        draftContextCheck('native Library identifier rejects malformed or nonopaque value '.$i,
            $binding->invoke(null,$manifestFor([$badEntry]),$selector) === [null,'manifest_invalid']);
    }
    foreach ([0,-1,'1',1.5,true] as $i=>$invalidVersion) {
        $badEntry = $nativeEntry; $badEntry['approval']['common_version'] = $invalidVersion;
        draftContextCheck('native ID retains the positive integer admission version rule '.$i,
            $binding->invoke(null,$manifestFor([$badEntry]),$selector) === [null,'manifest_invalid']);
    }
    foreach (['scope','member','extra'] as $case) {
        $badEntry = $nativeEntry;
        if ($case === 'scope') $badEntry['approval']['scope'] = '';
        if ($case === 'member') $badEntry['approval']['member'] = '../accepted-binding.json';
        if ($case === 'extra') $badEntry['approval']['verified'] = true;
        draftContextCheck('native ID cannot replace the exact admission '.$case.' boundary',
            $binding->invoke(null,$manifestFor([$badEntry]),$selector) === [null,'manifest_invalid']);
    }

    $entries = [];
    for ($id=1;$id<=1024;++$id) $entries[] = $makeEntry($id);
    $first = $selectorFor($entries[0]); $last = $selectorFor($entries[1023]);
    [$catalogue,$files] = $build(array_chunk($entries,32));
    draftContextCheck('1024 synthetic bindings fit 32 independently bounded unchanged-size shards',
        count($files) === 32 && max(array_map('strlen',$files)) <= 65536
        && strlen($canonical($catalogue)) <= 16384);
    [$found,$reason] = $run($catalogue,$files,$last);
    draftContextCheck('catalogue resolves the last of 1024 synthetic entries without a larger fact budget',
        $reason === 'available' && $canonical($found) === $canonical($entries[1023])
        && ManagerHotelKnowledge::MAX_REFERENCE_BYTES === 16384);
    draftContextCheck('catalogue also resolves the first entry only after checking every shard',
        $canonical($run($catalogue,$files,$first)) === $canonical([$entries[0],'available']));
    draftContextCheck('the old single-manifest 32-entry boundary is not relaxed',
        $binding->invoke(null,$manifestFor(array_slice($entries,0,33)),$first) === [null,'manifest_invalid']);

    [$small,$smallFiles] = $build([array_slice($entries,0,2),[$entries[32]]]);
    $bad = $small; $bad['shards'][1]['git_blob_sha'] = str_repeat('0',40);
    draftContextCheck('a bad later shard pin withholds an earlier matching hotel',
        $run($bad,$smallFiles,$first) === [null,'manifest_invalid']);
    $missing = $smallFiles; unset($missing['manifest-0001.json']);
    draftContextCheck('a missing later shard is unavailable rather than partial success',
        $run($small,$missing,$first) === [null,'knowledge_unavailable']);
    $badFiles = $smallFiles; $badFiles['manifest-0001.json'] = '{broken';
    $bad = $small; $bad['shards'][1]['git_blob_sha'] = $blob('{broken');
    draftContextCheck('correctly pinned invalid JSON is not an admitted shard',
        $run($bad,$badFiles,$first) === [null,'manifest_invalid']);
    $badFiles['manifest-0001.json'] = str_repeat(' ',65537);
    $bad['shards'][1]['git_blob_sha'] = $blob($badFiles['manifest-0001.json']);
    draftContextCheck('each shard still has the 64KiB read ceiling',
        $run($bad,$badFiles,$first) === [null,'manifest_invalid']);
    [$bad,$badFiles] = $build([array_slice($entries,0,33)]);
    draftContextCheck('partitioning cannot hide more than 32 bindings in one shard',
        $run($bad,$badFiles,$first) === [null,'manifest_invalid']);

    $duplicateSource = $entries[32];
    $duplicateSource['source_identity'] = $entries[1]['source_identity'];
    $duplicateSource['accepted_binding']['accepted_chain'][2]['id'] = 10002;
    $duplicateSource['binding_revision'] = hash('sha256',$canonical($duplicateSource['accepted_binding']));
    [$bad,$badFiles] = $build([array_slice($entries,0,2),[$duplicateSource]]);
    draftContextCheck('duplicate typed source identities across shards invalidate the whole lookup',
        $run($bad,$badFiles,$first) === [null,'manifest_ambiguous']);
    $duplicateHotel = $entries[32];
    $duplicateHotel['accepted_binding']['hotel_ref']['id'] = 2;
    $duplicateHotel['accepted_binding']['accepted_chain'][1]['id'] = 2;
    $duplicateHotel['bundle_file'] = 'anytour-2-synthetic.json';
    $duplicateHotel['binding_revision'] = hash('sha256',$canonical($duplicateHotel['accepted_binding']));
    [$bad,$badFiles] = $build([array_slice($entries,0,2),[$duplicateHotel]]);
    draftContextCheck('duplicate canonical hotels across shards invalidate the whole lookup',
        $run($bad,$badFiles,$first) === [null,'manifest_ambiguous']);
    $otherCountry = $duplicateSource;
    $otherCountry['source_identity']['country'] = '4';
    $otherCountry['accepted_binding']['identity_anchor']['country'] = 'Turkey';
    $otherCountry['binding_revision'] = hash('sha256',$canonical($otherCountry['accepted_binding']));
    [$valid,$validFiles] = $build([array_slice($entries,0,2),[$otherCountry]]);
    draftContextCheck('numeric source IDs in different admitted countries remain distinct',
        $canonical($run($valid,$validFiles,$first)) === $canonical([$entries[0],'available']));
    foreach (['approval','country','namespace','core'] as $case) {
        $entry = $entries[32];
        if ($case === 'approval') unset($entry['approval']);
        if ($case === 'country') $entry['source_identity']['country'] = '99';
        if ($case === 'namespace') $entry['source_identity']['adapter'] = 'anex';
        if ($case === 'core') $entry['binding_revision'] = str_repeat('0',64);
        [$bad,$badFiles] = $build([array_slice($entries,0,2),[$entry]]);
        draftContextCheck('a later shard retains the existing '.$case.' rejection',
            $run($bad,$badFiles,$first) === [null,'manifest_invalid']);
    }
    $renamed = $first; $renamed['selected_hotel'] = 'OTHER HOTEL';
    draftContextCheck('a correct typed ID cannot borrow an unaccepted hotel name',
        $run($small,$smallFiles,$renamed) === [null,'source_name_mismatch']);
    $absent = $first; $absent['source_identity']['hotel'] = '999999';
    draftContextCheck('no admitted match remains unknown rather than an invented property',
        $run($small,$smallFiles,$absent) === [null,'binding_unresolved']);

    foreach (['../manifest.json','https://example.test/manifest.json','manifest-0001.json/extra'] as $path) {
        $bad = $small; $bad['shards'][1]['file'] = $path; $reads = 0;
        $result = $lookup->invoke(null,$bad,$first,static function(string $file) use(&$reads): ?string { ++$reads; return null; });
        draftContextCheck('noncanonical shard paths are rejected before reads: '.$path,
            $result === [null,'manifest_invalid'] && $reads === 0);
    }
    $badIndexes = [];
    $bad = $small; $bad['shards'] = []; $badIndexes[] = $bad;
    $bad = $small; $bad['shards'][0]['file'] = 'manifest-0000.json'; $badIndexes[] = $bad;
    $bad = $small; $bad['shards'][] = $bad['shards'][1]; $badIndexes[] = $bad;
    $bad = $small; $bad['project_key'] = 'other'; $badIndexes[] = $bad;
    $bad = $small; $bad['extra'] = true; $badIndexes[] = $bad;
    $bad = $catalogue; $bad['shards'][] = ['file'=>'manifest-0032.json','git_blob_sha'=>str_repeat('0',40)]; $badIndexes[] = $bad;
    foreach ($badIndexes as $i=>$bad) {
        $reads = 0;
        $result = $lookup->invoke(null,$bad,$first,static function(string $file) use(&$reads): ?string { ++$reads; return null; });
        draftContextCheck('malformed or over-limit catalogue is rejected before any reads '.$i,
            $result === [null,'manifest_invalid'] && $reads === 0);
    }
    draftContextCheck('read exceptions remain a closed unavailable result',
        $lookup->invoke(null,$small,$first,static function(string $file): ?string { throw new RuntimeException('synthetic'); }) === [null,'knowledge_unavailable']);
    draftContextCheck('selected bundle integrity is still checked after global catalogue validation',
        $selectEntry->invoke(null,$entries[0],'tampered',$first,$now)['reason'] === 'bundle_integrity_failed');
    $limited = $selectEntry->invoke(null,$entries[0],str_repeat('x',16385),$first,$now);
    draftContextCheck('catalogue growth cannot expand the 16KiB selected bundle',
        $limited['status'] === 'held' && $limited['reason'] === 'bundle_integrity_failed' && $limited['facts'] === []);

    // Country expansion is only compatibility for separately admitted data.
    // All identities, approvals, facts and sources below are memory-only fixtures.
    $countryName = new ReflectionMethod(ManagerHotelKnowledge::class, 'retainedCountry');
    $retainedSelectorCheck = new ReflectionMethod(ManagerHotelKnowledge::class, 'validRetainedSelector');
    $supported = [
        '2'=>['Thailand',['THAILAND','ТАИЛАНД','ТАЙЛАНД']],
        '8'=>['Maldives',['MALDIVES','МАЛЬДИВЫ']],
        '9'=>['United Arab Emirates',['UNITED ARAB EMIRATES','UAE','ОАЭ','ОБЪЕДИНЕННЫЕ АРАБСКИЕ ЭМИРАТЫ','ОБЪЕДИНЁННЫЕ АРАБСКИЕ ЭМИРАТЫ']],
        '12'=>['Sri Lanka',['SRI LANKA','ШРИ-ЛАНКА','ШРИ ЛАНКА']],
        '16'=>['Vietnam',['VIETNAM','ВЬЕТНАМ']],
        '64'=>['Oman',['OMAN','ОМАН']],
    ];
    foreach ($supported as $countryId=>[$country,$aliases]) {
        $fixture = $makeEntry(8000+(int)$countryId);
        $fixture['source_identity']['country'] = (string)$countryId;
        $fixture['accepted_binding']['identity_anchor']['country'] = $country;
        $fixture['binding_revision'] = hash('sha256',$canonical($fixture['accepted_binding']));
        $core = $fixture['accepted_binding']; $key = $core['research_hotel_key'];
        $sourceRecord = ['id'=>'country_fixture_source','hotel_key'=>$key,'kind'=>'official',
            'url'=>'https://example.test/synthetic-country','read_scope'=>'Synthetic source record, never admitted.',
            'limitations'=>'','publication_date'=>null,'retrieved_on'=>'2026-11-16'];
        $fact = ['id'=>'country_fixture_fact','hotel_key'=>$key,'text'=>'Synthetic category declaration.',
            'aspect'=>'room','kind'=>'official_declared','valid_at_travel'=>'unverified','declared'=>true,
            'scope'=>['kind'=>'room_category','key'=>'synthetic-suite'],'source_ids'=>[$sourceRecord['id']]];
        $bundle = ['schema_version'=>'hotel_reference_v1','status'=>'SEALED_AUTHORING_DATA_ONLY',
            'project_key'=>'anytour','hotel_ref'=>$core['hotel_ref'],'research_hotel_key'=>$key,
            'binding_revision'=>$fixture['binding_revision'],'price_authority'=>'none',
            'trusted_selector_required'=>true,'runtime_admission_verified'=>false,
            'facts'=>[$fact],'sources'=>[$sourceRecord],'material_limits'=>['Synthetic test only.'],
            'coverage'=>['complete'=>false,'selected_claim_count'=>1],
            'record_hashes'=>['claims'=>[$fact['id']=>hash('sha256',$canonical($fact))],
                'sources'=>[$sourceRecord['id']=>hash('sha256',$canonical($sourceRecord))]]];
        $bytes = $canonical($bundle); $fixture['bundle_sha256'] = hash('sha256',$bytes);
        $native = $selectorFor($fixture); $manifest = $manifestFor([$fixture]);
        $reference = $select->invoke(null,$manifest,$bytes,$native,$now);
        draftContextCheck('expanded country '.$countryId.' uses full existing admission pipeline',
            $reference['status']==='available' && $canonical($reference['facts'])===$canonical([$fact])
            && $canonical($reference['sources'])===$canonical([$sourceRecord]) && $reference['price_authority']==='none'
            && $reference['room_tariff_equivalence_verified']===false && $reference['future_operation_verified']===false);
        foreach ($aliases as $alias) {
            draftContextCheck('explicit country name '.$alias.' only', $countryName->invoke(null,'  '.$alias.'  ')===$country);
        }
        $retained = ['project_key'=>'anytour','hotel_ref'=>$core['hotel_ref'],
            'selected_hotel'=>$fixture['accepted_source_names'][0],'selected_room'=>null,
            'provenance'=>['kind'=>'retained_search3_candidate','request_scope_digest'=>str_repeat('a',64),
                'source_scope_digest'=>str_repeat('b',64),'provider'=>'tourvisor','offer_digest'=>str_repeat('c',64),
                'candidate_sha256'=>str_repeat('d',64),'source_generated_at'=>'2026-11-10T23:59:30Z',
                'request_country'=>$aliases[0],'candidate_country'=>end($aliases)]];
        draftContextCheck('expanded retained country '.$countryId.' keeps request and candidate agreement',
            $retainedSelectorCheck->invoke(null,$retained)===true
            && $binding->invoke(null,$manifest,$retained)===[$fixture,'available']);
        $retainedRef = $selectEntry->invoke(null,$fixture,$bytes,$retained,$now);
        draftContextCheck('expanded retained reference '.$countryId.' preserves whole category and tariff limits',
            $canonical($retainedRef['facts'])===$canonical([$fact]) && $canonical($retainedRef['sources'])===$canonical([$sourceRecord])
            && $retainedRef['selected_room']===null && $retainedRef['current_trip_binding']===false
            && $retainedRef['customer_agreement_verified']===false && $retainedRef['current_price_verified']===false
            && $retainedRef['availability_verified']===false && $retainedRef['meal_plan_equivalence_verified']===false);
        $mismatch=$retained;$mismatch['provenance']['candidate_country']='Egypt';
        draftContextCheck('expanded country '.$countryId.' rejects contradictory candidate country',
            $retainedSelectorCheck->invoke(null,$mismatch)===false);
        $mismatch=$retained;$mismatch['provenance']['request_country']='Egypt';$mismatch['provenance']['candidate_country']='Egypt';
        draftContextCheck('expanded country '.$countryId.' rejects wrong request country',
            $binding->invoke(null,$manifest,$mismatch)===[null,'source_country_mismatch']);
        foreach (['approval','country','namespace','core','chain'] as $fault) {
            $badEntry=$fixture;
            if($fault==='approval')unset($badEntry['approval']);
            if($fault==='country')$badEntry['source_identity']['country']='1';
            if($fault==='namespace')$badEntry['accepted_binding']['hotel_ref']['catalog']='tourvisor';
            if($fault==='core')$badEntry['binding_revision']=str_repeat('0',64);
            if($fault==='chain')array_pop($badEntry['accepted_binding']['accepted_chain']);
            draftContextCheck('expanded country '.$countryId.' retains '.$fault.' gate',
                $binding->invoke(null,$manifestFor([$badEntry]),$native)===[null,'manifest_invalid']);
        }
        $wrong=$native;$wrong['source_identity']['hotel']=(string)((int)$wrong['source_identity']['hotel']+1);
        draftContextCheck('expanded country '.$countryId.' never resolves by name alone',
            $binding->invoke(null,$manifest,$wrong)===[null,'binding_unresolved']);
        draftContextCheck('expanded country '.$countryId.' never admits modified facts',
            $select->invoke(null,$manifest,$bytes.' ',$native,$now)['reason']==='bundle_integrity_failed');
        $expired=$bundle;$expired['valid_until']='2026-11-10T00:00:00Z';$expiredBytes=$canonical($expired);
        $expiredEntry=$fixture;$expiredEntry['bundle_sha256']=hash('sha256',$expiredBytes);
        draftContextCheck('expanded country '.$countryId.' retains explicit validity',
            $select->invoke(null,$manifestFor([$expiredEntry]),$expiredBytes,$native,$now)['reason']==='outside_explicit_validity');
    }
    foreach (['2','8','9','12','16','64','EG','TR','TH','MV','AE','LK','VN','OM','Atlantis','France','Dubai','Turkey or Oman'] as $unknown) {
        draftContextCheck('country names do not infer numeric IDs, codes or regions '.$unknown,
            $countryName->invoke(null,$unknown)===null);
    }

}
