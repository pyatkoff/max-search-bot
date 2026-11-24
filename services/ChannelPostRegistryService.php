<?php
declare(strict_types=1);

require_once __DIR__ . '/ConversationDb.php';
require_once __DIR__ . '/ConversationRecordClock.php';
require_once __DIR__ . '/ChannelPostReconciliationState.php';
require_once __DIR__ . '/ChannelPostTelegramAlbum.php';
require_once __DIR__ . '/ChannelPostMemberDeliveryService.php';
require_once __DIR__ . '/../integrations/ChannelPostIncomingAdapter.php';

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
    private const MANUAL_TELEGRAM_REPAIR = 'aloft_public_keyboard';

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConversationDb::connection();
    }

    public function memberDelivery(): ChannelPostMemberDeliveryService
    {
        return new ChannelPostMemberDeliveryService($this->pdo);
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
            $configured = $this->channel($project, $platform, $bot, $channelId, false) ?? throw new RuntimeException('channel_configuration_unavailable');
            if ($platform === 'max') (new ChannelPostReconciliationState($this->pdo))->configured((int)$configured['id'], !empty($existing['is_enabled']), $enabled === 1, (int)floor(microtime(true)*1000));
            return $configured;
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
        return $this->registerObservation($projectKey,$platform,$botKey,$channelId,$messageId,$snapshot);
    }

    /**
     * Explicit editor only. Reserve an identity, never a native observation or
     * queued version. The caller owns durable approval and the once-only journal.
     */
    public function reserveTelegramHotelTransfer(array $row, string $planDigest, ?callable $authorize = null): array
    {
        $row = self::telegramHotelTransferRow($row, $planDigest);
        return $this->transaction(function () use ($row, $planDigest, $authorize): array {
            $channel = $this->telegramHotelTransferChannel($row);
            $this->telegramHotelTransferSource($row);
            $existing = $this->fetch('SELECT id FROM post_publications WHERE post_channel_id=? AND external_message_id=?' . $this->lock(), [(int)$channel['id'], $row['target_message_id']]);
            // An orphan reservation is not proof of this operator's ownership.
            if ($existing) throw new RuntimeException('hotel_transfer_identity_exists');
            if ($authorize === null || $authorize($row, $planDigest) !== true) throw new RuntimeException('hotel_transfer_approval_required');
            $token = self::publicationToken();
            $this->execute('INSERT INTO post_publications (post_channel_id,source_id,external_message_id,start_payload,created_at) VALUES (?,?,?,?,?)', [(int)$channel['id'], (int)$channel['source_id'], $row['target_message_id'], $token, self::now()]);
            return self::telegramHotelTransferReservation($channel, $row, $planDigest, (int)$this->pdo->lastInsertId(), $token);
        });
    }

    /** Read-only final CAS. No version, claim, expiry, provider call or rearming. */
    public function assertTelegramHotelTransferReservation(array $reservation, array $row, string $planDigest): array
    {
        $row = self::telegramHotelTransferRow($row, $planDigest);
        return $this->transaction(function () use ($reservation, $row, $planDigest): array {
            $channel = $this->telegramHotelTransferChannel($row);
            $this->telegramHotelTransferSource($row);
            $identity = $this->telegramHotelTransferIdentity($reservation, $channel, $row, $planDigest);
            if ($identity['current_version_id'] !== null || $identity['observed_revision_ms'] !== null || $identity['telegram_sequence'] !== null
                || $this->fetch('SELECT id FROM post_versions WHERE publication_id=? LIMIT 1' . $this->lock(), [(int)$identity['id']])
                || $this->fetch('SELECT version_id FROM post_observations WHERE publication_id=? LIMIT 1' . $this->lock(), [(int)$identity['id']])) throw new RuntimeException('hotel_transfer_reservation_changed');
            return $reservation;
        });
    }

    /**
     * Adopt one authentic saved editMessageMedia response. Authentication and the
     * media once-only marker belong to the caller's private editor journal. This
     * method performs no provider write and invents no Telegram update identity.
     */
    public function completeTelegramHotelTransfer(array $reservation, array $row, string $planDigest, array $nativeResponse): array
    {
        $row = self::telegramHotelTransferRow($row, $planDigest);
        $observed = self::telegramHotelTransferResponse($reservation, $row, $nativeResponse);
        $responseDigest = hash('sha256', self::json(self::canonicalArray($nativeResponse)));
        return $this->transaction(function () use ($reservation, $row, $planDigest, $nativeResponse, $observed, $responseDigest): array {
            $channel = $this->telegramHotelTransferChannel($row);
            $identity = $this->telegramHotelTransferIdentity($reservation, $channel, $row, $planDigest);
            $id = (int)$identity['id'];
            if ($identity['current_version_id'] === null && ($identity['observed_revision_ms'] !== null || $identity['telegram_sequence'] !== null)) throw new RuntimeException('hotel_transfer_target_changed');
            $previous = $identity['current_version_id'] === null ? null : $this->fetch('SELECT * FROM post_versions WHERE id=? AND publication_id=?' . $this->lock(), [(int)$identity['current_version_id'], $id]);
            $provenance = ['kind'=>'telegram_hotel_transfer_v1', 'plan_digest'=>$planDigest,
                'row_digest'=>$reservation['row_digest'], 'response_sha256'=>$responseDigest,
                'publication_id'=>$id, 'source_publication_id'=>$row['source_publication_id'],
                'source_version_id'=>$row['source_version_id'], 'source_content_hash'=>$row['source_content_hash'],
                'source_file_unique_id_sha256'=>$row['source_file_unique_id_sha256']];
            if ($identity['current_version_id'] !== null && !$previous) throw new RuntimeException('hotel_transfer_target_changed');
            $priorSnapshot = $previous ? (json_decode((string)$previous['snapshot_json'], true) ?: []) : null;
            $revision = (int)$nativeResponse['data']['result']['edit_date'] * 1000;
            if ((int)($identity['observed_revision_ms'] ?? 0) > $revision) throw new RuntimeException('hotel_transfer_target_changed');
            if ($previous) {
                self::telegramHotelTransferObservedMatch($priorSnapshot, $observed, $row);
                if (!hash_equals((string)$previous['content_hash'], ChannelPostIncomingAdapter::contentFingerprint($priorSnapshot))) throw new RuntimeException('hotel_transfer_target_changed');
                if (!isset($priorSnapshot['metadata']['hotel_transfer'])) {
                    $nativeEvent = $priorSnapshot['metadata']['source_event_id'] ?? null;
                    $nativeObservation = is_string($nativeEvent) && preg_match('/\A[a-f0-9]{64}\z/D', $nativeEvent)
                        ? $this->fetch('SELECT version_id FROM post_observations WHERE publication_id=? AND source_event_key=?' . $this->lock(), [$id, hash('sha256', $nativeEvent)]) : null;
                    if (!$nativeObservation || (int)$nativeObservation['version_id'] !== (int)$previous['id']
                        || ($priorSnapshot['metadata']['source_sequence_scope'] ?? '') !== 'telegram_update_id'
                        || self::validSequence($priorSnapshot['metadata']['source_sequence'] ?? null) === null) throw new RuntimeException('hotel_transfer_target_changed');
                }
            }
            $held = $this->fetch("SELECT id,state,write_attempts,claim_token,claimed_at FROM post_versions WHERE publication_id=? AND (state IN ('claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL) ORDER BY id LIMIT 1" . $this->lock(), [$id]);
            if ($held) {
                $this->telegramHotelTransferSource($row);
                $history = $this->fetch('SELECT COUNT(*) AS total FROM post_versions WHERE publication_id=?', [$id]);
                // The only retryable DB completion race is an ordinary worker
                // reading this already-correct keyboard before its zero-write finish.
                if ($previous && (int)$history['total'] === 1 && (int)$held['id'] === (int)$previous['id']
                    && $held['state'] === 'claimed' && (int)$held['write_attempts'] === 0
                    && is_string($held['claim_token']) && preg_match('/\A[a-f0-9]{32}\z/D', $held['claim_token'])
                    && is_string($held['claimed_at']) && $held['claimed_at'] >= gmdate('Y-m-d H:i:s', time() - self::LEASE_SECONDS)
                    && $held['claimed_at'] <= self::now() && !isset($priorSnapshot['metadata']['hotel_transfer'])
                    && (!isset($priorSnapshot['metadata']['hotel_catalog_id']) || (int)$priorSnapshot['metadata']['hotel_catalog_id'] === $row['hotel_id'])) throw new RuntimeException('hotel_transfer_native_claim_busy');
                throw new RuntimeException('hotel_transfer_target_held');
            }
            if ($previous) {
                if (($priorSnapshot['metadata']['hotel_transfer'] ?? null) === $provenance
                    && ($priorSnapshot['metadata']['hotel_catalog_id'] ?? null) === $row['hotel_id']
                    && ($priorSnapshot['metadata']['hotel_transfer_price_record_id'] ?? null) === $row['price_record_id']
                    && $previous['state'] === 'sent') {
                    return ($this->publication($id) ?? throw new RuntimeException('hotel_transfer_target_changed')) + ['already_completed'=>true];
                }
            }
            $this->telegramHotelTransferSource($row);
            $history = $this->fetch('SELECT COUNT(*) AS total FROM post_versions WHERE publication_id=?', [$id]);
            if (($previous && ((int)$history['total'] !== 1 || (int)$previous['version_number'] !== 1))
                || (!$previous && (int)$history['total'] !== 0)) throw new RuntimeException('hotel_transfer_target_changed');
            if ($previous && (!in_array($previous['state'], ['pending','sent'], true)
                || (int)$previous['write_attempts'] !== 0
                || isset($priorSnapshot['metadata']['hotel_transfer'])
                || (isset($priorSnapshot['metadata']['hotel_catalog_id']) && (int)$priorSnapshot['metadata']['hotel_catalog_id'] !== $row['hotel_id']))) throw new RuntimeException('hotel_transfer_target_changed');
            $event = 'authorized-hotel-media:' . $planDigest . ':' . $row['target_message_id'] . ':' . $responseDigest;
            $snapshot = $priorSnapshot ?? $observed;
            if (!$previous) {
                $snapshot['metadata']['observation_kind'] = 'authorized_admin_media_edit';
                $snapshot['metadata']['source_event_id'] = $event;
                $snapshot['metadata']['source_revision_ms'] = (string)$revision;
            }
            $snapshot['metadata']['hotel_catalog_id'] = $row['hotel_id'];
            if ($row['price_record_id'] !== null) {
                $snapshot['metadata']['hotel_transfer_price_record_id'] = $row['price_record_id'];
            }
            $snapshot['metadata']['hotel_transfer'] = $provenance;
            $snapshot = self::snapshot($snapshot);
            $hash = ChannelPostIncomingAdapter::contentFingerprint($snapshot);
            $receipt = ['verified'=>true, 'button_verified'=>true, 'media_verified'=>true, 'caption_verified'=>true,
                'publication_date_verified'=>true, 'completion_kind'=>'telegram_hotel_transfer_v1',
                'plan_digest'=>$planDigest, 'row_digest'=>$reservation['row_digest'],
                'provider_response_sha256'=>$responseDigest, 'media_write_attempts'=>1,
                'request_count'=>0, 'write_attempts'=>0, 'adopted_from_version_id'=>$previous ? (int)$previous['id'] : null];
            $now = self::now();
            $this->execute("INSERT INTO post_versions (publication_id,version_number,content_hash,snapshot_json,state,write_attempts,error_reason,receipt_json,created_at,updated_at) VALUES (?,?,?,?,'sent',0,'',?,?,?)", [$id, $previous ? (int)$previous['version_number'] + 1 : 1, $hash, self::json($snapshot), self::json($receipt), $now, $now]);
            $versionId = (int)$this->pdo->lastInsertId();
            $cas = $previous ? 'current_version_id=?' : 'current_version_id IS NULL';
            $values = [$versionId, $id];
            if ($previous) $values[] = (int)$previous['id'];
            if ($this->execute('UPDATE post_publications SET current_version_id=? WHERE id=? AND ' . $cas, $values) !== 1) throw new RuntimeException('hotel_transfer_target_changed');
            if ($previous && $previous['state'] === 'pending') {
                if ($this->execute("UPDATE post_versions SET state='requires_attention',error_reason='superseded_before_write',updated_at=? WHERE id=? AND state='pending' AND write_attempts=0 AND claim_token IS NULL AND claimed_at IS NULL", [$now, (int)$previous['id']]) !== 1) throw new RuntimeException('hotel_transfer_target_held');
            }
            // HTTP has edit_date but no update_id. Never replace a genuine
            // same-second sequence or lower a newer accepted native clock.
            if ((int)($identity['observed_revision_ms'] ?? 0) < $revision) {
                $this->execute('UPDATE post_publications SET observed_revision_ms=? WHERE id=?', [$revision, $id]);
            }
            $this->execute('INSERT INTO post_observations (publication_id,source_event_key,version_id,created_at) VALUES (?,?,?,?)', [$id, hash('sha256', $event), $versionId, $now]);
            return ($this->publication($id) ?? throw new RuntimeException('hotel_transfer_target_changed')) + ['already_completed'=>false];
        });
    }

    private static function telegramHotelTransferRow(array $row, string $planDigest): array
    {
        require_once __DIR__ . '/../config/anytour_ranked_transfer_scope_20261123.php';
        $keys = AnyTourRankedHotelTransfer46::REGISTRY_FIELDS;
        $actual = array_keys($row); sort($actual); $expected = $keys; sort($expected);
        if ($actual !== $expected || !preg_match('/\A[a-f0-9]{64}\z/D', $planDigest)) throw new InvalidArgumentException('hotel_transfer_plan_invalid');
        $pairs = [17595=>['455',1003,'862',1726731731], 358=>['65',211,'328',1705145228],
            393=>['85',247,'1083',1745392994], 1260=>['548',1220,'288',1703918786]];
        $next = hash_equals('30f93518da51279979f7669538f7c09f6096733e9423f755dc21981af821b98d', $planDigest);
        $ranked = AnyTourRankedHotelTransfer46::profile($planDigest);
        if ($ranked !== null) {
            $next = true;
            $pairs = [];
            foreach ($ranked['pairs'] as $id => $pair) {
                $pairs[$id] = [$pair['source'], $pair['source_publication'], $pair['target'], $pair['date']];
            }
        } elseif ($next) {
            $pairs = [800=>['219',535,'1588',1781855273], 408=>['120',323,'997',1737725306],
                466=>['473',1047,'1101',1747735272]];
        }
        if (!is_int($row['hotel_id']) || !isset($pairs[$row['hotel_id']])
            || [$row['source_message_id'],$row['source_publication_id'],$row['target_message_id'],$row['target_published_unixtime']] !== $pairs[$row['hotel_id']]
            || !is_int($row['source_version_id']) || $row['source_version_id'] <= 0
            || $row['target_config_version'] !== 2 || $row['bot_url'] !== 'https://t.me/anyru_bot') throw new InvalidArgumentException('hotel_transfer_scope_invalid');
        foreach (['source_content_hash','source_caption_sha256','source_file_id_sha256','source_file_unique_id_sha256',
            'target_caption_sha256','target_photo_marker_sha256','next_caption_sha256'] as $key) {
            if (!is_string($row[$key]) || !preg_match('/\A[a-f0-9]{64}\z/D', $row[$key])) throw new InvalidArgumentException('hotel_transfer_plan_invalid');
        }
        if ($next ? $row['price_record_id'] !== null
            : (!is_string($row['price_record_id']) || !preg_match('/\A[a-f0-9]{64}\z/D', $row['price_record_id']))) {
            throw new InvalidArgumentException('hotel_transfer_plan_invalid');
        }
        if (!is_string($row['next_caption']) || $row['next_caption'] === '' || !mb_check_encoding($row['next_caption'], 'UTF-8')
            || strlen(mb_convert_encoding($row['next_caption'], 'UTF-16LE', 'UTF-8')) > 2048
            || !hash_equals(hash('sha256', $row['next_caption']), $row['next_caption_sha256'])
            || !is_string($row['button_text']) || $row['button_text'] === '' || !mb_check_encoding($row['button_text'], 'UTF-8')
            || mb_strlen($row['button_text'], 'UTF-8') > 80 || preg_match('/[\x00-\x1f\x7f]/u', $row['button_text'])) throw new InvalidArgumentException('hotel_transfer_plan_invalid');
        $validated = array_replace(array_fill_keys($keys, null), $row);
        if ($ranked !== null) {
            $actual = hash('sha256', json_encode($validated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (!hash_equals($ranked['pairs'][$row['hotel_id']]['registry_sha256'], $actual)) {
                throw new InvalidArgumentException('hotel_transfer_ranked_row_changed');
            }
        }
        return $validated;
    }

    private function telegramHotelTransferChannel(array $row): array
    {
        $channel = $this->channel('anytour', 'telegram', 'anyru_bot', '-1001845835645', true, true);
        if (!$channel || (int)$channel['config_version'] !== $row['target_config_version']
            || $channel['canonical_bot_username'] !== 'anyru_bot' || $channel['source_key'] !== 'tg_anytour_msk'
            || $channel['bot_url'] !== $row['bot_url'] || $channel['button_text'] !== $row['button_text']) throw new RuntimeException('hotel_transfer_configuration_changed');
        return $channel;
    }

    private function telegramHotelTransferSource(array $row): array
    {
        $channel = $this->channel('anytour', 'telegram', 'anyru_bot', '-1002262666616', true, true);
        $identity = $this->fetch('SELECT * FROM post_publications WHERE id=?' . $this->lock(), [$row['source_publication_id']]);
        if (!$channel || !$identity || (int)$identity['post_channel_id'] !== (int)$channel['id']
            || $identity['external_message_id'] !== $row['source_message_id']
            || (int)$identity['current_version_id'] !== $row['source_version_id']) throw new RuntimeException('hotel_transfer_source_changed');
        $version = $this->fetch('SELECT * FROM post_versions WHERE id=? AND publication_id=?' . $this->lock(), [$row['source_version_id'], $row['source_publication_id']]);
        $held = $this->fetch("SELECT id FROM post_versions WHERE publication_id=? AND (state IN ('claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL) LIMIT 1" . $this->lock(), [$row['source_publication_id']]);
        $snapshot = json_decode((string)($version['snapshot_json'] ?? ''), true) ?: [];
        $media = $snapshot['attachments'] ?? [];
        $photo = count($media) === 1 ? ($media[0] ?? []) : [];
        if (!$version || $held || $version['state'] !== 'sent' || (int)$identity['source_id'] !== (int)$channel['source_id']
            || !hash_equals((string)$version['content_hash'], $row['source_content_hash'])
            || !hash_equals(ChannelPostIncomingAdapter::contentFingerprint($snapshot), $row['source_content_hash'])
            || !hash_equals(hash('sha256', (string)($snapshot['text'] ?? '')), $row['source_caption_sha256'])
            || (int)($snapshot['metadata']['hotel_catalog_id'] ?? 0) !== $row['hotel_id']
            || ($snapshot['metadata']['capability'] ?? '') !== 'supported' || !empty($snapshot['metadata']['media_group_id'])
            || ($snapshot['post_url'] ?? '') !== 'https://t.me/any_toour/' . $row['source_message_id']
            || ($photo['type'] ?? '') !== 'image' || ($photo['provider'] ?? '') !== 'telegram'
            || !is_string($photo['telegram_file_id'] ?? null) || !preg_match('/\A[A-Za-z0-9_-]{8,1024}\z/D', $photo['telegram_file_id'])
            || !is_string($photo['file_unique_id'] ?? null) || !preg_match('/\A[A-Za-z0-9_-]{5,160}\z/D', $photo['file_unique_id'])
            || !hash_equals(hash('sha256', $photo['telegram_file_id']), $row['source_file_id_sha256'])
            || !hash_equals(hash('sha256', $photo['file_unique_id']), $row['source_file_unique_id_sha256'])) throw new RuntimeException('hotel_transfer_source_changed');
        return $snapshot;
    }

    private static function telegramHotelTransferReservation(array $channel, array $row, string $planDigest, int $id, string $token): array
    {
        return ['schema'=>'telegram_hotel_transfer_reservation_v1', 'project_key'=>'anytour', 'platform'=>'telegram',
            'bot_key'=>'anyru_bot', 'channel_id'=>'-1001845835645', 'post_channel_id'=>(int)$channel['id'],
            'source_id'=>(int)$channel['source_id'], 'target_config_version'=>(int)$channel['config_version'],
            'publication_id'=>$id, 'target_message_id'=>$row['target_message_id'], 'start_payload'=>$token,
            'button_url'=>$row['bot_url'] . '?start=' . $token, 'button_text'=>$row['button_text'],
            'current_version_id'=>null, 'plan_digest'=>$planDigest,
            'row_digest'=>hash('sha256', self::json(self::canonicalArray($row))),
            'channel_digest'=>hash('sha256', self::json(self::canonicalArray($channel)))];
    }

    private function telegramHotelTransferIdentity(array $reservation, array $channel, array $row, string $planDigest): array
    {
        $id = $reservation['publication_id'] ?? null; $token = $reservation['start_payload'] ?? null;
        if (!is_int($id) || $id <= 0 || !is_string($token) || !preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/D', $token)
            || self::canonicalArray($reservation) !== self::canonicalArray(self::telegramHotelTransferReservation($channel, $row, $planDigest, $id, $token))) throw new RuntimeException('hotel_transfer_reservation_changed');
        $identity = $this->fetch('SELECT * FROM post_publications WHERE post_channel_id=? AND external_message_id=?' . $this->lock(), [(int)$channel['id'], $row['target_message_id']]);
        if (!$identity || (int)$identity['id'] !== $id || (int)$identity['source_id'] !== (int)$channel['source_id']
            || !hash_equals((string)$identity['start_payload'], $token)) throw new RuntimeException('hotel_transfer_reservation_changed');
        return $identity;
    }

    private static function telegramHotelTransferResponse(array $reservation, array $row, array $response): array
    {
        $raw = $response['data']['result'] ?? null;
        if (($response['http'] ?? null) !== 200 || ($response['transport_error'] ?? null) !== false
            || ($response['data']['ok'] ?? null) !== true || !is_array($raw)
            || (string)($raw['chat']['id'] ?? '') !== '-1001845835645' || ($raw['chat']['type'] ?? '') !== 'channel'
            || ($raw['chat']['username'] ?? '') !== 'anytour' || (string)($raw['message_id'] ?? '') !== $row['target_message_id']
            || ($raw['date'] ?? null) !== $row['target_published_unixtime'] || !is_int($raw['edit_date'] ?? null)
            || $raw['edit_date'] <= $row['target_published_unixtime'] || $raw['edit_date'] > 9999999999
            || ($raw['caption'] ?? null) !== $row['next_caption'] || isset($raw['text']) || !empty($raw['media_group_id'])
            || ($raw['caption_entities'] ?? []) !== [] || ($raw['entities'] ?? []) !== []) throw new RuntimeException('hotel_transfer_response_unconfirmed');
        $expectedKeyboard = ['inline_keyboard'=>[[['text'=>$row['button_text'], 'url'=>$reservation['button_url'] ?? null]]]];
        if (!is_array($raw['reply_markup'] ?? null)
            || self::canonicalArray($raw['reply_markup']) !== self::canonicalArray($expectedKeyboard)) throw new RuntimeException('hotel_transfer_response_unconfirmed');
        $snapshot = ChannelPostIncomingAdapter::telegramSnapshot($raw);
        $photo = $snapshot['attachments'][0] ?? [];
        if (!$snapshot || ($snapshot['metadata']['capability'] ?? '') !== 'supported' || count($snapshot['attachments']) !== 1
            || ($photo['type'] ?? '') !== 'image' || ($photo['provider'] ?? '') !== 'telegram'
            || !is_string($photo['file_unique_id'] ?? null)
            || !hash_equals(hash('sha256', $photo['file_unique_id']), $row['source_file_unique_id_sha256'])
            || $snapshot['post_url'] !== 'https://t.me/anytour/' . $row['target_message_id']) throw new RuntimeException('hotel_transfer_response_unconfirmed');
        return self::snapshot($snapshot);
    }

    private static function telegramHotelTransferObservedMatch(array $current, array $observed, array $row): void
    {
        foreach (['text','markup','post_url','published_at','edited_at'] as $key) {
            if (($current[$key] ?? null) !== ($observed[$key] ?? null)) throw new RuntimeException('hotel_transfer_target_changed');
        }
        $raw = $current['raw_body'] ?? [];
        $native = is_array($raw) ? ChannelPostIncomingAdapter::telegramSnapshot($raw) : null;
        if (!is_array($current['keyboard'] ?? null) || self::canonicalArray($current['keyboard']) !== self::canonicalArray($observed['keyboard'])
            || !$native || (string)($raw['chat']['id'] ?? '') !== '-1001845835645' || ($raw['chat']['type'] ?? '') !== 'channel'
            || (string)($raw['message_id'] ?? '') !== $row['target_message_id'] || ($raw['date'] ?? null) !== $row['target_published_unixtime']
            || $native['published_at'] !== $observed['published_at'] || $native['edited_at'] !== $observed['edited_at']
            || !hash_equals(ChannelPostIncomingAdapter::contentFingerprint($native), ChannelPostIncomingAdapter::contentFingerprint($observed))
            || self::canonicalArray($native['keyboard']) !== self::canonicalArray($observed['keyboard'])
            || ($current['metadata']['capability'] ?? '') !== 'supported' || !empty($current['metadata']['media_group_id'])
            || ($current['metadata']['actual_message_id'] ?? '') !== $row['target_message_id']
            || !hash_equals(ChannelPostIncomingAdapter::contentFingerprint($current), ChannelPostIncomingAdapter::contentFingerprint($observed))) throw new RuntimeException('hotel_transfer_target_changed');
    }

    private static function publicationToken(): string
    {
        return 'p_' . rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /** Allocate only the exact old MAX identity. No queued version is created. */
    public function reserveMaxSugarMarinaTransfer(array $channel): array
    {
        return $this->transaction(function () use ($channel): array {
            $this->lockMaxSugarMarinaTransferChannel($channel);
            $mid = 'mid.ffffc1752b045fed019edec4de843c85';
            $occupied = $this->fetch('SELECT p.id FROM post_publications p LEFT JOIN post_versions v ON v.id=p.current_version_id'
                . " WHERE p.post_channel_id=? AND (p.external_message_id=? OR JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id'))=?)"
                . ' LIMIT 1' . $this->lock(), [(int)$channel['id'], $mid, '800']);
            if ($occupied) throw new RuntimeException('max_transfer_target_or_hotel_occupied');
            $payload = self::publicationToken();
            $this->execute('INSERT INTO post_publications (post_channel_id,source_id,external_message_id,start_payload,created_at) VALUES (?,?,?,?,?)',
                [(int)$channel['id'], (int)$channel['source_id'], $mid, $payload, self::now()]);
            return ['id'=>(int)$this->pdo->lastInsertId(), 'payload'=>$payload];
        });
    }

    public function assertMaxSugarMarinaTransfer(array $channel, array $reservation): void
    {
        $this->transaction(function () use ($channel, $reservation): void {
            $row = $this->lockMaxSugarMarinaTransfer($channel, $reservation);
            if ($row['current_version_id'] !== null || $row['observed_revision_ms'] !== null
                || $this->fetch('SELECT id FROM post_versions WHERE publication_id=? LIMIT 1' . $this->lock(), [(int)$row['id']])) {
                throw new RuntimeException('max_transfer_target_or_hotel_occupied');
            }
        });
    }

    /** Adopt an authenticated exact GET readback, without queuing a provider edit. */
    public function completeMaxSugarMarinaTransfer(array $channel, array $reservation, array $message, array $plan, string $digest): array
    {
        $snapshot = ChannelPostIncomingAdapter::maxSnapshot($message);
        $button = rtrim((string)$channel['bot_url'], '/') . '?start=' . rawurlencode($reservation['payload']);
        if (!$snapshot || ($message['body']['mid'] ?? '') !== 'mid.ffffc1752b045fed019edec4de843c85'
            || (string)($message['recipient']['chat_id'] ?? '') !== '-68765999669267'
            || ($message['recipient']['chat_type'] ?? '') !== 'channel'
            || (string)($message['timestamp'] ?? '') !== '1781853904516'
            || $snapshot['post_url'] !== 'https://max.ru/anytour/AZ7exN6EPIU'
            || ($snapshot['metadata']['capability'] ?? '') !== 'supported'
            || $snapshot['text'] !== $plan['text'] || count($snapshot['attachments']) !== 1
            || hash('sha256', (string)($snapshot['attachments'][0]['photo_id'] ?? '')) !== $plan['source']['photo_sha256']
            || !ChannelPostIncomingAdapter::containsOnlyButton($snapshot, $button, $channel['button_text'])) {
            throw new RuntimeException('max_transfer_native_readback_mismatch');
        }
        return $this->transaction(function () use ($channel, $reservation, $snapshot, $plan, $digest): array {
            $row = $this->lockMaxSugarMarinaTransfer($channel, $reservation);
            $id = (int)$row['id'];
            $version = 1;
            if ($row['current_version_id'] !== null) {
                $this->fetch('SELECT id FROM post_versions WHERE id=? AND publication_id=?' . $this->lock(), [(int)$row['current_version_id'], $id]);
                $previous = $this->publication($id);
                // The native edit can have arrived by webhook after our reservation.
                if (!$previous || $previous['version_number'] !== 1 || $previous['write_attempts'] !== 0
                    || !in_array($previous['state'], ['pending','sent'], true)
                    || $previous['claim_token'] !== null || $previous['claimed_at'] !== null
                    || !hash_equals(ChannelPostIncomingAdapter::contentFingerprint($snapshot),
                        ChannelPostIncomingAdapter::contentFingerprint($previous['snapshot']))
                    || !ChannelPostIncomingAdapter::containsOnlyButton($previous['snapshot'],
                        rtrim((string)$channel['bot_url'], '/') . '?start=' . rawurlencode($reservation['payload']), $channel['button_text'])) {
                    throw new RuntimeException('max_transfer_registry_readback_mismatch');
                }
                if ($previous['state'] === 'pending') {
                    if ($this->execute("UPDATE post_versions SET state='requires_attention',error_reason='superseded_before_write',updated_at=? WHERE id=? AND state='pending' AND write_attempts=0 AND claim_token IS NULL AND claimed_at IS NULL",
                        [self::now(), $previous['version_id']]) !== 1) throw new RuntimeException('max_transfer_registry_readback_mismatch');
                }
                $version++;
            }
            $observationHash = hash('sha256', self::json(self::canonicalArray($snapshot['raw_body'])));
            $snapshot['title'] = $plan['hotel_name'];
            $snapshot['metadata']['hotel_catalog_id'] = 800;
            $snapshot['metadata']['observation_kind'] = 'authorized_admin_media_edit';
            $snapshot['metadata']['hotel_transfer_plan_digest'] = $digest;
            $snapshot = self::snapshot($snapshot);
            $receipt = ['verified'=>true, 'button_verified'=>true, 'media_verified'=>true,
                'caption_verified'=>true, 'publication_date_verified'=>true, 'completion_kind'=>'max_hotel_transfer_v1',
                'plan_digest'=>$digest, 'provider_response_sha256'=>$observationHash, 'media_write_attempts'=>1];
            $now = self::now();
            $this->execute("INSERT INTO post_versions (publication_id,version_number,content_hash,snapshot_json,state,write_attempts,error_reason,receipt_json,created_at,updated_at) VALUES (?,?,?,?,'sent',0,'',?,?,?)",
                [$id, $version, ChannelPostIncomingAdapter::contentFingerprint($snapshot), self::json($snapshot), self::json($receipt), $now, $now]);
            $versionId = (int)$this->pdo->lastInsertId();
            $this->execute('UPDATE post_publications SET current_version_id=? WHERE id=?', [$versionId, $id]);
            return $this->publication($id) ?? throw new RuntimeException('max_transfer_registry_readback_mismatch');
        });
    }

    private function lockMaxSugarMarinaTransferChannel(array $channel): void
    {
        $fresh = $this->channel('anytour', 'max', (string)$channel['bot_key'], '-68765999669267', true, true);
        if ($fresh !== $channel || (int)$channel['config_version'] !== 2) {
            throw new RuntimeException('max_transfer_channel_configuration_changed');
        }
    }

    private function lockMaxSugarMarinaTransfer(array $channel, array $reservation): array
    {
        $this->lockMaxSugarMarinaTransferChannel($channel);
        $row = $this->fetch('SELECT * FROM post_publications WHERE id=? AND post_channel_id=? AND external_message_id=?' . $this->lock(),
            [$reservation['id'], (int)$channel['id'], 'mid.ffffc1752b045fed019edec4de843c85']);
        if (!$row || (int)$row['source_id'] !== (int)$channel['source_id'] || !hash_equals($row['start_payload'], $reservation['payload'])) {
            throw new RuntimeException('max_transfer_reservation_changed');
        }
        return $row;
    }

    /** Read the one selected publication without allocating a token or work. */
    public function manualTelegramButtonPublication(): ?array
    {
        $row = $this->fetch("SELECT pp.id FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id WHERE p.project_key='anytour' AND pc.platform='telegram' AND pc.bot_key='anyru_bot' AND pc.channel_id='-1001845835645' AND pp.external_message_id='1678' LIMIT 1", []);
        $publication = $row ? $this->publication((int)$row['id']) : null;
        if ($publication && ($original = $this->manualOriginalVersion((int)$publication['publication_id']))) {
            $publication['manual_original'] = ['version_id'=>(int)$original['id'], 'state'=>(string)$original['state'],
                'error_reason'=>(string)$original['error_reason'], 'attempt_count'=>(int)$original['attempt_count'],
                'write_attempts'=>(int)$original['write_attempts'], 'receipt'=>self::receipt($original['receipt'])];
        }
        return $publication;
    }

    /**
     * Public evidence may prepare a private CTA, never an authenticated update.
     * The first version is held in the same transaction as its ordinary token.
     */
    public function prepareManualTelegramButton(array $channel, array $publicSnapshot, array $publisher, ?callable $authorize = null): array
    {
        $expectedChannel = self::manualChannelBinding($channel);
        $publisher = self::manualPublisher($publisher);
        $snapshot = self::snapshot($publicSnapshot);
        $baseline = self::manualPublicFingerprint($snapshot);
        return $this->transaction(function () use ($expectedChannel, $snapshot, $baseline, $publisher, $authorize): array {
            if ($authorize !== null && $authorize() !== true) throw new RuntimeException('forbidden');
            $channel = $this->channel('anytour', 'telegram', 'anyru_bot', '-1001845835645', true, true);
            if (!$channel || self::manualChannelBinding($channel) !== $expectedChannel) throw new RuntimeException('manual_channel_changed');
            $row = $this->fetch('SELECT id FROM post_publications WHERE post_channel_id=? AND external_message_id=?' . $this->lock(), [(int)$channel['id'], '1678']);
            if ($row) {
                $existing = $this->publication((int)$row['id']);
                if (!$existing) throw new RuntimeException('manual_publication_changed');
                $this->requireManualClaimable($existing);
                if ($existing['publisher'] !== $publisher || !hash_equals($baseline, (string)$existing['public_fingerprint'])) throw new RuntimeException('manual_publication_conflict');
                return $existing;
            }
            $token = 'p_' . rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $now = self::now();
            $this->execute('INSERT INTO post_publications (post_channel_id,source_id,external_message_id,start_payload,created_at) VALUES (?,?,?,?,?)', [(int)$channel['id'], 9, '1678', $token, $now]);
            $id = (int)$this->pdo->lastInsertId();
            $receipt = ['repair_kind'=>self::MANUAL_TELEGRAM_REPAIR, 'publisher_username'=>$publisher['username'],
                'publisher_bot_id'=>$publisher['bot_id'], 'credential_version'=>$publisher['credential_version'],
                'channel_config_version'=>(int)$channel['config_version'], 'public_baseline_sha256'=>$baseline];
            $this->execute("INSERT INTO post_versions (publication_id,version_number,content_hash,snapshot_json,state,write_attempts,error_reason,receipt_json,created_at,updated_at) VALUES (?,1,?,?,'requires_attention',0,'manual_approval_required',?,?,?)", [$id, $baseline, self::json($snapshot), self::json($receipt), $now, $now]);
            $version = (int)$this->pdo->lastInsertId();
            $this->execute('UPDATE post_publications SET current_version_id=? WHERE id=?', [$version, $id]);
            $prepared = $this->publication($id) ?? throw new RuntimeException('manual_publication_changed');
            $receipt['plan_digest'] = self::manualTelegramButtonDigest($prepared);
            $this->execute('UPDATE post_versions SET receipt_json=? WHERE id=? AND publication_id=?', [self::json($receipt), $version, $id]);
            // Public reads deliberately create neither provider observation IDs
            // nor the authenticated revision/update clocks used by webhooks.
            return $this->publication($id) ?? throw new RuntimeException('manual_publication_changed');
        });
    }

    /** Exact review identity; delivery state and observation time cannot rebind it. */
    public static function manualTelegramButtonDigest(array $publication): string
    {
        $channel = self::manualChannelBinding((array)($publication['channel'] ?? []));
        $receipt = (array)($publication['receipt'] ?? []);
        if (($publication['project_key'] ?? null) !== 'anytour' || ($publication['platform'] ?? null) !== 'telegram'
            || ($publication['bot_key'] ?? null) !== 'anyru_bot' || ($publication['channel_id'] ?? null) !== '-1001845835645'
            || ($publication['external_message_id'] ?? null) !== '1678' || (int)($publication['source_id'] ?? 0) !== 9
            || (int)($publication['publication_id'] ?? 0) < 1 || (int)($publication['version_id'] ?? 0) < 1
            || ($receipt['repair_kind'] ?? null) !== self::MANUAL_TELEGRAM_REPAIR
            || ($receipt['channel_config_version'] ?? null) !== $channel['config_version']
            || !is_string($publication['start_payload'] ?? null)
            || preg_match('/\Ap_[A-Za-z0-9_-]{24}\z/D', $publication['start_payload']) !== 1) throw new RuntimeException('manual_publication_changed');
        $publisher = self::manualPublisher(['username'=>$receipt['publisher_username'] ?? null,
            'bot_id'=>$receipt['publisher_bot_id'] ?? null, 'credential_version'=>$receipt['credential_version'] ?? null]);
        $baseline = self::manualPublicFingerprint((array)($publication['snapshot'] ?? []));
        if (!is_string($receipt['public_baseline_sha256'] ?? null) || !hash_equals($baseline, $receipt['public_baseline_sha256'])
            || !is_string($publication['content_hash'] ?? null) || !hash_equals($baseline, $publication['content_hash'])) throw new RuntimeException('manual_publication_changed');
        return hash('sha256', self::json(['schema'=>'aloft-public-keyboard-plan-v1', 'channel'=>$channel,
            'publication_id'=>(int)$publication['publication_id'], 'version_id'=>(int)$publication['version_id'],
            'version_number'=>(int)($publication['version_number'] ?? 0), 'message_id'=>'1678', 'hotel_catalog_id'=>61159,
            'publisher'=>$publisher, 'button_text'=>$channel['button_text'],
            'button_url'=>$channel['bot_url'].'?start='.$publication['start_payload'], 'public_fingerprint'=>$baseline]));
    }

    /** Consume one separately approved, unchanged held preparation. */
    public function claimManualTelegramButton(array $publication, string $expectedDigest, ?callable $authorize = null): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1
            || !hash_equals(self::manualTelegramButtonDigest($publication), $expectedDigest)) throw new RuntimeException('manual_approval_changed');
        return $this->transaction(function () use ($publication, $expectedDigest, $authorize): array {
            if ($authorize !== null && $authorize() !== true) throw new RuntimeException('forbidden');
            $channel = $this->channel('anytour', 'telegram', 'anyru_bot', '-1001845835645', true, true);
            if (!$channel || self::manualChannelBinding($channel) !== self::manualChannelBinding($publication['channel'])) throw new RuntimeException('manual_channel_changed');
            $id = (int)$publication['publication_id'];
            $this->fetch('SELECT id FROM post_publications WHERE id=?' . $this->lock(), [$id]);
            $fresh = $this->publication($id);
            if (!$fresh || (int)$fresh['version_id'] !== (int)$publication['version_id']) throw new RuntimeException('manual_publication_changed');
            $this->requireManualClaimable($fresh);
            if (!hash_equals(self::manualTelegramButtonDigest($fresh), $expectedDigest)) throw new RuntimeException('manual_approval_changed');
            $token = bin2hex(random_bytes(16)); $now = self::now();
            $saved = $this->execute("UPDATE post_versions SET state='claimed',claim_token=?,claimed_at=?,attempt_count=attempt_count+1,updated_at=? WHERE id=? AND publication_id=? AND state='requires_attention' AND error_reason='manual_approval_required' AND attempt_count=0 AND write_attempts=0 AND claim_token IS NULL AND claimed_at IS NULL", [$token, $now, $now, (int)$fresh['version_id'], $id]);
            if ($saved !== 1) throw new RuntimeException('manual_repair_not_claimable');
            return $this->publication($id) ?? throw new RuntimeException('manual_publication_changed');
        });
    }

    private static function manualChannelBinding(array $channel): array
    {
        foreach (['project_key'=>'anytour','platform'=>'telegram','bot_key'=>'anyru_bot','canonical_bot_username'=>'anyru_bot',
            'channel_id'=>'-1001845835645','source_key'=>'tg_anytour_msk','bot_url'=>'https://t.me/anyru_bot'] as $key=>$value) {
            if (($channel[$key] ?? null) !== $value) throw new RuntimeException('manual_target_required');
        }
        if ((int)($channel['id'] ?? 0) < 1 || (int)($channel['source_id'] ?? 0) !== 9
            || (int)($channel['config_version'] ?? 0) < 1 || (int)($channel['is_enabled'] ?? 0) !== 1
            || (int)($channel['source_active'] ?? 0) !== 1 || (int)($channel['project_active'] ?? 0) !== 1
            || ($channel['source_platform'] ?? null) !== 'telegram'
            || !in_array($channel['source_handling_mode'] ?? null, ['manager','ask'], true)
            || ($channel['post_mode'] ?? 'append_button') !== 'append_button'
            || !is_string($channel['button_text'] ?? null) || trim($channel['button_text']) === ''
            || mb_strlen($channel['button_text'], 'UTF-8') > 80) throw new RuntimeException('manual_target_required');
        return ['id'=>(int)$channel['id'], 'project_key'=>'anytour', 'platform'=>'telegram', 'bot_key'=>'anyru_bot',
            'channel_id'=>'-1001845835645', 'source_id'=>9, 'source_key'=>'tg_anytour_msk',
            'config_version'=>(int)$channel['config_version'], 'bot_url'=>'https://t.me/anyru_bot',
            'button_text'=>$channel['button_text'], 'post_mode'=>'append_button',
            'source_handling_mode'=>$channel['source_handling_mode']];
    }

    private static function manualPublisher(array $publisher): array
    {
        if (count($publisher) !== 3 || array_diff(array_keys($publisher), ['username','bot_id','credential_version'])
            || ($publisher['username'] ?? null) !== 'ANYTOURBOT_bot' || !is_string($publisher['bot_id'] ?? null)
            || preg_match('/\A[1-9][0-9]{0,19}\z/D', $publisher['bot_id']) !== 1
            || !is_int($publisher['credential_version'] ?? null) || $publisher['credential_version'] < 1) throw new RuntimeException('invalid_manual_publisher');
        return ['username'=>$publisher['username'], 'bot_id'=>$publisher['bot_id'], 'credential_version'=>$publisher['credential_version']];
    }

    private static function manualPublicFingerprint(array $snapshot): string
    {
        $hash = ChannelPostIncomingAdapter::aloftPublicFingerprint($snapshot);
        if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) throw new RuntimeException('public_observation_invalid');
        return $hash;
    }

    private function requireManualClaimable(array $publication): void
    {
        $digest = self::manualTelegramButtonDigest($publication);
        if (!is_string($publication['receipt']['plan_digest'] ?? null) || !hash_equals($digest, $publication['receipt']['plan_digest'])) throw new RuntimeException('manual_approval_changed');
        $q = $this->pdo->prepare('SELECT id,state,attempt_count,write_attempts,claim_token,claimed_at FROM post_versions WHERE publication_id=? ORDER BY version_number' . $this->lock());
        $q->execute([(int)$publication['publication_id']]); $versions = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($versions as $version) {
            if ((int)$version['write_attempts'] > 0 || $version['state'] === 'unknown') throw new RuntimeException('manual_write_already_attempted');
            if ((int)$version['attempt_count'] > 0 || $version['claim_token'] !== null || $version['claimed_at'] !== null
                || in_array($version['state'], ['claimed','write_started'], true)) throw new RuntimeException('manual_repair_not_claimable');
        }
        if (count($versions) !== 1 || (int)$versions[0]['id'] !== (int)$publication['version_id']) throw new RuntimeException('manual_observation_changed');
        if ($publication['state'] !== 'requires_attention' || $publication['error_reason'] !== 'manual_approval_required') throw new RuntimeException('manual_repair_not_claimable');
    }

    private static function manualPlanReceipt(array $receipt): array
    {
        if (($receipt['repair_kind'] ?? null) !== self::MANUAL_TELEGRAM_REPAIR) return [];
        return array_intersect_key(self::receipt($receipt), array_fill_keys(['repair_kind','publisher_username','publisher_bot_id',
            'credential_version','channel_config_version','plan_digest','public_baseline_sha256'], true));
    }

    /** A later native version cannot erase the original manual/no-replay fence. */
    private function manualFenceReceipt(int $publicationId): ?array
    {
        $row = $this->manualOriginalVersion($publicationId);
        return $row ? self::manualPlanReceipt($row['receipt']) : null;
    }

    private function manualOriginalVersion(int $publicationId): ?array
    {
        $q = $this->pdo->prepare('SELECT id,state,error_reason,attempt_count,write_attempts,content_hash,snapshot_json,receipt_json FROM post_versions WHERE publication_id=? ORDER BY version_number');
        $q->execute([$publicationId]);
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $receipt = json_decode((string)$row['receipt_json'], true);
            if (is_array($receipt) && ($receipt['repair_kind'] ?? null) === self::MANUAL_TELEGRAM_REPAIR) {
                $row['receipt'] = $receipt;
                return $row;
            }
        }
        return null;
    }

    /** Carry only a proved hotel reference, never synthetic native media proof. */
    private function manualSuccessorSnapshot(int $publicationId, array $snapshot, array $fence): array
    {
        $snapshot['title'] = '';
        unset($snapshot['metadata']['hotel_catalog_id'], $snapshot['metadata']['context_reference_kind'],
            $snapshot['metadata']['public_baseline_sha256']);
        $original = $this->manualOriginalVersion($publicationId);
        if (!$original) return $snapshot;
        $baseline = json_decode((string)$original['snapshot_json'], true);
        try {
            if (!is_array($baseline) || !is_string($fence['public_baseline_sha256'] ?? null)
                || !hash_equals(self::manualPublicFingerprint($baseline), $fence['public_baseline_sha256'])
                || !hash_equals((string)$original['content_hash'], $fence['public_baseline_sha256'])) return $snapshot;
            $match = ChannelPostIncomingAdapter::aloftNativeComparison((array)$snapshot['raw_body'], $baseline);
            if (($match['matches'] ?? null) !== true) return $snapshot;
            $knownVideo = $original['receipt']['native_video_unique_id'] ?? null;
            if (is_string($knownVideo) && ($match['native_video_unique_id'] ?? null) !== $knownVideo) return $snapshot;
            $snapshot['title'] = 'Aloft Palm Jumeirah';
            $snapshot['metadata']['hotel_catalog_id'] = 61159;
            $snapshot['metadata']['context_reference_kind'] = 'public_hotel_binding';
            $snapshot['metadata']['public_baseline_sha256'] = $fence['public_baseline_sha256'];
        } catch (Throwable $ignored) { /* Unknown context remains unavailable. */ }
        return $snapshot;
    }

    /** Provider reads are explicitly distinct from authenticated webhook events. */
    public function registerProviderObservation(string $projectKey, string $botKey, array $channel, array $message, int $notBeforeMs): ?array
    {
        $stamp=$message['timestamp']??null;
        $mid=$message['body']['mid']??null;
        if ((!is_int($stamp)&&!is_string($stamp))||!preg_match('/\A[1-9][0-9]{0,15}\z/',(string)$stamp)||(int)$stamp<$notBeforeMs
            ||!is_string($mid)||!preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/',$mid)
            ||($message['recipient']['chat_type']??'')!=='channel'||(string)($message['recipient']['chat_id']??'')!==(string)$channel['channel_id']) return null;
        $snapshot=ChannelPostIncomingAdapter::maxSnapshot($message);
        if (!$snapshot) return null;
        $snapshot['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($snapshot);
        $snapshot['metadata']['source_revision_ms']=(string)$stamp;
        $snapshot['metadata']['observation_kind']='provider_read';
        return $this->registerObservation($projectKey,'max',$botKey,(string)$channel['channel_id'],$mid,$snapshot,(int)$channel['config_version']);
    }

    /** Explicit recovery only: a legacy video rejection is never reset or replayed. */
    public function recoverUnsupportedMaxVideoObservation(string $projectKey, string $botKey, array $observedChannel, array $message, int $expectedVersionId, string $expectedHash, bool $apply=false): array
    {
        if (($observedChannel['platform']??'')!=='max'||($apply&&$expectedVersionId<=0)||$expectedVersionId<0
            ||!preg_match('/\A[a-f0-9]{64}\z/D',$expectedHash)) throw new RuntimeException('exact_video_recovery_target_required');
        $mid=$message['body']['mid']??null;
        $stamp=$message['timestamp']??null;
        if (!is_string($mid)||!preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D',$mid)
            ||($message['recipient']['chat_type']??'')!=='channel'
            ||(string)($message['recipient']['chat_id']??'')!==(string)($observedChannel['channel_id']??'')
            ||(!is_int($stamp)&&!is_string($stamp))||!preg_match('/\A[1-9][0-9]{0,15}\z/D',(string)$stamp)) throw new RuntimeException('provider_page_identity_mismatch');
        $snapshot=ChannelPostIncomingAdapter::maxSnapshot($message);
        if (!$snapshot||($snapshot['metadata']['capability']??'')!=='supported') throw new RuntimeException('unsupported_post');
        $hash=ChannelPostIncomingAdapter::contentFingerprint($snapshot);
        if (!hash_equals($expectedHash,$hash)) throw new RuntimeException('target_content_changed');
        $content=self::maxVideoRecoveryContent($message);
        return $this->transaction(function () use ($projectKey,$botKey,$observedChannel,$message,$mid,$stamp,$snapshot,$hash,$content,$expectedVersionId,$apply): array {
            $channel=$this->channel($projectKey,'max',$botKey,(string)$observedChannel['channel_id'],true,true);
            if (!$channel||(int)$channel['id']!==(int)($observedChannel['id']??0)
                ||(int)$channel['config_version']!==(int)($observedChannel['config_version']??0)) throw new RuntimeException('channel_configuration_changed');
            $publication=$this->fetch('SELECT * FROM post_publications WHERE post_channel_id=? AND external_message_id=?'.$this->lock(),[(int)$channel['id'],$mid]);
            if (!$publication||(int)$publication['source_id']!==(int)$channel['source_id']||!$publication['current_version_id']) throw new RuntimeException('legacy_video_not_eligible');
            $previous=$this->fetch('SELECT * FROM post_versions WHERE id=? AND publication_id=?'.$this->lock(),[(int)$publication['current_version_id'],(int)$publication['id']]);
            if (!$previous||($expectedVersionId>0&&(int)$previous['id']!==$expectedVersionId)) throw new RuntimeException('target_version_changed');
            if ($previous['state']!=='requires_attention'||$previous['error_reason']!=='unsupported_media'
                ||(int)$previous['write_attempts']!==0||$previous['claim_token']!==null||$previous['claimed_at']!==null) throw new RuntimeException('legacy_video_not_eligible');
            // An older ambiguous or still-held operation also fences this publication.
            $held=$this->fetch("SELECT id FROM post_versions WHERE publication_id=? AND (state IN ('claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL) LIMIT 1".$this->lock(),[(int)$publication['id']]);
            if ($held) throw new RuntimeException('legacy_video_not_eligible');
            $legacy=json_decode((string)$previous['snapshot_json'],true);
            if (!is_array($legacy)||($legacy['metadata']['capability']??'')!=='requires_attention'
                ||($legacy['metadata']['capability_reason']??'')!=='unsupported_media'||!is_array($legacy['raw_body']??null)) throw new RuntimeException('legacy_video_not_eligible');
            $raw=$legacy['raw_body'];
            if (($raw['body']['mid']??'')!==$mid||($raw['recipient']['chat_type']??'')!=='channel'
                ||(string)($raw['recipient']['chat_id']??'')!==(string)$channel['channel_id']
                ||(string)($raw['timestamp']??'')!==(string)$stamp) throw new RuntimeException('legacy_video_content_changed');
            $normalized=ChannelPostIncomingAdapter::maxSnapshot($raw);
            if (!$normalized||($normalized['metadata']['capability']??'')!=='supported'
                ||self::canonicalArray(self::maxVideoRecoveryContent($raw))!==self::canonicalArray($content)
                ||!hash_equals(ChannelPostIncomingAdapter::contentFingerprint($legacy),ChannelPostIncomingAdapter::contentFingerprint($normalized))
                ||!hash_equals(ChannelPostIncomingAdapter::contentFingerprint($normalized),$hash)) throw new RuntimeException('legacy_video_content_changed');
            if (!$apply) return $this->publication((int)$publication['id'])??throw new RuntimeException('target_registration_changed');
            $next=self::snapshot($snapshot);
            $observation='provider-read:video-recovery:'.(int)$publication['id'].':'.(int)$previous['id'].':'.$hash;
            $next['metadata']['observation_kind']='provider_read';
            $next['metadata']['recovery_kind']='unsupported_max_video';
            $next['metadata']['recovered_version_id']=(int)$previous['id'];
            $next['metadata']['source_revision_ms']=(string)$stamp;
            $next['metadata']['source_event_id']=$observation;
            $next=self::snapshot($next);
            $now=self::now();
            $this->execute("INSERT INTO post_versions (publication_id,version_number,content_hash,snapshot_json,state,write_attempts,error_reason,receipt_json,created_at,updated_at) VALUES (?,?,?,?,'pending',0,'','[]',?,?)",[(int)$publication['id'],(int)$previous['version_number']+1,$hash,self::json($next),$now,$now]);
            $versionId=(int)$this->pdo->lastInsertId();
            if ($this->execute('UPDATE post_publications SET current_version_id=? WHERE id=? AND current_version_id=?',[$versionId,(int)$publication['id'],(int)$previous['id']])!==1) throw new RuntimeException('target_version_changed');
            $this->execute('INSERT INTO post_observations (publication_id,source_event_key,version_id,created_at) VALUES (?,?,?,?)',[(int)$publication['id'],hash('sha256',$observation),$versionId,$now]);
            // Retain the rejected row, p_ token and authenticated webhook clock verbatim.
            return $this->publication((int)$publication['id'])??throw new RuntimeException('target_registration_changed');
        });
    }

    /** Compare source media identities, not expiring URLs or replaceable keyboards. */
    private static function maxVideoRecoveryContent(array $message): array
    {
        $body=$message['body']??null;
        if (!is_array($body)||!array_key_exists('text',$body)||(!is_string($body['text'])&&$body['text']!==null)
            ||!is_array($body['attachments']??null)||!array_is_list($body['attachments'])) throw new RuntimeException('legacy_video_not_eligible');
        $media=[];$videos=0;
        foreach ($body['attachments'] as $item) {
            if (!is_array($item)||!is_array($item['payload']??null)) throw new RuntimeException('legacy_video_not_eligible');
            $type=$item['type']??'';$payload=$item['payload'];
            if ($type==='inline_keyboard') continue;
            if (!in_array($type,['image','video'],true)||!is_string($payload['token']??null)||$payload['token']==='') throw new RuntimeException('legacy_video_not_eligible');
            if ($type==='video') {
                if (!ChannelPostIncomingAdapter::maxVideoTokenValid($payload['token'])) throw new RuntimeException('legacy_video_not_eligible');
                if (ChannelPostIncomingAdapter::maxVideoId($payload['id']??null)==='') throw new RuntimeException('legacy_video_not_eligible');
                $videos++;
            } elseif (!preg_match('/\A[0-9]+\z/D',(string)($payload['photo_id']??''))) throw new RuntimeException('legacy_video_not_eligible');
            // Reusable credentials rotate; explicit media IDs identify content.
            $media[]=['type'=>$type,'video_id'=>$type==='video'?ChannelPostIncomingAdapter::maxVideoId($payload['id']):null,'photo_id'=>$type==='image'?(string)$payload['photo_id']:null];
        }
        if ($videos===0) throw new RuntimeException('legacy_video_not_eligible');
        return ['text'=>$body['text'],'markup'=>$body['markup']??[],'media'=>$media,'forward'=>$message['link']??null];
    }

    private function registerObservation(string $projectKey, string $platform, string $botKey, string $channelId, string $messageId, array $snapshot, ?int $providerConfigVersion=null): ?array
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
        return $this->transaction(function () use ($projectKey, $platform, $botKey, $channelId, $messageId, $snapshot, $hash, $suppliedHash, $providerConfigVersion): ?array {
            // Locking reads see current MySQL state even under REPEATABLE READ.
            // The publication and its configured source commit in the same scope.
            $channel = $this->channel($projectKey, $platform, $botKey, $channelId, true, true);
            if (!$channel || ($providerConfigVersion !== null && (int)$channel['config_version'] !== $providerConfigVersion)) return null;
            $publication = $this->fetch('SELECT * FROM post_publications WHERE post_channel_id=? AND external_message_id=?' . $this->lock(), [(int)$channel['id'], $messageId]);
            if (!$publication) {
                $token = self::publicationToken();
                $this->execute('INSERT INTO post_publications (post_channel_id,source_id,external_message_id,start_payload,created_at) VALUES (?,?,?,?,?)', [(int)$channel['id'], (int)$channel['source_id'], $messageId, $token, self::now()]);
                $publication = ['id' => (int)$this->pdo->lastInsertId(), 'current_version_id' => null];
            }
            $previous = $publication['current_version_id'] ? $this->fetch('SELECT * FROM post_versions WHERE id=? AND publication_id=?', [(int)$publication['current_version_id'], (int)$publication['id']]) : null;
            $manualFence = $projectKey === 'anytour' && $platform === 'telegram' && $botKey === 'anyru_bot'
                && $channelId === '-1001845835645' && $messageId === '1678'
                ? $this->manualFenceReceipt((int)$publication['id']) : null;
            if ($manualFence !== null) {
                $snapshot = $this->manualSuccessorSnapshot((int)$publication['id'], $snapshot, $manualFence);
                if ($suppliedHash === '') {
                    $fingerprint = [];
                    foreach (['text','title','format','attachments','post_url','media_group_id'] as $key) $fingerprint[$key] = $snapshot[$key] ?? null;
                    $hash = hash('sha256', self::json(self::canonicalArray($fingerprint)));
                }
            }
            $manualContextChanged = false;
            if ($manualFence !== null && $previous) {
                $priorManual = json_decode((string)$previous['snapshot_json'], true) ?: [];
                $reference = static fn(array $s): array => [(string)($s['title'] ?? ''),
                    $s['metadata']['hotel_catalog_id'] ?? null, $s['metadata']['context_reference_kind'] ?? null];
                // Unsupported video fingerprints lack duration and stable IDs.
                // A changed reference must still append a held native snapshot.
                $manualContextChanged = $reference($priorManual) !== $reference($snapshot);
            }
            $previousHash = $previous ? (string)$previous['content_hash'] : '';
            if ($previous && $platform === 'max') {
                $priorSnapshot = json_decode((string)$previous['snapshot_json'], true) ?: [];
                $videoIds = ChannelPostIncomingAdapter::maxVideoIds($priorSnapshot);
                // Compare old snapshots under the current identity contract;
                // never rewrite their immutable hash or create work for renewal.
                if ($videoIds !== [] && !in_array('', $videoIds, true)) {
                    $previousHash = ChannelPostIncomingAdapter::contentFingerprint($priorSnapshot);
                } elseif ($videoIds !== [] && ($previous['state'] === 'unknown'
                    || in_array($previous['state'], ['claimed','write_started'], true)
                    || $previous['claim_token'] !== null || $previous['claimed_at'] !== null)) {
                    return $this->publication((int)$publication['id']);
                }
            }
            if ($providerConfigVersion !== null) {
                if ($previous && (!hash_equals($previousHash,$hash) || $previous['state'] !== 'sent'
                    || ChannelPostIncomingAdapter::containsOnlyButton($snapshot,rtrim((string)$channel['bot_url'],'/').'?start='.rawurlencode((string)$publication['start_payload']),(string)$channel['button_text']))) return $this->publication((int)$publication['id']);
                $snapshot['metadata']['source_event_id']='provider-read:'.hash('sha256',$messageId.'|'.$hash.'|'.($previous['id']??'new'));
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
            $album = $platform === 'telegram' && str_starts_with($messageId, 'group:') && ChannelPostTelegramAlbum::isAlbum($snapshot);
            $albumOperation = ['state' => 'pending', 'write_attempts' => 0, 'receipt' => [], 'reason' => ''];
            if ($manualFence !== null) {
                // Native observations remain native immutable snapshots. They
                // invalidate the reviewed public version without granting work.
                $albumOperation = ['state'=>'requires_attention', 'write_attempts'=>0,
                    'receipt'=>$manualFence, 'reason'=>'manual_observation_changed'];
            }
            if ($album) {
                $priorSnapshot = $previous ? (json_decode((string)$previous['snapshot_json'], true) ?: []) : null;
                $merged = ChannelPostTelegramAlbum::merge($priorSnapshot, $snapshot);
                if ($merged['stale']) {
                    if ($sourceEventKey !== null) $this->execute('INSERT INTO post_observations (publication_id,source_event_key,version_id,created_at) VALUES (?,?,?,?)', [(int)$publication['id'], $sourceEventKey, (int)$previous['id'], self::now()]);
                    $result = $this->publication((int)$publication['id']);
                    if ($result) $result['stale_observation'] = true;
                    return $result;
                }
                $snapshot = self::snapshot($merged['snapshot']);
                $hash = ChannelPostIncomingAdapter::contentFingerprint($snapshot, '', (string)$channel['bot_url']);
                if ($previous) $albumOperation = ChannelPostTelegramAlbum::successorOperation($previous + ['snapshot' => $priorSnapshot], $snapshot, (bool)$merged['anchor_event']);
            }
            if ($previous && !$album && $providerConfigVersion === null) {
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
            // A newer authenticated wrong keyboard may replace a KNOWN completed
            // edit. Keep an immutable operation version, never reset UNKNOWN or
            // an in-flight write because another bot has sent an edit event.
            $keyboardCorrection = $previous && !$album && $sourceEventKey !== null
                && $previous['state'] === 'sent' && hash_equals($previousHash, $hash)
                && ($snapshot['metadata']['capability'] ?? '') === 'supported'
                && !ChannelPostIncomingAdapter::containsOnlyButton($snapshot,
                    rtrim((string)$channel['bot_url'], '/') . '?start=' . rawurlencode((string)$publication['start_payload']),
                    (string)$channel['button_text']);
            // Album observations also append immutable per-member clocks when the body is unchanged.
            if ($album || $keyboardCorrection || $manualContextChanged || !$previous || !hash_equals($previousHash, $hash)) {
                $version = $previous ? (int)$previous['version_number'] + 1 : 1;
                $now = self::now();
                $this->execute('INSERT INTO post_versions (publication_id,version_number,content_hash,snapshot_json,state,write_attempts,error_reason,receipt_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)', [(int)$publication['id'], $version, $hash, self::json($snapshot), $albumOperation['state'], $albumOperation['write_attempts'], $albumOperation['reason'], self::json($albumOperation['receipt']), $now, $now]);
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
            if (($providerConfigVersion === null || !$previous) && $incomingTime > 0 && ($incomingTime > $clockTime || ($incomingTime === $clockTime && $incomingSequence !== null && ($clockSequence === null || self::compareSequence($incomingSequence, $clockSequence) > 0)))) {
                $this->execute('UPDATE post_publications SET observed_revision_ms=?,telegram_sequence=? WHERE id=?', [$incomingTime, $incomingSequence, (int)$publication['id']]);
            }
            $result = $this->publication((int)$publication['id']);
            if ($result && $sourceEventKey !== null) $this->execute('INSERT INTO post_observations (publication_id,source_event_key,version_id,created_at) VALUES (?,?,?,?)', [(int)$publication['id'], $sourceEventKey, (int)$result['version_id'], self::now()]);
            if ($album && $result) {
                $this->memberDelivery()->synchronize($result);
                $result = $this->publication((int)$publication['id']);
            }
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
    public function pendingPublications(string $projectKey, string $platform, string $botKey, int $limit = 5, bool $expireLeases = true): array
    {
        $limit = max(1, min(25, $limit));
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::LEASE_SECONDS);
        $manualPattern = '%"repair_kind":"' . self::MANUAL_TELEGRAM_REPAIR . '"%';
        if($expireLeases)$this->execute("UPDATE post_versions SET state=CASE WHEN write_attempts>0 THEN 'unknown' WHEN receipt_json LIKE ? THEN 'requires_attention' ELSE 'pending' END,claim_token=NULL,claimed_at=NULL,error_reason=CASE WHEN write_attempts=0 AND receipt_json LIKE ? THEN 'manual_approval_required' ELSE 'claim_expired' END,updated_at=? WHERE publication_id IN (SELECT pp.id FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id WHERE p.project_key=? AND pc.platform=? AND pc.bot_key=?) AND state IN ('claimed','write_started') AND claimed_at<?", [$manualPattern, $manualPattern, self::now(), $projectKey, $platform, $botKey, $cutoff]);
        $query = $this->pdo->prepare("SELECT pp.id FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN projects p ON p.id=pc.project_id JOIN conversation_sources s ON s.id=pp.source_id AND s.id=pc.source_id AND s.project_id=pc.project_id JOIN post_versions v ON v.id=pp.current_version_id WHERE p.project_key=? AND pc.platform=? AND pc.bot_key=? AND pc.is_enabled=1 AND p.is_active=1 AND s.is_active=1 AND s.channel=pc.platform AND s.handling_mode IN ('manager','ask') AND v.state IN ('pending','unknown') AND NOT EXISTS (SELECT 1 FROM post_versions fence WHERE fence.publication_id=pp.id AND fence.receipt_json LIKE ?) ORDER BY CASE WHEN v.state='pending' THEN 0 ELSE 1 END ASC,v.id ASC LIMIT " . $limit);
        $query->execute([$projectKey, $platform, $botKey, $manualPattern]);
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
            if ($publication['platform'] === 'telegram' && $publication['channel_id'] === '-1001845835645'
                && $publication['external_message_id'] === '1678' && $this->manualFenceReceipt($publicationId) !== null) return null;
            // Telegram albums have distinct per-member edit claims.
            if ($publication['platform'] === 'telegram' && str_starts_with((string)$publication['external_message_id'], 'group:')) return null;
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

    /** Explicit single-post repair only; ordinary queue admission stays unchanged. */
    public static function telegramReadFailureRecoveryEligible(array $publication): bool
    {
        $snapshot = $publication['snapshot'] ?? [];
        $media = $snapshot['attachments'] ?? [];
        $receipt = $publication['receipt'] ?? [];
        $reads = $receipt['http_results'] ?? [];
        $mid = (string)($publication['external_message_id'] ?? '');
        if (($publication['platform'] ?? '') !== 'telegram' || ($publication['state'] ?? '') !== 'requires_attention'
            || ($publication['error_reason'] ?? '') !== 'provider_read_failed'
            || ($publication['write_attempts'] ?? -1) !== 0 || ($publication['attempt_count'] ?? -1) !== 1
            || ($publication['claim_token'] ?? null) !== null || ($publication['claimed_at'] ?? null) !== null
            || ($publication['content_hash'] ?? '') !== ChannelPostIncomingAdapter::contentFingerprint($snapshot)
            || isset($receipt['recovery_kind']) || !preg_match('/\A[1-9][0-9]{0,19}\z/D', $mid)
            || ($snapshot['metadata']['capability'] ?? '') !== 'supported'
            || ($snapshot['metadata']['observation_kind'] ?? '') !== 'provider_send_response'
            || (string)($snapshot['metadata']['actual_message_id'] ?? '') !== $mid
            || !empty($snapshot['metadata']['media_group_id']) || !empty($snapshot['keyboard'])
            || !is_array($media) || count($media) !== 1 || ($media[0]['type'] ?? '') !== 'image'
            || ($media[0]['provider'] ?? '') !== 'telegram' || empty($media[0]['file_unique_id'])
            || ($receipt['write_attempts'] ?? -1) !== 0 || !in_array($receipt['request_count'] ?? 0, [1, 2], true)
            || !is_array($reads) || !array_is_list($reads) || count($reads) < 1
            || count($reads) > $receipt['request_count']) return false;
        $allowed = array_slice(['bot_identity', 'channel_rights'], 0, $receipt['request_count']);
        $previous = -1;
        foreach ($reads as $read) {
            if (!is_array($read)) return false;
            $index = array_search($read['operation'] ?? '', $allowed, true);
            if ($index === false || $index <= $previous) return false;
            $previous = $index;
        }
        // Historical sanitization could omit successful reads, never the failed
        // transport result. Require that exact final pre-write network failure.
        $last = $reads[count($reads) - 1];
        if (($last['operation'] ?? '') !== $allowed[count($allowed) - 1]
            || ($last['http'] ?? -1) !== 0 || ($last['transport_error'] ?? 0) !== 1) return false;
        $raw = $snapshot['raw_body'] ?? [];
        $observed = is_array($raw) ? ChannelPostIncomingAdapter::telegramSnapshot($raw) : null;
        return $observed !== null && (string)($raw['message_id'] ?? '') === $mid
            && (string)($raw['chat']['id'] ?? '') === (string)($publication['channel_id'] ?? '')
            && ($raw['chat']['type'] ?? '') === 'channel'
            && ChannelPostIncomingAdapter::contentFingerprint($snapshot) === ChannelPostIncomingAdapter::contentFingerprint($observed);
    }

    /** Full immutable preview binding, including original failure and configuration. */
    public static function telegramReadFailureRecoveryDigest(array $publication): string
    {
        return hash('sha256', self::json($publication));
    }

    /**
     * Authorized caller supplies a fresh read-only capability proof. Claim the
     * exact failed version directly, preserving its failure before any edit.
     * No pending reset, new version, token, snapshot or global drain is allowed.
     */
    public function claimTelegramReadFailure(array $expected, array $proof, bool $apply = false): array
    {
        $check = function () use ($expected, $proof, $apply): array {
            $channel = $this->channel((string)($expected['project_key'] ?? ''), 'telegram',
                (string)($expected['bot_key'] ?? ''), (string)($expected['channel_id'] ?? ''), true, $apply);
            if (!$channel || $channel !== ($expected['channel'] ?? null)) throw new RuntimeException('channel_configuration_changed');
            $id = (int)($expected['publication_id'] ?? 0);
            $versionId = (int)($expected['version_id'] ?? 0);
            if ($apply) $this->fetch('SELECT id FROM post_publications WHERE id=?' . $this->lock(), [$id]);
            $version = $this->fetch('SELECT * FROM post_versions WHERE id=? AND publication_id=?' . ($apply ? $this->lock() : ''), [$versionId, $id]);
            $current = $this->publication($id);
            if (!$version || !$current || $current !== $expected) throw new RuntimeException('target_publication_changed');
            if (!self::telegramReadFailureRecoveryEligible($current)) throw new RuntimeException('read_failure_recovery_not_eligible');
            $receipt = $proof['receipt'] ?? [];
            $reads = $receipt['http_results'] ?? [];
            if (($proof['state'] ?? '') !== 'ready' || ($proof['reason'] ?? '') !== 'capability_verified'
                || ($receipt['write_attempts'] ?? -1) !== 0 || ($receipt['request_count'] ?? -1) !== 3
                || !is_array($reads) || array_column($reads, 'operation') !== ['bot_identity', 'channel_rights', 'channel_type'])
                throw new RuntimeException('fresh_read_only_capability_required');
            foreach ($reads as $read) if (($read['http'] ?? 0) !== 200 || !empty($read['transport_error']))
                throw new RuntimeException('fresh_read_only_capability_required');
            if (!$apply) return $current;
            foreach ($receipt['http_results'] as &$read) $read['transport_error'] = 0;
            unset($read);
            $history = [
                'recovery_kind' => 'telegram_zero_write_read_failure',
                'previous_failure' => [
                    'state' => (string)$version['state'], 'reason' => (string)$version['error_reason'],
                    'attempt_count' => (int)$version['attempt_count'], 'write_attempts' => (int)$version['write_attempts'],
                    'receipt_json' => (string)$version['receipt_json'], 'updated_at' => (string)$version['updated_at'],
                ],
                'recovery_preflight' => self::receipt($receipt),
            ];
            $claim = bin2hex(random_bytes(16));
            $now = self::now();
            $saved = $this->execute("UPDATE post_versions SET state='claimed',claim_token=?,claimed_at=?,attempt_count=attempt_count+1,receipt_json=?,updated_at=? WHERE id=? AND publication_id=? AND state='requires_attention' AND error_reason='provider_read_failed' AND write_attempts=0 AND claim_token IS NULL AND claimed_at IS NULL",
                [$claim, $now, self::json($history), $now, $versionId, $id]);
            if ($saved !== 1) throw new RuntimeException('target_publication_changed');
            return $this->publication($id) ?? throw new RuntimeException('target_publication_changed');
        };
        return $apply ? $this->transaction($check) : $check();
    }

    /** Only registry-created recovery history survives subsequent canonical outcomes. */
    private static function retainTelegramRecoveryHistory(array $stored, array $receipt): array
    {
        if (($stored['recovery_kind'] ?? '') === 'telegram_zero_write_read_failure') {
            foreach (['recovery_kind', 'previous_failure', 'recovery_preflight'] as $key)
                if (array_key_exists($key, $stored)) $receipt[$key] = $stored[$key];
        }
        return $receipt;
    }

    public function markExternalWriteStarted(int $publicationId, int $versionId, string $claimToken): bool
    {
        // This read occurs before the owned transaction, so its first consistent
        // read follows the locking channel/publication reads below on MySQL.
        $selected = $this->publication($publicationId);
        return $this->transaction(function () use ($publicationId, $versionId, $claimToken, $selected): bool {
            if (!empty($selected['manual_button_repair'])) {
                $lockedChannel = $this->channel('anytour', 'telegram', 'anyru_bot', '-1001845835645', true, true);
                if (!$lockedChannel) return false;
                try {
                    if (self::manualChannelBinding($lockedChannel) !== self::manualChannelBinding($selected['channel'])) return false;
                } catch (Throwable $ignored) { return false; }
            }
            // Serialize the final write decision with album-member registration.
            $this->fetch('SELECT id FROM post_publications WHERE id=?' . $this->lock(), [$publicationId]);
            $publication = $this->publication($publicationId);
            if (!$publication || $publication['version_id'] !== $versionId) return false;
            if (!empty($publication['manual_button_repair'])) {
                try {
                    if ((int)$publication['attempt_count'] !== 1 || (int)$publication['write_attempts'] !== 0
                        || !hash_equals(self::manualTelegramButtonDigest($publication), (string)($publication['receipt']['plan_digest'] ?? ''))) return false;
                    $q = $this->pdo->prepare('SELECT COUNT(*) FROM post_versions WHERE publication_id=?');
                    $q->execute([$publicationId]);
                    if ((int)$q->fetchColumn() !== 1) return false;
                } catch (Throwable $ignored) { return false; }
            }
            return $this->execute("UPDATE post_versions SET state='write_started',write_attempts=write_attempts+1,updated_at=? WHERE id=? AND publication_id=? AND claim_token=? AND state='claimed' AND write_attempts=0", [self::now(), $versionId, $publicationId, $claimToken]) === 1;
        });
    }

    public function finishPublication(int $publicationId, int $versionId, string $claimToken, string $state, string $reason = '', array $receipt = []): bool
    {
        if (!in_array($state, ['sent', 'unknown', 'requires_attention', 'pending'], true)) throw new InvalidArgumentException('invalid_operation_state');
        $reason = preg_match('/\A[a-zA-Z0-9_-]{0,96}\z/D', $reason) ? $reason : 'operation_failed';
        $receipt = self::receipt($receipt);
        return $this->transaction(function () use ($publicationId, $versionId, $claimToken, $state, $reason, $receipt): bool {
            $this->fetch('SELECT id FROM post_publications WHERE id=?' . $this->lock(), [$publicationId]);
            $written = $this->fetch('SELECT * FROM post_versions WHERE id=? AND publication_id=?', [$versionId, $publicationId]);
            $stored = json_decode((string)($written['receipt_json'] ?? ''), true) ?: [];
            $receipt = self::retainTelegramRecoveryHistory($stored, $receipt);
            $manual = self::manualPlanReceipt($stored);
            if ($manual !== []) {
                if ($state === 'pending') return false;
                $receipt = array_replace($receipt, $manual);
                unset($receipt['content_preserved']); // No native media identity existed before this edit.
                if ($state === 'sent' && ((int)($written['write_attempts'] ?? 0) !== 1
                    || ($receipt['verified'] ?? null) !== true || ($receipt['button_verified'] ?? null) !== true
                    || ($receipt['body_unchanged_by_method'] ?? null) !== true || ($receipt['public_caption_matched'] ?? null) !== true
                    || ($receipt['publication_date_matched'] ?? null) !== true || ($receipt['media_type_duration_matched'] ?? null) !== true
                    || ($receipt['native_media_identity_before_verified'] ?? null) !== false
                    || empty($receipt['native_video_unique_id']) || empty($receipt['provider_result_sha256']))) return false;
            }
            $extra = $state === 'pending' ? " AND write_attempts=0 AND state='claimed'" : " AND state IN ('claimed','write_started')";
            $saved = $this->execute('UPDATE post_versions SET state=?,claim_token=NULL,claimed_at=NULL,error_reason=?,receipt_json=?,updated_at=? WHERE id=? AND publication_id=? AND claim_token=?' . $extra, [$state, $reason, self::json($receipt), self::now(), $versionId, $publicationId, $claimToken]) === 1;
            if (!$saved || !$written || (int)$written['write_attempts'] === 0 || $state === 'pending') return $saved;
            // A later photo may arrive while the sole anchor edit is in flight.
            // Only a successor with the identical authenticated anchor observation
            // inherits this outcome; a new anchor edit cannot be overwritten here.
            $current = $this->publication($publicationId);
            $before = json_decode((string)$written['snapshot_json'], true) ?: [];
            if ($current && $current['platform'] === 'telegram' && (int)$current['version_id'] !== $versionId
                && in_array($current['state'], ['unknown', 'claimed'], true) && (int)$current['write_attempts'] > 0
                && ChannelPostTelegramAlbum::sameTargetObservation($before, $current['snapshot'])) {
                $this->execute("UPDATE post_versions SET state=?,claim_token=NULL,claimed_at=NULL,error_reason=?,receipt_json=?,updated_at=? WHERE id=? AND publication_id=? AND state IN ('unknown','claimed') AND write_attempts>0", [$state, $reason, self::json($receipt), self::now(), (int)$current['version_id'], $publicationId]);
            }
            return true;
        });
    }

    /** Caller must first verify the bot-owned URL in an authenticated channel edit. */
    public function confirmObservedButton(int $publicationId, int $versionId, array $receipt = []): bool
    {
        $publication = $this->publication($publicationId);
        if (!$publication || $publication['version_id'] !== $versionId) return false;
        if ($publication['platform'] === 'telegram' && $publication['channel_id'] === '-1001845835645'
            && $publication['external_message_id'] === '1678' && $this->manualFenceReceipt($publicationId) !== null) return false;
        return $this->execute("UPDATE post_versions SET state='sent',claim_token=NULL,claimed_at=NULL,error_reason='',receipt_json=?,updated_at=? WHERE id=? AND publication_id=? AND state IN ('pending','claimed','write_started','unknown')", [self::json(self::retainTelegramRecoveryHistory((array)$publication['receipt'], self::receipt($receipt))), self::now(), $versionId, $publicationId]) === 1;
    }

    /**
     * Metadata-only confirmation of an already successful authorized Telegram
     * caption edit. The authorized caller must authenticate the original durable
     * receipt before supplying it; metadata alone is never provider evidence.
     * This method never claims work, creates a version, or calls a provider.
     */
    public function confirmTelegramEditReadFailure(array $expected, array $evidence, bool $apply = false): array
    {
        $check = function () use ($expected, $evidence, $apply): array {
            $channel = $this->channel((string)($expected['project_key'] ?? ''), 'telegram',
                (string)($expected['bot_key'] ?? ''), (string)($expected['channel_id'] ?? ''), true, $apply);
            if (!$channel || $channel !== ($expected['channel'] ?? null)) throw new RuntimeException('channel_configuration_changed');
            $id = (int)($expected['publication_id'] ?? 0); $vid = (int)($expected['version_id'] ?? 0);
            if ($apply) $this->fetch('SELECT id FROM post_publications WHERE id=?' . $this->lock(), [$id]);
            $version = $this->fetch('SELECT * FROM post_versions WHERE id=? AND publication_id=?' . ($apply ? $this->lock() : ''), [$vid, $id]);
            $current = $this->publication($id);
            if (!$version || !$current || $current !== $expected) throw new RuntimeException('target_publication_changed');
            self::validateTelegramEditConfirmation($current, $evidence);
            $prior = $this->fetch('SELECT version_number,snapshot_json,state,claim_token,claimed_at FROM post_versions WHERE id=? AND publication_id=?' . ($apply ? $this->lock() : ''), [$evidence['plan']['old_version_id'], $id]);
            $priorSnapshot = json_decode((string)($prior['snapshot_json'] ?? ''), true) ?: [];
            if (!$prior || $prior['state'] !== 'sent' || $prior['claim_token'] !== null || $prior['claimed_at'] !== null
                || (int)$prior['version_number'] + 1 !== $current['version_number']
                || ($priorSnapshot['attachments'] ?? null) !== $current['snapshot']['attachments']
                || ($priorSnapshot['post_url'] ?? null) !== $current['snapshot']['post_url']) throw new RuntimeException('original_edit_observation_changed');
            $event = $current['snapshot']['metadata']['source_event_id'];
            $observation = $this->fetch('SELECT version_id FROM post_observations WHERE publication_id=? AND source_event_key=?' . ($apply ? $this->lock() : ''), [$id, hash('sha256', $event)]);
            if (!$observation || (int)$observation['version_id'] !== $vid) throw new RuntimeException('original_edit_observation_changed');
            if (!$apply) return $current;
            $receipt = [
                'verified'=>true, 'reconciled'=>true, 'content_preserved'=>true, 'button_verified'=>true,
                'confirmation_kind'=>'telegram_original_edit_after_read_failure',
                'write_attempts'=>0, 'request_count'=>0,
                'original_receipt_sha256'=>$evidence['original_receipt_sha256'],
                'original_response_sha256'=>hash('sha256', self::json($evidence['provider_response'])),
                'source_event_id'=>$event,
                'previous_failure'=>[
                    'state'=>(string)$version['state'], 'reason'=>(string)$version['error_reason'],
                    'attempt_count'=>(int)$version['attempt_count'], 'write_attempts'=>(int)$version['write_attempts'],
                    'receipt_json'=>(string)$version['receipt_json'], 'updated_at'=>(string)$version['updated_at'],
                ],
            ];
            $saved = $this->execute("UPDATE post_versions SET state='sent',error_reason='',receipt_json=?,updated_at=? WHERE id=? AND publication_id=? AND state='requires_attention' AND error_reason='provider_read_failed' AND attempt_count=1 AND write_attempts=0 AND claim_token IS NULL AND claimed_at IS NULL", [self::json($receipt), self::now(), $vid, $id]);
            if ($saved !== 1) throw new RuntimeException('target_publication_changed');
            return $this->publication($id) ?? throw new RuntimeException('target_publication_changed');
        };
        return $apply ? $this->transaction($check) : $check();
    }

    private static function validateTelegramEditConfirmation(array $p, array $e): void
    {
        $s=$p['snapshot']; $meta=$s['metadata']??[]; $r=$p['receipt'];
        $response=$e['provider_response']??[]; $plan=$e['plan']??[];
        $mid=(string)$p['external_message_id']; $op=$e['operation']??'';
        $fail=static function ():void { throw new RuntimeException('original_edit_confirmation_not_eligible'); };
        if ($p['platform']!=='telegram' || $p['state']!=='requires_attention' || $p['error_reason']!=='provider_read_failed'
            || $p['attempt_count']!==1 || $p['write_attempts']!==0 || $p['claim_token']!==null || $p['claimed_at']!==null
            || isset($r['recovery_kind']) || isset($r['confirmation_kind'])
            || !preg_match('/\A[1-9][0-9]{0,19}\z/D',$mid)
            || ($meta['observation_kind']??'')!=='authorized_admin_content_edit' || ($meta['capability']??'')!=='supported'
            || !empty($meta['media_group_id']) || (string)($meta['actual_message_id']??'')!==$mid
            || !is_string($op) || !preg_match('/\A[a-zA-Z0-9_-]{1,191}\z/D',$op)
            || ($meta['source_event_id']??'')!==$op.':telegram:'.$mid
            || !preg_match('/\A[a-f0-9]{64}\z/D',(string)($e['original_receipt_sha256']??''))
            || ($response['http']??null)!==200 || !empty($response['transport_error'])
            || ($response['data']['ok']??null)!==true || !is_array($response['data']['result']??null)) $fail();
        // Preserve the exact failed pre-write GET-prefix evidence, not UNKNOWN writes.
        $reads=$r['http_results']??[]; $count=$r['request_count']??0;
        if (($r['write_attempts']??-1)!==0 || !in_array($count,[1,2],true) || !is_array($reads)
            || !array_is_list($reads) || count($reads)<1 || count($reads)>$count) $fail();
        $allowed=array_slice(['bot_identity','channel_rights'],0,$count); $previous=-1;
        foreach($reads as $read){
            if(!is_array($read))$fail();
            $index=array_search($read['operation']??'',$allowed,true);
            if($index===false||$index<=$previous)$fail(); $previous=$index;
        }
        $last=$reads[count($reads)-1];
        if(($last['operation']??'')!==$allowed[$count-1]||($last['http']??-1)!==0||($last['transport_error']??0)!==1)$fail();
        $raw=$response['data']['result']; $observed=ChannelPostIncomingAdapter::telegramSnapshot($raw);
        if(!$observed || $raw!==($s['raw_body']??null) || (string)($raw['message_id']??'')!==$mid
            || (string)($raw['chat']['id']??'')!==$p['channel_id'] || ($raw['chat']['type']??'')!=='channel'
            || ($observed['metadata']['capability']??'')!=='supported')$fail();
        foreach(['text','attachments','markup','keyboard','post_url'] as $key)if(($s[$key]??null)!==($observed[$key]??null))$fail();
        $media=$observed['attachments'];
        if(count($media)!==1||($media[0]['type']??'')!=='image'||($media[0]['provider']??'')!=='telegram'||empty($media[0]['file_unique_id'])
            || $p['content_hash']!==ChannelPostIncomingAdapter::contentFingerprint($observed))$fail();
        $url=rtrim((string)$p['channel']['bot_url'],'/').'?start='.rawurlencode($p['start_payload']);
        // Exact markup rejects hidden callback fields as well as extra buttons.
        if($observed['keyboard']!==[[['text'=>$p['channel']['button_text'],'url'=>$url]]]
            || ($plan['platform']??'')!=='telegram' || ($plan['publication_id']??null)!==$p['publication_id']
            || (string)($plan['message_id']??'')!==$mid || ($plan['token']??null)!==$p['start_payload']
            || ($plan['text']??null)!==$s['text'] || ($plan['post_url']??null)!==$s['post_url']
            || ($plan['photo_signature']??null)!=='telegram:'.$media[0]['file_unique_id']
            || ($plan['update_text']??null)!==true || !is_int($plan['old_version_id']??null)
            || $plan['old_version_id']<=0 || $plan['old_version_id']>=$p['version_id'])$fail();
        if(isset($plan['monitor_price'])){
            if(($plan['monitor_price']['record_id']??null)!==($meta['monitor_price_record_id']??null)
                || !preg_match('/\A[a-f0-9]{64}\z/D',(string)($meta['monitor_price_record_id']??''))
                || ($plan['price_link']['url']??null)!==$url
                || !is_array($plan['price_link']['telegram_entities']??null)
                || self::canonicalArray($plan['price_link']['telegram_entities'])!==self::canonicalArray($observed['markup']))$fail();
        } elseif(isset($meta['monitor_price_record_id'])) $fail();
    }

    /** This opt-in confirmation cannot make a rejected job claimable again. */
    public static function maxReadFailureConfirmationEligible(array $publication): bool
    {
        $media = $publication['snapshot']['attachments'] ?? [];
        $receipt = $publication['receipt'] ?? [];
        $reads = $receipt['http_results'] ?? [];
        if (($publication['platform'] ?? '') !== 'max' || ($publication['state'] ?? '') !== 'requires_attention'
            || ($publication['error_reason'] ?? '') !== 'provider_read_failed' || ($publication['write_attempts'] ?? -1) !== 0
            || ($publication['claim_token'] ?? null) !== null || ($publication['claimed_at'] ?? null) !== null
            || ($publication['snapshot']['metadata']['capability'] ?? '') !== 'supported'
            || !is_array($media) || count($media) !== 1 || ($media[0]['type'] ?? '') !== 'image'
            || ($media[0]['provider'] ?? '') !== 'max' || !preg_match('/\A[0-9]+\z/D', (string)($media[0]['photo_id'] ?? ''))
            || ($receipt['write_attempts'] ?? -1) !== 0 || !is_array($reads) || !array_is_list($reads)
            || !in_array($receipt['request_count'] ?? 0, [1, 2, 3], true) || count($reads) > $receipt['request_count']) return false;
        // Older stored receipts may omit successful HTTP results; retained
        // entries must still be an ordered subset of the pre-write GET prefix.
        $allowed = array_slice(['bot_identity', 'channel_rights', 'current_post'], 0, $receipt['request_count']);
        $previous = -1;
        foreach ($reads as $read) {
            $index = array_search($read['operation'] ?? '', $allowed, true);
            if ($index === false || $index <= $previous) return false;
            $previous = $index;
        }
        return true;
    }

    /**
     * Called only after an authenticated GET-only confirmation by the existing
     * reconciliation service. Compare the complete pre-read publication and
     * configuration under locks; retain the original failed result verbatim.
     * No queue, claim, content version, token or provider write is created.
     */
    public function confirmMaxReadFailure(array $expected, array $observed, array $proof, bool $apply = false): array
    {
        $check = function () use ($expected, $observed, $proof, $apply): array {
            $channel = $this->channel((string)($expected['project_key'] ?? ''), 'max', (string)($expected['bot_key'] ?? ''), (string)($expected['channel_id'] ?? ''), true, $apply);
            if (!$channel || $channel !== ($expected['channel'] ?? null)) throw new RuntimeException('channel_configuration_changed');
            $id = (int)($expected['publication_id'] ?? 0);
            $versionId = (int)($expected['version_id'] ?? 0);
            if ($apply) $this->fetch('SELECT id FROM post_publications WHERE id=?' . $this->lock(), [$id]);
            $version = $this->fetch('SELECT * FROM post_versions WHERE id=? AND publication_id=?' . ($apply ? $this->lock() : ''), [$versionId, $id]);
            $current = $this->publication($id);
            if (!$version || !$current || $current !== $expected) throw new RuntimeException('target_publication_changed');
            if (!self::maxReadFailureConfirmationEligible($current)) throw new RuntimeException('read_failure_confirmation_not_eligible');
            $receipt = $proof['receipt'] ?? [];
            $reads = $receipt['http_results'] ?? [];
            if (($proof['state'] ?? '') !== 'sent' || ($proof['reason'] ?? '') !== 'already_present'
                || ($receipt['write_attempts'] ?? -1) !== 0 || ($receipt['request_count'] ?? -1) !== 3
                || !is_array($reads) || count($reads) !== 3
                || array_column($reads, 'operation') !== ['bot_identity', 'channel_rights', 'current_post']) throw new RuntimeException('read_only_button_confirmation_required');
            foreach ($reads as $read) if (($read['http'] ?? 0) !== 200 || !empty($read['transport_error'])) throw new RuntimeException('read_only_button_confirmation_required');
            $snapshot = $current['snapshot'];
            $url = rtrim((string)$channel['bot_url'], '/') . '?start=' . rawurlencode((string)$current['start_payload']);
            if (($observed['raw_body']['body']['mid'] ?? '') !== $current['external_message_id']
                || (string)($observed['raw_body']['recipient']['chat_id'] ?? '') !== $current['channel_id']
                || ($observed['raw_body']['recipient']['chat_type'] ?? '') !== 'channel'
                || ($observed['metadata']['capability'] ?? '') !== 'supported'
                || !hash_equals(ChannelPostIncomingAdapter::contentFingerprint($snapshot), ChannelPostIncomingAdapter::contentFingerprint($observed))
                || ($snapshot['text'] ?? null) !== ($observed['text'] ?? null)
                || ($snapshot['post_url'] ?? null) !== ($observed['post_url'] ?? null)
                || !ChannelPostIncomingAdapter::containsOnlyButton($observed, $url, (string)$channel['button_text'])) throw new RuntimeException('observed_post_does_not_match');
            if (!$apply) return $current;
            foreach ($receipt['http_results'] as &$read) $read['transport_error'] = 0;
            unset($read);
            $confirmed = self::receipt($receipt);
            $confirmed['verified'] = true;
            $confirmed['reconciled'] = true;
            $confirmed['content_preserved'] = true;
            $confirmed['button_verified'] = true;
            $confirmed['confirmation_kind'] = 'max_observed_after_read_failure';
            $confirmed['previous_failure'] = [
                'state' => (string)$version['state'], 'reason' => (string)$version['error_reason'],
                'attempt_count' => (int)$version['attempt_count'], 'write_attempts' => (int)$version['write_attempts'],
                'receipt_json' => (string)$version['receipt_json'], 'updated_at' => (string)$version['updated_at'],
            ];
            $saved = $this->execute("UPDATE post_versions SET state='sent',error_reason='',receipt_json=?,updated_at=? WHERE id=? AND publication_id=? AND state='requires_attention' AND error_reason='provider_read_failed' AND write_attempts=0 AND claim_token IS NULL AND claimed_at IS NULL", [self::json($confirmed), self::now(), $versionId, $id]);
            if ($saved !== 1) throw new RuntimeException('target_publication_changed');
            return $this->publication($id) ?? throw new RuntimeException('target_publication_changed');
        };
        return $apply ? $this->transaction($check) : $check();
    }


    /** Entry and event pointer commit together; existing dialogue ownership is untouched. */
    public function recordEntry(int $conversationId, array $resolved, string $eventKey = '', bool $joinTransaction = false, ?array $preservedEntry = null): ?array
    {
        if ($conversationId <= 0) return null;
        $publicationId = (int)($resolved['publication_id'] ?? 0);
        $versionId = (int)($resolved['version_id'] ?? 0);
        if ($publicationId <= 0 || $versionId <= 0) return null;
        return $this->transaction(function () use ($conversationId, $resolved, $eventKey, $publicationId, $versionId, $preservedEntry): ?array {
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
            $payload=['entry_id' => $entryId, 'publication_id' => $publicationId, 'version_id' => $versionId, 'source_key' => $publication['source_key']];
            $preserved=self::preservedEntry($preservedEntry,$conversation,$publication);
            if($preserved!==null){
                $payload['context_preserved']=['version'=>1,'origin'=>'max_start_source_policy',
                    'reason'=>'existing_handoff_without_reset','conversation_id'=>$preserved['conversation_id'],
                    'start_event_id'=>$preserved['start_event_id']];
                $payload=ConversationRecordClock::stamp($payload);
            }
            $this->execute('INSERT INTO conversation_events (conversation_id,event_type,actor_type,payload_json,created_at) VALUES (?,?,?,?,?)', [$conversationId, 'channel_post_entry', 'customer', self::json($payload), $now]);
            return ['entry_id' => $entryId, 'version_id' => $versionId, 'duplicate' => false];
        }, $joinTransaction);
    }

    /** A receipt annotates only a new entry in the same AnyTour MAX subject and source. */
    private static function preservedEntry(?array $receipt,array $conversation,array $publication): ?array
    {
        if(($conversation['project_key']??null)!=='anytour'||($conversation['channel']??null)!=='max'
            ||($publication['project_key']??null)!=='anytour'||($publication['platform']??null)!=='max'
            ||$receipt===null||count($receipt)!==4
            ||array_diff(array_keys($receipt),['version','conversation_id','start_event_id','source_key'])!==[]
            ||($receipt['version']??null)!==1||!is_string($receipt['conversation_id']??null)
            ||preg_match('/\A[1-9][0-9]{0,19}\z/D',$receipt['conversation_id'])!==1
            ||$receipt['conversation_id']!==(string)($conversation['id']??'')
            ||!is_string($receipt['start_event_id']??null)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$receipt['start_event_id'])!==1
            ||!is_string($receipt['source_key']??null)||preg_match('/\A[A-Za-z0-9_.:\/-]{1,191}\z/D',$receipt['source_key'])!==1
            ||$receipt['source_key']!==($publication['source_key']??null))return null;
        return $receipt;
    }

    /** Do not leave an earlier post looking current after an unknown p_ entry. */
    public function recordUnavailableEntry(int $conversationId, string $projectKey, string $platform, string $eventKey = '', bool $joinTransaction = false): ?array
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
        }, $joinTransaction);
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
        if (($row['receipt']['repair_kind'] ?? null) === self::MANUAL_TELEGRAM_REPAIR) {
            $row['manual_button_repair'] = true;
            $row['publisher'] = ['username'=>$row['receipt']['publisher_username'] ?? '',
                'bot_id'=>$row['receipt']['publisher_bot_id'] ?? '', 'credential_version'=>$row['receipt']['credential_version'] ?? 0];
            $row['public_fingerprint'] = $row['receipt']['public_baseline_sha256'] ?? '';
            $row['plan_digest'] = $row['receipt']['plan_digest'] ?? '';
        }
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
        if (($receipt['repair_kind'] ?? null) === self::MANUAL_TELEGRAM_REPAIR) $safe['repair_kind'] = self::MANUAL_TELEGRAM_REPAIR;
        if (($receipt['publisher_username'] ?? null) === 'ANYTOURBOT_bot') $safe['publisher_username'] = 'ANYTOURBOT_bot';
        if (is_string($receipt['publisher_bot_id'] ?? null) && preg_match('/\A[1-9][0-9]{0,19}\z/D', $receipt['publisher_bot_id'])) $safe['publisher_bot_id'] = $receipt['publisher_bot_id'];
        foreach (['credential_version','channel_config_version'] as $key) {
            if (is_int($receipt[$key] ?? null) && $receipt[$key] > 0) $safe[$key] = $receipt[$key];
        }
        foreach (['plan_digest','public_baseline_sha256','provider_result_sha256'] as $key) {
            if (is_string($receipt[$key] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $receipt[$key])) $safe[$key] = $receipt[$key];
        }
        foreach (['body_unchanged_by_method','public_caption_matched','publication_date_matched','media_type_duration_matched'] as $key) {
            if (is_bool($receipt[$key] ?? null)) $safe[$key] = $receipt[$key];
        }
        if (($receipt['native_media_identity_before_verified'] ?? null) === false) $safe['native_media_identity_before_verified'] = false;
        if (is_string($receipt['native_video_unique_id'] ?? null) && preg_match('/\A[A-Za-z0-9_-]{1,191}\z/D', $receipt['native_video_unique_id'])) $safe['native_video_unique_id'] = $receipt['native_video_unique_id'];
        if (is_int($receipt['telegram_error_code'] ?? null) && $receipt['telegram_error_code'] > 0 && $receipt['telegram_error_code'] <= 599) $safe['telegram_error_code'] = $receipt['telegram_error_code'];
        if (is_string($receipt['telegram_error_reason'] ?? null) && preg_match('/\A[a-zA-Z0-9_]{1,96}\z/D', $receipt['telegram_error_reason'])) $safe['telegram_error_reason'] = $receipt['telegram_error_reason'];
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

    private function transaction(callable $operation, bool $joinTransaction = false)
    {
        if ($this->pdo->inTransaction()) {
            if ($joinTransaction) return $operation();
            throw new LogicException('channel_registry_requires_own_transaction');
        }
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

