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
            $snapshot['keyboard'] = ChannelPostIncomingAdapter::withoutOwnedButton((array)$snapshot['keyboard'], '', (string)$channel['bot_url']);
            if (!empty($snapshot['metadata']['media_group_id'])) $snapshot['media_group_id'] = $snapshot['metadata']['media_group_id'];
            $publication = $registry->registerPublication($projectKey, $platform, $botKey, $event['channel_id'], $event['external_message_id'], $snapshot);
            if (!$publication) return $safe + ['reason' => 'channel_configuration_changed'];
            $publicationId = (int)$publication['publication_id']; $versionId = (int)$publication['version_id'];
            if (!empty($publication['duplicate_event']) || !empty($publication['stale_observation'])) {
                // A retried acknowledgement can safely drain an already durable
                // pending job without trusting the older envelope as new evidence.
                return ['recognized' => true, 'queued' => in_array($publication['state'] ?? '', ['pending', 'unknown'], true), 'state' => 'ignored', 'reason' => !empty($publication['stale_observation']) ? 'stale_channel_observation' : 'duplicate_channel_observation', 'publication_id' => $publicationId, 'version_id' => $versionId];
            }
            $url = ChannelPostButtonTransport::buttonUrl($publication);
            $label = (string)$publication['channel']['button_text'];
            $original = $event['snapshot'];
            if (($original['metadata']['capability'] ?? '') === 'supported'
                && ChannelPostIncomingAdapter::containsButton($original, $url, $label)
                && ($publication['content_hash'] ?? '') === $snapshot['content_hash']
                && ChannelPostIncomingAdapter::contentFingerprint($original, $url, (string)$channel['bot_url']) === $snapshot['content_hash']) {
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
                $id = (int)$publication['publication_id']; $version = (int)$publication['version_id'];
                $reconcile = ($publication['state'] ?? '') === 'unknown' || !empty($publication['write_attempted']);
                $claim = $registry->claimPublication($id, $version, $reconcile);
                if (!$claim) continue;
                $claimToken = (string)$claim['claim_token'];
                $beforeWrite = static function () use ($registry, $claim, $id, $version, $claimToken): bool {
                    $fresh = $registry->publication($id);
                    if (!$fresh || (int)$fresh['version_id'] !== $version
                        || ($fresh['claim_token'] ?? '') !== $claimToken
                        || self::configurationFingerprint((array)$fresh['channel']) !== self::configurationFingerprint((array)$claim['channel'])) return false;
                    return $registry->markExternalWriteStarted($id, $version, $claimToken);
                };
                $result = $this->transport->attempt($claim, $beforeWrite, $reconcile);
                $saved = $registry->finishPublication($id, $version, $claimToken, (string)$result['state'], (string)$result['reason'], (array)$result['receipt']);
                $results[] = [
                    'publication_id' => $id, 'version_id' => $version,
                    'state' => $saved ? $result['state'] : 'unknown',
                    'reason' => $saved ? $result['reason'] : 'receipt_storage_failed',
                    'reconcile_only' => $reconcile, 'receipt' => $result['receipt'],
                ];
            }
        } catch (Throwable $e) {
            $results[] = ['state' => 'unknown', 'reason' => 'channel_worker_unavailable'];
        }
        return $results;
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
