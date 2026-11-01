<?php
declare(strict_types=1);

require_once __DIR__.'/ManagerTourSelectionService.php';
require_once __DIR__.'/ManagerHotelOfferReference.php';

/** One exact saved offer. Reading never selects it for a tourist or updates a draft. */
final class ManagerHotelKnowledgeReadService
{
    private const MAX_JSON_BYTES = 1048576;

    /** Idempotent server-owned session fence: after snapshot and at final return. */
    public static function read(int $actor, int $conversationId, array $input, ?callable $authorizeResponse = null): array
    {
        $pdo = ConversationDb::connection();
        if ($pdo->inTransaction()) return self::error('knowledge_unavailable');
        try {
            ProjectAccessService::initializeReadOnly();
            $scope = self::scope($actor, $conversationId);
            if ($scope === null) return self::error('forbidden');
            if (!self::validInput($input)) return self::error('invalid_selection');
            // Bound the stored JSON before transferring it to PHP. Read a
            // consistent saved version, then recheck outside that transaction.
            $pdo->beginTransaction();
            $row = self::row($pdo, $conversationId, $input);
            $result = self::reference($row, $input);
            $pdo->commit();
            if ($authorizeResponse !== null && $authorizeResponse() !== true) return self::error('unauthorized');
            if (self::row($pdo, $conversationId, $input) !== $row) return self::error('selection_changed');
            $fresh = self::scope($actor, $conversationId);
            if ($fresh === null || $fresh !== $scope) return self::error('forbidden');
            if ($authorizeResponse !== null && $authorizeResponse() !== true) return self::error('unauthorized');
            return $result;
        } catch (Throwable $ignored) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return self::error('knowledge_unavailable');
        }
    }

    private static function scope(int $actor, int $conversationId): ?array
    {
        if (ProjectConfig::projectId() !== 'anytour' || $actor <= 0 || $conversationId <= 0) return null;
        $manager = ManagerAuthService::byId($actor);
        if (!$manager || empty($manager['is_active'])) return null;
        $conversation = ManagerConversationService::visibleConversation($conversationId, $actor);
        if (!$conversation || $conversation['project_key'] !== 'anytour') return null;
        return array_intersect_key($conversation, array_flip([
            'id','project_key','source_id','manager_id','customer_id','channel','external_chat_id','status',
        ]));
    }

    private static function validInput(array $input): bool
    {
        if (($input['kind'] ?? null) === 'working_draft') {
            return !array_diff(array_keys($input), ['kind','draft_version','offer_id'])
                && is_int($input['draft_version'] ?? null) && $input['draft_version'] > 0
                && self::hexId($input['offer_id'] ?? null);
        }
        return ($input['kind'] ?? null) === 'publication'
            && !array_diff(array_keys($input), ['kind','publication_id','card_index'])
            && self::hexId($input['publication_id'] ?? null)
            && is_int($input['card_index'] ?? null) && $input['card_index'] >= 0 && $input['card_index'] < 20;
    }

    private static function row(PDO $pdo, int $conversationId, array $input): ?array
    {
        if ($input['kind'] === 'working_draft') {
            $q = $pdo->prepare('SELECT version,CASE WHEN LENGTH(draft_json)<='.self::MAX_JSON_BYTES.' THEN draft_json ELSE NULL END AS draft_json FROM manager_tour_selection_drafts WHERE conversation_id=? LIMIT 2');
            $q->execute([$conversationId]);
        } else {
            $q = $pdo->prepare('SELECT CASE WHEN LENGTH(selection_json)<='.self::MAX_JSON_BYTES.' THEN selection_json ELSE NULL END AS selection_json,CASE WHEN LENGTH(binding_json)<=16384 THEN binding_json ELSE NULL END AS binding_json FROM manager_tour_selection_publications WHERE conversation_id=? AND publication_id=? LIMIT 2');
            $q->execute([$conversationId, $input['publication_id']]);
        }
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        return count($rows) === 1 ? $rows[0] : null;
    }

    private static function reference(?array $row, array $input): array
    {
        if ($row === null) return self::error('selection_unavailable');
        if ($input['kind'] === 'working_draft') {
            if ((int)$row['version'] !== $input['draft_version']) return self::error('selection_changed');
            $raw = self::decode($row['draft_json']);
            $draft = ManagerTourSelectionDraft::restoreStored($raw);
            // No silent repair or normalization of changed stored provenance.
            if ($draft === null || ManagerHotelOfferReference::hash($draft) !== ManagerHotelOfferReference::hash($raw)) return self::error('selection_unavailable');
            $entries = array_values(array_filter($draft['offers'], static fn(array $offer): bool => $offer['id'] === $input['offer_id']));
            if (count($entries) !== 1) return self::error('selection_changed');
            $entry = $entries[0];
            $provenance = $input + ['snapshot_sha256'=>ManagerHotelOfferReference::hash($entry),
                'source_sha256'=>ManagerHotelOfferReference::hash($entry['import_source'] ?? [])];
        } else {
            $selection = self::decode($row['selection_json']);
            $binding = self::decode($row['binding_json']);
            $offers = $selection['offers'] ?? null;
            if (($binding['project_key'] ?? null) !== 'anytour' || !is_array($offers) || !array_is_list($offers)
                || count($offers) < 1 || count($offers) > 20 || !is_array($offers[$input['card_index']] ?? null)) return self::error('selection_unavailable');
            $entry = $offers[$input['card_index']];
            $provenance = $input + ['draft_offer_id'=>$entry['draft_offer_id'] ?? null,
                'publication_sha256'=>hash('sha256', $row['binding_json']."\n".$row['selection_json']),
                'source_sha256'=>ManagerHotelOfferReference::hash($entry['import_source'] ?? [])];
        }
        $reference = ManagerHotelOfferReference::capture($entry, $provenance, time());
        return ['ok'=>true, 'schema_version'=>'hotel_offer_knowledge_v1', 'selection'=>$input,
            'hotel_reference'=>$reference, 'current_trip_link'=>false, 'customer_choice_verified'=>false,
            'offer_conditions_verified'=>false, 'requires_manager_review'=>true];
    }

    private static function decode(mixed $raw): array
    {
        if (!is_string($raw)) throw new RuntimeException('Stored selection unavailable');
        $value = json_decode($raw, true, 48, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) throw new RuntimeException('Stored selection unavailable');
        return $value;
    }

    private static function hexId(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{32}\z/D', $value) === 1;
    }

    private static function error(string $reason): array { return ['ok'=>false, 'error'=>$reason]; }
}
