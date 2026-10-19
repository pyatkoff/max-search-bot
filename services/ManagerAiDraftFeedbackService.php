<?php

declare(strict_types=1);

require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ManagerConversationService.php';
require_once __DIR__.'/ManagerAiDraftService.php';

/** Private provenance only. A request to insert is neither insertion nor acceptance. */
final class ManagerAiDraftFeedbackService
{
    public const MAX_PROPOSAL_BYTES = 32768;
    public const ATTRIBUTION_SECONDS = 86400;
    private const FIELDS = ['ok','should_draft','draft','next_action','reason','checks','evidence_message_ids','history_incomplete'];
    private const ACTIONS = ['clarify','answer_question','check_offer','prepare_selection','no_contact'];

    public static function record(int $cid, int $actor, array $snapshot, array $proposal): ?string
    {
        try {
            $pdo = ConversationDb::connection();
            if ($pdo->inTransaction()) return null;
            $scope = self::ownedConversation($pdo, $cid, $actor);
            $context = $snapshot['context'] ?? null;
            $revision = $snapshot['revision'] ?? null;
            $latest = $snapshot['latest_message_id'] ?? null;
            if ($scope === null || ($snapshot['ok'] ?? null) !== true || !self::revision($revision)
                || !is_int($latest) || $latest <= 0 || !is_array($context)
                || ($context['conversation_id'] ?? null) !== $cid
                || ($context['as_of_message_id'] ?? null) !== $latest
                || !is_bool($context['history_incomplete'] ?? null)
                || !is_array($context['messages'] ?? null) || !array_is_list($context['messages'])
                || count($context['messages']) > ManagerAiDraftService::MAX_MESSAGES) return null;
            $ids = [];
            foreach ($context['messages'] as $message) {
                $id = is_array($message) ? ($message['id'] ?? null) : null;
                if (!is_int($id) || $id <= 0 || $id > $latest || isset($ids[$id])) return null;
                $ids[$id] = true;
            }
            if (!isset($ids[$latest]) || !self::validProposal($proposal, $ids)
                || $proposal['history_incomplete'] !== $context['history_incomplete']) return null;
            $copy = [];
            foreach (self::FIELDS as $field) $copy[$field] = $proposal[$field];
            $json = json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($json) > self::MAX_PROPOSAL_BYTES) return null;
            $id = bin2hex(random_bytes(16));
            $q = $pdo->prepare('INSERT INTO manager_ai_drafts (draft_id,conversation_id,manager_id,project_key,context_revision,instruction_revision,latest_message_id,proposal_json,created_at) VALUES (?,?,?,?,?,?,?,?,?)');
            if (!$q->execute([$id,$cid,$actor,$scope['project_key'],$revision,
                hash('sha256', ManagerAiDraftService::instructions()),$latest,$json,gmdate('Y-m-d H:i:s')])) return null;
            return $id;
        } catch (Throwable $ignored) {
            return null;
        }
    }

    /** Caller also performs the canonical fresh-context check before this observation. */
    public static function markInsertRequested(int $cid, int $actor, string $draftId, string $revision, int $latest): ?string
    {
        if (!self::draftId($draftId) || !self::revision($revision) || $latest <= 0) return null;
        try {
            $pdo = ConversationDb::connection();
            if ($pdo->inTransaction()) return null;
            $scope = self::ownedConversation($pdo, $cid, $actor);
            if ($scope === null) return null;
            $row = self::load($pdo, $cid, $actor, $scope['project_key'], $draftId);
            if ($row === null || !hash_equals($row['context_revision'], $revision)
                || (int)$row['latest_message_id'] !== $latest) return null;
            $proposal = self::proposal($row);
            if ($proposal === null || !$proposal['should_draft']) return null;
            if ($row['insert_requested_at'] === null) {
                $q = $pdo->prepare('UPDATE manager_ai_drafts SET insert_requested_at=? WHERE draft_id=? AND conversation_id=? AND manager_id=? AND project_key=? AND context_revision=? AND latest_message_id=? AND insert_requested_at IS NULL');
                if (!$q->execute([gmdate('Y-m-d H:i:s'),$draftId,$cid,$actor,$scope['project_key'],$revision,$latest])) return null;
                $row = self::load($pdo, $cid, $actor, $scope['project_key'], $draftId);
            }
            return $row !== null && self::requested($row) ? $draftId : null;
        } catch (Throwable $ignored) {
            return null;
        }
    }

    /** Metadata is associated only by the owning outbound path's canonical INSERT. */
    public static function forMessage(int $cid, int $actor, string $projectKey, ?string $draftId, string $sentText): ?array
    {
        if ($draftId === null || !self::draftId($draftId) || !self::validText($sentText, 1, 20000)) return null;
        try {
            $pdo = ConversationDb::connection();
            if ($pdo->inTransaction()) return null;
            $scope = self::ownedConversation($pdo, $cid, $actor);
            if ($scope === null || $scope['project_key'] !== $projectKey) return null;
            $row = self::load($pdo, $cid, $actor, $projectKey, $draftId);
            if ($row === null || !self::requested($row)) return null;
            $proposal = self::proposal($row);
            if ($proposal === null || !$proposal['should_draft']) return null;
            $text = trim($sentText);
            return ['draft_id'=>$draftId,
                'text_matches_proposal'=>$text === trim($proposal['draft']),
                'sent_text_sha256'=>hash('sha256', $text)];
        } catch (Throwable $ignored) {
            return null;
        }
    }

    private static function ownedConversation(PDO $pdo, int $cid, int $actor): ?array
    {
        if ($cid <= 0 || $actor <= 0) return null;
        // Do not allow the legacy auth initialization to UPSERT project metadata.
        ProjectAccessService::initializeReadOnly();
        $q = $pdo->prepare('SELECT id FROM managers WHERE id=? AND is_active=1');
        if (!$q->execute([$actor]) || !$q->fetchColumn()) return null;
        $conversation = ManagerConversationService::visibleConversation($cid, $actor);
        if ($conversation === null || (string)($conversation['status'] ?? '') !== 'manager'
            || (int)($conversation['manager_id'] ?? 0) !== $actor
            || !self::validText($conversation['project_key'] ?? null, 1, 64)) return null;
        return ['project_key'=>$conversation['project_key']];
    }

    private static function load(PDO $pdo, int $cid, int $actor, string $project, string $id): ?array
    {
        $q = $pdo->prepare('SELECT project_key,context_revision,instruction_revision,latest_message_id,proposal_json,created_at,insert_requested_at FROM manager_ai_drafts WHERE draft_id=? AND conversation_id=? AND manager_id=? AND project_key=?');
        if (!$q->execute([$id,$cid,$actor,$project])) return null;
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($row['project_key'] ?? null) !== $project
            || !self::revision($row['context_revision'] ?? null) || !self::revision($row['instruction_revision'] ?? null)
            || filter_var($row['latest_message_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false) return null;
        $created = self::timestamp($row['created_at'] ?? null);
        $now = time();
        if ($created === null || $created > $now || $now - $created > self::ATTRIBUTION_SECONDS) return null;
        return $row;
    }

    private static function requested(array $row): bool
    {
        $created = self::timestamp($row['created_at'] ?? null);
        $requested = self::timestamp($row['insert_requested_at'] ?? null);
        return $created !== null && $requested !== null && $requested >= $created && $requested <= time();
    }

    private static function proposal(array $row): ?array
    {
        $json = $row['proposal_json'] ?? null;
        if (!is_string($json) || strlen($json) > self::MAX_PROPOSAL_BYTES) return null;
        $proposal = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        return is_array($proposal) && self::validProposal($proposal) ? $proposal : null;
    }

    private static function validProposal(array $value, ?array $allowedIds = null): bool
    {
        if (array_is_list($value) || count($value) !== count(self::FIELDS) || array_diff(self::FIELDS, array_keys($value))
            || $value['ok'] !== true || !is_bool($value['should_draft']) || !is_bool($value['history_incomplete'])
            || !is_string($value['next_action']) || !in_array($value['next_action'], self::ACTIONS, true)
            || !self::validText($value['reason'], 1, 1000)
            || !is_array($value['checks']) || !array_is_list($value['checks']) || count($value['checks']) > 10
            || !is_array($value['evidence_message_ids']) || !array_is_list($value['evidence_message_ids'])
            || !$value['evidence_message_ids'] || count($value['evidence_message_ids']) > 20) return false;
        if ($value['should_draft']) {
            if ($value['next_action'] === 'no_contact' || !self::validText($value['draft'], 1, ManagerAiDraftService::MAX_DRAFT_CHARACTERS)) return false;
        } elseif ($value['draft'] !== null) return false;
        foreach ($value['checks'] as $check) if (!self::validText($check, 1, 400)) return false;
        $seen = [];
        foreach ($value['evidence_message_ids'] as $id) {
            if (!is_int($id) || $id <= 0 || isset($seen[$id]) || ($allowedIds !== null && !isset($allowedIds[$id]))) return false;
            $seen[$id] = true;
        }
        return true;
    }

    private static function validText($value, int $minimum, int $maximum): bool
    {
        return is_string($value) && strlen($value) <= $maximum * 4 && preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 0
            && preg_match_all('/./us', $value) <= $maximum && preg_match_all('/./us', trim($value)) >= $minimum;
    }

    private static function draftId($value): bool { return is_string($value) && preg_match('/\A[a-f0-9]{32}\z/D', $value) === 1; }
    private static function revision($value): bool { return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1; }
    private static function timestamp($value): ?int
    {
        if (!is_string($value)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $date->getTimestamp() : null;
    }
}
