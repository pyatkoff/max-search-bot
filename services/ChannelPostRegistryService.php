<?php
declare(strict_types=1);

require_once __DIR__ . '/ConversationDb.php';

/**
 * Private, project-scoped channel publication registry. Runtime never creates
 * schema and this owner never changes routing, dialogue state or manager shifts.
 * Admin/manager callers must authorize their action before invoking this class.
 */
final class ChannelPostRegistryService
{
    private PDO $pdo;
    private const LEASE_SECONDS = 300;
    private const MAX_SNAPSHOT_BYTES = 131072;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConversationDb::connection();
    }

    /** Explicit admin configuration; new channels are disabled unless opted in. */
    public function configureChannel(array $input): array
    {
        $project = self::identity($input['project_key'] ?? '', 64);
        $platform = self::platform((string)($input['platform'] ?? ''));
        $bot = self::identity($input['bot_key'] ?? '', 191);
        $username = self::identity($input['canonical_bot_username'] ?? $bot, 191);
        $channelId = self::canonicalChannelId($platform, (string)($input['channel_id'] ?? ''));
        $sourceKey = self::identity($input['source_key'] ?? '', 96);
        $botUrl = self::botUrl($platform, (string)($input['bot_url'] ?? ''), $username);
        $displayName = self::text((string)($input['display_name'] ?? ''), 191);
        $button = self::text((string)($input['button_text'] ?? 'Рассчитать на мои даты'), 80);
        if ($button === '') throw new InvalidArgumentException('button_text_required');
        $enabled = !empty($input['is_enabled']) ? 1 : 0;
        $expectedVersion = isset($input['expected_version']) ? filter_var($input['expected_version'], FILTER_VALIDATE_INT) : 0;
        if ($expectedVersion === false || $expectedVersion < 0) throw new InvalidArgumentException('invalid_channel_configuration_version');

        return $this->transaction(function () use ($project, $platform, $bot, $username, $channelId, $sourceKey, $botUrl, $displayName, $button, $enabled, $expectedVersion): array {
            $source = $this->fetch('SELECT s.id,s.project_id,s.source_key,s.channel,s.handling_mode,p.project_key FROM conversation_sources s JOIN projects p ON p.id=s.project_id WHERE p.project_key=? AND s.source_key=? AND s.is_active=1 AND p.is_active=1' . $this->lock(), [$project, $sourceKey]);
            if (!$source || $source['project_key'] !== $project || $source['source_key'] !== $sourceKey || $source['channel'] !== $platform) {
                throw new InvalidArgumentException('active_platform_source_required');
            }
            if (!in_array((string)$source['handling_mode'], ['manager', 'ask'], true)) throw new InvalidArgumentException('manager_source_required');
            $existing = $this->fetch('SELECT * FROM post_channels WHERE platform=? AND channel_id=?' . $this->lock(), [$platform, $channelId]);
            $now = self::now();
            if ($existing) {
                if ((int)$existing['project_id'] !== (int)$source['project_id'] || $existing['bot_key'] !== $bot || $existing['canonical_bot_username'] !== $username) {
                    throw new InvalidArgumentException('channel_identity_conflict');
                }
                if ((int)$existing['config_version'] !== $expectedVersion) throw new InvalidArgumentException('channel_configuration_conflict');
                if ((int)$existing['source_id'] !== (int)$source['id']) {
                    $used = $this->fetch('SELECT id FROM post_publications WHERE post_channel_id=? LIMIT 1', [(int)$existing['id']]);
                    if ($used) throw new InvalidArgumentException('published_channel_source_immutable');
                }
                $this->execute('UPDATE post_channels SET source_id=?,display_name=?,bot_url=?,button_text=?,is_enabled=?,config_version=config_version+1,updated_at=? WHERE id=?', [(int)$source['id'], $displayName, $botUrl, $button, $enabled, $now, (int)$existing['id']]);
            } else {
                if ($expectedVersion !== 0) throw new InvalidArgumentException('channel_configuration_conflict');
                $this->execute('INSERT INTO post_channels (project_id,source_id,platform,bot_key,canonical_bot_username,channel_id,display_name,bot_url,button_text,is_enabled,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [(int)$source['project_id'], (int)$source['id'], $platform, $bot, $username, $channelId, $displayName, $botUrl, $button, $enabled, $now, $now]);
            }
            return $this->channel($project, $platform, $bot, $channelId, false) ?? throw new RuntimeException('channel_configuration_unavailable');
        });
    }

    /** Administration reads are private, bounded and contain no post body or media tokens. */
    public function listChannels(string $projectKey): array
    {
        $q = $this->pdo->prepare('SELECT pc.id,pc.source_id,p.project_key,pc.platform,pc.bot_key,pc.canonical_bot_username,pc.channel_id,pc.display_name,pc.bot_url,pc.button_text,pc.is_enabled,pc.config_version,pc.updated_at,s.source_key,s.display_name AS source_name,s.handling_mode AS source_handling_mode,s.is_active AS source_active,p.is_active AS project_active FROM post_channels pc JOIN projects p ON p.id=pc.project_id JOIN conversation_sources s ON s.id=pc.source_id AND s.project_id=pc.project_id WHERE p.project_key=? ORDER BY pc.id DESC LIMIT 100');
        $q->execute([$projectKey]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $byChannel = [];
        if ($rows) {
            $ids = array_map('intval', array_column($rows, 'id'));
            $counts = $this->pdo->prepare('SELECT pp.post_channel_id,v.state,COUNT(*) AS total FROM post_publications pp JOIN post_versions v ON v.id=pp.current_version_id WHERE pp.post_channel_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY pp.post_channel_id,v.state');
            $counts->execute($ids);
            foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $count) $byChannel[(int)$count['post_channel_id']][(string)$count['state']] = (int)$count['total'];
        }
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['source_id'] = (int)$row['source_id'];
            $row['config_version'] = (int)$row['config_version'];
            $row['is_enabled'] = (int)$row['is_enabled'] === 1;
            $row['publication_states'] = $byChannel[$row['id']] ?? [];
            $row['state_counts'] = $row['publication_states'];
            $row['publication_count'] = array_sum($row['publication_states']);
        }
        unset($row);
        return $rows;
    }

    public static function canonicalChannelId(string $platform, string $channelId): string
    {
        $platform = self::platform($platform);
        $channelId = trim($channelId);
        if (!preg_match('/\A-?[1-9][0-9]{0,19}\z/D', $channelId)) throw new InvalidArgumentException('invalid_channel_id');
        // MAX channel links use negative IDs; admin users often copy their magnitude.
        if ($platform === 'max') return '-' . ltrim($channelId, '-');
        if ($channelId[0] !== '-') throw new InvalidArgumentException('telegram_channel_id_required');
        return $channelId;
    }

    public function channel(string $projectKey, string $platform, string $botKey, string $channelId, bool $requireEnabled = true, bool $forUpdate = false): ?array
    {
        $platform = self::platform($platform);
        $channelId = self::canonicalChannelId($platform, $channelId);
        $row = $this->fetch('SELECT pc.*,p.project_key,s.source_key,s.channel AS source_platform,s.handling_mode AS source_handling_mode,s.is_active AS source_active,p.is_active AS project_active FROM post_channels pc JOIN projects p ON p.id=pc.project_id JOIN conversation_sources s ON s.id=pc.source_id AND s.project_id=pc.project_id WHERE p.project_key=? AND pc.platform=? AND pc.bot_key=? AND pc.channel_id=?' . ($forUpdate ? $this->lock() : ''), [$projectKey, $platform, $botKey, $channelId]);
        if (!$row || $row['project_key'] !== $projectKey || $row['platform'] !== $platform || $row['bot_key'] !== $botKey || $row['source_platform'] !== $platform || !in_array((string)$row['source_handling_mode'], ['manager', 'ask'], true) || (int)$row['source_active'] !== 1 || (int)$row['project_active'] !== 1 || ($requireEnabled && (int)$row['is_enabled'] !== 1)) return null;
        return $row;
    }

    /** Upsert identity, append changed content, preserve immutable previous snapshots. */
    public function registerPublication(string $projectKey, string $platform, string $botKey, string $channelId, string $messageId, array $snapshot): ?array
    {
        $messageId = self::identity($messageId, 191);
        $suppliedHash = (string)($snapshot['content_hash'] ?? '');
        if ($suppliedHash !== '' && !preg_match('/\A[a-f0-9]{64}\z/D', $suppliedHash)) throw new InvalidArgumentException('invalid_post_content_hash');
        $snapshot = self::snapshot($snapshot);
        $fingerprint = [];
        foreach (['text', 'title', 'format', 'attachments', 'post_url', 'media_group_id'] as $key) $fingerprint[$key] = $snapshot[$key] ?? null;
        // Transport adapters hash stable photo IDs and exclude our own button,
        // expiring CDN URLs, access tokens and platform edit timestamps.
        $hash = $suppliedHash !== '' ? $suppliedHash : hash('sha256', self::json(self::canonicalArray($fingerprint)));
        return $this->transaction(function () use ($projectKey, $platform, $botKey, $channelId, $messageId, $snapshot, $hash): ?array {
            // Locking reads see current MySQL state even under REPEATABLE READ.
            // The publication and its configured source commit in the same scope.
            $channel = $this->channel($projectKey, $platform, $botKey, $channelId, true, true);
            if (!$channel) return null;
            $publication = $this->fetch('SELECT * FROM post_publications WHERE post_channel_id=? AND external_message_id=?' . $this->lock(), [(int)$channel['id'], $messageId]);
            if (!$publication) {
                $token = 'p_' . rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
                $this->execute('INSERT INTO post_publications (post_channel_id,source_id,external_message_id,start_payload,created_at) VALUES (?,?,?,?,?)', [(int)$channel['id'], (int)$channel['source_id'], $messageId, $token, self::now()]);
                $publication = ['id' => (int)$this->pdo->lastInsertId(), 'current_version_id' => null];
            }
            $sourceEvent = (string)($snapshot['metadata']['source_event_id'] ?? '');
            $sourceEventKey = $sourceEvent !== '' ? hash('sha256', $sourceEvent) : null;
            if ($sourceEventKey !== null) {
                $observed = $this->fetch('SELECT version_id FROM post_observations WHERE publication_id=? AND source_event_key=?', [(int)$publication['id'], $sourceEventKey]);
                if ($observed) {
                    $result = $this->publication((int)$publication['id']);
                    if ($result) $result['duplicate_event'] = true;
                    return $result;
                }
            }
            $previous = $publication['current_version_id'] ? $this->fetch('SELECT * FROM post_versions WHERE id=? AND publication_id=?', [(int)$publication['current_version_id'], (int)$publication['id']]) : null;
            if ($previous) {
                $priorSnapshot = json_decode((string)$previous['snapshot_json'], true) ?: [];
                $incomingTime = self::revisionTime($snapshot);
                $priorTime = self::revisionTime($priorSnapshot);
                $incomingSequence = $platform === 'telegram' ? self::telegramSequence($snapshot) : null;
                $priorSequence = $platform === 'telegram' ? self::telegramSequence($priorSnapshot) : null;
                $clockTime = (int)($publication['observed_revision_ms'] ?? 0);
                $clockSequence = $platform === 'telegram' ? self::validSequence($publication['telegram_sequence'] ?? null) : null;
                if ($clockTime > $priorTime) {
                    $priorTime = $clockTime;
                    if ($platform === 'telegram') {
                        $priorSequence = $clockSequence;
                    }
                } elseif ($platform === 'telegram' && $clockTime === $priorTime && $clockSequence !== null && ($priorSequence === null || self::compareSequence($clockSequence, $priorSequence) > 0)) {
                    $priorSequence = $clockSequence;
                }
                // Telegram update_id resolves edits sharing one edit_date second.
                // Date remains primary because update IDs may reset after idle time.
                $olderSameSecond = $platform === 'telegram' && $incomingTime > 0 && $incomingTime === $priorTime && $incomingSequence !== null && $priorSequence !== null && self::compareSequence($incomingSequence, $priorSequence) < 0;
                if (($incomingTime > 0 && $priorTime > $incomingTime) || $olderSameSecond) {
                    if ($sourceEventKey !== null) $this->execute('INSERT INTO post_observations (publication_id,source_event_key,version_id,created_at) VALUES (?,?,?,?)', [(int)$publication['id'], $sourceEventKey, (int)$previous['id'], self::now()]);
                    $result = $this->publication((int)$publication['id']);
                    if ($result) $result['stale_observation'] = true;
                    return $result;
                }
            }
            if (!$previous || !hash_equals((string)$previous['content_hash'], $hash)) {
                $version = $previous ? (int)$previous['version_number'] + 1 : 1;
                $now = self::now();
                $this->execute('INSERT INTO post_versions (publication_id,version_number,content_hash,snapshot_json,created_at,updated_at) VALUES (?,?,?,?,?,?)', [(int)$publication['id'], $version, $hash, self::json($snapshot), $now, $now]);
                $versionId = (int)$this->pdo->lastInsertId();
                $this->execute('UPDATE post_publications SET current_version_id=? WHERE id=?', [$versionId, (int)$publication['id']]);
                if ($previous) {
                    $this->execute("UPDATE post_versions SET state='requires_attention',error_reason='superseded_before_write',updated_at=? WHERE id=? AND state IN ('pending','claimed') AND write_attempts=0", [$now, (int)$previous['id']]);
                }
            }
            $incomingTime = self::revisionTime($snapshot);
            $incomingSequence = $platform === 'telegram' ? self::telegramSequence($snapshot) : null;
            $clockTime = (int)($publication['observed_revision_ms'] ?? 0);
            $clockSequence = $platform === 'telegram' ? self::validSequence($publication['telegram_sequence'] ?? null) : null;
            if ($incomingTime > 0 && ($incomingTime > $clockTime || ($incomingTime === $clockTime && $incomingSequence !== null && ($clockSequence === null || self::compareSequence($incomingSequence, $clockSequence) > 0)))) {
                $this->execute('UPDATE post_publications SET observed_revision_ms=?,telegram_sequence=? WHERE id=?', [$incomingTime, $incomingSequence, (int)$publication['id']]);
            }
            $result = $this->publication((int)$publication['id']);
            if ($result && $sourceEventKey !== null) $this->execute('INSERT INTO post_observations (publication_id,source_event_key,version_id,created_at) VALUES (?,?,?,?)', [(int)$publication['id'], $sourceEventKey, (int)$result['version_id'], self::now()]);
            return $result;
        });
    }

    /** Opaque token resolution is exact, never a source-key suffix parser. */
    public function resolveToken(string $projectKey, string $platform, string $botKey, string $payload): ?array
    {
        if (!preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/D', $payload)) return null;
        $row = $this->fetch('SELECT id,start_payload FROM post_publications WHERE start_payload=? LIMIT 1', [$payload]);
        if (!$row || !hash_equals((string)$row['start_payload'], $payload)) return null;
        $result = $this->publication((int)$row['id']);
        if (!$result || $result['project_key'] !== $projectKey || $result['platform'] !== $platform || $result['bot_key'] !== $botKey) return null;
        return $result;
    }

    /** Fresh active configuration check used immediately before an external edit. */
    public function publication(int $publicationId): ?array
    {
        $row = $this->publicationRow($publicationId);
        if (!$row) return null;
        $channel = $this->channel((string)$row['project_key'], (string)$row['platform'], (string)$row['bot_key'], (string)$row['channel_id']);
        if (!$channel || (int)$row['source_id'] !== (int)$channel['source_id']) return null;
        return $this->operation($row, $channel);
    }

    /** The lease protects work, while write_attempts protects against ambiguous retry. */
    public function pendingPublications(string $projectKey, string $platform, string $botKey, int $limit = 5): array
    {
        $limit = max(1, min(25, $limit));
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::LEASE_SECONDS);
        $this->execute("UPDATE post_versions SET state=CASE WHEN write_attempts>0 THEN 'unknown' ELSE 'pending' END,claim_token=NULL,claimed_at=NULL,error_reason='claim_expired',updated_at=? WHERE publication_id IN (SELECT pp.id FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id WHERE p.project_key=? AND pc.platform=? AND pc.bot_key=?) AND state IN ('claimed','write_started') AND claimed_at<?", [self::now(), $projectKey, $platform, $botKey, $cutoff]);
        $query = $this->pdo->prepare("SELECT pp.id FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id JOIN conversation_sources s ON s.id=pp.source_id AND s.id=pc.source_id AND s.project_id=pc.project_id JOIN post_versions v ON v.id=pp.current_version_id WHERE p.project_key=? AND pc.platform=? AND pc.bot_key=? AND pc.is_enabled=1 AND p.is_active=1 AND s.is_active=1 AND s.channel=pc.platform AND s.handling_mode IN ('manager','ask') AND v.state IN ('pending','unknown') ORDER BY CASE WHEN v.state='pending' THEN 0 ELSE 1 END ASC,v.id ASC LIMIT " . $limit);
        $query->execute([$projectKey, $platform, $botKey]);
        $rows = [];
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $publication = $this->publication((int)$id);
            if ($publication) $rows[] = $publication;
        }
        return $rows;
    }

    public function claimPublication(int $publicationId, int $versionId, bool $reconcileOnly = false): ?array
    {
        return $this->transaction(function () use ($publicationId, $versionId, $reconcileOnly): ?array {
            $publication = $this->publication($publicationId);
            if (!$publication || $publication['version_id'] !== $versionId) return null;
            $expected = $reconcileOnly ? 'unknown' : 'pending';
            $claim = bin2hex(random_bytes(16));
            $now = self::now();
            $count = $this->execute("UPDATE post_versions SET state='claimed',claim_token=?,claimed_at=?,attempt_count=attempt_count+1,updated_at=? WHERE id=? AND publication_id=? AND state=?", [$claim, $now, $now, $versionId, $publicationId, $expected]);
            if ($count !== 1) return null;
            $result = $this->publication($publicationId);
            if ($result) $result['reconcile_only'] = $reconcileOnly;
            return $result;
        });
    }

    public function markExternalWriteStarted(int $publicationId, int $versionId, string $claimToken): bool
    {
        return $this->transaction(function () use ($publicationId, $versionId, $claimToken): bool {
            $publication = $this->publication($publicationId);
            if (!$publication || $publication['version_id'] !== $versionId) return false;
            return $this->execute("UPDATE post_versions SET state='write_started',write_attempts=write_attempts+1,updated_at=? WHERE id=? AND publication_id=? AND claim_token=? AND state='claimed' AND write_attempts=0", [self::now(), $versionId, $publicationId, $claimToken]) === 1;
        });
    }

    public function finishPublication(int $publicationId, int $versionId, string $claimToken, string $state, string $reason = '', array $receipt = []): bool
    {
        if (!in_array($state, ['sent', 'unknown', 'requires_attention', 'pending'], true)) throw new InvalidArgumentException('invalid_operation_state');
        $reason = preg_match('/\A[a-zA-Z0-9_-]{0,96}\z/D', $reason) ? $reason : 'operation_failed';
        $receipt = self::receipt($receipt);
        $extra = $state === 'pending' ? " AND write_attempts=0 AND state='claimed'" : " AND state IN ('claimed','write_started')";
        return $this->execute('UPDATE post_versions SET state=?,claim_token=NULL,claimed_at=NULL,error_reason=?,receipt_json=?,updated_at=? WHERE id=? AND publication_id=? AND claim_token=?' . $extra, [$state, $reason, self::json($receipt), self::now(), $versionId, $publicationId, $claimToken]) === 1;
    }

    /** Caller must first verify the bot-owned URL in an authenticated channel edit. */
    public function confirmObservedButton(int $publicationId, int $versionId, array $receipt = []): bool
    {
        $publication = $this->publication($publicationId);
        if (!$publication || $publication['version_id'] !== $versionId) return false;
        return $this->execute("UPDATE post_versions SET state='sent',claim_token=NULL,claimed_at=NULL,error_reason='',receipt_json=?,updated_at=? WHERE id=? AND publication_id=? AND state IN ('pending','claimed','write_started','unknown')", [self::json(self::receipt($receipt)), self::now(), $versionId, $publicationId]) === 1;
    }

    /** Entry and event pointer commit together; existing dialogue ownership is untouched. */
    public function recordEntry(int $conversationId, array $resolved, string $eventKey = ''): ?array
    {
        if ($conversationId <= 0) return null;
        $publicationId = (int)($resolved['publication_id'] ?? 0);
        $versionId = (int)($resolved['version_id'] ?? 0);
        if ($publicationId <= 0 || $versionId <= 0) return null;
        return $this->transaction(function () use ($conversationId, $resolved, $eventKey, $publicationId, $versionId): ?array {
            $publication = $this->publication($publicationId);
            if (!$publication || !hash_equals((string)$publication['start_payload'], (string)($resolved['start_payload'] ?? '')) || $publication['project_key'] !== ($resolved['project_key'] ?? '') || $publication['platform'] !== ($resolved['platform'] ?? '') || $publication['bot_key'] !== ($resolved['bot_key'] ?? '')) return null;
            $version = $this->fetch('SELECT id FROM post_versions WHERE id=? AND publication_id=?', [$versionId, $publicationId]);
            $conversation = $this->fetch('SELECT id,project_key,channel FROM conversations WHERE id=?' . $this->lock(), [$conversationId]);
            if (!$version || !$conversation || $conversation['project_key'] !== $publication['project_key'] || $conversation['channel'] !== $publication['platform']) return null;
            // Missing provider IDs cannot prove a replay. A fresh nonce preserves
            // A → B → A entry order instead of reusing A's old history position.
            $entryKey = hash('sha256', $eventKey !== '' ? $publication['platform'] . '|' . $publication['bot_key'] . '|' . $eventKey : 'entry|' . $publicationId . '|' . $versionId . '|' . bin2hex(random_bytes(18)));
            $existing = $this->fetch('SELECT id,version_id FROM post_entries WHERE conversation_id=? AND entry_key=?', [$conversationId, $entryKey]);
            if ($existing) {
                if ((int)$existing['version_id'] !== $versionId) return null;
                return ['entry_id' => (int)$existing['id'], 'version_id' => $versionId, 'duplicate' => true];
            }
            $now = self::now();
            $this->execute('INSERT INTO post_entries (conversation_id,publication_id,version_id,entry_key,created_at) VALUES (?,?,?,?,?)', [$conversationId, $publicationId, $versionId, $entryKey, $now]);
            $entryId = (int)$this->pdo->lastInsertId();
            $this->execute('INSERT INTO conversation_events (conversation_id,event_type,actor_type,payload_json,created_at) VALUES (?,?,?,?,?)', [$conversationId, 'channel_post_entry', 'customer', self::json(['entry_id' => $entryId, 'publication_id' => $publicationId, 'version_id' => $versionId, 'source_key' => $publication['source_key']]), $now]);
            return ['entry_id' => $entryId, 'version_id' => $versionId, 'duplicate' => false];
        });
    }

    /** Do not leave an earlier post looking current after an unknown p_ entry. */
    public function recordUnavailableEntry(int $conversationId, string $projectKey, string $platform, string $eventKey = ''): ?array
    {
        self::platform($platform);
        if ($conversationId <= 0) return null;
        return $this->transaction(function () use ($conversationId, $projectKey, $platform, $eventKey): ?array {
            $conversation = $this->fetch('SELECT id,project_key,channel FROM conversations WHERE id=?' . $this->lock(), [$conversationId]);
            if (!$conversation || $conversation['project_key'] !== $projectKey || $conversation['channel'] !== $platform) return null;
            $entryKey = hash('sha256', 'unavailable|' . $platform . '|' . ($eventKey !== '' ? $eventKey : bin2hex(random_bytes(18))));
            $existing = $this->fetch('SELECT id FROM post_entries WHERE conversation_id=? AND entry_key=?', [$conversationId, $entryKey]);
            if ($existing) return ['entry_id' => (int)$existing['id'], 'version_id' => null, 'duplicate' => true];
            $now = self::now();
            $this->execute('INSERT INTO post_entries (conversation_id,publication_id,version_id,availability_reason,entry_key,created_at) VALUES (?,NULL,NULL,?,?,?)', [$conversationId, 'unavailable', $entryKey, $now]);
            $entryId = (int)$this->pdo->lastInsertId();
            $this->execute('INSERT INTO conversation_events (conversation_id,event_type,actor_type,payload_json,created_at) VALUES (?,?,?,?,?)', [$conversationId, 'channel_post_entry', 'customer', self::json(['entry_id' => $entryId, 'unavailable' => true]), $now]);
            return ['entry_id' => $entryId, 'version_id' => null, 'duplicate' => false];
        });
    }

    /** Manager ACL belongs to the endpoint. Here project isolation is checked again. */
    public function managerContext(int $conversationId, string $projectKey, int $limit = 8): array
    {
        $empty = ['current' => null, 'history' => []];
        $conversation = $this->fetch('SELECT id,project_key,channel FROM conversations WHERE id=? AND project_key=?', [$conversationId, $projectKey]);
        if (!$conversation || $conversation['project_key'] !== $projectKey) return $empty;
        $limit = max(1, min(8, $limit));
        $q = $this->pdo->prepare('SELECT e.id AS entry_id,e.created_at AS entered_at,e.availability_reason,pp.id AS publication_id,pp.external_message_id,pc.platform,pc.channel_id,pc.display_name AS channel_name,s.source_key,s.display_name AS source_name,v.id AS version_id,v.version_number,v.snapshot_json FROM post_entries e LEFT JOIN post_publications pp ON pp.id=e.publication_id LEFT JOIN post_channels pc ON pc.id=pp.post_channel_id LEFT JOIN projects p ON p.id=pc.project_id LEFT JOIN conversation_sources s ON s.id=pp.source_id AND s.project_id=pc.project_id LEFT JOIN post_versions v ON v.id=e.version_id AND v.publication_id=pp.id WHERE e.conversation_id=? AND ((e.publication_id IS NULL AND e.version_id IS NULL) OR (p.project_key=? AND pc.platform=? AND v.id IS NOT NULL)) ORDER BY e.id DESC LIMIT ' . $limit);
        $q->execute([$conversationId, $projectKey, $conversation['channel']]);
        $rows = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $snapshot = json_decode((string)$row['snapshot_json'], true);
            $snapshot = is_array($snapshot) ? $snapshot : [];
            unset($row['snapshot_json']);
            $row['entry_id'] = (int)$row['entry_id'];
            foreach (['publication_id', 'version_id'] as $key) $row[$key] = $row[$key] === null ? null : (int)$row[$key];
            $row['version_number'] = (int)$row['version_number'];
            $row['unavailable'] = (string)$row['availability_reason'] !== '';
            $row['platform'] = (string)($row['platform'] ?? $conversation['channel']);
            $row['message_id'] = (string)($row['external_message_id'] ?? '');
            unset($row['external_message_id']);
            foreach (['text', 'title', 'format', 'published_at'] as $key) $row[$key] = (string)($snapshot[$key] ?? '');
            $row['post_url'] = self::postUrl((string)$row['platform'], (string)($snapshot['post_url'] ?? ''));
            $row['media_count'] = count((array)($snapshot['attachments'] ?? []));
            $firstAttachment = $snapshot['attachments'][0] ?? [];
            if (is_array($firstAttachment) && ($firstAttachment['type'] ?? '') === 'image' && ($firstAttachment['provider'] ?? '') === $row['platform']) {
                $row['media_url'] = '/manager/post-context-media.php?conversation_id=' . $conversationId . '&entry_id=' . $row['entry_id'] . '&attachment=0';
            }
            $rows[] = $row;
        }
        return ['current' => $rows[0] ?? null, 'history' => array_slice($rows, 1)];
    }

    /** Private media owner only; an entry ID never substitutes for endpoint ACL. */
    public function versionForEntry(int $conversationId, string $projectKey, int $entryId): ?array
    {
        if ($conversationId <= 0 || $entryId <= 0) return null;
        $row = $this->fetch('SELECT c.project_key,c.channel AS conversation_platform,pc.platform,pc.bot_key,pc.canonical_bot_username,pc.channel_id,pp.external_message_id,pp.id AS publication_id,v.id AS version_id,v.snapshot_json FROM post_entries e JOIN conversations c ON c.id=e.conversation_id JOIN post_publications pp ON pp.id=e.publication_id JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id JOIN post_versions v ON v.id=e.version_id AND v.publication_id=pp.id WHERE e.id=? AND e.conversation_id=? AND c.project_key=? AND p.project_key=?', [$entryId, $conversationId, $projectKey, $projectKey]);
        if (!$row || $row['project_key'] !== $projectKey || $row['conversation_platform'] !== $row['platform']) return null;
        $row['snapshot'] = json_decode((string)$row['snapshot_json'], true) ?: [];
        $row['version_id'] = (int)$row['version_id'];
        $row['publication_id'] = (int)$row['publication_id'];
        unset($row['snapshot_json'], $row['conversation_platform']);
        return $row;
    }

    /**
     * Private archival owner only. The caller must match project/platform/bot to
     * its authenticated runtime; publication/version IDs grant no public access.
     * This keeps an observed photo available after a newer SMM version arrives.
     */
    public function versionForPublication(int $publicationId, int $versionId): ?array
    {
        if ($publicationId <= 0 || $versionId <= 0) return null;
        $row = $this->fetch('SELECT p.project_key,pc.platform,pc.bot_key,pc.canonical_bot_username,pc.channel_id,pp.external_message_id,pp.id AS publication_id,v.id AS version_id,v.version_number,v.snapshot_json FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id JOIN conversation_sources s ON s.id=pp.source_id AND s.project_id=pc.project_id AND s.channel=pc.platform JOIN post_versions v ON v.publication_id=pp.id WHERE pp.id=? AND v.id=?', [$publicationId, $versionId]);
        if (!$row) return null;
        $row['snapshot'] = json_decode((string)$row['snapshot_json'], true) ?: [];
        foreach (['publication_id', 'version_id', 'version_number'] as $key) $row[$key] = (int)$row[$key];
        unset($row['snapshot_json']);
        return $row;
    }

    private function publicationRow(int $publicationId): ?array
    {
        if ($publicationId <= 0) return null;
        return $this->fetch('SELECT pp.id AS publication_id,pp.source_id,pp.external_message_id,pp.start_payload,pc.project_id,pc.platform,pc.bot_key,pc.channel_id,p.project_key,s.source_key,v.id AS version_id,v.version_number,v.content_hash,v.state,v.claim_token,v.claimed_at,v.attempt_count,v.write_attempts,v.error_reason,v.receipt_json,v.snapshot_json FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id JOIN conversation_sources s ON s.id=pp.source_id AND s.project_id=pc.project_id JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.id=?', [$publicationId]);
    }

    private function operation(array $row, array $channel): array
    {
        foreach (['publication_id', 'source_id', 'project_id', 'version_id', 'version_number', 'attempt_count', 'write_attempts'] as $key) $row[$key] = (int)$row[$key];
        $row['id'] = $row['publication_id'];
        $row['message_id'] = $row['external_message_id'];
        $row['snapshot'] = json_decode((string)$row['snapshot_json'], true) ?: [];
        $row['receipt'] = json_decode((string)($row['receipt_json'] ?? ''), true) ?: [];
        $row['write_attempted'] = $row['write_attempts'] > 0;
        $row['reconcile_only'] = $row['state'] === 'unknown' || $row['write_attempted'];
        $row['channel'] = $channel;
        unset($row['snapshot_json'], $row['receipt_json']);
        return $row;
    }

    private static function snapshot(array $input): array
    {
        $snapshot = [];
        foreach (['text', 'title', 'format', 'post_url', 'published_at', 'edited_at', 'media_group_id'] as $key) {
            $value = (string)($input[$key] ?? '');
            if ($key === 'text') {
                if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 12000) throw new InvalidArgumentException('post_text_too_large_or_invalid');
                $snapshot[$key] = $value;
            } else {
                $snapshot[$key] = self::text($value, $key === 'post_url' ? 2048 : 191);
            }
        }
        foreach (['attachments', 'metadata', 'raw_body', 'markup', 'keyboard'] as $key) {
            $value = $input[$key] ?? [];
            if (!is_array($value)) throw new InvalidArgumentException('invalid_snapshot_' . $key);
            if ($key === 'attachments' && count($value) > 20) throw new InvalidArgumentException('too_many_post_attachments');
            self::validateTree($value);
            $snapshot[$key] = $value;
        }
        if (strlen(self::json($snapshot)) > self::MAX_SNAPSHOT_BYTES) throw new InvalidArgumentException('post_snapshot_too_large');
        return $snapshot;
    }

    private static function validateTree($value, int $depth = 0): void
    {
        if ($depth > 10) throw new InvalidArgumentException('snapshot_depth_exceeded');
        if (is_array($value)) {
            if (count($value) > 100) throw new InvalidArgumentException('snapshot_items_exceeded');
            foreach ($value as $key => $item) {
                if (is_string($key) && strlen($key) > 191) throw new InvalidArgumentException('snapshot_key_too_long');
                self::validateTree($item, $depth + 1);
            }
        } elseif (is_string($value)) {
            if (strlen($value) > 65536 || !mb_check_encoding($value, 'UTF-8')) throw new InvalidArgumentException('invalid_snapshot_text');
        } elseif (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('invalid_snapshot_value');
        }
    }

    private static function canonicalArray(array $input): array
    {
        if (!array_is_list($input)) ksort($input, SORT_STRING);
        foreach ($input as &$value) if (is_array($value)) $value = self::canonicalArray($value);
        unset($value);
        return $input;
    }

    private static function revisionTime(array $snapshot): int
    {
        $eventRevision = $snapshot['metadata']['source_revision_ms'] ?? null;
        if ((is_int($eventRevision) || is_string($eventRevision)) && preg_match('/\A[0-9]{1,16}\z/D', (string)$eventRevision)) return (int)$eventRevision;
        $value = (string)(($snapshot['edited_at'] ?? '') ?: ($snapshot['published_at'] ?? ''));
        if ($value === '') return 0;
        if (ctype_digit($value)) return strlen($value) > 11 ? (int)$value : (int)$value * 1000;
        return (strtotime($value) ?: 0) * 1000;
    }

    private static function telegramSequence(array $snapshot): ?string
    {
        if (($snapshot['metadata']['source_sequence_scope'] ?? '') !== 'telegram_update_id') return null;
        return self::validSequence($snapshot['metadata']['source_sequence'] ?? null);
    }

    private static function validSequence($value): ?string
    {
        if (!is_string($value) && !is_int($value)) return null;
        $value = (string)$value;
        return preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D', $value) ? $value : null;
    }

    /** Decimal IDs can exceed PHP_INT_MAX; compare without coercing to a float. */
    private static function compareSequence(string $first, string $second): int
    {
        $length = strlen($first) <=> strlen($second);
        return $length !== 0 ? $length : strcmp($first, $second);
    }

    private static function receipt(array $receipt): array
    {
        $safe = [];
        foreach (['event_id', 'message_id', 'http_status', 'verified', 'reconciled', 'request_count', 'write_attempts', 'content_preserved', 'button_verified'] as $key) {
            if (!array_key_exists($key, $receipt) || !is_scalar($receipt[$key])) continue;
            $safe[$key] = is_string($receipt[$key]) ? self::text($receipt[$key], 191) : $receipt[$key];
        }
        if (is_array($receipt['http_results'] ?? null)) {
            $safe['http_results'] = [];
            foreach (array_slice($receipt['http_results'], 0, 5) as $result) {
                if (!is_array($result) || !is_string($result['operation'] ?? null) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $result['operation'])) continue;
                $http = filter_var($result['http'] ?? null, FILTER_VALIDATE_INT);
                $error = filter_var($result['transport_error'] ?? null, FILTER_VALIDATE_INT);
                if ($http === false || $http < 0 || $http > 599 || $error === false || $error < 0 || $error > 1000) continue;
                $safe['http_results'][] = ['operation' => $result['operation'], 'http' => $http, 'transport_error' => $error];
            }
        }
        return $safe;
    }

    private static function botUrl(string $platform, string $url, string $username): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        $host = $platform === 'max' ? 'max.ru' : 't.me';
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || strtolower((string)($parts['host'] ?? '')) !== $host || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) throw new InvalidArgumentException('invalid_bot_url');
        $path = trim((string)($parts['path'] ?? ''), '/');
        if ($path !== $username || !preg_match('/\A[A-Za-z0-9_]{1,191}\z/D', $path)) throw new InvalidArgumentException('bot_url_identity_mismatch');
        return 'https://' . $host . '/' . $path;
    }

    private static function postUrl(string $platform, string $url): string
    {
        $parts = parse_url($url);
        $host = $platform === 'max' ? 'max.ru' : 't.me';
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || strtolower((string)($parts['host'] ?? '')) !== $host || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) return '';
        $path = (string)($parts['path'] ?? '');
        return preg_match('~\A/(?:c/-?[1-9][0-9]{0,19}|[A-Za-z0-9_]{1,191})/[A-Za-z0-9_-]{1,191}\z~D', $path) ? $url : '';
    }

    private static function platform(string $platform): string
    {
        if (!in_array($platform, ['max', 'telegram'], true)) throw new InvalidArgumentException('unsupported_post_platform');
        return $platform;
    }

    private static function identity($value, int $maximum): string
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > $maximum || !preg_match('/\A[A-Za-z0-9_:.\/-]+\z/D', $value)) throw new InvalidArgumentException('invalid_post_identity');
        return $value;
    }

    private static function text(string $value, int $maximum): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) throw new InvalidArgumentException('invalid_post_utf8');
        return mb_substr(trim($value), 0, $maximum, 'UTF-8');
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function fetch(string $sql, array $parameters): ?array
    {
        $q = $this->pdo->prepare($sql);
        $q->execute($parameters);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function execute(string $sql, array $parameters): int
    {
        $q = $this->pdo->prepare($sql);
        $q->execute($parameters);
        return $q->rowCount();
    }

    private function transaction(callable $operation)
    {
        if ($this->pdo->inTransaction()) throw new LogicException('channel_registry_requires_own_transaction');
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function lock(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
