<?php
declare(strict_types=1);

require_once __DIR__ . '/../integrations/ChannelPostIncomingAdapter.php';

/** Mutable delivery projection; album content snapshots remain immutable. */
final class ChannelPostMemberDeliveryService
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** Called inside the registry's publication lock, after per-member clock merge. */
    public function synchronize(array $publication): void
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('member_sync_requires_publication_lock');
        $snapshot = (array)$publication['snapshot'];
        $anchor = (string)($snapshot['metadata']['actual_message_id'] ?? '');
        $url = rtrim((string)$publication['channel']['bot_url'], '/') . '?start=' . rawurlencode((string)$publication['start_payload']);
        $label = (string)$publication['channel']['button_text'];
        foreach ((array)($snapshot['metadata']['telegram_album_items'] ?? []) as $member) {
            if (!is_array($member)) continue;
            $mid = (string)($member['metadata']['actual_message_id'] ?? '');
            if (!preg_match('/\A[1-9][0-9]{0,19}\z/D', $mid)) throw new InvalidArgumentException('invalid_album_delivery_member');
            $role = $mid === $anchor ? 'anchor' : 'clear';
            $event = (string)($member['metadata']['source_event_id'] ?? '');
            $key = hash('sha256', $event !== '' ? $event : self::json([$member['text'] ?? '', $member['attachments'] ?? [], $member['keyboard'] ?? []]));
            $old = $this->one('SELECT * FROM post_member_deliveries WHERE publication_id=? AND external_message_id=?' . $this->lock(), [(int)$publication['publication_id'], $mid]);
            if ($old && hash_equals((string)$old['observation_key'], $key)) continue;
            $supported = ($member['metadata']['capability'] ?? '') === 'supported';
            $correct = $role === 'anchor' ? ChannelPostIncomingAdapter::containsOnlyButton($member, $url, $label) : empty($member['keyboard']);
            $state = !$supported ? 'requires_attention' : ($correct ? 'sent' : 'pending');
            $reason = !$supported ? (string)($member['metadata']['capability_reason'] ?? 'unsupported_media') : ($correct ? 'observed_final_markup' : '');
            $now = self::now();
            if (!$old) {
                // A forward migration may first meet an album whose anchor was
                // already attempted by the previous single-anchor delivery path.
                if ($role === 'anchor' && !$correct && (int)($publication['write_attempts'] ?? 0) > 0) {
                    $state = ($publication['state'] ?? '') === 'sent' ? 'sent' : 'unknown';
                    $reason = 'legacy_anchor_outcome';
                }
                $this->run('INSERT INTO post_member_deliveries (publication_id,external_message_id,desired_role,observation_key,snapshot_json,state,error_reason,receipt_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [(int)$publication['publication_id'], $mid, $role, $key, self::json($member), $state, $reason, '{}', $now, $now]);
            } else {
                // Only a newer authenticated observation of THIS member replaces
                // its decision. Registering another photo keeps the same key.
                $unresolved = (int)$old['write_attempts'] > 0 && in_array((string)$old['state'], ['write_started','unknown','claimed'], true);
                // Even a newer wrong-markup event can race the already started
                // HTTP edit. It is never permission to send that edit twice.
                if ($unresolved && !$correct) {
                    $state = 'unknown';
                    $reason = 'awaiting_member_final_markup';
                }
                $retainedAttempts = ($correct || $unresolved) ? (int)$old['write_attempts'] : 0;
                $retainedReceipt = ($correct || $unresolved) ? (string)$old['receipt_json'] : '{}';
                $this->run('UPDATE post_member_deliveries SET desired_role=?,observation_key=?,snapshot_json=?,state=?,claim_token=NULL,claimed_at=NULL,write_attempts=?,error_reason=?,receipt_json=?,updated_at=? WHERE id=?',
                    [$role, $key, self::json($member), $state, $retainedAttempts, $reason, $retainedReceipt, $now, (int)$old['id']]);
            }
        }
        $this->refresh((int)$publication['publication_id']);
    }

    public function rows(int $publicationId): array
    {
        $q = $this->pdo->prepare('SELECT * FROM post_member_deliveries WHERE publication_id=? ORDER BY id');
        $q->execute([$publicationId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id']; $row['publication_id'] = (int)$row['publication_id']; $row['write_attempts'] = (int)$row['write_attempts'];
            $row['snapshot'] = json_decode((string)$row['snapshot_json'], true) ?: [];
            $row['receipt'] = json_decode((string)$row['receipt_json'], true) ?: [];
        }
        unset($row);
        return $rows;
    }

    /** One bounded, distinct numeric-message edit; UNKNOWN is never a retry. */
    public function claim(int $publicationId): ?array
    {
        return $this->locked($publicationId, function () use ($publicationId): ?array {
            $publication = (new ChannelPostRegistryService($this->pdo))->publication($publicationId);
            if (!$publication || $publication['platform'] !== 'telegram' || !str_starts_with((string)$publication['external_message_id'], 'group:')) return null;
            $cutoff = gmdate('Y-m-d H:i:s', time() - 300);
            $this->run("UPDATE post_member_deliveries SET state=CASE WHEN write_attempts>0 THEN 'unknown' ELSE 'pending' END,claim_token=NULL,claimed_at=NULL,error_reason='claim_expired',updated_at=? WHERE publication_id=? AND state IN ('claimed','write_started') AND claimed_at<?", [self::now(), $publicationId, $cutoff]);
            $row = $this->one("SELECT * FROM post_member_deliveries WHERE publication_id=? AND state='pending' AND write_attempts=0 ORDER BY CASE WHEN desired_role='clear' THEN 0 ELSE 1 END,id LIMIT 1" . $this->lock(), [$publicationId]);
            if (!$row) { $this->refresh($publicationId); return null; }
            $token = bin2hex(random_bytes(16)); $now = self::now();
            if ($this->run("UPDATE post_member_deliveries SET state='claimed',claim_token=?,claimed_at=?,updated_at=? WHERE id=? AND state='pending' AND observation_key=? AND write_attempts=0", [$token, $now, $now, (int)$row['id'], (string)$row['observation_key']]) !== 1) return null;
            $operation = $publication;
            $operation['delivery_member_id'] = (int)$row['id'];
            $operation['delivery_observation_key'] = (string)$row['observation_key'];
            $operation['delivery_claim_token'] = $token;
            $operation['button_mode'] = $row['desired_role'] === 'anchor' ? 'append_button' : 'clear_keyboard';
            $operation['external_message_id'] = (string)$row['external_message_id'];
            $operation['message_id'] = (string)$row['external_message_id'];
            $operation['snapshot'] = json_decode((string)$row['snapshot_json'], true) ?: [];
            $operation['write_attempts'] = 0; $operation['write_attempted'] = false;
            $operation['reconcile_only'] = false;
            return $operation;
        });
    }

    public function markStarted(array $operation): bool
    {
        return $this->locked((int)$operation['publication_id'], function () use ($operation): bool {
            $fresh = (new ChannelPostRegistryService($this->pdo))->publication((int)$operation['publication_id']);
            if (!$fresh || self::config($fresh['channel']) !== self::config((array)$operation['channel'])) return false;
            $changed = $this->run("UPDATE post_member_deliveries SET state='write_started',write_attempts=1,updated_at=? WHERE id=? AND publication_id=? AND observation_key=? AND claim_token=? AND state='claimed' AND write_attempts=0",
                [self::now(), (int)$operation['delivery_member_id'], (int)$operation['publication_id'], (string)$operation['delivery_observation_key'], (string)$operation['delivery_claim_token']]) === 1;
            if ($changed) $this->refresh((int)$operation['publication_id']);
            return $changed;
        });
    }

    public function finish(array $operation, string $state, string $reason = '', array $receipt = []): bool
    {
        if (!in_array($state, ['sent', 'unknown', 'requires_attention'], true)) throw new InvalidArgumentException('invalid_member_delivery_state');
        return $this->locked((int)$operation['publication_id'], function () use ($operation, $state, $reason, $receipt): bool {
            $safe = [];
            foreach (['request_count', 'write_attempts', 'button_verified', 'content_preserved'] as $key) if (isset($receipt[$key]) && is_scalar($receipt[$key])) $safe[$key] = $receipt[$key];
            $reason = preg_match('/\A[a-zA-Z0-9_-]{0,96}\z/D', $reason) ? $reason : 'delivery_failed';
            $saved = $this->run("UPDATE post_member_deliveries SET state=?,claim_token=NULL,claimed_at=NULL,error_reason=?,receipt_json=?,updated_at=? WHERE id=? AND publication_id=? AND observation_key=? AND claim_token=? AND state IN ('claimed','write_started')",
                [$state, $reason, self::json($safe), self::now(), (int)$operation['delivery_member_id'], (int)$operation['publication_id'], (string)$operation['delivery_observation_key'], (string)$operation['delivery_claim_token']]) === 1;
            $this->refresh((int)$operation['publication_id']);
            if (!$saved) {
                $current = $this->one('SELECT * FROM post_member_deliveries WHERE id=? AND publication_id=?', [(int)$operation['delivery_member_id'], (int)$operation['publication_id']]);
                // An authenticated final-markup event can beat the edit response.
                // Its newer verified state wins without reporting a storage fault.
                if ($current && $current['state'] === 'sent'
                    && !hash_equals((string)$current['observation_key'], (string)$operation['delivery_observation_key'])
                    && ($current['desired_role'] === 'clear') === (($operation['button_mode'] ?? '') === 'clear_keyboard')) {
                    $observed = json_decode((string)$current['snapshot_json'], true) ?: [];
                    return ChannelPostIncomingAdapter::contentFingerprint($observed) === ChannelPostIncomingAdapter::contentFingerprint((array)$operation['snapshot']);
                }
            }
            return $saved;
        });
    }

    /** The group is sent only when every observed member is in its final state. */
    public function refresh(int $publicationId): void
    {
        $own = !$this->pdo->inTransaction();
        if ($own) { $this->locked($publicationId, function () use ($publicationId): void { $this->refresh($publicationId); }); return; }
        $rows = $this->rows($publicationId);
        if (!$rows) return;
        $state = 'sent'; $attempts = 0; $verified = 0; $reason = '';
        foreach ($rows as $row) {
            $attempts += $row['write_attempts'];
            if ($row['state'] === 'sent') $verified++;
            if ($row['state'] === 'requires_attention') { $state = 'requires_attention'; $reason = (string)$row['error_reason']; }
            elseif ($row['state'] === 'unknown' && $state !== 'requires_attention') $state = 'unknown';
            elseif ($row['state'] !== 'sent' && !in_array($state, ['requires_attention', 'unknown'], true)) $state = 'pending';
        }
        $receipt = self::json(['member_count' => count($rows), 'verified_member_count' => $verified, 'button_verified' => $state === 'sent', 'write_attempts' => $attempts]);
        $this->run('UPDATE post_versions SET state=?,write_attempts=?,claim_token=NULL,claimed_at=NULL,error_reason=?,receipt_json=?,updated_at=? WHERE id=(SELECT current_version_id FROM post_publications WHERE id=?) AND publication_id=?',
            [$state, $attempts, $state === 'sent' ? '' : ($reason !== '' ? $reason : 'album_members_' . $state), $receipt, self::now(), $publicationId, $publicationId]);
    }

    private function locked(int $publicationId, callable $callback)
    {
        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction();
        try {
            $this->one('SELECT id FROM post_publications WHERE id=?' . $this->lock(), [$publicationId]);
            $result = $callback();
            if ($own) $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
    private function one(string $sql, array $values): ?array { $q=$this->pdo->prepare($sql); $q->execute($values); return $q->fetch(PDO::FETCH_ASSOC) ?: null; }
    private function run(string $sql, array $values): int { $q=$this->pdo->prepare($sql); $q->execute($values); return $q->rowCount(); }
    private function lock(): string { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''; }
    private static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private static function now(): string { return gmdate('Y-m-d H:i:s'); }
    private static function config(array $channel): string
    {
        $out = [];
        foreach (['id','source_id','project_id','project_key','platform','bot_key','canonical_bot_username','channel_id','bot_url','button_text','config_version','is_enabled'] as $key) $out[$key] = (string)($channel[$key] ?? '');
        return hash('sha256', self::json($out));
    }
}
