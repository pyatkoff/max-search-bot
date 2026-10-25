<?php

declare(strict_types=1);

/** Approved hotel declarations only. No identity discovery, HTTP or storage writes. */
final class ManagerHotelKnowledge
{
    public const MAX_REFERENCE_BYTES = 16384;
    private const MAX_MANIFEST_BYTES = 65536;

    /** Shared by generation and the captured per-hotel policy revision. */
    public static function instructions(): string
    {
        return <<<'HOTEL'
hotel_reference — принятая гостиничная справка для точной карточки из последнего проверенного обращения туриста через веб-подборку. Только status=available содержит факты: их источники, scope, material_limits и valid_at_travel сохраняют ограничения. Это опубликованные сведения, а не подтверждение услуг на будущие даты, цены или наличия. Планировка официальной Family Suite не доказывает соответствие выбранному операторскому номеру; кровати не подтверждают допустимый состав, неизвестная вместимость и нерешённый вопрос об окне остаются проверкой менеджеру. Не переноси правила одной категории/продукта на весь отель. Выбранный room передан отдельно, его соответствие официальной категории не подтверждено. held/отсутствие справки означает неизвестность, не отсутствие услуги. Новое явное намерение туриста всегда важнее прежней карточки. Идентификаторы claims/sources не являются evidence_message_ids и не выводятся туристу.
HOTEL;
    }

    /** Only the existing server reference owner supplies this selector. */
    public static function capture(array $selector, int $now): array
    {
        if (!self::validSelector($selector) || $now <= 0) return self::held('selector_unavailable');
        $directory = dirname(__DIR__).'/knowledge/hotels';
        $rawManifest = self::read($directory, 'manifest.json', self::MAX_MANIFEST_BYTES);
        if ($rawManifest === null) return self::held('knowledge_unavailable');
        try { $manifest = json_decode($rawManifest, true, 24, JSON_THROW_ON_ERROR); }
        catch (Throwable $ignored) { return self::held('manifest_invalid'); }
        [$entry, $reason] = self::binding($manifest, $selector);
        if ($entry === null) return self::held($reason);
        // File paths are repository-owned, bounded basenames, never HTTP fields.
        $bytes = self::read($directory, $entry['bundle_file'], self::MAX_REFERENCE_BYTES);
        return self::select($manifest, $bytes, $selector, $now);
    }

    /** Pure approval/integrity boundary, also usable by controlled offline tests. */
    private static function select($manifest, ?string $bytes, array $selector, int $now): array
    {
        if (!self::validSelector($selector) || $now <= 0) return self::held('selector_unavailable');
        [$entry, $reason] = self::binding($manifest, $selector);
        if ($entry === null) return self::held($reason);
        $version = ['schema'=>1, 'usage_policy'=>hash('sha256', self::instructions()), 'binding'=>$entry, 'selector'=>$selector,
            'observed_bundle_sha256'=>$bytes === null ? null : hash('sha256', $bytes)];
        if ($bytes === null) return self::held('knowledge_unavailable', $version);
        if (strlen($bytes) > self::MAX_REFERENCE_BYTES || !hash_equals($entry['bundle_sha256'], hash('sha256', $bytes))) {
            return self::held('bundle_integrity_failed', $version);
        }
        try {
            $bundle = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
            $core = $entry['accepted_binding'];
            if (!self::validBundle($bundle, $core, $entry['binding_revision'])) return self::held('bundle_invalid', $version);
            // Only explicit structured boundaries affect eligibility. Retrieval
            // dates, undated publications and wall clock alone are not expiry.
            foreach (array_merge([$bundle], $bundle['facts'], $bundle['sources']) as $record) {
                if (!self::withinValidity($record, $now)) return self::held('outside_explicit_validity', $version);
            }
            $result = [
                'schema_version'=>'hotel_reference_v1', 'status'=>'available',
                'hotel_ref'=>$core['hotel_ref'], 'research_hotel_key'=>$core['research_hotel_key'],
                'binding_revision'=>$entry['binding_revision'],
                'knowledge_revision'=>hash('sha256', self::canonical([
                    'schema'=>1, 'usage_policy'=>hash('sha256', self::instructions()), 'binding'=>$entry,
                    'bundle_sha256'=>$entry['bundle_sha256'], 'eligibility'=>'eligible',
                    'selector'=>$selector,
                ])),
                'selector_provenance'=>$selector['provenance'], 'selected_hotel'=>$selector['selected_hotel'],
                'selected_room'=>$selector['selected_room'],
                'facts'=>$bundle['facts'], 'sources'=>$bundle['sources'],
                'record_hashes'=>$bundle['record_hashes'],
                'material_limits'=>$bundle['material_limits'], 'coverage'=>$bundle['coverage'],
                'price_authority'=>'none', 'applicability'=>'conditional_published_information',
                'room_tariff_equivalence_verified'=>false,
                'authoring_status'=>$bundle['status'], 'future_operation_verified'=>false,
            ];
            // The group and its related source-specific limits are indivisible.
            if (strlen(self::canonical($result)) > self::MAX_REFERENCE_BYTES) return self::held('projection_too_large', $version);
            return $result;
        } catch (Throwable $ignored) { return self::held('bundle_invalid', $version); }
    }

    private static function binding($manifest, array $selector): array
    {
        if (!is_array($manifest) || !self::keys($manifest, ['schema_version','version','project_key','bindings'])
            || ($manifest['schema_version'] ?? null) !== 1 || ($manifest['project_key'] ?? null) !== 'anytour'
            || !self::text($manifest['version'] ?? null, 80)
            || !is_array($manifest['bindings'] ?? null) || !array_is_list($manifest['bindings'])
            || count($manifest['bindings']) > 32) return [null, 'manifest_invalid'];
        $found = null; $identities = []; $hotels = [];
        foreach ($manifest['bindings'] as $entry) {
            if (!is_array($entry) || !self::keys($entry, ['status','source_identity','accepted_source_names','accepted_binding','binding_revision','bundle_file','bundle_sha256','approval'])
                || ($entry['status'] ?? null) !== 'accepted' || !self::validSourceIdentity($entry['source_identity'] ?? null)
                || !self::validSourceNames($entry['accepted_source_names'] ?? null)
                || !self::hex($entry['binding_revision'] ?? null) || !self::hex($entry['bundle_sha256'] ?? null)
                || !is_string($entry['bundle_file'] ?? null)
                || !preg_match('/\Aanytour-[1-9][0-9]{0,8}-[a-z0-9]+(?:-[a-z0-9]+)*\.json\z/D', $entry['bundle_file'])
                || !self::validApproval($entry['approval'] ?? null) || !self::validCore($entry['accepted_binding'] ?? null)) {
                return [null, 'manifest_invalid'];
            }
            $core = $entry['accepted_binding'];
            if (!str_starts_with($entry['bundle_file'], 'anytour-'.$core['hotel_ref']['id'].'-')
                || !hash_equals($entry['binding_revision'], hash('sha256', self::canonical($core)))) return [null, 'manifest_invalid'];
            $sourceKey = self::canonical($entry['source_identity']);
            $hotelKey = self::canonical($core['hotel_ref']);
            if (isset($identities[$sourceKey]) || isset($hotels[$hotelKey])) return [null, 'manifest_ambiguous'];
            $identities[$sourceKey] = true; $hotels[$hotelKey] = true;
            $local = $core['accepted_chain'][2];
            // This first accepted source map is the saved Egypt/Tourvisor edge.
            // Other countries need their own accepted identity evidence/slice.
            if ((string)$local['id'] !== $entry['source_identity']['hotel']
                || $entry['source_identity']['country'] !== '1' || $core['identity_anchor']['country'] !== 'Egypt') return [null, 'manifest_invalid'];
            if (self::canonical($entry['source_identity']) === self::canonical($selector['source_identity'])) {
                // Identity still comes from the accepted typed bridge. This
                // negative consistency guard stops a retained native key from
                // importing these facts under an edited, different hotel name.
                $names = array_map([self::class, 'foldName'], $entry['accepted_source_names']);
                if (!in_array(self::foldName($selector['selected_hotel']), $names, true)) return [null, 'source_name_mismatch'];
                $found = $entry;
            }
        }
        return [$found, $found === null ? 'binding_unresolved' : 'available'];
    }

    private static function validCore($core): bool
    {
        if (!is_array($core) || !self::keys($core, ['project_key','research_hotel_key','hotel_ref','accepted_chain','identity_anchor','proofs'])
            || ($core['project_key'] ?? null) !== 'anytour' || !self::identifier($core['research_hotel_key'] ?? null)
            || !self::validHotelRef($core['hotel_ref'] ?? null) || !is_array($core['accepted_chain'] ?? null)
            || !array_is_list($core['accepted_chain']) || count($core['accepted_chain']) !== 3
            || !is_array($core['identity_anchor'] ?? null) || !is_array($core['proofs'] ?? null)) return false;
        $anchor = $core['identity_anchor'];
        if (!self::keys($anchor, ['research_source_id','research_source_record_sha256','official_url','address','country','city'])
            || !self::identifier($anchor['research_source_id'] ?? null) || !self::hex($anchor['research_source_record_sha256'] ?? null)
            || !self::httpsUrl($anchor['official_url'] ?? null) || !self::text($anchor['address'] ?? null, 500)
            || !self::text($anchor['country'] ?? null, 80) || !self::text($anchor['city'] ?? null, 160)
            || count($core['proofs']) < 3 || count($core['proofs']) > 10) return false;
        foreach ($core['proofs'] as $name=>$hash) {
            if (!is_string($name) || !preg_match('/\A[a-z0-9]+(?:[-_.][a-z0-9]+)*\.(?:raw|json)\z/D', $name) || !self::hex($hash)) return false;
        }
        $chain = $core['accepted_chain'];
        return is_array($chain[0]) && self::keys($chain[0], ['namespace','id'])
            && is_array($chain[1]) && self::keys($chain[1], ['namespace','id','catalog_revision'])
            && is_array($chain[2]) && self::keys($chain[2], ['namespace','id','catalog_revision'])
            && ($chain[0]['namespace'] ?? null) === 'legacy_catalog/catalog_hotels.id' && self::id($chain[0]['id'] ?? null)
            && ($chain[1]['namespace'] ?? null) === 'anytour' && ($chain[1]['id'] ?? null) === $core['hotel_ref']['id']
            && self::id($chain[1]['catalog_revision'] ?? null)
            && ($chain[2]['namespace'] ?? null) === 'local-tv' && self::id($chain[2]['id'] ?? null)
            && self::id($chain[2]['catalog_revision'] ?? null);
    }

    private static function validApproval($approval): bool
    {
        return is_array($approval) && self::keys($approval, ['library_file_id','common_version','member','scope'])
            && is_string($approval['library_file_id'] ?? null)
            && preg_match('/\Alibfile_[a-f0-9]{32}\z/D', $approval['library_file_id']) === 1
            && self::id($approval['common_version'] ?? null) && self::text($approval['scope'] ?? null, 1000)
            && is_string($approval['member'] ?? null)
            && preg_match('~\A[a-z0-9]+(?:[-_][a-z0-9]+)*(?:/[a-z0-9]+(?:[-_][a-z0-9]+)*)*/accepted-binding\.json\z~D', $approval['member']) === 1;
    }

    private static function validBundle($bundle, array $core, string $bindingRevision): bool
    {
        if (!is_array($bundle) || ($bundle['schema_version'] ?? null) !== 'hotel_reference_v1'
            || ($bundle['status'] ?? null) !== 'SEALED_AUTHORING_DATA_ONLY'
            || ($bundle['project_key'] ?? null) !== 'anytour' || ($bundle['hotel_ref'] ?? null) !== $core['hotel_ref']
            || ($bundle['research_hotel_key'] ?? null) !== $core['research_hotel_key']
            || ($bundle['binding_revision'] ?? null) !== $bindingRevision || ($bundle['price_authority'] ?? null) !== 'none'
            || ($bundle['trusted_selector_required'] ?? null) !== true || ($bundle['runtime_admission_verified'] ?? null) !== false
            || !is_array($bundle['facts'] ?? null) || !array_is_list($bundle['facts']) || !$bundle['facts'] || count($bundle['facts']) > 20
            || !is_array($bundle['sources'] ?? null) || !array_is_list($bundle['sources']) || !$bundle['sources'] || count($bundle['sources']) > 20
            || !is_array($bundle['material_limits'] ?? null) || !$bundle['material_limits']
            || !is_array($bundle['coverage'] ?? null) || ($bundle['coverage']['complete'] ?? null) !== false
            || ($bundle['coverage']['selected_claim_count'] ?? null) !== count($bundle['facts'])
            || !is_array($bundle['record_hashes'] ?? null)) return false;
        $sources = [];
        foreach ($bundle['sources'] as $source) {
            if (!self::record($source, $core['research_hotel_key']) || isset($sources[$source['id']])
                || !self::httpsUrl($source['url'] ?? null)
                || !in_array($source['kind'] ?? null, ['official','operator'], true)
                || !self::text($source['read_scope'] ?? null, 2000) || !self::text($source['limitations'] ?? null, 2000)
                || !array_key_exists('publication_date', $source) || !self::dateOrNull($source['publication_date'])
                || !self::date($source['retrieved_on'] ?? null)) return false;
            $sources[$source['id']] = true;
        }
        $claims = [];
        foreach ($bundle['facts'] as $fact) {
            if (!self::record($fact, $core['research_hotel_key']) || isset($claims[$fact['id']])
                || !self::text($fact['text'] ?? null, 4000) || !self::text($fact['aspect'] ?? null, 120)
                || !in_array($fact['kind'] ?? null, ['official_declared','operator_declared'], true)
                || ($fact['valid_at_travel'] ?? null) !== 'unverified' || ($fact['declared'] ?? null) !== true
                || !is_array($fact['scope'] ?? null) || !self::text($fact['scope']['kind'] ?? null, 80)
                || !self::text($fact['scope']['key'] ?? null, 300)
                || !is_array($fact['source_ids'] ?? null) || !array_is_list($fact['source_ids']) || !$fact['source_ids']
                || count(array_unique($fact['source_ids'], SORT_REGULAR)) !== count($fact['source_ids'])) return false;
            foreach ($fact['source_ids'] as $id) if (!is_string($id) || !isset($sources[$id])) return false;
            $claims[$fact['id']] = true;
        }
        foreach (['claims'=>'facts','sources'=>'sources'] as $group=>$field) {
            $hashes = $bundle['record_hashes'][$group] ?? null;
            if (!is_array($hashes) || count($hashes) !== count($bundle[$field])) return false;
            foreach ($bundle[$field] as $record) {
                if (!self::hex($hashes[$record['id']] ?? null)
                    || !hash_equals($hashes[$record['id']], hash('sha256', self::canonical($record)))) return false;
            }
        }
        return true;
    }

    private static function validSelector(array $selector): bool
    {
        if (!self::keys($selector, ['project_key','source_identity','provenance','selected_hotel','selected_room'])
            || ($selector['project_key'] ?? null) !== 'anytour' || !self::validSourceIdentity($selector['source_identity'] ?? null)
            || !self::text($selector['selected_hotel'] ?? null, 191)
            || !self::text($selector['selected_room'] ?? null, 300) || !is_array($selector['provenance'] ?? null)
            || !self::keys($selector['provenance'], ['message_id','event_id','publication_sha256','source_sha256'])) return false;
        $p = $selector['provenance'];
        return self::id($p['message_id'] ?? null) && self::id($p['event_id'] ?? null)
            && self::hex($p['publication_sha256'] ?? null) && self::hex($p['source_sha256'] ?? null);
    }

    private static function validSourceIdentity($value): bool
    {
        return is_array($value) && self::keys($value, ['adapter','hotel','country'])
            && ($value['adapter'] ?? null) === 'tourvisor-snapshot-v1'
            && is_string($value['hotel'] ?? null) && preg_match('/\A[1-9][0-9]{0,8}\z/D', $value['hotel']) === 1
            && is_string($value['country'] ?? null) && preg_match('/\A[1-9][0-9]{0,8}\z/D', $value['country']) === 1;
    }

    private static function validSourceNames($names): bool
    {
        if (!is_array($names) || !array_is_list($names) || !$names || count($names) > 8) return false;
        $seen = [];
        foreach ($names as $name) {
            if (!self::text($name, 191)) return false;
            $folded = self::foldName($name);
            if ($folded === '' || isset($seen[$folded])) return false;
            $seen[$folded] = true;
        }
        return true;
    }

    private static function foldName(string $name): string
    {
        $name = trim((string)preg_replace('/(*UCP)\s+/u', ' ', $name));
        return function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name);
    }

    private static function validHotelRef($ref): bool
    {
        return is_array($ref) && self::keys($ref, ['catalog','id']) && ($ref['catalog'] ?? null) === 'anytour' && self::id($ref['id'] ?? null);
    }

    private static function withinValidity(array $record, int $now): bool
    {
        $from = null; $until = null;
        foreach (['valid_from','valid_until','expires_at'] as $key) {
            if (!array_key_exists($key, $record) || $record[$key] === null) continue;
            $value = self::timestamp($record[$key]);
            if ($value === null) return false;
            if ($key === 'valid_from') $from = $value;
            else $until = $until === null ? $value : min($until, $value);
        }
        return ($from === null || $now >= $from) && ($until === null || $now < $until)
            && ($from === null || $until === null || $from < $until);
    }

    private static function timestamp($value): ?int
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $value)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value ? $date->getTimestamp() : null;
    }

    private static function dateOrNull($value): bool { return $value === null || self::date($value); }
    private static function date($value): bool
    {
        return is_string($value) && preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $value, $m) === 1
            && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }
    private static function record($record, string $hotel): bool
    {
        return is_array($record) && self::identifier($record['id'] ?? null) && ($record['hotel_key'] ?? null) === $hotel;
    }
    private static function identifier($value): bool { return is_string($value) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,159}\z/D', $value) === 1; }
    private static function hex($value): bool { return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1; }
    private static function id($value): bool { return is_int($value) && $value > 0; }
    private static function keys(array $value, array $keys): bool { return count($value) === count($keys) && !array_diff(array_keys($value), $keys); }
    private static function httpsUrl($value): bool
    {
        if (!self::text($value, 2048)) return false;
        $url = parse_url($value);
        return is_array($url) && ($url['scheme'] ?? null) === 'https' && self::text($url['host'] ?? null, 253)
            && !isset($url['user']) && !isset($url['pass']) && !isset($url['port']) && !isset($url['fragment']);
    }
    private static function text($value, int $limit): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= $limit * 4
            && preg_match('//u', $value) === 1 && preg_match_all('/./us', $value) <= $limit
            && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 0;
    }

    private static function canonical($value): string
    {
        $sort = static function ($item) use (&$sort) {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key=>$child) $item[$key] = $sort($child);
            return $item;
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function held(string $reason, array $version = []): array
    {
        return ['schema_version'=>'hotel_reference_v1', 'status'=>'held', 'reason'=>$reason,
            'knowledge_revision'=>hash('sha256', self::canonical(['schema'=>1, 'status'=>'held', 'reason'=>$reason, 'version'=>$version])),
            'facts'=>[], 'sources'=>[], 'coverage'=>['complete'=>false], 'price_authority'=>'none'];
    }

    private static function read(string $directory, string $name, int $limit): ?string
    {
        if (is_link(dirname($directory)) || is_link($directory) || !is_dir($directory)
            || basename($name) !== $name || $name === '.' || $name === '..') return null;
        $path = $directory.'/'.$name;
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000 || $before['size'] > $limit) return null;
        $stream = @fopen($path, 'rb');
        if ($stream === false) return null;
        try {
            $stat = fstat($stream);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
                || $stat['dev'] !== $before['dev'] || $stat['ino'] !== $before['ino'] || $stat['size'] > $limit) return null;
            $bytes = stream_get_contents($stream, $limit + 1);
            $after = fstat($stream);
            return is_string($bytes) && strlen($bytes) <= $limit && is_array($after)
                && $stat['size'] === $after['size'] && strlen($bytes) === $after['size'] ? $bytes : null;
        } finally { fclose($stream); }
    }
}
