<?php
declare(strict_types=1);
require_once __DIR__ . '/ChannelPostRegistryService.php';
require_once __DIR__ . '/ChannelPostButtonTransport.php';
require_once __DIR__ . '/../integrations/ChannelPostIncomingAdapter.php';

/** Durable receive first; one bounded channel operation runs after webhook ACK. */
final class ChannelPostAutomationService
{
    private $registry;
    private ChannelPostButtonTransport $transport;

    public function __construct($registry = null, ?ChannelPostButtonTransport $transport = null)
    {
        $this->registry = $registry;
        $this->transport = $transport ?? new ChannelPostButtonTransport();
    }

    /** Safe return values only. Original bodies are held by the private registry. */
    public function receive(string $platform, array $update, string $projectKey, string $botKey): array
    {
        if (!ChannelPostIncomingAdapter::isChannelUpdate($platform, $update)) return ['recognized' => false, 'queued' => false, 'state' => 'not_channel'];
        $safe = ['recognized' => true, 'queued' => false, 'state' => 'ignored'];
        try {
            $event = ChannelPostIncomingAdapter::fromUpdate($platform, $update);
            if ($event === null) return $safe + ['reason' => 'unsupported_or_invalid_channel_update'];
            $registry = $this->registry();
            $channel = $registry->channel($projectKey, $platform, $botKey, $event['channel_id']);
            if (!$channel) return $safe + ['reason' => 'channel_not_managed'];
            $snapshot = $event['snapshot'];
            $snapshot['content_hash'] = ChannelPostIncomingAdapter::contentFingerprint($snapshot, '', (string)$channel['bot_url']);
            // Keep authenticated markup for every album member, including any
            // misplaced own link. Fingerprints already exclude replaceable keys.
            if (!empty($snapshot['metadata']['media_group_id'])) $snapshot['media_group_id'] = $snapshot['metadata']['media_group_id'];
            $publication = $registry->registerPublication($projectKey, $platform, $botKey, $event['channel_id'], $event['external_message_id'], $snapshot);
            if (!$publication) return $safe + ['reason' => 'channel_configuration_changed'];
            $publicationId = (int)$publication['publication_id']; $versionId = (int)$publication['version_id'];
            if (!empty($publication['duplicate_event']) || !empty($publication['stale_observation'])) {
                // A retried acknowledgement can safely drain an already durable
                // pending job without trusting the older envelope as new evidence.
                return ['recognized' => true, 'queued' => in_array($publication['state'] ?? '', ['pending', 'unknown'], true), 'state' => 'ignored', 'reason' => !empty($publication['stale_observation']) ? 'stale_channel_observation' : 'duplicate_channel_observation', 'publication_id' => $publicationId, 'version_id' => $versionId];
            }
            if ($platform === 'telegram' && str_starts_with($event['external_message_id'], 'group:')) {
                // Member observations reconcile only their own durable decision.
                // An anchor's successful edit never confirms another photo.
                return ['recognized'=>true, 'queued'=>in_array($publication['state'], ['pending','unknown'], true),
                    'state'=>(string)$publication['state'], 'publication_id'=>$publicationId, 'version_id'=>$versionId];
            }
            $url = ChannelPostButtonTransport::buttonUrl($publication);
            $label = (string)$publication['channel']['button_text'];
            $original = $event['snapshot'];
            $telegramAlbum = $platform === 'telegram' && str_starts_with($event['external_message_id'], 'group:');
            $observedTarget = $telegramAlbum ? ChannelPostTelegramAlbum::target((array)$publication['snapshot']) : $snapshot;
            $targetEvent = !$telegramAlbum || ($original['metadata']['actual_message_id'] ?? null) === ($observedTarget['metadata']['actual_message_id'] ?? null);
            if ($targetEvent && ($original['metadata']['capability'] ?? '') === 'supported'
                && ChannelPostIncomingAdapter::containsOnlyButton($original, $url, $label)
                && ($telegramAlbum || ChannelPostIncomingAdapter::contentFingerprint((array)$publication['snapshot']) === $snapshot['content_hash'])
                && ChannelPostIncomingAdapter::contentFingerprint($original, $url, (string)$channel['bot_url']) === ChannelPostIncomingAdapter::contentFingerprint($observedTarget, $url, (string)$channel['bot_url'])) {
                $registry->confirmObservedButton($publicationId, $versionId, ['event_id' => $event['source_event_id'], 'verified' => true, 'reconciled' => ($publication['state'] ?? '') === 'unknown']);
                return ['recognized' => true, 'queued' => false, 'state' => 'sent', 'publication_id' => $publicationId, 'version_id' => $versionId];
            }
            if (($original['metadata']['capability'] ?? '') !== 'supported') {
                $claim = $registry->claimPublication($publicationId, $versionId);
                if ($claim) $registry->finishPublication($publicationId, $versionId, (string)$claim['claim_token'], 'requires_attention', (string)($original['metadata']['capability_reason'] ?? 'unsupported_post'), ['event_id' => $event['source_event_id']]);
                return ['recognized' => true, 'queued' => false, 'state' => 'requires_attention', 'reason' => (string)($original['metadata']['capability_reason'] ?? 'unsupported_post'), 'publication_id' => $publicationId, 'version_id' => $versionId];
            }
            return ['recognized' => true, 'queued' => in_array($publication['state'] ?? '', ['pending', 'unknown'], true), 'state' => (string)($publication['state'] ?? 'pending'), 'publication_id' => $publicationId, 'version_id' => $versionId];
        } catch (Throwable $e) {
            // No unavailable DB/provider or malformed channel post may affect a
            // customer dialogue, leak a raw payload or trigger a default source.
            return $safe + ['reason' => 'channel_storage_unavailable'];
        }
    }

    public function processPending(string $projectKey, string $platform, string $botKey, int $limit = 1): array
    {
        $results = [];
        try {
            $registry = $this->registry();
            $pending = $registry->pendingPublications($projectKey, $platform, $botKey, max(1, min(5, $limit)));
            foreach ($pending as $publication) {
                $result = $this->processOperation($publication);
                if ($result !== null) $results[] = $result;
            }
        } catch (Throwable $e) {
            $results[] = ['state' => 'unknown', 'reason' => 'channel_worker_unavailable'];
        }
        return $results;
    }

    /** Explicit recovery selects one exact current version, never the global queue. */
    public function processPublication(string $projectKey, string $platform, string $botKey, int $publicationId, int $versionId): array
    {
        try {
            $publication = $this->registry()->publication($publicationId);
            if (!$publication || $publication['project_key'] !== $projectKey || $publication['platform'] !== $platform
                || $publication['bot_key'] !== $botKey || (int)$publication['version_id'] !== $versionId) {
                return ['state'=>'requires_attention', 'reason'=>'target_publication_changed'];
            }
            return $this->processOperation($publication) ?? ['state'=>'requires_attention', 'reason'=>'target_not_claimable'];
        } catch (Throwable $e) {
            return ['state'=>'unknown', 'reason'=>'channel_worker_unavailable'];
        }
    }

    /** One exact, explicitly selected zero-write Telegram failure; preview is read-only. */
    public function recoverTelegramReadFailure(array $identity, string $channelId, string $messageId,
        int $publicationId, int $versionId, int $configVersion, string $expectedHash,
        string $expectedDigest = '', bool $apply = false): array
    {
        $claimed = false;
        try {
            if (($identity['platform'] ?? '') !== 'telegram' || $publicationId <= 0 || $versionId <= 0 || $configVersion <= 0
                || !preg_match('/\A[a-f0-9]{64}\z/D', $expectedHash)
                || ($apply && !preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest)))
                throw new RuntimeException('exact_recovery_target_required');
            $registry = $this->registry();
            $publication = $registry->publication($publicationId);
            if (!$publication || $publication['project_key'] !== ($identity['project_key'] ?? '')
                || $publication['platform'] !== 'telegram' || $publication['bot_key'] !== ($identity['bot_key'] ?? '')
                || $publication['channel_id'] !== $channelId || $publication['external_message_id'] !== $messageId
                || (int)$publication['version_id'] !== $versionId
                || (int)$publication['channel']['config_version'] !== $configVersion
                || !hash_equals($expectedHash, ChannelPostIncomingAdapter::contentFingerprint($publication['snapshot'])))
                throw new RuntimeException('target_publication_changed');
            if (!ChannelPostRegistryService::telegramReadFailureRecoveryEligible($publication))
                throw new RuntimeException('read_failure_recovery_not_eligible');
            $digest = ChannelPostRegistryService::telegramReadFailureRecoveryDigest($publication);
            if ($apply && !hash_equals($digest, $expectedDigest)) throw new RuntimeException('recovery_preview_changed');
            $proof = $this->transport->verifyChannel($publication['channel']);
            $claim = $registry->claimTelegramReadFailure($publication, $proof, $apply);
            if (!$apply) return ['ok'=>true, 'state'=>'preview', 'publication_id'=>$publicationId,
                'version_id'=>$versionId, 'config_version'=>$configVersion, 'content_hash'=>$expectedHash,
                'recovery_digest'=>$digest, 'receipt'=>$proof['receipt']];
            $claimed = true;
            $result = $this->processClaimedOperation($claim, false, true);
            return ['ok'=>$result['state']==='sent'] + $result;
        } catch (Throwable $e) {
            $reason = preg_match('/\A[a-z_]{1,96}\z/D', $e->getMessage()) ? $e->getMessage() : 'recovery_unavailable';
            return ['ok'=>false, 'state'=>$claimed ? 'unknown' : 'requires_attention', 'reason'=>$reason];
        }
    }

    private function processOperation(array $publication): ?array
    {
        $registry = $this->registry();
        $id = (int)$publication['publication_id']; $version = (int)$publication['version_id'];
        if ($publication['platform'] === 'telegram' && str_starts_with((string)$publication['external_message_id'], 'group:')) {
            $delivery = $registry->memberDelivery();
            $member = $delivery->claim($id);
            if (!$member) return null;
            $result = $this->transport->attempt($member, static fn(): bool => $delivery->markStarted($member), false);
            $saved = $delivery->finish($member, (string)$result['state'], (string)$result['reason'], (array)$result['receipt']);
            $current = $registry->publication($id);
            return ['publication_id'=>$id, 'version_id'=>(int)($current['version_id'] ?? $version),
                'state'=>$saved ? (string)($current['state'] ?? 'unknown') : 'unknown',
                'reason'=>$saved ? (string)$result['reason'] : 'receipt_storage_failed',
                'reconcile_only'=>false, 'receipt'=>$result['receipt']];
        }
        $reconcile = ($publication['state'] ?? '') === 'unknown' || !empty($publication['write_attempted']);
        $claim = $registry->claimPublication($id, $version, $reconcile);
        if (!$claim) return null;
        // A crashed explicit repair with no write must never become an automatic
        // retry after the ordinary lease expires. Written outcomes remain UNKNOWN.
        if (!$reconcile && ($claim['receipt']['recovery_kind'] ?? '') === 'telegram_zero_write_read_failure') {
            $saved = $registry->finishPublication($id, $version, (string)$claim['claim_token'],
                'requires_attention', 'explicit_recovery_required');
            return ['publication_id'=>$id, 'version_id'=>$version, 'state'=>$saved ? 'requires_attention' : 'unknown',
                'reason'=>$saved ? 'explicit_recovery_required' : 'receipt_storage_failed', 'reconcile_only'=>false, 'receipt'=>[]];
        }
        return $this->processClaimedOperation($claim, $reconcile);
    }

    private function processClaimedOperation(array $claim, bool $reconcile, bool $explicitRecovery = false): array
    {
        $registry = $this->registry();
        $id = (int)$claim['publication_id']; $version = (int)$claim['version_id'];
        $claimToken = (string)$claim['claim_token'];
        $beforeWrite = static function () use ($registry, $claim, $id, $version, $claimToken, $explicitRecovery): bool {
            $fresh = $registry->publication($id);
            if (!$fresh || (int)$fresh['version_id'] !== $version
                || ($fresh['claim_token'] ?? '') !== $claimToken
                || ($explicitRecovery && $fresh['channel'] !== $claim['channel'])
                || self::configurationFingerprint((array)$fresh['channel']) !== self::configurationFingerprint((array)$claim['channel'])) return false;
            return $registry->markExternalWriteStarted($id, $version, $claimToken);
        };
        $result = $this->transport->attempt($claim, $beforeWrite, $reconcile);
        $saved = $registry->finishPublication($id, $version, $claimToken, (string)$result['state'], (string)$result['reason'], (array)$result['receipt']);
        return [
            'publication_id' => $id, 'version_id' => $version,
            'state' => $saved ? $result['state'] : 'unknown',
            'reason' => $saved ? $result['reason'] : 'receipt_storage_failed',
            'reconcile_only' => $reconcile, 'receipt' => $result['receipt'],
        ];
    }

    private function registry()
    {
        if ($this->registry === null) $this->registry = new ChannelPostRegistryService();
        return $this->registry;
    }

    private static function configurationFingerprint(array $channel): string
    {
        $identity = [];
        foreach (['id', 'project_id', 'project_key', 'platform', 'channel_id', 'bot_key', 'canonical_bot_username', 'source_id', 'source_key', 'bot_url', 'button_text', 'post_mode'] as $key) $identity[$key] = (string)($channel[$key] ?? ($key === 'post_mode' ? 'append_button' : ''));
        $identity['is_enabled'] = !empty($channel['is_enabled']);
        return hash('sha256', (string)json_encode($identity));
    }
}

