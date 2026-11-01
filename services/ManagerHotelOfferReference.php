<?php

declare(strict_types=1);

require_once __DIR__.'/ManagerHotelKnowledge.php';
require_once __DIR__.'/ManagerTourSelectionDraft.php';
require_once __DIR__.'/ManagerTourSelectionImportDraft.php';
require_once __DIR__.'/TourChoiceService.php';

/** Pure identity projection. The caller owns authorization and source freshness. */
final class ManagerHotelOfferReference
{
    public const MAX_ENTRY_BYTES = 131072;

    /** A normalized working offer or immutable publication entry, never client identity. */
    public static function selector(array $entry, array $provenance): array
    {
        try {
            if (!ManagerHotelKnowledge::validProvenance($provenance)) return self::held('selector_provenance_unavailable');
            if (strlen(self::canonical($entry)) > self::MAX_ENTRY_BYTES) return self::held('selector_entry_too_large');
            $kind = $provenance['kind'] ?? 'native_inquiry';
            $source = $entry['import_source'] ?? null;
            if (!is_array($source) || ($source['adapter'] ?? null) !== 'tourvisor-snapshot-v1'
                || array_diff(array_keys($source), ['adapter','url','observed_at','quote_id','keys','fields','raw','photos'])) {
                return self::held('selector_source_unavailable');
            }
            $normalized = ManagerTourSelectionImportDraft::normalize([
                'adapter'=>$source['adapter'], 'url'=>$source['url'] ?? null,
                'observed_at'=>$source['observed_at'] ?? null, 'quote_id'=>$source['quote_id'] ?? null,
                'keys'=>$source['keys'] ?? null, 'offer'=>$source['fields'] ?? null, 'raw'=>$source['raw'] ?? null,
                ...(array_key_exists('photos', $source) ? ['photos'=>$source['photos']] : []),
            ]);
            if ($normalized === null || !self::same(ManagerTourSelectionImportDraft::source($normalized), $source)) {
                return self::held('selector_source_unavailable');
            }
            if (!hash_equals($provenance['source_sha256'], self::hash($source))) return self::held('selector_source_changed');
            $fields = $source['fields'];
            if ($kind === 'working_draft') {
                $draft = ManagerTourSelectionDraft::normalize(['title'=>'', 'intro'=>'', 'offers'=>[$entry]]);
                if ($draft === null || !self::same($draft['offers'][0], $entry)
                    || $entry['id'] !== $provenance['offer_id']) return self::held('selector_entry_unavailable');
                if (!hash_equals($provenance['snapshot_sha256'], self::hash($entry))) return self::held('selector_snapshot_changed');
                $identityMatches = $entry['hotel'] === $fields['hotel'] && $entry['stars'] === $fields['stars']
                    && $entry['destination'] === $fields['destination'] && $entry['source_url'] === $source['url']
                    && $entry['observed_at'] === $source['observed_at'];
                $room = $entry['room'];
            } else {
                if ($kind === 'publication' && (array_diff(array_keys($entry), ['draft_offer_id','snapshot','presentation','import_source','hotel_group'])
                    || ($entry['draft_offer_id'] ?? null) !== $provenance['draft_offer_id'])) return self::held('selector_entry_unavailable');
                $snapshot = $entry['snapshot'] ?? null;
                if (!is_array($snapshot) || !self::same(TourChoiceService::normalize($snapshot), $snapshot)) {
                    return self::held('selector_quote_mismatch');
                }
                $details = $snapshot['quoted_details'] ?? [];
                $sourceHotel = $fields['hotel'].(!empty($fields['stars']) ? ' '.$fields['stars'].'★' : '');
                $identityMatches = $snapshot['hotel'] === $sourceHotel && ($details['source_url'] ?? null) === $source['url']
                    && ($details['observed_at'] ?? null) === $source['observed_at']
                    && ($details['destination'] ?? null) === $fields['destination'];
                $room = $snapshot['room'];
            }
            // Import provenance survives edits, so edited labels cannot inherit
            // the imported property's facts. Room/price edits remain unverified.
            if (!$identityMatches) return self::held('selector_source_identity_changed');
            if ($room === '') return self::held('selector_entry_unavailable');
            return ['project_key'=>'anytour',
                'source_identity'=>['adapter'=>$source['adapter'], 'hotel'=>$source['keys']['hotel'], 'country'=>$source['keys']['country']],
                'selected_hotel'=>$fields['hotel'], 'provenance'=>$provenance, 'selected_room'=>$room];
        } catch (Throwable $ignored) {
            return self::held('selector_source_unavailable');
        }
    }

    public static function capture(array $entry, array $provenance, int $now): array
    {
        $selector = self::selector($entry, $provenance);
        return ($selector['status'] ?? null) === 'held' ? $selector : ManagerHotelKnowledge::capture($selector, $now);
    }

    /** Same canonical source hash as the original native inquiry selector. */
    public static function hash(array $value): string { return hash('sha256', self::canonical($value)); }

    private static function same(mixed $left, mixed $right): bool { return self::canonical($left) === self::canonical($right); }

    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key=>$child) $item[$key] = $sort($child);
            return $item;
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR, 48);
    }

    private static function held(string $reason): array
    {
        return ['schema_version'=>'hotel_reference_v1', 'status'=>'held', 'reason'=>$reason,
            'hotel_ref'=>null, 'facts'=>[], 'sources'=>[], 'coverage'=>['complete'=>false, 'runtime_claims_admitted'=>0],
            'price_authority'=>'none'];
    }
}
