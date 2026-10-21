<?php

declare(strict_types=1);

require_once __DIR__.'/ManagerAiDraftContextService.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';

/**
 * One-off private pilot input. Returns bounded, redacted context in memory only.
 * The caller owns encryption/storage; this service neither generates nor sends.
 */
final class ManagerDraftPilotCollector
{
    public const MAX_CONVERSATIONS = 20;
    public const MAX_WINDOW_HOURS = 72;

    /**
     * $managerIds is the owner's explicit CLI cohort, never an inferred identity.
     * Dates are UTC SQL DATETIME strings. A trusted snapshot callback is provided
     * only for synthetic fixtures; production uses canonical authorization.
     */
    public static function collect(
        PDO $pdo,
        array $managerIds,
        int $limit = self::MAX_CONVERSATIONS,
        ?string $sinceUtc = null,
        ?callable $snapshot = null,
        ?string $capturedAtUtc = null,
        bool $includeUnassigned = false,
        ?array $selection = null
    ): array {
        if ($selection !== null && (!in_array($selection['lane'] ?? null, ['waiting','due','active'], true)
            || !is_int($selection['after_id'] ?? null) || $selection['after_id'] < 0)) return self::failure('invalid_selection');
        if (!array_is_list($managerIds) || !$managerIds || count($managerIds) > 2000) {
            return self::failure('invalid_cohort');
        }
        foreach ($managerIds as $id) {
            if (!is_int($id) || $id <= 0) return self::failure('invalid_cohort');
        }
        $managerIds = array_values(array_unique($managerIds));
        if ($limit <= 0) return self::failure('invalid_limit');
        $limit = min(self::MAX_CONVERSATIONS, $limit);
        $capturedAtUtc = $capturedAtUtc ?? gmdate('Y-m-d H:i:s');
        $capturedAt = self::utcDate($capturedAtUtc);
        if (!$capturedAt) return self::failure('invalid_window');
        $earliest = $capturedAt->modify('-'.self::MAX_WINDOW_HOURS.' hours');
        $sinceUtc = $sinceUtc ?? $earliest->format('Y-m-d H:i:s');
        $since = self::utcDate($sinceUtc);
        if (!$since || $since < $earliest || $since > $capturedAt) {
            return self::failure('invalid_window');
        }

        try {
            $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (!in_array($driver, ['mysql','sqlite'], true)) return self::failure('unsupported_driver');
            if ($pdo->inTransaction()) return self::failure('context_unavailable');
            if ($snapshot === null) {
                // Canonical services must authorize against the selected store.
                if (ConversationDb::connection() !== $pdo) return self::failure('context_unavailable');
                $snapshot = [ManagerAiDraftContextService::class, $includeUnassigned ? 'preparationSnapshot' : 'snapshot'];
            }
            $candidates = self::candidatePage($pdo, $driver, $managerIds, $limit, $sinceUtc, $capturedAtUtc, null, $includeUnassigned, $selection);
            $conversations = [];
            $skipped = [];
            $seen = [];
            foreach ($candidates as $candidate) {
                $id = (int)$candidate['conversation_id'];
                $actor = (int)$candidate['assigned_manager_id'];
                if ($id <= 0 || isset($seen[$id])) continue;
                $seen[$id] = true;
                try {
                    // candidatePage has committed: canonical capture owns a new
                    // read-only transaction and initializes before legacy auth.
                    $captured = $snapshot($id, $actor);
                    if (!is_array($captured) || ($captured['ok'] ?? false) !== true) {
                        self::skip($skipped, self::safeReason(is_array($captured) ? ($captured['error'] ?? null) : null));
                        continue;
                    }
                    $normalized = self::normalize($captured, $id);
                    if ($normalized === null) {
                        self::skip($skipped, 'context_unavailable');
                        continue;
                    }
                    // Capture can race a manager reply, test marking or a new
                    // customer message. Never export an obsolete selected row.
                    $current = self::candidatePage($pdo, $driver, [$actor], 1, $sinceUtc, $capturedAtUtc, $id, $includeUnassigned, $selection);
                    if (count($current) !== 1
                        || (int)($current[0]['assigned_manager_id'] ?? 0) !== $actor
                        || (int)$current[0]['latest_message_id'] !== $normalized['latest_message_id']
                        || (string)$current[0]['project_key'] !== (string)$candidate['project_key']
                        || (string)$current[0]['channel'] !== (string)$candidate['channel']
                        || (int)$current[0]['source_id'] !== (int)$candidate['source_id']) {
                        self::skip($skipped, 'changed_since_selection');
                        continue;
                    }
                    $row = $current[0];
                    $conversations[] = [
                        'conversation_id'=>$id,
                        'assigned_manager_id'=>$actor,
                        'project_key'=>(string)$row['project_key'],
                        'channel'=>(string)$row['channel'],
                        'last_customer_message_id'=>(int)$row['last_customer_message_id'],
                        'last_customer_message_at'=>(string)$row['last_customer_message_at'],
                    ] + $normalized;
                } catch (Throwable $ignored) {
                    self::skip($skipped, 'context_unavailable');
                }
            }
            ksort($skipped);
            return [
                'ok'=>true,
                'schema'=>'manager_draft_pilot_v1',
                'captured_at_utc'=>$capturedAtUtc,
                'since_utc'=>$selection === null ? $sinceUtc : null,
                'authorized_manager_ids'=>$managerIds,
                'limit'=>$limit,
                'candidate_count'=>count($candidates),
                'exported_count'=>count($conversations),
                'skipped_count'=>array_sum($skipped),
                'skipped_by_reason'=>$skipped,
                'conversations'=>$conversations,
                'next_cursor'=>$selection === null || count($candidates) < $limit ? 0 : (int)end($candidates)['conversation_id'],
                'unknowns'=>[
                    'outside_selected_page'=>'not_reviewed',
                    'media_and_link_contents'=>'not_read',
                    'state_after_capture'=>'unknown',
                ],
            ];
        } catch (Throwable $ignored) {
            return self::failure('context_unavailable');
        }
    }

    /** Small prefilter only; project/source/delivery permission belongs to snapshot(). */
    private static function candidatePage(
        PDO $pdo,
        string $driver,
        array $managerIds,
        int $limit,
        string $sinceUtc,
        string $capturedAtUtc,
        ?int $conversationId = null,
        bool $includeUnassigned = false,
        ?array $selection = null
    ): array {
        if ($pdo->inTransaction()) throw new RuntimeException('pilot_transaction_unavailable');
        $started = false;
        try {
            if ($driver === 'mysql') {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $pdo->exec('SET TRANSACTION READ ONLY');
            }
            $pdo->beginTransaction();
            $started = true;
            $metadata = "CASE WHEN JSON_VALID(mc.metadata_json) THEN mc.metadata_json ELSE '{}' END";
            $type = $driver === 'mysql'
                ? "JSON_UNQUOTE(JSON_EXTRACT({$metadata},'$.type'))"
                : "JSON_EXTRACT({$metadata},'$.type')";
            $cohort = implode(',', array_fill(0, count($managerIds), '?'));
            $waiting = "customer_last.id>COALESCE((SELECT MAX(mo.id) FROM messages mo WHERE mo.conversation_id=c.id AND mo.direction='outbound' AND mo.sender_type='manager'),0)";
            $due = "EXISTS (SELECT 1 FROM lead_tasks t WHERE t.conversation_id=c.id AND t.status='open' AND t.due_at_utc IS NOT NULL AND t.due_at_utc<=? AND (t.assigned_manager_id IS NULL OR t.assigned_manager_id=c.manager_id))";
            $sql = "SELECT c.id AS conversation_id,c.manager_id AS assigned_manager_id,c.project_key,c.source_id,c.channel,"
                ."customer_last.id AS last_customer_message_id,customer_last.created_at AS last_customer_message_at,"
                ."(SELECT MAX(all_messages.id) FROM messages all_messages WHERE all_messages.conversation_id=c.id) AS latest_message_id "
                ."FROM conversations c JOIN customers cu ON cu.id=c.customer_id "
                ."LEFT JOIN managers m ON m.id=c.manager_id "
                ."JOIN messages customer_last ON customer_last.id=(SELECT MAX(mc.id) FROM messages mc "
                ."WHERE mc.conversation_id=c.id AND mc.direction='inbound' AND mc.sender_type='customer' "
                ."AND COALESCE({$type},'message') NOT IN (?,?,?)) "
                ."WHERE ((c.status='manager' AND m.is_active=1 AND c.manager_id IN ({$cohort}))"
                .($includeUnassigned ? " OR (c.status='waiting_manager' AND c.manager_id IS NULL)" : '') .") AND c.is_test=0 "
                .'AND NOT '.InternalOwnerDialogueScope::conversationSql('c').' ';
            $args = array_merge(['callback','start','bot_started'], $managerIds);
            if ($selection === null) {
                $sql .= "AND customer_last.created_at>=? AND customer_last.created_at<=? AND {$waiting}";
                array_push($args, $sinceUtc, $capturedAtUtc);
            } else {
                // Preparation has its own bounded rotating lanes; the one-off
                // pilot retains its original recent/unanswered read contract.
                $sql .= "AND c.project_key=? AND customer_last.created_at<=? AND ";
                array_push($args, ProjectConfig::projectId(), $capturedAtUtc);
                if ($selection['lane'] === 'waiting') $sql .= $waiting;
                else {
                    $sql .= "NOT ({$waiting}) AND ".($selection['lane'] === 'active' ? "NOT ({$due})" : $due);
                    $args[] = $capturedAtUtc;
                }
                if ($conversationId === null) { $sql .= ' AND c.id>?'; $args[] = $selection['after_id']; }
            }
            if ($conversationId !== null) { $sql .= ' AND c.id=?'; $args[] = $conversationId; }
            $sql .= $selection === null ? ' ORDER BY COALESCE(c.last_message_at,c.started_at) DESC,c.id DESC' : ' ORDER BY c.id';
            $sql .= ' LIMIT '.min(self::MAX_CONVERSATIONS, $limit);
            $q = $pdo->prepare($sql);
            $q->execute($args);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            $q->closeCursor();
            $pdo->commit();
            $started = false;
            return $rows;
        } finally {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
        }
    }

    /** Keep the canonical redacted projection; no raw identities, metadata or URLs. */
    private static function normalize(array $captured, int $id): ?array
    {
        $context = $captured['context'] ?? null;
        $knowledge = $captured['company_knowledge'] ?? null;
        $latest = $captured['latest_message_id'] ?? null;
        $revision = $captured['revision'] ?? null;
        if (!is_array($context) || ($context['conversation_id'] ?? null) !== $id
            || !is_int($latest) || $latest <= 0 || ($context['as_of_message_id'] ?? null) !== $latest
            || !is_string($revision) || preg_match('/\A[a-f0-9]{64}\z/D', $revision) !== 1
            || !$knowledge instanceof ManagerCompanyKnowledge
            || !is_bool($captured['history_incomplete'] ?? null)
            || ($context['history_incomplete'] ?? null) !== $captured['history_incomplete']) return null;
        foreach (['media_unread','selections_link_unread'] as $flag) {
            if (!is_bool($context[$flag] ?? null)) return null;
        }
        $input = $context['messages'] ?? null;
        if (!is_array($input) || !array_is_list($input) || count($input) > ManagerAiDraftContextService::MAX_MESSAGES) return null;
        $messages = [];
        $characters = 0;
        $lastId = 0;
        foreach ($input as $message) {
            if (!is_array($message) || !is_int($message['id'] ?? null) || $message['id'] <= $lastId || $message['id'] > $latest
                || !in_array($message['sender_type'] ?? null, ['customer','manager','ai','system'], true)
                || !in_array($message['event_type'] ?? null, ['message','callback','start','bot_started','unknown'], true)
                || !is_string($message['text'] ?? null) || preg_match('//u', $message['text']) !== 1
                || !is_string($message['created_at'] ?? null) || strlen($message['created_at']) > 32) return null;
            foreach (['media_unread','links_redacted','selections_link_unread'] as $flag) {
                if (!is_bool($message[$flag] ?? null)) return null;
            }
            if (isset($message['text_truncated']) && !is_bool($message['text_truncated'])) return null;
            $characters += function_exists('mb_strlen') ? mb_strlen($message['text'], 'UTF-8') : preg_match_all('/./us', $message['text']);
            if ($characters > ManagerAiDraftContextService::MAX_TEXT_CHARACTERS) return null;
            $messages[] = array_intersect_key($message, array_flip([
                'id','sender_type','text','event_type','created_at','media_unread',
                'links_redacted','selections_link_unread','text_truncated',
            ]));
            $lastId = $message['id'];
        }
        return [
            'revision'=>$revision,
            'latest_message_id'=>$latest,
            'history_incomplete'=>$captured['history_incomplete'],
            'context'=>[
                'conversation_id'=>$id,
                'as_of_message_id'=>$latest,
                'messages'=>$messages,
                'history_incomplete'=>$captured['history_incomplete'],
                'media_unread'=>$context['media_unread'],
                'selections_link_unread'=>$context['selections_link_unread'],
            ],
            'company_knowledge'=>['revision'=>$knowledge->revision(), 'facts'=>$knowledge->facts()],
        ];
    }

    private static function utcDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $date : null;
    }

    private static function safeReason($reason): string
    {
        return is_string($reason) && in_array($reason, [
            'unauthorized','not_found','reply_not_allowed','suspended','context_unavailable',
        ], true) ? $reason : 'context_unavailable';
    }

    private static function skip(array &$counts, string $reason): void
    {
        $counts[$reason] = ($counts[$reason] ?? 0) + 1;
    }

    private static function failure(string $error): array
    {
        return ['ok'=>false, 'error'=>$error];
    }
}
