<?php

declare(strict_types=1);

require_once __DIR__.'/ManagerHotelKnowledge.php';
require_once __DIR__.'/TourChoiceService.php';
require_once __DIR__.'/ManagerTourSelectionImportDraft.php';

/** Read-only identity evidence from an explicit, native web-selection inquiry. */
final class ManagerHotelReferenceContext
{
    private const MAX_METADATA_BYTES = 16384;
    private const MAX_EVENT_BYTES = 65536;
    private const MAX_PUBLICATION_BYTES = 1048576;
    private const MAX_BINDING_BYTES = 16384;
    private const MAX_EVENTS = 64;

    /** The caller owns authorization and its consistent read-only snapshot. */
    public static function capture(PDO $pdo, array $conversation, array $context): array
    {
        try {
            if (($conversation['project_key'] ?? null) !== 'anytour') return self::held('unsupported_project');
            $conversationId = self::id($conversation['id'] ?? null);
            $cutoff = self::id($context['as_of_message_id'] ?? null);
            if ($conversationId === null || $cutoff === null
                || self::id($context['conversation_id'] ?? null) !== $conversationId
                || !is_array($context['messages'] ?? null) || !array_is_list($context['messages'])
                || count($context['messages']) > 120) return self::held('selector_context_unavailable');

            // Inspect the last real customer activity, not the last matching
            // inquiry. A newer text/callback must not revive an older hotel.
            $q = $pdo->prepare('SELECT id,direction,created_at,CASE WHEN LENGTH(metadata_json)<='.self::MAX_METADATA_BYTES.' THEN metadata_json ELSE NULL END AS metadata_json FROM messages WHERE conversation_id=? AND sender_type=\'customer\' AND id<=? ORDER BY id DESC LIMIT 1');
            $q->execute([$conversationId, $cutoff]);
            $message = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($message) || ($message['direction'] ?? null) !== 'inbound') return self::held('trusted_selector_missing');
            $messageId = self::id($message['id'] ?? null);
            $retained = null;
            foreach ($context['messages'] as $row) {
                if (is_array($row) && self::id($row['id'] ?? null) === $messageId) {
                    if ($retained !== null) return self::held('selector_context_ambiguous');
                    $retained = $row;
                }
            }
            if ($messageId === null || $retained === null || ($retained['sender_type'] ?? null) !== 'customer'
                || !empty($retained['text_truncated'])) return self::held('selector_not_retained');
            $metadata = self::decode($message['metadata_json'] ?? null, self::MAX_METADATA_BYTES, 16);
            if (($metadata['type'] ?? null) !== 'tour_web_inquiry') return self::held('current_intent_unbound');
            $tuple = self::tuple($metadata);
            $messageTime = self::timestamp($message['created_at'] ?? null);
            if ($tuple === null || $messageTime === null) return self::held('selector_metadata_unavailable');

            // Event and message IDs belong to separate sequences. Correlate
            // their immutable tuple, not numeric ordering or second equality.
            $q = $pdo->prepare('SELECT id,created_at,CASE WHEN LENGTH(payload_json)<='.self::MAX_EVENT_BYTES.' THEN payload_json ELSE NULL END AS payload_json FROM conversation_events WHERE conversation_id=? AND event_type=\'tour_web_inquiry\' ORDER BY id DESC LIMIT '.(self::MAX_EVENTS + 1));
            $q->execute([$conversationId]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > self::MAX_EVENTS) return self::held('selector_event_coverage_incomplete');
            $matches = [];
            foreach ($rows as $row) {
                $payload = self::decode($row['payload_json'] ?? null, self::MAX_EVENT_BYTES, 32);
                // Unknown/unread events prevent an exact uniqueness proof.
                if ($payload === null) return self::held('selector_event_unavailable');
                if (self::tuple($payload) !== $tuple) continue;
                $eventId = self::id($row['id'] ?? null);
                $eventTime = self::timestamp($row['created_at'] ?? null);
                if (($payload['schema'] ?? null) !== 1 || $eventId === null || $eventTime === null
                    || $eventTime > $messageTime || !is_array($payload['public_offer'] ?? null)) return self::held('selector_event_unavailable');
                $matches[] = ['id'=>$eventId, 'payload'=>$payload];
            }
            if (count($matches) !== 1) return self::held('selector_event_ambiguous');

            // A forwarded visitor may refer to another conversation's public
            // publication. Only the project and frozen selected card are used;
            // donor identities/bindings never leave this private reader.
            $q = $pdo->prepare('SELECT CASE WHEN LENGTH(p.selection_json)<='.self::MAX_PUBLICATION_BYTES.' THEN p.selection_json ELSE NULL END AS selection_json,CASE WHEN LENGTH(p.binding_json)<='.self::MAX_BINDING_BYTES.' THEN p.binding_json ELSE NULL END AS binding_json FROM manager_tour_selection_publications p JOIN conversations c ON c.id=p.conversation_id WHERE p.publication_id=? AND c.project_key=? LIMIT 2');
            $q->execute([$tuple['publication_id'], 'anytour']);
            $publications = $q->fetchAll(PDO::FETCH_ASSOC);
            if (count($publications) !== 1) return self::held('selector_publication_unavailable');
            $publication = $publications[0];
            $selection = self::decode($publication['selection_json'] ?? null, self::MAX_PUBLICATION_BYTES, 48);
            $binding = self::decode($publication['binding_json'] ?? null, self::MAX_BINDING_BYTES, 16);
            if (($binding['project_key'] ?? null) !== 'anytour' || !is_array($selection['offers'] ?? null)
                || !array_is_list($selection['offers']) || count($selection['offers']) < 1
                || count($selection['offers']) > 20) return self::held('selector_publication_unavailable');
            $index = $tuple['variant'] - 1;
            $entry = $selection['offers'][$index] ?? null;
            $snapshot = is_array($entry) && is_array($entry['snapshot'] ?? null) ? $entry['snapshot'] : null;
            if ($snapshot === null || !self::same(TourChoiceService::normalize($snapshot), $snapshot)
                || !self::same(self::publicOffer($snapshot, $index), $matches[0]['payload']['public_offer'])) return self::held('selector_quote_mismatch');

            $source = is_array($entry['import_source'] ?? null) ? $entry['import_source'] : null;
            if ($source === null || ($source['adapter'] ?? null) !== 'tourvisor-snapshot-v1'
                || array_diff(array_keys($source), ['adapter','url','observed_at','quote_id','keys','fields','raw','photos'])) return self::held('selector_source_unavailable');
            $normalized = ManagerTourSelectionImportDraft::normalize([
                'adapter'=>$source['adapter'], 'url'=>$source['url'] ?? null,
                'observed_at'=>$source['observed_at'] ?? null, 'quote_id'=>$source['quote_id'] ?? null,
                'keys'=>$source['keys'] ?? null, 'offer'=>$source['fields'] ?? null, 'raw'=>$source['raw'] ?? null,
                ...(array_key_exists('photos', $source) ? ['photos'=>$source['photos']] : []),
            ]);
            if ($normalized === null || !self::same(ManagerTourSelectionImportDraft::source($normalized), $source)) return self::held('selector_source_unavailable');
            $fields = $source['fields'];
            $sourceHotel = $fields['hotel'].(!empty($fields['stars']) ? ' '.$fields['stars'].'★' : '');
            $details = $snapshot['quoted_details'] ?? [];
            // Import provenance survives manager edits. A renamed hotel must
            // not inherit the original hotel's source ID or knowledge.
            if ($snapshot['hotel'] !== $sourceHotel || ($details['source_url'] ?? null) !== $source['url']
                || ($details['observed_at'] ?? null) !== $source['observed_at']
                || ($details['destination'] ?? null) !== $fields['destination']) return self::held('selector_source_identity_changed');

            $selector = [
                'project_key'=>'anytour',
                'source_identity'=>['adapter'=>$source['adapter'], 'hotel'=>$source['keys']['hotel'], 'country'=>$source['keys']['country']],
                'selected_hotel'=>$fields['hotel'],
                'provenance'=>[
                    'message_id'=>$messageId, 'event_id'=>$matches[0]['id'],
                    'publication_sha256'=>hash('sha256', $publication['binding_json']."\n".$publication['selection_json']),
                    'source_sha256'=>hash('sha256', self::canonical($source)),
                ],
                'selected_room'=>$snapshot['room'],
            ];
            return ManagerHotelKnowledge::capture($selector, time());
        } catch (Throwable $ignored) {
            // Optional evidence never repairs storage or blocks an ordinary draft.
            return self::held('selector_unavailable');
        }
    }

    private static function tuple(array $value): ?array
    {
        $publication = $value['publication_id'] ?? null;
        $variant = $value['variant'] ?? null;
        $version = $value['link_version'] ?? null;
        if (!is_string($publication) || !preg_match('/\A[a-f0-9]{32}\z/D', $publication)
            || !is_int($variant) || $variant < 1 || $variant > 20
            || !is_int($version) || $version < 1) return null;
        return ['publication_id'=>$publication, 'variant'=>$variant, 'link_version'=>$version];
    }

    /** Same explicit public fields as ManagerTourSelectionShareService::read. */
    private static function publicOffer(array $snapshot, int $index): array
    {
        $details = $snapshot['quoted_details'] ?? [];
        return [
            'index'=>$index, 'hotel'=>$snapshot['hotel'], 'departure'=>$snapshot['departure'],
            'date_from'=>$snapshot['date_from'], 'nights'=>$snapshot['nights'], 'adults'=>$snapshot['adults'],
            'children'=>count($snapshot['child_ages']), 'room'=>$snapshot['room'], 'meal'=>$snapshot['meal'],
            'amount'=>$snapshot['amount'], 'currency'=>$snapshot['currency'],
            'rationale'=>$snapshot['rationale'], 'compromise'=>$snapshot['compromise'],
            'destination'=>(string)($details['destination'] ?? ''), 'flights'=>(string)($details['flights'] ?? ''),
            'inclusions'=>(string)($details['inclusions'] ?? ''), 'constraints'=>(string)($details['constraints'] ?? ''),
            'observed_at'=>(string)($details['observed_at'] ?? ''), 'price_verified'=>false,
        ];
    }

    private static function decode(mixed $bytes, int $limit, int $depth): ?array
    {
        if (!is_string($bytes) || strlen($bytes) > $limit) return null;
        $value = json_decode($bytes, true, $depth, JSON_THROW_ON_ERROR);
        return is_array($value) && !array_is_list($value) ? $value : null;
    }

    private static function id(mixed $value): ?int
    {
        if (is_int($value)) return $value > 0 ? $value : null;
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]*\z/D', $value)) return null;
        $number = (int)$value;
        return $number > 0 && (string)$number === $value ? $number : null;
    }

    private static function timestamp(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $value : null;
    }

    private static function same(mixed $left, mixed $right): bool
    {
        return self::canonical($left) === self::canonical($right);
    }

    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $row) use (&$sort): mixed {
            if (!is_array($row)) return $row;
            if (!array_is_list($row)) ksort($row, SORT_STRING);
            foreach ($row as &$item) $item = $sort($item);
            unset($item);
            return $row;
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function held(string $reason): array
    {
        return ['schema_version'=>'hotel_reference_v1', 'status'=>'held', 'reason'=>$reason,
            'hotel_ref'=>null, 'facts'=>[], 'sources'=>[], 'coverage'=>['complete'=>false, 'runtime_claims_admitted'=>0],
            'price_authority'=>'none'];
    }
}
